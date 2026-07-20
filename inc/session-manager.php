<?php
/**
 * NewUI v4.0 — Session Manager
 *
 * Tracks active sessions in the database for visibility and forced logout.
 * Provides session timeout enforcement and multi-session management.
 *
 * USAGE:
 *   require_once __DIR__ . '/session-manager.php';
 *
 *   // After successful login:
 *   sm_create_session($userId);
 *
 *   // On each authenticated request:
 *   sm_update_activity();
 *
 *   // Admin: view active sessions
 *   $sessions = sm_get_user_sessions($userId);
 *
 *   // Admin: force logout
 *   sm_destroy_session($sessionId);
 *
 *   // User: log out all other sessions
 *   sm_destroy_all_except_current($userId);
 */

/**
 * Ensure the active_sessions table exists.
 * Safe to call multiple times.
 */
function sm_ensure_table(): bool {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}active_sessions` (
            `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
            `user_id`     INT          NOT NULL,
            `session_id`  VARCHAR(128) NOT NULL,
            `ip_address`  VARCHAR(45)  DEFAULT NULL,
            `user_agent`  VARCHAR(512) DEFAULT NULL,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_active` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at`  DATETIME     NOT NULL,
            UNIQUE KEY `uk_session_id` (`session_id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Exception $e) {
        error_log('Failed to create active_sessions table: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get the configured session timeout in seconds for a user.
 *
 * Phase 37: if $userId is given and any of their roles has a non-null
 * session_timeout_minutes, the *shortest* role timeout wins (safer for
 * mixed-role users — e.g. someone with both Admin and Guest roles
 * inherits the Admin timeout, not the more permissive Guest one).
 * Falls back to the global setting.
 *
 * @return int Timeout in seconds (default 8 hours = 28800)
 */
function sm_get_timeout(?int $userId = null): int {
    $minutes = null;
    if ($userId !== null && $userId > 0) {
        try {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            $minutes = db_fetch_value(
                "SELECT MIN(r.session_timeout_minutes)
                   FROM `{$prefix}user_roles` ur
                   JOIN `{$prefix}roles` r ON r.id = ur.role_id
                  WHERE ur.user_id = ?
                    AND r.session_timeout_minutes IS NOT NULL
                    AND r.session_timeout_minutes > 0",
                [$userId]
            );
        } catch (Exception $e) {
            // Column / table not present yet — fall through to global setting
        }
    }
    if (!$minutes) {
        $minutes = (int) (get_setting('session_timeout_minutes', 480) ?: 480);
    }
    return ((int) $minutes) * 60;
}

/**
 * Create a session record after successful login.
 *
 * @param int $userId
 * @return bool
 */
function sm_create_session(int $userId): bool {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $sessionId = session_id();
        // 2026-06-11 (Phase 10c): trusted-proxy-aware client IP.
        require_once __DIR__ . '/client-ip.php';
        $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
        $timeout = sm_get_timeout($userId);
        $expiresAt = date('Y-m-d H:i:s', time() + $timeout);

        // Upsert in case session ID already exists (e.g., after regenerate)
        db_query(
            "INSERT INTO `{$prefix}active_sessions`
             (`user_id`, `session_id`, `ip_address`, `user_agent`, `created_at`, `last_active`, `expires_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `user_id` = VALUES(`user_id`),
                `ip_address` = VALUES(`ip_address`),
                `user_agent` = VALUES(`user_agent`),
                `last_active` = VALUES(`last_active`),
                `expires_at` = VALUES(`expires_at`)",
            [$userId, $sessionId, $ip, $ua, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $expiresAt]
        );
        // Phase 73aa — mark session as tracked. Once this is set,
        // sm_is_session_valid treats "row missing" as forced-destroy
        // rather than "fresh session not yet created". Without this
        // sm_destroy_session()/sm_destroy_all_for_user() didn't
        // invalidate live cookies.
        $_SESSION['_sm_tracked'] = 1;
        return true;
    } catch (Exception $e) {
        // Table may not exist; try creating it and retry
        if (sm_ensure_table()) {
            try {
                $prefix = $GLOBALS['db_prefix'] ?? '';
                $sessionId = session_id();
                // 2026-06-11 (Phase 10c): trusted-proxy-aware client IP.
        require_once __DIR__ . '/client-ip.php';
        $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
                $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
                $timeout = sm_get_timeout($userId);
                $expiresAt = date('Y-m-d H:i:s', time() + $timeout);

                db_query(
                    "INSERT INTO `{$prefix}active_sessions`
                     (`user_id`, `session_id`, `ip_address`, `user_agent`, `created_at`, `last_active`, `expires_at`)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        `user_id` = VALUES(`user_id`),
                        `ip_address` = VALUES(`ip_address`),
                        `user_agent` = VALUES(`user_agent`),
                        `last_active` = VALUES(`last_active`),
                        `expires_at` = VALUES(`expires_at`)",
                    [$userId, $sessionId, $ip, $ua, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $expiresAt]
                );
                $_SESSION['_sm_tracked'] = 1;
                return true;
            } catch (Exception $e2) {
                error_log('sm_create_session retry failed: ' . $e2->getMessage());
            }
        }
        return false;
    }
}

/**
 * Update the last_active timestamp and extend expiration for the current session.
 * Call on each authenticated request.
 *
 * @return bool
 */
