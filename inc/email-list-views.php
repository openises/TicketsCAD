<?php
/**
 * Phase 155 (GH#145) - the read side of the Email Lists admin panel.
 *
 * Everything here is built on email_list_load_graph() + email_list_expand()
 * (inc/email-lists.php), so the numbers the panel shows are the numbers a
 * notification rule will act on.
 *
 *   email_list_overview()            the list table: entries / addresses / problems
 *   email_list_detail($id)           one list, every entry with its status
 *   email_list_search_recipients()   the server-side typeahead behind the pickers
 *
 * THE PICKERS ARE SERVER-SIDE ON PURPOSE
 * --------------------------------------
 * SearchableSelect was built to filter a pre-loaded array in the browser. That
 * is wrong here on both counts: constituents are unbounded (every unseen
 * inbound caller becomes one - inc/inbound-calls.php) and are private
 * citizens' contact data, and most of them have no email at all. So the
 * browser asks for at most 25 matches per keystroke and never holds the
 * address book.
 */

declare(strict_types=1);

require_once __DIR__ . '/email-lists.php';

/** Display name for a user id (first + last, else the login name). */
function _email_list_user_names(array $ids): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    if (!$ids) return $out;
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `user`, `name_f`, `name_l` FROM `{$prefix}user`
              WHERE `id` IN (" . _email_list_placeholders($ids) . ")", $ids);
        foreach ($rows as $r) {
            $n = trim(((string) ($r['name_f'] ?? '')) . ' ' . ((string) ($r['name_l'] ?? '')));
            $out[(int) $r['id']] = $n !== '' ? $n : (string) $r['user'];
        }
    } catch (Throwable $e) { error_log('[email-list-views] user-name lookup failed: ' . $e->getMessage()); }
    return $out;
}

/**
 * The list table: every non-archived list with how many entries it has, how
 * many unique addresses it resolves to, and how many of its entries have a
 * problem. `missing_tables` => true when the list tables do not exist (the API
 * turns that into a 503 rather than an empty table that reads as "no lists").
 *
 * @return array{missing_tables:bool,lists:array<int,array>}
 */
function email_list_overview(): array
{
    $graph = email_list_load_graph(null);
    if (!empty($graph['missing_tables'])) return ['missing_tables' => true, 'lists' => []];
    $o = email_list_options();
    $out = [];
    foreach ($graph['lists'] as $id => $l) {
        if (!empty($l['archived'])) continue;
        $exp = email_list_expand($graph, $id, ['skip_statuses' => $o['skip_statuses']]);
        $out[] = [
            'id' => $id,
            'name' => $l['name'],
            'slug' => $l['slug'],
            'description' => '',
            'entry_count' => $exp['summary']['entries'],
            'address_count' => $exp['summary']['unique_addresses'],
            'problem_count' => $exp['summary']['problems'],
        ];
    }
    // Descriptions + created_at are not in the graph (it carries only what a
    // send needs). One extra small query for the table's text columns.
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $meta = [];
        foreach (db_fetch_all("SELECT `id`, `description`, `created_at` FROM `{$prefix}email_lists`") as $r) {
            $meta[(int) $r['id']] = $r;
        }
        foreach ($out as $i => $row) {
            $out[$i]['description'] = (string) ($meta[$row['id']]['description'] ?? '');
            $out[$i]['created_at'] = (string) ($meta[$row['id']]['created_at'] ?? '');
        }
    } catch (Throwable $e) { error_log('[email-list-views] list description lookup failed: ' . $e->getMessage()); }
    usort($out, static function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return ['missing_tables' => false, 'lists' => $out];
}

/**
 * One list for the Manage modal: the list record, a summary, and one row per
 * entry with its resolved label, email, status and what it contributes.
 * Problems sort first so the thing an administrator must fix is at the top.
 *
 * @return array{missing_tables:bool,missing:bool,list:?array,members:array,summary:?array}
 */
