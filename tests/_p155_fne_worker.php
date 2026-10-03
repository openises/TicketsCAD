<?php
/**
 * Worker for tests/test_dvm_fne_rest.php — ONE PHP process (standing in for
 * one web worker) asks the FNE for its peer list through the real client
 * code. Several of these are started at the same instant to prove the token
 * cache is single-flight: the FNE invalidates a client's token on every
 * re-auth, and all workers share one source address.
 *
 * Prints "OK <peer count> <auth calls made by this process>" or "ERR <msg>".
 * Usage: php tests/_p155_fne_worker.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
ini_set('display_errors', '0');
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/dvm-fne-rest.php';

$q = dvm_fne_peers();
if ($q['ok']) {
    echo 'OK ' . count($q['peers']) . ' ' . dvm_fne_auth_call_count() . "\n";
} else {
    echo 'ERR ' . $q['error'] . "\n";
}
