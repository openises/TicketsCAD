<?php
/**
 * Phase 139 (2026-08-14) — Quick Notes API.
 *
 * GET  ?action=list[&done=0|1]        — this user's own notes
 * GET  ?action=wiki_tree               — this user's own personal SOP-Wiki pages
 * GET  ?action=ics214_forms&ticket_id= — existing 214 forms for an incident
 * POST action=create                   — { note_text }
 * POST action=delete                   — { id }
 * POST action=set_done                 — { id, done }
 * POST action=copy_to_activity_log     — { id, ticket_id, move }
 * POST action=copy_to_ics214           — { id, form_id, move }
 * POST action=copy_to_wiki             — { id, page_id | new_page_title, move }
 *
 * No RBAC permission gates this file -- every action is scoped to the
 * session's own user_id inside inc/quick-notes.php (ownership IS the
 * access control; notes are strictly private, never shared, per Eric's
 * explicit answer during the spec conversation).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/quick-notes.php';

ini_set('display_errors', '0');

$userId = (int) $current_user_id;
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $done = null;
        if (isset($_GET['done']) && $_GET['done'] !== '') $done = ((string) $_GET['done']) === '1';
        json_response(['notes' => quick_notes_list_internal($userId, $done)]);
    }

    if ($action === 'wiki_tree') {
        json_response(['pages' => quick_note_personal_wiki_tree_internal($userId)]);
    }

    if ($action === 'ics214_forms') {
        $ticketId = (int) ($_GET['ticket_id'] ?? 0);
        if ($ticketId <= 0) json_error('Invalid ticket_id');
        json_response(['forms' => quick_note_ics214_forms_for_incident($ticketId)]);
    }

    json_error('Unknown action', 400);
}

if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) json_error('Invalid JSON body');

if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$action = $input['action'] ?? '';

if ($action === 'create') {
    $result = quick_note_create_internal($userId, (string) ($input['note_text'] ?? ''));
    if (!empty($result['errors'])) json_response(['errors' => $result['errors']], 422);
    json_response(['success' => true, 'id' => $result['id']]);
}

if ($action === 'delete') {
    $result = quick_note_delete_internal($userId, (int) ($input['id'] ?? 0));
    if (!empty($result['errors'])) json_response(['errors' => $result['errors']], 404);
    json_response(['success' => true]);
}

if ($action === 'set_done') {
    $result = quick_note_set_done_internal($userId, (int) ($input['id'] ?? 0), !empty($input['done']));
    if (!empty($result['errors'])) json_response(['errors' => $result['errors']], 404);
    json_response(['success' => true]);
}

if ($action === 'copy_to_activity_log') {
    $result = quick_note_copy_to_activity_log_internal(
        $userId,
        (int) ($input['id'] ?? 0),
        (int) ($input['ticket_id'] ?? 0),
        !empty($input['move'])
    );
    if (!empty($result['errors'])) json_response(['errors' => $result['errors']], 422);
    json_response(['success' => true]);
}

if ($action === 'copy_to_ics214') {
    $result = quick_note_copy_to_ics214_internal(
        $userId,
        (int) ($input['id'] ?? 0),
        (int) ($input['form_id'] ?? 0),
        !empty($input['move'])
    );
    if (!empty($result['errors'])) json_response(['errors' => $result['errors']], 422);
    json_response(['success' => true]);
}

if ($action === 'copy_to_wiki') {
    $pageId = isset($input['page_id']) && $input['page_id'] !== '' ? (int) $input['page_id'] : null;
    $result = quick_note_copy_to_wiki_internal(
        $userId,
        (int) ($input['id'] ?? 0),
        $pageId,
        isset($input['new_page_title']) ? (string) $input['new_page_title'] : null,
        !empty($input['move'])
    );
    if (!empty($result['errors'])) json_response(['errors' => $result['errors']], 422);
    json_response(['success' => true, 'page_id' => $result['page_id'] ?? null]);
}

json_error('Unknown action', 400);
