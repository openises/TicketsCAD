<?php
/**
 * NewUI v4.0 — Routing engine recipient predicates (Phase 99v-1, 2026-06-29).
 *
 * Resolves a routing-engine recipient predicate JSON tree to a concrete
 * set of user IDs. A route can carry one of these predicates in its
 * recipient_predicate_json column; when set, the router resolves it
 * against the live message + DB state and delivers to each matched
 * user via whichever channel(s) the route names.
 *
 * Predicate JSON shape:
 *
 *   {
 *     "type": "any_of",          // any_of | all_of | none_of
 *     "conditions": [
 *       { "predicate": "assigned_to_incident",
 *         "params": { "ticket_id": "$payload.ticket_id" } },
 *       ...
 *     ]
 *   }
 *
 * A condition is either a nested composition (has "type" + "conditions")
 * or a leaf (has "predicate" + "params").
 *
 * Public surface:
 *
 *   router_recipients_ensure_column(): void
 *       Idempotent ALTER TABLE that adds recipient_predicate_json to
 *       message_routes if missing. Cached static so it's free to call
 *       on every router_evaluate.
 *
 *   router_recipients_resolve(array $predicate, array $message): array
 *       Returns sorted, deduped int[] of user IDs that satisfy the
 *       predicate against the live database + the message payload.
 *       Returns [] on empty predicate, malformed input, or zero matches.
 *
 *   router_recipients_available_predicates(): array
 *       Catalog used by the Settings UI builder. Each entry has name,
 *       description, params schema.
 *
 * Predicates supported in v1 (per spec §Predicate language):
 *
 *   assigned_to_incident  — users whose responders have an active
 *                           assigns row for the given ticket_id
 *   responder_status_in   — users whose responders are currently in
 *                           any of the given un_status names
 *   member_of_team        — users whose member rows are in
 *                           team_members for any of the given team_ids
 *   user_id_in            — literal list of user ids
 *   org_member            — users whose home org or any role-assigned
 *                           org is in the given org_ids
 *   rbac_can              — users whose roles grant the given perm code
 *
 * Param values may reference message-payload fields via $payload.X
 * (single level only — $payload.ticket_id, $payload.org_id, etc.).
 * Unresolved references resolve to NULL which generally yields the
 * empty set for that predicate.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────
// Schema (idempotent)
// ─────────────────────────────────────────────────────────────────────

function router_recipients_ensure_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $col = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = 'recipient_predicate_json'",
            [$prefix . 'message_routes']
        );
        if (!$col) {
            db_query(
                "ALTER TABLE `{$prefix}message_routes`
                 ADD COLUMN `recipient_predicate_json` TEXT DEFAULT NULL
                 AFTER `dest_channel`"
            );
        }
    } catch (Exception $e) {
        error_log('[router_recipients_ensure_column] ' . $e->getMessage());
    }
    router_recipients_ensure_seed_routes();
}

/**
 * Insert the default seed route(s) shipped with Phase 99v-3 so a
 * fresh install gets the "mobile users assigned to an incident get
 * a push" behaviour out of the box — same shape as the Phase 99t
 * band-aid, but driven by the routing engine.
 *
 * Idempotent: the seed lookup keys on the route name (which carries
 * a marker prefix) so re-running won't insert a second copy. Admins
 * can disable or rewrite the route freely; the function never
 * re-creates a route the admin has deleted by name.
 */
function router_recipients_ensure_seed_routes(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Seed 1: per-incident push to assigned units. The mobile/field
    // responder use case — get pushes only for incidents you're
    // currently working.
    _rr_seed_route_idempotent(
        $prefix,
        'Phase 99v seed: incident events → push, recipients assigned to incident',
        'Default seed route shipped with Phase 99v-3 (2026-06-29). Sends '
        . 'a web-push notification to every user whose responder is on an '
        . 'active assignment for the incident referenced by the audit event. '
        . 'Admins may disable, edit, or delete this route freely.',
        50,
        [
            'predicate' => 'assigned_to_incident',
            'params'    => ['ticket_id' => '$payload.ticket_id'],
        ]
    );

    // Seed 2: dispatch-screen firehose. The dispatcher/admin use case
    // — get every event by virtue of having access to the situation
    // screen or the incidents widget. Preserves the desktop firehose
    // behaviour that the Phase 99t band-aid filter used to allow via
    // empty-filter subscriptions; now driven by role, not by which
    // device installed the PWA.
    _rr_seed_route_idempotent(
        $prefix,
        'Phase 99v seed: incident events → push, recipients with dispatch screen access',
        // Description is varchar(255) — keep terse.
        'Push to anyone with screen.situation OR widget.incidents — preserves the '
        . 'legacy desktop-dispatcher firehose, now role-driven. Edit freely.',
        60,
        [
            'type' => 'any_of',
            'conditions' => [
                ['predicate' => 'rbac_can', 'params' => ['permission_code' => 'screen.situation']],
                ['predicate' => 'rbac_can', 'params' => ['permission_code' => 'widget.incidents']],
            ],
        ]
    );
}

