# Changelog

All notable changes to **DS PHP Edit** are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.1] — 2026-06-25

### Added
- **`api/csrf.php`** — a read-only `GET` endpoint that returns a fresh CSRF
  token, letting the client recover after a token rotates or its session is
  garbage-collected without forcing a full page reload.

### Fixed
- **Stale CSRF token → silent 403s.** PHP's session garbage collector
  (`session.gc_maxlifetime`, default 1440s) could reap the session file — and
  with it the CSRF token — long before the app-level `SESSION_IDLE_TIMEOUT`,
  causing state-changing POSTs (save, upload, AI edit) to fail with 403.
  `lib/security.php` now pins `session.gc_maxlifetime` to `SESSION_IDLE_TIMEOUT`
  so the token survives as long as the session is meant to.
- **Transparent CSRF retry on the client.** `assets/js/app.js` wraps requests in
  `withCsrfRetry()`: on a 403 it fetches a fresh token from `api/csrf.php` (one
  coalesced GET for a burst of failures), updates the cached value and the
  `<meta>` tag, then retries the original request once. Uploads rebuild their
  `FormData` per attempt so the refreshed token rides in both the field and the
  header.

## [1.1.0] — 2026-06-24

### Added
- **Operator settings panel (⚙)** — configure an **alternate working folder** and
  **alternate API-key path** (relative *or* absolute), default model, thinking
  on/off + reasoning effort, DeepSeek timeout, editor font/tab-size/word-wrap,
  auto-backup-on-save, and the agentic-reading/PEEK knobs. Stored in
  `dspe_settings.json` at the repo root — **outside `working_folder/`** so a
  folder switch never loses it, and **denied over HTTP** (`.htaccess` +
  `router.php`) alongside `K.dat`. `lib/settings.php` owns the schema, validation
  (types/ranges clamped, unknown keys dropped, app-bricking paths rejected) and
  the atomic save; `api/settings.php` is the get/save/reset endpoint. Changing the
  working folder or key path prompts a reload. `config.php` resolves every
  constant (`WORK_DIR`, `KEY_FILE`, `DEEPSEEK_TIMEOUT`, …) from settings so the
  whole app re-points from one place; `WORK_DIR_IN_APPROOT` gates server-side
  Preview when the folder isn't web-reachable.
- **PEEK project map (🗺)** — a compact structural index of `working_folder/`
  (per-file functions/methods/classes/consts with signatures, namespaces, and
  include/require dependency edges) extracted with `token_get_all()`, cached at
  `working_folder/.g023_peek.json` and rebuilt **incrementally** (only changed
  files re-scan). `peek_render()` (in `lib/codemap.php`) turns it into a
  few-hundred-token block the AI is given so it grasps the whole project without
  reading every file. Browse it in the 🗺 panel; `api/peek.php` serves
  render / structured / symbols / deps.
- **Agentic reading (🔍)** — when you ask the AI about (or to edit) a file,
  `lib/context.php` auto-gathers the context that file needs: it follows the
  code's own `include`/`require` edges **and** resolves symbol references (a
  call/`new`/const used here but defined elsewhere) via the PEEK symbol index,
  breadth-first to `AGENTIC_MAX_DEPTH` and bounded by
  `AGENTIC_MAX_FILES`/`AGENTIC_MAX_BYTES` (over-budget files degrade to their PEEK
  summary). Deterministic — no extra LLM round-trips. Injected as a system message
  by `api/ai_chat.php` and `ai_tools/assist.php`; the response carries a `context`
  block the UI shows as a **"🔍 read N related files"** badge. Disable per-call
  (`context=0`) or globally via settings.
- **`tools/test_features.php`** — in-process assertion suite (36 checks) covering
  settings load/validate/save, the PEEK map builder, and agentic context
  gathering. Run alongside `tools/selfcheck.php` after touching any of these.

### Changed
- `config.php` now loads `dspe_settings.json` first and derives `WORK_DIR`,
  `KEY_FILE`, `DEEPSEEK_TIMEOUT`, `AUTO_BACKUP_ON_SAVE`, the agentic/PEEK budgets,
  and `PREFERRED_MODEL` from it — defaults are unchanged when no settings file is
  present (zero-config). The server-side hard default stays `deepseek-v4-flash`
  everywhere; `PREFERRED_MODEL` only changes which allowlisted model the UI
  pre-selects.
- `.htaccess` and `router.php` deny `dspe_settings.json` (and its `.tmp_*`
  writes) over HTTP.
- `api/ai_chat.php` and `ai_tools/assist.php` attach the agentic context block and
  PEEK map to AI requests; `api/files.php` keeps the PEEK cache fresh on write.

### Fixed
- **`tools/test_paths.php` "exists" cases no longer depend on a stray `test.php`**
  in `working_folder/`. The suite now provisions its own throwaway fixture inside
  `WORK_DIR` (and removes it), so it is **15/15 green on a fresh release** that
  ships only the `welcome.php` sample, instead of failing two cases.

## [1.0.1] — 2026-06-24

