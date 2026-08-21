<?php
/**
 * Phase 145 (2026-08-19) — Facility-account scoping helpers.
 *
 * GH#90 (openises/TicketsCAD issue #90): v3 had a `LEVEL_FACILITY` user
 * level that redirected a facility login to facility_board.php, a board
 * scoped to `WHERE (ticket.facility = ? OR ticket.rec_facility = ?) AND
 * status IN (OPEN, SCHEDULED)`. Critically, v3's level enforced ZERO real
 * access control — the redirect was the entire mechanism; the facility
 * user got the same screens as anyone else and could create incidents.
 * This file is the REAL confinement v3 never had.
 *
 * Design (mirrors inc/org-scope.php's shape deliberately — a facility
 * link on the user account, checked in addition to role permissions, the
 * same way org_id/active_org_id scopes an Org Admin):
 *
 *   - `user.facility_id` (an existing, previously-dead column — see
 *     GH#91) is REPURPOSED as the real link: > 0 means this account is a
 *     facility login, scoped to that one facility, full stop.
 *   - login.php sets `$_SESSION['facility_id']` from that column at
 *     login time (never trusts a client-supplied value).
 *   - A dedicated RBAC role, "Facility" (resolved by NAME everywhere -- never
 *     a hardcoded id, since roles.id is a plain AUTO_INCREMENT a custom
 *     role may already occupy -- see sql/rbac.sql), holds exactly two
 *     permissions: `screen.facility_portal` and `action.facility_self_report`.
 *     See sql/rbac.sql / sql/run_00_rbac.php / sql/run_phase145_facility_accounts.php.
 *   - Confinement is enforced at THREE independent layers, deliberately
 *     redundant (a facility session must never reach anything outside the
 *     portal even if one layer has a gap):
 *
 *       1. inc/rbac.php's _rbac_load_grants() — the single lowest-level
 *          choke point every permission check funnels through (rbac_can(),
 *          is_admin()'s direct is_super cache read, rbac_user_permissions()).
 *          When $_SESSION['facility_id'] is set, is_super is forced false
 *          and the effective permission set is intersected down to
 *          FACILITY_ALLOWED_PERMISSIONS below, regardless of what role
 *          grants actually exist for the account.
 *
 *       2. api/auth.php (facility_confine_api_or_deny(), called near the
 *          top, before the RBAC fail-closed check) — api/auth.php is
 *          require_once'd by essentially every api/*.php endpoint
 *          (184 of them), so a script-name allowlist here blocks any
 *          endpoint outright, whether or not that endpoint separately
 *          calls rbac_can(). This is the layer that protects endpoints
 *          with NO permission gate at all (e.g. api/facility-capacity.php's
 *          `?summary=1`, which has none) — which is exactly why the new
 *          facility self-service surface is a brand-new, narrow,
 *          dedicated endpoint (api/facility-portal.php) rather than a
 *          reuse of that existing dispatcher-facing one.
 *
 *       3. inc/force-pw-change.php's force_pw_change_redirect() — called
 *          by 62 of the app's 70 top-level pages right after the session
 *          check, so folding facility_confine_page_redirect() into the
 *          very end of that function reaches nearly every page with a
 *          single edit instead of 62 separate ones (deliberately chosen
 *          to minimize collision surface with other agents concurrently
 *          editing this tree — see CLAUDE.md's concurrent-session note).
 *
 * Ticket-visibility (which incidents a facility account may see) is a
 * SEPARATE, narrower question from confinement — see
 * facility_ticket_visibility_sql() / facility_can_see_ticket() below,
 * used exclusively by api/facility-portal.php.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────
// The permission allowlist. Kept as a single source of truth — inc/rbac.php
// reads this constant so a facility-confined session can never be granted
// anything else, no matter what role_permissions rows exist for it.
// ─────────────────────────────────────────────────────────────────────
if (!defined('FACILITY_ALLOWED_PERMISSIONS')) {
    define('FACILITY_ALLOWED_PERMISSIONS', [
        'screen.facility_portal',
        'action.facility_self_report',
    ]);
}

/**
 * Page-name allowlist for facility_confine_page_redirect() below.
 * facility-portal.php is the portal itself; profile.php is required so a
 * facility account can still change its own password / manage its own
 * 2FA (the CLAUDE.md standing rule: any account with login MUST have a
 * self-service password-change UI reachable from its own surface);
 * login.php is required so logout (login.php?logout=1) keeps working.
 */
if (!defined('FACILITY_ALLOWED_PAGES')) {
    define('FACILITY_ALLOWED_PAGES', [
        'facility-portal.php',
        'profile.php',
        'login.php',
    ]);
}

