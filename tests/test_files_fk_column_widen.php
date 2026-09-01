<?php
/**
 * files/files_x MEDIUMINT foreign-key column widen — regression tests.
 *
 * Found 2026-08-31 while another agent verified the GH#124 wastebasket/
 * ticket-purge cascade fix and needed to attach a file to a ticket for the
 * test: `sql/base_schema.sql` declared `files.id` / `files.ticket_id` /
 * `files.responder_id` / `files.facility_id` / `files.mi_id` (and
 * `files_x.id` / `files_x.file_id`) as MEDIUMINT — a real 3-byte MySQL/
 * MariaDB storage type with a hard signed ceiling of 8,388,607, not a
 * cosmetic display-width parenthetical the way `int(11)` is used elsewhere
 * in this schema. `ticket.id`, `facilities.id`, `responder.id` and
 * `user.id` are all `bigint(8)` and, on the shared dev database, already
 * hold values north of 900 million — so every real file attach against a
 * ticket with that large an id fails with MySQL error 1264 "Out of range
 * value for column 'ticket_id'" today, not hypothetically at some future
 * scale. `sql/run_files_fk_column_widen.php` is the idempotent repair
 * migration; `sql/base_schema.sql` / `sql/base_schema_RESET_DESTRUCTIVE.sql`
 * were also fixed so a brand-new install never creates the narrow columns
 * in the first place.
 *
 * This file proves, per this project's "reproduce via real writer"
 * discipline (a hand-seeded ideal-state mock would hide exactly this bug
 * class — see CLAUDE.md):
 *
 *   (a) STRUCTURAL — sql/base_schema.sql and
 *       sql/base_schema_RESET_DESTRUCTIVE.sql no longer declare ANY of the
 *       seven affected columns as mediumint, and declare the correct
 *       replacement types, by parsing the actual shipped files.
 *   (b) REAL END-TO-END, BUGGY SHAPE — building a scratch `files`/
 *       `files_x` pair with the EXACT original narrow column definitions
 *       reproduces the real reported failure: an INSERT with a ticket_id
 *       above the mediumint ceiling fails with SQLSTATE 22003 / MySQL
 *       error 1264, byte-for-byte the same error the live dev database hit
 *       before this fix.
 *   (c) REAL FIX PROOF — running the REAL, unmodified
 *       sql/run_files_fk_column_widen.php script (via a real PHP
 *       subprocess, not a hand-copied snapshot of its logic) against that
 *       same scratch pair widens every column, preserves existing data and
 *       the AUTO_INCREMENT counter, and the SAME previously-failing INSERT
 *       now succeeds.
 *   (d) IDEMPOTENCY — a second run of the real script against the
 *       already-fixed scratch pair is a clean no-op (every column reports
 *       [SKIP], nothing reports [OK]).
 *   (e) GRACEFUL ABSENCE — running the real script against a scratch
 *       prefix where `files`/`files_x` don't exist at all exits 0 with
 *       [SKIP] messages, not a fatal error (covers an install where these
 *       tables are somehow missing).
 *
 * This environment has no database-creation privilege (confirmed during
 * the GH #92 fix — only the app's own database is grantable), so isolation
 * is achieved with a unique TABLE PREFIX within that same database
 * (fw_selftest_<random>_files / _files_x) rather than a separate database —
 * exactly the technique tests/test_gh92_settings_value_widening.php
 * established, reused here via the same harness shape (a scratch
 * config.php + inc/db.php shim pointing sql/run_files_fk_column_widen.php's
 * own require_once calls at the real database, with $db_prefix set to the
 * scratch prefix so it operates on scratch tables instead of the real
 * `files`/`files_x`).
 *
 * Usage: php tests/test_files_fk_column_widen.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$pass = 0;
$fail = 0;

function fw_check(bool $cond, string $label, string $detail = ''): void
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
// (a) STRUCTURAL: base_schema.sql + base_schema_RESET_DESTRUCTIVE.sql no
// longer declare the affected columns as mediumint, and declare the
// correct replacement types.
// ─────────────────────────────────────────────────────────────────────
echo "=== (a) base_schema.sql / base_schema_RESET_DESTRUCTIVE.sql column shapes ===\n";

foreach (['sql/base_schema.sql', 'sql/base_schema_RESET_DESTRUCTIVE.sql'] as $schemaFile) {
    $src = file_get_contents($base . '/' . $schemaFile);
    fw_check($src !== false, "{$schemaFile} is readable");
    $src = (string) $src;

    // Isolate just the `files` and `files_x` CREATE TABLE bodies so the
    // mediumint-absence check can't accidentally pass by looking at an
    // unrelated table elsewhere in a 2000+-line schema dump.
    $matchedFiles = preg_match(
        '/CREATE TABLE (?:IF NOT EXISTS )?`files`\s*\((.*?)\)\s*ENGINE=/is',
        $src, $filesBody
    );
    $matchedFilesX = preg_match(
        '/CREATE TABLE (?:IF NOT EXISTS )?`files_x`\s*\((.*?)\)\s*ENGINE=/is',
        $src, $filesXBody
    );
    fw_check($matchedFiles === 1, "{$schemaFile}: found the files CREATE TABLE body");
    fw_check($matchedFilesX === 1, "{$schemaFile}: found the files_x CREATE TABLE body");

    if ($matchedFiles === 1) {
        $body = $filesBody[1];
        fw_check(stripos($body, 'mediumint') === false,
            "{$schemaFile}: files no longer declares any mediumint column");
        fw_check((bool) preg_match('/`id`\s+int\(11\)\s+NOT NULL AUTO_INCREMENT/i', $body),
            "{$schemaFile}: files.id is int(11) AUTO_INCREMENT");
        foreach (['ticket_id', 'responder_id', 'facility_id', 'mi_id'] as $col) {
            fw_check((bool) preg_match('/`' . $col . '`\s+bigint\(8\)\s+NOT NULL DEFAULT 0/i', $body),
                "{$schemaFile}: files.{$col} is bigint(8) NOT NULL DEFAULT 0");
        }
    }

    if ($matchedFilesX === 1) {
        $bodyX = $filesXBody[1];
        fw_check(stripos($bodyX, 'mediumint') === false,
            "{$schemaFile}: files_x no longer declares any mediumint column");
        fw_check((bool) preg_match('/`id`\s+int\(11\)\s+NOT NULL AUTO_INCREMENT/i', $bodyX),
            "{$schemaFile}: files_x.id is int(11) AUTO_INCREMENT");
        fw_check((bool) preg_match('/`file_id`\s+int\(11\)\s+NOT NULL/i', $bodyX),
            "{$schemaFile}: files_x.file_id is int(11) NOT NULL");
    }
}

// Confirm ticket.id is unaffected/untouched by this fix — the task this
// migration exists for explicitly scoped ticket.id OUT (it's already
// bigint(8) and not part of the mediumint defect class).
$baseSchemaSrc = (string) file_get_contents($base . '/sql/base_schema.sql');
fw_check(
    (bool) preg_match('/CREATE TABLE IF NOT EXISTS `ticket`.{0,200}?`id`\s+bigint\(8\)\s+NOT NULL AUTO_INCREMENT/is', $baseSchemaSrc),
    'sql/base_schema.sql: ticket.id is (and remains) bigint(8) — untouched by this fix'
);

// ─────────────────────────────────────────────────────────────────────
// Shared harness for (b)/(c)/(d)/(e): an isolated scratch table pair,
// driven by the REAL sql/run_files_fk_column_widen.php file (read fresh
// from disk, not a frozen copy) via a real subprocess.
// ─────────────────────────────────────────────────────────────────────
$prefix = 'fw_selftest_' . substr(bin2hex(random_bytes(4)), 0, 8) . '_';
$scratchFiles = "`{$prefix}files`";
$scratchFilesX = "`{$prefix}files_x`";

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fw_harness_' . substr(bin2hex(random_bytes(4)), 0, 8);
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

// sql/run_files_fk_column_widen.php does its own separate
// `require_once __DIR__ . '/../inc/db.php'` (in addition to requiring
// config.php), resolving to <harness>/inc/db.php — one-line shim to the
// REAL inc/db.php by absolute path (require_once dedups by resolved
// realpath, so this is the same file, not a second divergent copy).
file_put_contents(
    $tmpIncDir . DIRECTORY_SEPARATOR . 'db.php',
    "<?php\nrequire_once " . var_export($base . '/inc/db.php', true) . ";\n"
);

$realMigrationPath = $base . '/sql/run_files_fk_column_widen.php';
$migrationSrc = file_get_contents($realMigrationPath);
fw_check($migrationSrc !== false, 'sql/run_files_fk_column_widen.php is readable (the file this bug is about)');
fw_check(
    (bool) preg_match('/^if \(PHP_SAPI !== \'cli\'\) \{ http_response_code\(403\); exit\(\'CLI only\'\); \}/m', (string) $migrationSrc),
    'sql/run_files_fk_column_widen.php has the CLI-only guard, per this project\'s web-exposure-hardening convention'
);
file_put_contents($tmpSqlDir . DIRECTORY_SEPARATOR . 'run_files_fk_column_widen.php', (string) $migrationSrc);
$harnessMigration = $tmpSqlDir . DIRECTORY_SEPARATOR . 'run_files_fk_column_widen.php';

/** Run a PHP CLI script and return [stdout+stderr combined, exit code]. */
function fw_run(string $phpBin, string $scriptPath): array
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

