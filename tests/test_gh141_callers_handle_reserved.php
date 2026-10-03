<?php
/**
 * GH#141 -- every caller of assign_create_internal() handles a RESERVED answer.
 *
 * In reserve mode the writer can answer {reserved: true, id: 0, ...}: the unit
 * was committed for later, not dispatched, and `id` is 0. A caller that reads
 * `$result['id']` straight away would audit `assign.created` with assign_id 0
 * (announcing a dispatch that has not happened to every webhook subscriber) or
 * report "assigned" to a dispatcher whose unit is still Available. Two callers
 * did exactly that on first read (api/incident-assign.php and
 * api/external/v1/assignments.php).
 *
 * This is the tripwire for the NEXT caller: TOKENIZED (never grepped -- the
 * project's own rule; these files discuss `reserved` in prose too), it fails when
 * any file under api/ or inc/ calls assign_create_internal() without referencing
 * the 'reserved' result key as a real string token. The allow-list is empty.
 *
 * Positive controls prove the audit fires and does not false-alarm.
 *
 * Usage: php tests/test_gh141_callers_handle_reserved.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- every caller handles a reserved answer ===\n\n";

/**
 * @return array{calls:int, defines:bool, handles:bool}
 */
function p155_scan_assign_callers(string $src): array {
    $tokens = @token_get_all($src);
    $n = count($tokens);
    $calls = 0; $defines = false; $handles = false;
    for ($i = 0; $i < $n; $i++) {
        $tk = $tokens[$i];
        if (!is_array($tk)) continue;
        if ($tk[0] === T_STRING && $tk[1] === 'assign_create_internal') {
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) $prev--;
            $next = $i + 1;
            while ($next < $n && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) $next++;
            $isDefinition = ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION);
            if ($isDefinition) { $defines = true; continue; }
            if ($next < $n && $tokens[$next] === '(') $calls++;
        }
        if ($tk[0] === T_CONSTANT_ENCAPSED_STRING && ($tk[1] === "'reserved'" || $tk[1] === '"reserved"')) {
            $handles = true;
        }
    }
    return ['calls' => $calls, 'defines' => $defines, 'handles' => $handles];
}

echo "--- the audit fires (positive controls) ---\n";
$bad = <<<'PHPSRC'
<?php
$r = assign_create_internal($t, $u, '', 1);
audit_log('incident', 'assign', 'assigns', $r['id'], 'x');
PHPSRC;
$s = p155_scan_assign_callers($bad);
t('a caller that never mentions the reserved key is flagged', $s['calls'] === 1 && !$s['handles']);

$good = <<<'PHPSRC'
<?php
$r = assign_create_internal($t, $u, '', 1);
if (!empty($r['reserved'])) { return; }
PHPSRC;
$s = p155_scan_assign_callers($good);
t('a caller that checks $r[\'reserved\'] is clean', $s['calls'] === 1 && $s['handles']);

$prose = <<<'PHPSRC'
<?php
// TODO: handle the 'reserved' answer someday
$r = assign_create_internal($t, $u, '', 1);
PHPSRC;
$s = p155_scan_assign_callers($prose);
t('the word in a COMMENT does not count (tokenized, not grepped)', $s['calls'] === 1 && !$s['handles']);

$def = <<<'PHPSRC'
<?php
function assign_create_internal(int $a, int $b) { return []; }
PHPSRC;
$s = p155_scan_assign_callers($def);
t('the definition itself is not a call', $s['calls'] === 0 && $s['defines']);

$mention = <<<'PHPSRC'
<?php
$name = 'assign_create_internal';
function wrapper() { return 1; }
PHPSRC;
$s = p155_scan_assign_callers($mention);
t('a bare string naming the function is not a call', $s['calls'] === 0);

echo "\n--- the real tree (api/, inc/) ---\n";
$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$files = [];
foreach (['api', 'inc'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php' && strpos(str_replace('\\', '/', $f->getPathname()), '/vendor/') === false) {
            $files[] = str_replace('\\', '/', $f->getPathname());
        }
    }
}
sort($files);
$callers = []; $unhandled = [];
foreach ($files as $f) {
    $s = p155_scan_assign_callers((string) file_get_contents($f));
    if ($s['defines']) continue;                 // the writer itself
    if ($s['calls'] > 0) {
        $rel = str_replace($root . '/', '', $f);
        $callers[] = $rel;
        if (!$s['handles']) $unhandled[] = $rel;
    }
}
t('the scan found the known callers (the sanity floor, so it cannot pass vacuously)',
    count($callers) >= 4, implode(', ', $callers));
foreach (['api/incident-assign.php', 'api/external/v1/assignments.php', 'inc/incident-write.php', 'inc/assign-reservations.php'] as $known) {
    t("the known caller $known is in the scan", in_array($known, $callers, true));
}
t('EVERY caller references the reserved key (allow-list empty)', $unhandled === [], implode(', ', $unhandled));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
