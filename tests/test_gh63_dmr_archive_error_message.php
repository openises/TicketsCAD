<?php
/**
 * GH#63 (rjonesbsink, 2026-08-15) — api/dmr-history.php always returns a
 * useful {"error": "..."} body on failure (missing permission, no DMR
 * channel configured, wrong method, query failure), but dmr-archive.js's
 * fetch chain rejected with the bare HTTP status code
 * (`Promise.reject(r.status)`), throwing the message away before the
 * catch handler ever saw it. So every failure read "Load failed: 404" (or
 * 403/405/500) no matter what actually went wrong -- including the
 * no-bridge-configured case, which reads like a broken install rather than
 * "this box has no DMR bridge, and that is fine".
 *
 * Fixed by reading the response body before rejecting, falling back to
 * "HTTP <status>" only if the server didn't send JSON at all.
 *
 * Extracts the real response-handling function (the first .then() in
 * fetchAndRender()) from assets/js/dmr-archive.js under node and drives it
 * with fake Response-like objects -- not a reimplementation of the logic.
 */

$root = dirname(__DIR__);
$jsPath = $root . '/assets/js/dmr-archive.js';
$src = (string) file_get_contents($jsPath);

$pass = 0; $fail = 0;
function test63(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#63 DMR Archive error-message regression ===\n\n";

test63('the old bare Promise.reject(r.status) pattern is gone',
    strpos($src, 'Promise.reject(r.status)') === false);
test63('the response handler reads body.error before rejecting',
    strpos($src, 'Promise.reject((body && body.error)') !== false);
test63('falls back to "HTTP <status>" when the body has no error field',
    strpos($src, "'HTTP ' + r.status") !== false);

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    // Extract the exact `.then(function (r) { ... })` block that follows the
    // fetch() call, by bracket-depth matching from its `function (r) {`.
    $marker = "fetch('api/dmr-history.php?' + params.toString(), { credentials: 'same-origin' })";
    $start = strpos($src, $marker);
    test63('located the fetch() call site in dmr-archive.js', $start !== false);

    $handlerSrc = null;
    if ($start !== false) {
        $fnStart = strpos($src, 'function (r) {', $start);
        test63('located the response handler function', $fnStart !== false);
        if ($fnStart !== false) {
            $depth = 0; $i = $fnStart; $len = strlen($src); $started = false;
            for (; $i < $len; $i++) {
                if ($src[$i] === '{') { $depth++; $started = true; }
                elseif ($src[$i] === '}') {
                    $depth--;
                    if ($started && $depth === 0) { $i++; break; }
                }
            }
            $handlerSrc = substr($src, $fnStart, $i - $fnStart);
        }
    }

    if ($handlerSrc !== null) {
        $harness = <<<'JS'
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var handler =
JS
        . $handlerSrc . ";\n" .
        <<<'JS'

function fakeResponse(ok, status, jsonResult, jsonRejects) {
    return {
        ok: ok,
        status: status,
        json: function () {
            return jsonRejects
                ? Promise.reject(new Error('not JSON'))
                : Promise.resolve(jsonResult);
        }
    };
}

var checks = [];

checks.push(
    handler(fakeResponse(true, 200, { rows: [] })).then(function (j) {
        check('ok:true response resolves with the parsed JSON body', j && Array.isArray(j.rows));
    })
);

checks.push(
    handler(fakeResponse(false, 403, { error: 'Missing required permission: action.dmr_receive' }))
        .then(function () { check('403 with a permission message should have rejected', false); })
        .catch(function (err) {
            check('403 rejects with the server\'s real permission message, not "403"',
                  err === 'Missing required permission: action.dmr_receive', 'got ' + JSON.stringify(err));
        })
);

checks.push(
    handler(fakeResponse(false, 404, { error: 'No DMR channel available' }))
        .then(function () { check('404 with a body message should have rejected', false); })
        .catch(function (err) {
            check('404 (no bridge configured) rejects with the real message, not "404"',
                  err === 'No DMR channel available', 'got ' + JSON.stringify(err));
        })
);

checks.push(
    handler(fakeResponse(false, 500, {}))
        .then(function () { check('500 with an empty body should have rejected', false); })
        .catch(function (err) {
            check('a body with no error field falls back to "HTTP <status>"',
                  err === 'HTTP 500', 'got ' + JSON.stringify(err));
        })
);

checks.push(
    handler(fakeResponse(false, 502, null, true))
        .then(function () { check('a non-JSON error body should have rejected', false); })
        .catch(function (err) {
            check('a response that is not JSON at all still falls back to "HTTP <status>", not a crash',
                  err === 'HTTP 502', 'got ' + JSON.stringify(err));
        })
);

Promise.all(checks).then(function () {
    console.log(out.join('\n'));
}).catch(function (e) {
    console.log('FAIL|harness threw|' + String(e));
});
JS;

        $h = sys_get_temp_dir() . '/tcad_gh63_harness_' . getmypid() . '_' . mt_rand() . '.js';
        file_put_contents($h, $harness);
        $raw = @shell_exec($node . ' ' . escapeshellarg($h) . ' 2>&1');
        @unlink($h);

        if (!is_string($raw) || trim($raw) === '') {
            test63('node harness produced output', false, 'no output — see harness for a syntax error');
        } else {
            foreach (explode("\n", trim($raw)) as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) < 2 || ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL')) {
                    echo "  (harness) $line\n";
                    continue;
                }
                test63('[js] ' . $parts[1], $parts[0] === 'PASS', $parts[2] ?? '');
            }
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
