<?php
/**
 * api/preview.php — Server-side PHP preview (THE headline feature).
 *
 * The previewed file is executed NATIVELY by the web server, exactly like any
 * other page. We validate the requested working_folder path, then 302-redirect
 * the preview iframe to the file's real URL so Apache + mod_php (or `php -S`)
 * runs it with real $_SERVER, headers, sessions, .htaccess — no subprocess and
 * no PHP-CLI dependency.
 *
 * Posture: a localhost-only operator editing their OWN PHP. Preview is
 * intentional code execution (RCE by design — see SECURITY.md / .htaccess).
 * The only job here is path confinement: safe_resolve() keeps the iframe
 * pointed exclusively at files inside working_folder.
 *
 *   GET ?file=relative/path.php[&t=cachebuster]
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/paths.php';

dspe_bootstrap_state();

// Preview executes the file NATIVELY via an HTTP redirect, so the working folder
// must be reachable under the web root (i.e. inside APP_ROOT). With an alternate
// working folder outside the app, editing/AI still work but preview cannot.
if (!WORK_DIR_IN_APPROOT) {
    http_response_code(409);
    preview_shell('Preview unavailable here',
        'The working folder is outside the app directory, so it is not web-reachable. '
        . 'Set a working folder inside the app (Settings) to use server-side Preview.');
}

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

// Build a URL relative to THIS script (api/preview.php) that points at the file
// inside the ACTIVE working folder, so the browser loads it straight from the
// server and PHP executes it natively. The working folder may be the bundled
// working_folder/ or any operator-configured directory inside the app — derive
// its web path from WORK_DIR rather than hardcoding the name. Each path segment
// is rawurlencoded; slashes stay.
$rel     = rel_path(WORK_DIR, $abs);                  // e.g. "sub/dir/foo.php"
$encoded = implode('/', array_map('rawurlencode', explode('/', $rel)));

// Path from APP_ROOT to WORK_DIR (e.g. "working_folder" or "wf_alt"), as web segs.
$workRel = ltrim(str_replace('\\', '/', substr(WORK_DIR, strlen(APP_ROOT))), '/');
$workEnc = $workRel === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $workRel))) . '/';
// api/preview.php -> APP_ROOT is one level up.
$target  = '../' . $workEnc . $encoded;

// Forward the numeric cache-buster so the ⟳ reload button always re-runs it.
$t = $_GET['t'] ?? '';
if ($t !== '' && preg_match('/^[0-9]+$/', $t)) {
    $target .= '?t=' . $t;
}

header('Location: ' . $target, true, 302);
exit;

// ---------------------------------------------------------------------------

function preview_shell(string $title, string $msg): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<body style="margin:0;display:flex;align-items:center;justify-content:center;'
        . 'height:100vh;font:14px/1.6 system-ui,sans-serif;background:#fff;color:#555;text-align:center;">'
        . '<div><h2 style="color:#333;font-weight:600;">' . htmlspecialchars($title) . '</h2><p>' . $msg . '</p></div></body>';
    exit;
}
