<?php
/**
 * NewUI v4.0 API - Personnel Config
 *
 * Manages reference tables for Personnel config panels:
 *   - certifications (certification types)
 *   - member_types
 *   - member_status
 *   - teams (read-only summary for config; full CRUD in teams.php)
 *
 * GET  ?table=certifications        — List all certification types
 * GET  ?table=member_types          — List all member types
 * GET  ?table=member_statuses       — List all member statuses
 * GET  ?table=teams_summary         — List teams with member counts
 * GET  ?table=members_summary       — Summary stats for members panel
 *
 * POST action=save_certification    — Add or update certification type
 * POST action=delete_certification  — Delete certification type
 * POST action=save_member_type      — Add or update member type
 * POST action=delete_member_type    — Delete member type
 * POST action=save_member_status    — Add or update member status
 * POST action=delete_member_status  — Delete member status
 *
 * Admin-only (level <= 1).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if (!is_admin()) {
    json_error('Admin access required', 403);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handlePersonnelGet();
} elseif ($method === 'POST') {
    handlePersonnelPost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

// ─── GET handlers ─────────────────────────────────────────────────────

function handlePersonnelGet() {
    $table = $_GET['table'] ?? '';

    if ($table === 'certifications') {
        return getCertifications();
    }
    if ($table === 'member_types') {
        return getMemberTypes();
    }
    if ($table === 'member_statuses') {
        return getMemberStatuses();
    }
    if ($table === 'teams_summary') {
        return getTeamsSummary();
    }
    if ($table === 'members_summary') {
        return getMembersSummary();
    }

    json_error('Missing or unknown table parameter');
}

function getCertifications() {
    try {
        $certs = db_fetch_all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM " . db_table('member_certifications') . " mc WHERE mc.certification_id = c.id) AS holder_count
             FROM " . db_table('certifications') . " c
             ORDER BY c.category, c.name"
        );
        json_response(['certifications' => $certs]);
    } catch (Exception $e) {
        json_error('Failed to load certifications: ' . $e->getMessage());
    }
}

function getMemberTypes() {
    try {
        // Filter by active org: show global types (org_id IS NULL) + current org types
        $orgId = isset($_SESSION['active_org_id']) ? (int) $_SESSION['active_org_id'] : null;

        $sql = "SELECT mt.*,
                    (SELECT COUNT(*) FROM " . db_table('member') . " m WHERE m.field3 = mt.id) AS member_count
             FROM " . db_table('member_types') . " mt";

        $params = [];
        // Try org-scoped query first; fall back if org_id column doesn't exist
        try {
            if ($orgId) {
                $sql .= " WHERE mt.org_id IS NULL OR mt.org_id = ?";
                $params[] = $orgId;
            }
            $sql .= " ORDER BY mt.id";
            $types = db_fetch_all($sql, $params);
        } catch (Exception $e2) {
            // org_id column may not exist yet — fall back to unfiltered
            $types = db_fetch_all(
                "SELECT mt.*,
                        (SELECT COUNT(*) FROM " . db_table('member') . " m WHERE m.field3 = mt.id) AS member_count
                 FROM " . db_table('member_types') . " mt ORDER BY mt.id"
            );
        }

        json_response(['types' => $types]);
    } catch (Exception $e) {
        json_error('Failed to load member types: ' . $e->getMessage());
    }
}

function getMemberStatuses() {
    try {
        $statuses = db_fetch_all(
            "SELECT ms.*,
                    (SELECT COUNT(*) FROM " . db_table('member') . " m WHERE m.field21 = ms.id) AS member_count
             FROM " . db_table('member_status') . " ms
             ORDER BY ms.id"
        );
        json_response(['statuses' => $statuses]);
    } catch (Exception $e) {
        json_error('Failed to load member statuses: ' . $e->getMessage());
    }
}

function getTeamsSummary() {
    try {
        // Use legacy column names with virtual aliases
        $teams = db_fetch_all(
            "SELECT t.id, t.team AS name, t.mission AS description,
                    t.nims_resource_type, t.nims_typing_level,
                    (SELECT COUNT(*) FROM " . db_table('team_members') . " tm WHERE tm.team_id = t.id) AS member_count
             FROM " . db_table('teams') . " t
             ORDER BY t.team"
        );
        json_response(['teams' => $teams]);
    } catch (Exception $e) {
        // Fallback without team_members join
        try {
            $teams = db_fetch_all(
                "SELECT t.id, t.team AS name, t.mission AS description
                 FROM " . db_table('teams') . " t
                 ORDER BY t.team"
            );
            json_response(['teams' => $teams]);
        } catch (Exception $e2) {
            json_error('Failed to load teams: ' . $e2->getMessage());
        }
    }
}

function getMembersSummary() {
    try {
        $total = db_fetch_value("SELECT COUNT(*) FROM " . db_table('member'));

        // Available count (field8 = 'Yes')
        $available = 0;
        try {
            $available = db_fetch_value("SELECT COUNT(*) FROM " . db_table('member') . " WHERE field8 = 'Yes'");
        } catch (Exception $e) {}

        // By type
        $byType = [];
        try {
            $byType = db_fetch_all(
                "SELECT mt.name, mt.color, COUNT(m.id) AS cnt
                 FROM " . db_table('member') . " m
                 LEFT JOIN " . db_table('member_types') . " mt ON m.field3 = mt.id
                 GROUP BY m.field3, mt.name, mt.color
                 ORDER BY cnt DESC"
            );
        } catch (Exception $e) {}

        // By status
        $byStatus = [];
        try {
            $byStatus = db_fetch_all(
                "SELECT ms.status_val AS name, ms.color, COUNT(m.id) AS cnt
                 FROM " . db_table('member') . " m
                 LEFT JOIN " . db_table('member_status') . " ms ON m.field21 = ms.id
                 GROUP BY m.field21, ms.status_val, ms.color
                 ORDER BY cnt DESC"
            );
        } catch (Exception $e) {}

        // Certs count
        $certCount = 0;
        try {
            $certCount = db_fetch_value("SELECT COUNT(*) FROM " . db_table('member_certifications'));
        } catch (Exception $e) {}

        // Team count
        $teamCount = 0;
        try {
            $teamCount = db_fetch_value("SELECT COUNT(*) FROM " . db_table('teams'));
        } catch (Exception $e) {}

        json_response([
            'total_members'  => (int) $total,
            'available'      => (int) $available,
            'by_type'        => $byType,
            'by_status'      => $byStatus,
            'cert_count'     => (int) $certCount,
            'team_count'     => (int) $teamCount,
        ]);
    } catch (Exception $e) {
        json_error('Failed to load member summary: ' . $e->getMessage());
    }
}

// ─── POST handlers ────────────────────────────────────────────────────

function handlePersonnelPost() {
    global $current_user_id;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'save_certification':    return saveCertification($input);
        case 'delete_certification':  return deleteCertification($input);
        case 'save_member_type':      return saveMemberType($input, $current_user_id);
        case 'delete_member_type':    return deleteMemberType($input);
        case 'save_member_status':    return saveMemberStatus($input);
        case 'delete_member_status':  return deleteMemberStatus($input);
        default: json_error('Unknown action: ' . $action);
    }
}

function saveCertification($input) {
    $id       = intval($input['id'] ?? 0);
    $name     = trim($input['name'] ?? '');
    $desc     = trim($input['description'] ?? '');
    $category = trim($input['category'] ?? '');
    $fema     = trim($input['fema_course_code'] ?? '');
    $nims     = trim($input['nims_credential_type'] ?? '');
    $required = !empty($input['required']) ? 1 : 0;
    $refresh  = !empty($input['refresh_months']) ? intval($input['refresh_months']) : null;

    if (!$name) json_error('Certification name is required');

    try {
        if ($id > 0) {
            db_query(
                "UPDATE " . db_table('certifications') . "
                 SET name = ?, description = ?, category = ?, fema_course_code = ?,
                     nims_credential_type = ?, required = ?, refresh_months = ?
                 WHERE id = ?",
                [$name, $desc ?: null, $category ?: null, $fema ?: null, $nims ?: null, $required, $refresh, $id]
            );
            audit_log('config', 'update', 'certification', $id, "Updated certification '{$name}'", ['category' => $category, 'fema_code' => $fema]);
        } else {
            db_query(
                "INSERT INTO " . db_table('certifications') . "
                 (name, description, category, fema_course_code, nims_credential_type, required, refresh_months)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$name, $desc ?: null, $category ?: null, $fema ?: null, $nims ?: null, $required, $refresh]
            );
            $id = db_insert_id();
            audit_log('config', 'create', 'certification', $id, "Created certification '{$name}'", ['category' => $category, 'fema_code' => $fema]);
        }

        json_response(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        json_error('Save failed: ' . $e->getMessage());
    }
}

function deleteCertification($input) {
    $id = intval($input['id'] ?? 0);
    if (!$id) json_error('Missing certification ID');

    try {
        // Check for member_certifications using this cert
        $count = db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('member_certifications') . " WHERE certification_id = ?", [$id]
        );
        if ($count > 0) {
            json_error('Cannot delete — ' . $count . ' member(s) hold this certification. Remove them first.');
        }

        // Get name for audit log before deleting
        $cert = db_fetch_one("SELECT name FROM " . db_table('certifications') . " WHERE id = ?", [$id]);
        db_query("DELETE FROM " . db_table('certifications') . " WHERE id = ?", [$id]);
        audit_log('config', 'delete', 'certification', $id, "Deleted certification '" . ($cert['name'] ?? $id) . "'");
        json_response(['success' => true]);
    } catch (Exception $e) {
        json_error('Delete failed: ' . $e->getMessage());
    }
}

function saveMemberType($input, $userId) {
    $id    = intval($input['id'] ?? 0);
    $name  = trim($input['name'] ?? '');
    $desc  = trim($input['description'] ?? '');
    $color = trim($input['color'] ?? '#000000');
    $bg    = trim($input['background'] ?? '#FFFFFF');

    if (!$name) json_error('Type name is required');

    try {
        if ($id > 0) {
            db_query(
                "UPDATE " . db_table('member_types') . "
                 SET name = ?, description = ?, color = ?, background = ?
                 WHERE id = ?",
                [$name, $desc ?: $name, $color, $bg, $id]
            );
            audit_log('config', 'update', 'member_type', $id, "Updated member type '{$name}'");
        } else {
            $orgId = isset($_SESSION['active_org_id']) ? (int) $_SESSION['active_org_id'] : null;
            try {
                db_query(
                    "INSERT INTO " . db_table('member_types') . "
                     (name, description, color, background, org_id, _on, _from, _by)
                     VALUES (?, ?, ?, ?, ?, NOW(), '127.0.0.1', ?)",
                    [$name, $desc ?: $name, $color, $bg, $orgId, $userId]
                );
            } catch (Exception $e2) {
                // Fallback if org_id column doesn't exist
                db_query(
                    "INSERT INTO " . db_table('member_types') . "
                     (name, description, color, background, _on, _from, _by)
                     VALUES (?, ?, ?, ?, NOW(), '127.0.0.1', ?)",
                    [$name, $desc ?: $name, $color, $bg, $userId]
                );
            }
            $id = db_insert_id();
            audit_log('config', 'create', 'member_type', $id, "Created member type '{$name}'");
        }

        json_response(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        json_error('Save failed: ' . $e->getMessage());
    }
}

function deleteMemberType($input) {
    $id = intval($input['id'] ?? 0);
    if (!$id) json_error('Missing member type ID');

    try {
        $count = db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('member') . " WHERE field3 = ?", [$id]
        );
        if ($count > 0) {
            json_error('Cannot delete — ' . $count . ' member(s) use this type. Reassign them first.');
        }

        $type = db_fetch_one("SELECT name FROM " . db_table('member_types') . " WHERE id = ?", [$id]);
        db_query("DELETE FROM " . db_table('member_types') . " WHERE id = ?", [$id]);
        audit_log('config', 'delete', 'member_type', $id, "Deleted member type '" . ($type['name'] ?? $id) . "'");
        json_response(['success' => true]);
    } catch (Exception $e) {
        json_error('Delete failed: ' . $e->getMessage());
    }
}

function saveMemberStatus($input) {
    $id     = intval($input['id'] ?? 0);
    $name   = trim($input['status_val'] ?? '');
    $desc   = trim($input['description'] ?? '');
    $color  = trim($input['color'] ?? '#000000');
    $bg     = trim($input['background'] ?? '#FFFFFF');

    if (!$name) json_error('Status name is required');

    try {
        if ($id > 0) {
            db_query(
                "UPDATE " . db_table('member_status') . "
                 SET status_val = ?, description = ?, color = ?, background = ?
                 WHERE id = ?",
                [$name, $desc ?: $name, $color, $bg, $id]
            );
            audit_log('config', 'update', 'member_status', $id, "Updated member status '{$name}'");
        } else {
            db_query(
                "INSERT INTO " . db_table('member_status') . "
                 (status_val, description, color, background)
                 VALUES (?, ?, ?, ?)",
                [$name, $desc ?: $name, $color, $bg]
            );
            $id = db_insert_id();
            audit_log('config', 'create', 'member_status', $id, "Created member status '{$name}'");
        }

        json_response(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        json_error('Save failed: ' . $e->getMessage());
    }
}

function deleteMemberStatus($input) {
    $id = intval($input['id'] ?? 0);
    if (!$id) json_error('Missing member status ID');

    try {
        $count = db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('member') . " WHERE field21 = ?", [$id]
        );
        if ($count > 0) {
            json_error('Cannot delete — ' . $count . ' member(s) use this status. Reassign them first.');
        }

        $status = db_fetch_one("SELECT status_val FROM " . db_table('member_status') . " WHERE id = ?", [$id]);
        db_query("DELETE FROM " . db_table('member_status') . " WHERE id = ?", [$id]);
        audit_log('config', 'delete', 'member_status', $id, "Deleted member status '" . ($status['status_val'] ?? $id) . "'");
        json_response(['success' => true]);
    } catch (Exception $e) {
        json_error('Delete failed: ' . $e->getMessage());
    }
}
