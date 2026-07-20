<?php
/**
 * NewUI v4.0 — RBAC (Role-Based Access Control) Helper
 *
 * Permission-checking with scope-aware grants. Companion spec:
 *   specs/rbac-redesign-2026-05/spec.md  (the WHAT and WHY)
 *   specs/rbac-redesign-2026-05/plan.md  (the HOW)
 *
 * Public surface:
 *
 *   rbac_can(string $permCode, array $context = []): bool
 *       The central check. $context can include:
 *         - 'org_id'   int   Override session active_org_id for this check.
 *         - 'team_id'  int   Resource's team for scope_kind='team' grants.
 *         - 'owner_id' int   Resource's owning user for scope_kind='self' grants.
 *
 *   rbac_user_permissions(): array
 *       List of effective permission codes. Honours scopes against the
 *       active session (no context — useful for UI rendering).
 *
 *   rbac_visible_widgets(): array
 *       Convenience filter on rbac_user_permissions() returning only
 *       widget-visibility codes.
 *
 *   rbac_user_roles(?int $userId = null): array
 *       The grants assigned to a user (id, name, scope_kind, scope_id,
 *       expires_at). Pass a userId to look up a specific account (e.g.
 *       during login for the account being authenticated); omit to
 *       resolve against $_SESSION['user_id'].
 *
 *   rbac_can_login(int $userId): bool
 *       Cheap pre-auth check; bypasses scopes.
 *
 *   rbac_clear_cache(): void
 *       Drop the per-request cache. Tests use this when flipping the
 *       session user mid-run.
 *
 * Legacy fallback: when the v2 schema isn't applied yet (fresh checkout
 * before the migration runner has been executed), _rbac_legacy_check()
 * still answers based on user.level. After migration this path is
 * unreachable; Block B5 deletes it as part of the redesign closeout.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────
// Public API
// ─────────────────────────────────────────────────────────────────────

function rbac_can(string $permCode, array $context = []): bool {
    $cache = _rbac_load_grants();

    // Super-admin short-circuit (any role with is_super=1).
    if ($cache !== false && !empty($cache['is_super'])) {
        return true;
    }

    // Legacy fallback when the v2 schema isn't present.
    if ($cache === false) {
        $level = (int) ($_SESSION['level'] ?? 99);
        if ($level === 0) return true;
        return _rbac_legacy_check($permCode, $level);
    }

    // Resolve aliases — both old and canonical codes work during the
    // deprecation window. The grants index already contains both forms.
    $candidates = _rbac_alias_candidates($permCode);

    // The actor's user id (for self / separate-approver checks).
    $actorId = (int) ($_SESSION['user_id'] ?? 0);

    // Separate-approver guard: when enabled, an actor cannot satisfy
    // an `approve` permission against their own resource regardless
    // of which grant supplies it. Volunteer ops leave this off (the
    // default). See specs/rbac-redesign-2026-05/handoff.md decision #2.
    $isApproveVerb = false;
    foreach ($candidates as $c) {
        if (str_ends_with($c, '.approve')
            || str_starts_with($c, 'action.approve_')
            || str_ends_with($c, '.reject')
            || str_starts_with($c, 'action.reject_')) {
            $isApproveVerb = true;
            break;
        }
    }
    if ($isApproveVerb
        && !empty($context['owner_id'])
        && (int) $context['owner_id'] === $actorId
        && _rbac_setting('rbac.require_separate_approver') === '1') {
        return false;
    }

    // Walk the cached grants. Any matching grant whose scope is
    // satisfied by $context grants the permission.
    foreach ($candidates as $code) {
        if (empty($cache['by_code'][$code])) continue;
        foreach ($cache['by_code'][$code] as $grant) {
            if (_rbac_scope_satisfied($grant, $context, $actorId)) {
                return true;
            }
        }
    }
    return false;
}

function rbac_user_permissions(): array {
    $cache = _rbac_load_grants();
    if ($cache === false) return [];
    if (!empty($cache['is_super'])) {
        // Return every canonical code we know about.
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $rows = db_fetch_all(
                "SELECT code FROM `{$prefix}permissions` WHERE deprecated_alias_of IS NULL"
            );
            return array_map(fn($r) => $r['code'], $rows);
        } catch (Throwable $e) {
            return array_keys($cache['by_code']);
        }
    }
    // Honour scope against the session-active context (no overrides).
    $codes = [];
    $actorId = (int) ($_SESSION['user_id'] ?? 0);
    foreach ($cache['by_code'] as $code => $grants) {
        foreach ($grants as $g) {
            if (_rbac_scope_satisfied($g, [], $actorId)) {
                $codes[$code] = true;
                break;
            }
        }
    }
    return array_keys($codes);
}

function rbac_visible_widgets(): array {
    $perms = rbac_user_permissions();
    $widgets = [];
    foreach ($perms as $p) {
        // New canonical: <name>.view where the row's category is 'widget'.
        // Old form: widget.<name>. Both surface here.
        if (str_starts_with($p, 'widget.')) {
            $widgets[] = $p;
        }
    }
    return $widgets;
}

/**
 * RBAC enforcement (2026-06-22, specs/rbac-enforcement-2026-06).
 * Page-level gate. Call IMMEDIATELY after the auth check at the top of a page.
 * If the session user lacks $permCode (and is not an admin), render the shared
 * themed Access-Denied partial and exit. Admins always pass.
 *
 * The default roles are seeded with the right screen.* perms, so this only
 * denies users whose role genuinely excludes the screen.
 */
