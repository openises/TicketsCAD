<?php
/**
 * Migration: `login_attempts.cleared_at` column (QA hardening, 2026-07-07).
 *
 * sql/login_security.sql creates login_attempts WITHOUT cleared_at (the
 * soft-clear audit-trail column added later); the guarded ALTER lives in
 * inc/login-security.php's ls_ensure_table(), which only ran when an
 * INSERT failed — i.e. never on a fresh install, because the base table
 * exists. Result: virgin installs silently lacked cleared_at, so
 * ls_clear_attempts() hard-DELETE fallback kicked in and the login audit
 * trail was NOT preserved (soft-clear regressed to delete) until the
 * first failure path happened to trigger the ensure.
 *
 * Provisioning now invokes the canonical ensure function directly, so
 * there is exactly one copy of the DDL (inc/login-security.php). Exits
 * non-zero if the column still doesn't exist afterwards.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/login-security.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

if (function_exists('ls_ensure_table')) {
    ls_ensure_table();
}

try {
    $col = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'cleared_at'",
        [$prefix . 'login_attempts']
    );
    if ((int) $col > 0) {
        echo "[OK] login_attempts.cleared_at ready\n";
    } else {
        echo "[FAIL] login_attempts.cleared_at still missing after ls_ensure_table()\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "[FAIL] cleared_at check: " . $e->getMessage() . "\n";
    exit(1);
}
