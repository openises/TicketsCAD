<?php
/**
 * TFA Key Migration Tool
 *
 * Migrates 2FA secrets from the legacy DB-password-derived key to a
 * dedicated key file (../keys/tfa.key). This decouples TFA security
 * from database credentials.
 *
 * Usage:
 *   php tools/tfa-migrate-key.php              -- migrate to new dedicated key
 *   php tools/tfa-migrate-key.php --dry-run    -- show what would be migrated
 *   php tools/tfa-migrate-key.php --status     -- check current key status
 *
 * IMPORTANT: Run this ONCE after upgrading. After migration, the dedicated
 * key file is used for all TFA operations. Changing the DB password no longer
 * affects 2FA enrollments.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/tfa.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$statusOnly = in_array('--status', $argv ?? []);

$keysDir = defined('FE_KEYS_DIR') ? FE_KEYS_DIR : (NEWUI_ROOT . '/../keys');
$keyFile = $keysDir . '/tfa.key';

echo "=== TFA Key Migration Tool ===\n\n";

// ── Status check ──
$hasDedicatedKey = file_exists($keyFile);
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Dedicated key file:  " . ($hasDedicatedKey ? "EXISTS ({$keyFile})" : "NOT FOUND") . "\n";

$enrollmentCount = 0;
try {
    $enrollmentCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_tfa` WHERE `confirmed` = 1");
} catch (Exception $e) {
    echo "Cannot read user_tfa table: " . $e->getMessage() . "\n";
    exit(1);
}
echo "TFA enrollments:     {$enrollmentCount}\n";

if ($hasDedicatedKey) {
    echo "Key source:          Dedicated file (independent of DB password)\n";
} else {
    echo "Key source:          DB password derived (LEGACY — change DB password = lose all 2FA)\n";
}

if ($statusOnly) {
    echo "\n";
    exit(0);
}

if ($hasDedicatedKey) {
    echo "\nDedicated key already exists. No migration needed.\n";
    echo "If you need to rotate the key, use --rotate (not yet implemented).\n";
    exit(0);
}

if ($enrollmentCount === 0) {
    echo "\nNo TFA enrollments to migrate. Generating dedicated key...\n";
    if (!$dryRun) {
        if (tfa_generate_key()) {
            echo "Dedicated key generated: {$keyFile}\n";
        } else {
            echo "FAILED to generate key. Check directory permissions.\n";
            exit(1);
        }
    } else {
        echo "[DRY RUN] Would generate key at: {$keyFile}\n";
    }
    echo "\nDone.\n";
    exit(0);
}

// ── Migration ──
echo "\nMigrating {$enrollmentCount} enrollment(s) from DB-password key to dedicated key...\n";

// Compute the old key (DB-password-derived)
global $db_pass;
$oldKey = hash('sha256', 'tfa_secret_key_' . $db_pass, true);

// Verify the old key works by test-decrypting the first enrollment
try {
    $testRow = db_fetch_one("SELECT `secret_encrypted` FROM `{$prefix}user_tfa` WHERE `confirmed` = 1 LIMIT 1");
    if ($testRow) {
        $testDecrypt = _tfa_decrypt_with_key($testRow['secret_encrypted'], $oldKey);
        if ($testDecrypt === false) {
            echo "ERROR: Cannot decrypt test enrollment with current DB-password key.\n";
            echo "The DB password may have already been changed. Migration cannot proceed.\n";
            exit(1);
        }
        echo "Old key validation:  OK (test decryption succeeded)\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

if ($dryRun) {
    echo "\n[DRY RUN] Would:\n";
    echo "  1. Generate new dedicated key at {$keyFile}\n";
    echo "  2. Decrypt all {$enrollmentCount} secrets with DB-password key\n";
    echo "  3. Re-encrypt all secrets with new dedicated key\n";
    echo "  4. Update user_tfa table\n";
    echo "\nRun without --dry-run to execute.\n";
    exit(0);
}

// Generate the new dedicated key
echo "Generating dedicated key... ";
$newKey = random_bytes(32);
if (!is_dir($keysDir)) {
    @mkdir($keysDir, 0700, true);
}
if (file_put_contents($keyFile, $newKey) === false) {
    echo "FAILED\n";
    exit(1);
}
@chmod($keyFile, 0600);
echo "OK\n";

// Re-encrypt all enrollments
echo "Re-encrypting secrets... ";
$result = tfa_reencrypt_all($oldKey, $newKey);
echo "done\n\n";

echo "Results:\n";
echo "  Migrated: {$result['migrated']}\n";
echo "  Failed:   {$result['failed']}\n";

if (!empty($result['errors'])) {
    echo "\nErrors:\n";
    foreach ($result['errors'] as $err) {
        echo "  - {$err}\n";
    }
}

// Verify the new key works
echo "\nVerification: ";
try {
    $verifyRow = db_fetch_one("SELECT `secret_encrypted` FROM `{$prefix}user_tfa` WHERE `confirmed` = 1 LIMIT 1");
    if ($verifyRow) {
        $verifyDecrypt = _tfa_decrypt_with_key($verifyRow['secret_encrypted'], $newKey);
        if ($verifyDecrypt !== false) {
            echo "OK (new key decrypts successfully)\n";
        } else {
            echo "FAILED (new key cannot decrypt — ROLLING BACK)\n";
            // Rollback: delete the new key file so system falls back to old key
            @unlink($keyFile);
            // Re-encrypt back to old key
            tfa_reencrypt_all($newKey, $oldKey);
            echo "Rolled back to DB-password key.\n";
            exit(1);
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Migration Complete ===\n";
echo "TFA secrets are now encrypted with a dedicated key independent of the DB password.\n";
echo "Key file: {$keyFile}\n";
echo "BACK UP THIS FILE — losing it means all 2FA enrollments become unrecoverable.\n";
