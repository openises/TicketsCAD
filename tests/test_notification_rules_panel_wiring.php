<?php
/**
 * Phase 155 (GH#144) - the Notification Rules PANEL is wired to the real endpoint.
 *
 * The recurring failure in this codebase is a control that LOOKS finished and is
 * wired to nothing: a setting with no reader, a key the endpoint never sends, an id
 * the script looks up that the markup never had. So this file proves the opposite
 * three ways.
 *
 * Part 1 (structure, runs everywhere): every element id the script looks up exists in
 * the markup the settings page really serves; every button is type="button"; no
 * innerHTML anywhere in the script; ES5 only; the tab is registered, gated and
 * activated; the SSE event is registered; the two false sentences the old stub and the
 * Email Lists panel carried are gone.
 *
 * Part 2 (behaviour, needs Node + jsdom): the REAL assets/js/notification-rules.js runs
 * against the REAL markup and the REAL api/notification-rules.php answers (captured here
 * from the real endpoint), is clicked and typed into like a person would, and every
 * request body it sends is then REPLAYED against the real endpoint - so a field-name
 * drift between the page and the API fails here, not in front of a user. Stored-XSS
 * payloads in a rule name, a user's name, a log message and a preview are shown as text.
 * Without Node + jsdom this part prints SKIP (CI installs jsdom).
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_p155_notify_helpers.php';

$root = realpath(__DIR__ . '/..');
$js = (string) file_get_contents($root . '/assets/js/notification-rules.js');
$panel = (string) file_get_contents($root . '/inc/notification-rules-panel.php');
$settings = (string) file_get_contents($root . '/settings.php');
$configJs = (string) file_get_contents($root . '/assets/js/config.js');
$sidebar = (string) file_get_contents($root . '/inc/config-sidebar.php');
$bus = (string) file_get_contents($root . '/assets/js/event-bus.js');

echo "=== Phase 155 / GH#144 - the Notification Rules panel ===\n\n--- Part 1: structure ---\n\n";

// Strip comments so a sentence that DESCRIBES a forbidden construct is not mistaken for one.
$jsCode = preg_replace('#/\*.*?\*/#s', '', $js);
$jsCode = preg_replace('#^\s*//.*$#m', '', $jsCode);

// (an id built by concatenation - 'nrOn' + id for the per-row switches - is not a fixed element)
preg_match_all("/'(nr[A-Z][A-Za-z0-9]*)'(?!\s*\+)/", $js, $m);
$wanted = array_values(array_unique($m[1]));
preg_match_all('/\bid="(nr[A-Za-z0-9]+)"/', $panel, $m2);
$have = array_values(array_unique($m2[1]));
$missing = array_values(array_diff($wanted, $have));
p155_t('every element id the script looks up exists in the markup (' . count($wanted) . ' ids)' . ($missing ? ' - MISSING: ' . implode(', ', $missing) : ''), $missing === []);
$unused = array_values(array_diff($have, $wanted));
$allowedUnused = ['nrInfoCard', 'nrStatus', 'nrTable', 'nrChipsLabel', 'nrSettingsCard', 'nrRuleTitle', 'nrLogTitle', 'nrBtnSettings', 'nrMain', 'nrBtnPreset'];
$unusedReal = array_values(array_diff($unused, $allowedUnused));
p155_t('every id in the markup is used by the script (no dead controls)' . ($unusedReal ? ' - UNUSED: ' . implode(', ', $unusedReal) : ''), $unusedReal === []);

preg_match_all('/<button\b[^>]*>/i', $panel, $btns);
$bad = 0;
foreach ($btns[0] as $b) if (!preg_match('/\btype="button"/', $b)) $bad++;
p155_t('every <button> in the panel is type="button" (a button in a form submits it - GH #84); ' . count($btns[0]) . ' checked', $bad === 0 && count($btns[0]) > 15);
preg_match_all('/\.type = \'button\'|\.type = "button"/', $jsCode, $bt);
$created = preg_match_all("/el\('button'/", $jsCode);
p155_t('...and every button the script creates sets type = "button" (' . $created . ' created)', $created > 0 && count($bt[0]) >= $created);

p155_t('no innerHTML / outerHTML / insertAdjacentHTML / document.write in the script (server data goes in with textContent only)',
    !preg_match('/\.innerHTML|\.outerHTML|insertAdjacentHTML|document\.write/', $jsCode));
