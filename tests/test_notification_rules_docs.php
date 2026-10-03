<?php
/**
 * Phase 155 (GH#144) - the Notification Rules documentation says what the code does.
 *
 * A doc sentence in the present tense about behaviour is a claim to verify, not
 * documentation to trust (CLAUDE.md: the IIS "Get-CimInstance fallback" that did not
 * exist; the Email Lists panel that promised "to:<list name>" on a form that never
 * had it). This compares docs/NOTIFICATION-RULES.md, the in-app help topic and the
 * user guide against the code itself: every event, every field, the two Active911
 * recipes (character for character), every setting, every limit.
 *
 * No database needed.
 */
require_once __DIR__ . '/_p155_notify_helpers.php';
$root = realpath(__DIR__ . '/..');
require_once $root . '/inc/notification-events.php';

$doc = (string) file_get_contents($root . '/docs/NOTIFICATION-RULES.md');
$help = (string) file_get_contents($root . '/help.php');
$guide = (string) file_get_contents($root . '/docs/NEWUI-USER-GUIDE.md');
$admin = (string) file_get_contents($root . '/inc/notification-rules-admin.php');
$index = (string) file_get_contents($root . '/docs/INDEX.md');
$fanout = (string) file_get_contents($root . '/inc/notify-fanout.php');

echo "=== Phase 155 / GH#144 - the Notification Rules docs match the code ===\n\n";

echo "--- events ---\n";
foreach (notification_events() as $id => $e) {
    p155_t("the guide names the event \"{$e['label']}\" ({$id})", strpos($doc, '**' . $e['label'] . '**') !== false);
}
$userGuideEvents = 0;
foreach (notification_events() as $e) if (stripos($guide, $e['label']) !== false) $userGuideEvents++;
p155_t('the user guide names the events too (matching the code\'s labels, case aside)', $userGuideEvents >= 5);
foreach (notification_events() as $id => $e) {
    $onceDoc = (bool) preg_match('/\*\*' . preg_quote($e['label'], '/') . '\*\*[^\n]*Supports "only the first time/', $doc);
    p155_t("\"{$e['label']}\": the guide " . ($e['once_capable'] ? 'says' : 'does not say') . ' it supports "only the first time"', $onceDoc === $e['once_capable']);
}

echo "\n--- fields ---\n";
$missing = [];
foreach (notification_placeholders() as $tok => $d) {
    if (strpos($doc, '`{' . $tok . '}`') === false) $missing[] = $tok;
}
p155_t('every message field the engine knows is in the guide\'s table' . ($missing ? ' - MISSING: ' . implode(', ', $missing) : ''), $missing === []);
p155_t('the guide documents the |clean filter', strpos($doc, '{street|clean}') !== false);

echo "\n--- the Active911 recipes are the shipped templates, character for character ---\n";
$presets = [];
foreach (notification_rule_presets() as $p) $presets[$p['id']] = $p['rule'];
p155_t('StandardA message in the guide == the template', strpos($doc, $presets['active911_standarda']['body_template']) !== false);
$cadLines = explode("\n", $presets['active911_cadpage']['body_template']);
$allLines = true;
foreach ($cadLines as $l) if (strpos($doc, $l . "\n") === false) $allLines = false;
p155_t('every Cadpage line in the guide == the template\'s lines', $allLines && count($cadLines) === 9);
p155_t('the guide says the Active911 recipes are best effort and unverified against a real agency', stripos($doc, 'Best effort') !== false);
p155_t('...and that the first-unit-only switch matters (both recipes ship with it on)',
    !empty($presets['active911_standarda']['once_per_incident']) && !empty($presets['active911_cadpage']['once_per_incident'])
    && stripos($doc, 'without it each unit dispatched pages again') !== false);

