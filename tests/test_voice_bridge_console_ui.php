<?php
/**
 * Phase 155 (GH#151 + GH#129) — Communications Console behaviour for a
 * listen-only digital voice bridge strip. Runs the REAL assets/js/console.js
 * and assets/js/console-mic.js inside jsdom (tests/_p155_console_strip.js)
 * against stubbed fetch() and a stub EventBus, and asserts the resulting DOM:
 *
 *   * the strip says "Listen-only" in words (mode, talkgroup) and has NO PTT
 *     button, never a data-launcher or data-real-ptt mark, whatever the
 *     operator's TX permission
 *   * an operator WITHOUT console TX permission is still offered "Listen" (a
 *     digital voice strip has no legacy widget to fall back on), and connecting
 *     for them never asks for the microphone; an operator WITH TX permission
 *     still does (their one shared connection must carry their mic)
 *   * a DMR strip (voice_tx) is unchanged: no toggle without TX permission
 *   * the RX lamp reads "RX", lights on comm:rx_state started, goes out on
 *     ended, survives a full repaint of the bank, ignores unknown ids and junk
 *   * console-mic.js treats both adapters as matrix-backed
 *
 * Part 1 is structural and runs everywhere; Part 2 needs Node and the jsdom
 * package (CI installs it) and prints SKIP for that part otherwise.
 *
 * Usage: php tests/test_voice_bridge_console_ui.php
 */
$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}
$root = dirname(__DIR__);
require_once __DIR__ . '/_test_node_probe.php';

echo "=== Phase 155 -- console strip for a listen-only digital voice bridge ===\n\n--- Part 1: structure ---\n\n";
$cjs = (string) file_get_contents($root . '/assets/js/console.js');
$css = (string) file_get_contents($root . '/assets/css/console.css');
t('console.js decides "listen-only" from the channel\'s own capabilities, not from an adapter name',
    strpos($cjs, 'var listenOnlyMatrix = isMatrixBacked && !caps.voice_tx;') !== false
    && strpos($cjs, "ch.adapter === 'dvmproject'") === false && strpos($cjs, "ch.adapter === 'usrp_bridge'") === false);
t('console.js subscribes to comm:rx_state', strpos($cjs, "window.EventBus.on('comm:rx_state', onRxState)") !== false);
t('the RX lamp has a watchdog so a lost "ended" event cannot leave it lit for ever', strpos($cjs, 'RX_LAMP_WATCHDOG_MS') !== false);
t('the lamp CSS is hidden until lit and the lit state exists', strpos($css, '.console-rx-lamp {') !== false && strpos($css, '.console-rx-lamp-on { display: inline-block; }') !== false);
$adminJs = (string) file_get_contents($root . '/assets/js/voice-bridges-admin.js');
t('ES5 only in the new admin JS (no arrow functions / let / const / template literals)', !preg_match('/=>|`|\blet\s|\bconst\s/', $adminJs));
t('no literal closing script tag sequence in the new JS (it would truncate an inline script)', stripos($adminJs, '</scr' . 'ipt') === false);
t('the admin JS builds DOM with textContent: no innerHTML assignment anywhere in it', strpos($adminJs, 'innerHTML') === false);

