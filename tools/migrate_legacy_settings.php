<?php
/**
 * NewUI v4.0 — Legacy Settings Migration Tool
 *
 * Reads settings from the legacy tickets database and maps them
 * into the NewUI settings table using INSERT IGNORE (never overwrites).
 *
 * Usage (CLI):
 *   php migrate_legacy_settings.php                 # dry-run (preview)
 *   php migrate_legacy_settings.php --execute       # actually migrate
 *
 * This script NEVER writes to the legacy database — read-only access.
 */

// ── Bootstrap NewUI ──────────────────────────────────────────────
require_once __DIR__ . '/../config.php';

// ── Resolve legacy credentials ───────────────────────────────────
$legacyIncFile = realpath(__DIR__ . '/../../..') . '/tickets/incs/mysql.inc.php';
if (!file_exists($legacyIncFile)) {
    // Try alternate relative path
    $legacyIncFile = realpath(__DIR__ . '/../../../tickets/incs/mysql.inc.php');
}
if (!$legacyIncFile || !file_exists($legacyIncFile)) {
    fwrite(STDERR, "ERROR: Cannot find legacy config at tickets/incs/mysql.inc.php\n");
    exit(1);
}

// Include the legacy config — it defines $mysql_host, $mysql_user, etc.
include $legacyIncFile;

$legacy_host   = $mysql_host   ?? 'localhost';
$legacy_user   = $mysql_user   ?? '';
$legacy_pass   = $mysql_passwd ?? '';
$legacy_db     = $mysql_db     ?? 'tickets';
$legacy_prefix = $mysql_prefix ?? '';

// ── Connect to legacy DB (read-only) ────────────────────────────
function legacy_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    global $legacy_host, $legacy_user, $legacy_pass, $legacy_db;

    $dsn = "mysql:host={$legacy_host};dbname={$legacy_db};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $legacy_user, $legacy_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, "ERROR: Cannot connect to legacy DB: " . $e->getMessage() . "\n");
        exit(1);
    }
    return $pdo;
}

// ── Key mapping: legacy key => NewUI key ─────────────────────────
// Explicit 1:1 mappings
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

// Prefix-based mappings: legacy prefix => newui prefix
$PREFIX_MAP = [
    '_sms'  => 'sms',
    '_mail' => 'email',
];

/**
 * Resolve the NewUI key for a given legacy key.
 *
 * @param string $legacyKey
 * @return string|null  NewUI key, or null if not mapped
 */
function resolve_newui_key($legacyKey) {
    global $KEY_MAP, $PREFIX_MAP;

    // Explicit mapping first
    if (isset($KEY_MAP[$legacyKey])) {
        return $KEY_MAP[$legacyKey];
    }

    // Prefix-based mapping
    foreach ($PREFIX_MAP as $legacyPrefix => $newuiPrefix) {
        if (strpos($legacyKey, $legacyPrefix) === 0) {
            // e.g. _sms_provider => sms_provider
            $suffix = substr($legacyKey, strlen($legacyPrefix));
            // Normalise: strip leading underscore if present
            $suffix = ltrim($suffix, '_');
            if ($suffix === '') continue;
            return $newuiPrefix . '_' . $suffix;
        }
    }

    return null; // Not mapped
}

/**
 * Read all legacy settings.
 *
 * @return array  [ ['name' => ..., 'value' => ...], ... ]
 */
function read_legacy_settings() {
    global $legacy_prefix;
    $table = $legacy_prefix . 'settings';

    $stmt = legacy_db()->prepare("SELECT `name`, `value` FROM `{$table}`");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Read all existing NewUI setting keys.
 *
 * @return array  key => value
 */
function read_newui_settings() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all("SELECT `name`, `value` FROM `{$prefix}settings`");
    $map = [];
    foreach ($rows as $row) {
        $map[$row['name']] = $row['value'];
    }
    return $map;
}

