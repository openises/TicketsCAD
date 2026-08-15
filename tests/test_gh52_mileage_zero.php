<?php
/**
 * GH#52 (cbyrdmo, 2026-08-15) — a starting-mileage odometer reading of
 * literal "0" (e.g. a trip gauge that was just reset) was always rejected
 * as "This field is required", even though the dispatcher had typed a
 * value. Root cause in both extra-data-collection modals: the numeric/
 * mileage/facility branch of collect() used `parseFloat(v) || fallback`
 * (app.js) or `parseInt(v, 10) || null` (status-extra-data-prompt.js's
 * facility branch) — `||` treats a legitimate 0 result as falsy and falls
 * through to the fallback, which is null/missing for a required field.
 * Fixed by checking isNaN(...) explicitly instead of truthiness in both
 * files.
 *
 * Extracts and drives the REAL collect() functions from assets/js/app.js
 * (_openExtraDataPrompt, the dashboard/command-bar modal) and
 * assets/js/status-extra-data-prompt.js (the incident-detail-page modal,
 * GH#52 follow-up 2026-08-13) under node — not a reimplementation of the
 * logic — since both files' IIFEs expose nothing to test against directly.
 */

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function test52(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#52 mileage=0 falsy-zero regression ===\n\n";

// ── Structural: the fixed pattern must be present, the buggy pattern gone ──
$appJs = (string) file_get_contents($root . '/assets/js/app.js');
test52('app.js no longer uses the falsy-or fallback for numeric/mileage/facility collect()',
    strpos($appJs, "parseFloat(v) || (type === 'facility'") === false,
    'the old `parseFloat(v) || fallback` pattern is still present');
test52('app.js collect() checks isNaN(num) explicitly for numeric/mileage',
    (bool) preg_match('/var num = parseFloat\(v\);\s*\n\s*return isNaN\(num\) \? null : num;/', $appJs));
test52('app.js collect() checks isNaN(fid) explicitly for facility',
    (bool) preg_match('/var fid = parseInt\(v, 10\);\s*\n\s*return isNaN\(fid\) \? null : fid;/', $appJs));

$edPromptJs = (string) file_get_contents($root . '/assets/js/status-extra-data-prompt.js');
test52('status-extra-data-prompt.js no longer uses `parseInt(v, 10) || null` for facility',
    strpos($edPromptJs, "parseInt(v, 10) || null") === false,
    'the old falsy-or fallback is still present');
test52('status-extra-data-prompt.js checks isNaN(fid) explicitly for facility',
    (bool) preg_match('/var fid = parseInt\(v, 10\);\s*\n\s*return isNaN\(fid\) \? null : fid;/', $edPromptJs));
test52('status-extra-data-prompt.js mileage/numeric branch returns parseFloat(v) directly (never had the bug)',
    strpos($edPromptJs, 'return parseFloat(v);') !== false);

// ── Functional: extract and run the real collect() closures under node ──
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    function extract_fn(string $src, string $name): ?string {
        $start = strpos($src, 'function ' . $name . '(');
        if ($start === false) return null;
        $depth = 0; $i = $start; $len = strlen($src); $started = false;
        for (; $i < $len; $i++) {
            if ($src[$i] === '{') { $depth++; $started = true; }
            elseif ($src[$i] === '}') {
                $depth--;
                if ($started && $depth === 0) { $i++; break; }
            }
        }
        return substr($src, $start, $i - $start);
    }

    // app.js's collect() closes over `type` and `bodyEl` (both set up by the
    // enclosing _openExtraDataPrompt). Supply fakes with the same shape.
    $appCollect = extract_fn($appJs, 'collect');
    // status-extra-data-prompt.js also defines a `collect`; extract the
    // SECOND occurrence's function body by searching past the first file's
    // worth of content — simplest correct approach is to extract from each
    // file's own source independently, which extract_fn already does since
    // it's called per-file below.
    $edCollect = extract_fn($edPromptJs, 'collect');

    test52('extracted collect() from app.js', $appCollect !== null);
    test52('extracted collect() from status-extra-data-prompt.js', $edCollect !== null);

    if ($appCollect !== null && $edCollect !== null) {
        $harness = <<<'JS'
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

function fakeBodyEl(selector, value) {
    return {
        querySelector: function (sel) {
            if (sel !== selector) return null;
            return { value: value };
        }
    };
}

// ---- app.js's collect() (dashboard / command-bar modal, #edInput) ----
(function () {
JS
        . $appCollect .
        <<<'JS'

    var type = 'mileage';
    var bodyEl = fakeBodyEl('#edInput', '0');
    check('app.js: mileage "0" collects as the number 0, not null',
          collect() === 0, 'got ' + JSON.stringify(collect()));

    type = 'numeric';
    bodyEl = fakeBodyEl('#edInput', '0');
    check('app.js: numeric "0" collects as the number 0, not null',
          collect() === 0, 'got ' + JSON.stringify(collect()));

    type = 'facility';
    bodyEl = fakeBodyEl('#edInput', '0');
    check('app.js: facility id "0" collects as the number 0, not null',
          collect() === 0, 'got ' + JSON.stringify(collect()));

    type = 'mileage';
    bodyEl = fakeBodyEl('#edInput', '');
    check('app.js: empty mileage input still collects as null (required-check still works)',
          collect() === null, 'got ' + JSON.stringify(collect()));

    type = 'mileage';
    bodyEl = fakeBodyEl('#edInput', 'abc');
    check('app.js: non-numeric mileage input collects as null, not NaN',
          collect() === null, 'got ' + JSON.stringify(collect()));

    type = 'mileage';
    bodyEl = fakeBodyEl('#edInput', '142');
    check('app.js: an ordinary positive mileage reading still works',
          collect() === 142, 'got ' + JSON.stringify(collect()));
})();

// ---- status-extra-data-prompt.js's collect() (incident-detail modal, #tcadEdInput) ----
(function () {
JS
        . $edCollect .
        <<<'JS'

    var type = 'mileage';
    var bodyEl = fakeBodyEl('#tcadEdInput', '0');
    check('status-extra-data-prompt.js: mileage "0" collects as the number 0, not null',
          collect() === 0, 'got ' + JSON.stringify(collect()));

    type = 'facility';
    bodyEl = fakeBodyEl('#tcadEdInput', '0');
    check('status-extra-data-prompt.js: facility id "0" collects as the number 0, not null',
          collect() === 0, 'got ' + JSON.stringify(collect()));

    type = 'mileage';
    bodyEl = fakeBodyEl('#tcadEdInput', '');
    check('status-extra-data-prompt.js: empty mileage input still collects as null',
          collect() === null, 'got ' + JSON.stringify(collect()));
})();

console.log(out.join('\n'));
JS;

        $h = sys_get_temp_dir() . '/tcad_gh52_mileage_harness_' . getmypid() . '_' . mt_rand() . '.js';
        file_put_contents($h, $harness);
        $raw = @shell_exec($node . ' ' . escapeshellarg($h) . ' 2>&1');
        @unlink($h);

        if (!is_string($raw) || trim($raw) === '') {
            test52('node harness produced output', false, 'no output — see harness for a syntax error');
        } else {
            foreach (explode("\n", trim($raw)) as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) < 2 || ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL')) {
                    echo "  (harness) $line\n";
                    continue;
                }
                test52('[js] ' . $parts[1], $parts[0] === 'PASS', $parts[2] ?? '');
            }
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
