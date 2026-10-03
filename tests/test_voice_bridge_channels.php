<?php
/**
 * Phase 155 (GH#151 + GH#129) — digital voice bridge channels: catalog,
 * validation, writers, policy acknowledgment, capabilities, patch guards,
 * bridge-config snippet, and the PHP<->Python contract.
 *
 * Drives the REAL writers in inc/voice-bridge-channels.php against throwaway
 * comm_channels fixtures (never hand-seeded rows standing in for what the
 * writer produces). The API/RBAC/audit/rollback half is in
 * test_voice_bridge_api.php; the live UDP half in test_voice_bridge_e2e.php.
 *
 * The regression for the reported symptom ("a P25 network cannot appear in
 * the console and be patched") is section 4: a `dvmproject` channel created
 * through vbc_create() is returned by channels_all() with its adapter, class,
 * lock, capabilities and config, and section 9 patches it out to another
 * channel through the real matrix_route_validate().
 *
 * @requires-db
 * Usage: php tests/test_voice_bridge_channels.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/channel_registry.php';
require_once 'inc/voice-bridge-channels.php';
require_once 'inc/matrix-routes.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 -- digital voice bridge channels ===\n\n";

// ── Fixtures, cleaned up on every way out (by REFERENCE: a closure that
// captures the array by value at registration would delete nothing) ──────
$createdIds = [];
$fixtureKeys = ['p155test:zello', 'p155test:dmr'];
$origAck = vbc_setting_get('dvm_policy_ack', '');
$origPw = vbc_setting_get('dvm_fne_rest_password', '');
register_shutdown_function(function () use (&$createdIds, $fixtureKeys, $prefix, $origAck, $origPw) {
    foreach ($createdIds as $id) {
        try { db_query("DELETE FROM `{$prefix}comm_routes` WHERE src_channel_id = ? OR dst_channel_id = ?", [$id, $id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($fixtureKeys as $k) {
        try {
            $row = db_fetch_one("SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?", [$k]);
            if ($row) {
                db_query("DELETE FROM `{$prefix}comm_routes` WHERE src_channel_id = ? OR dst_channel_id = ?", [$row['id'], $row['id']]);
                db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$row['id']]);
            }
        } catch (Throwable $e) {}
    }
    try { vbc_setting_set('dvm_policy_ack', $origAck); } catch (Throwable $e) {}
    try { vbc_setting_set('dvm_fne_rest_password', $origPw); } catch (Throwable $e) {}
});

function uslug($base) { return $base . '-' . substr(md5(uniqid('', true)), 0, 6); }
$portSeq = 41000 + (getmypid() % 1000) * 3;
function nextPort() { global $portSeq; return $portSeq++; }

function good($over = []) {
    return array_merge(['mode' => 'p25', 'bridge_host' => '127.0.0.1', 'bridge_tx_port' => 32001,
                        'listen_host' => '127.0.0.1', 'listen_port' => nextPort(), 'rx_hang_ms' => 400,
                        'talkgroup' => '9001'], $over);
}

function expect_invalid($label, $fn) {
    try { $fn(); t($label, false); }
    catch (InvalidArgumentException $e) { t($label, true); }
    catch (Throwable $e) { t($label . ' (wrong exception: ' . get_class($e) . ' ' . $e->getMessage() . ')', false); }
}

vbc_setting_set('dvm_policy_ack', '');   // start every run un-acknowledged

// ══ 1. catalog ══════════════════════════════════════════════════════════
echo "1. Adapter catalog\n";
$cat = channel_adapter_catalog();
foreach (['dvmproject', 'usrp_bridge'] as $a) {
    t("catalog has $a", isset($cat[$a]));
    t("$a defaults to regulatory class amateur", ($cat[$a]['regulatory_class'] ?? '') === 'amateur');
    $caps = $cat[$a]['capabilities'] ?? [];
    t("$a declares voice_rx", !empty($caps['voice_rx']));
    t("$a declares NO transmit/ptt/tts/record capability (listen-only; a control nothing wires up is a bug)",
        empty($caps['voice_tx']) && empty($caps['ptt_floor']) && empty($caps['tts_out']) && empty($caps['record']));
}
t('VBC_TX_SUPPORTED is false in this release', VBC_TX_SUPPORTED === false);
t('vbc_adapters() is exactly the two USRP-family adapters', vbc_adapters() === ['dvmproject', 'usrp_bridge']);
t('amateur and commercial are the only choosable classes (never internal/pstn)',
    vbc_allowed_classes() === ['amateur', 'commercial']);

// ══ 2. validation ═══════════════════════════════════════════════════════
echo "\n2. Validation\n";
expect_invalid('a hostname as bridge address is refused', function () { vbc_validate_config('dvmproject', good(['bridge_host' => 'bridge.example.org'])); });
expect_invalid('a public IPv4 as bridge address is refused', function () { vbc_validate_config('dvmproject', good(['bridge_host' => '8.8.8.8'])); });
expect_invalid('0.0.0.0 as listen address is refused', function () { vbc_validate_config('dvmproject', good(['listen_host' => '0.0.0.0'])); });
expect_invalid('an IPv6 address is refused', function () { vbc_validate_config('dvmproject', good(['bridge_host' => '::1'])); });
expect_invalid('a privileged listen port (80) is refused', function () { vbc_validate_config('dvmproject', good(['listen_port' => 80])); });
expect_invalid('a non-numeric port is refused', function () { vbc_validate_config('dvmproject', good(['listen_port' => 'abc'])); });
expect_invalid('port 65536 is refused', function () { vbc_validate_config('dvmproject', good(['bridge_tx_port' => 65536])); });
expect_invalid('listen port == bridge receive port on the same host is refused',
    function () { vbc_validate_config('dvmproject', good(['listen_port' => 34001, 'bridge_tx_port' => 34001])); });
expect_invalid('end-of-call silence below 100 ms is refused', function () { vbc_validate_config('dvmproject', good(['rx_hang_ms' => 50])); });
expect_invalid('end-of-call silence above 5000 ms is refused', function () { vbc_validate_config('dvmproject', good(['rx_hang_ms' => 9000])); });
expect_invalid('an unknown mode is refused', function () { vbc_validate_config('dvmproject', good(['mode' => 'nxdn'])); });
expect_invalid('a non-numeric talkgroup is refused', function () { vbc_validate_config('dvmproject', good(['talkgroup' => 'TAC 1'])); });
expect_invalid('a bad FNE peer id is refused', function () { vbc_validate_config('dvmproject', good(['fne_peer_id' => '-5'])); });
expect_invalid('tx_enabled=true is refused: transmit is not available in this release',
    function () { vbc_validate_config('dvmproject', good(['tx_enabled' => true])); });
expect_invalid('class internal is refused (would exempt a radio network from the cross-class guard)',
    function () { vbc_validate_identity('dvmproject', 'a', 'L', 'internal'); });
expect_invalid('class pstn is refused', function () { vbc_validate_identity('usrp_bridge', 'a', 'L', 'pstn'); });
expect_invalid('an upper-case / spaced channel key is refused', function () { vbc_validate_identity('dvmproject', 'Bad Key', 'L', 'amateur'); });
expect_invalid('a channel key that tries a browser: prefix is refused (it would collide with the matrix\'s own dynamic channels)',
    function () { vbc_validate_identity('dvmproject', 'browser:5', 'L', 'amateur'); });
expect_invalid('an empty label is refused', function () { vbc_validate_identity('dvmproject', 'a', '  ', 'amateur'); });
expect_invalid('an unknown adapter is refused', function () { vbc_validate_identity('zello', 'a', 'L', 'amateur'); });

$cfg = vbc_validate_config('dvmproject', good(['fne_peer_id' => '9000123', 'bridge_host' => '192.168.1.20', 'listen_host' => '10.0.0.5']));
t('a fully valid config (RFC1918 addresses) passes', $cfg['bridge_host'] === '192.168.1.20' && $cfg['listen_host'] === '10.0.0.5');
t('...tx_enabled is stored as false', $cfg['tx_enabled'] === false);
t('...framing is stored as usrp', $cfg['framing'] === 'usrp');
t('...the FNE peer id is stored nested', ($cfg['fne']['peer_id'] ?? null) === 9000123);
$ident = vbc_validate_identity('dvmproject', 'p25-tg1', 'County P25', 'commercial');
t('identity: the channel key is prefixed by adapter (dvm:)', $ident['channel_key'] === 'dvm:p25-tg1');
t('identity: usrp_bridge keys are prefixed usrp:', vbc_validate_identity('usrp_bridge', 'x', 'L', 'amateur')['channel_key'] === 'usrp:x');

// ══ 3. policy acknowledgment gate ═══════════════════════════════════════
echo "\n3. DVMProject usage-policy acknowledgment\n";
t('no acknowledgment recorded at start', vbc_policy_acknowledged() === false && vbc_policy_ack_get() === null);
$threw = null;
$noAckLabel = 'No ack ' . uslug('x');
try { vbc_create('dvmproject', uslug('noack'), $noAckLabel, 'amateur', good()); }
catch (RuntimeException $e) { $threw = $e; }
t('creating an ENABLED dvmproject channel without the acknowledgment is refused (code 403)',
    $threw !== null && (int) $threw->getCode() === 403);
$leak = db_fetch_all("SELECT id FROM `{$prefix}comm_channels` WHERE label = ?", [$noAckLabel]);
foreach ($leak as $lk) { $createdIds[] = (int) $lk['id']; }   // tracked even if the guard is broken
t('...and nothing was written', count($leak) === 0);

$idU = vbc_create('usrp_bridge', uslug('plain'), 'Plain USRP bridge', 'amateur', good(['mode' => 'other']));
$createdIds[] = $idU;
t('a usrp_bridge channel does NOT need the acknowledgment', $idU > 0);

$idOff = vbc_create('dvmproject', uslug('staged'), 'Staged DVM (disabled)', 'amateur', good(), false);
$createdIds[] = $idOff;
t('a DISABLED dvmproject channel can be staged without the acknowledgment (nothing binds a socket)',
    $idOff > 0 && (int) vbc_get($idOff)['enabled'] === 0);
$threw = null;
try { vbc_update($idOff, ['enabled' => 1]); } catch (RuntimeException $e) { $threw = $e; }
t('ENABLING that staged channel without the acknowledgment is refused (code 403)',
    $threw !== null && (int) $threw->getCode() === 403 && (int) vbc_get($idOff)['enabled'] === 0);

$rec = vbc_policy_ack_record(3, 'tester');
t('recording the acknowledgment stores who, when and which statement version',
    $rec['username'] === 'tester' && $rec['user_id'] === 3 && $rec['version'] === VBC_POLICY_VERSION && $rec['at'] !== '');
t('vbc_policy_acknowledged() is now true, and vbc_policy_ack_get() returns the record',
    vbc_policy_acknowledged() === true && (vbc_policy_ack_get()['username'] ?? '') === 'tester');
$upd = vbc_update($idOff, ['enabled' => 1]);
t('with the acknowledgment recorded, the staged channel can be enabled', (int) $upd['row']['enabled'] === 1);

vbc_setting_set('dvm_policy_ack', '   ');
t('a whitespace-only value is NOT an acknowledgment (mirrors service.py exactly)', vbc_policy_acknowledged() === false);
vbc_setting_set('dvm_policy_ack', 'yes');
t('a hand-written non-JSON value still counts as acknowledged, with no detail (mirrors service.py)',
    vbc_policy_acknowledged() === true && vbc_policy_ack_get() !== null);
vbc_policy_ack_revoke();
t('revoking blanks it', vbc_policy_acknowledged() === false);
$was = vbc_disable_all_dvmproject();
t('withdrawing disables every dvmproject channel (the helper reports which were enabled)',
    count($was) === 1 && $was[0]['id'] === $idOff && (int) vbc_get($idOff)['enabled'] === 0
    && (int) vbc_get($idU)['enabled'] === 1);
vbc_policy_ack_record(3, 'tester');     // acknowledged again for the rest of the file

// ══ 4. regression: the channel appears in the registry, patchable ═══════
echo "\n4. A DVMProject channel appears in the console registry (the reported symptom)\n";
$slug = uslug('p25-tg1');
$id = vbc_create('dvmproject', $slug, 'County P25 TG 1', 'amateur', good(['fne_peer_id' => '9000123']));
$createdIds[] = $id;
$key = 'dvm:' . $slug;
$found = null;
foreach (channels_all() as $c) { if ($c['channel_key'] === $key) { $found = $c; break; } }
t('channels_all() returns the new channel', $found !== null);
t('...with adapter dvmproject, class amateur, enabled', $found && $found['adapter'] === 'dvmproject'
    && $found['regulatory_class'] === 'amateur' && (int) $found['enabled'] === 1);
t('...managed=0, so channel_registry_sync() never touches it', $found && (int) $found['managed'] === 0);
$raw = db_fetch_one("SELECT regulatory_class_locked, sort_order FROM `{$prefix}comm_channels` WHERE id = ?", [$id]);
t('...regulatory_class_locked=1', $raw && (int) $raw['regulatory_class_locked'] === 1);
t('...capabilities are exactly {voice_rx:true}', $found && $found['capabilities'] === ['voice_rx' => true]);
t('...config round-trips the validated values', $found && (int) $found['config']['listen_port'] === (int) vbc_get($id)['config']['listen_port']
    && $found['config']['talkgroup'] === '9001' && $found['config']['mode'] === 'p25');
$before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_channels`");
channel_registry_sync();
t('a channel_registry_sync() pass leaves the channel (and its settings) alone',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_channels` WHERE id = ?", [$id]) === 1
    && vbc_get($id)['regulatory_class'] === 'amateur');

// ══ 5. no secret in config_json; capabilities derivation ════════════════
echo "\n5. Secrets and capabilities\n";
$rawJson = (string) db_fetch_value("SELECT config_json FROM `{$prefix}comm_channels` WHERE id = ?", [$id]);
t('config_json carries no password/secret/token key at all',
    !preg_match('/pass|secret|token|auth/i', $rawJson));
t('vbc_capabilities(tx_enabled=false) has no voice_tx', empty(vbc_capabilities(['tx_enabled' => false])['voice_tx']));
t('vbc_capabilities(tx_enabled=true) STILL has no voice_tx while VBC_TX_SUPPORTED is false (a hand-edited row cannot light a PTT)',
    empty(vbc_capabilities(['tx_enabled' => true])['voice_tx']) && empty(vbc_capabilities(['tx_enabled' => true])['ptt_floor']));
$pub = vbc_public_config(vbc_get($id)['config']);
t('the console-facing config exposes only mode, talkgroup and listen_only (no addresses or ports)',
    array_keys($pub) === ['mode', 'talkgroup', 'listen_only'] && $pub['listen_only'] === true);
t('the leg config sent to the service carries the leg keys and a forced tx_enabled=false',
    vbc_leg_config(vbc_get($id)['config'])['tx_enabled'] === false
    && !array_key_exists('talkgroup', vbc_leg_config(vbc_get($id)['config']))
    && !array_key_exists('mode', vbc_leg_config(vbc_get($id)['config'])));

// ══ 6. update ═══════════════════════════════════════════════════════════
echo "\n6. Update\n";
$u = vbc_update($id, ['label' => 'County P25 (renamed)', 'rx_hang_ms' => 900, 'regulatory_class' => 'commercial', 'talkgroup' => '9002']);
t('label, end-of-call silence, class and talkgroup change', $u['row']['label'] === 'County P25 (renamed)'
    && (int) $u['row']['config']['rx_hang_ms'] === 900 && $u['row']['regulatory_class'] === 'commercial'
    && $u['row']['config']['talkgroup'] === '9002');
t('...the channel key and adapter are immutable', $u['row']['channel_key'] === $key && $u['row']['adapter'] === 'dvmproject');
t('...the previous row is returned for rollback', $u['before']['label'] === 'County P25 TG 1' && $u['before']['regulatory_class'] === 'amateur');
expect_invalid('updating to class internal is refused', function () use ($id) { vbc_update($id, ['regulatory_class' => 'internal']); });
expect_invalid('updating onto another channel\'s listen port is refused', function () use ($id, $idU) {
    vbc_update($id, ['listen_port' => vbc_get($idU)['config']['listen_port']]); });
$keepPort = vbc_get($id)['config']['listen_port'];
t('re-saving a channel with its OWN listen port is not a conflict', (int) vbc_update($id, ['label' => 'County P25'])['row']['config']['listen_port'] === (int) $keepPort);
vbc_restore($u['before']);
t('vbc_restore() puts a row back exactly', vbc_get($id)['label'] === 'County P25 TG 1' && vbc_get($id)['regulatory_class'] === 'amateur'
    && (int) vbc_get($id)['config']['rx_hang_ms'] === 400);
expect_invalid('a duplicate listen port at create is refused', function () use ($keepPort) {
    vbc_create('usrp_bridge', uslug('dup'), 'Dup', 'amateur', good(['listen_port' => $keepPort])); });
$dupKeyThrew = null;
try { vbc_create('dvmproject', $slug, 'Dup key', 'amateur', good()); } catch (RuntimeException $e) { $dupKeyThrew = $e; }
t('creating a second channel with the SAME key is refused (code 409)', $dupKeyThrew !== null && (int) $dupKeyThrew->getCode() === 409);
$idNs = vbc_create('usrp_bridge', $slug, 'Same slug, other adapter', 'amateur', good(['mode' => 'other']));
$createdIds[] = $idNs;
t('keys are namespaced by adapter: the same slug under usrp_bridge is a different key (usrp:...) and succeeds',
    vbc_get($idNs)['channel_key'] === 'usrp:' . $slug && $key === 'dvm:' . $slug);

// ══ 7. snippet ══════════════════════════════════════════════════════════
echo "\n7. Bridge-config snippet\n";
db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('dvm_fne_rest_password', 'S3CRET-never-in-a-snippet') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
$row = vbc_get($id);
$snip = vbc_snippet($row);
$lp = (int) $row['config']['listen_port'];
t('snippet sets udpUsrp: true', strpos($snip, 'udpUsrp: true') !== false);
t('snippet sets localAudio: false (headless)', strpos($snip, 'localAudio: false') !== false);
t('snippet sets udpAudio: true', strpos($snip, 'udpAudio: true') !== false);
t('udpSendPort equals this channel\'s listen_port (the bridge sends where TicketsCAD listens)',
    (bool) preg_match('/udpSendPort: ' . $lp . '\b/', $snip));
t('udpReceivePort equals the bridge receive port', (bool) preg_match('/udpReceivePort: 32001\b/', $snip));
t('txMode is 2 for P25', (bool) preg_match('/txMode: 2\b/', $snip));
t('txMode is 1 for DMR and 3 for analog', (bool) preg_match('/txMode: 1\b/', vbc_snippet(array_replace_recursive($row, ['config' => ['mode' => 'dmr']])))
    && (bool) preg_match('/txMode: 3\b/', vbc_snippet(array_replace_recursive($row, ['config' => ['mode' => 'analog']]))));
t('destinationId and the FNE peer id come from the channel', strpos($snip, 'destinationId: 9001') !== false && strpos($snip, 'id: 9000123') !== false);
t('the password is a placeholder, and the real REST password never appears', strpos($snip, '<FNE-PASSWORD>') !== false
    && strpos($snip, 'S3CRET') === false);
t('a log-injecting label cannot add a line to the snippet',
    substr_count(vbc_snippet(array_replace($row, ['label' => "A\nnetwork:\n  password: x"])), "\n# Generated") === 1
    && strpos(vbc_snippet(array_replace($row, ['label' => "A\nnetwork:\n  password: x"])), "\nnetwork:\n  password: x") === false);
$usnip = vbc_snippet(vbc_get($idU));
t('a usrp_bridge snippet gives values, not an invented file syntax', strpos($usnip, 'udpUsrp') === false
    && strpos($usnip, 'UDP port ' . (int) vbc_get($idU)['config']['listen_port']) !== false);

// ══ 8. delete ═══════════════════════════════════════════════════════════
echo "\n8. Delete\n";
$fx = [];
foreach ([['p155test:zello', 'local_chat', 'internal', 'P155 test chat'], ['p155test:dmr', 'dmr_bm', 'amateur', 'P155 test DMR']] as $f) {
    db_query("INSERT INTO `{$prefix}comm_channels` (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order, capabilities_json)
              VALUES (?, ?, ?, ?, 1, 0, 900, ?)",
        [$f[0], $f[1], $f[3], $f[2], json_encode(['voice_rx' => true, 'voice_tx' => true])]);
    $fx[$f[0]] = (int) db_insert_id();
}
$idDel = vbc_create('usrp_bridge', uslug('todelete'), 'Delete me', 'amateur', good());
$createdIds[] = $idDel;
db_query("INSERT INTO `{$prefix}comm_routes` (src_channel_id, dst_channel_id, gain_db, priority, ducking, enabled, allow_cross_class)
          VALUES (?, ?, 0, 0, 1, 1, 0)", [$idDel, $fx['p155test:zello']]);
$gone = vbc_delete($idDel);
t('delete returns the deleted row and the number of patches removed', $gone && $gone['row']['id'] === $idDel && $gone['routes_removed'] === 1);
t('...the channel, its state row and its patches are gone',
    vbc_get($idDel) === null
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$idDel]) === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_routes` WHERE src_channel_id = ? OR dst_channel_id = ?", [$idDel, $idDel]) === 0);
t('deleting a non-existent id returns null', vbc_delete($idDel) === null);
t('vbc_delete() will not delete a channel of ANOTHER adapter',
    vbc_delete($fx['p155test:zello']) === null
    && db_fetch_one("SELECT id FROM `{$prefix}comm_channels` WHERE id = ?", [$fx['p155test:zello']]) !== false);

// ══ 9. patch guards: out is allowed, in is refused, class guard holds ═════
echo "\n9. Patching\n";
$dvmAm = $id;                                   // amateur, listen-only
$dvmCom = vbc_create('dvmproject', uslug('part90'), 'Part 90 network', 'commercial', good(['mode' => 'p25']));
$createdIds[] = $dvmCom;
$r = null;
try { $r = matrix_route_validate($dvmAm, $fx['p155test:zello'], false); } catch (Throwable $e) {}
t('a patch OUT of a digital voice channel to an internal channel is allowed (that is the point)', is_array($r));
$r = null;
try { $r = matrix_route_validate($dvmAm, $fx['p155test:dmr'], false); } catch (Throwable $e) {}
t('a patch OUT to an amateur DMR channel is allowed (same class)', is_array($r) && $r['cross_class'] === false);
$msg = '';
try { matrix_route_validate($fx['p155test:zello'], $dvmAm, false); } catch (InvalidArgumentException $e) { $msg = $e->getMessage(); }
t('a patch INTO a digital voice channel is refused: it is listen-only', stripos($msg, 'listen-only') !== false);
$msg = '';
try { matrix_route_validate($fx['p155test:zello'], $dvmAm, true); } catch (InvalidArgumentException $e) { $msg = $e->getMessage(); }
t('...the cross-class override does NOT lift that refusal', stripos($msg, 'listen-only') !== false);
$msg = '';
try { matrix_route_validate($dvmCom, $fx['p155test:dmr'], false); } catch (InvalidArgumentException $e) { $msg = $e->getMessage(); }
t('a commercial (Part 90) digital voice channel -> amateur DMR is blocked by the Part 97.113 guard without the override',
    stripos($msg, 'Regulatory guard') !== false);
$r = null;
try { $r = matrix_route_validate($dvmCom, $fx['p155test:dmr'], true); } catch (Throwable $e) {}
t('...and passes the pairwise check WITH the audited override (and is flagged cross-class)', is_array($r) && $r['cross_class'] === true);
$grp = null; $gmsg = '';
try { $grp = matrix_group_create([$dvmAm, $fx['p155test:zello']], false, null, null); } catch (InvalidArgumentException $e) { $gmsg = $e->getMessage(); }
t('a group coupling that includes a digital voice channel is refused as a whole, creating nothing',
    $grp === null && stripos($gmsg, 'listen-only') !== false
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_routes` WHERE src_channel_id = ? OR dst_channel_id = ?", [$dvmAm, $dvmAm]) === 0);
$newRouteId = matrix_route_create(['src_channel_id' => $dvmAm, 'dst_channel_id' => $fx['p155test:zello'], 'gain_db' => 0], 3);
t('a real standing patch OUT of the channel can be created through the real writer', $newRouteId > 0);
$intoId = 0;
try { $intoId = matrix_route_create(['src_channel_id' => $fx['p155test:zello'], 'dst_channel_id' => $dvmAm], 3); } catch (Throwable $e) {}
t('...and a standing patch INTO it cannot be created through the real writer', $intoId === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_routes` WHERE src_channel_id = ? AND dst_channel_id = ?", [$fx['p155test:zello'], $dvmAm]) === 0);
$tmsg = '';
try { matrix_browser_leg_validate_channel($dvmAm, 'talk'); } catch (InvalidArgumentException $e) { $tmsg = $e->getMessage(); }
t('a console mic cannot be routed INTO it (the talk direction is refused)', stripos($tmsg, 'listen-only') !== false);
$okListen = null;
try { $okListen = matrix_browser_leg_validate_channel($dvmAm, 'listen'); } catch (Throwable $e) {}
t('...but a console can listen to it', is_array($okListen) && (int) $okListen['id'] === $dvmAm);
$okNull = null;
try { $okNull = matrix_browser_leg_validate_channel($dvmAm); } catch (Throwable $e) {}
t('...and tearing a route down (no direction) is never blocked', is_array($okNull));

// ══ 10. PHP <-> Python contract ═════════════════════════════════════════
echo "\n10. PHP validator <-> Python builder contract\n";
function p155_find_python() {
    foreach (['python3', 'python'] as $bin) {
        $p = @proc_open([$bin, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) { continue; }
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $rc = proc_close($p);
        if ($rc === 0 && preg_match('/Python 3\./', $o)) { return $bin; }
    }
    return null;
}
function p155_build_probe($py, array $config) {
    $cmd = [$py, __DIR__ . '/../services/audio-matrix/tests/build_leg_probe.py', json_encode($config)];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($p)) { return null; }
    $out = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($p);
    return $out;
}
$py = p155_find_python();
if ($py === null) {
    echo "SKIP (this section only): no Python 3 interpreter on PATH\n";
} else {
    $validCfgs = [
        vbc_get($id)['config'],
        vbc_get($idU)['config'],
        vbc_validate_config('dvmproject', good(['bridge_host' => '192.168.4.9', 'listen_host' => '192.168.4.2', 'rx_hang_ms' => 100, 'fne_peer_id' => '77'])),
        vbc_validate_config('usrp_bridge', good(['mode' => 'other', 'bridge_host' => '10.1.2.3', 'listen_host' => '10.1.2.4', 'rx_hang_ms' => 5000])),
    ];
    foreach ($validCfgs as $i => $vc) {
        $out = p155_build_probe($py, vbc_leg_config($vc));
        t("PHP-valid config #$i is accepted by the Python builder", is_string($out) && strpos($out, 'OK ') === 0);
        if (is_string($out) && strpos($out, 'OK ') === 0) {
            $spec = json_decode(substr($out, 3), true);
            t("...with the same listen port, bridge host and hang as PHP wrote (#$i)",
                (int) $spec['listen_port'] === (int) $vc['listen_port'] && $spec['bridge_host'] === $vc['bridge_host']
                && (int) $spec['rx_inactivity_ms'] === (int) $vc['rx_hang_ms']);
        }
    }
    foreach ([['listen_port' => 80, 'bridge_host' => '127.0.0.1'],
              ['listen_port' => 40000, 'bridge_host' => 'host.example.org'],
              ['listen_port' => 40000],
              ['bridge_host' => '127.0.0.1'],
              ['listen_port' => 40000, 'bridge_host' => '127.0.0.1', 'rx_hang_ms' => 50]] as $i => $bad) {
        $out = p155_build_probe($py, $bad);
        t("a config PHP would refuse is refused by Python too (#$i: " . json_encode($bad) . ")",
            is_string($out) && strpos($out, 'ERR ') === 0);
    }
}

// ══ 11. wiring: sources ═════════════════════════════════════════════════
echo "\n11. Wiring\n";
$api = (string) @file_get_contents('api/voice-bridge-channels.php');
$page = (string) @file_get_contents('voice-bridges-admin.php');
$side = (string) @file_get_contents('inc/config-sidebar.php');
t('the API gates every action on action.manage_voice_bridges', strpos($api, "rbac_can('action.manage_voice_bridges')") !== false);
$hasCall = function ($src, $fn) {
    $toks = token_get_all($src);
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        if (is_array($toks[$i]) && $toks[$i][0] === T_STRING && $toks[$i][1] === $fn) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($toks[$j]) && in_array($toks[$j][0], [T_WHITESPACE, T_COMMENT], true)) { continue; }
                if ($toks[$j] === '(') { return true; }
                break;
            }
        }
    }
    return false;
};
t('...with NO is_admin() call anywhere in the API (Phase 138 lesson; comments tokenised away)', !$hasCall($api, 'is_admin'));
t('the page gates on the same permission and has no is_admin() call',
    strpos($page, "rbac_can('action.manage_voice_bridges')") !== false && !$hasCall($page, 'is_admin'));
t('the Settings sidebar link is gated on the same permission',
    (bool) preg_match("/rbac_can\('action\.manage_voice_bridges'\)\).*voice-bridges-admin/s", $side));
t('every POST action verifies a CSRF token', substr_count($api, 'vbca_csrf_check($input);') >= 5);
t('the API writes an audit row for each state change',
    substr_count($api, "audit_log('config'") >= 6);
$mic = (string) @file_get_contents('assets/js/console-mic.js');
preg_match('/var MATRIX_ADAPTERS = \{([^}]*)\}/s', $mic, $m);
$micMap = $m[1] ?? '';
foreach (vbc_adapters() as $a) {
    t("console-mic.js MATRIX_ADAPTERS includes the catalog adapter $a (catalog <-> console parity)",
        (bool) preg_match('/\b' . $a . '\s*:\s*true/', $micMap));
}
t('event-bus.js SSE_TYPES includes comm:rx_state (an event type missing there is invisible to every consumer)',
    strpos((string) @file_get_contents('assets/js/event-bus.js'), "'comm:rx_state'") !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
