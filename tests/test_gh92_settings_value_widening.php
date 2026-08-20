<?php
/**
 * GH #92 (Ron Jones, 2026-08-19) regression tests — settings.value column
 * width + the CJIS login-notice truncation it caused.
 *
 * Bug: tools/install_fresh.php widened settings.value from varchar(512) to
 * TEXT as a step that ran AFTER the sql/run_*.php migration sweep.
 * sql/run_99i_cjis_county.php seeds an 812-character CJIS login-notice
 * default into settings.value (via INSERT IGNORE) as part of that sweep —
 * so on a true fresh install the notice was written while the column was
 * still narrow: silently truncated to exactly 512 characters (non-strict
 * SQL mode), or the whole seeder script aborted via its own exit(1) error
 * handler (strict mode on some servers), which also skipped the unrelated
 * member.county column it adds further down the same try block. Because
 * both settings.value INSERTs in the seeder use INSERT IGNORE, a normal
 * re-run can never repair an already-truncated row — it reads as "already
 * present" and is silently skipped forever.
 *
 * Fix, in two parts:
 *   1. tools/install_fresh.php now widens settings.value to TEXT
 *      immediately after base_schema.sql import, BEFORE any seed step
 *      (the foundational .sql import loop, or the sql/run_*.php sweep)
 *      can write into it. sql/base_schema.sql itself now also ships
 *      settings.value as TEXT directly (defense in depth for any path
 *      that imports the schema file standalone).
 *   2. sql/run_gh92_settings_value_repair.php repairs an EXISTING install
 *      whose cjis_login_notice_text already carries the exact truncation
 *      signature (512 chars, an exact prefix of the known default) —
 *      narrowly targeted so a deliberately customized notice (including
 *      one that happens to also be 512 chars) is never touched.
 *
 * This file proves three things, per this project's established
 * "reproduce via real writer" discipline (a hand-seeded ideal-state mock
 * would have hidden this exact bug class before — see CLAUDE.md):
 *
 *   (a) STRUCTURAL — tools/install_fresh.php really does register the
 *       widening step before the seed steps now (and base_schema.sql
 *       really does ship the column as TEXT), by parsing the actual
 *       shipped files.
 *   (b) REAL END-TO-END, BUGGY ORDER — the REAL, unmodified
 *       sql/run_99i_cjis_county.php seeder, driven against a schema built
 *       from the REAL narrow (pre-widening) settings table shape,
 *       reproduces the exact reported truncation byte-for-byte.
 *   (c) REAL END-TO-END, FIXED ORDER — the same REAL seeder, driven
 *       against a schema widened FIRST (mirroring the fixed step order),
 *       stores the complete 812-character notice.
 *   (d) REAL REPAIR MIGRATION — the REAL, unmodified
 *       sql/run_gh92_settings_value_repair.php script detects and fixes
 *       the exact truncation signature, is idempotent, and leaves a
 *       deliberately-customized (non-signature-matching) value alone —
 *       including one that happens to also be exactly 512 characters.
 *
 * (b)/(c)/(d) run the REAL script files (dynamically read from disk at
 * test time, not a hand-copied snapshot) via a real PHP subprocess. This
 * environment has no database-creation privilege (confirmed during
 * development — only the app's own database is grantable), so isolation
 * is achieved with a unique TABLE PREFIX within that same database
 * (gh92_selftest_<random>_settings / _member) rather than a separate
 * database — the scripts under test already parameterize every table name
 * via $GLOBALS['db_prefix'], which is exactly what makes this possible.
 * The scratch tables are dropped in a finally block regardless of outcome.
 *
 * Usage: php tests/test_gh92_settings_value_widening.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pass = 0;
$fail = 0;

function g92(bool $cond, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] {$label}\n";
        $pass++;
    } else {
        echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $fail++;
    }
}

$base = realpath(__DIR__ . '/..');

// ─────────────────────────────────────────────────────────────────────
// (a) STRUCTURAL: install_fresh.php's step order + base_schema.sql shape
// ─────────────────────────────────────────────────────────────────────
echo "=== (a) install_fresh.php step ordering + base_schema.sql shape ===\n";

$installFreshSrc = file_get_contents($base . '/tools/install_fresh.php');
g92($installFreshSrc !== false, 'tools/install_fresh.php is readable');

$posWiden = strpos((string) $installFreshSrc, "step('settings.value to TEXT");
$posSweep = strpos((string) $installFreshSrc, "step('all sql/run_*.php migrations applied'");
$posFoundational = strpos((string) $installFreshSrc, 'Foundational SQL files');
$posBaseSchema = strpos((string) $installFreshSrc, "step('base schema present");

g92($posWiden !== false, 'the settings.value widening step exists');
g92($posSweep !== false, 'the sql/run_*.php migration sweep step exists');
g92($posFoundational !== false, 'the foundational .sql import loop exists');
g92($posBaseSchema !== false, 'the base-schema-present step exists');

if ($posWiden !== false && $posBaseSchema !== false) {
    g92($posBaseSchema < $posWiden,
        'the widening step runs AFTER base_schema.sql import (needs the table to exist)',
        "base-schema step at offset {$posBaseSchema}, widen step at offset {$posWiden}");
}
if ($posWiden !== false && $posFoundational !== false) {
    g92($posWiden < $posFoundational,
        'GH #92 FIX: the widening step runs BEFORE the foundational .sql import loop',
        "widen step at offset {$posWiden}, foundational loop at offset {$posFoundational}");
}
if ($posWiden !== false && $posSweep !== false) {
    g92($posWiden < $posSweep,
        'GH #92 FIX: the widening step runs BEFORE the sql/run_*.php migration sweep '
            . '(this is the actual ordering that caused the bug)',
        "widen step at offset {$posWiden}, migration sweep at offset {$posSweep}");
}

// Only ONE registration of the widening step should exist (the old
// duplicate-in-place-after-sweep copy must be gone, not just supplemented).
$widenOccurrences = substr_count((string) $installFreshSrc, "step('settings.value to TEXT");
g92($widenOccurrences === 1,
    'the widening step is registered exactly once (no leftover duplicate after the sweep)',
    "found {$widenOccurrences} occurrence(s)");

$baseSchemaSrc = (string) file_get_contents($base . '/sql/base_schema.sql');
g92(
    (bool) preg_match('/CREATE TABLE IF NOT EXISTS `settings`.{0,1500}?`value`\s+text\b/is', $baseSchemaSrc),
    'sql/base_schema.sql defines settings.value as TEXT directly'
);
g92(
    !preg_match('/CREATE TABLE IF NOT EXISTS `settings`.{0,1500}?`value`\s+varchar\(512\)/is', $baseSchemaSrc),
    'sql/base_schema.sql no longer defines settings.value as varchar(512)'
);

// ─────────────────────────────────────────────────────────────────────
// Shared harness for (b)/(c)/(d): an isolated scratch schema, driven by
// the REAL sql/run_99i_cjis_county.php and sql/run_gh92_settings_value_repair.php
// files (read fresh from disk, not a frozen copy) via a real subprocess.
// ─────────────────────────────────────────────────────────────────────
$prefix = 'gh92_selftest_' . substr(bin2hex(random_bytes(4)), 0, 8) . '_';
$scratchSettings = "`{$prefix}settings`";
$scratchMember = "`{$prefix}member`";

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gh92_harness_' . substr(bin2hex(random_bytes(4)), 0, 8);
$tmpSqlDir = $tmpDir . DIRECTORY_SEPARATOR . 'sql';
$tmpIncDir = $tmpDir . DIRECTORY_SEPARATOR . 'inc';
@mkdir($tmpSqlDir, 0777, true);
@mkdir($tmpIncDir, 0777, true);

global $db_host, $db_user, $db_pass, $db_name;
$harnessConfig = "<?php\n"
    . '$db_host = ' . var_export($db_host, true) . ";\n"
    . '$db_user = ' . var_export($db_user, true) . ";\n"
    . '$db_pass = ' . var_export($db_pass, true) . ";\n"
    . '$db_name = ' . var_export($db_name, true) . ";\n"
    . '$db_prefix = ' . var_export($prefix, true) . ";\n";
file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'config.php', $harnessConfig);

// The REAL seeder/repair scripts each do their OWN separate
// `require_once __DIR__ . '/../inc/db.php'` (in addition to requiring
// config.php), resolving to <harness>/inc/db.php — so the harness needs a
// real file there too. This is a one-line shim to the REAL inc/db.php by
// absolute path (require_once dedups by resolved realpath, so this is not
// a second, divergent copy of the connection logic — it's the same file).
file_put_contents(
    $tmpIncDir . DIRECTORY_SEPARATOR . 'db.php',
    "<?php\nrequire_once " . var_export($base . '/inc/db.php', true) . ";\n"
);

$realSeederPath = $base . '/sql/run_99i_cjis_county.php';
$realRepairPath = $base . '/sql/run_gh92_settings_value_repair.php';
$seederSrc = file_get_contents($realSeederPath);
$repairSrc = file_get_contents($realRepairPath);
g92($seederSrc !== false, 'sql/run_99i_cjis_county.php is readable (the file this bug is about)');
g92($repairSrc !== false, 'sql/run_gh92_settings_value_repair.php is readable (the repair migration)');
file_put_contents($tmpSqlDir . DIRECTORY_SEPARATOR . 'run_99i_cjis_county.php', (string) $seederSrc);
file_put_contents($tmpSqlDir . DIRECTORY_SEPARATOR . 'run_gh92_settings_value_repair.php', (string) $repairSrc);

$harnessSeeder = $tmpSqlDir . DIRECTORY_SEPARATOR . 'run_99i_cjis_county.php';
$harnessRepair = $tmpSqlDir . DIRECTORY_SEPARATOR . 'run_gh92_settings_value_repair.php';

/** Run a PHP CLI script and return [stdout+stderr combined, exit code]. */
function g92_run(string $phpBin, string $scriptPath): array
{
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1; echo EXITCODE:$?');
    $out = (string) $out;
    $exit = 0;
    if (preg_match('/EXITCODE:(\d+)\s*$/', $out, $m)) {
        $exit = (int) $m[1];
        $out = preg_replace('/EXITCODE:\d+\s*$/', '', $out);
    }
    return [trim((string) $out), $exit];
}

