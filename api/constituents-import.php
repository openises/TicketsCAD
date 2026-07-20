<?php
/**
 * NewUI v4.0 API - Constituents Import
 *
 * POST /api/constituents-import.php
 *   Multi-stage import process:
 *   - action=parse        : Upload & parse CSV/TSV, auto-guess column mapping
 *   - action=preview      : Check for duplicate conflicts before executing
 *   - action=execute      : Run the actual import with upsert/merge logic
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// ── Determine action ────────────────────────────────────────────
// For 'parse' the action comes via $_POST (multipart form), for others via JSON body
$action = '';
$input  = null;

if (isset($_POST['action'])) {
    $action = trim($_POST['action']);
    // CSRF from form field
    $csrf = $_POST['csrf_token'] ?? '';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        json_error('Invalid JSON body');
    }
    $action = trim($input['action'] ?? '');
    $csrf   = $input['csrf_token'] ?? '';
}

if (!csrf_verify($csrf)) {
    json_error('Invalid or expired security token. Please refresh the page.', 403);
}

// RBAC enforcement (specs/rbac-enforcement-2026-06).
// This endpoint is write-only (POST); all actions (parse/preview/execute)
// are part of a bulk import and require action.import_data.
if (!rbac_can('action.import_data')) {
    json_error('Insufficient permissions: import data', 403);
}

// ── Column alias dictionary for auto-guessing ───────────────────
$ALIAS_MAP = [
    'contact'      => ['name', 'contact', 'full name', 'fullname', 'person', 'resident', 'member', 'member name'],
    'phone'        => ['phone', 'phone number', 'phone 1', 'primary phone', 'telephone', 'tel', 'mobile', 'cell', 'cell phone'],
    'phone_type'   => ['phone type', 'phone 1 type', 'type 1', 'phone1 type'],
    'phone_2'      => ['phone 2', 'phone2', 'secondary phone', 'alternate phone', 'alt phone', 'home phone', 'work phone'],
    'phone_2_type' => ['phone 2 type', 'phone2 type', 'type 2'],
    'phone_3'      => ['phone 3', 'phone3', 'third phone'],
    'phone_3_type' => ['phone 3 type', 'phone3 type', 'type 3'],
    'phone_4'      => ['phone 4', 'phone4', 'fourth phone'],
    'phone_4_type' => ['phone 4 type', 'phone4 type', 'type 4'],
    'email'        => ['email', 'e-mail', 'email address', 'e-mail address'],
    'street'       => ['street', 'address', 'street address', 'address 1', 'address line 1'],
    'apartment'    => ['apartment', 'apt', 'unit', 'suite', 'address 2', 'address line 2', 'apt/unit'],
    'city'         => ['city', 'town', 'municipality'],
    'state'        => ['state', 'st', 'province', 'region'],
    'post_code'    => ['zip', 'zip code', 'zipcode', 'postal code', 'post code', 'postcode'],
    'community'    => ['community', 'neighborhood', 'neighbourhood', 'area', 'subdivision'],
    'miscellaneous'=> ['notes', 'warnings', 'misc', 'miscellaneous', 'comments', 'special instructions', 'remarks'],
    'reference'    => ['reference', 'ref', 'id', 'account', 'member id', 'reference id', 'ref id'],
    'lat'          => ['latitude', 'lat'],
    'lng'          => ['longitude', 'lng', 'lon', 'long'],
];

// Special: detect first/last/middle name columns
$FIRST_NAME_ALIASES  = ['first name', 'firstname', 'first', 'given name', 'fname'];
$LAST_NAME_ALIASES   = ['last name', 'lastname', 'last', 'surname', 'family name', 'lname'];
$MIDDLE_NAME_ALIASES = ['middle name', 'middlename', 'middle', 'middle initial', 'mi', 'mname'];


// ═════════════════════════════════════════════════════════════════
//  ACTION: PARSE
// ═════════════════════════════════════════════════════════════════
if ($action === 'parse') {
    // Validate file upload
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        ];
        $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        json_error($uploadErrors[$code] ?? 'Upload failed (code ' . $code . ')');
    }

    $tmpFile = $_FILES['file']['tmp_name'];
    $fileSize = $_FILES['file']['size'];
    $fileName = $_FILES['file']['name'];

    // Validate size (5MB max)
    if ($fileSize > 5 * 1024 * 1024) {
        json_error('File too large. Maximum size is 5MB.');
    }

    // Validate extension
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'tsv', 'txt'])) {
        json_error('Unsupported file type. Please upload a CSV, TSV, or TXT file.');
    }

    // Detect delimiter from first 2000 bytes
    $sample = file_get_contents($tmpFile, false, null, 0, 2000);
    // Strip BOM if present
    if (substr($sample, 0, 3) === "\xEF\xBB\xBF") {
        $sample = substr($sample, 3);
    }

    $delimiters = [
        ','  => substr_count($sample, ','),
        "\t" => substr_count($sample, "\t"),
        ';'  => substr_count($sample, ';'),
        '|'  => substr_count($sample, '|'),
    ];
    arsort($delimiters);
    $delimiter = key($delimiters);
    // If no delimiters found, default to comma
    if ($delimiters[$delimiter] === 0) {
        $delimiter = ',';
    }

    // Parse the file
    $handle = fopen($tmpFile, 'r');
    if (!$handle) {
        json_error('Failed to read uploaded file');
    }

    // Skip BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    // Read header row — explicit $escape for PHP 8.4+ (deprecation 2026-06)
    $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
    if (!$headers || count($headers) === 0) {
        fclose($handle);
        json_error('Could not read column headers from file. Is the file empty?');
    }

    // Trim whitespace from headers
    $headers = array_map('trim', $headers);

    // Read preview rows (up to 5)
    $preview = [];
    for ($i = 0; $i < 5; $i++) {
        $row = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($row === false) break;
        $preview[] = array_map('trim', $row);
    }

    // Read all data rows (cap at 5000) and store in session
    rewind($handle);
    // Skip BOM again
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    fgetcsv($handle, 0, $delimiter, '"', '\\'); // skip header

    $allRows = [];
    $rowCount = 0;
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if ($rowCount >= 5000) break;
        // Skip completely empty rows
        $joined = implode('', $row);
        if (trim($joined) === '') continue;
        $allRows[] = array_map('trim', $row);
        $rowCount++;
    }
    fclose($handle);

    // Store in session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['import_data'] = $allRows;
    $_SESSION['import_headers'] = $headers;
    $_SESSION['import_delimiter'] = $delimiter;

    // Auto-guess column mapping
    $guessedMap = guess_column_mapping($headers);

    json_response([
        'headers'     => $headers,
        'preview'     => $preview,
        'guessed_map' => $guessedMap,
        'delimiter'   => $delimiter === "\t" ? 'tab' : $delimiter,
        'total_rows'  => count($allRows),
        'file_name'   => $fileName,
    ]);
}


// ═════════════════════════════════════════════════════════════════
//  ACTION: PREVIEW (conflict detection)
// ═════════════════════════════════════════════════════════════════
if ($action === 'preview') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['import_data'])) {
        json_error('Import session expired. Please re-upload the file.');
    }

    $columnMap      = $input['column_map'] ?? [];
    $defaults       = $input['defaults'] ?? [];
    $firstNameCol   = isset($input['first_name_col']) ? (int) $input['first_name_col'] : null;
    $lastNameCol    = isset($input['last_name_col']) ? (int) $input['last_name_col'] : null;
    $middleNameCol  = isset($input['middle_name_col']) ? (int) $input['middle_name_col'] : null;
    $nameOrder      = $input['name_order'] ?? 'first_last';

    $rows = $_SESSION['import_data'];
    $conflicts = [];
    $newCount = 0;

    // Build import records from mapped columns
    foreach ($rows as $rowIdx => $row) {
        $record = map_row_to_record($row, $columnMap, $defaults, $firstNameCol, $lastNameCol, $middleNameCol, $nameOrder);

        // Skip empty rows
        if (empty($record['contact']) && empty($record['phone'])) {
            continue;
        }

        // Check for existing match
        $existing = find_existing_match($record, $prefix);

        if ($existing) {
            $conflicts[] = [
                'import_row'  => $rowIdx,
                'import_data' => $record,
                'existing'    => $existing,
            ];
        } else {
            $newCount++;
        }
    }

    json_response([
        'new_count'      => $newCount,
        'conflict_count' => count($conflicts),
        'conflicts'      => $conflicts,
        'total'          => $newCount + count($conflicts),
    ]);
}


// ═════════════════════════════════════════════════════════════════
//  ACTION: EXECUTE
// ═════════════════════════════════════════════════════════════════
if ($action === 'execute') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['import_data'])) {
        json_error('Import session expired. Please re-upload the file.');
    }

    $columnMap           = $input['column_map'] ?? [];
    $defaults            = $input['defaults'] ?? [];
    $firstNameCol        = isset($input['first_name_col']) ? (int) $input['first_name_col'] : null;
    $lastNameCol         = isset($input['last_name_col']) ? (int) $input['last_name_col'] : null;
    $middleNameCol       = isset($input['middle_name_col']) ? (int) $input['middle_name_col'] : null;
    $nameOrder           = $input['name_order'] ?? 'first_last';
    $conflictResolutions = $input['conflict_resolutions'] ?? [];
    $globalAction        = $input['global_conflict_action'] ?? 'skip';

    $rows = $_SESSION['import_data'];

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $errors   = [];

    $importableFields = [
        'contact', 'phone', 'phone_type', 'phone_2', 'phone_2_type',
        'phone_3', 'phone_3_type', 'phone_4', 'phone_4_type', 'email',
        'street', 'apartment', 'city', 'state', 'post_code',
        'community', 'miscellaneous', 'reference', 'lat', 'lng',
    ];

    foreach ($rows as $rowIdx => $row) {
        $record = map_row_to_record($row, $columnMap, $defaults, $firstNameCol, $lastNameCol, $middleNameCol, $nameOrder);

        // Validate: need at least contact or phone
        if (empty($record['contact']) && empty($record['phone'])) {
            $errors[] = ['row' => $rowIdx + 1, 'message' => 'Missing name and phone — skipped'];
            $skipped++;
            continue;
        }

        // Check for existing match
        $existing = find_existing_match($record, $prefix);

        if ($existing) {
            // Determine conflict resolution for this row
            $resolution = isset($conflictResolutions[(string) $rowIdx])
                ? $conflictResolutions[(string) $rowIdx]
                : $globalAction;

            if ($resolution === 'skip') {
                $skipped++;
                continue;
            }

            try {
                if ($resolution === 'overwrite') {
                    // Full overwrite — use all import values
                    $setClauses = [];
                    $params = [];
                    foreach ($importableFields as $f) {
                        if (isset($record[$f]) && $record[$f] !== '') {
                            $setClauses[] = "`{$f}` = ?";
                            $params[] = $record[$f];
                        }
                    }
                    $setClauses[] = '`updated` = NOW()';
                    $setClauses[] = '`_by` = ?';
                    $params[] = $current_user_id;
                    $params[] = (int) $existing['id'];

                    db_query(
                        "UPDATE `{$prefix}constituents` SET " . implode(', ', $setClauses) . " WHERE `id` = ?",
                        $params
                    );
                    $updated++;

                } elseif ($resolution === 'merge') {
                    // Merge — use import value only for fields where existing is empty
                    $setClauses = [];
                    $params = [];
                    foreach ($importableFields as $f) {
                        $importVal = isset($record[$f]) ? trim($record[$f]) : '';
                        $existingVal = isset($existing[$f]) ? trim($existing[$f]) : '';

                        if ($importVal !== '' && $existingVal === '') {
                            $setClauses[] = "`{$f}` = ?";
                            $params[] = $importVal;
                        }
                    }

                    if (!empty($setClauses)) {
                        $setClauses[] = '`updated` = NOW()';
                        $setClauses[] = '`_by` = ?';
                        $params[] = $current_user_id;
                        $params[] = (int) $existing['id'];

                        db_query(
                            "UPDATE `{$prefix}constituents` SET " . implode(', ', $setClauses) . " WHERE `id` = ?",
                            $params
                        );
                    }
                    $updated++;
                }
            } catch (Exception $e) {
                $label = trim(($record['contact'] ?? '') . ' / ' . ($record['phone'] ?? ''), ' /');
                $errors[] = [
                    'row'     => $rowIdx + 1,
                    'message' => 'Update failed: ' . $e->getMessage(),
                    'contact' => $record['contact'] ?? '',
                    'data'    => $record
                ];
            }

        } else {
            // INSERT new record
            try {
                $fields = [];
                $placeholders = [];
                $params = [];

                foreach ($importableFields as $f) {
                    if (isset($record[$f]) && $record[$f] !== '') {
                        $fields[] = "`{$f}`";
                        $placeholders[] = '?';
                        $params[] = $record[$f];
                    }
                }

                $fields[] = '`updated`';
                $placeholders[] = 'NOW()';
                $fields[] = '`_by`';
                $placeholders[] = '?';
                $params[] = $current_user_id;

                db_query(
                    "INSERT INTO `{$prefix}constituents` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")",
                    $params
                );
                $inserted++;

            } catch (Exception $e) {
                $errors[] = [
                    'row'     => $rowIdx + 1,
                    'message' => 'Insert failed: ' . $e->getMessage(),
                    'contact' => $record['contact'] ?? '',
                    'data'    => $record
                ];
            }
        }
    }

    // Clear session data
    unset($_SESSION['import_data']);
    unset($_SESSION['import_headers']);
    unset($_SESSION['import_delimiter']);

    // Log the import. Phase 73g: correct columns (who, from, when, code,
    // info). code 50 = constituents bulk-import summary.
    try {
        db_query(
            "INSERT INTO `{$prefix}log` (`who`, `from`, `when`, `code`, `info`) VALUES (?, ?, NOW(), 50, ?)",
            [
                (int) $current_user_id,
                $_SERVER['REMOTE_ADDR'] ?? '',
                "Constituents import: {$inserted} inserted, {$updated} updated, {$skipped} skipped",
            ]
        );
    } catch (Exception $e) {
        // non-fatal
    }

    json_response([
        'success'  => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ]);
}


// ── Unknown action ──────────────────────────────────────────────
json_error('Unknown action: ' . $action);


// ═════════════════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ═════════════════════════════════════════════════════════════════

/**
 * Auto-guess column mapping from CSV headers.
 * Returns: { "col_index": "db_field", ... }
 */
