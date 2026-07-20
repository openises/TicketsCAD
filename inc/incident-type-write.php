<?php
/**
 * Phase 94 Stage 4i — Incident type (in_types) write helpers.
 *
 * Extracted from api/config-admin.php?section=types so both the
 * internal CSRF-checked endpoint and the new external token-auth
 * endpoint share the same upsert / delete logic. Caller does
 * CSRF/bearer auth + RBAC; this file just writes.
 *
 * Scope of v1: upsert (insert or update by id) + hard delete. PAR
 * cadence tri-state lives only on the internal endpoint — it's
 * coupled to the par_config table that the external API doesn't
 * surface in v1.
 *
 * Legacy schema notes:
 *   - `in_types.match_pattern` is a Phase 32-era column that not every
 *     install has. The upsert retries without it if the column is
 *     missing, matching api/config-admin.php's fallback path.
 *   - `in_types.group` is a reserved word in MySQL — backticked
 *     everywhere.
 */

declare(strict_types=1);

/**
 * Upsert an incident type. Pass $existingId to update; pass null to
 * create. Returns ['id' => N, 'created' => bool, 'errors' => []].
 *
 * Required field: type (the human-readable name)
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.manage_config') check
 *   - $_SESSION['user_id'] for audit attribution upstream
 *
 * @return array ['id' => int, 'created' => bool, 'errors' => string[]]
 */
function incident_type_upsert_internal(array $input, int $userId, ?int $existingId = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $typeName    = trim((string) ($input['type'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $protocol    = trim((string) ($input['protocol'] ?? ''));
    $severity    = (int) ($input['set_severity'] ?? 0);
    $group       = trim((string) ($input['group'] ?? ''));
    $radius      = (int) ($input['radius'] ?? 0);
    $color       = trim((string) ($input['color'] ?? '#0d6efd'));
    $sort        = (int) ($input['sort'] ?? 0);
    $pattern     = trim((string) ($input['match_pattern'] ?? ''));

    if ($typeName === '') {
        return ['id' => 0, 'created' => false, 'errors' => ['type_required']];
    }

    $isUpdate = $existingId !== null && $existingId > 0;

    try {
        if ($isUpdate) {
            $sql = "UPDATE `{$prefix}in_types` SET
                `type` = ?, `description` = ?, `protocol` = ?, `set_severity` = ?,
                `group` = ?, `radius` = ?, `color` = ?, `sort` = ?, `match_pattern` = ?
                WHERE `id` = ?";
            db_query($sql, [$typeName, $description, $protocol, $severity,
                            $group, $radius, $color, $sort, $pattern, $existingId]);
            $id = (int) $existingId;
        } else {
            $sql = "INSERT INTO `{$prefix}in_types`
                (`type`, `description`, `protocol`, `set_severity`,
                 `group`, `radius`, `color`, `sort`, `match_pattern`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            db_query($sql, [$typeName, $description, $protocol, $severity,
                            $group, $radius, $color, $sort, $pattern]);
            $id = (int) db_insert_id();
        }
    } catch (Exception $e) {
        // Pre-Phase-32 schema fallback: no match_pattern column. Retry
        // without it. Mirrors api/config-admin.php's same retry shape.
        if (strpos($e->getMessage(), 'match_pattern') !== false) {
            try {
                if ($isUpdate) {
                    $sql = "UPDATE `{$prefix}in_types` SET
                        `type` = ?, `description` = ?, `protocol` = ?, `set_severity` = ?,
                        `group` = ?, `radius` = ?, `color` = ?, `sort` = ?
                        WHERE `id` = ?";
                    db_query($sql, [$typeName, $description, $protocol, $severity,
                                    $group, $radius, $color, $sort, $existingId]);
                    $id = (int) $existingId;
                } else {
                    $sql = "INSERT INTO `{$prefix}in_types`
                        (`type`, `description`, `protocol`, `set_severity`,
                         `group`, `radius`, `color`, `sort`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    db_query($sql, [$typeName, $description, $protocol, $severity,
                                    $group, $radius, $color, $sort]);
                    $id = (int) db_insert_id();
                }
            } catch (Exception $e2) {
                return ['id' => 0, 'created' => false,
                        'errors' => ['db_save_failed: ' . $e2->getMessage()]];
            }
        } else {
            return ['id' => 0, 'created' => false,
                    'errors' => ['db_save_failed: ' . $e->getMessage()]];
        }
    }

    return ['id' => $id, 'created' => !$isUpdate, 'errors' => []];
}

/**
 * Hard-delete an incident type. Mirrors api/config-admin.php's
 * DELETE behaviour — in_types is config data, no soft-delete column
 * exists. Callers should not delete a type that's referenced by open
 * tickets; the FK isn't enforced at the schema level so the warning
 * is on the caller.
 *
 * @return array ['deleted' => bool, 'errors' => string[]]
 */
function incident_type_delete_internal(int $id, int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($id <= 0) {
        return ['deleted' => false, 'errors' => ['invalid_id']];
    }
    try {
        db_query("DELETE FROM `{$prefix}in_types` WHERE `id` = ?", [$id]);
    } catch (Exception $e) {
        return ['deleted' => false, 'errors' => ['db_delete_failed: ' . $e->getMessage()]];
    }
    return ['deleted' => true, 'errors' => []];
}
