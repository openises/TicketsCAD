<?php
/**
 * Phase 140 (2026-08-16) — GH#69 follow-up fix: a table field's own
 * "Default rows" setting (the field-builder input at
 * data-field-prop="default_rows", inc/ics-form-types.php caps it 1-5 via
 * ics_form_type_validate_fields()) must actually control how many empty
 * rows a brand-new form instance starts with.
 *
 * Root cause this fixes: assets/js/ics-forms.js's renderTableField()
 * hardcoded "start with 3 empty rows" whenever an instance had no saved
 * row data yet, completely ignoring field.default_rows -- an exposed,
 * labeled admin control with zero effect on the one thing it claims to
 * control. Caught while building the training video for this feature
 * (the video's field-builder segment creates a table field and would have
 * silently shown 3 rows regardless of what was configured).
 *
 * Usage: php tests/test_ics_form_types_default_rows.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 140 — table field default_rows honored on a NEW instance ===\n\n";

$base = realpath(__DIR__ . '/..');
$icsFormsPath = $base . '/assets/js/ics-forms.js';
$src = (string) file_get_contents($icsFormsPath);
t('renderTableField() no longer hardcodes 3 empty rows',
    strpos($src, 'Start with 3 empty rows') === false);
t('renderTableField() reads field.default_rows',
    (bool) preg_match('/parseInt\(\s*field\.default_rows\s*,\s*10\s*\)/', $src));

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

function _p140dr_extract_fn(string $src, string $name): string {
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

$harness = sys_get_temp_dir() . '/tcad_p140_default_rows_' . getmypid() . '.js';

$body = <<<'JS'
function escHtml(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function renderSelectField(id, name, options, value, tabIdx) { return '<select></select>'; }

JS;
$body .= _p140dr_extract_fn($src, 'buildTableCell');
$body .= _p140dr_extract_fn($src, 'buildTableRow');
$body .= _p140dr_extract_fn($src, 'renderTableField');
$body .= <<<'JS'

var results = [];
function check(name, cond, detail) { results.push((cond ? 'PASS' : 'FAIL') + '|' + name + '|' + (detail || '')); }

function rowCount(html) {
    var m = html.match(/<tr>/g);
    // buildTableRow always emits one <tr>...</tr> per data row; the
    // <thead> row also uses <tr> without the ">" immediately after (same
    // literal though) -- count only rows inside <tbody>.
    var body = html.split('<tbody>')[1] || '';
    var rows = body.match(/<tr>/g);
    return rows ? rows.length : 0;
}

var field1 = { key: 'vitals_log', label: 'Vitals Log', columns: [{ key: 'time', label: 'Time', type: 'time' }], default_rows: 1 };
check('default_rows=1 renders exactly 1 empty row on a brand-new instance',
    rowCount(renderTableField(field1, [])) === 1, rowCount(renderTableField(field1, [])));

var field3 = { key: 'log2', label: 'Log', columns: [{ key: 'x', label: 'X', type: 'text' }], default_rows: 3 };
check('default_rows=3 renders exactly 3 empty rows', rowCount(renderTableField(field3, [])) === 3);

var field5 = { key: 'log5', label: 'Log', columns: [{ key: 'x', label: 'X', type: 'text' }], default_rows: 5 };
check('default_rows=5 (the max the builder allows) renders exactly 5 empty rows', rowCount(renderTableField(field5, [])) === 5);

var fieldOver = { key: 'logOver', label: 'Log', columns: [{ key: 'x', label: 'X', type: 'text' }], default_rows: 999 };
check('a default_rows value beyond the builder max (999) is clamped, never rendered literally', rowCount(renderTableField(fieldOver, [])) === 5);

var fieldMissing = { key: 'logMissing', label: 'Log', columns: [{ key: 'x', label: 'X', type: 'text' }] };
check('a table field with no default_rows at all (older/malformed data) falls back to 1 row, not 0', rowCount(renderTableField(fieldMissing, [])) === 1);

var fieldZero = { key: 'logZero', label: 'Log', columns: [{ key: 'x', label: 'X', type: 'text' }], default_rows: 0 };
check('default_rows=0 falls back to at least 1 row (an instance with zero fillable rows is a dead end)', rowCount(renderTableField(fieldZero, [])) === 1);

var fieldSaved = { key: 'logSaved', label: 'Log', columns: [{ key: 'x', label: 'X', type: 'text' }], default_rows: 1 };
check('reopening an instance WITH saved row data ignores default_rows and renders the actual saved rows',
    rowCount(renderTableField(fieldSaved, [{ x: 'a' }, { x: 'b' }, { x: 'c' }, { x: 'd' }])) === 4);

console.log(JSON.stringify(results));
JS;

file_put_contents($harness, $body);
$out = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1');
@unlink($harness);

$decoded = null;
if (is_string($out)) {
    $trimmed = trim($out);
    if ($trimmed !== '' && $trimmed[0] === '[') $decoded = json_decode($trimmed, true);
}
if (!is_array($decoded)) {
    t('node harness ran and returned parseable results', false);
    echo "  raw output: " . substr((string) $out, 0, 2000) . "\n";
} else {
    foreach ($decoded as $line) {
        $parts = explode('|', $line, 3);
        t($parts[1] ?? '(unnamed)', ($parts[0] ?? '') === 'PASS');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
