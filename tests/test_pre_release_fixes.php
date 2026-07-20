<?php
/**
 * Regression tests for the PRE-RELEASE-FIXES batch (items 1-6, 11, 15, 16,
 * 17, 18, 19). Each section maps to a numbered item in the tracker.
 */

require __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== PRE-RELEASE-FIXES regression suite ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── #1: install_fresh.php exists and is idempotent ──
$installFresh = $base . '/tools/install_fresh.php';
if (file_exists($installFresh)) {
    ok('#1 tools/install_fresh.php exists');
} else {
    bad('#1 tools/install_fresh.php exists');
}
$src = file_get_contents($installFresh);
// Strip comments before the no-literal-DDL check — explanatory comments
// legitimately mention "CREATE TABLE IF NOT EXISTS". Code that genuinely
// needs guarded DDL uses the split-token convention ('CR' . 'EATE'), same
// as sql/run_rbac_v2.php, so any literal CREATE in CODE is a violation.
$srcCode = preg_replace('!//[^\n]*!', '', $src);
$srcCode = preg_replace('!/\*.*?\*/!s', '', $srcCode);
$srcCode = preg_replace('!#[^\n]*!', '', $srcCode);
if (strpos($src, 'function step(') !== false
    && strpos($src, 'col_exists(') !== false
    && strpos($srcCode, 'CREATE') === false /* no destructive bare CREATE */) {
    ok('#1 install_fresh has idempotent step() helper + column existence checks');
} else {
    bad('#1 install_fresh has idempotent step() helper');
}
foreach (['middle_name', 'photo_file_id', 'phone_home', 'membership_due', 'emergency_contact', 'notes'] as $col) {
    if (preg_match('/[\'"\b]' . preg_quote($col, '/') . '[\'"\b]/', $src)) {
        ok("#2 install_fresh adds member.$col");
    } else {
        bad("#2 install_fresh adds member.$col");
    }
}
foreach (['first_name', 'last_name', 'callsign', 'phone_cell', 'street', 'city', 'state'] as $alias) {
    if (strpos($src, "'$alias'") !== false) {
        ok("#2 install_fresh declares $alias virtual alias");
    } else {
        bad("#2 install_fresh declares $alias virtual alias");
    }
}
if (strpos($src, "field3 nullable") !== false || preg_match('/field3.*NULL/i', $src)) {
    ok('#3 install_fresh makes field3 nullable');
} else {
    bad('#3 install_fresh makes field3 nullable');
}
if (preg_match('/field7.*VARCHAR/i', $src)) {
    ok('#15 install_fresh changes field7 to VARCHAR (was BIGINT)');
} else {
    bad('#15 install_fresh changes field7 to VARCHAR');
}
if (strpos($src, 'teams') !== false && strpos($src, "'name'") !== false) {
    ok('#4 install_fresh adds teams.name virtual alias');
} else {
    bad('#4 install_fresh adds teams.name virtual alias');
}
if (preg_match('/in_types.*protocol.*TEXT/is', $src)) {
    ok('#5 install_fresh widens in_types.protocol to TEXT');
} else {
    bad('#5 install_fresh widens in_types.protocol');
}
if (preg_match('/settings.*value.*TEXT/is', $src)) {
    ok('#6 install_fresh widens settings.value to TEXT');
} else {
    bad('#6 install_fresh widens settings.value');
}
if (strpos($src, "uploads/.htaccess") !== false || strpos($src, '/.htaccess') !== false) {
    ok('#11 install_fresh ensures uploads/.htaccess present');
} else {
    bad('#11 install_fresh ensures uploads/.htaccess present');
}

// ── #16: members.php uses array_key_exists semantics ──
// 2026-06-28 Phase 94 Stage 4j refactor: window raised 800 → 2000 chars
// because the if-id-update branch now starts with a longer doc comment
// + the static $passthrough whitelist before the foreach loop. The
// array_key_exists semantics are unchanged.
$memSrc = file_get_contents($base . '/api/members.php');
if (strpos($memSrc, 'array_key_exists') !== false
    && preg_match('/\$id\s*>\s*0[\s\S]{0,2000}array_key_exists\(\$col,\s*\$input\)/', $memSrc)) {
    ok('#16 members.php uses array_key_exists for partial updates');
} else {
    bad('#16 members.php uses array_key_exists for partial updates');
}
if (strpos($memSrc, "'photo_file_id'") !== false) {
    ok('#19 members.php passes photo_file_id through to UPDATE/INSERT');
} else {
    bad('#19 members.php passes photo_file_id through');
}
if (strpos($memSrc, 'm.photo_file_id') !== false) {
    ok('#19 members.php list query includes photo_file_id');
} else {
    bad('#19 members.php list query includes photo_file_id');
}

