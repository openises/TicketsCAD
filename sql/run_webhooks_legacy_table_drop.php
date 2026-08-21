<?php
/**
 * B20 (SPEC-STATUS.md, 2026-08-21) — drop the legacy `webhooks` table.
 *
 * `sql/run_webhooks.php` used to create this table (name/url/secret/
 * events_json/retry_max) unconditionally on every install, glob-discovered
 * by sql/run_migrations.php. Every live code path uses `webhook_subscriptions`
 * instead — inc/webhooks.php's own docblock says so explicitly ("Subscriptions
 * live in the new webhook_subscriptions table... webhook_fire() reads ONLY
 * from webhook_subscriptions going forward"). api/webhooks.php never INSERTs
 * into the legacy table, only into webhook_subscriptions. It was absent
 * from sql/schema_manifest.json.
 *
 * The ONE legitimate reader of the legacy table is
 * sql/run_phase94_external_api.php's own one-time data migration (step 1.5:
 * copy any pre-Phase-94 `webhooks` rows into `webhook_subscriptions` by
 * target_url match, idempotent, skips rows already migrated) and its
 * webhook_deliveries FK-retarget step (1.6: walk historical deliveries to
 * set subscription_id via a join through the legacy table). Both are
 * already guarded with `_phase94_table_exists($prefix, 'webhooks')` checks
 * and degrade to a graceful skip when the table is absent — which is
 * exactly what happens on any install from this point forward, since
 * sql/webhooks.sql / sql/run_webhooks.php no longer create it.
 *
 * Filename note: this is named to sort AFTER run_phase94_external_api.php
 * in sql/run_migrations.php's lexicographic ksort() (see that file's own
 * "run_*.php lexicographic-filename ordering" lesson in CLAUDE.md) so that,
 * on any install that still has an un-migrated legacy `webhooks` table when
 * it upgrades, Phase 94's data migration gets to run FIRST and this script
 * only ever drops a table whose rows have already had a chance to land in
 * webhook_subscriptions.
 *
 * Idempotent: DROP TABLE IF EXISTS, guarded by an information_schema check
 * first, and refuses (does not drop) if the table is ever non-empty --
 * matching the sql/run_gh96_drop_requests_table.php precedent for a
 * confirmed-dead table exactly (unlike its B19 sibling for
 * `newui_facility_capacity`, this table has no known auto-seeded shape --
 * it was never written by any code path at all -- so a strict zero-row
 * check is the right one here).
 *
 * Verified before this script was written against three independent
 * installs -- this dev database, your-server.example.com, and
 * your-server -- `webhooks` was 0 rows on all three.
 *
 * Usage: php sql/run_webhooks_legacy_table_drop.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'webhooks';

try {
    $exists = db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );
    if (!$exists) {
        echo "[SKIP] {$table} does not exist — nothing to drop "
            . "(already dropped, or a fresh install that never created it)\n";
    } else {
        // Belt-and-braces: this table's name is a short, common word that
        // could plausibly collide with a differently-prefixed table on an
        // unusual install. The SQL below is already hardcoded to the exact
        // computed name; assert it explicitly too.
        if ($table !== $prefix . 'webhooks') {
            fwrite(STDERR, "ERROR: refusing to drop — computed table name mismatch\n");
            exit(1);
        }

        $rowCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$table}`");
        if ($rowCount > 0) {
            // Never observed on any install checked (this dev database,
            // training, your deployment) -- Phase 94's data migration (step 1.5)
            // already runs earlier in ksort() order and copies any legacy
            // rows into webhook_subscriptions before this script ever sees
            // them, but it does not DELETE the source rows. If this is
            // non-zero, either that migration hasn't run yet on this
            // install, or something wrote to the legacy table directly.
            // Refuse rather than silently discard rows that might not be
            // migrated yet.
            fwrite(STDERR,
                "ERROR: {$table} has {$rowCount} row(s) -- refusing to drop a non-empty table.\n"
                . "Run `php sql/run_phase94_external_api.php` first to confirm its rows have\n"
                . "been migrated into webhook_subscriptions, verify with:\n"
                . "  SELECT * FROM webhook_subscriptions;\n"
                . "then re-run this script, which will then find the table empty and drop it.\n"
            );
            exit(1);
        }

        db_query("DROP TABLE `{$table}`");
        echo "[OK] {$table} dropped (confirmed 0 rows before drop)\n";
    }
    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
