<?php
/**
 * CLI helper for tests/test_vendor_review_fixes.php: plays the part of "another dispatcher mid-offer".
 *
 * It opens a transaction, takes the row lock on the given provider the way vendor_dispatch_offer() does, optionally
 * writes a ledger row that names that provider (what the offer's final INSERT does), prints READY, holds the lock for N
 * seconds and then commits. The test uses it to prove two things with real connections: an offer for the same company
 * WAITS for this holder, and a delete of that company waits too and then sees the row and refuses.
 *
 * Usage: php tests/_vendor_lock_holder.php <provider-id> <list-id|0> <hold-seconds> <write-ledger-row:0|1>
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/vendor-admin-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$providerId = (int) ($argv[1] ?? 0);
$listId = (int) ($argv[2] ?? 0);
$hold = max(0, min(10, (int) ($argv[3] ?? 2)));
$writeRow = (int) ($argv[4] ?? 0) === 1;

$pdo = db();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
$pdo->beginTransaction();
db_query("SELECT `id` FROM `{$prefix}vendor_providers` WHERE `id` = ? FOR UPDATE", [$providerId]);
if ($writeRow) {
    vendor_ledger_insert([
        'event_type' => 'joined_list', 'list_id' => $listId > 0 ? $listId : null, 'provider_id' => $providerId,
        'provider_name' => 'lock holder', 'consumed_turn' => 0, 'detail' => 'test row written by the lock holder',
    ], ['user_id' => null, 'name' => 'lock-holder', 'ip' => '127.0.0.1']);
}
echo "READY\n";
flush();
sleep($hold);
$pdo->commit();
echo "DONE\n";
