<?php
/**
 * NewUI v4.0 - External Links Migration
 *
 * Creates the external_links table and seeds default links.
 * Idempotent — safe to run multiple times.
 *
 * Usage: php run_links.php
 */

require_once __DIR__ . '/../config.php';

echo "=== External Links Migration ===\n\n";

// ── 1. Create table ────────────────────────────────────────────────
$sql_file = __DIR__ . '/links.sql';
if (!file_exists($sql_file)) {
    echo "ERROR: links.sql not found.\n";
    exit(1);
}

$sql = file_get_contents($sql_file);

// Strip comment-only lines before splitting
$lines = explode("\n", $sql);
$cleaned = [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
    $cleaned[] = $line;
}
$sql = implode("\n", $cleaned);

// Split on semicolons, execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));
foreach ($statements as $stmt) {
    if ($stmt === '') continue;
    try {
        db()->exec($stmt);
    } catch (PDOException $e) {
        // Ignore "already exists" errors
        if (strpos($e->getMessage(), 'already exists') === false) {
            echo "SQL Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "Table 'external_links' ensured.\n";

// ── 2. Seed default links (only if table is empty) ─────────────────
$count = (int) db_fetch_value("SELECT COUNT(*) FROM `external_links`");
if ($count > 0) {
    echo "Table already has {$count} links — skipping seed.\n";
    echo "\nDone.\n";
    exit(0);
}

$defaults = [
    [
        'title'       => 'Google Groups',
        'url'         => 'https://groups.google.com/g/open-source-cad',
        'description' => 'TicketsCAD mailing list and community discussion',
        'icon'        => 'bi-people',
        'category'    => 'Community',
        'sort_order'  => 1,
    ],
    [
        'title'       => 'GitHub Repository',
        'url'         => 'https://github.com/openises/tickets',
        'description' => 'Source code, issues, and pull requests',
        'icon'        => 'bi-github',
        'category'    => 'Community',
        'sort_order'  => 2,
    ],
    [
        'title'       => 'TicketsCAD Wiki',
        'url'         => 'https://github.com/openises/tickets/wiki',
        'description' => 'Documentation, installation guides, and FAQ',
        'icon'        => 'bi-book',
        'category'    => 'Community',
        'sort_order'  => 3,
    ],
    [
        'title'       => 'Weather.gov',
        'url'         => 'https://weather.gov',
        'description' => 'National Weather Service forecasts and alerts',
        'icon'        => 'bi-cloud-sun',
        'category'    => 'Resources',
        'sort_order'  => 1,
    ],
    [
        'title'       => 'FEMA',
        'url'         => 'https://fema.gov',
        'description' => 'Federal Emergency Management Agency',
        'icon'        => 'bi-shield-check',
        'category'    => 'Resources',
        'sort_order'  => 2,
    ],
];

$insert_sql = "INSERT INTO `external_links`
    (`title`, `url`, `description`, `icon`, `category`, `sort_order`, `active`, `created_by`)
    VALUES (?, ?, ?, ?, ?, ?, 1, NULL)";

$inserted = 0;
foreach ($defaults as $link) {
    try {
        db_query($insert_sql, [
            $link['title'],
            $link['url'],
            $link['description'],
            $link['icon'],
            $link['category'],
            $link['sort_order'],
        ]);
        $inserted++;
        echo "  + {$link['title']}\n";
    } catch (PDOException $e) {
        echo "  ! Failed to insert '{$link['title']}': " . $e->getMessage() . "\n";
    }
}

echo "\nSeeded {$inserted} default links.\n";
echo "Done.\n";
