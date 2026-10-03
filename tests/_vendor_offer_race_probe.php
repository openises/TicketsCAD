<?php
/**
 * CLI probe for tests/test_vendor_dispatch_concurrency.php. One of these runs per "dispatcher": two are launched as
 * separate OS processes so the contention on the rotation list is real (InnoDB row lock, two connections), not two
 * sequential calls in one process.
 *
 * A start barrier makes the race honest. PHP start-up time (~100 ms) would otherwise dwarf the lock window and let
 * one process finish before the other began: each probe loads everything, writes its READY file, then spins until the
 * parent writes the GO file, and only then calls vendor_dispatch_offer().
 *
 * Usage: php tests/_vendor_offer_race_probe.php '<settings-json>' '<input-json>' '<actor-json>' <ready-file> <go-file>
 * Prints one JSON line: the offer's result (the queue trimmed to its head).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/incident-write.php';
require_once $root . '/inc/vendor-admin-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$settings = json_decode((string) ($argv[1] ?? '{}'), true) ?: [];
$input = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
$actor = json_decode((string) ($argv[3] ?? '{}'), true) ?: [];
$ready = (string) ($argv[4] ?? '');
$go = (string) ($argv[5] ?? '');

// Settings first, before anything can populate get_variable()'s per-process cache.
foreach ($settings as $name => $value) {
    db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, $value]);
}
if (!empty($actor['user_id'])) { $_SESSION['user_id'] = (int) $actor['user_id']; $_SESSION['user'] = (string) ($actor['name'] ?? 'probe'); }

// Warm everything the offer path touches (schema probe, settings cache, class loading) so the barrier-to-lock gap is tiny.
vendor_schema_ready();
vendor_setting('vendor_advance_rule');

if ($ready !== '') file_put_contents($ready, '1');
$deadline = microtime(true) + 15;
while ($go !== '' && !is_file($go) && microtime(true) < $deadline) usleep(200);

$res = vendor_dispatch_offer($input, $actor);
if (isset($res['dispatch'])) $res['dispatch'] = ['id' => (int) $res['dispatch']['id'], 'status' => $res['dispatch']['status']];
if (isset($res['queue']['candidates'])) $res['queue'] = ['head_provider_id' => $res['queue']['head_provider_id']];
echo json_encode($res), "\n";
