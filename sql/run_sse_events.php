<?php
/**
 * Migration: `sse_events` table (QA hardening, 2026-07-07).
 *
 * Historically sse_events was created LAZILY by inc/sse.php's
 * _sse_ensure_schema() the first time anything published an event.
 * On a virgin install that meant api/stream.php, api/channels.php and
 * anything SELECTing the table directly crashed with "Base table or
 * view not found" until the first publish happened. Provisioning now
 * creates it up front by invoking the canonical ensure function, so
 * there is exactly one copy of the DDL (inc/sse.php).
 *
 * Idempotent — the ensure function uses CREATE TABLE IF NOT EXISTS and
 * guarded column-adds. Exits non-zero if the table still doesn't exist
 * afterwards so the master runner records a real failure.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/sse.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

if (function_exists('_sse_ensure_schema')) {
    _sse_ensure_schema();
}

try {
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events`");
    echo "[OK] sse_events table ready\n";
} catch (Exception $e) {
    echo "[FAIL] sse_events still missing after ensure: " . $e->getMessage() . "\n";
    exit(1);
}
