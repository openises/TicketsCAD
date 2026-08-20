<?php
/**
 * NewUI v4.0 API — Cross-Org Standing Relationships (Phase 143, GH#70 Phase 3).
 *
 * GET  ?list=1              — relationships visible to the caller: ALL, for
 *                              action.manage_org_relationships; only
 *                              relationships the caller's own org (via
 *                              org_visible_ids()) is a MEMBER of (any status)
 *                              or the PROPOSER of, otherwise. Each row carries
 *                              its member list, its current live activation
 *                              (if any, for the admin UI's countdown), and a
 *                              server `now` timestamp so the client can
 *                              compute a countdown without trusting its own
 *                              clock alone.
 * POST action=propose       — org_relationship_create_or_propose().
 * POST action=add_member    — org_relationship_member_add().
 * POST action=approve_member — org_relationship_member_approve().
 * POST action=reject_member — org_relationship_member_reject(). Used for
 *                              BOTH rejecting a pending row and withdrawing
 *                              an approved member (same underlying function
 *                              — see inc/org-relationships.php's own
 *                              docblock). The admin UI requires a
 *                              named-confirmation step (the org's own name
 *                              typed) before submitting a withdraw — that is
 *                              a client-side UX guard only; this endpoint
 *                              does not read or trust any client-asserted
 *                              "confirmed" field. The real authorization is
 *                              org_relationship_can_act_for_org() re-run
 *                              server-side against the row's own org_id,
 *                              inside org_relationship_member_reject()
 *                              itself, exactly as it is for approve.
 * POST action=activate       — org_relationship_activate().
 * POST action=deactivate     — org_relationship_deactivate() (manual path;
 *                              $autoExpired is always false here — the
 *                              auto-expired path belongs exclusively to
 *                              tools/org_relationship_cleanup_tick.php).
 *
 * RBAC (plan.md "RBAC" section — deliberately rbac_can(...) alone on every
 * gate below, never `|| is_admin()`, same reasoning as api/org-routing.php's
 * and api/incident-share.php's own already-documented reasoning, and this
 * project's own standing CLAUDE.md rule: rbac_can()'s own is_super
 * short-circuit already covers every real Super Admin; is_admin()'s extra
 * action.manage_config fallback would satisfy a correctly-scoped Org Admin's
 * narrower gate — exactly the leak class this project's history exists to
 * prevent):
 *   action.manage_org_relationships       — install-wide. Super Admin only
 *                                            in this phase's shipped default.
 *   action.manage_org_relationships_org   — org-scoped propose/administer
 *                                            (propose/add_member/approve/
 *                                            reject). Granted to Org Admin
 *                                            and Dispatcher by default.
 *   action.activate_org_relationship      — activate/deactivate. Granted to
 *                                            Org Admin and Dispatcher by
 *                                            default.
 *
 * CRITICAL: the $canActGlobal boolean passed into every inc/org-relationships.php
 * function below is EXACTLY rbac_can('action.manage_org_relationships') —
 * never OR'd with either of the other two codes. org_relationship_can_act_for_org()
 * and _org_relationship_caller_is_approved_member() both treat $canActGlobal=true
 * as an unconditional per-row/per-org bypass; OR-ing in action.manage_org_relationships_org
 * or action.activate_org_relationship here would silently let an org-scoped
 * holder of either narrower code act on behalf of ANY org, not just their
 * own — the exact bypass the two-party consent model exists to prevent. Only
 * the ENDPOINT-REACHABILITY gate below (whether the caller may call the
 * action at all) uses the OR'd form; the identity actually passed into the
 * authorization primitive is always the single, precise, install-wide flag.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-relationships.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
}

// Deliberately rbac_can(...) alone — see this file's own docblock. This is
// the SINGLE, PRECISE, install-wide flag that gets passed into every
// inc/org-relationships.php function as $canActGlobal — never OR'd.
$canActGlobal    = rbac_can('action.manage_org_relationships');
$canManageOrg    = rbac_can('action.manage_org_relationships_org');
$canActivateCode = rbac_can('action.activate_org_relationship');

$canProposeAny  = $canActGlobal || $canManageOrg;   // propose / add_member / approve / reject
$canActivateAny = $canActGlobal || $canActivateCode; // activate / deactivate
$canViewAny     = $canProposeAny || $canActivateAny; // GET list

function _orr_require_csrf(array $input): void {
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) json_error('Invalid CSRF token', 403);
}

if (!org_relationships_schema_ready()) {
    json_error('Cross-org standing relationships are not available on this install yet '
        . '-- run sql/run_phase143_cross_org_standing_relationships.php.', 503);
}

// ═══════════════════════════════════════════════════════════════════════
//  GET — list (display-only; every write below independently re-checks
//  RBAC + row-level ownership rather than trusting anything computed here)
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'GET' && isset($_GET['list'])) {
    if (!$canViewAny) {
        json_error('Insufficient permissions: manage cross-org standing relationships', 403);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $visible = org_visible_ids($userId);
    $visibleInt = $visible === null ? null : array_map('intval', $visible);

    try {
        if ($canActGlobal) {
            $relRows = db_fetch_all(
                "SELECT * FROM `{$prefix}org_relationships` ORDER BY
                    FIELD(`status`, 'active', 'pending', 'rejected'), `created_at` DESC"
            );
        } elseif ($visibleInt === null) {
            // Super Admin fallback shape (org_visible_ids() returned null
            // without action.manage_org_relationships itself being held —
            // consistent with org_relationship_can_act_for_org()'s own
            // treatment of this case).
            $relRows = db_fetch_all(
                "SELECT * FROM `{$prefix}org_relationships` ORDER BY
                    FIELD(`status`, 'active', 'pending', 'rejected'), `created_at` DESC"
            );
        } elseif (empty($visibleInt)) {
            $relRows = [];
        } else {
            $ph = implode(',', array_fill(0, count($visibleInt), '?'));
            $relRows = db_fetch_all(
                "SELECT DISTINCT r.* FROM `{$prefix}org_relationships` r
                   LEFT JOIN `{$prefix}org_relationships_members` m ON m.relationship_id = r.id
                  WHERE m.org_id IN ($ph) OR r.created_by = ?
                  ORDER BY FIELD(r.status, 'active', 'pending', 'rejected'), r.created_at DESC",
                array_merge($visibleInt, [$userId])
            );
        }

        $out = [];
        foreach ($relRows as $rel) {
            $relId = (int) $rel['id'];

            $memberRows = db_fetch_all(
                "SELECT m.*, o.name AS org_name
                   FROM `{$prefix}org_relationships_members` m
                   LEFT JOIN `{$prefix}organizations` o ON o.id = m.org_id
                  WHERE m.relationship_id = ?
                  ORDER BY m.id",
                [$relId]
            );
            $members = array_map(function ($m) {
                return [
                    'id'                => (int) $m['id'],
                    'org_id'            => (int) $m['org_id'],
                    'org_name'          => $m['org_name'] !== null ? $m['org_name'] : ('Org #' . (int) $m['org_id']),
                    'status'            => (string) $m['status'],
                    'proposed_by_name'  => (string) ($m['proposed_by_name'] ?? ''),
                    'proposed_at'       => $m['proposed_at'],
                    'approved_by_name'  => (string) ($m['approved_by_name'] ?? ''),
                    'approved_at'       => $m['approved_at'],
                    'rejected_by_name'  => (string) ($m['rejected_by_name'] ?? ''),
                    'rejected_at'       => $m['rejected_at'],
                    'rejection_reason'  => $m['rejection_reason'],
                ];
            }, $memberRows);

            $liveActivation = db_fetch_one(
                "SELECT `id`, `activated_at`, `activated_by_name`, `activation_reason`, `max_activation_minutes`
                   FROM `{$prefix}org_relationships_activations`
                  WHERE `relationship_id` = ? AND `deactivated_at` IS NULL
                  LIMIT 1",
                [$relId]
            );

            // Display-only "can I act for this org" hint — the client uses
            // this to decide which buttons to show. NEVER the authority for
            // whether a write is allowed; every write below re-derives this
            // from scratch server-side via org_relationship_can_act_for_org().
            $myOrgIds = [];
            if (!$canActGlobal && $visibleInt !== null) {
                foreach ($memberRows as $m) {
                    $oid = (int) $m['org_id'];
                    if (in_array($oid, $visibleInt, true)) $myOrgIds[] = $oid;
                }
            }

            $out[] = [
                'id'                     => $relId,
                'name'                   => (string) $rel['name'],
                'relationship_type'      => (string) $rel['relationship_type'],
                'access_tier'            => (string) $rel['access_tier'],
                'redaction_profile'      => (string) $rel['redaction_profile'],
                'requires_activation'    => (bool) $rel['requires_activation'],
                'max_activation_minutes' => $rel['max_activation_minutes'] !== null ? (int) $rel['max_activation_minutes'] : null,
                'status'                 => (string) $rel['status'],
                'created_by_name'        => (string) ($rel['created_by_name'] ?? ''),
                'created_at'             => $rel['created_at'],
                'members'                => $members,
                'live_activation'        => $liveActivation ? [
                    'id'                     => (int) $liveActivation['id'],
                    'activated_at'           => $liveActivation['activated_at'],
                    'activated_by_name'      => (string) ($liveActivation['activated_by_name'] ?? ''),
                    'activation_reason'      => $liveActivation['activation_reason'],
                    'max_activation_minutes' => $liveActivation['max_activation_minutes'] !== null ? (int) $liveActivation['max_activation_minutes'] : null,
                ] : null,
                'my_org_ids'             => $myOrgIds,
                'can_act_global'         => $canActGlobal,
            ];
        }

        json_response([
            'relationships'    => $out,
            // Local server clock, matching api/pending-messages.php's own
            // 'now' convention -- the same basis MySQL's NOW() writes
            // activated_at with, so a naive `new Date(str.replace(' ','T'))`
            // parse on the client compares like-for-like (this codebase's
            // established countdown pattern, assets/js/config.js
            // pmFriendlyDelta() -- no server/browser clock-skew correction
            // attempted, matching precedent exactly).
            'server_now'       => date('Y-m-d H:i:s'),
            'can_propose'      => $canProposeAny,
            'can_activate_any' => $canActivateAny,
        ]);
    } catch (Throwable $e) {
        json_error_safe('Failed to load standing relationships.', $e, 'org_relationships.list');
    }
}

if ($method === 'GET') {
    json_error('Unknown action');
}

// ═══════════════════════════════════════════════════════════════════════
//  POST — writes
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'POST') {
    if (!$canViewAny) {
        json_error('Insufficient permissions: manage cross-org standing relationships', 403);
    }
    _orr_require_csrf($input);

    $userId   = (int) ($_SESSION['user_id'] ?? 0);
    $userName = $_SESSION['user'] ?? '';

    if ($action === 'propose') {
        if (!$canProposeAny) {
            json_error('Insufficient permissions: propose cross-org standing relationships', 403);
        }
        $result = org_relationship_create_or_propose($input, $canActGlobal, $userId, $userName);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true, 'id' => $result['id'], 'status' => $result['status']]);
    }

    if ($action === 'add_member') {
        if (!$canProposeAny) {
            json_error('Insufficient permissions: administer cross-org standing relationships', 403);
        }
        $relationshipId = (int) ($input['relationship_id'] ?? 0);
        $orgId          = (int) ($input['org_id'] ?? 0);
        if ($relationshipId <= 0 || $orgId <= 0) json_error('relationship_id and org_id are required');

        $result = org_relationship_member_add($relationshipId, $orgId, $canActGlobal, $userId, $userName);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true, 'id' => $result['id']]);
    }

    if ($action === 'approve_member') {
        if (!$canProposeAny) {
            json_error('Insufficient permissions: administer cross-org standing relationships', 403);
        }
        $memberId = (int) ($input['member_id'] ?? 0);
        if ($memberId <= 0) json_error('member_id is required');

        $result = org_relationship_member_approve($memberId, $canActGlobal, $userId, $userName);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true]);
    }

    if ($action === 'reject_member') {
        if (!$canProposeAny) {
            json_error('Insufficient permissions: administer cross-org standing relationships', 403);
        }
        $memberId = (int) ($input['member_id'] ?? 0);
        // Deliberately NOT read: any client-asserted "confirmed"/typed-name
        // field. The admin UI's named-confirmation step (type the org's own
        // name before the button enables) is a UX guard only, per plan.md's
        // Admin UI section — it is never consulted here as an authority.
        // The only real check is org_relationship_can_act_for_org(), re-run
        // inside org_relationship_member_reject() itself against the row's
        // OWN org_id.
        $reason   = array_key_exists('reason', $input) ? (string) $input['reason'] : null;
        if ($memberId <= 0) json_error('member_id is required');

        $result = org_relationship_member_reject($memberId, $canActGlobal, $userId, $userName, $reason);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true]);
    }

    if ($action === 'activate') {
        if (!$canActivateAny) {
            json_error('Insufficient permissions: activate cross-org standing relationships', 403);
        }
        $relationshipId    = (int) ($input['relationship_id'] ?? 0);
        $reason            = array_key_exists('reason', $input) ? (string) $input['reason'] : null;
        $requestedMinutes  = (isset($input['max_activation_minutes']) && $input['max_activation_minutes'] !== '')
            ? (int) $input['max_activation_minutes'] : null;
        if ($relationshipId <= 0) json_error('relationship_id is required');

        $result = org_relationship_activate($relationshipId, $canActGlobal, $userId, $userName, $reason, $requestedMinutes);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true, 'id' => $result['id']]);
    }

    if ($action === 'deactivate') {
        if (!$canActivateAny) {
            json_error('Insufficient permissions: deactivate cross-org standing relationships', 403);
        }
        $relationshipId = (int) ($input['relationship_id'] ?? 0);
        $reason         = array_key_exists('reason', $input) ? (string) $input['reason'] : null;
        if ($relationshipId <= 0) json_error('relationship_id is required');

        // $autoExpired is always false here -- the auto-expired path
        // belongs exclusively to tools/org_relationship_cleanup_tick.php,
        // which calls org_relationship_deactivate() directly (never through
        // this HTTP endpoint) with $callerUserId = 0.
        $result = org_relationship_deactivate($relationshipId, $canActGlobal, $userId, $userName, $reason, false);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true]);
    }

    json_error('Unknown action: ' . $action);
}

json_error('Method not allowed', 405);
