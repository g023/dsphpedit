<?php
/**
 * api/csrf.php — Hand the client a fresh CSRF token.
 *
 *  GET -> { token }
 *
 * Purpose: lets the front-end recover transparently from a stale token. When a
 * state-changing POST is rejected with 403 (the session — and thus the token —
 * rotated or expired), the client GETs a fresh token here and retries once,
 * instead of surfacing a confusing failure to the operator.
 *
 * This endpoint is intentionally NOT behind api_guard(): it is a safe,
 * read-only GET that issues the token. csrf_token() boots the session and
 * mints one if none exists, so a brand-new session is handled too. No state is
 * changed, so there is nothing here for CSRF to protect.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';

security_headers();
json_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail('GET required', 405);
}

ok(['token' => csrf_token()]);
