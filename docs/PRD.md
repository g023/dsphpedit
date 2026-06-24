# Product Requirements Document — DS PHP Edit

**A PHP-based, DeepSeek V4-powered web IDE for editing and live-previewing PHP applications.**

| | |
|---|---|
| **Product name** | DS PHP Edit |
| **Author / Owner** | g023 — https://github.com/g023/ |
| **License** | MIT |
| **Document status** | Draft v1.0 — for build kickoff |
| **Last updated** | 2026-06-23 |
| **Target runtime** | PHP 8.4.22, Apache (userdir), single host |
| **Repo root** | `/home/ollama/public_html/php_builder/` → `http://localhost/~ollama/php_builder/` |

---

## 1. Overview & Vision

DS PHP Edit is a self-hosted, single-server web IDE for rapidly editing and previewing PHP applications with AI assistance. A user drops a PHP project into `working_folder/`, opens a file in a browser-based editor (syntax highlighting + line numbers), asks DeepSeek V4 for help (explanations, full-file rewrites, or surgical search/replace edits), and clicks **Preview** to execute the edited PHP **server-side** and see the rendered output.

The product's differentiator is the **tight loop between AI-assisted editing and real server-side PHP execution** — unlike client-side HTML playgrounds, the preview runs actual PHP through the web server so dynamic apps render truthfully.

### The single most important framing

**The preview feature is Remote Code Execution by design.** The product exists to run user-supplied PHP. No PHP configuration directive makes this "safe" in an adversarial setting. The default and supported deployment posture is therefore **single-user, trusted operator, bound to `127.0.0.1` (localhost)**. Every security control in this document is defense-in-depth layered on top of network isolation, never a substitute for it. See §9.

---

## 2. Current State (Baseline)

The repo is **greenfield with a working prototype**:

- **`page_editor.php`** (~810 lines, single file) — demonstrates the editor + AI chat + preview loop, but:
  - Targets **client-side HTML** preview via iframe `srcdoc` (renders static HTML only — **does not execute PHP**). This is the central gap to close.
  - Reads the API key from `file_get_contents('../../K.dat')` — **wrong path** for the current layout; the key is at `./K.dat`. Must use `__DIR__ . '/K.dat'`.
  - Loads jQuery **and** would load a code editor from a **CDN** — violates the no-CDN rule.
  - Monolithic — all concerns in one file.
- **`functions.php`** — currently only an educational prompt template (worksheet generator) in a comment block; not wired into anything. Treat as scratch/sample content, not architecture.
- **`K.dat`** — one line, `sk-...` (35 chars), the DeepSeek API key. Confirmed present.
- **`working_folder/test.php`** — a trivial sample PHP file (note: it calls `php_info()`, which is not a real function — `phpinfo()` is — useful as a "broken file" preview test case).

**Treat `page_editor.php` as a reference for AI integration and UI patterns, not as the architecture to extend in place.** The target is a modular app.

---

## 3. Goals & Non-Goals

### 3.1 Goals (v1.0, production-ready)

1. Modular, multi-file PHP application (no monolith).
2. File picker scoped to `working_folder/`, with create/rename/delete and subdirectory navigation.
3. Code editor with **syntax highlighting + line numbers** (PHP, HTML, JS, CSS), fully **vendored locally** (no CDN).
4. **Server-side PHP preview** — execute the edited file through the web server and display rendered output.
5. DeepSeek V4 AI assistant with three behaviors: **explain** (Q&A), **full** (return entire updated file), **edit** (return JSON search/replace ops). Default model `deepseek-v4-flash`; `deepseek-v4-pro` selectable but never default.
6. Optional **thinking mode** surfacing `reasoning_content`.
7. **Media upload** (images / PDF / audio / etc.) with finfo MIME validation, auto-generated thumbnails (GD), and a **media browser** to insert assets into edited files.
8. **Conversation history** persisted to `working_folder/g023_history.json`, browsable in the UI.
9. **Backup / restore** of the working folder via ZIP (`working_folder/g023_backups/`), with retention.
10. **Code analysis** features (non-AI lint/structure tools in `tools/`, AI-assisted analysis in `ai_tools/`).
11. Hardened security baseline (path confinement, CSRF, session, headers, key never reaching the browser).

