<?php
/**
 * api/complete.php — AI inline code completion (fill-in-the-middle).
 *
 *  POST action=complete
 *    prefix    : code BEFORE the cursor (required)
 *    suffix    : code AFTER  the cursor (optional)
 *    file      : relative path of the file being edited (for language hint)
 *    model     : allowlisted model id (defaults to flash — completion is a
 *                hot path, so flash is used unless pro is explicitly chosen)
 *    multiline : '1' to allow multi-line completions, '0' for single-line
 *
 * Returns { completion, model, usage, finish }. The completion is the RAW text
 * to insert verbatim at the cursor — never wrapped in markdown / fences.
 *
 * Completion is its own endpoint (not ai_chat) because it has very different
 * tuning: low temperature, tiny max_tokens, FIM-style prompt, aggressive
 * output sanitization, and it must never leak the chat conversation context.
 *
 * @author  g023 (https://github.com/g023/)
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/ds4.php';

$input = api_guard();

if (($input['action'] ?? '') !== 'complete') {
    fail('Unknown action');
}

if (!ds4_has_key()) {
    fail('AI completion unavailable: no DeepSeek key in K.dat.', 503);
}

// --- Inputs ----------------------------------------------------------------
$prefix    = (string) ($input['prefix'] ?? '');
$suffix    = (string) ($input['suffix'] ?? '');
$file      = (string) ($input['file'] ?? '');
$multiline = filter_var($input['multiline'] ?? true, FILTER_VALIDATE_BOOLEAN);

// Nothing to continue from → nothing to complete (cheap short-circuit).
if (trim($prefix) === '' && trim($suffix) === '') {
    ok(['completion' => '', 'model' => DEFAULT_MODEL, 'usage' => null, 'finish' => 'empty']);
}

// Model: flash by default (hot path). Pro only when explicitly allowlisted.
$model = $input['model'] ?? DEFAULT_MODEL;
if (!in_array($model, ALLOWED_MODELS, true)) {
    $model = DEFAULT_MODEL;
}

// Token budget: keep the request small so latency stays low. We send a bounded
// window around the cursor — recent code matters most for local completion.
// The suffix window is generous: "unaware of the code after it" is the classic
// FIM failure, and the model can only respect what it's actually shown.
$PREFIX_CHARS = 6000;   // ~the last chunk before the cursor
$SUFFIX_CHARS = 4000;   // a meaningful slice of what comes after (true FIM)
// Keep the FULL untruncated suffix tail for overlap dedup below — even when the
// model gets only a window, we dedup against everything that actually follows.
$fullSuffix = $suffix;
if (strlen($prefix) > $PREFIX_CHARS) {
    $prefix = substr($prefix, -$PREFIX_CHARS);
}
if (strlen($suffix) > $SUFFIX_CHARS) {
    $suffix = substr($suffix, 0, $SUFFIX_CHARS);
}

$lang = $file !== '' ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : 'php';
if ($lang === '') { $lang = 'php'; }

// With thinking disabled (see ds4_chat call below) max_tokens funds ONLY the
// visible completion, so modest caps keep latency low while leaving room for a
// few lines. Single-line mode needs only enough for one line.
$maxTokens = $multiline ? 300 : 80;

// --- Prompt ----------------------------------------------------------------
// A focused fill-in-the-middle prompt. The assistant must emit ONLY the text to
// splice in at the cursor — no prose, no fences, no repetition of context.
$lengthRule = $multiline
    ? "Complete the current statement/block. Prefer a few lines; stop at a natural boundary."
    : "Complete ONLY to the end of the current line. Never output a newline.";

$system = "You are an expert code completion engine embedded in a PHP web IDE, like GitHub Copilot.\n"
    . "You receive the code BEFORE the cursor and the code AFTER the cursor. Output ONLY the exact "
    . "text to insert at the cursor so the code reads naturally.\n"
    . "The code AFTER the cursor ALREADY EXISTS in the file — it is not yours to write. Treat your\n"
    . "insertion as glue: reading prefix + your_text + suffix must form correct, non-duplicated code.\n"
    . "STRICT RULES:\n"
    . "1. Output raw code/text ONLY. No explanations, no markdown, no ``` code fences, no leading labels.\n"
    . "2. Do NOT repeat code that already appears immediately before or after the cursor. In particular,\n"
    . "   do NOT emit closing characters (')', ';', '}', ']', '\"', \"'\") or lines that already appear at\n"
    . "   the START of the code after the cursor — stop exactly where the existing suffix takes over.\n"
    . "3. Your output is spliced in VERBATIM at the cursor — do not restate the prefix.\n"
    . "4. Match the existing indentation, naming, and style. Language: {$lang}.\n"
    . "5. {$lengthRule}\n"
    . "6. If the code after the cursor already completes the statement, output an empty string.";

$user = "<code_before_cursor>\n{$prefix}\n</code_before_cursor>\n"
    . "<code_after_cursor>\n{$suffix}\n</code_after_cursor>\n"
    . "Insert the completion at the cursor (between the two blocks). Output only the insertion text.";

$messages = [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user',   'content' => $user],
];

try {
    $result = ds4_chat(
        messages: $messages,
        model: $model,
        temperature: 0.15,   // deterministic-ish; completion wants precision
        max_tokens: $maxTokens,
        top_p: 0.95,
        // CRITICAL: explicitly DISABLE thinking. flash reasons by default even
        // when the param is omitted, and that reasoning (a) adds seconds of
        // latency and (b) eats the max_tokens budget — frequently consuming it
        // entirely and returning an empty completion (finish_reason=length).
        // type=disabled yields fast (~1s), non-truncated, precise completions.
        thinking: ['type' => 'disabled'],
    );

    $completion = dspe_clean_completion($result['response'], $multiline);
    // Make the completion "aware of the code BEFORE it": strip any head that
    // re-types what's immediately before the cursor (e.g. you typed "//" and the
    // model echoes "// comment", or you typed "function " and it restates it).
    $completion = dspe_trim_prefix_overlap($completion, $prefix);
    // Make the completion "aware of the code after it": strip any tail that just
    // re-types what already follows the cursor (doubled brackets, ';', repeated
    // lines). This is the dedup the FIM prompt can't guarantee on its own.
    $completion = dspe_trim_suffix_overlap($completion, $fullSuffix);

    ok([
        'completion' => $completion,
        'model'      => $result['meta']['model'] ?? $model,
        'usage'      => $result['meta']['usage'] ?? null,
        'finish'     => $result['meta']['finish_reason'] ?? null,
    ]);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 502);
}

/**
 * Sanitize raw model output into safe-to-splice completion text.
 *
 * Chat models occasionally ignore "no markdown" and wrap output in fences or add
 * a stray label. We strip those defensively so the editor never inserts junk.
 */
