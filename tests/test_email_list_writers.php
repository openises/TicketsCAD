<?php
/**
 * Phase 155 (GH#145) - the email list writers (inc/email-list-write.php).
 *
 * Drives the REAL writer functions. Covers the guards the old inline code in
 * api/email-lists.php lacked: a loop (A -> B -> A) was accepted, nothing checked
 * a referenced member/contact/list existed or that a member was not deleted,
 * the same recipient could be added repeatedly, `update` could blank a list's
 * name, `remove_member` took only a row id (a stale modal could delete a row
 * from a different list), `archive` ignored the rules that depend on the list,
 * and not one mutation was audited.
 *
 * Also the CONTROL for email_list_require_email_on_add: flipping the option
 * changes whether a member with no email is refused or added-with-a-warning.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_notify_helpers.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
$prefix = $GLOBALS['db_prefix'] ?? '';
try { db_fetch_value("SELECT 1 FROM `{$prefix}email_lists` LIMIT 1"); }
catch (Throwable $e) { p155_skip('email_lists table missing - run php sql/run_migrations.php'); }

require_once __DIR__ . '/../inc/email-list-write.php';
p155_install_cleanup();
// Audit rows with a non-list target (the options audit) are not cleaned per list;
// remember where the audit table ended so everything this run added is removed.
$auditFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}newui_audit_log`");
p155_on_cleanup("DELETE FROM `{$prefix}newui_audit_log` WHERE `activity` LIKE 'email_list.%' AND `id` > ?", [$auditFloor]);

echo "=== Phase 155 / GH#145 - email list writers ===\n\n";

echo "--- list create / update ---\n";
$r = email_list_create_internal('P155 Writers A', null, 'desc', 0);
p155_t('create succeeds and returns an id + slug', !empty($r['ok']) && $r['id'] > 0 && $r['slug'] === 'p155-writers-a');
$A = (int) $r['id']; $GLOBALS['P155_FIX']['lists'][] = $A;
p155_t('create wrote exactly one audit row', p155_audit_count('email_list.create', $A) === 1);
$r2 = email_list_create_internal('P155 Writers A', null, null, 0);
p155_t('a second list with the same NAME gets a distinct auto-suffixed slug (not a 500)',
    !empty($r2['ok']) && $r2['slug'] === 'p155-writers-a-2');
$GLOBALS['P155_FIX']['lists'][] = (int) $r2['id'];
$r3 = email_list_create_internal('P155 Writers Other', 'p155-writers-a', null, 0);
p155_t('an EXPLICIT duplicate slug is refused with code slug_exists', empty($r3['ok']) && $r3['code'] === 'slug_exists');
p155_t('an empty name is refused', email_list_create_internal('   ', null, null, 0)['code'] === 'bad_request');

$u = email_list_update_internal($A, ['name' => '   '], 0);
p155_t('update with an EMPTY name is refused (the old code blanked the name)', empty($u['ok']) && $u['code'] === 'bad_request');
p155_t('...and the stored name is unchanged',
    db_fetch_value("SELECT `name` FROM `{$prefix}email_lists` WHERE `id` = ?", [$A]) === 'P155 Writers A');
$u = email_list_update_internal($A, ['name' => 'P155 Writers A2', 'description' => 'new'], 0);
p155_t('a real update succeeds', !empty($u['ok']));
p155_t('update wrote exactly one audit row', p155_audit_count('email_list.update', $A) === 1);
p155_t('updating a list that does not exist is list_missing',
    email_list_update_internal(987654321, ['name' => 'x'], 0)['code'] === 'list_missing');

echo "\n--- add_entry: all four types ---\n";
$B = p155_make_list('P155 Writers B');
$mem = p155_make_member('P155W', 'Member', 'p155w.member@example.invalid');
$con = p155_make_constituent('P155W Contact', 'p155w.contact@example.invalid');
$r = email_list_add_entry_internal($A, 'member', ['ref_id' => $mem], 0);
p155_t('add a member', !empty($r['ok']) && $r['id'] > 0 && $r['warnings'] === []);
$entryMember = (int) $r['id'];
$r = email_list_add_entry_internal($A, 'constituent', ['ref_id' => $con], 0);
p155_t('add a constituent (contact)', !empty($r['ok']));
$r = email_list_add_entry_internal($A, 'inline', ['inline_email' => 'P155W.Inline@Example.invalid', 'display_name' => 'Ian'], 0);
p155_t('add an inline address', !empty($r['ok']));
$r = email_list_add_entry_internal($A, 'list', ['ref_id' => $B], 0);
p155_t('add a sub-list', !empty($r['ok']));
p155_t('each add wrote exactly one audit row (4)', p155_audit_count('email_list.add_entry', $A) === 4);
p155_t('the inline address is stored as typed (case preserved)',
    (string) db_fetch_value("SELECT `inline_email` FROM `{$prefix}email_list_members` WHERE `list_id` = ? AND `member_type` = 'inline'", [$A])
        === 'P155W.Inline@Example.invalid');

echo "\n--- add_entry: guards ---\n";
p155_t('the same member again is refused as duplicate',
    email_list_add_entry_internal($A, 'member', ['ref_id' => $mem], 0)['code'] === 'duplicate');
p155_t('the same constituent again is refused as duplicate',
    email_list_add_entry_internal($A, 'constituent', ['ref_id' => $con], 0)['code'] === 'duplicate');
p155_t('the same sub-list again is refused as duplicate',
    email_list_add_entry_internal($A, 'list', ['ref_id' => $B], 0)['code'] === 'duplicate');
p155_t('the same inline address in a DIFFERENT CASE is refused as duplicate',
    email_list_add_entry_internal($A, 'inline', ['inline_email' => 'p155w.inline@example.invalid'], 0)['code'] === 'duplicate');
p155_t('rejected duplicates left exactly four entries',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_list_members` WHERE `list_id` = ?", [$A]) === 4);

$self = email_list_add_entry_internal($A, 'list', ['ref_id' => $A], 0);
p155_t('A -> A is refused (self)', empty($self['ok']) && $self['code'] === 'self');

// B is already under A. Putting A under B closes a loop: A -> B -> A.
$loop = email_list_add_entry_internal($B, 'list', ['ref_id' => $A], 0);
p155_t('A -> B -> A is refused as a cycle', empty($loop['ok']) && $loop['code'] === 'cycle');
p155_t('...and the message NAMES the loop path',
    strpos((string) $loop['message'], 'P155 Writers A2') !== false && strpos((string) $loop['message'], '->') !== false);
$C = p155_make_list('P155 Writers C');
email_list_add_entry_internal($B, 'list', ['ref_id' => $C], 0);       // A -> B -> C
$loop2 = email_list_add_entry_internal($C, 'list', ['ref_id' => $A], 0); // C -> A would close A->B->C->A
p155_t('a three-list loop (A -> B -> C -> A) is also refused', empty($loop2['ok']) && $loop2['code'] === 'cycle');
p155_t('control: a non-looping sub-list (D under C) IS accepted',
    !empty(email_list_add_entry_internal($C, 'list', ['ref_id' => p155_make_list('P155 Writers D')], 0)['ok']));

p155_t('a member that does not exist -> ref_missing',
    email_list_add_entry_internal($A, 'member', ['ref_id' => 987654321], 0)['code'] === 'ref_missing');
p155_t('a constituent that does not exist -> ref_missing',
    email_list_add_entry_internal($A, 'constituent', ['ref_id' => 987654321], 0)['code'] === 'ref_missing');
p155_t('a sub-list that does not exist -> ref_missing',
    email_list_add_entry_internal($A, 'list', ['ref_id' => 987654321], 0)['code'] === 'ref_missing');
$gone = p155_make_member('P155W', 'Deleted', 'p155w.gone@example.invalid');
member_soft_delete($gone, 0);
$r = email_list_add_entry_internal($A, 'member', ['ref_id' => $gone], 0);
p155_t('a soft-deleted member -> ref_missing', empty($r['ok']) && $r['code'] === 'ref_missing');
p155_t('an invalid inline address -> invalid_email',
    email_list_add_entry_internal($A, 'inline', ['inline_email' => "bad\r\nBcc: x@y.z"], 0)['code'] === 'invalid_email');
p155_t('an unknown type -> bad_request',
    email_list_add_entry_internal($A, 'bogus', ['ref_id' => 1], 0)['code'] === 'bad_request');
p155_t('a list that does not exist -> list_missing',
    email_list_add_entry_internal(987654321, 'inline', ['inline_email' => 'a@b.co'], 0)['code'] === 'list_missing');

$arch = p155_make_list('P155 Writers Archived');
db_query("UPDATE `{$prefix}email_lists` SET `archived_at` = NOW() WHERE `id` = ?", [$arch]);
p155_t('adding an ARCHIVED list as a sub-list is refused',
    email_list_add_entry_internal($A, 'list', ['ref_id' => $arch], 0)['code'] === 'list_archived');
p155_t('adding anything TO an archived list is refused',
    email_list_add_entry_internal($arch, 'inline', ['inline_email' => 'a@b.co'], 0)['code'] === 'list_archived');

echo "\n--- CONTROL: email_list_require_email_on_add changes behaviour ---\n";
$noMail = p155_make_member('P155W', 'Nomail', null);
$noMail2 = p155_make_member('P155W', 'Nomail2', null);
p155_set_setting(EMAIL_LIST_SETTING_REQUIRE_EMAIL, '0');
$r = email_list_add_entry_internal($A, 'member', ['ref_id' => $noMail], 0);
p155_t('option 0: a member with no email is ADDED...', !empty($r['ok']));
p155_t('...carrying the warning no_email (so the row shows a persistent badge)', $r['warnings'] === ['no_email']);
p155_set_setting(EMAIL_LIST_SETTING_REQUIRE_EMAIL, '1');
$r = email_list_add_entry_internal($A, 'member', ['ref_id' => $noMail2], 0);
p155_t('option 1: the SAME situation is refused (no_email)', empty($r['ok']) && $r['code'] === 'no_email');
p155_t('...and nothing was inserted',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_list_members` WHERE `list_id` = ? AND `member_type` = 'member' AND `ref_id` = ?", [$A, $noMail2]) === 0);
$conNoMail = p155_make_constituent('P155W Phoneonly', null);
p155_t('option 1 also refuses a contact with no email',
    email_list_add_entry_internal($A, 'constituent', ['ref_id' => $conNoMail], 0)['code'] === 'no_email');
p155_t('option 1 does NOT refuse a member that has an email', !empty(email_list_add_entry_internal($B, 'member', ['ref_id' => $mem], 0)['ok']));
p155_set_setting(EMAIL_LIST_SETTING_REQUIRE_EMAIL, '0');

echo "\n--- remove_entry ---\n";
$wrong = email_list_remove_entry_internal($entryMember, $B, 0);
p155_t('removing an entry naming the WRONG list deletes nothing (entry_missing)', empty($wrong['ok']) && $wrong['code'] === 'entry_missing');
p155_t('...the row is still there',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_list_members` WHERE `id` = ?", [$entryMember]) === 1);
$ok = email_list_remove_entry_internal($entryMember, $A, 0);
p155_t('removing with the right list succeeds', !empty($ok['ok']));
p155_t('...the row is gone',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_list_members` WHERE `id` = ?", [$entryMember]) === 0);
p155_t('remove wrote exactly one audit row', p155_audit_count('email_list.remove_entry', $A) === 1);

echo "\n--- archive honours the rules that use the list ---\n";
$E = p155_make_list('P155 Writers InUse');
// No writer for notification_rules exists until the rules API (GH#144) lands; a
// raw INSERT is how a rule comes into being on this install today.
db_query("INSERT INTO `{$prefix}notification_rules` (`name`, `event_type`, `channel`, `email_list_id`, `active`)
          VALUES ('P155 InUse Rule', 'incident_create', 'email', ?, 1)", [$E]);
$ruleId = (int) db_fetch_value("SELECT LAST_INSERT_ID()");
$GLOBALS['P155_FIX']['rules'][] = $ruleId;
$r = email_list_archive_internal($E, false, 0);
p155_t('archiving a list a rule uses -> in_use, naming the rule', empty($r['ok']) && $r['code'] === 'in_use'
    && strpos((string) $r['message'], 'P155 InUse Rule') !== false);
p155_t('...and the list is NOT archived',
    db_fetch_value("SELECT `archived_at` FROM `{$prefix}email_lists` WHERE `id` = ?", [$E]) === null);
$r = email_list_archive_internal($E, true, 0);
p155_t('with force it archives', !empty($r['ok']));
p155_t('a forced archive is audited at MEDIUM severity',
    (int) db_fetch_value("SELECT `severity` FROM `{$prefix}newui_audit_log` WHERE `activity` = 'email_list.archive' AND `target_id` = ? ORDER BY `id` DESC LIMIT 1", [(string) $E]) === AUDIT_MEDIUM);
$F = p155_make_list('P155 Writers Free');
p155_t('a list no rule uses archives without force', !empty(email_list_archive_internal($F, false, 0)['ok']));

echo "\n--- CSV import ---\n";
$G = p155_make_list('P155 Writers Csv');
$csv = "# comment\n" .
       "p155w.csv1@example.invalid, One\n" .
       "P155W.CSV1@example.invalid\n" .       // duplicate of line 1 (case-insensitive)
       "not an address\n" .
       "\n" .
       "p155w.csv2@example.invalid\n";
$r = email_list_import_csv_internal($G, $csv, 0);
p155_t('CSV import: 2 added, 1 duplicate, 1 skipped',
    !empty($r['ok']) && $r['added'] === 2 && $r['duplicates'] === 1 && $r['skipped'] === 1);
$again = email_list_import_csv_internal($G, "p155w.csv1@example.invalid\n", 0);
p155_t('re-importing an address already on the list is a duplicate, not an added row', $again['added'] === 0 && $again['duplicates'] === 1);
p155_t('CSV import wrote ONE audit row per import (2 imports)', p155_audit_count('email_list.import_csv', $G) === 2);
p155_t('CSV import to a missing list -> list_missing', email_list_import_csv_internal(987654321, "a@b.co\n", 0)['code'] === 'list_missing');

echo "\n--- options ---\n";
$labels = email_list_member_status_labels();
p155_t('status labels come back distinct (case-insensitive)', count($labels) === count(array_unique(array_map('strtolower', $labels))));
$bad = email_list_save_options_internal(['skip_statuses' => ['no such status label']], 0);
p155_t('save_options rejects an unknown status label', empty($bad['ok']) && $bad['code'] === 'bad_request');
$known = $labels ? $labels[0] : null;
if ($known !== null) {
    $good = email_list_save_options_internal(['skip_statuses' => [$known], 'require_email_on_add' => true], 0);
    p155_t('save_options accepts a known label and the require flag', !empty($good['ok']));
    $o = email_list_options();
    p155_t('...and email_list_options() reads exactly what was written',
        $o['skip_statuses'] === [strtolower($known)] && $o['require_email_on_add'] === true);
    $row = db_fetch_one("SELECT `details` FROM `{$prefix}newui_audit_log` WHERE `activity` = 'email_list.options' ORDER BY `id` DESC LIMIT 1");
    $det = json_decode((string) ($row['details'] ?? ''), true);
    p155_t('...and the audit row records old -> new', isset($det['require_email_on_add']['from'], $det['require_email_on_add']['to'])
        && $det['require_email_on_add']['to'] === true);
}
// restore the settings the test touched
p155_set_setting(EMAIL_LIST_SETTING_REQUIRE_EMAIL, '0');

echo "\n--- hygiene ---\n";
$src = file_get_contents(__DIR__ . '/../inc/email-list-write.php');
p155_t('no returned message embeds driver text (getMessage() never reaches _email_list_fail)',
    !preg_match('/_email_list_fail\([^;]*getMessage\(\)/s', $src));
p155_t('no empty catch block remains in the writer file',
    !preg_match('/catch\s*\([^)]*\)\s*\{\s*\}/', $src));

p155_cleanup();
$left = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `name` LIKE 'P155 Writers%'");
p155_t('fixtures are gone after cleanup (verified by querying)', $left === 0);
$leftAudit = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `activity` LIKE 'email_list.%' AND `target_type` = 'email_list'
    AND `target_id` IN (" . ($A ? (string) $A : '0') . ")");
p155_t('audit rows for the fixture lists were cleaned too', $leftAudit === 0);

p155_done();
