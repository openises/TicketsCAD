<?php
/**
 * Shared SQL-literal extractor for the schema tooling.
 *
 * WHY THIS EXISTS (Phase 125, 2026-07-26)
 * ---------------------------------------
 * tools/schema_audit.php examined each PHP string literal in ISOLATION. But
 * every writer in inc/*-write.php builds its SQL as a concatenation:
 *
 *     "INSERT INTO " . db_table('teams') . "
 *      (`team`, `sub-group`, `mission`, ... )
 *      VALUES (?, ?, ?, ...)"
 *
 * That is three separate tokens. The first has the INSERT verb but no
 * backtick (so the audit skipped it); the third has the column list but no
 * verb (so the audit skipped that too). Net effect: the audit that gates every
 * commit could not see a single one of the 89 writer INSERTs — which is
 * exactly why `teams` was able to INSERT nine columns the table did not have
 * without any gate noticing, and why a beta tester found it instead of CI.
 *
 * This extractor stitches a concatenation chain back into one SQL string and
 * resolves db_table('x') to the bare table name, so the verb and the column
 * list arrive together.
 *
 * Interpolated variables inside double-quoted strings and heredocs are dropped
 * (same as before) UNLESS the variable was assigned a plain string literal or
 * a single db_table('x') call earlier in the SAME file (GH#130 follow-up,
 * rjonesbsink 2026-09-02): a table name built as
 *
 *     $bk = '`in_types_backup_apco`';
 *     $t  = db_table('in_types');
 *     db_query("CREATE TABLE $bk AS SELECT * FROM $t");
 *
 * used to vanish entirely at extraction — $bk/$t are dropped with nothing put
 * in their place, so the CTAS regex in dead_control_audit.php never even sees
 * a table name to match, and the write is invisible. This is the SAME
 * db_table()-style resolution the writer-INSERT case above already relies
 * on, just one assignment hop earlier; anything more complex on the
 * right-hand side (concatenation, a different function call, another
 * variable) is deliberately left unresolved, matching this file's existing
 * false-negative-avoiding posture rather than guessing.
 * Callers normalize the leftover `{$prefix}table` shapes.
 */

declare(strict_types=1);

