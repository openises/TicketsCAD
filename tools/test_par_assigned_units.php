<?php
/**
 * Regression test for par_assigned_units() — Phase 27 hotfix 2026-06-12.
 *
 * Eric tried Initiate PAR on incident 152 and the cycle silently
 * captured zero units even though two were dispatched and on scene.
 * Root cause: par_assigned_units() used three wrong identifiers in its
 * SQL — assigns.responder (should be responder_id), responder.currstatus
 * (should be un_status_id), and unit_statuses table (should be un_status).
 * The JOIN matched zero rows on every install.
 *
 * This test:
 *   1. Confirms the schema names the function relies on still exist.
 *   2. Builds a throwaway ticket + assignment, calls the function, and
 *      verifies the unit comes back.
 *   3. Cleans up after itself.
 *
 * Usage: php tools/test_par_assigned_units.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';

echo "=== par_assigned_units() Regression Tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

function check_column(string $table, string $column): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            [$prefix . $table, $column]
        );
        return (bool) $row;
    } catch (Exception $e) {
        return false;
    }
}

function check_table(string $table): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_fetch_value("SELECT 1 FROM `{$prefix}{$table}` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function t(string $name, bool $ok) {
    global $pass, $fail;
    if ($ok) { echo "[PASS] {$name}\n"; $pass++; }
    else     { echo "[FAIL] {$name}\n"; $fail++; }
}

// ── 1. Required schema identifiers exist ──────────────────────────────
t('assigns.responder_id column exists',     check_column('assigns', 'responder_id'));
t('assigns.ticket_id column exists',        check_column('assigns', 'ticket_id'));
t('assigns.clear column exists',            check_column('assigns', 'clear'));
t('responder.un_status_id column exists',   check_column('responder', 'un_status_id'));
t('un_status table exists',                 check_table('un_status'));
t('un_status.status_val column exists',     check_column('un_status', 'status_val'));

// ── 2. End-to-end: assign a unit, call function, expect unit back ─────
$ticketId = 0;
$respId   = 0;
$assignId = 0;
try {
    // Build a sandbox ticket. in_types_id is NOT NULL without a default
    // on most installs — pick the first existing type.
    $typeId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}in_types` ORDER BY sort, id LIMIT 1"
    );
    if (!$typeId) $typeId = 1;
    db_query(
        "INSERT INTO `{$prefix}ticket` (scope, status, date, in_types_id, description)
         VALUES ('test-par-assigned-units', 2, NOW(), ?, 'test')",
        [$typeId]
    );
    $ticketId = (int) db_insert_id();

    // Pick or create a responder. Most installs have at least one;
    // if not, create a throwaway. description is NOT NULL with no
    // default — required by the schema.
    $respId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}responder`
          WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
          ORDER BY id LIMIT 1"
    );
    if (!$respId) {
        db_query(
            "INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id)
             VALUES ('TestPAR-Unit', 'TPU', 'temporary', 1)"
        );
        $respId = (int) db_insert_id();
    }

    // Assign — assigns.user_id is NOT NULL without default per CLAUDE.md
    db_query(
        "INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, dispatched)
         VALUES (?, ?, 1, NOW())",
        [$ticketId, $respId]
    );
    $assignId = (int) db_insert_id();

    // Force this responder onto a non-standby status so the
    // "recommended" behavior doesn't filter it out.
    $availableId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}un_status`
          WHERE LOWER(status_val) NOT LIKE '%standby%'
            AND LOWER(status_val) NOT LIKE '%avail%'
            AND LOWER(status_val) NOT LIKE '%staging%'
            AND LOWER(status_val) NOT LIKE '%reserve%'
            AND (hide IS NULL OR hide <> 'y')
          ORDER BY sort, id LIMIT 1"
    );
    if ($availableId === 0) $availableId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}un_status` ORDER BY sort, id LIMIT 1"
    );
    if ($availableId > 0) {
        db_query(
            "UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?",
            [$availableId, $respId]
        );
    }

    $units = par_assigned_units($ticketId);
    $found = false;
    foreach ($units as $u) {
        if ((int) $u['id'] === $respId) { $found = true; break; }
    }
    t('par_assigned_units returns the assigned unit', $found);

    // Clear the assignment; function should now return zero.
    db_query(
        "UPDATE `{$prefix}assigns` SET clear = NOW() WHERE id = ?",
        [$assignId]
    );
    $units = par_assigned_units($ticketId);
    $stillFound = false;
    foreach ($units as $u) {
        if ((int) $u['id'] === $respId) { $stillFound = true; break; }
    }
    t('par_assigned_units excludes cleared assignments', !$stillFound);

    // ── Phase 30A regression — par_due_at gating ──────────────────────
    // After clearing, this ticket has 0 assigned units.
    // par_due_at should return null regardless of cadence.
    if (par_enabled()) {
        $due = par_due_at($ticketId);
        t('par_due_at returns null when no units are assigned', $due === null);
    } else {
        echo "[SKIP] par_due_at gates (PAR features disabled — par_enabled=false)\n";
    }

    // Re-assign so we have a unit. par_due_at should still be null
    // because no explicit cadence override exists for this incident
    // OR its type (only system fallback). Eric on 2026-06-12:
    //   "incidents that don't appear to show any PAR cadence
    //   configured ... why am I seeing warnings?"
    if (par_enabled()) {
        db_query(
            "INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, dispatched)
             VALUES (?, ?, 1, NOW())",
            [$ticketId, $respId]
        );
        $cad = par_resolve_cadence($ticketId);
        if (in_array($cad['source'], ['settings_default','fallback'], true)) {
            $due = par_due_at($ticketId);
            t('par_due_at returns null when cadence source is settings_default/fallback',
              $due === null);
        } else {
            echo "[SKIP] settings_default/fallback gate (cadence already explicitly opted in: source=" . $cad['source'] . ")\n";
        }

        // Phase 32 (2026-06-12) — is_disabled gate.
        // Force the incident's type into "disabled" via par_config, then
        // assert par_resolve_cadence reports cadence_minutes=0 with
        // source='incident_type' AND par_due_at returns null even with
        // a positive ticket-override (which should NOT override a
        // type-disable for this test — actually it should because
        // incident_override is layer 1. So this test only asserts
        // the is_disabled path by removing the ticket override.).
        db_query(
            "UPDATE `{$prefix}ticket` SET par_cadence_override_min = NULL WHERE id = ?",
            [$ticketId]
        );
        $typeIdForDisable = (int) db_fetch_value(
            "SELECT in_types_id FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]
        );
        // Confirm is_disabled column exists; if not, skip.
        $hasIsDisabled = (bool) db_fetch_one(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = 'is_disabled' LIMIT 1",
            [$prefix . 'par_config']
        );
        if ($typeIdForDisable > 0 && $hasIsDisabled) {
            // Wipe any pre-existing row for this type so we start clean
            db_query(
                "DELETE FROM `{$prefix}par_config` WHERE scope='incident_type' AND in_types_id = ?",
                [$typeIdForDisable]
            );
            db_query(
                "INSERT INTO `{$prefix}par_config`
                    (scope, in_types_id, cadence_minutes, is_disabled, updated_at)
                 VALUES ('incident_type', ?, 0, 1, NOW())",
                [$typeIdForDisable]
            );
            $cadDisabled = par_resolve_cadence($ticketId);
            t('par_resolve_cadence reports cadence=0 source=incident_type when is_disabled=1',
              ((int) $cadDisabled['cadence_minutes']) === 0 && $cadDisabled['source'] === 'incident_type');
            $dueDisabled = par_due_at($ticketId);
            t('par_due_at returns null when type is explicitly disabled', $dueDisabled === null);

            // Cleanup the test row.
            db_query(
                "DELETE FROM `{$prefix}par_config` WHERE scope='incident_type' AND in_types_id = ?",
                [$typeIdForDisable]
            );
        } else {
            echo "[SKIP] is_disabled tests (column not present or no in_types_id)\n";
        }

        // Phase 31 (2026-06-12) — per-unit reset logic.
        // par_unit_last_activity_at should pick the latest of
        // dispatched/responding/on_scene that maps to a status with
        // resets_par=1. Force the test ticket into a known state:
        //   - dispatched at T-30min
        //   - responding at T-10min
        //   - on_scene at T-2min
        // The MAX of those (on_scene timestamp) should be returned.
        $hasResetsParCol = (bool) db_fetch_one(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = 'resets_par' LIMIT 1",
            [$prefix . 'un_status']
        );
        if ($hasResetsParCol && function_exists('par_unit_last_activity_at')) {
            // Clear any previous assigns rows for this ticket to make
            // the test deterministic
            db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]);
            $dispatchedAt = date('Y-m-d H:i:s', time() - 30 * 60);
            $respondingAt = date('Y-m-d H:i:s', time() - 10 * 60);
            $onSceneAt    = date('Y-m-d H:i:s', time() -  2 * 60);
            db_query(
                "INSERT INTO `{$prefix}assigns`
                    (ticket_id, responder_id, user_id, dispatched, responding, on_scene)
                 VALUES (?, ?, 1, ?, ?, ?)",
                [$ticketId, $respId, $dispatchedAt, $respondingAt, $onSceneAt]
            );
            $last = par_unit_last_activity_at($ticketId, $respId);
            $expected = strtotime($onSceneAt);
            t('par_unit_last_activity_at picks max(dispatched/responding/on_scene)',
              $last === $expected);

            // Now flip on_scene's resets_par flag OFF and assert the
            // returned timestamp drops to responding's.
            db_query(
                "UPDATE `{$prefix}un_status` SET resets_par = 0
                  WHERE incident_action = 'on_scene'"
            );
            $last2 = par_unit_last_activity_at($ticketId, $respId);
            $expected2 = strtotime($respondingAt);
            t('par_unit_last_activity_at honors resets_par=0 on un_status',
              $last2 === $expected2);

            // Restore the seed.
            db_query(
                "UPDATE `{$prefix}un_status` SET resets_par = 1
                  WHERE incident_action = 'on_scene'"
            );
        } else {
            echo "[SKIP] par_unit_last_activity_at tests (column or function not present)\n";
        }
    }
} catch (Exception $e) {
    echo "[FAIL] sandbox setup: " . $e->getMessage() . "\n";
    $fail++;
}

// ── 3. Cleanup ────────────────────────────────────────────────────────
if ($ticketId > 0) {
    try {
        db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?",          [$ticketId]);
    } catch (Exception $e) {}
}
// We intentionally don't delete the responder — it may have been pre-existing.

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
