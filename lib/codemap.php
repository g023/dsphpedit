<?php
/**
 * lib/codemap.php — PEEK: a compact structural map of working_folder/.
 *
 * Motivation (PEEK mapping, cf. arXiv:2605.19932): an LLM reasons far more
 * cheaply and accurately about a codebase when it is first handed a small,
 * high-signal *map* — what files exist, the symbols each defines, and how they
 * depend on each other — instead of being made to read every file. PEEK builds
 * exactly that map and renders it as a few hundred tokens, so the model can
 * "peek" at the whole project and then ask for only the files it actually needs.
 *
 * What we extract per file (PHP via the tokenizer; JS via light regex):
 *   - namespace, classes/interfaces/traits/enums (+ extends/implements)
 *   - functions / methods with reconstructed signatures
 *   - defined constants (define()/const)
 *   - include/require edges (resolved to working_folder-relative targets)
 *
 * The map is cached at PEEK_CACHE_FILE and rebuilt incrementally: only files
 * whose mtime/size changed are re-scanned, so repeated calls are cheap.
 *
 * @author  g023 (https://github.com/g023/)
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/paths.php';

// Extensions we map. PHP-family gets full tokenizer extraction; JS gets regex;
// the rest are listed for structure only (so the map reflects the real tree).
const PEEK_PHP_EXT  = ['php', 'phtml', 'inc'];
const PEEK_JS_EXT   = ['js', 'mjs'];
const PEEK_LIST_EXT = ['css', 'html', 'htm', 'json', 'md', 'sql', 'xml', 'yml', 'yaml', 'txt'];

// Directories never worth mapping (app state + uploaded media).
const PEEK_SKIP_DIRS = ['g023_backups', 'uploads', '.thumbs'];

// Individual app-managed files to keep out of the map (not user code).
const PEEK_SKIP_FILES = ['g023_history.json', '_cc_snapshot.html'];

/**
 * Build (or incrementally refresh) the PEEK map for WORK_DIR.
 * Returns ['generated'=>ts, 'root'=>'working_folder', 'count'=>N, 'files'=>[rel=>entry], 'truncated'=>bool].
 */
function peek_build(bool $force = false): array
{
    $cache = $force ? null : peek_load_cache();
    $prev  = is_array($cache['files'] ?? null) ? $cache['files'] : [];

    $files = [];
    $count = 0;
    $truncated = false;

    foreach (peek_iter_files(WORK_DIR) as [$abs, $rel]) {
        if ($count >= PEEK_MAX_FILES) { $truncated = true; break; }
        $count++;

        $mtime = @filemtime($abs) ?: 0;
        $size  = @filesize($abs) ?: 0;

        // Reuse a cached entry when the file is byte-for-byte unchanged.
        if (isset($prev[$rel]) && ($prev[$rel]['mtime'] ?? -1) === $mtime
            && ($prev[$rel]['size'] ?? -1) === $size) {
            $files[$rel] = $prev[$rel];
            continue;
        }
        $files[$rel] = peek_scan_file($abs, $rel, $mtime, $size);
    }

    ksort($files);
    $map = [
        'generated' => time(),
        'root'      => 'working_folder',
        'count'     => count($files),
        'truncated' => $truncated,
        'files'     => $files,
    ];
    peek_save_cache($map);
    return $map;
}

/** Lazily yield [absPath, relPath] for every mappable file under $base. */
function peek_iter_files(string $base): \Generator
{
    if (!is_dir($base)) {
        return;
    }
    $stack = [[$base, '']];
    while ($stack) {
        [$absDir, $relDir] = array_pop($stack);
        $entries = @scandir($absDir);
        if ($entries === false) {
            continue;
        }
        sort($entries);
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') { continue; }
            if ($name !== '' && $name[0] === '.') { continue; }   // dotfiles/dirs
            $abs = $absDir . DIRECTORY_SEPARATOR . $name;
            $rel = $relDir === '' ? $name : $relDir . '/' . $name;
            if (is_dir($abs)) {
                if (in_array($name, PEEK_SKIP_DIRS, true)) { continue; }
                $stack[] = [$abs, $rel];
                continue;
            }
            if (in_array($name, PEEK_SKIP_FILES, true)) { continue; }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, PEEK_PHP_EXT, true)
                || in_array($ext, PEEK_JS_EXT, true)
                || in_array($ext, PEEK_LIST_EXT, true)) {
                yield [$abs, $rel];
            }
        }
    }
}

