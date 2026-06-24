<?php
/**
 * tools/selfcheck.php — Environment & dependency self-check.
 *
 * Run:  php tools/selfcheck.php   (CLI)   or open in a browser (localhost).
 * Confirms required extensions, writable runtime dirs, key presence, and the
 * preview subprocess capability. The app degrades gracefully if optional bits
 * are missing; this report tells the operator what's available.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ds4.php';

dspe_bootstrap_state();

$checks = [];
function chk(&$a, $label, $ok, $note = '') { $a[] = ['label' => $label, 'ok' => (bool) $ok, 'note' => $note]; }

chk($checks, 'PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
foreach (['curl', 'gd', 'zip', 'fileinfo', 'mbstring', 'json', 'session'] as $ext) {
    chk($checks, "ext: $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'MISSING');
}
chk($checks, 'working_folder writable', is_writable(WORK_DIR));
chk($checks, 'uploads/ writable',       is_writable(UPLOAD_DIR));
chk($checks, 'g023_backups/ writable',  is_writable(BACKUP_DIR));
chk($checks, 'history file writable',   is_writable(HISTORY_FILE));
chk($checks, 'K.dat present',           ds4_has_key(), ds4_has_key() ? 'key found' : 'no key — AI disabled, editing still works');
chk($checks, 'proc_open available (preview)', function_exists('proc_open') && !in_array('proc_open', explode(',', (string) ini_get('disable_functions'))), 'needed to execute preview');
chk($checks, 'GNU timeout (hard kill)', is_executable(trim((string) @shell_exec('command -v timeout 2>/dev/null'))) , 'optional; max_execution_time used as fallback');
chk($checks, 'upload no-exec guard',    is_file(UPLOAD_DIR . '/.htaccess'));

$req = array_filter($checks, fn($c) => !str_contains($c['label'], 'timeout') && !str_contains($c['label'], 'K.dat'));
$allReq = array_reduce($req, fn($carry, $c) => $carry && $c['ok'], true);

if (PHP_SAPI === 'cli') {
    echo "DS PHP Edit — self-check\n" . str_repeat('=', 50) . "\n";
    foreach ($checks as $c) {
        printf("[%s] %-30s %s\n", $c['ok'] ? ' OK ' : 'FAIL', $c['label'], $c['note']);
    }
    echo str_repeat('-', 50) . "\n";
    echo $allReq ? "Required checks: ALL PASS ✅\n" : "Required checks: FAILURES ❌\n";
    exit($allReq ? 0 : 1);
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Self-check</title>';
echo '<body style="font:13px/1.7 ui-monospace,monospace;background:#1b1d23;color:#d6dae2;padding:24px">';
echo '<h2>DS PHP Edit — self-check</h2><table cellpadding="6" style="border-collapse:collapse">';
foreach ($checks as $c) {
    $col = $c['ok'] ? '#4caf72' : '#e0625a';
    echo '<tr style="border-bottom:1px solid #343a46"><td style="color:' . $col . '">' . ($c['ok'] ? 'OK' : 'FAIL') . '</td><td>' . htmlspecialchars($c['label']) . '</td><td style="color:#8b94a6">' . htmlspecialchars($c['note']) . '</td></tr>';
}
echo '</table><p>' . ($allReq ? '<span style="color:#4caf72">Required checks: ALL PASS ✅</span>' : '<span style="color:#e0625a">Required checks: FAILURES ❌</span>') . '</p></body>';
