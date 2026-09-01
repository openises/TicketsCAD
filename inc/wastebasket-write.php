<?php
/**
 * Wastebasket write-path helpers — extracted so the real cascade logic is
 * directly testable from a CLI test without booting api/wastebasket.php's
 * HTTP request dispatch (session/auth/$_SERVER['REQUEST_METHOD']), matching
 * this codebase's own established inc/*-write.php pattern (see
 * inc/incident-write.php, inc/responder-write.php, inc/team-write.php) and
 * its own documented lesson about the opposite shape: reusable/testable
 * logic buried after an api/*.php endpoint's action-dispatch guard is
 * invisible to anything that can't also satisfy the guard (see
 * api/owntracks-config.php's `OT_CONFIG_LIBRARY_ONLY` pitfall in
 * CLAUDE.md).
 */

/**
 * GH#124 (2026-08-29). Permanently deleting a ticket — via a single
 * `purge` or the bulk `empty` action in api/wastebasket.php — used to
 * leave its `assigns` / `action` / `patient` rows, and any attached
 * `files`, pointing at a `ticket_id` that no longer existed anywhere.
 * Confirmed on a real install as 101 orphaned `assigns` rows: the "Clean
 * up related records" block in api/wastebasket.php already had one for
 * `member` (certifications, callsigns, organizations, comm identifiers)
 * and `responder` (allocates), but never had a `ticket` branch at all.
 *
 * Soft-delete deliberately does NOT cascade — see
 * incident_soft_delete_internal()'s own docblock in
 * inc/incident-write.php: the assigns/action/patient rows stay in place
 * so an admin can undelete cleanly with everything intact. PURGE is the
 * one-way, no-take-backs action, so THIS is where the cascade belongs —
 * mirroring the member/responder cleanup blocks that already do exactly
 * this for their own child tables.
 *
 * Deliberately does NOT touch `log` — this project's audit trail is kept
 * regardless of what it documents (see the login-audit "soft-clear with
 * cleared_at instead of DELETE" precedent, and the CJIS-posture retention
 * stance), so a purged ticket's audit history is left in place on
 * purpose, not by oversight.
 *
 * Also deliberately does NOT touch `notify`, `mi_x`, `photos`,
 * `facnotes`, or `messages_bin` — a repo-wide grep found zero NewUI code
 * (api/ or inc/) that ever reads or writes any of those tables; they are
 * legacy-v3-import artifacts this app does not use, so cascading them
 * here would be dead code addressing rows NewUI itself never creates.
 *
 * @param int[] $ticketIds
 */
function wb_purge_ticket_children(array $ticketIds): void {
    $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds), fn($id) => $id > 0)));
    if (!$ticketIds) return;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ph = implode(',', array_fill(0, count($ticketIds), '?'));

    // Attachments: unlink the on-disk blob (best effort — never let a
    // missing/already-gone file block the metadata row from being
    // cleaned up) before removing the `files` row itself.
    try {
        $attached = db_fetch_all("SELECT filename FROM `{$prefix}files` WHERE ticket_id IN ({$ph})", $ticketIds);
        $uploadDir = (defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__)) . '/uploads';
        foreach ($attached as $f) {
            $name = (string) ($f['filename'] ?? '');
            if ($name === '') continue;
            $path = $uploadDir . '/' . $name;
            if (file_exists($path)) { @unlink($path); }
        }
        db_query("DELETE FROM `{$prefix}files` WHERE ticket_id IN ({$ph})", $ticketIds);
    } catch (Exception $e) {}

    try { db_query("DELETE FROM `{$prefix}patient` WHERE ticket_id IN ({$ph})", $ticketIds); } catch (Exception $e) {}
    try { db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id IN ({$ph})", $ticketIds); } catch (Exception $e) {}
    try { db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id IN ({$ph})", $ticketIds); } catch (Exception $e) {}
}
