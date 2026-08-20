<?php
/**
 * Phase 143 (2026-08-17) — Cross-org STANDING relationships + time-boxed
 * activation windows (GH#70 Phase 3, the final phase of the Option D build).
 *
 * Every Phase-143-specific function (relationship propose/approve/reject,
 * activation/deactivation, the reusable per-row consent-authorization check,
 * the read-time expiry predicate) lives here, not in inc/org-sharing.php —
 * see specs/phase-143-cross-org-standing-relationships/plan.md's "Core
 * decision" for why this earns its own file the same way org-sharing.php
 * earned its own file in Phase 141.
 *
 * inc/org-scope.php (org_can_see_ticket, org_ticket_query_filter,
 * org_can_mutate_ticket) and inc/org-sharing.php
 * (org_share_context_for_ticket, org_sharing_apply_list_redaction) each get
 * a small, deliberate widening that calls into this file lazily (guarded by
 * function_exists()/is_file() so an install without this file, or without
 * these tables, behaves exactly as it did pre-Phase-143). This file itself
 * requires org-scope.php for org_visible_ids().
 *
 * Design: specs/phase-143-cross-org-standing-relationships/{spec.md,plan.md,
 * tasks.md}. GitHub issue #70.
 *
 * Public API:
 *   org_relationship_can_act_for_org(bool $canActGlobal, int $rowOrgId, int $callerUserId): bool
 *   org_relationship_create_or_propose(array $input, bool $canActGlobal, int $callerUserId, string $callerUserName = ''): array
 *   org_relationship_member_add(int $relationshipId, int $orgId, bool $canActGlobal, int $callerUserId, string $callerUserName = ''): array
 *   org_relationship_member_approve(int $memberId, bool $canActGlobal, int $callerUserId, string $callerUserName = ''): array
 *   org_relationship_member_reject(int $memberId, bool $canActGlobal, int $callerUserId, string $callerUserName = '', ?string $reason = null): array
 *   org_relationship_activate(int $relationshipId, bool $canActGlobal, int $callerUserId, string $callerUserName = '', ?string $reason = null, ?int $requestedMinutes = null): array
 *   org_relationship_deactivate(int $relationshipId, bool $canActGlobal, int $callerUserId, string $callerUserName = '', ?string $reason = null, bool $autoExpired = false): array
 *   org_relationship_activation_live_join_sql(string $activationAlias = 'ra'): string
 *   org_relationship_context_for_ticket(int $ticketId, ?int $userId = null): ?array
 *   org_relationships_schema_ready(): bool
 *
 * Note on the $canActGlobal parameter: mirrors inc/org-sharing.php's own
 * established convention for this exact shape of decision
 * (org_routing_can_author_org(bool $canAuthorGlobal, ...) /
 * org_routing_resolve_create_owning_org(bool $canAuthorGlobal, ...)) —
 * computed by the CALLER (normally an api/*.php endpoint, via
 * rbac_can('action.manage_org_relationships')) and passed in, so every
 * function here stays a pure/testable unit with no live RBAC session
 * required. The one genuinely load-bearing security check is NOT this
 * parameter — it is org_relationship_can_act_for_org()'s per-row
 * org_visible_ids() comparison, re-run on every membership write, exactly
 * as plan.md's "Two-party consent" section describes.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org-scope.php';

// ─────────────────────────────────────────────────────────────────────
// org_relationship_can_act_for_org — the one reusable per-row
// authorization primitive the whole two-party consent model rests on.
// ─────────────────────────────────────────────────────────────────────

/**
 * "May $callerUserId act on behalf of the org named by $rowOrgId?" —
 * true for a global-capability caller (Super Admin / a hand-granted
 * action.manage_org_relationships holder — $canActGlobal, computed by the
 * caller), OR when $rowOrgId is among $callerUserId's own
 * org_visible_ids(). This is what makes the two-party guarantee genuinely
 * two-party: Org A's proposer can never make Org B's OWN membership row
 * move to approved, because Org A's org_visible_ids() never contains Org
 * B — see plan.md's "Two-party consent" section for the full walk-through.
 */
function org_relationship_can_act_for_org(bool $canActGlobal, int $rowOrgId, int $callerUserId): bool
{
    if ($canActGlobal) return true; // Super Admin / action.manage_org_relationships
    $visible = org_visible_ids($callerUserId);
    if ($visible === null) return true; // Super Admin fallback, consistent shape
    return in_array($rowOrgId, array_map('intval', $visible), true);
}

// ─────────────────────────────────────────────────────────────────────
// Internal helpers
// ─────────────────────────────────────────────────────────────────────

