<?php
/**
 * NewUI v4.0 — Public incident board redaction library (Phase 138, 2026-08-13).
 *
 * Spec: specs/phase-138-public-incident-board/{spec.md,plan.md,tasks.md}
 *
 * This is the ONE place the public-board redaction model lives. Both
 * api/public-board.php (built in a later stage) and the patched
 * api/feed.php call into it, so there is exactly one rounding table and
 * one presence-only-stub rule, not two copies that can drift apart.
 *
 * Same defensive-PHP style as inc/security-labels.php — no classes,
 * function_exists() guards against redeclaration. The functions in THIS
 * file (pb_round_coords, pb_iso8601, pb_build_public_record) are pure
 * transforms with no DB access — cheaply unit-testable in isolation
 * (plan.md §3, §11). A later stage adds pb_eligible_incidents() to this
 * same file, which DOES hit the database (plan.md §2 / tasks.md C1) — not
 * present yet, this file currently covers Section B only.
 *
 * Public API:
 *
 *   pb_round_coords(?float $lat, ?float $lng, string $level): array
 *     Pure coordinate-rounding transform per Ron's measured table
 *     (plan.md §3 rule 4). Returns ['lat' => ?float, 'lng' => ?float].
 *     A null input, or an unrecognized $level, fails closed to
 *     ['lat' => null, 'lng' => null] — never leaks a coordinate on a
 *     level string this function doesn't recognize.
 *
 *   pb_iso8601(?string $datetime): ?string
 *     Format a DB datetime string as ISO-8601 with a 'Z' suffix. Treats
 *     null, empty, and the legacy MySQL zero-datetime ('0000-00-00 ...')
 *     as "no timestamp" and returns null rather than a bogus 1970 date.
 *
 *   pb_build_public_record(array $ticketRow, array $secLabel, string $precisionCeiling, bool $applyTypeVisibility = true): array
 *     Build the public-safe record for one ALREADY-ELIGIBLE incident.
 *     Never receives an ineligible incident — eligibility is entirely
 *     the caller's job (plan.md §2). See the function's own docblock
 *     below for the full redaction-axis breakdown and the expected
 *     $ticketRow / $secLabel shapes.
 */

