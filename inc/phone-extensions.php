<?php
/**
 * Phase 153 (2026-09-08) — SIP extension management for browser-native
 * WebRTC telephony ("general numbers and direct to station numbers").
 *
 * A `phone_extensions` row is a SIP endpoint the browser itself registers
 * as directly (via JsSIP over WSS to the Asterisk PBX) -- distinct from
 * Phase 149's `pbx_trunks`, which models an external line an adapter
 * posts webhooks against. `sip_password` is plaintext by necessity (the
 * browser needs the real value to register) -- same deliberate, documented
 * convention as inc/sip_token.php / inc/dmr_token.php for this exact class
 * of credential; masked on list/get responses that don't need it (see
 * api/phone-extensions.php), shown in full only to the one caller that
 * actually needs to configure a browser session with it.
 */

if (!function_exists('phone_extension_list')) {

/** Every extension, general numbers first then by extension string. */
function phone_extension_list() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_all(
        "SELECT id, extension, label, sip_username, workstation_token, org_id, is_general, enabled, created_at, updated_at
           FROM `{$prefix}phone_extensions`
          ORDER BY is_general DESC, extension"
    );
}

/** One extension by id, or null. Includes sip_password -- caller decides whether to mask it. */
function phone_extension_get($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one(
        "SELECT * FROM `{$prefix}phone_extensions` WHERE id = ?",
        [(int) $id]
    ) ?: null;
}

/** Generate a strong random SIP password -- the browser stores/uses this
 *  directly for JsSIP registration, so it must be a real usable secret,
 *  not a hash. 24 bytes -> 32 base64url chars, no separators to fumble
 *  when read off a screen. */
