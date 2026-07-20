<?php
/**
 * Phase E (messaging-send-gaps-2026-06) — Zello user-DM + reply threading.
 *
 * One idempotent, guarded schema addition so an inbound/outbound Zello text
 * can be tagged as a direct message and threaded reply-ably (mirroring the
 * Phase-C mesh inbox model):
 *
 *   zello_messages.recipient
 *     The DM partner's Zello username — the address a reply goes to.
 *       - inbound DM  : the SENDER (`from`) of an `on_text_message` that
 *                       carried a `for` field (i.e. it was addressed to us).
 *       - outbound DM : the user the console DM'd (the `for` we sent).
 *       - channel text: '' (empty) — a broadcast, no per-user partner.
 *
 *     With this column the Zello inbox can offer "reply to sender (DM)" vs
 *     "reply to channel", and a reply queues a zello_outbox row with the
 *     right channel/user sub-address.
 *
 * Safe to re-run. No data migration; the new column defaults '' so existing
 * rows read as channel (non-DM) traffic — which is what they were before
 * DM support existed.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

echo "Phase E — zello_messages.recipient (DM threading)\n";
echo "=================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Guard the helper so the migration is safe to require twice in one PHP
// process (e.g. the idempotency check in tests/test_zello_dm.php).
if (!function_exists('_zdm_has_col')) {
    function _zdm_has_col(string $table, string $col): bool {
        global $prefix;
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $table, $col]
        );
    }
}

// zello_messages must exist first (created by sql/zello_tables.sql). If the
// install has never set up Zello, there's nothing to alter — exit cleanly.
$tableExists = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'zello_messages']
);
if (!$tableExists) {
    echo "[SKIP] {$prefix}zello_messages does not exist yet — run sql/zello_tables.sql first.\n";
    echo "       (Nothing to migrate; the recipient column is added when the table is created.)\n";
    echo "\nDone.\n";
    exit(0);
}

if (_zdm_has_col('zello_messages', 'recipient')) {
    echo "[OK] zello_messages.recipient already present\n";
} else {
    try {
        db_query(
            "ALTER TABLE `{$prefix}zello_messages`
                ADD COLUMN `recipient` VARCHAR(100) NOT NULL DEFAULT ''
                COMMENT 'DM partner Zello username (inbound=sender, outbound=target); blank = channel broadcast'
                AFTER `channel`"
        );
        echo "[OK] added zello_messages.recipient\n";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'duplicate column') !== false) {
            echo "[OK] zello_messages.recipient already present (race)\n";
        } else {
            echo "[FAIL] zello_messages.recipient: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// Index it so the inbox's "DM threads only" filter stays cheap.
try {
    $hasIdx = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_recipient'",
        [$prefix . 'zello_messages']
    );
    if (!$hasIdx) {
        db_query("ALTER TABLE `{$prefix}zello_messages` ADD INDEX `idx_recipient` (`recipient`)");
        echo "[OK] added zello_messages.idx_recipient index\n";
    } else {
        echo "[OK] zello_messages.idx_recipient index already present\n";
    }
} catch (Exception $e) {
    // Non-fatal: the column works without the index, just slower filtering.
    echo "[WARN] could not add idx_recipient (non-fatal): " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
