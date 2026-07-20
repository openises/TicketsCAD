<?php
/**
 * Phase 94 Stage 4d — Member (personnel) write helpers.
 *
 * Extracted from api/members.php's POST save logic so both the
 * internal CSRF-checked endpoint and the external token-auth endpoint
 * call into the same write path. Caller does CSRF/bearer auth + RBAC
 * — this file just writes.
 *
 * Scope of this slim helper (v1): create + soft-delete. UPDATE
 * follows once the internal endpoint's partial-vs-full update
 * semantics are factored out cleanly.
 */

declare(strict_types=1);

/**
 * Create a member from a key-value input array.
 *
 * Required: first_name, last_name. All other fields optional.
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.manage_members') check
 *   - $_SESSION['user_id'] for created_by attribution
 *
 * @return array ['id' => int, 'errors' => string[]]
 *         On validation error: ['errors' => [...]] with no id.
 *         On DB error: throws.
 */
function member_create_internal(array $input, int $userId): array {
    $first = trim((string) ($input['first_name'] ?? ''));
    $last  = trim((string) ($input['last_name'] ?? ''));
    if ($first === '' || $last === '') {
        return ['errors' => ['first_name and last_name are required']];
    }

    // 2026-06-28: format_phone() lives in inc/functions.php — the
    // internal endpoint calls it before saving. Mirror that here so
    // the helper and the legacy endpoint produce identical writes.
    // function_exists() guard so the external API path (which already
    // includes functions.php) doesn't blow up when functions.php is
    // somehow missing on a partial install.
    $fmt = function_exists('format_phone')
        ? 'format_phone'
        : static fn($v) => trim((string) $v);

    $now = date('Y-m-d H:i:s');
    $fields = [
        'first_name'         => $first,
        'last_name'          => $last,
        'middle_name'        => trim((string) ($input['middle_name'] ?? '')),
        'member_type_id'     => !empty($input['member_type_id']) ? (int) $input['member_type_id'] : null,
        'member_status_id'   => !empty($input['member_status_id']) ? (int) $input['member_status_id'] : null,
        'team_id'            => !empty($input['team_id']) ? (int) $input['team_id'] : null,
        'callsign'           => trim((string) ($input['callsign'] ?? '')),
        'title'              => trim((string) ($input['title'] ?? '')),
        'email'              => trim((string) ($input['email'] ?? '')),
        'phone_home'         => $fmt(trim((string) ($input['phone_home'] ?? ''))),
        'phone_work'         => $fmt(trim((string) ($input['phone_work'] ?? ''))),
        'phone_cell'         => $fmt(trim((string) ($input['phone_cell'] ?? ''))),
        'street'             => trim((string) ($input['street'] ?? '')),
        'city'               => trim((string) ($input['city'] ?? '')),
        'county'             => trim((string) ($input['county'] ?? '')), // QA #9
        'state'              => trim((string) ($input['state'] ?? '')),
        'zip'                => trim((string) ($input['zip'] ?? '')),
        'dob'                => !empty($input['dob']) ? $input['dob'] : null,
        'join_date'          => !empty($input['join_date']) ? $input['join_date'] : null,
        'membership_due'     => !empty($input['membership_due']) ? $input['membership_due'] : null,
        'available'          => (($input['available'] ?? 'Yes') === 'Yes') ? 'Yes' : 'No',
        'emergency_contact'  => trim((string) ($input['emergency_contact'] ?? '')),
        'emergency_phone'    => trim((string) ($input['emergency_phone'] ?? '')),
        'emergency_relation' => trim((string) ($input['emergency_relation'] ?? '')),
        'medical_info'       => trim((string) ($input['medical_info'] ?? '')),
        'notes'              => trim((string) ($input['notes'] ?? '')),
        'photo_file_id'      => (isset($input['photo_file_id']) && $input['photo_file_id'] !== '')
                                    ? (int) $input['photo_file_id'] : null,
        'updated_at'         => $now,
        'created_at'         => $now,
        'created_by'         => $userId,
    ];

    // Remap generated columns (e.g. first_name → field2 on legacy v3.44 schemas).
    // Mirrors what api/members.php does via remapGeneratedColumns(). The
    // function isn't available from the external endpoint's include chain;
    // use the local helper below.
    $fields = _member_write_remap_generated($fields);

    $cols = array_keys($fields);
    $placeholders = array_fill(0, count($cols), '?');
    $sql = "INSERT INTO " . db_table('member') .
           " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";

    try {
        db_query($sql, array_values($fields));
    } catch (Exception $e) {
        // Self-heal: legacy NOT-NULL-no-default columns.
        $msg = $e->getMessage();
        if (strpos($msg, "doesn't have a default value") !== false) {
            _member_write_fix_legacy_defaults();
            db_query($sql, array_values($fields));
        } else {
            throw $e;
        }
    }
    $newId = (int) db_insert_id();

    // Phase 99j-5 (Billy beta 2026-06-29) — auto-link new member to
    // the creator's home org via member_organizations. Without this,
    // every new member has zero junction rows and is invisible to
    // every org-scoped session (the legacy-fallback "NOT EXISTS"
    // branch keeps them visible, but that's the wrong steady state
    // for new data). The link uses status='active' and join_date now.
    try {
        require_once __DIR__ . '/org-scope.php';
        $homeOrg = org_user_home_id($userId);
        if ($homeOrg > 0) {
            db_query(
                "INSERT INTO " . db_table('member_organizations') . "
                 (`member_id`, `org_id`, `status`, `join_date`, `created_at`)
                 VALUES (?, ?, 'active', CURDATE(), NOW())",
                [$newId, $homeOrg]
            );
        }
    } catch (Exception $e) {
        // Junction insert failure is non-fatal — log and continue.
        // The member exists; an Org Admin can add the org link via
        // the Personnel detail org-memberships UI.
        error_log("[member_create_internal] member_organizations insert failed for member $newId: " . $e->getMessage());
    }

    return ['id' => $newId, 'errors' => []];
}

