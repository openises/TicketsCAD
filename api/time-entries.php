<?php
/**
 * NewUI v4.0 API — Member time entries
 *
 * GET  /api/time-entries.php?member_id=N           — list entries for one member
 * GET  /api/time-entries.php?id=N                  — single entry
 * GET  /api/time-entries.php?summary=1&member_id=N — totals by activity type
 * GET  /api/time-entries.php?activity_types=1      — list of allowed activity types
 * GET  /api/time-entries.php?pending=1             — admin-only: pending approval queue
 *
 * POST action=create   { member_id, started_at, ended_at, activity_type, incident_id?, notes?, csrf_token }
 * POST action=update   { id, ...fields, csrf_token }
 * POST action=delete   { id, csrf_token }
 * POST action=approve  { id, csrf_token }   — admin only
 * POST action=reject   { id, csrf_token }   — admin only
 *
 * Access rules:
 *   - Members may CRUD their own entries while status='self_reported'.
 *   - Admins (level <= 1) may CRUD any entry and approve/reject.
 *   - Reading another member's entries requires user_can_access_entity('member',
 *     target_id), which currently allows any authenticated user (org-wide
 *     reads). Tighten via inc/access.php when policy changes.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/rbac.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Helpers ────────────────────────────────────────────────────────
function te_validate_datetime($s): ?string {
    $s = trim((string) $s);
    if ($s === '') return null;
    // Accept "Y-m-d H:i" or "Y-m-d H:i:s" or "Y-m-dTH:i" (HTML5 datetime-local)
    $s = str_replace('T', ' ', $s);
    $ts = strtotime($s);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

function te_validate_activity(string $type): bool {
    global $prefix;
    if ($type === '') return false;
    try {
        $row = db_fetch_one(
            "SELECT id FROM `{$prefix}time_activity_types` WHERE name = ? AND active = 1",
            [$type]
        );
        return !empty($row);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Decide whether a freshly-created time entry should land in
 * 'approved' state instead of 'self_reported'. Reads the global
 * rbac.time_entry_auto_approve setting:
 *
 *   off               — never auto-approve
 *   on                — every entry auto-approves (lightweight ops)
 *   by_activity_type  — auto-approve when the activity_type's row
 *                       has auto_approve = 1
 */
