<?php
/**
 * Gate: the SBOM document must stay inside the JSON profile an RFC 8785 (JCS)
 * canonicaliser — and so a CycloneDX in-document JSF signature — is proven on.
 *
 * The in-document signature is deliberately NOT shipped yet: it can only be
 * accepted by a second, independent implementation (cyclonedx-cli), which is
 * not available to this project's tooling. What CAN be done now, with no
 * verifier at all, is to stop the premise that a later canonicaliser rests on
 * from silently ceasing to be true. That is tools/sbom-jcs-profile.php, and
 * this file proves:
 *
 *   1. The guard function flags every way out of the profile — floats
 *      (including 1.0, which json_encode writes differently from JCS),
 *      integers a double cannot hold, control characters in values AND keys,
 *      non-ASCII keys, invalid UTF-8 — and flags NOTHING else: non-ASCII
 *      VALUES are legal (the document has dozens) and must not trip it.
 *   2. The COMMITTED SBOM is inside the profile today (the premise measured
 *      when the work was scoped: no floats, no control characters, ASCII keys).
 *   3. The generator really acts on the answer: it runs the guard before the
 *      `--check` / write split and exits non-zero without writing, so CI's
 *      `--check` fails at the change that broke the premise. Asserted on the
 *      real file by position and by control flow, because a guard that is
 *      computed and then ignored is the failure this project keeps
 *      rediscovering.
 *   4. The real generator still passes `--check` as a process.
 *
 * Usage: php tests/test_sbom_jcs_profile.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only'); }

$root = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
require_once $root . '/tools/sbom-jcs-profile.php';

$pass = 0;
$fail = 0;
function t($label, $cond, $hint = '')
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label" . ($hint !== '' ? "\n         $hint" : '') . "\n"; }
}
function hits(array $v, string $needle): bool
{
    foreach ($v as $m) { if (strpos($m, $needle) !== false) { return true; } }
    return false;
}

echo "\n-- 1. The guard function --\n";
$clean = [
    'bomFormat' => 'CycloneDX', 'specVersion' => '1.6', 'version' => 48,
    'components' => [
        ['name' => 'marked', 'version' => '4.3.0', 'licenses' => [['license' => ['id' => 'MIT']]]],
        ['name' => 'caf' . "\xc3\xa9", 'version' => '1.0', 'flag' => true, 'none' => null, 'n' => -7, 'zero' => 0],
    ],
    'properties' => [['name' => 'ticketscad:signature-algorithm', 'value' => 'ECDSA P-256 / SHA-256']],
];
t('a clean document has no violations (and non-ASCII VALUES are legal)', sbom_jcs_profile_violations($clean) === [],
    implode('; ', sbom_jcs_profile_violations($clean)));

$v = sbom_jcs_profile_violations(['a' => ['b' => [1, 2.5]]]);
t('a float is flagged, with a path that locates it', count($v) === 1 && strpos($v[0], '$.a.b[1]') === 0 && strpos($v[0], 'float') !== false, implode('; ', $v));
t('1.0 is flagged too (json_encode writes 1.0, JCS writes 1)', hits(sbom_jcs_profile_violations(['x' => 1.0]), 'float'));
t('INF/NAN are floats and are flagged', count(sbom_jcs_profile_violations(['x' => INF, 'y' => NAN])) === 2);
t('2^53-1 is accepted', sbom_jcs_profile_violations(['x' => 9007199254740991, 'y' => -9007199254740991]) === []);
t('2^53 is flagged (a double cannot hold every integer above it)', hits(sbom_jcs_profile_violations(['x' => 9007199254740992]), 'integer'));
t('-2^53 is flagged', hits(sbom_jcs_profile_violations(['x' => -9007199254740992]), 'integer'));
foreach (["\x00" => 'NUL', "\x01" => 'SOH', "\x08" => 'BS', "\x09" => 'TAB', "\x0a" => 'LF', "\x0d" => 'CR', "\x1f" => 'US'] as $ch => $nm) {
    t("a string value containing $nm is flagged", hits(sbom_jcs_profile_violations(['s' => 'a' . $ch . 'b']), 'control character'));
}
t('a control character in an object KEY is flagged', hits(sbom_jcs_profile_violations(["k\x01" => 1]), 'key'));
t('DEL (0x7f) and a plain space are not control characters for this profile',
    sbom_jcs_profile_violations(['s' => "a b\x7f"]) === []);
t('a non-ASCII object key is flagged', hits(sbom_jcs_profile_violations(["k\xc3\xa9y" => 1]), 'not ASCII'));
t('invalid UTF-8 in a string is flagged', hits(sbom_jcs_profile_violations(['s' => "\xff\xfe"]), 'UTF-8'));
$obj = new stdClass(); $obj->f = 0.5;
t('objects are walked as well as arrays', hits(sbom_jcs_profile_violations(['o' => $obj]), 'float'));
$many = [];
for ($i = 0; $i < 100; $i++) { $many[] = 0.5; }
t('a runaway document cannot flood the output (limit honoured)', count(sbom_jcs_profile_violations($many)) === 25
    && count(sbom_jcs_profile_violations($many, '$', 3)) === 3);
t('a list key is never treated as an object key', sbom_jcs_profile_violations(['list' => [0, 1, 2]]) === []);

echo "\n-- 2. The COMMITTED SBOM is inside the profile --\n";
$raw = (string) @file_get_contents($root . '/SBOM.cdx.json');
$doc = json_decode($raw, true);
t('SBOM.cdx.json exists and decodes', is_array($doc) && !empty($doc['components']));
$viol = is_array($doc) ? sbom_jcs_profile_violations($doc) : ['undecodable'];
t('...and has no float, no control character, only ASCII keys and exactly-representable integers',
    $viol === [], implode('; ', array_slice($viol, 0, 5)));
t('...the measured premise still holds: it carries non-ASCII VALUES (so the check is not vacuous)',
    preg_match('/[^\x00-\x7f]/', $raw) === 1);

echo "\n-- 3. The generator acts on the answer --\n";
$gen = (string) file_get_contents($root . '/tools/generate-sbom.php');
$posCall  = strpos($gen, '$profileViolations = sbom_jcs_profile_violations($bom);');
$posCheck = strpos($gen, 'if ($checkOnly) {');
$posWrite = strpos($gen, 'file_put_contents($outPath');
$posRequire = strpos($gen, "require_once __DIR__ . '/sbom-jcs-profile.php';");
t('the generator loads the guard and calls it on the assembled document',
    $posRequire !== false && $posCall !== false && $posRequire < $posCall);
t('...BEFORE the --check / write split (so CI\'s --check trips on it)',
    $posCall !== false && $posCheck !== false && $posCall < $posCheck);
t('...and before anything is written', $posCall !== false && $posWrite !== false && $posCall < $posWrite);
t('...and a violation stops the run with a non-zero exit (the result is not computed and ignored)',
    $posCall !== false && $posCheck !== false
    && preg_match('/if \(\$profileViolations !== \[\]\) \{.*exit\(1\);\s*\}/s',
        substr($gen, $posCall, $posCheck - $posCall)) === 1);
t('...and says what to do about it', strpos($gen, 'Remove the offending value') !== false);

echo "\n-- 4. The real generator still passes --check --\n";
$bin = PHP_BINARY;
$o = tempnam(sys_get_temp_dir(), 'sbomo');
$proc = @proc_open([$bin, '-d', 'display_errors=0', $root . '/tools/generate-sbom.php', '--check'],
    [0 => ['file', (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), 'r'], 1 => ['file', $o, 'w'], 2 => ['file', $o, 'a']],
    $pipes, null, null, ['bypass_shell' => true]);
$code = is_resource($proc) ? proc_close($proc) : -1;
$out = (string) @file_get_contents($o);
@unlink($o);
t('`php tools/generate-sbom.php --check` exits 0 with the guard in place', $code === 0, trim($out));

$cliGuard = (string) file_get_contents($root . '/tools/sbom-jcs-profile.php');
t('the guard file is CLI-only before it defines anything',
    preg_match('/^<\?php\s*\/\*\*.*?\*\/\s*if \(PHP_SAPI !== \'cli\'\)/s', $cliGuard) === 1);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
