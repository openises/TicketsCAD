<?php
/**
 * GH#52 follow-up (Chris Byrd, 2026-08-13) — structural checks for the two
 * JS-side symptoms tests/test_gh52_incident_page_extra_data.php cannot
 * reach (it drives assign_update_status_internal() directly, not the
 * browser): the missing facility-picker component, and the status
 * dropdown staying permanently disabled after any non-success outcome.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function test(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// ── 1. The component exists and is loaded on the right page, in the right order ──
$promptPath = $root . '/assets/js/status-extra-data-prompt.js';
test('assets/js/status-extra-data-prompt.js exists', is_file($promptPath));

$promptSrc = is_file($promptPath) ? (string) file_get_contents($promptPath) : '';
test('it defines window.TCADStatusExtraDataPrompt — the exact global incident-detail.js already looked for',
    strpos($promptSrc, 'window.TCADStatusExtraDataPrompt = function') !== false);
test('a cancelled/dismissed prompt calls done(null), not silence — the caller depends on this to re-enable its control',
    strpos($promptSrc, "done(null)") !== false && strpos($promptSrc, 'hidden.bs.modal') !== false);
test('facility options come from the real endpoint, not a hand-typed list',
    strpos($promptSrc, "fetch('api/facilities.php'") !== false);

$idpPath = $root . '/incident-detail.php';
$idpSrc = (string) file_get_contents($idpPath);
// Anchored to the <script src="..."> tag itself, not a bare path -- the
// file also mentions "assets/js/incident-detail.js" once in a docblock
// comment, which a plain strpos() would find FIRST and report as if it
// were the (correctly ordered) script tag.
$posPrompt = strpos($idpSrc, '<script src="assets/js/status-extra-data-prompt.js');
$posDetail = strpos($idpSrc, '<script src="assets/js/incident-detail.js');
test('incident-detail.php loads the prompt component', $posPrompt !== false);
test('…BEFORE incident-detail.js, so the global exists the first time it is needed',
    $posPrompt !== false && $posDetail !== false && $posPrompt < $posDetail);

// ── 2. updateAssignmentStatus() re-enables its control on every exit path ──
$jsPath = $root . '/assets/js/incident-detail.js';
$jsSrc = (string) file_get_contents($jsPath);
if (preg_match('/function updateAssignmentStatus\([^)]*\)\s*\{(.*?)\r?\n    \}\r?\n/s', $jsSrc, $m) !== 1) {
    echo "[FAIL] could not isolate updateAssignmentStatus() — file structure changed?\n";
    echo "\n$pass passed, 1 failed\n";
    exit(1);
}
$fn = $m[1];
$pass++; echo "[PASS] isolated updateAssignmentStatus()'s function body\n";

test('the function accepts a 5th parameter for the control to re-enable',
    (bool) preg_match('/function updateAssignmentStatus\([^)]*selEl\s*\)/', $jsSrc));

test('a reEnable()-shaped helper exists and touches selEl.disabled',
    (bool) preg_match('/function reEnable\(\)\s*\{[^}]*selEl\.disabled\s*=\s*false/s', $fn));

// Every place the OLD code returned without touching the control:
// cancelling the slot-1/slot-2 prompt, a generic server error, and a
// network failure. Each must now call reEnable() (directly or via the
// shared openPrompt() cancel branch) rather than leaving it disabled.
test('cancelling the extra-data prompt re-enables the control (via openPrompt\'s cancel branch)',
    (bool) preg_match('/if\s*\(\s*val\s*===\s*null\s*\)\s*\{\s*reEnable\(\)/', $fn));
test('a generic server-side error re-enables the control',
    (bool) preg_match('/showAlert\(escHtml\(data\.error\), .danger.\);\s*\r?\n\s*reEnable\(\);/', $fn));
test('a network/fetch failure (the .catch branch) re-enables the control',
    (bool) preg_match('/\.catch\(function\s*\(err\)\s*\{\s*showAlert\([^;]*\);\s*\r?\n\s*reEnable\(\);/s', $fn));

// ── 3. Slot 2 is actually wired on this page now, not just slot 1 ──
test('the function threads a 2nd extra-data value through to the request body',
    strpos($fn, 'extraData2') !== false && strpos($fn, 'body.extra_data_2') !== false);
test('a slot-2-required rejection (extra_data_required_2) is handled, distinct from slot 1\'s',
    strpos($fn, "'extra_data_required_2'") !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
