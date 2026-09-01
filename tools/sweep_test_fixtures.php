<?php
/**
 * Leaked test-fixture sweeper (GH#124, 2026-08-29).
 *
 * tests/test_gh118_assign_remove_ticketid.php creates real, live-database
 * fixtures early in the file and tears them down in a single trailing
 * try/catch block at the very end. That teardown works fine when the file
 * completes normally — but on the ~13 runs that fataled before ever
 * reaching it (GH#120's now-fixed disabled-`shell_exec()` trigger), the
 * fixtures were left behind as REAL rows on a REAL dispatch board: 13
 * incidents (8 still open), 26 responders, 26 assigns, 47 action rows.
 * tests/_test_fixture_guard.php now protects that test (and
 * test_gh116_multi_assign_status_scoping.php) going forward with a
 * register_shutdown_function()-based safety net that survives a mid-test
 * fatal — but that only helps a process that gets far enough to actually
 * run PHP shutdown handlers. It cannot help a process that is SIGKILLed,
 * OOM-killed, or dies before requiring that file at all, and it cannot
 * retroactively clean up whatever is on disk RIGHT NOW from every test
 * that has ever leaked, whether or not it has been retrofitted.
 *
 * THIS TOOL is that second, independent layer: an install owner (or a
 * periodic maintenance check) can run it directly against the live
 * database to find — and, only on request, remove — leftover rows from
 * ANY interrupted test run, without needing to already know that
 * particular test's own ad hoc naming scheme.
 *
 * ── THE MARKER CONVENTION ────────────────────────────────────────────
 *
 * There is no dedicated tracking table and no single canonical marker
 * column in this codebase today — but there IS a real, already-widespread
 * informal convention: every `tests/test_gh<NNN>_*.php` file that creates
 * live-database fixtures names them with a literal `gh<NNN>_` prefix in a
 * human-readable text column (confirmed across 20+ existing test files,
 * e.g. `ticket.scope = 'gh118_ticket'`, `responder.name = 'gh116_unit'`,
 * `un_status.status_val = 'gh116_avail'`). This tool recognizes exactly
 * that shape — `^gh[0-9]+_` (case-insensitive) — rather than inventing a
 * new convention nothing has ever used:
 *
 *   ticket.scope            e.g. 'gh118_ticket', 'gh116_call_A'
 *   responder.name          e.g. 'gh118_unit_A', 'gh116_other_unit'
 *   un_status.status_val    e.g. 'gh116_avail'
 *   un_status.group         e.g. 'gh116_test'
 *
 * `assigns` and `action` carry no marker text of their own — they are
 * matched by JOINing to an already-matched `ticket_id` (both tables) or
 * `responder_id` (assigns only), which is exactly how every existing
 * test's own trailing teardown finds them too.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────
 *
 * This is a LIVE dispatch database. Defaults are deliberately paranoid:
 *
 *   - REPORT ONLY by default. Nothing is ever deleted without the
 *     explicit --delete flag on the command line.
 *   - A minimum-age gate (--min-age-minutes, default 30) excludes any
 *     ticket/responder row created too recently to safely assume its
 *     test has finished — this project's own dev database is routinely
 *     shared by several concurrently-running Claude sessions (see
 *     CLAUDE.md's "concurrent sessions share one working tree" guidance),
 *     so a gh-prefixed row created 90 seconds ago is far more likely to
 *     be an in-flight test than a genuine leak. `un_status` has no
 *     creation timestamp in this schema and is not age-gated — in
 *     practice it is far lower-risk than ticket/responder, since a
 *     matched `un_status` row only matters if something still points at
 *     it, and that something (a responder row) IS age-gated.
 *   - Deletes run children first (assigns, action) then parents
 *     (ticket, responder, un_status) even though none of these tables
 *     enforce a foreign key that would require it — defensive habit, not
 *     a requirement of this schema today.
 *
 * Usage:
 *   php tools/sweep_test_fixtures.php                        report only
 *   php tools/sweep_test_fixtures.php --delete                actually remove matches
 *   php tools/sweep_test_fixtures.php --min-age-minutes=60    change the safety window (default 30)
 *   php tools/sweep_test_fixtures.php --quiet                 suppress the per-row id listing
 *
 * Exit codes: 0 = ran cleanly (whether or not anything was found/removed)
 *             1 = a database error prevented the scan from completing
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$argvv       = $argv ?? [];
$doDelete    = in_array('--delete', $argvv, true);
$quiet       = in_array('--quiet', $argvv, true);
$minAgeMin   = 30;
foreach ($argvv as $arg) {
    if (str_starts_with($arg, '--min-age-minutes=')) {
        $v = (int) substr($arg, strlen('--min-age-minutes='));
        if ($v >= 0) $minAgeMin = $v;
    }
}

$prefix = $GLOBALS['db_prefix'] ?? '';

function say(string $s = ''): void { echo $s . "\n"; }

/** @param int[] $ids */
function id_list(array $ids, bool $quiet): string {
    if ($quiet || !$ids) return '';
    sort($ids);
    return '  ids: ' . implode(', ', $ids);
}

say('=== Test-fixture sweeper (GH#124) ===');
say('Marker convention: text matching /^gh[0-9]+_/i in ticket.scope, responder.name, ' .
    'un_status.status_val/group, plus their assigns/action child rows.');
