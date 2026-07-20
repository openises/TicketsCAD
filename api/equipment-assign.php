<?php
/**
 * NewUI v4.0 API — Equipment cache checkout (Phase 109 Slice C).
 *
 * GET  ?action=cache                 available cache items (available_for_events
 *                                     = 1 AND not currently issued)
 * GET  ?action=for_member&member_id  gear currently issued to a member
 * POST action=issue  { equipment_id, member_id }   check a cache item OUT
 * POST action=return { assignment_id | equipment_id }  check it back IN
 *
 * Gear follows the PERSON (not the unit). Every issue/return is a row in the
 * equipment_assignments ledger (issued_at/returned_at) + an audit entry, and
 * updates newui_equipment.assigned_member_id as the quick "current holder"
 * pointer. Reads gate on screen.net_control; writes on action.issue_equipment.
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
}

// Read gate: net-control operators; write gate: action.issue_equipment.
if (!rbac_can('screen.net_control') && !rbac_can('action.issue_equipment') && !is_admin()) {
    json_error('Insufficient permissions: equipment cache', 403);
}
$canIssue = rbac_can('action.issue_equipment') || is_admin();

/** Member display name (cached). */
function _eq_member_name(int $id): string {
    static $c = [];
    if ($id <= 0) return '';
    if (isset($c[$id])) return $c[$id];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $m = db_fetch_one("SELECT fname, lname FROM `{$prefix}member` WHERE id = ?", [$id]);
        $n = $m ? trim(($m['fname'] ?? '') . ' ' . ($m['lname'] ?? '')) : '';
    } catch (Throwable $e) { $n = ''; }
    return $c[$id] = ($n !== '' ? $n : ('Member #' . $id));
}

/** Assemble a display label for an equipment row. */
function _eq_label(array $e): string {
    $bits = [];
    if (!empty($e['name']))         $bits[] = $e['name'];
    elseif (!empty($e['type_name'])) $bits[] = $e['type_name'];
    $tag = $e['asset_tag'] ?: ($e['serial_number'] ?? '');
    if ($tag !== '' && $tag !== null) $bits[] = '(' . $tag . ')';
    return trim(implode(' ', $bits)) ?: ('Item #' . (int) $e['id']);
}

// ── GET: available cache ─────────────────────────────────────────────────────
if ($method === 'GET' && ($action === 'cache' || $action === '')) {
    $rows = [];
    try {
        $rows = db_fetch_all(
            "SELECT e.id, e.name, e.serial_number, e.asset_tag, e.equipment_type_id,
                    t.name AS type_name, t.icon AS type_icon
             FROM `{$prefix}newui_equipment` e
             LEFT JOIN `{$prefix}newui_equipment_types` t ON t.id = e.equipment_type_id
             WHERE (e.available_for_events = 1)
               AND e.id NOT IN (
                   SELECT equipment_id FROM `{$prefix}equipment_assignments` WHERE returned_at IS NULL
               )
             ORDER BY t.name, e.name, e.id
             LIMIT 500"
        );
    } catch (Throwable $e) { $rows = []; }
    $out = [];
    foreach ($rows as $e) {
        $out[] = ['id' => (int) $e['id'], 'label' => _eq_label($e),
                  'type_name' => (string) ($e['type_name'] ?? ''),
                  'type_icon' => $e['type_icon'] !== null ? (int) $e['type_icon'] : null];
    }
    json_response(['cache' => $out]);
}

// ── GET: gear issued to a member ─────────────────────────────────────────────
if ($method === 'GET' && $action === 'for_member') {
    $memberId = (int) ($_GET['member_id'] ?? 0);
    if ($memberId <= 0) json_error('member_id required');
    $out = [];
    try {
        $rows = db_fetch_all(
            "SELECT a.id AS assignment_id, a.issued_at, e.id AS equipment_id,
                    e.name, e.serial_number, e.asset_tag,
                    t.name AS type_name, t.icon AS type_icon
             FROM `{$prefix}equipment_assignments` a
             JOIN `{$prefix}newui_equipment` e ON e.id = a.equipment_id
             LEFT JOIN `{$prefix}newui_equipment_types` t ON t.id = e.equipment_type_id
             WHERE a.member_id = ? AND a.returned_at IS NULL
             ORDER BY a.issued_at DESC",
            [$memberId]
        );
        foreach ($rows as $e) {
            $out[] = ['assignment_id' => (int) $e['assignment_id'], 'equipment_id' => (int) $e['equipment_id'],
                      'label' => _eq_label($e), 'type_name' => (string) ($e['type_name'] ?? ''),
                      'type_icon' => $e['type_icon'] !== null ? (int) $e['type_icon'] : null,
                      'issued_at' => (string) $e['issued_at']];
        }
    } catch (Throwable $e) { $out = []; }
    json_response(['member_id' => $memberId, 'equipment' => $out]);
}