/**
 * Extract candidate SQL strings from PHP source, stitching concatenations.
 *
 * @return array<int, array{0:int,1:string}>  list of [line, sql]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

function sql_extract_strings(string $src): array
{
    $tokens = @token_get_all($src);
    if (!$tokens) {
        return [];
    }

    $out     = [];
    $buf     = null;   // current stitched expression, or null when not in one
    $bufLine = 0;
    $inDq    = false;  // inside a double-quoted interpolated string
    $inHd    = false;  // inside a heredoc
    $curLine = 1;
    $n       = count($tokens);

    $flush = function () use (&$buf, &$bufLine, &$out) {
        if ($buf !== null && trim($buf) !== '') {
            $out[] = [$bufLine, $buf];
        }
        $buf = null;
    };
    $begin = function (int $line) use (&$buf, &$bufLine) {
        if ($buf === null) { $buf = ''; $bufLine = $line; }
    };

    // GH#130 follow-up: $name => resolved literal string, updated as
    // assignments are scanned forward so a reassignment naturally overrides
    // an earlier one (a whole-file pre-pass could not tell those apart).
    // Cleared at every `function` keyword: PHP functions do not share local
    // scope, and this codebase reuses common names ($prefix, $result, ...)
    // constantly across different functions in the same file. A flat,
    // whole-file map found this the hard way -- inc/chat-bridge.php has an
    // unrelated `$prefix = 'System-managed by...'` (a message description
    // prefix) inside chat_bridge_definitions(), and without this reset its
    // value leaked into a LATER function's `{$prefix}` DB-table-prefix
    // interpolation, corrupting an unrelated, previously-correct extraction.
    // Resetting per function is conservative in the safe direction: at worst
    // it leaves a genuinely resolvable cross-function case unresolved, which
    // is the existing (safe) fallback behavior, never a wrong substitution.
    $varMap = [];
    $ws = function (int $j) use ($tokens, $n): int {
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        return $j;
    };
    // $var = 'literal'; or $var = db_table('literal');  ->  $varMap[$var]
    $tryResolveAssignment = function (int $i) use ($tokens, $n, $ws, &$varMap) {
        $j = $ws($i + 1);
        if (!($j < $n && $tokens[$j] === '=')) { return; }
        $k = $ws($j + 1);
        if ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
            $end = $ws($k + 1);
            if ($end < $n && $tokens[$end] === ';') {
                $varMap[$tokens[$i][1]] = stripcslashes(substr($tokens[$k][1], 1, -1));
            }
            return;
        }
        if ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING
            && strtolower($tokens[$k][1]) === 'db_table') {
            $p = $ws($k + 1);
            if (!($p < $n && $tokens[$p] === '(')) { return; }
            $q = $ws($p + 1);
            if (!($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_CONSTANT_ENCAPSED_STRING)) { return; }
            $r = $ws($q + 1);
            if (!($r < $n && $tokens[$r] === ')')) { return; }
            $end = $ws($r + 1);
            if ($end < $n && $tokens[$end] === ';') {
                $varMap[$tokens[$i][1]] = substr($tokens[$q][1], 1, -1);
            }
        }
    };

    for ($i = 0; $i < $n; $i++) {
        $tk = $tokens[$i];

        if (is_array($tk)) {
            [$id, $text, $ln] = $tk;
            $curLine = $ln;

            switch ($id) {
                case T_FUNCTION:
                    // New local scope starting -- see the $varMap comment
                    // above for why this reset exists.
                    $varMap = [];
                    if (!$inDq && !$inHd) { $flush(); }
                    continue 2;

                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                    continue 2;                       // never breaks a chain

                case T_CONSTANT_ENCAPSED_STRING:
                    $begin($ln);
                    $buf .= stripcslashes(substr($text, 1, -1));
                    continue 2;

                case T_ENCAPSED_AND_WHITESPACE:
                    if ($inDq || $inHd) { $begin($ln); $buf .= $text; continue 2; }
                    $begin($ln);
                    $buf .= stripcslashes($text);
                    continue 2;

                case T_START_HEREDOC:
                    $inHd = true; $begin($ln);
                    continue 2;

                case T_END_HEREDOC:
                    $inHd = false;
                    continue 2;

                case T_VARIABLE:
                case T_CURLY_OPEN:
                case T_STRING_VARNAME:
                case T_OBJECT_OPERATOR:
                case T_NUM_STRING:
                    // Interpolation innards: dropped, but do not break the
                    // chain when we are inside a quoted string / heredoc --
                    // UNLESS this exact variable was resolved to a literal
                    // table name earlier in the file (GH#130 follow-up; see
                    // $varMap above), in which case substitute it instead of
                    // dropping it.
                    if ($inDq || $inHd) {
                        if ($id === T_VARIABLE && isset($varMap[$text])) {
                            $buf .= $varMap[$text];
                        }
                        continue 2;
                    }
                    if ($id === T_VARIABLE) { $tryResolveAssignment($i); }
                    $flush();
                    continue 2;

                case T_STRING:
                    // db_table('x')  ->  x     (the writers' table helper)
                    if ($buf !== null && strtolower($text) === 'db_table') {
                        $j = $i + 1;
                        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
                        if ($j < $n && $tokens[$j] === '(') {
                            $k = $j + 1;
                            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k++; }
                            if ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                                $tbl = substr($tokens[$k][1], 1, -1);
                                $m = $k + 1;
                                while ($m < $n && is_array($tokens[$m]) && $tokens[$m][0] === T_WHITESPACE) { $m++; }
                                if ($m < $n && $tokens[$m] === ')') {
                                    $buf .= $tbl;
                                    $i = $m;          // consume through ')'
                                    continue 2;
                                }
                            }
                        }
                    }
                    $flush();
                    continue 2;

                default:
                    if (!$inDq && !$inHd) { $flush(); }
                    continue 2;
            }
        }

        // Single-character tokens
        if ($tk === '"') {
            $inDq = !$inDq;
            if ($inDq) { $begin($curLine); }
            continue;
        }
        if ($tk === '.') {
            continue;                                  // concatenation: keep going
        }
        if ($inDq || $inHd) {
            continue;
        }
        $flush();
    }
    $flush();

    return $out;
}

/**
 * Normalize a stitched SQL string: drop {$prefix}-style interpolation remnants
 * so `{$prefix}ticket` and `ticket` compare equal.
 */
