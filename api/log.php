<?php
/**
 * NewUI v4.0 API - Audit Log
 *
 * GET /api/log.php?days=7
 *
 * Returns recent log entries with user names, responder names,
 * and meaningful info text. Code values match legacy constants
 * from functions_nm.inc.php.
 */

require_once __DIR__ . '/auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$days = max(1, min(365, (int) ($_GET['days'] ?? 7)));

// ── Code type descriptions (matching legacy LOG_* constants) ──
$code_types = [
    1    => 'Sign In',
    2    => 'Sign Out',
    3    => 'Comment',
    10   => 'Incident Opened',
    11   => 'Incident Closed',
    12   => 'Incident Changed',
    13   => 'Action Added',
    14   => 'Patient Added',
    15   => 'Incident Deleted',
    16   => 'Action Deleted',
    17   => 'Patient Deleted',
    20   => 'Unit Status',
    21   => 'Run Complete',
    22   => 'Unit Changed',
    30   => 'Dispatched',
    31   => 'Responding',
    32   => 'On Scene',
    33   => 'Cleared',
    34   => 'Reset',
    35   => 'Rec Facility Set',
    36   => 'Rec Facility Changed',
    37   => 'Rec Facility Unset',
    38   => 'Rec Facility Cleared',
    40   => 'Facility Added',
    41   => 'Facility Changed',
    42   => 'Fac Incident Open',
    43   => 'Fac Incident Close',
    44   => 'Fac Incident Change',
    45   => 'En Route Facility',
    46   => 'At Facility',
    47   => 'Facility Dispatched',
    48   => 'Facility Responding',
    49   => 'Facility On Scene',
    50   => 'Facility Cleared',
    51   => 'Facility Reset',
    122  => 'Auto-Status',
    4040 => 'Facility Status',
];

// ── Fetch log entries with user + responder + ticket joins ──
$sql = "SELECT
    `l`.*,
    `u`.`user`    AS `user_name`,
    `r`.`handle`  AS `responder_handle`,
    `r`.`name`    AS `responder_name`,
    `t`.`scope`   AS `ticket_scope`,
    `t`.`incident_number` AS `ticket_incident_number`
FROM `{$prefix}log` `l`
LEFT JOIN `{$prefix}user` `u` ON `l`.`who` = `u`.`id`
LEFT JOIN `{$prefix}responder` `r` ON `l`.`responder_id` = `r`.`id`
LEFT JOIN `{$prefix}ticket` `t` ON `l`.`ticket_id` = `t`.`id`
WHERE `l`.`code` NOT IN (90, 127, 5000)
  AND `l`.`when` >= CURRENT_DATE - INTERVAL ? DAY
ORDER BY `l`.`id` DESC
LIMIT 1000";

try {
    $rows = db_fetch_all($sql, [$days]);
} catch (Exception $e) {
    // Fallback without joins if table schema differs
    try {
        $rows = db_fetch_all(
            "SELECT * FROM `{$prefix}log`
             WHERE `code` NOT IN (90, 127, 5000)
               AND `when` >= CURRENT_DATE - INTERVAL ? DAY
             ORDER BY `id` DESC LIMIT 1000",
            [$days]
        );
    } catch (Exception $e2) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e2->getMessage(), 500);
    }
}

$entries = [];
foreach ($rows as $row) {
    $code = (int) ($row['code'] ?? 0);
    $code_type = $code_types[$code] ?? "Code {$code}";
    $user_name = $row['user_name'] ?? '';

    // Build meaningful info text
    $info = buildInfoText($code, $row);

    $entries[] = [
        'id'            => (int) $row['id'],
        'when'          => $row['when'],
        'code'          => $code,
        'code_type'     => $code_type,
        'by'            => $user_name,
        'from'          => $row['from'] ?? '',
        'ticket_id'     => (int) ($row['ticket_id'] ?? 0),
        // GH #86 — configured case number when set (else empty; consumers fall
        // back to '#' . ticket_id). The `info` text already embeds it.
        'incident_number' => (isset($row['ticket_incident_number']) && trim((string) $row['ticket_incident_number']) !== '')
            ? trim((string) $row['ticket_incident_number']) : '',
        'responder_id'  => (int) ($row['responder_id'] ?? 0),
        'responder'     => $row['responder_handle'] ?? $row['responder_name'] ?? '',
        'ticket_scope'  => $row['ticket_scope'] ?? '',
        'info'          => $info,
    ];
}

// ── GH #78 — merge Unit + Facility notes into the recent-events feed ──
// The Situation view's Events panel and the dashboard Recent Events widget
// both read this endpoint, so notes surface in both. Each note is normalized
// into the SAME row shape the loop above emits (when / code_type / by / info /
// ticket_id) so no consumer needs special handling. Both note tables may be
// ABSENT on installs that have never written a note (they self-create on first
// write) — each block is guarded so a missing table simply contributes zero
// rows. The note text goes into `info`, which every consumer escapes with
// esc() before rendering (verified: renderLog() app.js + the situation
// renderer), so it is not an XSS vector here.

