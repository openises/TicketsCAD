<?php
/**
 * GH#42 (2026-08-13) — retention for the outbound message log. 853 rows /
 * 1.6MB in 14 days on one real install, growing unbounded. Off by default,
 * a daily sweep purges `messages` rows (excluding local_chat) older than
 * the configured window once an admin turns it on.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/message-log-retention.php';

$pass = 0; $fail = 0;
function t($l, $c, $d = '') { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($d && !$c ? " — $d" : '') . "\n"; }

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_fetch_value("SELECT 1 FROM `{$prefix}messages` LIMIT 1");
} catch (Throwable $e) {
    echo "SKIP: messages table absent (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// ── Setting getter, proven from a SEPARATE process. get_variable() caches
//    the whole settings table in a static on first call (documented
//    elsewhere in this project as a real footgun) -- an in-process
//    write-then-read is answered from that cache and would pass even if
//    message_log_retention_days() read the wrong store entirely. Mirrors
//    tests/test_backup_end_to_end.php's e2e_probe() technique exactly.
$orig = null;
try { $orig = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'message_log_retention_days'"); } catch (Throwable $e) {}

function gh42_set_days($prefix, $val) {
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('message_log_retention_days', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [(string) $val]
    );
}

function gh42_probe(string $root, string $php): string
{
    $root = realpath($root) ?: $root;
    $code = '<?php require_once ' . var_export($root . '/config.php', true) . ';'
          . ' require_once ' . var_export($root . '/inc/message-log-retention.php', true) . ';'
          . ' ob_start();'
          . $php
          . ' ; $__payload = ob_get_clean(); echo "<<<GH42>>>" . $__payload;';
    $tmp = sys_get_temp_dir() . '/newui-gh42-probe-' . getmypid() . '-' . mt_rand() . '.php';
    file_put_contents($tmp, $code);
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $out = (string) @shell_exec(escapeshellarg($bin) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    $at = strpos($out, '<<<GH42>>>');
    if ($at !== false) $out = substr($out, $at + strlen('<<<GH42>>>'));
    return trim($out);
}

gh42_set_days($prefix, '0');
$seen = gh42_probe(__DIR__ . '/..', 'echo (string) message_log_retention_days();');
t('retention_days() reads 0 (disabled) after an explicit 0 write, from a fresh process', $seen === '0', "saw '$seen'");

gh42_set_days($prefix, '30');
$seen = gh42_probe(__DIR__ . '/..', 'echo (string) message_log_retention_days();');
t('retention_days() reads back a saved nonzero value, from a fresh process', $seen === '30', "saw '$seen'");

// ── Purge is a true no-op while disabled. Through the probe, same reason as
//    above -- and this ALSO avoids permanently warming this process's
//    get_variable() cache at '0', which would otherwise make every later
//    in-process purge_run() call in this file see a stale disabled reading
//    no matter what gh42_set_days() writes afterward.
gh42_set_days($prefix, '0');
$out = gh42_probe(__DIR__ . '/..', '$r = message_log_purge_run(); echo json_encode($r);');
$r = json_decode($out, true) ?: [];
t('purge_run() skips when disabled', ($r['skipped'] ?? null) === true && ($r['ok'] ?? null) === true, $out);
t('purge_run() deletes nothing while disabled', ($r['deleted'] ?? null) === 0, $out);

// ── Seed rows: one old external-channel row (should be purged), one old
//    local_chat row (must survive — different feature), one recent row
//    (must survive — inside the window).
$now = date('Y-m-d H:i:s');
$old = date('Y-m-d H:i:s', strtotime('-45 days'));
$oldChat = null; $oldSms = null; $recentSms = null;
try {
    db_query(
        "INSERT INTO `{$prefix}messages` (`channel`,`direction`,`sender`,`recipient`,`subject`,`body`,`status`,`created_at`)
         VALUES ('local_chat','outbound','gh42-test','gh42-test','','test','sent',?)", [$old]);
    $oldChat = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}messages` (`channel`,`direction`,`sender`,`recipient`,`subject`,`body`,`status`,`created_at`)
         VALUES ('sms','outbound','gh42-test','+15550000000','','test','sent',?)", [$old]);
    $oldSms = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}messages` (`channel`,`direction`,`sender`,`recipient`,`subject`,`body`,`status`,`created_at`)
         VALUES ('sms','outbound','gh42-test','+15550000000','','test','sent',?)", [$now]);
    $recentSms = (int) db_insert_id();
} catch (Throwable $e) {
    t('seed fixture rows', false, $e->getMessage());
}

if ($oldChat && $oldSms && $recentSms) {
    gh42_set_days($prefix, '30');
    $eligible = message_log_retention_eligible_count(30);
    t('eligible_count() counts the old SMS row', $eligible >= 1);

    $r = message_log_purge_run();
    t('purge_run() reports ok when enabled', $r['ok'] === true && $r['skipped'] === false);
    t('purge_run() deleted at least the seeded old SMS row', $r['deleted'] >= 1);

    $stillOldSms   = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}messages` WHERE id = ?", [$oldSms]);
    $stillOldChat  = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}messages` WHERE id = ?", [$oldChat]);
    $stillRecent   = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}messages` WHERE id = ?", [$recentSms]);
    t('old SMS row was actually deleted', (int) $stillOldSms === 0);
    t('old local_chat row survives — this purge never touches chat', (int) $stillOldChat === 1);
    t('recent SMS row survives — inside the retention window', (int) $stillRecent === 1);

    // Cleanup whatever's left.
    try {
        db_query("DELETE FROM `{$prefix}messages` WHERE id IN (?, ?, ?)", [$oldChat, $oldSms, $recentSms]);
    } catch (Throwable $e) {}
} else {
    t('seeded-row assertions skipped (fixture setup failed)', false);
}

// Restore whatever was there before this test ran.
try {
    if ($orig === null || $orig === false) {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'message_log_retention_days'");
    } else {
        gh42_set_days($prefix, $orig);
    }
} catch (Throwable $e) {}

// ── Scheduler registration — the job must actually be wired up, not just
//    exist as a standalone script nobody schedules.
require_once $root . '/inc/scheduled-jobs.php';
$defs = sched_job_registry();
t('message_log_purge is registered in the scheduled-jobs registry', array_key_exists('message_log_purge', $defs));
t('message_log_purge points at the real tick script', isset($defs['message_log_purge']['command'])
    && strpos($defs['message_log_purge']['command'], 'message_log_purge_tick.php') !== false);

// Through the probe again -- an in-process call to sched_job_required() here
// would read message_log_retention_days() through THIS process's now-warm
// get_variable() cache (poisoned '30' by the seeded-rows section above),
// not the disabled state actually restored to the database.
$reqOut = gh42_probe($root, 'require_once ' . var_export($root . '/inc/scheduled-jobs.php', true) . '; echo json_encode(sched_job_required("message_log_purge"));');
$req = json_decode($reqOut, true) ?: [];
t('sched_job_required() never reports critical on a fresh/disabled install', ($req['required'] ?? null) === false, $reqOut);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
