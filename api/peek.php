<?php
/**
 * api/peek.php — Expose the PEEK project map.
 *
 *  POST action= map      -> { generated, count, files, truncated }  (structured)
 *  POST action= render   -> { text }                                (prompt block)
 *       optional: file=<rel>  (marks the active file), force=1 (rebuild)
 *  POST action= symbols  -> { index }   (symbol => [files])
 *  POST action= deps     -> { edges }    (file => [included targets])
 *
 * The map is what the AI is given so it can understand the working folder
 * without reading every file (see lib/codemap.php). This endpoint also lets the
 * UI surface the map and lets tooling rebuild it.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/codemap.php';

$input  = api_guard();
$action = $input['action'] ?? 'map';
$force  = filter_var($input['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
$file   = (string) ($input['file'] ?? '');

$map = peek_build($force);

switch ($action) {
    case 'map':
        ok([
            'generated' => $map['generated'],
            'count'     => $map['count'],
            'truncated' => $map['truncated'],
            'files'     => $map['files'],
        ]);
        // no break (ok() exits)
    case 'render':
        ok(['text' => peek_render($map, $file !== '' ? $file : null)]);
    case 'symbols':
        ok(['index' => peek_symbol_index($map)]);
    case 'deps':
        ok(['edges' => peek_dependency_edges($map)]);
    default:
        fail('Unknown action');
}