function guess_column_mapping($headers) {
    global $ALIAS_MAP, $FIRST_NAME_ALIASES, $LAST_NAME_ALIASES, $MIDDLE_NAME_ALIASES;

    $map = [];
    $usedFields = [];
    $firstNameIdx = null;
    $lastNameIdx = null;
    $middleNameIdx = null;
    $namePartIndices = []; // Track columns that are name parts (skip from standard matching)

    // PASS 1: Detect first/last/middle name columns BEFORE standard matching
    foreach ($headers as $idx => $header) {
        $h = strtolower(trim($header));

        foreach ($FIRST_NAME_ALIASES as $alias) {
            if ($h === $alias) {
                $firstNameIdx = $idx;
                $namePartIndices[$idx] = true;
                break;
            }
        }
        foreach ($LAST_NAME_ALIASES as $alias) {
            if ($h === $alias) {
                $lastNameIdx = $idx;
                $namePartIndices[$idx] = true;
                break;
            }
        }
        foreach ($MIDDLE_NAME_ALIASES as $alias) {
            if ($h === $alias) {
                $middleNameIdx = $idx;
                $namePartIndices[$idx] = true;
                break;
            }
        }
    }

    // PASS 2: Standard alias matching — skip columns identified as name parts
    foreach ($headers as $idx => $header) {
        if (isset($namePartIndices[$idx])) continue; // Skip first/last/middle name columns

        $h = strtolower(trim($header));

        // Check standard aliases (exact match)
        foreach ($ALIAS_MAP as $dbField => $aliases) {
            if (isset($usedFields[$dbField])) continue;

            foreach ($aliases as $alias) {
                if ($h === $alias || $h === str_replace(' ', '', $alias) || $h === str_replace(' ', '_', $alias)) {
                    $map[(string) $idx] = $dbField;
                    $usedFields[$dbField] = true;
                    break 2;
                }
            }
        }

        // Partial matching as fallback
        if (!isset($map[(string) $idx])) {
            foreach ($ALIAS_MAP as $dbField => $aliases) {
                if (isset($usedFields[$dbField])) continue;
                foreach ($aliases as $alias) {
                    if (strpos($h, $alias) !== false || strpos($alias, $h) !== false) {
                        $map[(string) $idx] = $dbField;
                        $usedFields[$dbField] = true;
                        break 2;
                    }
                }
            }
        }
    }

    // If first+last name detected and no contact column already mapped, flag for concatenation
    if ($firstNameIdx !== null && $lastNameIdx !== null && !isset($usedFields['contact'])) {
        $map['_first_name_col'] = $firstNameIdx;
        $map['_last_name_col'] = $lastNameIdx;
        if ($middleNameIdx !== null) {
            $map['_middle_name_col'] = $middleNameIdx;
        }
    } elseif ($firstNameIdx !== null && !isset($usedFields['contact'])) {
        // Just first name → map directly to contact
        $map[(string) $firstNameIdx] = 'contact';
        $usedFields['contact'] = true;
        // If middle name also present, note it for possible concatenation
        if ($middleNameIdx !== null) {
            $map['_middle_name_col'] = $middleNameIdx;
        }
    }

    return $map;
}

