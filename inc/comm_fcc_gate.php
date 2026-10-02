<?php
/**
 * inc/comm_fcc_gate.php — Phase 152 prerequisite #2: station-ID audit
 * read/write against comm_id_log/comm_ptt_state (sql/run_phase152_comm_id_log.php).
 *
 * Scope, precisely: this gate covers AUTONOMOUS transmissions only -- a
 * coupling/patch relaying one operator's speech onto another channel with
 * no direct human PTT press on that specific leg. It does NOT gate a
 * strip's own direct human PTT (that stays on today's model: the operator
 * is responsible for their own ID, the software gates/prompts, never
 * transmits on their behalf -- see inc/fcc_station_id.php, unchanged).
 *
 * Deliberately a SIBLING of inc/fcc_station_id.php, not an extension of
 * it: comm_id_log/comm_ptt_state are separate tables from dmr_id_log/
 * dmr_ptt_state (see the migration's docblock for why -- dmr_channels.id
 * and comm_channels.id are independent sequences that would otherwise
 * collide). The two PURE timing functions this file depends on
 * (fcc_may_transmit_without_id(), fcc_id_zone()) take a raw interval and a
 * timestamp -- they were already table-agnostic, so they're reused
 * verbatim rather than duplicated.
 */

require_once __DIR__ . '/fcc_station_id.php';
require_once __DIR__ . '/id-policy.php';

if (!function_exists('comm_fcc_last_id_at')) {
    /**
     * Most recent confirmed/relayed station-ID event for (comm_channels.id,
     * user_id). $userId = 0 reads the autonomous-relay pseudo-operator row
     * (no single human operator for that leg).
     */
    function comm_fcc_last_id_at(int $channelId, int $userId): ?string
    {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $row = db_fetch_value(
                "SELECT MAX(`id_at`) FROM `{$prefix}comm_id_log` WHERE `channel_id` = ? AND `user_id` = ?",
                [$channelId, $userId]
            );
            return $row !== null ? (string) $row : null;
        } catch (Exception $e) {
            return null; // fail closed at the call site, not here — see comm_fcc_may_transmit()
        }
    }
}

if (!function_exists('comm_fcc_ptt_state')) {
    /** Informational PTT bookkeeping — mirrors fcc_ptt_state()'s contract. */
    function comm_fcc_ptt_state(int $channelId, int $userId): array
    {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $row = db_fetch_one(
                "SELECT `last_tx_at`, `conversation_started_at` FROM `{$prefix}comm_ptt_state`
                  WHERE `channel_id` = ? AND `user_id` = ?",
                [$channelId, $userId]
            );
        } catch (Exception $e) {
            $row = null;
        }
        return [
            'last_tx_at' => $row['last_tx_at'] ?? null,
            'conversation_started_at' => $row['conversation_started_at'] ?? null,
        ];
    }
}

if (!function_exists('comm_fcc_record_id_event')) {
    /**
     * Append a station-ID event. This is the ONLY writer of comm_id_log —
     * mirrors fcc_record_id_event()'s append-only contract. $userId = 0 +
     * $source = 'autonomous_relay' for a system-initiated relay-leg ID with
     * no single human operator of record.
     */
    function comm_fcc_record_id_event(int $channelId, int $userId, string $callsign, string $source, ?string $notes = null): bool
    {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $validSources = ['confirmed_tx', 'monitoring_id', 'end_of_conversation', 'autonomous_relay'];
        if (!in_array($source, $validSources, true)) {
            return false;
        }
        try {
            db_query(
                "INSERT INTO `{$prefix}comm_id_log` (`channel_id`,`user_id`,`callsign`,`id_at`,`source`,`notes`)
                 VALUES (?, ?, ?, NOW(), ?, ?)",
                [$channelId, $userId, strtoupper(trim($callsign)), $source, $notes]
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('comm_fcc_record_tx')) {
    /** Informational-only TX bookkeeping — mirrors the DMR widget's pattern. */
    function comm_fcc_record_tx(int $channelId, int $userId, bool $newConversation): void
    {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            if ($newConversation) {
                db_query(
                    "INSERT INTO `{$prefix}comm_ptt_state` (`channel_id`,`user_id`,`last_tx_at`,`conversation_started_at`)
                     VALUES (?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE `last_tx_at` = NOW(), `conversation_started_at` = COALESCE(`conversation_started_at`, NOW())",
                    [$channelId, $userId]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}comm_ptt_state` (`channel_id`,`user_id`,`last_tx_at`)
                     VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE `last_tx_at` = NOW()",
                    [$channelId, $userId]
                );
            }
        } catch (Exception $e) {
            // Informational bookkeeping only — never fatal to the caller.
        }
    }
}

if (!function_exists('comm_fcc_may_transmit')) {
    /**
     * THE decision function for an autonomous relay leg. Returns:
     *   ['allowed' => bool, 'reason' => string, 'zone' => string]
     * reason values: 'not_applicable' (class has no ID requirement — e.g.
     * pstn/internal/Zello, ALWAYS allowed), 'no_callsign' (amateur/
     * commercial, nothing on file — refuse), 'ok' (within interval or no
     * prior ID needed yet under soft enforcement), 'lapsed_hard' (past
     * interval, hard enforcement — refuse), 'lapsed_soft' (past interval,
     * soft enforcement — allow, flagged).
     *
     * $callsign is the callsign of record for the relay (the CHANNEL's
     * configured callsign for an autonomous leg — there is no individual
     * operator to ask, per this gate's whole reason for existing).
     */
    function comm_fcc_may_transmit(int $channelId, string $regulatoryClass, ?int $configuredIntervalSecs, string $enforce, string $callsign): array
    {
        $policy = id_policy_by_class($regulatoryClass);
        if (!$policy['requires_id']) {
            return ['allowed' => true, 'reason' => 'not_applicable', 'zone' => 'none'];
        }

        $cs = strtoupper(trim($callsign));
        if ($cs === '' || !fcc_callsign_valid($cs)) {
            return ['allowed' => false, 'reason' => 'no_callsign', 'zone' => 'none'];
        }

        $interval = id_policy_effective_interval($regulatoryClass, $configuredIntervalSecs) ?? 600;
        // Autonomous relay legs have no per-user identity — pseudo-operator 0.
        $lastId = comm_fcc_last_id_at($channelId, 0);
        $zone   = fcc_id_zone($lastId, $interval);
        $mayNoId = fcc_may_transmit_without_id($lastId, $interval);

        if ($mayNoId) {
            return ['allowed' => true, 'reason' => 'ok', 'zone' => $zone];
        }
        if ($enforce === 'hard') {
            return ['allowed' => false, 'reason' => 'lapsed_hard', 'zone' => $zone];
        }
        // soft (or unset — fail toward the more cautious of the two
        // documented enforcement levels, never toward "off") allows but flags.
        return ['allowed' => true, 'reason' => 'lapsed_soft', 'zone' => $zone];
    }
}
