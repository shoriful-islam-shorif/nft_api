<?php

return [
    'paths' => ['api/*','sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:3002',
        'https://nft.scottypumpkin.com',
        'https://www.nft.scottypumpkin.com',
        'https://scottypumpkin.com',        
        'https://www.scottypumpkin.com',  
        'https://admin.nft.scottypumpkin.com',
        'https://www.admin.nft.scottypumpkin.com',  // ← www version
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];