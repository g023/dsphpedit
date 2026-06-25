<?php
/**
 * ai_tools/assist.php — DeepSeek-powered code-assist tools (programmatic).
 *
 *  POST action= explain | refactor | docblocks | bugs   (path, optional code)
 *
 * These are the canonical AI tools the app surfaces in the Tools panel. The UI
 * runs them through the chat (so output is conversational + appliable), but
 * this endpoint exposes them directly for scripting/automation. All requests
 * route through lib/ds4.php on flash by default.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/ds4.php';
require_once __DIR__ . '/../lib/context.php';

$input  = api_guard();
$action = $input['action'] ?? '';

if (!ds4_has_key()) {
    fail('AI unavailable: no DeepSeek key in K.dat', 503);
}

$PROMPTS = [
    'explain'   => ['Explain what this file does, its structure, and notable logic. Plain text.', 0.5, 'explain'],
    'refactor'  => ['Suggest concrete refactors with short before/after snippets. Plain text.', 0.5, 'explain'],
    'docblocks' => ['Add PHPDoc docblocks to all functions/classes. Return the COMPLETE updated file in ONE code block, no commentary.', 0.4, 'full'],
    'bugs'      => ['Review for bugs, security issues, and edge cases. List each with severity and a fix. Plain text.', 0.4, 'explain'],
];

if (!isset($PROMPTS[$action])) {
    fail('Unknown action');
}

// Code may be supplied directly, or read from a working_folder file.
$code = (string) ($input['code'] ?? '');
$file = (string) ($input['file'] ?? $input['path'] ?? '');
if ($code === '' && $file !== '') {
    try {
        $abs = safe_resolve(WORK_DIR, $file, true);
        $code = (string) file_get_contents($abs);
    } catch (RuntimeException $e) {
        fail($e->getMessage());
    }
}
if ($code === '') {
    fail('No code provided');
}

$model = in_array($input['model'] ?? '', ALLOWED_MODELS, true) ? $input['model'] : DEFAULT_MODEL;
[$instruction, $temp, $mode] = $PROMPTS[$action];
$lang = $file !== '' ? pathinfo($file, PATHINFO_EXTENSION) : 'php';

$messages = [
    ['role' => 'system', 'content' => 'You are an expert PHP code assistant embedded in a web IDE.'],
];

// Agentic reading: give the tools the same auto-gathered project context.
$wantContext = !isset($input['context']) || filter_var($input['context'], FILTER_VALIDATE_BOOLEAN);
$ctxMeta = ['files' => [], 'peek_used' => false, 'bytes' => 0];
if ($wantContext && (AGENTIC_READING || PEEK_ENABLED) && ($file !== '' || $code !== '')) {
    try {
        $ctx = dspe_build_context($file, $code);
        $ctxMsg = dspe_context_message($ctx, $file);
        if ($ctxMsg !== '') {
            $messages[] = ['role' => 'system', 'content' => $ctxMsg];
        }
        $ctxMeta = [
            'files'     => array_map(static fn($f) => ['path' => $f['path'], 'reason' => $f['reason']], $ctx['files']),
            'peek_used' => $ctx['peek_used'],
            'bytes'     => $ctx['bytes'],
        ];
    } catch (\Throwable $e) {
        $ctxMeta['error'] = 'context unavailable';
    }
}

$messages[] = ['role' => 'user', 'content' => "{$instruction}\n\nFile: {$file}\n\n```{$lang}\n{$code}\n```"];

try {
    $r = ds4_chat(messages: $messages, model: $model, temperature: $temp);
    ok([
        'action'   => $action,
        'mode'     => $mode,
        'model'    => $r['meta']['model'] ?? $model,
        'response' => $r['response'],
        'usage'    => $r['meta']['usage'] ?? null,
        'context'  => $ctxMeta,
    ]);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 502);
}
