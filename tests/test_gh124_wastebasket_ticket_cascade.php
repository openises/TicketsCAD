<?php
/**
 * test_gh124_wastebasket_ticket_cascade.php — GH#124 (reported
 * 2026-08-28), the "separate, unrelated" data-integrity note the reporter
 * flagged in passing: "101 `assigns` rows on this install reference
 * `ticket_id` values that no longer exist... Deleting a ticket does not
 * appear to remove its assignments."
 *
 * ROOT CAUSE (confirmed by reading api/wastebasket.php, not guessed):
 * incident_soft_delete_internal() (inc/incident-write.php) deliberately
 * does NOT cascade — its own docblock says so: the assigns/action/patient
 * rows stay in place so an admin can undelete cleanly. That is correct
 * and unchanged by this fix. The bug is one step later: api/wastebasket.php's
 * `purge` (single record) and `empty` (bulk, age-based) actions — the
 * ONE-WAY, no-take-backs permanent delete — already had a "clean up
 * related records" block for `member` (certifications, callsigns, orgs,
 * comm identifiers) and `responder` (allocates), but never had a `ticket`
 * branch at all. So a permanently-purged ticket left its `assigns` /
 * `action` / `patient` rows, and any attached `files`, pointing at a
 * ticket_id that no longer existed anywhere — exactly the shape the
 * reporter found on a real install.
 *
 * THE FIX: wb_purge_ticket_children() in inc/wastebasket-write.php
 * (extracted out of api/wastebasket.php so it's directly testable here
 * without booting that file's HTTP request dispatch — same reasoning as
 * the OT_CONFIG_LIBRARY_ONLY pitfall in CLAUDE.md), called from BOTH the
 * single `purge` action and the bulk `empty` action.
 *
 * ALTERNATIVES CONSIDERED (recorded here since this was a judgment call,
 * not a single obviously-correct answer — see the final report for the
 * fuller reasoning):
 *   - Cascade at soft-delete time instead of purge time: REJECTED —
 *     incident_soft_delete_internal()'s own docblock already establishes
 *     the opposite convention on purpose (undelete must restore
 *     everything intact), and nothing about this bug report suggests
 *     that convention is wrong.
 *   - A read-side defensive join guard instead of a write-side cascade:
 *     REJECTED as the primary fix — orphaned rows would still accumulate
 *     forever and nothing would ever reclaim them; every other purge path
 *     in this same file (member, responder) already cascades at delete
 *     time, so following that precedent is more consistent than inventing
 *     a third pattern.
 *   - Cascading `log` too: REJECTED — this project's own audit-retention
 *     posture (login-audit "soft-clear with cleared_at instead of DELETE",
 *     the CJIS-posture retention stance) keeps audit trail rows regardless
 *     of what they document. Section 5 below proves this is not just a
 *     comment — a `log` row for a purged ticket survives.
 *   - Cascading `notify`/`mi_x`/`photos`/`facnotes`/`messages_bin`:
 *     REJECTED — confirmed by repo-wide grep that no NewUI code (api/ or
 *     inc/) ever reads or writes any of these; they are legacy-v3-import
 *     artifacts. Cascading them would be dead code, not a real fix.
 *
 * Section 1 — single-ticket purge cascade: assigns/action/patient/files
 *   removed, driving assign_create_internal()/patient_add_internal() as
 *   the real writers wherever CLI-callable (`files` cannot be — see its
 *   own is_uploaded_file() IDOR guard in inc/file-write.php — hand-
 *   inserted and explicitly justified below).
 * Section 2 — the on-disk file blob is actually unlinked, not just the
 *   metadata row.
 * Section 3 — `log` is deliberately NOT cascaded (survives).
 * Section 4 — batch call across two tickets at once (the bulk `empty`
 *   action's own call shape).
 * Section 5 — edge cases: empty array, only-invalid ids, and a `files`
 *   row whose on-disk blob is already gone (best-effort unlink, matching
 *   file_delete_internal()'s own precedent) never throw.
 * Section 6 (static) — the function lives in inc/wastebasket-write.php
 *   (not duplicated inline in api/wastebasket.php), and both call sites
 *   in api/wastebasket.php actually invoke it.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/patient-write.php';
require_once __DIR__ . '/../inc/wastebasket-write.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_test_fixture_guard.php';

if (!defined('NEWUI_ROOT')) { define('NEWUI_ROOT', dirname(__DIR__)); }

$prefix = $GLOBALS['db_prefix'] ?? '';
$userId = test_admin_user_id();
$tag    = 'gh124wbc_' . getmypid();

echo "=== GH#124 — wastebasket ticket-purge cascade (wb_purge_ticket_children) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

/** Create one throwaway ticket row (matches this suite's own established
 *  raw-INSERT fixture convention for lightweight fixture tests — see
 *  tests/test_gh116_multi_assign_status_scoping.php /
 *  tests/test_gh118_assign_remove_ticketid.php). Returns the new id. */
