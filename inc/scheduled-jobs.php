<?php
/**
 * NewUI v4.0 — scheduled-job bookkeeping + the stale-work cutoff.
 * Phase 127 (2026-07-29).
 *
 * WHY THIS EXISTS
 * ---------------
 * tools/par_tick.php and tools/pending_messages_tick.php were installed as
 * /etc/cron.d drop-ins on 11 June 2026. Neither of the two servers running
 * TicketsCAD has a cron daemon installed at all, so neither job ever executed
 * — not once, for seven weeks — and nothing anywhere noticed. A file in
 * /etc/cron.d on a host with no cron fails completely silently.
 *
 * Two separate things had to be true for that to go unseen for so long:
 *
 *   1. Nothing recorded that a background job had run, so there was nothing
 *      to notice was missing. Absence of evidence looked exactly like
 *      evidence of absence of problems.
 *   2. Both sweeps act on "everything that is overdue", with no upper bound
 *      on how overdue. That is correct for a job that runs every minute and
 *      catastrophic for one that has not run since June: the first
 *      successful tick would flush seven weeks of history at a live
 *      emergency-response team in one burst.
 *
 * This file addresses both. sched_job_record() gives every tick a heartbeat
 * that health_check_scheduled_jobs() can miss; sched_stale_cutoff_min()
 * gives every sweep an age past which it declines to act retroactively and
 * says so in a row an operator can read.
 *
 * THE CUTOFF CONTRACT
 * -------------------
 * Work older than the cutoff is never silently dropped and never deleted.
 * It is moved to an 'expired' state that records WHY, so that both
 * questions a volunteer can ask have an answer in the database:
 *
 *   "why did I get this?"     → status='sent',    sent_at
 *   "why did I NOT get this?" → status='expired', send_error / notes naming
 *                               the scheduled time, the age, and the cutoff
 *                               setting that governed the decision.
 *
 * Expiry is reversible: an operator who decides a message really should go
 * out can reset the row to 'pending' with a fresh scheduled_send_at.
 */

require_once __DIR__ . '/audit.php';

/** Cutoff default, in minutes. Work more than this far past due is expired
 *  rather than acted on. 0 disables the cutoff (pre-Phase-127 behaviour). */
if (!defined('SCHED_STALE_CUTOFF_DEFAULT_MIN')) {
    define('SCHED_STALE_CUTOFF_DEFAULT_MIN', 60);
}

/**
 * How far past due may a sweep still act? Minutes; 0 = no cutoff.
 *
 * Read through get_variable(), which is the `settings` table — the store the
 * Settings UI actually writes. get_setting() reads a DIFFERENT table
 * (`config`) and would return the default forever. See CLAUDE.md, "TWO
 * settings stores".
 */
function sched_stale_cutoff_min(): int {
    $v = false;
    if (function_exists('get_variable')) {
        try { $v = get_variable('sched_stale_cutoff_min'); } catch (Exception $e) { $v = false; }
    }
    if ($v === false || $v === null || $v === '') return SCHED_STALE_CUTOFF_DEFAULT_MIN;
    $n = (int) $v;
    return $n < 0 ? SCHED_STALE_CUTOFF_DEFAULT_MIN : $n;
}

/**
 * Is a unit of work too old to act on now?
 *
 * @param int      $dueTs     when the work became due (unix ts)
 * @param int|null $now       evaluation time (unix ts), defaults to time()
 * @param int|null $cutoffMin override the configured cutoff (for tests)
 */
function sched_is_stale(int $dueTs, ?int $now = null, ?int $cutoffMin = null): bool {
    if ($now === null) $now = time();
    if ($cutoffMin === null) $cutoffMin = sched_stale_cutoff_min();
    if ($cutoffMin <= 0) return false;              // cutoff disabled
    return ($now - $dueTs) > ($cutoffMin * 60);
}

/**
 * Human-readable reason string stored on an expired row. Deliberately
 * self-contained: it names the scheduled time, the age and the governing
 * setting, so the row explains itself without this code being present.
 */
function sched_expiry_reason(string $scheduledAt, int $dueTs, ?int $now = null, ?int $cutoffMin = null): string {
    if ($now === null) $now = time();
    if ($cutoffMin === null) $cutoffMin = sched_stale_cutoff_min();
    $ageMin = max(0, (int) round(($now - $dueTs) / 60));
    return sprintf(
        'expired: due %s, %d min overdue, exceeds sched_stale_cutoff_min=%d',
        $scheduledAt, $ageMin, $cutoffMin
    );
}