/**
 * DERIVED status recompute — called at the end of every membership
 * create/add/approve/reject, in the same request. 'rejected' if any
 * member row is rejected; 'active' if every member row is approved and
 * there are >= 2; otherwise 'pending'. Safe to read directly downstream
 * (org_relationships.status) because it changes ONLY here, synchronously,
 * never by elapsed wall-clock time — contrast the activation-liveness
 * predicate below, which is NEVER read from a stored column.
 */
function _org_relationship_recompute_status(int $relationshipId): void
{
    if ($relationshipId <= 0) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT `status` FROM `{$prefix}org_relationships_members` WHERE `relationship_id` = ?",
            [$relationshipId]
        );
    } catch (Throwable $e) {
        return;
    }
    if (empty($rows)) return;

    $anyRejected = false;
    $approvedCount = 0;
    foreach ($rows as $r) {
        $s = (string) $r['status'];
        if ($s === 'rejected') $anyRejected = true;
        if ($s === 'approved') $approvedCount++;
    }

    $newStatus = $anyRejected
        ? 'rejected'
        : (($approvedCount >= 2 && $approvedCount === count($rows)) ? 'active' : 'pending');

    try {
        db_query("UPDATE `{$prefix}org_relationships` SET `status` = ? WHERE `id` = ?", [$newStatus, $relationshipId]);
    } catch (Throwable $e) {
        error_log('[org-relationships] recompute_status failed for relationship ' . $relationshipId . ': ' . $e->getMessage());
    }
}

function _org_relationship_current_status(int $relationshipId): ?string
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $s = db_fetch_value("SELECT `status` FROM `{$prefix}org_relationships` WHERE `id` = ? LIMIT 1", [$relationshipId]);
        return $s !== null ? (string) $s : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Is $callerUserId's own org membership (org_visible_ids()) an APPROVED member of $relationshipId? */
function _org_relationship_caller_is_approved_member(int $relationshipId, bool $canActGlobal, int $callerUserId): bool
{
    if ($canActGlobal) return true;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $visible = org_visible_ids($callerUserId);
    if ($visible === null) return true; // Super Admin fallback shape
    $visibleInt = array_map('intval', $visible);
    if (empty($visibleInt)) return false;
    try {
        $ph = implode(',', array_fill(0, count($visibleInt), '?'));
        return (bool) db_fetch_value(
            "SELECT 1 FROM `{$prefix}org_relationships_members`
              WHERE `relationship_id` = ? AND `status` = 'approved' AND `org_id` IN ($ph) LIMIT 1",
            array_merge([$relationshipId], $visibleInt)
        );
    } catch (Throwable $e) {
        return false;
    }
}

function _org_relationship_audit(string $activity, int $relationshipId, string $summary, ?array $details = null): void
{
    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (!function_exists('audit_log')) return;
    audit_log(
        'config', $activity, 'org_relationship', $relationshipId, $summary, $details,
        defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3
    );
}

/**
 * Phase 143 -- coarse SSE lifecycle notify, mirrors
 * inc/org-sharing.php's own _org_sharing_notify_share_change() shape.
 * Published once per activation/deactivation (never per-ticket) to every
 * currently-approved member org via the SAME 'org' scope Phase 142 already
 * built into api/stream.php -- zero reader-side changes needed. Wrapped
 * end-to-end in try/catch and never throws.
 */
function _org_relationship_notify_lifecycle(string $eventType, int $relationshipId, ?int $userId): void
{
    if (!function_exists('sse_publish')) {
        if (is_file(__DIR__ . '/sse.php')) require_once __DIR__ . '/sse.php';
    }
    if (!function_exists('sse_publish')) return;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT `org_id` FROM `{$prefix}org_relationships_members` WHERE `relationship_id` = ? AND `status` = 'approved'",
            [$relationshipId]
        );
        $memberOrgIds = [];
        foreach ($rows as $r) {
            $oid = (int) ($r['org_id'] ?? 0);
            if ($oid > 0) $memberOrgIds[] = $oid;
        }
    } catch (Throwable $e) {
        $memberOrgIds = [];
    }
    if (empty($memberOrgIds)) return;

    try {
        sse_publish($eventType, ['relationship_id' => $relationshipId], $userId, 'org', $memberOrgIds);
    } catch (Throwable $e) {
        error_log('[org-relationships] lifecycle SSE notify failed: ' . $e->getMessage());
    }
}

/** Does org_relationships exist yet on this install (pre-migration guard)? */
function org_relationships_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $n = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'org_relationships']
        );
        $ready = $n > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

