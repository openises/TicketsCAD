<?php
/**
 * Phase 141 (2026-08-17) — Redaction allowlist, exhaustive.
 *
 * Proves org_share_redact_ticket_fields() / org_share_redact_assignment_
 * fields() are genuinely an ALLOWLIST, not a blocklist wearing an
 * allowlist's docblock: a brand-new field this test invents (one that
 * could never have been deliberately added to the allowlist, because it
 * did not exist when the allowlist was written) must NOT survive view-
 * tier redaction. This is the exact assertion tasks.md section 6 and
 * this project's own root-cause-troubleshooting discipline both call
 * for — an allowlist that quietly behaves like a blocklist is invisible
 * until the day a sensitive column is added and nobody notices it leaked.
 *
 * Also drives the FULL field-by-field table from plan.md's redaction
 * section (not "mostly matches" — every named field asserted
 * individually), the incident-detail.php-shaped assignment-row
 * redaction (the "never leak a roster" boundary — crew/distance_km/
 * responder_updated), and the composition-not-override relationship
 * with the independent security-label system.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_redaction.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — Redaction allowlist, exhaustive ===\n\n";

// ══════════════════════════════════════════════════════════════════════
// The full plan.md field table, field by field
// ══════════════════════════════════════════════════════════════════════
echo "--- plan.md's redaction allowlist table, field by field ---\n\n";

$fullTicketRow = [
    'id' => 1, 'incident_number' => '26-0100',
    'in_types_id' => 5, 'type_name' => 'Structure Fire', 'type_group' => 'Fire',
    'type_color' => '#ff0000', 'type_icon' => 'bi-fire',
    'org_id' => 10, 'org' => 'Owning Org', 'org_name' => 'Owning Org',
    'street' => '1 Main St', 'city' => 'Testville', 'state' => 'MN',
    'address_about' => 'near the water tower', 'lat' => 44.8, 'lng' => -93.3,
    'severity' => 2, 'status' => 2, 'booked_date' => null,
    'date' => '2026-08-17 12:00:00', 'problemstart' => '2026-08-17 12:05:00',
    'problemend' => null, 'updated' => '2026-08-17 12:10:00',
    'scope' => 'working structure fire',
    'facility' => 3, 'facility_name' => 'Station 3',
    'rec_facility' => 7, 'rec_facility_name' => 'Regional Hospital',
    'assignments' => [], 'active_responders' => 2,
    // Excluded — caller PII
    'contact' => 'Jane Caller', 'phone' => '555-1212', 'nine_one_one' => 'callback ok',
    // Excluded — free-text narrative
    'description' => 'the whole story', 'comments' => 'more notes',
    'affected' => 'notes on affected persons', 'to_address' => 'destination narrative',
];

$view = org_share_redact_ticket_fields($fullTicketRow, 'view');

$allowedKeys = ['id', 'incident_number', 'in_types_id', 'type_name', 'type_group', 'type_color', 'type_icon',
    'org_id', 'org', 'org_name', 'street', 'city', 'state', 'address_about', 'lat', 'lng',
    'severity', 'status', 'booked_date', 'date', 'problemstart', 'problemend', 'updated', 'scope',
    'facility', 'facility_name', 'rec_facility', 'rec_facility_name', 'assignments', 'active_responders'];
foreach ($allowedKeys as $k) {
    t("view tier INCLUDES '$k' (plan.md allowlist)", array_key_exists($k, $view));
}

$excludedKeys = ['contact', 'phone', 'nine_one_one', 'description', 'comments', 'affected', 'to_address'];
foreach ($excludedKeys as $k) {
    t("view tier EXCLUDES '$k' (plan.md exclusion table)", !array_key_exists($k, $view));
}

t("assist tier returns the row completely unchanged (byte-identical)", org_share_redact_ticket_fields($fullTicketRow, 'assist') === $fullTicketRow);

// ══════════════════════════════════════════════════════════════════════
// THE core proof: a brand-new, never-seen field defaults to EXCLUDED
// ══════════════════════════════════════════════════════════════════════
echo "\n--- allowlist-not-blocklist proof (future column safety) ---\n\n";

$futureRow = $fullTicketRow;
$futureRow['ssn_last_four'] = '1234';                 // invented, never-allowlisted field
$futureRow['medical_history_notes'] = 'diabetic';      // invented, never-allowlisted field
$futureRow['some_brand_new_column_2027'] = 'anything'; // invented, never-allowlisted field
$futureView = org_share_redact_ticket_fields($futureRow, 'view');
t("a field NOT on the allowlist ('ssn_last_four') is EXCLUDED by default, with zero code change needed", !array_key_exists('ssn_last_four', $futureView));
t("a field NOT on the allowlist ('medical_history_notes') is EXCLUDED by default", !array_key_exists('medical_history_notes', $futureView));
t("a field NOT on the allowlist ('some_brand_new_column_2027') is EXCLUDED by default", !array_key_exists('some_brand_new_column_2027', $futureView));
t("none of the invented values leak anywhere in the redacted payload", !in_array('1234', $futureView, true) && !in_array('diabetic', $futureView, true) && !in_array('anything', $futureView, true));

// Contrast case: prove this ISN'T just an accident of the specific field
// names chosen above — generate 50 random never-seen key names and
// confirm every single one is excluded.
$allExcluded = true;
for ($i = 0; $i < 50; $i++) {
    $randKey = 'zz141_random_field_' . bin2hex(random_bytes(6));
    $probe = [$randKey => 'sensitive-value-' . $i];
    $probeView = org_share_redact_ticket_fields($probe, 'view');
    if (array_key_exists($randKey, $probeView)) { $allExcluded = false; break; }
}
t("50 randomly-generated never-seen field names are ALL excluded at view tier (allowlist, not blocklist)", $allExcluded);

// ══════════════════════════════════════════════════════════════════════
// Endpoint-specific extended aliases (added during endpoint integration)
// ══════════════════════════════════════════════════════════════════════
echo "\n--- extended allowlist aliases (endpoint-integration additions) ---\n\n";

$aliasRow = [
    'id' => 1, 'created' => '2026-08-17', 'incident_type' => 'Fire', 'type_id' => 5,
    'in_type_name' => 'Fire', 'radius' => 200, 'severity_color' => '#ff0000', 'status_text' => 'Open',
    'scope_display' => 'working fire', 'address_display' => '1 Main St',
    'security' => ['label_name' => 'Public'], 'units_assigned' => 2, 'unit_names' => 'Engine 3, Medic 12',
    // Deliberately-still-excluded fields that live NEAR these aliases in
    // real endpoint responses — must NOT ride along with the alias additions.
    'actions_count' => 4, 'patients_count' => 1, 'facility_lat' => 44.8, 'facility_lng' => -93.3,
    'par_due_at' => '2026-08-17T12:30:00Z', 'par_overdue_secs' => 0,
    'disposition_id' => 3, 'disposition_label' => 'Transported', 'protocol' => 'call the protocol text here',
];
$aliasView = org_share_redact_ticket_fields($aliasRow, 'view');
foreach (['created', 'incident_type', 'type_id', 'in_type_name', 'radius', 'severity_color', 'status_text',
          'scope_display', 'address_display', 'security', 'units_assigned', 'unit_names'] as $k) {
    t("view tier includes extended alias '$k'", array_key_exists($k, $aliasView));
}
foreach (['actions_count', 'patients_count', 'facility_lat', 'facility_lng', 'par_due_at', 'par_overdue_secs',
          'disposition_id', 'disposition_label', 'protocol'] as $k) {
    t("view tier still excludes '$k' (not on the allowlist, deliberately not added)", !array_key_exists($k, $aliasView));
}

// ══════════════════════════════════════════════════════════════════════
// Per-assignment (per-unit) redaction — the "never leak a roster" boundary
// ══════════════════════════════════════════════════════════════════════
echo "\n--- org_share_redact_assignment_fields() — roster-isolation boundary ---\n\n";

$assignmentRow = [
    'id' => 42, 'responder_id' => 7, 'responder_name' => 'Engine 3', 'responder_handle' => 'E3',
    'status_id' => 2, 'responder_un_status_id' => 2, 'status_name' => 'On Scene',
    'bg_color' => '#ff0000', 'text_color' => '#ffffff',
    'dispatched' => '2026-08-17 12:00:00', 'responding' => '2026-08-17 12:02:00',
    'on_scene' => '2026-08-17 12:10:00', 'clear' => null, 'cleared' => false,
    'rec_facility_id' => 7, 'rec_facility_name' => 'Regional Hospital',
    // Roster / narrative fields that must NOT survive view tier:
    'comments' => 'crew found extension to floor 2',
    'u2fenr' => '12:05', 'u2farr' => '12:08',
    'distance_km' => 3.2, 'responder_updated' => '2026-08-17 12:09:00',
    'crew' => [['member_id' => 99, 'name' => 'John Smith', 'callsign' => 'N0ABC', 'role' => 'driver']],
    'crew_count' => 1,
];
$assignView = org_share_redact_assignment_fields($assignmentRow, 'view');
foreach (['id', 'responder_id', 'responder_name', 'responder_handle', 'status_id',
          'responder_un_status_id', 'status_name', 'bg_color', 'text_color',
          'dispatched', 'responding', 'on_scene', 'clear', 'cleared',
          'rec_facility_id', 'rec_facility_name'] as $k) {
    t("assignment view tier includes '$k' (unit identity + status timeline)", array_key_exists($k, $assignView));
}
t("assignment view tier EXCLUDES 'crew' (individual PERSONNEL — the roster-isolation boundary)", !array_key_exists('crew', $assignView));
t("assignment view tier EXCLUDES 'crew_count'", !array_key_exists('crew_count', $assignView));
t("assignment view tier EXCLUDES 'comments' (narrative)", !array_key_exists('comments', $assignView));
t("assignment view tier EXCLUDES 'distance_km' (derived from the responding org's OWN responder.lat/lng)", !array_key_exists('distance_km', $assignView));
t("assignment view tier EXCLUDES 'responder_updated' (derived from the responding org's OWN roster row)", !array_key_exists('responder_updated', $assignView));
t("no crew member name leaks anywhere in the redacted assignment payload", !in_array('John Smith', $assignView, true));

$assignAssist = org_share_redact_assignment_fields($assignmentRow, 'assist');
t("assignment assist tier is byte-identical to the input (crew included, same as a same-org dispatcher)", $assignAssist === $assignmentRow);

// ══════════════════════════════════════════════════════════════════════
// Composition with the independent security-label system
// ══════════════════════════════════════════════════════════════════════
echo "\n--- composes with security-label redaction, neither widens the other ---\n\n";

// The security-label system pre-masks scope/street display strings BEFORE
// org-sharing redaction ever runs (it's a separate, unmodified system) —
// org-sharing redaction only narrows the FIELD SET, never re-widens a
// value the label system already masked.
$labelMaskedRow = $fullTicketRow;
$labelMaskedRow['scope']  = '*** Restricted Incident ***'; // security label already masked this
$labelMaskedRow['street'] = '*** Restricted Incident ***';
$labelMaskedView = org_share_redact_ticket_fields($labelMaskedRow, 'view');
t("org-sharing redaction passes an already-label-masked 'scope' through UNCHANGED (does not re-widen it)", $labelMaskedView['scope'] === '*** Restricted Incident ***');
t("org-sharing redaction passes an already-label-masked 'street' through UNCHANGED", $labelMaskedView['street'] === '*** Restricted Incident ***');
t("org-sharing redaction still strips 'contact' even on a security-labeled ticket (both systems apply independently)", !array_key_exists('contact', $labelMaskedView));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
