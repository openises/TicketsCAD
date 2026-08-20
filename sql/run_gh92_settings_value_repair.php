<?php
/**
 * GH #92 (Ron Jones, 2026-08-19) — settings.value column-width repair +
 * truncated CJIS login-notice repair.
 *
 * tools/install_fresh.php widened settings.value from varchar(512) to TEXT
 * as a step that ran AFTER the sql/run_*.php migration sweep — including
 * sql/run_99i_cjis_county.php, which seeds an 812-character CJIS
 * login-notice default into settings.value via INSERT IGNORE. So on a
 * fresh install built before this fix, the notice was written while the
 * column was still narrow: silently truncated to exactly 512 characters
 * (non-strict SQL mode), or — on servers where INSERT IGNORE's truncation
 * still surfaces as a hard error under strict SQL mode — the whole script
 * aborted via its own exit(1) handler, which also skipped the unrelated
 * member.county column it adds further down the same try block.
 * tools/install_fresh.php now widens the column immediately after
 * base_schema.sql import, before any seed step runs (see its "step 0a").
 *
 * That fix only prevents the bug on installs that (re-)run
 * tools/install_fresh.php. The documented ROUTINE upgrade path
 * (docs/UPDATE-CHECKLIST.md) is `php sql/run_migrations.php` only, which
 * never touches install_fresh.php's steps — so an install that has been
 * upgrading via that path since before this fix could still have
 * settings.value sitting at varchar(512) today, with no ordering issue
 * left to trigger (nothing else seeds a >512-char value into it), but
 * also no chance to have picked up the widening. This script is the
 * companion fix that reaches that install: it is a sql/run_*.php script,
 * so sql/run_migrations.php's normal sweep discovers and runs it
 * automatically on the very next routine upgrade.
 *
 * Two independent repairs, both idempotent:
 *
 *   1. Widen settings.value to TEXT if it is not already (covers the
 *      update-only install described above).
 *
 *   2. Repair a cjis_login_notice_text that matches the TRUNCATION
 *      SIGNATURE left by the bug: because both settings.value INSERTs in
 *      sql/run_99i_cjis_county.php use INSERT IGNORE, a normal re-run of
 *      that seeder can never fix an already-truncated row — it reads as
 *      "already present" and is silently skipped forever. This repair is
 *      narrowly targeted: it rewrites the value ONLY when the current
 *      value is (a) EXACTLY 512 characters long AND (b) an EXACT prefix
 *      of the known 812-character default text. An admin who deliberately
 *      customized the notice to something else — including a genuinely
 *      intentional 512-character notice that happens not to be a prefix
 *      of the default — is left completely untouched. A missing row is
 *      also left alone (a normal run of run_99i_cjis_county.php seeds it
 *      fresh, and now safely — the column is TEXT by the time this runs).
 *
 * Usage: php sql/run_gh92_settings_value_repair.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// The exact text sql/run_99i_cjis_county.php seeds (812 characters, per
// mb_strlen — 814 bytes in UTF-8 because of the em dash). Kept as a
// literal copy here, deliberately NOT shared via a constant/function with
// run_99i_cjis_county.php: this script's job is to recognize what a PAST,
// buggy run of THAT script produced, so it must describe that exact
// historical text regardless of what run_99i_cjis_county.php's seed
// default might become in the future.
$defaultNotice =
    "WARNING — U.S. GOVERNMENT SYSTEM\n\n"
    . "This is a restricted information system. Unauthorized or improper use of this "
    . "system may result in disciplinary action, as well as civil and criminal penalties.\n\n"
    . "By using this system you understand and consent to the following:\n"
    . "- You have no reasonable expectation of privacy regarding any communication "
    . "transmitted through or data stored on this system.\n"
    . "- At any time, the government may monitor, intercept, search, and seize any "
    . "communication or data transiting or stored on this system.\n"
    . "- Any communications or data transiting or stored on this system may be "
    . "disclosed or used for any U.S. government-authorized purpose.\n\n"
    . "Access to CJIS information is restricted to authorized personnel. By logging in, "
    . "you certify that you are an authorized user and acknowledge these terms.";

try {
    // ── 1. Widen settings.value to TEXT if this install never got it ──────
    $colType = db_fetch_value(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'value'",
        [$prefix . 'settings']
    );
    if ($colType === null || $colType === false) {
        echo "[SKIP] {$prefix}settings.value column not found (settings table missing?) — nothing to widen\n";
    } elseif (strtolower((string) $colType) !== 'text') {
        db_query("ALTER TABLE `{$prefix}settings` MODIFY COLUMN `value` TEXT DEFAULT NULL");
        echo "[OK] settings.value widened to TEXT (was {$colType})\n";
    } else {
        echo "[SKIP] settings.value is already TEXT — nothing to widen\n";
    }

    // ── 2. Repair a truncated cjis_login_notice_text, signature-matched ───
    $current = db_fetch_value(
        "SELECT value FROM `{$prefix}settings` WHERE name = 'cjis_login_notice_text' LIMIT 1"
    );
    if ($current === null || $current === false) {
        echo "[SKIP] cjis_login_notice_text not present — nothing to repair "
            . "(a normal run of sql/run_99i_cjis_county.php will seed it, safely, now that the column is TEXT)\n";
    } else {
        $current = (string) $current;
        $isTruncationSignature =
            mb_strlen($current) === 512
            && $current === mb_substr($defaultNotice, 0, 512);
        if ($isTruncationSignature) {
            db_query(
                "UPDATE `{$prefix}settings` SET value = ? WHERE name = 'cjis_login_notice_text'",
                [$defaultNotice]
            );
            echo "[OK] cjis_login_notice_text matched the GH #92 truncation signature "
                . "(exactly 512 chars, a prefix of the default) — repaired to the full "
                . mb_strlen($defaultNotice) . "-character default\n";
        } else {
            echo "[SKIP] cjis_login_notice_text does not match the truncation signature "
                . "(current length: " . mb_strlen($current) . " chars) — left untouched, "
                . "may be an intentional admin customization\n";
        }
    }

    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
