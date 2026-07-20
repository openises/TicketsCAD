<?php
/**
 * Phase 99j-1 (2026-06-29) — Org scoping helpers.
 *
 * Computes which organization IDs the current session can see, and
 * builds SQL fragments to enforce that filter on list queries.
 *
 * Origin: Billy Irwin's 2026-06-29 beta email. Full design lives in
 * specs/phase-99j-org-scoping/spec.md — read that before changing
 * the visibility rule.
 *
 * Public API:
 *   org_visible_ids(?int $userId = null): ?array
 *   org_descendant_ids(int $orgId): array
 *   org_user_home_id(int $userId): int
 *   org_query_filter(string $column, ?int $userId = null): array
 *
 * NULL return from org_visible_ids() / empty filter from
 * org_query_filter() means "no restriction" (Super Admin scope).
 * Callers should treat NULL specifically — not as "empty list".
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────
// Cache (per-request, per-user). Cleared if session changes.
// ─────────────────────────────────────────────────────────────────────
$GLOBALS['_org_scope_cache'] = [];

function _org_scope_cache_get(int $userId, string $key) {
    return $GLOBALS['_org_scope_cache'][$userId][$key] ?? null;
}
function _org_scope_cache_set(int $userId, string $key, $value): void {
    if (!isset($GLOBALS['_org_scope_cache'][$userId])) {
        $GLOBALS['_org_scope_cache'][$userId] = [];
    }
    $GLOBALS['_org_scope_cache'][$userId][$key] = $value;
}

// ─────────────────────────────────────────────────────────────────────
// org_strict_isolation_enabled  (F-014, security-audit-2026-04)
// ─────────────────────────────────────────────────────────────────────

/**
 * F-014 (security-audit-2026-04): the `org_strict_isolation` setting.
 *
 * Every visibility helper below carries a legacy fall-through — rows
 * with org_id NULL (or members with zero junction rows) stay visible
 * to everyone so pre-multi-tenant data doesn't vanish mid-transition.
 * That fall-through is also a cross-tenant leak once an install has
 * backfilled org_id everywhere.
 *
 * Flipping the `org_strict_isolation` setting to 1 removes the
 * fall-through: NULL-org rows become visible ONLY to Super Admin.
 * Default (absent / 0) preserves legacy behavior.
 *
 * Originally implemented per-endpoint in api/incident-list.php; the
 * Phase 99j refactor (2026-06-29) centralized org scoping here but
 * dropped the toggle — restored 2026-07-07 so the audit follow-up
 * ("after org_id backfill, flip org_strict_isolation = 1") works again.
 */
function org_strict_isolation_enabled(): bool
{
    static $strict = null;
    if ($strict !== null) return $strict;

    $val = null;
    if (function_exists('get_variable')) {
        $v = get_variable('org_strict_isolation');
        if ($v !== false) $val = $v;
    }
    if ($val === null) {
        // Endpoint included org-scope.php without inc/functions.php —
        // read the settings table directly (column is `name`, not `key`).
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $val = db_fetch_value(
                "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
                ['org_strict_isolation']
            );
        } catch (Throwable $e) {
            error_log('[org_strict_isolation_enabled] settings read failed: ' . $e->getMessage());
            $val = null;
        }
    }
    $strict = ((int) ($val ?? 0)) === 1;
    return $strict;
}

// ─────────────────────────────────────────────────────────────────────
// org_user_home_id
// ─────────────────────────────────────────────────────────────────────

/**
 * Returns the home org ID for $userId. Falls back to organization 1
 * if user.home_org_id is NULL or the user record is missing.
 */
function org_user_home_id(int $userId): int {
    if ($userId <= 0) return 1;
    $cached = _org_scope_cache_get($userId, 'home_org_id');
    if ($cached !== null) return (int) $cached;

    try {
        $homeOrg = db_fetch_value(
            "SELECT `home_org_id` FROM " . db_table('user') . " WHERE `id` = ? LIMIT 1",
            [$userId]
        );
        $homeOrg = ($homeOrg !== null && (int) $homeOrg > 0) ? (int) $homeOrg : 1;
    } catch (Throwable $e) {
        // Schema pre-99j — home_org_id column missing. Fall back to 1.
        $homeOrg = 1;
    }
    _org_scope_cache_set($userId, 'home_org_id', $homeOrg);
    return $homeOrg;
}

