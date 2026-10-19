<?php
/**
 * GH#154 — read-time mapping of forms saved before the built-in ICS forms were
 * matched to the FEMA booklet (ics_form_normalize_data()).
 *
 * Each rule is driven through the real function, then through the real
 * printer (generatePrintHtml()), with the old-shape data a previous release
 * actually wrote. Rules checked for every mapping:
 *   - the new field gets the old value when it is empty;
 *   - a new field that already holds a value is NEVER overwritten;
 *   - the old key is left in place (nothing is deleted);
 *   - mapping twice changes nothing (idempotent).
 *
 * Pure PHP. tests/test_gh154_ics_forms_roundtrip.php checks the same mapping
 * through the real endpoint's GET ?id=.
 */
require_once __DIR__ . '/_gh154_helpers.php';

echo "=== GH#154 — mapping forms saved by an earlier version ===\n\n";

$print = function (string $type, array $data): string {
    return generatePrintHtml($type, $data, ['id' => 1, 'title' => 'Mapping ' . $type]);
};

// ── ICS 205: one Tone/NAC -> RX Tone/NAC and TX Tone/NAC ─────────────────
$old205 = ['incident_name' => 'Flood', 'radio_channels' => [
    ['zone_group' => 'Z1', 'channel' => '1', 'function' => 'Command', 'frequency_rx' => '155.1600',
     'frequency_tx' => '155.7600', 'tone' => '100.0', 'assignment' => 'IC'],
    ['channel' => '2', 'frequency_rx' => '453.2500', 'tone' => 'NAC 293', 'rx_tone' => '123.0', 'assignment' => 'Div A'],
]];
$n = ics_form_normalize_data('205', $old205);
gh154_t('205: an old row\'s Tone/NAC fills RX Tone/NAC', ($n['radio_channels'][0]['rx_tone'] ?? '') === '100.0');
gh154_t('205: ...and TX Tone/NAC', ($n['radio_channels'][0]['tx_tone'] ?? '') === '100.0');
gh154_t('205: the old tone key is kept', ($n['radio_channels'][0]['tone'] ?? '') === '100.0');
gh154_t('205: an RX Tone/NAC that is already set is not overwritten', ($n['radio_channels'][1]['rx_tone'] ?? '') === '123.0');
gh154_t('205: ...while its empty TX Tone/NAC still gets the old value', ($n['radio_channels'][1]['tx_tone'] ?? '') === 'NAC 293');
gh154_t('205: every other column keeps its data', ($n['radio_channels'][0]['frequency_tx'] ?? '') === '155.7600'
    && ($n['radio_channels'][0]['zone_group'] ?? '') === 'Z1');
gh154_t('205: mapping is idempotent', ics_form_normalize_data('205', $n) === $n);
$h = $print('205', $old205);
gh154_t('205: the printout shows the old tone in the RX and TX Tone/NAC columns', substr_count($h, '>100.0<') === 2);
gh154_t('205: a new row prints the frequency with its N or W ("155.1600 N"), as FEMA\'s "RX Freq N or W" column',
    strpos($print('205', ['radio_channels' => [['frequency_rx' => '155.1600', 'rx_nw' => 'N']]]), '155.1600 N') !== false);

// ── ICS 214: Team Name / Team Leader -> blocks 3 and 4 ───────────────────
// Read as FEMA's block instructions define them (booklet p.70): block 3 Name
// is the unit or resource designator, block 4 ICS Position is "the name and
// ICS position of the individual in charge of the Unit", and block 5 is the
// preparer's own home agency (nothing in the old shape is that).
$old214 = ['incident_name' => 'Flood', 'date_prepared' => '2026-08-16', 'team_name' => 'Engine 5',
    'team_leader_name' => 'J. Smith', 'team_leader_position' => 'Engine Boss',
    'activity_log' => [['time' => '10:00', 'activity' => 'Arrived on scene']]];
$n = ics_form_normalize_data('214', $old214);
gh154_t('214: Team Name/Number -> 3. Name (the unit or resource designator)', ($n['name'] ?? '') === 'Engine 5');
gh154_t('214: Team Leader name and position -> 4. ICS Position, joined "J. Smith, Engine Boss"',
    ($n['ics_position'] ?? '') === 'J. Smith, Engine Boss', (string) ($n['ics_position'] ?? ''));
