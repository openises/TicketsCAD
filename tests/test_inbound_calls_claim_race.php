<?php
/**
 * Phase 149 — FR-12/plan.md §4: the atomic claim is race-free.
 *
 * Two complementary proofs, both sanctioned by plan.md §10's own test
 * strategy ("real concurrent requests OR two sequential calls to the
 * real claim function"):
 *
 *   Part A — drives the REAL inbound_call_claim() function twice in a
 *   row against the same ringing call. The single atomic conditional
 *   UPDATE (`WHERE id = ? AND state = 'ringing'`) means the SECOND call
 *   deterministically sees state already flipped to 'claimed' by the
 *   first — proving the WHERE-clause-as-check mechanism itself, and
 *   proving the response shape is a flat ok:false/reason (never a
 *   needs_confirmation dialog shape — the GH#82/83 pattern this feature
 *   deliberately does NOT reuse, per plan.md §4).
 *
 *   Part B — fires two REAL, near-simultaneous requests against the REAL
 *   api/inbound-calls.php?action=claim endpoint code path for the SAME
 *   ringing call, each in its OWN genuinely concurrent OS process (via
 *   proc_open(), never curl against a running Apache — no web server
 *   required), matching spec.md success criterion #2's literal wording
 *   ("two simulated near-simultaneous 'Answer' clicks"). InnoDB's row
 *   lock on the UPDATE is what actually makes this safe under true
 *   concurrency, not application-level coordination — this proves it
 *   across two real processes hitting the database at once, not just
 *   two sequential calls in a single PHP process (Part A above).
 *
 * Usage: php tests/test_inbound_calls_claim_race.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/inbound-calls.php';
require_once __DIR__ . '/../inc/sip_token.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 — atomic claim race safety (FR-12) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$trunkId = 900014990;
$userAId = 900014991;
$userBId = 900014992;

$cleanup = function () use ($prefix, $trunkId, $userAId, $userBId) {
    try {
        $ids = db_fetch_all("SELECT id FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]);
        foreach ($ids as $r) db_query("DELETE FROM `{$prefix}inbound_call_events` WHERE `call_id` = ?", [(int) $r['id']]);
    } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ?", [$trunkId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` IN (?, ?)", [$userAId, $userBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE `id` IN (?, ?)", [$userAId, $userBId]); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {
    db_query(
        "INSERT INTO `{$prefix}pbx_trunks` (`id`, `label`, `org_id`, `bearer_token`, `enabled`)
         VALUES (?, 'Race Fixture Trunk', NULL, ?, 1)",
        [$trunkId, sip_token_mint()]
    );

    // ══════════════════════════════════════════════════════════════════
    // Part A — sequential calls to the real function
    // ══════════════════════════════════════════════════════════════════
    echo "--- Part A: sequential calls to inbound_call_claim() ---\n\n";

    $r = inbound_calls_ingest_event(
        db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$trunkId]),
        ['event' => 'ringing', 'call_id' => 'race-fixture-1', 'caller_number' => '+16125550301', 'event_ts' => date('c')]
    );
    $callId = $r['call_id'];
    t('fixture call is ringing', $r['ok'] === true);

    $claimA = inbound_call_claim($callId, 111, 'Dispatcher A');
    t('the FIRST claim succeeds', $claimA['ok'] === true);
    t('the first claim result carries the updated call row', isset($claimA['call']) && $claimA['call']['state'] === 'claimed');

    $claimB = inbound_call_claim($callId, 222, 'Dispatcher B');
    t('the SECOND claim on the same call fails', $claimB['ok'] === false);
    t("the second claim's reason is 'already_claimed'", $claimB['reason'] === 'already_claimed');
    t('the second claim reports WHO already has it', $claimB['claimed_by_name'] === 'Dispatcher A');
    t('the failure shape is a flat ok:false/reason -- NEVER a needs_confirmation dialog (deliberately not the GH#82/83 pattern)',
        !array_key_exists('needs_confirmation', $claimB));

    // Exactly one 'claimed' audit row, not two.
    $claimedEvents = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? AND `event_type` = 'claimed'",
        [$callId]
    );
    t('exactly ONE claimed audit row exists despite two claim attempts', $claimedEvents === 1);

    $finalRow = inbound_call_get($callId);
    t('the call is attributed to the FIRST claimant only', (int) $finalRow['claimed_by'] === 111);

    // Regression guard (found live 2026-08-22 via the Browser pane, NOT by
    // this test suite): inbound_call_broadcast_payload() -- consumed by
    // BOTH the SSE broadcast AND api/inbound-calls.php's `list` action --
    // originally omitted `claimed_by` (the numeric user id) entirely,
    // shipping only `claimed_by_name` (a display string). call-alert.js's
    // "is this MY OWN claim" check (the Take-button visibility guard, and
    // the FR-10 self-quieting check) compares against `claimed_by`, so it
    // silently always evaluated to false/NaN -- a user was shown the
    // "Take" button on a call THEY THEMSELVES already held, and never got
    // the self-quieting benefit either. Every existing JS test constructs
    // its OWN fake call objects (a hand-seeded payload, not the real
    // PHP-generated one), so none of them could have caught this — this
    // assertion is the one place proving the REAL function's output shape
    // actually carries the field the client depends on.
    $fixtureTrunk = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$trunkId]);
    $payload = inbound_call_broadcast_payload($finalRow, $fixtureTrunk);
    t('inbound_call_broadcast_payload() includes claimed_by (the numeric id, not just claimed_by_name)',
        array_key_exists('claimed_by', $payload) && $payload['claimed_by'] === 111);

    // ══════════════════════════════════════════════════════════════════
    // Part B — real concurrent HTTP requests
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part B: two REAL, near-simultaneous concurrent-process claim requests ---\n\n";

    if (!function_exists('proc_open')) {
        echo "SKIP: proc_open() is disabled on this PHP install — Part B needs it to launch genuinely concurrent processes\n";
    } else {
        $r2 = inbound_calls_ingest_event(
            db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$trunkId]),
            ['event' => 'ringing', 'call_id' => 'race-fixture-2', 'caller_number' => '+16125550302', 'event_ts' => date('c')]
        );
        $callId2 = $r2['call_id'];

        // Two probe processes, each with their OWN session as a distinct
        // fixture user, launched via shell background jobs so both
        // requests are genuinely in flight on the server at the same time
        // (real OS-level concurrency, not two sequential PHP calls).
        db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, 'p149-race-a', ?)", [$userAId, password_hash('x', PASSWORD_BCRYPT)]);
        db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, 'p149-race-b', ?)", [$userBId, password_hash('x', PASSWORD_BCRYPT)]);
        db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 1), (?, 1)", [$userAId, $userBId]);

        $probe = __DIR__ . '/_p149_claim_race_probe.php';
        $outA = sys_get_temp_dir() . '/p149_race_a_' . getmypid() . '.json';
        $outB = sys_get_temp_dir() . '/p149_race_b_' . getmypid() . '.json';
        $phpBin = PHP_BINARY ?: 'php';

        // proc_open() returns immediately without waiting for the child
        // (unlike shell_exec()), so both launches below fire in short
        // succession and both children are genuinely in flight on the
        // server at the same time — real OS-level concurrency, portable
        // across Windows/POSIX without relying on shell-specific
        // background-job syntax ('start /B', trailing '&', etc.).
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['file', $outA, 'w'], 2 => ['file', $outA, 'a']];
        $procA = proc_open([$phpBin, $probe, (string) $callId2, (string) $userAId], $descriptorSpec, $pipesA);
        $descriptorSpecB = [0 => ['pipe', 'r'], 1 => ['file', $outB, 'w'], 2 => ['file', $outB, 'a']];
        $procB = proc_open([$phpBin, $probe, (string) $callId2, (string) $userBId], $descriptorSpecB, $pipesB);
        if (is_array($pipesA)) foreach ($pipesA as $p) if (is_resource($p)) fclose($p);
        if (is_array($pipesB)) foreach ($pipesB as $p) if (is_resource($p)) fclose($p);

        // Bounded wait for both children to exit (never a fixed sleep).
        $deadline = microtime(true) + 10;
        $doneA = false; $doneB = false;
        while (microtime(true) < $deadline && !($doneA && $doneB)) {
            if (is_resource($procA)) { $stA = proc_get_status($procA); if (!$stA['running']) $doneA = true; }
            if (is_resource($procB)) { $stB = proc_get_status($procB); if (!$stB['running']) $doneB = true; }
            if (!($doneA && $doneB)) usleep(50000);
        }
        if (is_resource($procA)) proc_close($procA);
        if (is_resource($procB)) proc_close($procB);

        $resA = @json_decode((string) @file_get_contents($outA), true);
        $resB = @json_decode((string) @file_get_contents($outB), true);
        @unlink($outA); @unlink($outB);

        if (!is_array($resA) || !is_array($resB)) {
            t('both concurrent probe processes produced output (environment-dependent — see raw output below)', false);
            echo "  A: " . var_export($resA, true) . "\n  B: " . var_export($resB, true) . "\n";
        } else {
            $successCount = (int) ($resA['success'] ? 1 : 0) + (int) ($resB['success'] ? 1 : 0);
            t('exactly ONE of the two concurrent claim requests succeeds', $successCount === 1);
            $loser = $resA['success'] ? $resB : $resA;
            t('the losing request is told immediately who has it (no confirmation dialog)',
                $loser['success'] === false && $loser['reason'] === 'already_claimed' && !empty($loser['claimed_by_name']));

            $finalRow2 = inbound_call_get($callId2);
            t('the database agrees: exactly one claimant on the call', $finalRow2 && in_array((int) $finalRow2['claimed_by'], [$userAId, $userBId], true));
        }
    }

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
