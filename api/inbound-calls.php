<?php
/**
 * NewUI v4.0 API — Inbound calls: list/detail/claim/release/reassign/
 * heartbeat/force-reclaim/reviewed.
 *
 * Phase 149. Every write action here is a thin wrapper around the pure
 * functions in inc/inbound-calls.php (the atomic claim/reassign UPDATEs
 * live there, per plan.md §4/§4a) — this file's job is auth, RBAC, CSRF,
 * and org-scope, never business logic of its own.
 *
 * RBAC codes referenced here (screen.call_queue, action.claim_call,
 * action.manage_calls, field.caller_history, field.patient_history) are
 * seeded by Milestone 2 (sql/rbac.sql / sql/run_00_rbac.php) — on an
 * install that hasn't run that migration yet, rbac_can() simply returns
 * false for an unknown code and every action here 403s harmlessly.
 *
 * GET  ?action=list           — active calls (ringing/claimed/wrapup)
 * GET  ?action=list_missed    — unreviewed abandoned calls
 * GET  ?action=detail&id=N    — one call's full record
 * POST ?action=claim          — {id}
 * POST ?action=release        — {id}
 * POST ?action=reassign       — {id}                (FR-18a quick reassignment)
 * POST ?action=heartbeat      — {id}
 * POST ?action=force_reclaim  — {id, reason?}        (FR-16/FR-17 two-tier)
 * POST ?action=reviewed       — {id}
 * POST ?action=link_ticket    — {id, ticket_id}      (call-prefill.js markHandled)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/inbound-calls.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

/** Is this call visible to the current session? NULL org_id = visible
 *  install-wide (plan.md §6 -- "the common single-agency case pays no
 *  extra cost"). Mirrors api/stream.php's own org-scope check. */
function p149_user_can_see_call(array $call): bool
{
    if ($call['org_id'] === null) return true;
    if (is_admin()) return true;
    $visible = org_visible_ids();
    if ($visible === null) return true; // unrestricted (global grant / super admin)
    return in_array((int) $call['org_id'], $visible, true);
}

function p149_error(string $msg, int $code = 400): void
{
    json_error($msg, $code);
}

function p149_current_user(): array
{
    return [(int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['user'] ?? $_SESSION['username'] ?? '')];
}