// ─────────────────────────────────────────────────────────────────────
// org_descendant_ids
// ─────────────────────────────────────────────────────────────────────

/**
 * Returns the recursive descendant tree of $orgId, INCLUDING $orgId.
 *
 * Uses iterative BFS (one query per depth level) to avoid the need
 * for recursive CTEs — MariaDB 10.2+ supports them but we don't want
 * to introduce that dependency for installs still on older MariaDB.
 *
 * Depth is capped at 10 levels to prevent infinite loops if the
 * parent_org_id chain has a cycle (which the schema doesn't prevent
 * — only the application layer protects against it).
 */
function org_descendant_ids(int $orgId): array {
    if ($orgId <= 0) return [];
    $cached = _org_scope_cache_get(0, 'descendants_' . $orgId);
    if ($cached !== null) return $cached;

    $ids = [$orgId];
    $frontier = [$orgId];
    $depth = 0;

    try {
        while (!empty($frontier) && $depth < 10) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $children = db_fetch_all(
                "SELECT `id` FROM " . db_table('organizations') . "
                  WHERE `parent_org_id` IN ($placeholders)",
                $frontier
            );
            $frontier = [];
            foreach ($children as $row) {
                $cid = (int) $row['id'];
                if (!in_array($cid, $ids, true)) {
                    $ids[] = $cid;
                    $frontier[] = $cid;
                }
            }
            $depth++;
        }
    } catch (Throwable $e) {
        // Pre-99j schema (no parent_org_id) — just return the root.
        $ids = [$orgId];
    }

    _org_scope_cache_set(0, 'descendants_' . $orgId, $ids);
    return $ids;
}

// ─────────────────────────────────────────────────────────────────────
// org_visible_ids
// ─────────────────────────────────────────────────────────────────────

/**
 * Returns the array of org IDs the current session can see. NULL
 * means "no restriction" (Super Admin or fallback when scope
 * cannot be determined).
 *
 * Rule (see spec.md):
 *   - Super Admin (any role with is_super=1)           -> NULL
 *   - Otherwise, union of:
 *       - user_roles rows with scope_kind='global'     -> NULL
 *       - user_roles rows with org_id IS NOT NULL      -> [org_id + descendants...]
 *       - fallback if no role info                     -> [home_org_id]
 */
function org_visible_ids(?int $userId = null): ?array {
    if ($userId === null) {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
    }
    if ($userId <= 0) return [];   // unauthenticated — see nothing

    $cached = _org_scope_cache_get($userId, 'visible_ids');
    if ($cached !== null) {
        // Cached NULL is stored as a string sentinel because the
        // cache uses === null to mean "not set".
        return $cached === '__NULL__' ? null : $cached;
    }

    $result = _org_compute_visible_ids($userId);
    _org_scope_cache_set($userId, 'visible_ids', $result === null ? '__NULL__' : $result);
    return $result;
}

/**
 * Internal: actually computes the visible-org-id set. Separate
 * function so the caching layer can wrap it cleanly.
 */
function _org_compute_visible_ids(int $userId): ?array {
    try {
        // 1. Super Admin short-circuit.
        $isSuper = db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('user_roles') . " ur
             JOIN " . db_table('roles') . " r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND r.is_super = 1
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            [$userId]
        );
        if ((int) $isSuper > 0) return null;

        // 2. Walk active role grants for this user.
        $grants = db_fetch_all(
            "SELECT ur.org_id, ur.scope_kind, ur.scope_id
               FROM " . db_table('user_roles') . " ur
              WHERE ur.user_id = ?
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            [$userId]
        );

        $orgIds = [];
        $hasGlobal = false;
        foreach ($grants as $g) {
            $sk = (string) ($g['scope_kind'] ?? '');
            $oid = $g['org_id'] !== null ? (int) $g['org_id'] : null;
            if ($sk === 'global') {
                $hasGlobal = true;
                break;
            }
            if ($oid !== null && $oid > 0) {
                foreach (org_descendant_ids($oid) as $did) {
                    if (!in_array($did, $orgIds, true)) $orgIds[] = $did;
                }
            }
        }

        if ($hasGlobal) return null;

        // 3. Fallback when no role info gives us an org — use home org.
        if (empty($orgIds)) {
            $home = org_user_home_id($userId);
            $orgIds = [$home];
        }

        return $orgIds;
    } catch (Throwable $e) {
        // Schema not ready (pre-99j). Safest fallback: no filter,
        // so the application behaves as it did before this code
        // landed. The endpoint sweep (99j-4 onward) will tighten.
        return null;
    }
}