### Changed
- **Server-side preview now executes the file natively through the web server**
  instead of a PHP-CLI subprocess. `api/preview.php` validates the path and
  302-redirects the iframe to the file's real URL under `working_folder/`, so it
  runs exactly as in production — real `$_SERVER`, `header()`, sessions, and
  `.htaccess`. Works under Apache/mod_php, PHP-FPM, and `php -S router.php`.

### Fixed
- **Apache worker crash on WAMP/XAMPP when clicking Preview**
  (`mpm_winnt:crit The pipe has been ended. Unable to retrieve my generation
  from the parent.`). The old preview spawned `PHP_BINARY` as a subprocess, but
  under mod_php `PHP_BINARY` is the web-server binary (`httpd.exe`) — so Preview
  launched a second Apache and killed the worker. Native execution removes the
  subprocess (and the PHP-CLI dependency) entirely.
- **Lint tool (`tools/analyze.php`) under the web SAPI.** It used the same
  broken `PHP_BINARY ?: 'php'` resolver as the old preview: under
  apache2handler/PHP-FPM `PHP_BINARY` is empty or names the server binary, so
  lint only worked by luck when `php` happened to be on the server's `PATH`
  (and could spawn the web-server binary where it wasn't). Lint is now done
  **entirely in-process** with `token_get_all(…, TOKEN_PARSE)` — the same
  syntax/parse/compile errors `php -l` reports, with **no PHP-CLI binary and no
  `proc_open`**. Works identically on locked-down shared hosting, WAMP/XAMPP,
  and dev boxes; the project no longer depends on a PHP CLI anywhere.

### Removed
- Preview subprocess sandbox (`open_basedir`/`disable_functions`/`timeout` and
  the `PHP_CLI` resolver). Path confinement via `safe_resolve()` and localhost
  binding remain the security boundary; see `SECURITY.md` §3.
- The lint tool's PHP-CLI resolver and `proc_open`/`php -l` path
  (`php_cli_binary()` / `lint_via_cli()` in `tools/analyze.php`), plus the
  `proc_open` and GNU-`timeout` probes in `tools/selfcheck.php`. The project no
  longer shells out to a PHP binary for any feature — preview and lint both run
  under the native webserver's PHP.

## [1.0.0] — 2026-06-23

First public release. 🎉

### Added
- **Server-side PHP preview** — executes the saved file through a sandboxed
  subprocess (timeout + memory caps) and renders real output in a same-origin
  iframe. PHP errors/warnings/fatals are surfaced.
- **Ace editor** (vendored, no CDN) with syntax highlighting + line numbers for
  PHP/HTML/JS/CSS/JSON/Markdown, language auto-selected by extension, `Ctrl+S`
  to save, dirty-state tracking, multi-file tabs with persisted UI state.
- **DeepSeek V4 AI assistant** — three modes: **Explain** (Q&A), **Full**
  (whole-file rewrite → *Apply full code*), **Edit** (JSON search/replace →
  *Apply edits*). Optional **Thinking** mode surfaces reasoning. Token usage shown.
- **AI inline code completion** — Copilot-style ghost-text (fill-in-the-middle),
  Manual or Auto trigger, accept-all / accept-word, persisted per browser.
  Suggestions are overlap-deduped server-side (`dspe_trim_prefix_overlap` /
  `dspe_trim_suffix_overlap`) against the real prefix and the full untruncated
  suffix, so spliced completions never double up closers, `//`, or whole lines.
- **File explorer** scoped to `working_folder/` — create / rename / delete files
  and folders, nested directories, app-managed state hidden from the picker.
- **Media library** — upload images/PDF/audio/video with a server-side MIME
  allowlist (finfo), GD re-encode (strips EXIF/payloads), auto thumbnails,
  randomized filenames, a non-executable upload directory, and snippet insertion.
- **Conversation history** — persisted to `working_folder/g023_history.json`,
  browsable, reloadable, clearable, rotated.
- **Backup / restore** — one-click atomic ZIP of `working_folder/`, Zip-Slip-safe
  restore (per-entry validation, stream extraction, temp-dir-then-swap),
  retention pruning.
- **Analysis tools** — non-AI `php -l` lint, structure outline, metrics; AI-driven
  explain / refactor / docblocks / find-bugs.
- **Security hardening** — strict `'self'` CSP, CSRF on every state-changing POST,
  hardened sessions, security headers, single `safe_resolve()` path gate, and a
  DeepSeek key that never reaches the browser.
- **Zero-install, path-independent** deployment — works under Apache userdir/vhost
  or the built-in `php -S` server via `router.php`; all runtime state self-creates.
- Self-check tooling: `tools/selfcheck.php` (environment) and `tools/test_paths.php`
  (path-traversal test suite).

[1.1.0]: https://github.com/g023/dsphpedit/releases/tag/v1.1.0
[1.0.1]: https://github.com/g023/dsphpedit/releases/tag/v1.0.1
[1.0.0]: https://github.com/g023/dsphpedit/releases/tag/v1.0.0
