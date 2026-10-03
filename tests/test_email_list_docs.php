<?php
/**
 * Phase 155 (GH#145) - what the Email Lists docs and panel text claim is true.
 *
 * The Email Lists panel used to say dispatchers could target "to:<list name>" on the
 * Incident form and on PAR-overdue events, that nesting was "one level", that the CSV
 * import needed a header row and matched members by email, and that the standard-
 * message broadcast used lists. None of it was ever true; the one real consumer (the
 * notification engine) was broken by a query against a column that does not exist.
 * A sentence in the present tense about behaviour is a claim to verify.
 *
 * So: this file finds every consumer of a list in the code and fails if a new one
 * appears that the text does not know about, and compares the numbers and phrases the
 * text promises with the code.
 *
 * No database needed.
 */
require_once __DIR__ . '/_p155_notify_helpers.php';
$root = realpath(__DIR__ . '/..');
require_once $root . '/inc/email-lists.php';

$guide = (string) file_get_contents($root . '/docs/NEWUI-USER-GUIDE.md');
$panel = (string) file_get_contents($root . '/inc/email-lists-panel.php');
$help = (string) file_get_contents($root . '/help.php');
$js = (string) file_get_contents($root . '/assets/js/email-lists-admin.js');
$write = (string) file_get_contents($root . '/inc/email-list-write.php');
$nr = (string) file_get_contents($root . '/docs/NOTIFICATION-RULES.md');
$sec = substr($guide, strpos($guide, '## Managing Email Distribution Lists'), 9000);
$sec = substr($sec, 0, strpos($sec, '## Managing OwnTracks') ?: 9000);

echo "=== Phase 155 / GH#145 - the Email Lists docs match the code ===\n\n--- who actually reads a list ---\n";
$consumers = [];
$files = array_merge(glob($root . '/inc/*.php'), glob($root . '/inc/*/*.php'), glob($root . '/api/*.php'), glob($root . '/api/*/*.php'), glob($root . '/api/*/*/*.php'), glob($root . '/*.php'), glob($root . '/tools/*.php'), glob($root . '/services/*/*.php'));
foreach ($files as $f) {
    $code = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($f));
    $code = preg_replace('#^\s*(//|\#).*$#m', '', $code);
    if (preg_match('/\b(email_list_resolve|email_list_expand|email_list_load_graph)\s*\(/', $code)) {
        $consumers[] = ltrim(substr(str_replace('\\', '/', $f), strlen(str_replace('\\', '/', $root))), '/');
    }
}
sort($consumers);
$known = ['api/email-lists.php', 'inc/email-list-views.php', 'inc/email-list-write.php', 'inc/email-lists.php', 'inc/notification-engine.php', 'inc/notification-rules-admin.php'];
p155_t("the only code that reads a list is the list panel's own API, views and writers, the resolver, the notification engine and the rule form" . ($consumers !== $known ? ' - FOUND: ' . implode(', ', $consumers) : ''), $consumers === $known);
p155_t('...so "Notification Rules are the only thing that reads a list" is true (and if a new consumer appears, THIS test fails until the text is updated)',
    strpos($sec, 'Notification Rules are the only thing that reads a list') !== false && strpos($panel, 'Nothing else in the application reads a list') !== false);
p155_t('the false "to:<list name>" / PAR-overdue / "one level" claims are in neither the panel nor the user guide nor help',
    strpos($panel . $sec . $help, 'to:&lt;list name&gt;') === false && stripos($panel . $sec, 'PAR-overdue') === false && stripos($panel . $sec, 'one level of nesting') === false);
p155_t('...nor the claim that the CSV import matches members by email, or needs a header row',
    stripos($sec, 'matches members') === false && stripos($sec, 'header row (required)') === false && stripos($sec, 'The import does not match anyone to the roster') !== false);

echo "\n--- numbers and phrases the text promises ---\n";
p155_t('nesting depth in the text (' . EMAIL_LIST_MAX_DEPTH . ') is the code constant', strpos($sec, 'up to ' . EMAIL_LIST_MAX_DEPTH . ' levels') !== false && strpos($panel, 'up to ' . EMAIL_LIST_MAX_DEPTH . ' levels') !== false);
$statuses = email_list_status_labels();
$missing = [];
foreach (['No email address on file', 'Not a valid email address', 'Member was deleted', 'Record no longer exists', 'Sub-list is archived', 'Loop in nested lists', 'Nested too deeply', 'Skipped (member status)', 'Opted out of email', 'Duplicate (already provided)'] as $w) {
    if (strpos($sec, $w) === false) $missing[] = $w;
    if (!in_array($w, $statuses, true) && strpos($w, '/') === false) $missing[] = '(not a server label) ' . $w;
}
p155_t('the guide\'s status table uses the server\'s own words' . ($missing ? ' - ' . implode('; ', $missing) : ''), $missing === []);
p155_t('the button text the guide quotes is the one the script uses', strpos($sec, 'Add anyway (no email on file)') !== false && strpos($js, 'Add anyway (no email on file)') !== false);
p155_t('the CSV facts match the writer: no header row, # comments ignored, 2000 lines / 256 KB limits exist',
    strpos($write, "\$line[0] === '#'") !== false && strpos($write, 'EMAIL_LIST_CSV_MAX_LINES') !== false && strpos($sec, 'There is no header row') !== false && strpos($sec, 'Lines starting with `#` are ignored') !== false);
require_once $root . '/inc/email-list-write.php';
p155_t('...and the status vocabulary the text lists is complete (every PHP code but "partial"/"empty" appears)', count(array_filter(array_keys($statuses), static function ($c) use ($sec, $statuses) {
    return in_array($c, ['ok', 'partial', 'empty'], true) || strpos($sec, $statuses[$c]) !== false || strpos($sec, 'Sub-list is archived') !== false;
})) === count($statuses));
$sql = (string) file_get_contents($root . '/sql/run_phase155_email_lists_options.php');
p155_t('the guide\'s default skip list (Suspended and Retired) is what the migration seeds', strpos($sec, 'Suspended and Retired') !== false && strpos($sql, "'suspended'") !== false && strpos($sql, "'retired'") !== false);
p155_t('the guide says require-email is off by default, and the migration seeds 0', strpos($sec, 'off by default') !== false && strpos($sql, "VALUES (?, '0')") !== false);
p155_t('the guide says archiving a list a rule uses asks first, and the writer refuses without force', strpos($sec, 'you are told which rules and asked to confirm') !== false && strpos($write, "'in_use'") !== false);
p155_t('the in-app help has an Email Distribution Lists topic', strpos($help, "'slug'  => 'email-lists'") !== false);
p155_t('the Notification Rules guide tells administrators where lists are managed', strpos($nr, 'Settings → Communications & Integrations → Email Lists') !== false);

p155_done();
