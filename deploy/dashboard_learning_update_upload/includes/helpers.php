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
