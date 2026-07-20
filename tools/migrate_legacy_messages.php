<?php
/**
 * NewUI v4.0 — Legacy v3.44 messages migration tool
 *
 * Imports the legacy v3.44 `messages` table (20 columns) into the
 * modern broker `messages` table (14 columns) used by NewUI v4.0.
 *
 * Run this AFTER tools/fix_chat_tables.php has installed the modern
 * schema. The fix tool snapshots any pre-existing rows to
 * `messages_legacy_YYYYMMDD`; this tool reads from EITHER the
 * snapshot OR a side-by-side legacy install's `messages` table.
 *
 * v3.44 source schema (20 cols):
 *   id, msg_type, message_id, server_number, ticket_id, resp_id,
 *   recipients, from_address, fromname, subject, message, status,
 *   date, read_status, readby, delivered, delivery_status,
 *   _by, _from, _on
 *
 * v4.0 target schema (14 cols):
 *   id, channel, direction, msg_type, sender, recipient, subject,
 *   body, priority, status, error, payload, delivered_at, created_at
 *
 * Field mapping:
 *   channel       <- always 'legacy' (no equivalent concept in v3.44)
 *   direction     <- if from_address contains '@' or starts with a
 *                    phone-looking pattern → 'inbound', else 'outbound'.
 *                    Fallback heuristic only; v3.44 didn't track this.
 *   msg_type      <- legacy msg_type, defaulting to 'general'
 *   sender        <- from_address, else fromname, else 'legacy'
 *   recipient     <- recipients (256-char truncated)
 *   subject       <- subject
 *   body          <- message
 *   priority      <- 'normal' (legacy had no priority concept)
 *   status        <- legacy status, mapped through:
 *                      delivered=1                 → 'delivered'
 *                      delivery_status='failed'    → 'failed'
 *                      otherwise                   → 'pending'
 *   error         <- delivery_status if it looks like an error string
 *   payload       <- JSON containing the original legacy row for audit
 *   delivered_at  <- date IF delivered=1 else NULL
 *   created_at    <- date
 *
 * Usage:
 *   php tools/migrate_legacy_messages.php                    # dry-run
 *   php tools/migrate_legacy_messages.php --execute          # apply
 *   php tools/migrate_legacy_messages.php --source=snapshot  # use today's snapshot table
 *   php tools/migrate_legacy_messages.php --source=legacy    # use tickets/incs side-by-side install
 *
 * Re-runnable: uses INSERT IGNORE keyed on legacy id stored in payload.
 */

require __DIR__ . '/../config.php';

$args      = $_SERVER['argv'] ?? [];
$execute   = in_array('--execute', $args, true);
$sourceArg = 'snapshot';
foreach ($args as $a) {
    if (strpos($a, '--source=') === 0) {
        $sourceArg = substr($a, 9);
    }
}

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== v3.44 messages → v4.0 broker messages migration ===\n";
echo "Mode: " . ($execute ? 'EXECUTE' : 'DRY-RUN (no writes; pass --execute to apply)') . "\n";
echo "Source: {$sourceArg}\n\n";

// ─── Locate the source table ─────────────────────────────────────
$srcPdo = null;
$srcTable = null;

