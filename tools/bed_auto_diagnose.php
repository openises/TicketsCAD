<?php
/**
 * Bed-count auto-delivery diagnostic (GH #20).
 *
 * Read-only. Explains EXACTLY why a facility's beds_a/beds_o would (or would
 * not) move automatically when a unit reaches a delivery status — the same gate
 * checks bed_auto_apply_on_status_change() runs, but without touching any data.
 *
 * a beta tester 2026-07-14: "auto delivery still not working" but the [bed_auto] error
 * log line (which names the reason) isn't easy to find on his host. This turns
 * that into a one-command answer.
 *
 *   php tools/bed_auto_diagnose.php                 # config overview (the 2 prereqs)
 *   php tools/bed_auto_diagnose.php --responder=7   # trace a specific unit
 *   php tools/bed_auto_diagnose.php --responder=7 --status=12
 *
 * Run from the install root (or anywhere — it chdir's to its parent's parent).
 */
chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'inc/bed_auto.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

$args = [];
foreach ($argv as $a) { if (preg_match('/^--([a-z_]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2]; }
$responderId = isset($args['responder']) ? (int) $args['responder'] : 0;
$statusId    = isset($args['status'])    ? (int) $args['status']    : 0;

function line($s = '') { echo $s . "\n"; }
function ok($s)   { line("  [OK]   $s"); }
function bad($s)  { line("  [FAIL] $s"); }
function warn($s) { line("  [WARN] $s"); }
function colExists($prefix, $table, $col) {
    try { return !empty(db_fetch_all("SHOW COLUMNS FROM `{$prefix}{$table}` LIKE '{$col}'")); }
    catch (Exception $e) { return false; }
}

line("=== TicketsCAD bed auto-delivery diagnostic (GH #20) ===");
line();

// ── Stage 1: schema prerequisites ──
line("Stage 1 — schema");
$hasMode = colExists($prefix, 'facilities', 'bed_auto_mode');
$hasMode ? ok("facilities.bed_auto_mode column present")
         : bad("facilities.bed_auto_mode MISSING — run sql/run_migrations.php (Phase 103). Auto delivery cannot work.");
$hasFlag = colExists($prefix, 'un_status', 'bed_delivery');
$hasFlag ? ok("un_status.bed_delivery column present (per-status delivery flags supported)")
         : warn("un_status.bed_delivery MISSING — falls back to English name patterns only");
line();

// ── Stage 2: is any status configured as a delivery event? ──
line("Stage 2 — delivery statuses (Settings → Unit Statuses → 'Counts as facility delivery')");
$flagged = [];
if ($hasFlag) {
    try { $flagged = db_fetch_all("SELECT id, status_val FROM `{$prefix}un_status` WHERE bed_delivery = 1 ORDER BY id"); }
    catch (Exception $e) {}
}
if ($flagged) {
    ok(count($flagged) . " status(es) flagged as delivery: "
        . implode(', ', array_map(fn($s) => "#{$s['id']} '{$s['status_val']}'", $flagged)));
    line("       (flags are authoritative — the name-pattern fallback is OFF while any flag is set)");
} else {
    warn("NO status has the 'Counts as facility delivery' flag set.");
    line("       Falling back to name patterns: " . implode(', ', BED_AUTO_STATUS_PATTERNS));
    try {
        $all = db_fetch_all("SELECT id, status_val FROM `{$prefix}un_status` ORDER BY id");
        $match = array_filter($all, fn($s) => _bed_auto_is_delivery_status($s['status_val']));
        $match
            ? line("       Statuses that match a pattern today: " . implode(', ', array_map(fn($s) => "#{$s['id']} '{$s['status_val']}'", $match)))
            : bad("       NONE of your status names match a pattern either → NO status qualifies. "
                . "Flag your at-facility status: Settings → Unit Statuses → edit it → 'Counts as facility delivery'.");
    } catch (Exception $e) {}
}
line();

// ── Stage 3: is any facility set to Automatic? ──
line("Stage 3 — facilities on Automatic (Facility Edit → Capacity & Status → Bed Count Updates)");
$autoFacs = [];
if ($hasMode) {
    try { $autoFacs = db_fetch_all("SELECT id, name, beds_a, beds_o FROM `{$prefix}facilities` WHERE bed_auto_mode = 'auto' ORDER BY id"); }
    catch (Exception $e) {}
}
if ($autoFacs) {
    ok(count($autoFacs) . " facility(ies) on Automatic:");
    foreach ($autoFacs as $f) line("       #{$f['id']} '{$f['name']}' — beds_a={$f['beds_a']} beds_o={$f['beds_o']}");
} else {
    bad("NO facility is set to Automatic. Even a perfect delivery status won't move beds. "
        . "Facility Edit → Capacity & Status → Bed Count Updates = Automatic.");
}
line();

// ── Stage 4: per-unit trace ──
if ($responderId > 0) {
    line("Stage 4 — trace unit (responder) #{$responderId}");
    $resp = db_fetch_one("SELECT id, name FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
    $resp ? ok("unit: #{$resp['id']} '{$resp['name']}'") : bad("no responder with id={$responderId}");

    // Open assignments whose INCIDENT carries a receiving facility — THE
    // gate people miss. GH #20 round 3: the facility is resolved from the
    // incident (`ticket.rec_facility`, the "Receiving Facility" select on
    // the incident form), with `assigns.rec_facility_id` as an optional
    // per-assignment override. No dispatch UI writes rec_facility_id, so
    // in practice the incident is the source.
    $open = [];
    try {
        $open = db_fetch_all(
            "SELECT a.id AS assign_id, a.ticket_id,
                    a.rec_facility_id AS assign_fac,
                    t.rec_facility    AS incident_fac,
                    COALESCE(NULLIF(a.rec_facility_id, 0), NULLIF(t.rec_facility, 0)) AS rec_facility_id
               FROM `{$prefix}assigns` a
               JOIN `{$prefix}ticket` t ON t.id = a.ticket_id
              WHERE a.responder_id = ?
                AND (a.clear IS NULL OR a.clear = '' OR a.clear = '0000-00-00 00:00:00')",
            [$responderId]);
    } catch (Exception $e) { bad("assigns query failed: " . $e->getMessage()); }

    if (!$open) {
        bad("unit has NO open assignment → nothing to deliver to. Assign it to an incident first.");
    } else {
        $withFac = array_filter($open, fn($a) => (int) $a['rec_facility_id'] > 0);
        if (!$withFac) {
            bad("unit has " . count($open) . " open assignment(s) but NONE of their incidents has a "
                . "receiving facility set → reason 'no_open_assignment_with_receiving_facility'.");
            line("       FIX: open the incident and set its DESTINATION / Receiving Facility "
                . "(the transport destination), not just 'view' the facility. The bed count follows the "
                . "incident's receiving facility, so it must be set on the incident the unit is assigned to.");
        } else {
            foreach ($withFac as $a) {
                $fid = (int) $a['rec_facility_id'];
                $f = db_fetch_one("SELECT id, name, bed_auto_mode, beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$fid]);
                if (!$f) { bad("assign #{$a['assign_id']} → receiving facility #{$fid} NOT FOUND"); continue; }
                $mode = $f['bed_auto_mode'] ?? 'manual';
                if ($mode !== 'auto') {
                    bad("assign #{$a['assign_id']} → facility #{$fid} '{$f['name']}' is mode='{$mode}' (not auto) "
                        . "→ reason 'facility_{$fid}_mode_manual'. Set it to Automatic.");
                    continue;
                }
                $already = (int) db_fetch_value(
                    "SELECT COUNT(*) FROM `{$prefix}facility_bed_auto_log` WHERE assign_id = ? AND facility_id = ?",
                    [(int) $a['assign_id'], $fid]);
                if ($already > 0) {
                    warn("assign #{$a['assign_id']} → facility #{$fid} '{$f['name']}' already decremented once "
                        . "(one-shot per assignment; won't fire again).");
                } else {
                    $src = ((int) $a['assign_fac'] > 0) ? 'assignment override' : "incident #{$a['ticket_id']}";
                    ok("assign #{$a['assign_id']} → facility #{$fid} '{$f['name']}' (from {$src}) is AUTO + not yet "
                        . "applied → beds WILL move (beds_a {$f['beds_a']}→" . max(0, (int) $f['beds_a'] - 1) . ") "
                        . "the next time this unit hits a delivery status.");
                }
            }
        }
    }
    if ($statusId > 0) {
        $sv = (string) db_fetch_value("SELECT status_val FROM `{$prefix}un_status` WHERE id = ?", [$statusId]);
        bed_auto_status_qualifies($statusId, $sv)
            ? ok("status #{$statusId} '{$sv}' QUALIFIES as a delivery event.")
            : bad("status #{$statusId} '{$sv}' does NOT qualify → moving the unit into it won't move beds. "
                . "Flag it: Settings → Unit Statuses → 'Counts as facility delivery'.");
    }
    line();
}

line("Summary: beds move automatically only when ALL of these are true —");
line("  (1) a status is flagged 'Counts as facility delivery' (Stage 2),");
line("  (2) the receiving facility is on Automatic (Stage 3),");
line("  (3) the unit has an OPEN assignment whose INCIDENT's receiving facility is that facility (Stage 4),");
line("  (4) that (assignment, facility) pair hasn't already been counted once.");
line("The single most common miss is (3): setting the incident's Receiving Facility");
line("on the incident the unit is assigned to — not just viewing the facility.");
