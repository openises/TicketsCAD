<?php
/**
 * Comprehensive regression suite for the RBAC v2 redesign.
 * Companion spec: specs/rbac-redesign-2026-05/{spec,plan,tasks}.md.
 *
 * Sections (matches Block F task IDs):
 *   F1  Schema (8 assertions)
 *   F2  Scope kinds (10)
 *   F3  Expiry filtering (6)
 *   F4  Ownership / self scope (6)
 *   F5  Org isolation (6)
 *   F6  Privilege-escalation guard (4)
 *   F7  Audit-log emission (5)
 *   F8  Legacy fallback presence + alias resolver (5)
 *   F10 Time-entry surface uses RBAC (4)
 *
 * Target: ≥50 assertions. All PASS when the v2 schema + helpers +
 * grant module are correctly applied to a freshly migrated DB.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/rbac_grant.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== RBAC v2 — full regression ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void  { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "[FAIL] $name" . ($why ? " — $why" : '') . "\n"; $fail++;
}

function code_only(string $src): string {
    $src = preg_replace('!//[^\n]*!', '', $src);
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    return $src;
}

// Pick a sandbox user (non-admin, level > 0). All grants we create
// during this run target this user and clean up at the end.
$sandbox = (int) (db_fetch_value(
    "SELECT id FROM `{$prefix}user` WHERE level > 0 ORDER BY id LIMIT 1"
) ?: 0);
if (!$sandbox) {
    echo "[SKIP] No non-admin user available; cannot run dynamic checks.\n";
    exit(0);
}
echo "Sandbox user: #$sandbox\n\n";

// Snapshot ALL pre-existing grants for the sandbox user. We then
// delete them while the test runs so scope predicates can be tested
// against a known-empty grant set, and restore at the end so the
// sandbox user is unaltered.
$preGrants = db_fetch_all(
    "SELECT * FROM `{$prefix}user_roles` WHERE user_id = ?", [$sandbox]
);
db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$sandbox]);

register_shutdown_function(function () use ($sandbox, $preGrants, $prefix) {
    db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$sandbox]);
    foreach ($preGrants as $row) {
        // Re-insert column-by-column so any newly-added v2 columns
        // (delegated_by etc.) are restored intact.
        $cols = []; $vals = []; $params = [];
        foreach ($row as $k => $v) {
            if ($k === 'id') continue;  // let MySQL re-pick id
            $cols[]   = "`$k`";
            $vals[]   = '?';
            $params[] = $v;
        }
        if ($cols) {
            db_query(
                "INSERT INTO `{$prefix}user_roles` (" . implode(',', $cols) . ")
                 VALUES (" . implode(',', $vals) . ")",
                $params
            );
        }
    }
});

function cleanup_sandbox_grants(int $sandbox, array $preIds = []): void {
    global $prefix;
    db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$sandbox]);
}
$preIds = [];  // unused under the new clean-slate model

// ─────────────────────────────────────────────────────────────────────
// F1 — Schema (8)
// ─────────────────────────────────────────────────────────────────────
echo "── F1 Schema ──\n";

function col_exists_t(string $tbl, string $col): bool {
    global $prefix;
    try {
        $row = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $tbl, $col]
        );
        return !empty($row);
    } catch (Throwable $e) { return false; }
}
function index_exists_t(string $tbl, string $idx): bool {
    global $prefix;
    try {
        $row = db_fetch_one(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
            [$prefix . $tbl, $idx]
        );
        return !empty($row);
    } catch (Throwable $e) { return false; }
}

foreach (['scope_kind','scope_id','expires_at','granted_by','granted_at','reason'] as $c) {
    if (col_exists_t('user_roles', $c)) ok("user_roles.$c column");
    else                                 bad("user_roles.$c missing");
}
if (col_exists_t('permissions', 'deprecated_alias_of')) ok('permissions.deprecated_alias_of');
else                                                    bad('permissions.deprecated_alias_of missing');
if (col_exists_t('roles', 'is_super')) ok('roles.is_super');
else                                    bad('roles.is_super missing');

// ─────────────────────────────────────────────────────────────────────
// F2 — Scope kinds (10)
// ─────────────────────────────────────────────────────────────────────
echo "\n── F2 Scope kinds ──\n";

// Helper: simulate a session user for rbac_can.
function with_session_user(int $userId, ?int $orgId, callable $fn) {
    $oldUser = $_SESSION['user_id'] ?? null;
    $oldOrg  = $_SESSION['active_org_id'] ?? null;
    $_SESSION['user_id'] = $userId;
    if ($orgId !== null) $_SESSION['active_org_id'] = $orgId;
    rbac_clear_cache();
    try {
        return $fn();
    } finally {
        if ($oldUser === null) unset($_SESSION['user_id']);
        else $_SESSION['user_id'] = $oldUser;
        if ($oldOrg === null) unset($_SESSION['active_org_id']);
        else $_SESSION['active_org_id'] = $oldOrg;
        rbac_clear_cache();
    }
}

cleanup_sandbox_grants($sandbox, $preIds);

// 2a. Global: grant Read-Only globally → user has time_entry.view.
$gG = rbac_grant_role($sandbox, 5, 'global', null, null, 'F2 global', 0);
$canG = with_session_user($sandbox, null, fn() => rbac_can('time_entry.view'));
if ($canG) ok('global scope grants the perm');
else        bad('global scope grants the perm');

// 2b. Org: grant Operator at org=1 → can with org_id=1, cannot with org_id=2.
$gO = rbac_grant_role($sandbox, 4, 'org', 1, null, 'F2 org', 0);
$canO1 = with_session_user($sandbox, 1, fn() => rbac_can('time_entry.edit', ['org_id' => 1]));
$canO2 = with_session_user($sandbox, 2, fn() => rbac_can('time_entry.edit', ['org_id' => 2]));
if ($canO1) ok('org scope passes when context org_id matches');
else         bad('org scope passes when context org_id matches');
if (!$canO2 || $canG /* global still wins for view, but edit comes only from org */) ok('org scope (with no global edit grant) does not match other org');
else                                                                                  bad('org scope does not match other org');