// ─────────────────────────────────────────────────────────────────────
// Two-party consent — create / propose / add member / approve / reject
// ─────────────────────────────────────────────────────────────────────

/**
 * Creates a new org_relationships row plus one org_relationships_members
 * row per named org id (>= 2 distinct org ids required). Per-org
 * auto-approval is delegated entirely to org_relationship_can_act_for_org()
 * -- a global caller ($canActGlobal=true) auto-approves EVERY named org
 * immediately (status recomputes straight to 'active' at >= 2 members); an
 * org-scoped caller auto-approves only orgs within their own
 * org_visible_ids() (normally exactly their own org), leaving every other
 * named org 'pending' -- exactly plan.md's two creation branches, with no
 * separate code path needed for either.
 *
 * @param array $input ['name'=>string, 'relationship_type'=>?string,
 *   'member_org_ids'=>int[], 'access_tier'=>?string,
 *   'redaction_profile'=>?string, 'requires_activation'=>?bool,
 *   'max_activation_minutes'=>?int]
 * @return array{success:bool, errors:string[], id:?int, status:?string}
 */
function org_relationship_create_or_propose(array $input, bool $canActGlobal, int $callerUserId, string $callerUserName = ''): array
{
    $errors = [];

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        $errors[] = 'Name is required';
    } elseif (strlen($name) > 128) {
        $errors[] = 'Name must be 128 characters or fewer';
    }

    $relationshipType = trim((string) ($input['relationship_type'] ?? 'mutual_aid'));
    if ($relationshipType === '') $relationshipType = 'mutual_aid';

    $accessTier = (string) ($input['access_tier'] ?? 'view');
    if (!in_array($accessTier, ['view', 'assist'], true)) {
        $errors[] = "access_tier must be 'view' or 'assist'";
    }
    $redactionProfile = (string) ($input['redaction_profile'] ?? 'view');
    if (!in_array($redactionProfile, ['view', 'assist'], true)) {
        $errors[] = "redaction_profile must be 'view' or 'assist'";
    }
    $requiresActivation = array_key_exists('requires_activation', $input) ? (int) !!$input['requires_activation'] : 1;

    $maxActivationMinutes = null;
    if (isset($input['max_activation_minutes']) && $input['max_activation_minutes'] !== '') {
        $maxActivationMinutes = (int) $input['max_activation_minutes'];
        if ($maxActivationMinutes <= 0) $errors[] = 'max_activation_minutes must be a positive integer when set';
    }

    $memberOrgIds = [];
    foreach ((array) ($input['member_org_ids'] ?? []) as $oid) {
        $oid = (int) $oid;
        if ($oid > 0 && !in_array($oid, $memberOrgIds, true)) $memberOrgIds[] = $oid;
    }
    if (count($memberOrgIds) < 2) {
        $errors[] = 'At least 2 distinct member organizations are required';
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    foreach ($memberOrgIds as $oid) {
        try {
            $exists = db_fetch_value("SELECT 1 FROM `{$prefix}organizations` WHERE `id` = ? LIMIT 1", [$oid]);
            if (!$exists) $errors[] = "Organization #{$oid} does not exist";
        } catch (Throwable $e) {
            $errors[] = 'Could not verify member organizations';
            break;
        }
    }

    // Org-scoped caller must name their own org as one of the initial
    // members -- "the act of proposing IS their consent" (plan.md).
    if (!$canActGlobal && empty($errors)) {
        $visible = org_visible_ids($callerUserId);
        $visibleInt = $visible === null ? null : array_map('intval', $visible);
        $ownOrgNamed = $visibleInt === null ? true : (bool) array_intersect($visibleInt, $memberOrgIds);
        if (!$ownOrgNamed) {
            $errors[] = 'You must name your own organization as one of the initial members';
        }
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors, 'id' => null, 'status' => null];
    }

    try {
        db_query(
            "INSERT INTO `{$prefix}org_relationships`
                (`name`, `relationship_type`, `access_tier`, `redaction_profile`,
                 `requires_activation`, `max_activation_minutes`, `status`,
                 `created_by`, `created_by_name`)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
            [$name, $relationshipType, $accessTier, $redactionProfile,
             $requiresActivation, $maxActivationMinutes, $callerUserId, $callerUserName]
        );
        $relationshipId = (int) db_insert_id();
    } catch (Throwable $e) {
        error_log('[org-relationships] create failed: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not create relationship'], 'id' => null, 'status' => null];
    }

    foreach ($memberOrgIds as $oid) {
        $approvedNow = org_relationship_can_act_for_org($canActGlobal, $oid, $callerUserId);
        try {
            if ($approvedNow) {
                db_query(
                    "INSERT INTO `{$prefix}org_relationships_members`
                        (`relationship_id`, `org_id`, `status`, `proposed_by`, `proposed_by_name`,
                         `approved_by`, `approved_by_name`, `approved_at`)
                     VALUES (?, ?, 'approved', ?, ?, ?, ?, NOW())",
                    [$relationshipId, $oid, $callerUserId, $callerUserName, $callerUserId, $callerUserName]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}org_relationships_members`
                        (`relationship_id`, `org_id`, `status`, `proposed_by`, `proposed_by_name`)
                     VALUES (?, ?, 'pending', ?, ?)",
                    [$relationshipId, $oid, $callerUserId, $callerUserName]
                );
            }
        } catch (Throwable $e) {
            error_log('[org-relationships] member insert failed for relationship ' . $relationshipId . ' org ' . $oid . ': ' . $e->getMessage());
        }
    }

    _org_relationship_recompute_status($relationshipId);

    _org_relationship_audit(
        'relationship_proposed', $relationshipId,
        "Proposed cross-org standing relationship '{$name}' (#{$relationshipId}) among orgs " . implode(', ', $memberOrgIds),
        ['member_org_ids' => $memberOrgIds, 'access_tier' => $accessTier,
         'redaction_profile' => $redactionProfile, 'requires_activation' => (bool) $requiresActivation]
    );

    return ['success' => true, 'errors' => [], 'id' => $relationshipId, 'status' => _org_relationship_current_status($relationshipId)];
}

/**
 * Adds a member org to an EXISTING relationship, same auto-approval branch
 * logic as creation. A previously-rejected/withdrawn row for the same
 * (relationship, org) pair is REVIVED (UPDATE), never re-INSERTed --
 * mirrors org_sharing_create_manual_share()'s own "revive a lapsed grant"
 * discipline for exactly the same reason (a stale row occupies the natural
 * key).
 *
 * @return array{success:bool, errors:string[], id:?int}
 */
function org_relationship_member_add(int $relationshipId, int $orgId, bool $canActGlobal, int $callerUserId, string $callerUserName = ''): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($relationshipId <= 0 || $orgId <= 0) {
        return ['success' => false, 'errors' => ['relationship_id and org_id are required'], 'id' => null];
    }

    try {
        $rel = db_fetch_one("SELECT `id`, `name` FROM `{$prefix}org_relationships` WHERE `id` = ? LIMIT 1", [$relationshipId]);
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load relationship'], 'id' => null];
    }
    if (!$rel) return ['success' => false, 'errors' => ['Relationship not found'], 'id' => null];

    try {
        $orgExists = db_fetch_value("SELECT 1 FROM `{$prefix}organizations` WHERE `id` = ? LIMIT 1", [$orgId]);
    } catch (Throwable $e) {
        $orgExists = false;
    }
    if (!$orgExists) return ['success' => false, 'errors' => ['Organization does not exist'], 'id' => null];

    try {
        $existing = db_fetch_one(
            "SELECT `id`, `status` FROM `{$prefix}org_relationships_members` WHERE `relationship_id` = ? AND `org_id` = ? LIMIT 1",
            [$relationshipId, $orgId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not check existing membership'], 'id' => null];
    }
    if ($existing && (string) $existing['status'] !== 'rejected') {
        return ['success' => false, 'errors' => ['This organization is already a member (or pending) of this relationship'], 'id' => (int) $existing['id']];
    }

    $approvedNow = org_relationship_can_act_for_org($canActGlobal, $orgId, $callerUserId);
    $approvedAt = $approvedNow ? date('Y-m-d H:i:s') : null;

    try {
        if ($existing) {
            db_query(
                "UPDATE `{$prefix}org_relationships_members`
                    SET `status` = ?, `proposed_by` = ?, `proposed_by_name` = ?, `proposed_at` = NOW(),
                        `approved_by` = ?, `approved_by_name` = ?, `approved_at` = ?,
                        `rejected_by` = NULL, `rejected_by_name` = '', `rejected_at` = NULL, `rejection_reason` = NULL
                  WHERE `id` = ?",
                [$approvedNow ? 'approved' : 'pending', $callerUserId, $callerUserName,
                 $approvedNow ? $callerUserId : null, $approvedNow ? $callerUserName : '', $approvedAt,
                 (int) $existing['id']]
            );
            $memberId = (int) $existing['id'];
        } else {
            db_query(
                "INSERT INTO `{$prefix}org_relationships_members`
                    (`relationship_id`, `org_id`, `status`, `proposed_by`, `proposed_by_name`,
                     `approved_by`, `approved_by_name`, `approved_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$relationshipId, $orgId, $approvedNow ? 'approved' : 'pending', $callerUserId, $callerUserName,
                 $approvedNow ? $callerUserId : null, $approvedNow ? $callerUserName : '', $approvedAt]
            );
            $memberId = (int) db_insert_id();
        }
    } catch (Throwable $e) {
        error_log('[org-relationships] member_add failed for relationship ' . $relationshipId . ' org ' . $orgId . ': ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not add member'], 'id' => null];
    }

    _org_relationship_recompute_status($relationshipId);

    _org_relationship_audit(
        'relationship_proposed', $relationshipId,
        "Added org #{$orgId} to relationship '{$rel['name']}' (#{$relationshipId})" . ($approvedNow ? ' (auto-approved)' : ' (pending approval)'),
        ['org_id' => $orgId, 'auto_approved' => $approvedNow]
    );

    return ['success' => true, 'errors' => [], 'id' => $memberId];
}

