<?php
require __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}sse_events` (
        `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
        `event_type` VARCHAR(64)  NOT NULL,
        `payload`    TEXT         NOT NULL DEFAULT '{}',
        `user_id`    INT          DEFAULT NULL COMMENT 'Originating user (null = system)',
        `created_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        KEY `idx_created` (`created_at`),
        KEY `idx_type` (`event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] sse_events table ready\n";
} catch (Exception $e) {
    echo "[WARN] " . $e->getMessage() . "\n";
}

// Test publish
require __DIR__ . '/../inc/sse.php';
$result = sse_publish('system:refresh', ['reason' => 'SSE table test', 'test' => true]);
echo $result ? "[OK] Test event published\n" : "[WARN] Could not publish test event\n";

$count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events`");
echo "[OK] $count events in table\n";
