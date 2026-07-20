<?php
/**
 * Phase 15 (2026-06-11) — Incident-number template + storage column.
 *
 * - Adds `ticket.incident_number VARCHAR(64) NULL` so each row carries
 *   its rendered case number (e.g., "INC-2026-0042"). Indexed for
 *   the lookups by-number that the future search UI will want.
 * - Adds the `incident_number_template` setting (default `{YY}-{NNNN}`).
 * - Adds the `incident_number_sequence` setting (default `1`).
 * - Migrates the legacy `_inc_num` config into the new template if
 *   the legacy row exists. The legacy 4-mode config encoded the
 *   intent as a few fields; we render it back into the equivalent
 *   template here so admins don't lose their prior numbering.
 *
 * Idempotent: information-schema guarded for the column; INSERT
 * IGNORE for the settings rows.
 *
 * Usage: php sql/run_phase15_incident_number_template.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 15 — Incident-number template + storage\n";
echo "=============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. ticket.incident_number column ────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'incident_number'",
        [$prefix . 'ticket']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}ticket`
             ADD COLUMN `incident_number` VARCHAR(64) NULL
             COMMENT 'Rendered case number from the configured template (Phase 15)',
             ADD KEY `idx_incident_number` (`incident_number`)"
        );
        echo "[OK] Added ticket.incident_number column + index\n";
    } else {
        echo "[OK] ticket.incident_number already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] ticket.incident_number: " . $e->getMessage() . "\n";
}

// ── 2. Template setting ─────────────────────────────────────────────────
// If the legacy _inc_num config exists, translate it to a template
// before falling back to the default.
$legacyTemplate = null;
try {
    $raw = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?",
        ['_inc_num']
    );
    if ($raw) {
        $decoded = @base64_decode($raw);
        $arr = $decoded !== false ? @unserialize($decoded) : false;
        if (is_array($arr)) {
            // Legacy shape: [mode, label, separator, next_number, append_nature, year]
            //   mode 0 = disabled            → no template (skip migration)
            //   mode 1 = pure sequence       → "{NNNN}"
            //   mode 2 = label + sequence    → "{label}{sep}{NNNN}"
            //   mode 3 = year prefix         → "{YY}{sep}{NNNN}"
            $mode  = (int) ($arr[0] ?? 0);
            $label = (string) ($arr[1] ?? '');
            $sep   = (string) ($arr[2] ?? '-');
            switch ($mode) {
                case 1: $legacyTemplate = '{NNNN}'; break;
                case 2: $legacyTemplate = $label . $sep . '{NNNN}'; break;
                case 3: $legacyTemplate = '{YY}' . $sep . '{NNNN}'; break;
            }
            if ($legacyTemplate) {
                echo "[OK] Translated legacy _inc_num (mode={$mode}) → template: '{$legacyTemplate}'\n";
            }
        }
    }
} catch (Exception $e) {
    // Legacy migration is best-effort; default template covers users who never set _inc_num.
}

$defaultTemplate = $legacyTemplate ?: '{YY}-{NNNN}';
try {
    $existing = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_template' LIMIT 1"
    );
    if ($existing === null || $existing === false || $existing === '') {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_template', ?)",
            [$defaultTemplate]
        );
        echo "[OK] Seeded incident_number_template = '{$defaultTemplate}'\n";
    } else {
        echo "[OK] incident_number_template already set to '{$existing}'\n";
    }
} catch (Exception $e) {
    echo "[WARN] template seed: " . $e->getMessage() . "\n";
}

// ── 3. Sequence counter ─────────────────────────────────────────────────
$startingSeq = 1;
try {
    // If we migrated from a legacy config with a next_number, use it.
    if ($raw) {
        $decoded = @base64_decode($raw);
        $arr = $decoded !== false ? @unserialize($decoded) : false;
        if (is_array($arr) && isset($arr[3])) {
            $startingSeq = max(1, (int) $arr[3]);
        }
    }
} catch (Exception $e) {}

try {
    $existing = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_sequence' LIMIT 1"
    );
    if ($existing === null || $existing === false || $existing === '') {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_sequence', ?)",
            [(string) $startingSeq]
        );
        echo "[OK] Seeded incident_number_sequence = {$startingSeq}\n";
    } else {
        echo "[OK] incident_number_sequence already set to {$existing}\n";
    }
} catch (Exception $e) {
    echo "[WARN] sequence seed: " . $e->getMessage() . "\n";
}

// ── 4. Report ──────────────────────────────────────────────────────────
echo "\nFinal state:\n";
try {
    $tpl = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_template'");
    $seq = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_sequence'");
    echo "  template: {$tpl}\n";
    echo "  next seq: {$seq}\n";
} catch (Exception $e) {}

echo "\nDone.\n";
