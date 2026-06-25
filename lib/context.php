<?php
/**
 * lib/context.php — Agentic reading: auto-gather the code context the AI needs.
 *
 * When the operator asks the AI about (or to edit) a file, that file rarely
 * stands alone — it `require`s helpers, calls functions defined elsewhere, uses
 * classes from other files. A human reviewer would open those files; this module
 * does the same automatically, so the model answers with full knowledge instead
 * of guessing about code it was never shown.
 *
 * How it works (deterministic, bounded, testable):
 *   1. Start from the active file + its CURRENT (possibly unsaved) buffer.
 *   2. Follow include/require edges and resolve symbol references (functions,
 *      classes, constants) against the PEEK map's symbol index.
 *   3. Breadth-first expand to AGENTIC_MAX_DEPTH, collecting related files up to
 *      AGENTIC_MAX_FILES / AGENTIC_MAX_BYTES. Past the byte budget, a file is
 *      represented by its PEEK summary instead of full text (graceful degrade).
 *   4. Prepend a compact PEEK project map so the model also has the big picture.
 *
 * This is "agentic" in effect — the system reads what the code itself points to —
 * while staying fully deterministic (no extra round-trips, no flaky tool loops),
 * which is what makes it fast and reliably testable.
 *
 * @author  g023 (https://github.com/g023/)
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/codemap.php';

/**
 * Build the auto-context bundle for a given active file + buffer.
 *
 * @return array{
 *   files: array<int,array{path:string,content:string,reason:string,truncated:bool,summary:bool}>,
 *   peek: string,
 *   peek_used: bool,
 *   notes: string[],
 *   bytes: int
 * }
 */
function dspe_build_context(string $file, string $code, array $opts = []): array
{
    $out = ['files' => [], 'peek' => '', 'peek_used' => false, 'notes' => [], 'bytes' => 0];

    $agentic   = $opts['agentic']  ?? AGENTIC_READING;
    $usePeek   = $opts['peek']     ?? PEEK_ENABLED;
    $maxFiles  = (int) ($opts['max_files'] ?? AGENTIC_MAX_FILES);
    $maxBytes  = (int) ($opts['max_bytes'] ?? AGENTIC_MAX_BYTES);
    $maxDepth  = (int) ($opts['max_depth'] ?? AGENTIC_MAX_DEPTH);

    if (!$agentic && !$usePeek) {
        return $out;
    }

    // The PEEK map underpins both features (symbol lookup + the rendered map).
    $map = peek_build(false);

    if ($usePeek) {
        $out['peek'] = peek_render($map, $file !== '' ? $file : null);
        $out['peek_used'] = $out['peek'] !== '';
    }

    if (!$agentic || $maxFiles <= 0) {
        return $out;
    }

    $symIndex = peek_symbol_index($map);
    $active   = $file !== '' ? $file : null;

    // BFS over dependency + symbol edges. The active file's refs come from the
    // LIVE buffer ($code); everything else is read from disk.
    $visited = [];                 // rel => true (already expanded or queued)
    $collected = [];               // ordered list of [rel, reason]
    if ($active !== null) { $visited[$active] = true; }

    // Seed queue with the active file's references.
    $queue = [];   // [rel, depth, reason]
    foreach (dspe_refs_from_code($code, $active, $symIndex, $map) as $rel => $reason) {
        if ($active !== null && $rel === $active) { continue; }
        if (isset($visited[$rel])) { continue; }
        $visited[$rel] = true;
        $queue[] = [$rel, 1, $reason];
    }

    while ($queue) {
        [$rel, $depth, $reason] = array_shift($queue);
        $collected[] = [$rel, $reason];

        if ($depth >= $maxDepth || count($collected) >= $maxFiles * 3) {
            continue;   // cap exploration generously; final inclusion is bounded below
        }

        // Expand this file's own references (from disk).
        $abs = dspe_safe_abs($rel);
        if ($abs === null) { continue; }
        $childCode = @file_get_contents($abs, false, null, 0, MAX_EDIT_FILE_BYTES + 1);
        if ($childCode === false) { continue; }

        foreach (dspe_refs_from_code($childCode, $rel, $symIndex, $map) as $crel => $creason) {
            if (isset($visited[$crel])) { continue; }
            $visited[$crel] = true;
            $queue[] = [$crel, $depth + 1, $creason];
        }
    }

    // Materialize collected files within the byte/file budget.
    $bytes = 0;
    $included = 0;
    foreach ($collected as [$rel, $reason]) {
        if ($included >= $maxFiles) {
            $out['notes'][] = "More related files exist; capped at {$maxFiles} (see project map).";
            break;
        }
        $abs = dspe_safe_abs($rel);
        if ($abs === null || !is_file($abs)) { continue; }

        $content = (string) @file_get_contents($abs, false, null, 0, MAX_EDIT_FILE_BYTES + 1);
        $len = strlen($content);

        if ($bytes + $len > $maxBytes) {
            $remaining = $maxBytes - $bytes;
            if ($remaining > 400) {
                // Include a truncated head so the model still sees the top of it.
                $out['files'][] = [
                    'path'      => $rel,
                    'content'   => substr($content, 0, $remaining) . "\n/* … truncated by agentic-reading budget … */",
                    'reason'    => $reason,
                    'truncated' => true,
                    'summary'   => false,
                ];
                $bytes += $remaining;
                $included++;
            } else {
                // Out of byte budget — represent by its map summary only.
                $out['files'][] = [
                    'path'      => $rel,
                    'content'   => dspe_file_summary($map, $rel),
                    'reason'    => $reason . ' (summary only — context budget reached)',
                    'truncated' => true,
                    'summary'   => true,
                ];
                $included++;
            }
            continue;
        }

        $out['files'][] = [
            'path'      => $rel,
            'content'   => $content,
            'reason'    => $reason,
            'truncated' => false,
            'summary'   => false,
        ];
        $bytes += $len;
        $included++;
    }

    $out['bytes'] = $bytes;
    return $out;
}

