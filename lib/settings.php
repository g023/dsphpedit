<?php
/**
 * lib/settings.php — Operator settings: schema, validation, and persistence.
 *
 * The raw JSON is loaded in config.php (so WORK_DIR / KEY_FILE can be resolved
 * before anything else). This file adds the typed schema, validated reads, the
 * save path, and a "public" view safe to hand the front-end.
 *
 * Settings file: dspe_settings.json at the repo root (SETTINGS_FILE). It lives
 * OUTSIDE working_folder on purpose — changing the working folder must not lose
 * your settings — and is denied to the web by .htaccess / router.php.
 *
 * Posture (SECURITY.md): single trusted localhost operator. That is why
 * absolute key/working-folder paths are permitted — it is the operator pointing
 * the tool at their own files, by design.
 *
 * @author  g023 (https://github.com/g023/)
 * @license MIT
 */

require_once __DIR__ . '/../config.php';

/**
 * The canonical schema: key => [type, default, ...constraints].
 * type: 'path' | 'enum' | 'bool' | 'int' | 'string'
 *  - path:   {default}
 *  - enum:   {default, choices}
 *  - bool:   {default}
 *  - int:    {default, min, max}
 *  - string: {default, maxlen}
 */
function dspe_settings_schema(): array
{
    return [
        // --- Paths -------------------------------------------------------
        'working_folder' => ['type' => 'path', 'default' => ''],   // '' => bundled working_folder/
        'key_path'       => ['type' => 'path', 'default' => ''],   // '' => bundled K.dat

        // --- DeepSeek / AI ----------------------------------------------
        'default_model'    => ['type' => 'enum', 'default' => DEFAULT_MODEL, 'choices' => ALLOWED_MODELS],
        'thinking_default' => ['type' => 'bool', 'default' => false],
        'reasoning_effort' => ['type' => 'enum', 'default' => 'medium', 'choices' => ['low', 'medium', 'high']],
        'deepseek_timeout' => ['type' => 'int',  'default' => 120, 'min' => 10, 'max' => 600],

        // --- Agentic reading / PEEK -------------------------------------
        'agentic_reading'   => ['type' => 'bool', 'default' => true],
        'agentic_max_files' => ['type' => 'int',  'default' => 6,     'min' => 0, 'max' => 40],
        'agentic_max_bytes' => ['type' => 'int',  'default' => 60000, 'min' => 0, 'max' => 400000],
        'agentic_max_depth' => ['type' => 'int',  'default' => 3,     'min' => 1, 'max' => 6],
        'peek_enabled'      => ['type' => 'bool', 'default' => true],
        'peek_max_files'    => ['type' => 'int',  'default' => 400,   'min' => 10, 'max' => 5000],

        // --- Editor (client-side) ---------------------------------------
        'editor_font_size'  => ['type' => 'int',  'default' => 14, 'min' => 9, 'max' => 28],
        'editor_tab_size'   => ['type' => 'int',  'default' => 4,  'min' => 2, 'max' => 8],
        'editor_word_wrap'  => ['type' => 'bool', 'default' => false],
        'auto_backup_on_save' => ['type' => 'bool', 'default' => false],
    ];
}

/** Defaults only (key => default value). */
function dspe_settings_defaults(): array
{
    $out = [];
    foreach (dspe_settings_schema() as $k => $spec) {
        $out[$k] = $spec['default'];
    }
    return $out;
}

/**
 * Full effective settings = defaults overlaid with validated stored values.
 * Unknown keys in the file are ignored; invalid values fall back to default.
 */
function dspe_settings(): array
{
    $raw = $GLOBALS['__dspe_settings_raw'] ?? dspe_load_settings_file();
    return dspe_settings_validate($raw)['values'];
}

/** Single setting accessor with validation + default fallback. */
function dspe_setting(string $key, mixed $fallback = null): mixed
{
    $all = dspe_settings();
    return $all[$key] ?? $fallback;
}

/**
 * Validate an arbitrary input array against the schema.
 * Returns ['values' => fully-typed settings, 'errors' => [key => message]].
 * Every key is always present in 'values' (invalid/missing => default).
 */
