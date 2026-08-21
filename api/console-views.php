<?php
/**
 * Phase 114b-b2/b3 — console views API (shared + personal)
 *
 * GET                        — shared views + the caller's own personal
 *                               views + other users' is_shared personal
 *                               views (browsable clone sources), all with
 *                               their strips                (screen.console)
 * POST action=create         — new view {name, icon, personal, based_on_view_id}
 *                               personal=true -> owner_user_id = caller,
 *                               no console.design needed. personal absent/
 *                               false -> shared view, console.design required.
 *                               based_on_view_id (optional) clones an
 *                               existing view's strips into the new one —
 *                               source must be shared, the caller's own, or
 *                               another user's is_shared personal view.
 * POST action=update         — rename/re-icon/re-order/{is_shared} {id, ...}
 * POST action=delete         — remove a view + its strips {id}
 * POST action=save_strips    — replace a view's strip set {id, strips: [...]}
 *
 * Shared-view mutations (owner_user_id IS NULL) require console.design, as
 * before. Personal-view mutations require only ownership — screen.console
 * (checked file-wide below) is enough (Eric, 2026-08-20). The business
 * logic (validation, RBAC boundary, clone copy) lives in
 * inc/console-views.php so it can be driven directly by tests.
 *
 * Free-form layout (b2.5, Eric 2026-07-07): a strip is a rectangle on
 * the view canvas (12-column outer grid, 20px rows) and its components
 * are rectangles on the strip's inner grid (12 columns, 14px rows).
 * Component types are validated against the channel's capabilities so a
 * published view can never contain a dead button.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/channel_registry.php';
require_once __DIR__ . '/../inc/console-views.php';

if (!rbac_can('screen.console')) {
    json_error('Forbidden', 403);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        json_response([
            'views'                => console_shared_views(),
            'my_views'              => $uid > 0 ? console_personal_views_for_user($uid) : [],
            'shared_personal_views' => $uid > 0 ? console_shared_personal_views($uid) : [],
            'components'            => console_component_catalog(),
        ]);
    } catch (Exception $e) {
        json_error_safe('Failed to load views', $e);
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
$canDesign = rbac_can('console.design');

/** Build the response payload every mutating action returns on success. */
function _console_views_payload($uid) {
    return [
        'views'                => console_shared_views(),
        'my_views'              => $uid > 0 ? console_personal_views_for_user($uid) : [],
        'shared_personal_views' => $uid > 0 ? console_shared_personal_views($uid) : [],
    ];
}

if ($action === 'create') {
    $personal = !empty($input['personal']);
    if (!$personal && !$canDesign) {
        json_error('console.design permission required to create a shared view', 403);
    }
    if ($personal && $uid <= 0) {
        json_error('Not authenticated', 401);
    }

    $basedOnViewId = (int) ($input['based_on_view_id'] ?? 0);
    if ($basedOnViewId) {
        $src = console_view_get_row($basedOnViewId);
        if (!console_view_visible_as_clone_source($src, $uid)) {
            json_error('Base view not found or not available to clone', 404);
        }
    }

    $args = [
        'name'          => $input['name'] ?? '',
        'icon'          => $input['icon'] ?? '',
        'ownerUserId'   => $personal ? $uid : null,
        'createdBy'     => $uid ?: null,
        'basedOnViewId' => $basedOnViewId ?: null,
    ];
    $r = console_view_create($args);
    if (!$r['ok']) { json_error($r['error'], $r['status'] ?? 400); }
    audit_log('config', $personal ? 'console.personal_view_create' : 'console.view_create',
        'console_views', $r['id'],
        ($personal ? 'Personal' : 'Shared') . " console view \"{$args['name']}\" created"
        . ($basedOnViewId ? " (cloned from view $basedOnViewId, {$r['strips_copied']} strips)" : ''));
    json_response(array_merge(['ok' => true, 'id' => $r['id']], _console_views_payload($uid)));
}

if (in_array($action, ['update', 'delete', 'save_strips'], true)) {
    $id = (int) ($input['id'] ?? 0);
    $view = console_view_get_row($id);
    $gate = console_view_can_write($view, $uid, $canDesign);
    if (!$gate['ok']) { json_error($gate['error'], $gate['status']); }
    $isPersonal = $view['owner_user_id'] !== null;

    if ($action === 'update') {
        $fields = [];
        foreach (['name', 'icon', 'sort_order'] as $k) {
            if (array_key_exists($k, $input)) { $fields[$k] = $input[$k]; }
        }
        if ($isPersonal && array_key_exists('is_shared', $input)) {
            $fields['is_shared'] = $input['is_shared'];
        }
        $r = console_view_update($id, $fields);
        if (!$r['ok']) { json_error($r['error'], $r['status'] ?? 400); }
        audit_log('config', $isPersonal ? 'console.personal_view_update' : 'console.view_update',
            'console_views', $id, ($isPersonal ? 'Personal' : 'Shared') . " console view \"{$view['name']}\" updated");
        json_response(array_merge(['ok' => true], _console_views_payload($uid)));
    }

    if ($action === 'delete') {
        $r = console_view_delete($id);
        if (!$r['ok']) { json_error($r['error'], $r['status'] ?? 400); }
        audit_log('config', $isPersonal ? 'console.personal_view_delete' : 'console.view_delete',
            'console_views', $id, ($isPersonal ? 'Personal' : 'Shared') . " console view \"{$view['name']}\" deleted");
        json_response(array_merge(['ok' => true], _console_views_payload($uid)));
    }

    if ($action === 'save_strips') {
        $r = console_view_save_strips($id, $input['strips'] ?? null);
        if (!$r['ok']) { json_error($r['error'], $r['status'] ?? 400); }
        audit_log('config', $isPersonal ? 'console.personal_view_publish' : 'console.view_publish',
            'console_views', $id,
            ($isPersonal ? 'Personal' : 'Shared') . " console view \"{$view['name']}\" published ({$r['count']} strips)");
        json_response(array_merge(['ok' => true], _console_views_payload($uid)));
    }
}

json_error('Unknown action');
