<?php
/**
 * Phase 112 — Weather-alert engine (Phase 1: poll + in-CAD notify).
 *
 * Orchestrates: master-switch guard → load coverage areas → fetch/normalize
 * (NWS provider) → de-dup + Alert/Update/Cancel/expire lifecycle → match each
 * area's rules → dispatch-once → emit to the notification tray + situation
 * banner (SSE). Everything is inert unless weather_alerts_enabled = '1'.
 *
 * Design notes:
 *  - PURE matchers (area/rule/severity/distance) are unit-tested with no I/O.
 *  - weather_poll_run() takes an INJECTED fetcher so tests + the admin
 *    "test poll" drive it with a fixture — the live NWS call is never made in
 *    CI. Default fetcher = the real NWS provider.
 *  - Phase 1 delivers ONLY the 'tray' target. Non-tray rules (chat/sms/email/
 *    zello/dmr) are NOT evaluated yet and leave the dispatch ledger untouched,
 *    so Phase 2/3 can deliver them cleanly (uk_once stays free for them).
 *  - Fail-safe: master OFF ⇒ no rows, no UI. UA blank ⇒ refuse to poll +
 *    surface a health warning. NWS error ⇒ logged, never crashes a page.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/weather_provider_nws.php';
if (is_file(__DIR__ . '/sse.php')) { require_once __DIR__ . '/sse.php'; }

/**
 * Read a setting FRESH from the DB (no static cache). The engine can run in a
 * long-lived poller process, so it must observe live config changes; and tests
 * flip the master switch mid-process. Returns $default when absent.
 */
function weather_setting(string $name, string $default = ''): string
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1", [$name]);
        return $v === null || $v === false ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;
    }
}

/** Master switch. */
function weather_enabled(): bool
{
    return weather_setting('weather_alerts_enabled', '0') === '1';
}

/** Config warning surfaced on the System Health page (empty = healthy). */
function weather_config_warning(): string
{
    if (!weather_enabled()) return '';
    $contact = trim(weather_setting('weather_ua_contact', ''));
    if ($contact === '') {
        return 'Weather alerts are enabled but weather_ua_contact is blank — '
             . 'the NWS API requires a contact and polling is disabled until it is set.';
    }
    return '';
}

// ── Ranking helpers (PURE) ────────────────────────────────────────────────

function weather_severity_rank(?string $sev): int
{
    switch (strtolower((string) $sev)) {
        case 'extreme':  return 4;
        case 'severe':   return 3;
        case 'moderate': return 2;
        case 'minor':    return 1;
        default:         return 0; // Unknown
    }
}

function weather_urgency_rank(?string $urg): int
{
    switch (strtolower((string) $urg)) {
        case 'immediate': return 4;
        case 'expected':  return 3;
        case 'future':    return 2;
        case 'past':      return 1;
        default:          return 0; // Unknown
    }
}

/** Great-circle distance in statute miles. PURE. */
function weather_haversine_miles(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 3958.7613; // mean Earth radius, miles
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * asin(min(1.0, sqrt($a)));
}

/**
 * Does an alert fall inside a coverage area? PURE.
 *  - state: NWS already filtered by ?area=, so a state area matches any alert
 *    the query returned (we trust the query). Callers pass alerts fetched for
 *    THIS area, so return true. When testing cross-area, we also honor an
 *    explicit UGC state-prefix check.
 *  - zones: any of the area's UGC codes appears in the alert's geocode_ugc.
 *  - point_radius: alert centroid within radius_miles of the area point.
 */
