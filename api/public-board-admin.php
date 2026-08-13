<?php
/**
 * NewUI v4.0 API — Public incident board: admin configuration (Phase 138).
 *
 * Owns ALL public-board admin WRITES (plan.md §4). Split into two RBAC
 * tiers by blast radius (security review finding #1 — the original draft's
 * single flat permission let any Org Admin control cross-org, install-wide
 * settings with no server-side check):
 *
 *   action.manage_public_board      — install-wide. Super Admin only.
 *       Covers: the six board-wide settings, the shared in_types publish
 *       rules, and editing ANY org's public_board_enabled/slug row.
 *   action.manage_public_board_org  — org-scoped self-service. Super Admin
 *       + Org Admin. Covers ONLY organizations.public_board_enabled/slug
 *       for the CALLER'S OWN org — the org id is forced server-side, never
 *       trusted from the request body (see _pb_admin_caller_org_id() below
 *       and the save_organization handler).
 *
 * GET  ?action=settings                 (manage_public_board only)
 * GET  ?action=types                    (manage_public_board only)
 * GET  ?action=organizations            (either permission — org-scoped
 *                                        caller sees only their own row)
 * GET  ?action=sensitive_types          (manage_public_board only — the
 *                                        pre-enable-banner data source,
 *                                        tasks.md G1b)
 * POST action=save_settings             (manage_public_board only, CSRF)
 * POST action=save_type                 (manage_public_board only, CSRF)
 * POST action=save_organization         (either permission, CSRF — org id
 *                                        forced server-side per above)
 *
 * Every write calls audit_log('config', 'update', ...) in the SAME request
 * as the write it logs (plan.md §6).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/public-board.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
}

// action.manage_public_board is install-wide (settings/types panels, and
// editing ANY org's row) — SUPER ADMIN ONLY (security review finding #1).
//
// Deliberately NOT `rbac_can('action.manage_public_board') || is_admin()`,
// even though that is the idiom most other admin-only endpoints in this
// codebase use (see api/weather-alerts.php) and even though it is what
// plan.md §4's own code snippet literally shows. Verified live against
// this install's actual role_permissions data while building Section G
// (tasks.md G6's manual click-through): is_admin()'s own documented
// contract (inc/rbac.php, ~line 214) is "is_super=1, OR they hold the
// action.manage_config permission" — and Org Admin's `user_roles` grant
// only needs to be scope_kind='org' with scope_id matching their own
// `active_org_id` (i.e. an ordinary, correctly-scoped Org Admin session,
// not a misconfigured one) for `rbac_can('action.manage_config')` to
// return true for them, if their role holds that permission in ANY
// installation's `role_permissions` — which can happen either from stale
// data (a grant seeded before an exclusion existed, and INSERT IGNORE
// re-imports never revoke) or from a Super Admin granting it via the
// Roles & Permissions UI. When that's true, `is_admin()` returns true for
// an Org Admin, and the `|| is_admin()` here would silently hand that Org
// Admin the exact install-wide, cross-org control this permission split
// was built to prevent — reopening security review finding #1 through a
// path the fix never considered. This was confirmed live, not theoretical:
// an Org Admin test account with a properly org-scoped grant reached this
// endpoint's `settings` action (HTTP 200) before this comment/fix existed.
//
// Dropping `|| is_admin()` loses nothing for a genuine Super Admin:
// rbac_can()'s OWN is_super short-circuit (inc/rbac.php ~line 70) already
// returns true for ANY permission code when the caller's cached grants
// have is_super=1 — before it even resolves $permCode. So
// rbac_can('action.manage_public_board') alone already reaches every real
// Super Admin; is_admin()'s extra action.manage_config fallback was
// providing zero additional legitimate reach here, only the leak above.
$isBoardAdmin = rbac_can('action.manage_public_board');
$isOrgSelf    = rbac_can('action.manage_public_board_org');

/**
 * The acting user's OWN org id, resolved server-side — NEVER from the
 * request. Thin session-reading wrapper around the pure, independently-
 * testable pb_resolve_caller_org_id() (inc/public-board.php) — see that
 * function's docblock for why this can no longer trust
 * $_SESSION['active_org_id'] or org_user_home_id().
 */
function _pb_admin_caller_org_id(): int
{
    return pb_resolve_caller_org_id((int) ($_SESSION['user_id'] ?? 0));
}

/** CSRF on every write. Same pattern as api/config-admin.php. */
function _pb_admin_require_csrf(array $input): void
{
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) json_error('Invalid CSRF token', 403);
}

