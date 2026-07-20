<?php
/**
 * Phase 41 — Map Overlay Categories API.
 *
 *   GET  ?action=list          → all categories with markup_count
 *   POST ?action=create        → category (name, color, icon, sort_order,
 *                                          default_visible, description)
 *   POST ?action=update        → fields by id
 *   POST ?action=archive       → soft-delete (archived_at)
 *   POST ?action=assign_markup → set mmarkup.category_id from markup_id
 *
 * RBAC: action.manage_config.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

if (!rbac_can('action.manage_config')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — requires action.manage_config']);
    exit;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : $_POST;
    if (!$action && !empty($input['action'])) $action = $input['action'];
}

if ($action === 'list' && $method === 'GET') {
    try {
        $rows = db_fetch_all(
            "SELECT c.id, c.category AS name, c.color, c.icon, c.sort_order,
                    c.default_visible, c.description,
                    (SELECT COUNT(*) FROM `{$prefix}mmarkup` m WHERE m.category_id = c.id) AS markup_count
               FROM `{$prefix}mmarkup_cats` c
              WHERE c.archived_at IS NULL
              ORDER BY c.sort_order ASC, c.category ASC"
        );
        json_response(['categories' => $rows]);
    } catch (Exception $e) { json_error('list failed: ' . $e->getMessage(), 500); }
}

if ($action === 'create' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $name = substr(trim((string) ($input['name'] ?? '')), 0, 24);
    if ($name === '') json_error('name required');
    $color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($input['color'] ?? '')) ? $input['color'] : '#1976d2';
    $icon = substr((string) ($input['icon'] ?? ''), 0, 32);
    $sort = (int) ($input['sort_order'] ?? 50);
    $vis  = empty($input['default_visible']) ? 0 : 1;
    $desc = substr((string) ($input['description'] ?? ''), 0, 255);

    try {
        db_query(
            "INSERT INTO `{$prefix}mmarkup_cats`
                (category, color, icon, sort_order, default_visible, description, _by, _from)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'mapcat-api')",
            [$name, $color, $icon, $sort, $vis, $desc, (int) ($_SESSION['user_id'] ?? 0) ?: 0]
        );
        json_response(['id' => (int) db_insert_id(), 'name' => $name]);
    } catch (Exception $e) { json_error('create failed: ' . $e->getMessage(), 500); }
}

if ($action === 'update' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    $sets = [];
    $params = [];
    if (isset($input['name']))         { $sets[] = "category = ?";        $params[] = substr((string) $input['name'], 0, 24); }
    if (isset($input['color']))        { $sets[] = "color = ?";           $params[] = preg_match('/^#[0-9a-f]{6}$/i', $input['color']) ? $input['color'] : '#1976d2'; }
    if (isset($input['icon']))         { $sets[] = "icon = ?";            $params[] = substr((string) $input['icon'], 0, 32); }
    if (isset($input['sort_order']))   { $sets[] = "sort_order = ?";      $params[] = (int) $input['sort_order']; }
    if (isset($input['default_visible'])) { $sets[] = "default_visible = ?"; $params[] = $input['default_visible'] ? 1 : 0; }
    if (isset($input['description']))  { $sets[] = "description = ?";     $params[] = substr((string) $input['description'], 0, 255); }
    if (!$sets) json_error('nothing to update');
    $params[] = $id;
    try {
        db_query("UPDATE `{$prefix}mmarkup_cats` SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error('update failed: ' . $e->getMessage(), 500); }
}

if ($action === 'archive' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        db_query("UPDATE `{$prefix}mmarkup_cats` SET archived_at = NOW() WHERE id = ?", [$id]);
        // Re-assign markups in this category to NULL so they don't disappear.
        db_query("UPDATE `{$prefix}mmarkup` SET category_id = NULL WHERE category_id = ?", [$id]);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error('archive failed: ' . $e->getMessage(), 500); }
}

if ($action === 'assign_markup' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $markupId = (int) ($input['markup_id'] ?? 0);
    $catId    = isset($input['category_id']) ? (int) $input['category_id'] : null;
    if ($markupId <= 0) json_error('markup_id required');
    try {
        db_query("UPDATE `{$prefix}mmarkup` SET category_id = ? WHERE id = ?", [$catId ?: null, $markupId]);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error('assign failed: ' . $e->getMessage(), 500); }
}

json_error('Unknown action: ' . $action);
