<?php
declare(strict_types=1);

require_once __DIR__ . '/response.php';

$configPath = __DIR__ . '/../config.local.php';
if (!is_file($configPath)) {
    api_error(500, 'CONFIG_MISSING', 'API configuration is not installed.');
}

$config = require $configPath;
if (!is_array($config)) {
    api_error(500, 'CONFIG_INVALID', 'API configuration is invalid.');
}

return $config;