function rbac_require_screen(string $permCode): void {
    if (rbac_can($permCode) || is_admin()) return;
    if (!headers_sent()) {
        http_response_code(403);
    }
    $GLOBALS['__denied_perm'] = $permCode;
    require_once __DIR__ . '/denied.php';
    exit;
}

/**
 * Dashboard widget gate (2026-06-22). Returns true iff the session user may
 * see the widget identified by its data-widget name. Handles the one catalog
 * mismatch: the `statistics` widget maps to permission `widget.stats`.
 * $perms is an optional pre-fetched rbac_user_permissions() array (avoids a
 * re-query when called in a loop over many widgets).
 */
function dash_can(string $widget, array $perms = []): bool {
    static $map = ['statistics' => 'widget.stats'];
    $perm = $map[$widget] ?? 'widget.' . $widget;
    if (rbac_can($perm)) return true;
    return $perms && in_array($perm, $perms, true);
}

/**
 * The widget permission code for a data-widget name (for JS allow-lists and
 * the layout-API filter). Mirrors dash_can()'s mapping.
 */
function dash_widget_perm(string $widget): string {
    static $map = ['statistics' => 'widget.stats'];
    return $map[$widget] ?? 'widget.' . $widget;
}

/**
 * Phase 12 (2026-06-11): the canonical admin gate. Replaces every
 * `$current_level <= 1` check throughout the codebase. Returns true
 * iff the current session user has any active grant on a role with
 * is_super=1, OR they hold the action.manage_config permission.
 *
 * Cached per request.
 */
function is_admin(bool $forceReload = false): bool {
    static $cached = null;
    if ($forceReload) $cached = null;
    if ($cached !== null) return $cached;

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) return $cached = false;

    // Cheap path: walk cached grants for is_super=1.
    $cache = _rbac_load_grants();
    if ($cache !== false && !empty($cache['is_super'])) {
        return $cached = true;
    }

    // Fall back to the explicit "manage everything" permission.
    if (function_exists('rbac_can') && rbac_can('action.manage_config')) {
        return $cached = true;
    }

    return $cached = false;
}

/**
 * Phase 12 (2026-06-11): returns the user's primary active role name.
 * Replaces every `get_level_text((int)$_SESSION['level'])` call that
 * displayed legacy text in page headers. Falls back to "—" if no
 * active grant (which would be a brief migration transient).
 *
 * Cached per request.
 */
