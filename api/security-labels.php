<?php
/**
 * NewUI v4.0 API — Security Labels CRUD + per-incident override (Phase 18b/18c).
 *
 * GET  /api/security-labels.php                      → list all
 * GET  /api/security-labels.php?id=N                 → one
 * GET  /api/security-labels.php?action=resolve&ticket=N
 *                                                    → resolved row for ticket
 * POST /api/security-labels.php                      → create / update
 *      JSON: action=create, fields...
 *      JSON: action=update, id, fields...
 *      JSON: action=delete, id
 *      JSON: action=apply_override, ticket_id, label_id, reason
 *      JSON: action=clear_override, ticket_id
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/security-labels.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!empty($_GET['action']) && $_GET['action'] === 'resolve') {
        $tid = (int) ($_GET['ticket'] ?? 0);
        if ($tid <= 0) json_error('ticket required');
        if (!rbac_can('action.view_incident') && !is_admin()) json_error('Forbidden', 403);
        json_response(['resolved' => seclabel_resolve($tid)]);
    }
    if (isset($_GET['id'])) {
        $r = seclabel_get((int) $_GET['id']);
        if (!$r) json_error('not found', 404);
        json_response(['label' => $r]);
    }
    json_response(['labels' => seclabel_get_all()]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('CSRF', 403);
    }
    $action = (string) ($input['action'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0) ?: null;

    if ($action === 'apply_override') {
        if (!rbac_can('action.set_incident_security')) json_error('Forbidden', 403);
        $tid = (int) ($input['ticket_id'] ?? 0);
        $lid = (int) ($input['label_id']  ?? 0);
        if ($tid <= 0 || $lid <= 0) json_error('ticket_id + label_id required');
        $r = seclabel_apply_override($tid, $lid, $input['reason'] ?? null, $userId);
        if (isset($r['error'])) json_error($r['error'], 400);
        json_response($r);
    }

    if ($action === 'clear_override') {
        if (!rbac_can('action.set_incident_security')) json_error('Forbidden', 403);
        $tid = (int) ($input['ticket_id'] ?? 0);
        if ($tid <= 0) json_error('ticket_id required');
        if (seclabel_clear_override($tid, $userId)) json_response(['ok' => true]);
        else json_error('failed');
    }

    // Admin-only from here on
    if (!rbac_can('action.manage_security_labels') && !is_admin()) {
        json_error('Forbidden — Super Admin only', 403);
    }

    if ($action === 'create') {
        $id = seclabel_create($input);
        if ($id > 0) json_response(['ok' => true, 'id' => $id]);
        json_error('Create failed', 400);
    }
    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        if (seclabel_update($id, $input)) json_response(['ok' => true]);
        json_error('Update failed', 400);
    }
    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $r = seclabel_delete($id);
        if (!empty($r['ok'])) json_response(['ok' => true]);
        json_error($r['error'] ?? 'delete failed', 400);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