/**
 * Approves the CALLER'S OWN org's membership row. Gated by
 * org_relationship_can_act_for_org() against the ROW'S OWN org_id -- never
 * the relationship's proposer, never a caller-supplied org id. This is the
 * load-bearing security check the whole two-party model rests on: from Org
 * B's own admin's point of view, approving Org B's row IS Org B saying yes.
 *
 * @return array{success:bool, errors:string[]}
 */
function org_relationship_member_approve(int $memberId, bool $canActGlobal, int $callerUserId, string $callerUserName = ''): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT `id`, `relationship_id`, `org_id`, `status` FROM `{$prefix}org_relationships_members` WHERE `id` = ? LIMIT 1",
            [$memberId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load membership row']];
    }
    if (!$row) return ['success' => false, 'errors' => ['Membership row not found']];

    $rowOrgId = (int) $row['org_id'];
    if (!org_relationship_can_act_for_org($canActGlobal, $rowOrgId, $callerUserId)) {
        return ['success' => false, 'errors' => ['You are not authorized to act on behalf of this organization']];
    }
    if ((string) $row['status'] === 'approved') {
        return ['success' => false, 'errors' => ['This membership is already approved']];
    }

    try {
        db_query(
            "UPDATE `{$prefix}org_relationships_members`
                SET `status` = 'approved', `approved_by` = ?, `approved_by_name` = ?, `approved_at` = NOW(),
                    `rejected_by` = NULL, `rejected_by_name` = '', `rejected_at` = NULL, `rejection_reason` = NULL
              WHERE `id` = ?",
            [$callerUserId, $callerUserName, $memberId]
        );
    } catch (Throwable $e) {
        error_log('[org-relationships] member_approve failed for member ' . $memberId . ': ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not approve membership']];
    }

    $relationshipId = (int) $row['relationship_id'];
    _org_relationship_recompute_status($relationshipId);

    _org_relationship_audit(
        'relationship_member_approved', $relationshipId,
        "Org #{$rowOrgId} approved membership in relationship #{$relationshipId}",
        ['org_id' => $rowOrgId, 'member_id' => $memberId]
    );

    return ['success' => true, 'errors' => []];
}

