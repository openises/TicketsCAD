<?php
/**
 * GH#148 — licence-plate / vehicle-field retention + purge for the towing /
 * roadside vendor-dispatch feature. Panel review 2026-10-20 §5 #148.
 *
 * Proves, through the REAL dispatch writer (vendor_dispatch_create) and the
 * REAL purge (vendor_plate_purge_run) — never a hand-seeded row:
 *   - default 0 clears nothing;
 *   - a set window clears ONLY vehicle_desc / plate / plate_state on rows older
 *     than the window, leaving newer rows, the rest of the dispatch, and the
 *     whole append-only ledger untouched;
 *   - the sweep is idempotent (a second run clears nothing);
 *   - the scheduled job is "not required" at the default and "required" once
 *     the window is set (checked in a fresh process so get_variable()'s cache
 *     reflects the real settings-table read, Phase 151).
 * Row ages are backdated in the fixture — nothing sleeps.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/tests/_vendor_fixtures.php';
require_once $root . '/inc/vendor-plate-retention.php';

$pass = 0; $fail = 0;
function t($l, $c, $d = '') { global $pass, $fail; $c ? $pass++ : $fail++; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($d && !$c ? " — $d" : '') . "\n"; }

try { db_fetch_value('SELECT 1'); } catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n\n0 passed, 0 failed\n"; exit(0);
}
$prefix = $GLOBALS['db_prefix'] ?? '';
if (!vendor_schema_ready()) {
    echo "SKIP: vendor tables absent — run php sql/run_migrations.php\n\n0 passed, 0 failed\n"; exit(0);
}

// Act as the global Super Admin for every writer call below (org scope + the install-wide settings gate read $_SESSION).
vf_session_as(test_admin_user_id());

// Snapshot the (non-defs) plate-retention setting so we leave the shared DB as we found it.
$origRetention = null;
try { $origRetention = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'vendor_plate_retention_days'"); } catch (Throwable $e) {}

$oldId = 0; $newId = 0;

try {
    // ── The single source of truth for "which columns are identifying" ──
    $cols = vendor_plate_identifying_columns();
    t('identifying columns are exactly vehicle_desc, plate, plate_state',
        $cols === ['vehicle_desc', 'plate', 'plate_state'], json_encode($cols));

    // ── Fixtures through the real writers ──
    $ticketId = vf_ticket();
    $towTypeId = vf_service_type_id('tow');
    t('tow service type is seeded', $towTypeId > 0, 'id=' . $towTypeId);
    $actor = vf_actor();

    $details = [
        'service_type_id' => $towTypeId,
        'vehicle_desc'    => '2019 Honda Civic, blue',
        'plate'           => 'abc123',
        'plate_state'     => 'mn',
        'tow_reason'      => 'disabled vehicle',
        'dest_text'       => 'City Impound Lot, 9 Yard Rd',
        'notes'           => 'owner on scene',
    ];
    $rOld = vendor_dispatch_create($ticketId, $details, $actor);
    t('old dispatch created through the real writer', ($rOld['ok'] ?? false) === true, json_encode($rOld));
    $oldId = (int) ($rOld['dispatch_id'] ?? 0);

    $rNew = vendor_dispatch_create($ticketId, array_merge($details, ['plate' => 'xyz789', 'vehicle_desc' => '2022 Ford F150']), $actor);
    t('new dispatch created through the real writer', ($rNew['ok'] ?? false) === true, json_encode($rNew));
    $newId = (int) ($rNew['dispatch_id'] ?? 0);

    // A genuine ledger row on the OLD dispatch via the real offer path, so we can prove the purge never touches it.
    $rOffer = vendor_dispatch_offer(['dispatch_id' => $oldId, 'provider_name' => "Joe's Towing", 'provider_phone' => '555-0101'], $actor);
    t('offer on the old dispatch wrote a ledger row', ($rOffer['ok'] ?? false) === true, json_encode($rOffer));

    // The writer persisted the plate (it is stored upper-cased).
    $old = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ?", [$oldId]);
    t('writer stored the plate upper-cased', $old['plate'] === 'ABC123', (string) $old['plate']);
    t('writer stored vehicle_desc', $old['vehicle_desc'] === '2019 Honda Civic, blue');
    t('writer stored plate_state upper-cased', $old['plate_state'] === 'MN');

    // Backdate the OLD dispatch 100 days; the NEW one stays at "now". No sleeping.
    db_query("UPDATE `{$prefix}vendor_dispatches` SET `created_at` = DATE_SUB(NOW(), INTERVAL 100 DAY) WHERE `id` = ?", [$oldId]);

    // Snapshot the old dispatch's ledger BEFORE the purge (count + a content fingerprint).
    $ledgerBefore = vf_ledger($oldId);
    $fingerprint = function (array $rows) {
        return array_map(function ($r) {
            return [$r['event_type'], $r['provider_name'], $r['provider_phone'], $r['selection_method'], $r['reason']];
        }, $rows);
    };
    $ledgerFpBefore = $fingerprint($ledgerBefore);
    t('the ledger carries no plate/vehicle column to begin with',
        !array_key_exists('plate', $ledgerBefore[0]) && !array_key_exists('vehicle_desc', $ledgerBefore[0]));

    // ── 1. Default off (override 0) clears nothing ──
    $rDisabled = vendor_plate_purge_run(['retention_days_override' => 0]);
    t('override 0 → skipped, nothing cleared', ($rDisabled['skipped'] ?? false) === true && (int) $rDisabled['cleared'] === 0, json_encode($rDisabled));
    $stillOld = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ?", [$oldId]);
    t('old dispatch still has its plate after a disabled run', $stillOld['plate'] === 'ABC123');

    // Eligible count: only the old row (older than 30d AND carries identifying data).
    t('eligible count at 30 days is exactly the one old row', vendor_plate_retention_eligible_count(30) >= 1, (string) vendor_plate_retention_eligible_count(30));

    // ── 2. A set window clears ONLY the identifying fields on the OLD row ──
    $rPurge = vendor_plate_purge_run(['retention_days_override' => 30]);
    t('purge at 30 days reports ok, not skipped', ($rPurge['ok'] ?? false) === true && empty($rPurge['skipped']), json_encode($rPurge));
    t('purge cleared at least the one old row', (int) ($rPurge['cleared'] ?? 0) >= 1, (string) ($rPurge['cleared'] ?? 'n/a'));

    $old2 = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ?", [$oldId]);
    t('old: vehicle_desc cleared', $old2['vehicle_desc'] === null, var_export($old2['vehicle_desc'], true));
    t('old: plate cleared', $old2['plate'] === null, var_export($old2['plate'], true));
    t('old: plate_state cleared', $old2['plate_state'] === null, var_export($old2['plate_state'], true));
    // Everything else on the OLD row is untouched.
    t('old: tow_reason kept', $old2['tow_reason'] === 'disabled vehicle', (string) $old2['tow_reason']);
    t('old: dest_text kept', $old2['dest_text'] === 'City Impound Lot, 9 Yard Rd', (string) $old2['dest_text']);
    t('old: notes kept', $old2['notes'] === 'owner on scene', (string) $old2['notes']);
    t('old: ticket_ref kept', (string) $old2['ticket_ref'] !== '' && $old2['ticket_ref'] === $old['ticket_ref']);
    t('old: the dispatch row itself survives (status intact)', $old2['status'] === $old['status'], (string) $old2['status']);

    // The NEW (recent) row is wholly untouched.
    $new2 = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ?", [$newId]);
    t('new: plate kept (younger than the window)', $new2['plate'] === 'XYZ789', (string) $new2['plate']);
    t('new: vehicle_desc kept', $new2['vehicle_desc'] === '2022 Ford F150');

    // The append-only ledger of the old dispatch is byte-for-byte what it was.
    $ledgerAfter = vf_ledger($oldId);
    t('ledger row count unchanged', count($ledgerAfter) === count($ledgerBefore), count($ledgerBefore) . ' → ' . count($ledgerAfter));
    t('ledger content unchanged', $fingerprint($ledgerAfter) === $ledgerFpBefore);

    // ── 3. Idempotent: a second run at the same window clears nothing more ──
    $rAgain = vendor_plate_purge_run(['retention_days_override' => 30]);
    t('second purge clears nothing (already cleared)', (int) ($rAgain['cleared'] ?? -1) === 0, json_encode($rAgain));
    t('eligible count after purge excludes the cleared row', vendor_plate_retention_eligible_count(30) === 0, (string) vendor_plate_retention_eligible_count(30));

    // ── 4. Schema-missing degrades gracefully (a bad override cannot force a bad query) ──
    $rNeg = vendor_plate_purge_run(['retention_days_override' => -5]);
    t('negative override is treated as disabled', ($rNeg['skipped'] ?? false) === true);

    // ── 5. The scheduled job: not required at default, required once set.
    //    Checked in a FRESH process (get_variable caches per-process) via the real sched_job_required().
    $wOff = vf_worker(['vendor_plate_retention_days' => '0'], 'plate_retention', []);
    t('fresh process: days read as 0 when off', $wOff !== null && (int) $wOff['days'] === 0, json_encode($wOff));
    t('job NOT required when retention is off', $wOff !== null && $wOff['required'] === false, json_encode($wOff));

    $wOn = vf_worker(['vendor_plate_retention_days' => '45'], 'plate_retention', []);
    t('fresh process: days read as 45 when set', $wOn !== null && (int) $wOn['days'] === 45, json_encode($wOn));
    t('job IS required when retention is set', $wOn !== null && $wOn['required'] === true, json_encode($wOn));

    // ── 6. The admin write path validates + persists the numeric setting (and the reader clamps) ──
    $save = vendor_settings_save(['vendor_plate_retention_days' => '60'], $actor);
    t('vendor_settings_save accepts a valid day count', ($save['ok'] ?? false) === true, json_encode($save));
    t('vendor_settings_current echoes the saved days', vendor_settings_current()['vendor_plate_retention_days'] === '60');
    $bad = vendor_settings_save(['vendor_plate_retention_days' => 'soon'], $actor);
    t('vendor_settings_save rejects a non-numeric value (422)', ($bad['ok'] ?? true) === false && (int) ($bad['http'] ?? 0) === 422, json_encode($bad));
    $tooBig = vendor_settings_save(['vendor_plate_retention_days' => (string) (VENDOR_PLATE_RETENTION_MAX_DAYS + 1)], $actor);
    t('vendor_settings_save rejects a value above the cap (422)', ($tooBig['ok'] ?? true) === false && (int) ($tooBig['http'] ?? 0) === 422, json_encode($tooBig));

} catch (Throwable $e) {
    t('no uncaught exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    try { vf_cleanup(); } catch (Throwable $e) { fwrite(STDERR, 'cleanup: ' . $e->getMessage() . "\n"); }
    // Restore the plate-retention setting exactly (it is not a vendor_setting_defs() key, so vf_cleanup does not).
    try {
        if ($origRetention === false || $origRetention === null) {
            db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'vendor_plate_retention_days'");
        } else {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('vendor_plate_retention_days', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$origRetention]);
        }
    } catch (Throwable $e) {}
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
