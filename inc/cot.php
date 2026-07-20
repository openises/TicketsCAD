<?php
/**
 * NewUI v4.0 — CoT (Cursor-on-Target) translation library
 *
 * Pure-PHP CoT XML encoder/decoder + entity-to-CoT-type mapping +
 * PII gate for the sensitive-channel flag. Used by both the outbound
 * push path (inc/atak_push.php) and the inbound ingest path
 * (api/atak-ingest.php).
 *
 * Public API:
 *   cot_encode_xml(entity, opts) -> string  CoT XML
 *   cot_decode_xml(xml)          -> ?array  entity dict (null on malformed)
 *   cot_type_for_entity(entity)  -> string  CoT type code
 *   cot_strip_pii(entity)        -> array   entity with PII redacted
 *
 * Entity dict shape (used both directions — see plan.md §3.1):
 *   kind         'incident' | 'responder' | 'facility' | 'marker' | 'chat'
 *   id           int (TicketsCAD primary key when applicable)
 *   uid          string (CoT uid)
 *   callsign     string
 *   lat          float
 *   lng          float
 *   altitude     ?float   meters above ellipsoid
 *   speed        ?float   m/s
 *   course       ?float   degrees 0-359.9
 *   accuracy     ?float   meters (CE — circular error)
 *   remarks      ?string  free text (subject to PII strip)
 *   color        ?string  '#RRGGBB' or '#AARRGGBB' (severity-coded)
 *   reported_at  string   ISO8601 UTC ('2026-06-24T12:00:00Z')
 *   pii_fields   ?array   list of keys to redact when strip_pii=true
 *   marker_subtype ?string  for inbound markers — 'u-d-c-c' (circle),
 *                            'b-m-p-w' (waypoint), etc.
 *   severity     ?string  'high'|'moderate'|'low' (drives type + color)
 *   facility_type ?string 'hospital'|'shelter'|'station'|'icp'|other
 *
 * CoT spec reference: MITRE Cursor-on-Target Message Reference,
 * the open part of which is summarized at https://www.tak.gov/ and
 * various community-maintained guides. The subset implemented here
 * covers what TicketsCAD dispatch + ATAK volunteer-ops actually use:
 * friendly ground unit (responder), friendly ground emergency
 * (incident), friendly ground installation (facility), waypoint /
 * circle markers (inbound), and chat events.
 */

declare(strict_types=1);

// ─── Public API ─────────────────────────────────────────────────

/**
 * Encode a TicketsCAD entity dict as CoT XML.
 *
 * @param array $entity  See module docblock for shape.
 * @param array $opts {
 *   strip_pii?: bool (default false — caller's decision per channel sensitive_flag)
 *   stale_seconds?: int (default 300 — how long the event stays "fresh")
 *   how?: string (default 'm-g' machine GPS for positions, 'h-g-i-g-o' for operator-entered)
 * }
 * @return string CoT XML (single <event> element with prolog).
 */
function cot_encode_xml(array $entity, array $opts = []): string
{
    $stripPii = !empty($opts['strip_pii']);
    $stale    = (int) ($opts['stale_seconds'] ?? 300);
    $how      = (string) ($opts['how'] ?? 'm-g');

    if ($stripPii) {
        $entity = cot_strip_pii($entity);
    }

    $type    = cot_type_for_entity($entity);
    $uid     = (string) ($entity['uid'] ?? '');
    $time    = _cot_iso8601($entity['reported_at'] ?? null);
    $start   = $time;
    $staleAt = _cot_iso8601_offset($time, $stale);

    $lat = (float) ($entity['lat'] ?? 0.0);
    $lng = (float) ($entity['lng'] ?? 0.0);
    $hae = isset($entity['altitude']) ? (float) $entity['altitude'] : 0.0;
    // ce (circular error) and le (linear error) — large default = unknown
    $ce = isset($entity['accuracy']) ? (float) $entity['accuracy'] : 9999999.0;
    $le = 9999999.0;

    // Build the XML with SimpleXMLElement to get proper escaping.
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><event/>');
    $xml->addAttribute('version', '2.0');
    $xml->addAttribute('uid', $uid);
    $xml->addAttribute('type', $type);
    $xml->addAttribute('time', $time);
    $xml->addAttribute('start', $start);
    $xml->addAttribute('stale', $staleAt);
    $xml->addAttribute('how', $how);

    $point = $xml->addChild('point');
    $point->addAttribute('lat', (string) $lat);
    $point->addAttribute('lon', (string) $lng);
    $point->addAttribute('hae', (string) $hae);
    $point->addAttribute('ce', (string) $ce);
    $point->addAttribute('le', (string) $le);

    $detail = $xml->addChild('detail');

    $callsign = trim((string) ($entity['callsign'] ?? ''));
    if ($callsign !== '') {
        $contact = $detail->addChild('contact');
        $contact->addAttribute('callsign', $callsign);
    }

    if (!empty($entity['course']) || !empty($entity['speed'])) {
        $track = $detail->addChild('track');
        if (isset($entity['course'])) $track->addAttribute('course', (string) (float) $entity['course']);
        if (isset($entity['speed']))  $track->addAttribute('speed',  (string) (float) $entity['speed']);
    }

    $remarks = trim((string) ($entity['remarks'] ?? ''));
    if ($remarks !== '') {
        // SimpleXML escapes the value; safe for arbitrary user text.
        // Cap at 4096 chars — anything longer is almost certainly bogus
        // and risks blowing the Meshtastic packet size when this is
        // later compressed to protobuf.
        if (strlen($remarks) > 4096) {
            $remarks = substr($remarks, 0, 4093) . '...';
        }
        $detail->addChild('remarks', htmlspecialchars($remarks, ENT_XML1));
    }

    $color = trim((string) ($entity['color'] ?? ''));
    if ($color !== '') {
        $argb = _cot_color_to_argb($color);
        if ($argb !== null) {
            $colorEl = $detail->addChild('color');
            $colorEl->addAttribute('argb', (string) $argb);
        }
    }

    return $xml->asXML();
}

