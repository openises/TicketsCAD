<?php
/**
 * Phase 141 (2026-08-17) — Cross-org ticket sharing / auto-routing core.
 *
 * Every piece of Phase 141-specific logic (matching/precedence, redaction,
 * audit-context resolution, rule validation) lives here. inc/org-scope.php
 * — the existing, already-proven org visibility engine — is extended with
 * exactly three additions (see that file's own docblock); this file is
 * everything else.
 *
 * Design: specs/phase-141-cross-org-ticket-sharing/{spec.md,plan.md,tasks.md}.
 * GitHub issue #70.
 *
 * Public API:
 *   org_sharing_resolve_shares_for_ticket(int $inTypeId, int $ownerOrgId): array
 *   org_sharing_apply_routing_on_create(int $ticketId, int $inTypeId, int $ownerOrgId, int $userId): int
 *   org_share_context_for_ticket(int $ticketId, ?int $userId = null): ?array
 *   org_share_redact_ticket_fields(array $ticketRow, string $tier): array
 *   org_share_redact_assignment_fields(array $assignmentRow, string $tier): array   [endpoint-integration addition]
 *   org_sharing_apply_list_redaction(array $rows, ?int $userId = null): array
 *   org_routing_rule_validate(array $input, int $callerUserId): array
 *   org_routing_rule_create(array $input, int $callerUserId, string $callerUserName = ''): array   [endpoint-integration addition]
 *   org_routing_rule_update(int $ruleId, array $input, int $callerUserId): array                    [endpoint-integration addition]
 *   org_routing_rule_deactivate(int $ruleId, int $callerUserId): array                               [endpoint-integration addition]
 *   org_routing_resolve_caller_org_id(int $userId): int                                              [admin-UI addition]
 *   org_routing_can_author_org(bool $canAuthorGlobal, int $owningOrgId): bool                        [admin-UI addition]
 *   org_routing_resolve_create_owning_org(bool $canAuthorGlobal, int $callerOrgId, ?int $requestedOrgId): array   [admin-UI addition]
 *   org_routing_row_out(array $row): array                                                            [admin-UI addition]
 *   org_routing_schema_ready(): bool                                                                   [admin-UI addition]
 *   org_sharing_create_manual_share(int $ticketId, int $sharedWithOrgId, string $accessTier, string $reason, int $callerUserId, string $callerUserName = ''): array   [Phase 142]
 *   org_sharing_revoke_share(int $shareId, ?string $reason, int $callerUserId, string $callerUserName = ''): array                                                    [Phase 142]
 *   org_sharing_list_active_shares(int $ticketId): array                                                                                                              [Phase 142]
 *
 * The [admin-UI addition] functions are pure/DB-read-only helpers extracted
 * out of api/org-routing.php so they can be unit-tested directly, matching
 * this codebase's established convention (ics_form_types_resolve_caller_org_id()
 * / ics_form_types_resolve_create_org() in inc/ics-form-types.php,
 * pb_resolve_admin_write_org() / pb_resolve_caller_org_id() in
 * inc/public-board.php) -- api/*.php files require auth.php, which can exit
 * a CLI test before a locally-defined function would ever be reachable, so
 * this decision logic is never placed only in the endpoint file.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org-scope.php';

// ─────────────────────────────────────────────────────────────────────
// org_sharing_resolve_shares_for_ticket / precedence resolution
// ─────────────────────────────────────────────────────────────────────

/**
 * Pure precedence/dedup resolver. Takes candidate org_type_routing rows
 * (each with at least id, shared_with_org_id, match_scope, access_tier)
 * ALREADY ORDERED id DESC (most-recently-created first) and collapses
 * them to one winning row per shared_with_org_id:
 *   - a 'type'-scoped row always beats a 'group'-scoped row for the same
 *     target org (more specific wins), regardless of recency;
 *   - between two rows of the SAME specificity for the same target org,
 *     the row that appears FIRST in the (already id-DESC-ordered) input
 *     wins, i.e. the most-recently-created one.
 *
 * Separated from the DB-querying function below so the precedence logic
 * itself is testable with synthetic rows and no database at all — per
 * this codebase's UNIQUE KEY on org_type_routing, two ACTIVE rows of the
 * same specificity can never both match one ticket in practice (the key
 * is (owning_org_id, shared_with_org_id, match_key), and match_key
 * encodes the exact type id / group name a rule matches), so the
 * same-specificity tie-break below is a defensive contract, not a path
 * this schema can currently exercise via normal inserts — tested
 * directly against this pure function with synthetic ties for exactly
 * that reason.
 *
 * @param array<int, array{id:int,shared_with_org_id:int,match_scope:string,access_tier:string}> $rows
 * @return array<int, array{shared_with_org_id:int,routing_rule_id:int,access_tier:string}>
 */
function _org_sharing_apply_precedence(array $rows): array
{
    $bestByOrg = [];
    foreach ($rows as $row) {
        $target = (int) $row['shared_with_org_id'];
        $scope  = (string) $row['match_scope'];
        if (!isset($bestByOrg[$target])) {
            $bestByOrg[$target] = $row;
            continue;
        }
        $existingScope = (string) $bestByOrg[$target]['match_scope'];
        if ($existingScope === 'group' && $scope === 'type') {
            // type beats group even though the group-scoped row was seen
            // first (i.e. even if it happens to be more recent) — more
            // specific always wins.
            $bestByOrg[$target] = $row;
        }
        // else: keep the existing entry. Either it's already the more
        // specific ('type') winner, or both rows are the same specificity
        // and — because $rows arrives id-DESC-ordered — the existing
        // entry is the more-recently-created one.
    }

    $result = [];
    foreach ($bestByOrg as $target => $row) {
        $result[] = [
            'shared_with_org_id' => $target,
            'routing_rule_id'    => (int) $row['id'],
            'access_tier'        => (string) $row['access_tier'],
        ];
    }
    return $result;
}

/**
 * Resolves which orgs (and at what tier, under which rule) a ticket of
 * incident type $inTypeId, owned by $ownerOrgId, should be auto-shared
 * with — per every ACTIVE org_type_routing rule whose owning_org_id
 * matches exactly (no descendant tree-walk in Phase 1) and whose match
 * target (a specific in_type, or the type's group) matches this ticket.
 *
 * Pure function — no writes, no audit calls, fully unit-testable without
 * a ticket existing yet. Returns one entry per distinct
 * shared_with_org_id, precedence-resolved (type beats group; ties within
 * the same specificity go to the most-recently-created rule).
 *
 * @return array<int, array{shared_with_org_id:int,routing_rule_id:int,access_tier:string}>
 */
function org_sharing_resolve_shares_for_ticket(int $inTypeId, int $ownerOrgId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `shared_with_org_id`, `match_scope`, `access_tier`
               FROM `{$prefix}org_type_routing`
              WHERE `active` = 1 AND `owning_org_id` = ?
                AND (
                     (`match_scope` = 'type'  AND `match_in_type_id` = ?)
                  OR (`match_scope` = 'group' AND `match_group` = (
                        SELECT `group` FROM `{$prefix}in_types` WHERE `id` = ? LIMIT 1
                      ))
                )
              ORDER BY `id` DESC",
            [$ownerOrgId, $inTypeId, $inTypeId]
        );
    } catch (Throwable $e) {
        // org_type_routing / in_types not present yet (pre-Phase-141
        // install) — no routing is possible.
        return [];
    }

    return _org_sharing_apply_precedence($rows);
}

// ─────────────────────────────────────────────────────────────────────
// org_sharing_apply_routing_on_create
// ─────────────────────────────────────────────────────────────────────

/**
 * Called from incident_create_internal() (inc/incident-write.php)
 * immediately after $ticket_id is confirmed non-zero, before the
 * allocates-loop. Resolves routing rules for this ticket's type/owner,
 * inserts one incident_shares row per result, and fires one
 * audit_log('incident','share_created',...) call per share created.
 *
 * Wrapped so a failure here is non-fatal to incident creation — same
 * tolerance pattern already used for the assign_responders loop a few
 * lines below this call site in inc/incident-write.php.
 *
 * @return int number of incident_shares rows created
 */