// ═══════════════════════════════════════════════════════════════════════
//  GET — reads
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'GET' && $action === 'settings') {
    if (!$isBoardAdmin) json_error('Insufficient permissions: manage public board', 403);
    $keys = [
        'public_board_enabled', 'public_board_address_precision',
        'public_board_excluded_groups', 'public_board_default_delay_secs',
        'public_board_rate_limit_requests', 'public_board_rate_limit_window_secs',
    ];
    try {
        $rows = db_fetch_all(
            "SELECT `name`, `value` FROM " . db_table('settings') . " WHERE `name` IN (" .
            implode(',', array_fill(0, count($keys), '?')) . ")", $keys
        );
        $cfg = [];
        foreach ($keys as $k) $cfg[$k] = null;
        foreach ($rows as $r) $cfg[$r['name']] = $r['value'];
        json_response(['settings' => $cfg]);
    } catch (Throwable $e) {
        json_error_safe('Failed to load settings.', $e, 'public_board.get_settings');
    }
}

if ($method === 'GET' && $action === 'types') {
    if (!$isBoardAdmin) json_error('Insufficient permissions: manage public board', 403);
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `type`, `description`, `group`,
                    `public_board_never_publish`, `public_board_publish_delay_secs`,
                    `public_board_visibility`, `public_board_stub_label`
               FROM " . db_table('in_types') . "
              ORDER BY `group`, `type`"
        );
        json_response(['types' => $rows]);
    } catch (Throwable $e) {
        json_error_safe('Failed to load incident types.', $e, 'public_board.get_types');
    }
}

if ($method === 'GET' && $action === 'sensitive_types') {
    // Pre-enable-banner data source (tasks.md G1b) — every in_types row
    // whose group/type/description looks sensitive AND is still 'full'.
    //
    // Value/mission review finding #1 (2026-08-13): originally board-admin
    // only. But save_organization's own server-side re-check (added the
    // same day, right below) means an org-self caller (Org Admin) can hit
    // that exact 409 on their FIRST attempt to enable their own org's
    // board — with no way to see WHY, since this read was denied to them.
    // Read-only diagnostic detail about the shared in_types table is not
    // itself a write; opening it to action.manage_public_board_org lets an
    // Org Admin actually see and act on the same warning a board admin
    // sees, instead of hitting an opaque 409 they cannot resolve.
    if (!$isBoardAdmin && !$isOrgSelf) json_error('Insufficient permissions', 403);
    json_response(['types' => pb_sensitive_types_still_full()]);
}

