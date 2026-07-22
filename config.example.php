<?php
/**
 * Lithocinch config — copy to craft/config/litho.php. Multi-environment supported.
 * Values here override the Control Panel settings.
 */

return [
    '*' => [
        // 'cli' (Node on server), 'daemon' (local serve), 'remote' (hosted Lambda)
        'driver' => 'cli',

        // cli: command/path to the engine. Install it on the server (npm i -g lithocinch)
        // or point at the cloned cli.js with `nodePath`.
        'toolPath' => 'lithocinch',
        // 'nodePath' => '/usr/bin/node',

        // daemon: run `lithocinch serve --port 4111 --token $LITHO_TOKEN`
        'daemonUrl' => 'http://127.0.0.1:4111',

        // remote: your hosted Lambda Function URL
        'apiUrl' => getenv('LITHO_API_URL') ?: '',
        'apiKey' => getenv('LITHO_API_KEY') ?: '',

        // Where generated variants are written — a Craft filesystem handle. For
        // CAF/TLB this is your craftcms/google-cloud bucket (or a public CDN bucket).
        'transformFsHandle' => 'gcsPublic',
        'transformSubpath' => '_litho',

        'perceptualTarget' => 0.99,
        'maxSize' => 2560,
        'formatPool' => ['avif', 'webp', 'jpeg', 'png'],
        'defaultFormats' => ['avif', 'webp', 'auto'],
        'defaultWidths' => [400, 800, 1200, 1600],
        'timeout' => 60,

        // --- Layer A: on-upload source optimization (Pro) — DESTRUCTIVE, read the README ---
        // Caps + perceptually compresses the stored ORIGINAL on upload/replace,
        // keeping its format (never converts). Off by default. With the backup
        // toggle on, the pristine bytes are copied to the backup Fs (or to local
        // storage/lithocinch/originals/) BEFORE anything is overwritten; if the
        // backup cannot be secured, the optimization is skipped.
        'onUploadOptimize' => false,
        'onUploadKeepBackup' => true,
        'onUploadMaxSize' => 3840, // stored-source cap; separate from the delivery 'maxSize' above
        // 'onUploadBackupFsHandle' => 'gcsColdStorage', // a Craft Fs handle; blank = local storage dir
        'onUploadBackupSubpath' => '_litho-originals',

        // Savings dashboard (Pro): how long the detail activity ledger keeps
        // rows before Craft's own garbage collection prunes them. The lifetime
        // "bytes saved" totals are a separate running total and are NEVER
        // pruned — this only bounds the (optional, recent-activity) detail
        // table. 0 = keep every detail row forever.
        'savingsLogRetentionDays' => 90,
    ],

    // 'dev' => [ 'driver' => 'cli' ],
    // 'production' => [ 'driver' => 'remote' ],
];
