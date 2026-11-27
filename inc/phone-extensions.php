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
        // GH#108 (panel-review C2): the install-wide emergency-call policy, so the
        // admin page and the phone widget both read it from the one payload they
        // already fetch. restrict_emergency is OFF by default (every extension can
        // dial emergency numbers); emergency_numbers is DISPLAY/dial-plan guidance.
        'restrict_emergency' => phone_restrict_emergency_enabled(),
        'emergency_numbers'  => phone_emergency_numbers(),
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

/*
 * GH#108 (panel-review 2026-10-20, finding C3 / spec section 4) -- ephemeral
 * SIP credentials.
 *
 * The exposure this closes: api/phone-extensions.php?action=my_extension used
 * to hand the browser the stored, permanent per-extension sip_password, which
 * JsSIP then registered with. Session-gated on fetch, but reusable forever
 * from any SIP client by anyone with devtools on a signed-in session (or any
 * XSS) -- toll fraud. In ephemeral mode the browser instead gets a short-TTL
 * credential derived from a server-only shared secret, in the TURN-REST /
 * RFC-7635 shape, and the stored password never leaves the server.
 *
 *   sip_username = "<expiry-unix>:<the extension's sip_username or extension>"
 *   sip_password = base64( HMAC-SHA256( shared_secret, sip_username ) )
 *
 * The PBX validates this itself (realtime-auth backend or an ARI provisioner),
 * recomputing the HMAC with a constant-time compare and refusing once the
 * embedded expiry has passed -- see docs/PHONE-TELEPHONY-GUIDE.md. TicketsCAD
 * only MINTS; it never validates, and it never speaks SIP.
 *
 * 'static' is the default so an existing install is byte-identical after an
 * upgrade (my_extension returns the stored password and NO expires_at, exactly
 * as before). It is toll-fraud-exposed and documented as safe only on an
 * isolated LAN.
 */
function phone_sip_mode_values() {
    return ['static', 'ephemeral'];
}

function phone_sip_mode() {
    // A string enum, never the literal '0', so `?:` is safe (unlike the boolean
    // settings this file is careful about).
    $v = (string) (get_variable('phone_sip_mode') ?: '');
    return in_array($v, phone_sip_mode_values(), true) ? $v : 'static';
}

function phone_sip_mode_save($mode) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $mode = trim((string) $mode);
    if (!in_array($mode, phone_sip_mode_values(), true)) {
        throw new InvalidArgumentException("SIP credential mode must be 'static' or 'ephemeral'.");
    }
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_sip_mode', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$mode]
    );
    // Enabling ephemeral mode guarantees a shared secret exists, so the (GET,
    // read-only) minting path never has to create one. Generation lives on this
    // deliberate write path, not on a browser's my_extension request.
    if ($mode === 'ephemeral') { phone_sip_ensure_shared_secret(); }
    return $mode;
}

/* The ephemeral credential's lifetime, in seconds. The floor keeps a leaked
 * credential short-lived; the ceiling keeps it from becoming a de-facto static
 * password. Ten minutes by default, matching a typical SIP register-refresh. */
function phone_sip_cred_ttl_default() { return 600; }
function phone_sip_cred_ttl_min()     { return 60; }
function phone_sip_cred_ttl_max()     { return 3600; }

function phone_sip_cred_ttl_clamp($seconds) {
    $seconds = (int) $seconds;
    if ($seconds < phone_sip_cred_ttl_min()) { return phone_sip_cred_ttl_min(); }
    if ($seconds > phone_sip_cred_ttl_max()) { return phone_sip_cred_ttl_max(); }
    return $seconds;
}

function phone_sip_cred_ttl() {
    $v = get_variable('phone_sip_cred_ttl');
    // An unset/blank value is the default; '0' is falsy, so never use `?:` here.
    if ($v === false || $v === null || $v === '') { return phone_sip_cred_ttl_default(); }
    return phone_sip_cred_ttl_clamp((int) $v);
}

function phone_sip_cred_ttl_save($seconds) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $clamped = phone_sip_cred_ttl_clamp($seconds);
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_sip_cred_ttl', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [(string) $clamped]
    );
    return $clamped;
}

/**
 * The server-only HMAC shared secret for ephemeral credentials, READ ONLY:
 * returns the stored secret, or '' if none has been generated yet. This is what
 * the minting path (reached by the my_extension GET) calls, so that path never
 * writes -- generation happens only on a deliberate write/config path
 * (phone_sip_ensure_shared_secret(), called by phone_sip_mode_save('ephemeral')
 * and the CLI tool). It is NEVER returned to the browser (the whole point of
 * the scheme) and is masked on read in the general settings UI via the
 * `_secret` suffix backstop in inc/settings-secrets.php.
 */
function phone_sip_shared_secret() {
    $existing = get_variable('phone_sip_shared_secret');
    return is_string($existing) ? $existing : '';
}

