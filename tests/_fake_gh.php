<?php
/**
 * A recording stand-in for the GitHub CLI, used by the Phase 155 community tests.
 *
 * The tools under test run the REAL command runner (tools/lib/community.php:
 * cm_gh() -> cm_run() -> proc_open with an argument list). The tests point
 * COMMUNITY_GH_CMD at this script, so what is exercised is the production code
 * path end to end, with only the network replaced.
 *
 *   FAKE_GH_LOG    file the call is appended to, one JSON object per line:
 *                  {"args":[...],"stdin":"..."}
 *   FAKE_GH_RULES  JSON file: a list of {"match":"<substring of the joined
 *                  args>","out":"<stdout>","err":"<stderr>","code":<exit>}.
 *                  The first rule whose match is a substring of the space-joined
 *                  arguments answers. No rule: exit 1 and say so (a tool that
 *                  makes a call the test did not expect should fail loudly).
 *
 * Deliberately not named test_*.php, so the runner does not try to run it.
 */

$args = array_slice($argv, 1);
$stdin = stream_get_contents(STDIN);
$log = getenv('FAKE_GH_LOG');
if ($log !== false && $log !== '') {
    file_put_contents($log, json_encode(['args' => $args, 'stdin' => $stdin], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}
$rulesFile = getenv('FAKE_GH_RULES');
$rules = ($rulesFile !== false && is_file($rulesFile)) ? json_decode((string) file_get_contents($rulesFile), true) : [];
$joined = implode(' ', $args);
foreach ((array) $rules as $rule) {
    if (strpos($joined, (string) $rule['match']) !== false) {
        fwrite(STDOUT, (string) ($rule['out'] ?? ''));
        fwrite(STDERR, (string) ($rule['err'] ?? ''));
        exit((int) ($rule['code'] ?? 0));
    }
}
fwrite(STDERR, "fake gh: no rule for: {$joined}\n");
exit(1);
