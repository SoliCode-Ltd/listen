<?php

$appUrlHost = parse_url((string) env('APP_URL', ''), PHP_URL_SCHEME);
$appUrlDomain = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);
$appUrlPort = parse_url((string) env('APP_URL', ''), PHP_URL_PORT);
$appOrigin = null;
if (is_string($appUrlHost) && $appUrlHost !== '' && is_string($appUrlDomain) && $appUrlDomain !== '') {
    $appOrigin = $appUrlHost.'://'.$appUrlDomain.($appUrlPort ? ':'.$appUrlPort : '');
}

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        $appOrigin,
    ])),

    'allowed_origins_patterns' => [
        '#^chrome-extension://#',
        '#^moz-extension://#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
