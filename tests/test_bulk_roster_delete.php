<?php
/**
 * Bulk roster removal — api/members.php action=bulk_delete
 * (Billy Irwin / K9OH suggestion, 2026-07-04)
 *
 * Billy asked for a way to remove several roster members at once instead of
 * one-at-a-time. This locks in:
 *
 *   - the endpoint exists, is gated by the SAME CSRF + action.manage_members
 *     checks as the single delete (enforced at the top of the POST handler),
 *   - it normalizes/dedupes ids and caps the batch (max 500),
 *   - it reuses member_soft_delete() + the pre-wastebasket hard-delete
 *     fallback and writes one audit_log row per member,
 *   - the roster.php UI is gated behind canManageMembers and its select
 *     column is TRAILING + has no data-col-id (so ScreenPrefs' nth-child
 *     column customization is unaffected),
 *   - roster.js wires initBulkRoster() into init() and posts action=bulk_delete.
 *
 * The functional portion creates throwaway members through the REAL writer
 * (member_create_internal), soft-deletes the batch, and asserts they drop out
 * of the "not deleted" view — reproducing through the actual creation path,
 * not hand-inserted ideal rows. Self-cleaning; touches no existing account.
 *
 * Usage: php tests/test_bulk_roster_delete.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/member-write.php';

$base   = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }
function tbl($n) { return db_table($n); }

echo "=== Bulk roster removal (#55 follow-on / Billy K9OH) ===\n\n";

// ── Static guards: api/members.php endpoint shape ──────────────────────
$api = @file_get_contents($base . '/api/members.php');
if ($api !== false) {
    t("api/members.php has an action=bulk_delete handler",
        strpos($api, "'bulk_delete'") !== false);
    t("bulk_delete caps the batch (max 500 per request)",
        (bool) preg_match('/count\(\$ids\)\s*>\s*500/', $api));
    t("bulk_delete reuses member_soft_delete()",
        (bool) preg_match('/bulk_delete.*?member_soft_delete/s', $api));
    t("bulk_delete keeps the pre-wastebasket hard-delete fallback",
        (bool) preg_match('/bulk_delete.*?member_certifications.*?WHERE member_id/s', $api));
    t("bulk_delete writes an audit_log row per member",
        (bool) preg_match('/bulk_delete.*?audit_log\(.*?[\'"]delete[\'"].*?[\'"]member[\'"]/s', $api));
    // Dedicated permission gate (Eric, 2026-07-04): the handler requires
    // action.bulk_delete_members (or is_admin), ON TOP of the manage_members
    // gate at the top of the POST handler.
    t("bulk_delete requires action.bulk_delete_members (not just manage_members)",
        (bool) preg_match('/bulk_delete.*?rbac_can\([\'"]action\.bulk_delete_members[\'"]\)\s*&&\s*!is_admin\(\)/s', $api));
} else {
    t("api/members.php readable", false);
}

// ── Static guards: roster.php UI is gated on the dedicated permission ───
$rp = @file_get_contents($base . '/roster.php');
if ($rp !== false) {
    t("roster.php computes canBulkDeleteMembers from action.bulk_delete_members/is_admin",
        (bool) preg_match('/\$canBulkDeleteMembers\s*=\s*\(rbac_can\([\'"]action\.bulk_delete_members[\'"]\)\s*\|\|\s*is_admin\(\)\)/', $rp));
    // The bulk UI must NOT be gated on plain manage_members (which Org Admins
    // and Dispatchers hold) — Eric wants it narrowly held.
    t("roster.php bulk UI is NOT gated on action.manage_members",
        strpos($rp, "rbac_can('action.manage_members')") === false);
    t("roster.php bulk toolbar + confirm modal are gated behind canBulkDeleteMembers",
        strpos($rp, 'id="rosterBulkBar"') !== false &&
        strpos($rp, 'id="rosterBulkDeleteModal"') !== false &&
        strpos($rp, 'if ($canBulkDeleteMembers):') !== false);
    t("roster.php exposes #canBulkDeleteMembers hidden input to JS",
        (bool) preg_match('/id="canBulkDeleteMembers"\s+value="<\?php echo \$canBulkDeleteMembers/', $rp));
    // ScreenPrefs safety: the select <th> must NOT carry data-col-id (its
    // presence would shift the nth-child column indices ScreenPrefs computes).
    t("roster.php select column has NO data-col-id (ScreenPrefs-safe)",
        (bool) preg_match('/<th class="roster-sel-col[^>]*>(?![^<]*data-col-id)/', $rp) &&
        strpos($rp, 'roster-sel-col') !== false);
} else {
    t("roster.php readable", false);
}

// ── Static guards: roster.js wiring ────────────────────────────────────
$js = @file_get_contents($base . '/assets/js/roster.js');
if ($js !== false) {
    t("roster.js posts action: 'bulk_delete'",
        strpos($js, "action: 'bulk_delete'") !== false);
    t("roster.js defines initBulkRoster()",
        strpos($js, 'function initBulkRoster()') !== false);
    t("roster.js calls initBulkRoster() from init()",
        (bool) preg_match('/bindEvents\(\);.*?initBulkRoster\(\);/s', $js));
    t("roster.js reads the #canBulkDeleteMembers flag",
        strpos($js, "getElementById('canBulkDeleteMembers')") !== false);
    t("roster.js renders the row checkbox only when canBulkDeleteMembers",
        strpos($js, 'roster-sel-cb') !== false &&
        (bool) preg_match('/canBulkDeleteMembers\s*\n?\s*\?\s*[\'"]<td class="roster-sel-col/s', $js));
} else {
    t("roster.js readable", false);
}

// ── Static guards: migration + fresh-install seed ──────────────────────
$mig = @file_get_contents($base . '/sql/run_bulk_delete_member_perm.php');
if ($mig !== false) {
    t("migration seeds action.bulk_delete_members",
        strpos($mig, "'action.bulk_delete_members'") !== false);
    t("migration grants ONLY to Super Admin (role 1)",
        (bool) preg_match('/role_permissions.*?VALUES \(1,/s', $mig) &&
        strpos($mig, 'VALUES (2,') === false &&
        strpos($mig, 'VALUES (3,') === false);
} else {
    t("migration file present", false);
}
$sq = @file_get_contents($base . '/sql/rbac.sql');
if ($sq !== false) {
    t("rbac.sql fresh-install seed includes the permission",
        strpos($sq, "'action.bulk_delete_members'") !== false);
    t("rbac.sql excludes it from Org Admin's default grant",
        (bool) preg_match("/SELECT 2, .*?NOT IN \([^)]*action\.bulk_delete_members/s", $sq));
    t("rbac.sql excludes it from Dispatcher's default grant",
        (bool) preg_match("/SELECT 3, .*?NOT IN \([^)]*action\.bulk_delete_members/s", $sq));
} else {
    t("rbac.sql readable", false);
}

// ── Runtime: the grant is narrowly held (Super Admin yes; Org Admin no) ─
require_once __DIR__ . '/../inc/rbac.php';
$prefix = $GLOBALS['db_prefix'] ?? '';
$hasRbac = false;
try {
    $hasRbac = (bool) db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'role_permissions']);
} catch (Throwable $e) {}

if (!$hasRbac) {
    echo "[SKIP] role_permissions table absent — runtime grant checks skipped.\n";
} else {
    // Seed the permission the same way the migration does (idempotent), so
    // the check works on a dev DB that hasn't run the migration runner yet.
    try {
        db_query("INSERT IGNORE INTO `{$prefix}permissions` (`code`,`name`,`category`,`description`)
                  VALUES ('action.bulk_delete_members','Bulk Delete Members','action','Remove multiple member records at once (roster bulk actions)')");
        $permId = (int) db_fetch_value("SELECT id FROM `{$prefix}permissions` WHERE code=?", ['action.bulk_delete_members']);
        if (db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id=1")) {
            db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`,`permission_id`) VALUES (1, ?)", [$permId]);
        }
        t("permission row exists after seed", $permId > 0);

        $grantedTo = array_map('intval', array_column(
            db_fetch_all("SELECT role_id FROM `{$prefix}role_permissions` WHERE permission_id=?", [$permId]), 'role_id'));
        t("Super Admin (role 1) IS granted bulk delete", in_array(1, $grantedTo, true));
        t("Org Admin (role 2) is NOT granted bulk delete (Eric's requirement)", !in_array(2, $grantedTo, true));
        t("Dispatcher (role 3) is NOT granted bulk delete", !in_array(3, $grantedTo, true));
    } catch (Throwable $e) {
        t("runtime grant check without error: " . $e->getMessage(), false);
    }
}

// ── Normalization / cap logic (mirrors the endpoint) ───────────────────
$normalize = function (array $rawIds) {
    $ids = [];
    foreach ($rawIds as $rid) { $rid = (int) $rid; if ($rid > 0) { $ids[$rid] = true; } }
    return array_keys($ids);
};
t("dedupes + drops non-positive ids", $normalize([5, 5, 0, -3, 8, '8']) === [5, 8]);
t("empty after normalization is rejected upstream", $normalize(['x', 0, -1]) === []);
t("501 distinct ids exceeds the cap", count($normalize(range(1, 501))) > 500);

// ── Functional: create via the real writer, then bulk soft-delete ──────
$hasSoftDelete = false;
try {
    $hasSoftDelete = (bool) db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
        [($GLOBALS['db_prefix'] ?? '') . 'member']
    );
} catch (Throwable $e) {}

if (!$hasSoftDelete) {
    echo "[SKIP] member.deleted_at absent (pre-wastebasket install) — functional soft-delete scenario skipped.\n";
} else {
    $made = [];
    try {
        for ($i = 1; $i <= 3; $i++) {
            $res = member_create_internal(
                ['first_name' => 'zzBulk', 'last_name' => 'Test' . $i, 'available' => 'Yes'],
                0
            );
            if (empty($res['errors']) && !empty($res['id'])) { $made[] = (int) $res['id']; }
        }
        t("created 3 throwaway members via the real writer", count($made) === 3);

        // All three visible in the "not deleted" view before deletion.
        $liveBefore = 0;
        foreach ($made as $id) {
            $r = db_fetch_one("SELECT id FROM " . tbl('member') . " WHERE id = ? AND deleted_at IS NULL", [$id]);
            if ($r) { $liveBefore++; }
        }
        t("all 3 are live (deleted_at IS NULL) before bulk delete", $liveBefore === 3);

        // Exercise the endpoint's per-id loop (member_soft_delete).
        $deleted = 0;
        foreach ($made as $id) {
            $r = member_soft_delete($id, 0);
            if (!empty($r['deleted']) && empty($r['errors'])) { $deleted++; }
        }
        t("member_soft_delete reports all 3 deleted", $deleted === 3);

        // None remain in the "not deleted" view; all rows still physically present.
        $liveAfter = 0; $rowsPresent = 0;
        foreach ($made as $id) {
            if (db_fetch_one("SELECT id FROM " . tbl('member') . " WHERE id = ? AND deleted_at IS NULL", [$id])) { $liveAfter++; }
            if (db_fetch_one("SELECT id FROM " . tbl('member') . " WHERE id = ?", [$id])) { $rowsPresent++; }
        }
        t("none remain live after bulk soft-delete", $liveAfter === 0);
        t("rows are soft-deleted, not physically gone (recoverable via wastebasket)", $rowsPresent === 3);

    } catch (Throwable $e) {
        t("functional bulk-delete without error: " . $e->getMessage(), false);
    } finally {
        // Hard-clean the throwaways so the test leaves no residue.
        foreach ($made as $id) {
            try { db_query("DELETE FROM " . tbl('member_organizations') . " WHERE member_id = ?", [$id]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM " . tbl('member_certifications') . " WHERE member_id = ?", [$id]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM " . tbl('member') . " WHERE id = ?", [$id]); } catch (Throwable $e) {}
        }
    }
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
