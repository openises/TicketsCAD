<?php
/**
 * NewUI v4.0 API - Callsign & License Lookup
 *
 * Supports multiple backends (configurable in Settings > Integrations):
 *   disabled    — Lookups disabled
 *   local       — Local MySQL tables (fcc_amateur / fcc_gmrs)
 *   callook     — callook.info public API (internet required)
 *   fcc_uls_api — Self-hosted FCC-ULS-API (Flask, https://github.com/porcej/FCC-ULS-API)
 *
 * GET ?action=callsign&q=W1AW
 *   Returns amateur radio license data for a callsign
 *
 * GET ?action=gmrs&last_name=Smith&zip=90210
 *   Returns GMRS license matches by name + zip
 *
 * GET ?action=config
 *   Returns current lookup provider configuration
 *
 * POST action=config  { provider, fcc_uls_api_url }
 *   Save lookup provider configuration (admin only)
 */

require_once __DIR__ . '/auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// ── Load config ─────────────────────────────────────────────
function getLookupConfig() {
    global $prefix;
    $defaults = [
        'callsign_provider'  => 'callook',     // disabled | local | callook | fcc_uls_api
        'fcc_uls_api_url'    => 'http://localhost:5000',
        'callook_timeout'    => 5,
        'fcc_uls_api_timeout'=> 5,
    ];

    try {
        $rows = db_fetch_all(
            "SELECT `key`, `value` FROM `{$GLOBALS['db_prefix']}config`
             WHERE `key` LIKE 'lookup_%'"
        );
        $config = $defaults;
        foreach ($rows as $r) {
            $k = str_replace('lookup_', '', $r['key']);
            if (isset($config[$k])) {
                $config[$k] = $r['value'];
            }
        }
        return $config;
    } catch (Exception $e) {
        // Config table or keys don't exist yet — use defaults
        return $defaults;
    }
}

