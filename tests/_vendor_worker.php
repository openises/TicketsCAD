<?php
/**
 * CLI worker for the GH#148 vendor tests. Runs ONE library call in a fresh PHP process with the vendor settings it
 * is handed already in the database BEFORE anything in this process can call get_variable() (which caches the whole
 * settings table for the life of the process, so one process can observe only one value of a setting).
 *
 * Usage: php tests/_vendor_worker.php '<settings-json>' <action> '<args-json>'
 *   settings-json   {"vendor_advance_rule":"accepted_only", ...}  written straight to the settings table first
 *   action          offer | outcome | queue | view | order
 *   args-json       the call's arguments (actor included as "actor")
 * Prints one JSON line: the call's return value.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/incident-write.php';
require_once $root . '/inc/vendor-admin-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$settings = json_decode((string) ($argv[1] ?? '{}'), true) ?: [];
$action = (string) ($argv[2] ?? '');
$args = json_decode((string) ($argv[3] ?? '{}'), true) ?: [];

// FIRST: the settings, before any get_variable() call can populate its static cache.
foreach ($settings as $name => $value) {
    db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, $value]);
}

$actor = $args['actor'] ?? ['user_id' => null, 'name' => 'worker', 'ip' => '127.0.0.1', 'org_id' => null];
if (!empty($actor['user_id'])) { $_SESSION['user_id'] = (int) $actor['user_id']; $_SESSION['user'] = (string) ($actor['name'] ?? 'worker'); }

switch ($action) {
    case 'offer':
        $res = vendor_dispatch_offer($args['input'] ?? [], $actor);
        if (isset($res['dispatch'])) $res['dispatch'] = ['id' => (int) $res['dispatch']['id'], 'status' => $res['dispatch']['status']];
        if (isset($res['queue']['candidates'])) {
            $res['queue'] = ['mode' => $res['queue']['mode'], 'head_provider_id' => $res['queue']['head_provider_id'],
                             'order' => array_column($res['queue']['candidates'], 'provider_id')];
        }
        break;
    case 'outcome':
        $res = vendor_dispatch_outcome((int) $args['dispatch_id'], (int) $args['offer_event_id'], (string) $args['outcome'],
            isset($args['eta']) ? (int) $args['eta'] : null, $actor, $args['reason'] ?? null);
        unset($res['dispatch']);
        break;
    case 'queue':
        $q = vendor_rotation_queue((int) $args['list_id'], isset($args['dispatch_id']) ? (int) $args['dispatch_id'] : null);
        $res = ['mode' => $q['mode'], 'head_provider_id' => $q['head_provider_id'],
                'order' => array_column($q['candidates'], 'provider_id'),
                'heads' => array_values(array_map(function ($c) { return (int) $c['provider_id']; }, array_filter($q['candidates'], function ($c) { return $c['is_head']; })))];
        break;
    case 'enabled':
        $res = ['enabled' => vendor_enabled(), 'mode' => vendor_setting('vendor_rotation_mode'), 'advance' => vendor_setting('vendor_advance_rule'),
                'allow_override' => vendor_setting('vendor_allow_override'), 'requires_reason' => vendor_setting('vendor_override_requires_reason'),
                'dial' => vendor_setting('phone_click_to_call')];
        break;
    default:
        fwrite(STDERR, "unknown action: $action\n");
        exit(1);
}
echo json_encode($res), "\n";