/**
 * Rejects (or withdraws) the CALLER'S OWN org's membership row. A single
 * holdout blocks the whole named group -- _org_relationship_recompute_status()
 * flips the relationship's own status to 'rejected' even when every other
 * member already approved (plan.md: "never a unilateral grant, applied in
 * the negative direction"). Also used for a working relationship's
 * voluntary withdrawal -- same terminal 'rejected' status, no fourth
 * membership status invented.
 *
 * @return array{success:bool, errors:string[]}
 */
function org_relationship_member_reject(int $memberId, bool $canActGlobal, int $callerUserId, string $callerUserName = '', ?string $reason = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT `id`, `relationship_id`, `org_id`, `status` FROM `{$prefix}org_relationships_members` WHERE `id` = ? LIMIT 1",
            [$memberId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load membership row']];
    }
    if (!$row) return ['success' => false, 'errors' => ['Membership row not found']];

    $rowOrgId = (int) $row['org_id'];
    if (!org_relationship_can_act_for_org($canActGlobal, $rowOrgId, $callerUserId)) {
        return ['success' => false, 'errors' => ['You are not authorized to act on behalf of this organization']];
    }

    $reason = $reason !== null ? trim($reason) : null;
    if ($reason === '') $reason = null;
    if ($reason !== null && strlen($reason) > 255) {
        return ['success' => false, 'errors' => ['Reason must be 255 characters or fewer']];
    }

    try {
        db_query(
            "UPDATE `{$prefix}org_relationships_members`
                SET `status` = 'rejected', `rejected_by` = ?, `rejected_by_name` = ?, `rejected_at` = NOW(),
                    `rejection_reason` = ?
              WHERE `id` = ?",
            [$callerUserId, $callerUserName, $reason, $memberId]
        );
    } catch (Throwable $e) {
        error_log('[org-relationships] member_reject failed for member ' . $memberId . ': ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not reject membership']];
    }

    $relationshipId = (int) $row['relationship_id'];
    _org_relationship_recompute_status($relationshipId);

    _org_relationship_audit(
        'relationship_member_rejected', $relationshipId,
        "Org #{$rowOrgId} rejected/withdrew membership in relationship #{$relationshipId}" . ($reason ? ": {$reason}" : ''),
        ['org_id' => $rowOrgId, 'member_id' => $memberId, 'reason' => $reason]
    );

    return ['success' => true, 'errors' => []];
}

