<?php
/**
 * Phase 138 — Public incident board: pb_build_public_record() redaction.
 *
 * This is the spec.md success-criteria test verbatim (plan.md §11): fabricate
 * a full-detail incident with real name-shaped narrative text, an exact
 * street address, and a phone-shaped string, run it through
 * pb_build_public_record() at each precision level, and assert the withheld
 * strings do not appear as a substring ANYWHERE in the serialized output —
 * not "the key is absent," a full-string search of the JSON.
 *
 * No DB access — pb_build_public_record() is a pure transform over an
 * already-fetched row + already-resolved label (plan.md §3), so every case
 * here is a fabricated array, never a live query.
 *
 * Usage: php tests/test_public_board_redaction.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/public-board.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — pb_build_public_record() redaction ===\n\n";

// A "full" label — no redaction of its own, board precision is the only ceiling.
$fullLabel = [
    'eoc_show_address'     => 1,
    'eoc_show_map_marker'  => 'full',
    'eoc_placeholder_text' => null,
];

// The withheld strings a real dispatcher record would carry — narrative
// text with a name, an exact street number, and a phone-shaped string.
// None of these should EVER reach pb_build_public_record()'s output,
// because the function is never even handed them (they aren't part of
// $ticketRow's expected keys) — but the point of this test is to prove
// it, not assume it.
$withheldNarrative = 'Caller Jane Q. Public reports her ex-husband';
$withheldPhone      = '612-555-0199';
$fullDetailRow = [
    'id'             => 4821,
    'type_name'      => 'Structure Fire',
    'type_group'     => 'Fire',
    'severity'       => 2,
    'opened'         => '2026-08-13 13:41:02',
    'updated'        => '2026-08-13 13:58:19',
    'assigned_units' => 3,
    'street'         => '4821 Main St',
    'city'           => 'your deployment',
    'state'          => 'MN',
    'lat'            => 44.842123,
    'lng'            => -93.298456,
    'public_board_visibility'  => 'full',
    'public_board_stub_label'  => null,
    // Fields that MUST NEVER be read by pb_build_public_record() at all —
    // included here to prove they don't leak even though they're present
    // on the row (a real eligibility-query row will carry description/
    // scope/comments/phone/contact columns too).
    'description'    => $withheldNarrative,
    'scope'          => $withheldNarrative,
    'comments'       => $withheldNarrative,
    'phone'          => $withheldPhone,
    'contact'        => 'Jane Q. Public',
    'nine_one_one'   => $withheldPhone,
];

foreach (['exact', 'block', 'city', 'hidden'] as $level) {
    $rec = pb_build_public_record($fullDetailRow, $fullLabel, $level, true);
    $json = json_encode($rec);
    t("[$level] withheld narrative text is NOT a substring of the output",
        strpos($json, $withheldNarrative) === false);
    t("[$level] withheld phone-shaped string is NOT a substring of the output",
        strpos($json, $withheldPhone) === false);
    t("[$level] withheld contact name is NOT a substring of the output",
        strpos($json, 'Jane Q. Public') === false);
}

// ── Coordinate/address precision table drives real output differences ──
$exactRec = pb_build_public_record($fullDetailRow, $fullLabel, 'exact', true);
t('exact: full street address shown', $exactRec['street_display'] === '4821 Main St');
t('exact: lat/lng present and unrounded', $exactRec['lat'] === 44.842123 && $exactRec['lng'] === -93.298456);

$blockRec = pb_build_public_record($fullDetailRow, $fullLabel, 'block', true);
t('block: street name only, no house number', $blockRec['street_display'] === 'Main St');
t('block: lat/lng rounded to 3dp', $blockRec['lat'] === 44.842 && $blockRec['lng'] === -93.298);

$cityRec = pb_build_public_record($fullDetailRow, $fullLabel, 'city', true);
t('city: no street_display key at all', !array_key_exists('street_display', $cityRec));
t('city: city/state still present', $cityRec['city'] === 'your deployment' && $cityRec['state'] === 'MN');
t('city: lat/lng rounded to 2dp', $cityRec['lat'] === 44.84 && $cityRec['lng'] === -93.3);

$hiddenRec = pb_build_public_record($fullDetailRow, $fullLabel, 'hidden', true);
t('hidden: no lat key at all', !array_key_exists('lat', $hiddenRec));
t('hidden: no lng key at all', !array_key_exists('lng', $hiddenRec));
t('hidden: city/state still present (text, not coordinates, withheld)',
    $hiddenRec['city'] === 'your deployment' && $hiddenRec['state'] === 'MN');

// ── Full-detail envelope has exactly the expected key set ───────────────
$expectedFullKeys = ['id','type','type_group','severity_text','opened','updated',
    'assigned_units','street_display','city','state','lat','lng'];
sort($expectedFullKeys);
$actualKeys = array_keys($exactRec);
sort($actualKeys);
t('full-detail record has exactly the expected key set at exact precision',
    $actualKeys === $expectedFullKeys);

// ── Rule 1: presence-only stub — EXACTLY four keys ──────────────────────
$presenceRow = $fullDetailRow;
$presenceRow['public_board_visibility'] = 'presence_only';
$presenceRow['public_board_stub_label'] = 'Priority Call';
$stub = pb_build_public_record($presenceRow, $fullLabel, 'exact', true);
t('presence-only: exactly 4 keys (id, type, opened, assigned_units)',
    array_keys($stub) === ['id', 'type', 'opened', 'assigned_units']);
t('presence-only: uses the per-type stub label when set', $stub['type'] === 'Priority Call');
t('presence-only: no PII/address strings leak into the stub',
    strpos(json_encode($stub), $withheldNarrative) === false
    && strpos(json_encode($stub), '4821 Main St') === false
    && strpos(json_encode($stub), 'your deployment') === false);

// Stub label fallback — no public_board_stub_label set -> generic "Response",
// never a hardcoded medical-specific phrase (security review finding #7).
$presenceRowNoStub = $fullDetailRow;
$presenceRowNoStub['public_board_visibility'] = 'presence_only';
$presenceRowNoStub['public_board_stub_label'] = null;
$stubDefault = pb_build_public_record($presenceRowNoStub, $fullLabel, 'exact', true);
t('presence-only with no stub label configured falls back to generic "Response"',
    $stubDefault['type'] === 'Response');
t('the generic fallback is domain-neutral, not a medical-specific phrase',
    stripos($stubDefault['type'], 'medical') === false);

// A non-medical presence-only type (e.g. Domestic) must ALSO get the
// neutral "Response" fallback, not a phrase that mislabels it as medical
// (this is exactly security review finding #7's scenario).
$domesticPresenceRow = $fullDetailRow;
$domesticPresenceRow['type_name'] = 'Domestic Disturbance';
$domesticPresenceRow['type_group'] = 'Domestic';
$domesticPresenceRow['public_board_visibility'] = 'presence_only';
$domesticPresenceRow['public_board_stub_label'] = null;
$domesticStub = pb_build_public_record($domesticPresenceRow, $fullLabel, 'exact', true);
t('a non-medical presence-only type still gets the neutral "Response" stub, never "Medical..."',
    $domesticStub['type'] === 'Response');

// ── $applyTypeVisibility = false bypasses the stub entirely (feed.php use case) ──
// Security review finding #2: without this flag, a type an admin marked
// presence-only for the PUBLIC BOARD would silently stub out in feed.php's
// full-detail output too. Proving the flag actually takes effect.
$fedRec = pb_build_public_record($presenceRow, $fullLabel, 'exact', false);
t('applyTypeVisibility=false: full detail served even though the type is presence_only',
    isset($fedRec['street_display']) && $fedRec['street_display'] === '4821 Main St');
t('applyTypeVisibility=false: type_group is present (not the 4-key stub shape)',
    array_key_exists('type_group', $fedRec));

// ── Rule 2: eoc_show_address = 0 replaces street/city with the placeholder ──
$restrictedLabel = [
    'eoc_show_address'     => 0,
    'eoc_show_map_marker'  => 'dim',
    'eoc_placeholder_text' => '*** Restricted *** see dispatch console',
];
$restrictedRec = pb_build_public_record($fullDetailRow, $restrictedLabel, 'exact', true);
t('eoc_show_address=0: street_display is the label placeholder, not the real street',
    $restrictedRec['street_display'] === '*** Restricted *** see dispatch console');
t('eoc_show_address=0: city is ALSO the placeholder, not the real city',
    $restrictedRec['city'] === '*** Restricted *** see dispatch console');
t('eoc_show_address=0: the real street number never appears anywhere in the output',
    strpos(json_encode($restrictedRec), '4821 Main St') === false);
t('eoc_show_address=0: the real city name never appears anywhere in the output',
    strpos(json_encode($restrictedRec), 'your deployment') === false);

// A placeholder-less restricted label still gets SOME generic phrase, never
// an empty string that would look like a client bug.
$restrictedNoPlaceholder = ['eoc_show_address' => 0, 'eoc_show_map_marker' => 'full', 'eoc_placeholder_text' => null];
$recNoPlaceholder = pb_build_public_record($fullDetailRow, $restrictedNoPlaceholder, 'exact', true);
t('eoc_show_address=0 with no configured placeholder text still yields a non-empty phrase',
    $recNoPlaceholder['street_display'] !== '' && $recNoPlaceholder['city'] !== '');

// ── Rule 3: label caps the ceiling, never loosens it ─────────────────────
// A 'dim' label with board precision 'exact' still rounds to city.
$dimLabel = ['eoc_show_address' => 1, 'eoc_show_map_marker' => 'dim', 'eoc_placeholder_text' => null];
$dimRec = pb_build_public_record($fullDetailRow, $dimLabel, 'exact', true);
t("dim label caps 'exact' board precision down to city-level rounding",
    $dimRec['lat'] === 44.84 && $dimRec['lng'] === -93.3);
t('dim label at exact board precision: no street_display (city-level has none)',
    !array_key_exists('street_display', $dimRec));

// A 'dim' label must NOT loosen a board precision that's already coarser
// than city (i.e. 'hidden') back up to city.
$dimAtHidden = pb_build_public_record($fullDetailRow, $dimLabel, 'hidden', true);
t("dim label does NOT loosen an already-coarser 'hidden' board precision",
    !array_key_exists('lat', $dimAtHidden) && !array_key_exists('lng', $dimAtHidden));

// A 'hide' label forces no marker at all regardless of board setting,
// even when the board is configured at 'exact'.
$hideLabel = ['eoc_show_address' => 1, 'eoc_show_map_marker' => 'hide', 'eoc_placeholder_text' => null];
$hideRec = pb_build_public_record($fullDetailRow, $hideLabel, 'exact', true);
t("hide label suppresses lat/lng entirely even at board precision 'exact'",
    !array_key_exists('lat', $hideRec) && !array_key_exists('lng', $hideRec));

// A 'full' label leaves the board's configured precision unmodified.
$fullMarkerLabel = ['eoc_show_address' => 1, 'eoc_show_map_marker' => 'full', 'eoc_placeholder_text' => null];
$fullMarkerRec = pb_build_public_record($fullDetailRow, $fullMarkerLabel, 'block', true);
t("full label leaves the board's 'block' precision unmodified",
    $fullMarkerRec['lat'] === 44.842 && $fullMarkerRec['lng'] === -93.298);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