gh154_t('214: 5. Home Agency (and Unit) stays empty (the old data has no preparer\'s agency)',
    !isset($n['home_agency']) || $n['home_agency'] === '');
gh154_t('214: the old keys are kept', ($n['team_name'] ?? '') === 'Engine 5' && ($n['team_leader_name'] ?? '') === 'J. Smith'
    && ($n['team_leader_position'] ?? '') === 'Engine Boss');
gh154_t('214: only the leader\'s name, when the position is blank',
    (ics_form_normalize_data('214', ['team_leader_name' => 'J. Smith', 'team_leader_position' => ''])['ics_position'] ?? '') === 'J. Smith');
gh154_t('214: only the leader\'s position, when the name is blank',
    (ics_form_normalize_data('214', ['team_leader_position' => 'Engine Boss'])['ics_position'] ?? '') === 'Engine Boss');
gh154_t('214: no leader at all leaves ICS Position empty',
    !isset(ics_form_normalize_data('214', ['team_name' => 'Engine 5'])['ics_position']));
gh154_t('214: an old log entry keeps its time and gains no invented date',
    ($n['activity_log'][0]['time'] ?? '') === '10:00' && !isset($n['activity_log'][0]['date']));
$n2 = ics_form_normalize_data('214', $old214 + ['name' => 'Facilities Unit', 'ics_position' => 'A. Lee, Unit Leader']);
gh154_t('214: a Name already filled in is never overwritten by the old Team Name', $n2['name'] === 'Facilities Unit');
gh154_t('214: an ICS Position already filled in is never overwritten by the old Team Leader', $n2['ics_position'] === 'A. Lee, Unit Leader');
gh154_t('214: mapping is idempotent', ics_form_normalize_data('214', $n) === $n);
$h = $print('214', $old214);
gh154_t('214: the printout shows the team as 3. Name and the leader as 4. ICS Position',
    (bool) preg_match('#3\. Name:</span><span class="value">Engine 5</span>#', $h)
    && (bool) preg_match('#4\. ICS Position:</span><span class="value">J\. Smith, Engine Boss</span>#', $h));
gh154_t('214: ...and 5. Home Agency (and Unit) prints empty',
    (bool) preg_match('#5\. Home Agency \(and Unit\):</span><span class="value"></span>#', $h));
gh154_t('214: an old time-only log entry prints its time', strpos($h, '10:00') !== false && strpos($h, 'Arrived on scene') !== false);
gh154_t('214: a dated entry prints as one FEMA "Date/Time" cell (month/day/year then the 24-hour time)',
    strpos($print('214', ['activity_log' => [['date' => '2026-10-19', 'time' => '23:59', 'activity' => 'x']]]), '10/19/2026 23:59') !== false);
gh154_t('214: the old Date Prepared prints in block 8', strpos($h, '08/16/2026') !== false);

// ── ICS 205A: Phone / Radio / Email -> Method(s) of Contact ──────────────
$old205a = ['contacts' => [
    ['position' => 'IC', 'name' => 'Chief A', 'phone' => '555-1234', 'radio' => 'Ch 1', 'email' => 'a@example.invalid'],
    ['position' => 'PIO', 'name' => 'B', 'radio' => 'Ch 3'],
    ['position' => 'SO', 'name' => 'C', 'phone' => '555-9', 'contact' => 'cell 555-0000'],
]];
$n = ics_form_normalize_data('205a', $old205a);
gh154_t('205A: phone, radio and email are combined into Method(s) of Contact, labelled',
    ($n['contacts'][0]['contact'] ?? '') === 'Phone 555-1234; Radio Ch 1; Email a@example.invalid', $n['contacts'][0]['contact'] ?? '');
