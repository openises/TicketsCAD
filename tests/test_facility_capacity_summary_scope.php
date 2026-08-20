<?php
/**
 * Facility-capacity summary IDOR regression (found 2026-08-19).
 *
 * api/facility-capacity.php's `?facility_id=X` path has always called
 * user_can_access_entity('facility', $facId) before returning a single
 * facility's bed/capacity counts — non-admins must be in a group allocated
 * to that facility. The `?summary=1` sibling path (an unfiltered JOIN
 * across facility_capacity/facilities/capacity_categories) had NO such
 * check at all: any authenticated user (the file only requires basic
 * session auth via api/auth.php) could see bed/capacity data for EVERY
 * facility in the install, including facilities that 404 them individually
 * a few lines below in the SAME file. A filter-bypass of the IDOR gate one
 * path over.
 *
 * Fixed by extracting the summary query + scoping into
 * facility_capacity_summary_rows() (inc/access.php), reusing the exact
 * same user_can_access_entity('facility', ...) call the single-facility
 * path already relies on, so the two paths can never disagree about which
 * facilities a given session may see. api/facility-capacity.php's summary
 * branch now calls that function directly — this test drives the SAME
 * function, against real fixtures inserted through real INSERTs into
 * facilities/facility_capacity/allocates/user, not a hand-seeded mock of
 * "correct" filtered output.
 *
 * @requires-db
 * Usage: php tests/test_facility_capacity_summary_scope.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/rbac.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Facility-capacity summary IDOR regression (2026-08-19) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Fixture ids — a dedicated, unused block (grepped clean against the rest
// of tests/ before picking it).
$facAId       = 900019601; // facility the scoped user IS allocated to
$facBId       = 900019602; // facility the scoped user is NOT allocated to
$catId        = 900019603; // dedicated fixture capacity category
$scopedUserId = 900019611; // non-admin, allocates-scoped fixture user
$fixtureGroup = 900019621; // arbitrary group id used only by this fixture

$cleanup = function () use ($prefix, $facAId, $facBId, $catId, $scopedUserId, $fixtureGroup) {
    try { db_query("DELETE FROM `{$prefix}facility_capacity` WHERE `facility_id` IN (?, ?)", [$facAId, $facBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}capacity_categories` WHERE `id` = ?", [$catId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}allocates` WHERE `group` = ? AND `type` = 3", [$fixtureGroup]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$scopedUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$scopedUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facilities` WHERE `id` IN (?, ?)", [$facAId, $facBId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    // ══════════════════════════════════════════════════════════════════
    // Fixtures
    // ══════════════════════════════════════════════════════════════════

    db_query("INSERT INTO `{$prefix}facilities` (id, name, description) VALUES (?, 'ZZ-CAPSUM Facility A', 'zz-capsum fixture')", [$facAId]);
    db_query("INSERT INTO `{$prefix}facilities` (id, name, description) VALUES (?, 'ZZ-CAPSUM Facility B', 'zz-capsum fixture')", [$facBId]);
    t('fixture facilities A and B created', true);

    db_query(
        "INSERT INTO `{$prefix}capacity_categories` (id, name, icon, unit_label, sort_order) VALUES (?, 'ZZ-CAPSUM Beds', 'bi-hospital', 'beds', 999)",
        [$catId]
    );

    // total > 0 on both — the summary query's own WHERE clause requires
    // this, so both facilities are genuinely summary-eligible before any
    // access filtering happens.
    db_query("INSERT INTO `{$prefix}facility_capacity` (facility_id, category_id, total, available) VALUES (?, ?, 10, 4)", [$facAId, $catId]);
    db_query("INSERT INTO `{$prefix}facility_capacity` (facility_id, category_id, total, available) VALUES (?, ?, 20, 8)", [$facBId, $catId]);
    t('fixture capacity rows created for both facilities (total > 0)', true);

    // A non-admin user with ZERO role grants. is_admin() and rbac_can()
    // both fall through to false for this account, which forces
    // user_can_access_entity() onto the allocates-group path — the same
    // path a real Dispatcher-without-facility-view-permission session
    // would take.
    db_query(
        "INSERT INTO `{$prefix}user` (id, user, passwd) VALUES (?, 'zzcapsum-scoped', ?)",
        [$scopedUserId, password_hash('unused-test-fixture', PASSWORD_BCRYPT)]
    );
    t('fixture non-admin user created (zero role grants)', true);

    // Allocate the fixture group to Facility A ONLY (allocates.type = 3
    // per inc/access.php's $allocatesType map). Facility B deliberately
    // gets no allocates row for this group — the negative case.
    db_query("INSERT INTO `{$prefix}allocates` (`group`, `type`, `resource_id`) VALUES (?, 3, ?)", [$fixtureGroup, $facAId]);
    t("fixture allocates row grants the scoped user's group access to Facility A only", true);

    // ══════════════════════════════════════════════════════════════════
    // Admin session — sees every facility, unfiltered
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Admin session ---\n\n";

    $_SESSION['user_id'] = test_admin_user_id();
    unset($_SESSION['user_groups']);
    rbac_reset_cache();

    t('CONTROL: admin session is_admin() is true', is_admin(true));

    $adminRows = facility_capacity_summary_rows();
    $adminFacIds = array_values(array_unique(array_map('intval', array_column($adminRows, 'facility_id'))));

    t('Admin summary includes Facility A', in_array($facAId, $adminFacIds, true));
    t('Admin summary includes Facility B', in_array($facBId, $adminFacIds, true));

    // ══════════════════════════════════════════════════════════════════
    // Scoped non-admin session — the actual fix under test
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Scoped non-admin session ---\n\n";

    $_SESSION['user_id'] = $scopedUserId;
    $_SESSION['user_groups'] = [$fixtureGroup];
    rbac_reset_cache();

    t('CONTROL: scoped user is NOT an admin', !is_admin(true));
    t('CONTROL: scoped user is denied Facility B via the (already-correct) single-facility IDOR check — proves the negative case is real, not just absent data',
        !user_can_access_entity('facility', $facBId));
    t('CONTROL: scoped user IS allowed Facility A via the same single-facility IDOR check',
        user_can_access_entity('facility', $facAId));

    $scopedRows = facility_capacity_summary_rows();
    $scopedFacIds = array_values(array_unique(array_map('intval', array_column($scopedRows, 'facility_id'))));
    sort($scopedFacIds);

    t('THE FIX: scoped-user summary includes Facility A (the one it IS allocated to)',
        in_array($facAId, $scopedFacIds, true));
    t('THE FIX: scoped-user summary does NOT include Facility B — the filter-bypass this closes',
        !in_array($facBId, $scopedFacIds, true));
    t('THE FIX: scoped-user summary is EXACTLY {Facility A} among our two fixtures, nothing more',
        $scopedFacIds === [$facAId]);

    // Same conclusion via the row PAYLOAD itself (not just the id list) —
    // confirms no Facility-B bed/category data leaked into the response
    // under a different key.
    $leakedRows = array_filter($scopedRows, function ($r) use ($facBId) {
        return (int) $r['facility_id'] === $facBId;
    });
    t('THE FIX: zero raw rows for Facility B anywhere in the scoped response', count($leakedRows) === 0);

    // ══════════════════════════════════════════════════════════════════
    // api/facility-capacity.php actually calls the fixed function
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Endpoint wiring ---\n\n";

    $endpointSrc = file_get_contents(__DIR__ . '/../api/facility-capacity.php');
    t('api/facility-capacity.php\'s summary branch calls facility_capacity_summary_rows()',
        strpos($endpointSrc, 'facility_capacity_summary_rows()') !== false);
    // The old unscoped inline JOIN must be gone from the summary branch,
    // not just supplemented — otherwise a stray second query could still
    // leak the unfiltered rows.
    $summaryBranchStart = strpos($endpointSrc, "!empty(\$_GET['summary'])");
    $summaryBranchEnd = strpos($endpointSrc, "!empty(\$_GET['categories'])");
    $summaryBranch = ($summaryBranchStart !== false && $summaryBranchEnd !== false && $summaryBranchEnd > $summaryBranchStart)
        ? substr($endpointSrc, $summaryBranchStart, $summaryBranchEnd - $summaryBranchStart)
        : '';
    t('the summary branch no longer runs its own inline facility_capacity JOIN',
        $summaryBranch !== '' && strpos($summaryBranch, 'FROM `{$prefix}facility_capacity`') === false);

} finally {
    unset($_SESSION['user_id'], $_SESSION['user_groups']);
    if (function_exists('rbac_reset_cache')) rbac_reset_cache();
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
