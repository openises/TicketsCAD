<?php
/**
 * Phase 141 (2026-08-17) — Cross-org ticket sharing: RBAC.
 *
 * Covers:
 *   1. Both permission codes exist.
 *   2. Exact role grants match the design: Super Admin holds BOTH codes;
 *      every other role (Org Admin, Dispatcher, Operator, Read-Only,
 *      Field Unit) holds NEITHER. This is a deliberate departure from
 *      Phase 138/140's precedent (where the org-scoped `_org` code WAS
 *      granted to Org Admin by default) -- plan.md's open-question-1: a
 *      routing rule grants a DIFFERENT org visibility into the CREATING
 *      org's data with no two-party-awareness mechanism in Phase 1, so
 *      even the org-scoped variant stays Super-Admin-only.
 *   3. Re-running sql/run_00_rbac.php's broad sweep does not leak either
 *      code to Org Admin (mirrors test_ics_form_types_schema.php Part 5).
 *   4. Mirrors tests/test_rbac_canonical_alias_leak.php's own approach
 *      for these two codes specifically: reproduce both leak mechanisms
 *      (a stale DIRECT grant predating the exclusion list, and a stale
 *      grant of the canonical ALIAS) against live throwaway fixtures,
 *      then prove the shipped repair-DELETE statements (scoped to these
 *      exact two codes) close them.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_rbac.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — Cross-Org Ticket Sharing: RBAC ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — permission rows exist
// ═══════════════════════════════════════════════════════════════════════

echo "--- Permission rows ---\n\n";

$permGlobal = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_org_routing']);
$permOrg    = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_org_routing_org']);
t("action.manage_org_routing permission row exists", (bool) $permGlobal);
t("action.manage_org_routing_org permission row exists", (bool) $permOrg);

if (!$permGlobal || !$permOrg) {
    echo "\nSKIP: permission rows missing -- run sql/run_00_rbac.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

function _p141_role_has(int $roleId, int $permId): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = ? AND permission_id = ?",
        [$roleId, $permId]
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — exact role-grant boundaries (BOTH codes Super-Admin-only)
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Role grants (BOTH codes Super-Admin-only in Phase 1) ---\n\n";

$gId = (int) $permGlobal['id'];
$oId = (int) $permOrg['id'];

t("Super Admin (role 1) holds action.manage_org_routing", _p141_role_has(1, $gId));
t("Super Admin (role 1) holds action.manage_org_routing_org", _p141_role_has(1, $oId));
t("Org Admin (role 2) does NOT hold the install-wide permission", !_p141_role_has(2, $gId));
t("Org Admin (role 2) does NOT hold the org-scoped permission either (Phase 1 departure from Phase 138/140 precedent)", !_p141_role_has(2, $oId));
t("Dispatcher (role 3) does NOT hold the install-wide permission", !_p141_role_has(3, $gId));
t("Dispatcher (role 3) does NOT hold the org-scoped permission", !_p141_role_has(3, $oId));
t("Operator (role 4) does NOT hold the install-wide permission", !_p141_role_has(4, $gId));
t("Operator (role 4) does NOT hold the org-scoped permission", !_p141_role_has(4, $oId));
t("Read-Only (role 5) does NOT hold the install-wide permission", !_p141_role_has(5, $gId));
t("Read-Only (role 5) does NOT hold the org-scoped permission", !_p141_role_has(5, $oId));
t("Field Unit (role 6) does NOT hold the install-wide permission", !_p141_role_has(6, $gId));
t("Field Unit (role 6) does NOT hold the org-scoped permission", !_p141_role_has(6, $oId));

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — re-running run_00_rbac.php's broad sweep must not leak
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Re-run of run_00_rbac.php broad sweep does not leak either code ---\n\n";

$rbacScript = $base . '/sql/run_00_rbac.php';
if (file_exists($rbacScript)) {
    $phpBin = PHP_BINARY ?: 'php';
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($rbacScript) . ' 2>&1');
    t("re-running sql/run_00_rbac.php exits without a fatal error", strpos((string) $out, 'Fatal error') === false);

    t("AFTER re-running run_00_rbac.php: Org Admin still does NOT hold action.manage_org_routing", !_p141_role_has(2, $gId));
    t("AFTER re-running run_00_rbac.php: Org Admin still does NOT hold action.manage_org_routing_org", !_p141_role_has(2, $oId));
    t("AFTER re-running run_00_rbac.php: Dispatcher still holds neither", !_p141_role_has(3, $gId) && !_p141_role_has(3, $oId));
} else {
    echo "SKIP: sql/run_00_rbac.php not found.\n";
}

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — reproduce + repair both leak mechanisms directly, mirroring
// tests/test_rbac_canonical_alias_leak.php's own approach, scoped to
// these two Phase-141 codes specifically.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Reproduce + repair both leak mechanisms (Org Admin, role 2) ---\n\n";

$hasAlias = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deprecated_alias_of'",
    [$prefix . 'permissions']
);

foreach (['action.manage_org_routing' => $gId, 'action.manage_org_routing_org' => $oId] as $code => $permId) {
    // -- Mechanism 1: DIRECT grant predating the exclusion list. --
    db_query("INSERT IGNORE INTO {$prefix}role_permissions (role_id, permission_id) VALUES (2, ?)", [$permId]);
    $leaked = db_fetch_one("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$permId]);
    t("fixture setup: simulated direct-grant leak of $code onto Org Admin exists before repair", (bool) $leaked);

    // Run the EXACT direct-grant repair statement shipped in
    // sql/run_00_rbac.php's Org Admin block, scoped to just this code.
    db_query(
        "DELETE {$prefix}role_permissions FROM {$prefix}role_permissions
         JOIN {$prefix}permissions p ON p.id = {$prefix}role_permissions.permission_id
         WHERE {$prefix}role_permissions.role_id = 2
           AND p.code = ?",
        [$code]
    );
    $stillLeaked = db_fetch_one("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$permId]);
    t("the direct-grant repair DELETE (as shipped) revokes a pre-exclusion-list grant of $code", !$stillLeaked);

    if (!$hasAlias) continue;

    // -- Mechanism 2: ALIAS grant of the canonical form. --
    $canonCode = str_replace('action.', '', $code) . '.zztest_canon';
    db_query(
        "INSERT INTO {$prefix}permissions (code, name, category, description) VALUES (?, ?, 'action', 'throwaway fixture')",
        [$canonCode, "ZZ canonical fixture for $code"]
    );
    $canonId = (int) db_insert_id();
    db_query("UPDATE {$prefix}permissions SET deprecated_alias_of = ? WHERE code = ?", [$canonCode, $code]);

    db_query("INSERT IGNORE INTO {$prefix}role_permissions (role_id, permission_id) VALUES (2, ?)", [$canonId]);
    $aliasLeaked = db_fetch_one("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$canonId]);
    t("fixture setup: simulated alias-grant leak of $code's canonical form onto Org Admin exists before repair", (bool) $aliasLeaked);

    db_query(
        "DELETE rp FROM {$prefix}role_permissions rp
         JOIN {$prefix}permissions canon ON canon.id = rp.permission_id
         JOIN {$prefix}permissions old_p ON old_p.deprecated_alias_of = canon.code
         WHERE rp.role_id = 2
           AND old_p.code = ?",
        [$code]
    );
    $aliasStillLeaked = db_fetch_one("SELECT 1 FROM {$prefix}role_permissions WHERE role_id = 2 AND permission_id = ?", [$canonId]);
    t("the alias repair DELETE (as shipped) revokes the leaked canonical grant for $code", !$aliasStillLeaked);

    // Cleanup fixture.
    db_query("UPDATE {$prefix}permissions SET deprecated_alias_of = NULL WHERE code = ?", [$code]);
    db_query("DELETE FROM {$prefix}role_permissions WHERE permission_id = ?", [$canonId]);
    db_query("DELETE FROM {$prefix}permissions WHERE id = ?", [$canonId]);
}

// Restore true state: Super Admin should hold both, Org Admin neither.
// (The repair DELETEs above only ever remove grants; run_00_rbac.php's
// broad sweep already re-granted Super Admin in Part 3.)
t("final state: Org Admin holds neither code after all repro/repair cycles", !_p141_role_has(2, $gId) && !_p141_role_has(2, $oId));
t("final state: Super Admin still holds both codes", _p141_role_has(1, $gId) && _p141_role_has(1, $oId));

// ═══════════════════════════════════════════════════════════════════════
// Part 5 — both repair statements are present in the shipped seed files,
// scoped to these two codes (structural check).
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Repair statements present in shipped seed files, naming both codes ---\n\n";

$rbacSql = file_get_contents($base . '/sql/rbac.sql');
$run00 = file_get_contents($base . '/sql/run_00_rbac.php');

t("sql/rbac.sql: permissions INSERT list includes action.manage_org_routing",
    strpos($rbacSql, "'action.manage_org_routing'") !== false);
t("sql/rbac.sql: permissions INSERT list includes action.manage_org_routing_org",
    strpos($rbacSql, "'action.manage_org_routing_org'") !== false);
t("sql/rbac.sql: Org Admin exclusion list includes both codes",
    (bool) preg_match("/NOT IN \\(.*?action\\.manage_org_routing.*?action\\.manage_org_routing_org.*?\\);/s", $rbacSql));
t("sql/rbac.sql: Dispatcher exclusion list includes both codes",
    substr_count($rbacSql, "'action.manage_org_routing'") >= 2
    && substr_count($rbacSql, "'action.manage_org_routing_org'") >= 2);
t("sql/rbac.sql: Org Admin repair DELETE #1 (direct) names action.manage_org_routing",
    (bool) preg_match("/role_id`\\s*=\\s*2[\\s\\S]*?action\\.manage_org_routing/", $rbacSql));
t("sql/run_00_rbac.php: \$perms seed array includes both codes",
    strpos($run00, "'action.manage_org_routing'") !== false
    && strpos($run00, "'action.manage_org_routing_org'") !== false);
t("sql/run_00_rbac.php: Org Admin NOT IN exclusion includes both codes",
    // Deliberately does NOT require action.manage_org_routing_org to be the
    // LAST entry before the closing paren -- Phase 143 (2026-08-17)
    // legitimately appended action.manage_org_relationships to this same
    // exclusion list, after both Phase 141 codes. Only proves both Phase
    // 141 codes are somewhere inside the SAME NOT IN (...) block, in order.
    (bool) preg_match("/NOT IN \\('action\\.manage_config'.*?action\\.manage_org_routing.*?action\\.manage_org_routing_org'/s", $run00));

$excludedByRoleTest = file_get_contents($base . '/tests/test_rbac_canonical_alias_leak.php');
t("tests/test_rbac_canonical_alias_leak.php \$excludedByRole[2] includes both new codes",
    (bool) preg_match("/2 => \\[.*?action\\.manage_org_routing.*?action\\.manage_org_routing_org.*?\\],/s", $excludedByRoleTest));
t("tests/test_rbac_canonical_alias_leak.php \$excludedByRole[3] includes both new codes",
    (bool) preg_match("/3 => \\[.*?action\\.manage_org_routing.*?action\\.manage_org_routing_org.*?\\],/s", $excludedByRoleTest));

// ═══════════════════════════════════════════════════════════════════════
// Part 6 — Admin UI (Section 8): neither api/org-routing.php nor
// org-routing-admin.php ever OR's in is_admin() on either gate. Structural
// check, same technique as Part 5 above and this project's own standing
// CLAUDE.md rule ("never write rbac_can($perm) || is_admin() for a
// narrower-tier permission") — is_admin()'s action.manage_config fallback
// can satisfy a stale/broad grant and silently widen a narrower gate.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Admin UI (api/org-routing.php, org-routing-admin.php): no is_admin() leak ---\n\n";

$adminApiSrc = @file_get_contents($base . '/api/org-routing.php');
$adminPageSrc = @file_get_contents($base . '/org-routing-admin.php');

t("api/org-routing.php exists", $adminApiSrc !== false);
t("org-routing-admin.php exists", $adminPageSrc !== false);

/**
 * Strips comments/docstrings via the real PHP tokenizer before a substring
 * check for is_admin( -- both admin files' docblocks legitimately DISCUSS
 * "never `rbac_can() || is_admin()`" in prose, and a plain grep/substring
 * scan cannot tell that explanation from an actual call, which is exactly
 * this project's own documented "tokenize, do not grep" lesson (CLAUDE.md,
 * the proc_open pipe deadlock entry: "a substring scan cannot tell an
 * explanation from an occurrence").
 */
