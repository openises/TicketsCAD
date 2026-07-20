<?php
/**
 * Phase 85f — Claude-on-radio schema migration.
 *
 * Creates:
 *   - ai_pending_responses       — operator approval queue
 *   - ai_conversations           — per-caller state
 *   - ai_conversation_messages   — rolling history per caller
 *
 * Seeds settings:
 *   - radio_ai_enabled (0)
 *   - radio_ai_wake_word ('claude')
 *   - radio_ai_model ('claude-sonnet-4-6')
 *   - radio_ai_max_response_words (75)
 *   - radio_ai_auto_discard_seconds (60)
 *   - radio_ai_channel_ids ('3')
 *   - radio_ai_topic_scope ('ham_general_science')
 *   - radio_ai_daily_token_budget (50000)
 *
 * Idempotent — safe to re-run. Uses CREATE TABLE IF NOT EXISTS and
 * settings-INSERT-IGNORE patterns matching the rest of newui/sql/.
 *
 * Run:  php sql/run_phase85f_radio_ai.php
 */

$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

function run_phase85f_step(PDO $pdo, string $label, string $sql): void
{
    try {
        $pdo->exec($sql);
        echo "  [ok] {$label}\n";
    } catch (PDOException $e) {
        // CREATE TABLE IF NOT EXISTS won't error, but seed INSERTs
        // can fail on unique violations — that's fine, idempotent.
        if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
            echo "  [skip] {$label} (already exists)\n";
            return;
        }
        echo "  [err] {$label}: " . $e->getMessage() . "\n";
        throw $e;
    }
}

$pdo = db();

echo "Phase 85f schema migration\n";
echo "--------------------------\n";

run_phase85f_step($pdo, 'create ai_pending_responses', "
    CREATE TABLE IF NOT EXISTS `{$prefix}ai_pending_responses` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `channel_id`      INT NOT NULL,
        `caller_src_id`   INT NOT NULL,
        `caller_callsign` VARCHAR(16) NULL,
        `inbound_call_id` VARCHAR(16) NOT NULL,
        `transcript`      TEXT NOT NULL,
        `draft_response`  TEXT NULL,
        `final_response`  TEXT NULL,
        `status`          ENUM('pending_generation','pending_approval',
                              'sent','discarded','filtered','auto_discarded',
                              'error') NOT NULL DEFAULT 'pending_generation',
        `error_msg`       VARCHAR(512) NULL,
        `tx_stream_id`    VARCHAR(16) NULL,
        `api_tokens_in`   INT NULL,
        `api_tokens_out`  INT NULL,
        `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
        `decided_at`      DATETIME NULL,
        `decided_by`      INT NULL,
        KEY `idx_status_created` (`status`, `created_at`),
        KEY `idx_caller` (`caller_callsign`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

run_phase85f_step($pdo, 'create ai_conversations', "
    CREATE TABLE IF NOT EXISTS `{$prefix}ai_conversations` (
        `callsign`        VARCHAR(16) NOT NULL PRIMARY KEY,
        `first_seen_at`   DATETIME NOT NULL,
        `last_seen_at`    DATETIME NOT NULL,
        `exchange_count`  INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

run_phase85f_step($pdo, 'create ai_conversation_messages', "
    CREATE TABLE IF NOT EXISTS `{$prefix}ai_conversation_messages` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `callsign`   VARCHAR(16) NOT NULL,
        `role`       ENUM('caller','assistant') NOT NULL,
        `content`    TEXT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_callsign_created` (`callsign`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$settings = [
    'radio_ai_enabled'              => '0',
    'radio_ai_wake_word'            => 'claude',
    'radio_ai_model'                => 'claude-sonnet-4-6',
    'radio_ai_max_response_words'   => '75',
    'radio_ai_auto_discard_seconds' => '60',
    'radio_ai_channel_ids'          => '3',
    'radio_ai_topic_scope'          => 'ham_general_science',
    'radio_ai_daily_token_budget'   => '50000',
];

foreach ($settings as $k => $v) {
    run_phase85f_step(
        $pdo,
        "seed setting {$k}",
        "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`)
         VALUES (" . $pdo->quote($k) . ", " . $pdo->quote($v) . ")"
    );
}

echo "\nDone.\n";
