<?php
/**
 * sip_token.php — the SIP/PBX trunk bearer token: mint, mask, validate.
 *
 * Deliberately duplicates inc/dmr_token.php's mint/mask/validate shape
 * rather than sharing a module with it (plan.md §2): pbx_trunks and
 * dmr_channels are two independent credential lifecycles, and coupling
 * them now would make a future DMR-token change silently affect SIP auth.
 * Unify only if a third inbound-webhook integration shows up (rule of
 * three) — see plan.md's own note on this.
 *
 * WHY PLAINTEXT AND NOT ENCRYPTED AT REST
 * Same reasoning as inc/dmr_token.php: this is the same shape as every
 * other outbound/inbound service credential in this codebase (settings
 * .smtp_pass, dmr_channels.bridge_token, zello_user_config
 * .zello_password) — stored as-is, masked on the way out to the browser,
 * returned in full exactly once at mint/rotate time. The controls that
 * matter are enforced here and in api/sip-trunks.php:
 *   * the value is NEVER returned by a GET — the admin CRUD list reports
 *     a `has_token` boolean only;
 *   * it is returned exactly once, from the POST that mints/rotates it;
 *   * api/sip-ingest.php compares it with hash_equals(), never a
 *     substring/loose comparison.
 */

require_once __DIR__ . '/db.php';

if (!function_exists('sip_token_mint')) {

    /** Generate a fresh bearer token (64 hex chars). */
    function sip_token_mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Persist a bearer token on a pbx_trunks row. This is the ONLY writer
     * of `bearer_token` — api/sip-trunks.php's create/rotate actions both
     * go through it, so the written value and the value shown to the
     * admin can never drift apart.
     */
    function sip_token_store(int $trunkId, string $token): void
    {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        db_query(
            "UPDATE `{$prefix}pbx_trunks`
                SET `bearer_token` = ?, `updated_at` = NOW()
              WHERE `id` = ?",
            [$token, $trunkId]
        );
    }

    /**
     * Mask a stored token for display in an admin list — never the full
     * value, just enough for an operator to tell trunks apart at a glance
     * (last 4 characters), matching this codebase's existing masked-secret
     * convention (inc/settings-secrets.php).
     */
    function sip_token_mask(string $token): string
    {
        if ($token === '') return '';
        $len = strlen($token);
        if ($len <= 4) return str_repeat('*', $len);
        return str_repeat('*', $len - 4) . substr($token, -4);
    }

    /**
     * Resolve the pbx_trunks row for a presented bearer token, or null.
     * Uses hash_equals() against every enabled trunk's stored token —
     * trunk identity comes ONLY from the token, never from a field in the
     * request body (plan.md §2: "a compromised or misconfigured adapter
     * cannot claim to be a different trunk than its token authorizes").
     *
     * Linear scan is intentional and acceptable at this scale (an
     * install with dozens of trunks is already an extreme outlier for a
     * volunteer-agency CAD); a future phase could index on a token
     * fingerprint if that ever changes.
     */
    function sip_token_resolve_trunk(string $presentedToken): ?array
    {
        if ($presentedToken === '') return null;
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $trunks = db_fetch_all(
                "SELECT * FROM `{$prefix}pbx_trunks` WHERE `enabled` = 1"
            );
        } catch (Exception $e) {
            return null;
        }
        foreach ($trunks as $row) {
            $stored = (string) ($row['bearer_token'] ?? '');
            if ($stored !== '' && hash_equals($stored, $presentedToken)) {
                return $row;
            }
        }
        return null;
    }
}
