<?php
/**
 * NewUI v4.0 — Login Security Module
 *
 * Login attempt tracking and account lockout.
 * Prevents brute-force attacks by temporarily locking accounts
 * after too many failed login attempts.
 *
 * USAGE:
 *   require_once __DIR__ . '/login-security.php';
 *
 *   // Before password check:
 *   if (ls_is_locked($username)) {
 *       $remaining = ls_get_lockout_remaining($username);
 *       // show lockout message
 *   }
 *
 *   // After failed login:
 *   ls_record_attempt($username, false, $_SERVER['REMOTE_ADDR']);
 *
 *   // After successful login:
 *   ls_record_attempt($username, true, $_SERVER['REMOTE_ADDR']);
 *   ls_clear_attempts($username);
 */

/**
 * Ensure the login_attempts table exists.
 * Safe to call multiple times.
 */
function ls_ensure_table(): bool {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}login_attempts` (
            `id`             BIGINT AUTO_INCREMENT PRIMARY KEY,
            `username`       VARCHAR(255) NOT NULL,
            `ip_address`     VARCHAR(45)  DEFAULT NULL,
            `user_agent`     VARCHAR(512) DEFAULT NULL,
            `success`        TINYINT(1)   NOT NULL DEFAULT 0,
            `failure_reason`  VARCHAR(64)  DEFAULT NULL,
            `cleared_at`     DATETIME     DEFAULT NULL COMMENT 'Set when lockout counter is reset; NULL = still counts toward lockout',
            `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_username_created` (`username`, `created_at`),
            KEY `idx_ip_created` (`ip_address`, `created_at`),
            KEY `idx_cleared` (`cleared_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Add cleared_at column if table already existed without it
        try {
            $cols = db_fetch_all(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = '{$prefix}login_attempts'
                   AND COLUMN_NAME = 'cleared_at'"
            );
            if (empty($cols)) {
                db_query("ALTER TABLE `{$prefix}login_attempts`
                    ADD COLUMN `cleared_at` DATETIME DEFAULT NULL
                    COMMENT 'Set when lockout counter is reset; NULL = still counts toward lockout'
                    AFTER `failure_reason`");
            }
        } catch (Exception $e2) {
            // Non-fatal
        }

        return true;
    } catch (Exception $e) {
        error_log('Failed to create login_attempts table: ' . $e->getMessage());
        return false;
    }
}

/**
 * Read lockout settings from the settings/config table.
 *
 * @return array ['max_attempts' => int, 'window_minutes' => int, 'lockout_minutes' => int]
 */
function ls_get_settings(): array {
    return [
        'max_attempts'    => (int) (get_setting('lockout_max_attempts', 5) ?: 5),
        'window_minutes'  => (int) (get_setting('lockout_window_minutes', 15) ?: 15),
        'lockout_minutes' => (int) (get_setting('lockout_duration_minutes', 30) ?: 30),
    ];
}

/**
 * Record a login attempt.
 *
 * @param string      $username       Username that was tried
 * @param bool        $success        Whether login succeeded
 * @param string|null $ip             Client IP address
 * @param string|null $failureReason  Reason for failure (wrong_password, account_locked, 2fa_failed, account_disabled)
 * @return bool
 */
function ls_record_attempt(string $username, bool $success, ?string $ip = null, ?string $failureReason = null): bool {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;

        db_query(
            "INSERT INTO `{$prefix}login_attempts` (`username`, `ip_address`, `user_agent`, `success`, `failure_reason`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$username, $ip, $ua, $success ? 1 : 0, $failureReason, date('Y-m-d H:i:s')]
        );
        return true;
    } catch (Exception $e) {
        // Table may not exist yet; try creating it
        if (ls_ensure_table()) {
            try {
                $prefix = $GLOBALS['db_prefix'] ?? '';
                $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
                db_query(
                    "INSERT INTO `{$prefix}login_attempts` (`username`, `ip_address`, `user_agent`, `success`, `failure_reason`, `created_at`)
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    [$username, $ip, $ua, $success ? 1 : 0, $failureReason]
                );
                return true;
            } catch (Exception $e2) {
                error_log('ls_record_attempt retry failed: ' . $e2->getMessage());
            }
        }
        return false;
    }
}

