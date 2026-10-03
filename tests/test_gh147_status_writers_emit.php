<?php
/**
 * GH#147 -- stops the NEXT ticket-status writer from being a silent one.
 *
 * `incident.status_changed` is only true ("from every path") if every piece of
 * code that changes `ticket.status` announces it. This phase found FIVE such
 * writers where the issue named three (the lazy scheduled activation raw
 * UPDATE, and the Major Incident close raw UPDATE, which fired nothing and
 * stranded every unit). Both are now routed through the status writer or the
 * activation function; this audit makes that a standing rule rather than a
 * one-off clean-up.
 *
 * Rule: every `UPDATE ... ticket ... SET ... status = ...` under api/ and inc/
 * must sit inside a function that calls incident_status_change_emit(). (The
 * allow-list is empty: there is no legitimate raw status writer.)
 *
 * TOKENIZED, not grepped -- the project's own rule. Several comments in these
 * files describe the old raw UPDATEs in prose, and a substring scan cannot tell
 * an explanation from an occurrence. SQL is stitched with tools/sql_extract.php
 * (the shared extractor), and the enclosing function comes from the token
 * stream's brace nesting.
 *
 * Positive controls prove the audit fires: fixture sources with a raw writer
 * (flagged), a raw writer in a function that emits (clean), a raw writer at file
 * level (flagged), a reschedule that only mentions status in WHERE (clean).
 *
 * Usage: php tests/test_gh147_status_writers_emit.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../tools/sql_extract.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#147 -- every ticket.status writer announces the change ===\n\n";

/**
 * Map each function (name => [startLine, endLine, bodyCallsEmit]) in a source.
 */
function p155_function_ranges(string $src): array {
    $tokens = @token_get_all($src);
    $ranges = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tk = $tokens[$i];
        if (!is_array($tk) || $tk[0] !== T_FUNCTION) continue;
        // Named functions only: T_FUNCTION [&] T_STRING '('. A closure is
        // T_FUNCTION [&] '(' and belongs to whatever function encloses it.
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE], true)) $j++;
        if ($j < $n && $tokens[$j] === '&') { $j++; while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++; }
        if (!($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING)) continue;
        $name = $tokens[$j][1];
        $startLine = $tk[2];
        // Find the opening brace of the body.
        $k = $j;
        while ($k < $n && $tokens[$k] !== '{') {
            if ($tokens[$k] === ';') { $k = -1; break; }   // abstract / interface declaration
            $k++;
        }
        if ($k < 0 || $k >= $n) continue;
        $depth = 0; $callsEmit = false; $endLine = $startLine;
        for (; $k < $n; $k++) {
            $x = $tokens[$k];
            if ($x === '{') { $depth++; }
            elseif ($x === '}') { $depth--; }
            elseif (is_array($x)) {
                if ($x[0] === T_CURLY_OPEN || $x[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; }
                if ($x[0] === T_STRING && $x[1] === 'incident_status_change_emit') {
                    // a CALL, not the definition: next significant token is '('
                    $m = $k + 1;
                    while ($m < $n && is_array($tokens[$m]) && $tokens[$m][0] === T_WHITESPACE) $m++;
                    $prev = $k - 1;
                    while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) $prev--;
                    $isDefinition = ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION);
                    if ($m < $n && $tokens[$m] === '(' && !$isDefinition) $callsEmit = true;
                }
                if (isset($x[2])) $endLine = $x[2];
            }
            if ($depth === 0) break;
        }
        $ranges[] = ['name' => $name, 'start' => $startLine, 'end' => $endLine, 'emits' => $callsEmit];
    }
    return $ranges;
}

/**
 * Raw ticket.status writers in $src that are NOT inside a function calling
 * incident_status_change_emit(). Returns [ [line, functionName|'(file level)', sqlExcerpt] ].
 * Also reports the total number of status UPDATEs seen via $seen (a sanity floor).
 */
function p155_unemitted_status_writers(string $src, ?int &$seen = null): array {
    $seen = 0;
    $bad = [];
    $ranges = p155_function_ranges($src);
    foreach (sql_extract_strings($src) as [$line, $sql]) {
        $norm = preg_replace('/\s+/', ' ', $sql);
        if (!preg_match('/\bUPDATE\s+(.+?)\s+SET\s+(.+?)(?:\s+WHERE\s|$)/i', $norm, $m)) continue;
        $tables = $m[1]; $set = $m[2];
        if (!preg_match('/(?<![A-Za-z0-9_])`?(?:\{\$prefix\})?ticket`?(?![A-Za-z0-9_])/i', $tables)) continue;
        if (!preg_match('/(?<![A-Za-z0-9_])(?:[A-Za-z_]+\.)?`?status`?\s*=(?!=)/i', $set)) continue;
        $seen++;
        // Which function encloses this statement?
        $enclosing = null;
        foreach ($ranges as $r) {
            if ($line >= $r['start'] && $line <= $r['end']) {
                if ($enclosing === null || $r['start'] >= $enclosing['start']) $enclosing = $r;
            }
        }
        if ($enclosing !== null && $enclosing['emits']) continue;
        $bad[] = [$line, $enclosing['name'] ?? '(file level)', substr($norm, 0, 90)];
    }
    return $bad;
}

