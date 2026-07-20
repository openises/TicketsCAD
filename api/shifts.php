<?php
/**
 * NewUI v4.0 API - Shift Templates & Configuration
 *
 * GET  /api/shifts.php                    — List all templates with roles & slots
 * GET  /api/shifts.php?id=X              — Single template with full detail
 * GET  /api/shifts.php?schedule=1&template_id=X&start=YYYY-MM-DD&end=YYYY-MM-DD
 *                                         — Get schedule grid (slots + assignments) for date range
 * POST /api/shifts.php action=save_template  — Create/update template
 * POST /api/shifts.php action=delete_template — Delete template
 * POST /api/shifts.php action=save_role      — Create/update role
 * POST /api/shifts.php action=delete_role    — Delete role
 * POST /api/shifts.php action=save_slot      — Create/update slot
 * POST /api/shifts.php action=delete_slot    — Delete slot
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

function safe_fetch_shifts($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_shifts] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

if ($method === 'GET') {
    handleShiftGet();
} elseif ($method === 'POST') {
    handleShiftPost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

function handleShiftGet() {
    // Schedule grid for date range
    if (!empty($_GET['schedule'])) {
        return getScheduleGrid();
    }

    // Single template
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $tmpl = safe_fetch_shifts(
            "SELECT * FROM " . db_table('newui_shift_templates') . " WHERE id = ?",
            [$id]
        );
        if (empty($tmpl)) json_error('Template not found', 404);

        $roles = safe_fetch_shifts(
            "SELECT * FROM " . db_table('newui_shift_roles') . " WHERE template_id = ? ORDER BY sort_order",
            [$id]
        );
        $slots = safe_fetch_shifts(
            "SELECT * FROM " . db_table('newui_shift_slots') . " WHERE template_id = ? ORDER BY week_number, day_of_week, start_time",
            [$id]
        );

        json_response([
            'template' => $tmpl[0],
            'roles'    => $roles,
            'slots'    => $slots,
        ]);
    }

    // List all templates
    $templates = safe_fetch_shifts(
        "SELECT t.*,
                (SELECT COUNT(*) FROM " . db_table('newui_shift_roles') . " WHERE template_id = t.id) AS role_count,
                (SELECT COUNT(*) FROM " . db_table('newui_shift_slots') . " WHERE template_id = t.id) AS slot_count
         FROM " . db_table('newui_shift_templates') . " t
         ORDER BY t.active DESC, t.name"
    );

    json_response(['templates' => $templates]);
}

function getScheduleGrid() {
    $templateId = intval($_GET['template_id'] ?? 0);
    if (!$templateId) json_error('Missing template_id');

    $start = $_GET['start'] ?? date('Y-m-d');
    $end   = $_GET['end'] ?? date('Y-m-d', strtotime('+7 days'));

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        json_error('Invalid date format (use YYYY-MM-DD)');
    }

    // Get template info
    $tmpl = safe_fetch_shifts(
        "SELECT * FROM " . db_table('newui_shift_templates') . " WHERE id = ?",
        [$templateId]
    );
    if (empty($tmpl)) json_error('Template not found', 404);

    // Get slots
    $slots = safe_fetch_shifts(
        "SELECT * FROM " . db_table('newui_shift_slots') . " WHERE template_id = ? ORDER BY week_number, day_of_week, start_time",
        [$templateId]
    );

    // Get roles
    $roles = safe_fetch_shifts(
        "SELECT * FROM " . db_table('newui_shift_roles') . " WHERE template_id = ? ORDER BY sort_order",
        [$templateId]
    );

    // Get assignments for date range
    $assignments = safe_fetch_shifts(
        "SELECT sa.*, CONCAT(m.first_name, ' ', m.last_name) AS member_name, m.callsign AS member_callsign,
                sr.role_name
         FROM " . db_table('newui_shift_assignments') . " sa
         LEFT JOIN " . db_table('member') . " m ON sa.member_id = m.id
         LEFT JOIN " . db_table('newui_shift_roles') . " sr ON sa.role_id = sr.id
         WHERE sa.slot_id IN (SELECT id FROM " . db_table('newui_shift_slots') . " WHERE template_id = ?)
           AND sa.assignment_date BETWEEN ? AND ?
         ORDER BY sa.assignment_date, sa.slot_id",
        [$templateId, $start, $end]
    );

    // Get members for assignment dropdowns
    $members = safe_fetch_shifts(
        "SELECT id, first_name, last_name, callsign FROM " . db_table('member') . " ORDER BY last_name, first_name"
    );

    json_response([
        'template'    => $tmpl[0],
        'slots'       => $slots,
        'roles'       => $roles,
        'assignments' => $assignments,
        'members'     => $members,
        'start'       => $start,
        'end'         => $end,
    ]);
}

function handleShiftPost() {
    global $current_user_id;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // RBAC + CSRF enforcement (specs/rbac-enforcement-2026-06).
    // Writes require action.manage_schedule; reads (GET) stay open to viewers.
    if (!rbac_can('action.manage_schedule')) {
        json_error('Insufficient permissions: manage schedule', 403);
    }
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    // ── Template CRUD ──
    if ($action === 'save_template') {
        $name = trim($input['name'] ?? '');
        if (!$name) json_error('Template name is required');
        $id = intval($input['id'] ?? 0);

        $fields = [
            'name'           => $name,
            'description'    => trim($input['description'] ?? ''),
            'rotation_weeks' => max(1, intval($input['rotation_weeks'] ?? 1)),
            'timezone'       => trim($input['timezone'] ?? 'America/Chicago'),
            'active'         => !empty($input['active']) ? 1 : 0,
        ];

        try {
            if ($id > 0) {
                $fields['updated_at'] = date('Y-m-d H:i:s');
                $setParts = [];
                $params = [];
                foreach ($fields as $col => $val) {
                    $setParts[] = "`{$col}` = ?";
                    $params[] = $val;
                }
                $params[] = $id;
                db_query("UPDATE " . db_table('newui_shift_templates') . " SET " . implode(', ', $setParts) . " WHERE id = ?", $params);
            } else {
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('newui_shift_templates') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                    array_values($fields)
                );
                $id = db_insert_id();
            }
        } catch (Exception $e) {
            json_error('Failed to save template: ' . $e->getMessage());
        }
        audit_log('config', $id > 0 ? 'update' : 'create', 'shift_template', $id, ($id > 0 ? 'Updated' : 'Created') . " shift template '{$name}'", [
            'rotation_weeks' => $fields['rotation_weeks'],
            'active' => $fields['active']
        ]);
        json_response(['success' => true, 'id' => $id]);
    }

    if ($action === 'delete_template') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM " . db_table('newui_shift_templates') . " WHERE id = ?", [$id]);
        } catch (Exception $e) {
            json_error('Failed to delete: ' . $e->getMessage());
        }
        audit_log('config', 'delete', 'shift_template', $id, "Deleted shift template #{$id}");
        json_response(['success' => true]);
    }

    // ── Role CRUD ──
    if ($action === 'save_role') {
        $templateId = intval($input['template_id'] ?? 0);
        $roleName = trim($input['role_name'] ?? '');
        if (!$templateId || !$roleName) json_error('Template ID and role name required');
        $id = intval($input['id'] ?? 0);

        $fields = [
            'template_id'             => $templateId,
            'role_name'               => $roleName,
            'description'             => trim($input['description'] ?? ''),
            'min_slots'               => max(0, intval($input['min_slots'] ?? 1)),
            'max_slots'               => max(1, intval($input['max_slots'] ?? 1)),
            'required_cert_ids'       => !empty($input['required_cert_ids']) ? json_encode($input['required_cert_ids']) : null,
            'required_ics_position_id' => !empty($input['required_ics_position_id']) ? intval($input['required_ics_position_id']) : null,
            'sort_order'              => intval($input['sort_order'] ?? 0),
        ];

        try {
            if ($id > 0) {
                $setParts = [];
                $params = [];
                foreach ($fields as $col => $val) {
                    $setParts[] = "`{$col}` = ?";
                    $params[] = $val;
                }
                $params[] = $id;
                db_query("UPDATE " . db_table('newui_shift_roles') . " SET " . implode(', ', $setParts) . " WHERE id = ?", $params);
            } else {
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('newui_shift_roles') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                    array_values($fields)
                );
                $id = db_insert_id();
            }
        } catch (Exception $e) {
            json_error('Failed to save role: ' . $e->getMessage());
        }
        audit_log('config', $id > 0 ? 'update' : 'create', 'shift_role', $id, ($id > 0 ? 'Updated' : 'Created') . " shift role '{$roleName}'", [
            'template_id' => $templateId,
            'min_slots' => $fields['min_slots'],
            'max_slots' => $fields['max_slots']
        ]);
        json_response(['success' => true, 'id' => $id]);
    }

    if ($action === 'delete_role') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM " . db_table('newui_shift_roles') . " WHERE id = ?", [$id]);
        } catch (Exception $e) {
            json_error('Failed to delete role: ' . $e->getMessage());
        }
        audit_log('config', 'delete', 'shift_role', $id, "Deleted shift role #{$id}");
        json_response(['success' => true]);
    }

    // ── Slot CRUD ──
    if ($action === 'save_slot') {
        $templateId = intval($input['template_id'] ?? 0);
        if (!$templateId) json_error('Template ID required');
        $id = intval($input['id'] ?? 0);

        $fields = [
            'template_id' => $templateId,
            'day_of_week' => intval($input['day_of_week'] ?? 0),
            'start_time'  => $input['start_time'] ?? '06:00:00',
            'end_time'    => $input['end_time'] ?? '14:00:00',
            'week_number' => max(1, intval($input['week_number'] ?? 1)),
            'label'       => trim($input['label'] ?? '') ?: null,
        ];

        try {
            if ($id > 0) {
                $setParts = [];
                $params = [];
                foreach ($fields as $col => $val) {
                    $setParts[] = "`{$col}` = ?";
                    $params[] = $val;
                }
                $params[] = $id;
                db_query("UPDATE " . db_table('newui_shift_slots') . " SET " . implode(', ', $setParts) . " WHERE id = ?", $params);
            } else {
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('newui_shift_slots') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                    array_values($fields)
                );
                $id = db_insert_id();
            }
        } catch (Exception $e) {
            json_error('Failed to save slot: ' . $e->getMessage());
        }
        audit_log('config', $id > 0 ? 'update' : 'create', 'shift_slot', $id, ($id > 0 ? 'Updated' : 'Created') . " shift slot", [
            'template_id' => $templateId,
            'day_of_week' => $fields['day_of_week'],
            'start_time' => $fields['start_time'],
            'end_time' => $fields['end_time'],
            'week_number' => $fields['week_number']
        ]);
        json_response(['success' => true, 'id' => $id]);
    }

    if ($action === 'delete_slot') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM " . db_table('newui_shift_slots') . " WHERE id = ?", [$id]);
        } catch (Exception $e) {
            json_error('Failed to delete slot: ' . $e->getMessage());
        }
        audit_log('config', 'delete', 'shift_slot', $id, "Deleted shift slot #{$id}");
        json_response(['success' => true]);
    }

    json_error('Unknown action: ' . $action);
}