### 3.2 Non-Goals (v1.0)

- Multi-tenant / multi-user concurrent collaboration (real-time co-editing). Single trusted operator only.
- Running DS PHP Edit safely on a public, untrusted internet host without container/microVM isolation (explicitly out of scope; documented as a hard requirement if ever attempted — see §9.6).
- Full Git integration / version control beyond ZIP backups.
- Composer/dependency management for the edited project.
- Debugger / breakpoints / step execution.
- Mobile-first UI (desktop browser is the target).
- PDF *content* thumbnailing without adding Imagick + Ghostscript (see §8.4 open item).

---

## 4. Personas & Use Cases

| Persona | Description | Primary needs |
|---|---|---|
| **Solo developer (primary)** | Owns the machine; edits a personal/prototype PHP app locally. | Fast edit→AI→preview loop, no setup friction, trustworthy file handling. |
| **Educator / content author** | Generates PHP-rendered worksheets/tools (cf. `functions.php`). | AI generation, preview of rendered output, media insertion. |
| **Tinkerer / learner** | Learning PHP by editing and previewing. | Clear errors, AI explanations, safe undo via backups. |

**Core user journey:** open file → read/understand (AI explain) → request change (AI full or edit) → apply → preview server-side → iterate → backup.

---

## 5. Target Architecture

Modular files; separation of concerns. The prototype's "single PHP file returns JSON for `POST action=...`, serves its page on `GET`" pattern is kept **per endpoint**.

```
php_builder/
├── index.php                  # Front-end shell (editor UI, panes, asset includes)
├── config.php                 # Paths, constants (DEFAULT_MODEL), feature flags
├── K.dat                      # DeepSeek API key (sk-...), repo root, never web-served
├── lib/
│   ├── ds4.php                # Canonical DeepSeek connector: ds4_chat() / llm_get()
│   ├── paths.php              # safe_resolve() path-confinement helper (gates ALL file I/O)
│   ├── security.php           # CSRF token issue/verify, session bootstrap, headers
│   └── response.php           # JSON envelope helpers, error normalization
├── api/
│   ├── ai_chat.php            # POST: AI requests (explain | full | edit modes)
│   ├── files.php              # POST: list / read / write / create / rename / delete (in working_folder)
│   ├── preview.php            # Serves server-side PHP execution of a working_folder file
│   ├── upload.php             # POST: media upload + thumbnail generation
│   ├── media.php              # POST/GET: media browse / thumbnail serve
│   ├── history.php            # POST/GET: conversation history read/append/clear
│   └── backup.php             # POST: create / list / restore / delete backups
├── ai_tools/                  # DeepSeek-powered tools surfaced in the UI (e.g. explain, refactor, doc-gen)
├── tools/                     # Non-AI utilities (lint, structure outline, find-usages)
├── assets/                    # ALL vendored, no CDN
│   ├── vendor/ace/            # Ace editor (BSD-3) prebuilt single-file assets
│   ├── vendor/jquery/         # jQuery (vendored locally)
│   ├── css/app.css
│   └── js/app.js
└── working_folder/            # The user's PHP project under edit (sandbox boundary)
    ├── g023_history.json      # Conversation history (app-managed)
    └── g023_backups/          # ZIP backups (app-managed)
```

**Boundary rule:** Every file read/write/upload/preview must pass through `safe_resolve()` (lib/paths.php) confining the path to `working_folder/`. No endpoint touches the filesystem directly. App-managed state (`g023_history.json`, `g023_backups/`) lives inside `working_folder/` but is filtered out of the user-facing file picker.

---

## 6. DeepSeek V4 Integration

### 6.1 Verified API facts (researched 2026-06-23)

- **Endpoint:** `https://api.deepseek.com/chat/completions` — OpenAI-compatible Chat Completions. Bearer auth (`Authorization: Bearer <key>`); **trim the key** before sending.
- **Models (confirmed live):**
  - `deepseek-v4-flash` — default. ~$0.14/1M input (cache miss), ~$0.28/1M output. 1M-token context.
  - `deepseek-v4-pro` — opt-in only. ~$0.435/1M input (cache miss), ~$0.87/1M output. More capable for hard reasoning/coding/agentic tasks.
  - Legacy `deepseek-chat` (non-thinking) and `deepseek-reasoner` (thinking) map to flash and **will be deprecated 2026-07-24** — do not target them.
