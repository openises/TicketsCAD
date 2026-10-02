<?php
/**
 * Phase 152 (Console rebuild) — frontend wiring guards for the collapsed
 * strip-template renderer (assets/js/console.js) and the rebuilt,
 * canvas-free designer (assets/js/console-designer.js).
 *
 * Source-level assertions in the style tests/test_console_views.php
 * already established for this feature (no running browser needed) —
 * proving the retired free-form canvas code is actually GONE (not just
 * unused), that the launcher-strip visual-distinction rule from the
 * 5-persona review is wired, and that console-designer.js's client-side
 * SHOW_FLAG_NEEDS map stays byte-for-byte in sync with the server's real
 * console_strip_template_needs() (inc/console-views.php) — a designer
 * that offers a checkbox the server would silently drop is worse than
 * one that never offers it.
 *
 * Usage: php tests/test_phase152_console_strip_templates.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/console-views.php';

$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 152 -- console.js/console-designer.js strip templates ===\n\n";

$cjs = (string) @file_get_contents('assets/js/console.js');
$djs = (string) @file_get_contents('assets/js/console-designer.js');
$css = (string) @file_get_contents('assets/css/console.css');
$page = (string) @file_get_contents('console-designer.php');

// ── console.js: the free-form canvas is genuinely GONE ──────────────────
echo "1. console.js: retired free-form renderer is gone, not just unused\n";
t('renderPositionedStrip() no longer exists', strpos($cjs, 'function renderPositionedStrip') === false);
t('renderComponent() no longer exists', strpos($cjs, 'function renderComponent') === false);
t('defaultControls() no longer exists (replaced by defaultShowTemplate())', strpos($cjs, 'function defaultControls') === false);
t('the pixel-grid constants (OUTER_CELL/INNER_CELL) are gone', strpos($cjs, 'OUTER_CELL') === false && strpos($cjs, 'INNER_CELL') === false);
t('console-bank-abs (absolute-canvas mode) is never referenced', strpos($cjs, 'console-bank-abs') === false);
t('.ccp component classes are gone from the CSS too', strpos($css, '.ccp ') === false && strpos($css, '.ccp-') === false);
t('.console-strip-abs / .console-bank-abs rules are gone from the CSS', strpos($css, '.console-strip-abs') === false && strpos($css, '.console-bank-abs') === false);

echo "\n2. console.js: ONE renderer for both auto and designer-authored views\n";
t('renderStrip() is the sole strip renderer, consuming {overrides,show,hotkey,width}',
    strpos($cjs, 'function renderStrip(ch, tpl)') !== false
    && strpos($cjs, 'var show = (tpl && tpl.show) || {};') !== false);
t('renderBank() calls renderStrip() for BOTH the designer view and the auto view',
    substr_count($cjs, 'renderStrip(') >= 2
    && strpos($cjs, 'bank.appendChild(renderStrip(ch, s));') !== false
    && strpos($cjs, 'defaultShowTemplate(autoCh.capabilities || {})') !== false);
t('activity + status LED are universal chrome now (no show-flag gates them)',
    strpos($cjs, "el('div', 'console-strip-activity')") !== false);
t('sel/ptt/mon-mute-vol/text are each gated on their own show flag',
    strpos($cjs, 'if (show.sel)') !== false
    && strpos($cjs, 'if (show.ptt &&') !== false
    && strpos($cjs, '(show.mon || show.mute || show.vol)') !== false
    && strpos($cjs, 'if (show.text &&') !== false);

echo "\n3. console.js: launcher-strip visual distinction (5-persona review, veteran's addition)\n";
t('a zello/dmr launcher strip is marked with data-launcher + a distinct CSS class',
    strpos($cjs, "strip.classList.add('console-strip-launcher'); strip.setAttribute('data-launcher', '1');") !== false);
t('the launcher button uses console-launcher-btn, NEVER console-ptt or a pttColor background '
    . '(must not look like a real PTT control)',
    strpos($cjs, "el('button', 'btn btn-sm console-launcher-btn', null)") !== false
    && substr_count($cjs, "el('button', 'btn btn-sm console-launcher-btn', null)") === 2
    && strpos($cjs, 'zb.style.background') === false && strpos($cjs, 'rb.style.background') === false);
t('.console-launcher-btn is styled distinctly (outline, not solid PTT red) in CSS',
    strpos($css, '.console-launcher-btn {') !== false && strpos($css, 'border: 1px dashed') !== false);

echo "\n4. console.js: per-strip hotkeys toggle Select (a real, already-wired action)\n";
t('a strip with a hotkey carries data-hotkey', strpos($cjs, "strip.setAttribute('data-hotkey', tpl.hotkey);") !== false);
t('a document-level keydown listener resolves the pressed key to a strip and toggles Select, '
    . 'skipping typed input (a text-send box must never trigger a strip switch)',
    strpos($cjs, "document.addEventListener('keydown', function (e) {") !== false
    && strpos($cjs, "t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable") !== false
    && strpos($cjs, 'window.ConsoleAudio.setSelected(chId,') !== false);

// ── console-designer.js: no canvas, no drag library ──────────────────────
echo "\n5. console-designer.js: ordered list, no GridStack/free-drag anywhere\n";
t('GridStack.init()/window.GridStack are never called (dependency dropped entirely -- '
    . 'the docblock names it historically, that is not a live reference)',
    strpos($djs, 'GridStack.init(') === false && strpos($djs, 'window.GridStack') === false);
t('no mousedown-drag/resize machinery survives (addComp/placeComp/mousemove drag handlers gone)',
    strpos($djs, 'function placeComp') === false && strpos($djs, "addEventListener('mousemove'") === false);
t('console-designer.php no longer loads the GridStack vendor bundle',
    strpos($page, 'gridstack') === false);
t('strips are a plain ordered array with Up/Down move + a width badge, not x/y/w/h layout',
    strpos($djs, 'var strips = [];') !== false
    && strpos($djs, "var t = strips[idx - 1]; strips[idx - 1] = strips[idx]; strips[idx] = t;") !== false
    && strpos($djs, "var t = strips[idx + 1]; strips[idx + 1] = strips[idx]; strips[idx] = t;") !== false);
t('position is derived from array order alone -- no explicit position field is ever sent',
    strpos($djs, "'position'") === false);

echo "\n6. console-designer.js: the inspector edits {overrides,show,hotkey,width} directly\n";
t('width is a simple 1x/2x select, not a pixel rectangle', strpos($djs, "s.width = (widthSel.value === '2') ? 2 : 1;") !== false);
t('hotkey is validated against the same F1-F12/single-char pattern the server enforces',
    strpos($djs, "var HOTKEY_RE = /^(F[1-9]|F1[0-2]|[A-Za-z0-9])\$/;") !== false);
t('the show-flag checkboxes write directly into s.show[key]', strpos($djs, 's.show[key] = inp.checked;') !== false);
t('vu renders as an honest, disabled "future" checkbox (no backend yet), matching this '
    . 'project\'s established precedent for the old \'say\' (TTS) placeholder -- patchchips and '
    . 'recall are NOT in this set, since the patch rail and Recall tab shipped and made them real',
    strpos($djs, "var FUTURE_FLAGS = { vu: true };") !== false
    && strpos($djs, 'inp.disabled = !allowed || future;') !== false
    && strpos($djs, "'badge text-bg-warning ms-1', 'future'") !== false);
t('save publishes the exact {channel_id,overrides,show,hotkey,width} shape console_view_save_strips() expects',
    strpos($djs, "payload.push({ channel_id: s.channel_id, overrides: s.overrides, show: s.show, hotkey: s.hotkey, width: s.width });") !== false);

echo "\n7. The client-side capability-gate map matches the server's exactly (inc/console-views.php)\n";
$needs = console_strip_template_needs();
foreach ($needs as $key => $need) {
    if ($need === null) {
        t("SHOW_FLAG_NEEDS['$key'] is null (no capability gate) in the JS, matching the server",
            preg_match('/\b' . preg_quote($key, '/') . ':\s*null\b/', $djs) === 1);
    } else {
        $jsArray = "['" . implode("', '", $need) . "']";
        t("SHOW_FLAG_NEEDS['$key'] = $jsArray in the JS, matching the server byte-for-byte",
            strpos($djs, $key . ': ' . $jsArray) !== false);
    }
}
// Sanity floor: if inc/console-views.php's function is ever renamed/gutted,
// this test must not silently report "all clean" on an empty map.
t('sanity floor: console_strip_template_needs() actually returned all 9 known keys',
    count($needs) === 9);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