function saveLookupConfig($key, $value) {
    $fullKey = 'lookup_' . $key;
    try {
        // Ensure config table exists
        db_query("CREATE TABLE IF NOT EXISTS `{$GLOBALS['db_prefix']}config` (
            `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
            `value` TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_query(
            "INSERT INTO `{$GLOBALS['db_prefix']}config` (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = ?",
            [$fullKey, $value, $value]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ══════════════════════════════════════════════════════════════
// ACTION: callsign — Look up amateur radio callsign
// ══════════════════════════════════════════════════════════════
if ($action === 'callsign') {
    $callsign = strtoupper(trim($_GET['q'] ?? ''));
    if (empty($callsign) || !preg_match('/^[A-Z0-9]{3,8}$/', $callsign)) {
        json_error('Invalid callsign format');
    }

    $config   = getLookupConfig();
    $provider = $config['callsign_provider'];

    if ($provider === 'disabled') {
        json_response(['provider' => 'disabled', 'message' => 'Callsign lookup is disabled']);
    }

    $result = null;

    // Try primary provider
    switch ($provider) {
        case 'local':
            $result = lookupLocalAmateur($callsign);
            break;
        case 'callook':
            $result = lookupCallook($callsign, (int) $config['callook_timeout']);
            break;
        case 'fcc_uls_api':
            $result = lookupFccUlsApi($callsign, $config['fcc_uls_api_url'], (int) $config['fcc_uls_api_timeout']);
            break;
        default:
            break;
    }

    // If primary fails and there's a fallback, try it
    if ($result === null && $provider !== 'callook') {
        // Fall back to local DB if available
        $result = lookupLocalAmateur($callsign);
    }

    if ($result === null) {
        ini_set('display_errors', $prevDisplay);
        json_response([
            'found'    => false,
            'callsign' => $callsign,
            'provider' => $provider,
            'message'  => 'No record found',
        ]);
    }

    $result['found']    = true;
    $result['callsign'] = $callsign;
    $result['provider'] = $provider;

    ini_set('display_errors', $prevDisplay);
    json_response($result);
}

// ══════════════════════════════════════════════════════════════
// ACTION: gmrs — Look up GMRS license by name + zip
// ══════════════════════════════════════════════════════════════
elseif ($action === 'gmrs') {
    $lastName  = trim($_GET['last_name'] ?? '');
    $firstName = trim($_GET['first_name'] ?? '');
    $zip       = trim($_GET['zip'] ?? '');

    if (empty($lastName)) {
        json_error('Last name is required');
    }

    $config   = getLookupConfig();
    $provider = $config['callsign_provider'];

    if ($provider === 'disabled') {
        json_response(['provider' => 'disabled', 'results' => [], 'message' => 'Lookup disabled']);
    }

    $results = [];

    // Try local DB first (always — GMRS only works with local data or FCC-ULS-API)
    $results = lookupLocalGmrs($lastName, $firstName, $zip);

    // If no local results and FCC-ULS-API is configured, try that
    if (empty($results) && $provider === 'fcc_uls_api') {
        $results = lookupFccUlsApiByName($lastName, $zip, $config['fcc_uls_api_url'], (int) $config['fcc_uls_api_timeout']);
    }

    ini_set('display_errors', $prevDisplay);
    json_response([
        'results'  => $results,
        'count'    => count($results),
        'provider' => empty($results) ? 'none' : $provider,
    ]);
}

// ══════════════════════════════════════════════════════════════
// ACTION: config — Get or save lookup configuration
// ══════════════════════════════════════════════════════════════
elseif ($action === 'config') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $config = getLookupConfig();

        // Check which backends are available
        $localAvailable = false;
        try {
            $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}fcc_amateur`");
            $localAvailable = (int) $count > 0;
        } catch (Exception $e) {
            // Intentionally empty — table may not exist in this installation
        }

        $gmrsAvailable = false;
        try {
            $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}fcc_gmrs`");
            $gmrsAvailable = (int) $count > 0;
        } catch (Exception $e) {
            // Intentionally empty — table may not exist in this installation
        }

        // Check if zip codes are available
        $zipAvailable = false;
        try {
            $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}zipcodes`");
            $zipAvailable = (int) $count > 0;
        } catch (Exception $e) {
            // Intentionally empty — table may not exist in this installation
        }

        ini_set('display_errors', $prevDisplay);
        json_response([
            'config'         => $config,
            'local_amateur'  => $localAvailable,
            'local_amateur_count' => $localAvailable ? (int) $count : 0,
            'local_gmrs'     => $gmrsAvailable,
            'local_zipcodes' => $zipAvailable,
        ]);
    }

    // POST — save config (admin only)
    $userLevel = (int) ($_SESSION['level'] ?? 99);
    if ($userLevel > 1) {
        json_error('Admin access required', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON');
    }

    // CSRF (F-009) — admin POST that saves a server-fetched URL must be
    // tamper-proof. Reject before storing anything.
    if (!csrf_verify((string) ($input['csrf_token'] ?? ''))) {
        json_error('Invalid CSRF token', 403);
    }

    $allowed = ['callsign_provider', 'fcc_uls_api_url', 'callook_timeout', 'fcc_uls_api_timeout'];
    $saved   = 0;

    foreach ($allowed as $key) {
        if (isset($input[$key])) {
            // Validate provider
            if ($key === 'callsign_provider') {
                $valid = ['disabled', 'local', 'callook', 'fcc_uls_api'];
                if (!in_array($input[$key], $valid)) {
                    continue;
                }
            }
            // Validate fcc_uls_api_url — must be http(s) and not target internal
            // services (basic SSRF defense; admins can still point at LAN hosts
            // if they really mean to, but link-local / metadata is rejected).
            if ($key === 'fcc_uls_api_url') {
                $url = trim((string) $input[$key]);
                if ($url === '') { continue; }
                $parts = parse_url($url);
                if (!$parts
                    || !isset($parts['scheme'], $parts['host'])
                    || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                    json_error('fcc_uls_api_url must be a valid http(s) URL', 400);
                }
                $host = strtolower($parts['host']);
                $blockedHosts = ['169.254.169.254', 'metadata.google.internal', 'metadata'];
                if (in_array($host, $blockedHosts, true)) {
                    json_error('fcc_uls_api_url targets a blocked host', 400);
                }
            }
            if (saveLookupConfig($key, $input[$key])) {
                $saved++;
            }
        }
    }

    ini_set('display_errors', $prevDisplay);
    json_response(['success' => true, 'saved' => $saved]);
}

else {
    ini_set('display_errors', $prevDisplay);
    json_error('Unknown action. Valid: callsign, gmrs, config');
}


// ═══════════════════════════════════════════════════════════════
// PROVIDER FUNCTIONS
// ═══════════════════════════════════════════════════════════════

/**
 * Look up amateur callsign in local fcc_amateur table
 */