// Unit notes (responder_notes). Soft-deleted rows (deleted_at) are excluded.
try {
    $unitNotes = db_fetch_all(
        "SELECT `n`.`note`, `n`.`by_username`, `n`.`created_at`, `n`.`responder_id`,
                `r`.`handle`, `r`.`name`
           FROM `{$prefix}responder_notes` `n`
           LEFT JOIN `{$prefix}responder` `r` ON `n`.`responder_id` = `r`.`id`
          WHERE `n`.`deleted_at` IS NULL
            AND `n`.`created_at` >= CURRENT_DATE - INTERVAL ? DAY
          ORDER BY `n`.`created_at` DESC
          LIMIT 500",
        [$days]
    );
    foreach ($unitNotes as $n) {
        $unit = $n['handle'] ?: ($n['name'] ?: ('unit #' . (int) $n['responder_id']));
        $entries[] = [
            'id'           => 0,
            'when'         => $n['created_at'],
            'code'         => 3,               // cosmetic; consumers show code_type
            'code_type'    => 'Unit Note',
            'by'           => $n['by_username'] ?? '',
            'from'         => '',
            'ticket_id'    => 0,
            'responder_id' => (int) $n['responder_id'],
            'responder'    => $unit,
            'ticket_scope' => '',
            'info'         => $unit . ': ' . (string) $n['note'],
        ];
    }
} catch (Throwable $e) {
    // responder_notes absent on this install — no unit notes to merge.
}

// Facility notes (facility_notes). No soft-delete column on this table.
try {
    $facNotes = db_fetch_all(
        "SELECT `n`.`note`, `n`.`username`, `n`.`created_at`, `n`.`facility_id`,
                `f`.`name` AS `facility_name`
           FROM `{$prefix}facility_notes` `n`
           LEFT JOIN `{$prefix}facilities` `f` ON `n`.`facility_id` = `f`.`id`
          WHERE `n`.`created_at` >= CURRENT_DATE - INTERVAL ? DAY
          ORDER BY `n`.`created_at` DESC
          LIMIT 500",
        [$days]
    );
    foreach ($facNotes as $n) {
        $fac = $n['facility_name'] ?: ('facility #' . (int) $n['facility_id']);
        $entries[] = [
            'id'           => 0,
            'when'         => $n['created_at'],
            'code'         => 4040,            // cosmetic; consumers show code_type
            'code_type'    => 'Facility Note',
            'by'           => $n['username'] ?? '',
            'from'         => '',
            'ticket_id'    => 0,
            'responder_id' => 0,
            'responder'    => '',
            'ticket_scope' => '',
            'info'         => $fac . ': ' . (string) $n['note'],
        ];
    }
} catch (Throwable $e) {
    // facility_notes absent on this install — no facility notes to merge.
}

// Re-sort the merged feed newest-first and cap. Notes and log rows share the
// `when` field (both DATETIME strings), so a lexical compare sorts correctly.
usort($entries, function ($a, $b) {
    return strcmp((string) ($b['when'] ?? ''), (string) ($a['when'] ?? ''));
});
if (count($entries) > 1000) {
    $entries = array_slice($entries, 0, 1000);
}

ini_set('display_errors', $prevDisplay);

json_response([
    'entries' => $entries,
    'count'   => count($entries),
    'days'    => $days,
]);

/**
 * Build a meaningful info string based on event code and row data.
 * Replaces raw "mozilla" user agent and numeric IDs with useful text.
 */
function buildInfoText($code, $row) {
    $ticket_id = (int) ($row['ticket_id'] ?? 0);
    $scope     = $row['ticket_scope'] ?? '';
    $resp      = $row['responder_handle'] ?? $row['responder_name'] ?? '';
    $raw_info  = $row['info'] ?? '';

    // GH #86 — reference incidents by their configured case/incident number
    // (ticket.incident_number) rather than the raw ticket DB id. Resolved inline
    // from the joined column to avoid an N+1 per-row lookup; falls back to
    // '#<db id>' when an install hasn't configured incident numbering.
    $incNum = (isset($row['ticket_incident_number']) && trim((string) $row['ticket_incident_number']) !== '')
        ? trim((string) $row['ticket_incident_number'])
        : '#' . $ticket_id;

    // Sign in/out — show nothing (user agent is useless)
    if ($code === 1 || $code === 2) {
        return '';
    }

    // Incident events — show ticket scope
    if ($code >= 10 && $code <= 17) {
        if ($scope) {
            return $incNum . ' ' . $scope;
        }
        if ($ticket_id > 0) {
            return $incNum;
        }
        return $raw_info;
    }

    // Unit status change — info has the status ID, not very useful
    if ($code === 20 || $code === 21 || $code === 22) {
        $parts = [];
        if ($resp) $parts[] = $resp;
        if ($scope && $ticket_id) $parts[] = $incNum;
        return implode(' · ', $parts) ?: $raw_info;
    }

    // Dispatch lifecycle (30-34) — show responder + ticket
    if ($code >= 30 && $code <= 34) {
        $parts = [];
        if ($resp) $parts[] = $resp;
        if ($scope) {
            $parts[] = $incNum . ' ' . $scope;
        } elseif ($ticket_id > 0) {
            $parts[] = $incNum;
        }
        return implode(' → ', $parts) ?: $raw_info;
    }

    // Facility events (35-51, 4040) — show ticket or raw info
    if (($code >= 35 && $code <= 51) || $code === 4040) {
        if ($scope && $ticket_id) {
            return $incNum . ' ' . $scope;
        }
        return $raw_info;
    }

    // Auto-status (122)
    if ($code === 122) {
        return $raw_info;
    }

    // Default fallback — use raw info but skip if it's just "mozilla"
    if (stripos($raw_info, 'mozilla') === 0) {
        return '';
    }
    return $raw_info;
}
