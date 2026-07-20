<?php
/**
 * NewUI v4.0 API — Weather alerts (Phase 112, Phase 1).
 *
 * GET (any authenticated user):
 *   ?action=active                 → currently-active alerts (tray/banner)
 * GET (admin — action.manage_weather_alerts):
 *   ?action=config                 → weather_* settings + config warning
 *   ?action=areas                  → coverage-area rows
 *   ?action=rules                  → routing-rule rows
 * POST (admin, CSRF):
 *   action=save_settings           → master switch, UA contact, poll secs, TTS
 *   action=save_area / delete_area
 *   action=save_rule  / delete_rule
 *   action=test_fixture            → inject a synthetic Severe MN alert + poll
 *                                    (verifies tray/banner without live weather)
 *   action=dry_run                 → live NWS evaluate, write/emit NOTHING
 *   action=test_poll               → live NWS real poll (needs enabled + UA)
 *   action=load_minnesota_example  → seed Eric's MN area+rules (INACTIVE)
 *
 * Everything downstream honors the master switch — with it OFF the poll actions
 * report inert and change nothing.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/weather_alerts.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
}

$isAdmin = rbac_can('action.manage_weather_alerts') || is_admin();

/** Admin gate + CSRF for writes. */
function _wx_require_admin_write(array $input): void
{
    global $isAdmin;
    if (!$isAdmin) json_error('Insufficient permissions: manage weather alerts', 403);
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) json_error('Invalid CSRF token', 403);
}

// ── Dispatcher-facing read: active alerts (banner/tray) ───────────────────
if ($method === 'GET' && ($action === 'active' || $action === '')) {
    $alerts = weather_active_alerts(50);
    // Phase 4 — compute WHO is inside each alert polygon live on every read,
    // so the situation display stays current as units move. Cheap: typically
    // 0–3 active alerts × the resolved unit set. Best-effort per alert.
    if (weather_enabled() && weather_geofence_enabled()) {
        foreach ($alerts as &$a) {
            try {
                $a['units_inside'] = array_map(static function ($u) {
                    return ['responder_id' => $u['responder_id'], 'unit_identifier' => $u['unit_identifier']];
                }, weather_units_in_alert($a));
                $a['zones_inside'] = weather_zones_in_alert($a);
            } catch (Throwable $e) {
                $a['units_inside'] = [];
                $a['zones_inside'] = [];
            }
        }
        unset($a);
    }
    json_response([
        'enabled' => weather_enabled(),
        'alerts'  => $alerts,
    ]);
}

// ── Admin reads ────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'config') {
    if (!$isAdmin) json_error('Insufficient permissions', 403);
    $keys = ['weather_alerts_enabled','weather_provider','weather_poll_seconds',
             'weather_ua_contact','weather_tts_clear_channel_seconds','weather_tts_callsign',
             'weather_tts_prefix','weather_tts_voice','weather_tts_max_seconds',
             'weather_geofence_units','weather_radio_allow_autofire'];
    $cfg = [];
    foreach ($keys as $k) $cfg[$k] = weather_setting($k, '');
    json_response(['config' => $cfg, 'warning' => weather_config_warning()]);
}

// County picker: list a state's NWS county zones (works for any US state, so
// installs anywhere in the country build their coverage the same way).
if ($method === 'GET' && $action === 'nws_counties') {
    if (!$isAdmin) json_error('Insufficient permissions', 403);
    $ua = weather_setting('weather_ua_contact', '');
    if (trim($ua) === '') json_error('Set the contact email first — the NWS API requires it.');
    require_once __DIR__ . '/../inc/weather_provider_nws.php';
    $res = weather_nws_counties((string) ($_GET['state'] ?? ''), $ua);
    if (!$res['ok']) json_error($res['error'] ?? 'county lookup failed');
    json_response(['state' => strtoupper(substr(trim((string) $_GET['state']), 0, 2)),
                   'counties' => $res['counties']]);
}

