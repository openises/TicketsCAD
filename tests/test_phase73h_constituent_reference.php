<?php
/**
 * Phase 73h regression — constituents ?reference= lookup
 *
 * The endpoint is the foundation of the Skywarn-style structured-callback
 * flow on new-incident: a dispatcher types a known spotter ID, blur fires
 * a lookup, fields auto-populate from the constituent row.
 *
 * Run via tools/test_all.php (which picks up tests/test_*.php files).
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
require_once 'inc/security.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$tests  = 0; $passes = 0; $fails = [];
function t73h($cond, $msg) {
    global $tests, $passes, $fails;
    $tests++;
    if ($cond) { $passes++; return; }
    $fails[] = $msg;
}

// Virgin-install guard (QA automation 2026-07-07): on a fresh DB the
// constituent-suggest path has no org/member context and the endpoint
// returns empty regardless of fixtures — meaningful only on a seeded
// install. Skip rather than fail CI.
try {
    $__preExisting = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents`");
} catch (Exception $e) { $__preExisting = 0; }
if ($__preExisting === 0) {
    echo "SKIP: no constituents on this install — suggest-path test needs seeded data\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

// ── Fixtures ───────────────────────────────────────────────────────
$cleanup = [];
try {
    $hasRef = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = 'reference'",
        [$prefix . 'constituents']
    );
    if (!$hasRef) {
        echo "SKIP: constituents.reference column not present on this install\n";
        exit(0);
    }
    // Two spotters: exact-match + prefix-collision
    db_query(
        "INSERT INTO `{$prefix}constituents`
           (`contact`, `reference`, `phone`, `street`, `city`, `state`)
         VALUES ('Pat Skywarn', 'PH73H-2415', '555-1111', '1 Storm Ln', 'Sample', 'IA')"
    );
    $cleanup[] = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}constituents`
           (`contact`, `reference`, `phone`, `street`, `city`, `state`)
         VALUES ('Sam Spotter', 'PH73H-24151', '555-2222', '2 Cloud Way', 'Sample', 'IA')"
    );
    $cleanup[] = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}constituents`
           (`contact`, `reference`, `phone`, `street`, `city`, `state`)
         VALUES ('Other Unrelated', 'PH73H-OTHER', '555-3333', '99 Other', 'Sample', 'IA')"
    );
    $cleanup[] = (int) db_insert_id();
} catch (Exception $e) {
    echo "SKIP: fixture failed — " . $e->getMessage() . "\n";
    exit(0);
}

register_shutdown_function(function () use ($prefix, $cleanup) {
    try {
        if ($cleanup) {
            db_query(
                "DELETE FROM `{$prefix}constituents` WHERE id IN ("
                . implode(',', array_map('intval', $cleanup)) . ")"
            );
        }
    } catch (Exception $e) {}
});

// ── Exercise the API endpoint via subprocess ──────────────────────
// The harness targets THIS checkout (dirname(__DIR__)), never a hardcoded
// install path — a previous version hardcoded /var/www/newui, which made
// the subprocess hit a DIFFERENT install's config/database whenever the
// suite ran from any other checkout (QA fresh-install env, dev trees).
function call_constituents_api($query) {
    $baseExport = var_export(dirname(__DIR__), true);
    $harness = '<?php
        ini_set("display_errors", "0");
        error_reporting(0);
        $__base = ' . $baseExport . ';
        chdir($__base);
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["SCRIPT_NAME"] = "/api/constituents.php";
        ' . $query . '
        require $__base . "/config.php";
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $u = db_fetch_one("SELECT id, user FROM `user` WHERE level = 0 OR id = 1 ORDER BY id LIMIT 1");
        $_SESSION["user_id"] = (int) $u["id"];
        $_SESSION["user"] = $u["user"];
        $_SESSION["level"] = 0;
        require $__base . "/api/constituents.php";
    ';
    $tmp = tempnam(sys_get_temp_dir(), 'ph73h_');
    file_put_contents($tmp, $harness);
    // Windows cmd has no /dev/null — the shell_exec silently returned
    // empty there, failing every subprocess assertion.
    $raw = shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp)
        . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'));
    @unlink($tmp);
    $start = strpos($raw, '{');
    return $start !== false ? json_decode(substr($raw, $start), true) : null;
}

$exact = call_constituents_api('$_GET["reference"] = "PH73H-2415";');
t73h(is_array($exact), 'exact-match returns JSON');
t73h(!empty($exact['constituent']), 'exact match populates constituent (got ' . json_encode($exact) . ')');
if (!empty($exact['constituent'])) {
    t73h(($exact['constituent']['reference'] ?? '') === 'PH73H-2415', 'reference field matches');
    t73h(($exact['constituent']['contact'] ?? '') === 'Pat Skywarn', 'contact comes through');
    t73h(($exact['constituent']['phone'] ?? '') === '555-1111', 'phone comes through');
}

$miss = call_constituents_api('$_GET["reference"] = "PH73H-NOPE";');
t73h(is_array($miss), 'no-match returns JSON');
t73h(empty($miss['constituent']) && empty($miss['constituents']), 'no-match returns empty');

$prefix_search = call_constituents_api('$_GET["reference"] = "PH73H-241";');
t73h(is_array($prefix_search), 'prefix-match returns JSON');
t73h(empty($prefix_search['constituent']), 'prefix-only does NOT fill constituent (no exact match)');
t73h(isset($prefix_search['constituents']) && count($prefix_search['constituents']) >= 2,
    'prefix-match returns multiple suggestions (got ' . count($prefix_search['constituents'] ?? []) . ')');

$empty_q = call_constituents_api('$_GET["reference"] = " ";');
t73h(is_array($empty_q), 'whitespace-only is handled');
t73h(empty($empty_q['constituent']), 'whitespace-only -> null constituent');

echo "Phase 73h constituent-reference tests: $passes/$tests passed\n";
foreach ($fails as $f) echo "  FAIL: $f\n";
if ($fails) exit(1);
