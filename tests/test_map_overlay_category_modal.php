<?php
/**
 * Eric UX fix (2026-08-19) — Map Overlay Categories admin panel
 * (Settings → Maps/Overlays → Map Overlays → Categories).
 *
 * Eric hit a chain of native prompt()/confirm() dialogs while creating a
 * new category: prompt() for name, prompt() for color as raw hex text with
 * no picker, prompt() for icon, prompt() for sort order, then a confirm()
 * titled "Show by default on the map?" offering only OK/Cancel where a real
 * Yes/No choice belongs. Fixed by replacing openNewMapCatPrompt() /
 * window.__mc_edit() with a single shared Bootstrap modal
 * (#mapCatEditModal in settings.php), mirroring the GH#39 Places-panel fix
 * (bindX()/openX() guarded-bind pattern, bootstrap.Modal.
 * getOrCreateInstance()/.getInstance()) and reusing the SAME syncColorPair()
 * helper the Severity Levels panel already uses for its color picker + hex
 * text pair.
 *
 * This is a UI-only change — the API endpoints and payload shape
 * (api/map-overlay-categories.php?action=create|update; name/color/icon/
 * sort_order/default_visible/csrf_token/id) are unchanged, so this suite is
 * a content/structural audit in the style of tests/test_issue13_62_fixes.php
 * (GH#62 icon picker) rather than a DB-driven functional test — there is no
 * new backend behavior to exercise through a writer.
 *
 * window.__mc_archive() (the "Archive this category?" confirm()) is
 * DELIBERATELY untouched — a plain confirm() for a destructive delete-style
 * action is the established, reasonable pattern elsewhere in this codebase
 * and was not part of Eric's complaint.
 *
 * Usage: php tests/test_map_overlay_category_modal.php
 */
$base   = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return (string) @file_get_contents($p); }

echo "=== Map Overlay Categories: modal replaces prompt()/confirm() chain ===\n\n";

$js  = rd($base . '/assets/js/config.js');
$set = rd($base . '/settings.php');

// ── The old native dialogs are gone ──────────────────────────────────────
t('openNewMapCatPrompt() no longer prompt()s for a category name',
    strpos($js, 'prompt(\'Category name (e.g. "Patrol Zones"):\')') === false);
t('the raw-hex color prompt() is gone',
    strpos($js, "prompt('Color (hex, e.g. #1976d2):'") === false);
t('the icon-name prompt() is gone',
    strpos($js, "prompt('Bootstrap icon name") === false);
t('the sort-order prompt() is gone',
    strpos($js, "prompt('Sort order (lower = first):'") === false);
t('the "Show by default on the map?" confirm() (OK/Cancel where Yes/No belongs) is gone',
    strpos($js, "confirm('Show by default on the map?')") === false);
t('the __mc_edit() prompt() chain (Name/Color/Icon/Sort order) is gone',
    strpos($js, "prompt('Sort order:', c.sort_order)") === false);

// ── __mc_archive() is untouched — not part of this fix ──────────────────
t('window.__mc_archive() still exists using a plain confirm() (deliberately unchanged)',
    strpos($js, "window.__mc_archive = function (id) {") !== false &&
    strpos($js, "confirm('Archive this category? Markups in it will become uncategorised.')") !== false);

// ── New modal-driven JS exists and keeps the same calling convention ────
t('openNewMapCatPrompt() still exists (btnNewMapCat keeps calling it unchanged) and now opens the modal',
    strpos($js, 'function openNewMapCatPrompt() { openMapCatEditModal(null); }') !== false);
t('window.__mc_edit(id) still exists (row action buttons keep calling it unchanged) and now opens the modal',
    strpos($js, 'window.__mc_edit = function (id) {') !== false &&
    strpos($js, 'openMapCatEditModal(c);') !== false);
t('openMapCatEditModal() uses bootstrap.Modal.getOrCreateInstance() (GH#39 Places-panel pattern)',
    strpos($js, "bootstrap.Modal.getOrCreateInstance(document.getElementById('mapCatEditModal')).show();") !== false);
