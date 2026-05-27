<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:5173',
        'https://marketingautomation.netlify.app',
        'https://pj8bsf4t-5173.asse.devtunnels.ms',
        'https://pj8bsf4t-8000.asse.devtunnels.ms',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'Accept',
        'Origin',
        'X-Bypass-Tunnel-Reminder'
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
