<?php
/**
 * Phase 155 (GH#151 + GH#129) — api/voice-bridge-channels.php and the
 * api/channels.php guards for digital voice bridge channels: RBAC (tier 1,
 * no is_admin() fallback), CSRF, the policy-acknowledgment flow, write-only
 * FNE REST password, the audit trail, rollback when the live matrix service
 * refuses, and the console config redaction.
 *
 * Drives the REAL endpoint files through tests/_p155_voice_bridge_probe.php (one
 * fresh PHP process per call, no web server), never hand-seeded rows. The
 * live-service half (a real control plane and a real UDP leg) is in
 * test_voice_bridge_e2e.php.
 *
 * @requires-db
 * Usage: php tests/test_voice_bridge_api.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/channel_registry.php';
require_once 'inc/voice-bridge-channels.php';
require_once 'tests/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 -- digital voice bridge API ===\n\n";

/** One probe call. Returns ['status' => int, 'body' => array|null, 'raw' => string]. */
function probe($endpoint, $method, $query, $userId, $payload = '') {
    $cmd = [PHP_BINARY, __DIR__ . '/_p155_voice_bridge_probe.php', $endpoint, $method, $query, (string) $userId, $payload];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) { return ['status' => 0, 'body' => null, 'raw' => '']; }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/\nHTTP (\d+)\s*$/', $out, $m)) { $status = (int) $m[1]; $out = substr($out, 0, -strlen($m[0])); }
    $out = trim($out);
    $body = json_decode($out, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $out];
}

$adminId = test_admin_user_id();
$orgAdminId = 900015502;
$dispatcherId = 900015501;
$noRoleId = 900015503;
$sentinelPw = 'P155-sentinel-rest-password';
$createdIds = [];
$origSettings = [];
foreach (['dvm_policy_ack', 'dvm_fne_rest_url', 'dvm_fne_rest_password', 'dvm_fne_rest_verify_tls', 'dvm_fne_ping_stale_secs',
          'matrix_control_url', 'matrix_control_token'] as $k) {
    $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
    $origSettings[$k] = ($v === false) ? null : $v;
}
$cleanup = function () use (&$createdIds, $prefix, $orgAdminId, $dispatcherId, $noRoleId, &$origSettings) {
    foreach ($createdIds as $id) {
        try { db_query("DELETE FROM `{$prefix}comm_routes` WHERE src_channel_id = ? OR dst_channel_id = ?", [$id, $id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ([$orgAdminId, $dispatcherId, $noRoleId] as $uid) {
        try { db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$uid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$uid]); } catch (Throwable $e) {}
    }
    foreach ($origSettings as $k => $v) {
        try {
            if ($v === null) { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$k]); }
            else { vbc_setting_set($k, $v); }
        } catch (Throwable $e) {}
    }
    try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE user_name = 'p155-probe'"); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

foreach ([[$orgAdminId, 'p155orgadmin', 2], [$dispatcherId, 'p155dispatcher', 3], [$noRoleId, 'p155norole', null]] as $u) {
    db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
        [$u[0], $u[1], password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
    if ($u[2] !== null) {
        db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$u[0], $u[2]]);
    }
}
vbc_setting_set('dvm_policy_ack', '');
vbc_setting_set('matrix_control_url', '');       // unconfigured: the default on an install without the service
vbc_setting_set('matrix_control_token', '');

$portSeq = 42000 + (getmypid() % 1000) * 3;
function nextPort() { global $portSeq; return $portSeq++; }
function newBody($over = []) {
    return json_encode(array_merge([
        'adapter' => 'usrp_bridge', 'slug' => 'api-' . substr(md5(uniqid('', true)), 0, 6), 'label' => 'P155 API test',
        'regulatory_class' => 'amateur', 'mode' => 'other', 'bridge_host' => '127.0.0.1', 'bridge_tx_port' => 32001,
        'listen_host' => '127.0.0.1', 'listen_port' => nextPort(), 'rx_hang_ms' => 400, 'enabled' => 1,
    ], $over));
}
/** Track (for cleanup) any row a refused request wrongly left behind, then say whether there was one. */
function leakedRows($label) {
    global $prefix, $createdIds;
    $rows = db_fetch_all("SELECT id FROM `{$prefix}comm_channels` WHERE label = ?", [$label]);
    foreach ($rows as $r) { $createdIds[] = (int) $r['id']; }
    return count($rows);
}
function auditCount($activity) {
    global $prefix;
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = ?", [$activity]);
}