p155_t('ES5 only: no arrow functions, let, const or template literals',
    !preg_match('/=>|\blet\s|\bconst\s|`/', $jsCode));
p155_t('the script is one IIFE in strict mode', preg_match('/^\s*\(function \(\) \{\s*\'use strict\';/m', $jsCode) === 1 && substr(rtrim($jsCode), -5) === '})();');

echo "\n--- registration ---\n";
p155_t('settings.php includes the panel partial (and the old "will be configurable per region" stub is gone)',
    strpos($settings, "inc/notification-rules-panel.php") !== false && strpos($settings, 'per incident severity, type, and region') === false);
p155_t('...the partial still carries the tab id config.js and the docs link to (panel-notifications)', strpos($panel, 'id="panel-notifications"') !== false);
p155_t('settings.php loads the script, after config.js', ($a = strpos($settings, 'assets/js/config.js')) !== false && ($b = strpos($settings, 'assets/js/notification-rules.js')) !== false && $b > $a);
p155_t("config.js starts it when the 'notifications' tab is first opened", (bool) preg_match("/tab === 'notifications'[^\n]*NotificationRulesAdmin\.init\(\)/", $configJs));
p155_t('the SSE event the API publishes is registered in event-bus.js SSE_TYPES (an event type absent from that array is invisible to every consumer)', strpos($bus, "'notification_rules:changed'") !== false);
p155_t('the script subscribes to it', strpos($js, "'notification_rules:changed'") !== false);
p155_t('the sidebar tab is hidden from anyone without action.manage_notification_rules', (bool) preg_match("/rbac_can\('action\.manage_notification_rules'\)[^;]*_cfg_tab\('notifications'/s", $sidebar));
p155_t('the false "dispatchers can target to:<list name> on the Incident form / PAR" sentence is gone from the Email Lists panel',
    strpos($settings, 'to:&lt;list name&gt;') === false && strpos($settings, 'include_once members') === false);
p155_t('Message Routing and Webhooks each point people at Notification Rules (one link each in settings.php)', substr_count($settings, 'href="#notifications"') >= 2);
p155_t('...and so does the Email Lists panel, which only a rule can use', strpos((string) file_get_contents($root . '/inc/email-lists-panel.php'), 'href="#notifications"') !== false);

// every named control has an accessible name
preg_match_all('/<(?:input|select|textarea)\b[^>]*\bid="(nr[A-Za-z0-9]+)"[^>]*>/i', $panel, $ctl);
$noName = [];
foreach ($ctl[1] as $i => $id) {
    $tag = $ctl[0][$i];
    if (!preg_match('/for="' . preg_quote($id, '/') . '"/', $panel) && stripos($tag, 'aria-label') === false) $noName[] = $id;
}
p155_t('every form control in the markup has a <label for> or an aria-label' . ($noName ? ' - UNNAMED: ' . implode(', ', $noName) : ''), $noName === []);
p155_t('the panel has live regions for results (aria-live) and role=alert on the errors',
    strpos($panel, 'aria-live="polite"') !== false && strpos($panel, 'id="nrFormErrors" role="alert"') !== false);

echo "\n--- Part 2: behaviour (real script + real markup + real API answers, in jsdom) ---\n\n";

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
    try { db_fetch_value("SELECT queue_id FROM `{$prefix}notification_log` LIMIT 1"); }
    catch (Throwable $e) { $haveDb = false; }
}
if (!$jsdomOk || !$haveDb) {
    echo "SKIP: Part 2 needs Node + the jsdom package (npm install jsdom) and the Phase 155 schema; the structural checks above still ran\n";
    p155_done();
}

