<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/response.php';
$config = require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/validation.php';
require_once __DIR__ . '/endpoints/ingest.php';

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    api_error(400, 'BODY_EMPTY', 'JSON body is required.');
}

verify_signed_request($config, $rawBody);

try {
    $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    $payload = validate_payload($decoded);
    $pdo = db_connect($config);
    $result = ingest_payload($pdo, $payload);
    json_response(['ok' => true, 'result' => $result]);
} catch (JsonException) {
    api_error(400, 'JSON_INVALID', 'Malformed JSON body.');
} catch (Throwable $exception) {
    try {
        if (isset($pdo) && isset($payload) && is_array($payload)) {
            insert_api_log($pdo, (string)($payload['run']['run_id'] ?? ''), 'ERROR', 'Ingest failed');
        }
    } catch (Throwable) {
        // Do not expose or chain logging failures.
    }
    api_error(500, 'SERVER_ERROR', 'The API could not process this request.');
}
