<?php
/**
 * Phase 153 (2026-09-08) — AllStar relay tests.
 *
 * Covers the PURE logic (message building, WAV stats parsing, settings
 * round-trip) and static wiring guards. Deliberately does NOT exercise
 * the live SSH/AMI path (allstar_relay_trigger(), allstar_relay_deliver(),
 * the espeak-ng fallback) -- those need a real reachable relay VM
 * (10.0.0.10) that no CI runner or fresh install has. That path was
 * verified LIVE, by hand, against the real infrastructure built for this
 * phase: a real incident's message was synthesized, delivered, played
 * back, and the recording fetched back and measured (19.68s, RMS 0.088 --
 * genuinely audible, not silence). See specs/phase-153-webrtc-telephony-
 * allstar/tasks.md for that run's full detail.
 *
 * Usage: php tests/test_allstar_relay.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/rbac.php';
require_once 'inc/incident-write.php';
require_once 'inc/allstar-relay.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 153 -- AllStar relay ===\n\n";

// ── allstar_relay_build_message() ────────────────────────────────────
$user = db_fetch_one("SELECT id FROM `{$prefix}user` ORDER BY id LIMIT 1");
$inType = db_fetch_one("SELECT id, type FROM `{$prefix}in_types` LIMIT 1");
$created = incident_create_internal([
    'scope' => 'Test relay message incident',
    'in_types_id' => (int) $inType['id'],
    'street' => '100 Test St',
    'city' => 'Testville',
    'state' => 'MN',
    'description' => 'unit test description',
    'severity' => 2,
], (int) $user['id']);
$ticketId = (int) ($created['id'] ?? 0);
register_shutdown_function(function () use ($ticketId, $prefix) {
    if ($ticketId > 0) { try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]); } catch (Throwable $e) {} }
});
t('a test incident was created for this run', $ticketId > 0);

$msg = allstar_relay_build_message($ticketId);
t('build_message() includes the incident number or id', strpos($msg, (string) ($created['incident_number'] ?: $ticketId)) !== false);
t('build_message() includes the incident type', strpos($msg, (string) $inType['type']) !== false);
t('build_message() includes the location', strpos($msg, '100 Test St') !== false && strpos($msg, 'Testville') !== false);
t('build_message() includes the scope/description', strpos($msg, 'Test relay message incident') !== false);
t('build_message() ends with a clear call to action', strpos($msg, 'respond') !== false);

$missingThrew = false;
try { allstar_relay_build_message(999999999); }
catch (RuntimeException $e) { $missingThrew = true; }
t('build_message() throws for a nonexistent incident', $missingThrew);

// ── allstar_relay_wav_stats() -- pure parsing, no network ────────────
function make_test_wav(array $samples, int $rate = 8000): string {
    $data = '';
    foreach ($samples as $s) { $data .= pack('v', $s < 0 ? $s + 65536 : $s); }
    $dataSize = strlen($data);
    $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
        . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1) . pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16)
        . 'data' . pack('V', $dataSize);
    return $header . $data;
}

$silentWav = tempnam(sys_get_temp_dir(), 'allstar_test_') . '.wav';
file_put_contents($silentWav, make_test_wav(array_fill(0, 8000, 0))); // 1s of silence
$silentStats = allstar_relay_wav_stats($silentWav);
t('wav_stats() reads a 1-second silent WAV as 1.0s duration', abs($silentStats['duration_sec'] - 1.0) < 0.01);
t('wav_stats() reads a silent WAV as RMS ~0', $silentStats['rms'] < 0.001);
@unlink($silentWav);

$toneSamples = [];
for ($i = 0; $i < 8000; $i++) { $toneSamples[] = (int) round(16000 * sin(2 * M_PI * 440 * $i / 8000)); }
$toneWav = tempnam(sys_get_temp_dir(), 'allstar_test_') . '.wav';
file_put_contents($toneWav, make_test_wav($toneSamples));
$toneStats = allstar_relay_wav_stats($toneWav);
t('wav_stats() reads a 1-second 440Hz tone as 1.0s duration', abs($toneStats['duration_sec'] - 1.0) < 0.01);
t('wav_stats() reads a real tone as genuinely non-silent (RMS > 0.3)', $toneStats['rms'] > 0.3);
@unlink($toneWav);

$garbageWav = tempnam(sys_get_temp_dir(), 'allstar_test_');
file_put_contents($garbageWav, 'not a wav file');
$garbageStats = allstar_relay_wav_stats($garbageWav);
t('wav_stats() fails safely (ok=false) on a non-WAV file, not a fatal error', $garbageStats['ok'] === false);
@unlink($garbageWav);

// ── Settings round-trip ───────────────────────────────────────────────
// get_variable() caches the whole settings table for the life of the PHP
// process with no invalidation hook (this project's own documented
// Phase 151 lesson) -- verify the WRITE via a direct, uncached SQL read,
// not through allstar_relay_settings() again in this same process.
function _raw_setting_ar($name) {
    global $prefix;
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    return $row ? $row['value'] : null;
}
$origSshAlias = _raw_setting_ar('allstar_relay_ssh_alias');
$origExtension = _raw_setting_ar('allstar_relay_extension');
allstar_relay_settings_save(['ssh_alias' => 'test-alias-xyz', 'extension' => '999']);
t('settings_save() persists ssh_alias', _raw_setting_ar('allstar_relay_ssh_alias') === 'test-alias-xyz');
t('settings_save() persists extension', _raw_setting_ar('allstar_relay_extension') === '999');
// Restore the ORIGINAL state exactly. On a fresh install neither row exists (orig === null), and
// saving '' back through the validator throws (an extension must be 1-32 characters), so a null
// original is restored by deleting the row, never by writing an empty value.
foreach (['allstar_relay_ssh_alias' => $origSshAlias, 'allstar_relay_extension' => $origExtension] as $__n => $__v) {
    if ($__v === null) {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$__n]);
    } else {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = ?", [(string) $__v, $__n]);
    }
}

// Phase 155 (GH#108 S5): the host, SSH alias and AMI user used to default to the maintainer's
// lab ("allstar-mock", 10.0.0.10, "ticketscad-relay"); there are no such defaults now
// (tests/test_allstar_relay_honesty.php proves it against a database with no stored rows).
$defaults = allstar_relay_settings();
t('settings() still has sane defaults for the port and the dialplan extension', $defaults['ami_port'] > 0 && $defaults['extension'] !== '');

// ── Static wiring guards ─────────────────────────────────────────────
$api = (string) @file_get_contents('api/allstar-relay.php');
t('api/allstar-relay.php: trigger is gated on action.dispatch_unit',
    strpos($api, "rbac_can('action.dispatch_unit')") !== false);
// Phase 155 (GH#108 S5): install-wide settings that name servers and hold a secret need
// action.manage_config (Super Admin), not is_admin() (which an Org Admin can satisfy).
t('api/allstar-relay.php: configuration needs action.manage_config',
    strpos($api, "rbac_can('action.manage_config')") !== false && substr_count($api, 'ar_require_config_perm();') >= 3);
t('api/allstar-relay.php: trigger is CSRF-checked', strpos($api, 'ar_csrf_check($input)') !== false);
t('api/allstar-relay.php: every mutation is audited', substr_count($api, 'audit_log(') >= 2);
t('inc/allstar-relay.php: a blank or placeholder secret never overwrites the stored one',
    strpos((string) @file_get_contents('inc/allstar-relay.php'), "is_masked_secret_value(\$in[\$k])") !== false);

$page = (string) @file_get_contents('incident-detail.php');
t('incident-detail.php: the relay test button is gated on action.dispatch_unit AND the enabled switch',
    strpos($page, "rbac_can('action.dispatch_unit') && allstar_relay_enabled()") !== false
    && strpos($page, 'btnAllstarRelay') !== false);

$js = (string) @file_get_contents('assets/js/incident-detail.js');
t('incident-detail.js: initAllstarRelay() is wired into init()', strpos($js, 'initAllstarRelay(id)') !== false);
t('incident-detail.js: relay button disables itself during the call (no double-fire)',
    strpos($js, 'btn.disabled = true') !== false);
t('incident-detail.js: ES5 only (no arrows/template literals/let/const) in the new function',
    !preg_match('/function initAllstarRelay.*?\n    \}/s', $js, $m) || !preg_match('/=>|`|\\blet\\s|\\bconst\\s/', $m[0]));

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
