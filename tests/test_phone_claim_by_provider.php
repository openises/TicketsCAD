<?php
/**
 * Phase 155, GH#108 slice S4 -- ONE Answer: api/inbound-calls.php
 * claim_by_provider (the server half).
 *
 * Answering in the browser phone used to answer only the audio; a dispatcher
 * then had to click Answer AGAIN in the Phase 149 banner to claim the call and
 * open the New Incident form (F4). Nothing could correlate the two: the bridge
 * keys calls by the PBX's channel id and the browser only knows a SIP header.
 * The reference dial plan now tags the INVITE with X-Call-Linkedid, the bridge
 * keys by Linkedid, and the widget posts that id to claim_by_provider, which
 * resolves it to a row the caller may see and then runs the UNCHANGED claim path
 * (atomic UPDATE, audit event, SSE).
 *
 * Calls are created through the REAL ingest (inbound_calls_ingest_event, what
 * api/sip-ingest.php runs); the endpoint is driven as a real subprocess per
 * request (tests/_p155_api_probe.php) as different real users.
 *
 * @requires-db
 * Usage: php tests/test_phone_claim_by_provider.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/../inc/inbound-calls.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 / S4 -- claim_by_provider (one Answer) ===\n\n";

function p155cp_probe(string $action, int $userId, array $body, array $session = []): ?array {
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_api_probe.php', 'api/inbound-calls.php', 'POST', $action, (string) $userId,
            json_encode($body), $session ? json_encode($session) : ''];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $d = json_decode(trim((string) $out), true);
    return is_array($d) ? $d : null;
}

$adminId = test_admin_user_id();
$dispA = 900015531; $dispB = 900015532; $noRole = 900015533; $orgUser = 900015534;
$orgX = 900015541; $orgY = 900015542;
$trunkIds = []; $callIds = [];
$cleanup = function () use ($prefix, $dispA, $dispB, $noRole, $orgUser, $orgX, $orgY, &$trunkIds, &$callIds) {
    foreach ($callIds as $id) {
        try { db_query("DELETE FROM `{$prefix}inbound_call_events` WHERE call_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}inbound_calls` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($trunkIds as $id) {
        try { db_query("DELETE FROM `{$prefix}inbound_calls` WHERE trunk_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ([$dispA, $dispB, $noRole, $orgUser] as $uid) {
        try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$uid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$uid]); } catch (Throwable $e) {}
    }
    foreach ([$orgX, $orgY] as $o) { try { db_query("DELETE FROM `{$prefix}organizations` WHERE id = ?", [$o]); } catch (Throwable $e) {} }
};
$cleanup();
register_shutdown_function($cleanup);

function p155cp_trunk(string $label, ?int $orgId): array {
    global $prefix, $trunkIds;
    db_query("INSERT INTO `{$prefix}pbx_trunks` (label, bearer_token, enabled, org_id) VALUES (?, ?, 1, ?)",
        [$label, bin2hex(random_bytes(16)), $orgId]);
    $id = (int) db_insert_id();
    $trunkIds[] = $id;
    return db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]);
}
function p155cp_ring(array $trunk, string $providerId): int {
    global $callIds;
    $r = inbound_calls_ingest_event($trunk, [
        'event' => 'ringing', 'call_id' => $providerId, 'caller_number' => '6125559876', 'called_number' => '100',
    ]);
    $id = (int) ($r['call_id'] ?? 0);
    if ($id > 0) { $callIds[] = $id; }
    return $id;
}
function p155cp_state(int $id): ?array {
    global $prefix;
    return db_fetch_one("SELECT state, claimed_by, claimed_by_name FROM `{$prefix}inbound_calls` WHERE id = ?", [$id]) ?: null;
}

try {
    foreach ([[$dispA, 'p155cpdispa'], [$dispB, 'p155cpdispb'], [$noRole, 'p155cpnorole'], [$orgUser, 'p155cporguser']] as $u) {
        db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
            [$u[0], $u[1], password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
    }
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 3)", [$dispA]);
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 3)", [$dispB]);

    $trunk = p155cp_trunk('p155-claim-trunk', null);
    $tag = 'p155' . bin2hex(random_bytes(4));

    // ── The payload now carries the PBX's own id ────────────────────────
    $pid1 = $tag . '.1';
    $call1 = p155cp_ring($trunk, $pid1);
    t('a ringing call was ingested through the real ingest', $call1 > 0);
    $payload = inbound_call_broadcast_payload(inbound_call_get($call1), $trunk);
    t('the SSE/list payload carries provider_call_id (the banner needs it to tell the phone which leg to answer)',
        ($payload['provider_call_id'] ?? null) === $pid1);
    t('...and still carries no constituent identity or history (FR-26 line unchanged)',
        !isset($payload['constituent_id']) && !isset($payload['history']) && !isset($payload['constituent']));

    // ── Claim by the PBX's id ────────────────────────────────────────────
    echo "\n--- claim_by_provider ---\n\n";
    $r = p155cp_probe('claim_by_provider', $dispA, ['provider_call_id' => $pid1]);
    t('a Dispatcher claims the ringing call by the PBX id (200, success)', ($r['status'] ?? 0) === 200 && ($r['body']['success'] ?? false) === true, json_encode($r));
    t('...the response carries the inbound_calls id the New Incident tab needs', (int) ($r['body']['call']['id'] ?? 0) === $call1 && (int) ($r['body']['call_id'] ?? 0) === $call1);
    $st = p155cp_state($call1);
    t('...the row is claimed by that user (the UNCHANGED atomic claim path ran)', $st && $st['state'] === 'claimed' && (int) $st['claimed_by'] === $dispA);
    $ev = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}inbound_call_events` WHERE call_id = ? AND event_type = 'claimed'", [$call1]);
    t('...and the audit trail has its claimed event', (int) $ev === 1);

    $r = p155cp_probe('claim_by_provider', $dispA, ['provider_call_id' => $pid1]);
    t('the same user again (the banner Answer got there first): already_yours, NOT a second claim and no error',
        ($r['status'] ?? 0) === 200 && ($r['body']['success'] ?? true) === false && ($r['body']['reason'] ?? '') === 'already_yours'
        && (int) ($r['body']['call_id'] ?? 0) === $call1, json_encode($r));

    $r = p155cp_probe('claim_by_provider', $dispB, ['provider_call_id' => $pid1]);
    t('a different dispatcher is told it is already claimed, by whom (200, success false)',
        ($r['status'] ?? 0) === 200 && ($r['body']['reason'] ?? '') === 'already_claimed' && !empty($r['body']['claimed_by_name']), json_encode($r));
    $st = p155cp_state($call1);
    t('...and the claim did not move', (int) $st['claimed_by'] === $dispA);

    $r = p155cp_probe('claim_by_provider', $dispA, ['provider_call_id' => $tag . '.never-rang']);
    t('an id the bridge never reported is not_found, 200 -- answering must never depend on the bridge being up',
        ($r['status'] ?? 0) === 200 && ($r['body']['success'] ?? true) === false && ($r['body']['reason'] ?? '') === 'not_found', json_encode($r));

    // Ended / abandoned.
    $pid2 = $tag . '.2';
    $call2 = p155cp_ring($trunk, $pid2);
    inbound_calls_ingest_event($trunk, ['event' => 'abandoned', 'call_id' => $pid2]);
    $r = p155cp_probe('claim_by_provider', $dispB, ['provider_call_id' => $pid2]);
    t('a call that already ended or was abandoned reports already_ended and is not claimed',
        ($r['body']['reason'] ?? '') === 'already_ended' && p155cp_state($call2)['state'] === 'abandoned', json_encode($r));

    // Same PBX id on two trunks: the newest visible row.
    $trunk2 = p155cp_trunk('p155-claim-trunk-2', null);
    $pid3 = $tag . '.3';
    $c3a = p155cp_ring($trunk, $pid3);
    $c3b = p155cp_ring($trunk2, $pid3);
    $r = p155cp_probe('claim_by_provider', $dispB, ['provider_call_id' => $pid3]);
    t('a PBX id present on two trunks resolves to the most recent ringing row, deterministically',
        ($r['body']['success'] ?? false) === true && (int) ($r['body']['call_id'] ?? 0) === max($c3a, $c3b), json_encode($r));

    // ── Input and access control ────────────────────────────────────────
    echo "\n--- validation and access control ---\n\n";
    foreach (['empty' => '', 'spaces' => 'a b', 'sql' => "1' OR '1'='1", 'angle brackets' => '<script>', 'too long' => str_repeat('9', 129), 'embedded newline' => "12\n3"] as $label => $bad) {
        $r = p155cp_probe('claim_by_provider', $dispA, ['provider_call_id' => $bad]);
        t("a malformed provider_call_id ($label) is a 400", ($r['status'] ?? 0) === 400, json_encode($r));
    }
    $pid4 = $tag . '.4';
    $call4 = p155cp_ring($trunk, $pid4);
    $r = p155cp_probe('claim_by_provider', $dispA, ['provider_call_id' => $pid4, 'csrf_token' => 'bogus']);
    t('a bad CSRF token is refused (403) and the call stays ringing', ($r['status'] ?? 0) === 403 && p155cp_state($call4)['state'] === 'ringing');
    $r = p155cp_probe('claim_by_provider', $noRole, ['provider_call_id' => $pid4]);
    t('a user holding no role (no action.claim_call) is refused (403) and the call stays ringing',
        ($r['status'] ?? 0) === 403 && p155cp_state($call4)['state'] === 'ringing', json_encode($r));

    // Org scoping: the same visibility rule the banner's claim uses.
    db_query("INSERT INTO `{$prefix}organizations` (id, name, active) VALUES (?, ?, 1)", [$orgX, "p155 org X $tag"]);
    db_query("INSERT INTO `{$prefix}organizations` (id, name, active) VALUES (?, ?, 1)", [$orgY, "p155 org Y $tag"]);
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `org_id`, `scope_kind`, `scope_id`) VALUES (?, 3, ?, 'org', ?)",
        [$orgUser, $orgX, $orgX]);
    $trunkY = p155cp_trunk('p155-claim-trunk-orgY', $orgY);
    $trunkX = p155cp_trunk('p155-claim-trunk-orgX', $orgX);
    $pidY = $tag . '.y'; $pidX = $tag . '.x';
    $callY = p155cp_ring($trunkY, $pidY);
    $callX = p155cp_ring($trunkX, $pidX);
    $orgSession = ['active_org_id' => $orgX]; // set at login from the user's memberships and org-scoped grants
    $r = p155cp_probe('claim_by_provider', $orgUser, ['provider_call_id' => $pidY], $orgSession);
    t('a dispatcher scoped to org X cannot see, so cannot claim, a call on org Y\'s trunk (not_found, nothing changed)',
        ($r['body']['reason'] ?? '') === 'not_found' && p155cp_state($callY)['state'] === 'ringing', json_encode($r));
    $r = p155cp_probe('claim_by_provider', $orgUser, ['provider_call_id' => $pidX], $orgSession);
    t('...but can claim a call on their own org\'s trunk', ($r['body']['success'] ?? false) === true && p155cp_state($callX)['state'] === 'claimed', json_encode($r));
    $r = p155cp_probe('claim_by_provider', $adminId, ['provider_call_id' => $pidY]);
    t('...and Super Admin sees every org\'s calls', ($r['body']['success'] ?? false) === true, json_encode($r));

    // ── Structure ────────────────────────────────────────────────────────
    echo "\n--- structure ---\n\n";
    $src = (string) file_get_contents(__DIR__ . '/../api/inbound-calls.php');
    $i = strpos($src, "if (\$action === 'claim_by_provider')");
    $j = strpos($src, "if (\$action === 'release')");
    $block = $i !== false && $j !== false ? substr($src, $i, $j - $i) : '';
    t('the action checks CSRF, then action.claim_call, then visibility, before it claims',
        $block !== '' && strpos($block, 'p149_require_csrf') < strpos($block, "rbac_can('action.claim_call')")
        && strpos($block, "rbac_can('action.claim_call')") < strpos($block, 'p149_user_can_see_call')
        && strpos($block, 'p149_user_can_see_call') < strpos($block, 'inbound_call_claim('));
    t('...with no `|| is_admin()` fallback', strpos($block, 'is_admin()') === false);
    t('...and it reuses inbound_call_claim() (the audited, SSE-publishing, atomic path) -- no second claim mechanism',
        substr_count($block, 'inbound_call_claim(') === 1 && strpos($block, 'UPDATE') === false);
} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
