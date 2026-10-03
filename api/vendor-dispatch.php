<?php
/**
 * NewUI v4.0 API -- Towing / roadside vendor dispatch, the DISPATCHER side (GH#148, Phase 155).
 *
 * GET  ?action=config&ticket_id=N          everything the incident-detail dialog needs in one call
 * GET  ?action=queue&ticket_id=N&list_id=L[&dispatch_id=D]   the rotation queue (who is next, who was called)
 * GET  ?action=ticket&ticket_id=N          the incident's dispatches with their ledger timelines
 * POST action=update                       vehicle / plate / reason / destination / notes on a dispatch
 * POST action=offer                        "I am calling this company" (the server classifies and records it)
 * POST action=outcome                      accepted (ETA) / declined / no_answer / unavailable, for a call
 * POST action=status                       on_scene / completed / cancelled / goa / withdrew / eta_update
 * POST action=note                         a free-text note on the dispatch timeline
 * POST action=void                         void a ledger entry (own entry within 15 min, or a manager any time)
 *
 * Gate: rbac_can('action.dispatch_vendor') ALONE -- never `|| is_admin()`. This permission is tier 0 on purpose
 * (a Dispatcher holds it); the narrower tier-1 permission, action.manage_vendors, is a different endpoint
 * (api/vendor-admin.php) and is only consulted here to let a manager void any entry. `||`-ing is_admin() onto either
 * would let a correctly-scoped Org Admin pick up the install-wide action.manage_config fallback (CLAUDE.md, Phase 138).
 *
 * Incident access: every request that names an incident (directly, or through a dispatch or ledger row, which are
 * resolved to their ticket SERVER-SIDE -- a client-supplied ticket_id is never trusted for a dispatch it names) must
 * pass org_can_see_ticket() (else 404) and org_can_mutate_ticket() (else 403), the same two-step shape as
 * api/incident-share.php. A view-tier shared-in viewer therefore sees nothing here.
 *
 * There is deliberately no "create" action: the dialog creates the dispatch header in the SAME transaction as the first call
 * (action=offer with a ticket_id), so an abandoned dialog leaves nothing behind. vendor_dispatch_create() exists in the
 * library for callers that need a header with no call yet (and for the tests), not as an HTTP surface nothing uses.
 *
 * Every POST needs a CSRF token. Error text never echoes a driver message (the library logs it and returns a fixed
 * string). Business rules live in inc/vendor-dispatch.php, not here.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/vendor-dispatch.php';

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) json_error('Method not allowed', 405);

// Deliberately rbac_can() alone -- see the docblock.
if (!rbac_can('action.dispatch_vendor')) json_error('Forbidden', 403);

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    if (!csrf_verify((string) ($input['csrf_token'] ?? ''))) json_error('Invalid CSRF token', 403);
    $action = (string) ($input['action'] ?? '');
} else {
    $action = (string) ($_GET['action'] ?? 'config');
}

if (!vendor_schema_ready()) {
    json_response(['error' => 'The towing / roadside tables are missing. Run: php sql/run_migrations.php',
                   'code' => 'vendor_schema_missing'], 409);
}

if ($action === 'config' && $method === 'GET' && !vendor_enabled()) {
    json_response(['enabled' => false]);
}
if (!vendor_enabled()) {
    json_response(['error' => 'Towing / roadside dispatch is turned off.', 'code' => 'vendor_disabled'], 409);
}

$actor = vendor_actor_from_session();
$canManage = rbac_can('action.manage_vendors');

/** 404 when the caller cannot see the incident, 403 when they can see it but not change it. */
function _vd_require_ticket(int $ticketId): void
{
    if ($ticketId <= 0 || !org_can_see_ticket($ticketId)) json_error('Incident not found', 404);
    if (!org_can_mutate_ticket($ticketId)) json_error('You can view this incident but not dispatch for it.', 403);
}

/** Resolve a dispatch to its ticket server-side, then run the same access check. @return array the dispatch row */
function _vd_dispatch_for_caller(int $dispatchId): array
{
    $d = $dispatchId > 0 ? vendor_dispatch_get($dispatchId) : null;
    if (!$d) json_error('Dispatch not found', 404);
    _vd_require_ticket((int) $d['ticket_id']);
    return $d;
}

