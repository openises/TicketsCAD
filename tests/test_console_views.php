<?php
/**
 * Phase 114b-b2 (+ b3 wiring guards) — console views (designer) tests
 *
 * Schema + DB-level behavior + wiring guards. The full HTTP flow
 * (create/publish/delete + RBAC 403s) is exercised by the authenticated
 * smoke script run at build time; these tests pin what CI can check
 * without a webserver.
 *
 * Phase 114b3 (2026-08-20) moved the validation/business logic these
 * guards check out of api/console-views.php into inc/console-views.php
 * (so it can be driven directly, without HTTP — see tests/test_console_
 * personal_layouts.php and tests/test_console_personal_layouts_rbac.php,
 * which are the REAL functional coverage for personal/shareable layouts
 * and the RBAC boundary). The wiring-guard assertions below were updated
 * to check the combined api+inc source rather than api/ alone, and to
 * reflect that monitor/mute/volume are real controls now (only 'say'
 * stays an honest future placeholder) — see console-designer.md's status
 * line for the full b3 summary.
 *
 * Usage: php tests/test_console_views.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/channel_registry.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 114b console views ===\n\n";

// ── Schema ───────────────────────────────────────────────────────────────
foreach (['console_views', 'console_view_strips'] as $tbl) {
    $ok = false;
    try { db_query("SELECT 1 FROM `{$prefix}$tbl` LIMIT 1"); $ok = true; } catch (Exception $e) {}
    t("table $tbl exists", $ok);
}
t('console_views has the b3-reserved columns (owner/rbac/default)', (function () use ($prefix) {
    $cols = [];
    foreach (db_fetch_all("SHOW COLUMNS FROM `{$prefix}console_views`") as $c) { $cols[] = $c['Field']; }
    return in_array('owner_user_id', $cols, true)
        && in_array('rbac_json', $cols, true)
        && in_array('is_default_for_json', $cols, true)
        && in_array('based_on_view_id', $cols, true);
})());
t('console_view_strips has layout_json (b2.5 free-form)', (function () use ($prefix) {
    foreach (db_fetch_all("SHOW COLUMNS FROM `{$prefix}console_view_strips`") as $c) {
        if ($c['Field'] === 'layout_json') { return true; }
    }
    return false;
})());

// ── DB round-trip: view + positioned strip ───────────────────────────────
db_query("DELETE FROM `{$prefix}console_views` WHERE name = '_test114b_'");
db_query("INSERT INTO `{$prefix}console_views` (name, icon, owner_user_id, sort_order) VALUES ('_test114b_', 'bi-lightning', NULL, 999)");
$vid = db_insert_id();
$lc = channel_get('broker:local_chat');
t('fixture channel broker:local_chat available', (bool) $lc);
if ($lc) {
    $comps = [
        ['type' => 'label', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 3, 'props' => ['text' => 'Zello', 'bg' => '#d9c7ee']],
        ['type' => 'text', 'x' => 0, 'y' => 3, 'w' => 12, 'h' => 8],
    ];
    db_query(
        "INSERT INTO `{$prefix}console_view_strips`
            (view_id, channel_id, position, width, layout_json, overrides_json, controls_json)
         VALUES (?, ?, 0, 2, ?, ?, ?)",
        [$vid, $lc['id'], json_encode(['x' => 3, 'y' => 0, 'w' => 6, 'h' => 20]),
         json_encode(['label' => 'Ops Chat', 'color' => '#3366ff']), json_encode($comps)]
    );
    $row = db_fetch_one(
        "SELECT * FROM `{$prefix}console_view_strips` WHERE view_id = ? ORDER BY position", [$vid]
    );
    $ov  = json_decode($row['overrides_json'], true);
    $lay = json_decode($row['layout_json'], true);
    $cc  = json_decode($row['controls_json'], true);
    t('strip round-trips overrides + layout rectangle + positioned components',
        $ov['label'] === 'Ops Chat' && $lay === ['x' => 3, 'y' => 0, 'w' => 6, 'h' => 20]
        && $cc[0]['type'] === 'label' && $cc[0]['props']['bg'] === '#d9c7ee'
        && $cc[1]['type'] === 'text' && (int) $cc[1]['h'] === 8);
}
db_query("DELETE FROM `{$prefix}console_view_strips` WHERE view_id = ?", [$vid]);
db_query("DELETE FROM `{$prefix}console_views` WHERE id = ?", [$vid]);

// ── API wiring guards ────────────────────────────────────────────────────
// Phase 114b3: the validation/business logic that used to live inline in
// api/console-views.php was extracted into inc/console-views.php so it can
// be driven directly by tests without HTTP (see tests/test_console_
// personal_layouts.php, which does exactly that) — the established
// pattern this codebase uses for every admin-CRUD-UI endpoint (org-
// routing, public-board, ics-form-types, ...). api/console-views.php is
// now a thin HTTP dispatcher. These guards check the COMBINED content of
// both files, since the property being proven ("this validation exists
// somewhere in the console-views feature") doesn't care which file it
// lives in — only test_console_personal_layouts.php's own wiring-guard
// section cares about the SPECIFIC split (console_view_can_write() being
// called from api/, not re-derived inline).
$api = (string) @file_get_contents('api/console-views.php');
$incSrc = (string) @file_get_contents('inc/console-views.php');
$combined = $api . "\n" . $incSrc;
t('console-views API: auth + RBAC gates (read=console, write=design-for-shared) + CSRF',
    strpos($api, "require_once __DIR__ . '/auth.php'") !== false
    && strpos($api, "rbac_can('screen.console')") !== false
    && strpos($api, "rbac_can('console.design')") !== false
    && strpos($api, 'csrf_verify(') !== false
    && strpos($api, "ini_set('display_errors', '0')") !== false);
t('console-views API: components validated against channel capabilities',
    strpos($combined, 'console_component_clean(') !== false
    && strpos($combined, 'console_component_allowed(') !== false
    && strpos($combined, "capabilities']") !== false);
t('console-views API: component catalog covers Eric\'s sketch set '
    . '(monitor/mute/volume are REAL now, Phase 114b3 — only \'say\' stays future)',
    strpos($incSrc, "'label'") !== false && strpos($incSrc, "'led'") !== false
    && strpos($incSrc, "'ptt'") !== false && strpos($incSrc, "'monitor'") !== false
    && strpos($incSrc, "'mute'") !== false && strpos($incSrc, "'volume'") !== false
    && substr_count($incSrc, "'future' => false") >= 8
    && substr_count($incSrc, "'future' => true") === 1);
// Phase 152 (Console rebuild): console_view_save_strips() no longer takes
// a pixel layout rectangle or a positioned-component array at all -- the
// outer-rectangle x+w<=12 clamp this guard used to check for is genuinely
// GONE (there is no more outer rectangle; a strip's only geometry is
// width 1|2). The INNER per-component geometry/mode clamp
// (console_component_clean(), x+w<=12 / momentary|latch) is retained,
// unused by the live save path, purely for console_components_default()'s
// migration/fallback rendering -- see that function's own doc comment.
// This guard now checks the REPLACEMENT validation: width clamped to
// {1,2}, hotkey format + per-view uniqueness, show-flags capability-gated
// via console_strip_show_allowed(), and the overrides.color check (the
// one piece of the old validation that genuinely carried over untouched).
t('console-views API: strip-template validation (width clamp, hotkey format+uniqueness, '
    . 'capability-gated show flags) replaces the retired free-form geometry/mode validation',
    strpos($combined, 'function console_strip_template_clean(') !== false
    && strpos($combined, "((int) (\$s['width'] ?? 1) === 2) ? 2 : 1") !== false
    && strpos($combined, "preg_match('/^(F[1-9]|F1[0-2]|[A-Za-z0-9])\$/'") !== false
    && strpos($combined, 'function console_strip_show_allowed(') !== false
    && strpos($combined, "preg_match('/^#[0-9a-fA-F]{3,8}\$/'") !== false
    && strpos($incSrc, '$seenHotkeys') !== false);
t('console-views API: legacy flat control lists converted at read time',
    strpos($combined, 'console_components_default(') !== false
    && strpos($combined, 'is_string($decoded[0]') !== false);
t('console-views API: shared-view scoping (owner_user_id IS NULL) + audit',
    substr_count($combined, 'owner_user_id IS NULL') >= 4
    && substr_count($api, 'audit_log(') >= 4);
t('console-views API: Phase 114b3 personal-view scoping — the RBAC boundary is a pure '
    . 'function (console_view_can_write, driven directly by tests/test_console_personal_'
    . 'layouts.php) rather than re-derived inline in the API dispatcher',
    strpos($incSrc, 'function console_view_can_write(') !== false
    && strpos($api, 'console_view_can_write(') !== false
    && strpos($incSrc, 'function console_view_visible_as_clone_source(') !== false
    && strpos($incSrc, 'is_shared') !== false);

// ── Designer page + runtime wiring ───────────────────────────────────────
// Phase 152 (Console rebuild) retired the GridStack/free-drag canvas
// entirely (5-persona review, unanimous rejection of free-drag at the
// strip level) — the detailed coverage of that rebuild (no-canvas, no-
// GridStack, launcher-strip distinction, hotkeys, the client/server
// capability-gate sync) lives in its own dedicated file,
// tests/test_phase152_console_strip_templates.php. These guards are
// trimmed to what's still true post-rebuild rather than re-describing
// retired architecture.
$page = (string) @file_get_contents('console-designer.php');
// The PAGE gate loosened from console.design to screen.console (any
// operator may reach the page to build their OWN personal views — Eric,
// 2026-08-20). console.design is still checked, but only to decide
// whether the admin-only "Shared Views" panel renders — assert that
// distinction precisely rather than just "the string console.design
// appears somewhere", which would trivially still pass and hide the gate
// having moved.
t('console-designer.php: PAGE gate is screen.console (not console.design — '
    . 'any operator may build a PERSONAL view), cache-busted assets present',
    strpos($page, "rbac_can('screen.console')") !== false
    && strpos($page, "asset_v('assets/js/console-designer.js')") !== false
    && strpos($page, 'cdStripList') !== false);
t('console-designer.php: console.design still gates whether the SHARED views panel renders',
    strpos($page, '$can_design = rbac_can(\'console.design\')') !== false
    && strpos($page, 'if ($can_design)') !== false
    && strpos($page, 'cdMyViewList') !== false); // the ALWAYS-present personal panel
$djs = (string) @file_get_contents('assets/js/console-designer.js');
t('console-designer.js: ES5 style (no arrows/template literals/let/const)',
    !preg_match('/=>|`|\blet\s|\bconst\s/', $djs));
$cjs = (string) @file_get_contents('assets/js/console.js');
t('console.js: tabs render designer views + All Channels fallback',
    strpos($cjs, 'consoleTabs') !== false
    && strpos($cjs, "'All Channels'") !== false
    && strpos($cjs, 'api/console-views.php') !== false
    && strpos($cjs, 'newui_console_active_view') !== false);
t('console.js: Select is real, wired chrome — buildSelectChrome() is called from '
    . 'the one strip renderer (renderStrip())',
    strpos($cjs, 'function buildSelectChrome(') !== false
    && strpos($cjs, 'buildSelectChrome(ch)') !== false);
$cpage = (string) @file_get_contents('console.php');
t('console.php: tab bar + Design Views link for console.design holders',
    strpos($cpage, 'consoleTabs') !== false
    && strpos($cpage, 'console-designer.php') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
