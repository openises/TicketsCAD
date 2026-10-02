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
 *                            allow_cross_class, note, expires_at (REQUIRED
 *                            whenever the resolved cross_class is true —
 *                            Phase 152 prereq #6), group_id
 * POST action=update      — id + any of the above fields. Supplying
 *                            expires_at (any value) makes this a RENEWAL:
 *                            re-snapshots operator_ack_by/
 *                            operator_callsign and clears warned_at.
 * POST action=delete      — id
 * POST action=renew       — id, expires_at (required) — the console's
 *                            one-click renew; a thin dedicated wrapper
 *                            over the same renewal semantics as `update`,
 *                            logged as a renewal rather than a generic edit
 *
 * Validation (inc/matrix-routes.php) mirrors matrix_core.py's add_route()
 * exactly — unknown channel, self-route, duplicate src/dst pair, and the
 * FCC Part 97.113 cross-class regulatory guard — so this endpoint can
 * never create a row the live matrix service would silently skip at load
 * time (spec.md guardrail: "no silent routes").
 *
 * Phase 152 prerequisite #4 — every successful DB write is now ALSO
 * applied to the live running matrix service immediately
 * (inc/matrix-control-client.php), not just picked up on its next
 * restart. If the control plane is unreachable or rejects the change,
 * the DB write is rolled back (create: delete the row just inserted;
 * update: restore the pre-update field values; delete: never removed
 * from the DB in the first place, since the live-apply call for a delete
 * happens BEFORE the DB delete) and the caller gets a 502 naming "matrix
 * service unreachable" — never a silently-queued patch the running
 * service doesn't know about.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/channel_registry.php';
require_once __DIR__ . '/../inc/matrix-routes.php';
require_once __DIR__ . '/../inc/matrix-control-client.php';
require_once __DIR__ . '/../inc/sse.php';

// Console rebuild (2026-09-07) — widened from action.manage_matrix-only
// to ALSO accept the Dispatcher-tier action.patch_create: this endpoint's
// create/update/delete/renew logic (inc/matrix-routes.php +
// inc/matrix-control-client.php's live-apply/rollback/audit) is the ONE
// implementation of "manage a standing comm_routes patch" regardless of
// who invokes it — the persona review's "checkbox-select + one confirm
// button" patch-rail creation UI needs a dispatcher to be able to couple
// two REAL channels together, which is a different thing from api/
// console-patch.php's own concern (a dispatcher's own ephemeral browser-
// leg mic routes, never persisted to comm_routes — see that file's
// docblock). Duplicating this CRUD into a second file would only invite
// the two implementations to drift. GET is widened identically — its
// payload (channels_all(), including each channel's `config`) is no more
// exposure than api/channels.php ALREADY gives every screen.console
// holder today. Cross-class creation stays gated behind action.
// patch_cross_class (or action.manage_matrix) specifically — see the
// per-action checks below — matching action.patch_cross_class's own
// tier-1 (Org Admin+) seeding, unchanged from when it was created
// alongside action.patch_create.
if (!rbac_can('action.manage_matrix') && !rbac_can('action.patch_create')) {
    json_error('Forbidden', 403);
}

/** True once for the caller's whole request — matches action.manage_matrix's own tier-1 seeding. */
function _matrix_can_cross_class() {
    return rbac_can('action.manage_matrix') || rbac_can('action.patch_cross_class');
}

/**
 * Console rebuild — the patch rail (SSE-driven, not polling) reads its
 * live route list off these comm:route_* events. Payload is deliberately
 * the same shape matrix_route_full()/matrix_routes_all() already return
 * (src/dst labels+ids, gain_db, enabled, allow_cross_class, expires_at,
 * operator_callsign, group_id) so the rail's own render function works
 * identically whether it got a row from the initial GET or a live event.
 */
function _matrix_sse_route(string $verb, array $route): void {
    sse_publish('comm:route_' . $verb, ['route' => $route], null, 'entitled');
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
    if (!empty($input['allow_cross_class']) && !_matrix_can_cross_class()) {
        json_error('action.patch_cross_class (or action.manage_matrix) is required to create a cross-class override', 403);
    }
    try {
        $id    = matrix_route_create($input, $userId);
        $route = matrix_route_full($id);

        $applied = matrix_control_apply_create($route);
        if (!$applied['ok']) {
            matrix_route_delete($id);   // roll back — never a silently-queued patch
            json_error('Matrix service unreachable — patch was NOT created. ' . $applied['error'], 502);
        }

        audit_log(
            'config', 'matrix.route_create', 'comm_routes', $id,
            'Audio-matrix patch created: ' . ($route['src_label'] ?? $route['src_channel_id'])
                . ' -> ' . ($route['dst_label'] ?? $route['dst_channel_id']),
            $route,
            ((int) $route['allow_cross_class'] === 1) ? AUDIT_HIGH : AUDIT_INFO
        );
        _matrix_sse_route('created', $route);
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
        $oldRoute = matrix_route_full($id);
        if (!$oldRoute) {
            json_error('Route not found', 404);
        }
        // Either introducing a cross-class override, or touching a route
        // that's already one, needs the same permission creating one
        // would have — never let the lesser action.patch_create tier
        // modify/extend a cross-class patch it couldn't have created.
        $willBeCrossClass = array_key_exists('allow_cross_class', $input)
            ? !empty($input['allow_cross_class']) : ((int) $oldRoute['allow_cross_class'] === 1);
        if ($willBeCrossClass && !_matrix_can_cross_class()) {
            json_error('action.patch_cross_class (or action.manage_matrix) is required for a cross-class patch', 403);
        }
        matrix_route_update($id, $input, $userId);
        $newRoute = matrix_route_full($id);

        $applied = matrix_control_apply_update($oldRoute, $newRoute);
        if (!$applied['ok']) {
            // Roll back to the pre-update values — the same writer, just
            // fed the OLD row's own field values back through it. Always
            // includes expires_at/group_id (Phase 152 prereq #6) even
            // though the rollback call is itself never a real "renewal"
            // by intent — the first update may have changed expires_at,
            // and without restoring it explicitly here the DB would be
            // left holding whatever the FAILED update just wrote.
            matrix_route_update($id, [
                'src_channel_id'    => $oldRoute['src_channel_id'],
                'dst_channel_id'    => $oldRoute['dst_channel_id'],
                'gain_db'           => $oldRoute['gain_db'],
                'priority'          => $oldRoute['priority'],
                'ducking'           => $oldRoute['ducking'],
                'enabled'           => $oldRoute['enabled'],
                'allow_cross_class' => $oldRoute['allow_cross_class'],
                'note'              => $oldRoute['note'],
                'expires_at'        => $oldRoute['expires_at'],
                'group_id'          => $oldRoute['group_id'],
            ], $userId);
            json_error('Matrix service unreachable — patch was NOT updated. ' . $applied['error'], 502);
        }

        audit_log(
            'config', 'matrix.route_update', 'comm_routes', $id,
            'Audio-matrix patch #' . $id . ' updated',
            $newRoute,
            ((int) $newRoute['allow_cross_class'] === 1) ? AUDIT_HIGH : AUDIT_INFO
        );
        _matrix_sse_route('updated', $newRoute);
        json_response(['ok' => true, 'route' => $newRoute]);
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
        if (!$route) {
            // Already gone — matches matrix_route_delete()'s own
            // "not an error" idempotent semantics.
            json_response(['ok' => true]);
        }

        // Apply live BEFORE the DB delete: on failure the DB row is
        // simply never touched, so there's nothing to roll back.
        $applied = matrix_control_apply_delete($route);
        if (!$applied['ok']) {
            json_error('Matrix service unreachable — patch was NOT deleted. ' . $applied['error'], 502);
        }

        $ok = matrix_route_delete($id);
        if ($ok) {
            audit_log(
                'config', 'matrix.route_delete', 'comm_routes', $id,
                'Audio-matrix patch #' . $id . ' removed',
                $route
            );
            _matrix_sse_route('removed', $route);
        }
        json_response(['ok' => $ok]);
    } catch (Exception $e) {
        json_error_safe('Failed to delete patch', $e);
    }
}

if ($action === 'renew') {
    // Phase 152 prerequisite #6 — the console's one-click renew (UI
    // itself deferred to the Console rebuild task, matching prerequisite
    // #3's console-mic.js/console-playback.js deferral; this backend
    // action is what it will call). A thin, dedicated action rather than
    // routing through 'update' so the audit log reads as a renewal, not
    // a generic edit, and so the caller only ever needs to supply
    // {id, expires_at} — never the whole route.
    $id = (int) ($input['id'] ?? 0);
    $newExpiresAt = (string) ($input['expires_at'] ?? '');
    if ($id <= 0) {
        json_error('Missing route id');
    }
    if ($newExpiresAt === '') {
        json_error('expires_at is required to renew a patch');
    }
    try {
        $oldRoute = matrix_route_full($id);
        if (!$oldRoute) {
            json_error('Route not found', 404);
        }
        // Extending a cross-class override's life needs the same
        // permission creating one would have — same reasoning as update.
        if ((int) $oldRoute['allow_cross_class'] === 1 && !_matrix_can_cross_class()) {
            json_error('action.patch_cross_class (or action.manage_matrix) is required to renew a cross-class patch', 403);
        }
        matrix_route_renew($id, $newExpiresAt, $userId);
        $newRoute = matrix_route_full($id);

        $applied = matrix_control_apply_update($oldRoute, $newRoute);
        if (!$applied['ok']) {
            matrix_route_update($id, [
                'src_channel_id'    => $oldRoute['src_channel_id'],
                'dst_channel_id'    => $oldRoute['dst_channel_id'],
                'gain_db'           => $oldRoute['gain_db'],
                'priority'          => $oldRoute['priority'],
                'ducking'           => $oldRoute['ducking'],
                'enabled'           => $oldRoute['enabled'],
                'allow_cross_class' => $oldRoute['allow_cross_class'],
                'note'              => $oldRoute['note'],
                'expires_at'        => $oldRoute['expires_at'],
                'group_id'          => $oldRoute['group_id'],
            ], $userId);
            json_error('Matrix service unreachable — patch was NOT renewed. ' . $applied['error'], 502);
        }

        audit_log(
            'config', 'matrix.route_renew', 'comm_routes', $id,
            'Audio-matrix patch #' . $id . ' renewed to ' . $newRoute['expires_at']
                . ' by ' . ($newRoute['operator_callsign'] ?? ('operator #' . $newRoute['operator_ack_by'])),
            $newRoute,
            ((int) $newRoute['allow_cross_class'] === 1) ? AUDIT_HIGH : AUDIT_INFO
        );
        _matrix_sse_route('updated', $newRoute); // renewal is a field-level update from the rail's own perspective
        json_response(['ok' => true, 'route' => $newRoute]);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    } catch (Exception $e) {
        json_error_safe('Failed to renew patch', $e);
    }
}

// Console rebuild — group coupling actions. matrix_group_create()/
// matrix_group_break() (inc/matrix-routes.php) already build/tear down
// the full directed mesh and were tested against this exact regulatory
// model in tests/test_phase152_group_coupling.php; this is their first
// HTTP exposure, for the patch rail's "couple 2+ strips" action.
if ($action === 'group_create') {
    if (!empty($input['allow_cross_class']) && !_matrix_can_cross_class()) {
        json_error('action.patch_cross_class (or action.manage_matrix) is required to create a cross-class group', 403);
    }
    try {
        $result = matrix_group_create(
            $input['channel_ids'] ?? [],
            !empty($input['allow_cross_class']),
            $input['expires_at'] ?? null,
            $userId,
            $input['note'] ?? null
        );
        // matrix_group_create() already committed the DB rows atomically
        // (all-or-nothing) — this loop applies each leg to the LIVE
        // matrix and, on any single leg failing, rolls back BOTH the DB
        // (matrix_group_break()) and every leg already applied live, so
        // a group creation is all-or-nothing end to end, not just in the
        // DB (matching the single-route create's own guarantee above).
        $appliedRoutes = [];
        foreach ($result['route_ids'] as $rid) {
            $route = matrix_route_full($rid);
            $applied = matrix_control_apply_create($route);
            if (!$applied['ok']) {
                foreach ($appliedRoutes as $ar) { matrix_control_apply_delete($ar); }
                matrix_group_break($result['group_id']);
                json_error('Matrix service unreachable — group was NOT created. ' . $applied['error'], 502);
            }
            $appliedRoutes[] = $route;
        }
        $routes = matrix_group_routes($result['group_id']);
        $anyCrossClass = false;
        foreach ($routes as $r) { if ((int) $r['allow_cross_class'] === 1) { $anyCrossClass = true; break; } }
        audit_log(
            'config', 'matrix.group_create', 'comm_routes', $result['group_id'],
            'Audio-matrix group #' . $result['group_id'] . ' created (' . count($routes) . ' routes, '
                . count($input['channel_ids'] ?? []) . ' channels coupled)',
            $routes,
            $anyCrossClass ? AUDIT_HIGH : AUDIT_INFO
        );
        sse_publish('comm:group_created', ['group_id' => $result['group_id'], 'routes' => $routes], null, 'entitled');
        json_response(['ok' => true, 'group_id' => $result['group_id'], 'routes' => $routes]);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    } catch (Exception $e) {
        json_error_safe('Failed to create group', $e);
    }
}

if ($action === 'group_break') {
    $groupId = (int) ($input['group_id'] ?? 0);
    if ($groupId <= 0) {
        json_error('Missing group_id');
    }
    try {
        $routes = matrix_group_routes($groupId);
        // Breaking always fails toward LESS connectivity (persona review's
        // own stated principle) — unlike create, a leg that can't be
        // reached live is not rolled back into existence; this loop is
        // best-effort per leg, matching control_http.py's own documented,
        // accepted narrow-atomicity-gap posture for the equivalent single-
        // route case.
        foreach ($routes as $route) { matrix_control_apply_delete($route); }
        $removed = matrix_group_break($groupId);
        audit_log(
            'config', 'matrix.group_break', 'comm_routes', $groupId,
            'Audio-matrix group #' . $groupId . ' broken (' . $removed . ' route(s) removed)',
            $routes
        );
        sse_publish('comm:group_removed', ['group_id' => $groupId], null, 'entitled');
        json_response(['ok' => true, 'removed' => $removed]);
    } catch (Exception $e) {
        json_error_safe('Failed to break group', $e);
    }
}

json_error('Unknown action');
