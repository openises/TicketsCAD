<?php
/**
 * Phase 155 (GH#145) - email distribution list writers.
 *
 * The project's `*_internal` convention (inc/incident-write.php,
 * inc/team-write.php): the SQL and the business rules live here so a test can
 * drive the REAL writer, and api/email-lists.php shrinks to auth + CSRF + RBAC
 * + JSON shaping. Every function returns
 *
 *     ['ok' => bool, 'code' => ?string, 'message' => ?string, ...extras]
 *
 * and never throws to its caller. The API maps `code` to an HTTP status with
 * email_list_http_status() and the browser reads `code`.
 *
 * WHAT THE OLD INLINE CODE DID NOT CHECK (api/email-lists.php before this):
 *   - only a DIRECT self-reference was refused; A -> B -> A was accepted
 *   - nothing checked the referenced member / constituent / list EXISTS, that a
 *     member was not soft-deleted, or that the target list was unarchived
 *   - the same recipient could be added any number of times
 *   - `update` could blank a list's name; `remove_member` took only a row id so
 *     a stale modal could delete a row from a DIFFERENT list; `archive` ignored
 *     the notification rules that depend on the list
 *   - nothing called audit_log(), and error paths leaked driver text
 *
 * DUPLICATE PREVENTION IS APPLICATION-LEVEL ON PURPOSE. A UNIQUE key cannot
 * express it: the natural key (list_id, member_type, ref_id, inline_email) has
 * NULLs in two columns and MariaDB treats NULLs as distinct (the Phase 129 /
 * Phase 143 trap). Generated columns were rejected in Phase 144 for good
 * reason on unknown self-hosted databases. A duplicate row is harmless to
 * DELIVERY - the resolver collapses duplicates case-insensitively - so the
 * check exists to keep the UI honest, not to protect the send.
 */

declare(strict_types=1);

require_once __DIR__ . '/email-lists.php';
if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
    require_once __DIR__ . '/audit.php';
}

/** Largest CSV paste we accept (bytes / lines). */
if (!defined('EMAIL_LIST_CSV_MAX_BYTES')) define('EMAIL_LIST_CSV_MAX_BYTES', 262144);
if (!defined('EMAIL_LIST_CSV_MAX_LINES')) define('EMAIL_LIST_CSV_MAX_LINES', 2000);

function email_list_http_status(?string $code): int
{
    static $map = [
        'bad_request' => 400, 'invalid_email' => 422, 'no_email' => 422,
        'list_missing' => 404, 'ref_missing' => 404, 'entry_missing' => 404,
        'list_archived' => 409, 'self' => 409, 'cycle' => 409,
        'duplicate' => 409, 'in_use' => 409, 'slug_exists' => 409,
        'tables_missing' => 503, 'db_error' => 500,
    ];
    return $code !== null && isset($map[$code]) ? $map[$code] : 500;
}

function _email_list_fail(string $code, string $message, array $extra = []): array
{
    return array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra);
}

/** audit_log() must never break the action it records. */
function _email_list_audit(string $activity, $targetId, string $summary, array $details = [], int $severity = 1): void
{
    try {
        if (function_exists('audit_log')) {
            audit_log('config', 'email_list.' . $activity, 'email_list', $targetId, $summary, $details, $severity);
        }
    } catch (Throwable $e) {
        error_log('[email-list-write] audit failed: ' . $e->getMessage());
    }
}

function _email_list_is_missing_table(Throwable $e): bool
{
    return strpos($e->getMessage(), '42S02') !== false
        || stripos($e->getMessage(), "doesn't exist") !== false;
}

/** Map an unexpected Throwable to a generic, driver-text-free failure. */
function _email_list_db_fail(Throwable $e, string $tag): array
{
    error_log('[email-list-write] ' . $tag . ': ' . $e->getMessage());
    if (_email_list_is_missing_table($e)) {
        return _email_list_fail('tables_missing',
            'Email list tables are missing - run php sql/run_migrations.php');
    }
    return _email_list_fail('db_error', 'The database could not complete that request.');
}

