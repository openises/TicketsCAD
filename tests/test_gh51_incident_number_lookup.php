<?php
/**
 * GH#51 (cbyrdmo/rjonesbsink, 2026-08-12) — "Incident #" filters (Reports'
 * After Action, PAR's per-incident timeline) demanded the internal
 * `ticket.id`, a number dispatchers never see. They only have their own
 * configured case number (e.g. "26-0091"). incnum_resolve_input() in
 * inc/incident-number.php is the shared fix: resolve a case number to
 * the internal id server-side, with a numeric-id fallback for tickets
 * created before Phase 15 (incident_number is NULL for those).
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/incident-number.php';

$pass = 0;
$fail = 0;

function test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = "{$prefix}ticket";
$typeTable = "{$prefix}in_types";
$seedIds = [];

function gh51_cleanup(string $table, array $ids): void
{
    foreach ($ids as $id) {
        try { db_query("DELETE FROM {$table} WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
}

register_shutdown_function(function () use (&$seedIds, $table) {
    gh51_cleanup($table, $seedIds);
});

try {
    // A real in_types_id is required by ticket's NOT NULL constraint —
    // reuse whatever the install already has rather than inventing one.
    $typeId = (int) db_fetch_value("SELECT id FROM {$typeTable} LIMIT 1");
    test('a real in_types row exists to attach test tickets to', $typeId > 0);
    if ($typeId <= 0) {
        throw new Exception('no in_types row available — cannot seed test tickets');
    }

    $caseNum = 'GH51-TEST-' . substr(md5((string) mt_getrandmax()), 0, 6);

    // Row with a real case number (the normal, post-Phase-15 shape).
    db_query(
        "INSERT INTO {$table} (in_types_id, scope, description, incident_number) VALUES (?, ?, ?, ?)",
        [$typeId, 'GH51 regression', 'GH51 regression', $caseNum]
    );
    $idWithNumber = (int) db_insert_id();
    $seedIds[] = $idWithNumber;

    // Row with NO case number (simulates a pre-Phase-15 legacy ticket).
    db_query(
        "INSERT INTO {$table} (in_types_id, scope, description, incident_number) VALUES (?, ?, ?, NULL)",
        [$typeId, 'GH51 regression legacy', 'GH51 regression legacy']
    );
    $idLegacy = (int) db_insert_id();
    $seedIds[] = $idLegacy;

    // 1. Resolve by the actual case number — the primary path.
    test('resolves a real case number to its ticket id',
        incnum_resolve_input($caseNum) === $idWithNumber,
        'got ' . incnum_resolve_input($caseNum));

    // 2. Legacy ticket with no case number — falls back to the raw id.
    test('resolves a legacy ticket (no case number) by its raw internal id',
        incnum_resolve_input((string) $idLegacy) === $idLegacy);

    // 3. Priority: an exact case-number match wins over interpreting the
    //    SAME string as another ticket's raw id. Give a second ticket a
    //    case number that is literally the legacy ticket's id as text.
    db_query(
        "INSERT INTO {$table} (in_types_id, scope, description, incident_number) VALUES (?, ?, ?, ?)",
        [$typeId, 'GH51 regression priority', 'GH51 regression priority', (string) $idLegacy]
    );
    $idPriority = (int) db_insert_id();
    $seedIds[] = $idPriority;
    test('an exact case-number match takes priority over the raw-id fallback',
        incnum_resolve_input((string) $idLegacy) === $idPriority,
        'got ' . incnum_resolve_input((string) $idLegacy) . ', expected the case-number match (' . $idPriority . '), not the raw id (' . $idLegacy . ')');

    // 4. Nothing matches — no case number, not a real id either.
    test('a non-numeric string matching nothing resolves to 0',
        incnum_resolve_input('NO-SUCH-CASE-NUMBER-XYZ') === 0);
    test('a numeric string matching no case number and no ticket id resolves to 0',
        incnum_resolve_input('99999999') === 0);

    // 5. Empty / whitespace input.
    test('empty input resolves to 0', incnum_resolve_input('') === 0);
    test('whitespace-only input resolves to 0', incnum_resolve_input('   ') === 0);

    // 6. Leading/trailing whitespace around a real case number still resolves.
    test('surrounding whitespace is trimmed before matching',
        incnum_resolve_input("  {$caseNum}  ") === $idWithNumber);

} catch (Throwable $e) {
    test('no unexpected exception during GH#51 regression', false, $e->getMessage());
}

// ── API-contract checks: confirm the endpoints actually call the
// resolver instead of a bare (int) cast on user input. ──────────────
$reportsApi = file_get_contents($root . '/api/reports.php');
test('api/reports.php resolves incident input via incnum_resolve_input()',
    strpos($reportsApi, 'incnum_resolve_input(') !== false);
test('api/reports.php accepts incident_number (the case number), not just incident_id',
    strpos($reportsApi, "\$_GET['incident_number']") !== false);

$parApi = file_get_contents($root . '/api/par.php');
test('api/par.php\'s history action resolves ticket input via incnum_resolve_input()',
    (bool) preg_match('/history.*?incnum_resolve_input\(/s', $parApi));

gh51_cleanup($table, $seedIds);
$seedIds = [];

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
