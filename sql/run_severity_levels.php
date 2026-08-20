<?php
/**
 * GH#87 / GH#88 (2026-08-19) — configurable severity scale.
 *
 * GH#87: the New Incident form's auto-set-severity-from-incident-type
 * showed one severity level on screen and saved a different one.
 * assets/js/new-incident.js treated `in_types.set_severity` as a legacy
 * 1-5 scale and mapped it down to the 0-2 dropdown; inc/incident-write.php
 * and api/incident-create.php both read the same column straight through
 * as 0-2 with no mapping at all. Two independently-hardcoded readings of
 * one column, disagreeing on 33 of 37 real incident types, in the
 * direction that under-reports urgency to the dispatcher.
 *
 * GH#88: a reporter asked whether the severity scale (level count,
 * labels) could become configurable instead of a fixed three levels —
 * Eric's answer: yes, build it.
 *
 * This migration creates the `severity_levels` table — the single
 * source of truth both the client and the server now read from, which
 * fixes GH#87 by construction (there is only one scale definition, not
 * two independently-hardcoded ones) and gives GH#88 its configurable
 * level count / labels / colors. See inc/severity.php for the full
 * design rationale (value immutability, sort_order vs. value,
 * is_high_alert vs. a hardcoded `>= 2`).
 *
 * NO EXISTING DATA IS TOUCHED. `ticket.severity` and `in_types.set_severity`
 * are not read, written, or reinterpreted by this script — it only adds a
 * new lookup table, seeded so its three default rows (value 0/1/2) mean
 * exactly what those two integers have always meant in this codebase
 * (Normal / Elevated / Critical, matching new-incident.php's dropdown —
 * see GH#88's own investigation for why that scheme was picked as the
 * canonical default over the "Low/Medium/High" and "Normal/Medium/High"
 * spellings scattered across other screens; those screens are updated in
 * the same change to read from this table instead of their own copy).
 *
 * The seed also finally activates `sev_0_label` / `sev_1_label` /
 * `sev_2_label` — GH#88 found these Settings inputs saved but were read
 * by nothing (only their `sev_N_color` siblings were wired). If an admin
 * had already typed a label into that dead field, we honor it here as
 * the level's starting label rather than discarding the attempt.
 *
 * Idempotent — safe to re-run. Wired into the ongoing migration runner
 * (sql/run_migrations.php auto-discovers every sql/run_*.php file).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH#87/GH#88 — severity_levels table\n";
echo "====================================\n\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}severity_levels` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `value`         INT NOT NULL COMMENT 'the integer stored in ticket.severity / in_types.set_severity; assigned once, immutable',
        `label`         VARCHAR(30) NOT NULL,
        `color`         VARCHAR(7) NOT NULL DEFAULT '#6c757d',
        `sort_order`    INT NOT NULL DEFAULT 0,
        `is_default`    TINYINT(1) NOT NULL DEFAULT 0,
        `is_high_alert` TINYINT(1) NOT NULL DEFAULT 0,
        `_by`           INT NULL,
        `_from`         VARCHAR(45) NULL,
        `_on`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_severity_value` (`value`),
        KEY `idx_sort_order` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] severity_levels table ready.\n";
} catch (Exception $e) {
    echo "[FATAL] could not create severity_levels: " . $e->getMessage() . "\n";
    if (defined('_INCLUDED_FROM_INSTALLER')) return;
    exit(1);
}

// ── Seed default 3 levels, ONLY if the table is currently empty ──
// A non-empty table means either a prior run of this script already
// seeded it, or an admin has already configured their own scale —
// either way, never overwrite.
try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}severity_levels`");
} catch (Exception $e) {
    $count = 0;
}

if ($count === 0) {
    // Honor a previously-saved-but-never-read sev_N_label / sev_N_color
    // settings value if present (see docblock above); otherwise fall
    // back to the exact hardcoded defaults new-incident.php has always
    // shipped with.
    $defaults = [
        ['value' => 0, 'fallback_label' => 'Normal',   'fallback_color' => '#00ff00', 'is_default' => 1, 'is_high_alert' => 0],
        ['value' => 1, 'fallback_label' => 'Elevated', 'fallback_color' => '#ffff00', 'is_default' => 0, 'is_high_alert' => 0],
        ['value' => 2, 'fallback_label' => 'Critical', 'fallback_color' => '#ff0000', 'is_default' => 0, 'is_high_alert' => 1],
    ];

    $sortOrder = 0;
    foreach ($defaults as $d) {
        $label = $fallbackLabel = $d['fallback_label'];
        $color = $fallbackColor = $d['fallback_color'];

        try {
            if (function_exists('get_variable')) {
                $savedLabel = get_variable('sev_' . $d['value'] . '_label');
                if (is_string($savedLabel) && trim($savedLabel) !== '') {
                    $label = trim($savedLabel);
                }
                $savedColor = get_variable('sev_' . $d['value'] . '_color');
                if (is_string($savedColor) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($savedColor))) {
                    $color = trim($savedColor);
                }
            }
        } catch (Exception $e) {
            // settings table missing/unreadable — fall back to hardcoded defaults
            $label = $fallbackLabel;
            $color = $fallbackColor;
        }

        try {
            db_query(
                "INSERT INTO `{$prefix}severity_levels`
                    (`value`, `label`, `color`, `sort_order`, `is_default`, `is_high_alert`)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$d['value'], $label, $color, $sortOrder, $d['is_default'], $d['is_high_alert']]
            );
            echo "  [OK] seeded value={$d['value']} label=\"{$label}\" color={$color}\n";
        } catch (Exception $e) {
            echo "  [ERR] could not seed value={$d['value']}: " . $e->getMessage() . "\n";
        }
        $sortOrder++;
    }
} else {
    echo "[OK] severity_levels already has {$count} row(s) — leaving as-is.\n";
}

echo "\nDone.\n";