/**
 * Map a CSV row to a constituent record using the column map.
 */
function map_row_to_record($row, $columnMap, $defaults, $firstNameCol, $lastNameCol, $middleNameCol = null, $nameOrder = 'first_last') {
    $record = [];

    foreach ($columnMap as $colIdx => $dbField) {
        // Skip internal flags
        if (strpos($colIdx, '_') === 0) continue;

        $idx = (int) $colIdx;
        $value = isset($row[$idx]) ? trim($row[$idx]) : '';
        if ($value !== '') {
            $record[$dbField] = $value;
        }
    }

    // Handle first+last+middle name concatenation
    if ($firstNameCol !== null && $lastNameCol !== null) {
        $first  = isset($row[$firstNameCol]) ? trim($row[$firstNameCol]) : '';
        $last   = isset($row[$lastNameCol]) ? trim($row[$lastNameCol]) : '';
        $middle = ($middleNameCol !== null && isset($row[$middleNameCol])) ? trim($row[$middleNameCol]) : '';

        // Build name parts based on order preference
        $parts = [];
        if ($nameOrder === 'last_first') {
            // "Last, First Middle" format
            if ($last !== '') $parts[] = $last . ',';
            if ($first !== '') $parts[] = $first;
            if ($middle !== '') $parts[] = $middle;
        } else {
            // "First Middle Last" format (default)
            if ($first !== '') $parts[] = $first;
            if ($middle !== '') $parts[] = $middle;
            if ($last !== '') $parts[] = $last;
        }

        $full = trim(implode(' ', $parts));
        if ($full !== '' && empty($record['contact'])) {
            $record['contact'] = $full;
        }
    }

    // Apply defaults for missing fields
    foreach ($defaults as $field => $defaultVal) {
        $defaultVal = trim($defaultVal);
        if ($defaultVal !== '' && (empty($record[$field]) || trim($record[$field]) === '')) {
            $record[$field] = $defaultVal;
        }
    }

    return $record;
}

