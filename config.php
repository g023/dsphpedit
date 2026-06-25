<?php
/**
 * DS PHP Edit — central configuration.
 *
 * Ships with working defaults; the app runs with zero edits. The only required
 * operator action is providing the DeepSeek key in K.dat (repo root).
 *
 * All paths derive from __DIR__ so the folder is path-independent (drop it
 * anywhere on a PHP host and it works). No absolute filesystem literals.
 *
 * @author  g023 (https://github.com/g023/)
 * @license MIT
 */

if (defined('DSPE_CONFIG_LOADED')) {
    return;
}
define('DSPE_CONFIG_LOADED', true);

// --- Core paths (all relative to repo root) -------------------------------
define('APP_ROOT', __DIR__);
define('LIB_DIR', APP_ROOT . '/lib');
define('API_DIR', APP_ROOT . '/api');

// Operator settings live OUTSIDE working_folder (so they survive a working-
// folder switch) and outside the web-served picker. Zero-config: the file may
// be absent and every default below applies. See lib/settings.php for the full
// schema, validation, and the save path.
define('SETTINGS_FILE', APP_ROOT . '/dspe_settings.json');
$GLOBALS['__dspe_settings_raw'] = dspe_load_settings_file();

// Working folder and key path are operator-configurable (relative OR absolute).
// Everything downstream still uses these constants unchanged, so a single
// resolution point here re-points the whole app.
define('WORK_DIR', dspe_resolve_setting_path(
    (string) ($GLOBALS['__dspe_settings_raw']['working_folder'] ?? ''),
    APP_ROOT . '/working_folder'
));
define('KEY_FILE', dspe_resolve_setting_path(
    (string) ($GLOBALS['__dspe_settings_raw']['key_path'] ?? ''),
    APP_ROOT . '/K.dat'
));
define('UPLOAD_DIR', WORK_DIR . '/uploads');
define('THUMB_DIR', UPLOAD_DIR . '/.thumbs');
define('BACKUP_DIR', WORK_DIR . '/g023_backups');
define('HISTORY_FILE', WORK_DIR . '/g023_history.json');

// True when WORK_DIR sits inside APP_ROOT — required for the native-redirect
// preview to reach the file over HTTP. Editing/AI work regardless; only preview
// needs web-reachability (see api/preview.php).
define('WORK_DIR_IN_APPROOT',
    WORK_DIR === APP_ROOT
    || str_starts_with(WORK_DIR, APP_ROOT . DIRECTORY_SEPARATOR));

// Names filtered out of the user-facing file picker (app-managed state).
define('HIDDEN_NAMES', ['g023_history.json', 'g023_backups', 'uploads']);

// --- DeepSeek model policy ------------------------------------------------
// Flash is the default EVERYWHERE. Pro is opt-in only (never default).
define('DEFAULT_MODEL', 'deepseek-v4-flash');
define('PRO_MODEL', 'deepseek-v4-pro');
define('ALLOWED_MODELS', [DEFAULT_MODEL, PRO_MODEL]);
// NOTE: the DeepSeek endpoint URL lives ONLY in lib/ds4.php (the single
// connector) so all provider-contact logic is in exactly one place.
define('DEEPSEEK_TIMEOUT', dspe_setting_int('deepseek_timeout', 120, 10, 600));

// UI-preferred model: which allowlisted model the front-end pre-selects and
// passes by default. The SERVER-SIDE hard default stays flash everywhere
// (DEFAULT_MODEL) — this only changes what the client offers first, so the
// "flash default" policy still holds whenever the client sends nothing.
$__dspe_pref = (string) ($GLOBALS['__dspe_settings_raw']['default_model'] ?? '');
define('PREFERRED_MODEL', in_array($__dspe_pref, ALLOWED_MODELS, true) ? $__dspe_pref : DEFAULT_MODEL);

// --- Limits ---------------------------------------------------------------
define('MAX_UPLOAD_BYTES', 25 * 1024 * 1024);       // 25 MB
define('MAX_EDIT_FILE_BYTES', 5 * 1024 * 1024);     // editor file cap
define('THUMB_MAX', 240);                            // thumbnail longest edge
define('PREVIEW_TIMEOUT', 15);                       // seconds for preview exec
define('PREVIEW_MEMORY', '128M');
define('HISTORY_MAX_CONVERSATIONS', 100);            // rotation cap
define('BACKUP_RETENTION', 20);                      // keep N most recent
define('SESSION_IDLE_TIMEOUT', 8 * 3600);           // 8 hours

// --- Upload MIME allowlist: mime => stored extension ----------------------
define('UPLOAD_MIME_MAP', [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'image/bmp'       => 'bmp',
    'image/svg+xml'   => 'svg',
    'application/pdf' => 'pdf',
    'audio/mpeg'      => 'mp3',
    'audio/wav'       => 'wav',
    'audio/ogg'       => 'ogg',
    'audio/mp4'       => 'm4a',
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'text/plain'      => 'txt',
]);

