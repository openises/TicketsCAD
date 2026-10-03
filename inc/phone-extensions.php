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

/** Every extension, general numbers first then by extension string. The
 *  password itself is never selected; has_password says whether one is stored
 *  (Phase 155: this column was missing, so pe_mask() reported every extension
 *  as having NO password and the admin page showed a "None" warning badge on
 *  all of them -- found by the S2 test, which asserts the badge's source). */
function phone_extension_list() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_all(
        "SELECT id, extension, label, sip_username, workstation_token, org_id, is_general, enabled, created_at, updated_at,
                (sip_password IS NOT NULL AND sip_password <> '') AS has_password
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

/**
 * Phase 155 (GH#108 S2) -- an ADMINISTRATOR-SUPPLIED SIP password.
 *
 * Before this, create and rotate always generated the password, so connecting
 * TicketsCAD to an endpoint that already exists on the PBX meant editing the
 * PBX to match a value only this app knew. Now the administrator can supply
 * the PBX's existing password. Rules: 8-128 printable ASCII characters, no
 * spaces -- long enough not to invite toll fraud, plain enough to survive
 * pjsip.conf (where a semicolon starts a comment, an administrator's concern
 * the guide notes). The value is stored exactly like a generated one (the
 * browser needs the real secret to register) and is never echoed back by any
 * admin response.
 */
function phone_extension_validate_password($password) {
    $password = (string) $password;
    // \z, not $: a trailing newline must not slip past the end anchor.
    if (!preg_match('/^[!-~]{8,128}\z/', $password)) {
        throw new InvalidArgumentException(
            'SIP password must be 8-128 characters of letters, digits and symbols, with no spaces.'
        );
    }
    return $password;
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
 * Returns ['id'=>int, 'sip_username'=>string, 'sip_password'=>string|null,
 * 'password_supplied'=>bool] -- a GENERATED password is returned in full
 * exactly once, at creation, matching this project's established
 * credential-mint convention (sip_trunks' bearer_token, etc.). A password the
 * administrator SUPPLIED (Phase 155, $suppliedPassword) is never echoed back:
 * 'sip_password' is null, 'password_supplied' is true.
 */
function phone_extension_create($extension, $label, $isGeneral, $workstationToken, $orgId, $suppliedPassword = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $v = phone_extension_validate($extension, $label, $isGeneral);
    $supplied = ($suppliedPassword !== null && $suppliedPassword !== '');
    if ($supplied) {
        $suppliedPassword = phone_extension_validate_password($suppliedPassword);
    }

    $existing = db_fetch_one(
        "SELECT id FROM `{$prefix}phone_extensions` WHERE extension = ?",
        [$v['extension']]
    );
    if ($existing) {
        throw new RuntimeException("Extension '{$v['extension']}' already exists. Pick a different number.");
    }

    $sipUsername = $v['extension'];
    $sipPassword = $supplied ? $suppliedPassword : phone_extension_generate_password();
    $wsToken = trim((string) $workstationToken) !== '' ? trim((string) $workstationToken) : null;
    $org = ($orgId !== null && $orgId !== '') ? (int) $orgId : null;

    db_query(
        "INSERT INTO `{$prefix}phone_extensions`
            (extension, label, sip_username, sip_password, workstation_token, org_id, is_general, enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
        [$v['extension'], $v['label'], $sipUsername, $sipPassword, $wsToken, $org, $v['is_general'] ? 1 : 0]
    );
    $id = (int) db_insert_id();
    return [
        'id' => $id,
        'sip_username' => $sipUsername,
        'sip_password' => $supplied ? null : $sipPassword,
        'password_supplied' => $supplied,
    ];
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

/**
 * Phase 155 (GH#108 S2) -- store a password the administrator SUPPLIED for an
 * existing extension (the PBX endpoint already has it). Distinct from
 * phone_extension_rotate_password(), which mints a new random one. Nothing is
 * returned: the value is never echoed back.
 */
function phone_extension_set_password($id, $password) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $existing = phone_extension_get($id);
    if (!$existing) {
        throw new RuntimeException('Extension not found.');
    }
    $password = phone_extension_validate_password($password);
    db_query(
        "UPDATE `{$prefix}phone_extensions` SET sip_password = ? WHERE id = ?",
        [$password, (int) $id]
    );
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
        'register_scope' => phone_register_scope(),
        'internal_constituents' => phone_internal_constituents_enabled(),
    ];
}

/**
 * Phase 155 (GH#108 S1) -- WHERE the browser phone registers.
 *
 * A SIP registration lives only as long as the page's JavaScript, and
 * TicketsCAD is a multi-page application, so "register on every page" means
 * the extension drops off the PBX on every navigation (and an active call
 * ends). The default therefore registers only where the page does not
 * navigate away: the Console and the standalone Phone window (phone.php).
 * 'every_page' is for an install whose operators live on one page.
 *
 * Read by api/phone-extensions.php's my_extension payload -> phone-widget.js
 * (via PhoneDialLogic.shouldRegister); written by phone_register_scope_save().
 */
function phone_register_scope_values() {
    return ['phone_page', 'every_page'];
}

function phone_register_scope() {
    $v = (string) (get_variable('phone_register_scope') ?: '');
    return in_array($v, phone_register_scope_values(), true) ? $v : 'phone_page';
}

/**
 * Phase 155 (GH#108 S3, finding F6) -- does a call FROM one of our own
 * extensions get a Constituent?
 *
 * The three-laptop acceptance sentence was "I want the caller ID from that
 * workstation to be in the constituents". Until now a three-digit extension
 * never produced one (the four-digit minimum that protects the manual lookup
 * also swallowed it), so that sentence could not pass with extensions 101 to
 * 103. Default ON: a call from extension 101 resolves to (and, the first time,
 * creates) a Constituent named after the extension's label, matched EXACTLY on
 * the extension number so it never fuzzy-matches a member of the public. An
 * agency that does not want its own desks in the public contact list turns it
 * off, and a call from an extension then resolves to no Constituent at all.
 *
 * Stored as the literal '1' / '0'. NOTE: get_variable() returns the string
 * '0' for an explicit off, and '0' is falsy in PHP -- never write
 * `get_variable(..) ?: default` for a boolean setting.
 */
function phone_internal_constituents_enabled() {
    $v = get_variable('phone_internal_constituents');
    if ($v === false || $v === null || $v === '') {
        return true;
    }
    return (string) $v !== '0';
}

function phone_internal_constituents_save($enabled) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_internal_constituents', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$enabled ? '1' : '0']
    );
    return (bool) $enabled;
}

function phone_register_scope_save($scope) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $scope = trim((string) $scope);
    if (!in_array($scope, phone_register_scope_values(), true)) {
        throw new InvalidArgumentException("Registration scope must be 'phone_page' or 'every_page'.");
    }
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_register_scope', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$scope]
    );
    return $scope;
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
