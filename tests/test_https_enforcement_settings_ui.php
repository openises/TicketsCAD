<?php
/**
 * SPEC-STATUS.md gap B16 — Settings page live-state display.
 *
 * settings.php's "Require HTTPS" checkbox (Login Settings panel) was
 * `disabled` with a "Not yet wired" badge until this phase. This file
 * proves, structurally:
 *   1. The checkbox is no longer disabled and no longer carries the
 *      "Not yet wired" badge (login_userlist, right next to it, correctly
 *      still does — this must not regress that one).
 *   2. A live-state box exists next to the checkbox and is wired to fetch
 *      the SAME endpoint (api/https-enforcement-status.php) the navbar
 *      banner uses — one canonical source, never a second copy of the
 *      logic re-derived in JS.
 *   3. The API↔JS field-name contract holds: every JSON key the endpoint
 *      can emit that the live-state box reads is a key
 *      https_enforcement_status() actually returns — the same class of
 *      bug tools/api_contract_audit.php exists to catch project-wide
 *      (CLAUDE.md, "THE API↔JS CONTRACT PATTERN"), checked directly here
 *      for this one endpoint/consumer pair.
 *   4. Content built with DOM methods (textContent/createTextNode), never
 *      innerHTML with server-supplied text interpolated in — matching the
 *      established #tfaKeysDirExposureBox convention this box was modeled
 *      on.
 *
 * Usage: php tests/test_https_enforcement_settings_ui.php
 */

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function t($label, $cond, $hint = '') {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . ($cond ? '' : ($hint !== '' ? "\n       $hint" : '')) . "\n";
    $cond ? $pass++ : $fail++;
}

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. The checkbox itself --\n";

$settingsSrc = (string) file_get_contents($root . '/settings.php');

if (preg_match('/<input[^>]*data-key="require_https"[^>]*>/', $settingsSrc, $m)) {
    $tag = $m[0];
    t('require_https checkbox exists', true);
    t('require_https checkbox is NOT disabled', strpos($tag, 'disabled') === false, $tag);
} else {
    t('require_https checkbox exists', false);
}

// The "Not yet wired" badge must be gone from require_https specifically.
// Isolate the block between the checkbox's opening <div class="form-check">
// and its closing </div> so this doesn't accidentally match login_userlist's
// still-correct badge two lines away.
if (preg_match('/data-key="require_https"[\s\S]{0,1200}?<\/div>\s*<\/div>/', $settingsSrc, $blockM)) {
    t('require_https block no longer carries the "Not yet wired" badge',
        strpos($blockM[0], 'Not yet wired') === false, $blockM[0]);
} else {
    t('could isolate the require_https form-check block for the badge check', false);
}