// MIME types we re-encode through GD on ingest (raster images only).
define('GD_REENCODE_MIME', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp']);

// --- Feature flags --------------------------------------------------------
define('AUTO_BACKUP_ON_SAVE', dspe_setting_bool('auto_backup_on_save', false));   // opt-in
define('CSP_ENFORCE', true);            // false => Content-Security-Policy-Report-Only

// --- Agentic reading / PEEK map (AI context) ------------------------------
// The AI can automatically pull in files the edited file depends on (follows
// include/require + symbol references) and a compact structural map of the
// project, so it understands code without the operator pasting everything.
define('AGENTIC_READING', dspe_setting_bool('agentic_reading', true));
define('AGENTIC_MAX_FILES', dspe_setting_int('agentic_max_files', 6, 0, 40));
define('AGENTIC_MAX_BYTES', dspe_setting_int('agentic_max_bytes', 60000, 0, 400000));
define('AGENTIC_MAX_DEPTH', dspe_setting_int('agentic_max_depth', 3, 1, 6));
define('PEEK_ENABLED', dspe_setting_bool('peek_enabled', true));
define('PEEK_MAX_FILES', dspe_setting_int('peek_max_files', 400, 10, 5000));
define('PEEK_CACHE_FILE', WORK_DIR . '/.g023_peek.json');

// Ensure required runtime directories/files exist (self-creating state).
function dspe_bootstrap_state(): void
{
    foreach ([WORK_DIR, UPLOAD_DIR, THUMB_DIR, BACKUP_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    if (!is_file(HISTORY_FILE)) {
        @file_put_contents(HISTORY_FILE, json_encode(['conversations' => []]));
    }
    // Drop a no-execute guard into the upload dir if absent.
    $htaccess = UPLOAD_DIR . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, dspe_upload_htaccess());
    }
}

/**
 * Read the raw settings JSON (associative). Returns [] when absent/invalid —
 * the app is fully functional with zero settings. Never throws.
 */
function dspe_load_settings_file(): array
{
    if (!is_file(SETTINGS_FILE)) {
        return [];
    }
    $raw = @file_get_contents(SETTINGS_FILE);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Resolve a configurable path setting to an absolute, normalized path.
 *  - empty            -> $default
 *  - absolute (/, \\, X:\) -> used as-is (operator is trusted; localhost posture)
 *  - relative         -> resolved against APP_ROOT
 * Trailing slashes are trimmed. No existence check (the dir/file may be created
 * later); callers validate as needed.
 */
function dspe_resolve_setting_path(string $value, string $default): string
{
    $value = trim($value);
    if ($value === '') {
        return $default;
    }
    // Reject null bytes outright.
    if (strpos($value, "\0") !== false) {
        return $default;
    }
    $norm = str_replace('\\', '/', $value);
    $isAbsUnix = $norm !== '' && $norm[0] === '/';
    $isAbsWin  = (bool) preg_match('#^[A-Za-z]:/#', $norm);
    $abs = ($isAbsUnix || $isAbsWin) ? $value : APP_ROOT . DIRECTORY_SEPARATOR . $value;
    // Trim a trailing separator (but keep a lone root "/").
    $abs = rtrim($abs, "/\\");
    return $abs === '' ? $default : $abs;
}

/** Read a settings value as a clamped integer (with default + bounds). */
function dspe_setting_int(string $key, int $default, int $min, int $max): int
{
    $v = $GLOBALS['__dspe_settings_raw'][$key] ?? null;
    if ($v === null || $v === '' || !is_numeric($v)) {
        return $default;
    }
    $n = (int) $v;
    return max($min, min($max, $n));
}

/** Read a settings value as a boolean (accepts true/false/"1"/"0"/1/0). */
function dspe_setting_bool(string $key, bool $default): bool
{
    if (!array_key_exists($key, $GLOBALS['__dspe_settings_raw'])) {
        return $default;
    }
    return filter_var($GLOBALS['__dspe_settings_raw'][$key], FILTER_VALIDATE_BOOLEAN);
}

function dspe_upload_htaccess(): string
{
    // Portable across mod_php AND PHP-FPM/FastCGI: we do NOT use `php_flag`
    // (mod_php-only — it 500s under FPM). Instead we deny access to any
    // script-y file and strip handlers/types, wrapped so an unavailable
    // module never breaks the directory.
    // No `php_flag`/`php_admin_flag` here: those are rejected in .htaccess on
    // many servers (php_admin_* is never permitted in .htaccess, and a single
    // disallowed directive 500s the whole directory). Denying the script
    // extensions outright + stripping handlers is portable and sufficient.
    return <<<HT
# Auto-generated by DS PHP Edit — uploaded media must never execute as code.
<FilesMatch "\\.(php|phtml|php3|php4|php5|php7|phps|pht|phar|cgi|pl|py|asp|aspx|sh|htaccess)$">
    Require all denied
</FilesMatch>
<IfModule mod_mime.c>
    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .pht .phar
    RemoveType .php .phtml .php3 .php4 .php5 .php7 .phps .pht .phar
</IfModule>
<IfModule mod_autoindex.c>
    IndexIgnore *
</IfModule>
HT;
}