/**
 * Build a migration plan (dry run).
 *
 * @return array  [
 *   'mapped'   => [ ['legacy_key'=>..., 'newui_key'=>..., 'value'=>..., 'conflict'=>bool, 'existing'=>...], ... ],
 *   'skipped'  => [ ['legacy_key'=>..., 'value'=>..., 'reason'=>...], ... ],
 * ]
 */
function build_migration_plan() {
    $legacySettings = read_legacy_settings();
    $newuiSettings  = read_newui_settings();

    $mapped  = [];
    $skipped = [];

    foreach ($legacySettings as $row) {
        $legacyKey = $row['name'];
        $value     = $row['value'];
        $newuiKey  = resolve_newui_key($legacyKey);

        if ($newuiKey === null) {
            $skipped[] = [
                'legacy_key' => $legacyKey,
                'value'      => $value,
                'reason'     => 'No mapping defined',
            ];
            continue;
        }

        $conflict = isset($newuiSettings[$newuiKey]);
        $mapped[] = [
            'legacy_key' => $legacyKey,
            'newui_key'  => $newuiKey,
            'value'      => $value,
            'conflict'   => $conflict,
            'existing'   => $conflict ? $newuiSettings[$newuiKey] : null,
        ];
    }

    return ['mapped' => $mapped, 'skipped' => $skipped];
}

/**
 * Execute the migration — INSERT IGNORE into NewUI settings.
 *
 * @return array  ['inserted' => int, 'skipped_conflict' => int, 'errors' => [...]]
 */
function execute_migration() {
    $plan   = build_migration_plan();
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $inserted = 0;
    $skippedConflict = 0;
    $errors = [];

    foreach ($plan['mapped'] as $item) {
        if ($item['conflict']) {
            $skippedConflict++;
            continue;
        }

        try {
            db_query(
                "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                [$item['newui_key'], $item['value']]
            );
            $inserted++;
        } catch (Exception $e) {
            $errors[] = $item['newui_key'] . ': ' . $e->getMessage();
        }
    }

    return [
        'inserted'         => $inserted,
        'skipped_conflict' => $skippedConflict,
        'skipped_unmapped' => count($plan['skipped']),
        'errors'           => $errors,
    ];
}

// ── CLI execution ────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    $execute = in_array('--execute', $argv ?? []);

    echo "=== Legacy Settings Migration ===\n\n";

    try {
        $legacySettings = read_legacy_settings();
        echo "Found " . count($legacySettings) . " legacy setting(s).\n\n";
    } catch (Exception $e) {
        fwrite(STDERR, "ERROR reading legacy settings: " . $e->getMessage() . "\n");
        exit(1);
    }

    $plan = build_migration_plan();

    // Show mapped settings
    echo "--- MAPPED (" . count($plan['mapped']) . ") ---\n";
    foreach ($plan['mapped'] as $item) {
        $status = $item['conflict'] ? '[CONFLICT - will skip]' : '[OK]';
        echo sprintf("  %-25s => %-25s %s\n", $item['legacy_key'], $item['newui_key'], $status);
        if ($item['conflict']) {
            echo sprintf("  %27s existing: %s\n", '', substr($item['existing'], 0, 60));
        }
    }

    echo "\n--- SKIPPED (" . count($plan['skipped']) . ") ---\n";
    foreach ($plan['skipped'] as $item) {
        echo sprintf("  %-25s  %s\n", $item['legacy_key'], $item['reason']);
    }

    echo "\n";

    if ($execute) {
        echo "Executing migration...\n";
        $result = execute_migration();
        echo "  Inserted:          " . $result['inserted'] . "\n";
        echo "  Skipped (conflict): " . $result['skipped_conflict'] . "\n";
        echo "  Skipped (unmapped): " . $result['skipped_unmapped'] . "\n";
        if (!empty($result['errors'])) {
            echo "  Errors:\n";
            foreach ($result['errors'] as $err) {
                echo "    - " . $err . "\n";
            }
        }
        echo "\nDone.\n";
    } else {
        echo "Dry run complete. Use --execute to apply changes.\n";
    }
}
