<?php
/**
 * GH#147 / GH#134-class guard -- every webhook event the system can fire can be
 * subscribed to from Settings, and re-saving a subscription never drops a
 * filter the form cannot show.
 *
 * GH#134 removed checkboxes whose values could never match; it never ADDED the
 * ones that were missing. The map in inc/webhooks.php fires incident.reopened
 * and incident.deleted, and (since this phase) incident.status_changed -- two
 * of them had no checkbox, so a subscriber on the Settings form could not ask
 * for them. Worse, the form rebuilt a subscription's filter list from ticked
 * boxes only: opening and saving an API-created subscription for
 * incident.reopened or "incident.*" silently deleted that filter.
 *
 * Checks:
 *   1. every incident.* and assign.* event in _audit_to_webhook_event() has a
 *      wh-evt checkbox, and every checkbox (except "*") is a real mapped event
 *   2. incident.status_changed / .reopened / .deleted specifically
 *   3. the two pure JS helpers in assets/js/config.js, run under node:
 *      filters with no checkbox are remembered and written back; "*" wins;
 *      nothing is duplicated; bad JSON degrades to "no extras"
 *
 * @requires-db
 * Usage: php tests/test_gh147_event_checkbox_parity.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_node_probe.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#147 -- webhook event checkbox parity ===\n\n";

$base = realpath(__DIR__ . '/..');
$webhooksSrc = (string) file_get_contents($base . '/inc/webhooks.php');
$settingsSrc = (string) file_get_contents($base . '/settings.php');
$jsSrc       = (string) file_get_contents($base . '/assets/js/config.js');

// ── 1. map <-> checkboxes ────────────────────────────────────────────────
echo "--- the map and the form agree ---\n";
// Slice out _audit_to_webhook_event()'s map literal and pull its values.
$start = strpos($webhooksSrc, 'function _audit_to_webhook_event(');
$end   = strpos($webhooksSrc, '$key = $cat', $start);
$mapSrc = ($start !== false && $end !== false) ? substr($webhooksSrc, $start, $end - $start) : '';
// Strip comments so prose that mentions an event name is not mistaken for a map entry.
$mapSrc = preg_replace('#//[^\n]*#', '', $mapSrc);
preg_match_all("/'[a-z_]+\\|[a-z_]+\\|[a-z_]*'\\s*=>\\s*'([a-z_]+\\.[a-z_]+)'/", $mapSrc, $mm);
$mapped = array_values(array_unique($mm[1] ?? []));
t('the event map was read (a healthy number of events)', count($mapped) >= 25, (string) count($mapped));

$gridStart = strpos($settingsSrc, 'id="webhookEventsGrid"');
$gridEnd   = strpos($settingsSrc, 'id="webhookOtherEvents"', (int) $gridStart);
$grid = ($gridStart !== false && $gridEnd !== false) ? substr($settingsSrc, $gridStart, $gridEnd - $gridStart) : '';
preg_match_all('/class="form-check-input wh-evt" type="checkbox" value="([^"]+)"/', $grid, $vm);
$boxes = $vm[1] ?? [];
t('the checkbox grid was read', count($boxes) >= 8, (string) count($boxes));
t('the grid still has the All Events (*) box', in_array('*', $boxes, true));

$mustHave = array_values(array_filter($mapped, function ($e) {
    return strpos($e, 'incident.') === 0 || strpos($e, 'assign.') === 0 || $e === 'responder.status_changed';
}));
$missing = array_values(array_diff($mustHave, $boxes));
t('EVERY incident.*, assign.* and responder.status_changed event in the map has a checkbox',
    $missing === [], 'no checkbox for: ' . implode(', ', $missing));
$bogus = array_values(array_diff(array_diff($boxes, ['*']), $mapped));
t('every checkbox (other than *) is a real mapped event -- none is inert (the GH#134 failure)',
    $bogus === [], 'not in the map: ' . implode(', ', $bogus));
t('no checkbox value is listed twice', count($boxes) === count(array_unique($boxes)));

// ── 2. The three this phase cares about ──────────────────────────────────
foreach (['incident.status_changed', 'incident.reopened', 'incident.deleted'] as $ev) {
    t("$ev is both a mapped event and a checkbox", in_array($ev, $mapped, true) && in_array($ev, $boxes, true));
}
$ids = [];
preg_match_all('/<input[^>]*class="form-check-input wh-evt"[^>]*id="([^"]+)"/', $grid, $im);
$ids = $im[1] ?? [];
t('every checkbox has a unique element id (label for= wiring)', count($ids) === count(array_unique($ids)) && count($ids) === count($boxes));
t('the hidden other-events field exists for filters with no checkbox', strpos($settingsSrc, 'id="webhookOtherEvents"') !== false);
t("a checkbox's label points at its own id (keyboard/label click works)",
    (bool) preg_match('/id="whEvt9"><label class="form-check-label small" for="whEvt9"[^>]*>incident\.status_changed</', $grid));

// ── 3. The JS helpers, under node ────────────────────────────────────────
echo "\n--- config.js: filters with no checkbox survive a re-save (node) ---\n";
$node = test_probe_cli(['node', 'node.exe']);
if ($node === null) {
    echo "SKIP: node is not available on this host -- the config.js helper checks were not run\n";
} else {
    $driver = <<<'JS'
var fs = require('fs');
var src = fs.readFileSync(process.argv[2], 'utf8');
function extract(name) {
    var i = src.indexOf('function ' + name + '(');
    if (i < 0) throw new Error('missing ' + name);
    var depth = 0, j = src.indexOf('{', i), k = j;
    for (; k < src.length; k++) {
        if (src[k] === '{') depth++;
        else if (src[k] === '}') { depth--; if (depth === 0) break; }
    }
    return src.slice(i, k + 1);
}
eval(extract('whOtherEvents') + '\n' + extract('whMergeEvents'));
var known = ['*', 'incident.created', 'incident.closed', 'incident.status_changed'];
var out = {};
out.other1 = whOtherEvents(['incident.*', 'incident.closed', 'weird.thing'], known);
out.other2 = whOtherEvents([], known);
out.other3 = whOtherEvents(null, known);
out.merge1 = whMergeEvents(['incident.closed'], JSON.stringify(['incident.*']));
out.merge2 = whMergeEvents(['*'], JSON.stringify(['incident.*']));
out.merge3 = whMergeEvents(['incident.closed', 'incident.*'], JSON.stringify(['incident.*']));
out.merge4 = whMergeEvents(['incident.closed'], 'not json');
out.merge5 = whMergeEvents([], '[]');
out.merge6 = whMergeEvents(['incident.closed'], undefined);
var input = ['incident.closed'];
whMergeEvents(input, JSON.stringify(['x.y']));
out.inputUntouched = input.length === 1;
// the full round trip: open a subscription holding a wildcard, save with one ticked box
var saved = ['incident.*', 'incident.reopened'];
var knownBoxes = ['*', 'incident.reopened', 'incident.closed'];
var remembered = whOtherEvents(saved, knownBoxes);
out.roundTrip = whMergeEvents(['incident.reopened'], JSON.stringify(remembered));
console.log(JSON.stringify(out));
JS;
    $f = tempnam(sys_get_temp_dir(), 'p155js') . '.js';
    file_put_contents($f, $driver);
    $outRaw = test_run_cli([$node, $f, $base . '/assets/js/config.js']);
    @unlink($f);
    $lines = preg_split('/\r?\n/', trim((string) $outRaw));
    $o = json_decode((string) end($lines), true);
    t('the node driver ran and printed results', is_array($o), (string) $outRaw);
    if (is_array($o)) {
        t('whOtherEvents: a wildcard and an unknown event are "other"; a ticked box is not',
            $o['other1'] === ['incident.*', 'weird.thing']);
        t('whOtherEvents: nothing subscribed -> nothing other (empty and null)', $o['other2'] === [] && $o['other3'] === []);
        t('whMergeEvents: ticked boxes + remembered extras are both saved', $o['merge1'] === ['incident.closed', 'incident.*']);
        t('whMergeEvents: "All Events" (*) is saved alone -- nothing is added beside it', $o['merge2'] === ['*']);
        t('whMergeEvents: no duplicates', $o['merge3'] === ['incident.closed', 'incident.*']);
        t('whMergeEvents: unparseable remembered JSON degrades to "no extras"', $o['merge4'] === ['incident.closed']);
        t('whMergeEvents: empty in, empty out', $o['merge5'] === []);
        t('whMergeEvents: a missing remembered value is fine', $o['merge6'] === ['incident.closed']);
        t('whMergeEvents does not mutate its input array', $o['inputUntouched'] === true);
        t('round trip: opening [incident.*, incident.reopened] then saving with only reopened ticked KEEPS incident.*',
            $o['roundTrip'] === ['incident.reopened', 'incident.*'], json_encode($o['roundTrip']));
    }
}

// Wiring: the handlers actually call the helpers (a pure function nothing calls is the tile_mode disease).
t('the submit handler merges the remembered filters through whMergeEvents()', strpos($jsSrc, 'events = whMergeEvents(events,') !== false);
t('opening a subscription remembers its extras through whOtherEvents()', strpos($jsSrc, 'var otherEvents = whOtherEvents(events, knownValues);') !== false);
t("the 'Remove these' button clears the remembered filters (an admin can still drop one deliberately)",
    strpos($jsSrc, "getElementById('btnClearOtherEvents')") !== false && strpos($settingsSrc, 'id="btnClearOtherEvents"') !== false
    && (bool) preg_match('/id="btnClearOtherEvents"/', $settingsSrc) && (bool) preg_match('/<button type="button"[^>]*id="btnClearOtherEvents"/', $settingsSrc));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