/** Extract the PEEK entry for a single file. */
function peek_scan_file(string $abs, string $rel, int $mtime, int $size): array
{
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $entry = [
        'ext'       => $ext,
        'mtime'     => $mtime,
        'size'      => $size,
        'lines'     => 0,
        'namespace' => '',
        'classes'   => [],   // [{name,kind,extends,implements[]}]
        'functions' => [],   // [{name,sig,class}]
        'constants' => [],   // [name,...]
        'includes'  => [],   // [{target|raw, resolved:bool}]
    ];

    $src = @file_get_contents($abs, false, null, 0, MAX_EDIT_FILE_BYTES + 1);
    if ($src === false) {
        return $entry;
    }
    $entry['lines'] = substr_count($src, "\n") + 1;

    if (in_array($ext, PEEK_PHP_EXT, true)) {
        peek_scan_php($src, $abs, $entry);
    } elseif (in_array($ext, PEEK_JS_EXT, true)) {
        peek_scan_js($src, $entry);
    }
    return $entry;
}

/** Tokenizer-based PHP extraction. */
function peek_scan_php(string $src, string $abs, array &$entry): void
{
    $tokens = @token_get_all($src);
    if (!is_array($tokens)) {
        return;
    }
    $n = count($tokens);
    $fromDir = dirname($abs);

    // Track brace depth + a stack of class names so we can attribute methods.
    $depth = 0;
    $classStack = [];   // [depth => className]

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        if (is_string($t)) {
            if ($t === '{') { $depth++; }
            elseif ($t === '}') {
                if (isset($classStack[$depth])) { unset($classStack[$depth]); }
                $depth = max(0, $depth - 1);
            }
            continue;
        }

        [$id, $text] = [$t[0], $t[1]];

        switch ($id) {
            case T_NAMESPACE:
                $entry['namespace'] = peek_read_name($tokens, $i, $n);
                break;

            case T_CLASS:
            case T_INTERFACE:
            case T_TRAIT:
            case T_ENUM:
                // Skip anonymous class / ::class constant usages.
                $name = peek_next_ident($tokens, $i, $n);
                if ($name === null) { break; }
                $cls = [
                    'name'       => $name,
                    'kind'       => strtolower(str_replace('T_', '', token_name($id))),
                    'extends'    => '',
                    'implements' => [],
                ];
                peek_read_class_rel($tokens, $i, $n, $cls);
                $entry['classes'][] = $cls;
                // The class body opens at the next '{'; record its owner depth.
                $classStack[$depth + 1] = $name;
                break;

            case T_FUNCTION:
                $name = peek_next_ident($tokens, $i, $n);
                if ($name === null) { break; }   // closure / fn
                $sig = peek_read_params($tokens, $i, $n);
                $owner = '';
                // Method if we are currently inside a class body.
                for ($d = $depth; $d >= 1; $d--) {
                    if (isset($classStack[$d])) { $owner = $classStack[$d]; break; }
                }
                $entry['functions'][] = ['name' => $name, 'sig' => $sig, 'class' => $owner];
                break;

            case T_CONST:
                $name = peek_next_ident($tokens, $i, $n);
                if ($name !== null) { $entry['constants'][] = $name; }
                break;

            case T_STRING:
                if (strtolower($text) === 'define') {
                    $c = peek_read_define($tokens, $i, $n);
                    if ($c !== null) { $entry['constants'][] = $c; }
                }
                break;

            case T_INCLUDE:
            case T_INCLUDE_ONCE:
            case T_REQUIRE:
            case T_REQUIRE_ONCE:
                $inc = peek_read_include($tokens, $i, $n, $fromDir);
                if ($inc !== null) { $entry['includes'][] = $inc; }
                break;
        }
    }

    // De-dupe constants list.
    $entry['constants'] = array_values(array_unique($entry['constants']));
}

