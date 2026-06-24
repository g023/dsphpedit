<?php
/**
 * api/history.php — Conversation history in working_folder/g023_history.json.
 *
 *  POST action= list | load | append | clear
 *
 * Storage shape: {"conversations":[{id,title,file,updated,turns:[{role,content,ts}]}]}
 * Rotated to HISTORY_MAX_CONVERSATIONS most-recent.
 *
 * @license MIT
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/response.php';

$input  = api_guard();
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'list':   history_list();          break;
        case 'load':   history_load($input);    break;
        case 'append': history_append($input);  break;
        case 'clear':  history_clear($input);   break;
        default:       fail('Unknown action');
    }
} catch (RuntimeException $e) {
    fail($e->getMessage());
}

function history_read(): array
{
    if (!is_file(HISTORY_FILE)) {
        return ['conversations' => []];
    }
    $data = json_decode((string) file_get_contents(HISTORY_FILE), true);
    if (!is_array($data) || !isset($data['conversations']) || !is_array($data['conversations'])) {
        return ['conversations' => []];
    }
    return $data;
}

function history_write(array $data): void
{
    // Rotate.
    if (count($data['conversations']) > HISTORY_MAX_CONVERSATIONS) {
        usort($data['conversations'], fn($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));
        $data['conversations'] = array_slice($data['conversations'], 0, HISTORY_MAX_CONVERSATIONS);
    }
    $tmp = HISTORY_FILE . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @rename($tmp, HISTORY_FILE);
}

function history_list(): never
{
    $data = history_read();
    $out = [];
    foreach ($data['conversations'] as $c) {
        $out[] = [
            'id'      => $c['id'] ?? '',
            'title'   => $c['title'] ?? '(untitled)',
            'file'    => $c['file'] ?? '',
            'updated' => $c['updated'] ?? 0,
            'turns'   => count($c['turns'] ?? []),
        ];
    }
    usort($out, fn($a, $b) => $b['updated'] <=> $a['updated']);
    ok(['conversations' => $out]);
}

function history_load(array $input): never
{
    $id = (string) ($input['id'] ?? '');
    foreach (history_read()['conversations'] as $c) {
        if (($c['id'] ?? '') === $id) {
            ok(['conversation' => $c]);
        }
    }
    fail('Conversation not found', 404);
}

function history_append(array $input): never
{
    $id    = (string) ($input['id'] ?? '');
    $turns = $input['turns'] ?? null;
    if (is_string($turns)) {
        $turns = json_decode($turns, true);
    }
    if (!is_array($turns)) {
        fail('turns must be an array');
    }
    // Sanitize turns.
    $clean = [];
    foreach ($turns as $t) {
        if (isset($t['role'], $t['content']) && in_array($t['role'], ['user', 'assistant'], true)) {
            $clean[] = [
                'role'    => $t['role'],
                'content' => (string) $t['content'],
                'ts'      => (int) ($t['ts'] ?? time()),
            ];
        }
    }

    $data = history_read();
    $found = false;
    foreach ($data['conversations'] as &$c) {
        if (($c['id'] ?? '') === $id && $id !== '') {
            $c['turns']   = $clean;
            $c['updated'] = time();
            if (!empty($input['file'])) {
                $c['file'] = (string) $input['file'];
            }
            if (!empty($input['title'])) {
                $c['title'] = mb_substr((string) $input['title'], 0, 80);
            }
            $found = true;
            break;
        }
    }
    unset($c);

    if (!$found) {
        $id = $id !== '' ? $id : bin2hex(random_bytes(8));
        $title = !empty($input['title'])
            ? mb_substr((string) $input['title'], 0, 80)
            : derive_title($clean);
        $data['conversations'][] = [
            'id'      => $id,
            'title'   => $title,
            'file'    => (string) ($input['file'] ?? ''),
            'updated' => time(),
            'turns'   => $clean,
        ];
    }
    history_write($data);
    ok(['id' => $id]);
}

function derive_title(array $turns): string
{
    foreach ($turns as $t) {
        if ($t['role'] === 'user') {
            return mb_substr(trim($t['content']), 0, 60) ?: '(untitled)';
        }
    }
    return '(untitled)';
}

function history_clear(array $input): never
{
    $id = (string) ($input['id'] ?? '');
    if ($id === '') {
        // Clear all.
        history_write(['conversations' => []]);
        ok(['cleared' => 'all']);
    }
    $data = history_read();
    $data['conversations'] = array_values(array_filter(
        $data['conversations'],
        fn($c) => ($c['id'] ?? '') !== $id
    ));
    history_write($data);
    ok(['cleared' => $id]);
}
