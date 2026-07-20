<?php
/**
 * NewUI v4.0 — Notification Delivery Engine
 *
 * Evaluates notification rules against events and delivers messages
 * through the message broker (email, SMS, local chat).
 *
 * USAGE:
 *   require_once __DIR__ . '/notification-engine.php';
 *
 *   // After creating an incident:
 *   notification_check('incident_create', [
 *       'ticket_id' => $ticket_id,
 *       'scope'     => $scope,
 *       'severity'  => $severity,
 *       'in_types_id' => $in_types_id,
 *       'street'    => $street,
 *       'city'      => $city,
 *   ]);
 *
 *   // After status change:
 *   notification_check('incident_status', [
 *       'ticket_id'  => $ticket_id,
 *       'scope'      => $scope,
 *       'severity'   => $severity,
 *       'old_status' => 2,
 *       'new_status' => 1,
 *   ]);
 *
 *   // After unit assignment:
 *   notification_check('unit_assign', [
 *       'ticket_id'    => $ticket_id,
 *       'scope'        => $scope,
 *       'responder_id' => $resp_id,
 *   ]);
 */

// Load broker if not already loaded
if (!function_exists('broker_send')) {
    require_once __DIR__ . '/broker.php';
}

/**
 * Check notification rules for an event and dispatch matching notifications.
 *
 * @param string $event_type  One of: incident_create, incident_close, incident_status,
 *                            unit_assign, unit_clear, severity_high, has_broadcast
 * @param array  $context     Event-specific data (ticket_id, scope, severity, etc.)
 * @return array  Summary of notifications sent
 */
function notification_check($event_type, array $context = []) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $results = [];

    // Ensure tables exist (graceful degradation)
    try {
        $rules = db_fetch_all(
            "SELECT * FROM `{$prefix}notification_rules`
             WHERE `active` = 1 AND `event_type` = ?
             ORDER BY `id`",
            [$event_type]
        );
    } catch (\Exception $e) {
        // Table probably doesn't exist yet — run migration silently
        _notification_ensure_tables();
        return $results;
    }

    if (empty($rules)) {
        return $results;
    }

    foreach ($rules as $rule) {
        // Check severity filter
        if ($rule['severity_filter'] !== null && isset($context['severity'])) {
            if ((int) $rule['severity_filter'] !== (int) $context['severity']) {
                continue;
            }
        }

        // Check incident type filter
        if ($rule['incident_type_filter'] !== null && isset($context['in_types_id'])) {
            if ((int) $rule['incident_type_filter'] !== (int) $context['in_types_id']) {
                continue;
            }
        }

        // Build message from templates
        $subject = _notification_render_template($rule['subject_template'] ?: _notification_default_subject($event_type), $context);
        $body    = _notification_render_template($rule['body_template'] ?: _notification_default_body($event_type), $context);

        // Determine recipients
        $recipients = _notification_resolve_recipients($rule);

        // Determine channels to use
        $channels = [];
        if ($rule['channel'] === 'all') {
            $channels = ['email', 'sms', 'local_chat'];
        } else {
            $channels = [$rule['channel']];
        }

        // Send to each recipient on each channel
        foreach ($recipients as $recipient) {
            // Check user notification preferences (if we have a user_id)
            $prefs = null;
            if (isset($recipient['user_id'])) {
                $prefs = _notification_get_user_prefs((int) $recipient['user_id']);
            }

            foreach ($channels as $channel) {
                // Respect user preferences
                if ($prefs !== null) {
                    if ($channel === 'email' && !$prefs['channel_email']) continue;
                    if ($channel === 'sms' && !$prefs['channel_sms']) continue;
                    if ($channel === 'local_chat' && !$prefs['channel_chat']) continue;

                    // Check quiet hours
                    if (_notification_in_quiet_hours($prefs)) continue;
                }

                $message = [
                    'to'       => $recipient['address'] ?? $recipient['user_id'] ?? 'all',
                    'subject'  => $subject,
                    'body'     => $body,
                    'type'     => 'notification',
                    'priority' => (isset($context['severity']) && (int) $context['severity'] >= 2) ? 'high' : 'normal',
                ];

                $sendResult = broker_send($channel, $message);

                // Log the notification
                _notification_log($rule['id'], $event_type, $context['ticket_id'] ?? null, $channel,
                    $recipient['address'] ?? ('user:' . ($recipient['user_id'] ?? 'unknown')),
                    $subject, $body, $sendResult['success'] ? 'sent' : 'failed',
                    $sendResult['error'] ?? null
                );

                $results[] = [
                    'rule_id'   => $rule['id'],
                    'channel'   => $channel,
                    'recipient' => $recipient['address'] ?? $recipient['user_id'] ?? null,
                    'success'   => $sendResult['success'] ?? false,
                    'error'     => $sendResult['error'] ?? null,
                ];
            }
        }
    }

    return $results;
}

