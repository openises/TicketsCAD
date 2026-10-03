<?php
/**
 * Phase 155 (GH#145) - REGRESSION for the notification engine's email-list branch.
 *
 * THE BUG THIS DEFENDS AGAINST
 * ----------------------------
 * inc/notification-engine.php resolved a rule's `email_list_id` with
 *
 *     SELECT `email` FROM email_list_members WHERE list_id = ?
 *
 * but email_list_members has NO `email` column (it has `inline_email`, and the
 * other three entry types are references). The query threw SQLSTATE[42S22]
 * and an EMPTY catch, whose comment said "Email lists table may not exist",
 * swallowed it. Result: every rule that named a list resolved ZERO recipients -
 * for inline rows too, never mind members, contacts and nested lists - and
 * nothing anywhere said so. The control looked wired (a column, a UI promise, a
 * user-guide sentence "this is what the notification engine calls") and had
 * never once worked.
 *
 * HOW IT TESTS
 * ------------
 * A rule is inserted with a raw INSERT (until GH#144's rules API exists that is
 * the only way a rule comes into being - the same method
 * tests/test_notification_rule_channels.php uses), the list is built through the
 * REAL list writers, and the REAL engine is driven. Delivery goes through a
 * CAPTURING stub registered as the 'email'/'sms' broker channel, so we assert
 * exactly what would have been sent, to whom, never a hand-seeded result.
 *
 * Verified red on the pre-fix engine: the all-four-types resolution returned []
 * even for the inline entry.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_notify_helpers.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
$prefix = $GLOBALS['db_prefix'] ?? '';
foreach (['email_lists', 'notification_rules', 'notification_log'] as $t) {
    try { db_fetch_value("SELECT 1 FROM `{$prefix}{$t}` LIMIT 1"); }
    catch (Throwable $e) { p155_skip("{$t} table missing - run php sql/run_migrations.php"); }
}

require_once __DIR__ . '/../inc/notification-engine.php';
require_once __DIR__ . '/../inc/email-list-write.php';
p155_install_cleanup();
p155_on_cleanup("DELETE FROM `{$prefix}messages` WHERE `subject` LIKE 'P155 ENG%'");

echo "=== Phase 155 / GH#145 - notification engine resolves email lists ===\n\n";

// A capturing stand-in for the real transports. Re-registering a code replaces
// the real handler in the process-wide registry.
$GLOBALS['P155_SENT'] = [];
foreach (['email', 'sms', 'local_chat'] as $code) {
    broker_register($code, [
        'name' => 'p155 capture ' . $code,
        'send' => static function (array $m) use ($code) {
            $GLOBALS['P155_SENT'][] = ['channel' => $code, 'to' => $m['to'] ?? '', 'subject' => $m['subject'] ?? '', 'body' => $m['body'] ?? ''];
            return ['success' => true];
        },
        'receive' => null,
        'status' => static function () { return 'active'; },
    ]);
}
function p155e_sent_to(): array
{
    $to = [];
    foreach ($GLOBALS['P155_SENT'] as $s) $to[] = $s['to'];
    sort($to);
    return $to;
}
/** Deliver whatever the engine queued, if the engine queues (Phase B); a no-op before that. */
function p155e_drain(): void
{
    if (function_exists('notification_delivery_drain')) notification_delivery_drain(30.0, 500);
}
function p155e_rule(string $channel, ?int $listId, array $recipients = [], string $event = 'incident_create'): array
{
    global $prefix;
    db_query(
        "INSERT INTO `{$prefix}notification_rules`
            (`name`, `event_type`, `channel`, `recipients`, `email_list_id`, `subject_template`, `body_template`, `active`)
         VALUES ('P155 ENG rule', ?, ?, ?, ?, 'P155 ENG {scope}', 'body {scope}', 1)",
        [$event, $channel, json_encode($recipients), $listId]);
    $id = (int) db_fetch_value("SELECT LAST_INSERT_ID()");
    $GLOBALS['P155_FIX']['rules'][] = $id;
    return db_fetch_one("SELECT * FROM `{$prefix}notification_rules` WHERE `id` = ?", [$id]);
}