- **Thinking mode:** enabled via request body `"thinking": {"type": "enabled"}` (separate from model selection). Optional `"reasoning_effort": "high"` controls intensity. When enabled, the response message carries `reasoning_content` in addition to `content`.
- **Response shape:** `choices[0].message.content` (answer), `choices[0].message.reasoning_content` (only when thinking enabled), `choices[0].message.tool_calls` (when tools used), plus top-level `id`, `model`, `usage`, and `choices[0].finish_reason`.
- Supports `temperature`, `top_p`, `max_tokens`, `response_format` (`{"type":"json_object"}`), and tool calling (verify exact tool semantics against live API before relying on tools in v1).

### 6.2 Model usage policy (project constraint — must hold)

> `deepseek-v4-flash` is the **default everywhere** — all editing, testing, and AI-assist (explain, full, edit, analysis). `deepseek-v4-pro` is **opt-in only**: a clearly-indicated, non-default UI selector option, used only when the user explicitly chooses it. During development, exercise pro with **a single smoke-test request** to confirm integration; never use pro as the working dev/test model. Rationale: flash is ~3× cheaper. All automated/manual acceptance flows run on flash; pro is verified only for reachability.

### 6.3 Canonical connector — `lib/ds4.php`

Extract the prototype's inline `llm_get()` into a dedicated, fully-featured connector. Requirements:

- Signature preserves the prototype: `(messages, model, temperature, max_tokens, top_p, thinking, tools, tool_choice, response_format, user_id, extra_body)`.
- **Reads the key from `__DIR__` -resolved `K.dat`** (e.g. repo-root `K.dat`), **not** `../../K.dat`. Trim before use. Throw if missing/empty.
- Returns `['reasoning' => ..., 'response' => ..., 'meta' => [...]]` where `meta` carries `id`, `model`, `finish_reason`, `usage`, and `tool_calls` when present.
- cURL with explicit timeout (prototype uses 300s; consider 120s default + configurable). Surface HTTP/cURL errors as exceptions with sanitized messages.
- **Never** log, echo, or return the API key. Never reflect it in error payloads.
- Default model constant `DEFAULT_MODEL = 'deepseek-v4-flash'`.

### 6.4 AI request modes (UI → `api/ai_chat.php`, `POST action=ai_chat`)

| Mode | System prompt behavior | Temp | Client handling |
|---|---|---|---|
| **explain** | Plain-text answer about the code; no code block required. | ~0.5 | Render as message. |
| **full** | Return the **entire updated file** in one markdown code block, no commentary. | ~0.5 | Extract first code block, "Apply full code" button replaces editor content. |
| **edit** | Return **only** a JSON array of `{"search","replace"}` objects (literal, non-regex), applied sequentially. No prose/markdown. | ~0.2 | Parse JSON, "Apply edits" button does sequential `String.split().join()` replacements; report applied/total and warn on not-found search strings. |

