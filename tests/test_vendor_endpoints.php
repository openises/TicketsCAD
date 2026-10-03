<?php
/**
 * GH#148 (Phase 155) -- the REAL endpoints (api/vendor-dispatch.php, api/vendor-admin.php), through
 * tests/_vendor_endpoint_probe.php: a real attached session, real RBAC, real org scoping, real CSRF check.
 *
 * What is proven here that the library tests cannot: that an Operator is refused, that a bad CSRF token is refused, that a
 * caller in organization A can neither see nor use organization B's lists or incidents, that "can see but cannot change"
 * (a view-tier share) answers 403 while "cannot see" answers 404, that the stale-queue and override-not-allowed answers
 * carry the bodies the dialog reads, that the CSV is spreadsheet-safe and audited, and that an out-of-enum setting is refused.
 *
 * @requires-db
 * Usage: php tests/test_vendor_endpoints.php
 */
require_once __DIR__ . '/_vendor_fixtures.php';
require_once __DIR__ . '/../inc/org-sharing.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

$prefix = vf_prefix();
$admin = test_admin_user_id();
$A = vf_actor($admin, 'vf-admin');
vf_session_as($admin, null, 'vf-admin');
register_shutdown_function('vf_cleanup');

if (!vendor_schema_ready()) {
    echo "SKIP: the vendor tables are not installed\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
if (!function_exists('proc_open')) {
    echo "SKIP: proc_open() is disabled on this PHP install\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$BASE = ['vendor_dispatch_enabled' => '1', 'vendor_rotation_mode' => 'round_robin', 'vendor_advance_rule' => 'any_offer',
         'vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1', 'phone_click_to_call' => 'off'];
vf_set_settings($BASE);
$TOW = vf_service_type_id('tow');

/** Call an endpoint in its own process. @return array [decoded|null, http, raw] */
function ep(string $api, string $method, int $user, int $org, string $csrf, $payload): array
{
    $arg = is_array($payload) ? ($method === 'GET' ? http_build_query($payload) : json_encode($payload)) : (string) $payload;
    $argv = [PHP_BINARY ?: 'php', __DIR__ . '/_vendor_endpoint_probe.php', $api, $method, (string) $user, (string) $org, $csrf, $arg];
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($argv, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return [null, 0, ''];
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $http = 0; $body = (string) $out;
    if (preg_match('/\n__HTTP__(\d+)\s*$/', $body, $m)) { $http = (int) $m[1]; $body = substr($body, 0, -strlen($m[0])); }
    $dec = json_decode(trim($body), true);
    if ($dec === null && trim($body) !== '' && $http !== 0 && $err !== '') fwrite(STDERR, "probe stderr: " . substr($err, 0, 300) . "\n");
    return [is_array($dec) ? $dec : null, $http, $body];
}
function disp(string $m, int $u, int $o, string $c, $p) { return ep('api/vendor-dispatch.php', $m, $u, $o, $c, $p); }
function adm(string $m, int $u, int $o, string $c, $p) { return ep('api/vendor-admin.php', $m, $u, $o, $c, $p); }

echo "=== GH#148 -- endpoints ===\n\n";

// ── fixtures: two agencies, their people, incidents, lists and companies ──
$orgA = vf_org('VTE Agency A');
$orgB = vf_org('VTE Agency B');
$dispA = vf_user('vte-disp-a', 3, $orgA);
$dispB = vf_user('vte-disp-b', 3, $orgB);
$mgrA  = vf_user('vte-mgr-a', 2, $orgA);
$oper  = vf_user('vte-operator', 4);
$tA = vf_ticket($orgA);
$tB = vf_ticket($orgB);
$tShareView = vf_ticket($orgA);
$tShareAssist = vf_ticket($orgA);

$listA = vf_list('VTE list A', 'tow', ['org_id' => $orgA]);
$listB = vf_list('VTE list B', 'tow', ['org_id' => $orgB]);
$pA1 = vf_provider('VTE A1 Towing', ['phone' => '555-0301', 'org_id' => $orgA], $A);
$pA2 = vf_provider('VTE A2 Towing', ['phone' => '555-0302', 'org_id' => $orgA], $A);
$pB1 = vf_provider('VTE B1 Towing', ['phone' => '555-0311', 'org_id' => $orgB], $A);
$pEvil = vf_provider('=HYPERLINK("http://evil.example","click")', ['phone' => '555-0399', 'org_id' => $orgA], $A);
vf_members($listA, [$pA1, $pA2], $A);
vf_members($listB, [$pB1], $A);
vendor_list_set_default($listA, true, $A);

// shares: org A owns the incident, org B is shared in at the two tiers
$s1 = org_sharing_create_manual_share($tShareView, $orgB, 'view', 'vte view-tier', $dispA, 'vte-disp-a');
$s2 = org_sharing_create_manual_share($tShareAssist, $orgB, 'assist', 'vte assist-tier', $dispA, 'vte-disp-a');
t('(setup) both shares were created through the real sharing writer', !empty($s1['success']) && !empty($s2['success']));

// ══════════════════════════════════════════════════════════════
// 1. dispatch endpoint: gating
// ══════════════════════════════════════════════════════════════
echo "--- gating ---\n";
[$j, $h] = disp('GET', $oper, 0, 'good', ['action' => 'config', 'ticket_id' => $tA]);
t('an Operator (no action.dispatch_vendor) is refused: 403', $h === 403 && isset($j['error']));
[$j, $h] = disp('POST', $dispA, $orgA, 'bad', ['action' => 'offer', 'ticket_id' => $tA, 'service_type_id' => $TOW]);
t('a bad CSRF token is refused: 403', $h === 403 && ($j['error'] ?? '') === 'Invalid CSRF token');
[$j, $h] = disp('POST', $dispA, $orgA, 'none', ['action' => 'offer', 'ticket_id' => $tA, 'service_type_id' => $TOW]);
t('a missing CSRF token is refused: 403', $h === 403);
[$j, $h] = disp('GET', $dispA, $orgA, 'good', ['action' => 'config', 'ticket_id' => $tB]);
t('an incident the caller cannot see (another agency) answers 404, not 403', $h === 404);
[$j, $h] = disp('GET', $dispB, $orgB, 'good', ['action' => 'config', 'ticket_id' => $tShareView]);
t('an incident the caller can SEE but not change (a view-tier share) answers 403', $h === 403);
[$j, $h] = disp('GET', $dispB, $orgB, 'good', ['action' => 'config', 'ticket_id' => $tShareAssist]);
t('an assist-tier shared-in caller may dispatch (200)', $h === 200 && ($j['enabled'] ?? false) === true);
t('...and uses THEIR OWN agency\'s lists, never the owner\'s: only list B is offered', $h === 200 && array_column($j['lists'], 'id') === [$listB]);

// ══════════════════════════════════════════════════════════════
// 2. config shape
// ══════════════════════════════════════════════════════════════
echo "\n--- config ---\n";
[$j, $h] = disp('GET', $dispA, $orgA, 'good', ['action' => 'config', 'ticket_id' => $tA]);
t('config answers 200 with enabled:true', $h === 200 && $j['enabled'] === true);
t('config carries the incident (reference, address) the dialog needs', $j['ticket']['ref'] === incnum_display($tA)
    && $j['ticket']['street'] === '100 Test Road' && $j['ticket']['city'] === 'Testville' && $j['ticket']['closed'] === false);
t('config lists the active service types (Tow needs a destination)', (function ($j) {
    foreach ($j['service_types'] as $st) { if ($st['id'] === vf_service_type_id('tow')) return $st['label'] === 'Tow' && $st['needs_destination'] === 1; } return false; })($j)
    && count($j['service_types']) >= 4);
t('config offers only org A\'s list (org B\'s list is invisible), flagged default', array_column($j['lists'], 'id') === [$listA] && $j['lists'][0]['is_default'] === 1);
t('config offers only org A\'s companies under "other"', !in_array($pB1, array_column($j['other_providers'], 'id'), true) && in_array($pA1, array_column($j['other_providers'], 'id'), true));
t('config carries the override settings and the dial mode', $j['settings']['allow_override'] === true && $j['settings']['override_requires_reason'] === true && $j['dial_mode'] === 'off');
t('config starts with no dispatches', $j['dispatches'] === []);
t('config carries no management flag and no internal ids the dialog never reads', !array_key_exists('can_manage', $j) && !array_key_exists('id', $j['ticket']) && !array_key_exists('scope', $j['ticket']));

[$j, $h] = disp('GET', $dispA, $orgA, 'good', ['action' => 'queue', 'ticket_id' => $tA, 'list_id' => $listB]);
t('org B\'s list is invisible to an org A caller: the queue answers 404', $h === 404);
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'offer', 'ticket_id' => $tA, 'service_type_id' => $TOW, 'list_id' => $listB, 'provider_id' => $pB1]);
t('...and an offer naming it is refused (404)', $h === 404 && ($j['success'] ?? false) !== true);
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'offer', 'ticket_id' => $tA, 'service_type_id' => $TOW, 'list_id' => $listB, 'provider_name' => 'Typed Wrecker', 'provider_phone' => '555-0990']);
t('...and a typed-company offer naming the other agency list is refused too (the LIST is checked on its own, not only the company on it)', $h === 404 && ($j['success'] ?? false) !== true
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` = ?", [$tA]) === 0);
[$j, $h] = disp('GET', $dispA, $orgA, 'good', ['action' => 'queue', 'ticket_id' => $tA, 'list_id' => $listA]);
t('the queue for org A\'s list: A1 is next', $h === 200 && $j['queue']['head_provider_id'] === $pA1 && $j['queue']['candidates'][0]['is_head'] === true);

// ══════════════════════════════════════════════════════════════
// 3. the operational flow over HTTP
// ══════════════════════════════════════════════════════════════
echo "\n--- offer, stale queue, override, outcome, status, void ---\n";
$offerBody = ['action' => 'offer', 'ticket_id' => $tA, 'service_type_id' => $TOW, 'list_id' => $listA, 'provider_id' => $pA1, 'client_head_provider_id' => $pA1,
              'vehicle_desc' => 'Red Ford F150', 'plate' => 'xyz789', 'dest_text' => '1 Impound Way'];
[$j, $h] = disp('POST', $dispA, $orgA, 'good', $offerBody);
t('POST offer to the head: 200, success, a reference and the method', $h === 200 && $j['success'] === true
    && $j['selection_method'] === 'rotation' && $j['dispatch']['ref'] === incnum_display($tA) . '-T1');
t('the answer carries the refreshed queue (next up moved to A2)', $j['queue']['head_provider_id'] === $pA2);
$did = $j['dispatch']['id'];
$firstEvent = $j['dispatch']['pending'][0]['event_id'];
t('the dispatch view carries the pending call with the number that was dialled', count($j['dispatch']['pending']) === 1 && $j['dispatch']['pending'][0]['provider_phone'] === '555-0301');

[$j, $h] = disp('POST', $dispA, $orgA, 'good', $offerBody);
t('a second dispatcher whose screen still showed A1: 409 with code queue_changed', $h === 409 && $j['code'] === 'queue_changed');
t('...the body carries the fresh queue the dialog redraws from', $j['queue']['head_provider_id'] === $pA2 && is_array($j['queue']['candidates']));

disp('POST', $dispA, $orgA, 'good', ['action' => 'outcome', 'dispatch_id' => $did, 'offer_event_id' => $firstEvent, 'outcome' => 'declined']);
$ovBody = ['action' => 'offer', 'dispatch_id' => $did, 'list_id' => $listA, 'provider_id' => $pA1, 'client_head_provider_id' => $pA2];
vf_set_settings(array_merge($BASE, ['vendor_allow_override' => '0']));
[$j, $h] = disp('POST', $dispA, $orgA, 'good', $ovBody + ['reason' => 'Closest truck']);
t('with overrides turned off, a non-head pick answers 409 code override_not_allowed', $h === 409 && $j['code'] === 'override_not_allowed');
vf_set_settings($BASE);
[$j, $h] = disp('POST', $dispA, $orgA, 'good', $ovBody);
t('an override with no reason answers 422 code reason_required (the dialog then asks for one)', $h === 422 && $j['code'] === 'reason_required');

$next = ['action' => 'offer', 'dispatch_id' => $did, 'list_id' => $listA, 'provider_id' => $pA2, 'client_head_provider_id' => $pA2];
[$j, $h] = disp('POST', $dispA, $orgA, 'good', $next);
$secondEvent = $j['dispatch']['pending'][0]['event_id'] ?? 0;
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'outcome', 'dispatch_id' => $did, 'offer_event_id' => $secondEvent, 'outcome' => 'accepted', 'eta_minutes' => 25]);
t('POST outcome accepted: the dispatch is assigned with the ETA', $h === 200 && $j['dispatch']['status'] === 'assigned' && $j['dispatch']['eta_minutes'] === 25 && $j['dispatch']['provider_name'] === 'VTE A2 Towing');
[$j, $h] = disp('POST', $dispB, $orgB, 'good', ['action' => 'outcome', 'dispatch_id' => $did, 'offer_event_id' => $secondEvent, 'outcome' => 'declined']);
t('another agency cannot touch this dispatch by id (IDOR): 404', $h === 404);
[$j, $h] = disp('POST', $dispB, $orgB, 'good', ['action' => 'status', 'dispatch_id' => $did, 'event' => 'completed']);
t('...nor change its status: 404', $h === 404);
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'status', 'dispatch_id' => $did, 'event' => 'completed']);
t('POST status completed: 200 and closed', $h === 200 && $j['dispatch']['status'] === 'completed');
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'status', 'dispatch_id' => $did, 'event' => 'on_scene']);
t('an illegal transition answers 409 illegal_transition', $h === 409 && $j['code'] === 'illegal_transition');
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'note', 'dispatch_id' => $did, 'text' => 'Driver called back']);
t('POST note: 200, and the note is on the timeline', $h === 200 && in_array('note', array_column($j['dispatch']['events'], 'event_type'), true));
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'update', 'dispatch_id' => $did, 'vehicle_desc' => 'Red Ford F150 (crew cab)', 'plate' => 'xyz789']);
t('POST update: the details change', $h === 200 && $j['dispatch']['vehicle_desc'] === 'Red Ford F150 (crew cab)');
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'void', 'event_id' => $secondEvent, 'reason' => '']);
t('POST void without a reason: 422 reason_required', $h === 422 && $j['code'] === 'reason_required');
[$j, $h] = disp('POST', $dispB, $orgB, 'good', ['action' => 'void', 'event_id' => $secondEvent, 'reason' => 'trying another agency']);
t('another agency cannot void by event id: 404', $h === 404);
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'void', 'event_id' => $secondEvent, 'reason' => 'Wrong company']);
t('POST void of own entry within the window: 200, and the timeline marks it voided', $h === 200
    && count(array_filter($j['dispatch']['events'], function ($e) use ($secondEvent) { return $e['id'] === $secondEvent && $e['voided'] === true; })) === 1);
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'nonsense']);
t('an unknown action answers 400', $h === 400);
[$j, $h] = disp('GET', $dispA, $orgA, 'good', ['action' => 'ticket', 'ticket_id' => $tA]);
t('GET ticket returns the incident\'s dispatches with their timelines', $h === 200 && count($j['dispatches']) === 1 && count($j['dispatches'][0]['events']) >= 5);

// disabled / schema
vf_set_settings(array_merge($BASE, ['vendor_dispatch_enabled' => '0']));
[$j, $h] = disp('GET', $dispA, $orgA, 'good', ['action' => 'config', 'ticket_id' => $tA]);
t('with the feature OFF, config answers enabled:false', $h === 200 && $j === ['enabled' => false]);
[$j, $h] = disp('POST', $dispA, $orgA, 'good', $offerBody);
t('...and a write is refused (409 vendor_disabled), not silently accepted', $h === 409 && $j['code'] === 'vendor_disabled');
vf_set_settings($BASE);

// ══════════════════════════════════════════════════════════════
// 4. admin endpoint
// ══════════════════════════════════════════════════════════════
echo "\n--- admin endpoint ---\n";
[$j, $h] = adm('GET', $dispA, $orgA, 'good', ['action' => 'overview']);
t('a Dispatcher (no action.manage_vendors) is refused by the admin endpoint: 403', $h === 403);
[$j, $h] = adm('GET', $mgrA, $orgA, 'good', ['action' => 'overview']);
t('an Org Admin gets the overview (200)', $h === 200 && isset($j['providers'], $j['lists'], $j['service_types'], $j['settings'], $j['orgs']));
t('the overview shows only org A\'s companies and lists', in_array($pA1, array_column($j['providers'], 'id'), true) && !in_array($pB1, array_column($j['providers'], 'id'), true)
    && in_array($listA, array_column($j['lists'], 'id'), true) && !in_array($listB, array_column($j['lists'], 'id'), true));
$la = null; foreach ($j['lists'] as $l) { if ($l['id'] === $listA) $la = $l; }
t('the list row shows the COMPUTED next-up (A1 had a turn, declined; A2 was called: next is the least recently used)', $la !== null && $la['next_up_name'] !== null && $la['member_count'] === 2);
[$j, $h] = adm('GET', $mgrA, $orgA, 'good', ['action' => 'list_detail', 'list_id' => $listB]);
t('org B\'s list detail is invisible to org A\'s manager: 404', $h === 404);
[$j, $h] = adm('POST', $mgrA, $orgA, 'good', ['action' => 'provider_save', 'id' => $pB1, 'name' => 'Hijacked', 'phone' => '555-0000']);
t('an org A manager cannot edit org B\'s company: 404', $h === 404 && vendor_provider_get($pB1)['name'] === 'VTE B1 Towing');
[$j, $h] = adm('POST', $mgrA, $orgA, 'good', ['action' => 'member_add', 'list_id' => $listB, 'provider_id' => $pA1]);
t('...nor put a company on org B\'s list: 404', $h === 404);
[$j, $h] = adm('POST', $mgrA, $orgA, 'bad', ['action' => 'provider_save', 'name' => 'No CSRF', 'phone' => '555-0001']);
t('the admin endpoint refuses a bad CSRF token too', $h === 403);
// The settings and the service types are INSTALL-WIDE (every agency's dialog reads them): an Org Admin is refused,
// a Super Admin is not (review finding: one agency's manager could switch the feature off for all of them).
[$j, $h] = adm('POST', $mgrA, $orgA, 'good', ['action' => 'settings_save', 'vendor_dispatch_enabled' => '0']);
t('an org A manager cannot change the install-wide settings: 403 forbidden_scope', $h === 403 && $j['code'] === 'forbidden_scope');
t('...and the feature is still on', vendor_settings_current()['vendor_dispatch_enabled'] === '1');
[$j, $h] = adm('POST', $mgrA, $orgA, 'good', ['action' => 'service_type_save', 'id' => $TOW, 'label' => 'Hijacked', 'is_active' => 0]);
t('...nor rename or retire the shared "tow" service type: 403', $h === 403 && (string) db_fetch_value("SELECT `label` FROM `{$prefix}vendor_service_types` WHERE `id` = ?", [$TOW]) !== 'Hijacked');
[$j, $h] = adm('POST', $admin, 0, 'good', ['action' => 'settings_save', 'vendor_rotation_mode' => 'sideways']);
t('settings_save refuses an out-of-enum value: 422', $h === 422 && $j['code'] === 'validation');
t('...and nothing was written', vendor_settings_current()['vendor_rotation_mode'] === 'round_robin');
[$j, $h] = adm('POST', $admin, 0, 'good', ['action' => 'settings_save', 'vendor_rotation_mode' => 'strict_order']);
t('settings_save accepts a valid value from a Super Admin and echoes the settings', $h === 200 && $j['settings']['vendor_rotation_mode'] === 'strict_order');
$aud = db_fetch_one("SELECT * FROM `{$prefix}newui_audit_log` WHERE `category` = 'vendor' AND `activity` = 'settings.update' ORDER BY `id` DESC LIMIT 1");
t('...and the before/after is in the audit trail', $aud && strpos((string) $aud['details'], 'round_robin') !== false && strpos((string) $aud['details'], 'strict_order') !== false);
vf_set_settings($BASE);
[$j, $h] = adm('POST', $mgrA, $orgA, 'good', ['action' => 'provider_save', 'name' => 'Created by org A manager', 'phone' => '555-0444']);
$newPid = (int) ($j['id'] ?? 0);
if ($newPid) vf_track('providers', $newPid);
t('a new company is created in the caller\'s own organization', $h === 200 && $newPid > 0 && (int) vendor_provider_get($newPid)['org_id'] === $orgA);
[$j, $h] = adm('POST', $mgrA, $orgA, 'good', ['action' => 'provider_save', 'name' => 'Everyone\'s company', 'phone' => '555-0445', 'org_id' => 'all']);
t('an org-scoped manager cannot create an "all agencies" company: 403', $h === 403);

// CSV
[$j, $h, $raw] = adm('GET', $mgrA, $orgA, 'good', ['action' => 'history_csv']);
t('history_csv answers 200 with a UTF-8 BOM, a header row and rows', $h === 200 && substr($raw, 0, 3) === "\xEF\xBB\xBF" && strpos($raw, 'event_id,event_at,dispatch_ref') !== false && substr_count($raw, "\n") > 3);
t('the CSV contains the dispatch reference and the company that was called', strpos($raw, incnum_display($tA) . '-T1') !== false && strpos($raw, 'VTE A1 Towing') !== false);
// the spreadsheet-injection guard, driven through a real offer to the company whose name starts with "="
[$j, $h] = disp('POST', $dispA, $orgA, 'good', ['action' => 'offer', 'ticket_id' => $tA, 'service_type_id' => $TOW, 'provider_id' => $pEvil, 'owner_request' => true]);
[$j, $h, $raw] = adm('GET', $mgrA, $orgA, 'good', ['action' => 'history_csv']);
t('a company name starting with = is written with a leading apostrophe (never a live formula)', strpos($raw, "'=HYPERLINK") !== false && !preg_match('/(^|,)"?=HYPERLINK/m', $raw));
$exp = db_fetch_one("SELECT * FROM `{$prefix}newui_audit_log` WHERE `category` = 'vendor' AND `activity` = 'history.export' ORDER BY `id` DESC LIMIT 1");
t('the export itself is audited (vendor history.export)', $exp !== null);
[$j, $h, $raw] = adm('GET', $mgrA, $orgA, 'good', ['action' => 'history_csv', 'list_id' => $listB]);
t('the CSV never includes org B\'s rows to an org A manager', strpos($raw, 'VTE B1 Towing') === false);
[$j, $h] = adm('GET', $mgrA, $orgA, 'good', ['action' => 'history']);
t('the JSON history answers 200 with events and a per-company summary', $h === 200 && is_array($j['events']) && is_array($j['summary']) && count($j['summary']) >= 2);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