// ── Fixture: one list holding all four entry types + a nested list ─────
$outer = p155_make_list('P155 Eng Outer');
$inner = p155_make_list('P155 Eng Inner');
$uid   = p155_make_user(900155201, 'p155e_user', 'p155e.user@example.invalid');
$mem   = p155_make_member('P155E', 'Member', 'p155e.member@example.invalid');
$memU  = p155_make_member('P155E', 'Linked', 'p155e.user@example.invalid', null, $uid);   // linked to $uid, same address as the user
$con   = p155_make_constituent('P155E Contact', 'p155e.contact@example.invalid');
email_list_add_entry_internal($outer, 'member', ['ref_id' => $mem], 0);
email_list_add_entry_internal($outer, 'member', ['ref_id' => $memU], 0);
email_list_add_entry_internal($outer, 'constituent', ['ref_id' => $con], 0);
email_list_add_entry_internal($outer, 'inline', ['inline_email' => 'p155e.inline@example.invalid'], 0);
email_list_add_entry_internal($inner, 'inline', ['inline_email' => 'p155e.nested@example.invalid'], 0);
email_list_add_entry_internal($outer, 'list', ['ref_id' => $inner], 0);

echo "--- the regression: all four types + a nested list resolve ---\n";
$rule = p155e_rule('email', $outer);
$rec = _notification_resolve_recipients($rule);
$addrs = array_map(static function ($r) { return $r['address'] ?? ''; }, $rec);
sort($addrs);
p155_t('a rule with email_list_id resolves ALL addresses (it resolved NONE before the fix)',
    $addrs === ['p155e.contact@example.invalid', 'p155e.inline@example.invalid',
                'p155e.member@example.invalid', 'p155e.nested@example.invalid',
                'p155e.user@example.invalid']);
p155_t('...including the INLINE entry (even that never worked)', in_array('p155e.inline@example.invalid', $addrs, true));

echo "\n--- end to end through the real engine ---\n";
$GLOBALS['P155_SENT'] = [];
notification_check('incident_create', ['ticket_id' => null, 'scope' => 'engine-e2e', 'severity' => 0]);
p155e_drain();
p155_t('notification_check sends one email per unique list address',
    p155e_sent_to() === ['p155e.contact@example.invalid', 'p155e.inline@example.invalid',
                         'p155e.member@example.invalid', 'p155e.nested@example.invalid',
                         'p155e.user@example.invalid']);
p155_t('the subject template was rendered', ($GLOBALS['P155_SENT'][0]['subject'] ?? '') === 'P155 ENG engine-e2e');

echo "\n--- dedupe against the rule's explicit recipients ---\n";
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `id` = ?", [$rule['id']]);
$dupRule = p155e_rule('email', $outer, [$uid, 'P155E.INLINE@example.invalid']);
$rec = _notification_resolve_recipients($dupRule);
$cnt = [];
foreach ($rec as $r) { $k = strtolower($r['address'] ?? ''); $cnt[$k] = ($cnt[$k] ?? 0) + 1; }
p155_t('an address named BOTH explicitly and by the list appears once',
    ($cnt['p155e.inline@example.invalid'] ?? 0) === 1);
p155_t('a user listed explicitly AND present in the list (a linked member, same address) appears once',
    ($cnt['p155e.user@example.invalid'] ?? 0) === 1);
$winner = null;
foreach ($rec as $r) if (strtolower($r['address'] ?? '') === 'p155e.user@example.invalid') $winner = $r;
p155_t('...and the surviving entry carries the user id so preferences still apply', (int) ($winner['user_id'] ?? 0) === $uid);

echo "\n--- live edits take effect on the very next event ---\n";
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `id` = ?", [$dupRule['id']]);
$liveRule = p155e_rule('email', $outer);
$before = count(_notification_resolve_recipients($liveRule));
$extra = email_list_add_entry_internal($outer, 'inline', ['inline_email' => 'p155e.added@example.invalid'], 0);
$after = count(_notification_resolve_recipients($liveRule));
p155_t('adding an entry is visible to the next resolve (no cache, no copy)', $after === $before + 1);
email_list_remove_entry_internal((int) $extra['id'], $outer, 0);
p155_t('removing an entry is visible to the next resolve', count(_notification_resolve_recipients($liveRule)) === $before);

