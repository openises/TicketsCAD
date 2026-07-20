<?php
/**
 * Run Phase 8b i18n — Languages registry + user.preferred_lang column.
 *
 * Purpose:  Promotes the implicit "available languages" detection (DISTINCT
 *           lang FROM captions_i18n) into an explicit `languages` registry
 *           table with enable/disable, install-default, display names, and
 *           sort order. Also adds user.preferred_lang for per-user
 *           persistence across sessions.
 *
 *           Backfills the registry from existing captions_i18n entries so
 *           after this migration runs, the UI behaves exactly as before
 *           (same languages visible in the switcher), but admins now have
 *           a real management surface.
 *
 * Usage:    php sql/run_phase08b_i18n.php
 * Prereqs:  config.php; captions_i18n already populated (run_phase08_i18n.php).
 * Safety:   Idempotent. Guards every ALTER / CREATE with information_schema
 *           checks. Re-running this script does NOT clobber admin edits to
 *           the languages table (uses INSERT IGNORE for the seed pass).
 */
require_once __DIR__ . '/../config.php';

echo "Phase 8b i18n — Languages Registry Migration\n";
echo "============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ─── 1. Create languages table ─────────────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}languages` (
        `code`         VARCHAR(8)   NOT NULL PRIMARY KEY,
        `display_name` VARCHAR(64)  NOT NULL,
        `native_name`  VARCHAR(64)  NOT NULL DEFAULT '',
        `enabled`      TINYINT(1)   NOT NULL DEFAULT 1,
        `is_default`   TINYINT(1)   NOT NULL DEFAULT 0,
        `sort_order`   INT          NOT NULL DEFAULT 100,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_enabled` (`enabled`),
        KEY `idx_default` (`is_default`),
        KEY `idx_sort` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] languages table ready\n";
} catch (Exception $e) {
    echo "[WARN] languages CREATE TABLE: " . $e->getMessage() . "\n";
}

// ─── 2. Seed registry from captions_i18n + known defaults ─────────────────
// The registry should cover EVERY lang that already exists in captions_i18n
// so the switcher behaviour is unchanged immediately post-migration. Use
// curated display names for the ISO codes we know; fallback to uppercase
// for unknown codes (admin can rename via the UI).

$LANG_META = [
    'en' => ['English',    'English',    1, 1, 10],   // default
    'de' => ['German',     'Deutsch',    1, 0, 20],
    'fr' => ['French',     'Français',   1, 0, 30],
    'es' => ['Spanish',    'Español',    1, 0, 40],
    'it' => ['Italian',    'Italiano',   1, 0, 50],
    'pt' => ['Portuguese', 'Português',  1, 0, 60],
    'nl' => ['Dutch',      'Nederlands', 1, 0, 70],
    'sv' => ['Swedish',    'Svenska',    1, 0, 80],
    'no' => ['Norwegian',  'Norsk',      1, 0, 90],
    'da' => ['Danish',     'Dansk',      1, 0, 100],
    'fi' => ['Finnish',    'Suomi',      1, 0, 110],
    'pl' => ['Polish',     'Polski',     1, 0, 120],
    'cs' => ['Czech',      'Čeština',    1, 0, 130],
    'ja' => ['Japanese',   '日本語',     1, 0, 140],
    'ko' => ['Korean',     '한국어',     1, 0, 150],
    'zh' => ['Chinese',    '中文',       1, 0, 160],
    'ar' => ['Arabic',     'العربية',    1, 0, 170],
    'he' => ['Hebrew',     'עברית',      1, 0, 180],
    'ru' => ['Russian',    'Русский',    1, 0, 190],
    'uk' => ['Ukrainian',  'Українська', 1, 0, 200],
];

try {
    $existingLangs = db_fetch_all(
        "SELECT DISTINCT lang FROM `{$prefix}captions_i18n`"
    );
    $seeded = 0;
    foreach ($existingLangs as $row) {
        $code = $row['lang'];
        if (!isset($LANG_META[$code])) {
            // Unknown code → enabled, never default, sort to bottom
            $LANG_META[$code] = [strtoupper($code), strtoupper($code), 1, 0, 999];
        }
        $meta = $LANG_META[$code];
        db_query(
            "INSERT IGNORE INTO `{$prefix}languages`
             (code, display_name, native_name, enabled, is_default, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$code, $meta[0], $meta[1], $meta[2], $meta[3], $meta[4]]
        );
        $seeded++;
    }
    echo "[OK] Seeded {$seeded} languages from captions_i18n\n";
} catch (Exception $e) {
    echo "[WARN] seed languages: " . $e->getMessage() . "\n";
}

// Guarantee exactly one default exists. If admin manually flipped all to 0,
// or the seed didn't include en, recover by promoting the lowest-sort_order
// enabled row.
try {
    $defaultCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}languages` WHERE is_default = 1"
    );
    if ($defaultCount === 0) {
        $promote = db_fetch_one(
            "SELECT code FROM `{$prefix}languages`
             WHERE enabled = 1 ORDER BY sort_order, code LIMIT 1"
        );
        if ($promote) {
            db_query(
                "UPDATE `{$prefix}languages` SET is_default = 1 WHERE code = ?",
                [$promote['code']]
            );
            echo "[OK] Promoted '{$promote['code']}' to install default (no default was set)\n";
        }
    } elseif ($defaultCount > 1) {
        // Multiple defaults — pick the lowest sort_order, zero out the rest
        $keep = db_fetch_one(
            "SELECT code FROM `{$prefix}languages`
             WHERE is_default = 1 ORDER BY sort_order, code LIMIT 1"
        );
        db_query(
            "UPDATE `{$prefix}languages` SET is_default = 0 WHERE code <> ?",
            [$keep['code']]
        );
        echo "[OK] Resolved multi-default state — kept '{$keep['code']}'\n";
    }
} catch (Exception $e) {
    echo "[WARN] default reconciliation: " . $e->getMessage() . "\n";
}

// ─── 3. Add user.preferred_lang column (idempotent via info_schema) ───────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'preferred_lang'",
        [$prefix . 'user']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}user`
             ADD COLUMN `preferred_lang` VARCHAR(8) NULL DEFAULT NULL"
        );
        echo "[OK] Added user.preferred_lang column\n";
    } else {
        echo "[OK] user.preferred_lang already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] user.preferred_lang ALTER: " . $e->getMessage() . "\n";
}

// ─── 4. Report final state ────────────────────────────────────────────────
try {
    $rows = db_fetch_all(
        "SELECT code, display_name, native_name, enabled, is_default, sort_order
         FROM `{$prefix}languages` ORDER BY sort_order, code"
    );
    echo "\nLanguages registry:\n";
    echo "  code | display          | native           | enabled | default | sort\n";
    echo "  -----+------------------+------------------+---------+---------+-----\n";
    foreach ($rows as $r) {
        printf(
            "  %-4s | %-16s | %-16s | %-7s | %-7s | %d\n",
            $r['code'],
            substr($r['display_name'], 0, 16),
            substr($r['native_name'], 0, 16),
            ((int)$r['enabled']) ? 'yes' : 'no',
            ((int)$r['is_default']) ? 'YES' : '',
            (int)$r['sort_order']
        );
    }

    $usersWithPref = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user` WHERE preferred_lang IS NOT NULL"
    );
    echo "\nUsers with persistent language preference: {$usersWithPref}\n";
} catch (Exception $e) {
    echo "[WARN] report query: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
