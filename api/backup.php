<?php
/**
 * api/backup.php — Backup / restore of working_folder/ as ZIP.
 *
 *  POST action= create | list | restore | delete
 *
 * - create:  recursive ZIP of working_folder/ EXCLUDING g023_backups/,
 *            built to .tmp then atomically renamed. backup_YYYY-MM-DD_His.zip
 * - restore: ZIP-SLIP-SAFE. Every entry name validated (no absolute, no drive,
 *            no ../ component), extracted entry-by-entry via streams, each
 *            target re-verified inside working_folder/. Temp-dir then swap.
 * - retention: keep BACKUP_RETENTION most recent; prune oldest.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/paths.php';

$input  = api_guard();
$action = $input['action'] ?? '';

if (!class_exists('ZipArchive')) {
    fail('ZipArchive (php-zip) is not available on this host.', 500);
}

try {
    switch ($action) {
        case 'create':  backup_create();          break;
        case 'list':    backup_list();            break;
        case 'restore': backup_restore($input);   break;
        case 'delete':  backup_delete($input);    break;
        default:        fail('Unknown action');
    }
} catch (RuntimeException $e) {
    fail($e->getMessage());
}

function backup_create(): never
{
    $stamp = date('Y-m-d_His');
    $name  = "backup_{$stamp}.zip";
    $final = BACKUP_DIR . '/' . $name;
    $tmp   = $final . '.tmp';

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail('Could not create backup archive');
    }

    $backupReal = realpath(BACKUP_DIR);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(WORK_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $count = 0;
    foreach ($it as $file) {
        $path = $file->getPathname();
        // Exclude the backups directory itself + temp artifacts.
        if ($backupReal && str_starts_with(realpath($path) ?: $path, $backupReal)) {
            continue;
        }
        if (str_contains($file->getFilename(), '.tmp_')) {
            continue;
        }
        $local = ltrim(str_replace('\\', '/', substr($path, strlen(WORK_DIR))), '/');
        if ($file->isDir()) {
            $zip->addEmptyDir($local);
        } else {
            $zip->addFile($path, $local);
            $count++;
        }
    }
    $zip->close();

    if (!@rename($tmp, $final)) {
        @unlink($tmp);
        fail('Backup finalize (rename) failed');
    }
    backup_prune();
    ok(['name' => $name, 'files' => $count, 'size' => filesize($final)]);
}

function backup_list(): never
{
    $out = [];
    foreach (glob(BACKUP_DIR . '/backup_*.zip') ?: [] as $p) {
        $out[] = [
            'name'  => basename($p),
            'size'  => filesize($p),
            'mtime' => filemtime($p),
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    ok(['backups' => $out]);
}

function backup_delete(array $input): never
{
    $name = basename((string) ($input['name'] ?? ''));
    if (!preg_match('/^backup_[\d\-_]+\.zip$/', $name)) {
        fail('Invalid backup name');
    }
    $abs = safe_resolve(BACKUP_DIR, $name, true);
    if (!@unlink($abs)) {
        fail('Delete failed');
    }
    ok(['deleted' => $name]);
}

function backup_prune(): void
{
    $files = glob(BACKUP_DIR . '/backup_*.zip') ?: [];
    if (count($files) <= BACKUP_RETENTION) {
        return;
    }
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, BACKUP_RETENTION) as $old) {
        @unlink($old);
    }
}

/**
 * Reject any archive entry name that could escape on extraction.
 * Returns the safe relative name, or null if it must be rejected.
 */
function safe_zip_entry(string $entry): ?string
{
    if ($entry === '' || str_contains($entry, "\0")) {
        return null;
    }
    $n = str_replace('\\', '/', $entry);
    // Absolute path or Windows drive letter.
    if ($n[0] === '/' || preg_match('#^[A-Za-z]:#', $n)) {
        return null;
    }
    // Any traversal component.
    foreach (explode('/', $n) as $part) {
        if ($part === '..') {
            return null;
        }
    }
    return $n;
}

function backup_restore(array $input): never
{
    $name = basename((string) ($input['name'] ?? ''));
    if (!preg_match('/^backup_[\d\-_]+\.zip$/', $name)) {
        fail('Invalid backup name');
    }
    $archive = safe_resolve(BACKUP_DIR, $name, true);

    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        fail('Could not open archive');
    }

    // Extract into a fresh temp dir INSIDE working_folder (open_basedir-safe).
    $stage = WORK_DIR . '/.restore_' . bin2hex(random_bytes(6));
    if (!@mkdir($stage, 0775, true)) {
        $zip->close();
        fail('Could not create staging directory');
    }
    $stageReal = realpath($stage);

    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat  = $zip->statIndex($i);
            $entry = $stat['name'] ?? '';
            $safe  = safe_zip_entry($entry);
            if ($safe === null) {
                throw new RuntimeException('Refused unsafe archive entry: ' . htmlspecialchars($entry));
            }
            $isDir = str_ends_with($safe, '/');
            // Resolve target against the stage and re-verify containment.
            $target = safe_resolve($stageReal, $safe, false);
            if (!path_within($stageReal, $target) && $target !== $stageReal) {
                throw new RuntimeException('Entry escapes staging dir');
            }
            if ($isDir) {
                @mkdir($target, 0775, true);
                continue;
            }
            @mkdir(dirname($target), 0775, true);
            $in = $zip->getStream($entry);
            if (!$in) {
                throw new RuntimeException('Could not read entry: ' . htmlspecialchars($entry));
            }
            $out = @fopen($target, 'wb');
            if (!$out) {
                fclose($in);
                throw new RuntimeException('Could not write extracted file');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
        $zip->close();

        // Swap staged contents into working_folder (skip app-managed names).
        $restored = swap_in($stageReal);
        rrmdir_safe($stageReal);
        ok(['restored' => $name, 'files' => $restored]);
    } catch (RuntimeException $e) {
        $zip->close();
        rrmdir_safe($stage);
        fail($e->getMessage());
    }
}

/** Move staged files into WORK_DIR, overwriting; returns file count. */
function swap_in(string $stage): int
{
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($stage))), '/');
        if ($rel === '') {
            continue;
        }
        $top = explode('/', $rel)[0];
        // Never let a restore clobber the live backups directory (it is excluded
        // from archives anyway). Everything else in the snapshot — including
        // uploads/ and history — is restored faithfully.
        if ($top === basename(BACKUP_DIR)) {
            continue;
        }
        $dest = WORK_DIR . '/' . $rel;
        if ($file->isDir()) {
            @mkdir($dest, 0775, true);
        } else {
            @mkdir(dirname($dest), 0775, true);
            if (@copy($file->getPathname(), $dest)) {
                $count++;
            }
        }
    }
    return $count;
}

function rrmdir_safe(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
