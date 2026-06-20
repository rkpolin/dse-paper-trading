<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config.local.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Dashboard configuration is not installed. Copy config.sample.php to config.local.php.';
    exit;
}

$appConfig = require $configPath;
if (!is_array($appConfig)) {
    http_response_code(500);
    echo 'Dashboard configuration is invalid.';
    exit;
}

date_default_timezone_set((string)($appConfig['app']['timezone'] ?? 'Asia/Dhaka'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
