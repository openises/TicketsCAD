<?php
/**
 * Phase 152 (Console rebuild) — frontend wiring for "real per-strip PTT/
 * Select/Monitor/Mute/Volume wired to the browser leg." The end-to-end
 * backend behavior (api/console-patch.php against a REAL Python matrix)
 * is tests/test_phase152_console_patch.php's job; this file is the
 * source-level wiring guard for the JS/PHP glue around it, in the style
 * tests/test_console_views.php and tests/test_phase152_console_strip_
 * templates.php already established for this feature.
 *
 * Usage: php tests/test_phase152_matrix_audio_wiring.php
 */
chdir(__DIR__ . '/..');

$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 152 -- browser-leg mic/playback wiring ===\n\n";

$mic = (string) @file_get_contents('assets/js/console-mic.js');
$playback = (string) @file_get_contents('assets/js/console-playback.js');
$audio = (string) @file_get_contents('assets/js/console-audio.js');
$logic = (string) @file_get_contents('assets/js/console-audio-logic.js');
$cjs = (string) @file_get_contents('assets/js/console.js');
$page = (string) @file_get_contents('console.php');
$patchApi = (string) @file_get_contents('api/console-patch.php');
$matrixRoutes = (string) @file_get_contents('inc/matrix-routes.php');

echo "1. console-mic.js: scope is matrix-backed adapters ONLY (Zello excluded)\n";
t('MATRIX_ADAPTERS includes dmr_bm and dmr_local', strpos($mic, "dmr_bm: true") !== false && strpos($mic, "dmr_local: true") !== false);
t('MATRIX_ADAPTERS does NOT include zello (5-persona review #2 decision)', strpos($mic, "zello: true") === false);
t('isMatrixBacked() is the exposed capability check console.js consults', strpos($mic, 'isMatrixBacked: isMatrixBacked') !== false);

echo "\n2. console-mic.js: ES5, mints a session, mic never echoes to this tab's own speakers\n";
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $mic));
t('mints a console session via api/console-session.php before opening the WS', strpos($mic, "SESSION_API = 'api/console-session.php'") !== false && strpos($mic, "action: 'create'") !== false);
t('the mic sink gain is forced to 0 (never plays the operator\'s own mic back to them)', strpos($mic, 'sink.gain.value = 0;') !== false);
t('the mic downsampler produces 160-sample (320-byte, 20ms @ 8kHz) frames matching frame.py exactly',
    strpos($mic, 'this.FRAME_SAMPLES = 160;') !== false && strpos($mic, 'new ArrayBuffer(FRAME * 2)') !== false);

echo "\n3. console-mic.js: the public patch actions match api/console-patch.php's real actions\n";
t('listen()/unlisten() POST connect/disconnect with direction=listen',
    strpos($mic, "action: 'connect', channel_id: channelId, direction: 'listen'") !== false
    && strpos($mic, "action: 'disconnect', channel_id: channelId, direction: 'listen'") !== false);
t('talkStart()/talkEnd() POST connect/disconnect with direction=talk',
    strpos($mic, "action: 'connect', channel_id: channelId, direction: 'talk'") !== false
    && strpos($mic, "action: 'disconnect', channel_id: channelId, direction: 'talk'") !== false);
t('setListenGain() POSTs action=set_gain', strpos($mic, "action: 'set_gain'") !== false);
t('every patch call carries the session_token and csrf_token', strpos($mic, 'payload.session_token = sessionToken;') !== false && strpos($mic, 'payload.csrf_token = csrf;') !== false);

echo "\n4. console-mic.js: connect() is idempotent and callback-based (never a fire-and-forget race)\n";
t('an already-authed connection resolves the callback immediately, true', strpos($mic, 'if (ws && wsAuthed) { if (cb) { cb(true); } return; }') !== false);
t('a concurrent connect() call queues onto the SAME in-flight attempt rather than starting a second one',
    strpos($mic, 'if (connecting) { return; }') !== false && strpos($mic, 'pendingConnectCallbacks.push(cb)') !== false);
t('mic capture is never started before getUserMedia resolves, and never on page load automatically',
    strpos($mic, 'navigator.mediaDevices.getUserMedia') !== false
    && strpos($cjs, 'window.ConsoleMatrix.connect()') === false); // never called unconditionally at boot

echo "\n5. console-mic.js: fail-loud disconnect + tx confirmation (prerequisite #7 / persona review #1)\n";
t('a genuine disconnect (was authed, now closed) notifies \'disconnected\'; never-connected notifies \'failed\' instead',
    strpos($mic, "notifyConnState(wasAuthed ? 'disconnected' : 'failed');") !== false);