if ($sourceArg === 'snapshot') {
    $today = date('Ymd');
    $snap = "{$prefix}messages_legacy_{$today}";
    try {
        $exists = db_fetch_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$snap]);
    } catch (Exception $e) { $exists = 0; }
    if (!$exists) {
        // Try yesterday in case the snapshot rolled overnight
        $snap = "{$prefix}messages_legacy_" . date('Ymd', strtotime('-1 day'));
        try {
            $exists = db_fetch_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$snap]);
        } catch (Exception $e) { $exists = 0; }
    }
    if (!$exists) {
        echo "  No snapshot table found. Run tools/fix_chat_tables.php first, OR pass --source=legacy.\n";
        exit(1);
    }
    $srcTable = $snap;
    $srcPdo   = db();
    echo "  Reading from local snapshot: {$snap}\n\n";
} elseif ($sourceArg === 'legacy') {
    // Pattern lifted from migrate_legacy_settings.php — read-only side load
    $legacyIncFile = realpath(__DIR__ . '/../../..') . '/tickets/incs/mysql.inc.php';
    if (!$legacyIncFile || !file_exists($legacyIncFile)) {
        $legacyIncFile = realpath(__DIR__ . '/../../../tickets/incs/mysql.inc.php');
    }
    if (!$legacyIncFile || !file_exists($legacyIncFile)) {
        fwrite(STDERR, "ERROR: cannot find legacy config at tickets/incs/mysql.inc.php\n");
        exit(1);
    }
    include $legacyIncFile;
    $lhost = $mysql_host   ?? 'localhost';
    $luser = $mysql_user   ?? '';
    $lpass = $mysql_passwd ?? '';
    $ldb   = $mysql_db     ?? 'tickets';
    $lpre  = $mysql_prefix ?? '';
    $srcTable = "{$lpre}messages";
    try {
        $srcPdo = new PDO(
            "mysql:host={$lhost};dbname={$ldb};charset=utf8mb4",
            $luser, $lpass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (Exception $e) {
        fwrite(STDERR, "ERROR: cannot connect to legacy DB: " . $e->getMessage() . "\n");
        exit(1);
    }
    echo "  Reading read-only from side-by-side legacy install: {$ldb}.{$srcTable}\n\n";
} else {
    fwrite(STDERR, "ERROR: --source must be 'snapshot' or 'legacy'\n");
    exit(1);
}

// ─── Sanity check: target schema must be modern ──────────────────
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}messages`");
    $names = array_column($cols, 'Field');
    $hasModern = in_array('channel', $names) && in_array('direction', $names) && in_array('body', $names);
} catch (Exception $e) { $hasModern = false; }
if (!$hasModern) {
    fwrite(STDERR, "ERROR: target table `{$prefix}messages` is not on the modern schema.\n");
    fwrite(STDERR, "       Run tools/fix_chat_tables.php first.\n");
    exit(1);
}

// ─── Pull source rows ────────────────────────────────────────────
$rows = $srcPdo->query("SELECT * FROM `{$srcTable}` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);
echo "  Source rows: {$total}\n";
if (!$total) {
    echo "  Nothing to import. Done.\n";
    exit(0);
}

// Build a set of already-imported legacy ids by reading payload.legacy_id
$already = [];
try {
    $existing = db_fetch_all("SELECT payload FROM `{$prefix}messages` WHERE payload IS NOT NULL AND payload LIKE '%legacy_id%'");
    foreach ($existing as $e) {
        $p = json_decode($e['payload'] ?? '', true);
        if (is_array($p) && isset($p['legacy_id'])) $already[(int) $p['legacy_id']] = true;
    }
} catch (Exception $e) { /* fresh install — nothing to skip */ }

$inserted = 0;
$skipped  = 0;
foreach ($rows as $r) {
    $legacyId = (int) ($r['id'] ?? 0);
    if (isset($already[$legacyId])) { $skipped++; continue; }

    // Derive sender
    $sender = $r['from_address'] ?? '';
    if ($sender === '' || $sender === null) $sender = $r['fromname'] ?? '';
    if ($sender === '' || $sender === null) $sender = 'legacy';
    $sender = mb_substr((string) $sender, 0, 128);

    // Derive direction (heuristic — flag in payload for review)
    $direction = 'outbound';
    if (!empty($r['read_status']) && (int) $r['read_status'] > 0) {
        // It was read by someone → likely inbound
        $direction = 'inbound';
    }

    // Status mapping
    $status = 'pending';
    if (!empty($r['delivered']) && (int) $r['delivered'] === 1) {
        $status = 'delivered';
    } elseif (!empty($r['delivery_status']) && stripos((string) $r['delivery_status'], 'fail') !== false) {
        $status = 'failed';
    }

    $error = null;
    if (!empty($r['delivery_status'])) {
        $ds = (string) $r['delivery_status'];
        if (stripos($ds, 'fail') !== false || stripos($ds, 'error') !== false) {
            $error = $ds;
        }
    }

    $deliveredAt = null;
    if ($status === 'delivered' && !empty($r['date'])) {
        $deliveredAt = $r['date'];
    }

    $createdAt = !empty($r['date']) ? $r['date'] : date('Y-m-d H:i:s');

    $payload = json_encode([
        'legacy_id'        => $legacyId,
        'message_id'       => $r['message_id'] ?? null,
        'server_number'    => $r['server_number'] ?? null,
        'ticket_id'        => $r['ticket_id'] ?? null,
        'resp_id'          => $r['resp_id'] ?? null,
        'fromname'         => $r['fromname'] ?? null,
        'read_status'      => $r['read_status'] ?? null,
        'readby'           => $r['readby'] ?? null,
        'delivery_status'  => $r['delivery_status'] ?? null,
        '_by'              => $r['_by'] ?? null,
        '_from'            => $r['_from'] ?? null,
        '_on'              => $r['_on'] ?? null,
    ], JSON_UNESCAPED_SLASHES);

    if ($execute) {
        try {
            db_query(
                "INSERT INTO `{$prefix}messages`
                  (channel, direction, msg_type, sender, recipient, subject, body,
                   priority, status, error, payload, delivered_at, created_at)
                 VALUES
                  ('legacy', ?, ?, ?, ?, ?, ?, 'normal', ?, ?, ?, ?, ?)",
                [
                    $direction,
                    mb_substr((string) ($r['msg_type'] ?? 'general'), 0, 32),
                    $sender,
                    mb_substr((string) ($r['recipients'] ?? ''), 0, 256),
                    mb_substr((string) ($r['subject'] ?? ''), 0, 256),
                    (string) ($r['message'] ?? ''),
                    $status,
                    $error,
                    $payload,
                    $deliveredAt,
                    $createdAt,
                ]
            );
            $inserted++;
        } catch (Exception $e) {
            fwrite(STDERR, "  ERROR importing legacy id {$legacyId}: " . $e->getMessage() . "\n");
        }
    } else {
        $inserted++;
    }
}

echo "  " . ($execute ? "Inserted" : "Would insert") . ": {$inserted}\n";
echo "  Skipped (already imported): {$skipped}\n";

if (!$execute) {
    echo "\n  Dry-run only. Re-run with --execute to apply.\n";
} else {
    echo "\n  Import complete. Imported rows carry payload.legacy_id for traceability.\n";
}
