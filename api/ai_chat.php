<?php
/**
 * api/ai_chat.php — AI requests. Three modes on flash by default.
 *
 *  POST action=ai_chat
 *    code, prompt, mode(explain|full|edit), enable_thinking, history(JSON),
 *    model, file, reasoning_effort
 *
 * Default model = deepseek-v4-flash. Pro requires explicit user selection.
 * All AI traffic routes through lib/ds4.php (the only DeepSeek connector).
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/ds4.php';

$input = api_guard();

if (($input['action'] ?? '') !== 'ai_chat') {
    fail('Unknown action');
}

if (!ds4_has_key()) {
    fail('AI is unavailable: no DeepSeek key found in K.dat. Non-AI features still work.', 503);
}

$code    = (string) ($input['code'] ?? '');
$prompt  = trim((string) ($input['prompt'] ?? ''));
$mode    = $input['mode'] ?? 'explain';
$file    = (string) ($input['file'] ?? '');
$enableThinking = filter_var($input['enable_thinking'] ?? false, FILTER_VALIDATE_BOOLEAN);
$effort  = in_array($input['reasoning_effort'] ?? '', ['low', 'medium', 'high'], true)
           ? $input['reasoning_effort'] : null;

// Model: flash by default. Pro only if explicitly requested AND allowlisted.
$model = $input['model'] ?? DEFAULT_MODEL;
if (!in_array($model, ALLOWED_MODELS, true)) {
    $model = DEFAULT_MODEL;
}

if (!in_array($mode, ['explain', 'full', 'edit'], true)) {
    $mode = 'explain';
}
if ($prompt === '') {
    fail('Prompt is empty');
}

$history = [];
if (isset($input['history'])) {
    $h = is_array($input['history']) ? $input['history'] : json_decode((string) $input['history'], true);
    if (is_array($h)) {
        $history = $h;
    }
}

// --- System prompt per mode -----------------------------------------------
$lang = $file !== '' ? pathinfo($file, PATHINFO_EXTENSION) : 'php';
$systems = [
    'explain' => "You are a precise, concise coding assistant embedded in a PHP web IDE.\n"
        . "Answer the user's question about the code in plain text (Markdown allowed for formatting, "
        . "inline code, and short snippets). Do NOT return the whole file unless explicitly asked. "
        . "Be specific and reference line content where helpful.",
    'full' => "You are a coding assistant inside a live PHP IDE.\n"
        . "Return the COMPLETE updated file in a SINGLE Markdown code block (```), with no commentary "
        . "before or after it. Preserve the file's language ({$lang}). Output only the code block.",
    'edit' => "You are a code-editing assistant. Output ONLY a JSON array of objects, each with exactly "
        . "two string fields: \"search\" and \"replace\". Apply the replacements sequentially to the "
        . "current code. Use LITERAL string matching (no regex). Make each \"search\" string unique and "
        . "long enough to match exactly once. Do NOT include any prose, markdown, or code fences — only the JSON array.",
];
$temps = ['explain' => 0.5, 'full' => 0.5, 'edit' => 0.2];

$messages = [['role' => 'system', 'content' => $systems[$mode]]];
foreach ($history as $msg) {
    if (isset($msg['role'], $msg['content']) && in_array($msg['role'], ['user', 'assistant'], true)) {
        $messages[] = ['role' => $msg['role'], 'content' => (string) $msg['content']];
    }
}
$fileLine = $file !== '' ? "File: {$file}\n\n" : '';
$messages[] = [
    'role'    => 'user',
    'content' => "{$fileLine}Current code:\n\n```{$lang}\n{$code}\n```\n\nRequest: {$prompt}",
];

$thinking = false;
if ($enableThinking) {
    $thinking = ['type' => 'enabled'];
    if ($effort !== null) {
        $thinking['reasoning_effort'] = $effort;
    }
}

try {
    $result = ds4_chat(
        messages: $messages,
        model: $model,
        temperature: $temps[$mode],
        thinking: $thinking,
    );
    ok([
        'mode'      => $mode,
        'model'     => $result['meta']['model'] ?? $model,
        'response'  => $result['response'],
        'reasoning' => $result['reasoning'],
        'usage'     => $result['meta']['usage'] ?? null,
        'finish'    => $result['meta']['finish_reason'] ?? null,
    ]);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 502);
}
