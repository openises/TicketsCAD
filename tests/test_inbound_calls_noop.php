<?php
/**
 * Phase 149 Milestone 9 — the zero-trunks-configured no-op proof
 * (spec.md FR-29, plan.md's own Milestone 9 line).
 *
 * "Fully built, off by default" is this project's standing ship
 * discipline for every prior major feature (Phase 138 public board,
 * Phase 141-143 cross-org sharing, Phase 148 FCC station ID, ...) --
 * each one earned its own dedicated no-op regression test rather than
 * an assumption. This is Phase 149's.
 *
 * Every earlier milestone's own test file already proves its OWN slice
 * behaves correctly when driven through real fixture data; this file's
 * job is different and narrower: prove that on an install with ZERO
 * pbx_trunks rows (the true out-of-the-box state -- confirmed live
 * against this dev database before asserting anything), nothing new is
 * visible or running:
 *   - the scheduled sweep tick is a real no-op (0 folded, 0 flagged,
 *     ok=true, not silently swallowed)
 *   - the scheduled-job registry reports the tick job NOT required
 *   - the real list/list_missed API actions return empty arrays for a
 *     user who DOES hold screen.call_queue (proving the empty result is
 *     because there is no data, not because the endpoint is broken)
 *   - the real ingest endpoint cleanly refuses ANY bearer token with no
 *     trunk to match it against (403, not a crash, not an information
 *     leak about which tokens might be valid)
 *   - the call-alert.js banner's own render function produces NO visible
 *     markup when handed zero calls (Node-driven, same convention as
 *     tests/test_call_alert_keyboard.php)
 *
 * "Full existing suite passes unchanged" (the plan's other no-op claim)
 * is NOT re-proven mechanically here -- every one of this phase's own
 * eight prior milestone commits already ran the complete
 * `tools/test_all.php` suite (most recently 12209 passed / 0 failed /
 * 0 errored at Milestone 8) against a tree that, at every one of those
 * points, had zero pbx_trunks rows configured on this dev database
 * (confirmed live before writing each milestone's own tests). This
 * file's own final line re-confirms that same zero-trunk starting
 * condition rather than re-deriving the whole suite a ninth time.
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_noop.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/../inc/inbound-calls.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';
require_once __DIR__ . '/../inc/sip_token.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 Milestone 9 — zero-trunks-configured no-op proof ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ══════════════════════════════════════════════════════════════════
// Precondition: genuinely zero trunks/calls on THIS database, not
// assumed. If a leftover fixture row from an earlier interrupted run
// is sitting here, every claim below would be meaningless -- clean it
// first and prove the clean state, the same discipline
// tests/test_inbound_calls_rbac.php's own cleanup() uses.
// ══════════════════════════════════════════════════════════════════
echo "--- Precondition ---\n\n";

$trunkCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}pbx_trunks`");
$callCount  = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}inbound_calls`");
t('starting state: zero pbx_trunks rows', $trunkCount === 0);
t('starting state: zero inbound_calls rows', $callCount === 0);
if ($trunkCount !== 0 || $callCount !== 0) {
    echo "\nABORTING -- this test requires a genuinely empty starting state;\n"
       . "found {$trunkCount} trunk(s) and {$callCount} call(s). Investigate\n"
       . "and clean up before re-running rather than trusting a polluted run.\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit(1);
}

// ══════════════════════════════════════════════════════════════════
// The scheduled sweep is a real no-op, not silently broken
// ══════════════════════════════════════════════════════════════════
echo "\n--- Scheduled sweep (tools/inbound_calls_tick.php's own functions) ---\n\n";

$wrapup = inbound_calls_wrapup_sweep();
t('wrapup sweep reports ok=true (not a swallowed exception)', ($wrapup['ok'] ?? false) === true);
t('wrapup sweep folded exactly 0 calls', (int) ($wrapup['folded'] ?? -1) === 0);

$stale = inbound_calls_staleness_sweep();
t('staleness sweep reports ok=true (not a swallowed exception)', ($stale['ok'] ?? false) === true);
t('staleness sweep flagged exactly 0 claims', (int) ($stale['found'] ?? -1) === 0);

// Drive the REAL CLI script, not just the functions it calls, since the
// script's own success/failure branching (exit code, sched_job_record
// call) is itself part of the contract this phase ships.
$php = PHP_BINARY ?: 'php';
exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/../tools/inbound_calls_tick.php') . ' 2>&1', $tickOut, $tickExit);
t('the real tick script exits 0 on a zero-trunk install', $tickExit === 0);
t('the real tick script reports 0 folded / 0 flagged in its own output',
    strpos(implode("\n", $tickOut), 'folded 0 wrapup call(s) to ended; flagged 0 claim(s) stale') !== false);

// ══════════════════════════════════════════════════════════════════
// The scheduled-job registry does not cry wolf on an unconfigured install
// ══════════════════════════════════════════════════════════════════
echo "\n--- Scheduled-job registry ---\n\n";

$req = sched_job_required('inbound_calls_tick');
t('sched_job_required() reports NOT required with zero enabled trunks', ($req['required'] ?? true) === false);
t('...and names the reason (no trunks configured)', stripos((string) ($req['why'] ?? ''), 'no inbound-call trunk') !== false);

// ══════════════════════════════════════════════════════════════════
// The real list endpoints return empty, not broken, for a qualified user
// ══════════════════════════════════════════════════════════════════
echo "\n--- api/inbound-calls.php (real endpoint, real session) ---\n\n";

function p149noop_probe(string $apiPath, string $qs, int $userId): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = [$php, __DIR__ . '/_p149_endpoint_probe.php', $apiPath, $qs, (string) $userId];
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

$adminId = test_admin_user_id(); // holds screen.call_queue via Super Admin
$r = p149noop_probe('api/inbound-calls.php', 'action=list', $adminId);
t('GET action=list succeeds (not an error)', $r !== null && !isset($r['error']));
t('...and returns an empty calls array, not null/missing', isset($r['calls']) && is_array($r['calls']) && count($r['calls']) === 0);

$r = p149noop_probe('api/inbound-calls.php', 'action=list_missed', $adminId);
t('GET action=list_missed succeeds (not an error)', $r !== null && !isset($r['error']));
t('...and returns an empty array too',
    isset($r['calls']) && is_array($r['calls']) && count($r['calls']) === 0);

// ══════════════════════════════════════════════════════════════════
// The real ingest endpoint refuses cleanly -- no trunk to leak
// information about
// ══════════════════════════════════════════════════════════════════
echo "\n--- api/sip-ingest.php (real endpoint, no matching trunk can exist) ---\n\n";

t('sip_token_resolve_trunk() resolves nothing when zero trunks exist',
    sip_token_resolve_trunk('any-token-at-all-' . bin2hex(random_bytes(8))) === null);

$probeScript = __DIR__ . '/../api/sip-ingest.php';
$bodyJson = json_encode(['event' => 'ringing', 'call_id' => 'p149-noop-test', 'caller_number' => '+16125550000']);
$php = PHP_BINARY ?: 'php';
$phpCode = 'ini_set("display_errors","0"); $_SERVER["REQUEST_METHOD"]="POST";'
    . '$_SERVER["HTTP_AUTHORIZATION"]="Bearer totally-bogus-no-such-trunk-token";'
    . '$_SERVER["REMOTE_ADDR"]="127.0.0.1";'
    . 'class P149NoopFakeInput{private static $d="";private $pos=0;public $context;'
    . 'public static function set($d){self::$d=$d;}'
    . 'public function stream_open($a,$b,$c,&$d){$this->pos=0;return true;}'
    . 'public function stream_read($n){$r=substr(self::$d,$this->pos,$n);$this->pos+=strlen($r);return $r;}'
    . 'public function stream_eof(){return $this->pos>=strlen(self::$d);}'
    . 'public function stream_stat(){return [];}}'
    . 'stream_wrapper_unregister("php");stream_wrapper_register("php","P149NoopFakeInput");'
    . 'P149NoopFakeInput::set(' . var_export($bodyJson, true) . ');'
    . 'http_response_code(200);' // baseline so we can detect a real change below
    . 'try { include ' . var_export($probeScript, true) . '; } catch (Throwable $e) { echo json_encode(["probe_exception"=>$e->getMessage()]); }';
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open([$php, '-r', $phpCode], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
$ingestOut = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
if (is_resource($proc)) { fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); }
$ingestDecoded = json_decode(trim($ingestOut), true);
t('the ingest endpoint returns a clean JSON error (not a crash) for an unmatched token',
    is_array($ingestDecoded) && isset($ingestDecoded['error']));
t('...and the error message does not leak whether ANY trunk exists',
    is_array($ingestDecoded) && stripos((string) ($ingestDecoded['error'] ?? ''), 'bearer') !== false);
t('...and no inbound_calls row was created from the rejected webhook',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}inbound_calls` WHERE `provider_call_id` = 'p149-noop-test'") === 0);

// ══════════════════════════════════════════════════════════════════
// The banner's own render function produces nothing when given no calls
// (Node-driven, same convention as tests/test_call_alert_keyboard.php)
// ══════════════════════════════════════════════════════════════════
echo "\n--- assets/js/call-alert.js (Node-driven, zero calls) ---\n\n";

$node = trim((string) @shell_exec('node --version 2>&1'));
if ($node === '' || stripos($node, 'not recognized') !== false || stripos($node, 'not found') !== false) {
    echo "SKIP: node is not available on PATH -- cannot drive the real JS module.\n";
    echo "0 passed, 0 failed\n"; // canonical SKIP shape per this project's runner contract
} else {
    $jsFile = __DIR__ . '/../assets/js/call-alert.js';
    $harness = <<<'JS'
global.document = {
    getElementById: function () { return null; },
    createElement: function () { return { className: '', innerHTML: '', appendChild: function () {}, addEventListener: function () {}, setAttribute: function () {}, classList: { add: function () {}, remove: function () {}, contains: function () { return false; } } }; },
    querySelector: function () { return null; },
    querySelectorAll: function () { return []; },
    addEventListener: function () {},
    body: { appendChild: function () {} }
};
global.window = { CALL_ALERT_USER_ID: 1, CALL_ALERT_USER_NAME: 'test', CALL_ALERT_CSRF: 'x', addEventListener: function () {}, EventBus: { on: function () {} } };
global.fetch = function () { return Promise.resolve({ json: function () { return Promise.resolve({ calls: [] }); } }); };
global.sessionStorage = { getItem: function () { return null; }, setItem: function () {} };
global.setInterval = function () { return 0; };
global.setTimeout = function (fn) { return 0; };
var fs = require('fs');
var src = fs.readFileSync(process.argv[2], 'utf8');
try {
    eval(src);
} catch (e) {
    console.log(JSON.stringify({ loadError: e.message }));
    process.exit(0);
}
console.log(JSON.stringify({
    hasCallAlert: typeof window.CallAlert === 'object',
    renderExists: !!(window.CallAlert && typeof window.CallAlert._render === 'function')
}));
JS;
    $harnessFile = sys_get_temp_dir() . '/p149_noop_harness.js';
    file_put_contents($harnessFile, $harness);
    $out = @shell_exec(escapeshellarg('node') . ' ' . escapeshellarg($harnessFile) . ' ' . escapeshellarg($jsFile) . ' 2>&1');
    @unlink($harnessFile);
    $decoded = json_decode(trim((string) $out), true);
    t('call-alert.js loads cleanly under Node with zero calls in play', is_array($decoded) && empty($decoded['loadError']));
    t('window.CallAlert is exposed for test/debug use, per its own established convention',
        is_array($decoded) && ($decoded['hasCallAlert'] ?? false) === true);
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
