<?php
/**
 * GH#148 (Phase 155) -- Towing / roadside vendor dispatch: the core library.
 *
 * specs/phase-155-community-backlog/148-towing-rotation-list.md
 *
 * A "vendor" is an outside company the agency CALLS on someone else's behalf (a tow truck, a locksmith). It is
 * deliberately NOT a responder: modelling it as a unit would put it on the unit board, in PAR roll calls and in
 * the agency's own response-time statistics (spec section 3.1).
 *
 * THE ONE IDEA THAT EXPLAINS THE DESIGN: "who is next" is DERIVED from an append-only ledger
 * (vendor_dispatch_ledger) of every call placed and what came of it. It is never a stored "last dispatched"
 * timestamp that can drift. That is what makes the rotation provable after the fact, lets a decline or a
 * no-answer be recorded, and lets a correction be a new row (a `voided` row naming the row it voids) instead
 * of an edit. Nothing in this file (or anywhere in the application) UPDATEs or DELETEs a ledger row;
 * tests/test_vendor_ledger_immutability.php enforces that with a tokenizer.
 *
 * Layout of this file:
 *   1. schema/settings/actor helpers
 *   2. PURE functions (no database): ordering, effective events, derived status -- unit-tested directly
 *   3. the rotation queue (database wrapper around the pure ordering)
 *   4. the writers: create / update details / offer / outcome / status / note / void
 *   5. after-commit side effects (incident note, audit trail, SSE) -- never inside a lock, never fatal
 *   6. read helpers (dispatch view with timeline, history, CSV)
 *
 * Writers return ['ok' => true, ...] or vendor_fail()'s ['ok' => false, 'http' => N, 'code' => '...', 'message' => '...'].
 * They never check RBAC (the endpoints do, api/vendor-dispatch.php); they DO enforce every business rule, so a
 * caller that forgot a check cannot corrupt the rotation.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/incident-number.php';

// ═══════════════════════════════════════════════════════════════════════════
// 1. Schema / settings / actor helpers
// ═══════════════════════════════════════════════════════════════════════════

/** Result helpers: one shape for every writer. */
function vendor_fail(int $http, string $code, string $message, array $extra = []): array
{
    return array_merge(['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message], $extra);
}

/** The six tables this feature owns. */
function vendor_table_names(): array
{
    return ['vendor_service_types', 'vendor_providers', 'vendor_rotation_lists',
            'vendor_rotation_members', 'vendor_dispatches', 'vendor_dispatch_ledger'];
}

/**
 * True once all six tables exist. Probed from information_schema once per process (a drop-and-recreate during
 * a request is not a case worth handling). With the schema missing the button, card and script are simply not
 * rendered (spec 6.11) and the API answers 409 vendor_schema_missing.
 */
function vendor_schema_ready(bool $refresh = false): bool
{
    static $ready = null;
    if ($ready !== null && !$refresh) return $ready;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $names = array_map(function ($n) use ($prefix) { return $prefix . $n; }, vendor_table_names());
        $ph = implode(',', array_fill(0, count($names), '?'));
        $have = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)",
            $names);
        $ready = ($have === count($names));
    } catch (Throwable $e) {
        error_log('[vendor_schema_ready] ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * The settings this feature owns: name => [default, allowed values]. Stored in the `settings` table (the store
 * get_variable() reads), WRITTEN only by inc/vendor-admin-write.php's vendor_settings_save() (enum-validated),
 * READ only through vendor_setting(), which CLAMPS an unknown stored value to the default -- the generic settings
 * endpoint (api/config-admin.php) will happily store garbage, so a reader must never trust the raw value.
 */
function vendor_setting_defs(): array
{
    return [
        'vendor_dispatch_enabled'         => ['0',           ['0', '1']],
        'vendor_rotation_mode'            => ['round_robin', ['round_robin', 'strict_order', 'manual']],
        'vendor_advance_rule'             => ['any_offer',   ['any_offer', 'accepted_only']],
        'vendor_allow_override'           => ['1',           ['0', '1']],
        'vendor_override_requires_reason' => ['1',           ['0', '1']],
        // Owned by the click-to-dial slice (GH#108/S10); this feature only READS it. See vendor_settings_save().
        'phone_click_to_call'             => ['off',         ['off', 'widget', 'tel_link']],
    ];
}

function vendor_setting(string $name): string
{
    $defs = vendor_setting_defs();
    if (!isset($defs[$name])) return '';
    $raw = function_exists('get_variable') ? get_variable($name) : false;
    if ($raw === false || $raw === null) return $defs[$name][0];
    $raw = (string) $raw;
    return in_array($raw, $defs[$name][1], true) ? $raw : $defs[$name][0];
}

/** The feature is on only when an administrator turned it on AND the schema exists. */
function vendor_enabled(): bool
{
    return vendor_setting('vendor_dispatch_enabled') === '1' && vendor_schema_ready();
}

/** Ledger event types (a code-side whitelist, not an ENUM, so a new type needs no ALTER). */
function vendor_event_types(): array
{
    return ['offered', 'accepted', 'declined', 'no_answer', 'unavailable', 'withdrew', 'eta_update', 'on_scene',
            'completed', 'cancelled', 'goa', 'note', 'voided', 'joined_list', 'moved_to_end', 'removed_from_list', 'order_changed'];
}

/** The four ways an offer can be classified (always decided by the SERVER). */
function vendor_selection_methods(): array
{
    return ['rotation', 'override', 'owner_request', 'unlisted'];
}

function vendor_terminal_statuses(): array
{
    return ['completed', 'cancelled', 'goa'];
}

/** Trim, drop control characters, cap the length; null when empty. */
function vendor_clean_str($v, int $max): ?string
{
    if ($v === null || is_array($v) || is_object($v)) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v);
    if ($s === null) $s = '';
    $s = trim($s);
    if ($s === '') return null;
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/** A phone number: keep dialable characters only, at least three digits. */
function vendor_clean_phone($v): ?string
{
    $s = vendor_clean_str($v, 64);
    if ($s === null) return null;
    $s = preg_replace('/[^0-9+\-().#*xX ,]/', '', $s);
    $s = trim((string) $s);
    if (preg_match_all('/\d/', $s) < 3) return null;
    return substr($s, 0, 32);
}

/** The audit/ledger actor. $_SESSION in a request; tests pass their own array. */
function vendor_actor_from_session(): array
{
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    $name = (string) ($_SESSION['user'] ?? $_SESSION['username'] ?? '');
    $ip = '';
    if (!function_exists('client_ip') && is_file(__DIR__ . '/client-ip.php')) require_once __DIR__ . '/client-ip.php';
    $ip = function_exists('client_ip') ? client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return ['user_id' => $uid > 0 ? $uid : null, 'name' => $name !== '' ? $name : 'system', 'ip' => $ip, 'org_id' => vendor_default_org_id($uid)];
}

/** Normalise whatever an actor array carries so every writer can rely on the keys. */
function vendor_actor_norm(array $a): array
{
    return [
        'user_id' => isset($a['user_id']) && (int) $a['user_id'] > 0 ? (int) $a['user_id'] : null,
        'name'    => vendor_clean_str($a['name'] ?? '', 64) ?? 'system',
        'ip'      => vendor_clean_str($a['ip'] ?? '', 45),
        'org_id'  => isset($a['org_id']) && (int) $a['org_id'] > 0 ? (int) $a['org_id'] : null,
    ];
}

// ── org scoping ───────────────────────────────────────────────────────────

/** Needs inc/org-scope.php; loaded lazily so this file stays usable from a bare CLI test. */
function vendor_load_org_scope(): void
{
    if (!function_exists('org_visible_ids') && is_file(__DIR__ . '/org-scope.php')) {
        require_once __DIR__ . '/org-scope.php';
    }
}

/** The caller's own organization: the session's active org, else the user's home org (incident_create_internal()'s rule). */
function vendor_default_org_id(?int $userId = null): ?int
{
    vendor_load_org_scope();
    $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    $active = isset($_SESSION['active_org_id']) ? (int) $_SESSION['active_org_id'] : 0;
    if ($active > 0) return $active;
    if ($uid > 0 && function_exists('org_user_home_id')) {
        $home = org_user_home_id($uid);
        return $home > 0 ? $home : null;
    }
    return null;
}

/** True for a Super Admin / global grant (org_visible_ids() returns null = unrestricted). */
function vendor_org_unrestricted(?int $userId = null): bool
{
    vendor_load_org_scope();
    if (!function_exists('org_visible_ids')) return true;
    return org_visible_ids($userId) === null;
}

/**
 * [fragment, vars] for list queries, written exactly like org_query_filter() (fragment starts with " AND ").
 * Reuses it so strict-isolation mode (NULL-org rows are Super-Admin-only) behaves identically here.
 */
function vendor_org_frag(string $column, ?int $userId = null): array
{
    vendor_load_org_scope();
    if (!function_exists('org_query_filter')) return ['', []];
    return org_query_filter($column, $userId);
}

/** Can the caller SEE a row with this org_id? (NULL-org rows are visible unless strict isolation is on.) */
function vendor_org_visible(?int $orgId, ?int $userId = null): bool
{
    vendor_load_org_scope();
    if (!function_exists('org_visible_ids')) return true;
    $vis = org_visible_ids($userId);
    if ($vis === null) return true;
    if ($orgId === null || $orgId <= 0) return function_exists('org_strict_isolation_enabled') ? !org_strict_isolation_enabled() : true;
    return in_array($orgId, array_map('intval', $vis), true);
}

/**
 * Can the caller MODIFY a row with this org_id? A row visible to "all agencies" (org_id NULL) is shared data and
 * only a Super Admin may change it; everyone else may change rows of an organization they can see.
 */
function vendor_org_writable(?int $orgId, ?int $userId = null): bool
{
    if (vendor_org_unrestricted($userId)) return true;
    if ($orgId === null || $orgId <= 0) return false;
    return vendor_org_visible($orgId, $userId);
}

/**
 * Resolve the org_id for a NEW provider/list. $requested: '' / null = the caller's own org; 'all' = NULL
 * ("all agencies", Super Admin only); an integer = that org, which the caller must be able to see.
 * @return array [ok(bool), org_id(int|null), error(string)]
 */
function vendor_resolve_create_org($requested, ?int $userId = null): array
{
    vendor_load_org_scope();
    if ($requested === null || $requested === '' || $requested === 0 || $requested === '0') {
        return [true, vendor_default_org_id($userId), ''];
    }
    if ($requested === 'all') {
        if (!vendor_org_unrestricted($userId)) return [false, null, 'Only a Super Admin may create an "all agencies" record.'];
        return [true, null, ''];
    }
    $oid = (int) $requested;
    if ($oid <= 0) return [false, null, 'Invalid organization.'];
    if (!vendor_org_unrestricted($userId) && !vendor_org_visible($oid, $userId)) {
        return [false, null, 'You cannot create records for that organization.'];
    }
    return [true, $oid, ''];
}

// ── transactions ───────────────────────────────────────────────────────────

/**
 * Start a transaction at READ COMMITTED. Under InnoDB's default REPEATABLE READ the first plain (non-locking) SELECT
 * in a transaction pins the snapshot for every later plain SELECT, so a transaction that read ANYTHING before it won
 * the list lock would then compute the queue from rows OLDER than the offer the other dispatcher just committed and
 * hand the same "next up" slot out twice. Today vendor_dispatch_offer() takes the lock (SELECT ... FOR UPDATE on the
 * list row) as the very first statement, so its snapshot would only form once the lock is granted and this is
 * defence in depth -- kept on purpose, so a later edit that adds a read ahead of the lock cannot quietly bring the
 * double offer back. READ COMMITTED gives every statement a fresh view of committed rows.
 * @return bool true when THIS call opened the transaction (the caller then owns commit/rollback)
 */
function vendor_tx_begin(): bool
{
    $pdo = db();
    // A caller that already holds a transaction keeps its own isolation level (we cannot change it mid-transaction);
    // the lock-first rule in vendor_dispatch_offer() is what makes that safe, not the isolation level.
    if ($pdo->inTransaction()) return false;
    if (vendor_read_committed_ok()) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    return true;
}

/**
 * READ COMMITTED is defence in depth, and with binlog_format=STATEMENT InnoDB refuses every write made under it
 * (error 1665), which would make every offer fail. So it is used everywhere except there.
 */
function vendor_read_committed_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = strtoupper((string) db_fetch_value('SELECT @@SESSION.binlog_format')) !== 'STATEMENT';
        } catch (Throwable $e) {
            $ok = true;
        }
    }
    return $ok;
}