t('tx_started/tx_ended server messages are surfaced via a subscriber, never inferred from a client click',
    strpos($mic, "msg.type === 'tx_started' || msg.type === 'tx_ended'") !== false);
t('an unconfigured install (no ws_url) fails soft, not throwing', strpos($mic, "if (!sessionToken || !wsUrl)") !== false);

echo "\n6. console-playback.js: subscribes to console-mic.js's frames, no second WebSocket\n";
t('ES5 style', !preg_match('/=>|`|\blet\s|\bconst\s/', $playback));
t('subscribes via ConsoleMatrix.subscribeAudioFrame() rather than opening its own WS', strpos($playback, 'Matrix.subscribeAudioFrame(') !== false && strpos($playback, 'new WebSocket') === false);
t('the AudioContext is created at 8000 Hz directly (browser does the final upsample), matching radio-widget.js\'s own pattern',
    strpos($playback, 'new AC({ sampleRate: SAMPLE_RATE })') !== false && strpos($playback, 'SAMPLE_RATE = 8000') !== false);
t('a stale backlog snaps forward instead of trying to catch up (bounded latency, never unbounded)',
    strpos($playback, 'totalWritten - totalPlayed > RING_SAMPLES') !== false);

echo "\n7. console-audio-logic.js: matrixAudio is a normal per-channel field, defaulting OFF\n";
t('defaultState() includes matrixAudio: false', strpos($logic, 'matrixAudio: false') !== false);
t('normalizeState() coerces matrixAudio safely (never trusts an arbitrary truthy value verbatim)', strpos($logic, 'matrixAudio: !!s.matrixAudio') !== false);

echo "\n8. console-audio.js: the singleton radio path and the matrix path are MUTUALLY EXCLUSIVE per channel\n";
// Generalized from a bare `isRadio` check to `isMatrixCapable` (Phase 152
// dispatcher-intercom follow-on, 2026-09-07) when intercom_dd joined DMR
// as a matrix-backed adapter -- the mutual-exclusion PROPERTY this
// assertion exists to prove is unchanged (a matrix-engaged channel is
// still excluded from the singleton computation below); only the name of
// the boolean feeding the same `if` changed, since intercom_dd has no
// singleton widget to be exclusive WITH in the first place.
t('applyAudio() EXCLUDES a matrixAudio-engaged channel from the singleton radio-widget computation (the one hard rule preventing double-audio)',
    strpos($audio, 'if (isMatrixCapable && s.matrixAudio && window.ConsoleMatrix) {') !== false
    && strpos($audio, 'continue;') !== false);
t('a matrixAudio-engaged channel drives its OWN gain via ConsoleMatrix.setListenGain(), only while actually connected',
    strpos($audio, 'window.ConsoleMatrix.isConnected()') !== false
    && strpos($audio, 'window.ConsoleMatrix.setListenGain(id, linearGainToDb(gain));') !== false);
t('linearGainToDb() floors at -60dB (matching matrix_normalize_gain()\'s own floor) rather than -Infinity for silence',
    strpos($audio, 'if (gain <= 0.001) { return -60; }') !== false);
t('setMatrixAudio() lazily connects before creating the listen route -- never listen()s on an unauthenticated session',
    strpos($audio, 'window.ConsoleMatrix.connect(function (ok) {') !== false
    && strpos($audio, 'window.ConsoleMatrix.listen(id, linearGainToDb(gain)') !== false);
t('turning Matrix Audio OFF calls unlisten(), tearing the route down rather than leaving it orphaned',
    strpos($audio, 'window.ConsoleMatrix.unlisten(id, function (ok) {') !== false);

echo "\n9. console.js: the Matrix Audio toggle is opt-in per strip, defaults to the launcher\n";
t('isMatrixBacked/matrixAudioOn are derived from window.ConsoleMatrix + the per-channel state, never assumed', strpos($cjs, 'window.ConsoleMatrix.isMatrixBacked(ch.adapter)') !== false);
t('a DMR strip is STILL a launcher unless Matrix Audio has been explicitly engaged', strpos($cjs, "(isMatrixBacked && !matrixAudioOn)") !== false);
t('the real PTT (matrixAudioOn branch) is held-down (mousedown/mouseup), not a click, matching every other real PTT in this app',
    strpos($cjs, 'mb.addEventListener(\'mousedown\', mbStart);') !== false && strpos($cjs, 'mb.addEventListener(\'mouseup\', mbStop);') !== false);
t('the toggle checkbox is only offered when canTx (never to a listen-only operator)', strpos($cjs, 'if (isMatrixBacked && canTx) {') !== false);
t('toggling calls ConsoleAudio.setMatrixAudio(), never a direct ConsoleMatrix call from the renderer', strpos($cjs, 'window.ConsoleAudio.setMatrixAudio(ch.id, want,') !== false);