// ── POST: issue ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'issue') {
    if (!$canIssue) json_error('Insufficient permissions: issue equipment', 403);
    if (!csrf_verify($input['csrf_token'] ?? '')) json_error('Invalid CSRF token', 403);
    $equipmentId = (int) ($input['equipment_id'] ?? 0);
    $memberId    = (int) ($input['member_id'] ?? 0);
    if ($equipmentId <= 0 || $memberId <= 0) json_error('equipment_id and member_id required');

    try {
        // Reject double-issue: is this item already out?
        $open = db_fetch_one(
            "SELECT id, member_id FROM `{$prefix}equipment_assignments`
             WHERE equipment_id = ? AND returned_at IS NULL LIMIT 1", [$equipmentId]);
        if ($open) {
            json_error('That item is already issued to ' . _eq_member_name((int) $open['member_id']), 409);
        }
        db_query(
            "INSERT INTO `{$prefix}equipment_assignments` (equipment_id, member_id, issued_by, issued_at)
             VALUES (?, ?, ?, NOW())",
            [$equipmentId, $memberId, (int) ($_SESSION['user_id'] ?? 0)]
        );
        $assignmentId = (int) db_insert_id();
        // Quick "current holder" pointer for the equipment page (best-effort).
        try { db_query("UPDATE `{$prefix}newui_equipment` SET assigned_member_id = ? WHERE id = ?", [$memberId, $equipmentId]); }
        catch (Throwable $e) { /* column optional */ }
        audit_log('equipment', 'issue', 'equipment', $equipmentId,
            'Issued equipment #' . $equipmentId . ' to ' . _eq_member_name($memberId),
            ['member_id' => $memberId, 'assignment_id' => $assignmentId]);
        json_response(['ok' => true, 'assignment_id' => $assignmentId]);
    } catch (Throwable $e) {
        json_error_safe('Issue failed. Check server logs.', $e, 'equipment.issue');
    }
}

// ── POST: return ─────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'return') {
    if (!$canIssue) json_error('Insufficient permissions: return equipment', 403);
    if (!csrf_verify($input['csrf_token'] ?? '')) json_error('Invalid CSRF token', 403);
    $assignmentId = (int) ($input['assignment_id'] ?? 0);
    $equipmentId  = (int) ($input['equipment_id'] ?? 0);
    if ($assignmentId <= 0 && $equipmentId <= 0) json_error('assignment_id or equipment_id required');

    try {
        $row = $assignmentId > 0
            ? db_fetch_one("SELECT id, equipment_id, member_id FROM `{$prefix}equipment_assignments` WHERE id = ? AND returned_at IS NULL", [$assignmentId])
            : db_fetch_one("SELECT id, equipment_id, member_id FROM `{$prefix}equipment_assignments` WHERE equipment_id = ? AND returned_at IS NULL ORDER BY issued_at DESC LIMIT 1", [$equipmentId]);
        if (!$row) json_error('No open checkout found for that item', 404);

        db_query(
            "UPDATE `{$prefix}equipment_assignments` SET returned_at = NOW(), returned_by = ? WHERE id = ?",
            [(int) ($_SESSION['user_id'] ?? 0), (int) $row['id']]
        );
        try { db_query("UPDATE `{$prefix}newui_equipment` SET assigned_member_id = NULL WHERE id = ?", [(int) $row['equipment_id']]); }
        catch (Throwable $e) { /* optional */ }
        audit_log('equipment', 'return', 'equipment', (int) $row['equipment_id'],
            'Returned equipment #' . (int) $row['equipment_id'] . ' from ' . _eq_member_name((int) $row['member_id']),
            ['member_id' => (int) $row['member_id'], 'assignment_id' => (int) $row['id']]);
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        json_error_safe('Return failed. Check server logs.', $e, 'equipment.return');
    }
}

json_error('Unknown action', 400);
