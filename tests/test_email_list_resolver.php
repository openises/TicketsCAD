<?php
/**
 * Phase 155 (GH#145) - the shared email-list resolver (inc/email-lists.php).
 *
 * Part 1 drives the PURE expander (email_list_expand) with synthetic graphs: no
 * database, every status code, the cycle/depth/diamond guards, dedupe, naming.
 * Part 2 builds real lists through the REAL writers and resolves them through
 * the real loader, including the two CONTROLS that prove a setting/option
 * changes observable output (a setting that nothing reads is a bug):
 *   - email_list_skip_member_statuses, using member_status rows that share a
 *     LABEL (the table has no unique key and real installs hold duplicates)
 *   - honor_email_opt_out, using a member whose linked user switched email off
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_notify_helpers.php';
require_once __DIR__ . '/../inc/email-lists.php';

echo "=== Phase 155 / GH#145 - email list resolver ===\n\n";

// ── Part 1: pure ────────────────────────────────────────────────────────
echo "--- email_list_split_addresses ---\n";
$s = email_list_split_addresses('a@x.org, b@x.org;  c@x.org');
p155_t('comma, semicolon and whitespace all split', $s['valid'] === ['a@x.org', 'b@x.org', 'c@x.org']);
$s = email_list_split_addresses('Bob@X.org bob@x.org');
p155_t('addresses differing only by case collapse to one', count($s['valid']) === 1);
$s = email_list_split_addresses('n/a');
p155_t('"n/a" is not an address', $s['valid'] === [] && $s['invalid'] === ['n/a']);
$s = email_list_split_addresses("a@x.org\r\nBcc: evil@x.org");
p155_t('a CRLF header-injection attempt yields no injected recipient token containing CR/LF',
    (function ($v) { foreach ($v as $a) if (preg_match('/[\r\n]/', $a)) return false; return true; })($s['valid']));
$s = email_list_split_addresses('<jane@x.org>');
p155_t('angle brackets around an address are tolerated', $s['valid'] === ['jane@x.org']);
p155_t('an empty string yields nothing', email_list_split_addresses('') === ['valid' => [], 'invalid' => []]);

echo "\n--- email_list_expand on synthetic graphs ---\n";
function eg_entry(int $id, int $listId, string $type, int $ref = 0, string $inline = '', string $name = ''): array {
    return ['id' => $id, 'list_id' => $listId, 'member_type' => $type, 'ref_id' => $ref,
            'inline_email' => $inline, 'display_name' => $name, 'added_by' => 0, 'added_at' => ''];
}
function eg_list(int $id, string $name, bool $archived = false): array {
    return ['id' => $id, 'name' => $name, 'slug' => strtolower($name), 'archived' => $archived];
}
function eg_member(int $id, string $name, string $email, array $x = []): array {
    return array_merge(['id' => $id, 'name' => $name, 'callsign' => '', 'email' => $email, 'deleted' => false,
        'user_id' => 0, 'status' => 'Active', 'opted_out' => false], $x);
}

$g = ['missing_tables' => false,
    'lists' => [1 => eg_list(1, 'Root'), 2 => eg_list(2, 'Sub'), 3 => eg_list(3, 'Arch', true)],
    'entries' => [
        1 => [
            eg_entry(10, 1, 'inline', 0, 'inline@x.org', 'Inline Person'),
            eg_entry(11, 1, 'member', 100),
            eg_entry(12, 1, 'constituent', 200),
            eg_entry(13, 1, 'list', 2),
            eg_entry(14, 1, 'member', 101),          // no email
            eg_entry(15, 1, 'member', 102),          // invalid text
            eg_entry(16, 1, 'member', 103),          // soft-deleted
            eg_entry(17, 1, 'constituent', 999),     // hard-deleted
            eg_entry(18, 1, 'list', 3),              // archived sub-list
            eg_entry(19, 1, 'list', 888),            // missing sub-list
            eg_entry(20, 1, 'inline', 0, 'INLINE@x.org'),   // duplicate of entry 10, other case
            eg_entry(21, 1, 'member', 104),          // status skipped
            eg_entry(22, 1, 'member', 105, '', ''),  // two addresses in one field
        ],
        2 => [eg_entry(30, 2, 'inline', 0, 'nested@x.org')],
    ],
    'members' => [
        100 => eg_member(100, 'Jane Smith', 'jane@x.org'),
        101 => eg_member(101, 'No Mail', ''),
        102 => eg_member(102, 'Bad Mail', 'n/a'),
        103 => eg_member(103, 'Gone', 'gone@x.org', ['deleted' => true]),
        104 => eg_member(104, 'Retired Guy', 'retired@x.org', ['status' => 'Retired']),
        105 => eg_member(105, 'Two Mail', 'one@x.org, two@x.org'),
    ],
    'constituents' => [200 => ['id' => 200, 'name' => 'Pat Citizen', 'email' => 'pat@x.org']],
];
$r = email_list_expand($g, 1, ['skip_statuses' => ['retired']]);
$st = static function (int $id) use ($r) { return $r['entries'][$id]['status'] ?? '(none)'; };
p155_t('inline resolves', $st(10) === 'ok');
p155_t('member resolves', $st(11) === 'ok');
p155_t('constituent resolves', $st(12) === 'ok');
p155_t('a nested sub-list resolves', $st(13) === 'ok' && $r['entries'][13]['contributes'] === 1);
p155_t('a member with no email -> no_email', $st(14) === 'no_email');
p155_t('"n/a" in the email field -> invalid_email, raw text shown', $st(15) === 'invalid_email'
    && strpos((string) $r['entries'][15]['detail'], 'n/a') !== false);
p155_t('a soft-deleted member -> member_deleted (reported, not silently absent)', $st(16) === 'member_deleted');
p155_t('a hard-deleted constituent -> missing_ref', $st(17) === 'missing_ref');
p155_t('an archived sub-list -> sub_list_archived', $st(18) === 'sub_list_archived');
p155_t('a vanished sub-list -> missing_ref', $st(19) === 'missing_ref');
p155_t('INLINE@x.org after inline@x.org -> duplicate naming the winner entry', $st(20) === 'duplicate'
    && strpos((string) $r['entries'][20]['detail'], '#10') !== false);
p155_t('a member whose status label is in the skip list -> status_skipped', $st(21) === 'status_skipped');
p155_t('two addresses typed into one field -> both delivered', $st(22) === 'ok' && $r['entries'][22]['contributes'] === 2);
$addrs = array_map(static function ($x) { return $x['address']; }, $r['recipients']);
p155_t('recipient set is exactly the deliverable addresses (first spelling wins)',
    $addrs === ['inline@x.org', 'jane@x.org', 'pat@x.org', 'nested@x.org', 'one@x.org', 'two@x.org']);
p155_t('summary counts entries, addresses, problems, policy, duplicates',
    $r['summary']['entries'] === 13 && $r['summary']['unique_addresses'] === 6
    && $r['summary']['problems'] === 6 && $r['summary']['policy_excluded'] === 1
    && $r['summary']['duplicates'] === 1);
$byAddr = [];
foreach ($r['recipients'] as $rc) $byAddr[$rc['address']] = $rc;
p155_t('the entry display_name overrides the natural name', $byAddr['inline@x.org']['name'] === 'Inline Person');
p155_t('the natural name is used when there is no override', $byAddr['jane@x.org']['name'] === 'Jane Smith');

// Empty-string display_name must NOT mask the natural name (the old `??` bug).
$g2 = $g; $g2['entries'][1] = [eg_entry(11, 1, 'member', 100, '', '')];
$r2 = email_list_expand($g2, 1);
p155_t('an EMPTY-string display_name does not mask the natural name', $r2['recipients'][0]['name'] === 'Jane Smith');

echo "\n--- guards: diamond, cycle, depth ---\n";
$d = ['missing_tables' => false,
    'lists' => [1 => eg_list(1, 'A'), 2 => eg_list(2, 'B'), 3 => eg_list(3, 'C'), 4 => eg_list(4, 'D')],
    'entries' => [
        1 => [eg_entry(1, 1, 'list', 2), eg_entry(2, 1, 'list', 3)],
        2 => [eg_entry(3, 2, 'list', 4)],
        3 => [eg_entry(4, 3, 'list', 4)],
        4 => [eg_entry(5, 4, 'inline', 0, 'd@x.org')],
    ], 'members' => [], 'constituents' => []];
$r = email_list_expand($d, 1);
p155_t('a diamond (A -> B,C ; B,C -> D) expands D once', $r['summary']['unique_addresses'] === 1);
p155_t('the second path to D is a duplicate, not a problem', $r['entries'][2]['status'] === 'duplicate'
    && $r['summary']['problems'] === 0);

$cy = ['missing_tables' => false,
    'lists' => [1 => eg_list(1, 'A'), 2 => eg_list(2, 'B')],
    'entries' => [
        1 => [eg_entry(1, 1, 'list', 2), eg_entry(9, 1, 'inline', 0, 'a@x.org')],
        2 => [eg_entry(2, 2, 'list', 1), eg_entry(8, 2, 'inline', 0, 'b@x.org')],
    ], 'members' => [], 'constituents' => []];
$r = email_list_expand($cy, 1);   // raw-SQL cycle A -> B -> A
p155_t('a pre-existing raw-SQL cycle TERMINATES', is_array($r) && isset($r['summary']));
p155_t('...b@x.org is still reached and the loop is reported on the entry that closes it',
    in_array('b@x.org', array_map(static function ($x) { return $x['address']; }, $r['recipients']), true));

$chain = ['missing_tables' => false, 'lists' => [], 'entries' => [], 'members' => [], 'constituents' => []];
for ($i = 1; $i <= 13; $i++) {
    $chain['lists'][$i] = eg_list($i, 'L' . $i);
    $chain['entries'][$i] = ($i < 13) ? [eg_entry($i, $i, 'list', $i + 1)] : [eg_entry(100, $i, 'inline', 0, 'deep@x.org')];
}
$r = email_list_expand($chain, 1);
$top = $r['entries'][1];
p155_t('a chain deeper than EMAIL_LIST_MAX_DEPTH is cut off (too_deep reported, no runaway)',
    $top['status'] !== 'ok' && $r['summary']['unique_addresses'] === 0);
$short = ['missing_tables' => false, 'lists' => [], 'entries' => [], 'members' => [], 'constituents' => []];
for ($i = 1; $i <= 4; $i++) {
    $short['lists'][$i] = eg_list($i, 'L' . $i);
    $short['entries'][$i] = ($i < 4) ? [eg_entry($i, $i, 'list', $i + 1)] : [eg_entry(100, $i, 'inline', 0, 'ok@x.org')];
}
p155_t('control: a normal two-level-plus chain DOES resolve (depth is not limited to one level)',
    email_list_expand($short, 1)['summary']['unique_addresses'] === 1);

echo "\n--- email_list_find_cycle_path ---\n";
$cp = ['entries' => [
    1 => [eg_entry(1, 1, 'list', 2)],     // A contains B
    2 => [eg_entry(2, 2, 'list', 3)],     // B contains C
    3 => [],                              // C empty
]];
p155_t('adding A under itself is a loop', email_list_find_cycle_path($cp, 1, 1) === [1]);
p155_t('adding A under C (A -> B -> C already) is a loop with the path named',
    email_list_find_cycle_path($cp, 3, 1) === [1, 2, 3]);
p155_t('adding C under A is NOT a loop', email_list_find_cycle_path($cp, 1, 3) === null);

echo "\n--- opt-out and skip flags (pure) ---\n";
$o = ['missing_tables' => false, 'lists' => [1 => eg_list(1, 'A')],
    'entries' => [1 => [eg_entry(1, 1, 'member', 7)]],
    'members' => [7 => eg_member(7, 'Opt Out', 'oo@x.org', ['user_id' => 55, 'opted_out' => true])],
    'constituents' => []];
$on = email_list_expand($o, 1, ['honor_email_opt_out' => true]);
$off = email_list_expand($o, 1, ['honor_email_opt_out' => false]);
p155_t('honor_email_opt_out=true drops an opted-out member (status opted_out)',
    $on['recipients'] === [] && $on['entries'][1]['status'] === 'opted_out');
p155_t('honor_email_opt_out=false keeps them and carries user_id + opted_out for the caller\'s own gate',
    count($off['recipients']) === 1 && $off['recipients'][0]['user_id'] === 55 && $off['recipients'][0]['opted_out'] === true);

// ── Part 2: real loader + real writers ──────────────────────────────────
$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) { echo "\nSKIP (database part): no database\n"; p155_done(); }
$prefix = $GLOBALS['db_prefix'] ?? '';
try { db_fetch_value("SELECT 1 FROM `{$prefix}email_lists` LIMIT 1"); }
catch (Throwable $e) { echo "\nSKIP (database part): email_lists table missing\n"; p155_done(); }

require_once __DIR__ . '/../inc/email-list-write.php';
p155_install_cleanup();

echo "\n--- real loader + writers ---\n";
$outer = p155_make_list('P155 Resolver Outer');
$inner = p155_make_list('P155 Resolver Inner');
$mOk   = p155_make_member('P155R', 'Okay', 'p155r.ok@example.invalid');
$mNone = p155_make_member('P155R', 'Nomail', null);
$cOk   = p155_make_constituent('P155R Citizen', 'p155r.citizen@example.invalid');
$cNone = p155_make_constituent('P155R Phoneonly', null);
$entries = [];
foreach ([
    ['member', ['ref_id' => $mOk]], ['member', ['ref_id' => $mNone]],
    ['constituent', ['ref_id' => $cOk]], ['constituent', ['ref_id' => $cNone]],
    ['inline', ['inline_email' => 'p155r.inline@example.invalid', 'display_name' => 'Inline Ian']],
] as $pair) {
    $res = email_list_add_entry_internal($outer, $pair[0], $pair[1], 0);
    p155_t("added a {$pair[0]} entry through the real writer", !empty($res['ok']));
    $entries[] = (int) ($res['id'] ?? 0);
}
email_list_add_entry_internal($inner, 'inline', ['inline_email' => 'p155r.nested@example.invalid'], 0);
$sub = email_list_add_entry_internal($outer, 'list', ['ref_id' => $inner], 0);
p155_t('added a nested sub-list through the real writer', !empty($sub['ok']));

$res = email_list_resolve($outer);
$got = array_map(static function ($x) { return $x['address']; }, $res['recipients']);
sort($got);
p155_t('the loader + expander resolve all four entry types including a nested list',
    $got === ['p155r.citizen@example.invalid', 'p155r.inline@example.invalid',
              'p155r.nested@example.invalid', 'p155r.ok@example.invalid']);
p155_t('the member with no email is reported no_email (not silently dropped)',
    ($res['entries'][$entries[1]]['status'] ?? '') === 'no_email');
p155_t('the contact with no email is reported no_email',
    ($res['entries'][$entries[3]]['status'] ?? '') === 'no_email');
p155_t('the summary reports exactly two problems', $res['summary']['problems'] === 2);

// Soft-delete the member: the row flips to member_deleted.
member_soft_delete($mOk, 0);
$res = email_list_resolve($outer);
p155_t('soft-deleting a member flips its entry to member_deleted and drops the address',
    ($res['entries'][$entries[0]]['status'] ?? '') === 'member_deleted'
    && !in_array('p155r.ok@example.invalid',
        array_map(static function ($x) { return $x['address']; }, $res['recipients']), true));

// Hard-deleted constituent -> missing_ref.
db_query("DELETE FROM `{$prefix}constituents` WHERE `id` = ?", [$cOk]);
$res = email_list_resolve($outer);
p155_t('a hard-deleted constituent -> missing_ref', ($res['entries'][$entries[2]]['status'] ?? '') === 'missing_ref');

echo "\n--- CONTROL: email_list_skip_member_statuses changes the output ---\n";
// Two rows with the SAME label: the setting must key on the LABEL, not an id.
$label = 'P155 Standby ' . substr(md5((string) microtime(true)), 0, 6);
$sid1 = p155_make_status($label);
$sid2 = p155_make_status($label);
$mStandby = p155_make_member('P155R', 'Standby', 'p155r.standby@example.invalid', $sid2);
$list3 = p155_make_list('P155 Resolver Skip');
email_list_add_entry_internal($list3, 'member', ['ref_id' => $mStandby], 0);
p155_set_setting(EMAIL_LIST_SETTING_SKIP_STATUSES, json_encode([]));
$res = email_list_resolve($list3);
p155_t('with the label NOT in the skip setting the member resolves', count($res['recipients']) === 1);
p155_set_setting(EMAIL_LIST_SETTING_SKIP_STATUSES, json_encode([strtolower($label)]));
$res = email_list_resolve($list3);
p155_t('with the label in the skip setting the member is status_skipped (setting is READ)',
    count($res['recipients']) === 0 && array_values($res['entries'])[0]['status'] === 'status_skipped');
p155_t('the duplicate-label status id that was NOT on the member is irrelevant (label-keyed, not id-keyed)',
    $sid1 !== $sid2);

echo "\n--- CONTROL: honor_email_opt_out changes the output ---\n";
$uid = p155_make_user(900155101, 'p155r_optout', 'p155r.optout.user@example.invalid');
db_query("INSERT INTO `{$prefix}notification_preferences` (`user_id`, `channel_email`) VALUES (?, 0)", [$uid]);
$mOpt = p155_make_member('P155R', 'Optout', 'p155r.optout@example.invalid', null, $uid);
$list4 = p155_make_list('P155 Resolver Optout');
email_list_add_entry_internal($list4, 'member', ['ref_id' => $mOpt], 0);
$a = email_list_resolve($list4, ['honor_email_opt_out' => true]);
$b = email_list_resolve($list4, ['honor_email_opt_out' => false]);
p155_t('honored: the opted-out member is dropped', count($a['recipients']) === 0);
p155_t('not honored: the member stays, carrying the user id the engine needs',
    count($b['recipients']) === 1 && $b['recipients'][0]['user_id'] === $uid);

echo "\n--- missing list / archived root ---\n";
$none = email_list_resolve(987654321);
p155_t('an id that does not exist is reported missing, not an empty success', !empty($none['missing']));
db_query("UPDATE `{$prefix}email_lists` SET `archived_at` = NOW() WHERE `id` = ?", [$list3]);
$arch = email_list_resolve($list3);
p155_t('an archived root list is flagged archived', !empty($arch['archived']));

p155_cleanup();
$left = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `name` LIKE 'P155 Resolver%'");
p155_t('fixtures are gone after cleanup (verified by querying)', $left === 0);

p155_done();
