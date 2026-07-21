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

    /** Backend request timeout (seconds). */
    public int $timeout = 60;

    public function rules(): array
    {
        return [
            [['driver', 'toolPath'], 'required'],
            [['perceptualTarget'], 'number', 'min' => 0, 'max' => 1],
            [['maxSize', 'timeout'], 'integer', 'min' => 0],
            [['driver'], 'in', 'range' => ['cli', 'daemon', 'remote']],
        ];
    }
}
