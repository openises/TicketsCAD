<?php
/**
 * NewUI v4.0 API - ICS Positions Reference
 *
 * GET  /api/ics-positions.php       — List all ICS positions
 * GET  /api/ics-positions.php?id=X  — Single position with qualified members
 * POST /api/ics-positions.php       — Create or update position
 * POST action=delete                — Delete position
 * POST action=add_qualification     — Add member qualification
 * POST action=remove_qualification  — Remove member qualification
 * POST action=update_qualification  — Update qualification/PTB status
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

function safe_fetch_ics($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_ics] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

if ($method === 'GET') {
    handleGet();
} elseif ($method === 'POST') {
    handlePost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

function handleGet() {
    // Single position with qualified members
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $pos = safe_fetch_ics(
            "SELECT * FROM " . db_table('ics_positions') . " WHERE id = ?",
            [$id]
        );
        if (empty($pos)) json_error('Position not found', 404);

        // Members qualified for this position
        $qualified = safe_fetch_ics(
            "SELECT miq.*, m.field1 AS last_name, m.field2 AS first_name,
                    m.field26 AS callsign
             FROM " . db_table('member_ics_qualifications') . " miq
             JOIN " . db_table('member') . " m ON miq.member_id = m.id
             WHERE miq.ics_position_id = ?
             ORDER BY miq.qualification_level DESC, m.field1",
            [$id]
        );

        json_response([
            'position'  => $pos[0],
            'qualified' => $qualified
        ]);
    }

    // List all positions grouped by category
    // Join to member table to exclude orphaned qualification records
    $positions = safe_fetch_ics(
        "SELECT ip.*,
                (SELECT COUNT(DISTINCT miq.member_id)
                 FROM " . db_table('member_ics_qualifications') . " miq
                 INNER JOIN " . db_table('member') . " m ON miq.member_id = m.id
                 WHERE miq.ics_position_id = ip.id) AS qualified_count
         FROM " . db_table('ics_positions') . " ip
         ORDER BY ip.sort_order, ip.code"
    );

    json_response(['positions' => $positions]);
}

function handlePost() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // RBAC + CSRF enforcement (specs/rbac-enforcement-2026-06).
    // Writes require action.manage_teams; reads (GET) stay open to viewers.
    if (!rbac_can('action.manage_teams')) {
        json_error('Insufficient permissions: manage teams', 403);
    }
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    // ── Delete position ──
    if ($action === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            $pos = db_fetch_one("SELECT code, title FROM " . db_table('ics_positions') . " WHERE id = ?", [$id]);
            db_query("DELETE FROM " . db_table('member_ics_qualifications') . " WHERE ics_position_id = ?", [$id]);
            db_query("DELETE FROM " . db_table('ics_positions') . " WHERE id = ?", [$id]);
            audit_log('config', 'delete', 'ics_position', $id, "Deleted ICS position '" . ($pos['code'] ?? '') . " - " . ($pos['title'] ?? '') . "'");
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Add member qualification ──
    if ($action === 'add_qualification') {
        $memberId = intval($input['member_id'] ?? 0);
        $posId = intval($input['ics_position_id'] ?? 0);
        if (!$memberId || !$posId) json_error('Missing member_id or ics_position_id');

        try {
            db_query(
                "INSERT INTO " . db_table('member_ics_qualifications') . "
                 (member_id, ics_position_id, qualification_level, ptb_status,
                  ptb_start_date, evaluator, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                  qualification_level = VALUES(qualification_level),
                  ptb_status = VALUES(ptb_status),
                  updated_at = NOW()",
                [
                    $memberId, $posId,
                    $input['qualification_level'] ?? 'Trainee',
                    $input['ptb_status'] ?? 'Not Started',
                    !empty($input['ptb_start_date']) ? $input['ptb_start_date'] : null,
                    trim($input['evaluator'] ?? '') ?: null,
                    trim($input['notes'] ?? '') ?: null
                ]
            );
            audit_log('personnel', 'assign', 'ics_qualification', null, "Added ICS qualification for member #{$memberId} to position #{$posId}", [
                'member_id' => $memberId,
                'ics_position_id' => $posId,
                'qualification_level' => $input['qualification_level'] ?? 'Trainee',
                'ptb_status' => $input['ptb_status'] ?? 'Not Started'
            ]);
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Remove qualification ──
    if ($action === 'remove_qualification') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            $qual = safe_fetch_ics("SELECT member_id, ics_position_id FROM " . db_table('member_ics_qualifications') . " WHERE id = ?", [$id]);
            db_query("DELETE FROM " . db_table('member_ics_qualifications') . " WHERE id = ?", [$id]);
            audit_log('personnel', 'unassign', 'ics_qualification', $id, "Removed ICS qualification #{$id}", [
                'member_id' => !empty($qual) ? $qual[0]['member_id'] : null,
                'ics_position_id' => !empty($qual) ? $qual[0]['ics_position_id'] : null
            ]);
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Update qualification / PTB status ──
    if ($action === 'update_qualification') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');

        try {
            db_query(
                "UPDATE " . db_table('member_ics_qualifications') . "
                 SET qualification_level = ?, ptb_status = ?,
                     ptb_start_date = ?, ptb_completion_date = ?,
                     evaluator = ?, notes = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $input['qualification_level'] ?? 'Trainee',
                    $input['ptb_status'] ?? 'Not Started',
                    !empty($input['ptb_start_date']) ? $input['ptb_start_date'] : null,
                    !empty($input['ptb_completion_date']) ? $input['ptb_completion_date'] : null,
                    trim($input['evaluator'] ?? '') ?: null,
                    trim($input['notes'] ?? '') ?: null,
                    $id
                ]
            );
            audit_log('personnel', 'update', 'ics_qualification', $id, "Updated ICS qualification #{$id}", [
                'qualification_level' => $input['qualification_level'] ?? 'Trainee',
                'ptb_status' => $input['ptb_status'] ?? 'Not Started'
            ]);
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Create or update ICS position ──
    $code = strtoupper(trim($input['code'] ?? ''));
    $title = trim($input['title'] ?? '');
    if (!$code || !$title) json_error('Code and title are required');

    $id = intval($input['id'] ?? 0);

    try {
        if ($id > 0) {
            db_query(
                "UPDATE " . db_table('ics_positions') . "
                 SET code = ?, title = ?, category = ?, description = ?,
                     nims_typing_level = ?, required_certs = ?, sort_order = ?, active = ?
                 WHERE id = ?",
                [
                    $code, $title,
                    trim($input['category'] ?? '') ?: null,
                    trim($input['description'] ?? '') ?: null,
                    !empty($input['nims_typing_level']) ? intval($input['nims_typing_level']) : null,
                    !empty($input['required_certs']) ? json_encode($input['required_certs']) : null,
                    intval($input['sort_order'] ?? 0),
                    isset($input['active']) ? intval($input['active']) : 1,
                    $id
                ]
            );
            audit_log('config', 'update', 'ics_position', $id, "Updated ICS position '{$code} - {$title}'", [
                'code' => $code,
                'category' => trim($input['category'] ?? '') ?: null
            ]);
        } else {
            db_query(
                "INSERT INTO " . db_table('ics_positions') . "
                 (code, title, category, description, nims_typing_level, required_certs, sort_order, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $code, $title,
                    trim($input['category'] ?? '') ?: null,
                    trim($input['description'] ?? '') ?: null,
                    !empty($input['nims_typing_level']) ? intval($input['nims_typing_level']) : null,
                    !empty($input['required_certs']) ? json_encode($input['required_certs']) : null,
                    intval($input['sort_order'] ?? 0),
                    isset($input['active']) ? intval($input['active']) : 1
                ]
            );
            $id = db_insert_id();
            audit_log('config', 'create', 'ics_position', $id, "Created ICS position '{$code} - {$title}'", [
                'code' => $code,
                'category' => trim($input['category'] ?? '') ?: null
            ]);
        }
    } catch (Exception $e) {
        json_error('Failed to save: ' . $e->getMessage());
    }

    json_response(['success' => true, 'id' => $id]);
}
