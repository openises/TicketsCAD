<?php
/**
 * NewUI v4.0 API - Unit Types
 *
 * GET /api/unit-types.php
 *   Returns all unit_types entries for populating type dropdowns.
 */

require_once __DIR__ . '/auth.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $rows = db_fetch_all(
        "SELECT `id`, `name`, `icon` FROM `{$prefix}unit_types` ORDER BY `name`"
    );
} catch (Exception $e) {
    // Table may not exist in some installations
    $rows = [];
}

json_response([
    'types' => $rows,
    'count' => count($rows),
]);
