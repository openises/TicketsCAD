<?php
/**
 * Phase 104d (a beta tester GH #11) — configurable auto-close on all-clear.
 *
 * When the last active unit clears from an incident, and the admin
 * has opted into auto-close, the incident schedules itself to close
 * after a configurable grace period. If a unit gets re-dispatched to
 * the incident within the grace window, the pending close cancels.
 * A sweeper called on every /api/incidents.php read walks tickets
 * whose scheduled time has passed and closes them via the standard
 * `incident_update_status_internal()` — same audit + SSE + webhook
 * fan-out as a dispatcher-driven close.
 *
 * Settings:
 *   `auto_close_on_all_clear`      — '1' (default) or '0'.
 *   `auto_close_grace_seconds`     — INT seconds; default 90.
 *                                    Valid range 1..3596400 (999h).
 *                                    UI writes it as (seconds |
 *                                    minutes | hours). This helper
 *                                    always reads the raw seconds.
 *
 * Schema (self-healed on first use):
 *   ticket.auto_close_scheduled_at DATETIME NULL — set by
 *   maybe_schedule; NULL'd by cancel; consumed + NULL'd by sweep.
 *
 * All functions fail soft — an auto-close error must never block a
 * legitimate status change or incident-list read.
 */

/**
 * GH #65 (Ron Jones, 2026-08-15) — every audit_log() call in this file was
 * guarded with a bare `if (function_exists('audit_log'))`, no lazy require
 * attempt first (unlike inc/incident-write.php's own audit calls, which do
 * try `require_once audit.php` before checking). api/incidents.php reaches
 * audit.php transitively (inc/security-labels.php and inc/par.php both
 * require it), so calls from there always worked. api/stream.php -- the
 * SSE endpoint, running its own sweep on every tick, so the MOST frequent
 * caller -- loads only api_guard.php, config.php, rbac.php,
 * session-bootstrap.php, sse.php and auto_close.php, none of which reach
 * audit.php. From there the guard was always false: every auto-close
 * scheduled/cancelled/fired via a request that happened to arrive on the
 * stream connection left NO audit_log() record, silently, forever --
 * indistinguishable from "nothing happened" in the very log meant to prove
 * it did.
 */
function auto_close_ensure_audit(): bool {
    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    return function_exists('audit_log');
}

function auto_close_ensure_column(): void {
    static $ensured = false;
    if ($ensured) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $col = db_fetch_all("SHOW COLUMNS FROM `{$prefix}ticket` LIKE 'auto_close_scheduled_at'");
        if (empty($col)) {
            db_query("ALTER TABLE `{$prefix}ticket`
                      ADD COLUMN `auto_close_scheduled_at` DATETIME NULL
                          COMMENT 'Phase 104d — timestamp when auto-close fires; NULL = not scheduled',
                      ADD KEY `idx_auto_close_sched` (`auto_close_scheduled_at`)");
        }
        $ensured = true;
    } catch (Exception $e) {
        error_log('[auto_close] ensure_column: ' . $e->getMessage());
    }
}

function auto_close_enabled(): bool {
    $v = get_variable('auto_close_on_all_clear');
    if ($v === false || $v === null || $v === '') return true; // default ON per Eric 2026-07-02
    return $v === '1' || $v === 1 || $v === true;
}

function auto_close_grace_seconds(): int {
    $v = get_variable('auto_close_grace_seconds');
    if ($v === false || $v === null || $v === '') return 90;
    $n = (int) $v;
    if ($n < 1) $n = 1;
    if ($n > 3596400) $n = 3596400; // 999 hours cap
    return $n;
}

/**
 * Count active (uncleared) assigns for a ticket. Uses the same
 * clear-is-null test as the rest of the codebase — `clear IS NULL`
 * OR clear starts with '0000' (MyISAM null-substitute).
 *
 * GH #24 — this used to carry a third term, `clear = ''`. `assigns.clear`
 * is DATETIME, and MySQL 8.0 rejects the comparison outright:
 *
 *     SQLSTATE[HY000]: General error: 1525 Incorrect DATETIME value: ''
 *
 * One bad term throws the whole query, and the catch below used to
 * `return 0` — which on this code path is not a neutral failure value,
 * it is the one answer that means "no crews left on this incident", i.e.
 * exactly the condition that authorises an auto-close. So on MySQL 8.0
 * every call read as a definitive all-clear, with no log line anywhere:
 * incidents closed with crews still assigned, and the audit trail
 * recorded "all units clear" as fact. The `''` term was a MyISAM-era
 * null-substitute that the zero-date term already covers.
 *
 * Now returns -1 when the count could NOT be obtained. A count that
 * could not be taken is not a count of zero. Callers must treat a
 * negative result as "unknown — do not close".
 *
 * @return int >=0 active assigns, or -1 if the count could not be taken.
 */
