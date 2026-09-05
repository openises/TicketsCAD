<?php
/**
 * Converts semgrep's JSON report into SonarQube's Generic Issue Import
 * Format (sonar.externalIssuesReportPaths), so every SQL-injection taint
 * finding shows up natively in the ticketscad-newui SonarQube dashboard
 * alongside the built-in PHP analyzer's own issues -- this project's
 * SonarQube Community Build has no PHP rule-template mechanism and no
 * built-in taint-analysis rule (php:S3649 is Enterprise/Developer-tier
 * only), so Semgrep's taint-mode engine (tools/sqli_taint_audit.php) is
 * the actual detector; this script is just the wire format SonarQube
 * expects to display and track its output. Companion to the identical
 * script in the legacy `tickets` repo.
 *
 * Schema per SonarQube's own docs (docs.sonarsource.com, "Generic
 * formatted reports" -- verified live 2026-09, since two earlier
 * attempts at this file guessed wrong and both failed at scan time:
 * "Deprecated 'severity' field", then "Deprecated 'type' field"). An
 * ISSUE object may carry ONLY `ruleId`, `effortMinutes`,
 * `primaryLocation` (message/filePath/textRange), and
 * `secondaryLocations` -- no `type`, `severity`, `impacts`, or
 * `engineId` at the issue level. All of that lives on the RULE object
 * instead (`type`/`severity` are valid there, but optional once
 * `impacts` is provided, which this file always does).
 *
 * This is a dashboard/visibility integration, NOT the CI gate -- the gate
 * that actually blocks a bad merge is tests/test_sqli_taint_audit.php
 * (via tools/sqli_taint_audit.php's own baseline). This script imports
 * every current finding, including ones already in that baseline, so a
 * human reviewing the SonarQube UI sees the full picture.
 *
 * Usage: php tools/sqli_taint_to_sonar.php <semgrep-report.json> <output.json>
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

if ($argc < 3) {
    fwrite(STDERR, "Usage: php sqli_taint_to_sonar.php <semgrep-report.json> <output.json>\n");
    exit(2);
}

$semgrepReport = json_decode(file_get_contents($argv[1]), true);
if ($semgrepReport === null) {
    fwrite(STDERR, "sqli_taint_to_sonar: could not parse " . $argv[1] . "\n");
    exit(2);
}

$seenRuleIds = [];
$rules = [];
$issues = [];
foreach ($semgrepReport['results'] ?? [] as $r) {
    $path = preg_replace('#^/src/#', '', $r['path']);
    $ruleId = $r['check_id'] ?? 'unknown';
    $bareRuleId = substr(strrchr('.' . $ruleId, '.'), 1);

    if (!isset($seenRuleIds[$bareRuleId])) {
        $seenRuleIds[$bareRuleId] = true;
        $rules[] = [
            'id' => $bareRuleId,
            'name' => 'Tainted SQL query string (Semgrep taint analysis)',
            'description' => $r['extra']['message'] ?? 'SQL injection risk (Semgrep taint analysis)',
            'engineId' => 'semgrep-newui',
            'cleanCodeAttribute' => 'TRUSTWORTHY',
            'impacts' => [['softwareQuality' => 'SECURITY', 'severity' => 'HIGH']],
        ];
    }

    $issues[] = [
        'ruleId' => $bareRuleId,
        'effortMinutes' => 30,
        'primaryLocation' => [
            'message' => $r['extra']['message'] ?? 'SQL injection risk (Semgrep taint analysis)',
            'filePath' => $path,
            'textRange' => [
                'startLine' => max(1, (int) ($r['start']['line'] ?? 1)),
                'endLine' => max(1, (int) ($r['end']['line'] ?? $r['start']['line'] ?? 1)),
            ],
        ],
    ];
}

file_put_contents($argv[2], json_encode(['rules' => $rules, 'issues' => $issues], JSON_PRETTY_PRINT));
echo "Wrote " . count($issues) . " issues (" . count($rules) . " distinct rules) to " . $argv[2] . "\n";
