<?php
/**
 * Phase 37 — Per-role session timeout admin API.
 *
 *   GET  ?action=list    → [{id, name, description, session_timeout_minutes}]
 *   POST ?action=save    → bulk update; body = [{role_id, minutes|null}, ...]
 *
 * RBAC: action.manage_roles (admin-only).
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';   // exits if not authenticated
require_once __DIR__ . '/../inc/rbac.php';

if (!rbac_can('action.manage_roles')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — requires action.manage_roles']);
    exit;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list' && $method === 'GET') {
    try {
        $rows = db_fetch_all(
            "SELECT id, name, description, session_timeout_minutes
               FROM `{$prefix}roles`
              ORDER BY name ASC"
        );
        json_response(['roles' => $rows]);
    } catch (Exception $e) {
        json_error('list failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'save' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $items = $input['items'] ?? [];
    if (!is_array($items)) json_error('items must be an array');

    $updated = 0;
    try {
        foreach ($items as $item) {
            $roleId = (int) ($item['role_id'] ?? 0);
            if ($roleId <= 0) continue;
            $raw = $item['minutes'] ?? null;
            $minutes = ($raw === '' || $raw === null) ? null : max(0, min(14400, (int) $raw));
            // Treat 0 as "inherit default" — same as null.
            if ($minutes === 0) $minutes = null;
            db_query(
                "UPDATE `{$prefix}roles`
                    SET session_timeout_minutes = ?
                  WHERE id = ?",
                [$minutes, $roleId]
            );
            $updated++;
        }
        json_response(['updated' => $updated]);
    } catch (Exception $e) {
        json_error('save failed: ' . $e->getMessage(), 500);
    }
}

json_error('Unknown action: ' . $action);
