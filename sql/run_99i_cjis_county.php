<?php
/**
 * Phase 99i (2026-06-29) — CJIS login notice + county field on personnel.
 *
 * Two small features Billy Irwin (beta) asked for in his 2026-06-29 email:
 *
 *   1. Click-through "CJIS login notice" banner shown above the login form,
 *      admin-configurable. CJIS requires the user agree to acceptable-use
 *      and monitoring terms before accessing the system.
 *
 *   2. county column on member (Personnel). Billy's ARES work is organized
 *      by county; a free-text field on personnel records is the quickest
 *      surfacing.
 *
 * Both are idempotent. Run with: php sql/run_99i_cjis_county.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    // 1. CJIS login notice settings (key/value rows).
    //    cjis_login_notice_enabled = '0' or '1'
    //    cjis_login_notice_text    = the banner content (TEXT)
    //
    //    settings has columns (id, name, value). Insert-ignore so reruns
    //    don't clobber an admin's customized text.
    db_query(
        "INSERT IGNORE INTO `{$prefix}settings` (name, value) VALUES (?, ?)",
        ['cjis_login_notice_enabled', '0']
    );
    db_query(
        "INSERT IGNORE INTO `{$prefix}settings` (name, value) VALUES (?, ?)",
        ['cjis_login_notice_text',
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
         . "you certify that you are an authorized user and acknowledge these terms."
        ]
    );
    echo "✓ cjis_login_notice_enabled + cjis_login_notice_text seeded (insert-ignore)\n";

    // 2. member.county — VARCHAR(64) NULL. Free text for now; future
    //    work can swap to a state+county lookup (Billy has the JSON).
    $hasCounty = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = 'county'",
        [$prefix . 'member']
    );
    if (!$hasCounty) {
        // GH #72 follow-on: no `AFTER city` — member.city itself is added
        // by a LATER install step, so the positioning clause hard-failed
        // every fresh install. Column order is cosmetic; append instead.
        db_query(
            "ALTER TABLE `{$prefix}member`
             ADD COLUMN `county` VARCHAR(64) NULL"
        );
        echo "✓ member.county column added\n";
    } else {
        echo "✓ member.county column already exists — skipping ALTER\n";
    }

    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
