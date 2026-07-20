<?php
/**
 * Run Phase 10c migration — seed trusted_proxies setting.
 *
 * Default value: '127.0.0.1,::1' (single-host NPM deployment).
 * Admins can override via Settings → Login Settings or directly in DB:
 *
 *   UPDATE settings SET value = '127.0.0.1,::1,10.0.0.0/24'
 *    WHERE name = 'trusted_proxies';
 *
 * Idempotent. INSERT IGNORE.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 10c — trusted_proxies setting\n";
echo "===================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $stmt = db_query(
        "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
        ['trusted_proxies', '127.0.0.1,::1']
    );
    $current = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'trusted_proxies' LIMIT 1"
    );
    echo "[OK] trusted_proxies = '{$current}'\n";
    echo "     (CIDR notation supported; comma-separated)\n";
} catch (Exception $e) {
    echo "[WARN] trusted_proxies: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