function te_should_auto_approve(string $activityType): bool {
    global $prefix;
    try {
        $mode = db_fetch_value(
            "SELECT value FROM `{$prefix}settings` WHERE name = 'rbac.time_entry_auto_approve'"
        );
    } catch (Throwable $e) {
        return false;
    }
    $mode = (string) ($mode ?? 'off');
    if ($mode === 'on') return true;
    if ($mode !== 'by_activity_type') return false;
    try {
        $flag = db_fetch_value(
            "SELECT auto_approve FROM `{$prefix}time_activity_types`
             WHERE name = ? AND active = 1",
            [$activityType]
        );
        return ((int) ($flag ?? 0)) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function te_member_for_user(int $userId): ?int {
    global $prefix;
    try {
        $row = db_fetch_one(
            "SELECT m.id FROM `{$prefix}member` m
             JOIN `{$prefix}user` u ON u.member = m.id
             WHERE u.id = ?",
            [$userId]
        );
        return $row ? (int) $row['id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function te_can_modify(array $entry, int $currentUserId, int $currentLevel): bool {
    // Defer to RBAC: time_entry.edit with owner_id of the entry's
    // original submitter. Roles 1..6 all hold time_entry.edit, so the
    // outcome turns on:
    //   - context: rbac_can passes owner_id; a 'self' scope grant
    //     fires only when current user submitted this entry.
    //   - status lock: once approved/rejected, only roles holding
    //     unrestricted edit (admin / dispatcher / org admin) can
    //     modify. We enforce that here because rbac_can doesn't yet
    //     model "edit only while pending".
    $ownerId = isset($entry['submitted_by']) ? (int) $entry['submitted_by'] : 0;
    $canEdit = rbac_can('time_entry.edit', $ownerId ? ['owner_id' => $ownerId] : []);
    if (!$canEdit) return false;
    if (($entry['status'] ?? '') !== 'self_reported') {
        // Approved / rejected entries: still restricted to admins.
        return $currentLevel <= 1;
    }
    return true;
}

// Phase 80d -- helper: list of suggested volunteer categories. Free-form
// so each agency can adapt wording; the API doesn't gate on these values.
function te_category_suggestions(): array {
    return [
        'training', 'drill', 'event', 'radio_net', 'meeting',
        'admin', 'public_education', 'deployment', 'response', 'other',
    ];
}

// Phase 80d -- shared SELECT clause that gracefully reads the optional
// org_id / category / rejection_reason columns added by run_phase80d_*
// without crashing on installs that haven't run the migration yet.
function te_select_list(): string {
    global $prefix;
    static $cached = null;
    if ($cached !== null) return $cached;
    $extras = [];
    foreach (['org_id', 'category', 'rejection_reason'] as $col) {
        try {
            $row = db_fetch_one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$prefix . 'member_time_entries', $col]
            );
            if (!empty($row)) $extras[] = "te.`$col`";
        } catch (Throwable $e) { /* silent */ }
    }
    $cached = $extras;
    return $cached ? ', ' . implode(', ', $extras) : '';
}

// ── GET handlers ───────────────────────────────────────────────────
if ($method === 'GET') {
    // List activity types — open to any auth user (UI dropdown)
    if (!empty($_GET['activity_types'])) {
        $rows = db_fetch_all(
            "SELECT id, name, description FROM `{$prefix}time_activity_types`
             WHERE active = 1 ORDER BY sort_order, name"
        );
        json_response([
            'activity_types' => $rows,
            'categories'     => te_category_suggestions(),
        ]);
    }

    // Phase 80d -- self summary: my hours this week / this month / this year.
    // Buckets are aligned to local PHP timezone, computed via MySQL date
    // arithmetic so the totals match what a member sees on their wall
    // calendar (Sunday-start week per MariaDB default WEEK() mode 0).
    if (!empty($_GET['summary']) && empty($_GET['member_id'])) {
        $mid = te_member_for_user($current_user_id);
        if (!$mid) {
            json_response([
                'member_id'    => null,
                'week_hours'   => 0,
                'month_hours'  => 0,
                'year_hours'   => 0,
                'pending_count'=> 0,
            ]);
        }
        $row = db_fetch_one(
            "SELECT
               COALESCE(SUM(CASE WHEN YEARWEEK(started_at,3) = YEARWEEK(NOW(),3) THEN hours END), 0) AS week_h,
               COALESCE(SUM(CASE WHEN YEAR(started_at) = YEAR(NOW())
                                   AND MONTH(started_at) = MONTH(NOW()) THEN hours END), 0) AS month_h,
               COALESCE(SUM(CASE WHEN YEAR(started_at) = YEAR(NOW()) THEN hours END), 0) AS year_h,
               COALESCE(SUM(CASE WHEN status = 'self_reported' THEN 1 ELSE 0 END), 0) AS pending_cnt
             FROM `{$prefix}member_time_entries`
             WHERE member_id = ?",
            [$mid]
        );
        json_response([
            'member_id'     => $mid,
            'week_hours'    => round((float) ($row['week_h']  ?? 0), 2),
            'month_hours'   => round((float) ($row['month_h'] ?? 0), 2),
            'year_hours'    => round((float) ($row['year_h']  ?? 0), 2),
            'pending_count' => (int)   ($row['pending_cnt'] ?? 0),
        ]);
    }

    // Phase 80d -- recent self entries (default: last 90 days, grouped
    // by year-month for the dedicated page's left panel).
    if (!empty($_GET['recent'])) {
        $mid = te_member_for_user($current_user_id);
        if (!$mid) json_response(['member_id' => null, 'entries' => []]);
        $sel = te_select_list();
        $entries = db_fetch_all(
            "SELECT te.id, te.member_id, te.started_at, te.ended_at,
                    te.activity_type, te.incident_id, te.notes, te.status,
                    te.submitted_by, te.approved_by, te.approved_at,
                    te.hours, te.created_at $sel
             FROM `{$prefix}member_time_entries` te
             WHERE te.member_id = ?
               AND te.started_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             ORDER BY te.started_at DESC
             LIMIT 500",
            [$mid]
        );
        json_response(['member_id' => $mid, 'entries' => $entries]);
    }

    // Phase 80d -- org-wide listing for approvers / reporting.
    if (!empty($_GET['all'])) {
        if (!rbac_can('time_entry.approve')) {
            json_error('Approve permission required', 403);
        }
        $sel = te_select_list();
        $rows = db_fetch_all(
            "SELECT te.id, te.member_id, te.started_at, te.ended_at,
                    te.activity_type, te.notes, te.status, te.hours,
                    te.approved_by, te.approved_at, te.created_at $sel,
                    m.field2 AS first_name, m.field1 AS last_name
             FROM `{$prefix}member_time_entries` te
             JOIN `{$prefix}member` m ON te.member_id = m.id
             ORDER BY te.started_at DESC
             LIMIT 500"
        );
        json_response(['entries' => $rows]);
    }

    // Pending-queue: visible to anyone with time_entry.approve
    if (!empty($_GET['pending'])) {
        if (!rbac_can('time_entry.approve')) {
            json_error('Approve permission required', 403);
        }
        $rows = db_fetch_all(
            "SELECT te.*, m.field2 AS first_name, m.field1 AS last_name, m.field4 AS callsign
             FROM `{$prefix}member_time_entries` te
             JOIN `{$prefix}member` m ON te.member_id = m.id
             WHERE te.status = 'self_reported'
             ORDER BY te.started_at DESC LIMIT 200"
        );
        json_response(['entries' => $rows]);
    }

    // Single entry
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $entry = db_fetch_one(
            "SELECT te.*, m.field2 AS first_name, m.field1 AS last_name
             FROM `{$prefix}member_time_entries` te
             JOIN `{$prefix}member` m ON te.member_id = m.id
             WHERE te.id = ?",
            [$id]
        );
        if (!$entry) json_error('Time entry not found', 404);
        if (!user_can_access_entity('member', (int) $entry['member_id'])) {
            json_error('Time entry not found', 404);
        }
        json_response(['entry' => $entry]);
    }

    // Per-member list
    $memberId = (int) ($_GET['member_id'] ?? 0);
    if ($memberId <= 0) json_error('member_id required (or use ?activity_types=1 / ?pending=1)');
    if (!user_can_access_entity('member', $memberId)) {
        json_error('Member not found', 404);
    }

    // Optional date range
    $where  = ['te.member_id = ?'];
    $params = [$memberId];
    if (!empty($_GET['start_date'])) {
        $where[]  = 'te.started_at >= ?';
        $params[] = $_GET['start_date'] . ' 00:00:00';
    }
    if (!empty($_GET['end_date'])) {
        $where[]  = 'te.started_at <= ?';
        $params[] = $_GET['end_date'] . ' 23:59:59';
    }
    $whereSql = implode(' AND ', $where);

    if (!empty($_GET['summary'])) {
        // Aggregated totals by activity type
        $rows = db_fetch_all(
            "SELECT te.activity_type,
                    COUNT(*) AS entry_count,
                    SUM(te.hours) AS total_hours,
                    MAX(te.started_at) AS last_logged
             FROM `{$prefix}member_time_entries` te
             WHERE $whereSql
             GROUP BY te.activity_type
             ORDER BY total_hours DESC",
            $params
        );
        $grandTotal = db_fetch_one(
            "SELECT COALESCE(SUM(hours), 0) AS total
             FROM `{$prefix}member_time_entries` te
             WHERE $whereSql",
            $params
        );
        json_response([
            'member_id'   => $memberId,
            'by_activity' => $rows,
            'total_hours' => (float) ($grandTotal['total'] ?? 0),
        ]);
    }

    // Plain list (most recent first)
    $entries = db_fetch_all(
        "SELECT te.*, t.scope AS incident_scope
         FROM `{$prefix}member_time_entries` te
         LEFT JOIN `{$prefix}ticket` t ON te.incident_id = t.id
         WHERE $whereSql
         ORDER BY te.started_at DESC
         LIMIT 500",
        $params
    );
    json_response(['member_id' => $memberId, 'entries' => $entries]);
}

// ── POST handlers ──────────────────────────────────────────────────
if ($method !== 'POST') json_error('Method not allowed', 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) json_error('Invalid JSON body');
if (!csrf_verify((string) ($input['csrf_token'] ?? ''))) {
    json_error('Invalid CSRF token', 403);
}

$action = trim((string) ($input['action'] ?? ''));

// ── action=create ──
if ($action === 'create') {
    $memberId = (int) ($input['member_id'] ?? 0);
    if ($memberId <= 0) json_error('member_id required');

    // Members can only create entries for themselves; admins can create on
    // anyone's behalf. Org-wide visibility doesn't imply write rights.
    $ownMember = te_member_for_user($current_user_id);
    if (!is_admin() && $memberId !== $ownMember) {
        json_error('You can only log time for yourself', 403);
    }

    $start = te_validate_datetime($input['started_at'] ?? '');
    $end   = te_validate_datetime($input['ended_at']   ?? '');
    if (!$start || !$end) json_error('started_at and ended_at must be valid datetimes');
    if (strtotime($end) <= strtotime($start)) {
        json_error('ended_at must be after started_at');
    }
    if (strtotime($end) - strtotime($start) > 30 * 86400) {
        json_error('Time entry too long — split into multiple entries (>30 days)');
    }

    $activity = trim((string) ($input['activity_type'] ?? ''));
    if (!te_validate_activity($activity)) {
        json_error('Invalid activity_type — see ?activity_types=1 for allowed values');
    }

    $incidentId = !empty($input['incident_id']) ? (int) $input['incident_id'] : null;
    if ($incidentId && !user_can_access_entity('incident', $incidentId)) {
        json_error('Incident not found', 404);
    }

    $notes = trim((string) ($input['notes'] ?? '')) ?: null;

    // Phase 80d -- volunteer extensions. Category is free text (no lookup
    // gate); org_id is the agency scope for rollup. Both are nullable
    // and only included when the columns exist (the migration may not
    // have run on this install yet).
    $category = trim((string) ($input['category'] ?? '')) ?: null;
    if ($category !== null && strlen($category) > 32) {
        $category = substr($category, 0, 32);
    }
    $orgId = !empty($input['org_id']) ? (int) $input['org_id'] : null;
    if ($orgId === null && !empty($_SESSION['active_org_id'])) {
        $orgId = (int) $_SESSION['active_org_id'];
    }

    // Auto-approve mode (rbac.time_entry_auto_approve setting). When
    // on, the entry skips the approval queue. Honour the
    // separate-approver setting too — if both are on we still create
    // the entry as approved but record the actor as approved_by, so
    // an admin can see who self-approved.
    $autoApprove = te_should_auto_approve($activity);
    $status      = $autoApprove ? 'approved' : 'self_reported';
    $approvedBy  = $autoApprove ? $current_user_id : null;
    $approvedAt  = $autoApprove ? date('Y-m-d H:i:s') : null;

    // Dynamically include the optional columns iff they exist (so an install
    // without the Phase 80d migration applied still accepts creates).
    $cols   = ['member_id','started_at','ended_at','activity_type','incident_id','notes',
               'status','submitted_by','approved_by','approved_at'];
    $vals   = [$memberId, $start, $end, $activity, $incidentId, $notes,
               $status, $current_user_id, $approvedBy, $approvedAt];
    foreach (['org_id' => $orgId, 'category' => $category] as $col => $v) {
        try {
            $hasCol = db_fetch_one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$prefix . 'member_time_entries', $col]
            );
            if (!empty($hasCol)) { $cols[] = $col; $vals[] = $v; }
        } catch (Throwable $e) { /* skip */ }
    }
    $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $colsSql = '`' . implode('`,`', $cols) . '`';
    db_query(
        "INSERT INTO `{$prefix}member_time_entries` ($colsSql) VALUES $placeholders",
        $vals
    );
    $newId = db_insert_id();
    audit_log('personnel', 'log_time', 'time_entry', $newId,
        "Logged time for member #{$memberId}: {$activity} ({$start} → {$end})"
        . ($autoApprove ? ' [auto-approved]' : ''));

    json_response(['success' => true, 'id' => $newId, 'auto_approved' => $autoApprove]);
}

