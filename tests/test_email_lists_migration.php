<?php
/**
 * Phase 155 (GH#145) - sql/run_phase155_email_lists_options.php.
 *
 * The migration seeds the two Email List options. What can go wrong with a seeding
 * migration is exactly what has gone wrong before in this codebase: it overwrites a
 * value an administrator chose, it seeds the wrong thing, it prints a failure and
 * exits 0 (a migration that never ran, Phase 128 A9), or it breaks on an install that
 * lacks a table it did not need.
 *
 * The REAL script, read fresh from disk, runs as a subprocess against scratch tables
 * under a unique TABLE PREFIX (this environment cannot create databases; the script
 * takes its table names from $GLOBALS['db_prefix']) - the harness
 * tests/test_gh92_settings_value_widening.php established. Scratch tables are
 * dropped at the end.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_notify_helpers.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
global $db_host, $db_user, $db_pass, $db_name;
$base = realpath(__DIR__ . '/..');
$run = substr(bin2hex(random_bytes(4)), 0, 8);
$prefixes = []; $tmpDirs = [];

echo "=== Phase 155 / GH#145 - the email list options migration ===\n\n";

function eo_harness(string $prefix): string
{
    global $db_host, $db_user, $db_pass, $db_name, $base, $tmpDirs;
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p155eo_' . substr(bin2hex(random_bytes(4)), 0, 8);
    @mkdir($dir . '/sql', 0777, true);
    $tmpDirs[] = $dir;
    file_put_contents($dir . '/config.php', "<?php\n"
        . '$db_host = ' . var_export($db_host, true) . ";\n" . '$db_user = ' . var_export($db_user, true) . ";\n"
        . '$db_pass = ' . var_export($db_pass, true) . ";\n" . '$db_name = ' . var_export($db_name, true) . ";\n"
        . '$db_prefix = ' . var_export($prefix, true) . ";\n"
        . "require_once " . var_export($base . '/inc/db.php', true) . ";\n");
    file_put_contents($dir . '/sql/run_phase155_email_lists_options.php', (string) file_get_contents($base . '/sql/run_phase155_email_lists_options.php'));
    return $dir . '/sql/run_phase155_email_lists_options.php';
}
function eo_tables(string $prefix, ?array $statusLabels, bool $withSettings = true): void
{
    if ($withSettings) {
        db()->exec("CREATE TABLE `{$prefix}settings` (`id` bigint(8) NOT NULL AUTO_INCREMENT, `name` varchar(191) NOT NULL, `value` text DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq_name` (`name`)) ENGINE=InnoDB DEFAULT CHARSET=latin1");
    }
    if ($statusLabels !== null) {
        db()->exec("CREATE TABLE `{$prefix}member_status` (`id` int NOT NULL AUTO_INCREMENT, `status_val` varchar(64) NOT NULL DEFAULT '', PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ($statusLabels as $l) db()->prepare("INSERT INTO `{$prefix}member_status` (`status_val`) VALUES (?)")->execute([$l]);
    }
}
function eo_drop(string $prefix): void
{
    foreach (['settings', 'member_status'] as $t) { try { db()->exec("DROP TABLE IF EXISTS `{$prefix}{$t}`"); } catch (Throwable $e) {} }
}
function eo_val(string $prefix, string $name)
{
    return db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
}
function eo_new(string $tag, ?array $labels, bool $withSettings = true): array
{
    global $run, $prefixes;
    $p = "p155eo_{$run}_{$tag}_";
    $prefixes[] = $p;
    eo_tables($p, $labels, $withSettings);
    return [$p, eo_harness($p)];
}

try {
    echo "--- a fresh install ---\n";
    [$p1, $s1] = eo_new('a', ['Active', 'Suspended', 'Retired', 'retired', 'On Leave']);
    $r1 = p155_run_php([$s1]);
    p155_t('the migration exits 0', $r1['code'] === 0);
    p155_t('...and says both options were verified', strpos($r1['out'], 'both options verified') !== false);
    $skip = json_decode((string) eo_val($p1, 'email_list_skip_member_statuses'), true);
    sort($skip);
    p155_t('the default skip list is exactly the labels present among suspended / retired, lower-cased and de-duplicated (a second "retired" spelling adds nothing)', $skip === ['retired', 'suspended']);
    p155_t('...other statuses (Active, On Leave) are NOT skipped by default', !in_array('active', $skip, true) && !in_array('on leave', $skip, true));
    p155_t('require-email-on-add defaults to 0 (allow and warn)', (string) eo_val($p1, 'email_list_require_email_on_add') === '0');

    echo "\n--- idempotent, and an administrator's choice survives ---\n";
    $before = [eo_val($p1, 'email_list_skip_member_statuses'), eo_val($p1, 'email_list_require_email_on_add')];
    $r2 = p155_run_php([$s1]);
    p155_t('a re-run exits 0 and changes nothing', $r2['code'] === 0 && $before === [eo_val($p1, 'email_list_skip_member_statuses'), eo_val($p1, 'email_list_require_email_on_add')]);
    p155_t('...reporting the rows as already set', substr_count($r2['out'], 'already set') === 2);
    db()->exec("UPDATE `{$p1}settings` SET `value` = '[\"inactive\"]' WHERE `name` = 'email_list_skip_member_statuses'");
    db()->exec("UPDATE `{$p1}settings` SET `value` = '1' WHERE `name` = 'email_list_require_email_on_add'");
    $r3 = p155_run_php([$s1]);
    p155_t('values the administrator chose are NEVER overwritten by a re-run',
        $r3['code'] === 0 && (string) eo_val($p1, 'email_list_skip_member_statuses') === '["inactive"]' && (string) eo_val($p1, 'email_list_require_email_on_add') === '1');
    p155_t('...and exactly one row of each exists (no duplicate rows from repeated runs)',
        (int) db_fetch_value("SELECT COUNT(*) FROM `{$p1}settings` WHERE `name` IN ('email_list_skip_member_statuses','email_list_require_email_on_add')") === 2);

    echo "\n--- what gets seeded depends on what is installed ---\n";
    [$p2, $s2] = eo_new('b', ['Active', 'Retired']);
    p155_run_php([$s2]);
    p155_t('only "Retired" present -> ["retired"]', json_decode((string) eo_val($p2, 'email_list_skip_member_statuses'), true) === ['retired']);
    [$p3, $s3] = eo_new('c', ['Active', 'Inactive']);
    p155_run_php([$s3]);
    p155_t('neither present -> [] (never invents a label that does not exist)', json_decode((string) eo_val($p3, 'email_list_skip_member_statuses'), true) === []);
    [$p4, $s4] = eo_new('d', null);                       // no member_status table at all
    $r4 = p155_run_php([$s4]);
    p155_t('an install with no member_status table still migrates (exit 0, empty skip list) - self-sufficient in any order',
        $r4['code'] === 0 && json_decode((string) eo_val($p4, 'email_list_skip_member_statuses'), true) === [] && strpos($r4['out'], 'member_status not readable') !== false);

    echo "\n--- a migration that cannot make its outcome true must say so ---\n";
    [$p5, $s5] = eo_new('e', ['Retired']);
    db()->exec("INSERT INTO `{$p5}settings` (`name`, `value`) VALUES ('email_list_skip_member_statuses', 'this is not json')");
    $r5 = p155_run_php([$s5]);
    p155_t('an existing row that is not a JSON array -> exit NON-ZERO', $r5['code'] !== 0 && strpos($r5['out'], '[FAIL]') !== false);
    [$p6, $s6] = eo_new('f', ['Retired']);
    db()->exec("INSERT INTO `{$p6}settings` (`name`, `value`) VALUES ('email_list_require_email_on_add', 'maybe')");
    $r6 = p155_run_php([$s6]);
    p155_t('an existing require-email row that is not 0/1 -> exit NON-ZERO', $r6['code'] !== 0 && strpos($r6['out'], 'not 0/1') !== false);
    [$p7, $s7] = eo_new('g', ['Retired'], false);          // no settings table: the INSERT must fail loudly
    $r7 = p155_run_php([$s7]);
    p155_t('a failed write -> exit NON-ZERO with the failure on the output (never a printed failure with exit 0)', $r7['code'] !== 0 && strpos($r7['out'], '[FAIL]') !== false);

    echo "\n--- on this install ---\n";
    $live = $GLOBALS['db_prefix'] ?? '';
    p155_t('both options exist here (the migration has been applied)', db_fetch_value("SELECT COUNT(*) FROM `{$live}settings` WHERE `name` IN ('email_list_skip_member_statuses','email_list_require_email_on_add')") == 2);
    p155_t('...and the migration is discoverable by sql/run_migrations.php (run_*.php)', in_array('run_phase155_email_lists_options.php', array_map('basename', glob($base . '/sql/run_*.php')), true));
} finally {
    foreach ($prefixes as $p) eo_drop($p);
    foreach ($tmpDirs as $d) { @unlink($d . '/sql/run_phase155_email_lists_options.php'); @unlink($d . '/config.php'); @rmdir($d . '/sql'); @rmdir($d); }
}
$left = (int) db_fetch_value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?", ["p155eo_{$run}_%"]);
p155_t('the scratch tables are gone (verified by querying)', $left === 0);
p155_done();
