<?php
/**
 * tools/test_paths.php — Traversal test suite for safe_resolve().
 *
 * Run from CLI:   php tools/test_paths.php
 * Or via browser: returns a JSON/HTML summary (localhost-only tool).
 *
 * Exercises every vector in the acceptance criteria and confirms rejection.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/paths.php';

dspe_bootstrap_state();

$base = WORK_DIR;

// Provision a throwaway fixture inside WORK_DIR so the "exists" cases don't
// depend on whatever the operator happens to have in working_folder/ (a fresh
// release ships only welcome.php). Cleaned up below.
$fixtureRel = '__path_test_fixture.php';
$fixtureAbs = WORK_DIR . '/' . $fixtureRel;
$fixtureMade = @file_put_contents($fixtureAbs, "<?php // path-test fixture\n") !== false;

// Each case: [label, path, mustExist, shouldPass]
$cases = [
    ['simple file (exists)',          $fixtureRel,                   true,  true],
    ['nested create target',          'sub/dir/new.php',             false, true],
    ['dot-slash prefix',              './' . $fixtureRel,            true,  true],
    ['parent traversal',              '../config.php',               false, false],
    ['deep traversal',                '../../etc/passwd',            false, false],
    ['double-dot-slash obfuscation',  '....//....//config.php',      false, false],
    ['backslash traversal',           '..\\config.php',              false, false],
    ['url-encoded traversal',         '%2e%2e%2fconfig.php',         false, false],
    ['double url-encoded',            '%252e%252e%252fconfig.php',   false, false],
    ['absolute path',                 '/etc/passwd',                 false, false],
    ['windows drive',                 'C:\\Windows\\win.ini',        false, false],
    ['null byte injection',           "test.php\0.txt",             false, false],
    ['encoded null byte',             'test%00.php',                 false, false],
    ['mixed traversal',               'sub/../../config.php',        false, false],
];

// Symlink escape test (create a symlink inside working_folder pointing out).
$symlinkRel = '__evil_link';
$symlinkAbs = WORK_DIR . '/' . $symlinkRel;
@unlink($symlinkAbs);
$symlinkMade = @symlink('/etc', $symlinkAbs);
if ($symlinkMade) {
    $cases[] = ['in-folder symlink to /etc', $symlinkRel . '/passwd', true, false];
}

$results = [];
$pass = 0;
foreach ($cases as [$label, $path, $mustExist, $shouldPass]) {
    $rejected = false;
    $resolved = null;
    try {
        $resolved = safe_resolve($base, $path, $mustExist);
    } catch (Throwable $e) {
        $rejected = true;
    }
    $accepted = !$rejected;
    $ok = ($accepted === $shouldPass);
    if ($ok) $pass++;
    $results[] = [
        'label'    => $label,
        'path'     => $path,
        'expected' => $shouldPass ? 'ACCEPT' : 'REJECT',
        'actual'   => $accepted ? 'ACCEPT' : 'REJECT',
        'ok'       => $ok,
    ];
}
if ($symlinkMade) {
    @unlink($symlinkAbs);
}
if ($fixtureMade) {
    @unlink($fixtureAbs);
}

$total = count($results);
$allPass = ($pass === $total);

if (PHP_SAPI === 'cli') {
    echo "safe_resolve() traversal suite\n";
    echo str_repeat('=', 60) . "\n";
    foreach ($results as $r) {
        printf("[%s] %-32s exp %s got %s\n",
            $r['ok'] ? 'PASS' : 'FAIL', $r['label'], $r['expected'], $r['actual']);
    }
    echo str_repeat('-', 60) . "\n";
    printf("%d/%d passed%s\n", $pass, $total, $allPass ? '  ✅ ALL GREEN' : '  ❌ FAILURES');
    exit($allPass ? 0 : 1);
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Path tests</title>';
echo '<body style="font:13px/1.6 ui-monospace,monospace;background:#1b1d23;color:#d6dae2;padding:24px">';
echo '<h2>safe_resolve() traversal suite</h2>';
echo '<p>' . $pass . '/' . $total . ' passed ' . ($allPass ? '<span style="color:#4caf72">✅ ALL GREEN</span>' : '<span style="color:#e0625a">❌ FAILURES</span>') . '</p>';
echo '<table cellpadding="6" style="border-collapse:collapse">';
foreach ($results as $r) {
    $c = $r['ok'] ? '#4caf72' : '#e0625a';
    echo '<tr style="border-bottom:1px solid #343a46">';
    echo '<td style="color:' . $c . '">' . ($r['ok'] ? 'PASS' : 'FAIL') . '</td>';
    echo '<td>' . htmlspecialchars($r['label']) . '</td>';
    echo '<td style="color:#8b94a6">' . htmlspecialchars($r['path']) . '</td>';
    echo '<td>exp ' . $r['expected'] . '</td><td>got ' . $r['actual'] . '</td></tr>';
}
echo '</table></body>';