// ══ 1. RBAC ═════════════════════════════════════════════════════════════
echo "1. RBAC: tier 1, Org Admin and Super Admin only\n";
$perm = db_fetch_one("SELECT id, admin_only FROM `{$prefix}permissions` WHERE code = 'action.manage_voice_bridges'");
t('the permission exists and is admin_only tier 1', $perm && (int) $perm['admin_only'] === 1);
$holders = array_column(db_fetch_all("SELECT role_id FROM `{$prefix}role_permissions` WHERE permission_id = ? ORDER BY role_id", [$perm['id']]), 'role_id');
t('...held by exactly Super Admin (1) and Org Admin (2)', array_map('intval', $holders) === [1, 2]);

foreach ([['Dispatcher', $dispatcherId], ['a user with no role', $noRoleId]] as $who) {
    $r = probe('voice-bridge-channels.php', 'GET', 'action=list', $who[1]);
    t("{$who[0]}: GET list is refused (403)", $r['status'] === 403 && isset($r['body']['error']));
    $r = probe('voice-bridge-channels.php', 'GET', 'action=policy', $who[1]);
    t("{$who[0]}: GET policy is refused (403)", $r['status'] === 403);
    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_channels` WHERE adapter IN ('dvmproject','usrp_bridge')");
    $r = probe('voice-bridge-channels.php', 'POST', 'action=create', $who[1], newBody());
    t("{$who[0]}: POST create is refused (403) and writes nothing", $r['status'] === 403
        && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_channels` WHERE adapter IN ('dvmproject','usrp_bridge')") === $before);
    $r = probe('voice-bridge-channels.php', 'POST', 'action=policy_ack', $who[1], json_encode(['acknowledged' => 1]));
    t("{$who[0]}: POST policy_ack is refused (403): a Dispatcher cannot acknowledge on the install's behalf",
        $r['status'] === 403 && !vbc_policy_acknowledged());
    $r = probe('voice-bridge-channels.php', 'POST', 'action=fne_settings', $who[1], json_encode(['url' => 'http://127.0.0.1:9990']));
    t("{$who[0]}: POST fne_settings is refused (403)", $r['status'] === 403 && vbc_setting_get('dvm_fne_rest_url', '') === '');
}
foreach ([['Org Admin', $orgAdminId], ['Super Admin', $adminId]] as $who) {
    $r = probe('voice-bridge-channels.php', 'GET', 'action=list', $who[1]);
    t("{$who[0]}: GET list succeeds", $r['status'] === 200 && isset($r['body']['channels']));
}

// ══ 2. CSRF ═════════════════════════════════════════════════════════════
echo "\n2. CSRF\n";
foreach (['create', 'update', 'delete', 'policy_ack', 'fne_settings'] as $act) {
    $r = probe('voice-bridge-channels.php', 'POST', 'action=' . $act, $adminId, json_encode(['csrf_token' => 'bogus', 'id' => 1, 'acknowledged' => 1]));
    t("POST $act with a bogus CSRF token is refused (403)", $r['status'] === 403);
    $r = probe('voice-bridge-channels.php', 'POST', 'action=' . $act, $adminId, json_encode(['csrf_token' => null, 'id' => 1]));
    t("POST $act with a missing CSRF token is refused (403)", $r['status'] === 403);
}
t('nothing was changed by any refused request', !vbc_policy_acknowledged() && vbc_setting_get('dvm_fne_rest_url', '') === '');

