<?php
/**
 * Phase 15b (2026-06-11) — Incident-number period reset.
 *
 * Eric asked: when the year/month changes, does the sequence
 * reset? Answer in Phase 15: no — it just keeps incrementing.
 * Almost no agency wants that. The first incident of 2027 should
 * be "27-0001", not "27-4288" (continuation from 2026).
 *
 * This migration adds:
 *
 *   - `incident_number_reset_mode` setting
 *       Values: 'never' | 'yearly' | 'monthly' | 'daily'
 *       Default: 'yearly' (matches fire/EMS agency convention).
 *
 *   - `incident_number_period` setting
 *       Stores the period (e.g., '2026', '2026-06', '2026-06-11')
 *       in which the current sequence counter is valid. When the
 *       allocator sees a different period, it resets the sequence
 *       to 1.
 *
 * The mode is computed automatically (or admin can override). It's
 * best-effort smart-suggested from the template — if the template
 * contains {YY} or {YYYY}, yearly is the natural default; if it
 * contains {MM}, monthly; if it contains {DD} or {JJJ}, daily.
 *
 * Idempotent: settings rows are guarded by SELECT-before-INSERT;
 * the period seed reflects the CURRENT date so the first
 * post-migration allocation doesn't trigger a spurious reset.
 *
 * Usage: php sql/run_phase15b_incident_number_reset_mode.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 15b — Incident-number period reset\n";
echo "========================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── reset_mode ─────────────────────────────────────────────────────────
// Smart default: read the current template; if it contains a date
// token, lean toward yearly. Admin can change later.
$defaultMode = 'yearly';
try {
    $tpl = (string) db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_template' LIMIT 1"
    );
    if ($tpl !== '') {
        if (strpos($tpl, '{DD}')  !== false || strpos($tpl, '{JJJ}') !== false) {
            $defaultMode = 'daily';
        } elseif (strpos($tpl, '{MM}') !== false) {
            $defaultMode = 'monthly';
        } elseif (strpos($tpl, '{YY}') !== false || strpos($tpl, '{YYYY}') !== false) {
            $defaultMode = 'yearly';
        } else {
            // No date token in template — sequence is the only thing
            // distinguishing incidents, so resetting would create
            // duplicate identifiers. Force 'never'.
            $defaultMode = 'never';
        }
    }
} catch (Exception $e) {
    // settings table missing — treat as fresh install.
}

try {
    $existing = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_reset_mode' LIMIT 1"
    );
    if ($existing === null || $existing === false || $existing === '') {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_reset_mode', ?)",
            [$defaultMode]
        );
        echo "[OK] Seeded incident_number_reset_mode = '{$defaultMode}' (auto-detected from template)\n";
    } else {
        echo "[OK] incident_number_reset_mode already set to '{$existing}'\n";
    }
} catch (Exception $e) {
    echo "[WARN] reset_mode seed: " . $e->getMessage() . "\n";
}

// ── current period ─────────────────────────────────────────────────────
// Seed with the current period so the very first allocation after
// this migration doesn't trigger a spurious reset. Use the mode we
// just decided.
$mode = (string) db_fetch_value(
    "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_reset_mode' LIMIT 1"
);
$periodKey = '0';
switch ($mode) {
    case 'yearly':  $periodKey = date('Y');       break;
    case 'monthly': $periodKey = date('Y-m');     break;
    case 'daily':   $periodKey = date('Y-m-d');   break;
    case 'never':   default: $periodKey = '0';    break;
}

try {
    $existing = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_period' LIMIT 1"
    );
    if ($existing === null || $existing === false || $existing === '') {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)",
            [$periodKey]
        );
        echo "[OK] Seeded incident_number_period = '{$periodKey}'\n";
    } else {
        echo "[OK] incident_number_period already set to '{$existing}'\n";
    }
} catch (Exception $e) {
    echo "[WARN] period seed: " . $e->getMessage() . "\n";
}

echo "\nFinal state:\n";
try {
    $m = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_reset_mode'");
    $p = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_period'");
    echo "  reset_mode: {$m}\n";
    echo "  period:     {$p}\n";
} catch (Exception $e) {}

echo "\nDone.\n";