function dspe_settings_validate(array $input): array
{
    $schema = dspe_settings_schema();
    $values = [];
    $errors = [];

    foreach ($schema as $key => $spec) {
        $has = array_key_exists($key, $input);
        $raw = $has ? $input[$key] : null;
        $def = $spec['default'];

        if (!$has) {
            $values[$key] = $def;
            continue;
        }

        switch ($spec['type']) {
            case 'path':
                $v = is_string($raw) ? trim($raw) : '';
                if ($v !== '' && strpos($v, "\0") !== false) {
                    $errors[$key] = 'Path contains a null byte.';
                    $v = '';
                }
                if (strlen($v) > 4096) {
                    $errors[$key] = 'Path too long.';
                    $v = '';
                }
                $values[$key] = $v;
                break;

            case 'enum':
                $v = is_string($raw) ? $raw : (string) $raw;
                if (!in_array($v, $spec['choices'], true)) {
                    $errors[$key] = 'Invalid choice; using default.';
                    $v = $def;
                }
                $values[$key] = $v;
                break;

            case 'bool':
                $values[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
                break;

            case 'int':
                if (!is_numeric($raw)) {
                    $errors[$key] = 'Not a number; using default.';
                    $values[$key] = $def;
                } else {
                    $n = (int) $raw;
                    if ($n < $spec['min'] || $n > $spec['max']) {
                        $errors[$key] = "Out of range ({$spec['min']}–{$spec['max']}); clamped.";
                    }
                    $values[$key] = max($spec['min'], min($spec['max'], $n));
                }
                break;

            default: // string
                $v = is_string($raw) ? $raw : (string) $raw;
                $max = $spec['maxlen'] ?? 1000;
                if (strlen($v) > $max) {
                    $v = substr($v, 0, $max);
                }
                $values[$key] = $v;
        }
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * Validate the requested working folder / key path against the live filesystem
 * so the UI can warn BEFORE the operator reloads into a broken state.
 * Returns ['errors'=>[k=>msg], 'warnings'=>[k=>msg], 'resolved'=>[k=>absPath]].
 */
function dspe_settings_check_paths(array $values): array
{
    $errors = [];
    $warnings = [];
    $resolved = [];

    // Working folder: resolve, must be a directory (or creatable), must be
    // writable for saves/uploads/backups. Warn if outside APP_ROOT (preview).
    $wf = dspe_resolve_setting_path((string) ($values['working_folder'] ?? ''), APP_ROOT . '/working_folder');
    $resolved['working_folder'] = $wf;
    if (is_file($wf)) {
        $errors['working_folder'] = 'That path is a file, not a folder.';
    } elseif (is_dir($wf)) {
        if (!is_writable($wf)) {
            $warnings['working_folder'] = 'Folder exists but is not writable — saves/uploads will fail.';
        }
    } else {
        // Does not exist yet — is the parent writable so we can create it?
        $parent = dirname($wf);
        if (!is_dir($parent) || !is_writable($parent)) {
            $errors['working_folder'] = 'Folder does not exist and cannot be created (parent missing or not writable).';
        } else {
            $warnings['working_folder'] = 'Folder will be created on next load.';
        }
    }
    $inApp = ($wf === APP_ROOT || str_starts_with($wf, APP_ROOT . DIRECTORY_SEPARATOR));
    if (!$inApp && !isset($errors['working_folder'])) {
        $warnings['working_folder'] = trim((($warnings['working_folder'] ?? '') . ' Outside the app directory — editing/AI work, but server-side Preview is unavailable.'));
    }

    // Key path: only warn (AI degrades gracefully without a key).
    $kp = dspe_resolve_setting_path((string) ($values['key_path'] ?? ''), APP_ROOT . '/K.dat');
    $resolved['key_path'] = $kp;
    if (is_dir($kp)) {
        $errors['key_path'] = 'That path is a folder, not a file.';
    } elseif (!is_file($kp)) {
        $warnings['key_path'] = 'No key file at that path yet — AI features stay disabled until one exists.';
    } elseif (trim((string) @file_get_contents($kp)) === '') {
        $warnings['key_path'] = 'Key file is empty — AI features stay disabled.';
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'resolved' => $resolved];
}

/**
 * Persist settings atomically. $patch is merged over the current stored values,
 * then validated. Returns ['ok'=>bool, 'values'=>..., 'errors'=>..., 'paths'=>...].
 * Never writes the key itself; only the *path* to the key file.
 */
function dspe_settings_save(array $patch): array
{
    $current = $GLOBALS['__dspe_settings_raw'] ?? dspe_load_settings_file();
    // Only accept known keys from the patch.
    $known = array_keys(dspe_settings_schema());
    foreach ($patch as $k => $v) {
        if (in_array($k, $known, true)) {
            $current[$k] = $v;
        }
    }

    $res   = dspe_settings_validate($current);
    $paths = dspe_settings_check_paths($res['values']);

    // Hard path errors block the save (would brick the app on reload).
    $blocking = $res['errors'] + $paths['errors'];
    if (!empty($paths['errors'])) {
        return ['ok' => false, 'values' => $res['values'], 'errors' => $blocking,
                'warnings' => $paths['warnings'], 'paths' => $paths['resolved']];
    }

    $json = json_encode($res['values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'values' => $res['values'], 'errors' => ['_' => 'Could not encode settings.'],
                'warnings' => $paths['warnings'], 'paths' => $paths['resolved']];
    }

    $tmp = SETTINGS_FILE . '.tmp_' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false || !@rename($tmp, SETTINGS_FILE)) {
        @unlink($tmp);
        return ['ok' => false, 'values' => $res['values'], 'errors' => ['_' => 'Could not write settings file.'],
                'warnings' => $paths['warnings'], 'paths' => $paths['resolved']];
    }
    @chmod(SETTINGS_FILE, 0664);
    // Reflect the new values for the rest of THIS request.
    $GLOBALS['__dspe_settings_raw'] = $res['values'];

    return ['ok' => true, 'values' => $res['values'], 'errors' => $res['errors'],
            'warnings' => $paths['warnings'], 'paths' => $paths['resolved']];
}

/**
 * Settings + resolved runtime facts for the front-end. Never includes the key.
 * Path values are echoed back exactly as stored (relative or absolute) plus the
 * resolved absolute form for display.
 */
function dspe_settings_public(): array
{
    $values = dspe_settings();
    $paths  = dspe_settings_check_paths($values);
    return [
        'values'   => $values,
        'resolved' => $paths['resolved'],
        'warnings' => $paths['warnings'],
        'runtime'  => [
            'app_root'           => APP_ROOT,
            'work_dir'           => WORK_DIR,
            'work_dir_in_approot'=> WORK_DIR_IN_APPROOT,
            'preview_supported'  => WORK_DIR_IN_APPROOT,
            'default_work_dir'   => APP_ROOT . '/working_folder',
            'default_key_path'   => APP_ROOT . '/K.dat',
        ],
    ];
}
