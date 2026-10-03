<?php
/**
 * CLI probe: call the REAL _p153_resolve_constituent() in a FRESH process so
 * settings (get_variable() caches the whole settings table per process) are
 * read at their current value. Starts with `_`: not a test.
 * Usage: php tests/_p155_resolve_constituent_probe.php <caller-number>
 * Prints {"id": <int|null>}.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/inc/inbound-calls.php';
echo json_encode(['id' => _p153_resolve_constituent(isset($argv[1]) ? (string) $argv[1] : null)]);
