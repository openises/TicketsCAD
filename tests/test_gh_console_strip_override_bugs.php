<?php
/**
 * Two real console-strip-override bugs, both found live by Eric against
 * your-server on 2026-09-08 while trying "Strip accent color" on a
 * DMR talkgroup strip in the Console Designer:
 *
 * 1. console_view_save_strips()'s $overrideKeys whitelist ('label',
 *    'short_label', 'color') never included 'ptt_color' -- so the
 *    Designer's "PTT button color" picker (console-designer.js's
 *    colorRow('PTT button color', s.overrides.ptt_color, ...)) and
 *    console.js's renderStrip() (which already reads ov.ptt_color to paint
 *    the PTT button) both fully supported it client-side, but every save
 *    silently dropped the value before it ever reached the database. Fixed
 *    by adding 'ptt_color' to the whitelist with the same hex validation
 *    'color' already had.
 *
 * 2. This is NOT the bug Eric actually hit for "Strip accent color" itself
 *    -- that value WAS saving and serving correctly (confirmed live via
 *    the Browser pane against the real DispatchDefault view on your deployment-
 *    auxcomm: api/console-views.php genuinely returned
 *    overrides:{color:"#1829f8"}). The real cause was in assets/js/
 *    console.js: a first-time (or localStorage-cleared) operator lands on
 *    the "All Channels" auto-generated view by default, which has NO
 *    per-strip override concept at all -- so a color set on a named shared
 *    view is invisible until the operator happens to click over to that
 *    tab by hand. That fix (defaulting to the first shared view when there
 *    is no genuinely-stored tab preference) is pure client-side JS logic
 *    with no PHP surface to test here; see the docblock at the top of
 *    assets/js/console.js's loadViews() for the reasoning, and this
 *    file's own structural assertion below that the fix's specific
 *    behavior is present in the shipped source.
 *
 * Usage: php tests/test_gh_console_strip_override_bugs.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/channel_registry.php';
require_once 'inc/console-views.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c, $hint = '') { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $passed++ : $failed++; }

echo "=== GH console strip override bugs (ptt_color whitelist + default-tab source guard) ===\n\n";

channel_registry_sync();
$ch = db_fetch_one("SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = 'eventbus:main' LIMIT 1");

$createdViewIds = [];
register_shutdown_function(function () use (&$createdViewIds) {
    foreach ($createdViewIds as $id) { console_view_delete($id); }
});

if (!$ch) {
    echo "SKIP: eventbus:main channel not found -- run channel_registry_sync() first\n";
} else {
    $r = console_view_create(['name' => '_test_ptt_color', 'icon' => '', 'ownerUserId' => null, 'createdBy' => 900001520]);
    t('console_view_create: shared test view created', $r['ok'] === true && $r['id'] > 0);
    $viewId = $r['id'];
    $createdViewIds[] = $viewId;

    // ── 1. ptt_color now persists (the confirmed, fixed bug) ──────────────
    $r = console_view_save_strips($viewId, [[
        'channel_id' => $ch['id'],
        'overrides'  => ['color' => '#1829f8', 'ptt_color' => '#dc3545'],
        'show'       => [],
        'hotkey'     => null,
        'width'      => 1,
    ]]);
    t('save_strips accepts a valid ptt_color alongside color', $r['ok'] === true, json_encode($r));

    $rows = db_fetch_all(
        "SELECT overrides_json FROM `{$prefix}console_view_strips` WHERE view_id = ?",
        [$viewId]
    );
    t('exactly one strip row was written', count($rows) === 1);
    $stored = $rows ? (json_decode((string) $rows[0]['overrides_json'], true) ?: []) : [];
    t('color round-trips through raw storage', ($stored['color'] ?? null) === '#1829f8');
    t('ptt_color round-trips through raw storage (was silently dropped before this fix)',
        ($stored['ptt_color'] ?? null) === '#dc3545');

    // Round-trip through the same read path console.js actually consumes
    // (console_shared_views() -> console_view_attach_strips()), not just
    // the raw table -- this is what proves a real operator's browser would
    // have received it, matching this project's "reproduce through the
    // real read/write path" discipline.
    $shared = console_shared_views();
    $found = null;
    foreach ($shared as $v) {
        if ($v['id'] !== $viewId) { continue; }
        foreach ($v['strips'] as $s) {
            if ((int) $s['channel_id'] === (int) $ch['id']) { $found = $s; }
        }
    }
    t('the strip is found via console_shared_views() (the API-serving read path)', $found !== null);
    if ($found !== null) {
        t('console_shared_views() -> overrides.ptt_color survives the full read path',
            ($found['overrides']['ptt_color'] ?? null) === '#dc3545');
    }

    // ── 2. Validation matches 'color's existing rule ──────────────────────
    $r = console_view_save_strips($viewId, [[
        'channel_id' => $ch['id'],
        'overrides'  => ['ptt_color' => 'not-a-color'],
        'show'       => [],
        'hotkey'     => null,
        'width'      => 1,
    ]]);
    t('an invalid ptt_color value is rejected, same as an invalid color value', $r['ok'] === false);
}

// ── 3. Structural guard: the default-active-view fix is actually shipped ──
// (assets/js/console.js has no PHP-testable surface for this, so this pins
// the specific mechanism by source rather than leaving it entirely
// unguarded -- a future refactor that silently reverts it should fail here.)
$consoleJsSrc = (string) @file_get_contents('assets/js/console.js');
t('console.js tracks whether the operator ever explicitly chose a tab (hadStoredView)',
    strpos($consoleJsSrc, 'hadStoredView') !== false);
t('console.js defaults to the first shared view (not "All Channels") when nothing was ever stored',
    preg_match('/!hadStoredView\s*&&\s*activeView\s*===\s*[\'"]auto[\'"]\s*&&\s*views\.length/', $consoleJsSrc) === 1);

// ── 4. Two other live-reported fixes from the same session, pinned by source ──
$designerJsSrc = (string) @file_get_contents('assets/js/console-designer.js');
t('console-designer.js Publish View gives success feedback, not silence',
    strpos($designerJsSrc, "showToast('success', 'View published.')") !== false);
t('console-designer.js inspector labels use American spelling ("color"), not "colour"',
    strpos($designerJsSrc, 'colour') === false);

// Superseded 2026-09-08, same session: zello-widget.css (along with the
// widget's template/JS/proxy-port meta tag) moved out of console.php
// entirely and into inc/navbar.php -- see test_gh137_zello_proxy_port_
// meta.php for the full current-architecture coverage of that. The
// original bug this assertion pinned (console.php forgetting the CSS
// link) can no longer recur the OLD way since console.php doesn't own
// this asset at all any more; this assertion now checks the thing that
// actually matters going forward -- that console.php still pulls in the
// one file that supplies it.
$consolePhpSrc = (string) @file_get_contents('console.php');
t('console.php still includes inc/navbar.php (which now supplies the Zello widget)',
    strpos($consolePhpSrc, 'inc/navbar.php') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
