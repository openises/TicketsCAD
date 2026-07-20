<?php
/**
 * Phase 117 (GH #84, a beta tester/SAG) — unit-level OwnTracks device helpers.
 *
 * A unit (responder/vehicle) can carry its OWN OwnTracks device, tracked
 * independently of personnel. This reuses the existing device-tracking stack —
 * NO new schema:
 *   - location_ingest_tokens (Phase 89 per-device token; provider=owntracks,
 *     device_unique_id = the unit's OwnTracks TID)
 *   - unit_location_bindings (responder_id -> provider + unit_identifier)
 *   - inc/location-resolver.php resolves the unit's location from its bound
 *     device's latest location_reports row.
 *
 * The three key values — token.device_unique_id, binding.unit_identifier, and
 * the OwnTracks app's `tid` — must all equal the same short "unit TID".
 *
 * These are the REAL writers; api/owntracks-config.php's unit_* actions AND the
 * regression test (tools/test_unit_owntracks.php) both call them, so tests
 * exercise the production path rather than a hand-seeded copy.
 *
 * Requires inc/db.php (db_query/db_fetch_one/db_fetch_value/db_insert_id) and a
 * global $prefix to be in scope.
 */

if (!function_exists('_p117_ot_provider')) {

    function _p117_ot_provider() {
        global $prefix;
        try {
            return db_fetch_one("SELECT `id`, `enabled` FROM `{$prefix}location_providers` WHERE `code`='owntracks' LIMIT 1");
        } catch (Exception $e) { return null; }
    }

    function _p117_base_url() {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    // Existing owntracks TID bound to this unit, if any.
    function _p117_unit_tid($responderId, $providerId) {
        global $prefix;
        $r = db_fetch_one(
            "SELECT `unit_identifier` FROM `{$prefix}unit_location_bindings`
              WHERE `responder_id` = ? AND `provider_id` = ? AND `active` = 1
              ORDER BY `id` ASC LIMIT 1",
            [$responderId, $providerId]
        );
        return $r ? (string) $r['unit_identifier'] : null;
    }

    // Short, unique TID for a unit device (OwnTracks tid is ~2 chars).
    function _p117_generate_tid($responderId, $providerId, $explicit = '') {
        global $prefix;
        $isFree = function ($tid) use ($prefix, $providerId) {
            if ($tid === '') return false;
            $n = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}unit_location_bindings` WHERE `provider_id` = ? AND `unit_identifier` = ?",
                [$providerId, $tid]
            );
            return $n === 0;
        };
        if ($explicit !== '' && $isFree($explicit)) return $explicit;
        $u = db_fetch_one("SELECT `name`, `handle` FROM `{$prefix}responder` WHERE `id` = ?", [$responderId]);
        $seed = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($u['handle'] ?? '') . (string) ($u['name'] ?? '')));
        if (strlen($seed) >= 2 && $isFree(substr($seed, 0, 2))) return substr($seed, 0, 2);
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        for ($i = 0; $i < strlen($alpha); $i++) {
            for ($j = 0; $j < strlen($alpha); $j++) {
                if ($isFree($alpha[$i] . $alpha[$j])) return $alpha[$i] . $alpha[$j];
            }
        }
        return 'U' . $responderId; // last resort (unique by id, may exceed 2 chars)
    }

    // OwnTracks device configuration for a unit device token.
    function _p117_unit_config($responderId, $tid, $secret) {
        $username = 'unit-' . $responderId; // cosmetic — device-token path matches by secret_hash, not username
        return [
            '_type'               => 'configuration',
            'url'                 => _p117_base_url() . '/api/location.php?provider=owntracks',
            'auth'                => true,
            'username'            => $username,
            'password'            => $secret,   // raw device token, sent as HTTP Basic password
            'deviceId'            => $tid,
            'tid'                 => $tid,
            'mode'                => 3,         // HTTP private
            'monitoring'          => 1,         // "move" — sensible for a vehicle
            'locatorInterval'     => 30,
            'locatorDisplacement' => 0,
            'pubTopicBase'        => 'owntracks/' . $username . '/' . $tid,
            'cmd'                 => false,
        ];
    }

    // Provision (or rotate) a unit's OwnTracks device: mint a device token +
    // ensure the unit_location_bindings row. Returns [tid, token_id, secret_raw].
    function _p117_provision_unit_device($responderId, $providerId, $explicit = '', $createdBy = null) {
        global $prefix;
        $tid = _p117_unit_tid($responderId, $providerId);
        if ($tid === null) $tid = _p117_generate_tid($responderId, $providerId, $explicit);

        $raw   = bin2hex(random_bytes(24));
        $hash  = hash('sha256', $raw);
        $u     = db_fetch_one("SELECT `name` FROM `{$prefix}responder` WHERE `id` = ?", [$responderId]);
        $label = substr('Unit ' . ($u['name'] ?? ('#' . $responderId)) . ' device', 0, 120);
        db_query(
            "INSERT INTO `{$prefix}location_ingest_tokens`
                (`label`, `secret_hash`, `provider_id`, `device_unique_id`, `created_by`)
             VALUES (?, ?, ?, ?, ?)",
            [$label, $hash, $providerId, $tid, $createdBy ?: null]
        );
        $tokenId = (int) db_insert_id();

        // Idempotent binding upsert. Priority 40 so the unit's OWN device outranks
        // personnel-inherited (default 50) location.
        $existing = db_fetch_one(
            "SELECT `id` FROM `{$prefix}unit_location_bindings`
              WHERE `responder_id` = ? AND `provider_id` = ? AND `unit_identifier` = ?",
            [$responderId, $providerId, $tid]
        );
        if (!$existing) {
            db_query(
                "INSERT INTO `{$prefix}unit_location_bindings`
                    (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`, `source`)
                 VALUES (?, ?, ?, 40, 1, 'manual')",
                [$responderId, $providerId, $tid]
            );
        } else {
            db_query("UPDATE `{$prefix}unit_location_bindings` SET `active` = 1 WHERE `id` = ?", [(int) $existing['id']]);
        }
        return ['tid' => $tid, 'token_id' => $tokenId, 'secret_raw' => $raw];
    }

    // Revoke a unit device token; deactivate the binding when no live token
    // remains for its TID. Returns ['tid'=>, 'binding_deactivated'=>bool] or null.
    function _p117_revoke_unit_device($responderId, $providerId, $tokenId) {
        global $prefix;
        $tok = db_fetch_one(
            "SELECT `id`, `device_unique_id` FROM `{$prefix}location_ingest_tokens`
              WHERE `id` = ? AND `provider_id` = ?",
            [$tokenId, $providerId]
        );
        if (!$tok) return null;
        db_query("UPDATE `{$prefix}location_ingest_tokens` SET `revoked_at` = NOW() WHERE `id` = ?", [$tokenId]);
        $tid  = (string) $tok['device_unique_id'];
        $live = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}location_ingest_tokens`
              WHERE `provider_id` = ? AND `device_unique_id` = ? AND `revoked_at` IS NULL",
            [$providerId, $tid]
        );
        if ($live === 0) {
            db_query(
                "UPDATE `{$prefix}unit_location_bindings` SET `active` = 0
                  WHERE `responder_id` = ? AND `provider_id` = ? AND `unit_identifier` = ?",
                [$responderId, $providerId, $tid]
            );
        }
        return ['tid' => $tid, 'binding_deactivated' => ($live === 0)];
    }

    // RBAC gate for the unit-OT actions (endpoint use; needs rbac_can + json_error).
    function _p117_unit_rbac() {
        if (!rbac_can('action.manage_ingest_tokens') && !rbac_can('action.manage_config')) {
            json_error('Forbidden', 403);
        }
    }
}