/**
 * "Now" as the DATABASE sees it, as a timestamp comparable with strtotime() of a DATETIME the database stamped
 * (event_at, suspended_until). Mixing the database clock (NOW() stamps event_at) with PHP's time() is only right when
 * the two timezones agree, so every elapsed-time and "is it still suspended" decision reads the database clock.
 */
function vendor_db_now_ts(): int
{
    $now = db_fetch_value('SELECT NOW()');
    $ts = $now !== false && $now !== null ? strtotime((string) $now) : false;
    return $ts !== false ? $ts : time();
}

function vendor_tx_commit(bool $owned): void
{
    if ($owned && db()->inTransaction()) db()->commit();
}

function vendor_tx_rollback(bool $owned): void
{
    if ($owned && db()->inTransaction()) {
        try { db()->rollBack(); } catch (Throwable $e) { error_log('[vendor_tx_rollback] ' . $e->getMessage()); }
    }
}

function vendor_is_dup_key(Throwable $e): bool
{
    return $e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. PURE functions (no database)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Drop the ledger rows that no longer count: every `voided` row itself, every row a `voided` row points at, and
 * (one level) every row that points at a voided row -- an outcome whose OFFER was voided means nothing.
 * $rows must be ordered by id ascending; each needs id, event_type, ref_event_id.
 */
function vendor_ledger_effective_events(array $rows): array
{
    $voided = [];
    foreach ($rows as $r) {
        if (($r['event_type'] ?? '') === 'voided' && !empty($r['ref_event_id'])) {
            $voided[(int) $r['ref_event_id']] = true;
        }
    }
    $out = [];
    foreach ($rows as $r) {
        if (($r['event_type'] ?? '') === 'voided') continue;
        if (isset($voided[(int) $r['id']])) continue;
        if (!empty($r['ref_event_id']) && isset($voided[(int) $r['ref_event_id']])) continue;
        $out[] = $r;
    }
    return $out;
}

/**
 * Per-provider state WITHIN ONE dispatch, from its ledger rows: provider_id => calling | accepted | declined |
 * no_answer | unavailable | withdrew. A provider with ANY state is excluded from the head for this dispatch only
 * (it was already called). Providers with no provider_id (unlisted companies) are not tracked: they have no seat.
 */
function vendor_dispatch_provider_states(array $rows): array
{
    $map = ['offered' => 'calling', 'accepted' => 'accepted', 'declined' => 'declined',
            'no_answer' => 'no_answer', 'unavailable' => 'unavailable', 'withdrew' => 'withdrew'];
    $states = [];
    foreach (vendor_ledger_effective_events($rows) as $r) {
        $t = $r['event_type'];
        $pid = (int) ($r['provider_id'] ?? 0);
        if ($pid <= 0 || !isset($map[$t])) continue;
        $states[$pid] = $map[$t];
    }
    return $states;
}

/**
 * The rotation order. PURE.
 *
 * @param array  $members each: provider_id, position, eligible(bool) (+ any display fields, passed through)
 * @param array  $turns   provider_id => the ledger id of that provider's latest UN-VOIDED consumed turn
 *                        (absent / 0 = never had a turn). The ledger id, not a timestamp, is the sequence key:
 *                        event_at has one-second resolution, so two turns in the same second would tie.
 * @param string $mode    round_robin | strict_order | manual
 * @param array  $states  provider_id => per-dispatch state (see vendor_dispatch_provider_states())
 * @return array ['candidates' => [... + rank, last_turn_seq, state, is_head], 'head_provider_id' => int|null]
 *
 *   round_robin  least recently used first (never-used first), then position, then provider id
 *   strict_order position only (history ignored), then provider id
 *   manual       display order by position; NO head is designated (the system informs, the human picks)
 * Ineligible members (inactive, suspended, removed list) are returned after the eligible ones, flagged, never head.
 * Head = first eligible candidate with no state in this dispatch (none in manual mode).
 */
function vendor_rotation_order(array $members, array $turns, string $mode, array $states = []): array
{
    $rows = [];
    foreach ($members as $m) {
        $pid = (int) $m['provider_id'];
        $m['provider_id'] = $pid;
        $m['position'] = (int) ($m['position'] ?? 0);
        $m['eligible'] = !empty($m['eligible']);
        $m['last_turn_seq'] = (int) ($turns[$pid] ?? 0);
        $m['state'] = $states[$pid] ?? null;
        $m['is_head'] = false;
        $rows[] = $m;
    }
    $useHistory = ($mode === 'round_robin');
    usort($rows, function ($a, $b) use ($useHistory) {
        if ($a['eligible'] !== $b['eligible']) return $a['eligible'] ? -1 : 1;
        if ($useHistory && $a['last_turn_seq'] !== $b['last_turn_seq']) {
            return $a['last_turn_seq'] <=> $b['last_turn_seq'];          // 0 (never) sorts first
        }
        if ($a['position'] !== $b['position']) return $a['position'] <=> $b['position'];
        return $a['provider_id'] <=> $b['provider_id'];                   // deterministic ties
    });
    $head = null;
    $rank = 1;
    foreach ($rows as $i => $r) {
        $rows[$i]['rank'] = $rank++;
        if ($head === null && $mode !== 'manual' && $r['eligible'] && $r['state'] === null) {
            $head = $r['provider_id'];
            $rows[$i]['is_head'] = true;
        }
    }
    return ['candidates' => $rows, 'head_provider_id' => $head];
}

/** The mode in force for a list: its own override if valid, else the global setting. */
function vendor_effective_mode(array $list): string
{
    $m = (string) ($list['mode'] ?? '');
    if (in_array($m, ['round_robin', 'strict_order', 'manual'], true)) return $m;
    return vendor_setting('vendor_rotation_mode');
}

/**
 * The dispatch header, derived from its ledger rows (voided rows and their dependants ignored). PURE.
 * The header columns are a denormalised cache written by the same function, in the same transaction, that appends
 * the ledger row; tests/test_vendor_dispatch_writers.php proves cache == this derivation for a battery of sequences.
 * @return array status, provider_id, provider_name, provider_phone, eta_minutes, assigned_at, closed_at
 */
function vendor_dispatch_derive_status(array $rows): array
{
    $st = ['status' => 'open', 'provider_id' => null, 'provider_name' => null, 'provider_phone' => null,
           'eta_minutes' => null, 'assigned_at' => null, 'closed_at' => null];
    foreach (vendor_ledger_effective_events($rows) as $e) {
        $cur = $st['status'];
        switch ($e['event_type']) {
            case 'accepted':
                if ($cur === 'open') {
                    $st['status'] = 'assigned';
                    $st['provider_id'] = $e['provider_id'] !== null ? (int) $e['provider_id'] : null;
                    $st['provider_name'] = $e['provider_name'];
                    $st['provider_phone'] = $e['provider_phone'] ?? null;
                    $st['eta_minutes'] = $e['eta_minutes'] !== null ? (int) $e['eta_minutes'] : null;
                    $st['assigned_at'] = $e['event_at'];
                }
                break;
            case 'eta_update':
                if ($cur === 'assigned' || $cur === 'on_scene') {
                    $st['eta_minutes'] = $e['eta_minutes'] !== null ? (int) $e['eta_minutes'] : null;
                }
                break;
            case 'withdrew':
                if ($cur === 'assigned' || $cur === 'on_scene') {
                    $st['status'] = 'open';
                    $st['provider_id'] = null; $st['provider_name'] = null; $st['provider_phone'] = null;
                    $st['eta_minutes'] = null; $st['assigned_at'] = null;
                }
                break;
            case 'on_scene':
                if ($cur === 'assigned') $st['status'] = 'on_scene';
                break;
            case 'completed':
                if ($cur === 'assigned' || $cur === 'on_scene') { $st['status'] = 'completed'; $st['closed_at'] = $e['event_at']; }
                break;
            case 'cancelled':
                if ($cur === 'open' || $cur === 'assigned' || $cur === 'on_scene') { $st['status'] = 'cancelled'; $st['closed_at'] = $e['event_at']; }
                break;
            case 'goa':
                if ($cur === 'open' || $cur === 'assigned' || $cur === 'on_scene') { $st['status'] = 'goa'; $st['closed_at'] = $e['event_at']; }
                break;
        }
    }
    return $st;
}

/** Is the status event legal from the current status? PURE. 'withdrew' returns the dispatch to open. */
function vendor_status_event_allowed(string $from, string $event): bool
{
    switch ($event) {
        case 'on_scene':   return $from === 'assigned';
        case 'completed':  return $from === 'assigned' || $from === 'on_scene';
        case 'cancelled':
        case 'goa':        return $from === 'open' || $from === 'assigned' || $from === 'on_scene';
        case 'withdrew':   return $from === 'assigned' || $from === 'on_scene';
        case 'eta_update': return !in_array($from, vendor_terminal_statuses(), true);
        default:           return false;
    }
}

/** The human reference for a dispatch: "26-0123-T1" (or "#123-T1" for a ticket with no case number). */
function vendor_ref_label(string $ticketRef, int $ordinal): string
{
    return $ticketRef . '-T' . $ordinal;
}

/** CSV cell guard: a value starting with = + - @ (or tab/CR) is prefixed with ' so a spreadsheet never runs it. */
function vendor_csv_cell($v): string
{
    $s = (string) $v;
    if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) return "'" . $s;
    return $s;
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. The rotation queue (database)
// ═══════════════════════════════════════════════════════════════════════════

/** Load a rotation list row, or null. */
function vendor_list_get(int $listId): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one("SELECT * FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ?", [$listId]);
}

