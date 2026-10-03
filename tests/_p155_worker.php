<?php
/**
 * Phase 155 -- CLI worker for the concurrency and mode-sensitive tests.
 *
 * Two reasons a test shells out to this instead of calling the writer in its
 * own process:
 *
 *   1. CONCURRENCY. "Exactly one winner" can only be proven by genuinely
 *      concurrent OS processes. Each worker busy-waits until an agreed
 *      wall-clock instant (argv `startAt`, a float unix time) so several
 *      workers hit the database at the same moment.
 *   2. get_variable() caches the WHOLE `settings` table for the life of the
 *      process, with no invalidation hook. A test that needs two values of a
 *      setting (scheduled_assign_mode = immediate, then reserve) must run each
 *      value in its own fresh process. This worker applies the requested
 *      settings BEFORE anything in the process has called get_variable().
 *
 * Usage: php tests/_p155_worker.php <action> <startAt|0> [key=value ...]
 *   settings applied first, from args of the form  set:<name>=<value>
 *
 *   activate_due          incident_activate_due_scheduled(50)
 *   activate_one  id=N    incident_activate_scheduled_one(N)
 *   status  id=N to=S user=U [booked=...] [disp=D] [source=...]
 *                         incident_update_status_internal()
 *   assign  id=N unit=R user=U [force=1] [dispatch_now=1] [role=...]
 *                         assign_create_internal()
 *   promote_one res=N [force=1] [user=U]      assign_reservations_promote_one()
 *   promote_due           assign_reservations_promote_due()
 *   promote_ticket id=N   assign_reservations_promote_for_ticket()
 *
 * Prints ONE line of JSON: {"result": <the function's return value>}.
 * File name starts with `_` so tools/test_all.php does not run it as a test.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$action  = (string) ($argv[1] ?? '');
$startAt = (float) ($argv[2] ?? 0);
$args = [];
$settings = [];
for ($i = 3; $i < count($argv); $i++) {
    $a = (string) $argv[$i];
    if (strpos($a, 'set:') === 0) {
        $kv = explode('=', substr($a, 4), 2);
        if (count($kv) === 2) $settings[$kv[0]] = $kv[1];
        continue;
    }
    $kv = explode('=', $a, 2);
    if (count($kv) === 2) $args[$kv[0]] = $kv[1];
}

// Settings first -- before ANY get_variable() call can populate the cache.
$prefix = $GLOBALS['db_prefix'] ?? '';
foreach ($settings as $name => $value) {
    $has = db_fetch_value("SELECT 1 FROM `{$prefix}settings` WHERE name = ?", [$name]);
    if ($has) {
        db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = ?", [$value, $name]);
    } else {
        db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", [$name, $value]);
    }
}

require_once $root . '/inc/sse.php';
require_once $root . '/inc/incident-write.php';
require_once $root . '/inc/assignment-write.php';
require_once $root . '/inc/scheduled-incidents.php';
if (is_file($root . '/inc/assign-reservations.php')) {
    require_once $root . '/inc/assign-reservations.php';
}

// Barrier: spin until the agreed instant so concurrent workers really overlap.
if ($startAt > 0) {
    while (microtime(true) < $startAt) { usleep(200); }
}

$result = null;
switch ($action) {
    case 'activate_due':
        $result = incident_activate_due_scheduled(50);
        break;
    case 'activate_one':
        $result = incident_activate_scheduled_one((int) ($args['id'] ?? 0));
        break;
    case 'status':
        $extra = ['source' => $args['source'] ?? 'ui'];
        if (isset($args['booked'])) $extra['booked_date'] = $args['booked'];
        if (isset($args['disp']))   $extra['disposition_id'] = (int) $args['disp'];
        if (!empty($args['skip_disp'])) $extra['skip_disposition_check'] = true;
        $result = incident_update_status_internal((int) $args['id'], (int) $args['to'], (int) ($args['user'] ?? 1), $extra);
        break;
    case 'assign':
        $opts = [];
        if (!empty($args['dispatch_now'])) $opts['dispatch_now'] = true;
        $result = assign_create_internal((int) $args['id'], (int) $args['unit'], (string) ($args['role'] ?? ''),
            (int) ($args['user'] ?? 1), !empty($args['force']), $opts);
        break;
    case 'promote_one':
        $result = assign_reservations_promote_one((int) $args['res'], !empty($args['force']), (int) ($args['user'] ?? 0));
        break;
    case 'promote_due':
        $result = assign_reservations_promote_due(100);
        break;
    case 'promote_ticket':
        $result = assign_reservations_promote_for_ticket((int) $args['id'], (int) ($args['user'] ?? 0));
        break;
    default:
        fwrite(STDERR, "unknown action: {$action}\n");
        exit(2);
}
echo json_encode(['result' => $result]) . "\n";
