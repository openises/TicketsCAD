<?php
/**
 * Master Test Runner — Discovers and runs all test_*.php files in the tools/ directory.
 *
 * Usage: php tools/test_all.php
 *
 * Executes each test file as a subprocess, captures pass/fail counts,
 * and reports combined totals. Returns exit code 1 if any test fails.
 *
 * CI / no-web-server mode (QA automation, Eric 2026-07-07):
 *   NEWUI_TEST_NO_HTTP=1 php tools/test_all.php
 * skips every test file whose header carries a `@requires-http` marker
 * (integration tests that hit http://localhost via a running Apache).
 * Skipped files are listed but count as neither pass nor fail, so the
 * suite can be a meaningful red/green gate on a DB-only environment
 * (GitHub Actions fresh-install job, headless VMs).
 */

$toolsDir = __DIR__;
$phpBin = PHP_BINARY;
$noHttp = getenv('NEWUI_TEST_NO_HTTP') === '1';

// Discover all test files (exclude this runner)
// Search both tools/ and tests/ directories
$testFiles = glob($toolsDir . '/test_*.php');
$testsDir = dirname($toolsDir) . '/tests';
if (is_dir($testsDir)) {
    $testFiles = array_merge($testFiles, glob($testsDir . '/test_*.php'));
}
$testFiles = array_filter($testFiles, function ($f) {
    return basename($f) !== 'test_all.php';
});
sort($testFiles);

echo "=== TicketsCAD NewUI Test Suite ===\n";
echo "PHP: {$phpBin}\n";
echo "Found " . count($testFiles) . " test files\n\n";

$totalPass = 0;
$totalFail = 0;
$skippedFiles = [];
$fileResults = [];

foreach ($testFiles as $file) {
    $basename = basename($file);

    // NEWUI_TEST_NO_HTTP=1 → skip HTTP-integration tests. The marker is
    // a literal `@requires-http` anywhere in the file's first 60 lines
    // (docblock convention).
    if ($noHttp) {
        $head = implode('', array_slice(file($file), 0, 60));
        if (strpos($head, '@requires-http') !== false) {
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

    // Parse pass/fail from the Results line
    $filePassed = 0;
    $fileFailed = 0;
    if (preg_match('/(\d+)\s+passed,\s+(\d+)\s+failed/', $outputText, $m)) {
        $filePassed = (int) $m[1];
        $fileFailed = (int) $m[2];
    }

    $totalPass += $filePassed;
    $totalFail += $fileFailed;

    // Format output line
    $status = ($fileFailed > 0 || $exitCode !== 0) ? 'FAIL' : 'PASS';
    $padded = str_pad($basename, 35);
    echo "Running {$padded} {$filePassed} passed, {$fileFailed} failed";
    if ($exitCode !== 0 && $fileFailed === 0) {
        echo " (exit code {$exitCode})";
        $totalFail++;
    }
    echo "\n";

    $fileResults[] = [
        'file' => $basename,
        'pass' => $filePassed,
        'fail' => $fileFailed,
        'exit' => $exitCode,
    ];
}

echo "\n=== TOTAL: {$totalPass} passed, {$totalFail} failed ===\n";
if (!empty($skippedFiles)) {
    echo "(" . count($skippedFiles) . " file(s) skipped — need a running web server)\n";
}

if ($totalFail > 0) {
    echo "\nFailed test files:\n";
    foreach ($fileResults as $r) {
        if ($r['fail'] > 0 || $r['exit'] !== 0) {
            echo "  - {$r['file']} ({$r['fail']} failures, exit {$r['exit']})\n";
        }
    }
}

exit($totalFail > 0 ? 1 : 0);
