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
    } else {
        // INSERT — `sub-group`, `by`, `from`, `on` columns are legacy
        // NOT NULL with no default; supply empty/zero placeholders.
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
