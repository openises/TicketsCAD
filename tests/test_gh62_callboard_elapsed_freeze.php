<?php
/**
 * GH#62 (rjonesbsink, 2026-08-15) — a closed call's elapsed timer on the
 * Dispatch Call Board kept counting forever, since getElapsedInfo() had no
 * way to know the call had ended: it always measured created -> now. The
 * fix passes the call's problemend timestamp when it's closed (same
 * isClosed condition -- problemend set AND status===1 -- the file already
 * uses 120 lines below for the "Closed" timeline step), so the elapsed
 * value freezes at the real close time instead of climbing toward red and
 * critical for a call that is finished.
 *
 * Extracts and drives the REAL getElapsedInfo()/formatElapsed()/
 * getElapsedClass() functions from assets/js/callboard.js under node --
 * not a reimplementation of the math -- since the file's IIFE exposes
 * nothing to test against directly.
 */

$root = dirname(__DIR__);
$jsPath = $root . '/assets/js/callboard.js';
$src = (string) file_get_contents($jsPath);

$pass = 0; $fail = 0;
function test62(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#62 Call Board elapsed-freeze regression ===\n\n";

// ── Structural: the render + update paths must both pass problemend through ──
test62('renderRow computes isClosed the same way the existing Closed-step check does',
    (bool) preg_match('/var isClosed = inc\.problemend && parseInt\(inc\.status, 10\) === 1;/', $src));
test62('renderRow passes problemend into getElapsedInfo only when closed',
    strpos($src, 'getElapsedInfo(inc.created, isClosed ? inc.problemend : null)') !== false);
test62('the rendered cell carries a data-problemend attribute when closed',
    strpos($src, "data-problemend=") !== false);
test62('updateAllElapsed() reads data-problemend and forwards it',
    (bool) preg_match('/var problemend = cells\[i\]\.getAttribute\(.data-problemend.\);\s*\n\s*\n?\s*var info = getElapsedInfo\(created, problemend\);/', $src));

// ── Functional: extract and run the real pure functions under node ──
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    // Extract each function's own source verbatim (start-of-function to its
    // matching closing brace at the same indent level), so this drives the
    // actual shipped code, not a copy of its logic.
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

    $fns = ['getElapsedInfo', 'formatElapsed', 'getElapsedClass'];
    $extracted = '';
    $allFound = true;
    foreach ($fns as $fn) {
        $code = extract_fn($src, $fn);
        if ($code === null) { $allFound = false; test62("extracted $fn() from callboard.js", false); continue; }
        $extracted .= $code . "\n";
    }
    test62('extracted all three functions getElapsedInfo/formatElapsed/getElapsedClass', $allFound);

    if ($allFound) {
        // Thresholds are file-level vars the functions close over; redeclare
        // with the same values (read from the file, not hand-copied) so the
        // extracted functions run standalone.
        preg_match('/var THRESH_YELLOW\s*=\s*([^;]+);/', $src, $ty);
        preg_match('/var THRESH_ORANGE\s*=\s*([^;]+);/', $src, $to);
        preg_match('/var THRESH_RED\s*=\s*([^;]+);/', $src, $tr);
        preg_match('/var THRESH_CRITICAL\s*=\s*([^;]+);/', $src, $tc);

        $harness = "var THRESH_YELLOW = {$ty[1]};\n"
            . "var THRESH_ORANGE = {$to[1]};\n"
            . "var THRESH_RED = {$tr[1]};\n"
            . "var THRESH_CRITICAL = {$tc[1]};\n"
            . $extracted
            . <<<'JS'
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

// An OPEN call (no problemend): elapsed keeps climbing with wall-clock time --
// simulate by using a `created` far enough in the past that it's already red.
var openCreated = new Date(Date.now() - (20 * 60 * 1000)).toISOString(); // 20 min ago
var openInfo = getElapsedInfo(openCreated, null);
check('an open call (no problemend) still measures against "now"',
      openInfo.seconds >= 1190 && openInfo.seconds <= 1210, // ~20 min, small tolerance
      'seconds=' + openInfo.seconds);

// A CLOSED call: created 20 min ago, closed after exactly 2 minutes
// (comfortably under THRESH_YELLOW=5min, so the color check below isn't
// sitting on a threshold boundary).
var created = new Date(Date.now() - (20 * 60 * 1000));
var closedAt = new Date(created.getTime() + (2 * 60 * 1000));
var closedInfo = getElapsedInfo(created.toISOString(), closedAt.toISOString());
check('a closed call freezes at (problemend - created), not (now - created)',
      closedInfo.seconds === 120, 'seconds=' + closedInfo.seconds);
check('a closed call frozen at 2 minutes is still green, not red/critical from wall-clock drift',
      closedInfo.cssClass === 'cb-elapsed-green', 'cssClass=' + closedInfo.cssClass);
check('the frozen text reads 02:00', closedInfo.text === '02:00', 'text=' + closedInfo.text);

// Re-running "later" (simulated by calling again) must return the SAME
// frozen value -- this is the actual bug: the interval calls this every
// second, and a real fix must be stable across repeated calls.
var closedInfoAgain = getElapsedInfo(created.toISOString(), closedAt.toISOString());
check('calling getElapsedInfo again for the same closed call returns the identical frozen value',
      closedInfoAgain.seconds === closedInfo.seconds);

console.log(out.join('\n'));
JS;

        $h = sys_get_temp_dir() . '/tcad_gh62_harness_' . getmypid() . '_' . mt_rand() . '.js';
        file_put_contents($h, $harness);
        $raw = @shell_exec($node . ' ' . escapeshellarg($h) . ' 2>&1');
        @unlink($h);

        if (!is_string($raw) || trim($raw) === '') {
            test62('node harness produced output', false, 'no output — see harness for a syntax error');
        } else {
            foreach (explode("\n", trim($raw)) as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) < 2 || ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL')) {
                    echo "  (harness) $line\n";
                    continue;
                }
                test62('[js] ' . $parts[1], $parts[0] === 'PASS', $parts[2] ?? '');
            }
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