/** The queue as the dialog consumes it: only the keys the JavaScript reads. */
function _vd_queue_out(?array $q): ?array
{
    if ($q === null) return null;
    $cands = [];
    foreach ($q['candidates'] as $c) {
        $cands[] = [
            'provider_id' => (int) $c['provider_id'], 'name' => $c['name'], 'contact_name' => $c['contact_name'],
            'phone' => $c['phone'], 'phone_alt' => $c['phone_alt'], 'service_area' => $c['service_area'],
            'hours_note' => $c['hours_note'], 'yard_facility_id' => $c['yard_facility_id'] !== null ? (int) $c['yard_facility_id'] : null,
            'rank' => (int) $c['rank'], 'eligible' => (bool) $c['eligible'], 'is_head' => (bool) $c['is_head'],
            'state' => $c['state'], 'last_turn_at' => $c['last_turn_at'],
            'suspended_until' => ((int) $c['is_suspended'] === 1) ? $c['suspended_until'] : null,
        ];
    }
    return ['list_id' => (int) $q['list_id'], 'mode' => $q['mode'],
            'head_provider_id' => $q['head_provider_id'] !== null ? (int) $q['head_provider_id'] : null, 'candidates' => $cands];
}

/** Turn a library result into the HTTP response. */
function _vd_respond(array $res, array $okExtra = []): void
{
    if (!($res['ok'] ?? false)) {
        $body = ['error' => (string) ($res['message'] ?? 'Request failed'), 'code' => (string) ($res['code'] ?? 'error')];
        if (isset($res['queue'])) $body['queue'] = _vd_queue_out($res['queue']);
        json_response($body, (int) ($res['http'] ?? 400));
    }
    json_response(array_merge(['success' => true], $okExtra));
}

function _vd_view(?array $dispatch, array $actor, bool $canManage): ?array
{
    return $dispatch ? vendor_dispatch_view($dispatch, $actor, $canManage, true) : null;
}

// ═══════════════════════════════════════════════════════════════════════
//  GET
// ═══════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    _vd_require_ticket($ticketId);

    try {
        if ($action === 'config') {
            $ticket = vendor_ticket_info($ticketId);
            if (!$ticket) json_error('Incident not found', 404);
            [$types, $lists] = vendor_dialog_lists();
            json_response([
                'enabled'      => true,
                'ticket'       => [
                    'ref' => $ticket['ref'], 'street' => $ticket['street'], 'city' => $ticket['city'],
                    'state' => $ticket['state'], 'address_about' => $ticket['address_about'],
                    'closed' => ((int) $ticket['status'] === 1),
                ],
                'service_types'   => $types,
                'lists'           => $lists,
                'other_providers' => vendor_other_providers(),
                'settings'        => [
                    'allow_override'           => vendor_setting('vendor_allow_override') === '1',
                    'override_requires_reason' => vendor_setting('vendor_override_requires_reason') === '1',
                ],
                'dial_mode'       => vendor_setting('phone_click_to_call'),
                'dispatches'      => vendor_dispatches_for_ticket($ticketId, $actor, $canManage),
            ]);
        }

        if ($action === 'ticket') {
            json_response(['dispatches' => vendor_dispatches_for_ticket($ticketId, $actor, $canManage)]);
        }

        if ($action === 'queue') {
            $listId = (int) ($_GET['list_id'] ?? 0);
            $list = $listId > 0 ? vendor_list_get($listId) : null;
            if (!$list || !vendor_org_visible($list['org_id'] !== null ? (int) $list['org_id'] : null)) json_error('Rotation list not found', 404);
            $dispatchId = (int) ($_GET['dispatch_id'] ?? 0);
            if ($dispatchId > 0) {
                $d = vendor_dispatch_get($dispatchId);
                if (!$d || (int) $d['ticket_id'] !== $ticketId) json_error('Dispatch not found', 404);
            }
            json_response(['queue' => _vd_queue_out(vendor_rotation_queue($listId, $dispatchId > 0 ? $dispatchId : null))]);
        }
    } catch (Throwable $e) {
        json_error_safe('Could not load the towing / roadside data', $e, 'vendor-dispatch GET');
    }
    json_error('Unknown action', 400);
}

