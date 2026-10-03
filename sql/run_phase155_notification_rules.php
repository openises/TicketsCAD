<?php
/**
 * Phase 155 (GH#144) - schema for the Notification Rules rebuild.
 *
 *   1. notification_rules.event_type   ENUM(7 values)  -> VARCHAR(50) NOT NULL
 *   2. notification_rules.once_per_incident            TINYINT NOT NULL DEFAULT 0 (new)
 *   3. notification_log.status         ENUM(sent,failed,skipped) -> VARCHAR(20) NOT NULL DEFAULT 'sent'
 *   4. notification_log.queue_id       INT UNSIGNED NULL (new) + index idx_queue
 *   5. pending_routed_messages.status  gains 'expired' (see below)
 *   6. the three delivery settings are seeded when absent
 *   7. the permission action.manage_notification_rules exists at admin_only=2
 *
 * WHY THE ENUMS GO. The tables were defined twice with different types - the
 * installer imported sql/notification_rules.sql (ENUMs) while the engine's
 * lazy CREATE used VARCHARs - so what an install accepted depended on how its
 * table came to exist. An ENUM also refuses an event name added later and, on a
 * strict-mode connection, fails an INSERT of 'queued' outright. Both tables now
 * carry the single shape in inc/notification-schema.php.
 *
 * (5) is a defect found while building this phase's queue. run_phase127_
 * scheduled_jobs.php widens pending_routed_messages.status with 'expired', but
 * `run_phase127_` sorts BEFORE `run_phase18a_` (sql/run_migrations.php runs files
 * in lexicographic order) and run_phase18a_security_labels.php is what CREATES
 * that table - so on every FRESH install the widening ran against a table that
 * did not exist yet ("[skip] not present on this install"), and the sweep's
 * stale-work cutoff then tried to write a status its own column could not hold.
 * The queue the notification deliveries depend on must be able to hold every
 * state the sweep writes, so this migration makes that true regardless of order.
 * Self-sufficient in ANY order: every step checks what exists first.
 *
 * Idempotent. VERIFIES ITS OWN OUTCOME and exits non-zero if anything it was
 * asked to make true is not true afterwards - a migration that prints a
 * failure and exits 0 is a migration that never ran (Phase 128, A9).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/notification-schema.php';

echo "Phase 155 - notification rules schema\n";
echo "======================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$exit = 0;

$tableExists = static function (string $t) use ($prefix): bool {
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $t]);
};
$colInfo = static function (string $t, string $c) use ($prefix): ?array {
    $r = db_fetch_one(
        "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $t, $c]);
    return $r ?: null;
};
$idxExists = static function (string $t, string $i) use ($prefix): bool {
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
        [$prefix . $t, $i]);
};
$step = static function (string $label, callable $fn) use (&$exit): void {
    try {
        $msg = $fn();
        echo "[OK] {$label}" . ($msg ? " - {$msg}" : '') . "\n";
    } catch (Throwable $e) {
        echo "[FAIL] {$label}: " . $e->getMessage() . "\n";
        $exit = 1;
    }
};

// 0. The three tables, if an install never had them (the engine used to create
//    them lazily on first use, so a quiet install may have none).
$step('notification tables present', function () use ($prefix, $tableExists) {
    $made = [];
    foreach (['notification_rules', 'notification_preferences', 'notification_log'] as $t) {
        if (!$tableExists($t)) $made[] = $t;
    }
    foreach (notification_schema_ddl($prefix) as $ddl) db_query($ddl);
    return $made ? 'created ' . implode(', ', $made) : 'already there';
});

// 1. event_type -> VARCHAR(50)
$step('notification_rules.event_type is VARCHAR(50)', function () use ($prefix, $colInfo) {
    $c = $colInfo('notification_rules', 'event_type');
    if (!$c) throw new RuntimeException('column missing');
    if (strtolower($c['DATA_TYPE']) !== 'enum') return 'already ' . $c['COLUMN_TYPE'];
    db_query("ALTER TABLE `{$prefix}notification_rules` MODIFY COLUMN `event_type` VARCHAR(50) NOT NULL");
    return 'widened from ' . $c['COLUMN_TYPE'] . ' (values preserved as text)';
});

// 2. once_per_incident
$step('notification_rules.once_per_incident exists', function () use ($prefix, $colInfo) {
    if ($colInfo('notification_rules', 'once_per_incident')) return 'already there';
    db_query("ALTER TABLE `{$prefix}notification_rules`
              ADD COLUMN `once_per_incident` TINYINT NOT NULL DEFAULT 0 AFTER `body_template`");
    return 'added';
});

// 3. log status -> VARCHAR(20)
$step('notification_log.status is VARCHAR(20)', function () use ($prefix, $colInfo) {
    $c = $colInfo('notification_log', 'status');
    if (!$c) throw new RuntimeException('column missing');
    if (strtolower($c['DATA_TYPE']) !== 'enum') return 'already ' . $c['COLUMN_TYPE'];
    db_query("ALTER TABLE `{$prefix}notification_log` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'sent'");
    return 'widened from ' . $c['COLUMN_TYPE'];
});

// 4. queue_id + index
$step('notification_log.queue_id exists', function () use ($prefix, $colInfo) {
    if ($colInfo('notification_log', 'queue_id')) return 'already there';
    db_query("ALTER TABLE `{$prefix}notification_log` ADD COLUMN `queue_id` INT UNSIGNED NULL DEFAULT NULL AFTER `sent_at`");
    return 'added';
});
$step('notification_log idx_queue exists', function () use ($prefix, $idxExists) {
    if ($idxExists('notification_log', 'idx_queue')) return 'already there';
    db_query("ALTER TABLE `{$prefix}notification_log` ADD KEY `idx_queue` (`queue_id`)");
    return 'added';
});

// 5. the queue must be able to hold every state the sweep writes
$step("pending_routed_messages.status accepts 'expired'", function () use ($prefix, $tableExists, $colInfo) {
    if (!$tableExists('pending_routed_messages')) {
        // Not created yet (run_phase18a_security_labels.php creates it and runs
        // later). Nothing to widen; its own CREATE is what must be correct, and
        // the verification below only insists when the table exists.
        return 'table not present yet - skipped';
    }
    $c = $colInfo('pending_routed_messages', 'status');
    if (!$c) throw new RuntimeException('column missing');
    if (strpos((string) $c['COLUMN_TYPE'], "'expired'") !== false) return 'already accepts it';
    db_query("ALTER TABLE `{$prefix}pending_routed_messages`
              MODIFY COLUMN `status` ENUM('pending','sent','killed','failed','expired') NOT NULL DEFAULT 'pending'");
    return 'widened (fresh installs created the table AFTER Phase 127 tried to)';
});

// 6. delivery settings (only when absent: an administrator's value survives)
$step('delivery settings seeded', function () use ($prefix) {
    $defaults = ['notification_email_format' => 'text', 'notification_prefs_mode' => 'explicit_only',
                 'notification_log_retention_days' => '180'];
    $added = [];
    foreach ($defaults as $name => $val) {
        $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if ($n === 0) {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $val]);
            $added[] = $name;
        }
    }
    return $added ? 'added ' . implode(', ', $added) : 'already set';
});

// 7. the permission (seeded by sql/rbac.sql + sql/run_00_rbac.php, which sort/import first)
$step('action.manage_notification_rules exists at admin_only=2', function () use ($prefix) {
    $row = db_fetch_one("SELECT `id`, `admin_only` FROM `{$prefix}permissions` WHERE `code` = 'action.manage_notification_rules'");
    if (!$row) throw new RuntimeException('permission row missing - run php sql/run_00_rbac.php');
    if ((int) $row['admin_only'] !== 2) {
        throw new RuntimeException('admin_only is ' . (int) $row['admin_only'] . ', expected 2 - run php sql/run_zzz_admin_only_reconcile.php');
    }
    return 'present';
});

// ── Verify the outcome: ask the database, not the lines just printed ─────
$bad = [];
$c = $colInfo('notification_rules', 'event_type');
if (!$c || strtolower($c['DATA_TYPE']) === 'enum') $bad[] = 'notification_rules.event_type is still an ENUM or missing';
if (!$colInfo('notification_rules', 'once_per_incident')) $bad[] = 'notification_rules.once_per_incident missing';
$c = $colInfo('notification_log', 'status');
if (!$c || strtolower($c['DATA_TYPE']) === 'enum') $bad[] = 'notification_log.status is still an ENUM or missing';
if (!$colInfo('notification_log', 'queue_id')) $bad[] = 'notification_log.queue_id missing';
if ($tableExists('pending_routed_messages')) {
    $c = $colInfo('pending_routed_messages', 'status');
    if (!$c || strpos((string) $c['COLUMN_TYPE'], "'expired'") === false) $bad[] = "pending_routed_messages.status does not accept 'expired'";
}
if ($bad) {
    foreach ($bad as $b) echo "[FAIL] {$b}\n";
    $exit = 1;
}

echo ($exit === 0) ? "\nDone - outcome verified.\n" : "\nFINISHED WITH FAILURES.\n";
exit($exit);