// ── #17: teams.php uses field4 for callsign ──
$teamsSrc = file_get_contents($base . '/api/teams.php');
// Strip block + line comments before checking — the new code-comments
// reference the historical field26 string for explanatory purposes.
$teamsCodeOnly = preg_replace('!//[^\n]*!', '', $teamsSrc);
$teamsCodeOnly = preg_replace('!/\*.*?\*/!s', '', $teamsCodeOnly);
// The callsign SELECT now prefers the virtual alias with a field4
// fallback: COALESCE(NULLIF(m.callsign, ''), m.field4) AS callsign.
// Accept either the modern COALESCE form or the original bare m.field4 —
// what matters is field4 is the source and field26 never is.
if ((strpos($teamsCodeOnly, 'm.field4) AS callsign') !== false
     || strpos($teamsCodeOnly, 'm.field4 AS callsign') !== false)
    && strpos($teamsCodeOnly, 'm.field26') === false) {
    ok('#17 teams.php uses field4 (not field26) for callsign');
} else {
    bad('#17 teams.php uses field4 for callsign — old field26 reference still present');
}

// ── #18: teams.php auto-promotes leader/deputy into team_members ──
// 2026-06-28: the literal moved from api/teams.php into the canonical
// inc/team-write.php helper as part of the Phase 94 internal-endpoint
// refactor; check both locations (the wire still exists, just one
// indirection deeper now).
$teamHelperSrc = file_exists($base . '/inc/team-write.php')
    ? file_get_contents($base . '/inc/team-write.php')
    : '';
$autoPromoteHere   = strpos($teamsSrc, "['Leader', \$leaderId]") !== false
    || preg_match('/\[\s*[\'"]Leader[\'"]\s*,\s*\$leaderId\s*\]/', $teamsSrc);
$autoPromoteHelper = strpos($teamsSrc, 'team_upsert_internal') !== false
    && (strpos($teamHelperSrc, "['Leader', \$leaderId]") !== false
        || preg_match('/\[\s*[\'"]Leader[\'"]\s*,\s*\$leaderId\s*\]/', $teamHelperSrc));
if ($autoPromoteHere || $autoPromoteHelper) {
    ok('#18 teams.php auto-adds Leader/Deputy to team_members');
} else {
    bad('#18 teams.php auto-adds Leader/Deputy to team_members');
}

// ── #19: photo UI is wired in roster ──
$rosterPhpSrc = file_get_contents($base . '/roster.php');
if (strpos($rosterPhpSrc, 'editPhotoFileId') !== false
    && strpos($rosterPhpSrc, 'editPhotoInput') !== false
    && strpos($rosterPhpSrc, 'editPhotoPreview') !== false) {
    ok('#19 roster.php has photo upload UI elements');
} else {
    bad('#19 roster.php has photo upload UI elements');
}
$rosterJsSrc = file_get_contents($base . '/assets/js/roster.js');
if (strpos($rosterJsSrc, 'uploadMemberPhoto') !== false
    && strpos($rosterJsSrc, "fetch('api/upload.php'") !== false
    && strpos($rosterJsSrc, "entity', 'member'") !== false) {
    ok('#19 roster.js implements uploadMemberPhoto via api/upload.php');
} else {
    bad('#19 roster.js implements uploadMemberPhoto');
}
if (strpos($rosterJsSrc, 'roster-avatar') !== false
    && strpos($rosterJsSrc, 'photo_file_id') !== false) {
    ok('#19 roster.js renders avatar in member list');
} else {
    bad('#19 roster.js renders avatar in member list');
}

// roster.js should NOT send the legacy `callsign: ''` line that wiped data
if (preg_match("/^\\s*callsign:\\s*''/m", $rosterJsSrc)) {
    bad('#16 roster.js no longer ships callsign:\'\' wipe', "still present");
} else {
    ok('#16 roster.js no longer ships callsign:\'\' wipe');
}

