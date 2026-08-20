<?php
/**
 * GH#87 / GH#88 (2026-08-19) — single source of truth for the severity
 * scale (level count, labels, colors, default, high-alert flag).
 *
 * Before this file existed, `ticket.severity` / `in_types.set_severity`
 * were a fixed 0-2 integer that the SERVER read literally while the
 * CLIENT (assets/js/new-incident.js) independently believed the same
 * `in_types.set_severity` column was a legacy 1-5 scale and remapped it
 * down to 0-2 before display. Two disconnected readings of one column,
 * disagreeing on 33 of 37 real incident types (GH#87) — and, separately,
 * at least four more hardcoded copies of the label set scattered across
 * other screens, all spelling the same three integers differently
 * (GH#88: Normal/Elevated/Critical, Low/Medium/High, Low/Med/High,
 * Normal/Medium/High). Every consumer that needs a label, a color, a
 * clamp range, or a "should this fire the high-severity notification"
 * answer must go through this file's functions — never a hardcoded
 * array or a literal `severity >= 2` comparison. See CLAUDE.md's
 * schema-mismatch pitfall entries for why that pattern keeps recurring
 * in this codebase.
 *
 * Design (GH#88's admin-configurable scale):
 *
 *   - `value` is assigned ONCE, at creation, and is immutable afterward
 *     (severity_level_next_value() always returns MAX(value)+1). This
 *     is the guarantee that existing `ticket.severity` /
 *     `in_types.set_severity` data is NEVER reinterpreted when an admin
 *     edits the scale later — a historical incident's stored integer
 *     always resolves to whatever level currently owns that value, and
 *     that ownership never moves. Renaming a level's label/color is
 *     fine and expected; reusing or reassigning its `value` is not
 *     possible through the admin CRUD (api/config-admin.php?section=
 *     severity_levels never accepts a client-supplied value).
 *
 *   - `sort_order` is a SEPARATE, freely-editable display-order field,
 *     independent of `value`. This is what lets an agency that speaks
 *     "Priority 1 = most urgent" assign that label to whichever
 *     `value` it likes and have it sort first in every dropdown,
 *     without touching the immutable value or any numeric severity
 *     comparison anywhere in the app (there are none left — see
 *     is_high_alert below). This is the decoupling GH#88's reporter
 *     flagged as the one design point that "isn't obvious."
 *
 *   - `is_high_alert` replaces the old hardcoded `severity >= 2` /
 *     `severity === 2` thresholds used to fire the severity_high
 *     notification and decide badge text contrast. Any number of
 *     levels can be flagged high-alert; nothing assumes the highest
 *     VALUE is the most severe one.
 *
 *   - `is_default` marks the level a brand-new incident starts at
 *     (matches the pre-existing severity=0 default behavior). Exactly
 *     one row should carry it; severity_default_value() falls back to
 *     the lowest configured value if none do, so a misconfigured
 *     install degrades safely rather than erroring.
 *
 * Graceful degradation: on an unmigrated install (table missing) or one
 * where every level was somehow deleted, severity_levels_load() falls
 * back to the exact historical 3-level scale (Normal/Elevated/Critical,
 * 0/1/2) so every consumer still renders something correct instead of
 * an empty dropdown or a fatal error.
 */

declare(strict_types=1);

if (!array_key_exists('__severity_levels_cache', $GLOBALS)) {
    $GLOBALS['__severity_levels_cache'] = null;
}

/**
 * Load all configured severity levels, ordered for display (sort_order,
 * then value as a tiebreaker). Cached per-request; pass true to force a
 * re-read (e.g. immediately after an admin CRUD write in the same
 * request).
 *
 * @return array<int,array{id:int,value:int,label:string,color:string,sort_order:int,is_default:bool,is_high_alert:bool}>
 */
function severity_levels_load(bool $fresh = false): array {
    if (!$fresh && $GLOBALS['__severity_levels_cache'] !== null) {
        return $GLOBALS['__severity_levels_cache'];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = [];
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `value`, `label`, `color`, `sort_order`, `is_default`, `is_high_alert`
               FROM `{$prefix}severity_levels`
              ORDER BY `sort_order` ASC, `value` ASC"
        );
    } catch (Exception $e) {
        $rows = [];
    }

    if (empty($rows)) {
        // Unmigrated install, or every level was deleted — degrade to the
        // historical fixed scale rather than leaving consumers with nothing.
        $rows = [
            ['id' => 0, 'value' => 0, 'label' => 'Normal',   'color' => '#00ff00', 'sort_order' => 0, 'is_default' => 1, 'is_high_alert' => 0],
            ['id' => 0, 'value' => 1, 'label' => 'Elevated', 'color' => '#ffff00', 'sort_order' => 1, 'is_default' => 0, 'is_high_alert' => 0],
            ['id' => 0, 'value' => 2, 'label' => 'Critical', 'color' => '#ff0000', 'sort_order' => 2, 'is_default' => 0, 'is_high_alert' => 1],
        ];
    }

    foreach ($rows as &$r) {
        $r['id']            = (int) $r['id'];
        $r['value']         = (int) $r['value'];
        $r['label']         = (string) $r['label'];
        $r['color']         = (string) $r['color'];
        $r['sort_order']    = (int) $r['sort_order'];
        $r['is_default']    = ((int) $r['is_default']) === 1;
        $r['is_high_alert'] = ((int) $r['is_high_alert']) === 1;
    }
    unset($r);

    $GLOBALS['__severity_levels_cache'] = $rows;
    return $rows;
}

