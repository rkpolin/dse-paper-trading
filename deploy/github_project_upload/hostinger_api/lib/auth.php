<?php
declare(strict_types=1);

function verify_signed_request(array $config, string $rawBody): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error(405, 'METHOD_NOT_ALLOWED', 'Only POST is allowed.');
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        api_error(415, 'UNSUPPORTED_MEDIA_TYPE', 'Only application/json is accepted.');
    }

    $token = get_header_value('X-API-Token');
    $timestamp = get_header_value('X-Timestamp');
    $signature = get_header_value('X-Signature');

    if ($token === '' || $timestamp === '' || $signature === '') {
        api_error(401, 'AUTH_HEADERS_MISSING', 'Authentication headers are required.');
    }
    if (!ctype_digit($timestamp)) {
        api_error(401, 'TIMESTAMP_INVALID', 'Timestamp is invalid.');
    }

    $security = $config['security'] ?? [];
    $expectedToken = (string)($security['api_token'] ?? '');
    $hmacSecret = (string)($security['hmac_secret'] ?? '');
    $tolerance = (int)($security['timestamp_tolerance_seconds'] ?? 300);

    if ($expectedToken === '' || $hmacSecret === '') {
        api_error(500, 'AUTH_CONFIG_INVALID', 'Authentication configuration is incomplete.');
    }

    if (abs(time() - (int)$timestamp) > $tolerance) {
        api_error(401, 'REQUEST_EXPIRED', 'Request timestamp is outside the allowed window.');
    }

    if (!hash_equals($expectedToken, $token)) {
        api_error(401, 'AUTH_FAILED', 'Authentication failed.');
    }

    $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $hmacSecret);
    if (!hash_equals($expectedSignature, $signature)) {
        api_error(401, 'SIGNATURE_INVALID', 'Authentication failed.');
    }
}

function get_header_value(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return trim((string)$_SERVER[$serverKey]);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return trim((string)$value);
            }
        }
    }
    return '';
}