/**
 * Decode a CoT XML event into an entity dict (or null if malformed).
 * Strict on lat/lng bounds and required fields. Returns the
 * marker_subtype for `a-u-*` and `b-m-p-w` so the router can apply
 * decision 3 (per-channel default + marker-type override).
 */
function cot_decode_xml(string $xml): ?array
{
    if (trim($xml) === '') return null;

    libxml_use_internal_errors(true);
    $el = @simplexml_load_string($xml);
    libxml_clear_errors();
    if ($el === false || strtolower($el->getName()) !== 'event') return null;

    $type = trim((string) ($el['type'] ?? ''));
    $uid  = trim((string) ($el['uid'] ?? ''));
    if ($type === '' || $uid === '') return null;

    // Reject whitespace anywhere in identifier fields. CoT uids in
    // practice are UUIDs or 'prefix-id' strings — never contain
    // spaces. Catches CR/LF injection attempts even after libxml's
    // attribute-value normalization (which silently turns \r\n into
    // a regular space, per XML spec). Defense in depth: the same
    // lesson as the Phase 90 email-header injection — anything that
    // gets stored or echoed downstream should be validated at the
    // parse boundary.
    if (preg_match('/\s/', $uid)) return null;

    $point = $el->point;
    if (!$point) return null;
    $lat = isset($point['lat']) ? (float) $point['lat'] : null;
    $lng = isset($point['lon']) ? (float) $point['lon'] : null;
    if ($lat === null || $lng === null) return null;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;

    $entity = [
        'uid'         => $uid,
        'lat'         => $lat,
        'lng'         => $lng,
        'reported_at' => _cot_iso8601($el['time'] ?? null),
    ];

    if (isset($point['hae'])) $entity['altitude'] = (float) $point['hae'];
    if (isset($point['ce']))  $entity['accuracy'] = (float) $point['ce'];

    $detail = $el->detail;
    if ($detail) {
        if (isset($detail->contact['callsign'])) {
            $entity['callsign'] = preg_replace('/[\r\n\0]/', ' ',
                trim((string) $detail->contact['callsign']));
        }
        if (isset($detail->track['course'])) $entity['course'] = (float) $detail->track['course'];
        if (isset($detail->track['speed']))  $entity['speed']  = (float) $detail->track['speed'];
        if (isset($detail->remarks)) {
            $r = (string) $detail->remarks;
            // Strip CR/LF and cap — these land in audit text downstream.
            $r = preg_replace('/[\r\n]+/', ' ', $r);
            if (strlen($r) > 4096) $r = substr($r, 0, 4093) . '...';
            $entity['remarks'] = $r;
        }
    }

    // Classify what kind of entity this is and infer the kind/subtype.
    if (strpos($type, 'b-t-f') === 0) {
        $entity['kind'] = 'chat';
    } elseif (strpos($type, 'u-d-c-c') === 0) {
        // Circle / area marker — decision 3 forces new geofenced incident.
        $entity['kind'] = 'marker';
        $entity['marker_subtype'] = 'u-d-c-c';
    } elseif (strpos($type, 'b-m-p-w') === 0) {
        // Waypoint marker — follow channel default.
        $entity['kind'] = 'marker';
        $entity['marker_subtype'] = 'b-m-p-w';
    } elseif (strpos($type, 'a-') === 0) {
        // Atom (friendly/hostile/unknown ground unit) — typically a
        // position update from a tracked ATAK user. The bridge layer
        // decides whether this is a responder (bound personnel) or
        // an unbound uid.
        $entity['kind'] = 'responder';
        $entity['cot_type'] = $type;
    } else {
        // Unknown CoT type — store but mark for operator review.
        $entity['kind'] = 'other';
        $entity['cot_type'] = $type;
    }

    return $entity;
}

