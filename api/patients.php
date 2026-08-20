<?php
/**
 * NewUI v4.0 API - Patients (per-incident)
 *
 * GET  /api/patients.php?ticket_id=N   — list patients for an incident
 * POST /api/patients.php               — create / update / delete (JSON body)
 *
 *   POST actions:
 *     { action: 'add',    ticket_id, name, dob, gender, description,
 *                          insurance_id, facility_id, facility_contact }
 *     { action: 'update', id, name, dob, gender, description,
 *                          insurance_id, facility_id, facility_contact }
 *     { action: 'delete', id }
 *
 * Persists to the `patient` table (legacy MyISAM, columns: id, ticket_id,
 * name, fullname, dob, gender, insurance_id, facility_id, facility_contact,
 * description, date, user, updated). The `description` column is NOT NULL
 * with no default, so all writes pass an empty string when no condition /
 * notes were entered.
 *
 * Added 2026-06-26 to address a beta tester's beta-tester report that the
 * incident edit flow had no way to manage patients after creation.
 *
 * GH TicketsCAD#68 (2026-08-18) — insurance_id / facility_id /
 * facility_contact restored. Those three columns exist in the schema
 * (carried over unchanged from v3's `patient` table) but had no NewUI
 * read or write path at all — vestigial since the original 2026-06-26
 * implementation. v3 captured all three on the same per-patient
 * Add/Edit form (tickets/patient.php): an insurance-type dropdown, a
 * receiving-facility dropdown, and a free-text facility contact. See
 * inc/patient-write.php's docblock for the full behavioral detail; the
 * actual read/write logic now lives there so it can be tested directly.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/patient-write.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $ticket_id = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticket_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('ticket_id required');
    }
    // IDOR check — same rule as incident-detail.php
    if (!user_can_access_entity('incident', $ticket_id)) {
        ini_set('display_errors', $prevDisplay);
        json_error('Incident not found', 404);
    }
    try {
        $patients = patient_list_internal($ticket_id);
    } catch (Exception $e) {
        // table missing or other DB error — return empty list, don't 500
        $patients = [];
    }
    ini_set('display_errors', $prevDisplay);
    json_response(['patients' => $patients]);
}

if ($method !== 'POST') {
    ini_set('display_errors', $prevDisplay);
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    ini_set('display_errors', $prevDisplay);
    json_error('Invalid JSON body');
}

if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    ini_set('display_errors', $prevDisplay);
    json_error('Invalid CSRF token', 403);
}

if (!rbac_can('action.edit_incident')) {
    ini_set('display_errors', $prevDisplay);
    json_error('Insufficient permissions: edit incident', 403);
}

$action = trim($input['action'] ?? '');

// ── ACTION: add ──
if ($action === 'add') {
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    if ($ticket_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('ticket_id required');
    }
    if (!user_can_access_entity('incident', $ticket_id)) {
        ini_set('display_errors', $prevDisplay);
        json_error('Incident not found', 404);
    }

    try {
        $result = patient_add_internal($ticket_id, $input, $current_user_id);
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Failed to add patient: ' . $e->getMessage(), 500);
    }

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'id'      => $result['id'],
        'message' => 'Patient added',
    ]);
}

// ── ACTION: update ──
if ($action === 'update') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('id required');
    }
    // Look up the patient's ticket_id and gate via the parent incident
    try {
        $row = db_fetch_one(
            "SELECT `ticket_id` FROM `{$prefix}patient` WHERE `id` = ?",
            [$id]
        );
    } catch (Exception $e) {
        $row = null;
    }
    if (!$row) {
        ini_set('display_errors', $prevDisplay);
        json_error('Patient not found', 404);
    }
    if (!user_can_access_entity('incident', (int) $row['ticket_id'])) {
        ini_set('display_errors', $prevDisplay);
        json_error('Patient not found', 404);
    }

    try {
        patient_update_internal($id, $input, $current_user_id);
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Failed to update patient: ' . $e->getMessage(), 500);
    }

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => 'Patient updated',
    ]);
}

// ── ACTION: delete ──
if ($action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('id required');
    }
    try {
        $row = db_fetch_one(
            "SELECT `ticket_id` FROM `{$prefix}patient` WHERE `id` = ?",
            [$id]
        );
    } catch (Exception $e) {
        $row = null;
    }
    if (!$row) {
        ini_set('display_errors', $prevDisplay);
        json_error('Patient not found', 404);
    }
    if (!user_can_access_entity('incident', (int) $row['ticket_id'])) {
        ini_set('display_errors', $prevDisplay);
        json_error('Patient not found', 404);
    }
    try {
        db_query("DELETE FROM `{$prefix}patient` WHERE `id` = ?", [$id]);
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Failed to delete patient: ' . $e->getMessage(), 500);
    }
    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => 'Patient removed',
    ]);
}

ini_set('display_errors', $prevDisplay);
json_error('Unknown action: ' . $action . '. Valid: add, update, delete');
