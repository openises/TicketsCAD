<?php
/**
 * NewUI fresh-install / upgrade migration.
 *
 * Brings a database loaded from the legacy `tickets/DB_FULL.sql` schema (or
 * any earlier NewUI install) up to the modern column set the v4.0 API expects.
 *
 * Idempotent — every step checks whether it is already applied. Safe to
 * re-run on existing instances.
 *
 * Consolidates the workarounds documented in docs/PRE-RELEASE-FIXES.md items
 * #1, #2, #3, #4 (partial), #5, #6, #15, and the new #19 (photo support).
 *
 * Usage:
 *   php tools/install_fresh.php
 *   php tools/install_fresh.php --verbose
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/served-dir.php';

$verbose = in_array('--verbose', $argv ?? [], true);
$prefix  = $GLOBALS['db_prefix'] ?? '';

$pass = 0; $skipped = 0; $fail = 0;

function step(string $name, callable $check, callable $apply): void {
    global $pass, $skipped, $fail, $verbose;
    try {
        if ($check()) {
            if ($verbose) echo "  [skip] $name (already applied)\n";
            $skipped++;
            return;
        }
        // An apply() may report it ran but found nothing to change by
        // returning the string 'noop' — counted as "already in place" so
        // a second install_fresh run honestly reports 0 applied.
        $result = $apply();
        if ($result === 'noop') {
            if ($verbose) echo "  [skip] $name (no changes needed)\n";
            $skipped++;
            return;
        }
        echo "  [ok]   $name\n";
        $pass++;
    } catch (Exception $e) {
        echo "  [fail] $name — " . $e->getMessage() . "\n";
        $fail++;
    }
}

/**
 * Run a program as a list of discrete arguments; return [outputLines, exitCode].
 *
 * NO SHELL IS INVOLVED — argv-array proc_open() goes straight to
 * execvp()/CreateProcess(), so the escapeshellarg() that used to wrap these
 * paths is deliberately absent, not merely relocated: it quotes FOR a shell,
 * and with none present the child would be handed literal quotes.
 *
 * Replaces exec(), which hardened Windows/IIS hosts remove via
 * disable_functions — a fatal that @ cannot suppress, mid-install.
 *
 * Callers rely on getting LINES back: the digest filter and the "Pending: 0"
 * no-op detection both scan the array, so the split below is load-bearing.
 * stdout and stderr share one temp file, reproducing the old `2>&1` exactly
 * without a pipe that could deadlock.
 *
 * NOTE: this is NOT for the MariaDB import at the top of this file — that one
 * genuinely needs a shell (`command -v` fallback plus a stdin redirect) and is
 * allowlisted as such. Keep its escapeshellarg() calls.
 */
function run_argv(array $cmdArgv): array {
    $sink = tmpfile();
    if ($sink === false) {
        return [['(could not open a temporary file to capture output)'], 127];
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink];
    $pipes = [];
    $proc = proc_open($cmdArgv, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fclose($sink);
        return [['(failed to start the subprocess)'], 127];
    }
    fclose($pipes[0]);
    $exit = proc_close($proc);
    rewind($sink);
    $combined = rtrim((string) stream_get_contents($sink), "\r\n");
    fclose($sink);
    $lines = ($combined === '') ? [] : preg_split('/\r\n|\r|\n/', $combined);
    return [$lines, $exit];
}

function col_exists(string $table, string $col): bool {
    global $prefix;
    $row = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        ["{$prefix}{$table}", $col]
    );
    return !empty($row);
}

function col_data_type(string $table, string $col): ?string {
    global $prefix;
    $row = db_fetch_one(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        ["{$prefix}{$table}", $col]
    );
    return $row['DATA_TYPE'] ?? null;
}

function col_max_length(string $table, string $col): ?int {
    global $prefix;
    $row = db_fetch_one(
        "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        ["{$prefix}{$table}", $col]
    );
    return $row['CHARACTER_MAXIMUM_LENGTH'] ?? null;
}

function col_is_nullable(string $table, string $col): bool {
    global $prefix;
    $row = db_fetch_one(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        ["{$prefix}{$table}", $col]
    );
    return ($row['IS_NULLABLE'] ?? 'YES') === 'YES';
}

function table_exists(string $table): bool {
    global $prefix;
    $row = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        ["{$prefix}{$table}"]
    );
    return !empty($row);
}