/**
 * The queue for a list, optionally in the context of one dispatch (whose per-provider states then exclude
 * already-called providers from the head). Last-turn is DERIVED live from the ledger on every call.
 *
 * @return array ['found','list_id','mode','head_provider_id','candidates'=>[...]]; each candidate carries the display fields
 */
function vendor_rotation_queue(int $listId, ?int $dispatchId = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $list = vendor_list_get($listId);
    if (!$list) return ['found' => false, 'list_id' => $listId, 'mode' => vendor_setting('vendor_rotation_mode'), 'head_provider_id' => null, 'candidates' => []];

    $mode = vendor_effective_mode($list);
    $listActive = ((int) $list['is_active'] === 1);

    $members = db_fetch_all(
        "SELECT m.`provider_id`, m.`position`, p.`name`, p.`contact_name`, p.`phone`, p.`phone_alt`, p.`service_area`,
                p.`hours_note`, p.`yard_facility_id`, p.`is_active`, p.`suspended_until`, p.`suspend_reason`,
                (p.`suspended_until` IS NOT NULL AND p.`suspended_until` > NOW()) AS `is_suspended`
           FROM `{$prefix}vendor_rotation_members` m
           JOIN `{$prefix}vendor_providers` p ON p.`id` = m.`provider_id`
          WHERE m.`list_id` = ? AND m.`removed_at` IS NULL
          ORDER BY m.`position`, m.`provider_id`",
        [$listId]);
    foreach ($members as $i => $m) {
        $members[$i]['eligible'] = $listActive && (int) $m['is_active'] === 1 && (int) $m['is_suspended'] === 0;
    }

    // A turn is a consumed_turn=1 row that no `voided` row cancels -- directly, or through the offer it depends on.
    $turnRows = db_fetch_all(
        "SELECT g.`provider_id`, MAX(g.`id`) AS `seq`, MAX(g.`event_at`) AS `at`
           FROM `{$prefix}vendor_dispatch_ledger` g
          WHERE g.`list_id` = ? AND g.`consumed_turn` = 1 AND g.`provider_id` IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM `{$prefix}vendor_dispatch_ledger` v
                             WHERE v.`event_type` = 'voided' AND v.`ref_event_id` = g.`id`)
            AND NOT EXISTS (SELECT 1 FROM `{$prefix}vendor_dispatch_ledger` v2
                             WHERE v2.`event_type` = 'voided' AND g.`ref_event_id` IS NOT NULL AND v2.`ref_event_id` = g.`ref_event_id`)
          GROUP BY g.`provider_id`",
        [$listId]);
    $turns = []; $turnAt = [];
    foreach ($turnRows as $t) {
        $turns[(int) $t['provider_id']] = (int) $t['seq'];
        $turnAt[(int) $t['provider_id']] = $t['at'];
    }

    $states = [];
    if ($dispatchId !== null && $dispatchId > 0) {
        $states = vendor_dispatch_provider_states(vendor_dispatch_ledger_rows($dispatchId));
    }

    $ordered = vendor_rotation_order($members, $turns, $mode, $states);
    foreach ($ordered['candidates'] as $i => $c) {
        $ordered['candidates'][$i]['last_turn_at'] = $turnAt[$c['provider_id']] ?? null;
    }
    return ['found' => true, 'list_id' => $listId, 'mode' => $mode,
            'head_provider_id' => $ordered['head_provider_id'], 'candidates' => $ordered['candidates']];
}

/** All ledger rows of one dispatch in id order. */
function vendor_dispatch_ledger_rows(int $dispatchId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_all(
        "SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `dispatch_id` = ? ORDER BY `id`", [$dispatchId]);
}

/** Does this list have at least one consumed turn (so a newcomer must go to the BACK)? */
function vendor_list_has_history(int $listId): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `consumed_turn` = 1", [$listId]) > 0;
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. Writers
// ═══════════════════════════════════════════════════════════════════════════

/**
 * The ONLY writer of the ledger: a plain INSERT, never an UPDATE or DELETE (a tokenizing test enforces it).
 * event_at is the database's NOW(), never a client value.
 * @param array $r keys: event_type (required), dispatch_id, list_id, provider_id, provider_name, provider_phone,
 *                 selection_method, consumed_turn, expected_head_provider_id, eta_minutes, reason, detail,
 *                 ref_event_id; $actor supplies the user, name and ip
 * @return int the new ledger row id
 */
function vendor_ledger_insert(array $r, array $actor): int
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $a = vendor_actor_norm($actor);
    $type = (string) ($r['event_type'] ?? '');
    if (!in_array($type, vendor_event_types(), true)) {
        throw new InvalidArgumentException('Unknown vendor ledger event type: ' . $type);
    }
    $method = $r['selection_method'] ?? null;
    if ($method !== null && !in_array($method, vendor_selection_methods(), true)) {
        throw new InvalidArgumentException('Unknown selection method: ' . $method);
    }
    $eta = isset($r['eta_minutes']) && $r['eta_minutes'] !== null && $r['eta_minutes'] !== '' ? (int) $r['eta_minutes'] : null;
    db_query(
        "INSERT INTO `{$prefix}vendor_dispatch_ledger`
            (`dispatch_id`, `list_id`, `provider_id`, `provider_name`, `provider_phone`, `event_type`,
             `selection_method`, `consumed_turn`, `expected_head_provider_id`, `eta_minutes`, `reason`, `detail`,
             `ref_event_id`, `event_at`, `actor_user_id`, `actor_name`, `actor_ip`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)",
        [
            isset($r['dispatch_id']) ? (int) $r['dispatch_id'] : null,
            isset($r['list_id']) && (int) $r['list_id'] > 0 ? (int) $r['list_id'] : null,
            isset($r['provider_id']) && (int) $r['provider_id'] > 0 ? (int) $r['provider_id'] : null,
            vendor_clean_str($r['provider_name'] ?? '', 120) ?? '',
            vendor_clean_str($r['provider_phone'] ?? '', 32),
            $type,
            $method,
            !empty($r['consumed_turn']) ? 1 : 0,
            isset($r['expected_head_provider_id']) && (int) $r['expected_head_provider_id'] > 0 ? (int) $r['expected_head_provider_id'] : null,
            $eta,
            vendor_clean_str($r['reason'] ?? null, 255),
            vendor_clean_str($r['detail'] ?? null, 500),
            isset($r['ref_event_id']) && (int) $r['ref_event_id'] > 0 ? (int) $r['ref_event_id'] : null,
            $a['user_id'],
            $a['name'],
            $a['ip'],
        ]);
    return (int) db_insert_id();
}

/** The incident a dispatch hangs off: not deleted. Returns the columns the dispatch screens need, or null. */
function vendor_ticket_info(int $ticketId): ?array
{
    if ($ticketId <= 0) return null;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $row = db_fetch_one(
        "SELECT `id`, `incident_number`, `street`, `city`, `state`, `address_about`, `lat`, `lng`, `scope`, `status`, `org_id`
           FROM `{$prefix}ticket`
          WHERE `id` = ? AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
        [$ticketId]);
    if (!$row) return null;
    $row['ref'] = incnum_display((int) $row['id'], $row['incident_number'] ?? null);
    return $row;
}

/** Load a dispatch header; optionally with a row lock (inside a transaction). */
function vendor_dispatch_get(int $dispatchId, bool $forUpdate = false): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one(
        "SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ?" . ($forUpdate ? ' FOR UPDATE' : ''), [$dispatchId]);
}

/**
 * Does this facility exist, is it not soft-deleted, and may the caller see it? A destination or yard facility must be one
 * the dispatcher could have picked from the facility list: an id from another agency is refused, not stored (and then
 * read back by name). Installs whose facilities table predates org_id or deleted_at degrade to what they can check.
 */
function vendor_facility_exists(int $facilityId): bool
{
    if ($facilityId <= 0) return false;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $live = " AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')";
    try {
        [$frag, $vars] = vendor_org_frag('`org_id`');
        return (bool) db_fetch_value("SELECT 1 FROM `{$prefix}facilities` WHERE `id` = ?" . $live . $frag, array_merge([$facilityId], $vars));
    } catch (Throwable $e) {
        // pre-org_id and/or pre-soft-delete schema: plain existence
        try {
            return (bool) db_fetch_value("SELECT 1 FROM `{$prefix}facilities` WHERE `id` = ?" . $live, [$facilityId]);
        } catch (Throwable $e2) {
            return (bool) db_fetch_value("SELECT 1 FROM `{$prefix}facilities` WHERE `id` = ?", [$facilityId]);
        }
    }
}

/**
 * The rotation list a registered company sits on, for a pick that named the company but no list. Without this a
 * direct request that simply omits list_id would be classified "unlisted" and skip the override rules for a company
 * that IS a rotation member. Active, visible lists of this service type only; the default list wins, then sort order.
 */
function vendor_rotation_list_for_provider(int $providerId, int $serviceTypeId): ?array
{
    if ($providerId <= 0 || $serviceTypeId <= 0) return null;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$frag, $vars] = vendor_org_frag('l.`org_id`');
    $row = db_fetch_one(
        "SELECT l.`id`
           FROM `{$prefix}vendor_rotation_lists` l
           JOIN `{$prefix}vendor_rotation_members` m ON m.`list_id` = l.`id` AND m.`removed_at` IS NULL
          WHERE l.`is_active` = 1 AND l.`service_type_id` = ? AND m.`provider_id` = ?" . $frag . "
          ORDER BY l.`is_default` DESC, l.`sort_order`, l.`id`
          LIMIT 1", array_merge([$serviceTypeId, $providerId], $vars));
    return $row ? vendor_list_get((int) $row['id']) : null;
}

/**
 * Validate and normalise the free-form header fields (vehicle, plate, reason, destination, notes).
 * A destination is only kept when the service type NEEDS one; for a lockout the server drops it even if sent.
 * @return array [fields(array), error(string|null)]
 */
function vendor_clean_dispatch_fields(array $in, array $serviceType): array
{
    $f = [
        'vehicle_desc' => vendor_clean_str($in['vehicle_desc'] ?? null, 160),
        'plate'        => ($p = vendor_clean_str($in['plate'] ?? null, 16)) !== null ? strtoupper($p) : null,
        'plate_state'  => ($s = vendor_clean_str($in['plate_state'] ?? null, 4)) !== null ? strtoupper($s) : null,
        'tow_reason'   => vendor_clean_str($in['tow_reason'] ?? null, 80),
        'notes'        => vendor_clean_str($in['notes'] ?? null, 255),
        'dest_facility_id' => null,
        'dest_text'    => null,
    ];
    if ((int) $serviceType['needs_destination'] === 1) {
        $fid = (int) ($in['dest_facility_id'] ?? 0);
        if ($fid > 0) {
            if (!vendor_facility_exists($fid)) return [$f, 'That destination facility does not exist.'];
            $f['dest_facility_id'] = $fid;
        }
        $f['dest_text'] = vendor_clean_str($in['dest_text'] ?? null, 255);
    }
    return [$f, null];
}

