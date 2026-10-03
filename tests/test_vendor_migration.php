<?php
/**
 * GH#148 (Phase 155) -- the migrations, run for real.
 *
 * sql/run_gh148_vendor_dispatch.php must be idempotent (a second run changes nothing), must leave the six tables, the three
 * real unique keys, the four seeded service types, the five setting rows and the two permissions with the right tier and the right
 * grants -- and must EXIT NON-ZERO if its outcome is not there (a migration that catches its own failure and exits 0 is a
 * migration that never ran). The leak check is exercised for real: Dispatcher is deliberately handed the tier-1 code, the
 * migration must refuse (exit 1), and once the leak is removed it must pass again.
 *
 * sql/run_gh148_vendor_captions.php seeds every vendor.* caption key and verifies it did.
 *
 * Usage: php tests/test_vendor_migration.php
 */
require_once __DIR__ . '/_vendor_fixtures.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

$prefix = vf_prefix();
$root = dirname(__DIR__);

function run_php(array $argv): array
{
    $root = dirname(__DIR__);
    $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return [-1, '', ''];
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}

$php = PHP_BINARY ?: 'php';
$mig = $root . '/sql/run_gh148_vendor_dispatch.php';
$permIds = function () use ($prefix) {
    return array_column(db_fetch_all("SELECT `id`, `code` FROM `{$prefix}permissions` WHERE `code` IN ('action.dispatch_vendor','action.manage_vendors') ORDER BY `code`"), 'id', 'code');
};

echo "=== GH#148 -- migrations ===\n\n";