// ─────────────────────────────────────────────────────────────────────
// Activation lifecycle
// ─────────────────────────────────────────────────────────────────────

/**
 * Activates a `requires_activation` relationship on behalf of the
 * caller's own (approved-member) org -- gated by
 * _org_relationship_caller_is_approved_member(), never by either RBAC code
 * alone (plan.md: "a dispatcher can only activate a relationship their own
 * org is an approved member of, never one their org merely knows exists").
 * $requestedMinutes is clamped to org_relationships.max_activation_minutes
 * server-side (authoritative -- any client-side clamp is UX only).
 *
 * Relies on org_relationships_activations.uk_org_rel_activation_live (the
 * live_key generated column) as the real DB-level guarantee that at most
 * one LIVE activation exists per relationship -- a duplicate-key exception
 * here is translated into a normal error response, not allowed to bubble
 * as a fatal.
 *
 * @return array{success:bool, errors:string[], id:?int}
 */
function org_relationship_activate(int $relationshipId, bool $canActGlobal, int $callerUserId, string $callerUserName = '', ?string $reason = null, ?int $requestedMinutes = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rel = db_fetch_one(
            "SELECT `id`, `name`, `status`, `requires_activation`, `max_activation_minutes`
               FROM `{$prefix}org_relationships` WHERE `id` = ? LIMIT 1",
            [$relationshipId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load relationship'], 'id' => null];
    }
    if (!$rel) return ['success' => false, 'errors' => ['Relationship not found'], 'id' => null];
    if ((string) $rel['status'] !== 'active') {
        return ['success' => false, 'errors' => ['Relationship is not active yet (all named members must consent first)'], 'id' => null];
    }

    if (!_org_relationship_caller_is_approved_member($relationshipId, $canActGlobal, $callerUserId)) {
        return ['success' => false, 'errors' => ['Your organization is not an approved member of this relationship'], 'id' => null];
    }

    $ceiling = $rel['max_activation_minutes'] !== null ? (int) $rel['max_activation_minutes'] : null;
    $minutes = $requestedMinutes !== null ? (int) $requestedMinutes : $ceiling;
    if ($ceiling !== null && ($minutes === null || $minutes > $ceiling)) {
        $minutes = $ceiling; // server-side authoritative clamp
    }
    if ($minutes !== null && $minutes <= 0) {
        return ['success' => false, 'errors' => ['max_activation_minutes must be a positive integer when set'], 'id' => null];
    }

    $reason = $reason !== null ? trim($reason) : null;
    if ($reason === '') $reason = null;
    if ($reason !== null && strlen($reason) > 255) {
        return ['success' => false, 'errors' => ['Reason must be 255 characters or fewer'], 'id' => null];
    }

    try {
        db_query(
            "INSERT INTO `{$prefix}org_relationships_activations`
                (`relationship_id`, `activated_by`, `activated_by_name`, `activation_reason`, `max_activation_minutes`)
             VALUES (?, ?, ?, ?, ?)",
            [$relationshipId, $callerUserId, $callerUserName, $reason, $minutes]
        );
        $activationId = (int) db_insert_id();
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, '1062') !== false || stripos($msg, 'Duplicate entry') !== false) {
            return ['success' => false, 'errors' => ['This relationship already has a live activation'], 'id' => null];
        }
        error_log('[org-relationships] activate failed for relationship ' . $relationshipId . ': ' . $msg);
        return ['success' => false, 'errors' => ['Could not activate relationship'], 'id' => null];
    }

    _org_relationship_audit(
        'relationship_activated', $relationshipId,
        "Activated relationship '{$rel['name']}' (#{$relationshipId})" . ($reason ? ": {$reason}" : ''),
        ['activation_id' => $activationId, 'reason' => $reason, 'max_activation_minutes' => $minutes]
    );

    _org_relationship_notify_lifecycle('org_relationship:activated', $relationshipId, $callerUserId);

    return ['success' => true, 'errors' => [], 'id' => $activationId];
}

