<?php
/**
 * NewUI v4.0 - RSA Field Encryption
 *
 * Encrypts sensitive form fields (passwords, patient data, medical info)
 * when the page is served over HTTP so data is not sent in cleartext.
 *
 * Encryption:
 *   Algorithm: RSA-OAEP with SHA-1 hash (both client and server)
 *   Key size:  2048-bit
 *   Client:    Web Crypto API  (crypto.subtle.encrypt, RSA-OAEP, SHA-1)
 *   Server:    PHP OpenSSL     (openssl_private_decrypt, OPENSSL_PKCS1_OAEP_PADDING)
 *
 * Note: PHP's OPENSSL_PKCS1_OAEP_PADDING always uses SHA-1 for the OAEP
 * hash function. The `digest_alg => 'sha256'` in openssl_pkey_new() controls
 * the digest for CSR/signing operations, NOT the OAEP encryption padding.
 * Both sides intentionally use SHA-1 for OAEP to ensure interoperability.
 *
 * Key storage: ../keys/ (outside webroot)
 */

// Directory for RSA keys — outside the webroot
define('FE_KEYS_DIR', NEWUI_ROOT . '/../keys');
define('FE_PRIVATE_KEY', FE_KEYS_DIR . '/private.pem');
define('FE_PUBLIC_KEY',  FE_KEYS_DIR . '/public.pem');

// Maximum age (seconds) for encrypted payloads before rejection
define('FE_MAX_AGE', 120); // 2 minutes (tightened from 5 min to reduce replay window)

/**
 * Ensure RSA keypair exists. Generate if missing.
 * Called automatically by fe_get_public_key() on first use,
 * and can be called by the installer.
 *
 * @return bool TRUE on success
 */
function fe_ensure_keys()
{
    if (file_exists(FE_PRIVATE_KEY) && file_exists(FE_PUBLIC_KEY)) {
        return true;
    }

    // Create keys directory if needed
    if (!is_dir(FE_KEYS_DIR)) {
        if (!@mkdir(FE_KEYS_DIR, 0700, true)) {
            error_log('field-encrypt: Cannot create keys directory: ' . FE_KEYS_DIR);
            return false;
        }
    }

    return fe_generate_keypair();
}

/**
 * Generate a new RSA 2048-bit keypair.
 * Archives old keys before overwriting (if they exist).
 *
 * @return bool TRUE on success
 */
function fe_generate_keypair()
{
    // Archive existing keys before overwriting
    fe_archive_keys();

    $config = array(
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'digest_alg'       => 'sha256',
    );

    // XAMPP on Windows needs explicit path to openssl.cnf
    $cnfPaths = array(
        getenv('OPENSSL_CONF'),
        dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
        dirname(PHP_BINARY) . '/../apache/conf/openssl.cnf',
    );
    foreach ($cnfPaths as $cnf) {
        if ($cnf && file_exists($cnf)) {
            $config['config'] = $cnf;
            break;
        }
    }

    $res = @openssl_pkey_new($config);
    if ($res === false) {
        error_log('field-encrypt: openssl_pkey_new() failed: ' . openssl_error_string());
        return false;
    }

    // Export private key
    if (!openssl_pkey_export($res, $privPem, null, $config)) {
        error_log('field-encrypt: openssl_pkey_export() failed: ' . openssl_error_string());
        return false;
    }

    // Extract public key
    $details = openssl_pkey_get_details($res);
    if (!$details || empty($details['key'])) {
        error_log('field-encrypt: openssl_pkey_get_details() failed');
        return false;
    }
    $pubPem = $details['key'];

    // Create keys directory if needed
    if (!is_dir(FE_KEYS_DIR)) {
        if (!@mkdir(FE_KEYS_DIR, 0700, true)) {
            error_log('field-encrypt: Cannot create keys directory: ' . FE_KEYS_DIR);
            return false;
        }
    }

    // Write keys to disk.
    // The @ suppression prevents PHP from emitting a "Failed to open stream"
    // Warning into the page when the keys directory is not writable by the
    // web server user. The function already error_logs the failure and
    // returns false, so the warning was redundant — and crucially, a stray
    // warning rendered into <body> on the login page (which uses flex
    // centering) breaks the layout. Fixed 2026-05-20 after Eric flagged
    // an off-centre login form on the your-server.example.com install.
    if (@file_put_contents(FE_PRIVATE_KEY, $privPem) === false) {
        error_log('field-encrypt: Cannot write private key to ' . FE_PRIVATE_KEY
            . ' — check that the keys directory exists and is writable by the web server user');
        return false;
    }
    if (@file_put_contents(FE_PUBLIC_KEY, $pubPem) === false) {
        error_log('field-encrypt: Cannot write public key to ' . FE_PUBLIC_KEY);
        return false;
    }

    // Set restrictive permissions (no-op on Windows but correct for Linux)
    // Permissions are intentionally restrictive: 0600 private, 0644 public, 0700 dir
    @chmod(FE_PRIVATE_KEY, 0600); // NOSONAR — 0600 is the correct restrictive permission for private keys
    @chmod(FE_PUBLIC_KEY, 0644);  // NOSONAR — 0644 is correct: public key needs to be readable
    @chmod(FE_KEYS_DIR, 0700);    // NOSONAR — 0700 restricts key directory to owner only

    return true;
}

