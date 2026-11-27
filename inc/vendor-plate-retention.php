<?php
/**
 * GH#148 — retention / purge for the licence-plate + vehicle-identifying
 * fields stored on a towing / roadside dispatch row.
 *
 * WHY THIS EXISTS. Slice A of the vendor-dispatch feature stores a vehicle
 * description, a licence plate and the plate's issuing state on every
 * `vendor_dispatches` row (so a dispatcher can read them to the tow driver and
 * find the record later). A vehicle registration plate is personal data in
 * several jurisdictions (GDPR/UK DPA, a number of US states), and the panel
 * review (specs/panel-review-2026-10-20/README.md §5 #148) and the feature
 * spec both flagged that nothing cleared it — the row, and its plate, lived
 * for ever. This file is the setting and the sweep that clears it.
 *
 * THE MODEL, deliberately the SIMPLER of this project's two retention shapes
 * (inc/message-log-retention.php, not inc/audit-retention.php):
 *
 *   1. OFF BY DEFAULT. `vendor_plate_retention_days` = 0 means disabled / keep
 *      for ever. Upgrading TicketsCAD must never start deleting data an
 *      operator never configured it to delete — the same non-disruptive bar
 *      as every other retention setting in this app.
 *   2. NO ARCHIVE. Unlike audit-retention (Phase 133), this does not
 *      archive-before-delete, because it does NOT delete a record: the
 *      dispatch row and the whole append-only ledger survive untouched. It
 *      only NULLs three identifying fields on a surviving row after the
 *      window. This is data minimisation, not record retention — the
 *      dispatch/ledger history (who was called, when, the outcome, the fee
 *      trail) is exactly what the feature exists to keep, so it is kept.
 *   3. THE LEDGER IS NEVER TOUCHED. `vendor_dispatch_ledger` carries no plate
 *      or vehicle column at all (only provider snapshots), so clearing the
 *      three header columns removes the plate from the mutable record and
 *      there is nothing in the append-only ledger to clear. A site that has
 *      REVOKEd UPDATE/DELETE on the ledger for tamper-resistance is unaffected
 *      by this sweep.
 *   4. NEVER A SILENT NO-OP. Disabled returns a real "skipped" verdict; a
 *      missing schema returns a real "skipped (schema)"; a failure returns a
 *      real error. The tick (tools/vendor_plate_purge_tick.php) records a
 *      heartbeat either way, and Settings → System Health → Scheduled Jobs
 *      turns red if the job stops — but ONLY once retention is actually turned
 *      on (sched_job_required('vendor_plate_purge') in inc/scheduled-jobs.php;
 *      shipped-default-is-not-usage, the project's standing rule).
 *
 * Settings live in the `settings` table, read via get_variable() — NOT the
 * `config` table read by get_setting(). Crossing those wires is a documented,
 * previously-real bug in this project (CLAUDE.md, "TWO settings stores"): a
 * value saved by the Settings UI would read back as the default for ever.
 * The setting is WRITTEN only by vendor_settings_save() (inc/vendor-admin-
 * write.php), which range-validates and audits it — the generic settings
 * endpoint (api/config-admin.php) would happily store garbage, so the reader
 * below clamps too.
 */

require_once __DIR__ . '/audit.php';

/** Hard cap on the configurable window: 10 years. Not a floor, not a default —
 *  only to clamp a garbage stored value to something sane. */
if (!defined('VENDOR_PLATE_RETENTION_MAX_DAYS')) {
    define('VENDOR_PLATE_RETENTION_MAX_DAYS', 3650);
}

/**
 * The `vendor_dispatches` columns this sweep clears, and ONLY these.
 *
 * One source of truth, read by the purge, the eligible-count and the test, so
 * the three can never disagree about what "identifying" means.
 *
 *   vehicle_desc  free text: year / make / model / colour — identifies the
 *                 vehicle (and, with time + place, its owner).
 *   plate         the licence / registration plate — the core personal datum.
 *   plate_state   the plate's issuing state / province — part of the plate's
 *                 identity (a plate is ambiguous without it).
 *
 * Deliberately NOT cleared: `tow_reason` (operational classification —
 * accident / abandoned / recovery — not a vehicle identifier), `dest_text` /
 * `dest_facility_id` (the tow DESTINATION — impound lot or shop — part of the
 * dispatch history the feature keeps), `notes`, `provider_*` and everything
 * else (operational record, not vehicle-identifying). The append-only ledger
 * is never touched (it carries none of these columns).
 */
function vendor_plate_identifying_columns(): array
{
    return ['vehicle_desc', 'plate', 'plate_state'];
}

/**
 * The retention window in days. 0 = disabled (keep for ever). A negative or
 * non-numeric stored value reads as 0; a value above the cap is clamped down
 * (the reader clamps because the generic settings endpoint can store garbage).
 */
function vendor_plate_retention_days(): int
{
    if (!function_exists('get_variable')) return 0;
    $v = get_variable('vendor_plate_retention_days');
    if ($v === null || $v === false || $v === '') return 0;
    $d = (int) $v;
    if ($d <= 0) return 0;
    return min($d, VENDOR_PLATE_RETENTION_MAX_DAYS);
}

/**
 * Does the one table and all three columns this sweep touches exist? Probed
 * from information_schema so a pre-migration / partially-built install
 * degrades gracefully (returns false → the purge skips, the admin readout
 * shows nothing) rather than throwing a column error.
 */