/**
 * Internal: remap canonical column names to legacy v3.44 source columns
 * when the modern names are GENERATED columns (e.g. first_name reads
 * from field2). Copied from api/members.php so this helper is
 * standalone — the external endpoint doesn't include api/members.php.
 */
function _member_write_remap_generated(array $fields): array {
    static $map = null;
    if ($map === null) {
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
                $source = trim($col['GENERATION_EXPRESSION'], '` ');
                if ($source) $map[$col['COLUMN_NAME']] = $source;
            }
        } catch (Exception $e) { /* fall through with empty map */ }
    }
    if (empty($map)) return $fields;
    $remapped = [];
    foreach ($fields as $col => $val) {
        $remapped[$map[$col] ?? $col] = $val;
    }
    return $remapped;
}

/**
 * Internal: self-heal legacy NOT NULL columns that lack DEFAULT values.
 * Mirrors fixLegacyDefaults() in api/members.php.
 */
function _member_write_fix_legacy_defaults(): void {
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
               AND (COLUMN_NAME REGEXP '^field[0-9]+\$'
                    OR COLUMN_NAME IN ('_by', '_on', '_from'))",
            [$table]
        );
        foreach ($cols as $col) {
            try {
                $dtype = strtolower($col['DATA_TYPE']);
                if (in_array($dtype, ['int','bigint','smallint','tinyint','mediumint','decimal','float','double'])) {
                    db_query("ALTER TABLE `{$table}` ALTER COLUMN `{$col['COLUMN_NAME']}` SET DEFAULT 0");
                } elseif (in_array($dtype, ['datetime','timestamp','date'])) {
                    db_query("ALTER TABLE `{$table}` MODIFY COLUMN `{$col['COLUMN_NAME']}` {$col['COLUMN_TYPE']} NULL DEFAULT NULL");
                } else {
                    db_query("ALTER TABLE `{$table}` ALTER COLUMN `{$col['COLUMN_NAME']}` SET DEFAULT ''");
                }
            } catch (Exception $alterErr) { /* per-column failure non-fatal */ }
        }
    } catch (Exception $e) { /* schema read failed; let outer retry fail loudly */ }
}

/**
 * Soft-delete a member: set deleted_at + deleted_by. Does NOT cascade —
 * follows the same wastebasket pattern as api/wastebasket.php.
 *
 * @return array ['deleted' => bool, 'errors' => string[]]
 */
function member_soft_delete(int $memberId, int $userId): array {
    if ($memberId <= 0) {
        return ['deleted' => false, 'errors' => ['invalid_id']];
    }
    try {
        db_query(
            "UPDATE " . db_table('member') . "
             SET `deleted_at` = NOW(), `deleted_by` = ?
             WHERE `id` = ?",
            [$userId, $memberId]
        );
    } catch (Exception $e) {
        // Pre-wastebasket installs may not have deleted_at / deleted_by
        // columns. Don't paper over — let the error surface so admin
        // knows to run the wastebasket migration.
        throw $e;
    }
    return ['deleted' => true, 'errors' => []];
}

/**
 * Update selected fields on an existing member. Mirrors the partial-
 * update semantics of api/members.php's POST save path with id present:
 * only touch fields the caller sent (so an absent key means "leave it
 * alone", an empty-string key means "clear it").
 *
 * Required: $memberId > 0 AND $fields non-empty.
 * Whitelist mirrors the canonical column list from member_create_internal
 * (minus first_name/last_name being mandatory — on update they can be
 * omitted).
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.manage_members') check
 *
 * @return array ['id' => int, 'fields_changed' => string[], 'errors' => string[]]
 */