if ($method === 'GET' && $action === 'areas') {
    if (!$isAdmin) json_error('Insufficient permissions', 403);
    json_response(['areas' => db_fetch_all(
        "SELECT * FROM `{$prefix}weather_alert_areas` ORDER BY `sort_order`, `id`"
    )]);
}

if ($method === 'GET' && $action === 'rules') {
    if (!$isAdmin) json_error('Insufficient permissions', 403);
    json_response(['rules' => db_fetch_all(
        "SELECT r.*, a.label AS area_label
         FROM `{$prefix}weather_alert_rules` r
         LEFT JOIN `{$prefix}weather_alert_areas` a ON a.id = r.area_id
         ORDER BY r.`sort_order`, r.`id`"
    )]);
}

// ── Admin writes ───────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'save_settings') {
    _wx_require_admin_write($input);
    // Whitelisted, typed writes. Master switch requires a non-empty UA contact.
    $enabled  = !empty($input['weather_alerts_enabled']) ? '1' : '0';
    $ua       = trim((string) ($input['weather_ua_contact'] ?? ''));
    if ($enabled === '1' && $ua === '') {
        json_error('A contact email (User-Agent contact) is required before enabling weather alerts.');
    }
    $writes = [
        'weather_alerts_enabled'            => $enabled,
        'weather_provider'                  => in_array(($input['weather_provider'] ?? 'nws'), ['nws'], true) ? $input['weather_provider'] : 'nws',
        'weather_poll_seconds'              => (string) max(30, (int) ($input['weather_poll_seconds'] ?? 60)),
        'weather_ua_contact'                => $ua,
        'weather_tts_clear_channel_seconds' => (string) max(0, (float) ($input['weather_tts_clear_channel_seconds'] ?? 3.0)),
        'weather_tts_callsign'              => trim((string) ($input['weather_tts_callsign'] ?? '')),
        'weather_tts_prefix'                => trim((string) ($input['weather_tts_prefix'] ?? '')),
        'weather_tts_voice'                 => trim((string) ($input['weather_tts_voice'] ?? '')),
        'weather_tts_max_seconds'           => (string) max(10, (int) ($input['weather_tts_max_seconds'] ?? 45)),
        // Phase 4 — flag units/zones inside alert polygons (default ON).
        'weather_geofence_units'            => isset($input['weather_geofence_units']) && !$input['weather_geofence_units'] ? '0' : '1',
        // Phase 3 — EXTRA safety switch for unattended radio keying (default
        // OFF): only an explicit truthy value enables auto-fire; anything else
        // (absent, 0, '') stays off. Without it, auto_fire rules degrade to
        // operator approval.
        'weather_radio_allow_autofire'      => !empty($input['weather_radio_allow_autofire']) ? '1' : '0',
    ];
    try {
        foreach ($writes as $k => $v) {
            db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES (?, ?)
                      ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$k, $v]);
        }
        audit_log('weather', 'update', 'weather_settings', null,
            'Updated weather-alert settings (enabled=' . $enabled . ')',
            ['enabled' => $enabled, 'provider' => $writes['weather_provider']]);
        json_response(['saved' => true, 'warning' => weather_config_warning()]);
    } catch (Throwable $e) {
        json_error_safe('Save failed. Check server logs.', $e, 'weather.save_settings');
    }
}