function p149_read_json_body(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function p149_require_csrf(array $input): void
{
    if (!csrf_verify((string) ($input['csrf_token'] ?? ''))) {
        json_error('Invalid or expired security token. Please refresh the page.', 403);
    }
}

if ($method === 'GET') {

    if ($action === 'list') {
        if (!rbac_can('screen.call_queue')) json_error('Insufficient permissions', 403);
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $rows = db_fetch_all(
                "SELECT * FROM `{$prefix}inbound_calls`
                  WHERE `state` IN ('ringing','claimed','wrapup')
                  ORDER BY `ringing_at` ASC
                  LIMIT 100"
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!p149_user_can_see_call($row)) continue;
            $trunk = _p149_trunk_for_call($row);
            $out[] = inbound_call_broadcast_payload($row, $trunk ?? []) + ['id' => (int) $row['id']];
        }
        json_response(['calls' => $out]);
    }

    if ($action === 'list_missed') {
        if (!rbac_can('screen.call_queue')) json_error('Insufficient permissions', 403);
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $rows = db_fetch_all(
                "SELECT * FROM `{$prefix}inbound_calls`
                  WHERE `state` = 'abandoned' AND `reviewed_at` IS NULL
                  ORDER BY `ringing_at` DESC
                  LIMIT 100"
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!p149_user_can_see_call($row)) continue;
            $out[] = [
                'id'             => (int) $row['id'],
                'trunk_id'       => (int) $row['trunk_id'],
                'caller_number'  => $row['caller_number'],
                'caller_name'    => $row['caller_name'],
                'called_number'  => $row['called_number'],
                'ringing_at'     => $row['ringing_at'],
                'ended_at'       => $row['ended_at'],
                'reviewed_at'    => $row['reviewed_at'],
                'reviewed_by'    => $row['reviewed_by'],
            ];
        }
        json_response(['calls' => $out]);
    }

    if ($action === 'detail') {
        if (!rbac_can('screen.call_queue')) json_error('Insufficient permissions', 403);
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $call = inbound_call_get($id);
        if (!$call) json_error('Call not found', 404);
        if (!p149_user_can_see_call($call)) json_error('Call not found', 404);

        $trunk = _p149_trunk_for_call($call) ?? [];

        // FR-18: "reviewable later by a supervisor or administrator" --
        // the call's own append-only audit trail, in order, so a
        // supervisor opening a call's detail can see who claimed/
        // released/reassigned/force-reclaimed it and when, without a
        // separate admin screen.
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $events = [];
        try {
            $eventRows = db_fetch_all(
                "SELECT * FROM `{$prefix}inbound_call_events` WHERE `call_id` = ? ORDER BY `at` ASC, `id` ASC",
                [$id]
            );
            foreach ($eventRows as $ev) {
                $events[] = [
                    'event_type'    => $ev['event_type'],
                    'actor_user_id' => $ev['actor_user_id'] !== null ? (int) $ev['actor_user_id'] : null,
                    'actor_name'    => $ev['actor_name'],
                    'reason'        => $ev['reason'],
                    'detail'        => $ev['detail_json'] !== null ? json_decode((string) $ev['detail_json'], true) : null,
                    'at'            => $ev['at'],
                ];
            }
        } catch (Throwable $e) {
            $events = [];
        }
        // FR-26: the base fields here are caller-ID-equivalent (number,
        // called line, state, claim attribution) -- never gated behind
        // field.caller_history, which governs the MATCHED CONSTITUENT
        // identity/history a downstream lookup (api/constituents.php,
        // api/call-history.php -- Milestone 2's retrofit) resolves.
        json_response([
            'call' => [
                'id'                => (int) $call['id'],
                'trunk_id'          => (int) $call['trunk_id'],
                'trunk_label'       => (string) ($trunk['label'] ?? ''),
                'org_id'            => $call['org_id'] !== null ? (int) $call['org_id'] : null,
                'provider_call_id'  => $call['provider_call_id'],
                'caller_number'     => $call['caller_number'],
                'caller_name'       => $call['caller_name'],
                'called_number'     => $call['called_number'],
                'state'             => $call['state'],
                'claimed_by'        => $call['claimed_by'] !== null ? (int) $call['claimed_by'] : null,
                'claimed_by_name'   => $call['claimed_by_name'],
                'claimed_at'        => $call['claimed_at'],
                'reassigned_from'   => $call['reassigned_from'] !== null ? (int) $call['reassigned_from'] : null,
                'stale'             => !empty($call['stale_since']),
                'stale_since'       => $call['stale_since'],
                'ended_at'          => $call['ended_at'],
                'ticket_id'         => $call['ticket_id'] !== null ? (int) $call['ticket_id'] : null,
                'ringing_at'        => $call['ringing_at'],
                'reassign_grace_seconds' => (int) ($trunk['reassign_grace_seconds'] ?? 20),
            ],
            'events' => $events,
            // Told to the client so it can render "no history available"
            // states without a second permission-check round trip -- the
            // ACTUAL enforcement of these two permissions happens at
            // api/constituents.php / api/call-history.php (plan.md §5),
            // which is what the caller_number's synthetic blur event
            // (assets/js/call-prefill.js) triggers next.
            'can_view_history'         => rbac_can('field.caller_history'),
            'can_view_patient_history' => rbac_can('field.patient_history'),
        ]);
    }

    json_error('Unknown action', 404);
}

