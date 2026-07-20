<?php
/**
 * Phase 12 follow-up: fix the rbac.php require ordering on pages where
 * current_role_name() is called BEFORE inc/rbac.php gets loaded.
 *
 * Discovered 2026-06-11 when /roster.php returned HTTP 500 with
 *   PHP Fatal error: Call to undefined function current_role_name()
 *
 * Root cause: my Phase 12 sweep tool detected the existing `require_once
 * inc/rbac.php` further down in roster.php and skipped its top-of-file
 * insertion — so the call site at line 26 ran before the include at
 * line 34. Five other pages (equipment, import-export, status,
 * status-time, vehicles) had no rbac.php include at all because the
 * sweep tool's i18n.php-anchor regex didn't match (those files use
 * different quoting / spacing).
 *
 * Fix: drop an extra `require_once __DIR__ . '/inc/rbac.php';` IMMEDIATELY
 * BEFORE the `$level = current_role_name();` call. require_once is
 * idempotent, so any duplicate later in the file is a no-op.
 *
 * Usage: php tools/phase12_rbac_order_fix.php
 */

$base = realpath(__DIR__ . '/..');

$pages = [
    'roster.php',
    'equipment.php',
    'import-export.php',
    'status.php',
    'status-time.php',
    'vehicles.php',
];

foreach ($pages as $name) {
    $path = $base . '/' . $name;
    if (!file_exists($path)) {
        echo "[--] {$name} — file not found\n";
        continue;
    }
    $src = file_get_contents($path);

    // Already-correct guard.
    $marker = "require_once __DIR__ . '/inc/rbac.php';\n\$level = current_role_name()";
    if (strpos($src, $marker) !== false) {
        echo "[OK] {$name} — already in correct order\n";
        continue;
    }

    // Insert one line above the current_role_name() call.
    $new = preg_replace(
        '/(\$level\s*=\s*current_role_name\(\);)/',
        "require_once __DIR__ . '/inc/rbac.php';\n$1",
        $src,
        1
    );
    if ($new !== $src) {
        file_put_contents($path, $new);
        echo "[OK] {$name} — inserted require_once before call\n";
    } else {
        echo "[WARN] {$name} — couldn't match the call pattern; needs manual review\n";
    }
}