// All other actions need the entry id
$entryId = (int) ($input['id'] ?? 0);
if ($entryId <= 0) json_error('id required');

$entry = db_fetch_one(
    "SELECT * FROM `{$prefix}member_time_entries` WHERE id = ?", [$entryId]
);
if (!$entry) json_error('Time entry not found', 404);

// ── action=update ──
if ($action === 'update') {
    if (!te_can_modify($entry, $current_user_id, $current_level)) {
        json_error('Cannot edit this entry (already approved or not yours)', 403);
    }

    $sets = [];
    $params = [];
    if (array_key_exists('started_at', $input)) {
        $v = te_validate_datetime($input['started_at']);
        if (!$v) json_error('Invalid started_at');
        $sets[] = '`started_at` = ?'; $params[] = $v;
    }
    if (array_key_exists('ended_at', $input)) {
        $v = te_validate_datetime($input['ended_at']);
        if (!$v) json_error('Invalid ended_at');
        $sets[] = '`ended_at` = ?'; $params[] = $v;
    }
    if (array_key_exists('activity_type', $input)) {
        $v = trim((string) $input['activity_type']);
        if (!te_validate_activity($v)) json_error('Invalid activity_type');
        $sets[] = '`activity_type` = ?'; $params[] = $v;
    }
    if (array_key_exists('incident_id', $input)) {
        $v = !empty($input['incident_id']) ? (int) $input['incident_id'] : null;
        if ($v && !user_can_access_entity('incident', $v)) {
            json_error('Incident not found', 404);
        }
        $sets[] = '`incident_id` = ?'; $params[] = $v;
    }
    if (array_key_exists('notes', $input)) {
        $sets[] = '`notes` = ?'; $params[] = trim((string) $input['notes']) ?: null;
    }
    // Phase 80d -- accept category + org_id when columns exist.
    foreach (['category', 'org_id'] as $col) {
        if (!array_key_exists($col, $input)) continue;
        try {
            $hasCol = db_fetch_one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$prefix . 'member_time_entries', $col]
            );
            if (empty($hasCol)) continue;
        } catch (Throwable $e) { continue; }
        if ($col === 'category') {
            $v = trim((string) $input['category']) ?: null;
            if ($v !== null && strlen($v) > 32) $v = substr($v, 0, 32);
            $sets[] = '`category` = ?'; $params[] = $v;
        } else {
            $v = !empty($input['org_id']) ? (int) $input['org_id'] : null;
            $sets[] = '`org_id` = ?'; $params[] = $v;
        }
    }
    if (empty($sets)) json_error('Nothing to update');

    $params[] = $entryId;
    db_query(
        "UPDATE `{$prefix}member_time_entries` SET " . implode(', ', $sets) . " WHERE id = ?",
        $params
    );
    audit_log('personnel', 'update', 'time_entry', $entryId, 'Updated time entry');
    json_response(['success' => true]);
}

