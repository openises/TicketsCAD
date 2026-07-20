<?php
/**
 * Phase 118 (#89) — configurable list pagination.
 *
 * Verifies the admin-configurable page-size setting end to end at the layers a
 * PHP test can reach: the settings round-trip + seed idempotency (live DB), and
 * the wiring on the UI/endpoint/JS boundaries (static-source, the same approach
 * as test_chat_csrf_and_rbac.php). The client-side slice/clamp math is covered
 * separately by a Node check.
 *
 * Self-skips on a DB it can't reach.
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
if (!function_exists('get_variable')) require_once __DIR__ . '/../inc/functions.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$tests = 0; $fails = 0;
function ok($label, $cond) { global $tests, $fails; $tests++; if ($cond) { echo "  [PASS] $label\n"; } else { $fails++; echo "  [FAIL] $label\n"; } }

echo "=== Phase 118 configurable pagination ===\n\n";

// Reachable DB?
try { db_fetch_value('SELECT 1'); }
catch (Throwable $e) { echo "SKIP: database unavailable.\n\n=== Results: 0 passed, 0 failed ===\n"; exit(0); }

$root = __DIR__ . '/..';

// ── Setting round-trip + seed (live DB) ──────────────────────────
try {
    // Seed default (idempotent) then confirm the row exists.
    db_query("INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES ('page_size', '50')");
    $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'page_size'");
    ok("page_size row exists after seed (value='{$v}')", $v !== null && (int)$v > 0);

    // Idempotent: a second INSERT IGNORE must not change an existing value.
    db_query("UPDATE `{$prefix}settings` SET `value` = '37' WHERE `name` = 'page_size'");
    db_query("INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES ('page_size', '50')");
    $v2 = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'page_size'");
    ok('seed is idempotent — did not clobber admin value 37', (int)$v2 === 37);

    // get_variable reads it (fresh process cache).
    ok('get_variable(page_size) reflects the stored value', (int) get_variable('page_size') === 37);

    // restore default
    db_query("UPDATE `{$prefix}settings` SET `value` = '50' WHERE `name` = 'page_size'");
} catch (Throwable $e) {
    ok('setting round-trip threw: ' . $e->getMessage(), false);
}

// ── Validation rule (mirrors api/config-admin.php) ───────────────
foreach ([['37', true], ['200', true], ['1', true], ['0', false], ['-5', false], ['abc', false], ['', false]] as $case) {
    list($in, $expect) = $case;
    $accepted = ((int) $in) >= 1;
    ok("validate '{$in}' -> " . ($expect ? 'accept' : 'reject'), $accepted === $expect);
}

// ── Wiring (static-source) ───────────────────────────────────────
$cfg = file_get_contents("$root/api/config-admin.php");
ok('config-admin.php validates page_size as a positive integer',
    strpos($cfg, "\$key === 'page_size'") !== false && strpos($cfg, 'positive integer') !== false);

$nav = file_get_contents("$root/inc/navbar.php");
ok('navbar.php injects window.LIST_PAGE_SIZE from settings.page_size',
    strpos($nav, 'window.LIST_PAGE_SIZE') !== false && strpos($nav, "get_variable('page_size')") !== false);

$set = file_get_contents("$root/settings.php");
ok('settings.php page_size is a free number input (not a preset select)',
    strpos($set, 'id="setPageSize"') !== false
    && strpos($set, 'type="number"') !== false
    && preg_match('/<select[^>]*id="setPageSize"/', $set) === 0);

$uphp = file_get_contents("$root/units.php");
ok('units.php has the pagination footer container', strpos($uphp, 'id="unitsPager"') !== false
    && strpos($uphp, 'id="unitsPageNav"') !== false);

$ujs = file_get_contents("$root/assets/js/units.js");
ok('units.js consumes LIST_PAGE_SIZE + renders the pager',
    strpos($ujs, 'window.LIST_PAGE_SIZE') !== false
    && strpos($ujs, 'function renderPager') !== false
    && strpos($ujs, 'filtered.slice(') !== false);

echo "\n=== Results: " . ($tests - $fails) . " passed, $fails failed ===\n";
exit($fails === 0 ? 0 : 1);