/** Read a namespace/qualified name following token $i (until ; or {). */
function peek_read_name(array $tokens, int $i, int $n): string
{
    $name = '';
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_string($t)) {
            if ($t === ';' || $t === '{') { break; }
            continue;
        }
        if (in_array($t[0], [T_WHITESPACE], true)) {
            if ($name !== '') { /* allow trailing space stop */ }
            continue;
        }
        if (in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name .= $t[1];
        } else {
            break;
        }
    }
    return trim($name);
}

/** First T_STRING identifier after token $i (skipping whitespace and '&'). */
function peek_next_ident(array $tokens, int $i, int $n): ?string
{
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_array($t) && $t[0] === T_STRING) { return $t[1]; }
        if (is_array($t) && in_array($t[0], [T_WHITESPACE], true)) { continue; }
        if (is_string($t) && ($t === '&' || trim($t) === '')) { continue; }
        return null;   // '(' => closure, etc.
    }
    return null;
}

/** Capture `extends X` / `implements A, B` for a class declaration. */
function peek_read_class_rel(array $tokens, int $i, int $n, array &$cls): void
{
    $mode = '';
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_string($t)) {
            if ($t === '{' || $t === ';') { return; }
            if ($t === ',') { continue; }
            continue;
        }
        if ($t[0] === T_EXTENDS)   { $mode = 'extends'; continue; }
        if ($t[0] === T_IMPLEMENTS) { $mode = 'implements'; continue; }
        if (in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            if ($mode === 'extends')        { $cls['extends'] .= $t[1]; }
            elseif ($mode === 'implements') {
                $last = count($cls['implements']) - 1;
                // accumulate qualified names split across NS separators
                if ($last >= 0 && substr($cls['implements'][$last], -1) === '\\') {
                    $cls['implements'][$last] .= $t[1];
                } else {
                    $cls['implements'][] = $t[1];
                }
            }
        }
    }
}

/** Reconstruct the parameter list "(...)" following a function name. */
function peek_read_params(array $tokens, int $i, int $n): string
{
    // Find the opening paren.
    $j = $i + 1;
    while ($j < $n) {
        $t = $tokens[$j];
        if (is_string($t) && $t === '(') { break; }
        $j++;
    }
    if ($j >= $n) { return '()'; }

    $out = '(';
    $par = 0;
    for ($k = $j; $k < $n; $k++) {
        $t = $tokens[$k];
        $txt = is_string($t) ? $t : $t[1];
        if ($txt === '(') { $par++; if ($k !== $j) { $out .= $txt; } continue; }
        if ($txt === ')') { $par--; $out .= $txt; if ($par === 0) { break; } continue; }
        // Collapse whitespace/newlines to single spaces for a tidy one-liner.
        if (is_array($t) && $t[0] === T_WHITESPACE) { $out .= ' '; continue; }
        $out .= $txt;
    }
    // Tidy: collapse runs of spaces.
    return preg_replace('/\s+/', ' ', trim($out));
}

/** Pull the constant name from define('NAME', ...). */
function peek_read_define(array $tokens, int $i, int $n): ?string
{
    // Expect: define ( 'NAME'
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_array($t) && $t[0] === T_WHITESPACE) { continue; }
        if (is_string($t) && $t === '(') { continue; }
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            return trim($t[1], "'\"");
        }
        return null;
    }
    return null;
}

/**
 * Read an include/require target. Captures string literals (concatenated),
 * ignoring __DIR__ / dirname(__FILE__) prefixes, and resolves the result to a
 * working_folder-relative path when it lands inside the sandbox.
 */