function email_list_slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = (string) preg_replace('/[^a-z0-9]+/i', '-', $s);
    return trim($s, '-');
}

/** @return array|null the list row, or null */
function _email_list_get(int $id): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one("SELECT * FROM `{$prefix}email_lists` WHERE `id` = ?", [$id]);
}

// ─────────────────────────────────────────────────────────────────────────
// Lists
// ─────────────────────────────────────────────────────────────────────────

function email_list_create_internal(string $name, ?string $slug, ?string $description, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $name = trim($name);
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, 64); else $name = substr($name, 0, 64);
    if ($name === '') return _email_list_fail('bad_request', 'A list needs a name.');

    $explicit = $slug !== null && trim($slug) !== '';
    $base = substr(email_list_slugify($explicit ? (string) $slug : $name), 0, 56);
    if ($base === '') $base = 'list';
    $desc = $description !== null ? substr($description, 0, 1024) : null;

    try {
        $candidate = $base;
        for ($i = 2; $i < 50; $i++) {
            $exists = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}email_lists` WHERE `slug` = ?", [$candidate]);
            if ($exists === 0) break;
            if ($explicit) {
                return _email_list_fail('slug_exists', 'A list with that slug already exists.');
            }
            $candidate = $base . '-' . $i;
        }
        db_query(
            "INSERT INTO `{$prefix}email_lists` (`name`, `slug`, `description`, `created_by`)
             VALUES (?, ?, ?, ?)",
            [$name, $candidate, $desc, $userId > 0 ? $userId : null]
        );
        $id = (int) db_insert_id();
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            return _email_list_fail('slug_exists', 'A list with that slug already exists.');
        }
        return _email_list_db_fail($e, 'create');
    }
    _email_list_audit('create', $id, "Created email list '{$name}'", ['slug' => $candidate]);
    return ['ok' => true, 'code' => null, 'message' => null, 'id' => $id, 'name' => $name, 'slug' => $candidate];
}

function email_list_update_internal(int $id, array $fields, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($id <= 0) return _email_list_fail('bad_request', 'id required');
    $sets = []; $params = []; $changed = [];
    if (array_key_exists('name', $fields)) {
        $name = trim((string) $fields['name']);
        if ($name === '') return _email_list_fail('bad_request', 'A list needs a name.');
        $sets[] = '`name` = ?';
        $params[] = function_exists('mb_substr') ? mb_substr($name, 0, 64) : substr($name, 0, 64);
        $changed[] = 'name';
    }
    if (array_key_exists('description', $fields)) {
        $sets[] = '`description` = ?';
        $params[] = substr((string) $fields['description'], 0, 1024);
        $changed[] = 'description';
    }
    if (!$sets) return _email_list_fail('bad_request', 'nothing to update');
    try {
        $list = _email_list_get($id);
        if (!$list) return _email_list_fail('list_missing', 'That list does not exist.');
        $params[] = $id;
        db_query("UPDATE `{$prefix}email_lists` SET " . implode(', ', $sets) . " WHERE `id` = ?", $params);
    } catch (Throwable $e) {
        return _email_list_db_fail($e, 'update');
    }
    _email_list_audit('update', $id, "Updated email list '{$list['name']}'", ['fields' => $changed]);
    return ['ok' => true, 'code' => null, 'message' => null, 'id' => $id];
}

/**
 * Names of the notification rules that name this list. Table may not exist.
 *
 * @return string[]
 */
function email_list_rules_using(int $listId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `name` FROM `{$prefix}notification_rules` WHERE `email_list_id` = ? ORDER BY `id`",
            [$listId]);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) $out[] = ((string) $r['name'] !== '' ? (string) $r['name'] : 'Rule #' . $r['id']);
    return $out;
}

/**
 * Archive a list. Refused (code `in_use`) while a notification rule names it,
 * unless $force - the engine would otherwise log "email list is archived" on
 * every matching event with nobody looking at that log.
 */
function email_list_archive_internal(int $id, bool $force, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($id <= 0) return _email_list_fail('bad_request', 'id required');
    try {
        $list = _email_list_get($id);
        if (!$list) return _email_list_fail('list_missing', 'That list does not exist.');
        $rules = email_list_rules_using($id);
        if ($rules && !$force) {
            return _email_list_fail('in_use',
                'This list is used by ' . count($rules) . ' notification rule(s): '
                . implode(', ', array_slice($rules, 0, 5)) . '. Archive anyway?',
                ['rules' => $rules]);
        }
        db_query("UPDATE `{$prefix}email_lists` SET `archived_at` = NOW() WHERE `id` = ?", [$id]);
    } catch (Throwable $e) {
        return _email_list_db_fail($e, 'archive');
    }
    _email_list_audit('archive', $id, "Archived email list '{$list['name']}'",
        ['forced' => $force && !empty($rules), 'rules' => $rules ?? []],
        ($force && !empty($rules)) ? AUDIT_MEDIUM : AUDIT_INFO);
    return ['ok' => true, 'code' => null, 'message' => null, 'id' => $id, 'rules' => $rules];
}

// ─────────────────────────────────────────────────────────────────────────
// Entries
// ─────────────────────────────────────────────────────────────────────────

/**
 * Validate and INSERT one entry, WITHOUT auditing (the CSV import audits once
 * for the whole paste). See email_list_add_entry_internal() for the contract.
 *
 * Check order (the order is part of the contract - tests assert it):
 *   list exists + not archived -> type valid -> reference exists -> self ->
 *   cycle -> duplicate -> require-email option -> INSERT.
 */
function _email_list_insert_entry(int $listId, string $type, array $input, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($listId <= 0) return _email_list_fail('bad_request', 'list_id required');
    if (!in_array($type, ['member', 'constituent', 'inline', 'list'], true)) {
        return _email_list_fail('bad_request', 'member_type must be member, constituent, inline or list.');
    }

    try {
        $list = _email_list_get($listId);
    } catch (Throwable $e) {
        return _email_list_db_fail($e, 'add_entry list lookup');
    }
    if (!$list) return _email_list_fail('list_missing', 'That list does not exist.');
    if ($list['archived_at'] !== null) {
        return _email_list_fail('list_archived', 'That list is archived. Restore it before adding recipients.');
    }

    $refId = isset($input['ref_id']) ? (int) $input['ref_id'] : 0;
    $displayName = isset($input['display_name']) ? substr(trim((string) $input['display_name']), 0, 128) : '';
    $inline = isset($input['inline_email']) ? trim((string) $input['inline_email']) : '';
    $warnings = [];
    $label = '';
    $emailRaw = '';

    try {
        if ($type === 'inline') {
            if ($inline === '' || strlen($inline) > 254 || filter_var($inline, FILTER_VALIDATE_EMAIL) === false) {
                return _email_list_fail('invalid_email', 'Enter a valid email address.');
            }
            $label = $displayName !== '' ? $displayName : $inline;
        } else {
            if ($refId <= 0) return _email_list_fail('bad_request', 'ref_id required for ' . $type);
            $inline = '';
        }

        if ($type === 'member') {
            $m = null;
            foreach ([
                "SELECT `id`, `first_name`, `last_name`, `callsign`, `email`, `deleted_at`
                   FROM `{$prefix}member` WHERE `id` = ?",
                "SELECT `id`, `first_name`, `last_name`, `callsign`, `email`, NULL AS `deleted_at`
                   FROM `{$prefix}member` WHERE `id` = ?",
            ] as $sql) {
                try { $m = db_fetch_one($sql, [$refId]); break; } catch (Throwable $e) { $m = null; }
            }
            if (!$m) return _email_list_fail('ref_missing', 'That member does not exist.');
            if (email_list_is_deleted($m['deleted_at'] ?? null)) {
                return _email_list_fail('ref_missing', 'That member has been deleted from the roster.');
            }
            $emailRaw = (string) ($m['email'] ?? '');
            $label = trim(((string) ($m['first_name'] ?? '')) . ' ' . ((string) ($m['last_name'] ?? '')));
            if ($label === '') $label = (string) ($m['callsign'] ?? '') ?: ('member #' . $refId);
        } elseif ($type === 'constituent') {
            $c = db_fetch_one("SELECT `id`, `contact`, `email` FROM `{$prefix}constituents` WHERE `id` = ?", [$refId]);
            if (!$c) return _email_list_fail('ref_missing', 'That contact does not exist.');
            $emailRaw = (string) ($c['email'] ?? '');
            $label = (string) ($c['contact'] ?? '') ?: ('contact #' . $refId);
        } elseif ($type === 'list') {
            $sub = _email_list_get($refId);
            if (!$sub) return _email_list_fail('ref_missing', 'That list does not exist.');
            if ($sub['archived_at'] !== null) {
                return _email_list_fail('list_archived', 'The list "' . $sub['name'] . '" is archived.');
            }
            $label = (string) $sub['name'];
            if ($refId === $listId) return _email_list_fail('self', 'A list cannot include itself.');

            $graph = email_list_load_graph($refId);
            $path = email_list_find_cycle_path($graph, $listId, $refId);
            if ($path !== null) {
                $names = [(string) $list['name']];
                foreach ($path as $pid) {
                    $names[] = (string) ($graph['lists'][$pid]['name'] ?? ('list #' . $pid));
                }
                return _email_list_fail('cycle',
                    "Adding '{$sub['name']}' to '{$list['name']}' would create a loop: "
                    . implode(' -> ', $names), ['path' => $names]);
            }
        }

        // Duplicate: same (list, type, ref), or the same address (case-insensitive) for inline.
        if ($type === 'inline') {
            $dup = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}email_list_members`
                  WHERE `list_id` = ? AND `member_type` = 'inline' AND LOWER(`inline_email`) = LOWER(?)",
                [$listId, $inline]);
        } else {
            $dup = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}email_list_members`
                  WHERE `list_id` = ? AND `member_type` = ? AND `ref_id` = ?",
                [$listId, $type, $refId]);
        }
        if ($dup > 0) {
            return _email_list_fail('duplicate', '"' . $label . '" is already on this list.', ['label' => $label]);
        }

        // An entry that resolves to no address: warn, or refuse per the option.
        if ($type === 'member' || $type === 'constituent') {
            $split = trim($emailRaw) === '' ? null : email_list_split_addresses($emailRaw);
            $problem = null;
            if ($split === null) $problem = 'no_email';
            elseif (!$split['valid']) $problem = 'invalid_email';
            if ($problem !== null) {
                $opts = email_list_options();
                if ($opts['require_email_on_add']) {
                    return _email_list_fail($problem === 'no_email' ? 'no_email' : 'invalid_email',
                        '"' . $label . '" has no usable email address on file, and this install refuses entries '
                        . 'without one (Settings -> Email Lists -> List options).', ['label' => $label]);
                }
                $warnings[] = $problem;
            }
        }

        db_query(
            "INSERT INTO `{$prefix}email_list_members`
                (`list_id`, `member_type`, `ref_id`, `inline_email`, `display_name`, `added_by`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$listId, $type, $type === 'inline' ? null : $refId,
             $type === 'inline' ? $inline : null, $displayName !== '' ? $displayName : null,
             $userId > 0 ? $userId : null]
        );
        $id = (int) db_insert_id();
    } catch (Throwable $e) {
        return _email_list_db_fail($e, 'add_entry');
    }

    return ['ok' => true, 'code' => null, 'message' => null, 'id' => $id, 'warnings' => $warnings,
            'label' => $label, 'list_name' => (string) $list['name'], 'type' => $type, 'ref_id' => $refId];
}

/**
 * Add one recipient to a list.
 *
 * $input keys: ref_id (member / constituent / list), inline_email (inline),
 * display_name (optional label override).
 *
 * `warnings` on success: ['no_email'] / ['invalid_email'] when the entry was
 * added but resolves to nothing (the modal row shows a persistent badge).
 */
function email_list_add_entry_internal(int $listId, string $type, array $input, int $userId): array
{
    $r = _email_list_insert_entry($listId, $type, $input, $userId);
    if (!$r['ok']) return $r;
    $details = ['type' => $type, 'ref_id' => $r['ref_id'] ?: null, 'label' => $r['label'],
                'warnings' => $r['warnings']];
    if ($type === 'inline') $details['inline_email'] = (string) ($input['inline_email'] ?? '');
    _email_list_audit('add_entry', $listId,
        "Added {$type} '{$r['label']}' to email list '{$r['list_name']}'", $details);
    return $r;
}

/**
 * Remove an entry. The caller must name the list the entry belongs to: the old
 * action took only a row id, so a stale modal could delete a row from a
 * DIFFERENT list.
 */
function email_list_remove_entry_internal(int $entryId, int $listId, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($entryId <= 0 || $listId <= 0) return _email_list_fail('bad_request', 'id and list_id required');
    try {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}email_list_members` WHERE `id` = ? AND `list_id` = ?",
            [$entryId, $listId]);
        if (!$row) return _email_list_fail('entry_missing', 'That entry is not on this list.');
        db_query("DELETE FROM `{$prefix}email_list_members` WHERE `id` = ? AND `list_id` = ?", [$entryId, $listId]);
        $list = _email_list_get($listId);
    } catch (Throwable $e) {
        return _email_list_db_fail($e, 'remove_entry');
    }
    _email_list_audit('remove_entry', $listId,
        "Removed {$row['member_type']} entry #{$entryId} from email list '" . ($list['name'] ?? $listId) . "'",
        ['entry_id' => $entryId, 'type' => $row['member_type'], 'ref_id' => $row['ref_id'],
         'inline_email' => $row['inline_email']]);
    return ['ok' => true, 'code' => null, 'message' => null, 'id' => $entryId];
}