/** Insert the dispatch header (ordinal allocated with a retry on the unique key). Caller owns the transaction. */
function _vendor_dispatch_insert_header(array $ticket, array $serviceType, array $in, array $actor, ?array $list)
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$fields, $err] = vendor_clean_dispatch_fields($in, $serviceType);
    if ($err !== null) return vendor_fail(422, 'validation', $err);

    $orgId = null;
    if ($list !== null && $list['org_id'] !== null) $orgId = (int) $list['org_id'];
    elseif ($ticket['org_id'] !== null && (int) $ticket['org_id'] > 0) $orgId = (int) $ticket['org_id'];

    $a = vendor_actor_norm($actor);
    for ($try = 0; $try < 6; $try++) {
        $ordinal = (int) db_fetch_value(
            "SELECT COALESCE(MAX(`ordinal`), 0) + 1 FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` = ?", [(int) $ticket['id']]);
        try {
            db_query(
                "INSERT INTO `{$prefix}vendor_dispatches`
                    (`ticket_id`, `ticket_ref`, `ordinal`, `org_id`, `service_type_id`, `service_label`, `list_id`, `status`,
                     `vehicle_desc`, `plate`, `plate_state`, `tow_reason`, `dest_facility_id`, `dest_text`, `notes`, `created_by_name`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?)",
                [(int) $ticket['id'], substr((string) $ticket['ref'], 0, 64), $ordinal, $orgId,
                 (int) $serviceType['id'], (string) $serviceType['label'], $list ? (int) $list['id'] : null,
                 $fields['vehicle_desc'], $fields['plate'], $fields['plate_state'], $fields['tow_reason'],
                 $fields['dest_facility_id'], $fields['dest_text'], $fields['notes'], $a['name']]);
            $id = (int) db_insert_id();
            return ['ok' => true, 'dispatch_id' => $id, 'ordinal' => $ordinal];
        } catch (Throwable $e) {
            if (vendor_is_dup_key($e)) continue;   // another dispatcher took that ordinal a moment ago: ask again
            throw $e;
        }
    }
    return vendor_fail(503, 'busy', 'Could not allocate a dispatch reference. Please try again.');
}

/** Load and validate the pieces create/offer share: ticket, service type, list. */
function _vendor_dispatch_context(int $ticketId, array $in): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ticket = vendor_ticket_info($ticketId);
    if (!$ticket) return [vendor_fail(404, 'ticket_not_found', 'Incident not found.'), null, null, null];

    $stId = (int) ($in['service_type_id'] ?? 0);
    $st = $stId > 0 ? db_fetch_one("SELECT * FROM `{$prefix}vendor_service_types` WHERE `id` = ? AND `is_active` = 1", [$stId]) : null;
    if (!$st) return [vendor_fail(422, 'validation', 'Choose a service type.'), null, null, null];

    $list = null;
    $listId = (int) ($in['list_id'] ?? 0);
    if ($listId > 0) {
        $list = vendor_list_get($listId);
        if (!$list || (int) $list['is_active'] !== 1) return [vendor_fail(422, 'validation', 'That rotation list is not available.'), null, null, null];
        if ((int) $list['service_type_id'] !== (int) $st['id']) return [vendor_fail(422, 'validation', 'That rotation list is for a different service type.'), null, null, null];
        if (!vendor_org_visible($list['org_id'] !== null ? (int) $list['org_id'] : null)) {
            return [vendor_fail(404, 'list_not_found', 'That rotation list is not available.'), null, null, null];
        }
    }
    return [null, $ticket, $st, $list];
}

/**
 * Create a dispatch header on an incident WITHOUT offering it to anyone (the dialog normally goes through
 * vendor_dispatch_offer(), which creates the header in the same transaction as the first offer so an abandoned
 * dialog leaves nothing behind). Allowed on a closed incident? NO new offers on a closed incident; a bare header
 * is also refused there so a closed incident cannot quietly grow dispatches.
 * @param array $in service_type_id, list_id?, vehicle_desc?, plate?, plate_state?, tow_reason?, dest_facility_id?, dest_text?, notes?
 */
function vendor_dispatch_create(int $ticketId, array $in, array $actor): array
{
    if (!vendor_schema_ready()) return vendor_fail(409, 'vendor_schema_missing', 'The towing / roadside tables are missing. Run: php sql/run_migrations.php');
    [$err, $ticket, $st, $list] = _vendor_dispatch_context($ticketId, $in);
    if ($err) return $err;
    if ((int) $ticket['status'] === 1) return vendor_fail(409, 'incident_closed', 'This incident is closed. Reopen it before dispatching.');

    $owned = vendor_tx_begin();
    try {
        $res = _vendor_dispatch_insert_header($ticket, $st, $in, $actor, $list);
        if (!($res['ok'] ?? false)) { vendor_tx_rollback($owned); return $res; }
        vendor_tx_commit($owned);
    } catch (Throwable $e) {
        vendor_tx_rollback($owned);
        error_log('[vendor_dispatch_create] ' . $e->getMessage());
        return vendor_fail(500, 'internal', 'Could not create the dispatch.');
    }
    $row = vendor_dispatch_get((int) $res['dispatch_id']);
    vendor_after_commit('dispatch.create', $row, ['ticket' => $ticket], $actor);
    return ['ok' => true, 'dispatch' => $row, 'dispatch_id' => (int) $res['dispatch_id']];
}

/**
 * Update the free-form details of a dispatch (vehicle, plate, reason, destination, notes). The destination is
 * ignored for a service type that does not need one. Allowed in any state (a plate typo on a completed dispatch
 * is a correction, and the audit trail records which fields changed).
 */
function vendor_dispatch_update_details(int $dispatchId, array $in, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $d = vendor_dispatch_get($dispatchId);
    if (!$d) return vendor_fail(404, 'dispatch_not_found', 'Dispatch not found.');
    $st = db_fetch_one("SELECT * FROM `{$prefix}vendor_service_types` WHERE `id` = ?", [(int) $d['service_type_id']]);
    if (!$st) return vendor_fail(500, 'internal', 'Service type missing.');

    [$f, $err] = vendor_clean_dispatch_fields($in, $st);
    if ($err !== null) return vendor_fail(422, 'validation', $err);

    $changed = [];
    $changes = [];
    foreach ($f as $col => $val) {
        $old = $d[$col];
        if ((string) $old !== (string) $val) { $changed[] = $col; $changes[$col] = ['before' => $old, 'after' => $val]; }
    }
    if (!$changed) return ['ok' => true, 'dispatch' => $d, 'changed' => []];

    try {
        db_query(
            "UPDATE `{$prefix}vendor_dispatches`
                SET `vehicle_desc` = ?, `plate` = ?, `plate_state` = ?, `tow_reason` = ?,
                    `dest_facility_id` = ?, `dest_text` = ?, `notes` = ?
              WHERE `id` = ?",
            [$f['vehicle_desc'], $f['plate'], $f['plate_state'], $f['tow_reason'], $f['dest_facility_id'], $f['dest_text'], $f['notes'], $dispatchId]);
    } catch (Throwable $e) {
        error_log('[vendor_dispatch_update_details] ' . $e->getMessage());
        return vendor_fail(500, 'internal', 'Could not save the details.');
    }
    $row = vendor_dispatch_get($dispatchId);
    vendor_after_commit('dispatch.update', $row, ['changed' => $changed, 'changes' => $changes], $actor);
    return ['ok' => true, 'dispatch' => $row, 'changed' => $changed];
}

/** Recompute the header cache from the ledger. Caller holds the dispatch row lock. */
function vendor_dispatch_recompute_header(int $dispatchId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $st = vendor_dispatch_derive_status(vendor_dispatch_ledger_rows($dispatchId));
    db_query(
        "UPDATE `{$prefix}vendor_dispatches`
            SET `status` = ?, `provider_id` = ?, `provider_name` = ?, `provider_phone` = ?,
                `eta_minutes` = ?, `assigned_at` = ?, `closed_at` = ?
          WHERE `id` = ?",
        [$st['status'], $st['provider_id'], $st['provider_name'], $st['provider_phone'],
         $st['eta_minutes'], $st['assigned_at'], $st['closed_at'], $dispatchId]);
    return $st;
}

/**
 * OFFER the job to a company (the dispatcher is about to call, or has just called, them).
 *
 * The server classifies every offer; the client only says what it saw (client_head_provider_id) and what it picked:
 *   owner_request  the driver/owner asked for this company (a flag from the dispatcher)
 *   unlisted       no list context, or not an eligible member of the list (typed name + phone, or a registered
 *                  provider picked from "Other provider")
 *   rotation       the list's head (always, in manual mode: every manual pick is a legitimate rotation pick)
 *   override       anything else on the list -- needs vendor_allow_override=1, and a reason when it skips an
 *                  eligible next-up and vendor_override_requires_reason=1
 * Only `rotation` consumes a turn (and only under vendor_advance_rule=any_offer; under accepted_only the turn is
 * consumed by the ACCEPTED row instead). The stamp is written on the ledger row, so a later settings change never
 * rewrites history.
 *
 * Concurrency: the list row is locked FOR UPDATE as the first statement of a READ COMMITTED transaction, so two
 * dispatchers pressing "next tow" at once are serialised per list. The loser sees the winner's offer, finds the
 * head has moved, and gets 409 queue_changed with the fresh queue instead of a duplicate offer. Side effects
 * (incident note, audit, SSE) run AFTER commit, never inside the lock.
 *
 * @param array $in dispatch_id  an existing open dispatch, OR ticket_id + service_type_id (+ details) to create
 *                  one in the same transaction; provider_id (0/absent = unlisted); provider_name + provider_phone
 *                  (unlisted); list_id; client_head_provider_id; owner_request; reason
 */
