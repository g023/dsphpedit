<?php
/**
 * tools/test_features.php — CLI self-test for the settings, PEEK map, and
 * agentic-reading subsystems. Run:  php tools/test_features.php
 *
 * Pure in-process assertions (no DeepSeek calls, no HTTP). Exits non-zero on
 * any failure so it can gate a release. Uses an isolated TEST fixture directory
 * so it never touches the operator's real working_folder.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/codemap.php';
require_once __DIR__ . '/../lib/context.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function t_ok(string $label, bool $cond, string $detail = ''): void
{
    if ($cond) {
        $GLOBALS['__pass']++;
        echo "  \033[32mPASS\033[0m  $label\n";
    } else {
        $GLOBALS['__fail']++;
        echo "  \033[31mFAIL\033[0m  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}
function t_head(string $s): void { echo "\n== $s ==\n"; }

// ---------------------------------------------------------------------------
// 1. Settings validation
// ---------------------------------------------------------------------------
t_head('Settings validation');

$d = dspe_settings_defaults();
t_ok('defaults present', isset($d['default_model'], $d['agentic_max_files'], $d['peek_enabled']));
t_ok('default model is flash (policy)', $d['default_model'] === DEFAULT_MODEL, $d['default_model']);

$r = dspe_settings_validate([
    'agentic_max_files' => '999',          // clamp to 40
    'agentic_max_files_x' => 'ignored',    // unknown -> dropped
    'default_model' => 'bogus-model',      // invalid enum -> default
    'editor_font_size' => 'abc',           // non-numeric -> default
    'thinking_default' => 'yes',           // truthy -> true
    'deepseek_timeout' => '5',             // below min(10) -> clamp 10
]);
t_ok('int clamp to max', $r['values']['agentic_max_files'] === 40, (string) $r['values']['agentic_max_files']);
t_ok('int clamp to min', $r['values']['deepseek_timeout'] === 10, (string) $r['values']['deepseek_timeout']);
t_ok('invalid enum -> default', $r['values']['default_model'] === DEFAULT_MODEL);
t_ok('non-numeric -> default', $r['values']['editor_font_size'] === $d['editor_font_size']);
t_ok('bool coercion', $r['values']['thinking_default'] === true);
t_ok('unknown key dropped', !array_key_exists('agentic_max_files_x', $r['values']));
t_ok('errors reported', isset($r['errors']['agentic_max_files'], $r['errors']['default_model']));

// Path resolution (no FS writes)
t_ok('empty path -> default', dspe_resolve_setting_path('', '/def') === '/def');
t_ok('relative -> under APP_ROOT', dspe_resolve_setting_path('sub/dir', '/def') === APP_ROOT . '/sub/dir');
t_ok('absolute kept', dspe_resolve_setting_path('/etc/app', '/def') === '/etc/app');
t_ok('trailing slash trimmed', dspe_resolve_setting_path('sub/', '/def') === APP_ROOT . '/sub');
t_ok('null byte rejected', dspe_resolve_setting_path("a\0b", '/def') === '/def');

// ---------------------------------------------------------------------------
// 2./3. PEEK map + agentic reading against an isolated fixture set
// ---------------------------------------------------------------------------
t_head('PEEK map + agentic reading (isolated fixtures)');

// Fixtures live UNDER the real WORK_DIR so include-path confinement (which only
// resolves targets inside the sandbox, by design) behaves exactly as in prod.
// We use the real peek_build() scan path, then clean up + refresh the cache.
$sub  = '__dspe_test_' . bin2hex(random_bytes(3));
$base = WORK_DIR . '/' . $sub;
@mkdir($base . '/inc', 0775, true);
file_put_contents($base . '/inc/util.php', "<?php\nfunction slugify(string \$s): string { return strtolower(\$s); }\nconst MAX = 10;\n");
file_put_contents($base . '/inc/model.php', "<?php\nnamespace App;\nclass User { public function name(): string { return 'x'; } }\n");
file_put_contents($base . '/main.php',
    "<?php\nrequire_once __DIR__ . '/inc/util.php';\nuse App\\User;\n" .
    "function go(){ \$u = new User(); echo slugify(\$u->name()); return MAX; }\n");
// A file that references a symbol but does NOT include it.
file_put_contents($base . '/orphan.php', "<?php\nfunction page(){ return slugify('Hi'); }\n");

$map = peek_build(true);   // real scan of WORK_DIR (includes our fixtures)
$kMain  = "$sub/main.php";
$kUtil  = "$sub/inc/util.php";
$kModel = "$sub/inc/model.php";
$kOrph  = "$sub/orphan.php";

t_ok('fixtures appear in map', isset($map['files'][$kMain], $map['files'][$kUtil], $map['files'][$kModel]));
t_ok('function signature captured', ($map['files'][$kUtil]['functions'][0]['sig'] ?? '') === '(string $s)',
    $map['files'][$kUtil]['functions'][0]['sig'] ?? 'none');
t_ok('constant captured', in_array('MAX', $map['files'][$kUtil]['constants'] ?? [], true));
t_ok('namespace captured', ($map['files'][$kModel]['namespace'] ?? '') === 'App');
t_ok('class captured', ($map['files'][$kModel]['classes'][0]['name'] ?? '') === 'User');
t_ok('method attributed to class', ($map['files'][$kModel]['functions'][0]['class'] ?? '') === 'User');

$edges = peek_dependency_edges($map);
t_ok('include edge resolved', ($edges[$kMain][0] ?? '') === $kUtil, json_encode($edges[$kMain] ?? null));

$idx = peek_symbol_index($map);
t_ok('symbol index: slugify', in_array($kUtil, $idx['slugify'] ?? [], true));
t_ok('symbol index: user class', in_array($kModel, $idx['user'] ?? [], true));

$rendered = peek_render($map, $kMain);
t_ok('render marks active file', strpos($rendered, '«active»') !== false);
t_ok('render lists requires', strpos($rendered, "requires: $kUtil") !== false);

// Agentic refs: main.php includes util.php AND references User (symbol) from model.php
$refs = dspe_refs_from_code(file_get_contents($base . '/main.php'), $kMain, $idx, $map);
t_ok('ref via include (util.php)', isset($refs[$kUtil]));
t_ok('ref via symbol (model.php User)', isset($refs[$kModel]), json_encode(array_keys($refs)));

// orphan.php uses slugify() with NO include -> still discovered by symbol
$refs2 = dspe_refs_from_code(file_get_contents($base . '/orphan.php'), $kOrph, $idx, $map);
t_ok('symbol-only discovery (no include)', isset($refs2[$kUtil]), json_encode(array_keys($refs2)));

// self-reference excluded
$refs3 = dspe_refs_from_code(file_get_contents($base . '/inc/util.php'), $kUtil, $idx, $map);
t_ok('self symbol not self-referenced', !isset($refs3[$kUtil]));

// full end-to-end bundle for the fixture entry file
$bundle = dspe_build_context($kMain, file_get_contents($base . '/main.php'));
$bpaths = array_column($bundle['files'], 'path');
t_ok('e2e bundle includes util + model', in_array($kUtil, $bpaths, true) && in_array($kModel, $bpaths, true), json_encode($bpaths));

// cleanup fixtures + refresh cache so the map returns to normal
array_map('unlink', glob($base . '/inc/*'));
array_map('unlink', glob($base . '/*.php'));
@rmdir($base . '/inc');
@rmdir($base);
peek_build(true);

// ---------------------------------------------------------------------------
// 4. Live working_folder context build (real WORK_DIR; read-only)
// ---------------------------------------------------------------------------
t_head('Live working_folder context build');

if (is_file(WORK_DIR . '/app.php')) {
    $code = file_get_contents(WORK_DIR . '/app.php');
    $ctx = dspe_build_context('app.php', $code);
    $paths = array_column($ctx['files'], 'path');
    t_ok('app.php pulls in helpers.php', in_array('includes/helpers.php', $paths, true), json_encode($paths));
    t_ok('app.php pulls in db.php', in_array('includes/db.php', $paths, true));
    t_ok('peek map included', $ctx['peek_used'] === true);

    // Budget enforcement
    $tiny = dspe_build_context('app.php', $code, ['max_files' => 1]);
    t_ok('max_files honored', count($tiny['files']) === 1 && !empty($tiny['notes']));

    $bytes = dspe_build_context('app.php', $code, ['max_bytes' => 100]);
    $hasSummary = false;
    foreach ($bytes['files'] as $f) { if ($f['summary'] || $f['truncated']) { $hasSummary = true; } }
    t_ok('byte budget triggers truncation/summary', $hasSummary);

    // Disabled agentic reading -> no files, but peek may still render
    $off = dspe_build_context('app.php', $code, ['agentic' => false, 'peek' => false]);
    t_ok('fully disabled -> empty bundle', empty($off['files']) && $off['peek'] === '');
} else {
    echo "  (skipped: working_folder/app.php fixture not present)\n";
}

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
printf("RESULT: %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail']);
exit($GLOBALS['__fail'] === 0 ? 0 : 1);