function current_role_name(bool $forceReload = false): string {
    static $cached = null;
    if ($forceReload) $cached = null;
    if ($cached !== null) return $cached;

    // Phase 11 stores role_name in session at login time. Use it if present.
    if (!empty($_SESSION['role_name'])) {
        return $cached = (string) $_SESSION['role_name'];
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) return $cached = '—';

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $name = db_fetch_value(
            "SELECT r.name
             FROM `{$prefix}user_roles` ur
             JOIN `{$prefix}roles` r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
             ORDER BY r.sort_order, ur.granted_at DESC
             LIMIT 1",
            [$userId]
        );
        return $cached = $name ?: '—';
    } catch (Exception $e) {
        return $cached = '—';
    }
}

/**
 * Return the RBAC role grants for a user.
 *
 * @param int|null $userId  If null or 0, falls back to the current session's
 *                          user_id (the historical behavior). Callers that
 *                          need to check roles for a specific user — e.g.
 *                          the login flow deciding whether 2FA is required
 *                          for the account being authenticated — MUST pass
 *                          the target user id explicitly.
 *
 * This signature was silently arity-0 through 2026-07-03; callers that
 * "passed a $userId" (like inc/tfa.php::tfa_is_required_for_user()) were
 * ignored and the function always answered for whatever session was
 * active, which during login is either nobody or the wrong user. See
 * Sonar php:S930 finding on inc/tfa.php:843.
 */
function rbac_user_roles(?int $userId = null): array {
    if ($userId === null || $userId === 0) {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
    }
    if (!$userId) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_all(
            "SELECT r.id, r.name, r.description, ur.org_id, ur.scope_kind,
                    ur.scope_id, ur.expires_at, ur.granted_at, r.is_super
             FROM `{$prefix}user_roles` ur
             JOIN `{$prefix}roles` r ON ur.role_id = r.id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
             ORDER BY r.sort_order",
            [$userId]
        );
    } catch (Exception $e) {
        return [];
    }
}

function rbac_can_login(int $userId): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $val = db_fetch_value(
            "SELECT `can_login` FROM `{$prefix}user` WHERE `id` = ?",
            [$userId]
        );
        return $val === null || (int) $val === 1;
    } catch (Exception $e) {
        return true;
    }
}

function rbac_clear_cache(): void {
    _rbac_load_grants(true);
}

// ─────────────────────────────────────────────────────────────────────
// Phase 99u-1 (a beta tester/Eric beta 2026-06-29) — permission-audit
// dismissal helpers + un-reviewed math.
//
// The yellow "N permissions not granted to any non-system role" banner
// is annoying because there's no way to acknowledge it. These helpers
// add a dismissals table so an admin can mark a permission as
// intentionally un-granted; the audit math then treats that permission
// as "reviewed" and stops counting it.
//
// A permission is REVIEWED when EITHER:
//   - at least one non-system role grants it, OR
//   - a row exists in permission_review_dismissals for it.
// When BOTH conditions are false, it's UN-REVIEWED and counted by
// rbac_unreviewed_count() — which is what the banner shows.
//
// Per Eric: no dismissal-reason column. The acting user is captured
// in the audit_log entry written by api/rbac.php on dismiss/undismiss.
// ─────────────────────────────────────────────────────────────────────

/**
 * Idempotently ensure the permission_review_dismissals table exists.
 * Safe to call on every request (one info_schema check + at most one
 * CREATE TABLE on a fresh install). Cached in a static after first
 * confirmation.
 */
