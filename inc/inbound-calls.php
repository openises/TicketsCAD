<?php
/**
 * inbound-calls.php — Phase 149 business logic: ingest, claim, quick-
 * reassignment, heartbeat, staleness, wrap-up, and ticket-linking.
 *
 * Pure, directly-testable functions against the `inbound_calls` /
 * `inbound_call_events` / `pbx_trunks` tables, mirroring
 * inc/assignment-write.php's shape (plan.md §12's file inventory). Every
 * function here is called by BOTH the real API endpoints
 * (api/sip-ingest.php, api/inbound-calls.php) and this feature's own test
 * suite — driving the real writer, never a hand-seeded stand-in for what
 * the writer would produce (this project's standing testing convention).
 *
 * See specs/phase-149-inbound-sip-calls/plan.md for the full design
 * rationale; only load-bearing facts are repeated inline below.
 */

require_once __DIR__ . '/db.php';

if (!function_exists('inbound_calls_normalize_ts')) {

    /**
     * Accept either a Unix epoch (with or without fractional seconds) or a
     * pre-formatted MySQL DATETIME string. Empty/missing -> now. Mirrors
     * api/dmr-ingest.php's dmr_ingest_normalize_ts() -- same class of PBX/
     * bridge timestamp variance.
     */
    function inbound_calls_normalize_ts($value): string
    {
        if ($value === null || $value === '') {
            return date('Y-m-d H:i:s');
        }
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) round((float) $value));
        }
        $s = (string) $value;
        // Accept a trailing 'Z'/ISO-8601 'T' separator from the payload
        // contract's own example ("2026-08-22T14:03:11Z") without requiring
        // the adapter to pre-format it.
        $ts = strtotime($s);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
        return date('Y-m-d H:i:s');
    }

    /** Table-name helper, matching this file family's `{$prefix}` convention. */
    function _p149_prefix(): string
    {
        return $GLOBALS['db_prefix'] ?? '';
    }

    /**
     * Append-only audit row. Never throws -- a logging failure must not
     * break the action it describes (this project's standing audit
     * discipline: wrap so a logging failure is logged-and-swallowed).
     */
    function inbound_call_audit(
        int $callId,
        string $eventType,
        ?int $actorUserId = null,
        ?string $actorName = null,
        ?string $reason = null,
        ?array $detail = null
    ): void {
        $prefix = _p149_prefix();
        try {
            db_query(
                "INSERT INTO `{$prefix}inbound_call_events`
                    (`call_id`, `event_type`, `actor_user_id`, `actor_name`, `reason`, `detail_json`)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $callId, $eventType, $actorUserId, $actorName, $reason,
                    $detail !== null ? json_encode($detail) : null,
                ]
            );
        } catch (Throwable $e) {
            error_log('[inbound-calls audit] ' . $eventType . ' call=' . $callId . ' failed: ' . $e->getMessage());
        }
    }

    /** Best-effort SSE publish -- a no-op until inc/sse.php's
     *  sse_publish_for_call() lands (Milestone 3); guarded so ingest never
     *  fatals on an install mid-upgrade. */
    function _p149_sse(int $callId, string $eventType, array $payload, ?int $orgId): void
    {
        if (function_exists('sse_publish_for_call')) {
            try {
                sse_publish_for_call($callId, $eventType, $payload, $orgId);
            } catch (Throwable $e) {
                error_log('[inbound-calls sse] ' . $eventType . ' call=' . $callId . ' failed: ' . $e->getMessage());
            }
        }
    }

    function inbound_call_get(int $id): ?array
    {
        $prefix = _p149_prefix();
        try {
            return db_fetch_one("SELECT * FROM `{$prefix}inbound_calls` WHERE `id` = ?", [$id]) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    function inbound_call_find_by_provider(int $trunkId, string $providerCallId): ?array
    {
        $prefix = _p149_prefix();
        try {
            return db_fetch_one(
                "SELECT * FROM `{$prefix}inbound_calls` WHERE `trunk_id` = ? AND `provider_call_id` = ?",
                [$trunkId, $providerCallId]
            ) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** The SSE-broadcast payload shape (plan.md §6's "hard structural
     *  limit" -- caller_number/called_number/trunk_label/mute_bypass/
     *  timestamps only, NEVER a constituent match or history summary). */
    function inbound_call_broadcast_payload(array $call, array $trunk): array
    {
        return [
            'call_id'        => (int) $call['id'],
            'trunk_id'       => (int) $call['trunk_id'],
            'trunk_label'    => (string) ($trunk['label'] ?? ''),
            'caller_number'  => $call['caller_number'],
            'called_number'  => $call['called_number'],
            'state'          => $call['state'],
            // claimed_by (the numeric user id) is NOT the caller-identity
            // FR-26 restricts -- it is which DISPATCHER holds the claim,
            // already disclosed as a name via claimed_by_name below. The
            // client needs the id (not just the name) to answer "is this
            // MY OWN claim" (e.g. call-alert.js's Take-button visibility,
            // and the FR-10 self-quieting check) without a second request.
            // Found missing live (2026-08-22): its absence silently broke
            // the "don't show Take on your own claim" check for every
            // user, always comparing against `undefined`.
            'claimed_by'     => $call['claimed_by'] !== null ? (int) $call['claimed_by'] : null,
            'claimed_by_name'=> $call['claimed_by_name'],
            'stale'          => !empty($call['stale_since']),
            'mute_bypass'    => !empty($trunk['mute_bypass_enabled']),
            'ringing_at'     => $call['ringing_at'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // A. Ingest (Milestone 1) — plan.md §2
    // ─────────────────────────────────────────────────────────────────

    /**
     * Apply one normalized webhook event to the call it names, creating
     * the row on first sight. Idempotent per (trunk_id, provider_call_id)
     * -- the UNIQUE key is the mechanism; this function additionally
     * enforces the event_ts ordering guard (an older event_ts than the
     * row's last_event_at is accepted but does not mutate state).
     *
     * @return array{ok:bool, call_id:?int, applied:bool, reason:?string}
     */
    function inbound_calls_ingest_event(array $trunk, array $payload): array
    {
        $prefix = _p149_prefix();

        $event = (string) ($payload['event'] ?? '');
        $providerCallId = trim((string) ($payload['call_id'] ?? ''));
        if ($providerCallId === '') {
            return ['ok' => false, 'call_id' => null, 'applied' => false, 'reason' => 'call_id required'];
        }
        if (!in_array($event, ['ringing', 'claimed_externally', 'ended', 'abandoned'], true)) {
            return ['ok' => false, 'call_id' => null, 'applied' => false, 'reason' => 'unknown event'];
        }

        $eventTs = inbound_calls_normalize_ts($payload['event_ts'] ?? null);
        $callerNumber = isset($payload['caller_number']) ? substr((string) $payload['caller_number'], 0, 40) : null;
        $callerName   = isset($payload['caller_name'])   ? substr((string) $payload['caller_name'], 0, 120) : null;
        $calledNumber = isset($payload['called_number']) ? substr((string) $payload['called_number'], 0, 40) : null;

        $trunkId = (int) $trunk['id'];
        $orgId   = isset($trunk['org_id']) && $trunk['org_id'] !== null ? (int) $trunk['org_id'] : null;

        $existing = inbound_call_find_by_provider($trunkId, $providerCallId);

        // ── First sighting of this call ─────────────────────────────
        if ($existing === null) {
            // A 'ringing' event creates the row in 'ringing' state. Any
            // OTHER event arriving first (out-of-order delivery, or an
            // adapter that only sends terminal events for calls nobody
            // ever needed to see ring) still creates a row so the call
            // has a durable record -- but lands directly in a terminal
            // state rather than inventing a ring that never happened.
            $initialState = 'ringing';
            $endedAt = null;
            if ($event === 'ended' || $event === 'abandoned') {
                $initialState = 'abandoned';
                $endedAt = $eventTs;
            }
            try {
                db_query(
                    "INSERT INTO `{$prefix}inbound_calls`
                        (`trunk_id`, `org_id`, `provider_call_id`, `caller_number`, `caller_name`,
                         `called_number`, `state`, `ended_at`, `ringing_at`, `last_event_at`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$trunkId, $orgId, $providerCallId, $callerNumber, $callerName,
                     $calledNumber, $initialState, $endedAt, $eventTs, $eventTs]
                );
            } catch (Throwable $e) {
                // Concurrent duplicate INSERT racing the unique key -- the
                // other request created the row first; re-fetch and fall
                // through to the "existing row" branch below instead of
                // erroring the retry.
                $existing = inbound_call_find_by_provider($trunkId, $providerCallId);
                if ($existing === null) {
                    error_log('[inbound-calls ingest] insert failed: ' . $e->getMessage());
                    return ['ok' => false, 'call_id' => null, 'applied' => false, 'reason' => 'insert failed'];
                }
            }
            if ($existing === null) {
                $call = inbound_call_find_by_provider($trunkId, $providerCallId);
                if ($event === 'ringing' && $call) {
                    inbound_call_audit((int) $call['id'], 'rang', null, null, null, ['caller_number' => $callerNumber]);
                    _p149_sse((int) $call['id'], 'call:ringing', inbound_call_broadcast_payload($call, $trunk), $orgId);
                } elseif ($call) {
                    inbound_call_audit((int) $call['id'], 'abandoned', null, null, null, ['reason' => 'terminal event with no prior ringing seen']);
                    _p149_sse((int) $call['id'], 'call:abandoned', inbound_call_broadcast_payload($call, $trunk), $orgId);
                }
                return ['ok' => true, 'call_id' => $call ? (int) $call['id'] : null, 'applied' => true, 'reason' => null];
            }
            // fall through: a concurrent insert won the race, existing is now set
        }

        $callId = (int) $existing['id'];

        // ── Ordering guard ───────────────────────────────────────────
        // An event whose event_ts is strictly older than the row's already-
        // applied last_event_at is accepted (200, never a failure code --
        // telcos retry aggressively) but must not mutate state.
        if ($eventTs < $existing['last_event_at']) {
            return ['ok' => true, 'call_id' => $callId, 'applied' => false, 'reason' => 'out of order'];
        }

        $currentState = (string) $existing['state'];

        if ($event === 'ringing') {
            // A duplicate/retried ringing webhook for a call already past
            // 'ringing' is a pure no-op on state (idempotency, FR-3) --
            // still advances last_event_at so a later legitimate event
            // isn't itself misjudged as out-of-order.
            db_query(
                "UPDATE `{$prefix}inbound_calls` SET `last_event_at` = ? WHERE `id` = ?",
                [$eventTs, $callId]
            );
            // A duplicate/retried ringing webhook never re-fires the audit
            // row or SSE publish above (FR-3: never a second ring) -- so
            // `applied` is false here even though the row is (still)
            // legitimately in `ringing` state; `applied` means "this call
            // of the function caused a new mutation/notification", not
            // "the resulting state happens to match".
            return ['ok' => true, 'call_id' => $callId, 'applied' => false, 'reason' => null];
        }

        if ($event === 'claimed_externally') {
            // Deliberately narrow, informational-only (plan.md §2): the PBX
            // saw a physical extension answer with no TicketsCAD claim.
            // Recorded as an audit note; never mutates `state` in v1
            // (coordination-only -- see spec.md's out-of-scope section).
            db_query(
                "UPDATE `{$prefix}inbound_calls` SET `last_event_at` = ? WHERE `id` = ?",
                [$eventTs, $callId]
            );
            inbound_call_audit($callId, 'claimed_externally', null, null, null, $payload);
            return ['ok' => true, 'call_id' => $callId, 'applied' => true, 'reason' => null];
        }

        if ($event === 'ended') {
            if ($currentState === 'ringing') {
                db_query(
                    "UPDATE `{$prefix}inbound_calls`
                        SET `state` = 'abandoned', `ended_at` = ?, `last_event_at` = ?
                      WHERE `id` = ? AND `state` = 'ringing'",
                    [$eventTs, $eventTs, $callId]
                );
                $call = inbound_call_get($callId);
                inbound_call_audit($callId, 'abandoned');
                if ($call) _p149_sse($callId, 'call:abandoned', inbound_call_broadcast_payload($call, $trunk), $orgId);
                return ['ok' => true, 'call_id' => $callId, 'applied' => true, 'reason' => null];
            }
            if (in_array($currentState, ['claimed', 'wrapup'], true)) {
                // Moves to 'wrapup' (plan.md's Wrap-up section) -- the PBX's
                // 'ended' is authoritative and immediate for the CALL, but
                // the dispatcher may still be mid-form. Only fires the
                // wrapup_started audit + SSE on the actual transition (a
                // retried 'ended' while already in wrapup is a pure no-op).
                $stmt = db_query(
                    "UPDATE `{$prefix}inbound_calls`
                        SET `state` = 'wrapup', `ended_at` = ?, `last_event_at` = ?
                      WHERE `id` = ? AND `state` = 'claimed'",
                    [$eventTs, $eventTs, $callId]
                );
                if ($stmt->rowCount() > 0) {
                    $call = inbound_call_get($callId);
                    inbound_call_audit($callId, 'wrapup_started');
                    if ($call) _p149_sse($callId, 'call:wrapup', inbound_call_broadcast_payload($call, $trunk), $orgId);
                } else {
                    db_query("UPDATE `{$prefix}inbound_calls` SET `last_event_at` = ? WHERE `id` = ?", [$eventTs, $callId]);
                }
                return ['ok' => true, 'call_id' => $callId, 'applied' => true, 'reason' => null];
            }
            // Already ended/abandoned -- idempotent no-op.
            db_query("UPDATE `{$prefix}inbound_calls` SET `last_event_at` = ? WHERE `id` = ?", [$eventTs, $callId]);
            return ['ok' => true, 'call_id' => $callId, 'applied' => false, 'reason' => 'already terminal'];
        }

        if ($event === 'abandoned') {
            if ($currentState === 'ringing') {
                db_query(
                    "UPDATE `{$prefix}inbound_calls`
                        SET `state` = 'abandoned', `ended_at` = ?, `last_event_at` = ?
                      WHERE `id` = ? AND `state` = 'ringing'",
                    [$eventTs, $eventTs, $callId]
                );
                $call = inbound_call_get($callId);
                inbound_call_audit($callId, 'abandoned');
                if ($call) _p149_sse($callId, 'call:abandoned', inbound_call_broadcast_payload($call, $trunk), $orgId);
                return ['ok' => true, 'call_id' => $callId, 'applied' => true, 'reason' => null];
            }
            db_query("UPDATE `{$prefix}inbound_calls` SET `last_event_at` = ? WHERE `id` = ?", [$eventTs, $callId]);
            return ['ok' => true, 'call_id' => $callId, 'applied' => false, 'reason' => 'not ringing'];
        }

        return ['ok' => false, 'call_id' => $callId, 'applied' => false, 'reason' => 'unhandled event'];
    }

    // ─────────────────────────────────────────────────────────────────
    // B. Claim / release / quick-reassignment (Milestone 5) — plan.md §4/§4a
    // ─────────────────────────────────────────────────────────────────

    /**
     * The single atomic conditional UPDATE (plan.md §4). No prior SELECT --
     * the UPDATE's WHERE clause IS the check, executed atomically by the
     * database. Affected rows = 1 -> this caller won; 0 -> someone else
     * already has it (or it already ended).
     */
    function inbound_call_claim(int $callId, int $userId, string $userName): array
    {
        $prefix = _p149_prefix();
        $stmt = db_query(
            "UPDATE `{$prefix}inbound_calls`
                SET `state` = 'claimed', `claimed_by` = ?, `claimed_by_name` = ?,
                    `claimed_at` = NOW(), `claim_heartbeat_at` = NOW(),
                    `stale_since` = NULL, `reassigned_from` = NULL
              WHERE `id` = ? AND `state` = 'ringing'",
            [$userId, $userName, $callId]
        );

        if ($stmt->rowCount() === 1) {
            $call = inbound_call_get($callId);
            inbound_call_audit($callId, 'claimed', $userId, $userName);
            if ($call) {
                $trunk = _p149_trunk_for_call($call);
                _p149_sse($callId, 'call:claimed', inbound_call_broadcast_payload($call, $trunk ?? []), $call['org_id'] !== null ? (int) $call['org_id'] : null);
            }
            return ['ok' => true, 'call' => $call];
        }

        $call = inbound_call_get($callId);
        if ($call === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($call['state'] === 'ringing') {
            // Should not happen (we just failed to claim a ringing row) --
            // treat as a transient race, caller may retry.
            return ['ok' => false, 'reason' => 'race', 'state' => $call['state']];
        }
        if (in_array($call['state'], ['ended', 'abandoned'], true)) {
            return ['ok' => false, 'reason' => 'already_ended', 'state' => $call['state']];
        }
        return [
            'ok' => false,
            'reason' => 'already_claimed',
            'claimed_by_name' => $call['claimed_by_name'],
            'state' => $call['state'],
        ];
    }

    function _p149_trunk_for_call(array $call): ?array
    {
        $prefix = _p149_prefix();
        try {
            return db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE `id` = ?", [(int) $call['trunk_id']]) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Release: valid only while state='claimed' -- a no-op if the PBX has
     *  already reported `ended` in the meantime (plan.md §4). */
    function inbound_call_release(int $callId, int $userId, string $userName): array
    {
        $prefix = _p149_prefix();
        $stmt = db_query(
            "UPDATE `{$prefix}inbound_calls`
                SET `state` = 'ringing', `claimed_by` = NULL, `claimed_by_name` = NULL,
                    `claimed_at` = NULL, `claim_heartbeat_at` = NULL, `stale_since` = NULL,
                    `released_at` = NOW()
              WHERE `id` = ? AND `state` = 'claimed'",
            [$callId]
        );
        if ($stmt->rowCount() === 1) {
            $call = inbound_call_get($callId);
            inbound_call_audit($callId, 'released', $userId, $userName);
            if ($call) {
                $trunk = _p149_trunk_for_call($call);
                _p149_sse($callId, 'call:released', inbound_call_broadcast_payload($call, $trunk ?? []), $call['org_id'] !== null ? (int) $call['org_id'] : null);
            }
            return ['ok' => true, 'call' => $call];
        }
        return ['ok' => false, 'reason' => 'not_claimed'];
    }

    /**
     * Quick reassignment (FR-18a, plan.md §4a) — the physical-phone-vs-CAD-
     * claim mismatch self-correction. A second atomic conditional UPDATE,
     * time-boxed to pbx_trunks.reassign_grace_seconds. Deliberately does
     * NOT require action.manage_calls (fast and ungated, unlike FR-17's
     * override) and is its OWN distinct audit event type ('reassigned'),
     * never conflated with force_reclaimed_active.
     */
    function inbound_call_reassign(int $callId, int $newUserId, string $newUserName): array
    {
        $prefix = _p149_prefix();
        $call = inbound_call_get($callId);
        if ($call === null) return ['ok' => false, 'reason' => 'not_found'];
        if ((string) $call['state'] !== 'claimed') {
            return ['ok' => false, 'reason' => 'not_claimed', 'state' => $call['state']];
        }
        if ((int) $call['claimed_by'] === $newUserId) {
            return ['ok' => false, 'reason' => 'already_yours'];
        }
        $trunk = _p149_trunk_for_call($call);
        $graceSeconds = $trunk !== null ? (int) $trunk['reassign_grace_seconds'] : 20;
        $previousUserId = (int) $call['claimed_by'];
        $previousName = $call['claimed_by_name'];

        $stmt = db_query(
            "UPDATE `{$prefix}inbound_calls`
                SET `claimed_by` = ?, `claimed_by_name` = ?, `reassigned_from` = ?,
                    `claimed_at` = NOW(), `claim_heartbeat_at` = NOW(), `stale_since` = NULL
              WHERE `id` = ? AND `state` = 'claimed' AND `claimed_by` <> ?
                AND `claimed_at` > (NOW() - INTERVAL ? SECOND)",
            [$newUserId, $newUserName, $previousUserId, $callId, $newUserId, $graceSeconds]
        );

        if ($stmt->rowCount() === 1) {
            $updated = inbound_call_get($callId);
            inbound_call_audit(
                $callId, 'reassigned', $newUserId, $newUserName, null,
                ['previous_user_id' => $previousUserId, 'previous_user_name' => $previousName]
            );
            if ($updated) {
                _p149_sse($callId, 'call:claimed', inbound_call_broadcast_payload($updated, $trunk ?? []), $updated['org_id'] !== null ? (int) $updated['org_id'] : null);
            }
            return ['ok' => true, 'call' => $updated];
        }

        // Affected rows = 0: either the grace window elapsed, or the call
        // is no longer state='claimed'. Tell the caller plainly which, and
        // point at the FR-17 override path (still available) -- the
        // capability degrades gracefully, never disappears outright.
        $fresh = inbound_call_get($callId);
        if ($fresh === null) return ['ok' => false, 'reason' => 'not_found'];
        if ((string) $fresh['state'] !== 'claimed') {
            return ['ok' => false, 'reason' => 'not_claimed', 'state' => $fresh['state']];
        }
        return [
            'ok' => false,
            'reason' => 'grace_window_elapsed',
            'claimed_by_name' => $fresh['claimed_by_name'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // C. Heartbeat + staleness (Milestone 7) — plan.md §4
    // ─────────────────────────────────────────────────────────────────

    /** 15s client heartbeat while state='claimed'. Clears stale_since on
     *  a successful beat -- staleness is a read-time-derived liveness
     *  signal, not a one-shot decision. */
    function inbound_call_heartbeat(int $callId, int $userId): array
    {
        $prefix = _p149_prefix();
        $stmt = db_query(
            "UPDATE `{$prefix}inbound_calls`
                SET `claim_heartbeat_at` = NOW(), `stale_since` = NULL
              WHERE `id` = ? AND `claimed_by` = ? AND `state` = 'claimed'",
            [$callId, $userId]
        );
        if ($stmt->rowCount() === 1) {
            return ['ok' => true];
        }
        return ['ok' => false, 'reason' => 'not_your_claim'];
    }

    /**
     * Two-tier reclaim (FR-16/FR-17). $isStaleOverride tells the caller
     * (the API endpoint, which has already checked the caller's RBAC
     * permission for the tier it is about to attempt) which audit event
     * type to record; this function independently VERIFIES staleness
     * server-side rather than trusting the caller's assertion, so an
     * endpoint bug can never silently downgrade an active-claim override
     * into the low-friction stale path.
     */
    function inbound_call_force_reclaim(int $callId, int $userId, string $userName, ?string $reason = null): array
    {
        $prefix = _p149_prefix();
        $call = inbound_call_get($callId);
        if ($call === null) return ['ok' => false, 'reason' => 'not_found'];
        if ((string) $call['state'] !== 'claimed') {
            return ['ok' => false, 'reason' => 'not_claimed', 'state' => $call['state']];
        }
        $isStale = !empty($call['stale_since']);
        if (!$isStale && (empty($reason) || trim((string) $reason) === '')) {
            return ['ok' => false, 'reason' => 'reason_required'];
        }
        $previousUserId = (int) $call['claimed_by'];
        $previousName = $call['claimed_by_name'];

        $stmt = db_query(
            "UPDATE `{$prefix}inbound_calls`
                SET `claimed_by` = ?, `claimed_by_name` = ?, `reassigned_from` = ?,
                    `claimed_at` = NOW(), `claim_heartbeat_at` = NOW(), `stale_since` = NULL
              WHERE `id` = ? AND `state` = 'claimed' AND `claimed_by` <> ?",
            [$userId, $userName, $previousUserId, $callId, $userId]
        );
        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'reason' => 'race'];
        }
        $updated = inbound_call_get($callId);
        $eventType = $isStale ? 'force_reclaimed_stale' : 'force_reclaimed_active';
        inbound_call_audit(
            $callId, $eventType, $userId, $userName, $isStale ? $reason : trim((string) $reason),
            ['previous_user_id' => $previousUserId, 'previous_user_name' => $previousName]
        );
        if ($updated) {
            $trunk = _p149_trunk_for_call($updated);
            _p149_sse($callId, 'call:claimed', inbound_call_broadcast_payload($updated, $trunk ?? []), $updated['org_id'] !== null ? (int) $updated['org_id'] : null);
        }
        return ['ok' => true, 'call' => $updated, 'was_stale' => $isStale];
    }

    /**
     * Staleness sweep -- three missed 15s beats (45s default). Pure,
     * read-time-derived: if a heartbeat resumes, stale_since clears back
     * to NULL via inbound_call_heartbeat() above, not by this sweep.
     * Never auto-releases a stale claim (plan.md §4 -- a second dispatcher
     * independently starting to work a caller who may still genuinely be
     * on the line is a strictly worse outcome than a stale badge).
     */
    function inbound_calls_staleness_sweep(int $thresholdSeconds = 45): array
    {
        $prefix = _p149_prefix();
        $found = 0;
        try {
            $rows = db_fetch_all(
                "SELECT * FROM `{$prefix}inbound_calls`
                  WHERE `state` = 'claimed'
                    AND `stale_since` IS NULL
                    AND `claim_heartbeat_at` IS NOT NULL
                    AND `claim_heartbeat_at` < (NOW() - INTERVAL ? SECOND)",
                [$thresholdSeconds]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'found' => 0];
        }
        foreach ($rows as $row) {
            $callId = (int) $row['id'];
            $stmt = db_query(
                "UPDATE `{$prefix}inbound_calls` SET `stale_since` = NOW()
                  WHERE `id` = ? AND `state` = 'claimed' AND `stale_since` IS NULL",
                [$callId]
            );
            if ($stmt->rowCount() > 0) {
                $found++;
                inbound_call_audit($callId, 'stale_detected', null, null, null, [
                    'claimed_by' => $row['claimed_by'],
                    'last_heartbeat' => $row['claim_heartbeat_at'],
                ]);
                $updated = inbound_call_get($callId);
                if ($updated) {
                    $trunk = _p149_trunk_for_call($updated);
                    _p149_sse($callId, 'call:stale', inbound_call_broadcast_payload($updated, $trunk ?? []), $updated['org_id'] !== null ? (int) $updated['org_id'] : null);
                }
            }
        }
        return ['ok' => true, 'found' => $found];
    }

    // ─────────────────────────────────────────────────────────────────
    // D. Wrap-up fold (Milestone 6) — plan.md's "Wrap-up" section
    // ─────────────────────────────────────────────────────────────────

    /**
     * Folds any 'wrapup' row whose deadline (ended_at + trunk.wrapup_seconds)
     * has passed into 'ended'. Read-time-derived and idempotent -- piggy-
     * backs on the same sweep cadence as staleness (both driven from
     * inc/scheduled-jobs.php, never a raw cron entry).
     */
    function inbound_calls_wrapup_sweep(): array
    {
        $prefix = _p149_prefix();
        $folded = 0;
        try {
            $rows = db_fetch_all(
                "SELECT c.*, t.wrapup_seconds AS trunk_wrapup_seconds
                   FROM `{$prefix}inbound_calls` c
                   JOIN `{$prefix}pbx_trunks` t ON t.id = c.trunk_id
                  WHERE c.state = 'wrapup' AND c.ended_at IS NOT NULL"
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'folded' => 0];
        }
        foreach ($rows as $row) {
            $wrapSecs = (int) ($row['trunk_wrapup_seconds'] ?? 90);
            $deadline = strtotime((string) $row['ended_at']) + $wrapSecs;
            if (time() < $deadline) continue;
            $callId = (int) $row['id'];
            $stmt = db_query(
                "UPDATE `{$prefix}inbound_calls` SET `state` = 'ended' WHERE `id` = ? AND `state` = 'wrapup'",
                [$callId]
            );
            if ($stmt->rowCount() > 0) {
                $folded++;
                inbound_call_audit($callId, 'ended');
                $updated = inbound_call_get($callId);
                if ($updated) {
                    $trunk = _p149_trunk_for_call($updated);
                    _p149_sse($callId, 'call:ended', inbound_call_broadcast_payload($updated, $trunk ?? []), $updated['org_id'] !== null ? (int) $updated['org_id'] : null);
                }
            }
        }
        return ['ok' => true, 'folded' => $folded];
    }

    // ─────────────────────────────────────────────────────────────────
    // E. New Incident linking (Milestone 5) — plan.md §7
    // ─────────────────────────────────────────────────────────────────

    /**
     * Called on a successful incident save (window.CallPrefill.markHandled).
     * Sets ticket_id and, if the call is still in 'wrapup', ends it early --
     * the paperwork is genuinely done, no need to wait out the timer.
     */
    function inbound_call_link_ticket(int $callId, int $ticketId, ?int $userId = null, ?string $userName = null): array
    {
        $prefix = _p149_prefix();
        $call = inbound_call_get($callId);
        if ($call === null) return ['ok' => false, 'reason' => 'not_found'];

        db_query("UPDATE `{$prefix}inbound_calls` SET `ticket_id` = ? WHERE `id` = ?", [$ticketId, $callId]);
        inbound_call_audit($callId, 'linked_to_ticket', $userId, $userName, null, ['ticket_id' => $ticketId]);

        if ((string) $call['state'] === 'wrapup') {
            $stmt = db_query(
                "UPDATE `{$prefix}inbound_calls` SET `state` = 'ended' WHERE `id` = ? AND `state` = 'wrapup'",
                [$callId]
            );
            if ($stmt->rowCount() > 0) {
                inbound_call_audit($callId, 'ended', $userId, $userName, null, ['reason' => 'wrapup ended early on save']);
            }
        }
        $updated = inbound_call_get($callId);
        if ($updated) {
            $trunk = _p149_trunk_for_call($updated);
            _p149_sse($callId, 'call:ended', inbound_call_broadcast_payload($updated, $trunk ?? []), $updated['org_id'] !== null ? (int) $updated['org_id'] : null);
        }
        return ['ok' => true, 'call' => $updated];
    }

    /** Milestone 6 — clears a missed/abandoned call from the live panel
     *  without deleting the record. */
    function inbound_call_mark_reviewed(int $callId, int $userId, string $userName): array
    {
        $prefix = _p149_prefix();
        // `reviewed_at IS NULL` makes this a genuine one-shot action --
        // without it, re-clicking "reviewed" on an already-reviewed call
        // would silently succeed again (state stays 'abandoned' forever;
        // it is the ONLY signal that distinguishes "still needs review"
        // from "already reviewed"), re-stamping reviewed_at/reviewed_by
        // and appending a duplicate 'reviewed' audit row every time.
        $stmt = db_query(
            "UPDATE `{$prefix}inbound_calls` SET `reviewed_at` = NOW(), `reviewed_by` = ?
              WHERE `id` = ? AND `state` = 'abandoned' AND `reviewed_at` IS NULL",
            [$userId, $callId]
        );
        if ($stmt->rowCount() === 1) {
            inbound_call_audit($callId, 'reviewed', $userId, $userName);
            return ['ok' => true];
        }
        return ['ok' => false, 'reason' => 'not_abandoned'];
    }
}