function org_sharing_apply_routing_on_create(int $ticketId, int $inTypeId, int $ownerOrgId, int $userId): int
{
    if ($ticketId <= 0 || $inTypeId <= 0 || $ownerOrgId <= 0) return 0;

    $shares = org_sharing_resolve_shares_for_ticket($inTypeId, $ownerOrgId);
    if (empty($shares)) return 0;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }

    $created = 0;
    foreach ($shares as $share) {
        $sharedWithOrgId = (int) $share['shared_with_org_id'];
        $routingRuleId   = (int) $share['routing_rule_id'];
        $tier            = (string) $share['access_tier'];
        try {
            db_query(
                "INSERT INTO `{$prefix}incident_shares`
                    (`ticket_id`, `shared_with_org_id`, `owning_org_id`, `routing_rule_id`, `access_tier`)
                 VALUES (?, ?, ?, ?, ?)",
                [$ticketId, $sharedWithOrgId, $ownerOrgId, $routingRuleId, $tier]
            );
            $created++;
            if (function_exists('audit_log')) {
                audit_log(
                    'incident', 'share_created', 'ticket', $ticketId,
                    "Incident #{$ticketId} auto-shared with org #{$sharedWithOrgId} ({$tier} tier, rule #{$routingRuleId})",
                    [
                        'shared_with_org_id' => $sharedWithOrgId,
                        'routing_rule_id'    => $routingRuleId,
                        'access_tier'        => $tier,
                    ],
                    defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3
                );
            }
            // Phase 142 (2026-08-17) -- live push for the AUTO-ROUTED path
            // too, per spec.md's explicit requirement to close the SSE gap
            // "for both sharing paths" from one shared helper rather than
            // two divergent implementations. Non-fatal to ticket creation --
            // _org_sharing_notify_share_change() already wraps its own body
            // in try/catch and never throws.
            if (function_exists('_org_sharing_notify_share_change')) {
                _org_sharing_notify_share_change(
                    'incident:shared', $ticketId, $sharedWithOrgId,
                    ['routing_rule_id' => $routingRuleId, 'access_tier' => $tier],
                    $userId
                );
            }
        } catch (Throwable $e) {
            error_log(
                '[org-sharing] apply_routing_on_create failed for ticket '
                . $ticketId . ' -> org ' . $sharedWithOrgId . ': ' . $e->getMessage()
            );
        }
    }
    return $created;
}

// ─────────────────────────────────────────────────────────────────────
// org_share_context_for_ticket
// ─────────────────────────────────────────────────────────────────────

/**
 * Returns the caller's winning grant context (shared_with_org_id,
 * access_tier, redaction_tier, routing_rule_id, relationship_id) if — and
 * only if — the caller's visibility into this ticket came from a share or
 * a standing relationship rather than same-org access. Returns null for
 * Super Admin, same-org callers, and callers with no applicable grant
 * (including a pre-Phase-141 database).
 *
 * Phase 143 (2026-08-17) widening: consults an incident_shares row FIRST
 * (unconditional precedence, per plan.md — an incident_shares grant, when
 * present, ALWAYS governs the returned tier/redaction) and falls back to
 * org_relationship_context_for_ticket() only when incident_shares produced
 * nothing. `redaction_tier` is the field every REDACTION call site must
 * read (org_share_redact_ticket_fields()/org_share_redact_assignment_fields())
 * — it equals `access_tier` for incident_shares-sourced context (unchanged
 * behavior) but can genuinely differ for relationship-sourced context (see
 * plan.md's "Two independent axes"). `routing_rule_id` is non-null only
 * for a rule-sourced incident_shares row; `relationship_id` is non-null
 * only for relationship-sourced context — the two are mutually exclusive.
 *
 * Single choke point incident-detail.php calls once, after
 * org_can_see_ticket() has already passed, to decide (a) whether to log
 * a view_shared audit entry and (b) which tier's redaction to apply.
 */