function lookupLocalAmateur($callsign) {
    global $prefix;
    try {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}fcc_amateur` WHERE `callsign` = ? LIMIT 1",
            [$callsign]
        );
        if (!$row) {
            return null;
        }

        return [
            'first_name'     => $row['first_name'] ?? '',
            'last_name'      => $row['last_name'] ?? '',
            'middle_initial' => $row['middle_initial'] ?? '',
            'suffix'         => $row['suffix'] ?? '',
            'entity_name'    => $row['entity_name'] ?? '',
            'entity_type'    => $row['entity_type'] ?? '',
            'oper_class'     => $row['oper_class'] ?? '',
            'street'         => $row['street'] ?? '',
            'city'           => $row['city'] ?? '',
            'state'          => $row['state'] ?? '',
            'zip'            => $row['zip'] ?? '',
            'frn'            => $row['frn'] ?? '',
            'grant_date'     => $row['grant_date'] ?? '',
            'expiry_date'    => $row['expiry_date'] ?? '',
            'last_action'    => $row['last_action'] ?? '',
            'grid_square'    => $row['grid_square'] ?? '',
            'lat'            => $row['lat'] ?? null,
            'lng'            => $row['lng'] ?? null,
        ];
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Look up callsign via callook.info public API
 */
function lookupCallook($callsign, $timeout = 5) {
    $url = 'https://callook.info/' . urlencode($callsign) . '/json';

    $ctx = stream_context_create([
        'http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'user_agent'    => 'NewUI-CAD/4.0 (callsign-lookup)',
        ],
        'ssl' => [
            'verify_peer' => false,  // Some XAMPP setups lack CA certs
        ],
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!$data || ($data['status'] ?? '') !== 'VALID') {
        return null;
    }

    $fullName = $data['name'] ?? '';
    $nameParts = _parseCallookName($data['type'] ?? '', $fullName);
    $addrParts = _parseCallookAddress($data['address'] ?? []);
    $loc = $data['location'] ?? [];

    return [
        'first_name'     => $nameParts['first_name'],
        'last_name'      => $nameParts['last_name'],
        'middle_initial' => $nameParts['middle_initial'],
        'suffix'         => '',
        'entity_name'    => ($data['type'] !== 'PERSON') ? $fullName : '',
        'entity_type'    => ($data['type'] === 'PERSON') ? 'I' : 'C',
        'oper_class'     => $data['current']['operClass'] ?? '',
        'street'         => $addrParts['street'],
        'city'           => $addrParts['city'],
        'state'          => $addrParts['state'],
        'zip'            => $addrParts['zip'],
        'frn'            => $data['otherInfo']['frn'] ?? '',
        'grant_date'     => parseApiDate($data['otherInfo']['grantDate'] ?? ''),
        'expiry_date'    => parseApiDate($data['otherInfo']['expiryDate'] ?? ''),
        'last_action'    => parseApiDate($data['otherInfo']['lastActionDate'] ?? ''),
        'grid_square'    => $loc['gridsquare'] ?? '',
        'lat'            => isset($loc['latitude']) ? (float) $loc['latitude'] : null,
        'lng'            => isset($loc['longitude']) ? (float) $loc['longitude'] : null,
    ];
}

/**
 * Parse callook.info name field into first/last/middle components.
 * Callook returns "LAST, FIRST MIDDLE" for persons.
 */
function _parseCallookName($type, $fullName) {
    $result = ['first_name' => '', 'last_name' => '', 'middle_initial' => ''];

    if ($type !== 'PERSON' || empty($fullName)) {
        return $result;
    }

    // Try "Last, First Middle" format
    $parts = explode(', ', $fullName, 2);
    if (count($parts) === 2) {
        $result['last_name'] = trim($parts[0]);
        $givenParts = explode(' ', trim($parts[1]));
        $result['first_name'] = isset($givenParts[0]) ? $givenParts[0] : '';
        if (isset($givenParts[1])) {
            $result['middle_initial'] = substr($givenParts[1], 0, 1);
        }
        return $result;
    }

    // Try "First Last" format
    $parts = explode(' ', $fullName);
    if (count($parts) >= 2) {
        $result['first_name'] = $parts[0];
        $result['last_name'] = end($parts);
        if (count($parts) > 2) {
            $result['middle_initial'] = substr($parts[1], 0, 1);
        }
    }

    return $result;
}

/**
 * Parse callook.info address fields into street/city/state/zip.
 */
function _parseCallookAddress($addr) {
    $result = ['street' => '', 'city' => '', 'state' => '', 'zip' => ''];

    if (!empty($addr['line1'])) {
        $result['street'] = $addr['line1'];
    }
    if (!empty($addr['line2'])) {
        // "CITY, ST ZIP" format
        if (preg_match('/^(.+),\s*([A-Z]{2})\s+(\d{5}(-\d{4})?)$/', $addr['line2'], $m)) {
            $result['city'] = $m[1];
            $result['state'] = $m[2];
            $result['zip'] = $m[3];
        }
    }

    return $result;
}

/**
 * Look up callsign via self-hosted FCC-ULS-API (Flask)
 */
function lookupFccUlsApi($callsign, $baseUrl, $timeout = 5) {
    $url = rtrim($baseUrl, '/') . '/api/callsign/' . urlencode($callsign);

    $ctx = stream_context_create([
        'http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'user_agent'    => 'NewUI-CAD/4.0',
        ],
        'ssl' => [
            'verify_peer' => false,
        ],
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!$data || !($data['success'] ?? false)) {
        return null;
    }

    $licenses = $data['licenses'] ?? [];
    if (empty($licenses)) {
        return null;
    }

    $lic = $licenses[0]; // First match

    return [
        'first_name'     => $lic['first_name'] ?? '',
        'last_name'      => $lic['last_name'] ?? '',
        'middle_initial' => $lic['middle_initial'] ?? '',
        'suffix'         => $lic['suffix'] ?? '',
        'entity_name'    => $lic['entity_name'] ?? $lic['full_name'] ?? '',
        'entity_type'    => $lic['entity_type'] ?? '',
        'oper_class'     => $lic['operator_class'] ?? $lic['oper_class'] ?? '',
        'street'         => $lic['street'] ?? $lic['address'] ?? '',
        'city'           => $lic['city'] ?? '',
        'state'          => $lic['state'] ?? '',
        'zip'            => $lic['zip_code'] ?? $lic['zip'] ?? '',
        'frn'            => $lic['frn'] ?? '',
        'grant_date'     => $lic['grant_date'] ?? '',
        'expiry_date'    => $lic['expiry_date'] ?? $lic['expired_date'] ?? '',
        'last_action'    => $lic['last_action_date'] ?? '',
        'grid_square'    => $lic['grid_square'] ?? '',
        'lat'            => isset($lic['latitude']) ? (float) $lic['latitude'] : null,
        'lng'            => isset($lic['longitude']) ? (float) $lic['longitude'] : null,
    ];
}

/**
 * Look up GMRS license by name + zip in local fcc_gmrs table
 */
function lookupLocalGmrs($lastName, $firstName, $zip) {
    global $prefix;

    $where  = ['UPPER(`last_name`) = ?'];
    $params = [strtoupper($lastName)];

    if (!empty($firstName)) {
        $where[]  = 'UPPER(`first_name`) LIKE ?';
        $params[] = strtoupper($firstName) . '%';
    }
    if (!empty($zip)) {
        $where[]  = '`zip` LIKE ?';
        $params[] = $zip . '%'; // Match zip prefix (5-digit from 9-digit)
    }

    try {
        $rows = db_fetch_all(
            "SELECT * FROM `{$prefix}fcc_gmrs`
             WHERE " . implode(' AND ', $where) . "
             ORDER BY `last_name`, `first_name`
             LIMIT 20",
            $params
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'callsign'    => $row['callsign'] ?? '',
                'first_name'  => $row['first_name'] ?? '',
                'last_name'   => $row['last_name'] ?? '',
                'city'        => $row['city'] ?? '',
                'state'       => $row['state'] ?? '',
                'zip'         => $row['zip'] ?? '',
                'grant_date'  => $row['grant_date'] ?? '',
                'expiry_date' => $row['expiry_date'] ?? '',
                'frn'         => $row['frn'] ?? '',
            ];
        }
        return $results;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Look up by name via FCC-ULS-API (zipcode endpoint with name filter)
 */
function lookupFccUlsApiByName($lastName, $zip, $baseUrl, $timeout = 5) {
    if (empty($zip)) {
        return [];
    }

    $url = rtrim($baseUrl, '/') . '/api/zipcode/' . urlencode($zip);

    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false],
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return [];
    }

    $data = json_decode($json, true);
    if (!$data || !($data['success'] ?? false)) {
        return [];
    }

    // Filter by last name client-side
    $results = [];
    $upperLast = strtoupper($lastName);
    foreach (($data['licenses'] ?? []) as $lic) {
        $licLast = strtoupper($lic['last_name'] ?? $lic['full_name'] ?? '');
        if (strpos($licLast, $upperLast) !== false) {
            $results[] = [
                'callsign'    => $lic['callsign'] ?? '',
                'first_name'  => $lic['first_name'] ?? '',
                'last_name'   => $lic['last_name'] ?? '',
                'city'        => $lic['city'] ?? '',
                'state'       => $lic['state'] ?? '',
                'zip'         => $lic['zip_code'] ?? $lic['zip'] ?? '',
                'grant_date'  => $lic['grant_date'] ?? '',
                'expiry_date' => $lic['expiry_date'] ?? '',
                'frn'         => $lic['frn'] ?? '',
            ];
        }
    }
    return $results;
}

/**
 * Parse MM/DD/YYYY date to YYYY-MM-DD
 */
function parseApiDate($str) {
    $str = trim($str);
    if (empty($str)) {
        return '';
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $str, $m)) {
        return $m[3] . '-' . $m[1] . '-' . $m[2];
    }
    return $str;
}