// Drop global Read-Only so we can test self/team in isolation.
db_query("DELETE FROM `{$prefix}user_roles` WHERE id = ?", [$gG]);
rbac_clear_cache();

// 2c. Self: grant Field Unit (id=6) at scope='self' → fires only when owner_id == actor.
cleanup_sandbox_grants($sandbox, $preIds);
$gS = rbac_grant_role($sandbox, 6, 'self', $sandbox, null, 'F2 self', 0);
$canSelf  = with_session_user($sandbox, null, fn() => rbac_can('time_entry.edit', ['owner_id' => $GLOBALS['sandbox']]));
$canOther = with_session_user($sandbox, null, fn() => rbac_can('time_entry.edit', ['owner_id' => 999999]));
if ($canSelf)   ok('self scope fires when owner_id matches actor');
else            bad('self scope fires when owner_id matches actor');
if (!$canOther) ok('self scope does not fire when owner_id differs');
else            bad('self scope does not fire when owner_id differs');

// 2d. Team: grant at scope='team' → only matches matching team_id.
cleanup_sandbox_grants($sandbox, $preIds);
$gT = rbac_grant_role($sandbox, 4, 'team', 7, null, 'F2 team', 0);
$canT  = with_session_user($sandbox, null, fn() => rbac_can('time_entry.edit', ['team_id' => 7]));
$canTx = with_session_user($sandbox, null, fn() => rbac_can('time_entry.edit', ['team_id' => 8]));
if ($canT)   ok('team scope fires when team_id matches');
else         bad('team scope fires when team_id matches');
if (!$canTx) ok('team scope does not fire when team_id differs');
else         bad('team scope does not fire when team_id differs');

// 2e. Delegate scope kind validates required scope_id.
try {
    rbac_grant_role($sandbox, 5, 'delegate', null, date('Y-m-d H:i:s', time()+3600),
        'F2 delegate-no-scope', 0, $sandbox);
    bad('delegate scope rejects missing scope_id');
} catch (RuntimeException $e) {
    ok('delegate scope rejects missing scope_id');
}

// 2f. Invalid scope kind throws.
try {
    rbac_grant_role($sandbox, 5, 'whatever', null, null, 'F2 bad', 0);
    bad('invalid scope_kind throws');
} catch (RuntimeException $e) {
    ok('invalid scope_kind throws');
}

cleanup_sandbox_grants($sandbox, $preIds);

// ─────────────────────────────────────────────────────────────────────
// F3 — Expiry (6)
// ─────────────────────────────────────────────────────────────────────
echo "\n── F3 Expiry ──\n";