// ── action=delete ──
if ($action === 'delete') {
    if (!te_can_modify($entry, $current_user_id, $current_level)) {
        json_error('Cannot delete this entry (already approved or not yours)', 403);
    }
    db_query("DELETE FROM `{$prefix}member_time_entries` WHERE id = ?", [$entryId]);
    audit_log('personnel', 'delete', 'time_entry', $entryId, 'Deleted time entry');
    json_response(['success' => true]);
}

// ── action=approve / action=reject — gated by RBAC ──
if ($action === 'approve' || $action === 'reject') {
    // Honour the rbac.require_separate_approver setting via context:
    // when the actor submitted this entry themselves, rbac_can() with
    // owner_id will deny if the setting is on. Off (default) lets
    // volunteer ops self-approve.
    $ownerId = isset($entry['submitted_by']) ? (int) $entry['submitted_by'] : 0;
    if (!rbac_can('time_entry.approve', $ownerId ? ['owner_id' => $ownerId] : [])) {
        json_error('Approve permission required', 403);
    }
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';

    // Phase 80d -- capture optional rejection_reason when the column exists.
    $reason = null;
    if ($action === 'reject') {
        $reason = trim((string) ($input['rejection_reason'] ?? '')) ?: null;
        if ($reason !== null && strlen($reason) > 255) {
            $reason = substr($reason, 0, 255);
        }
    }
    $hasReasonCol = false;
    try {
        $hasReasonCol = !empty(db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND COLUMN_NAME = 'rejection_reason'",
            [$prefix . 'member_time_entries']
        ));
    } catch (Throwable $e) { $hasReasonCol = false; }

    if ($hasReasonCol) {
        db_query(
            "UPDATE `{$prefix}member_time_entries`
             SET status = ?, approved_by = ?, approved_at = NOW(),
                 rejection_reason = ?
             WHERE id = ?",
            [$newStatus, $current_user_id, $reason, $entryId]
        );
    } else {
        db_query(
            "UPDATE `{$prefix}member_time_entries`
             SET status = ?, approved_by = ?, approved_at = NOW()
             WHERE id = ?",
            [$newStatus, $current_user_id, $entryId]
        );
    }
    audit_log('personnel', $action, 'time_entry', $entryId,
        ucfirst($action) . " time entry for member #{$entry['member_id']}"
        . ($reason ? " -- $reason" : ''));
    json_response([
        'success' => true,
        'status'  => $newStatus,
        'rejection_reason' => $hasReasonCol ? $reason : null,
    ]);
}

// Phase 80d -- action=submit is a no-op in the current state model: every
// entry is created as 'self_reported' which already means "submitted for
// review". We accept the action for forward-compat with UIs that expect
// a draft/submit lifecycle, audit-log the call, and return success.
if ($action === 'submit') {
    if (!te_can_modify($entry, $current_user_id, $current_level)) {
        json_error('Cannot submit this entry', 403);
    }
    audit_log('personnel', 'submit', 'time_entry', $entryId,
        "Submitted time entry for member #{$entry['member_id']}");
    json_response(['success' => true, 'status' => $entry['status']]);
}

json_error('Unknown action', 400);
