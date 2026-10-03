<?php
/**
 * GH#148 (Phase 155) -- two dispatchers pressing "next tow" at the same instant must not offer the same slot twice.
 *
 * Real OS processes (proc_open with an argv array), a start barrier so both reach vendor_dispatch_offer() together, and
 * repeated rounds so a lucky ordering cannot hide a missing lock. Modelled on tests/test_inbound_calls_claim_race.php.
 *
 * Under any_offer (the default) the first offer consumes the head's turn, so exactly one RATIONAL offer lands and the other
 * dispatcher is told queue_changed (409) with nothing recorded. Under accepted_only a turn is only consumed on acceptance,
 * so both offers may legitimately land (documented, asserted).
 *
 * @requires-db
 * Usage: php tests/test_vendor_dispatch_concurrency.php
 */
require_once __DIR__ . '/_vendor_fixtures.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

$prefix = vf_prefix();
$admin = test_admin_user_id();
$A = vf_actor($admin, 'vf-admin');
vf_session_as($admin, null, 'vf-admin');
register_shutdown_function('vf_cleanup');

if (!vendor_schema_ready()) {
    echo "SKIP: the vendor tables are not installed\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
if (!function_exists('proc_open')) {
    echo "SKIP: proc_open() is disabled on this PHP install; the race needs genuinely concurrent processes\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$BASE = ['vendor_dispatch_enabled' => '1', 'vendor_rotation_mode' => 'round_robin', 'vendor_advance_rule' => 'any_offer',
         'vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1'];
vf_set_settings($BASE);
$TOW = vf_service_type_id('tow');

/**
 * Launch N probes at once, release them together, and collect their JSON.
 * @param array $inputs one offer input per probe
 */
function race(array $settings, array $inputs, array $actor): array
{
    $tmp = sys_get_temp_dir();
    $tag = getmypid() . '_' . mt_rand(1000, 9999);
    $go = "$tmp/vendor_race_go_$tag";
    @unlink($go);
    $procs = []; $outs = []; $readys = [];
    foreach ($inputs as $i => $input) {
        $readys[$i] = "$tmp/vendor_race_ready_{$tag}_$i";
        $outs[$i] = "$tmp/vendor_race_out_{$tag}_$i.json";
        @unlink($readys[$i]); @unlink($outs[$i]);
        $argv = [PHP_BINARY ?: 'php', __DIR__ . '/_vendor_offer_race_probe.php', json_encode($settings), json_encode($input), json_encode($actor), $readys[$i], $go];
        $spec = [0 => ['pipe', 'r'], 1 => ['file', $outs[$i], 'w'], 2 => ['file', $outs[$i] . '.err', 'w']];
        $procs[$i] = proc_open($argv, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (is_array($pipes) && isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
    }
    // wait for every probe to be warm and parked at the barrier
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        $all = true;
        foreach ($readys as $r) { if (!is_file($r)) { $all = false; break; } }
        if ($all) break;
        usleep(5000);
    }
    file_put_contents($go, '1');                       // release them together
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline) {
        $running = false;
        foreach ($procs as $p) { if (is_resource($p)) { $st = proc_get_status($p); if ($st['running']) $running = true; } }
        if (!$running) break;
        usleep(20000);
    }
    $results = [];
    foreach ($procs as $i => $p) {
        if (is_resource($p)) proc_close($p);
        $results[$i] = json_decode(trim((string) @file_get_contents($outs[$i])), true);
        if (!is_array($results[$i])) fwrite(STDERR, "probe $i: " . @file_get_contents($outs[$i]) . ' | ' . @file_get_contents($outs[$i] . '.err') . "\n");
        @unlink($outs[$i]); @unlink($outs[$i] . '.err'); @unlink($readys[$i]);
    }
    @unlink($go);
    return $results;
}

echo "=== GH#148 -- two dispatchers, one next-up ===\n\n";

// ══════════════════════════════════════════════════════════════
// any_offer: exactly one rotation offer lands per round
// ══════════════════════════════════════════════════════════════
echo "--- any_offer: five rounds, two dispatchers each, same head ---\n";
$allExactlyOne = true; $allLoserClean = true; $allNoDouble = true; $allProbesAnswered = true;
for ($round = 1; $round <= 5; $round++) {
    $t1 = vf_ticket(); $t2 = vf_ticket();
    $list = vf_list("VTC list r$round");
    $pa = vf_provider("VTC r$round A", ['phone' => '555-0201'], $A);
    $pb = vf_provider("VTC r$round B", ['phone' => '555-0202'], $A);
    $pc = vf_provider("VTC r$round C", ['phone' => '555-0203'], $A);
    vf_members($list, [$pa, $pb, $pc], $A);

    $mk = function ($ticket) use ($TOW, $list, $pa) {
        return ['ticket_id' => $ticket, 'service_type_id' => $TOW, 'list_id' => $list, 'provider_id' => $pa, 'client_head_provider_id' => $pa];
    };
    $res = race($BASE, [$mk($t1), $mk($t2)], $A);
    if (!is_array($res[0]) || !is_array($res[1])) { $allProbesAnswered = false; continue; }

    $okCount = ($res[0]['ok'] ? 1 : 0) + ($res[1]['ok'] ? 1 : 0);
    if ($okCount !== 1) $allExactlyOne = false;
    $winner = $res[0]['ok'] ? $res[0] : $res[1];
    $loser = $res[0]['ok'] ? $res[1] : $res[0];
    $loserTicket = $res[0]['ok'] ? $t2 : $t1;
    if (!($loser['ok'] === false && $loser['code'] === 'queue_changed' && $loser['http'] === 409
          && ($loser['queue']['head_provider_id'] ?? null) === $pb)) $allLoserClean = false;

    $offers = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `event_type` = 'offered' AND `selection_method` = 'rotation' AND `consumed_turn` = 1", [$list]);
    $aTurns = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `provider_id` = ? AND `consumed_turn` = 1", [$list, $pa]);
    $loserRows = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` = ?", [$loserTicket]);
    if ($offers !== 1 || $aTurns !== 1 || $loserRows !== 0) $allNoDouble = false;
}
t('every probe in every round answered with JSON', $allProbesAnswered);
t('in all 5 rounds exactly ONE of the two simultaneous offers landed', $allProbesAnswered && $allExactlyOne);
t('the loser always got 409 queue_changed with the fresh queue (next up = B)', $allProbesAnswered && $allLoserClean);
t('the ledger holds exactly one consumed rotation turn for the head, and the loser\'s incident has NO dispatch header and NO offer', $allProbesAnswered && $allNoDouble);

// ══════════════════════════════════════════════════════════════
// different heads never block each other's correctness: the second dispatcher moves on to B
// ══════════════════════════════════════════════════════════════
echo "\n--- the loser retries from the fresh queue and calls the NEW head ---\n";
$t1 = vf_ticket(); $t2 = vf_ticket();
$list = vf_list('VTC retry list');
$pa = vf_provider('VTC retry A', ['phone' => '555-0211'], $A);
$pb = vf_provider('VTC retry B', ['phone' => '555-0212'], $A);
vf_members($list, [$pa, $pb], $A);
$mk = function ($ticket, $head) use ($TOW, $list) {
    return ['ticket_id' => $ticket, 'service_type_id' => $TOW, 'list_id' => $list, 'provider_id' => $head, 'client_head_provider_id' => $head];
};
$res = race($BASE, [$mk($t1, $pa), $mk($t2, $pa)], $A);
$loserTicket = (is_array($res[0]) && $res[0]['ok']) ? $t2 : $t1;
$retry = vendor_dispatch_offer($mk($loserTicket, $pb), $A);
t('the dispatcher who lost the race can call the new head (B) straight away', $retry['ok'] === true && $retry['selection_method'] === 'rotation');
$q = vendor_rotation_queue($list);
t('after both dispatchers the rotation has moved past A and B (each consumed exactly one turn)', (int) db_fetch_value(
    "SELECT COUNT(DISTINCT `provider_id`) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `consumed_turn` = 1", [$list]) === 2);

// ══════════════════════════════════════════════════════════════
// accepted_only: both may land (a turn is consumed only on acceptance)
// ══════════════════════════════════════════════════════════════
echo "\n--- accepted_only: both offers may land (documented behaviour) ---\n";
$acc = $BASE; $acc['vendor_advance_rule'] = 'accepted_only';
$t1 = vf_ticket(); $t2 = vf_ticket();
$list = vf_list('VTC accepted_only list');
$pa = vf_provider('VTC acc A', ['phone' => '555-0221'], $A);
$pb = vf_provider('VTC acc B', ['phone' => '555-0222'], $A);
vf_members($list, [$pa, $pb], $A);
$mk = function ($ticket) use ($TOW, $list, $pa) {
    return ['ticket_id' => $ticket, 'service_type_id' => $TOW, 'list_id' => $list, 'provider_id' => $pa, 'client_head_provider_id' => $pa];
};
$res = race($acc, [$mk($t1), $mk($t2)], $A);
t('under accepted_only both simultaneous offers to the head are recorded (nobody has used a turn yet)',
    is_array($res[0]) && is_array($res[1]) && $res[0]['ok'] === true && $res[1]['ok'] === true
    && $res[0]['selection_method'] === 'rotation' && $res[1]['selection_method'] === 'rotation');
t('...and neither consumed a turn', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `consumed_turn` = 1", [$list]) === 0);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
