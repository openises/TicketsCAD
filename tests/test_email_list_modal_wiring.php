<?php
/**
 * Phase 155 (GH#145 PR2) - the Email Lists panel and its Manage modal are wired to
 * the real endpoint, and the reported symptom is gone.
 *
 * THE SYMPTOM. "Manage list" could only add a typed (inline) address: the modal's one
 * add button was "Add inline address", while the backend, the API and the schema all
 * supported members, contacts and sub-lists. The same modal built each row's label by
 * concatenating a member's name into markup (stored XSS), and its click handlers lived
 * on window.__el_* globals.
 *
 * Part 1 (structure, runs everywhere): the panel markup, every element id the script looks
 * up, ES5, no innerHTML, no onclick, the Phase 41 code is gone from config.js, the tab
 * dispatcher calls the new script, the status vocabulary is the PHP one word for word,
 * SearchableSelect carries the new options and still carries the old surface.
 *
 * Part 2 (behaviour, needs Node + jsdom): the REAL scripts run against the REAL markup and
 * the REAL API's answers, are clicked and typed into like a person would, and every request
 * body they send is REPLAYED against the real endpoint. Stored-XSS payloads (a list name,
 * a member's name, a picker row) render as text; an orphan row says "(deleted record)",
 * never the string "null"; Esc in an open picker never reaches the modal behind it.
 * Without Node + jsdom this part prints SKIP (CI installs jsdom).
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_p155_notify_helpers.php';
require_once __DIR__ . '/../inc/email-list-write.php';

$root = realpath(__DIR__ . '/..');
$js = (string) file_get_contents($root . '/assets/js/email-lists-admin.js');
$ss = (string) file_get_contents($root . '/assets/js/searchable-select.js');
$panel = (string) file_get_contents($root . '/inc/email-lists-panel.php');
$settings = (string) file_get_contents($root . '/settings.php');
$configJs = (string) file_get_contents($root . '/assets/js/config.js');
$strip = static function (string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
};
$jsCode = $strip($js);
$ssCode = $strip($ss);

echo "=== Phase 155 / GH#145 - the Email Lists panel and Manage modal ===\n\n--- Part 1: structure ---\n\n";

preg_match_all("/\\\$\('(el[A-Z][A-Za-z0-9]*|emailListFilter|btnNewEmailList|emailListsBody|panel-email-lists)'\)/", $js, $m);
$wanted = array_values(array_unique($m[1]));
preg_match_all('/\bid="([A-Za-z0-9\-]+)"/', $panel, $m2);
$have = array_values(array_unique($m2[1]));
$missing = array_values(array_diff($wanted, $have));
p155_t('every element id the script looks up exists in the markup (' . count($wanted) . ' ids)' . ($missing ? ' - MISSING: ' . implode(', ', $missing) : ''), $missing === []);
// (elOptionsCard is the Bootstrap collapse target of the List options button, wired by data-bs-target in the markup)
$unusedAllowed = ['elAddLabel', 'elTypeMember', 'elTypeConstituent', 'elTypeList', 'elTypeInline', 'elNewTitle', 'elTypeGroup', 'elOptionsCard'];
$unused = array_values(array_diff(array_diff($have, $wanted), $unusedAllowed));
p155_t('every id in the markup is used by the script (no dead controls)' . ($unused ? ' - UNUSED: ' . implode(', ', $unused) : ''), $unused === []);

preg_match_all('/<button\b[^>]*>/i', $panel, $btns);
$bad = 0;
foreach ($btns[0] as $b) if (!preg_match('/\btype="button"/', $b)) $bad++;
p155_t('every <button> in the markup is type="button" (' . count($btns[0]) . ' checked)', $bad === 0 && count($btns[0]) > 8);
$created = preg_match_all("/el\('button'/", $jsCode);
preg_match_all('/\.type = \'button\'/', $jsCode, $bt);
p155_t('...and every button the script creates sets type = "button" (' . $created . ' created)', $created > 0 && count($bt[0]) >= $created);
p155_t('no innerHTML / outerHTML / insertAdjacentHTML / document.write / inline onclick in the new script',
    !preg_match('/\.innerHTML|\.outerHTML|insertAdjacentHTML|document\.write|onclick\s*=/', $jsCode));
p155_t('ES5 only: no arrow functions, let, const or template literals', !preg_match('/=>|\blet\s|\bconst\s|`/', $jsCode) && !preg_match('/=>|\blet\s|\bconst\s|`/', $ssCode));
p155_t('the script is one IIFE in strict mode', preg_match('/^\s*\(function \(\) \{\s*\'use strict\';/m', $jsCode) === 1 && substr(rtrim($jsCode), -5) === '})();');

echo "\n--- the old code is gone, the new code is registered ---\n";
p155_t('config.js no longer contains the Phase 41 email-list code (loadEmailLists, __el_open/__el_rm/__el_archive, emailListsCache)',
    !preg_match('/function loadEmailLists|__el_open|__el_rm|__el_archive|emailListsCache|openEmailListImportPrompt|showEmailListDetail/', $configJs));
p155_t('...and no "Add inline address" / prompt()-driven add flow survives anywhere in the shipped scripts',
    strpos($configJs, 'Add inline address') === false && strpos($jsCode, 'Add inline address') === false && !preg_match('/prompt\(/', $jsCode));
p155_t("config.js's tab dispatcher starts the new script when the tab opens", (bool) preg_match("/tab === 'email-lists' && window\.EmailListsAdmin\) window\.EmailListsAdmin\.load\(\)/", $configJs));
p155_t('settings.php includes the panel partial and loads the script with a cache-buster',
    strpos($settings, 'inc/email-lists-panel.php') !== false && (bool) preg_match("#assets/js/email-lists-admin\.js\?v=<\?php echo asset_v\('assets/js/email-lists-admin\.js'\)#", $settings));
p155_t('...and the old panel (and its btnImportEmailList prompt flow) is gone from settings.php', strpos($settings, 'btnImportEmailList') === false && strpos($settings, 'id="panel-email-lists"') === false);
p155_t('settings.php still loads searchable-select.js and its CSS (the picker depends on them)', strpos($settings, 'assets/js/searchable-select.js') !== false && strpos($settings, 'assets/css/searchable-select.css') !== false);
p155_t('the panel text no longer promises behaviour that does not exist (no "to:<list name>", no PAR-overdue, no "one level of nesting")',
    strpos($panel, 'to:&lt;list name&gt;') === false && stripos($panel, 'PAR-overdue') === false && stripos($panel, 'one level of nesting') === false && stripos($panel, 'include_once') === false);
p155_t('...it says what is true: only Notification Rules read a list, entries are read when a rule fires, nesting goes 10 deep', strpos($panel, 'Nothing else in the application reads a list') !== false
    && stripos($panel, 'each time a rule fires') !== false && strpos($panel, 'up to 10 levels') !== false);

echo "\n--- the status vocabulary is the server's, word for word ---\n";
require_once $root . '/inc/email-lists.php';
$php = email_list_status_labels();
preg_match_all("/^\s*([a-z_]+):\s*\{\s*label:\s*'([^']+)'/m", $js, $sm, PREG_SET_ORDER);
$jsLabels = [];
foreach ($sm as $x) $jsLabels[$x[1]] = $x[2];
p155_t('every status code the PHP can return has a label in the script, and the words match (' . count($php) . ' codes)', $jsLabels === $php);
$problems = email_list_problem_statuses(); $policy = email_list_policy_statuses();
$kindsOk = true;
foreach ($php as $code => $_) {
    $want = $code === 'ok' ? 'ok' : (in_array($code, $problems, true) ? 'problem' : (in_array($code, $policy, true) ? 'policy' : 'dup'));
    if (!preg_match("/\b{$code}:\s*\{[^}]*kind:\s*'{$want}'/", $js)) $kindsOk = false;
}
p155_t('...and each is drawn as the right kind (problem / policy decision / duplicate / ok)', $kindsOk);

echo "\n--- SearchableSelect ---\n";
foreach (['onQuery', 'isDisabled', 'getDisabledReason', 'getSubLabel', 'onCommit', 'hideEmptyOption', 'noMatchLabel'] as $opt) {
    p155_t("SearchableSelect supports the new option {$opt}", strpos($ssCode, $opt) !== false);
}
foreach (['setItems:', 'setValue:', 'getValue:', 'destroy:', 'getSelectedItem:', 'clear:', 'focus:'] as $fn) {
    p155_t("...and its public surface has {$fn}", strpos($ssCode, $fn) !== false);
}
p155_t('Escape stops the keystroke where it is consumed (so a picker in a modal does not also close the modal)', (bool) preg_match("/key === 'Escape'\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*e\.stopPropagation\(\)/", $ss));
p155_t('the list carries role=listbox and the input role=combobox with aria-expanded / aria-controls / aria-activedescendant',
    strpos($ssCode, "'role', 'listbox'") !== false && strpos($ssCode, "'combobox'") !== false && strpos($ssCode, 'aria-expanded') !== false && strpos($ssCode, 'aria-controls') !== false && strpos($ssCode, 'aria-activedescendant') !== false);

echo "\n--- Part 2: behaviour (real scripts + real markup + real API answers, in jsdom) ---\n\n";
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
$node = $node === '' ? '' : strtok($node, "\r\n");
$jsdomOk = false;
if ($node !== '') {
    $probe = @shell_exec('"' . $node . '" -e "require(\'jsdom\');console.log(\'ok\')" 2>&1');
    $jsdomOk = is_string($probe) && strpos($probe, 'ok') !== false;
}
$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
$prefix = $GLOBALS['db_prefix'] ?? '';
if ($haveDb) {
    try { db_fetch_value("SELECT 1 FROM `{$prefix}email_list_members` LIMIT 1"); } catch (Throwable $e) { $haveDb = false; }
}
if (!$jsdomOk || !$haveDb) {
    echo "SKIP: Part 2 needs Node + the jsdom package (npm install jsdom) and the email list tables; the structural checks above still ran\n";
    p155_done();
}

p155_install_cleanup();
$auditFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}newui_audit_log`");
p155_on_cleanup("DELETE FROM `{$prefix}newui_audit_log` WHERE `id` > ? AND `activity` LIKE 'email_list.%'", [$auditFloor]);
p155_remember_setting('email_list_skip_member_statuses');
p155_remember_setting('email_list_require_email_on_add');
p155_set_setting('email_list_skip_member_statuses', '[]');
p155_set_setting('email_list_require_email_on_add', '0');
$existingLists = array_map('intval', array_column(db_fetch_all("SELECT `id` FROM `{$prefix}email_lists` WHERE `archived_at` IS NULL AND `name` NOT LIKE 'P155%'"), 'id'));
if ($existingLists) {
    db_query("UPDATE `{$prefix}email_lists` SET `archived_at` = NOW() WHERE `id` IN (" . implode(',', $existingLists) . ")");
    p155_on_cleanup("UPDATE `{$prefix}email_lists` SET `archived_at` = NULL WHERE `id` IN (" . implode(',', $existingLists) . ")");
}

$admin = test_admin_user_id();
function wm_api(string $method, string $action, int $uid, $payload = null): array
{
    return p155_api($method, $action, $uid, $payload, ['endpoint' => 'email-lists']);
}

// ── fixtures, through the REAL writers ─────────────────────────────────────
$xssName = '<img src=x onerror="window.__xss=1">';
$A = p155_make_list('P155 Mod A ' . $xssName);
db_query("UPDATE `{$prefix}email_lists` SET `description` = ? WHERE `id` = ?", ['<b>desc</b> of A', $A]);
$sub = p155_make_list('P155 Mod Sub');
$other = p155_make_list('P155 Mod Other');
email_list_add_entry_internal($sub, 'inline', ['inline_email' => 'sub-only@example.invalid'], 0);

$mAlpha = p155_make_member('P155ElMod', 'Alpha', 'alpha@example.invalid');                   // on A
$mNoMail = p155_make_member('P155ElMod', 'Brokenmail', null);                                 // on A -> problem
$mEvil = p155_make_member('<img src=x onerror=window.__xss=1>', 'Evil', 'evil@example.invalid'); // on A, markup in the NAME
$mGone = p155_make_member('P155ElMod', 'Gone', 'gone@example.invalid');                      // on A, then soft-deleted
$mNew = p155_make_member('P155ElMod', 'Aardvark', 'aardvark@example.invalid');               // NOT on A: first enabled row
$mNew2 = p155_make_member('P155ElMod', 'Bremen', null);                                      // NOT on A, no email
$c1 = p155_make_constituent('P155ElMod Contact One', 'contact1@example.invalid');            // NOT on A
$cNo = p155_make_constituent('P155ElMod Contact NoMail', null);                              // on A -> problem
$cOrphan = p155_make_constituent('P155ElMod Orphan', 'orphan@example.invalid');              // on A, then hard-deleted
foreach ([['member', $mAlpha], ['member', $mNoMail], ['member', $mEvil], ['member', $mGone], ['constituent', $cNo], ['constituent', $cOrphan], ['list', $sub]] as [$t, $rid]) {
    $r = email_list_add_entry_internal($A, $t, ['ref_id' => $rid], 0);
    if (empty($r['ok'])) echo "fixture add {$t} {$rid} failed: " . ($r['message'] ?? '') . "\n";
}
email_list_add_entry_internal($A, 'inline', ['inline_email' => 'inline@example.invalid', 'display_name' => 'Inline Person'], 0);
db_query("UPDATE `{$prefix}member` SET `deleted_at` = NOW() WHERE `id` = ?", [$mGone]);
db_query("DELETE FROM `{$prefix}constituents` WHERE `id` = ?", [$cOrphan]);
$GLOBALS['P155_FIX']['consts'] = array_values(array_diff($GLOBALS['P155_FIX']['consts'], [$cOrphan]));
p155_t('fixtures: a list with every kind of entry, including a soft-deleted member, a hard-deleted contact (an orphan), a no-email member and contact, and markup in a name',
    $A > 0 && $sub > 0 && $mEvil > 0 && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_list_members` WHERE `list_id` = ?", [$A]) === 8);

$lists = wm_api('GET', 'list', $admin)['json']['lists'] ?? [];
$details = [];
foreach ([$A, $sub, $other] as $id) $details[(string) $id] = wm_api('GET', 'detail', $admin, 'id=' . $id)['json'];
$resolve = [];
foreach ([$A, $sub, $other] as $id) $resolve[(string) $id] = wm_api('GET', 'resolve', $admin, 'id=' . $id)['json'];
$options = wm_api('GET', 'get_options', $admin)['json'];
$q = ['member' => 'P155ElMod', 'noEmail' => 'Bremen', 'constituent' => 'P155ElMod', 'list' => 'P155 Mod'];
$search = [];
$grab = static function (string $type, string $qq, string $he) use (&$search, $admin, $A) {
    $r = wm_api('GET', 'search_recipients', $admin, 'type=' . $type . '&q=' . rawurlencode($qq) . '&list_id=' . $A . '&has_email=' . $he)['json'];
    $search[$type . '|' . $qq . '|' . $he] = $r;
    return $r;
};
$grab('member', '', '0'); $grab('constituent', '', '1'); $grab('list', '', '0');
$memSearch = $grab('member', $q['member'], '0'); $grab('member', $q['noEmail'], '0');
$grab('constituent', $q['constituent'], '1'); $grab('constituent', $q['constituent'], '0'); $grab('list', $q['list'], '0');
$firstEnabled = null;
foreach ($memSearch['items'] ?? [] as $it) if ($it['disabled_reason'] === '') { $firstEnabled = $it['id']; break; }
$xssLabel = '';
foreach ($details[(string) $A]['members'] ?? [] as $row) if (strpos($row['label'], '<img') !== false) $xssLabel = $row['label'];
p155_t('fixtures: the real endpoint answered list, detail, resolve, options and the pickers; the first enabled member row is the one with an email and not yet on the list',
    count($lists) >= 3 && !empty($details[(string) $A]['members']) && $firstEnabled === $mNew && $xssLabel !== '' && !empty($options['status_labels']));

ob_start();
if (!defined('NEWUI_ROOT')) define('NEWUI_ROOT', $root);
include $root . '/inc/email-lists-panel.php';
$html = ob_get_clean();
p155_t('the panel markup rendered from the partial', strlen($html) > 5000 && strpos($html, 'id="panel-email-lists"') !== false);

$fx = ['html' => $html, 'lists' => $lists, 'details' => $details, 'resolve' => $resolve, 'options' => $options, 'search' => $search,
       'mainListId' => $A, 'createdId' => $other, 'xssLabel' => $xssLabel, 'queries' => $q,
       'expectedFirstEnabledMemberId' => $mNew, 'hasContactProblem' => true, 'addWarnings' => [], 'archiveInUse' => false];
$fxFile = tempnam(sys_get_temp_dir(), 'p155elfx');
file_put_contents($fxFile, json_encode($fx));
$errFile = tempnam(sys_get_temp_dir(), 'p155elerr');
$proc = proc_open([$node, __DIR__ . '/_p155_el_harness.cjs', $root, $fxFile], [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, null, ['bypass_shell' => true]);
$outRaw = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
if (is_resource($proc)) { fclose($pipes[1]); proc_close($proc); }
$run = json_decode(trim((string) $outRaw), true);
@unlink($fxFile);
if (!is_array($run)) echo "harness output was not JSON:\n" . substr((string) $outRaw, 0, 800) . "\n" . substr((string) @file_get_contents($errFile), 0, 800) . "\n";
@unlink($errFile);
p155_t('the jsdom harness ran to the end with no exception' . (!empty($run['errors']) ? ' - ' . $run['errors'][0] : ''), is_array($run) && empty($run['errors']));
$checks = is_array($run) ? ($run['checks'] ?? []) : [];

$expected = [
    'missing_tables_shows_repair_hint' => 'a 503 from the API shows the repair hint, never an empty table that reads as "no lists"',
    'table_one_row_per_list' => 'one table row per list',
    'table_shows_entries_addresses_problems_columns' => 'the table says Entries, Addresses and Problems',
    'table_no_member_count_column' => '...and no longer a "Members" column that was mistaken for addresses',
    'table_xss_list_name_is_text' => 'STORED XSS: a list named with an <img onerror> payload renders as TEXT',
    'table_problem_badge_has_icon_and_text' => 'a problem count is a badge with an icon, the number and a screen-reader sentence (never colour alone)',
    'table_actions_are_buttons_with_names' => 'row actions are type=button with an aria-label naming the list',
    'no_inline_onclick_anywhere' => 'no inline onclick and no window.__el_* globals',
    'filter_hides_non_matching' => 'the filter hides non-matching lists',
    'filter_cleared_restores' => '...and clearing it restores them',
    'new_modal_opens_and_name_gets_focus' => 'New List opens a modal (not prompt()) and focuses the name after it is shown',
    'new_empty_name_refused_client_side' => 'an empty name is refused before any request',
    'new_posts_name_and_description_with_csrf' => 'Create posts name and description with the page\'s CSRF token',
    'new_success_opens_manage_for_the_new_list' => '...closes the modal and opens Manage for the new list',
    'manage_opens_once' => 'Manage shows the modal exactly once',
    'manage_focus_goes_to_the_type_radio_after_shown' => 'focus goes to the recipient-type radio AFTER shown (Bootstrap\'s focus trap otherwise keeps it on the dialog)',
    'manage_title_names_list' => 'the title names the list',
    'banner_headline' => 'the summary banner states the unique addresses (aria-live polite)',
    'banner_is_warning_when_problems' => '...as a warning when anything needs fixing, success when not',
    'banner_lists_problem_counts' => '...and lists what is wrong (no email, skipped...)',
    'entries_one_row_per_entry' => 'one row per entry',
    'entries_problems_first_as_the_server_ordered' => 'problems stay first, as the server ordered them',
    'entries_xss_member_name_is_text' => 'STORED XSS: a member whose NAME is markup renders as text in the entries table',
    'entries_orphan_says_deleted_record_never_null' => 'a deleted record reads "(deleted record)" - never the string null or undefined',
    'entries_status_has_icon_and_word' => 'every status is an icon plus a word',
    'entries_fix_in_roster_link' => 'a member with no email carries a "Fix in Roster" link to that roster record',
    'entries_edit_in_contacts_link_for_a_contact_problem' => 'a contact with no email says "Edit in Contacts"',
    'entries_remove_buttons_named' => 'every Remove button names the recipient',
    'entries_sublist_gives_a_link_that_opens_it' => 'a sub-list shows how many addresses it gives, as a link that opens it',
    'edit_form_prefilled' => 'the name/description form is pre-filled',
    'picker_click_to_browse_queries_with_empty_text' => 'focusing the picker browses (server query with empty text and the list id)',
    'picker_typing_queries_server_with_text_and_list' => 'typing queries the SERVER (the address book is never held in the browser)',
    'picker_shows_server_results' => 'the picker lists what the server returned',
    'picker_disabled_rows_show_reason_in_text' => 'a row already on the list is disabled and says why in text',
    'picker_no_none_row_in_a_picker_that_adds' => 'the picker has no "- None -" row (it adds, it does not set)',
    'picker_rows_are_text_not_markup' => 'STORED XSS: picker rows render a member\'s name as text',
    'picker_aria' => 'the picker is a combobox with a listbox and aria-expanded',
    'escape_in_picker_does_not_reach_the_modal' => 'Esc inside an open picker never reaches the modal behind it',
    'escape_closed_the_picker_and_the_modal_is_still_open' => '...it closed the picker and the modal is still open',
    'enter_commits_the_highlight_and_moves_focus_to_add' => 'Enter commits the highlighted row and moves focus to Add',
    'add_button_plain_when_member_has_email' => 'the button reads Add for a member with an email',
    'add_member_payload_is_member_with_ref_id_NOT_inline' => 'THE REPORTED SYMPTOM: adding a picked member posts {member_type:"member", ref_id} - not only an inline address',
    'add_success_message_names_what_was_added' => 'success says what was added and to which list',
    'add_refreshes_in_place_without_reshowing_the_modal' => 'the list refreshes in place and the modal is not shown again (no stacked backdrops)',
    'add_clears_picker_and_refocuses_it' => 'the picker clears and is refocused for the next add',
    'add_button_says_add_anyway_for_a_member_with_no_email' => 'a member with no email reads "Add anyway (no email on file)"',
    'add_no_email_success_mentions_the_gap' => '...and the success message mentions the gap',
    'contact_shows_the_has_email_filter_checked_by_default' => 'Contact shows "only contacts that have an email address", ticked by default',
    'contact_search_sends_has_email_1' => '...and the search says so to the server',
    'unticking_the_filter_sends_has_email_0' => 'unticking it widens the search',
    'add_member_payload_for_a_contact' => 'adding a contact posts {member_type:"constituent", ref_id}',
    'sublist_hides_the_contact_filter' => 'Sub-list hides the contact filter',
    'sublist_add_button_plain' => '...and its Add button is plain',
    'add_member_payload_for_a_sublist' => 'adding a sub-list posts {member_type:"list", ref_id}',
    'address_type_swaps_picker_for_email_and_name_fields' => 'Email address swaps the picker for email and name fields',
    'address_empty_refused_client_side' => 'an empty typed address is refused before any request',
    'add_member_payload_for_a_typed_address' => 'a typed address posts {member_type:"inline", inline_email, display_name}, trimmed',
    'address_fields_cleared_after_add' => '...and the fields clear and refocus after the add',
    'remove_declined_sends_nothing' => 'Remove asks first; declining sends nothing',
    'remove_posts_entry_id_and_list_id' => 'Remove posts the entry id AND the list id (a stale modal cannot delete another list\'s row)',
    'remove_refreshes_in_place' => '...and refreshes in place',
    'remove_moves_focus_to_a_remove_button' => '...moving focus to another Remove button',
    'preview_reads_resolve_and_lists_recipients_as_text' => '"Preview recipients" reads resolve and lists the recipients as text',
    'preview_lists_what_was_left_out_with_a_reason' => '...and what was left out, with the reason',
    'csv_posts_list_id_and_text' => 'CSV import posts this list\'s id and the pasted text',
    'csv_result_reports_added_skipped_duplicates_and_errors' => '...and reports added, skipped, duplicates and the errors',
    'rename_posts_id_name_description' => 'the name/description form posts an update',
    'options_load_on_first_open' => 'List options load the server\'s values on first open',
    'options_one_checkbox_per_status_label' => '...one checkbox per member status',
    'options_prechecked_from_the_server' => '...ticked exactly as the server has them',
    'options_each_checkbox_has_a_label' => '...each with a label',
    'options_post_the_ticked_labels_and_the_flag' => 'Save posts the ticked labels and the require-email flag',
    'options_saved_message' => '...and says Saved',
    'archive_declined_sends_nothing' => 'Archive asks first; declining sends nothing',
    'archive_in_use_asks_again_then_forces' => 'archiving a list a rule uses asks again, naming the consequence, and only then forces',
    'no_xss_flag_ever_set' => 'no injected script ever ran',
];
foreach ($expected as $name => $label) {
    p155_t($label . '  [' . $name . ']', array_key_exists($name, $checks) && $checks[$name] === true);
}
$extra = array_values(array_diff(array_keys($checks), array_keys($expected)));
p155_t('every check the harness ran is asserted here (a check nobody reads is a dead control)' . ($extra ? ' - UNASSERTED: ' . implode(', ', $extra) : ''), $extra === []);

$ssExpected = [
    'compat_none_row_present' => 'with no new options the "none" row is still there',
    'compat_local_filter' => '...typing still filters locally',
    'compat_enter_commits_first' => '...Enter still commits the first match',
    'compat_aria_attrs' => '...and the ARIA roles are added',
    'compat_escape_clears_text_first' => '...Escape still clears typed text first',
    'remote_debounced_to_one_call' => 'onQuery is debounced (three keystrokes, one call)',
    'remote_ignores_a_stale_answer' => '...a slower earlier answer arriving late is ignored',
    'remote_no_none_row_when_hidden' => '...hideEmptyOption removes the none row',
    'remote_disabled_row_is_marked_with_reason' => '...a disabled row is aria-disabled and shows its reason',
    'remote_arrow_skips_disabled' => '...ArrowDown skips disabled rows',
    'remote_arrow_up_skips_disabled_too' => '...ArrowUp too',
    'remote_clicking_disabled_commits_nothing' => '...clicking a disabled row commits nothing',
    'remote_enter_commits_first_enabled' => '...Enter commits the first enabled row',
    'labels_are_escaped' => 'labels and sub-labels are escaped by the component',
    'escape_consumed_when_it_closes_the_list' => 'Escape that closes the list is stopped at the picker',
    'escape_passes_through_when_nothing_to_close' => '...and passes through when the list is already closed',
];
foreach ($ssExpected as $name => $label) {
    p155_t('SearchableSelect: ' . $label . '  [' . $name . ']', is_array($run) && ($run['ss'][$name] ?? null) === true);
}
$ssExtra = is_array($run) ? array_values(array_diff(array_keys($run['ss'] ?? []), array_keys($ssExpected))) : [];
p155_t('every SearchableSelect check the harness ran is asserted here' . ($ssExtra ? ' - UNASSERTED: ' . implode(', ', $ssExtra) : ''), $ssExtra === []);

echo "\n--- replaying what the page sent against the REAL endpoint (a field-name drift fails here) ---\n";
$noCsrf = static function (?array $b): array { $b = $b ?? []; unset($b['csrf_token']); return $b; };
$replay = [
    'a picked member' => $run['addMemberBody'] ?? null,
    'a member with no email' => $run['addNoEmailBody'] ?? null,
    'a picked contact' => $run['addConstituentBody'] ?? null,
    'a picked sub-list' => $run['addListBody'] ?? null,
    'a typed address' => $run['addInlineBody'] ?? null,
];
foreach ($replay as $label => $body) {
    $r = $body ? wm_api('POST', 'add_member', $admin, $noCsrf($body)) : ['status' => 0, 'json' => null];
    p155_t("the real endpoint ACCEPTS the page's add_member payload for {$label} (HTTP 200)", $r['status'] === 200 && !empty($r['json']['id']));
}
$after = wm_api('GET', 'detail', $admin, 'id=' . $A)['json'];
$types = array_count_values(array_map(static function ($x) { return $x['member_type']; }, $after['members'] ?? []));
p155_t('...and the list really holds the new member, contact, sub-list and address entries now (the symptom: only inline could be added)',
    ($types['member'] ?? 0) >= 6 && ($types['constituent'] ?? 0) >= 3 && ($types['list'] ?? 0) >= 2 && ($types['inline'] ?? 0) >= 2);
$noMailRow = null; foreach ($after['members'] as $x) if ($x['ref_id'] === $mNew2 && $x['member_type'] === 'member') $noMailRow = $x;
p155_t('...a member with no email was added WITH the problem visible on its row', $noMailRow !== null && $noMailRow['status'] === 'no_email');
$rb = $noCsrf($run['removeBody'] ?? null);
$rr = $rb ? wm_api('POST', 'remove_member', $admin, $rb) : ['status' => 0];
p155_t('the real endpoint accepts the page\'s remove_member payload', $rr['status'] === 200);
$cb = $noCsrf($run['csvBody'] ?? null);
$cr = $cb ? wm_api('POST', 'import_csv', $admin, $cb) : ['status' => 0, 'json' => null];
p155_t('...its import_csv payload', $cr['status'] === 200 && ($cr['json']['added'] ?? 0) === 2 && ($cr['json']['skipped'] ?? 0) === 1);
$ub = $noCsrf($run['updateBody'] ?? null);
$ur = $ub ? wm_api('POST', 'update', $admin, $ub) : ['status' => 0];
p155_t('...its update payload (rename)', $ur['status'] === 200 && (string) db_fetch_value("SELECT `name` FROM `{$prefix}email_lists` WHERE `id` = ?", [$A]) === 'P155 Renamed');
$ob = $noCsrf($run['optionsBody'] ?? null);
$or = $ob ? wm_api('POST', 'save_options', $admin, $ob) : ['status' => 0];
p155_t('...its save_options payload', $or['status'] === 200);
wm_api('POST', 'save_options', $admin, ['skip_statuses' => [], 'require_email_on_add' => false]);
$crb = isset($run['createBody']) ? $noCsrf($run['createBody']) : null;
$crr = $crb ? wm_api('POST', 'create', $admin, $crb) : ['status' => 0, 'json' => null];
if (!empty($crr['json']['id'])) $GLOBALS['P155_FIX']['lists'][] = (int) $crr['json']['id'];
p155_t('...its create payload', $crr['status'] === 200 && ($crr['json']['name'] ?? '') === 'P155 Created');
$arch = array_map($noCsrf, $run['archiveBodies'] ?? []);
$a1 = isset($arch[0]) ? wm_api('POST', 'archive', $admin, array_merge($arch[0], ['id' => $sub])) : ['status' => 0];
$a2 = isset($arch[1]) ? wm_api('POST', 'archive', $admin, array_merge($arch[1], ['id' => $other])) : ['status' => 0];
p155_t('...and both archive payloads (without and with force)', $a1['status'] === 200 && $a2['status'] === 200);

p155_cleanup();
p155_t('fixtures are gone after cleanup (verified by querying)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `name` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}member` WHERE `first_name` LIKE 'P155%' OR `first_name` LIKE '<img%'") === 0);
p155_done();
