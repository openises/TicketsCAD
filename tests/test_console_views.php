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
t('console-views API: geometry clamped (inner 12-col, outer 12-col) + colours/mode validated',
    strpos($combined, "preg_match('/^#[0-9a-fA-F]{3,8}\$/'") !== false
    && strpos($combined, "['momentary', 'latch']") !== false
    && strpos($combined, "if (\$out['x'] + \$out['w'] > 12)") !== false
    && strpos($combined, "if (\$layout['x'] + \$layout['w'] > 12)") !== false);
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
$page = (string) @file_get_contents('console-designer.php');
// Phase 114b3: the PAGE gate loosened from console.design to screen.console
// (any operator may reach the page to build their OWN personal views —
// Eric, 2026-08-20). console.design is still checked, but only to decide
// whether the admin-only "Shared Views" panel renders — assert that
// distinction precisely rather than just "the string console.design
// appears somewhere", which would trivially still pass and hide the gate
// having moved.
t('console-designer.php: PAGE gate is screen.console (not console.design — '
    . 'any operator may build a PERSONAL view), gridstack + cache-busted assets present',
    strpos($page, "rbac_can('screen.console')") !== false
    && strpos($page, 'gridstack-all.js') !== false
    && strpos($page, "asset_v('assets/js/console-designer.js')") !== false
    && strpos($page, 'cdPalette') !== false);
t('console-designer.php: console.design still gates whether the SHARED views panel renders',
    strpos($page, '$can_design = rbac_can(\'console.design\')') !== false
    && strpos($page, 'if ($can_design)') !== false
    && strpos($page, 'cdMyViewList') !== false); // the ALWAYS-present personal panel
$djs = (string) @file_get_contents('assets/js/console-designer.js');
t('console-designer.js: ES5 style (no arrows/template literals/let/const)',
    !preg_match('/=>|`|\blet\s|\bconst\s/', $djs));
t('console-designer.js: GridStack outer strips + CUSTOM snap-grid inner drag (no nested GridStack — froze the renderer)',
    substr_count($djs, 'GridStack.init(') === 1
    && strpos($djs, "handle: '.cds-handle'") !== false
    && strpos($djs, 'function placeComp') !== false
    && strpos($djs, 'cd-comp-resize') !== false
    && strpos($djs, "addEventListener('mousemove'") !== false);
t('console-designer.js: palette + per-component inspector + publish serialization',
    strpos($djs, 'renderPalette') !== false
    && strpos($djs, 'gridstackNode') !== false
    && strpos($djs, "action: 'save_strips'") !== false
    && strpos($djs, 'components: comps') !== false);
$cjs = (string) @file_get_contents('assets/js/console.js');
t('console.js: tabs render designer views + All Channels fallback',
    strpos($cjs, 'consoleTabs') !== false
    && strpos($cjs, "'All Channels'") !== false
    && strpos($cjs, 'api/console-views.php') !== false
    && strpos($cjs, 'newui_console_active_view') !== false);
t('console.js: positioned renderer (abs strips + components, matching grid math)',
    strpos($cjs, 'renderPositionedStrip') !== false
    && strpos($cjs, 'renderComponent') !== false
    && strpos($cjs, 'OUTER_CELL = 20') !== false
    && strpos($cjs, 'INNER_CELL = 14') !== false
    && strpos($cjs, 'console-bank-abs') !== false);
t("console.js: the ONE remaining future component ('say' — no TTS backend yet) still "
    . 'renders disabled with an honest tooltip; disabled channels fail soft',
    strpos($cjs, 'ccp-future-rt') !== false
    && strpos($cjs, 'no backend yet') !== false
    && strpos($cjs, "'Channel disabled'") !== false);
t('console.js: Phase 114b3 — monitor/mute/volume are REAL interactive controls now '
    . '(wired to window.ConsoleAudio), not the old disabled future placeholder',
    strpos($cjs, "comp.type === 'monitor'") !== false
    && strpos($cjs, "comp.type === 'mute'") !== false
    && strpos($cjs, "comp.type === 'volume'") !== false
    && strpos($cjs, 'window.ConsoleAudio.setMon(') !== false
    && strpos($cjs, 'window.ConsoleAudio.setMuted(') !== false
    && strpos($cjs, 'window.ConsoleAudio.setVolume(') !== false);
t('console.js: Select + Simulselect are universal strip chrome, present on every strip '
    . 'in both the auto and positioned (designer) renderers',
    strpos($cjs, 'function buildSelectChrome(') !== false
    && strpos($cjs, 'buildSelectChrome(ch)') !== false
    && substr_count($cjs, 'buildSelectChrome(') >= 3); // definition + renderStrip + renderPositionedStrip
$cpage = (string) @file_get_contents('console.php');
t('console.php: tab bar + Design Views link for console.design holders',
    strpos($cpage, 'consoleTabs') !== false
    && strpos($cpage, 'console-designer.php') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