function weather_area_matches(array $area, array $alert): bool
{
    $kind = (string) ($area['kind'] ?? '');

    if ($kind === 'state') {
        $code = strtoupper(trim((string) ($area['state_code'] ?? '')));
        if ($code === '') return false;
        $ugc = strtoupper((string) ($alert['geocode_ugc'] ?? ''));
        if ($ugc === '') return true; // trust the ?area= query that fetched it
        // UGC codes start with the 2-letter state (e.g. MNZ060, MNC053).
        foreach (explode(',', $ugc) as $z) {
            if (strpos(trim($z), $code) === 0) return true;
        }
        return false;
    }

    if ($kind === 'zones') {
        $want = array_filter(array_map(static function ($z) {
            return strtoupper(trim($z));
        }, explode(',', (string) ($area['zones'] ?? ''))));
        if (empty($want)) return false;
        $have = array_filter(array_map(static function ($z) {
            return strtoupper(trim($z));
        }, explode(',', (string) ($alert['geocode_ugc'] ?? ''))));
        return (bool) array_intersect($want, $have);
    }

    if ($kind === 'point_radius') {
        $lat = $area['lat'] ?? null; $lng = $area['lng'] ?? null;
        $rad = (float) ($area['radius_miles'] ?? 0);
        $aLat = $alert['centroid_lat'] ?? null; $aLng = $alert['centroid_lng'] ?? null;
        if ($lat === null || $lng === null || $rad <= 0 || $aLat === null || $aLng === null) {
            return false;
        }
        return weather_haversine_miles((float) $lat, (float) $lng, (float) $aLat, (float) $aLng) <= $rad;
    }

    return false;
}

/**
 * Does an alert clear a rule's filters? PURE.
 * severity floor + urgency floor + event allow/deny + message-type set.
 */
function weather_rule_matches(array $rule, array $alert): bool
{
    if (weather_severity_rank($alert['severity'] ?? null) < weather_severity_rank($rule['min_severity'] ?? 'Severe')) {
        return false;
    }
    if (weather_urgency_rank($alert['urgency'] ?? null) < weather_urgency_rank($rule['min_urgency'] ?? 'Expected')) {
        return false;
    }

    $event = strtolower(trim((string) ($alert['event'] ?? '')));

    $allow = _wx_csv_lower($rule['event_allow'] ?? '');
    if (!empty($allow) && !_wx_event_in($event, $allow)) return false;

    $deny = _wx_csv_lower($rule['event_deny'] ?? '');
    if (!empty($deny) && _wx_event_in($event, $deny)) return false;

    $types = _wx_csv_lower($rule['message_types'] ?? 'Alert,Update');
    if (empty($types)) $types = ['alert', 'update'];
    $mt = strtolower(trim((string) ($alert['message_type'] ?? 'Alert')));
    if (!in_array($mt, $types, true)) return false;

    return true;
}

/** Substring-aware event match ("tornado" matches "Tornado Warning"). */
function _wx_event_in(string $event, array $list): bool
{
    foreach ($list as $needle) {
        if ($needle !== '' && strpos($event, $needle) !== false) return true;
    }
    return false;
}

function _wx_csv_lower($csv): array
{
    return array_values(array_filter(array_map(static function ($s) {
        return strtolower(trim($s));
    }, explode(',', (string) $csv)), static function ($s) {
        return $s !== '';
    }));
}

// ── Persistence: dedup + lifecycle ────────────────────────────────────────

/**
 * Upsert a normalized alert by nws_id. Returns:
 *   ['id'=>int, 'is_new'=>bool, 'message_type'=>string, 'status'=>string,
 *    'became_cancelled'=>bool]
 * A messageType of 'Cancel' sets status='cancelled'.
 */
