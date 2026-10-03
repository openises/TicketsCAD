<?php
/**
 * Phase 155 (GH#145) - the ONE place an email distribution list is turned into
 * a set of deliverable addresses.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Before this file there were two copies of "expand a list", and they had
 * drifted apart:
 *
 *   api/email-lists.php   `resolve` - a correct-ish expander that NOTHING called
 *   inc/notification-engine.php - the ONLY real consumer, which ran
 *       SELECT `email` FROM email_list_members
 *     against a table that has no `email` column (it has `inline_email`), with
 *     an EMPTY catch whose comment said "Email lists table may not exist" - a
 *     cause nobody ever checked. So a notification rule that named a list
 *     resolved ZERO recipients, for inline rows too, and nothing said so.
 *
 * Now the modal's summary banner, the list table's counts, the `resolve`
 * action, and the notification engine all call email_list_expand(), so what an
 * administrator sees is what a rule will send.
 *
 * SHAPE
 * -----
 *   email_list_split_addresses()  PURE  "a@x.org, b@x.org" -> valid + invalid tokens
 *   email_list_options()          DB    the two settings (uncached - see below)
 *   email_list_load_graph()       DB    lists + entries + the member/constituent
 *                                       rows they reference, in bounded queries
 *   email_list_expand()           PURE  graph in, recipients + per-entry status out
 *   email_list_find_cycle_path()  PURE  would adding X under Y make a loop?
 *   email_list_resolve()          DB    load_graph + expand, for callers that
 *                                       only have a list id
 *
 * Every entry in the root list gets a STATUS code (tests assert the codes; the
 * UI maps them to words plus an icon - never colour alone). A silent dead
 * recipient is a bug: a member with no email must show as "no_email", a retired
 * member as "status_skipped", never just vanish from the result.
 *
 * SETTINGS ARE READ UNCACHED
 * --------------------------
 * email_list_options() runs a direct SELECT, deliberately NOT get_variable().
 * get_variable() loads the whole settings table into a static on first use and
 * never invalidates it (the Phase 151 test pitfall), so a change an admin just
 * saved would not take effect inside the same PHP process. These two settings
 * are read once per resolve, not per row, so the extra query is cheap.
 */

declare(strict_types=1);

/** Safety cap against corrupt data. Not a setting on purpose: cycles are rejected at add time. */
if (!defined('EMAIL_LIST_MAX_DEPTH')) {
    define('EMAIL_LIST_MAX_DEPTH', 10);
}

/** Rows per IN (...) chunk when the loader fetches referenced members/constituents. */
if (!defined('EMAIL_LIST_CHUNK')) {
    define('EMAIL_LIST_CHUNK', 500);
}

/** Setting names (settings table, name/value). */
if (!defined('EMAIL_LIST_SETTING_SKIP_STATUSES')) {
    define('EMAIL_LIST_SETTING_SKIP_STATUSES', 'email_list_skip_member_statuses');
}
if (!defined('EMAIL_LIST_SETTING_REQUIRE_EMAIL')) {
    define('EMAIL_LIST_SETTING_REQUIRE_EMAIL', 'email_list_require_email_on_add');
}

// ─────────────────────────────────────────────────────────────────────────
// Status vocabulary (stable codes)
// ─────────────────────────────────────────────────────────────────────────

/** Statuses that mean "an administrator should fix something". */
function email_list_problem_statuses(): array
{
    return ['no_email', 'invalid_email', 'member_deleted', 'missing_ref',
            'sub_list_archived', 'cycle', 'too_deep', 'partial', 'empty'];
}

/** Statuses that are an explained policy decision, not a defect. */
function email_list_policy_statuses(): array
{
    return ['status_skipped', 'opted_out'];
}

