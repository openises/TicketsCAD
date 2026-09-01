<?php
/**
 * test_upgrade_path.php — the ≥30-assertion regression suite
 * specs/legacy-upgrade-2026-05/plan.md calls for and SPEC-STATUS.md's B13
 * names as missing (`tests/test_upgrade_path.php` "does not exist").
 *
 * Found while writing this file, not part of the original ask: a real,
 * previously-undiscovered defect in tools/upgrade/settings_migrate.php --
 * the ONE script whose entire job is carrying legacy v3.44 settings
 * forward into NewUI. Its SMTP handling was built against
 * email_host/email_port/email_user/email_pass/phpmailer_* as the assumed
 * legacy key names. NONE of those exist anywhere in the legacy v3.44 tree
 * (confirmed by grep) -- v3.44 stores SMTP config as a single combined
 * key, `smtp_acct`, slash-delimited in the fixed order documented in its
 * own do_smtp_mail() (tickets/incs/smtp.inc.php:37): "0 = server, 1 =
 * port, 2 = security, 3 = user, 4 = password". So every real v3.44
 * upgrade had its SMTP config silently DROPPED -- the rename loop just
 * no-ops on a key that was never present. The destination keys were ALSO
 * wrong regardless of the source: inc/channels/smtp.php's
 * _smtp_get_config() reads smtp_host/smtp_port/smtp_encryption/
 * smtp_user/smtp_pass (underscore, no dot), not smtp.host/smtp.port/etc
 * -- a second, independent bug that would have persisted even with the
 * right source key. tools/upgrade/postcheck.php's own verification
 * report independently searched for the same wrong 'smtp.%' prefix, so
 * even a correctly-migrated SMTP config would never have shown up in the
 * upgrade verification report an operator is told to check.
 *
 * tile_url -> map.tile_url had the isolated second half of the same bug:
 * tile_url genuinely is v3.44's key, but api/map-config.php reads the
 * setting as tile_server_url, not map.tile_url -- nothing has ever read
 * the old destination key.
 *
 * All three are fixed in the same change as this test file. This suite
 * proves the fix by parsing a REALISTIC smtp_acct value through the REAL
 * settings_migrate.php script (run as a subprocess against real fixture
 * rows -- never hand-seeding the "already migrated" shape) and confirming
 * the output is readable by the REAL, unmodified inc/channels/smtp.php
 * reader -- cross-compatibility, not just internal self-consistency.
 *
 * Sections:
 *   1. preflight.php      -- runs against the real dev DB (pure read)
 *   2. settings_migrate.php -- the core fix: smtp_acct + tile_url,
 *      idempotency, operator-value preservation, malformed-input handling
 *   3. smoke_test.php     -- runs against the real dev DB (pure read +
 *      one self-cleaning audit_log write)
 *   4. postcheck.php      -- confirms the settings report now surfaces
 *      the corrected keys
 *   5. run.php (static)   -- step ordering / structure, since the
 *      interactive confirm prompt makes a live run unsuitable for CI
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/_test_fixture_guard.php';
require_once __DIR__ . '/_test_node_probe.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');
$php    = PHP_BINARY ?: '/c/xampp/8.2.4/php/php.exe';

echo "=== Legacy v3.44 -> NewUI v4 upgrade path (specs/legacy-upgrade-2026-05, SPEC-STATUS.md B13) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

function upgrade_run(string $php, string $script, array $args = []): array {
    $out = test_run_cli(array_merge([$php, $script], $args));
    return ['out' => $out];
}

/**
 * This test mutates real, non-namespaced settings keys (smtp_host,
 * tile_server_url, etc.) on the shared dev database this suite runs
 * against -- some of which may ALREADY hold a real, meaningful value
 * (tile_server_url does, on this dev box: a real OSM tile URL). A naive
 * "DELETE what I touched" teardown would permanently destroy that
 * pre-existing configuration rather than restore it. Snapshot every key
 * this test is about to touch BEFORE touching it, and restore the exact
 * prior state (present-with-value, or genuinely absent) in teardown.
 */
function snapshot_settings(string $prefix, array $keys): array {
    $snap = [];
    foreach ($keys as $k) {
        $row = db_fetch_one("SELECT value FROM `{$prefix}settings` WHERE name = ?", [$k]);
        $snap[$k] = $row ? $row['value'] : null; // null = key did not exist
    }
    return $snap;
}
function restore_settings(string $prefix, array $snapshot): void {
    foreach ($snapshot as $k => $val) {
        db_query("DELETE FROM `{$prefix}settings` WHERE name = ?", [$k]);
        if ($val !== null) {
            db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", [$k, $val]);
        }
    }
}