function rbac_ensure_dismissal_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}permission_review_dismissals` (
            `id`            INT(11) NOT NULL AUTO_INCREMENT,
            `permission_id` INT(11) NOT NULL,
            `dismissed_by`  INT(11) NOT NULL,
            `dismissed_at`  DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_perm` (`permission_id`),
            KEY `idx_dismissed_by` (`dismissed_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('[rbac_ensure_dismissal_table] create failed: ' . $e->getMessage());
    }
}

/**
 * Set of permission ids dismissed from the audit.
 *   ['12' => true, '47' => true, ...]
 * Returns [] if the table is missing (legacy installs).
 */
function rbac_dismissed_permission_ids(): array
{
    rbac_ensure_dismissal_table();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all("SELECT `permission_id` FROM `{$prefix}permission_review_dismissals`");
        $set = [];
        foreach ($rows as $r) $set[(int) $r['permission_id']] = true;
        return $set;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Count of un-reviewed permissions = (no non-system role grants it)
 * AND (no dismissal). Drives the banner number.
 */
function rbac_unreviewed_count(): int
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    rbac_ensure_dismissal_table();
    try {
        $row = db_fetch_one(
            "SELECT COUNT(*) AS cnt FROM `{$prefix}permissions` p
              WHERE NOT EXISTS (
                    SELECT 1 FROM `{$prefix}role_permissions` rp
                      JOIN `{$prefix}roles` r ON r.id = rp.role_id
                     WHERE rp.permission_id = p.id
                       AND (r.is_super = 0 OR r.is_super IS NULL)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM `{$prefix}permission_review_dismissals` d
                     WHERE d.permission_id = p.id
                )"
        );
        return (int) ($row['cnt'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Mark a permission as intentionally un-granted. Caller (api/rbac.php)
 * is responsible for writing the audit_log entry — this helper just
 * persists the row. Returns true on insert, false if already dismissed
 * (idempotent: UNIQUE KEY on permission_id).
 */
function rbac_dismiss_permission(int $permissionId, int $actingUserId): bool
{
    rbac_ensure_dismissal_table();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}permission_review_dismissals`
             (`permission_id`, `dismissed_by`, `dismissed_at`)
             VALUES (?, ?, NOW())",
            [$permissionId, $actingUserId]
        );
        return true;
    } catch (Exception $e) {
        error_log('[rbac_dismiss_permission] insert failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Re-open the review on a previously-dismissed permission. Returns
 * true regardless of whether a row was actually deleted (idempotent).
 */
function rbac_undismiss_permission(int $permissionId): bool
{
    rbac_ensure_dismissal_table();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "DELETE FROM `{$prefix}permission_review_dismissals`
              WHERE `permission_id` = ?",
            [$permissionId]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * True iff the v2 schema (scope_kind on user_roles) has been applied.
 * Cached per-request. Used by inc/auth.php's fail-closed guard so we
 * don't punish installs that haven't migrated yet.
 */
function _rbac_v2_schema_present(): bool {
    static $present = null;
    if ($present !== null) return $present;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'scope_kind'",
            [$prefix . 'user_roles']
        );
        return $present = !empty($row);
    } catch (Throwable $e) {
        return $present = false;
    }
}

// ─────────────────────────────────────────────────────────────────────
// Internals
// ─────────────────────────────────────────────────────────────────────

/**
 * Load the user's effective grants and build a permission-code index.
 *
 *   Returns false if the v2 schema isn't applied (caller falls back).
 *   Returns array{
 *      'is_super'  : bool,
 *      'by_code'   : array<string, array<grant>>
 *   } otherwise. Each grant carries scope_kind / scope_id / expires_at /
 *   delegated_by / delegation_depth.
 *
 * Cached per request.
 */

/**
 * Force-reload the rbac grants cache. Useful for test harnesses that
 * swap $_SESSION between cases — the static cache inside
 * _rbac_load_grants() is keyed on first call, so without an explicit
 * reset, later cases see the first user's grants. In normal HTTP use
 * each request has one session, so this is unnecessary.
 *
 * 2026-06-11 — added when Phase 10b RBAC-aware user_can_access_entity()
 * surfaced this latent issue in tests/test_security_f001_upload.php.
 */
function rbac_reset_cache(): void {
    _rbac_load_grants(true);
    // Phase 12 (2026-06-11): also reset the is_admin / current_role_name
    // helper caches so tests that swap $_SESSION between sub-cases see
    // the fresh state.
    if (function_exists('is_admin')) is_admin(true);
    if (function_exists('current_role_name')) current_role_name(true);
}

function _rbac_load_grants(bool $forceReload = false) {
    static $cache = null;
    if ($forceReload) { $cache = null; }
    if ($cache !== null) { return $cache; }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) { return $cache = false; }

    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        // Check whether the v2 columns exist; if not, return false.
        $hasV2 = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'scope_kind'",
            [$prefix . 'user_roles']
        );
        if (!$hasV2) { return $cache = false; }

        // Active grants joined to permissions. Expired grants are
        // filtered out at the SQL layer so PHP doesn't need to know.
        $rows = db_fetch_all(
            "SELECT ur.role_id, ur.scope_kind, ur.scope_id, ur.expires_at,
                    ur.delegated_by, ur.delegation_depth,
                    r.is_super,
                    p.code, p.deprecated_alias_of
             FROM `{$prefix}user_roles` ur
             JOIN `{$prefix}roles` r              ON ur.role_id = r.id
             JOIN `{$prefix}role_permissions` rp  ON rp.role_id = r.id
             JOIN `{$prefix}permissions` p        ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            [$userId]
        );
    } catch (Throwable $e) {
        return $cache = false;
    }

    $isSuper = false;
    $byCode  = [];
    foreach ($rows as $row) {
        if ((int) $row['is_super'] === 1) { $isSuper = true; }

        $grant = [
            'scope_kind'       => $row['scope_kind'],
            'scope_id'         => $row['scope_id'] === null ? null : (int) $row['scope_id'],
            'expires_at'       => $row['expires_at'],
            'delegated_by'     => $row['delegated_by'] === null ? null : (int) $row['delegated_by'],
            'delegation_depth' => (int) ($row['delegation_depth'] ?? 0),
        ];

        // Index by the row's code AND by its canonical-alias target if
        // any. That way both old (`action.edit_incident`) and new
        // (`incident.edit`) lookups hit the same grant.
        $byCode[$row['code']][] = $grant;
        if (!empty($row['deprecated_alias_of'])) {
            $byCode[$row['deprecated_alias_of']][] = $grant;
        }
    }

    return $cache = ['is_super' => $isSuper, 'by_code' => $byCode];
}

