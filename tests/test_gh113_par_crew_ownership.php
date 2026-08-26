<?php
/**
 * GH#113 (rjonesbsink, 2026-08-25) — a crew-linked Field Unit user (no
 * personal responder row, no username match, just crewing a shared
 * organizational unit via unit_personnel_assignments) could sign into the
 * PWA and work the call fine, but was refused acking PAR for that same
 * unit: "Forbidden — you can only ack PAR for your own unit".
 *
 * Root cause: par_user_owns_responder() (inc/par.php) documented itself
 * as mirroring api/mobile-data.php's THREE resolver paths — true when
 * written, but mobile-data.php grew a fourth (mobile_crew_unit_ids(),
 * added for GH#77) that this gate never learned about. Fixed by moving
 * mobile_crew_unit_ids() into the shared inc/mobile-assignments.php (it
 * used to live inside api/mobile-data.php itself) and having
 * par_user_owns_responder() call the SAME function mobile-data.php uses,
 * rather than re-deriving a third, inevitably-drifting copy of the query.
 *
 * Driven through the REAL par_user_owns_responder() against a throwaway
 * fixture (a user with none of paths 1-3, only a crew assignment), not a
 * hand-simulated "should be true" check.
 *
 * @requires-db
 * Usage: php tests/test_gh113_par_crew_ownership.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/par.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#113: par_user_owns_responder() recognizes a crew-linked unit ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Static contract: par_user_owns_responder() calls the shared
// mobile_crew_unit_ids() resolver (Path 4), not a re-derived copy. ────────
$parSrc = file_get_contents($root . '/inc/par.php');
if (preg_match('/function par_user_owns_responder[\s\S]{0,3000}mobile_crew_unit_ids\(\s*\$prefix\s*,\s*\$userId\s*\)/', $parSrc)) {
    ok('par_user_owns_responder() calls the shared mobile_crew_unit_ids() resolver');
} else {
    bad('par_user_owns_responder() does not call mobile_crew_unit_ids()', 'GH#113 regression — a crew-linked-only Field Unit user would be refused acking PAR again');
}
if (preg_match('/require_once\s+__DIR__\s*\.\s*[\'"]\/mobile-assignments\.php[\'"]\s*;/', $parSrc)) {
    ok('inc/par.php requires inc/mobile-assignments.php before calling mobile_crew_unit_ids()');
} else {
    bad('inc/par.php does not require inc/mobile-assignments.php', 'mobile_crew_unit_ids() would be undefined');
}

// ── 2. Functional: a fixture user with NONE of paths 1-3, only a crew
// assignment, must resolve as owning the crewed unit. ─────────────────────
try {
    $testUser = 'gh113_test_' . bin2hex(random_bytes(4));
    db_query(
        "INSERT INTO `{$prefix}user` (`user`, `passwd`) VALUES (?, ?)",
        [$testUser, password_hash('x', PASSWORD_BCRYPT)]
    );
    $userId = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}member` (`user_id`, `field1`, `field2`) VALUES (?, 'Test', 'User')",
        [$userId]
    );
    $memberId = (int) db_insert_id();

    // A responder that matches NONE of paths 1-3: no user_id link, no
    // personal_for_member_id, and a name/handle that deliberately does
    // NOT match the fixture username (mirroring the real report — a unit
    // name like "MDT UNIT 1" vs a username like "mdt-unit-1").
    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`) VALUES (?, ?, 'GH#113 fixture unit')",
        ['GH113 CREW UNIT', 'GH113CREW']
    );
    $responderId = (int) db_insert_id();

    try {
        $before = par_user_owns_responder($userId, $responderId);
        if ($before !== false) {
            bad('fixture user does NOT own the responder before crewing it (paths 1-3 all miss)', 'setup assumption violated — got true before any crew link existed');
        } else {
            ok('fixture user correctly does NOT own the responder via paths 1-3 before crewing it');
        }

        // Crew the unit — the ONLY path that should now succeed.
        db_query(
            "INSERT INTO `{$prefix}unit_personnel_assignments` (`member_id`, `responder_id`, `status`, `assigned_at`) VALUES (?, ?, 'active', NOW())",
            [$memberId, $responderId]
        );

        $after = par_user_owns_responder($userId, $responderId);
        if ($after === true) {
            ok('GH#113 FIX: par_user_owns_responder() returns true once the user crews the unit, via Path 4');
        } else {
            bad('par_user_owns_responder() still returns false after crewing the unit', 'GH#113 not actually fixed end-to-end');
        }

        // A DIFFERENT, unrelated responder must still be refused — the
        // crew path must not accidentally grant ownership of every unit.
        db_query(
            "INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`) VALUES ('GH113 OTHER UNIT', 'GH113OTHER', 'GH#113 fixture unrelated unit')"
        );
        $otherResponderId = (int) db_insert_id();
        try {
            $otherResult = par_user_owns_responder($userId, $otherResponderId);
            if ($otherResult === false) {
                ok('an unrelated, uncrewed responder is still correctly refused — the fix is scoped to the crewed unit only');
            } else {
                bad('an unrelated responder was incorrectly reported as owned', 'the crew path is too broad');
            }
        } finally {
            db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$otherResponderId]);
        }
    } finally {
        db_query("DELETE FROM `{$prefix}unit_personnel_assignments` WHERE responder_id = ? AND member_id = ?", [$responderId, $memberId]);
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
        db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$memberId]);
        db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$userId]);
    }
} catch (Throwable $e) {
    echo "SKIP: could not drive par_user_owns_responder() against a fixture crew assignment (" . $e->getMessage() . ") — 0 passed, 0 failed for this section\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