/**
 * Ensure a shared secret exists, generating and persisting a strong one (32
 * random bytes, hex-encoded) if absent; returns it. A WRITE path -- never
 * reached from a browser GET.
 *
 * Race-safe: on first use two concurrent writers could each generate a value,
 * so the INSERT keeps the first writer's row (ON DUPLICATE KEY UPDATE
 * value = value, a no-op on conflict) and we read the authoritative row back --
 * both end up with the same secret. A direct db_fetch_one() (not get_variable)
 * is used for the read-back, because get_variable() caches the whole settings
 * table for the process and would not see a row this process just inserted.
 */
function phone_sip_ensure_shared_secret() {
    $existing = get_variable('phone_sip_shared_secret');
    if (is_string($existing) && $existing !== '') { return $existing; }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    // A fresh, uncached read in case get_variable()'s process cache predates a
    // row written earlier in this same request.
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'phone_sip_shared_secret'");
    if ($row && is_string($row['value']) && $row['value'] !== '') { return $row['value']; }

    $secret = bin2hex(random_bytes(32));
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_sip_shared_secret', ?)
         ON DUPLICATE KEY UPDATE `value` = `value`",
        [$secret]
    );
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'phone_sip_shared_secret'");
    if ($row && is_string($row['value']) && $row['value'] !== '') { return $row['value']; }
    return $secret;
}

/**
 * Rotate the shared secret. Every outstanding ephemeral credential becomes
 * cryptographically invalid at once, so the PBX's copy must be updated in the
 * same change. Returns the new secret (server-side callers only -- e.g. the CLI
 * tool; never surfaced to the browser).
 */
function phone_sip_rotate_shared_secret() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $secret = bin2hex(random_bytes(32));
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_sip_shared_secret', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$secret]
    );
    return $secret;
}

/**
 * Mint a short-TTL ephemeral SIP credential for $ext in the TURN-REST-API /
 * RFC-7635 shape (see the block comment above). Deterministic given
 * (secret, username, expiry), so the PBX -- and a test -- can recompute and
 * verify it.
 *
 * @param array|string $ext    a phone_extensions row (uses 'sip_username', then
 *                             'extension'), or a bare username/extension string
 * @param int|null     $ttl    lifetime in seconds (default: phone_sip_cred_ttl())
 * @param int|null     $now    unix time (default: time()) -- injectable for tests
 * @param string|null  $secret override the shared secret -- tests only
 * @return array{sip_username:string, sip_password:string, expires_at:int}
 */
function phone_extension_mint_ephemeral($ext, $ttl = null, $now = null, $secret = null) {
    if (is_array($ext)) {
        $base = (string) ($ext['sip_username'] ?? '');
        if ($base === '') { $base = (string) ($ext['extension'] ?? ''); }
    } else {
        $base = (string) $ext;
    }
    $ttl    = ($ttl === null) ? phone_sip_cred_ttl() : phone_sip_cred_ttl_clamp($ttl);
    $now    = ($now === null) ? time() : (int) $now;
    $secret = ($secret === null) ? phone_sip_shared_secret() : (string) $secret;
    $expiry = $now + $ttl;

    $username = $expiry . ':' . $base;
    $password = base64_encode(hash_hmac('sha256', $username, $secret, true));
    return [
        'sip_username' => $username,
        'sip_password' => $password,
        'expires_at'   => $expiry,
    ];
}

/**
 * Status summary for the CLI tool / tests: mode, ttl, and WHETHER a shared
 * secret exists -- never the secret itself. The secret-set flag is read with a
 * DIRECT, uncached query (not get_variable()), because a secret just lazily
 * generated in THIS process would be stale in get_variable()'s process cache
 * (that cache is populated once, before the lazy INSERT) and report "no".
 */
function phone_sip_ephemeral_status() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $secretSet = false;
    try {
        $row = db_fetch_one(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'phone_sip_shared_secret'"
        );
        $secretSet = $row && is_string($row['value']) && $row['value'] !== '';
    } catch (Exception $e) { /* no settings table yet: treat as unset */ }
    return [
        'mode'              => phone_sip_mode(),
        'cred_ttl'          => phone_sip_cred_ttl(),
        'shared_secret_set' => $secretSet,
    ];
}

/*
 * GH#108 (panel-review 2026-10-20, finding C2) -- emergency-call policy.
 *
 * DECISION (Eric): every extension can dial emergency services BY DEFAULT. The
 * RESTRICTION is the opt-in feature and is OFF by default. FreePBX/Asterisk is
 * where call routing is actually enforced (and where each registered device's
 * outbound caller-ID / dispatchable location is set for the RAY BAUM'S Act), so
 * TicketsCAD only surfaces the POLICY and WARNS -- it does not itself route or
 * block calls.
 *
 * This is why there is no "block emergency numbers" code path anywhere here: the
 * panel review's finding C2 flagged that a block-by-default could be legally
 * wrong in the US (Kari's Law / RAY BAUM'S Act) and differs by country, so the
 * only enforcement boundary is the dial plan on the PBX. The phone widget's note
 * (phone-widget.js) is a courtesy indication for the operator, NEVER a security
 * boundary -- the guide already says any in-page limit is "a guard rail, not a
 * security boundary".
 */

