<?php
/**
 * NewUI v4.0 API — Legacy Import
 *
 * Provides a web-accessible interface for migrating data from the
 * legacy tickets database into NewUI.
 *
 * Routes:
 *   GET                     — Preview what would be migrated (dry run)
 *   POST action=migrate     — Execute settings migration
 *   POST action=migrate_users — Import legacy user accounts
 *   POST action=migrate_types — Import legacy incident types
 *
 * Auth: super admin only (level == 0).
 * NEVER writes to the legacy database — read-only access.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

// Suppress display_errors to keep JSON clean
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

// ── Auth: super admin only ───────────────────────────────────────
if (!is_admin()) {
    json_error('Super admin access required', 403);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── CSRF on writes ───────────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

// ── Locate and connect to legacy database ────────────────────────
function get_legacy_config() {
    // Look for legacy config in standard location
    $paths = [
        NEWUI_ROOT . '/../../tickets/incs/mysql.inc.php',
        NEWUI_ROOT . '/../../../tickets/incs/mysql.inc.php',
    ];

    foreach ($paths as $path) {
        $resolved = realpath($path);
        if ($resolved && file_exists($resolved)) {
            return $resolved;
        }
    }
    return null;
}

function legacy_pdo() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $configPath = get_legacy_config();
    if (!$configPath) {
        return null;
    }

    // Include legacy config — defines $mysql_host, $mysql_user, etc.
    include_once $configPath;

    $host   = $mysql_host   ?? 'localhost';
    $user   = $mysql_user   ?? '';
    $pass   = $mysql_passwd ?? '';
    $dbName = $mysql_db     ?? 'tickets';

    $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        return null;
    }
    return $pdo;
}

// ── Key mapping (same as CLI tool) ───────────────────────────────
$KEY_MAP = [
    '_title'       => 'org_name',
    '_location'    => 'org_location',
    '_email'       => 'email_from',
    '_email_name'  => 'email_from_name',
    '_smtp_host'   => 'smtp_host',
    '_smtp_port'   => 'smtp_port',
    '_smtp_user'   => 'smtp_user',
    '_smtp_pass'   => 'smtp_pass',
    '_smtp_secure' => 'smtp_encryption',
    '_map_lat'     => 'default_lat',
    '_map_lng'     => 'default_lng',
    '_map_zoom'    => 'default_zoom',
    '_timezone'    => 'timezone',
    '_inc_num'     => 'incident_numbers',
    '_version'     => 'legacy_version',
];

$PREFIX_MAP = [
    '_sms'  => 'sms',
    '_mail' => 'email',
];

function resolve_newui_key($legacyKey) {
    global $KEY_MAP, $PREFIX_MAP;

    if (isset($KEY_MAP[$legacyKey])) {
        return $KEY_MAP[$legacyKey];
    }

    foreach ($PREFIX_MAP as $legacyPrefix => $newuiPrefix) {
        if (strpos($legacyKey, $legacyPrefix) === 0) {
            $suffix = substr($legacyKey, strlen($legacyPrefix));
            $suffix = ltrim($suffix, '_');
            if ($suffix === '') continue;
            return $newuiPrefix . '_' . $suffix;
        }
    }

    return null;
}

// ── Helper: read legacy settings ─────────────────────────────────
function read_legacy_settings($legacyPdo, $legacyPrefix) {
    $table = $legacyPrefix . 'settings';
    $stmt = $legacyPdo->prepare("SELECT `name`, `value` FROM `{$table}`");
    $stmt->execute();
    return $stmt->fetchAll();
}

// ── Helper: read NewUI settings ──────────────────────────────────
function read_newui_settings_map() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all("SELECT `name`, `value` FROM `{$prefix}settings`");
    $map = [];
    foreach ($rows as $row) {
        $map[$row['name']] = $row['value'];
    }
    return $map;
}

// ── GET: Preview / dry run ───────────────────────────────────────
if ($method === 'GET') {
    $legacyPdo = legacy_pdo();

    // Legacy connection status
    $legacyStatus = [
        'config_found' => get_legacy_config() !== null,
        'connected'    => $legacyPdo !== null,
    ];

    if (!$legacyPdo) {
        json_response([
            'legacy_status' => $legacyStatus,
            'preview'       => null,
            'db_info'       => get_db_info(),
        ]);
    }

    // Get legacy prefix from config file
    $configPath = get_legacy_config();
    include_once $configPath;
    $legacyPrefix = $mysql_prefix ?? '';

    try {
        $legacySettings = read_legacy_settings($legacyPdo, $legacyPrefix);
    } catch (Exception $e) {
        json_response([
            'legacy_status' => array_merge($legacyStatus, ['error' => $e->getMessage()]),
            'preview'       => null,
            'db_info'       => get_db_info(),
        ]);
    }

    $newuiSettings = read_newui_settings_map();

    $mapped  = [];
    $skipped = [];
    foreach ($legacySettings as $row) {
        $legacyKey = $row['name'];
        $value     = $row['value'];
        $newuiKey  = resolve_newui_key($legacyKey);

        if ($newuiKey === null) {
            $skipped[] = [
                'legacy_key' => $legacyKey,
                'value'      => mb_substr($value, 0, 100),
                'reason'     => 'No mapping',
            ];
            continue;
        }

        $conflict = isset($newuiSettings[$newuiKey]);
        $mapped[] = [
            'legacy_key' => $legacyKey,
            'newui_key'  => $newuiKey,
            'value'      => mb_substr($value, 0, 100),
            'conflict'   => $conflict,
            'existing'   => $conflict ? mb_substr($newuiSettings[$newuiKey], 0, 100) : null,
        ];
    }

    // Count legacy users and types for the preview
    $legacyCounts = [];
    try {
        $stmt = $legacyPdo->prepare("SELECT COUNT(*) FROM `{$legacyPrefix}user`");
        $stmt->execute();
        $legacyCounts['users'] = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        $legacyCounts['users'] = 0;
    }
    try {
        $stmt = $legacyPdo->prepare("SELECT COUNT(*) FROM `{$legacyPrefix}in_types`");
        $stmt->execute();
        $legacyCounts['types'] = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        $legacyCounts['types'] = 0;
    }

    json_response([
        'legacy_status' => $legacyStatus,
        'legacy_counts' => $legacyCounts,
        'preview'       => [
            'mapped'  => $mapped,
            'skipped' => $skipped,
        ],
        'db_info' => get_db_info(),
    ]);
}

// ── POST: Execute migration actions ──────────────────────────────
if ($method === 'POST') {
    $action = $input['action'] ?? '';

    $legacyPdo = legacy_pdo();
    if (!$legacyPdo) {
        json_error('Cannot connect to legacy database', 500);
    }

    $configPath = get_legacy_config();
    include_once $configPath;
    $legacyPrefix = $mysql_prefix ?? '';

    // ── Migrate settings ─────────────────────────────────────────
    if ($action === 'migrate') {
        try {
            $legacySettings = read_legacy_settings($legacyPdo, $legacyPrefix);
        } catch (Exception $e) {
            json_error('Cannot read legacy settings: ' . $e->getMessage(), 500);
        }

        $newuiSettings = read_newui_settings_map();
        $inserted = 0;
        $skippedConflict = 0;
        $skippedUnmapped = 0;
        $errors = [];

        foreach ($legacySettings as $row) {
            $legacyKey = $row['name'];
            $value     = $row['value'];
            $newuiKey  = resolve_newui_key($legacyKey);

            if ($newuiKey === null) {
                $skippedUnmapped++;
                continue;
            }

            if (isset($newuiSettings[$newuiKey])) {
                $skippedConflict++;
                continue;
            }

            try {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                    [$newuiKey, $value]
                );
                $inserted++;
            } catch (Exception $e) {
                $errors[] = $newuiKey . ': ' . $e->getMessage();
            }
        }

        audit_log('config', 'import', 'settings', null,
            "Legacy settings migration: {$inserted} imported, {$skippedConflict} conflicts, {$skippedUnmapped} unmapped",
            ['errors' => $errors]
        );

        json_response([
            'success'          => true,
            'inserted'         => $inserted,
            'skipped_conflict' => $skippedConflict,
            'skipped_unmapped' => $skippedUnmapped,
            'errors'           => $errors,
        ]);
    }

    // ── Migrate users ────────────────────────────────────────────
    if ($action === 'migrate_users') {
        try {
            $stmt = $legacyPdo->prepare(
                "SELECT `id`, `user`, `pass`, `level` FROM `{$legacyPrefix}user` ORDER BY `id`"
            );
            $stmt->execute();
            $legacyUsers = $stmt->fetchAll();
        } catch (Exception $e) {
            json_error('Cannot read legacy users: ' . $e->getMessage(), 500);
        }

        // Get existing NewUI usernames
        $existingUsers = [];
        try {
            $rows = db_fetch_all("SELECT `user` FROM `{$prefix}user`");
            foreach ($rows as $row) {
                $existingUsers[strtolower($row['user'])] = true;
            }
        } catch (Exception $e) {
            // user table may not exist
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($legacyUsers as $lu) {
            $username = trim($lu['user']);
            if ($username === '') continue;

            // Skip if user already exists in NewUI
            if (isset($existingUsers[strtolower($username)])) {
                $skipped++;
                continue;
            }

            $passHash = $lu['pass'] ?? '';
            $level    = (int) ($lu['level'] ?? 2);

            // Legacy passwords may be MD5 or bcrypt — import as-is.
            // Users will need to reset if the hash format is incompatible.
            try {
                // Schema audit 2026-07-07: the column is `passwd`, not
                // `pass` — this INSERT failed for every user, so the
                // legacy user import never imported anyone.
                db_query(
                    "INSERT IGNORE INTO `{$prefix}user` (`user`, `passwd`, `level`) VALUES (?, ?, ?)",
                    [$username, $passHash, $level]
                );
                $imported++;
            } catch (Exception $e) {
                $errors[] = $username . ': ' . $e->getMessage();
            }
        }

        audit_log('auth', 'import', 'user', null,
            "Legacy user import: {$imported} imported, {$skipped} skipped (existing)",
            ['errors' => $errors]
        );

        json_response([
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'total'    => count($legacyUsers),
            'errors'   => $errors,
        ]);
    }

    // ── Migrate incident types ───────────────────────────────────
    if ($action === 'migrate_types') {
        try {
            // Try full column set first, fall back gracefully
            try {
                $stmt = $legacyPdo->prepare(
                    "SELECT `id`, `type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `sort`
                     FROM `{$legacyPrefix}in_types` ORDER BY `sort`, `type`"
                );
                $stmt->execute();
            } catch (Exception $e) {
                // Minimal fallback
                $stmt = $legacyPdo->prepare(
                    "SELECT `id`, `type`, `description` FROM `{$legacyPrefix}in_types` ORDER BY `type`"
                );
                $stmt->execute();
            }
            $legacyTypes = $stmt->fetchAll();
        } catch (Exception $e) {
            json_error('Cannot read legacy incident types: ' . $e->getMessage(), 500);
        }

        // Get existing NewUI type names
        $existingTypes = [];
        try {
            $rows = db_fetch_all("SELECT `type` FROM `{$prefix}in_types`");
            foreach ($rows as $row) {
                $existingTypes[strtolower($row['type'])] = true;
            }
        } catch (Exception $e) {
            // table may not exist
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($legacyTypes as $lt) {
            $typeName = trim($lt['type'] ?? '');
            if ($typeName === '') continue;

            // Skip if type already exists
            if (isset($existingTypes[strtolower($typeName)])) {
                $skipped++;
                continue;
            }

            $description = $lt['description'] ?? '';
            $protocol    = $lt['protocol']     ?? '';
            $severity    = (int) ($lt['set_severity'] ?? 0);
            $group       = $lt['group']        ?? '';
            $radius      = (int) ($lt['radius'] ?? 0);
            $color       = $lt['color']        ?? '#0d6efd';
            $sort        = (int) ($lt['sort']   ?? 0);

            try {
                db_query(
                    "INSERT INTO `{$prefix}in_types`
                        (`type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `sort`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$typeName, $description, $protocol, $severity, $group, $radius, $color, $sort]
                );
                $imported++;
            } catch (Exception $e) {
                // Retry with minimal columns
                try {
                    db_query(
                        "INSERT INTO `{$prefix}in_types` (`type`, `description`) VALUES (?, ?)",
                        [$typeName, $description]
                    );
                    $imported++;
                } catch (Exception $e2) {
                    $errors[] = $typeName . ': ' . $e2->getMessage();
                }
            }
        }

        audit_log('config', 'import', 'incident_type', null,
            "Legacy type import: {$imported} imported, {$skipped} skipped (existing)",
            ['errors' => $errors]
        );

        json_response([
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'total'    => count($legacyTypes),
            'errors'   => $errors,
        ]);
    }

    json_error('Unknown action: ' . $action, 400);
}

// ── Helper: Get current DB info ──────────────────────────────────
function get_db_info() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    global $db_name;

    $info = [
        'database'       => $db_name ?? 'unknown',
        'server_version' => '',
        'table_count'    => 0,
        'total_size'     => '0 KB',
        'key_tables'     => [],
    ];

    try {
        $info['server_version'] = db()->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Exception $e) {}

    // Table count and sizes
    try {
        $rows = db_fetch_all(
            "SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
             ORDER BY TABLE_NAME",
            [$db_name]
        );
        $info['table_count'] = count($rows);

        $totalBytes = 0;
        foreach ($rows as $row) {
            $totalBytes += (int) $row['DATA_LENGTH'] + (int) $row['INDEX_LENGTH'];
        }
        $info['total_size'] = format_bytes($totalBytes);

        // Key table row counts
        $keyTables = ['ticket', 'user', 'responder', 'in_types', 'facilities', 'settings', 'action', 'member'];
        foreach ($rows as $row) {
            $tname = $row['TABLE_NAME'];
            // Strip prefix for comparison
            $baseName = $prefix ? preg_replace('/^' . preg_quote($prefix, '/') . '/', '', $tname) : $tname;
            if (in_array($baseName, $keyTables)) {
                $info['key_tables'][] = [
                    'name' => $tname,
                    'rows' => (int) $row['TABLE_ROWS'],
                    'size' => format_bytes((int) $row['DATA_LENGTH'] + (int) $row['INDEX_LENGTH']),
                ];
            }
        }
    } catch (Exception $e) {}

    return $info;
}

function format_bytes($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

ini_set('display_errors', $prevDisplay);
json_error('Method not allowed', 405);
