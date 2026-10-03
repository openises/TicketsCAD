<?php
/**
 * NewUI v4.0 API -- Towing / roadside vendor dispatch, the ADMIN side (GH#148, Phase 155).
 *
 * GET  ?action=overview                  providers, lists (with the computed next-up), service types, settings, counts
 * GET  ?action=list_detail&list_id=L     one list with its full ordered queue
 * GET  ?action=history[&filters]         every ledger row the caller may see + a per-company summary
 * GET  ?action=history_csv[&filters]     the same rows as a CSV download (audited; spreadsheet-injection guarded)
 * POST action=provider_save | provider_suspend | provider_unsuspend | provider_retire | provider_delete
 *      action=list_save | list_set_default | list_retire | list_delete
 *      action=member_add | member_remove | member_move | member_move_to_end
 *      action=service_type_save | settings_save
 *
 * Gate: rbac_can('action.manage_vendors') ALONE (tier 1, Org Admin or above). Never `|| is_admin()`: this permission
 * is deliberately narrower than action.manage_config, and is_admin()'s fallback would hand it to anyone holding that.
 * The page (service-providers-admin.php) gates on the SAME permission (a page gate and its API gate must agree).
 *
 * Org scoping: providers and lists carry org_id. Reads use org_query_filter() (so strict-isolation mode treats NULL-org
 * rows as Super-Admin-only); a write to an existing row needs the caller to be able to see it, and a row that belongs
 * to "all agencies" (org_id NULL) can only be changed by a Super Admin. The library enforces all of that
 * (inc/vendor-admin-write.php); this file only authenticates, checks CSRF and RBAC, and maps results to HTTP.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/vendor-admin-write.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) json_error('Method not allowed', 405);

// Deliberately rbac_can() alone -- see the docblock.
if (!rbac_can('action.manage_vendors')) json_error('Forbidden', 403);

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    if (!csrf_verify((string) ($input['csrf_token'] ?? ''))) json_error('Invalid CSRF token', 403);
    $action = (string) ($input['action'] ?? '');
} else {
    $action = (string) ($_GET['action'] ?? 'overview');
}

if (!vendor_schema_ready()) {
    json_response(['error' => 'The towing / roadside tables are missing. Run: php sql/run_migrations.php',
                   'code' => 'vendor_schema_missing'], 409);
}

$actor = vendor_actor_from_session();

/** Library result -> HTTP. */
function _va_respond(array $res, array $okExtra = []): void
{
    if (!($res['ok'] ?? false)) {
        json_response(['error' => (string) ($res['message'] ?? 'Request failed'), 'code' => (string) ($res['code'] ?? 'error')], (int) ($res['http'] ?? 400));
    }
    json_response(array_merge(['success' => true], $okExtra));
}

/** Filters for the history views, taken from the query string (validated again by the library). */
function _va_history_filters(): array
{
    return [
        'list_id'        => (int) ($_GET['list_id'] ?? 0),
        'provider_id'    => (int) ($_GET['provider_id'] ?? 0),
        'dispatch_id'    => (int) ($_GET['dispatch_id'] ?? 0),
        'date_from'      => (string) ($_GET['date_from'] ?? ''),
        'date_to'        => (string) ($_GET['date_to'] ?? ''),
        'overrides_only' => !empty($_GET['overrides_only']) && $_GET['overrides_only'] !== '0',
    ];
}