function weather_upsert_alert(array $a): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    $mt = (string) ($a['message_type'] ?? 'Alert');
    $status = (strcasecmp($mt, 'Cancel') === 0) ? 'cancelled' : 'active';

    $existing = db_fetch_one(
        "SELECT `id`, `status`, `message_type` FROM `{$prefix}weather_alerts` WHERE `nws_id` = ? LIMIT 1",
        [$a['nws_id']]
    );

    if ($existing) {
        $id = (int) $existing['id'];
        $becameCancelled = ($status === 'cancelled' && $existing['status'] !== 'cancelled');
        db_query(
            "UPDATE `{$prefix}weather_alerts`
             SET `event`=?, `severity`=?, `urgency`=?, `certainty`=?, `message_type`=?,
                 `area_desc`=?, `headline`=?, `description`=?, `instruction`=?,
                 `onset`=?, `expires`=?, `ends`=?, `geocode_ugc`=?, `polygon`=?,
                 `centroid_lat`=?, `centroid_lng`=?, `status`=?, `last_seen`=?
             WHERE `id`=?",
            [
                $a['event'], $a['severity'], $a['urgency'], $a['certainty'], $mt,
                $a['area_desc'], $a['headline'], $a['description'], $a['instruction'],
                $a['onset'], $a['expires'], $a['ends'], $a['geocode_ugc'], $a['polygon'],
                $a['centroid_lat'], $a['centroid_lng'], $status, $now, $id,
            ]
        );
        return ['id' => $id, 'is_new' => false, 'message_type' => $mt,
                'status' => $status, 'became_cancelled' => $becameCancelled];
    }

    db_query(
        "INSERT INTO `{$prefix}weather_alerts`
            (`nws_id`,`event`,`severity`,`urgency`,`certainty`,`message_type`,
             `area_desc`,`headline`,`description`,`instruction`,
             `onset`,`expires`,`ends`,`geocode_ugc`,`polygon`,
             `centroid_lat`,`centroid_lng`,`status`,`first_seen`,`last_seen`)
         VALUES (?,?,?,?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?, ?,?,?)",
        [
            $a['nws_id'], $a['event'], $a['severity'], $a['urgency'], $a['certainty'], $mt,
            $a['area_desc'], $a['headline'], $a['description'], $a['instruction'],
            $a['onset'], $a['expires'], $a['ends'], $a['geocode_ugc'], $a['polygon'],
            $a['centroid_lat'], $a['centroid_lng'], $status, $now, $now,
        ]
    );
    return ['id' => (int) db_insert_id(), 'is_new' => true, 'message_type' => $mt,
            'status' => $status, 'became_cancelled' => ($status === 'cancelled')];
}

/**
 * Claim a (alert, rule, message_type) dispatch slot exactly once. Returns true
 * if THIS caller won the claim (should deliver); false if already dispatched.
 * The unique key uk_once makes this race-safe.
 */