if ($method === 'POST' && $action === 'save_area') {
    _wx_require_admin_write($input);
    $id    = (int) ($input['id'] ?? 0);
    $label = trim((string) ($input['label'] ?? ''));
    $kind  = (string) ($input['kind'] ?? '');
    if ($label === '') json_error('Label is required');
    if (!in_array($kind, ['state', 'zones', 'point_radius'], true)) json_error('Invalid area kind');

    $state  = $kind === 'state' ? strtoupper(substr(trim((string) ($input['state_code'] ?? '')), 0, 2)) : null;
    $zones  = $kind === 'zones' ? trim((string) ($input['zones'] ?? '')) : null;
    $lat    = $kind === 'point_radius' ? (float) ($input['lat'] ?? 0) : null;
    $lng    = $kind === 'point_radius' ? (float) ($input['lng'] ?? 0) : null;
    $radius = $kind === 'point_radius' ? max(1, (int) ($input['radius_miles'] ?? 40)) : null;
    $active = !empty($input['active']) ? 1 : 0;
    $sort   = (int) ($input['sort_order'] ?? 0);

    try {
        if ($id > 0) {
            db_query("UPDATE `{$prefix}weather_alert_areas`
                      SET `label`=?,`kind`=?,`state_code`=?,`zones`=?,`lat`=?,`lng`=?,`radius_miles`=?,`active`=?,`sort_order`=?
                      WHERE `id`=?",
                [$label, $kind, $state, $zones, $lat, $lng, $radius, $active, $sort, $id]);
        } else {
            db_query("INSERT INTO `{$prefix}weather_alert_areas`
                      (`label`,`kind`,`state_code`,`zones`,`lat`,`lng`,`radius_miles`,`active`,`sort_order`)
                      VALUES (?,?,?,?,?,?,?,?,?)",
                [$label, $kind, $state, $zones, $lat, $lng, $radius, $active, $sort]);
            $id = (int) db_insert_id();
        }
        audit_log('weather', $id ? 'update' : 'create', 'weather_area', $id, "Weather area '{$label}' saved");
        json_response(['saved' => true, 'id' => $id]);
    } catch (Throwable $e) {
        json_error_safe('Save failed. Check server logs.', $e, 'weather.save_area');
    }
}

if ($method === 'POST' && $action === 'delete_area') {
    _wx_require_admin_write($input);
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('Missing id');
    try {
        db_query("DELETE FROM `{$prefix}weather_alert_rules` WHERE `area_id`=?", [$id]);
        db_query("DELETE FROM `{$prefix}weather_alert_areas` WHERE `id`=?", [$id]);
        audit_log('weather', 'delete', 'weather_area', $id, "Weather area #{$id} deleted (with its rules)");
        json_response(['deleted' => true]);
    } catch (Throwable $e) {
        json_error_safe('Delete failed. Check server logs.', $e, 'weather.delete_area');
    }
}

