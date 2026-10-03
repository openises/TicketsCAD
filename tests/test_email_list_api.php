<?php
/**
 * Phase 155 (GH#145 PR2) - api/email-lists.php through the REAL endpoint
 * (tests/_p155_rules_probe.php, endpoint=email-lists: a real session, a real
 * csrf_token, no web server).
 *
 * Proves the authority model (action.manage_config only - Org Admin, Dispatcher and
 * Operator are refused on EVERY action; CSRF on every POST), the shape the Manage
 * modal reads (summary, per-entry status, problems first), the server-side pickers
 * (per type, with the has-email control, already-on-list / would-loop flags, the
 * `more` flag, escaped wildcards), the writers' rules reached through the endpoint
 * (duplicate, loop, wrong-list remove), the options, and that a missing table is a
 * 503 - never an empty list that reads as "no lists".
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_p155_notify_helpers.php';
require_once __DIR__ . '/../inc/email-list-write.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
$prefix = $GLOBALS['db_prefix'] ?? '';
foreach (['email_lists', 'email_list_members'] as $t) {
    try { db_fetch_value("SELECT 1 FROM `{$prefix}{$t}` LIMIT 1"); }
    catch (Throwable $e) { p155_skip("{$t} missing - run php sql/run_migrations.php"); }
}

p155_install_cleanup();
$auditFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}newui_audit_log`");
p155_on_cleanup("DELETE FROM `{$prefix}newui_audit_log` WHERE `id` > ? AND (`activity` LIKE 'email_list.%' OR `activity` LIKE 'notification_rule.%')", [$auditFloor]);
p155_remember_setting('email_list_skip_member_statuses');
p155_remember_setting('email_list_require_email_on_add');
p155_set_setting('email_list_skip_member_statuses', '[]');
p155_set_setting('email_list_require_email_on_add', '0');

echo "=== Phase 155 / GH#145 - api/email-lists.php ===\n\n";

$admin = test_admin_user_id();
$orgAdmin = p155_make_user(900155601, 'p155_el_orgadmin', 'p155.el.orgadmin@example.invalid', null, null, [2]);
$dispatcher = p155_make_user(900155602, 'p155_el_dispatcher', 'p155.el.dispatcher@example.invalid', null, null, [3]);
$operator = p155_make_user(900155603, 'p155_el_operator', 'p155.el.operator@example.invalid', null, null, [4]);

function el_api(string $method, string $action, int $uid, $payload = null): array
{
    return p155_api($method, $action, $uid, $payload, ['endpoint' => 'email-lists']);
}

echo "--- static: the gate ---\n";
$src = (string) file_get_contents(__DIR__ . '/../api/email-lists.php');
$hasIsAdmin = false;
foreach (token_get_all($src) as $tk) { if (is_array($tk) && $tk[0] === T_STRING && strtolower($tk[1]) === 'is_admin') $hasIsAdmin = true; }
p155_t("the endpoint gates on rbac_can('action.manage_config')", strpos($src, "rbac_can('action.manage_config')") !== false);
p155_t('...with NO is_admin() fallback (tokenized - the docblock mentions it)', !$hasIsAdmin);

echo "\n--- RBAC: refused on every action for Org Admin, Dispatcher, Operator ---\n";
$gets = ['list', 'detail', 'resolve', 'search_recipients', 'get_options'];
$posts = ['create', 'update', 'archive', 'add_member', 'remove_member', 'import_csv', 'save_options'];
$listsBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `name` LIKE 'P155%'");
foreach (['Org Admin' => $orgAdmin, 'Dispatcher' => $dispatcher, 'Operator' => $operator] as $label => $uid) {
    $all = true; $detail = '';
    foreach ($gets as $a) {
        $r = el_api('GET', $a, $uid, 'id=1&type=member&q=a');
        if ($r['status'] !== 403) { $all = false; $detail .= " GET {$a}={$r['status']}"; }
    }
    foreach ($posts as $a) {
        $r = el_api('POST', $a, $uid, ['id' => 1, 'list_id' => 1, 'name' => 'P155 should not exist', 'member_type' => 'inline', 'inline_email' => 'x@example.invalid', 'csv_text' => 'a@b.co']);
        if ($r['status'] !== 403) { $all = false; $detail .= " POST {$a}={$r['status']}"; }
    }
    p155_t("{$label}: every action answers 403" . ($detail !== '' ? " ({$detail} )" : ''), $all);
}
p155_t('...and no list was created by any refused call', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `name` LIKE 'P155%'") === $listsBefore);

echo "\n--- CSRF on every POST ---\n";
$r = el_api('POST', 'create', $admin, ['name' => 'P155 csrf', 'csrf_token' => 'bogus']);
p155_t('a bogus csrf_token -> 403', $r['status'] === 403);
$r = el_api('POST', 'create', $admin, ['name' => 'P155 csrf', 'csrf_token' => null]);
p155_t('a missing csrf_token -> 403', $r['status'] === 403);
p155_t('...nothing was created', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `name` = 'P155 csrf'") === 0);

echo "\n--- create, list ---\n";
$cr = el_api('POST', 'create', $admin, ['name' => 'P155 Api List', 'description' => 'p155 test']);
$listId = (int) ($cr['json']['id'] ?? 0);
if ($listId > 0) $GLOBALS['P155_FIX']['lists'][] = $listId;
p155_t('create answers 200 with the new id, name and slug', $cr['status'] === 200 && $listId > 0 && ($cr['json']['name'] ?? '') === 'P155 Api List' && !empty($cr['json']['slug']));
$blank = el_api('POST', 'create', $admin, ['name' => '   ']);
p155_t('a blank name is refused (400)', $blank['status'] === 400);

$m1 = p155_make_member('P155Api', 'Alpha', 'alpha@example.invalid', null, null, 'P1ALPHA');
$m2 = p155_make_member('P155Api', 'Bravo', null, null, null, 'P1BRAVO');            // no email
$m3 = p155_make_member('P155Api', 'Charlie', 'charlie@example.invalid');
$m4 = p155_make_member('P155Api', 'Delta', null);                                    // no email, never added in the first half
$c1 = p155_make_constituent('P155Api Contact One', 'contact1@example.invalid');
$c2 = p155_make_constituent('P155Api Contact Two', null);                            // no email
$sub = p155_make_list('P155 Api Sub');

foreach ([['member', ['ref_id' => $m1]], ['member', ['ref_id' => $m2]], ['constituent', ['ref_id' => $c1]], ['list', ['ref_id' => $sub]],
          ['inline', ['inline_email' => 'inline@example.invalid', 'display_name' => 'Inline Person']]] as [$type, $extra]) {
    $r = el_api('POST', 'add_member', $admin, ['list_id' => $listId, 'member_type' => $type] + $extra);
    p155_t("add_member type={$type} answers 200 with the entry id", $r['status'] === 200 && !empty($r['json']['id']));
}
$dupe = el_api('POST', 'add_member', $admin, ['list_id' => $listId, 'member_type' => 'member', 'ref_id' => $m1]);
p155_t('the same member again is refused as a duplicate (409)', $dupe['status'] === 409 && ($dupe['json']['code'] ?? '') === 'duplicate');
email_list_add_entry_internal($sub, 'inline', ['inline_email' => 'sub-only@example.invalid'], 0);

$lst = el_api('GET', 'list', $admin);
$row = null; foreach ($lst['json']['lists'] ?? [] as $l) if ($l['id'] === $listId) $row = $l;
p155_t('list returns entry, address and problem counts for each list', $row !== null && isset($row['entry_count'], $row['address_count'], $row['problem_count']));
p155_t('...5 entries; addresses = alpha + contact1 + inline + the sub-list\'s one = 4; the no-email member is the one problem',
    $row !== null && $row['entry_count'] === 5 && $row['address_count'] === 4 && $row['problem_count'] === 1);
p155_t('...and the Phase 41 member_count key is gone (nothing reads it any more)', $row !== null && !array_key_exists('member_count', $row));
p155_t('...description and slug are returned for the table', $row !== null && $row['description'] === 'p155 test' && $row['slug'] !== '');

echo "\n--- detail: what the Manage modal reads ---\n";
$d = el_api('GET', 'detail', $admin, 'id=' . $listId);
$members = $d['json']['members'] ?? [];
p155_t('detail returns the list, the summary and every entry', $d['status'] === 200 && !empty($d['json']['list']['name']) && !empty($d['json']['summary']) && count($members) === 5);
$sum = $d['json']['summary'] ?? [];
p155_t('...the summary says entries, unique addresses, problems and policy-excluded', ($sum['entries'] ?? null) === 5 && ($sum['unique_addresses'] ?? null) === 4 && ($sum['problems'] ?? null) === 1 && isset($sum['policy_excluded'], $sum['duplicates']));
p155_t('...problems come FIRST (the thing to fix is at the top)', ($members[0]['status'] ?? '') === 'no_email');
$byType = []; foreach ($members as $mm) $byType[$mm['member_type']] = $mm;
p155_t('...each row carries type, label, resolved email and status', ($byType['member']['label'] ?? '') !== '' && ($byType['inline']['resolved_email'] ?? '') === 'inline@example.invalid' && ($byType['inline']['status'] ?? '') === 'ok');
p155_t('...a sub-list row says how many addresses it contributes and which list it is', ($byType['list']['contributes'] ?? 0) === 1 && ($byType['list']['sub_list_id'] ?? 0) === $sub);
p155_t('...a member row carries the roster id (for the Fix in Roster link)', in_array($m1, array_map(static function ($x) { return $x['roster_id']; }, $members), true));
$noEmail = null; foreach ($members as $mm) if ($mm['status'] === 'no_email') $noEmail = $mm;
p155_t('...the problem row is the member with no address, named, with the roster id for the fix link', $noEmail !== null && $noEmail['member_type'] === 'member' && $noEmail['roster_id'] === $m2 && $noEmail['label'] !== '');
$notFound = el_api('GET', 'detail', $admin, 'id=987654321');
p155_t('detail of a list that does not exist is 404', $notFound['status'] === 404);

echo "\n--- resolve: the preview ---\n";
$rv = el_api('GET', 'resolve', $admin, 'id=' . $listId);
$emails = array_map(static function ($x) { return $x['email']; }, $rv['json']['recipients'] ?? []);
sort($emails);
p155_t('resolve returns the final recipient set', $rv['status'] === 200 && ($rv['json']['count'] ?? 0) === 4
    && $emails === ['alpha@example.invalid', 'contact1@example.invalid', 'inline@example.invalid', 'sub-only@example.invalid']);
$ex = $rv['json']['excluded'] ?? [];
p155_t('...AND the entries left out, named, with the reason code (a silent dead recipient is a bug)', count($ex) === 1 && $ex[0]['status'] === 'no_email' && $ex[0]['label'] !== '' && isset($ex[0]['entry_id']));

echo "\n--- search_recipients: the server-side pickers ---\n";
$s = el_api('GET', 'search_recipients', $admin, 'type=member&q=P155Api&list_id=' . $listId);
$ids = array_map(static function ($i) { return $i['id']; }, $s['json']['items'] ?? []);
p155_t('member search finds the fixture members (and only by what was typed)', $s['status'] === 200 && in_array($m1, $ids, true) && in_array($m3, $ids, true));
$items = []; foreach ($s['json']['items'] ?? [] as $i) $items[$i['id']] = $i;
p155_t('...a member already on the list is flagged and disabled with the reason', !empty($items[$m1]['already_in_list']) && $items[$m1]['disabled_reason'] === 'already on this list' && empty($items[$m3]['already_in_list']));
p155_t('...a member with no email says so in the sub-label', strpos($items[$m2]['sublabel'], 'no email on file') !== false && $items[$m2]['has_email'] === false);
p155_t('...and one with an email shows it', strpos($items[$m3]['sublabel'], 'charlie@example.invalid') !== false && $items[$m3]['has_email'] === true);
$withEmail = el_api('GET', 'search_recipients', $admin, 'type=member&q=P155Api&has_email=1&list_id=' . $listId)['json']['items'] ?? [];
$noFilter = el_api('GET', 'search_recipients', $admin, 'type=member&q=P155Api&has_email=0&list_id=' . $listId)['json']['items'] ?? [];
p155_t('CONTROL: has_email=1 returns fewer members than has_email=0 (the filter changes the answer: the two with no address drop out)', count($withEmail) === 2 && count($noFilter) === 4);
$cs = el_api('GET', 'search_recipients', $admin, 'type=constituent&q=P155Api&list_id=' . $listId)['json']['items'] ?? [];
$cs0 = el_api('GET', 'search_recipients', $admin, 'type=constituent&q=P155Api&has_email=0&list_id=' . $listId)['json']['items'] ?? [];
p155_t('constituent search defaults to ONLY contacts that have an email address', count($cs) === 1 && count($cs0) === 2);
p155_t('...and flags the one already on the list', !empty($cs[0]['already_in_list']) && $cs[0]['disabled_reason'] === 'already on this list');
$ls = el_api('GET', 'search_recipients', $admin, 'type=list&q=P155&list_id=' . $listId)['json']['items'] ?? [];
$lsNames = array_map(static function ($i) { return $i['label']; }, $ls);
p155_t('sub-list search excludes the list being edited', !in_array('P155 Api List', $lsNames, true) && in_array('P155 Api Sub', $lsNames, true));
$loop = el_api('GET', 'search_recipients', $admin, 'type=list&q=P155&list_id=' . $sub)['json']['items'] ?? [];
$loopRow = null; foreach ($loop as $i) if ($i['label'] === 'P155 Api List') $loopRow = $i;
p155_t('...and flags a list that would create a LOOP (editing the sub-list, the parent is offered as unusable)', $loopRow !== null && !empty($loopRow['would_cycle']) && $loopRow['disabled_reason'] === 'would create a loop');
$more = el_api('GET', 'search_recipients', $admin, 'type=member&q=P155Api&limit=2&list_id=' . $listId)['json'];
p155_t('`more` is true past the limit, and the page is capped at the limit', ($more['more'] ?? false) === true && count($more['items']) === 2);
$wildMember = p155_make_member('P155Wild%card', 'Under_score', 'wild@example.invalid');
$w1 = el_api('GET', 'search_recipients', $admin, 'type=member&q=' . rawurlencode('P155Wild%') . '&list_id=' . $listId)['json']['items'] ?? [];
$w2 = el_api('GET', 'search_recipients', $admin, 'type=member&q=' . rawurlencode('Under_s') . '&list_id=' . $listId)['json']['items'] ?? [];
$w3 = el_api('GET', 'search_recipients', $admin, 'type=member&q=' . rawurlencode('P155Api%') . '&list_id=' . $listId)['json']['items'] ?? [];
p155_t('LIKE wildcards in the query are escaped: % and _ match only themselves', count($w1) === 1 && count($w2) === 1 && count($w3) === 0);
p155_t('an unknown type is refused (400)', el_api('GET', 'search_recipients', $admin, 'type=banana&q=x')['status'] === 400);
p155_t('a member search result list is capped at 50 whatever the caller asks', count(el_api('GET', 'search_recipients', $admin, 'type=member&q=&limit=9999&has_email=0')['json']['items'] ?? []) <= 50);

echo "\n--- the writers' rules, reached through the endpoint ---\n";
$self = el_api('POST', 'add_member', $admin, ['list_id' => $listId, 'member_type' => 'list', 'ref_id' => $listId]);
p155_t('a list cannot contain itself (409)', $self['status'] === 409 && ($self['json']['code'] ?? '') === 'self');
$cyc = el_api('POST', 'add_member', $admin, ['list_id' => $sub, 'member_type' => 'list', 'ref_id' => $listId]);
p155_t('A -> B -> A is refused (409) and the message names the loop', $cyc['status'] === 409 && ($cyc['json']['code'] ?? '') === 'cycle' && stripos((string) ($cyc['json']['error'] ?? ''), 'P155 Api') !== false);
$entry = (int) db_fetch_value("SELECT `id` FROM `{$prefix}email_list_members` WHERE `list_id` = ? AND `member_type` = 'inline'", [$listId]);
$wrong = el_api('POST', 'remove_member', $admin, ['id' => $entry, 'list_id' => $sub]);
p155_t('remove_member with the WRONG list id deletes nothing (404)', $wrong['status'] === 404
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_list_members` WHERE `id` = ?", [$entry]) === 1);
$right = el_api('POST', 'remove_member', $admin, ['id' => $entry, 'list_id' => $listId]);
p155_t('...and with the right list id removes it', $right['status'] === 200
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_list_members` WHERE `id` = ?", [$entry]) === 0);
$up = el_api('POST', 'update', $admin, ['id' => $listId, 'name' => 'P155 Api List Renamed', 'description' => 'changed']);
p155_t('update renames and re-describes a list', $up['status'] === 200
    && (string) db_fetch_value("SELECT `name` FROM `{$prefix}email_lists` WHERE `id` = ?", [$listId]) === 'P155 Api List Renamed');
p155_t('update with an empty name is refused (400)', el_api('POST', 'update', $admin, ['id' => $listId, 'name' => ''])['status'] === 400);
$csv = el_api('POST', 'import_csv', $admin, ['list_id' => $listId, 'csv_text' => "csv1@example.invalid,Csv One\nnot an address\ncsv1@example.invalid\ncsv2@example.invalid"]);
p155_t('import_csv reports added / skipped / duplicates', $csv['status'] === 200 && ($csv['json']['added'] ?? -1) === 2 && ($csv['json']['skipped'] ?? -1) === 1 && ($csv['json']['duplicates'] ?? -1) === 1);

echo "\n--- archive refuses a list a rule depends on ---\n";
$ruleList = p155_make_list('P155 Api Used');
$ruleId = p155_create_rule(['name' => 'P155 api rule', 'event_type' => 'incident_create', 'channel' => 'email', 'email_list_id' => $ruleList, 'recipients' => []], $admin);
p155_t('fixture: a notification rule uses the list', $ruleId > 0);
$ar = el_api('POST', 'archive', $admin, ['id' => $ruleList]);
p155_t('archive of a list a rule uses is refused (409, in_use) and names the rule', $ar['status'] === 409 && ($ar['json']['code'] ?? '') === 'in_use' && !empty($ar['json']['rules']));
p155_t('...and the list is still active', db_fetch_value("SELECT `archived_at` FROM `{$prefix}email_lists` WHERE `id` = ?", [$ruleList]) === null);
$forced = el_api('POST', 'archive', $admin, ['id' => $ruleList, 'force' => true]);
p155_t('...force archives it anyway', $forced['status'] === 200 && db_fetch_value("SELECT `archived_at` FROM `{$prefix}email_lists` WHERE `id` = ?", [$ruleList]) !== null);
$plain = p155_make_list('P155 Api Plain');
p155_t('an unused list archives without force', el_api('POST', 'archive', $admin, ['id' => $plain])['status'] === 200);
$listed = array_map(static function ($l) { return $l['id']; }, el_api('GET', 'list', $admin)['json']['lists'] ?? []);
p155_t('archived lists leave the list table', !in_array($ruleList, $listed, true) && !in_array($plain, $listed, true));

echo "\n--- options ---\n";
$go = el_api('GET', 'get_options', $admin);
p155_t('get_options returns both options and the member-status labels to choose from', $go['status'] === 200 && isset($go['json']['skip_statuses'], $go['json']['require_email_on_add']) && is_array($go['json']['status_labels']));
p155_t('save_options rejects a status label that does not exist (400)', el_api('POST', 'save_options', $admin, ['skip_statuses' => ['No Such Status P155']])['status'] === 400);
$st = p155_make_status('P155 Retired');
$so = el_api('POST', 'save_options', $admin, ['skip_statuses' => ['P155 Retired'], 'require_email_on_add' => true]);
p155_t('save_options saves valid values (200)', $so['status'] === 200 && ($so['json']['skip_statuses'] ?? []) === ['p155 retired'] && ($so['json']['require_email_on_add'] ?? null) === true);
p155_t('...the next read returns them (uncached)', email_list_options()['skip_statuses'] === ['p155 retired'] && email_list_options()['require_email_on_add'] === true);
$au = json_decode((string) db_fetch_value("SELECT `details` FROM `{$prefix}newui_audit_log` WHERE `activity` = 'email_list.options' ORDER BY `id` DESC LIMIT 1"), true);
p155_t('...and the audit row records old -> new', isset($au['skip_statuses']['from'], $au['skip_statuses']['to'], $au['require_email_on_add']['to']) && $au['require_email_on_add']['to'] === true);
$strict = el_api('POST', 'add_member', $admin, ['list_id' => $listId, 'member_type' => 'member', 'ref_id' => $m3]);
$strict2 = el_api('POST', 'add_member', $admin, ['list_id' => $listId, 'member_type' => 'member', 'ref_id' => $m4]);
p155_t('CONTROL: with require-email on, a member WITH an email is added and one WITHOUT is refused (422)', $strict['status'] === 200 && $strict2['status'] === 422);
$sr = el_api('GET', 'search_recipients', $admin, 'type=member&q=P155Api&has_email=0&list_id=' . $listId)['json']['items'] ?? [];
$noEmailRow = null; foreach ($sr as $i) if ($i['id'] === $m4) $noEmailRow = $i;
p155_t('...and the picker shows that member disabled, with the reason', $noEmailRow !== null && strpos($noEmailRow['disabled_reason'], 'requires one') !== false);

echo "\n--- a missing table is a 503, never an empty list ---\n";
$bak = $prefix . 'email_lists_p155bak';
db_query("DROP TABLE IF EXISTS `{$bak}`");
register_shutdown_function(static function () use ($prefix, $bak) {
    try {
        $has = (int) db_fetch_value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$bak]);
        $orig = (int) db_fetch_value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'email_lists']);
        if ($has && !$orig) db_query("RENAME TABLE `{$bak}` TO `{$prefix}email_lists`");
    } catch (Throwable $e) { /* nothing more we can do at shutdown */ }
});
db_query("RENAME TABLE `{$prefix}email_lists` TO `{$bak}`");
try {
    $m503 = el_api('GET', 'list', $admin);
    $d503 = el_api('GET', 'detail', $admin, 'id=' . $listId);
} finally {
    db_query("RENAME TABLE `{$bak}` TO `{$prefix}email_lists`");
}
p155_t('list with the table missing answers 503 with a repair hint', $m503['status'] === 503 && stripos((string) ($m503['json']['error'] ?? ''), 'run php sql/run_migrations.php') !== false && ($m503['json']['code'] ?? '') === 'tables_missing');
p155_t('detail with the table missing answers 503 too', $d503['status'] === 503);
p155_t('...and the table is back', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `id` = ?", [$listId]) === 1);

echo "\n--- hygiene ---\n";
$views = (string) file_get_contents(__DIR__ . '/../inc/email-list-views.php');
p155_t('no empty catch block in the API or the views', !preg_match('/catch\s*\([^)]*\)\s*\{\s*\}/', $src) && !preg_match('/catch\s*\([^)]*\)\s*\{\s*\}/', $views));
p155_t('no driver text reaches a response from the API file (getMessage never feeds a reply)', !preg_match('/json_(?:response|error)\([^;]*getMessage\(\)/s', $src));
p155_t('the views file reads the audited-by names (added_by is no longer a write-only column)', strpos($views, 'added_by') !== false);

p155_cleanup();
p155_t('fixtures are gone after cleanup (verified by querying)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `name` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}member` WHERE `first_name` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents` WHERE `contact` LIKE 'P155%'") === 0);
p155_done();
