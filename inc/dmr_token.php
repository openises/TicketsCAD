<?php
/**
 * dmr_token.php — the DMR bridge bearer token: mint, store, read.
 *
 * WHY THIS FILE EXISTS
 * ────────────────────
 * `dmr_channels.bridge_token` used to be stored as `hash('sha256', $token)`,
 * copied from api/mesh.php's mint/verify convention. That convention is right
 * for a credential you VERIFY and wrong for one you PRESENT.
 *
 * The CAD is a *client* of the bridge: api/dmr-tx-audio.php, api/dmr-stream.php,
 * api/dmr-tx-stream.php, api/dmr-audio.php, proxy/dmr-proxy.php,
 * inc/channel_registry.php, inc/weather_radio.php and api/radio-ai-decide.php all
 * have to put the token in an `Authorization: Bearer` header on every call. A
 * digest cannot be turned back into the value the bridge compares against
 * (`DMR_BEARER_TOKEN`), so every one of those calls answered 401 — silently,
 * because the DMR side kept working and only the operator-driven Test dialog
 * (which asks a human to paste the plaintext) ever succeeded.
 *
 * Reported by @kmk1971 in openises/tickets#10, reproduced against HBLink3.
 *
 * WHY PLAINTEXT AND NOT ENCRYPTED AT REST
 * ───────────────────────────────────────
 * This is the same shape as every other outbound credential in the codebase —
 * `settings.smtp_pass`, `settings.slack_token`, `settings.sms_twilio_token`,
 * `zello_user_config.zello_password` — all of which are stored as-is and masked
 * on the way out to the browser (see inc/settings-secrets.php). Encrypting only
 * this one would be inconsistent, would not fit the existing `CHAR(64)` column,
 * and buys little: the decryption key would have to live on the same filesystem
 * as config.php, which already holds the database credentials that got the
 * attacker to the row in the first place.
 *
 * The controls that do matter are enforced here and in api/dvswitch.php:
 *   * the value is NEVER returned by a GET — `?action=channels` reports a
 *     `has_token` boolean, `?action=channel` does not select the column;
 *   * it is returned exactly once, from the POST that mints it;
 *   * a token stored by an older version is recognised as unusable and reported
 *     as "must be regenerated" rather than being sent and 401-ing.
 *
 * The `bridge_token_format` column records which era a stored value comes from:
 *   'plain'       — usable; the CAD can present it.
 *   'legacy_hash' — a SHA-256 digest written before this fix. Unrecoverable;
 *                   the channel's token must be rotated (or the operator can
 *                   paste the plaintext they saved at mint time into the Test
 *                   dialog, and a successful probe adopts it — see
 *                   dmr_token_adopt()).
 *
 * A hash and a plaintext token are both 64 hex characters, so the two cannot be
 * told apart by inspection — which is exactly why the column exists.
 */

require_once __DIR__ . '/db.php';

