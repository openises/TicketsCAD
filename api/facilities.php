<?php
/**
 * NewUI v4.0 API - Facilities
 *
 * GET /api/facilities.php
 *
 * Returns all facilities with their type, status, and location data.
 */

require_once __DIR__ . '/auth.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// Facility status lookup
$fac_statuses = [];
$rows = db_fetch_all("SELECT * FROM `{$prefix}fac_status` ORDER BY `id`");
foreach ($rows as $r) {
    $fac_statuses[(int) $r['id']] = $r;
}

// Facility type lookup
$fac_types = [];
$rows = db_fetch_all("SELECT * FROM `{$prefix}fac_types` ORDER BY `id`");
foreach ($rows as $r) {
    $fac_types[(int) $r['id']] = $r;
}

// User group filtering — admins (level 0,1) see all
$user_groups = $_SESSION['user_groups'] ?? [];
$is_admin = is_admin();
$group_filter = '';
$params = [];
// RBAC-aware bypass — see api/incidents.php for the rationale.
require_once __DIR__ . '/../inc/rbac.php';
$rbacFacilityView = (function_exists('rbac_can')
    && (rbac_can('screen.facilities') || rbac_can('facility.view') || rbac_can('widget.facilities')));

if ($is_admin || $rbacFacilityView) {
    $group_filter = "";
} elseif (!empty($user_groups)) {
    $placeholders = implode(',', array_fill(0, count($user_groups), '?'));
    $group_filter = "WHERE `a`.`group` IN ({$placeholders}) AND `a`.`type` = 3";
    $params = $user_groups;
} else {
    $group_filter = "WHERE 1=0";
}

// Phase 99j-6 (Billy beta 2026-06-29) — org-scope filter.
require_once __DIR__ . '/../inc/org-scope.php';
ensure_org_id_column('facilities');
[$orgFrag, $orgVars] = org_query_filter('f.org_id');
if ($orgFrag !== '') {
    if ($group_filter === '') {
        $group_filter = 'WHERE 1=1' . $orgFrag;
    } else {
        $group_filter .= $orgFrag;
    }
    $params = array_merge($params, $orgVars);
}

