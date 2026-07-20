<?php
/**
 * NewUI v4.0 API - Constituents Export
 *
 * GET /api/constituents-export.php
 *   Downloads all constituents as a CSV file.
 *   Optional ?search= parameter to export filtered results.
 */

require_once __DIR__ . '/auth.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$search = trim($_GET['search'] ?? '');

// Column map: DB field => CSV header
$columns = [
    'contact'      => 'Name',
    'phone'        => 'Phone',
    'phone_type'   => 'Phone Type',
    'phone_2'      => 'Phone 2',
    'phone_2_type' => 'Phone 2 Type',
    'phone_3'      => 'Phone 3',
    'phone_3_type' => 'Phone 3 Type',
    'phone_4'      => 'Phone 4',
    'phone_4_type' => 'Phone 4 Type',
    'email'        => 'Email',
    'street'       => 'Street',
    'apartment'    => 'Apartment',
    'city'         => 'City',
    'state'        => 'State',
    'post_code'    => 'Zip',
    'community'    => 'Community',
    'miscellaneous'=> 'Notes',
    'reference'    => 'Reference',
    'lat'          => 'Latitude',
    'lng'          => 'Longitude',
];

$dbFields = array_keys($columns);
$csvHeaders = array_values($columns);
$fieldList = '`' . implode('`, `', $dbFields) . '`';

try {
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql = "SELECT {$fieldList} FROM `{$prefix}constituents`
                WHERE `contact` LIKE ? OR `phone` LIKE ? OR `phone_2` LIKE ?
                   OR `phone_3` LIKE ? OR `phone_4` LIKE ? OR `street` LIKE ?
                   OR `city` LIKE ? OR `miscellaneous` LIKE ? OR `email` LIKE ?
                ORDER BY `contact` ASC";
        $params = array_fill(0, 9, $like);
    } else {
        $sql = "SELECT {$fieldList} FROM `{$prefix}constituents` ORDER BY `contact` ASC";
        $params = [];
    }

    $rows = db_fetch_all($sql, $params);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Send CSV headers
$filename = 'constituents_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// BOM for Excel UTF-8 compatibility
fwrite($out, "\xEF\xBB\xBF");

// Header row — explicit $escape for PHP 8.4+ (deprecation 2026-06)
fputcsv($out, $csvHeaders, ',', '"', '\\');

// Data rows
foreach ($rows as $row) {
    $line = [];
    foreach ($dbFields as $field) {
        $line[] = $row[$field] ?? '';
    }
    fputcsv($out, $line, ',', '"', '\\');
}

fclose($out);
exit;