/**
 * Bulk-add inline addresses from pasted CSV (`address[,name]` per line, `#`
 * comments and blank lines ignored). NO header row is required or detected: a
 * first line that is not an address is simply reported as skipped, exactly like
 * any other bad line. The old user guide said the import matched members by
 * email - it never did, and this does not either.
 *
 * @return array ok + added/skipped/duplicates/errors (errors capped at 10)
 */
function email_list_import_csv_internal(int $listId, string $csv, int $userId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($listId <= 0 || trim($csv) === '') return _email_list_fail('bad_request', 'list_id and csv_text required');
    if (strlen($csv) > EMAIL_LIST_CSV_MAX_BYTES) {
        return _email_list_fail('bad_request', 'That paste is too large (limit ' . (int) (EMAIL_LIST_CSV_MAX_BYTES / 1024) . ' KB).');
    }
    try {
        $list = _email_list_get($listId);
    } catch (Throwable $e) {
        return _email_list_db_fail($e, 'import list lookup');
    }
    if (!$list) return _email_list_fail('list_missing', 'That list does not exist.');
    if ($list['archived_at'] !== null) {
        return _email_list_fail('list_archived', 'That list is archived. Restore it before importing.');
    }

    $existing = [];
    try {
        foreach (db_fetch_all(
            "SELECT LOWER(`inline_email`) AS `e` FROM `{$prefix}email_list_members`
              WHERE `list_id` = ? AND `member_type` = 'inline'", [$listId]) as $r) {
            $existing[(string) $r['e']] = true;
        }
    } catch (Throwable $e) {
        return _email_list_db_fail($e, 'import existing');
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if ($lines === false) $lines = [];
    if (count($lines) > EMAIL_LIST_CSV_MAX_LINES) {
        return _email_list_fail('bad_request', 'That paste has more than ' . EMAIL_LIST_CSV_MAX_LINES . ' lines.');
    }

    $added = 0; $skipped = 0; $duplicates = 0; $errors = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = str_getcsv($line, ',', '"', '');
        $email = trim((string) ($parts[0] ?? ''));
        $name = isset($parts[1]) ? substr(trim((string) $parts[1]), 0, 128) : '';
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $skipped++;
            if (count($errors) < 10) $errors[] = 'skipped (not an address): ' . substr($line, 0, 60);
            continue;
        }
        $key = strtolower($email);
        if (isset($existing[$key])) { $duplicates++; continue; }
        try {
            db_query(
                "INSERT INTO `{$prefix}email_list_members`
                    (`list_id`, `member_type`, `inline_email`, `display_name`, `added_by`)
                 VALUES (?, 'inline', ?, ?, ?)",
                [$listId, $email, $name !== '' ? $name : null, $userId > 0 ? $userId : null]);
            $existing[$key] = true;
            $added++;
        } catch (Throwable $e) {
            error_log('[email-list-write] import row failed: ' . $e->getMessage());
            $skipped++;
            if (count($errors) < 10) $errors[] = 'could not add: ' . substr($email, 0, 60);
        }
    }
    _email_list_audit('import_csv', $listId, "Imported addresses into email list '{$list['name']}'",
        ['added' => $added, 'skipped' => $skipped, 'duplicates' => $duplicates]);
    return ['ok' => true, 'code' => null, 'message' => null,
            'added' => $added, 'skipped' => $skipped, 'duplicates' => $duplicates, 'errors' => $errors];
}