function email_list_detail(int $listId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $graph = email_list_load_graph($listId);
    if (!empty($graph['missing_tables'])) {
        return ['missing_tables' => true, 'missing' => false, 'list' => null, 'members' => [], 'summary' => null];
    }
    if (!isset($graph['lists'][$listId])) {
        return ['missing_tables' => false, 'missing' => true, 'list' => null, 'members' => [], 'summary' => null];
    }
    $o = email_list_options();
    $exp = email_list_expand($graph, $listId, ['skip_statuses' => $o['skip_statuses']]);

    $listRow = null;
    try { $listRow = db_fetch_one("SELECT * FROM `{$prefix}email_lists` WHERE `id` = ?", [$listId]); }
    catch (Throwable $e) { error_log('[email-list-views] list record lookup failed: ' . $e->getMessage()); $listRow = null; }

    $entries = $graph['entries'][$listId] ?? [];
    $names = _email_list_user_names(array_map(static function ($e) { return $e['added_by']; }, $entries));

    $problemSet = array_flip(email_list_problem_statuses());
    $rows = [];
    foreach ($entries as $e) {
        $st = $exp['entries'][$e['id']] ?? ['status' => 'ok', 'detail' => null, 'contributes' => 0];
        $label = ''; $callsign = ''; $emailText = ''; $rosterId = 0; $subName = '';
        if ($e['member_type'] === 'member') {
            $m = $graph['members'][$e['ref_id']] ?? null;
            if ($m) {
                $label = $m['name'] !== '' ? $m['name'] : $m['callsign'];
                $callsign = $m['name'] !== '' ? $m['callsign'] : '';
                $emailText = $m['email'];
                $rosterId = $m['id'];
            }
        } elseif ($e['member_type'] === 'constituent') {
            $c = $graph['constituents'][$e['ref_id']] ?? null;
            if ($c) { $label = $c['name']; $emailText = $c['email']; }
        } elseif ($e['member_type'] === 'list') {
            $s = $graph['lists'][$e['ref_id']] ?? null;
            if ($s) { $label = $s['name']; $subName = $s['name']; }
        } else {
            $label = $e['inline_email'];
            $emailText = $e['inline_email'];
        }
        if ($e['display_name'] !== '') $label = $e['display_name'];
        $rows[] = [
            'id' => $e['id'],
            'member_type' => $e['member_type'],
            'ref_id' => $e['ref_id'],
            'label' => $label,
            'callsign' => $callsign,
            'resolved_email' => $emailText,
            'sub_list_id' => $e['member_type'] === 'list' ? $e['ref_id'] : 0,
            'status' => $st['status'],
            'status_detail' => $st['detail'] ?? '',
            'contributes' => (int) $st['contributes'],
            'added_by_name' => $names[$e['added_by']] ?? '',
            'added_at' => $e['added_at'],
            'roster_id' => $rosterId,
            'orphan' => in_array($st['status'], ['missing_ref'], true),
        ];
    }
    // Problems first, then policy-explained, then the rest; stable by id.
    usort($rows, static function ($a, $b) use ($problemSet) {
        $rank = static function (array $r) use ($problemSet): int {
            if (isset($problemSet[$r['status']])) return 0;
            if (in_array($r['status'], email_list_policy_statuses(), true)) return 1;
            if ($r['status'] === 'duplicate') return 2;
            return 3;
        };
        $ra = $rank($a); $rb = $rank($b);
        return $ra === $rb ? $a['id'] <=> $b['id'] : $ra <=> $rb;
    });

    $summary = $exp['summary'];
    $summary['skip_statuses'] = $o['skip_statuses'];
    return ['missing_tables' => false, 'missing' => false, 'list' => $listRow,
            'members' => $rows, 'summary' => $summary];
}

/** Escape a user query for LIKE ... ESCAPE '|'. */
function _email_list_like(string $q): string
{
    return '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $q) . '%';
}

/**
 * Typeahead for the three picker types.
 *
 * @param string $type     member | constituent | list
 * @param int    $listId   the list being edited (for already_in_list / would_cycle)
 * @param ?bool  $hasEmail null = per-type default (constituents: only those with an email)
 * @return array{items:array,more:bool}
 */
