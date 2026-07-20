<?php
/**
 * NewUI v4.0 API — Personal ICS-214 (Activity Log) PAR export.
 *
 * Builds an ICS-214 activity log timeline for a single responder
 * from three sources:
 *
 *   1. par_unit_acks    — every PAR check-in this responder logged
 *   2. assigns          — the dispatch/respond/on-scene/clear stamps
 *                         on incidents this responder was assigned to
 *   3. action (log)     — entries authored by this responder's user
 *
 * Returns JSON (default) or Winlink-compatible ICS-214 XML.
 *
 *   GET /api/ics214-par-export.php?responder_id=X[&from=YYYY-MM-DD][&to=YYYY-MM-DD][&format=xml]
 *   GET /api/ics214-par-export.php?responder_id=X&ticket_id=Y[&format=xml]
 *
 * Phase 26A (2026-06-11).
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed', 405);

$prefix      = $GLOBALS['db_prefix'] ?? '';
$responderId = (int) ($_GET['responder_id'] ?? 0);
$ticketId    = (int) ($_GET['ticket_id'] ?? 0);
$from        = trim($_GET['from'] ?? '');
$to          = trim($_GET['to'] ?? '');
$format      = strtolower(trim($_GET['format'] ?? 'json'));

if (!$responderId) json_error('responder_id is required', 400);

// IDOR — only allow if the caller is the responder themself (via user.member_id->responder linkage)
// OR has admin/access to this responder. par.member self-check would be ideal but absent that, we
// gate on the same access rule used for member-detail.
if (!is_admin()) {
    // Allow if the calling user's member_id matches the member behind this
    // responder. Schema audit 2026-07-07: the linkage is member.responder_id
    // (responder has no member_id column) — the old query threw and the
    // catch fail-closed to 403 for EVERY non-admin caller.
    try {
        $callerMemberId = (int) ($_SESSION['member_id'] ?? 0);
        $respMember = db_fetch_value(
            "SELECT `id` FROM `{$prefix}member` WHERE `responder_id` = ? LIMIT 1",
            [$responderId]
        );
        if ($callerMemberId === 0 || (int) $respMember !== $callerMemberId) {
            json_error('Forbidden', 403);
        }
    } catch (Exception $e) {
        json_error('Forbidden', 403);
    }
}

// Default date range: trailing 24h
if ($from === '') $from = date('Y-m-d 00:00:00', strtotime('-1 day'));
else              $from = date('Y-m-d 00:00:00', strtotime($from));
if ($to === '')   $to   = date('Y-m-d 23:59:59');
else              $to   = date('Y-m-d 23:59:59', strtotime($to));

// Pull responder + member identity
$responder = null;
try {
    // Schema audit 2026-07-07: responder has NO member_id column and member
    // has no fname/lname — the link runs the OTHER way (member.responder_id)
    // and the name columns are first_name/last_name. The old query threw,
    // the catch swallowed it, and this endpoint 404'd for everyone.
    $responder = db_fetch_one(
        "SELECT r.id, r.name, r.handle, r.callsign, m.first_name, m.last_name
           FROM `{$prefix}responder` r
           LEFT JOIN `{$prefix}member` m ON m.responder_id = r.id
          WHERE r.id = ?",
        [$responderId]
    );
} catch (Exception $e) {}
if (!$responder) json_error('Responder not found', 404);

$personName = trim(($responder['first_name'] ?? '') . ' ' . ($responder['last_name'] ?? ''));
if ($personName === '') $personName = (string) ($responder['name'] ?? ('Unit ' . $responderId));

// Build the timeline ─────────────────────────────────────────────────
// Sources 1–4 (PAR / assigns / user-authored notes / radio-attributed member
// notes) live in a reusable, unit-testable builder (Phase 111 Slice C).
require_once __DIR__ . '/../inc/ics214_timeline.php';
$entries = ics214_build_timeline($responder, $responderId, $from, $to, $ticketId);

// Operational period = first to last entry, or the requested range if empty
$opStart = $entries ? $entries[0]['t'] : $from;
$opEnd   = $entries ? $entries[count($entries) - 1]['t'] : $to;

// ─── Output ─────────────────────────────────────────────────────────
if ($format === 'xml' || $format === 'winlink') {
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="ICS214_' . $responderId . '_' .
        date('Ymd_His') . '.xml"');
    echo build_ics214_xml($responder, $personName, $entries, $opStart, $opEnd, $ticketId);
    exit;
}

json_response([
    'responder_id'   => $responderId,
    'person_name'    => $personName,
    'callsign'       => (string) ($responder['callsign'] ?? ''),
    'handle'         => (string) ($responder['handle'] ?? ''),
    'op_start'       => $opStart,
    'op_end'         => $opEnd,
    'entries'        => $entries,
    'count'          => count($entries),
]);

function build_ics214_xml(array $responder, string $personName, array $entries,
                          string $opStart, string $opEnd, int $ticketId): string {
    $now = date('Y-m-d H:i');
    $callsign = htmlspecialchars((string) ($responder['callsign'] ?? ''), ENT_XML1, 'UTF-8');
    $name     = htmlspecialchars($personName, ENT_XML1, 'UTF-8');
    $start    = htmlspecialchars($opStart, ENT_XML1, 'UTF-8');
    $end      = htmlspecialchars($opEnd, ENT_XML1, 'UTF-8');
    $incident = $ticketId ? ('Incident #' . $ticketId) : 'Multiple incidents';
    $incident = htmlspecialchars($incident, ENT_XML1, 'UTF-8');

    $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    $xml .= "<RMS_Express_Form>\n";
    $xml .= "  <form_parameters>\n";
    $xml .= "    <xml_file_version>1.0</xml_file_version>\n";
    $xml .= "    <rms_express_version>TicketsCAD_v4</rms_express_version>\n";
    $xml .= "    <submission_datetime>{$now}</submission_datetime>\n";
    $xml .= "    <senders_callsign>{$callsign}</senders_callsign>\n";
    $xml .= "    <form_name>ICS214</form_name>\n";
    $xml .= "  </form_parameters>\n";
    $xml .= "  <variable_input>\n";
    $xml .= "    <incident_name>{$incident}</incident_name>\n";
    $xml .= "    <op_period_from>{$start}</op_period_from>\n";
    $xml .= "    <op_period_to>{$end}</op_period_to>\n";
    $xml .= "    <name>{$name}</name>\n";
    $xml .= "    <ics_position>Unit/Responder</ics_position>\n";
    $xml .= "    <home_agency>" . htmlspecialchars((string) ($responder['handle'] ?? ''), ENT_XML1, 'UTF-8') . "</home_agency>\n";
    $xml .= "    <activity_log>\n";
    $i = 0;
    foreach ($entries as $e) {
        $i++;
        $t = htmlspecialchars($e['t'], ENT_XML1, 'UTF-8');
        $note = htmlspecialchars($e['note'], ENT_XML1, 'UTF-8');
        $xml .= "      <entry seq=\"{$i}\">\n";
        $xml .= "        <time>{$t}</time>\n";
        $xml .= "        <activity>{$note}</activity>\n";
        $xml .= "      </entry>\n";
    }
    $xml .= "    </activity_log>\n";
    $xml .= "  </variable_input>\n";
    $xml .= "</RMS_Express_Form>\n";
    return $xml;
}
