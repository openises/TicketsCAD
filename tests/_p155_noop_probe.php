<?php
/**
 * CLI probe for tests/test_notification_rules_queue.php - proves the "no rules"
 * path is genuinely free.
 *
 * In a FRESH process (nothing pre-loaded, so the answer is not contaminated by
 * whatever else a test file already included), with NO active notification rule:
 *
 *   1. count the SELECT statements one notification_hook() call costs
 *   2. create a real incident through the real writer and see whether the
 *      engine - which drags in the whole message broker - was ever loaded
 *
 * Prints one JSON line: {selects:int, engine_loaded_after_hook:bool,
 * engine_loaded_after_incident:bool, ticket_id:int}
 *
 * Usage: php tests/_p155_noop_probe.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
ini_set('display_errors', '0');
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/notification-hook.php';

$sel = static function (): int {
    $r = db_fetch_one("SHOW SESSION STATUS LIKE 'Com_select'");
    return (int) ($r['Value'] ?? 0);
};

$before = $sel();
notification_hook('incident_create', ['ticket_id' => 1]);
$after = $sel();
$engineAfterHook = function_exists('notification_fire');

require_once $root . '/inc/incident-write.php';
$r = incident_create_internal(['in_types_id' => 1, 'scope' => 'P155 noop probe', 'description' => 'p155',
                               'street' => '1 Probe St', 'city' => 'Nowhere'], 0);

echo json_encode([
    // SHOW STATUS is not counted as a SELECT by the server, so the delta is exactly
    // what the hook itself ran.
    'selects' => $after - $before,
    'engine_loaded_after_hook' => $engineAfterHook,
    'engine_loaded_after_incident' => function_exists('notification_fire'),
    'ticket_id' => (int) ($r['id'] ?? 0),
]) . "\n";