/**
 * Find an existing constituent that matches by name+phone or name+email.
 * Returns the existing record or null.
 */
function find_existing_match($record, $prefix) {
    $name = strtolower(trim($record['contact'] ?? ''));
    if ($name === '') return null;

    $phone = normalize_phone($record['phone'] ?? '');
    $email = strtolower(trim($record['email'] ?? ''));

    // Must have at least name + one identifier
    if ($phone === '' && $email === '') return null;

    $conditions = [];
    $params = [];

    // Always require name match
    $conditions[] = 'LOWER(TRIM(`contact`)) = ?';
    $params[] = $name;

    // Build phone/email OR condition
    $idConditions = [];

    if ($phone !== '') {
        // Match against all 4 phone fields (digits only)
        $idConditions[] = "(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone`, '-', ''), '(', ''), ')', ''), ' ', ''), '.', '') LIKE ?)";
        $params[] = '%' . $phone;
        $idConditions[] = "(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone_2`, '-', ''), '(', ''), ')', ''), ' ', ''), '.', '') LIKE ?)";
        $params[] = '%' . $phone;
        $idConditions[] = "(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone_3`, '-', ''), '(', ''), ')', ''), ' ', ''), '.', '') LIKE ?)";
        $params[] = '%' . $phone;
        $idConditions[] = "(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone_4`, '-', ''), '(', ''), ')', ''), ' ', ''), '.', '') LIKE ?)";
        $params[] = '%' . $phone;
    }

    if ($email !== '') {
        $idConditions[] = '(LOWER(TRIM(`email`)) = ?)';
        $params[] = $email;
    }

    if (empty($idConditions)) return null;

    $sql = "SELECT * FROM `{$prefix}constituents`
            WHERE (" . $conditions[0] . ") AND (" . implode(' OR ', $idConditions) . ")
            LIMIT 1";

    try {
        return db_fetch_one($sql, $params);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Normalize a phone number to digits only. Returns empty string if < 7 digits.
 */
function normalize_phone($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    return (strlen($digits) >= 7) ? $digits : '';
}
