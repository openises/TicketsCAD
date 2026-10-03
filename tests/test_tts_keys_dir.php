<?php
/**
 * TTS API keys lived inside the web root (inc/tts/engine.php returned the install-directory path).
 *
 * inc/tts/engine.php returned dirname(__DIR__, 2) . '/keys/tts' — the install
 * directory itself, which the documented setup publishes. Every comment said
 * "../keys/tts, outside the webroot". This test pins the corrected behaviour
 * through the REAL code: the one writer (tts_write_key), the reader, the
 * relocation function, the migration script run as a subprocess, and the
 * Status-page health check — never by asserting a function body looks right.
 *
 * What it asserts:
 *   1. THE DEFAULT is never inside the app tree, nor in inetpub\wwwroot, on any
 *      of the Windows layouts that produced the earlier three regressions, and
 *      on the POSIX ones — asked for an EXPLICIT platform, because a test that
 *      only sees its own platform's answer is how those regressions shipped.
 *   2. An operator's FE_KEYS_DIR / TTS_KEYS_DIR choice is honoured; the built-in
 *      legacy (published) keys directory is NOT followed.
 *   3. The writer puts a key in the private directory with deny rules beside it,
 *      REFUSES the web tree, never reports success it did not achieve, and never
 *      puts a server path in the message an HTTP response may carry.
 *   4. The reader still finds a key left in the old place (no upgrade outage),
 *      and a re-save does not leave the old copy behind.
 *   5. Relocation copies, verifies, then deletes; never overwrites; leaves
 *      non-key files alone; is idempotent; fails loudly when it cannot move.
 *   6. The migration script run twice as a real process: exit codes, no
 *      directory created on an install with nothing to move.
 *   7. The Status page's key row reports a key left in the web tree.
 *   8. No other code resolves keys/tts from the app root (tokenised, not grepped);
 *      the new location is under the Docker volume and the old one is not.
 *
 * Usage: php tests/test_tts_keys_dir.php
 */