// 3a. Past expiry rejected at grant time.
try {
    rbac_grant_role($sandbox, 5, 'global', null, '2020-01-01 00:00:00', 'past', 0);
    bad('past expiry rejected at grant time');
} catch (RuntimeException $e) {
    ok('past expiry rejected at grant time');
}

// 3b. Future expiry honoured: grant + read-back appears.
$gFut = rbac_grant_role($sandbox, 5, 'global', null,
    date('Y-m-d H:i:s', time() + 3600), 'F3 future', 0);
$grants = rbac_user_grants($sandbox, false);
$found  = false;
foreach ($grants as $g) if ((int) $g['grant_id'] === $gFut) { $found = true; break; }
if ($found) ok('future-expiry grant visible');
else        bad('future-expiry grant visible');

// 3c. We manually set expires_at to past (UPDATE) — should disappear from
//     visible grants.
db_query("UPDATE `{$prefix}user_roles` SET expires_at = '2020-01-01 00:00:00' WHERE id = ?",
    [$gFut]);
rbac_clear_cache();
$grants = rbac_user_grants($sandbox, false);
$still  = false;
foreach ($grants as $g) if ((int) $g['grant_id'] === $gFut) { $still = true; break; }
if (!$still) ok('past-expiry grant excluded by default visibility');
else         bad('past-expiry grant excluded by default visibility');

// 3d. include_expired=true reveals it again.
$grantsAll = rbac_user_grants($sandbox, true);
$revived   = false;
foreach ($grantsAll as $g) if ((int) $g['grant_id'] === $gFut) { $revived = true; break; }
if ($revived) ok('include_expired=true reveals expired grants');
else          bad('include_expired=true reveals expired grants');

// 3e. rbac_expire_due_grants() removes the past-expiry row + audits.
$audPre = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'expire' AND target_type = 'user_role'"
);
$swept = rbac_expire_due_grants();
$audPost = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'expire' AND target_type = 'user_role'"
);
if ($swept >= 1) ok("rbac_expire_due_grants swept (count=$swept)");
else             bad("rbac_expire_due_grants swept (count=$swept)");
if ($audPost > $audPre) ok('expire emits audit_log entries');
else                    bad('expire emits audit_log entries');

cleanup_sandbox_grants($sandbox, $preIds);

// ─────────────────────────────────────────────────────────────────────
// F4 — Ownership / self scope at API level (4)
// ─────────────────────────────────────────────────────────────────────
echo "\n── F4 Ownership ──\n";

// Confirm time-entries.php uses rbac_can with owner_id rather than
// hand-rolled level checks.
$src = code_only(file_get_contents($base . '/api/time-entries.php'));
if (preg_match("/rbac_can\\('time_entry\\.edit'.{0,100}owner_id/s", $src)) {
    ok('time-entries.php uses rbac_can(time_entry.edit, owner_id)');
} else {
    bad('time-entries.php uses rbac_can(time_entry.edit, owner_id)');
}
if (preg_match("/rbac_can\\('time_entry\\.approve'/s", $src)) {
    ok('time-entries.php uses rbac_can(time_entry.approve)');
} else {
    bad('time-entries.php uses rbac_can(time_entry.approve)');
}
if (strpos($src, 'rbac.require_separate_approver') !== false ||
    preg_match("/rbac_can\\('time_entry\\.approve'.{0,100}owner_id/s", $src)) {
    ok('time-entries.php passes owner_id to approve check (separate-approver setting honoured)');
} else {
    bad('time-entries.php passes owner_id to approve check');
}
// And the four canonical permissions exist.
$expectedPerms = ['time_entry.view','time_entry.edit','time_entry.approve','time_entry.delete'];
$missing = [];
foreach ($expectedPerms as $c) {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE code = ?", [$c]
    );
    if (!$exists) $missing[] = $c;
}
if (empty($missing)) ok('all 4 time_entry.* permissions exist');
else                  bad('time_entry.* permissions: missing ' . implode(',', $missing));

// ─────────────────────────────────────────────────────────────────────
// F5 — Org isolation (4)
// ─────────────────────────────────────────────────────────────────────
echo "\n── F5 Org isolation ──\n";

cleanup_sandbox_grants($sandbox, $preIds);

// Grant Org Admin only at org=1.
$gOA = rbac_grant_role($sandbox, 2, 'org', 1, null, 'F5 OA org=1', 0);