function vendor_dispatch_offer(array $in, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!vendor_schema_ready()) return vendor_fail(409, 'vendor_schema_missing', 'The towing / roadside tables are missing. Run: php sql/run_migrations.php');

    // ── pre-reads (outside the transaction; nothing here decides rotation) ──
    $dispatchId = (int) ($in['dispatch_id'] ?? 0);
    $existing = null;
    if ($dispatchId > 0) {
        $existing = vendor_dispatch_get($dispatchId);
        if (!$existing) return vendor_fail(404, 'dispatch_not_found', 'Dispatch not found.');
        $in['ticket_id'] = (int) $existing['ticket_id'];
        $in['service_type_id'] = (int) $existing['service_type_id'];
        if (empty($in['list_id']) && $existing['list_id'] !== null) $in['list_id'] = (int) $existing['list_id'];
    }
    [$err, $ticket, $st, $list] = _vendor_dispatch_context((int) ($in['ticket_id'] ?? 0), $in);
    if ($err) return $err;
    if ((int) $ticket['status'] === 1) return vendor_fail(409, 'incident_closed', 'This incident is closed. Reopen it before dispatching a company.');
    if ($existing && $existing['status'] !== 'open') {
        return vendor_fail(409, in_array($existing['status'], vendor_terminal_statuses(), true) ? 'dispatch_closed' : 'already_assigned',
            in_array($existing['status'], vendor_terminal_statuses(), true) ? 'This dispatch is already closed.' : 'A company has already accepted this dispatch.');
    }

    $ownerRequest = !empty($in['owner_request']);
    $reason = vendor_clean_str($in['reason'] ?? null, 255);
    $providerId = (int) ($in['provider_id'] ?? 0);
    $clientHead = (int) ($in['client_head_provider_id'] ?? 0);

    // A pick that names a registered company but no list is judged against the list that company sits on, so omitting
    // list_id cannot be used to dodge the override rules. (owner_request is the one deliberate exception: it is the
    // dispatcher's statement that the owner chose the company, it is stamped on the ledger row, and the history shows it.)
    if ($list === null && $providerId > 0 && !$ownerRequest) {
        $shadow = vendor_rotation_list_for_provider($providerId, (int) $st['id']);
        if ($shadow !== null) $list = $shadow;
    }

    // ── resolve who is being called ──
    $provider = null;
    if ($providerId > 0) {
        $provider = db_fetch_one("SELECT * FROM `{$prefix}vendor_providers` WHERE `id` = ?", [$providerId]);
        if (!$provider || !vendor_org_visible($provider['org_id'] !== null ? (int) $provider['org_id'] : null)) {
            return vendor_fail(404, 'provider_not_found', 'That company is not available.');
        }
        if ((int) $provider['is_active'] !== 1) return vendor_fail(409, 'provider_unavailable', 'That company has been retired.');
        $suspended = $provider['suspended_until'] !== null && strtotime((string) $provider['suspended_until']) > vendor_db_now_ts();
        if ($suspended && !$ownerRequest) {
            return vendor_fail(409, 'provider_unavailable', 'That company is suspended until ' . $provider['suspended_until'] . '.');
        }
        $name = (string) $provider['name'];
        $phone = (string) $provider['phone'];
    } else {
        $name = vendor_clean_str($in['provider_name'] ?? null, 120);
        $phone = vendor_clean_phone($in['provider_phone'] ?? null);
        if ($name === null) return vendor_fail(422, 'validation', 'Enter the company name.');
        if ($phone === null) return vendor_fail(422, 'validation', 'Enter a phone number for that company.');
    }

    // ── transaction: lock the list first, then decide ──
    $owned = vendor_tx_begin();
    try {
        if ($list) {
            db_query("SELECT `id` FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ? FOR UPDATE", [(int) $list['id']]);
            $list = vendor_list_get((int) $list['id']);            // re-read under the lock
            if (!$list || (int) $list['is_active'] !== 1) { vendor_tx_rollback($owned); return vendor_fail(409, 'list_unavailable', 'That rotation list was just retired.'); }
        }

        if ($providerId > 0) {
            // Lock order is always list, then provider (vendor_provider_delete() does the same), so a delete of this company
            // cannot commit between the checks above and the ledger insert below and leave a row pointing at nothing.
            $lockedProvider = db_fetch_one("SELECT `id`, `is_active` FROM `{$prefix}vendor_providers` WHERE `id` = ? FOR UPDATE", [$providerId]);
            if (!$lockedProvider || (int) $lockedProvider['is_active'] !== 1) {
                vendor_tx_rollback($owned);
                return vendor_fail(409, 'provider_unavailable', 'That company is no longer available.');
            }
        }

        if ($existing) {
            $existing = vendor_dispatch_get((int) $existing['id'], true);    // serialise events on this dispatch too
            if ($existing['status'] !== 'open') { vendor_tx_rollback($owned); return vendor_fail(409, 'dispatch_closed', 'This dispatch is no longer open.'); }
        }

        $queue = $list ? vendor_rotation_queue((int) $list['id'], $existing ? (int) $existing['id'] : null) : null;
        $head = $queue ? $queue['head_provider_id'] : null;

        // A company already being called on this dispatch: finish that call before starting another to the same one.
        if ($queue && $providerId > 0) {
            foreach ($queue['candidates'] as $c) {
                if ((int) $c['provider_id'] === $providerId && $c['state'] === 'calling') {
                    vendor_tx_rollback($owned);
                    return vendor_fail(409, 'offer_pending', 'That company has already been called for this dispatch. Record the outcome of that call first.', ['queue' => $queue]);
                }
            }
        }

        // Stale-screen race: the dispatcher picked what THEIR screen showed as next, but next has since changed.
        if ($queue && !$ownerRequest && $queue['mode'] !== 'manual' && $clientHead > 0 && $providerId === $clientHead && $head !== $clientHead) {
            vendor_tx_rollback($owned);
            $who = '';
            foreach ($queue['candidates'] as $c) { if ($head !== null && (int) $c['provider_id'] === (int) $head) $who = (string) $c['name']; }
            return vendor_fail(409, 'queue_changed',
                $who !== '' ? ('The rotation just moved. ' . $who . ' is next now. Nothing was recorded.') : 'The rotation just moved. Nothing was recorded.',
                ['queue' => $queue]);
        }

        // ── classify ──
        $member = null;
        if ($queue && $providerId > 0) {
            foreach ($queue['candidates'] as $c) { if ((int) $c['provider_id'] === $providerId) $member = $c; }
        }
        if ($ownerRequest) {
            $method = 'owner_request';
        } elseif ($queue && $member && $member['eligible']) {
            if ($queue['mode'] === 'manual' || $head === $providerId) $method = 'rotation';
            else $method = 'override';
        } else {
            $method = 'unlisted';
        }

        if ($method === 'override') {
            if (vendor_setting('vendor_allow_override') !== '1') {
                vendor_tx_rollback($owned);
                return vendor_fail(409, 'override_not_allowed', 'This agency requires calling the company that is next in the rotation.', ['queue' => $queue]);
            }
            if ($head !== null && vendor_setting('vendor_override_requires_reason') === '1' && $reason === null) {
                vendor_tx_rollback($owned);
                return vendor_fail(422, 'reason_required', 'Choose or enter a reason for skipping the company that is next in the rotation.', ['queue' => $queue]);
            }
        }

        // ── write ──
        if ($existing) {
            $dId = (int) $existing['id'];
            if ($existing['list_id'] === null && $list) {
                db_query("UPDATE `{$prefix}vendor_dispatches` SET `list_id` = ? WHERE `id` = ? AND `list_id` IS NULL", [(int) $list['id'], $dId]);
            }
        } else {
            $hdr = _vendor_dispatch_insert_header($ticket, $st, $in, $actor, $list);
            if (!($hdr['ok'] ?? false)) { vendor_tx_rollback($owned); return $hdr; }
            $dId = (int) $hdr['dispatch_id'];
        }

        $consumed = ($method === 'rotation' && vendor_setting('vendor_advance_rule') === 'any_offer') ? 1 : 0;
        $eventId = vendor_ledger_insert([
            'event_type' => 'offered', 'dispatch_id' => $dId, 'list_id' => $list ? (int) $list['id'] : null,
            'provider_id' => $providerId > 0 ? $providerId : null, 'provider_name' => $name, 'provider_phone' => $phone,
            'selection_method' => $method, 'consumed_turn' => $consumed,
            'expected_head_provider_id' => $head, 'reason' => $reason,
        ], $actor);

        vendor_tx_commit($owned);
    } catch (Throwable $e) {
        vendor_tx_rollback($owned);
        error_log('[vendor_dispatch_offer] ' . $e->getMessage());
        $busy = $e instanceof PDOException && isset($e->errorInfo[1]) && in_array((int) $e->errorInfo[1], [1205, 1213], true);
        return $busy ? vendor_fail(503, 'busy', 'The rotation is busy. Please try again.')
                     : vendor_fail(500, 'internal', 'Could not record the call.');
    }

    $row = vendor_dispatch_get($dId);
    vendor_after_commit('dispatch.offer', $row, [
        'ticket' => $ticket, 'event_id' => $eventId, 'method' => $method, 'provider_name' => $name,
        'expected_head' => $head, 'reason' => $reason, 'consumed_turn' => $consumed, 'list_id' => $list ? (int) $list['id'] : null,
    ], $actor);
    return ['ok' => true, 'dispatch' => $row, 'dispatch_id' => $dId, 'event_id' => $eventId, 'selection_method' => $method,
            'consumed_turn' => $consumed, 'queue' => $list ? vendor_rotation_queue((int) $list['id'], $dId) : null];
}

/**
 * Record what came of a call: accepted (with an ETA), declined, no_answer or unavailable. $offerEventId names the
 * offer this answers. Only a call still `calling` can be answered; `accepted` needs the dispatch to be open (one
 * accepted company at a time). Outcomes are accepted even on a closed INCIDENT: a tow that arrives after the
 * incident closed must still be recorded.
 */
function vendor_dispatch_outcome(int $dispatchId, int $offerEventId, string $outcome, ?int $eta, array $actor, ?string $reason = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!in_array($outcome, ['accepted', 'declined', 'no_answer', 'unavailable'], true)) {
        return vendor_fail(422, 'validation', 'Unknown outcome.');
    }
    if ($eta !== null && ($eta < 0 || $eta > 1440)) return vendor_fail(422, 'validation', 'ETA must be between 0 and 1440 minutes.');
    if ($outcome !== 'accepted') $eta = null;

    $owned = vendor_tx_begin();
    try {
        $d = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ? FOR UPDATE", [$dispatchId]);
        if (!$d) { vendor_tx_rollback($owned); return vendor_fail(404, 'dispatch_not_found', 'Dispatch not found.'); }

        $rows = vendor_dispatch_ledger_rows($dispatchId);
        $effective = vendor_ledger_effective_events($rows);
        $offer = null;
        foreach ($effective as $r) { if ((int) $r['id'] === $offerEventId && $r['event_type'] === 'offered') $offer = $r; }
        if (!$offer) { vendor_tx_rollback($owned); return vendor_fail(404, 'offer_not_found', 'That call is not on this dispatch (or was voided).'); }
        foreach ($effective as $r) {
            if ((int) ($r['ref_event_id'] ?? 0) === $offerEventId && in_array($r['event_type'], ['accepted', 'declined', 'no_answer', 'unavailable'], true)) {
                vendor_tx_rollback($owned);
                return vendor_fail(409, 'outcome_already_recorded', 'An outcome was already recorded for that call.');
            }
        }
        if ($outcome === 'accepted' && $d['status'] !== 'open') {
            vendor_tx_rollback($owned);
            return vendor_fail(409, in_array($d['status'], vendor_terminal_statuses(), true) ? 'dispatch_closed' : 'already_assigned',
                in_array($d['status'], vendor_terminal_statuses(), true) ? 'This dispatch is already closed.' : 'A company has already accepted this dispatch.');
        }

        // The accepted row consumes the turn exactly when the OFFER it answers was a rotation offer that did NOT consume
        // it (stamped under accepted_only). Reading the offer row rather than the setting as it stands now means an
        // administrator flipping vendor_advance_rule while a call is in flight can neither stamp two turns for one call
        // nor none.
        $consumed = ($outcome === 'accepted' && $offer['selection_method'] === 'rotation'
                     && (int) ($offer['consumed_turn'] ?? 0) === 0) ? 1 : 0;
        $eventId = vendor_ledger_insert([
            'event_type' => $outcome, 'dispatch_id' => $dispatchId,
            'list_id' => $offer['list_id'], 'provider_id' => $offer['provider_id'],
            'provider_name' => $offer['provider_name'], 'provider_phone' => $offer['provider_phone'],
            'selection_method' => $offer['selection_method'], 'consumed_turn' => $consumed,
            'eta_minutes' => $eta, 'reason' => $reason, 'ref_event_id' => $offerEventId,
        ], $actor);
        vendor_dispatch_recompute_header($dispatchId);
        vendor_tx_commit($owned);
    } catch (Throwable $e) {
        vendor_tx_rollback($owned);
        error_log('[vendor_dispatch_outcome] ' . $e->getMessage());
        return vendor_fail(500, 'internal', 'Could not record the outcome.');
    }

    $row = vendor_dispatch_get($dispatchId);
    $ticket = vendor_ticket_info((int) $row['ticket_id']);
    vendor_after_commit('dispatch.outcome', $row, [
        'ticket' => $ticket, 'event_id' => $eventId, 'outcome' => $outcome, 'provider_name' => $offer['provider_name'],
        'eta' => $eta, 'consumed_turn' => $consumed,
    ], $actor);
    return ['ok' => true, 'dispatch' => $row, 'event_id' => $eventId, 'consumed_turn' => $consumed];
}

