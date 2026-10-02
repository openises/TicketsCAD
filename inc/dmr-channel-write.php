<?php
/**
 * GH#140 (cbyrdmo, openises/TicketsCAD) — permanent DMR channel deletion.
 *
 * api/dvswitch.php's channel_delete has only ever been a soft delete
 * (disable + clear the bridge token — the Phase 35A bridge model); nothing
 * hard-deleted a dmr_channels row, so a mistakenly-created or fully
 * decommissioned channel squatted forever on its UNIQUE `label` and
 * `usrp_listen_port`. This is the writer for the real, permanent delete —
 * extracted out of the api/ endpoint (per this project's own established
 * "reusable/testable logic goes in inc/, not buried after an action-dispatch
 * guard" convention) so tests/test_gh140_dmr_channel_purge.php can drive it
 * directly, the same way inc/wastebasket-write.php's
 * wb_purge_ticket_children() is driven directly for the ticket-purge
 * cascade.
 *
 * Mirrors api/wastebasket.php's purge convention: only ever operates on a
 * record that is ALREADY soft-deleted (here: enabled=0), and cascades to
 * the rows that exist only because this channel did. None of dmr_id_log/
 * dmr_ptt_state/dmr_messages carries a real FOREIGN KEY — the same
 * "comm_routes has no hard FK" reasoning inc/matrix-routes.php documents
 * for this exact shape of relationship — so a table missing on an older
 * install is a normal no-op, not an error. channel_registry_sync() then
 * prunes the mirrored comm_channels/comm_channel_state row (channel_key
 * 'dmr_bm:<id>') immediately, rather than leaving it stale until an admin
 * next clicks Sync Channels.
 */

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/channel_registry.php';

/**
 * Permanently delete an already-disabled dmr_channels row and everything
 * that hangs off it. Throws Exception (message is safe to surface to the
 * caller) on any validation failure. Returns a summary array on success.
 *
 * @throws Exception
 */
function dmr_channel_purge_internal(int $id): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($id <= 0) {
        throw new Exception('id required');
    }

    $ch = db_fetch_one(
        "SELECT id, label, talkgroup, enabled FROM `{$prefix}dmr_channels` WHERE id = ?",
        [$id]
    );
    if (!$ch) {
        throw new Exception('not found');
    }
    if ((int) $ch['enabled'] !== 0) {
        throw new Exception(
            'Channel must be disabled (soft-deleted) before it can be permanently deleted.'
        );
    }

    $deletedIdLog    = 0;
    $deletedPttState = 0;
    $deletedMessages = 0;
    try {
        $deletedIdLog = db_query(
            "DELETE FROM `{$prefix}dmr_id_log` WHERE channel_id = ?", [$id]
        )->rowCount();
    } catch (Exception $e) { /* table absent on an older install — fine */ }
    try {
        $deletedPttState = db_query(
            "DELETE FROM `{$prefix}dmr_ptt_state` WHERE channel_id = ?", [$id]
        )->rowCount();
    } catch (Exception $e) { /* table absent on an older install — fine */ }
    try {
        $deletedMessages = db_query(
            "DELETE FROM `{$prefix}dmr_messages` WHERE channel_id = ?", [$id]
        )->rowCount();
    } catch (Exception $e) { /* table absent on an older install — fine */ }

    db_query("DELETE FROM `{$prefix}dmr_channels` WHERE id = ? AND enabled = 0", [$id]);

    $syncResult = null;
    try {
        $syncResult = channel_registry_sync();
    } catch (Exception $e) {
        error_log('[dmr_channel_purge_internal] registry sync failed: ' . $e->getMessage());
    }

    audit_log('comms', 'purge', 'dmr_channel', $id,
        "Permanently deleted DMR channel '{$ch['label']}' (TG {$ch['talkgroup']})",
        null, AUDIT_HIGH);

    return [
        'ok'                => true,
        'id'                => $id,
        'label'             => $ch['label'],
        'deleted_id_log'    => $deletedIdLog,
        'deleted_ptt_state' => $deletedPttState,
        'deleted_messages'  => $deletedMessages,
        'sync'              => $syncResult,
    ];
}
