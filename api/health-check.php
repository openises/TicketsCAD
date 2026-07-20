<?php
/**
 * NewUI v4.0 API — Installation Health / File-Permission Check (GH #41)
 *
 * GET /api/health-check.php
 *   Admin-gated (is_admin() OR rbac_can('action.manage_config')).
 *   Returns health_check_all() from inc/health-check.php:
 *
 *   {
 *     "checked": true,
 *     "generated_at": "...",
 *     "sapi": "apache2handler",
 *     "process_user": "www-data",
 *     "dirs":       { "checked": true, "dirs": [ {path, exists, writable, owner, severity, note}, ... ] },
 *     "unreadable": { "checked": true, "scanned": N, "unreadable": [ {path, issue}, ... ], "truncated": false },
 *     "opcache":    { "enabled": true, "validate_timestamps": true, "revalidate_freq": 2, "severity": "ok", ... },
 *     "version":    { "running": "4.0.0", "on_disk": "4.0.0", "match": true, "severity": "ok", ... },
 *     "summary":    { "critical": 0, "warn": 0 }
 *   }
 *
 * IMPORTANT: this endpoint runs as the WEB SERVER USER, so its
 * is_writable / is_readable answers are authoritative for "will the app
 * actually work". tools/check-health.php runs the same library from the
 * CLI and reflects the CLI user instead — informative, not authoritative.
 *
 * Read-only (GET, no state change) → no CSRF token required, matching
 * api/migrations-check.php.
 *
 * Policy: detect and warn, never auto-fix.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/health-check.php';

ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

if (!is_admin() && !rbac_can('action.manage_config')) {
    json_error('Admin access required', 403);
}

try {
    json_response(health_check_all());
} catch (Throwable $e) {
    json_error_safe('Health check failed', $e, 'health-check');
}