/** Drop the process-local cache — call after any admin CRUD write. */
function severity_levels_reset_cache(): void {
    $GLOBALS['__severity_levels_cache'] = null;
}

/** All currently-valid stored values, e.g. [0, 1, 2]. */
function severity_valid_values(): array {
    return array_values(array_map(function ($r) { return $r['value']; }, severity_levels_load()));
}

/** The value a brand-new incident should start at. */
function severity_default_value(): int {
    foreach (severity_levels_load() as $r) {
        if ($r['is_default']) return $r['value'];
    }
    $values = severity_valid_values();
    return $values ? min($values) : 0;
}

/**
 * Clamp an arbitrary input to a currently-valid configured severity
 * value. Anything not in the configured set (out of range, a gap left
 * by a deleted level, a non-numeric string) falls back to the default
 * level rather than writing an unresolvable integer into `ticket.severity`
 * / `in_types.set_severity` — this is the fix for GH#87's second,
 * unreproduced concern (`set_severity` reaching the ticket table
 * unclamped, past every label map).
 */
function severity_clamp($value): int {
    $v = (int) $value;
    if (in_array($v, severity_valid_values(), true)) {
        return $v;
    }
    return severity_default_value();
}

/** The next value a newly-created level should be assigned. Immutable-value guarantee — see docblock. */
function severity_next_value(): int {
    $values = severity_valid_values();
    return $values ? (max($values) + 1) : 0;
}

function severity_label(int $value): string {
    foreach (severity_levels_load() as $r) {
        if ($r['value'] === $value) return $r['label'];
    }
    return 'Unknown';
}

function severity_color(int $value): string {
    foreach (severity_levels_load() as $r) {
        if ($r['value'] === $value) return $r['color'];
    }
    return '#6c757d';
}

/** [value => label] for every configured level — replaces hardcoded ['Normal','Elevated','Critical'] arrays. */
function severity_label_map(): array {
    $map = [];
    foreach (severity_levels_load() as $r) $map[$r['value']] = $r['label'];
    return $map;
}

/** [value => color] for every configured level — replaces the repeated $sev_colors = [0=>get_variable('sev_0_color')...] blocks. */
function severity_color_map(): array {
    $map = [];
    foreach (severity_levels_load() as $r) $map[$r['value']] = $r['color'];
    return $map;
}

function severity_is_high_alert(int $value): bool {
    foreach (severity_levels_load() as $r) {
        if ($r['value'] === $value) return $r['is_high_alert'];
    }
    return false;
}

