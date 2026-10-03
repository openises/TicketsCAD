<?php
/**
 * GH#142 (Phase 155) - agency logo: schema, seeded settings and RBAC.
 *
 * Drives the REAL migration (sql/run_gh142_branding_logos.php) as a child
 * process, and asks the DATABASE what it will accept rather than reading DDL:
 *
 *   - the table exists with the columns the library writes;
 *   - `org_id` is NOT NULL DEFAULT 0, and the database REFUSES a duplicate
 *     (org_id, variant) and a duplicate asset_key (a UNIQUE key over a NULLable
 *     column constrains nothing for the NULL rows - Phase 129 - so this is
 *     asked, not read);
 *   - the eleven settings carry the specified defaults, and a re-run neither
 *     errors nor overwrites an administrator's changed value;
 *   - both permissions exist with tiers 2 and 1, Super Admin holds both, Org
 *     Admin holds only the org-scoped one, and no lower role holds either;
 *   - the migration is self-sufficient when the permission rows are missing
 *     (it sorts after run_00_rbac.php but must not depend on it), and it exits
 *     NON-ZERO when its own verification fails (a key dropped from the table).
 *
 * @requires-db
 * Usage: php tests/test_gh142_branding_schema.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/_gh142_cli.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding schema, settings and RBAC ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$migration = dirname(__DIR__) . '/sql/run_gh142_branding_logos.php';

// A fresh install has run the migration already; make sure this database has.
$r = gh142_run_php([$migration]);
t('migration runs and exits 0', $r['code'] === 0);
t('migration prints its own completion line', strpos($r['out'], 'installed') !== false);

// ── 1. The table ───────────────────────────────────────────────────────
$cols = [];
foreach (db_fetch_all(
    "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'branding_logos']) as $c) {
    $cols[$c['COLUMN_NAME']] = $c;
}
foreach (['id', 'org_id', 'variant', 'asset_key', 'mime', 'width', 'height', 'byte_size', 'sha256',
          'data_b64', 'uploaded_by', 'uploaded_by_name', 'created_at', 'updated_at'] as $col) {
    t("branding_logos.{$col} exists", isset($cols[$col]));
}
t('org_id is NOT NULL (0 = install-wide, never NULL)', ($cols['org_id']['IS_NULLABLE'] ?? '') === 'NO');
t("org_id defaults to 0", (string) ($cols['org_id']['COLUMN_DEFAULT'] ?? '') === '0');
t('data_b64 is MEDIUMTEXT (base64 text, not a BLOB)', ($cols['data_b64']['DATA_TYPE'] ?? '') === 'mediumtext');
$engine = db_fetch_value("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'branding_logos']);
t('table is InnoDB (the duplicate probe below relies on transactional rollback)', strtolower((string) $engine) === 'innodb');

// ── 2. The database refuses duplicates (asked, never read from DDL) ────
$before = (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos'));
$pdo = db();
$sha = str_repeat('0', 64);
$ins = "INSERT INTO " . db_table('branding_logos') . " (`org_id`, `variant`, `asset_key`, `mime`, `sha256`, `data_b64`) VALUES (?, ?, ?, 'image/png', ?, 'AA==')";
$k1 = bin2hex(random_bytes(16));
$k2 = bin2hex(random_bytes(16));
$refusedInstallDup = $refusedKeyDup = $acceptedFirst = false;
$pdo->beginTransaction();
try {
    db_query("DELETE FROM " . db_table('branding_logos') . " WHERE `org_id` = 0");   // rolled back below
    db_query($ins, [0, 'light', $k1, $sha]);
    $acceptedFirst = true;
    try { db_query($ins, [0, 'light', $k2, $sha]); } catch (Throwable $e) { $refusedInstallDup = true; }
    try { db_query($ins, [0, 'dark', $k1, $sha]); } catch (Throwable $e) { $refusedKeyDup = true; }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
t('a first install-wide (org 0, light) row is accepted', $acceptedFirst);
t('the database REFUSES a second install-wide light row (org_id 0 is a real key value, not NULL)', $refusedInstallDup);
t('the database REFUSES a duplicate asset_key', $refusedKeyDup);
t('the probe left the table exactly as it found it', (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos')) === $before);

// ── 3. Settings: defaults per the spec, hand-copied (not from the code) ─
$specDefaults = [
    'branding_login' => '1', 'branding_navbar' => 'product', 'branding_print' => '1',
    'branding_public_board' => '1', 'branding_org_logos' => '1', 'branding_dark_fallback' => 'plate',
    'branding_login_size' => 'medium', 'branding_print_size' => 'medium', 'branding_print_align' => 'center',
    'branding_print_banner' => 'replace', 'branding_logo_alt' => '',
];
$defs = branding_setting_definitions();
t('exactly eleven settings are defined', count($defs) === 11);
foreach ($specDefaults as $name => $default) {
    t("{$name}: the definition's default is '{$default}'", isset($defs[$name]) && $defs[$name]['default'] === $default);
    $has = db_fetch_value("SELECT 1 FROM " . db_table('settings') . " WHERE `name` = ?", [$name]);
    t("{$name}: a settings row exists", (bool) $has);
}

// A re-run is a clean no-op and does NOT overwrite a value an admin changed.
$original = db_fetch_value("SELECT `value` FROM " . db_table('settings') . " WHERE `name` = 'branding_login_size'");
db_query("UPDATE " . db_table('settings') . " SET `value` = 'large' WHERE `name` = 'branding_login_size'");
$r = gh142_run_php([$migration]);
$after = db_fetch_value("SELECT `value` FROM " . db_table('settings') . " WHERE `name` = 'branding_login_size'");
db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = 'branding_login_size'", [$original]);
t('a re-run exits 0', $r['code'] === 0);
t("a re-run does not overwrite an administrator's changed setting", $after === 'large');

// ── 4. Permissions, tiers and real role grants ─────────────────────────
$perm = function (string $code) {
    return db_fetch_one("SELECT `id`, `admin_only` FROM " . db_table('permissions') . " WHERE `code` = ?", [$code]);
};
$pBrand = $perm('action.manage_branding');
$pOrg   = $perm('action.manage_branding_org');
t('action.manage_branding exists', (bool) $pBrand);
t('action.manage_branding_org exists', (bool) $pOrg);
t('action.manage_branding is tier 2 (Super Admin only)', $pBrand && (int) $pBrand['admin_only'] === 2);
t('action.manage_branding_org is tier 1 (Org Admin and above)', $pOrg && (int) $pOrg['admin_only'] === 1);

$holds = function (string $role, string $code): bool {
    return (bool) db_fetch_value(
        "SELECT 1 FROM " . db_table('role_permissions') . " rp
           JOIN " . db_table('roles') . " r ON r.id = rp.role_id
           JOIN " . db_table('permissions') . " p ON p.id = rp.permission_id
          WHERE r.name = ? AND p.code = ? LIMIT 1", [$role, $code]);
};
t('Super Admin holds action.manage_branding', $holds('Super Admin', 'action.manage_branding'));
t('Super Admin holds action.manage_branding_org', $holds('Super Admin', 'action.manage_branding_org'));
t('Org Admin holds action.manage_branding_org', $holds('Org Admin', 'action.manage_branding_org'));
t('Org Admin does NOT hold action.manage_branding', !$holds('Org Admin', 'action.manage_branding'));
foreach (['Dispatcher', 'Operator', 'Read-Only', 'Field Unit'] as $role) {
    t("{$role} holds neither branding permission",
        !$holds($role, 'action.manage_branding') && !$holds($role, 'action.manage_branding_org'));
}

// ── 5. Order independence: sorts between run_00_rbac and run_rbac_v2 ───
t('the migration sorts after run_00_rbac.php', strcmp('run_00_rbac.php', 'run_gh142_branding_logos.php') < 0);
t('the migration sorts before run_rbac_v2.php', strcmp('run_gh142_branding_logos.php', 'run_rbac_v2.php') < 0);
t('the migration sorts before run_zzz_admin_only_reconcile.php', strcmp('run_gh142_branding_logos.php', 'run_zzz_admin_only_reconcile.php') < 0);

// Self-sufficient when the permission rows are missing (an install whose
// run_00_rbac.php predates this change): delete both rows and their grants,
// run ONLY this migration, and it must recreate them with the right tiers.
// The delete-and-recreate below also loses permissions.deprecated_alias_of (run_rbac_v2.php's A8
// link between an old code and its canonical row): the recreated rows start with it NULL, which
// leaves the canonical rows looking like duplicates of them for every later test in the suite
// (tests/test_rbac_alias_dedupe.php failed on exactly this). Remember the links, put them back.
$savedAliases = [];
try {
    foreach (db_fetch_all("SELECT `code`, `deprecated_alias_of` FROM " . db_table('permissions') . "
                            WHERE `code` IN ('action.manage_branding', 'action.manage_branding_org')") as $__a) {
        $savedAliases[(string) $__a['code']] = $__a['deprecated_alias_of'];
    }
} catch (Throwable $e) { /* column absent on a pre-RBAC-v2 database: nothing to restore */ }
$savedGrants = db_fetch_all(
    "SELECT rp.role_id, rp.permission_id FROM " . db_table('role_permissions') . " rp
       JOIN " . db_table('permissions') . " p ON p.id = rp.permission_id
      WHERE p.code IN ('action.manage_branding', 'action.manage_branding_org')");
$restored = false;
try {
    db_query("DELETE rp FROM " . db_table('role_permissions') . " rp JOIN " . db_table('permissions') . " p ON p.id = rp.permission_id
               WHERE p.code IN ('action.manage_branding', 'action.manage_branding_org')");
    db_query("DELETE FROM " . db_table('permissions') . " WHERE `code` IN ('action.manage_branding', 'action.manage_branding_org')");
    $gone = !$perm('action.manage_branding') && !$perm('action.manage_branding_org');
    $r = gh142_run_php([$migration]);
    $pBrand2 = $perm('action.manage_branding');
    $pOrg2   = $perm('action.manage_branding_org');
    t('fixture: both permission rows were removed first', $gone);
    t('with the rows missing, the migration alone recreates both and exits 0',
        $r['code'] === 0 && (bool) $pBrand2 && (bool) $pOrg2);
    t('...with tier 2 and tier 1 applied by the migration itself',
        $pBrand2 && (int) $pBrand2['admin_only'] === 2 && $pOrg2 && (int) $pOrg2['admin_only'] === 1);
    t('...and re-grants Super Admin both and Org Admin only the org-scoped one',
        $holds('Super Admin', 'action.manage_branding') && $holds('Super Admin', 'action.manage_branding_org')
        && $holds('Org Admin', 'action.manage_branding_org') && !$holds('Org Admin', 'action.manage_branding'));
    $restored = true;
} finally {
    if (!$restored) {
        // make sure the install is left whole even if an assertion above threw
        gh142_run_php([$migration]);
    }
}

foreach ($savedAliases as $__code => $__alias) {
    if ($__alias !== null && $__alias !== '') {
        db_query("UPDATE " . db_table('permissions') . " SET `deprecated_alias_of` = ? WHERE `code` = ?", [$__alias, $__code]);
    }
}

// ── 5b. Fresh-install ordering: the columns it must NOT assume ─────────
// FOUND by running the real installer (tools/install_fresh.php) into an empty
// database: this migration sorts BEFORE run_rbac_v2.php, so on a genuinely fresh
// install roles.is_super and permissions.deprecated_alias_of do not exist yet. The
// first version selected r.is_super, failed with "Unknown column", and exited 1 on
// the first pass (red CI); only the second pass recovered. Simulate that state by
// renaming both columns away for the duration of one migration run.
$colDefs = [];
$renamed = [];
foreach ([['roles', 'is_super'], ['permissions', 'deprecated_alias_of']] as [$tbl, $col]) {
    $ddl = (string) (db_fetch_one("SHOW CREATE TABLE " . db_table($tbl))['Create Table'] ?? '');
    if (preg_match('/^\s*`' . preg_quote($col, '/') . '`\s+(.+?),?\s*$/m', $ddl, $mm)) {
        $colDefs[$tbl] = [$col, rtrim($mm[1], ',')];
    }
}
t('fixture: both columns were found to be renamed away', count($colDefs) === 2);
try {
    foreach ($colDefs as $tbl => [$col, $def]) {
        db_query("ALTER TABLE " . db_table($tbl) . " CHANGE `{$col}` `{$col}_zz142` {$def}");
        $renamed[$tbl] = true;
    }
    $r = gh142_run_php([$migration]);
    t('with roles.is_super and permissions.deprecated_alias_of ABSENT (fresh-install ordering), the migration exits 0', $r['code'] === 0);
    t('...and prints no failure', strpos($r['out'], '[FAIL]') === false && strpos($r['out'], 'FAILED') === false);
    t('...and Super Admin / Org Admin still hold exactly the right permissions',
        $holds('Super Admin', 'action.manage_branding') && $holds('Org Admin', 'action.manage_branding_org') && !$holds('Org Admin', 'action.manage_branding'));
} finally {
    foreach ($renamed as $tbl => $_) {
        [$col, $def] = $colDefs[$tbl];
        db_query("ALTER TABLE " . db_table($tbl) . " CHANGE `{$col}_zz142` `{$col}` {$def}");
    }
}
t('both columns were restored', (bool) db_fetch_value("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'is_super'", [$prefix . 'roles'])
    && (bool) db_fetch_value("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deprecated_alias_of'", [$prefix . 'permissions']));

// ── 6. The migration exits NON-ZERO when its own verification fails ────
db_query("ALTER TABLE " . db_table('branding_logos') . " DROP INDEX `uk_branding_asset_key`");
try {
    $r = gh142_run_php([$migration]);
    t('with the asset_key unique key missing, the migration exits non-zero', $r['code'] !== 0);
    t('...and names what failed', strpos($r['out'], 'asset_key unique key does not refuse a duplicate') !== false);
} finally {
    // Re-adding it directly (not via the migration, which only CREATEs when the
    // table is absent) proves the repair path an admin would actually take.
    try {
        db_query("ALTER TABLE " . db_table('branding_logos') . " ADD UNIQUE KEY `uk_branding_asset_key` (`asset_key`)");
    } catch (Throwable $e) { /* already present */ }
}
$r = gh142_run_php([$migration]);
t('after the key is restored the migration exits 0 again', $r['code'] === 0);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
