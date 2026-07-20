<?php
/**
 * NewUI v4.0 - Pure PHP TOTP Implementation
 *
 * RFC 6238 TOTP (Time-Based One-Time Password) using only built-in PHP functions.
 * No Composer dependencies required — uses hash_hmac, pack/unpack, openssl.
 *
 * All functions are stateless — storage is handled by the caller.
 */

// ═══════════════════════════════════════════════════════════════
//  BASE32 ENCODE / DECODE
// ═══════════════════════════════════════════════════════════════

/**
 * Base32 character set (RFC 4648).
 */
define('TOTP_BASE32_CHARS', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567');

/**
 * Encode binary data to Base32 string.
 *
 * @param string $data Raw binary data
 * @return string Base32-encoded string (uppercase, no padding)
 */
function base32_encode($data)
{
    if ($data === '' || $data === null) {
        return '';
    }

    $binary = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $result = '';
    $chunks = str_split($binary, 5);
    foreach ($chunks as $chunk) {
        // Pad last chunk to 5 bits if needed
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $index = bindec($chunk);
        $result .= TOTP_BASE32_CHARS[$index];
    }

    return $result;
}

/**
 * Decode a Base32 string to binary data.
 *
 * @param string $encoded Base32-encoded string
 * @return string Raw binary data
 */
function base32_decode($encoded)
{
    if ($encoded === '' || $encoded === null) {
        return '';
    }

    // Normalize: uppercase, strip padding and whitespace
    $encoded = strtoupper(trim($encoded));
    $encoded = rtrim($encoded, '=');
    $encoded = preg_replace('/[^A-Z2-7]/', '', $encoded);

    if ($encoded === '') {
        return '';
    }

    $binary = '';
    $len = strlen($encoded);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos(TOTP_BASE32_CHARS, $encoded[$i]);
        if ($pos === false) {
            continue; // skip invalid characters
        }
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    // Convert binary string to bytes (groups of 8 bits)
    $result = '';
    $chunks = str_split($binary, 8);
    foreach ($chunks as $chunk) {
        if (strlen($chunk) < 8) {
            break; // discard incomplete trailing bits
        }
        $result .= chr(bindec($chunk));
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════════
//  TOTP CORE FUNCTIONS
// ═══════════════════════════════════════════════════════════════

/**
 * Generate a random Base32-encoded secret.
 *
 * @param int $length Number of random bytes (default 20 = 160-bit for SHA1)
 * @return string Base32-encoded secret
 */
function totp_generate_secret($length = 20)
{
    $bytes = random_bytes($length);
    return base32_encode($bytes);
}

/**
 * Calculate a TOTP code for a given secret and time.
 *
 * Implements RFC 6238 using HMAC-SHA1 (RFC 4226 HOTP with time-based counter).
 *
 * @param string   $secret Base32-encoded secret
 * @param int|null $time   Unix timestamp (null = current time)
 * @param int      $digits Number of digits in the code (default 6)
 * @param int      $period Time step in seconds (default 30)
 * @return string Zero-padded TOTP code
 */
function totp_get_code($secret, $time = null, $digits = 6, $period = 30)
{
    if ($time === null) {
        $time = time();
    }

    // Calculate time counter (number of periods since epoch)
    $counter = (int) floor($time / $period);

    // Pack counter as 8-byte big-endian unsigned 64-bit integer
    $counterBytes = pack('N*', 0, $counter);

    // Decode the base32 secret to raw bytes
    $key = base32_decode($secret);

    // Calculate HMAC-SHA1
    $hash = hash_hmac('sha1', $counterBytes, $key, true);

    // Dynamic truncation (RFC 4226 section 5.4)
    $offset = ord($hash[19]) & 0x0F;
    $code = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
         (ord($hash[$offset + 3]) & 0xFF)
    );

    // Modulo to get desired number of digits
    $code = $code % pow(10, $digits);

    // Zero-pad to requested digit count
    return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Verify a TOTP code with time window tolerance.
 *
 * Checks the code against the current period and +/- $window periods
 * to account for clock skew.
 *
 * @param string $secret Base32-encoded secret
 * @param string $code   Code to verify (6 digits)
 * @param int    $window Number of periods to check before and after (default 1)
 * @param int    $period Time step in seconds (default 30)
 * @return bool True if the code is valid
 */
function totp_verify($secret, $code, $window = 1, $period = 30, $userId = null)
{
    // Normalize: strip spaces, ensure string
    $code = trim((string) $code);

    $now = time();
    $currentCounter = (int) floor($now / $period);

    // Phase 73bb — RFC 6238 §5.2 mandates one-time use. Without this
    // a successfully-used code stayed valid for the full
    // (window*2+1)*30s = 90s slip window, letting a shoulder-surfed
    // or phished code be replayed on a parallel session for up to
    // 90 seconds. Track the highest counter that has redeemed a
    // code per user; reject any code whose counter ≤ that mark.
    if ($userId !== null && function_exists('db_fetch_value')) {
        try {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            $lastUsed = (int) db_fetch_value(
                "SELECT `last_used_counter` FROM `{$prefix}user_tfa`
                  WHERE `user_id` = ? LIMIT 1",
                [(int) $userId]
            );
        } catch (Exception $e) {
            // last_used_counter column may not exist on legacy install.
            // Try to add it once per process so the next call succeeds.
            static $_addedCol = false;
            if (!$_addedCol) {
                $_addedCol = true;
                try {
                    db_query("ALTER TABLE `{$prefix}user_tfa`
                              ADD COLUMN `last_used_counter` BIGINT NULL DEFAULT NULL");
                } catch (Exception $e2) { /* column already exists */ }
            }
            $lastUsed = 0;
        }
    } else {
        $lastUsed = 0;
    }

    for ($i = -$window; $i <= $window; $i++) {
        $checkCounter = $currentCounter + $i;
        if ($lastUsed > 0 && $checkCounter <= $lastUsed) {
            // Already-redeemed code; refuse replay.
            continue;
        }
        $checkTime = $checkCounter * $period;
        $expected = totp_get_code($secret, $checkTime);
        if (hash_equals($expected, $code)) {
            // Record the redemption so future attempts within the
            // window are rejected. Best-effort — if the column /
            // table missing we still return true (caller's recovery
            // is at the auth layer, not here).
            if ($userId !== null && function_exists('db_query')) {
                try {
                    $prefix = $GLOBALS['db_prefix'] ?? '';
                    db_query(
                        "UPDATE `{$prefix}user_tfa`
                            SET `last_used_counter` = ?
                          WHERE `user_id` = ?",
                        [$checkCounter, (int) $userId]
                    );
                } catch (Exception $e) { /* best effort */ }
            }
            return true;
        }
    }

    return false;
}

/**
 * Generate an otpauth:// URI for QR code scanning.
 *
 * Compatible with Google Authenticator, Authy, Microsoft Authenticator, etc.
 *
 * @param string $secret  Base32-encoded secret
 * @param string $account User account identifier (e.g. username or email)
 * @param string $issuer  Service name (default 'TicketsCAD')
 * @return string otpauth:// URI
 */
function totp_get_uri($secret, $account, $issuer = 'TicketsCAD')
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ]);
    return 'otpauth://totp/' . $label . '?' . $params;
}

// ═══════════════════════════════════════════════════════════════
//  BACKUP CODES
// ═══════════════════════════════════════════════════════════════

/**
 * Generate a set of random 8-digit backup codes.
 *
 * @param int $count Number of codes to generate (default 8)
 * @return array Array of 8-digit string codes
 */
function totp_generate_backup_codes($count = 8)
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        // Generate random 8-digit code using cryptographically secure random
        $num = random_int(10000000, 99999999);
        $codes[] = (string) $num;
    }
    return $codes;
}

/**
 * Verify and consume a backup code (one-time use).
 *
 * @param string $code        The backup code to verify
 * @param array  $storedCodes Array of remaining valid backup codes
 * @return array ['valid' => bool, 'remaining' => array] Updated code list if valid
 */
function totp_verify_backup_code($code, $storedCodes)
{
    $code = trim((string) $code);

    // Remove dashes/spaces for flexible input
    $code = preg_replace('/[^0-9]/', '', $code);

    foreach ($storedCodes as $idx => $stored) {
        if (hash_equals(trim($stored), $code)) {
            // Remove the used code
            $remaining = $storedCodes;
            unset($remaining[$idx]);
            return [
                'valid' => true,
                'remaining' => array_values($remaining),
            ];
        }
    }

    return [
        'valid' => false,
        'remaining' => $storedCodes,
    ];
}
