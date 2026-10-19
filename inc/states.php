<?php
/**
 * GH#153 — the State / Province list (table `states_translator`).
 *
 * Every State dropdown in the application (incidents, members, units,
 * facilities, vehicles, constituents, warn locations, places) is filled from
 * this one table, through api/incident-types.php. Until GH#153 nothing in the
 * application could change it: a Canadian agency had to edit it with SQL to
 * get provinces. This library is the only writer. It is driven by
 * api/states.php (Settings → States / Provinces) and by the tests, which call
 * these functions directly.
 *
 * What a change does and does not do:
 *   - It changes the CHOICES offered by the dropdowns, on the next page load.
 *   - It never rewrites a saved record. An incident saved with "MN" keeps
 *     "MN" after Minnesota is removed; every dropdown already shows a saved
 *     value that is not on the list (assets/js/states-select.js setValue()).
 *     That is why removals are previewed with a count of the records that
 *     use each code (states_usage()).
 *
 * Codes are stored upper-case, 1–4 characters of A–Z, 0–9 and "-" (every
 * *.state column in the schema is 4 wide). Names are 1–64 characters of
 * UTF-8 (the table is utf8mb4 from sql/run_gh153_states_translator_utf8mb4.php).
 * Codes are unique case-insensitively (UNIQUE KEY uk_states_code where the
 * install's data allowed it; this library enforces it either way).
 *
 * Preset data lives ONLY in inc/state-presets.php.
 */

require_once __DIR__ . '/audit.php';

const STATES_CODE_MAX         = 4;
const STATES_NAME_MAX         = 64;
const STATES_LABEL_MAX        = 40;
const STATES_MAX_ROWS         = 2000;
const STATES_IMPORT_MAX_BYTES = 262144;   // 256 KB
const STATES_IMPORT_MAX_ROWS  = 2000;
const STATES_CUSTOMIZED_SETTING = 'states_list_customized';

/** First characters a spreadsheet would read as the start of a formula. */
const STATES_CSV_FORMULA_CHARS = "=+-@\t\r";

/** The caption every State label reads (canonical since GH #42), plus the two legacy keys kept in step. */
const STATES_LABEL_CAPTION_KEY = 'form.state';

/** The entry asked for is not on the list (HTTP 404). */
class StatesNotFoundException extends RuntimeException {}

/** The list changed after the preview an apply was based on (HTTP 409). */
class StatesStaleException extends RuntimeException {}

/**
 * The current list, ordered the way every dropdown shows it (by name).
 * Throws if the table cannot be read — callers decide what that means.
 *
 * @return array<int, array{id:int, code:string, name:string}>
 */
function states_list(): array
{
    $rows = db_fetch_all('SELECT `id`, `code`, `name` FROM ' . db_table('states_translator') . ' ORDER BY `name`, `code`, `id`');
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name']];
    }
    return $out;
}

/** Upper-case, trimmed. Validation is separate (states_code_error()). */
function states_normalize_code(string $code): string
{
    return strtoupper(trim($code));
}

/** Trimmed, internal runs of whitespace collapsed to one space. */
function states_clean_name(string $name): string
{
    $name = trim($name);
    if (mb_check_encoding($name, 'UTF-8')) {
        $collapsed = preg_replace('/\s+/u', ' ', $name);
        if (is_string($collapsed)) {
            $name = $collapsed;
        }
    }
    return $name;
}

/** Null when the (already normalised) code is acceptable, otherwise the reason. */
function states_code_error(string $code): ?string
{
    if ($code === '') {
        return 'Code is required.';
    }
    if (!preg_match('/^[A-Z0-9-]{1,' . STATES_CODE_MAX . '}$/', $code)) {
        return 'Code must be 1 to ' . STATES_CODE_MAX . ' characters: letters A–Z, digits 0–9 or a hyphen.';
    }
    return null;
}

/** Null when the (already cleaned) name is acceptable, otherwise the reason. */
function states_name_error(string $name): ?string
{
    if (!mb_check_encoding($name, 'UTF-8')) {
        return 'Name is not valid UTF-8 text.';
    }
    if (strpos($name, "\u{FFFD}") !== false) {
        return 'Name contains a character that could not be read. Save the file as "CSV UTF-8" and try again.';
    }
    if ($name === '') {
        return 'Name is required.';
    }
    if (mb_strlen($name, 'UTF-8') > STATES_NAME_MAX) {
        return 'Name is longer than ' . STATES_NAME_MAX . ' characters.';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
        return 'Name contains a control character.';
    }
    return null;
}

/** Validate a code/name pair; throws InvalidArgumentException with the first problem. */
function states_validate_pair(string $code, string $name): void
{
    $err = states_code_error($code) ?? states_name_error($name);
    if ($err !== null) {
        throw new InvalidArgumentException($err);
    }
}

