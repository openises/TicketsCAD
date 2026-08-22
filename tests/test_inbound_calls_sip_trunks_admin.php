<?php
/**
 * Phase 149 Milestone 8 — api/sip-trunks.php admin CRUD, RBAC, CSRF, and
 * the mint-once token contract (plan.md §8).
 *
 * Drives the REAL endpoint file via CLI subprocess (tests/
 * _p149_sip_trunks_probe.php), same discipline as
 * tests/test_inbound_calls_rbac.php's own tests/_p149_endpoint_probe.php
 * -- never a hand-seeded `pbx_trunks` row standing in for what the real
 * writer produces. Covers: RBAC gate (no is_admin() fallback per this
 * project's Phase 138 lesson), CSRF enforcement, the token is minted once
 * and never re-exposed on GET, rotate invalidates the prior token against
 * the REAL api/sip-ingest.php auth path, and every mutation lands a real
 * newui_audit_log row.
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_sip_trunks_admin.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 Milestone 8 — api/sip-trunks.php admin CRUD ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

/**
 * PHP's escapeshellarg() on Windows replaces an embedded double-quote
 * with a bare space rather than escaping it -- so a JSON payload passed
 * as a shell-quoted argument (`{"label":"X"}`) arrives at the child
 * process as ` label : X ` with every quote silently gone. proc_open()
 * with an ARRAY command bypasses cmd.exe/escapeshellarg entirely
 * (CreateProcess gets the argv list directly), the same fix this
 * project's own tests/test_proc_open_pipe_deadlock.php documents via
 * `bypass_shell` for the identical class of problem.
 */
function p149st_probe(string $method, string $action, int $userId, string $payload = ''): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = [$php, __DIR__ . '/_p149_sip_trunks_probe.php', $method, $action, (string) $userId, $payload];
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

$adminId      = test_admin_user_id();
$dispatcherId = 900015070; // holds screen.call_queue/action.claim_call but NOT action.manage_calls
$createdTrunkIds = [];

