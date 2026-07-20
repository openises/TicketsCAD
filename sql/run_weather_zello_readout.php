<?php
/**
 * Phase 112 Phase 6 — Zello weather read-out (operator-approval routing).
 *
 * Adds target routing columns to ai_pending_responses so an approval card can
 * carry a NON-DMR destination: a Zello weather bulletin queues a card with
 * target_kind='zello' + target_ref='<channel name>'; on approve,
 * api/radio-ai-decide.php queues a zello_outbox kind='tts' row (the Zello
 * proxy synthesizes + keys it) instead of POSTing the DMR bridge.
 *
 * Existing rows default to target_kind='dmr' (channel_id stays authoritative
 * for the DMR path) — nothing about the Phase 85f flow changes.
 *
 * Idempotent — guarded by information_schema; safe to run repeatedly.
 */

if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('CLI or migration-runner only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 112 Phase 6 — Zello weather read-out columns\n";

function _wxz_col_exists(string $table, string $col): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

try {
    if (!_wxz_col_exists('ai_pending_responses', 'target_kind')) {
        db_query("ALTER TABLE `{$prefix}ai_pending_responses`
                  ADD COLUMN `target_kind` VARCHAR(8) NOT NULL DEFAULT 'dmr'
                  COMMENT 'dmr = bridge /tx/text via channel_id; zello = zello_outbox tts via target_ref'");
        echo "[OK] ai_pending_responses.target_kind added\n";
    } else {
        echo "[OK] ai_pending_responses.target_kind already present\n";
    }

    if (!_wxz_col_exists('ai_pending_responses', 'target_ref')) {
        db_query("ALTER TABLE `{$prefix}ai_pending_responses`
                  ADD COLUMN `target_ref` VARCHAR(100) DEFAULT NULL
                  COMMENT 'non-DMR destination (Zello channel name); NULL for DMR rows'");
        echo "[OK] ai_pending_responses.target_ref added\n";
    } else {
        echo "[OK] ai_pending_responses.target_ref already present\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "Done.\n";