function weather_dispatch_claim(int $alertId, int $ruleId, string $messageType, string $status = 'sent', string $detail = ''): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}weather_alert_dispatch`
                (`alert_id`,`rule_id`,`nws_message_type`,`status`,`detail`,`created_at`)
             VALUES (?,?,?,?,?,?)",
            [$alertId, $ruleId, $messageType, $status, $detail, date('Y-m-d H:i:s')]
        );
        return true;
    } catch (Throwable $e) {
        // Duplicate key ⇒ already dispatched for this revision ⇒ not ours.
        return false;
    }
}

// ── Tray emit ─────────────────────────────────────────────────────────────

/** Emit a weather alert to the notification tray + situation banner (SSE). */
function weather_emit_tray(array $alert, array $rule): void
{
    if (!function_exists('sse_publish')) return;
    // Phase 4 — name the units inside the alert polygon right in the
    // notification ("Units inside: Alpha, Delta"). Best-effort.
    $unitsInside = [];
    try {
        foreach (weather_units_in_alert($alert) as $u) {
            $unitsInside[] = $u['unit_identifier'];
        }
    } catch (Throwable $e) { $unitsInside = []; }
    // Null-coalesce every field: callers may pass a minimal alert struct
    // (e.g. a radio rule in notify mode) — a PHP warning here must never
    // break the delivery path.
    sse_publish('weather:alert', [
        'nws_id'       => $alert['nws_id']      ?? '',
        'event'        => $alert['event']       ?? '',
        'severity'     => $alert['severity']    ?? '',
        'urgency'      => $alert['urgency']     ?? '',
        'area_desc'    => $alert['area_desc']   ?? '',
        'headline'     => $alert['headline']    ?? '',
        'instruction'  => $alert['instruction'] ?? '',
        'expires'      => $alert['expires']     ?? null,
        'rule'         => $rule['label'] ?? '',
        'units_inside' => $unitsInside,
    ], null, 'public');
}

/** Emit a clear (cancel/expire) so the situation banner drops the alert. */
function weather_emit_clear(string $nwsId, string $reason): void
{
    if (!function_exists('sse_publish')) return;
    sse_publish('weather:clear', ['nws_id' => $nwsId, 'reason' => $reason], null, 'public');
}

// ── Phase 2: route to messaging channels (chat / SMS / email) ─────────────

/**
 * Build a compact human-readable alert message for a text channel. PURE.
 * event + area + the NWS instruction (or headline), trimmed so SMS stays sane.
 */
function weather_build_message_text(array $alert, int $maxLen = 300): string
{
    $parts = [];
    if (!empty($alert['event']))     $parts[] = (string) $alert['event'];
    if (!empty($alert['area_desc'])) $parts[] = (string) $alert['area_desc'];
    $detail = trim((string) ($alert['instruction'] ?? '')) ?: trim((string) ($alert['headline'] ?? ''));
    $text = implode(' — ', $parts);
    if ($detail !== '') $text .= ': ' . $detail;
    $text = trim($text);
    if (function_exists('mb_strlen') && mb_strlen($text) > $maxLen) {
        $text = rtrim(mb_substr($text, 0, $maxLen - 1)) . '…';
    } elseif (strlen($text) > $maxLen) {
        $text = rtrim(substr($text, 0, $maxLen - 1)) . '...';
    }
    return $text;
}

/** Map a rule target to a broker channel code. */
function weather_target_channel(string $target): string
{
    switch ($target) {
        case 'chat':  return 'local_chat';
        case 'sms':   return 'sms';
        case 'email': return 'email';
        case 'zello': return 'zello';
        case 'dmr':   return 'dmr';
        default:      return $target;
    }
}

/**
 * Deliver an alert to a messaging channel via the broker. Returns
 * ['ok'=>bool, 'detail'=>string]. Never throws.
 */
function weather_deliver_via_broker(array $alert, array $rule): array
{
    if (!function_exists('broker_send')) {
        // Lazy-load the broker only when a message target actually fires.
        if (is_file(__DIR__ . '/broker.php')) { require_once __DIR__ . '/broker.php'; }
    }
    if (!function_exists('broker_send')) return ['ok' => false, 'detail' => 'broker unavailable'];

    $channel = weather_target_channel((string) $rule['target']);
    $to      = trim((string) ($rule['target_ref'] ?? '')) ?: 'all';
    $sev     = strtolower((string) ($alert['severity'] ?? ''));
    $priority = ($sev === 'extreme' || $sev === 'severe') ? 'high' : 'normal';

    try {
        $res = broker_send($channel, [
            'to'       => $to,
            'body'     => weather_build_message_text($alert),
            'type'     => 'weather',
            'priority' => $priority,
            'subject'  => 'Weather: ' . (string) ($alert['event'] ?? 'alert'),
        ]);
        return ['ok' => !empty($res['success']), 'detail' => (string) ($res['error'] ?? '')];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => $e->getMessage()];
    }
}

// ── Phase 4: geofence cross-ref — who/what is inside the alert polygon? ───

/**
 * Is the units-inside-alert cross-ref enabled? Configurable; defaults ON
 * (inert anyway unless the weather master switch is on).
 */
function weather_geofence_enabled(): bool
{
    return weather_setting('weather_geofence_units', '1') === '1';
}

/**
 * Units currently INSIDE an alert's polygon. Reuses the geofencing ray-cast
 * (inc/geofence.php: Polygon/MultiPolygon/Circle) against the live unit
 * positions from the location resolver.
 *
 * @param array      $alert normalized alert (needs ['polygon'] GeoJSON string)
 * @param array|null $units injectable for tests: [{responder_id, unit_identifier,
 *                          lat, lng}] — null ⇒ location_resolve_all_units()
 * @return array [{responder_id, unit_identifier, lat, lng}]
 */
function weather_units_in_alert(array $alert, ?array $units = null): array
{
    if (!weather_geofence_enabled()) return [];
    $poly = $alert['polygon'] ?? null;
    if ($poly === null || $poly === '' || strtolower(trim((string) $poly)) === 'null') return [];

    if (!function_exists('geofence_test_point')) {
        if (is_file(__DIR__ . '/geofence.php')) require_once __DIR__ . '/geofence.php';
        if (!function_exists('geofence_test_point')) return [];
    }
    if ($units === null) {
        if (!function_exists('location_resolve_all_units')) {
            if (is_file(__DIR__ . '/location-resolver.php')) require_once __DIR__ . '/location-resolver.php';
        }
        $units = function_exists('location_resolve_all_units') ? location_resolve_all_units() : [];
    }

    $inside = [];
    foreach ($units as $u) {
        $lat = isset($u['lat']) ? (float) $u['lat'] : 0.0;
        $lng = isset($u['lng']) ? (float) $u['lng'] : 0.0;
        if (!$lat || !$lng) continue;
        try {
            if (geofence_test_point($lat, $lng, $poly)) {
                $inside[] = [
                    'responder_id'    => (int) ($u['responder_id'] ?? 0),
                    'unit_identifier' => (string) ($u['unit_identifier'] ?? ('#' . (int) ($u['responder_id'] ?? 0))),
                    'lat'             => $lat,
                    'lng'             => $lng,
                ];
            }
        } catch (Throwable $e) { /* one bad geometry never breaks the sweep */ }
    }
    return $inside;
}

/**
 * Event zones that OVERLAP an alert polygon (Phase 109 zones ↔ Phase 112
 * alerts). Approximation, documented: a zone is flagged when (a) any vertex of
 * its geometry lies inside the alert polygon, OR (b) the alert's centroid lies
 * inside the zone's geometry — catches both a warning over a zone corner and a
 * small warning wholly inside a large zone. Exact polygon intersection isn't
 * needed for "should net control look at Zone 3?".
 *
 * @param array      $alert normalized alert (polygon + centroid_lat/lng)
 * @param array|null $zones injectable for tests: [{id, name, geo_json}] —
 *                          null ⇒ the ACTIVE event's zones (empty if none set)
 * @return array [{id, name}]
 */
function weather_zones_in_alert(array $alert, ?array $zones = null): array
{
    if (!weather_geofence_enabled()) return [];
    $poly = $alert['polygon'] ?? null;
    if ($poly === null || $poly === '' || strtolower(trim((string) $poly)) === 'null') return [];

    if (!function_exists('geofence_test_point')) {
        if (is_file(__DIR__ . '/geofence.php')) require_once __DIR__ . '/geofence.php';
        if (!function_exists('geofence_test_point')) return [];
    }
    if ($zones === null) {
        $zones = [];
        if (!function_exists('mi_active_event_ticket_id')) {
            if (is_file(__DIR__ . '/message-incident.php')) require_once __DIR__ . '/message-incident.php';
        }
        $tid = function_exists('mi_active_event_ticket_id') ? mi_active_event_ticket_id() : 0;
        if ($tid > 0) {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            try {
                $zones = db_fetch_all(
                    "SELECT `id`, `name`, `geo_json` FROM `{$prefix}event_zones`
                      WHERE `ticket_id` = ? AND (`hide` = 0 OR `hide` IS NULL)
                        AND `geo_json` IS NOT NULL AND `geo_json` != ''",
                    [$tid]
                );
            } catch (Throwable $e) { $zones = []; }
        }
    }

    $cLat = isset($alert['centroid_lat']) && $alert['centroid_lat'] !== null ? (float) $alert['centroid_lat'] : null;
    $cLng = isset($alert['centroid_lng']) && $alert['centroid_lng'] !== null ? (float) $alert['centroid_lng'] : null;

    $flagged = [];
    foreach ($zones as $z) {
        $zg = $z['geo_json'] ?? null;
        if (!$zg) continue;
        $hit = false;
        try {
            // (b) alert centroid inside the zone's geometry
            if ($cLat !== null && $cLng !== null && geofence_test_point($cLat, $cLng, $zg)) {
                $hit = true;
            }
            // (a) any zone vertex inside the alert polygon
            if (!$hit) {
                $g = json_decode((string) $zg, true);
                $verts = [];
                if (is_array($g)) {
                    if (($g['type'] ?? '') === 'Point' && isset($g['coordinates'][0], $g['coordinates'][1])) {
                        $verts[] = [(float) $g['coordinates'][1], (float) $g['coordinates'][0]];
                    } elseif (($g['type'] ?? '') === 'Polygon' && !empty($g['coordinates'][0])) {
                        foreach ($g['coordinates'][0] as $pt) {
                            if (isset($pt[0], $pt[1])) $verts[] = [(float) $pt[1], (float) $pt[0]];
                        }
                    }
                }
                foreach ($verts as $v) {
                    if (geofence_test_point($v[0], $v[1], $poly)) { $hit = true; break; }
                }
            }
        } catch (Throwable $e) { $hit = false; }
        if ($hit) $flagged[] = ['id' => (int) $z['id'], 'name' => (string) $z['name']];
    }
    return $flagged;
}

// ── Phase 3 (content layer): radio read-out script ────────────────────────

/**
 * Build the spoken read-out script for a DMR/Zello weather bulletin. PURE.
 *
 * Layout (per the claude-on-amateur-radio + fcc-amateur-station-id skills):
 *   <prefix> <event> for <areaDesc>. <instruction>  [truncated]  <CALLSIGN> clear.
 *
 * The §97.119 station ID is appended ONCE at the end as the closing sign-off —
 * only when a callsign is configured (amateur DMR like TG 3127). Non-amateur
 * targets (Zello Work, public-safety DMR) leave the callsign blank → no ID.
 *
 * @param array $alert normalized alert (event/area_desc/instruction/headline)
 * @param array $opts  ['prefix'=>, 'callsign'=>, 'max_seconds'=>int]
 * @return string ready for Piper TTS
 */
function weather_build_readout_script(array $alert, array $opts = []): string
{
    $prefix   = trim((string) ($opts['prefix'] ?? 'Weather bulletin from the National Weather Service.'));
    $callsign = trim((string) ($opts['callsign'] ?? ''));
    $maxSecs  = max(10, (int) ($opts['max_seconds'] ?? 45));

    // Budget the spoken body by an approximate speaking rate (~2.4 words/sec),
    // reserving a little room for the prefix + closing ID.
    $wordBudget = (int) max(12, $maxSecs * 2.4 - 12);

    $parts = [];
    $event = trim((string) ($alert['event'] ?? ''));
    $area  = trim((string) ($alert['area_desc'] ?? ''));
    if ($event !== '') $parts[] = $event . ($area !== '' ? ' for ' . $area : '');
    $detail = trim((string) ($alert['instruction'] ?? '')) ?: trim((string) ($alert['headline'] ?? ''));
    if ($detail !== '') $parts[] = $detail;

    $body = implode('. ', $parts);
    // Word-cap the body.
    $words = preg_split('/\s+/', trim($body), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($words) > $wordBudget) {
        $body = implode(' ', array_slice($words, 0, $wordBudget)) . '…';
    }

    $script = trim($prefix);
    if ($body !== '') $script .= ' ' . $body;
    $script = rtrim($script);
    if ($script !== '' && substr($script, -1) !== '.' && substr($script, -1) !== '…') $script .= '.';

    // §97.119 closing station ID (amateur targets only).
    if ($callsign !== '') $script .= ' ' . strtoupper($callsign) . ' clear.';

    return trim($script);
}

/** Update a dispatch ledger row's status (best-effort, after a claim). */
function weather_dispatch_mark(int $alertId, int $ruleId, string $messageType, string $status, string $detail = ''): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}weather_alert_dispatch`
                SET `status` = ?, `detail` = ?
              WHERE `alert_id` = ? AND `rule_id` = ? AND `nws_message_type` = ?",
            [$status, substr($detail, 0, 255), $alertId, $ruleId, $messageType]
        );
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── Query helpers for the API/tray/banner ─────────────────────────────────