/**
 * Extract the files referenced by a chunk of code:
 *  - include/require targets (resolved to working_folder-relative paths)
 *  - files defining any symbol (function/class/const) the code uses
 * Returns [rel => human reason]. The reason for a path is the FIRST one found.
 */
function dspe_refs_from_code(string $code, ?string $fromRel, array $symIndex, array $map): array
{
    $refs = [];
    $tokens = @token_get_all($code);
    if (!is_array($tokens)) {
        return $refs;
    }
    $n = count($tokens);
    $fromDir = $fromRel !== null
        ? dirname(realpath(WORK_DIR) . DIRECTORY_SEPARATOR . $fromRel)
        : (realpath(WORK_DIR) ?: WORK_DIR);

    // Symbols defined IN this same file shouldn't pull the file in via itself.
    $selfSymbols = [];
    if ($fromRel !== null && isset($map['files'][$fromRel])) {
        $e = $map['files'][$fromRel];
        foreach ($e['functions'] ?? [] as $fn) { $selfSymbols[strtolower($fn['name'])] = true; }
        foreach ($e['classes'] ?? [] as $c)   { $selfSymbols[strtolower($c['name'])] = true; }
        foreach ($e['constants'] ?? [] as $c) { $selfSymbols[strtolower($c)] = true; }
    }

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) { continue; }
        $id = $t[0];

        // include/require edges
        if (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
            $inc = peek_read_include($tokens, $i, $n, $fromDir);
            if ($inc !== null && !empty($inc['target']) && !isset($refs[$inc['target']])) {
                $refs[$inc['target']] = 'included by ' . ($fromRel ?? 'active file');
            }
            continue;
        }

        // symbol references: any identifier that the project defines elsewhere
        if ($id === T_STRING) {
            $name = strtolower($t[1]);
            if (isset($selfSymbols[$name])) { continue; }
            if (!isset($symIndex[$name])) { continue; }
            foreach ($symIndex[$name] as $defRel) {
                if ($defRel === $fromRel) { continue; }
                if (!isset($refs[$defRel])) {
                    $refs[$defRel] = "defines `{$t[1]}` used by " . ($fromRel ?? 'active file');
                }
            }
        }
    }
    return $refs;
}

/** Resolve a working_folder-relative path to a confined absolute path, or null. */
function dspe_safe_abs(string $rel): ?string
{
    try {
        return safe_resolve(WORK_DIR, $rel, true);
    } catch (\RuntimeException $e) {
        return null;
    }
}

/** A one-file PEEK summary (used when the byte budget is exhausted). */
function dspe_file_summary(array $map, string $rel): string
{
    $e = $map['files'][$rel] ?? null;
    if (!$e) { return "// {$rel} (no summary available)"; }
    $parts = ["// PEEK summary of {$rel} [{$e['ext']}, {$e['lines']}L]"];
    if (!empty($e['namespace'])) { $parts[] = "// namespace {$e['namespace']}"; }
    foreach ($e['classes'] ?? [] as $c) { $parts[] = "// {$c['kind']} {$c['name']}"; }
    foreach ($e['functions'] ?? [] as $fn) {
        $parts[] = "// fn " . ($fn['class'] !== '' ? "{$fn['class']}::" : '') . $fn['name'] . $fn['sig'];
    }
    if (!empty($e['constants'])) { $parts[] = "// const " . implode(', ', $e['constants']); }
    return implode("\n", $parts);
}

/**
 * Render the context bundle as a single message string for the AI prompt.
 * Returns '' when there is nothing useful to add.
 */
function dspe_context_message(array $ctx, string $activeFile = ''): string
{
    $hasFiles = !empty($ctx['files']);
    $hasPeek  = !empty($ctx['peek']);
    if (!$hasFiles && !$hasPeek) {
        return '';
    }

    $blocks = [];
    $blocks[] = "=== PROJECT CONTEXT (auto-gathered by DS PHP Edit's agentic reading) ===";
    $blocks[] = "The user is working on: " . ($activeFile !== '' ? $activeFile : '(unsaved buffer)');
    $blocks[] = "This context was assembled automatically by following the code's own "
              . "includes and symbol references. Use it to answer accurately. The CURRENT "
              . "code of the active file is provided separately in the user message; the "
              . "snippets below are SUPPORTING files — do not rewrite them unless asked.";

    if ($hasPeek) {
        $blocks[] = "\n" . $ctx['peek'];
    }

    if ($hasFiles) {
        $blocks[] = "\n--- Related files (auto-included) ---";
        foreach ($ctx['files'] as $f) {
            $lang = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION)) ?: 'txt';
            $blocks[] = "\n### {$f['path']}  ({$f['reason']})";
            if (!empty($f['summary'])) {
                $blocks[] = $f['content'];
            } else {
                $blocks[] = "```{$lang}\n" . $f['content'] . "\n```";
            }
        }
    }

    foreach ($ctx['notes'] as $note) {
        $blocks[] = "\nNote: " . $note;
    }

    $blocks[] = "\n=== END PROJECT CONTEXT ===";
    return implode("\n", $blocks);
}