/**
 * Map a TicketsCAD entity to its canonical CoT type code.
 * The set covered is the subset volunteer-ops actually use; expand
 * as users hit edge cases (don't preemptively map every 2525B symbol).
 */
function cot_type_for_entity(array $entity): string
{
    $kind     = (string) ($entity['kind'] ?? 'responder');
    $severity = (string) ($entity['severity'] ?? '');

    if ($kind === 'chat') return 'b-t-f';

    if ($kind === 'marker') {
        $sub = (string) ($entity['marker_subtype'] ?? 'b-m-p-w');
        return $sub;
    }

    if ($kind === 'incident') {
        if (in_array($severity, ['high', 'critical'], true)) {
            return 'a-f-G-E'; // friendly ground emergency activity
        }
        return 'a-f-G';
    }

    if ($kind === 'facility') {
        $ft = (string) ($entity['facility_type'] ?? '');
        switch ($ft) {
            case 'hospital': return 'a-f-G-I-U-T-Med';
            case 'icp':      return 'a-f-G-I-X';   // HQ
            case 'shelter':
            case 'station':
            default:         return 'a-f-G-I-U';   // generic installation
        }
    }

    // Default: responder. Differentiate EMS / fire / generic ground.
    $rt = (string) ($entity['responder_type'] ?? '');
    switch ($rt) {
        case 'ems':  return 'a-f-G-E-V-A-A-V'; // emergency vehicle, ambulance
        case 'fire': return 'a-f-G-E-V-F';     // emergency vehicle, fire
        default:     return 'a-f-G-U-C-I';     // friendly ground troops generic
    }
}

/**
 * Return a copy of $entity with PII fields redacted.
 * Called by encoders when the channel's sensitive_flag is on.
 *
 * Always-PII fields (regardless of pii_fields): 'remarks' is replaced
 * by '[redacted]'. The structure is preserved so consumers can still
 * see "an event happened here" without the operationally-sensitive
 * detail.
 *
 * Per-entity pii_fields list: if the caller marked specific keys as
 * sensitive (e.g. 'callsign' for an undercover operator), those are
 * replaced with '[redacted]' too. Other fields untouched.
 */
function cot_strip_pii(array $entity): array
{
    $out = $entity;
    if (!empty($out['remarks'])) {
        $out['remarks'] = '[redacted]';
    }
    $extra = $entity['pii_fields'] ?? [];
    if (is_array($extra)) {
        foreach ($extra as $k) {
            if (array_key_exists($k, $out)) $out[$k] = '[redacted]';
        }
    }
    return $out;
}

// ─── Internal helpers ───────────────────────────────────────────

function _cot_iso8601($v): string
{
    if ($v === null || $v === '') return gmdate('Y-m-d\TH:i:s\Z');
    if (is_string($v)) {
        $ts = strtotime($v);
        if ($ts !== false) return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
    if (is_int($v)) return gmdate('Y-m-d\TH:i:s\Z', $v);
    return gmdate('Y-m-d\TH:i:s\Z');
}

function _cot_iso8601_offset(string $iso, int $seconds): string
{
    $ts = strtotime($iso);
    if ($ts === false) $ts = time();
    return gmdate('Y-m-d\TH:i:s\Z', $ts + $seconds);
}

/**
 * Convert '#RRGGBB' or '#AARRGGBB' to the signed-32-bit ARGB integer
 * CoT expects (Java-style: alpha-prefixed, signed because top bit set
 * for full opacity means negative in two's complement). Returns null
 * on malformed input.
 */
function _cot_color_to_argb(string $color): ?int
{
    $c = ltrim($color, '#');
    if (!preg_match('/^[0-9A-Fa-f]+$/', $c)) return null;

    if (strlen($c) === 6) $c = 'FF' . $c; // assume full alpha
    if (strlen($c) !== 8) return null;

    $u = (int) hexdec($c);
    // PHP hexdec returns large unsigned ints on 64-bit; convert to
    // signed 32-bit so the value matches what Java/Android render.
    if ($u >= 0x80000000) $u -= 0x100000000;
    return $u;
}