function phone_extension_generate_password() {
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function phone_extension_validate($extension, $label, $isGeneral) {
    $extension = trim((string) $extension);
    $label     = trim((string) $label);
    if ($extension === '') {
        throw new InvalidArgumentException('Extension is required.');
    }
    if (!preg_match('/^[0-9]{2,10}$/', $extension)) {
        throw new InvalidArgumentException('Extension must be 2-10 digits (e.g. 100, 101).');
    }
    if ($label === '') {
        throw new InvalidArgumentException('Label is required.');
    }
    if (strlen($label) > 100) {
        throw new InvalidArgumentException('Label must be 100 characters or fewer.');
    }
    return ['extension' => $extension, 'label' => $label, 'is_general' => (bool) $isGeneral];
}

/**
 * Create a new extension. Mints its own SIP username (= the extension
 * number, matching Asterisk's own convention) and a generated password.
 * Returns ['id'=>int, 'sip_username'=>string, 'sip_password'=>string] --
 * the password is returned in full exactly once, at creation, matching
 * this project's established credential-mint convention (sip_trunks'
 * bearer_token, etc.).
 */
function phone_extension_create($extension, $label, $isGeneral, $workstationToken, $orgId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $v = phone_extension_validate($extension, $label, $isGeneral);

    $existing = db_fetch_one(
        "SELECT id FROM `{$prefix}phone_extensions` WHERE extension = ?",
        [$v['extension']]
    );
    if ($existing) {
        throw new RuntimeException("Extension '{$v['extension']}' already exists. Pick a different number.");
    }

    $sipUsername = $v['extension'];
    $sipPassword = phone_extension_generate_password();
    $wsToken = trim((string) $workstationToken) !== '' ? trim((string) $workstationToken) : null;
    $org = ($orgId !== null && $orgId !== '') ? (int) $orgId : null;

    db_query(
        "INSERT INTO `{$prefix}phone_extensions`
            (extension, label, sip_username, sip_password, workstation_token, org_id, is_general, enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
        [$v['extension'], $v['label'], $sipUsername, $sipPassword, $wsToken, $org, $v['is_general'] ? 1 : 0]
    );
    $id = (int) db_insert_id();
    return ['id' => $id, 'sip_username' => $sipUsername, 'sip_password' => $sipPassword];
}

/** Update label/workstation_token/org_id/is_general/enabled. Extension
 *  number and SIP credentials are immutable once created -- a genuine
 *  renumber or credential rotation is delete+recreate (matching every
 *  other adapter's channel-identity convention in this codebase); a
 *  rotate-password action is a distinct, explicit operation (see
 *  phone_extension_rotate_password()), never silent on a general edit. */
function phone_extension_update($id, $label, $isGeneral, $workstationToken, $orgId, $enabled) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $existing = phone_extension_get($id);
    if (!$existing) {
        throw new RuntimeException('Extension not found.');
    }
    $v = phone_extension_validate($existing['extension'], $label, $isGeneral);
    $wsToken = trim((string) $workstationToken) !== '' ? trim((string) $workstationToken) : null;
    $org = ($orgId !== null && $orgId !== '') ? (int) $orgId : null;
    $enabledInt = $enabled ? 1 : 0;

    db_query(
        "UPDATE `{$prefix}phone_extensions`
            SET label = ?, is_general = ?, workstation_token = ?, org_id = ?, enabled = ?
          WHERE id = ?",
        [$v['label'], $v['is_general'] ? 1 : 0, $wsToken, $org, $enabledInt, (int) $id]
    );
    return phone_extension_get($id);
}

/** Mint a fresh password for an existing extension. Returned in full
 *  exactly once, matching sip_trunks' trunk_rotate_token action. */
function phone_extension_rotate_password($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $existing = phone_extension_get($id);
    if (!$existing) {
        throw new RuntimeException('Extension not found.');
    }
    $newPassword = phone_extension_generate_password();
    db_query(
        "UPDATE `{$prefix}phone_extensions` SET sip_password = ? WHERE id = ?",
        [$newPassword, (int) $id]
    );
    return $newPassword;
}

function phone_extension_delete($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $existing = phone_extension_get($id);
    if (!$existing) { return null; }
    db_query("DELETE FROM `{$prefix}phone_extensions` WHERE id = ?", [(int) $id]);
    return $existing;
}

/**
 * The one direct-station extension bound to this browser's own
 * workstation_token (the same opaque per-browser value
 * assets/js/console-workstation.js generates and persists -- Phase 152's
 * established "this browser proves it's this workstation" convention).
 * Never matches a general number -- a workstation registers as its OWN
 * direct extension so it can RECEIVE a call; the general number is a
 * dialplan target, never something a browser itself registers as.
 */
function phone_extension_find_by_workstation_token($token) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $token = trim((string) $token);
    if ($token === '') { return null; }
    return db_fetch_one(
        "SELECT * FROM `{$prefix}phone_extensions`
          WHERE workstation_token = ? AND is_general = 0 AND enabled = 1
          LIMIT 1",
        [$token]
    ) ?: null;
}

/** The one enabled general-number extension, if configured. */
function phone_extension_find_general() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one(
        "SELECT * FROM `{$prefix}phone_extensions` WHERE is_general = 1 AND enabled = 1 LIMIT 1"
    ) ?: null;
}

/**
 * PBX connection settings the browser's phone widget needs (WSS URL for
 * JsSIP, the general number to show as a dial-pad shortcut). Stored in
 * the `settings` table -- get_variable()/this pair, NOT the separate
 * `config` table (see CLAUDE.md's "TWO settings stores" pitfall).
 */
function phone_pbx_settings_get() {
    return [
        'wss_url'        => (string) (get_variable('phone_pbx_wss_url') ?: ''),
        'general_number' => (string) (get_variable('phone_general_number') ?: ''),
    ];
}

function phone_pbx_settings_save($wssUrl, $generalNumber) {
    $prefix  = $GLOBALS['db_prefix'] ?? '';
    $wssUrl  = trim((string) $wssUrl);
    $general = trim((string) $generalNumber);
    if ($wssUrl !== '' && !preg_match('#^wss://#i', $wssUrl)) {
        throw new InvalidArgumentException('PBX WebSocket URL must start with wss://');
    }
    if ($general !== '' && !preg_match('/^[0-9]{2,10}$/', $general)) {
        throw new InvalidArgumentException('General number must be 2-10 digits.');
    }
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_pbx_wss_url', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$wssUrl]
    );
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_general_number', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$general]
    );
    return phone_pbx_settings_get();
}

}
