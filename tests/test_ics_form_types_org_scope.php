<?php
/**
 * Phase 140 (2026-08-16) — api/ics-form-types.php's org-scope decision
 * functions (tasks.md §3).
 *
 * Driving api/ics-form-types.php end-to-end over real HTTP with a real
 * login session is out of proportion to what's actually being protected --
 * same principle this project already applies to api/public-board-admin.php
 * (see tests/test_public_board_rbac.php's own docblock: the entire fix IS
 * the branching in the resolver function, extracted to be pure for exactly
 * this reason). This test drives ics_form_types_resolve_create_org() and
 * ics_form_types_resolve_caller_org_id() (inc/ics-form-types.php) directly
 * with every combination the CREATE endpoint can hit, plus the slug-
 * uniqueness-per-scope check the API's create handler enforces.
 *
 * ics_form_custom_template() -- the READ-side org-scope choke point this
 * API's `GET ?id=X` delegates to directly -- already has exhaustive live-
 * fixture coverage in tests/test_ics_form_types_validate.php Part 7; not
 * duplicated here.
 *
 * @requires-db
 * Usage: php tests/test_ics_form_types_org_scope.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/ics-form-types.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}
function tbl($n) { return db_table($n); }

echo "=== Phase 140 — api/ics-form-types.php org-scope decisions ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — ics_form_types_resolve_create_org(): every combination
// ═══════════════════════════════════════════════════════════════════════

echo "--- resolve_create_org: global author ---\n\n";

$d = ics_form_types_resolve_create_org(true, false, 0, 5);
t("global author: requested org_id is honored as-is", $d['ok'] === true && $d['org_id'] === 5);

$d = ics_form_types_resolve_create_org(true, false, 0, null);
t("global author: no org_id requested -> install-wide (null)", $d['ok'] === true && $d['org_id'] === null);

$d = ics_form_types_resolve_create_org(true, false, 0, 0);
t("global author: org_id=0 requested -> treated as install-wide (null)", $d['ok'] === true && $d['org_id'] === null);

echo "\n--- resolve_create_org: org-scoped-only author ---\n\n";

$d = ics_form_types_resolve_create_org(false, true, 7, null);
t("org-scoped author, no org_id in request: FORCED to their own resolved org (7), never trusts an absent value as install-wide",
    $d['ok'] === true && $d['org_id'] === 7);

$d = ics_form_types_resolve_create_org(false, true, 7, 7);
t("org-scoped author requesting THEIR OWN org_id: accepted", $d['ok'] === true && $d['org_id'] === 7);

$d = ics_form_types_resolve_create_org(false, true, 7, 99);
t("org-scoped author requesting a DIFFERENT org_id (99): REJECTED outright, not silently overridden",
    $d['ok'] === false && $d['status'] === 403);

$d = ics_form_types_resolve_create_org(false, true, 0, null);
t("org-scoped author with NO resolvable own org (ambiguous/no grant): REJECTED with 403, never silently install-wide",
    $d['ok'] === false && $d['status'] === 403 && $d['org_id'] === null);

echo "\n--- resolve_create_org: neither permission ---\n\n";

$d = ics_form_types_resolve_create_org(false, false, 0, null);
t("caller with neither authoring permission: forbidden", $d['ok'] === false && $d['status'] === 403);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — ics_form_types_resolve_caller_org_id(): live fixture
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- resolve_caller_org_id: live fixtures ---\n\n";

$hasTypesTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'ics_form_types']
);
if (!$hasTypesTable) {
    echo "SKIP: ics_form_types table not present -- run sql/run_phase140_custom_ics_form_types.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$oa = db_fetch_one("SELECT id FROM " . tbl('roles') . " WHERE name = 'Org Admin' LIMIT 1");
if (!$oa) {
    echo "SKIP: Org Admin role not present -- nothing to test.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}
$orgAdminRoleId = (int) $oa['id'];

t("with no grant at all, resolve_caller_org_id returns 0 (ambiguous/none)",
    ics_form_types_resolve_caller_org_id(900002199) === 0);

$createdOrg = null; $createdUser = null; $createdUR = null;
try {
    db_query("INSERT INTO " . tbl('organizations') . " (name, short_name, active, sort_order) VALUES (?,?,1,999)",
        ['zz-test-140-resolve-org', 'ZZ140R']);
    $createdOrg = (int) db_insert_id();

    $cols = array_column(db_fetch_all("DESCRIBE " . tbl('user')), null, 'Field');
    $fields = [];
    if (isset($cols['user']))          $fields['user']     = 'zz-test-140r';
    elseif (isset($cols['username']))  $fields['username'] = 'zz-test-140r';
    if (isset($cols['passwd']))        $fields['passwd']   = password_hash('unused', PASSWORD_BCRYPT);
    elseif (isset($cols['password']))  $fields['password'] = password_hash('unused', PASSWORD_BCRYPT);
    if (isset($cols['level']))         $fields['level']    = 5;
    if (isset($cols['email']))         $fields['email']    = 'zz140r@example.invalid';
    $fn = array_keys($fields);
    db_query("INSERT INTO " . tbl('user') . " (`" . implode('`,`', $fn) . "`) VALUES (" .
        implode(',', array_fill(0, count($fn), '?')) . ")", array_values($fields));
    $createdUser = (int) db_insert_id();

    $urCols = array_column(db_fetch_all("DESCRIBE " . tbl('user_roles')), null, 'Field');
    $ur = ['user_id' => $createdUser, 'role_id' => $orgAdminRoleId, 'scope_kind' => 'org', 'scope_id' => $createdOrg];
    if (isset($urCols['org_id']))     $ur['org_id'] = $createdOrg;
    if (isset($urCols['granted_at'])) $ur['granted_at'] = date('Y-m-d H:i:s');
    $un = array_keys($ur);
    db_query("INSERT INTO " . tbl('user_roles') . " (`" . implode('`,`', $un) . "`) VALUES (" .
        implode(',', array_fill(0, count($un), '?')) . ")", array_values($ur));
    $createdUR = (int) db_insert_id();

    t("a user with exactly one org-scoped action.manage_ics_form_types_org grant resolves to that org",
        ics_form_types_resolve_caller_org_id($createdUser) === $createdOrg);

} catch (Throwable $e) {
    t('setup/exec without error: ' . $e->getMessage(), false);
} finally {
    try { if ($createdUR)   db_query("DELETE FROM " . tbl('user_roles') . " WHERE id=?", [$createdUR]); } catch (Throwable $e) {}
    try { if ($createdUser) db_query("DELETE FROM " . tbl('user') . " WHERE id=?", [$createdUser]); } catch (Throwable $e) {}
    try { if ($createdOrg)  db_query("DELETE FROM " . tbl('organizations') . " WHERE id=?", [$createdOrg]); } catch (Throwable $e) {}
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — API file structural sanity (never a hand-drift copy of the
// endpoint list its own docblock promises)
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- api/ics-form-types.php structural checks ---\n\n";

$src = file_get_contents(__DIR__ . '/../api/ics-form-types.php');
t("endpoint requires auth.php (session-gated, not credential-free)", strpos($src, "require_once __DIR__ . '/auth.php'") !== false);
t("endpoint enforces CSRF on every POST", strpos($src, '_icsft_require_csrf($input)') !== false);
// create + update + the shared archive/restore branch = 3 call sites
// (archive and restore share one audit_log() call keyed by $action).
t("create/update/archive-or-restore are all audit-logged", substr_count($src, "audit_log('config',") === 3);
t("no delete action exists (archiving is the only removal path, per plan.md)", strpos($src, "action === 'delete'") === false);
t("the read choke point (GET ?id=X) delegates to ics_form_custom_template(), not a second copy of the org-scope check",
    (bool) preg_match('/ics_form_custom_template\(\$id\)/', $src));
// Strip comment lines first -- the docblock/comments legitimately DISCUSS
// `|| is_admin()` (in backticks, explaining why it's deliberately absent);
// only a REAL code occurrence would be a regression.
$codeOnly = preg_replace('#^\s*(//|\*|/\*).*$#m', '', $src);
t("neither RBAC gate falls back to is_admin() in actual code (plan.md's central requirement)",
    strpos($codeOnly, 'is_admin(') === false);

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — admin JS ↔ GET ?id=X contract (2026-08-16 live-verification find)
// ═══════════════════════════════════════════════════════════════════════
//
// Live browser testing of ics-form-type-admin.php caught a real bug the
// PHP-side audits above cannot: GET ?id=X delegates straight to
// ics_form_custom_template(), whose return shape names the id key
// `custom_type_id` (matching how assets/js/ics-forms.js's openFormEditor()
// already reads that SAME function's output) -- but the admin editor's
// openEditor(type) read `type.id`, which that shape never has. editingId
// came out NaN, so "Edit -> Save" silently took the CREATE branch instead
// of UPDATE and hit the duplicate-slug 409 guard (no data corruption, but
// editing was completely broken). tools/api_contract_audit.php could not
// catch this: `id` is emitted by many OTHER endpoints project-wide, so a
// global "is this key emittable anywhere" check passes even when THIS
// endpoint's response never has it. Confirmed live: GET ?id=X's actual
// key set is [form_type, custom_type_id, slug, form_number, form_title,
// description, badge_color, icon, org_id, status, restrict_to_permission,
// fields] -- no bare `id`.
echo "\n--- admin JS reads the real GET ?id=X key contract ---\n\n";

$adminJsSrc = file_get_contents(__DIR__ . '/../assets/js/ics-form-type-admin.js');
t("openEditor() reads type.custom_type_id (the key ics_form_custom_template() actually returns)",
    (bool) preg_match('/editingId\s*=\s*type\s*\?\s*parseInt\(\s*type\.custom_type_id/', $adminJsSrc));
t("openEditor() does NOT read the nonexistent type.id (the confirmed-live regression)",
    !preg_match('/editingId\s*=\s*type\s*\?\s*parseInt\(\s*type\.id\b/', $adminJsSrc));
t("archive/restore POSTs include csrf_token (2026-08-16 live-verification find: this was also missing)",
    (bool) preg_match('/postJson\(\{\s*action:\s*action,\s*id:\s*id,\s*csrf_token:\s*csrfToken\s*\}\)/', $adminJsSrc));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
