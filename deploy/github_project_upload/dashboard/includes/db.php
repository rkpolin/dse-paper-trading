<?php
declare(strict_types=1);

function dashboard_db(): PDO
{
    static $pdo = null;
    global $appConfig;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $appConfig['db'] ?? [];
    $pdo = new PDO((string)$db['dsn'], (string)$db['user'], (string)$db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