function sm_update_activity(?string $sid = null): bool {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $sessionId = $sid ?? session_id();
        if (empty($sessionId)) return false;

        // Phase 37: look up this session's owning user so per-role timeout applies.
        $uid = null;
        try {
            $uid = db_fetch_value(
                "SELECT user_id FROM `{$prefix}active_sessions` WHERE session_id = ?",
                [$sessionId]
            );
        } catch (Exception $e) { /* fall through */ }

        $timeout = sm_get_timeout($uid ? (int) $uid : null);
        $expiresAt = date('Y-m-d H:i:s', time() + $timeout);

        $stmt = db_query(
            "UPDATE `{$prefix}active_sessions`
             SET `last_active` = ?, `expires_at` = ?
             WHERE `session_id` = ?",
            [date('Y-m-d H:i:s'), $expiresAt, $sessionId]
        );
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        // Silently fail — activity tracking is non-critical
        return false;
    }
}

/**
 * Get all active sessions for a user.
 *
 * @param int $userId
 * @return array
 */
function sm_get_user_sessions(int $userId): array {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        return db_fetch_all(
            "SELECT `id`, `session_id`, `ip_address`, `user_agent`, `created_at`, `last_active`, `expires_at`
             FROM `{$prefix}active_sessions`
             WHERE `user_id` = ? AND `expires_at` > '" . date('Y-m-d H:i:s') . "'
             ORDER BY `last_active` DESC",
            [$userId]
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get all active sessions across all users (admin view).
 *
 * @param int $limit
 * @return array
 */
function sm_get_all_sessions(int $limit = 100): array {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        return db_fetch_all(
            "SELECT s.`id`, s.`user_id`, s.`session_id`, s.`ip_address`, s.`user_agent`,
                    s.`created_at`, s.`last_active`, s.`expires_at`,
                    u.`user` AS `username`
             FROM `{$prefix}active_sessions` s
             LEFT JOIN `{$prefix}user` u ON s.`user_id` = u.`id`
             WHERE s.`expires_at` > '" . date('Y-m-d H:i:s') . "'
             ORDER BY s.`last_active` DESC
             LIMIT ?",
            [$limit]
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Destroy a specific session by session_id.
 *
 * @param string $sessionId PHP session ID
 * @return bool
 */
function sm_destroy_session(string $sessionId): bool {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $stmt = db_query(
            "DELETE FROM `{$prefix}active_sessions` WHERE `session_id` = ?",
            [$sessionId]
        );
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Destroy all sessions for a user except the current one.
 *
 * @param int $userId
 * @return int Number of sessions destroyed
 */
function sm_destroy_all_except_current(int $userId): int {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $currentSessionId = session_id();

        $stmt = db_query(
            "DELETE FROM `{$prefix}active_sessions`
             WHERE `user_id` = ? AND `session_id` != ?",
            [$userId, $currentSessionId]
        );
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Destroy all sessions for a user (used for forced global logout).
 *
 * @param int $userId
 * @return int Number of sessions destroyed
 */
function sm_destroy_all_for_user(int $userId): int {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $stmt = db_query(
            "DELETE FROM `{$prefix}active_sessions` WHERE `user_id` = ?",
            [$userId]
        );
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Remove expired sessions from the database.
 * Called periodically (e.g., on login, on admin page load).
 *
 * @return int Number of expired sessions cleaned up
 */
function sm_cleanup_expired(): int {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $stmt = db_query(
            "DELETE FROM `{$prefix}active_sessions` WHERE `expires_at` <= '" . date('Y-m-d H:i:s') . "'"
        );
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Check if the current session is still valid (not expired, not force-destroyed).
 *
 * @return bool True if session is valid
 */
function sm_is_session_valid(?string $sid = null): bool {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $sessionId = $sid ?? session_id();
        if (empty($sessionId)) return false;

        $row = db_fetch_one(
            "SELECT `expires_at` FROM `{$prefix}active_sessions`
             WHERE `session_id` = ? LIMIT 1",
            [$sessionId]
        );

        // Phase 73aa — CRITICAL: previously returned true when no row
        // matched ("session record not found — may not have been created
        // yet, allow it"). That assumption was load-bearing in two
        // dangerous ways:
        //   1. sm_destroy_session() does `DELETE FROM active_sessions
        //      WHERE session_id = ?`, then the next request would see
        //      "no row" and return true — force-logout never invalidated
        //      a live cookie.
        //   2. sm_destroy_all_for_user() (admin force-logout, password-
        //      reset cascade) had the same problem.
        //
        // New discipline: once $_SESSION carries a marker that the
        // active_sessions row was ever created (sm_create_session sets
        // $_SESSION['_sm_tracked']=1), an absent row is INVALID — the
        // session was explicitly destroyed. Fresh sessions without the
        // marker still get the benefit of the doubt to avoid locking
        // out users mid-create.
        if (!$row) {
            if (!empty($_SESSION['_sm_tracked'])) {
                return false;  // row deleted = forcibly destroyed
            }
            return true;       // session not yet tracked — allow create-flow
        }

        return strtotime($row['expires_at']) > time();
    } catch (Exception $e) {
        // If the active_sessions table genuinely doesn't exist (legacy
        // install pre-CJIS-hardening), assume valid. Once the table is
        // present and the marker is set on the session, missing rows
        // are treated as forced-destroy via the marker check above.
        return true;
    }
}

/**
 * Count active sessions for a user.
 *
 * @param int $userId
 * @return int
 */
function sm_count_sessions(int $userId): int {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}active_sessions`
             WHERE `user_id` = ? AND `expires_at` > '" . date('Y-m-d H:i:s') . "'",
            [$userId]
        );
    } catch (Exception $e) {
        return 0;
    }
}