function sql_extract_normalize(string $sql): string
{
    return (string) preg_replace('/\{\$[A-Za-z_]+\}|\$[A-Za-z_]+(?=\w*`)/', '', $sql);
}

/**
 * Does this string look like real SQL we should inspect?
 */
function sql_extract_is_query(string $sql): bool
{
    if (stripos($sql, 'information_schema') !== false) {
        return false;
    }
    return (bool) preg_match(
        '/\b(SELECT\s+[\s\S]+\s+FROM\s|INSERT\s+(?:IGNORE\s+)?INTO\s|UPDATE\s+\S+\s+SET\s|DELETE\s+FROM\s)/i',
        $sql
    );
}

/**
 * Every table a statement REFERENCES, however it is used.
 *
 * Phase 125b: the written-columns view above only sees tables the code INSERTs
 * into with a literal column list. A table the code only READS is invisible to
 * it — so when a beta tester lost four tables to crash recovery, the ones he
 * only read from could be dropped without anything noticing. Table existence is
 * checkable even when column coverage is not, so collect it separately.
 *
 * @return array<int, string>
 */
function sql_extract_referenced_tables(string $sql): array
{
    $norm  = sql_extract_normalize($sql);
    $found = [];
    $patterns = [
        '/\bFROM\s+`?([a-z0-9_]+)`?/i',
        '/\bJOIN\s+`?([a-z0-9_]+)`?/i',
        '/\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z0-9_]+)`?/i',
        '/\bUPDATE\s+`?([a-z0-9_]+)`?\s+SET\b/i',
        '/\bDELETE\s+FROM\s+`?([a-z0-9_]+)`?/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $norm, $m)) {
            foreach ($m[1] as $t) {
                $t = strtolower($t);
                // `FROM (` subqueries and SQL keywords are not tables.
                if ($t !== '' && !in_array($t, ['select', 'dual', 'values'], true)) {
                    $found[] = $t;
                }
            }
        }
    }
    return array_values(array_unique($found));
}

/**
 * Columns a statement WRITES to: INSERT column lists and UPDATE SET targets.
 * These are the columns whose absence breaks a save.
 *
 * @return array<string, array<int, string>>  table => [column, ...]
 */
function sql_extract_written_columns(string $sql): array
{
    $norm = sql_extract_normalize($sql);
    $found = [];

    // INSERT INTO t (a, b, c)
    if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z0-9_]+)`?\s*\(([^)]+)\)/i', $norm, $mi, PREG_SET_ORDER)) {
        foreach ($mi as $m) {
            $tbl = strtolower($m[1]);
            foreach (explode(',', $m[2]) as $col) {
                $col = strtolower(trim(trim($col), '` '));
                if ($col !== '' && preg_match('/^[a-z0-9_-]+$/', $col)) {
                    $found[$tbl][] = $col;
                }
            }
        }
    }

    // UPDATE t SET `a` = ?, `b` = ?   (backticked targets only — conservative)
    if (preg_match_all('/UPDATE\s+`?([a-z0-9_]+)`?\s+SET\s+([\s\S]+?)(?:\bWHERE\b|$)/i', $norm, $mu, PREG_SET_ORDER)) {
        foreach ($mu as $m) {
            $tbl = strtolower($m[1]);
            if (preg_match_all('/`([a-z0-9_-]+)`\s*=/', $m[2], $mc)) {
                foreach ($mc[1] as $col) {
                    $found[$tbl][] = strtolower($col);
                }
            }
        }
    }

    foreach ($found as $t => $cols) {
        $found[$t] = array_values(array_unique($cols));
    }
    return $found;
}
