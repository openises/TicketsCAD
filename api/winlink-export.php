<?php
/**
 * NewUI v4.0 API - Winlink ICS Form XML Export
 *
 * GET /api/winlink-export.php?form=ics213&ticket_id=X
 *   Generates a Winlink-compatible XML data file for the specified ICS form,
 *   pre-populated with incident data. User downloads the XML and imports it
 *   into Winlink Express or Pat for radio transmission.
 *
 * Supported forms: ics213 (General Message)
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') json_error('Method not allowed', 405);

$form = $_GET['form'] ?? '';
$ticketId = intval($_GET['ticket_id'] ?? 0);

if (!$form) json_error('form parameter required (e.g., ics213)');

// IDOR — exporting incident data via Winlink ICS-213 is a sensitive
// operation; non-admins must be in a group allocated to the ticket.
if ($ticketId && !user_can_access_entity('incident', $ticketId)) {
    json_error('Incident not found', 404);
}

// Load incident data if ticket_id provided
$incident = null;
if ($ticketId) {
    try {
        $incident = db_fetch_one(
            "SELECT t.*, it.name AS incident_type_name
             FROM " . db_table('ticket') . " t
             LEFT JOIN " . db_table('in_types') . " it ON t.in_types_id = it.id
             WHERE t.id = ?",
            [$ticketId]
        );
    } catch (Exception $e) {
        $incident = null;
    }
}

// Load settings for org info
$orgName = '';
try {
    $orgName = db_fetch_value("SELECT `value` FROM " . db_table('settings') . " WHERE `name` = 'area_title'") ?: '';
} catch (Exception $e) {}

if ($form === 'ics213') {
    $xml = generateICS213($incident, $orgName);
    $filename = 'RMS_Express_Form_ICS213_' . ($ticketId ?: 'blank') . '.xml';

    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $xml;
    exit;
}

json_error('Unsupported form type: ' . $form . '. Supported: ics213');

/**
 * Generate ICS-213 General Message XML in Winlink format
 */
function generateICS213($incident, $orgName) {
    $now = date('Y-m-d H:i');
    $user = $_SESSION['user'] ?? 'dispatch';

    // Pre-populate from incident if available
    $incidentName = '';
    $subject = '';
    $body = '';
    $to = '';
    $from = $orgName ?: 'Dispatch';
    $dateTime = $now;

    if ($incident) {
        // Phase 99p — prefer the case number over the internal id.
        $incidentName = $incident['scope'] ?? ('Incident ' . ($incident['incident_number'] ?? ('#' . $incident['id'])));
        $subject = ($incident['incident_type_name'] ?? 'Incident') . ' - ' . $incidentName;
        $body = trim(
            "Type: " . ($incident['incident_type_name'] ?? '') . "\n" .
            "Location: " . trim(($incident['street'] ?? '') . ', ' . ($incident['city'] ?? '')) . "\n" .
            "Severity: " . ($incident['severity'] ?? '') . "\n" .
            "Status: " . ($incident['curstat'] ?? '') . "\n" .
            ($incident['description'] ? "Description: " . $incident['description'] . "\n" : '')
        );
        $dateTime = $incident['date'] ?? $now;
    }

    $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    $xml .= '<RMS_Express_Form>' . "\n";
    $xml .= '  <form_parameters>' . "\n";
    $xml .= '    <xml_file_version>1.0</xml_file_version>' . "\n";
    $xml .= '    <rms_express_version>TicketsCAD_v4</rms_express_version>' . "\n";
    $xml .= '    <submission_datetime>' . xmlSafe($now) . '</submission_datetime>' . "\n";
    $xml .= '    <senders_callsign></senders_callsign>' . "\n";
    $xml .= '    <grid_square></grid_square>' . "\n";
    $xml .= '    <display_form>ICS213_Viewer.html</display_form>' . "\n";
    $xml .= '  </form_parameters>' . "\n";
    $xml .= '  <variables>' . "\n";
    $xml .= '    <msgseqnum></msgseqnum>' . "\n";
    $xml .= '    <incidentname>' . xmlSafe($incidentName) . '</incidentname>' . "\n";
    $xml .= '    <datetime>' . xmlSafe($dateTime) . '</datetime>' . "\n";
    $xml .= '    <to>' . xmlSafe($to) . '</to>' . "\n";
    $xml .= '    <toposition></toposition>' . "\n";
    $xml .= '    <from>' . xmlSafe($from) . '</from>' . "\n";
    $xml .= '    <fromposition>' . xmlSafe($user) . '</fromposition>' . "\n";
    $xml .= '    <subject>' . xmlSafe($subject) . '</subject>' . "\n";
    $xml .= '    <message>' . xmlSafe($body) . '</message>' . "\n";
    $xml .= '    <approved_by></approved_by>' . "\n";
    $xml .= '    <approved_position></approved_position>' . "\n";
    $xml .= '    <approved_datetime></approved_datetime>' . "\n";
    $xml .= '    <reply></reply>' . "\n";
    $xml .= '    <reply_by></reply_by>' . "\n";
    $xml .= '    <reply_position></reply_position>' . "\n";
    $xml .= '    <reply_datetime></reply_datetime>' . "\n";
    $xml .= '  </variables>' . "\n";
    $xml .= '</RMS_Express_Form>' . "\n";

    return $xml;
}

function xmlSafe($str) {
    return htmlspecialchars($str ?? '', ENT_XML1, 'UTF-8');
}
