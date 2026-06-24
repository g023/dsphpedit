<?php
/**
 * lib/ds4.php — Canonical DeepSeek V4 connector. The ONLY file that talks to
 * api.deepseek.com. Every AI path routes through ds4_chat() / llm_get().
 *
 * - Key read from repo-root K.dat via __DIR__ (KEY_FILE) — not the prototype's
 *   old relative path — trimmed, throws if missing/empty. Never logged, echoed,
 *   or reflected in errors.
 * - Returns ['reasoning'=>, 'response'=>, 'meta'=>[id,model,finish_reason,usage,tool_calls?]].
 * - Default model = deepseek-v4-flash everywhere. Pro is opt-in only.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';

// The DeepSeek API endpoint is defined HERE and nowhere else — this file is the
// single connector that contacts the provider.
if (!defined('DEEPSEEK_ENDPOINT')) {
    define('DEEPSEEK_ENDPOINT', 'https://api.deepseek.com/chat/completions');
}

/**
 * Read + trim the DeepSeek API key from K.dat. Throws (sanitized) if absent.
 * The exception message never contains the key.
 */
function ds4_api_key(): string
{
    if (!is_file(KEY_FILE)) {
        throw new RuntimeException('DeepSeek API key file (K.dat) not found.');
    }
    $key = trim((string) file_get_contents(KEY_FILE));
    if ($key === '') {
        throw new RuntimeException('DeepSeek API key (K.dat) is empty.');
    }
    return $key;
}

/** True if a usable key is present (used to degrade AI features gracefully). */
function ds4_has_key(): bool
{
    return is_file(KEY_FILE) && trim((string) @file_get_contents(KEY_FILE)) !== '';
}

/**
 * Call DeepSeek V4 Chat Completions (non-streaming).
 *
 * @return array{reasoning:string,response:string,meta:array}
 * @throws RuntimeException with sanitized messages only.
 */
function ds4_chat(
    array $messages,
    string $model = DEFAULT_MODEL,
    float $temperature = 1.0,
    ?int $max_tokens = null,
    float $top_p = 1.0,
    bool|array $thinking = false,
    ?array $tools = null,
    mixed $tool_choice = null,
    ?array $response_format = null,
    ?string $user_id = null,
    array $extra_body = []
): array {
    if (empty($messages)) {
        throw new RuntimeException('Messages array cannot be empty');
    }

    // Enforce the model allowlist; silently fall back to the default otherwise.
    if (!in_array($model, ALLOWED_MODELS, true)) {
        $model = DEFAULT_MODEL;
    }

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
        'top_p'       => $top_p,
    ];
    if ($max_tokens !== null)       { $payload['max_tokens'] = $max_tokens; }
    if ($thinking !== false)        { $payload['thinking'] = is_array($thinking) ? $thinking : ['type' => 'enabled']; }
    if ($tools !== null)            { $payload['tools'] = $tools; }
    if ($tool_choice !== null)      { $payload['tool_choice'] = $tool_choice; }
    if ($response_format !== null)  { $payload['response_format'] = $response_format; }
    if ($user_id !== null)          { $payload['user_id'] = $user_id; }
    $payload = array_merge($payload, $extra_body);

    $key = ds4_api_key();

    $ch = curl_init(DEEPSEEK_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => DEEPSEEK_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr   = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        // Sanitized: surface the cURL error category, never request internals.
        throw new RuntimeException('Network error contacting DeepSeek (code ' . $curlErrNo . ').');
    }

    if ($httpCode !== 200) {
        // Surface the provider's human message if present, but never the raw
        // body / headers (which could echo the Authorization line).
        $detail = '';
        $decoded = $response ? json_decode($response, true) : null;
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $detail = ': ' . preg_replace('/sk-[A-Za-z0-9_\-]+/', 'sk-***', (string) $decoded['error']['message']);
        }
        throw new RuntimeException('DeepSeek API error (HTTP ' . $httpCode . ')' . $detail);
    }

    $data = json_decode((string) $response, true);
    if (!isset($data['choices'][0])) {
        throw new RuntimeException('Invalid API response: missing choices[0]');
    }

    $choice  = $data['choices'][0];
    $message = $choice['message'] ?? [];

    $meta = [
        'id'            => $data['id'] ?? null,
        'model'         => $data['model'] ?? null,
        'finish_reason' => $choice['finish_reason'] ?? null,
        'usage'         => $data['usage'] ?? [],
    ];
    if (isset($message['tool_calls'])) {
        $meta['tool_calls'] = $message['tool_calls'];
    }

    return [
        'reasoning' => $message['reasoning_content'] ?? '',
        'response'  => $message['content'] ?? '',
        'meta'      => $meta,
    ];
}

/**
 * Backwards-compatible alias preserving the prototype signature/order.
 */
function llm_get(
    array $messages,
    string $model = DEFAULT_MODEL,
    float $temperature = 1.0,
    ?int $max_tokens = null,
    float $top_p = 1.0,
    bool|array $thinking = false,
    ?array $tools = null,
    mixed $tool_choice = null,
    ?array $response_format = null,
    ?string $user_id = null,
    array $extra_body = []
): array {
    return ds4_chat($messages, $model, $temperature, $max_tokens, $top_p, $thinking, $tools, $tool_choice, $response_format, $user_id, $extra_body);
}
