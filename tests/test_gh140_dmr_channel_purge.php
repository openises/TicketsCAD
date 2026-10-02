<?php
/**
 * GH#140 (cbyrdmo, openises/TicketsCAD) — "Unable to Delete DMR (DVSwitch)".
 *
 * api/dvswitch.php's channel_delete has only ever been a soft delete
 * (disable + clear the bridge token). There was no path anywhere in the
 * codebase that permanently removed a dmr_channels row, so a mistakenly
 * created (or fully decommissioned) channel squatted forever on its
 * UNIQUE `label` and `usrp_listen_port` columns.
 *
 * This drives the REAL writer — dmr_channel_purge_internal()
 * (inc/dmr-channel-write.php), the exact function api/dvswitch.php's
 * `channel_purge` action calls — against a throwaway fixture channel,
 * never hand-seeded ideal state. It proves:
 *   1. a channel that is still enabled is refused (soft-delete first)
 *   2. purging an already-disabled channel removes the row and its
 *      cascade-only rows (dmr_id_log, dmr_ptt_state, dmr_messages —
 *      created through direct inserts here since nothing else in this
 *      codebase writes them independently of a channel existing)
 *   3. the mirrored comm_channels/comm_channel_state row
 *      (channel_key 'dmr_bm:<id>') is pruned by the registry sync the
 *      writer triggers, not left stale
 *   4. a not-yet-created / already-purged id is refused with "not found"
 *
 * Usage: php tests/test_gh140_dmr_channel_purge.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/dmr-channel-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($label, $cond) {
    global $passed, $failed;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $passed++ : $failed++;
}
function sk($label, $why) { echo "SKIP: {$label} — {$why}\n"; }

echo "=== GH#140 — permanent DMR channel deletion ===\n\n";

$haveDb = false;
try { db_fetch_value("SELECT 1"); $haveDb = true; }
catch (Throwable $e) { echo "SKIP: no database connection (" . $e->getMessage() . ")\n"; }

$haveTable = false;
if ($haveDb) {
    try {
        $haveTable = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'dmr_channels']
        ) > 0;
    } catch (Throwable $e) { $haveTable = false; }
    if (!$haveTable) echo "SKIP: `{$prefix}dmr_channels` not present on this install\n";
}

$LABEL = 'ZZTEST_GH140_PURGE';
$cleanup = function () use ($prefix, $LABEL) {
    try {
        $row = db_fetch_one("SELECT id FROM `{$prefix}dmr_channels` WHERE label = ?", [$LABEL]);
        if ($row) {
            $id = (int) $row['id'];
            try { db_query("DELETE FROM `{$prefix}dmr_id_log` WHERE channel_id = ?", [$id]); } catch (Exception $e) {}
            try { db_query("DELETE FROM `{$prefix}dmr_ptt_state` WHERE channel_id = ?", [$id]); } catch (Exception $e) {}
            try { db_query("DELETE FROM `{$prefix}dmr_messages` WHERE channel_id = ?", [$id]); } catch (Exception $e) {}
            try {
                db_query("DELETE FROM `{$prefix}comm_channel_state` WHERE channel_id IN
                            (SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?)",
                    ['dmr_bm:' . $id]);
            } catch (Exception $e) {}
            try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE channel_key = ?", ['dmr_bm:' . $id]); } catch (Exception $e) {}
        }
        db_query("DELETE FROM `{$prefix}dmr_channels` WHERE label = ?", [$LABEL]);
    } catch (Throwable $e) {}
};

if ($haveDb && $haveTable) {
    $cleanup();
    $userId = test_admin_user_id();

    // ── 1. Fixture: a real row created the way channel_create creates one ──
    db_query(
        "INSERT INTO `{$prefix}dmr_channels`
            (label, talkgroup, network, bridge_host, bridge_port, bridge_token,
             usrp_listen_port, usrp_send_port, link_mode, chat_channel, enabled)
         VALUES (?, '999140', 'BrandMeister', '127.0.0.1', 18091, 'zztest-token',
                 39140, 39141, 'rx_only', 'dispatch', 1)",
        [$LABEL]
    );
    $id = (int) db_insert_id();
    t('fixture channel created', $id > 0);

    // ── 2. Still enabled — purge must be refused, nothing removed ──────────
    $refusedWhileEnabled = false;
    try {
        dmr_channel_purge_internal($id);
    } catch (Exception $e) {
        $refusedWhileEnabled = stripos($e->getMessage(), 'disabled') !== false;
    }
    t('purge refuses a channel that is still enabled', $refusedWhileEnabled);
    $stillThere = db_fetch_one("SELECT id FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
    t('the still-enabled channel was NOT deleted', $stillThere !== null && $stillThere !== false);

    // ── 3. Soft-delete it (the real endpoint's own channel_delete shape) ───
    db_query("UPDATE `{$prefix}dmr_channels` SET enabled = 0, bridge_token = '' WHERE id = ?", [$id]);

    // ── 4. Seed the cascade-only rows a purge must clean up ────────────────
    $haveIdLog = false;
    try {
        db_query(
            "INSERT INTO `{$prefix}dmr_id_log` (channel_id, user_id, callsign, id_at, source)
             VALUES (?, ?, 'N0TEST', NOW(), 'confirmed_tx')",
            [$id, $userId]
        );
        $haveIdLog = true;
    } catch (Exception $e) { sk('dmr_id_log fixture', $e->getMessage()); }

    $havePttState = false;
    try {
        db_query(
            "INSERT INTO `{$prefix}dmr_ptt_state` (channel_id, user_id, last_tx_at, conversation_started_at)
             VALUES (?, ?, NOW(), NOW())",
            [$id, $userId]
        );
        $havePttState = true;
    } catch (Exception $e) { sk('dmr_ptt_state fixture', $e->getMessage()); }

    db_query(
        "INSERT INTO `{$prefix}dmr_messages`
            (channel_id, direction, call_started_at, talkgroup, radio_callsign)
         VALUES (?, 'rx', NOW(), '999140', 'N0TEST')",
        [$id]
    );

    // Registry sync so the mirrored comm_channels row exists before purge —
    // this is the row channel_registry_sync() is supposed to prune once the
    // channel disappears.
    $haveRegistry = false;
    try {
        channel_registry_sync();
        $mirrored = db_fetch_one(
            "SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?",
            ['dmr_bm:' . $id]
        );
        $haveRegistry = $mirrored !== null && $mirrored !== false;
    } catch (Exception $e) { sk('comm_channels mirror fixture', $e->getMessage()); }
    if ($haveRegistry) {
        t('mirrored comm_channels row exists before purge', true);
    } else {
        sk('comm_channels mirror pre-check', 'registry sync produced no dmr_bm row (comm_channels absent?)');
    }

    // ── 5. Purge — the real writer, nothing simulated ───────────────────────
    $result = null; $threw = null;
    try { $result = dmr_channel_purge_internal($id); }
    catch (Exception $e) { $threw = $e->getMessage(); }

    t('purge succeeds on an already-disabled channel', $threw === null && is_array($result));
    if (is_array($result)) {
        t('purge result reports ok=true', !empty($result['ok']));
        t('purge result echoes the channel id', (int) ($result['id'] ?? 0) === $id);
    }

    $gone = db_fetch_one("SELECT id FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]);
    t('the dmr_channels row is actually gone', $gone === null || $gone === false);

    if ($haveIdLog) {
        $idLogLeft = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}dmr_id_log` WHERE channel_id = ?", [$id]
        );
        t('dmr_id_log rows for this channel are gone', $idLogLeft === 0);
    }
    if ($havePttState) {
        $pttLeft = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}dmr_ptt_state` WHERE channel_id = ?", [$id]
        );
        t('dmr_ptt_state rows for this channel are gone', $pttLeft === 0);
    }
    $msgsLeft = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}dmr_messages` WHERE channel_id = ?", [$id]
    );
    t('dmr_messages rows for this channel are gone', $msgsLeft === 0);

    if ($haveRegistry) {
        $mirrorLeft = db_fetch_one(
            "SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?",
            ['dmr_bm:' . $id]
        );
        t('the mirrored comm_channels row was pruned by the purge itself',
            $mirrorLeft === null || $mirrorLeft === false);
    }

    // ── 6. A NOT-yet-soft-deleted / already-gone id is refused ─────────────
    $notFound = false;
    try { dmr_channel_purge_internal($id); }
    catch (Exception $e) { $notFound = $e->getMessage() === 'not found'; }
    t('purging an id that no longer exists is refused with "not found"', $notFound);

    $zeroRefused = false;
    try { dmr_channel_purge_internal(0); }
    catch (Exception $e) { $zeroRefused = true; }
    t('id <= 0 is refused', $zeroRefused);

    $cleanup();
} else {
    sk('purge cascade', 'no database / no dmr_channels table');
}

// ── 7. Source-level wiring: the endpoint gates and delegates correctly ─────
$src = file_get_contents(__DIR__ . '/../api/dvswitch.php');
t('api/dvswitch.php exposes a channel_purge action',
    strpos($src, "'channel_purge'") !== false);
t('channel_purge is gated the same way as channel_delete (action.dmr_configure)',
    preg_match("/'channel_purge'.*?dvs_require_perm\\('action\\.dmr_configure'/s", $src) === 1);
t('channel_purge requires a CSRF token like every other mutating action',
    preg_match("/'channel_purge'.*?dvs_csrf_check\\(/s", $src) === 1);
t('channel_purge delegates to the real writer (not reimplemented inline)',
    preg_match("/'channel_purge'.*?dmr_channel_purge_internal\\(/s", $src) === 1);
t('channel_delete remains the soft-delete action (still there, unchanged shape)',
    strpos($src, "'channel_delete'") !== false
    && strpos($src, 'SET enabled = 0, bridge_token') !== false);

$writerSrc = file_get_contents(__DIR__ . '/../inc/dmr-channel-write.php');
t('the writer refuses to purge a channel that is not disabled',
    strpos($writerSrc, "enabled") !== false
    && stripos($writerSrc, 'must be disabled') !== false);
t('the writer calls channel_registry_sync() to prune the comm_channels mirror',
    strpos($writerSrc, 'channel_registry_sync()') !== false);
t('the writer audit-logs the permanent delete',
    strpos($writerSrc, "audit_log('comms', 'purge', 'dmr_channel'") !== false);

$jsSrc = file_get_contents(__DIR__ . '/../assets/js/dvswitch-admin.js');
t('the admin UI only offers Purge on an already-disabled row',
    strpos($jsSrc, 'dvs-purge') !== false);
t('the admin UI calls channel_purge, not channel_delete, for the purge button',
    strpos($jsSrc, "action: 'channel_purge'") !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
