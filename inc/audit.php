<?php
/**
 * NewUI v4.0 — Audit Logging Helper
 *
 * OCSF-inspired lightweight audit logger. Every write operation in NewUI
 * should call audit_log() to create a structured audit trail.
 *
 * USAGE:
 *   require_once __DIR__ . '/audit.php';
 *
 *   // Simple: log a config change
 *   audit_log('config', 'update', 'setting', 'theme', 'Changed theme to Night');
 *
 *   // With details: log a member creation
 *   audit_log('personnel', 'create', 'member', $id, "Created member '{$name}'", [
 *       'callsign' => 'KC9ABC',
 *       'team'     => 'HF Radio Team',
 *   ]);
 *
 *   // Higher severity: log a deletion
 *   audit_log('personnel', 'delete', 'member', $id, "Deleted member '{$name}'", null, AUDIT_HIGH);
 *
 * CATEGORIES (OCSF-inspired):
 *   auth       — login, logout, session, password changes
 *   config     — system settings, display, map, API keys
 *   personnel  — members, teams, certifications, training, ICS positions
 *   incident   — tickets, assignments, status changes
 *   asset      — vehicles, equipment, checkout/checkin
 *   data       — import, export, bulk operations
 *   system     — service events, errors, maintenance
 *   comms      — Zello, messaging, alerts
 *
 * ACTIVITIES:
 *   create, update, delete, login, logout, export, import,
 *   assign, unassign, activate, deactivate, error, view
 *
 * SEVERITY LEVELS:
 *   0 = Unknown    — shouldn't be used in practice
 *   1 = Info       — routine operations (default)
 *   2 = Low        — minor changes, exports
 *   3 = Medium     — significant changes (bulk operations, role changes)
 *   4 = High       — deletions, security-relevant actions
 *   5 = Critical   — system errors, auth failures, data loss events
 */

// Severity constants
define('AUDIT_UNKNOWN',  0);
define('AUDIT_INFO',     1);
define('AUDIT_LOW',      2);
define('AUDIT_MEDIUM',   3);
define('AUDIT_HIGH',     4);
define('AUDIT_CRITICAL', 5);

// Severity labels for display
function audit_severity_label(int $level): string {
    $labels = [
        0 => 'Unknown',
        1 => 'Info',
        2 => 'Low',
        3 => 'Medium',
        4 => 'High',
        5 => 'Critical',
    ];
    return $labels[$level] ?? 'Unknown';
}

// Severity badge colors for Bootstrap
function audit_severity_color(int $level): string {
    $colors = [
        0 => 'secondary',
        1 => 'info',
        2 => 'success',
        3 => 'warning',
        4 => 'danger',
        5 => 'danger',
    ];
    return $colors[$level] ?? 'secondary';
}

/**
 * Write an audit log entry.
 *
 * @param string      $category    Event category (auth, config, personnel, incident, asset, data, system, comms)
 * @param string      $activity    What happened (create, update, delete, login, logout, export, import, etc.)
 * @param string|null $targetType  What was affected (member, team, certification, setting, etc.)
 * @param mixed       $targetId    PK of affected record (will be cast to string)
 * @param string      $summary     Human-readable summary of what happened
 * @param array|null  $details     Optional structured context (stored as JSON)
 * @param int         $severity    Severity level (use AUDIT_* constants, default AUDIT_INFO)
 * @return bool                    True on success, false on failure (never throws)
 */

/**
 * GH #8 — resolve the ticket id a fan-out payload concerns, for use as a
 * top-level `ticket_id` that recipient predicates ($payload.ticket_id) and
 * the push notification-tap URL builder can read. Extracted as a pure,
 * testable function (see tests/test_push_recipient.php) because the missing
 * top-level ticket_id is exactly why the assigned-to-incident push predicate
 * matched zero users and the mobile push never fired.
 *
 * @param string|null $targetType  audit target type ('ticket' for incidents)
 * @param mixed       $targetId    audit target id (the ticket id for tickets)
 * @param mixed       $details     audit details array (assign.* nests ticket_id)
 * @return int|null                the ticket id, or null if the event isn't
 *                                 about a ticket
 */
