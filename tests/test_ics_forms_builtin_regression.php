<?php
/**
 * Phase 140 (2026-08-16) — byte-for-byte regression proof for the nine
 * existing built-in ICS form types.
 *
 * plan.md: "A byte-for-byte regression test asserts getFormTemplate() and
 * generatePrintHtml() for all nine existing types return output identical
 * to a pre-change snapshot. This is a hard test, not a claim -- this
 * codebase's own history includes 'someone cleans up the switch while
 * they're in the file.'"
 *
 * Method: extract getFormTemplate()/generatePrintHtml()/every printICSxxx()
 * helper from TWO sources -- the git blob at the last commit before this
 * phase touched api/ics-forms.php, and the CURRENT working-tree file --
 * run each in its own subprocess (both define the same function names, so
 * they cannot coexist in one process), and diff their outputs for all nine
 * types. This is real proof, not an assumption about what the diff touched.
 *
 * @requires-db
 * Usage: php tests/test_ics_forms_builtin_regression.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 140 — Built-in ICS form byte-for-byte regression ===\n\n";

$base = realpath(__DIR__ . '/..');
$currentFile = $base . '/api/ics-forms.php';

// Resolve the git blob from before Phase 140 touched this file, if git is
// available. If not (e.g. a plain tarball deploy with no .git), skip
// gracefully -- this is a development-time proof, not an install-time gate.
$gitAvailable = false;
$priorRef = null;
$out = [];
$code = 0;
exec('git -C ' . escapeshellarg($base) . ' rev-parse --is-inside-work-tree 2>&1', $out, $code);
if ($code === 0 && trim(implode('', $out)) === 'true') {
    $gitAvailable = true;
    $out2 = [];
    exec('git -C ' . escapeshellarg($base) . ' log --format=%H -1 -- api/ics-forms.php 2>&1', $out2, $code2);
    // The CURRENT HEAD commit for this file might already be Phase 140's own
    // commit if this test runs after that commit lands. Walk back to the
    // first ancestor whose diff for this file does NOT contain the Phase 140
    // marker, so this test keeps proving the invariant on every future run,
    // not just the one immediately after this diff.
    $out3 = [];
    exec('git -C ' . escapeshellarg($base) . ' log --format=%H -- api/ics-forms.php 2>&1', $out3, $code3);
    $priorRef = null;
    foreach ($out3 as $sha) {
        $sha = trim($sha);
        if ($sha === '') continue;
        $show = [];
        exec('git -C ' . escapeshellarg($base) . ' show ' . escapeshellarg($sha . ':api/ics-forms.php') . ' 2>&1', $show, $sc);
        $content = implode("\n", $show);
        if (strpos($content, 'Phase 140') === false) {
            $priorRef = $sha;
            break;
        }
    }
}

if (!$gitAvailable || !$priorRef) {
    echo "SKIP: could not resolve a pre-Phase-140 git blob for api/ics-forms.php "
        . "(no git, or every history entry already mentions Phase 140) -- "
        . "nothing to diff against.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$priorContent = [];
exec('git -C ' . escapeshellarg($base) . ' show ' . escapeshellarg($priorRef . ':api/ics-forms.php') . ' 2>&1', $priorContent, $pc);
$priorSrc = implode("\n", $priorContent);
if ($pc !== 0 || trim($priorSrc) === '') {
    t('resolved a non-empty pre-Phase-140 source blob', false);
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$priorTmp = tempnam(sys_get_temp_dir(), 'icsforms_prior_');
file_put_contents($priorTmp, $priorSrc);

$runnerTmp = tempnam(sys_get_temp_dir(), 'icsforms_runner_');
file_put_contents($runnerTmp, <<<'PHP'
<?php
// Extracts and calls the pure rendering functions from a given
// api/ics-forms.php source file, WITHOUT executing its top-level
// GET/POST dispatch (which needs auth/session/db). Outputs JSON.
$srcFile = $argv[1];
$src = file_get_contents($srcFile);

function extract_fn(string $src, string $name): string {
    if (!preg_match('/\nfunction\s+' . preg_quote($name, '/') . '\s*\([^)]*\)(?::\s*\??[A-Za-z|]+)?\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException("could not find function $name");
    }
    $start = $m[0][1] + 1; // skip leading \n
    $bodyStart = strpos($src, '{', $start);
    $depth = 0;
    $i = $bodyStart;
    $len = strlen($src);
    for (; $i < $len; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) { $i++; break; }
        }
    }
    return substr($src, $start, $i - $start);
}

$fns = ['getFormTemplate', 'generatePrintHtml', 'pv', 'xs',
        'printICS213', 'printICS214', 'printICS202', 'printICS205',
        'printICS205A', 'printICS213RR', 'printICS206', 'printICS214A', 'printICS221'];
foreach ($fns as $fn) {
    eval(extract_fn($src, $fn));
}

$types = ['213', '214', '202', '205', '205a', '213rr', '206', '214a', '221'];
$out = ['templates' => [], 'print_html' => []];

foreach ($types as $type) {
    $out['templates'][$type] = getFormTemplate($type);
}

// One representative sample dataset per type, exercising both simple
// fields and (where present) table rows, so the print renderers'
// row-iteration branches run identically on both sides.
$sampleData = [
    '213'   => ['to_name' => 'Chief A', 'to_position' => 'IC', 'from_name' => 'Op B',
                'subject' => 'Test', 'date' => '2026-08-16', 'time' => '10:00',
                'message' => "line one\nline two", 'reply' => 'ack'],
    '214'   => ['incident_name' => 'Drill', 'date_prepared' => '2026-08-16',
                'activity_log' => [['time' => '10:00', 'activity' => 'started']]],
    '202'   => ['incident_name' => 'Drill', 'objectives' => 'Test objectives',
                'current_actions' => 'Test actions'],
    '205'   => ['incident_name' => 'Drill', 'radio_channels' => [
                ['zone_group' => 'Z1', 'channel' => 'Ch1', 'function' => 'Cmd',
                 'frequency_rx' => '155.0', 'frequency_tx' => '155.0', 'tone' => '100.0', 'assignment' => 'IC']]],
    '205a'  => ['incident_name' => 'Drill', 'contacts' => [
                ['position' => 'IC', 'name' => 'Chief A', 'phone' => '555-1234', 'radio' => 'Ch1', 'email' => 'a@example.invalid']]],
    '213rr' => ['incident_name' => 'Drill', 'resource_requests' => [
                ['qty' => '2', 'kind' => 'Engine', 'type' => 'Type 1', 'description' => 'Structure engine',
                 'arrival' => 'ASAP', 'priority' => 'High', 'cost' => 'TBD']]],
    '206'   => ['incident_name' => 'Drill', 'aid_stations' => [['name' => 'Station 1', 'location' => 'Main St', 'paramedics' => 'Y']],
                'transportation' => [['service' => 'EMS Co', 'address' => '123 Main', 'phone' => '555-0000', 'paramedics' => 'Y']],
                'hospitals' => [['name' => 'General', 'address' => '456 Elm', 'phone' => '555-1111', 'travel_time' => '10min', 'trauma_level' => 'II', 'helipad' => 'Y']]],
    '214a'  => ['incident_name' => 'Drill', 'individual_name' => 'Op B',
                'activity_log' => [['time' => '10:00', 'activity' => 'started']]],
    '221'   => ['incident_name' => 'Drill', 'resource_unit_id' => 'E1', 'leader_name' => 'Chief A',
                'chk_logistics' => 'OK'],
];

$fakeRow = ['id' => 1, 'title' => 'Regression Test'];
foreach ($types as $type) {
    $out['print_html'][$type] = generatePrintHtml($type, $sampleData[$type], $fakeRow);
}

echo json_encode($out);
PHP
);

function _p140_run_snapshot(string $runnerTmp, string $srcTmp): array {
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($runnerTmp) . ' ' . escapeshellarg($srcTmp) . ' 2>&1';
    $json = shell_exec($cmd);
    $decoded = json_decode((string) $json, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('snapshot subprocess did not return valid JSON: ' . substr((string) $json, 0, 500));
    }
    return $decoded;
}

$priorSnapshot = null;
$currentSnapshot = null;
try {
    $priorSnapshot = _p140_run_snapshot($runnerTmp, $priorTmp);
} catch (Throwable $e) {
    t('pre-Phase-140 snapshot subprocess ran cleanly: ' . $e->getMessage(), false);
}
try {
    $currentSnapshot = _p140_run_snapshot($runnerTmp, $currentFile);
} catch (Throwable $e) {
    t('current-file snapshot subprocess ran cleanly: ' . $e->getMessage(), false);
}

@unlink($priorTmp);
@unlink($runnerTmp);

if ($priorSnapshot === null || $currentSnapshot === null) {
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

$types = ['213', '214', '202', '205', '205a', '213rr', '206', '214a', '221'];
foreach ($types as $type) {
    $priorTpl = $priorSnapshot['templates'][$type] ?? null;
    $currTpl = $currentSnapshot['templates'][$type] ?? null;
    t("getFormTemplate('$type') is byte-for-byte identical to the pre-Phase-140 version",
        json_encode($priorTpl) === json_encode($currTpl));

    $priorHtml = $priorSnapshot['print_html'][$type] ?? null;
    $currHtml = $currentSnapshot['print_html'][$type] ?? null;
    t("generatePrintHtml() for type '$type' is byte-for-byte identical to the pre-Phase-140 version",
        $priorHtml === $currHtml);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