/** Ordered list shaped for JSON API responses (New Incident dropdown, incident-type editor, admin CRUD table). */
function severity_levels_for_json(): array {
    $out = [];
    foreach (severity_levels_load() as $r) {
        $out[] = [
            'id'            => $r['id'],
            'value'         => $r['value'],
            'label'         => $r['label'],
            'color'         => $r['color'],
            'sort_order'    => $r['sort_order'],
            'is_default'    => $r['is_default'],
            'is_high_alert' => $r['is_high_alert'],
        ];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════
//  ADMIN CRUD — extracted here (not inline in api/config-admin.php)
//  so both the endpoint AND a test can drive the real writer, per
//  this project's own standing convention (see CLAUDE.md's
//  OT_CONFIG_LIBRARY_ONLY pitfall entry: "reusable/testable logic
//  goes in an inc/*.php include, not buried after an action-dispatch
//  guard in an api/*.php endpoint"). api/config-admin.php's
//  severity_levels section is a thin wrapper: parse input, call one
//  of these, translate the result to json_response()/json_error().
// ═══════════════════════════════════════════════════════════════

/**
 * Create a new severity level. `value` is NEVER a caller-supplied
 * parameter — it is always severity_next_value() (MAX(value)+1),
 * which is the whole immutability guarantee this feature rests on.
 *
 * @return array{ok:bool,id:?int,value:?int,error:?string}
 */
function severity_level_create(string $label, string $color, int $sortOrder, bool $isDefault, bool $isHighAlert, ?int $byUserId = null, ?string $byUserName = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $label = trim($label);
    if ($label === '') {
        return ['ok' => false, 'id' => null, 'value' => null, 'error' => 'Label is required'];
    }
    if (strlen($label) > 30) $label = substr($label, 0, 30);
    $color = trim($color);
    if ($color === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#6c757d';
    }

    try {
        $value = severity_next_value();
        if ($isDefault) {
            db_query("UPDATE `{$prefix}severity_levels` SET `is_default` = 0");
        }
        db_query(
            "INSERT INTO `{$prefix}severity_levels`
                (`value`, `label`, `color`, `sort_order`, `is_default`, `is_high_alert`, `_by`, `_from`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$value, $label, $color, $sortOrder, $isDefault ? 1 : 0, $isHighAlert ? 1 : 0, $byUserId, $byUserName]
        );
        $id = (int) db_insert_id();
        severity_levels_reset_cache();
        return ['ok' => true, 'id' => $id, 'value' => $value, 'error' => null];
    } catch (Exception $e) {
        error_log('severity_level_create failed: ' . $e->getMessage());
        return ['ok' => false, 'id' => null, 'value' => null, 'error' => 'Save failed.'];
    }
}

/**
 * Update an existing severity level's label/color/sort_order/flags.
 * `value` is intentionally not a parameter — see severity_level_create().
 *
 * @return array{ok:bool,error:?string}
 */
function severity_level_update(int $id, string $label, string $color, int $sortOrder, bool $isDefault, bool $isHighAlert, ?int $byUserId = null, ?string $byUserName = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $label = trim($label);
    if ($label === '') {
        return ['ok' => false, 'error' => 'Label is required'];
    }
    if (strlen($label) > 30) $label = substr($label, 0, 30);
    $color = trim($color);
    if ($color === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#6c757d';
    }

    try {
        if ($isDefault) {
            // Exactly one row may carry is_default — clear it on every
            // other row before setting it here.
            db_query("UPDATE `{$prefix}severity_levels` SET `is_default` = 0 WHERE `id` != ?", [$id]);
        }
        db_query(
            "UPDATE `{$prefix}severity_levels`
                SET `label` = ?, `color` = ?, `sort_order` = ?, `is_default` = ?, `is_high_alert` = ?,
                    `_by` = ?, `_from` = ?
              WHERE `id` = ?",
            [$label, $color, $sortOrder, $isDefault ? 1 : 0, $isHighAlert ? 1 : 0, $byUserId, $byUserName, $id]
        );
        // A level must always have exactly one default. If this update
        // just turned OFF the only default (unchecked the box on the row
        // that held it), promote the lowest-value remaining row rather
        // than leaving none configured — severity_default_value() would
        // fall back safely either way, but keeping exactly one row
        // flagged is the least surprising state for the admin UI to show.
        if (!$isDefault) {
            $stillHasDefault = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}severity_levels` WHERE `is_default` = 1");
            if ($stillHasDefault === 0) {
                db_query(
                    "UPDATE `{$prefix}severity_levels` SET `is_default` = 1
                      WHERE `id` = (SELECT `id` FROM (SELECT `id` FROM `{$prefix}severity_levels` ORDER BY `value` ASC LIMIT 1) `x`)"
                );
            }
        }
        severity_levels_reset_cache();
        return ['ok' => true, 'error' => null];
    } catch (Exception $e) {
        error_log('severity_level_update failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Save failed.'];
    }
}

/**
 * Delete a severity level, refusing when it would lose data or leave
 * the scale in an invalid state:
 *   - the last remaining level (must always have at least one)
 *   - the level currently flagged is_default
 *   - a level any ticket.severity or in_types.set_severity row still
 *     references (deleting it would leave that historical/configured
 *     value pointing at nothing — the exact "silently reinterpret
 *     existing data" this feature must never do)
 *
 * @return array{ok:bool,error:?string}
 */
function severity_level_delete(int $id): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $row = db_fetch_one("SELECT `value`, `is_default` FROM `{$prefix}severity_levels` WHERE `id` = ?", [$id]);
        if (!$row) {
            return ['ok' => false, 'error' => 'Not found'];
        }
        $value = (int) $row['value'];

        $totalLevels = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}severity_levels`");
        if ($totalLevels <= 1) {
            return ['ok' => false, 'error' => 'Cannot delete the last remaining severity level — at least one must exist.'];
        }
        if ((int) $row['is_default'] === 1) {
            return ['ok' => false, 'error' => 'Cannot delete the default severity level. Set a different level as default first.'];
        }

        $ticketRefs = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}ticket` WHERE `severity` = ?", [$value]
        );
        $typeRefs = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}in_types` WHERE `set_severity` = ?", [$value]
        );
        if ($ticketRefs > 0 || $typeRefs > 0) {
            return ['ok' => false, 'error' => "Cannot delete: {$ticketRefs} incident(s) and {$typeRefs} incident type(s) currently use this severity level."];
        }

        db_query("DELETE FROM `{$prefix}severity_levels` WHERE `id` = ?", [$id]);
        severity_levels_reset_cache();
        return ['ok' => true, 'error' => null];
    } catch (Exception $e) {
        error_log('severity_level_delete failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Delete failed.'];
    }
}
