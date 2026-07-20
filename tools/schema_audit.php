<?php
/**
 * Schema audit — find SQL column/table references that don't exist
 * (Eric 2026-07-07, after GH #71's member.username / constituents.name
 * class of bug shipped repeatedly).
 *
 * The failure pattern this hunts: NewUI's database is a HYBRID of
 * legacy v3.44 tables (30-year-old naming: status_val, contact,
 * capt/repl, `group`) and new tables (modern naming: name, code,
 * label). Code written assuming modern names on legacy tables fails
 * on EVERY install, but only when a user finally exercises that path.
 *
 * What it does:
 *   1. Loads the live schema (tables + columns) from information_schema.
 *   2. Scans PHP under api/, inc/, and the page roots for SQL strings.
 *   3. Resolves `{$prefix}table` aliases from FROM/JOIN clauses.
 *   4. Validates alias-qualified column references (`a`.`col`, a.col)
 *      and INSERT INTO ... (col, ...) column lists against the schema.
 *   5. Reports every reference to a table or column that doesn't exist.
 *
 * Deliberately conservative: only alias-qualified references and INSERT
 * column lists are checked (bare column names in WHERE clauses are too
 * ambiguous to attribute to a table without a real SQL parser). That
 * still catches the entire GH #71 class.
 *
 * Exit code: 0 = clean (or only baseline-listed findings), 1 = new
 * findings. Baseline lives in tools/schema_audit_baseline.txt — one
 * finding key per line; remove entries as they're fixed.
 *
 * Usage:
 *   php tools/schema_audit.php            # report + exit code
 *   php tools/schema_audit.php --all      # include baseline-listed findings
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$showAll = in_array('--all', $argv ?? [], true);

// ── 1. Live schema map ────────────────────────────────────────────────────
$schema = [];   // table => [col => true]
foreach (db_fetch_all(
    "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()"
) as $r) {
    $schema[strtolower($r['TABLE_NAME'])][strtolower($r['COLUMN_NAME'])] = true;
}
echo count($schema) . " tables loaded from live schema\n";

// ── 2. Collect PHP files ─────────────────────────────────────────────────
$files = [];
foreach (['api', 'inc'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
}
foreach (glob('*.php') as $f) { $files[] = $f; }
echo count($files) . " PHP files scanned\n";

// ── 3/4. Extract + validate ──────────────────────────────────────────────
$findings = [];   // key => [file, line, message]

/**
 * Given one SQL string, return findings. $schema/$prefix via globals.
 */