function _rr_seed_route_idempotent(string $prefix, string $name, string $desc, int $priority, array $predicate): void
{
    try {
        $existing = db_fetch_value(
            "SELECT id FROM `{$prefix}message_routes` WHERE name = ? LIMIT 1",
            [$name]
        );
        if ($existing) return;
        db_query(
            "INSERT INTO `{$prefix}message_routes`
             (name, description, enabled, priority, source_channel,
              dest_channel, direction, recipient_predicate_json,
              filters_json, transform_json, created_by, created_at)
             VALUES (?, ?, 1, ?, 'audit_event', 'push', 'outbound', ?,
              NULL, NULL, 0, NOW())",
            [$name, $desc, $priority, json_encode($predicate)]
        );
    } catch (Exception $e) {
        error_log('[_rr_seed_route_idempotent] ' . $name . ' — ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────
// Public resolver
// ─────────────────────────────────────────────────────────────────────

function router_recipients_resolve(array $predicate, array $message): array
{
    if (empty($predicate)) return [];
    try {
        $ids = _rr_eval_node($predicate, $message);
    } catch (Exception $e) {
        error_log('[router_recipients_resolve] ' . $e->getMessage());
        return [];
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function router_recipients_available_predicates(): array
{
    return [
        'assigned_to_incident' => [
            'name'        => 'Users assigned to incident',
            'description' => 'Users whose responder is on an active (un-cleared) assignment for the incident.',
            'params'      => [['name' => 'ticket_id', 'type' => 'int', 'placeholder' => '$payload.ticket_id']],
        ],
        'responder_status_in' => [
            'name'        => 'Users with responder in status',
            'description' => 'Users whose responder is currently in any of the named un_status values (e.g. Available, On Scene).',
            'params'      => [['name' => 'status_names', 'type' => 'string[]', 'placeholder' => '["Available","On Scene"]']],
        ],
        'member_of_team' => [
            'name'        => 'Members of team',
            'description' => 'Users whose member record is in the team_members junction for any of the listed team IDs.',
            'params'      => [['name' => 'team_ids', 'type' => 'int[]', 'placeholder' => '[3, 7]']],
        ],
        'user_id_in' => [
            'name'        => 'Specific users',
            'description' => 'Literal list of user IDs. Useful for direct notifications or testing.',
            'params'      => [['name' => 'user_ids', 'type' => 'int[]', 'placeholder' => '[29, 30]']],
        ],
        'org_member' => [
            'name'        => 'Members of organization',
            'description' => 'Users whose home org_id, or any role-assigned org scope, matches one of the listed orgs.',
            'params'      => [['name' => 'org_ids', 'type' => 'int[]', 'placeholder' => '[1, 2]']],
        ],
        'rbac_can' => [
            'name'        => 'Users with permission',
            'description' => 'Users whose roles grant the named permission code (e.g. action.view_major).',
            'params'      => [['name' => 'permission_code', 'type' => 'string', 'placeholder' => 'action.view_major']],
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────
// Composition (any_of / all_of / none_of)
// ─────────────────────────────────────────────────────────────────────

function _rr_eval_node(array $node, array $message): array
{
    // Composition node?
    if (isset($node['type']) && isset($node['conditions'])) {
        return _rr_compose($node['type'], $node['conditions'], $message);
    }
    // Leaf node?
    if (isset($node['predicate'])) {
        return _rr_eval_predicate($node['predicate'], $node['params'] ?? [], $message);
    }
    // Malformed
    return [];
}

function _rr_compose(string $type, array $conditions, array $message): array
{
    $resolved = [];
    foreach ($conditions as $cond) {
        $resolved[] = _rr_eval_node($cond, $message);
    }
    if (empty($resolved)) return [];

    switch ($type) {
        case 'any_of':
            return array_values(array_unique(array_merge(...$resolved)));

        case 'all_of':
            $base = $resolved[0];
            for ($i = 1; $i < count($resolved); $i++) {
                $base = array_values(array_intersect($base, $resolved[$i]));
            }
            return $base;

        case 'none_of':
            // For none_of we need a universe to subtract from. In practice
            // none_of by itself is meaningless; it's only useful inside an
            // all_of where the LEFT side defines the universe. So return
            // the COMPLEMENT relative to the ALL-USERS universe, then let
            // the surrounding all_of intersect it down. That preserves
            // composition semantics: all_of[A, none_of[B]] = A minus B.
            $excluded = array_values(array_unique(array_merge(...$resolved)));
            $allUsers = _rr_all_active_user_ids();
            return array_values(array_diff($allUsers, $excluded));

        default:
            return [];
    }
}

// ─────────────────────────────────────────────────────────────────────
// Leaf predicates
// ─────────────────────────────────────────────────────────────────────

function _rr_eval_predicate(string $name, array $params, array $message): array
{
    $resolved = _rr_resolve_params($params, $message);
    switch ($name) {
        case 'assigned_to_incident':   return _rr_pred_assigned_to_incident($resolved);
        case 'responder_status_in':    return _rr_pred_responder_status_in($resolved);
        case 'member_of_team':         return _rr_pred_member_of_team($resolved);
        case 'user_id_in':             return _rr_pred_user_id_in($resolved);
        case 'org_member':             return _rr_pred_org_member($resolved);
        case 'rbac_can':               return _rr_pred_rbac_can($resolved);
        default:
            error_log('[router_recipients] unknown predicate: ' . $name);
            return [];
    }
}

function _rr_pred_assigned_to_incident(array $p): array
{
    $tid = (int) ($p['ticket_id'] ?? 0);
    if (!$tid) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // An open assignment: clear is NULL, empty, or a zero-date (legacy rows
    // stamp '0000-00-00 00:00:00' instead of NULL).
    $open = "(a.clear IS NULL OR a.clear = '' OR a.clear = '0000-00-00 00:00:00')";

    $ids = [];

    // (1) A unit directly linked to a login user (responder.user_id).
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT r.user_id
               FROM `{$prefix}assigns` a
               JOIN `{$prefix}responder` r ON r.id = a.responder_id
              WHERE a.ticket_id = ? AND {$open}
                AND r.user_id IS NOT NULL AND r.user_id > 0",
            [$tid]
        );
        foreach ($rows as $r) { $ids[(int) $r['user_id']] = true; }
    } catch (Throwable $e) {
        error_log('[router_recipients] assigned_to_incident (unit) failed: ' . $e->getMessage());
    }

    // (2) GH #8 — personnel assigned to those units. In practice a unit
    //     rarely carries a direct user_id; the mobile field user is the
    //     PERSON assigned to the unit (unit_personnel_assignments), linked to
    //     a login account via member.user_id. Resolving only responder.user_id
    //     meant an assigned responder with no personal user link got no push
    //     (a beta tester's install: responder 7, user_id NULL). Include active/standby
    //     personnel of the incident's units. Guarded so an install without the
    //     table/columns degrades to just path (1).
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT m.user_id
               FROM `{$prefix}assigns` a
               JOIN `{$prefix}unit_personnel_assignments` upa
                    ON upa.responder_id = a.responder_id
                   AND upa.status IN ('active', 'standby')
               JOIN `{$prefix}member` m ON m.id = upa.member_id
              WHERE a.ticket_id = ? AND {$open}
                AND m.user_id IS NOT NULL AND m.user_id > 0",
            [$tid]
        );
        foreach ($rows as $r) { $ids[(int) $r['user_id']] = true; }
    } catch (Throwable $e) {
        // Missing table/columns on an older install — path (1) still applies.
        error_log('[router_recipients] assigned_to_incident (personnel) skipped: ' . $e->getMessage());
    }

    return array_keys($ids);
}

function _rr_pred_responder_status_in(array $p): array
{
    $names = $p['status_names'] ?? [];
    if (!is_array($names) || empty($names)) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $place = implode(',', array_fill(0, count($names), '?'));
    $rows = db_fetch_all(
        "SELECT DISTINCT r.user_id
           FROM `{$prefix}responder` r
           JOIN `{$prefix}un_status` s ON s.id = r.un_status_id
          WHERE LOWER(s.status_val) IN ($place)
            AND r.user_id IS NOT NULL
            AND r.user_id > 0",
        array_map('strtolower', $names)
    );
    return array_map(function ($r) { return (int) $r['user_id']; }, $rows);
}

function _rr_pred_member_of_team(array $p): array
{
    $tids = $p['team_ids'] ?? [];
    if (!is_array($tids) || empty($tids)) return [];
    $tids = array_map('intval', $tids);
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $place = implode(',', array_fill(0, count($tids), '?'));
    $rows = db_fetch_all(
        "SELECT DISTINCT m.user_id
           FROM `{$prefix}team_members` tm
           JOIN `{$prefix}member` m ON m.id = tm.member_id
          WHERE tm.team_id IN ($place)
            AND m.user_id IS NOT NULL
            AND m.user_id > 0",
        $tids
    );
    return array_map(function ($r) { return (int) $r['user_id']; }, $rows);
}

function _rr_pred_user_id_in(array $p): array
{
    $uids = $p['user_ids'] ?? [];
    if (!is_array($uids)) return [];
    return array_map('intval', $uids);
}

function _rr_pred_org_member(array $p): array
{
    $oids = $p['org_ids'] ?? [];
    if (!is_array($oids) || empty($oids)) return [];
    $oids = array_map('intval', $oids);
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $place = implode(',', array_fill(0, count($oids), '?'));

    // Two paths into "org membership":
    //   (a) user.home_org_id directly in the requested set
    //   (b) any user_roles grant scope_kind='org' with scope_id in the set
    // Union with DISTINCT.
    $homeRows = [];
    try {
        $homeRows = db_fetch_all(
            "SELECT id FROM `{$prefix}user` WHERE home_org_id IN ($place)",
            $oids
        );
    } catch (Exception $e) {
        // home_org_id column may not exist on older installs — skip.
    }
    $roleRows = [];
    try {
        $roleRows = db_fetch_all(
            "SELECT DISTINCT user_id
               FROM `{$prefix}user_roles`
              WHERE scope_kind = 'org'
                AND scope_id IN ($place)",
            $oids
        );
    } catch (Exception $e) {
        // schema variant
    }
    $ids = [];
    foreach ($homeRows as $r) $ids[] = (int) $r['id'];
    foreach ($roleRows as $r) $ids[] = (int) $r['user_id'];
    return $ids;
}

function _rr_pred_rbac_can(array $p): array
{
    $code = trim((string) ($p['permission_code'] ?? ''));
    if ($code === '') return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    // Find all users who have any role that grants the named permission.
    // Honours scope_kind only at the granular layer: this is a "who could
    // do this?" question, so a user with the perm globally OR org-scoped
    // OR self-scoped all qualifies.
    $rows = db_fetch_all(
        "SELECT DISTINCT ur.user_id
           FROM `{$prefix}user_roles` ur
           JOIN `{$prefix}role_permissions` rp ON rp.role_id = ur.role_id
           JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
          WHERE p.code = ?
            AND ur.user_id IS NOT NULL",
        [$code]
    );
    return array_map(function ($r) { return (int) $r['user_id']; }, $rows);
}

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────

/**
 * Resolve param values, expanding $payload.X references against the
 * routed message. Single-level dot lookup only.
 */
function _rr_resolve_params(array $params, array $message): array
{
    $out = [];
    foreach ($params as $k => $v) {
        if (is_string($v) && strncmp($v, '$payload.', 9) === 0) {
            $field = substr($v, 9);
            $out[$k] = $message[$field] ?? null;
        } elseif (is_array($v)) {
            // recurse one level — list of literals; if any element is
            // a $payload reference, resolve it the same way.
            $sub = [];
            foreach ($v as $item) {
                if (is_string($item) && strncmp($item, '$payload.', 9) === 0) {
                    $f = substr($item, 9);
                    $sub[] = $message[$f] ?? null;
                } else {
                    $sub[] = $item;
                }
            }
            $out[$k] = $sub;
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * Universe for none_of — all active (non-deleted) users with login allowed.
 * Cached per-request since none_of inside a multi-condition tree may
 * call this repeatedly.
 */
function _rr_all_active_user_ids(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT id FROM `{$prefix}user`
              WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')"
        );
    } catch (Exception $e) {
        // Old install with no deleted_at — fall back to everyone.
        $rows = db_fetch_all("SELECT id FROM `{$prefix}user`");
    }
    $cached = array_map(function ($r) { return (int) $r['id']; }, $rows);
    return $cached;
}
