<?php
/**
 * NewUI v4.0 API — Require-HTTPS enforcement status (SPEC-STATUS.md gap B16).
 *
 * GET /api/https-enforcement-status.php
 *   Admin-only (403 for non-admin — matching api/migrations-check.php and
 *   api/health-check.php). Returns the CURRENT request's require_https /
 *   is_https_verified() status. See inc/https-enforcement.php for the
 *   canonical computation every caller (this endpoint, the navbar banner,
 *   the Settings live-state box, and health_check_https_enforcement() on
 *   the Status page) shares — so all three surfaces read the same answer.
 *
 * This endpoint NEVER changes behavior based on the answer it computes —
 * it only reports it. There is no non-200 response tied to the
 * verification state itself; the only non-200s here are the ordinary
 * auth/method gates every read-only admin endpoint in this codebase uses.
 *
 * Response shape (see https_enforcement_status in inc/https-enforcement.php
 * for field meanings):
 *   {
 *     "enabled": true,
 *     "verified": false,
 *     "reason": "untrusted_proxy",
 *     "message": "Require HTTPS is turned on, and this request ...",
 *     "show_banner": true
 *   }
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/https-enforcement.php';

ini_set('display_errors', '0');

if (!is_admin()) {
    json_error('Admin access required', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

json_response(https_enforcement_status());
