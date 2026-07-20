<?php
/**
 * GH #13 — real-time cross-client incident sync (mobile ↔ CAD).
 *
 * a beta tester: a unit status change (DP→EN) + a note made ON MOBILE did not appear
 * in the open CAD incident window. Root cause was an event-name mismatch plus
 * missing publishes:
 *   - notes emit 'incident:note' (desktop) — the mobile note path emitted
 *     nothing, and both the CAD incident view + mobile only listened for
 *     'action:added' (which nothing publishes).
 *   - status changes emit nothing from responder_set_status_internal — the
 *     shared helper every path funnels through — and the viewers listened for
 *     'assign:update' (also never published).
 *
 * Fix: the status helper publishes 'responder:status' per open-assignment
 * incident; the mobile note path publishes 'incident:note'; and both the CAD
 * incident view and the mobile view now listen for those real event names.
 *
 * Usage: php tests/test_realtime_incident_sync.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sse.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== GH #13 — real-time incident sync (mobile ↔ CAD) ===\n\n";

// ── Publishers ──────────────────────────────────────────────────────────────
$rw = rd($base . '/inc/responder-write.php');
t('status helper publishes responder:status per open-assignment incident (with ticket_id)',
    $rw !== false &&
    (bool) preg_match("/sse_publish_for_incident\('responder:status',\s*\[[\s\S]{0,120}'ticket_id'\s*=>\s*\\\$tid/", $rw) &&
    strpos($rw, 'foreach ($openAssigns as $oa)') !== false);

$md = rd($base . '/api/mobile-data.php');
t('mobile add_note publishes incident:note (with ticket_id)',
    $md !== false &&
    (bool) preg_match("/sse_publish_for_incident\('incident:note',\s*\['ticket_id'\s*=>\s*\\\$ticketId\]/", $md));

// ── Subscribers ─────────────────────────────────────────────────────────────
$idjs = rd($base . '/assets/js/incident-detail.js');
t('CAD incident view listens for incident:note + responder:status',
    $idjs !== false &&
    (bool) preg_match("/var events = \[[\s\S]{0,200}'incident:note'[\s\S]{0,200}'responder:status'/", $idjs));

$mjs = rd($base . '/assets/js/mobile.js');
t('mobile view listens for incident:note + responder:status',
    $mjs !== false &&
    strpos($mjs, "addEventListener('incident:note'") !== false &&
    strpos($mjs, "addEventListener('responder:status'") !== false);

// ── Live round-trip: the events actually land in the SSE stream table ────────
$tid = 991300 + (int) (fmod((float) (string) memory_get_usage(), 900)); // pseudo-unique, no Date/rand
try {
    // responder:status for an incident → a readable sse_events row.
    $ok1 = sse_publish_for_incident('responder:status', ['ticket_id' => $tid, 'responder_id' => 990013, 'status' => 'Enroute'], $tid);
    $ok2 = sse_publish_for_incident('incident:note', ['ticket_id' => $tid], $tid);
    t('sse_publish_for_incident(responder:status / incident:note) returns true', $ok1 && $ok2);

    $rows = db_fetch_all(
        "SELECT event_type, payload FROM `{$prefix}sse_events`
          WHERE payload LIKE ? ORDER BY id DESC LIMIT 5",
        ['%"ticket_id":' . $tid . '%']);
    $types = [];
    foreach ($rows as $r) { $types[$r['event_type']] = true; }
    t('a responder:status event row was written for the incident', isset($types['responder:status']));
    t('an incident:note event row was written for the incident', isset($types['incident:note']));

    // Cleanup our synthetic events.
    db_query("DELETE FROM `{$prefix}sse_events` WHERE payload LIKE ?", ['%"ticket_id":' . $tid . '%']);
} catch (Throwable $e) {
    t('SSE round-trip (unexpected error: ' . $e->getMessage() . ')', false);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
