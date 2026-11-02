<?php

namespace iceboxind\lithocinch\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use iceboxind\lithocinch\drivers\CliDriver;
use iceboxind\lithocinch\drivers\DriverInterface;
use iceboxind\lithocinch\drivers\HttpDriver;
use iceboxind\lithocinch\migrations\Install;
use iceboxind\lithocinch\models\Settings;
use iceboxind\lithocinch\Plugin;
use Throwable;
use Twig\Markup;

/**
 * The orchestrator: asset + transform opts -> cached, optimized variant URL(s).
 *
 * Storage is via Craft's Fs abstraction, so this works unchanged with the
 * craftcms/google-cloud filesystem (or S3/local). Variants are cached by key in
 * Craft's data cache (durable enough for a skeleton; a DB index is the
 * production upgrade) and stored as files in the configured transform Fs.
 *
 * The Craft API calls below were verified against Craft 5.10.8.1 vendored source
 * (Asset::getContents/getFocalPoint/getHasFocalPoint, Fs service + BaseFsInterface).
 */
class Optimizer extends Component
{
    private const EXT = [
        'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp',
        'avif' => 'avif', 'gif' => 'gif', 'tiff' => 'tif',
    ];

    /** Formats reserved for Pro. Lite delivers the best non-AVIF modern format. */
    private const PRO_ONLY_FORMATS = ['avif'];

    /**
     * Extensions the on-upload layer will rewrite in place. Deliberately
     * conservative: JPEG/PNG/WebP are the formats where cap + same-format
     * perceptual re-encode is an unambiguous win. GIF (animation), SVG (vector,
     * no pixel cap), TIFF (print masters), and AVIF (already efficient) are
     * skipped silently — a skipped file is always safe.
     */
    private const ON_UPLOAD_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Engine output `format` -> the source extensions it legitimately matches. */
    private const ON_UPLOAD_FORMAT_EXTS = [
        'jpeg' => ['jpg', 'jpeg'],
        'png' => ['png'],
        'webp' => ['webp'],
    ];

    /** Single best <img> src for an asset. */
    public function url(Asset $asset, array $opts = []): ?string
    {
        $format = $this->editionFormat($opts['format'] ?? 'auto');
        $variant = $this->variant($asset, $this->ops($asset, $opts), $format);
        return $variant['url'] ?? null;
    }

    /**
     * Build a full <picture> element (avif/webp/... sources + <img> fallback).
     *
     * Responsive <picture>/srcset generation is a Pro feature. On Lite this
     * degrades gracefully to a single optimized <img> (largest requested width,
     * best non-AVIF format) so templates that call picture() still render.
     */
    public function picture(Asset $asset, array $opts = []): Markup
    {
        if (!Plugin::$plugin->isPro()) {
            return $this->litePicture($asset, $opts);
        }

        $settings = Plugin::$plugin->getSettings();
        $widths = $opts['widths'] ?? $settings->defaultWidths;
        $formats = $opts['formats'] ?? $settings->defaultFormats;
        $baseOps = $this->ops($asset, $opts);

        // Desired (format x width) set + cache resolution.
        $desired = [];
        foreach ($formats as $format) {
            foreach ($widths as $w) {
                $ops = array_merge($baseOps, ['width' => (int)$w]);
                $desired[] = ['format' => $format, 'width' => (int)$w, 'ops' => $ops, 'key' => $this->cacheKey($asset, $ops, $format)];
            }
        }

        $resolved = [];
        $misses = [];
        foreach ($desired as $i => $d) {
            $meta = $this->cacheGet($d['key']);
            if ($meta) {
                $resolved[$i] = $meta + ['format' => $d['format'], 'width' => $d['width']];
            } else {
                $misses[$i] = $d;
            }
        }

        // Generate all misses in ONE batch call (one source read, one backend hit).
        if ($misses) {
            $source = $asset->getContents(); // reads bytes via the asset's Fs (GCS-safe) — verified Craft 5.10
            $batch = array_values(array_map(fn($d) => ['ops' => $d['ops'], 'format' => $d['format']], $misses));
            $outputs = $this->driver()->transform($source, ['batch' => $batch, 'defaults' => $this->defaults()]);
            $j = 0;
            foreach ($misses as $i => $d) {
                $o = $outputs[$j++] ?? null;
                if ($o) {
                    $resolved[$i] = $this->store($asset, $d['key'], $o) + ['format' => $d['format'], 'width' => $d['width']];
                }
            }
        }

        ksort($resolved);
        return $this->renderPicture($resolved, $formats, $opts);
    }

