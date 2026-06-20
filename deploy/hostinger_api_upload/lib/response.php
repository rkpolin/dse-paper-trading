<?php
declare(strict_types=1);

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $statusCode, string $code, string $message): void
{
    json_response([
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ], $statusCode);
}
