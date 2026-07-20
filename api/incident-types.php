<?php
/**
 * NewUI v4.0 API - Incident Types & Form Lookup Data
 *
 * GET /api/incident-types.php
 *   Returns incident types, facilities, and responders for the new-incident form.
 */

require_once __DIR__ . '/auth.php';

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

// Incident types
$types = safe_fetch_all(
    "SELECT `id`, `type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `match_pattern`
     FROM `{$prefix}in_types`
     ORDER BY `sort`, `type`"
);

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

// Available responders — include active assignment count for real availability status
$responders = safe_fetch_all(
    "SELECT `r`.`id`, `r`.`name`, `r`.`handle`, `r`.`type`,
            `s`.`description` AS `status`,
            (SELECT COUNT(*) FROM `{$prefix}assigns` `a`
             WHERE `a`.`responder_id` = `r`.`id` AND `a`.`clear` IS NULL) AS `active_assignments`
     FROM `{$prefix}responder` `r`
     LEFT JOIN `{$prefix}un_status` `s` ON `r`.`un_status_id` = `s`.`id`
     WHERE (`r`.`hide` = 0 OR `r`.`hide` IS NULL)
     ORDER BY `r`.`name`"
);
if (empty($responders)) {
    $responders = safe_fetch_all(
        "SELECT `r`.`id`, `r`.`name`, `r`.`handle`, `r`.`type`,
                `s`.`description` AS `status`,
                (SELECT COUNT(*) FROM `{$prefix}assigns` `a`
                 WHERE `a`.`responder_id` = `r`.`id` AND `a`.`clear` IS NULL) AS `active_assignments`
         FROM `{$prefix}responder` `r`
         LEFT JOIN `{$prefix}un_status` `s` ON `r`.`un_status_id` = `s`.`id`
         ORDER BY `r`.`name`"
    );
}
if (empty($responders)) {
    $responders = safe_fetch_all(
        "SELECT `id`, `name`, `handle`, `type`, '' AS `status`, 0 AS `active_assignments`
         FROM `{$prefix}responder`
         ORDER BY `name`"
    );
}

// Severity color map
$sev_colors = [
    0 => get_variable('sev_0_color') ?: '#00ff00',
    1 => get_variable('sev_1_color') ?: '#ffff00',
    2 => get_variable('sev_2_color') ?: '#ff0000',
];

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
    'states'           => $states,
    'signals'          => $signals,
    'major_incidents'  => $major_incidents,
]);
