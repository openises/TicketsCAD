<?php
/**
 * Reports drill-down links (Eric, 2026-08-13) — from the live Reports page
 * screenshot: "we need a hyper link to the actual incident details when a
 * user clicks on the 'ID' value of this report... Please repeat this so
 * all ID's in any report allow you to hyperlink to the incident detail
 * page", then extended in the same conversation to units
 * ("unit-detail.php?id=53") and facilities.
 *
 * api/reports.php now sends a generic `links` array of {col, kind, ids}
 * descriptors alongside `columns`/`rows` (assets/js/reports.js turns the
 * matching cell into an <a href="..."> without ever changing the visible
 * text — Eric's stated rule that the number a user SEES and the internal
 * id used to LOCATE a row are two different things, and the id must never
 * appear on screen).
 *
 * Driven through the REAL endpoint (api/reports.php via a CLI subprocess
 * probe, same discipline as tests/_soft_delete_sweep_probe.php and
 * tests/_reports_stats_probe.php — the endpoint finishes via
 * json_response() and exits, so one call = one process) and through the
 * REAL writers (incident_create_internal, assign_create_internal) rather
 * than hand-seeded ideal rows.
 *
 * Covers the three link kinds wired up in this pass: incident, unit,
 * facility — across unit_log, dispatch_log, incident_report, facility_log.
 * Personnel/team links (member/team kinds) are a separate, larger piece
 * of work (new deep-link support needed in roster.php/teams.php) and are
 * NOT covered here.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Reports drill-down links (incident/unit/facility) ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

function rlp_probe(string $report, string $period = 'this_year', int $incidentId = 0): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_reports_links_probe.php')
         . ' ' . escapeshellarg($report) . ' ' . escapeshellarg($period);
    if ($incidentId > 0) {
        $cmd .= ' ' . escapeshellarg((string) $incidentId);
    }
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

/** Find the {col,kind,ids} descriptor for a given kind, or null. */
function rlp_link(array $payload, string $kind): ?array {
    foreach (($payload['links'] ?? []) as $d) {
        if (($d['kind'] ?? '') === $kind) return $d;
    }
    return null;
}

$typeId = 0;
try {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
} catch (Exception $e) { /* handled below */ }

if ($typeId <= 0) {
    echo "  SKIP  no incident types configured — cannot create fixture incidents\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$adminId = test_admin_user_id();
$marker  = 'RLP drilldown ' . bin2hex(random_bytes(5));

$ticketId    = 0;
$responderId = 0;
$facilityId  = 0;

$cleanup = function () use (&$ticketId, &$responderId, &$facilityId, $prefix) {
    try {
        if ($ticketId) {
            db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]);
            db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$ticketId]);
            db_query("DELETE FROM `{$prefix}ticket`  WHERE id = ?",        [$ticketId]);
        }
        if ($responderId) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
        if ($facilityId)  db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$facilityId]);
    } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
};
register_shutdown_function($cleanup);

// ── Fixtures: a dedicated responder + facility (fresh rows, not shared
// with other live data) so responder_id/facility filters below are
// unambiguous, plus one incident linking them together. ──────────────
try {
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description) VALUES (?, ?, ?)",
        [$marker . ' Unit', 'RLP1', 'RLP drilldown fixture responder']);
    $responderId = (int) db_insert_id();
} catch (Exception $e) { bad('creating fixture responder', $e->getMessage()); }

try {
    db_query("INSERT INTO `{$prefix}facilities` (name, description) VALUES (?, ?)",
        [$marker . ' Facility', 'RLP drilldown fixture facility']);
    $facilityId = (int) db_insert_id();
} catch (Exception $e) { bad('creating fixture facility', $e->getMessage()); }