// ─────────────────────────────────────────────────────────────────────
// org_query_filter
// ─────────────────────────────────────────────────────────────────────

/**
 * Builds a SQL fragment + parameter list for use in list queries.
 *
 *   [$frag, $vars] = org_query_filter('t.org_id');
 *   $sql = "SELECT * FROM ticket t WHERE 1=1" . $frag . " ORDER BY ...";
 *   $rows = db_fetch_all($sql, array_merge([...], $vars));
 *
 * The fragment begins with " AND " when non-empty so concatenation
 * after "WHERE 1=1" is safe. Returns ['', []] for Super Admin so
 * the query is unfiltered.
 *
 * IMPORTANT: $column must be controlled by the caller, NEVER from
 * user input — it's inlined directly. Pass a qualified column name
 * like 't.org_id' or '`org_id`'.
 */
function org_query_filter(string $column, ?int $userId = null): array {
    $visible = org_visible_ids($userId);
    if ($visible === null) return ['', []];       // Super Admin / no filter
    if (empty($visible))   return [' AND 0=1', []]; // unauthenticated — empty set
    $placeholders = implode(',', array_fill(0, count($visible), '?'));
    if (org_strict_isolation_enabled()) {
        // F-014 strict mode: no legacy NULL fall-through — NULL-org rows
        // are visible only to Super Admin (filter skipped above).
        return [" AND $column IN ($placeholders)", array_values($visible)];
    }
    return [" AND ($column IN ($placeholders) OR $column IS NULL)", array_values($visible)];
}

/**
 * Phase 99j-4 (Billy beta 2026-06-29) — visibility check for a
 * single ticket. Returns true if the current session can see (and
 * therefore mutate) the ticket given its org_id, false otherwise.
 *
 * Use this in detail / update / assign endpoints BEFORE doing any
 * work, to prevent an Org Admin from URL-hopping to a ticket that
 * belongs to a different tenant. Super Admin (org_visible_ids
 * returns NULL) always wins; tickets with NULL org_id stay visible
 * for legacy-data compatibility (same fallback as the list filter).
 */
function org_can_see_ticket(int $ticketId, ?int $userId = null): bool
{
    $visible = org_visible_ids($userId);
    if ($visible === null) return true;            // Super Admin
    if (empty($visible))   return false;           // unauth / no orgs
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $orgId = db_fetch_value(
            "SELECT `org_id` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1",
            [$ticketId]
        );
    } catch (Exception $e) {
        // table missing / column missing → fall open (legacy installs)
        return true;
    }
    if ($orgId === null || $orgId === '') {
        // Legacy NULL row — visible unless F-014 strict isolation is on.
        return !org_strict_isolation_enabled();
    }
    return in_array((int) $orgId, array_map('intval', $visible), true);
}

/**
 * Phase 99j-5 (Billy beta 2026-06-29) — junction-aware filter for
 * the personnel (`member`) table. Members live in a many-to-many
 * relationship with organizations via the `member_organizations`
 * junction (member_id, org_id). A member is visible to this session
 * if ANY of their junction rows points to one of the session's
 * visible org IDs.
 *
 * Usage:
 *   [$frag, $vars] = org_member_query_filter('m.id');
 *   $sql = "SELECT ... FROM member m WHERE 1=1 {$frag} ...";
 *
 * Returns ['', []] for Super Admin (no filter applied). The
 * fragment includes a "NOT EXISTS" branch so legacy members with
 * zero junction rows stay visible during the transition — same
 * convention as the ticket fallback (OR org_id IS NULL).
 *
 * IMPORTANT: $memberIdRef must be controlled by the caller, NEVER
 * from user input — it's inlined into the SQL.
 */