gh154_t('205A: a row with only a radio gets just that', ($n['contacts'][1]['contact'] ?? '') === 'Radio Ch 3');
gh154_t('205A: a Method(s) of Contact already filled in is not overwritten', ($n['contacts'][2]['contact'] ?? '') === 'cell 555-0000');
gh154_t('205A: the old columns are kept', ($n['contacts'][0]['phone'] ?? '') === '555-1234');
gh154_t('205A: mapping is idempotent', ics_form_normalize_data('205a', $n) === $n);
gh154_t('205A: the printout shows the combined contact', strpos($print('205a', $old205a), 'Phone 555-1234; Radio Ch 1; Email a@example.invalid') !== false);

// ── ICS 206: Y/N -> Yes/No; Paramedics -> Level of Service; Travel Time ──
$old206 = [
    'aid_stations'   => [['name' => 'Aid 1', 'paramedics' => 'Y'], ['name' => 'Aid 2', 'paramedics' => 'n'], ['name' => 'Aid 3', 'paramedics' => 'Maybe']],
    'transportation' => [['service' => 'Medic 1', 'paramedics' => 'Y'], ['service' => 'Rescue 2', 'paramedics' => 'N'],
                         ['service' => 'Air 3', 'paramedics' => 'flight nurse'], ['service' => 'X', 'paramedics' => 'Y', 'level_of_service' => 'BLS']],
    'hospitals'      => [['name' => 'General', 'travel_time' => '12 min', 'helipad' => 'y'],
                         ['name' => 'Mercy', 'travel_time' => '20 min', 'travel_ground' => '25 min']],
];
$n = ics_form_normalize_data('206', $old206);
gh154_t('206: an aid station\'s Y/N becomes Yes/No', $n['aid_stations'][0]['paramedics'] === 'Yes' && $n['aid_stations'][1]['paramedics'] === 'No');
gh154_t('206: an unrecognised value is kept as it was (the editor offers it as its own choice)', $n['aid_stations'][2]['paramedics'] === 'Maybe');
gh154_t('206: an ambulance with paramedics (Y) is Level of Service ALS; N is BLS',
    $n['transportation'][0]['level_of_service'] === 'ALS' && $n['transportation'][1]['level_of_service'] === 'BLS');
gh154_t('206: ...other text is carried over as written', $n['transportation'][2]['level_of_service'] === 'flight nurse');
gh154_t('206: ...and a Level of Service already set is not overwritten', $n['transportation'][3]['level_of_service'] === 'BLS');
gh154_t('206: a hospital\'s one Travel Time is shown under Ground', $n['hospitals'][0]['travel_ground'] === '12 min');
gh154_t('206: ...unless Ground is already filled in', $n['hospitals'][1]['travel_ground'] === '25 min');
gh154_t('206: a hospital\'s helipad Y becomes Yes', $n['hospitals'][0]['helipad'] === 'Yes');
gh154_t('206: mapping is idempotent', ics_form_normalize_data('206', $n) === $n);
$h = $print('206', $old206);
gh154_t('206: the printout shows ALS/BLS, Yes/No and the travel time',
    strpos($h, '>ALS<') !== false && strpos($h, '>BLS<') !== false && strpos($h, '12 min') !== false && strpos($h, 'Maybe') !== false);

// ── ICS 221: check-out boxes -> block 6; Room Reservations -> Room Overnight
$old221 = ['incident_name' => 'Flood', 'resource_unit_id' => 'E-12', 'leader_name' => 'C. Diaz',
    'chk_logistics' => 'returned radios', 'chk_supply' => 'Y', 'chk_communications' => 'radio #44 returned',
    'chk_facilities' => 'n', 'chk_ground_support' => '', 'travel_room' => 'yes', 'reassign_destination' => 'Duluth, MN',
    'reassign_eta' => '1600', 'reassign_area' => 'NE Region', 'approved_planning' => 'P. Chief'];
$n = ics_form_normalize_data('221', $old221);
$byUnit = [];
foreach ($n['checkout'] ?? [] as $r) $byUnit[$r['unit']] = $r;
gh154_t('221: the old check-out boxes become FEMA\'s block 6 rows', count($n['checkout'] ?? []) === 9);
gh154_t('221: "Y" -> checked, no remarks', ($byUnit['Supply Unit']['check'] ?? '') === '1' && ($byUnit['Supply Unit']['remarks'] ?? 'x') === '');
gh154_t('221: free text -> checked, text kept as the remarks', ($byUnit['Communications Unit']['check'] ?? '') === '1'
    && ($byUnit['Communications Unit']['remarks'] ?? '') === 'radio #44 returned');