if ($responderId <= 0 || $facilityId <= 0) {
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok('created fixture responder + facility');

$res = incident_create_internal([
    'in_types_id' => $typeId,
    'scope'       => $marker,
    'street'      => '221B Baker Street',
    'city'        => 'Cleveland',
    'state'       => 'OH',
    'description' => 'RLP drilldown fixture incident',
], $adminId);
$ticketId = (int) ($res['id'] ?? 0);
if ($ticketId <= 0) {
    bad('incident_create_internal did not create the fixture incident', implode('; ', $res['errors'] ?? ['unknown']));
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
ok("created fixture incident #{$ticketId} through the real writer");

$assignRes = assign_create_internal($ticketId, $responderId, 'Test', $adminId);
if (empty($assignRes['errors'])) {
    ok('created fixture assignment through the real writer (assign_create_internal)');
} else {
    bad('assign_create_internal failed', implode('; ', $assignRes['errors']));
}

db_query("UPDATE `{$prefix}ticket` SET `rec_facility` = ? WHERE `id` = ?", [$facilityId, $ticketId]);
$incidentNumber = (string) db_fetch_value("SELECT incident_number FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]);

// ─────────────────────────────────────────────────────────────────────
// unit_log — scoped via incident_id (its where clause supports it)
// ─────────────────────────────────────────────────────────────────────
$ul = rlp_probe('unit_log', 'this_year', $ticketId);
if (!is_array($ul) || empty($ul['rows'])) {
    bad('unit_log probe returned no rows', substr(json_encode($ul), 0, 300));
} else {
    $row = $ul['rows'][0];
    $unitDesc = rlp_link($ul, 'unit');
    $incDesc  = rlp_link($ul, 'incident');
    if ($unitDesc && (int) ($unitDesc['col']) === 0) {
        ok('unit_log: links[] carries a unit descriptor on column 0');
        if ((int) ($unitDesc['ids'][0] ?? 0) === $responderId) {
            ok('unit_log: unit link id matches the fixture responder id');
        } else {
            bad('unit_log: unit link id mismatch', 'got ' . ($unitDesc['ids'][0] ?? 'null') . " expected {$responderId}");
        }
    } else {
        bad('unit_log: no unit descriptor on column 0', json_encode($unitDesc));
    }
    if ($incDesc && (int) ($incDesc['col']) === 2) {
        ok('unit_log: links[] carries an incident descriptor on column 2');
        if ((int) ($incDesc['ids'][0] ?? 0) === $ticketId) {
            ok('unit_log: incident link id matches the fixture ticket id');
        } else {
            bad('unit_log: incident link id mismatch', 'got ' . ($incDesc['ids'][0] ?? 'null') . " expected {$ticketId}");
        }
    } else {
        bad('unit_log: no incident descriptor on column 2', json_encode($incDesc));
    }
    // The visible cell must be the unit's NAME, never the raw internal id.
    if (($row[0] ?? '') === $marker . ' Unit') {
        ok('unit_log: visible unit-name cell is the name, not the internal id');
    } else {
        bad('unit_log: visible unit-name cell unexpected', json_encode($row[0] ?? null));
    }
}

// ─────────────────────────────────────────────────────────────────────
// dispatch_log — no incident_id filter supported; scoped instead by
// finding our marker's incident number in the rendered rows.
// ─────────────────────────────────────────────────────────────────────
$dl = rlp_probe('dispatch_log', 'this_year');
if (!is_array($dl) || empty($dl['rows'])) {
    bad('dispatch_log probe returned no rows', substr(json_encode($dl), 0, 300));
} else {
    $idx = null;
    foreach ($dl['rows'] as $i => $r) {
        if (($r[0] ?? '') === $incidentNumber && $incidentNumber !== '') { $idx = $i; break; }
    }
    if ($idx === null) {
        bad('dispatch_log: could not find the fixture incident by its incident_number in the rendered rows',
            "incident_number={$incidentNumber}");
    } else {
        $incDesc  = rlp_link($dl, 'incident');
        $unitDesc = rlp_link($dl, 'unit');
        $incOk  = $incDesc && (int) ($incDesc['col']) === 0 && (int) ($incDesc['ids'][$idx] ?? 0) === $ticketId;
        $unitOk = $unitDesc && (int) ($unitDesc['col']) === 4 && (int) ($unitDesc['ids'][$idx] ?? 0) === $responderId;
        if ($incOk) ok('dispatch_log: incident descriptor (col 0) resolves to the fixture ticket at the matched row');
        else bad('dispatch_log: incident link mismatch at matched row', json_encode($incDesc));
        if ($unitOk) ok('dispatch_log: unit descriptor (col 4) resolves to the fixture responder at the matched row');
        else bad('dispatch_log: unit link mismatch at matched row', json_encode($unitDesc));
    }
}

// ─────────────────────────────────────────────────────────────────────
// incident_report — scoped via incident_id
// ─────────────────────────────────────────────────────────────────────
$ir = rlp_probe('incident_report', 'this_year', $ticketId);
if (!is_array($ir) || empty($ir['rows'])) {
    bad('incident_report probe returned no rows', substr(json_encode($ir), 0, 300));
} else {
    $incDesc = rlp_link($ir, 'incident');
    if ($incDesc && (int) ($incDesc['col']) === 0 && (int) ($incDesc['ids'][0] ?? 0) === $ticketId) {
        ok('incident_report: incident descriptor (col 0) resolves to the fixture ticket');
    } else {
        bad('incident_report: incident link mismatch', json_encode($incDesc));
    }
}

// ─────────────────────────────────────────────────────────────────────
// facility_log — no incident_id filter supported; scoped by finding our
// marker's incident number in the rendered rows.
// ─────────────────────────────────────────────────────────────────────
$fl = rlp_probe('facility_log', 'this_year');
if (!is_array($fl) || empty($fl['rows'])) {
    bad('facility_log probe returned no rows', substr(json_encode($fl), 0, 300));
} else {
    $idx = null;
    foreach ($fl['rows'] as $i => $r) {
        if (($r[1] ?? '') === $incidentNumber && $incidentNumber !== '') { $idx = $i; break; }
    }
    if ($idx === null) {
        bad('facility_log: could not find the fixture incident by its incident_number in the rendered rows',
            "incident_number={$incidentNumber}");
    } else {
        $facDesc  = rlp_link($fl, 'facility');
        $incDesc  = rlp_link($fl, 'incident');
        $unitDesc = rlp_link($fl, 'unit');
        $facOk  = $facDesc  && (int) ($facDesc['col'])  === 0 && (int) ($facDesc['ids'][$idx] ?? 0)  === $facilityId;
        $incOk  = $incDesc  && (int) ($incDesc['col'])  === 1 && (int) ($incDesc['ids'][$idx] ?? 0)  === $ticketId;
        $unitOk = $unitDesc && (int) ($unitDesc['col']) === 3 && (int) ($unitDesc['ids'][$idx] ?? 0) === $responderId;
        if ($facOk) ok('facility_log: facility descriptor (col 0) resolves to the fixture facility at the matched row');
        else bad('facility_log: facility link mismatch at matched row', json_encode($facDesc));
        if ($incOk) ok('facility_log: incident descriptor (col 1) resolves to the fixture ticket at the matched row');
        else bad('facility_log: incident link mismatch at matched row', json_encode($incDesc));
        if ($unitOk) ok('facility_log: unit descriptor (col 3) resolves to the fixture responder at the matched row');
        else bad('facility_log: unit link mismatch at matched row', json_encode($unitDesc));
        if (($fl['rows'][$idx][0] ?? '') === $marker . ' Facility') {
            ok('facility_log: visible facility-name cell is the name, not the internal id');
        } else {
            bad('facility_log: visible facility-name cell unexpected', json_encode($fl['rows'][$idx][0] ?? null));
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
