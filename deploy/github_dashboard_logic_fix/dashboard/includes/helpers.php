<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return number_format((float)$value, 2) . ' BDT';
}

function pct(mixed $value): string
{
    return number_format(((float)$value) * 100, 2) . '%';
}

function latest_run_id(PDO $pdo): ?string
{
    $stmt = $pdo->query('SELECT run_id FROM system_runs ORDER BY created_at DESC LIMIT 1');
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

function latest_daily_run_id(PDO $pdo): ?string
{
    $row = fetch_one(
        $pdo,
        "SELECT run_id
         FROM system_runs
         WHERE source = 'github_actions_python_engine'
         ORDER BY created_at DESC
         LIMIT 1"
    );
    return $row === null ? null : (string)$row['run_id'];
}

function latest_intraday_run_id(PDO $pdo): ?string
{
    $row = fetch_one(
        $pdo,
        "SELECT run_id
         FROM system_runs
         WHERE source = 'github_actions_intraday_engine'
         ORDER BY created_at DESC
         LIMIT 1"
    );
    return $row === null ? null : (string)$row['run_id'];
}

function pct_or_na(?float $value, string $fallback = 'N/A'): string
{
    return $value === null ? $fallback : pct($value);
}

function money_or_na(mixed $value, string $fallback = 'N/A'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    return money($value);
}

function dashboard_date_diff_days(string $startDate, string $endDate): ?int
{
    try {
        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
    } catch (Exception) {
        return null;
    }
    return (int)$start->diff($end)->format('%a');
}

function dashboard_csrf_token(): string
{
    if (!isset($_SESSION['dashboard_csrf']) || !is_string($_SESSION['dashboard_csrf']) || $_SESSION['dashboard_csrf'] === '') {
        $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['dashboard_csrf'];
}

function dashboard_verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['dashboard_csrf'])
        && is_string($_SESSION['dashboard_csrf'])
        && hash_equals($_SESSION['dashboard_csrf'], $token);
}

function nav_active(string $file): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '') === $file ? 'active' : '';
}

function fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function dashboard_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $row = fetch_one(
            $pdo,
            'SELECT COUNT(*) AS table_count
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name',
            ['table_name' => $table]
        );
        return (int)($row['table_count'] ?? 0) > 0;
    } catch (PDOException) {
        return false;
    }
}