gh154_t('221: "n" -> not checked', ($byUnit['Facilities Unit']['check'] ?? 'x') === '');
gh154_t('221: the whole-Logistics-Section box gets its own row', ($byUnit['Logistics Section (whole section)']['remarks'] ?? '') === 'returned radios');
gh154_t('221: FEMA\'s other rows (Time Unit, Documentation Leader...) are present, unchecked',
    isset($byUnit['Time Unit'], $byUnit['Documentation Leader'], $byUnit['Demobilization Leader']) && $byUnit['Time Unit']['check'] === '');
gh154_t('221: Room Reservations "yes" -> Room Overnight Yes', ($n['travel_room'] ?? '') === 'Yes');
gh154_t('221: a check-out list already saved in the new shape is left alone',
    ics_form_normalize_data('221', ['chk_supply' => 'Y', 'checkout' => [['unit' => 'Custom', 'check' => '']]])['checkout'] === [['unit' => 'Custom', 'check' => '']]);
gh154_t('221: mapping is idempotent', ics_form_normalize_data('221', $n) === $n);
$h = $print('221', $old221);
gh154_t('221: the printout shows the check-out rows with their remarks and checks',
    strpos($h, 'radio #44 returned') !== false && strpos($h, '&#9745;') !== false && strpos($h, 'Documentation Leader') !== false);
gh154_t('221: the reassignment/travel values print in blocks 8 and 9', strpos($h, 'Duluth, MN') !== false
    && strpos($h, '1600') !== false && strpos($h, 'NE Region') !== false);
gh154_t('221: Leader Name and the old Approved By fields print under "From an earlier version of this form"',
    strpos($h, 'From an earlier version of this form') !== false && strpos($h, 'C. Diaz') !== false && strpos($h, 'P. Chief') !== false);
gh154_t('221: a NEW form prints no earlier-version section', strpos($print('221', ['incident_name' => 'x']), 'earlier version') === false);

// ── ICS 213 RR / 202: fields with no FEMA home stay visible ──────────────
$h = $print('213rr', ['to_name' => 'Logistics Chief', 'from_name' => 'Div A', 'approved_by' => 'L. Rep',
    'resource_requests' => [['qty' => '2', 'kind' => 'Engine', 'priority' => 'High']]]);
gh154_t('213 RR: old To/From print under the earlier-version section', strpos($h, 'Logistics Chief') !== false && strpos($h, 'Div A') !== false);
gh154_t('213 RR: an old per-row Priority prints in its own earlier-version column', strpos($h, 'Priority (earlier version)') !== false && strpos($h, '>High<') !== false);
gh154_t('213 RR: the old Approved By (Logistics) prints as block 14', strpos($h, '14. Approval Signature of Auth Logistics Rep') !== false && strpos($h, 'L. Rep') !== false);
gh154_t('213 RR: a new form shows no earlier-version Priority column', strpos($print('213rr', ['resource_requests' => [['qty' => '1']]]), 'Priority (earlier version)') === false);
$h = $print('202', ['objectives' => 'Protect life', 'current_actions' => 'Sandbagging on Elm St']);
gh154_t('202: the old Summary of Current Actions prints under the earlier-version section', strpos($h, 'Sandbagging on Elm St') !== false
    && strpos($h, 'From an earlier version of this form') !== false);

// ── Unknown types and junk are harmless ───────────────────────────────────
gh154_t('a type with no mapping passes data through unchanged', ics_form_normalize_data('213', ['to_name' => 'A']) === ['to_name' => 'A']);
gh154_t('non-array data becomes an empty array, never an error', ics_form_normalize_data('205', null) === []
    && ics_form_normalize_data('214', 'garbage') === []);
gh154_t('a malformed table row is left alone', ics_form_normalize_data('205', ['radio_channels' => ['x', null]])['radio_channels'] === ['x', null]);

gh154_done();