function peek_read_include(array $tokens, int $i, int $n, string $fromDir): ?array
{
    $literal = '';
    $hasExpr = false;
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_string($t)) {
            if ($t === ';') { break; }
            if ($t === '.' || $t === '(' || $t === ')') { continue; }
            $hasExpr = true;
            continue;
        }
        if (in_array($t[0], [T_WHITESPACE], true)) { continue; }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literal .= trim($t[1], "'\"");
            continue;
        }
        // __DIR__, dirname(__FILE__), variables, constants → expression part.
        $hasExpr = true;
    }

    $literal = trim($literal);
    if ($literal === '') {
        return null;   // fully dynamic include — nothing to map
    }

    $resolved = peek_resolve_include($literal, $fromDir);
    return [
        'raw'      => $literal,
        'target'   => $resolved,            // working_folder-relative, or null
        'resolved' => $resolved !== null,
        'dynamic'  => $hasExpr,             // had non-literal parts (e.g. __DIR__)
    ];
}

/**
 * Resolve an include literal to a working_folder-relative path.
 * Tries: relative to the including file's directory, then to WORK_DIR root.
 * Returns null if it can't be confined inside working_folder.
 */
function peek_resolve_include(string $literal, string $fromDir): ?string
{
    $lit = str_replace('\\', '/', $literal);
    // A literal like "/includes/helpers.php" extracted after __DIR__ is meant
    // to be relative to the file's dir — strip the leading slash.
    $bases = [];
    $bases[] = rtrim($fromDir, '/');
    $bases[] = rtrim(WORK_DIR, '/');

    $candidate = ltrim($lit, '/');
    foreach ($bases as $base) {
        $full = $base . '/' . $candidate;
        $real = realpath($full);
        if ($real === false) {
            // Not on disk; still try normalizing the lexical path.
            $real = peek_normalize($full);
            if (!is_file($real)) { continue; }
        }
        if (path_within(realpath(WORK_DIR) ?: WORK_DIR, $real)) {
            return rel_path(WORK_DIR, $real);
        }
    }
    return null;
}

/** Lexically normalize a path (collapse ./ and ../) without touching disk. */
function peek_normalize(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $isAbs = $path !== '' && $path[0] === '/';
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') { continue; }
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    return ($isAbs ? '/' : '') . implode('/', $parts);
}

/** Lightweight JS extraction (functions, classes, top-level consts). */
function peek_scan_js(string $src, array &$entry): void
{
    if (preg_match_all('/\bfunction\s+([A-Za-z_$][\w$]*)\s*\(([^)]*)\)/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $entry['functions'][] = ['name' => $mm[1], 'sig' => '(' . preg_replace('/\s+/', ' ', trim($mm[2])) . ')', 'class' => ''];
        }
    }
    if (preg_match_all('/\bclass\s+([A-Za-z_$][\w$]*)/', $src, $m)) {
        foreach ($m[1] as $name) {
            $entry['classes'][] = ['name' => $name, 'kind' => 'class', 'extends' => '', 'implements' => []];
        }
    }
    // const NAME = function/arrow → treat as a function-ish symbol.
    if (preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function|\([^)]*\)\s*=>)/', $src, $m)) {
        foreach ($m[1] as $name) {
            $entry['functions'][] = ['name' => $name, 'sig' => '()', 'class' => ''];
        }
    }
}

// ---------------------------------------------------------------------------
// Cache
// ---------------------------------------------------------------------------

function peek_load_cache(): array
{
    if (!is_file(PEEK_CACHE_FILE)) {
        return [];
    }
    $raw = @file_get_contents(PEEK_CACHE_FILE);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function peek_save_cache(array $map): void
{
    if (!is_dir(WORK_DIR)) {
        return;
    }
    $json = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    $tmp = PEEK_CACHE_FILE . '.tmp_' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, PEEK_CACHE_FILE);
        @chmod(PEEK_CACHE_FILE, 0664);
    } else {
        @unlink($tmp);
    }
}