if ($method === 'GET' && $action === 'organizations') {
    if (!$isBoardAdmin && !$isOrgSelf) json_error('Insufficient permissions', 403);
    // `open_incident_count` (per org, currently-open, not-soft-deleted) is
    // the data source for the admin UI's inline §7 "check 3" diagnostic
    // (tasks.md G2 — "0 open incidents currently tagged to this
    // organization"). Section H's health_check_public_board() doesn't exist
    // yet (it's built in a later task-list section, after G), so this GET
    // computes the same count directly rather than G2 having no data to
    // show at all. Same open/not-deleted definition as plan.md §2's
    // eligibility query and §7's check 3 (status = 2, soft-delete guard).
    $openIncidentCountSql = "(SELECT COUNT(*) FROM " . db_table('ticket') . " t
                               WHERE t.org_id = o.id AND t.status = 2
                                 AND (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')
                              ) AS open_incident_count";
    try {
        if ($isBoardAdmin) {
            $rows = db_fetch_all(
                "SELECT o.`id`, o.`name`, o.`short_name`, o.`public_board_enabled`, o.`public_board_slug`,
                        {$openIncidentCountSql}
                   FROM " . db_table('organizations') . " o ORDER BY o.`name`"
            );
        } else {
            // Org-scoped caller: server-side filter to their own org only.
            // (The admin UI's "client-side filter" language in plan.md §9
            // panel 2 describes DISPLAY convenience on top of this — the
            // real boundary is here, not trusting the browser to behave.)
            $orgId = _pb_admin_caller_org_id();
            $rows = $orgId > 0 ? db_fetch_all(
                "SELECT o.`id`, o.`name`, o.`short_name`, o.`public_board_enabled`, o.`public_board_slug`,
                        {$openIncidentCountSql}
                   FROM " . db_table('organizations') . " o WHERE o.`id` = ?", [$orgId]
            ) : [];
        }
        json_response(['organizations' => $rows]);
    } catch (Throwable $e) {
        json_error_safe('Failed to load organizations.', $e, 'public_board.get_organizations');
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  POST — writes
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'POST' && $action === 'save_settings') {
    if (!$isBoardAdmin) json_error('Insufficient permissions: manage public board', 403);
    _pb_admin_require_csrf($input);

    $precision = (string) ($input['public_board_address_precision'] ?? 'block');
    if (!in_array($precision, ['exact', 'block', 'city', 'hidden'], true)) {
        json_error('Invalid address precision level.');
    }
    $delay = (int) ($input['public_board_default_delay_secs'] ?? 90);
    if ($delay < 0) json_error('Default delay must not be negative.');
    $rlRequests = (int) ($input['public_board_rate_limit_requests'] ?? 30);
    if ($rlRequests < 1) json_error('Rate limit requests must be at least 1.');
    $rlWindow = (int) ($input['public_board_rate_limit_window_secs'] ?? 60);
    if ($rlWindow < 1) json_error('Rate limit window must be at least 1 second.');

    // Excluded groups: comma-separated free text, trimmed, de-duplicated.
    $groupsRaw = (string) ($input['public_board_excluded_groups'] ?? '');
    $groups = array_values(array_unique(array_filter(array_map('trim', explode(',', $groupsRaw)), function ($g) {
        return $g !== '';
    })));
    $groupsClean = implode(',', $groups);

    $newEnabled = !empty($input['public_board_enabled']) ? '1' : '0';

    try {
        // ── Server-side re-check of the pre-enable sensitive-type warning
        // (security/value-mission review finding #1, plan.md §9 panel 1):
        // "the server-side save handler ALSO re-checks this condition
        // independently — never trust a client-side-only gate for
        // something this consequential." Only fires on an off->on
        // transition, and only blocks if the caller has not sent the
        // acknowledgment flag.
        $prevEnabled = (string) (db_fetch_value(
            "SELECT `value` FROM " . db_table('settings') . " WHERE `name` = 'public_board_enabled' LIMIT 1"
        ) ?? '0');
        if ($prevEnabled !== '1' && $newEnabled === '1') {
            $stillFull = pb_sensitive_types_still_full();
            if (!empty($stillFull) && empty($input['public_board_ack_sensitive_types'])) {
                $names = array_map(function ($r) { return $r['type']; }, $stillFull);
                json_error(
                    count($stillFull) . ' incident type(s) that look medical/sensitive ('
                    . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ', ...' : '')
                    . ') are still set to Full visibility on the public board. Review the '
                    . 'Incident Type Rules panel and confirm before enabling.',
                    409
                );
            }
        }

        $writes = [
            'public_board_enabled'                => $newEnabled,
            'public_board_address_precision'      => $precision,
            'public_board_excluded_groups'        => $groupsClean,
            'public_board_default_delay_secs'     => (string) $delay,
            'public_board_rate_limit_requests'    => (string) $rlRequests,
            'public_board_rate_limit_window_secs' => (string) $rlWindow,
        ];
        foreach ($writes as $k => $v) {
            db_query(
                "INSERT INTO " . db_table('settings') . " (`name`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$k, $v]
            );
        }
        audit_log('config', 'update', 'public_board_settings', 0,
            'Updated public-board settings (enabled=' . $newEnabled . ', precision=' . $precision . ')',
            $writes);
        json_response(['saved' => true]);
    } catch (Throwable $e) {
        json_error_safe('Save failed. Check server logs.', $e, 'public_board.save_settings');
    }
}

if ($method === 'POST' && $action === 'save_type') {
    if (!$isBoardAdmin) json_error('Insufficient permissions: manage public board', 403);
    _pb_admin_require_csrf($input);

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('Missing incident type id.');

    $visibility = (string) ($input['public_board_visibility'] ?? 'full');
    if (!in_array($visibility, ['full', 'presence_only'], true)) {
        json_error('Invalid visibility value.');
    }
    $neverPublish = !empty($input['public_board_never_publish']) ? 1 : 0;
    $delayRaw = $input['public_board_publish_delay_secs'] ?? null;
    $delay = ($delayRaw === null || $delayRaw === '') ? null : max(0, (int) $delayRaw);
    $stubLabel = trim((string) ($input['public_board_stub_label'] ?? ''));
    if (mb_strlen($stubLabel) > 64) $stubLabel = mb_substr($stubLabel, 0, 64);
    $stubLabel = $stubLabel === '' ? null : $stubLabel;

    try {
        $exists = db_fetch_value("SELECT `id` FROM " . db_table('in_types') . " WHERE `id` = ? LIMIT 1", [$id]);
        if (!$exists) json_error('Incident type not found.', 404);

        db_query(
            "UPDATE " . db_table('in_types') . "
                SET `public_board_never_publish` = ?,
                    `public_board_publish_delay_secs` = ?,
                    `public_board_visibility` = ?,
                    `public_board_stub_label` = ?
              WHERE `id` = ?",
            [$neverPublish, $delay, $visibility, $stubLabel, $id]
        );
        audit_log('config', 'update', 'incident_type', $id,
            'Updated public-board rules for incident type #' . $id
            . ' (never_publish=' . $neverPublish . ', visibility=' . $visibility . ')',
            ['never_publish' => $neverPublish, 'delay_secs' => $delay,
             'visibility' => $visibility, 'stub_label' => $stubLabel]);
        json_response(['saved' => true]);
    } catch (Throwable $e) {
        json_error_safe('Save failed. Check server logs.', $e, 'public_board.save_type');
    }
}