/**
 * Deactivates the CURRENTLY LIVE activation for a relationship. Used for
 * BOTH an explicit operator deactivation ($autoExpired=false, gated by
 * membership same as activate()) AND the cleanup job's own path
 * ($autoExpired=true, tools/org_relationship_cleanup_tick.php,
 * $callerUserId=0 matching this codebase's convention for "the system, not
 * a human, did this"). $autoExpired bypasses the membership gate --
 * closing an audit record for a window the read-time predicate has
 * ALREADY excluded from every request is not a privileged action, it is
 * housekeeping. See this file's org_relationship_activation_live_join_sql()
 * docblock and specs/phase-143-.../plan.md's "Companion cleanup job" for
 * why this function never being called changes nothing about access.
 *
 * @return array{success:bool, errors:string[]}
 */
function org_relationship_deactivate(int $relationshipId, bool $canActGlobal, int $callerUserId, string $callerUserName = '', ?string $reason = null, bool $autoExpired = false): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $activation = db_fetch_one(
            "SELECT `id` FROM `{$prefix}org_relationships_activations`
              WHERE `relationship_id` = ? AND `deactivated_at` IS NULL LIMIT 1",
            [$relationshipId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load activation']];
    }
    if (!$activation) {
        return ['success' => false, 'errors' => ['No live activation exists for this relationship']];
    }

    if (!$autoExpired && !_org_relationship_caller_is_approved_member($relationshipId, $canActGlobal, $callerUserId)) {
        return ['success' => false, 'errors' => ['Your organization is not an approved member of this relationship']];
    }

    $activationId = (int) $activation['id'];
    $deactivatedReason = $autoExpired
        ? 'auto-expired (cleanup sweep)'
        : (($reason !== null && trim($reason) !== '') ? trim($reason) : null);

    try {
        db_query(
            "UPDATE `{$prefix}org_relationships_activations`
                SET `deactivated_at` = NOW(), `deactivated_by` = ?, `deactivated_by_name` = ?, `deactivated_reason` = ?
              WHERE `id` = ?",
            [$autoExpired ? 0 : $callerUserId, $autoExpired ? '' : $callerUserName, $deactivatedReason, $activationId]
        );
    } catch (Throwable $e) {
        error_log('[org-relationships] deactivate failed for relationship ' . $relationshipId . ': ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not deactivate relationship']];
    }

    _org_relationship_audit(
        'relationship_deactivated', $relationshipId,
        $autoExpired
            ? "Relationship #{$relationshipId}'s activation auto-expired"
            : "Deactivated relationship #{$relationshipId}" . ($deactivatedReason ? ": {$deactivatedReason}" : ''),
        ['activation_id' => $activationId, 'auto_expired' => $autoExpired]
    );

    _org_relationship_notify_lifecycle('org_relationship:deactivated', $relationshipId, $autoExpired ? null : $callerUserId);

    return ['success' => true, 'errors' => []];
}

// ─────────────────────────────────────────────────────────────────────
// Read-time expiry enforcement — the single most important mechanism in
// this phase. See plan.md's "Read-time expiry enforcement" section.
// ─────────────────────────────────────────────────────────────────────

/**
 * Returns the literal ON-clause fragment for a LEFT JOIN against
 * org_relationships_activations, computed fresh against NOW() every time
 * it is used. NEVER cache this JOIN's result across calls or requests --
 * the whole point is that it is recomputed by the database on every
 * single evaluation, with nothing on the read side that could have gone
 * stale. Deliberately a SQL-fragment-returning helper, NOT a
 * boolean-returning one -- a cached boolean is exactly the mistake this
 * mechanism exists to avoid re-introducing (the PAR-scheduler / pending-
 * message-sweep lesson, CLAUDE.md 2026-07-29).
 */
function org_relationship_activation_live_join_sql(string $activationAlias = 'ra'): string
{
    return "{$activationAlias}.deactivated_at IS NULL
            AND ({$activationAlias}.max_activation_minutes IS NULL
                 OR {$activationAlias}.activated_at > DATE_SUB(NOW(), INTERVAL {$activationAlias}.max_activation_minutes MINUTE))";
}