/**
 * Scope predicate. Returns true iff the grant applies to the given
 * context. Conservative on unrecognised scope kinds (deny).
 */
function _rbac_scope_satisfied(array $grant, array $context, int $actorId): bool {
    switch ($grant['scope_kind']) {
        case 'global':
            return true;

        case 'org':
            $ctxOrg = isset($context['org_id'])
                ? (int) $context['org_id']
                : (int) ($_SESSION['active_org_id'] ?? 0);
            return $ctxOrg > 0 && $ctxOrg === (int) $grant['scope_id'];

        case 'team':
            return isset($context['team_id'])
                && (int) $context['team_id'] === (int) $grant['scope_id'];

        case 'self':
            return isset($context['owner_id'])
                && (int) $context['owner_id'] === $actorId;

        case 'delegate':
            // Delegate grants are bounded by depth and expiry; expiry
            // is already enforced in the SQL filter. The depth cap
            // applies when the system creates further delegated
            // grants — at check time we honour them like global so
            // long as scope_id (the original delegator) is set.
            return $grant['scope_id'] !== null;

        default:
            return false;
    }
}

/**
 * Return the list of codes that should match a given lookup, including
 * the canonical/deprecated alias of the requested code. The same list
 * is what the SQL query indexes by, so this is a small helper.
 */
