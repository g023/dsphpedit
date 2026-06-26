<?php
/**
 * lib/security.php — Session bootstrap, CSRF, security response headers.
 *
 * Posture (see SECURITY.md): single trusted operator, bound to 127.0.0.1.
 * Preview executes user PHP = RCE by design. These controls are
 * defense-in-depth on top of localhost network isolation, never a substitute.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';

/**
 * Harden + start the session. Cookie params set BEFORE session_start().
 * `secure` is conditional on HTTPS (Secure breaks plain-http localhost).
 */
function security_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    // Keep PHP's GC from reaping sessions before our app-level idle timeout.
    // Without this, gc_maxlifetime (default 1440s) lets the GC delete the
    // session file long before SESSION_IDLE_TIMEOUT, dropping the CSRF token.
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_TIMEOUT);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $https,
    ]);
    session_name('DSPE_SESS');
    session_start();

    // Idle timeout.
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = $now;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    security_session_start();
    return $_SESSION['csrf_token'];
}

/** Constant-time CSRF check. Returns true on match. */
function csrf_verify(?string $token): bool
{
    security_session_start();
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/** Pull the CSRF token from header or body field. */
function csrf_from_request(array $input): ?string
{
    $h = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (is_string($h) && $h !== '') {
        return $h;
    }
    return isset($input['csrf_token']) ? (string) $input['csrf_token'] : null;
}

/**
 * Emit security response headers. Strict 'self' CSP is achievable precisely
 * because every asset is vendored (no CDN). frame-ancestors none + same-origin
 * preview iframe.
 */
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header_remove('X-Powered-By');

    $csp = "default-src 'self'; "
         . "img-src 'self' data: blob:; "
         . "media-src 'self' blob:; "
         . "style-src 'self' 'unsafe-inline'; "    // Ace injects inline styles
         . "script-src 'self'; "
         . "connect-src 'self'; "
         . "worker-src 'self' blob:; "             // Ace same-origin worker
         . "frame-src 'self'; "                    // same-origin preview iframe
         . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    $name = CSP_ENFORCE ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
    header($name . ': ' . $csp);
}

/**
 * Standard guard for a state-changing POST endpoint:
 *  - bootstraps state dirs, sends security headers + JSON headers
 *  - requires POST + valid CSRF
 * Returns the merged input array.
 */
function api_guard(): array
{
    require_once __DIR__ . '/response.php';
    dspe_bootstrap_state();
    security_session_start();
    security_headers();
    json_headers();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        fail('POST required', 405);
    }
    $input = read_input();
    if (!csrf_verify(csrf_from_request($input))) {
        fail('Invalid or missing CSRF token', 403);
    }
    return $input;
}