// ═══════════════════════════════════════════════════════════════════════
//  POST
// ═══════════════════════════════════════════════════════════════════════
try {
    switch ($action) {
        case 'update': {
            $d = _vd_dispatch_for_caller((int) ($input['dispatch_id'] ?? 0));
            $res = vendor_dispatch_update_details((int) $d['id'], $input, $actor);
            _vd_respond($res, ['dispatch' => _vd_view($res['dispatch'] ?? null, $actor, $canManage)]);
        }

        case 'offer': {
            $dispatchId = (int) ($input['dispatch_id'] ?? 0);
            if ($dispatchId > 0) {
                $d = _vd_dispatch_for_caller($dispatchId);
                $ticketId = (int) $d['ticket_id'];
            } else {
                $ticketId = (int) ($input['ticket_id'] ?? 0);
                _vd_require_ticket($ticketId);
            }
            $allowed = ['dispatch_id', 'ticket_id', 'service_type_id', 'list_id', 'provider_id', 'provider_name', 'provider_phone',
                        'client_head_provider_id', 'owner_request', 'reason', 'vehicle_desc', 'plate', 'plate_state',
                        'tow_reason', 'dest_facility_id', 'dest_text', 'notes'];
            $in = [];
            foreach ($allowed as $k) { if (array_key_exists($k, $input)) $in[$k] = $input[$k]; }
            $in['ticket_id'] = $ticketId;
            $res = vendor_dispatch_offer($in, $actor);
            _vd_respond($res, [
                'dispatch'         => _vd_view($res['dispatch'] ?? null, $actor, $canManage),
                'selection_method' => $res['selection_method'] ?? null,
                'queue'            => _vd_queue_out($res['queue'] ?? null),
            ]);
        }

        case 'outcome': {
            $d = _vd_dispatch_for_caller((int) ($input['dispatch_id'] ?? 0));
            $eta = (isset($input['eta_minutes']) && $input['eta_minutes'] !== '' && $input['eta_minutes'] !== null) ? (int) $input['eta_minutes'] : null;
            $res = vendor_dispatch_outcome((int) $d['id'], (int) ($input['offer_event_id'] ?? 0), (string) ($input['outcome'] ?? ''), $eta, $actor,
                vendor_clean_str($input['reason'] ?? null, 255));
            _vd_respond($res, ['dispatch' => _vd_view($res['dispatch'] ?? null, $actor, $canManage)]);
        }

        case 'status': {
            $d = _vd_dispatch_for_caller((int) ($input['dispatch_id'] ?? 0));
            $eta = (isset($input['eta_minutes']) && $input['eta_minutes'] !== '' && $input['eta_minutes'] !== null) ? (int) $input['eta_minutes'] : null;
            $res = vendor_dispatch_status((int) $d['id'], (string) ($input['event'] ?? ''), $eta, $actor, vendor_clean_str($input['reason'] ?? null, 255));
            _vd_respond($res, ['dispatch' => _vd_view($res['dispatch'] ?? null, $actor, $canManage)]);
        }

        case 'note': {
            $d = _vd_dispatch_for_caller((int) ($input['dispatch_id'] ?? 0));
            $res = vendor_dispatch_note((int) $d['id'], (string) ($input['text'] ?? ''), $actor);
            _vd_respond($res, ['dispatch' => _vd_view(vendor_dispatch_get((int) $d['id']), $actor, $canManage)]);
        }

        case 'void': {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            $eventId = (int) ($input['event_id'] ?? 0);
            $dispatchId = $eventId > 0 ? (int) db_fetch_value(
                "SELECT `dispatch_id` FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$eventId]) : 0;
            _vd_dispatch_for_caller($dispatchId);
            $res = vendor_dispatch_void($eventId, (string) ($input['reason'] ?? ''), $actor, $canManage);
            _vd_respond($res, ['dispatch' => _vd_view($res['dispatch'] ?? null, $actor, $canManage)]);
        }
    }
} catch (Throwable $e) {
    json_error_safe('Could not complete the towing / roadside request', $e, 'vendor-dispatch POST');
}

json_error('Unknown action', 400);
