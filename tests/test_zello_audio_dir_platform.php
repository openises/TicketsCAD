<?php
/**
 * Gate: Zello voice recordings must not sit in a directory a web server
 * publishes — round 2 of GHSA-x9x6-w4fg-pmcc, reported by the SAME person
 * (@rjonesbsink / Ron) who found round 1.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────────
 *
 * Round 2 (2026-08-13) moved recordings from cache/zello-audio/ (inside the
 * web root) to dirname(NEWUI_ROOT) . '/zello-audio' — "a sibling of the app
 * root" — on the stated belief that this was "the same move already made
 * for BACKUP_DIR and FE_KEYS_DIR". It was not: both of those had already
 * moved past sibling-of-root to a platform-aware default (%ProgramData% on
 * Windows) after their OWN sibling-of-root mistakes shipped and were
 * reported. Round 2 repeated the exact mistake this codebase had already
 * paid for twice.
 *
 * Ron reported it directly: upgrading 4.2.14 -> 4.2.17 and re-running the
 * migration moved 210 recordings from a local, unfirewalled port (8089) to
 * C:\inetpub\wwwroot\zello-audio — the IIS Default Web Site's root, bound
 * to port 80, which DOES have an inbound firewall rule. The fix made his
 * install's exposure WORSE, not better: reachable from the network where it
 * had only been reachable from the box itself before.
 *
 * ── WHAT THIS FILE ASSERTS, THAT test_zello_rbac_audio.php DOES NOT ───────
 *
 *   1. The Windows default is never inside inetpub\wwwroot, on the exact
 *      layout reported, tested from ANY platform (the $windows override
 *      exists specifically so this runs on Linux CI too — a test that can
 *      only see its own platform's answer is how this shipped in the first
 *      place).
 *   2. served_dir_exposure() grades round 2's sibling location CERTAIN
 *      exposure for that layout — not a heuristic, not "suspect".
 *   3. The POSIX default is unchanged (it was correct there).
 *   4. Recordings already moved to round 2's location (Ron's exact state)
 *      are still found via zello_audio_resolve() — nothing orphaned.
 *   5. The deny files written beside recordings are this project's
 *      standardised shape (Request Filtering, never URL Authorization).
 *   6. THE END-TO-END REPRODUCTION: the real migration script, run twice in
 *      a subprocess against a directory tree shaped like Ron's report (a
 *      round-1 legacy directory AND a round-2 sibling directory both
 *      holding recordings), (a) moves everything to the new platform-aware
 *      destination, (b) writes deny files at the destination, and (c)
 *      writes deny files at BOTH sources too, so anything a failed move
 *      leaves behind is still fenced.
 *
 * Usage: php tests/test_zello_audio_dir_platform.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/served-dir.php';
require_once __DIR__ . '/../inc/zello_audio_dir.php';

$passed = 0;
$failed = 0;
function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) { echo "[PASS] $label\n"; $passed++; }
    else { echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n"; $failed++; }
}

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$n = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };

echo "=== Zello audio directory: platform-correct location, nothing orphaned ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. The Windows default never lands in a site root --\n";

$winInstalls = [
    'C:\\inetpub\\wwwroot\\TicketsV4',   // Ron's exact reported layout
    'C:\\inetpub\\wwwroot\\ticketscad',
    'C:\\xampp\\htdocs\\newui',
    'D:\\sites\\ticketscad',
];
foreach ($winInstalls as $app) {
    $d  = zello_audio_dir($app, true);
    $dN = $n($d);
    test("Windows zello-audio default for $app is not inside inetpub\\wwwroot",
        stripos($dN, '/inetpub/wwwroot') === false, 'got ' . $d);
    test("Windows zello-audio default for $app is not inside xampp\\htdocs",
        stripos($dN, '/xampp/htdocs') === false, 'got ' . $d);
    test("Windows zello-audio default for $app is not inside the application tree",
        strpos($dN . '/', $n($app) . '/') !== 0, 'got ' . $d);
    test("Windows zello-audio default for $app is not round 2's sibling-of-install path",
        $dN !== $n(zello_audio_dir_sibling_legacy($app, true)), 'got ' . $d);
    test("Windows zello-audio default for $app is an absolute Windows path",
        preg_match('/^[A-Za-z]:\\\\/', $d) === 1, 'got ' . $d);
}
test('the Windows zello-audio default is under %ProgramData%\\TicketsCAD, same base as backups/keys',
    stripos($n(zello_audio_dir('C:\\inetpub\\wwwroot\\TicketsV4', true)),
            '/programdata/ticketscad/zello-audio') !== false,
    zello_audio_dir('C:\\inetpub\\wwwroot\\TicketsV4', true));

// The EXACT directory Ron's report was about.
test("round 2's rule put recordings in C:\\inetpub\\wwwroot\\zello-audio — the reported state",
    $n(zello_audio_dir_sibling_legacy('C:\\inetpub\\wwwroot\\TicketsV4', true))
    === 'C:/inetpub/wwwroot/zello-audio',
    zello_audio_dir_sibling_legacy('C:\\inetpub\\wwwroot\\TicketsV4', true));

$wx = served_dir_exposure('C:\\inetpub\\wwwroot\\zello-audio');
$sd = getenv('SystemDrive');
$sd = ($sd !== false && trim((string) $sd) !== '') ? rtrim((string) $sd, '\\/') : 'C:';
if (strcasecmp($sd, 'C:') === 0) {
    test('…and that directory is graded CERTAIN exposure, not merely suspect',
        $wx['served'] === true && $wx['state'] === 'in_default_site_root',
        'state=' . $wx['state']);
} else {
    echo "[SKIP] %SystemDrive% is not C: on this machine — the literal-path grading is not exercised\n";
}

test('the platform default is deterministic — same input, same answer',
    zello_audio_dir('C:\\inetpub\\wwwroot\\TicketsV4', true)
    === zello_audio_dir('C:\\inetpub\\wwwroot\\TicketsV4', true));
test('the platform default does not consult the filesystem',
    preg_match('/function zello_audio_dir\(.*?\n\}/s',
        (string) file_get_contents($root . '/inc/zello_audio_dir.php'), $m) === 1
    && strpos($m[0], 'is_dir') === false
    && strpos($m[0], 'file_exists') === false
    && strpos($m[0], 'realpath') === false,
    'the default is what a fresh install gets; only resolution/discovery reads the disk');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. The POSIX default is unchanged (it was right there) --\n";
test('/var/www/newui  → /var/www/zello-audio',
    zello_audio_dir('/var/www/newui', false) === '/var/www/zello-audio');
test('/srv/ticketscad → /srv/zello-audio',
    zello_audio_dir('/srv/ticketscad', false) === '/srv/zello-audio');
test('on POSIX the default IS round 2\'s sibling location — a no-op upgrade for every Linux/Docker install',
    zello_audio_dir('/var/www/newui', false) === zello_audio_dir_sibling_legacy('/var/www/newui', false));

test('zello_audio_dir() on THIS machine is outside the application tree',
    strpos($n(zello_audio_dir()) . '/', $n($root) . '/') !== 0,
    'zello_audio_dir()=' . zello_audio_dir());

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. THE CRITICAL ONE: recordings already moved by round 2 are still found --\n";
// Ron's exact state: nothing at the new ProgramData default yet, real files
// sitting at round 2's sibling location. zello_audio_resolve() must still
// serve them (never orphan what an install already has), and the CLEAR
// requirement Eric added: they must not be *reachable over HTTP* — that is
// section 5/6 below, this section is only "can the app still find them".

$sandbox = sys_get_temp_dir() . '/tcad-zaudio-' . getmypid();
$roundTwoDir = $sandbox . '/round2-sibling';
$freshDir    = $sandbox . '/programdata-fresh';
@mkdir($roundTwoDir, 0777, true);
@mkdir($freshDir, 0777, true);

if (!is_dir($roundTwoDir) || !is_dir($freshDir)) {
    echo "[SKIP] filesystem sandbox could not be created — the discovery path is not exercised\n";
} else {
    $clipName = 'zar-test-' . getmypid() . '.ogg';
    @file_put_contents($roundTwoDir . '/' . $clipName, 'fake-ogg-bytes');

    // zello_audio_resolve() always walks THIS install's real three locations
    // (zello_audio_dir(), zello_audio_dir_sibling_legacy(), zello_audio_dir_legacy()),
    // not the sandbox — so exercise the same lookup chain the app actually
    // uses by dropping the fixture into the REAL sibling-legacy location for
    // this install, not an arbitrary sandbox path.
    $realSibling = zello_audio_dir_sibling_legacy();
    @mkdir($realSibling, 0777, true);
    $placed = @copy($roundTwoDir . '/' . $clipName, $realSibling . '/' . $clipName);
    if ($placed) {
        $resolved = zello_audio_resolve($clipName);
        test('a recording sitting only at round 2\'s sibling location is still resolved',
            $resolved !== null && $n($resolved) === $n($realSibling . '/' . $clipName),
            'resolved to ' . var_export($resolved, true));
        @unlink($realSibling . '/' . $clipName);
    } else {
        echo "[SKIP] could not place a fixture in the real sibling-legacy directory\n";
    }
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. The deny files beside recordings are the standardised shape --\n";

$fence = $sandbox . '/fence';
@mkdir($fence, 0777, true);
if (is_dir($fence)) {
    zello_audio_harden_dir($fence);
    $wc = (string) @file_get_contents($fence . '/web.config');
    $ht = (string) @file_get_contents($fence . '/.htaccess');

    test('a web.config is written beside the recordings', $wc !== '');
    test('it denies FILES, not just the listing (the .ogg/.webm itself)',
        strpos($wc, '<fileExtensions allowUnlisted="false" />') !== false, $wc);
    test('it keeps directory browsing off as the independent second stop',
        strpos($wc, '<directoryBrowse enabled="false" />') !== false, $wc);
    test('it uses Request Filtering, never URL Authorization (500.19 trap)',
        strpos($wc, '<authorization') === false && strpos($wc, 'requestFiltering') !== false, $wc);
    test('it is well-formed XML', @simplexml_load_string($wc) !== false);
    test('an .htaccess is written too, because IIS is not the only server',
        $ht !== '' && strpos($ht, 'RewriteRule .* - [F,L]') !== false, $ht);
    test('an existing deny file is never overwritten', (function () use ($fence) {
        @file_put_contents($fence . '/web.config', 'MINE');
        zello_audio_harden_dir($fence);
        return trim((string) @file_get_contents($fence . '/web.config')) === 'MINE';
    })());
} else {
    echo "[SKIP] filesystem sandbox unavailable — the deny-file writer is not exercised\n";
}

// The writer must be unconditional: a recording has no legitimate
// reachable-over-HTTP state, same reasoning as encryption keys.
$zadSrc = (string) file_get_contents($root . '/inc/zello_audio_dir.php');
test('zello_audio_harden_dir() fences unconditionally, not only when it looks published',
    preg_match('/served_dir_harden\(\s*\$dir\s*,[^)]*true\s*\)/', $zadSrc) === 1, $zadSrc);
test('zello_audio_write_dir() hardens the directory it hands back, on every call — not just at creation',
    preg_match('/function zello_audio_write_dir.*?\n\}/s', $zadSrc, $mw) === 1
    && substr_count($mw[0], 'zello_audio_harden_dir(') >= 2,
    'expected the primary path AND the fallback path to both harden');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. END-TO-END: the real migration script reproduces Ron's fix --\n";
// A full subprocess run of sql/run_zello_audio_relocate.php against a real
// NEWUI_ROOT-shaped tree (a temp app root with cache/zello-audio/ AND a
// sibling zello-audio/ beside it, both holding recordings — Ron's exact
// mixed state after two rounds of a partial fix). Proves the script moves
// everything to the destination AND hardens every directory it touched,
// not just what the unit-level assertions above imply it should do.

$appRoot = $sandbox . '/e2e-app';
@mkdir($appRoot . '/cache/zello-audio', 0777, true);
@mkdir(dirname($appRoot) . '/zello-audio', 0777, true); // sibling of $appRoot

$roundOneClip = 'round1-' . getmypid() . '.ogg';
$roundTwoClip = 'round2-' . getmypid() . '.webm';
@file_put_contents($appRoot . '/cache/zello-audio/' . $roundOneClip, 'round-1-bytes');
@file_put_contents(dirname($appRoot) . '/zello-audio/' . $roundTwoClip, 'round-2-bytes');

$php = PHP_BINARY ?: 'php';
$configStub = $sandbox . '/e2e-config.php';
// A minimal config.php equivalent: define NEWUI_ROOT and load only what the
// relocation script actually requires, so this drives the REAL script
// against a REAL (if synthetic) root — not a re-implementation of its logic.
@file_put_contents($configStub, '<?php define(\'NEWUI_ROOT\', ' . var_export($appRoot, true) . ');');

$realScript = (string) file_get_contents($root . '/sql/run_zello_audio_relocate.php');
$e2eScript = $sandbox . '/e2e-relocate.php';
// Swap the real config.php require for the stub; everything else runs
// verbatim, including served-dir.php's real path logic.
$e2eSrc = str_replace(
    "require __DIR__ . '/../config.php';",
    'require ' . var_export($configStub, true) . ';'
    . ' require ' . var_export($root . '/inc/served-dir.php', true) . ';',
    $realScript
);
$e2eSrc = str_replace(
    "require __DIR__ . '/../inc/zello_audio_dir.php';",
    'require ' . var_export($root . '/inc/zello_audio_dir.php', true) . ';',
    $e2eSrc
);
@file_put_contents($e2eScript, $e2eSrc);

$out = @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($e2eScript) . ' 2>&1');
$expectedDest = zello_audio_dir($appRoot, DIRECTORY_SEPARATOR === '\\');

if ($out === null) {
    echo "[SKIP] could not run the migration script as a subprocess\n";
} else {
    test('the migration script ran without a fatal error',
        stripos($out, 'Fatal error') === false && stripos($out, 'Uncaught') === false, $out);
    test('both round-1 and round-2 clips reached the destination',
        is_file($expectedDest . '/' . $roundOneClip) && is_file($expectedDest . '/' . $roundTwoClip),
        'destination=' . $expectedDest . "\noutput:\n" . $out);
    test('the destination was hardened (deny files present)',
        is_file($expectedDest . '/web.config') && is_file($expectedDest . '/.htaccess'),
        'destination=' . $expectedDest);
    test('the round-1 source (cache/zello-audio, inside the web root) was hardened too',
        is_file($appRoot . '/cache/zello-audio/web.config'));
    test('the round-2 source (the sibling directory) was hardened too — the exact gap Ron reported',
        is_file(dirname($appRoot) . '/zello-audio/web.config'));
    test('re-running the script is a clean no-op (idempotent, 0 failures)',
        (function () use ($php, $e2eScript) {
            $out2 = @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($e2eScript) . ' 2>&1');
            return is_string($out2) && stripos($out2, 'Fatal error') === false
                && preg_match('/Total: moved 0 file\(s\); \d+ already present; 0 failed\./', $out2) === 1;
        })());
}

// $expectedDest is computed via served_dir_program_data(), which reads the
// REAL %ProgramData% on THIS machine regardless of the synthetic $appRoot
// above (by design — it matches backups/keys, whose ProgramData base does
// not depend on the app root either). So section 5 wrote real files under
// the real C:\ProgramData\TicketsCAD\zello-audio on whatever machine runs
// this test; clean those up specifically, not just the sandbox.
if (isset($expectedDest) && isset($roundOneClip) && isset($roundTwoClip)) {
    foreach ([$roundOneClip, $roundTwoClip, 'web.config', '.htaccess'] as $f) {
        @unlink($expectedDest . '/' . $f);
    }
    @rmdir($expectedDest);
}

// ─────────────────────────────────────────────────────────────────────
// Cleanup
$rrmdir = function (string $dir) use (&$rrmdir) {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = $dir . '/' . $f;
        is_dir($p) ? $rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
};
$rrmdir($sandbox);

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
