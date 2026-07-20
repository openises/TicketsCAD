<?php
/**
 * NewUI v4.0 API — Read-only list of unit statuses (Phase 25, 2026-06-11).
 *
 * Returns admin-configured un_status rows so the incident-detail
 * assignment dropdown can render the full set with colors. Sorted
 * by the admin's sort order.
 *
 * GET /api/un-statuses.php           → all enabled statuses
 * GET /api/un-statuses.php?dispatch=1 → only dispatch-flagged statuses
 */
require_once __DIR__ . '/auth.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

$where = "hide <> 'y' AND hide <> '1'";
$params = [];
if (!empty($_GET['dispatch'])) {
    $where .= " AND dispatch = 1";
}

try {
    // Phase 95 (2026-06-28) — include extra_data_* columns so the
    // dispatcher status-change UI can prompt for the right kind of
    // input (facility dropdown, mileage number, etc.) when the
    // selected status has extra_data_type != 'none'. Older installs
    // without the Phase 95 schema fall through to the legacy SELECT
    // and have the columns synthesized as defaults below.
    try {
        $rows = db_fetch_all(
            "SELECT id, status_val, description, dispatch, watch, hide,
                    `group`, sort, bg_color, text_color, incident_action,
                    extra_data_type, extra_data_required,
                    extra_data_label, extra_data_target
               FROM `{$prefix}un_status`
              WHERE {$where}
              ORDER BY sort ASC, id ASC",
            $params
        );
    } catch (Exception $e95) {
        $rows = db_fetch_all(
            "SELECT id, status_val, description, dispatch, watch, hide,
                    `group`, sort, bg_color, text_color, incident_action
               FROM `{$prefix}un_status`
              WHERE {$where}
              ORDER BY sort ASC, id ASC",
            $params
        );
        foreach ($rows as &$r) {
            $r['extra_data_type']     = 'none';
            $r['extra_data_required'] = 0;
            $r['extra_data_label']    = null;
            $r['extra_data_target']   = 'action_log';
        }
        unset($r);
    }
    json_response(['statuses' => $rows]);
} catch (Exception $e) {
    json_error($e->getMessage(), 500);
}