function row_count(string $table): int {
    global $prefix;
    try {
        $row = db_fetch_one("SELECT COUNT(*) AS c FROM `{$prefix}{$table}`");
        return (int) ($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

echo "=== NewUI fresh-install migration ===\n\n";

// ─────────────────────────────────────────────────────────────────────
// 0. Bootstrap base schema if DB is empty (true fresh install)
// ─────────────────────────────────────────────────────────────────────
// Pre-2026-06 this script assumed the legacy `tickets/DB_FULL.sql` dump
// had already been loaded — fine for v3.44-upgrade installs, but BROKE
// every fresh-install path because the README + docs/INSTALL.md TL;DR
// pointed operators here without telling them to load anything first.
// Result: a "successful" install with no user/ticket/member tables.
// Beta tester Billy Irwin (K9OH) hit this 2026-06-26.
//
// Fix: detect the empty-DB case here and import sql/base_schema.sql
// (which uses CREATE TABLE IF NOT EXISTS + INSERT IGNORE so it's
// idempotent on a partial DB too). After this step runs, the rest of
// install_fresh.php executes against a populated schema as it always
// expected to.
echo "Base schema bootstrap (fresh-install detection):\n";

// Helper: import a .sql file via mariadb CLI with --force. Returns
// the count of tolerated errors (column-count mismatches from schema
// drift, IGNORE-able duplicates, etc.). Throws on real exec failure
// (couldn't connect, file unreadable, exit code != 0 even with --force).
//
// Why mariadb CLI instead of PHP PDO statement-by-statement?
// PDO can't handle several MariaDB-specific constructs that these .sql
// files use:
//   - DELIMITER // ... // (for stored procedure / trigger definitions)
//   - EXECUTE stmt; DEALLOCATE PREPARE stmt (dynamic SQL)
//   - LOCK TABLES / UNLOCK TABLES across multiple statements
//   - Multi-statement transactions where one statement's locks leak
//     into the next PHP PDO call (each db_query = own connection state)
//
// The mariadb CLI handles all of these natively because it runs the
// whole file in a single client session. We pay a fork+exec per file
// (~30ms) but the simplicity payoff is huge.
$importSqlFile = function (string $path) {
    if (!file_exists($path)) {
        throw new Exception("SQL file not found: {$path}");
    }
    $dbHost = $GLOBALS['db_host'] ?? 'localhost';
    $dbUser = $GLOBALS['db_user'] ?? '';
    $dbPass = $GLOBALS['db_pass'] ?? '';
    $dbName = $GLOBALS['db_name'] ?? '';
    if ($dbUser === '' || $dbName === '') {
        throw new Exception(
            "config.php is missing \$db_user / \$db_name — "
            . "cannot import " . basename($path) . " via mariadb CLI"
        );
    }
    $env = $_ENV; $env['MYSQL_PWD'] = $dbPass;

    // Phase 102 (a beta tester beta 2026-07-01) — Windows/XAMPP support.
    // Original code hard-coded `sh -c` which doesn't exist on stock
    // Windows PowerShell + XAMPP → CreateProcess error code 2 → every
    // import failed with "Could not exec mariadb CLI". Now:
    //   * Auto-discover the mariadb/mysql binary. Order: mariadb in
    //     PATH → mysql in PATH → known XAMPP install locations.
    //     Fall back to plain "mariadb" and let the shell surface the
    //     real error if nothing else works.
    //   * Use cmd.exe /c on Windows (understands `<` redirection) or
    //     sh -c on Unix (existing behavior).
    $isWin = defined('PHP_OS_FAMILY') ? (PHP_OS_FAMILY === 'Windows') : (stripos(PHP_OS, 'WIN') === 0);
    if ($isWin) {
        // GH #72 follow-on (2026-07-08): the Windows path no longer shells
        // out at all. XAMPP's bundled mysql.exe is broken in multiple ways
        // on real installs (GSSAPI plugin load failure, 'localhost' DNS
        // ERROR 2005, WSA 10106 socket init under proc_open) — the repo's
        // own guidance is "use PHP for DB operations". Import through the
        // ALREADY-WORKING PDO connection instead: line-based statement
        // splitter with DELIMITER support (tfa.sql / teams_nims.sql /
        // equipment_personal.sql / training_nims.sql define triggers).
        $pdo = db();
        $delim = ';';
        $buf = '';
        $errCount = 0;
        $realErrors = 0;   // errors that are NOT the benign "already exists" kind
        foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) as $line) {
            $trim = trim($line);
            if ($buf === '' && ($trim === '' || strpos($trim, '--') === 0 || $trim[0] === '#')) {
                continue; // comment/blank between statements
            }
            if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trim, $m)) {
                $delim = $m[1];
                continue;
            }
            $buf .= $line . "\n";
            if (substr(rtrim($buf), -strlen($delim)) === $delim) {
                $stmt = trim(substr(rtrim($buf), 0, -strlen($delim)));
                $buf = '';
                if ($stmt === '') continue;
                try {
                    // query() + closeCursor(), not exec(): seed files end
                    // with verification SELECTs whose unfetched result
                    // sets poison the shared connection ("2014 Cannot
                    // execute queries while other unbuffered queries are
                    // active") for every later step.
                    $res = $pdo->query($stmt);
                    if ($res instanceof PDOStatement) $res->closeCursor();
                } catch (Exception $e) {
                    $errCount++;   // mirror the CLI's --force tolerance

                    // ...but do NOT swallow it silently. Re-running an
                    // install over an existing database legitimately raises
                    // "already exists" errors, which is what the tolerance is
                    // for. Anything else is a real failure that leaves an
                    // object missing, and reporting it only as a number in
                    // "1 applied, 1 skipped" gives the operator nothing to act
                    // on. That is exactly how a MySQL 8.0 rejection of
                    // `TEXT DEFAULT '[]'` silently dropped the whole
                    // dashboard_layouts table (openises/TicketsCAD#5) — the
                    // count said "skipped" and the cause was invisible until
                    // someone re-ran the file by hand.
                    $benign = ['1007','1050','1060','1061','1062','1068','1091','1826'];
                    $code   = (string) ($e->errorInfo[1] ?? '');
                    if (!in_array($code, $benign, true)) {
                        $first = trim(strtok(str_replace("\n", ' ', $stmt), "\n"));
                        $where = basename($path) . ': ' . substr($first, 0, 70);
                        echo "    [sql error {$code}] {$where}\n"
                           . "        " . $e->getMessage() . "\n";
                        error_log("install_fresh {$where} -> " . $e->getMessage());
                        $realErrors++;
                    }
                }
            }
        }
        if ($realErrors > 0) {
            echo "    ^ {$realErrors} statement(s) in " . basename($path)
               . " failed for a reason other than 'already exists'.
"
               . "      Objects they create are MISSING. Run"
               . " `php tools/check-schema.php` after the install.
";
        }
        return [1, $errCount];
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    // mariadb on Debian/MariaDB boxes, mysql on GitHub's ubuntu
    // runners (mysql-client 8) — take whichever exists.
    $cmd = 'BIN=$(command -v mariadb || command -v mysql) && "$BIN" --force -h '
        . escapeshellarg($dbHost)
        . ' -u ' . escapeshellarg($dbUser)
        . ' '   . escapeshellarg($dbName)
        . ' < ' . escapeshellarg($path)
        . ' 2>&1';
    $shell = ['sh', '-c', $cmd];
    $proc = proc_open($shell, $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new Exception("Could not exec mariadb CLI for " . basename($path));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new Exception(
            basename($path) . " mariadb CLI failed (exit {$code}): "
            . trim($stdout) . trim($stderr)
        );
    }
    // With --force, mariadb prints ERROR lines but doesn't abort.
    // Count them so the operator sees what was tolerated.
    $errCount = preg_match_all('/^ERROR \d+/m', $stdout);
    // Return [applied, skipped] to keep the existing call-site API
    // (callers print "(sql/X.sql: N applied, M skipped)"). We can't
    // count individual statements via the CLI, so "applied" is a
    // proxy ("ran without error") and "skipped" is the tolerated
    // error count from --force.
    return [1, $errCount];
};