require_once __DIR__ . '/../inc/notification-engine.php';
p155_install_cleanup();
$auditFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}newui_audit_log`");
p155_on_cleanup("DELETE FROM `{$prefix}newui_audit_log` WHERE `id` > ? AND (`activity` LIKE 'notification_rule.%')", [$auditFloor]);
foreach (['notification_email_format', 'notification_prefs_mode', 'notification_log_retention_days'] as $s) p155_remember_setting($s);
// rules left by anything else would change what the page lists
$others = array_map('intval', array_column(db_fetch_all("SELECT `id` FROM `{$prefix}notification_rules` WHERE `name` NOT LIKE 'P155%'"), 'id'));
if ($others) {
    db_query("UPDATE `{$prefix}notification_rules` SET `active` = 0, `name` = CONCAT('P155HIDDEN ', `name`) WHERE `id` IN (" . implode(',', $others) . ")");
    foreach ($others as $oid) p155_on_cleanup("UPDATE `{$prefix}notification_rules` SET `name` = SUBSTRING(`name`, 13), `active` = 1 WHERE `id` = ?", [$oid]);
}

$admin = test_admin_user_id();
$logFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}notification_log`");
// the replayed test-send writes [TEST] delivery-log rows that carry no P155 marker
p155_on_cleanup("DELETE FROM `{$prefix}notification_log` WHERE `id` > ? AND `subject` LIKE '[TEST] %'", [$logFloor]);
$u0 = p155_make_user(900155501, 'p155_panel_alpha', 'p155.panel.alpha@example.invalid', '5551110000', null, []);
$u1 = p155_make_user(900155502, 'p155_panel_noemail', null, null, null, []);
db_query("UPDATE `{$prefix}user` SET `name_f` = ?, `name_l` = ? WHERE `id` = ?", ['<b>Pan</b>', 'Elist', $u0]);
$listId = p155_make_list('P155 Panel List');

$xssName = 'P155 panel <img src=x onerror="window.__xss=1"> rule';
$r0 = p155_create_rule(['name' => $xssName, 'event_type' => 'unit_assign', 'channel' => 'email', 'once_per_incident' => 1,
    'recipients' => ['user:' . $u0, 'email:ops@example.invalid'], 'email_list_id' => $listId], $admin);
$r1 = p155_create_rule(['name' => 'P155 panel slack', 'event_type' => 'incident_create', 'channel' => 'slack', 'recipients' => []], $admin);
$r2 = p155_create_rule(['name' => 'P155 panel sms', 'event_type' => 'incident_create', 'channel' => 'sms',
    'recipients' => ['user:' . $u1, 'tel:+15551230000']], $admin);
p155_t('fixtures: three rules created through the real endpoint', $r0 > 0 && $r1 > 0 && $r2 > 0);

$res = p155_make_incident(['scope' => 'P155 panel incident']);
$tid = (int) $res['id'];
notification_log_insert(['rule_id' => $r0, 'event_type' => 'unit_assign', 'ticket_id' => $tid, 'channel' => 'email', 'recipient' => 'ops@example.invalid',
    'subject' => 'P155 subject', 'body' => '<script>window.__xss2=1</script> P155 body', 'status' => 'failed', 'error' => 'Connection failed: timed out']);
notification_log_insert(['rule_id' => $r1, 'event_type' => 'incident_create', 'ticket_id' => $tid, 'channel' => 'slack', 'recipient' => 'shared:slack',
    'subject' => 'P155 subject two', 'body' => 'P155 hello', 'status' => 'sent', 'error' => null]);

$meta = p155_api('GET', 'meta', $admin)['json'];
$list = p155_api('GET', 'list', $admin)['json'];
$queue = p155_api('GET', 'queue', $admin)['json'];
$logJson = p155_api('GET', 'log', $admin, 'limit=25')['json'];
$users = p155_api('GET', 'users', $admin, 'ids=' . $u0 . ',' . $u1)['json']['users'] ?? [];
usort($users, static function ($a, $b) use ($u0) { return $a['id'] === $u0 ? -1 : 1; });
$prev = p155_api('POST', 'preview', $admin, ['name' => 'P155 x', 'event_type' => 'incident_create', 'channel' => 'email', 'recipients' => ['email:fixture@example.invalid'],
    'subject_template' => 'Subject {incident_type}', 'body_template' => '{street}'])['json'];
p155_t('fixtures: the real endpoint answered meta, list, queue, log, users and preview',
    !empty($meta['events']) && !empty($list['rules']) && isset($queue['scheduler_live']) && !empty($logJson['rows']) && count($users) === 2 && !empty($prev['rendered']));

ob_start();
if (!defined('NEWUI_ROOT')) define('NEWUI_ROOT', $root);
include $root . '/inc/notification-rules-panel.php';
$html = ob_get_clean();
p155_t('the markup the settings page serves rendered from the partial', strlen($html) > 5000 && strpos($html, 'id="panel-notifications"') !== false);