/** The row holding $code (case-insensitive), optionally ignoring row $exceptId. */
function states_find_by_code(string $code, int $exceptId = 0): ?array
{
    $row = db_fetch_one(
        'SELECT `id`, `code`, `name` FROM ' . db_table('states_translator') . ' WHERE UPPER(TRIM(`code`)) = ? AND `id` <> ? ORDER BY `id` LIMIT 1',
        [states_normalize_code($code), $exceptId]
    );
    return $row ? ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']] : null;
}

function states_get(int $id): ?array
{
    $row = db_fetch_one('SELECT `id`, `code`, `name` FROM ' . db_table('states_translator') . ' WHERE `id` = ?', [$id]);
    return $row ? ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']] : null;
}

function states_count(): int
{
    return (int) db_fetch_value('SELECT COUNT(*) FROM ' . db_table('states_translator'));
}

/**
 * Remember that an administrator has changed the list through the panel, so
 * the US seed (sql/run_phase84_states_seed.php) never puts the US states back,
 * even into a list that was deliberately emptied. Best-effort: never fatal.
 */
function states_mark_customized(): void
{
    try {
        db_query(
            'INSERT INTO ' . db_table('settings') . ' (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [STATES_CUSTOMIZED_SETTING, '1']
        );
    } catch (Throwable $e) {
        error_log('[states] could not record ' . STATES_CUSTOMIZED_SETTING . ': ' . $e->getMessage());
    }
}

