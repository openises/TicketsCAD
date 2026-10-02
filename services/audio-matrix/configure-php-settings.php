<?php
/**
 * Sync matrix_control_url / matrix_control_token into the `settings` table
 * from the audio-matrix service's own JSON config file, so
 * inc/matrix-control-client.php (which reads them via get_variable(), NOT
 * config.php) can actually reach the service install.sh just installed.
 *
 * Run by services/audio-matrix/install.sh -- not a general fresh-install
 * migration, because the control_token is host-specific and randomly
 * generated per host (there is nothing generic to seed on an install that
 * hasn't run this service's own installer). Safe to re-run: ON DUPLICATE
 * KEY UPDATE (settings.name is UNIQUE, sql/run_phase24_settings_unique_
 * name.php) means the two sides can never silently drift apart even if
 * install.sh is re-run after a manual edit to the conf file.
 *
 * matrix_ws_url (added 2026-09-08, a solo-sysadmin persona review finding):
 * BEFORE this fix, `matrix_ws_url` -- read by api/console-session.php to
 * tell the BROWSER where to open its own WebSocket to the audio-matrix
 * service's browser leg (services/audio-matrix/legs/browser.py, port
 * 18093 by default) -- had NO writer anywhere in this tree at all: this
 * script only ever wrote matrix_control_url/matrix_control_token (the
 * PHP-to-Python loopback control plane, port 18092), and nothing else
 * ever touched matrix_ws_url. Following docs/AUDIO-MATRIX-SETUP.md exactly
 * left "Matrix Audio"/"Join Intercom" permanently, silently non-functional
 * -- the checkbox would just uncheck itself with no explanation. UNLIKE
 * the control plane above, this value genuinely cannot be auto-derived:
 * it's a PUBLIC wss:// path through a reverse proxy the admin sets up
 * themselves (the browser leg's raw ws://host:18093 is not meant to be
 * exposed directly -- no TLS, and mixed-content-blocked on any HTTPS
 * install), matching this project's already-solved Zello WSS proxy
 * pattern (docs/ZELLO-PROXY-LESSONS.md) exactly. So this script accepts
 * it as an OPTIONAL field on the conf file (`browser_public_ws_url`) --
 * present only once an admin has actually configured the reverse proxy
 * and edited the conf file to say so; absent by default, and this script
 * makes no attempt to write anything for it when absent (a genuinely
 * unconfigured install correctly gets ws_url:null from the API, which the
 * console already degrades from, per api/console-session.php's own
 * docblock -- this script's job is only to close the "there was never a
 * way to set it at all" gap, not to guess a value).
 *
 * Usage: php configure-php-settings.php /etc/ticketscad-audio-matrix.conf
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$confPath = $argv[1] ?? '/etc/ticketscad-audio-matrix.conf';
if (!is_readable($confPath)) {
    fwrite(STDERR, "[FAIL] cannot read $confPath\n");
    exit(1);
}
$conf = json_decode((string) file_get_contents($confPath), true);
if (!is_array($conf) || empty($conf['control_token'])) {
    fwrite(STDERR, "[FAIL] $confPath did not parse as JSON with a control_token\n");
    exit(1);
}

chdir(__DIR__ . '/../..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

$controlUrl = 'http://127.0.0.1:' . (int) ($conf['control_port'] ?? 18092);
$rows = [
    'matrix_control_url'   => $controlUrl,
    'matrix_control_token' => (string) $conf['control_token'],
];
// Optional -- see the docblock above. Only present once an admin has
// actually set up and documented their own reverse proxy for the browser
// leg (docs/AUDIO-MATRIX-SETUP.md's "Expose the browser leg" step).
if (!empty($conf['browser_public_ws_url']) && is_string($conf['browser_public_ws_url'])) {
    $rows['matrix_ws_url'] = $conf['browser_public_ws_url'];
}

$ok = true;
foreach ($rows as $name => $value) {
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?) "
          . "ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$name, $value]
        );
        echo "[OK] $name synced\n";
    } catch (Exception $e) {
        fwrite(STDERR, "[FAIL] writing $name: " . $e->getMessage() . "\n");
        $ok = false;
    }
}

// Verify (this project's own "a migration that swallows its own failure and
// exits 0 is a migration that never ran" lesson, applied to a settings sync).
$check = db_fetch_value(
    "SELECT value FROM `{$prefix}settings` WHERE name = 'matrix_control_url'"
);
if ($check !== $controlUrl) {
    fwrite(STDERR, "[FAIL] verification: matrix_control_url does not read back as expected\n");
    $ok = false;
}

if (!$ok) { exit(1); }
echo "\n[OK] verified -- PHP will reach the audio-matrix control plane at $controlUrl\n";
exit(0);
