<?php
/**
 * lib/paths.php — Path confinement. The SOLE gate for all file I/O.
 *
 * Every endpoint that touches the filesystem must resolve user-supplied paths
 * through safe_resolve(). It is realpath()-based, rejects null bytes, decodes
 * percent-encoding before validating, and uses a prefix check that is immune
 * to the classic prefix-collision bug (/var/www vs /var/www-evil).
 *
 * @license MIT
 */

/**
 * Resolve $userPath against $baseDir, confining it inside $baseDir.
 *
 * @param string $baseDir       Trusted base directory (e.g. WORK_DIR).
 * @param string $userPath      Untrusted relative path from the client.
 * @param bool   $mustExist     If true, target must already exist.
 * @return string               Absolute, confined path.
 * @throws RuntimeException      On any traversal / invalid input.
 */
function safe_resolve(string $baseDir, string $userPath, bool $mustExist = true): string
{
    // 1. Reject null bytes outright (truncation attacks).
    if (strpos($userPath, "\0") !== false || strpos($baseDir, "\0") !== false) {
        throw new RuntimeException('Invalid path: null byte');
    }

    // 2. Decode percent-encoding BEFORE validating (defeats %2e%2e/%2f tricks).
    //    Decode repeatedly to catch double-encoding.
    $decoded = $userPath;
    for ($i = 0; $i < 3; $i++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            break;
        }
        $decoded = $next;
    }
    if (strpos($decoded, "\0") !== false) {
        throw new RuntimeException('Invalid path: null byte (encoded)');
    }

    // 3. Normalise separators, then REJECT (not silently strip) absolute paths,
    //    drive letters, and any traversal-style component. We reject rather than
    //    sanitize so hostile input never resolves to a surprising in-sandbox
    //    location either.
    $decoded = str_replace('\\', '/', $decoded);
    if ($decoded !== '' && $decoded[0] === '/') {
        throw new RuntimeException('Invalid path: absolute path');
    }
    if (preg_match('#^[A-Za-z]:#', $decoded)) {
        throw new RuntimeException('Invalid path: drive path');
    }
    // Any segment that is purely dots (.. ... ....) is a traversal vector.
    foreach (explode('/', $decoded) as $segment) {
        if ($segment !== '' && $segment !== '.' && preg_match('/^\.{2,}$/', $segment)) {
            throw new RuntimeException('Invalid path: traversal component');
        }
    }
    $decoded = ltrim($decoded, '/');

    // 4. Canonicalise the trusted base.
    $base = realpath($baseDir);
    if ($base === false) {
        throw new RuntimeException('Base directory does not exist');
    }

    $candidate = $base . DIRECTORY_SEPARATOR . $decoded;

    $real = realpath($candidate);
    if ($real === false) {
        // Target may not exist yet (create/write), and neither may some of its
        // parent dirs (nested create). Walk up to the nearest EXISTING ancestor,
        // verify it is inside base, then confirm the remaining (not-yet-created)
        // components contain no traversal. This re-appends the trailing path to
        // a canonicalized existing ancestor — no escape is possible.
        if ($mustExist) {
            throw new RuntimeException('Path does not exist');
        }
        $name = basename($candidate);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('Invalid target name');
        }
        $parent    = dirname($candidate);
        $trailing  = [];
        $realParent = false;
        // Climb until an ancestor resolves, collecting the missing tail.
        while (true) {
            $rp = realpath($parent);
            if ($rp !== false) {
                $realParent = $rp;
                break;
            }
            $segment = basename($parent);
            if ($segment === '..' || $segment === '') {
                throw new RuntimeException('Invalid path component');
            }
            array_unshift($trailing, $segment);
            $next = dirname($parent);
            if ($next === $parent) {
                break; // reached filesystem root without resolving
            }
            $parent = $next;
        }
        if ($realParent === false || !path_within($base, $realParent)) {
            throw new RuntimeException('Path escapes sandbox');
        }
        $tail = $trailing ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $trailing) : '';
        return $realParent . $tail . DIRECTORY_SEPARATOR . $name;
    }

    // 5. Existing target: enforce the correct prefix check.
    if (!path_within($base, $real)) {
        throw new RuntimeException('Path escapes sandbox');
    }
    return $real;
}

/**
 * Correct containment check: equal to base, or a true descendant.
 * NOT strpos(...)===0 (which allows /var/www-evil to pass for /var/www).
 */
function path_within(string $base, string $real): bool
{
    return $real === $base
        || str_starts_with($real, $base . DIRECTORY_SEPARATOR);
}

/**
 * Convert an absolute confined path back to a path relative to $baseDir,
 * using forward slashes (for the UI / client).
 */
function rel_path(string $baseDir, string $absPath): string
{
    $base = realpath($baseDir) ?: $baseDir;
    if ($absPath === $base) {
        return '';
    }
    $rel = ltrim(substr($absPath, strlen($base)), DIRECTORY_SEPARATOR . '/');
    return str_replace('\\', '/', $rel);
}
