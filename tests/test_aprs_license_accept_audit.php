<?php
/**
 * api/aprs-license-accept.php must write its legal-trail audit row.
 *
 * WHAT WAS WRONG (Phase 155, found by another builder who could not fix it in
 * their own slice)
 *
 *   The endpoint records an administrator's attestation that they hold a current
 *   FCC amateur licence, and its docblock says "audit_log captures the event with
 *   the user's IP for legal-trail purposes". It never did, for two independent
 *   reasons that each produced the same silence:
 *
 *     1. The call was audit_log('settings|aprs|license_attestation', "<summary>",
 *        [details]) -- three arguments in the shape of a different, older logger.
 *        The real signature is audit_log($category, $activity, $targetType,
 *        $targetId, $summary, $details, ...), so the array landed in the
 *        `?string $targetType` slot: a TypeError. TypeError extends Error, so the
 *        function's own `catch (Exception)` cannot see it; the endpoint's outer
 *        `catch (Throwable)` turned it into HTTP 500 "Acceptance failed" -- AFTER
 *        the two settings rows had been written. The admin saw an error, the
 *        attestation was nevertheless stored, and no audit row existed.
 *     2. The call sat behind `if (function_exists('audit_log'))`, and the file
 *        never loaded inc/audit.php (api/auth.php does not either), so on this
 *        request path the guard was false and the call was skipped without a
 *        sound. Fixing the arguments alone would have left the row unwritten.
 *
 *   A test that checks "the endpoint answered 200" proves neither. This drives the
 *   REAL endpoint and then asks the audit table whether the row is there.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function la_ok(string $what, bool $cond, string $why = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] {$what}\n"; }
    else       { $fail++; echo "[FAIL] {$what}" . ($why !== '' ? " -- {$why}" : '') . "\n"; }
}

echo "=== api/aprs-license-accept.php writes its audit row ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $adminId = test_admin_user_id();
    $have = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log`");
} catch (Throwable $e) {
    echo "SKIP: database not reachable or audit table missing: " . $e->getMessage() . "\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// Snapshot the two settings rows so the run leaves the install as it found it.
$names = ['aprs_license_attestation_accepted_at', 'aprs_license_attestation_accepted_by'];
$saved = [];
foreach ($names as $n) {
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$n]);
    $saved[$n] = $row === null || $row === false ? null : (string) $row['value'];
}
$restore = static function () use ($names, &$saved, $prefix): void {
    foreach ($names as $n) {
        if ($saved[$n] === null) {
            db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$n]);
        } else {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
                      ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$n, $saved[$n]]);
        }
    }
};
test_fixture_guard_track_cleanup($restore, 'restore aprs attestation settings');
// The probe's session user name is 'p155-probe'; real attestations carry a real name.
test_fixture_guard_track_where('newui_audit_log',
    "target_type = 'aprs_license_attestation' AND user_name = 'p155-probe'");

$clearSettings = static function () use ($names, $prefix): void {
    foreach ($names as $n) db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$n]);
};
$auditRows = static function () use ($prefix): array {
    return db_fetch_all(
        "SELECT * FROM `{$prefix}newui_audit_log`
          WHERE target_type = 'aprs_license_attestation' AND user_name = 'p155-probe'
          ORDER BY id");
};
$settingNow = static function (string $n) use ($prefix): ?string {
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$n]);
    return $row ? (string) $row['value'] : null;
};

db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE target_type = 'aprs_license_attestation' AND user_name = 'p155-probe'");

// ── 1. An accepted attestation: 200, settings written, audit row present ───────
$clearSettings();
$r = p155_probe('session', 'api/aprs-license-accept.php', $adminId, [], 'POST');
la_ok('the endpoint answers 200 with ok:true', $r['http'] === 200 && !empty($r['json']['ok']),
      'http=' . $r['http'] . ' body=' . substr($r['raw'], 0, 300));
la_ok('the acceptance time is stored', $settingNow($names[0]) !== null && $settingNow($names[0]) !== '');
la_ok('the accepting user is stored', $settingNow($names[1]) === 'p155-probe', var_export($settingNow($names[1]), true));

$rows = $auditRows();
la_ok('exactly one legal-trail audit row was written through the real endpoint', count($rows) === 1,
      'found ' . count($rows) . ' row(s)');
if (count($rows) === 1) {
    $a = $rows[0];
    la_ok('the row names the acting user', (int) $a['user_id'] === $adminId, 'user_id=' . $a['user_id']);
    la_ok('the row is in the config category with a real activity', $a['category'] === 'config' && $a['activity'] === 'accept',
          $a['category'] . '/' . $a['activity']);
    la_ok('the row is HIGH severity (it is a legal attestation, not routine traffic)', (int) $a['severity'] === 4, 'severity=' . $a['severity']);
    la_ok('the summary says who attested', strpos((string) $a['summary'], 'p155-probe') !== false, (string) $a['summary']);
    la_ok('the row carries the source address', (string) $a['ip_address'] !== '');
    $d = json_decode((string) $a['details'], true);
    la_ok('the details payload is structured JSON with the user and the acceptance time',
          is_array($d) && (int) ($d['user_id'] ?? 0) === $adminId && !empty($d['accepted_at']) && array_key_exists('user_agent', $d),
          (string) $a['details']);
}

// ── 2. A second acceptance is a second event, not a silent overwrite ────────────
$r2 = p155_probe('session', 'api/aprs-license-accept.php', $adminId, [], 'POST');
la_ok('a re-acceptance answers 200', $r2['http'] === 200 && !empty($r2['json']['ok']), $r2['raw']);
la_ok('a re-acceptance adds a second audit row', count($auditRows()) === 2, 'rows=' . count($auditRows()));

// ── 3. A refused request writes nothing at all ──────────────────────────────────
$clearSettings();
db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE target_type = 'aprs_license_attestation' AND user_name = 'p155-probe'");
$bad = p155_probe('session', 'api/aprs-license-accept.php', $adminId, ['csrf_token' => 'not-the-token'], 'POST');
la_ok('a wrong CSRF token is refused with 403', $bad['http'] === 403, 'http=' . $bad['http'] . ' ' . $bad['raw']);
la_ok('a refused request stores no attestation', $settingNow($names[0]) === null && $settingNow($names[1]) === null);
la_ok('a refused request writes no audit row', count($auditRows()) === 0);

$get = p155_probe('session', 'api/aprs-license-accept.php', $adminId, [], 'GET');
la_ok('a GET is refused with 405 and stores nothing', $get['http'] === 405 && $settingNow($names[0]) === null, 'http=' . $get['http']);

// ── 4. The silent-disable smell must stay out of the file ───────────────────────
$src = (string) file_get_contents(__DIR__ . '/../api/aprs-license-accept.php');
$code = '';
foreach (token_get_all($src) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $code .= is_array($t) ? $t[1] : $t;
}
la_ok('the file loads inc/audit.php itself instead of hoping something else did',
      (bool) preg_match('~require_once\s+__DIR__\s*\.\s*\'/\.\./inc/audit\.php\'~', $code));
la_ok('the audit call is not hidden behind function_exists()', !preg_match('~function_exists\s*\(\s*\'audit_log\'~', $code));

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
