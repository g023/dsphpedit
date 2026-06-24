<?php
/**
 * api/media.php — Media browser listing + thumbnail/file serving.
 *
 *  POST action=list   -> JSON list of uploaded media (with thumb info)
 *  POST action=delete -> remove a media file + its thumbnail
 *  GET  ?thumb=NAME    -> serves the JPG thumbnail (or 404)
 *  GET  ?raw=NAME      -> serves the original media bytes (no-exec)
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/paths.php';

dspe_bootstrap_state();

// --- GET: serve a thumbnail or raw media file -----------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (isset($_GET['thumb'])) {
        serve_media(THUMB_DIR, basename((string) $_GET['thumb']), true);
    }
    if (isset($_GET['raw'])) {
        serve_media(UPLOAD_DIR, basename((string) $_GET['raw']), false);
    }
    http_response_code(400);
    exit;
}

// --- POST: list / delete --------------------------------------------------
$input = api_guard();
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'list':   media_list();           break;
        case 'delete': media_delete($input);   break;
        default:       fail('Unknown action');
    }
} catch (RuntimeException $e) {
    fail($e->getMessage());
}

function media_kind_from_ext(string $ext): string
{
    $ext = strtolower($ext);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)) return 'image';
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'], true))                        return 'audio';
    if (in_array($ext, ['mp4', 'webm'], true))                                      return 'video';
    if ($ext === 'pdf')                                                             return 'pdf';
    return 'file';
}

function media_list(): never
{
    $items = [];
    $entries = @scandir(UPLOAD_DIR) ?: [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue; // skip .htaccess and .thumbs
        }
        $abs = UPLOAD_DIR . '/' . $name;
        if (!is_file($abs)) {
            continue;
        }
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $kind = media_kind_from_ext($ext);
        $thumbName = pathinfo($name, PATHINFO_FILENAME) . '.jpg';
        $hasThumb = is_file(THUMB_DIR . '/' . $thumbName);
        $items[] = [
            'name'  => $name,
            'rel'   => 'uploads/' . $name,
            'ext'   => $ext,
            'kind'  => $kind,
            'size'  => filesize($abs),
            'mtime' => filemtime($abs),
            'thumb' => $hasThumb ? $thumbName : null,
        ];
    }
    usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    ok(['items' => $items]);
}

function media_delete(array $input): never
{
    $name = basename((string) ($input['name'] ?? ''));
    if ($name === '' || $name[0] === '.') {
        fail('Invalid name');
    }
    $abs = safe_resolve(UPLOAD_DIR, $name, true);
    @unlink($abs);
    $thumb = THUMB_DIR . '/' . pathinfo($name, PATHINFO_FILENAME) . '.jpg';
    if (is_file($thumb)) {
        @unlink($thumb);
    }
    ok(['deleted' => $name]);
}

function serve_media(string $baseDir, string $name, bool $thumb): never
{
    if ($name === '' || $name[0] === '.') {
        http_response_code(404);
        exit;
    }
    try {
        $abs = safe_resolve($baseDir, $name, true);
    } catch (RuntimeException) {
        http_response_code(404);
        exit;
    }
    if (!is_file($abs)) {
        http_response_code(404);
        exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($abs) ?: 'application/octet-stream';
    // Never serve uploaded content as executable/script-y types.
    if (preg_match('#(php|x-httpd|x-php)#i', $mime)) {
        $mime = 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=86400');
    readfile($abs);
    exit;
}
