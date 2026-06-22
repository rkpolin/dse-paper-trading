<?php
declare(strict_types=1);

$appName = (string)($appConfig['app']['name'] ?? 'DSE Paper Trading');
$pageTitle = $pageTitle ?? $appName;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | <?= h($appName) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php"><?= h($appName) ?></a>
    <nav class="nav">
        <a class="<?= nav_active('index.php') ?>" href="index.php">Overview</a>
        <a class="<?= nav_active('signals.php') ?>" href="signals.php">Signals</a>
        <a class="<?= nav_active('trades.php') ?>" href="trades.php">Trades</a>
        <a class="<?= nav_active('accuracy.php') ?>" href="accuracy.php">Accuracy</a>
        <a class="<?= nav_active('portfolio.php') ?>" href="portfolio.php">Portfolio</a>
        <a class="<?= nav_active('intraday.php') ?>" href="intraday.php">Intraday</a>
        <a class="<?= nav_active('readiness.php') ?>" href="readiness.php">Readiness</a>
        <a class="<?= nav_active('system_health.php') ?>" href="system_health.php">Health</a>
        <a class="<?= nav_active('settings.php') ?>" href="settings.php">Settings</a>
    </nav>
    <a class="logout" href="logout.php">Logout</a>
</header>
<main class="page">
    <div class="page-title">
        <h1><?= h($pageTitle) ?></h1>
    </div>
