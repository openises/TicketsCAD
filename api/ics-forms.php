<?php
/**
 * NewUI v4.0 API - ICS Forms
 *
 * GET /api/ics-forms.php                  — List saved forms (optionally ?incident_id=X)
 * GET /api/ics-forms.php?id=X             — Get a specific saved form
 * GET /api/ics-forms.php?template=213     — Get blank template for a form type
 * POST action=save                        — Save form data (create or update)
 * POST action=export_xml                  — Export as Winlink-compatible XML (ICS-213)
 * POST action=export_pdf                  — Generate print-optimized HTML
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/rbac.php';
if (file_exists(__DIR__ . '/../inc/security-labels.php')) {
    require_once __DIR__ . '/../inc/security-labels.php';
}

$method = $_SERVER['REQUEST_METHOD'];
$prefix = $GLOBALS['db_prefix'] ?? '';

// GH #79 — team-wide visibility for standalone (incident-less) ICS forms.
// Off by default; an admin turns it on only when the install is a single
// organization (see ics_form_accessible()). Read once per request and thread
// it through every access check below.
//
// NOTE: use get_variable() (the `settings` name/value store the Settings UI
// writes to via api/config-admin.php), NOT get_setting() — that reads the
// separate `config` key/value table and would never see this toggle.
$icsShareStandalone = function_exists('get_variable')
    ? (get_variable('ics_forms_share_standalone') === '1')
    : false;

/**
 * Phase 73q (2026-06-14) — IDOR fix. GH #79 (2026-07-12) — configurable
 * standalone-form sharing.
 *
 * Returns true if the current session is allowed to read or mutate
 * this saved form. Rules:
 *  - Admin / form owner — always allowed.
 *  - Form bound to an incident — gate on access to that incident.
 *  - Orphan form (no incident_id) — creator only, UNLESS the install has
 *    opted into team-wide standalone sharing ($shareStandalone), in which
 *    case any authenticated user may read/mutate it.
 *
 * Without this gate, any logged-in user could read or overwrite any
 * other agency's ICS-213 dispatch messages by incrementing the id
 * in a GET. See specs/security-audit-2026-06/ for the audit note.
 *
 * $shareStandalone is passed in (not read from the DB here) so this stays
 * a pure function — the IDOR regression test drives it in isolation, and
 * the callers read get_setting('ics_forms_share_standalone') once per
 * request. It DEFAULTS to false: an install must explicitly assert it is a
 * single organization before orphan forms open to the whole team, because
 * ics_forms carries no org_id and opening it blindly would be a cross-tenant
 * leak on a multi-org install (test_ics_forms_idor.php guards this).
 */
function ics_form_accessible(array $row, bool $shareStandalone = false): bool
{
    if (is_admin()) return true;
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($currentUserId > 0
        && (int) ($row['created_by'] ?? 0) === $currentUserId) {
        return true;
    }
    $incidentId = (int) ($row['incident_id'] ?? 0);
    if ($incidentId > 0) {
        return user_can_access_entity('incident', $incidentId);
    }
    // Orphan form, not owned by us, not admin. GH #79: a beta tester runs one
    // organization per install (a fresh instance per event), so team-wide
    // visibility of standalone forms is exactly what's wanted there. Honor it
    // only when the admin has turned on ics_forms_share_standalone AND the
    // caller is authenticated — never for an anonymous/absent session.
    if ($shareStandalone && $currentUserId > 0) {
        return true;
    }
    return false;
}