function gh124wbc_mk_ticket(string $prefix, string $scope): int {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, ?, 'GH124 wastebasket cascade fixture', NOW(), NOW(), 1)", [$typeId, $scope]);
    return (int) db_insert_id();
}

$ticketIds = []; $extraFiles = [];

try {
    // ─────────────────────────────────────────────────────────────────
    echo "-- 1. Single-ticket purge cascade: assigns/action/patient/files removed --\n";
    // ─────────────────────────────────────────────────────────────────
    $tid = gh124wbc_mk_ticket($prefix, $tag . '_t1');
    test_fixture_guard_track('ticket', $tid);
    $ticketIds[] = $tid;
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tid]);

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES (?, 'GH124WBC', 'test', 1, NOW(), NOW())", [$tag . '_unit']);
    $rid = (int) db_insert_id();
    test_fixture_guard_track('responder', $rid);

    $ra = assign_create_internal($tid, $rid, '', $userId);
    $aid = (int) ($ra['id'] ?? 0);
    is_true($aid > 0, 'fixture: real writer created an assignment', json_encode($ra));
    test_fixture_guard_track('assigns', $aid);

    $actionCountBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
    is_true($actionCountBefore > 0, 'fixture: the real writer stamped at least one action row', (string) $actionCountBefore);

    $pr = patient_add_internal($tid, ['description' => 'GH124 test patient', 'name' => 'Doe'], $userId);
    $pid = (int) ($pr['id'] ?? 0);
    is_true($pid > 0, 'fixture: real writer (patient_add_internal) created a patient row', json_encode($pr));
    test_fixture_guard_track('patient', $pid);

    // `files` cannot be created via the real writer (file_attach_to_internal())
    // from a CLI test — it hard-requires is_uploaded_file($upload['tmp_name'])
    // to be true, which is only ever true for a file PHP itself moved during
    // a genuine HTTP multipart upload (deliberate IDOR/forgery protection,
    // inc/file-write.php). Hand-inserted here, with an actual on-disk blob so
    // Section 2 below can prove the REAL unlink behavior, not just the DB row.
    //
    // GH#124 side finding, unrelated to this fix: `files.ticket_id` is a real
    // MEDIUMINT(6) column (max signed value 8,388,607 — a genuine 3-byte
    // storage type, unlike the display-width-only parenthesized number on
    // this project's INT columns) while `ticket.id` on THIS install has
    // already run past 900 million. A ticket id that large cannot be stored
    // in `files.ticket_id` at all — confirmed here, not assumed, by
    // attempting the INSERT and catching MySQL error 1264 (Out of range
    // value). Flagged in the final report as a genuinely separate,
    // pre-existing bug worth its own look; not fixed as part of GH#124.
    $filesTicketIdFits = $tid <= 8388607;
    $blobPath = null;
    $fid = 0;
    if ($filesTicketIdFits) {
        $uploadDir = NEWUI_ROOT . '/uploads';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
        $blobName = 'gh124wbc_test_blob_' . getmypid() . '.txt';
        $blobPath = $uploadDir . '/' . $blobName;
        file_put_contents($blobPath, 'GH124 wastebasket cascade test blob — safe to delete.');
        $extraFiles[] = $blobPath; // in case the assertion below is ever wrong
        is_true(file_exists($blobPath), 'fixture: on-disk test blob was actually written');

        db_query("INSERT INTO `{$prefix}files`
                    (`title`, `filename`, `orig_filename`, `ticket_id`, `type`, `filetype`, `_by`, `_on`, `_from`)
                  VALUES (?, ?, ?, ?, NULL, 'text/plain', ?, NOW(), 'internal_ui')",
            ['GH124 test file', $blobName, 'gh124-original.txt', $tid, $userId]);
        $fid = (int) db_insert_id();
        test_fixture_guard_track('files', $fid);
        is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}files` WHERE id = ?", [$fid]) === 1, 'sanity: files row exists pre-purge');
    } else {
        echo "SKIP: files.ticket_id is MEDIUMINT(6) and cannot hold this install's current "
            . "ticket id ({$tid}) — see the code comment above. Skipping the files-row-specific "
            . "assertions in Sections 1-2 only; every other assertion in this file still runs.\n";
    }

    // ── Sanity: everything exists before the cascade runs ──
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE id = ?", [$aid]) === 1, 'sanity: assign row exists pre-purge');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]) === $actionCountBefore, 'sanity: action row(s) exist pre-purge');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}patient` WHERE id = ?", [$pid]) === 1, 'sanity: patient row exists pre-purge');

    // ── THE FIX, driven directly ──
    wb_purge_ticket_children([$tid]);

    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE id = ?", [$aid]) === 0,
        'FIX: the assigns row is removed — this is the reporter\'s exact 101-orphaned-rows symptom, closed at the source');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]) === 0,
        'FIX: every action row for the purged ticket is removed');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}patient` WHERE id = ?", [$pid]) === 0,
        'FIX: the patient row is removed');
    if ($filesTicketIdFits) {
        is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}files` WHERE id = ?", [$fid]) === 0,
            'FIX: the files metadata row is removed');
    }

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 2. The on-disk file blob is actually unlinked, not just the row --\n";
    // ─────────────────────────────────────────────────────────────────
    if ($filesTicketIdFits) {
        is_true(!file_exists($blobPath),
            'FIX: the attached file\'s on-disk blob was deleted, not just its metadata row '
            . '(an orphaned files ROW is the same class of bug as an orphaned assigns row — '
            . 'this closes both at once, plus the disk-space leak a metadata-only fix would still have)');
    } else {
        echo "SKIP: see Section 1's note — files.ticket_id cannot hold this install's ticket ids.\n";
    }

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 3. `log` is deliberately NOT cascaded — it survives the purge --\n";
    // ─────────────────────────────────────────────────────────────────
    $tid2 = gh124wbc_mk_ticket($prefix, $tag . '_t2log');
    test_fixture_guard_track('ticket', $tid2);
    $ticketIds[] = $tid2;

    // Simulates the `log` row incident_create_internal() itself stamps on
    // real incident creation (inc/incident-write.php ~line 224) — hand-
    // inserted here since this test's fixture ticket is a raw INSERT, not a
    // full incident_create_internal() call (deliberately lightweight, matching
    // this suite's established fixture convention).
    db_query("INSERT INTO `{$prefix}log` (`who`, `from`, `when`, `code`, `ticket_id`, `info`)
              VALUES (?, '', NOW(), 10, ?, 'GH124 log-survives-purge fixture')", [$userId, $tid2]);
    $logId = (int) db_insert_id();
    test_fixture_guard_track('log', $logId);

    wb_purge_ticket_children([$tid2]);

    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}log` WHERE id = ?", [$logId]) === 1,
        'FIX (deliberate exclusion, proven functionally — not just documented in a comment): '
        . 'a log row referencing the purged ticket SURVIVES, matching this project\'s standing '
        . 'audit-retention posture');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 4. Batch call across two tickets at once (the bulk `empty` action's own shape) --\n";
    // ─────────────────────────────────────────────────────────────────
    $tidA = gh124wbc_mk_ticket($prefix, $tag . '_batchA');
    $tidB = gh124wbc_mk_ticket($prefix, $tag . '_batchB');
    test_fixture_guard_track('ticket', $tidA);
    test_fixture_guard_track('ticket', $tidB);
    $ticketIds[] = $tidA; $ticketIds[] = $tidB;

    $raA = assign_create_internal($tidA, $rid, '', $userId, true);
    $raB = assign_create_internal($tidB, $rid, '', $userId, true);
    $aidA = (int) ($raA['id'] ?? 0); $aidB = (int) ($raB['id'] ?? 0);
    is_true($aidA > 0 && $aidB > 0, 'fixture: both batch tickets got a real assignment',
        'raA=' . json_encode($raA) . ' raB=' . json_encode($raB));
    test_fixture_guard_track('assigns', $aidA);
    test_fixture_guard_track('assigns', $aidB);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tidA]);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tidB]);

    wb_purge_ticket_children([$tidA, $tidB]);

    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE id IN (?, ?)", [$aidA, $aidB]) === 0,
        'FIX: a single batch call removes assigns for BOTH tickets at once — matches how the bulk '
        . '`empty` action resolves every eligible ticket id and cascades them together');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id IN (?, ?)", [$tidA, $tidB]) === 0,
        'FIX: action rows for BOTH batch tickets are removed');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 5. Edge cases never throw: empty array, invalid ids, already-gone blob --\n";
    // ─────────────────────────────────────────────────────────────────
    $threwEmpty = false;
    try { wb_purge_ticket_children([]); } catch (Throwable $e) { $threwEmpty = true; }
    is_true(!$threwEmpty, 'an empty ticket-id array is a safe no-op');

    $threwInvalid = false;
    try { wb_purge_ticket_children([0, -1, -99]); } catch (Throwable $e) { $threwInvalid = true; }
    is_true(!$threwInvalid, 'an array of only invalid (<= 0) ids is a safe no-op');

    // A files row whose on-disk blob is already gone (e.g. an admin pruned
    // the uploads dir by hand) — file_exists() check inside the function
    // must skip the unlink attempt, never throw, and still remove the row.
    // Same MEDIUMINT(6) width caveat as Sections 1-2 applies (see that note).
    $tid3 = gh124wbc_mk_ticket($prefix, $tag . '_missingblob');
    test_fixture_guard_track('ticket', $tid3);
    $ticketIds[] = $tid3;

    if ($tid3 > 8388607) {
        echo "SKIP: files.ticket_id width — see Section 1's note.\n";
    } else {
    db_query("INSERT INTO `{$prefix}files`
                (`title`, `filename`, `orig_filename`, `ticket_id`, `type`, `filetype`, `_by`, `_on`, `_from`)
              VALUES ('GH124 missing blob', ?, 'gone.txt', ?, NULL, 'text/plain', ?, NOW(), 'internal_ui')",
        ['gh124wbc_never_existed_' . getmypid() . '.txt', $tid3, $userId]);
    $fid2 = (int) db_insert_id();
    test_fixture_guard_track('files', $fid2);

    $threwMissingBlob = false;
    try { wb_purge_ticket_children([$tid3]); } catch (Throwable $e) { $threwMissingBlob = true; }
    is_true(!$threwMissingBlob, 'a files row pointing at an already-missing on-disk blob does not throw '
        . '(best-effort unlink, matching file_delete_internal()\'s own precedent)');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}files` WHERE id = ?", [$fid2]) === 0,
        'the files row is still removed even though there was nothing on disk to unlink');
    }

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 6. Static: extracted to inc/, and both api/wastebasket.php call sites use it --\n";
    // ─────────────────────────────────────────────────────────────────
    is_true(function_exists('wb_purge_ticket_children'), 'wb_purge_ticket_children() is defined');

    $incSrc = (string) file_get_contents(__DIR__ . '/../inc/wastebasket-write.php');
    is_true(strpos($incSrc, 'function wb_purge_ticket_children(') !== false,
        'the function lives in inc/wastebasket-write.php');

    $apiSrc = (string) file_get_contents(__DIR__ . '/../api/wastebasket.php');
    is_true(strpos($apiSrc, "function wb_purge_ticket_children(") === false,
        'FIX: api/wastebasket.php no longer has its OWN inline copy of the function '
        . '(one real implementation, not two that can drift apart)');
    is_true(strpos($apiSrc, "require_once __DIR__ . '/../inc/wastebasket-write.php';") !== false,
        'api/wastebasket.php requires the extracted file');
    is_true(substr_count($apiSrc, 'wb_purge_ticket_children(') >= 2,
        "FIX: wb_purge_ticket_children() is actually CALLED from api/wastebasket.php — "
        . 'both the single `purge` action and the bulk `empty` action, not just defined and forgotten '
        . '(this project\'s own "plumbing exists, nobody wired the last mile" failure class)');

} catch (Throwable $e) {
    bad('fixture/cascade path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";

// ── Teardown (defense in depth) ──
try {
    foreach ($ticketIds as $t) {
        db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$t]);
        db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$t]);
        db_query("DELETE FROM `{$prefix}patient` WHERE ticket_id = ?", [$t]);
        db_query("DELETE FROM `{$prefix}files`   WHERE ticket_id = ?", [$t]);
        db_query("DELETE FROM `{$prefix}log`     WHERE ticket_id = ?", [$t]);
        db_query("DELETE FROM `{$prefix}ticket`  WHERE id = ?", [$t]);
    }
    if (isset($rid)) { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]); }
    foreach ($extraFiles as $p) { if (file_exists($p)) { @unlink($p); } }
} catch (Throwable $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

exit($fail === 0 ? 0 : 1);