/**
 * Resolve recipients from a notification rule.
 * Returns an array of ['user_id' => int, 'address' => string].
 */
function _notification_resolve_recipients(array $rule) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $recipients = [];

    // Parse JSON recipients list
    $recipientList = [];
    if (!empty($rule['recipients'])) {
        $recipientList = json_decode($rule['recipients'], true);
        if (!is_array($recipientList)) {
            $recipientList = [];
        }
    }

    foreach ($recipientList as $entry) {
        if (is_numeric($entry)) {
            // User ID — look up email/phone
            try {
                $user = db_fetch_one(
                    "SELECT `id`, `email`, `cell` FROM `{$prefix}user` WHERE `id` = ?",
                    [(int) $entry]
                );
                if ($user) {
                    $recipients[] = [
                        'user_id' => (int) $user['id'],
                        'address' => $user['email'] ?? '',
                        'phone'   => $user['cell'] ?? '',
                    ];
                }
            } catch (\Exception $e) {
                // Skip this recipient
            }
        } elseif (filter_var($entry, FILTER_VALIDATE_EMAIL)) {
            // Direct email address
            $recipients[] = ['address' => $entry];
        } elseif (preg_match('/^\+?[\d\-\s()]+$/', $entry)) {
            // Phone number
            $recipients[] = ['address' => preg_replace('/[^\d+]/', '', $entry), 'phone' => $entry];
        }
    }

    // Email list recipients
    if (!empty($rule['email_list_id'])) {
        try {
            $listMembers = db_fetch_all(
                "SELECT `email` FROM `{$prefix}email_list_members` WHERE `list_id` = ?",
                [(int) $rule['email_list_id']]
            );
            foreach ($listMembers as $m) {
                if (!empty($m['email'])) {
                    $recipients[] = ['address' => $m['email']];
                }
            }
        } catch (\Exception $e) {
            // Email lists table may not exist
        }
    }

    return $recipients;
}

/**
 * Get a user's notification preferences.
 */