// ─────────────────────────────────────────────────────────────────────────
// Job registry + heartbeat
// ─────────────────────────────────────────────────────────────────────────

/**
 * Is this install running on Windows?
 *
 * GH openises/TicketsCAD#18 — everything this file said about running the
 * background jobs was systemd, and a Windows/IIS install has no systemd. The
 * Status page correctly reported "PAR scheduler — never run, CRITICAL" and
 * then told the admin to run `systemctl`, which is a dead end from the one
 * screen that had correctly identified the problem.
 */
function sched_is_windows(): bool {
    return defined('PHP_OS_FAMILY')
        ? PHP_OS_FAMILY === 'Windows'
        : stripos(PHP_OS, 'WIN') === 0;
}

/**
 * The background jobs this install expects something to be running.
 *
 * 'interval_s'  how often it should run.
 * 'grace_mult'  how many intervals may pass before we call it overdue.
 *               Generous, because a 60s job that is 3 minutes late is not
 *               news; one that is a day late is.
 * 'unit'        what an admin needs to type to check on it — a systemd unit
 *               on Linux, a Task Scheduler task name on Windows. Naming a
 *               systemd timer to a Windows admin is worse than saying
 *               nothing, so this is platform-derived rather than fixed.
 * 'unit_kind'   'systemd' | 'schtasks' — what 'unit' IS, so a consumer can
 *               render the right verb around it.
 * 'command'     the underlying command, path-separated for this platform.
 *
 * On Windows both ticks are driven by a single Task Scheduler entry running
 * tools\run-scheduled-jobs.bat every minute: Windows' minimum repeat interval
 * is one minute, which matches both jobs' interval_s, and one task is less to
 * get wrong than two. So both rows legitimately name the same task.
 */
function sched_job_registry(): array {
    $win = sched_is_windows();

    return [
        'par_tick' => [
            'label'      => 'PAR scheduler',
            'interval_s' => 60,
            'grace_mult' => 15,
            'unit'       => $win ? 'TicketsCAD Background Jobs' : 'ticketscad-par-tick.timer',
            'unit_kind'  => $win ? 'schtasks' : 'systemd',
            'command'    => $win ? 'php tools\\par_tick.php' : 'php tools/par_tick.php',
            'purpose'    => 'Initiates due PAR cycles and marks unanswered units missed',
        ],
        'pending_messages_tick' => [
            'label'      => 'Notification + pending message sweep',
            'interval_s' => 60,
            'grace_mult' => 15,
            'unit'       => $win ? 'TicketsCAD Background Jobs' : 'ticketscad-pending-msg.timer',
            'unit_kind'  => $win ? 'schtasks' : 'systemd',
            'command'    => $win ? 'php tools\\pending_messages_tick.php' : 'php tools/pending_messages_tick.php',
            // Since 2026-07-31 this job carries the outbound notifications
            // too: push, webhooks, SMS, e-mail and Slack were moved off the
            // dispatch request path, where they cost 21 seconds per action
            // during an outage. If this job is not running, notifications
            // queue instead of going out — which is why the check below
            // turns critical the moment anything is waiting.
            'purpose'    => 'Sends queued notifications (push, webhooks, SMS, e-mail, Slack) '
                          . 'and messages held for a security label\'s kill window',
        ],
        // Phase 133 (2026-08-03). Daily, not 60s — the grace multiplier is
        // deliberately much smaller than the two ticks above (3 intervals,
        // not 15): a job that runs once a day and is 45 hours late deserves
        // attention sooner than "15 missed daily runs" would imply.
        'audit_log_purge' => [
            'label'      => 'Audit log retention purge',
            'interval_s' => 86400,
            'grace_mult' => 3,
            'unit'       => $win ? 'TicketsCAD Background Jobs' : 'ticketscad-audit-purge.timer',
            'unit_kind'  => $win ? 'schtasks' : 'systemd',
            'command'    => $win ? 'php tools\\audit_log_purge_tick.php' : 'php tools/audit_log_purge_tick.php',
            'purpose'    => 'Archives and removes audit-log rows older than the configured retention window',
        ],
        // GH#42 (2026-08-13). Daily, same grace as audit_log_purge above --
        // this is a lower-stakes table (delivery-status log, not the audit
        // trail), so it skips the archive step but keeps the same cadence.
        'message_log_purge' => [
            'label'      => 'Message log retention purge',
            'interval_s' => 86400,
            'grace_mult' => 3,
            'unit'       => $win ? 'TicketsCAD Background Jobs' : 'ticketscad-message-log-purge.timer',
            'unit_kind'  => $win ? 'schtasks' : 'systemd',
            'command'    => $win ? 'php tools\\message_log_purge_tick.php' : 'php tools/message_log_purge_tick.php',
            'purpose'    => 'Removes outbound message-log rows (SMS/e-mail/Slack deliveries) older than the configured retention window',
        ],
        // Phase 134 (2026-08, GH #23 Model 3). 60s, same grace as par_tick /
        // pending_messages_tick — polls whichever broker channels have both
        // declared themselves 'pollable' AND been opted in via Settings
        // (telegram_poll_inbound / slack_poll_inbound, both default off).
        'channel_receive_tick' => [
            'label'      => 'Inbound channel poll (Telegram/Slack)',
            'interval_s' => 60,
            'grace_mult' => 15,
            'unit'       => $win ? 'TicketsCAD Background Jobs' : 'ticketscad-channel-receive-tick.timer',
            'unit_kind'  => $win ? 'schtasks' : 'systemd',
            'command'    => $win ? 'php tools\\channel_receive_tick.php' : 'php tools/channel_receive_tick.php',
            'purpose'    => 'Polls opted-in channels (e.g. Telegram, Slack) for inbound messages and '
                          . 'routes them to the sender\'s assigned incident',
        ],
        // Phase 143 (2026-08-17) — standing cross-org relationships,
        // activation-window cleanup. 5 min, not daily -- matches
        // par_tick/pending_messages_tick's cadence, not the daily purge
        // jobs: an activation window can be measured in tens of minutes,
        // and its audit-closure record should not lag a whole day behind
        // the read-time expiry it merely notes. NON-AUTHORITATIVE by
        // construction -- see inc/org-relationships.php's
        // org_relationship_deactivate() and tools/org_relationship_cleanup_tick.php's
        // own docblocks: access is already gone via the read-time
        // predicate before this job ever runs.
        'org_relationship_activation_cleanup' => [
            'label'      => 'Standing-relationship activation cleanup',
            'interval_s' => 300,
            'grace_mult' => 3,
            'unit'       => $win ? 'TicketsCAD Background Jobs' : 'ticketscad-org-relationship-cleanup.timer',
            'unit_kind'  => $win ? 'schtasks' : 'systemd',
            'command'    => $win ? 'php tools\\org_relationship_cleanup_tick.php' : 'php tools/org_relationship_cleanup_tick.php',
            'purpose'    => 'Closes out (deactivated_at) activation windows that have already expired by the read-time predicate, for audit-trail hygiene only',
        ],
    ];
}

