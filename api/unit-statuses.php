<?php
/**
 * NewUI v4.0 API - Unit Statuses
 *
 * GET /api/unit-statuses.php
 *   Returns all un_status entries for building status selection UI.
 */

require_once __DIR__ . '/auth.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

// Phase 95 (2026-06-28) — include extra_data_* columns so the
// status-change UI can prompt for the configured extra-data type.
// Fallback to the legacy column set on installs that haven't run
// sql/run_phase95_status_extra_data.php yet — synthesizes 'none'
// defaults so the JS treats those statuses as no-extra-data.
try {
    $rows = db_fetch_all(
        "SELECT `id`, `status_val`, `description`, `dispatch`, `watch`, `hide`,
                `excl_from_reset`, `group`, `bg_color`, `text_color`,
                `extra_data_type`, `extra_data_required`,
                `extra_data_label`, `extra_data_target`
         FROM `{$prefix}un_status`
         ORDER BY `id`"
    );
} catch (Exception $e95) {
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `status_val`, `description`, `dispatch`, `watch`, `hide`,
                    `excl_from_reset`, `group`, `bg_color`, `text_color`
             FROM `{$prefix}un_status`
             ORDER BY `id`"
        );
        foreach ($rows as &$r) {
            $r['extra_data_type']     = 'none';
            $r['extra_data_required'] = 0;
            $r['extra_data_label']    = null;
            $r['extra_data_target']   = 'action_log';
        }
        unset($r);
    } catch (Exception $e) {
        json_error('Database error: ' . $e->getMessage(), 500);
    }
}

json_response([
    'statuses' => $rows,
    'count'    => count($rows),
]);