if ($method === 'POST' && $action === 'save_rule') {
    _wx_require_admin_write($input);
    $id     = (int) ($input['id'] ?? 0);
    $label  = trim((string) ($input['label'] ?? ''));
    $areaId = (int) ($input['area_id'] ?? 0);
    $target = (string) ($input['target'] ?? '');
    if ($label === '') json_error('Label is required');
    if ($areaId <= 0) json_error('A coverage area is required');
    if (!in_array($target, ['tray', 'chat', 'sms', 'email', 'zello', 'dmr'], true)) json_error('Invalid target');

    $minSev  = in_array(($input['min_severity'] ?? 'Severe'), ['Minor','Moderate','Severe','Extreme'], true) ? $input['min_severity'] : 'Severe';
    $minUrg  = in_array(($input['min_urgency'] ?? 'Expected'), ['Past','Future','Expected','Immediate'], true) ? $input['min_urgency'] : 'Expected';
    $mode    = in_array(($input['action_mode'] ?? 'notify'), ['notify','auto_fire','operator_approve'], true) ? $input['action_mode'] : 'notify';
    $writes  = [
        $label, $areaId, $target, trim((string) ($input['target_ref'] ?? '')),
        $minSev, $minUrg,
        trim((string) ($input['event_allow'] ?? '')), trim((string) ($input['event_deny'] ?? '')),
        trim((string) ($input['message_types'] ?? 'Alert,Update')) ?: 'Alert,Update',
        $mode, !empty($input['repeat_on_update']) ? 1 : 0, !empty($input['active']) ? 1 : 0,
        (int) ($input['sort_order'] ?? 0),
    ];
    try {
        if ($id > 0) {
            db_query("UPDATE `{$prefix}weather_alert_rules`
                      SET `label`=?,`area_id`=?,`target`=?,`target_ref`=?,`min_severity`=?,`min_urgency`=?,
                          `event_allow`=?,`event_deny`=?,`message_types`=?,`action_mode`=?,`repeat_on_update`=?,`active`=?,`sort_order`=?
                      WHERE `id`=?", array_merge($writes, [$id]));
        } else {
            db_query("INSERT INTO `{$prefix}weather_alert_rules`
                      (`label`,`area_id`,`target`,`target_ref`,`min_severity`,`min_urgency`,
                       `event_allow`,`event_deny`,`message_types`,`action_mode`,`repeat_on_update`,`active`,`sort_order`)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", $writes);
            $id = (int) db_insert_id();
        }
        audit_log('weather', $id ? 'update' : 'create', 'weather_rule', $id, "Weather rule '{$label}' → {$target} saved");
        json_response(['saved' => true, 'id' => $id]);
    } catch (Throwable $e) {
        json_error_safe('Save failed. Check server logs.', $e, 'weather.save_rule');
    }
}

if ($method === 'POST' && $action === 'delete_rule') {
    _wx_require_admin_write($input);
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('Missing id');
    try {
        db_query("DELETE FROM `{$prefix}weather_alert_rules` WHERE `id`=?", [$id]);
        audit_log('weather', 'delete', 'weather_rule', $id, "Weather rule #{$id} deleted");
        json_response(['deleted' => true]);
    } catch (Throwable $e) {
        json_error_safe('Delete failed. Check server logs.', $e, 'weather.delete_rule');
    }
}

// ── Diagnostics ────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'dry_run') {
    _wx_require_admin_write($input);
    // Live NWS evaluate; writes/emits NOTHING.
    $summary = weather_poll_run(true, null);
    json_response(['summary' => $summary]);
}

if ($method === 'POST' && $action === 'test_poll') {
    _wx_require_admin_write($input);
    // Real live poll (needs enabled + UA). Actually notifies matched rules.
    $summary = weather_poll_run(false, null);
    audit_log('weather', 'test_poll', 'weather', null, 'Manual live weather poll', $summary);
    json_response(['summary' => $summary]);
}

if ($method === 'POST' && $action === 'test_fixture') {
    _wx_require_admin_write($input);
    // Inject a synthetic Severe MN alert so an admin can verify the tray/banner
    // pipeline without waiting for real severe weather. Honors the master
    // switch: with it OFF the poll is inert and nothing fires.
    $fixture = [
        'id' => 'urn:tickets:wx-test:' . substr(md5((string) ($_SERVER['REQUEST_TIME'] ?? '') . ($_SESSION['user_id'] ?? '')), 0, 10),
        'geometry' => ['type' => 'Point', 'coordinates' => [-93.27, 44.98]],
        'properties' => [
            'event' => 'Severe Thunderstorm Warning (TEST)', 'severity' => 'Severe',
            'urgency' => 'Expected', 'certainty' => 'Observed', 'messageType' => 'Alert',
            'areaDesc' => 'TEST — Hennepin, MN', 'headline' => 'TEST weather alert (manual fixture)',
            'instruction' => 'This is a configuration test. No action needed.',
            'expires' => date('Y-m-d\TH:i:sP', time() + 1800),
            'geocode' => ['UGC' => ['MNC053']],
        ],
    ];
    $fetcher = static function (array $area, string $ua) use ($fixture) {
        return ['ok' => true, 'status' => 200, 'features' => [$fixture]];
    };
    $summary = weather_poll_run(false, $fetcher);
    audit_log('weather', 'test_fixture', 'weather', null, 'Injected synthetic weather alert (config test)', $summary);
    json_response(['summary' => $summary,
                   'note' => $summary['enabled'] ? 'Fixture injected — check the notification tray/banner.'
                                                 : 'Master switch is OFF, so nothing fired (as designed).']);
}

if ($method === 'POST' && $action === 'load_minnesota_example') {
    _wx_require_admin_write($input);
    // One-click seed of Eric's install config — coverage areas + rules, all
    // INACTIVE and behind the (still-OFF) master switch, so nothing fires until
    // an admin reviews + activates them. Idempotent by label.
    try {
        $mk_area = function (string $label, array $cols) use ($prefix) {
            $exists = db_fetch_one("SELECT id FROM `{$prefix}weather_alert_areas` WHERE `label`=? LIMIT 1", [$label]);
            if ($exists) return (int) $exists['id'];
            db_query("INSERT INTO `{$prefix}weather_alert_areas`
                      (`label`,`kind`,`state_code`,`zones`,`lat`,`lng`,`radius_miles`,`active`,`sort_order`)
                      VALUES (?,?,?,?,?,?,?,?,?)", [
                $label, $cols['kind'], $cols['state_code'] ?? null, $cols['zones'] ?? null,
                $cols['lat'] ?? null, $cols['lng'] ?? null, $cols['radius_miles'] ?? null,
                0, $cols['sort_order'] ?? 0,
            ]);
            return (int) db_insert_id();
        };
        $stateId  = $mk_area('MN statewide', ['kind' => 'state', 'state_code' => 'MN', 'sort_order' => 0]);
        $metroId  = $mk_area('Metro (40 mi)', ['kind' => 'point_radius', 'lat' => 44.98, 'lng' => -93.27, 'radius_miles' => 40, 'sort_order' => 1]);

        $mk_rule = function (string $label, int $areaId, array $cols) use ($prefix) {
            $exists = db_fetch_one("SELECT id FROM `{$prefix}weather_alert_rules` WHERE `label`=? LIMIT 1", [$label]);
            if ($exists) return;
            db_query("INSERT INTO `{$prefix}weather_alert_rules`
                      (`label`,`area_id`,`target`,`target_ref`,`min_severity`,`min_urgency`,`message_types`,`action_mode`,`repeat_on_update`,`active`,`sort_order`)
                      VALUES (?,?,?,?,?,?,?,?,?,0,?)", [
                $label, $areaId, $cols['target'], $cols['target_ref'] ?? '',
                $cols['min_severity'] ?? 'Severe', $cols['min_urgency'] ?? 'Expected',
                $cols['message_types'] ?? 'Alert,Update', $cols['action_mode'] ?? 'notify',
                $cols['repeat_on_update'] ?? 1, $cols['sort_order'] ?? 0,
            ]);
        };
        // Tray+chime for everything (Minor+) so dispatchers see all; radio/Zello
        // targets are seeded but require Phase 3 (still inactive here).
        $mk_rule('Tray — all MN alerts', $stateId, ['target' => 'tray', 'min_severity' => 'Minor', 'action_mode' => 'notify', 'sort_order' => 0]);
        $mk_rule('DMR TG 3127 — severe MN', $stateId, ['target' => 'dmr', 'target_ref' => '3127', 'min_severity' => 'Severe', 'action_mode' => 'auto_fire', 'sort_order' => 1]);
        $mk_rule('Zello — severe within 40mi', $metroId, ['target' => 'zello', 'min_severity' => 'Severe', 'action_mode' => 'auto_fire', 'sort_order' => 2]);

        audit_log('weather', 'seed_example', 'weather', null, 'Loaded Minnesota example weather config (inactive)');
        json_response(['seeded' => true, 'note' => 'Minnesota example loaded (all rows INACTIVE). Review and activate what you need.']);
    } catch (Throwable $e) {
        json_error_safe('Seed failed. Check server logs.', $e, 'weather.seed_mn');
    }
}

json_error('Unknown action', 400);
