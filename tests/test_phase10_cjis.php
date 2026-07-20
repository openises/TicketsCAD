<?php
/**
 * Phase 10 CJIS hardening — regression tests.
 *
 * Covers:
 *   - schema additions (columns + table + settings)
 *   - pw_validate length + history checks
 *   - pw_record_history INSERT + trim
 *   - pw_needs_rotation: fresh / aged / snoozed
 *   - pw_snooze writes future timestamp
 *   - pw_mark_changed updates column
 *   - api/login-security.php source: requires reason + audits it
 *   - api/profile.php source: snooze action + history wiring
 *   - api/config-admin.php source: validates via policy on save
 *   - settings.php source: Password Policy section + admin reset form
 *   - inc/navbar.php source: rotation banner
 *   - login.php source: seeds session flag
 *   - compliance-dashboard.php exists + admin-gated
 *   - api/security-compliance.php returns expected keys
 *   - sidebar has Security Compliance link
 *   - captions exist in 5 languages
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/password-policy.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 10 CJIS Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Schema ────────────────────────────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'password_changed_at'",
        [$prefix . 'user']
    );
    if ($col && stripos($col['COLUMN_TYPE'], 'datetime') !== false) {
        ok('user.password_changed_at column exists');
    } else {
        bad('user.password_changed_at column missing');
    }
} catch (Exception $e) {
    bad('password_changed_at column check', $e->getMessage());
}

try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'password_reminder_snoozed_until'",
        [$prefix . 'user']
    );
    if ($col) {
        ok('user.password_reminder_snoozed_until column exists');
    } else {
        bad('snoozed_until column missing');
    }
} catch (Exception $e) {
    bad('snoozed_until column check', $e->getMessage());
}

try {
    $tbl = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'user_password_history']
    );
    if ($tbl) {
        ok('user_password_history table exists');
    } else {
        bad('user_password_history table missing');
    }
} catch (Exception $e) {
    bad('history table check', $e->getMessage());
}

// ── Settings defaults ────────────────────────────────────────────────────
$expected = [
    'password_min_length' => '8',
    'password_history_count' => '10',
    'password_rotation_reminder_days' => '180',
    'password_rotation_snooze_days' => '10',
];
foreach ($expected as $name => $def) {
    try {
        $v = db_fetch_value(
            "SELECT value FROM `{$prefix}settings` WHERE name = ? LIMIT 1",
            [$name]
        );
        if ($v !== null) {
            ok("Setting {$name} exists (value: '{$v}')");
        } else {
            bad("Setting {$name} missing");
        }
    } catch (Exception $e) {
        bad("Setting query {$name}", $e->getMessage());
    }
}

// ── Helper functions ─────────────────────────────────────────────────────
if (pw_min_length() >= 8) {
    ok('pw_min_length() returns >= 8');
} else {
    bad('pw_min_length() < 8: ' . pw_min_length());
}
if (pw_history_count() >= 10) {
    ok('pw_history_count() returns >= 10');
} else {
    bad('pw_history_count() < 10: ' . pw_history_count());
}

// ── Validation: too short ────────────────────────────────────────────────
$v = pw_validate('ab', 0);
if (!$v['ok'] && $v['code'] === 'too_short') {
    ok('pw_validate("ab", 0) returns too_short');
} else {
    bad('pw_validate too-short check', var_export($v, true));
}

// ── Validation: long enough + complexity, no history ─────────────────────
// (Must contain a letter AND a number under the complexity policy.)
$v = pw_validate('longenough1password', 0);
if ($v['ok'] && $v['code'] === 'ok') {
    ok('pw_validate("longenough1password", 0) returns ok');
} else {
    bad('pw_validate ok check', var_export($v, true));
}

// ── Validation: complexity — letters only is too_weak ─────────────────────
$v = pw_validate('longenoughpassword', 0);
if (!$v['ok'] && $v['code'] === 'too_weak') {
    ok('pw_validate rejects letters-only password (too_weak)');
} else {
    bad('pw_validate complexity (letters-only) check', var_export($v, true));
}

// ── Validation: complexity — digits only is too_weak ──────────────────────
$v = pw_validate('1029384756', 0);
if (!$v['ok'] && $v['code'] === 'too_weak') {
    ok('pw_validate rejects digits-only password (too_weak)');
} else {
    bad('pw_validate complexity (digits-only) check', var_export($v, true));
}

// ── Validation: common-password rejection ─────────────────────────────────
$v = pw_validate('password123', 0);
if (!$v['ok'] && $v['code'] === 'too_common') {
    ok('pw_validate rejects common password (too_common)');
} else {
    bad('pw_validate common-password check', var_export($v, true));
}

// ── pw_is_common direct + case-insensitivity ──────────────────────────────
if (pw_is_common('Password123') && !pw_is_common('Zt9mK2qr7wLx')) {
    ok('pw_is_common matches case-insensitively and passes strong passwords');
} else {
    bad('pw_is_common check', var_export([pw_is_common('Password123'), pw_is_common('Zt9mK2qr7wLx')], true));
}

// ── History record + reuse detection (round trip) ────────────────────────
// Use a temporary user id high enough not to collide.
$testUid = 99001;
try {
    // Clean up any prior test data
    db_query(
        "DELETE FROM `{$prefix}user_password_history` WHERE user_id = ?",
        [$testUid]
    );
    $hashA = password_hash('TestPasswordA12!', PASSWORD_BCRYPT);
    pw_record_history($testUid, $hashA);
    // Now validate A against this history — should reject
    $v = pw_validate('TestPasswordA12!', $testUid);
    if (!$v['ok'] && $v['code'] === 'in_history') {
        ok('pw_validate rejects recently-used password (in_history)');
    } else {
        bad('pw_validate did not catch reuse', var_export($v, true));
    }
    // A different password should pass
    $v = pw_validate('TestPasswordB34!', $testUid);
    if ($v['ok']) {
        ok('pw_validate accepts new password not in history');
    } else {
        bad('pw_validate rejected new password incorrectly', var_export($v, true));
    }
    // Cleanup
    db_query(
        "DELETE FROM `{$prefix}user_password_history` WHERE user_id = ?",
        [$testUid]
    );
} catch (Exception $e) {
    bad('history round-trip', $e->getMessage());
}

// ── History trim ─────────────────────────────────────────────────────────
try {
    db_query(
        "DELETE FROM `{$prefix}user_password_history` WHERE user_id = ?",
        [$testUid]
    );
    // Insert 11 hashes (one above default count of 10)
    for ($i = 0; $i < 11; $i++) {
        $h = password_hash("HistPw{$i}!23456", PASSWORD_BCRYPT);
        pw_record_history($testUid, $h);
    }
    $count = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_password_history` WHERE user_id = ?",
        [$testUid]
    );
    if ($count === pw_history_count()) {
        ok("History trims at pw_history_count() (saw {$count} after 11 inserts)");
    } else {
        bad("History trim", "expected " . pw_history_count() . " after 11 inserts, got {$count}");
    }
    db_query(
        "DELETE FROM `{$prefix}user_password_history` WHERE user_id = ?",
        [$testUid]
    );
} catch (Exception $e) {
    bad('history trim test', $e->getMessage());
}

// ── api/login-security.php: reason field required ─────────────────────────
$lsSrc = file_get_contents($base . '/api/login-security.php');
if (strpos($lsSrc, "\$reason") !== false && strpos($lsSrc, 'reason for the reset is required') !== false) {
    ok('api/login-security.php requires reason field');
} else {
    bad('api/login-security.php does NOT require reason');
}
if (strpos($lsSrc, "'reason'         => \$reason") !== false ||
    strpos($lsSrc, "'reason' => \$reason") !== false) {
    ok('api/login-security.php audit_admin includes reason in details');
} else {
    bad('api/login-security.php does NOT log reason to audit');
}
if (strpos($lsSrc, 'pw_validate(') !== false) {
    ok('api/login-security.php validates password via policy module');
} else {
    bad('api/login-security.php does NOT call pw_validate');
}
if (strpos($lsSrc, 'pw_record_history(') !== false) {
    ok('api/login-security.php records old password to history');
} else {
    bad('api/login-security.php does NOT record to history');
}
if (strpos($lsSrc, 'must_change_password') !== false) {
    ok('api/login-security.php forces target user to change pw on next login');
} else {
    bad('api/login-security.php does NOT force change on next login');
}

// ── api/profile.php: snooze action + history wiring ──────────────────────
$pp = file_get_contents($base . '/api/profile.php');
if (strpos($pp, "snooze_password_reminder") !== false) {
    ok('api/profile.php has snooze_password_reminder action');
} else {
    bad('api/profile.php missing snooze action');
}
if (strpos($pp, 'pw_record_history(') !== false) {
    ok('api/profile.php records history on change_password');
} else {
    bad('api/profile.php does NOT record history');
}
if (strpos($pp, 'pw_mark_changed(') !== false) {
    ok('api/profile.php marks password_changed_at on change');
} else {
    bad('api/profile.php does NOT mark changed timestamp');
}

// ── api/config-admin.php: policy on user save ────────────────────────────
$ca = file_get_contents($base . '/api/config-admin.php');
if (substr_count($ca, 'pw_validate(') >= 2) {
    ok('api/config-admin.php calls pw_validate on both create and update');
} else {
    bad('api/config-admin.php missing policy check on user save');
}

// ── settings.php: Password Policy section + admin reset form ─────────────
$st = file_get_contents($base . '/settings.php');
if (strpos($st, 'pw_policy.section_title') !== false) {
    ok('settings.php has Password Policy section');
} else {
    bad('settings.php missing Password Policy section');
}
if (strpos($st, 'data-key="password_min_length"') !== false) {
    ok('settings.php has password_min_length input');
} else {
    bad('settings.php missing password_min_length input');
}
if (strpos($st, 'id="adminResetReason"') !== false) {
    ok('settings.php has admin reset Reason field');
} else {
    bad('settings.php missing admin reset Reason field');
}

// ── inc/navbar.php: rotation banner ──────────────────────────────────────
$nv = file_get_contents($base . '/inc/navbar.php');
if (strpos($nv, "rotation_reminder_age") !== false &&
    strpos($nv, "btnSnoozePwReminder") !== false) {
    ok('inc/navbar.php has rotation banner with snooze button');
} else {
    bad('inc/navbar.php missing rotation banner');
}

// ── login.php seeds session flag ─────────────────────────────────────────
$lg = file_get_contents($base . '/login.php');
if (strpos($lg, 'pw_needs_rotation') !== false &&
    strpos($lg, "rotation_reminder_age") !== false) {
    ok('login.php seeds rotation_reminder_age session flag');
} else {
    bad('login.php does NOT seed rotation reminder');
}

// ── compliance-dashboard.php exists + admin gate ─────────────────────────
$cd = $base . '/compliance-dashboard.php';
if (file_exists($cd)) {
    ok('compliance-dashboard.php exists');
    $cdSrc = file_get_contents($cd);
    if (strpos($cdSrc, "rbac_can('action.manage_config')") !== false &&
        strpos($cdSrc, "admin_required") !== false) {
        ok('compliance-dashboard.php has admin gate');
    } else {
        bad('compliance-dashboard.php missing admin gate');
    }
} else {
    bad('compliance-dashboard.php does not exist');
}

// ── api/security-compliance.php returns expected keys ────────────────────
$sc = $base . '/api/security-compliance.php';
if (file_exists($sc)) {
    ok('api/security-compliance.php exists');
    $scSrc = file_get_contents($sc);
    foreach (['password_policy', 'account_lockout', 'two_factor_auth',
              'session_management', 'auth_activity_7d', 'audit_log_health',
              'rotation', 'cjis_expected'] as $key) {
        if (strpos($scSrc, "'{$key}'") !== false) {
            ok("api/security-compliance.php returns {$key}");
        } else {
            bad("api/security-compliance.php missing {$key} in response");
        }
    }
} else {
    bad('api/security-compliance.php missing');
}

// ── sidebar has Security Compliance link ────────────────────────────────
$sb = file_get_contents($base . '/inc/config-sidebar.php');
if (strpos($sb, 'compliance-dashboard.php') !== false) {
    ok('config-sidebar.php has Security Compliance link');
} else {
    bad('config-sidebar.php missing Compliance link');
}

// ── Caption seeds (spot-check 5 languages) ──────────────────────────────
foreach (['en','de','nl','fr','es'] as $lang) {
    try {
        $v = db_fetch_value(
            "SELECT value FROM `{$prefix}captions_i18n` WHERE caption_key=? AND lang=?",
            ['pw_policy.warn_min', $lang]
        );
        if ($v) {
            ok("Caption pw_policy.warn_min [{$lang}] present");
        } else {
            bad("Caption pw_policy.warn_min [{$lang}] missing");
        }
    } catch (Exception $e) {
        bad("Caption [{$lang}]", $e->getMessage());
    }
}

// ── docs/SECURITY-POLICY.md exists ──────────────────────────────────────
if (file_exists($base . '/docs/SECURITY-POLICY.md')) {
    ok('docs/SECURITY-POLICY.md exists');
    $sp = file_get_contents($base . '/docs/SECURITY-POLICY.md');
    if (strpos($sp, 'CJIS Security Policy v6.0') !== false &&
        strpos($sp, 'Phase 10') !== false) {
        ok('docs/SECURITY-POLICY.md mentions CJIS v6.0 + Phase 10');
    } else {
        bad('docs/SECURITY-POLICY.md missing expected references');
    }
} else {
    bad('docs/SECURITY-POLICY.md missing');
}

// ── Migration runner ───────────────────────────────────────────────────
$mig = $base . '/sql/run_phase10_cjis.php';
if (file_exists($mig)) {
    ok('sql/run_phase10_cjis.php exists');
    $migSrc = file_get_contents($mig);
    if (strpos($migSrc, 'information_schema') !== false &&
        strpos($migSrc, 'INSERT IGNORE') !== false) {
        ok('Phase 10 migration is idempotent');
    } else {
        bad('Phase 10 migration missing idempotency guards');
    }
} else {
    bad('Phase 10 migration missing');
}

echo "\n";
echo "===========================================\n";
echo "Phase 10 CJIS: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
