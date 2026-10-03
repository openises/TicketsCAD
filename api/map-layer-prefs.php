<?php
/**
 * NewUI v4.0 API — per-user map layer visibility.
 *
 *   GET                                     → effective prefs for this user
 *   POST {layers:{id:bool}}                 → save this user's overrides
 *   POST {reset:true}                       → drop overrides, back to the
 *                                             administrator default
 *   POST {admin_defaults:{id:bool}}         → set the ORG default
 *                                             (requires action.manage_config)
 *
 * A save here is fire-and-forget from the browser's point of view: the map has
 * already rendered and already reflects the new state locally. Nothing on this
 * path may block or interrupt a dispatcher, so failures return a plain JSON
 * error the client discards silently — and are written to the server log,
 * which is the only place anyone will actually look for them.
 */

// A PHP warning printed before the JSON corrupts the response body.
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
// Loaded here, not assumed: the audit row below sat behind function_exists('audit_log'),
// which was false on this request path, so changing the install's default map layers
// was never audited.
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/map-layer-prefs.php';

try {
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) json_error('Auth required', 401);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        json_response(['ok' => true, 'prefs' => map_layer_prefs_get($uid)]);
    }

    if ($method === 'POST') {
        // $_POST is not populated for Content-Type: application/json.
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = [];

        if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
            json_error('CSRF', 403);
        }

        // ── Administrator default ───────────────────────────────────────────
        if (isset($input['admin_defaults'])) {
            if (!function_exists('rbac_can') || !rbac_can('action.manage_config')) {
                json_error('Permission denied', 403);
            }
            if (!is_array($input['admin_defaults'])) {
                json_error('admin_defaults must be an object', 400);
            }
            if (!map_layer_prefs_set_admin_defaults($input['admin_defaults'])) {
                error_log('[map-layer-prefs] admin default save failed for user ' . $uid);
                json_error('Save failed', 500);
            }
            audit_log('config', 'update', 'settings', null,
                'Updated default map layer visibility',
                ['layers' => $input['admin_defaults']]);
            json_response(['ok' => true, 'prefs' => map_layer_prefs_get($uid)]);
        }

        // ── Reset this user back to the administrator default ───────────────
        if (!empty($input['reset'])) {
            if (!map_layer_prefs_reset($uid)) {
                error_log('[map-layer-prefs] reset failed for user ' . $uid);
                json_error('Reset failed', 500);
            }
            json_response(['ok' => true, 'prefs' => map_layer_prefs_get($uid)]);
        }

        // ── Per-user save ───────────────────────────────────────────────────
        if (!isset($input['layers']) || !is_array($input['layers'])) {
            json_error('layers required', 400);
        }
        if (!map_layer_prefs_set($uid, $input['layers'])) {
            // Logged, not shown: the client deliberately ignores this so a
            // preference write can never put a modal in front of a dispatcher.
            error_log('[map-layer-prefs] save failed for user ' . $uid);
            json_error('Save failed', 500);
        }
        json_response(['ok' => true]);
    }

    json_error('Method not allowed', 405);
} catch (Throwable $e) {
    error_log('[map-layer-prefs] ' . $e->getMessage());
    json_error('Request failed', 500);
} finally {
    ini_set('display_errors', $prevDisplay);
}
