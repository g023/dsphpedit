<?php
/**
 * api/files.php — File picker & I/O, scoped to working_folder/.
 *
 * POST action= list | read | write | create | rename | delete | mkdir
 * Every path argument passes through safe_resolve(). Writes are atomic.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/paths.php';
require_once __DIR__ . '/../lib/response.php';

$input  = api_guard();
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'list':    files_list();                  break;
        case 'read':    files_read($input);            break;
        case 'write':   files_write($input);           break;
        case 'create':  files_create($input);          break;
        case 'rename':  files_rename($input);          break;
        case 'delete':  files_delete($input);          break;
        case 'mkdir':   files_mkdir($input);           break;
        default:        fail('Unknown action');
    }
} catch (RuntimeException $e) {
    fail($e->getMessage());
}

/** Is this entry app-managed state hidden from the picker (top level only)? */
function is_hidden_top(string $name): bool
{
    return in_array($name, HIDDEN_NAMES, true);
}

function files_list(): never
{
    $tree = build_tree(WORK_DIR, '');
    ok(['root' => 'working_folder', 'tree' => $tree]);
}

function build_tree(string $absDir, string $relDir): array
{
    $out = [];
    $entries = @scandir($absDir);
    if ($entries === false) {
        return $out;
    }
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        // Hide app-managed state at the top level only.
        if ($relDir === '' && is_hidden_top($name)) {
            continue;
        }
        $abs = $absDir . DIRECTORY_SEPARATOR . $name;
        $rel = $relDir === '' ? $name : $relDir . '/' . $name;
        if (is_dir($abs)) {
            $out[] = [
                'name'     => $name,
                'path'     => $rel,
                'type'     => 'dir',
                'children' => build_tree($abs, $rel),
            ];
        } else {
            $out[] = [
                'name'  => $name,
                'path'  => $rel,
                'type'  => 'file',
                'size'  => @filesize($abs) ?: 0,
                'mtime' => @filemtime($abs) ?: 0,
                'ext'   => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            ];
        }
    }
    // Dirs first, then files, each alphabetical.
    usort($out, function ($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'dir' ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

function require_param(array $input, string $k): string
{
    if (!isset($input[$k]) || $input[$k] === '') {
        fail("Missing parameter: $k");
    }
    return (string) $input[$k];
}

function files_read(array $input): never
{
    $path = require_param($input, 'path');
    $abs  = safe_resolve(WORK_DIR, $path, true);
    if (!is_file($abs)) {
        fail('Not a file');
    }
    if (filesize($abs) > MAX_EDIT_FILE_BYTES) {
        fail('File too large to edit (limit ' . round(MAX_EDIT_FILE_BYTES / 1048576) . ' MB)');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    ok([
        'path'    => rel_path(WORK_DIR, $abs),
        'content' => (string) file_get_contents($abs),
        'size'    => filesize($abs),
        'mtime'   => filemtime($abs),
        'mime'    => $finfo->file($abs) ?: 'application/octet-stream',
    ]);
}

function files_write(array $input): never
{
    $path    = require_param($input, 'path');
    $content = $input['content'] ?? '';
    if (!is_string($content)) {
        fail('content must be a string');
    }
    if (strlen($content) > MAX_EDIT_FILE_BYTES) {
        fail('Content exceeds size limit');
    }
    $abs = safe_resolve(WORK_DIR, $path, false);
    if (is_dir($abs)) {
        fail('Target is a directory');
    }
    atomic_write($abs, $content);
    ok(['path' => rel_path(WORK_DIR, $abs), 'size' => strlen($content), 'mtime' => time()]);
}

/** Atomic write: temp file in same dir + rename. */
function atomic_write(string $abs, string $content): void
{
    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        fail('Could not create parent directory');
    }
    $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $content) === false) {
        fail('Write failed');
    }
    if (!@rename($tmp, $abs)) {
        @unlink($tmp);
        fail('Atomic rename failed');
    }
    @chmod($abs, 0664);
}

function files_create(array $input): never
{
    $path = require_param($input, 'path');
    $abs  = safe_resolve(WORK_DIR, $path, false);
    if (file_exists($abs)) {
        fail('File already exists');
    }
    atomic_write($abs, (string) ($input['content'] ?? ''));
    ok(['path' => rel_path(WORK_DIR, $abs)]);
}

function files_mkdir(array $input): never
{
    $path = require_param($input, 'path');
    $abs  = safe_resolve(WORK_DIR, $path, false);
    if (file_exists($abs)) {
        fail('Already exists');
    }
    if (!@mkdir($abs, 0775, true)) {
        fail('mkdir failed');
    }
    ok(['path' => rel_path(WORK_DIR, $abs)]);
}

function files_rename(array $input): never
{
    $from = require_param($input, 'path');
    $to   = require_param($input, 'to');
    $absFrom = safe_resolve(WORK_DIR, $from, true);
    $absTo   = safe_resolve(WORK_DIR, $to, false);
    // Protect app-managed top-level names from being clobbered.
    if (is_hidden_top(rel_path(WORK_DIR, $absFrom)) || is_hidden_top(rel_path(WORK_DIR, $absTo))) {
        fail('Reserved name');
    }
    if (file_exists($absTo)) {
        fail('Destination already exists');
    }
    if (!@rename($absFrom, $absTo)) {
        fail('Rename failed');
    }
    ok(['path' => rel_path(WORK_DIR, $absTo)]);
}

function files_delete(array $input): never
{
    $path = require_param($input, 'path');
    $abs  = safe_resolve(WORK_DIR, $path, true);
    if (is_hidden_top(rel_path(WORK_DIR, $abs))) {
        fail('Reserved name');
    }
    if (is_dir($abs)) {
        if (!rrmdir($abs)) {
            fail('Failed to delete directory');
        }
    } else {
        if (!@unlink($abs)) {
            fail('Delete failed');
        }
    }
    ok(['path' => rel_path(WORK_DIR, $abs)]);
}

function rrmdir(string $dir): bool
{
    $items = @scandir($dir);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($p) && !is_link($p)) {
            rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    return @rmdir($dir);
}
