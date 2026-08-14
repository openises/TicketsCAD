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
//
// GH#52 (2026-08-14) — SELECT * (matching api/responders.php's own
// statusOptions query, the one place this pattern already worked
// correctly for both slots) so a pre-GH#52 install missing the _2
// columns entirely doesn't need a THIRD nested try/catch tier; the ??
// fallback below covers both "column never existed" and "row predates
// the migration".
try {
    $rows = db_fetch_all("SELECT * FROM `{$prefix}un_status` ORDER BY `id`");
    foreach ($rows as &$r) {
        $r['extra_data_type']       = $r['extra_data_type']       ?? 'none';
        $r['extra_data_required']   = (int) ($r['extra_data_required']   ?? 0);
        $r['extra_data_label']      = $r['extra_data_label']      ?? null;
        $r['extra_data_target']     = $r['extra_data_target']     ?? 'action_log';
        $r['extra_data_type_2']     = $r['extra_data_type_2']     ?? 'none';
        $r['extra_data_required_2'] = (int) ($r['extra_data_required_2'] ?? 0);
        $r['extra_data_label_2']    = $r['extra_data_label_2']    ?? null;
        $r['extra_data_target_2']   = $r['extra_data_target_2']   ?? 'action_log';
    }
    unset($r);
} catch (Exception $e) {
    json_error('Database error: ' . $e->getMessage(), 500);
}

json_response([
    'statuses' => $rows,
    'count'    => count($rows),
]);