if ($method === 'POST') {

    $input = p149_read_json_body();
    $id = (int) ($input['id'] ?? 0);
    [$userId, $userName] = p149_current_user();

    if ($action === 'claim') {
        p149_require_csrf($input);
        if (!rbac_can('action.claim_call')) json_error('Insufficient permissions: claim calls', 403);
        if ($id <= 0) json_error('id required');
        $existing = inbound_call_get($id);
        if ($existing && !p149_user_can_see_call($existing)) json_error('Call not found', 404);
        $result = inbound_call_claim($id, $userId, $userName);
        if (!$result['ok']) {
            json_response([
                'success'          => false,
                'reason'           => $result['reason'],
                'claimed_by_name'  => $result['claimed_by_name'] ?? null,
                'state'            => $result['state'] ?? null,
            ]);
        }
        json_response(['success' => true, 'call' => $result['call']]);
    }

    if ($action === 'release') {
        p149_require_csrf($input);
        if (!rbac_can('action.claim_call')) json_error('Insufficient permissions', 403);
        if ($id <= 0) json_error('id required');
        $existing = inbound_call_get($id);
        if (!$existing) json_error('Call not found', 404);
        if (!p149_user_can_see_call($existing)) json_error('Call not found', 404);
        // The claimant may always release their own claim; releasing
        // someone else's claim is a supervisor action.
        if ((int) $existing['claimed_by'] !== $userId && !rbac_can('action.manage_calls')) {
            json_error('Only the current claimant or a supervisor may release this call', 403);
        }
        $result = inbound_call_release($id, $userId, $userName);
        json_response(['success' => $result['ok'], 'reason' => $result['reason'] ?? null]);
    }

    if ($action === 'reassign') {
        p149_require_csrf($input);
        // FR-18a: deliberately the SAME permission as an ordinary claim --
        // no action.manage_calls, no reason. Fast and ungated by design.
        if (!rbac_can('action.claim_call')) json_error('Insufficient permissions: claim calls', 403);
        if ($id <= 0) json_error('id required');
        $existing = inbound_call_get($id);
        if ($existing && !p149_user_can_see_call($existing)) json_error('Call not found', 404);
        $result = inbound_call_reassign($id, $userId, $userName);
        if (!$result['ok']) {
            json_response([
                'success'         => false,
                'reason'          => $result['reason'],
                'claimed_by_name' => $result['claimed_by_name'] ?? null,
                // Tells the client the FR-17 override path is still
                // available even though the fast path just refused.
                'can_force_reclaim' => rbac_can('action.manage_calls') || rbac_can('action.claim_call'),
            ]);
        }
        json_response(['success' => true, 'call' => $result['call']]);
    }

    if ($action === 'heartbeat') {
        if (!rbac_can('action.claim_call')) json_error('Insufficient permissions', 403);
        if ($id <= 0) json_error('id required');
        $result = inbound_call_heartbeat($id, $userId);
        json_response(['success' => $result['ok']]);
    }

    if ($action === 'force_reclaim') {
        p149_require_csrf($input);
        if ($id <= 0) json_error('id required');
        $existing = inbound_call_get($id);
        if (!$existing) json_error('Call not found', 404);
        if (!p149_user_can_see_call($existing)) json_error('Call not found', 404);
        $isStale = !empty($existing['stale_since']);
        // Two-tier authorization (FR-16/FR-17): a STALE claim only needs
        // the ordinary claim permission (recovering from an apparent
        // technical failure); an ACTIVE claim needs the separate,
        // supervisor-tier action.manage_calls PLUS a reason. Checked here
        // against the server's own view of staleness, never a
        // client-supplied flag -- inbound_call_force_reclaim() itself
        // re-verifies this independently as the authoritative gate.
        if ($isStale) {
            if (!rbac_can('action.claim_call')) json_error('Insufficient permissions', 403);
        } else {
            if (!rbac_can('action.manage_calls')) {
                json_error('Overriding an active claim requires supervisor permission (action.manage_calls)', 403);
            }
        }
        $reason = isset($input['reason']) ? trim((string) $input['reason']) : null;
        $result = inbound_call_force_reclaim($id, $userId, $userName, $reason);
        if (!$result['ok']) {
            json_response(['success' => false, 'reason' => $result['reason']]);
        }
        json_response(['success' => true, 'call' => $result['call'], 'was_stale' => $result['was_stale']]);
    }

    if ($action === 'reviewed') {
        p149_require_csrf($input);
        if (!rbac_can('action.claim_call')) json_error('Insufficient permissions', 403);
        if ($id <= 0) json_error('id required');
        $existing = inbound_call_get($id);
        if ($existing && !p149_user_can_see_call($existing)) json_error('Call not found', 404);
        $result = inbound_call_mark_reviewed($id, $userId, $userName);
        json_response(['success' => $result['ok'], 'reason' => $result['reason'] ?? null]);
    }

    if ($action === 'link_ticket') {
        p149_require_csrf($input);
        if (!rbac_can('action.claim_call')) json_error('Insufficient permissions', 403);
        $ticketId = (int) ($input['ticket_id'] ?? 0);
        if ($id <= 0 || $ticketId <= 0) json_error('id and ticket_id required');
        $existing = inbound_call_get($id);
        if ($existing && !p149_user_can_see_call($existing)) json_error('Call not found', 404);
        $result = inbound_call_link_ticket($id, $ticketId, $userId, $userName);
        json_response(['success' => $result['ok']]);
    }

    json_error('Unknown action', 404);
}

json_error('Method not allowed', 405);

ini_set('display_errors', $prevDisplay);
