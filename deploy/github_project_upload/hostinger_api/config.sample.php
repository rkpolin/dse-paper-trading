<?php
declare(strict_types=1);

// Copy this file to config.local.php on Hostinger and fill in real values there.
// Do not commit config.local.php.
return [
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE_NAME;charset=utf8mb4',
        'user' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    'security' => [
        'api_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_TOKEN',
        'hmac_secret' => 'CHANGE_ME_TO_A_DIFFERENT_LONG_RANDOM_SECRET',
        'timestamp_tolerance_seconds' => 300,
    ],
];
