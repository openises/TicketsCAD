<?php
/**
 * Phase 94 Stage 4g — Team write helpers.
 *
 * Extracted from api/teams.php's POST save + delete actions so both the
 * internal CSRF-checked endpoint and the external token-auth endpoint
 * call into the same write path. Caller does CSRF/bearer auth + RBAC —
 * this file just writes.
 *
 * Helpers:
 *   team_upsert_internal($input, $userId, $existingId = null)
 *     → ['id' => int, 'errors' => string[], 'is_new' => bool]
 *
 *   team_soft_delete_internal($id, $userId)
 *     → ['deleted' => bool, 'errors' => string[]]
 *
 * Note: teams use HARD delete (api/teams.php does `DELETE FROM teams`
 * after deleting team_members). There's no soft-delete column on the
 * teams table, so this helper preserves that semantics. The function
 * name keeps the *_soft_delete_* shape for caller consistency with
 * responder/facility/member helpers.
 *
 * Legacy DB column mapping (see api/teams.php header comment):
 *   teams.team        = name
 *   teams.mission     = description
 *   teams.ttypes_id   = team type id
 *   teams.leader      = leader member id
 *   teams.leader_dpty = deputy member id
 */

declare(strict_types=1);

/**
 * Upsert a team. If $existingId > 0 (or $input['id']), updates that row;
 * otherwise creates a new team.
 *
 * Required: name. All other fields optional.
 *
 * Also auto-promotes the chosen leader/deputy into team_members (matches
 * PRE-RELEASE-FIXES #18 in api/teams.php).
 *
 * @return array ['id' => int, 'errors' => string[], 'is_new' => bool]
 */
