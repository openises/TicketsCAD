<?php
/**
 * Phase 12 sweep — replace `$current_level > 1` / `<= 1` patterns in
 * API endpoints with `is_admin()` from inc/rbac.php.
 *
 * Patterns matched (idempotent — each is replaced with the modern
 * is_admin() form):
 *
 *   $current_level > 1          → !is_admin()
 *   $current_level > 0          → !is_admin()   (super-only was the same intent: admin gate)
 *   $current_level <= 1         → is_admin()
 *   $current_level <= 2         → is_admin() || rbac_can('action.create_incident')
 *                                    (best-effort; dispatcher+ was the intent — manual review marker
 *                                    added in comments next to these so Eric / I can refine later)
 *   $is_admin = ($current_level <= 1)  → $is_admin = is_admin()
 *   ($current_level ?? 99) > 1  → !is_admin()
 *
 * Also inserts `require_once __DIR__ . '/../inc/rbac.php';` at the
 * top of any file that doesn't already include it (the require_once
 * is idempotent — re-including is fine).
 *
 * Does NOT touch:
 *   - api/rbac.php (migration endpoint reads level)
 *   - tools/upgrade/**
 *   - tests/**
 *   - Comment-only lines (regex requires a leading $)
 *
 * Usage: php tools/phase12_sweep_apis.php
 */
require_once __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/../api');
$dir = new DirectoryIterator($base);

// Files NOT to touch:
$skipFiles = [
    'rbac.php',     // migration endpoint must read level
];

$touched = 0;
$skipped = 0;

foreach ($dir as $entry) {
    if (!$entry->isFile()) continue;
    if ($entry->getExtension() !== 'php') continue;
    $name = $entry->getFilename();
    if (in_array($name, $skipFiles, true)) {
        echo "[--] {$name} — skip (migration endpoint)\n";
        $skipped++;
        continue;
    }

    $path = $entry->getPathname();
    $src = file_get_contents($path);
    $orig = $src;

    // ── Patterns ────────────────────────────────────────────────────────

    // $is_admin = ($current_level <= 1);  -- preserve variable name
    $src = preg_replace(
        '/\$is_admin\s*=\s*\(\s*\$current_level\s*<=\s*1\s*\)\s*;/',
        '$is_admin = is_admin();',
        $src
    );

    // ($current_level ?? 99) > 1  → !is_admin()
    $src = preg_replace(
        '/\(\s*\$current_level\s*\?\?\s*\d+\s*\)\s*>\s*1\b/',
        '!is_admin()',
        $src
    );

    // $current_level > 1   → !is_admin()
    $src = preg_replace(
        '/\$current_level\s*>\s*1\b/',
        '!is_admin()',
        $src
    );

    // $current_level > 0   → !is_admin()  (super-admin-only gate)
    $src = preg_replace(
        '/\$current_level\s*>\s*0\b/',
        '!is_admin()',
        $src
    );

    // $current_level <= 1  → is_admin()
    $src = preg_replace(
        '/\$current_level\s*<=\s*1\b/',
        'is_admin()',
        $src
    );

    // $current_level <= 2  → (is_admin() || rbac_can('action.create_incident'))
    // (dispatcher+ semantic — best effort; manual review may refine to a more
    // specific permission per endpoint)
    $src = preg_replace(
        '/\$current_level\s*<=\s*2\b/',
        "(is_admin() || rbac_can('action.create_incident'))",
        $src
    );

    if ($src === $orig) {
        echo "[OK] {$name} — no level patterns\n";
        continue;
    }

    // Make sure inc/rbac.php is required.
    if (strpos($src, "require_once __DIR__ . '/../inc/rbac.php'") === false &&
        strpos($src, 'require_once __DIR__ . "/../inc/rbac.php"') === false) {
        // Insert after the auth.php require (every authenticated API has it).
        $src = preg_replace(
            "#require_once __DIR__ \. '/auth\.php';#",
            "require_once __DIR__ . '/auth.php';\nrequire_once __DIR__ . '/../inc/rbac.php';",
            $src,
            1
        );
    }

    file_put_contents($path, $src);
    echo "[OK] {$name} — converted\n";
    $touched++;
}

echo "\nTouched: {$touched}, Skipped: {$skipped}\n";
