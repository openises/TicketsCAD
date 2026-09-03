<?php
/**
 * CLI worker for tests/test_phase151_primary_unit.php.
 *
 * get_variable() (inc/functions.php) caches the whole `settings` table in a
 * function-local `static $cache` for the lifetime of the PHP process, with
 * no invalidation hook — a real, deliberate perf optimization used
 * throughout this codebase, not a bug. A single test process that mutates
 * `primary_unit_mode` via direct SQL mid-run and then calls a writer that
 * reads it via get_variable() would silently keep observing the FIRST
 * value it ever cached, no matter how many times the row is updated
 * afterward. Each mode-sensitive scenario therefore runs in its OWN fresh
 * subprocess, matching this project's established CLI-probe discipline
 * (tests/_gh96_mileage_report_probe.php and siblings) — this worker sets
 * the settings row BEFORE anything in this process has ever called
 * get_variable(), so the cache that eventually populates is correct.
 *
 * Usage: php tests/_phase151_worker.php <mode> <action> <args...>
 *   set_primary          <ticket_id> <responder_id_or_0> <user_id>
 *   assign_create        <ticket_id> <responder_id> <user_id>
 *   assign_unassign      <assign_id> <user_id>
 *   assign_update_status <assign_id> <status_id> <user_id>
 * Prints one JSON line: {"result": <writer's return value>, "primary_responder_id": <int|null>}
 * The ticket id for the post-action lookup is taken from the action's own
 * first ticket-shaped argument where applicable, or -1 when not applicable
 * (assign_unassign/assign_update_status resolve it from the assigns row).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/incident-write.php';
require_once $root . '/inc/assignment-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$mode = (string) ($argv[1] ?? 'off');
$action = (string) ($argv[2] ?? '');

// Set the mode via direct SQL BEFORE any get_variable() call in this
// process can happen — this line must stay first.
db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = ?", [$mode, 'primary_unit_mode']);

$ticketIdForLookup = -1;
$result = null;

switch ($action) {
    case 'set_primary':
        $ticketId = (int) $argv[3];
        $responderId = (int) $argv[4];
        $userId = (int) $argv[5];
        $result = incident_set_primary_internal($ticketId, $responderId > 0 ? $responderId : null, $userId, 'manual');
        $ticketIdForLookup = $ticketId;
        break;
    case 'assign_create':
        $ticketId = (int) $argv[3];
        $responderId = (int) $argv[4];
        $userId = (int) $argv[5];
        $result = assign_create_internal($ticketId, $responderId, '', $userId);
        $ticketIdForLookup = $ticketId;
        break;
    case 'assign_unassign':
        $assignId = (int) $argv[3];
        $userId = (int) $argv[4];
        $result = assign_unassign_internal($assignId, $userId);
        $ticketIdForLookup = (int) ($result['ticket_id'] ?? -1);
        break;
    case 'assign_update_status':
        $assignId = (int) $argv[3];
        $statusId = (int) $argv[4];
        $userId = (int) $argv[5];
        $result = assign_update_status_internal($assignId, $statusId, $userId);
        $ticketIdForLookup = (int) db_fetch_value(
            "SELECT ticket_id FROM `{$prefix}assigns` WHERE id = ?", [$assignId]);
        break;
    default:
        fwrite(STDERR, "unknown action: $action\n");
        exit(1);
}

$primaryResponderId = null;
if ($ticketIdForLookup > 0) {
    $v = db_fetch_value("SELECT primary_responder_id FROM `{$prefix}ticket` WHERE id = ?", [$ticketIdForLookup]);
    $primaryResponderId = ($v !== null && $v !== false) ? (int) $v : null;
}

echo json_encode(['result' => $result, 'primary_responder_id' => $primaryResponderId]) . "\n";
