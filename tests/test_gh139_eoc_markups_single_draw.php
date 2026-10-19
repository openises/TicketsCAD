<?php
/**
 * GH#139 (reopened 2026-09-12, a beta tester) — "Users have to turn overlays off
 * twice in two separate areas for them to stop displaying" on the EOC
 * display (situation.php).
 *
 * THE BUG: situation.php drew every saved map markup TWICE.
 *   1. initMap() hands the layer control to MapPrefs.addMarkupOverlays()
 *      (assets/js/map-prefs.js), which draws each markup into its
 *      category's group ("Region Boundary", "Hazards", ... and
 *      "Uncategorised markups") — the checkboxes in the top-right layer
 *      control.
 *   2. The "Map Draw Controls & Markups" script further down kept a layer
 *      of its own (markupGroup), always on the map and in no control, and
 *      drew EVERY visible markup into it again for its "Saved Markups"
 *      panel.
 * So unticking a category in the layer control left the second copy on the
 * map; the only way to remove that copy was the panel's checkbox — which
 * does not hide it for you at all: it flips mmarkup.line_status, hiding the
 * markup on every map for every user (and for a role without
 * action.manage_map the API refused it, so that copy could not be removed
 * at all). The dashboard (index.php, "Situation" in the help page) draws
 * each markup once, which is why the reporter saw it resolved there.
 * Separately, a category that is on by default came back on at every
 * reload however often it was turned off, because only "turned on" choices
 * were remembered.
 *
 * THE FIX (the shape the reporter asked for — one control shows and hides,
 * the other edits):
 *   - the layer control's category groups are the only drawing of markups
 *     on the EOC display and the only place to show or hide them;
 *   - the panel ("Edit Markups") draws nothing and has no checkboxes: it
 *     renames, hides/shows for everyone (a labelled button) and deletes,
 *     then asks the layer-control groups to redraw
 *     (MapPrefs.getMarkupOverlays(map).reload());
 *   - a newly drawn markup switches its group on so the user sees it;
 *   - "turned off" is remembered as well as "turned on";
 *   - api/map-markups.php toggle_visibility sets the value it is sent
 *     (it used to flip whatever was stored) and writes an audit row.
 *
 * Part 1 — static: the pieces the behaviour rests on are present, and the
 *          shipped browser scripts stay ES5.
 * Part 2 — behaviour, in jsdom (needs Node + jsdom; CI installs jsdom):
 *          tests/_gh139_eoc_markups_harness.cjs runs the REAL Leaflet, the
 *          REAL map-prefs.js and the REAL code taken out of situation.php,
 *          and counts how many times each markup is drawn on the map.
 * Part 3 — the REAL api/map-markups.php toggle_visibility (CLI probe, no
 *          web server): explicit value, repeat-safe, old callers still
 *          flip, audit row written.
 *
 * Usage: php tests/test_gh139_eoc_markups_single_draw.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';

$root = realpath(__DIR__ . '/..');
$pass = 0; $fail = 0; $skipped = '';
function gh139_t(string $label, bool $cond, string $why = ''): void {
    global $pass, $fail;
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . (!$cond && $why !== '' ? ' — ' . $why : '') . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#139 — EOC display draws each markup once; one place to show/hide ===\n\n";

// ── Part 1: static ─────────────────────────────────────────────────────────
echo "--- Part 1: static ---\n\n";
$sit   = (string) file_get_contents($root . '/situation.php');
$prefs = (string) file_get_contents($root . '/assets/js/map-prefs.js');

gh139_t('map-prefs.js exposes MapPrefs.getMarkupOverlays()', strpos($prefs, 'getMarkupOverlays: function (mapInstance)') !== false);
gh139_t('MapPrefs.addMarkupOverlays() returns the controller', (bool) preg_match('/addMarkupOverlays: function \(mapInstance, ctl, opts\) \{\s*return attachMarkupOverlays\(/', $prefs));
gh139_t('situation.php starts "Uncategorised markups" on (markups drawn there are uncategorised)',
    strpos($sit, 'addMarkupOverlays(map, sitLayersControl, { uncategorisedDefaultVisible: true })') !== false);
gh139_t('the draw toolbar and edit panel are rendered only for a user with action.manage_map',
    (bool) preg_match('/<!-- Draw Toolbar -->\s*<\?php if \(\$sitCanManageMap\): \?>\s*<div id="drawToolbar">[\s\S]*?<div id="markupsPanel">[\s\S]*?<\?php endif; \?>/', $sit)
    && strpos($sit, "\$sitCanManageMap = rbac_can('action.manage_map');") !== false);

$drawJs = '';
if (preg_match('/<!-- Map Draw Controls & Markups -->\s*<script>([\s\S]*?)<\/script>/', $sit, $m)) { $drawJs = $m[1]; }
gh139_t('situation.php still has the draw-tools script', $drawJs !== '');
gh139_t('the draw-tools script keeps no markup layer of its own (no markupGroup)', $drawJs !== '' && strpos($drawJs, 'markupGroup') === false);
// ES5 only in shipped browser JS (no arrow functions, let/const, template literals).
$es5 = function (string $src): bool {
    $noStrings = preg_replace(["/'(?:[^'\\\\\n]|\\\\.)*'/", '/"(?:[^"\\\\\n]|\\\\.)*"/', '#//[^\n]*#', '#/\*[\s\S]*?\*/#'], "''", $src);
    return !preg_match('/=>|\blet\s|\bconst\s|`/', (string) $noStrings);
};
gh139_t('map-prefs.js is still ES5', $es5($prefs));
gh139_t('the situation.php draw-tools script is still ES5', $drawJs !== '' && $es5((string) preg_replace('/<\?php[\s\S]*?\?>/', 'true', $drawJs)));