// The exact 812-character notice sql/run_99i_cjis_county.php seeds — used
// here only to compute the KNOWN truncation prefix for assertions, not to
// duplicate the seeder's own logic.
$defaultNotice =
    "WARNING — U.S. GOVERNMENT SYSTEM\n\n"
    . "This is a restricted information system. Unauthorized or improper use of this "
    . "system may result in disciplinary action, as well as civil and criminal penalties.\n\n"
    . "By using this system you understand and consent to the following:\n"
    . "- You have no reasonable expectation of privacy regarding any communication "
    . "transmitted through or data stored on this system.\n"
    . "- At any time, the government may monitor, intercept, search, and seize any "
    . "communication or data transiting or stored on this system.\n"
    . "- Any communications or data transiting or stored on this system may be "
    . "disclosed or used for any U.S. government-authorized purpose.\n\n"
    . "Access to CJIS information is restricted to authorized personnel. By logging in, "
    . "you certify that you are an authorized user and acknowledge these terms.";
$expectedTruncatedPrefix = mb_substr($defaultNotice, 0, 512);

function g92_reset_scratch_tables(string $settingsTbl, string $memberTbl, bool $narrow): void
{
    db()->exec("DROP TABLE IF EXISTS {$settingsTbl}");
    db()->exec("DROP TABLE IF EXISTS {$memberTbl}");
    $valueCol = $narrow ? 'varchar(512)' : 'text';
    db()->exec("CREATE TABLE IF NOT EXISTS {$settingsTbl} (
        `id` bigint(8) NOT NULL AUTO_INCREMENT,
        `name` tinytext DEFAULT NULL,
        `value` {$valueCol} DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ID` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS {$memberTbl} (
        `id` bigint(8) NOT NULL AUTO_INCREMENT,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try {
    $phpBin = PHP_BINARY ?: 'php';

    // ─────────────────────────────────────────────────────────────────
    // (b) REAL END-TO-END, BUGGY ORDER: narrow column, then seed — must
    // reproduce the exact reported truncation via the REAL seeder script.
    // ─────────────────────────────────────────────────────────────────
    echo "\n=== (b) real seeder against a narrow (pre-widening) column reproduces the bug ===\n";
    g92_reset_scratch_tables($scratchSettings, $scratchMember, true);
    $colBefore = db_fetch_one(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'value'",
        [$prefix . 'settings']
    );
    g92(strtolower((string) ($colBefore['DATA_TYPE'] ?? '')) === 'varchar',
        'scratch settings.value really is varchar (fixture is not a strawman)');

    [$seedOut1] = g92_run($phpBin, $harnessSeeder);
    $truncatedVal = db_fetch_value(
        "SELECT value FROM {$scratchSettings} WHERE name = 'cjis_login_notice_text'"
    );
    $truncatedLen = $truncatedVal !== null && $truncatedVal !== false ? mb_strlen((string) $truncatedVal) : null;
    g92($truncatedLen === 512,
        'REAL seeder + narrow column: cjis_login_notice_text truncated to exactly 512 characters',
        'got length: ' . var_export($truncatedLen, true) . "; seeder output: {$seedOut1}");
    g92($truncatedVal === $expectedTruncatedPrefix,
        'REAL seeder + narrow column: truncated value byte-matches the reported symptom '
            . '("...or data transiting or stored on t")',
        'got: ' . var_export($truncatedVal, true));
    $memberCountyExists1 = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'county'",
        [$prefix . 'member']
    );
    g92($memberCountyExists1, 'member.county was still added (this environment truncates rather than aborts)');

    // ─────────────────────────────────────────────────────────────────
    // (c) REAL END-TO-END, FIXED ORDER: widen FIRST, then seed — must
    // store the FULL 812-character notice via the same REAL seeder script.
    // ─────────────────────────────────────────────────────────────────
    echo "\n=== (c) real seeder against a widened-first column stores the full notice ===\n";
    g92_reset_scratch_tables($scratchSettings, $scratchMember, true);
    db()->exec("ALTER TABLE {$scratchSettings} MODIFY COLUMN `value` TEXT DEFAULT NULL");
    $colAfterWiden = db_fetch_one(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'value'",
        [$prefix . 'settings']
    );
    g92(strtolower((string) ($colAfterWiden['DATA_TYPE'] ?? '')) === 'text',
        'scratch settings.value is TEXT after widening (mirrors the fixed step order)');

    [$seedOut2] = g92_run($phpBin, $harnessSeeder);
    $fullVal = db_fetch_value(
        "SELECT value FROM {$scratchSettings} WHERE name = 'cjis_login_notice_text'"
    );
    $fullLen = $fullVal !== null && $fullVal !== false ? mb_strlen((string) $fullVal) : null;
    g92($fullLen === 812,
        'GH #92 FIX PROOF: REAL seeder + widened-first column stores the FULL 812-character notice',
        'got length: ' . var_export($fullLen, true) . "; seeder output: {$seedOut2}");
    g92($fullVal === $defaultNotice,
        'GH #92 FIX PROOF: stored value is byte-identical to the full default notice (no truncation at all)');
    $memberCountyExists2 = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'county'",
        [$prefix . 'member']
    );
    g92($memberCountyExists2, 'member.county was added (unrelated feature in the same script, unaffected by the fix)');

    // ─────────────────────────────────────────────────────────────────
    // (d) REAL REPAIR MIGRATION: detects + fixes the exact truncation
    // signature, is idempotent, and never touches a deliberate
    // customization — including one that happens to also be 512 chars.
    // ─────────────────────────────────────────────────────────────────
    echo "\n=== (d) real repair migration (sql/run_gh92_settings_value_repair.php) ===\n";

    // d1: reproduce the truncated-signature state, then repair it.
    g92_reset_scratch_tables($scratchSettings, $scratchMember, true);
    g92_run($phpBin, $harnessSeeder); // produces the truncated row again
    $preRepairLen = mb_strlen((string) db_fetch_value(
        "SELECT value FROM {$scratchSettings} WHERE name = 'cjis_login_notice_text'"
    ));
    g92($preRepairLen === 512, 'd1 setup: truncated row is in place before repair runs');

    [$repairOut1, $repairExit1] = g92_run($phpBin, $harnessRepair);
    g92($repairExit1 === 0, 'the repair migration exits 0', "output: {$repairOut1}");
    $colAfterRepair = db_fetch_one(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'value'",
        [$prefix . 'settings']
    );
    g92(strtolower((string) ($colAfterRepair['DATA_TYPE'] ?? '')) === 'text',
        'GH #92 REPAIR: the repair migration widens a still-narrow settings.value to TEXT',
        "output: {$repairOut1}");
    $repairedVal = db_fetch_value(
        "SELECT value FROM {$scratchSettings} WHERE name = 'cjis_login_notice_text'"
    );
    g92($repairedVal === $defaultNotice,
        'GH #92 REPAIR: a truncated cjis_login_notice_text is rewritten to the full 812-character default',
        'got: ' . var_export($repairedVal, true));

    // d2: idempotency — a second run must be a clean no-op.
    [$repairOut2, $repairExit2] = g92_run($phpBin, $harnessRepair);
    g92($repairExit2 === 0, 'a second run of the repair migration exits 0');
    g92(strpos($repairOut2, '[SKIP]') !== false && strpos($repairOut2, '[OK]') === false,
        'a second run of the repair migration is a clean [SKIP]-only no-op (idempotent)',
        "output: {$repairOut2}");
    $valAfterSecondRun = db_fetch_value(
        "SELECT value FROM {$scratchSettings} WHERE name = 'cjis_login_notice_text'"
    );
    g92($valAfterSecondRun === $defaultNotice,
        'the notice is unchanged by the idempotent second run');

    // d3: a genuinely-customized notice (NOT the truncation signature,
    // arbitrary length) must never be touched.
    g92_reset_scratch_tables($scratchSettings, $scratchMember, false); // already TEXT this time
    db_query(
        "INSERT INTO {$scratchSettings} (name, value) VALUES (?, ?)",
        ['cjis_login_notice_text', 'Custom Agency Notice: contact the watch office before logging in.']
    );
    [, $repairExit3] = g92_run($phpBin, $harnessRepair);
    g92($repairExit3 === 0, 'the repair migration exits 0 against a customized notice');
    $customUnchanged = db_fetch_value(
        "SELECT value FROM {$scratchSettings} WHERE name = 'cjis_login_notice_text'"
    );
    g92($customUnchanged === 'Custom Agency Notice: contact the watch office before logging in.',
        'a genuinely customized notice (different content and length) is left completely untouched',
        'got: ' . var_export($customUnchanged, true));

    // d4: the sharpest edge case — a customization that is ALSO exactly
    // 512 characters, but is NOT a prefix of the default text. This is
    // the case that distinguishes "truncation signature" from "any
    // 512-character value", per the task's explicit repair requirement.
    $customSameLength = mb_substr(str_repeat('CUSTOM AGENCY NOTICE. ', 40), 0, 512);
    if (mb_strlen($customSameLength) !== 512) {
        throw new Exception('test fixture error: customSameLength is not 512 chars');
    }
    if ($customSameLength === $expectedTruncatedPrefix) {
        throw new Exception('test fixture error: customSameLength accidentally matches the real truncation prefix');
    }
    db_query(
        "UPDATE {$scratchSettings} SET value = ? WHERE name = 'cjis_login_notice_text'",
        [$customSameLength]
    );
    [, $repairExit4] = g92_run($phpBin, $harnessRepair);
    g92($repairExit4 === 0, 'the repair migration exits 0 against a same-length-but-different customization');
    $sameLengthUnchanged = db_fetch_value(
        "SELECT value FROM {$scratchSettings} WHERE name = 'cjis_login_notice_text'"
    );
    g92($sameLengthUnchanged === $customSameLength,
        'GH #92 REPAIR SAFETY: a 512-character value that is NOT a prefix of the default '
            . '(same length as the truncation signature, different content) is left completely untouched — '
            . 'the repair targets the TRUNCATION SIGNATURE, not "any 512-character value"',
        'got: ' . var_export($sameLengthUnchanged, true));

    // d5: a missing row (settings never seeded at all) must not error.
    db_query("DELETE FROM {$scratchSettings} WHERE name = 'cjis_login_notice_text'");
    [$repairOut5, $repairExit5] = g92_run($phpBin, $harnessRepair);
    g92($repairExit5 === 0, 'the repair migration exits 0 when cjis_login_notice_text is absent entirely',
        "output: {$repairOut5}");
} finally {
    // ── Cleanup: always drop the scratch tables and remove the harness. ──
    try {
        db()->exec("DROP TABLE IF EXISTS {$scratchSettings}");
        db()->exec("DROP TABLE IF EXISTS {$scratchMember}");
    } catch (Throwable $e) {
        // best-effort cleanup only
    }
    @unlink($tmpSqlDir . DIRECTORY_SEPARATOR . 'run_99i_cjis_county.php');
    @unlink($tmpSqlDir . DIRECTORY_SEPARATOR . 'run_gh92_settings_value_repair.php');
    @unlink($tmpIncDir . DIRECTORY_SEPARATOR . 'db.php');
    @unlink($tmpDir . DIRECTORY_SEPARATOR . 'config.php');
    @rmdir($tmpSqlDir);
    @rmdir($tmpIncDir);
    @rmdir($tmpDir);
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