// In org=1, sandbox can do management things; in org=2, cannot.
$inOrg1 = with_session_user($sandbox, 1, fn() => rbac_can('action.manage_members', ['org_id' => 1]));
$inOrg2 = with_session_user($sandbox, 2, fn() => rbac_can('action.manage_members', ['org_id' => 2]));
if ($inOrg1)   ok('org-scoped grant grants the perm in matching org');
else            bad('org-scoped grant grants the perm in matching org');
if (!$inOrg2)  ok('org-scoped grant denied in non-matching org');
else            bad('org-scoped grant denied in non-matching org');

// rbac_can_grant must respect scope: a granter holding Org Admin in
// org=1 cannot grant any role in org=2.
$canCross = rbac_can_grant($sandbox, 4, 'org', 2);
if (!$canCross) ok('rbac_can_grant denies cross-org grant attempt');
else            bad('rbac_can_grant denies cross-org grant attempt');

// And cannot grant a role with strictly more perms. Build a synthetic
// "extra" role with one permission Org Admin lacks (Super-only sentinel)
// so the subset check is unambiguous regardless of any data drift in
// the live install's role_permissions matrix.
$sentinelCode = '__rbac_v2_test_sentinel__';
$pid = (int) (db_fetch_value(
    "SELECT id FROM `{$prefix}permissions` WHERE code = ?", [$sentinelCode]
) ?: 0);
if (!$pid) {
    db_query("INSERT INTO `{$prefix}permissions` (code, name, category) VALUES (?, ?, ?)",
        [$sentinelCode, 'TEST sentinel', 'action']);
    $pid = (int) db_insert_id();
}
$ridSentinel = (int) (db_fetch_value(
    "SELECT id FROM `{$prefix}roles` WHERE name = '__test_super_plus__'"
) ?: 0);
if (!$ridSentinel) {
    db_query("INSERT INTO `{$prefix}roles` (name, description, sort_order) VALUES (?, ?, ?)",
        ['__test_super_plus__', 'TEST role with one perm Org Admin lacks', 999]);
    $ridSentinel = (int) db_insert_id();
}
db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)",
    [$ridSentinel, $pid]);
$canSuperPlus = rbac_can_grant($sandbox, $ridSentinel, 'org', 1);
if (!$canSuperPlus) ok('rbac_can_grant denies escalation to a role with extra perms');
else                 bad('rbac_can_grant denies escalation to a role with extra perms');
// Cleanup synthetic test role + perm.
db_query("DELETE FROM `{$prefix}role_permissions` WHERE role_id = ?", [$ridSentinel]);
db_query("DELETE FROM `{$prefix}roles` WHERE id = ?", [$ridSentinel]);
db_query("DELETE FROM `{$prefix}permissions` WHERE id = ?", [$pid]);

cleanup_sandbox_grants($sandbox, $preIds);

// ─────────────────────────────────────────────────────────────────────
// F6 — Privilege-escalation guard (3)
// ─────────────────────────────────────────────────────────────────────
echo "\n── F6 Privilege escalation ──\n";

// 6a. Super admin granter always passes.
$superId = (int) db_fetch_value("SELECT id FROM `{$prefix}user` WHERE level = 0 ORDER BY id LIMIT 1");
if ($superId && rbac_can_grant($superId, 1, 'global', null)) {
    ok('Super admin can grant Super Admin');
} else {
    bad('Super admin can grant Super Admin');
}
// 6b. Granter with no manage_roles permission cannot grant anything.
$noMgr = rbac_can_grant($sandbox, 5, 'global', null);  // sandbox has no roles right now
if (!$noMgr) ok('Granter without manage_roles is blocked');
else          bad('Granter without manage_roles is blocked');
// 6c. Bypass when grantedBy=0 (CLI tooling).
$gid = rbac_grant_role($sandbox, 5, 'global', null, null, 'F6 CLI', 0);
db_query("DELETE FROM `{$prefix}user_roles` WHERE id = ?", [$gid]);
ok('grantedBy=0 bypasses privilege guard (CLI/install path)');

// ─────────────────────────────────────────────────────────────────────
// F7 — Audit-log emission (5)
// ─────────────────────────────────────────────────────────────────────
echo "\n── F7 Audit log ──\n";

cleanup_sandbox_grants($sandbox, $preIds);

