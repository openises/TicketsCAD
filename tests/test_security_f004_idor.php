<?php
/**
 * F-004 / F-005 / F-006 regression — incident-detail, responder-detail, and
 * location-history must call user_can_access_entity() before doing any work.
 */

$base = realpath(__DIR__ . '/..');

echo "=== F-004/5/6 IDOR Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

$cases = [
    'F-004 incident-detail.php' => ['file' => 'api/incident-detail.php',  'entity' => 'incident',  'idVar' => '$id'],
    'F-005 responder-detail.php' => ['file' => 'api/responder-detail.php', 'entity' => 'responder', 'idVar' => '$id'],
    'F-006 location-history.php' => ['file' => 'api/location-history.php', 'entity' => 'responder', 'idVar' => '$responderId'],
];

foreach ($cases as $label => $c) {
    $src = file_get_contents($base . '/' . $c['file']);

    // 1. include the access helper
    if (strpos($src, "/inc/access.php") !== false) {
        ok("$label includes inc/access.php");
    } else {
        bad("$label includes inc/access.php");
    }

    // 2. call user_can_access_entity with the right entity type and id var
    $needle = "user_can_access_entity('" . $c['entity'] . "', " . $c['idVar'] . ")";
    if (strpos($src, $needle) !== false) {
        ok("$label calls $needle");
    } else {
        bad("$label calls $needle");
    }

    // 3. failure path returns 404 (not 403) per constitution rule #27
    if (preg_match('/json_error\([^,)]+,\s*404\)/', $src)) {
        ok("$label returns 404 on access denial (rule #27)");
    } else {
        bad("$label returns 404 on access denial (rule #27)");
    }

    // 4. the access check happens before the main DB query (heuristic: line of
    //    user_can_access_entity is before the line that fetches the resource).
    $checkLine = null; $queryLine = null;
    foreach (explode("\n", $src) as $i => $line) {
        if ($checkLine === null && strpos($line, 'user_can_access_entity') !== false) {
            $checkLine = $i;
        }
        if ($queryLine === null && (strpos($line, 'db_fetch_one') !== false
            || strpos($line, 'db_fetch_all') !== false)) {
            $queryLine = $i;
        }
    }
    if ($checkLine !== null && $queryLine !== null && $checkLine < $queryLine) {
        ok("$label runs access check before any DB fetch");
    } else {
        bad("$label runs access check before any DB fetch",
            "check@$checkLine, query@$queryLine");
    }
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