function audit_sql($sql, $file, $line, array &$findings)
{
    global $schema, $prefix;

    // Normalize: strip {$prefix} / {$p} / $prefix interpolations.
    $norm = preg_replace('/\{\$[A-Za-z_]+\}|\$[A-Za-z_]+(?=\w*`)/', '', $sql);
    $norm = preg_replace('/\s+/', ' ', $norm);

    // Map aliases: FROM/JOIN `table` [AS] alias  (alias may be backticked)
    $aliases = [];   // alias => table
    if (preg_match_all(
        '/(?:FROM|JOIN)\s+`?([a-z0-9_]+)`?\s+(?:AS\s+)?`?([a-z0-9_]+)`?/i',
        $norm, $mm, PREG_SET_ORDER
    )) {
        foreach ($mm as $m) {
            $tbl = strtolower($m[1]);
            $ali = strtolower($m[2]);
            // Skip SQL keywords captured as "alias"
            if (in_array($ali, ['on', 'set', 'where', 'left', 'right', 'inner',
                'outer', 'join', 'group', 'order', 'limit', 'using', 'cross',
                'straight_join', 'values', 'select'], true)) {
                $ali = $tbl; // self-alias
            }
            $aliases[$ali] = $tbl;
        }
    }
    // Tables referenced without alias also map to themselves.
    // UPDATE requires a following SET (so "ON DUPLICATE KEY UPDATE col="
    // doesn't read as UPDATE <table>); INSERT INTO requires the column
    // paren or VALUES to follow.
    if (preg_match_all('/(?:FROM|JOIN)\s+`?([a-z0-9_]+)`?/i', $norm, $mm2)) {
        foreach ($mm2[1] as $tbl) {
            $tbl = strtolower($tbl);
            if (!isset($aliases[$tbl])) { $aliases[$tbl] = $tbl; }
        }
    }
    if (preg_match_all('/(?<!KEY )UPDATE\s+`?([a-z0-9_]+)`?\s+SET\b/i', $norm, $mm3)) {
        foreach ($mm3[1] as $tbl) {
            $tbl = strtolower($tbl);
            if (!isset($aliases[$tbl])) { $aliases[$tbl] = $tbl; }
        }
    }
    if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z0-9_]+)`?\s*(?:\(|VALUES)/i', $norm, $mm4)) {
        foreach ($mm4[1] as $tbl) {
            $tbl = strtolower($tbl);
            if (!isset($aliases[$tbl])) { $aliases[$tbl] = $tbl; }
        }
    }

    // Check table existence. Blacklist SQL keywords + English articles the
    // regex can capture from subqueries/prose fragments.
    static $notTables = null;
    if ($notTables === null) {
        $notTables = array_flip(['information_schema', 'columns', 'tables',
            'dual', 'select', 'as', 'on', 'set', 'where', 'values', 'not',
            'null', 'if', 'exists', 'duplicate', 'key', 'unique', 'index',
            'current_timestamp', 'now', 'a', 'an', 'the', 'this', 'each',
            'all', 'any', 'one', 'your', 'their', 'count', 'sum', 'max',
            'min', 'avg', 'coalesce', 'concat', 'distinct', 'temporary',
            'order', 'limit', 'group', 'left', 'right', 'inner', 'union',
            'having', 'between', 'like', 'into', 'from', 'ignore']);
    }
    foreach (array_unique(array_values($aliases)) as $tbl) {
        if (isset($notTables[$tbl]) || strlen($tbl) < 3) { continue; }
        if (!isset($schema[$tbl])) {
            $key = "table:$tbl";
            $findings[$key][] = [$file, $line, "table `$tbl` not in schema"];
        }
    }

    // Alias-qualified column references: `a`.`col` or a.col
    if (preg_match_all('/`?([a-z0-9_]+)`?\.`([a-z0-9_]+)`|\b([a-z0-9_]+)\.([a-z0-9_]+)\b/i', $norm, $mc, PREG_SET_ORDER)) {
        foreach ($mc as $m) {
            $ali = strtolower($m[1] !== '' ? $m[1] : ($m[3] ?? ''));
            $col = strtolower($m[1] !== '' ? $m[2] : ($m[4] ?? ''));
            if ($ali === '' || $col === '') { continue; }
            if (!isset($aliases[$ali])) { continue; }             // not a table alias (php var, decimal, etc.)
            $tbl = $aliases[$ali];
            if (!isset($schema[$tbl])) { continue; }              // already reported as missing table
            if ($col === '*') { continue; }
            if (!isset($schema[$tbl][$col])) {
                $key = "col:$tbl.$col";
                $findings[$key][] = [$file, $line, "column `$tbl`.`$col` not in schema"];
            }
        }
    }

    // INSERT INTO table (col, col, ...) column lists
    if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z0-9_]+)`?\s*\(([^)]+)\)/i', $norm, $mi, PREG_SET_ORDER)) {
        foreach ($mi as $m) {
            $tbl = strtolower($m[1]);
            if (!isset($schema[$tbl])) { continue; }
            foreach (explode(',', $m[2]) as $col) {
                $col = strtolower(trim(trim($col), '` '));
                if ($col === '' || !preg_match('/^[a-z0-9_]+$/', $col)) { continue; }
                if (!isset($schema[$tbl][$col])) {
                    $key = "col:$tbl.$col";
                    $findings[$key][] = [$file, $line, "INSERT column `$tbl`.`$col` not in schema"];
                }
            }
        }
    }
}

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) { continue; }
    // Tokenize so comments (with apostrophes) can't masquerade as strings
    // and line numbers are exact. Interpolated variables inside double-
    // quoted strings/heredocs are dropped — the normalizer tolerates the
    // resulting `table` shapes.
    $tokens = @token_get_all($src);
    if (!$tokens) { continue; }
    $strings = [];   // [line, text]
    $buf = null; $bufLine = 0; $curLine = 1;
    foreach ($tokens as $tk) {
        if (is_array($tk)) {
            [$id, $text, $ln] = $tk;
            $curLine = $ln;
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = [$ln, stripcslashes(substr($text, 1, -1))];
            } elseif ($id === T_START_HEREDOC) {
                $buf = ''; $bufLine = $ln;
            } elseif ($id === T_END_HEREDOC) {
                if ($buf !== null) { $strings[] = [$bufLine, $buf]; }
                $buf = null;
            } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
                if ($buf !== null) { $buf .= $text; }
                else { $strings[] = [$ln, stripcslashes($text)]; }
            }
            // T_VARIABLE / T_CURLY_OPEN etc. inside interpolation: dropped.
        } elseif ($tk === '"') {
            // Toggle double-quoted interpolated string accumulation.
            if ($buf === null) { $buf = ''; $bufLine = $curLine; }
            else { if ($buf !== '') { $strings[] = [$bufLine, $buf]; } $buf = null; }
        }
    }
    foreach ($strings as [$line, $str]) {
        if ($str === '') { continue; }
        // Real SQL only — needs a query verb+shape AND a backtick (every
        // real query in this codebase backticks its {$prefix}table).
        if (!preg_match('/\b(SELECT\s+[\s\S]+\s+FROM\s|INSERT\s+(?:IGNORE\s+)?INTO\s|UPDATE\s+\S+\s+SET\s|DELETE\s+FROM\s)/i', $str)) { continue; }
        if (strpos($str, '`') === false) { continue; }
        if (stripos($str, 'information_schema') !== false) { continue; }
        audit_sql($str, $file, $line, $findings);
    }
}

// ── 5. Report ─────────────────────────────────────────────────────────────
$baselineFile = __DIR__ . '/schema_audit_baseline.txt';
$baseline = is_file($baselineFile)
    ? array_filter(array_map('trim', file($baselineFile)))
    : [];

ksort($findings);
$newCount = 0;
foreach ($findings as $key => $sites) {
    $inBaseline = in_array($key, $baseline, true);
    if ($inBaseline && !$showAll) { continue; }
    if (!$inBaseline) { $newCount++; }
    echo ($inBaseline ? '[baseline] ' : '[NEW]      ') . $key . "\n";
    foreach (array_slice($sites, 0, 5) as [$f, $l, $msg]) {
        echo "             $f:$l — $msg\n";
    }
    if (count($sites) > 5) { echo '             … +' . (count($sites) - 5) . " more sites\n"; }
}

echo "\n" . count($findings) . " distinct finding(s), $newCount new (not in baseline)\n";
exit($newCount === 0 ? 0 : 1);
