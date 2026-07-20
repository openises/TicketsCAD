<?php
/**
 * NewUI v4.0 API - Unit Status Management
 *
 * POST /api/unit-status-manage.php
 *   CRUD operations on the `un_status` table.
 *
 * Actions (via JSON body "action" field):
 *   create  - Insert a new status
 *   update  - Update an existing status by id
 *   delete  - Delete a status by id (id=1 "Available" is protected)
 *
 * Requires admin level (level <= 1).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Require admin ──────────────────────────────────────────────
if (!is_admin()) {
    json_error('Admin access required', 403);
}

// ── Only POST allowed ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// ── Parse input ────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_error('Invalid JSON body');
}

// ── CSRF check ─────────────────────────────────────────────────
$token = $input['csrf_token'] ?? '';
if (!csrf_verify($token)) {
    json_error('Invalid CSRF token', 403);
}

$action = trim($input['action'] ?? '');

// ═══════════════════════════════════════════════════════════════
//  CREATE
// ═══════════════════════════════════════════════════════════════
if ($action === 'create') {
    $statusVal  = trim($input['status_val'] ?? '');
    $desc       = trim($input['description'] ?? '');
    $dispatch   = (int) ($input['dispatch'] ?? 0);
    $watch      = (int) ($input['watch'] ?? 0);
    $hide       = ($input['hide'] ?? 'n') === 'y' ? 'y' : 'n';
    $exclReset  = ($input['excl_from_reset'] ?? 'n') === 'y' ? 'y' : 'n';
    $group      = trim($input['group'] ?? '');
    $sort       = (int) ($input['sort'] ?? 0);
    $bgColor    = trim($input['bg_color'] ?? '');
    $txtColor   = trim($input['text_color'] ?? '');

    if ($desc === '') {
        json_error('Status name (description) is required');
    }

    // Default status_val from description if not provided
    if ($statusVal === '') {
        $statusVal = substr($desc, 0, 20);
    }

    try {
        $sql = "INSERT INTO `{$prefix}un_status`
            (`status_val`, `description`, `dispatch`, `watch`, `hide`,
             `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        db_query($sql, [
            $statusVal, $desc, $dispatch, $watch, $hide,
            $exclReset, $group, $sort, $bgColor, $txtColor
        ]);
        $id = (int) db_insert_id();
        audit_log('config', 'create', 'unit_status', $id, "Created unit status '{$desc}'");
        json_response(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        json_error('Create failed: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
//  UPDATE
// ═══════════════════════════════════════════════════════════════
if ($action === 'update') {
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    if (!$id) {
        json_error('Missing status id');
    }

    $statusVal  = trim($input['status_val'] ?? '');
    $desc       = trim($input['description'] ?? '');
    $dispatch   = (int) ($input['dispatch'] ?? 0);
    $watch      = (int) ($input['watch'] ?? 0);
    $hide       = ($input['hide'] ?? 'n') === 'y' ? 'y' : 'n';
    $exclReset  = ($input['excl_from_reset'] ?? 'n') === 'y' ? 'y' : 'n';
    $group      = trim($input['group'] ?? '');
    $sort       = (int) ($input['sort'] ?? 0);
    $bgColor    = trim($input['bg_color'] ?? '');
    $txtColor   = trim($input['text_color'] ?? '');

    if ($desc === '') {
        json_error('Status name (description) is required');
    }

    if ($statusVal === '') {
        $statusVal = substr($desc, 0, 20);
    }

    try {
        $sql = "UPDATE `{$prefix}un_status` SET
            `status_val` = ?, `description` = ?, `dispatch` = ?, `watch` = ?,
            `hide` = ?, `excl_from_reset` = ?, `group` = ?, `sort` = ?,
            `bg_color` = ?, `text_color` = ?
            WHERE `id` = ?";
        db_query($sql, [
            $statusVal, $desc, $dispatch, $watch, $hide,
            $exclReset, $group, $sort, $bgColor, $txtColor, $id
        ]);
        audit_log('config', 'update', 'unit_status', $id, "Updated unit status '{$desc}'");
        json_response(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        json_error('Update failed: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
//  DELETE
// ═══════════════════════════════════════════════════════════════
if ($action === 'delete') {
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    if (!$id) {
        json_error('Missing status id');
    }

    // Protect the default "Available" status (id=1)
    if ($id === 1) {
        json_error('Cannot delete the default Available status (id=1)');
    }

    try {
        db_query("DELETE FROM `{$prefix}un_status` WHERE `id` = ?", [$id]);
        audit_log('config', 'delete', 'unit_status', $id, "Deleted unit status #{$id}");
        json_response(['success' => true, 'deleted' => $id]);
    } catch (Exception $e) {
        json_error('Delete failed: ' . $e->getMessage(), 500);
    }
}

// ── Unknown action ─────────────────────────────────────────────
json_error('Unknown action: ' . $action);