echo "\n--- settings, limits, defaults ---\n";
foreach (['notification_email_format', 'notification_prefs_mode', 'notification_log_retention_days', 'notify_inline_budget_s', 'notify_breaker_threshold', 'notify_breaker_cooloff_s', 'sched_stale_cutoff_min'] as $s) {
    p155_t("the guide names the setting {$s}", strpos($doc, '`' . $s . '`') !== false);
}
preg_match('/NOTIFICATION_RULES_MAX\',\s*(\d+)/', $admin, $m1);
preg_match('/NOTIFICATION_RECIPIENTS_MAX\',\s*(\d+)/', $admin, $m2);
preg_match('/NOTIFICATION_BODY_TEMPLATE_MAX\',\s*(\d+)/', $admin, $m3);
preg_match('/NOTIFICATION_TEST_THROTTLE_COUNT\',\s*(\d+)/', $admin, $m4);
preg_match('/NOTIFICATION_TEST_THROTTLE_WINDOW\',\s*(\d+)/', $admin, $m5);
p155_t('the guide\'s limits are the code\'s: ' . "{$m1[1]} rules, {$m2[1]} recipients, {$m3[1]}-character message",
    strpos($doc, $m1[1] . ' rules, ' . $m2[1] . ' recipients per rule') !== false && strpos($doc, $m3[1] . '-character message') !== false);
p155_t('...and the test-send throttle (' . $m4[1] . ' per ' . ((int) $m5[1] / 60) . ' minutes)',
    strpos($doc, 'five per five minutes') !== false && (int) $m4[1] === 5 && (int) $m5[1] === 300);
preg_match("/NOTIFY_INLINE_BUDGET_DEFAULT_S',\s*(\d+)/", $fanout, $f1);
preg_match("/NOTIFY_BREAKER_THRESHOLD_DEFAULT',\s*(\d+)/", $fanout, $f2);
preg_match("/NOTIFY_BREAKER_COOLOFF_DEFAULT_S',\s*(\d+)/", $fanout, $f3);
p155_t('the guide\'s advanced defaults are the code\'s (inline budget ' . $f1[1] . ' s, breaker ' . $f2[1] . ' failures / ' . $f3[1] . ' s)',
    strpos($doc, '`notify_inline_budget_s` (default ' . $f1[1] . ')') !== false
    && strpos($doc, '`notify_breaker_threshold` (' . $f2[1] . ')') !== false
    && strpos($doc, '`notify_breaker_cooloff_s` (' . $f3[1] . ')') !== false);

echo "\n--- permissions and where things are ---\n";
p155_t('the guide names the permission', strpos($doc, '`action.manage_notification_rules`') !== false);
p155_t('...and says Super Admin only, not grantable to Org Admin', stripos($doc, 'Super Admin') !== false && stripos($doc, 'cannot be granted to Org Admin') !== false);
p155_t('the guide does not claim a personal preferences screen exists (there is none in this release)',
    stripos($doc, 'This release has no personal preferences screen') !== false && !preg_match('/Profile page|My notification preferences/i', $doc));
foreach (['Settings → Communications & Integrations', 'Email Configuration', 'SMS Configuration', 'Web Push Notifications', 'Chat Settings'] as $label) {
    p155_t("a settings label the guide uses is spelled as the sidebar spells it: {$label}", strpos($doc, $label) !== false);
}
p155_t('the in-app help has a Notification Rules topic linking the guide',
    strpos($help, "'slug'  => 'notification-rules'") !== false && strpos($help, 'documentation/?doc=NOTIFICATION-RULES') !== false);
p155_t('the user guide has a Notification Rules section linking the guide',
    strpos($guide, '## Notification Rules (Email, Text and Chat Alerts for CAD Events)') !== false && strpos($guide, '(NOTIFICATION-RULES.md)') !== false);
p155_t('the documentation index lists the guide', strpos($index, '(NOTIFICATION-RULES.md)') !== false);

echo "\n--- the delivery statuses the guide describes are the ones the log can show ---\n";
$js = (string) file_get_contents($root . '/assets/js/notification-rules.js');
foreach (['Sent', 'Waiting', 'Expired', 'Failed', 'Skipped', 'Cancelled'] as $st) {
    p155_t("the log can show \"{$st}\" and the guide explains it", strpos($js, "'{$st}'") !== false && strpos($doc, '**' . $st . '**') !== false);
}
preg_match('/SCHED_STALE_CUTOFF_DEFAULT_MIN\',\s*(\d+)/', (string) file_get_contents($root . '/inc/scheduled-jobs.php'), $sc);
p155_t('the guide\'s stale cutoff (60 minutes) is the setting\'s default in code',
    strpos($doc, 'default 60 minutes') !== false && isset($sc[1]) && (int) $sc[1] === 60);

p155_done();