function org_share_context_for_ticket(int $ticketId, ?int $userId = null): ?array
{
    $visible = org_visible_ids($userId);
    if ($visible === null) return null; // Super Admin
    if (empty($visible))    return null; // unauthenticated / no orgs

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $visibleInt = array_map('intval', $visible);

    try {
        $orgId = db_fetch_value(
            "SELECT `org_id` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1",
            [$ticketId]
        );
    } catch (Throwable $e) {
        return null;
    }
    if ($orgId !== null && $orgId !== '' && in_array((int) $orgId, $visibleInt, true)) {
        return null; // same-org access — not share-derived
    }

    try {
        $placeholders = implode(',', array_fill(0, count($visibleInt), '?'));
        $row = db_fetch_one(
            "SELECT `shared_with_org_id`, `access_tier`, `routing_rule_id`
               FROM `{$prefix}incident_shares`
              WHERE `ticket_id` = ? AND `shared_with_org_id` IN ($placeholders)
                AND `revoked_at` IS NULL
              ORDER BY (`access_tier` = 'assist') DESC, `id` DESC
              LIMIT 1",
            array_merge([$ticketId], $visibleInt)
        );
    } catch (Throwable $e) {
        $row = null; // incident_shares not present — fall through to the relationship path below
    }
    if ($row) {
        $tier = (string) $row['access_tier'];
        return [
            'shared_with_org_id' => (int) $row['shared_with_org_id'],
            'access_tier'        => $tier,
            // Phase 143 (2026-08-17) -- redaction_tier === access_tier for
            // incident_shares-sourced context (unchanged behavior). See
            // this function's own updated docblock and plan.md's "Two
            // independent axes" for why relationship-sourced context below
            // does NOT collapse the two the same way.
            'redaction_tier'     => $tier,
            'routing_rule_id'    => $row['routing_rule_id'] !== null ? (int) $row['routing_rule_id'] : null,
            'relationship_id'    => null,
        ];
    }

    // Phase 143 (2026-08-17) — precedence rule per plan.md: incident_shares
    // wins UNCONDITIONALLY when it produced a row; the relationship path is
    // consulted ONLY when it produced nothing. Lazy-loaded (this file does
    // not unconditionally require org-relationships.php) so an install
    // without the Phase 143 tables/file behaves exactly as before.
    if (!function_exists('org_relationship_context_for_ticket') && is_file(__DIR__ . '/org-relationships.php')) {
        require_once __DIR__ . '/org-relationships.php';
    }
    if (function_exists('org_relationship_context_for_ticket')) {
        $relCtx = org_relationship_context_for_ticket($ticketId, $userId);
        if ($relCtx !== null) {
            return [
                'shared_with_org_id' => $relCtx['shared_with_org_id'],
                'access_tier'        => $relCtx['access_tier'],
                'redaction_tier'     => $relCtx['redaction_tier'],
                'routing_rule_id'    => null,
                'relationship_id'    => $relCtx['relationship_id'],
            ];
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────
// Redaction
// ─────────────────────────────────────────────────────────────────────

/**
 * The `view`-tier field allowlist, built directly from plan.md's table
 * (itself built from spec.md's own wording: "dispatch-relevant fields
 * only: location, incident type, severity, status, assigned-unit status
 * -- never patient/medical detail, caller PII, or free-text
 * activity-log narrative beyond what dispatch needs").
 *
 * Keyed by array key name, not raw `ticket` column name alone -- callers
 * (incident-list.php, incident-detail.php, etc.) each SELECT their own
 * shape and may alias resolved-display columns (e.g. `it`.`type` AS
 * `type_name`, per api/incident-list.php's existing convention). This
 * allowlist intersects against whatever keys a given row actually
 * carries, so it is safe to apply uniformly across endpoints with
 * different projections -- a key absent from the row is simply absent
 * from the redacted result, never an error. Endpoint integration (Task
 * 5/6/7) is responsible for confirming each endpoint's own key names are
 * covered here; extend this list rather than fork the function.
 *
 * Explicitly EXCLUDED (never returned at `view` tier): contact, phone,
 * nine_one_one (caller PII); description, comments, affected, to_address
 * (free-text narrative); any `action`-table / notes payload; any
 * `patient`-table payload. Security-label redaction is a separate,
 * already-shipped system (Phase 18a) and composes independently -- this
 * allowlist narrows the field SET; the security-label system separately
 * narrows VALUES within whatever fields are shown here. Neither widens
 * the other.
 */
function org_share_view_tier_field_allowlist(): array
{
    return [
        // Identity
        'id', 'incident_number',
        // Incident type (raw id + commonly-used resolved-display aliases --
        // each of the 11 endpoints spells "incident type" a little
        // differently; extend THIS list when a new endpoint's own key name
        // needs to ride through, per this file's own docblock instruction --
        // never fork the function).
        'in_types_id', 'in_types_id_id', 'type_name', 'type_group', 'type_color', 'type_icon', 'type',
        'incident_type', 'type_id', 'in_type_name', 'radius',
        // Owning org (needed for the "shared from [Org]" indicator itself)
        'org_id', 'org', 'org_name',
        // Location
        'street', 'city', 'state', 'address_about', 'lat', 'lng',
        // Status (+ derived presentational renderings of the SAME already-
        // allowed severity/status values -- no new information)
        'severity', 'severity_color', 'status', 'status_text', 'booked_date',
        // Operational timestamps (not narrative) -- 'created' is
        // incidents.php/callboard.php's own alias for 'date'
        'date', 'created', 'problemstart', 'problemend', 'updated',
        // The required "what's happening" field -- distinct from the
        // optional free-text narrative fields excluded below
        'scope',
        // Security-label system's own ALREADY-MASKED display renderings +
        // metadata (Phase 18a) -- composes independently, per plan.md:
        // showing these can never leak more than the security-label system
        // itself already permits, since they're derived FROM the same
        // scope/street/city fields this allowlist already allows and are
        // masked/unmasked entirely by that separate, unmodified system.
        'scope_display', 'address_display', 'security',
        // Facilities -- "where units/patients are going" (Phase 116
        // precedent) -- IDs/names only, per plan.md's explicit qualifier.
        // Deliberately NOT included: facility_lat/lng, facility_street/city,
        // rec_facility_lat/lng/street/city.
        'facility', 'facility_name', 'rec_facility', 'rec_facility_name',
        // Assigned-unit status -- "which units, and their current status"
        // (unit_names is a comma-joined list of UNIT names, not personnel --
        // distinct from incident-detail.php's per-assignment 'crew', which
        // names individual people and is deliberately excluded elsewhere)
        'assignments', 'active_responders', 'units_assigned', 'unit_names',
    ];
}

/**
 * Phase 141 -- per-assignment (per-responding-unit) redaction, used ONLY by
 * incident-detail.php's `assignments` array. Distinct from
 * org_share_view_tier_field_allowlist() above because an assignment row
 * carries fields that allowlist was never meant to cover, and some of them
 * are exactly the "never leak a roster" boundary this phase's own docblock
 * treats as non-negotiable: `crew`/`crew_count` name the individual
 * PERSONNEL assigned to a unit (Phase 116b), and `distance_km`/
 * `responder_updated` are derived from the responding org's OWN
 * `responder.lat/lng/updated` -- i.e. that org's roster/location data, not
 * this ticket's data. None of that survives to `view` tier. `assist` tier
 * returns the row unchanged, same as the ticket-level function.
 */
function org_share_view_tier_assignment_allowlist(): array
{
    return [
        'id', 'responder_id', 'responder_name', 'responder_handle',
        'status_id', 'responder_un_status_id', 'status_name', 'bg_color', 'text_color',
        'dispatched', 'responding', 'on_scene', 'clear', 'cleared',
        'rec_facility_id', 'rec_facility_name',
    ];
}

function org_share_redact_assignment_fields(array $assignmentRow, string $tier): array
{
    if ($tier === 'assist') return $assignmentRow;
    $allow = array_flip(org_share_view_tier_assignment_allowlist());
    return array_intersect_key($assignmentRow, $allow);
}

/**
 * Applies the view-tier allowlist to a single ticket row. `assist` tier
 * returns $ticketRow unchanged -- same field set a same-org dispatcher
 * gets (still subject to the independent security-label system,
 * unchanged). `view` tier returns a NEW array containing only the
 * allowlisted keys that were actually present in $ticketRow.
 */
function org_share_redact_ticket_fields(array $ticketRow, string $tier): array
{
    if ($tier === 'assist') return $ticketRow;
    $allow = array_flip(org_share_view_tier_field_allowlist());
    return array_intersect_key($ticketRow, $allow);
}

/**
 * The single choke point every list-shaped endpoint (incident-list.php,
 * incident-search.php, incidents.php, callboard.php, external API's GET
 * list) calls once on its already-fetched, already-org-filtered result
 * set, immediately before json_response(). Each $row must carry at
 * least `id` and `org_id`.
 *
 * Same-org rows (row's org_id in the caller's own org_visible_ids()) are
 * returned completely unchanged -- no shared_from_* keys added. A row
 * NOT in the caller's own org set that IS in the (already org-filtered)
 * result set MUST be share-derived; such rows are redacted per their
 * winning share's tier and annotated with shared_from_org_id /
 * shared_from_org_name so the client needs no second round-trip.
 *
 * Batched: one query for the matching incident_shares rows, one query
 * for Phase 143 relationship-derived grants (merged into the same tier
 * map, for tickets incident_shares didn't already cover), one query for
 * organization names -- never N+1.
 */
function org_sharing_apply_list_redaction(array $rows, ?int $userId = null): array
{
    if (empty($rows)) return $rows;

    $visible = org_visible_ids($userId);
    if ($visible === null) return $rows; // Super Admin — never share-derived, nothing to annotate
    if (empty($visible))    return $rows;
    $visibleInt = array_map('intval', $visible);

    $ids = [];
    foreach ($rows as $r) {
        if (isset($r['id'])) $ids[] = (int) $r['id'];
    }
    if (empty($ids)) return $rows;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $idPh  = implode(',', array_fill(0, count($ids), '?'));
    $orgPh = implode(',', array_fill(0, count($visibleInt), '?'));

    // tierByTicket maps ticket_id => the REDACTION tier to apply. For an
    // incident_shares-sourced row this is access_tier (unchanged); for a
    // Phase 143 relationship-sourced row (merged in below) it is
    // redaction_profile — see plan.md's "Two independent axes".
    $tierByTicket = [];
    try {
        $shareRows = db_fetch_all(
            "SELECT `ticket_id`, `access_tier`
               FROM `{$prefix}incident_shares`
              WHERE `ticket_id` IN ($idPh) AND `shared_with_org_id` IN ($orgPh)
                AND `revoked_at` IS NULL",
            array_merge($ids, $visibleInt)
        );
        foreach ($shareRows as $sr) {
            $tid  = (int) $sr['ticket_id'];
            $tier = (string) $sr['access_tier'];
            // Prefer 'assist' if the caller somehow has multiple applicable
            // shares for the same ticket (possible if the caller belongs to
            // more than one org and more than one of those orgs was granted
            // a share).
            if (!isset($tierByTicket[$tid]) || $tier === 'assist') {
                $tierByTicket[$tid] = $tier;
            }
        }
    } catch (Throwable $e) {
        // incident_shares not present — nothing from this source, fall
        // through to the relationship source below.
    }

    // Phase 143 (2026-08-17) — second batched query, for any ticket whose
    // owning org is NOT already covered by the incident_shares pass above
    // (precedence: incident_shares wins unconditionally when it produced a
    // tier for a given ticket, per plan.md). Lazy-loaded so an install
    // without the Phase 143 tables/file is unaffected.
    if (!function_exists('org_relationship_activation_live_join_sql') && is_file(__DIR__ . '/org-relationships.php')) {
        require_once __DIR__ . '/org-relationships.php';
    }
    if (function_exists('org_relationship_activation_live_join_sql')) {
        $ownerOrgTickets = []; // owner_org_id => [ticket_id, ...] not already covered
        foreach ($rows as $row) {
            $tid = isset($row['id']) ? (int) $row['id'] : 0;
            if ($tid === 0 || isset($tierByTicket[$tid])) continue; // incident_shares already covers this ticket
            $rowOrgId = isset($row['org_id']) ? (int) $row['org_id'] : null;
            if ($rowOrgId === null || in_array($rowOrgId, $visibleInt, true)) continue; // same-org
            $ownerOrgTickets[$rowOrgId][] = $tid;
        }
        if (!empty($ownerOrgTickets)) {
            try {
                $liveJoin = org_relationship_activation_live_join_sql('ra');
                $ownerPh = implode(',', array_fill(0, count($ownerOrgTickets), '?'));
                $relRows = db_fetch_all(
                    "SELECT DISTINCT theirs.org_id AS owner_org_id, r.redaction_profile
                       FROM `{$prefix}org_relationships` r
                       JOIN `{$prefix}org_relationships_members` mine
                         ON mine.relationship_id = r.id AND mine.status = 'approved' AND mine.org_id IN ($orgPh)
                       JOIN `{$prefix}org_relationships_members` theirs
                         ON theirs.relationship_id = r.id AND theirs.status = 'approved' AND theirs.org_id IN ($ownerPh)
                       LEFT JOIN `{$prefix}org_relationships_activations` ra
                         ON ra.relationship_id = r.id AND {$liveJoin}
                      WHERE r.status = 'active'
                        AND (r.requires_activation = 0 OR ra.id IS NOT NULL)",
                    array_merge($visibleInt, array_keys($ownerOrgTickets))
                );
                $redactionByOwnerOrg = [];
                foreach ($relRows as $rr) {
                    $ooid  = (int) $rr['owner_org_id'];
                    $rtier = (string) $rr['redaction_profile'];
                    if (!isset($redactionByOwnerOrg[$ooid]) || $rtier === 'assist') {
                        $redactionByOwnerOrg[$ooid] = $rtier;
                    }
                }
                foreach ($ownerOrgTickets as $ooid => $ticketIds) {
                    if (!isset($redactionByOwnerOrg[$ooid])) continue;
                    foreach ($ticketIds as $tid) {
                        $tierByTicket[$tid] = $redactionByOwnerOrg[$ooid];
                    }
                }
            } catch (Throwable $e) {
                // org_relationships tables not present — nothing to merge.
            }
        }
    }

    if (empty($tierByTicket)) return $rows;

    // Batched org-name lookup for every distinct OWNING org id among the
    // rows about to be annotated (the "shared FROM" org, i.e. the
    // ticket's own org_id — not the caller's shared_with_org_id).
    $owningOrgIdsNeeded = [];
    foreach ($rows as $row) {
        $tid = isset($row['id']) ? (int) $row['id'] : 0;
        if (!isset($tierByTicket[$tid])) continue;
        $rowOrgId = isset($row['org_id']) ? (int) $row['org_id'] : null;
        if ($rowOrgId !== null && in_array($rowOrgId, $visibleInt, true)) continue; // same-org
        if ($rowOrgId !== null) $owningOrgIdsNeeded[$rowOrgId] = true;
    }
    $orgNames = [];
    if (!empty($owningOrgIdsNeeded)) {
        try {
            $orgIds = array_keys($owningOrgIdsNeeded);
            $ph = implode(',', array_fill(0, count($orgIds), '?'));
            $orgRows = db_fetch_all(
                "SELECT `id`, `name` FROM `{$prefix}organizations` WHERE `id` IN ($ph)",
                $orgIds
            );
            foreach ($orgRows as $or) $orgNames[(int) $or['id']] = (string) $or['name'];
        } catch (Throwable $e) {
            // organizations table missing — names just won't be attached
        }
    }

    foreach ($rows as &$row) {
        $tid = isset($row['id']) ? (int) $row['id'] : 0;
        $rowOrgId = isset($row['org_id']) ? (int) $row['org_id'] : null;
        if ($rowOrgId !== null && in_array($rowOrgId, $visibleInt, true)) {
            continue; // same-org — unchanged, no shared_from_* keys
        }
        if (!isset($tierByTicket[$tid])) continue; // defensive — not reachable in correct operation
        $row = org_share_redact_ticket_fields($row, $tierByTicket[$tid]);
        $row['shared_from_org_id']   = $rowOrgId;
        $row['shared_from_org_name'] = $rowOrgId !== null ? ($orgNames[$rowOrgId] ?? null) : null;
    }
    unset($row);

    return $rows;
}

// ─────────────────────────────────────────────────────────────────────
// org_routing_rule_validate
// ─────────────────────────────────────────────────────────────────────

/**
 * Validates a proposed org_type_routing rule (create or the tier-only
 * update path — callers enforce immutability of the identity fields
 * separately, per plan.md). Returns ['valid' => bool, 'errors' =>
 * string[]].
 *
 * Includes the design-synthesis guardrail: owning_org_id must be in the
 * caller's own org_visible_ids() — always true for Super Admin (NULL =
 * no restriction), exercised for real only if/when
 * action.manage_org_routing_org is ever hand-granted to a non-Super-Admin
 * caller (Phase 1 default keeps both codes Super-Admin-only).
 */
function org_routing_rule_validate(array $input, int $callerUserId): array
{
    $errors = [];
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $owningOrgId     = (int) ($input['owning_org_id'] ?? 0);
    $sharedWithOrgId = (int) ($input['shared_with_org_id'] ?? 0);
    $matchScope      = (string) ($input['match_scope'] ?? '');
    $matchGroup      = (isset($input['match_group']) && $input['match_group'] !== '')
                        ? (string) $input['match_group'] : null;
    $matchInTypeId   = (isset($input['match_in_type_id']) && $input['match_in_type_id'] !== '')
                        ? (int) $input['match_in_type_id'] : null;
    $accessTier      = (string) ($input['access_tier'] ?? '');

    if ($owningOrgId <= 0) $errors[] = 'Owning organization is required';
    if ($sharedWithOrgId <= 0) $errors[] = 'Target organization is required';
    if ($owningOrgId > 0 && $owningOrgId === $sharedWithOrgId) {
        $errors[] = 'A rule cannot route from an organization to itself';
    }

    if ($owningOrgId > 0) {
        try {
            $exists = db_fetch_value(
                "SELECT 1 FROM `{$prefix}organizations` WHERE `id` = ? LIMIT 1", [$owningOrgId]
            );
            if (!$exists) $errors[] = 'Owning organization does not exist';
        } catch (Throwable $e) {
            $errors[] = 'Could not verify owning organization';
        }
    }
    if ($sharedWithOrgId > 0 && $sharedWithOrgId !== $owningOrgId) {
        try {
            $exists = db_fetch_value(
                "SELECT 1 FROM `{$prefix}organizations` WHERE `id` = ? LIMIT 1", [$sharedWithOrgId]
            );
            if (!$exists) $errors[] = 'Target organization does not exist';
        } catch (Throwable $e) {
            $errors[] = 'Could not verify target organization';
        }
    }

    if ($owningOrgId > 0) {
        $visible = org_visible_ids($callerUserId);
        if ($visible !== null && !in_array($owningOrgId, array_map('intval', $visible), true)) {
            $errors[] = "You do not have access to organization #{$owningOrgId} as an owning org";
        }
    }

    if (!in_array($matchScope, ['group', 'type'], true)) {
        $errors[] = "match_scope must be 'group' or 'type'";
    } elseif ($matchScope === 'type') {
        if ($matchInTypeId === null || $matchInTypeId <= 0) {
            $errors[] = 'match_in_type_id is required when match_scope is type';
        }
        if ($matchGroup !== null) {
            $errors[] = 'match_group must not be set when match_scope is type';
        }
        if ($matchInTypeId !== null && $matchInTypeId > 0) {
            try {
                $exists = db_fetch_value(
                    "SELECT 1 FROM `{$prefix}in_types` WHERE `id` = ? LIMIT 1", [$matchInTypeId]
                );
                if (!$exists) $errors[] = 'match_in_type_id does not reference an existing incident type';
            } catch (Throwable $e) {
                $errors[] = 'Could not verify match_in_type_id';
            }
        }
    } else { // 'group'
        if ($matchGroup === null || $matchGroup === '') {
            $errors[] = 'match_group is required when match_scope is group';
        }
        if ($matchInTypeId !== null) {
            $errors[] = 'match_in_type_id must not be set when match_scope is group';
        }
        if ($matchGroup !== null && $matchGroup !== '') {
            try {
                $exists = db_fetch_value(
                    "SELECT 1 FROM `{$prefix}in_types` WHERE `group` = ? LIMIT 1", [$matchGroup]
                );
                // A typo'd group name that matches nothing is a validation
                // error, not a silently-inert rule — this project's own
                // tile_mode post-mortem is the exact shape of bug a config
                // value with no matching effect produces.
                if (!$exists) $errors[] = "match_group '{$matchGroup}' does not match any existing incident type group";
            } catch (Throwable $e) {
                $errors[] = 'Could not verify match_group';
            }
        }
    }

    if (!in_array($accessTier, ['view', 'assist'], true)) {
        $errors[] = "access_tier must be 'view' or 'assist'";
    }

    return ['valid' => empty($errors), 'errors' => $errors];
}

// ─────────────────────────────────────────────────────────────────────
// Routing-rule CRUD -- the write + audit-log layer api/org-routing.php
// (plan.md section 8, a separate future task) calls into. Housed here
// rather than in the not-yet-built endpoint file, matching this
// codebase's established split (business logic in inc/*.php, thin
// endpoint in api/*.php -- e.g. inc/incident-write.php + api/
// incident-update.php) so the audit-logging requirement for
// routing-rule changes has a real writer to fire from NOW, and so
// tests can exercise it without hand-seeding org_type_routing rows.
// ─────────────────────────────────────────────────────────────────────

/**
 * Creates a new org_type_routing rule. Runs org_routing_rule_validate()
 * first; on success, inserts the row and fires the 'config'-category
 * audit_log() call from plan.md's Audit Logging section verbatim.
 *
 * @return array{success:bool, errors:string[], id:?int}
 */
function org_routing_rule_create(array $input, int $callerUserId, string $callerUserName = ''): array
{
    $validation = org_routing_rule_validate($input, $callerUserId);
    if (!$validation['valid']) {
        return ['success' => false, 'errors' => $validation['errors'], 'id' => null];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $owningOrgId     = (int) $input['owning_org_id'];
    $sharedWithOrgId = (int) $input['shared_with_org_id'];
    $matchScope      = (string) $input['match_scope'];
    $matchGroup      = ($matchScope === 'group')
        ? (string) ($input['match_group'] ?? '') : null;
    $matchInTypeId   = ($matchScope === 'type')
        ? (int) ($input['match_in_type_id'] ?? 0) : null;
    $accessTier      = (string) $input['access_tier'];

    try {
        db_query(
            "INSERT INTO `{$prefix}org_type_routing`
                (`owning_org_id`, `shared_with_org_id`, `match_scope`, `match_group`,
                 `match_in_type_id`, `access_tier`, `active`, `created_by`, `created_by_name`)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
            [$owningOrgId, $sharedWithOrgId, $matchScope, $matchGroup, $matchInTypeId,
             $accessTier, $callerUserId, $callerUserName]
        );
        $ruleId = (int) db_insert_id();
    } catch (Throwable $e) {
        error_log('[org-sharing] org_routing_rule_create failed: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not create routing rule'], 'id' => null];
    }

    $matchDescription = $matchScope === 'type'
        ? "specific incident type #{$matchInTypeId}"
        : "incident type group '{$matchGroup}'";
    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (function_exists('audit_log')) {
        audit_log(
            'config', 'create', 'org_type_routing', $ruleId,
            "Created cross-org routing rule: org #{$owningOrgId} -> org #{$sharedWithOrgId} ({$matchDescription}, {$accessTier} tier)",
            ['owning_org_id' => $owningOrgId, 'shared_with_org_id' => $sharedWithOrgId,
             'match_scope' => $matchScope, 'match_group' => $matchGroup,
             'match_in_type_id' => $matchInTypeId, 'access_tier' => $accessTier],
            defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3
        );
    }

    return ['success' => true, 'errors' => [], 'id' => $ruleId];
}

/**
 * Tier-only edit. Per plan.md's immutability rule, owning_org_id,
 * shared_with_org_id, match_scope, match_group, and match_in_type_id may
 * never change after creation -- rejects the update if the caller's
 * $input attempts to change any of them (comparing against the rule's
 * OWN current row, not trusting the caller's copy of those fields).
 *
 * @return array{success:bool, errors:string[]}
 */
function org_routing_rule_update(int $ruleId, array $input, int $callerUserId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $existing = db_fetch_one(
            "SELECT `owning_org_id`, `shared_with_org_id`, `match_scope`, `match_group`,
                    `match_in_type_id`, `access_tier`
               FROM `{$prefix}org_type_routing` WHERE `id` = ? LIMIT 1",
            [$ruleId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load routing rule']];
    }
    if (!$existing) {
        return ['success' => false, 'errors' => ['Routing rule not found']];
    }

    $errors = [];
    foreach (['owning_org_id' => 'int', 'shared_with_org_id' => 'int',
              'match_scope' => 'string', 'match_in_type_id' => 'int'] as $field => $cast) {
        if (!array_key_exists($field, $input)) continue;
        $incoming = $input[$field];
        $current  = $existing[$field];
        $incomingCmp = $cast === 'int'
            ? ($incoming === null || $incoming === '' ? null : (int) $incoming)
            : (string) $incoming;
        $currentCmp = $cast === 'int'
            ? ($current === null ? null : (int) $current)
            : (string) $current;
        if ($incomingCmp !== $currentCmp) {
            $errors[] = "{$field} is immutable and cannot be changed on an existing rule";
        }
    }
    if (array_key_exists('match_group', $input)) {
        $incomingGroup = ($input['match_group'] === '' ? null : $input['match_group']);
        if ($incomingGroup !== $existing['match_group']) {
            $errors[] = 'match_group is immutable and cannot be changed on an existing rule';
        }
    }
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    $newTier = isset($input['access_tier']) ? (string) $input['access_tier'] : (string) $existing['access_tier'];
    if (!in_array($newTier, ['view', 'assist'], true)) {
        return ['success' => false, 'errors' => ["access_tier must be 'view' or 'assist'"]];
    }

    try {
        db_query(
            "UPDATE `{$prefix}org_type_routing` SET `access_tier` = ? WHERE `id` = ?",
            [$newTier, $ruleId]
        );
    } catch (Throwable $e) {
        error_log('[org-sharing] org_routing_rule_update failed: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not update routing rule']];
    }

    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (function_exists('audit_log')) {
        audit_log(
            'config', 'update', 'org_type_routing', $ruleId,
            "Updated cross-org routing rule #{$ruleId}: access_tier -> {$newTier}",
            ['owning_org_id' => (int) $existing['owning_org_id'],
             'shared_with_org_id' => (int) $existing['shared_with_org_id'],
             'access_tier' => $newTier],
            defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3
        );
    }

    return ['success' => true, 'errors' => []];
}

/**
 * Archives a rule (active=0, deactivated_at/deactivated_by). Per plan.md,
 * does NOT retroactively revoke incident_shares rows already created by
 * this rule -- only future ticket creation stops matching it.
 *
 * @return array{success:bool, errors:string[]}
 */
function org_routing_rule_deactivate(int $ruleId, int $callerUserId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $existing = db_fetch_one(
            "SELECT `owning_org_id`, `shared_with_org_id` FROM `{$prefix}org_type_routing` WHERE `id` = ? LIMIT 1",
            [$ruleId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load routing rule']];
    }
    if (!$existing) {
        return ['success' => false, 'errors' => ['Routing rule not found']];
    }

    try {
        db_query(
            "UPDATE `{$prefix}org_type_routing`
                SET `active` = 0, `deactivated_at` = NOW(), `deactivated_by` = ?
              WHERE `id` = ?",
            [$callerUserId, $ruleId]
        );
    } catch (Throwable $e) {
        error_log('[org-sharing] org_routing_rule_deactivate failed: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not deactivate routing rule']];
    }

    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (function_exists('audit_log')) {
        audit_log(
            'config', 'deactivate', 'org_type_routing', $ruleId,
            "Deactivated cross-org routing rule #{$ruleId}",
            ['owning_org_id' => (int) $existing['owning_org_id'],
             'shared_with_org_id' => (int) $existing['shared_with_org_id']],
            defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3
        );
    }

    return ['success' => true, 'errors' => []];
}

// ─────────────────────────────────────────────────────────────────────
// Admin UI support -- api/org-routing.php's own resolver/formatter logic,
// housed here (not in the api/ file) so it is unit-testable without a
// live session. Same split rationale as the routing-rule CRUD block above.
// ─────────────────────────────────────────────────────────────────────

/**
 * Which single org id an org-scoped-only caller's action.manage_org_routing_org
 * grant is scoped to. Mirrors ics_form_types_resolve_caller_org_id()
 * (inc/ics-form-types.php, Phase 140) exactly: a grant with more than one
 * distinct org-scoped row, or zero, resolves to 0 (no usable org) -- every
 * caller treats that as "cannot author," never "author install-wide."
 */
function org_routing_resolve_caller_org_id(int $userId): int
{
    if ($userId <= 0) return 0;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT ur.scope_id
               FROM `{$prefix}user_roles` ur
               JOIN `{$prefix}role_permissions` rp ON rp.role_id = ur.role_id
               JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
              WHERE ur.user_id = ?
                AND ur.scope_kind = 'org'
                AND ur.scope_id IS NOT NULL
                AND p.code = 'action.manage_org_routing_org'
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            [$userId]
        );
    } catch (Throwable $e) {
        error_log('[org-sharing] org_routing_resolve_caller_org_id failed: ' . $e->getMessage());
        return 0;
    }
    if (count($rows) === 1) return (int) $rows[0]['scope_id'];
    return 0;
}

/**
 * Does the caller hold authoring rights over THIS SPECIFIC owning org (or
 * global)? Pure function over its inputs plus one rbac_can() call -- no DB
 * read of its own beyond what rbac_can() does.
 */
function org_routing_can_author_org(bool $canAuthorGlobal, int $owningOrgId): bool
{
    if ($canAuthorGlobal) return true;
    if ($owningOrgId <= 0) return false;
    if (!function_exists('rbac_can')) return false;
    return rbac_can('action.manage_org_routing_org', ['org_id' => $owningOrgId]);
}

/**
 * Resolves which owning_org_id a CREATE should target, mirroring
 * ics_form_types_resolve_create_org() (Phase 140) / pb_resolve_admin_write_org()
 * (Phase 138) exactly: a global author must name a real org (required --
 * unlike ICS form types, an install-wide routing rule has no meaning, since
 * a rule always routes FROM one specific owning org); an org-scoped-only
 * author is force-pinned to their own resolved org and refused if they
 * attempt to name a different one. Pure function -- no DB access.
 *
 * @return array{ok:bool, org_id:?int, error:?string, status:int}
 */
function org_routing_resolve_create_owning_org(bool $canAuthorGlobal, int $callerOrgId, ?int $requestedOrgId): array
{
    if ($canAuthorGlobal) {
        $target = ($requestedOrgId !== null && $requestedOrgId > 0) ? $requestedOrgId : 0;
        if ($target <= 0) {
            return ['ok' => false, 'org_id' => null, 'error' => 'Owning organization is required', 'status' => 400];
        }
        return ['ok' => true, 'org_id' => $target, 'error' => null, 'status' => 200];
    }
    if ($callerOrgId <= 0) {
        return ['ok' => false, 'org_id' => null, 'error' => 'No organization scoped to this account for cross-org routing authoring', 'status' => 403];
    }
    if ($requestedOrgId !== null && $requestedOrgId > 0 && $requestedOrgId !== $callerOrgId) {
        return ['ok' => false, 'org_id' => null, 'error' => 'Forbidden: cannot create a routing rule for another organization', 'status' => 403];
    }
    return ['ok' => true, 'org_id' => $callerOrgId, 'error' => null, 'status' => 200];
}

/** Display-shaping for one org_type_routing row, as returned by GET ?list=1. */
function org_routing_row_out(array $row): array
{
    $matchScope = (string) $row['match_scope'];
    if ($matchScope === 'type') {
        $typeName = $row['match_type_name'] ?? null;
        $desc = ($typeName !== null && $typeName !== '')
            ? $typeName . ' (specific type)'
            : 'Incident type #' . (int) $row['match_in_type_id'] . ' (specific type)';
    } else {
        $desc = "'" . $row['match_group'] . "' incidents (group)";
    }
    return [
        'id'                   => (int) $row['id'],
        'owning_org_id'        => (int) $row['owning_org_id'],
        'owning_org_name'      => $row['owning_org_name'] ?? ('Org #' . (int) $row['owning_org_id']),
        'shared_with_org_id'   => (int) $row['shared_with_org_id'],
        'shared_with_org_name' => $row['shared_with_org_name'] ?? ('Org #' . (int) $row['shared_with_org_id']),
        'match_scope'          => $matchScope,
        'match_group'          => $row['match_group'],
        'match_in_type_id'     => $row['match_in_type_id'] !== null ? (int) $row['match_in_type_id'] : null,
        'match_description'    => $desc,
        'access_tier'          => $row['access_tier'],
        'active'               => (int) $row['active'] === 1,
        'created_by_name'      => $row['created_by_name'],
        'created_at'           => $row['created_at'],
        'updated_at'           => $row['updated_at'],
        'deactivated_at'       => $row['deactivated_at'],
    ];
}

/** Whether org_type_routing exists yet on this install (pre-migration guard). */
function org_routing_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $n = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'org_type_routing']
        );
        $ready = $n > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