/** Human-readable word for each status code (the UI adds an icon). */
function email_list_status_labels(): array
{
    return [
        'ok'                => 'OK',
        'no_email'          => 'No email address on file',
        'invalid_email'     => 'Not a valid email address',
        'member_deleted'    => 'Member was deleted',
        'missing_ref'       => 'Record no longer exists',
        'sub_list_archived' => 'Sub-list is archived',
        'cycle'             => 'Loop in nested lists',
        'too_deep'          => 'Nested too deeply',
        'partial'           => 'Some entries inside have problems',
        'empty'             => 'Contributes no addresses',
        'status_skipped'    => 'Skipped (member status)',
        'opted_out'         => 'Opted out of email',
        'duplicate'         => 'Duplicate (already provided)',
    ];
}

// ─────────────────────────────────────────────────────────────────────────
// Pure helpers
// ─────────────────────────────────────────────────────────────────────────

/**
 * Split a free-text field into addresses.
 *
 * `member.email` and `constituents.email` are ONE varchar each, so "several
 * addresses" can only ever mean several typed into that one field (comma,
 * semicolon or whitespace separated). The old code treated the whole string as
 * a single - invalid - address.
 *
 * FILTER_VALIDATE_EMAIL rejects CR/LF, which is also what keeps an entry from
 * smuggling an SMTP command into a recipient later (the smtp channel writes the
 * address into RCPT TO).
 *
 * @return array{valid:string[],invalid:string[]} valid addresses in first-seen
 *         order, de-duplicated case-insensitively; invalid = the tokens that
 *         were not addresses.
 */
function email_list_split_addresses(string $raw): array
{
    $valid = [];
    $invalid = [];
    $seen = [];
    $tokens = preg_split('/[,;\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === false) $tokens = [];
    foreach ($tokens as $tok) {
        // Strip a "<...>" wrapper and surrounding quotes some people paste.
        $t = trim($tok, " \t\"'<>()");
        if ($t === '') continue;
        if (strlen($t) <= 254 && filter_var($t, FILTER_VALIDATE_EMAIL) !== false) {
            $key = strtolower($t);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $valid[] = $t;
            }
        } else {
            $invalid[] = substr($tok, 0, 80);
        }
    }
    return ['valid' => $valid, 'invalid' => $invalid];
}

/**
 * Is this deleted_at value a real soft-delete? (legacy rows carry the zero date)
 */
function email_list_is_deleted($deletedAt): bool
{
    if ($deletedAt === null || $deletedAt === false) return false;
    $s = (string) $deletedAt;
    return $s !== '' && strpos($s, '0000-00-00') !== 0;
}

/**
 * A recipient's display name: an entry's own non-empty display_name wins over
 * the natural name. (The old code used `??`, which keeps an EMPTY STRING and so
 * masked the natural name.)
 */
function _email_list_pick_name(?string $override, ?string $natural, ?string $fallback = null): ?string
{
    $o = $override !== null ? trim($override) : '';
    if ($o !== '') return $o;
    $n = $natural !== null ? trim($natural) : '';
    if ($n !== '') return $n;
    $f = $fallback !== null ? trim($fallback) : '';
    return $f !== '' ? $f : null;
}

// ─────────────────────────────────────────────────────────────────────────
// Options
// ─────────────────────────────────────────────────────────────────────────

/**
 * The two administrator-configurable list options.
 *
 * @return array{skip_statuses:string[],require_email_on_add:bool}
 *         skip_statuses are lower-cased member_status labels.
 */
