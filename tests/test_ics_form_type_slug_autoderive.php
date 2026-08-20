<?php
/**
 * Phase 140 (2026-08-16) — slug auto-derive from Form Title, in
 * assets/js/ics-form-type-admin.js (slugifyTitle()).
 *
 * The type-authoring editor derives the Slug field from Form Title while
 * creating a NEW type, stopping the moment the author types into Slug
 * directly (that manual choice then wins for the rest of the editing
 * session -- see slugManuallyEdited in bindEvents()). This is the same
 * "auto-derive until manually touched" convention used elsewhere in this
 * app for a permanent-identifier-from-display-name field.
 *
 * This extracts the REAL slugifyTitle() function via node (same convention
 * as tests/test_ics_form_types_render_safety.php) and proves its output
 * matches the server's own acceptance pattern
 * (inc/ics-form-types.php ics_form_type_validate_metadata():
 * ^[a-z][a-z0-9_-]{2,59}$, 3-60 chars total) for a range of real titles,
 * including the two the module's own training video types in: "Rehab
 * Check-In" and a same-slug-colliding "REHAB CHECK-IN".
 *
 * Usage: php tests/test_ics_form_type_slug_autoderive.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 140 — ics-form-type-admin.js slugifyTitle() ===\n\n";

$base = realpath(__DIR__ . '/..');
$adminPath = $base . '/assets/js/ics-form-type-admin.js';
t('assets/js/ics-form-type-admin.js exists', is_file($adminPath));

$src = (string) file_get_contents($adminPath);
t('slugifyTitle() is defined in the file', (bool) preg_match('/function\s+slugifyTitle\s*\(/', $src));
t('Form Title input listener stops auto-deriving once editingId > 0 or the slug was manually edited',
    (bool) preg_match('/if\s*\(\s*editingId\s*>\s*0\s*\|\|\s*slugManuallyEdited\s*\)\s*return;/', $src));
t('typing into #ftSlug directly sets slugManuallyEdited = true',
    (bool) preg_match('/slugManuallyEdited\s*=\s*true;/', $src));
t('opening the editor resets slugManuallyEdited for a fresh session',
    (bool) preg_match('/slugManuallyEdited\s*=\s*false;/', $src));

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

function _p140sd_extract_fn(string $src, string $name): string {
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

$harness = sys_get_temp_dir() . '/tcad_p140_slug_autoderive_' . getmypid() . '.js';

$body = _p140sd_extract_fn($src, 'slugifyTitle');
$body .= <<<'JS'

// Server's own acceptance pattern (inc/ics-form-types.php
// ics_form_type_validate_metadata()) -- the derived slug must satisfy it
// for every realistic title, or the auto-derive would hand the author a
// value the server then rejects on Save.
var SERVER_PATTERN = /^[a-z][a-z0-9_-]{2,59}$/;

var cases = [
    ['Rehab Check-In', 'rehab-check-in'],
    ['REHAB CHECK-IN', 'rehab-check-in'],           // collides with the row above -- proves the duplicate-slug demo is real
    ['Shelter Intake', 'shelter-intake'],
    ['  Extra   Spaces  Everywhere  ', 'extra-spaces-everywhere'],
    ["CERT Damage Assessment (v2)", 'cert-damage-assessment-v2'],
];

var results = [];
function check(name, cond, detail) { results.push((cond ? 'PASS' : 'FAIL') + '|' + name + '|' + (detail || '')); }

cases.forEach(function (c) {
    var got = slugifyTitle(c[0]);
    check('slugifyTitle(' + JSON.stringify(c[0]) + ') === ' + JSON.stringify(c[1]),
        got === c[1], 'got ' + JSON.stringify(got));
    check('slugifyTitle(' + JSON.stringify(c[0]) + ') satisfies the server pattern',
        SERVER_PATTERN.test(got), got);
});

check('two titles that differ only in case/punctuation collide to the SAME slug',
    slugifyTitle('Rehab Check-In') === slugifyTitle('REHAB CHECK-IN'));

check('a title starting with a digit gets a letter-prefixed slug (server requires a leading letter)',
    /^[a-z]/.test(slugifyTitle('2026 Intake Form')));

check('an empty title produces an empty slug (nothing to save yet, not a crash)',
    slugifyTitle('') === '');

console.log(JSON.stringify(results));
JS;

file_put_contents($harness, $body);

$out = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1');
@unlink($harness);

$decoded = null;
if (is_string($out)) {
    $trimmed = trim($out);
    if ($trimmed !== '' && $trimmed[0] === '[') {
        $decoded = json_decode($trimmed, true);
    }
}

if (!is_array($decoded)) {
    t('node harness ran and returned parseable results', false);
    echo "  raw output: " . substr((string) $out, 0, 2000) . "\n";
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