// ─────────────────────────────────────────────────────────────────────
// Phase 142 (2026-08-17) — Manual cross-org ticket sharing (GH#70 Phase 2).
// Design: specs/phase-142-cross-org-manual-sharing/{spec.md,plan.md,tasks.md}.
//
// org_sharing_create_manual_share() / org_sharing_revoke_share() are the
// two write functions api/incident-share.php (a separate, not-yet-built
// task) will call into. Housed here now, ahead of that endpoint, so the
// anti-chaining security boundary has a real writer to test against
// immediately -- per plan.md's explicit instruction that the anti-chaining
// regression test not wait for the rest of the feature to exist.
//
// THE ANTI-CHAINING LINE (plan.md's "one hard security line"): every
// action funnels through org_ticket_is_owned_by_caller()
// (inc/org-scope.php:498) and refuses (empty-errors-false) if it returns
// false. That function was built in Phase 141 specifically so that NO
// share, at ANY tier -- including 'assist', which already grants full
// same-org-equivalent WRITE access via org_can_mutate_ticket() -- ever
// satisfies it. A caller whose own visibility into a ticket is itself
// share-derived can therefore never create or revoke a further share on
// that ticket, no matter what RBAC codes they hold. This is checked FIRST
// in both functions below, before any other validation, as defense in
// depth -- safe to call even if a future caller (endpoint, script) forgets
// its own copy of the same check.
// ─────────────────────────────────────────────────────────────────────