/**
 * Relationship-analog of inc/org-sharing.php's own
 * org_share_context_for_ticket() -- resolves the caller's winning
 * RELATIONSHIP-derived grant for a ticket, using the live-join predicate
 * above, iff the caller's visibility into this ticket is genuinely
 * relationship-derived (not same-org, and not already covered by an
 * incident_shares row -- that precedence check is org-sharing.php's own
 * responsibility, this function only ever answers "what would the
 * relationship path grant here", unconditionally).
 *
 * @return array{relationship_id:int, shared_with_org_id:int, access_tier:string, redaction_tier:string}|null
 */
function org_relationship_context_for_ticket(int $ticketId, ?int $userId = null): ?array
{
    $visible = org_visible_ids($userId);
    if ($visible === null) return null; // Super Admin
    if (empty($visible))    return null; // unauthenticated / no orgs

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $visibleInt = array_map('intval', $visible);

    try {
        $orgId = db_fetch_value("SELECT `org_id` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1", [$ticketId]);
    } catch (Throwable $e) {
        return null;
    }
    if ($orgId === null || $orgId === '') return null;
    $ownerOrgId = (int) $orgId;
    if (in_array($ownerOrgId, $visibleInt, true)) return null; // same-org — not relationship-derived

    try {
        $ph = implode(',', array_fill(0, count($visibleInt), '?'));
        $liveJoin = org_relationship_activation_live_join_sql('ra');
        $row = db_fetch_one(
            "SELECT r.id AS relationship_id, r.access_tier, r.redaction_profile, mine.org_id AS mine_org_id
               FROM `{$prefix}org_relationships` r
               JOIN `{$prefix}org_relationships_members` mine
                 ON mine.relationship_id = r.id AND mine.status = 'approved' AND mine.org_id IN ($ph)
               JOIN `{$prefix}org_relationships_members` theirs
                 ON theirs.relationship_id = r.id AND theirs.status = 'approved' AND theirs.org_id = ?
               LEFT JOIN `{$prefix}org_relationships_activations` ra
                 ON ra.relationship_id = r.id AND {$liveJoin}
              WHERE r.status = 'active'
                AND (r.requires_activation = 0 OR ra.id IS NOT NULL)
              ORDER BY (r.access_tier = 'assist') DESC, r.id DESC
              LIMIT 1",
            array_merge($visibleInt, [$ownerOrgId])
        );
    } catch (Throwable $e) {
        return null; // org_relationships tables not present yet
    }
    if (!$row) return null;

    return [
        'relationship_id'    => (int) $row['relationship_id'],
        'shared_with_org_id' => (int) $row['mine_org_id'],
        'access_tier'        => (string) $row['access_tier'],
        'redaction_tier'     => (string) $row['redaction_profile'],
    ];
}

/**
 * SSE support -- active relationship-derived recipient orgs for a
 * TICKET'S OWNING org (relationships are a property of the owning org,
 * not the ticket). Resolves the ticket's own org_id first, then every
 * OTHER approved member org of any currently-live-or-always-on
 * relationship the owning org belongs to. Wrapped in try/catch: [] if the
 * tables don't exist or nothing applies -- the exact condition that keeps
 * sse_publish_for_incident() byte-identical to its pre-Phase-143 shape for
 * every ticket whose owning org has never used a relationship.
 */
function _org_relationship_orgs_for_ticket_owner(int $ticketId): array
{
    if ($ticketId <= 0) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $ownerOrgId = db_fetch_value("SELECT `org_id` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1", [$ticketId]);
    } catch (Throwable $e) {
        return [];
    }
    if ($ownerOrgId === null || $ownerOrgId === '') return [];
    $ownerOrgId = (int) $ownerOrgId;

    try {
        $liveJoin = org_relationship_activation_live_join_sql('ra');
        $rows = db_fetch_all(
            "SELECT DISTINCT theirs.org_id AS other_org_id
               FROM `{$prefix}org_relationships` r
               JOIN `{$prefix}org_relationships_members` mine
                 ON mine.relationship_id = r.id AND mine.status = 'approved' AND mine.org_id = ?
               JOIN `{$prefix}org_relationships_members` theirs
                 ON theirs.relationship_id = r.id AND theirs.status = 'approved' AND theirs.org_id != ?
               LEFT JOIN `{$prefix}org_relationships_activations` ra
                 ON ra.relationship_id = r.id AND {$liveJoin}
              WHERE r.status = 'active'
                AND (r.requires_activation = 0 OR ra.id IS NOT NULL)",
            [$ownerOrgId, $ownerOrgId]
        );
        $out = [];
        foreach ($rows as $r) {
            $oid = (int) ($r['other_org_id'] ?? 0);
            if ($oid > 0) $out[] = $oid;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
