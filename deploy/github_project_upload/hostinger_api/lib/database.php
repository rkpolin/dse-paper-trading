<?php
declare(strict_types=1);

function db_connect(array $config): PDO
{
    $db = $config['db'] ?? [];
    $dsn = (string)($db['dsn'] ?? '');
    $user = (string)($db['user'] ?? '');
    $password = (string)($db['password'] ?? '');

    if ($dsn === '' || $user === '') {
        api_error(500, 'DB_CONFIG_INVALID', 'Database configuration is incomplete.');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