/**
 * Creates (or revives) a manual cross-org share on a ticket the caller's
 * own org actually owns. Per plan.md's Schema section, does NOT blindly
 * INSERT against uk_incident_share (ticket_id, shared_with_org_id):
 *   - no existing row for this (ticket, target org) pair -> fresh INSERT
 *   - an ACTIVE row already exists (revoked_at IS NULL) -> rejected, never
 *     silently overwrite an existing grant's tier or attribution
 *   - a REVOKED row exists -> revived: UPDATE clears every revoked_* field,
 *     sets the new tier/reason/attribution, and explicitly sets
 *     routing_rule_id = NULL even if the row's original creation was
 *     rule-driven -- this specific act of re-granting is a human decision
 *     and must be attributed as one.
 *
 * @return array{success:bool, errors:string[], id:?int}
 */
function org_sharing_create_manual_share(int $ticketId, int $sharedWithOrgId, string $accessTier, string $reason, int $callerUserId, string $callerUserName = ''): array
{
    // The anti-chaining gate -- checked first, before any other validation.
    // See this section's docblock: no share, at any tier, ever satisfies
    // org_ticket_is_owned_by_caller().
    if (!org_ticket_is_owned_by_caller($ticketId, $callerUserId)) {
        return ['success' => false, 'errors' => ['You do not have permission to share this ticket'], 'id' => null];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $owningOrgId = db_fetch_value(
            "SELECT `org_id` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1",
            [$ticketId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load ticket'], 'id' => null];
    }
    $owningOrgId = ($owningOrgId !== null && $owningOrgId !== '') ? (int) $owningOrgId : 0;
    if ($owningOrgId <= 0) {
        return ['success' => false, 'errors' => ['Ticket has no owning organization to share from'], 'id' => null];
    }

    $errors = [];

    if ($sharedWithOrgId <= 0) {
        $errors[] = 'Target organization is required';
    } elseif ($sharedWithOrgId === $owningOrgId) {
        $errors[] = 'A ticket cannot be shared with its own owning organization';
    } else {
        try {
            $targetOrg = db_fetch_one(
                "SELECT `active` FROM `{$prefix}organizations` WHERE `id` = ? LIMIT 1",
                [$sharedWithOrgId]
            );
        } catch (Throwable $e) {
            $targetOrg = null;
        }
        if (!$targetOrg) {
            $errors[] = 'Target organization does not exist';
        } elseif ((int) ($targetOrg['active'] ?? 0) !== 1) {
            $errors[] = 'Target organization is not active';
        }
    }

    if (!in_array($accessTier, ['view', 'assist'], true)) {
        $errors[] = "access_tier must be 'view' or 'assist'";
    }

    $reason = trim($reason);
    if ($reason === '') {
        $errors[] = 'A reason is required';
    } elseif (strlen($reason) > 255) {
        $errors[] = 'Reason must be 255 characters or fewer';
    }

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors, 'id' => null];
    }

    try {
        $existing = db_fetch_one(
            "SELECT `id`, `revoked_at`, `access_tier` FROM `{$prefix}incident_shares`
              WHERE `ticket_id` = ? AND `shared_with_org_id` = ? LIMIT 1",
            [$ticketId, $sharedWithOrgId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not check for an existing share'], 'id' => null];
    }

    if ($existing && $existing['revoked_at'] === null) {
        $targetName = 'org #' . $sharedWithOrgId;
        try {
            $nameRow = db_fetch_one("SELECT `name` FROM `{$prefix}organizations` WHERE `id` = ? LIMIT 1", [$sharedWithOrgId]);
            if ($nameRow && !empty($nameRow['name'])) $targetName = (string) $nameRow['name'];
        } catch (Throwable $e) {
            // fall back to the "org #N" label above
        }
        return [
            'success' => false,
            'errors'  => ["This ticket is already shared with {$targetName} at {$existing['access_tier']} tier"],
            'id'      => null,
        ];
    }

    try {
        if ($existing) {
            // Revoked row exists for this (ticket, target org) pair -- revive
            // it rather than colliding with uk_incident_share on a fresh
            // INSERT (plan.md's Phase-129-lesson callout: a lapsed grant
            // occupies the natural key; UPDATE, don't INSERT).
            db_query(
                "UPDATE `{$prefix}incident_shares`
                    SET `access_tier` = ?, `share_reason` = ?, `created_by` = ?, `created_by_name` = ?,
                        `created_at` = NOW(), `routing_rule_id` = NULL,
                        `revoked_at` = NULL, `revoked_reason` = NULL, `revoked_by` = NULL, `revoked_by_name` = ''
                  WHERE `id` = ?",
                [$accessTier, $reason, $callerUserId, $callerUserName, (int) $existing['id']]
            );
            $shareId = (int) $existing['id'];
        } else {
            db_query(
                "INSERT INTO `{$prefix}incident_shares`
                    (`ticket_id`, `shared_with_org_id`, `owning_org_id`, `routing_rule_id`, `access_tier`,
                     `created_by`, `created_by_name`, `share_reason`)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?)",
                [$ticketId, $sharedWithOrgId, $owningOrgId, $accessTier, $callerUserId, $callerUserName, $reason]
            );
            $shareId = (int) db_insert_id();
        }
    } catch (Throwable $e) {
        error_log('[org-sharing] org_sharing_create_manual_share failed for ticket ' . $ticketId . ' -> org ' . $sharedWithOrgId . ': ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not create share'], 'id' => null];
    }

    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (function_exists('audit_log')) {
        // Reuses Phase 141's exact category/activity ('incident'/'share_created')
        // rather than forking a new activity name -- the two origins (rule vs.
        // manual) are told apart by the details payload (routing_rule_id null
        // vs. set), per plan.md's Audit Logging section and spec.md user story 4.
        audit_log(
            'incident', 'share_created', 'ticket', $ticketId,
            "Incident #{$ticketId} manually shared with org #{$sharedWithOrgId} ({$accessTier} tier) by {$callerUserName}: {$reason}",
            [
                'shared_with_org_id' => $sharedWithOrgId,
                'access_tier'        => $accessTier,
                'created_by'         => $callerUserId,
                'share_reason'       => $reason,
                'routing_rule_id'    => null,
            ],
            defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3
        );
    }

    _org_sharing_notify_share_change(
        'incident:shared', $ticketId, $sharedWithOrgId,
        ['access_tier' => $accessTier, 'routing_rule_id' => null, 'share_reason' => $reason],
        $callerUserId
    );

    return ['success' => true, 'errors' => [], 'id' => $shareId];
}

