<?php
/**
 * Migration: starter `teams` rows (QA hardening, 2026-07-07).
 *
 * The four starter teams were supposed to come from sql/membership.sql's
 * INSERT IGNORE — but install_fresh.php imports that file BEFORE the
 * run_member_columns.php migration adds the modern columns (name,
 * description, team_type, active) to the legacy v3.44 teams table, so on
 * a truly fresh install the INSERT failed on "unknown column" and teams
 * stayed empty. (Pre-2026-07-07 the accidental fix was that a SECOND
 * install_fresh run re-imported membership.sql after the columns
 * existed; the import-marker change removed that re-import, exposing
 * the ordering bug.)
 *
 * This migration seeds the same four teams, running AFTER
 * run_member_columns.php (lexicographic runner order 'm' < 't'), and is
 * schema-aware: it fills whichever legacy NOT-NULL columns (team,
 * sub-group, ttypes_id, mission, leader, leader_dpty, by, from, on)
 * exist on this install.
 *
 * Idempotent — seeds ONLY when the teams table has zero rows, so
 * operator-managed teams are never touched.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}teams`");
    if ($count > 0) {
        echo "[OK] teams already has {$count} row(s) — seed skipped\n";
        exit(0);
    }

    $cols = array_column(db_fetch_all("SHOW COLUMNS FROM `{$prefix}teams`"), 'Field');

    // Never INSERT into generated columns — on training, `name` is
    // GENERATED ALWAYS AS (`team`) VIRTUAL and writing to it errors
    // (1906). Filter them out; the value flows in via the base column.
    $genCols = array_column(db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            AND EXTRA LIKE '%GENERATED%'",
        [$prefix . 'teams']), 'COLUMN_NAME');
    $cols = array_values(array_diff($cols, $genCols));

    $starterTeams = [
        ['Alpha Team',     'Primary response team',              'General'],
        ['Bravo Team',     'Secondary response team',            'General'],
        ['Medical Unit',   'Medical response specialists',       'Medical'],
        ['Communications', 'Radio and communications operators', 'RACES'],
    ];

    // Legacy v3.44 NOT-NULL columns without defaults — fill when present.
    $legacyDefaults = [
        'team'        => null,      // mirrored from the modern name (set per row)
        'sub-group'   => '',
        'ttypes_id'   => 0,
        'mission'     => '',
        'leader'      => 0,
        'leader_dpty' => 0,
        'by'          => 0,
        'from'        => 'install',
    ];

    $seeded = 0;
    foreach ($starterTeams as [$name, $description, $teamType]) {
        $fields = [];
        $values = [];
        if (in_array('name', $cols, true))        { $fields['name']        = $name; }
        if (in_array('description', $cols, true)) { $fields['description'] = $description; }
        if (in_array('team_type', $cols, true))   { $fields['team_type']   = $teamType; }
        if (in_array('active', $cols, true))      { $fields['active']      = 1; }
        foreach ($legacyDefaults as $col => $default) {
            if (in_array($col, $cols, true)) {
                $fields[$col] = ($col === 'team') ? substr($name, 0, 48) : $default;
            }
        }

        $colSql = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($fields)));
        $ph     = implode(', ', array_fill(0, count($fields), '?'));
        // Legacy `on` (datetime NOT NULL) handled inline with NOW().
        if (in_array('on', $cols, true)) {
            db_query(
                "INSERT INTO `{$prefix}teams` ({$colSql}, `on`) VALUES ({$ph}, NOW())",
                array_values($fields)
            );
        } else {
            db_query(
                "INSERT INTO `{$prefix}teams` ({$colSql}) VALUES ({$ph})",
                array_values($fields)
            );
        }
        $seeded++;
    }
    echo "[OK] seeded {$seeded} starter teams\n";
} catch (Exception $e) {
    echo "[FAIL] teams seed: " . $e->getMessage() . "\n";
    exit(1);
}
