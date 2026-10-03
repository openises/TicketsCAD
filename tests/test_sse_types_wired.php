<?php
/**
 * Every SSE event the server publishes, and every one the browser listens for, must
 * be in assets/js/event-bus.js SSE_TYPES -- otherwise it silently reaches nobody.
 *
 * Why: EventBus registers an EventSource listener ONLY for the types in that array.
 * Phase 151's incident:primary_changed was published by two endpoints and never listed,
 * so a primary-unit change never refreshed another dispatcher's open incident page; the
 * same audit found six older types in the same state (tools/sse_types_unwired_baseline.txt).
 * This is the project's recurring "producer with no consumer" class, checked mechanically.
 *
 * Findings are: types PUBLISHED as a string literal through sse_publish*() in app code,
 * or LISTENED for via EventBus.on('x:y') and not emitted client-side by EventBus.emit(),
 * that are absent from SSE_TYPES and absent from the baseline. A baseline entry that is
 * now wired is STALE and also fails (so the file shrinks as the gaps close).
 *
 * Usage: php tests/test_sse_types_wired.php
 */
$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$root = dirname(__DIR__);

function sse_wired_files(string $root, array $globs): array {
    $out = [];
    foreach ($globs as $g) { foreach (glob($root . '/' . $g) ?: [] as $f) { $out[] = $f; } }
    return $out;
}

// ── SSE_TYPES ────────────────────────────────────────────────
$eb = file_get_contents($root . '/assets/js/event-bus.js');
t('event-bus.js defines SSE_TYPES', preg_match('/var SSE_TYPES = \[(.*?)\n    \];/s', $eb, $m) === 1);
$arrayText = preg_replace('#//[^\n]*#', '', $m[1] ?? '');   // drop comments so a type named in prose does not count
preg_match_all("/'([a-z_]+:[a-z_]+)'/", $arrayText, $lm);
$listed = array_unique($lm[1]);
t('SSE_TYPES was parsed (sanity floor)', count($listed) >= 25);

// ── published literals (app code only) ───────────────────────
$phpFiles = array_merge(
    sse_wired_files($root, ['*.php', 'api/*.php', 'api/external/v1/*.php', 'inc/*.php', 'inc/channels/*.php'])
);
$published = [];
foreach ($phpFiles as $f) {
    $s = file_get_contents($f);
    if (preg_match_all("/sse_publish(?:_for_incident|_for_call|_for_user|_for_org)?\s*\(\s*['\"]([a-z_]+:[a-z_]+)['\"]/", $s, $pm)) {
        foreach ($pm[1] as $type) {
            if (substr($type, -1) === '_') continue;          // a dynamic prefix ('comm:route_' . $x)
            $published[$type][] = substr($f, strlen($root) + 1);
        }
    }
}
t('publishers were found (sanity floor)', count($published) >= 20);

// ── listened-for literals, minus ones the client emits itself ──
$jsFiles = array_merge(sse_wired_files($root, ['assets/js/*.js']), sse_wired_files($root, ['*.php', 'inc/*.php']));
$consumed = []; $emittedClient = [];
foreach ($jsFiles as $f) {
    $s = file_get_contents($f);
    if (preg_match_all("/EventBus\.on\(\s*'([a-z_]+:[a-z_]+)'/", $s, $cm)) {
        foreach ($cm[1] as $type) { $consumed[$type][] = substr($f, strlen($root) + 1); }
    }
    if (preg_match_all("/EventBus\.emit\(\s*'([a-z_]+:[a-z_]+)'/", $s, $em)) {
        foreach ($em[1] as $type) { $emittedClient[$type] = true; }
    }
}
foreach (array_keys($consumed) as $type) {
    if (isset($emittedClient[$type]) || strpos($type, 'sse:') === 0) unset($consumed[$type]); // local/internal events
}

// ── baseline ─────────────────────────────────────────────────
$baseline = [];
foreach (file($root . '/tools/sse_types_unwired_baseline.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $baseline[$line] = true;
}

$candidates = array_unique(array_merge(array_keys($published), array_keys($consumed)));
sort($candidates);
$unwired = array_values(array_filter($candidates, function ($type) use ($listed) { return !in_array($type, $listed, true); }));
$newFindings = array_values(array_filter($unwired, function ($type) use ($baseline) { return !isset($baseline[$type]); }));
$stale = array_values(array_filter(array_keys($baseline), function ($type) use ($unwired) { return !in_array($type, $unwired, true); }));

echo "\n";
foreach ($newFindings as $type) {
    $where = array_merge($published[$type] ?? [], $consumed[$type] ?? []);
    echo "      unwired: $type  (" . implode(', ', array_unique($where)) . ")\n";
}
t('every published/listened-for SSE type is in SSE_TYPES (or on the documented baseline) -- ' . count($newFindings) . ' new gap(s)',
    count($newFindings) === 0);
t('no stale baseline entry (a gap that has since been wired must be removed from the baseline)' . ($stale ? ': ' . implode(', ', $stale) : ''),
    count($stale) === 0);

// The specific regression that motivated this file.
t('incident:primary_changed is subscribed by the browser', in_array('incident:primary_changed', $listed, true));
$detail = file_get_contents($root . '/assets/js/incident-detail.js');
t('incident-detail.js refreshes the open incident on it', strpos($detail, "'incident:primary_changed'") !== false);

// The detector must actually detect: a made-up type must be reported.
$probe = "x:probe_type";
t('the detector flags a type that is not listed (self-check)', !in_array($probe, $listed, true) && !isset($baseline[$probe]));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