function email_list_search_recipients(string $type, string $q, int $listId, ?bool $hasEmail = null, int $limit = 25): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit = max(1, min(50, $limit));
    $q = trim($q);
    if (function_exists('mb_substr')) $q = mb_substr($q, 0, 64); else $q = substr($q, 0, 64);
    if ($hasEmail === null) $hasEmail = ($type === 'constituent');
    $opts = email_list_options();
    $like = _email_list_like($q);
    $items = [];
    $fetch = $limit + 1;

    // What is already on the list (so a row can render disabled).
    $present = [];
    try {
        foreach (db_fetch_all(
            "SELECT `member_type`, `ref_id` FROM `{$prefix}email_list_members`
              WHERE `list_id` = ? AND `member_type` <> 'inline'", [$listId]) as $r) {
            $present[$r['member_type'] . ':' . (int) $r['ref_id']] = true;
        }
    } catch (Throwable $e) { error_log('[email-list-views] present-entries lookup failed: ' . $e->getMessage()); }

    if ($type === 'member') {
        $statusLabel = [];
        try {
            foreach (db_fetch_all("SELECT `id`, `status_val` FROM `{$prefix}member_status`") as $s) {
                $statusLabel[(int) $s['id']] = (string) $s['status_val'];
            }
        } catch (Throwable $e) { error_log('[email-list-views] member status labels failed: ' . $e->getMessage()); }
        $where = "(m.`deleted_at` IS NULL OR m.`deleted_at` = '0000-00-00 00:00:00')";
        $params = [];
        if ($q !== '') {
            $where .= " AND (m.`first_name` LIKE ? ESCAPE '|' OR m.`last_name` LIKE ? ESCAPE '|'
                         OR CONCAT(COALESCE(m.`first_name`,''), ' ', COALESCE(m.`last_name`,'')) LIKE ? ESCAPE '|'
                         OR m.`callsign` LIKE ? ESCAPE '|' OR m.`email` LIKE ? ESCAPE '|')";
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($hasEmail) $where .= " AND m.`email` IS NOT NULL AND m.`email` <> ''";
        $rows = null;
        foreach ([
            "SELECT m.`id`, m.`first_name`, m.`last_name`, m.`callsign`, m.`email`, m.`member_status_id`
               FROM `{$prefix}member` m WHERE {$where}
              ORDER BY m.`last_name`, m.`first_name`, m.`id` LIMIT {$fetch}",
            // pre-wastebasket installs (no deleted_at)
            "SELECT m.`id`, m.`first_name`, m.`last_name`, m.`callsign`, m.`email`, NULL AS `member_status_id`
               FROM `{$prefix}member` m WHERE " . str_replace("(m.`deleted_at` IS NULL OR m.`deleted_at` = '0000-00-00 00:00:00')", '1=1', $where) . "
              ORDER BY m.`last_name`, m.`first_name`, m.`id` LIMIT {$fetch}",
        ] as $sql) {
            try { $rows = db_fetch_all($sql, $params); break; } catch (Throwable $e) { $rows = null; $lastErr = $e->getMessage(); }
        }
        if ($rows === null) error_log('[email-list-views] member search failed on every query shape: ' . ($lastErr ?? ''));
        foreach ($rows ?? [] as $r) {
            $name = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
            $call = (string) ($r['callsign'] ?? '');
            $label = $name !== '' ? $name : ($call !== '' ? $call : 'member #' . $r['id']);
            $email = trim((string) ($r['email'] ?? ''));
            $split = $email !== '' ? email_list_split_addresses($email) : ['valid' => []];
            $sid = isset($r['member_status_id']) ? (int) $r['member_status_id'] : 0;
            $stat = ($sid > 0 && isset($statusLabel[$sid])) ? $statusLabel[$sid] : '';
            $items[] = _email_list_search_item((int) $r['id'], $label, $call, $email, !empty($split['valid']),
                $stat, isset($present['member:' . $r['id']]), false, $opts);
        }
    } elseif ($type === 'constituent') {
        $where = '1=1'; $params = [];
        if ($q !== '') {
            $where .= " AND (c.`contact` LIKE ? ESCAPE '|' OR c.`email` LIKE ? ESCAPE '|'
                         OR c.`phone` LIKE ? ESCAPE '|' OR c.`reference` LIKE ? ESCAPE '|')";
            array_push($params, $like, $like, $like, $like);
        }
        if ($hasEmail) $where .= " AND c.`email` IS NOT NULL AND c.`email` <> ''";
        try {
            $rows = db_fetch_all(
                "SELECT c.`id`, c.`contact`, c.`email`, c.`phone` FROM `{$prefix}constituents` c
                  WHERE {$where} ORDER BY c.`contact`, c.`id` LIMIT {$fetch}", $params);
        } catch (Throwable $e) {
            error_log('[email-list-views] constituent search failed: ' . $e->getMessage());
            $rows = [];
        }
        foreach ($rows as $r) {
            $email = trim((string) ($r['email'] ?? ''));
            $split = $email !== '' ? email_list_split_addresses($email) : ['valid' => []];
            $label = (string) ($r['contact'] ?? '') !== '' ? (string) $r['contact'] : ('contact #' . $r['id']);
            $items[] = _email_list_search_item((int) $r['id'], $label, (string) ($r['phone'] ?? ''), $email,
                !empty($split['valid']), '', isset($present['constituent:' . $r['id']]), false, $opts);
        }
    } elseif ($type === 'list') {
        $graph = email_list_load_graph(null);
        $n = 0; $more = false;
        $needle = strtolower($q);
        foreach ($graph['lists'] as $id => $l) {
            if (!empty($l['archived']) || $id === $listId) continue;
            if ($needle !== '' && strpos(strtolower($l['name'] . ' ' . $l['slug']), $needle) === false) continue;
            if ($n >= $limit) { $more = true; break; }
            $loop = email_list_find_cycle_path($graph, $listId, $id) !== null;
            $exp = email_list_expand($graph, $id, ['skip_statuses' => $opts['skip_statuses']]);
            $count = $exp['summary']['unique_addresses'];
            $items[] = _email_list_search_item($id, $l['name'],
                $count > 0 ? $count . ' address' . ($count === 1 ? '' : 'es') : 'no addresses yet',
                '', true, '', isset($present['list:' . $id]), $loop, $opts, 'list');
            $n++;
        }
        return ['items' => $items, 'more' => $more];
    } else {
        return ['items' => [], 'more' => false];
    }

    $more = count($items) > $limit;
    if ($more) $items = array_slice($items, 0, $limit);
    return ['items' => $items, 'more' => $more];
}