/** Read straight from the table (get_variable() caches per process). */
function states_is_customized(): bool
{
    try {
        $v = db_fetch_value('SELECT `value` FROM ' . db_table('settings') . ' WHERE `name` = ?', [STATES_CUSTOMIZED_SETTING]);
        return $v !== false && $v !== null && (string) $v === '1';
    } catch (Throwable $e) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Single-row edits
// ─────────────────────────────────────────────────────────────────────────

/**
 * Add one entry.
 * @throws InvalidArgumentException on a bad code/name, a duplicate code, or a full list
 */
function states_create(string $code, string $name): array
{
    $code = states_normalize_code($code);
    $name = states_clean_name($name);
    states_validate_pair($code, $name);
    $dup = states_find_by_code($code);
    if ($dup !== null) {
        throw new InvalidArgumentException("Code {$code} is already on the list ({$dup['name']}).");
    }
    if (states_count() >= STATES_MAX_ROWS) {
        throw new InvalidArgumentException('The list already has ' . STATES_MAX_ROWS . ' entries, the most it can hold.');
    }
    db_query('INSERT INTO ' . db_table('states_translator') . ' (`code`, `name`) VALUES (?, ?)', [$code, $name]);
    $id = (int) db_insert_id();
    states_mark_customized();
    audit_log('config', 'create', 'state_province', $id, "Added state/province {$code} — {$name}",
        ['code' => $code, 'name' => $name]);
    return ['id' => $id, 'code' => $code, 'name' => $name];
}

/**
 * Change one entry's code and/or name. Saved records are NOT rewritten, so a
 * changed code reports how many records still hold the old one.
 * @throws InvalidArgumentException on bad input or a duplicate code
 * @throws StatesNotFoundException when the row does not exist
 */
function states_update(int $id, string $code, string $name): array
{
    $old = states_get($id);
    if ($old === null) {
        throw new StatesNotFoundException('That entry no longer exists. Reload the list.');
    }
    $code = states_normalize_code($code);
    $name = states_clean_name($name);
    states_validate_pair($code, $name);
    $dup = states_find_by_code($code, $id);
    if ($dup !== null) {
        throw new InvalidArgumentException("Code {$code} is already on the list ({$dup['name']}).");
    }
    if ($old['code'] === $code && $old['name'] === $name) {
        return ['changed' => false, 'id' => $id, 'code' => $code, 'name' => $name, 'old_code_usage' => 0];
    }
    db_query('UPDATE ' . db_table('states_translator') . ' SET `code` = ?, `name` = ? WHERE `id` = ?', [$code, $name, $id]);
    $oldUsage = 0;
    if (states_normalize_code($old['code']) !== $code) {
        $u = states_usage([$old['code']]);
        $oldUsage = (int) ($u[states_normalize_code($old['code'])]['total'] ?? 0);
    }
    states_mark_customized();
    audit_log('config', 'update', 'state_province', $id,
        "Changed state/province {$old['code']} — {$old['name']} to {$code} — {$name}",
        ['old' => ['code' => $old['code'], 'name' => $old['name']], 'new' => ['code' => $code, 'name' => $name],
         'records_still_holding_old_code' => $oldUsage]);
    return ['changed' => true, 'id' => $id, 'code' => $code, 'name' => $name, 'old_code' => $old['code'], 'old_code_usage' => $oldUsage];
}

/**
 * Remove one entry. Saved records keep the code.
 * @throws StatesNotFoundException when the row does not exist
 */
function states_delete(int $id): array
{
    $old = states_get($id);
    if ($old === null) {
        throw new StatesNotFoundException('That entry no longer exists. Reload the list.');
    }
    $u = states_usage([$old['code']]);
    $total = (int) ($u[states_normalize_code($old['code'])]['total'] ?? 0);
    db_query('DELETE FROM ' . db_table('states_translator') . ' WHERE `id` = ?', [$id]);
    states_mark_customized();
    audit_log('config', 'delete', 'state_province', $id, "Removed state/province {$old['code']} — {$old['name']}",
        ['code' => $old['code'], 'name' => $old['name'], 'records_holding_code' => $total]);
    return ['deleted' => $old, 'usage' => $total];
}

// ─────────────────────────────────────────────────────────────────────────
// Usage: how many saved records hold a code
// ─────────────────────────────────────────────────────────────────────────

/**
 * Every column that stores a code chosen from this list. Fixed allowlist —
 * the only table/column names ever interpolated into states_usage()'s SQL.
 * Reference data (zipcodes, cities, FCC tables) is deliberately absent:
 * those are not records anyone saved with this dropdown.
 */
function states_usage_column_catalog(): array
{
    return [
        ['ticket',            'state',        'Incidents'],
        ['member',            'state',        'Members'],
        ['responder',         'state',        'Units'],
        ['facilities',        'state',        'Facilities'],
        ['constituents',      'state',        'Constituents'],
        ['newui_vehicles',    'plate_state',  'Vehicles (plate state)'],
        ['warnings',          'state',        'Warn locations'],
        ['places',            'state',        'Places'],
        ['organizations',     'state',        'Organizations'],
        ['region',            'def_st',       'Regions (default state)'],
        ['major_incidents',   'gold_state',   'Major incidents (command posts)'],
        ['major_incidents',   'silver_state', 'Major incidents (command posts)'],
        ['major_incidents',   'bronze_state', 'Major incidents (command posts)'],
        ['major_incidents',   'level4_state', 'Major incidents (command posts)'],
        ['major_incidents',   'level5_state', 'Major incidents (command posts)'],
        ['major_incidents',   'level6_state', 'Major incidents (command posts)'],
        ['vendor_dispatches', 'plate_state',  'Service provider dispatches (plate state)'],
    ];
}

/** The catalog filtered to columns that exist on this install (schema-resilient). */
function states_usage_columns(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $catalog = states_usage_column_catalog();
    $present = [];
    try {
        $tables = array_values(array_unique(array_map(static function ($c) use ($prefix) { return $prefix . $c[0]; }, $catalog)));
        $ph = implode(',', array_fill(0, count($tables), '?'));
        $rows = db_fetch_all(
            "SELECT `TABLE_NAME` AS t, `COLUMN_NAME` AS c FROM information_schema.COLUMNS
              WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN ($ph)",
            $tables
        );
        foreach ($rows as $r) {
            $present[strtolower($r['t'] . '.' . $r['c'])] = true;
        }
    } catch (Throwable $e) {
        error_log('[states] usage column discovery failed: ' . $e->getMessage());
    }
    $cache = [];
    foreach ($catalog as $c) {
        if (isset($present[strtolower($prefix . $c[0] . '.' . $c[1])])) {
            $cache[] = $c;
        }
    }
    return $cache;
}

/**
 * How many saved records hold each of $codes (case-insensitive). Every
 * requested code is present in the answer (0 when unused). Soft-deleted
 * rows are counted: they still hold the value and can be restored.
 *
 * @param string[] $codes
 * @return array<string, array{total:int, by:array<int, array{label:string, table:string, column:string, count:int}>}>
 */
function states_usage(array $codes): array
{
    $want = [];
    foreach ($codes as $c) {
        $n = states_normalize_code((string) $c);
        if ($n !== '') {
            $want[$n] = true;
        }
    }
    $want = array_keys($want);
    $out = [];
    foreach ($want as $c) {
        $out[$c] = ['total' => 0, 'by' => []];
    }
    if ($want === []) {
        return $out;
    }
    foreach (array_chunk($want, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (states_usage_columns() as [$table, $col, $label]) {
            try {
                $rows = db_fetch_all(
                    'SELECT UPPER(TRIM(`' . $col . '`)) AS c, COUNT(*) AS n FROM ' . db_table($table)
                    . ' WHERE `' . $col . '` IN (' . $ph . ') GROUP BY UPPER(TRIM(`' . $col . '`))',
                    $chunk
                );
            } catch (Throwable $e) {
                error_log("[states] usage count on {$table}.{$col} failed: " . $e->getMessage());
                continue;
            }
            foreach ($rows as $r) {
                $code = (string) $r['c'];
                $n = (int) $r['n'];
                if ($n <= 0 || !isset($out[$code])) {
                    continue;
                }
                $out[$code]['total'] += $n;
                $out[$code]['by'][] = ['label' => $label, 'table' => $table, 'column' => $col, 'count' => $n];
            }
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Presets
// ─────────────────────────────────────────────────────────────────────────

/** The raw preset data (inc/state-presets.php). */
function states_presets(): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/state-presets.php';
    }
    return $data;
}

/** A browser-friendly summary of the presets and quick picks. */
function states_preset_catalog(): array
{
    $data = states_presets();
    $presets = [];
    foreach ($data['presets'] as $id => $p) {
        $opts = [];
        foreach ($p['options'] as $key => $o) {
            $opts[] = ['key' => $key, 'label' => $o['label'], 'count' => count($o['rows']), 'default' => (bool) $o['default']];
        }
        $presets[] = [
            'id' => $id, 'name' => $p['name'], 'country' => $p['country'], 'label' => $p['label'],
            'source' => $p['source'], 'count' => count($p['rows']), 'options' => $opts,
        ];
    }
    $picks = [];
    foreach ($data['quick_picks'] as $id => $q) {
        $sel = [];
        foreach ($q['selection'] as $pid => $keys) {
            $sel[$pid] = array_values($keys);
        }
        $picks[] = ['id' => $id, 'label' => $q['label'], 'selection' => $sel];
    }
    return ['presets' => $presets, 'quick_picks' => $picks, 'label_choices' => states_label_choices()];
}

/** Each preset with its default options ticked — what a "Download" button gives. */
function states_default_selection(string $presetId): array
{
    $data = states_presets();
    if (!isset($data['presets'][$presetId])) {
        throw new InvalidArgumentException('Unknown preset: ' . $presetId);
    }
    $keys = [];
    foreach ($data['presets'][$presetId]['options'] as $key => $o) {
        if (!empty($o['default'])) {
            $keys[] = $key;
        }
    }
    return [$presetId => $keys];
}

/**
 * Build the target list for a wizard selection: { presetId: [optionKey, ...] }.
 * Presets are combined in catalog order; a code that appears in two chosen
 * presets with DIFFERENT names keeps the first and is reported as a conflict
 * (US "WA" Washington vs Australia "WA" Western Australia). The same code with
 * the same name (US 48 and US 50 together) is silently one row.
 *
 * @throws InvalidArgumentException on an unknown preset/option or an empty selection
 */
function states_build_selection(array $selection): array
{
    $data = states_presets();
    if ($selection === []) {
        throw new InvalidArgumentException('Choose at least one list.');
    }
    foreach ($selection as $pid => $keys) {
        if (!is_string($pid) || !isset($data['presets'][$pid])) {
            throw new InvalidArgumentException('Unknown preset: ' . (is_string($pid) ? $pid : '?'));
        }
        if (!is_array($keys)) {
            throw new InvalidArgumentException('Options for ' . $pid . ' must be a list.');
        }
        foreach ($keys as $k) {
            if (!is_string($k) || !isset($data['presets'][$pid]['options'][$k])) {
                throw new InvalidArgumentException('Unknown option for ' . $pid . ': ' . (is_string($k) ? $k : '?'));
            }
        }
    }
    $rows = [];
    $seen = [];       // CODE => [name, presetName]
    $conflicts = [];
    $labels = [];
    $sources = [];
    foreach ($data['presets'] as $pid => $p) {
        if (!array_key_exists($pid, $selection)) {
            continue;
        }
        $labels[$p['label']] = true;
        $sources[] = $p['name'] . ' — ' . $p['source'];
        $chosen = $p['rows'];
        foreach ($p['options'] as $key => $o) {
            if (in_array($key, $selection[$pid], true)) {
                $chosen = array_merge($chosen, $o['rows']);
            }
        }
        foreach ($chosen as [$code, $name]) {
            $c = states_normalize_code($code);
            if (isset($seen[$c])) {
                if ($seen[$c][0] !== $name) {
                    $conflicts[] = ['code' => $c, 'kept' => $seen[$c][0], 'kept_from' => $seen[$c][1],
                                    'dropped' => $name, 'dropped_from' => $p['name']];
                }
                continue;
            }
            $seen[$c] = [$name, $p['name']];
            $rows[] = [$c, $name];
        }
    }
    return [
        'rows' => $rows,
        'conflicts' => $conflicts,
        'sources' => $sources,
        'label_suggestion' => states_label_suggestion(array_keys($labels)),
    ];
}

/** One label from the presets' own labels (Canada → Province; State + Province → State or Province). */
function states_label_suggestion(array $labels): string
{
    $labels = array_values(array_unique($labels));
    if (count($labels) === 1) {
        return $labels[0];
    }
    sort($labels);
    if ($labels === ['Province', 'State']) {
        return 'State or Province';
    }
    return count($labels) === 0 ? 'State' : 'Region';
}

function states_label_choices(): array
{
    return ['State', 'Province', 'State or Province', 'Region', 'County'];
}

// ─────────────────────────────────────────────────────────────────────────
// Diff / preview / apply
// ─────────────────────────────────────────────────────────────────────────

/** A fingerprint of the list, so an apply can refuse when the list changed after its preview. */
function states_list_hash(array $rows): string
{
    $lines = [];
    foreach ($rows as $r) {
        $lines[] = states_normalize_code((string) $r['code']) . "\t" . (string) $r['name'];
    }
    sort($lines, SORT_STRING);
    return sha1(implode("\n", $lines));
}

/**
 * Compare the current list with a target list.
 *
 * @param array  $current rows from states_list()
 * @param array  $target  [[code, name], ...] — codes already normalised and unique
 * @param string $mode    'replace' or 'add'
 */
function states_diff(array $current, array $target, string $mode): array
{
    if ($mode !== 'replace' && $mode !== 'add') {
        throw new InvalidArgumentException('Mode must be "replace" or "add".');
    }
    // Lowest id first, so the row kept for a code that appears twice (an
    // install the unique-key migration could not clean up) is the oldest.
    usort($current, static function ($a, $b) { return $a['id'] <=> $b['id']; });
    $byCode = [];
    $extraDuplicates = [];
    foreach ($current as $r) {
        $c = states_normalize_code($r['code']);
        if (isset($byCode[$c])) {
            $extraDuplicates[] = $r;
            continue;
        }
        $byCode[$c] = $r;
    }
    $adds = $renames = $skipped = $removes = [];
    $unchanged = 0;
    $targetCodes = [];
    foreach ($target as [$code, $name]) {
        $targetCodes[$code] = true;
        if (!isset($byCode[$code])) {
            $adds[] = ['code' => $code, 'name' => $name];
            continue;
        }
        $cur = $byCode[$code];
        if ($mode === 'add') {
            $skipped[] = ['code' => $code, 'name' => $name, 'existing_name' => $cur['name']];
            continue;
        }
        if ($cur['name'] !== $name || $cur['code'] !== $code) {
            $renames[] = ['id' => $cur['id'], 'code' => $code, 'old_code' => $cur['code'], 'old_name' => $cur['name'], 'new_name' => $name];
        } else {
            $unchanged++;
        }
    }
    if ($mode === 'replace') {
        foreach ($byCode as $c => $r) {
            if (!isset($targetCodes[$c])) {
                $removes[] = ['id' => $r['id'], 'code' => $r['code'], 'name' => $r['name']];
            }
        }
        foreach ($extraDuplicates as $r) {
            $removes[] = ['id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'duplicate' => true];
        }
        usort($removes, static function ($a, $b) { return strcmp($a['name'], $b['name']); });
    }
    $final = $mode === 'replace' ? count($target) : count($byCode) + count($extraDuplicates) + count($adds);
    return [
        'mode' => $mode,
        'adds' => $adds,
        'removes' => $removes,
        'renames' => $renames,
        'skipped' => $skipped,
        'unchanged' => $unchanged,
        'current_count' => count($current),
        'final_count' => $final,
    ];
}

/**
 * A target list must be [[code, name], ...] with normalised, valid, unique
 * codes and clean names — what states_build_selection() and
 * states_csv_parse() produce. Checked again here so no caller can write
 * anything else.
 *
 * @throws InvalidArgumentException
 */
function states_validate_target(array $target): void
{
    $seen = [];
    foreach ($target as $i => $row) {
        if (!is_array($row) || !isset($row[0], $row[1]) || !is_string($row[0]) || !is_string($row[1])) {
            throw new InvalidArgumentException('Entry ' . ($i + 1) . ' is not a code and a name.');
        }
        [$code, $name] = $row;
        if ($code !== states_normalize_code($code) || $name !== states_clean_name($name)) {
            throw new InvalidArgumentException("Entry {$code} is not in its stored form.");
        }
        states_validate_pair($code, $name);
        if (isset($seen[$code])) {
            throw new InvalidArgumentException("Code {$code} appears more than once.");
        }
        $seen[$code] = true;
    }
}

/** The diff plus, for every code being removed, how many saved records hold it. */
function states_preview(array $target, string $mode): array
{
    states_validate_target($target);
    $current = states_list();
    $diff = states_diff($current, $target, $mode);
    $removeCodes = array_map(static function ($r) { return $r['code']; }, $diff['removes']);
    $usage = states_usage($removeCodes);
    $records = 0;
    foreach ($diff['removes'] as $i => $r) {
        $u = $usage[states_normalize_code($r['code'])] ?? ['total' => 0, 'by' => []];
        // A duplicate row's code stays on the list (the kept row has it), so
        // nothing that holds it loses its entry.
        if (!empty($r['duplicate'])) {
            $u = ['total' => 0, 'by' => []];
        }
        $diff['removes'][$i]['usage'] = $u['total'];
        $diff['removes'][$i]['usage_by'] = $u['by'];
        $records += $u['total'];
    }
    $diff['records_using_removed'] = $records;
    $diff['list_hash'] = states_list_hash($current);
    $diff['too_many'] = $diff['final_count'] > STATES_MAX_ROWS;
    return $diff;
}

/**
 * Apply a target list. Recomputes the diff here — the client never sends one.
 * With $expectedHash (from the preview) the apply refuses when the list has
 * changed since, rather than act on a preview nobody saw. One transaction.
 *
 * @throws InvalidArgumentException  bad mode / too many rows
 * @throws StatesStaleException      when the list changed since the preview
 */
function states_apply(array $target, string $mode, ?string $expectedHash, string $source, string $activity = 'update'): array
{
    states_validate_target($target);
    $current = states_list();
    if ($expectedHash !== null && $expectedHash !== '' && !hash_equals(states_list_hash($current), $expectedHash)) {
        throw new StatesStaleException('The list changed after this preview was made. Preview again before applying.');
    }
    $diff = states_diff($current, $target, $mode);
    if ($diff['final_count'] > STATES_MAX_ROWS) {
        throw new InvalidArgumentException('The result would have ' . $diff['final_count'] . ' entries; the most the list can hold is ' . STATES_MAX_ROWS . '.');
    }
    $changed = count($diff['adds']) + count($diff['removes']) + count($diff['renames']);
    $result = [
        'changed' => $changed > 0,
        'mode' => $mode,
        'added' => count($diff['adds']),
        'removed' => count($diff['removes']),
        'renamed' => count($diff['renames']),
        'skipped' => count($diff['skipped']),
        'unchanged' => $diff['unchanged'],
        'final_count' => $diff['final_count'],
    ];
    if ($changed === 0) {
        return $result;
    }
    $usage = states_usage(array_map(static function ($r) { return $r['code']; }, $diff['removes']));

    $pdo = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($diff['removes'] as $r) {
            db_query('DELETE FROM ' . db_table('states_translator') . ' WHERE `id` = ?', [$r['id']]);
        }
        foreach ($diff['renames'] as $r) {
            db_query('UPDATE ' . db_table('states_translator') . ' SET `code` = ?, `name` = ? WHERE `id` = ?', [$r['code'], $r['new_name'], $r['id']]);
        }
        foreach ($diff['adds'] as $r) {
            db_query('INSERT INTO ' . db_table('states_translator') . ' (`code`, `name`) VALUES (?, ?)', [$r['code'], $r['name']]);
        }
        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    states_mark_customized();

    $removedDetail = [];
    foreach ($diff['removes'] as $r) {
        $removedDetail[] = ['code' => $r['code'], 'name' => $r['name'],
            'records_holding_code' => empty($r['duplicate']) ? (int) ($usage[states_normalize_code($r['code'])]['total'] ?? 0) : 0];
    }
    $summary = sprintf('%s the State / Province list from %s: %d added, %d removed, %d renamed, %d entries now',
        $mode === 'replace' ? 'Replaced' : 'Added to', mb_strimwidth($source, 0, 200, '…', 'UTF-8'), $result['added'], $result['removed'], $result['renamed'], $result['final_count']);
    audit_log('config', $activity, 'state_province', null, $summary, [
        'mode' => $mode,
        'source' => $source,
        'counts' => ['added' => $result['added'], 'removed' => $result['removed'], 'renamed' => $result['renamed'],
                     'skipped' => $result['skipped'], 'before' => $diff['current_count'], 'after' => $result['final_count']],
        'added' => array_map(static function ($r) { return $r['code']; }, $diff['adds']),
        'removed' => $removedDetail,
        'renamed' => array_map(static function ($r) {
            return ['code' => $r['code'], 'old_code' => $r['old_code'], 'old_name' => $r['old_name'], 'new_name' => $r['new_name']];
        }, $diff['renames']),
    ]);
    return $result;
}

// ─────────────────────────────────────────────────────────────────────────
// CSV
// ─────────────────────────────────────────────────────────────────────────

/**
 * One CSV cell for export. A value a spreadsheet would read as a formula
 * (= + - @ TAB CR) is prefixed with an apostrophe; states_csv_uncell() strips
 * it again on import, so an export re-imports unchanged.
 */
function states_csv_cell(string $v): string
{
    if ($v !== '' && strpos(STATES_CSV_FORMULA_CHARS, $v[0]) !== false) {
        $v = "'" . $v;
    }
    if (preg_match('/[",\r\n;]/', $v) || $v !== trim($v)) {
        $v = '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

/** Undo states_csv_cell()'s formula guard. */
function states_csv_uncell(string $v): string
{
    if (strlen($v) >= 2 && $v[0] === "'" && strpos(STATES_CSV_FORMULA_CHARS, $v[1]) !== false) {
        return substr($v, 1);
    }
    return $v;
}

/**
 * CSV text for a list of rows: UTF-8 with a byte-order mark (so Excel reads
 * accented names correctly), header "code,name", CRLF line endings.
 *
 * @param array $rows [[code, name], ...] or [['code'=>..,'name'=>..], ...]
 */
function states_csv_build(array $rows): string
{
    $out = "\xEF\xBB\xBF" . "code,name\r\n";
    foreach ($rows as $r) {
        $code = (string) ($r['code'] ?? $r[0]);
        $name = (string) ($r['name'] ?? $r[1]);
        $out .= states_csv_cell($code) . ',' . states_csv_cell($name) . "\r\n";
    }
    return $out;
}

/**
 * Parse an uploaded list. Header "code,name" (either order, any case, extra
 * columns ignored); "," or ";" delimiter (taken from the header line); UTF-8
 * with or without BOM; LF, CRLF or CR line endings; blank lines skipped.
 * Every problem is reported with its line; ANY problem blocks an apply.
 *
 * @return array{rows: array<int, array{0:string,1:string}>, lines: int[], errors: array<int, array{line:int, message:string}>}
 */
function states_csv_parse(string $text): array
{
    $errors = [];
    if (strlen($text) > STATES_IMPORT_MAX_BYTES) {
        return ['rows' => [], 'lines' => [], 'errors' => [['line' => 0, 'message' => 'The file is larger than ' . (STATES_IMPORT_MAX_BYTES / 1024) . ' KB.']]];
    }
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
        $text = substr($text, 3);
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        return ['rows' => [], 'lines' => [], 'errors' => [['line' => 0, 'message' => 'The file is not UTF-8 text. In Excel use Save As → "CSV UTF-8 (Comma delimited)".']]];
    }
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (trim($text) === '') {
        return ['rows' => [], 'lines' => [], 'errors' => [['line' => 0, 'message' => 'The file is empty.']]];
    }
    $firstLine = strtok(ltrim($text, "\n"), "\n");
    $delim = (strpos((string) $firstLine, ';') !== false && strpos((string) $firstLine, ',') === false) ? ';' : ',';

    // One record per physical line, so every reported line number is the
    // line a spreadsheet shows. (A name cannot contain a line break — it is a
    // control character — so a quoted field spanning lines is an error either
    // way.) str_getcsv, not fgetcsv on php://temp: no stream to open.
    $line = 0;
    $codeIdx = $nameIdx = null;
    $rows = [];
    $lines = [];
    $firstSeen = [];
    foreach (explode("\n", $text) as $physical) {
        $line++;
        if (trim($physical) === '') {
            continue;   // blank line
        }
        $rec = str_getcsv($physical, $delim, '"', '');
        if (count(array_filter($rec, static function ($c) { return trim((string) $c) !== ''; })) === 0) {
            continue;   // a line of empty cells (",,,")
        }
        if ($codeIdx === null) {
            $hdr = array_map(static function ($c) { return strtolower(trim((string) $c)); }, $rec);
            $codeIdx = array_search('code', $hdr, true);
            $nameIdx = array_search('name', $hdr, true);
            if ($codeIdx === false || $nameIdx === false) {
                return ['rows' => [], 'lines' => [], 'errors' => [['line' => $line, 'message' => 'The first line must be the header "code,name".']]];
            }
            continue;
        }
        if (count($rows) + count($errors) >= STATES_IMPORT_MAX_ROWS) {
            $errors[] = ['line' => $line, 'message' => 'The file has more than ' . STATES_IMPORT_MAX_ROWS . ' rows; the rest were not read.'];
            break;
        }
        $code = states_normalize_code(states_csv_uncell(trim((string) ($rec[$codeIdx] ?? ''))));
        $name = states_clean_name(states_csv_uncell(trim((string) ($rec[$nameIdx] ?? ''))));
        $err = states_code_error($code) ?? states_name_error($name);
        if ($err !== null) {
            $errors[] = ['line' => $line, 'message' => ($code !== '' ? $code . ': ' : '') . $err];
            continue;
        }
        if (isset($firstSeen[$code])) {
            $errors[] = ['line' => $line, 'message' => "Code {$code} appears more than once (first on line {$firstSeen[$code]})."];
            continue;
        }
        $firstSeen[$code] = $line;
        $rows[] = [$code, $name];
        $lines[] = $line;
    }
    if ($codeIdx === null) {
        $errors[] = ['line' => 0, 'message' => 'The file is empty.'];
    }
    return ['rows' => $rows, 'lines' => $lines, 'errors' => $errors];
}

// ─────────────────────────────────────────────────────────────────────────
// Field label (the caption every State label reads)
// ─────────────────────────────────────────────────────────────────────────

function states_sanitize_lang(string $lang): string
{
    $clean = strtolower(substr(preg_replace('/[^a-zA-Z0-9\-]/', '', $lang), 0, 8));
    return $clean !== '' ? $clean : 'en';
}

/** The label the State fields show for $lang (falls back to English, then "State"). */
function states_field_label(string $lang = 'en'): string
{
    $lang = states_sanitize_lang($lang);
    try {
        foreach (array_unique([$lang, 'en']) as $l) {
            $v = db_fetch_value(
                'SELECT `value` FROM ' . db_table('captions_i18n') . ' WHERE `caption_key` = ? AND `lang` = ?',
                [STATES_LABEL_CAPTION_KEY, $l]
            );
            if ($v !== false && $v !== null && trim((string) $v) !== '') {
                return (string) $v;
            }
        }
    } catch (Throwable $e) {
        error_log('[states] could not read the field label: ' . $e->getMessage());
    }
    return 'State';
}

/**
 * Set the label every State field shows, for one language. Writes caption
 * form.state (what every page reads) and keeps the two legacy keys
 * newinc.label.state / field.state in step where an install has them, so the
 * Translations list does not show a stale "State" beside the new label.
 *
 * @throws InvalidArgumentException on an empty/long/control-character label
 */
function states_set_field_label(string $label, string $lang = 'en'): array
{
    $label = states_clean_name($label);
    if ($label === '' || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label, 'UTF-8') > STATES_LABEL_MAX
        || preg_match('/[\x00-\x1F\x7F<>]/', $label)) {
        throw new InvalidArgumentException('The label must be 1 to ' . STATES_LABEL_MAX . ' characters, without < or >.');
    }
    $lang = states_sanitize_lang($lang);
    $previous = states_field_label($lang);
    db_query(
        'INSERT INTO ' . db_table('captions_i18n') . " (`caption_key`, `lang`, `value`, `category`) VALUES (?, ?, ?, 'form')
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [STATES_LABEL_CAPTION_KEY, $lang, $label]
    );
    foreach (['newinc.label.state', 'field.state'] as $legacyKey) {
        db_query('UPDATE ' . db_table('captions_i18n') . ' SET `value` = ? WHERE `caption_key` = ? AND `lang` = ?', [$label, $legacyKey, $lang]);
    }
    audit_log('config', 'update', 'caption', null, "Set the State field label to \"{$label}\" ({$lang})",
        ['caption_key' => STATES_LABEL_CAPTION_KEY, 'lang' => $lang, 'old' => $previous, 'new' => $label]);
    return ['label' => $label, 'lang' => $lang, 'previous' => $previous];
}

// ─────────────────────────────────────────────────────────────────────────
// Health of the table itself (shown on the page)
// ─────────────────────────────────────────────────────────────────────────

/** Codes that appear on more than one row (case-insensitive). */
function states_duplicate_codes(): array
{
    try {
        $rows = db_fetch_all(
            'SELECT UPPER(TRIM(`code`)) AS c, COUNT(*) AS n FROM ' . db_table('states_translator')
            . ' GROUP BY UPPER(TRIM(`code`)) HAVING COUNT(*) > 1 ORDER BY c'
        );
    } catch (Throwable $e) {
        return [];
    }
    return array_map(static function ($r) { return ['code' => (string) $r['c'], 'rows' => (int) $r['n']]; }, $rows);
}

/** Is the table utf8mb4, and does it carry the unique key on code? */
function states_schema_status(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $status = ['utf8mb4' => false, 'unique_code' => false, 'duplicates' => states_duplicate_codes()];
    try {
        $cs = db_fetch_all(
            "SELECT `COLUMN_NAME` AS c, `CHARACTER_SET_NAME` AS cs FROM information_schema.COLUMNS
              WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` IN ('code', 'name')",
            [$prefix . 'states_translator']
        );
        $ok = count($cs) === 2;
        foreach ($cs as $r) {
            if (strtolower((string) $r['cs']) !== 'utf8mb4') {
                $ok = false;
            }
        }
        $status['utf8mb4'] = $ok;
        $status['unique_code'] = states_has_unique_code_index();
    } catch (Throwable $e) {
        error_log('[states] schema status check failed: ' . $e->getMessage());
    }
    return $status;
}

/** True when a single-column UNIQUE index on `code` exists. */
function states_has_unique_code_index(): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all(
        "SELECT `INDEX_NAME` AS i, COUNT(*) AS cols, MAX(`COLUMN_NAME`) AS col, MIN(`NON_UNIQUE`) AS nu
           FROM information_schema.STATISTICS
          WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?
          GROUP BY `INDEX_NAME`",
        [$prefix . 'states_translator']
    );
    foreach ($rows as $r) {
        if ((int) $r['cols'] === 1 && strtolower((string) $r['col']) === 'code' && (int) $r['nu'] === 0) {
            return true;
        }
    }
    return false;
}