// ── idempotence ──────────────────────────────────────────────
echo "--- run twice ---\n";
[$c1, $o1, $e1] = run_php([$php, $mig]);
t('the migration exits 0', $c1 === 0);
t('it reports no failure', strpos($o1, '[FAIL]') === false && $e1 === '');
$snap = function () use ($prefix) {
    return [
        'types'  => (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_service_types`"),
        'perms'  => (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}permissions` WHERE `code` IN ('action.dispatch_vendor','action.manage_vendors')"),
        'grants' => (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}role_permissions` rp JOIN `{$prefix}permissions` p ON p.id = rp.permission_id WHERE p.`code` IN ('action.dispatch_vendor','action.manage_vendors')"),
        'sets'   => (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` IN ('vendor_dispatch_enabled','vendor_rotation_mode','vendor_advance_rule','vendor_allow_override','vendor_override_requires_reason')"),
    ];
};
$before = $snap();
[$c2, $o2, $e2] = run_php([$php, $mig]);
t('a second run also exits 0', $c2 === 0);
t('...and changes nothing (same service types, permissions, grants and setting rows)', $snap() === $before);
t('the second run says the tables already exist', substr_count($o2, 'already present') >= 6);

// ── the outcome ──────────────────────────────────────────────
echo "\n--- the outcome ---\n";
foreach (vendor_table_names() as $tbl) {
    t("table $tbl exists", (int) db_fetch_value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . $tbl]) === 1);
}
t('vendor_schema_ready() agrees', vendor_schema_ready(true));
$uniq = function (string $tbl, string $idx) use ($prefix) {
    $rows = db_fetch_all("SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX", [$prefix . $tbl, $idx]);
    return $rows ? [implode(',', array_column($rows, 'COLUMN_NAME')), (int) $rows[0]['NON_UNIQUE']] : null;
};
t('uk_vendor_service_code is UNIQUE on (code)', $uniq('vendor_service_types', 'uk_vendor_service_code') === ['code', 0]);
t('uk_list_provider is UNIQUE on (list_id, provider_id)', $uniq('vendor_rotation_members', 'uk_list_provider') === ['list_id,provider_id', 0]);
t('uk_ticket_ordinal is UNIQUE on (ticket_id, ordinal)', $uniq('vendor_dispatches', 'uk_ticket_ordinal') === ['ticket_id,ordinal', 0]);
$colNullable = function (string $tbl, string $col) use ($prefix) {
    return db_fetch_value("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$prefix . $tbl, $col]);
};
t('every column in those unique keys is NOT NULL (a UNIQUE key ending in a NULLable column constrains nothing)',
    $colNullable('vendor_service_types', 'code') === 'NO' && $colNullable('vendor_rotation_members', 'list_id') === 'NO' && $colNullable('vendor_rotation_members', 'provider_id') === 'NO'
    && $colNullable('vendor_dispatches', 'ticket_id') === 'NO' && $colNullable('vendor_dispatches', 'ordinal') === 'NO');
$codes = array_column(db_fetch_all("SELECT `code` FROM `{$prefix}vendor_service_types` ORDER BY `sort_order`"), 'code');
t('the four service types are seeded in order', array_slice($codes, 0, 4) === ['tow', 'lockout', 'jumpstart', 'tire_change']);
t('only Tow asks for a destination', db_fetch_all("SELECT `code` FROM `{$prefix}vendor_service_types` WHERE `needs_destination` = 1 AND `code` IN ('tow','lockout','jumpstart','tire_change')") === [['code' => 'tow']]);
t('every setting row exists', $before['sets'] === 5);
$enabledRaw = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'vendor_dispatch_enabled'");
t('the enable switch is a plain 0/1 row', in_array($enabledRaw, ['0', '1'], true));
$pids = $permIds();
t('both permissions exist', count($pids) === 2);
t('dispatch is tier 0 and manage is tier 1', (int) db_fetch_value("SELECT `admin_only` FROM `{$prefix}permissions` WHERE `code` = 'action.dispatch_vendor'") === 0
    && (int) db_fetch_value("SELECT `admin_only` FROM `{$prefix}permissions` WHERE `code` = 'action.manage_vendors'") === 1);
$grantCount = function (string $code, array $roles) use ($prefix) {
    $ph = implode(',', array_fill(0, count($roles), '?'));
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}role_permissions` rp JOIN `{$prefix}permissions` p ON p.id = rp.permission_id WHERE p.`code` = ? AND rp.`role_id` IN ($ph)", array_merge([$code], $roles));
};
t('dispatch is held by roles 1, 2 and 3', $grantCount('action.dispatch_vendor', [1, 2, 3]) === 3);
t('manage is held by roles 1 and 2 only', $grantCount('action.manage_vendors', [1, 2]) === 2 && $grantCount('action.manage_vendors', [3, 4, 5, 6, 7]) === 0);

// ── the leak check really refuses ────────────────────────────
echo "\n--- the migration refuses a leaked tier-1 grant ---\n";
$mid = (int) $pids['action.manage_vendors'];
db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (3, ?)", [$mid]);
[$cl, $ol, $el] = run_php([$php, $mig]);
t('with Dispatcher holding action.manage_vendors the migration EXITS NON-ZERO and names the leak', $cl !== 0 && strpos($ol . $el, 'Dispatcher (role 3) holds action.manage_vendors DIRECTLY') !== false);
db_query("DELETE FROM `{$prefix}role_permissions` WHERE `role_id` = 3 AND `permission_id` = ?", [$mid]);
[$cl2] = run_php([$php, $mig]);
t('once the leak is removed it passes again', $cl2 === 0 && $grantCount('action.manage_vendors', [3]) === 0);

// ── a missing grant is repaired AND the outcome re-verified ──
echo "\n--- a missing grant is restored ---\n";
$did = (int) $pids['action.dispatch_vendor'];
db_query("DELETE FROM `{$prefix}role_permissions` WHERE `role_id` = 3 AND `permission_id` = ?", [$did]);
[$cr] = run_php([$php, $mig]);
t('a Dispatcher that lost action.dispatch_vendor gets it back, and the run exits 0', $cr === 0 && $grantCount('action.dispatch_vendor', [3]) === 1);

// ── the migration never lowers an existing classification and is CLI-only ──
echo "\n--- guards ---\n";
$src = file_get_contents($mig);
t('the CLI-only guard is the first executable statement', preg_match('/^<\?php\s*\/\*\*.*?\*\/\s*if \(PHP_SAPI !== \'cli\'\)/s', $src) === 1);
t('it verifies its own outcome and exits non-zero', strpos($src, "exit(1)") !== false && strpos($src, 'Verify the OUTCOME') !== false);
t('it never ALTERs an existing table (no change to ticket)', !preg_match('/ALTER TABLE `\{\$prefix\}ticket`/i', $src));

// ── captions ─────────────────────────────────────────────────
echo "\n--- captions migration ---\n";
[$cc, $co, $ce] = run_php([$php, $root . '/sql/run_gh148_vendor_captions.php']);
t('the captions migration exits 0 and checked 70+ keys', $cc === 0 && preg_match('/\((\d+) keys checked\)/', $co, $m) === 1 && (int) $m[1] >= 70);
[$cc2, $co2] = run_php([$php, $root . '/sql/run_gh148_vendor_captions.php']);
t('a re-run seeds nothing new (INSERT IGNORE: an administrator\'s own wording is never overwritten)', $cc2 === 0 && strpos($co2, 'done: 0 new caption row(s)') !== false);
db_query("UPDATE `{$prefix}captions_i18n` SET `value` = 'Wrecker dispatch (custom)' WHERE `caption_key` = 'vendor.modal.title' AND `lang` = 'en'");
run_php([$php, $root . '/sql/run_gh148_vendor_captions.php']);
t('...an administrator\'s renamed caption survives the re-run', db_fetch_value("SELECT `value` FROM `{$prefix}captions_i18n` WHERE `caption_key` = 'vendor.modal.title' AND `lang` = 'en'") === 'Wrecker dispatch (custom)');
db_query("UPDATE `{$prefix}captions_i18n` SET `value` = 'Dispatch Towing / Roadside' WHERE `caption_key` = 'vendor.modal.title' AND `lang` = 'en'");

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