/**
 * Script-name allowlist for facility_confine_api_or_deny() below.
 * api/facility-portal.php is the ONE bespoke endpoint backing the portal
 * (incident list + self-service status/capacity updates, all scoped
 * server-side to the session's own facility_id — see that file). profile
 * and tfa endpoints mirror the page allowlist's self-service reasoning.
 */
if (!defined('FACILITY_ALLOWED_API_SCRIPTS')) {
    define('FACILITY_ALLOWED_API_SCRIPTS', [
        'facility-portal.php',
        'profile.php',
        'tfa.php',
    ]);
}

/**
 * The current session's linked facility id, or 0 if this is not a
 * facility-confined session. Never trust anything BUT the session value
 * here — it is populated exactly once, at login, from the user row.
 */
function facility_session_facility_id(): int
{
    return (int) ($_SESSION['facility_id'] ?? 0);
}

function facility_session_is_confined(): bool
{
    return facility_session_facility_id() > 0;
}

/**
 * Pure predicates (no I/O, no exit()) — the actual allow/deny logic,
 * factored out so tests can exercise it directly without triggering the
 * header()+exit()/json_error() side effects of the guard functions below.
 */
function facility_page_allowed(string $script): bool
{
    return in_array($script, FACILITY_ALLOWED_PAGES, true);
}

function facility_api_script_allowed(string $script): bool
{
    return in_array($script, FACILITY_ALLOWED_API_SCRIPTS, true);
}

/**
 * Page-entry guard. Call AFTER the session/user_id check, same contract
 * as force_pw_change_redirect() — which is exactly where this is wired
 * in (inc/force-pw-change.php). No-op for every non-facility session
 * (the overwhelming majority of requests), so this never adds a query or
 * behavior change for anyone else.
 */
function facility_confine_page_redirect(?string $script = null): void
{
    if (!facility_session_is_confined()) {
        return;
    }

    $script = $script ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (facility_page_allowed($script)) {
        return;
    }

    if (function_exists('audit_log')) {
        audit_log(
            'auth',
            'facility_scope_denied',
            'user',
            (int) ($_SESSION['user_id'] ?? 0),
            "Facility-scoped session redirected away from {$script}",
            ['facility_id' => facility_session_facility_id(), 'script' => $script],
            defined('AUDIT_LOW') ? AUDIT_LOW : 2
        );
    }

    header('Location: facility-portal.php');
    exit;
}

/**
 * API-entry guard. Call from api/auth.php, after session validation but
 * before the must_change_password/RBAC fail-closed checks. Every
 * api/*.php endpoint reaches this in one place (api/auth.php is
 * require_once'd by all of them), so this is the single choke point that
 * blocks a facility session from ANY endpoint not on the allowlist —
 * including endpoints with no RBAC gate of their own.
 */
function facility_confine_api_or_deny(): void
{
    if (!facility_session_is_confined()) {
        return;
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (facility_api_script_allowed($script)) {
        return;
    }

    if (function_exists('audit_log')) {
        audit_log(
            'auth',
            'facility_scope_denied',
            'user',
            (int) ($_SESSION['user_id'] ?? 0),
            "Facility-scoped session blocked from api/{$script}",
            ['facility_id' => facility_session_facility_id(), 'script' => $script],
            defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3
        );
    }

    json_error('Not authorized for facility accounts', 403);
}

/**
 * SQL fragment (+ bound params, in order) restricting a ticket query to
 * incidents at, or inbound to, the given facility — origin (`t.facility`),
 * receiving (`t.rec_facility`), OR per-unit destination (Phase 116's
 * `assigns.rec_facility_id`, which can differ from the ticket-level
 * rec_facility in a mass-casualty event where units go to different
 * hospitals). Soft-delete is NOT included here — callers add their own
 * deleted_at filter, matching every other list query in this codebase.
 *
 *   [$frag, $params] = facility_ticket_visibility_sql('t');
 *   $sql = "SELECT ... FROM ticket t WHERE 1=1 {$frag} ...";
 */
function facility_ticket_visibility_sql(string $ticketAlias = 't'): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $facilityId = facility_session_facility_id();
    $frag = " AND ({$ticketAlias}.`facility` = ? OR {$ticketAlias}.`rec_facility` = ?"
          . " OR EXISTS (SELECT 1 FROM `{$prefix}assigns` `fa` "
          . "WHERE `fa`.`ticket_id` = {$ticketAlias}.`id` AND `fa`.`rec_facility_id` = ?))";
    return [$frag, [$facilityId, $facilityId, $facilityId]];
}

/**
 * Single-ticket visibility check — the IDOR gate for a would-be
 * incident-detail-shaped lookup within the facility portal. 404-shaped
 * denial is the caller's job (Constitution rule #27 — don't leak
 * existence); this just answers true/false.
 */
