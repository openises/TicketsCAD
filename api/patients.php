<?php
/**
 * NewUI v4.0 API - Patients (per-incident)
 *
 * GET  /api/patients.php?ticket_id=N   — list patients for an incident
 * POST /api/patients.php               — create / update / delete (JSON body)
 *
 *   POST actions:
 *     { action: 'add',    ticket_id, name, dob, gender, description }
 *     { action: 'update', id, name, dob, gender, description }
 *     { action: 'delete', id }
 *
 * Persists to the `patient` table (legacy MyISAM, columns: id, ticket_id,
 * name, fullname, dob, gender, description, date, user, updated). The
 * `description` column is NOT NULL with no default, so all writes pass an
 * empty string when no condition / notes were entered.
 *
 * Added 2026-06-26 to address a beta tester's beta-tester report that the
 * incident edit flow had no way to manage patients after creation.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/access.php';

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
    $patients = [];
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `ticket_id`, `name`, `fullname`, `dob`, `gender`, `description`, `date`
             FROM `{$prefix}patient`
             WHERE `ticket_id` = ?
             ORDER BY `id` ASC",
            [$ticket_id]
        );
        foreach ($rows as $r) {
            $patients[] = [
                'id'          => (int) $r['id'],
                'ticket_id'   => (int) $r['ticket_id'],
                'name'        => $r['name'] ?? '',
                'fullname'    => $r['fullname'] ?? '',
                'dob'         => $r['dob'] ?? '',
                'gender'      => (int) ($r['gender'] ?? 0),
                'description' => $r['description'] ?? '',
                'date'        => $r['date'] ?? '',
            ];
        }
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
$now    = date('Y-m-d H:i:s');

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
    $name   = trim((string) ($input['name'] ?? ''));
    $dob    = trim((string) ($input['dob'] ?? ''));
    $gender = (int) ($input['gender'] ?? 0);
    $desc   = trim((string) ($input['description'] ?? ''));

    try {
        db_query(
            "INSERT INTO `{$prefix}patient`
             (`ticket_id`, `name`, `fullname`, `dob`, `gender`, `description`, `date`, `user`, `updated`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$ticket_id, $name, $name, $dob, $gender, $desc, $now, $current_user_id, $now]
        );
        $new_id = db_insert_id();
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Failed to add patient: ' . $e->getMessage(), 500);
    }

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'id'      => (int) $new_id,
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

    $name   = trim((string) ($input['name'] ?? ''));
    $dob    = trim((string) ($input['dob'] ?? ''));
    $gender = (int) ($input['gender'] ?? 0);
    $desc   = trim((string) ($input['description'] ?? ''));

    try {
        db_query(
            "UPDATE `{$prefix}patient`
             SET `name` = ?, `fullname` = ?, `dob` = ?, `gender` = ?, `description` = ?, `updated` = ?
             WHERE `id` = ?",
            [$name, $name, $dob, $gender, $desc, $now, $id]
        );
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
