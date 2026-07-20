<?php
/**
 * NewUI v4.0 — Schema self-healing helpers
 *
 * Phase 102 (a beta tester beta 2026-07-01) — extracted the fixLegacyDefaults()
 * pattern from api/members.php into a shared helper so other endpoints
 * that insert into audit-columned tables (fac_types, facilities,
 * warnings, etc.) don't need to duplicate the same MySQL strict-mode
 * workaround inline.
 *
 * The problem it solves:
 *   * Fresh installs from the base_schema.sql have `_by / _on / _from`
 *     legacy audit columns declared NOT NULL without a default.
 *   * MySQL 8.0 + MariaDB with STRICT_TRANS_TABLES reject INSERTs that
 *     omit these columns → user sees "Field '_by' doesn't have a
 *     default value". (a beta tester hit this on fac_types the moment he
 *     tried to add a Facility Type in Settings on a fresh install.)
 *   * Older installs with the same tables migrated in from v3.44 may
 *     also lack columns our newer INSERTs reference — the classic
 *     "Unknown column 'contact' in INSERT INTO" symptom.
 *
 * Two helpers:
 *   heal_legacy_defaults($table)       — relax the legacy audit cols
 *   present_cols_only($table, $cols)   — filter to columns that exist
 *
 * Callers wrap their INSERT in the pattern:
 *
 *     $cols = ['name', 'description', 'contact', 'hide'];
 *     $vals = [$name, $desc, $contact, $hide];
 *     [$cols, $vals] = present_cols_only($table, $cols, $vals);
 *     try {
 *         $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $cols)
 *              . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")";
 *         db_query($sql, $vals);
 *     } catch (Exception $e) {
 *         heal_legacy_defaults($table);
 *         db_query($sql, $vals);  // retry once
 *     }
 */

declare(strict_types=1);

if (!function_exists('heal_legacy_defaults')) {
    /**
     * Add DEFAULTs to legacy NOT-NULL audit columns so future INSERTs
     * that don't populate them succeed under STRICT mode. Type-aware:
     * numeric → 0, datetime → NULL, text → ''. Only touches columns
     * matching the legacy audit pattern OR extras supplied by caller.
     *
     * @param string $table Full table name (with prefix already applied).
     * @param array<string> $extraCols Additional column-name literals to relax.
     */
    function heal_legacy_defaults(string $table, array $extraCols = []): void
    {
        $table = trim($table, '` ');
        try {
            $params = [$table];
            $extraCond = '';
            if (!empty($extraCols)) {
                $placeholders = rtrim(str_repeat('?,', count($extraCols)), ',');
                $extraCond = " OR COLUMN_NAME IN ({$placeholders})";
                $params = array_merge($params, $extraCols);
            }
            $cols = db_fetch_all(
                "SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND IS_NULLABLE = 'NO'
                   AND COLUMN_DEFAULT IS NULL
                   AND EXTRA NOT LIKE '%auto_increment%'
                   AND (
                       COLUMN_NAME REGEXP '^field[0-9]+\$'
                       OR COLUMN_NAME IN ('_by', '_on', '_from')
                       {$extraCond}
                   )",
                $params
            );
            foreach ($cols as $col) {
                try {
                    $name  = $col['COLUMN_NAME'];
                    $dtype = strtolower((string) $col['DATA_TYPE']);
                    if (in_array($dtype, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint',
                                          'decimal', 'float', 'double'], true)) {
                        db_query("ALTER TABLE `{$table}` ALTER COLUMN `{$name}` SET DEFAULT 0");
                    } elseif (in_array($dtype, ['datetime', 'timestamp', 'date'], true)) {
                        db_query("ALTER TABLE `{$table}` MODIFY COLUMN `{$name}` " . $col['COLUMN_TYPE'] . " NULL DEFAULT NULL");
                    } else {
                        db_query("ALTER TABLE `{$table}` ALTER COLUMN `{$name}` SET DEFAULT ''");
                    }
                    error_log("[heal_legacy_defaults] {$table}.{$name} relaxed ({$dtype})");
                } catch (Exception $e) {
                    // per-column failure isn't fatal; try the next.
                }
            }
        } catch (Exception $e) {
            // information_schema query itself failed; caller's retry
            // will surface the underlying INSERT error.
        }
    }
}

if (!function_exists('present_cols_only')) {
    /**
     * Given a parallel [$cols, $vals] pair and a table, drop any
     * entries whose column isn't in the actual schema. Returns the
     * filtered pair. Silent — a column removed by an old install
     * simply doesn't get its data written.
     *
     * @param string $table
     * @param array<string> $cols
     * @param array<mixed>  $vals
     * @return array{0: array<string>, 1: array<mixed>}
     */
    function present_cols_only(string $table, array $cols, array $vals): array
    {
        $table = trim($table, '` ');
        try {
            $rows = db_fetch_all(
                "SELECT LOWER(COLUMN_NAME) AS c
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?",
                [$table]
            );
            $have = [];
            foreach ($rows as $r) $have[$r['c']] = true;
            $outC = [];
            $outV = [];
            foreach ($cols as $i => $c) {
                if (isset($have[strtolower($c)])) {
                    $outC[] = $c;
                    $outV[] = $vals[$i] ?? null;
                }
            }
            // If the introspection returned nothing (table absent),
            // fall through to the raw list — caller's INSERT will
            // fail with a clearer error than a silent drop.
            if (empty($rows)) return [$cols, $vals];
            return [$outC, $outV];
        } catch (Exception $e) {
            // Fall back to the raw list.
            return [$cols, $vals];
        }
    }
}
