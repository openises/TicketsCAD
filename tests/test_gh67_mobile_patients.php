<?php
/**
 * GH#67 (Ron Jones, 2026-08-15): the mobile Patients section read
 * data.patients, a field api/incident-detail.php has never returned, so it
 * always rendered "Patients (0) / None" even when the incident had a
 * patient. Same `|| []` failure-to-none shape already fixed once in the
 * Call history section and once in Notes, in the same file.
 *
 * Fix: fetch api/patients.php?ticket_id=N directly, matching the desktop
 * incident-detail page and the Call history section's own established
 * pattern right above it.
 */

$js = file_get_contents(__DIR__ . '/../assets/js/mobile.js');

$pass = 0; $fail = 0;
function g67(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#67: mobile Patients section fetches api/patients.php ===\n\n";

$start = strpos($js, "if (mobileDetailPref('Patients')");
$end = strpos($js, "if (mobileDetailPref('CallHistory')", $start ?: 0);
g67('could isolate the Patients section block', $start !== false && $end !== false && $end > $start);
$block = ($start !== false && $end !== false) ? substr($js, $start, $end - $start) : '';

g67('no longer reads the never-provided data.patients field directly from incident-detail.php\'s payload',
    !preg_match('/var\s+pats\s*=\s*\(data\.patients/', $block),
    'the old bug pattern is still present verbatim');

g67('fetches api/patients.php with the incident\'s ticket_id',
    (bool) preg_match('#fetch\(\s*[\'"]api/patients\.php\?ticket_id=[\'"]\s*\+\s*encodeURIComponent\(ticketId\)#', $block));

g67('renders the patient count and list from the fetched response (not the outer data object)',
    strpos($block, 'patData.patients') !== false);

g67('has a load-failure fallback message (matches the Call history section\'s convention)',
    strpos($block, '.catch(') !== false
    && strpos($block, 'Could not load patients') !== false);

g67('still guards on the Patients detail preference toggle',
    strpos($block, "mobileDetailPref('Patients')") !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