function email_list_options(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $out = ['skip_statuses' => [], 'require_email_on_add' => false];
    try {
        $rows = db_fetch_all(
            "SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` IN (?, ?)",
            [EMAIL_LIST_SETTING_SKIP_STATUSES, EMAIL_LIST_SETTING_REQUIRE_EMAIL]
        );
    } catch (Throwable $e) {
        error_log('[email-lists] options read failed: ' . $e->getMessage());
        return $out;
    }
    foreach ($rows as $r) {
        if ($r['name'] === EMAIL_LIST_SETTING_SKIP_STATUSES) {
            $dec = json_decode((string) $r['value'], true);
            if (is_array($dec)) {
                $labels = [];
                foreach ($dec as $l) {
                    $l = strtolower(trim((string) $l));
                    if ($l !== '') $labels[$l] = true;
                }
                $out['skip_statuses'] = array_keys($labels);
            }
        } elseif ($r['name'] === EMAIL_LIST_SETTING_REQUIRE_EMAIL) {
            $out['require_email_on_add'] = ((string) $r['value'] === '1');
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Loader
// ─────────────────────────────────────────────────────────────────────────

/** @return string "?,?,?" for a non-empty id list (placeholders only, never values). */
function _email_list_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

/**
 * Load the data the expander needs.
 *
 * $rootId = null  -> every list (admin views: the table's counts).
 * $rootId = N     -> only the lists reachable from N (the engine path, so a
 *                    send reads a handful of rows, not the whole install).
 *
 * Members and constituents are fetched ONLY for the ids the entries actually
 * reference, in IN (...) chunks - an install can hold thousands of constituents
 * (every unseen inbound caller becomes one), and shipping them wholesale to a
 * browser or into memory is wrong on both privacy and size.
 *
 * The member query selects deleted_at ON PURPOSE so a soft-deleted volunteer is
 * REPORTED ("member_deleted"), not silently absent.
 *
 * Never throws. If the list tables are missing the result carries
 * 'missing_tables' => true so a caller can say so instead of presenting an
 * empty list that reads as "no lists".
 *
 * @return array{
 *   missing_tables:bool,
 *   lists:array<int,array{id:int,name:string,slug:string,archived:bool}>,
 *   entries:array<int,array<int,array>>,
 *   members:array<int,array>,
 *   constituents:array<int,array>
 * }
 */
function email_list_load_graph(?int $rootId = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $graph = ['missing_tables' => false, 'lists' => [], 'entries' => [],
              'members' => [], 'constituents' => []];

    try {
        if ($rootId === null) {
            $lists = db_fetch_all(
                "SELECT `id`, `name`, `slug`, `archived_at` FROM `{$prefix}email_lists`");
            foreach ($lists as $l) {
                $graph['lists'][(int) $l['id']] = [
                    'id' => (int) $l['id'], 'name' => (string) $l['name'],
                    'slug' => (string) $l['slug'], 'archived' => $l['archived_at'] !== null,
                ];
            }
            $ids = array_keys($graph['lists']);
            foreach (array_chunk($ids, EMAIL_LIST_CHUNK) as $chunk) {
                $rows = db_fetch_all(
                    "SELECT `id`, `list_id`, `member_type`, `ref_id`, `inline_email`,
                            `display_name`, `added_by`, `added_at`
                       FROM `{$prefix}email_list_members`
                      WHERE `list_id` IN (" . _email_list_placeholders($chunk) . ")
                      ORDER BY `id`", $chunk);
                foreach ($rows as $r) {
                    $graph['entries'][(int) $r['list_id']][] = _email_list_norm_entry($r);
                }
            }
        } else {
            // Breadth-first over sub-list references.
            $frontier = [$rootId];
            $loaded = [];
            $guard = 0;
            while ($frontier && $guard++ < 50) {
                $frontier = array_values(array_diff(array_unique($frontier), $loaded));
                if (!$frontier) break;
                $next = [];
                foreach (array_chunk($frontier, EMAIL_LIST_CHUNK) as $chunk) {
                    $lists = db_fetch_all(
                        "SELECT `id`, `name`, `slug`, `archived_at` FROM `{$prefix}email_lists`
                          WHERE `id` IN (" . _email_list_placeholders($chunk) . ")", $chunk);
                    foreach ($lists as $l) {
                        $graph['lists'][(int) $l['id']] = [
                            'id' => (int) $l['id'], 'name' => (string) $l['name'],
                            'slug' => (string) $l['slug'], 'archived' => $l['archived_at'] !== null,
                        ];
                    }
                    $rows = db_fetch_all(
                        "SELECT `id`, `list_id`, `member_type`, `ref_id`, `inline_email`,
                                `display_name`, `added_by`, `added_at`
                           FROM `{$prefix}email_list_members`
                          WHERE `list_id` IN (" . _email_list_placeholders($chunk) . ")
                          ORDER BY `id`", $chunk);
                    foreach ($rows as $r) {
                        $e = _email_list_norm_entry($r);
                        $graph['entries'][$e['list_id']][] = $e;
                        if ($e['member_type'] === 'list' && $e['ref_id'] > 0) $next[] = $e['ref_id'];
                    }
                }
                $loaded = array_merge($loaded, $frontier);
                $frontier = $next;
            }
        }
    } catch (Throwable $e) {
        // 42S02 = base table or view not found. Anything else is a genuine
        // failure; log it (a silent catch is what hid the original bug) but
        // still hand back an empty graph so a send path never fatals.
        $graph['missing_tables'] = (strpos($e->getMessage(), '42S02') !== false
            || stripos($e->getMessage(), "doesn't exist") !== false);
        error_log('[email-lists] graph load failed: ' . $e->getMessage());
        return $graph;
    }

    // Referenced member / constituent ids.
    $memberIds = [];
    $constIds = [];
    foreach ($graph['entries'] as $entries) {
        foreach ($entries as $e) {
            if ($e['ref_id'] <= 0) continue;
            if ($e['member_type'] === 'member') $memberIds[$e['ref_id']] = true;
            elseif ($e['member_type'] === 'constituent') $constIds[$e['ref_id']] = true;
        }
    }

    if ($memberIds) $graph['members'] = _email_list_load_members(array_keys($memberIds));
    if ($constIds) {
        foreach (array_chunk(array_keys($constIds), EMAIL_LIST_CHUNK) as $chunk) {
            try {
                $rows = db_fetch_all(
                    "SELECT `id`, `contact`, `email` FROM `{$prefix}constituents`
                      WHERE `id` IN (" . _email_list_placeholders($chunk) . ")", $chunk);
            } catch (Throwable $e) {
                error_log('[email-lists] constituent load failed: ' . $e->getMessage());
                continue;
            }
            foreach ($rows as $r) {
                $graph['constituents'][(int) $r['id']] = [
                    'id' => (int) $r['id'],
                    'name' => (string) ($r['contact'] ?? ''),
                    'email' => (string) ($r['email'] ?? ''),
                ];
            }
        }
    }
    return $graph;
}

function _email_list_norm_entry(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'list_id' => (int) $r['list_id'],
        'member_type' => (string) $r['member_type'],
        'ref_id' => isset($r['ref_id']) ? (int) $r['ref_id'] : 0,
        'inline_email' => (string) ($r['inline_email'] ?? ''),
        'display_name' => isset($r['display_name']) ? (string) $r['display_name'] : '',
        'added_by' => isset($r['added_by']) ? (int) $r['added_by'] : 0,
        'added_at' => (string) ($r['added_at'] ?? ''),
    ];
}

/**
 * Member rows by id: name, callsign, email, deleted flag, status LABEL, user id,
 * and whether the linked user has switched email off.
 *
 * Schema-resilient: deleted_at / member_status_id / user_id are absent on very
 * old installs, so each optional piece is its own query that may fail alone.
 */
function _email_list_load_members(array $ids): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $out = [];

    // Status label map (id -> label). member_status has no unique key and, on
    // real installs, DUPLICATE labels - so everything downstream keys on the
    // LABEL, never the id.
    $statusLabel = [];
    try {
        foreach (db_fetch_all("SELECT `id`, `status_val` FROM `{$prefix}member_status`") as $s) {
            $statusLabel[(int) $s['id']] = (string) $s['status_val'];
        }
    } catch (Throwable $e) { /* no member_status table: no status policy possible */ }

    foreach (array_chunk($ids, EMAIL_LIST_CHUNK) as $chunk) {
        $in = _email_list_placeholders($chunk);
        $rows = null;
        $sqls = [
            // current schema
            "SELECT `id`, `first_name`, `last_name`, `callsign`, `email`, `deleted_at`,
                    `user_id`, `member_status_id`
               FROM `{$prefix}member` WHERE `id` IN ({$in})",
            // pre-wastebasket (no deleted_at)
            "SELECT `id`, `first_name`, `last_name`, `callsign`, `email`,
                    NULL AS `deleted_at`, `user_id`, `member_status_id`
               FROM `{$prefix}member` WHERE `id` IN ({$in})",
            // very old: no user link / status id
            "SELECT `id`, `first_name`, `last_name`, `callsign`, `email`,
                    NULL AS `deleted_at`, NULL AS `user_id`, NULL AS `member_status_id`
               FROM `{$prefix}member` WHERE `id` IN ({$in})",
        ];
        foreach ($sqls as $sql) {
            try { $rows = db_fetch_all($sql, $chunk); break; }
            catch (Throwable $e) { $rows = null; }
        }
        if ($rows === null) {
            error_log('[email-lists] member load failed on every schema variant');
            continue;
        }
        foreach ($rows as $r) {
            $name = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
            $sid = isset($r['member_status_id']) ? (int) $r['member_status_id'] : 0;
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'name' => $name,
                'callsign' => (string) ($r['callsign'] ?? ''),
                'email' => (string) ($r['email'] ?? ''),
                'deleted' => email_list_is_deleted($r['deleted_at'] ?? null),
                'user_id' => !empty($r['user_id']) ? (int) $r['user_id'] : 0,
                'status' => ($sid > 0 && isset($statusLabel[$sid])) ? $statusLabel[$sid] : null,
                'opted_out' => false,
            ];
        }
    }

    // Per-user email opt-out (notification_preferences.channel_email = 0). The
    // only opt-out concept in the product; an absent row means "not opted out".
    $userIds = [];
    foreach ($out as $m) if ($m['user_id'] > 0) $userIds[$m['user_id']] = true;
    if ($userIds) {
        foreach (array_chunk(array_keys($userIds), EMAIL_LIST_CHUNK) as $chunk) {
            try {
                $rows = db_fetch_all(
                    "SELECT `user_id` FROM `{$prefix}notification_preferences`
                      WHERE `channel_email` = 0 AND `user_id` IN (" . _email_list_placeholders($chunk) . ")",
                    $chunk);
            } catch (Throwable $e) { continue; /* table absent: nobody has opted out */ }
            $off = [];
            foreach ($rows as $r) $off[(int) $r['user_id']] = true;
            foreach ($out as $id => $m) {
                if ($m['user_id'] > 0 && isset($off[$m['user_id']])) $out[$id]['opted_out'] = true;
            }
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Expander (PURE)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Expand one list into recipients, with a status for every entry.
 *
 * PURE: takes the graph from email_list_load_graph(), touches no database, so
 * tests can drive it with synthetic graphs.
 *
 * $opts:
 *   'skip_statuses'       string[]  lower-cased member_status labels to skip
 *                                   (default: whatever email_list_options() says
 *                                   is NOT consulted here - pass it in; the
 *                                   convenience wrapper email_list_resolve()
 *                                   fills it from settings)
 *   'honor_email_opt_out' bool      default true. false = opted-out members are
 *                                   kept and carry opted_out => true, for a
 *                                   caller (the notification engine) that
 *                                   applies its OWN preference gate and needs to
 *                                   bypass it for high-alert incidents.
 *
 * @return array{
 *   recipients:array<int,array>,
 *   entries:array<int,array>,
 *   summary:array
 * }
 */
function email_list_expand(array $graph, int $rootId, array $opts = []): array
{
    $skip = [];
    foreach ((array) ($opts['skip_statuses'] ?? []) as $l) {
        $l = strtolower(trim((string) $l));
        if ($l !== '') $skip[$l] = true;
    }
    $honorOptOut = array_key_exists('honor_email_opt_out', $opts)
        ? (bool) $opts['honor_email_opt_out'] : true;

    $state = [
        'graph' => $graph, 'skip' => $skip, 'honor' => $honorOptOut,
        'recipients' => [],   // lower-case address => recipient
        'order' => [],        // addresses in first-seen order
        'expanded' => [],     // list id => true once fully expanded (diamond guard)
        'owner' => [],        // list id => root entry id that first provided it
    ];

    $entriesOut = [];
    $rootEntries = $graph['entries'][$rootId] ?? [];
    foreach ($rootEntries as $e) {
        $entriesOut[$e['id']] = _email_list_expand_entry($state, $e, [$rootId], 1, $e['id']);
    }

    $recipients = [];
    foreach ($state['order'] as $k) $recipients[] = $state['recipients'][$k];

    $byStatus = [];
    $problems = 0; $policy = 0; $dups = 0;
    $problemSet = array_flip(email_list_problem_statuses());
    $policySet = array_flip(email_list_policy_statuses());
    foreach ($entriesOut as $eo) {
        $s = $eo['status'];
        $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
        if (isset($problemSet[$s])) $problems++;
        elseif (isset($policySet[$s])) $policy++;
        elseif ($s === 'duplicate') $dups++;
    }

    return [
        'recipients' => $recipients,
        'entries' => $entriesOut,
        'summary' => [
            'entries' => count($entriesOut),
            'unique_addresses' => count($recipients),
            'problems' => $problems,
            'policy_excluded' => $policy,
            'duplicates' => $dups,
            'by_status' => $byStatus,
        ],
    ];
}

/**
 * Expand a single entry; returns its status record and adds its addresses to
 * $state. $path = list ids from the root down to the list that CONTAINS this
 * entry (cycle detection); $rootEntryId = the root-level entry this is under.
 *
 * @return array{status:string,detail:?string,addresses:string[],contributes:int,problems_inside:int}
 */
function _email_list_expand_entry(array &$state, array $e, array $path, int $depth, int $rootEntryId): array
{
    $rec = static function (string $status, ?string $detail = null, array $addrs = [], int $contrib = 0, int $inside = 0): array {
        return ['status' => $status, 'detail' => $detail, 'addresses' => $addrs,
                'contributes' => $contrib, 'problems_inside' => $inside];
    };

    $type = $e['member_type'];
    $override = $e['display_name'] !== '' ? $e['display_name'] : null;

    if ($type === 'list') {
        return _email_list_expand_sublist($state, $e, $path, $depth, $rootEntryId);
    }

    $natural = null; $raw = ''; $userId = 0; $optedOut = false; $label = '';

    if ($type === 'inline') {
        $raw = $e['inline_email'];
    } elseif ($type === 'member') {
        $m = $state['graph']['members'][$e['ref_id']] ?? null;
        if ($m === null) return $rec('missing_ref', 'member #' . $e['ref_id'] . ' no longer exists');
        if (!empty($m['deleted'])) return $rec('member_deleted', 'member was deleted from the roster');
        $stat = $m['status'] ?? null;
        if ($stat !== null && isset($state['skip'][strtolower($stat)])) {
            return $rec('status_skipped', 'member status "' . $stat . '" is excluded from lists');
        }
        $natural = $m['name'] !== '' ? $m['name'] : null;
        $label = $m['name'] !== '' ? $m['name'] : $m['callsign'];
        $raw = (string) $m['email'];
        $userId = (int) ($m['user_id'] ?? 0);
        $optedOut = !empty($m['opted_out']);
        if ($optedOut && $state['honor']) {
            return $rec('opted_out', 'the member\'s account has email notifications switched off');
        }
        if ($natural === null && !empty($m['callsign'])) $natural = $m['callsign'];
    } elseif ($type === 'constituent') {
        $c = $state['graph']['constituents'][$e['ref_id']] ?? null;
        if ($c === null) return $rec('missing_ref', 'contact #' . $e['ref_id'] . ' no longer exists');
        $natural = $c['name'] !== '' ? $c['name'] : null;
        $raw = (string) $c['email'];
    } else {
        return $rec('missing_ref', 'unknown entry type "' . $type . '"');
    }

    if (trim($raw) === '') {
        return $rec('no_email', null);
    }
    $split = email_list_split_addresses($raw);
    if (!$split['valid']) {
        return $rec('invalid_email', 'text on file: ' . substr(trim($raw), 0, 80));
    }

    $name = _email_list_pick_name($override, $natural, $label);
    $added = []; $dupWinner = null;
    foreach ($split['valid'] as $addr) {
        $k = strtolower($addr);
        if (isset($state['recipients'][$k])) {
            $dupWinner = $dupWinner ?? $state['recipients'][$k]['via'][0];
            // A later entry that knows the user id lets the engine apply that
            // user's preferences to an address first seen as a bare inline one.
            if ($userId > 0 && empty($state['recipients'][$k]['user_id'])) {
                $state['recipients'][$k]['user_id'] = $userId;
                $state['recipients'][$k]['opted_out'] = $optedOut;
            }
            continue;
        }
        $state['recipients'][$k] = [
            'address' => $addr, 'name' => $name,
            'user_id' => $userId > 0 ? $userId : null,
            'opted_out' => $optedOut, 'via' => [$rootEntryId],
        ];
        $state['order'][] = $k;
        $added[] = $addr;
    }
    if (!$added) {
        return $rec('duplicate', $dupWinner !== null ? 'already provided by entry #' . $dupWinner : null, [], 0);
    }
    $detail = null;
    if ($split['invalid']) $detail = 'ignored text: ' . implode(', ', array_slice($split['invalid'], 0, 3));
    return $rec('ok', $detail, $added, count($added));
}

/** Sub-list entry: recursive, cycle- and depth-guarded, diamond-safe. */
function _email_list_expand_sublist(array &$state, array $e, array $path, int $depth, int $rootEntryId): array
{
    $rec = static function (string $status, ?string $detail = null, array $addrs = [], int $contrib = 0, int $inside = 0): array {
        return ['status' => $status, 'detail' => $detail, 'addresses' => $addrs,
                'contributes' => $contrib, 'problems_inside' => $inside];
    };
    $subId = $e['ref_id'];
    $sub = $state['graph']['lists'][$subId] ?? null;
    if ($sub === null) return $rec('missing_ref', 'list #' . $subId . ' no longer exists');
    if (!empty($sub['archived'])) return $rec('sub_list_archived', 'list "' . $sub['name'] . '" is archived');
    if (in_array($subId, $path, true)) {
        return $rec('cycle', 'list "' . $sub['name'] . '" contains this list again (a loop)');
    }
    if ($depth > EMAIL_LIST_MAX_DEPTH) {
        return $rec('too_deep', 'nested more than ' . EMAIL_LIST_MAX_DEPTH . ' levels');
    }
    if (isset($state['expanded'][$subId])) {
        // Diamond: this list was already expanded under an earlier entry.
        return $rec('duplicate', 'list "' . $sub['name'] . '" already included by entry #'
            . $state['owner'][$subId], [], 0);
    }
    $state['expanded'][$subId] = true;
    $state['owner'][$subId] = $rootEntryId;

    $childPath = array_merge($path, [$subId]);
    $added = []; $inside = 0; $count = 0; $dupes = 0;
    $problemSet = array_flip(email_list_problem_statuses());
    foreach ($state['graph']['entries'][$subId] ?? [] as $child) {
        $count++;
        $r = _email_list_expand_entry($state, $child, $childPath, $depth + 1, $rootEntryId);
        foreach ($r['addresses'] as $a) $added[] = $a;
        if (isset($problemSet[$r['status']])) $inside++;
        elseif ($r['status'] === 'duplicate') $dupes++;
        $inside += (int) $r['problems_inside'];
    }
    $n = count($added);
    if ($n > 0) {
        return $rec($inside > 0 ? 'partial' : 'ok',
            $inside > 0 ? $inside . ' entr' . ($inside === 1 ? 'y' : 'ies') . ' inside "' . $sub['name'] . '" have problems' : null,
            $added, $n, $inside);
    }
    if ($count === 0) return $rec('empty', 'list "' . $sub['name'] . '" has no entries', [], 0, 0);
    // Every entry inside was already provided elsewhere (the diamond case: A
    // includes B and C, and both include D). Nothing is wrong, nothing is new.
    if ($inside === 0 && $dupes === $count) {
        return $rec('duplicate', 'everything in "' . $sub['name'] . '" is already provided by another entry', [], 0, 0);
    }
    return $rec('empty', 'nothing in "' . $sub['name'] . '" resolves to an address', [], 0, $inside);
}

// ─────────────────────────────────────────────────────────────────────────
// Cycle finder (PURE)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Would making $candidateSubId a member of $listId create a loop?
 *
 * A loop exists iff $listId is reachable from $candidateSubId (or they are the
 * same list). Returns the list ids along the way, starting at the candidate and
 * ending at $listId, e.g. adding C under A when C -> B -> A already exists
 * returns [C, B, A]. Null = no loop. A direct self-reference returns [A].
 *
 * The graph must contain at least the lists reachable from the candidate
 * (email_list_load_graph($candidateSubId) is enough).
 *
 * @return int[]|null
 */
function email_list_find_cycle_path(array $graph, int $listId, int $candidateSubId): ?array
{
    if ($candidateSubId === $listId) return [$listId];

    // Depth-first search for $listId starting at the candidate.
    $seen = [];
    $stack = [[$candidateSubId, [$candidateSubId]]];
    while ($stack) {
        [$cur, $path] = array_pop($stack);
        if (isset($seen[$cur])) continue;
        $seen[$cur] = true;
        foreach ($graph['entries'][$cur] ?? [] as $e) {
            if ($e['member_type'] !== 'list' || $e['ref_id'] <= 0) continue;
            if ($e['ref_id'] === $listId) return array_merge($path, [$listId]);
            if (!isset($seen[$e['ref_id']])) {
                $stack[] = [$e['ref_id'], array_merge($path, [$e['ref_id']])];
            }
        }
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────
// Convenience wrapper
// ─────────────────────────────────────────────────────────────────────────

/**
 * Load + expand, filling the policy options from settings unless the caller
 * overrides them. This is what the notification engine, the `resolve` action
 * and the modal's preview all call.
 *
 * Extra keys beyond the expander's: 'missing' => true when the list id does
 * not exist, 'archived' => true when the ROOT list is archived, 'list' => the
 * root list record, 'missing_tables' => true when the tables are absent.
 */
function email_list_resolve(int $listId, array $opts = []): array
{
    $graph = email_list_load_graph($listId);
    $base = [
        'recipients' => [], 'entries' => [], 'summary' => [
            'entries' => 0, 'unique_addresses' => 0, 'problems' => 0,
            'policy_excluded' => 0, 'duplicates' => 0, 'by_status' => []],
        'list' => null, 'missing' => false, 'archived' => false,
        'missing_tables' => !empty($graph['missing_tables']),
    ];
    if (!empty($graph['missing_tables'])) return $base;
    if (!isset($graph['lists'][$listId])) {
        $base['missing'] = true;
        return $base;
    }
    $base['list'] = $graph['lists'][$listId];
    $base['archived'] = !empty($graph['lists'][$listId]['archived']);

    if (!array_key_exists('skip_statuses', $opts)) {
        $o = email_list_options();
        $opts['skip_statuses'] = $o['skip_statuses'];
    }
    $exp = email_list_expand($graph, $listId, $opts);
    return array_merge($base, $exp);
}
