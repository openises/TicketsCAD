<?php
/**
 * Phase 145 (2026-08-19, GH#90) — Facility-account confinement, adversarial.
 *
 * v3's LEVEL_FACILITY enforced ZERO real access control (see GH#90's
 * reverse-engineering) — the "facility" behavior was a post-login redirect
 * only; a facility user got the same screens as anyone else and could
 * create incidents. This is the regression suite proving v4's replacement
 * (inc/facility-scope.php) genuinely confines a facility-scoped session,
 * with the same rigor this project applies to org-scoping / anti-chaining
 * elsewhere (e.g. tests/test_org_sharing_anti_chaining.php).
 *
 * Sections:
 *   A. Ticket visibility — facility_ticket_visibility_sql() /
 *      facility_can_see_ticket(): a facility sees ONLY incidents at, or
 *      inbound to, ITS OWN facility (origin leg, ticket-level receiving
 *      leg, and Phase 116 per-unit assigns.rec_facility_id leg) — and
 *      explicitly CANNOT see another facility's incidents, even ones
 *      that share nothing else in common.
 *   B. RBAC confinement, defense-in-depth — a facility-confined session
 *      is denied EVERY permission outside the 2-code allowlist, even
 *      when the underlying account ALSO holds a genuine Super Admin
 *      grant in the database (the adversarial case: confinement must
 *      win over is_super, not merely over an absent grant). A control
 *      case with the SAME grants but facility_id unset proves the
 *      grants were real and confinement — not missing data — did the
 *      denying.
 *   C. Page/API allowlist enumeration — walks every REAL top-level page
 *      and every REAL api/*.php file on disk (not a hardcoded sample)
 *      and asserts the allowlist contains exactly the 3 expected page
 *      names and 3 expected API script names — nothing more. This is
 *      what makes "verify every existing endpoint a facility session
 *      might reach correctly refuses it" true by construction rather
 *      than by enumeration: the confinement is allowlist-shaped, so a
 *      new endpoint added later is refused by default, and this test
 *      would need an explicit, deliberate edit to widen the allowlist —
 *      exactly the friction a security boundary like this should have.
 *   D. Source-wiring verification — confirms api/auth.php, inc/rbac.php,
 *      and inc/force-pw-change.php actually call the guard functions at
 *      the right place (the same source-level technique
 *      tests/test_org_sharing_endpoint_wiring.php already established
 *      in this codebase for "no live-session HTTP harness exists here").
 *   E. Schema — user.responder_id is gone; user.facility_id is repurposed
 *      (comment updated); the Facility role (7) + its 2 permissions exist.
 *
 * @requires-db
 * Usage: php tests/test_facility_scope_confinement.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/facility-scope.php';
require_once __DIR__ . '/../inc/rbac.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 145 — facility-account confinement (adversarial) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$facAId = 900014501; // "our" facility
$facBId = 900014502; // "the other" facility — must never be visible to A
$userAId = 900014511; // facility-A account (role 7 + facility_id=A)
$userSuperMixId = 900014512; // facility-A account that ALSO holds Super Admin

$createdTicketIds = [];
$createdAssignIds = [];

$cleanup = function () use ($prefix, $facAId, $facBId, $userAId, $userSuperMixId, &$createdTicketIds, &$createdAssignIds) {
    foreach ($createdAssignIds as $id) { try { db_query("DELETE FROM {$prefix}assigns WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$userAId, $userSuperMixId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user WHERE id IN (?, ?)", [$userAId, $userSuperMixId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}facilities WHERE id IN (?, ?)", [$facAId, $facBId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    // ══════════════════════════════════════════════════════════════════
    // Fixtures
    // ══════════════════════════════════════════════════════════════════

    // Resolved by NAME, never a hardcoded id — see sql/rbac.sql's comment
    // on the Facility role INSERT. A hardcoded literal here would be
    // exactly the bug this whole test is designed to catch: this very
    // dev database already has a pre-existing custom role occupying id 7
    // (discovered while first writing this test).
    $facilityRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Facility' LIMIT 1");
    if ($facilityRoleId <= 0) {
        echo "SKIP: Facility role not found — run sql/run_00_rbac.php first\n";
        echo "\n=== 0 passed, 0 failed ===\n";
        exit(0);
    }

    db_query(
        "INSERT INTO {$prefix}facilities (id, name, description) VALUES (?, 'ZZ145 Facility A', 'zz145 fixture')",
        [$facAId]
    );
    db_query(
        "INSERT INTO {$prefix}facilities (id, name, description) VALUES (?, 'ZZ145 Facility B', 'zz145 fixture')",
        [$facBId]
    );
    t('fixture facilities A and B created', true);

    // user A — the Facility-role account under test.
    db_query("INSERT INTO {$prefix}user (id, user, passwd, facility_id) VALUES (?, 'zz145-facA', ?, ?)",
        [$userAId, password_hash('unused', PASSWORD_BCRYPT), $facAId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, ?, NULL, 'global', NULL)",
        [$userAId, $facilityRoleId]);

    // user "super-mix" — Facility-role AND facility_id=A, but ALSO holds a
    // genuine Super Admin grant. This is the deliberate adversarial case:
    // does facility confinement really win over is_super, or only over an
    // absent grant? A test that never grants Super Admin can't tell.
    db_query("INSERT INTO {$prefix}user (id, user, passwd, facility_id) VALUES (?, 'zz145-supermix', ?, ?)",
        [$userSuperMixId, password_hash('unused', PASSWORD_BCRYPT), $facAId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 1, NULL, 'global', NULL)",
        [$userSuperMixId]);
    t('fixture users created (facility-A account, and a facility-A + Super Admin mix account)', true);

    // ══════════════════════════════════════════════════════════════════
    // Section A — ticket visibility
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- A. facility_ticket_visibility_sql() / facility_can_see_ticket() ---\n\n";

    $now = date('Y-m-d H:i:s');
    function _zz145_ticket($prefix, $now, $facility, $recFacility) {
        db_query(
            "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`facility`,`rec_facility`)
             VALUES (0, '', '1 ZZ145 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz145 ticket', 'zz145 ticket', 2, 1, NOW(), ?, ?)",
            [$now, $facility, $recFacility]
        );
        return (int) db_insert_id();
    }

    // Ticket 1: origin leg at facility A.
    $t1 = _zz145_ticket($prefix, $now, $facAId, 0);
    $createdTicketIds[] = $t1;
    // Ticket 2: receiving leg (ticket-level rec_facility) at facility A.
    $t2 = _zz145_ticket($prefix, $now, 0, $facAId);
    $createdTicketIds[] = $t2;
    // Ticket 3: neither ticket-level column points at A OR B — ONLY a
    // per-unit assigns.rec_facility_id leg points at A (Phase 116
    // mass-casualty case: a scene with no single "the" facility yet,
    // where an individual unit has already been given a destination).
    // Deliberately NOT facility B's origin/receiving here — that would
    // make it legitimately visible to B too via the ticket-level columns,
    // which would defeat the point of this specific case (isolating the
    // per-unit leg as the ONLY visibility path).
    $t3 = _zz145_ticket($prefix, $now, 0, 0);
    $createdTicketIds[] = $t3;
    db_query(
        "INSERT INTO {$prefix}assigns (ticket_id, user_id, rec_facility_id) VALUES (?, 1, ?)",
        [$t3, $facAId]
    );
    $createdAssignIds[] = (int) db_insert_id();
    // Ticket 4: belongs ENTIRELY to facility B — the negative case.
    $t4 = _zz145_ticket($prefix, $now, $facBId, $facBId);
    $createdTicketIds[] = $t4;

    t('Facility A sees its OWN origin-leg ticket', facility_can_see_ticket($t1, $facAId));
    t('Facility A sees its OWN ticket-level receiving-leg ticket', facility_can_see_ticket($t2, $facAId));
    t('Facility A sees a ticket where ONLY the per-unit assigns.rec_facility_id leg points to it', facility_can_see_ticket($t3, $facAId));
    t('Facility A does NOT see a ticket that belongs entirely to Facility B', !facility_can_see_ticket($t4, $facAId));

    t('Facility B does NOT see Facility A\'s origin-leg ticket', !facility_can_see_ticket($t1, $facBId));
    t('Facility B does NOT see Facility A\'s receiving-leg ticket', !facility_can_see_ticket($t2, $facBId));
    t('Facility B does NOT see the per-unit-leg ticket routed to Facility A', !facility_can_see_ticket($t3, $facBId));
    t('Facility B DOES see its own ticket (control — the gate is not failing closed for everyone)', facility_can_see_ticket($t4, $facBId));

    // The SQL fragment itself, exercised the way api/facility-portal.php
    // actually uses it — not just the single-ticket helper.
    $_SESSION['facility_id'] = $facAId;
    [$visFrag, $visParams] = facility_ticket_visibility_sql('t');
    $visibleIds = array_map('intval', array_column(
        db_fetch_all("SELECT t.id FROM {$prefix}ticket t WHERE t.id IN (?, ?, ?, ?) {$visFrag}",
            array_merge([$t1, $t2, $t3, $t4], $visParams)),
        'id'
    ));
    sort($visibleIds);
    $expectedVisible = [$t1, $t2, $t3];
    sort($expectedVisible);
    t('facility_ticket_visibility_sql() run as a real list query returns EXACTLY {t1,t2,t3} for Facility A, never t4',
        $visibleIds === $expectedVisible);
    unset($_SESSION['facility_id']);

    // ══════════════════════════════════════════════════════════════════
    // Section B — RBAC confinement, defense-in-depth
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- B. RBAC confinement (rbac_can()/is_admin()) — wins even over a genuine Super Admin grant ---\n\n";

    // First: prove the Super Admin grant on the "mix" account is REAL by
    // checking it WITHOUT facility confinement (facility_id unset). If
    // this control fails, every "confinement denies it" assertion below
    // would be meaningless (there'd be nothing to override).
    $_SESSION['user_id'] = $userSuperMixId;
    unset($_SESSION['facility_id']);
    rbac_reset_cache();
    t('CONTROL: without facility_id set, the mix account\'s genuine Super Admin grant makes is_admin() true',
        is_admin(true));
    t('CONTROL: without facility_id set, rbac_can(action.manage_config) is true (Super Admin)',
        rbac_can('action.manage_config'));
    t('CONTROL: without facility_id set, rbac_can(screen.incidents) is true (Super Admin holds everything)',
        rbac_can('screen.incidents'));

    // Now: the SAME account, SAME DB grants, but facility_id IS set (the
    // real login.php would set this from user.facility_id, which the mix
    // account also has — this is the actual state a login for this
    // account would produce).
    $_SESSION['facility_id'] = $facAId;
    rbac_reset_cache();
    t('Facility confinement forces is_admin() to FALSE despite a real Super Admin grant in the database',
        !is_admin(true));
    t('Facility confinement denies action.manage_config despite the Super Admin grant',
        !rbac_can('action.manage_config'));
    t('Facility confinement denies action.manage_roles despite the Super Admin grant',
        !rbac_can('action.manage_roles'));
    t('Facility confinement denies screen.incidents despite the Super Admin grant',
        !rbac_can('screen.incidents'));
    t('Facility confinement denies action.create_incident despite the Super Admin grant',
        !rbac_can('action.create_incident'));
    t('Facility confinement denies action.delete_incident despite the Super Admin grant',
        !rbac_can('action.delete_incident'));
    t('Facility confinement STILL allows screen.facility_portal (the one thing it should have)',
        rbac_can('screen.facility_portal'));
    t('Facility confinement STILL allows action.facility_self_report (the other thing it should have)',
        rbac_can('action.facility_self_report'));

    // The plain facility-A-only account (no Super Admin grant at all) —
    // same assertions, proving the allowlist behavior isn't an artifact
    // of the mix account's particular grant set.
    $_SESSION['user_id'] = $userAId;
    $_SESSION['facility_id'] = $facAId;
    rbac_reset_cache();
    t('Plain Facility-role account: is_admin() is false', !is_admin(true));
    t('Plain Facility-role account: rbac_can(screen.facility_portal) is true', rbac_can('screen.facility_portal'));
    t('Plain Facility-role account: rbac_can(action.facility_self_report) is true', rbac_can('action.facility_self_report'));
    t('Plain Facility-role account: rbac_can(screen.dashboard) is false', !rbac_can('screen.dashboard'));
    t('Plain Facility-role account: rbac_can(action.manage_users) is false', !rbac_can('action.manage_users'));
    t('Plain Facility-role account: rbac_user_permissions() is EXACTLY the 2-code allowlist, nothing more',
        (function () {
            $perms = rbac_user_permissions();
            sort($perms);
            $expected = FACILITY_ALLOWED_PERMISSIONS;
            sort($expected);
            return $perms === $expected;
        })()
    );

    unset($_SESSION['user_id'], $_SESSION['facility_id']);
    rbac_reset_cache();

    // ══════════════════════════════════════════════════════════════════
    // Section C — allowlist enumeration against every REAL file on disk
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- C. page/API allowlist — enumerated against every real file, not a sample ---\n\n";

    $root = realpath(__DIR__ . '/..');
    $pages = array_map('basename', glob($root . '/*.php'));
    $allowedPages = array_values(array_filter($pages, 'facility_page_allowed'));
    sort($allowedPages);
    $expectedPages = FACILITY_ALLOWED_PAGES;
    sort($expectedPages);
    t('EXACTLY the expected page set is allowed (facility-portal.php, profile.php, login.php) — no more, no less, walking every *.php file in the app root ('
        . count($pages) . ' files checked)', $allowedPages === $expectedPages);

    $apiFiles = array_map('basename', glob($root . '/api/*.php'));
    $allowedApi = array_values(array_filter($apiFiles, 'facility_api_script_allowed'));
    sort($allowedApi);
    $expectedApi = FACILITY_ALLOWED_API_SCRIPTS;
    sort($expectedApi);
    t('EXACTLY the expected api script set is allowed (facility-portal.php, profile.php, tfa.php) — no more, no less, walking every api/*.php file ('
        . count($apiFiles) . ' files checked)', $allowedApi === $expectedApi);

    // Explicitly spell out a handful of high-value incident/admin
    // endpoints a facility session might plausibly try, per this phase's
    // own instruction to verify them by name, not just by set difference.
    $mustBeDenied = [
        'incident-list.php', 'incident-detail.php', 'incident-create.php',
        'incident-update.php', 'incidents.php', 'incident-search.php',
        'callboard.php', 'facility-capacity.php', 'facility-detail.php',
        'facility-action.php', 'config-admin.php', 'rbac.php', 'users.php',
        'major-incidents.php', 'reports.php',
    ];
    foreach ($mustBeDenied as $script) {
        if (in_array($script, $apiFiles, true)) {
            t("api/{$script} is NOT on the facility allowlist", !facility_api_script_allowed($script));
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Section D — source-wiring verification
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- D. source-wiring (the technique tests/test_org_sharing_endpoint_wiring.php established for this codebase) ---\n\n";

    $authSrc = file_get_contents($root . '/api/auth.php');
    t('api/auth.php calls facility_confine_api_or_deny()', strpos($authSrc, 'facility_confine_api_or_deny()') !== false);
    $authPos = strpos($authSrc, 'facility_confine_api_or_deny()');
    $mustChangePos = strpos($authSrc, "\$_SESSION['must_change_password']");
    t('facility_confine_api_or_deny() runs BEFORE the must_change_password gate (confinement is unconditional)',
        $authPos !== false && $mustChangePos !== false && $authPos < $mustChangePos);

    $fpwSrc = file_get_contents($root . '/inc/force-pw-change.php');
    t('inc/force-pw-change.php calls facility_confine_page_redirect(', strpos($fpwSrc, 'facility_confine_page_redirect(') !== false);

    $rbacSrc = file_get_contents($root . '/inc/rbac.php');
    t('inc/rbac.php\'s _rbac_load_grants() forces is_super = false under facility confinement',
        strpos($rbacSrc, "\$isSuper = false;\n") !== false || substr_count($rbacSrc, '$isSuper = false;') >= 1);
    t('inc/rbac.php intersects by_code down to FACILITY_ALLOWED_PERMISSIONS',
        strpos($rbacSrc, 'array_intersect_key($byCode, array_flip($allowed))') !== false);

    $loginSrc = file_get_contents($root . '/login.php');
    t('login.php sets $_SESSION[\'facility_id\'] from the DB row (never trusts client input)',
        strpos($loginSrc, "\$_SESSION['facility_id'] = \$facilityIdVal;") !== false);
    t('login.php redirects a facility session to facility-portal.php', substr_count($loginSrc, "'facility-portal.php'") >= 2);

    // ══════════════════════════════════════════════════════════════════
    // Section E — schema
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- E. schema (user.responder_id dropped, user.facility_id repurposed, Facility role seeded) ---\n\n";

    $responderIdCol = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'responder_id'",
        [$prefix . 'user']
    );
    t('user.responder_id column no longer exists', !$responderIdCol);

    $facilityIdCol = db_fetch_one(
        "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'facility_id'",
        [$prefix . 'user']
    );
    t('user.facility_id column still exists', (bool) $facilityIdCol);
    t('user.facility_id comment documents the Phase 145 repurpose',
        $facilityIdCol && strpos((string) $facilityIdCol['COLUMN_COMMENT'], 'Phase 145') !== false);

    $role = db_fetch_one("SELECT id, name FROM {$prefix}roles WHERE id = ?", [$facilityRoleId]);
    t('Facility role exists at its resolved (not assumed) id', (bool) $role);
    t('Facility role is named "Facility"', $role && $role['name'] === 'Facility');

    $permCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}permissions WHERE code IN ('screen.facility_portal', 'action.facility_self_report')"
    );
    t('Both facility-portal permissions exist', $permCount === 2);

    $grantCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}role_permissions rp
         JOIN {$prefix}permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.code IN ('screen.facility_portal', 'action.facility_self_report')",
        [$facilityRoleId]
    );
    t('Facility role holds exactly both permissions', $grantCount === 2);

    // The privilege-leak class this codebase has hit repeatedly (RBAC
    // exclusion-list leaks, Org Admin / Dispatcher picking up an
    // admin-only permission via a broad grant): confirm the 2
    // facility-only codes are NOT held by Org Admin (2) or Dispatcher (3).
    $leakCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}role_permissions rp
         JOIN {$prefix}permissions p ON p.id = rp.permission_id
         WHERE rp.role_id IN (2, 3) AND p.code IN ('screen.facility_portal', 'action.facility_self_report')"
    );
    t('Neither Org Admin nor Dispatcher holds either facility-only permission (no exclusion-list leak)', $leakCount === 0);

} finally {
    unset($_SESSION['user_id'], $_SESSION['facility_id']);
    if (function_exists('rbac_reset_cache')) rbac_reset_cache();
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