/**
 * Check if a username is currently locked out.
 *
 * @param string $username
 * @return bool True if account is locked
 */
function ls_is_locked(string $username): bool {
    try {
        $settings = ls_get_settings();
        $prefix = $GLOBALS['db_prefix'] ?? '';

        $windowStart = date('Y-m-d H:i:s', time() - ($settings['window_minutes'] * 60));

        // Count only un-cleared failed attempts (cleared_at IS NULL means still active)
        // Falls back gracefully if cleared_at column doesn't exist yet
        try {
            $failCount = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}login_attempts`
                 WHERE `username` = ?
                   AND `success` = 0
                   AND `cleared_at` IS NULL
                   AND `created_at` > ?",
                [$username, $windowStart]
            );
        } catch (Exception $e) {
            // cleared_at column may not exist — fall back to original query
            $failCount = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}login_attempts`
                 WHERE `username` = ?
                   AND `success` = 0
                   AND `created_at` > ?",
                [$username, $windowStart]
            );
        }

        if ($failCount < $settings['max_attempts']) {
            return false;
        }

        // Check if the lockout period has passed since the Nth failure
        $lastFail = db_fetch_value(
            "SELECT `created_at` FROM `{$prefix}login_attempts`
             WHERE `username` = ? AND `success` = 0
             ORDER BY `created_at` DESC LIMIT 1",
            [$username]
        );

        if (!$lastFail) {
            return false;
        }

        $lockExpires = strtotime($lastFail) + ($settings['lockout_minutes'] * 60);
        return time() < $lockExpires;
    } catch (Exception $e) {
        // If table doesn't exist, not locked
        return false;
    }
}

/**
 * Get seconds remaining until lockout expires.
 *
 * @param string $username
 * @return int Seconds remaining, 0 if not locked
 */
