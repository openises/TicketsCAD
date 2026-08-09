<?php
/**
 * GH#45 (Chris Byrd, 2026-08-09): "Unit lists two Traccar Providers. One
 * is off and is marked with a strikethrough but it will not delete."
 *
 * Root cause, confirmed by reading api/location.php: the unit-edit
 * "Remove" (X) button and the enable/disable toggle switch both called
 * the SAME action, `unbind` -- which only ever runs
 * `UPDATE unit_location_bindings SET active = 0`. Clicking Remove on a
 * row that was ALREADY inactive re-set active=0 to 0 (a real no-op), and
 * even on an active row, "Remove" never actually removed anything -- the
 * row stayed in the table forever, just deactivated. This test proves
 * the new `delete_binding` action actually deletes the row, and that the
 * pre-existing `unbind` action is unchanged (still a soft deactivate,
 * which the toggle switch still correctly relies on).
 *
 * Usage: php tools/test_gh45_location_binding_delete.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#45 — unit-edit location binding delete (real removal, not re-deactivation) ===\n\n";

$table = "{$prefix}unit_location_bindings";
$responderId = (int) db_fetch_value("SELECT id FROM {$prefix}responder LIMIT 1");
$providerId  = (int) db_fetch_value("SELECT id FROM {$prefix}location_providers LIMIT 1");
$marker = 'gh45_test_' . getmypid();

if (!$responderId || !$providerId) {
    echo "SKIP: no responder or location_providers row available to bind against\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// Literal port of api/location.php's unbind and delete_binding SQL.
function unbindBinding(string $table, int $id): void {
    db_query("UPDATE {$table} SET `active` = 0 WHERE `id` = ?", [$id]);
}
function deleteBinding(string $table, int $id): bool {
    $existing = db_fetch_one("SELECT id FROM {$table} WHERE `id` = ?", [$id]);
    if (!$existing) return false;
    db_query("DELETE FROM {$table} WHERE `id` = ?", [$id]);
    return true;
}

try {
    // ── 1. Seed an active binding, same shape the real bind action creates.
    db_query(
        "INSERT INTO {$table} (responder_id, provider_id, unit_identifier, priority, active)
         VALUES (?, ?, ?, 50, 1)",
        [$responderId, $providerId, $marker]
    );
    $bindId = (int) db_insert_id();
    $row = db_fetch_one("SELECT active FROM {$table} WHERE id = ?", [$bindId]);
    ($row && (int) $row['active'] === 1)
        ? ok('seeded an active binding, matching what the real bind action produces')
        : bad('seed setup', json_encode($row));

    // ── 2. Reproduce the OLD broken behavior: the toggle switch turns it
    //      off (unbind), matching a real user unchecking the enable switch.
    unbindBinding($table, $bindId);
    $afterUnbind = db_fetch_one("SELECT active FROM {$table} WHERE id = ?", [$bindId]);
    ($afterUnbind && (int) $afterUnbind['active'] === 0)
        ? ok('unbind (the toggle switch action) deactivates but does not remove the row')
        : bad('unbind should set active=0', json_encode($afterUnbind));

    // ── 3. This is Chris's exact repro: clicking "Remove" on an
    //      ALREADY-inactive row, using the OLD (buggy) code path that
    //      called unbind again -- a no-op, row still present.
    unbindBinding($table, $bindId);
    $stillThere = db_fetch_one("SELECT id FROM {$table} WHERE id = ?", [$bindId]);
    ($stillThere !== null)
        ? ok('reproduces the bug: calling unbind again (the old "Remove" behavior) leaves the row in place')
        : bad('expected the row to still exist under the old buggy behavior');

    // ── 4. The fix: delete_binding actually removes the row.
    $deleted = deleteBinding($table, $bindId);
    $goneNow = db_fetch_one("SELECT id FROM {$table} WHERE id = ?", [$bindId]);
    ($deleted === true && $goneNow === null)
        ? ok('delete_binding actually removes the row -- "Remove" now really removes it')
        : bad('delete_binding should delete the row', json_encode(['deleted' => $deleted, 'row' => $goneNow]));

    // ── 5. delete_binding on an already-gone id reports not-found rather
    //      than silently succeeding (matches the API's 404 json_error).
    $deletedAgain = deleteBinding($table, $bindId);
    ($deletedAgain === false)
        ? ok('deleting an already-deleted binding id is reported, not silently accepted')
        : bad('expected deleteBinding to return false for a missing row');

    // ── 6. Un-regressed: unbind on a still-existing, active row still only
    //      deactivates (the toggle switch's own behavior must be unchanged).
    db_query(
        "INSERT INTO {$table} (responder_id, provider_id, unit_identifier, priority, active)
         VALUES (?, ?, ?, 50, 1)",
        [$responderId, $providerId, $marker . '_b']
    );
    $bindId2 = (int) db_insert_id();
    unbindBinding($table, $bindId2);
    $row2 = db_fetch_one("SELECT active FROM {$table} WHERE id = ?", [$bindId2]);
    ($row2 && (int) $row2['active'] === 0)
        ? ok('toggle-switch behavior (unbind) is unchanged by this fix')
        : bad('unbind regression check', json_encode($row2));

    // ── 7. Source string check: the JS delete handler must call
    //      delete_binding, not unbind (the actual root cause of GH#45).
    $js = file_get_contents(__DIR__ . '/../assets/js/unit-edit.js');
    $delSection = substr($js, (int) strpos($js, 'loc-source-delete', (int) strpos($js, 'Bind delete handlers')));
    (strpos($delSection, "action: 'delete_binding'") !== false)
        ? ok('the Remove button\'s JS handler calls delete_binding, not unbind')
        : bad('unit-edit.js Remove handler should call delete_binding');
} finally {
    db_query("DELETE FROM {$table} WHERE unit_identifier IN (?, ?)", [$marker, $marker . '_b']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
