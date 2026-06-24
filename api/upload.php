<?php
/**
 * api/upload.php — Media upload with server-side validation.
 *
 *  POST action=upload  (multipart, field "file")
 *
 * - finfo(FILEINFO_MIME_TYPE) validated against UPLOAD_MIME_MAP allowlist
 * - stored extension derived from the validated MIME (never the upload name)
 * - random filename: bin2hex(random_bytes(16))
 * - raster images re-encoded through GD on ingest (strips appended payloads/EXIF)
 * - thumbnails generated for images
 * - stored under working_folder/uploads/ which cannot execute PHP (.htaccess)
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/paths.php';

$input = api_guard();   // also validates CSRF (token sent as form field/header)

if (($input['action'] ?? '') !== 'upload') {
    fail('Unknown action');
}
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    fail('No file uploaded');
}

$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    fail('Upload error code ' . $f['error']);
}
if ($f['size'] <= 0 || $f['size'] > MAX_UPLOAD_BYTES) {
    fail('File exceeds size limit (' . round(MAX_UPLOAD_BYTES / 1048576) . ' MB)');
}
if (!is_uploaded_file($f['tmp_name'])) {
    fail('Invalid upload');
}

// Server-side MIME detection — never trust the client's type or filename.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']) ?: '';
$map   = UPLOAD_MIME_MAP;
if (!isset($map[$mime])) {
    fail('Unsupported file type: ' . htmlspecialchars($mime));
}
$ext = $map[$mime];

$name = bin2hex(random_bytes(16)) . '.' . $ext;
$dest = safe_resolve(UPLOAD_DIR, $name, false);

$isRaster = in_array($mime, GD_REENCODE_MIME, true);

if ($isRaster) {
    // Re-encode through GD: this strips any appended payload / polyglot data
    // and EXIF, and proves the bytes are a real decodable image.
    if (!reencode_image($f['tmp_name'], $dest, $mime)) {
        fail('Image could not be processed');
    }
} else {
    if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        fail('Failed to store file');
    }
    @chmod($dest, 0644);
}

// Thumbnail (images only; PDF/others get a UI icon placeholder).
$hasThumb = false;
if ($isRaster) {
    $thumbPath = THUMB_DIR . '/' . pathinfo($name, PATHINFO_FILENAME) . '.jpg';
    $hasThumb = make_thumbnail($dest, $thumbPath, $mime);
}

ok([
    'name'   => $name,
    'rel'    => 'uploads/' . $name,
    'mime'   => $mime,
    'size'   => filesize($dest),
    'isImage'=> $isRaster,
    'thumb'  => $hasThumb,
    'kind'   => media_kind($mime),
]);

// ---------------------------------------------------------------------------

function media_kind(string $mime): string
{
    if (str_starts_with($mime, 'image/'))  return 'image';
    if (str_starts_with($mime, 'audio/'))  return 'audio';
    if (str_starts_with($mime, 'video/'))  return 'video';
    if ($mime === 'application/pdf')        return 'pdf';
    return 'file';
}

function load_image(string $path, string $mime): \GdImage|false
{
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/gif'  => @imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'image/bmp'  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
        default      => false,
    };
}

function reencode_image(string $src, string $dest, string $mime): bool
{
    $img = load_image($src, $mime);
    if ($img === false) {
        return false;
    }
    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($img, $dest, 90),
        'image/png'  => imagepng($img, $dest, 6),
        'image/gif'  => imagegif($img, $dest),
        'image/webp' => function_exists('imagewebp') ? imagewebp($img, $dest, 90) : imagejpeg($img, $dest, 90),
        'image/bmp'  => function_exists('imagebmp') ? imagebmp($img, $dest) : imagejpeg($img, $dest, 90),
        default      => false,
    };
    imagedestroy($img);
    if ($ok) {
        @chmod($dest, 0644);
    }
    return (bool) $ok;
}

function make_thumbnail(string $src, string $dest, string $mime): bool
{
    $img = load_image($src, $mime);
    if ($img === false) {
        return false;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, THUMB_MAX / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $thumb = imagecreatetruecolor($tw, $th);
    // flat white background (handles transparency for jpg thumb)
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefilledrectangle($thumb, 0, 0, $tw, $th, $white);
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = imagejpeg($thumb, $dest, 82);
    imagedestroy($img);
    imagedestroy($thumb);
    if ($ok) {
        @chmod($dest, 0644);
    }
    return (bool) $ok;
}