$cleanup = function () use ($prefix, $dispatcherId, &$createdTrunkIds) {
    foreach ($createdTrunkIds as $id) {
        try { db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'pbx_trunk' AND `target_id` = ?", [$id]); } catch (Throwable $e) {}
    }
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$dispatcherId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$dispatcherId]); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {

    // ══════════════════════════════════════════════════════════════════
    // Static: no is_admin() fallback on the gate (Phase 138 lesson)
    // ══════════════════════════════════════════════════════════════════
    echo "--- Static source checks ---\n\n";

    $apiSrc = file_get_contents(__DIR__ . '/../api/sip-trunks.php');
    t('st_require_perm() gates on rbac_can(action.manage_calls)',
        strpos($apiSrc, "rbac_can('action.manage_calls')") !== false);
    t('st_require_perm() does NOT fall back to is_admin() (Phase 138 lesson)',
        !preg_match('/rbac_can\(\'action\.manage_calls\'\)[^;]*is_admin\(\)/s', $apiSrc));
    t('every mutating action calls audit_log()', substr_count($apiSrc, 'audit_log(') >= 5);
    t('trunk_create mints via sip_token_mint(), never a hand-rolled token',
        strpos($apiSrc, 'sip_token_mint()') !== false);
    t('the list/detail SELECTs never select the raw bearer_token column',
        !preg_match('/SELECT[^;]*\bbearer_token\b[^;]*FROM `\{\$prefix\}pbx_trunks`\s+WHERE `id` = \?/s', $apiSrc));

    // ══════════════════════════════════════════════════════════════════
    // Fixture: a Dispatcher (holds call_queue/claim_call, NOT manage_calls)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Fixture setup ---\n\n";

    db_query(
        "INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
        [$dispatcherId, 'p149sttrunkfixture', password_hash('unused-test-fixture', PASSWORD_BCRYPT)]
    );
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 3)", [$dispatcherId]);
    t('Dispatcher fixture account created', true);

    // ══════════════════════════════════════════════════════════════════
    // RBAC: Dispatcher is refused on every action, GET and POST alike
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- RBAC enforcement ---\n\n";

    $r = p149st_probe('GET', 'trunks', $dispatcherId);
    t('Dispatcher GET trunks is refused', isset($r['error']));

    $r = p149st_probe('POST', 'trunk_create', $dispatcherId, json_encode(['label' => 'Should Not Exist']));
    t('Dispatcher POST trunk_create is refused', isset($r['error']));
    $leftover = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}pbx_trunks` WHERE `label` = 'Should Not Exist'");
    t('...and no row was actually created', (int) $leftover === 0);

    // ══════════════════════════════════════════════════════════════════
    // Super Admin: full CRUD lifecycle through the real endpoint
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Super Admin CRUD lifecycle ---\n\n";

    $r = p149st_probe('POST', 'trunk_create', $adminId, json_encode([
        'label' => 'P149 Test Trunk', 'wrapup_seconds' => 45, 'reassign_grace_seconds' => 10,
        'mute_bypass_enabled' => 1,
    ]));
    t('trunk_create succeeded', !empty($r['trunk_id']));
    $trunkId = (int) ($r['trunk_id'] ?? 0);
    if ($trunkId > 0) $createdTrunkIds[] = $trunkId;
    $firstToken = $r['bearer_token'] ?? '';
    t('trunk_create returned a bearer token', $firstToken !== '' && strlen($firstToken) >= 32);
    t('trunk_create response carries adapter-setup guidance', strpos((string) ($r['note'] ?? ''), 'sip-ingest.php') !== false);

    $row = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('the row exists with the posted label', $row && $row['label'] === 'P149 Test Trunk');
    t('the row exists with the posted wrapup_seconds', $row && (int) $row['wrapup_seconds'] === 45);
    t('the row exists with the posted reassign_grace_seconds', $row && (int) $row['reassign_grace_seconds'] === 10);
    t('the row was created enabled by default', $row && (int) $row['enabled'] === 1);
    t('the stored bearer_token matches what was returned (round-trip, not re-derived)',
        $row && hash_equals((string) $row['bearer_token'], (string) $firstToken));

    $list = p149st_probe('GET', 'trunks', $adminId);
    $listedRow = null;
    foreach (($list['trunks'] ?? []) as $lr) { if ((int) $lr['id'] === $trunkId) { $listedRow = $lr; break; } }
    t('GET trunks lists the new trunk', $listedRow !== null);
    t('GET trunks never exposes the raw bearer_token field', $listedRow !== null && !array_key_exists('bearer_token', $listedRow));
    t('GET trunks reports has_token = true instead', $listedRow !== null && (int) $listedRow['has_token'] === 1);

    // trunk detail takes id via query string, not the JSON body -- the
    // probe's GET path parses the 4th arg as a query string.
    $detailReq = p149st_probe('GET', 'trunk', $adminId, 'id=' . $trunkId);
    t('GET trunk (detail) returns the row', isset($detailReq['trunk']['id']) && (int) $detailReq['trunk']['id'] === $trunkId);
    t('GET trunk (detail) never exposes the raw bearer_token field either',
        isset($detailReq['trunk']) && !array_key_exists('bearer_token', $detailReq['trunk']));

    // ---- update ----
    $r = p149st_probe('POST', 'trunk_update', $adminId, json_encode([
        'id' => $trunkId, 'label' => 'P149 Test Trunk (renamed)', 'wrapup_seconds' => 60,
        'reassign_grace_seconds' => 25, 'mute_bypass_enabled' => 0,
    ]));
    t('trunk_update succeeded', !empty($r['success']));
    $row = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('label was actually updated', $row && $row['label'] === 'P149 Test Trunk (renamed)');
    t('wrapup_seconds was actually updated', $row && (int) $row['wrapup_seconds'] === 60);
    t('reassign_grace_seconds was actually updated', $row && (int) $row['reassign_grace_seconds'] === 25);
    t('mute_bypass_enabled was actually updated', $row && (int) $row['mute_bypass_enabled'] === 0);
    t('trunk_update does NOT change the bearer_token', $row && hash_equals((string) $row['bearer_token'], (string) $firstToken));

    $r = p149st_probe('POST', 'trunk_update', $adminId, json_encode(['id' => $trunkId, 'label' => '']));
    t('trunk_update refuses an empty label', isset($r['error']));

    // ---- toggle ----
    $r = p149st_probe('POST', 'trunk_toggle', $adminId, json_encode(['id' => $trunkId]));
    t('trunk_toggle succeeded', !empty($r['success']));
    $enabledAfterFirstToggle = (int) db_fetch_value("SELECT `enabled` FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('first toggle disabled the trunk (was enabled)', $enabledAfterFirstToggle === 0);
    p149st_probe('POST', 'trunk_toggle', $adminId, json_encode(['id' => $trunkId]));
    $enabledAfterSecondToggle = (int) db_fetch_value("SELECT `enabled` FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('second toggle re-enabled the trunk', $enabledAfterSecondToggle === 1);

    // ---- rotate token ----
    $r = p149st_probe('POST', 'trunk_rotate_token', $adminId, json_encode(['id' => $trunkId]));
    $secondToken = $r['bearer_token'] ?? '';
    t('trunk_rotate_token returned a new token', $secondToken !== '' && strlen($secondToken) >= 32);
    t('the new token differs from the original', $secondToken !== $firstToken);
    $row = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('the stored token was actually updated to the new value',
        $row && hash_equals((string) $row['bearer_token'], (string) $secondToken));

    // The doc's own claim: "the old token stops working immediately."
    // Prove it against the REAL trunk-resolution function the ingest
    // endpoint uses, not a re-implementation of the lookup.
    require_once __DIR__ . '/../inc/sip_token.php';
    t('the OLD token no longer resolves to any trunk', sip_token_resolve_trunk($firstToken) === null);
    $resolved = sip_token_resolve_trunk($secondToken);
    t('the NEW token resolves to this exact trunk', $resolved !== null && (int) $resolved['id'] === $trunkId);

    // ══════════════════════════════════════════════════════════════════
    // CSRF enforcement
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- CSRF enforcement ---\n\n";

    $r = p149st_probe('POST', 'trunk_update', $adminId, json_encode(['id' => $trunkId, 'label' => 'CSRF Bypass Attempt', 'csrf_token' => null]));
    t('a POST with no csrf_token is refused', isset($r['error']));
    $r = p149st_probe('POST', 'trunk_update', $adminId, json_encode(['id' => $trunkId, 'label' => 'CSRF Bypass Attempt', 'csrf_token' => 'totally-bogus-value']));
    t('a POST with a bogus csrf_token is refused', isset($r['error']));
    $row = db_fetch_one("SELECT `label` FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('...and neither bypass attempt actually changed the label',
        $row && $row['label'] === 'P149 Test Trunk (renamed)');

    // ══════════════════════════════════════════════════════════════════
    // Audit trail — a real newui_audit_log row per mutation
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Audit trail ---\n\n";

    $activities = db_fetch_all(
        "SELECT `activity` FROM `{$prefix}newui_audit_log`
          WHERE `target_type` = 'pbx_trunk' AND `target_id` = ? ORDER BY `id`",
        [$trunkId]
    );
    $activityList = array_map(function ($r) { return $r['activity']; }, $activities);
    t('create/update/disable/enable/rotate are all audited (at least one of each)',
        in_array('create', $activityList, true)
        && in_array('update', $activityList, true)
        && in_array('disable', $activityList, true)
        && in_array('enable', $activityList, true)
        && in_array('rotate', $activityList, true));
    $auditRow = db_fetch_one(
        "SELECT * FROM `{$prefix}newui_audit_log`
          WHERE `target_type` = 'pbx_trunk' AND `target_id` = ? AND `activity` = 'create'
          ORDER BY `id` LIMIT 1",
        [$trunkId]
    );
    t('the create audit row records the acting user', $auditRow && (int) $auditRow['user_id'] === $adminId);
    t('the create audit row summary names the trunk label', $auditRow && strpos((string) $auditRow['summary'], 'P149 Test Trunk') !== false);

    // ══════════════════════════════════════════════════════════════════
    // Delete — hard delete, historical inbound_calls rows untouched
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Delete ---\n\n";

    // Attach one historical call row to prove delete does not cascade.
    db_query(
        "INSERT INTO `{$prefix}inbound_calls`
            (`trunk_id`, `provider_call_id`, `caller_number`, `state`, `ringing_at`, `last_event_at`, `created_at`)
         VALUES (?, ?, ?, 'ended', NOW(), NOW(), NOW())",
        [$trunkId, 'p149-st-test-call-' . $trunkId, '+16125550000']
    );
    $callId = (int) db_insert_id();

    $r = p149st_probe('POST', 'trunk_delete', $adminId, json_encode(['id' => $trunkId]));
    t('trunk_delete succeeded', !empty($r['success']));
    $stillThere = db_fetch_one("SELECT id FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [$trunkId]);
    t('the trunk row is actually gone', $stillThere === null);
    $callStillThere = db_fetch_one("SELECT id FROM `{$prefix}inbound_calls` WHERE `id` = ?", [$callId]);
    t('the historical inbound_calls row is NOT cascaded away (matches the documented no-FK convention)',
        $callStillThere !== null);
    db_query("DELETE FROM `{$prefix}inbound_calls` WHERE `id` = ?", [$callId]);

    $r = p149st_probe('POST', 'trunk_delete', $adminId, json_encode(['id' => $trunkId]));
    t('deleting an already-deleted trunk id is a clean 404, not a crash', isset($r['error']));

} finally {
    $cleanup();
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
