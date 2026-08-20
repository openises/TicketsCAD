<?php
/**
 * NewUI v4.0 API — Manual Cross-Org Incident Sharing (Phase 142, GH#70 Phase 2).
 *
 * GET  ?ticket_id=N   — active shares on a ticket the caller's OWN org
 *                        owns, plus can_create/can_revoke booleans for the
 *                        modal's conditional rendering. Deliberately
 *                        owning-org-only (org_ticket_is_owned_by_caller()),
 *                        NOT merely org_can_see_ticket()-gated — a
 *                        shared-in org has no legitimate need to see the
 *                        full roster of which OTHER orgs a ticket is
 *                        shared with (an org-to-org relationship
 *                        disclosure between two separate response
 *                        agencies), same reasoning as the target-org
 *                        picker's own scope (plan.md).
 * POST action=create  — org_sharing_create_manual_share(). Gated on
 *                        action.share_incident AND
 *                        org_ticket_is_owned_by_caller($ticketId).
 * POST action=revoke  — org_sharing_revoke_share(). Gated on
 *                        action.revoke_incident_share ONLY at this entry
 *                        point — the ownership check happens INSIDE
 *                        org_sharing_revoke_share() against the row's own
 *                        derived ticket_id (plan.md's IDOR guard / Task
 *                        3-4's anti-chaining section). There is
 *                        deliberately no ticket_id parameter on this
 *                        action to pre-check against.
 *
 * RBAC (plan.md "RBAC" section — deliberately rbac_can(...) alone on
 * every gate below, never `|| is_admin()`, same reasoning as
 * api/org-routing.php's own docblock and this project's own CLAUDE.md
 * rule: rbac_can()'s own is_super short-circuit already covers every real
 * Super Admin; is_admin()'s extra action.manage_config fallback would
 * satisfy a correctly-scoped Org Admin's narrower gate):
 *   action.share_incident         — create a manual share.
 *   action.revoke_incident_share  — revoke an active share (manual or
 *                                    rule-sourced — revoke doesn't care
 *                                    how a share was created, only that
 *                                    the caller owns the ticket).
 * Both are granted to Dispatcher and Org Admin by default (plan.md's
 * deliberate departure from Phase 141's Super-Admin-only precedent — see
 * plan.md's "RBAC" section for the full reasoning). The load-bearing
 * security control is NOT RBAC — it's org_ticket_is_owned_by_caller(),
 * re-verified on every single request, inside inc/org-sharing.php's write
 * functions themselves (defense in depth: safe even if this endpoint ever
 * forgot its own copy of the check).
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
}

// Deliberately rbac_can(...) alone — see this file's own docblock.
$canShare  = rbac_can('action.share_incident');
$canRevoke = rbac_can('action.revoke_incident_share');
$canManage = $canShare || $canRevoke; // either permission unlocks GET (the read-only share list)

function _incshare_require_csrf(array $input): void {
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) json_error('Invalid CSRF token', 403);
}

// ═══════════════════════════════════════════════════════════════════════
//  GET — current shares on a ticket
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'GET') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) json_error('ticket_id is required');

    if (!$canManage) {
        json_error('Insufficient permissions: share incidents', 403);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    // Deliberately owning-org-only — see this file's own docblock. A
    // caller whose visibility into this ticket is itself share-derived
    // gets the SAME 404-not-403 "not found" shape org_can_see_ticket()
    // callers elsewhere in this codebase use for a ticket that genuinely
    // doesn't exist, so this endpoint doesn't leak "a ticket with this id
    // exists but you can't manage its sharing" to a caller with zero
    // visibility into it either way.
    if (!org_ticket_is_owned_by_caller($ticketId, $userId)) {
        if (org_can_see_ticket($ticketId, $userId)) {
            json_error('Insufficient permissions: manage sharing on this ticket', 403);
        }
        json_error('Incident not found', 404);
    }

    try {
        $shares = org_sharing_list_active_shares($ticketId);
        // owning_org_id — the ticket's OWN org, so the target-org picker
        // (assets/js/incident-detail.js) can exclude it from the
        // organizations.php?action=list fetch it reuses, same filtering
        // shape as org-routing-admin.js's populateTargetOrgSelect(). We
        // already confirmed the caller owns this ticket above (the
        // org_ticket_is_owned_by_caller() gate), so this is safe to
        // return directly rather than round-tripping through
        // api/incident-detail.php's own response, which omits org_id for
        // an owning-org caller (it only appears server-side as
        // shared_from_org_id for a SHARED-IN viewer, per
        // api/incident-detail.php's own Phase 141 code).
        $owningOrgId = (int) db_fetch_value(
            "SELECT `org_id` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1",
            [$ticketId]
        );
        json_response([
            'shares'         => $shares,
            'can_create'     => $canShare,
            'can_revoke'     => $canRevoke,
            'owning_org_id'  => $owningOrgId ?: null,
        ]);
    } catch (Throwable $e) {
        json_error_safe('Failed to load shares.', $e, 'incident_share.get');
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  POST — writes
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'POST') {
    $userId   = (int) ($_SESSION['user_id'] ?? 0);
    $userName = $_SESSION['user'] ?? '';

    if ($action === 'create') {
        if (!$canShare) {
            json_error('Insufficient permissions: share incidents', 403);
        }
        _incshare_require_csrf($input);

        $ticketId        = (int) ($input['ticket_id'] ?? 0);
        $sharedWithOrgId = (int) ($input['shared_with_org_id'] ?? 0);
        $accessTier      = (string) ($input['access_tier'] ?? 'view');
        $reason          = (string) ($input['reason'] ?? '');

        if ($ticketId <= 0) json_error('ticket_id is required');

        // Endpoint-level ownership check (defense-in-depth alongside the
        // one org_sharing_create_manual_share() itself performs first,
        // per plan.md's anti-chaining section) — lets this endpoint return
        // the same 404-not-403 "not found" shape as GET above for a
        // caller with no visibility at all, rather than a generic
        // validation error from the writer.
        if (!org_ticket_is_owned_by_caller($ticketId, $userId)) {
            if (org_can_see_ticket($ticketId, $userId)) {
                json_error('Insufficient permissions: manage sharing on this ticket', 403);
            }
            json_error('Incident not found', 404);
        }

        $result = org_sharing_create_manual_share($ticketId, $sharedWithOrgId, $accessTier, $reason, $userId, $userName);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true, 'id' => $result['id']]);
    }

    if ($action === 'revoke') {
        if (!$canRevoke) {
            json_error('Insufficient permissions: revoke incident shares', 403);
        }
        _incshare_require_csrf($input);

        $shareId = (int) ($input['share_id'] ?? 0);
        $reason  = array_key_exists('reason', $input) ? (string) $input['reason'] : null;

        if ($shareId <= 0) json_error('share_id is required');

        // NO ticket_id pre-check here — deliberately. org_sharing_revoke_share()
        // looks the row up by its OWN id first, derives ticket_id FROM that
        // row, and only THEN checks org_ticket_is_owned_by_caller() against
        // the DERIVED ticket_id. Pre-checking against a caller-supplied
        // ticket_id (there isn't one to check) is exactly the IDOR shape
        // plan.md's anti-chaining section rules out.
        $result = org_sharing_revoke_share($shareId, $reason, $userId, $userName);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true]);
    }

    json_error('Unknown action: ' . $action);
}

json_error('Method not allowed', 405);