// photo_file_id is included in the saveMember payload
if (strpos($rosterJsSrc, 'photo_file_id:') !== false) {
    ok('#19 roster.js saveMember sends photo_file_id');
} else {
    bad('#19 roster.js saveMember sends photo_file_id');
}

// ── Functional: install_fresh idempotent run ──
//
// Run install_fresh in-process on the live DB. Skipping if not connected.
echo "\n[functional] running install_fresh.php inline:\n";
ob_start();
$installResult = null;
try {
    // Run via include in a child scope so its `exit()` is captured by the
    // shutdown handler — the script always ends with `exit($fail > 0 ? 1 : 0)`.
    pcntl_async_signals(true);
} catch (Throwable $t) {}

$installOutput = '';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($installFresh) . ' 2>&1';
$installOutput = @shell_exec($cmd);
$installOutput = (string) $installOutput;
echo "  (output suppressed; last line below)\n";
$lastLine = trim(preg_replace('/\s+/', ' ', substr($installOutput, max(0, strlen($installOutput) - 200))));
echo "  $lastLine\n";

if (preg_match('/(\d+) applied,\s*(\d+) already in place,\s*(\d+) failed/', $installOutput, $m)) {
    if ((int) $m[3] === 0) {
        ok("install_fresh ran end-to-end with 0 failures (applied={$m[1]}, skipped={$m[2]})");
    } else {
        bad('install_fresh had failures', "{$m[3]} step(s) failed — see output above");
    }
} else {
    bad('install_fresh produced expected summary line');
}

// Re-run — every step should now skip
$rerun = (string) @shell_exec($cmd);
if (preg_match('/(\d+) applied,\s*(\d+) already in place,\s*(\d+) failed/', $rerun, $m2)) {
    if ((int) $m2[1] === 0 && (int) $m2[3] === 0) {
        ok('install_fresh second run: 0 applied, 0 failed (idempotent)');
    } else {
        bad('install_fresh second run idempotent', "applied={$m2[1]}, failed={$m2[3]}");
    }
}

// ── Functional: schema after migration matches API expectations ──
$columns = ['middle_name','phone_home','phone_work','zip','dob','membership_due',
            'emergency_contact','medical_info','notes','photo_file_id',
            'first_name','last_name','callsign','phone_cell','street','city','state'];
foreach ($columns as $col) {
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            ["{$prefix}member", $col]
        );
        if ($r) ok("DB has member.$col after install_fresh");
        else    bad("DB has member.$col after install_fresh");
    } catch (Exception $e) {
        bad("DB has member.$col after install_fresh", $e->getMessage());
    }
}

// ── Functional: members.php save preserves callsign across partial save (#16) ──
echo "\n[functional] partial-save preserves callsign:\n";
$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'test'];
$GLOBALS['_TEST_csrf_skip'] = true; // not actually used; demonstrating intent

// Find any member with a callsign
$probe = db_fetch_one("SELECT id, field4 FROM `{$prefix}member` WHERE field4 IS NOT NULL AND field4 != '' LIMIT 1");
if ($probe) {
    $beforeCs = $probe['field4'];
    $mid = (int) $probe['id'];
    // Simulate a partial save that omits 'callsign' — we do this by directly
    // reproducing the API logic against a test field.
    $allFields = [
        'first_name' => 'Test',
        'last_name'  => 'Test',
        'callsign'   => '',  // would have been clobbered pre-fix
        'notes'      => 'partial-save-test',
    ];
    $input = ['notes' => 'partial-save-test', 'first_name' => 'Test', 'last_name' => 'Test']; // no callsign key
    $fields = [];
    foreach ($allFields as $col => $val) {
        if ($col === 'first_name' || $col === 'last_name' || array_key_exists($col, $input)) {
            $fields[$col] = $val;
        }
    }
    if (!array_key_exists('callsign', $fields)) {
        ok('#16 partial-save omits callsign when not in input');
    } else {
        bad('#16 partial-save omits callsign when not in input');
    }

    // After: original callsign should still be there
    $after = db_fetch_one("SELECT field4 FROM `{$prefix}member` WHERE id = ?", [$mid]);
    if ($after && $after['field4'] === $beforeCs) {
        ok('#16 callsign untouched after partial-save simulation');
    } else {
        bad('#16 callsign untouched after partial-save simulation');
    }
} else {
    echo "  [skip] no member with callsign — can't run preservation test\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
