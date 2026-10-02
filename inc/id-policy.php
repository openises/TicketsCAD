<?php
/**
 * inc/id-policy.php — Phase 152 prerequisite #2: station-ID requirements
 * are a property of a channel's regulatory_class, not one global constant.
 *
 * Corrected 2026-09-06 (Eric): amateur and commercial/public-safety radio
 * have DIFFERENT ID intervals under different FCC rules (Part 97 vs
 * Part 90) -- reusing amateur's interval for commercial, or vice versa, is
 * wrong, not just imprecise. Zello and other internal/VoIP-only channels
 * have NO station-ID requirement at all, ever -- the caller must be able
 * to tell "not applicable" apart from "applicable, currently compliant"
 * without evaluating an interval that doesn't exist for that class.
 *
 * This file is data/policy only -- it does not touch dmr_id_log,
 * comm_id_log, or any transmission-gating decision. See
 * inc/fcc_station_id.php (the existing, unchanged, DMR-specific amateur
 * gate) and inc/comm_fcc_gate.php (the new comm_channels-scoped audit sink
 * for autonomous transmissions) for where this policy actually gets
 * enforced.
 */

if (!function_exists('id_policy_by_class')) {
    /**
     * @param string $regulatoryClass one of comm_channels.regulatory_class:
     *                                'amateur'|'commercial'|'pstn'|'internal'
     * @return array{requires_id: bool, interval_ceiling_secs: int|null}
     *               interval_ceiling_secs is null when requires_id is
     *               false -- callers must check requires_id first, never
     *               infer "not applicable" from a null/zero interval.
     */
    function id_policy_by_class(string $regulatoryClass): array
    {
        switch ($regulatoryClass) {
            case 'amateur':
                // 47 CFR §97.119 — identify at least once every 10 minutes.
                return ['requires_id' => true, 'interval_ceiling_secs' => 600];
            case 'commercial':
                // Part 90 land mobile / public safety — 15-minute interval,
                // a genuinely different rule from Part 97, not the same
                // number reused. (Confirm against the specific license
                // class if an install ever needs a stricter ceiling; 900s
                // is the general Part 90 default this project uses.)
                return ['requires_id' => true, 'interval_ceiling_secs' => 900];
            case 'pstn':
            case 'internal':
            default:
                // Zello and any other internal/VoIP-only channel: no
                // station-ID requirement exists for these at all. Not
                // "requirement met" -- not applicable. A caller that
                // evaluates an interval here anyway is a bug.
                return ['requires_id' => false, 'interval_ceiling_secs' => null];
        }
    }
}

if (!function_exists('id_policy_effective_interval')) {
    /**
     * A channel's own configured interval (e.g. dmr_channels.id_interval_
     * seconds, or a future comm_channels.config_json id_interval_seconds
     * for non-DMR amateur/commercial channels) is a per-channel OVERRIDE of
     * the class ceiling -- it may be stricter, never looser. Returns null
     * when the class has no ID requirement at all (mirrors
     * id_policy_by_class()'s contract).
     */
    function id_policy_effective_interval(string $regulatoryClass, ?int $configuredSecs): ?int
    {
        $policy = id_policy_by_class($regulatoryClass);
        if (!$policy['requires_id']) {
            return null;
        }
        $ceiling = $policy['interval_ceiling_secs'];
        if ($configuredSecs === null || $configuredSecs <= 0) {
            return $ceiling;
        }
        return min($configuredSecs, $ceiling);
    }
}
