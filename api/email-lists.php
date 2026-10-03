<?php
/**
 * Email Distribution Lists API (Phase 41; rebuilt Phase 155, GH#145).
 *
 *   GET  ?action=list                    all active lists with entry / address / problem counts
 *   GET  ?action=detail&id=N             one list + every entry with its resolved status
 *   GET  ?action=resolve&id=N            the final recipient set + the entries left out, and why
 *   GET  ?action=search_recipients       server-side typeahead for the pickers
 *                                          type=member|constituent|list, q, list_id, has_email, limit
 *   GET  ?action=get_options             the two list options (see inc/email-lists.php)
 *   POST ?action=create                  body: name, slug?, description?
 *   POST ?action=update                  body: id, name?, description?
 *   POST ?action=archive                 body: id, force?
 *   POST ?action=add_member              body: list_id, member_type, ref_id | inline_email, display_name?
 *   POST ?action=remove_member           body: id, list_id
 *   POST ?action=import_csv              body: list_id, csv_text
 *   POST ?action=save_options            body: skip_statuses[], require_email_on_add
 *
 * The SQL and the business rules live in inc/email-list-write.php (writes),
 * inc/email-list-views.php (reads) and inc/email-lists.php (the resolver this
 * panel and the notification engine share). This file is auth + CSRF + RBAC +
 * JSON shaping only. Action names are unchanged from Phase 41 so any scripted
 * caller keeps working.
 *
 * RBAC: action.manage_config. Deliberately NO `|| is_admin()` fallback: there
 * is no narrower permission here to defend, and rbac_can()'s own is_super
 * short-circuit already covers every real Super Admin.
 *
 * Errors: a failing action replies {error: <message>, code: <stable code>} so
 * the browser can branch on `code`. Driver text is never sent to the client
 * (it goes to the server log); a missing table is HTTP 503 with a repair
 * hint, never an empty list that reads as "no lists".
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/email-lists.php';
require_once __DIR__ . '/../inc/email-list-views.php';
require_once __DIR__ . '/../inc/email-list-write.php';

if (!rbac_can('action.manage_config')) {
    json_error('Forbidden - requires action.manage_config', 403);
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

/** Turn a writer result into a JSON reply. */
function el_reply(array $r, array $extra = []): void
{
    if (!empty($r['ok'])) {
        unset($r['ok'], $r['code'], $r['message']);
        json_response(array_merge(['ok' => true], $r, $extra));
    }
    $payload = ['error' => (string) ($r['message'] ?? 'Request failed'), 'code' => (string) ($r['code'] ?? 'error')];
    foreach (['rules', 'path', 'label'] as $k) {
        if (isset($r[$k])) $payload[$k] = $r[$k];
    }
    json_response($payload, email_list_http_status($r['code'] ?? null));
}

function el_tables_missing(): void
{
    json_response(['error' => 'Email list tables are missing - run php sql/run_migrations.php',
                   'code' => 'tables_missing'], 503);
}

if ($method === 'GET') {
    if ($action === 'list') {
        $ov = email_list_overview();
        if (!empty($ov['missing_tables'])) el_tables_missing();
        json_response(['lists' => $ov['lists']]);
    }

    if ($action === 'detail') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $d = email_list_detail($id);
        if (!empty($d['missing_tables'])) el_tables_missing();
        if (!empty($d['missing'])) json_response(['error' => 'That list does not exist.', 'code' => 'list_missing'], 404);
        json_response(['list' => $d['list'], 'members' => $d['members'], 'summary' => $d['summary']]);
    }

    if ($action === 'resolve') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $r = email_list_resolve($id);
        if (!empty($r['missing_tables'])) el_tables_missing();
        if (!empty($r['missing'])) json_response(['error' => 'That list does not exist.', 'code' => 'list_missing'], 404);
        $recipients = [];
        foreach ($r['recipients'] as $rc) {
            $recipients[] = ['email' => $rc['address'], 'name' => $rc['name']];
        }
        // Everything that did NOT produce a recipient, with the reason - a
        // silent dead recipient is a bug, and this is the preview that makes
        // it visible before a rule ever sends.
        $detail = email_list_detail($id);
        $excluded = [];
        foreach ($detail['members'] as $row) {
            if ($row['status'] === 'ok') continue;
            $excluded[] = ['entry_id' => $row['id'], 'label' => $row['label'],
                           'status' => $row['status'], 'detail' => $row['status_detail']];
        }
        json_response(['count' => count($recipients), 'recipients' => $recipients, 'excluded' => $excluded]);
    }

    if ($action === 'search_recipients') {
        $type = (string) ($_GET['type'] ?? '');
        if (!in_array($type, ['member', 'constituent', 'list'], true)) json_error('type must be member, constituent or list');
        $hasEmail = null;
        if (isset($_GET['has_email']) && $_GET['has_email'] !== '') $hasEmail = ((string) $_GET['has_email'] === '1');
        $res = email_list_search_recipients($type, (string) ($_GET['q'] ?? ''),
            (int) ($_GET['list_id'] ?? 0), $hasEmail, (int) ($_GET['limit'] ?? 25));
        json_response($res);
    }

    if ($action === 'get_options') {
        $o = email_list_options();
        json_response([
            'skip_statuses' => $o['skip_statuses'],
            'require_email_on_add' => $o['require_email_on_add'],
            'status_labels' => email_list_member_status_labels(),
        ]);
    }

    json_error('Unknown action: ' . $action);
}

if ($method === 'POST') {
    if ($action === 'create') {
        el_reply(email_list_create_internal((string) ($input['name'] ?? ''),
            isset($input['slug']) ? (string) $input['slug'] : null,
            isset($input['description']) ? (string) $input['description'] : null, $userId));
    }
    if ($action === 'update') {
        $fields = [];
        if (isset($input['name'])) $fields['name'] = $input['name'];
        if (isset($input['description'])) $fields['description'] = $input['description'];
        el_reply(email_list_update_internal((int) ($input['id'] ?? 0), $fields, $userId));
    }
    if ($action === 'archive') {
        el_reply(email_list_archive_internal((int) ($input['id'] ?? 0), !empty($input['force']), $userId));
    }
    if ($action === 'add_member') {
        el_reply(email_list_add_entry_internal((int) ($input['list_id'] ?? 0),
            (string) ($input['member_type'] ?? ''), $input, $userId));
    }
    if ($action === 'remove_member') {
        el_reply(email_list_remove_entry_internal((int) ($input['id'] ?? 0), (int) ($input['list_id'] ?? 0), $userId));
    }
    if ($action === 'import_csv') {
        el_reply(email_list_import_csv_internal((int) ($input['list_id'] ?? 0),
            (string) ($input['csv_text'] ?? ''), $userId));
    }
    if ($action === 'save_options') {
        el_reply(email_list_save_options_internal($input, $userId));
    }
    json_error('Unknown action: ' . $action);
}

json_error('Method not allowed', 405);
