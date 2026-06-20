<?php
declare(strict_types=1);

function is_logged_in(): bool
{
    return isset($_SESSION['dashboard_user']) && $_SESSION['dashboard_user'] === 'admin';
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function attempt_login(string $username, string $password): bool
{
    global $appConfig;
    $expectedUser = (string)($appConfig['auth']['username'] ?? '');
    $passwordHash = (string)($appConfig['auth']['password_hash'] ?? '');

    if ($expectedUser === '' || $passwordHash === '' || $passwordHash === 'PASTE_PASSWORD_HASH_HERE') {
        return false;
    }

    if (hash_equals($expectedUser, $username) && password_verify($password, $passwordHash)) {
        session_regenerate_id(true);
        $_SESSION['dashboard_user'] = 'admin';
        return true;
    }
    return false;
}
