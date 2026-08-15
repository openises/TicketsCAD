<?php
/**
 * run_rbac_v2.php step A8 idempotency-check regression (2026-08-15).
 *
 * ORIGIN. Fixing the permissions-schema-ordering bug (see
 * tests/test_permissions_schema_columns.php) let five phase scripts start
 * succeeding at seeding ALREADY-canonical permissions (code = resource.verb,
 * e.g. a script inserting code=par.manage with resource=par/verb=manage
 * directly) on a fresh install. That exposed a SECOND, previously
 * unreachable bug: A8's own idempotency check asked "does at least one
 * already-canonical row exist" to mean "has THIS STEP already run" -- but a
 * canonical-shaped row can now exist from a completely different script
 * that has nothing to do with A8's actual job (migrating the legacy
 * screen.X, action.X and widget.X codes to <resource>.<verb> form and
 * linking the old row via deprecated_alias_of). The moment any such row existed
 * before A8 ran, the check went true and A8 skipped ENTIRELY -- so on a
 * fresh install, incidents.view, facilities.view, responders.view,
 * roster.view, and every other alias + role_permissions mirror this step is
 * responsible for silently never got created. Permissions on a fresh
 * install dropped from 203 to 116 with zero error anywhere in the pipeline
 * (confirmed reproducible: two separate CI runs of the same commit both
 * landed on exactly 116).
 *
 * FIX. The check now asks the real question: is there still a row this
 * step would act on (resource/verb set, not yet marked deprecated, not
 * already in canonical shape)? Rows A8 has already processed get
 * deprecated_alias_of set by its own apply callback, so a second run of
 * the SAME install correctly counts zero remaining and skips -- but an
 * unrelated canonical-shaped row from another script can never satisfy
 * the check on A8's behalf.
 *
 * Schema/text-only by design (reads run_rbac_v2.php's source, no live
 * database) so it catches a regression on a plain PHP CLI with nothing
 * running, the same convention as test_permissions_schema_columns.php.
 */

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/sql/run_rbac_v2.php');

$pass = 0; $fail = 0;
function tra(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== run_rbac_v2.php A8 idempotency-check regression ===\n\n";

$start = strpos($src, "rrbv2_step('permissions: seed canonical codes + link aliases',");
$end = strpos($src, "// ─────────────────────────────────────────────────────────────────────\n// A10", $start ?: 0);
if ($start === false || $end === false || $end <= $start) {
    echo "[FAIL] could not isolate the A8 step block from sql/run_rbac_v2.php (markers moved?)\n";
    echo "\n1 passed, 1 failed\n";
    exit(1);
}
$step = substr($src, $start, $end - $start);

// Split into check callback (first) vs apply callback (second) on the
// "function () use ($prefix) {" that starts $rows = db_fetch_all(...) --
// that line only appears once, at the top of the apply callback.
$applyStart = strpos($step, '$rows = db_fetch_all');
tra('could isolate check vs apply callbacks within the A8 step', $applyStart !== false);
$checkBlock = $applyStart !== false ? substr($step, 0, $applyStart) : $step;
$applyBlock = $applyStart !== false ? substr($step, $applyStart) : '';

// The bug: a bare "count of already-canonical rows > 0" check. Must be gone
// as an ACTUAL return expression (a mention in the explanatory comment
// above it is fine and expected -- check the return statement specifically).
tra('the old "return $canonical > 0" false-positive check is gone',
    strpos($checkBlock, 'return $canonical > 0') === false,
    'found the exact pattern that let an unrelated canonical-shaped row short-circuit this step');

// The fix: ask whether there is still unprocessed work, scoped to rows THIS
// step owns (deprecated_alias_of IS NULL, not already canonical).
tra('the check counts unfinished work (deprecated_alias_of IS NULL)',
    strpos($checkBlock, 'deprecated_alias_of IS NULL') !== false);
tra('the check excludes rows already in canonical shape (code <> CONCAT(resource, \'.\', verb))',
    strpos($checkBlock, "code <> CONCAT(resource, '.', verb)") !== false);
tra('the check is satisfied ("already done") only when zero rows need work',
    strpos($checkBlock, 'return $needsWork === 0') !== false);

// The apply side must still mark processed rows deprecated -- the fixed
// check's whole correctness depends on this staying true (it's what makes
// $needsWork hit zero after a real run, so a second run is a clean skip).
tra('apply still marks a processed old row deprecated (deprecated_alias_of = ?)',
    strpos($applyBlock, 'SET deprecated_alias_of = ?') !== false);
tra('apply still skips (does not deprecate) a row that is already canonical',
    strpos($applyBlock, 'Already canonical') !== false
    && strpos($applyBlock, 'continue;') !== false);

// The step must still not be marked $critical=true -- its per-row apply
// loop is naturally resumable (a partial failure leaves needsWork > 0 for
// the remaining rows on the next run), so halting the whole install here
// would be a regression in the opposite direction.
tra('the A8 rrbv2_step call is not marked critical (per-row work is self-resumable)',
    strpos($step, ', true);') === false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