/**
 * Revokes an active share, identified by its OWN primary key -- never a
 * caller-supplied ticket_id. Per plan.md's IDOR guard: the row is looked
 * up by `id` FIRST, `ticket_id` is derived FROM that row, and only THEN is
 * org_ticket_is_owned_by_caller() checked against that derived ticket_id.
 * A caller cannot pass a share_id belonging to a ticket they don't own and
 * expect the gate to check a different, client-supplied ticket instead --
 * there is no client-supplied ticket_id parameter at all.
 *
 * `revoked_by` is a real column (this phase's migration adds it -- see
 * sql/run_phase142_cross_org_manual_sharing.php's docblock correcting
 * spec.md's assumption that it already existed from Phase 141).
 *
 * @return array{success:bool, errors:string[]}
 */
function org_sharing_revoke_share(int $shareId, ?string $reason, int $callerUserId, string $callerUserName = ''): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $row = db_fetch_one(
            "SELECT `id`, `ticket_id`, `shared_with_org_id`, `access_tier`, `routing_rule_id`, `revoked_at`
               FROM `{$prefix}incident_shares` WHERE `id` = ? LIMIT 1",
            [$shareId]
        );
    } catch (Throwable $e) {
        return ['success' => false, 'errors' => ['Could not load share']];
    }
    if (!$row) {
        return ['success' => false, 'errors' => ['Share not found']];
    }

    $ticketId = (int) $row['ticket_id'];

    // The anti-chaining / IDOR gate -- derived ticket_id, never a
    // caller-supplied one. See this section's top-of-block docblock.
    if (!org_ticket_is_owned_by_caller($ticketId, $callerUserId)) {
        return ['success' => false, 'errors' => ['You do not have permission to manage sharing on this ticket']];
    }

    if ($row['revoked_at'] !== null) {
        return ['success' => false, 'errors' => ['This share has already been revoked']];
    }

    $reason = $reason !== null ? trim($reason) : null;
    if ($reason === '') $reason = null;
    if ($reason !== null && strlen($reason) > 255) {
        return ['success' => false, 'errors' => ['Reason must be 255 characters or fewer']];
    }

    try {
        db_query(
            "UPDATE `{$prefix}incident_shares`
                SET `revoked_at` = NOW(), `revoked_by` = ?, `revoked_by_name` = ?, `revoked_reason` = ?
              WHERE `id` = ?",
            [$callerUserId, $callerUserName, $reason, $shareId]
        );
    } catch (Throwable $e) {
        error_log('[org-sharing] org_sharing_revoke_share failed for share ' . $shareId . ': ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Could not revoke share']];
    }

    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (function_exists('audit_log')) {
        $sharedWithOrgId    = (int) $row['shared_with_org_id'];
        $priorTier          = (string) $row['access_tier'];
        $priorRoutingRuleId = $row['routing_rule_id'] !== null ? (int) $row['routing_rule_id'] : null;
        // Genuinely new activity -- Phase 141 shipped revoked_at/revoked_reason
        // but nothing ever wrote them, so there is no existing activity name
        // to reuse (per plan.md's Audit Logging section).
        audit_log(
            'incident', 'share_revoked', 'ticket', $ticketId,
            "Incident #{$ticketId} un-shared from org #{$sharedWithOrgId} by {$callerUserName}" . ($reason ? ": {$reason}" : ''),
            [
                'shared_with_org_id' => $sharedWithOrgId,
                'access_tier'        => $priorTier,
                'revoked_by'         => $callerUserId,
                'revoked_reason'     => $reason,
                'was_rule_sourced'   => $priorRoutingRuleId !== null,
            ],
            defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3
        );

        _org_sharing_notify_share_change(
            'incident:unshared', $ticketId, $sharedWithOrgId,
            ['access_tier' => $priorTier, 'revoked_reason' => $reason],
            $callerUserId
        );
    }

    return ['success' => true, 'errors' => []];
}

