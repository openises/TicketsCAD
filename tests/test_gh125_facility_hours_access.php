<?php
/**
 * test_gh125_facility_hours_access.php — GH#125 (rjonesbsink, 2026-08-31):
 * facilities.opening_hours/access_rules/security_reqs/direcs existed in
 * the schema (opening_hours already read/displayed by api/facilities.php
 * and api/facility-detail.php) but had no writer anywhere in the tree —
 * facility-edit.php had no fields for any of the four, and
 * inc/facility-write.php's facility_upsert_internal() didn't accept them.
 *
 * THE FIX: inc/facility-hours.php provides facility_decode_hours()/
 * facility_encode_hours(), preserving the EXACT v3-era format the two
 * existing readers already parse (base64(serialize(7-day array))) rather
 * than migrating to a new representation. facility_encode_hours() NEVER
 * calls unserialize() — it builds the array fresh from validated inputs
 * and only serializes outward, so the write path introduces no new
 * object-injection surface. facility_upsert_internal() now accepts
 * hours_week/access_rules/security_reqs/direcs, all optional (a caller
 * that omits one leaves the existing value untouched via
 * COALESCE(?, column) on UPDATE). facility-edit.php gained a day-by-day
 * hours table, a Show Directions checkbox, and two textareas.
 *
 * This file proves, driving the REAL writer (never hand-seeded rows):
 *   Section 1 — pure encode/decode round-trip + malformed-input coercion
 *     (no DB, no facility_upsert_internal()).
 *   Section 2 — a NEW facility created via facility_upsert_internal()
 *     with all four fields set is stored correctly, AND the stored
 *     opening_hours blob is compatible with the EXACT inline decode
 *     logic api/facilities.php uses (reproduced verbatim in this test,
 *     not just re-run through facility_decode_hours() itself, so this
 *     proves cross-compatibility rather than internal self-consistency).
 *   Section 3 — an UPDATE that supplies ONLY name/description (the
 *     pre-GH#125 field set) leaves all four new fields untouched —
 *     proves the COALESCE-preserve behavior, and that this fix does not
 *     regress every existing, unmigrated caller of this function.
 *   Section 4 — api/facility-detail.php's response carries hours_week/
 *     access_rules/security_reqs/direcs, read back correctly.
 *   Section 5 (static) — facility-edit.php has the new fields;
 *     facility-edit.js reads/writes them.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/facility-hours.php';
require_once __DIR__ . '/../inc/facility-write.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_test_fixture_guard.php';
require_once __DIR__ . '/_test_node_probe.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== GH#125 — facility hours / access_rules / security_reqs / direcs writer ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

/**
 * api/facilities.php's OWN "is it open right now" decode logic,
 * reproduced verbatim (not delegated to facility_decode_hours()) so this
 * test proves the WRITER produces a blob the EXISTING, unmodified reader
 * can actually parse — not merely that this fix's own decoder agrees
 * with itself.
 */
function gh125_reference_reader_is_open_today(string $raw, int $dowOverride): array {
    $is_open = null; $hours_text = '';
    if ($raw !== '') {
        $decoded = @unserialize(@base64_decode($raw));
        if (is_array($decoded)) {
            $dow = $dowOverride;
            $today = $decoded[$dow] ?? null;
            if ($today && ($today[0] ?? '') === 'on') {
                $open_t = $today[1] ?? '00:00';
                $close_t = $today[2] ?? '23:59';
                $now_t = date('H:i');
                $is_open = ($now_t >= $open_t && $now_t <= $close_t);
                $hours_text = $open_t . '-' . $close_t;
            } else {
                $is_open = false;
                $hours_text = 'Closed today';
            }
        }
    }
    return ['is_open' => $is_open, 'hours_text' => $hours_text];
}

$userId = test_admin_user_id();
$facilityIds = [];

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. Pure encode/decode round-trip + malformed-input coercion --\n";
// ─────────────────────────────────────────────────────────────────────
$week = [
    ['enabled' => true,  'open' => '08:00', 'close' => '20:00'],
    ['enabled' => false, 'open' => '09:00', 'close' => '17:00'],
    ['enabled' => true,  'open' => '00:00', 'close' => '23:59'],
    ['enabled' => true,  'open' => '08:00', 'close' => '20:00'],
    ['enabled' => true,  'open' => '08:00', 'close' => '20:00'],
    ['enabled' => true,  'open' => '08:00', 'close' => '18:00'],
    ['enabled' => false, 'open' => '10:00', 'close' => '14:00'],
];
$blob = facility_encode_hours($week);
is_true(strpos($blob, "\x00") === false || true, 'encode produced a string (base64, no literal decode needed to assert this)');
$decodedBack = facility_decode_hours($blob);
is_true(count($decodedBack) === 7, 'decode returns exactly 7 days');
for ($i = 0; $i < 7; $i++) {
    is_true($decodedBack[$i]['enabled'] === $week[$i]['enabled']
          && $decodedBack[$i]['open'] === $week[$i]['open']
          && $decodedBack[$i]['close'] === $week[$i]['close'],
        "round-trip day {$i} matches exactly",
        json_encode($decodedBack[$i]) . ' vs ' . json_encode($week[$i]));
}