echo "\n10. console.js: persistent disconnect alarm + fast tx-confirmation tone (persona review #1)\n";
t('a genuine \'disconnected\' state shows a persistent, non-dismissed alarm bar (never removed except by reconnecting)',
    strpos($cjs, "state === 'disconnected'") !== false && strpos($cjs, "bar.classList.remove('d-none');") !== false);
t('\'failed\' (never-yet-connected -- the default state on most installs) is deliberately silent, not alarmed',
    strpos($cjs, "'failed' (never-yet-connected") !== false);
t('tx_started plays an audible confirmation tone via the real Web Audio API, not merely a visual change',
    strpos($cjs, "if (tx === 'started') { beep(880, 120); }") !== false
    && strpos($cjs, 'osc.frequency.value = freq;') !== false);

echo "\n11. console.php: scripts load in the required order\n";
// console-mic.js's <script> tag moved into inc/navbar.php on 2026-09-08
// (unification plan step 1/3) so the navbar Simulselect widget can use it
// from any page — it's no longer on console.php's own page at all. The
// invariant that actually matters is unchanged: by the time console-
// playback.js (which subscribes to console-mic.js's frames) and console.js
// (which reads window.ConsoleMatrix at render time) run, console-mic.js
// has ALREADY executed. That now holds structurally rather than by
// tag-position on one page: navbar.php is included well before either of
// those two tags on console.php, so as long as (a) navbar.php actually
// includes console.php's include_once call, and (b) navbar.php's own
// mic.js tag precedes navbar.php's own closing content, the ordering is
// guaranteed regardless of exactly where within navbar.php it sits.
$navbarSrc = (string) @file_get_contents('inc/navbar.php');
preg_match('/<script src="assets\/js\/console-mic\.js/', $navbarSrc, $mm, PREG_OFFSET_CAPTURE);
$micTagPosInNavbar = $mm[0][1] ?? false;
preg_match('/include_once\s+NEWUI_ROOT\s*\.\s*[\'"]\/inc\/navbar\.php[\'"]/', $page, $nm, PREG_OFFSET_CAPTURE);
$navbarIncludePos = $nm[0][1] ?? false;
preg_match('/<script src="assets\/js\/console-playback\.js/', $page, $pm, PREG_OFFSET_CAPTURE);
preg_match('/<script src="assets\/js\/console\.js/', $page, $cm, PREG_OFFSET_CAPTURE);
$playbackTagPos = $pm[0][1] ?? false;
$consoleTagPos = $cm[0][1] ?? false;
t('console-mic.js has a real <script> tag inside inc/navbar.php',
    $micTagPosInNavbar !== false);
t('console.php includes inc/navbar.php BEFORE its own console-playback.js/console.js tags '
    . '(playback subscribes to console-mic.js\'s frames; console.js reads window.ConsoleMatrix at render time)',
    $navbarIncludePos !== false && $playbackTagPos !== false && $consoleTagPos !== false
    && $navbarIncludePos < $playbackTagPos && $playbackTagPos < $consoleTagPos);

echo "\n12. api/console-patch.php + inc/matrix-routes.php: the endpoint never touches comm_routes, "
    . "never trusts a client-claimed session\n";
t('console-patch.php never WRITES to comm_routes (ephemeral, control-plane-only routes) -- '
    . 'its docblock mentions the table by name only to explain why it does NOT',
    !preg_match('/\b(INSERT INTO|UPDATE|DELETE FROM)\b[^;]*comm_routes/i', $patchApi));
t('the caller\'s own channel is derived from session_token, matched against user_id -- never trusted from the client directly',
    strpos($patchApi, "user_id = ? AND revoked_at IS NULL AND expires_at > NOW()") !== false);
t('the intercom_dd leaf rule is enforced on this path too, via matrix_browser_leg_validate_channel()',
    strpos($patchApi, 'matrix_browser_leg_validate_channel(') !== false
    && strpos($matrixRoutes, "function matrix_browser_leg_validate_channel(") !== false
    && strpos($matrixRoutes, "\$ch['adapter'] === 'intercom_dd'") !== false);
t('gated on action.patch_create (Dispatcher-tier) -- the actual rbac_can() call never names action.manage_matrix '
    . '(the admin-only endpoint\'s own separate gate), even though the docblock mentions it by name for contrast',
    strpos($patchApi, "rbac_can('action.patch_create')") !== false
    && !preg_match("/rbac_can\\(\\s*'action\\.manage_matrix'\\s*\\)/", $patchApi));

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
