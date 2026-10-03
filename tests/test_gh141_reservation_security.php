<?php
/**
 * GH#141 -- the security boundaries around reservations.
 *
 * A reservation names a unit and an incident, can be released by one request and
 * turned into a real dispatch by another, so every entry point must hold the same
 * lines the rest of the dispatch endpoints hold:
 *
 *   - ORGANIZATION SCOPE. An Org Admin of organization A can neither release nor
 *     dispatch nor see a reservation on organization B's incident, and cannot
 *     reach one by pairing its id with an incident of their own (the Phase 142
 *     revoke-IDOR lesson: the incident is derived from the reservation row, never
 *     trusted from the client).
 *   - CSRF on every state change; RBAC (action.assign_unit) on every action;
 *     an unauthenticated caller changes nothing.
 *   - The External API's assignments endpoint had NO organization gate at all --
 *     a token bound to organization A's user could assign units to B's incident,
 *     and with reservations could now reserve them. POST, PATCH and DELETE are
 *     gated now, with the same 403/404 shape incidents.php uses.
 *   - A viewer who reached the incident through a cross-org VIEW-tier share sees
 *     the unit and state of a reservation, never who reserved it, the role, or the
 *     free-text note.
 *
 * Fixtures are two throwaway organizations, an Org Admin scoped to A, a
 * Read-Only user, and a token for the scoped user (with the member row the
 * External API uses to learn a token user's organization).
 *
 * @requires-db
 * Usage: php tests/test_gh141_reservation_security.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/assign-reservations.php';
require_once __DIR__ . '/../inc/external-auth.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- reservation security ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
p155_remember_settings(['scheduled_assign_mode', 'scheduled_assign_lead_minutes']);
p155_set_setting('scheduled_assign_mode', 'reserve');
p155_set_setting('scheduled_assign_lead_minutes', '0');

// ── Fixtures ─────────────────────────────────────────────────────────────
$orgA = 900141001; $orgB = 900141002;
foreach ([$orgA => 'GH141 Org A', $orgB => 'GH141 Org B'] as $id => $name) {
    db_query("INSERT INTO `{$prefix}organizations` (`id`, `name`) VALUES (?, ?)", [$id, $name]);
    test_fixture_guard_track('organizations', $id);
}
$mkUser = function (string $name) use ($prefix) {
    db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`, `name_f`, `name_l`, `can_login`) VALUES (?, ?, 'GH141', 'Sec', 0)",
        [$name, password_hash('unused-' . bin2hex(random_bytes(8)), PASSWORD_BCRYPT)]);
    $id = (int) db_insert_id();
    test_fixture_guard_track('user', $id);
    test_fixture_guard_track_where('user_roles', 'user_id = ?', [$id]);
    return $id;
};
$scoped = $mkUser('gh141-orgadmin-a');
db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `org_id`, `scope_kind`, `scope_id`) VALUES (?, 2, ?, 'org', ?)", [$scoped, $orgA, $orgA]);
$readOnly = $mkUser('gh141-readonly');
db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `scope_kind`) VALUES (?, 5, 'global')", [$readOnly]);
// The member row the External API reads to learn a token user's organization.
db_query("INSERT INTO `{$prefix}member` (`user_id`, `first_name`, `last_name`) VALUES (?, 'GH141', 'Scoped')", [$scoped]);
$memberId = (int) db_insert_id();
test_fixture_guard_track('member', $memberId);
db_query("INSERT INTO `{$prefix}member_organizations` (`member_id`, `org_id`, `status`) VALUES (?, ?, 'active')", [$memberId, $orgA]);
test_fixture_guard_track_where('member_organizations', 'member_id = ?', [$memberId]);

$stAvail = p155_make_status('p155_sec_avail', 0);
$mkUnit = function (string $h) use ($prefix, $stAvail) {
    $rid = p155_make_unit($h);
    db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $rid]);
    return $rid;
};
$mkTicket = function (int $orgId, string $scope) use ($prefix) {
    $tid = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 5 HOUR)', $scope);
    db_query("UPDATE `{$prefix}ticket` SET org_id = ? WHERE id = ?", [$orgId, $tid]);
    return $tid;
};
$reserve = function (int $tid, int $rid, string $role = 'Medic') use ($adminId) {
    $r = p155_worker('assign', ["id=$tid", "unit=$rid", "user=$adminId", "role=$role", 'set:scheduled_assign_mode=reserve']);
    return (int) ($r['reservation_id'] ?? 0);
};
$state = function (int $resId) use ($prefix) { return db_fetch_value("SELECT state FROM `{$prefix}assign_reservations` WHERE id = ?", [$resId]); };

$tA = $mkTicket($orgA, 'GH141 org A incident');
$tB = $mkTicket($orgB, 'GH141 org B incident');
$uA1 = $mkUnit('SEC-A1'); $uA2 = $mkUnit('SEC-A2');
$uB1 = $mkUnit('SEC-B1'); $uB2 = $mkUnit('SEC-B2'); $uB3 = $mkUnit('SEC-B3');
$resA1 = $reserve($tA, $uA1);
$resA2 = $reserve($tA, $uA2);
$resB1 = $reserve($tB, $uB1, 'SecretRole');
$resB2 = $reserve($tB, $uB2);
$resB3 = $reserve($tB, $uB3);
t('setup: reservations exist on both organizations\' incidents', $resA1 > 0 && $resB1 > 0 && $resB2 > 0);

$as = function (array $body, int $user = 0, int $org = 0) use ($scoped, $orgA) {
    return p155_probe('session', 'api/incident-assign.php', $user ?: $scoped, $body, 'POST', '', $org ?: $orgA);
};

// ── 1. Organization scope / IDOR ─────────────────────────────────────────
echo "--- organization scope ---\n";
$r = $as(['action' => 'release_reservation', 'ticket_id' => $tA, 'reservation_id' => $resA1]);
t('control: the Org A admin CAN release a reservation on Org A\'s own incident', $r['http'] === 200 && $state($resA1) === 'cancelled', $r['raw']);
$r = $as(['action' => 'release_reservation', 'ticket_id' => $tA, 'reservation_id' => $resB1]);
t("the Org A admin CANNOT release Org B's reservation -- even naming Org A's OWN incident (the server uses the reservation's incident, not the client's)",
    $r['http'] === 404 && $state($resB1) === 'pending', $r['raw']);
t('...and the answer is a plain 404, not a 403 that would confirm the reservation exists', $r['http'] === 404);
$r = $as(['action' => 'dispatch_reservation_now', 'ticket_id' => $tA, 'reservation_id' => $resB2]);
t("the Org A admin CANNOT dispatch Org B's reservation (no assigns row, reservation untouched)",
    $r['http'] === 404 && $state($resB2) === 'pending' && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tB]) === 0, $r['raw']);
$r = $as(['action' => 'dispatch_reservation_now', 'ticket_id' => $tA, 'reservation_id' => $resA2]);
t("control: the Org A admin CAN dispatch Org A's own reservation", $r['http'] === 200 && $state($resA2) === 'promoted', $r['raw']);
$r = $as(['action' => 'assign', 'ticket_id' => $tB, 'responder_id' => $uA1]);
t("the Org A admin cannot assign (or reserve) a unit on Org B's incident (404)", $r['http'] === 404 && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assign_reservations` WHERE ticket_id = ? AND responder_id = ?", [$tB, $uA1]) === 0, $r['raw']);
$d = p155_probe('session', 'api/incident-detail.php', $scoped, [], 'GET', 'id=' . $tB, $orgA);
t("the Org A admin cannot read Org B's incident -- so its reservations are not exposed through the detail API either", $d['http'] === 404, $d['raw']);

// ── 2. CSRF / RBAC / unauthenticated ─────────────────────────────────────
echo "\n--- CSRF, RBAC, unauthenticated ---\n";
$r = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'release_reservation', 'ticket_id' => $tB, 'reservation_id' => $resB2, 'csrf_token' => 'not-the-token']);
t('a wrong CSRF token is refused (403) and changes nothing', $r['http'] === 403 && $state($resB2) === 'pending', $r['raw']);
$r = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'dispatch_reservation_now', 'ticket_id' => $tB, 'reservation_id' => $resB2, 'csrf_token' => '']);
t('an empty CSRF token is refused on dispatch too', $r['http'] === 403 && $state($resB2) === 'pending', $r['raw']);
$r = p155_probe('session', 'api/incident-assign.php', $readOnly, ['action' => 'release_reservation', 'ticket_id' => $tB, 'reservation_id' => $resB2]);
t('a user without action.assign_unit (Read-Only) cannot release (403)', $r['http'] === 403 && $state($resB2) === 'pending', $r['raw']);
$r = p155_probe('session', 'api/incident-assign.php', $readOnly, ['action' => 'dispatch_reservation_now', 'ticket_id' => $tB, 'reservation_id' => $resB2]);
t('...nor dispatch (403)', $r['http'] === 403 && $state($resB2) === 'pending', $r['raw']);
$r = p155_probe('session', 'api/incident-assign.php', 0, ['action' => 'release_reservation', 'ticket_id' => $tB, 'reservation_id' => $resB2]);
t('an unauthenticated session changes nothing', $r['http'] !== 200 && $state($resB2) === 'pending', $r['raw']);
$r = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'release_reservation', 'ticket_id' => $tB, 'reservation_id' => 'abc; DROP TABLE ticket']);
t('a hostile reservation_id is treated as 0 -> 404 (bound parameters; nothing executes)', $r['http'] === 404 && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket`") > 0, $r['raw']);
$r = p155_probe('session', 'api/incident-assign.php', $adminId, [], 'GET');
t('GET is refused (the endpoint is POST-only)', $r['http'] === 405, $r['raw']);

// ── 3. External API organization gate ────────────────────────────────────
echo "\n--- External API: the organization gate its assignments endpoint never had ---\n";
$tokScoped = ext_api_mint_token($scoped, ['incidents:write'], $adminId, ['name' => 'gh141-scoped-token']);
test_fixture_guard_track('external_api_tokens', (int) $tokScoped['id']);
$tokAdmin = ext_api_mint_token($adminId, ['incidents:write'], $adminId, ['name' => 'gh141-admin-token']);
test_fixture_guard_track('external_api_tokens', (int) $tokAdmin['id']);
$ext = function (string $token, string $method, array $body, string $qs = '') {
    return p155_probe('bearer', 'api/external/v1/assignments.php', $token, $body, $method, $qs);
};
$uX1 = $mkUnit('SEC-X1'); $uX2 = $mkUnit('SEC-X2'); $uX3 = $mkUnit('SEC-X3');
$r = $ext($tokScoped['raw_token'], 'POST', ['ticket_id' => $tA, 'responder_id' => $uX1]);
t("control: a token for the Org A user CAN assign (here: reserve) a unit on Org A's incident (201)", $r['http'] === 201 && !empty($r['json']['data']['reserved']), $r['raw']);
$r = $ext($tokScoped['raw_token'], 'POST', ['ticket_id' => $tB, 'responder_id' => $uX2]);
t("a token for the Org A user CANNOT assign a unit on Org B's incident: 404 not_found", $r['http'] === 404 && ($r['json']['error'] ?? '') === 'not_found', $r['raw']);
t('...and no reservation or assignment was created on Org B\'s incident',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assign_reservations` WHERE ticket_id = ? AND responder_id = ?", [$tB, $uX2]) === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ? AND responder_id = ?", [$tB, $uX2]) === 0);
$r = $ext($tokScoped['raw_token'], 'POST', ['ticket_id' => $tB, 'responder_id' => $uX2, 'dispatch_now' => true]);
t('dispatch_now does not get around the gate (404)', $r['http'] === 404, $r['raw']);
$r = $ext($tokAdmin['raw_token'], 'POST', ['ticket_id' => $tB, 'responder_id' => $uX3, 'dispatch_now' => true]);
t('control: a Super Admin token can (the gate is about the caller\'s organization, not a blanket refusal)', $r['http'] === 201 && !empty($r['json']['data']['id']), $r['raw']);
$assignB = (int) ($r['json']['data']['id'] ?? 0);
$r = $ext($tokScoped['raw_token'], 'PATCH', ['assign_id' => $assignB, 'new_status' => 'responding']);
t("a token for the Org A user CANNOT change the status of an assignment on Org B's incident (404)",
    $r['http'] === 404 && db_fetch_value("SELECT responding FROM `{$prefix}assigns` WHERE id = ?", [$assignB]) === null, $r['raw']);
$r = $ext($tokScoped['raw_token'], 'DELETE', [], 'assign_id=' . $assignB);
t("...nor unassign it (404; the assignment is still open)",
    $r['http'] === 404 && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE id = ? AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')", [$assignB]) === 1, $r['raw']);
$r = $ext($tokAdmin['raw_token'], 'PATCH', ['assign_id' => $assignB, 'new_status' => 'responding']);
t('control: the Super Admin token CAN change it', $r['http'] === 200, $r['raw']);
$r = $ext($tokScoped['raw_token'], 'POST', ['ticket_id' => 2147480000, 'responder_id' => $uX1]);
t('an incident that does not exist still gets the writer\'s own answer (422 Ticket not found) -- existing behaviour unchanged', $r['http'] === 422, $r['raw']);

// ── 4. Share-derived views redact the reservation's free text ────────────
echo "\n--- a cross-org VIEW-tier share sees the unit and state, not who/why ---\n";
$tS = $mkTicket($orgB, 'GH141 shared incident');
$uS = $mkUnit('SEC-S');
$resS = $reserve($tS, $uS, 'ConfidentialRole');
db_query("UPDATE `{$prefix}assign_reservations` SET outcome_note = 'internal note: do not leak' WHERE id = ?", [$resS]);
$_SESSION['user_id'] = $adminId; $_SESSION['user'] = 'gh141-test-admin';
$share = org_sharing_create_manual_share($tS, $orgA, 'view', 'gh141 redaction test', $adminId, 'gh141-test-admin');
test_fixture_guard_track_where('incident_shares', 'ticket_id = ?', [$tS]);
t('setup: Org B shared the incident with Org A at VIEW tier', !empty($share['success']), json_encode($share));
$dv = p155_probe('session', 'api/incident-detail.php', $scoped, [], 'GET', 'id=' . $tS, $orgA);
$rv = $dv['json']['reservations'][0] ?? null;
t('the Org A viewer (share-derived) can see the incident and its reservation', $dv['http'] === 200 && $rv !== null, $dv['raw']);
t('...with the unit and the state', $rv && !empty($rv['responder_handle']) && $rv['state'] === 'pending');
t('...but NOT who reserved it, the role, or the free-text note', $rv && $rv['reserved_by_name'] === '' && $rv['role'] === '' && $rv['outcome_note'] === '', json_encode($rv));
t('and the secret strings are nowhere in the response body', strpos($dv['raw'], 'ConfidentialRole') === false && strpos($dv['raw'], 'do not leak') === false);
$dOwn = p155_probe('session', 'api/incident-detail.php', $adminId, [], 'GET', 'id=' . $tS);
t('control: the OWNING side (Super Admin) sees the role and note', strpos($dOwn['raw'], 'ConfidentialRole') !== false && strpos($dOwn['raw'], 'do not leak') !== false);
$r = $as(['action' => 'release_reservation', 'ticket_id' => $tS, 'reservation_id' => $resS]);
t('a VIEW-tier share cannot release the owning org\'s reservation (403: it can see the incident but its tier does not permit this action)', $r['http'] === 403 && $state($resS) === 'pending', $r['raw']);

p155_set_setting('scheduled_assign_mode', 'immediate');
p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