// ══ 3. policy acknowledgment flow ═══════════════════════════════════════
echo "\n3. Policy acknowledgment through the API\n";
$r = probe('voice-bridge-channels.php', 'GET', 'action=policy', $adminId);
t('GET policy returns the statement paragraphs and the not-acknowledged state',
    $r['status'] === 200 && $r['body']['acknowledged'] === false && count($r['body']['paragraphs']) >= 4);
t('...and the statement says the leg is listen-only and that no DVMProject software is bundled',
    stripos(implode(' ', $r['body']['paragraphs']), 'listen-only') !== false
    && stripos(implode(' ', $r['body']['paragraphs']), 'bundles and redistributes no DVMProject software') !== false);
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $adminId, newBody(['adapter' => 'dvmproject', 'slug' => 'noack-' . substr(md5(uniqid('', true)), 0, 6), 'label' => 'P155 dvm noack']));
t('creating a dvmproject channel with no acknowledgment is refused (403) and flagged needs_policy_ack',
    $r['status'] === 403 && !empty($r['body']['needs_policy_ack']));
foreach (db_fetch_all("SELECT id FROM `{$prefix}comm_channels` WHERE label = 'P155 dvm noack'") as $lk) { $createdIds[] = (int) $lk['id']; }
t('...and nothing was written', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_channels` WHERE label = 'P155 dvm noack'") === 0);

$a0 = auditCount('voice_bridge.policy_ack');
$r = probe('voice-bridge-channels.php', 'POST', 'action=policy_ack', $orgAdminId, json_encode(['acknowledged' => 1]));
t('an Org Admin can record the acknowledgment', $r['status'] === 200 && $r['body']['ok'] === true && vbc_policy_acknowledged());
$ack = vbc_policy_ack_get();
t('...it records WHO (the session user), WHEN and which statement version',
    $ack && $ack['username'] === 'p155-probe' && (int) $ack['user_id'] === $orgAdminId && $ack['at'] !== '' && $ack['version'] === VBC_POLICY_VERSION);
t('...and writes an audit row (voice_bridge.policy_ack), at high severity',
    auditCount('voice_bridge.policy_ack') === $a0 + 1
    && (int) db_fetch_value("SELECT severity FROM `{$prefix}newui_audit_log` WHERE activity = 'voice_bridge.policy_ack' ORDER BY id DESC LIMIT 1") >= 3);

// a dvmproject channel to exercise withdraw (created directly: the live-service path is tested in the e2e file)
$dvmId = vbc_create('dvmproject', 'wd-' . substr(md5(uniqid('', true)), 0, 6), 'P155 withdraw test', 'amateur',
    ['mode' => 'p25', 'bridge_host' => '127.0.0.1', 'bridge_tx_port' => 32001, 'listen_host' => '127.0.0.1', 'listen_port' => nextPort(), 'rx_hang_ms' => 400]);
$createdIds[] = $dvmId;
$u0 = auditCount('voice_bridge.policy_revoke');
$r = probe('voice-bridge-channels.php', 'POST', 'action=policy_ack', $adminId, json_encode(['acknowledged' => 0]));
t('withdrawing the acknowledgment succeeds and reports how many channels it disabled', $r['status'] === 200 && (int) $r['body']['disabled'] === 1);
t('...the DVMProject channel is now disabled and the acknowledgment is blank', (int) vbc_get($dvmId)['enabled'] === 0 && !vbc_policy_acknowledged());
t('...and writes an audit row (voice_bridge.policy_revoke)', auditCount('voice_bridge.policy_revoke') === $u0 + 1);

// ══ 4. rollback when the live service refuses ═══════════════════════════
echo "\n4. Rollback when the audio-matrix service is unreachable\n";
$body = newBody(['label' => 'P155 rollback']);
$c0 = auditCount('voice_bridge.create');
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $adminId, $body);
t('create with the matrix service unconfigured returns 502 naming the service', $r['status'] === 502 && stripos($r['raw'], 'audio-matrix service') !== false);
t('...and the channel row was rolled back (no silently-queued channel)', leakedRows('P155 rollback') === 0);
t('...and no create audit row was written for a channel that does not exist', auditCount('voice_bridge.create') === $c0);

vbc_setting_set('matrix_control_url', 'http://127.0.0.1:9');   // the discard port: connection refused
vbc_setting_set('matrix_control_token', 'x');
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $adminId, newBody(['label' => 'P155 rollback 2']));
t('create with the service configured but unreachable also returns 502 and rolls back',
    $r['status'] === 502 && leakedRows('P155 rollback 2') === 0);

// a disabled channel needs no live service: it can be staged
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $adminId, newBody(['label' => 'P155 staged', 'enabled' => 0]));
t('creating a channel DISABLED needs no live service (nothing binds a socket) and succeeds',
    $r['status'] === 200 && !empty($r['body']['id']) && (int) $r['body']['channel']['enabled'] === 0);
$stagedId = (int) ($r['body']['id'] ?? 0);
if ($stagedId) { $createdIds[] = $stagedId; }
t('...the audit trail has a create row for it with the key, adapter, class and config',
    $stagedId > 0 && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'voice_bridge.create' AND target_id = ?", [(string) $stagedId]) === 1);

// update that enables it while the service is down: refused, and the row is put back
$b4 = vbc_get($stagedId);
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $adminId, json_encode(['id' => $stagedId, 'enabled' => 1, 'label' => 'P155 staged (edited)']));
t('ENABLING while the service is unreachable returns 502', $r['status'] === 502);
$after = vbc_get($stagedId);
t('...and the database was restored: still disabled, original label (DB and running service never disagree)',
    (int) $after['enabled'] === 0 && $after['label'] === $b4['label']);

// an update that only edits a disabled channel
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $adminId, json_encode(['id' => $stagedId, 'label' => 'P155 staged (renamed)', 'rx_hang_ms' => 700]));
t('editing a disabled channel succeeds without the live service', $r['status'] === 200 && vbc_get($stagedId)['label'] === 'P155 staged (renamed)'
    && (int) vbc_get($stagedId)['config']['rx_hang_ms'] === 700);
t('...and writes a before/after audit row', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE activity = 'voice_bridge.update' AND target_id = ?", [(string) $stagedId]) === 1);
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $adminId, json_encode(['id' => $stagedId, 'regulatory_class' => 'internal']));
t('reclassifying to internal through the API is refused (400)', $r['status'] === 400 && vbc_get($stagedId)['regulatory_class'] === 'amateur');
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $adminId, json_encode(['id' => $stagedId, 'tx_enabled' => 1]));
t('asking for transmit through the API is refused (400): listen-only', $r['status'] === 400 && stripos($r['raw'], 'listen-only') !== false);
$r = probe('voice-bridge-channels.php', 'POST', 'action=update', $adminId, json_encode(['id' => 999999999, 'label' => 'x']));
t('updating a channel that does not exist returns 404', $r['status'] === 404);

// delete
$d0 = auditCount('voice_bridge.delete');
$r = probe('voice-bridge-channels.php', 'POST', 'action=delete', $adminId, json_encode(['id' => $stagedId]));
t('delete succeeds even with the service unreachable (best-effort live removal) and reports routes removed',
    $r['status'] === 200 && array_key_exists('routes_removed', $r['body']) && vbc_get($stagedId) === null);
t('...and writes an audit row (voice_bridge.delete)', auditCount('voice_bridge.delete') === $d0 + 1);
$r = probe('voice-bridge-channels.php', 'POST', 'action=delete', $adminId, json_encode(['id' => $stagedId]));
t('deleting it again returns 404', $r['status'] === 404);

// validation errors surface as 400 with the reason
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $adminId, newBody(['label' => 'P155 bad', 'bridge_host' => 'evil.example.org']));
t('a hostname as bridge address is refused with a 400 and the reason', $r['status'] === 400 && stripos($r['raw'], 'IPv4') !== false);
$r = probe('voice-bridge-channels.php', 'POST', 'action=create', $adminId, newBody(['label' => 'P155 bad', 'regulatory_class' => 'pstn']));
t('class pstn is refused with a 400', $r['status'] === 400);
t('...and neither left a row behind', leakedRows('P155 bad') === 0);
$r = probe('voice-bridge-channels.php', 'POST', 'action=nope', $adminId, '{}');
t('an unknown POST action returns 404', $r['status'] === 404);
$r = probe('voice-bridge-channels.php', 'GET', 'action=nope', $adminId);
t('an unknown GET action returns 404', $r['status'] === 404);

// ══ 5. FNE REST settings: write-only password ═══════════════════════════
echo "\n5. FNE REST settings\n";
vbc_setting_set('matrix_control_url', '');
vbc_setting_set('matrix_control_token', '');
foreach (['http://fne.example.org:9990', 'http://8.8.8.8:9990', 'ftp://127.0.0.1', 'http://127.0.0.1:9990/admin', 'http://user:pw@127.0.0.1:9990'] as $badUrl) {
    $r = probe('voice-bridge-channels.php', 'POST', 'action=fne_settings', $adminId, json_encode(['url' => $badUrl, 'password' => $sentinelPw, 'stale_secs' => 30]));
    t("FNE URL $badUrl is refused (400)", $r['status'] === 400);
}
t('...a refused request stored neither the URL nor the password', vbc_setting_get('dvm_fne_rest_url', '') === '' && vbc_setting_get('dvm_fne_rest_password', '') === '');
$r = probe('voice-bridge-channels.php', 'POST', 'action=fne_settings', $adminId, json_encode(['url' => 'http://192.168.77.5:9990', 'password' => $sentinelPw, 'verify_tls' => 1, 'stale_secs' => 45]));
t('a private-network http URL is accepted, with a plaintext-network warning in the reply',
    $r['status'] === 200 && stripos((string) ($r['body']['warning'] ?? ''), 'plain http') !== false);
t('...URL, TLS flag and stale threshold are stored',
    vbc_setting_get('dvm_fne_rest_url', '') === 'http://192.168.77.5:9990' && vbc_setting_get('dvm_fne_ping_stale_secs', '') === '45');
t('...the password is stored (server side only)', vbc_setting_get('dvm_fne_rest_password', '') === $sentinelPw);
t('...and NEITHER the save reply NOR the list reply contains the password',
    strpos($r['raw'], $sentinelPw) === false
    && strpos(probe('voice-bridge-channels.php', 'GET', 'action=list', $adminId)['raw'], $sentinelPw) === false);
$lst = probe('voice-bridge-channels.php', 'GET', 'action=list', $adminId);
t('...instead the list reports password_set = true', $lst['body']['fne']['password_set'] === true);
$r = probe('voice-bridge-channels.php', 'POST', 'action=fne_settings', $adminId, json_encode(['url' => 'http://192.168.77.5:9990', 'password' => '', 'stale_secs' => 45]));
t('a BLANK password means "unchanged", never "clear"', $r['status'] === 200 && vbc_setting_get('dvm_fne_rest_password', '') === $sentinelPw);
$r = probe('voice-bridge-channels.php', 'POST', 'action=fne_settings', $adminId, json_encode(['url' => 'http://192.168.77.5:9990', 'password' => '********', 'stale_secs' => 45]));
t('a masking placeholder is also "unchanged"', vbc_setting_get('dvm_fne_rest_password', '') === $sentinelPw);
$rawAudit = db_fetch_all("SELECT summary, details FROM `{$prefix}newui_audit_log` WHERE activity = 'voice_bridge.fne_settings'");
$auditChanged = [];
foreach ($rawAudit as $ar) { $d = json_decode((string) $ar['details'], true); $auditChanged[] = is_array($d) ? ($d['password_changed'] ?? null) : null; }
t('the audit trail records each change, and whether the password changed, but never the password itself',
    count($rawAudit) >= 2 && in_array(true, $auditChanged, true) && in_array(false, $auditChanged, true)
    && strpos(json_encode($rawAudit), $sentinelPw) === false);
$r = probe('voice-bridge-channels.php', 'POST', 'action=fne_settings', $adminId, json_encode(['url' => 'http://192.168.77.5:9990', 'stale_secs' => 2]));
t('a stale threshold below 5 seconds is refused', $r['status'] === 400);

require_once 'inc/settings-secrets.php';
t('is_secret_setting_key() masks dvm_fne_rest_password (the _password suffix backstop)', is_secret_setting_key('dvm_fne_rest_password') === true);
t('...and does NOT mask the non-secret dvm_ keys the admin page must read back',
    !is_secret_setting_key('dvm_fne_rest_url') && !is_secret_setting_key('dvm_fne_rest_verify_tls') && !is_secret_setting_key('dvm_policy_ack'));
$cfgResp = probe('config-admin.php', 'GET', 'section=settings', $adminId);
t('the generic Settings API never returns the REST password value',
    $cfgResp['status'] === 200 && strpos($cfgResp['raw'], $sentinelPw) === false);

// ══ 6. api/channels.php: redaction and the enable/class guard ═══════════
echo "\n6. api/channels.php\n";
vbc_policy_ack_record($adminId, 'tester');
$chId = vbc_create('usrp_bridge', 'red-' . substr(md5(uniqid('', true)), 0, 6), 'P155 redaction', 'amateur',
    ['mode' => 'dmr', 'talkgroup' => '3127', 'bridge_host' => '127.0.0.1', 'bridge_tx_port' => 32001, 'listen_host' => '127.0.0.1',
     'listen_port' => nextPort(), 'rx_hang_ms' => 400]);
$createdIds[] = $chId;
function findCh($resp, $id) { foreach (($resp['body']['channels'] ?? []) as $c) { if ((int) $c['id'] === (int) $id) { return $c; } } return null; }
$asDisp = findCh(probe('channels.php', 'GET', '', $dispatcherId), $chId);
$asAdmin = findCh(probe('channels.php', 'GET', '', $adminId), $chId);
t('a Dispatcher (screen.console, no manage_voice_bridges) sees the strip with mode and talkgroup only',
    $asDisp !== null && ($asDisp['config']['mode'] ?? '') === 'dmr' && ($asDisp['config']['talkgroup'] ?? '') === '3127');
t('...but NOT the bridge address, ports or any other config key',
    $asDisp !== null && array_diff(array_keys($asDisp['config']), ['mode', 'talkgroup', 'listen_only']) === []);
t('an admin sees the full config (addresses and ports)', $asAdmin !== null && isset($asAdmin['config']['listen_port']) && isset($asAdmin['config']['bridge_host']));
t('capabilities reach the console unchanged: voice_rx only', $asDisp !== null && $asDisp['capabilities'] === ['voice_rx' => true]);

$r = probe('channels.php', 'POST', '', $adminId, json_encode(['action' => 'update', 'id' => $chId, 'enabled' => 0]));
t('api/channels.php refuses to enable/disable a digital voice bridge (403): that is the bridges page\'s job',
    $r['status'] === 403 && (int) vbc_get($chId)['enabled'] === 1);
$r = probe('channels.php', 'POST', '', $adminId, json_encode(['action' => 'update', 'id' => $chId, 'regulatory_class' => 'internal']));
t('...and refuses to reclassify one (an internal class would exempt a radio network from the cross-class guard)',
    $r['status'] === 403 && vbc_get($chId)['regulatory_class'] === 'amateur');
$r = probe('channels.php', 'POST', '', $adminId, json_encode(['action' => 'update', 'id' => $chId, 'regulatory_class_locked' => 0]));
t('...and refuses to unlock its class', $r['status'] === 403);
$r = probe('channels.php', 'POST', '', $adminId, json_encode(['action' => 'update', 'id' => $chId, 'label' => 'P155 renamed in console', 'short_label' => 'P25']));
t('presentation edits (label, short label) still work from the console designer', $r['status'] === 200 && vbc_get($chId)['label'] === 'P155 renamed in console');

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
