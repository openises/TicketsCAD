<?php
/**
 * NewUI v4.0 API - Call History Lookup
 *
 * GET /api/call-history.php?phone=XXX&street=XXX
 *   Searches previous incidents by phone number and/or street address.
 *   Returns matching incidents for the call history panel.
 */

require_once __DIR__ . '/auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

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
    $sql = "SELECT `t`.`id`, `t`.`scope`, `t`.`street`, `t`.`city`, `t`.`phone`,
                   `t`.`status`, `t`.`date`, `t`.`severity`,
                   `it`.`type` AS `incident_type`
            FROM `{$prefix}ticket` `t`
            LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
            WHERE ({$where})
            ORDER BY `t`.`date` DESC
            LIMIT 25";

    $rows = db_fetch_all($sql, $params);
} catch (Exception $e) {
    $rows = [];
}

$results = [];
foreach ($rows as $row) {
    $results[] = [
        'id'            => (int) $row['id'],
        'scope'         => $row['scope'],
        'street'        => $row['street'],
        'city'          => $row['city'],
        'phone'         => $row['phone'],
        'status'        => (int) $row['status'],
        'date'          => $row['date'],
        'severity'      => (int) $row['severity'],
        'incident_type' => $row['incident_type'],
    ];
}

ini_set('display_errors', $prevDisplay);

json_response(['results' => $results]);
