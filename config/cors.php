<?php

return [

    'paths' => [
        'api/*',
        'public/api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // 0 meant the browser never cached a preflight response, so every
    // cross-origin call carrying Authorization/X-Active-Company-Id (axios
    // attaches both once logged in — see lib/axios.ts) paid a fresh OPTIONS
    // round-trip on top of the real request, every single time. On the
    // single-threaded `php artisan serve` dev server those queue up rather
    // than overlap, which is what made Step 2 of /register (two calls fired
    // back-to-back) visibly slow. 24h matches Chrome's own preflight cache
    // ceiling, so this is the most a browser would ever honor anyway.
    'max_age' => 86400,

    'supports_credentials' => false,

];