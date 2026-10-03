<?php
/**
 * Incident-detail "Assign" must honour the server's double-booking confirmation
 * (GH#82/GH#83 contract), not report it as success.
 *
 * api/incident-assign.php answers a WARN-level double-booking with
 *     { needs_confirmation: true, message: "..." }      (HTTP 200, not an error)
 * and expects the client to ask the dispatcher and resubmit with force=true.
 * assets/js/incident-detail.js's assignResponder() never looked at it: the reply fell
 * through to the success branch, showed the warning text as a green "assigned"
 * message, and assigned NOTHING. app.js and unit-actions.js were already correct.
 *
 * Part 1 (runs everywhere): every JS file that posts action 'assign' to
 * incident-assign.php mentions needs_confirmation, so a NEW caller cannot skip it.
 * Part 2 (needs Node): runs the REAL assignResponder() source in a sandbox with a
 * stubbed fetch/DOM and checks the exact request/response behaviour.
 *
 * Usage: php tests/test_gh_assign_needs_confirmation.php
 */
$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}
$root = dirname(__DIR__);

echo "=== Assign confirmation contract (incident-assign.php) ===\n\n--- Part 1: every caller ---\n\n";
$callers = [];
foreach (glob($root . '/assets/js/*.js') ?: [] as $f) {
    $s = file_get_contents($f);
    if (strpos($s, 'api/incident-assign.php') !== false && preg_match("/action:\s*'assign'/", $s)) {
        $callers[basename($f)] = $s;
    }
}
t('the known callers were found (sanity floor: app.js, incident-detail.js, unit-actions.js)',
    isset($callers['app.js'], $callers['incident-detail.js'], $callers['unit-actions.js']));
foreach ($callers as $name => $src) {
    t("$name handles needs_confirmation", strpos($src, 'needs_confirmation') !== false);
    t("$name can resubmit with force", (bool) preg_match('/force/', $src));
}