t('saveMapCatEdit() closes the modal via bootstrap.Modal.getInstance()',
    strpos($js, "bootstrap.Modal.getInstance(document.getElementById('mapCatEditModal'));") !== false);
t('the color picker is synced with its hex text field via the shared syncColorPair() helper (same helper Severity Levels uses)',
    strpos($js, "syncColorPair(picker, colorText);") !== false);
t('default visibility is now a checkbox read via .checked, never confirm()',
    strpos($js, "document.getElementById('mapCatEditDefaultVisible').checked") !== false);
t('a new category defaults default_visible to true, matching the mmarkup_cats DB column default (NOT NULL DEFAULT 1)',
    strpos($js, "document.getElementById('mapCatEditDefaultVisible').checked = isEdit ? !!Number(cat.default_visible) : true;") !== false);

// ── API contract is UNCHANGED: same endpoints, same payload field names ──
t('create still posts to api/map-overlay-categories.php?action=create',
    strpos($js, "fetch('api/map-overlay-categories.php?action=' + (isEdit ? 'update' : 'create')") !== false);

// Scope the payload-field check to saveMapCatEdit()'s own body (not the
// whole 15,000-line file) so a field name matching elsewhere can't
// false-positive the assertion.
$fnStart = strpos($js, 'function saveMapCatEdit()');
$fnBody  = '';
if ($fnStart !== false) {
    $fnEnd  = strpos($js, "\n    window.__mc_archive", $fnStart);
    $fnBody = $fnEnd !== false ? substr($js, $fnStart, $fnEnd - $fnStart) : '';
}
t('saveMapCatEdit() was found and isolated for payload-field checks', $fnBody !== '');
foreach (['name', 'color', 'icon', 'sort_order', 'default_visible', 'csrf_token'] as $field) {
    t("saveMapCatEdit() payload still includes `$field` (API contract unchanged)",
        strpos($fnBody, "$field:") !== false);
}

// ── settings.php: the modal exists with every field ──────────────────────
t('settings.php defines #mapCatEditModal',
    strpos($set, 'id="mapCatEditModal"') !== false);
t('modal has a Name text input',
    strpos($set, 'id="mapCatEditName"') !== false);
t('modal has a real color picker (type=color) — not a raw hex prompt()',
    (bool) preg_match('/type="color"[^>]*id="mapCatEditColorPicker"/', $set));
t('modal ALSO has a hex text input next to the picker (type-and-paste still works)',
    strpos($set, 'id="mapCatEditColor"') !== false);
t('modal has an Icon text input',
    strpos($set, 'id="mapCatEditIcon"') !== false);
t('modal has a live icon glyph preview',
    strpos($set, 'id="mapCatEditIconPreview"') !== false);
t('modal has a Sort Order number input',
    (bool) preg_match('/type="number"[^>]*id="mapCatEditSort"/', $set));
t('modal has a real checkbox for "Show by default on the map" — not a confirm()',
    (bool) preg_match('/type="checkbox"[^>]*id="mapCatEditDefaultVisible"/', $set));
t('the checkbox label reads "Show by default on the map" (same intent as the old confirm() text, now a real control)',
    strpos($set, 'for="mapCatEditDefaultVisible">Show by default on the map<') !== false);
t('modal has Save and Cancel buttons',
    strpos($set, 'id="btnMapCatEditSave"') !== false &&
    (bool) preg_match('/data-bs-dismiss="modal">Cancel</', $set));
t('the modal sits inside the Map Overlays panel (near mapCatsBody, per the GH#39 Places-panel precedent placement)',
    strpos($set, 'id="mapCatsBody"') !== false &&
    strpos($set, 'id="mapCatEditModal"') !== false &&
    strpos($set, 'id="mapCatsBody"') < strpos($set, 'id="mapCatEditModal"') &&
    strpos($set, 'id="mapCatEditModal"') < strpos($set, 'id="moMapWrap"'));

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
