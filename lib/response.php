<?php
/**
 * lib/response.php — Standard JSON envelope + error normalization.
 *
 * Envelope:  {"success":true,"data":...}  /  {"success":false,"error":"..."}
 * Always Content-Type: application/json, encoded with JSON_HEX_* flags so the
 * payload is safe to embed and never breaks out of a <script> context.
 *
 * @license MIT
 */

const DSPE_JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

function json_headers(): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
}

function send_json(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, DSPE_JSON_FLAGS);
    exit;
}

function ok(mixed $data = null): never
{
    send_json(['success' => true, 'data' => $data]);
}

function fail(string $error, int $status = 400): never
{
    send_json(['success' => false, 'error' => $error], $status);
}

/**
 * Read the POSTed action, supporting both form-encoded and JSON bodies.
 * Returns the merged input array (so endpoints read params uniformly).
 */
function read_input(): array
{
    $input = $_POST;
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = array_merge($input, $decoded);
        }
    }
    return $input;
}
