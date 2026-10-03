<?php
/**
 * Notification Rules API (Phase 155, GH#144) - Settings -> Notification Rules.
 *
 *   GET  ?action=meta                    events, channels (with set-up state), severities, incident
 *                                        types, email lists, placeholders, presets, settings, queue
 *   GET  ?action=list                    every rule + last fired + 30-day sent/failed
 *   GET  ?action=get&id=N                one rule
 *   GET  ?action=log                     the delivery log   (rule_id, status, from, to, ticket_id, limit, offset)
 *   GET  ?action=queue                   delivery-queue state (scheduler live?, depth, breakers)
 *   GET  ?action=users&q=text | &ids=1,2 recipient picker: accounts with has_email / has_mobile (never the address)
 *   POST action=create | update | delete | toggle | duplicate
 *   POST action=preview                  what a rule WOULD do - sends nothing, writes nothing
 *   POST action=test_send                one real, bounded test (default: to the administrator only)
 *   POST action=save_settings            the three delivery settings
 *
 * RBAC: action.manage_notification_rules ONLY (admin_only tier 2 - Super Admin).
 * Deliberately NO `|| is_admin()` fallback: is_admin()'s own action.manage_config
 * branch would hand an Org Admin an install-wide control that mails incident
 * details to arbitrary recipients across every organization (the Phase 138
 * lesson). rbac_can()'s is_super short-circuit already covers real Super Admins.
 *
 * CSRF on every POST. Every mutation is audited. Errors reply
 * {error, code, errors?, warnings?} - never driver text (it goes to the server
 * log). All logic lives in inc/notification-rules-admin.php.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/notification-rules-admin.php';

if (!rbac_can('action.manage_notification_rules')) {
    json_error('Forbidden - requires action.manage_notification_rules', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    $input = is_array($decoded) ? $decoded : $_POST;
    if ($action === '' && !empty($input['action'])) $action = (string) $input['action'];
    if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
}

/** A writer's result -> JSON. */
function nr_reply(array $r, array $extra = []): void
{
    if (!empty($r['ok'])) {
        unset($r['ok'], $r['code'], $r['message']);
        json_response(array_merge(['ok' => true], $r, $extra));
    }
    $status = 400;
    switch ($r['code'] ?? '') {
        case 'not_found': $status = 404; break;
        case 'confirm_required': $status = 409; break;
        case 'throttled': $status = 429; break;
        case 'limit': $status = 409; break;
        case 'tables_missing': $status = 503; break;
        case 'db_error': $status = 500; break;
        case 'nothing_to_send': $status = 422; break;
        case 'validation': $status = 422; break;
    }
    $payload = ['error' => (string) ($r['message'] ?? 'Request failed'), 'code' => (string) ($r['code'] ?? 'error')];
    foreach (['errors', 'warnings', 'skipped'] as $k) {
        if (!empty($r[$k])) $payload[$k] = $r[$k];
    }
    json_response($payload, $status);
}

if ($method === 'GET') {
    if ($action === 'meta') {
        json_response(notification_rules_meta());
    }
    if ($action === 'list') {
        $l = notification_rules_list();
        if (empty($l['ok'])) {
            json_response(['error' => 'Notification tables are missing - run php sql/run_migrations.php', 'code' => 'tables_missing'], 503);
        }
        json_response(['rules' => $l['rules']]);
    }
    if ($action === 'get') {
        $rule = notification_rule_get((int) ($_GET['id'] ?? 0));
        if (!$rule) json_response(['error' => 'That rule does not exist.', 'code' => 'not_found'], 404);
        json_response(['rule' => $rule]);
    }
    if ($action === 'log') {
        $res = notification_log_query([
            'rule_id' => $_GET['rule_id'] ?? null, 'status' => $_GET['status'] ?? null,
            'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'ticket_id' => $_GET['ticket_id'] ?? null,
        ], (int) ($_GET['limit'] ?? 50), (int) ($_GET['offset'] ?? 0));
        if (empty($res['ok'])) {
            json_response(['error' => 'The delivery log could not be read.', 'code' => 'db_error'], 500);
        }
        json_response(['rows' => $res['rows'], 'total' => $res['total']]);
    }
    if ($action === 'queue') {
        json_response(notification_queue_status());
    }
    if ($action === 'users') {
        $ids = [];
        if (!empty($_GET['ids'])) foreach (explode(',', (string) $_GET['ids']) as $i) $ids[] = (int) $i;
        json_response(['users' => notification_recipient_users((string) ($_GET['q'] ?? ''), $ids)]);
    }
    json_error('Unknown action: ' . $action);
}

if ($method === 'POST') {
    if ($action === 'create') nr_reply(notification_rule_create_internal($input, $userId));
    if ($action === 'update') nr_reply(notification_rule_update_internal((int) ($input['id'] ?? 0), $input, $userId));
    if ($action === 'delete') nr_reply(notification_rule_delete_internal((int) ($input['id'] ?? 0), $userId));
    if ($action === 'toggle') {
        $active = array_key_exists('active', $input) ? (bool) $input['active'] : null;
        nr_reply(notification_rule_toggle_internal((int) ($input['id'] ?? 0), $active, $userId));
    }
    if ($action === 'duplicate') nr_reply(notification_rule_duplicate_internal((int) ($input['id'] ?? 0), $userId));
    if ($action === 'preview') {
        nr_reply(notification_rule_preview($input, (string) ($input['sample'] ?? 'synthetic')));
    }
    if ($action === 'test_send') {
        nr_reply(notification_rule_test_send($input, [
            'mode' => $input['mode'] ?? 'me', 'confirm_real' => !empty($input['confirm_real']),
            'sample' => (string) ($input['sample'] ?? 'synthetic'),
        ], $userId));
    }
    if ($action === 'save_settings') nr_reply(notification_settings_save_internal($input, $userId));
    json_error('Unknown action: ' . $action);
}

json_error('Method not allowed', 405);
