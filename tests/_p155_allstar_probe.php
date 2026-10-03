<?php
/**
 * CLI probe: print {"settings": allstar_relay_settings(), "enabled": allstar_relay_enabled()}
 * from a FRESH process (get_variable() caches the settings table per process).
 * Starts with `_`: not a test.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/inc/allstar-relay.php';
$s = allstar_relay_settings();
$s['ami_secret'] = $s['ami_secret'] === '' ? '' : '(set)';
echo json_encode(['settings' => $s, 'enabled' => allstar_relay_enabled()]);