function member_update_internal(int $memberId, array $fields, int $userId): array {
    if ($memberId <= 0) return ['id' => 0, 'fields_changed' => [], 'errors' => ['invalid member_id']];
    if (empty($fields)) return ['id' => $memberId, 'fields_changed' => [], 'errors' => ['no fields to update']];

    // Same canonical columns as member_create_internal (sans the
    // mandatory first_name/last_name validation — on update they're
    // optional). 2026-06-28 added membership_due + photo_file_id so the
    // internal endpoint's partial-save flow can drop its inline UPDATE.
    static $allowed = [
        'first_name', 'last_name', 'middle_name',
        'member_type_id', 'member_status_id', 'team_id',
        'callsign', 'title', 'email',
        'phone_home', 'phone_work', 'phone_cell',
        'street', 'city', 'county', 'state', 'zip', // QA #9 — county was missing
        'dob', 'join_date', 'membership_due', 'available',
        'emergency_contact', 'emergency_phone', 'emergency_relation',
        'medical_info', 'notes', 'photo_file_id',
    ];
    static $intCols = [
        'member_type_id', 'member_status_id', 'team_id', 'photo_file_id',
    ];
    // Date-shaped columns: keep the caller's string as-is when truthy,
    // store NULL when empty/blank (mirrors the internal endpoint's
    // !empty(...) ? $val : null pattern).
    static $dateCols = [
        'dob', 'join_date', 'membership_due',
    ];
    static $phoneCols = [
        'phone_home', 'phone_work', 'phone_cell',
    ];
    $fmt = function_exists('format_phone')
        ? 'format_phone'
        : static fn($v) => trim((string) $v);

    $now = date('Y-m-d H:i:s');
    $writes = ['updated_at' => $now];
    $changed = [];
    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        if (in_array($key, $intCols, true)) {
            $writes[$key] = (int) $value > 0 ? (int) $value : null;
        } elseif (in_array($key, $dateCols, true)) {
            $writes[$key] = !empty($value) ? $value : null;
        } elseif (in_array($key, $phoneCols, true)) {
            $writes[$key] = $fmt(trim((string) $value));
        } else {
            $writes[$key] = trim((string) $value);
        }
        $changed[] = $key;
    }
    if (empty($changed)) {
        return ['id' => $memberId, 'fields_changed' => [], 'errors' => ['no whitelisted fields in request']];
    }

    // Remap canonical → legacy field columns on installs that use
    // GENERATED columns (first_name etc. read from field2/field4 on
    // v3.44). Same _member_write_remap_generated helper used by create.
    $writesRemapped = _member_write_remap_generated($writes);

    $sets = [];
    $params = [];
    foreach ($writesRemapped as $col => $val) {
        $sets[] = "`{$col}` = ?";
        $params[] = $val;
    }
    $params[] = $memberId;

    try {
        db_query(
            "UPDATE " . db_table('member') . " SET " . implode(', ', $sets) . " WHERE `id` = ?",
            $params
        );
    } catch (Exception $e) {
        return ['id' => $memberId, 'fields_changed' => [], 'errors' => ['update failed: ' . $e->getMessage()]];
    }
    return ['id' => $memberId, 'fields_changed' => $changed, 'errors' => []];
}

/**
 * Phase 94 Stage 4i — Update a member's status_id.
 *
 * Internal endpoints (api/members.php POST save) update status as
 * part of a wider upsert; the external API needs a per-resource
 * status-change call that mirrors api/responder-status.php's shape.
 * This helper writes the status column and bumps updated_at.
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.manage_members') check
 *   - Verifying both the member and the status row exist (this helper
 *     only checks $memberId > 0; status validity is caller's job so
 *     the error envelope can be shaped consistently)
 *
 * @return array ['status_id' => int, 'errors' => string[]]
 */
function member_set_status_internal(int $memberId, int $statusId, int $userId): array {
    if ($memberId <= 0) {
        return ['status_id' => 0, 'errors' => ['invalid_member_id']];
    }
    if ($statusId <= 0) {
        return ['status_id' => 0, 'errors' => ['invalid_status_id']];
    }
    try {
        db_query(
            "UPDATE " . db_table('member') . "
             SET `member_status_id` = ?, `updated_at` = NOW()
             WHERE `id` = ?
               AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
            [$statusId, $memberId]
        );
    } catch (Exception $e) {
        return ['status_id' => 0, 'errors' => ['db_update_failed: ' . $e->getMessage()]];
    }
    return ['status_id' => $statusId, 'errors' => []];
}
