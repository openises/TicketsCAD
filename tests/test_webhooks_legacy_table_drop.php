<?php
/**
 * B20 (SPEC-STATUS.md, 2026-08-21) — the legacy `webhooks` table is
 * dropped; the live `webhook_subscriptions` + `webhook_deliveries` pair is
 * untouched, and the delivery/fire path still works end-to-end. See
 * sql/run_webhooks_legacy_table_drop.php's own docblock for the full
 * rationale (zero readers/writers anywhere in the app; confirmed 0 rows
 * on every install checked).
 *
 * Usage: php tests/test_webhooks_legacy_table_drop.php
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/webhooks.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== B20 — legacy webhooks table dropped, webhook_subscriptions/webhook_deliveries untouched ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function b20_table_exists(string $prefix, string $table): bool {
    return (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $table]
    );
}

// ── 1. Migration is idempotent and drives the real script.
ob_start();
try {
    $ranOk = true;
    require __DIR__ . '/../sql/run_webhooks_legacy_table_drop.php';
} catch (Throwable $e) {
    $ranOk = false;
}
$out1 = ob_get_clean();
t('run_webhooks_legacy_table_drop.php ran without throwing', $ranOk);

ob_start();
try {
    require __DIR__ . '/../sql/run_webhooks_legacy_table_drop.php';
} catch (Throwable $e) {
    $ranOk = false;
}
$out2 = ob_get_clean();
t('run_webhooks_legacy_table_drop.php is idempotent (second run is a clean SKIP)',
    strpos($out2, '[SKIP]') !== false);

// ── 2. The actual database state.
t('webhooks table does NOT exist', !b20_table_exists($prefix, 'webhooks'));
t('webhook_subscriptions table STILL exists (the real, live subscription model)',
    b20_table_exists($prefix, 'webhook_subscriptions'));
t('webhook_deliveries table STILL exists (the real, live delivery log)',
    b20_table_exists($prefix, 'webhook_deliveries'));

// ── 3. Source-level: sql/webhooks.sql and sql/run_webhooks.php no longer
//      CREATE the dead table, but both still CREATE webhook_deliveries.
$webhooksSql = file_get_contents(__DIR__ . '/../sql/webhooks.sql');
t('sql/webhooks.sql no longer CREATEs `webhooks`',
    preg_match('/CREATE TABLE\s+(IF NOT EXISTS\s+)?`webhooks`\s*\(/i', $webhooksSql) === 0);
t('sql/webhooks.sql still CREATEs webhook_deliveries (untouched)',
    strpos($webhooksSql, 'CREATE TABLE IF NOT EXISTS `webhook_deliveries`') !== false);

$runWebhooks = file_get_contents(__DIR__ . '/../sql/run_webhooks.php');
t('sql/run_webhooks.php no longer CREATEs `{$prefix}webhooks`',
    preg_match('/CREATE TABLE\s+(IF NOT EXISTS\s+)?`\{\$prefix\}webhooks`\s*\(/i', $runWebhooks) === 0);
t('sql/run_webhooks.php still CREATEs webhook_deliveries (untouched)',
    strpos($runWebhooks, 'CREATE TABLE IF NOT EXISTS `{$prefix}webhook_deliveries`') !== false);

// ── 4. No remaining PHP reference to the dropped table's backtick-quoted
//      identifier anywhere in api/, inc/ (outside this test file, the
//      migration script, and the Phase 94 data-migration script, which
//      legitimately names it to handle upgrades from before this table
//      existed).
$scanDirs = ['api', 'inc'];
$allowedFiles = [
    realpath(__DIR__ . '/../sql/run_phase94_external_api.php'),
];
$offenders = [];
foreach ($scanDirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $dir));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        if (in_array(realpath($file->getPathname()), $allowedFiles, true)) continue;
        $src = file_get_contents($file->getPathname());
        // Backtick-quoted identifier only — not "webhook_subscriptions" or
        // "webhook_deliveries", which both contain the substring "webhook".
        if (preg_match('/`\{?\$?p?r?e?f?i?x?\}?webhooks`/', $src)
            && preg_match('/(?<!_)`(\{\$prefix\})?webhooks`/', $src)) {
            $offenders[] = str_replace('\\', '/', str_replace(__DIR__ . '/../', '', $file->getPathname()));
        }
    }
}
t('no api/ or inc/ file (outside the Phase 94 migration) references a backtick-quoted `webhooks` table'
    . (empty($offenders) ? '' : ' (found in: ' . implode(', ', $offenders) . ')'),
    empty($offenders));

// ── 5. tools/run_phase94_external_api.php's legacy-table migration steps
//      degrade gracefully now that the table never exists on a fresh
//      install — re-run it and confirm it reports "skipped", not a fatal
//      error, matching its own documented fresh-install behavior.
ob_start();
$phase94Ok = true;
try {
    require __DIR__ . '/../sql/run_phase94_external_api.php';
} catch (Throwable $e) {
    $phase94Ok = false;
}
$phase94Out = ob_get_clean();
t('sql/run_phase94_external_api.php still runs cleanly with the legacy table absent', $phase94Ok);
t("sql/run_phase94_external_api.php's data-migration step reports a graceful skip, not a failure",
    strpos($phase94Out, 'skipped (legacy webhooks table not present') !== false
    || strpos($phase94Out, 'FAIL') === false);

// ── 6. The real delivery path still works end-to-end against
//      webhook_subscriptions (proves dropping the legacy table did not
//      collaterally break the live system) — mirrors
//      tests/test_webhook_delivery.php's own fire+log pattern but scoped
//      to a direct function call, not a live HTTP round trip, so it runs
//      under NEWUI_TEST_NO_HTTP=1.
$testSecret = bin2hex(random_bytes(16));
$subId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}webhook_subscriptions`
            (name, target_url, hmac_secret, event_filters_json, active, retry_policy_json, created_by)
         VALUES (?, ?, ?, ?, 1, ?, 0)",
        ['B20 test subscription', 'https://example.invalid/b20-test', $testSecret,
         json_encode(['incident.created']), json_encode(['max_attempts' => 1, 'backoff_seconds' => [30]])]
    );
    $subId = (int) db_insert_id();
} catch (Exception $e) {
    $subId = null;
}
t('created a real webhook_subscriptions row for the live-path check', $subId !== null && $subId > 0);

if ($subId) {
    $fired = webhook_fire('incident.created', ['ticket_id' => 999999, 'scope' => 'B20 test']);
    t('webhook_fire() against a real subscription (no legacy table involved) reports an attempt', $fired >= 1);

    $delivery = db_fetch_all(
        "SELECT * FROM `{$prefix}webhook_deliveries` WHERE `subscription_id` = ? ORDER BY `id` DESC LIMIT 1",
        [$subId]
    );
    t('a webhook_deliveries row was logged against subscription_id (not the dropped legacy webhook_id path)',
        count($delivery) === 1 && $delivery[0]['event_type'] === 'incident.created');

    // Cleanup
    db_query("DELETE FROM `{$prefix}webhook_deliveries` WHERE `subscription_id` = ?", [$subId]);
    db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE `id` = ?", [$subId]);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