/** Currently-active (non-cancelled, non-expired) alerts for the banner/tray. */
function weather_active_alerts(int $limit = 50): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_all(
            "SELECT `nws_id`,`event`,`severity`,`urgency`,`area_desc`,`headline`,
                    `instruction`,`onset`,`expires`,`status`,
                    `polygon`,`centroid_lat`,`centroid_lng`
             FROM `{$prefix}weather_alerts`
             WHERE `status` = 'active' AND (`expires` IS NULL OR `expires` > NOW())
             ORDER BY FIELD(`severity`,'Extreme','Severe','Moderate','Minor') , `expires` ASC
             LIMIT ?",
            [$limit]
        );
    } catch (Throwable $e) {
        return [];
    }
}

// ── The poll ──────────────────────────────────────────────────────────────

/**
 * Run one poll cycle.
 *
 * @param bool          $dryRun  true ⇒ evaluate + report, but write no dispatch
 *                               rows and emit nothing.
 * @param callable|null $fetcher fn(array $area, string $uaContact): array
 *                               returning the SAME shape as weather_nws_fetch
 *                               (ok/status/features). null ⇒ real NWS fetch.
 * @return array summary: {enabled, ran, areas, fetched, matched, notified,
 *                         errors[], warning}
 */
function weather_poll_run(bool $dryRun = false, ?callable $fetcher = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $summary = ['enabled' => weather_enabled(), 'ran' => false, 'areas' => 0,
                'fetched' => 0, 'matched' => 0, 'notified' => 0,
                'errors' => [], 'warning' => ''];

    if (!weather_enabled()) return $summary; // fully inert

    $uaContact = trim(weather_setting('weather_ua_contact', ''));
    if ($uaContact === '' && $fetcher === null) {
        $summary['warning'] = 'weather_ua_contact is blank — refusing to poll (fail-safe)';
        return $summary;
    }
    if ($fetcher === null) {
        $fetcher = static function (array $area, string $ua): array {
            return weather_nws_fetch(weather_nws_area_query($area), $ua);
        };
    }

    $summary['ran'] = true;

    // Expire lifecycle: anything past its expires time that is still 'active'.
    if (!$dryRun) {
        try {
            $stale = db_fetch_all(
                "SELECT `nws_id` FROM `{$prefix}weather_alerts`
                 WHERE `status`='active' AND `expires` IS NOT NULL AND `expires` <= NOW()"
            );
            if (!empty($stale)) {
                db_query("UPDATE `{$prefix}weather_alerts`
                          SET `status`='expired', `last_seen`=NOW()
                          WHERE `status`='active' AND `expires` IS NOT NULL AND `expires` <= NOW()");
                foreach ($stale as $s) weather_emit_clear($s['nws_id'], 'expired');
            }
        } catch (Throwable $e) {
            $summary['errors'][] = 'expire: ' . $e->getMessage();
        }
    }

    $areas = db_fetch_all(
        "SELECT * FROM `{$prefix}weather_alert_areas` WHERE `active`=1 ORDER BY `sort_order`, `id`"
    );
    $summary['areas'] = count($areas);

    // Preload active rules grouped by area. Phase 1 delivered 'tray'; Phase 2
    // the messaging targets (chat/sms/email); Phase 3 the radio targets
    // (dmr/zello — operator-approve by default, auto-fire behind an extra
    // OFF-by-default switch; see inc/weather_radio.php).
    $rulesByArea = [];
    $ruleRows = db_fetch_all(
        "SELECT * FROM `{$prefix}weather_alert_rules`
          WHERE `active`=1 AND `target` IN ('tray','chat','sms','email','dmr','zello')
          ORDER BY `sort_order`, `id`"
    );
    foreach ($ruleRows as $r) {
        $rulesByArea[(int) $r['area_id']][] = $r;
    }

    foreach ($areas as $area) {
        $areaId = (int) $area['id'];
        $res = $fetcher($area, $uaContact);
        if (empty($res['ok'])) {
            $summary['errors'][] = 'area ' . $area['label'] . ': ' . ($res['error'] ?? 'fetch failed');
            continue;
        }
        $alerts = weather_nws_normalize_collection($res['features'] ?? []);
        $summary['fetched'] += count($alerts);

        foreach ($alerts as $alert) {
            if (!weather_area_matches($area, $alert)) continue;

            $rules = $rulesByArea[$areaId] ?? [];
            if (empty($rules)) continue;

            // Persist first (so the banner/tray can reference it) unless dry-run.
            $up = $dryRun ? ['id' => 0, 'message_type' => (string) ($alert['message_type'] ?? 'Alert')]
                          : weather_upsert_alert($alert);
            $mt = $up['message_type'];

            // Cancel ⇒ clear the banner, do not notify (unless a Cancel rule).
            if (strcasecmp($mt, 'Cancel') === 0) {
                if (!$dryRun && !empty($up['became_cancelled'])) {
                    weather_emit_clear($alert['nws_id'], 'cancelled');
                }
            }

            foreach ($rules as $rule) {
                if (!weather_rule_matches($rule, $alert)) continue;
                $summary['matched']++;

                // repeat_on_update: an Update re-notifies only if allowed.
                // De-dup is keyed on (alert,rule,message_type): an Alert fires
                // once; an Update fires once more (new message_type). If
                // repeat_on_update is off, collapse Update onto the Alert slot.
                $dedupType = $mt;
                if (strcasecmp($mt, 'Update') === 0 && (int) ($rule['repeat_on_update'] ?? 1) !== 1) {
                    $dedupType = 'Alert';
                }

                if ($dryRun) { $summary['notified']++; continue; }

                if (weather_dispatch_claim((int) $up['id'], (int) $rule['id'], $dedupType, 'sent', $rule['target'])) {
                    $target = (string) $rule['target'];
                    if ($target === 'tray') {
                        weather_emit_tray($alert, $rule);
                    } elseif ($target === 'dmr' || $target === 'zello') {
                        // Phase 3 — radio read-out. operator_approve enqueues the
                        // Phase 85f approval card; auto_fire keys ONLY behind the
                        // extra weather_radio_allow_autofire switch. Ledger records
                        // queued / sent / failed so nothing is silent.
                        if (!function_exists('weather_radio_deliver')) {
                            require_once __DIR__ . '/weather_radio.php';
                        }
                        $d = weather_radio_deliver($alert, $rule);
                        weather_dispatch_mark((int) $up['id'], (int) $rule['id'], $dedupType,
                            $d['ledger'], $d['detail'] ?? '');
                    } else {
                        // chat / sms / email → deliver through the broker.
                        $d = weather_deliver_via_broker($alert, $rule);
                        if (empty($d['ok'])) {
                            weather_dispatch_mark((int) $up['id'], (int) $rule['id'], $dedupType,
                                'failed', $d['detail'] ?? 'send failed');
                        }
                    }
                    $summary['notified']++;
                    if (function_exists('audit_log')) {
                        audit_log('weather', 'notify', 'weather_alert', $up['id'],
                            'Weather alert → ' . $target . ': ' . ($alert['event'] ?? '') . ' — ' . ($alert['area_desc'] ?? ''),
                            ['rule' => $rule['label'] ?? '', 'severity' => $alert['severity'] ?? '', 'target' => $target]);
                    }
                }
            }
        }
    }

    return $summary;
}
