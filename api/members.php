<?php
/**
 * NewUI v4.0 API - Members (Roster Management)
 *
 * GET  /api/members.php              — List all members with type/status/team joins
 * GET  /api/members.php?id=X         — Get single member with certifications
 * GET  /api/members.php?search=X     — Search by name, callsign, phone, email
 * POST /api/members.php              — Create or update member
 * POST /api/members.php action=delete — Delete member
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/member-write.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

// Helper: safe query
function safe_fetch_all_m($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_all_m] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

/**
 * Self-healing: fix legacy NOT NULL columns that lack DEFAULT values.
 *
 * The legacy member table may have field1-field65, _by, _on, _from columns
 * defined as NOT NULL without defaults. This causes INSERT to fail in MySQL
 * strict mode when only named columns are specified. This function discovers
 * those columns and sets DEFAULT '' so inserts succeed.
 *
 * Safe to call multiple times — only alters columns that need it.
 */
/**
 * Discover VIRTUAL/STORED GENERATED columns in the member table.
 * Returns a map of generated_column_name => source_column_name, e.g.:
 *   ['first_name' => 'field2', 'last_name' => 'field1', ...]
 * This lets us redirect writes to the underlying field* columns.
 */
function getGeneratedColumnMap() {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    try {
        $cols = db_fetch_all(
            "SELECT COLUMN_NAME, GENERATION_EXPRESSION
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND GENERATION_EXPRESSION IS NOT NULL
               AND GENERATION_EXPRESSION != ''",
            [trim(db_table('member'), '` ')]
        );
        foreach ($cols as $col) {
            // GENERATION_EXPRESSION is like `field2` — strip backticks
            $source = trim($col['GENERATION_EXPRESSION'], '` ');
            if ($source) {
                $map[$col['COLUMN_NAME']] = $source;
            }
        }
    } catch (Exception $e) {
        // Can't read schema — return empty, writes will attempt the named columns
    }
    return $map;
}

/**
 * Sync the primary callsign from member_callsigns to the legacy member.callsign field.
 */
function syncPrimaryCallsign($memberId) {
    $primary = safe_fetch_all_m(
        "SELECT callsign FROM " . db_table('member_callsigns') . "
         WHERE member_id = ? AND is_primary = 1 LIMIT 1",
        [$memberId]
    );
    $cs = !empty($primary) ? $primary[0]['callsign'] : '';
    try {
        // Use remapGeneratedColumns in case callsign is generated
        $fields = remapGeneratedColumns(['callsign' => $cs]);
        $col = array_keys($fields)[0];
        $val = array_values($fields)[0];
        db_query("UPDATE " . db_table('member') . " SET `{$col}` = ? WHERE id = ?", [$val, $memberId]);
    } catch (Exception $e) {
        // Non-fatal — legacy sync is best-effort
    }
}

/**
 * Remap generated columns in a fields array to their underlying source columns.
 * E.g. if 'first_name' is a generated column from 'field2', replaces the key
 * 'first_name' => 'John' with 'field2' => 'John'.
 */
function remapGeneratedColumns($fields) {
    $genMap = getGeneratedColumnMap();
    if (empty($genMap)) return $fields;

    $remapped = [];
    foreach ($fields as $col => $val) {
        if (isset($genMap[$col])) {
            $remapped[$genMap[$col]] = $val;
        } else {
            $remapped[$col] = $val;
        }
    }
    return $remapped;
}

/**
 * Phase 99q (Billy beta 2026-06-29) — idempotently ensure the
 * columns the LIST endpoint's SELECT depends on actually exist.
 *
 * Older legacy installs may lack `deleted_at`, `title`, `join_date`,
 * `photo_file_id`. Missing any one of them makes the SELECT throw,
 * safe_fetch_all_m swallows the exception, and the Roster page
 * renders empty even when `member` rows are present (Billy's repro:
 * import succeeds, phpMyAdmin shows the data, but the page is blank).
 *
 * Each ALTER is guarded by an information_schema existence check, so
 * this is safe to call on every LIST request. Result is cached in a
 * static for the lifetime of the request so we only check once.
 */
