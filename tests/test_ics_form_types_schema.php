<?php
/**
 * Phase 140 (2026-08-16) — Custom ICS Form Types: schema + migration.
 *
 * Covers:
 *   1. Table/column existence for ics_form_types and ics_forms.custom_type_id.
 *   2. The NULLable-unique-key fix actually binds: two install-wide
 *      (org_id NULL) rows with the same slug are rejected, not silently
 *      allowed (the exact bug class Phase 129's uk_user_role_scope fixed).
 *      Two DIFFERENT orgs may reuse the same slug -- proving org_key
 *      distinguishes real org ids correctly, not just NULL vs non-NULL.
 *   3. Idempotent re-run: running the migration script twice produces no
 *      duplicate rows/errors.
 *   4. RBAC: both permission codes exist, and the exact role grants match
 *      the design (Super Admin: both; Org Admin: org-scoped only;
 *      Dispatcher/Operator/Read-Only: neither) -- across BOTH seed paths
 *      (the phase migration's own direct grant, and a re-run of
 *      sql/run_00_rbac.php's broad sweep, which must not leak the
 *      install-wide permission to Org Admin via its NOT IN exclusion list).
 *
 * @requires-db
 * Usage: php tests/test_ics_form_types_schema.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 140 — Custom ICS Form Types: Schema ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — table/column existence
// ═══════════════════════════════════════════════════════════════════════

echo "--- Structural ---\n\n";

$hasTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'ics_form_types']
);
if (!$hasTable) {
    echo "\nSKIP: ics_form_types table not present -- run sql/run_phase140_custom_ics_form_types.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}
t("ics_form_types table exists", $hasTable);

$cols = db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'ics_form_types']
);
$colNames = array_column($cols, 'COLUMN_NAME');
foreach (['id', 'slug', 'form_number', 'form_title', 'description', 'fields_json',
          'badge_color', 'icon', 'org_id', 'org_key', 'status', 'restrict_to_permission',
          'created_by', 'created_by_name', 'created_at', 'updated_at'] as $c) {
    t("ics_form_types.$c exists", in_array($c, $colNames, true));
}

$hasCustomTypeId = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
    [$prefix . 'ics_forms', 'custom_type_id']
);
t("ics_forms.custom_type_id column exists", $hasCustomTypeId);

$hasIdx = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
    [$prefix . 'ics_forms', 'idx_ics_custom_type_id']
);
t("ics_forms.idx_ics_custom_type_id index exists", $hasIdx);

$uk = db_fetch_all(
    "SELECT COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX",
    [$prefix . 'ics_form_types', 'uk_ics_form_type_slug_org']
);
$ukCols = array_column($uk, 'COLUMN_NAME');
t("uk_ics_form_type_slug_org covers (org_key, slug) in that order", $ukCols === ['org_key', 'slug']);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — the NULLable-unique-key fix actually binds
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- NULLable-unique-key fix (org_key generated column) ---\n\n";

// Fake org ids well outside any real range, mirroring this project's
// established convention for throwaway test fixtures.
$orgA = 900002140;
$orgB = 900002141;
$testSlug = 'phase140schematest';

db_query("DELETE FROM {$prefix}ics_form_types WHERE slug = ?", [$testSlug]);

db_query(
    "INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id) VALUES (?, ?, ?, NULL)",
    [$testSlug, 'Install-wide A', '[]']
);
t("first install-wide (org_id NULL) row with a fresh slug inserts fine", true);

$dupRejected = false;
try {
    db_query(
        "INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id) VALUES (?, ?, ?, NULL)",
        [$testSlug, 'Install-wide B (duplicate)', '[]']
    );
} catch (Exception $e) {
    $dupRejected = (bool) preg_match('/1062|Duplicate entry/i', $e->getMessage());
}
t("a SECOND install-wide row with the SAME slug is rejected (org_key coalesces NULL -> -1, binding the key)", $dupRejected);

$orgAInserted = false;
try {
    db_query(
        "INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id) VALUES (?, ?, ?, ?)",
        [$testSlug, 'Org A version', '[]', $orgA]
    );
    $orgAInserted = true;
} catch (Exception $e) {
    // fall through -- assertion below reports the failure
}
t("a DIFFERENT org (org_id=$orgA) MAY reuse the same slug the install-wide row already used", $orgAInserted);

$orgBInserted = false;
try {
    db_query(
        "INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id) VALUES (?, ?, ?, ?)",
        [$testSlug, 'Org B version', '[]', $orgB]
    );
    $orgBInserted = true;
} catch (Exception $e) {
    // fall through
}
t("a THIRD, different org (org_id=$orgB) may ALSO reuse the same slug", $orgBInserted);

$orgADupRejected = false;
try {
    db_query(
        "INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id) VALUES (?, ?, ?, ?)",
        [$testSlug, 'Org A duplicate', '[]', $orgA]
    );
} catch (Exception $e) {
    $orgADupRejected = (bool) preg_match('/1062|Duplicate entry/i', $e->getMessage());
}
t("a duplicate slug WITHIN org A's own scope is still rejected (real org ids bind their own key, not just NULL)", $orgADupRejected);

db_query("DELETE FROM {$prefix}ics_form_types WHERE slug = ?", [$testSlug]);

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — idempotent re-run of the migration script
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Idempotent re-run ---\n\n";

$countBefore = (int) db_fetch_value("SELECT COUNT(*) AS c FROM {$prefix}permissions WHERE code LIKE ?", ['action.manage_ics_form_types%']);

$migScript = $base . '/sql/run_phase140_custom_ics_form_types.php';
t("migration script file exists", file_exists($migScript));

if (file_exists($migScript)) {
    $phpBin = PHP_BINARY ?: 'php';
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($migScript) . ' 2>&1');
    t("re-running the migration script exits without a fatal error", strpos((string) $out, 'Fatal error') === false);
    t("re-running the migration script reports the table already exists", strpos((string) $out, 'already exists') !== false);
}

$countAfter = (int) db_fetch_value("SELECT COUNT(*) AS c FROM {$prefix}permissions WHERE code LIKE ?", ['action.manage_ics_form_types%']);
t("re-run does not duplicate permission rows ($countBefore before, $countAfter after)", $countBefore === $countAfter && $countAfter === 2);

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — RBAC: permission rows + exact role-grant boundaries
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- RBAC permissions + role grants ---\n\n";

$permGlobal = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_ics_form_types']);
$permOrg    = db_fetch_one("SELECT id FROM {$prefix}permissions WHERE code = ?", ['action.manage_ics_form_types_org']);
t("action.manage_ics_form_types permission row exists", (bool) $permGlobal);
t("action.manage_ics_form_types_org permission row exists", (bool) $permOrg);

function _p140_role_has(int $roleId, int $permId): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = ? AND permission_id = ?",
        [$roleId, $permId]
    );
}

if ($permGlobal && $permOrg) {
    $gId = (int) $permGlobal['id'];
    $oId = (int) $permOrg['id'];

    t("Super Admin (role 1) holds action.manage_ics_form_types", _p140_role_has(1, $gId));
    t("Super Admin (role 1) holds action.manage_ics_form_types_org", _p140_role_has(1, $oId));
    t("Org Admin (role 2) does NOT hold the install-wide permission", !_p140_role_has(2, $gId));
    t("Org Admin (role 2) DOES hold the org-scoped permission", _p140_role_has(2, $oId));
    t("Dispatcher (role 3) does NOT hold the install-wide permission", !_p140_role_has(3, $gId));
    t("Dispatcher (role 3) does NOT hold the org-scoped permission either (authoring is administrative, not dispatch)", !_p140_role_has(3, $oId));
    t("Operator (role 4) does NOT hold the install-wide permission", !_p140_role_has(4, $gId));
    t("Operator (role 4) does NOT hold the org-scoped permission", !_p140_role_has(4, $oId));
    t("Read-Only (role 5) does NOT hold the install-wide permission", !_p140_role_has(5, $gId));
    t("Read-Only (role 5) does NOT hold the org-scoped permission", !_p140_role_has(5, $oId));
}

// ═══════════════════════════════════════════════════════════════════════
// Part 5 — re-running run_00_rbac.php's broad sweep must not leak
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Re-run of run_00_rbac.php broad sweep does not leak install-wide grant ---\n\n";

$rbacScript = $base . '/sql/run_00_rbac.php';
if (file_exists($rbacScript) && $permGlobal && $permOrg) {
    $phpBin = PHP_BINARY ?: 'php';
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($rbacScript) . ' 2>&1');
    t("re-running sql/run_00_rbac.php exits without a fatal error", strpos((string) $out, 'Fatal error') === false);

    $gId = (int) $permGlobal['id'];
    $oId = (int) $permOrg['id'];
    t("AFTER re-running run_00_rbac.php: Org Admin still does NOT hold the install-wide permission (exclusion list is in sync)", !_p140_role_has(2, $gId));
    t("AFTER re-running run_00_rbac.php: Org Admin still holds the org-scoped permission", _p140_role_has(2, $oId));
    t("AFTER re-running run_00_rbac.php: Dispatcher still holds neither", !_p140_role_has(3, $gId) && !_p140_role_has(3, $oId));
} else {
    echo "SKIP: sql/run_00_rbac.php not found or permission rows missing.\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