/**
 * A status event on a dispatch: on_scene, completed, cancelled, goa, withdrew (the company backs out after
 * accepting: back to open, provider cleared) or eta_update. Always accepted, whatever the incident's state.
 */
function vendor_dispatch_status(int $dispatchId, string $event, ?int $eta, array $actor, ?string $reason = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!in_array($event, ['on_scene', 'completed', 'cancelled', 'goa', 'withdrew', 'eta_update'], true)) {
        return vendor_fail(422, 'validation', 'Unknown status event.');
    }
    if ($event === 'eta_update') {
        if ($eta === null || $eta < 0 || $eta > 1440) return vendor_fail(422, 'validation', 'Enter the new ETA in minutes (0 to 1440).');
    } else {
        $eta = null;
    }

    $owned = vendor_tx_begin();
    try {
        $d = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ? FOR UPDATE", [$dispatchId]);
        if (!$d) { vendor_tx_rollback($owned); return vendor_fail(404, 'dispatch_not_found', 'Dispatch not found.'); }
        if (!vendor_status_event_allowed((string) $d['status'], $event)) {
            vendor_tx_rollback($owned);
            return vendor_fail(409, 'illegal_transition', 'A dispatch that is "' . $d['status'] . '" cannot be marked "' . $event . '".');
        }
        $eventId = vendor_ledger_insert([
            'event_type' => $event, 'dispatch_id' => $dispatchId,
            'list_id' => $d['list_id'], 'provider_id' => $d['provider_id'],
            'provider_name' => (string) ($d['provider_name'] ?? ''), 'provider_phone' => $d['provider_phone'],
            'eta_minutes' => $eta, 'reason' => $reason,
        ], $actor);
        vendor_dispatch_recompute_header($dispatchId);
        vendor_tx_commit($owned);
    } catch (Throwable $e) {
        vendor_tx_rollback($owned);
        error_log('[vendor_dispatch_status] ' . $e->getMessage());
        return vendor_fail(500, 'internal', 'Could not record the status change.');
    }

    $row = vendor_dispatch_get($dispatchId);
    $ticket = vendor_ticket_info((int) $row['ticket_id']);
    vendor_after_commit('dispatch.status', $row, [
        'ticket' => $ticket, 'event_id' => $eventId, 'event' => $event, 'eta' => $eta, 'provider_name' => (string) ($d['provider_name'] ?? ''),
    ], $actor);
    return ['ok' => true, 'dispatch' => $row, 'event_id' => $eventId];
}

/** A free-text note on the dispatch's own timeline. Allowed in any state. */
function vendor_dispatch_note(int $dispatchId, string $text, array $actor): array
{
    $text = vendor_clean_str($text, 500);
    if ($text === null) return vendor_fail(422, 'validation', 'Enter a note.');
    $d = vendor_dispatch_get($dispatchId);
    if (!$d) return vendor_fail(404, 'dispatch_not_found', 'Dispatch not found.');
    try {
        $eventId = vendor_ledger_insert([
            'event_type' => 'note', 'dispatch_id' => $dispatchId, 'list_id' => $d['list_id'],
            'provider_id' => $d['provider_id'], 'provider_name' => (string) ($d['provider_name'] ?? ''),
            'detail' => $text,
        ], $actor);
    } catch (Throwable $e) {
        error_log('[vendor_dispatch_note] ' . $e->getMessage());
        return vendor_fail(500, 'internal', 'Could not save the note.');
    }
    $ticket = vendor_ticket_info((int) $d['ticket_id']);
    vendor_after_commit('dispatch.note', $d, ['ticket' => $ticket, 'event_id' => $eventId, 'text' => $text], $actor);
    return ['ok' => true, 'dispatch' => $d, 'event_id' => $eventId];
}

/**
 * VOID a ledger row: a correction, never an edit. The void is a NEW row naming its target; both stay visible in
 * the history. A voided offer no longer consumes a turn, and the outcome rows that answered it stop counting too.
 * Allowed for the row's own actor within 15 minutes, or at any time for a manager (action.manage_vendors, decided
 * by the endpoint and passed as $canManage). A reason is required. Only dispatch-level rows can be voided
 * (list-level admin events are corrected by a further admin action, not erased).
 */
function vendor_dispatch_void(int $eventId, string $reason, array $actor, bool $canManage): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $reason = vendor_clean_str($reason, 255);
    if ($reason === null) return vendor_fail(422, 'reason_required', 'A reason is required to void an entry.');
    $a = vendor_actor_norm($actor);

    $owned = vendor_tx_begin();
    try {
        $target = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$eventId]);
        if (!$target || $target['dispatch_id'] === null) { vendor_tx_rollback($owned); return vendor_fail(404, 'event_not_found', 'That entry cannot be voided.'); }
        if ($target['event_type'] === 'voided') { vendor_tx_rollback($owned); return vendor_fail(409, 'already_voided', 'That entry is itself a void.'); }

        $d = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ? FOR UPDATE", [(int) $target['dispatch_id']]);
        if (!$d) { vendor_tx_rollback($owned); return vendor_fail(404, 'dispatch_not_found', 'Dispatch not found.'); }

        $already = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `event_type` = 'voided' AND `ref_event_id` = ?", [$eventId]);
        if ($already > 0) { vendor_tx_rollback($owned); return vendor_fail(409, 'already_voided', 'That entry was already voided.'); }

        if (!$canManage) {
            $own = $a['user_id'] !== null && (int) $target['actor_user_id'] === (int) $a['user_id'];
            $age = vendor_db_now_ts() - strtotime((string) $target['event_at']);
            if (!$own || $age > 900) {
                vendor_tx_rollback($owned);
                return vendor_fail(403, 'void_not_allowed', 'You can void only your own entries, within 15 minutes. Ask a supervisor to void this one.');
            }
        }

        $voidId = vendor_ledger_insert([
            'event_type' => 'voided', 'dispatch_id' => (int) $target['dispatch_id'], 'list_id' => $target['list_id'],
            'provider_id' => $target['provider_id'], 'provider_name' => (string) $target['provider_name'],
            'provider_phone' => $target['provider_phone'], 'reason' => $reason, 'ref_event_id' => $eventId,
            'detail' => 'Voided ' . $target['event_type'],
        ], $actor);
        vendor_dispatch_recompute_header((int) $target['dispatch_id']);
        vendor_tx_commit($owned);
    } catch (Throwable $e) {
        vendor_tx_rollback($owned);
        error_log('[vendor_dispatch_void] ' . $e->getMessage());
        return vendor_fail(500, 'internal', 'Could not void the entry.');
    }

    $row = vendor_dispatch_get((int) $target['dispatch_id']);
    $ticket = vendor_ticket_info((int) $row['ticket_id']);
    vendor_after_commit('dispatch.void', $row, [
        'ticket' => $ticket, 'event_id' => $voidId, 'target_id' => $eventId, 'target_type' => $target['event_type'],
        'provider_name' => (string) $target['provider_name'], 'reason' => $reason,
    ], $actor);
    return ['ok' => true, 'dispatch' => $row, 'event_id' => $voidId, 'voided_event_id' => $eventId];
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. After-commit side effects -- never inside the lock, never fatal
// ═══════════════════════════════════════════════════════════════════════════

/** "ETA 20 min (about 14:22)" */
function vendor_eta_text(?int $eta): string
{
    return $eta === null ? '' : ('ETA ' . $eta . ' min');
}

/** The sentence written onto the incident's own log for a dispatch event; null = no incident note for that kind. */
function vendor_incident_note_text(string $kind, array $dispatch, array $ctx): ?string
{
    $ref = vendor_ref_label((string) $dispatch['ticket_ref'], (int) $dispatch['ordinal']);
    $head = $dispatch['service_label'] . ' ref ' . $ref . ': ';
    $name = (string) ($ctx['provider_name'] ?? '');
    $at = date('H:i');
    switch ($kind) {
        case 'dispatch.offer':
            $how = ['rotation' => ' (next up)', 'override' => ' (override' . (!empty($ctx['reason']) ? ': ' . $ctx['reason'] : '') . ')',
                    'owner_request' => ' (requested by the driver/owner)', 'unlisted' => ' (not on a rotation list)'][$ctx['method'] ?? ''] ?? '';
            if (($ctx['method'] ?? '') === 'rotation' && ($ctx['expected_head'] ?? null) === null) $how = ' (manual pick)';
            return $head . 'called ' . $name . $how . ' ' . $at;
        case 'dispatch.outcome':
            $o = $ctx['outcome'] ?? '';
            if ($o === 'accepted') return $head . $name . ' accepted' . ($ctx['eta'] !== null ? ', ' . vendor_eta_text($ctx['eta']) : '');
            return $head . $name . ' ' . ['declined' => 'declined', 'no_answer' => 'did not answer', 'unavailable' => 'is unavailable'][$o];
        case 'dispatch.status':
            $e = $ctx['event'] ?? '';
            $map = ['on_scene' => 'on scene', 'completed' => 'completed', 'cancelled' => 'cancelled', 'goa' => 'gone on arrival',
                    'withdrew' => ($name !== '' ? $name . ' withdrew' : 'company withdrew'), 'eta_update' => 'ETA updated to ' . ($ctx['eta'] ?? '?') . ' min'];
            if (!isset($map[$e])) return null;
            return $head . (in_array($e, ['on_scene', 'goa'], true) && $name !== '' ? $name . ' ' : '') . $map[$e];
        case 'dispatch.void':
            return $head . 'entry voided (' . ($ctx['target_type'] ?? '') . ($name !== '' ? ' - ' . $name : '') . '): ' . ($ctx['reason'] ?? '');
        case 'dispatch.note':
            return $head . ($ctx['text'] ?? '');
    }
    return null;
}

