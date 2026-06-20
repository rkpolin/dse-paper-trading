<?php
declare(strict_types=1);

// Copy this file to config.local.php on Hostinger and fill in real values there.
// Generate a password hash with:
// php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
return [
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE_NAME;charset=utf8mb4',
        'user' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    'auth' => [
        'username' => 'admin',
        'password_hash' => 'PASTE_PASSWORD_HASH_HERE',
    ],
    'app' => [
        'name' => 'DSE Paper Trading',
        'timezone' => 'Asia/Dhaka',
    ],
];
