<?php
/**
 * GH #61 — Config editor for Unit Types.
 *
 * unit-edit.php offered a unit "type" (populated from api/unit-types.php) but
 * settings.php had no way to create/edit/delete those types. This adds the
 * editor (config-admin.php `unit_types` section + settings panel + config.js +
 * sidebar tab), mirroring facility types.
 *
 * Static wiring guards + a DB round-trip proving the unit_types CRUD works,
 * including the legacy audit-column self-heal.
 *
 * Usage: php tests/test_unit_types_config.php
 */
require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== GH #61 — Unit Types config editor ===\n\n";

// ── API section ─────────────────────────────────────────────────────────────
$api = rd($base . '/api/config-admin.php');
t('config-admin has a unit_types section',
    $api !== false && strpos($api, "if (\$section === 'unit_types')") !== false);
t('unit_types section handles GET/POST/DELETE',
    $api !== false &&
    (bool) preg_match("/unit_types.*SELECT .id., .name., .description., .icon. FROM/s", $api) &&
    strpos($api, "INSERT INTO `{\$prefix}unit_types`") !== false &&
    strpos($api, "DELETE FROM `{\$prefix}unit_types`") !== false);
t('unit_types save self-heals legacy audit columns + audits',
    $api !== false &&
    strpos($api, "heal_legacy_defaults(\$prefix . 'unit_types')") !== false &&
    strpos($api, "audit_log('config', \$id ? 'update' : 'create', 'unit_type'") !== false);

// ── Settings panel ──────────────────────────────────────────────────────────
$set = rd($base . '/settings.php');
t('settings has panel-unit-types with name/desc/icon form',
    $set !== false &&
    strpos($set, 'id="panel-unit-types"') !== false &&
    strpos($set, 'id="unitTypeForm"') !== false &&
    strpos($set, 'id="unitTypeName"') !== false &&
    strpos($set, 'id="unitTypesTableBody"') !== false &&
    strpos($set, 'id="btnAddUnitType"') !== false);

// ── Config controller ───────────────────────────────────────────────────────
$js = rd($base . '/assets/js/config.js');
t('config.js binds + loads unit types and hits the unit_types API',
    $js !== false &&
    strpos($js, 'bindUnitTypesPanel();') !== false &&
    strpos($js, "tab === 'unit-types')        loadUnitTypes()") !== false &&
    strpos($js, "apiPost('unit_types'") !== false &&
    strpos($js, "apiDelete('unit_types'") !== false);
// Window widened 600 → 1500 chars: the GH #62 icon-glyph rendering added
// ~350 chars between the function head and the name cell. Also assert the
// description cell is escaped, not just the name.
t('config.js render is DOM-safe (esc() on name/description)',
    $js !== false
    && (bool) preg_match('/renderUnitTypes[\s\S]{0,1500}esc\(ut\.name\)/', $js)
    && (bool) preg_match('/renderUnitTypes[\s\S]{0,1500}esc\(ut\.description/', $js));

// ── Sidebar ─────────────────────────────────────────────────────────────────
$side = rd($base . '/inc/config-sidebar.php');
t('config sidebar has a Unit Types tab',
    $side !== false && strpos($side, "_cfg_tab('unit-types'") !== false);

// ── Name field widened 16 → 32 (Eric, 2026-07-05) ────────────────────────────
$mig = rd($base . '/sql/run_unit_types_name_widen.php');
t('migration widens unit_types.name to varchar(32)',
    $mig !== false && strpos($mig, "MODIFY `name` VARCHAR(32)") !== false);
t('settings form allows 32-char names (maxlength=32)',
    $set !== false && strpos($set, 'id="unitTypeName" name="name" required maxlength="32"') !== false);
t('API clamps name to 32 (matches the widened column)',
    $api !== false && strpos($api, "mb_substr(trim(\$input['name'] ?? ''), 0, 32)") !== false);
$nameLen = (int) db_fetch_value(
    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='name'", [$prefix . 'unit_types']);
t('live unit_types.name column is >= 32 (migration applied)', $nameLen >= 32);

// ── DB round-trip (the CRUD the API performs) — a NAME LONGER THAN 16 chars ───
$marker = 'ZZ Long Unit Type 61';  // 20 chars — would fail on the old varchar(16)
try {
    db_query("DELETE FROM `{$prefix}unit_types` WHERE `name` = ?", [$marker]);
    // Mirror the API's insert (name/description/icon only — audit cols self-heal).
    $ok = false;
    try {
        db_query("INSERT INTO `{$prefix}unit_types` (`name`,`description`,`icon`) VALUES (?,?,0)", [$marker, 'test']);
        $ok = true;
    } catch (Throwable $e) {
        // Legacy install: audit cols NOT NULL. Confirm heal path is what the API uses.
        $ok = strpos(rd($base . '/api/config-admin.php'), "heal_legacy_defaults(\$prefix . 'unit_types')") !== false;
        if (!function_exists('heal_legacy_defaults')) { require_once $base . '/inc/schema-heal.php'; }
        if (function_exists('heal_legacy_defaults')) {
            heal_legacy_defaults($prefix . 'unit_types');
            db_query("INSERT INTO `{$prefix}unit_types` (`name`,`description`,`icon`) VALUES (?,?,0)", [$marker, 'test']);
            $ok = true;
        }
    }
    t('unit_types INSERT succeeds (with heal fallback on legacy audit cols)', $ok);

    $row = db_fetch_one("SELECT id, name, description FROM `{$prefix}unit_types` WHERE `name` = ?", [$marker]);
    t('inserted unit type reads back', $row && $row['name'] === $marker && $row['description'] === 'test');

    db_query("DELETE FROM `{$prefix}unit_types` WHERE `name` = ?", [$marker]);
    $gone = db_fetch_one("SELECT id FROM `{$prefix}unit_types` WHERE `name` = ?", [$marker]);
    t('unit_types DELETE removes the row', $gone === false || $gone === null);
} catch (Throwable $e) {
    t('DB round-trip (unexpected error: ' . $e->getMessage() . ')', false);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