/**
 * One typeahead row. All text is plain; the browser escapes it.
 *
 * @param string $kind 'person' (member / contact: needs an email) or 'list'
 */
function _email_list_search_item(int $id, string $label, string $sub, string $email, bool $hasEmail,
                                 string $statusLabel, bool $already, bool $wouldCycle, array $opts,
                                 string $kind = 'person'): array
{
    $disabled = '';
    $note = '';
    if ($already) {
        $disabled = 'already on this list';
    } elseif ($wouldCycle) {
        $disabled = 'would create a loop';
    }
    if ($disabled === '' && $statusLabel !== '' && in_array(strtolower($statusLabel), $opts['skip_statuses'], true)) {
        $note = 'status ' . $statusLabel . ' - will be skipped';
    }
    $second = [];
    if ($sub !== '') $second[] = $sub;
    if ($kind === 'person') {
        if ($email === '') {
            $second[] = 'no email on file';
        } else {
            $second[] = $hasEmail ? $email : $email . ' (not a valid address)';
        }
        if ($disabled === '' && !$hasEmail && !empty($opts['require_email_on_add'])) {
            $disabled = 'no usable email on file (this install requires one)';
        }
    }
    if ($note !== '') $second[] = $note;
    return [
        'id' => $id,
        'label' => $label,
        'sublabel' => implode(' - ', $second),
        'email' => $email,
        'has_email' => $hasEmail,
        'status_label' => $statusLabel,
        'already_in_list' => $already,
        'would_cycle' => $wouldCycle,
        'disabled_reason' => $disabled,
        'search_text' => strtolower($label . ' ' . $sub . ' ' . $email),
    ];
}