// login_userlist must NOT have regressed — it is still genuinely unwired.
if (preg_match('/<input[^>]*data-key="login_userlist"[^>]*>/', $settingsSrc, $ulM)) {
    t('login_userlist checkbox is still disabled (unrelated to this phase, must not regress)',
        strpos($ulM[0], 'disabled') !== false, $ulM[0]);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The live-state box exists and is wired in JS --\n";

t('settings.php has a #requireHttpsLiveStatus container next to the checkbox',
    strpos($settingsSrc, 'id="requireHttpsLiveStatus"') !== false);

$configJsSrc = (string) file_get_contents($root . '/assets/js/config.js');

t('config.js defines loadRequireHttpsLiveStatus()',
    strpos($configJsSrc, 'function loadRequireHttpsLiveStatus()') !== false);

t('loadRequireHttpsLiveStatus() reads the #requireHttpsLiveStatus element',
    (function () use ($configJsSrc) {
        $start = strpos($configJsSrc, 'function loadRequireHttpsLiveStatus()');
        if ($start === false) return false;
        $body = substr($configJsSrc, $start, 1500);
        return strpos($body, "getElementById('requireHttpsLiveStatus')") !== false;
    })());

t('loadRequireHttpsLiveStatus() fetches the SAME endpoint the navbar banner uses '
    . '(one canonical source, not a second re-derivation)',
    (function () use ($configJsSrc) {
        $start = strpos($configJsSrc, 'function loadRequireHttpsLiveStatus()');
        if ($start === false) return false;
        $body = substr($configJsSrc, $start, 1500);
        return strpos($body, "fetch('api/https-enforcement-status.php'") !== false;
    })());

t('loadRequireHttpsLiveStatus() is actually CALLED (both on load and after save) — '
    . 'a defined-but-never-invoked function is the same "plumbing exists, nobody wired '
    . 'the last mile" gap this project has hit before',
    substr_count($configJsSrc, 'loadRequireHttpsLiveStatus()') >= 3, // the definition + >=2 call sites
    'occurrences=' . substr_count($configJsSrc, 'loadRequireHttpsLiveStatus()'));

$bannerSrc = (string) file_get_contents($root . '/inc/https-enforcement-notice.php');
t('the navbar banner also fetches api/https-enforcement-status.php — same endpoint as the Settings box',
    strpos($bannerSrc, "fetch('api/https-enforcement-status.php'") !== false);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. API <-> JS field-name contract --\n";

// Every key https_enforcement_status() actually returns (inc/https-enforcement.php).
$emittedKeys = ['enabled', 'verified', 'reason', 'message', 'show_banner'];
$logicSrc = (string) file_get_contents($root . '/inc/https-enforcement.php');
foreach ($emittedKeys as $key) {
    t("https_enforcement_status() source really does return '$key'",
        preg_match('/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>/', $logicSrc) === 1);
}

// Every data.<key> the live-state box JS reads must be one of those keys
// (or the endpoint's own JSON wrapper — none here, the endpoint returns
// the status array directly) — not a key the endpoint never emits, the
// exact shape tools/api_contract_audit.php polices project-wide.
$boxStart = strpos($configJsSrc, 'function loadRequireHttpsLiveStatus()');
$boxEnd   = strpos($configJsSrc, "\n    }\n", $boxStart);
$boxBody  = $boxStart !== false ? substr($configJsSrc, $boxStart, ($boxEnd !== false ? $boxEnd - $boxStart : 1500)) : '';
preg_match_all('/\bdata\.([a-zA-Z_][a-zA-Z0-9_]*)/', $boxBody, $dataReads);
$readKeys = array_unique($dataReads[1] ?? []);
$unknownReads = array_diff($readKeys, $emittedKeys);
t('every data.<key> the live-state box reads is a key the endpoint actually emits',
    $unknownReads === [], implode(', ', $unknownReads));
t('the live-state box reads at least enabled/verified/reason/message (not a no-op box)',
    count(array_intersect(['enabled', 'verified', 'reason', 'message'], $readKeys)) === 4,
    'read=' . implode(',', $readKeys));

// Same contract check for the navbar banner (reads show_banner + message).
preg_match_all('/\bdata\.([a-zA-Z_][a-zA-Z0-9_]*)/', $bannerSrc, $bannerReads);
$bannerReadKeys = array_unique($bannerReads[1] ?? []);
$bannerUnknown = array_diff($bannerReadKeys, $emittedKeys);
t('the navbar banner also only reads keys the endpoint actually emits',
    $bannerUnknown === [], implode(', ', $bannerUnknown));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. DOM-safety convention (no innerHTML with server text) --\n";

t('loadRequireHttpsLiveStatus() does not use innerHTML to insert the server message '
    . '(uses createTextNode/appendChild instead, matching #tfaKeysDirExposureBox)',
    (function () use ($boxBody) {
        // innerHTML = '' (clearing) is fine and expected; innerHTML with
        // concatenated data.* is what would be unsafe. None should exist.
        return preg_match('/innerHTML\s*\+?=\s*[^;]*data\./', $boxBody) === 0;
    })());

t('loadRequireHttpsLiveStatus() builds the message via createTextNode',
    strpos($boxBody, 'createTextNode') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
