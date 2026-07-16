<?php
/**
 * Crisp config — copy to craft/config/litho.php. Multi-environment supported.
 * Values here override the Control Panel settings.
 */

return [
    '*' => [
        // 'cli' (Node on server), 'daemon' (local serve), 'remote' (hosted Lambda)
        'driver' => 'cli',

        // cli: command/path to the engine. Install it on the server (npm i -g process-images)
        // or point at the cloned cli.js with `nodePath`.
        'toolPath' => 'process-images',
        // 'nodePath' => '/usr/bin/node',

        // daemon: run `process-images serve --port 4111 --token $CRISP_TOKEN`
        'daemonUrl' => 'http://127.0.0.1:4111',

        // remote: your hosted Lambda Function URL
        'apiUrl' => getenv('CRISP_API_URL') ?: '',
        'apiKey' => getenv('CRISP_API_KEY') ?: '',

        // Where generated variants are written — a Craft filesystem handle. For
        // CAF/TLB this is your craftcms/google-cloud bucket (or a public CDN bucket).
        'transformFsHandle' => 'gcsPublic',
        'transformSubpath' => '_crisp',

        'perceptualTarget' => 0.99,
        'maxSize' => 2560,
        'formatPool' => ['avif', 'webp', 'jpeg', 'png'],
        'defaultFormats' => ['avif', 'webp', 'auto'],
        'defaultWidths' => [400, 800, 1200, 1600],
        'timeout' => 60,
    ],

    // 'dev' => [ 'driver' => 'cli' ],
    // 'production' => [ 'driver' => 'remote' ],
];