/**
 * Incident note + audit row + SSE for a committed event. Each part is wrapped on its own: a logging or push
 * failure is logged and swallowed, it must never undo or fail the dispatch that already committed.
 */
function vendor_after_commit(string $kind, array $dispatch, array $ctx, array $actor): void
{
    $a = vendor_actor_norm($actor);
    $ticketId = (int) $dispatch['ticket_id'];
    $dispatchId = (int) $dispatch['id'];

    // 1. the incident's own log, so the log and ICS-214 timeline read as dispatchers expect
    try {
        $text = vendor_incident_note_text($kind, $dispatch, $ctx);
        if ($text !== null) {
            if (!function_exists('incident_add_note_internal') && is_file(__DIR__ . '/incident-write.php')) require_once __DIR__ . '/incident-write.php';
            if (function_exists('incident_add_note_internal')) {
                incident_add_note_internal($ticketId, $text, (int) ($a['user_id'] ?? 0));
            }
        }
    } catch (Throwable $e) { error_log('[vendor_after_commit note] ' . $e->getMessage()); }

    // 2. audit trail (the ledger is the durable record; audit-log retention may purge these rows)
    try {
        if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) require_once __DIR__ . '/audit.php';
        if (function_exists('audit_log')) {
            $ref = vendor_ref_label((string) $dispatch['ticket_ref'], (int) $dispatch['ordinal']);
            $sev = ($kind === 'dispatch.void') ? AUDIT_MEDIUM : AUDIT_INFO;
            $details = ['ticket_id' => $ticketId, 'ref' => $ref, 'status' => $dispatch['status']];
            foreach (['event_id', 'method', 'expected_head', 'reason', 'outcome', 'event', 'eta', 'target_id', 'target_type', 'changed', 'changes', 'consumed_turn', 'list_id'] as $k) {
                if (array_key_exists($k, $ctx) && $ctx[$k] !== null) $details[$k] = $ctx[$k];
            }
            if (!empty($ctx['provider_name'])) $details['provider'] = $ctx['provider_name'];
            audit_log('vendor', $kind, 'vendor_dispatch', $dispatchId, 'Vendor dispatch ' . $ref . ' ' . substr($kind, 9), $details, $sev);
        }
    } catch (Throwable $e) { error_log('[vendor_after_commit audit] ' . $e->getMessage()); }

    // 3. live refresh for the other dispatchers' open incident pages
    vendor_publish_dispatch_sse($ticketId, [
        'ticket_id' => $ticketId, 'dispatch_id' => $dispatchId, 'status' => (string) $dispatch['status'],
    ]);
}

/**
 * Publish vendor:dispatch to the organizations that can actually work this incident's dispatches: the incident's owning
 * organization and any organization holding an active ASSIST-tier share of it (a view-tier recipient cannot read
 * dispatches, so it is not told one changed). The event carries ids and a status, never names or numbers.
 * An incident with no owning organization (a single-agency install) falls back to the ordinary per-incident audience,
 * which api/stream.php limits to holders of action.dispatch_vendor. Never fatal.
 */
function vendor_publish_dispatch_sse(int $ticketId, array $payload): void
{
    try {
        if (!function_exists('sse_publish') && is_file(__DIR__ . '/sse.php')) require_once __DIR__ . '/sse.php';
        if (!function_exists('sse_publish')) return;
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $orgIds = [];
        $owner = (int) db_fetch_value(
            "SELECT `org_id` FROM `{$prefix}ticket` WHERE `id` = ? AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')", [$ticketId]);
        if ($owner > 0) $orgIds[] = $owner;
        if ($owner > 0) {
            try {
                foreach (db_fetch_all(
                    "SELECT DISTINCT `shared_with_org_id` FROM `{$prefix}incident_shares`
                      WHERE `ticket_id` = ? AND `revoked_at` IS NULL AND `access_tier` = 'assist'", [$ticketId]) as $r) {
                    if ((int) $r['shared_with_org_id'] > 0) $orgIds[] = (int) $r['shared_with_org_id'];
                }
            } catch (Throwable $e) { /* pre-Phase-141 install: no shares table */ }
        }
        if ($orgIds) {
            sse_publish('vendor:dispatch', $payload, null, 'entitled', array_values(array_unique($orgIds)));
        } elseif (function_exists('sse_publish_for_incident')) {
            sse_publish_for_incident('vendor:dispatch', $payload, $ticketId);
        }
    } catch (Throwable $e) { error_log('[vendor_publish_dispatch_sse] ' . $e->getMessage()); }
}

// ═══════════════════════════════════════════════════════════════════════════
// 6. Read helpers
// ═══════════════════════════════════════════════════════════════════════════

/**
 * A dispatch as the screens see it: header + a timeline (every ledger row, voided ones flagged) + the facts
 * the card needs (reference label, the calls still awaiting an outcome, ETA clock time, per-row can_void for THIS caller).
 */
function vendor_dispatch_view(array $d, array $actor = [], bool $canManage = false, bool $withTimeline = true): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $a = vendor_actor_norm($actor);
    $rows = vendor_dispatch_ledger_rows((int) $d['id']);
    $effective = vendor_ledger_effective_events($rows);
    $effIds = [];
    foreach ($effective as $e) $effIds[(int) $e['id']] = true;

    // Calls with no outcome: an effective `offered` row no effective outcome row points at.
    $answered = [];
    foreach ($effective as $e) {
        if (in_array($e['event_type'], ['accepted', 'declined', 'no_answer', 'unavailable'], true) && !empty($e['ref_event_id'])) {
            $answered[(int) $e['ref_event_id']] = true;
        }
    }
    $pending = [];
    $lastEtaAt = null;
    foreach ($effective as $e) {
        if ($e['event_type'] === 'offered' && !isset($answered[(int) $e['id']])) {
            $pending[] = ['event_id' => (int) $e['id'], 'provider_id' => $e['provider_id'] !== null ? (int) $e['provider_id'] : null,
                          'provider_name' => $e['provider_name'], 'provider_phone' => $e['provider_phone'], 'event_at' => $e['event_at']];
        }
        if (in_array($e['event_type'], ['accepted', 'eta_update'], true)) $lastEtaAt = $e['event_at'];
    }

    $etaClock = null;
    if ($d['eta_minutes'] !== null && $lastEtaAt !== null && in_array($d['status'], ['assigned', 'on_scene'], true)) {
        $ts = strtotime((string) $lastEtaAt);
        if ($ts) $etaClock = date('H:i', $ts + ((int) $d['eta_minutes']) * 60);
    }

    $destName = null;
    if ($d['dest_facility_id'] !== null) {
        try {
            $destName = db_fetch_value("SELECT `name` FROM `{$prefix}facilities` WHERE `id` = ?", [(int) $d['dest_facility_id']]);
        } catch (Throwable $e) { $destName = null; }
        if ($destName === false) $destName = null;
    }

    $out = [
        'id' => (int) $d['id'],
        'ref' => vendor_ref_label((string) $d['ticket_ref'], (int) $d['ordinal']),
        'service_type_id' => (int) $d['service_type_id'],
        'service_label' => (string) $d['service_label'],
        'list_id' => $d['list_id'] !== null ? (int) $d['list_id'] : null,
        'status' => (string) $d['status'],
        'vehicle_desc' => $d['vehicle_desc'], 'plate' => $d['plate'], 'plate_state' => $d['plate_state'],
        'tow_reason' => $d['tow_reason'],
        'dest_facility_id' => $d['dest_facility_id'] !== null ? (int) $d['dest_facility_id'] : null,
        'dest_facility_name' => $destName !== null ? (string) $destName : null,
        'dest_text' => $d['dest_text'],
        'provider_id' => $d['provider_id'] !== null ? (int) $d['provider_id'] : null,
        'provider_name' => $d['provider_name'], 'provider_phone' => $d['provider_phone'],
        'eta_minutes' => $d['eta_minutes'] !== null ? (int) $d['eta_minutes'] : null,
        'eta_clock' => $etaClock,
        'assigned_at' => $d['assigned_at'], 'closed_at' => $d['closed_at'],
        'notes' => $d['notes'], 'created_at' => $d['created_at'], 'created_by_name' => $d['created_by_name'],
        'pending' => $pending,
    ];
    if ($withTimeline) {
        $tl = [];
        $nowTs = vendor_db_now_ts();
        foreach ($rows as $r) {
            $age = $nowTs - strtotime((string) $r['event_at']);
            $own = $a['user_id'] !== null && (int) $r['actor_user_id'] === (int) $a['user_id'];
            $isVoid = ($r['event_type'] === 'voided');
            $struck = !$isVoid && !isset($effIds[(int) $r['id']]);
            $tl[] = [
                'id' => (int) $r['id'], 'event_type' => $r['event_type'], 'provider_name' => $r['provider_name'],
                'selection_method' => $r['selection_method'], 'consumed_turn' => (int) $r['consumed_turn'],
                'eta_minutes' => $r['eta_minutes'] !== null ? (int) $r['eta_minutes'] : null,
                'reason' => $r['reason'], 'detail' => $r['detail'],
                'event_at' => $r['event_at'], 'actor_name' => $r['actor_name'], 'voided' => $struck,
                'can_void' => !$isVoid && !$struck && ($canManage || ($own && $age <= 900)),
            ];
        }
        $out['events'] = $tl;
    }
    return $out;
}

