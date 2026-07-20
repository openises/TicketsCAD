<?php
/**
 * NewUI v4.0 API - Import/Export
 *
 * GET  /api/import-export.php?targets=1           — List supported table targets
 * GET  /api/import-export.php?config=member        — Get column config for a target
 * GET  /api/import-export.php?export=member        — Export table as CSV download
 * POST /api/import-export.php action=preview        — Upload CSV, get preview + auto-mapped columns
 * POST /api/import-export.php action=import          — Execute import with confirmed column mapping
 *
 * Admin-only (level <= 1).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/import-export.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

// Admin only
if (!is_admin()) {
    json_error('Admin access required — import/export is restricted to administrators', 403);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleImExGet();
} elseif ($method === 'POST') {
    // CSRF (F-008) — verify before any data is read or written.
    // Multipart POSTs send the token as a form field; JSON bodies in body.
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    if ($token === '') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $token = $body['csrf_token'] ?? '';
        }
    }
    if (!csrf_verify((string) $token)) {
        json_error('Invalid CSRF token', 403);
    }
    handleImExPost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

function handleImExGet() {
    // List targets
    if (isset($_GET['targets'])) {
        json_response(['targets' => get_supported_targets()]);
    }

    // Get config for a target
    if (!empty($_GET['config'])) {
        $target = $_GET['config'];
        $config = get_table_config($target);
        if (empty($config)) json_error('Unknown target: ' . $target, 400);

        // Return importable and exportable columns
        $importable = [];
        $exportable = [];
        foreach ($config['columns'] as $dbCol => $def) {
            if ($def['import']) {
                $importable[] = [
                    'db_column' => $dbCol,
                    'label'     => $def['label'],
                    'type'      => $def['type'],
                    'required'  => $def['required'],
                ];
            }
            if ($def['export']) {
                $exportable[] = [
                    'db_column' => $dbCol,
                    'label'     => $def['label'],
                ];
            }
        }

        json_response([
            'target'       => $target,
            'label'        => $config['label'],
            'match_columns' => $config['match_columns'],
            'importable'   => $importable,
            'exportable'   => $exportable,
        ]);
    }

    // Export as CSV download
    if (!empty($_GET['export'])) {
        $target = $_GET['export'];
        $config = get_table_config($target);
        if (empty($config)) json_error('Unknown target: ' . $target, 400);

        $filters = [];
        if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

        $csv = export_csv($config, $filters);
        if (empty($csv)) json_error('Export failed — no data or table error');

        $filename = $target . '_export_' . date('Ymd_His') . '.csv';
        $rowCount = max(0, substr_count($csv, "\n") - 1); // subtract header row
        audit_log('data', 'export', $target, null, "Exported {$rowCount} {$target} records as CSV", [
            'filename' => $filename,
            'search'   => $filters['search'] ?? null,
            'rows'     => $rowCount,
        ]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    json_error('Missing parameter: targets, config, or export');
}

function handleImExPost() {
    global $current_user_id;

    // Check Content-Type — could be JSON or multipart/form-data
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (strpos($contentType, 'multipart/form-data') !== false) {
        // File upload for preview
        return handleFileUpload();
    }

    // JSON body for import execution
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    $action = $input['action'] ?? '';

    if ($action === 'preview') {
        // Preview from already-uploaded CSV data (base64 encoded)
        $target = $input['target'] ?? '';
        $csvData = $input['csv_data'] ?? '';
        if (!$target || !$csvData) json_error('Missing target or csv_data');

        $config = get_table_config($target);
        if (empty($config)) json_error('Unknown target: ' . $target, 400);

        // Decode if base64
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $csvData)) {
            $decoded = base64_decode($csvData);
            if ($decoded !== false) $csvData = $decoded;
        }

        $parsed = parse_csv_string($csvData);
        $autoMap = auto_map_columns($parsed['headers'], $config);
        $validation = validate_import($parsed['rows'], $autoMap, $config);

        json_response([
            'headers'    => $parsed['headers'],
            'row_count'  => $parsed['row_count'],
            'preview'    => array_slice($parsed['rows'], 0, 10),
            'auto_map'   => $autoMap,
            'validation' => [
                'valid_count'   => count($validation['valid']),
                'error_count'   => count($validation['errors']),
                'warning_count' => count($validation['warnings']),
                'errors'        => array_slice($validation['errors'], 0, 20),
                'warnings'      => array_slice($validation['warnings'], 0, 20),
                'error_rows'    => array_slice($validation['error_rows'] ?? [], 0, 50),
            ],
        ]);
    }

    if ($action === 'import') {
        $target    = $input['target'] ?? '';
        $csvData   = $input['csv_data'] ?? '';
        $columnMap = $input['column_map'] ?? [];
        $mode      = $input['mode'] ?? 'insert'; // 'insert' or 'upsert'

        if (!$target || !$csvData || empty($columnMap)) {
            json_error('Missing target, csv_data, or column_map');
        }

        $config = get_table_config($target);
        if (empty($config)) json_error('Unknown target: ' . $target, 400);

        // Decode base64
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $csvData)) {
            $decoded = base64_decode($csvData);
            if ($decoded !== false) $csvData = $decoded;
        }

        $parsed = parse_csv_string($csvData);
        $validation = validate_import($parsed['rows'], $columnMap, $config);

        if (count($validation['valid']) === 0) {
            json_error('No valid rows to import. Errors: ' . implode('; ', array_slice($validation['errors'], 0, 5)));
        }

        $result = execute_import($validation['valid'], $config, $current_user_id, $mode);

        audit_log('data', 'import', $target, null,
            "Imported {$target}: {$result['inserted']} inserted, {$result['updated']} updated, {$result['skipped']} skipped" .
            (count($result['errors']) > 0 ? ', ' . count($result['errors']) . ' errors' : ''),
            [
                'mode'     => $mode,
                'total'    => $parsed['row_count'],
                'valid'    => count($validation['valid']),
                'inserted' => $result['inserted'],
                'updated'  => $result['updated'],
                'skipped'  => $result['skipped'],
                'errors'   => count($result['errors']),
            ],
            count($result['errors']) > 0 ? AUDIT_MEDIUM : AUDIT_INFO
        );

        json_response([
            'success'  => true,
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
            'skipped'  => $result['skipped'],
            'errors'   => $result['errors'],
            'total_rows'     => $parsed['row_count'],
            'valid_rows'     => count($validation['valid']),
            'invalid_rows'   => count($validation['errors']),
            'validation_errors'   => array_slice($validation['errors'], 0, 20),
            'validation_warnings' => array_slice($validation['warnings'], 0, 20),
        ]);
    }

    // Import manually-corrected rows (pre-mapped DB columns, from error editor)
    if ($action === 'import_fixed') {
        $target  = $input['target'] ?? '';
        $fixedRows = $input['rows'] ?? [];
        $mode    = $input['mode'] ?? 'insert';

        if (!$target || empty($fixedRows)) {
            json_error('Missing target or rows');
        }

        $config = get_table_config($target);
        if (empty($config)) json_error('Unknown target: ' . $target, 400);

        $result = execute_import($fixedRows, $config, $current_user_id, $mode);

        audit_log('data', 'import', $target, null,
            "Imported " . count($fixedRows) . " manually-fixed {$target} rows: {$result['inserted']} inserted, {$result['updated']} updated",
            ['mode' => $mode, 'fixed_rows' => count($fixedRows), 'inserted' => $result['inserted'], 'updated' => $result['updated']]
        );

        json_response([
            'success'  => true,
            'inserted' => $result['inserted'],
            'updated'  => $result['updated'],
            'skipped'  => $result['skipped'],
            'errors'   => $result['errors'],
        ]);
    }

    json_error('Unknown action: ' . $action);
}

function handleFileUpload() {
    $target = $_POST['target'] ?? '';
    if (!$target) json_error('Missing target');

    $config = get_table_config($target);
    if (empty($config)) json_error('Unknown target: ' . $target, 400);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCodes = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file uploaded',
        ];
        $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        json_error($errCodes[$code] ?? 'File upload failed (error ' . $code . ')');
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'tsv'])) {
        json_error('Only CSV files are supported (.csv, .txt, .tsv)');
    }

    $csvData = file_get_contents($file['tmp_name']);
    if ($csvData === false || strlen($csvData) === 0) {
        json_error('File is empty');
    }

    // Parse and auto-map
    $parsed = parse_csv_string($csvData);
    if ($parsed['row_count'] === 0) {
        json_error('CSV file contains no data rows');
    }

    $autoMap = auto_map_columns($parsed['headers'], $config);
    $validation = validate_import($parsed['rows'], $autoMap, $config);

    json_response([
        'headers'    => $parsed['headers'],
        'row_count'  => $parsed['row_count'],
        'preview'    => array_slice($parsed['rows'], 0, 10),
        'auto_map'   => $autoMap,
        'csv_data'   => base64_encode($csvData), // Send back for later import execution
        'validation' => [
            'valid_count'   => count($validation['valid']),
            'error_count'   => count($validation['errors']),
            'warning_count' => count($validation['warnings']),
            'errors'        => array_slice($validation['errors'], 0, 20),
            'warnings'      => array_slice($validation['warnings'], 0, 20),
            'error_rows'    => array_slice($validation['error_rows'] ?? [], 0, 50),
        ],
    ]);
}
