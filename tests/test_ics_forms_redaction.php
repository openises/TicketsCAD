<?php
/**
 * Phase 73r regression tests — ICS forms security-label redaction.
 *
 * Verifies _ics_apply_security_wrap actually redacts when
 * ics_export_show_full=0 — pre-fix the regex targeted a CSS class
 * generatePrintHtml never emits, so redaction silently no-op'd
 * while the watermark made it look protected.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

// Load the helper without booting the full API stack (no auth, no session).
function _load_ics_apply_wrap(): void
{
    $src = file_get_contents(__DIR__ . '/../api/ics-forms.php');
    if (!preg_match('/function _ics_apply_security_wrap\(.*?\n\}\n/s', $src, $m)) {
        throw new RuntimeException('could not extract _ics_apply_security_wrap');
    }
    eval($m[0]);
}
_load_ics_apply_wrap();

$tests = 0;
$fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) {
        $fails++;
        echo "FAIL: $label\n";
    }
}

// Sample HTML that mirrors what generatePrintHtml emits for an ICS-213.
// Address-like content is the realistic leak vector — Confidential
// patient/scene narrative would be in the message <td> with no .value
// inner span (the wide free-text panes).
$sampleHtml = <<<HTML
<!DOCTYPE html><html><head><title>ICS-213 - Drill</title></head>
<body>
<table>
  <tr><td><span class="label">TO:</span><span class="value">123 Maple Street Apt B</span></td>
      <td><span class="label">POSITION:</span><span class="value">Chief</span></td></tr>
  <tr><td colspan="2" style="min-height:100px"><span class="value">SECRET PATIENT DETAILS — narcotic overdose, addr 456 Birch Ln</span></td></tr>
  <tr><td colspan="2" style="min-height:80px">Free-text reply with PII (DOB 1965-04-12)</td></tr>
</table>
</body></html>
HTML;

// ── Scenario A: show_full=1 — no redaction ─────────────────────────
$out = _ics_apply_security_wrap($sampleHtml, [
    'ics_export_show_full' => 1,
    'ics_watermark_text'   => 'OFFICIAL USE',
    'name'                 => 'Confidential',
]);
tcheck(strpos($out, '123 Maple Street Apt B') !== false,
    'show_full=1 keeps the address visible');
tcheck(strpos($out, 'OFFICIAL USE') !== false,
    'show_full=1 still applies watermark');

// ── Scenario B: show_full=0 — value spans redacted ─────────────────
$out = _ics_apply_security_wrap($sampleHtml, [
    'ics_export_show_full' => 0,
    'ics_watermark_text'   => 'RESTRICTED',
    'name'                 => 'Confidential',
]);
tcheck(strpos($out, '123 Maple Street Apt B') === false,
    'show_full=0 removes the .value-wrapped address');
tcheck(strpos($out, 'SECRET PATIENT DETAILS') === false,
    'show_full=0 removes the .value-wrapped patient details');
tcheck(strpos($out, 'DOB 1965-04-12') === false,
    'show_full=0 removes free-text td-colspan block (no .value child)');
tcheck(strpos($out, '*** Confidential ***') !== false,
    'show_full=0 inserts the redacted-marker badge');
tcheck(strpos($out, 'RESTRICTED') !== false,
    'show_full=0 still emits the watermark layer');

// ── Scenario C: show_full=0 with no name — falls back to "Restricted" ─
$out = _ics_apply_security_wrap('<span class="value">leak me</span>', [
    'ics_export_show_full' => 0,
    'ics_watermark_text'   => '',
    'name'                 => '',
]);
tcheck(strpos($out, 'leak me') === false,
    'show_full=0 even without watermark redacts content');
tcheck(strpos($out, '*** Restricted ***') !== false,
    'default name fallback is "Restricted"');

echo "ICS-forms redaction regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
