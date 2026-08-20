<?php
/**
 * Phase 140 (2026-08-16) — GH#69 follow-up fix: re-opening an
 * ALREADY-SAVED custom-type instance for EDITING must render the exact
 * field list it was first saved with, forever, even after the type
 * definition has since been edited (a field renamed, added, or removed)
 * or archived entirely.
 *
 * Root cause this fixes: assets/js/ics-forms.js's openFormEditor() ALWAYS
 * re-fetched api/ics-forms.php?template=custom&custom_type_id=X -- even
 * when reopening a saved instance -- and rendered THAT (the type's
 * CURRENT, possibly since-edited field list), using the instance's own
 * savedData only to fill in values by key. The frozen snapshot the
 * instance carries in its own form_data._meta (built once at first save
 * by ics_form_custom_build_meta(), inc/ics-form-types.php) was written
 * correctly and already honored by PRINT (ics_form_custom_print_html()
 * renders solely from _meta, never a fresh type lookup) -- but the EDITOR
 * reopen path silently ignored it. Caught while building the training
 * video for this feature, whose entire "one design detail that matters
 * more than it looks like it should" segment demonstrates exactly this
 * guarantee (specs/... not yet written up as a phase directory; see
 * training-production/all-modules/micsforms/manifest.json beats
 * b31-b36 for the narrated claim this proves true).
 *
 * This extracts the REAL openFormEditor()/_finishOpenFormEditor() functions
 * from assets/js/ics-forms.js via node (same convention as
 * tests/test_ics_form_types_render_safety.php) and drives them with a
 * mocked fetch() that FAILS THE TEST if called when it must not be --
 * the frozen-snapshot path's whole point is that it never needs the network.
 *
 * Usage: php tests/test_ics_form_types_frozen_reopen.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 140 — frozen _meta snapshot honored on EDITOR reopen ===\n\n";

$base = realpath(__DIR__ . '/..');
$icsFormsPath = $base . '/assets/js/ics-forms.js';
t('assets/js/ics-forms.js exists', is_file($icsFormsPath));

$src = (string) file_get_contents($icsFormsPath);
t('_finishOpenFormEditor() exists (the shared render tail introduced by this fix)',
    (bool) preg_match('/function\s+_finishOpenFormEditor\s*\(/', $src));
t('openFormEditor() checks savedData._meta.fields before touching the network',
    (bool) preg_match('/savedData\s*&&\s*savedData\._meta\s*&&\s*Array\.isArray\(\s*savedData\._meta\.fields\s*\)/', $src));

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "\nSKIP: node not available — the JS execution checks were not run\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

function _p140fr_extract_fn(string $src, string $name): string {
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException("could not find function $name");
    }
    $start = $m[0][1];
    $bodyStart = strpos($src, '{', $start);
    $depth = 0; $i = $bodyStart; $len = strlen($src);
    for (; $i < $len; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $i++; break; } }
    }
    return substr($src, $start, $i - $start) . "\n";
}

$harness = sys_get_temp_dir() . '/tcad_p140_frozen_reopen_' . getmypid() . '.js';

$prelude = <<<'JS'
// Module-level state openFormEditor()/_finishOpenFormEditor() close over
// in the real IIFE. Declared here as plain globals for the harness.
var currentFormId = 0;
var currentCustomTypeId = 0;
var currentForm = null;
var currentTemplate = null;
var currentFormType = null;
var incidentData = null;

// fetch() mock: records every call; THROWS if a test configures it to
// (proves the frozen-snapshot path genuinely never hits the network).
var fetchCalls = [];
var fetchShouldThrow = false;
var fetchResponse = { form_type: 'custom', custom_type_id: 999, fields: [{ key: 'live_only', label: 'Live Only', type: 'text' }] };
global.fetch = function (url) {
    fetchCalls.push(url);
    if (fetchShouldThrow) {
        throw new Error('fetch() must NOT be called on the frozen-snapshot reopen path: ' + url);
    }
    return Promise.resolve({ json: function () { return Promise.resolve(fetchResponse); } });
};

// Minimal DOM stub -- these functions only READ a few ids/values via
// getElementById; none of them need real rendering for this test.
global.document = {
    getElementById: function (id) { return null; }
};

// Stubs for functions this pair calls that are irrelevant to what's under
// test here (full rendering is covered by test_ics_form_types_render_safety.php).
var renderFormEditorCalls = [];
function renderFormEditor(tpl, data, title, status) {
    renderFormEditorCalls.push({ tpl: tpl, data: data, title: title, status: status });
}
function showEditor() {}
function loadIncidentData() {}
function showAlert() {}

JS;

$body = $prelude
    . _p140fr_extract_fn($src, 'openFormEditor')
    . _p140fr_extract_fn($src, '_finishOpenFormEditor');

$body .= <<<'JS'

var results = [];
function check(name, cond, detail) { results.push((cond ? 'PASS' : 'FAIL') + '|' + name + '|' + (detail || '')); }

// ── Case 1: reopening an ALREADY-SAVED custom instance whose type has
// since changed live (simulated by fetchResponse carrying a DIFFERENT
// field list than the frozen _meta) must render the FROZEN fields and
// must NEVER call fetch() at all. ──
var FROZEN_FIELDS = [
    { key: 'subject_name', label: 'Subject Name', type: 'text' },
    { key: 'heart_rate', label: 'Heart Rate (BPM)', type: 'number', min: 40, max: 220 }
];
var savedRehabForm = {
    _meta: {
        type_id: 42, type_slug: 'rehab-check-in', form_number: '', form_title: 'Rehab Check-In',
        badge_color: 'secondary', icon: 'bi-heart-pulse', fields: FROZEN_FIELDS, snapshot_at: '2026-08-16 00:00:00'
    },
    subject_name: 'J. Rivera', heart_rate: 88
};

fetchCalls = [];
fetchShouldThrow = true;   // any fetch() call here is itself the failure
renderFormEditorCalls = [];
openFormEditor('custom', 501, savedRehabForm, null, 'Rehab check', 'draft', 42);

check('reopening a saved custom instance calls renderFormEditor synchronously (no network round-trip needed)',
    renderFormEditorCalls.length === 1, 'calls=' + renderFormEditorCalls.length);
check('fetch() was never called on the frozen-snapshot reopen path',
    fetchCalls.length === 0, JSON.stringify(fetchCalls));
if (renderFormEditorCalls.length === 1) {
    var gotFields = renderFormEditorCalls[0].tpl.fields;
    check('the rendered field list is IDENTICAL to the frozen _meta.fields (===, not a re-fetched live list)',
        gotFields === FROZEN_FIELDS);
    check('the rendered field list still has exactly the 2 frozen fields, not a since-added field',
        Array.isArray(gotFields) && gotFields.length === 2, JSON.stringify(gotFields));
    check('the rendered template carries the frozen form_title from _meta',
        renderFormEditorCalls[0].tpl.form_title === 'Rehab Check-In');
    check('the saved values (heart_rate=88) are passed through as the data to populate',
        renderFormEditorCalls[0].data.heart_rate === 88);
}
check('currentCustomTypeId is set from the frozen meta.type_id',
    currentCustomTypeId === 42, 'got ' + currentCustomTypeId);

// ── Case 2: a BRAND-NEW custom form (savedData null, only a customTypeId
// from the hub card) has no frozen snapshot yet and MUST fetch the live
// template -- this is the legitimate, still-needed network path. ──
fetchCalls = [];
fetchShouldThrow = false;
renderFormEditorCalls = [];
currentCustomTypeId = 0;
openFormEditor('custom', 0, null, null, '', 'draft', 42);

// fetch() is async (returns a Promise); give the microtask queue a tick.
setTimeout(function () {
    check('a brand-new custom form (no savedData) DOES call fetch() for the live template',
        fetchCalls.length === 1 && /template=custom/.test(fetchCalls[0]) && /custom_type_id=42/.test(fetchCalls[0]),
        JSON.stringify(fetchCalls));

    // ── Case 3: reopening a saved BUILT-IN form (e.g. a 213) must still
    // fetch -- the frozen-snapshot short-circuit is scoped to formType
    // === 'custom' only; built-ins have no _meta mechanism at all. ──
    fetchCalls = [];
    renderFormEditorCalls = [];
    openFormEditor('213', 77, { to_name: 'Dispatch' }, null, 'A message', 'draft', 0);
    setTimeout(function () {
        check('reopening a saved BUILT-IN (213) form still fetches its template (unaffected by this fix)',
            fetchCalls.length === 1 && /template=213/.test(fetchCalls[0]), JSON.stringify(fetchCalls));

        // ── Case 4: savedData present but with NO _meta (defensive/edge
        // case -- e.g. a pre-fix-era row) must fall back to fetching. ──
        fetchCalls = [];
        renderFormEditorCalls = [];
        openFormEditor('custom', 88, { subject_name: 'X' }, null, 'No meta', 'draft', 42);
        setTimeout(function () {
            check('savedData without a _meta.fields array falls back to the live-template fetch (defensive)',
                fetchCalls.length === 1, JSON.stringify(fetchCalls));

            console.log(JSON.stringify(results));
        }, 10);
    }, 10);
}, 10);
JS;

file_put_contents($harness, $body);

$out = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1');
@unlink($harness);

$decoded = null;
if (is_string($out)) {
    $trimmed = trim($out);
    $lines = explode("\n", $trimmed);
    // Node prints check() results as the LAST '[' line once all nested
    // setTimeout callbacks resolve; scan from the end for the JSON array.
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $cand = trim($lines[$i]);
        if ($cand !== '' && $cand[0] === '[') { $decoded = json_decode($cand, true); break; }
    }
}

if (!is_array($decoded)) {
    t('node harness ran and returned parseable results', false);
    echo "  raw output: " . substr((string) $out, 0, 3000) . "\n";
} else {
    foreach ($decoded as $line) {
        $parts = explode('|', $line, 3);
        $ok = ($parts[0] ?? '') === 'PASS';
        $name = $parts[1] ?? '(unnamed)';
        t($name, $ok);
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
