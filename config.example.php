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
    ],

    // 'dev' => [ 'driver' => 'cli' ],
    // 'production' => [ 'driver' => 'remote' ],
];
