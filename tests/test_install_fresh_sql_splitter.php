<?php
/**
 * Regression test for the Windows PDO-import SQL splitter in
 * tools/install_fresh.php (splitSqlStatements() / stripInlineSqlComment()).
 *
 * The naive line-buffer splitter used to flush a statement whenever the
 * accumulated buffer's LAST characters matched the delimiter (';'), with no
 * awareness of SQL comments. sql/rbac.sql's Dispatcher role_permissions
 * NOT IN(...) exclusion list has several `-- ...` comments that themselves
 * happen to end in a semicolon on their own physical line (e.g. "...(roles
 * 1-2, 2026-07-29);"), which prematurely terminated the statement mid-list
 * on the Windows-only PDO import path — producing two malformed SQL
 * fragments that both failed with a 1064 syntax error. The Unix path (which
 * shells out to a real mariadb/mysql client) was never affected, since a
 * real SQL parser ignores comments correctly.
 *
 * This test proves both halves: (1) the OLD naive algorithm really did
 * split the fixture incorrectly (so this isn't testing a strawman), and
 * (2) the CURRENT splitSqlStatements() — the function tools/install_fresh.php
 * actually calls — produces exactly one, complete, executable statement.
 *
 * Usage: php tests/test_install_fresh_sql_splitter.php
 */

require_once __DIR__ . '/../inc/sql-splitter.php';

$pass = 0;
$fail = 0;
function check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] {$label}\n";
        $pass++;
    } else {
        echo "[FAIL] {$label}\n";
        $fail++;
    }
}

echo "=== install_fresh.php SQL splitter regression ===\n\n";

// Fixture: the actual pattern from sql/rbac.sql (2026-08-18) — a NOT IN(...)
// list whose entries carry inline comments, one of which ends in ';'.
$fixture = <<<'SQL'
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
    SELECT 3, `id` FROM `permissions`
    WHERE `code` NOT IN (
        'action.manage_config',        -- system configuration is admin-only
        'action.view_reports',         -- org-wide aggregate reports are admin-only (roles 1-2, 2026-07-29);
                                       -- grant per-role via the Roles UI if your dispatchers need them
        'action.manage_dispositions'   -- managing the incident-disposition list is admin-only
    );
SQL;

// --- Part 1: prove the OLD naive algorithm really was broken on this input ---
// (a faithful reproduction of the pre-fix loop body, kept ONLY to demonstrate
// the bug is real — not the code under test).
function naiveSplitPreFix(string $sql): array
{
    $delim = ';';
    $buf = '';
    $statements = [];
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trim = trim($line);
        if ($buf === '' && ($trim === '' || strpos($trim, '--') === 0 || $trim[0] === '#')) {
            continue;
        }
        $buf .= $line . "\n";
        if (substr(rtrim($buf), -strlen($delim)) === $delim) {
            $stmt = trim(substr(rtrim($buf), 0, -strlen($delim)));
            $buf = '';
            if ($stmt !== '') $statements[] = $stmt;
        }
    }
    return $statements;
}

$naiveResult = naiveSplitPreFix($fixture);
check(
    count($naiveResult) > 1,
    'sanity check: the OLD naive algorithm really does mis-split this fixture (found '
        . count($naiveResult) . ' fragment(s), expected >1 — if this fails, the fixture no longer reproduces the bug)'
);
if (count($naiveResult) > 1) {
    check(
        stripos($naiveResult[0], 'NOT IN') !== false && stripos($naiveResult[0], "'action.manage_dispositions'") === false,
        'OLD algorithm: first fragment is a truncated, syntactically invalid NOT IN(...) list'
    );
}

// --- Part 2: prove the CURRENT (fixed) function handles it correctly ---
$fixedResult = splitSqlStatements($fixture);
check(count($fixedResult) === 1, 'splitSqlStatements() returns exactly ONE statement for the fixture (got ' . count($fixedResult) . ')');
if (count($fixedResult) === 1) {
    $stmt = $fixedResult[0];
    check(stripos($stmt, "'action.manage_config'") !== false, 'statement contains the first NOT IN entry');
    check(stripos($stmt, "'action.view_reports'") !== false, 'statement contains the entry with the semicolon-ending comment');
    check(stripos($stmt, "'action.manage_dispositions'") !== false, 'statement contains the LAST NOT IN entry (proves no premature truncation)');
    check(substr(rtrim($stmt), -1) !== ';', 'the delimiter itself is stripped from the returned statement, not left dangling');
}

// --- Part 3: comment-stripping helper, direct unit checks ---
check(
    stripInlineSqlComment("        'action.view_reports',         -- ...(roles 1-2, 2026-07-29);") === "        'action.view_reports',         ",
    'stripInlineSqlComment() removes a trailing comment even when the comment text ends in a semicolon'
);
check(
    stripInlineSqlComment("SELECT 1;") === "SELECT 1;",
    'stripInlineSqlComment() leaves a line with no comment unchanged'
);
check(
    stripInlineSqlComment("SELECT '--not a comment--' AS x;") === "SELECT '--not a comment--' AS x;",
    'stripInlineSqlComment() does not treat -- inside a string literal as a comment start'
);
check(
    stripInlineSqlComment("SELECT 'it''s here' -- trailing") === "SELECT 'it''s here' ",
    "stripInlineSqlComment() correctly walks past an escaped '' quote inside a string before finding the real comment"
);

// --- Part 4: a normal multi-statement file still splits correctly (no regression) ---
$normal = "CREATE TABLE t (id INT);\nINSERT INTO t VALUES (1);\n-- a full-line comment\nSELECT * FROM t;\n";
$normalResult = splitSqlStatements($normal);
check(count($normalResult) === 3, 'ordinary 3-statement file (with a full-line comment between statements) still splits into exactly 3 (got ' . count($normalResult) . ')');

// --- Part 5: DELIMITER handling still works (trigger/procedure definitions) ---
$withDelimiter = "DELIMITER //\nCREATE TRIGGER t1 BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; END//\nDELIMITER ;\nSELECT 1;\n";
$delimResult = splitSqlStatements($withDelimiter);
check(count($delimResult) === 2, 'DELIMITER // ... // block still parses as one statement, plus the trailing SELECT (got ' . count($delimResult) . ')');
if (count($delimResult) === 2) {
    check(stripos($delimResult[0], 'CREATE TRIGGER') !== false, 'first statement is the full trigger body');
    check(trim($delimResult[1]) === 'SELECT 1', 'second statement is the post-DELIMITER SELECT');
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