$touchedKeys = ['smtp_acct', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user',
                'smtp_pass', 'email_mode', 'tile_url', 'tile_server_url'];
$originalSnapshot = snapshot_settings($prefix, $touchedKeys);

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. preflight.php (pure read, real dev DB) --\n";
// ─────────────────────────────────────────────────────────────────────
$r1 = upgrade_run($php, $base . '/tools/upgrade/preflight.php', ['--json']);
is_true($r1['out'] !== null, 'preflight.php ran and produced output');
$p1 = $r1['out'] !== null ? json_decode(trim($r1['out']), true) : null;
is_true(is_array($p1) && isset($p1['overall']), 'preflight.php --json output decodes with an overall verdict', (string) $r1['out']);
is_true(is_array($p1) && isset($p1['checks']) && count($p1['checks']) === 9, 'preflight.php runs all 9 documented checks', json_encode($p1['checks'] ?? null));
if (is_array($p1)) {
    $names = array_column($p1['checks'], 'check');
    foreach (['PHP version', 'PHP extensions', 'Database connection', 'DB engine version',
              'Legacy tables present', 'Data volume', 'Disk free for backup',
              'RBAC v2 schema state', 'Timezone alignment'] as $expected) {
        is_true(in_array($expected, $names, true), "preflight.php includes the \"{$expected}\" check");
    }
}
is_true(in_array(($p1['overall'] ?? ''), ['pass', 'warn'], true),
    'preflight.php reports pass/warn against this dev DB (fail would mean a real environment problem)',
    json_encode($p1['overall'] ?? null));