if ($method === 'POST' && $action === 'save_organization') {
    _pb_admin_require_csrf($input);

    // ── The server-side org check a permission grant alone cannot express
    // (plan.md §4's snippet). The decision itself lives in the pure,
    // independently-tested pb_resolve_admin_write_org() (inc/public-board.php)
    // — see the _pb_admin_caller_org_id() docblock above for the one
    // deviation from plan.md's literal snippet: the real session key in
    // this codebase is `active_org_id`, not `org_id`. ──
    $requestedOrgId = isset($input['org_id']) ? (int) $input['org_id'] : null;
    $decision = pb_resolve_admin_write_org($isBoardAdmin, $isOrgSelf, _pb_admin_caller_org_id(), $requestedOrgId);
    if (!$decision['ok']) {
        json_error($decision['error'], $decision['status']);
    }
    $targetOrgId = $decision['org_id'];

    $enabled = !empty($input['public_board_enabled']) ? 1 : 0;
    $slugRaw = trim((string) ($input['public_board_slug'] ?? ''));
    if (!pb_valid_public_board_slug($slugRaw)) {
        json_error('URL slug may only contain lowercase letters, numbers, and hyphens.');
    }
    $slug = $slugRaw === '' ? null : $slugRaw;

    try {
        $org = db_fetch_one("SELECT `id`, `name`, `public_board_enabled` FROM " . db_table('organizations') . " WHERE `id` = ? LIMIT 1", [$targetOrgId]);
        if (!$org) json_error('Organization not found.', 404);

        // ── Value/mission review finding #1 (2026-08-13) — the sensitive-
        // type pre-enable re-check existed ONLY on save_settings (the
        // install-wide master switch). This is the SECOND on/off switch
        // that exposes the exact same shared in_types data (plan.md §1b:
        // "org-scoping is URL routing only, not per-org redaction rules")
        // — an Org Admin flipping THEIR OWN org's board on with zero
        // warning, no ack, and (unlike a board admin) no visibility into
        // the Incident Type Rules panel at all to have checked beforehand.
        // Same re-check as save_settings, scoped to this org's own
        // off->on transition; the sensitive-type list itself is
        // necessarily install-wide (in_types is one shared table).
        $prevOrgEnabled = (string) ($org['public_board_enabled'] ?? '0');
        if ($prevOrgEnabled !== '1' && $enabled === 1) {
            $stillFull = pb_sensitive_types_still_full();
            if (!empty($stillFull) && empty($input['public_board_ack_sensitive_types'])) {
                $names = array_map(function ($r) { return $r['type']; }, $stillFull);
                json_error(
                    count($stillFull) . ' incident type(s) that look medical/sensitive ('
                    . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ', ...' : '')
                    . ') are still set to Full visibility on the public board. These are shared '
                    . 'across every organization\'s board — ask a Super Admin to review the '
                    . 'Incident Type Rules panel, or confirm below to enable anyway.',
                    409
                );
            }
        }

        try {
            db_query(
                "UPDATE " . db_table('organizations') . "
                    SET `public_board_enabled` = ?, `public_board_slug` = ?
                  WHERE `id` = ?",
                [$enabled, $slug, $targetOrgId]
            );
        } catch (Throwable $dupe) {
            // C5: surface the DB's uniqueness constraint as a friendly
            // error, never the raw MySQL duplicate-key message.
            if (stripos($dupe->getMessage(), 'Duplicate entry') !== false
                || stripos($dupe->getMessage(), 'uk_public_board_slug') !== false) {
                json_error('That URL slug is already in use by another organization.', 409);
            }
            throw $dupe;
        }

        $callerOrgId = _pb_admin_caller_org_id();
        audit_log('config', 'update', 'organization', $targetOrgId,
            'Public board ' . ($enabled ? 'enabled' : 'disabled') . " for '{$org['name']}'"
            . ($slug ? " (slug: {$slug})" : ''),
            ['acting_user_org_id' => $callerOrgId, 'target_org_id' => $targetOrgId,
             'enabled' => $enabled, 'slug' => $slug]);
        json_response(['saved' => true]);
    } catch (Throwable $e) {
        json_error_safe('Save failed. Check server logs.', $e, 'public_board.save_organization');
    }
}

// ── Unmatched route ───────────────────────────────────────────────────
json_error('Unknown action.', 404);