function ls_get_lockout_remaining(string $username): int {
    try {
        $settings = ls_get_settings();
        $prefix = $GLOBALS['db_prefix'] ?? '';

        $lastFail = db_fetch_value(
            "SELECT `created_at` FROM `{$prefix}login_attempts`
             WHERE `username` = ? AND `success` = 0
             ORDER BY `created_at` DESC LIMIT 1",
            [$username]
        );

        if (!$lastFail) {
            return 0;
        }

        $lockExpires = strtotime($lastFail) + ($settings['lockout_minutes'] * 60);
        $remaining = $lockExpires - time();
        return $remaining > 0 ? $remaining : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Clear failed attempts for a username (call after successful login).
 *
 * @param string $username
 * @return bool
 */
/**
 * Mark failed attempts as cleared (for lockout counter reset) without deleting them.
 * Previously this DELETEd failed attempts, which destroyed audit trail.
 * Now we keep them but set a `cleared_at` timestamp so the lockout counter ignores them.
 * Falls back to DELETE if the `cleared_at` column doesn't exist yet.
 */
function ls_clear_attempts(string $username): bool {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        // Try the non-destructive approach first (soft-clear)
        db_query(
            "UPDATE `{$prefix}login_attempts`
             SET `cleared_at` = NOW()
             WHERE `username` = ? AND `success` = 0 AND `cleared_at` IS NULL",
            [$username]
        );
        return true;
    } catch (Exception $e) {
        // Column may not exist yet — fall back to legacy DELETE behavior
        try {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            db_query(
                "DELETE FROM `{$prefix}login_attempts`
                 WHERE `username` = ? AND `success` = 0",
                [$username]
            );
            return true;
        } catch (Exception $e2) {
            return false;
        }
    }
}

/**
 * Get recent login attempts for a username.
 *
 * @param string $username
 * @param int    $minutes  Lookback window (default 60)
 * @return array
 */
function ls_get_recent_attempts(string $username, int $minutes = 60): array {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        return db_fetch_all(
            "SELECT `id`, `username`, `ip_address`, `user_agent`, `success`, `failure_reason`, `created_at`
             FROM `{$prefix}login_attempts`
             WHERE `username` = ?
               AND `created_at` > DATE_SUB(NOW(), INTERVAL ? MINUTE)
             ORDER BY `created_at` DESC",
            [$username, $minutes]
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get recent failed attempts count for display on login page.
 *
 * @param string $username
 * @return int Number of recent failures
 */
function ls_get_recent_failure_count(string $username): int {
    try {
        $settings = ls_get_settings();
        $prefix = $GLOBALS['db_prefix'] ?? '';

        // Count only un-cleared failures for lockout evaluation
        try {
            return (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}login_attempts`
                 WHERE `username` = ?
                   AND `success` = 0
                   AND `cleared_at` IS NULL
                   AND `created_at` > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$username, $settings['window_minutes']]
            );
        } catch (Exception $e) {
            // cleared_at column may not exist yet
            return (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}login_attempts`
                 WHERE `username` = ?
                   AND `success` = 0
                   AND `created_at` > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$username, $settings['window_minutes']]
            );
        }
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Get all recent login attempts (all users) for admin display.
 *
 * @param int $limit Max rows to return
 * @return array
 */
function ls_get_all_recent(int $limit = 50): array {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        return db_fetch_all(
            "SELECT `id`, `username`, `ip_address`, `success`, `failure_reason`, `created_at`
             FROM `{$prefix}login_attempts`
             ORDER BY `created_at` DESC
             LIMIT ?",
            [$limit]
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Count failed attempts from a specific IP in a time window.
 *
 * @param string $ip
 * @param int    $minutes Lookback window
 * @return int
 */
function ls_ip_failure_count(string $ip, int $minutes = 15): int {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}login_attempts`
             WHERE `ip_address` = ?
               AND `success` = 0
               AND `created_at` > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$ip, $minutes]
        );
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Check if an IP is in a CIDR range.
 *
 * @param string $ip    IP address to check
 * @param string $cidr  CIDR notation (e.g., "192.168.1.0/24")
 * @return bool
 */
function ls_ip_in_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }

    list($subnet, $bits) = explode('/', $cidr, 2);
    $bits = (int) $bits;

    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);

    if ($ipLong === false || $subnetLong === false) {
        return false;
    }

    $mask = -1 << (32 - $bits);
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * Check if an IP is in a list of trusted networks.
 *
 * @param string $ip
 * @param array  $cidrs Array of CIDR strings
 * @return bool
 */
function ls_is_trusted_ip(string $ip, array $cidrs): bool {
    foreach ($cidrs as $cidr) {
        if (ls_ip_in_cidr($ip, trim($cidr))) {
            return true;
        }
    }
    return false;
}

/**
 * Log a high-severity audit entry when lockout triggers.
 *
 * @param string $username
 * @param string $ip
 * @return void
 */
function ls_alert_admin(string $username, string $ip): void {
    if (function_exists('audit_log')) {
        audit_log(
            'auth',
            'lockout',
            'user',
            null,
            "Account locked: '{$username}' from IP {$ip} after too many failed attempts",
            [
                'username'   => $username,
                'ip_address' => $ip,
                'settings'   => ls_get_settings(),
            ],
            AUDIT_CRITICAL
        );
    }
}

/**
 * Cleanup old login attempts (older than 90 days).
 *
 * @return int Number of rows deleted
 */
function ls_cleanup_old(int $days = 90): int {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $stmt = db_query(
            "DELETE FROM `{$prefix}login_attempts`
             WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}
