<?php
/**
 * tools/selfcheck.php — Environment & dependency self-check.
 *
 * Run:  php tools/selfcheck.php   (CLI)   or open in a browser (localhost).
 * Confirms required extensions, writable runtime dirs, and key presence.
 * Preview runs through the native webserver (no PHP CLI / subprocess), so
 * there is nothing process-related to probe here.
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
chk($checks, 'preview endpoint present', is_file(__DIR__ . '/../api/preview.php'), 'redirects into working_folder so the native webserver runs the file (no PHP CLI)');
chk($checks, 'upload no-exec guard',    is_file(UPLOAD_DIR . '/.htaccess'));

// New subsystems: settings, PEEK map, agentic reading.
chk($checks, 'lib/settings.php present', is_file(__DIR__ . '/../lib/settings.php'));
chk($checks, 'lib/codemap.php present', is_file(__DIR__ . '/../lib/codemap.php'), 'PEEK map');
chk($checks, 'lib/context.php present', is_file(__DIR__ . '/../lib/context.php'), 'agentic reading');
chk($checks, 'settings file path safe', !WORK_DIR_IN_APPROOT || !str_starts_with(SETTINGS_FILE, WORK_DIR . DIRECTORY_SEPARATOR), 'settings live outside working_folder');
chk($checks, 'preview reachable',       WORK_DIR_IN_APPROOT, WORK_DIR_IN_APPROOT ? 'working folder under app root' : 'working folder outside app — preview disabled');
if (is_file(__DIR__ . '/../lib/codemap.php')) {
    require_once __DIR__ . '/../lib/codemap.php';
    $map = peek_build(false);
    chk($checks, 'PEEK map builds',      is_array($map) && isset($map['files']), $map['count'] . ' file(s) mapped');
}

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
