<?php
/**
 * Phase 114c — audio-matrix patch/route CRUD API (closes SPEC-STATUS.md §B1)
 *
 * comm_routes is the audio patch matrix's route table — the thing the
 * audio matrix actually IS (services/audio-matrix/matrix_core.py's Route
 * dataclass; sql/run_phase114c_comm_routes.php created the table). Until
 * this file it had a schema and one reader (services/audio-matrix/
 * service.py's load_routes()) but no writer anywhere in the app — a patch
 * could only be created by hand-written SQL, and action.manage_matrix
 * (seeded to Super Admin/Org Admin by the 114c migration) gated nothing.
 *
 * GET                     — list every patch (joined with channel display
 *                            fields) + the channel picker list
 *                            (action.manage_matrix)
 * POST action=create      — create a patch: src_channel_id, dst_channel_id,
 *                            gain_db, priority, ducking, enabled,
 *                            allow_cross_class, note
 * POST action=update      — id + any of the above fields
 * POST action=delete      — id
 *
 * Validation (inc/matrix-routes.php) mirrors matrix_core.py's add_route()
 * exactly — unknown channel, self-route, duplicate src/dst pair, and the
 * FCC Part 97.113 cross-class regulatory guard — so this endpoint can
 * never create a row the live matrix service would silently skip at load
 * time (spec.md guardrail: "no silent routes").
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/channel_registry.php';
require_once __DIR__ . '/../inc/matrix-routes.php';

// The whole surface — read and write — is action.manage_matrix. Unlike
// api/channels.php (whose GET is the operational console everyone with
// screen.console needs), this endpoint IS the admin patch-matrix panel;
// there's no operational reason for a non-admin to load it.
if (!rbac_can('action.manage_matrix')) {
    json_error('Forbidden', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        json_response([
            'routes'   => matrix_routes_all(),
            'channels' => channels_all(),
        ]);
    } catch (Exception $e) {
        json_error_safe('Failed to load matrix routes', $e);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($input['csrf_token'] ?? '')) {
    json_error('Invalid CSRF token', 403);
}

require_once __DIR__ . '/../inc/audit.php';

$action = $input['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

if ($action === 'create') {
    try {
        $id    = matrix_route_create($input, $userId);
        $route = matrix_route_full($id);
        audit_log(
            'config', 'matrix.route_create', 'comm_routes', $id,
            'Audio-matrix patch created: ' . ($route['src_label'] ?? $route['src_channel_id'])
                . ' -> ' . ($route['dst_label'] ?? $route['dst_channel_id']),
            $route,
            ((int) $route['allow_cross_class'] === 1) ? AUDIT_HIGH : AUDIT_INFO
        );
        json_response(['ok' => true, 'id' => $id, 'route' => $route]);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    } catch (Exception $e) {
        json_error_safe('Failed to create patch', $e);
    }
}

if ($action === 'update') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_error('Missing route id');
    }
    try {
        matrix_route_update($id, $input);
        $route = matrix_route_full($id);
        audit_log(
            'config', 'matrix.route_update', 'comm_routes', $id,
            'Audio-matrix patch #' . $id . ' updated',
            $route,
            ((int) $route['allow_cross_class'] === 1) ? AUDIT_HIGH : AUDIT_INFO
        );
        json_response(['ok' => true, 'route' => $route]);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    } catch (Exception $e) {
        json_error_safe('Failed to update patch', $e);
    }
}

if ($action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_error('Missing route id');
    }
    try {
        $route = matrix_route_full($id);
        $ok    = matrix_route_delete($id);
        if ($ok) {
            audit_log(
                'config', 'matrix.route_delete', 'comm_routes', $id,
                'Audio-matrix patch #' . $id . ' removed',
                $route
            );
        }
        json_response(['ok' => $ok]);
    } catch (Exception $e) {
        json_error_safe('Failed to delete patch', $e);
    }
}

json_error('Unknown action');
