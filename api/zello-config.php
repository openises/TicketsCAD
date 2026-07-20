<?php
/**
 * NewUI v4.0 API - Zello Configuration
 *
 * GET  /api/zello-config.php  - Read all zello_* settings
 *
 * Returns system-level Zello settings for the browser widget and proxy.
 * Strips sensitive fields (private_key, password, auth_token) for non-admin users.
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

try {
    $rows = db_fetch_all("SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` LIKE 'zello_%'");
    $config = [];
    foreach ($rows as $row) {
        $config[$row['name']] = $row['value'];
    }

    // Strip sensitive fields for non-admin users
    if (!is_admin()) {
        unset($config['zello_private_key']);
        unset($config['zello_password']);
        unset($config['zello_auth_token']);
    }

    json_response(['config' => $config]);
} catch (Exception $e) {
    json_error('Failed to load Zello config: ' . $e->getMessage(), 500);
}
