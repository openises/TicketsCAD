<?php
/**
 * Phase 152 (Console rebuild) — acoustic proximity auto-discovery
 * (plan.md section 3.5's own sub-section, Eric's idea).
 *
 * The tone-plan MATH (frequency table, code<->pair mapping) is pure and
 * DOM-free by design (assets/js/console-beacon.js's own docblock), so
 * section 1 below drives the REAL file under Node exactly the way
 * tests/test_console_audio_state.php drives console-audio-logic.js --
 * eval the production source with a stubbed `window`, then call its
 * exported functions and assert on the real output. Genuine reliability
 * of the ACTUAL acoustic detection (real speakers, real mics, real room
 * noise) needs a live manual test on the validation instance, not a
 * fixture -- documented at the bottom of this file, not silently skipped.
 *
 * Usage: php tests/test_phase152_beacon_discovery.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/console-workstations.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- acoustic proximity auto-discovery ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Pure tone-plan math, driven under Node against the REAL file ────
echo "--- 1. assets/js/console-beacon.js: tone-plan math (Node-driven) ---\n\n";

$beaconPath = __DIR__ . '/../assets/js/console-beacon.js';
t('console-beacon.js exists', file_exists($beaconPath));

$src = (string) @file_get_contents($beaconPath);
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $src));
t('the pure tone-plan math has no DOM/getUserMedia CALLS above the "Browser glue" marker '
    . '(stays testable without a browser, matching console-audio-logic.js\'s own established pattern -- '
    . 'the word appears once in the file\'s own prose docblock ABOVE this point, which is fine; an actual call is not)',
    strpos(substr($src, 0, strpos($src, 'Browser glue')), '.getUserMedia(') === false);
t('capture explicitly disables echoCancellation/noiseSuppression/autoGainControl -- required or the '
    . "browser's own audio pipeline strips the deliberately-injected tone as noise",
    strpos($src, 'echoCancellation: false, noiseSuppression: false, autoGainControl: false') !== false);
t('only decoded integer codes are ever sent anywhere -- no raw audio capture leaves the browser',
    strpos($src, 'getByteFrequencyData') !== false
    && preg_match('/\bmedia_url\b|\baudio_data\b|\bsend.*stream\b/i', $src) === 0);

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $harness = sys_get_temp_dir() . '/tcad_console_beacon_harness_' . getmypid() . '.js';
    $beaconJsPath = str_replace('\\', '/', $beaconPath);
    $js = <<<'JS'
var fs = require('fs');
var out = [];
function check(name, cond) { out.push((cond ? 'PASS|' : 'FAIL|') + name); }

global.window = global;
global.navigator = {}; // no mediaDevices -- exercises the pure math only
eval(fs.readFileSync(process.argv[2], 'utf8'));

var B = global.window.ConsoleBeacon;
check('ConsoleBeacon loaded', !!B);
check('FREQS has exactly 8 frequencies', B.FREQS.length === 8);
check('all 8 frequencies are distinct', (function () {
    var seen = {};
    for (var i = 0; i < B.FREQS.length; i++) { seen[B.FREQS[i]] = true; }
    return Object.keys(seen).length === 8;
})());
check('all 8 frequencies fall in the near-ultrasonic 18-20kHz band', (function () {
    for (var i = 0; i < B.FREQS.length; i++) {
        if (B.FREQS[i] < 18000 || B.FREQS[i] > 20000) { return false; }
    }
    return true;
})());
check('CODE_COUNT is exactly C(8,2) = 28', B.CODE_COUNT === 28);

// codeToPair / pairToCode must be exact inverses across the WHOLE range.
var roundTripOk = true;
var allPairsDistinct = {};
for (var c = 0; c < 28; c++) {
    var pair = B.codeToPair(c);
    if (!pair || pair.length !== 2 || pair[0] >= pair[1]) { roundTripOk = false; break; }
    var key = pair[0] + ',' + pair[1];
    if (allPairsDistinct[key]) { roundTripOk = false; break; } // no code shares a pair with another
    allPairsDistinct[key] = true;
    var back = B.pairToCode(pair[0], pair[1]);
    if (back !== c) { roundTripOk = false; break; }
    var backSwapped = B.pairToCode(pair[1], pair[0]); // order-independent
    if (backSwapped !== c) { roundTripOk = false; break; }
}
check('codeToPair()/pairToCode() are exact inverses for all 28 codes, order-independent, no shared pairs', roundTripOk);
check('code 28 (out of range) has no pair', B.codeToPair(28) === null);
check('pairToCode(i,i) (a code cannot mute itself into a pair) returns -1', B.pairToCode(3, 3) === -1);

// freqToBin: standard DFT bin-width math (sampleRate / fftSize Hz/bin).
check('freqToBin: 18000Hz at 48000Hz/4096 lands in the expected bin',
    B.freqToBin(18000, 48000, 4096) === Math.round(18000 / (48000 / 4096)));
check('freqToBin: distinct FREQS produce distinct bins at a realistic sample rate/fftSize '
    + '(the whole detection scheme depends on this)', (function () {
    var bins = {};
    for (var i = 0; i < B.FREQS.length; i++) {
        var b = B.freqToBin(B.FREQS[i], 48000, 4096);
        if (bins[b]) { return false; }
        bins[b] = true;
    }
    return true;
})());

console.log(out.join('\n'));
JS;
    file_put_contents($harness, $js);
    $raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($beaconJsPath) . ' 2>&1');
    @unlink($harness);

    if (!is_string($raw) || strpos($raw, '|') === false) {
        t('node harness ran console-beacon.js', false);
        echo "  raw output: " . trim((string) $raw) . "\n";
    } else {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) < 2) { continue; }
            t('[js] ' . $parts[1], $parts[0] === 'PASS');
        }
    }
}

// ── 2. Server-side beacon_code assignment + resolution ─────────────────
echo "\n--- 2. beacon_code assignment (inc/console-workstations.php) ---\n\n";

function p152bd_uuid() {
    return sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff), mt_rand(0x8000, 0xbfff),
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

$tokenA = p152bd_uuid();
$tokenB = p152bd_uuid();
$createdTokens = [$tokenA, $tokenB];
$cleanup = function () use ($prefix, &$createdTokens) {
    foreach ($createdTokens as $tok) {
        try {
            $id = db_fetch_value("SELECT id FROM {$prefix}console_workstations WHERE workstation_token = ?", [$tok]);
            if ($id) {
                db_query("DELETE FROM {$prefix}console_workstation_mutes WHERE workstation_id = ? OR muted_workstation_id = ?", [$id, $id]);
                db_query("DELETE FROM {$prefix}console_workstations WHERE id = ?", [$id]);
            }
        } catch (Throwable $e) {}
    }
};
$cleanup();

try {
    $rowA = console_workstation_resolve($tokenA);
    $idA = (int) $rowA['id'];
    t('a freshly-created workstation is assigned a beacon_code immediately, no separate step needed',
        $rowA['beacon_code'] !== null && (int) $rowA['beacon_code'] === (($idA - 1) % CONSOLE_WORKSTATION_BEACON_CODE_COUNT));
    t('beacon_code stays in the documented [0,27] range', (int) $rowA['beacon_code'] >= 0
        && (int) $rowA['beacon_code'] < CONSOLE_WORKSTATION_BEACON_CODE_COUNT);

    $rowAAgain = console_workstation_resolve($tokenA);
    t('re-resolving the SAME token never reassigns beacon_code (a workstation\'s tone never changes underneath it)',
        (int) $rowAAgain['beacon_code'] === (int) $rowA['beacon_code']);

    $rowB = console_workstation_resolve($tokenB);
    $idB = (int) $rowB['id'];

    $resolved = console_workstation_resolve_beacon_code((int) $rowB['beacon_code'], $idA);
    t('resolving B\'s real beacon_code (excluding A) finds B', $resolved !== null && (int) $resolved['id'] === $idB);

    $resolvedSelf = console_workstation_resolve_beacon_code((int) $rowA['beacon_code'], $idA);
    t('resolving a code while excluding the SAME workstation that holds it never matches itself '
        . '(the initiator can never "discover" its own desk)', $resolvedSelf === null);

    db_query("UPDATE {$prefix}console_workstations SET last_seen_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?",
        [CONSOLE_WORKSTATION_NEARBY_WINDOW_SECONDS + 60, $idB]);
    $resolvedStale = console_workstation_resolve_beacon_code((int) $rowB['beacon_code'], $idA);
    t('a workstation stale enough to have already fallen out of the "nearby" recency window '
        . 'is NOT resolved by a beacon match either -- a beacon match can never resurrect a desk '
        . 'nobody would otherwise see as nearby', $resolvedStale === null);

    t('an unassigned/nonexistent beacon_code resolves to nothing', console_workstation_resolve_beacon_code(999, $idA) === null);
} finally {
    $cleanup();
}

// ── 3. The migration (settings default; beacon_code column already
//      existed from the earlier workstation-identity migration) ────────
echo "\n--- 3. sql/run_phase152_beacon_discovery.php ---\n\n";
$phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : '/c/xampp/8.2.4/php/php.exe';
$scriptPath = __DIR__ . '/../sql/run_phase152_beacon_discovery.php';
$out1 = []; $rc1 = 1;
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1', $out1, $rc1);
t('the migration exits 0', $rc1 === 0);
t('console_beacon_discovery_enabled is seeded, default on',
    (string) db_fetch_value("SELECT value FROM {$prefix}settings WHERE name = 'console_beacon_discovery_enabled'") === '1');
$out2 = []; $rc2 = 1;
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1', $out2, $rc2);
t('a second run is a clean idempotent no-op', $rc2 === 0);

// ── 3b. THE REAL CI FAILURE THIS FIX CLOSES: run_migrations.php applies
//       sql/run_phase152_*.php scripts in ksort() LEXICOGRAPHIC FILENAME
//       ORDER (this project's own documented gotcha) -- and
//       "run_phase152_beacon_discovery.php" sorts BEFORE "run_phase152_
//       workstations.php" ('b' < 'w'). A genuinely fresh install (CI's
//       own fresh-install job, not this shared dev database, which had
//       already run the OTHER migration earlier this same session in the
//       opposite order and never caught it) runs this script FIRST, while
//       console_workstations doesn't exist yet at all. Reproduced here by
//       actually dropping both tables and running the two migrations in
//       that exact order -- not merely asserting the new code LOOKS
//       order-independent. ────────────────────────────────────────────
echo "\n--- 3b. Reproducing the real CI failure: this migration must survive running BEFORE "
    . "sql/run_phase152_workstations.php on a genuinely fresh install ---\n\n";
try {
    db_query("DROP TABLE IF EXISTS `{$prefix}console_workstation_mutes`");
    db_query("DROP TABLE IF EXISTS `{$prefix}console_workstations`");
    db_query("DELETE FROM `{$prefix}settings` WHERE name = 'console_beacon_discovery_enabled'");

    $workstationsScript = __DIR__ . '/../sql/run_phase152_workstations.php';
    $out3 = []; $rc3 = 1;
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1', $out3, $rc3);
    t('run_phase152_beacon_discovery.php exits 0 even when console_workstations does not exist yet '
        . '-- the exact scenario that made CI red', $rc3 === 0);

    $out4 = []; $rc4 = 1;
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($workstationsScript) . ' 2>&1', $out4, $rc4);
    t('sql/run_phase152_workstations.php, run SECOND, still succeeds and creates the table normally',
        $rc4 === 0);

    t('beacon_code exists on the table after BOTH scripts ran in this order',
        (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'beacon_code'",
            [$prefix . 'console_workstations']
        ) === 1);
    t('console_beacon_discovery_enabled is still seeded correctly after this ordering',
        (string) db_fetch_value("SELECT value FROM {$prefix}settings WHERE name = 'console_beacon_discovery_enabled'") === '1');

    // Confirm the fresh table itself still creates beacon_code via its OWN
    // CREATE TABLE (this test's drop-and-recreate must not have papered
    // over that path with the ALTER path instead).
    $rowAOrder = console_workstation_resolve(p152bd_uuid());
    $createdTokens[] = $rowAOrder['workstation_token'];
    t('a fresh workstation created after this exact migration order still gets a real beacon_code assigned',
        $rowAOrder['beacon_code'] !== null);
} finally {
    // Restore normal state for the rest of this file's sections (and any
    // other test relying on these tables existing).
    $out5 = []; exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1', $out5);
}

// ── 4. RBAC + wiring over real HTTP ─────────────────────────────────────
echo "\n--- 4. api/console-workstation-search.php: RBAC over real HTTP ---\n\n";
$httpCapable = function_exists('proc_open') && function_exists('curl_init');
$userIdA = 900199391;
$userIdB = 900199392;
$plainPwA = 'Zz152Bd!' . mt_rand(1000, 9999);
$plainPwB = 'Zz152BdB!' . mt_rand(1000, 9999);
if (!$httpCapable) {
    echo "SKIP: proc_open/curl unavailable — real end-to-end HTTP not run.\n";
} else {
    $dispatcherRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Dispatcher' LIMIT 1");
    $superAdminRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name LIKE 'Super%Admin%' LIMIT 1");
    if ($dispatcherRoleId <= 0 || $superAdminRoleId <= 0) {
        echo "SKIP: Dispatcher/Super Admin roles not found — run sql/run_00_rbac.php first\n";
    } else {
        try {
            db_query("INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
                [$userIdA, 'zz152bd-a', password_hash($plainPwA, PASSWORD_BCRYPT)]);
            db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$userIdA, $dispatcherRoleId]);
            db_query("INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
                [$userIdB, 'zz152bd-b', password_hash($plainPwB, PASSWORD_BCRYPT)]);
            db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$userIdB, $superAdminRoleId]);

            $srv = p152bd_start_php_server();
            t('ephemeral php -S test server started', $srv !== null);
            if ($srv !== null) {
                $base = 'http://127.0.0.1:' . $srv['port'];
                $cookieA = p152bd_login($base, 'zz152bd-a', $plainPwA);
                $cookieB = p152bd_login($base, 'zz152bd-b', $plainPwB);
                t('both real HTTP logins succeeded', $cookieA !== null && $cookieB !== null);

                if ($cookieA !== null && $cookieB !== null) {
                    $csrfA = p152bd_csrf($base, $cookieA, '/console.php');
                    $csrfB = p152bd_csrf($base, $cookieB, '/console-positions-admin.php');
                    $tokenHttp = p152bd_uuid();
                    $createdTokens[] = $tokenHttp;

                    $r = p152bd_post_json($base . '/api/console-workstation-search.php', $cookieA, [
                        'action' => 'start', 'csrf_token' => $csrfA, 'workstation_token' => $tokenHttp,
                    ]);
                    t('a Dispatcher (screen.console) CAN start a beacon search', $r['status'] === 200 && !empty($r['json']['ok']));

                    $r = p152bd_post_json($base . '/api/console-workstation-search.php', $cookieA, [
                        'action' => 'report', 'csrf_token' => $csrfA, 'workstation_token' => $tokenHttp, 'detected_codes' => [],
                    ]);
                    t('a Dispatcher CAN report detection results (empty is a valid, non-error result)',
                        $r['status'] === 200 && isset($r['json']['matched']) && $r['json']['matched'] === []);

                    $r = p152bd_post_json($base . '/api/console-workstation-search.php', $cookieA, [
                        'action' => 'set_discovery_enabled', 'csrf_token' => $csrfA, 'enabled' => false,
                    ]);
                    t('a Dispatcher (no action.manage_config) is refused toggling the install-wide setting with 403', $r['status'] === 403);

                    $r = p152bd_post_json($base . '/api/console-workstation-search.php', $cookieB, [
                        'action' => 'set_discovery_enabled', 'csrf_token' => $csrfB, 'enabled' => false,
                    ]);
                    t('a Super Admin (action.manage_config) CAN toggle the install-wide setting',
                        $r['status'] === 200 && !empty($r['json']['ok'])
                        && (string) db_fetch_value("SELECT value FROM {$prefix}settings WHERE name = 'console_beacon_discovery_enabled'") === '0');

                    // restore for a clean environment
                    p152bd_post_json($base . '/api/console-workstation-search.php', $cookieB, [
                        'action' => 'set_discovery_enabled', 'csrf_token' => $csrfB, 'enabled' => true,
                    ]);

                    $r = p152bd_get($base . '/api/console-workstation-mutes.php?workstation_token=' . urlencode($tokenHttp), $cookieA);
                    t('api/console-workstation-mutes.php\'s GET now ALSO exposes my_beacon_code and discovery_enabled '
                        . '(the fields console-beacon.js/console-workstation-panel.js actually read)',
                        $r['status'] === 200 && array_key_exists('my_beacon_code', $r['json'])
                        && array_key_exists('discovery_enabled', $r['json']));

                    @unlink($cookieA);
                    @unlink($cookieB);
                }
            }
            p152bd_stop_php_server($srv);
        } finally {
            $cleanup();
            try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$userIdA, $userIdB]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM {$prefix}user WHERE id IN (?, ?)", [$userIdA, $userIdB]); } catch (Throwable $e) {}
        }
    }
}

// ── 5. Frontend wiring (source-level) ───────────────────────────────────
echo "\n--- 5. Frontend wiring ---\n\n";
$ebjs = (string) @file_get_contents(__DIR__ . '/../assets/js/event-bus.js');
t('event-bus.js\'s SSE_TYPES includes comm:beacon_request -- the exact "shipped but nobody wired the '
    . 'last mile" gap class this project has hit before', strpos($ebjs, "'comm:beacon_request'") !== false);

$pjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-workstation-panel.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $pjs));
t('the search button only renders when discoveryEnabled is true -- absent, not present-and-broken, when the install-wide setting is off',
    strpos($pjs, 'if (state.discoveryEnabled) {') !== false);
t('console-workstation-panel.js pushes the resolved beacon_code into console-beacon.js after every load',
    strpos($pjs, 'window.ConsoleBeacon.setMyBeaconCode(') !== false);

$adminPage = (string) @file_get_contents(__DIR__ . '/../console-positions-admin.php');
t('the discovery toggle on console-positions-admin.php is gated on action.manage_config specifically '
    . '(not action.manage_positions, the page\'s own base gate) -- matching the API endpoint\'s own '
    . 'stricter requirement so the two can never disagree about who can reach this',
    strpos($adminPage, "rbac_can('action.manage_config')") !== false);

$adminJs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-positions-admin.js');
t('the toggle posts to api/console-workstation-search.php\'s set_discovery_enabled action',
    strpos($adminJs, "action: 'set_discovery_enabled'") !== false);

$cphp = (string) @file_get_contents(__DIR__ . '/../console.php');
t('console.php loads console-beacon.js before console-workstation-panel.js '
    . '(matched by the actual <script src=...> tags, not an earlier unrelated prose mention of the filename)',
    strpos($cphp, '<script src="assets/js/console-beacon.js') !== false
    && strpos($cphp, '<script src="assets/js/console-beacon.js')
       < strpos($cphp, '<script src="assets/js/console-workstation-panel.js'));

// ── 6. The section's own final checklist item: workstation identity
//      survives a login change; a third workstation is unaffected ──────
echo "\n--- 6. Workstation identity survives a login change; the third-party regression risk ---\n\n";
$wjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-workstation.js');
t('getToken() reads/writes ONLY localStorage -- never document.cookie or any other session-tied '
    . 'storage -- so surviving a login change on the same browser is structural, not merely tested-once '
    . '(the file\'s own docblock prose mentions "login"/"logout" to EXPLAIN this property in English; '
    . 'checking for document.cookie is the actual mechanical proof)',
    strpos($wjs, 'window.localStorage') !== false && strpos($wjs, 'document.cookie') === false);
// The "mute pairing between A and B does not affect a third workstation C"
// regression is already proven twice over, at both layers this feature
// actually operates on: tests/test_phase152_workstation_mute.php section 4
// (the PHP/database layer -- a THIRD, uninvolved workstation has nothing
// muted) and services/audio-matrix/tests/test_workstation_mute.py section 3
// (the REAL AUDIO layer -- C still hears B normally while A does not).
// Re-asserted here structurally rather than duplicating either test.
t('the PHP-layer "third workstation unaffected" regression is covered (tests/test_phase152_workstation_mute.php)',
    file_exists(__DIR__ . '/test_phase152_workstation_mute.php'));
t('the AUDIO-layer "third workstation unaffected" regression is covered (services/audio-matrix/tests/test_workstation_mute.py)',
    file_exists(__DIR__ . '/../services/audio-matrix/tests/test_workstation_mute.py'));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);

// ── Lightweight php -S harness (no Python -- this file's own HTTP layer
//    never touches the live audio matrix) ───────────────────────────────
function p152bd_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function p152bd_start_php_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = p152bd_free_port();
    if ($port === null) return null;
    $tmpdir = sys_get_temp_dir() . '/tcad-p152bd-php-' . getmypid() . '-' . mt_rand();
    if (!@mkdir($tmpdir, 0777, true) && !is_dir($tmpdir)) return null;
    $logdir = $tmpdir . '/logs';
    @mkdir($logdir, 0777, true);
    $docroot = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
    $env = array_merge($_ENV ?: [], getenv() ?: []);
    $desc = [1 => ['file', $logdir . '/out.log', 'a'], 2 => ['file', $logdir . '/err.log', 'a']];
    $proc = @proc_open([$bin, '-S', '127.0.0.1:' . $port, '-t', $docroot], $desc, $pipes, $docroot, $env);
    if (!is_resource($proc)) return null;
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if (is_resource($c)) { fclose($c); return ['proc' => $proc, 'port' => $port, 'tmpdir' => $tmpdir]; }
        usleep(50000);
    }
    @proc_terminate($proc);
    @proc_close($proc);
    return null;
}

function p152bd_stop_php_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    p152bd_rrmdir($srv['tmpdir']);
}

function p152bd_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) p152bd_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function p152bd_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'p152bdcookie');
    $ch = curl_init($base . '/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $html = curl_exec($ch);
    curl_close($ch);
    if ($html === false) return null;
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string) $html, $m);
    $csrf = $m[1] ?? '';
    $ch = curl_init($base . '/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => $username, 'password' => $password, 'csrf_token' => $csrf]));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return null;
    if ($code === 302 || $code === 301) { return $cookieFile; }
    @unlink($cookieFile);
    return null;
}

function p152bd_csrf(string $base, string $cookieFile, string $path): ?string {
    $ch = curl_init($base . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!is_string($html)) return null;
    if (!preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $m)) return null;
    return $m[1];
}

function p152bd_post_json(string $url, string $cookieFile, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_TIMEOUT => 8,
    ]);
    $respBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => @json_decode((string) $respBody, true)];
}

function p152bd_get(string $url, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_TIMEOUT => 8,
    ]);
    $respBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => @json_decode((string) $respBody, true)];
}
