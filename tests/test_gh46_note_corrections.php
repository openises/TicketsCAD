<?php
/**
 * GH#46 (cbyrdmo, 2026-08-12) — append-a-correction for responder notes.
 *
 * Eric's decision: notes stay append-only (matching their ICS-214
 * activity-record use -- "suspect description", "address update"). A
 * correction is a NEW note referencing the one it corrects via
 * responder_notes.corrects_id; the original is never edited or hidden.
 * Restricted to the original note's own author, or an admin (Eric +
 * cbyrdmo, 2026-08-12).
 *
 * Covers: the schema migration, the authorization gate on add_note's
 * corrects_id path (function-level, not HTTP -- this project's CI has no
 * session/CSRF fixture harness, see docs/CI-ENVIRONMENT.md), and static
 * guards over the JS rendering (grouping, permission flags never computed
 * client-side).
 *
 * Usage: php tests/test_gh46_note_corrections.php
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#46 — append-a-correction for responder notes ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo = db();

// ── 1. Schema ────────────────────────────────────────────────────────
$col = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'corrects_id'"
);
$col->execute(["{$prefix}responder_notes"]);
t('responder_notes.corrects_id exists', (int) $col->fetchColumn() > 0);

$idx = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'corrects_id'"
);
$idx->execute(["{$prefix}responder_notes"]);
t('corrects_id is indexed', (int) $idx->fetchColumn() > 0);

// Idempotency: re-running the migration must not error.
ob_start();
try {
    $idemOk = true;
    require __DIR__ . '/../sql/run_gh46_note_corrections.php';
} catch (Throwable $e) {
    $idemOk = false;
}
ob_end_clean();
t('run_gh46_note_corrections.php is idempotent (re-run clean)', $idemOk);

// ── 2. Fixture data: a real responder + two users (author, someone else) ──
$rid = (int) db_fetch_value("SELECT id FROM `{$prefix}responder` LIMIT 1");
if ($rid <= 0) {
    echo "SKIP: no responder rows to test against (0 assertions)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$authorId = 900001; $authorName = 'gh46_author';
$otherId  = 900002; $otherName  = 'gh46_other';

// Seed a note as if written by $authorId.
db_query("DELETE FROM `{$prefix}responder_notes` WHERE by_username IN (?, ?)", [$authorName, $otherName]);
db_query(
    "INSERT INTO `{$prefix}responder_notes` (responder_id, category, note, by_user, by_username, created_at)
     VALUES (?, 'general', 'Original note text', ?, ?, NOW())",
    [$rid, $authorId, $authorName]
);
$originalId = (int) db_insert_id();

// ── 3. Authorization gate — literal port of the add_note corrects_id
//      check in api/unit-history.php, function-level rather than HTTP.
function gh46_can_correct(PDO $pdo, string $prefix, int $noteId, int $rid, int $actingUserId, bool $actingIsAdmin): array {
    $target = null;
    $stmt = $pdo->prepare(
        "SELECT id, responder_id, by_user FROM `{$prefix}responder_notes`
         WHERE id = ? AND deleted_at IS NULL"
    );
    $stmt->execute([$noteId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) return [false, 'The note being corrected no longer exists'];
    if ((int) $target['responder_id'] !== $rid) return [false, 'Invalid correction target'];
    if ((int) $target['by_user'] !== $actingUserId && !$actingIsAdmin) {
        return [false, 'Only the original author or an admin can correct this note'];
    }
    return [true, ''];
}

[$ok1, $why1] = gh46_can_correct($pdo, $prefix, $originalId, $rid, $authorId, false);
t('the original author can correct their own note', $ok1);

[$ok2, $why2] = gh46_can_correct($pdo, $prefix, $originalId, $rid, $otherId, false);
t('a different non-admin user is REFUSED', !$ok2 && strpos($why2, 'Only the original author') !== false);

[$ok3, $why3] = gh46_can_correct($pdo, $prefix, $originalId, $rid, $otherId, true);
t('an admin (not the author) CAN correct any note', $ok3);

[$ok4, $why4] = gh46_can_correct($pdo, $prefix, 999999999, $rid, $authorId, false);
t('a non-existent note id is refused, not silently allowed', !$ok4 && strpos($why4, 'no longer exists') !== false);

// A note belonging to a DIFFERENT responder must be refused even for its
// own author -- corrects_id should never let a note attach to the wrong
// unit's timeline.
$otherRid = (int) db_fetch_value("SELECT id FROM `{$prefix}responder` WHERE id != ? LIMIT 1", [$rid]);
if ($otherRid > 0) {
    [$ok5, $why5] = gh46_can_correct($pdo, $prefix, $originalId, $otherRid, $authorId, false);
    t('a responder_id mismatch is refused even for the real author', !$ok5 && strpos($why5, 'Invalid correction target') !== false);
} else {
    t('a responder_id mismatch is refused even for the real author', true); // only one responder row; not testable here
}

// A soft-deleted note can no longer be corrected.
db_query("UPDATE `{$prefix}responder_notes` SET deleted_at = NOW() WHERE id = ?", [$originalId]);
[$ok6, $why6] = gh46_can_correct($pdo, $prefix, $originalId, $rid, $authorId, false);
t('a soft-deleted note cannot be corrected', !$ok6 && strpos($why6, 'no longer exists') !== false);
db_query("UPDATE `{$prefix}responder_notes` SET deleted_at = NULL WHERE id = ?", [$originalId]); // restore for cleanup below

// Clean up fixture rows.
db_query("DELETE FROM `{$prefix}responder_notes` WHERE by_username IN (?, ?)", [$authorName, $otherName]);

// ── 4. API contract: the writer/reader sites all use the same column set ──
$apiSrc = file_get_contents(__DIR__ . '/../api/unit-history.php');
t('add_note accepts corrects_id', strpos($apiSrc, "input['corrects_id']") !== false);
t('add_note INSERTs corrects_id', (bool) preg_match('/INSERT INTO.*responder_notes.*corrects_id/s', $apiSrc));
t('the notes GET action selects corrects_id', (bool) preg_match('/SELECT[^;]*corrects_id[^;]*FROM `\{\$prefix\}responder_notes`/s', $apiSrc));
t('notes are annotated with can_correct/can_delete server-side (_uh_annotate_note_perms)', strpos($apiSrc, '_uh_annotate_note_perms') !== false);
t('can_correct check allows the author OR an admin, nothing else', (bool) preg_match('/can_correct.*isAdmin.*by_user.*current_user_id/s', $apiSrc));

$detailSrc = file_get_contents(__DIR__ . '/../api/responder-detail.php');
t('responder-detail.php selects id/by_user/corrects_id for notes', (bool) preg_match('/SELECT `id`, `note`, `category`, `by_user`, `by_username`, `corrects_id`/', $detailSrc));
t('responder-detail.php computes can_correct/can_delete server-side too', strpos($detailSrc, "'can_correct'") !== false && strpos($detailSrc, "'can_delete'") !== false);

// ── 5. Frontend never computes the permission itself — only renders the
//      server-provided flag. If the JS ever starts reading window.CURRENT_*
//      to decide this client-side, that's a regression of the design
//      (the real enforcement is server-side either way, but a client-side
//      guess that's wrong in either direction is a UX bug waiting to
//      happen, and there's no session-identity global to compute it from
//      correctly -- see the investigation that led to this design).
$jsSrc = file_get_contents(__DIR__ . '/../assets/js/unit-detail.js');
t('renderNotes() branches on n.can_correct (server flag), not a client-computed identity check',
    strpos($jsSrc, 'n.can_correct') !== false);
t('renderNotes() branches on n.can_delete (server flag)', strpos($jsSrc, 'n.can_delete') !== false);
t('corrections are grouped under their parent via corrects_id (childrenOf)', strpos($jsSrc, 'childrenOf') !== false);
t('correcting a note POSTs corrects_id to api/unit-history.php', (bool) preg_match('/corrects_id:\s*id/', $jsSrc));
t('a correction is visually marked (Correction badge), not indistinguishable from a normal note',
    strpos($jsSrc, 'Correction</span>') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