echo "\n--- per-user opt-out still applies to list members ---\n";
db_query("INSERT INTO `{$prefix}notification_preferences` (`user_id`, `channel_email`) VALUES (?, 0)", [$uid]);
$GLOBALS['P155_SENT'] = [];
notification_check('incident_create', ['ticket_id' => null, 'scope' => 'engine-optout', 'severity' => 0]);
p155e_drain();
p155_t('a list member whose linked account turned email off is NOT emailed',
    !in_array('p155e.user@example.invalid', p155e_sent_to(), true));
p155_t('...everyone else on the list still is', in_array('p155e.member@example.invalid', p155e_sent_to(), true));
db_query("DELETE FROM `{$prefix}notification_preferences` WHERE `user_id` = ?", [$uid]);

echo "\n--- email-list recipients are not handed to non-email channels ---\n";
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `id` = ?", [$liveRule['id']]);
$smsRule = p155e_rule('sms', $outer);
db_query("DELETE FROM `{$prefix}notification_log` WHERE `rule_id` = ?", [$smsRule['id']]);
$GLOBALS['P155_SENT'] = [];
notification_check('incident_create', ['ticket_id' => null, 'scope' => 'engine-sms', 'severity' => 0]);
p155e_drain();
p155_t('an SMS rule that names an email list sends NOTHING to the email addresses', $GLOBALS['P155_SENT'] === []);
$skipped = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log` WHERE `rule_id` = ? AND `status` = 'skipped'", [$smsRule['id']]);
p155_t('...and each is logged as skipped (not a silent drop, not a failed attempt)', $skipped >= 5);
$failed = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log` WHERE `rule_id` = ? AND `status` = 'failed'", [$smsRule['id']]);
p155_t('...with zero failed rows', $failed === 0);

echo "\n--- an archived or missing list is logged, not silently empty ---\n";
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `id` = ?", [$smsRule['id']]);
db_query("UPDATE `{$prefix}email_lists` SET `archived_at` = NOW() WHERE `id` = ?", [$outer]);
$archRule = p155e_rule('email', $outer);
$GLOBALS['P155_SENT'] = [];
notification_check('incident_create', ['ticket_id' => null, 'scope' => 'engine-arch', 'severity' => 0]);
p155e_drain();
p155_t('an archived list sends nothing', $GLOBALS['P155_SENT'] === []);
$why = (string) db_fetch_value("SELECT `error` FROM `{$prefix}notification_log` WHERE `rule_id` = ? AND `status` = 'skipped' ORDER BY `id` DESC LIMIT 1", [$archRule['id']]);
p155_t('...and a skipped row says the list is archived', stripos($why, 'archived') !== false);
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `id` = ?", [$archRule['id']]);
$missRule = p155e_rule('email', 987654321);
notification_check('incident_create', ['ticket_id' => null, 'scope' => 'engine-miss', 'severity' => 0]);
p155e_drain();
$why = (string) db_fetch_value("SELECT `error` FROM `{$prefix}notification_log` WHERE `rule_id` = ? AND `status` = 'skipped' ORDER BY `id` DESC LIMIT 1", [$missRule['id']]);
p155_t('a rule naming a list that no longer exists logs a skipped row saying so', stripos($why, 'missing') !== false || stripos($why, 'not exist') !== false);

echo "\n--- hygiene ---\n";
$src = file_get_contents(__DIR__ . '/../inc/notification-engine.php');
p155_t('the engine no longer selects a bare `email` column from email_list_members',
    !preg_match('/SELECT\s+`?email`?\s+FROM\s+`?\{\$prefix\}email_list_members/i', $src));
p155_t('the empty catch with the false "table may not exist" comment is gone',
    strpos($src, 'Email lists table may not exist') === false);
p155_t('the engine reaches lists only through the shared resolver', strpos($src, 'email_list_resolve(') !== false);

p155_cleanup();
$left = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `name` = 'P155 ENG rule'");
p155_t('fixtures are gone after cleanup (verified by querying)', $left === 0);

p155_done();
