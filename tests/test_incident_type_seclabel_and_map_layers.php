<?php
/**
 * Eric (2026-08-12) — two smaller, related fixes bundled with the incident
 * creation / security-label work:
 *
 * 1. settings.php's Incident Types panel can now set a per-type default
 *    Security Label (the admin-facing half of closing seclabel_resolve()'s
 *    previously-dead tier 2).
 * 2. new-incident.php's map never had a layer control — no way to switch
 *    basemap (e.g. to satellite to spot a landmark) or toggle weather/radar
 *    while taking a call, unlike every other map page in the app.
 *
 * Static-contract checks (no JS runtime in CI).
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== Incident-type default Security Label + new-incident map layers ===\n\n";

$settings = file_get_contents($root . '/settings.php');
$configJs = file_get_contents($root . '/assets/js/config.js');
$configAdmin = file_get_contents($root . '/api/config-admin.php');

// ── Settings UI ──────────────────────────────────────────────────────
if (strpos($settings, 'id="typeDefaultSecLabel"') !== false) {
    ok('settings.php has the Default Security Label field on the incident-type edit form');
} else {
    bad('settings.php is missing the typeDefaultSecLabel field');
}
if (strpos($settings, 'name="default_security_label_id"') !== false) {
    ok('the field posts as default_security_label_id (matches the DB column + API param)');
} else {
    bad('the field\'s name attribute does not match default_security_label_id');
}

// ── config.js wiring ─────────────────────────────────────────────────
if (strpos($configJs, 'function loadSecLabelOptions') !== false) {
    ok('config.js has loadSecLabelOptions() to populate the select');
} else {
    bad('config.js is missing loadSecLabelOptions()');
}
if (preg_match('/function openTypeForm[\s\S]{0,3000}typeDefaultSecLabel/', $configJs)) {
    ok('openTypeForm() sets the Default Security Label field when editing an existing type');
} else {
    bad('openTypeForm() does not appear to populate typeDefaultSecLabel');
}

// ── api/config-admin.php wiring (GET + POST both sides) ─────────────
if (preg_match('/SELECT[\s\S]{0,300}default_security_label_id[\s\S]{0,100}FROM.*in_types/i', $configAdmin)) {
    ok('config-admin.php\'s types GET selects default_security_label_id');
} else {
    bad('config-admin.php\'s types GET does not select default_security_label_id');
}
if (strpos($configAdmin, '$defaultSecLabelId') !== false
    && strpos($configAdmin, 'INSERT INTO') !== false
    && strpos($configAdmin, 'UPDATE') !== false) {
    ok('config-admin.php\'s types POST persists default_security_label_id on both insert and update');
} else {
    bad('config-admin.php\'s types POST does not appear to persist default_security_label_id on both paths');
}

// ── new-incident.php map layer control ───────────────────────────────
$newIncidentJs = file_get_contents($root . '/assets/js/new-incident.js');
if (strpos($newIncidentJs, 'MapPrefs.addLayerControl') !== false) {
    ok('new-incident.js now calls MapPrefs.addLayerControl() (basemap switcher + weather/radar toggles)');
} else {
    bad('new-incident.js still never calls MapPrefs.addLayerControl() — no layer control on the New Incident map');
}
if (strpos($newIncidentJs, 'includeMarkupOverlays: true') !== false) {
    ok('new-incident.js includes markup overlays, matching incident-detail.js / unit-detail.js');
} else {
    bad('new-incident.js\'s layer control call does not include markup overlays (inconsistent with other map pages)');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
