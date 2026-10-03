<?php
/**
 * Phase 155, GH#108 slice S4 -- ONE Answer, client wiring.
 *
 * Finding F4: answering in the browser phone answered only the AUDIO; the
 * dispatcher then clicked Answer a second time in the Phase 149 banner to
 * claim the call and open the New Incident form (and clicking the banner
 * first still left the phone ringing). Now:
 *   widget Answer  -> session.answer() AND, when the PBX tagged the INVITE
 *                     with X-Call-Linkedid, POST claim_by_provider and open
 *                     the New Incident tab exactly as the banner does;
 *   banner Answer  -> claim, open the tab, AND tell the phone (BroadcastChannel
 *                     plus a same-page event) to answer the leg carrying that
 *                     PBX id;
 *   no header / no bridge / nothing found -> the audio is answered anyway and
 *                     nothing else happens (never an error, never a loop).
 *
 * The behaviour runs the REAL phone-widget.js and the REAL call-alert.js in
 * jsdom against a FAKE JsSIP (no PBX; INVITEs are simulated). The server half
 * is tests/test_phone_claim_by_provider.php.
 *
 * Usage: php tests/test_phone_answer_claim_wiring.php
 */
require_once __DIR__ . '/_phone_node_runner.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$root = dirname(__DIR__);
echo "=== Phase 155 / S4 -- one Answer: widget and banner wiring ===\n\n--- Part 1: static ---\n\n";

$widget = (string) file_get_contents($root . '/assets/js/phone-widget.js');
$alert  = (string) file_get_contents($root . '/assets/js/call-alert.js');
$navbar = (string) file_get_contents($root . '/inc/navbar.php');

t('the widget claims through api/inbound-calls.php?action=claim_by_provider', strpos($widget, 'action=claim_by_provider') !== false);
t('...only from the widget path, never when the banner already claimed (source !== banner)',
    strpos($widget, "source !== 'banner' && currentLinkedId") !== false);
t('the widget reads the PBX id from the newRTCSession EVENT through PhoneDialLogic.linkedIdFromEvent (validated, not raw; an RTCSession has no public request)',
    strpos($widget, 'Logic.linkedIdFromEvent(e)') !== false && strpos($widget, 'session.request') === false);
t('call-alert.js tells the phone after EVERY successful claim path (claim, reassign, force_reclaim)',
    substr_count($alert, 'notifyPhoneAnswered(id);') === 3);
t('...on the SAME channel name the widget listens on',
    strpos($alert, "new window.BroadcastChannel('ticketscad-phone')") !== false && strpos($widget, "var BANNER_CHANNEL = 'ticketscad-phone'") !== false);
t('...and the same-page event name matches', strpos($alert, "'ticketscad:phone-answer'") !== false && strpos($widget, "'ticketscad:phone-answer'") !== false);
t('call-alert.js keeps the PBX id from the payload', strpos($alert, 'provider_call_id: payload.provider_call_id') !== false);
t('inc/navbar.php cache-busts call-alert.js by file modification time (a changed file must reach training through its CDN)',
    (bool) preg_match("#call-alert\.js\?v=<\?php echo file_exists\(__DIR__ \. '/\.\./assets/js/call-alert\.js'\) \? filemtime#", $navbar));
$ev = (string) file_get_contents($root . '/assets/js/event-bus.js');
t('no new SSE event type was introduced (the PBX id rides in the existing call:* payloads)',
    strpos($ev, 'call:phone') === false);
$ci = (string) file_get_contents($root . '/inc/inbound-calls.php');
t('the bridge-facing contract is unchanged: sip-ingest still takes call_id, and provider_call_id is only ever READ into payloads here',
    strpos($ci, "'provider_call_id' => (string) (\$call['provider_call_id'] ?? '')") !== false);

echo "\n--- Part 2: behaviour (real widget + real call-alert.js in jsdom, fake JsSIP) ---\n\n";
$r = phone_node_run('_phone_answer_claim_node.js');
if ($r !== null) { foreach ($r as $row) { t($row[0], $row[1]); } }

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
