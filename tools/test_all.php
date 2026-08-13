<?php
/**
 * Master Test Runner — Discovers and runs all test_*.php files in tools/ and tests/.
 *
 * Usage: php tools/test_all.php
 *        php tools/test_all.php --only=<substring>   (scope to matching files)
 *
 * Executes each test file as a subprocess, classifies the result, and reports
 * combined totals. Returns exit code 1 if any file FAILED or ERRORED.
 *
 * `--only=` exists so tests/test_runner_end_to_end.php can drive THIS runner
 * against a handful of planted probe files instead of the whole suite. It
 * announces itself loudly in the header and in the TOTAL line, because a
 * filtered run that reads like a full one is the same class of lie this
 * runner was rewritten to stop telling. Never use it in CI.
 *
 * CI / no-web-server mode (QA automation, Eric 2026-07-07):
 *   NEWUI_TEST_NO_HTTP=1 php tools/test_all.php
 * skips every test file whose header carries a `@requires-http` marker
 * (integration tests that hit http://localhost via a running Apache).
 * Skipped files are listed but count as neither pass nor fail, so the
 * suite can be a meaningful red/green gate on a DB-only environment
 * (GitHub Actions fresh-install job, headless VMs).
 *
 * The contract every test file must honour — and the reasoning behind each
 * clause of it — lives in tools/suite_contract.php, which also holds the
 * classifier. tests/test_runner_contract.php drives that same function, so
 * the gate's own logic is covered by the suite it gates.
 *
 * In short: the runner no longer infers success from the absence of evidence.
 * A file that cannot produce a trustworthy result is ERRORED — its own
 * category, distinct from FAILED — and one errored file turns the whole run
 * red. Errored and failed files get the tail of their output printed, because
 * a runner that swallows the child's stdout leaves CI nothing to diagnose from.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/suite_contract.php';

$toolsDir = __DIR__;
$phpBin = PHP_BINARY;
$noHttp = getenv('NEWUI_TEST_NO_HTTP') === '1';

// ── Recursion backstop ───────────────────────────────────────────────
// A test file may now legitimately invoke this runner (that is how the
// end-to-end contract test proves the wiring), which means the runner can
// appear in its own process tree. One level is intended; two is a mistake
// that would fork-bomb the machine, so refuse rather than discover it the
// hard way. putenv() so the value is inherited by every child.
$depth = (int) getenv('NEWUI_TEST_ALL_DEPTH');
if ($depth >= 2) {
    fwrite(STDERR, "test_all.php: refusing to nest more than one level deep "
        . "(NEWUI_TEST_ALL_DEPTH={$depth}). A test file invoked the runner, which "
        . "invoked a test file that invoked the runner.\n");
    exit(1);
}
putenv('NEWUI_TEST_ALL_DEPTH=' . ($depth + 1));

// ── --only=<substring> ───────────────────────────────────────────────
$onlyFilters = [];
// $argv is absent unless register_argc_argv is on (it always is under CLI, but
// this file should not warn if it is ever reached another way).
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (strpos($arg, '--only=') === 0) {
        $needle = substr($arg, 7);
        if ($needle !== '') {
            $onlyFilters[] = $needle;
        }
    }
}

// Discover all test files (exclude this runner)
// Search both tools/ and tests/ directories
$testFiles = glob($toolsDir . '/test_*.php');
$testsDir = dirname($toolsDir) . '/tests';
if (is_dir($testsDir)) {
    $testFiles = array_merge($testFiles, glob($testsDir . '/test_*.php'));
}
$testFiles = array_filter($testFiles, function ($f) use ($onlyFilters) {
    $base = basename($f);
    if ($base === 'test_all.php') {
        return false;
    }
    if (!$onlyFilters) {
        return true;
    }
    foreach ($onlyFilters as $needle) {
        if (strpos($base, $needle) !== false) {
            return true;
        }
    }
    return false;
});
sort($testFiles);

echo "=== TicketsCAD NewUI Test Suite ===\n";
echo "PHP: {$phpBin}\n";
if ($onlyFilters) {
    echo "*** FILTERED RUN — only files matching: " . implode(', ', $onlyFilters) . "\n";
    echo "*** This is NOT a full suite run and proves nothing about the tree.\n";
}
echo "Found " . count($testFiles) . " test files\n\n";

$totalPass = 0;
$totalFail = 0;
$totalSkipAssert = 0;   // files that ran but declared a skip (0/0 + SKIP)
$skippedFiles = [];
$fileResults = [];

foreach ($testFiles as $file) {
    $basename = basename($file);

    // NEWUI_TEST_NO_HTTP=1 → skip HTTP-integration tests. The marker is
    // `@requires-http` as an actual docblock TAG — i.e. the first token on
    // a line, after stripping a leading `*`/`/` comment prefix — not a
    // literal substring anywhere in the file.
    //
    // Found during Phase 138's final adversarial review (2026-08-13): the
    // old check was `strpos($head, '@requires-http') !== false` — a bare
    // substring search over the whole head block. Several files' OWN
    // docblocks explain a design decision by writing prose like "NOT
    // @requires-http: this spins up its own local server..." — that prose
    // contains the literal substring "@requires-http" too, so the old
    // check skipped them anyway, silently inverting the sentence's actual
    // meaning. Confirmed live: tests/test_public_board_frontend_safety.php
    // and tests/test_public_board_org_scope.php — both self-contained (one
    // drives static source through Node with no server at all, the other
    // spins up its own local PHP server via tests/_pb_test_server.php,
    // exactly like the already-established tests/test_web_exposure_
    // backups_probe.php pattern) — were skipped under NEWUI_TEST_NO_HTTP=1,
    // which is the exact flag `.github/workflows/qa.yml` runs the whole
    // suite under on every push. Both ran clean (39/0 and 11/0) the moment
    // they were driven directly instead of through this runner. This is
    // the same "test runner scored silence as success" disease this file's
    // own history already names (see CLAUDE.md) — a smaller, quieter
    // instance of it, but the same root cause: string-matching stood in
    // for actually parsing the thing being checked.
    if ($noHttp) {
        $isRequiresHttp = false;
        foreach (array_slice(file($file), 0, 60) as $line) {
            $tagStart = ltrim(rtrim($line), "*/ \t\r\n");
            if (strpos($tagStart, '@requires-http') === 0) {
                $isRequiresHttp = true;
                break;
            }
        }
        if ($isRequiresHttp) {
            $skippedFiles[] = $basename;
            echo "Skipping " . str_pad($basename, 35) . " (@requires-http, NEWUI_TEST_NO_HTTP=1)\n";
            continue;
        }
    }

    // Run each test file as a separate process
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($file) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    $outputText = implode("\n", $output);

    $verdict = test_all_classify($outputText, $exitCode);
    $filePassed = $verdict['pass'];
    $fileFailed = $verdict['fail'];

    // An errored file's counts are not trustworthy — it may have died half
    // way — so they do not go into the totals. It counts as one errored
    // file instead, and the run goes red on that alone.
    if ($verdict['status'] !== 'ERROR') {
        $totalPass += $filePassed;
        $totalFail += $fileFailed;
        if ($verdict['status'] === 'SKIP') {
            $totalSkipAssert++;
        }
    }

    $padded = str_pad($basename, 42);
    switch ($verdict['status']) {
        case 'SKIP':
            echo "  SKIP  {$padded} (declared a skip — 0 assertions)\n";
            break;
        case 'ERROR':
            echo "  ERROR {$padded} " . implode('; ', $verdict['reasons']) . "\n";
            break;
        case 'FAIL':
            echo "  FAIL  {$padded} {$filePassed} passed, {$fileFailed} failed"
                . ($verdict['reasons'] ? ' — ' . implode('; ', $verdict['reasons']) : '') . "\n";
            break;
        default:
            echo "  ok    {$padded} {$filePassed} passed, {$fileFailed} failed\n";
    }

    $fileResults[] = [
        'file'    => $basename,
        'status'  => $verdict['status'],
        'pass'    => $filePassed,
        'fail'    => $fileFailed,
        'exit'    => $exitCode,
        'reasons' => $verdict['reasons'],
        'output'  => $outputText,
    ];
}