// ── GET handlers ────────────────────────────────────────────
if ($method === 'GET') {

    // Get blank template
    if (isset($_GET['template'])) {
        $type = $_GET['template'];
        $tpl = getFormTemplate($type);
        if (!$tpl) json_error('Unknown form type: ' . $type);
        json_response($tpl);
    }

    // Get specific form by id
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?",
            [$id]
        );
        if (!$row) json_error('Form not found', 404);
        if (!ics_form_accessible($row, $icsShareStandalone)) json_error('Form not found', 404);
        $row['form_data'] = json_decode($row['form_data_json'], true);
        unset($row['form_data_json']);
        json_response($row);
    }

    // List forms — optionally filtered by incident_id or form_type
    $where = '1=1';
    $params = [];

    if (isset($_GET['incident_id'])) {
        $where .= ' AND `incident_id` = ?';
        $params[] = (int) $_GET['incident_id'];
    }
    if (isset($_GET['form_type'])) {
        $where .= ' AND `form_type` = ?';
        $params[] = $_GET['form_type'];
    }

    $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $rows = db_fetch_all(
        "SELECT `id`, `form_type`, `incident_id`, `title`, `status`,
                `created_by`, `created_by_name`, `created_at`, `updated_at`
         FROM `{$prefix}ics_forms`
         WHERE {$where}
         ORDER BY `updated_at` DESC
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    // Phase 73q — IDOR fix on the list endpoint. Drop rows the caller
    // can't access (someone else's orphan forms or incidents not in
    // their allocates groups). Cheaper to filter in PHP than to JOIN
    // allocates here, and the resulting page may be short but never
    // contains forbidden rows.
    if (!is_admin()) {
        $rows = array_values(array_filter($rows ?: [], function ($r) use ($icsShareStandalone) {
            return ics_form_accessible($r, $icsShareStandalone);
        }));
    }

    $total = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}ics_forms` WHERE {$where}",
        $params
    );

    json_response(['forms' => $rows, 'total' => $total]);
}

// ── POST handlers ───────────────────────────────────────────
if ($method === 'POST') {

    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!$token) {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['csrf_token'] ?? '';
    }
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    // Parse JSON body if Content-Type is JSON
    if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    // Phase 73r — RBAC gate. Authoring or exporting ICS forms is an
    // operational dispatch task; piggyback on action.create_incident
    // (the existing "trusted to dispatch" permission) plus admin
    // bypass. A user without either has no business saving forms or
    // generating an exportable PDF/XML, since both surfaces contain
    // PII the ICS-213 redactor + Phase 18f labels are supposed to
    // protect.
    //
    // Volunteers without operational roles can still READ forms they
    // own or that are bound to incidents they can access (GET is not
    // gated here — the per-row ics_form_accessible() check from
    // Phase 73q does that more granularly).
    if (!is_admin()
        && !rbac_can('action.create_incident')
        && !rbac_can('action.manage_ics_forms')) {
        json_error('Forbidden — requires Incident Create or ICS Forms Management role', 403);
    }

    // ── Save form ──
    if ($action === 'save') {
        $formType = $input['form_type'] ?? '';
        $title    = trim($input['title'] ?? '');
        $status   = $input['status'] ?? 'draft';
        $formData = $input['form_data'] ?? [];
        $incidentId = isset($input['incident_id']) && $input['incident_id'] !== ''
            ? (int) $input['incident_id']
            : null;
        $formId   = isset($input['id']) ? (int) $input['id'] : 0;

        // Validate
        $validTypes = ['213', '214', '202', '205', '205a', '213rr', '206', '214a', '221'];
        if (!in_array($formType, $validTypes)) {
            json_error('Invalid form_type. Must be one of: ' . implode(', ', $validTypes));
        }
        $validStatuses = ['draft', 'final', 'sent'];
        if (!in_array($status, $validStatuses)) {
            $status = 'draft';
        }

        $json = json_encode($formData, JSON_UNESCAPED_UNICODE);
        $userName = $_SESSION['user'] ?? '';

        if ($formId > 0) {
            // Phase 73q — IDOR fix on UPDATE. Without this, any logged-in
            // user could overwrite any other agency's saved ICS-213 by
            // passing its id back to ?action=save.
            $existing = db_fetch_one(
                "SELECT `id`, `incident_id`, `created_by`
                   FROM `{$prefix}ics_forms` WHERE `id` = ?",
                [$formId]
            );
            if (!$existing) json_error('Form not found', 404);
            if (!ics_form_accessible($existing, $icsShareStandalone)) json_error('Form not found', 404);
            // Update existing
            db_query(
                "UPDATE `{$prefix}ics_forms`
                 SET `form_type` = ?, `incident_id` = ?, `title` = ?,
                     `form_data_json` = ?, `status` = ?, `updated_at` = NOW()
                 WHERE `id` = ?",
                [$formType, $incidentId, $title, $json, $status, $formId]
            );
        } else {
            // Insert new
            db_query(
                "INSERT INTO `{$prefix}ics_forms`
                 (`form_type`, `incident_id`, `title`, `form_data_json`,
                  `created_by`, `created_by_name`, `status`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$formType, $incidentId, $title, $json, $current_user_id, $userName, $status]
            );
            $formId = (int) db_insert_id();
        }

        json_response(['ok' => true, 'id' => $formId]);
    }

    // ── Export XML (ICS-213 Winlink) ──
    if ($action === 'export_xml') {
        $formId = (int) ($input['id'] ?? 0);
        if (!$formId) json_error('id is required');

        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?",
            [$formId]
        );
        if (!$row) json_error('Form not found', 404);
        if (!ics_form_accessible($row, $icsShareStandalone)) json_error('Form not found', 404);
        if ($row['form_type'] !== '213') {
            json_error('XML export is only available for ICS-213 forms');
        }

        $data = json_decode($row['form_data_json'], true);
        $xml = generateICS213Xml($data, $row);

        // Return XML as a JSON-wrapped string so JS can create a download
        json_response(['xml' => $xml, 'filename' => 'RMS_Express_Form_ICS213_' . $formId . '.xml']);
    }

    // ── Export print-optimized HTML ──
    if ($action === 'export_pdf') {
        $formId = (int) ($input['id'] ?? 0);
        if (!$formId) json_error('id is required');

        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?",
            [$formId]
        );
        if (!$row) json_error('Form not found', 404);
        if (!ics_form_accessible($row, $icsShareStandalone)) json_error('Form not found', 404);

        $data = json_decode($row['form_data_json'], true);
        // Phase 18f — resolve security label for the linked incident
        // and apply watermark + redaction. If no incident_id, fall
        // through with no security wrap.
        $sec = null;
        if (!empty($row['incident_id']) && function_exists('seclabel_resolve')) {
            $sec = seclabel_resolve((int) $row['incident_id']);
        }
        $html = generatePrintHtml($row['form_type'], $data, $row);
        if ($sec) $html = _ics_apply_security_wrap($html, $sec);

        json_response(['html' => $html]);
    }

    json_error('Unknown action: ' . $action);
}

json_error('Method not allowed', 405);

// ═══════════════════════════════════════════════════════════════
// Helper functions
// ═══════════════════════════════════════════════════════════════

/**
 * Return a blank template definition for a given form type.
 */
function getFormTemplate($type) {
    $templates = [
        '213' => [
            'form_type'   => '213',
            'form_number' => 'ICS-213',
            'form_title'  => 'General Message',
            'fields' => [
                ['key' => 'to_name',           'label' => 'To (Name)',           'type' => 'text',     'required' => true],
                ['key' => 'to_position',       'label' => 'To (Position)',       'type' => 'text',     'required' => false],
                ['key' => 'from_name',         'label' => 'From (Name)',         'type' => 'text',     'required' => true],
                ['key' => 'from_position',     'label' => 'From (Position)',     'type' => 'text',     'required' => false],
                ['key' => 'subject',           'label' => 'Subject',             'type' => 'text',     'required' => true],
                ['key' => 'date',              'label' => 'Date',                'type' => 'date',     'required' => true],
                ['key' => 'time',              'label' => 'Time',                'type' => 'time',     'required' => true],
                ['key' => 'message',           'label' => 'Message',             'type' => 'textarea', 'required' => true, 'rows' => 6],
                ['key' => 'approved_name',     'label' => 'Approved By (Name)',  'type' => 'text',     'required' => false],
                ['key' => 'approved_position', 'label' => 'Approved By (Position)', 'type' => 'text', 'required' => false],
                ['key' => 'reply',             'label' => 'Reply',               'type' => 'textarea', 'required' => false, 'rows' => 4],
                ['key' => 'reply_date',        'label' => 'Reply Date',          'type' => 'date',     'required' => false],
                ['key' => 'reply_time',        'label' => 'Reply Time',          'type' => 'time',     'required' => false],
                ['key' => 'reply_by',          'label' => 'Reply Signature/Position', 'type' => 'text', 'required' => false],
            ]
        ],
        '214' => [
            'form_type'   => '214',
            'form_number' => 'ICS-214',
            'form_title'  => 'Activity Log',
            'fields' => [
                ['key' => 'incident_name',     'label' => 'Incident Name',       'type' => 'text',     'required' => true],
                ['key' => 'date_prepared',     'label' => 'Date Prepared',       'type' => 'date',     'required' => true],
                ['key' => 'op_period_from',    'label' => 'Operational Period From', 'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_to',      'label' => 'Operational Period To',   'type' => 'datetime-local', 'required' => true],
                ['key' => 'team_name',         'label' => 'Team Name/Number',    'type' => 'text',     'required' => true],
                ['key' => 'team_leader_name',  'label' => 'Team Leader (Name)',  'type' => 'text',     'required' => true],
                ['key' => 'team_leader_position', 'label' => 'Team Leader (Position)', 'type' => 'text', 'required' => false],
                ['key' => 'activity_log',      'label' => 'Activity Log',        'type' => 'table',    'required' => false,
                 'columns' => [
                     ['key' => 'time',     'label' => 'Time',               'type' => 'time',  'width' => '120px'],
                     ['key' => 'activity', 'label' => 'Notable Activities', 'type' => 'text',  'width' => 'auto']
                 ]
                ],
                ['key' => 'prepared_by',       'label' => 'Prepared By',         'type' => 'text',     'required' => false],
            ]
        ],
        '202' => [
            'form_type'   => '202',
            'form_number' => 'ICS-202',
            'form_title'  => 'Incident Objectives',
            'fields' => [
                ['key' => 'incident_name',     'label' => 'Incident Name',       'type' => 'text',     'required' => true],
                ['key' => 'date_prepared',     'label' => 'Date/Time Prepared',  'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_from',    'label' => 'Operational Period From', 'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_to',      'label' => 'Operational Period To',   'type' => 'datetime-local', 'required' => true],
                ['key' => 'objectives',        'label' => 'Objective(s)',        'type' => 'textarea', 'required' => true, 'rows' => 8],
                ['key' => 'current_actions',   'label' => 'Summary of Current Actions', 'type' => 'textarea', 'required' => false, 'rows' => 6],
                ['key' => 'prepared_by',       'label' => 'Prepared By (Name/Position)', 'type' => 'text', 'required' => false],
            ]
        ],
        '205' => [
            'form_type'   => '205',
            'form_number' => 'ICS-205',
            'form_title'  => 'Radio Communications Plan',
            'fields' => [
                ['key' => 'incident_name',     'label' => 'Incident Name',       'type' => 'text',     'required' => true],
                ['key' => 'date_prepared',     'label' => 'Date/Time Prepared',  'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_from',    'label' => 'Operational Period From', 'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_to',      'label' => 'Operational Period To',   'type' => 'datetime-local', 'required' => true],
                ['key' => 'radio_channels',    'label' => 'Radio Channel Assignments', 'type' => 'table', 'required' => false,
                 'columns' => [
                     ['key' => 'zone_group',  'label' => 'Zone/Group',    'type' => 'text', 'width' => '100px'],
                     ['key' => 'channel',     'label' => 'Channel',       'type' => 'text', 'width' => '80px'],
                     ['key' => 'function',    'label' => 'Function',      'type' => 'text', 'width' => '120px'],
                     ['key' => 'frequency_rx','label' => 'Freq (RX)',     'type' => 'text', 'width' => '100px'],
                     ['key' => 'frequency_tx','label' => 'Freq (TX)',     'type' => 'text', 'width' => '100px'],
                     ['key' => 'tone',        'label' => 'Tone/NAC',     'type' => 'text', 'width' => '80px'],
                     ['key' => 'assignment',  'label' => 'Assignment',    'type' => 'text', 'width' => 'auto'],
                 ]
                ],
                ['key' => 'special_instructions', 'label' => 'Special Instructions', 'type' => 'textarea', 'required' => false, 'rows' => 4],
                ['key' => 'prepared_by',       'label' => 'Prepared By (Name/Position)', 'type' => 'text', 'required' => false],
            ]
        ],
        '205a' => [
            'form_type'   => '205a',
            'form_number' => 'ICS-205A',
            'form_title'  => 'Communications List',
            'fields' => [
                ['key' => 'incident_name',     'label' => 'Incident Name',       'type' => 'text',     'required' => true],
                ['key' => 'date_prepared',     'label' => 'Date/Time Prepared',  'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_from',    'label' => 'Operational Period From', 'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_to',      'label' => 'Operational Period To',   'type' => 'datetime-local', 'required' => true],
                ['key' => 'contacts',          'label' => 'Communications List', 'type' => 'table', 'required' => false,
                 'columns' => [
                     ['key' => 'position',    'label' => 'ICS Position',   'type' => 'text', 'width' => '140px'],
                     ['key' => 'name',        'label' => 'Name',           'type' => 'text', 'width' => '140px'],
                     ['key' => 'phone',       'label' => 'Phone #',        'type' => 'text', 'width' => '120px'],
                     ['key' => 'radio',       'label' => 'Radio/Freq',     'type' => 'text', 'width' => '100px'],
                     ['key' => 'email',       'label' => 'Email',          'type' => 'text', 'width' => 'auto'],
                 ]
                ],
                ['key' => 'prepared_by',       'label' => 'Prepared By (Name/Position)', 'type' => 'text', 'required' => false],
            ]
        ],
        '213rr' => [
            'form_type'   => '213rr',
            'form_number' => 'ICS-213RR',
            'form_title'  => 'Resource Request Message',
            'fields' => [
                ['key' => 'incident_name',     'label' => 'Incident Name',       'type' => 'text',     'required' => true],
                ['key' => 'date_prepared',     'label' => 'Date/Time Prepared',  'type' => 'datetime-local', 'required' => true],
                ['key' => 'to_name',           'label' => 'To (Name/Position)',  'type' => 'text',     'required' => true],
                ['key' => 'from_name',         'label' => 'From (Name/Position)','type' => 'text',     'required' => true],
                ['key' => 'resource_requests', 'label' => 'Resource Requests',   'type' => 'table',    'required' => false,
                 'columns' => [
                     ['key' => 'qty',         'label' => 'Qty',             'type' => 'text', 'width' => '60px'],
                     ['key' => 'kind',        'label' => 'Kind',            'type' => 'text', 'width' => '100px'],
                     ['key' => 'type',        'label' => 'Type',            'type' => 'text', 'width' => '80px'],
                     ['key' => 'description', 'label' => 'Item Description','type' => 'text', 'width' => 'auto'],
                     ['key' => 'arrival',     'label' => 'Requested Arrival','type' => 'text', 'width' => '120px'],
                     ['key' => 'priority',    'label' => 'Priority',        'type' => 'text', 'width' => '80px'],
                     ['key' => 'cost',        'label' => 'Est. Cost',       'type' => 'text', 'width' => '80px'],
                 ]
                ],
                ['key' => 'delivery_location', 'label' => 'Delivery Location',    'type' => 'text',     'required' => false],
                ['key' => 'substitutes',       'label' => 'Substitutes / Notes',  'type' => 'textarea', 'required' => false, 'rows' => 3],
                ['key' => 'requested_by',      'label' => 'Requested By',         'type' => 'text',     'required' => false],
                ['key' => 'approved_by',       'label' => 'Approved By (Logistics)', 'type' => 'text',  'required' => false],
                ['key' => 'approved_date',     'label' => 'Approved Date/Time',   'type' => 'datetime-local', 'required' => false],
            ]
        ],
        '206' => [
            'form_type'   => '206',
            'form_number' => 'ICS-206',
            'form_title'  => 'Medical Plan',
            'fields' => [
                ['key' => 'incident_name',     'label' => 'Incident Name',           'type' => 'text',           'required' => true],
                ['key' => 'date_prepared',     'label' => 'Date/Time Prepared',      'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_from',    'label' => 'Operational Period From',  'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_to',      'label' => 'Operational Period To',    'type' => 'datetime-local', 'required' => true],
                ['key' => 'aid_stations',      'label' => 'Medical Aid Stations',     'type' => 'table',          'required' => false,
                 'columns' => [
                     ['key' => 'name',       'label' => 'Name',           'type' => 'text', 'width' => '160px'],
                     ['key' => 'location',   'label' => 'Location',       'type' => 'text', 'width' => 'auto'],
                     ['key' => 'paramedics', 'label' => 'Paramedics Y/N', 'type' => 'text', 'width' => '100px'],
                 ]
                ],
                ['key' => 'transportation',    'label' => 'Transportation',           'type' => 'table',          'required' => false,
                 'columns' => [
                     ['key' => 'service',    'label' => 'Ambulance Service', 'type' => 'text', 'width' => '160px'],
                     ['key' => 'address',    'label' => 'Address',           'type' => 'text', 'width' => 'auto'],
                     ['key' => 'phone',      'label' => 'Phone',             'type' => 'text', 'width' => '120px'],
                     ['key' => 'paramedics', 'label' => 'Paramedics Y/N',   'type' => 'text', 'width' => '100px'],
                 ]
                ],
                ['key' => 'hospitals',         'label' => 'Hospitals',                'type' => 'table',          'required' => false,
                 'columns' => [
                     ['key' => 'name',         'label' => 'Name',              'type' => 'text', 'width' => '140px'],
                     ['key' => 'address',      'label' => 'Address',           'type' => 'text', 'width' => 'auto'],
                     ['key' => 'phone',        'label' => 'Phone',             'type' => 'text', 'width' => '110px'],
                     ['key' => 'travel_time',  'label' => 'Travel Time',       'type' => 'text', 'width' => '80px'],
                     ['key' => 'trauma_level', 'label' => 'Trauma Ctr Level',  'type' => 'text', 'width' => '90px'],
                     ['key' => 'helipad',      'label' => 'Helipad Y/N',       'type' => 'text', 'width' => '80px'],
                 ]
                ],
                ['key' => 'medical_procedures', 'label' => 'Medical Emergency Procedures', 'type' => 'textarea', 'required' => false, 'rows' => 6],
                ['key' => 'prepared_by',       'label' => 'Prepared By (Name/Position)',   'type' => 'text',     'required' => false],
            ]
        ],
        '214a' => [
            'form_type'   => '214a',
            'form_number' => 'ICS-214a',
            'form_title'  => 'Individual Activity Log',
            'fields' => [
                ['key' => 'incident_name',     'label' => 'Incident Name',           'type' => 'text',           'required' => true],
                ['key' => 'date_prepared',     'label' => 'Date Prepared',           'type' => 'date',           'required' => true],
                ['key' => 'op_period_from',    'label' => 'Operational Period From',  'type' => 'datetime-local', 'required' => true],
                ['key' => 'op_period_to',      'label' => 'Operational Period To',    'type' => 'datetime-local', 'required' => true],
                ['key' => 'individual_name',   'label' => 'Name',                    'type' => 'text',           'required' => true],
                ['key' => 'ics_position',      'label' => 'ICS Position',            'type' => 'text',           'required' => false],
                ['key' => 'home_agency',       'label' => 'Home Agency',             'type' => 'text',           'required' => false],
                ['key' => 'activity_log',      'label' => 'Activity Log',            'type' => 'table',          'required' => false,
                 'columns' => [
                     ['key' => 'time',     'label' => 'Time',               'type' => 'time',  'width' => '120px'],
                     ['key' => 'activity', 'label' => 'Notable Activities', 'type' => 'text',  'width' => 'auto']
                 ]
                ],
                ['key' => 'prepared_by',       'label' => 'Prepared By',             'type' => 'text',           'required' => false],
            ]
        ],
        '221' => [
            'form_type'   => '221',
            'form_number' => 'ICS-221',
            'form_title'  => 'Demobilization Check-Out',
            'fields' => [
                ['key' => 'incident_name',     'label' => 'Incident Name/Number',    'type' => 'text',           'required' => true],
                ['key' => 'date_time',         'label' => 'Date/Time',               'type' => 'datetime-local', 'required' => true],
                ['key' => 'resource_unit_id',  'label' => 'Resource/Unit ID',        'type' => 'text',           'required' => true],
                ['key' => 'leader_name',       'label' => 'Leader Name',             'type' => 'text',           'required' => true],
                ['key' => 'chk_logistics',     'label' => 'Logistics Section',       'type' => 'text',           'required' => false],
                ['key' => 'chk_supply',        'label' => 'Supply Unit',             'type' => 'text',           'required' => false],
                ['key' => 'chk_communications','label' => 'Communications Unit',     'type' => 'text',           'required' => false],
                ['key' => 'chk_facilities',    'label' => 'Facilities Unit',         'type' => 'text',           'required' => false],
                ['key' => 'chk_ground_support','label' => 'Ground Support Unit',     'type' => 'text',           'required' => false],
                ['key' => 'reassign_destination', 'label' => 'Reassignment Destination', 'type' => 'text',      'required' => false],
                ['key' => 'reassign_eta',      'label' => 'Reassignment ETA',        'type' => 'text',           'required' => false],
                ['key' => 'reassign_area',     'label' => 'Area/Agency/Region',      'type' => 'text',           'required' => false],
                ['key' => 'travel_room',       'label' => 'Room Reservations',       'type' => 'text',           'required' => false],
                ['key' => 'travel_method',     'label' => 'Travel Method',           'type' => 'text',           'required' => false],
                ['key' => 'travel_contact',    'label' => 'Contact Information',     'type' => 'text',           'required' => false],
                ['key' => 'remarks',           'label' => 'Remarks',                 'type' => 'textarea',       'required' => false, 'rows' => 4],
                ['key' => 'prepared_by',       'label' => 'Prepared By',             'type' => 'text',           'required' => false],
                ['key' => 'approved_unit_leader', 'label' => 'Approved By (Unit Leader)', 'type' => 'text',     'required' => false],
                ['key' => 'approved_planning', 'label' => 'Approved By (Planning Section)', 'type' => 'text',   'required' => false],
                ['key' => 'approved_logistics','label' => 'Approved By (Logistics)',  'type' => 'text',          'required' => false],
            ]
        ],
    ];

    return isset($templates[$type]) ? $templates[$type] : null;
}

/**
 * Generate Winlink-compatible ICS-213 XML.
 */
/**
 * Phase 18f (2026-06-11) — Wrap an exported ICS form HTML with a
 * diagonal watermark per the incident's security label, and optionally
 * redact scope/address-like fields if the label has
 * ics_export_show_full=0. The watermark literal is the
 * ics_watermark_text column; if empty, no watermark.
 */
function _ics_apply_security_wrap(string $html, array $sec): string {
    $watermark = (string) ($sec['ics_watermark_text'] ?? '');
    $showFull  = (int) ($sec['ics_export_show_full'] ?? 1) === 1;

    if (!$showFull) {
        // Phase 73r — was targeting <td class="form-field">…</td> but
        // generatePrintHtml emits <span class="value">…</span> instead,
        // so the redactor silently no-op'd while the watermark made it
        // look like content was protected. Fix: match the actual
        // class. Also redact <td>…</td> blocks that are message-body
        // only (no .label child) since ICS-213's free-text panes don't
        // wrap their content in a .value span at all.
        $name = trim((string) ($sec['name'] ?? ''));
        if ($name === '') $name = 'Restricted';
        $rep = '<span style="background:#dc3545;color:#fff;padding:2px 6px;border-radius:3px;">*** ' .
               htmlspecialchars($name) . ' ***</span>';
        // Replace any .value span content. /s for cross-line match.
        $html = preg_replace(
            '/<span class="value"[^>]*>.*?<\/span>/s',
            '<span class="value">' . $rep . '</span>',
            $html
        );
        // Also redact pure free-text td cells (message body, reply body
        // — wide cells with colspan that printICS213 emits without an
        // inner .value span on some form types).
        $html = preg_replace(
            '/<td colspan="2"[^>]*style="min-height:[^"]*"[^>]*>.*?<\/td>/s',
            '<td colspan="2" style="min-height:60px">' . $rep . '</td>',
            $html
        );
    }

    if ($watermark !== '') {
        // CSS-based diagonal watermark across the page.
        $css = '<style>.p18-watermark{position:fixed;top:0;left:0;width:100vw;height:100vh;display:flex;align-items:center;justify-content:center;font-size:9rem;color:rgba(220,53,69,0.18);font-weight:700;transform:rotate(-32deg);pointer-events:none;z-index:9999;white-space:nowrap;}</style>';
        $div = '<div class="p18-watermark">' . htmlspecialchars($watermark) . '</div>';
        // Insert into <body> if present; else prepend.
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</head>', $css . '</head>', $html);
            $html = str_ireplace('<body', '<body data-p18="' . htmlspecialchars($watermark) . '"', $html);
            $html = str_ireplace('</body>', $div . '</body>', $html);
        } else {
            $html = $css . $div . $html;
        }
    }
    return $html;
}

function generateICS213Xml($data, $row) {
    $now = date('Y-m-d H:i');

    $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    $xml .= '<RMS_Express_Form>' . "\n";
    $xml .= '  <form_parameters>' . "\n";
    $xml .= '    <xml_file_version>1.0</xml_file_version>' . "\n";
    $xml .= '    <rms_express_version>TicketsCAD_v4</rms_express_version>' . "\n";
    $xml .= '    <submission_datetime>' . xs($now) . '</submission_datetime>' . "\n";
    $xml .= '    <senders_callsign></senders_callsign>' . "\n";
    $xml .= '    <grid_square></grid_square>' . "\n";
    $xml .= '    <display_form>ICS213_Viewer.html</display_form>' . "\n";
    $xml .= '  </form_parameters>' . "\n";
    $xml .= '  <variables>' . "\n";
    $xml .= '    <msgseqnum></msgseqnum>' . "\n";
    $xml .= '    <incidentname>' . xs($row['title'] ?? '') . '</incidentname>' . "\n";
    $xml .= '    <datetime>' . xs(($data['date'] ?? '') . ' ' . ($data['time'] ?? '')) . '</datetime>' . "\n";
    $xml .= '    <to>' . xs($data['to_name'] ?? '') . '</to>' . "\n";
    $xml .= '    <toposition>' . xs($data['to_position'] ?? '') . '</toposition>' . "\n";
    $xml .= '    <from>' . xs($data['from_name'] ?? '') . '</from>' . "\n";
    $xml .= '    <fromposition>' . xs($data['from_position'] ?? '') . '</fromposition>' . "\n";
    $xml .= '    <subject>' . xs($data['subject'] ?? '') . '</subject>' . "\n";
    $xml .= '    <message>' . xs($data['message'] ?? '') . '</message>' . "\n";
    $xml .= '    <approved_by>' . xs($data['approved_name'] ?? '') . '</approved_by>' . "\n";
    $xml .= '    <approved_position>' . xs($data['approved_position'] ?? '') . '</approved_position>' . "\n";
    $xml .= '    <approved_datetime></approved_datetime>' . "\n";
    $xml .= '    <reply>' . xs($data['reply'] ?? '') . '</reply>' . "\n";
    $xml .= '    <reply_by>' . xs($data['reply_by'] ?? '') . '</reply_by>' . "\n";
    $xml .= '    <reply_position></reply_position>' . "\n";
    $xml .= '    <reply_datetime>' . xs(($data['reply_date'] ?? '') . ' ' . ($data['reply_time'] ?? '')) . '</reply_datetime>' . "\n";
    $xml .= '  </variables>' . "\n";
    $xml .= '</RMS_Express_Form>' . "\n";

    return $xml;
}

/** XML-safe string escaping */
function xs($str) {
    return htmlspecialchars($str ?? '', ENT_XML1, 'UTF-8');
}

/**
 * Generate print-optimized HTML for any ICS form type.
 */
function generatePrintHtml($formType, $data, $row) {
    $title = strtoupper('ICS-' . $formType);
    $names = [
        '213'   => 'GENERAL MESSAGE',
        '214'   => 'ACTIVITY LOG',
        '202'   => 'INCIDENT OBJECTIVES',
        '205'   => 'RADIO COMMUNICATIONS PLAN',
        '205a'  => 'COMMUNICATIONS LIST',
        '213rr' => 'RESOURCE REQUEST MESSAGE',
        '206'   => 'MEDICAL PLAN',
        '214a'  => 'INDIVIDUAL ACTIVITY LOG',
        '221'   => 'DEMOBILIZATION CHECK-OUT',
    ];
    $formName = $names[$formType] ?? $formType;

    $html = '<!DOCTYPE html><html><head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<title>' . $title . ' - ' . htmlspecialchars($row['title'] ?? '') . '</title>';
    $html .= '<style>';
    $html .= 'body{font-family:Arial,sans-serif;font-size:10pt;color:#000;margin:0.5in;background:#fff}';
    $html .= 'h1{text-align:center;font-size:14pt;margin:0 0 4px}';
    $html .= 'h2{text-align:center;font-size:11pt;margin:0 0 12px;font-weight:normal}';
    $html .= 'table{width:100%;border-collapse:collapse;margin-bottom:12px}';
    $html .= 'td,th{border:1px solid #000;padding:4px 6px;vertical-align:top;font-size:9pt}';
    $html .= 'th{background:#f0f0f0;font-weight:bold;text-align:left}';
    $html .= '.label{font-size:7pt;font-weight:bold;color:#333;display:block;margin-bottom:2px}';
    $html .= '.value{min-height:18px}';
    $html .= '.footer{margin-top:20px;font-size:8pt;color:#666;text-align:center}';
    $html .= '@media print{body{margin:0.3in}@page{size:letter;margin:0.5in}}';
    $html .= '</style></head><body>';
    $html .= '<h1>' . $title . '</h1>';
    $html .= '<h2>' . $formName . '</h2>';

    switch ($formType) {
        case '213':
            $html .= printICS213($data);
            break;
        case '214':
            $html .= printICS214($data);
            break;
        case '202':
            $html .= printICS202($data);
            break;
        case '205':
            $html .= printICS205($data);
            break;
        case '205a':
            $html .= printICS205A($data);
            break;
        case '213rr':
            $html .= printICS213RR($data);
            break;
        case '206':
            $html .= printICS206($data);
            break;
        case '214a':
            $html .= printICS214A($data);
            break;
        case '221':
            $html .= printICS221($data);
            break;
    }

    $html .= '<div class="footer">Generated by Tickets CAD v4 &mdash; ' . date('Y-m-d H:i') . '</div>';
    $html .= '</body></html>';
    return $html;
}

function pv($data, $key) {
    return htmlspecialchars($data[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

function printICS213($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">TO:</span><span class="value">' . pv($d,'to_name') . '</span></td>';
    $h .= '<td><span class="label">POSITION:</span><span class="value">' . pv($d,'to_position') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">FROM:</span><span class="value">' . pv($d,'from_name') . '</span></td>';
    $h .= '<td><span class="label">POSITION:</span><span class="value">' . pv($d,'from_position') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">SUBJECT:</span><span class="value">' . pv($d,'subject') . '</span></td>';
    $h .= '<td style="width:30%"><span class="label">DATE/TIME:</span><span class="value">' . pv($d,'date') . ' ' . pv($d,'time') . '</span></td></tr>';
    $h .= '<tr><th colspan="2" style="background:#e5e5e5">MESSAGE:</th></tr>';
    $h .= '<tr><td colspan="2" style="min-height:100px"><span class="value">' . nl2br(pv($d,'message')) . '</span></td></tr>';
    $h .= '<tr><td><span class="label">APPROVED BY:</span><span class="value">' . pv($d,'approved_name') . '</span></td>';
    $h .= '<td><span class="label">POSITION:</span><span class="value">' . pv($d,'approved_position') . '</span></td></tr>';
    $h .= '<tr><th colspan="2" style="background:#e5e5e5">REPLY:</th></tr>';
    $h .= '<tr><td colspan="2" style="min-height:80px"><span class="value">' . nl2br(pv($d,'reply')) . '</span></td></tr>';
    $h .= '<tr><td><span class="label">REPLY DATE/TIME:</span><span class="value">' . pv($d,'reply_date') . ' ' . pv($d,'reply_time') . '</span></td>';
    $h .= '<td><span class="label">SIGNATURE/POSITION:</span><span class="value">' . pv($d,'reply_by') . '</span></td></tr>';
    $h .= '</table>';
    return $h;
}

function printICS214($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">INCIDENT NAME:</span><span class="value">' . pv($d,'incident_name') . '</span></td>';
    $h .= '<td><span class="label">DATE PREPARED:</span><span class="value">' . pv($d,'date_prepared') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">OPERATIONAL PERIOD FROM:</span><span class="value">' . pv($d,'op_period_from') . '</span></td>';
    $h .= '<td><span class="label">OPERATIONAL PERIOD TO:</span><span class="value">' . pv($d,'op_period_to') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">TEAM NAME/NUMBER:</span><span class="value">' . pv($d,'team_name') . '</span></td>';
    $h .= '<td><span class="label">TEAM LEADER:</span><span class="value">' . pv($d,'team_leader_name') . ' ' . pv($d,'team_leader_position') . '</span></td></tr>';
    $h .= '</table>';
    $h .= '<table><tr><th style="width:120px">Time</th><th>Notable Activities</th></tr>';
    $log = isset($d['activity_log']) ? $d['activity_log'] : [];
    if (!empty($log)) {
        foreach ($log as $entry) {
            $h .= '<tr><td>' . htmlspecialchars($entry['time'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $h .= '<td>' . htmlspecialchars($entry['activity'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
    } else {
        for ($i = 0; $i < 15; $i++) {
            $h .= '<tr><td style="height:22px">&nbsp;</td><td>&nbsp;</td></tr>';
        }
    }
    $h .= '</table>';
    $h .= '<table><tr><td><span class="label">PREPARED BY:</span><span class="value">' . pv($d,'prepared_by') . '</span></td></tr></table>';
    return $h;
}

function printICS202($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">INCIDENT NAME:</span><span class="value">' . pv($d,'incident_name') . '</span></td>';
    $h .= '<td><span class="label">DATE/TIME PREPARED:</span><span class="value">' . pv($d,'date_prepared') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">OPERATIONAL PERIOD FROM:</span><span class="value">' . pv($d,'op_period_from') . '</span></td>';
    $h .= '<td><span class="label">OPERATIONAL PERIOD TO:</span><span class="value">' . pv($d,'op_period_to') . '</span></td></tr>';
    $h .= '</table>';
    $h .= '<table><tr><th>OBJECTIVE(S):</th></tr>';
    $h .= '<tr><td style="min-height:150px">' . nl2br(pv($d,'objectives')) . '</td></tr></table>';
    $h .= '<table><tr><th>SUMMARY OF CURRENT ACTIONS:</th></tr>';
    $h .= '<tr><td style="min-height:120px">' . nl2br(pv($d,'current_actions')) . '</td></tr></table>';
    $h .= '<table><tr><td><span class="label">PREPARED BY:</span><span class="value">' . pv($d,'prepared_by') . '</span></td></tr></table>';
    return $h;
}

function printICS205($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">INCIDENT NAME:</span><span class="value">' . pv($d,'incident_name') . '</span></td>';
    $h .= '<td><span class="label">DATE/TIME PREPARED:</span><span class="value">' . pv($d,'date_prepared') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">OPERATIONAL PERIOD FROM:</span><span class="value">' . pv($d,'op_period_from') . '</span></td>';
    $h .= '<td><span class="label">OPERATIONAL PERIOD TO:</span><span class="value">' . pv($d,'op_period_to') . '</span></td></tr>';
    $h .= '</table>';
    $h .= '<table><tr><th>Zone/Group</th><th>Channel</th><th>Function</th><th>Freq (RX)</th><th>Freq (TX)</th><th>Tone/NAC</th><th>Assignment</th></tr>';
    $channels = isset($d['radio_channels']) ? $d['radio_channels'] : [];
    if (!empty($channels)) {
        foreach ($channels as $ch) {
            $h .= '<tr>';
            foreach (['zone_group','channel','function','frequency_rx','frequency_tx','tone','assignment'] as $k) {
                $h .= '<td>' . htmlspecialchars($ch[$k] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $h .= '</tr>';
        }
    } else {
        for ($i = 0; $i < 10; $i++) {
            $h .= '<tr>' . str_repeat('<td style="height:22px">&nbsp;</td>', 7) . '</tr>';
        }
    }
    $h .= '</table>';
    $h .= '<table><tr><th>SPECIAL INSTRUCTIONS:</th></tr>';
    $h .= '<tr><td style="min-height:60px">' . nl2br(pv($d,'special_instructions')) . '</td></tr></table>';
    $h .= '<table><tr><td><span class="label">PREPARED BY:</span><span class="value">' . pv($d,'prepared_by') . '</span></td></tr></table>';
    return $h;
}

function printICS205A($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">INCIDENT NAME:</span><span class="value">' . pv($d,'incident_name') . '</span></td>';
    $h .= '<td><span class="label">DATE/TIME PREPARED:</span><span class="value">' . pv($d,'date_prepared') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">OPERATIONAL PERIOD FROM:</span><span class="value">' . pv($d,'op_period_from') . '</span></td>';
    $h .= '<td><span class="label">OPERATIONAL PERIOD TO:</span><span class="value">' . pv($d,'op_period_to') . '</span></td></tr>';
    $h .= '</table>';
    $h .= '<table><tr><th>ICS Position</th><th>Name</th><th>Phone #</th><th>Radio/Freq</th><th>Email</th></tr>';
    $contacts = isset($d['contacts']) ? $d['contacts'] : [];
    if (!empty($contacts)) {
        foreach ($contacts as $c) {
            $h .= '<tr>';
            foreach (['position','name','phone','radio','email'] as $k) {
                $h .= '<td>' . htmlspecialchars($c[$k] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $h .= '</tr>';
        }
    } else {
        for ($i = 0; $i < 12; $i++) {
            $h .= '<tr>' . str_repeat('<td style="height:22px">&nbsp;</td>', 5) . '</tr>';
        }
    }
    $h .= '</table>';
    $h .= '<table><tr><td><span class="label">PREPARED BY:</span><span class="value">' . pv($d,'prepared_by') . '</span></td></tr></table>';
    return $h;
}

function printICS213RR($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">INCIDENT NAME:</span><span class="value">' . pv($d,'incident_name') . '</span></td>';
    $h .= '<td><span class="label">DATE/TIME PREPARED:</span><span class="value">' . pv($d,'date_prepared') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">TO:</span><span class="value">' . pv($d,'to_name') . '</span></td>';
    $h .= '<td><span class="label">FROM:</span><span class="value">' . pv($d,'from_name') . '</span></td></tr>';
    $h .= '</table>';
    $h .= '<table><tr><th>Qty</th><th>Kind</th><th>Type</th><th>Item Description</th><th>Requested Arrival</th><th>Priority</th><th>Est. Cost</th></tr>';
    $reqs = isset($d['resource_requests']) ? $d['resource_requests'] : [];
    if (!empty($reqs)) {
        foreach ($reqs as $r) {
            $h .= '<tr>';
            foreach (['qty','kind','type','description','arrival','priority','cost'] as $k) {
                $h .= '<td>' . htmlspecialchars($r[$k] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $h .= '</tr>';
        }
    } else {
        for ($i = 0; $i < 8; $i++) {
            $h .= '<tr>' . str_repeat('<td style="height:22px">&nbsp;</td>', 7) . '</tr>';
        }
    }
    $h .= '</table>';
    $h .= '<table>';
    $h .= '<tr><td><span class="label">DELIVERY LOCATION:</span><span class="value">' . pv($d,'delivery_location') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">SUBSTITUTES / NOTES:</span><span class="value">' . nl2br(pv($d,'substitutes')) . '</span></td></tr>';
    $h .= '<tr><td style="width:50%"><span class="label">REQUESTED BY:</span><span class="value">' . pv($d,'requested_by') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">APPROVED BY (LOGISTICS):</span><span class="value">' . pv($d,'approved_by') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">APPROVED DATE/TIME:</span><span class="value">' . pv($d,'approved_date') . '</span></td></tr>';
    $h .= '</table>';
    return $h;
}

function printICS206($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">INCIDENT NAME:</span><span class="value">' . pv($d,'incident_name') . '</span></td>';
    $h .= '<td><span class="label">DATE/TIME PREPARED:</span><span class="value">' . pv($d,'date_prepared') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">OPERATIONAL PERIOD FROM:</span><span class="value">' . pv($d,'op_period_from') . '</span></td>';
    $h .= '<td><span class="label">OPERATIONAL PERIOD TO:</span><span class="value">' . pv($d,'op_period_to') . '</span></td></tr>';
    $h .= '</table>';

    // Medical Aid Stations
    $h .= '<table><tr><th colspan="3" style="background:#e5e5e5">MEDICAL AID STATIONS</th></tr>';
    $h .= '<tr><th>Name</th><th>Location</th><th style="width:100px">Paramedics Y/N</th></tr>';
    $stations = isset($d['aid_stations']) ? $d['aid_stations'] : [];
    if (!empty($stations)) {
        foreach ($stations as $s) {
            $h .= '<tr>';
            foreach (['name','location','paramedics'] as $k) {
                $h .= '<td>' . htmlspecialchars($s[$k] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $h .= '</tr>';
        }
    } else {
        for ($i = 0; $i < 4; $i++) {
            $h .= '<tr>' . str_repeat('<td style="height:22px">&nbsp;</td>', 3) . '</tr>';
        }
    }
    $h .= '</table>';

    // Transportation
    $h .= '<table><tr><th colspan="4" style="background:#e5e5e5">TRANSPORTATION</th></tr>';
    $h .= '<tr><th>Ambulance Service</th><th>Address</th><th>Phone</th><th style="width:100px">Paramedics Y/N</th></tr>';
    $transport = isset($d['transportation']) ? $d['transportation'] : [];
    if (!empty($transport)) {
        foreach ($transport as $t) {
            $h .= '<tr>';
            foreach (['service','address','phone','paramedics'] as $k) {
                $h .= '<td>' . htmlspecialchars($t[$k] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $h .= '</tr>';
        }
    } else {
        for ($i = 0; $i < 4; $i++) {
            $h .= '<tr>' . str_repeat('<td style="height:22px">&nbsp;</td>', 4) . '</tr>';
        }
    }
    $h .= '</table>';

    // Hospitals
    $h .= '<table><tr><th colspan="6" style="background:#e5e5e5">HOSPITALS</th></tr>';
    $h .= '<tr><th>Name</th><th>Address</th><th>Phone</th><th>Travel Time</th><th>Trauma Ctr Level</th><th>Helipad Y/N</th></tr>';
    $hospitals = isset($d['hospitals']) ? $d['hospitals'] : [];
    if (!empty($hospitals)) {
        foreach ($hospitals as $hosp) {
            $h .= '<tr>';
            foreach (['name','address','phone','travel_time','trauma_level','helipad'] as $k) {
                $h .= '<td>' . htmlspecialchars($hosp[$k] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $h .= '</tr>';
        }
    } else {
        for ($i = 0; $i < 4; $i++) {
            $h .= '<tr>' . str_repeat('<td style="height:22px">&nbsp;</td>', 6) . '</tr>';
        }
    }
    $h .= '</table>';

    // Medical Emergency Procedures
    $h .= '<table><tr><th>MEDICAL EMERGENCY PROCEDURES:</th></tr>';
    $h .= '<tr><td style="min-height:100px">' . nl2br(pv($d,'medical_procedures')) . '</td></tr></table>';

    $h .= '<table><tr><td><span class="label">PREPARED BY:</span><span class="value">' . pv($d,'prepared_by') . '</span></td></tr></table>';
    return $h;
}

function printICS214A($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">INCIDENT NAME:</span><span class="value">' . pv($d,'incident_name') . '</span></td>';
    $h .= '<td><span class="label">DATE PREPARED:</span><span class="value">' . pv($d,'date_prepared') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">OPERATIONAL PERIOD FROM:</span><span class="value">' . pv($d,'op_period_from') . '</span></td>';
    $h .= '<td><span class="label">OPERATIONAL PERIOD TO:</span><span class="value">' . pv($d,'op_period_to') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">NAME:</span><span class="value">' . pv($d,'individual_name') . '</span></td>';
    $h .= '<td><span class="label">ICS POSITION:</span><span class="value">' . pv($d,'ics_position') . '</span></td></tr>';
    $h .= '<tr><td colspan="2"><span class="label">HOME AGENCY:</span><span class="value">' . pv($d,'home_agency') . '</span></td></tr>';
    $h .= '</table>';

    $h .= '<table><tr><th style="width:120px">Time</th><th>Notable Activities</th></tr>';
    $log = isset($d['activity_log']) ? $d['activity_log'] : [];
    if (!empty($log)) {
        foreach ($log as $entry) {
            $h .= '<tr><td>' . htmlspecialchars($entry['time'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $h .= '<td>' . htmlspecialchars($entry['activity'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
    } else {
        for ($i = 0; $i < 15; $i++) {
            $h .= '<tr><td style="height:22px">&nbsp;</td><td>&nbsp;</td></tr>';
        }
    }
    $h .= '</table>';

    $h .= '<table><tr><td><span class="label">PREPARED BY:</span><span class="value">' . pv($d,'prepared_by') . '</span></td></tr></table>';
    return $h;
}

function printICS221($d) {
    $h  = '<table>';
    $h .= '<tr><td style="width:50%"><span class="label">INCIDENT NAME/NUMBER:</span><span class="value">' . pv($d,'incident_name') . '</span></td>';
    $h .= '<td><span class="label">DATE/TIME:</span><span class="value">' . pv($d,'date_time') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">RESOURCE/UNIT ID:</span><span class="value">' . pv($d,'resource_unit_id') . '</span></td>';
    $h .= '<td><span class="label">LEADER NAME:</span><span class="value">' . pv($d,'leader_name') . '</span></td></tr>';
    $h .= '</table>';

    // Demob section checkboxes
    $h .= '<table><tr><th colspan="2" style="background:#e5e5e5">DEMOBILIZATION SECTION CHECK-OUT</th></tr>';
    $checks = [
        'chk_logistics'      => 'Logistics Section',
        'chk_supply'         => 'Supply Unit',
        'chk_communications' => 'Communications Unit',
        'chk_facilities'     => 'Facilities Unit',
        'chk_ground_support' => 'Ground Support Unit',
    ];
    foreach ($checks as $key => $label) {
        $val = isset($d[$key]) && $d[$key] ? $d[$key] : '';
        $mark = ($val !== '' && strtolower($val) !== 'n' && strtolower($val) !== 'no') ? '&#9745;' : '&#9744;';
        $h .= '<tr><td style="width:30px;text-align:center">' . $mark . '</td>';
        $h .= '<td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if ($val !== '' && strtolower($val) !== 'y' && strtolower($val) !== 'yes' && strtolower($val) !== 'n' && strtolower($val) !== 'no') {
            $h .= ' <em>(' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . ')</em>';
        }
        $h .= '</td></tr>';
    }
    $h .= '</table>';

    // Reassignment
    $h .= '<table><tr><th colspan="2" style="background:#e5e5e5">REASSIGNMENT INFORMATION</th></tr>';
    $h .= '<tr><td style="width:50%"><span class="label">DESTINATION:</span><span class="value">' . pv($d,'reassign_destination') . '</span></td>';
    $h .= '<td><span class="label">ETA:</span><span class="value">' . pv($d,'reassign_eta') . '</span></td></tr>';
    $h .= '<tr><td colspan="2"><span class="label">AREA/AGENCY/REGION:</span><span class="value">' . pv($d,'reassign_area') . '</span></td></tr>';
    $h .= '</table>';

    // Travel info
    $h .= '<table><tr><th colspan="2" style="background:#e5e5e5">TRAVEL INFORMATION</th></tr>';
    $h .= '<tr><td style="width:50%"><span class="label">ROOM RESERVATIONS:</span><span class="value">' . pv($d,'travel_room') . '</span></td>';
    $h .= '<td><span class="label">TRAVEL METHOD:</span><span class="value">' . pv($d,'travel_method') . '</span></td></tr>';
    $h .= '<tr><td colspan="2"><span class="label">CONTACT INFORMATION:</span><span class="value">' . pv($d,'travel_contact') . '</span></td></tr>';
    $h .= '</table>';

    // Remarks
    $h .= '<table><tr><th>REMARKS:</th></tr>';
    $h .= '<tr><td style="min-height:60px">' . nl2br(pv($d,'remarks')) . '</td></tr></table>';

    // Signatures
    $h .= '<table>';
    $h .= '<tr><td><span class="label">PREPARED BY:</span><span class="value">' . pv($d,'prepared_by') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">APPROVED BY (UNIT LEADER):</span><span class="value">' . pv($d,'approved_unit_leader') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">APPROVED BY (PLANNING SECTION):</span><span class="value">' . pv($d,'approved_planning') . '</span></td></tr>';
    $h .= '<tr><td><span class="label">APPROVED BY (LOGISTICS):</span><span class="value">' . pv($d,'approved_logistics') . '</span></td></tr>';
    $h .= '</table>';
    return $h;
}