function org_member_query_filter(string $memberIdRef = 'm.id', ?int $userId = null): array
{
    $visible = org_visible_ids($userId);
    if ($visible === null) return ['', []];        // Super Admin
    if (empty($visible))   return [' AND 0=1', []];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $placeholders = implode(',', array_fill(0, count($visible), '?'));
    if (org_strict_isolation_enabled()) {
        // F-014 strict mode: members with zero junction rows are visible
        // only to Super Admin (no legacy NOT EXISTS fall-through).
        $frag = " AND EXISTS (SELECT 1 FROM `{$prefix}member_organizations` mo "
              . "WHERE mo.member_id = {$memberIdRef} AND mo.org_id IN ({$placeholders}))";
        return [$frag, array_values($visible)];
    }
    $frag = " AND ("
          . "EXISTS (SELECT 1 FROM `{$prefix}member_organizations` mo "
          . "WHERE mo.member_id = {$memberIdRef} AND mo.org_id IN ({$placeholders}))"
          . " OR NOT EXISTS (SELECT 1 FROM `{$prefix}member_organizations` mo2 "
          . "WHERE mo2.member_id = {$memberIdRef})"
          . ")";
    return [$frag, array_values($visible)];
}

/**
 * Visibility gate for a single member. True if any of the member's
 * junction rows is in the session's visible-org set, OR the member
 * has no junction rows (legacy fallback). Super Admin always wins.
 */
/**
 * Phase 99j-6 (Billy beta 2026-06-29) — idempotently ensure the
 * given table has an `org_id INT NULL` column for org scoping.
 *
 * For tables like responder, facilities, teams, newui_vehicles,
 * newui_equipment which were never multi-tenant aware. Existing
 * rows stay org_id=NULL; the legacy-fallback in
 * org_query_filter('… IS NULL') keeps them visible until an admin
 * explicitly assigns an org via the (future) settings UI.
 *
 * Result cached in a static so it only runs the info-schema check
 * once per request per table.
 */
function ensure_org_id_column(string $table): void
{
    static $checked = [];
    if (isset($checked[$table])) return;
    $checked[$table] = true;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $fullTable = $prefix . $table;
    try {
        $existing = db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
            [$fullTable]
        );
        if ($existing) return;
        db_query("ALTER TABLE `{$fullTable}` ADD COLUMN `org_id` INT NULL DEFAULT NULL");
        error_log("[ensure_org_id_column] added org_id column to {$fullTable}");
    } catch (Exception $e) {
        error_log("[ensure_org_id_column] {$fullTable} check/add failed: " . $e->getMessage());
    }
}

/**
 * Generic visibility gate for a row in a table with an `org_id`
 * column (responder, facilities, teams, vehicles, equipment).
 *
 * Returns true for Super Admin, or when the row's org_id is in
 * the session's visible set, or when org_id is NULL (legacy
 * fallback). Falls open if the table or column is missing.
 *
 * Use for direct mutator gates (incident_create's org-default
 * already uses org_user_home_id; for tickets use the more
 * specific org_can_see_ticket).
 */
function org_can_see_row(string $table, int $rowId, ?int $userId = null): bool
{
    $visible = org_visible_ids($userId);
    if ($visible === null) return true;
    if (empty($visible))   return false;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $orgId = db_fetch_value(
            "SELECT `org_id` FROM `{$prefix}{$table}` WHERE `id` = ? LIMIT 1",
            [$rowId]
        );
    } catch (Exception $e) {
        return true;                                // table/column missing
    }
    if ($orgId === null || $orgId === '') {
        // Legacy NULL row — visible unless F-014 strict isolation is on.
        return !org_strict_isolation_enabled();
    }
    return in_array((int) $orgId, array_map('intval', $visible), true);
}

function org_can_see_member(int $memberId, ?int $userId = null): bool
{
    $visible = org_visible_ids($userId);
    if ($visible === null) return true;            // Super Admin
    if (empty($visible))   return false;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $orgRows = db_fetch_all(
            "SELECT `org_id` FROM `{$prefix}member_organizations` WHERE `member_id` = ?",
            [$memberId]
        );
    } catch (Exception $e) {
        return true;                               // junction missing → fall open
    }
    if (empty($orgRows)) {
        // Legacy member with no org links — visible unless F-014 strict
        // isolation is on.
        return !org_strict_isolation_enabled();
    }
    $visibleInt = array_map('intval', $visible);
    foreach ($orgRows as $r) {
        if (in_array((int) $r['org_id'], $visibleInt, true)) return true;
    }
    return false;
}
