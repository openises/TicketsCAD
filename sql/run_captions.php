<?php
/**
 * Run Captions — Create i18n/captions schema and seed default translations.
 *
 * Purpose:  Creates the captions_i18n table and seeds default English
 *           caption strings for all UI elements. Supports the i18n system.
 * Usage:    php sql/run_captions.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
 *           Safe to run repeatedly.
 * Output:   [OK]/[WARN] status for each table and seed operation.
 */
require_once __DIR__ . '/../config.php';

echo "Captions / i18n Schema Setup\n";
echo "============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// 1. Create captions_i18n table
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}captions_i18n` (
        `id`          INT          AUTO_INCREMENT PRIMARY KEY,
        `caption_key` VARCHAR(128) NOT NULL,
        `lang`        VARCHAR(8)   NOT NULL DEFAULT 'en',
        `value`       TEXT         NOT NULL,
        `category`    VARCHAR(64)  NOT NULL DEFAULT 'general',
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_key_lang` (`caption_key`, `lang`),
        KEY `idx_lang` (`lang`),
        KEY `idx_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] captions_i18n table ready\n";
} catch (Exception $e) {
    echo "[WARN] captions_i18n: " . $e->getMessage() . "\n";
}

// 2. Seed common UI labels (English)
$seeds = [
    // Navigation
    ['nav.dashboard',    'en', 'Dashboard',    'nav'],
    ['nav.incidents',    'en', 'Incidents',     'nav'],
    ['nav.new_incident', 'en', 'New Incident',  'nav'],
    ['nav.roster',       'en', 'Roster',        'nav'],
    ['nav.teams',        'en', 'Teams',         'nav'],
    ['nav.facilities',   'en', 'Facilities',    'nav'],
    ['nav.equipment',    'en', 'Equipment',     'nav'],
    ['nav.vehicles',     'en', 'Vehicles',      'nav'],
    ['nav.scheduling',   'en', 'Scheduling',    'nav'],
    ['nav.search',       'en', 'Search',        'nav'],
    ['nav.reports',      'en', 'Reports',       'nav'],
    ['nav.settings',     'en', 'Settings',      'nav'],
    ['nav.logout',       'en', 'Logout',        'nav'],
    // Buttons
    ['btn.save',         'en', 'Save',          'button'],
    ['btn.cancel',       'en', 'Cancel',        'button'],
    ['btn.delete',       'en', 'Delete',        'button'],
    ['btn.edit',         'en', 'Edit',          'button'],
    ['btn.add',          'en', 'Add',           'button'],
    ['btn.close',        'en', 'Close',         'button'],
    ['btn.submit',       'en', 'Submit',        'button'],
    // Form labels
    ['form.address',     'en', 'Address',       'form'],
    ['form.city',        'en', 'City',          'form'],
    ['form.state',       'en', 'State',         'form'],
    ['form.zip',         'en', 'Zip Code',      'form'],
    ['form.phone',       'en', 'Phone',         'form'],
    ['form.name',        'en', 'Name',          'form'],
    ['form.description', 'en', 'Description',   'form'],
    ['form.notes',       'en', 'Notes',         'form'],
    // Status labels
    ['status.open',      'en', 'Open',          'status'],
    ['status.closed',    'en', 'Closed',        'status'],
    ['status.pending',   'en', 'Pending',       'status'],
];

$inserted = 0;
foreach ($seeds as $s) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, ?, ?, ?)",
            $s
        );
        $inserted++;
    } catch (Exception $e) {
        // Skip duplicates silently
    }
}
echo "[OK] Seeded $inserted caption entries\n";

// 3. Report totals
try {
    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}captions_i18n`");
    $langs = db_fetch_all("SELECT `lang`, COUNT(*) AS cnt FROM `{$prefix}captions_i18n` GROUP BY `lang`");
    echo "\nTotal captions: $total\n";
    foreach ($langs as $l) {
        echo "  {$l['lang']}: {$l['cnt']} entries\n";
    }
} catch (Exception $e) {
    echo "[WARN] Count failed: " . $e->getMessage() . "\n";
}

// 4. Check legacy captions table compatibility
try {
    $legacyCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}captions`");
    echo "\nLegacy captions table: $legacyCount entries (will be used as fallback)\n";
} catch (Exception $e) {
    echo "\nLegacy captions table not found (OK — not required)\n";
}

echo "\nDone.\n";