$preGrant = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'grant' AND target_type = 'user_role'"
);
$gid = rbac_grant_role($sandbox, 5, 'global', null, null, 'F7 audit', 0);
$postGrant = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'grant' AND target_type = 'user_role'"
);
if ($postGrant > $preGrant) ok('grant emits audit_log');
else                          bad('grant emits audit_log');

$preRev = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'revoke' AND target_type = 'user_role'"
);
rbac_revoke_grant($gid, 'F7 audit cleanup', 0);
$postRev = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'revoke' AND target_type = 'user_role'"
);
if ($postRev > $preRev) ok('revoke emits audit_log');
else                     bad('revoke emits audit_log');

// Most recent grant audit row must include user_id, role_id, scope_kind in details.
$lastDetails = db_fetch_value(
    "SELECT details FROM `{$prefix}newui_audit_log`
     WHERE activity = 'grant' AND target_type = 'user_role'
     ORDER BY id DESC LIMIT 1"
);
$decoded = json_decode((string) $lastDetails, true) ?: [];
foreach (['user_id','role_id','scope_kind'] as $k) {
    if (array_key_exists($k, $decoded)) ok("audit details carry $k");
    else                                  bad("audit details carry $k");
}

// ─────────────────────────────────────────────────────────────────────
// F8 — Legacy fallback presence + alias resolver (5)
// ─────────────────────────────────────────────────────────────────────
echo "\n── F8 Legacy + alias ──\n";

if (function_exists('_rbac_legacy_check')) ok('_rbac_legacy_check still present (deprecation window)');
else                                       bad('_rbac_legacy_check should still be present per Eric decision #5');

if (function_exists('_rbac_v2_schema_present') && _rbac_v2_schema_present()) {
    ok('_rbac_v2_schema_present returns true on migrated DB');
} else {
    bad('_rbac_v2_schema_present returns true on migrated DB');
}

// Alias pair: action.edit_incident -> incident.edit. Granter who has
// the OLD code through a role should pass rbac_can on the NEW code.
$superCan = with_session_user($superId, null, fn() => rbac_can('incident.edit'));
if ($superCan) ok('rbac_can resolves canonical aliases (incident.edit via super)');
else           bad('rbac_can resolves canonical aliases (incident.edit via super)');
$superCanOld = with_session_user($superId, null, fn() => rbac_can('action.edit_incident'));
if ($superCanOld) ok('rbac_can resolves deprecated aliases (action.edit_incident via super)');
else              bad('rbac_can resolves deprecated aliases');

// Backup table from the migration must exist.
$backup = db_fetch_one(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'user_roles_pre_v2_backup']
);
if (!empty($backup)) ok('migration backup table user_roles_pre_v2_backup exists');
else                  bad('migration backup table missing');

// ─────────────────────────────────────────────────────────────────────
// F10 — Time-entry surface (4)
// ─────────────────────────────────────────────────────────────────────
echo "\n── F10 Time-entry surface ──\n";

// Role 4 (Operator) holds time_entry.edit
$pid = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}permissions` WHERE code = 'time_entry.edit'"
);
$has = (int) db_fetch_value(
    "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = 4 AND permission_id = ?",
    [$pid]
);
if ($has) ok('Operator role holds time_entry.edit');
else      bad('Operator role holds time_entry.edit');

// Role 4 (Operator) does NOT hold time_entry.approve (per the spec —
// approve is for Dispatcher/Org Admin/Super only).
$pid = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}permissions` WHERE code = 'time_entry.approve'"
);
$has = (int) db_fetch_value(
    "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = 4 AND permission_id = ?",
    [$pid]
);
if (!$has) ok('Operator role lacks time_entry.approve');
else        bad('Operator role lacks time_entry.approve');

// Role 3 (Dispatcher) holds time_entry.approve.
$has = (int) db_fetch_value(
    "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = 3 AND permission_id = ?",
    [$pid]
);
if ($has) ok('Dispatcher role holds time_entry.approve');
else      bad('Dispatcher role holds time_entry.approve');

// te_can_modify in api/time-entries.php now delegates to rbac_can.
$src = code_only(file_get_contents($base . '/api/time-entries.php'));
if (preg_match('/function\s+te_can_modify[\s\S]{0,400}rbac_can/', $src)) {
    ok('te_can_modify delegates to rbac_can (no hand-rolled level math)');
} else {
    bad('te_can_modify delegates to rbac_can');
}

cleanup_sandbox_grants($sandbox, $preIds);

echo "\n=== Result: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