function _auto_close_active_assigns(int $ticketId): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}assigns`
              WHERE ticket_id = ?
                AND (clear IS NULL OR clear = '0000-00-00 00:00:00')",
            [$ticketId]
        );
    } catch (Exception $e) {
        error_log('[auto_close] active-assign count FAILED for ticket ' . $ticketId
            . ' — failing closed, NOT treating as all-clear: ' . $e->getMessage());
        return -1;
    }
}

/**
 * Called from assignment-write.php after stamping an assigns.clear.
 * If (a) auto-close is enabled AND (b) no more active assigns remain
 * AND (c) the ticket is still open, stamp
 * ticket.auto_close_scheduled_at to NOW() + grace.
 *
 * Safe to call unconditionally: skips silently if the ticket is
 * already scheduled (idempotent — the earlier schedule stays put).
 */
function auto_close_maybe_schedule(int $ticketId, int $userId): array {
    if ($ticketId <= 0) return ['scheduled' => false, 'reason' => 'invalid_ticket_id'];
    if (!auto_close_enabled()) return ['scheduled' => false, 'reason' => 'disabled'];
    auto_close_ensure_column();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        // Soft-delete sweep (issue #25 follow-up) — a soft-deleted
        // incident must not have an auto-close scheduled against it.
        $ticket = db_fetch_one(
            "SELECT id, status, auto_close_scheduled_at
             FROM `{$prefix}ticket`
              WHERE id = ?
                AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
              LIMIT 1",
            [$ticketId]
        );
        if (!$ticket) return ['scheduled' => false, 'reason' => 'ticket_not_found'];
        if ((int) $ticket['status'] === 1) return ['scheduled' => false, 'reason' => 'already_closed'];
        if (!empty($ticket['auto_close_scheduled_at'])
            && substr((string) $ticket['auto_close_scheduled_at'], 0, 4) !== '0000') {
            return ['scheduled' => false, 'reason' => 'already_scheduled'];
        }
        // GH #24 — only an explicit, successfully-taken zero authorises a
        // schedule. -1 means the count could not be obtained; that is not
        // an all-clear.
        $active = _auto_close_active_assigns($ticketId);
        if ($active < 0) {
            return ['scheduled' => false, 'reason' => 'active_assigns_unknown'];
        }
        if ($active > 0) {
            return ['scheduled' => false, 'reason' => 'active_assigns_remain'];
        }
        $grace = auto_close_grace_seconds();
        $fireAt = date('Y-m-d H:i:s', time() + $grace);
        db_query(
            "UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = ?, updated = NOW()
             WHERE id = ?",
            [$fireAt, $ticketId]
        );
        if (auto_close_ensure_audit()) {
            audit_log('incident', 'update', 'ticket', $ticketId,
                "Auto-close scheduled in {$grace}s (all units clear)",
                ['fire_at' => $fireAt, 'grace_seconds' => $grace, 'user_id' => $userId]);
        }
        return ['scheduled' => true, 'fire_at' => $fireAt, 'grace_seconds' => $grace];
    } catch (Exception $e) {
        error_log('[auto_close] schedule: ' . $e->getMessage());
        return ['scheduled' => false, 'reason' => 'exception:' . $e->getMessage()];
    }
}

/**
 * Called from assignment-write.php after inserting a new assigns row
 * (re-dispatch). Cancels any pending auto-close on the ticket.
 */
function auto_close_maybe_cancel(int $ticketId, int $userId): array {
    if ($ticketId <= 0) return ['cancelled' => false, 'reason' => 'invalid_ticket_id'];
    auto_close_ensure_column();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $sched = db_fetch_value(
            "SELECT auto_close_scheduled_at FROM `{$prefix}ticket` WHERE id = ? LIMIT 1",
            [$ticketId]
        );
        if (!$sched || substr((string) $sched, 0, 4) === '0000') {
            return ['cancelled' => false, 'reason' => 'not_scheduled'];
        }
        db_query(
            "UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = NULL
             WHERE id = ?",
            [$ticketId]
        );
        if (auto_close_ensure_audit()) {
            audit_log('incident', 'update', 'ticket', $ticketId,
                'Auto-close cancelled (unit re-dispatched during grace window)',
                ['was_fire_at' => $sched, 'user_id' => $userId]);
        }
        return ['cancelled' => true, 'was_fire_at' => $sched];
    } catch (Exception $e) {
        error_log('[auto_close] cancel: ' . $e->getMessage());
        return ['cancelled' => false, 'reason' => 'exception:' . $e->getMessage()];
    }
}

/**
 * GH #65 (Ron Jones, 2026-08-15) — called whenever an incident transitions
 * to Closed via ANY path (manual close, sweep-driven auto-close, or any
 * future caller of incident_update_status_internal()). Clears a stale
 * scheduled-close marker so a LATER reopen can never race a leftover
 * timer from a previous, already-honoured close cycle.
 *
 * Root cause this closes off: a manual close only ever wrote
 * `status = 1` -- it never touched auto_close_scheduled_at, which the
 * all-clear path had already armed for +grace seconds. The marker
 * survived, inert, on a Closed ticket (the sweep's WHERE clause requires
 * status <> 1, so nothing acts on it while closed). Reopening set
 * status back to 2 with the marker still in the past, and the very next
 * sweep -- api/stream.php runs one on every SSE tick -- silently
 * re-closed it within seconds.
 *
 * Fail-soft like every other function in this file: a bookkeeping
 * cleanup must never block the close it rides along with.
 */
function auto_close_clear_on_close(int $ticketId): void {
    if ($ticketId <= 0) return;
    auto_close_ensure_column();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = NULL
             WHERE id = ? AND auto_close_scheduled_at IS NOT NULL",
            [$ticketId]
        );
    } catch (Exception $e) {
        error_log('[auto_close] clear-on-close: ' . $e->getMessage());
    }
}

/**
 * Lazy sweep — called from api/incidents.php GET so any dispatcher
 * loading the list picks up overdue auto-closes without a cron.
 * Bounded (LIMIT 20 per call) so a huge backlog doesn't stall the
 * request.
 *
 * A ticket only closes if it STILL has no active assigns — a
 * re-dispatch that didn't cancel via the helper path is still
 * respected here as a safety net.
 */
function auto_close_sweep(int $limit = 20): array {
    if (!auto_close_enabled()) return ['closed' => 0, 'skipped' => 0];
    auto_close_ensure_column();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    $closed = 0;
    $skipped = 0;
    try {
        // Soft-delete sweep (issue #25 follow-up) — never auto-close a
        // soft-deleted incident (a status write it shouldn't receive).
        $due = db_fetch_all(
            "SELECT id FROM `{$prefix}ticket`
              WHERE auto_close_scheduled_at IS NOT NULL
                AND auto_close_scheduled_at <= ?
                AND status <> 1
                AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
              LIMIT ?",
            [$now, $limit]
        );
    } catch (Exception $e) {
        error_log('[auto_close] sweep query: ' . $e->getMessage());
        return ['closed' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
    }
    foreach ($due as $row) {
        $tid = (int) $row['id'];
        try {
            $active = _auto_close_active_assigns($tid);
            if ($active < 0) {
                // GH #24 — the count could not be taken. Leave the
                // schedule in place (so the next sweep retries) and do
                // NOT close. The old code shared this branch with the
                // "units remain" case via a `> 0` test, so a failed
                // count fell through to the close instead — the safety
                // net was defeated by the same broken comparison as the
                // gate it was there to back up.
                $skipped++;
                continue;
            }
            if ($active > 0) {
                // Safety net: a re-dispatch that skipped
                // auto_close_maybe_cancel() should still stop the
                // close. NULL the scheduled time and continue.
                db_query(
                    "UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = NULL WHERE id = ?",
                    [$tid]
                );
                $skipped++;
                continue;
            }
            require_once __DIR__ . '/incident-write.php';
            // Phase 132 Step 2 (GH #16) — this sweep closes incidents with
            // no human present, so it must not start silently failing the
            // moment an admin turns disposition_required_on_close on.
            // Mirrors the Phase 129 PAR lesson: a disabled/gated feature
            // must not silently break unrelated background housekeeping.
            $res = incident_update_status_internal($tid, 1, 0, ['skip_disposition_check' => true]);
            if (!empty($res['errors'])) {
                error_log('[auto_close] sweep close #' . $tid . ': ' . implode(',', $res['errors']));
                $skipped++;
                continue;
            }
            // Clear the scheduled marker regardless of whether the
            // helper already did (it may not).
            db_query(
                "UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = NULL WHERE id = ?",
                [$tid]
            );
            if (auto_close_ensure_audit()) {
                audit_log('incident', 'close', 'ticket', $tid,
                    'Incident auto-closed after grace period expired (Phase 104d)',
                    ['grace_seconds' => auto_close_grace_seconds()]);
            }
            $closed++;
        } catch (Exception $e) {
            error_log('[auto_close] sweep close #' . $tid . ': ' . $e->getMessage());
            $skipped++;
        }
    }
    return ['closed' => $closed, 'skipped' => $skipped];
}