    /** Get-or-generate one variant. Returns ['format','width','height','url','path']. */
    public function variant(Asset $asset, array $ops, string $format): array
    {
        $key = $this->cacheKey($asset, $ops, $format);
        if ($meta = $this->cacheGet($key)) {
            return $meta;
        }
        $outputs = $this->driver()->transform($asset->getContents(), [
            'ops' => $ops,
            'format' => $format,
            'defaults' => $this->defaults(),
        ]);
        return isset($outputs[0]) ? $this->store($asset, $key, $outputs[0]) : [];
    }

    /**
     * Bulk-(re)optimization entry point for the Savings utility's queue job (Pro).
     *
     * "Bulk optimize" here means: pre-warm the DEFAULT delivery variant (auto
     * format shootout, delivery maxSize cap, no explicit width) through the exact
     * same driver + store plumbing as url()/picture(). It deliberately skips the
     * cache read and re-runs the transform on every call, so re-optimizing after
     * a settings change produces fresh output; store() is idempotent on the
     * transform Fs (won't rewrite a file that's already there), but — being a
     * genuinely new variant from the cache's point of view — DOES append a fresh
     * row to the savings ledger via store()'s own logSavings() call. This method
     * no longer writes to that ledger itself; it just reports the delta back to
     * the caller (BulkOptimizeJob) for progress/UI purposes.
     *
     * Rewriting the stored original is explicitly NOT this method's job — that is
     * optimizeUpload()'s (destructive, opt-in) surface. This one is safe to run
     * on everything: it only writes variants to the transform Fs.
     *
     * @return array{bytesBefore: int, bytesAfter: int, format: string, url: ?string}|null
     *         null when the driver produced no output for the asset.
     */
    public function bulkOptimize(Asset $asset): ?array
    {
        $source = $asset->getContents();
        $format = $this->editionFormat('auto');
        $ops = $this->ops($asset, []);

        $outputs = $this->driver()->transform($source, [
            'ops' => $ops,
            'format' => $format,
            'defaults' => $this->defaults(),
        ]);
        if (!isset($outputs[0])) {
            return null;
        }

        $meta = $this->store($asset, $this->cacheKey($asset, $ops, $format), $outputs[0]);

        return [
            'bytesBefore' => strlen($source),
            'bytesAfter' => strlen($outputs[0]['data']),
            'format' => $outputs[0]['format'],
            'url' => $meta['url'],
        ];
    }

    // ---- layer A: on-upload source optimization (Pro) -----------------------

