<?php
/**
 * NewUI v4.0 API — TFA Encryption Key Management
 *
 * GET  /api/tfa-key.php           — key status
 * POST action=migrate             — migrate from DB-derived to dedicated key
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/tfa.php';
require_once __DIR__ . '/../inc/totp.php';

ini_set('display_errors', '0');

if (!is_admin()) {
    json_error('Admin access required', 403);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$keysDir = defined('FE_KEYS_DIR') ? FE_KEYS_DIR : (NEWUI_ROOT . '/../keys');
$keyFile = $keysDir . '/tfa.key';

if ($method === 'GET') {
    $hasDedicatedKey = file_exists($keyFile);

    $enrollments = 0;
    try {
        $enrollments = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user_tfa` WHERE `confirmed` = 1"
        );
    } catch (Exception $e) {}

    json_response([
        'key_source'     => $hasDedicatedKey ? 'dedicated_file' : 'db_password_derived',
        'key_file'       => $hasDedicatedKey ? $keyFile : '(not created)',
        'dedicated_exists' => $hasDedicatedKey,
        'enrollments'    => $enrollments,
    ]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!csrf_verify($input['csrf_token'] ?? '')) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    if ($action === 'migrate') {
        if (file_exists($keyFile)) {
            json_error('Dedicated key already exists. No migration needed.');
        }

        // Count enrollments
        $enrollments = 0;
        try {
            $enrollments = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}user_tfa` WHERE `confirmed` = 1"
            );
        } catch (Exception $e) {
            json_error('Cannot read user_tfa table: ' . $e->getMessage(), 500);
        }

        if ($enrollments === 0) {
            // No enrollments — just generate the key
            if (tfa_generate_key()) {
                audit_log('config', 'create', 'tfa_key', null,
                    'Generated dedicated TFA encryption key (no enrollments to migrate)',
                    null, defined('AUDIT_HIGH') ? AUDIT_HIGH : 4);
                json_response([
                    'success'  => true,
                    'migrated' => 0,
                    'message'  => 'Dedicated key generated. No enrollments to migrate.',
                ]);
            } else {
                json_error('Failed to generate key file. Check directory permissions.', 500);
            }
        }

        // Compute old key (DB-derived)
        global $db_pass;
        $oldKey = hash('sha256', 'tfa_secret_key_' . $db_pass, true);

        // Verify old key works
        try {
            $testRow = db_fetch_one(
                "SELECT `secret_encrypted` FROM `{$prefix}user_tfa` WHERE `confirmed` = 1 LIMIT 1"
            );
            if ($testRow) {
                $testDecrypt = _tfa_decrypt_with_key($testRow['secret_encrypted'], $oldKey);
                if ($testDecrypt === false) {
                    json_error('Cannot decrypt existing enrollments with current DB password key. Migration cannot proceed.');
                }
            }
        } catch (Exception $e) {
            json_error('Verification failed: ' . $e->getMessage(), 500);
        }

        // Generate new key
        $newKey = random_bytes(32);
        if (!is_dir($keysDir)) {
            @mkdir($keysDir, 0700, true);
        }
        if (file_put_contents($keyFile, $newKey) === false) {
            json_error('Failed to write key file', 500);
        }
        @chmod($keyFile, 0600);

        // Re-encrypt all enrollments
        $result = tfa_reencrypt_all($oldKey, $newKey);

        // Verify
        if ($result['failed'] > 0) {
            // Rollback
            @unlink($keyFile);
            tfa_reencrypt_all($newKey, $oldKey);
            json_error('Migration failed for ' . $result['failed'] . ' enrollment(s). Rolled back. Errors: ' . implode('; ', $result['errors']));
        }

        audit_log('config', 'update', 'tfa_key', null,
            'Migrated TFA encryption to dedicated key file (' . $result['migrated'] . ' enrollments re-encrypted)',
            ['migrated' => $result['migrated']],
            defined('AUDIT_CRITICAL') ? AUDIT_CRITICAL : 5);

        json_response([
            'success'  => true,
            'migrated' => $result['migrated'],
            'message'  => 'Successfully migrated ' . $result['migrated'] . ' enrollment(s) to dedicated key.',
        ]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
