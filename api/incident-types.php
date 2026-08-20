<?php
/**
 * NewUI v4.0 API - Incident Types & Form Lookup Data
 *
 * GET /api/incident-types.php
 *   Returns incident types, facilities, and responders for the new-incident form.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/severity.php';

// Suppress PHP warnings/notices from corrupting JSON output
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

// Helper: safe query that returns empty array on failure
function safe_fetch_all($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_fetch_all] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

// Incident types. default_security_label_id (Phase 18a) drives the
// creation-form sensitivity hint (Eric, 2026-08-12) — two-tier query
// because safe_fetch_all() returns [] on ANY error, and an empty type
// list here means a dispatcher cannot create an incident at all. Never
// let an optional column's absence take down the whole list.
try {
    $types = db_fetch_all(
        "SELECT `id`, `type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `match_pattern`, `default_security_label_id`
         FROM `{$prefix}in_types`
         ORDER BY `sort`, `type`"
    );
} catch (Exception $e) {
    $types = safe_fetch_all(
        "SELECT `id`, `type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `match_pattern`
         FROM `{$prefix}in_types`
         ORDER BY `sort`, `type`"
    );
}

// Facilities — exclude soft-deleted rows. The legacy `hide` column
// never made it into the v4.0 schema; soft-delete via `deleted_at`
// is the modern convention (Phase 70-series wastebasket). The OR
// branch keeps the query working on the rare install where
// deleted_at is still NULL-typed without a default.
$facilities = safe_fetch_all(
    "SELECT `id`, `name`, `type`, `lat`, `lng`
     FROM `{$prefix}facilities`
     WHERE `deleted_at` IS NULL
     ORDER BY `name`"
);
if (empty($facilities)) {
    // Final fallback: list everything if soft-delete column is absent
    // on an even older install.
    $facilities = safe_fetch_all(
        "SELECT `id`, `name`, `type`, `lat`, `lng`
         FROM `{$prefix}facilities`
         ORDER BY `name`"
    );
}

// Available responders — include active assignment count for real availability status.
//
// GH#40 (Chris Byrd, 2026-08-07): "All assign responders are duplicated" on
// the New Incident screen. The old 3-tier hide-column try/fallback never
// actually reached a deleted_at filter in practice: base_schema.sql's
// responder table has no `hide` column at all, so on every fresh v4.0
// install (Chris's included) the first two tiers threw "unknown column
// r.hide" and silently fell through to the third, which had NO soft-delete
// filter whatsoever -- every responder, including ones soft-deleted and
// re-added under the same name (a common cleanup step), showed up next to
// its replacement, reading as "duplicated." Detect both optional columns
// via information_schema instead, matching api/responders.php's own real
// list query (which already gets this right) -- one query, correct on
// fresh installs, legacy installs with `hide`, and everything between.
$hasHide = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'hide'",
    [$prefix . 'responder']
);
$hasDeletedAt = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
    [$prefix . 'responder']
);
$responderFilters = [];
if ($hasHide) $responderFilters[] = '(`r`.`hide` = 0 OR `r`.`hide` IS NULL)';
if ($hasDeletedAt) $responderFilters[] = '`r`.`deleted_at` IS NULL';
$responderWhere = $responderFilters ? ('WHERE ' . implode(' AND ', $responderFilters)) : '';

$responders = safe_fetch_all(
    "SELECT `r`.`id`, `r`.`name`, `r`.`handle`, `r`.`type`,
            `s`.`description` AS `status`,
            (SELECT COUNT(*) FROM `{$prefix}assigns` `a`
             WHERE `a`.`responder_id` = `r`.`id` AND `a`.`clear` IS NULL) AS `active_assignments`
     FROM `{$prefix}responder` `r`
     LEFT JOIN `{$prefix}un_status` `s` ON `r`.`un_status_id` = `s`.`id`
     {$responderWhere}
     ORDER BY `r`.`name`"
);
if (empty($responders)) {
    $responders = safe_fetch_all(
        "SELECT `id`, `name`, `handle`, `type`, '' AS `status`, 0 AS `active_assignments`
         FROM `{$prefix}responder`
         ORDER BY `name`"
    );
}

// GH#87/GH#88 (2026-08-19) — the full configured severity scale, not just
// a 3-entry color map. assets/js/new-incident.js populates the Severity
// dropdown AND resolves the incident type's auto-set value from this same
// list, which is what makes the two agree by construction (see
// inc/severity.php's docblock). $sev_colors is kept, unchanged shape, for
// any external consumer that was already reading it.
$sev_colors = severity_color_map();
$severity_levels = severity_levels_for_json();

// States for address dropdown
$states = safe_fetch_all(
    "SELECT `code`, `name` FROM `{$prefix}states_translator` ORDER BY `name`"
);

// Signals — UNION `signals` + `codes`.
// Issue #31 (a beta tester + a beta tester, 2026-07-02..2026-07-03): two tables
// hold the same semantic concept because a schema-split happened
// mid-development.
//
//   * `signals`  (columns: code, description)  — added 2026-07-02
//     as the "official" signals home. Configured via
//     `Config → Signal Codes`.
//   * `codes`    (columns: code, text)         — legacy from before
//     the split, still surfaced in `Config → Standard Messages`.
//     a beta tester's install has real data here (10-4, 10-19, etc.) and
//     no rows in `signals`; the dropdown was empty because the
//     API only read `signals`.
//
// Rather than force every admin to migrate, UNION both tables so
// whichever one the operator populated shows up in the incident
// form. Prefix code onto description to give dispatchers the
// familiar "10-4 — acknowledged" combo. safe_fetch_all() handles
// missing-table gracefully on either side.
$signalsA = safe_fetch_all(
    "SELECT `id`, `code`, `description` FROM `{$prefix}signals`
     WHERE (`hide` IS NULL OR `hide` <> 'y')
     ORDER BY `sort_order`, `code`"
);
$signalsB = safe_fetch_all(
    "SELECT `id`, `code`, `text` AS `description`
       FROM `{$prefix}codes`
      ORDER BY `sort`, `code`"
);
$signalsMerged = [];
$seen = [];
foreach (array_merge($signalsA, $signalsB) as $row) {
    $code = trim((string) ($row['code'] ?? ''));
    if ($code === '') continue;
    $key = mb_strtolower($code);
    if (isset($seen[$key])) continue;   // signals wins over codes on dupe
    $seen[$key] = true;
    $desc = trim((string) ($row['description'] ?? ''));
    $label = $desc !== '' ? ($code . ' — ' . $desc) : $code;
    $signalsMerged[] = [
        'id'          => (int) ($row['id'] ?? 0),
        'code'        => $code,
        'description' => $label,
    ];
}
// The client still consumes `signals[].description` for the option
// label — passing the pre-formatted "code — description" string keeps
// zero client code changes.
$signals = $signalsMerged;

// Major incidents — a beta tester beta 2026-06-29: original query looked at
// the wrong table entirely. Major incidents live in
// newui_major_incidents (managed by api/major-incidents.php), not in
// the ticket table. The old query was searching ticket WHERE status=2
// AND severity=2 — that's just "open high-severity tickets," nothing
// to do with the major-incident management feature. Result: dropdown
// always empty on installs that hadn't happened to file a high-sev
// open ticket recently. Now we query the actual majors table.
$major_incidents = safe_fetch_all(
    "SELECT `id`, `name`, `description`, `severity`
       FROM `{$prefix}newui_major_incidents`
      WHERE `status` = 'open'
      ORDER BY `created_at` DESC
      LIMIT 50"
);

ini_set('display_errors', $prevDisplay);

json_response([
    'types'            => $types,
    'facilities'       => $facilities,
    'responders'       => $responders,
    'sev_colors'       => $sev_colors,
    'severity_levels'  => $severity_levels,
    'states'           => $states,
    'signals'          => $signals,
    'major_incidents'  => $major_incidents,
]);
