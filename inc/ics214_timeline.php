<?php
/**
 * Phase 111 Slice C — ICS-214 personal-timeline builder.
 *
 * Extracted from api/ics214-par-export.php so the aggregation logic is unit-
 * testable without the HTTP/auth layer. Builds a chronological activity-log
 * timeline for one responder from FOUR sources:
 *
 *   1. par_unit_acks    — PAR check-ins
 *   2. assigns          — dispatch/respond/on-scene/clear stamps
 *   3. action (log)     — entries authored by this responder's USER account
 *   4. action (log)     — entries attributed to this responder's MEMBER via
 *                         reverse identity resolution (author_member_id) — a
 *                         volunteer's Meshtastic/Zello/DMR field reports, which
 *                         often have no user_id. This is the load-bearing
 *                         Phase 111 link: field reports flow into a signable 214.
 *
 * Source (4) de-dups against (3) by action.id and is guarded on the Slice-A
 * author_member_id column so a pre-Slice-A install degrades to sources 1–3.
 *
 * Pure-ish: reads the DB (via the global db_* helpers) but takes an already-
 * fetched $responder + resolved date range, and returns a sorted array of
 * ['t' => datetime, 'note' => string, 'source' => par|assigns|action|radio].
 */

/**
 * @param array  $responder   row w/ id, name, handle, callsign, member_id
 * @param int    $responderId
 * @param string $from        'Y-m-d H:i:s'
 * @param string $to          'Y-m-d H:i:s'
 * @param int    $ticketId    0 = all incidents
 * @return array<int,array{t:string,note:string,source:string}>
 */
function ics214_build_timeline(array $responder, int $responderId, string $from, string $to, int $ticketId): array
{
    $prefix   = $GLOBALS['db_prefix'] ?? '';
    $entries  = [];
    $memberId = (int) ($responder['member_id'] ?? 0);

    // 1) PAR acks ─────────────────────────────────────────────────────────
    try {
        $sql = "SELECT a.acked_at, a.state, a.member_count, a.comments,
                       a.par_cycle_id, c.ticket_id
                  FROM `{$prefix}par_unit_acks` a
                  LEFT JOIN `{$prefix}par_cycles` c ON a.par_cycle_id = c.id
                 WHERE a.responder_id = ? AND a.acked_at BETWEEN ? AND ?";
        $params = [$responderId, $from, $to];
        if ($ticketId) { $sql .= " AND c.ticket_id = ?"; $params[] = $ticketId; }
        $sql .= " ORDER BY a.acked_at ASC";
        foreach (db_fetch_all($sql, $params) as $row) {
            $note = 'PAR check-in';
            if ($row['state'] === 'missed')  $note = 'PAR MISSED — no response';
            if ($row['state'] === 'aborted') $note = 'PAR aborted';
            if (!empty($row['member_count'])) $note .= ' — ' . (int) $row['member_count'] . ' personnel accounted for';
            if (!empty($row['comments'])) $note .= ' (' . trim($row['comments']) . ')';
            if (!empty($row['ticket_id'])) $note .= ' [Incident #' . (int) $row['ticket_id'] . ']';
            $entries[] = ['t' => $row['acked_at'], 'note' => $note, 'source' => 'par'];
        }
    } catch (Exception $e) {}

    // 2) Assigns timestamps ───────────────────────────────────────────────
    try {
        $sql = "SELECT a.ticket_id, a.dispatched, a.responding, a.on_scene, a.clear, t.scope
                  FROM `{$prefix}assigns` a
                  LEFT JOIN `{$prefix}ticket` t ON a.ticket_id = t.id
                 WHERE a.responder_id = ?";
        $params = [$responderId];
        if ($ticketId) { $sql .= " AND a.ticket_id = ?"; $params[] = $ticketId; }
        foreach (db_fetch_all($sql, $params) as $row) {
            $inc = !empty($row['scope']) ? $row['scope'] : ('Incident #' . (int) $row['ticket_id']);
            foreach (['dispatched' => 'Dispatched to', 'responding' => 'Responding to',
                      'on_scene'   => 'On scene of', 'clear'      => 'Cleared from'] as $col => $verb) {
                $ts = $row[$col] ?? null;
                if (!$ts || substr($ts, 0, 4) === '0000') continue;
                if ($ts < $from || $ts > $to) continue;
                $entries[] = ['t' => $ts, 'note' => $verb . ' ' . $inc, 'source' => 'assigns'];
            }
        }
    } catch (Exception $e) {}

    // De-dup key across the two action sources.
    $seenActionIds = [];

    // The `action` table's note text lives in `description` and the authoring
    // user is the `user` column (NOT action_text/user_id — those don't exist;
    // the previous exporter referenced them and silently returned nothing via
    // its try/catch, a latent bug fixed here).
    // 3) Action log authored by this responder's user account ──────────────
    try {
        $userId = (int) db_fetch_value(
            "SELECT `id` FROM `{$prefix}user` WHERE `member_id` = ? LIMIT 1",
            [$memberId]
        );
        if ($userId > 0) {
            $sql = "SELECT id, date, ticket_id, description
                      FROM `{$prefix}action`
                     WHERE `user` = ? AND date BETWEEN ? AND ?";
            $params = [$userId, $from, $to];
            if ($ticketId) { $sql .= " AND ticket_id = ?"; $params[] = $ticketId; }
            $sql .= " ORDER BY date ASC LIMIT 500";
            foreach (db_fetch_all($sql, $params) as $row) {
                $note = trim((string) $row['description']);
                if ($note === '') continue;
                $seenActionIds[(int) $row['id']] = true;
                if (!empty($row['ticket_id'])) $note .= ' [Incident #' . (int) $row['ticket_id'] . ']';
                $entries[] = ['t' => $row['date'], 'note' => $note, 'source' => 'action'];
            }
        }
    } catch (Exception $e) {}

    // 4) Radio-reported notes attributed to this member (Phase 111 Link 1/3) ─
    if ($memberId > 0) {
        try {
            $hasAuthorCol = (bool) db_fetch_one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'author_member_id'",
                [$prefix . 'action']
            );
            if ($hasAuthorCol) {
                $sql = "SELECT id, date, ticket_id, description, source_channel
                          FROM `{$prefix}action`
                         WHERE author_member_id = ? AND date BETWEEN ? AND ?";
                $params = [$memberId, $from, $to];
                if ($ticketId) { $sql .= " AND ticket_id = ?"; $params[] = $ticketId; }
                $sql .= " ORDER BY date ASC LIMIT 500";
                foreach (db_fetch_all($sql, $params) as $row) {
                    if (isset($seenActionIds[(int) $row['id']])) continue; // de-dup vs (3)
                    $note = trim((string) $row['description']);
                    if ($note === '') continue;
                    $chan = trim((string) ($row['source_channel'] ?? ''));
                    if ($chan !== '' && $chan !== 'manual' && $chan !== 'local_chat') {
                        $note .= ' — reported via ' . strtoupper($chan);
                    }
                    if (!empty($row['ticket_id'])) $note .= ' [Incident #' . (int) $row['ticket_id'] . ']';
                    $entries[] = ['t' => $row['date'], 'note' => $note, 'source' => 'radio'];
                }
            }
        } catch (Exception $e) {}
    }

    // Chronological order across all sources.
    usort($entries, function ($a, $b) { return strcmp($a['t'], $b['t']); });
    return $entries;
}
