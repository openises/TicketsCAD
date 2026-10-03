<?php
/**
 * Phase 155 (GH#144) - how a rule's text is RENDERED and what format it goes out in.
 *
 *   D12  Mail was sent as text/html with "\n" newlines and UNESCAPED values, so a
 *        multi-line template (the Active911 / Cadpage shape) collapsed into one
 *        run-on line, and an incident's title - which can originate from an
 *        inbound message - was injected into outgoing mail as HTML.
 *
 * Covers the placeholders (incl. the new ones), the `|clean` filter that keeps a
 * semicolon in a street name from shifting every later field of an Active911
 * StandardA line, single-line subjects (CR/LF injection), text vs HTML mode (the
 * notification_email_format setting is a CONTROL: flipping it changes the MIME
 * type and the escaping), unknown placeholders, the size cap, single-pass
 * substitution, and that every shipped PRESET passes the real validation.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_p155_notify_helpers.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
$prefix = $GLOBALS['db_prefix'] ?? '';
try { db_fetch_value("SELECT once_per_incident FROM `{$prefix}notification_rules` LIMIT 1"); }
catch (Throwable $e) { p155_skip('Phase 155 schema missing - run php sql/run_migrations.php'); }

require_once __DIR__ . '/../inc/assignment-write.php';
p155_install_cleanup();
p155_install_capture();
p155_scheduler(true);
p155_remember_setting('notification_email_format');
p155_on_cleanup("DELETE FROM `{$prefix}messages` WHERE `subject` LIKE 'P155%'");

echo "=== Phase 155 / GH#144 - rendering and mail format ===\n\n";
$admin = test_admin_user_id();

echo "--- pure rendering ---\n";
$ctx = ['ticket_id' => 7, 'scope' => 'Fire; smoke', 'street' => '12 Main St; Unit B', 'city' => "River\nside", 'severity' => 2,
        'severity_label' => 'Critical', 'incident_type' => 'Structure Fire', 'units' => 'E1, L1', 'unit_count' => 2,
        'incident_number' => '26-0007', 'description' => 'desc', 'state' => 'MN', 'lat' => '44.1', 'lng' => '-93.2',
        'user' => 'dispatcher1', 'event' => 'Unit dispatched', '_when' => strtotime('2026-10-02 14:05:09')];
p155_t('plain tokens', notification_render('#{ticket_id} {incident_type} {incident_number} {state}', $ctx) === '#7 Structure Fire 26-0007 MN');
p155_t('the new tokens: {units} {unit_count} {lat} {lng} {description} {event} {user}',
    notification_render('{units}|{unit_count}|{lat}|{lng}|{description}|{event}|{user}', $ctx) === 'E1, L1|2|44.1|-93.2|desc|Unit dispatched|dispatcher1');
p155_t('{address} is street and city together', notification_render('{address}', ['street' => '1 A St', 'city' => 'Bton']) === '1 A St Bton');
p155_t('{date} {time} {datetime} come from the event time captured at fire time, not "now"',
    notification_render('{date} {time} {datetime}', $ctx) === '2026-10-02 14:05:09 2026-10-02 14:05:09'
    || notification_render('{date}|{time}|{datetime}', $ctx) === '2026-10-02|14:05:09|2026-10-02 14:05:09');
p155_t('tokens are case-insensitive', notification_render('{STREET}', ['street' => 'x']) === 'x');
$unknown = [];
$out = notification_render('hello {nonsense} and {street}', ['street' => 'S'], 'text', $unknown);
p155_t('an unknown token is left AS TYPED and reported', $out === 'hello {nonsense} and S' && count($unknown) === 1 && strpos($unknown[0], 'nonsense') !== false);
p155_t('substitution is single-pass: a value that is itself "{street}" is NOT expanded', notification_render('{scope}', ['scope' => '{street}', 'street' => 'BOOM']) === '{street}');
p155_t('NUL bytes are removed from values', notification_render('{scope}', ['scope' => "a\0b"]) === 'ab');
p155_t('a rendered message is capped (the log column is TEXT)', strlen(notification_render('{scope}', ['scope' => str_repeat('x', 50000)])) === NOTIFICATION_BODY_MAX);
p155_t('|clean strips ; and line breaks and collapses spaces', notification_render('{street|clean}/{city|clean}/{scope|clean}', $ctx) === '12 Main St Unit B/River side/Fire smoke');
p155_t('a subject is forced onto ONE line', notification_render_subject("S: {scope}", ['scope' => "line1\r\nBcc: evil@example.invalid"]) === 'S: line1 Bcc: evil@example.invalid');
p155_t('a subject is capped at the column width (255)', strlen(notification_render_subject('{scope}', ['scope' => str_repeat('s', 400)])) === 255);
$htmlOut = notification_render("<b>{scope}</b>\n{street}", ['scope' => '<script>alert(1)</script>', 'street' => 'a & b'], 'html');
p155_t('html mode escapes every VALUE (the administrator\'s own template markup is kept)', $htmlOut === "<b>&lt;script&gt;alert(1)&lt;/script&gt;</b><br>\na &amp; b");

echo "\n--- the shipped presets pass the real validation ---\n";
foreach (notification_rule_presets() as $preset) {
    $fields = $preset['rule'] + ['recipients' => ($preset['rule']['channel'] === 'email' ? ['email:active911.alert@example.invalid'] : [])];
    $fields['name'] = 'P155 preset ' . $preset['id'];
    $id = p155_create_rule($fields, $admin);
    p155_t("preset '{$preset['id']}' is accepted by api/notification-rules.php", $id > 0);
}
$stdA = null; $cad = null;
foreach (notification_rule_presets() as $pr) { if ($pr['id'] === 'active911_standarda') $stdA = $pr; if ($pr['id'] === 'active911_cadpage') $cad = $pr; }
p155_t('both Active911 presets are once-per-incident and fire on unit dispatch', $stdA['rule']['once_per_incident'] === 1 && $cad['rule']['once_per_incident'] === 1
    && $stdA['rule']['event_type'] === 'unit_assign' && $cad['rule']['event_type'] === 'unit_assign');
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 preset %'");
$GLOBALS['P155_FIX']['rules'] = [];

echo "\n--- Active911 StandardA end to end (a semicolon in the street must not shift the fields) ---\n";
$typeName = (string) db_fetch_value("SELECT `type` FROM `{$prefix}in_types` WHERE `id` = 1");
$unit = p155_make_responder('P155 Render Unit');
$inc = p155_make_incident(['in_types_id' => 1, 'scope' => "Fire; smoke\nshowing", 'street' => '12 Main St; Unit B', 'city' => 'River;side']);
$tid = (int) $inc['id'];
$preset = $stdA['rule'];
$preset['name'] = 'P155 render stdA';
$preset['recipients'] = ['email:active911.alert@example.invalid'];
p155_create_rule($preset, $admin);
assign_create_internal($tid, $unit, '', 0);
p155_drain();
$m = $GLOBALS['P155_SENT'][0] ?? ['body' => '', 'subject' => '', 'content_type' => ''];
p155_t('the StandardA body is EXACTLY NATURE;ADDRESS;CITY;DETAILS with no stray delimiter',
    $m['body'] === $typeName . ';12 Main St Unit B;River side;Fire smoke showing');
p155_t('it is sent as TEXT/PLAIN (not text/html) by default', $m['content_type'] === 'text/plain; charset=UTF-8');
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 render %'"); p155_reset_deliveries();

echo "\n--- Cadpage end to end: real line breaks survive ---\n";
$preset = $cad['rule'];
$preset['name'] = 'P155 render cad';
$preset['recipients'] = ['email:active911.alert@example.invalid'];
p155_create_rule($preset, $admin);
$unit2 = p155_make_responder('P155 Render Unit Two');
assign_create_internal($tid, $unit2, '', 0);
p155_drain();
$cb = (string) ($GLOBALS['P155_SENT'][0]['body'] ?? '');
$lines = preg_split('/\n/', $cb);
p155_t('a Cadpage body is several NAME: VALUE lines (they used to be one run-on line)', count($lines) >= 9 && strpos($lines[0], 'CALL: ') === 0);
p155_t('...with the address on its own ADDR: line and the unit on UNIT:',
    in_array('ADDR: 12 Main St; Unit B', $lines, true) && in_array('UNIT: P155 Render Unit Two', $lines, true));
$num = (string) db_fetch_value("SELECT `incident_number` FROM `{$prefix}ticket` WHERE `id` = ?", [$tid]);
p155_t('...and the case number on ID:', in_array('ID: ' . $num, $lines, true));
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 render %'"); p155_reset_deliveries();

echo "\n--- mail format: text (default) vs html (CONTROL: flipping the setting changes the output) ---\n";
$hostile = p155_make_incident(['in_types_id' => 1, 'scope' => 'P155 <script>alert(1)</script> & more', 'street' => 'S']);
$htid = (int) $hostile['id'];
p155_create_rule(['name' => 'P155 render fmt', 'event_type' => 'incident_close', 'channel' => 'email', 'recipients' => ['email:fmt@example.invalid'],
    'subject_template' => "P155 {scope}\r\nBcc: evil@example.invalid", 'body_template' => "Line one: {scope}\nLine two"], $admin);
p155_set_setting('notification_email_format', 'text');
incident_update_status_internal($htid, 1, 0);
p155_drain();
$m = $GLOBALS['P155_SENT'][0] ?? ['body' => '', 'subject' => '', 'content_type' => ''];
p155_t('text mode: Content-Type text/plain', $m['content_type'] === 'text/plain; charset=UTF-8');
p155_t('text mode: the body keeps real newlines and the title is NOT HTML-escaped (it is plain text)',
    $m['body'] === "Line one: P155 <script>alert(1)</script> & more\nLine two");
p155_t('the subject is ONE line even though the template tried to add a Bcc: header',
    strpos($m['subject'], "\n") === false && strpos($m['subject'], "\r") === false);
p155_reset_deliveries();
incident_update_status_internal($htid, 2, 0);
p155_set_setting('notification_email_format', 'html');
incident_update_status_internal($htid, 1, 0);
p155_drain();
$m = $GLOBALS['P155_SENT'][0] ?? ['body' => '', 'subject' => '', 'content_type' => ''];
p155_t('CONTROL html mode: Content-Type text/html', $m['content_type'] === 'text/html; charset=UTF-8');
p155_t('html mode: the incident title is ESCAPED (an inbound message cannot inject markup) and newlines become <br>',
    $m['body'] === "Line one: P155 &lt;script&gt;alert(1)&lt;/script&gt; &amp; more<br>\nLine two");
p155_t('html mode: the SUBJECT stays plain text (it is a header, not markup)', strpos($m['subject'], '&lt;') === false);
p155_reset_deliveries();

echo "\n--- html mode applies to EMAIL only ---\n";
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` = 'P155 render fmt'");
p155_create_rule(['name' => 'P155 render sms', 'event_type' => 'incident_close', 'channel' => 'sms', 'recipients' => ['tel:5557778888'],
    'subject_template' => '', 'body_template' => "A & B\nC"], $admin);
incident_update_status_internal($htid, 2, 0);
incident_update_status_internal($htid, 1, 0);
p155_drain();
$m = $GLOBALS['P155_SENT'][0] ?? ['body' => '', 'content_type' => 'x'];
p155_t('an SMS body is never HTML-escaped even when the email format is html', $m['body'] === "A & B\nC" && $m['content_type'] === '');
p155_set_setting('notification_email_format', 'text');

echo "\n--- a bogus format value falls back to the default (never trusted) ---\n";
p155_set_setting('notification_email_format', 'rtf');
p155_t('an unrecognised stored format reads as text', notification_setting('notification_email_format') === 'text');
p155_set_setting('notification_email_format', 'text');

p155_cleanup();
p155_t('fixtures are gone after cleanup (verified by querying)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE `scope` LIKE 'P155%'") === 0);
p155_done();
