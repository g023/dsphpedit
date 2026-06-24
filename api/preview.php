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
// inside working_folder, so the browser loads it straight from the server and
// PHP executes it natively. Each path segment is rawurlencoded; slashes stay.
$rel     = rel_path(WORK_DIR, $abs);                  // e.g. "sub/dir/foo.php"
$encoded = implode('/', array_map('rawurlencode', explode('/', $rel)));
$target  = '../working_folder/' . $encoded;

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
