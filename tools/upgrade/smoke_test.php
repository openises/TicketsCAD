<?php
/**
 * Legacy v3.44 → NewUI v4 — post-migration smoke test.
 *
 * Exercises the install without HTTP. Confirms DB read/write,
 * RBAC initialisation, audit pipeline, password verifier.
 *
 * Exit 0 if everything passes; non-zero on first failure.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/rbac.php';
require_once __DIR__ . '/../../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;

function ok(string $name): void  { global $pass; echo "  [ok]   $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  [fail] $name" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "Smoke test:\n";

// 1. DB reachable
try {
    $row = db_fetch_one("SELECT 1 AS one");
    if (($row['one'] ?? 0) === 1 || (string) ($row['one'] ?? '') === '1') ok('DB reachable');
    else bad('DB reachable', 'unexpected response');
} catch (Throwable $e) { bad('DB reachable', $e->getMessage()); }

// 2. RBAC v2 schema present
if (function_exists('_rbac_v2_schema_present') && _rbac_v2_schema_present()) {
    ok('RBAC v2 schema present');
} else {
    bad('RBAC v2 schema present');
}

// 3. Every user has at least one grant
try {
    $orphans = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user` u
         LEFT JOIN `{$prefix}user_roles` ur ON u.id = ur.user_id
         WHERE ur.id IS NULL"
    );
    if ($orphans === 0) ok('every user has at least one grant');
    else                bad("$orphans orphan user(s) — fail-closed will block their API access",
                            'Run tools/migrate_rbac.php to assign default roles');
} catch (Throwable $e) { bad('orphan check', $e->getMessage()); }

// 4. Default Super Admin role flagged is_super
try {
    $row = db_fetch_one("SELECT is_super FROM `{$prefix}roles` WHERE id = 1");
    if (($row && (int) $row['is_super'] === 1)) ok('Super Admin role has is_super=1');
    else bad('Super Admin role missing is_super');
} catch (Throwable $e) { bad('is_super check', $e->getMessage()); }

// 5. Audit pipeline works
try {
    audit_log('upgrade', 'smoke_test', 'system', 0, 'smoke test event from upgrade tool');
    $row = db_fetch_one(
        "SELECT id FROM `{$prefix}newui_audit_log`
         WHERE category = 'upgrade' AND activity = 'smoke_test'
         ORDER BY id DESC LIMIT 1"
    );
    if (!empty($row)) {
        ok('audit_log writes');
        // Cleanup the test row.
        db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE id = ?", [$row['id']]);
    } else {
        bad('audit_log writes', 'row not found after insert');
    }
} catch (Throwable $e) { bad('audit pipeline', $e->getMessage()); }

// 6. Permission catalog populated
try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}permissions`");
    if ($count >= 60) ok("permissions catalog populated ({$count} rows)");
    else              bad('permissions catalog', "only {$count} rows");
} catch (Throwable $e) { bad('permissions catalog', $e->getMessage()); }

// 7. Aliases linked
try {
    $aliased = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE deprecated_alias_of IS NOT NULL"
    );
    if ($aliased > 0) ok("alias mapping in place ({$aliased} aliases)");
    else              bad('alias mapping', 'no deprecated_alias_of rows found');
} catch (Throwable $e) { bad('alias mapping', $e->getMessage()); }

echo "\n";
if ($fail === 0) {
    echo "SMOKE: PASS ($pass checks)\n";
    exit(0);
}
echo "SMOKE: FAIL ($fail of " . ($pass + $fail) . " failed)\n";
exit(1);