say($doDelete ? 'Mode: DELETE — matched rows WILL be removed.' : 'Mode: REPORT ONLY (pass --delete to remove matched rows)');
say($minAgeMin > 0
    ? "Safety window: ticket/responder rows younger than {$minAgeMin} minute(s) are excluded."
    : 'Safety window: DISABLED (--min-age-minutes=0) — every matching row is eligible regardless of age.');
say('');

try {
    // A strict `< cutoff` comparison against `NOW() - $minAgeMin minutes`
    // is exactly right for a positive window, but at $minAgeMin === 0 the
    // cutoff computed here can land in the SAME SECOND a fixture was just
    // inserted with SQL's own NOW() (PHP's clock and the DB server's clock
    // are never perfectly synchronized, and both round to whole seconds),
    // so `date < cutoff` can spuriously exclude a row created moments ago
    // even though "0 minutes" was meant to mean "no gate at all". Skip the
    // age condition entirely rather than trust a same-second inequality.
    $ageGated = $minAgeMin > 0;
    $cutoff = $ageGated ? date('Y-m-d H:i:s', time() - $minAgeMin * 60) : null;

    // ── 1. Tickets ──────────────────────────────────────────────────
    $tickets = $ageGated
        ? db_fetch_all("SELECT id, scope FROM `{$prefix}ticket` WHERE scope REGEXP '^gh[0-9]+_' AND `date` < ?", [$cutoff])
        : db_fetch_all("SELECT id, scope FROM `{$prefix}ticket` WHERE scope REGEXP '^gh[0-9]+_'");
    $ticketIds = array_map(fn($r) => (int) $r['id'], $tickets);

    // ── 2. Responders ───────────────────────────────────────────────
    $responders = $ageGated
        ? db_fetch_all("SELECT id, name FROM `{$prefix}responder` WHERE name REGEXP '^gh[0-9]+_' AND `updated` < ?", [$cutoff])
        : db_fetch_all("SELECT id, name FROM `{$prefix}responder` WHERE name REGEXP '^gh[0-9]+_'");
    $responderIds = array_map(fn($r) => (int) $r['id'], $responders);

    // ── 3. un_status (no creation timestamp in this schema — see docblock) ──
    $statuses = db_fetch_all(
        "SELECT id, status_val, `group` FROM `{$prefix}un_status`
          WHERE status_val REGEXP '^gh[0-9]+_' OR `group` REGEXP '^gh[0-9]+_'"
    );
    $statusIds = array_map(fn($r) => (int) $r['id'], $statuses);

    // ── 4. assigns — matched via either parent already matched above ──
    $assignIds = [];
    if ($ticketIds || $responderIds) {
        $clauses = [];
        $params  = [];
        if ($ticketIds) {
            $clauses[] = 'ticket_id IN (' . implode(',', array_fill(0, count($ticketIds), '?')) . ')';
            array_push($params, ...$ticketIds);
        }
        if ($responderIds) {
            $clauses[] = 'responder_id IN (' . implode(',', array_fill(0, count($responderIds), '?')) . ')';
            array_push($params, ...$responderIds);
        }
        $assigns = db_fetch_all(
            "SELECT id FROM `{$prefix}assigns` WHERE " . implode(' OR ', $clauses),
            $params
        );
        $assignIds = array_map(fn($r) => (int) $r['id'], $assigns);
    }

    // ── 5. action — matched via ticket_id only (no responder_id column) ──
    $actionIds = [];
    if ($ticketIds) {
        $actions = db_fetch_all(
            "SELECT id FROM `{$prefix}action` WHERE ticket_id IN ("
                . implode(',', array_fill(0, count($ticketIds), '?')) . ')',
            $ticketIds
        );
        $actionIds = array_map(fn($r) => (int) $r['id'], $actions);
    }

    // ── Report ────────────────────────────────────────────────────────
    $found = [
        'action'    => $actionIds,
        'assigns'   => $assignIds,
        'ticket'    => $ticketIds,
        'responder' => $responderIds,
        'un_status' => $statusIds,
    ];
    $total = array_sum(array_map('count', $found));

    say('Found:');
    foreach ($found as $table => $ids) {
        $n = count($ids);
        say('  ' . str_pad($table, 10) . str_pad((string) $n, 6) . 'row(s)' . id_list($ids, $quiet));
    }
    say('');
    say("Total: {$total} row(s) across " . count(array_filter($found)) . ' table(s) with at least one match');
    say('');

    if ($total === 0) {
        say('Nothing to do.');
        exit(0);
    }

    if (!$doDelete) {
        say('Dry run — nothing deleted. Re-run with --delete to remove these rows.');
        exit(0);
    }

    // ── Delete, children first ───────────────────────────────────────
    $removed = 0;
    foreach (['action', 'assigns', 'ticket', 'responder', 'un_status'] as $table) {
        $ids = $found[$table];
        if (!$ids) continue;
        $stmt = db_query(
            "DELETE FROM `{$prefix}{$table}` WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $n = $stmt->rowCount();
        $removed += $n;
        say("Deleted {$n} row(s) from {$table}" . id_list($ids, $quiet));
    }
    say('');
    say("Done — removed {$removed} row(s) total.");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'sweep_test_fixtures: ' . $e->getMessage() . "\n");
    exit(1);
}
