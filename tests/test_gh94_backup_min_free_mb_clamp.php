<?php
/**
 * GH#94 (2026-08-20) — backup_min_free_mb had no upper bound.
 *
 * A real install (Windows/IIS 10, v4.2.23) had settings.backup_min_free_mb
 * holding 1073741824 -- that field is in MB, and inc/backup_schedule.php's
 * backup_min_free_bytes() multiplies it by 1024*1024 to get a byte reserve.
 * 1073741824 is exactly 1024^3, "1 GB expressed in BYTES" -- an easy mistake,
 * since 1024 (the correct MB entry for a 1 GB reserve) computes to the exact
 * same number of bytes. The result was a ~1 petabyte reserve requirement that
 * no real disk could ever satisfy: every automatic backup was silently and
 * PERMANENTLY refused from that point on, discovered only because the
 * operator happened to open System Health two days later.
 *
 * backup_min_free_bytes() only ever guarded $n < 0 (negative); the Settings
 * <input max="1048576"> (settings.php) was purely decorative -- the generic
 * settings writer in api/config-admin.php type-validates exactly two keys
 * (area_timezone, page_size) and upserts everything else verbatim.
 *
 * This file proves three things, driven through the REAL shipped code:
 *
 *   (a) the REAL settings-save endpoint (api/config-admin.php?section=settings)
 *       clamps an absurd backup_min_free_mb on write. @requires-http --
 *       self-skips when no local Apache is reachable or NEWUI_TEST_NO_HTTP=1
 *       is set (matching tools/test_api_endpoints.php's convention); the
 *       read-time self-heal in (b) is the durable, always-on regression guard
 *       that runs in every environment including the fresh-install CI job.
 *
 *   (b) the REAL backup_min_free_bytes() function self-heals an ALREADY-bad
 *       value sitting in the settings table at READ time, however it got
 *       there (a bad save before this fix shipped, a hand-edited row, an old
 *       fixture) -- with no migration required, per this project's
 *       defensive-database-pattern convention. Each check spawns a FRESH
 *       subprocess to read the value back, because get_variable()
 *       (inc/functions.php) caches the WHOLE settings table in a
 *       function-static on first call per process and never invalidates it --
 *       the exact same reason tests/test_backup_schedule.php's GH#32 section
 *       already had to do this (see backupStatusFresh() there).
 *
 *   (c) backup_space_verdict() -- the pure function every refusal message on
 *       Settings/Status is rendered from -- distinguishes "the reserve
 *       exceeds this volume's total capacity" (a configuration error that NO
 *       amount of freeing space, pruning backups, or lowering the reserve a
 *       little can ever fix) from an ordinary "not enough free space right
 *       now" refusal (where those remedies genuinely help). Tested as a pure
 *       function -- no disk, no DB -- the same technique
 *       tests/test_backup_guard.php already established for this function's
 *       other boundary cases (exactly-at-the-floor, free-space-unreadable).
 *       This is the only way to exercise the config-error branch on THIS dev
 *       machine: its real disk (~3.7 TB total) is bigger than the new
 *       BACKUP_MAX_MIN_FREE_MB ceiling (1 TiB), so no value of
 *       backup_min_free_mb can exceed real total capacity here any more --
 *       which is the fix working as intended, not a gap in this test.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup_schedule.php';

$base = realpath(__DIR__ . '/..');
echo "=== GH#94 — backup_min_free_mb upper bound + config-error message ===\n\n";
$pass = 0; $fail = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }

$MB = 1024 * 1024;
$GB = 1024 * $MB;
$TB = 1024 * $GB;

// ── (c) PURE function: backup_space_verdict() tells the two refusal shapes
//     apart. No filesystem, no DB, no clock — reachable everywhere. ────────

// The exact real-world shape: the reserve alone is bigger than the volume.
$v = backup_space_verdict(300 * $GB, 2 * $MB, 1 * $TB, 500 * $GB);
(!$v['ok'] && !empty($v['config_error']))
    ? ok('reserve exceeding total capacity is flagged config_error')
    : bad('config_error flag', json_encode($v));
(stripos($v['reason'], 'configuration error') !== false
    && stripos($v['reason'], "exceeds this volume's total capacity") !== false)
    ? ok('config-error message names it a configuration error, not a space shortage')
    : bad('config-error wording', $v['reason']);
(stripos($v['reason'], 'free some space') === false)
    ? ok('config-error message does NOT suggest freeing space (that could never fix it)')
    : bad('should not suggest freeing space', $v['reason']);
(stripos($v['reason'], 'never be satisfied') !== false || stripos($v['reason'], 'can never be satisfied') !== false)
    ? ok('config-error message says the condition can never be satisfied by the usual remedies')
    : bad('config-error should explain why the remedies are futile', $v['reason']);

// An ordinary refusal — tight on free space right now, but the reserve
// itself is well within total capacity — must keep the ORIGINAL wording and
// remedies (freeing space genuinely can help here).
$v2 = backup_space_verdict(500 * $MB, 2 * $MB, 1 * $GB, 500 * $GB);
(!$v2['ok'] && empty($v2['config_error']))
    ? ok('an ordinary low-free-space refusal is NOT flagged config_error')
    : bad('ordinary refusal should not be config_error', json_encode($v2));
(stripos($v2['reason'], 'free some space') !== false)
    ? ok('ordinary refusal keeps the "free some space" remedy (it can actually help here)')
    : bad('ordinary refusal wording regressed', $v2['reason']);

// Reserve exceeds total capacity, but total capacity is UNDETERMINABLE (NULL)
// — must never guess; falls back to the ordinary message.
$v3 = backup_space_verdict(300 * $GB, 2 * $MB, 1 * $TB, null);
(!$v3['ok'] && empty($v3['config_error']))
    ? ok('unknown total capacity falls back to the ordinary message (never guesses config_error)')
    : bad('unknown total should not claim config_error', json_encode($v3));

// BOUNDARY: reserve exactly EQUALS total capacity — that is "cannot fit
// anything at all", not "exceeds", and stays an ordinary refusal (>= is
// deliberate elsewhere in this function for the same reason: the floor is a
// remain-requirement, not a must-exceed one — config_error is reserved for
// the reserve being LARGER than what physically exists).
$v4 = backup_space_verdict(1 * $GB, 0, 500 * $GB, 500 * $GB);
(!$v4['ok'] && empty($v4['config_error']))
    ? ok('reserve exactly equal to total capacity is NOT flagged config_error (only "exceeds" is)')
    : bad('boundary: equal-to-total', json_encode($v4));

// A satisfiable request must still pass regardless of the new parameter.
$v5 = backup_space_verdict(10 * $GB, 1 * $GB, 1 * $GB, 500 * $GB);
($v5['ok'] === true)
    ? ok('ample free space still proceeds with the new totalBytes parameter present')
    : bad('ample space regressed', json_encode($v5));

// ── (b) READ-TIME self-heal: backup_min_free_bytes() clamps a value already
//     sitting in the settings table, however it got there. Each read runs in
//     a FRESH subprocess (get_variable() caches per-process — see file
//     docblock), matching test_backup_schedule.php's established technique.
// ─────────────────────────────────────────────────────────────────────────
function freshMinFreeBytes(string $base): ?int {
    $script = rtrim(sys_get_temp_dir(), '/\\') . '/tcad_gh94_minfree_' . getmypid() . '_' . mt_rand() . '.php';
    file_put_contents($script, "<?php\nrequire " . var_export("$base/config.php", true) . ";\n"
        . "require_once " . var_export("$base/inc/backup_schedule.php", true) . ";\n"
        . "echo backup_min_free_bytes();\n");
    $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    exec($cmd . ' 2>&1', $lines, $exit);
    @unlink($script);
    if ($exit !== 0) return null;
    $out = trim(implode("\n", $lines));
    return ctype_digit($out) ? (int) $out : null;
}

$savedFloorMb = backup_setting('backup_min_free_mb', (string) BACKUP_DEFAULT_MIN_FREE_MB);
try {
    // The reporter's exact real-world number: a byte figure in an MB field.
    backup_setting_set('backup_min_free_mb', '1073741824');
    $bytes = freshMinFreeBytes($base);
    ($bytes === BACKUP_MAX_MIN_FREE_MB * $MB)
        ? ok('GH#94 repro value (1073741824 MB, ~1 PB) is clamped to the sane ceiling on read')
        : bad('GH#94 repro clamp', 'got ' . json_encode($bytes) . ', expected ' . (BACKUP_MAX_MIN_FREE_MB * $MB));
    ($bytes !== null && $bytes < 2 * $TB)
        ? ok('the clamped reserve is nowhere near petabyte scale any more')
        : bad('clamped reserve still absurd', json_encode($bytes));

    // One MB over the ceiling — still clamped down to exactly the ceiling.
    backup_setting_set('backup_min_free_mb', (string) (BACKUP_MAX_MIN_FREE_MB + 1));
    (freshMinFreeBytes($base) === BACKUP_MAX_MIN_FREE_MB * $MB)
        ? ok('one MB over the ceiling is clamped down to the ceiling')
        : bad('one-over-ceiling clamp failed');

    // Exactly AT the ceiling — must pass through unclamped (no off-by-one).
    backup_setting_set('backup_min_free_mb', (string) BACKUP_MAX_MIN_FREE_MB);
    (freshMinFreeBytes($base) === BACKUP_MAX_MIN_FREE_MB * $MB)
        ? ok('a value exactly at the ceiling is honoured, not further reduced')
        : bad('at-ceiling value was incorrectly clamped');

    // A sane, legitimate value must be completely unaffected.
    backup_setting_set('backup_min_free_mb', '2048');
    (freshMinFreeBytes($base) === 2048 * $MB)
        ? ok('an ordinary reserve (2048 MB) passes through unclamped')
        : bad('ordinary value was incorrectly altered');

    // Negative/garbage still falls back to the documented default — this is
    // PRE-EXISTING behaviour and must not have regressed.
    backup_setting_set('backup_min_free_mb', '-5');
    (freshMinFreeBytes($base) === BACKUP_DEFAULT_MIN_FREE_MB * $MB)
        ? ok('a negative value still falls back to the default (pre-existing behaviour intact)')
        : bad('negative fallback regressed');
} finally {
    backup_setting_set('backup_min_free_mb', $savedFloorMb !== '' ? $savedFloorMb : (string) BACKUP_DEFAULT_MIN_FREE_MB);
}

// ── (a) WRITE-TIME clamp through the REAL settings-save endpoint ──────────
$noHttp = getenv('NEWUI_TEST_NO_HTTP') === '1';
if ($noHttp) {
    echo "SKIP: NEWUI_TEST_NO_HTTP=1 — endpoint check skipped (the read-time\n"
       . "      self-heal above is the durable, always-on regression guard)\n";
} else {
    $apiBase    = 'http://localhost/newui';
    $cookieFile = tempnam(sys_get_temp_dir(), 'gh94cookie');
    $reachable  = false;
    try {
        // Login — mirrors tools/test_api_endpoints.php's established helper
        // (same local dev credentials that helper already uses successfully).
        $ch = curl_init("$apiBase/login.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_TIMEOUT => 5]);
        $html = curl_exec($ch);
        $reachable = $html !== false;
        curl_close($ch);

        if (!$reachable) {
            echo "SKIP: http://localhost/newui/ not reachable — endpoint check skipped\n";
        } else {
            preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string) $html, $m);
            $csrf = $m[1] ?? '';

            $ch = curl_init("$apiBase/login.php");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['username' => 'admin', 'password' => 'testing', 'csrf_token' => $csrf]),
                CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5,
            ]);
            $loginResp = curl_exec($ch);
            $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($loginCode === 200 && strpos((string) $loginResp, 'tfa_verify') !== false) {
                require_once __DIR__ . '/../inc/tfa.php';
                require_once __DIR__ . '/../inc/totp.php';
                $adminRow = db_fetch_one("SELECT id FROM " . db_table('user') . " WHERE user = 'admin'");
                $adminId  = $adminRow ? (int) $adminRow['id'] : 1;
                $tfaRow   = null;
                try {
                    $tfaRow = db_fetch_one("SELECT `secret_encrypted` FROM " . db_table('user_tfa')
                        . " WHERE `user_id` = ? AND `confirmed` = 1", [$adminId]);
                } catch (Exception $e) { /* no TFA row — plain login */ }
                $tfaCode = null;
                if ($tfaRow && !empty($tfaRow['secret_encrypted'])) {
                    $secret = tfa_decrypt($tfaRow['secret_encrypted']);
                    if ($secret) $tfaCode = totp_get_code($secret);
                }
                if ($tfaCode) {
                    preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string) $loginResp, $cm);
                    $tfaCsrf = $cm[1] ?? $csrf;
                    $ch = curl_init("$apiBase/login.php");
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query(['tfa_verify' => '1', 'tfa_code' => $tfaCode, 'csrf_token' => $tfaCsrf]),
                        CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile,
                        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }

            // Pull a fresh CSRF token off any authenticated page's window.CSRF_TOKEN
            // (inc/navbar.php emits it on every authenticated page load).
            $ch = curl_init("$apiBase/settings.php");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_TIMEOUT => 5]);
            $settingsHtml = curl_exec($ch);
            curl_close($ch);
            preg_match('/window\.CSRF_TOKEN\s*=\s*"([^"]*)"/', (string) $settingsHtml, $tm);
            $apiCsrf = $tm[1] ?? '';

            if ($apiCsrf === '') {
                echo "SKIP: could not obtain an authenticated CSRF token (login may have failed) — endpoint check skipped\n";
            } else {
                // Remember what was really configured, so this test never
                // leaves the live setting worse than it found it.
                $beforeSave = backup_setting('backup_min_free_mb', (string) BACKUP_DEFAULT_MIN_FREE_MB);

                // POST the GH#94 repro value through the REAL endpoint.
                $ch = curl_init("$apiBase/api/config-admin.php?section=settings");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['csrf_token' => $apiCsrf,
                        'settings' => ['backup_min_free_mb' => '1073741824']]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_TIMEOUT => 10,
                ]);
                $saveResp = curl_exec($ch);
                $saveCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                ($saveCode === 200)
                    ? ok('the real settings endpoint accepts the save (clamps rather than hard-rejecting the whole batch)')
                    : bad('settings save HTTP code', "got $saveCode, body: $saveResp");

                // GET it back through the SAME real endpoint — must read back
                // clamped, never the raw absurd value.
                $ch = curl_init("$apiBase/api/config-admin.php?section=settings");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
                    CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_TIMEOUT => 10]);
                $getResp = curl_exec($ch);
                curl_close($ch);
                $decoded = json_decode((string) $getResp, true);
                $stored  = $decoded['settings']['backup_min_free_mb'] ?? null;

                ($stored !== null && (int) $stored <= BACKUP_MAX_MIN_FREE_MB)
                    ? ok("the real endpoint's own GET reflects the clamped value (stored='" . $stored . "')")
                    : bad('endpoint GET should reflect the clamp', 'stored=' . json_encode($stored));
                ($stored !== null && (int) $stored !== 1073741824)
                    ? ok('the raw GH#94 repro value (1073741824) was never actually persisted')
                    : bad('raw absurd value was saved verbatim — write-time clamp did not run', json_encode($stored));

                // Restore whatever was really configured before this test ran.
                $ch = curl_init("$apiBase/api/config-admin.php?section=settings");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['csrf_token' => $apiCsrf,
                        'settings' => ['backup_min_free_mb' => $beforeSave]]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_TIMEOUT => 10,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }
    } finally {
        if (file_exists($cookieFile)) @unlink($cookieFile);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