if (!function_exists('pb_round_coords')) {

/**
 * Pure coordinate-rounding transform (plan.md §3 rule 4 / rule 5).
 *
 *   | Level    | Rounding        | lat/lng in output? |
 *   |----------|-----------------|---------------------|
 *   | exact    | none            | yes, unrounded       |
 *   | block    | 3 decimal places| yes                  |
 *   | city     | 2 decimal places| yes                  |
 *   | hidden   | n/a             | no (both null)       |
 *
 * A null $lat or $lng (incident has no location on file) always yields
 * ['lat' => null, 'lng' => null] regardless of $level — there is nothing
 * to round. An unrecognized $level string fails CLOSED to the 'hidden'
 * behavior (both null) rather than guessing a precision.
 */
function pb_round_coords(?float $lat, ?float $lng, string $level): array
{
    if ($lat === null || $lng === null) {
        return ['lat' => null, 'lng' => null];
    }

    switch ($level) {
        case 'exact':
            return ['lat' => $lat, 'lng' => $lng];
        case 'block':
            return ['lat' => round($lat, 3), 'lng' => round($lng, 3)];
        case 'city':
            return ['lat' => round($lat, 2), 'lng' => round($lng, 2)];
        case 'hidden':
            return ['lat' => null, 'lng' => null];
        default:
            // Unrecognized level — fail closed, never leak a coordinate.
            return ['lat' => null, 'lng' => null];
    }
}

/**
 * ISO-8601 with a 'Z' offset, from a DB datetime string. Single source of
 * truth for both api/public-board.php and the api/feed.php JSON-branch
 * timestamp fix (plan.md §5 step 6 / §8 change 2) — one function, two
 * callers, so the timestamp bug can't reappear in one without the other.
 *
 * Returns null (not a bogus epoch/1970 date) for null, empty, or the
 * legacy MySQL zero-datetime string, and for any value strtotime() can't
 * parse.
 */
function pb_iso8601(?string $datetime): ?string
{
    if ($datetime === null) return null;
    $datetime = trim($datetime);
    if ($datetime === '' || strpos($datetime, '0000-00-00') === 0) {
        return null;
    }
    $ts = strtotime($datetime);
    if ($ts === false) return null;
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/**
 * Strip a leading house number from a street string, leaving the street
 * name only ("123 Main St" -> "Main St"). Used at the 'block' precision
 * level (plan.md §3 rule 4 — "street name only, no house number"). If no
 * leading number is present, the string is returned unchanged (trimmed).
 */
function _pb_street_name_only(string $street): string
{
    $stripped = preg_replace('/^\s*[0-9]+[A-Za-z0-9\-\/]*\s+/', '', $street);
    return trim($stripped ?? $street);
}

/**
 * Severity code -> display text. Mirrors api/feed.php's own
 * $severity_labels mapping (0=Low, 1=Medium, 2=High) so the public board
 * and the authenticated feed never disagree on what a severity number
 * means.
 */
function _pb_severity_text(int $severity): string
{
    $labels = [0 => 'Low', 1 => 'Medium', 2 => 'High'];
    return $labels[$severity] ?? 'Unknown';
}

/**
 * Build the public-safe record for one eligible incident, or a
 * presence-only stub if the type calls for it. Never receives an
 * ineligible incident — eligibility (never-publish type / excluded group /
 * publish delay / Security Label broadcast block / INNER JOIN on in_types)
 * is entirely the caller's job, done before this function is ever called
 * (plan.md §2).
 *
 * Expected $ticketRow keys (a superset of what any one branch reads —
 * missing keys are treated as absent/empty, never fatal):
 *   id, type_name, type_group, severity, opened, updated, assigned_units,
 *   street, city, state, lat, lng,
 *   public_board_visibility ('full'|'presence_only'),
 *   public_board_stub_label (?string)
 *
 * NOTE for the caller building the eligibility query (tasks.md C1): the
 * SQL sketch in plan.md §2 selects it.public_board_never_publish /
 * _publish_delay_secs / _visibility but does NOT select
 * it.public_board_stub_label — that column must be added to the SELECT
 * list, or every presence-only incident silently falls back to the
 * generic "Response" stub even when an admin configured a specific one.
 * Flagged explicitly here since this function has no other way to see it.
 *
 * Expected $secLabel keys (this is exactly a seclabel_resolve() row):
 *   eoc_show_address (0/1), eoc_show_map_marker ('full'|'dim'|'hide'),
 *   eoc_placeholder_text (?string)
 *
 * $precisionCeiling: one of 'exact'|'block'|'city'|'hidden' — the BOARD's
 * own configured ceiling (or 'exact' for the feed.php caller, plan.md §8).
 * The label's eoc_show_map_marker can only make this COARSER, never finer
 * (plan.md §3 rule 3).
 *
 * $applyTypeVisibility: TRUE for the public-board caller (the surface
 * public_board_visibility was designed for); FALSE for the feed.php
 * caller. Security review finding #2 — without this flag, a type an
 * admin marked presence-only for the PUBLIC BOARD would also silently
 * stub out in feed.php's full-detail, trusted-consumer output. Do not
 * drop this parameter or its default.
 */
function pb_build_public_record(array $ticketRow, array $secLabel, string $precisionCeiling, bool $applyTypeVisibility = true): array
{
    $id            = (int) ($ticketRow['id'] ?? 0);
    $opened        = pb_iso8601($ticketRow['opened'] ?? null);
    $assignedUnits = (int) ($ticketRow['assigned_units'] ?? 0);

    // ── Rule 1: presence-only short-circuit ──────────────────────────
    // EXACTLY four keys in the output — id, type, opened, assigned_units.
    // No type_group/severity_text/street/city/state/lat/lng at all (not
    // null-valued — ABSENT), so a client bug can't render an empty string
    // where real data used to be, and a quiet board can't be narrowed by
    // combining severity + generic label + time + unit count.
    $visibility = (string) ($ticketRow['public_board_visibility'] ?? 'full');
    if ($applyTypeVisibility && $visibility === 'presence_only') {
        $stubLabel = trim((string) ($ticketRow['public_board_stub_label'] ?? ''));
        return [
            'id'             => $id,
            'type'           => $stubLabel !== '' ? $stubLabel : 'Response',
            'opened'         => $opened,
            'assigned_units' => $assignedUnits,
        ];
    }

    // ── Rule 3: the label's eoc_show_map_marker caps the ceiling ─────
    // (never loosens it). Rank finest-to-coarsest so "cap at city" means
    // "whichever of the two is coarser wins" — a board ceiling already
    // coarser than city (e.g. 'hidden') must NOT be loosened back to city
    // by a 'dim' label.
    $rank = ['exact' => 0, 'block' => 1, 'city' => 2, 'hidden' => 3];
    $labelMarker    = (string) ($secLabel['eoc_show_map_marker'] ?? 'full');
    $effectiveLevel = in_array($precisionCeiling, ['exact', 'block', 'city', 'hidden'], true)
        ? $precisionCeiling : 'hidden';
    if ($labelMarker === 'hide') {
        $effectiveLevel = 'hidden';
    } elseif ($labelMarker === 'dim') {
        if (($rank[$effectiveLevel] ?? 0) < $rank['city']) {
            $effectiveLevel = 'city';
        }
    }
    // else 'full' (or an unrecognized value) — board ceiling applies unmodified.

    $record = [
        'id'             => $id,
        'type'           => (string) ($ticketRow['type_name'] ?? 'Unknown'),
        'type_group'     => (string) ($ticketRow['type_group'] ?? ''),
        'severity_text'  => _pb_severity_text((int) ($ticketRow['severity'] ?? -1)),
        'opened'         => $opened,
        'updated'        => pb_iso8601($ticketRow['updated'] ?? null),
        'assigned_units' => $assignedUnits,
    ];

    // ── Rule 2: eoc_show_address ──────────────────────────────────────
    // 0 replaces street/city with the label's own eoc_placeholder_text —
    // the SAME phrase EOC Display already shows internally, one consistent
    // phrase per label across every surface that redacts it. `state` is
    // deliberately omitted rather than shown bare: a lone two-letter state
    // code next to an otherwise-placeholder record is a partial redaction,
    // not a clean one.
    //
    // CORRECTION (final adversarial review, 2026-08-13): this comment used
    // to claim eoc_show_address=0 "implicitly" redacts the narrative
    // "scope" field too — that was FALSE. eoc_show_scope is a SEPARATE,
    // independently-configurable Security Label flag (see
    // api/incidents.php's $maskScope vs $maskAddress, and
    // inc/security-labels.php) — a dispatcher can set eoc_show_address=1
    // (address visible) with eoc_show_scope=0 (narrative hidden), or vice
    // versa. This function does not build a `scope`/`description` field at
    // all (the public board itself never surfaces incident narrative), so
    // there is nothing "elsewhere" for eoc_show_address to imply. The ONE
    // caller that DOES carry scope/description — api/feed.php — reads
    // eoc_show_scope directly and masks those fields itself; see the
    // comment there. Do not reintroduce this function reading
    // eoc_show_scope without also updating that caller.
    $showAddress = ((int) ($secLabel['eoc_show_address'] ?? 1)) === 1;
    if (!$showAddress) {
        $placeholder = trim((string) ($secLabel['eoc_placeholder_text'] ?? ''));
        if ($placeholder === '') $placeholder = 'Location withheld';
        $record['street_display'] = $placeholder;
        $record['city']           = $placeholder;
    } else {
        // ── Rule 4: coordinate/address precision rounding table ──────
        $street = (string) ($ticketRow['street'] ?? '');
        $city   = (string) ($ticketRow['city'] ?? '');
        $state  = (string) ($ticketRow['state'] ?? '');
        switch ($effectiveLevel) {
            case 'exact':
                $record['street_display'] = $street;
                $record['city']  = $city;
                $record['state'] = $state;
                break;
            case 'block':
                $record['street_display'] = _pb_street_name_only($street);
                $record['city']  = $city;
                $record['state'] = $state;
                break;
            case 'city':
            case 'hidden':
            default:
                // "city/state only (or the label's placeholder)" — no
                // street_display key at this level or coarser.
                $record['city']  = $city;
                $record['state'] = $state;
                break;
        }
    }

    // ── Rule 4/5: lat/lng at the effective level ─────────────────────
    $lat = isset($ticketRow['lat']) && $ticketRow['lat'] !== null ? (float) $ticketRow['lat'] : null;
    $lng = isset($ticketRow['lng']) && $ticketRow['lng'] !== null ? (float) $ticketRow['lng'] : null;
    $coords = pb_round_coords($lat, $lng, $effectiveLevel);
    if ($coords['lat'] !== null && $coords['lng'] !== null) {
        $record['lat'] = $coords['lat'];
        $record['lng'] = $coords['lng'];
    }

    return $record;
}

} // end if (!function_exists('pb_round_coords'))

if (!function_exists('pb_eligible_incidents')) {

/**
 * Sensitive-keyword list used for the conservative-visibility heuristic
 * (plan.md §1a / tasks.md §0a). MUST be kept in sync with the identical
 * literal list in sql/run_phase138_public_board.php's one-time migration
 * seed — that script owns the historical/idempotent seeding of existing
 * rows; THIS function is what the admin UI's pre-enable warning banner
 * (tasks.md G1b) and its server-side re-check (plan.md §9 panel 1, "the
 * server-side save handler ALSO re-checks this condition independently")
 * query live against, so a type created or re-grouped AFTER the migration
 * ran is still caught.
 */
function pb_sensitive_keywords(): array
{
    return [
        'ems', 'medical', 'rescue', 'ambulance', 'mental health', 'overdose',
        'domestic', 'dv', 'sexual assault', 'abuse', 'welfare check', 'psych',
        'suicide', 'juvenile', 'child', 'minor', 'behavioral', 'crisis',
        'psychiatric', 'self harm',
        // Added 2026-08-14 per @rjonesbsink's report: his own CARDIAC, MISSING,
        // and MCI incident types (grouped under Fire/Law, not EMS) matched
        // NONE of the keywords above and defaulted to full public exposure.
        'cardiac', 'arrest', 'casualty', 'missing', 'unconscious', 'stroke',
        'respiratory', 'seizure',
    ];
}

/**
 * Live query (not the one-time migration seed): every `in_types` row whose
 * `type`, `group`, OR `description` case-insensitively matches the
 * sensitive-keyword list AND could actually appear on the public board
 * right now — i.e. `public_board_never_publish = 0` (2026-08-14: since
 * never-publish became the default, `public_board_visibility` alone is no
 * longer a reliable signal of real exposure — a type can sit at
 * visibility='full' while never_publish=1 keeps it fully hidden
 * underneath). Used by the master-switch pre-enable warning banner
 * (server-side re-check — never trust a client-side-only gate for
 * something this consequential, plan.md §9 panel 1) and by the admin UI's
 * own warning list (tasks.md G1b).
 *
 * CORRECTION (final adversarial review, 2026-08-13): this used to match
 * ONLY against `group` — which is a defensible-SOUNDING column name but
 * is, on every install this codebase's own CLAUDE.md documents (and
 * confirmed live on this install's actual demo data), the org/dispatch
 * CATEGORY a type belongs to ("CERT", "Med Team", "Campus PD", "Vol
 * Fire"), never a clinical descriptor. Matching only `group` caught
 * ZERO of the ~10 plainly medical/crisis-shaped demo types shipped with
 * every fresh install (MedAssist, WelfareChk, AltMental, MHCrisis, etc.)
 * — the pre-enable warning banner and the migration seed below were both
 * silent no-ops on stock data. `description` is where the human-readable
 * signal actually lives ("Welfare Check", "Mental Health Crisis",
 * "Medical Assistance (CERT)") — confirmed against this install's real
 * rows. `type` is included too since a real install may spell things out
 * there directly (e.g. "Suicide", "OverdoseEMS"). Still a heuristic, not
 * a guarantee — an admin must still review the Incident Type Rules panel.
 *
 * CORRECTION 2 (2026-08-14, never-publish-by-default): before this fix the
 * WHERE clause was `public_board_visibility = 'full'` alone. Once
 * never-publish defaults to 1 for nearly every type, that condition would
 * be true for almost the entire table (visibility stays 'full' underneath,
 * unused, on a type nobody has ever opted in) -- the warning banner would
 * fire on essentially every enable attempt regardless of real risk, the
 * exact "check cries wolf, gets muted" failure mode this codebase's own
 * pitfalls history already names. Added `AND public_board_never_publish =
 * 0` so this only flags types that would ACTUALLY be exposed.
 *
 * Fails safe (empty array) on any DB error — the CALLER decides what an
 * empty/unavailable result means; this function does not itself block
 * anything.
 */
function pb_sensitive_types_still_full(): array
{
    try {
        $regex = implode('|', pb_sensitive_keywords());
        return db_fetch_all(
            "SELECT `id`, `type`, `group` FROM " . db_table('in_types') . "
              WHERE `public_board_never_publish` = 0
                AND (
                    (`type` IS NOT NULL AND `type` <> '' AND LOWER(`type`) REGEXP ?)
                 OR (`group` IS NOT NULL AND `group` <> '' AND LOWER(`group`) REGEXP ?)
                 OR (`description` IS NOT NULL AND `description` <> '' AND LOWER(`description`) REGEXP ?)
                )
              ORDER BY `type`",
            [$regex, $regex, $regex]
        );
    } catch (Exception $e) {
        error_log('[pb_sensitive_types_still_full] query failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Eligibility (plan.md §2 / tasks.md C1) — which OPEN incidents may appear
 * on a public board at all, entirely BEFORE any redaction runs. An
 * ineligible incident is dropped here, never included-then-hidden.
 *
 * `INNER JOIN in_types` — NOT `LEFT JOIN` — is deliberate (security review
 * finding #4): a `ticket` row whose `in_types_id` does not resolve to a
 * real `in_types` row (the column is NOT NULL in the base schema, so this
 * means an orphaned/stale id — the practical equivalent of "untyped") must
 * be EXCLUDED, not silently included with every type-based exclusion rule
 * bypassed.
 *
 * $orgId: null = the shared (no-org) board, every eligible incident
 * regardless of `ticket.org_id`. A non-null id scopes to that org only
 * (`ticket.org_id = $orgId`) — an org whose incidents never set org_id
 * gets an always-empty, legible result, by design (plan.md §1b).
 *
 * Returns a list of ['ticket' => array, 'label' => array] pairs — the
 * exact two arguments pb_build_public_record() expects — for incidents
 * that survived BOTH the SQL-expressible eligibility gates AND the
 * per-row Security Label broadcast-block check (seclabel_resolve()
 * can't be pushed into the query — plan.md §2). Bounded to 300 rows,
 * same LIMIT/ORDER shape as api/feed.php's existing base query.
 */
function pb_eligible_incidents(?int $orgId = null): array
{
    $eligible = [];

    try {
        $excludedGroupsRaw = function_exists('get_variable') ? get_variable('public_board_excluded_groups') : false;
        $excludedGroups = [];
        if (is_string($excludedGroupsRaw) && trim($excludedGroupsRaw) !== '') {
            $excludedGroups = array_values(array_filter(array_map('trim', explode(',', $excludedGroupsRaw)), function ($g) {
                return $g !== '';
            }));
        }

        $defaultDelayRaw = function_exists('get_variable') ? get_variable('public_board_default_delay_secs') : false;
        $defaultDelay = ($defaultDelayRaw !== false && $defaultDelayRaw !== null && $defaultDelayRaw !== '')
            ? (int) $defaultDelayRaw : 90;
        if ($defaultDelay < 0) $defaultDelay = 0;

        $groupClause = '';
        $params = [];
        if (!empty($excludedGroups)) {
            $placeholders = implode(',', array_fill(0, count($excludedGroups), '?'));
            $groupClause = " AND (it.`group` IS NULL OR it.`group` NOT IN ($placeholders))";
            $params = array_merge($params, $excludedGroups);
        }
        $params[] = $defaultDelay;
        $params[] = $orgId;
        $params[] = $orgId;

        $sql = "SELECT t.id, t.scope, t.description, t.street, t.city, t.state, t.lat, t.lng,
                       t.severity, t.date AS opened, t.updated, t.org_id,
                       it.id AS type_id, it.type AS type_name, it.`group` AS type_group,
                       it.public_board_never_publish, it.public_board_publish_delay_secs,
                       it.public_board_visibility, it.public_board_stub_label,
                       (SELECT COUNT(*) FROM " . db_table('assigns') . " a
                         WHERE a.ticket_id = t.id AND (a.clear IS NULL OR a.clear = '0000-00-00 00:00:00')
                       ) AS assigned_units
                  FROM " . db_table('ticket') . " t
                  INNER JOIN " . db_table('in_types') . " it ON t.in_types_id = it.id
                 WHERE t.status = 2
                   AND (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')
                   AND it.public_board_never_publish = 0
                   {$groupClause}
                   AND TIMESTAMPDIFF(SECOND, t.date, NOW()) >= COALESCE(it.public_board_publish_delay_secs, ?)
                   AND (? IS NULL OR t.org_id = ?)
                 ORDER BY t.date DESC
                 LIMIT 300";

        $rows = db_fetch_all($sql, $params);
    } catch (Exception $e) {
        error_log('[pb_eligible_incidents] query failed: ' . $e->getMessage());
        return [];
    }

    foreach ($rows as $row) {
        try {
            $sec = function_exists('seclabel_resolve') ? seclabel_resolve((int) $row['id']) : [];
        } catch (Exception $e) {
            $sec = [];
        }
        if ((int) ($sec['routing_allow_broadcast'] ?? 1) === 0) {
            continue; // dropped, not redacted — spec.md: no count/hint that anything was withheld
        }
        $eligible[] = ['ticket' => $row, 'label' => $sec];
    }

    return $eligible;
}

} // end if (!function_exists('pb_eligible_incidents'))

if (!function_exists('pb_resolve_admin_write_org')) {

/**
 * The org-write authorization decision for api/public-board-admin.php's
 * save_organization handler (plan.md §4's code snippet), pulled out into a
 * pure, cheaply-testable function — same principle as pb_round_coords()
 * being kept pure so it's unit-testable in isolation (tasks.md C4/C5's
 * "critical covering case" drives THIS function directly rather than
 * needing a full HTTP session to exercise the branching).
 *
 * This IS the fix for security review finding #1 (an Org Admin's write
 * reaching another org's row): the branching below is the entire boundary.
 * Do not simplify it.
 *
 *   $isBoardAdmin   caller holds action.manage_public_board (install-wide)
 *   $isOrgSelf      caller holds action.manage_public_board_org (org-scoped)
 *   $callerOrgId    the caller's OWN org id, resolved server-side from the
 *                   session (NEVER from the request) — 0/negative means
 *                   "no organization on this account"
 *   $requestedOrgId the org id the CLIENT asked to write, or null if absent
 *
 * Returns ['ok' => bool, 'org_id' => ?int, 'error' => ?string, 'status' => int].
 * On ok=true, 'org_id' is the org id the caller is authorized to write —
 * for a board-admin caller this is whatever they requested; for an
 * org-self caller this is ALWAYS $callerOrgId, never $requestedOrgId.
 */
function pb_resolve_admin_write_org(bool $isBoardAdmin, bool $isOrgSelf, int $callerOrgId, ?int $requestedOrgId): array
{
    if ($isBoardAdmin) {
        // Super Admin (or anyone holding the install-wide permission): may
        // write any org row, org id comes from the request.
        $target = ($requestedOrgId !== null && $requestedOrgId > 0) ? $requestedOrgId : 0;
        if ($target <= 0) {
            return ['ok' => false, 'org_id' => null, 'error' => 'Missing organization id.', 'status' => 400];
        }
        return ['ok' => true, 'org_id' => $target, 'error' => null, 'status' => 200];
    }

    if ($isOrgSelf) {
        // Org Admin: org id is FORCED from the session, never trusted from
        // the client — this branch is the actual fix, not the permission
        // check that got us here.
        if ($callerOrgId <= 0) {
            return ['ok' => false, 'org_id' => null, 'error' => 'No organization on this account', 'status' => 403];
        }
        // A client-supplied org_id that disagrees with the caller's own is
        // evidence of a crafted request — reject explicitly rather than
        // silently overriding it with the caller's org (which would mask
        // the attempt from anyone reading the response).
        if ($requestedOrgId !== null && $requestedOrgId > 0 && $requestedOrgId !== $callerOrgId) {
            return ['ok' => false, 'org_id' => null, 'error' => 'Forbidden: cannot modify another organization', 'status' => 403];
        }
        return ['ok' => true, 'org_id' => $callerOrgId, 'error' => null, 'status' => 200];
    }

    return ['ok' => false, 'org_id' => null, 'error' => 'Forbidden', 'status' => 403];
}

} // end if (!function_exists('pb_resolve_admin_write_org'))

if (!function_exists('pb_resolve_caller_org_id')) {

/**
 * Resolve the SPECIFIC organization a user has been granted
 * action.manage_public_board_org for, via an org-SCOPED role assignment —
 * independent of session state. Used by api/public-board-admin.php's
 * _pb_admin_caller_org_id() as the ONLY input to pb_resolve_admin_write_
 * org()'s org-self branch.
 *
 * FINAL ADVERSARIAL REVIEW (2026-08-13) FOUND AND FIXED A THIRD INSTANCE OF
 * THE "PERMISSION CHECK WITH A WIDENING FALLBACK" PATTERN HERE. The
 * function this replaced read $_SESSION['active_org_id'] and, if unset,
 * fell back to org_user_home_id() (user.home_org_id). That fallback is a
 * DEFAULT-VALUE helper used everywhere else in this codebase
 * (facility-save.php, responder-save.php, incident-write.php,
 * member-write.php) ONLY to fill in a value for a NEW RECORD being
 * created — never as an authorization decision — and it returns 1
 * whenever home_org_id is NULL. sql/run_99j_org_scoping.php backfilled
 * home_org_id = 1 for every pre-existing user the day that column was
 * added, so "no explicit home org" and "org #1 (System Owner)" were
 * indistinguishable through that fallback.
 *
 * Confirmed LIVE against this install's actual data before the fix: two
 * real accounts (user_id 3 and 218) hold the "Org Admin" role with
 * scope_kind='global' and zero member_organizations rows — the exact
 * shape login.php documents as landing with active_org_id = NULL ("no-org
 * GLOBAL users"). For such a user, rbac_can('action.manage_public_board_
 * org') already returns true unconditionally (_rbac_scope_satisfied()'s
 * `case 'global': return true` ignores active_org_id entirely) — so the
 * OLD fallback let a user who was never scoped to ANY specific
 * organization silently resolve to org #1 and enable/rename its public
 * board. It was worse than "always org #1": $_SESSION['active_org_id']
 * itself was not trustworthy even when non-zero — api/organizations.php's
 * set_active_org action wrote it to session BEFORE its membership check,
 * and skipped that check entirely whenever $_SESSION['user_orgs'] was
 * empty (exactly the case for a global-scope grant with no member row),
 * so a caller in this shape could set active_org_id to ANY org id of
 * their choosing, not just org #1. (Reproduced live; api/organizations.php
 * was hardened separately as defense-in-depth, but this function does not
 * rely on that fix holding — it never reads session state at all.)
 *
 * The correct question is not "what org is in the session" but "what
 * SPECIFIC org has this user actually been granted action.manage_public_
 * board_org for, via an org-SCOPED role assignment" — resolved directly
 * from user_roles/role_permissions. A scope_kind='global' grant has no
 * single "own org" to resolve to; treated as "no organization" (0), same
 * as the function's own long-standing contract already promised for an
 * account with none. Ambiguous grants (more than one distinct org-scoped
 * assignment carrying this permission) are ALSO treated as "no
 * organization" — resolving to the first/lowest one would be exactly the
 * same silent-widening mistake in a new shape. Fails safe (0) on any DB
 * error, same convention as pb_sensitive_types_still_full().
 */
function pb_resolve_caller_org_id(int $userId): int
{
    if ($userId <= 0) return 0;

    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT ur.scope_id
               FROM " . db_table('user_roles') . " ur
               JOIN " . db_table('role_permissions') . " rp ON rp.role_id = ur.role_id
               JOIN " . db_table('permissions') . " p ON p.id = rp.permission_id
              WHERE ur.user_id = ?
                AND ur.scope_kind = 'org'
                AND ur.scope_id IS NOT NULL
                AND p.code = 'action.manage_public_board_org'
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            [$userId]
        );
    } catch (Exception $e) {
        error_log('[pb_resolve_caller_org_id] grant lookup failed: ' . $e->getMessage());
        return 0;
    }

    if (count($rows) === 1) {
        return (int) $rows[0]['scope_id'];
    }
    // Zero matching org-scoped grants (only a global-scope grant, or none
    // at all), or more than one distinct org — no single "own org" this
    // caller resolves to. pb_resolve_admin_write_org() turns 0 into a 403
    // "No organization on this account", exactly as its docblock promises.
    return 0;
}

} // end if (!function_exists('pb_resolve_caller_org_id'))

if (!function_exists('pb_valid_public_board_slug')) {

/**
 * Slug validation (plan.md §9 panel 2 / tasks.md C5): `[a-z0-9-]+`, or
 * empty (meaning "no slug set" — org isn't board-reachable by URL yet).
 * Pure function so the rule is asserted directly rather than left to
 * manual QA, and so the admin endpoint and any future admin-UI JS mirror
 * (client-side preview only, never the authority) share one definition.
 */
function pb_valid_public_board_slug(string $slug): bool
{
    return $slug === '' || (bool) preg_match('/^[a-z0-9-]+$/', $slug);
}

} // end if (!function_exists('pb_valid_public_board_slug'))
