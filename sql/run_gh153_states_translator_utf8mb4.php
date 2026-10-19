<?php
/**
 * GH#153 — make `states_translator` able to hold any State / Province name,
 * and give its code column a unique key.
 *
 * 1. CHARACTER SET. The table was created latin1 (sql/base_schema.sql, a
 *    legacy v3 dump), so "Michoacán de Ocampo" stored fine but
 *    "Manawatū-Whanganui" (ū is not in latin1) became "Manawat?-Whanganui".
 *    CONVERT TO CHARACTER SET utf8mb4 re-encodes the existing rows; nothing
 *    is lost, because every latin1 character exists in utf8mb4.
 *
 * 2. DUPLICATE CODES. The new Settings page (states-admin.php) treats a code
 *    as unique. Rows that repeat a code with the SAME name (case- and
 *    space-insensitively) are pure redundancy and the extra copies are
 *    deleted, keeping the oldest. Rows that repeat a code with DIFFERENT names
 *    are an administrator's data and are left exactly as they are: the
 *    migration says which codes they are, skips the unique key, and still
 *    exits 0 so the upgrade is not blocked. The Settings page shows the same
 *    warning; once the administrator resolves it there, running THIS script
 *    again adds the key (run_migrations.php will not re-run it: its tracker
 *    records that it already ran). The page enforces uniqueness either way.
 *
 * 3. UNIQUE KEY uk_states_code (code), case-insensitive by collation.
 *
 * Verifies its own outcome (both columns utf8mb4; the key present unless
 * conflicting duplicates were reported) and exits non-zero if not.
 * Idempotent: a second run finds nothing to do.
 *
 * Usage: php sql/run_gh153_states_translator_utf8mb4.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'states_translator';
$qt     = '`' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . '`';

echo "GH#153 — states_translator: utf8mb4 + unique code\n";
echo "==================================================\n\n";

/** utf8mb4 status of the code and name columns. */
function gh153_column_charsets(string $table): array
{
    $rows = db_fetch_all(
        "SELECT `COLUMN_NAME` AS c, `CHARACTER_SET_NAME` AS cs FROM information_schema.COLUMNS
          WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` IN ('code', 'name')",
        [$table]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['c']] = strtolower((string) $r['cs']);
    }
    return $out;
}

function gh153_has_unique_code(string $table): bool
{
    $rows = db_fetch_all(
        "SELECT `INDEX_NAME` AS i, COUNT(*) AS cols, MAX(`COLUMN_NAME`) AS col, MIN(`NON_UNIQUE`) AS nu
           FROM information_schema.STATISTICS
          WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?
          GROUP BY `INDEX_NAME`",
        [$table]
    );
    foreach ($rows as $r) {
        if ((int) $r['cols'] === 1 && strtolower((string) $r['col']) === 'code' && (int) $r['nu'] === 0) {
            return true;
        }
    }
    return false;
}

try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?",
        [$table]
    );
    if (!$exists) {
        db_query(
            "CREATE TABLE IF NOT EXISTS {$qt} (
                `id` int(4) NOT NULL AUTO_INCREMENT,
                `name` varchar(64) NOT NULL,
                `code` varchar(4) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_states_code` (`code`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "[OK] {$table} was missing — created (utf8mb4, unique code)\n";
    }

    // ── 1. character set ────────────────────────────────────────────────
    $cs = gh153_column_charsets($table);
    if (($cs['code'] ?? '') === 'utf8mb4' && ($cs['name'] ?? '') === 'utf8mb4') {
        echo "[SKIP] code and name are already utf8mb4\n";
    } else {
        db_query("ALTER TABLE {$qt} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "[OK] converted to utf8mb4 (was code=" . ($cs['code'] ?? '?') . ", name=" . ($cs['name'] ?? '?') . ")\n";
    }

    // ── 2. duplicate codes ──────────────────────────────────────────────
    $conflicting = [];
    $removed = 0;
    $groups = db_fetch_all(
        "SELECT UPPER(TRIM(`code`)) AS c FROM {$qt} GROUP BY UPPER(TRIM(`code`)) HAVING COUNT(*) > 1"
    );
    foreach ($groups as $g) {
        $rows = db_fetch_all(
            "SELECT `id`, `code`, `name` FROM {$qt} WHERE UPPER(TRIM(`code`)) = ? ORDER BY `id`",
            [$g['c']]
        );
        $names = [];
        foreach ($rows as $r) {
            $names[mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $r['name'])))] = true;
        }
        if (count($names) === 1) {
            $keep = (int) $rows[0]['id'];
            $del = db_query("DELETE FROM {$qt} WHERE UPPER(TRIM(`code`)) = ? AND `id` <> ?", [$g['c'], $keep]);
            $n = $del->rowCount();
            $removed += $n;
            echo "[OK] {$g['c']}: removed {$n} exact duplicate row(s), kept id {$keep}\n";
        } else {
            $desc = [];
            foreach ($rows as $r) {
                $desc[] = '#' . $r['id'] . ' "' . $r['name'] . '"';
            }
            $conflicting[] = $g['c'];
            echo "[WARN] {$g['c']} is used by different entries: " . implode(', ', $desc) . "\n";
        }
    }
    if (!$groups) {
        echo "[SKIP] no duplicate codes\n";
    }

    // ── 3. unique key ───────────────────────────────────────────────────
    if (gh153_has_unique_code($table)) {
        echo "[SKIP] unique key on code already present\n";
    } elseif ($conflicting) {
        echo "[WARN] unique key on code NOT added: resolve " . implode(', ', $conflicting)
            . " in Settings → States / Provinces, then run php sql/run_gh153_states_translator_utf8mb4.php again.\n";
    } else {
        db_query("ALTER TABLE {$qt} ADD UNIQUE KEY `uk_states_code` (`code`)");
        echo "[OK] added unique key uk_states_code (code)\n";
    }

    // ── verify the outcome, not just that the statements ran ────────────
    $cs = gh153_column_charsets($table);
    if (($cs['code'] ?? '') !== 'utf8mb4' || ($cs['name'] ?? '') !== 'utf8mb4') {
        fwrite(STDERR, "[FAIL] {$table}.code/name are not utf8mb4 after the conversion\n");
        exit(1);
    }
    if (!$conflicting && !gh153_has_unique_code($table)) {
        fwrite(STDERR, "[FAIL] unique key on {$table}.code is missing after the migration\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone." . ($removed ? " Removed {$removed} duplicate row(s)." : '') . "\n";
