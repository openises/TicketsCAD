<?php
/**
 * Phase 155 (GH#144) - the Notification Rules schema migration.
 *
 * WHAT THIS DEFENDS AGAINST. The three notification tables were defined TWICE with
 * different column types: the installer imported sql/notification_rules.sql
 * (ENUMs for notification_rules.event_type and notification_log.status) while the
 * engine's lazy CREATE used VARCHARs when it found the table missing. What an
 * install ACCEPTED therefore depended on how its table had come to exist - an ENUM
 * refuses an event name added later, and 'queued' is not in the old status ENUM.
 * There is now ONE definition (inc/notification-schema.php); sql/notification_rules.sql
 * carries the same final shape; sql/run_phase155_notification_rules.php brings an
 * existing install to it.
 *
 * HOW IT TESTS. The REAL migration script, read fresh from disk, is run as a
 * subprocess against scratch tables under a unique TABLE PREFIX (this environment
 * cannot create databases; the script already takes every table name from
 * $GLOBALS['db_prefix']) - the same harness tests/test_gh92_settings_value_widening.php
 * uses. The "before" shapes are the pre-Phase-155 definitions, written out in full:
 * nothing is derived from the code under test. Scratch tables are dropped at the end.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_notify_helpers.php';
require_once __DIR__ . '/../inc/sql-splitter.php';
require_once __DIR__ . '/../inc/notification-schema.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
global $db_host, $db_user, $db_pass, $db_name;
$base = realpath(__DIR__ . '/..');
$livePrefix = $GLOBALS['db_prefix'] ?? '';
$run = substr(bin2hex(random_bytes(4)), 0, 8);
$prefixes = [];          // every scratch prefix, for cleanup
$tmpDirs = [];

echo "=== Phase 155 / GH#144 - the notification schema migration ===\n\n";

// ── harness ─────────────────────────────────────────────────────────────
/** A directory shaped like the app root, wired to the scratch prefix, holding the REAL migration. */
function mig_harness(string $prefix): string
{
    global $db_host, $db_user, $db_pass, $db_name, $base, $tmpDirs;
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p155mig_' . substr(bin2hex(random_bytes(4)), 0, 8);
    @mkdir($dir . '/sql', 0777, true);
    @mkdir($dir . '/inc', 0777, true);
    $tmpDirs[] = $dir;
    file_put_contents($dir . '/config.php', "<?php\n"
        . '$db_host = ' . var_export($db_host, true) . ";\n" . '$db_user = ' . var_export($db_user, true) . ";\n"
        . '$db_pass = ' . var_export($db_pass, true) . ";\n" . '$db_name = ' . var_export($db_name, true) . ";\n"
        . '$db_prefix = ' . var_export($prefix, true) . ";\n");
    // shims to the REAL files by absolute path (require_once dedups by realpath)
    file_put_contents($dir . '/inc/db.php', "<?php\nrequire_once " . var_export($base . '/inc/db.php', true) . ";\n");
    file_put_contents($dir . '/inc/notification-schema.php', "<?php\nrequire_once " . var_export($base . '/inc/notification-schema.php', true) . ";\n");
    file_put_contents($dir . '/sql/run_phase155_notification_rules.php', (string) file_get_contents($base . '/sql/run_phase155_notification_rules.php'));
    return $dir . '/sql/run_phase155_notification_rules.php';
}
function mig_drop(string $prefix): void
{
    foreach (['notification_rules', 'notification_preferences', 'notification_log', 'pending_routed_messages', 'settings', 'permissions'] as $t) {
        try { db()->exec("DROP TABLE IF EXISTS `{$prefix}{$t}`"); } catch (Throwable $e) {}
    }
}
/** The supporting tables the migration reads (settings, permissions) and, optionally, the queue. */
function mig_support(string $prefix, ?int $adminOnly, bool $withQueue): void
{
    db()->exec("CREATE TABLE `{$prefix}settings` (`id` bigint(8) NOT NULL AUTO_INCREMENT, `name` varchar(191) NOT NULL, `value` text DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uniq_name` (`name`)) ENGINE=InnoDB DEFAULT CHARSET=latin1");
    db()->exec("CREATE TABLE `{$prefix}permissions` (`id` int(11) NOT NULL AUTO_INCREMENT, `code` varchar(64) NOT NULL, `name` varchar(128) NOT NULL DEFAULT '',
        `category` varchar(32) NOT NULL DEFAULT 'action', `admin_only` tinyint(3) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`), UNIQUE KEY `code` (`code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($adminOnly !== null) {
        db()->prepare("INSERT INTO `{$prefix}permissions` (`code`, `admin_only`) VALUES ('action.manage_notification_rules', ?)")->execute([$adminOnly]);
    }
    if ($withQueue) {
        // the pre-Phase-127 shape: no 'expired'
        db()->exec("CREATE TABLE `{$prefix}pending_routed_messages` (`id` int(10) unsigned NOT NULL AUTO_INCREMENT, `ticket_id` bigint(20) unsigned DEFAULT NULL,
            `channel` varchar(64) NOT NULL, `target` varchar(255) NOT NULL, `body` text NOT NULL, `scheduled_send_at` datetime NOT NULL,
            `status` enum('pending','sent','killed','failed') NOT NULL DEFAULT 'pending', PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
/** The notification tables exactly as every install before Phase 155 had them (sql/notification_rules.sql, GH#84 era). */
function mig_legacy_tables(string $prefix): void
{
    db()->exec("CREATE TABLE `{$prefix}notification_rules` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL DEFAULT '',
        `event_type` ENUM('incident_create','incident_close','incident_status','unit_assign','unit_clear','severity_high','has_broadcast') NOT NULL,
        `severity_filter` TINYINT DEFAULT NULL, `incident_type_filter` INT UNSIGNED DEFAULT NULL,
        `channel` VARCHAR(20) NOT NULL DEFAULT 'email', `recipients` TEXT, `email_list_id` INT UNSIGNED DEFAULT NULL,
        `subject_template` VARCHAR(255) DEFAULT '', `body_template` TEXT, `active` TINYINT NOT NULL DEFAULT 1,
        `created_by` INT UNSIGNED DEFAULT NULL, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_event_type` (`event_type`), KEY `idx_active` (`active`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE `{$prefix}notification_preferences` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL, `channel_email` TINYINT NOT NULL DEFAULT 1,
        `channel_sms` TINYINT NOT NULL DEFAULT 0, `channel_chat` TINYINT NOT NULL DEFAULT 1, `quiet_start` TIME DEFAULT NULL,
        `quiet_end` TIME DEFAULT NULL, `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `idx_user` (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE `{$prefix}notification_log` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `rule_id` INT UNSIGNED DEFAULT NULL, `event_type` VARCHAR(50) NOT NULL,
        `ticket_id` INT UNSIGNED DEFAULT NULL, `channel` VARCHAR(20) NOT NULL, `recipient` VARCHAR(255) NOT NULL,
        `subject` VARCHAR(255) DEFAULT '', `body` TEXT, `status` ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
        `error` TEXT DEFAULT NULL, `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_ticket` (`ticket_id`), KEY `idx_rule` (`rule_id`), KEY `idx_sent` (`sent_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
/** A comparable description of the three tables: columns (type, null, default, extra, position) and indexes. */
function mig_signature(string $prefix): array
{
    $sig = [];
    foreach (['notification_rules', 'notification_preferences', 'notification_log'] as $t) {
        $cols = [];
        foreach (db_fetch_all("SELECT COLUMN_NAME n, COLUMN_TYPE t, IS_NULLABLE nl, COLUMN_DEFAULT d, EXTRA x, ORDINAL_POSITION p
                                 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$prefix . $t]) as $c) {
            // MariaDB spells an absent default 'NULL'; MySQL leaves it NULL. Same meaning.
            $d = $c['d'] === null ? 'NULL' : (string) $c['d'];
            $cols[] = implode('|', [$c['n'], strtolower($c['t']), $c['nl'], $d, strtolower((string) $c['x']), $c['p']]);
        }
        $idx = [];
        foreach (db_fetch_all("SELECT INDEX_NAME i, NON_UNIQUE u, SEQ_IN_INDEX s, COLUMN_NAME c FROM information_schema.STATISTICS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX", [$prefix . $t]) as $i) {
            $idx[] = implode('|', [$i['i'], $i['u'], $i['s'], $i['c']]);
        }
        $sig[$t] = ['columns' => $cols, 'indexes' => $idx];
    }
    return $sig;
}
function mig_coltype(string $prefix, string $table, string $col): string
{
    return strtolower((string) db_fetch_value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]));
}

try {
    // ─────────────────────────────────────────────────────────────────────
    echo "--- an existing install (the pre-Phase-155 shapes) ---\n";
    $pA = "p155mig_{$run}_a_"; $prefixes[] = $pA;
    mig_support($pA, 2, true);
    mig_legacy_tables($pA);
    db()->exec("INSERT INTO `{$pA}notification_rules` (`name`, `event_type`, `channel`, `recipients`) VALUES ('Legacy rule', 'severity_high', 'email', '[\"7\"]')");
    db()->exec("INSERT INTO `{$pA}notification_log` (`rule_id`, `event_type`, `channel`, `recipient`, `status`) VALUES (1, 'severity_high', 'email', 'old@example.invalid', 'failed')");
    p155_t('fixture: event_type and status really are ENUMs, and queue_id / once_per_incident do not exist yet',
        strpos(mig_coltype($pA, 'notification_rules', 'event_type'), 'enum(') === 0 && strpos(mig_coltype($pA, 'notification_log', 'status'), 'enum(') === 0
        && mig_coltype($pA, 'notification_log', 'queue_id') === '' && mig_coltype($pA, 'notification_rules', 'once_per_incident') === '');
    $refused = false;
    try { db()->exec("INSERT INTO `{$pA}notification_log` (`event_type`, `channel`, `recipient`, `status`) VALUES ('x', 'email', 'a', 'queued')"); }
    catch (Throwable $e) { $refused = true; }
    $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM `{$pA}notification_log` WHERE `status` = 'queued'");
    p155_t('...and the old status ENUM cannot hold `queued` (refused, or silently stored as an empty string)', $refused || $cnt === 0);
    db()->exec("DELETE FROM `{$pA}notification_log` WHERE `recipient` = 'a'");
    $sigBefore = mig_signature($pA);

    $script = mig_harness($pA);
    $r1 = p155_run_php([$script]);
    p155_t('the migration exits 0 on an existing install', $r1['code'] === 0);
    p155_t('...and says its outcome was verified', strpos($r1['out'], 'outcome verified') !== false);
    p155_t('notification_rules.event_type is now VARCHAR(50)', mig_coltype($pA, 'notification_rules', 'event_type') === 'varchar(50)');
    p155_t('notification_log.status is now VARCHAR(20)', mig_coltype($pA, 'notification_log', 'status') === 'varchar(20)');
    p155_t('once_per_incident, queue_id and idx_queue exist', mig_coltype($pA, 'notification_rules', 'once_per_incident') === 'tinyint(4)'
        && mig_coltype($pA, 'notification_log', 'queue_id') === 'int(10) unsigned'
        && (int) db_fetch_value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_queue'", [$pA . 'notification_log']) === 1);
    p155_t("the queue now accepts the 'expired' status the sweep writes (a fresh install's table was created AFTER Phase 127 tried to widen it)",
        strpos(mig_coltype($pA, 'pending_routed_messages', 'status'), "'expired'") !== false);
    $rule = db_fetch_one("SELECT * FROM `{$pA}notification_rules` WHERE `name` = 'Legacy rule'");
    $log = db_fetch_one("SELECT * FROM `{$pA}notification_log` WHERE `recipient` = 'old@example.invalid'");
    p155_t('existing rows survive intact (event name and status kept as text, once_per_incident defaults to 0)',
        $rule && $rule['event_type'] === 'severity_high' && (int) $rule['once_per_incident'] === 0 && $log && $log['status'] === 'failed' && $log['queue_id'] === null);
    db()->exec("INSERT INTO `{$pA}notification_log` (`event_type`, `channel`, `recipient`, `status`) VALUES ('incident_create', 'email', 'q@example.invalid', 'queued')");
    db()->exec("INSERT INTO `{$pA}notification_rules` (`name`, `event_type`) VALUES ('Future event', 'some_future_event')");
    p155_t('...and the widened columns now hold `queued` and an event name added later',
        (int) db_fetch_value("SELECT COUNT(*) FROM `{$pA}notification_log` WHERE `status` = 'queued'") === 1
        && (string) db_fetch_value("SELECT `event_type` FROM `{$pA}notification_rules` WHERE `name` = 'Future event'") === 'some_future_event');
    $settings = db_fetch_all("SELECT `name`, `value` FROM `{$pA}settings` ORDER BY `name`");
    $sv = []; foreach ($settings as $s) $sv[$s['name']] = $s['value'];
    p155_t('the three delivery settings were seeded with their defaults',
        ($sv['notification_email_format'] ?? null) === 'text' && ($sv['notification_prefs_mode'] ?? null) === 'explicit_only' && ($sv['notification_log_retention_days'] ?? null) === '180');

    $sigAfter1 = mig_signature($pA);
    $r2 = p155_run_php([$script]);
    p155_t('IDEMPOTENT: a second run exits 0', $r2['code'] === 0);
    p155_t('...changes nothing (the schema signature is byte-identical)', mig_signature($pA) === $sigAfter1);
    p155_t('...and reports "already" for the steps that were done', substr_count($r2['out'], 'already') >= 4);
    p155_t('...and the first run really did change the schema (the comparison is not vacuous)', $sigBefore !== $sigAfter1);

    // An administrator's own setting value must survive a re-run.
    db()->exec("UPDATE `{$pA}settings` SET `value` = 'html' WHERE `name` = 'notification_email_format'");
    p155_run_php([$script]);
    p155_t('a re-run does not overwrite an administrator\'s setting (seeded only when absent)',
        (string) db_fetch_value("SELECT `value` FROM `{$pA}settings` WHERE `name` = 'notification_email_format'") === 'html');

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- a fresh install, in any order (no notification tables, no queue table yet) ---\n";
    $pB = "p155mig_{$run}_b_"; $prefixes[] = $pB;
    mig_support($pB, 2, false);
    $scriptB = mig_harness($pB);
    $rb = p155_run_php([$scriptB]);
    p155_t('with none of the three tables present the migration creates them and exits 0', $rb['code'] === 0
        && (int) db_fetch_value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)",
            [$pB . 'notification_rules', $pB . 'notification_preferences', $pB . 'notification_log']) === 3);
    p155_t('...and tolerates the queue table not existing yet (run_phase18a_ creates it later)', strpos($rb['out'], 'table not present yet') !== false);
    // Now the later migration creates the queue table the OLD way; a re-run must still make it right.
    db()->exec("CREATE TABLE `{$pB}pending_routed_messages` (`id` int(10) unsigned NOT NULL AUTO_INCREMENT, `ticket_id` bigint(20) unsigned DEFAULT NULL,
        `channel` varchar(64) NOT NULL, `target` varchar(255) NOT NULL, `body` text NOT NULL, `scheduled_send_at` datetime NOT NULL,
        `status` enum('pending','sent','killed','failed') NOT NULL DEFAULT 'pending', PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $rb2 = p155_run_php([$scriptB]);
    p155_t("order-independent: once the queue table exists, a re-run widens it to accept 'expired'",
        $rb2['code'] === 0 && strpos(mig_coltype($pB, 'pending_routed_messages', 'status'), "'expired'") !== false);

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- a migration that cannot make its outcome true must say so (exit non-zero) ---\n";
    $pC = "p155mig_{$run}_c_"; $prefixes[] = $pC;
    mig_support($pC, null, true);               // the permission row does not exist
    $rc = p155_run_php([mig_harness($pC)]);
    p155_t('permission row missing -> exit NON-ZERO (a printed failure with exit 0 is a migration that never ran)', $rc['code'] !== 0);
    p155_t('...naming what to run', strpos($rc['out'], 'permission row missing') !== false && strpos($rc['out'], 'FINISHED WITH FAILURES') !== false);
    $pD = "p155mig_{$run}_d_"; $prefixes[] = $pD;
    mig_support($pD, 1, true);                  // classified tier 1 instead of tier 2
    $rd = p155_run_php([mig_harness($pD)]);
    p155_t('permission at admin_only=1 (not 2) -> exit NON-ZERO and says to run the reconcile script',
        $rd['code'] !== 0 && strpos($rd['out'], 'run_zzz_admin_only_reconcile') !== false);
    p155_t('...yet the schema steps still ran (one failure does not strand the rest)', mig_coltype($pD, 'notification_log', 'queue_id') === 'int(10) unsigned');

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- ONE definition: the .sql file, the engine's DDL, the migration and the live tables agree ---\n";
    $sqlFile = (string) file_get_contents($base . '/sql/notification_rules.sql');
    $stripped = preg_replace('/^\s*--.*$/m', '', $sqlFile);
    $stmts = splitSqlStatements($sqlFile);
    p155_t('sql/notification_rules.sql splits into exactly its three CREATE TABLE statements (no stray semicolon inside a string or comment truncates a statement)',
        count($stmts) === 3 && substr_count($stripped, ';') === 3);

    $pE = "p155mig_{$run}_e_"; $prefixes[] = $pE;      // built from the .sql file
    foreach ($stmts as $s) {
        $s = preg_replace('/CREATE TABLE IF NOT EXISTS `(notification_\w+)`/', 'CREATE TABLE `' . $pE . '$1`', $s);
        db()->exec($s);
    }
    $pF = "p155mig_{$run}_f_"; $prefixes[] = $pF;      // built from the engine's DDL
    foreach (notification_schema_ddl($pF) as $ddl) db()->exec($ddl);
    $sigSql = mig_signature($pE); $sigDdl = mig_signature($pF); $sigMig = mig_signature($pA); $sigLive = mig_signature($livePrefix);
    p155_t('sql/notification_rules.sql (what the installer imports) == inc/notification-schema.php (what the engine creates lazily)', $sigSql === $sigDdl);
    p155_t('...== the legacy install AFTER the migration (column types, defaults, nullability, ORDER, indexes)', $sigMig === $sigDdl);
    p155_t('...== this install\'s live tables', $sigLive === $sigDdl);
    if ($sigSql !== $sigDdl) {
        foreach ($sigSql as $t => $v) if ($v !== $sigDdl[$t]) echo "  drift in {$t}:\n    sql: " . implode(' ; ', $v['columns']) . "\n    ddl: " . implode(' ; ', $sigDdl[$t]['columns']) . "\n";
    }
    $cols = array_map(static function ($l) { return explode('|', $l)[0]; }, $sigDdl['notification_log']['columns']);
    p155_t('the shape is the intended one: log has queue_id, status is VARCHAR(20) DEFAULT sent',
        in_array('queue_id', $cols, true) && mig_coltype($pF, 'notification_log', 'status') === 'varchar(20)'
        // MariaDB reports a string default quoted ('sent'); MySQL reports it bare (sent)
        && trim((string) db_fetch_value("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'status'", [$pF . 'notification_log']), "'") === 'sent');

    echo "\n--- wiring ---\n";
    $glob = array_map('basename', glob($base . '/sql/run_*.php'));
    p155_t('the migration is named so sql/run_migrations.php discovers it (run_*.php)', in_array('run_phase155_notification_rules.php', $glob, true));
    $engineSrc = (string) file_get_contents($base . '/inc/notification-engine.php');
    p155_t('the engine\'s lazy table creation takes its DDL from inc/notification-schema.php (not its own copy)',
        strpos($engineSrc, 'notification_schema_ddl(') !== false && strpos($engineSrc, 'CREATE TABLE IF NOT EXISTS') === false);
} finally {
    foreach ($prefixes as $p) mig_drop($p);
    foreach ($tmpDirs as $d) {
        foreach (['/sql/run_phase155_notification_rules.php', '/inc/db.php', '/inc/notification-schema.php', '/config.php'] as $f) @unlink($d . $f);
        @rmdir($d . '/sql'); @rmdir($d . '/inc'); @rmdir($d);
    }
}
$left = 0;
foreach (db_fetch_all("SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?", ["p155mig_{$run}_%"]) as $_) $left++;
p155_t('the scratch tables are gone (verified by querying)', $left === 0);
p155_done();
