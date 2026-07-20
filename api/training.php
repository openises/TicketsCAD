<?php
/**
 * NewUI v4.0 API - Training Records
 *
 * GET  /api/training.php               — List all training records (optional ?member_id=X filter)
 * GET  /api/training.php?id=X          — Get single training record
 * GET  /api/training.php?member_id=X   — Get all training for a member
 * GET  /api/training.php?summary=1     — Get training summary stats
 * POST /api/training.php               — Create or update training record
 * POST /api/training.php action=delete — Delete training record
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/access.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

function safe_fetch_all_t($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_all_t] silent SQL failure: " . $e->getMessage()
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
    // Single record
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        $row = safe_fetch_all_t(
            "SELECT tr.*, CONCAT(m.first_name, ' ', m.last_name) AS member_name, m.callsign
             FROM " . db_table('training_records') . " tr
             LEFT JOIN " . db_table('member') . " m ON tr.member_id = m.id
             WHERE tr.id = ?",
            [$id]
        );
        if (empty($row)) json_error('Training record not found', 404);
        json_response(['record' => $row[0]]);
    }

    // Summary stats
    if (!empty($_GET['summary'])) {
        $totalRecords = safe_fetch_all_t(
            "SELECT COUNT(*) AS cnt FROM " . db_table('training_records')
        );
        $byType = safe_fetch_all_t(
            "SELECT training_type, COUNT(*) AS cnt, SUM(hours) AS total_hours
             FROM " . db_table('training_records') . "
             GROUP BY training_type
             ORDER BY cnt DESC"
        );
        $byResult = safe_fetch_all_t(
            "SELECT result, COUNT(*) AS cnt
             FROM " . db_table('training_records') . "
             GROUP BY result"
        );
        $recentActivity = safe_fetch_all_t(
            "SELECT tr.*, CONCAT(m.first_name, ' ', m.last_name) AS member_name
             FROM " . db_table('training_records') . " tr
             LEFT JOIN " . db_table('member') . " m ON tr.member_id = m.id
             ORDER BY tr.training_date DESC
             LIMIT 10"
        );
        json_response([
            'total_records' => !empty($totalRecords) ? (int)$totalRecords[0]['cnt'] : 0,
            'by_type'       => $byType,
            'by_result'     => $byResult,
            'recent'        => $recentActivity
        ]);
    }

    // Records for a specific member
    if (!empty($_GET['member_id'])) {
        $memberId = intval($_GET['member_id']);

        // IDOR — explicit access check so a future tightening of the
        // member-visibility rule (self-or-admin) takes effect here without
        // re-touching this endpoint. Today user_can_access_entity('member')
        // returns true for any auth user; org-wide visibility is the
        // intended posture for training records.
        if (!user_can_access_entity('member', $memberId)) {
            json_error('Member not found', 404);
        }

        $rows = safe_fetch_all_t(
            "SELECT tr.*
             FROM " . db_table('training_records') . " tr
             WHERE tr.member_id = ?
             ORDER BY tr.training_date DESC",
            [$memberId]
        );

        // Also return training type counts for this member
        $typeCounts = safe_fetch_all_t(
            "SELECT training_type, COUNT(*) AS cnt, SUM(hours) AS total_hours
             FROM " . db_table('training_records') . "
             WHERE member_id = ?
             GROUP BY training_type",
            [$memberId]
        );

        // Total hours
        $totalHours = safe_fetch_all_t(
            "SELECT COALESCE(SUM(hours), 0) AS total
             FROM " . db_table('training_records') . "
             WHERE member_id = ?",
            [$memberId]
        );

        json_response([
            'records'     => $rows,
            'type_counts' => $typeCounts,
            'total_hours' => !empty($totalHours) ? (float)$totalHours[0]['total'] : 0
        ]);
    }

    // List all with optional filters
    $where = [];
    $params = [];

    if (!empty($_GET['type'])) {
        $where[] = "tr.training_type = ?";
        $params[] = $_GET['type'];
    }
    if (!empty($_GET['result'])) {
        $where[] = "tr.result = ?";
        $params[] = $_GET['result'];
    }
    if (!empty($_GET['search'])) {
        $term = '%' . trim($_GET['search']) . '%';
        $where[] = "(tr.training_name LIKE ? OR tr.instructor LIKE ? OR tr.fema_course_code LIKE ?
                     OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.callsign LIKE ?)";
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
    }

    $whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $rows = safe_fetch_all_t(
        "SELECT tr.*, CONCAT(m.first_name, ' ', m.last_name) AS member_name, m.callsign
         FROM " . db_table('training_records') . " tr
         LEFT JOIN " . db_table('member') . " m ON tr.member_id = m.id
         {$whereSQL}
         ORDER BY tr.training_date DESC
         LIMIT 500",
        $params
    );

    // Training types for filter dropdowns
    $types = ['Course', 'Drill', 'Exercise', 'Workshop', 'OJT', 'Webinar', 'Self-Study'];

    json_response([
        'records'        => $rows,
        'training_types' => $types
    ]);
}

function handlePost() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // RBAC enforcement (specs/rbac-enforcement-2026-06).
    // Writes require action.manage_members; reads (GET) stay open to viewers.
    // NOTE: CSRF intentionally NOT added here — the roster.js caller does not
    // send a token and the spec excludes roster.js from caller updates. Add a
    // CSRF check only when the caller is updated to send window.CSRF_TOKEN.
    if (!rbac_can('action.manage_members')) {
        json_error('Insufficient permissions: manage members', 403);
    }

    // Delete
    if (($input['action'] ?? '') === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            $tr = safe_fetch_all_t("SELECT training_name, member_id FROM " . db_table('training_records') . " WHERE id = ?", [$id]);
            db_query("DELETE FROM " . db_table('training_records') . " WHERE id = ?", [$id]);
            audit_log('personnel', 'delete', 'training_record', $id, "Deleted training record '" . (!empty($tr) ? $tr[0]['training_name'] : "#{$id}") . "'", [
                'member_id' => !empty($tr) ? $tr[0]['member_id'] : null
            ]);
        } catch (Exception $e) {
            json_error('Failed to delete: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Create or Update
    $memberId = intval($input['member_id'] ?? 0);
    $name = trim($input['training_name'] ?? '');
    if (!$memberId) json_error('Missing member_id');
    if (!$name) json_error('Training name is required');

    $fields = [
        'member_id'        => $memberId,
        'training_name'    => $name,
        'training_type'    => $input['training_type'] ?? 'Course',
        'training_date'    => !empty($input['training_date']) ? $input['training_date'] : null,
        'hours'            => !empty($input['hours']) ? floatval($input['hours']) : null,
        'location'         => trim($input['location'] ?? ''),
        'instructor'       => trim($input['instructor'] ?? ''),
        'result'           => $input['result'] ?? 'Completed',
        'fema_course_code' => trim($input['fema_course_code'] ?? '') ?: null,
        'certificate_number' => trim($input['certificate_number'] ?? '') ?: null,
        'notes'            => trim($input['notes'] ?? ''),
        'updated_at'       => date('Y-m-d H:i:s')
    ];

    $id = intval($input['id'] ?? 0);

    try {
        if ($id > 0) {
            // Update
            $setParts = [];
            $params = [];
            foreach ($fields as $col => $val) {
                $setParts[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $params[] = $id;
            db_query(
                "UPDATE " . db_table('training_records') . " SET " . implode(', ', $setParts) . " WHERE id = ?",
                $params
            );
            audit_log('personnel', 'update', 'training_record', $id, "Updated training record '{$name}'", [
                'member_id' => $memberId,
                'training_type' => $fields['training_type']
            ]);
        } else {
            // Insert
            $fields['created_at'] = date('Y-m-d H:i:s');
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            db_query(
                "INSERT INTO " . db_table('training_records') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                array_values($fields)
            );
            $id = db_insert_id();
            audit_log('personnel', 'create', 'training_record', $id, "Created training record '{$name}'", [
                'member_id' => $memberId,
                'training_type' => $fields['training_type']
            ]);
        }
    } catch (Exception $e) {
        json_error('Failed to save: ' . $e->getMessage());
    }

    json_response(['success' => true, 'id' => $id]);
}