echo "\n--- Part 2: behaviour (the real console.js + console-mic.js in jsdom) ---\n\n";
$node = test_probe_cli(['node', 'node.exe']);
$jsdomOk = false;
if ($node !== null) {
    $chk = test_run_cli([$node, '-e', "try{require('jsdom');console.log('jsdom-ok')}catch(e){console.log('jsdom-missing')}"]);
    $jsdomOk = is_string($chk) && strpos($chk, 'jsdom-ok') !== false;
}
if (!$jsdomOk) {
    echo "SKIP (Part 2 only): Node.js with the jsdom package is not available here (set NODE_PATH to its node_modules).\n";
} else {
    $raw = (string) test_run_cli([$node, __DIR__ . '/_p155_console_strip.js', $root]);
    $json = null;
    foreach (preg_split('/\R/', $raw) as $line) {
        if (isset($line[0]) && $line[0] === '{') { $json = json_decode($line, true); break; }
    }
    if (!is_array($json) || isset($json['harness_error'])) {
        t('the jsdom harness ran: ' . substr($raw, 0, 400), false);
    } else {
        $o = $json;
        t('both strips rendered', !empty($o['strips_rendered']));
        t('the digital voice strip says Listen-only in words, with its mode and talkgroup',
            is_string($o['dvm_note_text']) && strpos($o['dvm_note_text'], 'Listen-only') === 0
            && strpos($o['dvm_note_text'], 'P25') !== false && strpos($o['dvm_note_text'], 'TG 9001') !== false);
        t('...and says how to hear it', strpos((string) $o['dvm_note_text'], 'Listen') !== false);
        t('...it has NO PTT button', $o['dvm_has_ptt_button'] === false);
        t('...it is not marked as a launcher, and not as a real-PTT target (a footswitch can never key it)',
            $o['dvm_has_launcher_mark'] === false && $o['dvm_has_real_ptt_mark'] === false);
        t('the amateur badge on the digital voice strip says "listen-only", not "ID required" (it makes no transmission to identify), while a DMR strip keeps "ID required"',
            $o['dvm_reg_badge'] === 'AMATEUR — listen-only' && $o['dmr_reg_badge'] === 'AMATEUR — ID required');
        t('an operator WITHOUT console TX permission is still offered the Listen toggle',
            $o['dvm_toggle_present_without_tx'] === true && $o['dvm_toggle_label'] === 'Listen');
        t('...and gets Monitor/Mute/Volume controls for it', $o['dvm_audio_block_present'] === true);
        t('a DMR strip (voice_tx) is unchanged: NO toggle without TX permission, and the old "(no TX permission)" note',
            $o['dmr_toggle_present_without_tx'] === false && $o['dmr_note_no_tx'] === 'Listen-only (no TX permission)');
        t('the RX lamp exists, reads "RX" and starts unlit',
            $o['dvm_rx_lamp_present'] === true && $o['dvm_rx_lamp_text'] === 'RX' && $o['dvm_rx_lamp_initially_on'] === false);
        t('comm:rx_state "started" lights that strip\'s lamp', $o['lamp_on_after_started'] === true);
        t('...and no other strip\'s lamp', $o['other_strip_lamp_untouched'] === true);
        t('...and a full repaint of the bank (tab click) keeps it lit', $o['tab_links'] >= 1 && $o['lamp_on_after_repaint'] === true);
        t('"ended" puts it out', $o['lamp_off_after_ended'] === true);
        t('an unknown channel id and a junk event do not throw', $o['junk_events_survived'] === true);
        t('connecting for an operator WITHOUT TX permission opens the connection but NEVER asks for the microphone',
            (int) $o['no_tx_getusermedia_calls'] === 0 && (int) $o['no_tx_websocket_opened'] === 1);
        t('connecting for an operator WITH TX permission still asks for the microphone (their PTT must carry audio)',
            (int) $o['tx_getusermedia_calls'] === 1);
        t('with TX permission the digital voice strip STILL has no PTT button and no real-PTT mark (it cannot transmit)',
            $o['tx_dvm_has_ptt_button'] === false && $o['tx_dvm_has_real_ptt_mark'] === false);
        t('...and the Matrix Audio toggle is offered for both the digital voice strip and the DMR strip with TX permission',
            $o['tx_dvm_toggle_present'] === true && $o['tx_dmr_toggle_present'] === true);
        t('with Matrix Audio ENGAGED, a transmit-capable DMR strip grows a real PTT button and is a footswitch target...',
            $o['engaged_dmr_has_ptt_button'] === true && $o['engaged_dmr_has_real_ptt_mark'] === true);
        t('...while the listen-only digital voice strip, in the very same state, still has NO PTT button and is NOT a footswitch target',
            $o['engaged_dvm_has_ptt_button'] === false && $o['engaged_dvm_has_real_ptt_mark'] === false);
        t('...and its note no longer tells the operator to turn Listen on (it is on)',
            is_string($o['engaged_dvm_note_text']) && strpos($o['engaged_dvm_note_text'], 'turn on Listen') === false
            && strpos($o['engaged_dvm_note_text'], 'Listen-only') === 0);
        t('console-mic.js treats dvmproject and usrp_bridge as matrix-backed, and still not zello',
            $o['mic_isMatrixBacked']['dvmproject'] === true && $o['mic_isMatrixBacked']['usrp_bridge'] === true
            && $o['mic_isMatrixBacked']['zello'] === false);
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