function _p141_code_only(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

if ($adminApiSrc !== false) {
    $adminApiCode = _p141_code_only($adminApiSrc);
    t("api/org-routing.php: gates on action.manage_org_routing", strpos($adminApiSrc, "rbac_can('action.manage_org_routing')") !== false);
    t("api/org-routing.php: gates on action.manage_org_routing_org", strpos($adminApiSrc, "rbac_can('action.manage_org_routing_org')") !== false);
    t("api/org-routing.php: NEVER ORs is_admin() into either gate", !preg_match('/rbac_can\([^)]*manage_org_routing[^)]*\)\s*\|\|\s*is_admin\(\)/', $adminApiCode));
    t("api/org-routing.php: no is_admin() call in actual CODE (comments excluded via tokenizer)", strpos($adminApiCode, 'is_admin(') === false);
}
if ($adminPageSrc !== false) {
    $adminPageCode = _p141_code_only($adminPageSrc);
    t("org-routing-admin.php: gates on action.manage_org_routing", strpos($adminPageSrc, "rbac_can('action.manage_org_routing')") !== false);
    t("org-routing-admin.php: gates on action.manage_org_routing_org", strpos($adminPageSrc, "rbac_can('action.manage_org_routing_org')") !== false);
    t("org-routing-admin.php: NEVER ORs is_admin() into either gate", !preg_match('/rbac_can\([^)]*manage_org_routing[^)]*\)\s*\|\|\s*is_admin\(\)/', $adminPageCode));
    t("org-routing-admin.php: no is_admin() call in actual CODE (comments excluded via tokenizer)", strpos($adminPageCode, 'is_admin(') === false);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 7 — Phase 142 (2026-08-17): manual cross-org ticket sharing,
// GH#70 Phase 2. Extends THIS file per plan.md row 10 / tasks.md section
// 2's own instruction ("Extend tests/test_org_sharing_rbac.php -- Phase
// 141's own file, not a new one"), because Phase 142's two codes are the
// MIRROR case of Part 2 above: codes that should be HELD by default, not
// excluded. Run BEFORE any Phase 142 endpoint code exists -- this proves
// the RBAC seeding is correct in isolation, same discipline Phase 141
// used for its own RBAC test.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Phase 142: action.share_incident / action.revoke_incident_share ---\n\n";

$permShare  = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.share_incident']);
$permRevoke = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.revoke_incident_share']);
t("action.share_incident permission row exists", (bool) $permShare);
t("action.revoke_incident_share permission row exists", (bool) $permRevoke);

if ($permShare && $permRevoke) {
    $shareId  = (int) $permShare['id'];
    $revokeId = (int) $permRevoke['id'];

    echo "\n--- inclusion-side role grants (the MIRROR of Part 2 above -- these codes should be HELD) ---\n\n";

    t("Super Admin (role 1) holds action.share_incident", _p141_role_has(1, $shareId));
    t("Super Admin (role 1) holds action.revoke_incident_share", _p141_role_has(1, $revokeId));
    t("Org Admin (role 2) holds action.share_incident (granted by default, unlike Phase 141's codes)", _p141_role_has(2, $shareId));
    t("Org Admin (role 2) holds action.revoke_incident_share (granted by default)", _p141_role_has(2, $revokeId));
    t("Dispatcher (role 3) holds action.share_incident (spec.md user story 1's own persona)", _p141_role_has(3, $shareId));
    t("Dispatcher (role 3) holds action.revoke_incident_share", _p141_role_has(3, $revokeId));
    t("Operator (role 4) does NOT hold action.share_incident (allow-list withholds it by construction)", !_p141_role_has(4, $shareId));
    t("Operator (role 4) does NOT hold action.revoke_incident_share", !_p141_role_has(4, $revokeId));
    t("Read-Only (role 5) does NOT hold action.share_incident", !_p141_role_has(5, $shareId));
    t("Read-Only (role 5) does NOT hold action.revoke_incident_share", !_p141_role_has(5, $revokeId));
    t("Field Unit (role 6) does NOT hold action.share_incident", !_p141_role_has(6, $shareId));
    t("Field Unit (role 6) does NOT hold action.revoke_incident_share", !_p141_role_has(6, $revokeId));

    echo "\n--- re-run of run_00_rbac.php's broad sweep still holds both codes for Org Admin/Dispatcher ---\n\n";

    if (file_exists($rbacScript)) {
        $out2 = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($rbacScript) . ' 2>&1');
        t("re-running sql/run_00_rbac.php exits without a fatal error", strpos((string) $out2, 'Fatal error') === false);
        t("AFTER re-running run_00_rbac.php: Org Admin still holds both Phase 142 codes", _p141_role_has(2, $shareId) && _p141_role_has(2, $revokeId));
        t("AFTER re-running run_00_rbac.php: Dispatcher still holds both Phase 142 codes", _p141_role_has(3, $shareId) && _p141_role_has(3, $revokeId));
        t("AFTER re-running run_00_rbac.php: Operator still does NOT hold either Phase 142 code", !_p141_role_has(4, $shareId) && !_p141_role_has(4, $revokeId));
    }

    echo "\n--- shipped seed files carry both codes, correctly NOT in any exclusion/repair list ---\n\n";

    $rbacSql2 = file_get_contents($base . '/sql/rbac.sql');
    $run002 = file_get_contents($base . '/sql/run_00_rbac.php');

    t("sql/rbac.sql: permissions INSERT list includes action.share_incident",
        strpos($rbacSql2, "'action.share_incident'") !== false);
    t("sql/rbac.sql: permissions INSERT list includes action.revoke_incident_share",
        strpos($rbacSql2, "'action.revoke_incident_share'") !== false);
    t("sql/rbac.sql: Org Admin exclusion list does NOT name action.share_incident (absence grants by construction)",
        !preg_match("/NOT IN \\([^;]*?'action\\.share_incident'/s", $rbacSql2));
    t("sql/rbac.sql: Dispatcher exclusion list does NOT name action.share_incident either (this file's Dispatcher mapping is also a broad NOT IN)",
        substr_count($rbacSql2, "'action.share_incident'") === 1);
    t("sql/run_00_rbac.php: \$perms seed array includes both Phase 142 codes",
        strpos($run002, "'action.share_incident'") !== false && strpos($run002, "'action.revoke_incident_share'") !== false);
    t("sql/run_00_rbac.php: Org Admin NOT IN exclusion does NOT name either Phase 142 code",
        !preg_match("/NOT IN \\([^)]*?action\\.share_incident/s", $run002));
    t("sql/run_00_rbac.php: Dispatcher ALLOW-list DOES explicitly name both Phase 142 codes (the one file/role combination that needs an edit, per plan.md's verified asymmetry)",
        (bool) preg_match("/role_id`, `permission_id`\\)\\s*\\n\\s*SELECT 3,[\\s\\S]*?'action\\.share_incident'[\\s\\S]*?'action\\.revoke_incident_share'/", $run002));

    echo "\n--- tests/test_rbac_canonical_alias_leak.php must NOT list these codes as excluded (mirror-case guard) ---\n\n";

    t("test_rbac_canonical_alias_leak.php \$excludedByRole[2] (Org Admin) does NOT include action.share_incident",
        !preg_match("/2 => \\[[^\\]]*?action\\.share_incident/s", $excludedByRoleTest));
    t("test_rbac_canonical_alias_leak.php \$excludedByRole[3] (Dispatcher) does NOT include action.share_incident",
        !preg_match("/3 => \\[[^\\]]*?action\\.share_incident/s", $excludedByRoleTest));
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
