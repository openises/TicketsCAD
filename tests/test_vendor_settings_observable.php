<?php
/**
 * GH#148 (Phase 155) -- every setting must change something a user can SEE.
 *
 * A setting with a UI, a database row and a migration but no reader that branches on it is a bug this project keeps
 * finding (tile_mode lived like that for five months). So this is not a round-trip test: each setting is flipped and the
 * OBSERVABLE behaviour is compared.
 *
 *   vendor_dispatch_enabled          the incident page gains / loses the button, card, dialog, stylesheet and script; the API answers differently
 *   vendor_rotation_mode (+ per-list) three modes, three orders, three answers to "who is next"
 *   vendor_advance_rule              the same decline leaves a different head
 *   vendor_allow_override            a non-head pick is refused or accepted
 *   vendor_override_requires_reason  an override with no reason is refused or accepted
 *   phone_click_to_call              the REAL dialog JS, in jsdom: "Log call" + plain number vs "Call" + tel: anchor / browser phone
 *
 * Also: a junk value written straight into `settings` (the generic endpoint will store anything) is CLAMPED to the default
 * by the readers, and the dedicated writer round-trips to the readers.
 *
 * Settings-dependent cases each run in a fresh PHP process (get_variable() caches the whole table for a process's life).
 *
 * @requires-db
 * Usage: php tests/test_vendor_settings_observable.php
 */
require_once __DIR__ . '/_vendor_fixtures.php';
require_once __DIR__ . '/../inc/i18n.php';