// ─────────────────────────────────────────────────────────────────────
// SSE — org_sharing_list_active_shares() / _org_sharing_notify_share_change()
// Design: plan.md's "SSE -- closing Phase 141's named limitation for both
// sharing paths" + "api/incident-share.php -- the new endpoint" sections.
// ─────────────────────────────────────────────────────────────────────

/**
 * Pure read -- every active (revoked_at IS NULL) incident_shares row for a
 * ticket, with target-org names resolved (one batched organizations
 * lookup, never N+1) and a computed source field ('rule' if
 * routing_rule_id is set, else 'manual'). No permission check inside this
 * one -- matches org_routing_row_out()'s own pattern of being a pure
 * formatter; the caller (api/incident-share.php's GET handler) does the
 * RBAC+ownership gate before calling it.
 *
 * @return array<int, array{id:int, shared_with_org_id:int, shared_with_org_name:string,
 *   access_tier:string, source:string, routing_rule_id:?int, created_by_name:string,
 *   created_at:?string, share_reason:?string}>
 */
function org_sharing_list_active_shares(int $ticketId): array
{
    if ($ticketId <= 0) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $rows = db_fetch_all(
            "SELECT `id`, `shared_with_org_id`, `access_tier`, `routing_rule_id`,
                    `created_by_name`, `created_at`, `share_reason`
               FROM `{$prefix}incident_shares`
              WHERE `ticket_id` = ? AND `revoked_at` IS NULL
              ORDER BY `created_at` DESC, `id` DESC",
            [$ticketId]
        );
    } catch (Throwable $e) {
        return []; // incident_shares not present — no shares possible
    }
    if (empty($rows)) return [];

    $orgIds = [];
    foreach ($rows as $r) {
        $oid = (int) $r['shared_with_org_id'];
        if ($oid > 0) $orgIds[$oid] = true;
    }
    $orgNames = [];
    if (!empty($orgIds)) {
        try {
            $ids = array_keys($orgIds);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $orgRows = db_fetch_all("SELECT `id`, `name` FROM `{$prefix}organizations` WHERE `id` IN ($ph)", $ids);
            foreach ($orgRows as $or) $orgNames[(int) $or['id']] = (string) $or['name'];
        } catch (Throwable $e) {
            // organizations table missing — names fall back to "Org #N" below
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $oid = (int) $r['shared_with_org_id'];
        $routingRuleId = $r['routing_rule_id'] !== null ? (int) $r['routing_rule_id'] : null;
        $out[] = [
            'id'                    => (int) $r['id'],
            'shared_with_org_id'    => $oid,
            'shared_with_org_name'  => $orgNames[$oid] ?? ('Org #' . $oid),
            'access_tier'           => (string) $r['access_tier'],
            'source'                => $routingRuleId !== null ? 'rule' : 'manual',
            'routing_rule_id'       => $routingRuleId,
            'created_by_name'       => (string) ($r['created_by_name'] ?? ''),
            'created_at'            => $r['created_at'],
            'share_reason'          => $r['share_reason'],
        ];
    }
    return $out;
}