// ── Part 2: behaviour in jsdom ─────────────────────────────────────────────
echo "\n--- Part 2: behaviour (real Leaflet + real map-prefs.js + real situation.php code, in jsdom) ---\n\n";
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
$node = $node === '' ? '' : (string) strtok($node, "\r\n");
$jsdomOk = false;
if ($node !== '') {
    $probe = @shell_exec('"' . $node . '" -e "require(\'jsdom\');console.log(\'ok\')" 2>&1');
    $jsdomOk = is_string($probe) && strpos($probe, 'ok') !== false;
}
if (!$jsdomOk) {
    $skipped = 'Part 2 needs Node + the jsdom package (npm install jsdom); CI installs it';
    echo "SKIP: {$skipped}\n";
} else {
    $errFile = tempnam(sys_get_temp_dir(), 'gh139err');
    $proc = proc_open([$node, __DIR__ . '/_gh139_eoc_markups_harness.cjs', $root],
        [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $raw = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
    if (is_resource($proc)) { fclose($pipes[1]); proc_close($proc); }
    $run = json_decode(trim((string) $raw), true);
    if (!is_array($run)) {
        echo "harness output was not JSON:\n" . substr((string) $raw, 0, 800) . "\n" . substr((string) @file_get_contents($errFile), 0, 800) . "\n";
    }
    @unlink($errFile);
    gh139_t('the jsdom harness ran to the end with no exception'
        . (!empty($run['errors']) ? ' - ' . substr((string) $run['errors'][0], 0, 300) : ''),
        is_array($run) && empty($run['errors']));
    $checks = is_array($run) ? ($run['checks'] ?? []) : [];
    $expected = [
        'zone_drawn_once'                              => 'a categorised markup is drawn ONCE on the EOC map (was twice)',
        'eoc_marker_drawn_once'                        => 'an uncategorised markup (as drawn on the EOC) is drawn once, and shown by default there',
        'layer_control_lists_category_and_uncategorised' => 'the layer control lists the category and "Uncategorised markups"',
        'uncategorised_on_by_default_here'             => '"Uncategorised markups" starts ticked on the EOC display',
        'category_off_removes_it_from_map'             => 'unticking the category in the layer control removes the markup from the map entirely',
        'uncategorised_off_removes_it_from_map'        => 'unticking "Uncategorised markups" removes the uncategorised markup entirely',
        'category_back_on_draws_once'                  => 'ticking it again draws it once',
        'panel_opens'                                  => 'the Edit Markups panel opens from the toolbar',
        'panel_has_no_visibility_checkboxes'           => 'the panel has no show/hide checkboxes of its own',
        'panel_lists_markups'                          => 'the panel lists the saved markups',
        'panel_markup_name_and_colour_stay_text'       => 'STORED XSS: a markup name/colour with quotes and tags renders as text in the panel (no attribute break-out)',
        'turned_off_category_stays_off_after_reload'   => 'a category on by default that the user turned off stays off after a reload',
        'turned_off_uncategorised_stays_off_after_reload' => '"Uncategorised markups" turned off stays off after a reload',
        'new_markup_saved'                             => 'drawing a marker with the toolbar saves it',
        'new_markup_drawn_once'                        => '...draws it once',
        'new_markup_group_switched_on'                 => '...and switches its group on so the user sees what they drew',
        'category_on_again_draws_once'                 => 'turning the category back on after a reload draws the markup once',
        'panel_has_hide_for_everyone_button'           => 'the panel offers "hide on every map" as a labelled button',
        'hide_sends_explicit_visible_0'                => 'hiding sends visible: 0 (an explicit value, not a flip)',
        'hidden_markup_removed_from_map'               => 'a hidden markup disappears from the map',
        'hidden_markup_marked_in_panel'                => 'the panel marks it "hidden for everyone"',
        'shown_again_drawn_once'                       => 'showing it again draws it once',
        'renamed_markup_drawn_once'                    => 'a renamed markup is redrawn once under its new name',
        'deleted_markup_removed_from_map'              => 'a deleted markup disappears from the map',
        'zone_still_drawn_once_after_edits'            => 'after all the edits the other markup is still drawn exactly once',
    ];
    foreach ($expected as $key => $label) {
        gh139_t($label, ($checks[$key] ?? null) === true,
            isset($run['notes']) ? 'drawn counts ' . json_encode($run['notes']) : '');
    }
}

// ── Part 3: api/map-markups.php toggle_visibility ─────────────────────────
echo "\n--- Part 3: api/map-markups.php toggle_visibility (real endpoint, CLI probe) ---\n\n";
$prefix = $GLOBALS['db_prefix'] ?? '';
$haveDb = false;
try { db_fetch_value("SELECT COUNT(*) FROM `{$prefix}mmarkup`"); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    $skipped .= ($skipped !== '' ? '; ' : '') . 'Part 3 needs the mmarkup table';
    echo "SKIP: Part 3 needs a database with the mmarkup table\n";
} else {
    $php = PHP_BINARY;
    $admin = test_admin_user_id();
    $probe = function (array $body) use ($php, $admin): array {
        $proc = proc_open([$php, __DIR__ . '/_p155_endpoint_probe.php', 'session', 'api/map-markups.php', (string) $admin, 'POST', json_encode($body)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        $outRaw = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($proc);
        $env = json_decode(trim((string) $outRaw), true);
        $json = is_array($env) ? json_decode((string) ($env['body'] ?? ''), true) : null;
        return ['http' => is_array($env) ? (int) ($env['http'] ?? 0) : 0, 'json' => is_array($json) ? $json : []];
    };
    $status = function (int $id) use ($prefix) {
        return (int) db_fetch_value("SELECT line_status FROM `{$prefix}mmarkup` WHERE id = ?", [$id]);
    };
    $auditFloor = (int) db_fetch_value("SELECT COALESCE(MAX(id), 0) FROM `{$prefix}newui_audit_log`");
    $id = 0;
    try {
        $r = $probe(['action' => 'save', 'name' => 'GH139 API fixture', 'type' => 'M',
            'coordinates' => json_encode([[44.97, -93.26]]), 'visible' => 1, 'category_id' => 0]);
        $id = (int) ($r['json']['id'] ?? 0);
        gh139_t('fixture: a markup created through the real endpoint', $id > 0, json_encode($r));
        if ($id > 0) {
            $r = $probe(['action' => 'toggle_visibility', 'id' => $id, 'visible' => 0]);
            gh139_t('visible: 0 hides it', $status($id) === 0 && ($r['json']['visible'] ?? null) === 0, json_encode($r));
            $r = $probe(['action' => 'toggle_visibility', 'id' => $id, 'visible' => 0]);
            gh139_t('visible: 0 sent again leaves it hidden (it used to flip it back on)', $status($id) === 0, json_encode($r));
            $r = $probe(['action' => 'toggle_visibility', 'id' => $id, 'visible' => 1]);
            gh139_t('visible: 1 shows it', $status($id) === 1 && ($r['json']['visible'] ?? null) === 1, json_encode($r));
            $r = $probe(['action' => 'toggle_visibility', 'id' => $id]);
            gh139_t('without visible it still flips (older callers)', $status($id) === 0, json_encode($r));
            $r = $probe(['action' => 'toggle_visibility', 'id' => 999999999, 'visible' => 1]);
            gh139_t('an unknown markup id answers 404', $r['http'] === 404, json_encode($r));
            $audits = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE id > ? AND target_type = 'markup' AND target_id = ? AND summary LIKE '%on all maps%'",
                [$auditFloor, (string) $id]);
            gh139_t('every hide/show writes an audit row (4 changes, 4 rows)', $audits === 4, "found {$audits}");
        }
    } finally {
        if ($id > 0) {
            try { db_query("DELETE FROM `{$prefix}mmarkup` WHERE id = ?", [$id]); } catch (Throwable $e) {}
            try {
                db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE id > ? AND target_type = 'markup' AND target_id = ?", [$auditFloor, (string) $id]);
            } catch (Throwable $e) {}
        }
    }
}

if ($skipped !== '') { echo "\nSKIP: {$skipped}\n"; }
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
