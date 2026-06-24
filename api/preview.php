<?php
/**
 * api/preview.php — Server-side PHP preview (THE headline feature).
 *
 * Loaded as the src of a same-origin iframe (NOT srcdoc). It resolves the
 * requested working_folder file via safe_resolve() and EXECUTES it through a
 * sandboxed PHP subprocess so real PHP runs and its rendered output (or
 * errors/warnings/fatals) is returned to the iframe.
 *
 *   GET ?file=relative/path.php
 *
 * Isolation (defense-in-depth; localhost binding is the real boundary):
 *   - separate `php` subprocess with -d open_basedir, memory_limit,
 *     max_execution_time, disable_functions, ffi.enable=0, allow_url_*=0
 *   - hard wall-clock kill via `timeout` (catches sleep()/blocking I/O)
 *   - chdir into the file's directory so relative includes/assets resolve
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/paths.php';

dspe_bootstrap_state();

// This document is meant to be framed by our OWN app (same-origin), so we set
// SAMEORIGIN here rather than the global DENY. object-src none, no inline.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Referrer-Policy: no-referrer');

$file = $_GET['file'] ?? '';
if ($file === '') {
    preview_shell('No file specified.', 'Open a file and click <b>Preview</b>.');
}

try {
    $abs = safe_resolve(WORK_DIR, $file, true);
} catch (RuntimeException $e) {
    http_response_code(400);
    preview_shell('Invalid path', htmlspecialchars($e->getMessage()));
}

if (!is_file($abs)) {
    http_response_code(404);
    preview_shell('Not found', 'That file does not exist.');
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));

// Non-PHP files: serve their bytes with a best-effort content type so images,
// html, css, etc. render directly in the preview iframe.
$phpExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar'];
if (!in_array($ext, $phpExts, true)) {
    serve_static($abs, $ext);
}

// --- Execute the PHP file in a sandboxed subprocess -----------------------
$php = PHP_BINARY ?: 'php';
$disable = 'exec,passthru,shell_exec,system,proc_open,popen,dl,putenv,'
         . 'pcntl_exec,proc_nice,proc_get_status,proc_terminate';
$openBase = WORK_DIR . PATH_SEPARATOR . sys_get_temp_dir();

$args = [
    '-d', 'open_basedir=' . $openBase,
    '-d', 'memory_limit=' . PREVIEW_MEMORY,
    '-d', 'max_execution_time=' . PREVIEW_TIMEOUT,
    '-d', 'disable_functions=' . $disable,
    '-d', 'ffi.enable=0',
    '-d', 'allow_url_fopen=0',
    '-d', 'allow_url_include=0',
    '-d', 'display_errors=1',
    '-d', 'display_startup_errors=1',
    '-d', 'error_reporting=' . (E_ALL),
    '-d', 'html_errors=0',
    basename($abs),
];

// Prefer GNU `timeout` for a hard wall-clock kill (catches sleep()).
$hasTimeout = @is_executable(trim((string) @shell_exec('command -v timeout 2>/dev/null')));
if ($hasTimeout) {
    $cmd = array_merge(['timeout', '-k', '2', (string) (PREVIEW_TIMEOUT + 2), $php], $args);
} else {
    $cmd = array_merge([$php], $args);
}

[$stdout, $stderr, $code, $timedOut] = run_sandboxed($cmd, dirname($abs), PREVIEW_TIMEOUT + 5);

if ($timedOut || $code === 124) {
    preview_shell('Execution timed out',
        'The script exceeded the ' . PREVIEW_TIMEOUT . 's limit and was terminated.');
}

// If the script produced output, send it as-is (it owns its content type via
// any header() it emitted is lost across the subprocess, so we default to HTML).
$out = (string) $stdout;
$err = trim((string) $stderr);

header('Content-Type: text/html; charset=utf-8');

if ($out === '' && $err !== '') {
    // Pure error (e.g. fatal before any output): show it clearly.
    preview_error_page($err, $code);
}

echo $out;

// Append a non-intrusive error banner if warnings/notices were emitted to
// stderr alongside normal output (operator-only tool — visibility matters).
if ($err !== '') {
    echo error_banner($err);
}
exit;

// ---------------------------------------------------------------------------

function run_sandboxed(array $cmd, string $cwd, int $timeoutSecs): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, null);
    if (!is_resource($proc)) {
        return ['', 'Failed to start preview subprocess.', 1, false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSecs;
    $timedOut = false;

    do {
        $status = proc_get_status($proc);
        $r = [$pipes[1], $pipes[2]];
        $w = $e = null;
        if (@stream_select($r, $w, $e, 0, 200000) > 0) {
            foreach ($r as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) { $stdout .= $chunk; }
                else                       { $stderr .= $chunk; }
            }
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            proc_terminate($proc, 9);
            break;
        }
    } while ($status['running']);

    // Drain anything remaining.
    foreach ([$pipes[1], $pipes[2]] as $stream) {
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') { break; }
            if ($stream === $pipes[1]) { $stdout .= $chunk; }
            else                       { $stderr .= $chunk; }
        }
        fclose($stream);
    }
    $exit = proc_close($proc);
    return [$stdout, $stderr, $exit, $timedOut];
}

function serve_static(string $abs, string $ext): never
{
    $types = [
        'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
        'js' => 'text/javascript', 'json' => 'application/json',
        'txt' => 'text/plain', 'md' => 'text/plain', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4',
    ];
    $ct = $types[$ext] ?? 'application/octet-stream';
    // SVG can carry script; force download-ish handling by neutralizing.
    header('Content-Type: ' . $ct . (str_starts_with($ct, 'text/') ? '; charset=utf-8' : ''));
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

function error_banner(string $err): string
{
    $safe = htmlspecialchars($err, ENT_QUOTES);
    return '<div style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;'
        . 'max-height:40%;overflow:auto;background:#2b1416;color:#ffb4a8;'
        . 'font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;border-top:2px solid #d9534f;'
        . 'padding:8px 12px;white-space:pre-wrap;">'
        . "<strong style=\"color:#ff7b6b;\">⚠ PHP diagnostics</strong>\n" . $safe . '</div>';
}

function preview_error_page(string $err, int $code): never
{
    $safe = htmlspecialchars($err, ENT_QUOTES);
    echo '<!doctype html><meta charset="utf-8"><title>Preview error</title>'
        . '<body style="margin:0;font:14px/1.6 ui-monospace,Menlo,Consolas,monospace;'
        . 'background:#1e1212;color:#ffb4a8;padding:20px;">'
        . '<h2 style="color:#ff7b6b;margin:0 0 8px;">PHP error (exit ' . (int) $code . ')</h2>'
        . '<pre style="white-space:pre-wrap;margin:0;">' . $safe . '</pre></body>';
    exit;
}

function preview_shell(string $title, string $msg): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<body style="margin:0;display:flex;align-items:center;justify-content:center;'
        . 'height:100vh;font:14px/1.6 system-ui,sans-serif;background:#fff;color:#555;text-align:center;">'
        . '<div><h2 style="color:#333;font-weight:600;">' . htmlspecialchars($title) . '</h2><p>' . $msg . '</p></div></body>';
    exit;
}