function ensureMemberListColumns(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $table = trim(db_table('member'), '` ');
    $needed = [
        'deleted_at'    => 'DATETIME NULL DEFAULT NULL',
        'deleted_by'    => 'INT(11) NULL DEFAULT NULL',
        'title'         => 'VARCHAR(64) NULL DEFAULT NULL',
        'join_date'     => 'DATE NULL DEFAULT NULL',
        'photo_file_id' => 'INT(11) NULL DEFAULT NULL',
    ];

    try {
        $existing = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        );
        $haveCols = [];
        foreach ($existing as $c) $haveCols[$c['COLUMN_NAME']] = true;

        foreach ($needed as $col => $def) {
            if (isset($haveCols[$col])) continue;
            try {
                db_query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
                error_log("[ensureMemberListColumns] added missing column member.{$col}");
            } catch (Exception $e) {
                error_log("[ensureMemberListColumns] ADD COLUMN {$col} failed: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("[ensureMemberListColumns] schema check failed: " . $e->getMessage());
    }
}

/**
 * Self-healing: fix legacy NOT NULL columns that lack DEFAULT values.
 * Handles different data types: numeric → DEFAULT 0, datetime/date → NULL, text → DEFAULT ''.
 */
function fixLegacyDefaults() {
    $table = trim(db_table('member'), '` ');
    try {
        $cols = db_fetch_all(
            "SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND IS_NULLABLE = 'NO'
               AND COLUMN_DEFAULT IS NULL
               AND COLUMN_NAME != 'id'
               AND (COLUMN_NAME REGEXP '^field[0-9]+$'
                    OR COLUMN_NAME IN ('_by', '_on', '_from'))",
            [$table]
        );
        foreach ($cols as $col) {
            try {
                $dtype = strtolower($col['DATA_TYPE']);
                if (in_array($dtype, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'])) {
                    db_query("ALTER TABLE `{$table}` ALTER COLUMN `{$col['COLUMN_NAME']}` SET DEFAULT 0");
                } elseif (in_array($dtype, ['datetime', 'timestamp', 'date'])) {
                    db_query("ALTER TABLE `{$table}` MODIFY COLUMN `{$col['COLUMN_NAME']}` {$col['COLUMN_TYPE']} NULL DEFAULT NULL");
                } else {
                    db_query("ALTER TABLE `{$table}` ALTER COLUMN `{$col['COLUMN_NAME']}` SET DEFAULT ''");
                }
            } catch (Exception $e) {
                // ignore individual failures
            }
        }
    } catch (Exception $e) {
        // ignore — schema introspection failed, the retry will fail too
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
    // Single member
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        // Phase 99j-5 — org-scope gate before the load. 404 (not 403)
        // so we don't leak existence.
        require_once __DIR__ . '/../inc/org-scope.php';
        if (!org_can_see_member($id)) {
            json_error('Member not found', 404);
        }
        $member = safe_fetch_all_m(
            "SELECT m.*, mt.name AS type_name,
                    mt.background AS type_color, mt.color AS type_text_color,
                    ms.status_val AS status_name,
                    ms.background AS status_color, ms.color AS status_text_color,
                    t.name AS team_name
             FROM " . db_table('member') . " m
             LEFT JOIN " . db_table('member_types') . " mt ON m.member_type_id = mt.id
             LEFT JOIN " . db_table('member_status') . " ms ON m.member_status_id = ms.id
             LEFT JOIN " . db_table('teams') . " t ON m.team_id = t.id
             WHERE m.id = ?",
            [$id]
        );

        if (empty($member)) json_error('Member not found', 404);

        // Get certifications (with enhanced fields)
        $certs = safe_fetch_all_m(
            "SELECT mc.*, c.name AS cert_name, c.description AS cert_description,
                    c.required, c.refresh_months, c.category AS cert_category,
                    c.fema_course_code, c.nims_credential_type
             FROM " . db_table('member_certifications') . " mc
             JOIN " . db_table('certifications') . " c ON mc.certification_id = c.id
             WHERE mc.member_id = ?
             ORDER BY c.category, c.name",
            [$id]
        );

        // Get ICS qualifications
        $ics_quals = safe_fetch_all_m(
            "SELECT miq.*, ip.code, ip.title AS position_title, ip.category
             FROM " . db_table('member_ics_qualifications') . " miq
             JOIN " . db_table('ics_positions') . " ip ON miq.ics_position_id = ip.id
             WHERE miq.member_id = ?
             ORDER BY ip.sort_order, ip.code",
            [$id]
        );

        // Get all ICS positions for dropdown
        $all_ics = safe_fetch_all_m(
            "SELECT id, code, title, category FROM " . db_table('ics_positions') . "
             WHERE active = 1 ORDER BY sort_order, code"
        );

        // Get team memberships
        $team_memberships = safe_fetch_all_m(
            "SELECT tm.*, t.`team` AS team_name
             FROM " . db_table('team_members') . " tm
             JOIN " . db_table('teams') . " t ON tm.team_id = t.id
             ORDER BY t.`team`",
            []
        );
        // Filter to this member
        $member_teams = array_values(array_filter($team_memberships, function($tm) use ($id) {
            return (int)$tm['member_id'] === $id;
        }));

        // Get training records
        $training = safe_fetch_all_m(
            "SELECT * FROM " . db_table('training_records') . "
             WHERE member_id = ?
             ORDER BY training_date DESC",
            [$id]
        );

        // Training stats
        $training_hours = safe_fetch_all_m(
            "SELECT COALESCE(SUM(hours), 0) AS total
             FROM " . db_table('training_records') . "
             WHERE member_id = ?",
            [$id]
        );

        // Get organization memberships
        $member_orgs = safe_fetch_all_m(
            "SELECT mo.*, o.name AS org_name, o.short_name, o.org_type,
                    mt.name AS type_name,
                    mt.background AS type_color, mt.color AS type_text_color
             FROM " . db_table('member_organizations') . " mo
             JOIN " . db_table('organizations') . " o ON mo.org_id = o.id
             LEFT JOIN " . db_table('member_types') . " mt ON mo.member_type_id = mt.id
             WHERE mo.member_id = ? AND o.active = 1
             ORDER BY o.sort_order, o.name",
            [$id]
        );

        // Get communication identifiers.
        // 2026-06-14 (Phase 48b) — order by the per-identifier sort_order
        // set by the reorder_identifier action (api/comm-identifiers.php),
        // not by the per-mode sort_order. Phase 48 added the column and
        // updated comm-identifiers.php's own query, but selectMember()
        // on the roster page reads from THIS endpoint (api/members.php?id=)
        // so the Move Up / Move Down buttons appeared to do nothing — the
        // backend was honest, the rendered list just kept arriving in
        // mode order. Coalesce on mci.sort_order to handle older rows
        // whose sort_order may still be NULL (the migration backfills id
        // but a freshly-added column without the seed step could leave
        // them at 0/NULL).
        $comm_ids = safe_fetch_all_m(
            "SELECT mci.*, cm.code AS mode_code, cm.name AS mode_name,
                    cm.icon AS mode_icon, cm.color AS mode_color, cm.fields_json,
                    cm.capabilities AS mode_capabilities
             FROM " . db_table('member_comm_identifiers') . " mci
             JOIN " . db_table('comm_modes') . " cm ON mci.comm_mode_id = cm.id
             WHERE mci.member_id = ? AND cm.enabled = 1
             ORDER BY COALESCE(NULLIF(mci.sort_order, 0), mci.id),
                      cm.sort_order, mci.id",
            [$id]
        );

        // Get all enabled comm modes (for add dropdown)
        $all_comm_modes = safe_fetch_all_m(
            "SELECT id, code, name, icon, color, fields_json, capabilities FROM " . db_table('comm_modes') . "
             WHERE enabled = 1 ORDER BY sort_order, name"
        );

        // Get all organizations (for add dropdown)
        $all_orgs = safe_fetch_all_m(
            "SELECT id, name, short_name FROM " . db_table('organizations') . "
             WHERE active = 1 ORDER BY sort_order, name"
        );

        // Get callsigns
        $callsigns = safe_fetch_all_m(
            "SELECT * FROM " . db_table('member_callsigns') . "
             WHERE member_id = ?
             ORDER BY is_primary DESC, license_type, callsign",
            [$id]
        );

        // Member types (for org edit modal)
        $all_types = safe_fetch_all_m(
            "SELECT id, name, color FROM " . db_table('member_types') . " ORDER BY sort_order, name"
        );

        // Linked user account (check if any user row references this member)
        $linked_user = null;
        try {
            $linked_user = db_fetch_one(
                "SELECT `id`, `user`, `level` FROM " . db_table('user') .
                " WHERE `member` = ?",
                [$id]
            );
        } catch (Exception $e) {
            // 'member' column may not exist on user table — non-fatal
        }

        json_response([
            'member'            => $member[0],
            'certifications'    => $certs,
            'ics_qualifications' => $ics_quals,
            'all_ics_positions' => $all_ics,
            'team_memberships'  => $member_teams,
            'training_records'  => $training,
            'training_hours'    => !empty($training_hours) ? (float)$training_hours[0]['total'] : 0,
            'organizations'     => $member_orgs,
            'all_organizations' => $all_orgs,
            'comm_identifiers'  => $comm_ids,
            'all_comm_modes'    => $all_comm_modes,
            'callsigns'         => $callsigns,
            'all_member_types'  => $all_types,
            'linked_user'       => $linked_user
        ]);
    }

    // Search
    if (!empty($_GET['search'])) {
        $term = '%' . trim($_GET['search']) . '%';
        // Phase 99j-5 — same org-scope filter applies to search.
        require_once __DIR__ . '/../inc/org-scope.php';
        [$memOrgFrag, $memOrgVars] = org_member_query_filter('m.id');
        $rows = safe_fetch_all_m(
            "SELECT m.id, m.first_name, m.last_name, m.callsign, m.phone_cell, m.email,
                    m.available, m.member_type_id, m.member_status_id, m.team_id,
                    m.photo_file_id,
                    mt.name AS type_name,
                    mt.background AS type_color, mt.color AS type_text_color,
                    ms.status_val AS status_name,
                    ms.background AS status_color, ms.color AS status_text_color,
                    t.name AS team_name
             FROM " . db_table('member') . " m
             LEFT JOIN " . db_table('member_types') . " mt ON m.member_type_id = mt.id
             LEFT JOIN " . db_table('member_status') . " ms ON m.member_status_id = ms.id
             LEFT JOIN " . db_table('teams') . " t ON m.team_id = t.id
             WHERE (m.deleted_at IS NULL)
               AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.callsign LIKE ?
                OR m.phone_cell LIKE ? OR m.email LIKE ?)
             {$memOrgFrag}
             ORDER BY m.last_name, m.first_name
             LIMIT 100",
            array_merge([$term, $term, $term, $term, $term], $memOrgVars)
        );
        json_response(['members' => $rows]);
    }

    // Members with a specific ICS qualification
    if (!empty($_GET['ics_position_id'])) {
        $posId = intval($_GET['ics_position_id']);
        // Phase 99j-5 — same org-scope filter on ICS-qualified roster.
        require_once __DIR__ . '/../inc/org-scope.php';
        [$memOrgFrag, $memOrgVars] = org_member_query_filter('m.id');
        $rows = safe_fetch_all_m(
            "SELECT m.id, m.first_name, m.last_name, m.callsign, m.phone_cell, m.email,
                    m.available, m.member_type_id, m.member_status_id, m.team_id,
                    m.title, m.join_date,
                    mt.name AS type_name,
                    mt.background AS type_color, mt.color AS type_text_color,
                    ms.status_val AS status_name,
                    ms.background AS status_color, ms.color AS status_text_color,
                    t.name AS team_name,
                    miq.qualification_level, miq.ptb_status
             FROM " . db_table('member') . " m
             INNER JOIN " . db_table('member_ics_qualifications') . " miq ON m.id = miq.member_id
             LEFT JOIN " . db_table('member_types') . " mt ON m.member_type_id = mt.id
             LEFT JOIN " . db_table('member_status') . " ms ON m.member_status_id = ms.id
             LEFT JOIN " . db_table('teams') . " t ON m.team_id = t.id
             WHERE miq.ics_position_id = ? AND (m.deleted_at IS NULL)
             {$memOrgFrag}
             ORDER BY m.last_name, m.first_name",
            array_merge([$posId], $memOrgVars)
        );

        // Get the position info for display
        $pos = safe_fetch_all_m(
            "SELECT code, title FROM " . db_table('ics_positions') . " WHERE id = ?",
            [$posId]
        );

        // Also return standard lookup data for filter UI
        $types = safe_fetch_all_m("SELECT * FROM " . db_table('member_types') . " ORDER BY name");
        $statuses = safe_fetch_all_m("SELECT id, status_val AS name, color, background FROM " . db_table('member_status') . " ORDER BY id");
        $teams = safe_fetch_all_m("SELECT * FROM " . db_table('teams') . " WHERE active = 1 ORDER BY name");
        $certifications = safe_fetch_all_m("SELECT * FROM " . db_table('certifications') . " ORDER BY category, name");

        json_response([
            'members'        => $rows,
            'types'          => $types,
            'statuses'       => $statuses,
            'teams'          => $teams,
            'certifications' => $certifications,
            'ics_filter'     => !empty($pos) ? $pos[0] : null
        ]);
    }

    // Phase 99q (Billy beta 2026-06-29) — older installs may lack
    // some columns the LIST SELECT references (`deleted_at`,
    // `title`, `join_date`, `photo_file_id`). When that happens the
    // SELECT throws → safe_fetch_all_m swallows the exception → the
    // Roster page renders empty even though `member` rows exist.
    //
    // Fix: idempotently ensure those columns exist before querying.
    // Each ALTER is a no-op when the column already exists, so this
    // is safe to run on every request (and cheap — the
    // information_schema check is cached by MariaDB).
    ensureMemberListColumns();

    // Phase 99j-5 (Billy beta 2026-06-29) — org-scope filter via the
    // member_organizations junction. Super Admin → empty fragment;
    // Org Admin → only members linked to own + descendant orgs;
    // ordinary users → only members linked to their home org.
    // See specs/phase-99j-org-scoping/spec.md.
    require_once __DIR__ . '/../inc/org-scope.php';
    [$memOrgFrag, $memOrgVars] = org_member_query_filter('m.id');

    // List all (exclude soft-deleted)
    $rows = safe_fetch_all_m(
        "SELECT m.id, m.first_name, m.last_name, m.callsign, m.phone_cell, m.email,
                m.available, m.member_type_id, m.member_status_id, m.team_id,
                m.title, m.join_date, m.photo_file_id,
                mt.name AS type_name,
                mt.background AS type_color, mt.color AS type_text_color,
                ms.status_val AS status_name,
                ms.background AS status_color, ms.color AS status_text_color,
                t.name AS team_name
         FROM " . db_table('member') . " m
         LEFT JOIN " . db_table('member_types') . " mt ON m.member_type_id = mt.id
         LEFT JOIN " . db_table('member_status') . " ms ON m.member_status_id = ms.id
         LEFT JOIN " . db_table('teams') . " t ON m.team_id = t.id
         WHERE (m.deleted_at IS NULL)
         {$memOrgFrag}
         ORDER BY m.last_name, m.first_name",
        $memOrgVars
    );

    // Enrich members with team memberships from junction table
    $teamMemberships = safe_fetch_all_m(
        "SELECT tm.member_id, tm.team_id, t.`team` AS team_name
         FROM " . db_table('team_members') . " tm
         JOIN " . db_table('teams') . " t ON tm.team_id = t.id
         ORDER BY t.`team`"
    );
    // Build lookup: member_id => [team_id, team_id, ...]
    $memberTeams = [];
    foreach ($teamMemberships as $tm) {
        $mid = (int) $tm['member_id'];
        if (!isset($memberTeams[$mid])) $memberTeams[$mid] = [];
        $memberTeams[$mid][] = ['id' => (int) $tm['team_id'], 'name' => $tm['team_name']];
    }
    // Add team_ids array to each member
    foreach ($rows as &$m) {
        $mid = (int) $m['id'];
        $m['team_ids'] = isset($memberTeams[$mid]) ? array_column($memberTeams[$mid], 'id') : [];
        $m['team_names'] = isset($memberTeams[$mid]) ? array_column($memberTeams[$mid], 'name') : [];
        // Use first team as primary if team_id is null
        if (empty($m['team_id']) && !empty($m['team_ids'])) {
            $m['team_id'] = $m['team_ids'][0];
            $m['team_name'] = $m['team_names'][0];
        }
    }
    unset($m);

    // Also return lookup data for forms
    $types = safe_fetch_all_m("SELECT * FROM " . db_table('member_types') . " ORDER BY name");
    $statuses = safe_fetch_all_m("SELECT id, status_val AS name, color, background FROM " . db_table('member_status') . " ORDER BY id");
    $teams = safe_fetch_all_m("SELECT id, `team` AS name FROM " . db_table('teams') . " ORDER BY `team`");
    $certifications = safe_fetch_all_m("SELECT * FROM " . db_table('certifications') . " ORDER BY category, name");

    json_response([
        'members'        => $rows,
        'types'          => $types,
        'statuses'       => $statuses,
        'teams'          => $teams,
        'certifications' => $certifications
    ]);
}

function handlePost() {
    global $current_user_id;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // RBAC + CSRF enforcement (specs/rbac-enforcement-2026-06).
    // Writes require action.manage_members; reads (GET) stay open to viewers.
    //
    // M3 in code review 2026-07-03: cert_search / training_search are
    // read-only actions (they SELECT suggestion lists for the roster's
    // typeahead) and were incorrectly gated behind action.manage_members
    // just because they live inside handlePost(). Non-manager
    // dispatchers who could otherwise view the roster couldn't use the
    // typeahead at all. Let those two through with only the
    // authentication check (auth.php ran above) + CSRF token; they
    // never write.
    $readOnlyPostActions = ['cert_search', 'training_search'];
    $actionName = (string) ($input['action'] ?? '');
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    if (!in_array($actionName, $readOnlyPostActions, true)) {
        if (!rbac_can('action.manage_members')) {
            json_error('Insufficient permissions: manage members', 403);
        }
    }

    // Phase 99j-5 (Billy beta 2026-06-29) — org-scope gate. Every
    // member-write action targets a specific member via either
    // `id` (delete, bare update) or `member_id` (sub-resource:
    // certifications, callsigns, etc.). If we can identify the
    // target and it's not visible to this user, return 404.
    //
    // Create-shaped requests (no id / no member_id) flow through
    // unchanged — they fall through to the bare-update branch which
    // INSERTs a new member, and the new member's org link is
    // established at write time (handled separately below).
    $targetMemberId = (int) ($input['id'] ?? $input['member_id'] ?? 0);
    if ($targetMemberId > 0) {
        require_once __DIR__ . '/../inc/org-scope.php';
        if (!org_can_see_member($targetMemberId)) {
            json_error('Member not found', 404);
        }
    }

    // Delete (soft delete — sets deleted_at instead of removing)
    if (($input['action'] ?? '') === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');

        // Pre-fetch name for the audit message — helper doesn't return it.
        $mem = safe_fetch_all_m(
            "SELECT first_name, last_name, callsign FROM " . db_table('member') . " WHERE id = ?",
            [$id]
        );
        $memName = !empty($mem) ? trim($mem[0]['first_name'] . ' ' . $mem[0]['last_name']) : "#{$id}";

        try {
            $result = member_soft_delete($id, (int) $current_user_id);
            if (!empty($result['errors'])) {
                // Helper throws on real DB errors, so reaching here means
                // a validation-shaped error.
                json_error('Failed to delete: ' . $result['errors'][0]);
            }
        } catch (Exception $sdErr) {
            // Pre-wastebasket installs may not have deleted_at column —
            // fall back to hard delete with cascade on member_certifications.
            // The helper throws in that case so the caller can implement
            // its own fallback.
            $msg = $sdErr->getMessage();
            if (strpos($msg, 'deleted_at') !== false
                || strpos($msg, 'Unknown column') !== false) {
                try {
                    db_query("DELETE FROM " . db_table('member_certifications') . " WHERE member_id = ?", [$id]);
                    db_query("DELETE FROM " . db_table('member') . " WHERE id = ?", [$id]);
                    audit_log('personnel', 'delete', 'member', $id,
                        "Deleted member '{$memName}' (pre-wastebasket hard delete)");
                    json_response(['success' => true]);
                } catch (Exception $hdErr) {
                    json_error('Failed to delete: ' . $hdErr->getMessage());
                }
            } else {
                json_error('Failed to delete: ' . $msg);
            }
        }

        // Canonical webhook-eligible event: 'personnel|delete|member' →
        // member.deleted (already canonical — no normalization needed).
        audit_log('personnel', 'delete', 'member', $id,
            "Soft-deleted member '{$memName}'");
        json_response(['success' => true]);
    }

    // Bulk soft-delete (Billy Irwin / K9OH suggestion, 2026-07-04) — remove
    // several roster members at once. Same CSRF + action.manage_members gate as
    // the single delete above (enforced at the top of this POST handler), the
    // same member_soft_delete() helper + pre-wastebasket hard-delete fallback,
    // and one audit_log row per member so the trail is unchanged.
    if (($input['action'] ?? '') === 'bulk_delete') {
        // Dedicated permission on TOP of the manage_members gate above. Bulk
        // removal is a bigger hammer than single delete, so Eric wants it
        // narrowly held (2026-07-04) — Super Admin only by default; grantable
        // to any role via the Roles UI. is_admin() is the super-admin escape
        // hatch (Org Admins lack it — they don't have action.manage_config).
        if (!rbac_can('action.bulk_delete_members') && !is_admin()) {
            json_error('Insufficient permissions: bulk delete members', 403);
        }
        $rawIds = $input['ids'] ?? [];
        if (!is_array($rawIds)) json_error('ids must be an array');
        // Normalize to a unique list of positive ints; cap to keep one request
        // bounded (the UI selects from a paged list, so this is generous).
        $ids = [];
        foreach ($rawIds as $rid) {
            $rid = (int) $rid;
            if ($rid > 0) { $ids[$rid] = true; }
        }
        $ids = array_keys($ids);
        if (empty($ids)) json_error('No valid member ids supplied');
        if (count($ids) > 500) json_error('Too many members selected (max 500 per request)');

        // SECURITY (2026-07-05) — org-scope gate PER id. The single-delete path
        // above runs org_can_see_member() on $input['id'], but bulk_delete
        // targets an ARRAY ($input['ids']), so $targetMemberId was 0 and that
        // gate was skipped. Without this, a user granted action.bulk_delete_members
        // in an ORG scope could soft-delete members of other orgs (cross-org
        // IDOR). Super Admins see all (org_can_see_member returns true), so this
        // is a no-op for the default config. Denied ids are reported as failed.
        require_once __DIR__ . '/../inc/org-scope.php';

        $deleted = 0;
        $failed  = [];
        $denied  = [];
        foreach ($ids as $id) {
            if (!org_can_see_member($id)) { $failed[] = $id; $denied[] = $id; continue; }
            $mem = safe_fetch_all_m(
                "SELECT first_name, last_name FROM " . db_table('member') . " WHERE id = ?",
                [$id]
            );
            $memName = !empty($mem) ? trim($mem[0]['first_name'] . ' ' . $mem[0]['last_name']) : "#{$id}";
            try {
                $result = member_soft_delete($id, (int) $current_user_id);
                if (!empty($result['errors'])) { $failed[] = $id; continue; }
                audit_log('personnel', 'delete', 'member', $id,
                    "Soft-deleted member '{$memName}' (bulk)");
                $deleted++;
            } catch (Exception $sdErr) {
                $msg = $sdErr->getMessage();
                if (strpos($msg, 'deleted_at') !== false || strpos($msg, 'Unknown column') !== false) {
                    // Pre-wastebasket install — hard delete with cert cascade.
                    try {
                        db_query("DELETE FROM " . db_table('member_certifications') . " WHERE member_id = ?", [$id]);
                        db_query("DELETE FROM " . db_table('member') . " WHERE id = ?", [$id]);
                        audit_log('personnel', 'delete', 'member', $id,
                            "Deleted member '{$memName}' (bulk, pre-wastebasket hard delete)");
                        $deleted++;
                    } catch (Exception $hdErr) {
                        $failed[] = $id;
                    }
                } else {
                    $failed[] = $id;
                }
            }
        }
        json_response([
            'success' => true,
            'deleted' => $deleted,
            'failed'  => $failed,
            'denied'  => $denied,   // ids skipped by the org-scope gate
        ]);
    }

    // Add/update certification.
    //
    // Accepts EITHER:
    //   * certification_id — pick an existing row from the dropdown (legacy path)
    //   * certification_name — freeform typeahead value. If it doesn't match an
    //     existing row (case-insensitively), a new certifications row is
    //     auto-created with just the name so the FK on member_certifications
    //     stays consistent. Relies on the UNIQUE KEY certifications(name)
    //     added in commit 5ee301c to prevent races.
    if (($input['action'] ?? '') === 'add_cert') {
        $memberId = intval($input['member_id'] ?? 0);
        $certId   = intval($input['certification_id'] ?? 0);
        $certName = trim((string) ($input['certification_name'] ?? ''));
        if (!$memberId) json_error('Missing member_id');

        // Name-based path: resolve to id, auto-create if novel.
        if (!$certId && $certName !== '') {
            $existing = db_fetch_one(
                "SELECT id FROM " . db_table('certifications') . " WHERE `name` = ? LIMIT 1",
                [$certName]
            );
            if ($existing) {
                $certId = (int) $existing['id'];
            } else {
                // Auto-create. INSERT IGNORE handles a race where another
                // request creates the same name concurrently; we re-SELECT
                // to pick up whichever row won.
                try {
                    db_query(
                        "INSERT IGNORE INTO " . db_table('certifications') .
                        " (`name`, `category`) VALUES (?, ?)",
                        [$certName, 'Other']
                    );
                    $row = db_fetch_one(
                        "SELECT id FROM " . db_table('certifications') . " WHERE `name` = ? LIMIT 1",
                        [$certName]
                    );
                    $certId = $row ? (int) $row['id'] : 0;
                    if ($certId) {
                        audit_log('personnel', 'create', 'certification', $certId,
                            "Auto-created certification '{$certName}' via freeform roster entry",
                            ['certification_name' => $certName]);
                    }
                } catch (Exception $e) {
                    json_error('Failed to create certification: ' . $e->getMessage());
                }
            }
        }
        if (!$certId) json_error('Missing certification_id or certification_name');

        try {
            db_query(
                "INSERT INTO " . db_table('member_certifications') . "
                 (member_id, certification_id, earned_date, expiry_date, certificate_number, issuing_authority, verification_url, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $memberId, $certId,
                    $input['earned_date'] ?? null,
                    $input['expiry_date'] ?? null,
                    !empty($input['certificate_number']) ? trim($input['certificate_number']) : null,
                    !empty($input['issuing_authority']) ? trim($input['issuing_authority']) : null,
                    !empty($input['verification_url']) ? trim($input['verification_url']) : null,
                    $input['notes'] ?? null
                ]
            );
            audit_log('personnel', 'assign', 'member_certification', null, "Added certification #{$certId} to member #{$memberId}", [
                'member_id' => $memberId,
                'certification_id' => $certId
            ]);
        } catch (Exception $e) {
            json_error('Failed to add certification: ' . $e->getMessage());
        }
        json_response(['success' => true, 'certification_id' => $certId]);
    }

    // Freeform training / certification typeahead search.
    //
    // Powers the Training Records name field AND the Certifications
    // Add row on the roster page (and any future consumer that wants
    // consistent training-name autocomplete). Returns three buckets:
    //
    //   * catalog  — matches from the `certifications` table (the
    //                canonical training list), each with a
    //                member_count so the caller can show how many
    //                people already hold it.
    //   * logged   — distinct `training_records.training_name` strings
    //                that at least one other member has logged, but
    //                aren't in the catalog. Useful when someone
    //                previously entered a freeform name and the same
    //                training comes up again for another member.
    //   * total    — count of catalog + logged returned.
    //
    // Passing q='' returns the top 50 by popularity so the operator
    // sees suggestions on focus without needing to type first.
    //
    // Backward compat: the older 'cert_search' action name still works
    // as an alias for the same behavior.
    if (in_array(($input['action'] ?? ''), ['training_search', 'cert_search'], true)) {
        $q = trim((string) ($input['q'] ?? ''));
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        // 1. Catalog matches from certifications (with usage count).
        $sqlCat = "SELECT c.id, c.name, c.category, c.fema_course_code,
                          COUNT(DISTINCT mc.member_id) AS member_count
                     FROM " . db_table('certifications') . " c
                     LEFT JOIN " . db_table('member_certifications') . " mc
                       ON mc.certification_id = c.id";
        $whereCat = '';
        $paramsCat = [];
        if ($q !== '') {
            $whereCat = " WHERE c.name LIKE ? OR c.fema_course_code LIKE ?";
            $paramsCat = [$like, $like];
        }
        $sqlCat .= $whereCat . "
                    GROUP BY c.id, c.name, c.category, c.fema_course_code
                    ORDER BY member_count DESC, c.name ASC
                    LIMIT 30";
        $catalog = safe_fetch_all_m($sqlCat, $paramsCat);

        // 2. Distinct training_names logged in training_records that
        //    aren't already in the catalog (case-insensitive filter).
        //    Grouped so popular names float to the top.
        $catalogNames = [];
        foreach ($catalog as $c) $catalogNames[strtolower($c['name'])] = true;

        $logged = [];
        try {
            $sqlLog = "SELECT training_name, COUNT(DISTINCT member_id) AS member_count,
                              COUNT(*) AS record_count
                         FROM " . db_table('training_records') . "
                        WHERE training_name IS NOT NULL AND training_name <> ''";
            $paramsLog = [];
            if ($q !== '') {
                $sqlLog .= " AND training_name LIKE ?";
                $paramsLog[] = $like;
            }
            $sqlLog .= " GROUP BY training_name
                         ORDER BY member_count DESC, record_count DESC, training_name ASC
                         LIMIT 30";
            foreach (safe_fetch_all_m($sqlLog, $paramsLog) as $r) {
                if (isset($catalogNames[strtolower($r['training_name'])])) continue;
                $logged[] = [
                    'name'         => $r['training_name'],
                    'member_count' => (int) $r['member_count'],
                    'record_count' => (int) $r['record_count'],
                ];
            }
        } catch (Exception $e) {
            // training_records table may not exist on very old installs
            $logged = [];
        }

        json_response([
            'query'   => $q,
            'catalog' => $catalog,
            'logged'  => $logged,
            'total'   => count($catalog) + count($logged),
        ]);
    }

    // Update certification (edit existing)
    if (($input['action'] ?? '') === 'update_cert') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query(
                "UPDATE " . db_table('member_certifications') . "
                 SET earned_date = ?, expiry_date = ?, certificate_number = ?,
                     issuing_authority = ?, verification_url = ?, notes = ?
                 WHERE id = ?",
                [
                    $input['earned_date'] ?? null,
                    $input['expiry_date'] ?? null,
                    !empty($input['certificate_number']) ? trim($input['certificate_number']) : null,
                    !empty($input['issuing_authority']) ? trim($input['issuing_authority']) : null,
                    !empty($input['verification_url']) ? trim($input['verification_url']) : null,
                    $input['notes'] ?? null,
                    $id
                ]
            );
            audit_log('personnel', 'update', 'member_certification', $id, "Updated member certification #{$id}");
        } catch (Exception $e) {
            json_error('Failed to update certification: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Remove certification
    if (($input['action'] ?? '') === 'remove_cert') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            $mc = safe_fetch_all_m("SELECT member_id, certification_id FROM " . db_table('member_certifications') . " WHERE id = ?", [$id]);
            db_query("DELETE FROM " . db_table('member_certifications') . " WHERE id = ?", [$id]);
            audit_log('personnel', 'unassign', 'member_certification', $id, "Removed certification from member", [
                'member_id' => !empty($mc) ? $mc[0]['member_id'] : null,
                'certification_id' => !empty($mc) ? $mc[0]['certification_id'] : null
            ]);
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Callsign CRUD ──────────────────────────────────────────

    // Add / update a callsign
    if (($input['action'] ?? '') === 'save_callsign') {
        $memberId     = intval($input['member_id'] ?? 0);
        $callsign     = strtoupper(trim($input['callsign'] ?? ''));
        $licenseType  = trim($input['license_type'] ?? 'amateur');
        if (!$memberId || !$callsign) json_error('Missing member_id or callsign');

        try {
            $existing = safe_fetch_all_m(
                "SELECT id FROM " . db_table('member_callsigns') . " WHERE member_id = ? AND callsign = ?",
                [$memberId, $callsign]
            );
            if (!empty($existing)) {
                // Update
                db_query(
                    "UPDATE " . db_table('member_callsigns') . "
                     SET license_type = ?, oper_class = ?, frn = ?, grant_date = ?,
                         expiry_date = ?, grid_square = ?, source = ?
                     WHERE id = ?",
                    [
                        $licenseType,
                        !empty($input['oper_class']) ? trim($input['oper_class']) : null,
                        !empty($input['frn']) ? trim($input['frn']) : null,
                        !empty($input['grant_date']) ? $input['grant_date'] : null,
                        !empty($input['expiry_date']) ? $input['expiry_date'] : null,
                        !empty($input['grid_square']) ? trim($input['grid_square']) : null,
                        !empty($input['source']) ? trim($input['source']) : null,
                        $existing[0]['id']
                    ]
                );
            } else {
                // Check if this is the first callsign — make it primary
                $count = safe_fetch_all_m(
                    "SELECT COUNT(*) AS cnt FROM " . db_table('member_callsigns') . " WHERE member_id = ?",
                    [$memberId]
                );
                $isPrimary = (empty($count) || intval($count[0]['cnt']) === 0) ? 1 : 0;

                db_query(
                    "INSERT INTO " . db_table('member_callsigns') . "
                     (member_id, callsign, license_type, oper_class, frn, grant_date, expiry_date, grid_square, is_primary, source)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $memberId, $callsign, $licenseType,
                        !empty($input['oper_class']) ? trim($input['oper_class']) : null,
                        !empty($input['frn']) ? trim($input['frn']) : null,
                        !empty($input['grant_date']) ? $input['grant_date'] : null,
                        !empty($input['expiry_date']) ? $input['expiry_date'] : null,
                        !empty($input['grid_square']) ? trim($input['grid_square']) : null,
                        $isPrimary,
                        !empty($input['source']) ? trim($input['source']) : null
                    ]
                );
            }
            // Sync primary callsign to legacy member.callsign field
            syncPrimaryCallsign($memberId);
            audit_log('personnel', 'update', 'member_callsign', $memberId, "Saved callsign {$callsign} ({$licenseType})");
        } catch (Exception $e) {
            json_error('Failed to save callsign: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Delete a callsign
    if (($input['action'] ?? '') === 'delete_callsign') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            $row = safe_fetch_all_m("SELECT member_id, callsign FROM " . db_table('member_callsigns') . " WHERE id = ?", [$id]);
            db_query("DELETE FROM " . db_table('member_callsigns') . " WHERE id = ?", [$id]);
            if (!empty($row)) {
                syncPrimaryCallsign($row[0]['member_id']);
                audit_log('personnel', 'delete', 'member_callsign', $id, "Removed callsign {$row[0]['callsign']}");
            }
        } catch (Exception $e) {
            json_error('Failed to delete callsign: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Set primary callsign
    if (($input['action'] ?? '') === 'set_primary_callsign') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            $row = safe_fetch_all_m("SELECT member_id FROM " . db_table('member_callsigns') . " WHERE id = ?", [$id]);
            if (empty($row)) json_error('Callsign not found');
            $memberId = $row[0]['member_id'];
            db_query("UPDATE " . db_table('member_callsigns') . " SET is_primary = 0 WHERE member_id = ?", [$memberId]);
            db_query("UPDATE " . db_table('member_callsigns') . " SET is_primary = 1 WHERE id = ?", [$id]);
            syncPrimaryCallsign($memberId);
            audit_log('personnel', 'update', 'member_callsign', $id, "Set primary callsign");
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Validate
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    if (!$firstName || !$lastName) json_error('First and last name are required');

    $id = intval($input['id'] ?? 0);

    if ($id > 0) {
        // UPDATE — partial-save semantics: only touch fields the caller
        // actually sent. An "intent to clear" is signalled by sending
        // the key with an empty string. An absent key means "don't touch"
        // (preserves callsign, notes, photo_file_id, etc. across
        // partial form saves — PRE-RELEASE-FIXES item #16).
        //
        // Filter input down to the keys the helper's whitelist accepts.
        // first_name + last_name are validated above and ALWAYS included.
        static $passthrough = [
            'first_name', 'last_name', 'middle_name',
            'member_type_id', 'member_status_id', 'team_id',
            'callsign', 'title', 'email',
            'phone_home', 'phone_work', 'phone_cell',
            'street', 'city', 'county', 'state', 'zip',
            'dob', 'join_date', 'membership_due', 'available',
            'emergency_contact', 'emergency_phone', 'emergency_relation',
            'medical_info', 'notes', 'photo_file_id',
        ];
        $partial = [
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ];
        foreach ($passthrough as $col) {
            if ($col === 'first_name' || $col === 'last_name') continue;
            if (!array_key_exists($col, $input)) continue;
            $partial[$col] = $input[$col];
        }
        // Normalize 'available' to the Yes/No domain the helper stores.
        if (array_key_exists('available', $partial)) {
            $partial['available'] = ($partial['available'] === 'Yes') ? 'Yes' : 'No';
        }

        try {
            $result = member_update_internal($id, $partial, (int) $current_user_id);
            if (!empty($result['errors'])) {
                json_error('Failed to save: ' . $result['errors'][0]);
            }
        } catch (Exception $e) {
            json_error('Failed to save: ' . $e->getMessage());
        }

        audit_log('personnel', 'update', 'member', $id,
            "Updated member '{$firstName} {$lastName}'",
            [
                'callsign'       => trim($input['callsign'] ?? '') ?: null,
                'fields_changed' => $result['fields_changed'] ?? [],
            ]);
    } else {
        // CREATE — helper does the validation and the legacy-defaults
        // self-heal retry. Pass the raw input untouched so the helper's
        // full default set kicks in.
        try {
            $result = member_create_internal($input, (int) $current_user_id);
            if (!empty($result['errors'])) {
                json_error('Failed to save: ' . $result['errors'][0]);
            }
            $id = (int) $result['id'];
        } catch (Exception $e) {
            json_error('Failed to save: ' . $e->getMessage());
        }

        audit_log('personnel', 'create', 'member', $id,
            "Created member '{$firstName} {$lastName}'",
            ['callsign' => trim($input['callsign'] ?? '') ?: null]);
    }

    json_response(['success' => true, 'id' => $id]);
}
