<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:3001',
        'https://nft.bfin.technology',
        'https://www.nft.bfin.technology',
        'https://scottypumpkin.com',        // ← আপনার main site
        'https://www.scottypumpkin.com',    // ← www version
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];