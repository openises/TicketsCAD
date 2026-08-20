<?php
/**
 * GH TicketsCAD#68 (2026-08-18) — frontend wiring for the restored
 * patient.insurance_id / facility_id / facility_contact fields.
 *
 * Structural checks (grep/regex against the shipped JS), matching the
 * convention tests/test_gh67_mobile_patients.php already established for
 * this same file/feature area: prove the specific markup and payload
 * shapes exist, rather than driving a browser.
 *
 * Covers:
 *   1. incident-detail.js — the patient row template renders Insurance /
 *      Facility selects + a Facility Contact input, and savePatientRow()
 *      includes all three in its POST payload to api/patients.php.
 *   2. incident-detail.js — a dedicated loadInsuranceTypesList() cache
 *      function reads api/insurance-types.php (the non-admin-gated
 *      picker), mirroring loadFacilitiesList()'s existing pattern.
 *   3. config.js — the Settings "Patient Insurance Types" admin panel is
 *      wired into the panel-activation dispatcher and posts to
 *      config-admin.php's insurance_types section.
 */

$idJs = file_get_contents(__DIR__ . '/../assets/js/incident-detail.js');
$cfgJs = file_get_contents(__DIR__ . '/../assets/js/config.js');

$pass = 0; $fail = 0;
function g68ui(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH TicketsCAD#68: frontend wiring for patient insurance/facility ===\n\n";

// ── incident-detail.js: patient row template ──
g68ui('patient row template renders an Insurance select',
    strpos($idJs, 'patient-insurance') !== false);
g68ui('patient row template renders a Facility select',
    strpos($idJs, 'patient-facility') !== false);
g68ui('patient row template renders a Facility Contact input',
    strpos($idJs, 'patient-fac-contact') !== false);

// ── incident-detail.js: shared insurance-types cache, mirroring
//    loadFacilitiesList()'s established pattern ──
g68ui('loadInsuranceTypesList() function exists',
    (bool) preg_match('/function\s+loadInsuranceTypesList\s*\(\s*cb\s*\)/', $idJs));
g68ui('loadInsuranceTypesList() fetches api/insurance-types.php',
    strpos($idJs, "fetch('api/insurance-types.php'") !== false);

// ── incident-detail.js: savePatientRow() payload ──
$saveStart = strpos($idJs, 'function savePatientRow');
$saveEnd = strpos($idJs, 'function removePatientRow', $saveStart ?: 0);
g68ui('could isolate savePatientRow()', $saveStart !== false && $saveEnd !== false && $saveEnd > $saveStart);
$saveBlock = ($saveStart !== false && $saveEnd !== false) ? substr($idJs, $saveStart, $saveEnd - $saveStart) : '';

g68ui('save payload includes insurance_id', strpos($saveBlock, 'insurance_id:') !== false);
g68ui('save payload includes facility_id', strpos($saveBlock, 'facility_id:') !== false);
g68ui('save payload includes facility_contact', strpos($saveBlock, 'facility_contact:') !== false);
g68ui('save payload still includes the original 4 fields (regression guard)',
    strpos($saveBlock, 'name:') !== false
    && strpos($saveBlock, 'dob:') !== false
    && strpos($saveBlock, 'gender:') !== false
    && strpos($saveBlock, 'description:') !== false);

// ── config.js: Settings admin panel wiring ──
g68ui('panel-activation dispatcher routes patient-insurance tab to loadInsuranceTypes()',
    (bool) preg_match("/tab === 'patient-insurance'\\)\\s*loadInsuranceTypes\\(\\)/", $cfgJs));
g68ui('loadInsuranceTypes() reads the insurance_types config-admin section',
    strpos($cfgJs, "apiGet('insurance_types')") !== false);
g68ui('bindInsuranceTypesForm() posts to the insurance_types config-admin section',
    strpos($cfgJs, "apiPost('insurance_types', payload)") !== false);
g68ui('bindInsuranceTypesForm() deletes via the insurance_types config-admin section',
    strpos($cfgJs, "apiDelete('insurance_types'") !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
