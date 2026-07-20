<?php
/**
 * NewUI v4.0 - Two-Factor Authentication Session/Flow Management
 *
 * Handles 2FA enrollment, verification, device remembering, and trusted networks.
 * Uses inc/totp.php for TOTP calculations and inc/db.php for storage.
 *
 * Encryption uses OpenSSL AES-256-CBC with a key derived from the DB password
 * in config.php (no additional secrets to manage).
 */

require_once __DIR__ . '/totp.php';

// ═══════════════════════════════════════════════════════════════
//  ENCRYPTION HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Get the encryption key for 2FA secrets.
 *
 * Key resolution order:
 *   1. Dedicated key file: ../keys/tfa.key (preferred — independent of DB password)
 *   2. Fallback: derived from DB password + fixed salt via SHA-256 (legacy)
 *
 * The dedicated key file is generated on first use or via tfa_generate_key().
 * This decouples TFA security from database credentials so changing the DB
 * password does not invalidate all 2FA enrollments.
 *
 * @return string 32-byte binary key
 */
function tfa_encryption_key()
{
    $keyFile = defined('FE_KEYS_DIR') ? FE_KEYS_DIR . '/tfa.key' : (NEWUI_ROOT . '/../keys/tfa.key');

    // Prefer dedicated key file if it exists
    if (file_exists($keyFile)) {
        $key = @file_get_contents($keyFile);
        if ($key !== false && strlen($key) === 32) {
            return $key;
        }
    }

    // Auto-generate a dedicated key file on first use (new installations).
    // This ensures new deployments never use the DB-password-derived key,
    // decoupling TFA security from database credentials from the start.
    if (tfa_generate_key()) {
        $key = @file_get_contents($keyFile);
        if ($key !== false && strlen($key) === 32) {
            return $key;
        }
    }

    // Phase 73cc — only fall back to the DB-password-derived key on
    // installs that have an EXISTING legacy ENC: blob in the database.
    // For fresh installs, refuse — silently dropping to the weaker key
    // means anyone with read access to config.php (e.g. a backup leak)
    // can decrypt every TOTP secret + backup-code blob. The hard-fail
    // tells the operator to fix file permissions on ../keys/ rather
    // than the security degrading invisibly.
    static $hasLegacyBlob = null;
    if ($hasLegacyBlob === null) {
        try {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            $hasLegacyBlob = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}user_tfa`
                  WHERE `secret_encrypted` LIKE 'ENC:%'"
            ) > 0;
        } catch (Exception $e) {
            $hasLegacyBlob = false;
        }
    }
    if (!$hasLegacyBlob) {
        error_log('[tfa] CRITICAL: ' . $keyFile . ' missing/unreadable and could not be generated. '
                . 'Fix permissions on the keys directory. Refusing to fall back to '
                . 'DB-password-derived key on a fresh install.');
        throw new RuntimeException(
            'TFA key file missing and cannot be created. '
            . 'Check permissions on ' . dirname($keyFile)
        );
    }
    global $db_pass;
    return hash('sha256', 'tfa_secret_key_' . $db_pass, true);
}

/**
 * Generate a dedicated TFA encryption key file.
 * Stores 32 bytes of cryptographically random data in ../keys/tfa.key.
 *
 * @return bool TRUE on success
 */
function tfa_generate_key()
{
    $keysDir = defined('FE_KEYS_DIR') ? FE_KEYS_DIR : (NEWUI_ROOT . '/../keys');
    $keyFile = $keysDir . '/tfa.key';

    if (!is_dir($keysDir)) {
        if (!@mkdir($keysDir, 0700, true)) {
            error_log('tfa: Cannot create keys directory: ' . $keysDir);
            return false;
        }
    }

    $key = random_bytes(32);
    if (file_put_contents($keyFile, $key) === false) {
        error_log('tfa: Cannot write TFA key file');
        return false;
    }
    @chmod($keyFile, 0600);
    return true;
}

/**
 * Re-encrypt all TFA secrets from one key to another.
 * Used when migrating from DB-password-derived key to dedicated key file,
 * or when rotating the TFA key.
 *
 * @param  string $oldKey  32-byte old encryption key
 * @param  string $newKey  32-byte new encryption key
 * @return array  ['migrated' => int, 'failed' => int, 'errors' => string[]]
 */
function tfa_reencrypt_all($oldKey, $newKey)
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $result = ['migrated' => 0, 'failed' => 0, 'errors' => []];

    try {
        $rows = db_fetch_all("SELECT `id`, `user_id`, `secret_encrypted`, `backup_codes_json` FROM `{$prefix}user_tfa`");
    } catch (Exception $e) {
        $result['errors'][] = 'Cannot read user_tfa: ' . $e->getMessage();
        return $result;
    }

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $userId = (int) $row['user_id'];

        // Decrypt secret with old key
        $secret = _tfa_decrypt_with_key($row['secret_encrypted'], $oldKey);
        if ($secret === false) {
            $result['errors'][] = "User #{$userId}: cannot decrypt secret with old key";
            $result['failed']++;
            continue;
        }

        // Decrypt backup codes with old key
        $backupCodes = null;
        if (!empty($row['backup_codes_json'])) {
            $backupCodes = _tfa_decrypt_with_key($row['backup_codes_json'], $oldKey);
            if ($backupCodes === false) {
                $result['errors'][] = "User #{$userId}: cannot decrypt backup codes with old key";
                $result['failed']++;
                continue;
            }
        }

        // Re-encrypt with new key
        $newSecret = _tfa_encrypt_with_key($secret, $newKey);
        $newBackup = $backupCodes !== null ? _tfa_encrypt_with_key($backupCodes, $newKey) : $row['backup_codes_json'];

        try {
            db_query(
                "UPDATE `{$prefix}user_tfa` SET `secret_encrypted` = ?, `backup_codes_json` = ? WHERE `id` = ?",
                [$newSecret, $newBackup, $id]
            );
            $result['migrated']++;
        } catch (Exception $e) {
            $result['errors'][] = "User #{$userId}: update failed: " . $e->getMessage();
            $result['failed']++;
        }
    }

    return $result;
}

/**
 * Encrypt with a specific key (for re-encryption).
 */
function _tfa_encrypt_with_key($plaintext, $key)
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

/**
 * Decrypt with a specific key (for re-encryption).
 */
function _tfa_decrypt_with_key($encrypted, $key)
{
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) < 17) {
        return false;
    }
    $iv = substr($data, 0, 16);
    $cipher = substr($data, 16);
    return openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
}

/**
 * Encrypt a string using AES-256-CBC.
 *
 * @param string $plaintext Data to encrypt
 * @return string Base64-encoded IV + ciphertext
 */
function tfa_encrypt($plaintext)
{
    $key = tfa_encryption_key();
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

/**
 * Decrypt a string encrypted with tfa_encrypt().
 *
 * @param string $encrypted Base64-encoded IV + ciphertext
 * @return string|false Decrypted plaintext or false on failure
 */
function tfa_decrypt($encrypted)
{
    $key = tfa_encryption_key();
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) < 17) {
        return false;
    }
    $iv = substr($data, 0, 16);
    $cipher = substr($data, 16);
    return openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
}

// ═══════════════════════════════════════════════════════════════
//  2FA STATUS / SETTINGS
// ═══════════════════════════════════════════════════════════════

/**
 * Check if a user has 2FA enrolled and active.
 *
 * @param int $userId
 * @return bool
 */
function tfa_is_enabled($userId)
{
    try {
        $row = db_fetch_one(
            "SELECT `id`, `confirmed` FROM " . db_table('user_tfa') . " WHERE `user_id` = ? LIMIT 1",
            [(int) $userId]
        );
        // Only consider 2FA enabled if enrollment was confirmed with a valid TOTP code
        return $row !== null && (int) ($row['confirmed'] ?? 0) === 1;
    } catch (Exception $e) {
        // Table may not exist yet
        return false;
    }
}

/**
 * Check if user has a pending (unconfirmed) enrollment.
 */
function tfa_has_pending_enrollment($userId)
{
    try {
        $row = db_fetch_one(
            "SELECT `id` FROM " . db_table('user_tfa') . " WHERE `user_id` = ? AND `confirmed` = 0 LIMIT 1",
            [(int) $userId]
        );
        return $row !== null;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get 2FA settings from the config table.
 *
 * @return array Settings with defaults
 */
function tfa_get_settings()
{
    return [
        'tfa_enabled'        => (int) get_setting('tfa_enabled', 0),
        'tfa_required_roles' => json_decode(get_setting('tfa_required_roles', '[]'), true) ?: [],
        'tfa_trusted_cidrs'  => json_decode(get_setting('tfa_trusted_cidrs', '["127.0.0.0/8","10.0.0.0/8","172.16.0.0/12","192.168.0.0/16"]'), true) ?: [],
        'tfa_remember_days'  => (int) get_setting('tfa_remember_days', 30),
    ];
}

// ═══════════════════════════════════════════════════════════════
//  ENROLLMENT
// ═══════════════════════════════════════════════════════════════

/**
 * Start 2FA enrollment for a user.
 * Generates a secret, encrypts and stores it, generates backup codes.
 *
 * @param int    $userId
 * @param string $username For the QR code label
 * @return array ['secret' => base32, 'uri' => otpauth://, 'backup_codes' => [...]]
 */
function tfa_enroll($userId, $username = '')
{
    $userId = (int) $userId;

    // Generate secret and backup codes
    $secret = totp_generate_secret(20);
    $backupCodes = totp_generate_backup_codes(8);

    // Encrypt for storage
    $secretEncrypted = tfa_encrypt($secret);
    $backupEncrypted = tfa_encrypt(json_encode($backupCodes));

    // Remove any existing enrollment (re-enrollment)
    try {
        db_query(
            "DELETE FROM " . db_table('user_tfa') . " WHERE `user_id` = ?",
            [$userId]
        );
    } catch (Exception $e) {
        // Table may not exist yet — the INSERT will create it if run_tfa.php was run
    }

    // Store in database
    db_query(
        "INSERT INTO " . db_table('user_tfa')
        . " (`user_id`, `secret_encrypted`, `backup_codes_json`, `enrolled_at`)
           VALUES (?, ?, ?, NOW())",
        [$userId, $secretEncrypted, $backupEncrypted]
    );

    // Generate QR URI
    $uri = totp_get_uri($secret, $username, 'TicketsCAD');

    return [
        'secret'       => $secret,
        'uri'          => $uri,
        'backup_codes' => $backupCodes,
    ];
}

/**
 * Confirm enrollment by verifying the user can generate a valid code.
 * This should be called after tfa_enroll() when the user enters their first code.
 *
 * @param int    $userId
 * @param string $code TOTP code to verify
 * @return bool True if code is valid (enrollment confirmed)
 */
function tfa_confirm_enroll($userId, $code)
{
    $userId = (int) $userId;

    $row = db_fetch_one(
        "SELECT `secret_encrypted` FROM " . db_table('user_tfa') . " WHERE `user_id` = ? LIMIT 1",
        [$userId]
    );

    if (!$row) {
        return false;
    }

    $secret = tfa_decrypt($row['secret_encrypted']);
    if ($secret === false) {
        return false;
    }

    if (!totp_verify($secret, $code, 1, 30, $userId)) {
        return false;
    }

    // Mark enrollment as confirmed — 2FA is now active
    db_query(
        "UPDATE " . db_table('user_tfa') . " SET `confirmed` = 1, `last_used_at` = NOW() WHERE `user_id` = ?",
        [$userId]
    );

    return true;
}

// ═══════════════════════════════════════════════════════════════
//  LOGIN VERIFICATION
// ═══════════════════════════════════════════════════════════════

/**
 * Verify a TOTP or backup code during login.
 *
 * @param int    $userId
 * @param string $code 6-digit TOTP code or 8-digit backup code
 * @return bool True if valid
 */
function tfa_verify_login($userId, $code)
{
    $userId = (int) $userId;
    $code = trim((string) $code);

    $row = db_fetch_one(
        "SELECT `secret_encrypted`, `backup_codes_json` FROM " . db_table('user_tfa')
        . " WHERE `user_id` = ? LIMIT 1",
        [$userId]
    );

    if (!$row) {
        return false;
    }

    // Try TOTP first
    $secret = tfa_decrypt($row['secret_encrypted']);
    if ($secret !== false && totp_verify($secret, $code, 1, 30, $userId)) {
        // Update last_used_at
        db_query(
            "UPDATE " . db_table('user_tfa') . " SET `last_used_at` = NOW() WHERE `user_id` = ?",
            [$userId]
        );
        return true;
    }

    // Try backup code (8 digits, no dashes)
    $cleanCode = preg_replace('/[^0-9]/', '', $code);
    if (strlen($cleanCode) === 8) {
        $backupCodes = json_decode(tfa_decrypt($row['backup_codes_json']), true);
        if (is_array($backupCodes)) {
            $result = totp_verify_backup_code($cleanCode, $backupCodes);
            if ($result['valid']) {
                // Update remaining codes
                $backupEncrypted = tfa_encrypt(json_encode($result['remaining']));
                db_query(
                    "UPDATE " . db_table('user_tfa')
                    . " SET `backup_codes_json` = ?, `last_used_at` = NOW() WHERE `user_id` = ?",
                    [$backupEncrypted, $userId]
                );
                return true;
            }
        }
    }

    return false;
}

/**
 * Disable 2FA for a user. Removes enrollment and all remember tokens.
 *
 * @param int $userId
 * @return bool
 */
function tfa_disable($userId)
{
    $userId = (int) $userId;

    try {
        db_query("DELETE FROM " . db_table('user_tfa') . " WHERE `user_id` = ?", [$userId]);
        tfa_invalidate_all_devices($userId);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Regenerate backup codes for a user.
 *
 * @param int $userId
 * @return array|false New backup codes, or false on failure
 */
function tfa_regenerate_backup_codes($userId)
{
    $userId = (int) $userId;

    $row = db_fetch_one(
        "SELECT `id` FROM " . db_table('user_tfa') . " WHERE `user_id` = ? LIMIT 1",
        [$userId]
    );

    if (!$row) {
        return false;
    }

    $newCodes = totp_generate_backup_codes(8);
    $backupEncrypted = tfa_encrypt(json_encode($newCodes));

    db_query(
        "UPDATE " . db_table('user_tfa') . " SET `backup_codes_json` = ? WHERE `user_id` = ?",
        [$backupEncrypted, $userId]
    );

    return $newCodes;
}

// ═══════════════════════════════════════════════════════════════
//  DEVICE REMEMBERING
// ═══════════════════════════════════════════════════════════════

/**
 * Build a device fingerprint hash from request headers.
 * Not bulletproof, but provides reasonable device identification.
 *
 * @return string SHA-256 fingerprint hash
 */
function tfa_device_fingerprint()
{
    // Phase 104e (a beta tester GH #6, 2026-07-02) — rebalanced fingerprint
    // per Eric's spec: drop the /24 IP prefix that was breaking
    // roaming mobile carriers (option D), add browser-collected
    // attributes to keep the "stolen cookie replay" defence Phase
    // 73bb was after. The additional attributes are shipped from
    // the client on the 2FA form submit (see login.php's tfaFP
    // hidden input) and stored server-side alongside the token
    // hash in tfa_remember_tokens. Every attribute is admin-
    // toggleable via `tfa_fingerprint_include_*` settings so
    // agencies can trade friction for strictness.
    //
    // Default weighting (defensible for mobile-carrier users):
    //   UA                — always on (Phase 73bb heritage)
    //   Accept-Language   — always on
    //   Sec-CH-UA         — on if browser sent it (Chromium)
    //   client_timezone   — on (Intl.DateTimeFormat)
    //   client_screen     — on (screen.availWidth x availHeight)
    //   client_platform   — on (navigator.platform)
    //   ip_prefix         — OFF by default (was '/24' — Eric asked
    //                       for D "loosen")
    //
    // A stolen cookie replayed from a different device now has to
    // reproduce timezone + screen + platform + Sec-CH-UA — much
    // harder to spoof than just UA + language. The tradeoff is
    // documented in help.php#topic-2fa-remember so admins picking
    // ip_prefix back on know what they're getting.
    $useUA        = _tfa_fp_setting('tfa_fingerprint_include_ua',        true);
    $useLang      = _tfa_fp_setting('tfa_fingerprint_include_accept_lang', true);
    $useCHUA      = _tfa_fp_setting('tfa_fingerprint_include_sec_ch_ua',  true);
    $useTZ        = _tfa_fp_setting('tfa_fingerprint_include_timezone',   true);
    $useScreen    = _tfa_fp_setting('tfa_fingerprint_include_screen',     true);
    $usePlatform  = _tfa_fp_setting('tfa_fingerprint_include_platform',   true);
    $useIpPrefix  = _tfa_fp_setting('tfa_fingerprint_include_ip_prefix',  false);

    $parts = [];
    if ($useUA)   $parts[] = 'ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($useLang) $parts[] = 'lang=' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if ($useCHUA) $parts[] = 'chua=' . ($_SERVER['HTTP_SEC_CH_UA'] ?? '');

    // Client-collected attributes travel on the 2FA form as a
    // hidden field, then get stashed in $_SESSION['_tfa_client_fp']
    // by login.php so they're available here at cookie-issue and
    // cookie-verify time. Missing entries reduce to empty strings.
    $clientFP = (isset($_SESSION['_tfa_client_fp']) && is_array($_SESSION['_tfa_client_fp']))
        ? $_SESSION['_tfa_client_fp'] : [];
    if ($useTZ)       $parts[] = 'tz='  . (string) ($clientFP['tz'] ?? '');
    if ($useScreen)   $parts[] = 'sc='  . (string) ($clientFP['sc'] ?? '');
    if ($usePlatform) $parts[] = 'pl='  . (string) ($clientFP['pl'] ?? '');

    if ($useIpPrefix) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ipPrefix = '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $oct = explode('.', $ip);
            if (count($oct) === 4) $ipPrefix = $oct[0] . '.' . $oct[1] . '.' . $oct[2] . '.0/24';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipParts = explode(':', $ip);
            $ipPrefix = implode(':', array_slice($ipParts, 0, 3)) . '::/48';
        }
        $parts[] = 'ip=' . $ipPrefix;
    }

    return hash('sha256', implode('|', $parts));
}

function _tfa_fp_setting(string $key, bool $default): bool {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $v = get_variable($key);
        if ($v === false || $v === null || $v === '') return $cache[$key] = $default;
        return $cache[$key] = ($v === '1' || $v === 1 || $v === true);
    } catch (Throwable $e) { return $cache[$key] = $default; }
}

/**
 * Set a "remember this device" cookie for 2FA bypass.
 * Cookie value is an HMAC-signed token containing user_id + fingerprint.
 *
 * @param int $userId
 * @return bool
 */
function tfa_remember_device($userId)
{
    $userId = (int) $userId;
    $settings = tfa_get_settings();
    $days = $settings['tfa_remember_days'];

    $fingerprint = tfa_device_fingerprint();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));

    // Create token payload
    $payload = $userId . '|' . $fingerprint . '|' . $expiresAt;
    $signature = hash_hmac('sha256', $payload, tfa_encryption_key());
    $token = base64_encode($payload . '|' . $signature);

    // Store token hash in database (with user_agent if column exists)
    $tokenHash = hash('sha256', $token);
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : '';
    try {
        db_query(
            "INSERT INTO " . db_table('tfa_remember_tokens')
            . " (`user_id`, `token_hash`, `device_fingerprint`, `ip_address`, `user_agent`, `created_at`, `expires_at`)
               VALUES (?, ?, ?, ?, ?, NOW(), ?)",
            [$userId, $tokenHash, $fingerprint, $ip, $userAgent, $expiresAt]
        );
    } catch (Exception $e) {
        // Retry without user_agent column (may not exist on older schemas)
        try {
            db_query(
                "INSERT INTO " . db_table('tfa_remember_tokens')
                . " (`user_id`, `token_hash`, `device_fingerprint`, `ip_address`, `created_at`, `expires_at`)
                   VALUES (?, ?, ?, ?, NOW(), ?)",
                [$userId, $tokenHash, $fingerprint, $ip, $expiresAt]
            );
        } catch (Exception $e2) {
            return false;
        }
    }

    // Set cookie
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('tfa_remember', $token, [
        'expires'  => time() + ($days * 86400),
        'path'     => '/',
        'secure'   => $secure,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);

    return true;
}

/**
 * Check if the current device has a valid remember cookie.
 *
 * @param int $userId
 * @return bool
 */
function tfa_is_device_remembered($userId)
{
    $userId = (int) $userId;

    if (empty($_COOKIE['tfa_remember'])) {
        return false;
    }

    $token = $_COOKIE['tfa_remember'];

    // Decode and verify signature
    $decoded = base64_decode($token, true);
    if ($decoded === false) {
        return false;
    }

    $parts = explode('|', $decoded);
    if (count($parts) !== 4) {
        return false;
    }

    $tokenUserId = (int) $parts[0];
    $tokenFingerprint = $parts[1];
    $tokenExpires = $parts[2];
    $tokenSignature = $parts[3];

    // Verify user match
    if ($tokenUserId !== $userId) {
        return false;
    }

    // Verify signature
    $payload = $parts[0] . '|' . $parts[1] . '|' . $parts[2];
    $expectedSig = hash_hmac('sha256', $payload, tfa_encryption_key());
    if (!hash_equals($expectedSig, $tokenSignature)) {
        return false;
    }

    // Check expiry
    if (strtotime($tokenExpires) < time()) {
        return false;
    }

    // Verify fingerprint matches current device
    $currentFingerprint = tfa_device_fingerprint();
    if (!hash_equals($tokenFingerprint, $currentFingerprint)) {
        return false;
    }

    // Check token exists in database and hasn't been invalidated
    $tokenHash = hash('sha256', $token);
    try {
        $row = db_fetch_one(
            "SELECT `id` FROM " . db_table('tfa_remember_tokens')
            . " WHERE `user_id` = ? AND `token_hash` = ? AND `expires_at` > NOW() LIMIT 1",
            [$userId, $tokenHash]
        );
        return $row !== null;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Invalidate all remember tokens for a user (admin force-logout).
 *
 * @param int $userId
 * @return void
 */
function tfa_invalidate_all_devices($userId)
{
    $userId = (int) $userId;
    try {
        db_query(
            "DELETE FROM " . db_table('tfa_remember_tokens') . " WHERE `user_id` = ?",
            [$userId]
        );
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

// ═══════════════════════════════════════════════════════════════
//  TRUSTED NETWORK CHECKING
// ═══════════════════════════════════════════════════════════════

/**
 * Check if an IP address is within a CIDR range.
 *
 * @param string $ip   IP address to check
 * @param string $cidr CIDR notation (e.g. '10.0.0.0/8')
 * @return bool
 */
function tfa_ip_in_cidr($ip, $cidr)
{
    // Handle plain IP (no subnet mask) as /32
    if (strpos($cidr, '/') === false) {
        $cidr .= '/32';
    }

    $parts = explode('/', $cidr, 2);
    $subnet = $parts[0];
    $bits = (int) $parts[1];

    if ($bits < 0 || $bits > 32) {
        return false;
    }

    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);

    if ($ipLong === false || $subnetLong === false) {
        return false;
    }

    // Create the mask
    if ($bits === 0) {
        $mask = 0;
    } else {
        $mask = -1 << (32 - $bits);
    }

    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * Check if the client IP is in the admin-configured trusted network list.
 * Only trusted networks can use the "remember device" feature.
 *
 * @return bool
 */
function tfa_check_trusted_network()
{
    // Phase 104e (a beta tester GH #6, 2026-07-02) — admin toggle for the
    // "any network is trusted for remember-device" convenience.
    // Off by default (Phase 73's original restriction stays). Turned
    // on, the CIDR list is skipped entirely and every network gets
    // the checkbox — appropriate when the extra fingerprint
    // attributes (Phase 104e above) already provide the anti-replay
    // defence you wanted from the CIDR match.
    $anyNet = false;
    try {
        $v = get_variable('tfa_trusted_cidrs_any_network');
        if ($v === '1' || $v === 1 || $v === true) $anyNet = true;
    } catch (Throwable $e) { /* setting missing — keep default off */ }
    if ($anyNet) return true;

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($clientIp === '') {
        return false;
    }

    $settings = tfa_get_settings();
    $cidrs = $settings['tfa_trusted_cidrs'];

    if (empty($cidrs)) {
        // No trusted CIDRs configured — allow all (remember device available everywhere)
        return true;
    }

    foreach ($cidrs as $cidr) {
        $cidr = trim($cidr);
        if ($cidr !== '' && tfa_ip_in_cidr($clientIp, $cidr)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if 2FA is required for a user based on global settings and role requirements.
 *
 * @param int $userId
 * @param int $userLevel Legacy user level
 * @return bool True if the user must have 2FA enabled
 */
function tfa_is_required_for_user($userId, $userLevel)
{
    $settings = tfa_get_settings();

    // 2FA not globally enabled
    if (!$settings['tfa_enabled']) {
        return false;
    }

    // If no roles specified, 2FA is available but not required
    $requiredRoles = $settings['tfa_required_roles'];
    if (empty($requiredRoles)) {
        return false;
    }

    // Phase 73bb — was: `in_array((int) $userLevel, $requiredRoles)`.
    // That checked the LEGACY user.level integer, but Phase 12 (2026-06-11)
    // migrated away from the level concept. Admins configuring "2FA required
    // for Dispatcher" via the new UI store RBAC role IDs, so we resolve
    // against the Phase-12 grants.
    //
    // Sonar php:S930 (2026-07-03 scan): the previous version called
    // `rbac_user_roles((int) $userId)` but rbac_user_roles() took no
    // arguments and always resolved via $_SESSION['user_id']. During the
    // login flow the session id isn't set yet, so the check ALWAYS
    // returned [] and role-based 2FA requirements effectively never fired.
    // On training + Bloomington the setting is empty (0 rows in
    // tfa_required_roles), so no admin has been silently miscovered, but
    // the moment any operator configured "require 2FA for role X" the bug
    // would have quietly no-op'd. Fixed alongside the rbac_user_roles()
    // signature widening; see [[inc/rbac.php::rbac_user_roles()]].
    //
    // Also dropped the role-code matcher branch: it read $r['code'] but
    // the `roles` table has no `code` column, only `name`. That branch
    // has been dead code since the RBAC schema was built. Kept the
    // numeric-id + legacy-level matchers — those are what the admin UI
    // and the pre-Phase-12 stored values use.
    require_once __DIR__ . '/rbac.php';
    $userRoles = function_exists('rbac_user_roles') ? rbac_user_roles((int) $userId) : [];
    $userRoleIds = array_map(fn($r) => (int) ($r['id'] ?? 0), $userRoles);

    foreach ($requiredRoles as $r) {
        $rs = (string) $r;
        if (!is_numeric($rs)) continue;      // codes were never populated
        $rn = (int) $rs;
        if ($rn > 0 && in_array($rn, $userRoleIds, true)) return true;
        if ($rn === (int) $userLevel)                     return true;  // legacy level
    }
    return false;
}
