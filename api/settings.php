<?php
/**
 * api/settings.php — Read & save operator settings.
 *
 *  POST action= get   -> { values, resolved, warnings, runtime, schema }
 *  POST action= save  -> body carries any subset of setting keys
 *                        -> { values, resolved, warnings, errors, reload }
 *  POST action= reset -> restore all defaults
 *
 * Settings change WORK_DIR / KEY_FILE etc. on the NEXT request (constants are
 * fixed for the current one), so `reload` tells the UI to refresh.
 *
 * @author  g023 (https://github.com/g023/)
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/settings.php';

$input  = api_guard();
$action = $input['action'] ?? '';

switch ($action) {
    case 'get':    settings_get();          break;
    case 'save':   settings_save($input);   break;
    case 'reset':  settings_reset();        break;
    default:       fail('Unknown action');
}

function settings_get(): never
{
    $pub = dspe_settings_public();
    $pub['schema'] = dspe_settings_schema();
    ok($pub);
}

function settings_save(array $input): never
{
    // Pull only schema keys from the request body.
    $patch = [];
    foreach (array_keys(dspe_settings_schema()) as $k) {
        if (array_key_exists($k, $input)) {
            $patch[$k] = $input[$k];
        }
    }
    if (!$patch) {
        fail('No settings provided');
    }

    // Detect path changes that require a reload to take effect.
    $before = dspe_settings();

    $res = dspe_settings_save($patch);
    if (!$res['ok']) {
        send_json(['success' => false, 'error' => 'Some settings are invalid.',
                   'errors' => $res['errors'], 'warnings' => $res['warnings'] ?? []], 422);
    }

    $after  = $res['values'];
    $reload = ($before['working_folder'] !== $after['working_folder'])
           || ($before['key_path'] !== $after['key_path']);

    ok([
        'values'   => $after,
        'resolved' => $res['paths'],
        'warnings' => $res['warnings'],
        'errors'   => $res['errors'],
        'reload'   => $reload,
    ]);
}

function settings_reset(): never
{
    $res = dspe_settings_save(dspe_settings_defaults());
    ok(['values' => $res['values'], 'resolved' => $res['paths'],
        'warnings' => $res['warnings'], 'reload' => true]);
}