    /**
     * Cap + perceptually compress a just-uploaded original IN PLACE, keeping its
     * format. Called from `Asset::EVENT_BEFORE_HANDLE_FILE` (fired inside
     * `Asset::beforeSave()` on new uploads AND replacements — verified against
     * Craft 5.10 vendored source). At that point `$asset->tempFilePath` is the
     * local path Craft will later copy into the volume: `afterSave()` →
     * `_relocateFile()` opens a FRESH `fopen($tempPath, 'rb')` stream (no cached
     * buffer), then recomputes `_width`/`_height`/`size`/`dateModified` from the
     * temp file — so overwriting it here is fully honored and the asset record
     * self-corrects to the optimized dimensions. Craft's own image sanitation
     * (`Image::cleanImageByPath()`) also runs after this event, on our output.
     *
     * Safety contract (the upload must NEVER be blocked, corrupted, or lost):
     *  - off by default; Pro-only (silent no-op on Lite);
     *  - with `onUploadKeepBackup` on, the pristine bytes are verifiably copied
     *    to the backup location BEFORE anything can overwrite them — if the
     *    backup cannot be secured, the optimization is skipped entirely;
     *  - any driver/engine failure, format mismatch, or zero-savings result
     *    logs a warning/info and leaves the original untouched;
     *  - the overwrite itself is write-sibling-then-rename, so a mid-write
     *    failure can never leave a truncated file at tempFilePath.
     *
     * Recovery story: backups land at `<backupFs>/<subpath>/YYYY-MM-DD/<uniq>-<filename>`
     * (or `storage/lithocinch/originals/YYYY-MM-DD/<uniq>-<filename>` when no
     * backup Fs is configured). To restore, find the file by name + upload date
     * and replace the asset with it in the CP. Backups are never deleted by the
     * plugin — prune on your own schedule.
     */
    public function optimizeUpload(Asset $asset): void
    {
        $s = Plugin::$plugin->getSettings();

        // Off by default; Pro-only. Both gates are silent no-ops.
        if (!$s->onUploadOptimize || !Plugin::$plugin->isPro()) {
            return;
        }

        // The event also fires for moves/renames (newLocation set, no temp file)
        // — only a new upload/replacement carries a local temp file to optimize.
        $path = $asset->tempFilePath;
        if (!$path || !is_file($path)) {
            return;
        }

        $filename = $this->uploadFilename($asset);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ON_UPLOAD_EXTENSIONS, true)) {
            return; // non-image, or a format we deliberately don't rewrite
        }

        $source = @file_get_contents($path);
        if ($source === false || $source === '') {
            Craft::warning("On-upload optimize: could not read temp file for \"{$filename}\"; upload proceeds untouched.", 'lithocinch');
            return;
        }

        // One-way-door safety net: secure the pristine bytes BEFORE anything can
        // overwrite them. If the backup can't be verified written, skip the
        // optimization entirely — the upload proceeds with the original bytes.
        if ($s->onUploadKeepBackup && !$this->backupOriginal($filename, $source)) {
            return;
        }

        try {
            // `format: 'keep'` = cap + perceptually compress WITHOUT converting
            // (engine maps it to format:null). `ops.maxSize` overrides the
            // delivery-layer default. `keepMetadata: true` because rewriting a
            // stored original should preserve embedded EXIF/XMP (copyright,
            // orientation) — stripping is a delivery-layer economy, not a
            // source-of-truth one.
            $outputs = $this->driver()->transform($source, [
                'ops' => ['maxSize' => $s->onUploadMaxSize],
                'format' => 'keep',
                'defaults' => [
                    'perceptualTarget' => $s->perceptualTarget,
                    'keepMetadata' => true,
                ],
            ]);
        } catch (Throwable $e) {
            Craft::warning("On-upload optimize failed for \"{$filename}\" (upload proceeds untouched): {$e->getMessage()}", 'lithocinch');
            return;
        }

        $out = $outputs[0] ?? null;
        $data = is_array($out) ? (string)($out['data'] ?? '') : '';
        if ($data === '') {
            Craft::warning("On-upload optimize: empty engine output for \"{$filename}\"; upload proceeds untouched.", 'lithocinch');
            return;
        }

        // Belt-and-braces format guard: if the engine somehow returned a
        // different format than the source extension, refuse the overwrite —
        // the on-upload layer must never change a file's format/extension.
        $format = is_array($out) ? (string)($out['format'] ?? '') : '';
        if (!in_array($ext, self::ON_UPLOAD_FORMAT_EXTS[$format] ?? [], true)) {
            Craft::warning("On-upload optimize: engine returned \"{$format}\" for .{$ext} \"{$filename}\" — refusing to change the source format; upload proceeds untouched.", 'lithocinch');
            return;
        }

        if (strlen($data) >= strlen($source)) {
            Craft::info("On-upload optimize: \"{$filename}\" is already optimal (no byte savings); left untouched.", 'lithocinch');
            return;
        }

        // Write-sibling-then-rename so a mid-write failure can never leave a
        // truncated file where Craft is about to read the upload from.
        $tmp = $path . '.litho';
        if (@file_put_contents($tmp, $data) !== strlen($data) || !@rename($tmp, $path)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            Craft::warning("On-upload optimize: could not replace temp file for \"{$filename}\"; upload proceeds untouched.", 'lithocinch');
            return;
        }

        $pct = round((1 - strlen($data) / strlen($source)) * 100, 1);
        Craft::info(sprintf(
            'On-upload optimize: "%s" %d -> %d bytes (-%s%%), kept %s.',
            $filename,
            strlen($source),
            strlen($data),
            $pct,
            $format
        ), 'lithocinch');
    }

    /**
     * Copy the pristine original bytes to the backup location, verifying the
     * write. Returns false (after logging) on ANY doubt — the caller treats
     * false as "do not optimize". Backups are date-partitioned and uniquely
     * prefixed, so repeated uploads/replacements of the same filename never
     * clobber an earlier backup.
     */
    private function backupOriginal(string $filename, string $bytes): bool
    {
        $s = Plugin::$plugin->getSettings();
        $leaf = date('Y-m-d') . '/' . uniqid('', false) . '-' . $filename;

        try {
            if ($s->onUploadBackupFsHandle) {
                $fs = Craft::$app->getFs()->getFilesystemByHandle($s->onUploadBackupFsHandle);
                if (!$fs) {
                    Craft::warning("On-upload optimize: backup Fs handle \"{$s->onUploadBackupFsHandle}\" not found; skipping optimization for \"{$filename}\".", 'lithocinch');
                    return false;
                }
                $bpath = trim($s->onUploadBackupSubpath, '/') . '/' . $leaf;
                $fs->write($bpath, $bytes); // throws on failure — caught below
                Craft::info("On-upload optimize: pristine original backed up to {$s->onUploadBackupFsHandle}:{$bpath}", 'lithocinch');
                return true;
            }

            // No backup Fs configured: fall back to a local directory on the
            // web server (survives the request; NOT deploy-artifact storage —
            // point onUploadBackupFsHandle at a bucket for real durability).
            $file = Craft::$app->getPath()->getStoragePath() . '/lithocinch/originals/' . $leaf;
            FileHelper::createDirectory(dirname($file));
            if (@file_put_contents($file, $bytes) !== strlen($bytes)) {
                Craft::warning("On-upload optimize: could not write local backup for \"{$filename}\"; skipping optimization.", 'lithocinch');
                return false;
            }
            Craft::info("On-upload optimize: pristine original backed up to {$file}", 'lithocinch');
            return true;
        } catch (Throwable $e) {
            Craft::warning("On-upload optimize: backup failed for \"{$filename}\" ({$e->getMessage()}); skipping optimization.", 'lithocinch');
            return false;
        }
    }

    /**
     * The asset's destination filename at EVENT_BEFORE_HANDLE_FILE time. New
     * uploads carry it in `newLocation` ("{folder:ID}name.ext" — set in
     * beforeSave() before the event fires); replacements have `_filename` set.
     * The temp-file basename is a last resort (it may be a randomized name).
     */
    private function uploadFilename(Asset $asset): string
    {
        if ($asset->newLocation) {
            try {
                [, $filename] = AssetsHelper::parseFileLocation($asset->newLocation);
                return $filename;
            } catch (Throwable) {
                // fall through
            }
        }
        try {
            return $asset->getFilename();
        } catch (Throwable) {
            return basename((string)$asset->tempFilePath);
        }
    }

    // ---- internals ----------------------------------------------------------

    private function driver(): DriverInterface
    {
        $s = Plugin::$plugin->getSettings();

        // The daemon/remote drivers are Pro. On Lite, fall back to cli rather
        // than throwing, so a mis-configured driver still renders images.
        if ($s->driver !== 'cli' && !Plugin::$plugin->isPro()) {
            Craft::warning(
                "Lithocinch Lite supports only the 'cli' driver; ignoring '{$s->driver}'. "
                . 'Upgrade to Pro for the daemon and remote drivers.',
                'lithocinch'
            );
            return new CliDriver($s);
        }

        // The CP fields offer `$ENV_VAR` values, which Craft does not resolve
        // in plugin settings: parse them here, where they are read.
        $apiKey = (string)App::parseEnv($s->apiKey) ?: null;

        return match ($s->driver) {
            'daemon' => new HttpDriver((string)App::parseEnv($s->daemonUrl), $apiKey, $s->timeout),
            'remote' => new HttpDriver((string)App::parseEnv($s->apiUrl), $apiKey, $s->timeout),
            default => new CliDriver($s),
        };
    }

    private function defaults(): array
    {
        $s = Plugin::$plugin->getSettings();
        return [
            'perceptualTarget' => $s->perceptualTarget,
            'formatPool' => $this->editionFormatPool($s->formatPool),
            'maxSize' => $s->maxSize,
        ];
    }

    /**
     * On Lite, strip Pro-only formats (AVIF) from the shootout pool so the
     * engine picks the best *non-AVIF* format (WebP/JPEG/PNG). Falls back to a
     * sane pool if that would leave nothing. Pro passes the pool through as-is.
     */
    private function editionFormatPool(array $pool): array
    {
        if (Plugin::$plugin->isPro()) {
            return $pool;
        }
        $lite = array_values(array_filter($pool, fn($f) => !in_array($f, self::PRO_ONLY_FORMATS, true)));
        return $lite ?: ['webp', 'jpeg', 'png'];
    }

    /**
     * Coerce an explicitly-requested Pro-only format down to 'auto' on Lite, so
     * `format: 'avif'` (or a CP transform set to AVIF) yields the best Lite
     * format instead of silently bypassing the edition gate.
     */
    private function editionFormat(string $format): string
    {
        if (in_array($format, self::PRO_ONLY_FORMATS, true) && !Plugin::$plugin->isPro()) {
            return 'auto';
        }
        return $format;
    }

    /**
     * Lite degradation for picture(): a single optimized <img> at the largest
     * requested width, best non-AVIF format — no multi-format/multi-width
     * sources. Returns empty markup if no transform Fs is configured.
     */
    private function litePicture(Asset $asset, array $opts): Markup
    {
        $widths = $opts['widths'] ?? Plugin::$plugin->getSettings()->defaultWidths;
        $width = $widths ? (int)max($widths) : null;

        $urlOpts = $opts;
        unset($urlOpts['widths'], $urlOpts['formats']);
        if ($width) {
            $urlOpts['width'] = $width;
        }
        $urlOpts['format'] = 'auto'; // editionFormat/pool keep this non-AVIF on Lite

        $src = $this->url($asset, $urlOpts);
        if (!$src) {
            return new Markup('', 'UTF-8');
        }

        $alt = htmlspecialchars((string)($opts['alt'] ?? ''), ENT_QUOTES);
        $img = "<img src=\"{$src}\""
            . ($width ? " width=\"{$width}\"" : '')
            . " alt=\"{$alt}\" loading=\"lazy\" decoding=\"async\">";
        return new Markup("<picture>{$img}</picture>", 'UTF-8');
    }

    /** Map Twig opts -> transform `ops`, defaulting focal to the asset's focal point. */
    private function ops(Asset $asset, array $opts): array
    {
        $ops = [];
        foreach (['width', 'height', 'fit', 'position', 'maxSize', 'bg'] as $k) {
            if (isset($opts[$k])) {
                $ops[$k] = $opts[$k];
            }
        }
        if (isset($opts['focal'])) {
            $ops['focal'] = $opts['focal'];
        } elseif (($ops['fit'] ?? 'cover') === 'cover' && $asset->getHasFocalPoint()) {
            // getFocalPoint() returns ['x'=>0.5,'y'=>0.5] defaults for any image, so
            // gate on getHasFocalPoint() to only override the crop gravity when the
            // user actually set one (verified against Craft 5.10: Asset.php).
            $fp = $asset->getFocalPoint();
            $ops['focal'] = ['x' => $fp['x'], 'y' => $fp['y']];
        }
        return $ops;
    }

    private function cacheKey(Asset $asset, array $ops, string $format): string
    {
        return 'litho:' . md5(implode('|', [
            $asset->uid,
            $asset->dateModified?->getTimestamp() ?? 0,
            json_encode($ops),
            $format,
            json_encode($this->defaults()),
            Plugin::$plugin->schemaVersion,
        ]));
    }

    private function cacheGet(string $key): ?array
    {
        $meta = Craft::$app->getCache()->get($key);
        if (is_array($meta) && $this->fs()?->fileExists($meta['path'] ?? '')) {
            return $meta;
        }
        return null;
    }

    /** Write the generated bytes to the transform Fs and cache the metadata. */
    private function store(Asset $asset, string $key, array $output): array
    {
        $ext = self::EXT[$output['format']] ?? $output['format'];
        $path = trim(Plugin::$plugin->getSettings()->transformSubpath, '/')
            . '/' . $asset->id . '/' . substr($key, 6) . '.' . $ext;

        $fs = $this->fs();
        // True only when we actually just wrote real bytes to a real transform
        // Fs — NOT when $fs is null (no transform Fs configured, nothing was
        // stored anywhere). That distinction matters below: logSavings() must
        // never fire for a "variant" that was never actually delivered.
        $wroteNewFile = $fs !== null && !$fs->fileExists($path);
        if ($wroteNewFile) {
            $fs->write($path, $output['data']); // BaseFsInterface::write(string,string,array $config=[]) — verified Craft 5.10
        }

        $meta = [
            'format' => $output['format'],
            'width' => $output['width'] ?? null,
            'height' => $output['height'] ?? null,
            'path' => $path,
            'url' => $this->fsUrl($path),
        ];
        Craft::$app->getCache()->set($key, $meta); // no TTL; invalidated by key (source mtime + settings)

        // Only log when we actually just wrote a genuinely new file to a real
        // transform Fs (not a re-write of one that already existed, and not a
        // no-op when no Fs is configured) — keeps the savings ledger honest:
        // one row per real byte-savings event that was actually delivered.
        // cacheGet() is the fast path that skips store() entirely on a real
        // cache hit, so this is still the natural "we did real work" hook.
        if ($wroteNewFile) {
            $this->logSavings($asset, $output);
        }

        return $meta;
    }

    /**
     * Record a real variant generation for the Pro savings dashboard — called
     * from store() so EVERY source of variants (organic url()/picture()
     * template hits and bulk-optimize runs alike) feeds it, not just manual
     * bulk runs. Two writes, deliberately split so the dashboard's headline
     * numbers never depend on scanning an unbounded table:
     *
     *  1. An ATOMIC INCREMENT of the single-row lifetime totals
     *     (`Install::TABLE_TOTALS`) — this is what the dashboard reads, an
     *     O(1) lookup that stays fast and accurate forever.
     *  2. An INSERT into the append-only detail ledger (`Install::TABLE`) —
     *     a bounded, recent-activity window (pruned by `Plugin::registerGc()`
     *     past the configured retention), useful for a future "recent
     *     activity" view but never load-bearing for the core totals.
     *
     * Pro-only (the dashboard itself is Pro-gated — no point logging for an
     * edition that can never see the numbers). Never lets a logging failure
     * take down the actual optimization work it's reporting on.
     */
    private function logSavings(Asset $asset, array $output): void
    {
        if (!Plugin::$plugin->isPro() || $asset->size === null) {
            return;
        }

        $bytesAfter = strlen((string)($output['data'] ?? ''));
        if ($bytesAfter <= 0 || $bytesAfter >= $asset->size) {
            return; // nothing saved (or nothing to compare against) — not worth a row
        }
        $bytesBefore = $asset->size;

        $db = Craft::$app->getDb();

        try {
            if (!$db->tableExists(Install::TABLE_TOTALS)) {
                return; // migration hasn't run yet on this install
            }
            // Atomic increment via SQL expression (value computed server-side
            // from the current row, not read-modify-write in PHP) — safe under
            // concurrent requests. The totals row is seeded by the install
            // migration, so this is always an UPDATE, never an upsert.
            $db->createCommand()->update(
                Install::TABLE_TOTALS,
                [
                    'variants' => new \yii\db\Expression('[[variants]] + 1'),
                    'bytesBefore' => new \yii\db\Expression('[[bytesBefore]] + :bb', [':bb' => $bytesBefore]),
                    'bytesAfter' => new \yii\db\Expression('[[bytesAfter]] + :ba', [':ba' => $bytesAfter]),
                    'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                ]
            )->execute();
        } catch (Throwable $e) {
            Craft::warning("Lithocinch savings totals update failed: {$e->getMessage()}", 'lithocinch');
        }

        try {
            if (!$db->tableExists(Install::TABLE)) {
                return;
            }
            // Db::insert() auto-fills dateCreated/dateUpdated/uid (same helper
            // BulkOptimizeJob already uses via Db::upsert() — verified Craft 5.10).
            Db::insert(Install::TABLE, [
                'assetId' => $asset->id,
                'bytesBefore' => $bytesBefore,
                'bytesAfter' => $bytesAfter,
                'format' => $output['format'] ?? null,
            ]);
        } catch (Throwable $e) {
            // The lifetime totals above already landed — a lost detail row
            // only shrinks the "recent activity" window, never the headline
            // numbers, so this is a warning, not a rollback of the increment.
            Craft::warning("Lithocinch savings log insert failed: {$e->getMessage()}", 'lithocinch');
        }
    }

    /** The configured transform filesystem (e.g. your GCS bucket). */
    private function fs(): ?object
    {
        $handle = Plugin::$plugin->getSettings()->transformFsHandle;
        // Craft::$app->getFs()->getFilesystemByHandle(): ?FsInterface — verified Craft 5.10 (nullable).
        return $handle ? Craft::$app->getFs()->getFilesystemByHandle($handle) : null;
    }

    private function fsUrl(string $path): ?string
    {
        $fs = $this->fs();
        $root = $fs && method_exists($fs, 'getRootUrl') ? $fs->getRootUrl() : null;
        return $root ? rtrim($root, '/') . '/' . ltrim($path, '/') : null;
    }

    /** Assemble the cached/generated variants into a <picture> element. */
    private function renderPicture(array $resolved, array $formats, array $opts): Markup
    {
        $sizes = $opts['sizes'] ?? '100vw';
        $byFormat = [];
        foreach ($resolved as $meta) {
            $byFormat[$meta['format']][] = $meta;
        }

        $sources = '';
        $fallbackFormat = end($formats); // last format is the <img> fallback
        foreach ($formats as $format) {
            if ($format === $fallbackFormat || empty($byFormat[$format])) {
                continue;
            }
            $srcset = $this->srcset($byFormat[$format]);
            $type = $this->mime($byFormat[$format][0]['format'] ?? $format);
            $sources .= "<source type=\"{$type}\" srcset=\"{$srcset}\" sizes=\"{$sizes}\">";
        }

        $fallbacks = $byFormat[$fallbackFormat] ?? (reset($byFormat) ?: []);
        $last = end($fallbacks) ?: null;
        $alt = htmlspecialchars((string)($opts['alt'] ?? ''), ENT_QUOTES);
        $img = $last
            ? "<img src=\"{$last['url']}\" srcset=\"{$this->srcset($fallbacks)}\" sizes=\"{$sizes}\""
                . ($last['width'] ? " width=\"{$last['width']}\"" : '')
                . ($last['height'] ? " height=\"{$last['height']}\"" : '')
                . " alt=\"{$alt}\" loading=\"lazy\" decoding=\"async\">"
            : '';

        return new Markup("<picture>{$sources}{$img}</picture>", 'UTF-8');
    }

    private function srcset(array $metas): string
    {
        $parts = [];
        foreach ($metas as $m) {
            if (!empty($m['url']) && !empty($m['width'])) {
                $parts[] = "{$m['url']} {$m['width']}w";
            }
        }
        return implode(', ', $parts);
    }

    private function mime(string $format): string
    {
        return [
            'avif' => 'image/avif', 'webp' => 'image/webp', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
        ][$format] ?? 'image/jpeg';
    }
}
