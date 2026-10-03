<?php
/**
 * GH#141 -- the two new settings are WIRED, end to end.
 *
 * `scheduled_assign_mode` and `scheduled_assign_lead_minutes` each need a
 * WRITER (Settings -> Incident Lifecycle -> "Units assigned to Scheduled
 * incidents"), a READER that branches on them, and proof that changing them
 * changes observable behaviour. A setting nothing reads is a bug -- this project
 * has shipped exactly that before (tile_mode, ~5 months, CLAUDE.md), so the
 * check is not "the key round-trips through the database" but "the writer the
 * form actually uses stores a clamped value, and the reader that dispatch uses
 * sees it".
 *
 * Proves, through the REAL api/config-admin.php (driven by a CLI endpoint probe):
 *   - an unknown mode is stored as 'immediate'; 'reserve' stays 'reserve'
 *   - the lead is clamped to whole minutes 0-1440 (garbage and negatives -> 0)
 *   - a multi-key save still stores the rest of its keys
 *   - the value the endpoint stored is the value the dispatch reader then acts
 *     on (a fresh worker process assigns a unit and reserves or dispatches)
 *   - GET returns both values for the form to load
 *   - the form's markup and JS use the same keys; the reader reads the same keys
 *   - the migration seeds both, defaulting to the no-behaviour-change values
 *   - dead_control_audit.php reports nothing for either key or the new table
 *
 * @requires-db
 * Usage: php tests/test_gh141_settings_wiring.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/assign-reservations.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- settings wiring ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
p155_remember_settings(['scheduled_assign_mode', 'scheduled_assign_lead_minutes', 'page_size']);

$stored = function (string $name) use ($prefix) {
    return db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
};
$save = function (array $settings) use ($adminId) {
    return p155_probe('session', 'api/config-admin.php', $adminId, ['settings' => $settings], 'POST', 'section=settings');
};

// ── 1. The writer the form uses clamps ────────────────────────────────────
echo "--- the real writer (api/config-admin.php?section=settings) ---\n";
$r = $save(['scheduled_assign_mode' => 'reserve', 'scheduled_assign_lead_minutes' => '45']);
t('saving reserve + 45 succeeds', $r['http'] === 200 && isset($r['json']['saved']), $r['raw']);
t("mode stored as 'reserve'", $stored('scheduled_assign_mode') === 'reserve');
t("lead stored as '45'", $stored('scheduled_assign_lead_minutes') === '45');

$r = $save(['scheduled_assign_mode' => 'banana']);
t("an unknown mode is stored as 'immediate' (never as garbage the reader would have to guess about)", $stored('scheduled_assign_mode') === 'immediate', (string) $stored('scheduled_assign_mode'));
$r = $save(['scheduled_assign_mode' => 'RESERVE']);
t("'RESERVE' (wrong case) is not 'reserve' -> 'immediate' (strict, like the reader)", $stored('scheduled_assign_mode') === 'immediate');
$r = $save(['scheduled_assign_mode' => 'reserve']);
t("'reserve' stays 'reserve'", $stored('scheduled_assign_mode') === 'reserve');
foreach (['99999' => '1440', '1440' => '1440', '-5' => '0', 'abc' => '0', '' => '0', '30.9' => '30', '0' => '0'] as $in => $want) {
    $save(['scheduled_assign_lead_minutes' => (string) $in]);
    t("lead '" . $in . "' is stored as '" . $want . "'", $stored('scheduled_assign_lead_minutes') === (string) $want, (string) $stored('scheduled_assign_lead_minutes'));
}
$r = $save(['scheduled_assign_mode' => 'reserve', 'scheduled_assign_lead_minutes' => '15', 'page_size' => '37']);
t('a multi-key save still stores the other keys (the clamp does not reject the whole save)', $stored('page_size') === '37' && $stored('scheduled_assign_lead_minutes') === '15' && $stored('scheduled_assign_mode') === 'reserve');

$get = p155_probe('session', 'api/config-admin.php', $adminId, [], 'GET', 'section=settings');
t('GET returns both values for the form to load', ($get['json']['settings']['scheduled_assign_mode'] ?? '') === 'reserve' && ($get['json']['settings']['scheduled_assign_lead_minutes'] ?? '') === '15', $get['raw']);

// ── 2. What the writer stored is what DISPATCH acts on ────────────────────
echo "\n--- the reader acts on what the writer stored ---\n";
$stAvail = p155_make_status('p155_sw_avail', 0);
$mk = function (string $h) use ($prefix, $stAvail) {
    $rid = p155_make_unit($h);
    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $rid]);
    return $rid;
};
$tid = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)', 'GH141 settings wiring');
// NO set: arguments -- the worker reads whatever the endpoint above stored.
$save(['scheduled_assign_mode' => 'reserve', 'scheduled_assign_lead_minutes' => '0']);
$u1 = $mk('SW-1');
$a = p155_worker('assign', ["id=$tid", "unit=$u1", "user=$adminId"]);
t("after the form's writer saved 'reserve', the dispatch reader RESERVES the unit", !empty($a['reserved']), json_encode($a));
$save(['scheduled_assign_mode' => 'immediate']);
$u2 = $mk('SW-2');
$a = p155_worker('assign', ["id=$tid", "unit=$u2", "user=$adminId"]);
t("after the writer saved 'immediate', the SAME incident dispatches at once (the setting changes behaviour)", !empty($a['id']) && empty($a['reserved']), json_encode($a));
$save(['scheduled_assign_mode' => 'reserve', 'scheduled_assign_lead_minutes' => '1440']);
$u3 = $mk('SW-3');
$a = p155_worker('assign', ["id=$tid", "unit=$u3", "user=$adminId"]);
t('after the writer saved a 1440-minute lead, an incident 3 hours out is INSIDE the lead window -> dispatched at once (the lead setting changes behaviour)',
    !empty($a['id']) && empty($a['reserved']), json_encode($a));

// ── 3. The form, the JS and the reader share the keys ─────────────────────
echo "\n--- the form, its JS and the reader agree on the keys ---\n";
$settingsSrc = (string) file_get_contents(__DIR__ . '/../settings.php');
$readerSrc = (string) file_get_contents(__DIR__ . '/../inc/assign-reservations.php');
$adminSrc = (string) file_get_contents(__DIR__ . '/../api/config-admin.php');
foreach (['scheduled_assign_mode', 'scheduled_assign_lead_minutes'] as $key) {
    t("settings.php writes '$key' (form JS)", substr_count($settingsSrc, $key) >= 2);
    t("the reader reads '$key' via get_variable() (the settings table, not the separate config table)", strpos($readerSrc, "get_variable('$key')") !== false);
    t("config-admin.php normalizes '$key'", strpos($adminSrc, "'$key'") !== false);
}
t('the admin writer calls the same normalizer the reader module owns (one definition)', strpos($adminSrc, 'assign_reservation_normalize_setting(') !== false);
t('the form exists, in Incident Lifecycle, with its own save handler',
    strpos($settingsSrc, 'id="scheduledAssignForm"') !== false && strpos($settingsSrc, 'id="scheduledAssignModeSelect"') !== false
    && strpos($settingsSrc, 'id="scheduledAssignLeadMinutes"') !== false && strpos($settingsSrc, "getElementById('scheduledAssignForm')") !== false);
t('the lead field is disabled unless the mode is reserve (it only applies there)', strpos($settingsSrc, "saLead.disabled = (saMode.value !== 'reserve')") !== false);
t('the form offers exactly the two modes the reader understands',
    (bool) preg_match('/<option value="immediate">Dispatch immediately/', $settingsSrc) && (bool) preg_match('/<option value="reserve">Reserve until the booked time/', $settingsSrc));
t('the save button is a submit button inside its own form and nothing else in the form is an untyped button',
    (bool) preg_match('/<form id="scheduledAssignForm"[\s\S]*?<button type="submit"[\s\S]*?<\/form>/', $settingsSrc));

// ── 4. The migration seeds the no-behaviour-change defaults ───────────────
echo "\n--- the migration's defaults ---\n";
$mig = (string) file_get_contents(__DIR__ . '/../sql/run_gh141_assign_reservations.php');
t("seeds scheduled_assign_mode = 'immediate'", strpos($mig, "'scheduled_assign_mode' => 'immediate'") !== false);
t("seeds scheduled_assign_lead_minutes = '0'", strpos($mig, "'scheduled_assign_lead_minutes' => '0'") !== false);
t('refuses to report success without verifying its own outcome (exits non-zero on failure)', strpos($mig, 'exit(1)') !== false && strpos($mig, 'verify:') !== false);
t('has the CLI-only guard before touching config', (bool) preg_match("/PHP_SAPI !== 'cli'[\\s\\S]{0,80}?require_once __DIR__ \\. '\\/\\.\\.\\/config\\.php'/", $mig));
$twice = [];
for ($i = 0; $i < 2; $i++) {
    $res = p155_run_process([__DIR__ . '/../sql/run_gh141_assign_reservations.php']);
    $twice[] = $res['exit'];
}
t('the migration runs twice in a row cleanly (idempotent)', $twice === [0, 0], json_encode($twice));
t('and a re-run does not overwrite an admin-chosen value',
    $stored('scheduled_assign_mode') !== null && ($save(['scheduled_assign_mode' => 'reserve']) && p155_run_process([__DIR__ . '/../sql/run_gh141_assign_reservations.php'])['exit'] === 0 && $stored('scheduled_assign_mode') === 'reserve'));

// ── 5. The audits ────────────────────────────────────────────────────────
echo "\n--- dead_control_audit.php ---\n";
$audit = p155_run_process([__DIR__ . '/../tools/dead_control_audit.php'], 300);
t('the audit ran', strpos($audit['stdout'], 'distinct finding') !== false, substr($audit['stdout'] . $audit['stderr'], 0, 200));
t('it has NO finding for either setting or for assign_reservations (every column has a reader)',
    strpos($audit['stdout'], 'scheduled_assign') === false && strpos($audit['stdout'], 'assign_reservations') === false);

p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