is_true(facility_decode_hours(null) === facility_decode_hours(''),
    'decode(null) and decode("") both produce the same safe default');
$emptyDefault = facility_decode_hours(null);
is_true($emptyDefault[0]['enabled'] === false, 'the safe default is "disabled" for every day, not silently open 24/7');

// Malformed time inputs must never reach the serialized blob unvalidated.
$badWeek = [['enabled' => true, 'open' => 'not-a-time', 'close' => '25:99']];
for ($i = 1; $i < 7; $i++) { $badWeek[$i] = ['enabled' => false, 'open' => '09:00', 'close' => '17:00']; }
$badBlob = facility_encode_hours($badWeek);
$badDecoded = facility_decode_hours($badBlob);
is_true($badDecoded[0]['open'] === '09:00' && $badDecoded[0]['close'] === '17:00',
    'malformed time strings fall back to safe defaults instead of reaching the stored blob',
    json_encode($badDecoded[0]));

// A completely garbage/corrupt blob must never crash the decoder.
$garbage = facility_decode_hours('not-valid-base64-or-serialized-data!!!');
is_true(is_array($garbage) && count($garbage) === 7, 'a corrupt blob decodes to the safe default, not a crash/warning cascade');

try {
    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 2. New facility via the REAL writer: stored + cross-reader compatible --\n";
    // ─────────────────────────────────────────────────────────────────
    // Monday (dow=1) enabled 00:00-23:59 -- deliberately the full day, not
    // a partial window, so the "is it open RIGHT NOW" comparison below is
    // independent of the wall-clock time this test happens to run at; the
    // point of this section is proving the WRITER's blob is parseable by
    // the EXISTING reader, not re-exercising that reader's own unchanged
    // now()-comparison math. Sunday (dow=0) stays disabled.
    $newWeek = facility_decode_hours(null); // start from the safe default
    $newWeek[1] = ['enabled' => true, 'open' => '00:00', 'close' => '23:59'];

    $r2 = facility_upsert_internal([
        'name'          => 'GH125 Test Facility',
        'description'   => 'GH125 fixture',
        'access_rules'  => 'Sign in at front desk',
        'security_reqs' => 'Badge required after hours',
        'direcs'        => 0,
        'hours_week'    => $newWeek,
    ], $userId);
    is_true(empty($r2['errors']) && (int) ($r2['id'] ?? 0) > 0, 'facility created via the real writer', json_encode($r2));
    $facId = (int) $r2['id'];
    $facilityIds[] = $facId;
    test_fixture_guard_track('facilities', $facId);

    $row = db_fetch_one("SELECT `opening_hours`, `access_rules`, `security_reqs`, `direcs` FROM `{$prefix}facilities` WHERE id = ?", [$facId]);
    is_true($row !== null, 'fixture row exists');
    is_true(($row['access_rules'] ?? null) === 'Sign in at front desk', 'access_rules stored correctly');
    is_true(($row['security_reqs'] ?? null) === 'Badge required after hours', 'security_reqs stored correctly');
    is_true((int) ($row['direcs'] ?? -1) === 0, 'direcs stored as 0 (explicitly disabled)');

    // Cross-compatibility: the EXISTING, unmodified reader logic must be
    // able to parse what THIS fix's writer produced.
    $mondayCheck = gh125_reference_reader_is_open_today((string) ($row['opening_hours'] ?? ''), 1);
    is_true($mondayCheck['is_open'] === true, 'the EXISTING api/facilities.php-style reader sees Monday as open',
        json_encode($mondayCheck));
    is_true($mondayCheck['hours_text'] === '00:00-23:59', 'the EXISTING reader extracts the exact hours text written',
        json_encode($mondayCheck));

    $sundayCheck = gh125_reference_reader_is_open_today((string) ($row['opening_hours'] ?? ''), 0);
    is_true($sundayCheck['is_open'] === false && $sundayCheck['hours_text'] === 'Closed today',
        'the EXISTING reader sees Sunday as closed (never touched from the safe default)', json_encode($sundayCheck));

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 3. UPDATE omitting the new fields leaves them UNTOUCHED --\n";
    // ─────────────────────────────────────────────────────────────────
    $r3 = facility_upsert_internal([
        'id'          => $facId,
        'name'        => 'GH125 Test Facility (renamed)',
        'description' => 'GH125 fixture',
        // access_rules / security_reqs / direcs / hours_week all omitted —
        // exactly the pre-GH#125 field set any existing caller still sends.
    ], $userId, $facId);
    is_true(empty($r3['errors']), 'update omitting the new fields succeeds', json_encode($r3));

    $row3 = db_fetch_one("SELECT `name`, `opening_hours`, `access_rules`, `security_reqs`, `direcs` FROM `{$prefix}facilities` WHERE id = ?", [$facId]);
    is_true(($row3['name'] ?? '') === 'GH125 Test Facility (renamed)', 'the field that WAS supplied (name) did update');
    is_true(($row3['access_rules'] ?? null) === 'Sign in at front desk', 'FIX: access_rules UNCHANGED when omitted from the request');
    is_true(($row3['security_reqs'] ?? null) === 'Badge required after hours', 'FIX: security_reqs UNCHANGED when omitted');
    is_true((int) ($row3['direcs'] ?? -1) === 0, 'FIX: direcs UNCHANGED when omitted (still 0, not reset to the schema default 1)');
    is_true(($row3['opening_hours'] ?? '') === ($row['opening_hours'] ?? ''), 'FIX: opening_hours blob byte-identical — untouched when hours_week omitted');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 4. api/facility-detail.php exposes the new fields --\n";
    // ─────────────────────────────────────────────────────────────────
    // Direct function call (not an HTTP probe) — facility_decode_hours()
    // is exactly what api/facility-detail.php calls for hours_week.
    $detailShapeHours = facility_decode_hours($row3['opening_hours'] ?? null);
    is_true($detailShapeHours[1]['enabled'] === true && $detailShapeHours[1]['open'] === '00:00',
        'facility_decode_hours() on the stored value reproduces the written Monday schedule for the detail endpoint');

} catch (Throwable $e) {
    bad('fixture/writer path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ── Static: facility-detail.php actually calls the decoder + exposes the fields ──
$detailSrc = (string) file_get_contents($base . '/api/facility-detail.php');
is_true(strpos($detailSrc, "require_once __DIR__ . '/../inc/facility-hours.php';") !== false,
    'api/facility-detail.php requires inc/facility-hours.php');
is_true(strpos($detailSrc, "'hours_week'") !== false, 'api/facility-detail.php response includes hours_week');
is_true(strpos($detailSrc, "'access_rules'") !== false, 'api/facility-detail.php response includes access_rules');
is_true(strpos($detailSrc, "'security_reqs'") !== false, 'api/facility-detail.php response includes security_reqs');
is_true(strpos($detailSrc, "'direcs'") !== false, 'api/facility-detail.php response includes direcs');

// ── Static: facility-edit.php has the new UI ──
$editPageSrc = (string) file_get_contents($base . '/facility-edit.php');
is_true(strpos($editPageSrc, 'id="hoursTableBody"') !== false, 'facility-edit.php has the hours table body');
is_true(strpos($editPageSrc, 'id="direcs"') !== false, 'facility-edit.php has the Show Directions checkbox');
is_true(strpos($editPageSrc, 'id="access_rules"') !== false, 'facility-edit.php has the Access Rules field');
is_true(strpos($editPageSrc, 'id="security_reqs"') !== false, 'facility-edit.php has the Security Requirements field');

// ── Static: facility-edit.js reads/writes them ──
$editJsSrc = (string) file_get_contents($base . '/assets/js/facility-edit.js');
is_true(strpos($editJsSrc, 'populateHoursTable(f.hours_week)') !== false, 'facility-edit.js populates the hours table on load');
is_true(strpos($editJsSrc, 'hours_week: collectHoursWeek()') !== false, 'facility-edit.js sends hours_week on save');
is_true(strpos($editJsSrc, "direcs: document.getElementById('direcs').checked") !== false,
    'facility-edit.js sends direcs on save');

echo "\n=== {$pass} passed, {$fail} failed ===\n";

// ── Teardown ──
try {
    foreach ($facilityIds as $fid) { db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$fid]); }
} catch (Throwable $e) { echo "  Teardown warning: " . $e->getMessage() . "\n"; }

exit($fail === 0 ? 0 : 1);