/** Does the scheduled_job_runs table exist? Cached per request. */
function sched_table_exists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $exists = (bool) db_fetch_one(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
            [$prefix . 'scheduled_job_runs']
        );
    } catch (Exception $e) { $exists = false; }
    return $exists;
}

/**
 * Record that a job ran. Called by the tick itself, so the heartbeat is
 * produced by the real runner and cannot be faked into existence by a test.
 *
 * Never throws: a bookkeeping failure must not break the job it is
 * describing. It is error_log'd rather than swallowed silently — the whole
 * point of this file is that silence is what hurt us.
 */
function sched_job_record(string $jobKey, string $status, string $detail = '', ?int $durationMs = null): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!sched_table_exists()) {
        error_log("sched_job_record({$jobKey}): scheduled_job_runs table missing — run php sql/run_migrations.php");
        return false;
    }
    $status = ($status === 'ok') ? 'ok' : 'error';
    $detail = substr($detail, 0, 255);
    try {
        db_query(
            "INSERT INTO `{$prefix}scheduled_job_runs`
                (job_key, last_run_at, last_ok_at, last_status, last_detail,
                 last_duration_ms, run_count, error_count)
             VALUES (?, NOW(), " . ($status === 'ok' ? 'NOW()' : 'NULL') . ", ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                last_run_at      = NOW(),
                " . ($status === 'ok' ? 'last_ok_at = NOW(),' : '') . "
                last_status      = VALUES(last_status),
                last_detail      = VALUES(last_detail),
                last_duration_ms = VALUES(last_duration_ms),
                run_count        = run_count + 1,
                error_count      = error_count + VALUES(error_count)",
            [$jobKey, $status, $detail, $durationMs, ($status === 'ok' ? 0 : 1)]
        );
        return true;
    } catch (Exception $e) {
        error_log("sched_job_record({$jobKey}) failed: " . $e->getMessage());
        return false;
    }
}

