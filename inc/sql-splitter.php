<?php
/**
 * Pure, side-effect-free SQL statement splitting used by the Windows PDO
 * fresh-install import path (tools/install_fresh.php), which has no working
 * mariadb/mysql CLI to shell out to and must therefore parse multi-statement
 * .sql files itself. Split into its own file (rather than defined inline in
 * install_fresh.php, a top-level script that runs a full install as soon as
 * it's required) so it can be unit-tested directly —
 * see tests/test_install_fresh_sql_splitter.php.
 */

/**
 * Strips a trailing `-- comment` from one line of SQL, respecting single-
 * quoted string literals (so a `--` inside a string is never mistaken for a
 * comment start). Used only to decide whether a line's REAL content ends in
 * the statement delimiter -- the original line (comment included) is still
 * what gets sent to the database, since MariaDB/MySQL ignore SQL comments
 * harmlessly. Without this, a `-- ...` comment that happens to end in a
 * semicolon on its own physical line (e.g. sql/rbac.sql's Dispatcher
 * role_permissions NOT IN(...) list: "..., -- ...(roles 1-2, 2026-07-29);")
 * prematurely terminates the statement mid-way through the list on the
 * Windows PDO import path, producing two malformed SQL fragments that both
 * fail with a 1064 syntax error. Confirmed via a standalone reproduction
 * against sql/rbac.sql, 2026-08-17/18.
 */
function stripInlineSqlComment(string $line): string
{
    $inString = false;
    $len = strlen($line);
    for ($i = 0; $i < $len; $i++) {
        $ch = $line[$i];
        if ($inString) {
            if ($ch === '\\' && $i + 1 < $len) {
                $i++; // backslash-escaped char inside a string literal
                continue;
            }
            if ($ch === "'") {
                if ($i + 1 < $len && $line[$i + 1] === "'") {
                    $i++; // '' -- escaped quote inside a string literal
                    continue;
                }
                $inString = false;
            }
            continue;
        }
        if ($ch === "'") {
            $inString = true;
            continue;
        }
        if ($ch === '-' && $i + 1 < $len && $line[$i + 1] === '-'
            && ($i + 2 >= $len || $line[$i + 2] === ' ' || $line[$i + 2] === "\t")) {
            return substr($line, 0, $i);
        }
    }
    return $line;
}

/**
 * Splits raw multi-statement SQL text into individual statement strings,
 * honoring DELIMITER changes (for trigger/procedure definitions) and
 * treating `-- comment` / `# comment` text as inert for the purpose of
 * finding each statement's real terminator (see stripInlineSqlComment()).
 */
function splitSqlStatements(string $sql): array
{
    $delim = ';';
    $buf = '';
    $statements = [];
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trim = trim($line);
        if ($buf === '' && ($trim === '' || strpos($trim, '--') === 0 || $trim[0] === '#')) {
            continue; // comment/blank between statements
        }
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trim, $m)) {
            $delim = $m[1];
            continue;
        }
        $buf .= $line . "\n";
        // rtrim($buf) always resolves to this line's own tail (blank/
        // comment-only lines contribute only whitespace, which rtrim
        // discards), so checking the comment-stripped CURRENT line is
        // sufficient -- no need to re-scan the whole accumulated buffer.
        $lineNoComment = rtrim(stripInlineSqlComment($line));
        if (substr($lineNoComment, -strlen($delim)) === $delim
            && substr(rtrim($buf), -strlen($delim)) === $delim) {
            $stmt = trim(substr(rtrim($buf), 0, -strlen($delim)));
            $buf = '';
            if ($stmt !== '') $statements[] = $stmt;
        }
    }
    return $statements;
}