/**
 * Phase 142 -- narrow, targeted SSE notification for a share grant/revoke.
 * Deliberately NOT sse_publish_for_incident()'s broad "everyone currently
 * entitled" resolution: (a) the org gaining/losing access must hear about
 * it even though, for revoke, they are by definition no longer in the
 * "currently active" set _sse_share_orgs_for_ticket() would compute; (b)
 * no OTHER already-shared org needs to know about a grant/revoke that
 * doesn't involve them -- that would be an information leak about a
 * ticket's sharing relationships to a third party, the same reasoning
 * that makes GET api/incident-share.php owning-org-only.
 *
 * Wrapped end-to-end in try/catch and never throws -- every call site in
 * this file treats a notification failure as non-fatal to the underlying
 * share create/revoke, which has already committed by the time this runs.
 */
function _org_sharing_notify_share_change(string $eventType, int $ticketId, int $targetOrgId, array $extraPayload, ?int $userId): void
{
    if (!function_exists('sse_publish')) {
        if (is_file(__DIR__ . '/sse.php')) require_once __DIR__ . '/sse.php';
    }
    if (!function_exists('sse_publish')) return;

    $payload = array_merge(['ticket_id' => $ticketId, 'shared_with_org_id' => $targetOrgId], $extraPayload);

    try {
        // The owning org's own day-to-day audience -- same mechanism every
        // OTHER ticket event already uses.
        if (function_exists('_sse_groups_for_resource')) {
            $groups = _sse_groups_for_resource($ticketId, 1);
            empty($groups)
                ? sse_publish($eventType, $payload, $userId, 'entitled')
                : sse_publish($eventType, $payload, $userId, 'group', $groups);
        }
        // The ONE org gaining/losing access -- never any other current
        // share recipient.
        sse_publish($eventType, $payload, $userId, 'org', [$targetOrgId]);
    } catch (Throwable $e) {
        error_log('[org-sharing] share-change SSE notify failed: ' . $e->getMessage());
    }
}
