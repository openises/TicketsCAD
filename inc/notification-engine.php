<?php
/**
 * NewUI v4.0 — Notification Delivery Engine
 *
 * Evaluates notification rules against events and delivers messages
 * through the message broker (email, SMS, local chat).
 *
 * dead_control_audit.php check (c), 2026-08-20: this engine is fully
 * functional and genuinely reads every `notification_rules` column
 * (active, event_type, severity_filter, incident_type_filter, channel,
 * recipients, email_list_id, subject_template, body_template) — but
 * there is NO admin UI or API to create/edit a rule. Settings ->
 * Notification Rules is a described-but-unbuilt stub (see
 * tools/dead_control_settings_baseline.txt's own entry for this exact
 * feature from the settings-key side of the same audit). Rows can only
 * be inserted by hand/SQL today — see tests/test_notification_rule_channels.php
 * for the only current writer. Wiring api/notification-rules.php + a
 * Settings CRUD panel is the deferred work this comment marks; the
 * engine itself needs no change when that lands.
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
require_once __DIR__ . '/severity.php';

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

        // Determine channels to use. GH #84: 'all' now means every
        // currently-registered broker channel, computed fresh on each
        // evaluation so a future adapter is reachable via 'all' without
        // another code change here. Before this fix 'all' was hardcoded
        // to ['email', 'sms', 'local_chat'] and notification_rules.channel
        // was an ENUM that could not even STORE any other single channel
        // name — see sql/notification_rules.sql.
        global $_broker_channels;
        $channels = _notification_resolve_rule_channels($rule['channel'], $_broker_channels);

        // Some adapters (Slack, Telegram) deliver to exactly ONE
        // destination fixed by server-side configuration, ignoring the
        // message's `to` entirely. See _notification_classify_channels()
        // below for why those must fire once per rule match rather than
        // once per recipient.
        [$sharedChannels, $perRecipientChannels] = _notification_classify_channels($channels, $_broker_channels);

        // GH#88 — was a hardcoded `severity >= 2`; now honors whichever
        // level(s) an agency has actually flagged is_high_alert
        // (inc/severity.php), so it isn't tied to "the level historically
        // numbered 2."
        $priority = (isset($context['severity']) && severity_is_high_alert((int) $context['severity'])) ? 'high' : 'normal';

        // Shared-destination channels fire exactly once per rule match.
        // There is exactly one destination no matter how many (or how
        // few — zero is fine) recipients the rule has configured, so
        // these are NOT looped against $recipients and are not subject
        // to the per-user preference gate below — there is no "user" on
        // the other end of a Slack channel or a Telegram chat.
        foreach ($sharedChannels as $channel) {
            $message = [
                'to'       => 'all',
                'subject'  => $subject,
                'body'     => $body,
                'type'     => 'notification',
                'priority' => $priority,
            ];

            $sendResult = broker_send($channel, $message);

            _notification_log($rule['id'], $event_type, $context['ticket_id'] ?? null, $channel,
                'shared:' . $channel, $subject, $body,
                $sendResult['success'] ? 'sent' : 'failed', $sendResult['error'] ?? null
            );

            $results[] = [
                'rule_id'   => $rule['id'],
                'channel'   => $channel,
                'recipient' => 'shared:' . $channel,
                'success'   => $sendResult['success'] ?? false,
                'error'     => $sendResult['error'] ?? null,
            ];
        }

        // Per-recipient channels — unchanged shape from before GH #84:
        // email, SMS, and local chat each address a specific recipient;
        // push (new, GH #84) resolves to that recipient's own push
        // subscriptions.
        foreach ($recipients as $recipient) {
            // Check user notification preferences (if we have a user_id)
            $prefs = null;
            if (isset($recipient['user_id'])) {
                $prefs = _notification_get_user_prefs((int) $recipient['user_id']);
            }

            foreach ($perRecipientChannels as $channel) {
                // Respect user preferences for the channels that carry an
                // explicit opt-in/opt-out column. A channel with no
                // preference column here (push, aprs, dmr, meshtastic,
                // meshcore, smtp) is deliberately NOT gated by preference —
                // notification_preferences has no per-channel column for
                // them, and a rule naming one of them is an explicit admin
                // choice, not a per-user subscription.
                if ($prefs !== null) {
                    if ($channel === 'email' && !$prefs['channel_email']) continue;
                    if ($channel === 'sms' && !$prefs['channel_sms']) continue;
                    if ($channel === 'local_chat' && !$prefs['channel_chat']) continue;

                    // Check quiet hours (applies to every channel, not
                    // just the three above)
                    if (_notification_in_quiet_hours($prefs)) continue;
                }

                $message = [
                    'to'       => $recipient['address'] ?? $recipient['user_id'] ?? 'all',
                    'subject'  => $subject,
                    'body'     => $body,
                    'type'     => 'notification',
                    'priority' => $priority,
                ];

                // GH #84: inc/channels/push.php doesn't read `to` at all —
                // it requires a resolved user-id list in
                // `_recipient_user_ids` (the shape the routing engine's
                // predicate resolver normally produces for it). Without
                // this, broker_send('push', ...) always fails with "no
                // user IDs resolved", even once the channel itself is
                // reachable. A recipient with no user_id (a bare email
                // address, or an email-list member) has no push
                // subscription to target — log it as skipped rather than
                // attempt a call that can only fail.
                if ($channel === 'push') {
                    if (empty($recipient['user_id'])) {
                        _notification_log($rule['id'], $event_type, $context['ticket_id'] ?? null, $channel,
                            $recipient['address'] ?? 'unknown', $subject, $body, 'skipped',
                            'push requires a user account; recipient has no user_id'
                        );
                        continue;
                    }
                    $message['_recipient_user_ids'] = [(int) $recipient['user_id']];
                }

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
 * GH #84: resolve a notification_rules.channel value to the list of
 * broker channel codes it targets. Pure function — no DB, no globals —
 * so it can be unit tested without a database.
 *
 * 'all' resolves to every key of the supplied registered-channels map
 * (i.e. array_keys($_broker_channels)) rather than a hardcoded subset,
 * so a newly-registered broker channel is reachable via 'all' the
 * instant its inc/channels/*.php file registers it — no code change
 * needed here.
 *
 * @param string $ruleChannel        The rule's `channel` column value.
 * @param array  $registeredChannels The live $_broker_channels map
 *                                   (code => handler array).
 * @return string[] Channel codes to dispatch to.
 */