function vendor_plate_retention_schema_ready(): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $cols = vendor_plate_identifying_columns();
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $have = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($ph)",
            array_merge([$prefix . 'vendor_dispatches'], $cols)
        );
        return $have === count($cols);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * How many dispatch rows would this sweep clear right now — rows older than
 * the window that still carry at least one identifying field. Drives the
 * "N rows eligible" readout so an admin sees the impact BEFORE turning
 * retention on or changing the threshold. 0 when disabled, schema missing, or
 * nothing qualifies. Never throws.
 */
function vendor_plate_retention_eligible_count(int $days): int
{
    if ($days <= 0) return 0;
    if (!vendor_plate_retention_schema_ready()) return 0;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}vendor_dispatches`
              WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND (`vehicle_desc` IS NOT NULL OR `plate` IS NOT NULL OR `plate_state` IS NOT NULL)",
            [$days]
        );
    } catch (Throwable $e) {
        error_log('[vendor-plate-retention] eligible count failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Clear the identifying vehicle fields on dispatch rows older than the window.
 *
 * A no-op (skipped) when retention is disabled or the schema is missing —
 * never fatal, never silent: the caller always gets a real
 * ok/skipped/cleared verdict to log.
 *
 * $opts:
 *   'triggered_by'            string 'scheduled' | 'manual' (for the audit note)
 *   'retention_days_override' int    TEST-ONLY — never read from request input
 *                             (tools/vendor_plate_purge_tick.php and
 *                             api/vendor-admin.php never forward it).
 *                             get_variable() caches the whole settings table in
 *                             a static on first read per process (CLAUDE.md /
 *                             Phase 151), so a single test process cannot read
 *                             the setting as "disabled" and then "N days" by
 *                             rewriting the row between calls. This override
 *                             lets the purge MECHANICS be tested
 *                             deterministically in one process; the real
 *                             settings-table read path is covered separately,
 *                             across a real subprocess boundary.
 *
 * The clear uses `DATE_SUB(NOW(), INTERVAL ? DAY)` so the database computes the
 * cutoff against its own clock (the same anchor message-log retention uses);
 * tests backdate a row's `created_at` well past or well within the window, so
 * the exact value of "now" does not matter to the assertion.
 *
 * @return array{ok:bool, skipped:bool, cleared:int, detail:string}
 */
function vendor_plate_purge_run(array $opts = []): array
{
    $days = array_key_exists('retention_days_override', $opts) && $opts['retention_days_override'] !== null
        ? (int) $opts['retention_days_override']
        : vendor_plate_retention_days();
    if ($days < 0) $days = 0;

    if ($days <= 0) {
        return ['ok' => true, 'skipped' => true, 'cleared' => 0,
                'detail' => 'disabled (vendor_plate_retention_days=0)'];
    }
    if (!vendor_plate_retention_schema_ready()) {
        return ['ok' => true, 'skipped' => true, 'cleared' => 0,
                'detail' => 'skipped: towing / roadside tables are not installed'];
    }

    $triggeredBy = ($opts['triggered_by'] ?? 'scheduled') === 'manual' ? 'manual' : 'scheduled';
    $prefix      = $GLOBALS['db_prefix'] ?? '';

    try {
        $eligible = vendor_plate_retention_eligible_count($days);
        if ($eligible === 0) {
            return ['ok' => true, 'skipped' => false, 'cleared' => 0,
                    'detail' => "0 dispatch row(s) older than {$days} day(s) carry identifying fields"];
        }
        // Clear exactly the identifying columns, only on rows that still have
        // one, so a re-run does not churn rows already cleared and the delete
        // never touches the dispatch row, the provider/outcome fields, or the
        // append-only ledger.
        db_query(
            "UPDATE `{$prefix}vendor_dispatches`
                SET `vehicle_desc` = NULL, `plate` = NULL, `plate_state` = NULL
              WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND (`vehicle_desc` IS NOT NULL OR `plate` IS NOT NULL OR `plate_state` IS NOT NULL)",
            [$days]
        );
        if (function_exists('audit_log')) {
            audit_log('vendor', 'plate_purge', 'vendor_dispatches', null,
                "Cleared licence plate / vehicle fields on {$eligible} towing dispatch row(s) older than {$days} day(s)",
                ['cleared' => $eligible, 'retention_days' => $days,
                 'columns' => vendor_plate_identifying_columns(), 'triggered_by' => $triggeredBy]);
        }
        return ['ok' => true, 'skipped' => false, 'cleared' => $eligible,
                'detail' => "cleared identifying fields on {$eligible} dispatch row(s) older than {$days} day(s)"];
    } catch (Throwable $e) {
        error_log('[vendor-plate-retention] purge failed: ' . $e->getMessage());
        return ['ok' => false, 'skipped' => false, 'cleared' => 0,
                'detail' => $e->getMessage()];
    }
}

/**
 * Status for the Service Providers admin Settings tab / overview endpoint.
 * Read straight from the table (NOT get_variable(), which caches), so the
 * readout is correct immediately after a save in the same process.
 */
function vendor_plate_retention_status(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $days   = 0;
    try {
        $raw = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", ['vendor_plate_retention_days']);
        if ($raw !== false && $raw !== null && $raw !== '') {
            $d = (int) $raw;
            if ($d > 0) $days = min($d, VENDOR_PLATE_RETENTION_MAX_DAYS);
        }
    } catch (Throwable $e) { /* schema missing → days stays 0 */ }

    return [
        'enabled'        => $days > 0,
        'retention_days' => $days,
        'max_days'       => VENDOR_PLATE_RETENTION_MAX_DAYS,
        'eligible_count' => vendor_plate_retention_eligible_count($days),
        'columns'        => vendor_plate_identifying_columns(),
    ];
}