function _rbac_alias_candidates(string $permCode): array {
    static $aliasMap = null;
    if ($aliasMap === null) {
        $aliasMap = ['old_to_new' => [], 'new_to_old' => []];
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $rows = db_fetch_all(
                "SELECT code, deprecated_alias_of FROM `{$prefix}permissions`
                 WHERE deprecated_alias_of IS NOT NULL"
            );
            foreach ($rows as $r) {
                $aliasMap['old_to_new'][$r['code']] = $r['deprecated_alias_of'];
                $aliasMap['new_to_old'][$r['deprecated_alias_of']][] = $r['code'];
            }
        } catch (Throwable $e) {
            // Schema not migrated; alias resolution is a no-op.
        }
    }
    $out = [$permCode];
    if (isset($aliasMap['old_to_new'][$permCode])) {
        $out[] = $aliasMap['old_to_new'][$permCode];
    }
    if (isset($aliasMap['new_to_old'][$permCode])) {
        foreach ($aliasMap['new_to_old'][$permCode] as $oldCode) {
            $out[] = $oldCode;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Cheap settings reader for the rbac.* feature flags. Returns null when
 * the row is absent so callers can supply their own default.
 */
function _rbac_setting(string $name): ?string {
    static $cache = [];
    if (array_key_exists($name, $cache)) return $cache[$name];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $val = db_fetch_value(
            "SELECT value FROM `{$prefix}settings` WHERE name = ?",
            [$name]
        );
        return $cache[$name] = ($val === false ? null : $val);
    } catch (Throwable $e) {
        return $cache[$name] = null;
    }
}

/**
 * Pre-v2 fallback. Removed in Block B5 once the migration is mandatory.
 * Until then it preserves login for installs that haven't run
 * `sql/run_rbac_v2.php`.
 */
function _rbac_legacy_check(string $permCode, int $level): bool {
    // Phase 73cc — was a blocklist (level=2 gets everything EXCEPT a
    // 4-element deny list). That meant high-impact permissions added
    // since the original write — screen.audit_log, screen.encryption_keys,
    // action.manage_routing, action.manage_rbac, action.broadcast_alerts —
    // all evaluated TRUE for level=2 on any install that hadn't completed
    // the v2 migration. Replaced with an explicit allowlist so anything
    // not listed is denied, full stop.
    //
    // This path only fires when _rbac_v2_schema_present() returns false,
    // which on a modern install is "never" — the migration is part of
    // the install flow. If you hit this in prod, run sql/run_rbac_v2.php.
    if ($level <= 1) {
        // Super-admin equivalent — still trust this; the only time it
        // matters is the pre-migration window for a single hardcoded
        // admin user.
        return true;
    }
    // Explicit allowlists for the legacy levels. Anything not listed
    // returns false (deny).
    $level2Allowed = [
        'screen.dashboard', 'screen.incidents', 'screen.incident_detail',
        'screen.units', 'screen.unit_detail',
        'screen.facilities', 'screen.facility_detail',
        'screen.roster', 'screen.member_detail',
        'screen.teams', 'screen.search', 'screen.reports',
        'widget.map', 'widget.weather', 'widget.incidents',
        'widget.responders', 'widget.facilities', 'widget.stats',
        'widget.log', 'widget.controls', 'widget.comms',
        'action.create_incident', 'action.edit_incident', 'action.close_incident',
        'action.assign_unit', 'action.add_note',
        'action.manage_members', 'action.manage_teams', 'action.manage_schedule',
        'action.change_unit_status', 'action.dispatch_unit',
        'action.send_chat', 'action.send_sms', 'action.send_email',
        'field.view_patient', 'field.view_contact', 'field.view_address',
        'field.view_notes',
    ];
    $level3Allowed = [
        'screen.dashboard', 'screen.incidents', 'screen.incident_detail',
        'screen.units', 'screen.unit_detail',
        'widget.map', 'widget.incidents', 'widget.responders',
        'widget.weather',
    ];
    if ($level === 2) return in_array($permCode, $level2Allowed, true);
    if ($level === 3) return in_array($permCode, $level3Allowed, true);
    $level4Allowed = ['screen.dashboard', 'widget.map', 'widget.weather'];
    return in_array($permCode, $level4Allowed, true);
}
