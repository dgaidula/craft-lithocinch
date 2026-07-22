<?php

namespace iceboxind\lithocinch\models;

use craft\base\Model;

/**
 * Plugin settings. Project-config-aware (override per-environment in
 * config/litho.php). Secrets (apiKey) belong in .env via that config file.
 */
class Settings extends Model
{
    /** Backend driver: 'cli' (Node on server), 'daemon' (local serve), 'remote' (hosted API/Lambda). */
    public string $driver = 'cli';

    // --- cli driver ---
    /** Command or path to the lithocinch bin (or the cli.js script). */
    public string $toolPath = 'lithocinch';
    /** Optional explicit node binary to prefix (when toolPath is a .js script). */
    public string $nodePath = '';

    // --- daemon driver ---
    public string $daemonUrl = 'http://127.0.0.1:4111';

    // --- remote driver (hosted Lambda) ---
    public string $apiUrl = '';
    public string $apiKey = '';

    /**
     * Route existing `asset.getUrl({width})` calls through Lithocinch's native
     * ImageTransformer (drop-in optimization, no template changes). Off by
     * default so installing the plugin never silently hijacks a site's existing
     * transforms; enable per-environment in config/litho.php once a transform Fs
     * is configured. Requires transformFsHandle to actually emit URLs.
     */
    public bool $useAsDefaultTransformer = false;

    // --- where generated variants are stored (a Craft filesystem handle, e.g. your GCS bucket) ---
    public ?string $transformFsHandle = null;
    public string $transformSubpath = '_litho';

    // --- optimization knobs (forwarded to the engine) ---
    public float $perceptualTarget = 0.99;
    public int $maxSize = 2560;
    public array $formatPool = ['avif', 'webp', 'jpeg', 'png'];

    // --- responsive defaults for craft.litho.picture() ---
    /** Last entry is the <img> fallback format ('auto' or a concrete format). */
    public array $defaultFormats = ['avif', 'webp', 'auto'];
    public array $defaultWidths = [400, 800, 1200, 1600];

    // --- layer A: on-upload source optimization (Pro) ---
    /**
     * Cap + perceptually compress the stored ORIGINAL on upload/replace, keeping
     * its format (never converts — format conversion is the delivery layer's job).
     * Destructive by design (the stored original is rewritten), so this is off by
     * default: installing the plugin must never silently start modifying uploads.
     * Pro-only; on Lite the hook is a silent no-op.
     */
    public bool $onUploadOptimize = false;

    /**
     * Safety net for the one-way door above: copy the pristine original bytes to
     * a backup location BEFORE the optimized bytes overwrite them. On failure to
     * secure the backup, the optimization is skipped and the upload proceeds
     * untouched. Only disable this if you truly don't need the originals back.
     */
    public bool $onUploadKeepBackup = true;

    /**
     * Max dimension (px) for the stored original — deliberately SEPARATE from the
     * delivery-side `maxSize` above. The delivery cap is a safe, regenerable
     * ceiling for transform variants (2560 is plenty for web delivery); this cap
     * rewrites the source itself, a one-way door, so it defaults more generously
     * (3840 ≈ 4K) to preserve future crop/print/retina headroom. A site owner may
     * legitimately want the two different, so they are two settings.
     */
    public int $onUploadMaxSize = 3840;

    /**
     * Where original-file backups are written when onUploadKeepBackup is on: a
     * Craft filesystem handle (mirrors transformFsHandle — e.g. a cold-storage
     * bucket), under onUploadBackupSubpath. When null, backups fall back to the
     * local `storage/lithocinch/originals/` directory on the web server.
     */
    public ?string $onUploadBackupFsHandle = null;
    public string $onUploadBackupSubpath = '_litho-originals';

    /**
     * How many days of detail rows the Savings dashboard's activity ledger
     * keeps (`litho_optimization_log`) before Craft's garbage collection
     * prunes them (see `Plugin::registerGc()`). The dashboard's lifetime
     * totals (variants/bytes saved) are NOT affected — those are a separate,
     * never-pruned running total. `0` disables pruning entirely (keep
     * everything forever; only appropriate for low-traffic sites).
     */
    public int $savingsLogRetentionDays = 90;

    /** Backend request timeout (seconds). */
    public int $timeout = 60;

    public function rules(): array
    {
        return [
            [['driver', 'toolPath'], 'required'],
            [['perceptualTarget'], 'number', 'min' => 0, 'max' => 1],
            [['maxSize', 'onUploadMaxSize', 'timeout', 'savingsLogRetentionDays'], 'integer', 'min' => 0],
            [['onUploadOptimize', 'onUploadKeepBackup'], 'boolean'],
            [['driver'], 'in', 'range' => ['cli', 'daemon', 'remote']],
        ];
    }
}
