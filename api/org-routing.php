<?php
/**
 * NewUI v4.0 API — Cross-Org Ticket Routing Rules (Phase 141, GH#70).
 *
 * GET  ?list=1       — rules visible to the caller: ALL rules for
 *                       action.manage_org_routing; only rules whose
 *                       owning_org_id is the caller's own resolved org for
 *                       action.manage_org_routing_org. Owning/target org
 *                       names and a computed match_description are
 *                       resolved server-side for display.
 * GET  ?meta=1       — incident-type metadata for the create/edit form:
 *                       distinct in_types.group values + the full
 *                       {id, type, group} list for the specific-type picker.
 * POST action=create — runs org_routing_rule_validate(); owning_org_id is
 *                       forced server-side for an org-scoped-only caller
 *                       (never trusts the client's copy); audit-logged.
 * POST action=update — tier-only edit; org_routing_rule_update() itself
 *                       rejects any attempt to change the org pair or match
 *                       target (immutability); audit-logged.
 * POST action=deactivate — active=0 + deactivated_at/deactivated_by; does
 *                       NOT retroactively revoke incident_shares rows
 *                       already created by this rule; audit-logged.
 *
 * No delete action — matches this table family's established
 * archive-never-hard-delete convention (Phase 138/140 precedent).
 *
 * RBAC (plan.md "RBAC" section — deliberately `rbac_can(...)` alone on
 * EVERY gate below, never `|| is_admin()`. rbac_can()'s own is_super
 * short-circuit already covers every real Super Admin; is_admin()'s extra
 * action.manage_config fallback would satisfy a correctly-scoped Org
 * Admin's narrower gate — exactly the leak class Phase 138 shipped and had
 * to fix, and the class this project's CLAUDE.md now names explicitly):
 *   action.manage_org_routing      — install-wide. Super Admin only in
 *                                     Phase 1's shipped default.
 *   action.manage_org_routing_org  — org-scoped self-service, excluded from
 *                                     Org Admin's default grant in Phase 1
 *                                     (plan.md open-question-1) — present so
 *                                     a Super Admin can hand-grant it
 *                                     per-install without a schema change.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
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

// See this file's own docblock + api/ics-form-types.php / api/public-board-admin.php's
// identical, already-documented reasoning: deliberately `rbac_can(...)` alone,
// never `|| is_admin()`.
$canAuthorGlobal  = rbac_can('action.manage_org_routing');
$canAuthorOrgOnly = rbac_can('action.manage_org_routing_org');
$canAuthorAny     = $canAuthorGlobal || $canAuthorOrgOnly;

function _ortr_require_csrf(array $input): void {
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) json_error('Invalid CSRF token', 403);
}

// The resolver/formatter logic itself (org_routing_resolve_caller_org_id(),
// org_routing_can_author_org(), org_routing_resolve_create_owning_org(),
// org_routing_row_out(), org_routing_schema_ready()) lives in
// inc/org-sharing.php, not here -- see that file's docblock for why: this
// file requires auth.php, which can exit before a locally-defined function
// would ever be reachable to a CLI unit test.

if (!org_routing_schema_ready()) {
    json_error('Cross-org ticket routing is not available on this install yet '
        . '-- run sql/run_phase141_cross_org_ticket_sharing.php.', 503);
}

// ═══════════════════════════════════════════════════════════════════════
//  GET — reads
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'GET' && isset($_GET['meta'])) {
    try {
        $groupRows = db_fetch_all(
            "SELECT DISTINCT `group` FROM `{$prefix}in_types`
              WHERE `group` IS NOT NULL AND `group` != '' ORDER BY `group`"
        );
        $typeRows = db_fetch_all(
            "SELECT `id`, `type`, `group` FROM `{$prefix}in_types` ORDER BY `type`"
        );
        json_response([
            'groups' => array_map(function ($r) { return $r['group']; }, $groupRows),
            'types'  => array_map(function ($r) {
                return ['id' => (int) $r['id'], 'type' => $r['type'], 'group' => $r['group']];
            }, $typeRows),
        ]);
    } catch (Throwable $e) {
        json_error_safe('Failed to load incident type metadata.', $e, 'org_routing.meta');
    }
}

if ($method === 'GET' && isset($_GET['list'])) {
    if (!$canAuthorAny) {
        json_error('Insufficient permissions: manage cross-org ticket routing', 403);
    }
    try {
        if ($canAuthorGlobal) {
            $rows = db_fetch_all(
                "SELECT r.*, oo.name AS owning_org_name, so.name AS shared_with_org_name, it.type AS match_type_name
                   FROM `{$prefix}org_type_routing` r
                   LEFT JOIN `{$prefix}organizations` oo ON oo.id = r.owning_org_id
                   LEFT JOIN `{$prefix}organizations` so ON so.id = r.shared_with_org_id
                   LEFT JOIN `{$prefix}in_types` it ON it.id = r.match_in_type_id
                  ORDER BY r.active DESC, r.created_at DESC"
            );
        } else {
            // Org-scoped-only author sees ONLY rules where THEIR OWN resolved
            // org is the owning_org_id -- never another org's rules, even
            // ones sharing INTO their org (that would surface a peer's
            // configuration choice this caller has no authority over).
            $callerOrgId = org_routing_resolve_caller_org_id((int) ($_SESSION['user_id'] ?? 0));
            $rows = db_fetch_all(
                "SELECT r.*, oo.name AS owning_org_name, so.name AS shared_with_org_name, it.type AS match_type_name
                   FROM `{$prefix}org_type_routing` r
                   LEFT JOIN `{$prefix}organizations` oo ON oo.id = r.owning_org_id
                   LEFT JOIN `{$prefix}organizations` so ON so.id = r.shared_with_org_id
                   LEFT JOIN `{$prefix}in_types` it ON it.id = r.match_in_type_id
                  WHERE r.owning_org_id = ?
                  ORDER BY r.active DESC, r.created_at DESC",
                [$callerOrgId]
            );
        }
        json_response(['rules' => array_map('org_routing_row_out', $rows)]);
    } catch (Throwable $e) {
        json_error_safe('Failed to load routing rules.', $e, 'org_routing.list');
    }
}

if ($method === 'GET') {
    json_error('Unknown action');
}

// ═══════════════════════════════════════════════════════════════════════
//  POST — writes
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'POST') {
    if (!$canAuthorAny) {
        json_error('Insufficient permissions: manage cross-org ticket routing', 403);
    }
    _ortr_require_csrf($input);

    $userId   = (int) ($_SESSION['user_id'] ?? 0);
    $userName = $_SESSION['user'] ?? '';

    if ($action === 'create') {
        $requestedOrgId = array_key_exists('owning_org_id', $input) && $input['owning_org_id'] !== null && $input['owning_org_id'] !== ''
            ? (int) $input['owning_org_id'] : null;
        $callerOrgId = org_routing_resolve_caller_org_id($userId);
        $decision = org_routing_resolve_create_owning_org($canAuthorGlobal, $callerOrgId, $requestedOrgId);
        if (!$decision['ok']) json_error($decision['error'], $decision['status']);
        $input['owning_org_id'] = $decision['org_id'];

        $result = org_routing_rule_create($input, $userId, $userName);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true, 'id' => $result['id']]);
    }

    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id is required');

        try {
            $existing = db_fetch_one("SELECT `owning_org_id` FROM `{$prefix}org_type_routing` WHERE `id` = ?", [$id]);
        } catch (Throwable $e) {
            json_error_safe('Failed to load routing rule.', $e, 'org_routing.update.load');
        }
        if (!$existing) json_error('Routing rule not found', 404);

        $existingOwningOrgId = (int) $existing['owning_org_id'];
        if (!org_routing_can_author_org($canAuthorGlobal, $existingOwningOrgId)) {
            json_error('Routing rule not found', 404); // no enumeration signal
        }

        // org_routing_rule_update() itself enforces immutability of the org
        // pair / match target -- rejecting the request if $input attempts to
        // change any of them. Nothing further needed here.
        $result = org_routing_rule_update($id, $input, $userId);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true, 'id' => $id]);
    }

    if ($action === 'deactivate') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id is required');

        try {
            $existing = db_fetch_one("SELECT `owning_org_id` FROM `{$prefix}org_type_routing` WHERE `id` = ?", [$id]);
        } catch (Throwable $e) {
            json_error_safe('Failed to load routing rule.', $e, 'org_routing.deactivate.load');
        }
        if (!$existing) json_error('Routing rule not found', 404);

        $existingOwningOrgId = (int) $existing['owning_org_id'];
        if (!org_routing_can_author_org($canAuthorGlobal, $existingOwningOrgId)) {
            json_error('Routing rule not found', 404);
        }

        $result = org_routing_rule_deactivate($id, $userId);
        if (!$result['success']) json_error(implode(' ', $result['errors']));
        json_response(['ok' => true, 'id' => $id]);
    }

    json_error('Unknown action: ' . $action);
}

json_error('Method not allowed', 405);