// ── Positive controls ────────────────────────────────────────────────────
echo "--- the audit fires (positive controls) ---\n";
$fixtureRaw = <<<'PHPSRC'
<?php
function sneaky_close(int $id) {
    db_query("UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = NOW() WHERE `id` = ?", [$id]);
}
PHPSRC;
$seen = 0;
$bad = p155_unemitted_status_writers($fixtureRaw, $seen);
t('a raw status UPDATE in a function that does not emit IS flagged', count($bad) === 1 && $bad[0][1] === 'sneaky_close', json_encode($bad));

$fixtureOk = <<<'PHPSRC'
<?php
function honest_close(int $id) {
    db_query("UPDATE `{$prefix}ticket` SET `status` = 1 WHERE `id` = ? AND `status` = ?", [$id, 2]);
    incident_status_change_emit($id, 2, 1, []);
}
PHPSRC;
t('the same UPDATE in a function that calls incident_status_change_emit() is clean', p155_unemitted_status_writers($fixtureOk) === []);

$fixtureTop = <<<'PHPSRC'
<?php
$rows = 1;
db_query("UPDATE ticket SET status = 2 WHERE id = ?", [5]);
PHPSRC;
$badTop = p155_unemitted_status_writers($fixtureTop);
t('a raw status UPDATE at FILE level is flagged', count($badTop) === 1 && $badTop[0][1] === '(file level)', json_encode($badTop));

$fixtureResched = <<<'PHPSRC'
<?php
function reschedule(int $id) {
    db_query("UPDATE `{$prefix}ticket` SET `booked_date` = ?, `updated` = ? WHERE `id` = ? AND `status` = 3", [1, 2, $id]);
}
PHPSRC;
t('an UPDATE that only mentions status in its WHERE (a reschedule) is NOT a status writer', p155_unemitted_status_writers($fixtureResched) === []);

$fixtureConcat = <<<'PHPSRC'
<?php
function concat_writer(int $id) {
    db_query("UPDATE " . db_table('ticket') . " SET status = 1 WHERE id = ?", [$id]);
}
PHPSRC;
t('a concatenated UPDATE (db_table()) is still caught', count(p155_unemitted_status_writers($fixtureConcat)) === 1);

$fixtureProse = <<<'PHPSRC'
<?php
// This used to be: UPDATE `ticket` SET `status` = 1 WHERE id = ?  -- now routed through the writer.
function fine() { return 1; }
PHPSRC;
t('prose in a comment that quotes the old UPDATE is NOT flagged (tokenized, not grepped)', p155_unemitted_status_writers($fixtureProse) === []);

$fixtureOtherTable = <<<'PHPSRC'
<?php
function other() { db_query("UPDATE `{$prefix}action` SET `status` = 1 WHERE id = ?", [1]); db_query("UPDATE ticket_disposition SET status = 1"); }
PHPSRC;
t('a status column on a different table (action, ticket_disposition) is not this audit\'s business', p155_unemitted_status_writers($fixtureOtherTable) === []);

// ── The real tree ────────────────────────────────────────────────────────
echo "\n--- the real tree (api/ and inc/) ---\n";
$root = realpath(__DIR__ . '/..');
$files = [];
foreach (['api', 'inc'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php' && strpos($f->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) === false) {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);
t('the scan covers the api/ and inc/ trees', count($files) > 150, (string) count($files));
$totalSeen = 0; $violations = [];
foreach ($files as $f) {
    $s = 0;
    $bad = p155_unemitted_status_writers((string) file_get_contents($f), $s);
    $totalSeen += $s;
    foreach ($bad as $b) { $violations[] = str_replace($root . DIRECTORY_SEPARATOR, '', $f) . ':' . $b[0] . ' in ' . $b[1] . ' -> ' . $b[2]; }
}
t('the sanity floor: at least the 4 known status writers were seen (writer x3 branches + activation), so the scan cannot pass vacuously',
    $totalSeen >= 4, (string) $totalSeen);
t('NO raw ticket.status writer exists outside a function that announces the change (allow-list is empty)',
    $violations === [], "\n    " . implode("\n    ", $violations));

// And the two specific routes the issue's author did not know about stay fixed.
$incSrc = (string) file_get_contents($root . '/api/incidents.php');
$majSrc = (string) file_get_contents($root . '/api/major-incidents.php');
t('api/incidents.php (the lazy activation) no longer writes status itself', p155_unemitted_status_writers($incSrc) === [] && strpos($incSrc, 'incident_activate_due_scheduled(') !== false);
t('api/major-incidents.php (the cascade close) goes through incident_update_status_internal()',
    p155_unemitted_status_writers($majSrc) === [] && strpos($majSrc, 'incident_update_status_internal(') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