if ($method === 'GET') {
    try {
        if ($action === 'overview') {
            json_response(vendor_admin_overview());
        }

        if ($action === 'list_detail') {
            $detail = vendor_admin_list_detail((int) ($_GET['list_id'] ?? 0));
            if (!$detail) json_error('Rotation list not found', 404);
            json_response($detail);
        }

        if ($action === 'history') {
            $h = vendor_history_query(_va_history_filters(), 500);
            $events = [];
            foreach ($h['events'] as $r) {
                $events[] = [
                    'event_at' => $r['event_at'], 'event_type' => $r['event_type'],
                    'dispatch_ref' => ($r['ticket_ref'] !== null && $r['ordinal'] !== null) ? vendor_ref_label((string) $r['ticket_ref'], (int) $r['ordinal']) : null,
                    'list_name' => $r['list_name'],
                    'provider_name' => $r['provider_name'], 'selection_method' => $r['selection_method'],
                    'consumed_turn' => (int) $r['consumed_turn'], 'expected_head_name' => $r['expected_head_name'],
                    'reason' => $r['reason'], 'detail' => $r['detail'], 'actor_name' => $r['actor_name'],
                    'voided' => (bool) $r['voided'], 'no_outcome' => (bool) $r['no_outcome'],
                ];
            }
            json_response(['events' => $events, 'summary' => $h['summary'], 'truncated' => $h['truncated']]);
        }

        if ($action === 'history_csv') {
            $h = vendor_history_query(_va_history_filters(), 5000);
            if (function_exists('audit_log')) {
                audit_log('vendor', 'history.export', 'vendor_history', 'csv', 'Exported the towing / roadside rotation history',
                    ['rows' => count($h['events']), 'truncated' => $h['truncated']], AUDIT_LOW);
            }
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="rotation-history-' . date('Ymd-His') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");                   // UTF-8 BOM so a spreadsheet reads company names correctly
            fputcsv($out, vendor_history_csv_header());
            // history_query returns newest first; an auditor reads a ledger oldest first.
            foreach (array_reverse($h['events']) as $r) fputcsv($out, vendor_history_csv_row($r));
            fclose($out);
            exit;
        }
    } catch (Throwable $e) {
        json_error_safe('Could not load the towing / roadside data', $e, 'vendor-admin GET');
    }
    json_error('Unknown action', 400);
}

try {
    switch ($action) {
        case 'provider_save': {
            $res = vendor_provider_save($input, $actor);
            _va_respond($res, ['id' => (int) ($res['provider']['id'] ?? 0)]);
        }
        case 'provider_suspend':
            _va_respond(vendor_provider_suspend((int) ($input['id'] ?? 0), $input['until'] ?? '', $input['reason'] ?? '', $actor));
        case 'provider_unsuspend':
            _va_respond(vendor_provider_unsuspend((int) ($input['id'] ?? 0), $actor));
        case 'provider_retire':
            _va_respond(vendor_provider_retire((int) ($input['id'] ?? 0), $actor));
        case 'provider_delete':
            _va_respond(vendor_provider_delete((int) ($input['id'] ?? 0), $actor));

        case 'list_save': {
            $res = vendor_list_save($input, $actor);
            _va_respond($res, ['id' => (int) ($res['list']['id'] ?? 0)]);
        }
        case 'list_set_default':
            _va_respond(vendor_list_set_default((int) ($input['id'] ?? 0), !empty($input['is_default']), $actor));
        case 'list_retire':
            _va_respond(vendor_list_retire((int) ($input['id'] ?? 0), $actor));
        case 'list_delete':
            _va_respond(vendor_list_delete((int) ($input['id'] ?? 0), $actor));

        case 'member_add':
            _va_respond(vendor_member_add((int) ($input['list_id'] ?? 0), (int) ($input['provider_id'] ?? 0), $actor));
        case 'member_remove':
            _va_respond(vendor_member_remove((int) ($input['list_id'] ?? 0), (int) ($input['provider_id'] ?? 0), $actor));
        case 'member_move':
            _va_respond(vendor_member_move((int) ($input['list_id'] ?? 0), (int) ($input['provider_id'] ?? 0), (string) ($input['direction'] ?? ''), $actor,
                isset($input['reason']) ? (string) $input['reason'] : null));
        case 'member_move_to_end':
            _va_respond(vendor_member_move_to_end((int) ($input['list_id'] ?? 0), (int) ($input['provider_id'] ?? 0), (string) ($input['reason'] ?? ''), $actor));

        case 'service_type_save':
            _va_respond(vendor_service_type_save($input, $actor));
        case 'settings_save':
            _va_respond(vendor_settings_save($input, $actor), ['settings' => vendor_settings_current()]);
    }
} catch (Throwable $e) {
    json_error_safe('Could not complete the request', $e, 'vendor-admin POST');
}

json_error('Unknown action', 400);