try {
    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 2. settings_migrate.php: the smtp_acct / tile_url fix --\n";
    // ─────────────────────────────────────────────────────────────────
    // Clean slate: remove anything a prior run of this file (or a real
    // migration) may have left, so this run's assertions reflect ONLY
    // what THIS invocation produces.
    foreach (['smtp_acct', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user',
              'smtp_pass', 'email_mode', 'tile_url', 'tile_server_url'] as $k) {
        db_query("DELETE FROM `{$prefix}settings` WHERE name = ?", [$k]);
    }

    // A realistic legacy value in the REAL field order (server/port/
    // security/user/password) per do_smtp_mail()'s own authoritative
    // comment -- not the stale example in config.inc.php's docblock.
    $legacySmtpAcct = 'outgoing.example.com/587/tls/dispatch@example.com/S3cretPass!';
    db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", ['smtp_acct', $legacySmtpAcct]);
    db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", ['tile_url', 'https://tiles.example.com/{z}/{x}/{y}.png']);

    $r2 = upgrade_run($php, $base . '/tools/upgrade/settings_migrate.php');
    is_true($r2['out'] !== null, 'settings_migrate.php ran', (string) $r2['out']);
    is_true($r2['out'] !== null && strpos($r2['out'], '[smtp]') !== false,
        'settings_migrate.php reports the smtp_acct parse in its output', (string) $r2['out']);
    is_true($r2['out'] !== null && strpos($r2['out'], 'tile_url -> tile_server_url') !== false,
        'settings_migrate.php reports the tile_url rename', (string) $r2['out']);

    $smtpHost = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp_host'");
    $smtpPort = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp_port'");
    $smtpEnc  = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp_encryption'");
    $smtpUser = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp_user'");
    $smtpPass = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp_pass'");
    $emailMode = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'email_mode'");

    is_true($smtpHost === 'outgoing.example.com', 'FIX: smtp_host parsed correctly from smtp_acct', (string) $smtpHost);
    is_true($smtpPort === '587', 'FIX: smtp_port parsed correctly', (string) $smtpPort);
    is_true($smtpEnc === 'tls', 'FIX: smtp_encryption parsed correctly', (string) $smtpEnc);
    is_true($smtpUser === 'dispatch@example.com', 'FIX: smtp_user parsed correctly', (string) $smtpUser);
    is_true($smtpPass === 'S3cretPass!', 'FIX: smtp_pass parsed correctly (including special characters)', (string) $smtpPass);
    is_true($emailMode === 'smtp', 'FIX: email_mode is set to smtp so the migrated config is actually used, not left behind the sendmail default', (string) $emailMode);

    $tileServerUrl = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'tile_server_url'");
    is_true($tileServerUrl === 'https://tiles.example.com/{z}/{x}/{y}.png', 'FIX: tile_url correctly renamed to tile_server_url', (string) $tileServerUrl);
    $deadKey = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'map.tile_url'");
    is_true($deadKey === false || $deadKey === null, 'the old dead destination key map.tile_url is never created');
    $deadSmtpDot = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp.host'");
    is_true($deadSmtpDot === false || $deadSmtpDot === null, 'the old dead destination key smtp.host (dotted) is never created');

    // Cross-compatibility: the REAL, unmodified reader must parse what
    // THIS fix wrote -- not just this test's own expectations.
    // inc/channels/smtp.php calls broker_register() at file-load time, so
    // inc/broker.php (which defines it) must load first -- the same
    // "broker_send() callers must require inc/broker.php first" gotcha
    // this project's own CLAUDE.md documents.
    require_once $base . '/inc/broker.php';
    require_once $base . '/inc/channels/smtp.php';
    $realConfig = _smtp_get_config();
    is_true($realConfig['smtp_host'] === 'outgoing.example.com', 'CROSS-COMPAT: the real _smtp_get_config() reads back the correct host', (string) ($realConfig['smtp_host'] ?? null));
    is_true((int) $realConfig['smtp_port'] === 587, 'CROSS-COMPAT: the real _smtp_get_config() reads back the correct port');
    is_true($realConfig['smtp_encryption'] === 'tls', 'CROSS-COMPAT: the real _smtp_get_config() reads back the correct encryption mode');
    is_true($realConfig['smtp_user'] === 'dispatch@example.com', 'CROSS-COMPAT: the real _smtp_get_config() reads back the correct user');
    is_true($realConfig['smtp_pass'] === 'S3cretPass!', 'CROSS-COMPAT: the real _smtp_get_config() reads back the correct password');
    is_true($realConfig['email_mode'] === 'smtp', 'CROSS-COMPAT: the real _smtp_get_config() sees email_mode=smtp, so _smtp_send() will actually relay rather than fall back to sendmail');

    // ── Idempotency: a second run must not duplicate or corrupt ──
    $r2b = upgrade_run($php, $base . '/tools/upgrade/settings_migrate.php');
    is_true($r2b['out'] !== null, 'second run of settings_migrate.php succeeds', (string) $r2b['out']);
    $smtpHostAfter2 = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp_host'");
    is_true($smtpHostAfter2 === 'outgoing.example.com', 'idempotent: smtp_host unchanged after a second run');
    $smtpHostCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE name = 'smtp_host'");
    is_true($smtpHostCount === 1, 'idempotent: exactly one smtp_host row, not duplicated', (string) $smtpHostCount);

    // ── Never overwrite an operator-set value ──
    db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = 'smtp_host'", ['operator-set.example.com']);
    db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = 'smtp_acct'", ['different-server.example.com/25/none/other@example.com/otherpass']);
    $r2c = upgrade_run($php, $base . '/tools/upgrade/settings_migrate.php');
    is_true($r2c['out'] !== null, 'third run (operator override present) succeeds', (string) $r2c['out']);
    $smtpHostAfterOverride = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp_host'");
    is_true($smtpHostAfterOverride === 'operator-set.example.com',
        'FIX: an operator-set smtp_host is NEVER overwritten by a later smtp_acct value, even a changed one',
        (string) $smtpHostAfterOverride);

    // ── Malformed smtp_acct (too few fields) is left untouched, not guessed at ──
    db_query("DELETE FROM `{$prefix}settings` WHERE name IN ('smtp_host','smtp_port','smtp_encryption','smtp_user','smtp_pass','email_mode','smtp_acct')");
    db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES ('smtp_acct', 'onlyoneserverfield')");
    $r2d = upgrade_run($php, $base . '/tools/upgrade/settings_migrate.php');
    is_true($r2d['out'] !== null && strpos($r2d['out'], '[warn]') !== false,
        'a malformed (single-field) smtp_acct is reported as a warning, not silently guessed at', (string) $r2d['out']);
    $noHost = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'smtp_host'");
    is_true($noHost === false || $noHost === null, 'malformed smtp_acct produces NO smtp_host row rather than a garbage partial one');

} catch (Throwable $e) {
    bad('settings_migrate fixture/assertion path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. smoke_test.php (pure read + self-cleaning write, real dev DB) --\n";
// ─────────────────────────────────────────────────────────────────────
$r3 = upgrade_run($php, $base . '/tools/upgrade/smoke_test.php');
is_true($r3['out'] !== null, 'smoke_test.php ran', (string) $r3['out']);
is_true($r3['out'] !== null && strpos($r3['out'], 'SMOKE: PASS') !== false,
    'smoke_test.php passes against this dev DB (a real, already-migrated NewUI install)', (string) $r3['out']);
is_true($r3['out'] !== null && strpos($r3['out'], '[fail]') === false,
    'smoke_test.php reports zero individual failures', (string) $r3['out']);
// Confirm the self-cleaning behavior actually happened (not just claimed).
$leftoverAudit = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE category = 'upgrade' AND activity = 'smoke_test'"
);
is_true($leftoverAudit === 0, 'smoke_test.php cleans up its own audit_log probe row, leaving none behind', (string) $leftoverAudit);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. postcheck.php surfaces the corrected settings keys --\n";
// ─────────────────────────────────────────────────────────────────────
// Re-seed a clean, valid smtp_acct so this section observes real output.
db_query("DELETE FROM `{$prefix}settings` WHERE name IN ('smtp_host','smtp_port','smtp_encryption','smtp_user','smtp_pass','email_mode','smtp_acct')");
db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES ('smtp_acct', ?)", [$legacySmtpAcct]);
upgrade_run($php, $base . '/tools/upgrade/settings_migrate.php');

$r4 = upgrade_run($php, $base . '/tools/upgrade/postcheck.php', ['--json']);
is_true($r4['out'] !== null, 'postcheck.php ran', (string) $r4['out']);
$p4 = $r4['out'] !== null ? json_decode(trim($r4['out']), true) : null;
is_true(is_array($p4) && isset($p4['settings']), 'postcheck.php --json output includes a settings section', (string) $r4['out']);
if (is_array($p4)) {
    $settingsReported = $p4['settings'] ?? [];
    is_true(array_key_exists('smtp_host', $settingsReported),
        'FIX: postcheck.php now surfaces smtp_host — it used to search for the dead \'smtp.%\' prefix and would report nothing here',
        json_encode(array_keys($settingsReported)));
    is_true(($settingsReported['smtp_host'] ?? null) === 'outgoing.example.com', 'postcheck.php reports the correct smtp_host value');
    is_true(array_key_exists('smtp_pass', $settingsReported) && $settingsReported['smtp_pass'] === '(set)',
        'postcheck.php masks smtp_pass rather than printing it in the clear', json_encode($settingsReported['smtp_pass'] ?? null));
    is_true(array_key_exists('email_mode', $settingsReported) && $settingsReported['email_mode'] === 'smtp',
        'postcheck.php reports email_mode=smtp, confirming the migrated config is actually active');
}
is_true(isset($p4['counts']) && is_array($p4['counts']) && count($p4['counts']) > 0, 'postcheck.php reports table row counts');
is_true(isset($p4['schema']) && is_array($p4['schema']), 'postcheck.php reports schema-state checks');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. run.php orchestrator (static: step order + structure) --\n";
// ─────────────────────────────────────────────────────────────────────
$runSrc = (string) file_get_contents($base . '/tools/upgrade/run.php');
is_true(strpos($runSrc, "--skip-backup") !== false && strpos($runSrc, "--no-confirm") !== false,
    'run.php supports non-interactive flags for scripted/CI use');
// Single-quoted: the literal step() call-site labels, not the file's own
// top docblock prose, which mentions several of these same words (e.g.
// "runs a smoke test") long before the real step(7, ..., 'smoke test', ...)
// call -- an unquoted search matched that prose mention first and reported
// a false ordering failure.
$stepOrder = ["'preflight'", "'database backup'", "'settings translator'", "'level → role migration'", "'smoke test'", "'postcheck'"];
$lastPos = -1;
$orderedCorrectly = true;
foreach ($stepOrder as $stepName) {
    $pos = strpos($runSrc, $stepName);
    if ($pos === false || $pos < $lastPos) { $orderedCorrectly = false; break; }
    $lastPos = $pos;
}
is_true($orderedCorrectly, 'run.php\'s steps appear in the documented order: preflight, backup, settings, roles, smoke test, postcheck');
is_true(strpos($runSrc, "function_exists('backup_dump_sql')") !== false,
    'run.php falls back to the built-in PDO-based backup when mysqldump is unavailable/can\'t authenticate (a documented Windows/XAMPP gap)');
is_true(strpos($runSrc, 'ROLLBACK.md') !== false, 'run.php points at the rollback procedure on failure');

echo "\n=== {$pass} passed, {$fail} failed ===\n";

// ── Teardown: restore this dev DB's PRE-EXISTING settings exactly (not
// just delete) -- tile_server_url in particular already held a real,
// meaningful value (a live OSM tile URL) before this test ran. ──
try {
    restore_settings($prefix, $originalSnapshot);
    $verify = snapshot_settings($prefix, $touchedKeys);
    $restoredCleanly = ($verify === $originalSnapshot);
    echo $restoredCleanly
        ? "  Teardown: all touched settings restored to their pre-test state.\n"
        : "  Teardown WARNING: post-restore snapshot does not match the original -- check manually: "
            . json_encode(['before' => $originalSnapshot, 'after' => $verify]) . "\n";
} catch (Throwable $e) { echo "  Teardown warning: " . $e->getMessage() . "\n"; }

exit($fail === 0 ? 0 : 1);
