<?php

namespace iceboxind\lithocinch\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use iceboxind\lithocinch\drivers\CliDriver;
use iceboxind\lithocinch\drivers\DriverInterface;
use iceboxind\lithocinch\drivers\HttpDriver;
use iceboxind\lithocinch\models\Settings;
use iceboxind\lithocinch\Plugin;
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

        return match ($s->driver) {
            'daemon' => new HttpDriver($s->daemonUrl, $s->apiKey ?: null, $s->timeout),
            'remote' => new HttpDriver($s->apiUrl, $s->apiKey ?: null, $s->timeout),
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
        if ($fs && !$fs->fileExists($path)) {
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
        return $meta;
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