function _notification_get_user_prefs($userId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}notification_preferences` WHERE `user_id` = ?",
            [$userId]
        );
        if ($row) {
            return $row;
        }
    } catch (\Exception $e) {
        // Table may not exist
    }

    // Defaults: email + chat on, SMS off
    return [
        'channel_email' => 1,
        'channel_sms'   => 0,
        'channel_chat'  => 1,
        'quiet_start'   => null,
        'quiet_end'     => null,
    ];
}

/**
 * Check if the current time is within a user's quiet hours.
 */
function _notification_in_quiet_hours(array $prefs) {
    if (empty($prefs['quiet_start']) || empty($prefs['quiet_end'])) {
        return false;
    }

    $now   = date('H:i:s');
    $start = $prefs['quiet_start'];
    $end   = $prefs['quiet_end'];

    // Handle overnight ranges (e.g. 22:00 - 07:00)
    if ($start > $end) {
        return ($now >= $start || $now <= $end);
    }

    return ($now >= $start && $now <= $end);
}

/**
 * Render a template string with {placeholder} substitution.
 */
function _notification_render_template($template, array $context) {
    if (empty($template)) return '';

    $replacements = [
        '{ticket_id}'      => $context['ticket_id'] ?? '',
        '{scope}'          => $context['scope'] ?? '',
        '{severity}'       => $context['severity'] ?? '',
        '{severity_label}' => _notification_severity_label($context['severity'] ?? 0),
        '{incident_type}'  => $context['incident_type'] ?? '',
        '{street}'         => $context['street'] ?? '',
        '{city}'           => $context['city'] ?? '',
        '{address}'        => trim(($context['street'] ?? '') . ' ' . ($context['city'] ?? '')),
        '{old_status}'     => $context['old_status_label'] ?? ($context['old_status'] ?? ''),
        '{new_status}'     => $context['new_status_label'] ?? ($context['new_status'] ?? ''),
        '{responder}'      => $context['responder_name'] ?? ($context['responder_id'] ?? ''),
        '{user}'           => $_SESSION['user'] ?? 'System',
        '{time}'           => date('H:i:s'),
        '{date}'           => date('Y-m-d'),
        '{datetime}'       => date('Y-m-d H:i:s'),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

/**
 * Default subject line for an event type.
 */
function _notification_default_subject($event_type) {
    $subjects = [
        'incident_create'  => '[Tickets CAD] New Incident #{ticket_id}: {scope}',
        'incident_close'   => '[Tickets CAD] Incident #{ticket_id} Closed: {scope}',
        'incident_status'  => '[Tickets CAD] Incident #{ticket_id} Status Change: {scope}',
        'unit_assign'      => '[Tickets CAD] Unit Assigned to #{ticket_id}: {scope}',
        'unit_clear'       => '[Tickets CAD] Unit Cleared from #{ticket_id}: {scope}',
        'severity_high'    => '[Tickets CAD] HIGH SEVERITY #{ticket_id}: {scope}',
        'has_broadcast'    => '[Tickets CAD] HAS Broadcast Alert',
    ];
    return $subjects[$event_type] ?? '[Tickets CAD] Notification';
}

/**
 * Default body text for an event type.
 */
function _notification_default_body($event_type) {
    $bodies = [
        'incident_create'  => "New incident created:\n\nIncident: #{ticket_id}\nScope: {scope}\nSeverity: {severity_label}\nAddress: {address}\nTime: {datetime}\nCreated by: {user}",
        'incident_close'   => "Incident closed:\n\nIncident: #{ticket_id}\nScope: {scope}\nClosed at: {datetime}\nClosed by: {user}",
        'incident_status'  => "Incident status changed:\n\nIncident: #{ticket_id}\nScope: {scope}\nOld Status: {old_status}\nNew Status: {new_status}\nChanged at: {datetime}\nChanged by: {user}",
        'unit_assign'      => "Unit assigned:\n\nIncident: #{ticket_id}\nScope: {scope}\nUnit: {responder}\nAssigned at: {datetime}\nAssigned by: {user}",
        'unit_clear'       => "Unit cleared:\n\nIncident: #{ticket_id}\nScope: {scope}\nUnit: {responder}\nCleared at: {datetime}",
        'severity_high'    => "HIGH SEVERITY INCIDENT:\n\nIncident: #{ticket_id}\nScope: {scope}\nSeverity: {severity_label}\nAddress: {address}\nTime: {datetime}",
        'has_broadcast'    => "HAS Broadcast Alert\n\nTime: {datetime}\nIssued by: {user}",
    ];
    return $bodies[$event_type] ?? "Notification from Tickets CAD\n\nTime: {datetime}";
}

/**
 * Get a human-readable severity label.
 */
function _notification_severity_label($severity) {
    $labels = [0 => 'Normal', 1 => 'Medium', 2 => 'High'];
    return $labels[(int) $severity] ?? 'Unknown';
}

/**
 * Log a notification delivery to the notification_log table.
 */
function _notification_log($ruleId, $eventType, $ticketId, $channel, $recipient, $subject, $body, $status, $error = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}notification_log`
             (`rule_id`, `event_type`, `ticket_id`, `channel`, `recipient`, `subject`, `body`, `status`, `error`, `sent_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$ruleId, $eventType, $ticketId, $channel, $recipient, $subject, $body, $status, $error]
        );
    } catch (\Exception $e) {
        // Non-fatal — notification was still attempted
        error_log('Notification log failed: ' . $e->getMessage());
    }
}

/**
 * Ensure notification tables exist (idempotent migration).
 */
function _notification_ensure_tables() {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $sqls = [
        "CREATE TABLE IF NOT EXISTS `{$prefix}notification_rules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL DEFAULT '',
            `event_type` VARCHAR(50) NOT NULL,
            `severity_filter` TINYINT DEFAULT NULL,
            `incident_type_filter` INT UNSIGNED DEFAULT NULL,
            `channel` VARCHAR(20) NOT NULL DEFAULT 'email',
            `recipients` TEXT,
            `email_list_id` INT UNSIGNED DEFAULT NULL,
            `subject_template` VARCHAR(255) DEFAULT '',
            `body_template` TEXT,
            `active` TINYINT NOT NULL DEFAULT 1,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$prefix}notification_preferences` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `channel_email` TINYINT NOT NULL DEFAULT 1,
            `channel_sms` TINYINT NOT NULL DEFAULT 0,
            `channel_chat` TINYINT NOT NULL DEFAULT 1,
            `quiet_start` TIME DEFAULT NULL,
            `quiet_end` TIME DEFAULT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$prefix}notification_log` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `rule_id` INT UNSIGNED DEFAULT NULL,
            `event_type` VARCHAR(50) NOT NULL,
            `ticket_id` INT UNSIGNED DEFAULT NULL,
            `channel` VARCHAR(20) NOT NULL,
            `recipient` VARCHAR(255) NOT NULL,
            `subject` VARCHAR(255) DEFAULT '',
            `body` TEXT,
            `status` VARCHAR(20) NOT NULL DEFAULT 'sent',
            `error` TEXT DEFAULT NULL,
            `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ticket` (`ticket_id`),
            KEY `idx_rule` (`rule_id`),
            KEY `idx_sent` (`sent_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($sqls as $sql) {
        try {
            db_query($sql);
        } catch (\Exception $e) {
            // Non-fatal
            error_log('Notification table creation failed: ' . $e->getMessage());
        }
    }
}