if (PHP_SAPI !== 'cli') { exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$root    = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tcad-gh105-' . getmypid();
@mkdir($scratch, 0777, true);
// The in-process default key directory is a scratch one, so nothing here can
// touch the machine-wide keys directory other checkouts share.
define('TTS_KEYS_DIR', $scratch . DIRECTORY_SEPARATOR . 'live');

require_once __DIR__ . '/../inc/tts/engine.php';
require_once __DIR__ . '/../inc/health-check.php';

$pass = 0;
$fail = 0;
function t($label, $cond, $hint = '')
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label" . ($hint !== '' ? "\n         $hint" : '') . "\n"; }
}
function n($p) { return rtrim(str_replace('\\', '/', (string) $p), '/'); }
function rrm($p)
{
    if (is_file($p) || is_link($p)) { @unlink($p); return; }
    if (!is_dir($p)) { return; }
    foreach ((array) @scandir($p) as $e) {
        if ($e !== '.' && $e !== '..') { rrm($p . '/' . $e); }
    }
    @rmdir($p);
}
/** Run a PHP script as a real process: argv array, output via temp files (no pipes to deadlock). */
function run_php(array $args): array
{
    $bin = PHP_BINARY;
    $o = tempnam(sys_get_temp_dir(), 'gh105o');
    $e = tempnam(sys_get_temp_dir(), 'gh105e');
    $proc = @proc_open(array_merge([$bin, '-d', 'display_errors=0'], $args),
        [0 => ['file', (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), 'r'], 1 => ['file', $o, 'w'], 2 => ['file', $e, 'w']],
        $pipes, null, null, ['bypass_shell' => true]);
    $code = is_resource($proc) ? proc_close($proc) : -1;
    $out = (string) @file_get_contents($o);
    $err = (string) @file_get_contents($e);
    @unlink($o); @unlink($e);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

$legacyDir  = tts_keys_dir_legacy();           // <worktree>/keys/tts, inside the tree
$createdKeysParent = !is_dir($root . '/keys');

try {

echo "\n-- 1. The default is never inside the served tree (explicit platforms) --\n";
// The four real IIS/XAMPP layouts that produced the earlier regressions.
$winLayouts = [
    'C:\\inetpub\\wwwroot\\TicketsV4',
    'C:\\inetpub\\wwwroot\\ticketscad',
    'C:\\inetpub\\wwwroot',
    'C:\\xampp\\htdocs\\newui',
    'D:\\Sites\\TicketsCAD',
];
$pd = served_dir_program_data();
foreach ($winLayouts as $app) {
    $d = tts_keys_dir_for($app, true, null);
    $under = function ($child, $parent) {
        $c = strtolower(n($child)); $p = strtolower(n($parent));
        return $c === $p || strpos($c, $p . '/') === 0;
    };
    t("Windows $app -> $d is not inside the app tree", !$under($d, $app));
    t("   ...nor inside C:\\inetpub\\wwwroot (the Default Web Site)", !$under($d, 'C:\\inetpub\\wwwroot'));
    t("   ...nor inside C:\\xampp\\htdocs", !$under($d, 'C:\\xampp\\htdocs'));
    t("   ...and it is the shared %ProgramData% keys\\tts location",
        strcasecmp(n($d), n($pd) . '/TicketsCAD/keys/tts') === 0, $d);
}
foreach (['/var/www/newui', '/srv/ticketscad/app', '/opt/tickets/current', '/home/u/public_html/cad'] as $app) {
    $d = tts_keys_dir_for($app, false, null);
    t("POSIX $app -> $d is a sibling keys/tts, not inside the app",
        $d === dirname($app) . '/keys/tts' && strpos($d, $app . '/') !== 0, $d);
}
t('the real default for THIS install is not inside its own tree',
    !served_dir_is_in_app_tree(tts_keys_dir_for(NEWUI_ROOT)),
    tts_keys_dir_for(NEWUI_ROOT));
t('...and it is not the old in-tree location',
    n(tts_keys_dir_for(NEWUI_ROOT)) !== n($legacyDir));

echo "\n-- 2. Operator choices are honoured; the published legacy directory is not followed --\n";
t('an operator-chosen FE_KEYS_DIR gets tts beneath it',
    tts_keys_dir_for('/var/www/newui', false, '/data/secrets') === '/data/secrets/tts');
t('...on Windows too',
    tts_keys_dir_for('C:\\inetpub\\wwwroot\\App', true, 'D:\\secrets') === 'D:\\secrets\\tts');
t('FE_KEYS_DIR sitting in the legacy, PUBLISHED Windows sibling is NOT followed',
    strcasecmp(n(tts_keys_dir_for('C:\\inetpub\\wwwroot\\App', true, 'C:\\inetpub\\wwwroot\\keys')),
               n($pd) . '/TicketsCAD/keys/tts') === 0,
    'TTS keys are cheap to re-enter; they must not follow the encryption keys into an exposed folder');
t('FE_KEYS_DIR equal to the platform default stays the default',
    strcasecmp(n(tts_keys_dir_for('C:\\xampp\\htdocs\\x', true, $pd . '\\TicketsCAD\\keys')),
               n($pd) . '/TicketsCAD/keys/tts') === 0);
t('the TTS_KEYS_DIR define overrides everything (this very process runs with one)',
    n(tts_keys_dir()) === n(TTS_KEYS_DIR));
t('the FE_KEYS_DIR constant is consulted only for THIS install, never a simulated layout',
    tts_keys_dir_for('C:\\inetpub\\wwwroot\\Other', true) === tts_keys_dir_for('C:\\inetpub\\wwwroot\\Other', true, null)
    && strcasecmp(n(tts_keys_dir_for('C:\\inetpub\\wwwroot\\Other', true)), n($pd) . '/TicketsCAD/keys/tts') === 0);

echo "\n-- 3. The one writer --\n";
$res = tts_write_key('zz gh105/engine', "  S3CRET-VALUE \n");
t('writes ok', $res['ok'] === true, $res['error']);
t('file name is sanitised to the same bare name the old writer produced',
    $res['file'] === 'zz_gh105_engine.key');
$live = tts_keys_dir();
t('the key is in the private directory', is_file($live . '/zz_gh105_engine.key'));
t('...trimmed, exact content', trim((string) file_get_contents($live . '/zz_gh105_engine.key')) === 'S3CRET-VALUE');
t('deny rules were written beside it (.htaccess)', is_file($live . '/.htaccess'));
t('deny rules were written beside it (web.config)', is_file($live . '/web.config'));
t('NOTHING was written into the old in-tree directory',
    !is_file($legacyDir . '/zz_gh105_engine.key'));
t('tts_read_key returns the stored key', tts_read_key('zz_gh105_engine.key') === 'S3CRET-VALUE');
t('a traversal attempt is cut to a bare filename and finds nothing',
    tts_read_key('../../../config.php') === '' && tts_read_key('..') === '' && tts_read_key('') === '');
$lock = tts_write_key('zz-empty', "   ");
t('an empty key is refused, not written', $lock['ok'] === false && !is_file($live . '/zz-empty.key'));

$inTree = $root . '/cache/tcad-gh105-intree-' . getmypid();
$refused = tts_write_key('zz-intree', 'TOPSECRET', $inTree);
t('REFUSES a directory inside the web root', $refused['ok'] === false);
t('...and creates nothing there', !is_dir($inTree) && !is_file($inTree . '/zz-intree.key'));
t('...and says how to fix it', stripos($refused['error'], 'TTS_KEYS_DIR') !== false);
t('...and the browser-facing message names no server path',
    isset($refused['public']) && strpos(n($refused['public']), $root) === false
    && strpos(n($refused['public']), 'cache/tcad-gh105') === false, (string) ($refused['public'] ?? ''));

// A directory that cannot be created: a path whose parent is a regular file.
$blocker = $scratch . DIRECTORY_SEPARATOR . 'blocker';
file_put_contents($blocker, 'not a directory');
$bad = tts_write_key('zz-blocked', 'K', $blocker . DIRECTORY_SEPARATOR . 'sub');
t('an uncreatable directory is a FAILURE result, never a fake success', $bad['ok'] === false);
t('...and its public message names no path', strpos((string) $bad['public'], $scratch) === false
    && strpos((string) $bad['public'], 'tcad-gh105') === false, (string) $bad['public']);
t('...but the log-facing message does name it (an operator needs that)',
    strpos((string) $bad['error'], 'sub') !== false);

echo "\n-- 4. Reading survives the upgrade; a re-save cleans up --\n";
@mkdir($legacyDir, 0777, true);
file_put_contents($legacyDir . '/zz-gh105-old.key', "OLD-VALUE\n");
t('a key still in the old directory is found (no outage between upgrade and relocation)',
    tts_read_key('zz-gh105-old.key') === 'OLD-VALUE');
t('the current directory wins when both exist',
    (function () use ($legacyDir, $live) {
        file_put_contents($legacyDir . '/zz_gh105_engine.key', "STALE\n");
        $v = tts_read_key('zz_gh105_engine.key');
        @unlink($legacyDir . '/zz_gh105_engine.key');
        return $v === 'S3CRET-VALUE';
    })());
$save = tts_write_key('zz-gh105-old', 'NEW-VALUE');
t('re-saving that engine writes the NEW value to the private directory',
    $save['ok'] && trim((string) file_get_contents($live . '/zz-gh105-old.key')) === 'NEW-VALUE');
t('...and removes the old copy from the web tree', !is_file($legacyDir . '/zz-gh105-old.key'));
t('...and reads now return the new value', tts_read_key('zz-gh105-old.key') === 'NEW-VALUE');
$ls = tts_keys_legacy_files();
t('tts_keys_legacy_files() is empty afterwards', $ls === [], implode(',', $ls));

echo "\n-- 5. Relocation: copy, verify, delete; never overwrite --\n";
$A = $scratch . '/old'; $B = $scratch . '/new';
@mkdir($A, 0777, true);
file_put_contents($A . '/alpha.key', "ALPHA\n");
file_put_contents($A . '/stale.key', "OLD-STALE\n");
file_put_contents($A . '/dupe.key',  "SAME\n");
file_put_contents($A . '/notes.txt', "someone else's file\n");
file_put_contents($A . '/.htaccess', "# deny\n");
@mkdir($B, 0777, true);
file_put_contents($B . '/stale.key', "NEWER\n");
file_put_contents($B . '/dupe.key',  "SAME\n");

$dry = tts_keys_relocate($A, $B, true);
t('a dry run lists the files', in_array('alpha.key', $dry['moved'], true));
t('...and changes nothing', is_file($A . '/alpha.key') && !is_file($B . '/alpha.key'));

$r1 = tts_keys_relocate($A, $B);
t('relocation reports ok', $r1['ok'] === true, implode('; ', $r1['errors']));
t('alpha.key moved with identical bytes',
    in_array('alpha.key', $r1['moved'], true) && file_get_contents($B . '/alpha.key') === "ALPHA\n"
    && !is_file($A . '/alpha.key'));
t('a same-named file at the destination is NEVER overwritten (newer wins)',
    file_get_contents($B . '/stale.key') === "NEWER\n" && in_array('stale.key', $r1['stale'], true)
    && !is_file($A . '/stale.key'));
t('an identical duplicate is recognised and the original removed',
    in_array('dupe.key', $r1['duplicates'], true) && !is_file($A . '/dupe.key'));
t('a file that is not a key is left exactly where it was',
    is_file($A . '/notes.txt') && file_get_contents($A . '/notes.txt') === "someone else's file\n");
t('...so the old directory and its deny file are NOT removed from under it',
    is_dir($A) && is_file($A . '/.htaccess'));
t('deny rules were written beside the destination keys', is_file($B . '/.htaccess'));
t('no key file remains in the old directory', tts_keys_legacy_files($A) === []);

$r2 = tts_keys_relocate($A, $B);
t('a second run is a no-op and still ok',
    $r2['ok'] && $r2['moved'] === [] && $r2['stale'] === [] && $r2['duplicates'] === []);

// A destination that is empty — what a run killed mid-copy would have left if the
// copy were written in place — must never outrank an original that has content:
// treating it as "the newer copy" would delete the only good key.
$E_old = $scratch . '/e-old'; $E_new = $scratch . '/e-new';
@mkdir($E_old, 0777, true); @mkdir($E_new, 0777, true);
file_put_contents($E_old . '/torn.key', "REAL-KEY\n");
file_put_contents($E_new . '/torn.key', '');               // zero bytes
$rE = tts_keys_relocate($E_old, $E_new);
t('an EMPTY destination does not outrank an original that has content',
    $rE['ok'] && file_get_contents($E_new . '/torn.key') === "REAL-KEY\n" && in_array('torn.key', $rE['moved'], true),
    json_encode($rE));
t('...and the original was removed only after the good copy was in place',
    !is_file($E_old . '/torn.key'));
t('relocation leaves no temp file behind at the destination',
    (array) glob($E_new . '/*.tmp*') === [] && (array) glob($B . '/*.tmp*') === []);
t('a moved key keeps the restrictive mode where the platform has one',
    DIRECTORY_SEPARATOR === '\\' || (fileperms($B . '/alpha.key') & 0007) === 0,
    sprintf('%o', fileperms($B . '/alpha.key') & 0777));

// A directory holding only keys + our own deny files is removed once empty.
$C = $scratch . '/only-keys';
@mkdir($C, 0777, true);
file_put_contents($C . '/one.key', "ONE\n");
file_put_contents($C . '/.htaccess', "# deny\n");
file_put_contents($C . '/web.config', "<x/>\n");
$r3 = tts_keys_relocate($C, $B);
t('moving the last key removes the emptied old directory (keys + our own deny files only)',
    $r3['ok'] && !is_dir($C) && file_get_contents($B . '/one.key') === "ONE\n");

// Failure: destination cannot be created. The originals must stay put.
$D = $scratch . '/fail-old';
@mkdir($D, 0777, true);
file_put_contents($D . '/keep.key', "KEEP\n");
$r4 = tts_keys_relocate($D, $blocker . DIRECTORY_SEPARATOR . 'dest');
t('an uncreatable destination is reported as a failure', $r4['ok'] === false && !empty($r4['errors']));
t('...and the original key is untouched', is_file($D . '/keep.key')
    && file_get_contents($D . '/keep.key') === "KEEP\n");

$same = tts_keys_relocate($B, $B);
t('TTS_KEYS_DIR pointed at the old directory on purpose is respected (nothing moved)',
    $same['same_dir'] === true && $same['ok'] === true);

echo "\n-- 6. The migration script, as a real process, twice --\n";
$script = $root . '/sql/run_tts_keys_relocate.php';
$M_old = $scratch . '/m-old'; $M_new = $scratch . '/m-new';
@mkdir($M_old, 0777, true);
file_put_contents($M_old . '/engine_a.key', "AAA\n");
file_put_contents($M_old . '/engine_b.key', "BBB\n");
$args = [$script, '--legacy-dir=' . $M_old, '--keys-dir=' . $M_new];

$p0 = run_php(array_merge($args, ['--dry-run']));
t('--dry-run exits 0 and lists what it would do',
    $p0['code'] === 0 && strpos($p0['out'], 'WOULD MOVE') !== false, $p0['out'] . $p0['err']);
t('...and moved nothing', is_file($M_old . '/engine_a.key') && !is_dir($M_new));

$p1 = run_php($args);
t('first real run exits 0', $p1['code'] === 0, $p1['out'] . $p1['err']);
t('...both keys landed at the destination with their bytes',
    @file_get_contents($M_new . '/engine_a.key') === "AAA\n" && @file_get_contents($M_new . '/engine_b.key') === "BBB\n");
t('...and none is left in the old directory', tts_keys_legacy_files($M_old) === []);
$p2 = run_php($args);
t('second run exits 0 and reports nothing to do', $p2['code'] === 0 && strpos($p2['out'], 'nothing to do') !== false,
    $p2['out'] . $p2['err']);

// Exit non-zero when a key cannot be moved: a "successful" run that left a
// secret in the web tree is the failure this exists to end.
$F_old = $scratch . '/f-old';
@mkdir($F_old, 0777, true);
file_put_contents($F_old . '/stuck.key', "STUCK\n");
$p3 = run_php([$script, '--legacy-dir=' . $F_old, '--keys-dir=' . $blocker . DIRECTORY_SEPARATOR . 'x']);
t('a key that cannot be moved => exit 1', $p3['code'] === 1, $p3['out'] . $p3['err']);
t('...and the message tells the operator what to do', stripos($p3['err'], 'web server account') !== false
    || stripos($p3['err'], 'web server') !== false, $p3['err']);
t('...and the key is still where it was (still readable, nothing lost)', is_file($F_old . '/stuck.key'));
$p4 = run_php([$script, '--bogus']);
t('an unknown argument is refused (exit 2)', $p4['code'] === 2);

// On an install with nothing to move it must not create the default directory:
// a CLI user creating it would own it, and the web server could not read it.
$defaultDir = tts_keys_dir_for(NEWUI_ROOT);
$existedBefore = is_dir($defaultDir);
$p5 = run_php([$script]);
t('no-argument run on an install with nothing to move exits 0',
    $p5['code'] === 0 || tts_keys_legacy_files() !== [], $p5['out'] . $p5['err']);
t('...and does not create the default key directory as a side effect',
    is_dir($defaultDir) === $existedBefore, 'created ' . $defaultDir);

$srcScript = (string) file_get_contents($script);
t('the script is CLI-only before it loads anything (web-exposure guard)',
    preg_match('/^<\?php\s*\/\*\*.*?\*\/\s*if \(PHP_SAPI !== \'cli\'\)/s', $srcScript) === 1);
t('the script has no database dependency, so it is correct in ANY migration order',
    strpos($srcScript, 'db_query') === false && strpos($srcScript, 'db_fetch') === false);

echo "\n-- 7. The Status page's key row reports a key left in the web tree --\n";
$ex0 = tts_keys_exposure($live, $legacyDir);
t('nothing left behind and a private directory => ok', $ex0['severity'] === 'ok', json_encode($ex0['notes']));

// The real default is <keys dir>/tts, and the keys directory carries the DENY
// web.config / .htaccess that fe_harden_keys_dir() writes. served_dir_exposure()
// reads a web.config in a parent as "this looks like a published document root",
// so judging keys/tts directly flagged EVERY Windows install as "may be
// published" because of the very file that protects it — an amber row on every
// correct install. Reproduced with the real hardening function on a real
// directory layout, not a hand-built verdict.
$kd = $scratch . '/layout/keys';
@mkdir($kd . '/tts', 0777, true);
tts_harden_keys_dir($kd);                  // what fe_harden_keys_dir() does to the parent
t('(fixture) the keys directory really carries a deny web.config', is_file($kd . '/web.config'));
$exReal = tts_keys_exposure($kd . '/tts', $legacyDir);
t('a keys/tts directory beside our own deny files is NOT reported as published',
    $exReal['severity'] === 'ok', $exReal['severity'] . ' ' . json_encode($exReal['notes']));
t('...and the unjudged-parent behaviour is what would have flagged it (control)',
    served_dir_exposure($kd . '/tts')['suspect'] === true,
    'if this ever stops being true the workaround is unnecessary');
@mkdir($legacyDir, 0777, true);
file_put_contents($legacyDir . '/zz-gh105-left.key', "LEFT\n");
$ex1 = tts_keys_exposure($live, $legacyDir);
t('a key left in the web root => warn', $ex1['severity'] === 'warn');
t('...naming the file', $ex1['legacy_files'] === ['zz-gh105-left.key']);
t('...and the command that fixes it', strpos($ex1['remedy'], 'run_tts_keys_relocate') !== false);
$hk = health_check_keys();
t('health_check_keys() carries it (severity at least warn)',
    in_array($hk['severity'] ?? '', ['warn', 'critical'], true));
t('...with a note naming the leftover file',
    count(array_filter((array) ($hk['notes'] ?? []), function ($x) { return strpos($x, 'zz-gh105-left.key') !== false; })) === 1);
t('...and the remedy tells the operator to run the relocation',
    strpos((string) ($hk['remedy'] ?? ''), 'run_tts_keys_relocate') !== false);
t('...exposing the tts block for the CLI/API', isset($hk['tts']['legacy_files'])
    && $hk['tts']['legacy_files'] === ['zz-gh105-left.key']);
$ex2 = tts_keys_exposure($root . '/cache/zz-gh105-active', $legacyDir);
t('an active directory INSIDE the web root is critical', $ex2['severity'] === 'critical', $ex2['severity']);
@unlink($legacyDir . '/zz-gh105-left.key');
$ex3 = tts_keys_exposure($live, $legacyDir);
t('after the leftover is removed the leftover note is gone',
    $ex3['legacy_files'] === [] && count(array_filter($ex3['notes'], function ($x) { return strpos($x, 'still inside the web root') !== false; })) === 0);
$cli = (string) file_get_contents($root . '/tools/check-health.php');
t('tools/check-health.php already prints the keys block (notes + remedy), so the CLI shows it',
    strpos($cli, "\$ky['notes']") !== false && strpos($cli, "\$ky['remedy']") !== false);
$status = (string) file_get_contents($root . '/status.php');
t('status.php already renders ky.notes and ky.remedy, so the Status page shows it',
    strpos($status, 'ky.notes') !== false && strpos($status, 'ky.remedy') !== false);

echo "\n-- 8. Nothing else resolves keys/tts; Docker persistence --\n";
$offenders = [];
foreach (['api', 'inc', 'proxy', 'tools', 'sql', 'services'] as $dirName) {
    $it = @new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dirName, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
        $rel = n(substr(n($f->getPathname()), strlen($root) + 1));
        if ($rel === 'inc/tts/keys.php') { continue; }   // the one owner of the legacy path
        foreach (token_get_all((string) file_get_contents($f->getPathname())) as $tok) {
            if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && strpos($tok[1], 'keys/tts') !== false) {
                $offenders[] = $rel;
                break;
            }
        }
    }
}
t('no PHP file outside inc/tts/keys.php builds a keys/tts path (tokenised)', $offenders === [],
    implode(', ', $offenders));