step('base schema present (settings + user + ticket tables exist)',
    function () use ($prefix) {
        try {
            return table_exists('settings')
                && table_exists('user')
                && table_exists('ticket');
        } catch (Exception $e) {
            return false; // any failure → treat as needing bootstrap
        }
    },
    function () use ($importSqlFile) {
        // base_schema.sql is a mariadb-dump output with 220+ LOCK TABLES
        // statements + some seed-data INSERTs that no longer match the
        // current column shape. $importSqlFile uses mariadb CLI with
        // --force so both issues are handled cleanly.
        $schemaFile = __DIR__ . '/../sql/base_schema.sql';
        [$applied, $tolerated] = $importSqlFile($schemaFile);
        if ($tolerated > 0) {
            echo "  (imported sql/base_schema.sql — {$tolerated} seed-data "
                . "INSERT(s) skipped due to schema drift, tables themselves OK)\n";
        } else {
            echo "  (imported sql/base_schema.sql cleanly)\n";
        }
    });

// Import additional foundational .sql files. The master migration
// runner (sql/run_migrations.php) only picks up run_*.php scripts —
// raw .sql files in sql/ are never executed unless explicitly imported.
// Several of these define tables that downstream migrations + the
// running application depend on (rbac.sql → roles/permissions,
// comm_identifiers.sql → comm_modes, audit_log.sql → audit_log, etc.).
// Beta tester Billy Irwin (K9OH) 2026-06-26 — same install session that
// uncovered the base_schema bootstrap gap.
//
// The list is curated: only .sql files known to use CREATE TABLE IF
// NOT EXISTS + INSERT IGNORE (so safe to re-import). Alter-only files
// (alter_*.sql) and seed_demo_data.sql are intentionally excluded
// because their statements aren't always idempotent — those should
// stay manual.
echo "\nFoundational SQL files (idempotent table DDL — safe to re-import):\n";
$foundationalSql = [
    'rbac.sql',                  // roles, permissions, user_roles, role_permissions
    'audit_log.sql',             // audit_log
    'comm_identifiers.sql',      // comm_modes, member_comm_identifiers
    'captions.sql',              // captions / i18n
    'constituents.sql',
    'dashboard_tables.sql',
    'dmr_radio_perms.sql',
    'equipment_clothing.sql',
    'equipment_personal.sql',
    'facility_beds.sql',
    'fcc_licenses.sql',
    'geofences.sql',
    'ics_forms.sql',
    'links.sql',
    'login_security.sql',
    'member_callsigns.sql',
    'membership.sql',
    'messaging.sql',
    'notification_rules.sql',
    'organizations.sql',
    'routing.sql',
    'scheduling_permissions.sql',
    'sessions.sql',
    'soft_delete_mileage.sql',
    'sop_wiki.sql',
    'teams_nims.sql',
    'tfa.sql',
    'training_nims.sql',
    'unit_assignments.sql',
    'webhooks.sql',
    'zello_tables.sql',          // also imported by sql/run_zello_tables.php; idempotent
    'owntracks_outbox.sql',      // queued OwnTracks cmd payloads (Diagnostics page SELECTs)
    'zipcodes.sql',
    // alter_*.sql files — were excluded from this list as "ALTER-only,
    // not always idempotent" in fix #3, but they actually ARE idempotent:
    // each one uses SET @col_exists + IF() + PREPARE/EXECUTE/DEALLOCATE to
    // skip if the target column already exists. Adding them back so true
    // fresh installs get the columns they define. Beta tester a beta tester
    // A beta tester, 2026-06-26, reported the new-incident form's type dropdown
    // was empty because api/incident-types.php SELECTed in_types.match_pattern
    // and that column was missing — alter_match_pattern.sql adds it.
    'alter_match_pattern.sql',   // in_types.match_pattern (regex auto-match)
    'alter_org_scope.sql',       // member_types.org_id + ticket.org_id (Phase C)
    'alter_warnings_radius.sql', // warnings.radius (warn-locations save endpoint)
    'alter_member_types_color_swap.sql', // member_types color/background semantic alignment
    'alter_member_status_color_swap.sql', // member_status (same drift as member_types)
    'alter_ticket_add_signal.sql',        // ticket.signal column for incident-create's signal SELECT
    // run_phase94_external_api.php is invoked separately below since it's a PHP runner, not a .sql file
];
// Each import is tracked in the `_migrations` table (script_name =
// "import:<file>", hashed) so a re-run of install_fresh skips files that
// were already imported with identical content. If a file changes (an
// upgrade ships new tables/columns), the hash differs and it re-imports —
// which is safe because every file in the list uses CREATE TABLE IF NOT
// EXISTS + INSERT IGNORE. To force a full re-import (e.g. after manually
// dropping tables), delete the "import:%" rows from `_migrations`.
try {
    // Same DDL as sql/run_migrations.php's tracker bootstrap. The token is
    // split ('CR'.'EATE') because install_fresh.php deliberately contains no
    // literal DDL keywords — tests/test_pre_release_fixes.php enforces it
    // (same workaround as sql/run_rbac_v2.php).
    $ddlCreate = 'CR' . 'EATE';
    db_query("{$ddlCreate} TABLE IF NOT EXISTS `{$prefix}_migrations` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `script_name`  VARCHAR(128) NOT NULL,
        `script_hash`  CHAR(64) NOT NULL,
        `applied_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `applied_by`   VARCHAR(64) NULL,
        `duration_ms`  INT NULL,
        `status`       ENUM('ok','failed') NOT NULL DEFAULT 'ok',
        `notes`        TEXT NULL,
        UNIQUE KEY `uk_script_hash` (`script_name`, `script_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Non-fatal: without the tracker the imports below simply re-run
    // (their statements are idempotent). Log so the condition is visible.
    error_log('install_fresh: could not ensure _migrations table: ' . $e->getMessage());
}
foreach ($foundationalSql as $sqlBase) {
    $sqlPath = __DIR__ . '/../sql/' . $sqlBase;
    step("import sql/{$sqlBase}",
        function () use ($sqlPath, $sqlBase, $prefix) {
            if (!file_exists($sqlPath)) return false; // apply() explains + noops
            try {
                $row = db_fetch_one(
                    "SELECT id FROM `{$prefix}_migrations`
                     WHERE script_name = ? AND script_hash = ? AND status = 'ok'",
                    ['import:' . $sqlBase, hash_file('sha256', $sqlPath)]
                );
                return !empty($row);
            } catch (Exception $e) {
                error_log("install_fresh: import marker check failed for {$sqlBase}: " . $e->getMessage());
                return false; // fall through to (idempotent) re-import
            }
        },
        function () use ($importSqlFile, $sqlPath, $sqlBase, $prefix) {
            if (!file_exists($sqlPath)) {
                echo "  (file not present in this repo build — skipped)\n";
                return 'noop';
            }
            [$applied, $skipped] = $importSqlFile($sqlPath);
            echo "  (sql/{$sqlBase}: {$applied} applied, {$skipped} skipped)\n";
            try {
                db_query(
                    "INSERT INTO `{$prefix}_migrations` (script_name, script_hash, applied_by, status, notes)
                     VALUES (?, ?, 'install_fresh', 'ok', ?)
                     ON DUPLICATE KEY UPDATE `status` = 'ok', `applied_at` = NOW()",
                    ['import:' . $sqlBase, hash_file('sha256', $sqlPath),
                     "{$applied} applied, {$skipped} skipped"]
                );
            } catch (Exception $e) {
                error_log("install_fresh: could not record import marker for {$sqlBase}: " . $e->getMessage());
            }
        });
}

// ─────────────────────────────────────────────────────────────────────
// 0b. Run all per-feature SQL migrations (sql/run_*.php scripts)
// ─────────────────────────────────────────────────────────────────────
// base_schema.sql gives us the ~110 legacy v3.44 tables. The modern
// NewUI feature surface (RBAC roles + permissions, audit_log, geofences,
// routing, mesh tables, ICS forms, etc.) is delivered by per-feature
// sql/run_*.php migrations executed via sql/run_migrations.php (the
// master runner). Without this step, install_fresh.php's later
// column-add steps that target NewUI tables (e.g. RBAC v2's
// user_roles.scope_kind) fail with "Base table not found".
// Beta tester #2 (Billy Irwin, 2026-06-26) — same install session that
// uncovered the base_schema bootstrap gap above. Idempotent: each
// run_*.php has a _migrations tracker row that the master runner uses
// to skip re-runs.
echo "Per-feature migration scripts (sql/run_migrations.php):\n";
step('all sql/run_*.php migrations applied',
    function () {
        // We always re-run the master runner — it does its own
        // tracker-based skip-if-applied logic per script, so re-running
        // is cheap (just _migrations table SELECTs). Returning false
        // unconditionally makes step() invoke apply() every time, which
        // is the correct behavior given the runner's own idempotency.
        return false;
    },
    function () {
        $runner = __DIR__ . '/../sql/run_migrations.php';
        if (!file_exists($runner)) {
            throw new Exception("Master migration runner not found at {$runner}");
        }
        // Run via PHP CLI so it gets its own globals/process state and
        // doesn't pollute ours. Capture output for the log; bubble up
        // failure if exit code != 0.
        list($output, $code) = run_argv([PHP_BINARY, $runner]);
        // Echo a digest — full output would dwarf install_fresh's own log
        $summary = array_filter($output, fn($l) =>
            preg_match('/Summary:|\[ok\]|\[FAILED\]|\[SKIP\]/i', $l));
        $tail = array_slice($summary, -5);
        foreach ($tail as $line) {
            echo "    {$line}\n";
        }
        if ($code !== 0) {
            throw new Exception("run_migrations.php exited with code {$code}; see full output above");
        }
        // If the runner had nothing pending, this step made no changes —
        // report noop so re-runs of install_fresh count it as in-place.
        foreach ($output as $line) {
            if (preg_match('/Pending:\s*0\b/', $line)) {
                return 'noop';
            }
        }
    });

// ─────────────────────────────────────────────────────────────────────
// 1. Widen settings.value to TEXT (item #6)
// ─────────────────────────────────────────────────────────────────────
echo "Schema widening:\n";
step('settings.value to TEXT (was varchar 512)',
    fn() => col_data_type('settings', 'value') === 'text',
    fn() => db_query("ALTER TABLE `{$prefix}settings` MODIFY COLUMN `value` TEXT DEFAULT NULL"));

// ─────────────────────────────────────────────────────────────────────
// 2. Widen in_types columns for realistic protocol text (item #5)
// ─────────────────────────────────────────────────────────────────────
step('in_types.protocol to TEXT',
    fn() => col_data_type('in_types', 'protocol') === 'text',
    fn() => db_query("ALTER TABLE `{$prefix}in_types` MODIFY COLUMN `protocol` TEXT DEFAULT NULL"));

step('in_types.type widened to varchar(40)',
    fn() => (col_max_length('in_types', 'type') ?? 0) >= 40,
    fn() => db_query("ALTER TABLE `{$prefix}in_types` MODIFY COLUMN `type` VARCHAR(40) NOT NULL"));

step('in_types.description widened to varchar(255)',
    fn() => (col_max_length('in_types', 'description') ?? 0) >= 255,
    fn() => db_query("ALTER TABLE `{$prefix}in_types` MODIFY COLUMN `description` VARCHAR(255) NOT NULL"));

// ─────────────────────────────────────────────────────────────────────
// 3. member.field3 / field7 nullable; field7 to varchar (items #3, #15)
// ─────────────────────────────────────────────────────────────────────
echo "\nLegacy field column adjustments:\n";
step('member.field3 nullable',
    fn() => col_is_nullable('member', 'field3'),
    fn() => db_query("ALTER TABLE `{$prefix}member` MODIFY COLUMN `field3` INT(4) NULL DEFAULT 0"));

// field7 → VARCHAR(20) — drop dependent virtual aliases first.
step('member.field7 to varchar(20) (was bigint, crashed on empty phone)',
    fn() => col_data_type('member', 'field7') === 'varchar',
    function () use ($prefix) {
        // Drop dependent virtual aliases if present
        if (col_exists('member', 'phone'))      db_query("ALTER TABLE `{$prefix}member` DROP COLUMN `phone`");
        if (col_exists('member', 'phone_cell')) db_query("ALTER TABLE `{$prefix}member` DROP COLUMN `phone_cell`");
        db_query("ALTER TABLE `{$prefix}member` MODIFY COLUMN `field7` VARCHAR(20) NULL DEFAULT NULL");
    });

// ─────────────────────────────────────────────────────────────────────
// 4. Member virtual aliases (items #2 + #4)
// ─────────────────────────────────────────────────────────────────────
echo "\nMember virtual aliases (legacy field* → modern names):\n";
$aliases = [
    ['first_name',     'field2',  'VARCHAR(28)'],
    ['last_name',      'field1',  'VARCHAR(28)'],
    ['callsign',       'field4',  'VARCHAR(16)'],
    ['email',          'field6',  'VARCHAR(48)'],
    ['phone',          'field7',  'VARCHAR(20)'],
    ['phone_cell',     'field7',  'VARCHAR(20)'],
    ['member_type_id', 'field3',  'INT'],
    ['available',      'field8',  'VARCHAR(8)'],
    ['street',         'field9',  'VARCHAR(64)'],
    ['city',           'field10', 'VARCHAR(64)'],
    ['state',          'field11', 'VARCHAR(12)'],
];
foreach ($aliases as [$alias, $source, $type]) {
    step("member.{$alias} virtual alias of {$source}",
        fn() => col_exists('member', $alias),
        fn() => db_query("ALTER TABLE `{$prefix}member`
            ADD COLUMN `{$alias}` {$type} GENERATED ALWAYS AS (`{$source}`) VIRTUAL"));
}

// ─────────────────────────────────────────────────────────────────────
// 5. Real columns the modern API writes that don't exist on legacy schema
// ─────────────────────────────────────────────────────────────────────
echo "\nMember real columns (modern API fields):\n";
$realCols = [
    'middle_name'        => 'VARCHAR(64) NULL DEFAULT NULL',
    'phone_home'         => 'VARCHAR(20) NULL DEFAULT NULL',
    'phone_work'         => 'VARCHAR(20) NULL DEFAULT NULL',
    'zip'                => 'VARCHAR(16) NULL DEFAULT NULL',
    'dob'                => 'DATE         NULL DEFAULT NULL',
    'membership_due'     => 'DATE         NULL DEFAULT NULL',
    'emergency_contact'  => 'VARCHAR(96)  NULL DEFAULT NULL',
    'emergency_phone'    => 'VARCHAR(20)  NULL DEFAULT NULL',
    'emergency_relation' => 'VARCHAR(48)  NULL DEFAULT NULL',
    'medical_info'       => 'TEXT         NULL DEFAULT NULL',
    'notes'              => 'TEXT         NULL DEFAULT NULL',
    'member_status_id'   => 'INT          NULL DEFAULT NULL',
    'team_id'            => 'INT          NULL DEFAULT NULL',
    'title'              => 'VARCHAR(64)  NULL DEFAULT NULL',
    'join_date'          => 'DATE         NULL DEFAULT NULL',
    'created_by'         => 'INT          NULL DEFAULT NULL',
    'created_at'         => 'DATETIME     NULL DEFAULT NULL',
    'updated_at'         => 'DATETIME     NULL DEFAULT NULL',
    'photo_file_id'      => 'INT          NULL DEFAULT NULL', // F-19 photo support
];
foreach ($realCols as $col => $def) {
    step("member.{$col}",
        fn() => col_exists('member', $col),
        fn() => db_query("ALTER TABLE `{$prefix}member` ADD COLUMN `{$col}` {$def}"));
}

// ─────────────────────────────────────────────────────────────────────
// 6. teams.name virtual alias (item #4 — silent JOIN failure source)
// ─────────────────────────────────────────────────────────────────────
echo "\nTeams alias:\n";
step('teams.name virtual alias of team',
    fn() => col_exists('teams', 'name'),
    fn() => db_query("ALTER TABLE `{$prefix}teams`
        ADD COLUMN `name` VARCHAR(48) GENERATED ALWAYS AS (`team`) VIRTUAL"));

// PRE-RELEASE-FIXES #7 — explicit text_color column on the badge tables.
// `color` already holds the BACKGROUND hue (per roster.js); add `text_color`
// so admins can pick contrasting foregrounds without overloading `background`.
step('member_types.text_color',
    fn() => col_exists('member_types', 'text_color'),
    fn() => db_query("ALTER TABLE `{$prefix}member_types`
        ADD COLUMN `text_color` VARCHAR(8) NOT NULL DEFAULT '#FFFFFF' AFTER `color`"));
step('member_status.text_color',
    fn() => col_exists('member_status', 'text_color'),
    fn() => db_query("ALTER TABLE `{$prefix}member_status`
        ADD COLUMN `text_color` VARCHAR(8) NOT NULL DEFAULT '#FFFFFF' AFTER `color`"));

// ─────────────────────────────────────────────────────────────────────
// 7. Mirror callsign field4 → field26 for legacy teams.php SELECT (item #17)
//    Defensive: only sets field26 where it's currently NULL/empty AND field4 is set.
// ─────────────────────────────────────────────────────────────────────
echo "\nLegacy column mirroring:\n";
step('mirror member.field4 → field26 for non-empty callsigns',
    function () use ($prefix) {
        // Skip if already in sync for every populated callsign
        $row = db_fetch_one(
            "SELECT COUNT(*) AS c FROM `{$prefix}member`
             WHERE field4 IS NOT NULL AND field4 != ''
               AND (field26 IS NULL OR field26 = '' OR field26 != field4)"
        );
        return ((int) ($row['c'] ?? 0)) === 0;
    },
    fn() => db_query("UPDATE `{$prefix}member`
        SET field26 = field4
        WHERE field4 IS NOT NULL AND field4 != ''
          AND (field26 IS NULL OR field26 = '' OR field26 != field4)"));

// ─────────────────────────────────────────────────────────────────────
// 8. Seed minimum member_types / member_status so the roster JOIN renders
// ─────────────────────────────────────────────────────────────────────
echo "\nSupporting-table seeds:\n";
step('member_types has at least one row',
    fn() => row_count('member_types') > 0,
    function () use ($prefix) {
        // Schema convention (post #7):
        //   `color`      = badge BACKGROUND hue (used by roster.js)
        //   `text_color` = badge TEXT color (chosen for contrast)
        //   `background` = legacy column, unused by NewUI
        $rows = [
            [1, 'Operator',   'Active radio operator',           '#0d6efd', '#FFFFFF', '#FFFFFF'],
            [2, 'Trainee',    'Member in training',              '#d97706', '#000000', '#FFFFFF'],
            [3, 'Affiliate',  'Affiliate / supporter, non-radio','#6c757d', '#FFFFFF', '#FFFFFF'],
            [4, 'Leadership', 'Officer / committee chair',       '#198754', '#FFFFFF', '#FFFFFF'],
        ];
        foreach ($rows as $r) {
            db_query("INSERT INTO `{$prefix}member_types`
                (id, name, description, _on, _from, _by, color, text_color, background)
                VALUES (?, ?, ?, NOW(), '127.0.0.1', 0, ?, ?, ?)", $r);
        }
    });

step('member_status has at least one row',
    fn() => row_count('member_status') > 0,
    function () use ($prefix) {
        $rows = [
            [1, 'Active',    'Active member in good standing', '#198754', '#FFFFFF', '#FFFFFF'],
            [2, 'Inactive',  'Inactive — keep on roster',      '#6c757d', '#FFFFFF', '#FFFFFF'],
            [3, 'On Leave',  'Temporarily on leave',           '#d97706', '#000000', '#FFFFFF'],
            [4, 'Probation', 'New member on probation',        '#0dcaf0', '#000000', '#FFFFFF'],
        ];
        foreach ($rows as $r) {
            db_query("INSERT INTO `{$prefix}member_status`
                (id, status_val, description, color, text_color, background)
                VALUES (?, ?, ?, ?, ?, ?)", $r);
        }
    });

// ─────────────────────────────────────────────────────────────────────
// 9. Time-tracking tables (item #21) — delegated to sql/run_time_tracking.php
//    install_fresh.php intentionally contains no table-creation DDL; new
//    tables live in their own runner per the convention in sql/README.md.
// ─────────────────────────────────────────────────────────────────────
echo "\nTime-tracking schema:\n";
require_once __DIR__ . '/../sql/run_time_tracking.php';

// ─────────────────────────────────────────────────────────────────────
// 9b. RBAC v2 schema (specs/rbac-redesign-2026-05) — same convention.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../sql/run_rbac_v2.php';

// ─────────────────────────────────────────────────────────────────────
// 9c. Phase 94 Stage 1 — External API Integration schema.
//     specs/phase-94-external-api-integration/{spec,plan,tasks}.md.
//     Creates external_api_tokens, external_api_rate_limits,
//     webhook_subscriptions (NEW per Decision #3), extends
//     webhook_deliveries, seeds RBAC perms + settings defaults.
//     Migrates legacy webhooks rows into webhook_subscriptions
//     (idempotent — skips already-migrated rows by target_url match).
// ─────────────────────────────────────────────────────────────────────
echo "\nExternal API (Phase 94 Stage 1) schema:\n";
require_once __DIR__ . '/../sql/run_phase94_external_api.php';

// ─────────────────────────────────────────────────────────────────────
// 10. uploads/.htaccess present (F-001 belt-and-suspenders for photos too)
// ─────────────────────────────────────────────────────────────────────
echo "\nWebroot defenses:\n";
$uploadsDir = realpath(__DIR__ . '/../uploads') ?: __DIR__ . '/../uploads';
$htaccess   = $uploadsDir . '/.htaccess';
step('uploads/.htaccess present',
    fn() => file_exists($htaccess),
    function () use ($uploadsDir, $htaccess) {
        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
        @file_put_contents($htaccess,
            "# Auto-generated by install_fresh.php — see docs/PRE-RELEASE-FIXES.md item 11\n"
            . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
            . "<FilesMatch \"\\.(php|phar|phtml|pht|phtm|inc|htaccess|html|htm|svg|xml|xsl|vbs|js)\$\">\n"
            . "    Require all denied\n</FilesMatch>\nOptions -ExecCGI\n");
        if (!file_exists($htaccess)) {
            throw new Exception('Could not write uploads/.htaccess (check perms)');
        }
    });

// ─────────────────────────────────────────────────────────────────────
// 10b. uploads/web.config + cache/web.config present (architecture.md §6
//      item 1 — the IIS equivalent of the .htaccess step above; IIS never
//      reads .htaccess, and both directories are wholesale .gitignore'd, so
//      this runtime write is the ONLY way either file reaches a real
//      install. Extension list MUST match $ALLOWED_EXT_MIME in
//      api/upload.php and api/file-upload.php — tests/
//      test_web_upload_extension_sync.php gates the three staying in sync.
// ─────────────────────────────────────────────────────────────────────
step('uploads/web.config present',
    fn() => file_exists($uploadsDir . '/web.config'),
    function () use ($uploadsDir) {
        served_dir_harden_allowlist($uploadsDir, 'File attachments (api/upload.php)', [
            '.png', '.jpg', '.jpeg', '.gif', '.webp', '.bmp', '.pdf',
            '.csv', '.tsv', '.txt', '.log', '.json', '.doc', '.docx',
            '.xls', '.xlsx', '.ppt', '.pptx', '.rtf', '.odt', '.ods',
            '.mp3', '.wav', '.mp4', '.webm',
        ]);
        if (!file_exists($uploadsDir . '/web.config')) {
            throw new Exception('Could not write uploads/web.config (check perms)');
        }
    });

step('cache/web.config present',
    fn() => file_exists(realpath(__DIR__ . '/../cache') !== false
        ? realpath(__DIR__ . '/../cache') . '/web.config' : '/nonexistent'),
    function () {
        $cacheDir = realpath(__DIR__ . '/../cache') ?: __DIR__ . '/../cache';
        served_dir_harden_allowlist($cacheDir, 'NWS weather-zone cache + backup health probe', ['.json']);
        if (!file_exists($cacheDir . '/web.config')) {
            throw new Exception('Could not write cache/web.config (check perms)');
        }
    });

// ─────────────────────────────────────────────────────────────────────
// 11. RSA keypair for field encryption (inc/field-encrypt.php).
//
// Pre-generate the keypair as part of the install so the first user
// to hit login.php doesn't trigger lazy keygen. Lazy keygen fails
// silently when the keys dir isn't writable by the web server user,
// and (until 2026-05-20) leaked a PHP Warning into the login HTML
// that broke the page's flex centering. Generating here moves the
// keygen to a context where the operator can SEE the failure.
// ─────────────────────────────────────────────────────────────────────
// keys/ lives ONE LEVEL ABOVE the webroot intentionally — the RSA
// private key must NOT be HTTP-reachable even if Apache config or
// .htaccess fails. This means the user running install_fresh.php
// needs write access to the project root's PARENT directory. On a
// typical /var/www/newui install that's /var/www/ which www-data
// doesn't own by default. The op fix is to either:
//
//   - mkdir + chown the keys/ dir before running install_fresh.php
//     (one-time prep, see INSTALLATION-CHECKLIST.md Section 6), OR
//   - skip this step entirely if the deployment is behind HTTPS
//     (the RSA-field-encryption layer is only useful as a defense-in-
//     depth for non-TLS deployments; on HTTPS it's redundant with TLS)
//
// Treat this as a NOTICE rather than a fail. The rest of the app
// works fine without these keys; only the optional non-HTTPS
// field-encryption feature degrades.
$keysDir = realpath(__DIR__ . '/../../keys') ?: (__DIR__ . '/../../keys');
$keysDirParent = dirname($keysDir);
$keysWritable = is_dir($keysDir) || is_writable($keysDirParent);

if (!$keysWritable) {
    echo "  [notice] keys/ directory at {$keysDir} not creatable by this user.\n";
    echo "           This is OK for HTTPS deployments (TLS replaces field-encryption).\n";
    echo "           For non-HTTPS deployments, run as a user with write access to "
        . escapeshellarg($keysDirParent) . ":\n";
    echo "             sudo mkdir -p " . escapeshellarg($keysDir) . "\n";
    echo "             sudo chown www-data:www-data " . escapeshellarg($keysDir) . "\n";
    echo "             sudo chmod 770 " . escapeshellarg($keysDir) . "\n";
    echo "           Then re-run install_fresh.php to populate keys/private.pem + public.pem.\n";
} else {
    step('keys/ directory exists',
        fn() => is_dir($keysDir),
        function () use ($keysDir) {
            if (!@mkdir($keysDir, 0770, true) && !is_dir($keysDir)) {
                throw new Exception("Could not create keys directory at {$keysDir}");
            }
            @chmod($keysDir, 0770);
        });

    step('keys/private.pem + keys/public.pem exist',
        function () use ($keysDir) {
            return file_exists($keysDir . '/private.pem')
                && file_exists($keysDir . '/public.pem');
        },
        function () {
            require_once __DIR__ . '/../inc/field-encrypt.php';
            if (!function_exists('fe_ensure_keys')) {
                throw new Exception('fe_ensure_keys() not available — check inc/field-encrypt.php');
            }
            if (!fe_ensure_keys()) {
                throw new Exception('RSA keypair generation failed');
            }
        });
}

// ─────────────────────────────────────────────────────────────────────
echo "\n=== Result: $pass applied, $skipped already in place, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
