<?php
/**
 * RBAC exclusion-list privilege-leak audit gate (2026-08-21).
 *
 * Companion to tests/test_rbac_canonical_alias_leak.php, which catches
 * this same bug shape only for the specific codes someone remembered to
 * hand-add to its own $excludedByRole array. This file drives the
 * GENERIC tool (tools/rbac_exclusion_leak_audit.php) instead — it parses
 * the exclusion lists straight out of sql/rbac.sql and
 * sql/run_00_rbac.php, so a NEW exclusion added to either file is
 * checked automatically with no companion test edit required.
 *
 * These tests drive the REAL tool against fixture files (--rbac-sql=,
 * --run00=, --scan-dir=) rather than re-implementing its parsing here —
 * same convention as tests/test_rbac_permission_audit.php's use of
 * tools/rbac_permission_audit.php's own --path=. The DB-state checks
 * (Part 1 of the tool) always run against the REAL live
 * permissions/role_permissions tables regardless of which fixture files
 * are passed for the two source files, exactly like the tool's own
 * --path= flag for tools/rbac_permission_audit.php never repoints the DB
 * connection.
 *
 * Usage: php tests/test_rbac_exclusion_leak_audit.php
 *
 * @requires-db
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');
$tool = $base . '/tools/rbac_exclusion_leak_audit.php';

require_once $base . '/config.php';

echo "=== RBAC exclusion-list privilege-leak audit gate ===\n\n";
$pass = 0;
$fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void {
    global $fail; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

/** Run the audit with the given CLI flags; return [exitCode, output]. */
function rela_run(string $tool, array $flags = []): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
    foreach ($flags as $f) { $cmd .= ' ' . escapeshellarg($f); }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Fixtures live outside the repo ───────────────────────────────────