Request payload (existing prototype contract, to preserve): `action`, `code`, `prompt`, `mode`, `enable_thinking`, `history` (JSON), plus new: `model`, `csrf_token`, `file` (active file path). History sends prior turns excluding the just-added user message. Consider `response_format: {"type":"json_object"}` for edit mode to harden structured output (verify it doesn't suppress the array form first).

---

## 7. Feature Specifications

### 7.1 File picker & file I/O (`api/files.php`)
- Actions: `list` (tree within `working_folder/`, excluding `g023_history.json` and `g023_backups/`), `read`, `write`, `create`, `rename`, `delete`, `mkdir`.
- Every path argument resolved via `safe_resolve()`. Reject anything escaping the sandbox or containing null bytes.
- Writes should be atomic (write temp + `rename`) to avoid half-written files. Optionally auto-backup-on-save (configurable).
- Return file metadata (size, mtime, MIME) for UI.

### 7.2 Editor (front-end)
- **Library: Ace (`ace-builds`, BSD-3-Clause).** Chosen because it is the only major editor shipping prebuilt single-file browser assets with **no build step / no bundler** — matching the "vendored, no-CDN, drop-in" constraint. Use `src-noconflict` build (`ace.require`, avoids clobbering jQuery globals). Preserve the BSD-3 license notice in the vendored copy.
  - Vendor: `ace.js`, `mode-php_laravel_blade.js` (mixed HTML/PHP), `mode-html/javascript/css`, a theme (e.g. `theme-monokai`), `worker-php.js`, relevant `ext-*`. Worker optional (`setUseWorker(false)` works fully) — keep workers same-origin to satisfy CSP.
  - Required: syntax highlighting, line numbers, language auto-select by extension.
  - (CodeMirror 6 rejected: ES-module-only, requires a bundler — violates no-build. Monaco rejected: ~15 MB, requires worker-URL config.)
- Panes: editor (left), collapsible preview (right), AI chat (right sidebar). Toolbar: Preview toggle, Save, Backup, model selector, edit-mode toggle, thinking toggle, reset chat.

### 7.3 Server-side PHP preview (`api/preview.php`) — **the headline feature**
- Replaces the prototype's iframe `srcdoc` approach (which only renders static HTML).
- Mechanism: the editor's content is the file already saved under `working_folder/`; preview loads that file **through the web server so PHP executes**, displayed in an iframe pointed at the preview URL (not `srcdoc`).
- The preview endpoint resolves the requested file via `safe_resolve()` and includes/serves it within the restricted execution context (see §9).
- Capture and surface **PHP errors/warnings/fatals** in the preview (so `test.php`'s `php_info()` typo shows a clear error, not a blank page). Run preview with error display on but in an isolated context; never leak server paths to a real end user (operator-only tool).
- Enforce execution timeouts (`max_execution_time` + FPM `request_terminate_timeout`) and memory caps.

### 7.4 Media upload & browser (`api/upload.php`, `api/media.php`)
- Accept images / PDF / audio / etc. Validate **server-side** via `finfo(FILEINFO_MIME_TYPE)` against a **MIME allowlist**; derive stored extension from the validated MIME (never the upload's filename). Random filenames (`bin2hex(random_bytes(16))`).
- **Images:** re-encode through GD on ingest (strips appended payloads/EXIF) and generate thumbnails (`imagecreatefrom*` → `imagecopyresampled` → `imagejpeg`).
- **No-execute storage:** the upload directory must not execute PHP (Apache `RemoveHandler`/`<FilesMatch> Require all denied`, `Options -ExecCGI`, ignore uploaded `.htaccess`). Prefer storing uploads in a dedicated `working_folder/uploads/` (or outside webroot with a serving proxy).
- **Media browser** lists thumbnails and inserts the correct asset reference (relative path / `<img>` / `<audio>` snippet) into the editor at the cursor.
- **PDF thumbnails:** GD cannot rasterize PDFs. v1 uses a generic PDF icon placeholder. Real PDF page thumbnails require **Imagick + Ghostscript** (not in the stated extension set) — tracked as an open item (§12).

### 7.5 Conversation history (`api/history.php`)
- Persist turns to `working_folder/g023_history.json` (append-safe; consider per-file or per-session keying). Browsable list in the UI; load a past conversation back into the chat; clear/reset. Cap size / rotate to avoid unbounded growth.

### 7.6 Backup / restore (`api/backup.php`)
- **Create:** recursive ZIP of `working_folder/` (excluding `g023_backups/` itself) via `ZipArchive` + `RecursiveDirectoryIterator`; timestamped name `backup_YYYY-MM-DD_His.zip`; build to `.tmp` then `rename` (atomic, no half-written backups). Store in `working_folder/g023_backups/`.
- **List:** enumerate backups with size/date.
- **Restore:** **Guard against Zip Slip** — validate every entry name before extraction (reject absolute paths, drive letters, and any `../` component via regex), extract entry-by-entry with `getStream()` + `stream_copy_to_stream()`, verify each resolved target stays inside `working_folder/`. Extract to a temp dir, validate, then swap. Never use raw `extractTo()` on untrusted archives.
- **Retention:** keep N most recent (configurable, default e.g. 20); prune oldest by lexical/chronological name sort.

### 7.7 Code analysis (`tools/`, `ai_tools/`)
- Non-AI (`tools/`): `php -l` style lint (or `php_check_syntax` via a sandboxed lint call), structure/outline (functions/classes), find-usages, basic metrics.
- AI (`ai_tools/`): "Explain this file", "Suggest refactors", "Generate docblocks", "Find bugs" — all routed through `ds4.php` on **flash** by default.

---

## 8. API / Endpoint Contract

All endpoints follow: `GET` serves the page/asset; `POST` with an `action` field returns a JSON envelope. Standard envelope:

```json
{ "success": true,  "data": { ... } }
{ "success": false, "error": "human-readable, sanitized message" }
```

Conventions:
- `Content-Type: application/json` on all JSON responses; `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)`.
- Allowlist the `action` value with `match`/`switch`; unknown action → `{"success":false}`.
- **Every state-changing POST requires a valid CSRF token** (`X-CSRF-Token` header or `csrf_token` field), verified with `hash_equals`.
- Generic errors only — never stack traces, server paths, or the API key.

| Endpoint | Actions | Notes |
|---|---|---|
| `api/ai_chat.php` | `ai_chat` | modes: explain/full/edit; params per §6.4 |
| `api/files.php` | `list,read,write,create,rename,delete,mkdir` | all via `safe_resolve()` |
| `api/preview.php` | (GET file param) | executes PHP in restricted context |
| `api/upload.php` | `upload` | finfo + GD re-encode + thumbnail |
| `api/media.php` | `list,thumb` | thumbnail serving |
| `api/history.php` | `list,load,append,clear` | g023_history.json |
| `api/backup.php` | `create,list,restore,delete` | Zip-Slip-safe |

---

## 9. Security Model

> **Restate the framing: previewing user PHP is RCE by design.** Default posture = **single trusted operator, bound to 127.0.0.1**. The controls below are defense-in-depth, not a boundary.

### 9.1 Network isolation (primary control)
- Bind the editor + preview to **localhost only** (`php -S 127.0.0.1:8000`, or Apache `Require ip 127.0.0.1` / `Listen 127.0.0.1`). Anyone who reaches the preview runs code as the PHP user.

### 9.2 Path confinement (every file op)
Canonical `safe_resolve($baseDir, $userPath)` helper, used by all file I/O:
- `realpath()` the base; reject null bytes; decode encodings **before** validating.
- For existing targets: accept only if `$real === $base` or `str_starts_with($real, $base . DIRECTORY_SEPARATOR)`. **Do not** use the buggy `strpos($real, $base) === 0` (prefix-collision: `/var/www` vs `/var/www-evil`).
- For not-yet-existing write targets: canonicalize `dirname()`, re-append `basename()`, verify the parent is inside base.
- `realpath()` resolves symlinks, so in-folder symlinks pointing outside are rejected. Optionally also reject `is_link()` components for strict mode.

### 9.3 Restricted PHP execution context for preview
A dedicated PHP-FPM pool (or equivalent) for the preview, with:
- Dedicated low-privilege OS user; `listen` on `127.0.0.1`, `listen.allowed_clients = 127.0.0.1`.
- `php_admin_value[open_basedir] = .../working_folder/:/tmp/` (trailing slash; `_admin_` form so scripts can't override).
- `php_admin_value[disable_functions]` including `exec,passthru,shell_exec,system,proc_open,popen,dl` **and** `putenv,mail,error_log` (LD_PRELOAD bypass chain).
- `php_admin_flag[ffi.enable] = off` (FFI calls libc `system()` directly).
- `request_terminate_timeout = 15s` (hard wall-clock kill — catches `sleep`/I/O that `max_execution_time` misses), `max_execution_time`, `memory_limit` cap.
- `security.limit_extensions = .php`; `allow_url_fopen=0`, `allow_url_include=0`.
- **Documented limitation:** `eval`/`include`/`require` are language constructs and **cannot** be disabled; an editor that runs arbitrary PHP can always reach them. These directives are speed bumps, not boundaries.

### 9.4 Upload safety
finfo MIME allowlist + GD re-encode + no-execute storage dir + random filenames (§7.4). Each control alone has a documented bypass; layer all of them.

### 9.5 App-layer hardening
- **CSRF:** synchronizer token in `$_SESSION` (`random_bytes(32)`), surfaced via `<meta name="csrf-token">`, sent as `X-CSRF-Token`, verified with `hash_equals`. `SameSite=Strict` cookie as defense-in-depth.
- **Session:** set cookie params before `session_start()` (`httponly`, `samesite=Strict`, `secure` **conditional on HTTPS** — `secure` breaks plain-http localhost), `use_strict_mode=On`, `use_only_cookies=On`, `session_regenerate_id(true)` on privilege change, idle timeout via `last_activity`.
- **Optional auth:** app-level `password_hash()`/`password_verify()` (PHP 8.4 bcrypt default cost 12; check `password_algos()` before relying on Argon2id) or HTTP Basic. Auth is defense-in-depth on top of localhost binding.
- **Response headers:** `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` (preview iframe is same-origin), `Referrer-Policy: strict-origin-when-cross-origin`, and a strict **`Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'`**. The no-CDN/vendor-everything rule makes a strict `'self'` CSP uniquely achievable — a real security win. Roll out CSP in `-Report-Only` first.
- **Secret handling:** key stays server-side in `ds4.php`; browser never receives it and never calls DeepSeek directly; never logged or reflected.

### 9.6 Untrusted-exposure requirement (out of scope for v1, documented)
If ever exposed beyond a trusted localhost user, the preview **must** move to per-preview **Docker container or microVM (gVisor / Firecracker / Kata)** isolation (non-root, dropped capabilities, read-only rootfs, seccomp, resource quotas). In-PHP directives are insufficient. `runkit`/Suhosin are not viable sandboxes.

---

## 10. Non-Functional Requirements

- **Performance:** edit→preview round trip < 1s for typical files (excluding the user's app runtime). AI latency dominated by DeepSeek; show progress/streaming-style affordance.
- **Reliability:** atomic writes and backups; restore validated before swap; history capped/rotated.
- **Portability:** runs under Apache userdir and `php -S` with no build step (vendored assets, no bundler).
- **Maintainability:** modular files, shared libs, no duplicated DeepSeek logic.
- **Dependencies (lean — rely on stated extensions only):** `curl` (DeepSeek), `gd` (thumbnails), `zip` (backups), `fileinfo` (upload MIME), `mbstring`. No libraries that duplicate these. **No CDN assets** — everything vendored.
- **Accessibility/UX:** keyboard-friendly editor, clear error surfacing, dark theme baseline (per prototype).
- **Browser support:** modern desktop Chromium/Firefox.

---

## 11. Milestones / Roadmap

| Phase | Deliverable | Exit criteria |
|---|---|---|
| **M0 — Foundation** | `config.php`, `lib/ds4.php` (key path fixed), `lib/paths.php` (`safe_resolve`), `lib/security.php`, JSON envelope. | Connector smoke-tests flash + one pro request; `safe_resolve` unit-tested against traversal vectors. |
| **M1 — Editor + Files** | `index.php` shell, vendored Ace + jQuery, `api/files.php`. | Open/edit/save a file in `working_folder/` with highlighting + line numbers; no CDN requests in network panel. |
| **M2 — Server-side Preview** | `api/preview.php` + restricted exec context. | `test.php` previews; PHP executes; the `php_info()` typo surfaces as a visible error; timeout/memory caps enforced. |
| **M3 — AI Assistant** | `api/ai_chat.php` (explain/full/edit) + thinking + model selector. | All three modes work on flash; apply-full and apply-edits update the editor; pro selectable. |
| **M4 — Media** | `api/upload.php`, `api/media.php`, media browser. | Upload image → MIME-validated, re-encoded, thumbnailed, insertable; upload dir cannot execute PHP. |
| **M5 — History + Backup** | `api/history.php`, `api/backup.php`. | Browse/restore history; create/restore backup; Zip-Slip restore attempt rejected. |
| **M6 — Analysis + Hardening** | `tools/`, `ai_tools/`, CSP/CSRF/session, docs. | Security checklist (§9) passes; lint/analysis tools usable; README + SECURITY.md shipped. |

---

## 12. Open Questions / Decisions Needed

1. **Preview execution mechanism:** dedicated FPM pool vs. a sub-`php -S` instance on a separate localhost port vs. inline include with `open_basedir`? (Recommend dedicated FPM pool or separate port for isolation; confirm Apache userdir capabilities on the target host.)
2. **PDF thumbnails:** accept generic-icon placeholder for v1, or add **Imagick + Ghostscript** to the stack? (Stated extensions are `gd`-only.)
3. **Authentication:** is localhost binding sufficient, or is app-level login required for v1?
4. **History scope:** global vs. per-file vs. per-session conversation history keying, and retention size.
5. **Edit-mode robustness:** rely on prompt discipline, or enforce `response_format: json_object`? (Verify it still yields the bare array the client expects.)
6. **Auto-backup-on-save:** default on or opt-in? Retention count (proposed 20).
7. **Multi-file context for AI:** v1 sends only the active file; should the assistant see sibling files / project tree? (Token-budget vs. capability trade-off; flash's 1M context makes this feasible later.)
8. **Tool calling:** defer DeepSeek tool/function-calling to a later version, or include in v1? (Verify live semantics first.)
9. **Streaming:** non-streaming only (per prototype) for v1, or add SSE streaming for responsiveness?

---

## 13. Acceptance Criteria (v1.0 Definition of Done)

- [ ] App is modular per §5; no monolith; no duplicated DeepSeek logic.
- [ ] Zero CDN requests (verified in browser network panel); Ace + jQuery vendored.
- [ ] Editor has PHP/HTML/JS/CSS syntax highlighting + line numbers.
- [ ] All file I/O confined to `working_folder/` via `safe_resolve`; traversal vectors rejected (tested: `../`, `....//`, `..\`, encoded, absolute, null byte, symlink).
- [ ] **Preview executes PHP server-side** and renders output; PHP errors are visible; execution timeout + memory cap enforced.
- [ ] AI: explain/full/edit all functional on `deepseek-v4-flash`; thinking mode surfaces `reasoning_content`; `deepseek-v4-pro` selectable (verified with one smoke-test) and **never the default**.
- [ ] API key read from repo-root `K.dat` via `__DIR__`; never sent to browser, logged, or reflected in errors.
- [ ] Uploads: finfo MIME allowlist + GD re-encode + thumbnail + random name; upload dir cannot execute PHP.
- [ ] Conversation history persisted to `g023_history.json` and browsable.
- [ ] Backup creates a valid ZIP; restore is Zip-Slip-safe (malicious entry rejected); retention prunes oldest.
- [ ] CSRF on all state-changing POSTs; session hardened; strict `'self'` CSP + security headers present.
- [ ] App runnable both via Apache userdir and `php -S 127.0.0.1:8000` with no build step.
- [ ] `README.md` + `SECURITY.md` document the RCE-by-design posture and localhost-only requirement.

---

## 14. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Preview = arbitrary code execution | Critical | Localhost binding (primary) + restricted FPM pool + open_basedir + disable_functions + FFI off + timeouts; document loudly. |
| Path traversal escaping sandbox | High | Single `safe_resolve` gate, correct prefix check, tested against OWASP vectors. |
| Malicious upload (polyglot/webshell) | High | finfo allowlist + GD re-encode + no-execute storage + random names. |
| Zip Slip on restore | High | Per-entry validation, stream extraction, temp-dir-then-swap. |
| API key leakage | High | Server-side only in `ds4.php`; never in HTML/JS/logs/errors; strict CSP. |
| Runaway DeepSeek cost | Medium | Flash default everywhere; pro opt-in with one smoke-test; surface token usage in UI. |
| Legacy model deprecation (2026-07-24) | Low | Target only `deepseek-v4-flash`/`-pro`; do not use `deepseek-chat`/`-reasoner`. |
| Plain-http localhost vs. Secure cookies | Low | Make `Secure` conditional on HTTPS, or mandate HTTPS locally. |

---

## 15. Appendix — Key References

- DeepSeek API docs — models, pricing, reasoning/thinking mode: https://api-docs.deepseek.com/
- OWASP Path Traversal, File Upload, CSRF, Session Management, Secure Headers cheat sheets.
- PHP manual: `realpath`, `finfo`, ZipArchive, `password_hash`, FPM configuration, `session_set_cookie_params`.
- Ace editor (BSD-3): https://github.com/ajaxorg/ace-builds
- Zip Slip advisories (CVE-2008-5658, CVE-2021-21706).

---

*Document complete. Build toward this PRD; treat `page_editor.php` as a UI/AI reference only, not the architecture.*
