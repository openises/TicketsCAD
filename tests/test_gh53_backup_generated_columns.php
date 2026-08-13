<?php
/**
 * GitHub #53 (rjonesbsink, 2026-08-12) — a table with a GENERATED column made
 * every automatic backup on the reporter's install unrestorable, silently.
 * backup_dump_sql() named columns from SHOW COLUMNS (which lists generated
 * columns) but read row values from `SELECT *` (which MySQL 8 excludes an
 * INVISIBLE generated column from, while still including a visible one it
 * will not accept back via INSERT). Two distinct failures fell out of that
 * single mismatch: a column-count error for an invisible generated column
 * (SQLSTATE 21S01, e.g. user_roles.scope_key), and a rejected-value error for
 * a visible one (error 3105, e.g. teams.name). backup_dump_sql() reported
 * success and produced a plausible file either way.
 *
 * This drives the real function against real scratch tables shaped like both
 * cases, then actually EXECUTES the dumped CREATE + INSERT against a fresh
 * copy of each table -- proving restorability, not just inspecting the SQL.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup.php';

echo "=== GH#53 — backup dump excludes GENERATED columns ===\n\n";
$pass = 0; $fail = 0; $skip = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function skip($n, $why) { global $skip; echo "SKIP: $n — $why\n"; $skip++; }

$pdo = db();
$prefix = $GLOBALS['db_prefix'] ?? '';

$tInvisible = $prefix . 'gh53_test_invisible';
$tVisible   = $prefix . 'gh53_test_visible';

function gh53_cleanup(PDO $pdo, array $tables): void {
    foreach ($tables as $t) {
        try { $pdo->exec("DROP TABLE IF EXISTS `{$t}`"); } catch (Throwable $e) {}
    }
}
gh53_cleanup($pdo, [$tInvisible, $tVisible]);

try {
    // Case 1: STORED GENERATED INVISIBLE -- the user_roles.scope_key shape.
    $pdo->exec("CREATE TABLE `{$tInvisible}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `a` INT NOT NULL,
        `b` INT NOT NULL,
        `computed` INT GENERATED ALWAYS AS (`a` + `b`) STORED INVISIBLE,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO `{$tInvisible}` (`a`,`b`) VALUES (2,3)");

    // Case 2: VIRTUAL GENERATED, visible -- the teams.name shape.
    $pdo->exec("CREATE TABLE `{$tVisible}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `first` VARCHAR(20) NOT NULL,
        `last` VARCHAR(20) NOT NULL,
        `full_name` VARCHAR(41) GENERATED ALWAYS AS (CONCAT(`first`,' ',`last`)) VIRTUAL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO `{$tVisible}` (`first`,`last`) VALUES ('Jane','Doe')");
} catch (Throwable $e) {
    skip('GH#53 generated-column dump', 'could not create scratch tables: ' . $e->getMessage());
    gh53_cleanup($pdo, [$tInvisible, $tVisible]);
    echo "\n$pass passed, $fail failed, $skip skipped\n";
    exit(0);
}

$outFile = sys_get_temp_dir() . '/gh53_backup_test_' . getmypid() . '.sql';
try {
    $ok = backup_dump_sql($outFile);
    if (!$ok) { bad('backup_dump_sql() returned false'); }
    $sql = file_exists($outFile) ? file_get_contents($outFile) : '';
    if ($sql === '') {
        bad('backup_dump_sql() produced no output');
    } else {
        ok('backup_dump_sql() ran and produced a file');
    }

    foreach ([
        ['table' => $tInvisible, 'generated' => 'computed', 'label' => 'STORED GENERATED INVISIBLE'],
        ['table' => $tVisible,   'generated' => 'full_name', 'label' => 'VIRTUAL GENERATED'],
    ] as $case) {
        $t = $case['table'];
        // Isolate this table's dumped section.
        if (!preg_match('/-- Table: `' . preg_quote($t, '/') . '`.*?(?=-- Table: `|\z)/s', $sql, $m)) {
            bad("{$case['label']}: table section not found in dump");
            continue;
        }
        $section = $m[0];

        if (strpos($section, "`{$case['generated']}`") !== false
            && preg_match('/INSERT INTO.*\(([^)]*)\)\s*VALUES/s', $section, $insMatch)
            && strpos($insMatch[1], "`{$case['generated']}`") !== false) {
            bad("{$case['label']}: generated column '{$case['generated']}' still appears in the INSERT column list");
        } else {
            ok("{$case['label']}: generated column '{$case['generated']}' excluded from the INSERT");
        }

        // The strongest proof: actually restore it. Drop the real scratch
        // table and re-run exactly the CREATE + INSERT the dump produced.
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
        try {
            foreach (array_filter(array_map('trim', explode(";\n", $section))) as $stmt) {
                if ($stmt === '' || strpos($stmt, '--') === 0) continue;
                $pdo->exec($stmt . ';');
            }
            $restoredValue = $pdo->query("SELECT `{$case['generated']}` FROM `{$t}` LIMIT 1")->fetchColumn();
            ok("{$case['label']}: dump round-trips through DROP+restore (computed value: {$restoredValue})");
        } catch (Throwable $e) {
            bad("{$case['label']}: restoring the dumped SQL threw", $e->getMessage());
        }
    }
} finally {
    @unlink($outFile);
    gh53_cleanup($pdo, [$tInvisible, $tVisible]);
}

echo "\n$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail > 0 ? 1 : 0);
