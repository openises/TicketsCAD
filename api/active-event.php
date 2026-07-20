<?php
/**
 * NewUI v4.0 API — Active Event (Phase 111 Slice A)
 *
 * The "active event" is the single incident that inbound Meshtastic / Zello
 * / DMR / chat messages auto-log to (their reports append to its ICS-214
 * activity log) while a net-control operation is running. Set it once when
 * you open an event; clear it (ticket_id = 0) when the event is over.
 *
 * GET  /api/active-event.php
 *   → { active_event_ticket_id: int, scope: string|null }
 *      active_event_ticket_id is 0 when the feature is OFF (nothing set).
 *      When set, `scope` is the event incident's description for display.
 *
 * POST /api/active-event.php   (CSRF + rbac_can('action.manage_active_event'))
 *   body: { ticket_id: int, csrf_token: string }
 *     ticket_id > 0 : must exist in `ticket` (and not be soft-deleted) →
 *                     sets it as the active event.
 *     ticket_id = 0 : clears the active event (feature off).
 *   → { ok: true, active_event_ticket_id: int }
 *
 * Every change is audit-logged. Exceptions go through json_error_safe so
 * driver detail never leaks to the client.
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/message-incident.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

/**
 * Read the active-event ticket id straight from settings (not the static-
 * cached helper — this endpoint may run right after a write in the same
 * process during tests).
 */
function _ae_read_setting(string $prefix): int {
    try {
        $val = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            ['active_event_ticket_id']
        );
        if ($val !== false && $val !== null && (int) $val > 0) {
            return (int) $val;
        }
    } catch (Exception $e) {
        // settings table missing — feature off.
    }
    return 0;
}

// ── GET — read the current active event ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $ticketId = _ae_read_setting($prefix);
        $scope = null;
        if ($ticketId > 0) {
            try {
                $row = db_fetch_one(
                    "SELECT `scope` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1",
                    [$ticketId]
                );
                $scope = $row['scope'] ?? null;
            } catch (Exception $e) {
                $scope = null;
            }
        }
        json_response([
            'active_event_ticket_id' => $ticketId,
            'scope'                  => $scope,
        ]);
    } catch (Throwable $e) {
        json_error_safe('Failed to read active event', $e, 'active-event.get');
    }
}

// ── POST — set / clear the active event ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Permission: action.manage_active_event, with an is_admin() fallback
    // for pre-RBAC installs (mirrors api/routing.php's pattern).
    if (function_exists('rbac_can') && !rbac_can('action.manage_active_event')) {
        if (!function_exists('is_admin') || !is_admin()) {
            json_error('Insufficient permissions', 403);
        }
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    // CSRF
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify((string) $token)) {
        json_error('Invalid CSRF token', 403);
    }

    // ticket_id must be provided (0 clears).
    if (!array_key_exists('ticket_id', $input)) {
        json_error('ticket_id is required (0 to clear)');
    }
    $ticketId = (int) $input['ticket_id'];
    if ($ticketId < 0) {
        json_error('ticket_id must be a non-negative integer');
    }

    // When setting (not clearing), the ticket must exist and not be deleted.
    if ($ticketId > 0) {
        try {
            $exists = db_fetch_one(
                "SELECT `id`, `scope` FROM `{$prefix}ticket`
                  WHERE `id` = ?
                    AND (`deleted_at` IS NULL)
                  LIMIT 1",
                [$ticketId]
            );
        } catch (Exception $e) {
            // Pre-wastebasket install without deleted_at — retry plainly.
            try {
                $exists = db_fetch_one(
                    "SELECT `id`, `scope` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1",
                    [$ticketId]
                );
            } catch (Exception $e2) {
                $exists = null;
            }
        }
        if (!$exists) {
            json_error('Incident not found (or deleted)', 404);
        }
    }

    try {
        // Upsert the setting. UNIQUE-safe: update if the row exists, else
        // insert. (settings.name is the logical key.)
        $has = db_fetch_value(
            "SELECT `id` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            ['active_event_ticket_id']
        );
        if ($has !== false && $has !== null) {
            db_query(
                "UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = ?",
                [(string) $ticketId, 'active_event_ticket_id']
            );
        } else {
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                ['active_event_ticket_id', (string) $ticketId]
            );
        }

        // Invalidate the helper's static cache so an in-process router call
        // (or a follow-up GET in this same request) sees the new value.
        if (function_exists('mi_reset_active_event_cache')) {
            mi_reset_active_event_cache();
        }

        // Audit — never breaks the action.
        $summary = $ticketId > 0
            ? "Set active event to incident #{$ticketId}"
            : 'Cleared active event (inbound auto-logging off)';
        audit_log('comms', $ticketId > 0 ? 'update' : 'delete', 'setting',
            'active_event_ticket_id', $summary,
            ['active_event_ticket_id' => $ticketId]);

        json_response([
            'ok'                     => true,
            'active_event_ticket_id' => $ticketId,
        ]);
    } catch (Throwable $e) {
        json_error_safe('Failed to set active event', $e, 'active-event.post');
    }
}

// ── Any other method ─────────────────────────────────────────────────
json_error('Method not allowed', 405);
