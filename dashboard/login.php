<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (attempt_login($username, $password)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Login failed.';
}

$appName = (string)($appConfig['app']['name'] ?? 'DSE Paper Trading');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | <?= h($appName) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
    <form class="login-box" method="post" action="login.php">
        <h1><?= h($appName) ?></h1>
        <p class="muted">Paper trading dashboard</p>
        <?php if ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>
        <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" autocomplete="username" required>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button type="submit">Sign in</button>
    </form>
</body>
</html>