// ---------------------------------------------------------------------------
// Indices + rendering (used by lib/context.php and api/peek.php)
// ---------------------------------------------------------------------------

/**
 * Symbol index: lower-cased symbol name => list of rel paths that define it.
 * Covers functions, methods (by bare method name), classes, and constants.
 */
function peek_symbol_index(array $map): array
{
    $idx = [];
    foreach ($map['files'] ?? [] as $rel => $e) {
        foreach ($e['functions'] ?? [] as $fn) {
            $idx[strtolower($fn['name'])][] = $rel;
        }
        foreach ($e['classes'] ?? [] as $c) {
            $idx[strtolower($c['name'])][] = $rel;
        }
        foreach ($e['constants'] ?? [] as $c) {
            $idx[strtolower($c)][] = $rel;
        }
    }
    foreach ($idx as $k => $v) {
        $idx[$k] = array_values(array_unique($v));
    }
    return $idx;
}

/**
 * Dependency edges from include/require: rel => [targets...].
 */
function peek_dependency_edges(array $map): array
{
    $edges = [];
    foreach ($map['files'] ?? [] as $rel => $e) {
        foreach ($e['includes'] ?? [] as $inc) {
            if (!empty($inc['target'])) {
                $edges[$rel][] = $inc['target'];
            }
        }
        if (isset($edges[$rel])) {
            $edges[$rel] = array_values(array_unique($edges[$rel]));
        }
    }
    return $edges;
}

/**
 * Render the map as a compact, token-efficient text block for AI prompts.
 * $focus (a rel path), when given, is marked so the model knows the entry file.
 */
function peek_render(array $map, ?string $focus = null, int $maxChars = 8000): string
{
    $files = $map['files'] ?? [];
    if (!$files) {
        return "PROJECT MAP (PEEK): working_folder is empty.";
    }
    $lines = [];
    $lines[] = "PROJECT MAP (PEEK) — {$map['count']} file(s) in working_folder/"
             . (!empty($map['truncated']) ? " (truncated)" : "");
    $lines[] = "Use this to locate code; ask for a file's full contents only when needed.";
    $lines[] = "";

    foreach ($files as $rel => $e) {
        $tag = ($focus !== null && $rel === $focus) ? ' «active»' : '';
        $head = "• {$rel} [{$e['ext']}, {$e['lines']}L]{$tag}";
        $lines[] = $head;

        if (!empty($e['namespace'])) {
            $lines[] = "    ns: {$e['namespace']}";
        }
        foreach ($e['classes'] ?? [] as $c) {
            $rel2 = '';
            if (!empty($c['extends']))    { $rel2 .= " extends {$c['extends']}"; }
            if (!empty($c['implements'])) { $rel2 .= " implements " . implode(', ', $c['implements']); }
            $lines[] = "    {$c['kind']} {$c['name']}{$rel2}";
        }
        $fns = [];
        foreach ($e['functions'] ?? [] as $fn) {
            $fns[] = ($fn['class'] !== '' ? "{$fn['class']}::" : '') . $fn['name'] . $fn['sig'];
        }
        if ($fns) {
            $lines[] = "    fn: " . implode(', ', $fns);
        }
        if (!empty($e['constants'])) {
            $lines[] = "    const: " . implode(', ', $e['constants']);
        }
        $deps = [];
        foreach ($e['includes'] ?? [] as $inc) {
            $deps[] = $inc['target'] ?? ($inc['raw'] . ($inc['resolved'] ? '' : ' (?)'));
        }
        if ($deps) {
            $lines[] = "    requires: " . implode(', ', array_unique($deps));
        }
    }

    $text = implode("\n", $lines);
    if (strlen($text) > $maxChars) {
        $text = substr($text, 0, $maxChars) . "\n… (map truncated for length)";
    }
    return $text;
}
