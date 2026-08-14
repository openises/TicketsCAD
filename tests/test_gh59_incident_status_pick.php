<?php
/**
 * GH#59 (Chris Byrd, 2026-08-13) — on the Incident Page, picking "At Scene"
 * or "On Scene" for a unit always reverted to "In Area". Root cause:
 * assign_update_status_internal() (inc/assignment-write.php), used ONLY by
 * the Incident Page's per-unit status control (api/incident-assign.php),
 * resolved a picked status id to its `incident_action` ('responding' /
 * 'on_scene' / 'clear') and then, in the 'responding' and 'on_scene'
 * branches, DISCARDED the specific id the dispatcher picked and re-derived
 * one via _assign_status_id_by_action() -- "whichever status mapped to
 * that action sorts first". On an install with more than one status
 * mapped to the same action (e.g. "On Scene" and a custom "At Scene"
 * alias, both incident_action='on_scene'), every pick collapsed to
 * whichever one happened to sort first -- "In Area" on the reporting
 * install. The sibling 'clear' branch already preferred the picked id
 * (line ~430); 'responding' and 'on_scene' did not. Other status-change
 * entry points (unit-edit.php, situation.php) go through a DIFFERENT
 * writer, responder_set_status_internal(), which always wrote the exact
 * id passed in -- which is why only the Incident Page was affected.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pass = 0; $fail = 0;
function test(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

require_once $root . '/inc/assignment-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];

$tid = 0; $rid = 0; $statFirstOnScene = 0; $statSecondOnScene = 0; $statResponding = 0; $assignId = 0;
try {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh59 fixture', 'GH#59 regression fixture', NOW(), NOW(), 1)", [$typeId]);
    $tid = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh59_unit', 'GH59', 'test', 1, NOW(), NOW())");
    $rid = (int) db_insert_id();

    // Two DISTINCT statuses mapped to the SAME incident_action, exactly the
    // shape ("On Scene" + a custom "At Scene" alias) that collapsed to
    // whichever sorted first. Deliberately give the FIRST one the lower
    // sort/id so a bug that ignores the picked id and re-derives would
    // land on it instead of the SECOND one actually picked below.
    db_query("INSERT INTO `{$prefix}un_status`
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par)
              VALUES ('gh59 In Area', 'test', 0, 0, 'n', 'n', 'busy', 1,
                      'transparent', '#000000', 'on_scene', 0)");
    $statFirstOnScene = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}un_status`
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par)
              VALUES ('gh59 At Scene', 'test', 0, 0, 'n', 'n', 'busy', 2,
                      'transparent', '#000000', 'on_scene', 0)");
    $statSecondOnScene = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}un_status`
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par)
              VALUES ('gh59 Responding', 'test', 0, 0, 'n', 'n', 'busy', 3,
                      'transparent', '#000000', 'responding', 0)");
    $statResponding = (int) db_insert_id();

    $created = assign_create_internal($tid, $rid, '', 1);
    test('unit assigned via the real writer', (int) ($created['id'] ?? 0) > 0);
    $assignId = (int) ($created['id'] ?? 0);

    // Drive the EXACT path incident-detail.js uses: a specific numeric
    // un_status.id, the SECOND on-scene-mapped status, not the first.
    $r = assign_update_status_internal($assignId, $statSecondOnScene, 1);
    test('assign_update_status_internal() reports success', empty($r['errors']), json_encode($r));

    $got = (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    test('the SPECIFIC status picked is written, not the lower-sorted sibling',
        $got === $statSecondOnScene,
        "picked {$statSecondOnScene}, got {$got}" . ($got === $statFirstOnScene ? ' (collapsed to the first-sorted sibling — the exact reported bug)' : ''));

    // The 'responding' branch had the identical bug shape -- confirm it too.
    $r2 = assign_update_status_internal($assignId, $statResponding, 1);
    test("the 'responding' branch also writes the exact picked id",
        empty($r2['errors'])
        && (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$rid]) === $statResponding);
} catch (Exception $e) {
    echo "[FAIL] fixture threw: " . $e->getMessage() . "\n"; $fail++;
}

// Teardown.
try {
    if ($assignId > 0) db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$assignId]);
    if ($tid > 0) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    if ($rid > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    foreach ([$statFirstOnScene, $statSecondOnScene, $statResponding] as $sid) {
        if ($sid > 0) db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$sid]);
    }
} catch (Exception $e) {
    echo "Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
