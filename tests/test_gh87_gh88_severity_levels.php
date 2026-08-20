<?php
/**
 * GH#87 / GH#88 (2026-08-19) — configurable severity scale.
 *
 * GH#87: New Incident's auto-set-severity-from-incident-type showed one
 * severity level on screen (assets/js/new-incident.js remapped
 * in_types.set_severity as a legacy 1-5 scale down to a 0-2 dropdown) and
 * saved a DIFFERENT one (inc/incident-write.php / api/incident-create.php
 * both read the same column straight through as 0-2, no mapping). This
 * file proves the fix through the REAL writer (incident_create_internal())
 * — not a hand-seeded reproduction — and proves the related, previously
 * unreproduced concern (an out-of-range set_severity reaching
 * ticket.severity unclamped) is also closed.
 *
 * GH#88: the severity scale (level count, labels, colors) is now
 * admin-configurable via the severity_levels table (inc/severity.php) +
 * api/config-admin.php?section=severity_levels. This file exercises the
 * extracted, directly-callable writers (severity_level_create/update/delete)
 * that endpoint calls — not a copy of its SQL — per this project's
 * OT_CONFIG_LIBRARY_ONLY convention (CLAUDE.md pitfall entry).
 *
 * All test fixtures use a __GH8788_ prefix and are cleaned up at the end
 * regardless of outcome. This suite does NOT assume the severity_levels
 * table is empty — it shares the table with the install's real configured
 * scale (seeded 0/1/2 by sql/run_severity_levels.php) and only adds/removes
 * its own throwaway rows on top.
 *
 * Usage: php tests/test_gh87_gh88_severity_levels.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/severity.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/_test_admin.php';

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();

$pass = 0; $fail = 0;
function g8788(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#87/GH#88: configurable severity scale ===\n\n";

$typeIds = [];
$ticketIds = [];
$sevLevelIds = [];

try {
    // ── 1. Schema ────────────────────────────────────────────────────
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'severity_levels']
    );
    $colNames = array_map(function ($c) { return $c['COLUMN_NAME']; }, $cols);
    foreach (['id', 'value', 'label', 'color', 'sort_order', 'is_default', 'is_high_alert'] as $need) {
        g8788("severity_levels has column `$need`", in_array($need, $colNames, true));
    }

    // ── 2. Default seed matches the historical scale (0/1/2) ───────────
    $levels = severity_levels_load(true);
    g8788('severity_levels_load() returns at least 3 levels (the historical default)', count($levels) >= 3,
        'got ' . count($levels));
    $byValue = [];
    foreach ($levels as $l) $byValue[$l['value']] = $l;
    g8788('value 0 is configured', isset($byValue[0]));
    g8788('value 1 is configured', isset($byValue[1]));
    g8788('value 2 is configured', isset($byValue[2]));
    if (isset($byValue[0])) g8788('value 0 is the default level', $byValue[0]['is_default'] === true);
    if (isset($byValue[2])) g8788('value 2 is flagged high-alert (matches pre-existing severity>=2 threshold)', $byValue[2]['is_high_alert'] === true);

    // ── 3. Helper functions ─────────────────────────────────────────────
    $validValues = severity_valid_values();
    g8788('severity_valid_values() is non-empty', count($validValues) > 0);
    $defaultVal = severity_default_value();
    g8788('severity_default_value() is a currently-valid value', in_array($defaultVal, $validValues, true));

    g8788('severity_clamp() passes through an already-valid value', severity_clamp($validValues[0]) === $validValues[0]);
    $bogus = 987654; // guaranteed not a configured value
    g8788('severity_clamp() falls back to the default for an invalid value (GH#87 unclamped-override fix)',
        severity_clamp($bogus) === $defaultVal);
    g8788('severity_clamp() falls back to the default for a non-numeric string',
        severity_clamp('not-a-number') === $defaultVal);

    g8788('severity_label(0) is a non-empty string', is_string(severity_label(0)) && severity_label(0) !== '');
    g8788('severity_label() on an unconfigured value returns "Unknown", not a crash',
        severity_label($bogus) === 'Unknown');
    g8788('severity_color(0) looks like a hex color', (bool) preg_match('/^#[0-9a-fA-F]{6}$/', severity_color(0)));

    $labelMap = severity_label_map();
    $colorMap = severity_color_map();
    g8788('severity_label_map() has an entry for every configured value',
        count(array_diff($validValues, array_keys($labelMap))) === 0);
    g8788('severity_color_map() has an entry for every configured value',
        count(array_diff($validValues, array_keys($colorMap))) === 0);

    // ── 4. GH#87 — reproduce the mismatch fix through the REAL writer ──
    // Add a throwaway 4th severity level (proves the scale is genuinely
    // extensible past the historical 3 — the whole point of GH#88 — and
    // that a level beyond 0-2 auto-sets correctly, which the OLD 1-5-to-
    // 0-2 remapping in new-incident.js could never have represented).
    $newLevel = severity_level_create('__GH8788_ Test Level', '#123456', 99, false, false, $adminId, 'test');
    g8788('severity_level_create() succeeds', $newLevel['ok'] === true, $newLevel['error'] ?? '');
    if ($newLevel['ok']) {
        $sevLevelIds[] = $newLevel['id'];
        $newValue = $newLevel['value'];
        g8788('created level got a value beyond the historical 0-2 range', $newValue > 2, "got {$newValue}");

        // Incident type auto-sets severity to the new level.
        db_query(
            "INSERT INTO `{$prefix}in_types` (`type`, `description`, `protocol`, `set_severity`, `sort`)
             VALUES (?, ?, ?, ?, ?)",
            ['__GH8788_ Type AutoSet', 'GH#87/88 test type', 'n/a', $newValue, 999]
        );
        $typeAutoId = (int) db_insert_id();
        $typeIds[] = $typeAutoId;

        // Drive the REAL writer — incident_create_internal(), the exact
        // function api/incident-create.php and api/external/v1/incidents.php
        // both call. No hand-seeded ticket row.
        $result = incident_create_internal([
            'in_types_id' => $typeAutoId,
            'scope'       => '__GH8788_ auto-set severity test',
            'severity'    => 0, // dispatcher's manual pick — the type override must WIN
        ], $adminId);
        g8788('incident_create_internal() succeeded for the auto-set-severity case', empty($result['errors']), json_encode($result['errors'] ?? []));
        if (!empty($result['id'])) {
            $ticketIds[] = (int) $result['id'];
            $stored = (int) db_fetch_value("SELECT `severity` FROM `{$prefix}ticket` WHERE `id` = ?", [$result['id']]);
            // THE GH#87 ASSERTION: the value actually written to ticket.severity
            // is EXACTLY the type's configured set_severity — no 1-5-to-0-2
            // remapping, no silent clamp-to-a-different-level. Client and
            // server now read the exact same severity_levels domain.
            g8788('GH#87 FIX: ticket.severity equals the incident type\'s configured set_severity exactly (no scale remap)',
                $stored === $newValue, "expected {$newValue}, got {$stored}");
            g8788('the label a dispatcher would see for the stored value matches the type\'s configured level',
                severity_label($stored) === '__GH8788_ Test Level');
        }
    }

    // ── 5. GH#87 (related, previously unreproduced) — an out-of-range
    //      set_severity must never reach ticket.severity unclamped ──────
    db_query(
        "INSERT INTO `{$prefix}in_types` (`type`, `description`, `protocol`, `set_severity`, `sort`)
         VALUES (?, ?, ?, ?, ?)",
        ['__GH8788_ Type BadSeverity', 'GH#87 unclamped-override regression test', 'n/a', 555555, 999]
    );
    $typeBadId = (int) db_insert_id();
    $typeIds[] = $typeBadId;

    $result2 = incident_create_internal([
        'in_types_id' => $typeBadId,
        'scope'       => '__GH8788_ unclamped severity test',
    ], $adminId);
    g8788('incident_create_internal() succeeded for the out-of-range-set_severity case', empty($result2['errors']), json_encode($result2['errors'] ?? []));
    if (!empty($result2['id'])) {
        $ticketIds[] = (int) $result2['id'];
        $stored2 = (int) db_fetch_value("SELECT `severity` FROM `{$prefix}ticket` WHERE `id` = ?", [$result2['id']]);
        g8788('GH#87 FIX: an out-of-range set_severity (555555) never reaches ticket.severity unclamped',
            $stored2 !== 555555, "ticket.severity was written as 555555 — unclamped, past every label map");
        g8788('an out-of-range set_severity clamps to the configured default instead',
            $stored2 === severity_default_value(), "expected default (" . severity_default_value() . "), got {$stored2}");
    }

    // ── 6. is_high_alert flag (GH#88 — replaces hardcoded `severity >= 2`) ──
    g8788('severity_is_high_alert() is true for the level flagged high-alert (value=2)',
        severity_is_high_alert(2) === true);
    g8788('severity_is_high_alert() is false for a level not flagged high-alert (value=0)',
        severity_is_high_alert(0) === false);
    g8788('severity_is_high_alert() is false for an unconfigured value (fails closed, not a crash)',
        severity_is_high_alert($bogus) === false);

    // ── 7. Admin CRUD — severity_level_update() ─────────────────────────
    if (!empty($sevLevelIds)) {
        $updId = $sevLevelIds[0];
        $upd = severity_level_update($updId, '__GH8788_ Renamed', '#abcdef', 50, false, true, $adminId, 'test');
        g8788('severity_level_update() succeeds', $upd['ok'] === true, $upd['error'] ?? '');
        $after = db_fetch_one("SELECT * FROM `{$prefix}severity_levels` WHERE `id` = ?", [$updId]);
        g8788('update persisted the new label', $after && $after['label'] === '__GH8788_ Renamed');
        g8788('update persisted the new color', $after && strtolower($after['color']) === '#abcdef');
        g8788('update persisted is_high_alert=1', $after && (int) $after['is_high_alert'] === 1);
        g8788("update did NOT change the immutable value (still {$newValue})",
            $after && (int) $after['value'] === $newValue);
    }

    // ── 8. Admin CRUD — is_default enforcement (exactly one row) ────────
    $secondLevel = severity_level_create('__GH8788_ Second Level', '#654321', 100, true, false, $adminId, 'test');
    g8788('second severity_level_create() succeeds', $secondLevel['ok'] === true, $secondLevel['error'] ?? '');
    if ($secondLevel['ok']) {
        $sevLevelIds[] = $secondLevel['id'];
        $defaultCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}severity_levels` WHERE `is_default` = 1");
        g8788('exactly one severity level is flagged is_default after creating a new default',
            $defaultCount === 1, "found {$defaultCount}");
        $isThisOneDefault = (int) db_fetch_value("SELECT `is_default` FROM `{$prefix}severity_levels` WHERE `id` = ?", [$secondLevel['id']]);
        g8788('the newly-created level is the one now flagged default', $isThisOneDefault === 1);

        // Put the default back on value 0 so the rest of this run (and
        // any other test/session sharing this DB) sees the install's real
        // configured default, not a throwaway fixture.
        $origDefaultRow = db_fetch_one("SELECT `id` FROM `{$prefix}severity_levels` WHERE `value` = 0");
        if ($origDefaultRow) {
            severity_level_update((int) $origDefaultRow['id'], $byValue[0]['label'], $byValue[0]['color'], $byValue[0]['sort_order'], true, $byValue[0]['is_high_alert'], $adminId, 'test');
        }
    }

    // ── 9. Admin CRUD — delete guards ───────────────────────────────────
    // 9a. Cannot delete a level a ticket/type currently references.
    if (!empty($sevLevelIds) && !empty($typeIds)) {
        $referencedDel = severity_level_delete($sevLevelIds[0]);
        g8788('severity_level_delete() refuses a level referenced by an incident type/ticket',
            $referencedDel['ok'] === false);
        g8788('the refusal names the reference count', strpos((string) $referencedDel['error'], 'currently use') !== false,
            $referencedDel['error'] ?? '(no error message)');
    }
    // 9b. Cannot delete the level currently flagged default.
    if (isset($secondLevel) && $secondLevel['ok']) {
        // secondLevel is no longer default (we reset it above) — flag it
        // default again just to exercise this specific guard, then clear.
        severity_level_update($secondLevel['id'], '__GH8788_ Second Level', '#654321', 100, true, false, $adminId, 'test');
        $defBlockedDel = severity_level_delete($secondLevel['id']);
        g8788('severity_level_delete() refuses to delete the current default level',
            $defBlockedDel['ok'] === false && strpos((string) $defBlockedDel['error'], 'default') !== false,
            $defBlockedDel['error'] ?? '(no error message)');
        // Un-default it so it can actually be cleaned up below.
        severity_level_update($secondLevel['id'], '__GH8788_ Second Level', '#654321', 100, false, false, $adminId, 'test');
        $origDefaultRow2 = db_fetch_one("SELECT `id` FROM `{$prefix}severity_levels` WHERE `value` = 0");
        if ($origDefaultRow2) {
            severity_level_update((int) $origDefaultRow2['id'], $byValue[0]['label'], $byValue[0]['color'], $byValue[0]['sort_order'], true, $byValue[0]['is_high_alert'], $adminId, 'test');
        }
    }
    // 9c. An unreferenced, non-default level CAN be deleted.
    if (isset($secondLevel) && $secondLevel['ok']) {
        $cleanDel = severity_level_delete($secondLevel['id']);
        g8788('severity_level_delete() succeeds for an unreferenced, non-default level',
            $cleanDel['ok'] === true, $cleanDel['error'] ?? '');
        if ($cleanDel['ok']) {
            // Already gone — don't try to clean it up again below.
            $sevLevelIds = array_diff($sevLevelIds, [$secondLevel['id']]);
            $stillThere = db_fetch_one("SELECT `id` FROM `{$prefix}severity_levels` WHERE `id` = ?", [$secondLevel['id']]);
            g8788('the deleted level row is actually gone', $stillThere === false || $stillThere === null);
        }
    }

    // ── 10. api/severity-levels.php's query shape (mirrors GH#68's
    //      insurance-types test convention — not HTTP-driven, same
    //      SELECT the endpoint runs) ─────────────────────────────────
    $readOnlyRows = severity_levels_for_json();
    g8788('severity_levels_for_json() returns rows shaped for the read-only API (value/label/color present)',
        !empty($readOnlyRows) && isset($readOnlyRows[0]['value'], $readOnlyRows[0]['label'], $readOnlyRows[0]['color']));

    // ── 11. GH#87 regression guard — the old client-side 1-5-to-0-2 remap
    //      must never come back ─────────────────────────────────────────
    $jsPath = __DIR__ . '/../assets/js/new-incident.js';
    $js = file_exists($jsPath) ? file_get_contents($jsPath) : '';
    g8788('new-incident.js no longer contains the old 1-5-to-0-2 severity remap',
        strpos($js, 'autoSev <= 1 ? 0 : (autoSev <= 3 ? 1 : 2)') === false);
    g8788('new-incident.js populates the Severity dropdown from severity_levels (populateSeverities)',
        strpos($js, 'function populateSeverities') !== false);
    g8788('new-incident.js\'s auto-severity handler sets the dropdown directly from the configured value',
        strpos($js, "document.getElementById('severity').value = autoSev;") !== false);

} catch (Exception $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    $fail++;
}

// ── Cleanup ──────────────────────────────────────────────────────────────
try {
    foreach ($ticketIds as $tid) {
        db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$tid]);
    }
    foreach ($typeIds as $tyid) {
        db_query("DELETE FROM `{$prefix}in_types` WHERE `id` = ?", [$tyid]);
    }
    foreach ($sevLevelIds as $sid) {
        db_query("DELETE FROM `{$prefix}severity_levels` WHERE `id` = ?", [$sid]);
    }
    severity_levels_reset_cache();
} catch (Exception $e) {
    fwrite(STDERR, "CLEANUP WARNING: " . $e->getMessage() . "\n");
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