function dspe_clean_completion(string $text, bool $multiline): string
{
    if ($text === '') {
        return '';
    }

    // Unwrap a single surrounding code fence: ```lang\n...\n```
    $trimmed = trim($text);
    if (preg_match('/^```[a-zA-Z0-9_+-]*\r?\n(.*?)\r?\n?```$/s', $trimmed, $m)) {
        $text = $m[1];
    } elseif (preg_match('/^```[a-zA-Z0-9_+-]*\r?\n?(.*)$/s', $trimmed, $m)) {
        // Opening fence with no clean close — drop the fence line, keep the rest.
        $text = rtrim($m[1], "`\r\n");
    }

    // Strip an accidental leading language label line like "php" on its own line
    // only when it's clearly a stray token (handled above for fenced cases).

    // Normalize line endings.
    $text = str_replace("\r\n", "\n", $text);

    if (!$multiline) {
        // Single-line mode: keep only the first line, no trailing newline.
        $nl = strpos($text, "\n");
        if ($nl !== false) {
            $text = substr($text, 0, $nl);
        }
        return rtrim($text, "\r\n");
    }

    // Multi-line: trim only trailing whitespace-only tail, preserve internal
    // indentation exactly.
    return rtrim($text, "\n");
}

/**
 * Remove any HEAD of the completion that merely re-types the code that already
 * sits immediately before the cursor. Chat-based FIM models routinely restate
 * the token(s) the user just typed: you type "//" and it returns "// comment",
 * you type "function " and it returns "function foo()". Spliced in verbatim this
 * doubles the prefix ("////", "function function foo()").
 *
 * We find the LONGEST suffix-of-the-prefix that equals a prefix-of-the-
 * completion and drop it from the front of the completion. A lone 1-char overlap
 * is only trimmed when it's structural (punctuation/comment/closer/whitespace),
 * since a single coincidental identifier letter is far more likely than a real
 * duplication.
 */
function dspe_trim_prefix_overlap(string $completion, string $prefix): string
{
    if ($completion === '' || $prefix === '') {
        return $completion;
    }

    // Bound the comparison window: duplicated tokens/lines are short, and this
    // keeps the scan cheap. 500 chars covers a repeated line or two.
    $tail = substr(str_replace("\r\n", "\n", $prefix), -500);
    $max  = min(strlen($completion), strlen($tail));

    for ($k = $max; $k >= 1; $k--) {
        if (substr($tail, -$k) !== substr($completion, 0, $k)) {
            continue;
        }
        if ($k === 1) {
            // Only trim a single-char overlap when it's clearly structural.
            // Includes '/' for the "//" comment case the user hits most.
            $first = $completion[0];
            if (!preg_match('/[\s)\]};,>\'"\/(\[{=.]/', $first)) {
                break;
            }
        }
        return substr($completion, $k);
    }

    return $completion;
}

/**
 * Remove any tail of the completion that merely re-types the code that already
 * follows the cursor. This is what makes completion "aware of the code after
 * it": chat-based FIM models routinely re-emit the closing ')', ';', '}', or
 * even a whole line that the existing suffix already provides, producing doubled
 * brackets / duplicated lines when spliced in.
 *
 * We find the LONGEST suffix-of-completion that equals a prefix-of-the-code-
 * after-the-cursor and drop it. A lone 1-char overlap is only trimmed when it's
 * a closer/punctuation/whitespace char — trimming a single identifier letter is
 * far more likely a coincidence than a real duplication.
 */
function dspe_trim_suffix_overlap(string $completion, string $suffix): string
{
    if ($completion === '' || $suffix === '') {
        return $completion;
    }

    // Bound the comparison window: duplicated closers/lines are short, and this
    // keeps the scan cheap. 500 chars covers a repeated line or two.
    $head = substr(str_replace("\r\n", "\n", $suffix), 0, 500);
    $max  = min(strlen($completion), strlen($head));

    for ($k = $max; $k >= 1; $k--) {
        if (substr($completion, -$k) !== substr($head, 0, $k)) {
            continue;
        }
        if ($k === 1) {
            // Only trim a single-char overlap when it's clearly structural.
            $last = $completion[strlen($completion) - 1];
            if (!preg_match('/[\s)\]};,>\'"]/', $last)) {
                break;
            }
        }
        return substr($completion, 0, strlen($completion) - $k);
    }

    return $completion;
}