function facility_can_see_ticket(int $ticketId, ?int $facilityId = null): bool
{
    $facilityId = $facilityId ?? facility_session_facility_id();
    if ($facilityId <= 0 || $ticketId <= 0) return false;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $hit = db_fetch_value(
            "SELECT 1 FROM `{$prefix}ticket` t
              WHERE t.`id` = ?
                AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')
                AND (t.`facility` = ? OR t.`rec_facility` = ?
                     OR EXISTS (SELECT 1 FROM `{$prefix}assigns` fa
                                 WHERE fa.`ticket_id` = t.`id` AND fa.`rec_facility_id` = ?))
              LIMIT 1",
            [$ticketId, $facilityId, $facilityId, $facilityId]
        );
        return (bool) $hit;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * GH#99 (2026-08-20) — which of a ticket's active unit assignments a
 * facility-confined viewer may see, and only the fields
 * api/facility-portal.php already exposes (identity + status timeline;
 * never crew roster/comments — see that file's own docblock on the
 * org-sharing view-tier redaction precedent it follows).
 *
 * A multi-unit incident can have units transporting to DIFFERENT
 * facilities — a mass-casualty call is the textbook case. Before this
 * fix, the units query was scoped to the TICKET only: every unit on the
 * call was returned to every facility that had ANY leg on that ticket,
 * including units destined somewhere else entirely — along with
 * `en_route_at`/`arrived_at` (assigns.u2fenr/u2farr, the FACILITY-leg
 * timestamps GH#64 shipped), which read as "this unit is coming to /
 * has arrived at YOU" regardless of where it was actually headed. On a
 * real mass-casualty incident that is exactly the kind of false signal
 * that holds a bed or stands up a trauma team for a patient who was
 * never coming (reported as GH#99).
 *
 * The two relationships a facility can have to a ticket (see
 * facility_ticket_visibility_sql() above for the three legs a ticket
 * itself can carry) need two different rules here:
 *
 *   - ORIGIN (`ticket.facility` === this facility — the incident is
 *     physically AT this facility, e.g. a group home reporting a
 *     resident emergency): every responding unit is relevant regardless
 *     of transport destination — they are all responding to ITS
 *     location. Filtering here would be a pure functionality
 *     regression (the facility watching its own incident would lose
 *     visibility on every unit the moment any ambulance starts
 *     transporting elsewhere) with no matching security benefit — this
 *     facility already had legitimate access to "who responded to my
 *     call," and that relationship doesn't change because of where a
 *     patient ends up.
 *
 *   - RECEIVING-ONLY (`ticket.rec_facility`, or ONLY a per-unit
 *     `assigns.rec_facility_id` leg, points at this facility — the
 *     reported case): a unit is relevant ONLY if it is actually coming
 *     here. Filtered to units whose EFFECTIVE destination —
 *     `COALESCE(NULLIF(assigns.rec_facility_id,0), NULLIF(ticket.rec_facility,0))`
 *     — resolves to this facility. This is the EXACT resolution
 *     `inc/bed_auto.php:228` already uses for "which facility is this
 *     unit actually going to," reused verbatim so both places agree —
 *     never re-derived.
 *
 * $ticketFacility / $ticketRecFacility are the CALLER's own
 * already-fetched `ticket.facility` / `ticket.rec_facility` for this
 * ticket (api/facility-portal.php's incidents query selects both
 * columns per ticket already) — passed in rather than re-joined, since
 * the caller has them in hand for the very same row.
 */
function facility_portal_visible_units(int $ticketId, int $facilityId, int $ticketFacility, int $ticketRecFacility): array
{
    if ($ticketId <= 0 || $facilityId <= 0) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $isOrigin = ($ticketFacility > 0 && $ticketFacility === $facilityId) ? 1 : 0;

    try {
        return db_fetch_all(
            "SELECT r.`name` AS responder_name, r.`handle`,
                    us.`status_val`, us.`bg_color`, us.`text_color`,
                    a.`u2fenr`, a.`u2farr`
             FROM `{$prefix}assigns` a
             LEFT JOIN `{$prefix}responder` r ON a.`responder_id` = r.`id`
             LEFT JOIN `{$prefix}un_status` us ON r.`un_status_id` = us.`id`
             WHERE a.`ticket_id` = ?
               AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')
               AND (? = 1 OR COALESCE(NULLIF(a.`rec_facility_id`, 0), NULLIF(?, 0)) = ?)
             ORDER BY a.`id`",
            [$ticketId, $isOrigin, $ticketRecFacility, $facilityId]
        );
    } catch (Throwable $e) {
        return [];
    }
}