$tmp = sys_get_temp_dir() . '/rela_fixtures_' . getmypid();
@mkdir($tmp . '/sql', 0777, true);
register_shutdown_function(static function () use ($tmp) {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

// A baseline exclusion list large enough to clear the tool's own
// sanity floor (it refuses to trust silence below 5 parsed codes for
// sql/rbac.sql, guarding against the parser regex silently breaking).
$rbacSqlFixture = $tmp . '/rbac.sql';
$run00MatchingFixture = $tmp . '/run00_matching.php';
$run00DriftedFixture  = $tmp . '/run00_drifted.php';

file_put_contents($rbacSqlFixture, <<<'SQL'
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 2, `id` FROM `permissions`
    WHERE `code` NOT IN ('action.manage_config', 'action.manage_roles', 'screen.facility_portal',
                          'action.manage_audit_retention', 'action.bulk_delete_members');
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 3, `id` FROM `permissions`
    WHERE `code` NOT IN ('action.manage_config', 'console.design', 'action.intercom_unlock');
SQL);

file_put_contents($run00MatchingFixture, <<<'PHP'
<?php
db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
          SELECT 2, `id` FROM `{$prefix}permissions`
          WHERE `code` NOT IN ('action.manage_config', 'action.manage_roles', 'screen.facility_portal',
                                'action.manage_audit_retention', 'action.bulk_delete_members')");
PHP);

file_put_contents($run00DriftedFixture, <<<'PHP'
<?php
// Deliberately missing 'action.manage_roles' compared to rbac.sql -- must
// be reported as cross-file drift.
db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
          SELECT 2, `id` FROM `{$prefix}permissions`
          WHERE `code` NOT IN ('action.manage_config', 'screen.facility_portal',
                                'action.manage_audit_retention', 'action.bulk_delete_members')");
PHP);

// ═══════════════════════════════════════════════════════════════════════
// 1. Clean fixtures (matching lists, empty scan dir, no live-DB leak for
//    these codes) — the tool must stay silent.
// ═══════════════════════════════════════════════════════════════════════
[$code1, $out1] = rela_run($tool, [
    '--rbac-sql=' . $rbacSqlFixture,
    '--run00=' . $run00MatchingFixture,
    '--scan-dir=' . $tmp . '/sql',
]);
is_true($code1 === 0, 'clean fixtures (matching exclusion lists, no rogue grants) produce exit 0', $out1);

// ═══════════════════════════════════════════════════════════════════════
// 2. Cross-file drift: rbac.sql excludes 'action.manage_roles' for role 2,
//    run00 fixture does not — the tool must flag it without anyone having
//    hand-added 'action.manage_roles' to a check-list anywhere in THIS
//    test file.
// ═══════════════════════════════════════════════════════════════════════
[$code2, $out2] = rela_run($tool, [
    '--rbac-sql=' . $rbacSqlFixture,
    '--run00=' . $run00DriftedFixture,
    '--scan-dir=' . $tmp . '/sql',
]);
is_true($code2 === 1, 'a drifted exclusion list between the two files produces exit 1', $out2);
is_true(
    strpos($out2, "'action.manage_roles'") !== false && strpos($out2, 'drifted out of sync') !== false,
    'the drift finding names the specific code that differs between the two files',
    $out2
);

// ═══════════════════════════════════════════════════════════════════════
// 3. Rogue-grant scan: a run_*.php file containing a literal
//    "SELECT 3, id FROM permissions WHERE code = 'console.design'" grants
//    an excluded code to an excluded role in one self-contained SQL
//    statement — the tool must catch it with zero hand-maintenance.
// ═══════════════════════════════════════════════════════════════════════
file_put_contents($tmp . '/sql/run_rogue_test.php', <<<'PHP'
<?php
db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
          SELECT 3, `id` FROM `{$prefix}permissions` WHERE `code` = 'console.design'");
PHP);
[$code3, $out3] = rela_run($tool, [
    '--rbac-sql=' . $rbacSqlFixture,
    '--run00=' . $run00MatchingFixture,
    '--scan-dir=' . $tmp . '/sql',
]);
is_true($code3 === 1, 'a rogue migration literally granting an excluded code produces exit 1', $out3);
is_true(
    strpos($out3, 'run_rogue_test.php') !== false && strpos($out3, "'console.design'") !== false,
    'the rogue-grant finding names the offending file and code',
    $out3
);
@unlink($tmp . '/sql/run_rogue_test.php');

// A migration that grants a NON-excluded code (or excludes the same one
// via NOT IN, not a positive grant) must NOT be flagged — proves the scan
// isn't just "any SELECT ... FROM permissions" pattern-matching blindly.
file_put_contents($tmp . '/sql/run_benign_test.php', <<<'PHP'
<?php
db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
          SELECT 3, `id` FROM `{$prefix}permissions` WHERE `code` = 'action.add_note'");
PHP);
[$code3b, $out3b] = rela_run($tool, [
    '--rbac-sql=' . $rbacSqlFixture,
    '--run00=' . $run00MatchingFixture,
    '--scan-dir=' . $tmp . '/sql',
]);
is_true($code3b === 0, 'a migration granting a NON-excluded code is not flagged', $out3b);
@unlink($tmp . '/sql/run_benign_test.php');

// ═══════════════════════════════════════════════════════════════════════
// 4. DB-state leak (mechanisms 1 DIRECT + 2 ALIAS): grant a throwaway
//    permission to role 2, name it in the fixture rbac.sql exclusion
//    list, and confirm the tool catches both the direct grant and the
//    grant of its canonical alias — driven entirely off the parsed list,
//    not a hand-added code in this test.
// ═══════════════════════════════════════════════════════════════════════
$directId = null; $oldId = null; $canonId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}permissions` (code, name, category, description)
         VALUES ('action.zz_rela_direct_test', 'ZZ RELA direct test', 'action', 'throwaway fixture')"
    );
    $directId = (int) db_insert_id();
    db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (2, ?)", [$directId]);

    $hasAlias = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deprecated_alias_of'",
        [$prefix . 'permissions']
    );
    if ($hasAlias) {
        db_query(
            "INSERT INTO `{$prefix}permissions` (code, name, category, description)
             VALUES ('action.zz_rela_alias_old', 'ZZ RELA alias old', 'action', 'throwaway fixture')"
        );
        $oldId = (int) db_insert_id();
        db_query(
            "INSERT INTO `{$prefix}permissions` (code, name, category, description)
             VALUES ('zz_rela_alias.manage', 'ZZ RELA alias canonical', 'action', 'throwaway fixture')"
        );
        $canonId = (int) db_insert_id();
        db_query("UPDATE `{$prefix}permissions` SET deprecated_alias_of = 'zz_rela_alias.manage' WHERE id = ?", [$oldId]);
        db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (2, ?)", [$canonId]);
    }

    $dbLeakFixture = $tmp . '/rbac_dbleak.sql';
    $codes = "'action.manage_config', 'action.zz_rela_direct_test'";
    if ($hasAlias) { $codes .= ", 'action.zz_rela_alias_old'"; }
    $codes .= ", 'action.manage_roles', 'screen.facility_portal'"; // pad past the sanity floor
    file_put_contents($dbLeakFixture, <<<SQL
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 2, `id` FROM `permissions`
    WHERE `code` NOT IN ({$codes});
SQL);

    [$code4, $out4] = rela_run($tool, [
        '--rbac-sql=' . $dbLeakFixture,
        '--run00=' . $run00MatchingFixture, // deliberately mismatched -- drift noise is fine, checked separately below
        '--scan-dir=' . $tmp . '/sql',
    ]);
    is_true($code4 === 1, 'a live direct-grant leak of a fixture-excluded code produces exit 1', $out4);
    is_true(
        strpos($out4, "directly holds excluded code 'action.zz_rela_direct_test'") !== false,
        'the direct-leak finding names the throwaway code, driven purely off the parsed exclusion list',
        $out4
    );
    if ($hasAlias) {
        is_true(
            strpos($out4, "action.zz_rela_alias_old") !== false && strpos($out4, 'canonical alias') !== false,
            'the alias-leak finding is reported for the throwaway old-code/canonical pair',
            $out4
        );
    } else {
        ok('alias mechanism not applicable on this schema (no deprecated_alias_of column) -- skipped');
    }
} catch (Throwable $e) {
    bad('DB-state leak fixture setup/exec without error', $e->getMessage());
} finally {
    try { if (!empty($directId)) db_query("DELETE FROM `{$prefix}role_permissions` WHERE permission_id = ?", [$directId]); } catch (Throwable $e) {}
    try { if (!empty($directId)) db_query("DELETE FROM `{$prefix}permissions` WHERE id = ?", [$directId]); } catch (Throwable $e) {}
    try { if (!empty($canonId)) db_query("DELETE FROM `{$prefix}role_permissions` WHERE permission_id = ?", [$canonId]); } catch (Throwable $e) {}
    try { if (!empty($canonId)) db_query("DELETE FROM `{$prefix}permissions` WHERE id = ?", [$canonId]); } catch (Throwable $e) {}
    try { if (!empty($oldId)) db_query("DELETE FROM `{$prefix}role_permissions` WHERE permission_id = ?", [$oldId]); } catch (Throwable $e) {}
    try { if (!empty($oldId)) db_query("DELETE FROM `{$prefix}permissions` WHERE id = ?", [$oldId]); } catch (Throwable $e) {}
}

// ═══════════════════════════════════════════════════════════════════════
// 5. Sanity floor: a fixture with almost nothing parseable must FAIL
//    loudly, not silently report "clean" — guards against the parser
//    regex breaking on a future reformat and this whole gate going quiet.
// ═══════════════════════════════════════════════════════════════════════
$emptyFixture = $tmp . '/rbac_empty.sql';
file_put_contents($emptyFixture, "-- nothing here that looks like an exclusion list\nSELECT 1;\n");
[$code5, $out5] = rela_run($tool, [
    '--rbac-sql=' . $emptyFixture,
    '--run00=' . $run00MatchingFixture,
    '--scan-dir=' . $tmp . '/sql',
]);
is_true($code5 === 1, 'a fixture with no parseable exclusion lists fails loudly (never a silent false-clean)', $out5);
is_true(strpos($out5, 'suspiciously few') !== false, 'the sanity-floor finding explains why it refused to trust silence', $out5);

// ═══════════════════════════════════════════════════════════════════════
// 6. The real app tree: must be clean today (every finding this tool has
//    ever produced against the real tree was fixed before landing, not
//    baselined).
// ═══════════════════════════════════════════════════════════════════════
[$rcode, $rout] = rela_run($tool);
$tail = implode("\n", array_slice(explode("\n", $rout), -30));
is_true($rcode === 0, 'no NEW RBAC exclusion-list leak in the real app tree', $tail);

$baselineFile = $base . '/tools/rbac_exclusion_leak_audit_baseline.txt';
is_true(is_file($baselineFile), 'the baseline file exists');
if (is_file($baselineFile)) {
    $entries = [];
    foreach (file($baselineFile) as $l) {
        $l = trim($l);
        if ($l !== '' && $l[0] !== '#') { $entries[] = $l; }
    }
    is_true($entries === [], 'the baseline is empty (every finding was fixed, not grandfathered)',
        count($entries) . ' entries present');
}

// The tool must refuse to run under a web SAPI, same convention as every
// sibling audit tool.
$src = (string) file_get_contents($tool);
is_true(
    strpos($src, "if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }") !== false,
    "$tool carries the canonical CLI-only guard"
);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
