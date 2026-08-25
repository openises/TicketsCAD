<?php
/**
 * GH#107 (rjonesbsink, 2026-08-24) — the ICS Form Builder's "Table" field
 * type, with a column set to "Select", could never be saved.
 *
 * Root cause, per rjonesbsink's own diagnosis (verified against the live
 * source at assets/js/ics-form-type-admin.js before this fix): a single
 * string/array asymmetry in handleFieldsListChange(). The plain top-level
 * select field's options textarea was split on newlines into an array
 * (`f.options = (el.value || '').split('\n')`), but a TABLE COLUMN'S
 * options textarea fell through to the generic branch
 * (`f.columns[colIdx][colProp] = readInputValue(el)`), which just returns
 * the raw textarea string. cleanFieldsForSave() then calls `.filter()` on
 * that value — which strings don't have — and THROWS inside the payload
 * object literal at the saveType() call site, before postJson() (and its
 * .catch()) is ever reached. No request is sent, no error is shown: a
 * completely dead Save button, matching the reporter's "does not save nor
 * does any message appear for failure or success."
 *
 * A second, separate symptom from the same area: switching a column's type
 * to "select" mutates the model but nothing re-renders, so the options
 * textarea doesn't appear until some unrelated structural action (e.g. Add
 * Column) happens to trigger the next renderFieldsEditor().
 *
 * No JS runtime in CI (docs/CI-ENVIRONMENT.md doesn't guarantee node), so
 * these are static-contract checks against the shipped JS — same
 * convention as test_incident_detail_ui_fixes.php for the same class of
 * "silent dead click handler" bug.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== ics-form-type-admin.js: table-column select save fix (GH#107) ===\n\n";

$js = file_get_contents($root . '/assets/js/ics-form-type-admin.js');

// ── 1. handleFieldsListChange()'s table-column branch must split
// colProp === 'options' on newlines into an array, matching the plain
// top-level select field's own branch a few lines below it. ──────────────
if (preg_match(
    '/colProp\s*===\s*[\'"]options[\'"][\s\S]{0,500}f\.columns\[colIdx\]\.options\s*=\s*\(\s*el\.value\s*\|\|\s*[\'"][\'"]\s*\)\.split\(\s*[\'"]\\\\n[\'"]\s*\)/',
    $js
)) {
    ok("the table-column branch splits colProp==='options' on newlines into an array, not a raw readInputValue() string");
} else {
    bad("the table-column branch does not special-case 'options' with a .split('\\n')", 'GH#107 regression — cleanFieldsForSave() will throw on co.options.filter()');
}

// ── 2. The OLD unconditional shape must be gone: a bare
// f.columns[colIdx][colProp] = readInputValue(el) with NO options
// special-case anywhere before it in the same branch. ────────────────────
$colBranchStart = strpos($js, "if (colProp !== null && colIdx !== null) {");
if ($colBranchStart === false) {
    bad('found the table-column branch to inspect its body', 'if (colProp !== null && colIdx !== null) { not found — did the code move?');
} else {
    $braceDepth = 0; $i = $colBranchStart; $bodyEnd = null;
    $len = strlen($js);
    for (; $i < $len; $i++) {
        if ($js[$i] === '{') $braceDepth++;
        elseif ($js[$i] === '}') { $braceDepth--; if ($braceDepth === 0) { $bodyEnd = $i; break; } }
    }
    if ($bodyEnd === null) {
        bad('found a matching closing brace for the table-column branch');
    } else {
        $body = substr($js, $colBranchStart, $bodyEnd - $colBranchStart);
        if (strpos($body, "colProp === 'options'") !== false || strpos($body, 'colProp === "options"') !== false) {
            ok("the table-column branch body contains an explicit colProp==='options' special-case (not just a bare unconditional assignment)");
        } else {
            bad("the table-column branch body has no colProp==='options' special-case", 'the exact GH#107 shape — every column property, including options, falls through to readInputValue()');
        }
    }
}

// ── 3. Switching a column's type triggers a re-render, so the options
// textarea appears immediately rather than on the next unrelated action. ──
if (preg_match(
    "/colProp\s*===\s*'type'[\\s\\S]{0,500}renderFieldsEditor\\(\\)/",
    $js
)) {
    ok("changing a column's type (colProp==='type') triggers renderFieldsEditor() — the options box appears immediately, not on the next unrelated click");
} else {
    bad("no renderFieldsEditor() call found gated on colProp==='type'", 'GH#107 regression — the select-options textarea will only appear after an unrelated structural action');
}

// ── 4. saveType() must not inline cleanFieldsForSave(editorFields)
// directly inside the payload object literal — any throw there must be
// catchable and surfaced via showSaveError(), not a silent dead click. ───
if (preg_match('/fields\s*:\s*cleanFieldsForSave\s*\(\s*editorFields\s*\)/', $js)) {
    bad('cleanFieldsForSave(editorFields) is still inlined directly in the payload literal', 'a throw here escapes saveType() before postJson()/.catch() exist — GH#107\'s root failure mode for ANY future defect in that function, not just this one');
} else {
    ok('cleanFieldsForSave(editorFields) is no longer inlined directly in the payload literal');
}
if (preg_match('/try\s*\{\s*cleanedFields\s*=\s*cleanFieldsForSave\s*\(\s*editorFields\s*\)\s*;\s*\}\s*catch\s*\(\s*err\s*\)\s*\{[\s\S]{0,200}showSaveError/', $js)) {
    ok('cleanFieldsForSave(editorFields) is now wrapped in try/catch, surfacing a failure via showSaveError() instead of dying silently');
} else {
    bad('cleanFieldsForSave(editorFields) is not wrapped in a try/catch that calls showSaveError()', 'a future throw in cleanFieldsForSave() would still be a silent dead click');
}
if (preg_match('/fields\s*:\s*cleanedFields/', $js)) {
    ok('the payload literal uses the pre-computed cleanedFields variable');
} else {
    bad('the payload literal does not reference a cleanedFields variable', 'the try/catch extraction may not actually be wired to the payload');
}

// ── 5. cleanFieldsForSave() itself is unchanged (this fix works by
// preventing the bad input shape from ever reaching it, not by loosening
// its own validation). ─────────────────────────────────────────────────
if (preg_match('/function cleanFieldsForSave[\s\S]{0,900}co\.options\.filter/', $js)) {
    ok('cleanFieldsForSave() still calls .filter() on column options directly (no defensive Array.isArray() loosening added — the fix is at the source of the bad data, not a patch over it)');
} else {
    bad('cleanFieldsForSave() no longer calls .filter() on co.options as expected', 're-verify the fix location — this pins down that the fix is upstream, not a workaround inside this function');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