if (!function_exists('dmr_token_mint')) {

    /** Generate a fresh bridge bearer token (64 hex chars). */
    function dmr_token_mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * True when this install has the `bridge_token_format` column.
     * Older databases that have not run the migration yet do not, and are
     * treated as 'plain' so nothing breaks before the migration runs.
     */
    function dmr_token_format_column_exists(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $cached = (int) db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = 'bridge_token_format'",
                [$prefix . 'dmr_channels']
            ) > 0;
        } catch (Throwable $e) {
            $cached = false;
        }
        return $cached;
    }

    /**
     * Persist a bridge token in the form the CAD's outbound callers need.
     *
     * This is the ONLY writer of `bridge_token`. api/dvswitch.php's
     * channel_create and channel_rotate_token both go through it, so the
     * written value and the value shown to the admin can never drift apart.
     */
    function dmr_token_store(int $channelId, string $token): void
    {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        if (dmr_token_format_column_exists()) {
            db_query(
                "UPDATE `{$prefix}dmr_channels`
                    SET `bridge_token` = ?, `bridge_token_format` = 'plain',
                        `updated_at` = NOW()
                  WHERE `id` = ?",
                [$token, $channelId]
            );
        } else {
            db_query(
                "UPDATE `{$prefix}dmr_channels`
                    SET `bridge_token` = ?, `updated_at` = NOW()
                  WHERE `id` = ?",
                [$token, $channelId]
            );
        }
    }

    /**
     * True when the row's stored token is a pre-fix SHA-256 digest and so
     * cannot be presented to the bridge.
     *
     * @param array $row a dmr_channels row. Most callers select only the
     *                   columns they need and so do not carry
     *                   bridge_token_format; when it is absent but the row
     *                   has an id, it is looked up (one indexed read — the
     *                   alternative was widening eight SELECTs, one of which
     *                   is an SSE stream, against a column older installs do
     *                   not have yet). On a database that predates the
     *                   migration the lookup is skipped and the token is
     *                   treated as usable, i.e. behaviour is unchanged until
     *                   the migration runs.
     */
    function dmr_token_needs_regen(array $row): bool
    {
        if (($row['bridge_token'] ?? '') === '') return false;
        if (array_key_exists('bridge_token_format', $row)) {
            return ((string) $row['bridge_token_format'] === 'legacy_hash');
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || !dmr_token_format_column_exists()) return false;
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $fmt = (string) db_fetch_value(
                "SELECT `bridge_token_format` FROM `{$prefix}dmr_channels` WHERE `id` = ?",
                [$id]
            );
        } catch (Throwable $e) {
            return false;
        }
        return ($fmt === 'legacy_hash');
    }

    /**
     * The value to put after "Bearer " when calling the bridge.
     *
     * Returns '' when there is nothing usable — either no token at all, or a
     * legacy hash. Callers already guard on an empty token; pair that guard
     * with dmr_token_missing_reason() so the operator is told which it is.
     */
    function dmr_bridge_token(array $row): string
    {
        $token = (string) ($row['bridge_token'] ?? '');
        if ($token === '') return '';
        if (dmr_token_needs_regen($row)) return '';
        return $token;
    }

    /** Operator-facing explanation for an empty dmr_bridge_token(). */
    function dmr_token_missing_reason(array $row): string
    {
        if (dmr_token_needs_regen($row)) {
            return 'This DMR channel\'s bridge token was stored hashed by an '
                 . 'older version of TicketsCAD and cannot be sent to the '
                 . 'bridge. Regenerate it: Settings → Communications & Integrations → DMR → '
                 . 'Rotate token, then paste the new value into the bridge\'s '
                 . 'DMR_BEARER_TOKEN and restart it.';
        }
        return 'Channel missing bridge_host / bridge_port / bridge_token';
    }

    /**
     * Adopt a token an operator supplied by hand, AFTER it has been proven
     * against the live bridge.
     *
     * This is the repair path for an install that already has a legacy hash:
     * the admin pastes the plaintext they saved at mint time into the Test
     * dialog, the /health probe returns 200 — which is the bridge itself
     * confirming the value is correct — and only then do we store it. No
     * bridge restart, no token rotation, and the unattended callers start
     * working immediately.
     *
     * Never call this without a successful probe: it would let a typo
     * overwrite a working token.
     */
    function dmr_token_adopt(int $channelId, string $token): void
    {
        if ($channelId <= 0 || $token === '') return;
        dmr_token_store($channelId, $token);
    }

    /**
     * Resolve which dmr_channels row a caller means: an explicit id if
     * given and valid, else the first enabled channel (matching the
     * default-channel logic already duplicated inline in
     * api/dmr-tx-audio.php and api/dmr-tx-stream.php). Added for Phase 148
     * (api/dmr-station-id.php) rather than refactoring those two existing,
     * working endpoints to call it -- keeps this change additive.
     *
     * @param string $cols column list for the SELECT (caller picks only
     *                     what it needs, matching the rest of this file's
     *                     convention of narrow selects).
     */
    function dmr_resolve_channel(?int $channelId, string $cols = '*'): ?array
    {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        if ($channelId !== null && $channelId > 0) {
            return db_fetch_one(
                "SELECT {$cols} FROM `{$prefix}dmr_channels` WHERE `id` = ? LIMIT 1",
                [$channelId]
            ) ?: null;
        }
        return db_fetch_one(
            "SELECT {$cols} FROM `{$prefix}dmr_channels` WHERE `enabled` = 1 ORDER BY `id` LIMIT 1"
        ) ?: null;
    }
}
