<?php
/**
 * NewUI v4.0 API — Read-only list of patient insurance types.
 *
 * GH TicketsCAD#68 (2026-08-18). Returns the admin-managed `insurance`
 * lookup table so the patient Add/Edit insurance dropdown can render the
 * full set, sorted the way an admin ordered it. Deliberately separate
 * from the admin CRUD (Settings -> Patient Insurance Types, gated on
 * action.manage_config via api/config-admin.php?section=insurance_types)
 * — same split as api/un-statuses.php / api/dispositions-picker.php:
 * SELECTING an insurance type while editing a patient needs no special
 * permission beyond ordinary incident access, only MANAGING the list
 * itself is admin-only.
 *
 * GET /api/insurance-types.php → all insurance types, admin sort order
 */
require_once __DIR__ . '/auth.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $rows = db_fetch_all(
        "SELECT `id`, `ins_value`, `sort_order`
           FROM `{$prefix}insurance`
          ORDER BY `sort_order` ASC, `ins_value` ASC"
    );
} catch (Exception $e) {
    // Table missing on an unmigrated install — degrade to an empty list
    // rather than a 500 (matches api/patients.php's own convention).
    $rows = [];
}

json_response(['insurance_types' => $rows]);
