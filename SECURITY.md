# Security Model — DS PHP Edit

## TL;DR — the one thing that matters

**Previewing user PHP is Remote Code Execution by design.** This product exists to run arbitrary, user-supplied PHP through the web server. **No PHP configuration directive makes that safe in an adversarial setting** — `eval`, `include`, and `require` are language constructs and cannot be disabled, and any editor that runs PHP can reach them.

The default and **only supported** posture is:

> **Single trusted operator, bound to `127.0.0.1` (localhost).**

Everything below is **defense-in-depth layered on top of network isolation** — speed bumps that raise the cost of mistakes, never a security boundary. The network boundary is the boundary.

---

## 1. Network isolation (the primary control)

Bind the editor + preview to **localhost only**:

- `php -S 127.0.0.1:8000 router.php`, or
- Apache: `Listen 127.0.0.1` and/or `Require ip 127.0.0.1` (a commented block ships in `.htaccess`).

Anyone who can reach the preview endpoint runs code as the PHP user. Do not expose this to an untrusted network.

## 2. Path confinement (every file op)

All filesystem access routes through a single gate, `safe_resolve()` in `lib/paths.php`:

- `realpath()`-based; rejects null bytes; decodes percent-encoding (incl. double-encoding) **before** validating.
- Rejects absolute paths, drive letters, and any all-dots traversal segment (`..`, `...`, `....`).
- Correct containment check — `$real === $base` or `str_starts_with($real, $base . DIRECTORY_SEPARATOR)` — **not** the prefix-collision-prone `strpos(...) === 0`.
- Not-yet-existing write targets are validated by canonicalizing the nearest existing ancestor and re-appending the remaining components (no escape via a missing parent).
- Because `realpath()` resolves symlinks, an in-folder symlink pointing outside the sandbox is rejected.

**Verification:** `php tools/test_paths.php` exercises `../`, `....//`, `..\`, URL/double-URL-encoded variants, absolute paths, Windows drive, null-byte (raw + encoded), mixed traversal, and an in-folder symlink to `/etc` — all rejected. Must print **ALL GREEN**.

## 3. Restricted preview execution context

`api/preview.php` executes the target file in a **separate `php` subprocess** (not in the web request), with:

- `open_basedir = working_folder/ : /tmp/` (confines file access),
- `memory_limit` cap and `max_execution_time` (15s),
- a hard wall-clock kill via GNU `timeout` (catches `sleep()`/blocking I/O that `max_execution_time` misses); falls back to a `proc_open` deadline if `timeout` is absent,
- `disable_functions` = `exec,passthru,shell_exec,system,proc_open,popen,dl,putenv,pcntl_exec,…`,
- `ffi.enable=0`, `allow_url_fopen=0`, `allow_url_include=0`,
- `display_errors=1` so fatals/warnings are visible to the operator (this is an operator-only tool).

**Documented limitation:** `eval`/`include`/`require` cannot be disabled. These directives are speed bumps, not a sandbox.

### Hardening further (recommended for any shared host)

For stronger isolation than in-process directives, run the preview under a **dedicated low-privilege PHP-FPM pool**:

```ini
; pool: dspe-preview
user = dspe-preview
listen = 127.0.0.1:9123
listen.allowed_clients = 127.0.0.1
php_admin_value[open_basedir] = /path/to/working_folder/:/tmp/
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,dl,putenv,mail,error_log
php_admin_flag[ffi.enable] = off
php_admin_value[allow_url_fopen] = 0
php_admin_value[allow_url_include] = 0
php_admin_value[memory_limit] = 128M
php_admin_value[max_execution_time] = 15
request_terminate_timeout = 15s
security.limit_extensions = .php
```

## 4. Upload safety (layered — each control has a known bypass alone)

`api/upload.php`:

- **finfo MIME allowlist** — `finfo(FILEINFO_MIME_TYPE)` on the bytes; the client filename/type is never trusted. Stored extension is derived from the validated MIME.
- **GD re-encode** of raster images on ingest — strips appended payloads, polyglot data, and EXIF; proves the bytes decode as a real image.
- **Random filenames** — `bin2hex(random_bytes(16))`.
- **Non-executable storage** — `working_folder/uploads/` ships a `.htaccess` (`engine off`, `RemoveHandler`, `Require all denied` for script extensions, `Options -ExecCGI`); an uploaded `.htaccess` is filtered from listings and the dir is excluded from PHP execution. Under `php -S`, requests are served by `router.php`, which never executes uploaded files as PHP.

## 5. App-layer hardening

- **CSRF:** synchronizer token in `$_SESSION` (`random_bytes(32)`), exposed via `<meta name="csrf-token">`, sent as `X-CSRF-Token`, verified with `hash_equals`. Every state-changing POST goes through `api_guard()`. `SameSite=Strict` cookie.
- **Session:** cookie params set before `session_start()` (`httponly`, `samesite=Strict`, `secure` **conditional on HTTPS** so plain-http localhost still works), `use_strict_mode=On`, `use_only_cookies=On`, idle timeout.
- **Response headers:** `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` (preview overrides to `SAMEORIGIN` for its own iframe), `Referrer-Policy: strict-origin-when-cross-origin`, and a strict **`Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'`** (extended with `img/media/worker` `'self'`/`blob:`/`data:` for assets). The no-CDN/vendor-everything rule makes a strict `'self'` CSP achievable. Toggle enforce vs report-only with `CSP_ENFORCE` in `config.php`.
- **Secret handling:** the DeepSeek key stays server-side in `lib/ds4.php`; the browser never receives it and never calls DeepSeek directly. The key is never logged, echoed, or reflected in any error payload (provider error messages are scrubbed of `sk-...` tokens). `K.dat` is denied over HTTP by `.htaccess` (Apache) and `router.php` (`php -S`).

## 6. Out of scope for v1 — untrusted exposure

If this is ever exposed beyond a trusted localhost operator, the preview **must** move to per-preview **container or microVM isolation** (Docker/gVisor/Firecracker/Kata): non-root, dropped capabilities, read-only rootfs, seccomp, resource quotas. In-PHP directives are **insufficient**. `runkit`/Suhosin are not viable sandboxes. This is a hard requirement, not a recommendation.

---

## Reporting

This is a single-operator local tool. If you adapt it for shared use and find an issue in the confinement/upload/restore logic, please open an issue at the project repository.