$apiTts = (string) file_get_contents($root . '/api/tts.php');
t('api/tts.php stores keys through tts_write_key() only',
    strpos($apiTts, 'tts_write_key(') !== false && strpos($apiTts, 'file_put_contents') === false
    && strpos($apiTts, 'tts_keys_dir()') === false);
$engineSrc = (string) file_get_contents($root . '/inc/tts/engine.php');
t('inc/tts/engine.php no longer defines its own key directory or reader',
    strpos($engineSrc, 'function tts_keys_dir') === false && strpos($engineSrc, 'function tts_read_key') === false
    && strpos($engineSrc, 'keys.php') !== false);

$compose = (string) @file_get_contents($root . '/docker-compose.yml');
$mounts = [];
if (preg_match('/\n  app:\n(.*?)(?=\n  [a-z0-9_-]+:\n|\nvolumes:)/s', $compose, $m)
    && preg_match_all('~^\s*-\s*[a-z_]+:(/var/www[^\s:#]*)~m', $m[1], $mm)) {
    $mounts = array_map('rtrim', $mm[1], array_fill(0, count($mm[1]), '/'));
}
$under = function ($path) use ($mounts) {
    foreach ($mounts as $mt) { if ($path === $mt || strpos($path, $mt . '/') === 0) { return true; } }
    return false;
};
t('parsed the Docker app mounts', count($mounts) >= 4, implode(',', $mounts));
$dockerNew = tts_keys_dir_for('/var/www/html', false, null);   // the image's real layout
t('Docker: the new TTS key directory (' . $dockerNew . ') is under a mounted volume', $under($dockerNew),
    'mounts: ' . implode(', ', $mounts));
t('Docker: the OLD directory (/var/www/html/keys/tts) was NOT on any volume — this is the data-loss gap '
    . '`docker compose up -d --build` used to cause', !$under('/var/www/html/keys/tts'),
    'if this ever becomes true the change was unnecessary for Docker');

} finally {
    rrm($scratch);
    foreach (['zz-gh105-old.key', 'zz-gh105-left.key', 'zz_gh105_engine.key', 'zz-intree.key'] as $f) {
        @unlink($legacyDir . '/' . $f);
    }
    rrm($root . '/cache/tcad-gh105-intree-' . getmypid());
    // Remove the fixture directories we created, but only if they are now empty.
    $left = array_diff((array) @scandir($legacyDir), ['.', '..', '.htaccess', 'web.config']);
    if (is_dir($legacyDir) && empty($left)) { rrm($legacyDir); }
    if ($createdKeysParent && is_dir($root . '/keys') && count((array) @scandir($root . '/keys')) <= 2) {
        @rmdir($root . '/keys');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
