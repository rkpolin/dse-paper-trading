<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<pre>";
echo "Debug dashboard\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Current folder: " . __DIR__ . "\n";

$configPath = __DIR__ . '/config.local.php';
echo "Config path: " . $configPath . "\n";
echo "Config exists: " . (is_file($configPath) ? 'yes' : 'no') . "\n";
echo "Config readable: " . (is_readable($configPath) ? 'yes' : 'no') . "\n";

if (is_file($configPath)) {
    echo "Loading config...\n";
    $config = require $configPath;
    echo "Config loaded: " . (is_array($config) ? 'yes' : 'no') . "\n";
    echo "DB DSN exists: " . (!empty($config['db']['dsn']) ? 'yes' : 'no') . "\n";
    echo "DB user exists: " . (!empty($config['db']['user']) ? 'yes' : 'no') . "\n";
    echo "Password hash exists: " . (!empty($config['auth']['password_hash']) ? 'yes' : 'no') . "\n";
}

echo "Loading dashboard bootstrap...\n";
require __DIR__ . '/includes/bootstrap.php';
echo "Bootstrap loaded OK\n";
echo "</pre>";
