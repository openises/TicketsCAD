<?php
/**
 * Phase 94 Stage 4f — Facility write helpers.
 *
 * Extracted from api/facility-save.php so both the internal CSRF-checked
 * endpoint and the external token-auth endpoint call into the same write
 * path. Caller does CSRF/bearer auth + RBAC — this file just writes.
 *
 * Helpers:
 *   facility_upsert_internal($input, $userId, $existingId = null)
 *     → ['id' => int, 'errors' => string[], 'is_new' => bool]
 *
 *   facility_soft_delete_internal($id, $userId)
 *     → ['deleted' => bool, 'errors' => string[]]
 *
 * Soft-delete sets `hide = 1` (legacy convention used by api/facility-save.php
 * DELETE handler). No deleted_at column exists on facilities — wastebasket
 * for this table is the hide column.
 */

declare(strict_types=1);

/**
 * Upsert a facility. If $existingId > 0 (or $input['id']), updates that
 * row; otherwise creates a new facility.
 *
 * Required for create + update: name, description.
 *
 * @return array ['id' => int, 'errors' => string[], 'is_new' => bool]
 */
function facility_upsert_internal(array $input, int $userId, ?int $existingId = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $id = $existingId !== null ? (int) $existingId : (int) ($input['id'] ?? 0);
    $isNew = ($id <= 0);

    $name        = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $errors = [];
    if ($name === '')        $errors[] = 'name is required';
    if ($description === '') $errors[] = 'description is required';
    if ($errors) return ['id' => 0, 'errors' => $errors, 'is_new' => $isNew];

    $handle        = trim((string) ($input['handle'] ?? ''));
    $callsign      = trim((string) ($input['callsign'] ?? ''));
    $street        = trim((string) ($input['street'] ?? ''));
    $city          = trim((string) ($input['city'] ?? ''));
    $state         = trim((string) ($input['state'] ?? ''));
    // Accept both `type_id` (modern, used by facility-save.php) and `type`
    // (the actual DB column name — gives integrators a choice).
    $type          = (int) ($input['type_id'] ?? $input['type'] ?? 0);
    $status_id     = (int) ($input['status_id'] ?? 0);
    $contact_name  = trim((string) ($input['contact_name'] ?? ''));
    $contact_email = trim((string) ($input['contact_email'] ?? ''));
    $contact_phone = trim((string) ($input['contact_phone'] ?? ''));
    $capab         = trim((string) ($input['capab'] ?? ''));
    $beds_a        = (int) ($input['beds_a'] ?? 0);
    $beds_o        = (int) ($input['beds_o'] ?? 0);
    $beds_info     = trim((string) ($input['beds_info'] ?? ''));
    // Phase 103 (a beta tester GH #20) — bed-count automation mode.
    // Accepts 'manual' or 'auto'; anything else falls back to 'manual'
    // so bad input can't disable a facility admin's manual counters.
    $bed_auto_mode = strtolower(trim((string) ($input['bed_auto_mode'] ?? 'manual')));
    if (!in_array($bed_auto_mode, ['manual', 'auto'], true)) $bed_auto_mode = 'manual';
    $status_about  = trim((string) ($input['status_about'] ?? ''));

    $lat = (isset($input['lat']) && $input['lat'] !== '') ? (float) $input['lat'] : null;
    $lng = (isset($input['lng']) && $input['lng'] !== '') ? (float) $input['lng'] : null;

    $now = date('Y-m-d H:i:s');

    // Phase 103 — ensure the bed_auto_mode column exists on installs
    // that haven't run sql/phase-103-facility-bed-automation.sql yet.
    // Self-healing to keep this shippable without a hard migration step.
    _facility_ensure_bed_auto_column($prefix);

    if (!$isNew) {
        $existing = db_fetch_one(
            "SELECT `id` FROM `{$prefix}facilities` WHERE `id` = ?",
            [$id]
        );
        if (!$existing) {
            return ['id' => 0, 'errors' => ['not_found'], 'is_new' => false];
        }

        db_query(
            "UPDATE `{$prefix}facilities` SET
                `name` = ?, `handle` = ?, `callsign` = ?, `description` = ?,
                `street` = ?, `city` = ?, `state` = ?,
                `lat` = ?, `lng` = ?,
                `type` = ?, `status_id` = ?,
                `contact_name` = ?, `contact_email` = ?, `contact_phone` = ?,
                `capab` = ?, `beds_a` = ?, `beds_o` = ?, `beds_info` = ?,
                `bed_auto_mode` = ?, `status_about` = ?,
                `updated` = ?, `_by` = ?, `_on` = ?
             WHERE `id` = ?",
            [
                $name, $handle, $callsign, $description,
                $street, $city, $state, $lat, $lng,
                $type, $status_id,
                $contact_name, $contact_email, $contact_phone,
                $capab, $beds_a, $beds_o, $beds_info,
                $bed_auto_mode, $status_about,
                $now, $userId, $now,
                $id,
            ]
        );

        return ['id' => $id, 'errors' => [], 'is_new' => false];
    }

    // INSERT
    db_query(
        "INSERT INTO `{$prefix}facilities`
            (`name`, `handle`, `callsign`, `description`,
             `street`, `city`, `state`, `lat`, `lng`,
             `type`, `status_id`,
             `contact_name`, `contact_email`, `contact_phone`,
             `capab`, `beds_a`, `beds_o`, `beds_info`,
             `bed_auto_mode`, `status_about`,
             `updated`, `_by`, `_on`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $name, $handle, $callsign, $description,
            $street, $city, $state, $lat, $lng,
            $type, $status_id,
            $contact_name, $contact_email, $contact_phone,
            $capab, $beds_a, $beds_o, $beds_info,
            $bed_auto_mode, $status_about,
            $now, $userId, $now,
        ]
    );
    $newId = (int) db_insert_id();
    return ['id' => $newId, 'errors' => [], 'is_new' => true];
}

