<?php
/**
 * index.php — Front-end shell for DS PHP Edit.
 *
 * Serves the editor UI. Bootstraps the session, emits security headers + a
 * strict 'self' CSP (achievable because every asset is vendored — no CDN),
 * and exposes the CSRF token via <meta>. No business logic lives here.
 *
 * @author  g023 (https://github.com/g023/)
 * @license MIT
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/ds4.php';

dspe_bootstrap_state();
security_session_start();
security_headers();

$csrf       = csrf_token();
$hasKey     = ds4_has_key();
$defaultMdl = DEFAULT_MODEL;
$proModel   = PRO_MODEL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <meta name="has-key" content="<?= $hasKey ? '1' : '0' ?>">
    <meta name="default-model" content="<?= htmlspecialchars($defaultMdl, ENT_QUOTES) ?>">
    <meta name="preferred-model" content="<?= htmlspecialchars(PREFERRED_MODEL, ENT_QUOTES) ?>">
    <meta name="preview-supported" content="<?= WORK_DIR_IN_APPROOT ? '1' : '0' ?>">
    <title>DS PHP Edit — DeepSeek V4 Web IDE</title>
    <link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#0e639c"/><text x="16" y="22" font-size="16" text-anchor="middle" fill="#fff" font-family="monospace">DS</text></svg>') ?>">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div id="app">

    <!-- ===== Top bar ===== -->
    <header id="topbar">
        <div class="brand">
            <span class="brand-mark">DS</span>
            <span class="brand-name">PHP&nbsp;Edit</span>
            <span class="brand-sub">DeepSeek V4</span>
        </div>
        <div class="filepath" id="active-file-label" title="No file open">No file open</div>
        <div class="topbar-actions">
            <span id="dirty-dot" class="dirty-dot" title="Unsaved changes" hidden></span>
            <button id="btn-save" class="btn" disabled title="Save (Ctrl+S)">💾 Save</button>
            <button id="btn-preview" class="btn" disabled title="Open server-side preview of the active file">▶ Preview</button>
            <button id="btn-chat" class="btn btn-toggle active" title="Toggle AI panel">✦ AI</button>
            <a id="btn-github" class="btn" href="https://github.com/g023/dsphpedit" target="_blank"
               rel="noopener noreferrer" title="Open the project on GitHub (new window)">⧉ GitHub</a>
        </div>
    </header>

    <div id="workbench">

        <!-- ===== Activity rail ===== -->
        <nav id="activity-rail" aria-label="Panels">
            <button class="rail-btn active" data-panel="files"   title="Files">🗂</button>
            <button class="rail-btn"        data-panel="media"   title="Media">🖼</button>
            <button class="rail-btn"        data-panel="history" title="Conversation history">💬</button>
            <button class="rail-btn"        data-panel="backups" title="Backups">📦</button>
            <button class="rail-btn"        data-panel="tools"   title="Analysis tools">🛠</button>
            <button class="rail-btn"        data-panel="peek"    title="Project map (PEEK)">🗺</button>
            <span class="rail-spacer"></span>
            <button class="rail-btn"        data-panel="settings" title="Settings">⚙</button>
            <button class="rail-btn" data-panel="about" title="About / help">ⓘ</button>
        </nav>

        <!-- ===== Side panel ===== -->
        <aside id="side-panel">
            <!-- Files -->
            <section class="panel active" data-panel="files">
                <div class="panel-head">
                    <span>Explorer</span>
                    <div class="panel-tools">
                        <button id="btn-new-file"   class="icon-btn" title="New file">＋</button>
                        <button id="btn-new-folder" class="icon-btn" title="New folder">📁</button>
                        <button id="btn-upload"     class="icon-btn" title="Upload media">⬆</button>
                        <button id="btn-refresh"    class="icon-btn" title="Refresh">⟳</button>
                    </div>
                </div>
                <div class="panel-sub">working_folder/</div>
                <div id="file-tree" class="tree" tabindex="0"></div>
                <input type="file" id="upload-input" hidden multiple>
            </section>

            <!-- Media -->
            <section class="panel" data-panel="media">
                <div class="panel-head">
                    <span>Media Library</span>
                    <div class="panel-tools">
                        <button id="btn-upload2" class="icon-btn" title="Upload">⬆</button>
                        <button id="btn-media-refresh" class="icon-btn" title="Refresh">⟳</button>
                    </div>
                </div>
                <div class="panel-sub">Click to insert at cursor</div>
                <div id="media-grid" class="media-grid"></div>
            </section>

            <!-- History -->
            <section class="panel" data-panel="history">
                <div class="panel-head">
                    <span>Conversations</span>
                    <div class="panel-tools">
                        <button id="btn-history-refresh" class="icon-btn" title="Refresh">⟳</button>
                        <button id="btn-history-clear" class="icon-btn" title="Clear all">🗑</button>
                    </div>
                </div>
                <div id="history-list" class="list"></div>
            </section>

            <!-- Backups -->
            <section class="panel" data-panel="backups">
                <div class="panel-head">
                    <span>Backups</span>
                    <div class="panel-tools">
                        <button id="btn-backup-create" class="icon-btn" title="Create backup now">＋</button>
                        <button id="btn-backup-refresh" class="icon-btn" title="Refresh">⟳</button>
                    </div>
                </div>
                <div class="panel-sub">ZIP snapshots of working_folder/</div>
                <div id="backup-list" class="list"></div>
            </section>

            <!-- Tools -->
            <section class="panel" data-panel="tools">
                <div class="panel-head"><span>Analysis</span></div>
                <div class="panel-sub">Non-AI tools for the open file</div>
                <div class="tool-buttons">
                    <button class="tool-btn" data-tool="lint">⚙ Lint (syntax check)</button>
                    <button class="tool-btn" data-tool="outline">≣ Structure outline</button>
                    <button class="tool-btn" data-tool="metrics">📊 Metrics</button>
                </div>
                <div class="panel-head" style="margin-top:14px"><span>AI Assist</span></div>
                <div class="panel-sub">Routed through DeepSeek (flash)</div>
                <div class="tool-buttons">
                    <button class="tool-btn ai" data-aitool="explain">🔍 Explain this file</button>
                    <button class="tool-btn ai" data-aitool="refactor">♻ Suggest refactors</button>
                    <button class="tool-btn ai" data-aitool="docblocks">📝 Generate docblocks</button>
                    <button class="tool-btn ai" data-aitool="bugs">🐞 Find bugs</button>
                </div>
                <div id="tool-output" class="tool-output"></div>
            </section>

            <!-- Project map (PEEK) -->
            <section class="panel" data-panel="peek">
                <div class="panel-head">
                    <span>Project Map · PEEK</span>
                    <div class="panel-tools">
                        <button id="btn-peek-rebuild" class="icon-btn" title="Rebuild map">⟳</button>
                    </div>
                </div>
                <div class="panel-sub">Compact structural map the AI uses to understand your code</div>
                <pre id="peek-output" class="peek-output">Loading…</pre>
            </section>

            <!-- Settings -->
            <section class="panel" data-panel="settings">
                <div class="panel-head">
                    <span>Settings</span>
                    <div class="panel-tools">
                        <button id="btn-settings-reset" class="icon-btn" title="Reset all to defaults">↺</button>
                    </div>
                </div>
                <div class="panel-sub">Stored in <code>dspe_settings.json</code> (never web-served)</div>

                <form id="settings-form" class="settings-form" autocomplete="off">
                    <fieldset>
                        <legend>Paths</legend>
                        <label class="set-field">
                            <span>Working folder</span>
                            <input type="text" id="set-working_folder" placeholder="(default) working_folder/">
                            <small class="set-hint" id="hint-working_folder">Relative to the app, or an absolute path. Blank = bundled folder.</small>
                        </label>
                        <label class="set-field">
                            <span>API key path</span>
                            <input type="text" id="set-key_path" placeholder="(default) K.dat">
                            <small class="set-hint" id="hint-key_path">Path to the DeepSeek key file. Blank = bundled K.dat.</small>
                        </label>
                        <div class="set-note" id="paths-reload-note" hidden>⚠ Changing a path takes effect after reload.</div>
                    </fieldset>

                    <fieldset>
                        <legend>AI / DeepSeek</legend>
                        <label class="set-field">
                            <span>Default model</span>
                            <select id="set-default_model">
                                <option value="<?= htmlspecialchars($defaultMdl) ?>"><?= htmlspecialchars($defaultMdl) ?> · default</option>
                                <option value="<?= htmlspecialchars($proModel) ?>"><?= htmlspecialchars($proModel) ?> · opt-in</option>
                            </select>
                        </label>
                        <label class="set-field">
                            <span>Reasoning effort (thinking)</span>
                            <select id="set-reasoning_effort">
                                <option value="low">low</option>
                                <option value="medium">medium</option>
                                <option value="high">high</option>
                            </select>
                        </label>
                        <label class="set-check"><input type="checkbox" id="set-thinking_default"> Enable thinking by default</label>
                        <label class="set-field">
                            <span>DeepSeek timeout (s)</span>
                            <input type="number" id="set-deepseek_timeout" min="10" max="600">
                        </label>
                    </fieldset>

                    <fieldset>
                        <legend>Agentic reading &amp; PEEK</legend>
                        <label class="set-check"><input type="checkbox" id="set-agentic_reading"> Auto-read related files for the AI</label>
                        <label class="set-check"><input type="checkbox" id="set-peek_enabled"> Include the project map (PEEK) in prompts</label>
                        <label class="set-field">
                            <span>Max related files</span>
                            <input type="number" id="set-agentic_max_files" min="0" max="40">
                        </label>
                        <label class="set-field">
                            <span>Max context bytes</span>
                            <input type="number" id="set-agentic_max_bytes" min="0" max="400000" step="1000">
                        </label>
                        <label class="set-field">
                            <span>Max follow depth</span>
                            <input type="number" id="set-agentic_max_depth" min="1" max="6">
                        </label>
                    </fieldset>

                    <fieldset>
                        <legend>Editor</legend>
                        <label class="set-field">
                            <span>Font size</span>
                            <input type="number" id="set-editor_font_size" min="9" max="28">
                        </label>
                        <label class="set-field">
                            <span>Tab size</span>
                            <input type="number" id="set-editor_tab_size" min="2" max="8">
                        </label>
                        <label class="set-check"><input type="checkbox" id="set-editor_word_wrap"> Word wrap</label>
                        <label class="set-check"><input type="checkbox" id="set-auto_backup_on_save"> Auto-backup on save</label>
                    </fieldset>

                    <div class="settings-actions">
                        <button type="submit" id="btn-settings-save" class="btn btn-primary">Save settings</button>
                        <span id="settings-status" class="set-status"></span>
                    </div>
                </form>
            </section>

            <!-- About -->
            <section class="panel" data-panel="about">
                <div class="panel-head"><span>About</span></div>
                <div class="about-body">
                    <p><strong>DS PHP Edit</strong> — a DeepSeek V4-powered web IDE for editing and
                    live-previewing PHP applications.</p>
                    <p class="warn">⚠ <strong>Preview executes PHP server-side</strong> — this is
                    Remote Code Execution by design. Run bound to <code>127.0.0.1</code> only.</p>
                    <p>Drop a PHP project into <code>working_folder/</code>, edit, ask the AI, and
                    click Preview to run it.</p>
                    <p><strong>🗂 Tabs</strong> — open several files at once; click a tab to switch,
                    ✕ (or middle-click) to close. <strong>▶ Preview</strong> opens a pinned preview of
                    the active file; it only refreshes when you press <strong>⟳</strong> in the preview
                    header. Your open files, expanded folders, and panel choices are restored on reload.</p>
                    <ul>
                        <li>Author: <strong>g023</strong></li>
                        <li>License: MIT</li>
                        <li>Editor: Ace (BSD-3, vendored)</li>
                        <li>No CDN — every asset is local.</li>
                    </ul>
                    <p><strong>✨ AI code completion</strong> — toggle it in the editor
                    toolbar (top-right). Pick <em>Manual</em> (suggest on <kbd>Alt</kbd>+<kbd>\</kbd>)
                    or <em>Auto</em> (suggest as you pause typing). When a suggestion
                    appears, click <strong>✓ Accept</strong> in the toolbar (or
                    <strong>✓ word</strong> / <kbd>Alt</kbd>+<kbd>]</kbd> for one word);
                    <kbd>Esc</kbd> dismisses. <kbd>Tab</kbd> keeps its normal
                    indent behavior. <kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>A</kbd> toggles it on/off.</p>
                    <p class="muted">See <code>README.md</code> &amp; <code>SECURITY.md</code>.</p>
                </div>
            </section>
        </aside>

        <!-- ===== Editor + preview ===== -->
        <main id="editor-area">
            <div id="editor-toolbar">
                <div class="toolbar-left">
                    <span id="lang-badge" class="badge">—</span>
                    <span id="editor-status" class="muted">Open a file to start editing</span>
                </div>
                <div class="toolbar-right">
                    <div class="cc-control" id="cc-control" title="AI code completion">
                        <button id="cc-toggle" class="cc-btn" type="button"
                                title="Toggle AI code completion (Alt+Shift+A)">
                            <span class="cc-spark">✨</span>
                            <span class="cc-label">Complete</span>
                            <span id="cc-state" class="cc-state">Off</span>
                        </button>
                        <div class="cc-modes" id="cc-modes" role="group" aria-label="Completion mode" hidden>
                            <button class="cc-mode" type="button" data-ccmode="manual"
                                    title="Suggest only when you ask (Alt+\)">Manual</button>
                            <button class="cc-mode" type="button" data-ccmode="auto"
                                    title="Suggest automatically as you pause typing">Auto</button>
                        </div>
                        <div class="cc-accept-group" id="cc-accept-group" role="group" aria-label="Accept suggestion" hidden>
                            <button class="cc-btn cc-accept" id="cc-accept" type="button"
                                    title="Accept the suggestion">✓ Accept</button>
                            <button class="cc-mode" id="cc-accept-word" type="button"
                                    title="Accept one word (Alt+])">✓ word</button>
                        </div>
                        <span id="cc-status" class="cc-status" hidden></span>
                    </div>
                    <label class="mini">Ln <span id="cursor-pos">1:1</span></label>
                </div>
            </div>
            <div id="editor-tabs" class="editor-tabs" hidden></div>
            <div id="editor-split">
                <div id="ace-editor"></div>
                <div id="preview-wrap" hidden>
                    <div class="preview-head">
                        <span>Preview · <span id="preview-target" class="muted">—</span></span>
                        <div>
                            <button id="btn-preview-reload" class="icon-btn" title="Reload preview">⟳</button>
                            <button id="btn-preview-open" class="icon-btn" title="Open in new tab">↗</button>
                            <button id="btn-preview-close" class="icon-btn" title="Close preview">✕</button>
                        </div>
                    </div>
                    <iframe id="preview-frame" title="PHP preview"></iframe>
                </div>
            </div>
        </main>

        <!-- ===== AI chat ===== -->
        <aside id="chat-panel">
            <div class="chat-head">
                <span>AI Assistant</span>
                <button id="btn-reset-chat" class="icon-btn" title="New conversation">🗑</button>
            </div>
            <div class="chat-controls">
                <select id="model-select" class="select" title="Model (flash is default & ~3× cheaper)">
                    <option value="<?= htmlspecialchars($defaultMdl) ?>"><?= htmlspecialchars($defaultMdl) ?> · default</option>
                    <option value="<?= htmlspecialchars($proModel) ?>"><?= htmlspecialchars($proModel) ?> · opt-in</option>
                </select>
                <div class="mode-group" role="tablist" aria-label="AI mode">
                    <button class="mode-btn active" data-mode="explain" title="Q&amp;A about the code">Explain</button>
                    <button class="mode-btn" data-mode="full" title="Return the whole updated file">Full</button>
                    <button class="mode-btn" data-mode="edit" title="Search/replace edits">Edit</button>
                </div>
                <label class="check"><input type="checkbox" id="thinking-toggle"> Thinking</label>
            </div>
            <div id="chat-messages" class="chat-messages"></div>
            <div id="usage-bar" class="usage-bar" hidden></div>
            <div class="chat-input-row">
                <textarea id="chat-input" rows="2" placeholder="Ask about the code, or request a change…" autocomplete="off"></textarea>
                <button id="btn-send" class="btn btn-primary" title="Send (Enter)">Send</button>
            </div>
            <?php if (!$hasKey): ?>
            <div class="key-warn">No <code>K.dat</code> key — AI is disabled. File editing &amp; preview still work.</div>
            <?php endif; ?>
        </aside>
    </div>

    <!-- ===== Toast / status ===== -->
    <div id="toast" class="toast" hidden></div>

    <!-- ===== Modal ===== -->
    <div id="modal-backdrop" class="modal-backdrop" hidden>
        <div class="modal" role="dialog" aria-modal="true">
            <div class="modal-title" id="modal-title">Title</div>
            <div class="modal-body" id="modal-body"></div>
            <div class="modal-actions">
                <button id="modal-cancel" class="btn">Cancel</button>
                <button id="modal-ok" class="btn btn-primary">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Vendored assets — NO CDN -->
<script src="assets/vendor/jquery/jquery-3.7.1.min.js"></script>
<script src="assets/vendor/ace/ace.js"></script>
<script src="assets/vendor/ace/ext-language_tools.js"></script>
<script src="assets/vendor/ace/ext-searchbox.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