// ─────────────────────────────────────────────────────────────────────────
// Options
// ─────────────────────────────────────────────────────────────────────────

/** Distinct, case-insensitively de-duplicated member_status labels (first spelling wins). */
function email_list_member_status_labels(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $out = [];
    try {
        foreach (db_fetch_all("SELECT `status_val` FROM `{$prefix}member_status` ORDER BY `sort_order`, `id`") as $r) {
            $l = trim((string) $r['status_val']);
            if ($l !== '' && !isset($out[strtolower($l)])) $out[strtolower($l)] = $l;
        }
    } catch (Throwable $e) { /* no member_status table */ }
    return array_values($out);
}

/** settings has no guaranteed unique key on every old install: SELECT then UPDATE/INSERT. */
function _email_list_write_setting(string $name, string $value): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    if ($n > 0) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = ?", [$value, $name]);
    } else {
        db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
    }
}

/**
 * Save the two list options. skip_statuses must be labels that exist in
 * member_status, OR labels already stored (so an admin can remove a label whose
 * status was since deleted - and re-saving an unchanged list never fails).
 */
function email_list_save_options_internal(array $input, int $userId): array
{
    $old = email_list_options();
    $valid = [];
    foreach (email_list_member_status_labels() as $l) $valid[strtolower($l)] = true;
    foreach ($old['skip_statuses'] as $l) $valid[strtolower($l)] = true;

    $skip = [];
    if (array_key_exists('skip_statuses', $input)) {
        if (!is_array($input['skip_statuses'])) return _email_list_fail('bad_request', 'skip_statuses must be a list.');
        foreach ($input['skip_statuses'] as $l) {
            $k = strtolower(trim((string) $l));
            if ($k === '') continue;
            if (!isset($valid[$k])) {
                return _email_list_fail('bad_request', 'No member status is labelled "' . substr((string) $l, 0, 48) . '".');
            }
            $skip[$k] = true;
        }
        $skip = array_keys($skip);
    } else {
        $skip = $old['skip_statuses'];
    }
    $require = array_key_exists('require_email_on_add', $input)
        ? !empty($input['require_email_on_add']) : $old['require_email_on_add'];

    try {
        _email_list_write_setting(EMAIL_LIST_SETTING_SKIP_STATUSES, (string) json_encode(array_values($skip)));
        _email_list_write_setting(EMAIL_LIST_SETTING_REQUIRE_EMAIL, $require ? '1' : '0');
    } catch (Throwable $e) {
        return _email_list_db_fail($e, 'save_options');
    }
    _email_list_audit('options', 'options', 'Changed email list options', [
        'skip_statuses' => ['from' => $old['skip_statuses'], 'to' => array_values($skip)],
        'require_email_on_add' => ['from' => $old['require_email_on_add'], 'to' => $require],
    ]);
    return ['ok' => true, 'code' => null, 'message' => null,
            'skip_statuses' => array_values($skip), 'require_email_on_add' => $require];
}