$failedFiles  = array_values(array_filter($fileResults, fn($r) => $r['status'] === 'FAIL'));
$erroredFiles = array_values(array_filter($fileResults, fn($r) => $r['status'] === 'ERROR'));

echo "\n=== TOTAL: {$totalPass} passed, {$totalFail} failed, "
    . count($erroredFiles) . " errored ===\n";
if ($onlyFilters) {
    echo "(FILTERED RUN — only files matching: " . implode(', ', $onlyFilters)
        . " — not the full suite)\n";
}
if (!empty($skippedFiles)) {
    echo "(" . count($skippedFiles) . " file(s) skipped — need a running web server)\n";
}
if ($totalSkipAssert > 0) {
    echo "({$totalSkipAssert} file(s) declared a skip — prerequisites absent, 0 assertions)\n";
}

if ($failedFiles) {
    echo "\nFailed test files (assertions failed):\n";
    foreach ($failedFiles as $r) {
        echo "  - {$r['file']} ({$r['fail']} failures)\n";
        echo test_all_tail($r['output']);
    }
}

if ($erroredFiles) {
    echo "\nErrored test files (result could not be trusted — NOT counted as passing):\n";
    foreach ($erroredFiles as $r) {
        echo "  - {$r['file']} — " . implode('; ', $r['reasons']) . "\n";
        echo test_all_tail($r['output']);
    }
    echo "\nEvery test file must end with a canonical summary line:\n";
    echo "    echo \"\\n=== \$pass passed, \$fail failed ===\\n\";\n";
    echo "    exit(\$fail > 0 ? 1 : 0);\n";
    echo "A file that cannot run must say so — print \"SKIP: <reason>\" AND the\n";
    echo "canonical \"0 passed, 0 failed\" line before exiting 0.\n";
}

exit(($totalFail > 0 || $erroredFiles) ? 1 : 0);
