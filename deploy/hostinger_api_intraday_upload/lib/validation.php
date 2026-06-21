<?php
declare(strict_types=1);

function validate_payload(mixed $payload): array
{
    if (!is_array($payload)) {
        api_error(400, 'JSON_INVALID', 'Request body must be a JSON object.');
    }

    $run = require_array($payload, 'run');
    $runId = require_string($run, 'run_id', 80);
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $runId)) {
        api_error(400, 'RUN_ID_INVALID', 'run_id contains unsupported characters.');
    }

    foreach ([
        'stocks',
        'daily_prices',
        'indicators',
        'signals',
        'paper_trades',
        'positions',
        'portfolio_snapshots',
        'accuracy_evaluations',
    ] as $arrayKey) {
        if (!isset($payload[$arrayKey]) || !is_array($payload[$arrayKey])) {
            api_error(400, 'PAYLOAD_INVALID', $arrayKey . ' must be an array.');
        }
    }

    if (!isset($payload['strategy_performance']) || !is_array($payload['strategy_performance'])) {
        api_error(400, 'PAYLOAD_INVALID', 'strategy_performance must be an object.');
    }

    return $payload;
}

function require_array(array $input, string $key): array
{
    if (!isset($input[$key]) || !is_array($input[$key])) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' must be an object.');
    }
    return $input[$key];
}

function require_string(array $input, string $key, int $maxLength): string
{
    if (!isset($input[$key]) || !is_scalar($input[$key])) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' is required.');
    }
    $value = trim((string)$input[$key]);
    if ($value === '' || strlen($value) > $maxLength) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' is invalid.');
    }
    return $value;
}

function require_enum_value(array $input, string $key, array $allowed): string
{
    $value = require_string($input, $key, 30);
    if (!in_array($value, $allowed, true)) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' has an unsupported value.');
    }
    return $value;
}

function optional_string(array $input, string $key, int $maxLength, ?string $default = null): ?string
{
    if (!array_key_exists($key, $input) || $input[$key] === null) {
        return $default;
    }
    if (!is_scalar($input[$key])) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' is invalid.');
    }
    $value = trim((string)$input[$key]);
    if (strlen($value) > $maxLength) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' is too long.');
    }
    return $value;
}

function require_symbol(array $input): string
{
    if (!isset($input['symbol']) || !is_scalar($input['symbol'])) {
        api_error(400, 'PAYLOAD_INVALID', 'symbol is required.');
    }

    $rawSymbol = trim((string)$input['symbol']);
    $symbol = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $rawSymbol));
    if ($symbol === '' || strlen($symbol) > 30) {
        api_error(400, 'SYMBOL_INVALID', 'Symbol contains unsupported characters.');
    }
    return $symbol;
}

function require_date_value(array $input, string $key): string
{
    $date = require_string($input, $key, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        api_error(400, 'DATE_INVALID', $key . ' must be YYYY-MM-DD.');
    }
    return $date;
}

function require_time_value(array $input, string $key): string
{
    $time = require_string($input, $key, 8);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
        return $time . ':00';
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $time)) {
        api_error(400, 'TIME_INVALID', $key . ' must be HH:MM or HH:MM:SS.');
    }
    return $time;
}

function require_datetime_value(array $input, string $key): string
{
    $value = require_string($input, $key, 25);
    $normalized = str_replace('T', ' ', rtrim($value, 'Z'));
    if (preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):([0-5]\d)$/', $normalized)) {
        return $normalized . ':00';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $normalized)) {
        api_error(400, 'DATETIME_INVALID', $key . ' must be YYYY-MM-DD HH:MM:SS.');
    }
    return $normalized;
}

function require_int_value(array $input, string $key, int $min = 0): int
{
    if (!isset($input[$key]) || !is_numeric($input[$key])) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' must be numeric.');
    }
    $value = (int)$input[$key];
    if ($value < $min) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' is outside the allowed range.');
    }
    return $value;
}

function require_float_value(array $input, string $key, float $min = -INF): float
{
    if (!isset($input[$key]) || !is_numeric($input[$key])) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' must be numeric.');
    }
    $value = (float)$input[$key];
    if ($value < $min || is_nan($value) || is_infinite($value)) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' is outside the allowed range.');
    }
    return $value;
}

function optional_float_value(array $input, string $key): ?float
{
    if (!array_key_exists($key, $input) || $input[$key] === null) {
        return null;
    }
    if (!is_numeric($input[$key])) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' must be numeric.');
    }
    $value = (float)$input[$key];
    if (is_nan($value) || is_infinite($value)) {
        api_error(400, 'PAYLOAD_INVALID', $key . ' is invalid.');
    }
    return $value;
}

function bool_to_int(mixed $value): int
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}
