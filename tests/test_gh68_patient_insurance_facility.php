<?php
/**
 * GH TicketsCAD#68 (2026-08-18) — patient.insurance_id / facility_id /
 * facility_contact restored.
 *
 * `api/patients.php`'s original 2026-06-26 implementation only ever
 * read/wrote name/dob/gender/description, even though the `patient` table
 * (and the `insurance` lookup table) carried three more columns straight
 * over from v3's schema: insurance_id, facility_id, facility_contact. v3
 * (tickets/patient.php) captured all three on the same per-patient
 * Add/Edit form — an insurance-type dropdown, a receiving-facility
 * dropdown, and a free-text facility contact.
 *
 * This drives the REAL write path — inc/patient-write.php's
 * patient_add_internal() / patient_update_internal() / patient_list_internal()
 * — the exact functions api/patients.php now calls, not a hand-seeded
 * reproduction of the schema. (php://input is empty under the CLI SAPI on
 * this project's PHP 8.2.4 build — see tests/test_org_sharing_manual_api.php's
 * docblock for the verified finding — so a subprocess-driven HTTP POST test
 * against api/patients.php directly isn't possible; extracting the write
 * logic to inc/ is what makes this level of direct, real-writer testing
 * possible at all for this endpoint.)
 *
 * Also exercises api/insurance-types.php's read-only picker query shape
 * directly against the `insurance` table (same SELECT the endpoint runs)
 * to confirm the admin-managed list is actually queryable in the shape the
 * frontend expects.
 *
 * All test data uses __NUT68_ prefix (this GH issue's own prefix, distinct
 * from test_newui_full.php's shared __NUT_ prefix) for identification and
 * cleanup, and is fully cleaned up at the end regardless of outcome.
 *
 * Usage: php tests/test_gh68_patient_insurance_facility.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/patient-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();

$pass = 0; $fail = 0;
function g68(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH TicketsCAD#68: patient insurance_id / facility_id / facility_contact ===\n\n";

// ── Fixtures: a throwaway incident type, ticket, insurance type, facility ──
$typeId = null; $ticketId = null; $insId = null; $facId = null; $patientIds = [];

try {
    db_query(
        "INSERT INTO `{$prefix}in_types` (`type`, `description`, `protocol`, `set_severity`, `sort`)
         VALUES (?, ?, ?, ?, ?)",
        ['__NUT68_ Medical', 'GH#68 test incident type', 'n/a', 2, 999]
    );
    $typeId = (int) db_insert_id();

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `status`, `severity`, `scope`, `description`,
         `street`, `city`, `state`, `date`, `problemstart`, `_by`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$typeId, 2, 2, '__NUT68_ Test Incident', 'GH#68 patient insurance/facility test',
         '1 Test St', 'Testville', 'CA', $now, $now, $adminId]
    );
    $ticketId = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}insurance` (`ins_value`, `sort_order`, `_by`, `_from`)
         VALUES (?, ?, ?, ?)",
        ['__NUT68_ Test Insurance', 0, $adminId, 'test']
    );
    $insId = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}facilities` (`name`, `description`, `type`)
         VALUES (?, ?, ?)",
        ['__NUT68_ Test Hospital', 'GH#68 test facility', 1]
    );
    $facId = (int) db_insert_id();
} catch (Exception $e) {
    fwrite(STDERR, "FIXTURE SETUP FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

g68('fixtures created (type, ticket, insurance, facility)',
    $typeId > 0 && $ticketId > 0 && $insId > 0 && $facId > 0);

// ── 1. patient_add_internal() writes all three restored columns ──
$addResult = patient_add_internal($ticketId, [
    'name'             => '__NUT68_ Doe',
    'dob'              => '01/01/1980',
    'gender'           => 1,
    'insurance_id'     => $insId,
    'facility_id'      => $facId,
    'facility_contact' => 'Charge nurse, ext 4102',
    'description'      => 'GH#68 add test',
], $adminId);
$patientId = $addResult['id'] ?? 0;
$patientIds[] = $patientId;

g68('patient_add_internal() returns a new id', $patientId > 0);

$row = db_fetch_one("SELECT * FROM `{$prefix}patient` WHERE `id` = ?", [$patientId]);
g68('insurance_id persisted', $row && (int) $row['insurance_id'] === $insId,
    'got ' . var_export($row['insurance_id'] ?? null, true));
g68('facility_id persisted', $row && (int) $row['facility_id'] === $facId,
    'got ' . var_export($row['facility_id'] ?? null, true));
g68('facility_contact persisted', $row && $row['facility_contact'] === 'Charge nurse, ext 4102',
    'got ' . var_export($row['facility_contact'] ?? null, true));
g68('name/description still written (regression guard on the original 4 fields)',
    $row && $row['name'] === '__NUT68_ Doe' && $row['description'] === 'GH#68 add test');

// ── 2. patient_list_internal() returns the restored fields ──
$list = patient_list_internal($ticketId);
$listed = null;
foreach ($list as $p) {
    if ((int) $p['id'] === $patientId) { $listed = $p; break; }
}
g68('patient_list_internal() finds the new patient', $listed !== null);
g68('list output includes insurance_id', $listed && (int) $listed['insurance_id'] === $insId);
g68('list output includes facility_id', $listed && (int) $listed['facility_id'] === $facId);
g68('list output includes facility_contact', $listed && $listed['facility_contact'] === 'Charge nurse, ext 4102');

// ── 3. insurance_id = 0/absent stores NULL (schema default), not 0 ──
// (v3 parity — insurance_id is nullable; 0 means "not selected", never
// insurance record #0.)
$addResult2 = patient_add_internal($ticketId, [
    'name'        => '__NUT68_ NoInsurance',
    'description' => 'GH#68 no-insurance test',
    // insurance_id omitted entirely
], $adminId);
$patientId2 = $addResult2['id'] ?? 0;
$patientIds[] = $patientId2;
$row2 = db_fetch_one("SELECT `insurance_id`, `facility_id` FROM `{$prefix}patient` WHERE `id` = ?", [$patientId2]);
g68('insurance_id stored as NULL when unset (not 0)', $row2 && $row2['insurance_id'] === null,
    'got ' . var_export($row2['insurance_id'] ?? 'MISSING_ROW', true));
g68('facility_id defaults to 0 (NOT NULL column, matches schema default) when unset',
    $row2 && (int) $row2['facility_id'] === 0);

// ── 4. facility_contact longer than the varchar(64) column is truncated
//       predictably by the writer, not left to depend on sql_mode ──
$longContact = str_repeat('X', 100);
$addResult3 = patient_add_internal($ticketId, [
    'name'             => '__NUT68_ LongContact',
    'facility_contact' => $longContact,
    'description'      => 'GH#68 truncation test',
], $adminId);
$patientId3 = $addResult3['id'] ?? 0;
$patientIds[] = $patientId3;
$row3 = db_fetch_one("SELECT `facility_contact` FROM `{$prefix}patient` WHERE `id` = ?", [$patientId3]);
g68('facility_contact truncated to 64 chars', $row3 && strlen($row3['facility_contact']) === 64,
    'got length ' . strlen($row3['facility_contact'] ?? ''));

// ── 5. patient_update_internal() updates all three fields on an existing row ──
db_query(
    "INSERT INTO `{$prefix}insurance` (`ins_value`, `sort_order`, `_by`, `_from`)
     VALUES (?, ?, ?, ?)",
    ['__NUT68_ Second Insurance', 1, $adminId, 'test']
);
$insId2 = (int) db_insert_id();

patient_update_internal($patientId, [
    'name'             => '__NUT68_ Doe Updated',
    'dob'              => '01/01/1980',
    'gender'           => 1,
    'insurance_id'     => $insId2,
    'facility_id'      => 0,
    'facility_contact' => 'Front desk',
    'description'      => 'GH#68 update test',
], $adminId);

$updated = db_fetch_one("SELECT * FROM `{$prefix}patient` WHERE `id` = ?", [$patientId]);
g68('update changes insurance_id', $updated && (int) $updated['insurance_id'] === $insId2);
g68('update can clear facility_id back to 0', $updated && (int) $updated['facility_id'] === 0);
g68('update changes facility_contact', $updated && $updated['facility_contact'] === 'Front desk');
g68('update changes name (regression guard)', $updated && $updated['name'] === '__NUT68_ Doe Updated');

// ── 6. api/insurance-types.php's query shape returns the admin-managed list ──
// (Same SELECT the endpoint runs — see that file. Not driven over HTTP for
// the same php://input/CLI-SAPI reason noted above; GET endpoints with no
// body could be subprocess-driven, but keeping this in the same DB-fixture
// test avoids a second fixture setup for one query.)
$insuranceTypes = db_fetch_all(
    "SELECT `id`, `ins_value`, `sort_order`
       FROM `{$prefix}insurance`
      ORDER BY `sort_order` ASC, `ins_value` ASC"
);
$foundBoth = 0;
foreach ($insuranceTypes as $it) {
    if ((int) $it['id'] === $insId || (int) $it['id'] === $insId2) $foundBoth++;
}
g68('insurance list query returns both test insurance types', $foundBoth === 2,
    "found $foundBoth of 2");

// ── Cleanup ──
try {
    if ($patientIds) {
        $ids = implode(',', array_map('intval', array_filter($patientIds)));
        if ($ids !== '') db_query("DELETE FROM `{$prefix}patient` WHERE `id` IN ({$ids})");
    }
    if ($ticketId) db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]);
    if ($typeId) db_query("DELETE FROM `{$prefix}in_types` WHERE `id` = ?", [$typeId]);
    if ($insId) db_query("DELETE FROM `{$prefix}insurance` WHERE `id` = ?", [$insId]);
    if (isset($insId2) && $insId2) db_query("DELETE FROM `{$prefix}insurance` WHERE `id` = ?", [$insId2]);
    if ($facId) db_query("DELETE FROM `{$prefix}facilities` WHERE `id` = ?", [$facId]);
} catch (Exception $e) {
    fwrite(STDERR, "CLEANUP WARNING: " . $e->getMessage() . "\n");
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
