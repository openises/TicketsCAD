<?php
/**
 * GH#54 (cbyrdmo, 2026-08-12) — Import/Export 'insert' mode duplicated
 * facilities on re-import instead of skipping them.
 *
 * The 'insert' radio in import-export.php is checked by default and its
 * own label reads "Insert only — skip rows that match existing records".
 * execute_import() honored that promise only when $mode === 'upsert' —
 * an 'insert' row fell straight through to an unconditional INSERT with
 * no match lookup at all. Exporting Facilities from a test install and
 * importing into live (the reporter's exact steps) duplicated every
 * facility whose name already existed on live, because the default mode
 * never checked.
 *
 * This drives the REAL execute_import() against a live `facilities`
 * table (not a hand-seeded expectation of what it does) so the test
 * actually exercises the writer GH#54 was filed against.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/import-export.php';

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

$config = get_table_config('facility');
$table  = db_table($config['table']);
$seedIds = [];
$uniq = 'GH54 Test Facility ' . substr(md5((string) mt_getrandmax()), 0, 8);

function gh54_cleanup(string $table, array $ids): void
{
    foreach ($ids as $id) {
        try { db_query("DELETE FROM {$table} WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
}

register_shutdown_function(function () use (&$seedIds, $table) {
    gh54_cleanup($table, $seedIds);
});

try {
    // Seed one "already on live" facility directly (bypassing the
    // import path entirely, so the test starts from a state the real
    // app produces, not one only execute_import() could produce).
    db_query(
        "INSERT INTO {$table} (name, description) VALUES (?, ?)",
        [$uniq, 'Seeded directly for GH#54 regression test']
    );
    $seedId = (int) db_insert_id();
    $seedIds[] = $seedId;

    $countByName = function () use ($table, $uniq) {
        return (int) db_fetch_value("SELECT COUNT(*) FROM {$table} WHERE name = ?", [$uniq]);
    };

    test('seed facility present exactly once before any import', $countByName() === 1);

    // 1. Default 'insert' mode, re-importing a row that matches on name
    //    (the reporter's exact scenario) must SKIP, not duplicate.
    $dupRow = ['name' => $uniq, 'description' => 'Re-imported from test system'];
    $result = execute_import([$dupRow], $config, 1, 'insert');
    test('insert mode: matching row is skipped, not inserted', $result['inserted'] === 0 && $result['skipped'] === 1,
        'inserted=' . $result['inserted'] . ' skipped=' . $result['skipped']);
    test('insert mode: no duplicate row was created', $countByName() === 1, 'count=' . $countByName());

    // 2. Original data must be untouched by an 'insert'-mode skip (it
    //    must not silently update, only 'upsert' updates).
    $unchangedDesc = db_fetch_value("SELECT description FROM {$table} WHERE id = ?", [$seedId]);
    test('insert mode: matched row is not modified (upsert-only behavior stays upsert-only)',
        $unchangedDesc === 'Seeded directly for GH#54 regression test');

    // 3. A genuinely new name must still insert normally under 'insert'
    //    mode — the fix must not turn every insert into a skip.
    $freshName = $uniq . ' Fresh';
    $freshRow = ['name' => $freshName, 'description' => 'Brand new facility'];
    $result2 = execute_import([$freshRow], $config, 1, 'insert');
    test('insert mode: non-matching row still inserts', $result2['inserted'] === 1 && $result2['skipped'] === 0,
        'inserted=' . $result2['inserted'] . ' skipped=' . $result2['skipped']);
    $freshId = (int) db_fetch_value("SELECT id FROM {$table} WHERE name = ? ORDER BY id DESC LIMIT 1", [$freshName]);
    if ($freshId > 0) $seedIds[] = $freshId;
    test('insert mode: the newly inserted row is really there', $freshId > 0);

    // 4. 'upsert' mode must still UPDATE on a match (unchanged behavior —
    //    the fix must not have collapsed upsert into insert's skip path).
    $upsertRow = ['name' => $uniq, 'description' => 'Updated via upsert'];
    $result3 = execute_import([$upsertRow], $config, 1, 'upsert');
    test('upsert mode: matching row updates, does not insert', $result3['updated'] === 1 && $result3['inserted'] === 0,
        'updated=' . $result3['updated'] . ' inserted=' . $result3['inserted']);
    $updatedDesc = db_fetch_value("SELECT description FROM {$table} WHERE id = ?", [$seedId]);
    test('upsert mode: the description actually changed', $updatedDesc === 'Updated via upsert');
    test('upsert mode: still exactly one row for this name (no duplicate either)', $countByName() === 1);

} catch (Throwable $e) {
    test('no unexpected exception during GH#54 regression', false, $e->getMessage());
}

gh54_cleanup($table, $seedIds);
$seedIds = [];

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
