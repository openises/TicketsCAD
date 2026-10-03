<?php
/**
 * A backup must restore every VALUE as it went in -- and its restore drill must be able to tell.
 *
 * Phase 155. backup_dump_sql() decided how to write a cell by looking at the cell: anything that
 * is_numeric() and did not begin with "0" or contain "+" went into the dump BARE. Reproduced
 * through the real dump and the real restore (backup_apply_sql), with nothing hand-seeded:
 *
 *     VARCHAR/CHAR/TEXT  '1e5' -> 100000      ' 7 ' -> 7       '-0' -> 0      '.5' -> 0.5
 *                        '111...1e5' -> 1.111111111111111e70
 *     ENUM('0','1','2')  '1' -> '0'           (an unquoted number is an ENUM INDEX)
 *     DOUBLE             3.141592653589793 -> 3.1415926535898   (PHP's `precision` ini, 14)
 *     TIMESTAMP          every value shifted by the server's UTC offset (the header says UTC, the
 *                        connection that read the values used the server's zone)
 *     restore onto an existing database    failed on EVERY table: backup_apply_sql() discarded any
 *                        statement chunk that began with a comment, and the DROP TABLE sits under one
 *
 * and the restore DRILL passed all of it, because it counted statements and rows.
 *
 *   PART A  pure: storage classes, the SQL literal matrix, the fingerprint's algebra, comment stripping
 *   PART B  the REAL round trip: a throwaway table covering every storage class and every awkward
 *           value from the report -> backup_dump_sql() -> the dump's own header + that table's section
 *           -> backup_apply_sql() into a scratch database -> every cell compared as bytes
 *   PART C  negative controls: the OLD rule demonstrably corrupts these values, and the fingerprint
 *           check catches a restore that does (so the drill would have failed on the old dump)
 *   PART D  restore over an existing table
 *   PART E  the real drill, end to end: a clean dump passes with content verified; a dump poisoned
 *           the old way FAILS the drill
 *   PART F  tools/restore.php --verify
 *
 * Parts B-F need a database user allowed to CREATE DATABASE (a drill does too); without one they
 * print SKIP and Part A still runs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup_schedule.php';
require_once __DIR__ . '/_p155_helpers.php';

$pass = 0; $fail = 0;
function bf_ok(string $what, bool $cond, string $why = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] {$what}\n"; }
    else       { $fail++; echo "[FAIL] {$what}" . ($why !== '' ? " -- {$why}" : '') . "\n"; }
}

echo "=== Backup value fidelity ===\n\n";
$quote = static function (string $s): string { return "'" . addcslashes($s, "\\'\0\n\r\x1a") . "'"; };

// ── PART A ─────────────────────────────────────────────────────────────────────
echo "-- Part A: storage classes, literals, fingerprints --\n";
$kinds = [
    'int(11)' => 'int', 'tinyint(1)' => 'int', 'bigint(20) unsigned' => 'int', 'smallint(5) unsigned zerofill' => 'int',
    'decimal(10,2)' => 'decimal', 'numeric(5,0)' => 'decimal',
    'double' => 'float', 'float' => 'float', 'float(7,4)' => 'float', 'double precision' => 'float', 'real' => 'float',
    'bit(1)' => 'bit', 'blob' => 'binary', 'longblob' => 'binary', 'binary(16)' => 'binary', 'varbinary(255)' => 'binary',
    'varchar(255)' => 'text', 'char(64)' => 'text', 'text' => 'text', 'longtext' => 'text', 'enum(\'0\',\'1\')' => 'text',
    'set(\'a\',\'b\')' => 'text', 'datetime' => 'text', 'timestamp' => 'text', 'date' => 'text', 'time' => 'text',
    'year(4)' => 'text', 'json' => 'text', 'tinytext' => 'text',
];
foreach ($kinds as $type => $want) {
    bf_ok("column type '{$type}' is storage class '{$want}'", backup_column_kind($type) === $want, backup_column_kind($type));
}
// A text column's content NEVER decides: every one of these is quoted.
foreach (['1e5', ' 7 ', '-0', '.5', '+5', '0123', '0x1F', '12e45678', str_repeat('9', 90), '1_000', 'NaN', '1.50', '007'] as $v) {
    bf_ok("text value " . var_export($v, true) . " is always quoted", backup_sql_literal($v, 'text', $quote) === "'" . $v . "'", backup_sql_literal($v, 'text', $quote));
}
bf_ok('an int is bare', backup_sql_literal(42, 'int', $quote) === '42');
bf_ok('an int that is not an integer (cannot happen, but never emit it bare) is quoted', backup_sql_literal('4e2', 'int', $quote) === "'4e2'");
bf_ok('a BIGINT UNSIGNED max stays exact', backup_sql_literal('18446744073709551615', 'int', $quote) === '18446744073709551615');
bf_ok('a DECIMAL keeps every digit', backup_sql_literal('12345678901234567890.12345678901234567890', 'decimal', $quote) === '12345678901234567890.12345678901234567890');
bf_ok('NULL is NULL in every class', array_unique(array_map(static fn($k) => backup_sql_literal(null, $k, $quote), ['int', 'decimal', 'float', 'bit', 'binary', 'text'])) === ['NULL']);
bf_ok('binary is hex, empty binary is an empty string', backup_sql_literal("a\0b", 'binary', $quote) === '0x610062' && backup_sql_literal('', 'binary', $quote) === "''");
bf_ok('a BIT value given as an int is a bit literal', backup_sql_literal(5, 'bit', $quote) === "b'101'");
bf_ok('a BIT value given as bytes is hex', backup_sql_literal("\x05", 'bit', $quote) === '0x05');
foreach ([3.141592653589793, 1 / 3, 0.1, 1.7976931348623157e308, -2.2250738585072014e-308, 1.0e25, 123456789.0, 5e-324, 0.30000000000000004] as $f) {
    $t = backup_float_text($f);
    bf_ok("float {$t} reads back as exactly the same double (whatever php.ini precision is)",
          (float) $t === $f && backup_sql_literal($f, 'float', $quote) === $t, $t);
}
$oldPrecision = ini_get('precision');
ini_set('precision', '14');
bf_ok('with php.ini precision=14 (the stock value) pi still keeps every digit', backup_float_text(3.141592653589793) === '3.141592653589793');
ini_set('precision', (string) $oldPrecision);
bf_ok('a float arriving as the server\'s text is kept exact too', backup_sql_literal('3.141592653589793', 'float', $quote) === '3.141592653589793');

// The fingerprint.
$fpOf = static function (array $rows, array $k): string {
    $fp = backup_fp_new();
    foreach ($rows as $r) { backup_fp_add($fp, $r, $k); }
    return backup_fp_finish($fp);
};
$k2 = ['int', 'text'];
$base = [[1, 'a'], [2, 'b'], [3, 'c']];
bf_ok('the fingerprint does not depend on row order', $fpOf($base, $k2) === $fpOf([[3, 'c'], [1, 'a'], [2, 'b']], $k2));
bf_ok('two identical rows do not cancel out (a dropped pair is detected)', $fpOf([[1, 'a'], [1, 'a']], $k2) !== $fpOf([], $k2) && $fpOf([[1, 'a'], [1, 'a'], [2, 'b']], $k2) !== $fpOf([[2, 'b']], $k2));
bf_ok('one changed character changes it', $fpOf($base, $k2) !== $fpOf([[1, 'a'], [2, 'b'], [3, 'd']], $k2));
bf_ok('1e5 and 100000 are different values', $fpOf([[1, '1e5']], $k2) !== $fpOf([[1, '100000']], $k2));
bf_ok('" 7 " and "7" are different values', $fpOf([[1, ' 7 ']], $k2) !== $fpOf([[1, '7']], $k2));
bf_ok('NULL and the empty string are different values', $fpOf([[1, null]], $k2) !== $fpOf([[1, '']], $k2));
bf_ok('a missing row is detected', $fpOf($base, $k2) !== $fpOf(array_slice($base, 0, 2), $k2));
bf_ok('it does not depend on whether PDO typed a number as int or string', $fpOf([[7, 'x']], $k2) === $fpOf([['7', 'x']], $k2));
bf_ok('it does not depend on whether a float arrived as a float or as text', $fpOf([[0.1]], ['float']) === $fpOf([['0.1']], ['float']));
bf_ok('a BIT cell is the same as an int or as bytes', $fpOf([[5]], ['bit']) === $fpOf([["\x05"]], ['bit']));
$digestLines = "-- Digest: alpha rows=3 sha256=" . str_repeat('a', 64) . "\n-- Digest: beta_2 rows=0 sha256=" . str_repeat('0', 64) . "\r\nnoise\n";
$pd = backup_parse_digests($digestLines);
bf_ok('digest lines are parsed (LF and CRLF), noise ignored', count($pd) === 2 && $pd['alpha']['rows'] === 3 && $pd['beta_2']['sha256'] === str_repeat('0', 64));
bf_ok('a dump with no digest lines (a pre-Phase-155 backup) yields none', backup_parse_digests("CREATE TABLE x (a int);\n") === []);

bf_ok('comment lines that begin a chunk are stripped and the statement kept',
      backup_strip_leading_comments("-- ----\n-- Table: `t`\n-- ----\n\nDROP TABLE IF EXISTS `t`") === 'DROP TABLE IF EXISTS `t`');
bf_ok('a chunk that is only comments gives nothing', backup_strip_leading_comments("-- Backup complete\n") === '' && backup_strip_leading_comments("-- x") === '');
bf_ok('the header SET statements that share a chunk with the header comments survive',
      backup_strip_leading_comments("-- TicketsCAD\n-- Generated: now\n--\n\nSET NAMES utf8mb4") === 'SET NAMES utf8mb4');
bf_ok('a statement that does not begin with a comment is untouched', backup_strip_leading_comments("INSERT INTO t VALUES (1)") === 'INSERT INTO t VALUES (1)');

// ── Parts B-F need a database that can CREATE DATABASE ─────────────────────────
$prefix = $GLOBALS['db_prefix'] ?? '';
$adminUser = (string) ($GLOBALS['db_user'] ?? '');
$adminPass = (string) ($GLOBALS['db_pass'] ?? '');
$host = (string) ($GLOBALS['db_host'] ?? '127.0.0.1');
$canCreate = false;
$rootPdo = null;
$scratchNames = [];
try {
    $rootPdo = new PDO("mysql:host={$host};charset=utf8mb4", $adminUser, $adminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $probe = 'p155bf_probe_' . getmypid();
    $rootPdo->exec("CREATE DATABASE `{$probe}` CHARACTER SET utf8mb4");
    $rootPdo->exec("DROP DATABASE `{$probe}`");
    $canCreate = true;
} catch (Throwable $e) {
    $why = $e->getMessage();
}
if (!$canCreate) {
    echo "\nSKIP: Parts B-F -- this database user cannot CREATE DATABASE (" . ($why ?? '') . "); a restore drill needs that too.\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$TBL = 'p155_bkfid_all';
$pdo = db();
$dumpFile = sys_get_temp_dir() . '/p155_bkfid_' . getmypid() . '.sql';
register_shutdown_function(static function () use (&$scratchNames, &$rootPdo, $TBL, $dumpFile) {
    foreach ($scratchNames as $n) { try { $rootPdo->exec("DROP DATABASE IF EXISTS `{$n}`"); } catch (Throwable $e) {} }
    try { db()->exec("DROP TABLE IF EXISTS `{$TBL}`"); } catch (Throwable $e) {}
    foreach (glob(sys_get_temp_dir() . '/p155_bkfid_' . getmypid() . '*') ?: [] as $f) { @unlink($f); }
});
$newScratch = static function (string $tag) use (&$scratchNames, $rootPdo, $host, $adminUser, $adminPass): array {
    $name = 'p155bf_' . $tag . '_' . getmypid();
    $rootPdo->exec("DROP DATABASE IF EXISTS `{$name}`");
    $rootPdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");
    $scratchNames[] = $name;
    $p = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $adminUser, $adminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return [$name, $p];
};

// The throwaway table: every storage class, every awkward value from the report.
$pdo->exec("DROP TABLE IF EXISTS `{$TBL}`");
$pdo->exec("CREATE TABLE `{$TBL}` (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  c_char CHAR(70) NULL, c_varchar VARCHAR(120) NULL, c_text TEXT NULL,
  c_tiny TINYINT NULL, c_int INT NULL, c_big BIGINT UNSIGNED NULL, c_dec DECIMAL(40,20) NULL,
  c_float FLOAT NULL, c_double DOUBLE NULL, c_bit BIT(8) NULL,
  c_enum ENUM('0','1','2') NULL, c_set SET('1','2','3') NULL,
  c_blob BLOB NULL, c_bin BINARY(4) NULL,
  c_date DATE NULL, c_dt DATETIME NULL, c_ts TIMESTAMP NULL DEFAULT NULL, c_time TIME NULL, c_year YEAR NULL,
  c_json LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("SET time_zone = '+00:00'");
$awkward = ['0123', '1e5', '0x1F', ' 7 ', '-0', '1234567890e234567890123456789012345678901234567890123456789012',
            '.5', '+5', str_repeat('9', 80), '1.50', '007', "it's", 'back\\slash', "a;\nb", 'with "double" quotes',
            "tab\tand\r\nCRLF", "nul\0byte", "emoji \u{1F4FB} and \u{00E9}", '', 'NaN', 'INF', '12e45678'];
$sqlLit = static function (?string $s) use ($pdo): string { return $s === null ? 'NULL' : $pdo->quote($s); };
$ins = [];
foreach ($awkward as $i => $v) {
    $enum = ['0', '1', '2'][$i % 3];
    $ins[] = '(' . implode(',', [
        $sqlLit(substr($v, 0, 70)), $sqlLit($v), $sqlLit($v),
        (string) (($i % 200) - 100), (string) ($i * 1000003), '18446744073709551615', "'12345678901234567890.12345678901234567890'",
        '0.1', '3.141592653589793', (string) ($i % 256), "'{$enum}'", "'" . ($i % 2 ? '1,3' : '2') . "'",
        '0x' . bin2hex($v === '' ? "\x00" : substr($v, 0, 8)), '0x' . bin2hex(substr(str_pad($v, 4, "\0"), 0, 4)),
        "'2026-03-01'", "'2026-03-01 12:34:56'", "'2026-03-01 12:34:56'", "'838:59:59'", '2026',
        $sqlLit('{"v":' . json_encode($v) . '}'),
    ]) . ')';
}
// Extreme and exact numeric rows, written as SQL LITERALS so no PHP conversion can touch them.
$ins[] = "(NULL,NULL,NULL, 127,2147483647,0,'-0.00000000000000000001', 3.4028234e38,1.7976931348623157e308,255,'2','1,2,3',NULL,NULL,NULL,'1970-01-01 00:00:01','2038-01-19 03:14:07','-838:59:59',1901,NULL)";
$ins[] = "(NULL,NULL,NULL,-128,-2147483648,9223372036854775807,'0', 1.17549435e-38,-2.2250738585072014e-308,0,'0','1',NULL,NULL,'1000-01-01','9999-12-31 23:59:59','2026-06-15 03:00:00','00:00:00',2155,NULL)";
$ins[] = "(NULL,NULL,NULL,1,1,1,'1e5', 16777217,1.0e25,1,'1','3',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)";
$ins[] = "(NULL,NULL,NULL,1,1,1,'1', 0.30000001192092896,0.30000000000000004,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)";
$colsSql = '(c_char,c_varchar,c_text,c_tiny,c_int,c_big,c_dec,c_float,c_double,c_bit,c_enum,c_set,c_blob,c_bin,c_date,c_dt,c_ts,c_time,c_year,c_json)';
$ok = true;
foreach ($ins as $rowSql) {
    try { $pdo->exec("INSERT INTO `{$TBL}` {$colsSql} VALUES {$rowSql}"); }
    catch (Throwable $e) { $ok = false; echo "fixture row failed: " . $e->getMessage() . "\n  " . substr($rowSql, 0, 140) . "\n"; }
}
bf_ok('the fixture table was filled (' . (int) $pdo->query("SELECT COUNT(*) FROM `{$TBL}`")->fetchColumn() . ' rows)', $ok && (int) $pdo->query("SELECT COUNT(*) FROM `{$TBL}`")->fetchColumn() === count($ins));

// ── PART B ─────────────────────────────────────────────────────────────────────
echo "\n-- Part B: the real dump -> the real restore, every cell compared as bytes --\n";
$t0 = microtime(true);
$dumped = backup_dump_sql($dumpFile);
bf_ok('backup_dump_sql() ran', $dumped === true && is_file($dumpFile) && filesize($dumpFile) > 1000);
$dump = (string) file_get_contents($dumpFile);
$bannerAt = strpos($dump, "-- --------------------------------------------------------\n-- Table: `");
$header = $bannerAt === false ? '' : substr($dump, 0, $bannerAt);
if (!preg_match('/-- -{20,}\n-- Table: `' . preg_quote($TBL, '/') . '`\n.*?(?=\n-- -{20,}\n-- Table: |\nSET FOREIGN_KEY_CHECKS = 1;)/s', $dump, $sm)) { $sm = ['']; }
$section = $sm[0];
bf_ok('the dump header carries the restore prologue and the digest format', strpos($header, "SET time_zone = '+00:00'") !== false && strpos($header, '-- Digest-Format: ' . BACKUP_DIGEST_FORMAT) !== false);
bf_ok("the dump holds the fixture table and a fingerprint for it", $section !== '' && (bool) preg_match('/^-- Digest: ' . preg_quote($TBL, '/') . ' rows=' . count($ins) . ' sha256=[0-9a-f]{64}$/m', $section));
$digestCount = count(backup_parse_digests($dump));
bf_ok('every table in the dump carries a fingerprint (' . $digestCount . ' of ' . count($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll()) . ')',
      $digestCount === count($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll()));
$subset = $header . $section . "\nSET FOREIGN_KEY_CHECKS = 1;\nCOMMIT;\n";

/** Every cell as hex bytes, in a UTC session, FLOAT widened exactly as the dump reads it. */
$cellSql = static function (PDO $p) use ($TBL): array {
    $p->exec("SET time_zone = '+00:00'");
    $cols = ['c_char', 'c_varchar', 'c_text', 'c_tiny', 'c_int', 'c_big', 'c_dec', '(c_float + 0e0)', 'c_double', 'c_bit', 'c_enum', 'c_set',
             'c_blob', 'c_bin', 'c_date', 'c_dt', 'c_ts', 'c_time', 'c_year', 'c_json'];
    $sel = implode(',', array_map(static fn($c) => "IFNULL(HEX(CAST({$c} AS BINARY)),'<NULL>')", $cols));
    return [$cols, $p->query("SELECT id, {$sel} FROM `{$TBL}` ORDER BY id")->fetchAll(PDO::FETCH_NUM)];
};
[, $orig] = $cellSql($pdo);
[$sName1, $s1] = $newScratch('rt');
[$applied, $errors, $rep] = backup_apply_sql($s1, $subset);
bf_ok('the dump applies with no SQL error (header, DROP, CREATE, INSERTs: ' . $applied . ' statements)', $errors === 0 && $applied >= 5, json_encode($rep));
[$cols, $back] = $cellSql($s1);
bf_ok('the same number of rows came back', count($back) === count($orig), count($back) . ' vs ' . count($orig));
$bad = [];
foreach ($orig as $i => $row) {
    foreach ($cols as $k => $c) {
        if (($back[$i][$k + 1] ?? '<MISSING>') !== $row[$k + 1]) {
            $bad[] = "row {$row[0]} {$c}: " . substr((string) hex2bin(str_replace('<NULL>', '', $row[$k + 1])), 0, 24) . ' -> ' . substr((string) hex2bin(str_replace(['<NULL>', '<MISSING>'], '', (string) ($back[$i][$k + 1] ?? ''))), 0, 24);
        }
    }
}
bf_ok('EVERY cell of every row came back byte-for-byte (' . (count($orig) * count($cols)) . ' cells; the old rule corrupted dozens of them)', !$bad, implode(' | ', array_slice($bad, 0, 5)));
$fid = backup_verify_digests($s1, $subset);
bf_ok('the dump\'s own fingerprint for the table verifies against the restored rows', $fid['checked'] === 1 && !$fid['mismatched'] && !$fid['missing'] && $fid['ok'] === [$TBL], json_encode($fid));
bf_ok('verification leaves the connection\'s own time zone as it found it', (function () use ($s1) {
    $s1->exec("SET time_zone = '-05:00'");
    backup_verify_digests($s1, "-- Digest: nosuchtable rows=0 sha256=" . str_repeat('0', 64) . "\n");
    return $s1->query('SELECT @@session.time_zone')->fetchColumn() === '-05:00';
})());
// TIMESTAMP: the dump's read connection is now UTC, like the header it writes.
$ref = new ReflectionFunction('backup_dump_sql');
$src = implode('', array_slice(file($ref->getFileName()), $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
bf_ok("backup_dump_sql() reads on a connection set to UTC (the header says UTC)", (bool) preg_match('/\$unbuffered->exec\("SET time_zone = \'\+00:00\'"\)/', $src));

// ── PART C ─────────────────────────────────────────────────────────────────────
echo "\n-- Part C: the old rule corrupts these values, and the fingerprint sees it --\n";
$oldLiteral = static function ($val, bool $blob) use ($quote): string {
    // The pre-Phase-155 rule, verbatim: look at the VALUE.
    if ($val === null) return 'NULL';
    if ($blob) return strlen($val) > 0 ? '0x' . bin2hex($val) : "''";
    if (is_numeric($val) && strpos((string) $val, '0') !== 0 && strpos((string) $val, '+') === false) return (string) $val;
    return $quote((string) $val);
};
[, $s2] = $newScratch('old');
$s2->exec("CREATE TABLE `c` (id INT PRIMARY KEY, v VARCHAR(120), e ENUM('0','1','2'))");
$old = [[1, '1e5', '1'], [2, ' 7 ', '2'], [3, '-0', '1'], [4, '.5', '2'], [5, str_repeat('1', 66) . 'e5', '1']];
foreach ($old as [$id, $v, $e]) { $s2->exec("INSERT INTO `c` VALUES ({$id}, " . $oldLiteral($v, false) . ", " . $oldLiteral($e, false) . ")"); }
$gotOld = $s2->query("SELECT v, e FROM `c` ORDER BY id")->fetchAll(PDO::FETCH_NUM);
$changed = 0;
foreach ($old as $i => [$id, $v, $e]) { if ($gotOld[$i][0] !== $v) $changed++; if ($gotOld[$i][1] !== $e) $changed++; }
bf_ok('the OLD value-sniffing rule demonstrably corrupts these cells (' . $changed . ' of ' . (count($old) * 2) . ' changed)', $changed >= 7, (string) $changed);
$poisoned = $subset;
$poisonedFrom = "'1e5'";
$poisoned = preg_replace('/\'1e5\'/', '1e5', $poisoned, 1, $nrep);
bf_ok('(the poisoned dump really contains the old rule\'s bare 1e5)', $nrep === 1 && $poisoned !== $subset);
[, $s3] = $newScratch('poison');
[$pa, $perr] = backup_apply_sql($s3, $poisoned);
bf_ok('the poisoned dump still applies without a single SQL error (this is why counting statements proved nothing)', $perr === 0);
$pf = backup_verify_digests($s3, $poisoned);
bf_ok('...and the fingerprint check names the corrupted table', isset($pf['mismatched'][$TBL]) && !$pf['ok'], json_encode($pf));
// A deleted row and an edited cell are caught as well.
$s1->exec("DELETE FROM `{$TBL}` WHERE id = 3");
$f1 = backup_verify_digests($s1, $subset);
bf_ok('a missing row is caught', isset($f1['mismatched'][$TBL]) && $f1['mismatched'][$TBL]['rows_actual'] === count($ins) - 1, json_encode($f1));
[, $s4] = $newScratch('edit');
backup_apply_sql($s4, $subset);
$s4->exec("UPDATE `{$TBL}` SET c_varchar = CONCAT(c_varchar, 'x') WHERE id = 5");
$f2 = backup_verify_digests($s4, $subset);
bf_ok('an edited cell is caught', isset($f2['mismatched'][$TBL]) && $f2['mismatched'][$TBL]['rows_actual'] === count($ins), json_encode($f2));
$s4->exec("DROP TABLE `{$TBL}`");
$f3 = backup_verify_digests($s4, $subset);
bf_ok('a table that is not there at all is reported missing', $f3['missing'] === [$TBL], json_encode($f3));

// ── PART D ─────────────────────────────────────────────────────────────────────
echo "\n-- Part D: restoring over a database that already has the table --\n";
[, $s5] = $newScratch('over');
backup_apply_sql($s5, $subset);
$s5->exec("INSERT INTO `{$TBL}` (c_varchar) VALUES ('stale row added after the backup')");
$s5->exec("UPDATE `{$TBL}` SET c_varchar = 'changed since the backup' WHERE id = 1");
[$a5, $e5, $r5] = backup_apply_sql($s5, $subset);
bf_ok('restoring onto the existing table raises no error (it used to fail on every table: "already exists", "Duplicate entry")', $e5 === 0, json_encode($r5));
bf_ok('...and replaces its contents: the stale row is gone, the edited cell is back', (int) $s5->query("SELECT COUNT(*) FROM `{$TBL}`")->fetchColumn() === count($ins)
      && (string) $s5->query("SELECT c_varchar FROM `{$TBL}` WHERE id = 1")->fetchColumn() === $awkward[0]);
$f5 = backup_verify_digests($s5, $subset);
bf_ok('...and the fingerprint agrees', !$f5['mismatched'] && !$f5['missing'] && $f5['checked'] === 1);
// The session the restore ran on is left in autocommit, not stuck mid-transaction.
bf_ok('the restore leaves the connection in autocommit', (int) $s5->query('SELECT @@session.autocommit')->fetchColumn() === 1);

// ── PART E ─────────────────────────────────────────────────────────────────────
echo "\n-- Part E: the real restore drill, end to end --\n";
$t1 = microtime(true);
$r = backup_drill($dumpFile, $adminUser, $adminPass);
bf_ok('the drill of the REAL, whole-database dump passes (' . round(microtime(true) - $t1, 1) . ' s)', $r['ok'] === true && $r['conclusive'] === true, $r['detail']);
bf_ok('...and it checked content, table by table (' . ($r['fidelity']['checked'] ?? 0) . ' tables), with nothing differing',
      isset($r['fidelity']) && $r['fidelity']['checked'] >= 100 && !$r['fidelity']['mismatched'] && !$r['fidelity']['missing'], json_encode(array_slice($r['fidelity']['mismatched'] ?? [], 0, 3)));
bf_ok('...and says so', strpos($r['detail'], 'matches the dump\'s own fingerprint') !== false, $r['detail']);
$poisonFile = sys_get_temp_dir() . '/p155_bkfid_' . getmypid() . '_poison.sql';
file_put_contents($poisonFile, $poisoned);
$rp = backup_drill($poisonFile, $adminUser, $adminPass);
bf_ok('the drill of a dump written the OLD way FAILS, conclusively, and names the table', $rp['ok'] === false && $rp['conclusive'] === true && strpos($rp['detail'], $TBL) !== false, $rp['detail']);
bf_ok('...although every statement applied and the rows are all there', $rp['errors'] === 0 && $rp['tables'] > 0, 'errors=' . $rp['errors'] . ' tables=' . $rp['tables']);
$legacy = preg_replace('/^-- Digest.*\n/m', '', $subset);
$legacyFile = sys_get_temp_dir() . '/p155_bkfid_' . getmypid() . '_legacy.sql';
file_put_contents($legacyFile, $legacy);
$rl = backup_drill($legacyFile, $adminUser, $adminPass);
bf_ok('a backup written before fingerprints existed still drills (counts only) and says that it could not check content',
      $rl['ok'] === true && strpos($rl['detail'], 'no content fingerprints') !== false, $rl['detail']);

// ── PART F ─────────────────────────────────────────────────────────────────────
echo "\n-- Part F: tools/restore.php --verify --\n";
$subsetFile = sys_get_temp_dir() . '/p155_bkfid_' . getmypid() . '_subset.sql';
file_put_contents($subsetFile, $subset);
$cli = p155_run_process([__DIR__ . '/../tools/restore.php', '--verify', '--file=' . $subsetFile], 120);
bf_ok('--verify against the live table that was just dumped exits 0', $cli['exit'] === 0 && strpos($cli['stdout'], 'matches the dump\'s own fingerprint') !== false, trim($cli['stdout'] . $cli['stderr']));
$pdo->exec("UPDATE `{$TBL}` SET c_text = CONCAT(c_text, '!') WHERE id = 2");
$cli = p155_run_process([__DIR__ . '/../tools/restore.php', '--verify', '--file=' . $subsetFile], 120);
bf_ok('--verify after a cell changed exits 1 and names the table', $cli['exit'] === 1 && strpos($cli['stdout'], $TBL) !== false, trim($cli['stdout'] . $cli['stderr']));
$cli = p155_run_process([__DIR__ . '/../tools/restore.php', '--verify'], 60);
bf_ok('--verify without --file says what it needs', $cli['exit'] === 1 && strpos($cli['stdout'], '--verify needs --file') !== false, trim($cli['stdout']));
$cli = p155_run_process([__DIR__ . '/../tools/restore.php', '--help'], 60);
bf_ok('--help documents --verify and the fingerprint check', strpos($cli['stdout'], '--verify') !== false && strpos($cli['stdout'], 'fingerprint') !== false);
$rsrc = (string) file_get_contents(__DIR__ . '/../tools/restore.php');
bf_ok('a real restore uses the shared applier and ends with the content check (no private copy of the loop)',
      strpos($rsrc, 'backup_apply_sql($pdo, $sql, 5)') !== false && strpos($rsrc, 'backup_verify_digests($pdo, $sql)') !== false
      && strpos($rsrc, "str_starts_with(\$stmt, '--')") === false);

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