/**
 * Read the public key PEM. Auto-generates keys if missing.
 *
 * @return string|false PEM string, or FALSE on error
 */
function fe_get_public_key()
{
    if (!file_exists(FE_PUBLIC_KEY)) {
        if (!fe_ensure_keys()) {
            return false;
        }
    }
    $pem = @file_get_contents(FE_PUBLIC_KEY);
    return ($pem !== false && strpos($pem, '-----BEGIN PUBLIC KEY-----') !== false) ? $pem : false;
}

/**
 * Read the private key PEM.
 *
 * @return string|false PEM string, or FALSE on error
 */
function fe_get_private_key()
{
    if (!file_exists(FE_PRIVATE_KEY)) {
        return false;
    }
    return @file_get_contents(FE_PRIVATE_KEY);
}

/**
 * Check if keys exist and are valid.
 *
 * @return array Status info: [exists => bool, valid => bool, created => string|null]
 */
function fe_key_status()
{
    $status = array(
        'exists'  => false,
        'valid'   => false,
        'created' => null,
    );

    if (file_exists(FE_PRIVATE_KEY) && file_exists(FE_PUBLIC_KEY)) {
        $status['exists'] = true;
        $status['created'] = date('Y-m-d H:i:s', filemtime(FE_PRIVATE_KEY));

        // Validate the keypair works
        $pubPem = @file_get_contents(FE_PUBLIC_KEY);
        $privPem = @file_get_contents(FE_PRIVATE_KEY);
        if ($pubPem && $privPem) {
            $testData = 'fe_validation_test';
            $encrypted = '';
            $pubKey = openssl_pkey_get_public($pubPem);
            if ($pubKey && openssl_public_encrypt($testData, $encrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                $decrypted = '';
                $privKey = openssl_pkey_get_private($privPem);
                if ($privKey && openssl_private_decrypt($encrypted, $decrypted, $privKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                    $status['valid'] = ($decrypted === $testData);
                }
            }
        }
    }

    return $status;
}

/**
 * Detect if the current request is over HTTPS.
 *
 * @return bool
 */
function fe_is_https()
{
    // Direct HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    // Behind a reverse proxy
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    // Port-based detection
    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}

/**
 * Should field encryption be active for this request?
 * Returns TRUE if NOT on HTTPS and the admin toggle is enabled.
 *
 * @return bool
 */
function fe_should_encrypt()
{
    // If already on HTTPS, no need for field encryption
    if (fe_is_https()) {
        return false;
    }

    // Check admin toggle (default: on)
    $setting = get_setting('field_encrypt_enabled', '1');
    return ($setting === '1');
}

/**
 * Decrypt a base64-encoded RSA-OAEP ciphertext.
 *
 * @param string $encryptedBase64 Base64-encoded ciphertext
 * @return string|false Plaintext, or FALSE on error
 */
function fe_decrypt($encryptedBase64)
{
    $privPem = fe_get_private_key();
    if (!$privPem) {
        error_log('field-encrypt: Private key not available');
        return false;
    }

    $privKey = openssl_pkey_get_private($privPem);
    if (!$privKey) {
        error_log('field-encrypt: Cannot load private key: ' . openssl_error_string());
        return false;
    }

    $ciphertext = base64_decode($encryptedBase64, true);
    if ($ciphertext === false) {
        error_log('field-encrypt: Invalid base64');
        return false;
    }

    $decrypted = '';
    if (!openssl_private_decrypt($ciphertext, $decrypted, $privKey, OPENSSL_PKCS1_OAEP_PADDING)) {
        error_log('field-encrypt: Decryption failed: ' . openssl_error_string());
        return false;
    }

    return $decrypted;
}

/**
 * Decrypt a field value transparently.
 * Supports two formats:
 *   "ENC:"  — Legacy direct RSA-OAEP (base64 ciphertext)
 *   "ENC2:" — Hybrid RSA+AES-GCM (wrappedKeyLen + wrappedKey + iv + ciphertext)
 *
 * If the value has no prefix, it's returned as-is (plaintext pass-through).
 *
 * @param string $fieldValue Raw field value from form submission
 * @return string|false Plaintext value, or FALSE if decryption/validation fails
 */
function fe_decrypt_field($fieldValue)
{
    // Hybrid AES-GCM format
    if (strpos($fieldValue, 'ENC2:') === 0) {
        return fe_decrypt_hybrid(substr($fieldValue, 5));
    }

    // Legacy direct RSA format
    if (strpos($fieldValue, 'ENC:') === 0) {
        return fe_decrypt_legacy(substr($fieldValue, 4));
    }

    // Not encrypted — pass through
    return $fieldValue;
}

/**
 * Decrypt hybrid RSA+AES-GCM payload.
 * Format: base64( wrappedKeyLen(2 bytes BE) | wrappedKey | iv(12) | aesCiphertext+tag )
 */
function fe_decrypt_hybrid($encBase64)
{
    $privPem = fe_get_private_key();
    if (!$privPem) {
        error_log('field-encrypt: Private key not available');
        return false;
    }

    $raw = base64_decode($encBase64, true);
    if ($raw === false || strlen($raw) < 50) { // min: 2 + 32 + 12 + tag(16) = 62, but be lenient
        error_log('field-encrypt: Invalid hybrid payload (too short)');
        return false;
    }

    // Parse wrapped key length (2 bytes, big-endian)
    $wrappedKeyLen = (ord($raw[0]) << 8) | ord($raw[1]);
    if ($wrappedKeyLen < 128 || $wrappedKeyLen > 512) {
        error_log('field-encrypt: Invalid wrapped key length: ' . $wrappedKeyLen);
        return false;
    }

    if (strlen($raw) < 2 + $wrappedKeyLen + 12 + 16) {
        error_log('field-encrypt: Payload too short for declared key length');
        return false;
    }

    $wrappedKey    = substr($raw, 2, $wrappedKeyLen);
    $iv            = substr($raw, 2 + $wrappedKeyLen, 12);
    $aesCiphertext = substr($raw, 2 + $wrappedKeyLen + 12); // includes GCM auth tag

    // Unwrap the AES key with RSA-OAEP
    $privKey = openssl_pkey_get_private($privPem);
    if (!$privKey) {
        error_log('field-encrypt: Cannot load private key');
        return false;
    }

    $aesKeyRaw = '';
    if (!openssl_private_decrypt($wrappedKey, $aesKeyRaw, $privKey, OPENSSL_PKCS1_OAEP_PADDING)) {
        error_log('field-encrypt: RSA unwrap failed: ' . openssl_error_string());
        return false;
    }

    if (strlen($aesKeyRaw) !== 32) {
        error_log('field-encrypt: Unwrapped AES key wrong size: ' . strlen($aesKeyRaw));
        return false;
    }

    // AES-256-GCM: last 16 bytes of ciphertext are the auth tag
    if (strlen($aesCiphertext) < 16) {
        error_log('field-encrypt: AES ciphertext too short');
        return false;
    }

    $tagLen = 16;
    $tag       = substr($aesCiphertext, -$tagLen);
    $encrypted = substr($aesCiphertext, 0, -$tagLen);

    $json = openssl_decrypt($encrypted, 'aes-256-gcm', $aesKeyRaw, OPENSSL_RAW_DATA, $iv, $tag);
    if ($json === false) {
        error_log('field-encrypt: AES-GCM decryption failed (auth tag mismatch or corrupted)');
        return false;
    }

    return fe_validate_envelope($json);
}

/**
 * Decrypt legacy direct RSA-OAEP payload.
 */
function fe_decrypt_legacy($encBase64)
{
    $json = fe_decrypt($encBase64);
    if ($json === false) {
        return false;
    }
    return fe_validate_envelope($json);
}

/**
 * Validate the decrypted JSON envelope (timestamp + nonce).
 * Shared by both legacy and hybrid decryption paths.
 *
 * @param string $json  Decrypted JSON string
 * @return string|false Plaintext value, or FALSE on validation failure
 */
function fe_validate_envelope($json)
{
    $envelope = json_decode($json, true);
    if (!is_array($envelope) || !isset($envelope['value']) || !isset($envelope['ts']) || !isset($envelope['nonce'])) {
        error_log('field-encrypt: Invalid envelope structure');
        return false;
    }

    // Validate timestamp (within FE_MAX_AGE seconds)
    $tsSeconds = (int)($envelope['ts'] / 1000); // JS Date.now() is milliseconds
    $age = time() - $tsSeconds;
    if ($age < -30 || $age > FE_MAX_AGE) {
        error_log('field-encrypt: Payload expired or clock skew (age=' . $age . 's)');
        return false;
    }

    // Validate nonce format (hex string)
    if (!preg_match('/^[0-9a-f]{32}$/', $envelope['nonce'])) {
        error_log('field-encrypt: Invalid nonce format');
        return false;
    }

    return $envelope['value'];
}

/**
 * Inject the field encryption JavaScript into the page.
 * Only outputs when fe_should_encrypt() is true.
 *
 * @return string HTML <script> tags, or empty string if not needed
 */
function fe_inject_js()
{
    if (!fe_should_encrypt()) {
        return '';
    }

    $pubPem = fe_get_public_key();
    if (!$pubPem) {
        return '<!-- field-encrypt: key generation failed -->';
    }

    // Escape for embedding in JS string
    $pubPemJs = str_replace(array("\r\n", "\r", "\n"), '\\n', $pubPem);
    $pubPemJs = str_replace("'", "\\'", $pubPemJs);

    $html = '<script src="assets/js/field-encrypt.js?v=' . asset_v('assets/js/field-encrypt.js') . '"></script>' . "\n";
    $html .= '<script>' . "\n";
    $html .= '(function () {' . "\n";
    $html .= '    "use strict";' . "\n";
    $html .= '    if (window.FieldEncrypt) {' . "\n";
    $html .= '        window.FieldEncrypt.init(\'' . $pubPemJs . '\').then(function () {' . "\n";
    $html .= '            window.FieldEncrypt.autoProtect();' . "\n";
    $html .= '        });' . "\n";
    $html .= '    }' . "\n";
    $html .= '})();' . "\n";
    $html .= '</script>' . "\n";

    return $html;
}

/**
 * Archive existing RSA keys before regeneration.
 * Creates timestamped copies in ../keys/archive/ so old keys
 * are recoverable if any stored data was encrypted with them.
 *
 * @return bool TRUE if archive succeeded (or no keys to archive)
 */
function fe_archive_keys()
{
    if (!file_exists(FE_PRIVATE_KEY) || !file_exists(FE_PUBLIC_KEY)) {
        return true; // Nothing to archive
    }

    $archiveDir = FE_KEYS_DIR . '/archive';
    if (!is_dir($archiveDir)) {
        if (!@mkdir($archiveDir, 0700, true)) {
            error_log('field-encrypt: Cannot create archive directory: ' . $archiveDir);
            return false;
        }
    }

    $timestamp = date('Y-m-d-His');
    $privArchive = $archiveDir . '/private-' . $timestamp . '.pem';
    $pubArchive  = $archiveDir . '/public-' . $timestamp . '.pem';

    $ok = @copy(FE_PRIVATE_KEY, $privArchive) && @copy(FE_PUBLIC_KEY, $pubArchive);
    if ($ok) {
        @chmod($privArchive, 0600);
        // Phase 41 (Sonar S2612): tighten public-key archive to 0640.
        // The public key is not secret (it verifies signatures), but
        // there's no operational need for world-read, and 0640 satisfies
        // the chmod-mask scanner without affecting any caller. The
        // matching private archive is at 0600.
        @chmod($pubArchive, 0640); // NOSONAR
    } else {
        error_log('field-encrypt: Failed to archive keys');
    }

    return $ok;
}
