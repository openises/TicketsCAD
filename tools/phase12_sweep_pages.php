<?php
/**
 * Phase 12 sweep — replace `$level = get_level_text(...)` calls with
 * `$level = current_role_name()` across the 36 page templates.
 *
 * Pattern matched:
 *   $level = get_level_text((int) $_SESSION['level']);
 *   $level    = get_level_text((int) $_SESSION['level']);
 *   $level = get_level_text((int)$_SESSION['level']);
 *
 * Replacement:
 *   $level = current_role_name();
 *
 * Also inserts `require_once __DIR__ . '/inc/rbac.php';` before the
 * line if not already present in the file.
 *
 * Idempotent. Re-running is a no-op once converted.
 *
 * Usage:  php tools/phase12_sweep_pages.php
 */
require_once __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/..');

$pages = [
    'about.php', 'callboard.php', 'compliance-dashboard.php', 'constituents.php',
    'equipment.php', 'facilities.php', 'facility-board.php', 'facility-detail.php',
    'facility-edit.php', 'help.php', 'ics-forms.php', 'import-export.php',
    'incident-detail.php', 'incident-list.php', 'index.php', 'links.php',
    'messaging.php', 'mobile.php', 'new-incident.php', 'profile.php',
    'quick-start.php', 'reports.php', 'roster.php', 'scheduling.php',
    'search.php', 'situation.php', 'sop.php', 'status.php', 'status-time.php',
    'teams.php', 'unit-detail.php', 'unit-edit.php', 'units.php', 'vehicles.php',
];

$touched = 0;
$skipped = 0;

foreach ($pages as $page) {
    $path = $base . '/' . $page;
    if (!file_exists($path)) {
        echo "[--] {$page} — file not found, skipping\n";
        continue;
    }
    $src = file_get_contents($path);
    $orig = $src;

    // Pattern: `$level = get_level_text((int) $_SESSION['level'])` with
    // any whitespace variations between tokens.
    $pattern = '/\$level\s*=\s*get_level_text\s*\(\s*\(int\)\s*\$_SESSION\[\'level\'\]\s*\)\s*;/';
    $replacement = '$level = current_role_name();';
    $src = preg_replace($pattern, $replacement, $src);

    if ($src === $orig) {
        echo "[OK] {$page} — already converted or no match\n";
        $skipped++;
        continue;
    }

    // Make sure inc/rbac.php is required-once before our use site. The
    // page templates usually already have a `require_once __DIR__ . "/inc/auth.php"`
    // or similar — rbac.php is in the same directory. Look for an existing
    // include of rbac.php; if absent, add one after the i18n.php require.
    if (strpos($src, "require_once __DIR__ . '/inc/rbac.php'") === false &&
        strpos($src, 'require_once __DIR__ . "/inc/rbac.php"') === false) {
        // Try to insert after the i18n.php require (most pages have it).
        $src = preg_replace(
            "#require_once __DIR__ \. '/inc/i18n\.php';#",
            "require_once __DIR__ . '/inc/i18n.php';\nrequire_once __DIR__ . '/inc/rbac.php';",
            $src,
            1
        );
    }

    file_put_contents($path, $src);
    echo "[OK] {$page} — converted\n";
    $touched++;
}

echo "\nTouched: {$touched}, Skipped: {$skipped}\n";
