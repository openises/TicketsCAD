<?php
/**
 * GH#64 (Ron Jones, 2026-08-15) — pointing a second unit status at the
 * "On Scene" incident action silently does nothing to the incident
 * timeline: assigns.on_scene is write-once (inc/responder-write.php's
 * stamping branch only fires when the column is still empty), so a
 * later status configured the same way changes the unit's status and
 * adds an action-log entry, but the timestamp itself never moves. The
 * status change reads as though it worked. Ron's own suggestion: "worth
 * a note in the status-config help text ... since the failure is
 * invisible from the UI."
 *
 * This test only confirms the warning text landed on the Incident
 * Action control's help popover; it does not re-verify the write-once
 * behavior itself, which tools/test_bed_auto*.php and friends already
 * exercise against the real writer.
 */

$html = file_get_contents(__DIR__ . '/../settings.php');

$pass = 0; $fail = 0;
function g64(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name\n"; }
}

echo "=== GH#64: Incident Action help text warns about write-once On Scene ===\n\n";

$start = strpos($html, 'id="statusIncidentAction"');
g64('found the Incident Action select', $start !== false);

$popoverStart = strpos($html, 'title="Incident action help"');
g64('found the Incident Action help popover', $popoverStart !== false);

$context = $popoverStart !== false ? substr($html, max(0, $popoverStart - 900), 900) : '';
g64('help text explains On Scene is write-once', strpos($context, 'write-once') !== false);
g64('help text names the concrete failure mode a config-time reader would hit',
    strpos($context, 'will NOT re-stamp') !== false || strpos($context, 'not re-stamp') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
