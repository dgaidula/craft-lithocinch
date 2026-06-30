<?php

namespace iceboxind\crisp\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use iceboxind\crisp\drivers\CliDriver;
use iceboxind\crisp\drivers\DriverInterface;
use iceboxind\crisp\drivers\HttpDriver;
use iceboxind\crisp\models\Settings;
use iceboxind\crisp\Plugin;
use Twig\Markup;

/**
 * The orchestrator: asset + transform opts -> cached, optimized variant URL(s).
 *
 * Storage is via Craft's Fs abstraction, so this works unchanged with the
 * craftcms/google-cloud filesystem (or S3/local). Variants are cached by key in
 * Craft's data cache (durable enough for a skeleton; a DB index is the
 * production upgrade) and stored as files in the configured transform Fs.
 *
 * NOTE: a few Craft API calls below are marked VERIFY — confirm exact signatures
 * against your Craft 4.5.x / 5.x before shipping.
 */
class Optimizer extends Component
{
    private const EXT = [
        'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp',
        'avif' => 'avif', 'gif' => 'gif', 'tiff' => 'tif',
    ];

    /** Single best <img> src for an asset. */
    public function url(Asset $asset, array $opts = []): ?string
    {
        $format = $opts['format'] ?? 'auto';
        $variant = $this->variant($asset, $this->ops($asset, $opts), $format);
        return $variant['url'] ?? null;
    }

    /** Build a full <picture> element (avif/webp/... sources + <img> fallback). */
    public function picture(Asset $asset, array $opts = []): Markup
    {
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
            $source = $asset->getContents(); // VERIFY: reads bytes from the asset's Fs (GCS-safe)
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
            'formatPool' => $s->formatPool,
            'maxSize' => $s->maxSize,
        ];
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
        } elseif (($ops['fit'] ?? 'cover') === 'cover' && method_exists($asset, 'getFocalPoint')) {
            $fp = $asset->getFocalPoint(); // VERIFY: ['x'=>0..1,'y'=>0..1]
            if ($fp) {
                $ops['focal'] = ['x' => $fp['x'], 'y' => $fp['y']];
            }
        }
        return $ops;
    }

    private function cacheKey(Asset $asset, array $ops, string $format): string
    {
        return 'crisp:' . md5(implode('|', [
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
            $fs->write($path, $output['data']); // VERIFY: FsInterface::write($path, $contents)
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
        // VERIFY: Craft 4/5 service + method name for fetching a filesystem by handle.
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
