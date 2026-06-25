<?php
/**
 * tools/analyze.php — Non-AI code analysis for the open file.
 *
 *  POST action= lint | outline | metrics   (path = working_folder-relative)
 *
 * lint    : in-process syntax check via token_get_all(TOKEN_PARSE).
 * outline : functions / classes / methods via token_get_all().
 * metrics : line counts, token stats, simple complexity hints.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/paths.php';

$input  = api_guard();
$action = $input['action'] ?? '';
$path   = (string) ($input['path'] ?? '');

try {
    $abs = safe_resolve(WORK_DIR, $path, true);
} catch (RuntimeException $e) {
    fail($e->getMessage());
}
if (!is_file($abs)) {
    fail('Not a file');
}

switch ($action) {
    case 'lint':    ok(['output' => tool_lint($abs)]);              break;
    case 'outline': ok(['output' => tool_outline($abs)]);          break;
    case 'metrics': ok(['output' => tool_metrics($abs)]);          break;
    default:        fail('Unknown action');
}

/**
 * In-process syntax check using the tokenizer's full-parse mode. Catches the
 * same syntax/parse errors as `php -l` without ever shelling out to a PHP CLI
 * binary — by design this project relies only on the native webserver's PHP,
 * so there is no subprocess/proc_open path.
 */
function tool_lint(string $abs): string
{
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'phtml', 'inc'], true)) {
        return 'Lint applies to PHP files only (this is .' . $ext . ').';
    }
    return lint_via_tokenizer($abs);
}

/**
 * Subprocess-free syntax check using the tokenizer's full-parse mode. Catches
 * the same syntax/parse errors as `php -l` without spawning a process.
 */
function lint_via_tokenizer(string $abs): string
{
    $src = @file_get_contents($abs);
    if ($src === false) {
        return 'Could not read file for lint.';
    }
    try {
        token_get_all($src, TOKEN_PARSE);
    } catch (\ParseError $e) {   // ParseError extends CompileError; catch first
        return sprintf("Parse error: %s in %s on line %d\nErrors parsing %s",
            $e->getMessage(), basename($abs), $e->getLine(), basename($abs));
    } catch (\CompileError $e) {
        return sprintf('Compile error: %s in %s on line %d',
            $e->getMessage(), basename($abs), $e->getLine());
    } catch (\Throwable $e) {
        return 'Lint error: ' . $e->getMessage();
    }
    return 'No syntax errors detected in ' . basename($abs) . ' (in-process check).';
}

function tool_outline(string $abs): string
{
    $src = (string) file_get_contents($abs);
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'phtml', 'inc'], true)) {
        return 'Structure outline is available for PHP files.';
    }
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) {
        return 'Could not tokenize file.';
    }
    $lines = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        [$id, $text, $line] = [$t[0], $t[1], $t[2]];
        if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $name = next_ident($tokens, $i);
            if ($name !== null) {
                $kind = token_name($id);
                $lines[] = sprintf('%-5d  %-9s %s', $line, strtolower(str_replace('T_', '', $kind)), $name);
            }
        } elseif ($id === T_FUNCTION) {
            $name = next_ident($tokens, $i);
            if ($name !== null) {
                $lines[] = sprintf('%-5d  %-9s %s()', $line, 'function', $name);
            }
        }
    }
    if (!$lines) {
        return 'No functions or classes found.';
    }
    return "LINE   TYPE      NAME\n" . str_repeat('-', 36) . "\n" . implode("\n", $lines);
}

function next_ident(array $tokens, int $i): ?string
{
    $n = count($tokens);
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_array($t) && $t[0] === T_STRING) {
            return $t[1];
        }
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_NS_SEPARATOR], true)) {
            continue;
        }
        if (is_string($t) && trim($t) === '') {
            continue;
        }
        // ampersand for return-by-ref functions
        if (is_string($t) && $t === '&') {
            continue;
        }
        break;
    }
    return null;
}

function tool_metrics(string $abs): string
{
    $src   = (string) file_get_contents($abs);
    $lines = substr_count($src, "\n") + 1;
    $bytes = strlen($src);
    $blank = preg_match_all('/^\s*$/m', $src);
    $ext   = strtolower(pathinfo($abs, PATHINFO_EXTENSION));

    $rows = [
        ['File',        basename($abs)],
        ['Size',        number_format($bytes) . ' bytes'],
        ['Lines',       (string) $lines],
        ['Blank lines', (string) $blank],
    ];

    if (in_array($ext, ['php', 'phtml', 'inc'], true)) {
        $tokens = @token_get_all($src) ?: [];
        $fn = $cls = 0;
        $comments = 0;
        foreach ($tokens as $t) {
            if (!is_array($t)) continue;
            if ($t[0] === T_FUNCTION) $fn++;
            if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) $cls++;
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) $comments++;
        }
        // crude cyclomatic hint: decision keywords
        $branches = preg_match_all('/\b(if|elseif|for|foreach|while|case|catch|&&|\|\||\?)\b/', $src);
        $rows[] = ['Functions',  (string) $fn];
        $rows[] = ['Classes/etc', (string) $cls];
        $rows[] = ['Comment toks', (string) $comments];
        $rows[] = ['Branch points', (string) $branches . '  (complexity hint)'];
    }

    $out = '';
    foreach ($rows as [$k, $v]) {
        $out .= sprintf("%-15s %s\n", $k . ':', $v);
    }
    return rtrim($out);
}
