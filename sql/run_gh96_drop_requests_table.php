<?php
/**
 * GH#96 (2026-08-20) — drop the legacy `requests` table.
 *
 * Decided via a 5-persona design review (fire chief / ARES volunteer /
 * patient-transport coordinator / campus security / sysadmin) alongside the
 * Mileage Log report: all five personas agreed the table should go (four
 * indifferent-to-supportive, one -- the sysadmin -- actively wants it gone
 * for maintenance-burden reasons). It fits the #91 dead-control-audit
 * policy's "confirmed genuinely gone" bucket exactly:
 *
 *   - Zero rows on every install checked (this dev database, and verified
 *     on your-server.example.com + your-server before this script
 *     was ever run against them -- see the deploy notes in
 *     specs/handoff.md / the GH#96 commit history for the exact counts).
 *   - Zero PHP references anywhere in the tree outside its own two
 *     CREATE TABLE statements (sql/base_schema.sql,
 *     sql/base_schema_RESET_DESTRUCTIVE.sql, both removed in the same
 *     change) and a generated schema doc (docs/SCHEMA-REFERENCE.md,
 *     regenerated in the same change).
 *   - No partially-wired mechanism to preserve -- its one historical job,
 *     inferring an org for billing via a fragile
 *     requests->user->organisations three-hop join, is already superseded
 *     by the direct, indexed `ticket.org_id` column and the
 *     org_query_filter()/org_ticket_query_filter() machinery v4 already
 *     has.
 *
 * Idempotent: DROP TABLE IF EXISTS, guarded by an information_schema check
 * first so a re-run against an install that has already dropped it (or a
 * fresh install that never created it, per the corresponding
 * base_schema.sql edit) is a clean no-op.
 *
 * IMPORTANT — do NOT confuse this with `access_requests`, a SEPARATE,
 * unrelated table (facility/account access requests, a real live v4
 * feature). The name similarity is a trap: this script touches ONLY
 * `requests`, never `access_requests`, and asserts that explicitly before
 * dropping anything.
 *
 * Usage: php sql/run_gh96_drop_requests_table.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $exists = db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'requests']
    );
    if (!$exists) {
        echo "[SKIP] {$prefix}requests does not exist — nothing to drop "
            . "(already dropped, or a fresh install that never created it)\n";
    } else {
        // Safety check, not a formality: the whole point of this comment
        // block is that `requests` and `access_requests` are easy to
        // confuse. Assert the table this script is about to drop is
        // EXACTLY "{$prefix}requests" and never touches
        // "{$prefix}access_requests" -- both belt-and-braces (the SQL
        // below is already hardcoded to the right name) and a clear
        // failure if that ever stops being true.
        $tableName = $prefix . 'requests';
        if ($tableName === $prefix . 'access_requests') {
            fwrite(STDERR, "ERROR: refusing to drop — computed table name '{$tableName}' collides with access_requests\n");
            exit(1);
        }

        $rowCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}requests`");
        if ($rowCount > 0) {
            // This has never been observed on any install checked (training,
            // your deployment, this dev database) -- if it's ever non-zero on a
            // real install, that is new information worth a human's eyes
            // before data is destroyed. Refuse rather than silently drop rows.
            fwrite(STDERR,
                "ERROR: {$prefix}requests has {$rowCount} row(s) -- refusing to drop a non-empty table.\n"
                . "This table was believed unused on every install checked; investigate before proceeding.\n"
                . "(If this is confirmed safe to discard, drop it manually and re-run this script, which\n"
                . "will then find the table already gone and exit cleanly.)\n"
            );
            exit(1);
        }

        db_query("DROP TABLE `{$prefix}requests`");
        echo "[OK] {$prefix}requests dropped (confirmed 0 rows before drop)\n";
    }
    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