function team_upsert_internal(array $input, int $userId, ?int $existingId = null): array {
    $id = $existingId !== null ? (int) $existingId : (int) ($input['id'] ?? 0);
    $isNew = ($id <= 0);

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        return ['id' => 0, 'errors' => ['name is required'], 'is_new' => $isNew];
    }

    $description       = trim((string) ($input['description'] ?? ''));
    $teamTypeId        = !empty($input['team_type_id']) ? (int) $input['team_type_id'] : 0;
    $leaderId          = !empty($input['leader_id']) ? (int) $input['leader_id'] : 0;
    $deputyId          = !empty($input['deputy_id']) ? (int) $input['deputy_id'] : 0;
    $nimsResourceType  = trim((string) ($input['nims_resource_type'] ?? ''));
    $nimsTypingLevel   = !empty($input['nims_typing_level']) ? (int) $input['nims_typing_level'] : 0;
    $rtltCode          = trim((string) ($input['rtlt_code'] ?? ''));

    if (!$isNew) {
        $existing = db_fetch_one(
            "SELECT `id` FROM " . db_table('teams') . " WHERE `id` = ?",
            [$id]
        );
        if (!$existing) {
            return ['id' => 0, 'errors' => ['not_found'], 'is_new' => false];
        }

        try {
            db_query(
                "UPDATE " . db_table('teams') . "
                 SET `team` = ?, `mission` = ?, `ttypes_id` = ?, `leader` = ?, `leader_dpty` = ?,
                     `nims_resource_type` = ?, `nims_typing_level` = ?, `rtlt_code` = ?,
                     `updated_at` = NOW()
                 WHERE `id` = ?",
                [
                    $name, $description, $teamTypeId, $leaderId, $deputyId,
                    $nimsResourceType, $nimsTypingLevel, $rtltCode,
                    $id,
                ]
            );
        } catch (Exception $e) {
            // Phase 137 (2026-08-06) added a real UNIQUE KEY on teams.team --
            // translate the duplicate-key exception into the same friendly
            // shape as the 'not_found'/'name is required' cases above,
            // rather than letting the raw MySQL message reach the user.
            if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return ['id' => 0, 'errors' => ["A team named \"{$name}\" already exists"], 'is_new' => false];
            }
            throw $e;
        }
    } else {
        // INSERT — `sub-group`, `by`, `from`, `on` columns are legacy
        // NOT NULL with no default; supply empty/zero placeholders.
        try {
            db_query(
                "INSERT INTO " . db_table('teams') . "
                 (`team`, `sub-group`, `mission`, `ttypes_id`, `leader`, `leader_dpty`,
                  `nims_resource_type`, `nims_typing_level`, `rtlt_code`,
                  `formed`, `by`, `from`, `on`, `created_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, '', NOW(), NOW())",
                [
                    $name,
                    '',  // sub-group
                    $description,
                    $teamTypeId, $leaderId, $deputyId,
                    $nimsResourceType, $nimsTypingLevel, $rtltCode,
                    $userId,
                ]
            );
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return ['id' => 0, 'errors' => ["A team named \"{$name}\" already exists"], 'is_new' => true];
            }
            throw $e;
        }
        $id = (int) db_insert_id();
    }

    // Auto-promote leader/deputy into team_members (PRE-RELEASE-FIXES #18).
    foreach ([['Leader', $leaderId], ['Deputy', $deputyId]] as $pair) {
        $role = $pair[0]; $mid = $pair[1];
        if ($mid <= 0) continue;
        try {
            db_query(
                "INSERT INTO " . db_table('team_members') . "
                    (`team_id`, `member_id`, `role`, `assigned_date`)
                 VALUES (?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE `role` = VALUES(`role`)",
                [$id, $mid, $role]
            );
        } catch (Exception $e) {
            error_log("team_upsert_internal: failed to auto-add $role member $mid to team $id: " . $e->getMessage());
        }
    }

    return ['id' => $id, 'errors' => [], 'is_new' => $isNew];
}

/**
 * Delete a team. Hard delete (matches api/teams.php — there is no
 * deleted_at on teams). Cascades to team_members first.
 *
 * Function name keeps the _soft_delete_ shape for caller consistency
 * with responder/facility/member helpers, but the underlying delete is
 * a hard DELETE since legacy schema has no soft-delete column here.
 *
 * @return array ['deleted' => bool, 'errors' => string[]]
 */
function team_soft_delete_internal(int $id, int $userId): array {
    if ($id <= 0) {
        return ['deleted' => false, 'errors' => ['invalid_id']];
    }

    $existing = db_fetch_one(
        "SELECT `team` AS `name` FROM " . db_table('teams') . " WHERE `id` = ?",
        [$id]
    );
    if (!$existing) {
        return ['deleted' => false, 'errors' => ['not_found']];
    }

    try {
        db_query("DELETE FROM " . db_table('team_members') . " WHERE `team_id` = ?", [$id]);
    } catch (Exception $e) { /* non-fatal; teams DELETE will still try */ }

    db_query("DELETE FROM " . db_table('teams') . " WHERE `id` = ?", [$id]);

    return ['deleted' => true, 'errors' => [], 'name' => $existing['name']];
}

/**
 * GH#76 Phase 144 (2026-08-18) — add (or update the role of) a member on a
 * team. Extracted from api/teams.php's add_member action so the roster
 * page's Team Memberships card and any test can drive the SAME code the
 * Teams tab drives — identical SQL, identical ON DUPLICATE KEY UPDATE
 * upsert semantics (a caller adding an already-member updates their role
 * rather than erroring). This is the ONE new write path this phase's
 * design spec allows: the roster card calls api/teams.php's EXISTING
 * add_member action (unchanged endpoint, unchanged action.manage_teams
 * gate) — this extraction does not add a second gate or a second endpoint,
 * it just gives the existing one a directly-testable, directly-reusable
 * body, mirroring team_upsert_internal()/team_soft_delete_internal()'s own
 * extraction shape in this same file.
 *
 * $source: NULL (default) for a human coordinator's direct action (Teams
 * tab or the roster card); 'external_api' for the external-API compat
 * shim (api/external/v1/members.php). Never set to 'legacy_migration'
 * here — that value is written only by
 * sql/run_phase144_team_membership_unification.php's one-time backfill.
 *
 * @return array ['success' => bool, 'errors' => string[]]
 */
function team_add_member_internal(int $teamId, int $memberId, string $role = 'Member', ?string $posCode = null, ?string $source = null): array {
    if ($teamId <= 0 || $memberId <= 0) {
        return ['success' => false, 'errors' => ['missing team_id or member_id']];
    }
    try {
        db_query(
            "INSERT INTO " . db_table('team_members') . "
             (team_id, member_id, role, position_code, assigned_date, source)
             VALUES (?, ?, ?, ?, CURDATE(), ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), position_code = VALUES(position_code)",
            [$teamId, $memberId, $role, $posCode, $source]
        );
    } catch (Exception $e) {
        return ['success' => false, 'errors' => ['db_error: ' . $e->getMessage()]];
    }
    return ['success' => true, 'errors' => []];
}

/**
 * GH#76 Phase 144 (2026-08-18) — remove a member from a team by
 * team_members.id (the "assignment id"). Extracted from api/teams.php's
 * remove_member action — see team_add_member_internal()'s docblock for
 * why. Returns the removed row's team_id/member_id (needed for the
 * caller's audit_log() detail payload) since the row is gone after this
 * call.
 *
 * @return array ['success' => bool, 'team_id' => ?int, 'member_id' => ?int, 'errors' => string[]]
 */
function team_remove_member_internal(int $assignmentId): array {
    if ($assignmentId <= 0) {
        return ['success' => false, 'team_id' => null, 'member_id' => null, 'errors' => ['missing assignment_id']];
    }
    $tm = null;
    try {
        $tm = db_fetch_one(
            "SELECT team_id, member_id FROM " . db_table('team_members') . " WHERE id = ?",
            [$assignmentId]
        );
        db_query("DELETE FROM " . db_table('team_members') . " WHERE id = ?", [$assignmentId]);
    } catch (Exception $e) {
        return ['success' => false, 'team_id' => null, 'member_id' => null, 'errors' => ['db_error: ' . $e->getMessage()]];
    }
    return [
        'success'   => true,
        'team_id'   => $tm ? (int) $tm['team_id'] : null,
        'member_id' => $tm ? (int) $tm['member_id'] : null,
        'errors'    => [],
    ];
}
