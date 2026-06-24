<?php
/**
 * router.php — Router for the built-in PHP server.
 *
 *   php -S 127.0.0.1:8000 router.php
 *
 * Purpose:
 *   - Block direct HTTP access to secrets / app-managed state (K.dat, tmp,
 *     dotfiles, history JSON) which Apache handles via .htaccess.
 *   - Otherwise let the built-in server serve static assets directly and route
 *     PHP files normally.
 *
 * @license MIT
 */

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path = urldecode($uri);
$base = basename($path);

// --- Deny secrets / state -------------------------------------------------
$denied =
    $base === 'K.dat'
    || $base === 'g023_history.json'
    || str_contains($path, '/g023_backups/')
    || preg_match('#\.tmp(_|$)#', $base)
    || ($base !== '' && $base[0] === '.');   // dotfiles (.htaccess etc.)

if ($denied) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden";
    return true;
}

$file = __DIR__ . $path;

// UPLOADS NO-EXECUTE: uploaded media is untrusted content. It must never run
// as code under the built-in server (mirrors working_folder/uploads/.htaccess
// under Apache). Anything script-y under uploads/ is refused outright.
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$phpExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'phar'];

if (str_starts_with($path, '/working_folder/uploads/') && in_array($ext, $phpExts, true)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden";
    return true;
}

// Let the built-in server serve existing static files as-is.
if ($path !== '/' && is_file($file)) {
    if (!in_array($ext, $phpExts, true)) {
        return false; // built-in server serves the static file
    }
}

// Default document.
if ($path === '/' || is_dir($file)) {
    require __DIR__ . '/index.php';
    return true;
}

return false;
