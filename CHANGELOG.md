# Changelog

All notable changes to **DS PHP Edit** are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

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

### Removed
- Preview subprocess sandbox (`open_basedir`/`disable_functions`/`timeout` and
  the `PHP_CLI` resolver). Path confinement via `safe_resolve()` and localhost
  binding remain the security boundary; see `SECURITY.md` §3.

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

[1.0.1]: https://github.com/g023/dsphpedit/releases/tag/v1.0.1
[1.0.0]: https://github.com/g023/dsphpedit/releases/tag/v1.0.0