// Issue #52 regression (a beta tester 2026-07-03): the DELETE endpoint
// soft-deletes via facility_soft_delete_internal() (sets deleted_at
// or falls back to hide=1 on legacy installs), but this list-read
// query never filtered on either column. Every read path returned
// soft-deleted rows, so a beta tester watched a facility "get deleted",
// the page refresh, and the facility still show. Add the filter:
// deleted_at IS NULL (modern schema) AND hide != 1 (legacy). Detect
// which columns actually exist so a legacy-only install doesn't
// throw on a missing column.
$hasDeletedAt = false;
$hasHide = false;
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}facilities`");
    foreach ($cols as $c) {
        if ($c['Field'] === 'deleted_at') $hasDeletedAt = true;
        if ($c['Field'] === 'hide')       $hasHide      = true;
    }
} catch (Exception $e) { /* schema probe best-effort */ }
$softDelFrag = '';
if ($hasDeletedAt) $softDelFrag .= ' AND `f`.`deleted_at` IS NULL';
if ($hasHide)      $softDelFrag .= ' AND (`f`.`hide` IS NULL OR `f`.`hide` <> 1)';
if ($softDelFrag !== '') {
    if ($group_filter === '') {
        $group_filter = 'WHERE 1=1' . $softDelFrag;
    } else {
        $group_filter .= $softDelFrag;
    }
}

// GH #69 — the list omitted bed counts entirely, so facilities.php
// showed "--" for every row while Facility Detail (own query) had
// numbers. Older installs may not have the bed columns; check once.
$hasBeds = false;
try {
    $hasBeds = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'beds_a'",
        [$prefix . 'facilities']
    ) > 0;
} catch (Exception $e) { $hasBeds = false; }
$bedsSel = $hasBeds ? "`f`.`beds_a`,\n    `f`.`beds_o`," : '';

$sql = "SELECT
    `f`.`id`,
    `f`.`name`,
    `f`.`handle`,
    `f`.`description`,
    `f`.`street`,
    `f`.`city`,
    `f`.`state`,
    `f`.`lat`,
    `f`.`lng`,
    `f`.`boundary`,
    `f`.`contact_phone` AS `phone`,
    `f`.`status_id`,
    `f`.`type` AS `type_id`,
    `f`.`opening_hours`,
    `f`.`updated`,
    $bedsSel
    `ft`.`name` AS `type_name`,
    `ft`.`icon` AS `type_icon`,
    `fs`.`status_val` AS `status_name`
FROM `{$prefix}facilities` `f`
LEFT JOIN `{$prefix}allocates` `a` ON `f`.`id` = `a`.`resource_id`
LEFT JOIN `{$prefix}fac_types` `ft` ON `f`.`type` = `ft`.`id`
LEFT JOIN `{$prefix}fac_status` `fs` ON `f`.`status_id` = `fs`.`id`
{$group_filter}
GROUP BY `f`.`id`
ORDER BY `f`.`name` ASC";

$rows = db_fetch_all($sql, $params);

$facilities = [];
foreach ($rows as $row) {
    $type_id = (int) ($row['type_id'] ?? 0);
    $ft = $fac_types[$type_id] ?? null;
    $status_id = (int) ($row['status_id'] ?? 0);
    $fs = $fac_statuses[$status_id] ?? null;

    // opening_hours is a base64-encoded serialized PHP array (per-day schedules)
    $is_open = null;
    $hours_text = '';
    $raw_hours = $row['opening_hours'] ?? '';
    if ($raw_hours !== '') {
        $decoded = @unserialize(@base64_decode($raw_hours));
        if (is_array($decoded)) {
            // Array indexed 0-6 (Sun-Sat), each has [0 => on/off, 1 => open time, 2 => close time]
            $dow = (int) date('w'); // 0=Sun, 1=Mon, ..., 6=Sat
            $today = $decoded[$dow] ?? null;
            if ($today && ($today[0] ?? '') === 'on') {
                $open_t = $today[1] ?? '00:00';
                $close_t = $today[2] ?? '23:59';
                $now_t = date('H:i');
                $is_open = ($now_t >= $open_t && $now_t <= $close_t);
                $hours_text = $open_t . '-' . $close_t;
            } else {
                $is_open = false;
                $hours_text = 'Closed today';
            }
        }
    }

    $facilities[] = [
        'id'          => (int) $row['id'],
        'name'        => $row['name'],
        'handle'      => $row['handle'],
        'description' => $row['description'],
        'street'      => $row['street'],
        'city'        => $row['city'],
        'state'       => $row['state'],
        'lat'         => (float) $row['lat'],
        'lng'         => (float) $row['lng'],
        'boundary'    => $row['boundary'],
        'phone'       => $row['phone'],
        'type_id'     => $type_id,
        'type_name'   => $row['type_name'],
        'type_icon'   => $row['type_icon'],
        'status_id'   => $status_id,
        'status_name' => $row['status_name'],
        'hours_today' => $hours_text,
        'is_open'     => $is_open,
        'updated'     => $row['updated'],
        'bg_color'    => $fs['bg_color'] ?? '#ffffff',
        'text_color'  => $fs['text_color'] ?? '#000000',
    ];
    if ($hasBeds) {
        // Legacy rows hold '' (never configured) — surface as null so the
        // UI shows "--" instead of a misleading 0/0.
        $last = count($facilities) - 1;
        $facilities[$last]['beds_a'] = ($row['beds_a'] !== null && $row['beds_a'] !== '')
            ? (int) $row['beds_a'] : null;
        $facilities[$last]['beds_o'] = ($row['beds_o'] !== null && $row['beds_o'] !== '')
            ? (int) $row['beds_o'] : null;
    }
}

// Collect unique type names for category filtering
$categories = array_values(array_unique(array_filter(array_column($facilities, 'type_name'))));

// Issue #29 (a beta tester + a beta tester, 2026-07-02) — expose the fac_types +
// fac_status lookup tables so facility-edit.js can populate the type
// and status dropdowns on a fresh install. Previously facility-edit
// derived the dropdowns from EXISTING facilities' type_id/status_id,
// so on a clean install with no facilities the dropdowns were empty
// even when the admin had already configured types via settings.
//
// Schema notes (verified 2026-07-03 fresh install):
//   fac_types.name         VARCHAR(48)  — human label
//   fac_types.description  VARCHAR(96)
//   fac_types.icon         INT          — icon id (not a string class)
//   fac_status.status_val  VARCHAR(20)  — human label (NOT `name`)
//   fac_status.description VARCHAR(60)
//   fac_status.bg_color    VARCHAR(16)
//   fac_status.text_color  VARCHAR(16)
$fac_types_out = [];
foreach ($fac_types as $t) {
    $fac_types_out[] = [
        'id'          => (int) $t['id'],
        'name'        => $t['name'] ?? '',
        'description' => $t['description'] ?? '',
        'icon'        => isset($t['icon']) ? (int) $t['icon'] : 0,
    ];
}
$fac_statuses_out = [];
foreach ($fac_statuses as $s) {
    $fac_statuses_out[] = [
        'id'          => (int) $s['id'],
        // JS side reads .name for the label; alias status_val so
        // existing callers still work.
        'name'        => $s['status_val'] ?? '',
        'status_val'  => $s['status_val'] ?? '',
        'description' => $s['description'] ?? '',
        'bg_color'    => $s['bg_color'] ?? null,
        'text_color'  => $s['text_color'] ?? null,
    ];
}

json_response([
    'facilities'   => $facilities,
    'count'        => count($facilities),
    'categories'   => $categories,
    'fac_types'    => $fac_types_out,
    'fac_statuses' => $fac_statuses_out,
]);