function fw_col_type(string $table, string $column): ?string
{
    $t = db_fetch_value(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $column]
    );
    return $t === false || $t === null ? null : strtolower((string) $t);
}

// The exact PRE-fix narrow shape (matches sql/base_schema.sql's shape
// before this fix, deliberately hardcoded here rather than derived from
// git history — this test's job is to prove the OLD shape was buggy and
// the NEW real migration fixes it, not to depend on git being available
// or on any particular commit range).
function fw_reset_scratch_tables_narrow(string $filesTbl, string $filesXTbl): void
{
    db()->exec("DROP TABLE IF EXISTS {$filesTbl}");
    db()->exec("DROP TABLE IF EXISTS {$filesXTbl}");
    db()->exec("CREATE TABLE {$filesTbl} (
        `id` mediumint(5) NOT NULL AUTO_INCREMENT,
        `title` varchar(128) NOT NULL,
        `filename` varchar(512) NOT NULL,
        `orig_filename` varchar(512) NOT NULL,
        `ticket_id` mediumint(6) NOT NULL DEFAULT 0,
        `responder_id` mediumint(6) NOT NULL DEFAULT 0,
        `facility_id` mediumint(6) NOT NULL DEFAULT 0,
        `mi_id` mediumint(6) NOT NULL DEFAULT 0,
        `type` int(2) DEFAULT 0,
        `filetype` varchar(128) NOT NULL,
        `_by` int(7) NOT NULL,
        `_on` datetime NOT NULL,
        `_from` varchar(16) NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci");
    db()->exec("CREATE TABLE {$filesXTbl} (
        `id` mediumint(6) NOT NULL AUTO_INCREMENT,
        `file_id` mediumint(6) NOT NULL,
        `user_id` int(4) NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci");
}

/** A minimal, valid row matching `files`'s NOT-NULL-without-default columns. */
function fw_insert_files_row(string $filesTbl, int $ticketId): void
{
    db_query(
        "INSERT INTO {$filesTbl}
            (`title`, `filename`, `orig_filename`, `ticket_id`, `filetype`, `_by`, `_on`, `_from`)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)",
        ['t', 'f.txt', 'orig.txt', $ticketId, 'text/plain', 1, 'test']
    );
}

$overflowTicketId = 900037133; // real magnitude seen on the live dev DB — well past the 8,388,607 mediumint ceiling
$smallTicketId = 42;

try {
    $phpBin = PHP_BINARY ?: 'php';

    // ─────────────────────────────────────────────────────────────────
    // (b) REAL END-TO-END, BUGGY SHAPE: the exact original narrow column
    // definitions must reproduce the real reported failure.
    // ─────────────────────────────────────────────────────────────────
    echo "\n=== (b) narrow (pre-fix) shape reproduces the real reported failure ===\n";
    fw_reset_scratch_tables_narrow($scratchFiles, $scratchFilesX);

    fw_check(fw_col_type($prefix . 'files', 'ticket_id') === 'mediumint',
        'scratch files.ticket_id really is mediumint (fixture is not a strawman)');

    // A normal-sized ticket_id must succeed even in the narrow shape —
    // proves the fixture isn't just broken outright.
    fw_insert_files_row($scratchFiles, $smallTicketId);
    $smallOk = (int) db_fetch_value("SELECT COUNT(*) FROM {$scratchFiles} WHERE ticket_id = ?", [$smallTicketId]);
    fw_check($smallOk === 1, 'BASELINE: an ordinary-magnitude ticket_id inserts fine even pre-fix');

    $threw1264 = false;
    $errMsg1264 = '';
    try {
        fw_insert_files_row($scratchFiles, $overflowTicketId);
    } catch (PDOException $e) {
        $threw1264 = (strpos($e->getMessage(), '1264') !== false || strpos($e->getMessage(), 'Out of range') !== false);
        $errMsg1264 = $e->getMessage();
    }
    fw_check($threw1264,
        'BUG REPRODUCED: inserting a real large ticket_id (900037133) against the narrow mediumint column '
            . 'fails with MySQL error 1264 "Out of range value" — byte-for-byte the failure this fix exists for',
        "got: {$errMsg1264}");

    // ─────────────────────────────────────────────────────────────────
    // (c) REAL FIX PROOF: run the REAL, unmodified migration script and
    // confirm every column widens, data survives, and the same INSERT
    // that just failed now succeeds.
    // ─────────────────────────────────────────────────────────────────
    echo "\n=== (c) real migration script fixes the scratch pair ===\n";
    [$fixOut, $fixExit] = fw_run($phpBin, $harnessMigration);
    fw_check($fixExit === 0, 'the real migration script exits 0', "output: {$fixOut}");
    fw_check(substr_count($fixOut, '[OK]') === 7,
        'the real migration script reports exactly 7 [OK] widenings (files.id/ticket_id/responder_id/facility_id/mi_id + files_x.id/file_id)',
        "output: {$fixOut}");

    $expectedTypes = [
        ['files', 'id', 'int'],
        ['files', 'ticket_id', 'bigint'],
        ['files', 'responder_id', 'bigint'],
        ['files', 'facility_id', 'bigint'],
        ['files', 'mi_id', 'bigint'],
        ['files_x', 'id', 'int'],
        ['files_x', 'file_id', 'int'],
    ];
    foreach ($expectedTypes as [$tbl, $col, $expectedType]) {
        $actual = fw_col_type($prefix . $tbl, $col);
        fw_check($actual === $expectedType,
            "GH FIX PROOF: {$tbl}.{$col} is now {$expectedType} (was mediumint)",
            'got: ' . var_export($actual, true));
    }

    // Pre-existing data survived the ALTER, with correct values.
    $survivedRow = db_fetch_one("SELECT ticket_id FROM {$scratchFiles} WHERE ticket_id = ?", [$smallTicketId]);
    fw_check($survivedRow !== null && (int) $survivedRow['ticket_id'] === $smallTicketId,
        'the pre-existing small-ticket_id row survived the ALTER with its value intact');

    // The exact INSERT that failed in (b) now succeeds.
    $insertSucceeded = false;
    $insertErr = '';
    try {
        fw_insert_files_row($scratchFiles, $overflowTicketId);
        $insertSucceeded = true;
    } catch (PDOException $e) {
        $insertErr = $e->getMessage();
    }
    fw_check($insertSucceeded,
        'GH FIX PROOF: the SAME large ticket_id (900037133) that failed with 1264 in (b) now inserts successfully',
        "got error: {$insertErr}");
    $storedValue = db_fetch_value("SELECT ticket_id FROM {$scratchFiles} WHERE ticket_id = ?", [$overflowTicketId]);
    fw_check((int) $storedValue === $overflowTicketId,
        'the stored ticket_id is byte-exact (900037133), not truncated or coerced');

    // AUTO_INCREMENT continuity: the id sequence must not have reset —
    // the row inserted in (b) got id=1, so the NEXT insert (just above,
    // the overflow-ticket row) must be id=2, proving the widen preserved
    // the counter rather than silently recreating the table from scratch.
    $ids = db_fetch_all("SELECT id FROM {$scratchFiles} ORDER BY id");
    fw_check(count($ids) === 2 && (int) $ids[0]['id'] === 1 && (int) $ids[1]['id'] === 2,
        'AUTO_INCREMENT continuity preserved across the widen (ids are 1, 2 — not reset)',
        'got ids: ' . json_encode($ids));

    // ─────────────────────────────────────────────────────────────────
    // (d) IDEMPOTENCY: a second run against the already-fixed pair is a
    // clean no-op.
    // ─────────────────────────────────────────────────────────────────
    echo "\n=== (d) idempotent re-run ===\n";
    [$reOut, $reExit] = fw_run($phpBin, $harnessMigration);
    fw_check($reExit === 0, 'a second run of the migration script exits 0', "output: {$reOut}");
    fw_check(strpos($reOut, '[OK]') === false,
        'a second run reports zero [OK] lines (every column already matches, nothing re-altered)',
        "output: {$reOut}");
    fw_check(substr_count($reOut, '[SKIP]') === 7,
        'a second run reports exactly 7 [SKIP] lines (one per column)',
        "output: {$reOut}");

    // ─────────────────────────────────────────────────────────────────
    // (e) GRACEFUL ABSENCE: a scratch prefix where the tables don't exist
    // at all must not fatal.
    // ─────────────────────────────────────────────────────────────────
    echo "\n=== (e) graceful handling when files/files_x don't exist ===\n";
    db()->exec("DROP TABLE IF EXISTS {$scratchFiles}");
    db()->exec("DROP TABLE IF EXISTS {$scratchFilesX}");
    [$absentOut, $absentExit] = fw_run($phpBin, $harnessMigration);
    fw_check($absentExit === 0, 'the migration script exits 0 when files/files_x are absent entirely', "output: {$absentOut}");
    fw_check(substr_count($absentOut, '[SKIP]') === 7,
        'all 7 columns report [SKIP] (not found) rather than fataling',
        "output: {$absentOut}");
} finally {
    // ── Cleanup: always drop the scratch tables and remove the harness. ──
    try {
        db()->exec("DROP TABLE IF EXISTS {$scratchFiles}");
        db()->exec("DROP TABLE IF EXISTS {$scratchFilesX}");
    } catch (Throwable $e) {
        // best-effort cleanup only
    }
    @unlink($tmpSqlDir . DIRECTORY_SEPARATOR . 'run_files_fk_column_widen.php');
    @unlink($tmpIncDir . DIRECTORY_SEPARATOR . 'db.php');
    @unlink($tmpDir . DIRECTORY_SEPARATOR . 'config.php');
    @rmdir($tmpSqlDir);
    @rmdir($tmpIncDir);
    @rmdir($tmpDir);
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
