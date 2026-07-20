<?php
/**
 * Phase 114a — communications channel registry tests
 *
 * Covers: schema, adapter catalog sanity, sync idempotency + key stability,
 * override preservation across syncs, dmr_bm enabled-follows-source, prune,
 * state upsert + swallow-on-failure, probe wiring, RBAC permission seeding,
 * API file guards.
 *
 * Usage: php tests/test_channel_registry.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/channel_registry.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 114a channel registry ===\n\n";

// ── Schema ───────────────────────────────────────────────────────────────
foreach (['comm_channels', 'comm_channel_state'] as $tbl) {
    $ok = false;
    try { db_query("SELECT 1 FROM `{$prefix}$tbl` LIMIT 1"); $ok = true; } catch (Exception $e) {}
    t("table $tbl exists", $ok);
}

// ── Adapter catalog ──────────────────────────────────────────────────────
$cat = channel_adapter_catalog();
t('catalog covers the shipping adapters',
    isset($cat['zello'], $cat['dmr_bm'], $cat['mesh'], $cat['aprs'], $cat['local_chat'],
          $cat['nws'], $cat['eventbus']));
t('catalog covers the future adapters (designer needs their capabilities)',
    isset($cat['allstar'], $cat['sip'], $cat['intercom'], $cat['ptt1'], $cat['dmr_local']));
t('regulatory classes: DMR/APRS/AllStar amateur, SIP pstn, Zello internal',
    $cat['dmr_bm']['regulatory_class'] === 'amateur'
    && $cat['aprs']['regulatory_class'] === 'amateur'
    && $cat['allstar']['regulatory_class'] === 'amateur'
    && $cat['sip']['regulatory_class'] === 'pstn'
    && $cat['zello']['regulatory_class'] === 'internal');
t('capability shapes: zello voice+text+image, mesh text-only, sip full_duplex, intercom door actuator',
    !empty($cat['zello']['capabilities']['voice_tx']) && !empty($cat['zello']['capabilities']['image'])
    && empty($cat['mesh']['capabilities']['voice_tx']) && !empty($cat['mesh']['capabilities']['text_tx'])
    && !empty($cat['sip']['capabilities']['full_duplex'])
    && in_array('door', $cat['intercom']['capabilities']['actuators'] ?? [], true));
t('source channels flagged (nws, eventbus)',
    !empty($cat['nws']['capabilities']['source']) && !empty($cat['eventbus']['capabilities']['source']));

// ── Sync idempotency + key stability ─────────────────────────────────────
$r1 = channel_registry_sync();
$r2 = channel_registry_sync();
t('second sync is a no-op (idempotent)',
    $r2['created'] === 0 && $r2['pruned'] === 0);
t('eventbus:main always present', channel_get('eventbus:main') !== null);
t('broker:local_chat present and enabled by default', (function () {
    $c = channel_get('broker:local_chat');
    return $c && (int) $c['enabled'] === 1;
})());
t('every channel row has parsed capabilities + a state row joined', (function () {
    foreach (channels_all() as $c) {
        if (!is_array($c['capabilities']) || !count($c['capabilities'])) return false;
        if (!array_key_exists('state', $c)) return false;
    }
    return count(channels_all()) > 0;
})());

// ── Override preservation ────────────────────────────────────────────────
$eb = channel_get('eventbus:main');
db_query("UPDATE `{$prefix}comm_channels` SET label = 'My Custom Label', color = '#ff0000', enabled = 0 WHERE id = ?", [$eb['id']]);
channel_registry_sync();
$eb2 = channel_get('eventbus:main');
t('sync preserves admin overrides (label/color/enabled untouched)',
    $eb2['label'] === 'My Custom Label' && $eb2['color'] === '#ff0000' && (int) $eb2['enabled'] === 0);
db_query("UPDATE `{$prefix}comm_channels` SET label = 'Event Bus', color = NULL, enabled = 1 WHERE id = ?", [$eb['id']]);

// ── dmr_bm: enabled follows source; prune on source delete ──────────────
$dmrOk = true;
try {
    db_query("INSERT INTO `{$prefix}dmr_channels`
        (label, talkgroup, bridge_host, bridge_token, usrp_listen_port, usrp_send_port, enabled)
        VALUES ('_test114_', '3127', 'localhost', REPEAT('a',64), 64998, 64999, 1)");
    $srcId = db_insert_id();
    channel_registry_sync();
    $ch = channel_get('dmr_bm:' . $srcId);
    t('dmr_channels row surfaces as a managed dmr_bm channel',
        $ch && (int) $ch['enabled'] === 1 && (int) $ch['managed'] === 1
        && !empty($ch['config']['id_policy']) && $ch['regulatory_class'] === 'amateur');
    db_query("UPDATE `{$prefix}dmr_channels` SET enabled = 0 WHERE id = ?", [$srcId]);
    channel_registry_sync();
    $ch = channel_get('dmr_bm:' . $srcId);
    t('dmr_bm enabled follows its source config panel', $ch && (int) $ch['enabled'] === 0);
    db_query("DELETE FROM `{$prefix}dmr_channels` WHERE id = ?", [$srcId]);
    $r = channel_registry_sync();
    t('managed row pruned when its source disappears',
        channel_get('dmr_bm:' . $srcId) === null && $r['pruned'] >= 1);
} catch (Exception $e) {
    $dmrOk = false;
    t('dmr_bm sync round-trip (dmr_channels table available)', false);
    echo "       " . $e->getMessage() . "\n";
}

// ── Unmanaged rows: sync keeps hands off ─────────────────────────────────
db_query("INSERT INTO `{$prefix}comm_channels`
    (channel_key, adapter, label, capabilities_json, regulatory_class, enabled, managed)
    VALUES ('test:manual', 'sip', 'Hand-made SIP', '{\"voice_rx\":true}', 'pstn', 1, 0)");
$manualId = db_insert_id();
channel_registry_sync();
t('unmanaged (hand-created) row survives sync untouched',
    channel_get('test:manual') !== null);

// ── State upsert ─────────────────────────────────────────────────────────
t('channel_state_set upserts and channels_all joins it', (function () use ($manualId) {
    channel_state_set($manualId, ['state' => 'connected', 'last_caller' => 'N0NKI', 'last_rx_at' => date('Y-m-d H:i:s')]);
    $c = channel_get($manualId);
    return $c['state'] === 'connected' && $c['last_caller'] === 'N0NKI';
})());
t('channel_state_set ignores unknown fields and never throws', (function () use ($manualId) {
    $r = channel_state_set($manualId, ['bogus_column' => 'x']);
    return $r === false; // nothing allowed to write → false, no exception
})());
db_query("DELETE FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$manualId]);
db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$manualId]);

// ── Probe runs without throwing ──────────────────────────────────────────
t('channel_registry_probe runs clean', (function () {
    try { channel_registry_probe(); return true; } catch (Exception $e) { return false; }
})());

// ── RBAC permissions seeded ──────────────────────────────────────────────
foreach (['screen.console', 'console.customize', 'console.design',
          'action.console_tx', 'action.intercom_unlock'] as $code) {
    $id = db_fetch_value("SELECT id FROM `{$prefix}permissions` WHERE code = ?", [$code]);
    t("permission $code seeded", (bool) $id);
}
t('operational console perms granted to Dispatcher (role 3)', (function () use ($prefix) {
    foreach (['screen.console', 'action.console_tx', 'console.customize'] as $code) {
        $n = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}role_permissions` rp
              JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
             WHERE p.code = ? AND rp.role_id = 3", [$code]);
        if (!(int) $n) return false;
    }
    return true;
})());
t('console.design NOT granted to Dispatcher', (function () use ($prefix) {
    $n = db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}role_permissions` rp
          JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
         WHERE p.code = 'console.design' AND rp.role_id = 3");
    return (int) $n === 0;
})());

// ── API file wiring guards ───────────────────────────────────────────────
$api = (string) @file_get_contents('api/channels.php');
t('api/channels.php: auth + RBAC + CSRF + safe errors',
    strpos($api, "require_once __DIR__ . '/auth.php'") !== false
    && strpos($api, "rbac_can('screen.console')") !== false
    && strpos($api, 'csrf_verify(') !== false
    && strpos($api, 'json_error_safe(') !== false
    && strpos($api, "ini_set('display_errors', '0')") !== false);
t('api/channels.php: update path validates color and audits',
    strpos($api, "preg_match('/^#[0-9a-fA-F]{3,8}\$/'") !== false
    && substr_count($api, 'audit_log(') >= 2);
t('api/channels.php: text send is capability- and permission-gated',
    strpos($api, "capabilities']['text_tx']") !== false
    && strpos($api, "rbac_can('action.send_chat')") !== false
    && strpos($api, "rbac_can('action.console_tx')") !== false
    && strpos($api, 'broker_send(') !== false);
t('api/channels.php: feed covers zello/dmr/local_chat/nws/eventbus/broker',
    strpos($api, 'zello_messages') !== false
    && strpos($api, 'dmr_messages') !== false
    && strpos($api, 'chat_messages') !== false
    && strpos($api, 'weather_alerts') !== false
    && strpos($api, 'sse_events') !== false);

// ── Console page (114b slice b1) wiring guards ───────────────────────────
$page = (string) @file_get_contents('console.php');
t('console.php: auth, RBAC gate, CSRF meta, cache-busted assets',
    strpos($page, "rbac_can('screen.console')") !== false
    && strpos($page, 'csrf-token') !== false
    && strpos($page, "asset_v('assets/js/console.js')") !== false
    && strpos($page, "asset_v('assets/css/console.css')") !== false
    && strpos($page, 'zello-widget.js') !== false);
$js = (string) @file_get_contents('assets/js/console.js');
t('console.js: ES5 IIFE, no template literals / arrow functions / let-const',
    strpos($js, "(function () {") === 0 || strpos($js, '(function () {') !== false);
t('console.js style check', !preg_match('/=>|`|\blet\s|\bconst\s/', $js));
t('console.js: strips bind voice to existing backends + text drawer + in-place refresh',
    strpos($js, "'zello:toggle'") !== false
    && strpos($js, "setAttribute('data-action', 'radio')") !== false
    && strpos($js, 'updateInPlace') !== false
    && strpos($js, "action: 'send'") !== false
    && strpos($js, 'csrf_token: csrf') !== false);
$nav = (string) @file_get_contents('inc/navbar.php');
t('navbar links console.php gated on screen.console',
    strpos($nav, "console.php") !== false && strpos($nav, "rbac_can('screen.console')") !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