echo "\n--- Part 2: behaviour of the real assignResponder() ---\n\n";
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
$node = $node === '' ? '' : strtok($node, "\r\n");
if ($node === '') {
    echo "SKIP: Part 2 needs Node; Part 1 still ran\n";
} else {
    $script = <<<'JS'
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(process.argv[2] + '/assets/js/incident-detail.js', 'utf8');
// Extract the function by brace matching so the REAL source is what runs.
const start = src.indexOf('function assignResponder(');
if (start < 0) { console.log(JSON.stringify({ fatal: 'assignResponder not found' })); process.exit(0); }
let i = src.indexOf('{', start), depth = 0, end = -1, q = null;
for (; i < src.length; i++) {
  const c = src[i], n = src[i + 1];
  if (q) { if (c === '\\') { i++; } else if (c === q) q = null; continue; }
  if (c === '/' && n === '/') { i = src.indexOf('\n', i); continue; }
  if (c === '/' && n === '*') { i = src.indexOf('*/', i) + 1; continue; }
  if (c === '"' || c === "'" || c === '`') { q = c; continue; }
  if (c === '{') depth++;
  if (c === '}') { depth--; if (depth === 0) { end = i + 1; break; } }
}
const fnSrc = src.slice(start, end);

function scenario(replies, confirmAnswer) {
  const calls = [], log = { alerts: [], confirms: [], refreshes: 0 };
  const btn = { disabled: false, innerHTML: '' };
  const stubEl = { value: '', classList: { add() {}, remove() {} } };
  const ctx = {
    selectedResponderId: 7,
    document: { getElementById: (id) => id === 'btnAssignResponder' ? btn : stubEl },
    window: { confirm: (m) => { log.confirms.push(m); return confirmAnswer; } },
    getIncidentId: () => 42,
    getCsrfToken: () => 'csrf',
    escHtml: (s) => String(s),
    showAlert: (msg, kind) => log.alerts.push([msg, kind]),
    refreshIncident: () => { log.refreshes++; },
    fetch: (url, opts) => {
      calls.push({ url, body: JSON.parse(opts.body) });
      const reply = replies[calls.length - 1];
      return Promise.resolve({ json: () => Promise.resolve(reply) });
    },
  };
  vm.createContext(ctx);
  vm.runInContext(fnSrc, ctx);
  ctx.assignResponder(7);   // fire-and-forget like the UI; every stub resolves within a few ticks
  return new Promise(r => setTimeout(r, 60)).then(() => ({ calls, log, btn }));
}

(async () => {
  const warn = { needs_confirmation: true, message: 'Unit 7 is already On Scene at another incident. Assign anyway?' };
  const ok = { success: true, message: 'Unit 7 assigned.' };

  const accepted = await scenario([warn, ok], true);
  const declined = await scenario([warn], false);
  const plain = await scenario([ok], true);
  const failed = await scenario([{ error: 'Blocked: unit is Out of Service' }], true);

  console.log(JSON.stringify({
    accepted: {
      requests: accepted.calls.length,
      firstHasForce: !!accepted.calls[0].body.force,
      secondHasForce: accepted.calls[1] ? accepted.calls[1].body.force === true : false,
      sameUnit: accepted.calls[1] ? accepted.calls[1].body.responder_id === 7 : false,
      confirmedWithServerText: accepted.log.confirms[0] === warn.message,
      successAlerts: accepted.log.alerts.filter(a => a[1] === 'success').length,
      warningShownAsSuccess: accepted.log.alerts.some(a => a[1] === 'success' && a[0] === warn.message),
      refreshed: accepted.log.refreshes,
    },
    declined: {
      requests: declined.calls.length,
      successAlerts: declined.log.alerts.filter(a => a[1] === 'success').length,
      buttonReEnabled: declined.btn.disabled === false,
      refreshed: declined.log.refreshes,
    },
    plain: { requests: plain.calls.length, force: !!plain.calls[0].body.force, confirms: plain.log.confirms.length, successAlerts: plain.log.alerts.filter(a => a[1] === 'success').length },
    failed: { requests: failed.calls.length, confirms: failed.log.confirms.length, dangerAlerts: failed.log.alerts.filter(a => a[1] === 'danger').length, successAlerts: failed.log.alerts.filter(a => a[1] === 'success').length },
  }));
})();
JS;
    $tmp = tempnam(sys_get_temp_dir(), 'assignconf');
    file_put_contents($tmp, $script);
    $raw = shell_exec('"' . $node . '" "' . $tmp . '" "' . $root . '" 2>&1');
    @unlink($tmp);
    $r = json_decode(trim((string) $raw), true);
    if (!is_array($r) || isset($r['fatal'])) {
        t('the behaviour scenarios ran (' . substr((string) $raw, 0, 200) . ')', false);
    } else {
        $a = $r['accepted']; $d = $r['declined']; $p = $r['plain']; $f = $r['failed'];
        t('a double-booking warning is asked of the dispatcher using the SERVER\'s own text', $a['confirmedWithServerText'] === true);
        t('the first request carries no force flag', $a['firstHasForce'] === false);
        t('accepting resubmits exactly once, for the same unit, with force:true', $a['requests'] === 2 && $a['secondHasForce'] === true && $a['sameUnit'] === true);
        t('the warning is never reported as a green success', $a['warningShownAsSuccess'] === false);
        t('after the confirmed assign there is exactly one success message and one refresh', $a['successAlerts'] === 1 && $a['refreshed'] === 1);
        t('declining sends nothing more, reports no success, and does not refresh', $d['requests'] === 1 && $d['successAlerts'] === 0 && $d['refreshed'] === 0);
        t('declining leaves the Assign button usable (the picked unit stays selected)', $d['buttonReEnabled'] === true);
        t('an ordinary assign never asks and never forces', $p['requests'] === 1 && $p['force'] === false && $p['confirms'] === 0 && $p['successAlerts'] === 1);
        t('a hard server error (BLOCK) is shown as an error, with no confirm dialog', $f['requests'] === 1 && $f['confirms'] === 0 && $f['dangerAlerts'] === 1 && $f['successAlerts'] === 0);
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
