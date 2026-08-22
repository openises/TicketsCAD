<?php
/**
 * NewUI v4.0 API - Call History Lookup
 *
 * GET /api/call-history.php?phone=XXX&street=XXX
 *   Searches previous incidents by phone number and/or street address.
 *   Returns matching incidents for the call history panel.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

// Phase 149 (2026-08-22, plan.md §5) — same retrofit as
// api/constituents.php: this endpoint had NO permission check beyond
// being logged in. Gated on the whole response; disposition is treated
// as part of "history" (success criterion #6: "type/date/disposition")
// and travels with the same gate, while clinical/patient detail is a
// SEPARATE, more restrictive permission (field.patient_history) --
// dropped from the JSON entirely when absent, never merely hidden
// client-side. Default grants preserve Dispatcher/Operator/Org Admin/
// Super Admin's existing access exactly (tests/test_inbound_calls_rbac.php).
if (!rbac_can('field.caller_history')) {
    json_error('Insufficient permissions: view caller history', 403);
}
$canViewPatientHistory = rbac_can('field.patient_history');

$prefix = $GLOBALS['db_prefix'] ?? '';
$phone  = trim($_GET['phone'] ?? '');
$street = trim($_GET['street'] ?? '');

if ($phone === '' && $street === '') {
    json_response(['results' => []]);
}

$conditions = [];
$params = [];

// Phone match — strip non-digits for comparison
if ($phone !== '') {
    $phoneDigits = preg_replace('/\D/', '', $phone);
    if (strlen($phoneDigits) >= 4) {
        $conditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(`t`.`phone`, '-', ''), '(', ''), ')', ''), ' ', '') LIKE ?";
        $params[] = '%' . $phoneDigits . '%';
    }
}

// Street match — partial match
if ($street !== '') {
    $conditions[] = "`t`.`street` LIKE ?";
    $params[] = '%' . $street . '%';
}

if (empty($conditions)) {
    json_response(['results' => []]);
}

$where = implode(' OR ', $conditions);

try {
    // Soft-delete sweep (issue #25 follow-up) — call history is a live
    // dispatch tool; a soft-deleted incident shouldn't resurface here.
    // Phase 149: LEFT JOIN ticket_disposition for the disposition label
    // (success criterion #6 -- "type/date/disposition" travel together
    // under field.caller_history). Guarded: an install that hasn't run
    // Phase 132's migration yet has no `disposition_id` column or
    // `ticket_disposition` table -- caught below and re-queried without
    // the join, matching this project's schema-resilience convention.
    $sql = "SELECT `t`.`id`, `t`.`scope`, `t`.`street`, `t`.`city`, `t`.`phone`,
                   `t`.`status`, `t`.`date`, `t`.`severity`,
                   `it`.`type` AS `incident_type`,
                   `d`.`status_val` AS `disposition`
            FROM `{$prefix}ticket` `t`
            LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
            LEFT JOIN `{$prefix}ticket_disposition` `d` ON `t`.`disposition_id` = `d`.`id`
            WHERE ({$where})
              AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')
            ORDER BY `t`.`date` DESC
            LIMIT 25";

    $rows = db_fetch_all($sql, $params);
} catch (Exception $e) {
    try {
        $sql = "SELECT `t`.`id`, `t`.`scope`, `t`.`street`, `t`.`city`, `t`.`phone`,
                       `t`.`status`, `t`.`date`, `t`.`severity`,
                       `it`.`type` AS `incident_type`, NULL AS `disposition`
                FROM `{$prefix}ticket` `t`
                LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
                WHERE ({$where})
                  AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')
                ORDER BY `t`.`date` DESC
                LIMIT 25";
        $rows = db_fetch_all($sql, $params);
    } catch (Exception $e2) {
        $rows = [];
    }
}

$results = [];
foreach ($rows as $row) {
    $entry = [
        'id'            => (int) $row['id'],
        'scope'         => $row['scope'],
        'street'        => $row['street'],
        'city'          => $row['city'],
        'phone'         => $row['phone'],
        'status'        => (int) $row['status'],
        'date'          => $row['date'],
        'severity'      => (int) $row['severity'],
        'incident_type' => $row['incident_type'],
        'disposition'   => $row['disposition'] ?? null,
    ];

    // field.patient_history: clinical/patient detail nested inside this
    // history, dropped entirely from the JSON when the caller lacks the
    // permission (never merely hidden client-side, per plan.md §5).
    if ($canViewPatientHistory) {
        try {
            $patients = db_fetch_all(
                "SELECT `name`, `fullname`, `gender`, `description`
                   FROM `{$prefix}patient` WHERE `ticket_id` = ?",
                [(int) $row['id']]
            );
        } catch (Exception $e) {
            $patients = [];
        }
        if (!empty($patients)) {
            $entry['patients'] = array_map(function ($p) {
                return [
                    'name'        => $p['name'],
                    'fullname'    => $p['fullname'],
                    'gender'      => (int) $p['gender'],
                    'description' => $p['description'],
                ];
            }, $patients);
        }
    }

    $results[] = $entry;
}

ini_set('display_errors', $prevDisplay);

json_response(['results' => $results]);
