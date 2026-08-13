<?php
/**
 * GH#42 (2026-08-13) — retention for the outbound message log.
 *
 * `messages` (channel/direction/sender/recipient/status/error/delivered_at)
 * holds one row per SMS, e-mail, Slack, and other system-channel delivery --
 * these are what Messages -> Sent shows alongside internal chat, and unlike
 * internal_messages they carry no delete-from-my-view affordance at all, so
 * the table only ever grows. Measured by @rjonesbsink: 853 rows / 1.6MB in
 * 14 days on one real install.
 *
 * Deliberately simpler than inc/audit-retention.php's Phase 133 model: no
 * archive-before-delete, no CJIS-floor warning, no dedicated permission.
 * These are delivery-status log rows (did this SMS go out, and when), not
 * the audit trail Phase 133 built the heavier machinery to protect. Off by
 * default -- 0 days means keep forever, matching every other retention
 * setting in this app.
 *
 * `channel = 'local_chat'` rows are never touched by this purge -- internal
 * chat history is a different feature with different expectations, and
 * nothing about this setting should reach it.
 */

/** Read the retention window in days. 0 = disabled (keep everything). */
function message_log_retention_days(): int
{
    if (!function_exists('get_variable')) return 0;
    $v = get_variable('message_log_retention_days');
    if ($v === null || $v === false || $v === '') return 0;
    $d = (int) $v;
    return $d > 0 ? $d : 0;
}

/**
 * How many rows are eligible for purge right now, without deleting anything.
 * Used by the settings UI and by the tick's log line.
 */
function message_log_retention_eligible_count(int $days): int
{
    if ($days <= 0) return 0;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}messages`
              WHERE `channel` <> 'local_chat' AND `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Purge outbound message-log rows older than the configured window.
 * A no-op (skipped) when retention is disabled -- never fatal, never
 * silent: the caller always gets a real ok/skipped/error verdict to log.
 *
 * @return array{ok:bool, skipped:bool, deleted:int, detail:string}
 */
function message_log_purge_run(): array
{
    $days = message_log_retention_days();
    if ($days <= 0) {
        return ['ok' => true, 'skipped' => true, 'deleted' => 0,
                'detail' => 'disabled (message_log_retention_days=0)'];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $eligible = message_log_retention_eligible_count($days);
        if ($eligible === 0) {
            return ['ok' => true, 'skipped' => false, 'deleted' => 0,
                    'detail' => "0 rows older than {$days} day(s)"];
        }
        db_query(
            "DELETE FROM `{$prefix}messages`
              WHERE `channel` <> 'local_chat' AND `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        if (function_exists('audit_log')) {
            audit_log('config', 'purge', 'message_log', null,
                "Purged {$eligible} message-log row(s) older than {$days} day(s)");
        }
        return ['ok' => true, 'skipped' => false, 'deleted' => $eligible,
                'detail' => "purged {$eligible} row(s) older than {$days} day(s)"];
    } catch (Throwable $e) {
        return ['ok' => false, 'skipped' => false, 'deleted' => 0,
                'detail' => $e->getMessage()];
    }
}