/** Is the emergency-call RESTRICTION turned on? Default OFF (every extension
 *  may dial emergency numbers). Only the literal '1' restricts; an unset/blank
 *  value, and the explicit '0', both mean ALLOWED. '0' is falsy in PHP, so this
 *  deliberately compares the string and never uses `?:` (the same boolean-setting
 *  trap phone_internal_constituents_enabled() documents). */
function phone_restrict_emergency_enabled() {
    return (string) get_variable('phone_restrict_emergency') === '1';
}

function phone_restrict_emergency_save($restrict) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_restrict_emergency', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$restrict ? '1' : '0']
    );
    return (bool) $restrict;
}

/** The shipped default emergency-number set: US 911. Other locales add their own
 *  (112 in the EU, 999 UK, 000 AU, 144 CH ambulance, ...) -- configurable, and
 *  documented in docs/PHONE-TELEPHONY-GUIDE.md. This list is used for the admin
 *  display, the operator's phone-widget note, and the documented dial plan;
 *  FreePBX enforces the actual routing. */
function phone_emergency_numbers_default() {
    return ['911'];
}

/**
 * Parse an admin-supplied emergency-number list into a clean, de-duplicated
 * array of dialable tokens. Pure (no DB) so a test drives it directly. Accepts
 * an array, or a string separated by commas / whitespace / newlines / semicolons.
 * Each number is 2-10 of [0-9*#] (a short code like 911/112/999/000, or a longer
 * star/pound code some PBXes use). Invalid tokens are dropped; returns [] when
 * nothing valid remains (the caller decides whether to fall back to the default).
 */
function phone_emergency_numbers_normalize($input) {
    $tokens = is_array($input) ? $input : preg_split('/[\s,;]+/', (string) $input);
    $out = [];
    foreach ($tokens as $tok) {
        $tok = trim((string) $tok);
        if ($tok === '') { continue; }
        if (!preg_match('/^[0-9*#]{2,10}$/', $tok)) { continue; }
        if (!in_array($tok, $out, true)) { $out[] = $tok; }
    }
    return $out;
}

/** The configured emergency numbers, or the default set when none is stored or
 *  the stored value has nothing valid left in it (never returns an empty list,
 *  so the display/dial-plan guidance always has at least one number). */
function phone_emergency_numbers() {
    $raw = get_variable('phone_emergency_numbers');
    if ($raw === false || $raw === null || trim((string) $raw) === '') {
        return phone_emergency_numbers_default();
    }
    $list = phone_emergency_numbers_normalize($raw);
    return $list ? $list : phone_emergency_numbers_default();
}

function phone_emergency_numbers_save($input) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $list = phone_emergency_numbers_normalize($input);
    if (!$list) {
        throw new InvalidArgumentException(
            'Enter at least one valid emergency number (2-10 of the digits 0-9, * or #, e.g. 911 or 112).'
        );
    }
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_emergency_numbers', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [implode(',', $list)]
    );
    return $list;
}

/**
 * The plain-language warning shown wherever the emergency-call RESTRICTION is
 * surfaced to a human. ONE canonical source so the admin panel and any other
 * surface can never word it differently. This is advisory text; it does not
 * itself block anything.
 */
function phone_emergency_restriction_warning() {
    return 'Restricting emergency calling prevents these extensions from reaching '
         . 'emergency services (for example 911). This is a serious safety risk and may '
         . "violate laws such as Kari's Law in the United States and similar rules "
         . 'elsewhere. Only enable this on an extension that is not a primary means of '
         . 'summoning help.';
}

/**
 * The admin panel's warning banner markup, visible when the restriction is
 * enabled and hidden (d-none, for the admin JS to reveal live) when it is not.
 * The admin page renders this; a test calls it directly -- the same "one
 * function both the UI and the test use" shape the HTTPS-enforcement notice uses,
 * so the warning the operator is shown can never drift from the warning a test
 * asserts. The text is a developer-controlled constant (no user input, no HTML
 * metacharacters beyond a safe apostrophe), so it is embedded directly.
 */
function phone_emergency_policy_banner_html($restrictEnabled) {
    $hidden = $restrictEnabled ? '' : ' d-none';
    return '<div class="alert alert-danger d-flex align-items-start mb-2' . $hidden . '" id="peEmergencyWarning" role="alert">'
         . '<i class="bi bi-exclamation-octagon-fill me-2 fs-5" aria-hidden="true"></i>'
         . '<div><strong>Emergency calling is restricted on this install.</strong> '
         . phone_emergency_restriction_warning()
         . '</div></div>';
}

}
