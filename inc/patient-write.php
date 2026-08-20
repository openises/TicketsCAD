<?php
/**
 * Patient record read/write helpers (per-incident).
 *
 * GH TicketsCAD#68 (2026-08-18) — restores `patient.insurance_id`,
 * `patient.facility_id`, and `patient.facility_contact`. All three exist
 * in the schema (base_schema.sql, carried over unchanged from v3's
 * DB_FULL.sql) but api/patients.php's original 2026-06-26 implementation
 * only ever read/wrote name/dob/gender/description — insurance and the
 * receiving facility, both captured per-patient in the v3 Patient Add/Edit
 * form (tickets/patient.php), had no NewUI read or write path at all.
 *
 * v3 behavior being restored (tickets/patient.php):
 *   - `insurance_id` — a dropdown sourced from the (admin-managed) legacy
 *     `insurance` lookup table, e.g. "Medicare", "Private", "Self-Pay".
 *     Nullable; 0/unset means "not selected".
 *   - `facility_id` — a dropdown sourced from the `facilities` table:
 *     which hospital/facility THIS PATIENT was taken to. Distinct from
 *     Phase 116's per-UNIT `assigns.rec_facility_id` — this is per-PATIENT,
 *     which matters in a mass-casualty incident where different patients
 *     on the same incident can go to different facilities even when
 *     carried by the same unit. NOT NULL, defaults to 0 ("none selected").
 *   - `facility_contact` — free-text contact info at that receiving
 *     facility for this patient (e.g. a charge nurse's name/extension).
 *     varchar(64) in the schema.
 *
 * Extracted from api/patients.php (rather than left inline) so tests can
 * drive the REAL write path directly — `php://input` is empty under the
 * CLI SAPI on this project's PHP 8.2.4 build (see
 * tests/test_org_sharing_manual_api.php's docblock for the verified
 * finding), so a subprocess-driven POST test cannot deliver a JSON body
 * to api/patients.php directly. The caller (api/patients.php) still owns
 * all auth/CSRF/RBAC/IDOR gating — these functions only write/read.
 */

declare(strict_types=1);

/**
 * List patients for an incident, in the shape api/patients.php's GET
 * returns as `patients`.
 *
 * @return array<int, array<string, mixed>>
 */
function patient_list_internal(int $ticketId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $patients = [];

    $rows = db_fetch_all(
        "SELECT `id`, `ticket_id`, `name`, `fullname`, `dob`, `gender`,
                `insurance_id`, `facility_id`, `facility_contact`,
                `description`, `date`
         FROM `{$prefix}patient`
         WHERE `ticket_id` = ?
         ORDER BY `id` ASC",
        [$ticketId]
    );
    foreach ($rows as $r) {
        $patients[] = [
            'id'               => (int) $r['id'],
            'ticket_id'        => (int) $r['ticket_id'],
            'name'             => $r['name'] ?? '',
            'fullname'         => $r['fullname'] ?? '',
            'dob'              => $r['dob'] ?? '',
            'gender'           => (int) ($r['gender'] ?? 0),
            'insurance_id'     => isset($r['insurance_id']) && $r['insurance_id'] !== null ? (int) $r['insurance_id'] : 0,
            'facility_id'      => (int) ($r['facility_id'] ?? 0),
            'facility_contact' => $r['facility_contact'] ?? '',
            'description'      => $r['description'] ?? '',
            'date'             => $r['date'] ?? '',
        ];
    }

    return $patients;
}

/**
 * insurance_id is nullable (schema DEFAULT NULL) — 0/absent means "not
 * selected", stored as NULL so an unset value doesn't masquerade as
 * insurance record #0. facility_id is NOT NULL DEFAULT 0 — 0 IS the
 * valid "no facility selected" value there, stored as-is (matches how
 * Phase 116's assigns.rec_facility_id treats 0). facility_contact is a
 * free-text varchar(64); trimmed and hard-capped to the column width so
 * an overlong value truncates predictably here rather than depending on
 * whether the connection's sql_mode is strict.
 *
 * @return array{0: ?int, 1: int, 2: string}
 */
function _patient_normalize_extended_fields(array $input): array
{
    $insuranceIdRaw = (int) ($input['insurance_id'] ?? 0);
    $insuranceId = $insuranceIdRaw > 0 ? $insuranceIdRaw : null;

    $facilityId = (int) ($input['facility_id'] ?? 0);
    if ($facilityId < 0) {
        $facilityId = 0;
    }

    $facilityContact = trim((string) ($input['facility_contact'] ?? ''));
    if (strlen($facilityContact) > 64) {
        $facilityContact = substr($facilityContact, 0, 64);
    }

    return [$insuranceId, $facilityId, $facilityContact];
}

/**
 * Create a new patient row for an incident.
 *
 * @return array{id: int}
 */
function patient_add_internal(int $ticketId, array $input, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');

    $name   = trim((string) ($input['name'] ?? ''));
    $dob    = trim((string) ($input['dob'] ?? ''));
    $gender = (int) ($input['gender'] ?? 0);
    $desc   = trim((string) ($input['description'] ?? ''));
    [$insuranceId, $facilityId, $facilityContact] = _patient_normalize_extended_fields($input);

    db_query(
        "INSERT INTO `{$prefix}patient`
         (`ticket_id`, `name`, `fullname`, `dob`, `gender`,
          `insurance_id`, `facility_id`, `facility_contact`,
          `description`, `date`, `user`, `updated`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$ticketId, $name, $name, $dob, $gender,
         $insuranceId, $facilityId, $facilityContact,
         $desc, $now, $userId, $now]
    );

    return ['id' => (int) db_insert_id()];
}

/**
 * Update an existing patient row. Caller has already resolved and
 * access-checked the row's ticket_id.
 */
function patient_update_internal(int $id, array $input, int $userId): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');

    $name   = trim((string) ($input['name'] ?? ''));
    $dob    = trim((string) ($input['dob'] ?? ''));
    $gender = (int) ($input['gender'] ?? 0);
    $desc   = trim((string) ($input['description'] ?? ''));
    [$insuranceId, $facilityId, $facilityContact] = _patient_normalize_extended_fields($input);

    db_query(
        "UPDATE `{$prefix}patient`
         SET `name` = ?, `fullname` = ?, `dob` = ?, `gender` = ?,
             `insurance_id` = ?, `facility_id` = ?, `facility_contact` = ?,
             `description` = ?, `updated` = ?
         WHERE `id` = ?",
        [$name, $name, $dob, $gender,
         $insuranceId, $facilityId, $facilityContact,
         $desc, $now, $id]
    );
}