/**
 * Phase 103 — add facilities.bed_auto_mode on installs that haven't
 * run the Phase 103 migration. Cached in a static so we do at most one
 * SHOW COLUMNS per request.
 */
function _facility_ensure_bed_auto_column(string $prefix): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}facilities` LIKE 'bed_auto_mode'");
        if (empty($cols)) {
            db_query("ALTER TABLE `{$prefix}facilities`
                      ADD COLUMN `bed_auto_mode` VARCHAR(16) NOT NULL DEFAULT 'manual'
                          COMMENT 'Bed-count automation: manual | auto'
                      AFTER `beds_info`");
        }
        $ensured = true;
    } catch (Exception $e) {
        // Locked-down grants may deny SHOW COLUMNS / ALTER; the outer
        // INSERT/UPDATE will still fail cleanly if the column really
        // doesn't exist, and the audit trail captures the DB error.
        error_log('[facility-write] bed_auto_mode ensure failed: ' . $e->getMessage());
    }
}

/**
 * Soft-delete a facility. Newer installs have `deleted_at` / `deleted_by`
 * columns (the wastebasket pattern); the legacy api/facility-save.php
 * DELETE handler uses `hide=1` as its soft-delete convention. We support
 * both — try deleted_at first (modern), fall back to hide on column-
 * not-found errors.
 *
 * @return array ['deleted' => bool, 'errors' => string[]]
 */
function facility_soft_delete_internal(int $id, int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($id <= 0) {
        return ['deleted' => false, 'errors' => ['invalid_id']];
    }

    $existing = db_fetch_one(
        "SELECT `id`, `name` FROM `{$prefix}facilities` WHERE `id` = ?",
        [$id]
    );
    if (!$existing) {
        return ['deleted' => false, 'errors' => ['not_found']];
    }

    try {
        db_query(
            "UPDATE `{$prefix}facilities`
             SET `deleted_at` = NOW(), `deleted_by` = ?, `updated` = NOW()
             WHERE `id` = ?",
            [$userId, $id]
        );
    } catch (Exception $modErr) {
        $msg = $modErr->getMessage();
        if (strpos($msg, 'deleted_at') !== false || strpos($msg, 'Unknown column') !== false) {
            // Legacy install: no deleted_at column — fall back to hide=1.
            db_query(
                "UPDATE `{$prefix}facilities` SET `hide` = 1, `updated` = NOW() WHERE `id` = ?",
                [$id]
            );
        } else {
            throw $modErr;
        }
    }

    return ['deleted' => true, 'errors' => [], 'name' => $existing['name']];
}
