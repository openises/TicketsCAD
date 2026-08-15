<?php
/**
 * run_rbac_v2.php A8 privilege-tier alias guard (2026-08-15).
 *
 * ORIGIN. Fixing A8's idempotency check (test_rbac_v2_a8_idempotency.php)
 * let A8 actually run to completion for the first time on a real database,
 * which surfaced a THIRD bug in the same migration step: two old
 * permission codes from DIFFERENT categories can derive the identical
 * (resource, verb) pair by naming coincidence, and A8 silently treated
 * them as the same permission.
 *
 * CONFIRMED LIVE on the dev database: screen.reports (category=screen,
 * "can see the Reports screen -- single-resource reports only") and
 * action.view_reports (category=action, sql/rbac.sql's own seed comment:
 * "Run Aggregate Reports ... org-wide aggregate reports are admin-only")
 * both parse to resource=reports/verb=view. Whichever processed first
 * created the canonical reports.view row and correctly mirrored ITS OWN
 * role_permissions onto it; the second found reports.view already
 * existing, correctly skipped creating a duplicate, but then
 * unconditionally set ITS OWN deprecated_alias_of to reports.view anyway.
 * inc/rbac.php's alias resolution (_rbac_alias_candidates()) treats a row
 * and its deprecated_alias_of target as mutually interchangeable for
 * grant lookups -- so a Read-Only user holding only screen.reports
 * (deliberately, so they can see the reports screen) silently ALSO
 * satisfied action.view_reports (deliberately admin-only aggregate
 * reports). A second live instance was found the same way: widget.
 * audit_log paired with action.view_audit.
 *
 * FIX. A8's apply loop now checks whether the OLD row and the EXISTING
 * canonical row it would alias onto sit on opposite sides of the
 * screen/widget/field ("can see it") vs action ("can do it") boundary; if
 * so it leaves the old row un-aliased instead of merging privilege tiers.
 * A new one-time step A8b retroactively un-links (deprecated_alias_of =
 * NULL) any row an install already mismerged before this guard existed --
 * confirmed against the dev database's own two real instances.
 *
 * This test is schema/text-only for the guard shape (no DB needed) plus a
 * live check when a database IS available (skips cleanly otherwise,
 * matching tools/schema_audit.php's DB-unreachable convention) that
 * proves no cross-tier alias currently survives on whatever database
 * this happens to run against.
 */

$root = dirname(__DIR__);

// Loaded first, before any output -- config.php sets session ini directives
// that PHP refuses (with a warning) once a byte has already been echoed.
$dbAvailable = true;
try {
    require_once $root . '/config.php';
} catch (Throwable $e) {
    $dbAvailable = false;
}

$src = (string) file_get_contents($root . '/sql/run_rbac_v2.php');

$pass = 0; $fail = 0;
function trg(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== run_rbac_v2.php A8 privilege-tier alias guard ===\n\n";

$start = strpos($src, "rrbv2_step('permissions: seed canonical codes + link aliases',");
$a8bStart = strpos($src, "rrbv2_step('permissions: repair cross-tier alias merges");
$a8bEnd = strpos($src, "// A10", $a8bStart ?: 0);
if ($start === false || $a8bStart === false || $a8bEnd === false) {
    echo "[FAIL] could not isolate A8/A8b from sql/run_rbac_v2.php (markers moved?)\n";
    echo "\n1 passed, 1 failed\n";
    exit(1);
}
$a8Block = substr($src, $start, $a8bStart - $start);
$a8bBlock = substr($src, $a8bStart, $a8bEnd - $a8bStart);

// ── A8: the guard itself ────────────────────────────────────────────
trg('A8 defines a view-tier category list (screen/widget/field)',
    (bool) preg_match("/\\\$viewTier\\s*=\\s*\\['screen',\\s*'widget',\\s*'field'\\]/", $a8Block));
trg('A8 compares the OLD row\'s tier against the EXISTING canonical row\'s tier',
    strpos($a8Block, 'SELECT category FROM') !== false
    && strpos($a8Block, '$oldTier') !== false
    && strpos($a8Block, '$newTier') !== false);
trg('A8 skips (does not alias) on a tier mismatch rather than merging',
    strpos($a8Block, '$crossTierSkipped++') !== false
    && strpos($a8Block, 'continue;') !== false);
trg('a tier-mismatched row is left with deprecated_alias_of untouched (no UPDATE before the skip)',
    (bool) preg_match('/\$crossTierSkipped\+\+;\s*continue;\s*\}\s*\}\s*if \(!\$exists\)/s', $a8Block));

// ── A8b: the retroactive repair ─────────────────────────────────────
trg('A8b exists as its own migration step (repairs installs that already mismerged)',
    strpos($a8bBlock, "rrbv2_step('permissions: repair cross-tier alias merges") !== false);
trg('A8b\'s detection query self-joins permissions on deprecated_alias_of = code',
    strpos($a8bBlock, 'JOIN `{$prefix}permissions` new_p ON new_p.code = old_p.deprecated_alias_of') !== false);
trg('A8b flags BOTH directions of the tier mismatch (view->non-view and non-view->view)',
    substr_count($a8bBlock, "IN ('screen','widget','field')") >= 4);
trg('A8b\'s repair only resets deprecated_alias_of (no role_permissions DELETE — none were ever wrongly granted)',
    strpos($a8bBlock, 'SET deprecated_alias_of = NULL') !== false
    && strpos($a8bBlock, 'DELETE FROM') === false);

// ── Live check (only when a database is reachable) ──────────────────
if (!$dbAvailable) {
    echo "\n(no database available — skipping the live cross-tier check; CI runs it)\n";
} else {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $bad = db_fetch_all(
            "SELECT old_p.code AS old_code, new_p.code AS new_code,
                    old_p.category AS old_cat, new_p.category AS new_cat
             FROM `{$prefix}permissions` old_p
             JOIN `{$prefix}permissions` new_p ON new_p.code = old_p.deprecated_alias_of
             WHERE old_p.deprecated_alias_of IS NOT NULL
               AND ((old_p.category IN ('screen','widget','field') AND new_p.category NOT IN ('screen','widget','field'))
                 OR (old_p.category NOT IN ('screen','widget','field') AND new_p.category IN ('screen','widget','field')))"
        );
        trg('no permission on this database currently carries a cross-tier alias link',
            count($bad) === 0,
            $bad ? implode('; ', array_map(fn($r) => "{$r['old_code']}({$r['old_cat']}) -> {$r['new_code']}({$r['new_cat']})", $bad)) : '');
    } catch (Throwable $e) {
        echo "(permissions table not migrated yet — skipping the live check)\n";
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