$fx = ['html' => $html, 'meta' => $meta, 'rules' => $list['rules'], 'queue' => $queue, 'log' => $logJson, 'users' => $users,
       'preview' => $prev, 'noEmailLogin' => 'p155_panel_noemail', 'testSendConfirmFirst' => false,
       // whether the REAL queue state shows a paused channel right now (another test's leftover must not decide this file's outcome)
       'breakerOpen' => count(array_filter($queue['breakers'] ?? [], static function ($b) { return !empty($b['open']) || !empty($b['half_open']); })) > 0];
$fxFile = tempnam(sys_get_temp_dir(), 'p155fx');
file_put_contents($fxFile, json_encode($fx));
$errFile = tempnam(sys_get_temp_dir(), 'p155nodeerr');
$proc = proc_open([$node, __DIR__ . '/_p155_panel_harness.cjs', $root, $fxFile], [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, null, ['bypass_shell' => true]);
$outRaw = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
if (is_resource($proc)) { fclose($pipes[1]); proc_close($proc); }
$run = json_decode(trim((string) $outRaw), true);
@unlink($fxFile);
if (!is_array($run)) {
    echo "harness output was not JSON:\n" . substr((string) $outRaw, 0, 800) . "\n" . substr((string) @file_get_contents($errFile), 0, 800) . "\n";
}
@unlink($errFile);
p155_t('the jsdom harness ran to the end with no exception' . (!empty($run['errors']) ? ' - ' . $run['errors'][0] : ''), is_array($run) && empty($run['errors']));
$checks = is_array($run) ? ($run['checks'] ?? []) : [];

$expected = [
    'denied_shows_message' => 'a 403 from the API shows the "only a Super Admin" message',
    'denied_hides_main' => '...and hides the rest of the panel',
    'denied_never_posts' => '...and sends no write request',
    'init_loads_meta_list_once' => 'init() loads meta and the list once, and a second init() does nothing',
    'denied_hidden' => 'the denied message is hidden for an authorised administrator',
    'rows_match_rules' => 'one table row per rule',
    'row_text_is_text' => 'a rule name is shown in its row',
    'no_injected_elements_in_table' => 'STORED XSS: a rule named with an <img onerror> payload renders as TEXT (no element created, no script run)',
    'user_name_not_markup' => 'STORED XSS: a user account named "<b>Pan</b>" renders as text',
    'badges' => 'a status badge per channel',
    'queue_strip_text' => 'the status strip shows the delivery queue',
    'sched_banner_matches_state' => 'the "no scheduler heartbeat" banner shows exactly when the API says the scheduler is not live',
    'breaker_banner_shown_when_open' => 'the paused-channel banner follows the API state',
    'presets' => 'one menu entry per template',
    'settings_card_filled' => 'the delivery settings card is filled from the API',
    'channel_badge_navigates' => 'a channel badge opens that channel\'s settings tab',
    'info_box_goto_links_navigate' => 'the info box links open Message Routing / Webhooks',
    'toggle_posts_rule_id_and_state' => 'the on/off switch posts the rule id and the new state',
    'delete_declined_sends_nothing' => 'Delete asks first; declining sends nothing',
    'delete_confirmed_posts' => '...confirming posts the delete for that rule',
    'delete_removes_row_in_place' => '...and removes the row without a reload',
    'info_dismiss_hides_and_remembers' => 'Hide this closes the info box and remembers it in the browser',
    'modal_opens' => 'New rule opens the modal',
    'event_options' => 'the event list is the API\'s catalogue',
    'channel_options' => 'the channel list is the API\'s channels',
    'once_visible_for_unit_assign' => 'the once-per-incident switch appears only for events that support it',
    'filters_hidden_for_non_incident_event' => 'severity/type filters are hidden for an event that is not about an incident',
    'filters_visible_for_incident_event' => '...and shown for one that is',
    'placeholder_is_event_default' => 'the grey placeholder text is the event\'s default message',
    'email_shows_email_user_list' => 'email channel: email, person and list controls; no phone',
    'sms_shows_phone_user_only' => 'SMS channel: phone and person controls only',
    'shared_hides_recipients' => 'Slack/Telegram: no recipients, and a note says it posts to one shared destination',
    'bad_email_refused' => 'a typed non-address is refused with an error and no chip',
    'emails_added_deduped' => 'typed addresses are split on commas/semicolons and de-duplicated',
    'chip_text_is_text' => 'a recipient chip shows the address as text',
    'chip_removed' => 'a chip can be removed',
    'chip_remove_has_aria' => '...and its remove button names the recipient (aria-label)',
    'user_search_calls_endpoint' => 'typing in the person box queries the users endpoint',
    'user_results_listed' => '...and lists matches',
    'user_result_markup_is_text' => '...as text',
    'user_added_as_chip' => 'choosing a person adds them and closes the list',
    'user_without_email_flagged_on_email_channel' => 'a person with no email address is flagged on an email rule',
    'switching_to_sms_prunes_email_chips' => 'switching to SMS drops the email addresses that cannot be texted',
    'bad_phone_refused' => 'a too-short phone number is refused',
    'phone_added_normalised' => 'a typed number is normalised to +digits',
    'placeholder_inserted_at_caret' => 'a field button inserts {token} at the caret of the box you were typing in',
    'counts_update' => 'the character counters follow the text',
    'preview_posts_form' => 'the live preview posts what is on the form',
    'preview_rendered' => '...and shows the API\'s rendered subject and message',
    'preview_text_not_markup' => '...as text',
    'preview_lists_deliveries' => '...with the deliveries it would make',
    'preview_warnings' => 'preview warnings show exactly when the API sent some',
    'create_posted' => 'Save posts a create',
    'create_has_csrf' => '...with the page\'s CSRF token',
    'create_closes_modal' => '...closes the modal',
    'create_success_message_with_server_warning' => '...and reports success including the server\'s warnings',
    'empty_name_refused_client_side' => 'an empty name is refused before any request',
    'ctrl_enter_saves' => 'Ctrl+Enter saves',
    'preset_prefills' => 'a template pre-fills name, event, message and the once-only switch',
    'preset_note_text' => '...and explains itself (as text)',
    'edit_fills_fields' => 'editing a rule fills the form from it',
    'edit_resolves_user_names_into_chips' => '...and shows named people, not ids',
    'edit_title' => '...with an Edit title',
    'edit_posts_update_with_id' => '...and saves with an update carrying that rule\'s id',
    'test_me_posts_mode_me' => '"Send a test to me" posts mode me, unconfirmed',
    'test_result_text' => '...and reports who was sent to and what was skipped',
    'test_real_asks_first_listing_destinations' => '"Send a test to the real recipients" asks first, listing the destinations and warning about pagers',
    'test_real_declined_sends_nothing' => '...declining sends nothing',
    'test_real_confirmed_flags_confirm_real' => '...confirming posts mode rule with confirm_real',
    'row_test_posts_me' => 'the row\'s Test button sends one test to the administrator',
    'duplicate_posts' => 'Duplicate posts the duplicate',
    'settings_payload' => 'Delivery settings posts the three values',
    'settings_saved_message' => '...and says Saved',
    'log_loaded' => 'the delivery log loads',
    'log_markup_is_text' => 'STORED XSS: a <script> in a logged message renders as text',
    'log_body_hidden_until_asked' => 'a message body stays hidden until "Show message"',
    'log_body_expands_as_text' => '...and then shows as text in a <pre>',
    'log_ticket_link' => 'an incident number links to incident-detail.php?id=N',
    'log_status_labels' => 'each row\'s status badge uses the API\'s effective status',
    'log_filter_sent_to_server' => 'log filters are sent to the server',
    'no_xss_flag_ever_set' => 'no injected script ever ran',
    'shared_test_asks_after_409_then_resends_with_confirm' => 'a shared-channel test that the server says needs confirmation asks, then re-sends with confirm_real',
];
foreach ($expected as $name => $label) {
    p155_t($label . '  [' . $name . ']', array_key_exists($name, $checks) && $checks[$name] === true);
}
$extra = array_values(array_diff(array_keys($checks), array_keys($expected)));
p155_t('every check the harness ran is asserted here (a check nobody reads is a dead control)' . ($extra ? ' - UNASSERTED: ' . implode(', ', $extra) : ''), $extra === []);
p155_t('every form control reachable in the live panel has an accessible name' . (!empty($run['unlabeled']) ? ' - ' . implode(', ', $run['unlabeled']) : ''), is_array($run) && empty($run['unlabeled']));

echo "\n--- replaying what the page sent against the REAL endpoint (a field-name drift fails here) ---\n";
$strip = static function (?array $b): array {
    $b = $b ?? [];
    unset($b['csrf_token']);
    return $b;
};
$createBody = $strip($run['createBody'] ?? null);
p155_t('the page built a create payload', isset($createBody['name']) && $createBody['name'] === 'P155 wired rule');
$cr = p155_api('POST', 'create', $admin, $createBody);
if (!empty($cr['json']['id'])) $GLOBALS['P155_FIX']['rules'][] = (int) $cr['json']['id'];
p155_t('the real endpoint ACCEPTS the page\'s create payload (HTTP 200, a rule id back)', $cr['status'] === 200 && !empty($cr['json']['id']));
$stored = !empty($cr['json']['id']) ? db_fetch_one("SELECT * FROM `{$prefix}notification_rules` WHERE `id` = ?", [(int) $cr['json']['id']]) : null;
$storedRec = $stored ? json_decode((string) $stored['recipients'], true) : [];
p155_t('...and stored exactly what the page showed: name, event, channel, subject, message, and the person it kept as a recipient',
    $stored && $stored['event_type'] === 'incident_create' && $stored['channel'] === 'email' && $stored['subject_template'] === 'Subject {incident_type}'
    && $stored['body_template'] === '{street|clean};{city}' && $storedRec === ['user:' . $u0]);
$updateBody = $strip($run['updateBody'] ?? null);
$up = p155_api('POST', 'update', $admin, $updateBody);
p155_t('the real endpoint ACCEPTS the page\'s update payload for an existing rule', $up['status'] === 200 && !empty($up['json']['ok']));
$prevBody = null;
foreach (array_reverse($run['posts'] ?? []) as $p) if ($p['action'] === 'preview') { $prevBody = $strip($p['body']); break; }
$pv = $prevBody ? p155_api('POST', 'preview', $admin, $prevBody) : ['status' => 0, 'json' => null];
p155_t('the real endpoint ACCEPTS the page\'s preview payload and renders it', $pv['status'] === 200 && !empty($pv['json']['rendered']));
$setBody = null;
foreach (array_reverse($run['posts'] ?? []) as $p) if ($p['action'] === 'save_settings') { $setBody = $strip($p['body']); break; }
$st = $setBody ? p155_api('POST', 'save_settings', $admin, $setBody) : ['status' => 0, 'json' => null];
p155_t('the real endpoint ACCEPTS the page\'s delivery-settings payload', $st['status'] === 200 && ($st['json']['settings']['email_format'] ?? '') === 'html');
p155_api('POST', 'save_settings', $admin, ['email_format' => 'text', 'prefs_mode' => 'explicit_only', 'retention_days' => 180]);
$togBody = null; $delBody = null; $dupBody = null;
foreach ($run['posts'] ?? [] as $p) {
    if ($p['action'] === 'toggle') $togBody = $strip($p['body']);
    if ($p['action'] === 'duplicate') $dupBody = $strip($p['body']);
}
$tg = $togBody ? p155_api('POST', 'toggle', $admin, $togBody) : ['status' => 0, 'json' => null];
p155_t('...and its toggle payload', $tg['status'] === 200 && isset($tg['json']['active']));
$dp = $dupBody ? p155_api('POST', 'duplicate', $admin, $dupBody) : ['status' => 0, 'json' => null];
if (!empty($dp['json']['id'])) $GLOBALS['P155_FIX']['rules'][] = (int) $dp['json']['id'];
p155_t('...and its duplicate payload', $dp['status'] === 200 && !empty($dp['json']['id']));
$tsBody = null;
foreach ($run['posts'] ?? [] as $p) if ($p['action'] === 'test_send' && ($p['body']['mode'] ?? '') === 'me') { $tsBody = $strip($p['body']); break; }
$cap = sys_get_temp_dir() . '/p155_wire_cap_' . getmypid() . '.jsonl';
$ts = $tsBody ? p155_api('POST', 'test_send', $admin, $tsBody, ['capture_file' => $cap]) : ['status' => 0, 'json' => null];
p155_t('...and its test-send payload is understood (it answers 200, a clear 422, or the 429 throttle - never a 400 or 500)', in_array($ts['status'], [200, 422, 429], true));
@unlink($cap);

p155_cleanup();
p155_t('fixtures are gone after cleanup (verified by querying)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log` WHERE `subject` LIKE 'P155%'") === 0);
p155_done();