function audit_flatten_ticket_id($targetType, $targetId, $details): ?int
{
    if ($targetType === 'ticket') {
        return (int) $targetId;
    }
    if (is_array($details) && isset($details['ticket_id']) && (int) $details['ticket_id'] > 0) {
        return (int) $details['ticket_id'];
    }
    return null;
}

function audit_log(
    string  $category,
    string  $activity,
    ?string $targetType = null,
    $targetId = null,
    string  $summary = '',
    ?array  $details = null,
    int     $severity = AUDIT_INFO
): bool {
    try {
        // Auto-detect user from session
        $userId   = $_SESSION['user_id'] ?? null;
        $userName = $_SESSION['user'] ?? null;
        // 2026-06-11 (Phase 10c): use trusted-proxy-aware client IP so
        // audit log shows the real client address, not the proxy loopback.
        require_once __DIR__ . '/client-ip.php';
        $ip       = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Auto-set severity based on activity if not explicitly provided
        if ($severity === AUDIT_INFO) {
            $severity = _audit_default_severity($activity);
        }

        db_query(
            "INSERT INTO " . db_table('newui_audit_log') . "
             (event_time, user_id, user_name, ip_address, category, activity, severity, target_type, target_id, summary, details)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $userId,
                $userName,
                $ip,
                $category,
                $activity,
                $severity,
                $targetType,
                $targetId !== null ? (string) $targetId : null,
                $summary,
                $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ]
        );

        // Phase 94 Stage 5 (2026-06-27, a beta tester reliability fix):
        // audit-driven webhook fan-out. If the just-logged (category,
        // activity, target_type) maps to a webhook event type per the
        // explicit allowlist in inc/webhooks.php (Decision #4), fire
        // outbound webhooks. Wrapped in try/catch so any webhook
        // failure NEVER breaks the audit log's commit. The fire itself
        // is non-blocking (5s curl timeout per subscriber + retries
        // happen out-of-band via tools/webhook_retry_tick.php cron).
        try {
            require_once __DIR__ . '/webhooks.php';
            if (function_exists('_audit_to_webhook_event')) {
                $eventType = _audit_to_webhook_event($category, $activity, $targetType);
                if ($eventType !== null) {
                    $fanOutPayload = [
                        'category'    => $category,
                        'activity'    => $activity,
                        'target_type' => $targetType,
                        'target_id'   => $targetId,
                        'summary'     => $summary,
                        'details'     => $details,
                        'actor_id'    => $userId,
                        'actor_name'  => $userName,
                        'event_time'  => gmdate('Y-m-d\TH:i:s\Z'),
                    ];

                    // GH #8 (2026-07-08) — flatten a top-level ticket_id so
                    // recipient predicates ($payload.ticket_id) and the push
                    // notification-tap URL builder can resolve it. Without
                    // this, incident.created carries the ticket id only as
                    // target_id, and assign.created carries it nested under
                    // details[] — so the assigned_to_incident push predicate
                    // read null, matched zero users, and the push was silently
                    // skipped ("recipient predicate matched zero users") on
                    // EVERY dispatch. This is the real cause the mobile/PWA
                    // push never fired (survived two prior "fixes" because no
                    // test covered the audit->push->predicate integration).
                    $flatTid = audit_flatten_ticket_id($targetType, $targetId, $details);
                    if ($flatTid !== null) {
                        $fanOutPayload['ticket_id'] = $flatTid;
                    }

                    webhook_fire($eventType, $fanOutPayload);

                    // Phase 96 (2026-06-28) — parallel Web Push fan-out.
                    // Wrapped in its own try/catch so a push failure
                    // doesn't poison the webhook path or audit commit.
                    // The same event_type allowlist gate applies (we
                    // only call push_fire when webhook_fire fires).
                    try {
                        if (file_exists(__DIR__ . '/push.php')) {
                            require_once __DIR__ . '/push.php';
                            if (function_exists('push_fire')) {
                                push_fire($eventType, $fanOutPayload);
                            }
                        }
                    } catch (Throwable $pushErr) {
                        error_log('[audit_log] push fan-out failed: ' . $pushErr->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            // Webhook failure must never break the audit log path.
            // The retry cron picks up failed deliveries from
            // webhook_deliveries; the audit row itself is committed.
            error_log('[audit_log] webhook fan-out failed: ' . $e->getMessage());
        }

        return true;
    } catch (Exception $e) {
        // Logging should NEVER break the operation it's attached to.
        // Silently fail — consider writing to error_log in production.
        error_log('Audit log write failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Default severity by activity type.
 * Can be overridden by passing explicit severity to audit_log().
 */
function _audit_default_severity(string $activity): int {
    $map = [
        'create'     => AUDIT_INFO,
        'update'     => AUDIT_INFO,
        'delete'     => AUDIT_HIGH,
        'login'      => AUDIT_INFO,
        'logout'     => AUDIT_INFO,
        'export'     => AUDIT_LOW,
        'import'     => AUDIT_MEDIUM,
        'assign'     => AUDIT_INFO,
        'unassign'   => AUDIT_LOW,
        'activate'   => AUDIT_INFO,
        'deactivate' => AUDIT_MEDIUM,
        'error'      => AUDIT_HIGH,
        'view'       => AUDIT_INFO,
    ];
    return $map[$activity] ?? AUDIT_INFO;
}

/**
 * Log a data access event for sensitive fields.
 * Use when patient info, medical records, or contact data is viewed.
 *
 * @param string      $table     Table being accessed (e.g., 'ticket', 'member')
 * @param mixed       $recordId  PK of the record
 * @param array       $fields    Field names accessed (e.g., ['patient_name', 'medical_notes'])
 * @return bool
 */
function audit_data_access(string $table, $recordId, array $fields): bool {
    return audit_log(
        'data',
        'view',
        $table,
        $recordId,
        "Accessed sensitive data in {$table} #{$recordId}: " . implode(', ', $fields),
        ['fields' => $fields],
        AUDIT_MEDIUM
    );
}

/**
 * Log a login-related event.
 * Specialized wrapper for auth events: login, logout, 2fa_verify, lockout, password_change.
 *
 * @param int|null    $userId   User ID (null if user not found)
 * @param string      $username Username attempted
 * @param string      $action   Action (login, logout, 2fa_verify, lockout, password_change, login_failed, login_blocked)
 * @param string      $summary  Human-readable summary
 * @param array|null  $details  Optional extra context
 * @return bool
 */
function audit_login(?int $userId, string $username, string $action, string $summary, ?array $details = null): bool {
    // Determine severity based on action
    $severityMap = [
        'login'           => AUDIT_INFO,
        'logout'          => AUDIT_INFO,
        '2fa_verify'      => AUDIT_INFO,
        'lockout'         => AUDIT_CRITICAL,
        'password_change' => AUDIT_HIGH,
        'login_failed'    => AUDIT_MEDIUM,
        'login_blocked'   => AUDIT_HIGH,
    ];
    $severity = $severityMap[$action] ?? AUDIT_INFO;

    // Build details with IP and user-agent
    $merged = $details ?? [];
    $merged['username'] = $username;
    // 2026-06-11 (Phase 10c): trusted-proxy-aware client IP.
    require_once __DIR__ . '/client-ip.php';
    $merged['ip'] = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $merged['user_agent'] = substr($_SERVER['HTTP_USER_AGENT'], 0, 256);
    }

    // Temporarily override session for pre-login events
    $origUserId = $_SESSION['user_id'] ?? null;
    $origUser   = $_SESSION['user'] ?? null;
    if ($userId !== null) {
        $_SESSION['user_id'] = $userId;
        $_SESSION['user']    = $username;
    }

    $result = audit_log('auth', $action, 'user', $userId, $summary, $merged, $severity);

    // Restore session state
    if ($origUserId !== null) {
        $_SESSION['user_id'] = $origUserId;
        $_SESSION['user']    = $origUser;
    } elseif ($userId !== null) {
        unset($_SESSION['user_id'], $_SESSION['user']);
    }

    return $result;
}

/**
 * Log an admin action.
 * Use for config changes, user CRUD, role changes, etc.
 *
 * @param int         $userId   Admin user ID performing the action
 * @param string      $action   Action (create, update, delete, assign, config_change, role_change)
 * @param string      $target   What was affected (user, role, config, etc.)
 * @param string      $summary  Human-readable summary
 * @param array|null  $details  Optional structured context
 * @return bool
 */
function audit_admin(int $userId, string $action, string $target, string $summary, ?array $details = null): bool {
    $severityMap = [
        'create'        => AUDIT_MEDIUM,
        'update'        => AUDIT_MEDIUM,
        'delete'        => AUDIT_HIGH,
        'config_change' => AUDIT_HIGH,
        'role_change'   => AUDIT_HIGH,
        'assign'        => AUDIT_MEDIUM,
    ];
    $severity = $severityMap[$action] ?? AUDIT_MEDIUM;

    return audit_log('config', $action, $target, null, $summary, $details, $severity);
}

/**
 * Retrieve filtered audit log entries.
 * Supports filtering by category, activity, severity, user, date range.
 *
 * @param array $filters  Associative array of filters:
 *   'category'      => string   Filter by category
 *   'activity'      => string   Filter by activity
 *   'severity_min'  => int      Minimum severity (inclusive)
 *   'user_id'       => int      Filter by user ID
 *   'username'      => string   Filter by username (partial match)
 *   'target_type'   => string   Filter by target type
 *   'from'          => string   Start date (Y-m-d or Y-m-d H:i:s)
 *   'to'            => string   End date (Y-m-d or Y-m-d H:i:s)
 *   'search'        => string   Full-text search in summary
 * @param int  $limit   Max rows (default 100)
 * @param int  $offset  Offset for pagination (default 0)
 * @return array ['rows' => array, 'total' => int]
 */
function audit_get_log(array $filters = [], int $limit = 100, int $offset = 0): array {
    try {
        $where = [];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = '`category` = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['activity'])) {
            $where[] = '`activity` = ?';
            $params[] = $filters['activity'];
        }
        if (isset($filters['severity_min']) && $filters['severity_min'] !== '') {
            $where[] = '`severity` >= ?';
            $params[] = (int) $filters['severity_min'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = '`user_id` = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['username'])) {
            $where[] = '`user_name` LIKE ?';
            $params[] = '%' . $filters['username'] . '%';
        }
        if (!empty($filters['target_type'])) {
            $where[] = '`target_type` = ?';
            $params[] = $filters['target_type'];
        }
        if (!empty($filters['from'])) {
            $where[] = '`event_time` >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = '`event_time` <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '`summary` LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $table = db_table('newui_audit_log');

        // Get total count
        $total = (int) db_fetch_value(
            "SELECT COUNT(*) FROM {$table} {$whereClause}",
            $params
        );

        // Get rows
        $params[] = (int) $limit;
        $params[] = (int) $offset;
        $rows = db_fetch_all(
            "SELECT * FROM {$table} {$whereClause} ORDER BY `event_time` DESC LIMIT ? OFFSET ?",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    } catch (Exception $e) {
        error_log('audit_get_log failed: ' . $e->getMessage());
        return ['rows' => [], 'total' => 0];
    }
}

/**
 * Ensure the audit log table exists. Called once during setup.
 * Safe to call multiple times — uses CREATE TABLE IF NOT EXISTS.
 */
function audit_ensure_table(): bool {
    try {
        db_query("CREATE TABLE IF NOT EXISTS " . db_table('newui_audit_log') . " (
            id            BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_time    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id       INT          DEFAULT NULL,
            user_name     VARCHAR(64)  DEFAULT NULL,
            ip_address    VARCHAR(45)  DEFAULT NULL,
            category      VARCHAR(32)  NOT NULL,
            activity      VARCHAR(32)  NOT NULL,
            severity      TINYINT      NOT NULL DEFAULT 1,
            target_type   VARCHAR(48)  DEFAULT NULL,
            target_id     VARCHAR(64)  DEFAULT NULL,
            summary       VARCHAR(512) NOT NULL,
            details       JSON         DEFAULT NULL,
            KEY idx_event_time (event_time),
            KEY idx_category   (category),
            KEY idx_user_id    (user_id),
            KEY idx_target     (target_type, target_id),
            KEY idx_severity   (severity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Exception $e) {
        error_log('Failed to create audit log table: ' . $e->getMessage());
        return false;
    }
}
