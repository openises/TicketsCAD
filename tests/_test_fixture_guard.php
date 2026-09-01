<?php
/**
 * Shared test helper: guaranteed fixture cleanup, even when a test dies
 * before reaching its own teardown.
 *
 * GH#124 (reported 2026-08-28). tests/test_gh118_assign_remove_ticketid.php
 * creates its fixtures early (a ticket + two responders + their assigns
 * rows) and tears them down in a single trailing try/catch block at the
 * very end of the file. That teardown DOES work correctly when the file
 * completes normally — but on the ~13 runs that fataled before ever
 * reaching it (GH#120's now-fixed `@shell_exec()` trigger killed the
 * script ~300 lines before its own teardown), the fixtures were left
 * behind as REAL rows on a REAL dispatch board: 13 open-looking incidents,
 * 26 responders, 26 assigns, 47 action rows, all with no `finally`, no
 * shutdown handler, and no external marker recognizable without already
 * knowing that one test's own ad hoc naming scheme.
 *
 * GH#120's specific trigger is fixed, but the STRUCTURAL gap is not: ANY
 * test that creates live-database fixtures early and cleans them up late
 * can leak the same way the moment something between the two points
 * throws, fatals, or exits early. This file is the reusable fix, built
 * once rather than patched per-test — modeled on tests/_test_admin.php
 * and tests/_test_node_probe.php's own shared-helper shape (a `_`-prefixed
 * filename so tools/test_all.php's `test_*.php` glob does not try to run
 * it, `function_exists()` guards so it is safe to `require_once` from
 * many test files, no dependency beyond what a test already loads).
 *
 * ── THE MECHANISM ─────────────────────────────────────────────────────
 *
 * PHP's register_shutdown_function() fires on every way a script can end:
 * normal completion, an explicit exit()/die(), an uncaught exception, AND
 * a fatal Error (E_ERROR) — the one case a trailing try/catch or even a
 * `finally` block wrapped around the offending code cannot reach, because
 * the fatal unwinds past ALL of them. (It does not fire on a hard process
 * kill — SIGKILL, an OOM kill, a parse error before this file's own
 * require completes — which is exactly why the sweeper tool in
 * tools/sweep_test_fixtures.php exists as an independent, external second
 * layer: this file protects a single process from dying mid-test, the
 * sweeper protects an install from a process that never got to run any
 * shutdown handler at all.)
 *
 * A test registers each fixture AS IT CREATES IT — a table+id pair, a raw
 * cleanup statement, or an arbitrary closure — via the three
 * `test_fixture_guard_track*()` functions below. Exactly ONE
 * register_shutdown_function() call is armed process-wide (a `static`
 * guard inside test_fixture_guard_arm() — safe to call from many test
 * files' fixture-creation code without double-registering, satisfying the
 * "idempotent across many test files in one process" requirement even
 * though today's runner only ever runs one test file per process).
 *
 * The shutdown sweep is SAFE ALONGSIDE a test's own existing trailing
 * teardown: it is a plain `DELETE ... WHERE id = ?` (or the caller's own
 * SQL/closure) against rows that, on the normal/happy path, the test's
 * own teardown has already removed — a delete matching zero rows is a
 * silent no-op. Nothing is printed unless the sweep actually finds and
 * removes something the test's own teardown did NOT reach, which is
 * exactly the case worth surfacing.
 *
 * ── USAGE ──────────────────────────────────────────────────────────────
 *
 *   require_once __DIR__ . '/_test_fixture_guard.php';
 *
 *   db_query("INSERT INTO `{$prefix}responder` (...) VALUES (...)");
 *   $rid = (int) db_insert_id();
 *   test_fixture_guard_track('responder', $rid);           // by table + id
 *
 *   $ra = assign_create_internal($tid, $rid, '', $userId);
 *   test_fixture_guard_track('assigns', (int) $ra['id']);
 *
 *   // Side-effect rows a writer creates without ever handing back an id
 *   // (e.g. an `action` log entry stamped by the same writer call) are
 *   // covered with a raw cleanup statement instead:
 *   test_fixture_guard_track_where('action', 'ticket_id = ?', [$tid]);
 *
 *   // Fully custom cleanup (e.g. calling a real internal delete/undo
 *   // function instead of a raw DELETE):
 *   test_fixture_guard_track_cleanup(function () use ($fileId, $userId) {
 *       file_delete_internal($fileId, $userId);
 *   }, 'uploaded test file');
 *
 * A test keeps its own existing trailing try/catch teardown too — this is
 * defense in depth, not a replacement. The normal-path cleanup should
 * still be immediate and explicit; the shutdown sweep is the backstop for
 * the path nobody plans for.
 */

if (!function_exists('test_fixture_guard_track')) {
    /**
     * Register one fixture row for guaranteed cleanup by (table, id).
     *
     * @param string $table  Unprefixed table name — the db_prefix is
     *                       resolved automatically at sweep time, not at
     *                       registration time, so this works even if a
     *                       test tracks a fixture before config.php has
     *                       finished setting $GLOBALS['db_prefix'].
     * @param int    $id     Primary key value. Ids <= 0 are silently
     *                       ignored so a caller need not guard against a
     *                       failed INSERT itself before registering.
     * @param string $column Primary-key column name, default 'id'.
     */
    function test_fixture_guard_track(string $table, int $id, string $column = 'id'): void {
        if ($id <= 0) return;
        $GLOBALS['__test_fixture_guard_rows'][] = [
            'table' => $table, 'column' => $column, 'id' => $id,
        ];
        test_fixture_guard_arm();
    }
}

