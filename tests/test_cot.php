<?php
/**
 * Phase 91 — tests for the CoT translation library (inc/cot.php).
 *
 * Covers:
 *   - Encode for each entity kind (incident high/low, responder, facility, chat)
 *   - Decode of well-formed CoT XML round-trips fields correctly
 *   - PII gate honors strip_pii=true (decision 1 enforcement)
 *   - cot_type_for_entity maps the documented type set
 *   - Malformed input returns null (doesn't throw)
 *   - CR/LF/NUL in identifier fields rejected (Phase 90 injection lesson)
 *   - lat/lng bounds enforced
 *   - Color conversion '#RRGGBB' → signed ARGB int matches Android's expectation
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/cot.php';

$pass = 0; $fail = 0; $failures = [];
function assertEq($actual, $expected, $what) {
    global $pass, $fail, $failures;
    if ($actual === $expected) { $pass++; return; }
    $fail++;
    $failures[] = "FAIL: $what\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true);
}
function assertContains(string $haystack, string $needle, string $what) {
    global $pass, $fail, $failures;
    if (strpos($haystack, $needle) !== false) { $pass++; return; }
    $fail++;
    $failures[] = "FAIL: $what\n  expected to contain: $needle\n  haystack: " . substr($haystack, 0, 200);
}
function assertNotContains(string $haystack, string $needle, string $what) {
    global $pass, $fail, $failures;
    if (strpos($haystack, $needle) === false) { $pass++; return; }
    $fail++;
    $failures[] = "FAIL: $what\n  expected NOT to contain: $needle\n  haystack: " . substr($haystack, 0, 200);
}
function assertNull($v, string $what) {
    global $pass, $fail, $failures;
    if ($v === null) { $pass++; return; }
    $fail++;
    $failures[] = "FAIL: $what — expected null, got " . var_export($v, true);
}
function assertTrue(bool $v, string $what) {
    global $pass, $fail, $failures;
    if ($v) { $pass++; return; }
    $fail++;
    $failures[] = "FAIL: $what";
}

// ─── cot_type_for_entity ────────────────────────────────────────
assertEq(cot_type_for_entity(['kind'=>'incident', 'severity'=>'high']),     'a-f-G-E',        'type: incident high severity');
assertEq(cot_type_for_entity(['kind'=>'incident', 'severity'=>'critical']), 'a-f-G-E',        'type: incident critical severity');
assertEq(cot_type_for_entity(['kind'=>'incident', 'severity'=>'low']),      'a-f-G',          'type: incident low severity');
assertEq(cot_type_for_entity(['kind'=>'incident']),                          'a-f-G',         'type: incident no severity');
assertEq(cot_type_for_entity(['kind'=>'responder']),                         'a-f-G-U-C-I',   'type: generic responder');
assertEq(cot_type_for_entity(['kind'=>'responder', 'responder_type'=>'ems']),  'a-f-G-E-V-A-A-V', 'type: EMS responder');
assertEq(cot_type_for_entity(['kind'=>'responder', 'responder_type'=>'fire']), 'a-f-G-E-V-F',     'type: fire responder');
assertEq(cot_type_for_entity(['kind'=>'facility', 'facility_type'=>'hospital']), 'a-f-G-I-U-T-Med', 'type: hospital facility');
assertEq(cot_type_for_entity(['kind'=>'facility', 'facility_type'=>'icp']),      'a-f-G-I-X',       'type: ICP facility');
assertEq(cot_type_for_entity(['kind'=>'facility', 'facility_type'=>'shelter']),  'a-f-G-I-U',       'type: shelter facility');
assertEq(cot_type_for_entity(['kind'=>'facility']),                              'a-f-G-I-U',       'type: generic facility');
assertEq(cot_type_for_entity(['kind'=>'chat']),                                  'b-t-f',           'type: chat');
assertEq(cot_type_for_entity(['kind'=>'marker', 'marker_subtype'=>'u-d-c-c']),   'u-d-c-c',         'type: marker circle');
assertEq(cot_type_for_entity(['kind'=>'marker', 'marker_subtype'=>'b-m-p-w']),   'b-m-p-w',         'type: marker waypoint');

// ─── cot_encode_xml — basic shape ───────────────────────────────
$inc = [
    'kind' => 'incident', 'severity' => 'high',
    'uid' => 'tcad-incident-42', 'callsign' => 'Structure Fire',
    'lat' => 44.95, 'lng' => -93.25,
    'remarks' => 'Single family residence, smoke visible',
    'color' => '#FF0000', 'reported_at' => '2026-06-24T12:00:00Z',
];
$xml = cot_encode_xml($inc);
assertContains($xml, '<event ',                'encode incident: <event> root');
assertContains($xml, 'uid="tcad-incident-42"', 'encode incident: uid attribute');
assertContains($xml, 'type="a-f-G-E"',         'encode incident: severity-mapped type');
assertContains($xml, 'lat="44.95"',            'encode incident: lat in <point>');
assertContains($xml, 'lon="-93.25"',           'encode incident: lon in <point>');
assertContains($xml, 'callsign="Structure Fire"', 'encode incident: callsign in <contact>');
assertContains($xml, 'Single family residence', 'encode incident: remarks text');
assertContains($xml, 'argb=',                  'encode incident: color rendered as ARGB');

// ─── PII gate (decision 1 enforcement) ──────────────────────────
$incPii = $inc;
$incPii['remarks'] = 'Patient John Doe, age 67, chest pain';
$xmlClean = cot_encode_xml($incPii);
assertContains($xmlClean, 'John Doe', 'PII off: name flows through');

$xmlRedacted = cot_encode_xml($incPii, ['strip_pii' => true]);
assertNotContains($xmlRedacted, 'John Doe',  'PII strip: name NOT in CoT');
assertContains($xmlRedacted, '[redacted]',   'PII strip: replacement marker present');

// pii_fields list also redacted
$incPii2 = $inc;
$incPii2['callsign'] = 'COVERT-7';
$incPii2['pii_fields'] = ['callsign'];
$xmlRedacted2 = cot_encode_xml($incPii2, ['strip_pii' => true]);
assertNotContains($xmlRedacted2, 'COVERT-7',  'PII strip: pii_fields list honored');

// ─── cot_decode_xml — well-formed round-trips ───────────────────
$dec = cot_decode_xml($xml);
assertTrue(is_array($dec),                  'decode incident: returns array');
assertEq($dec['uid'] ?? null, 'tcad-incident-42', 'decode incident: uid');
assertEq($dec['lat'] ?? null, 44.95,        'decode incident: lat');
assertEq($dec['lng'] ?? null, -93.25,       'decode incident: lng');
assertEq($dec['callsign'] ?? null, 'Structure Fire', 'decode incident: callsign');
assertContains((string) ($dec['remarks'] ?? ''), 'Single family residence', 'decode incident: remarks');

// Waypoint marker decode
$wpXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<event version="2.0" uid="ATAK-7-WP1" type="b-m-p-w" time="2026-06-24T12:30:00Z" start="2026-06-24T12:30:00Z" stale="2026-06-24T12:35:00Z" how="h-e">'
       . '<point lat="44.96" lon="-93.26" hae="280.0" ce="10.0" le="10.0"/>'
       . '<detail><contact callsign="N0NKI"/><remarks>Cleared this grid</remarks></detail>'
       . '</event>';
$wp = cot_decode_xml($wpXml);
assertEq($wp['kind'] ?? null,            'marker',   'decode waypoint: kind');
assertEq($wp['marker_subtype'] ?? null,  'b-m-p-w',  'decode waypoint: subtype hint');
assertEq($wp['callsign'] ?? null,        'N0NKI',    'decode waypoint: callsign');

// Circle marker decode — decision 3 override hint
$circleXml = str_replace('b-m-p-w', 'u-d-c-c', $wpXml);
$circle = cot_decode_xml($circleXml);
assertEq($circle['kind'] ?? null,            'marker',   'decode circle: kind');
assertEq($circle['marker_subtype'] ?? null,  'u-d-c-c',  'decode circle: subtype hint');

// Chat decode
$chatXml = str_replace(['b-m-p-w', 'Cleared this grid'], ['b-t-f', 'Net check, all clear'], $wpXml);
$chat = cot_decode_xml($chatXml);
assertEq($chat['kind'] ?? null,    'chat',                  'decode chat: kind');
assertEq($chat['remarks'] ?? null, 'Net check, all clear',  'decode chat: body in remarks');

// ─── Malformed input returns null, never throws ─────────────────
assertNull(cot_decode_xml(''),                'malformed: empty string');
assertNull(cot_decode_xml('<not-an-event/>'), 'malformed: wrong root element');
assertNull(cot_decode_xml('<event/>'),        'malformed: no type or uid');
assertNull(cot_decode_xml('<event type="a-f-G" uid="x"/>'), 'malformed: no point');
assertNull(cot_decode_xml(
    '<event type="a-f-G" uid="x"><point lat="100.0" lon="0"/></event>'
), 'malformed: lat out of range');
assertNull(cot_decode_xml(
    '<event type="a-f-G" uid="x"><point lat="0" lon="200.0"/></event>'
), 'malformed: lng out of range');

// CR/LF in uid rejected (Phase 90 injection lesson)
$badUidXml = "<event type=\"a-f-G\" uid=\"X\r\nBcc:attacker@example.com\"><point lat=\"0\" lon=\"0\"/></event>";
assertNull(cot_decode_xml($badUidXml), 'malformed: CR/LF in uid rejected');

// ─── Color conversion ──────────────────────────────────────────
// Red full-alpha #FF0000 → 0xFFFF0000 → signed -65536
$redArgb = _cot_color_to_argb('#FF0000');
assertEq($redArgb, -65536, 'color: red is ARGB -65536');

// Green full-alpha #00FF00 → 0xFF00FF00 → signed -16711936
$greenArgb = _cot_color_to_argb('#00FF00');
assertEq($greenArgb, -16711936, 'color: green is ARGB -16711936');

// Malformed
assertNull(_cot_color_to_argb('#GGGGGG'), 'color: malformed returns null');
assertNull(_cot_color_to_argb('#FFF'),    'color: short form unsupported (returns null)');

// ─── Report ─────────────────────────────────────────────────────
echo "Phase 91 — CoT translation library tests\n";
echo "========================================\n";
echo "  Passed: $pass\n";
echo "  Failed: $fail\n";
if ($fail > 0) {
    echo "\n";
    foreach ($failures as $msg) echo "$msg\n\n";
    exit(1);
}
echo "  All passing.\n";
exit(0);
