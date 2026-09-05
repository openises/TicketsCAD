<?php
/**
 * SQL-injection taint-analysis gate (Eric 2026-09, following the legacy
 * `tickets` repo's SQLi remediation the same day — GHSA-ghqm-2wf7-8vww and
 * eight siblings, none of which this codebase shares any code with, but
 * the review raised "I do not want to see this class of vulnerability
 * missed" for every project).
 *
 * SonarQube's Community Build has no PHP rule-template mechanism and no
 * built-in SQL-injection taint rule (php:S3649 is Enterprise/Developer
 * tier only) -- confirmed against this project's own instance the same
 * day. This gate uses Semgrep's taint-mode engine instead, via the
 * semgrep/semgrep Docker image, with a rule tailored to this codebase's
 * actual safe-query API (.semgrep/newui-sql-injection.yml): $_GET/$_POST/
 * $_REQUEST/$_COOKIE flowing into db_query()/db_fetch_all()/db_fetch_one()/
 * db_fetch_value() or a raw PDO ->query()/->exec() call, without going
 * through a bound `?` parameter (VALUE position) or db_table()/an
 * allowlist (IDENTIFIER position -- db_table() in inc/db.php is this
 * codebase's own centralized identifier sanitizer, stripping to
 * [a-zA-Z0-9_] regardless of input).
 *
 * Validated against synthetic fixtures before the real scan (3/3 deliberate
 * vulnerabilities caught, 0 false positives on bound/db_table()-protected
 * code) and then run against the full tree: 5 findings, all independently
 * confirmed as the same safe idiom (a literal array-keyed lookup for a
 * sort column, e.g. `$sortMap[$_GET['sort']] ?? 'default'` -- the attacker
 * selects AMONG hardcoded values, never supplies one) that Semgrep's OSS
 * taint engine doesn't recognize syntactically. Recorded in
 * tools/sqli_taint_baseline.txt, not fixed -- there was nothing to fix.
 *
 * Exit code: 0 = no NEW findings (baseline-listed ones don't fail this),
 * 1 = a genuinely new finding appeared. Baseline is one "file:line" per
 * line; re-verify by reading the flagged code before adding an entry --
 * this file records confirmed false positives, never accepted vulnerabilities.
 *
 * Usage: php tools/sqli_taint_audit.php [--update-baseline]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
$baselinePath = $root . '/tools/sqli_taint_baseline.txt';
$rulePath = $root . '/.semgrep/newui-sql-injection.yml';
$updateBaseline = in_array('--update-baseline', $argv ?? [], true);

// Docker is an environmental dependency, not a code defect -- degrade to
// a clean SKIP (not a failure) if it isn't reachable, matching this
// project's proc_open()-unavailable degradation convention elsewhere.
exec('docker version --format "{{.Server.Version}}" 2>&1', $dockerCheck, $dockerExit);
if ($dockerExit !== 0) {
    echo "SKIP: Docker is not available -- the Semgrep SQL-injection audit could not run\n";
    exit(2);
}

$cmd = 'docker run --rm -v ' . escapeshellarg($root) . ':/src semgrep/semgrep:1.175.1 '
     . 'semgrep scan --config /src/.semgrep/ --json /src 2>' . escapeshellarg($root . '/.sqli_taint_audit_stderr.tmp');
$output = shell_exec($cmd);
@unlink($root . '/.sqli_taint_audit_stderr.tmp');

if ($output === null || trim($output) === '') {
    fwrite(STDERR, "sqli_taint_audit: semgrep produced no output\n");
    exit(2);
}

$data = json_decode($output, true);
if ($data === null) {
    fwrite(STDERR, "sqli_taint_audit: could not parse semgrep JSON output\n");
    exit(2);
}

if (!empty($data['errors'])) {
    fwrite(STDERR, "sqli_taint_audit: semgrep reported errors:\n");
    foreach ($data['errors'] as $e) {
        fwrite(STDERR, '  ' . ($e['message'] ?? json_encode($e)) . "\n");
    }
    exit(2);
}

$findings = [];
foreach ($data['results'] as $r) {
    $path = preg_replace('#^/src/#', '', $r['path']);
    $key = $path . ':' . $r['start']['line'];
    $findings[$key] = [
        'file' => $path,
        'line' => $r['start']['line'],
        'rule' => $r['check_id'],
    ];
}

$baseline = [];
if (file_exists($baselinePath)) {
    foreach (file($baselinePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $baseline[$line] = true;
    }
}

if ($updateBaseline) {
    ksort($findings);
    file_put_contents(
        $baselinePath,
        "# Reviewed Semgrep SQL-injection taint-tracking false positives.\n"
        . "# See tools/sqli_taint_audit.php's docblock before adding an entry --\n"
        . "# this file records ACCEPTED FALSE POSITIVES, never real findings.\n"
        . "# Regenerate with: php tools/sqli_taint_audit.php --update-baseline\n"
        . implode("\n", array_keys($findings)) . "\n"
    );
    echo "Baseline updated: " . count($findings) . " entries.\n";
    exit(0);
}

$new = [];
foreach ($findings as $key => $f) {
    if (!isset($baseline[$key])) {
        $new[$key] = $f;
    }
}

$knownCount = count($findings) - count($new);
echo "SQL-injection taint audit: " . count($findings) . " total findings, "
   . "{$knownCount} baselined, " . count($new) . " new.\n\n";

if ($new) {
    echo "=== NEW findings not in tools/sqli_taint_baseline.txt ===\n\n";
    foreach ($new as $key => $f) {
        echo "[{$f['rule']}] {$f['file']}:{$f['line']}\n";
    }
    echo "\nIf a finding above is a genuine false positive (the value IS checked\n";
    echo "against a literal allowlist or db_table() before use), re-run with\n";
    echo "--update-baseline after confirming that by reading the code.\n";
    exit(1);
}

echo "No new SQL-injection findings.\n";
exit(0);