$pass = 0; $fail = 0;
function chk($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

$prefix = vf_prefix();
$root = dirname(__DIR__);
$admin = test_admin_user_id();
$A = vf_actor($admin, 'vf-admin');
vf_session_as($admin, null, 'vf-admin');
register_shutdown_function('vf_cleanup');

if (!vendor_schema_ready()) {
    echo "SKIP: the vendor tables are not installed\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$BASE = ['vendor_dispatch_enabled' => '1', 'vendor_rotation_mode' => 'round_robin', 'vendor_advance_rule' => 'any_offer',
         'vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1', 'phone_click_to_call' => 'off'];
vf_set_settings($BASE);
$TOW = vf_service_type_id('tow');

function with(array $base, array $over): array { return array_merge($base, $over); }

echo "=== GH#148 -- every setting changes observable behaviour ===\n\n";

// ══════════════════════════════════════════════════════════════
// vendor_dispatch_enabled: the page, and the API
// ══════════════════════════════════════════════════════════════
echo "--- vendor_dispatch_enabled: the incident page ---\n";
$tid = vf_ticket();
function render_page(int $user, int $ticket): string
{
    $argv = [PHP_BINARY ?: 'php', __DIR__ . '/_vendor_page_probe.php', (string) $user, (string) $ticket];
    $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    return (string) $out;
}
$markers = ['id="btnVendorDispatch"', 'id="vendorDispatchCard"', 'id="vendorDispatchModal"', 'assets/css/vendor-dispatch.css', 'assets/js/vendor-dispatch.js', 'VENDOR_STR'];

vf_set_settings(with($BASE, ['vendor_dispatch_enabled' => '0']));
$off = render_page($admin, $tid);
chk('(control) the page rendered', strlen($off) > 50000 && strpos($off, 'incident-detail.js') !== false);
$present = array_filter($markers, function ($m) use ($off) { return strpos($off, $m) !== false; });
chk('OFF: the page contains NONE of the button, card, dialog, stylesheet, script or caption object', $present === []);
if ($present) echo "    still present: " . implode(', ', $present) . "\n";

vf_set_settings(with($BASE, ['vendor_dispatch_enabled' => '1']));
$on = render_page($admin, $tid);
$missing = array_filter($markers, function ($m) use ($on) { return strpos($on, $m) === false; });
chk('ON: the page contains the button, card, dialog, stylesheet, script and caption object', $missing === []);
if ($missing) echo "    missing: " . implode(', ', $missing) . "\n";
$posBase = strpos($on, '<script src="assets/js/incident-detail.js');
$posVend = strpos($on, '<script src="assets/js/vendor-dispatch.js');
chk('ON: vendor-dispatch.js loads AFTER incident-detail.js', $posBase !== false && $posVend !== false && $posVend > $posBase);
chk('ON: the button and card start hidden (d-none): the script reveals them only after the server says this caller may dispatch here',
    (bool) preg_match('/id="btnVendorDispatch"/', $on) && (bool) preg_match('/class="[^"]*d-none[^"]*" id="btnVendorDispatch"/', $on)
    && (bool) preg_match('/class="[^"]*d-none[^"]*" id="vendorDispatchCard"/', $on));

// a caller WITHOUT the permission gets nothing even when the feature is on
$oper = vf_user('vts-operator', 4);
$operPage = render_page($oper, $tid);
$operHas = array_filter($markers, function ($m) use ($operPage) { return strpos($operPage, $m) !== false; });
chk('ON, but the caller lacks action.dispatch_vendor (an Operator): still none of it', strlen($operPage) > 20000 ? $operHas === [] : true);

echo "\n--- vendor_dispatch_enabled: the API ---\n";
$w = vf_worker(with($BASE, ['vendor_dispatch_enabled' => '0']), 'enabled', []);
chk('OFF: vendor_enabled() is false', $w && $w['enabled'] === false);
$w = vf_worker($BASE, 'enabled', []);
chk('ON: vendor_enabled() is true', $w && $w['enabled'] === true);

// ══════════════════════════════════════════════════════════════
// rotation mode, global and per list
// ══════════════════════════════════════════════════════════════
echo "\n--- vendor_rotation_mode: three modes, three answers ---\n";
$list = vf_list('VTS modes list');
$p = [];
foreach (['A', 'B', 'C'] as $i => $n) $p[$n] = vf_provider("VTS $n", ['phone' => '555-06' . (10 + $i)], $A);
vf_members($list, [$p['A'], $p['B'], $p['C']], $A);
$tk = vf_ticket();
vendor_dispatch_offer(['ticket_id' => $tk, 'service_type_id' => $TOW, 'list_id' => $list, 'provider_id' => $p['A'], 'client_head_provider_id' => $p['A']], $A);   // A has had a turn
$rr = vf_worker(with($BASE, ['vendor_rotation_mode' => 'round_robin']), 'queue', ['list_id' => $list]);
$so = vf_worker(with($BASE, ['vendor_rotation_mode' => 'strict_order']), 'queue', ['list_id' => $list]);
$mn = vf_worker(with($BASE, ['vendor_rotation_mode' => 'manual']), 'queue', ['list_id' => $list]);
chk('round_robin: A (just called) is at the back and B is next', $rr['order'] === [$p['B'], $p['C'], $p['A']] && $rr['head_provider_id'] === $p['B'] && $rr['mode'] === 'round_robin');
chk('strict_order: the list order is fixed, so A is next regardless of history', $so['order'] === [$p['A'], $p['B'], $p['C']] && $so['head_provider_id'] === $p['A'] && $so['mode'] === 'strict_order');
chk('manual: the same order as the list, and NO company is marked next up', $mn['order'] === [$p['A'], $p['B'], $p['C']] && $mn['head_provider_id'] === null && $mn['heads'] === []);
chk('the three modes give three different observable answers from ONE ledger', $rr['order'] !== $so['order'] && $so['head_provider_id'] !== $mn['head_provider_id'] && $rr['head_provider_id'] !== $so['head_provider_id']);
vendor_list_save(['id' => $list, 'name' => 'VTS modes list', 'service_type_id' => $TOW, 'mode' => 'strict_order'], $A);
$perList = vf_worker(with($BASE, ['vendor_rotation_mode' => 'round_robin']), 'queue', ['list_id' => $list]);
chk('a PER-LIST mode overrides the install-wide setting (global round_robin, list strict_order -> strict)', $perList['mode'] === 'strict_order' && $perList['head_provider_id'] === $p['A']);
vendor_list_save(['id' => $list, 'name' => 'VTS modes list', 'service_type_id' => $TOW, 'mode' => ''], $A);
chk('clearing the list mode returns it to the install-wide setting', vf_worker(with($BASE, ['vendor_rotation_mode' => 'manual']), 'queue', ['list_id' => $list])['mode'] === 'manual');

// ══════════════════════════════════════════════════════════════
// advance rule / override / reason
// ══════════════════════════════════════════════════════════════
echo "\n--- vendor_advance_rule: a decline leaves a different head ---\n";
function after_decline(array $settings, array $A, int $TOW): ?int
{
    $tk = vf_ticket();
    $list = vf_list('VTS adv ' . mt_rand(1000, 9999));
    $a = vf_provider('VTS adv A ' . mt_rand(1000, 9999), ['phone' => '555-0701'], $A);
    $b = vf_provider('VTS adv B ' . mt_rand(1000, 9999), ['phone' => '555-0702'], $A);
    vf_members($list, [$a, $b], $A);
    $o = vf_worker($settings, 'offer', ['input' => ['ticket_id' => $tk, 'service_type_id' => $TOW, 'list_id' => $list, 'provider_id' => $a, 'client_head_provider_id' => $a], 'actor' => $A]);
    vf_worker($settings, 'outcome', ['dispatch_id' => $o['dispatch_id'], 'offer_event_id' => $o['event_id'], 'outcome' => 'declined', 'actor' => $A]);
    $q = vf_worker($settings, 'queue', ['list_id' => $list]);
    return ($q['head_provider_id'] === $a) ? 1 : (($q['head_provider_id'] === $b) ? 2 : null);
}
$headAny = after_decline(with($BASE, ['vendor_advance_rule' => 'any_offer']), $A, $TOW);
$headAcc = after_decline(with($BASE, ['vendor_advance_rule' => 'accepted_only']), $A, $TOW);
chk('any_offer: after A declines, the NEXT call starts at B (the offer used A\'s turn)', $headAny === 2);
chk('accepted_only: after A declines, the next call STILL starts at A (a decline costs nothing)', $headAcc === 1);

echo "\n--- vendor_allow_override / vendor_override_requires_reason ---\n";
function override_try(array $settings, array $A, int $TOW, string $reason): ?array
{
    $tk = vf_ticket();
    $list = vf_list('VTS ov ' . mt_rand(1000, 9999));
    $a = vf_provider('VTS ov A ' . mt_rand(1000, 9999), ['phone' => '555-0801'], $A);
    $b = vf_provider('VTS ov B ' . mt_rand(1000, 9999), ['phone' => '555-0802'], $A);
    vf_members($list, [$a, $b], $A);
    $in = ['ticket_id' => $tk, 'service_type_id' => $TOW, 'list_id' => $list, 'provider_id' => $b, 'client_head_provider_id' => $a];
    if ($reason !== '') $in['reason'] = $reason;
    return vf_worker($settings, 'offer', ['input' => $in, 'actor' => $A]);
}
$r = override_try(with($BASE, ['vendor_allow_override' => '0']), $A, $TOW, 'Closest truck');
chk('allow_override=0: the non-head pick is REFUSED', $r && $r['ok'] === false && $r['code'] === 'override_not_allowed');
$r = override_try(with($BASE, ['vendor_allow_override' => '1']), $A, $TOW, 'Closest truck');
chk('allow_override=1: the same pick is ACCEPTED as an override', $r && $r['ok'] === true && $r['selection_method'] === 'override');
$r = override_try(with($BASE, ['vendor_override_requires_reason' => '1']), $A, $TOW, '');
chk('requires_reason=1: an override with no reason is REFUSED', $r && $r['ok'] === false && $r['code'] === 'reason_required');
$r = override_try(with($BASE, ['vendor_override_requires_reason' => '0']), $A, $TOW, '');
chk('requires_reason=0: the same override with no reason is ACCEPTED', $r && $r['ok'] === true && $r['selection_method'] === 'override');

// ══════════════════════════════════════════════════════════════
// junk in the table is clamped by the readers
// ══════════════════════════════════════════════════════════════
echo "\n--- a junk value written straight into settings is clamped to the default ---\n";
$junk = ['vendor_dispatch_enabled' => 'yes', 'vendor_rotation_mode' => 'sideways', 'vendor_advance_rule' => 'x', 'vendor_allow_override' => 'maybe',
         'vendor_override_requires_reason' => '2', 'phone_click_to_call' => 'carrier-pigeon'];
$w = vf_worker($junk, 'enabled', []);
chk('junk for every setting reads as the shipped default', $w && $w['enabled'] === false && $w['mode'] === 'round_robin' && $w['advance'] === 'any_offer'
    && $w['allow_override'] === '1' && $w['requires_reason'] === '1' && $w['dial'] === 'off');
vf_set_settings($BASE);

echo "\n--- the dedicated writer round-trips to the readers ---\n";
$sv = vendor_settings_save(['vendor_rotation_mode' => 'manual', 'vendor_advance_rule' => 'accepted_only', 'vendor_allow_override' => '0',
                            'vendor_override_requires_reason' => '0', 'phone_click_to_call' => 'widget', 'vendor_dispatch_enabled' => '1'], $A);
$w = vf_worker([], 'enabled', []);          // NO settings passed: whatever vendor_settings_save wrote is what the fresh process reads
chk('values saved by vendor_settings_save() are what a fresh process reads', $sv['ok'] === true && $w['mode'] === 'manual' && $w['advance'] === 'accepted_only'
    && $w['allow_override'] === '0' && $w['requires_reason'] === '0' && $w['dial'] === 'widget' && $w['enabled'] === true);
vf_set_settings($BASE);

// ══════════════════════════════════════════════════════════════
// phone_click_to_call: the REAL dialog in jsdom
// ══════════════════════════════════════════════════════════════
echo "\n--- phone_click_to_call: the real dialog script in jsdom ---\n";
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
$node = $node === '' ? '' : strtok($node, "\r\n");
$jsdomOk = false;
if ($node !== '') {
    // Resolve jsdom from the HARNESS's own directory (that is where the real run resolves it), and match an exact marker:
    // a loose strpos($out, 'ok') also matches the words in a "Cannot find module" error.
    $probe = @shell_exec('"' . $node . '" -e "require.resolve(\'jsdom\', { paths: [process.argv[1]] });console.log(\'JSDOM_AVAILABLE\')" "' . $root . '/tests" 2>&1');
    $jsdomOk = is_string($probe) && trim($probe) === 'JSDOM_AVAILABLE';
}
if (!$jsdomOk) {
    echo "SKIP: this part needs Node and the jsdom package (npm install jsdom); everything above still ran\n";
} else {
    ob_start();
    include $root . '/inc/vendor-dispatch-modal.php';
    $modalHtml = ob_get_clean();
    $js = file_get_contents($root . '/assets/js/vendor-dispatch.js');
    $run = function (string $mode, string $scenario) use ($node, $modalHtml, $js, $root) {
        $tmp = sys_get_temp_dir() . '/vendor_dom_' . getmypid() . '_' . mt_rand(1000, 9999) . '.json';
        file_put_contents($tmp, json_encode(['html' => $modalHtml, 'js' => $js, 'dialMode' => $mode, 'scenario' => $scenario]));
        $proc = proc_open([$node, $root . '/tests/_vendor_dispatch_dom.js', $tmp], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        @unlink($tmp);
        $d = json_decode((string) $out, true);
        if (!is_array($d)) fwrite(STDERR, "node harness: " . substr($out, 0, 300) . ' | ' . substr($err, 0, 600) . "\n");
        return $d;
    };

    $off = $run('off', 'offer');
    chk('(setup) the real script revealed the card and the button after the server confirmed config', $off && $off['cardRevealed'] === true && $off['buttonRevealed'] === true);
    chk('(setup) the queue rendered two rows, the first marked NEXT UP', $off && count($off['rows']) === 2 && strpos($off['rows'][0]['text'], 'NEXT UP') !== false);
    chk('OFF: the button reads "Log call"', $off && $off['rows'][0]['button'] === 'Log call');
    chk('OFF: the number is plain text: NO tel: anchor anywhere in the queue', $off && count($off['rows'][0]['telAnchors']) === 0 && count($off['rows'][1]['telAnchors']) === 0);
    chk('every number carries data-dial and a data-dial-ctx naming the provider and the incident (the click-to-dial module upgrades these)',
        $off && $off['rows'][0]['dial'][0]['number'] === '555-0100' && $off['rows'][0]['dial'][0]['tag'] === 'span'
        && strpos($off['rows'][0]['dial'][0]['ctx'], '"target_type":"vendor_provider"') !== false && strpos($off['rows'][0]['dial'][0]['ctx'], '"target_id":11') !== false
        && strpos($off['rows'][0]['dial'][0]['ctx'], '"ticket_id":42') !== false);
    chk('OFF: Log call records the offer and dials NOTHING', $off && in_array('POST offer', $off['afterClickLog'], true)
        && count(array_filter($off['afterClickLog'], function ($l) { return strpos($l, 'tel:') === 0 || strpos($l, 'placeCall') === 0; })) === 0);
    chk('the offer carries what the screen showed as next (client_head_provider_id) and what was picked', $off && $off['offerBody']['client_head_provider_id'] === 11 && $off['offerBody']['provider_id'] === 11
        && $off['offerBody']['list_id'] === 5 && $off['offerBody']['service_type_id'] === 1 && $off['offerBody']['csrf_token'] === 'tok');
    chk('after the offer the call shows under "awaiting an outcome" with the number dialled', $off && $off['pendingShown'] === true && strpos($off['pendingText'], 'Anderson Towing') !== false && strpos($off['pendingText'], '555-0100') !== false);

    $tel = $run('tel_link', 'offer');
    chk('tel_link: the button reads "Call"', $tel && $tel['rows'][0]['button'] === 'Call');
    chk('tel_link: the number is rendered as a tel: anchor', $tel && $tel['rows'][0]['telAnchors'] === ['tel:5550100'] && $tel['rows'][0]['dial'][0]['tag'] === 'a');
    $iPost = $tel ? array_search('POST offer', $tel['afterClickLog'], true) : false;
    $iTel = $tel ? array_search('tel:tel:5550100', $tel['afterClickLog'], true) : false;
    chk('tel_link: the offer is RECORDED FIRST, the number is dialled SECOND', $iPost !== false && $iTel !== false && $iPost < $iTel);

    $anchor = $run('tel_link', 'anchor');
    chk('tel_link: clicking the NUMBER itself also records the offer before dialling (the anchor cannot bypass the ledger)',
        $anchor && array_search('POST offer', $anchor['afterClickLog'], true) !== false && array_search('tel:tel:5550100', $anchor['afterClickLog'], true) !== false
        && array_search('POST offer', $anchor['afterClickLog'], true) < array_search('tel:tel:5550100', $anchor['afterClickLog'], true));

    $wid = $run('widget', 'offer');
    chk('widget: the button reads "Call" and the number is NOT a tel: anchor', $wid && $wid['rows'][0]['button'] === 'Call' && count($wid['rows'][0]['telAnchors']) === 0);
    $iPost = $wid ? array_search('POST offer', $wid['afterClickLog'], true) : false;
    $iPlace = $wid ? array_search('placeCall:5550100', $wid['afterClickLog'], true) : false;
    chk('widget: the offer is recorded first, then the browser phone dials', $iPost !== false && $iPlace !== false && $iPost < $iPlace);

    $qc = $run('tel_link', 'queue_changed');
    chk('queue_changed: NOTHING is dialled (the rotation moved; nothing was recorded)', $qc && count(array_filter($qc['afterClickLog'], function ($l) { return strpos($l, 'tel:') === 0 || strpos($l, 'placeCall') === 0; })) === 0);
    chk('queue_changed: the dispatcher is told who is next now', $qc && $qc['errorShown'] === true && strpos($qc['errorText'], 'Bergstrom Wrecker is next now') !== false);
    chk('queue_changed: the queue is redrawn from the fresh answer (Bergstrom first)', $qc && strpos($qc['queueHeadAfter'], 'Bergstrom Wrecker') !== false);

    $xss = $run('off', 'xss');
    chk('XSS: a company name containing markup is shown as TEXT (no element created, no handler run)', $xss && $xss['imgInQueue'] === 0 && $xss['pwned'] === false && $xss['logoText'] === true);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