/** Every dispatch of one incident, oldest first. */
function vendor_dispatches_for_ticket(int $ticketId, array $actor = [], bool $canManage = false): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` = ? ORDER BY `ordinal`", [$ticketId]);
    $out = [];
    foreach ($rows as $r) $out[] = vendor_dispatch_view($r, $actor, $canManage, true);
    return $out;
}

/**
 * The service types a dispatcher can pick, the active lists visible to the caller, and the lists' default flags.
 * @return array [service_types, lists]
 */
function vendor_dialog_lists(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $types = db_fetch_all(
        "SELECT `id`, `label`, `needs_destination` FROM `{$prefix}vendor_service_types` WHERE `is_active` = 1 ORDER BY `sort_order`, `id`");
    foreach ($types as $i => $t) {
        $types[$i] = ['id' => (int) $t['id'], 'label' => $t['label'], 'needs_destination' => (int) $t['needs_destination']];
    }
    [$frag, $vars] = vendor_org_frag('l.`org_id`');
    $lists = db_fetch_all(
        "SELECT l.`id`, l.`name`, l.`service_type_id`, l.`description`, l.`is_default`
           FROM `{$prefix}vendor_rotation_lists` l
          WHERE l.`is_active` = 1" . $frag . "
          ORDER BY l.`sort_order`, l.`name`, l.`id`", $vars);
    foreach ($lists as $i => $l) {
        $lists[$i] = ['id' => (int) $l['id'], 'name' => $l['name'], 'service_type_id' => (int) $l['service_type_id'],
                      'description' => $l['description'], 'is_default' => (int) $l['is_default']];
    }
    return [$types, $lists];
}

/**
 * Other active, visible providers a dispatcher may pick under "Other provider" (not tied to a list).
 */
function vendor_other_providers(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$frag, $vars] = vendor_org_frag('p.`org_id`');
    $rows = db_fetch_all(
        "SELECT p.`id`, p.`name`, p.`phone`, p.`contact_name`, p.`yard_facility_id`
           FROM `{$prefix}vendor_providers` p
          WHERE p.`is_active` = 1" . $frag . "
          ORDER BY p.`name`, p.`id`", $vars);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'contact_name' => $r['contact_name'],
                  'yard_facility_id' => $r['yard_facility_id'] !== null ? (int) $r['yard_facility_id'] : null];
    }
    return $out;
}

/**
 * The history: every ledger row the caller may see (dispatch-level rows through the dispatch's org, list-level
 * rows through the list's org), newest first, with the dispatch reference and the list name, voided rows and their
 * targets flagged. Filters: list_id, provider_id, dispatch_id, date_from, date_to (Y-m-d), overrides_only.
 * @return array ['events' => [...], 'summary' => [per provider...], 'truncated' => bool]
 */
function vendor_history_query(array $filters, int $limit = 1000): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$dFrag, $dVars] = vendor_org_frag('d.`org_id`');
    [$lFrag, $lVars] = vendor_org_frag('l.`org_id`');
    $dCond = $dFrag === '' ? '1=1' : preg_replace('/^\s*AND\s*/i', '', $dFrag);
    $lCond = $lFrag === '' ? '1=1' : preg_replace('/^\s*AND\s*/i', '', $lFrag);

    $where = ["((g.`dispatch_id` IS NOT NULL AND ({$dCond})) OR (g.`dispatch_id` IS NULL AND ({$lCond})))"];
    $params = array_merge($dVars, $lVars);

    if (!empty($filters['list_id']))     { $where[] = 'g.`list_id` = ?';     $params[] = (int) $filters['list_id']; }
    if (!empty($filters['provider_id'])) { $where[] = 'g.`provider_id` = ?'; $params[] = (int) $filters['provider_id']; }
    if (!empty($filters['dispatch_id'])) { $where[] = 'g.`dispatch_id` = ?'; $params[] = (int) $filters['dispatch_id']; }
    if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['date_from'])) {
        $where[] = 'g.`event_at` >= ?'; $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['date_to'])) {
        $where[] = 'g.`event_at` <= ?'; $params[] = $filters['date_to'] . ' 23:59:59';
    }
    // Applied in SQL, BEFORE the LIMIT: filtering after it would silently drop older overrides from a busy history and
    // leave `truncated` describing a different set than the one the supervisor asked for.
    if (!empty($filters['overrides_only'])) { $where[] = "g.`event_type` = 'offered' AND g.`selection_method` = 'override'"; }
    $limit = max(1, min(5000, $limit));

    $rows = db_fetch_all(
        "SELECT g.*, d.`ticket_id`, d.`ticket_ref`, d.`ordinal`, d.`service_label`, l.`name` AS `list_name`,
                hp.`name` AS `expected_head_name`
           FROM `{$prefix}vendor_dispatch_ledger` g
           LEFT JOIN `{$prefix}vendor_dispatches` d ON d.`id` = g.`dispatch_id`
           LEFT JOIN `{$prefix}vendor_rotation_lists` l ON l.`id` = g.`list_id`
           LEFT JOIN `{$prefix}vendor_providers` hp ON hp.`id` = g.`expected_head_provider_id`
          WHERE " . implode(' AND ', $where) . "
          ORDER BY g.`id` DESC LIMIT " . ($limit + 1), $params);
    $truncated = count($rows) > $limit;
    if ($truncated) array_pop($rows);

    // Flag voided targets (a void row's own target, and the answers to a voided offer). The void rows may be
    // outside the filter window, so ask the database about exactly the targets in this result.
    $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
    $refs = [];
    foreach ($rows as $r) if (!empty($r['ref_event_id'])) $refs[(int) $r['ref_event_id']] = true;
    $voidedSet = [];
    $probe = array_values(array_unique(array_merge($ids, array_keys($refs))));
    foreach (array_chunk($probe, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (db_fetch_all("SELECT `ref_event_id` FROM `{$prefix}vendor_dispatch_ledger` WHERE `event_type` = 'voided' AND `ref_event_id` IN ($ph)", $chunk) as $v) {
            $voidedSet[(int) $v['ref_event_id']] = true;
        }
    }
    // "No outcome recorded": an offer that is still standing and that no standing outcome answers. Dispatchers forget to
    // log outcomes (spec risk 2), so the history says so plainly instead of leaving it to be inferred from silence.
    $offerIds = [];
    foreach ($rows as $r) if ($r['event_type'] === 'offered') $offerIds[] = (int) $r['id'];
    $answered = [];
    foreach (array_chunk($offerIds, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $outs = db_fetch_all(
            "SELECT `id`, `ref_event_id` FROM `{$prefix}vendor_dispatch_ledger`
              WHERE `event_type` IN ('accepted','declined','no_answer','unavailable') AND `ref_event_id` IN ($ph)", $chunk);
        if (!$outs) continue;
        $outIds = array_map(function ($o) { return (int) $o['id']; }, $outs);
        $ph2 = implode(',', array_fill(0, count($outIds), '?'));
        $voidedOuts = [];
        foreach (db_fetch_all("SELECT `ref_event_id` FROM `{$prefix}vendor_dispatch_ledger` WHERE `event_type` = 'voided' AND `ref_event_id` IN ($ph2)", $outIds) as $v) {
            $voidedOuts[(int) $v['ref_event_id']] = true;
        }
        foreach ($outs as $o) { if (!isset($voidedOuts[(int) $o['id']])) $answered[(int) $o['ref_event_id']] = true; }
    }
    $events = [];
    foreach ($rows as $r) {
        $isVoidRow = $r['event_type'] === 'voided';
        $struck = !$isVoidRow && (isset($voidedSet[(int) $r['id']]) || (!empty($r['ref_event_id']) && isset($voidedSet[(int) $r['ref_event_id']])));
        $r['voided'] = $struck;
        $r['no_outcome'] = ($r['event_type'] === 'offered' && !$struck && !isset($answered[(int) $r['id']]));
        $events[] = $r;
    }

    // Per-company summary. Aggregated BY THE DATABASE over every effective row the filters match -- not over the (limited)
    // rows fetched for display, which would make the fairness view wrong exactly when the history is long. "Effective"
    // = not a void row, not voided itself, and not an outcome whose offer was voided (the same rule as
    // vendor_ledger_effective_events()). Companies are grouped by id, or by lower-cased name when typed in unlisted.
    $effective = "g.`event_type` <> 'voided' AND g.`provider_name` <> ''
        AND NOT EXISTS (SELECT 1 FROM `{$prefix}vendor_dispatch_ledger` v WHERE v.`event_type` = 'voided' AND v.`ref_event_id` = g.`id`)
        AND (g.`ref_event_id` IS NULL OR NOT EXISTS
             (SELECT 1 FROM `{$prefix}vendor_dispatch_ledger` v2 WHERE v2.`event_type` = 'voided' AND v2.`ref_event_id` = g.`ref_event_id`))";
    $agg = db_fetch_all(
        "SELECT IF(g.`provider_id` IS NULL, CONCAT('n', LOWER(g.`provider_name`)), CONCAT('p', g.`provider_id`)) AS `k`,
                MAX(g.`id`) AS `last_id`,
                SUM(g.`event_type` = 'offered') AS `offered`,
                SUM(g.`event_type` = 'accepted') AS `accepted`,
                SUM(g.`event_type` = 'declined') AS `declined`,
                SUM(g.`event_type` = 'no_answer') AS `no_answer`,
                SUM(g.`event_type` = 'unavailable') AS `unavailable`,
                SUM(g.`event_type` = 'offered' AND g.`selection_method` = 'override') AS `overrides`,
                SUM(g.`event_type` = 'offered' AND g.`selection_method` = 'owner_request') AS `owner_requests`,
                MAX(CASE WHEN g.`event_type` = 'offered' THEN g.`event_at` END) AS `last_offer_at`
           FROM `{$prefix}vendor_dispatch_ledger` g
           LEFT JOIN `{$prefix}vendor_dispatches` d ON d.`id` = g.`dispatch_id`
           LEFT JOIN `{$prefix}vendor_rotation_lists` l ON l.`id` = g.`list_id`
          WHERE " . implode(' AND ', $where) . " AND " . $effective . "
          GROUP BY `k`", $params);
    $names = [];
    if ($agg) {
        $lastIds = array_map(function ($a) { return (int) $a['last_id']; }, $agg);
        foreach (array_chunk($lastIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach (db_fetch_all("SELECT `id`, `provider_name` FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` IN ($ph)", $chunk) as $n) {
                $names[(int) $n['id']] = (string) $n['provider_name'];
            }
        }
    }
    $summary = [];
    foreach ($agg as $a) {
        $isId = strpos((string) $a['k'], 'p') === 0;
        $summary[] = [
            'provider_id' => $isId ? (int) substr((string) $a['k'], 1) : null,
            'provider_name' => $names[(int) $a['last_id']] ?? '',
            'offered' => (int) $a['offered'], 'accepted' => (int) $a['accepted'], 'declined' => (int) $a['declined'],
            'no_answer' => (int) $a['no_answer'], 'unavailable' => (int) $a['unavailable'],
            'overrides' => (int) $a['overrides'], 'owner_requests' => (int) $a['owner_requests'],
            'last_offer_at' => $a['last_offer_at'],
        ];
    }
    usort($summary, function ($a, $b) { return strcasecmp((string) $a['provider_name'], (string) $b['provider_name']); });
    return ['events' => $events, 'summary' => $summary, 'truncated' => $truncated];
}

/** The CSV header and one row per ledger event, every cell guarded against spreadsheet formula injection. */
function vendor_history_csv_header(): array
{
    return ['event_id', 'event_at', 'dispatch_ref', 'service', 'list', 'event_type', 'company', 'company_phone',
            'selection_method', 'consumed_turn', 'expected_next_up', 'eta_minutes', 'reason', 'detail',
            'references_event_id', 'voided', 'no_outcome_recorded', 'actor', 'actor_ip'];
}

function vendor_history_csv_row(array $r): array
{
    $ref = $r['ticket_ref'] !== null && $r['ordinal'] !== null ? vendor_ref_label((string) $r['ticket_ref'], (int) $r['ordinal']) : '';
    $cells = [
        $r['id'], $r['event_at'], $ref, $r['service_label'] ?? '', $r['list_name'] ?? '', $r['event_type'],
        $r['provider_name'], $r['provider_phone'] ?? '', $r['selection_method'] ?? '', $r['consumed_turn'],
        $r['expected_head_name'] ?? '', $r['eta_minutes'] ?? '', $r['reason'] ?? '', $r['detail'] ?? '',
        $r['ref_event_id'] ?? '', !empty($r['voided']) ? 'yes' : 'no', !empty($r['no_outcome']) ? 'yes' : '', $r['actor_name'], $r['actor_ip'] ?? '',
    ];
    return array_map('vendor_csv_cell', $cells);
}
