<?php
/**
 * GH#147 -- incident.status_changed fires EXACTLY ONCE per real change, from
 * EVERY route a status can take, and the External API PATCH decides its
 * refusals BEFORE it writes anything.
 *
 * One real invocation per route (the endpoints are driven through their real
 * files via tests/_p155_endpoint_probe.php, the auto-close sweep and the
 * scheduled activation through their real entry points):
 *
 *   UI Close / Reopen / Schedule        api/incident-update.php
 *   External API PATCH {status}         api/external/v1/incidents.php (bearer)
 *   Auto-close sweep                    auto_close_sweep()
 *   Scheduled activation                incident_activate_due_scheduled()
 *   Major Incident close (cascade)      api/major-incidents.php action=close
 *
 * and the F7 ordering fix: a PATCH carrying other fields plus a `status` the
 * caller may not set used to save the other fields and THEN answer 403.
 *
 * @requires-db
 * Usage: php tests/test_gh147_every_path_fires_once.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/auto_close.php';
require_once __DIR__ . '/../inc/scheduled-incidents.php';
require_once __DIR__ . '/../inc/external-auth.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#147 -- every status route fires once ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
$_SESSION['user_id'] = $adminId;
$_SESSION['user']    = 'gh147-test-user';
p155_remember_settings(['disposition_required_on_close', 'primary_unit_mode']);

$sc   = function (int $tid): array { return p155_audit_rows($tid, 'status_change'); };
$col  = function (int $tid, string $c) use ($prefix) { return db_fetch_value("SELECT `{$c}` FROM `{$prefix}ticket` WHERE id = ?", [$tid]); };
$legacyCount = function (int $tid, string $activity): int { return count(p155_audit_rows($tid, $activity)); };

// ── 1. UI route ──────────────────────────────────────────────────────────
echo "--- UI (api/incident-update.php) ---\n";
$ui = p155_make_ticket(2, null, 'GH147 ui');
$booked = date('Y-m-d H:i:s', time() + 7200);
$uiSteps = [
    ['to' => 1, 'extra' => [],                      'legacy' => 'close',  'transition' => 'closed'],
    ['to' => 2, 'extra' => [],                      'legacy' => 'reopen', 'transition' => 'reopened'],
    ['to' => 3, 'extra' => ['booked_date' => $booked], 'legacy' => 'update', 'transition' => 'scheduled'],
    ['to' => 2, 'extra' => [],                      'legacy' => 'update', 'transition' => 'activated'],
];
$legacyBefore = ['close' => 0, 'reopen' => 0, 'update' => 0];
foreach ($uiSteps as $i => $step) {
    $scBefore = count($sc($ui));
    $legBefore = $legacyCount($ui, $step['legacy']);
    $r = p155_probe('session', 'api/incident-update.php', $adminId,
        ['action' => 'update_status', 'ticket_id' => $ui, 'new_status' => $step['to']] + $step['extra']);
    t("UI step " . ($i + 1) . " (-> {$step['to']}) succeeds", $r['http'] === 200 && !empty($r['json']['success']), $r['raw']);
    $rows = $sc($ui);
    t("UI step " . ($i + 1) . ": exactly one status_change row", count($rows) === $scBefore + 1, (string) (count($rows) - $scBefore));
    $last = $rows ? $rows[count($rows) - 1]['d'] : [];
    t("UI step " . ($i + 1) . ": source=ui, transition={$step['transition']}",
        ($last['source'] ?? '') === 'ui' && ($last['transition'] ?? '') === $step['transition']);
    t("UI step " . ($i + 1) . ": the legacy '{$step['legacy']}' row is still written exactly once (existing subscribers unaffected)",
        $legacyCount($ui, $step['legacy']) === $legBefore + 1);
}

// ── 2. External API route ────────────────────────────────────────────────
echo "\n--- External API (api/external/v1/incidents.php, real bearer auth) ---\n";
$tok = ext_api_mint_token($adminId, ['incidents:write', 'incidents:read'], $adminId, ['name' => 'gh147-test-token']);
test_fixture_guard_track('external_api_tokens', (int) $tok['id']);
$bearer = $tok['raw_token'];
$patch = function (int $tid, array $body) use ($bearer) {
    return p155_probe('bearer', 'api/external/v1/incidents.php', $bearer, $body, 'PATCH', 'id=' . $tid);
};

$ext = p155_make_ticket(2, null, 'GH147 ext');
$r = $patch($ext, ['status' => 1]);
t('PATCH {status:1} closes the incident (HTTP 200)', $r['http'] === 200 && (int) $col($ext, 'status') === 1, $r['raw']);
t("the response lists 'status' in fields_changed and says status_changed:true",
    in_array('status', $r['json']['data']['fields_changed'] ?? [], true) && ($r['json']['data']['status_changed'] ?? null) === true, $r['raw']);
$rows = $sc($ext);
t('exactly one status_change row', count($rows) === 1);
$d = $rows ? $rows[0]['d'] : [];
t("source=external_api, actor_type=api_token, token_id is this token",
    ($d['source'] ?? '') === 'external_api' && ($d['actor_type'] ?? '') === 'api_token' && ($d['token_id'] ?? null) === (int) $tok['id'], json_encode($d));
t("the legacy close row is written once", $legacyCount($ext, 'close') === 1);

db_query("UPDATE `{$prefix}ticket` SET problemend = '2020-01-02 03:04:05' WHERE id = ?", [$ext]);
$r = $patch($ext, ['status' => 1]);
t('RETRY of the same close answers 200 with status_changed:false', $r['http'] === 200 && ($r['json']['data']['status_changed'] ?? null) === false, $r['raw']);
t("'status' is NOT in fields_changed for the retry", !in_array('status', $r['json']['data']['fields_changed'] ?? [], true));
t('problemend is NOT re-stamped by the retry', (string) $col($ext, 'problemend') === '2020-01-02 03:04:05');
t('no second status_change row, no second incident.closed (legacy close) row', count($sc($ext)) === 1 && $legacyCount($ext, 'close') === 1);

$r = $patch($ext, ['status' => 2]);
t('PATCH {status:2} reopens', $r['http'] === 200 && (int) $col($ext, 'status') === 2);
t("and writes one 'reopened' status_change + the legacy reopen row",
    count($sc($ext)) === 2 && ($sc($ext)[1]['d']['transition'] ?? '') === 'reopened' && $legacyCount($ext, 'reopen') === 1);

$r = $patch($ext, ['status' => 3, 'booked_date' => $booked]);
t('PATCH {status:3, booked_date} schedules', $r['http'] === 200 && (int) $col($ext, 'status') === 3, $r['raw']);
t("string numerals are accepted ({status:'2'})", $patch($ext, ['status' => '2'])['http'] === 200 && (int) $col($ext, 'status') === 2);

// ── 2b. F7: refusals are decided BEFORE any write ────────────────────────
echo "\n--- F7: nothing is saved when the request is refused ---\n";
$f7 = p155_make_ticket(2, null, 'GH147 F7');
$sevOf = function () use ($f7, $col) { return (int) $col($f7, 'severity'); };
t('setup: severity starts at 0', $sevOf() === 0);

// A caller who may edit but NOT close: a custom role holding action.edit_incident only.
db_query("INSERT INTO `{$prefix}roles` (`name`, `description`, `is_default`, `is_super`) VALUES ('gh147-edit-only', 'test', 0, 0)");
$roleId = (int) db_insert_id();
test_fixture_guard_track('roles', $roleId);
test_fixture_guard_track_where('role_permissions', 'role_id = ?', [$roleId]);
foreach (['action.edit_incident'] as $code) {
    $pid = (int) db_fetch_value("SELECT id FROM `{$prefix}permissions` WHERE code = ?", [$code]);
    db_query("INSERT INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)", [$roleId, $pid]);
}
db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`, `name_f`, `name_l`) VALUES ('gh147-edit-only-user', ?, 'Edit', 'Only')",
    [password_hash('unused-' . bin2hex(random_bytes(8)), PASSWORD_BCRYPT)]);
$editUser = (int) db_insert_id();
test_fixture_guard_track('user', $editUser);
test_fixture_guard_track_where('user_roles', 'user_id = ?', [$editUser]);
db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `scope_kind`, `granted_by`) VALUES (?, ?, 'global', ?)", [$editUser, $roleId, $adminId]);
$tok2 = ext_api_mint_token($editUser, ['incidents:write'], $adminId, ['name' => 'gh147-edit-only-token']);
test_fixture_guard_track('external_api_tokens', (int) $tok2['id']);
$patchEdit = function (array $body) use ($tok2, $f7) {
    return p155_probe('bearer', 'api/external/v1/incidents.php', $tok2['raw_token'], $body, 'PATCH', 'id=' . $f7);
};

$r = $patchEdit(['severity' => 1]);
t('control: the edit-only user CAN change severity on its own', $r['http'] === 200 && $sevOf() === 1, $r['raw']);
db_query("UPDATE `{$prefix}ticket` SET severity = 0 WHERE id = ?", [$f7]);
$updRowsBefore = $legacyCount($f7, 'update');

$r = $patchEdit(['severity' => 2, 'status' => 1]);
t('severity + a status the caller may not set -> 403 forbidden_rbac naming action.close_incident',
    $r['http'] === 403 && ($r['json']['error'] ?? '') === 'forbidden_rbac' && ($r['json']['required'] ?? ($r['json']['details']['required'] ?? '')) === 'action.close_incident', $r['raw']);
t('F7: the severity was NOT saved (the old order saved it, THEN answered 403)', $sevOf() === 0);
t('F7: no audit "update" row was written for the refused request', $legacyCount($f7, 'update') === $updRowsBefore);
t('and the incident was not closed', (int) $col($f7, 'status') === 2);

// Same up-front discipline for the value checks, using the full-permission token.
$patchAdmin = function (array $body) use ($bearer, $f7) {
    return p155_probe('bearer', 'api/external/v1/incidents.php', $bearer, $body, 'PATCH', 'id=' . $f7);
};
$r = $patchAdmin(['severity' => 2, 'status' => 'closed']);
t("a non-numeric status is 422, and severity is NOT saved", $r['http'] === 422 && $sevOf() === 0, $r['raw']);
$r = $patchAdmin(['severity' => 2, 'status' => 9]);
t('status 9 is 422, severity NOT saved', $r['http'] === 422 && $sevOf() === 0, $r['raw']);
$r = $patchAdmin(['severity' => 2, 'status' => 3]);
t('scheduling without booked_date is 422, severity NOT saved', $r['http'] === 422 && $sevOf() === 0, $r['raw']);
$r = $patchAdmin(['severity' => 2, 'disposition_id' => 2147480000]);
t('an unknown disposition is 422, severity NOT saved', $r['http'] === 422 && $sevOf() === 0, $r['raw']);

p155_set_setting('disposition_required_on_close', '1');
$r = $patchAdmin(['severity' => 2, 'status' => 1]);
t('with disposition_required_on_close ON, a close with no disposition is 422 and severity is NOT saved',
    $r['http'] === 422 && $sevOf() === 0 && stripos($r['raw'], 'disposition') !== false, $r['raw']);
db_query("INSERT INTO `{$prefix}ticket_disposition` (`status_val`, `description`, `code`, `active`) VALUES ('GH147 F7 Disposition', 'x', 'GH147F7', 1)");
$dispId = (int) db_insert_id();
test_fixture_guard_track('ticket_disposition', $dispId);
$r = $patchAdmin(['severity' => 2, 'status' => 1, 'disposition_id' => $dispId]);
t('control: with a disposition the same request succeeds (severity saved AND closed AND disposition set)',
    $r['http'] === 200 && $sevOf() === 2 && (int) $col($f7, 'status') === 1 && (int) $col($f7, 'disposition_id') === $dispId, $r['raw']);
$r = $patchAdmin(['status' => 1]);
t('a repeat close of the already-closed incident is 200/no-op even with the gate ON (nothing is being closed)',
    $r['http'] === 200 && ($r['json']['data']['status_changed'] ?? null) === false, $r['raw']);
p155_set_setting('disposition_required_on_close', '0');

// Primary unit: refused up front when the feature is off.
p155_set_setting('primary_unit_mode', 'off');
$f7b = p155_make_ticket(2, null, 'GH147 F7 primary');
$r = p155_probe('bearer', 'api/external/v1/incidents.php', $bearer, ['severity' => 3, 'primary_responder_id' => 1], 'PATCH', 'id=' . $f7b);
t('primary_responder_id with the feature OFF is 409 and severity is NOT saved',
    $r['http'] === 409 && (int) $col($f7b, 'severity') === 0, $r['raw']);

// ── 3. Auto-close sweep ──────────────────────────────────────────────────
echo "\n--- auto-close sweep ---\n";
$ac = p155_make_ticket(2, null, 'GH147 auto close');
db_query("UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id = ?", [$ac]);
$sweep = auto_close_sweep(50);
t('the sweep closed it', (int) $col($ac, 'status') === 1 && (int) ($sweep['closed'] ?? 0) >= 1, json_encode($sweep));
$rows = $sc($ac);
t('exactly one status_change row', count($rows) === 1);
$d = $rows ? $rows[0]['d'] : [];
t("source=auto_close, actor_type=system, and the row names the SYSTEM (a dispatcher session is mounted in this process)",
    ($d['source'] ?? '') === 'auto_close' && ($d['actor_type'] ?? '') === 'system' && $rows[0]['user_name'] === 'System');
$legacy = p155_audit_rows($ac, 'close');
t("the sweep's own legacy close row now names the system too", count($legacy) === 1 && $legacy[0]['user_name'] === 'System');
$sweep2 = auto_close_sweep(50);
t('a second sweep announces nothing more', count($sc($ac)) === 1);

// ── 4. Scheduled activation ──────────────────────────────────────────────
echo "\n--- scheduled activation ---\n";
$sa = p155_make_ticket(3, 'DATE_SUB(NOW(), INTERVAL 3 MINUTE)', 'GH147 activation');
$act = incident_activate_due_scheduled(50);
t('activation ran', (int) $act['activated'] >= 1 && (int) $col($sa, 'status') === 2);
$rows = $sc($sa);
t("exactly one status_change row, source=scheduled_activation, transition=activated",
    count($rows) === 1 && ($rows[0]['d']['source'] ?? '') === 'scheduled_activation' && ($rows[0]['d']['transition'] ?? '') === 'activated');

// ── 5. Major Incident close (the cascade) ────────────────────────────────
echo "\n--- Major Incident close ---\n";
$unit = p155_make_unit('GH147-MAJ');
$mt = p155_make_ticket(2, null, 'GH147 major linked');
$a = assign_create_internal($mt, $unit, '', $adminId);
t('fixture: a unit is assigned to the linked incident', empty($a['errors']));
db_query("INSERT INTO `{$prefix}newui_major_incidents` (`name`, `description`, `status`) VALUES ('GH147 Major', 'test', 'open')");
$majorId = (int) db_insert_id();
test_fixture_guard_track('newui_major_incidents', $majorId);
test_fixture_guard_track_where('newui_major_incident_links', 'major_id = ?', [$majorId]);
test_fixture_guard_track_where('newui_audit_log', "target_type = 'major_incident' AND target_id = ?", [(string) $majorId]);
db_query("INSERT INTO `{$prefix}newui_major_incident_links` (`major_id`, `ticket_id`, `linked_by`) VALUES (?, ?, ?)", [$majorId, $mt, $adminId]);
// A linked incident that is already closed must be left strictly alone.
$mc = p155_make_ticket(1, null, 'GH147 major already closed');
db_query("UPDATE `{$prefix}ticket` SET problemend = '2020-01-02 03:04:05' WHERE id = ?", [$mc]);
db_query("INSERT INTO `{$prefix}newui_major_incident_links` (`major_id`, `ticket_id`, `linked_by`) VALUES (?, ?, ?)", [$majorId, $mc, $adminId]);

$r = p155_probe('session', 'api/major-incidents.php', $adminId, ['action' => 'close', 'major_id' => $majorId]);
t('closing the Major Incident succeeds and reports 1 closed ticket',
    $r['http'] === 200 && ($r['json']['closed_tickets'] ?? null) === 1 && ($r['json']['left_open_tickets'] ?? null) === 0, $r['raw']);
t('the linked Open incident is Closed', (int) $col($mt, 'status') === 1);
$rows = $sc($mt);
t("exactly one status_change row for it, source=major_incident_close", count($rows) === 1 && ($rows[0]['d']['source'] ?? '') === 'major_incident_close', json_encode($rows ? $rows[0]['d'] : null));
t("the close payload says it cleared the unit", ($rows[0]['d']['cleared_assigns'] ?? 0) === 1);
t('the standard legacy close row (-> incident.closed) is written exactly once', $legacyCount($mt, 'close') === 1);
t("DECISION 5: the unit was RELEASED (the old raw UPDATE left it assigned to a closed incident)",
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ? AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')", [$mt]) === 0);
t('and the unit is back to Available (not stuck Dispatched on a closed incident)',
    (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$unit]) === (int) _assign_available_status_id());
t('the already-closed linked incident was not touched', (string) $col($mc, 'problemend') === '2020-01-02 03:04:05' && count($sc($mc)) === 0);
t("the Major Incident itself is closed", db_fetch_value("SELECT status FROM `{$prefix}newui_major_incidents` WHERE id = ?", [$majorId]) === 'closed');

p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