if (!function_exists('test_fixture_guard_track_where')) {
    /**
     * Register a raw `DELETE FROM {prefix}{table} WHERE {whereSql}` for
     * guaranteed cleanup — for fixtures that don't have a single id to
     * track (e.g. every `action` row a writer stamped for a given
     * ticket_id as a side effect).
     *
     * $whereSql must use `?` placeholders bound against $params — never
     * interpolate a value directly, same discipline as every other query
     * in this codebase.
     */
    function test_fixture_guard_track_where(string $table, string $whereSql, array $params = []): void {
        $GLOBALS['__test_fixture_guard_sql'][] = [
            'table' => $table, 'where' => $whereSql, 'params' => $params,
        ];
        test_fixture_guard_arm();
    }
}

if (!function_exists('test_fixture_guard_track_cleanup')) {
    /**
     * Register an arbitrary closure to run at sweep time — for cleanup
     * that isn't a plain DELETE (e.g. calling a real internal
     * delete/undo function so a file's on-disk blob is removed along
     * with its metadata row).
     *
     * The closure receives no arguments and its return value is ignored;
     * throw or return whatever is convenient. A thrown exception is
     * caught and reported the same as a failed SQL cleanup — it can
     * never re-crash the process during shutdown.
     */
    function test_fixture_guard_track_cleanup(callable $fn, string $label = ''): void {
        $GLOBALS['__test_fixture_guard_callbacks'][] = ['fn' => $fn, 'label' => $label];
        test_fixture_guard_arm();
    }
}

if (!function_exists('test_fixture_guard_arm')) {
    /**
     * Register the shutdown sweep exactly once for this process, no
     * matter how many times (or from how many different test files'
     * shared code) this is called.
     */
    function test_fixture_guard_arm(): void {
        static $armed = false;
        if ($armed) return;
        $armed = true;
        register_shutdown_function('test_fixture_guard_sweep');
    }
}

if (!function_exists('test_fixture_guard_sweep')) {
    /**
     * The shutdown-registered sweep itself. Never call this directly from
     * a test — it runs automatically once, at process shutdown, for
     * whatever this process actually registered.
     *
     * Sweeps in REVERSE registration order (closures, then raw
     * WHERE-clause deletes, then by-id deletes — each group itself
     * reversed) so a fixture registered later — typically a child row
     * created after its parent — is cleaned up before the parent it
     * depends on, defensively, even though none of this project's own
     * test-fixture tables currently enforce a foreign key that would
     * make the order matter.
     */
    function test_fixture_guard_sweep(): void {
        $rows = $GLOBALS['__test_fixture_guard_rows'] ?? [];
        $sqls = $GLOBALS['__test_fixture_guard_sql'] ?? [];
        $cbs  = $GLOBALS['__test_fixture_guard_callbacks'] ?? [];
        if (!$rows && !$sqls && !$cbs) return;

        // Clear immediately so a shutdown function registered by
        // something ELSE in this same process (or a re-require of this
        // file) can never see and re-run this process's own list twice.
        $GLOBALS['__test_fixture_guard_rows'] = [];
        $GLOBALS['__test_fixture_guard_sql'] = [];
        $GLOBALS['__test_fixture_guard_callbacks'] = [];

        if (!function_exists('db_query')) {
            // config.php/inc/db.php were never loaded (or somehow
            // unloaded) — nothing this helper can do. Report it plainly
            // rather than silently pretending cleanup happened.
            echo "[fixture-guard] " . (count($rows) + count($sqls) + count($cbs))
                . " fixture(s) registered but db_query() is unavailable at "
                . "shutdown — cleanup could not run\n";
            return;
        }

        $prefix  = $GLOBALS['db_prefix'] ?? '';
        $cleaned = 0;
        $errors  = [];

        foreach (array_reverse($cbs) as $entry) {
            try {
                call_user_func($entry['fn']);
                $cleaned++;
            } catch (Throwable $e) {
                $errors[] = ($entry['label'] !== '' ? $entry['label'] : 'callback') . ': ' . $e->getMessage();
            }
        }

        foreach (array_reverse($sqls) as $entry) {
            try {
                $t = $prefix . $entry['table'];
                $stmt = db_query("DELETE FROM `{$t}` WHERE {$entry['where']}", $entry['params']);
                $cleaned += $stmt->rowCount();
            } catch (Throwable $e) {
                $errors[] = "{$entry['table']} ({$entry['where']}): " . $e->getMessage();
            }
        }

        foreach (array_reverse($rows) as $entry) {
            try {
                $t = $prefix . $entry['table'];
                $stmt = db_query("DELETE FROM `{$t}` WHERE `{$entry['column']}` = ?", [$entry['id']]);
                $cleaned += $stmt->rowCount();
            } catch (Throwable $e) {
                $errors[] = "{$entry['table']}.{$entry['column']}={$entry['id']}: " . $e->getMessage();
            }
        }

        // Silent on the normal/happy path: the test's own trailing
        // teardown already removed everything, so every DELETE here
        // matched zero rows and every closure is a harmless re-run of
        // work already done. Only speak up when this sweep actually did
        // something the test's own teardown did not — that is precisely
        // the scenario this file exists to catch.
        if ($cleaned > 0) {
            echo "[fixture-guard] shutdown sweep removed {$cleaned} leaked fixture row(s) "
                . "that the test's own teardown did not reach\n";
        }
        if ($errors) {
            echo "[fixture-guard] " . count($errors) . " cleanup error(s) (non-fatal): "
                . implode('; ', array_slice($errors, 0, 5)) . "\n";
        }
    }
}