/** Raw run row for a job, or null if it has never run. */
function sched_job_last(string $jobKey): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!sched_table_exists()) return null;
    try {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}scheduled_job_runs` WHERE job_key = ? LIMIT 1",
            [$jobKey]
        );
        return $row ?: null;
    } catch (Exception $e) { return null; }
}

/**
 * Is a given job actually needed on this install?
 *
 * A missing scheduler is only worth alarming about when the feature it
 * drives is in use. An install with PAR switched off and an empty message
 * queue is not broken because a timer it does not need is absent.
 *
 * The corollary matters more: the moment PAR is enabled, or the moment the
 * first message is queued, the same check turns critical — which is exactly
 * when an operator needs to hear about it, and seven weeks earlier than
 * anybody heard last time.
 *
 * Returns ['required' => bool, 'why' => string].
 */
function sched_job_required(string $jobKey): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    if ($jobKey === 'par_tick') {
        $on = false;
        try {
            require_once __DIR__ . '/par.php';
            $on = par_enabled();
        } catch (Exception $e) {}
        return $on
            ? ['required' => true,  'why' => 'PAR checks are enabled']
            : ['required' => false, 'why' => 'PAR checks are disabled (Settings → PAR Checks)'];
    }

    if ($jobKey === 'pending_messages_tick') {
        // Required when something is actually waiting to be delivered.
        //
        // An earlier version also treated "some security label has a
        // routing_send_delay_secs > 0" as evidence the sweep was needed.
        // It is not: run_phase18a_security_labels.php SEEDS a
        // 'confidential' label with a 60s delay on every install, so that
        // probe reported every fresh install — including CI — as
        // critically broken before an administrator had done anything at
        // all. Shipped default configuration is not usage. A queued
        // message is, and it appears exactly when it starts to matter.
        // Loaded lazily: this file is required by inc/notify-fanout.php, so a
        // top-level require here would be a load-time cycle. By the time this
        // function runs, both files are fully defined.
        if (!function_exists('notify_queue_depth') && is_file(__DIR__ . '/notify-fanout.php')) {
            require_once __DIR__ . '/notify-fanout.php';
        }
        try {
            $n = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}pending_routed_messages` WHERE status = 'pending'");
            if ($n > 0) {
                // Say WHICH kind is backing up. "3 messages waiting" and
                // "3 notifications nobody is sending" call for different
                // actions, and a dispatcher's callouts not going out is the
                // one an operator has to hear about.
                $why = "{$n} item(s) waiting in the send queue";
                if (function_exists('notify_queue_depth')) {
                    $q = notify_queue_depth();
                    if ($q['pending'] > 0) {
                        $why = $q['pending'] . ' notification(s) queued and undelivered';
                        if ($q['oldest_age_s'] !== null && $q['oldest_age_s'] > 120) {
                            $why .= ', oldest ' . _sched_ago((int) $q['oldest_age_s']) . ' old';
                        }
                        if ($n > $q['pending']) {
                            $why .= '; ' . ($n - $q['pending']) . ' held message(s) as well';
                        }
                    }
                }
                if (function_exists('notify_breaker_status')) {
                    $b = notify_breaker_status();
                    if (!empty($b['open'])) {
                        $why .= '. Outbound delivery is paused for ' . (int) $b['retry_in']
                              . 's after ' . (int) $b['fails'] . ' consecutive failures'
                              . ($b['last_error'] !== '' ? ' (' . $b['last_error'] . ')' : '');
                    }
                }
                return ['required' => true, 'why' => $why];
            }
        } catch (Exception $e) {}
        return ['required' => false, 'why' => 'Nothing is waiting in the send queue'];
    }

    if ($jobKey === 'audit_log_purge') {
        // Same "shipped default is not usage" discipline as pending_messages_tick
        // above: the setting defaults to 0 (disabled) on every install, so a
        // fresh/CI install must never report this job critical just because
        // nothing is scheduling it. The moment an admin sets a nonzero
        // retention window, this turns required — and if nothing is running
        // the job, critical — which is exactly when it matters.
        $on = false;
        try {
            require_once __DIR__ . '/audit-retention.php';
            $on = audit_retention_days() > 0;
        } catch (Exception $e) {}
        return $on
            ? ['required' => true,  'why' => 'Audit log retention is enabled']
            : ['required' => false, 'why' => 'Audit log retention is disabled (Settings → Audit Log → Retention & Purge)'];
    }

    if ($jobKey === 'message_log_purge') {
        // Same "shipped default is not usage" discipline as audit_log_purge
        // above: message_log_retention_days defaults to 0 (disabled) on every
        // install, so a fresh/CI install must never report this job critical
        // just because nothing is scheduling it.
        $on = false;
        try {
            require_once __DIR__ . '/message-log-retention.php';
            $on = message_log_retention_days() > 0;
        } catch (Exception $e) {}
        return $on
            ? ['required' => true,  'why' => 'Message log retention is enabled']
            : ['required' => false, 'why' => 'Message log retention is disabled (Settings → Pending Messages → Message Log Retention)'];
    }

    if ($jobKey === 'channel_receive_tick') {
        // Same "shipped default is not usage" discipline as pending_messages_
        // tick and audit_log_purge above: telegram_poll_inbound and
        // slack_poll_inbound both default unset/'0' on every install
        // (Phase 134 Step 1's migration seeds neither as on), so a fresh or
        // CI install must never report this job critical just because
        // nothing has opted in to polling. The moment an operator flips
        // EITHER channel's "Poll for inbound messages" switch on, this turns
        // required — and if nothing is running the job, critical — exactly
        // when it starts to matter.
        //
        // Reads the broker registry's 'pollable' flag rather than a
        // hardcoded ['telegram','slack'] list, so a future third pollable
        // channel becomes required-aware automatically (same "capability
        // flag, not an allowlist" discipline as channel_receive_run() itself
        // — plan.md §1).
        $enabledLabels = [];
        try {
            require_once __DIR__ . '/channel-receive.php';
            foreach (channel_receive_pollable_channels() as $ch) {
                $on = false;
                try {
                    $on = function_exists('get_variable') ? get_variable($ch['code'] . '_poll_inbound') : false;
                } catch (Exception $e) {}
                if ($on === '1') $enabledLabels[] = $ch['label'];
            }
        } catch (Exception $e) {}

        if (!empty($enabledLabels)) {
            $verb = count($enabledLabels) === 1 ? 'is' : 'are';
            return [
                'required' => true,
                'why'      => _sched_join_and($enabledLabels) . " inbound polling {$verb} enabled",
            ];
        }
        return [
            'required' => false,
            'why'      => 'No channel has inbound polling enabled (Settings → Telegram / Slack)',
        ];
    }

    if ($jobKey === 'org_relationship_activation_cleanup') {
        // Same "shipped default configuration is not evidence of use"
        // discipline as pending_messages_tick/channel_receive_tick above --
        // required only when a live-but-already-expired activation row
        // actually EXISTS (the negation of the same predicate
        // org_relationship_activation_live_join_sql() encodes), so a fresh
        // install or one that has never activated a relationship reports
        // this job as not-required, not critical.
        try {
            $n = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}org_relationships_activations`
                  WHERE `deactivated_at` IS NULL
                    AND `max_activation_minutes` IS NOT NULL
                    AND `activated_at` <= DATE_SUB(NOW(), INTERVAL `max_activation_minutes` MINUTE)");
            if ($n > 0) {
                return ['required' => true, 'why' => "{$n} activation window(s) have expired and are awaiting audit-trail closure"];
            }
        } catch (Exception $e) {}
        return ['required' => false, 'why' => 'No standing-relationship activation windows have expired'];
    }

    return ['required' => false, 'why' => 'Unknown job'];
}

/**
 * Evaluate every registered job. Shape mirrors the other health_check_*
 * sections: per-entry severity plus a section severity.
 */
function sched_jobs_status(?int $now = null): array {
    if ($now === null) $now = time();
    $out      = [];
    $worst    = 'ok';
    $haveTable = sched_table_exists();

    foreach (sched_job_registry() as $key => $def) {
        $req      = sched_job_required($key);
        $row      = sched_job_last($key);
        $overdueS = (int) ($def['interval_s'] * $def['grace_mult']);

        $lastRun = ($row && !empty($row['last_run_at'])) ? $row['last_run_at'] : null;
        $lastOk  = ($row && !empty($row['last_ok_at']))  ? $row['last_ok_at']  : null;
        $lastOkTs = $lastOk ? strtotime((string) $lastOk) : null;
        $ageS    = $lastOkTs ? max(0, $now - $lastOkTs) : null;

        if ($lastOkTs === null) {
            $state = 'never';
            $note  = $haveTable
                ? 'Has never run. Nothing is scheduling it.'
                : 'No run history table — run php sql/run_migrations.php';
        } elseif ($ageS > $overdueS) {
            $state = 'overdue';
            $note  = 'Last successful run ' . _sched_ago($ageS) . ' ago; expected every '
                   . _sched_dur($def['interval_s']) . '.';
        } else {
            $state = 'ok';
            $note  = 'Last successful run ' . _sched_ago($ageS) . ' ago.';
        }

        // Severity: only escalate for jobs this install actually needs.
        if ($state === 'ok') {
            $sev = 'ok';
        } elseif (!$req['required']) {
            $sev  = 'ok';
            $note .= ' Not currently required — ' . $req['why'] . '.';
        } else {
            $sev  = ($state === 'never') ? 'critical' : 'warn';
            $note .= ' Required: ' . $req['why'] . '.';
        }

        if ($sev === 'critical') $worst = 'critical';
        elseif ($sev === 'warn' && $worst !== 'critical') $worst = 'warn';

        $out[] = [
            'job'          => $key,
            'label'        => $def['label'],
            'purpose'      => $def['purpose'],
            'unit'         => $def['unit'],
            'unit_kind'    => $def['unit_kind'] ?? 'systemd',
            'command'      => $def['command'],
            'interval_s'   => (int) $def['interval_s'],
            'overdue_after_s' => $overdueS,
            'required'     => (bool) $req['required'],
            'required_why' => $req['why'],
            'state'        => $state,          // never | overdue | ok
            'note'         => $note,
            'last_run_at'  => $lastRun,
            'last_ok_at'   => $lastOk,
            'age_s'        => $ageS,
            'last_status'  => $row['last_status'] ?? null,
            'last_detail'  => $row['last_detail'] ?? null,
            'run_count'    => isset($row['run_count']) ? (int) $row['run_count'] : 0,
            'error_count'  => isset($row['error_count']) ? (int) $row['error_count'] : 0,
            'severity'     => $sev,
        ];
    }

    // The outbound-notification queue, reported separately from the job that
    // drains it. A dispatcher's callouts silently piling up is the thing an
    // operator most needs to see, and it is not the same fact as "a timer is
    // late" — the queue can be backing up with the timer running perfectly,
    // because the internet is down.
    $notify = null;
    if (function_exists('notify_queue_depth')) {
        $notify = notify_queue_depth($now);
        if (function_exists('notify_breaker_status')) {
            $notify['breaker'] = notify_breaker_status($now);
        }
    }

    return [
        'checked'  => true,
        'has_table' => $haveTable,
        'cutoff_min' => sched_stale_cutoff_min(),
        'jobs'     => $out,
        'notifications' => $notify,
        'severity' => $worst,
        'platform' => sched_is_windows() ? 'windows' : 'unix',
        'remedy'   => sched_is_windows()
            ? 'A job that has never run usually means nothing is scheduled to run it. '
            . 'Windows has no systemd — these jobs need a Task Scheduler entry. Check with: '
            . 'schtasks /Query /TN "TicketsCAD Background Jobs" — "cannot find the file specified" '
            . 'means nothing is scheduled. See docs/INSTALL-WINDOWS-IIS.md, '
            . '"The background jobs need Task Scheduler", for the one command that creates it.'
            : 'A job that has never run usually means no scheduler is installed. '
            . 'Check with: systemctl is-active cron — "not-found" means nothing is '
            . 'scheduled. See docs/MAINTENANCE-RUNBOOK.md for the systemd timer units.',
    ];
}

function _sched_ago(?int $s): string {
    if ($s === null) return 'never';
    if ($s < 90)    return $s . 's';
    if ($s < 5400)  return round($s / 60) . ' min';
    if ($s < 172800) return round($s / 3600) . ' hours';
    return round($s / 86400) . ' days';
}

function _sched_dur(int $s): string {
    if ($s < 90) return $s . 's';
    if ($s < 5400) return round($s / 60) . ' min';
    return round($s / 3600) . ' hours';
}

/**
 * "X" / "X and Y" / "X, Y, and Z" — used by channel_receive_tick's
 * required-check to name which pollable channel(s) are opted in, without
 * hardcoding to today's two (Telegram, Slack).
 */
function _sched_join_and(array $items): string {
    $items = array_values($items);
    $n = count($items);
    if ($n === 0) return '';
    if ($n === 1) return (string) $items[0];
    if ($n === 2) return $items[0] . ' and ' . $items[1];
    $last = array_pop($items);
    return implode(', ', $items) . ', and ' . $last;
}