function _notification_resolve_rule_channels($ruleChannel, array $registeredChannels) {
    if ($ruleChannel === 'all') {
        return array_keys($registeredChannels);
    }
    return [$ruleChannel];
}

/**
 * GH #84: split a list of channel codes into "shared destination"
 * (adapter posts to one fixed, server-configured destination regardless
 * of the message's `to` — e.g. Slack's `slack_channel`, Telegram's
 * `telegram_chat_id`) versus "per recipient" (the destination is
 * genuinely the message's `to` — email, SMS, local chat, push, and
 * everything else).
 *
 * This split exists because notification_check() loops
 * (recipient x channel): looping a shared-destination channel inside
 * that would re-post the identical message to the same destination once
 * per recipient. A channel opts into the shared bucket by declaring
 * `'shared_destination' => true` on its broker_register() call — the
 * same declarative pattern Phase 134's 'pollable' flag already uses, so
 * a future channel-owner sets the flag rather than this function
 * hardcoding channel names.
 *
 * Pure function — no DB, no globals — so it can be unit tested without
 * a database.
 *
 * @param string[] $channels           Channel codes to classify.
 * @param array    $registeredChannels The live $_broker_channels map.
 * @return array{0: string[], 1: string[]} [sharedChannels, perRecipientChannels]
 */
function _notification_classify_channels(array $channels, array $registeredChannels) {
    $shared = [];
    $perRecipient = [];
    foreach ($channels as $channel) {
        if (!empty($registeredChannels[$channel]['shared_destination'])) {
            $shared[] = $channel;
        } else {
            $perRecipient[] = $channel;
        }
    }
    return [$shared, $perRecipient];
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
            // User ID — look up email/phone.
            //
            // Found while testing GH #84's channel widening (a numeric
            // recipient never resolved on this dev DB): `user` has no
            // `cell` column and never has — the schema-mismatch pattern
            // this file documents extensively elsewhere (member.username,
            // facilities.fac_type, etc.). The mobile number lives in
            // `phone_m` (alongside `phone_p` primary / `phone_s`
            // secondary). Every numeric-user-id recipient on a
            // notification rule silently failed to resolve at all — the
            // query threw, the catch below swallowed it with no trace,
            // and the recipient was dropped. Aliased back to `cell` so
            // the rest of this function is unchanged.
            try {
                $user = db_fetch_one(
                    "SELECT `id`, `email`, `phone_m` AS `cell` FROM `{$prefix}user` WHERE `id` = ?",
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
                // Skip this recipient, but leave a trace — a silent
                // catch{} here is what let the `cell` schema mismatch
                // above go unnoticed.
                error_log('[notification-engine] recipient lookup failed for user_id ' . $entry . ': ' . $e->getMessage());
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
    // GH#87/GH#88 (2026-08-19) — was a hardcoded ['Normal','Medium','High']
    // array (yet another spelling of the same 3 integers vs. every other
    // screen — see GH#88's investigation). Sourced from the configurable
    // severity_levels table (inc/severity.php) instead.
    return severity_label((int) $severity);
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
