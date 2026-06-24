/* DS PHP Edit — app.js
 * Front-end controller. Vendored jQuery + Ace only (no CDN).
 * License: MIT
 */
(function ($) {
    "use strict";

    // ---- Globals & config -------------------------------------------------
    const CSRF = $('meta[name="csrf-token"]').attr('content') || '';
    const HAS_KEY = $('meta[name="has-key"]').attr('content') === '1';
    const DEFAULT_MODEL = $('meta[name="default-model"]').attr('content') || 'deepseek-v4-flash';

    let editor;
    let welcomeSession;           // read-only placeholder shown when no tabs are open

    // ---- Multi-file tabs --------------------------------------------------
    // Each open file is a tab holding its own Ace EditSession (content, undo
    // history, cursor). `activeFile` / `dirty` mirror the active tab so the
    // rest of the app can keep referring to them.
    let tabs = [];                // [{ path, session, dirty, size, mime }]
    let activeIdx = -1;           // index into tabs, or -1 for the welcome view
    let activeFile = null;        // relative path of the active tab (or null)
    let dirty = false;            // active tab's dirty flag (mirror)

    let aiMode = 'explain';
    let conversation = [];        // {role, content}
    let conversationId = '';      // history id
    let currentPanel = 'files';   // active side panel
    let expandedDirs = new Set(); // tree directory paths currently expanded
    let previewTarget = null;     // file the preview pane is pinned to

    // ---- AI code completion state ----------------------------------------
    const CC = {
        enabled: false,           // master on/off
        mode: 'manual',           // 'manual' | 'auto'
        ghost: null,              // { text, row, column } currently displayed
        reqId: 0,                 // monotonic; stale responses are discarded
        inflight: false,
        debounceTimer: null,
        suppress: false           // true while WE mutate the doc (show/accept)
    };
    const CC_DEBOUNCE = 600;      // ms idle before an auto suggestion fires
    const CC_MIN_PREFIX = 2;      // don't auto-trigger on near-empty buffers

    const EXT_MODE = {
        php: 'php_laravel_blade', phtml: 'php_laravel_blade', inc: 'php',
        html: 'html', htm: 'html', css: 'css', js: 'javascript', mjs: 'javascript',
        json: 'json', md: 'markdown', markdown: 'markdown', txt: 'text',
        xml: 'xml', sql: 'sql', sh: 'sh', yml: 'yaml', yaml: 'yaml'
    };
    const FILE_ICON = {
        php: '🐘', phtml: '🐘', html: '🌐', htm: '🌐', css: '🎨', js: '📜',
        json: '🔧', md: '📄', txt: '📄', png: '🖼', jpg: '🖼', jpeg: '🖼',
        gif: '🖼', webp: '🖼', svg: '🖼', pdf: '📕', mp3: '🎵', wav: '🎵',
        mp4: '🎬', zip: '📦'
    };

    // ---- Small helpers ----------------------------------------------------
    function post(url, data) {
        return $.ajax({
            url, type: 'POST', dataType: 'json',
            headers: { 'X-CSRF-Token': CSRF },
            data
        });
    }
    function api(file, data) { return post('api/' + file, data); }

    function toast(msg, kind) {
        const $t = $('#toast').removeClass('ok err').text(msg).prop('hidden', false);
        if (kind) $t.addClass(kind);
        clearTimeout(toast._t);
        toast._t = setTimeout(() => $t.prop('hidden', true), 2600);
    }
    function escapeHtml(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }
    function fmtBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }
    function fmtTime(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000);
        return d.toLocaleString();
    }
    function extOf(p) { return (p.split('.').pop() || '').toLowerCase(); }
    function baseName(p) { return (p || '').split('/').pop(); }

    // ---- Persisted UI state ----------------------------------------------
    // Open files, active file, expanded folders, panel + chat layout, and the
    // AI option selections are saved to localStorage and restored on reload.
    const STATE_KEY = 'dspe_state';
    let stateTimer = null;
    function saveState() {
        clearTimeout(stateTimer);
        stateTimer = setTimeout(() => {
            const st = {
                openFiles: tabs.map((t) => t.path),
                activeFile: activeFile,
                expandedDirs: Array.from(expandedDirs),
                panel: currentPanel,
                sidePanelCollapsed: $('#side-panel').hasClass('collapsed'),
                chatOpen: $('#btn-chat').hasClass('active'),
                model: $('#model-select').val(),
                aiMode: aiMode,
                thinking: $('#thinking-toggle').is(':checked')
            };
            try { localStorage.setItem(STATE_KEY, JSON.stringify(st)); } catch (e) { /* private mode */ }
        }, 200);
    }
    function loadState() {
        try { return JSON.parse(localStorage.getItem(STATE_KEY) || '{}') || {}; }
        catch (e) { return {}; }
    }

    // ---- Modal (promise-based) -------------------------------------------
    function modal(opts) {
        return new Promise((resolve) => {
            const $bd = $('#modal-backdrop');
            $('#modal-title').text(opts.title || '');
            const $body = $('#modal-body').empty();
            let $input = null;
            if (opts.message) $body.append($('<div>').text(opts.message));
            if (opts.prompt !== undefined) {
                $input = $('<input type="text">').val(opts.prompt || '');
                $body.append($input);
            }
            $('#modal-ok').text(opts.okText || 'OK');
            $bd.prop('hidden', false);
            if ($input) setTimeout(() => $input.focus().select(), 30);

            function done(val) {
                $bd.prop('hidden', true);
                $('#modal-ok').off('.m'); $('#modal-cancel').off('.m');
                $(document).off('keydown.m');
                resolve(val);
            }
            $('#modal-ok').on('click.m', () => done($input ? $input.val() : true));
            $('#modal-cancel').on('click.m', () => done(null));
            $(document).on('keydown.m', (e) => {
                if (e.key === 'Escape') done(null);
                if (e.key === 'Enter' && $input) done($input.val());
            });
        });
    }

    // ---- Ace editor -------------------------------------------------------
    function initEditor() {
        ace.config.set('basePath', 'assets/vendor/ace');
        editor = ace.edit('ace-editor');
        editor.setTheme('ace/theme/monokai');
        editor.setOptions({
            fontSize: '14px',
            showPrintMargin: false,
            useWorker: false,             // highlighting + gutter work without it (CSP-safe)
            enableBasicAutocompletion: true,
            enableLiveAutocompletion: false,
            tabSize: 4,
            useSoftTabs: true,
            wrap: false,
            newLineMode: 'unix'
        });

        // Welcome / empty-state session (read-only until a file is opened).
        welcomeSession = ace.createEditSession(
            "<?php\n// 👋 Welcome to DS PHP Edit\n" +
            "// 1. Pick or create a file in the Explorer (left).\n" +
            "// 2. Open as many files as you like — each gets its own tab.\n" +
            "// 3. Edit here with full syntax highlighting; ask the AI (right).\n" +
            "// 4. Click ▶ Preview to run the active file server-side.\n\n" +
            "echo 'Hello from PHP ' . PHP_VERSION;\n",
            'ace/mode/php_laravel_blade');
        editor.setSession(welcomeSession);
        editor.setReadOnly(true);

        editor.on('change', () => {
            if (activeFile) markDirty(true);
            if (CC.suppress) return;          // ignore our own ghost/accept edits
            ccClearGhost();                    // any real edit invalidates the suggestion
            if (CC.enabled && CC.mode === 'auto' && activeFile) {
                clearTimeout(CC.debounceTimer);
                CC.debounceTimer = setTimeout(() => ccRequest(true), CC_DEBOUNCE);
            }
        });

        // Cursor tracking lives on the session's Selection, which is replaced
        // whenever we switch tabs — rebind it on every session change.
        bindCursorTracking();
        editor.on('changeSession', bindCursorTracking);

        editor.commands.addCommand({
            name: 'save', bindKey: { win: 'Ctrl-S', mac: 'Cmd-S' },
            exec: () => saveFile()
        });
        ccInitEditorBindings();
    }

    function bindCursorTracking() {
        editor.selection.on('changeCursor', onCursorChange);
    }
    function onCursorChange() {
        const p = editor.getCursorPosition();
        $('#cursor-pos').text((p.row + 1) + ':' + (p.column + 1));
        // If the caret leaves the suggestion anchor (e.g. arrow keys), drop it.
        if (!CC.suppress && CC.ghost &&
            (p.row !== CC.ghost.row || p.column !== CC.ghost.column)) {
            ccClearGhost();
        }
    }

    function setMode(path) {
        const mode = EXT_MODE[extOf(path)] || 'text';
        editor.session.setMode('ace/mode/' + mode);
        $('#lang-badge').text(mode.replace('_laravel_blade', '').toUpperCase() || 'TXT');
    }

    // Update only the dirty indicators for the active tab (cheap; runs per key).
    function markDirty(d) {
        dirty = d;
        const t = activeTabObj();
        if (t) t.dirty = d;
        $('#dirty-dot').prop('hidden', !d || !activeFile);
        $('#btn-save').prop('disabled', !d || !activeFile);
        if (activeIdx >= 0) $('#editor-tabs .tab').eq(activeIdx).toggleClass('dirty', d);
    }

    // ---- Tabs -------------------------------------------------------------
    function activeTabObj() { return activeIdx >= 0 ? tabs[activeIdx] : null; }

    function renderTabs() {
        const $bar = $('#editor-tabs');
        if (!tabs.length) { $bar.empty().prop('hidden', true); return; }
        $bar.prop('hidden', false).empty();
        tabs.forEach((t, i) => {
            const name = baseName(t.path);
            const $tab = $('<div class="tab">')
                .attr('data-idx', i).attr('title', t.path)
                .toggleClass('active', i === activeIdx)
                .toggleClass('dirty', !!t.dirty);
            $tab.append($('<span class="tab-ic">').text(FILE_ICON[extOf(name)] || '📄'));
            $tab.append($('<span class="tab-nm">').text(name));
            $tab.append($('<span class="tab-dot" title="Unsaved changes">●</span>'));
            $('<button class="tab-close" title="Close (or middle-click)">✕</button>')
                .on('click', (e) => { e.stopPropagation(); closeTab(i); })
                .appendTo($tab);
            $tab.on('click', () => switchToTab(i));
            $tab.on('mousedown', (e) => { if (e.which === 2) { e.preventDefault(); closeTab(i); } });
            $bar.append($tab);
        });
    }

    function switchToTab(idx) {
        if (idx < 0 || idx >= tabs.length) return;
        ccClearGhost();
        clearTimeout(CC.debounceTimer);
        CC.reqId++;                          // invalidate any in-flight suggestion
        activeIdx = idx;
        const t = tabs[idx];
        activeFile = t.path;
        dirty = t.dirty;
        editor.setSession(t.session);
        editor.setReadOnly(false);
        setMode(t.path);
        updateFileLabel();
        $('#dirty-dot').prop('hidden', !t.dirty);
        $('#btn-save').prop('disabled', !t.dirty);
        $('#btn-preview').prop('disabled', false);
        $('#editor-status').text((t.size != null ? fmtBytes(t.size) + ' · ' : '') + (t.mime || ''));
        $('#file-tree .tree-row').removeClass('active');
        $('#file-tree .tree-row[data-path="' + cssEsc(t.path) + '"]').addClass('active');
        renderTabs();
        if (window.innerWidth <= 860) $('#side-panel').addClass('collapsed');
        editor.focus();
        saveState();
    }

    async function closeTab(idx) {
        if (idx < 0 || idx >= tabs.length) return;
        const t = tabs[idx];
        if (t.dirty) {
            const ok = await modal({
                title: 'Close file',
                message: '"' + t.path + '" has unsaved changes. Close without saving?',
                okText: 'Close anyway'
            });
            if (!ok) return;
        }
        tabs.splice(idx, 1);
        if (activeIdx === idx) {
            if (tabs.length === 0) showWelcome();
            else switchToTab(Math.min(idx, tabs.length - 1));
        } else {
            if (activeIdx > idx) activeIdx--;
            renderTabs();
        }
        saveState();
    }

    function showWelcome() {
        activeIdx = -1; activeFile = null; dirty = false;
        editor.setSession(welcomeSession);
        editor.setReadOnly(true);
        updateFileLabel();
        $('#btn-preview').prop('disabled', true);
        $('#btn-save').prop('disabled', true);
        $('#dirty-dot').prop('hidden', true);
        $('#lang-badge').text('—');
        $('#editor-status').text('Open a file to start editing');
        $('#file-tree .tree-row').removeClass('active');
        renderTabs();
    }

    // ---- AI code completion ----------------------------------------------
    // Copilot-style inline ghost-text completion powered by DeepSeek (flash).
    // Two methods: Manual (Alt+\) and Auto (debounced as you type). Accept via
    // the toolbar ✓ Accept button (Tab is left untouched); ✓ word accepts one
    // token (also Alt+]); Esc dismisses. Toggle Alt+Shift+A.

    function ccLoadSettings() {
        if (!HAS_KEY) { CC.enabled = false; CC.mode = 'manual'; return; }
        let en, md;
        try { en = localStorage.getItem('dspe_cc_enabled'); md = localStorage.getItem('dspe_cc_mode'); }
        catch (e) { en = null; md = null; }
        CC.enabled = (en === null) ? true : (en === '1');   // on by default when a key exists
        CC.mode = (md === 'auto') ? 'auto' : 'manual';      // manual by default (predictable)
    }
    function ccSaveSettings() {
        try {
            localStorage.setItem('dspe_cc_enabled', CC.enabled ? '1' : '0');
            localStorage.setItem('dspe_cc_mode', CC.mode);
        } catch (e) { /* private mode — settings simply won't persist */ }
    }
    function ccSyncUI() {
        $('#cc-state').text(CC.enabled ? 'On' : 'Off');
        $('#cc-toggle').toggleClass('active', CC.enabled);
        $('#cc-modes').prop('hidden', !CC.enabled);
        $('.cc-mode').removeClass('active');
        $('.cc-mode[data-ccmode="' + CC.mode + '"]').addClass('active');
        if (!HAS_KEY) {
            $('#cc-toggle').prop('disabled', true)
                .attr('title', 'AI completion unavailable — add a DeepSeek key to K.dat');
        }
    }
    function ccStatus(text, kind, persist) {
        const $s = $('#cc-status').removeClass('ok err');
        clearTimeout(ccStatus._t);
        if (!text) { $s.prop('hidden', true).text(''); return; }
        if (kind) $s.addClass(kind);
        $s.prop('hidden', false).text(text);
        if (!persist) ccStatus._t = setTimeout(() => $s.prop('hidden', true).text(''), 2000);
    }

    // Show/hide the toolbar Accept controls based on whether a suggestion is up.
    function ccUpdateAcceptUI() {
        $('#cc-accept-group').prop('hidden', !CC.ghost);
    }
    function ccClearGhost() {
        if (CC.ghost) {
            CC.suppress = true;
            try { editor.removeGhostText(); } catch (e) {}
            CC.suppress = false;
            CC.ghost = null;
        }
        ccUpdateAcceptUI();
    }
    function ccShowGhost(text) {
        ccClearGhost();
        if (!text) return;
        const pos = editor.getCursorPosition();
        CC.suppress = true;
        try { editor.setGhostText(text, pos); }
        catch (e) { CC.suppress = false; return; }
        CC.suppress = false;
        CC.ghost = { text: text, row: pos.row, column: pos.column };
        ccUpdateAcceptUI();
    }
    function ccAcceptGhost() {
        if (!CC.ghost) return false;
        const text = CC.ghost.text;
        ccClearGhost();
        CC.suppress = true;
        editor.session.insert(editor.getCursorPosition(), text);
        CC.suppress = false;
        markDirty(true);
        ccStatus('accepted', 'ok');
        editor.focus();
        return true;
    }
    function ccAcceptWord() {
        if (!CC.ghost) return false;
        const full = CC.ghost.text;
        // First chunk: leading whitespace+token, or a pure whitespace run, or to EOL.
        const m = full.match(/^([ \t]*\n|[ \t]*[^\s]+|\s+)/);
        let chunk = m ? m[0] : full;
        if (chunk.length >= full.length) return ccAcceptGhost();
        const rest = full.slice(chunk.length);
        ccClearGhost();
        CC.suppress = true;
        editor.session.insert(editor.getCursorPosition(), chunk);
        CC.suppress = false;
        markDirty(true);
        ccShowGhost(rest);          // keep showing the remainder
        ccStatus('accepted word · ✓ to accept rest');
        return true;
    }

    function ccCursorKey() {
        const p = editor.getCursorPosition();
        return p.row + ':' + p.column;
    }
    function ccPopupOpen() {
        return !!(editor.completer && editor.completer.activated);
    }
    function ccRequest(auto) {
        if (!CC.enabled || !HAS_KEY || !editor || !activeFile) return;
        if (!editor.selection.isEmpty()) return;     // never suggest over a selection
        if (ccPopupOpen()) return;                    // don't fight the word popup

        const session = editor.session;
        const pos = editor.getCursorPosition();
        const lastRow = session.getLength() - 1;
        const prefix = session.getTextRange({ start: { row: 0, column: 0 }, end: pos });
        const suffix = session.getTextRange({
            start: pos,
            end: { row: lastRow, column: session.getLine(lastRow).length }
        });

        if (auto && prefix.trim().length < CC_MIN_PREFIX) return;
        if (prefix.trim() === '' && suffix.trim() === '') return;

        const myId = ++CC.reqId;
        const triggerKey = ccCursorKey();
        CC.inflight = true;
        ccStatus('✨ thinking…', null, true);

        api('complete.php', {
            action: 'complete',
            prefix: prefix,
            suffix: suffix,
            file: activeFile || '',
            model: $('#model-select').val() || DEFAULT_MODEL,
            multiline: '1'
        }).then((r) => {
            CC.inflight = false;
            if (myId !== CC.reqId) return;                        // superseded
            if (!r.success) { ccStatus('⚠ ' + (r.error || 'error'), 'err'); return; }
            if (ccCursorKey() !== triggerKey || !editor.selection.isEmpty()) { ccStatus(''); return; }
            const text = (r.data && r.data.completion) || '';
            if (!text) { ccStatus('no suggestion'); return; }
            ccShowGhost(text);
            ccStatus('✓ Accept · Esc dismiss', 'ok', true);
        }).fail((xhr) => {
            CC.inflight = false;
            if (myId !== CC.reqId) return;
            let m = 'completion failed';
            try { m = JSON.parse(xhr.responseText).error || m; } catch (e) {}
            ccStatus('⚠ ' + m, 'err');
        });
    }

    function ccToggle(force) {
        const next = (force === undefined) ? !CC.enabled : !!force;
        if (next && !HAS_KEY) { toast('No K.dat key — AI completion unavailable', 'err'); return; }
        CC.enabled = next;
        if (!CC.enabled) { ccClearGhost(); ccStatus(''); clearTimeout(CC.debounceTimer); }
        ccSaveSettings(); ccSyncUI();
        toast('AI completion ' + (CC.enabled ? 'on · ' + CC.mode : 'off'), CC.enabled ? 'ok' : null);
    }
    function ccSetMode(mode) {
        CC.mode = (mode === 'auto') ? 'auto' : 'manual';
        if (CC.mode !== 'auto') clearTimeout(CC.debounceTimer);
        ccSaveSettings(); ccSyncUI();
        toast('Completion: ' + CC.mode, 'ok');
    }

    // Editor-level key + command wiring (called once from initEditor).
    function ccInitEditorBindings() {
        // Tab is intentionally NOT intercepted — it keeps its native indent /
        // snippet behavior at all times. Suggestions are accepted with the
        // toolbar Accept button (✓ / ✓ word). Esc dismisses a visible
        // suggestion (non-destructive, has no default editor action here).
        editor.keyBinding.addKeyboardHandler({
            handleKeyboard: function (data, hashId, keyString, keyCode) {
                if (!CC.ghost || ccPopupOpen()) return undefined;
                if (keyString === 'esc' || keyCode === 27) {
                    return { command: { name: 'ccDismiss', exec: function () { ccClearGhost(); ccStatus('dismissed'); return true; } } };
                }
                return undefined;
            }
        });
        editor.commands.addCommand({
            name: 'cc_trigger',
            bindKey: { win: 'Alt-\\', mac: 'Alt-\\' },
            exec: function () {
                if (!HAS_KEY) { toast('No K.dat key — AI completion unavailable', 'err'); return; }
                if (!activeFile) { toast('Open a file to use completion', 'err'); return; }
                if (!CC.enabled) ccToggle(true);   // friendly: the trigger key turns it on
                ccRequest(false);
            }
        });
        editor.commands.addCommand({
            name: 'cc_accept_word',
            bindKey: { win: 'Alt-]', mac: 'Alt-]' },
            exec: function () { if (CC.ghost) ccAcceptWord(); }
        });
        editor.commands.addCommand({
            name: 'cc_toggle',
            bindKey: { win: 'Alt-Shift-A', mac: 'Alt-Shift-A' },
            exec: function () { ccToggle(); }
        });
    }

    // ---- File tree --------------------------------------------------------
    function loadTree() {
        return api('files.php', { action: 'list' }).then((r) => {
            if (!r.success) { toast(r.error, 'err'); return; }
            renderTree(r.data.tree);
        });
    }
    function renderTree(nodes) {
        const $t = $('#file-tree').empty();
        if (!nodes || !nodes.length) {
            $t.append($('<div class="tree-empty">Empty. Create a file with ＋ or drop files into working_folder/.</div>'));
            return;
        }
        $t.append(buildNodes(nodes));
        // restore active highlight
        if (activeFile) $t.find('.tree-row[data-path="' + cssEsc(activeFile) + '"]').addClass('active');
    }
    function cssEsc(s) { return (s || '').replace(/["\\]/g, '\\$&'); }

    function buildNodes(nodes) {
        const $frag = $(document.createDocumentFragment());
        nodes.forEach((n) => {
            const $node = $('<div class="tree-node">');
            const $row = $('<div class="tree-row">').attr('data-path', n.path).attr('data-type', n.type);
            if (n.type === 'dir') {
                const isOpen = expandedDirs.has(n.path);
                const $tw = $('<span class="twisty">').text(isOpen ? '▾' : '▸');
                const $children = $('<div class="tree-children">').append(buildNodes(n.children || []));
                if (!isOpen) $children.hide();
                $row.append($tw, $('<span class="ic">').text(isOpen ? '📂' : '📁'), $('<span class="nm">').text(n.name));
                $row.on('click', (e) => {
                    e.stopPropagation();
                    const open = $children.is(':visible');
                    $children.toggle(!open);
                    $tw.text(open ? '▸' : '▾');
                    $row.find('.ic').first().text(open ? '📁' : '📂');
                    if (open) expandedDirs.delete(n.path); else expandedDirs.add(n.path);
                    saveState();
                });
                $row.on('contextmenu', (e) => { e.preventDefault(); ctxMenu(e, n); });
                $node.append($row, $children);
            } else {
                const icon = FILE_ICON[extOf(n.name)] || '📄';
                $row.append($('<span class="twisty"></span>'), $('<span class="ic">').text(icon), $('<span class="nm">').text(n.name));
                $row.on('click', () => openFile(n.path));
                $row.on('contextmenu', (e) => { e.preventDefault(); ctxMenu(e, n); });
                $node.append($row);
            }
            $frag.append($node);
        });
        return $frag;
    }

    // Simple context menu via modal choices
    async function ctxMenu(e, node) {
        const choice = await pickAction(node);
        if (!choice) return;
        if (choice === 'rename') {
            const to = await modal({ title: 'Rename', prompt: node.name, okText: 'Rename' });
            if (!to || to === node.name) return;
            const parent = node.path.includes('/') ? node.path.slice(0, node.path.lastIndexOf('/') + 1) : '';
            const r = await api('files.php', { action: 'rename', path: node.path, to: parent + to });
            if (r.success) {
                toast('Renamed');
                const ti = tabs.findIndex((t) => t.path === node.path);
                if (ti >= 0) {
                    tabs[ti].path = r.data.path;
                    if (activeIdx === ti) { activeFile = r.data.path; updateFileLabel(); setMode(activeFile); }
                    renderTabs();
                }
                loadTree(); saveState();
            } else toast(r.error, 'err');
        } else if (choice === 'delete') {
            const ok = await modal({ title: 'Delete', message: 'Delete "' + node.name + '"? This cannot be undone.', okText: 'Delete' });
            if (!ok) return;
            const r = await api('files.php', { action: 'delete', path: node.path });
            if (r.success) {
                toast('Deleted');
                // Close any open tab whose path is at/under the deleted node.
                for (let i = tabs.length - 1; i >= 0; i--) {
                    if (tabs[i].path === node.path || tabs[i].path.startsWith(node.path + '/')) {
                        tabs.splice(i, 1);
                        if (activeIdx === i) activeIdx = -1;
                        else if (activeIdx > i) activeIdx--;
                    }
                }
                if (activeIdx === -1) { if (tabs.length) switchToTab(Math.min(0, tabs.length - 1)); else showWelcome(); }
                else renderTabs();
                loadTree(); saveState();
            } else toast(r.error, 'err');
        } else if (choice === 'newfile' || choice === 'newfolder') {
            const base = node.type === 'dir' ? node.path + '/' : (node.path.includes('/') ? node.path.slice(0, node.path.lastIndexOf('/') + 1) : '');
            createEntry(choice === 'newfolder', base);
        }
    }
    function pickAction(node) {
        return new Promise((resolve) => {
            const $bd = $('#modal-backdrop');
            $('#modal-title').text(node.name);
            const $body = $('#modal-body').empty();
            const acts = [['📝 Rename', 'rename'], ['🗑 Delete', 'delete']];
            if (node.type === 'dir') { acts.unshift(['📁 New folder here', 'newfolder']); acts.unshift(['＋ New file here', 'newfile']); }
            acts.forEach(([label, val]) => {
                $('<button class="btn" style="display:block;width:100%;margin-bottom:6px;text-align:left">')
                    .text(label).on('click', () => { $bd.prop('hidden', true); resolve(val); }).appendTo($body);
            });
            $('#modal-ok').hide();
            $bd.prop('hidden', false);
            $('#modal-cancel').off('.p').on('click.p', () => { $bd.prop('hidden', true); $('#modal-ok').show(); resolve(null); });
        }).then((v) => { $('#modal-ok').show(); return v; });
    }

    // ---- Open / save files ------------------------------------------------
    // Returns a promise that resolves once the file is open & active.
    function openFile(path) {
        const existing = tabs.findIndex((t) => t.path === path);
        if (existing >= 0) { switchToTab(existing); return Promise.resolve(); }
        return api('files.php', { action: 'read', path }).then((r) => {
            if (!r.success) { toast(r.error, 'err'); return; }
            const mode = 'ace/mode/' + (EXT_MODE[extOf(r.data.path)] || 'text');
            const session = ace.createEditSession(r.data.content, mode);
            session.setOptions({ tabSize: 4, useSoftTabs: true, newLineMode: 'unix' });
            session.setUndoManager(new ace.UndoManager());
            tabs.push({ path: r.data.path, session, dirty: false, size: r.data.size, mime: r.data.mime });
            switchToTab(tabs.length - 1);
            saveState();
        });
    }
    function updateFileLabel() {
        const label = activeFile ? 'working_folder/' + activeFile : 'No file open';
        $('#active-file-label').text(label).attr('title', label);
    }
    function saveFile() {
        if (!activeFile) { toast('No file open'); return Promise.resolve(); }
        const content = editor.getValue();
        return api('files.php', { action: 'write', path: activeFile, content }).then((r) => {
            if (r.success) { markDirty(false); toast('Saved ' + activeFile, 'ok'); }
            else toast(r.error, 'err');
            return r;
        });
    }

    async function createEntry(isFolder, base) {
        base = base || '';
        const name = await modal({ title: isFolder ? 'New folder' : 'New file', prompt: '', okText: 'Create' });
        if (!name) return;
        const path = base + name;
        const action = isFolder ? 'mkdir' : 'create';
        const r = await api('files.php', { action, path });
        if (r.success) { toast(isFolder ? 'Folder created' : 'File created'); await loadTree(); if (!isFolder) openFile(r.data.path); }
        else toast(r.error, 'err');
    }

    // ---- Preview ----------------------------------------------------------
    // The preview pane is PINNED to a target file. Opening Preview points it at
    // the active file and loads it once. It does NOT auto-refresh on edits —
    // the user refreshes explicitly with the ⟳ button in the preview header.
    function previewUrl() {
        return 'api/preview.php?file=' + encodeURIComponent(previewTarget) + '&t=' + Date.now();
    }
    async function doPreview() {
        if (!activeFile) { toast('Open a file first'); return; }
        if (dirty) await saveFile();
        previewTarget = activeFile;
        $('#preview-frame').attr('src', previewUrl());
        $('#preview-target').text(previewTarget);
        $('#preview-wrap').prop('hidden', false);
        $('#btn-preview').addClass('active');
    }
    function reloadPreview() {
        if (!previewTarget) return;
        const go = () => $('#preview-frame').attr('src', previewUrl());
        // If the pinned file is the one being edited and is dirty, save first so
        // the refresh reflects the latest edits.
        if (dirty && activeFile === previewTarget) saveFile().then(go);
        else go();
    }
    function closePreview() {
        $('#preview-wrap').prop('hidden', true);
        $('#preview-frame').attr('src', 'about:blank');
        $('#btn-preview').removeClass('active');
    }

    // ---- AI chat ----------------------------------------------------------
    function addMsg(role, html, asText) {
        const $m = $('<div class="msg">').addClass(role);
        if (asText) $m.text(html); else $m.html(html);
        $('#chat-messages').append($m);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
        return $m;
    }
    function renderMarkdownish(text) {
        // Lightweight: escape, then code fences -> <pre>, inline `code`, **bold**.
        let s = escapeHtml(text);
        s = s.replace(/```(\w+)?\n?([\s\S]*?)```/g, (m, lang, code) =>
            '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>');
        s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\n/g, '<br>');
        return s;
    }
    function extractCodeBlock(text) {
        const m = text.match(/```(?:\w+)?\s*\n?([\s\S]*?)```/);
        return m ? m[1].replace(/\n$/, '') : null;
    }
    function applyEdits(jsonText) {
        let edits;
        try { edits = JSON.parse(jsonText); }
        catch (e) {
            // tolerate fenced JSON
            const inner = extractCodeBlock(jsonText);
            if (inner) { try { edits = JSON.parse(inner); } catch (e2) { return { error: 'Invalid JSON' }; } }
            else return { error: 'Invalid JSON' };
        }
        if (!Array.isArray(edits)) return { error: 'Not a JSON array' };
        let code = editor.getValue(), applied = 0, missing = [];
        for (let i = 0; i < edits.length; i++) {
            const e = edits[i];
            if (!e || typeof e.search !== 'string' || typeof e.replace !== 'string') return { error: 'Edit #' + (i + 1) + ' malformed' };
            if (code.includes(e.search)) { code = code.split(e.search).join(e.replace); applied++; }
            else missing.push(e.search.slice(0, 40));
        }
        return { code, applied, total: edits.length, missing };
    }

    function appendAssistant(data) {
        const text = data.response || '';
        const $m = addMsg('assistant', renderMarkdownish(text));

        // Reasoning accordion
        if (data.reasoning && data.reasoning.trim()) {
            const $d = $('<details>').append(
                $('<summary>').text('Show reasoning'),
                $('<div class="reasoning">').text(data.reasoning));
            $m.append($d);
        }
        // Apply buttons by mode. Applying changes the editor only — the preview
        // is never auto-refreshed (the user refreshes it when ready).
        if (data.mode === 'full') {
            const code = extractCodeBlock(text);
            if (code != null) {
                $('<button class="apply-btn">Apply full code</button>').on('click', () => {
                    if (!activeFile) { toast('Open a file first', 'err'); return; }
                    editor.setValue(code, -1); markDirty(true);
                    toast('Applied to editor', 'ok');
                }).appendTo($m);
            }
        } else if (data.mode === 'edit') {
            $('<button class="apply-btn">Apply edits</button>').on('click', () => {
                if (!activeFile) { toast('Open a file first', 'err'); return; }
                const res = applyEdits(text);
                if (res.error) { toast('Edit error: ' + res.error, 'err'); return; }
                editor.setValue(res.code, -1); markDirty(true);
                let msg = 'Applied ' + res.applied + '/' + res.total + ' edits';
                if (res.missing.length) msg += ' · ' + res.missing.length + ' not found';
                toast(msg, res.missing.length ? 'err' : 'ok');
            }).appendTo($m);
        }
        // Usage
        if (data.usage) {
            const u = data.usage;
            $('#usage-bar').prop('hidden', false).text(
                'model ' + (data.model || '') + ' · tokens: ' +
                (u.prompt_tokens || 0) + ' in / ' + (u.completion_tokens || 0) + ' out / ' +
                (u.total_tokens || 0) + ' total');
        }
    }

    function sendChat(presetPrompt) {
        const prompt = (presetPrompt != null ? presetPrompt : $('#chat-input').val()).trim();
        if (!prompt) return;
        if (!HAS_KEY) { toast('AI disabled: no K.dat key', 'err'); return; }

        addMsg('user', prompt, true);
        conversation.push({ role: 'user', content: prompt });
        $('#chat-input').val('');
        const $typing = addMsg('assistant', '<span class="typing"><span></span><span></span><span></span></span>');
        $('#btn-send').prop('disabled', true);

        api('ai_chat.php', {
            action: 'ai_chat',
            code: editor.getValue(),
            prompt,
            mode: aiMode,
            file: activeFile || '',
            model: $('#model-select').val(),
            enable_thinking: $('#thinking-toggle').is(':checked') ? '1' : '0',
            history: JSON.stringify(conversation.slice(0, -1))
        }).then((r) => {
            $typing.remove();
            if (r.success) {
                appendAssistant(r.data);
                conversation.push({ role: 'assistant', content: r.data.response });
                persistConversation();
            } else {
                addMsg('error', '⚠ ' + escapeHtml(r.error));
            }
        }).fail((xhr) => {
            $typing.remove();
            let m = 'Network error';
            try { m = JSON.parse(xhr.responseText).error || m; } catch (e) {}
            addMsg('error', '⚠ ' + escapeHtml(m));
        }).always(() => {
            $('#btn-send').prop('disabled', false);
            $('#chat-input').focus();
        });
    }

    function persistConversation() {
        if (!conversation.length) return;
        api('history.php', {
            action: 'append',
            id: conversationId,
            file: activeFile || '',
            turns: JSON.stringify(conversation)
        }).then((r) => { if (r.success) conversationId = r.data.id; });
    }
    function resetChat() {
        conversation = []; conversationId = '';
        $('#chat-messages').empty();
        $('#usage-bar').prop('hidden', true);
        welcomeMsg();
    }
    function welcomeMsg() {
        addMsg('system', HAS_KEY
            ? 'New conversation · ' + DEFAULT_MODEL + ' · Explain / Full / Edit modes available'
            : 'AI disabled — add your DeepSeek key to K.dat to enable.', true);
    }

    // ---- Media ------------------------------------------------------------
    function loadMedia() {
        return api('media.php', { action: 'list' }).then((r) => {
            if (!r.success) { toast(r.error, 'err'); return; }
            const $g = $('#media-grid').empty();
            if (!r.data.items.length) { $g.append('<div class="list-empty" style="grid-column:1/-1">No media yet. Upload with ⬆.</div>'); return; }
            r.data.items.forEach((it) => {
                const $cell = $('<div class="media-cell">').attr('title', it.name);
                if (it.thumb) {
                    $cell.append($('<img class="media-thumb">').attr('src', 'api/media.php?thumb=' + encodeURIComponent(it.thumb)));
                } else {
                    const ic = it.kind === 'audio' ? '🎵' : it.kind === 'video' ? '🎬' : it.kind === 'pdf' ? '📕' : it.kind === 'image' ? '🖼' : '📄';
                    $cell.append($('<div class="media-icon">').text(ic));
                }
                $cell.append($('<div class="media-name">').text(it.name));
                $('<button class="media-del" title="Delete">✕</button>').on('click', async (e) => {
                    e.stopPropagation();
                    const ok = await modal({ title: 'Delete media', message: 'Delete ' + it.name + '?', okText: 'Delete' });
                    if (!ok) return;
                    const dr = await api('media.php', { action: 'delete', name: it.name });
                    if (dr.success) { toast('Deleted'); loadMedia(); } else toast(dr.error, 'err');
                }).appendTo($cell);
                $cell.on('click', () => insertMedia(it));
                $g.append($cell);
            });
        });
    }
    function insertMedia(it) {
        const rel = it.rel; // uploads/xxxx.ext
        let snippet;
        if (it.kind === 'image') snippet = '<img src="' + rel + '" alt="">';
        else if (it.kind === 'audio') snippet = '<audio controls src="' + rel + '"></audio>';
        else if (it.kind === 'video') snippet = '<video controls src="' + rel + '"></video>';
        else if (it.kind === 'pdf') snippet = '<a href="' + rel + '">' + it.name + '</a>';
        else snippet = rel;
        if (activeFile) { editor.insert(snippet); editor.focus(); toast('Inserted'); }
        else { navigator.clipboard && navigator.clipboard.writeText(snippet); toast('Copied (no file open)'); }
    }
    function uploadFiles(files) {
        if (!files || !files.length) return;
        const queue = Array.from(files);
        let done = 0;
        queue.forEach((file) => {
            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('csrf_token', CSRF);
            fd.append('file', file);
            $.ajax({
                url: 'api/upload.php', type: 'POST', data: fd, processData: false, contentType: false,
                headers: { 'X-CSRF-Token': CSRF }, dataType: 'json'
            }).then((r) => {
                if (r.success) toast('Uploaded ' + r.data.name, 'ok');
                else toast(r.error, 'err');
            }).fail(() => toast('Upload failed: ' + file.name, 'err'))
              .always(() => { if (++done === queue.length) { loadMedia(); switchPanel('media'); } });
        });
    }

    // ---- History ----------------------------------------------------------
    function loadHistory() {
        return api('history.php', { action: 'list' }).then((r) => {
            if (!r.success) return;
            const $l = $('#history-list').empty();
            if (!r.data.conversations.length) { $l.append('<div class="list-empty">No conversations yet.</div>'); return; }
            r.data.conversations.forEach((c) => {
                const $it = $('<div class="list-item">');
                $it.append($('<div class="li-title">').text(c.title));
                $it.append($('<div class="li-meta">').append(
                    $('<span>').text(c.turns + ' turns'),
                    $('<span>').text(fmtTime(c.updated))));
                const $acts = $('<div class="li-actions">');
                $('<button class="btn">Load</button>').on('click', () => loadConversation(c.id)).appendTo($acts);
                $('<button class="btn">Delete</button>').on('click', async () => {
                    const dr = await api('history.php', { action: 'clear', id: c.id });
                    if (dr.success) { toast('Deleted'); loadHistory(); }
                }).appendTo($acts);
                $it.append($acts);
                $l.append($it);
            });
        });
    }
    function loadConversation(id) {
        api('history.php', { action: 'load', id }).then((r) => {
            if (!r.success) { toast(r.error, 'err'); return; }
            const c = r.data.conversation;
            conversation = (c.turns || []).map((t) => ({ role: t.role, content: t.content }));
            conversationId = c.id;
            $('#chat-messages').empty();
            addMsg('system', 'Loaded: ' + c.title, true);
            conversation.forEach((t) => {
                if (t.role === 'user') addMsg('user', t.content, true);
                else appendAssistant({ response: t.content, mode: 'explain' });
            });
            $('#btn-chat').addClass('active'); $('#chat-panel').removeClass('collapsed');
            toast('Conversation loaded', 'ok');
        });
    }

    // ---- Backups ----------------------------------------------------------
    function loadBackups() {
        return api('backup.php', { action: 'list' }).then((r) => {
            if (!r.success) { toast(r.error, 'err'); return; }
            const $l = $('#backup-list').empty();
            if (!r.data.backups.length) { $l.append('<div class="list-empty">No backups. Create one with ＋.</div>'); return; }
            r.data.backups.forEach((b) => {
                const $it = $('<div class="list-item">');
                $it.append($('<div class="li-title">').text(b.name));
                $it.append($('<div class="li-meta">').append(
                    $('<span>').text(fmtBytes(b.size)), $('<span>').text(fmtTime(b.mtime))));
                const $acts = $('<div class="li-actions">');
                $('<button class="btn">Restore</button>').on('click', async () => {
                    const ok = await modal({ title: 'Restore backup', message: 'Restore ' + b.name + '? Current files with the same names will be overwritten.', okText: 'Restore' });
                    if (!ok) return;
                    const rr = await api('backup.php', { action: 'restore', name: b.name });
                    if (rr.success) {
                        toast('Restored ' + rr.data.files + ' files', 'ok');
                        loadTree();
                        // Reload any open tabs whose file may have changed on disk.
                        const open = tabs.map((t) => t.path);
                        const wasActive = activeFile;
                        tabs = []; activeIdx = -1;
                        (async () => {
                            for (const p of open) { try { await openFile(p); } catch (e) {} }
                            const i = tabs.findIndex((t) => t.path === wasActive);
                            if (i >= 0) switchToTab(i); else if (!tabs.length) showWelcome();
                        })();
                    } else toast(rr.error, 'err');
                }).appendTo($acts);
                $('<button class="btn">Delete</button>').on('click', async () => {
                    const dr = await api('backup.php', { action: 'delete', name: b.name });
                    if (dr.success) { toast('Deleted'); loadBackups(); }
                }).appendTo($acts);
                $it.append($acts);
                $l.append($it);
            });
        });
    }
    function createBackup() {
        toast('Creating backup…');
        api('backup.php', { action: 'create' }).then((r) => {
            if (r.success) { toast('Backup created (' + r.data.files + ' files)', 'ok'); loadBackups(); }
            else toast(r.error, 'err');
        });
    }

    // ---- Tools ------------------------------------------------------------
    function runTool(tool) {
        if (!activeFile) { toast('Open a file first'); return; }
        const $out = $('#tool-output').addClass('show').text('Running ' + tool + '…');
        api('../tools/analyze.php', { action: tool, path: activeFile }).then((r) => {
            if (r.success) $out.text(r.data.output || '(no output)');
            else $out.text('Error: ' + r.error);
        }).fail(() => $out.text('Tool request failed'));
    }
    function runAiTool(kind) {
        const prompts = {
            explain: 'Explain what this file does, its structure, and any notable logic.',
            refactor: 'Suggest concrete refactors to improve this file. Be specific and show short before/after snippets.',
            docblocks: 'Add PHPDoc/docblock comments to the functions and classes. Return the full updated file in one code block.',
            bugs: 'Review this file for bugs, security issues, and edge cases. List each with severity and a suggested fix.'
        };
        if (!activeFile) { toast('Open a file first'); return; }
        if (!HAS_KEY) { toast('AI disabled: no K.dat key', 'err'); return; }
        // docblocks returns full file -> use full mode; others explain
        setMode_chat(kind === 'docblocks' ? 'full' : 'explain');
        $('#btn-chat').addClass('active'); $('#chat-panel').removeClass('collapsed');
        switchPanel('files');
        sendChat(prompts[kind]);
    }
    function setMode_chat(mode) {
        aiMode = mode;
        $('.mode-btn').removeClass('active');
        $('.mode-btn[data-mode="' + mode + '"]').addClass('active');
        saveState();
    }

    // ---- Panels -----------------------------------------------------------
    function switchPanel(name) {
        currentPanel = name;
        $('.rail-btn').removeClass('active');
        $('.rail-btn[data-panel="' + name + '"]').addClass('active');
        $('#side-panel .panel').removeClass('active');
        $('#side-panel .panel[data-panel="' + name + '"]').addClass('active');
        if (name === 'media') loadMedia();
        if (name === 'history') loadHistory();
        if (name === 'backups') loadBackups();
        saveState();
    }

    // ---- Wire up ----------------------------------------------------------
    function bind() {
        $('#btn-save').on('click', saveFile);
        // Preview button OPENS the preview (idempotent show); it never toggles
        // closed and never auto-refreshes. Close via ✕, refresh via ⟳.
        $('#btn-preview').on('click', doPreview);
        $('#btn-preview-reload').on('click', reloadPreview);
        $('#btn-preview-close').on('click', closePreview);
        $('#btn-preview-open').on('click', () => {
            const f = previewTarget || activeFile;
            if (f) window.open('api/preview.php?file=' + encodeURIComponent(f), '_blank', 'noopener');
        });
        $('#btn-chat').on('click', function () {
            $(this).toggleClass('active');
            $('#chat-panel').toggleClass('collapsed', !$(this).hasClass('active'));
            saveState();
        });
        $('#btn-new-file').on('click', () => createEntry(false, ''));
        $('#btn-new-folder').on('click', () => createEntry(true, ''));
        $('#btn-refresh').on('click', loadTree);
        $('#btn-upload, #btn-upload2').on('click', () => $('#upload-input').click());
        $('#upload-input').on('change', function () { uploadFiles(this.files); this.value = ''; });
        $('#btn-media-refresh').on('click', loadMedia);
        $('#btn-history-refresh').on('click', loadHistory);
        $('#btn-history-clear').on('click', async () => {
            const ok = await modal({ title: 'Clear all', message: 'Delete ALL saved conversations?', okText: 'Clear' });
            if (!ok) return;
            const r = await api('history.php', { action: 'clear' });
            if (r.success) { toast('Cleared'); loadHistory(); }
        });
        $('#btn-backup-create').on('click', createBackup);
        $('#btn-backup-refresh').on('click', loadBackups);

        $('.rail-btn').on('click', function () {
            const panel = $(this).data('panel');
            const wasActive = $(this).hasClass('active');
            const collapsed = $('#side-panel').hasClass('collapsed');
            if (wasActive && !collapsed) {
                $('#side-panel').addClass('collapsed');   // toggle off when re-clicking active
                saveState();
            } else {
                $('#side-panel').removeClass('collapsed');
                switchPanel(panel);
            }
        });

        $('.mode-btn').on('click', function () { setMode_chat($(this).data('mode')); });
        $('#btn-send').on('click', () => sendChat());
        $('#chat-input').on('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
        });
        $('#btn-reset-chat').on('click', resetChat);
        $('#model-select').on('change', saveState);
        $('#thinking-toggle').on('change', saveState);

        $('.tool-btn[data-tool]').on('click', function () { runTool($(this).data('tool')); });
        $('.tool-btn[data-aitool]').on('click', function () { runAiTool($(this).data('aitool')); });

        // AI code completion controls
        $('#cc-toggle').on('click', () => ccToggle());
        $('.cc-mode').on('click', function () { ccSetMode($(this).data('ccmode')); });
        // Accept controls (shown only while a suggestion is on screen). These
        // are the primary way to accept — Tab keeps its native behavior.
        $('#cc-accept').on('click', () => { ccAcceptGhost(); });
        $('#cc-accept-word').on('click', () => { if (CC.ghost) ccAcceptWord(); });

        // Drag-and-drop upload onto the window
        const $body = $('body');
        $body.on('dragover', (e) => { e.preventDefault(); });
        $body.on('drop', (e) => {
            e.preventDefault();
            const dt = e.originalEvent.dataTransfer;
            if (dt && dt.files && dt.files.length) uploadFiles(dt.files);
        });

        // Warn on unload if any tab has unsaved changes.
        window.addEventListener('beforeunload', (e) => {
            if (tabs.some((t) => t.dirty)) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    // ---- Init -------------------------------------------------------------
    async function restoreState() {
        const st = loadState();

        // Layout / option selections first (cheap, synchronous).
        expandedDirs = new Set(st.expandedDirs || []);
        if (st.model && $('#model-select option[value="' + st.model + '"]').length) $('#model-select').val(st.model);
        if (st.thinking) $('#thinking-toggle').prop('checked', true);
        setMode_chat(st.aiMode && /^(explain|full|edit)$/.test(st.aiMode) ? st.aiMode : 'explain');

        // Side panel + chat drawer: saved state wins; otherwise fall back to
        // viewport-based defaults so first run still looks right.
        if (st.panel) switchPanel(st.panel); else currentPanel = 'files';
        const hasSaved = Object.keys(st).length > 0;
        if (hasSaved) {
            $('#side-panel').toggleClass('collapsed', !!st.sidePanelCollapsed);
            $('#btn-chat').toggleClass('active', !!st.chatOpen);
            $('#chat-panel').toggleClass('collapsed', !st.chatOpen);
        } else if (window.innerWidth <= 1180) {
            $('#btn-chat').removeClass('active');
            $('#chat-panel').addClass('collapsed');
        }

        await loadTree();   // builds the tree honoring expandedDirs

        // Reopen files in order, then activate the previously active one.
        const files = (st.openFiles || []).slice(0, 24);
        for (const p of files) { try { await openFile(p); } catch (e) {} }
        if (st.activeFile) {
            const i = tabs.findIndex((t) => t.path === st.activeFile);
            if (i >= 0) switchToTab(i);
        }
        if (!tabs.length) showWelcome();
    }

    $(function () {
        initEditor();
        bind();
        ccLoadSettings();
        ccSyncUI();
        welcomeMsg();
        if (!HAS_KEY) $('#model-select, #btn-send, #chat-input').prop('disabled', true);
        $('#editor-status').text('Ready · PHP ' + '8.4');
        showWelcome();
        restoreState();
        // Force Ace to recompute size after layout settles.
        setTimeout(() => editor && editor.resize(), 80);
    });

})(jQuery);
