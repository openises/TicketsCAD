<?php
/**
 * Phase 138 (2026-08-13) — Public incident board.
 *
 * Adds the schema this phase needs: four `in_types` columns (never-publish,
 * per-type delay override, presence-only visibility, stub label), two
 * `organizations` columns (per-org public-board opt-in + URL slug), six
 * board-wide `settings` rows, and the two RBAC permissions split by blast
 * radius (security review finding #1 — see plan.md §4):
 *   - action.manage_public_board      — install-wide, Super Admin only
 *   - action.manage_public_board_org  — org-scoped self-service, Super
 *                                        Admin + Org Admin
 *
 * Idempotent — safe to run repeatedly. Follows the
 * sql/run_phase18a_security_labels.php template verbatim for the
 * table/column-existence helpers (renamed to the _p138_ prefix to avoid a
 * redeclaration if both files are ever `require`d in the same process;
 * reused via function_exists() if phase18a's helpers already ran first).
 *
 * Spec: specs/phase-138-public-incident-board/{spec.md,plan.md,tasks.md}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 138 — Public incident board\n";
echo "==================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

if (function_exists('_p18_col_exists')) {
    function _p138_col_exists(string $t, string $c): bool { return _p18_col_exists($t, $c); }
} else {
    function _p138_col_exists(string $t, string $c): bool {
        global $prefix;
        try {
            $r = db_fetch_one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$prefix . $t, $c]
            );
            return !empty($r);
        } catch (Exception $e) { return false; }
    }
}

if (function_exists('_p18_table_exists')) {
    function _p138_table_exists(string $t): bool { return _p18_table_exists($t); }
} else {
    function _p138_table_exists(string $t): bool {
        global $prefix;
        try {
            $r = db_fetch_one(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$prefix . $t]
            );
            return !empty($r);
        } catch (Exception $e) { return false; }
    }
}

// ── A2. in_types — four new columns (plan.md §1a) ───────────────────────
$inTypesCols = [
    'public_board_never_publish' =>
        "TINYINT(1) NOT NULL DEFAULT 1
         COMMENT 'Phase 138 - 1 = this incident type never appears on any public board, no matter what else is configured. Defaults to 1 (2026-08-14) -- an admin must explicitly opt a type in.'",
    'public_board_publish_delay_secs' =>
        "INT UNSIGNED NULL
         COMMENT 'Phase 138 - NULL = use the board-wide default delay; per-type override in seconds'",
    'public_board_visibility' =>
        "ENUM('full','presence_only') NOT NULL DEFAULT 'full'
         COMMENT 'Phase 138 - presence_only = generic label + time + unit count only, no type/address/narrative/pin'",
    'public_board_stub_label' =>
        "VARCHAR(64) NULL
         COMMENT 'Phase 138 - custom presence-only display label for this type; NULL = fall back to the generic Response stub'",
];
foreach ($inTypesCols as $col => $def) {
    if (!_p138_col_exists('in_types', $col)) {
        try {
            db_query("ALTER TABLE `{$prefix}in_types` ADD COLUMN `{$col}` {$def}");
            echo "[OK] Added in_types.{$col}\n";
        } catch (Exception $e) {
            echo "[WARN] in_types.{$col}: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[OK] in_types.{$col} already exists\n";
    }
}

// ── A2b. Conservative default seeding (value/mission review finding #1) ──
//
// Seeds public_board_visibility = 'presence_only' for any in_types row
// whose `group` case-insensitively contains one of a fixed sensitive-
// keyword list, ONLY while the row is still at the column's own defaults
// (public_board_visibility = 'full' AND public_board_never_publish = 0) —
// so a re-run, or an install where an admin already reviewed a type and
// deliberately set it back to 'full', never gets clobbered. This is a
// heuristic, not a guarantee (plan.md §1a) — an admin must still review
// the Incident Type Rules panel before relying on it.
//
// Keyword list decided 2026-08-13 (tasks.md §0a) while Eric was away —
// expanded from the original 12 terms to these 20, erring toward
// over-inclusion. Cheap to trim/extend later; flag prominently to Eric.
$sensitiveKeywords = [
    'ems', 'medical', 'rescue', 'ambulance', 'mental health', 'overdose',
    'domestic', 'dv', 'sexual assault', 'abuse', 'welfare check', 'psych',
    'suicide', 'juvenile', 'child', 'minor', 'behavioral', 'crisis',
    'psychiatric', 'self harm',
];
// None of the fixed keywords above contain MySQL/MariaDB REGEXP
// metacharacters, so a plain '|' join is a safe, unambiguous pattern —
// no PCRE-vs-POSIX escaping mismatch to worry about.
$sensitiveRegex = implode('|', $sensitiveKeywords);

// CORRECTION (final adversarial review, 2026-08-13): this originally
// matched ONLY `group` — which is, on every install this codebase's own
// CLAUDE.md documents (and confirmed live against this install's actual
// demo data), the org/dispatch CATEGORY a type belongs to ("CERT", "Med
// Team", "Campus PD", "Vol Fire"), never a clinical descriptor. Matching
// only `group` caught ZERO of the ~10 plainly medical/crisis-shaped demo
// types shipped with every fresh install (MedAssist, WelfareChk,
// AltMental, MHCrisis, etc.) — this seed step was a silent no-op on stock
// data, and so was pb_sensitive_types_still_full()'s live re-check (same
// bug, same fix, see inc/public-board.php). `description` is where the
// human-readable signal actually lives ("Welfare Check", "Mental Health
// Crisis", "Medical Assistance (CERT)") — confirmed against this
// install's real rows. `type` is included too since a real install may
// spell things out there directly.
try {
    $matches = db_fetch_all(
        "SELECT id, type, `group` FROM `{$prefix}in_types`
          WHERE public_board_visibility = 'full'
            AND public_board_never_publish = 0
            AND (
                (`type` IS NOT NULL AND `type` <> '' AND LOWER(`type`) REGEXP ?)
             OR (`group` IS NOT NULL AND `group` <> '' AND LOWER(`group`) REGEXP ?)
             OR (`description` IS NOT NULL AND `description` <> '' AND LOWER(`description`) REGEXP ?)
            )",
        [$sensitiveRegex, $sensitiveRegex, $sensitiveRegex]
    );
    if (!empty($matches)) {
        db_query(
            "UPDATE `{$prefix}in_types`
                SET public_board_visibility = 'presence_only'
              WHERE public_board_visibility = 'full'
                AND public_board_never_publish = 0
                AND (
                    (`type` IS NOT NULL AND `type` <> '' AND LOWER(`type`) REGEXP ?)
                 OR (`group` IS NOT NULL AND `group` <> '' AND LOWER(`group`) REGEXP ?)
                 OR (`description` IS NOT NULL AND `description` <> '' AND LOWER(`description`) REGEXP ?)
                )",
            [$sensitiveRegex, $sensitiveRegex, $sensitiveRegex]
        );
        echo "[OK] Seeded public_board_visibility='presence_only' for " . count($matches) . " sensitive-keyword-matching type(s):\n";
        foreach ($matches as $m) {
            echo "     - #{$m['id']} \"{$m['type']}\" (group: {$m['group']})\n";
        }
    } else {
        echo "[OK] No in_types rows matched the sensitive-keyword seed (0 touched)\n";
    }
} catch (Exception $e) {
    echo "[WARN] visibility seed: " . $e->getMessage() . "\n";
}

// ── A2c. Never-publish-by-default (Eric, 2026-08-14) ────────────────────
//
// Ron Jones's keyword-seeding gap report (same day) showed the A2b heuristic
// above under-classifies sensitive types whenever an install groups them
// under a non-EMS category (his own CARDIAC/MISSING/MCI types all resolved
// to FULL because none of the then-20 keywords matched). Eric's direction:
// flip the default posture from "publish unless a keyword downgrades it" to
// "publish nothing until an admin explicitly enables that type" — for both
// new types AND existing ones. See specs/phase-138-public-incident-board/
// changes.md for the full writeup.
//
// ONE-TIME, guarded by a settings marker: every in_types row still at
// public_board_never_publish = 0 gets flipped to 1. Safe to do
// unconditionally on first run — the admin UI for this control has never
// been used on any known install (Eric's own hands-on spot-check of Phase
// 138 is still pending per specs/handoff.md), so every existing 0 row today
// is "never reviewed," not "reviewed and approved": there is no real admin
// decision to lose. The marker prevents a LATER re-run (this script is part
// of the normal migration runner, invoked on every deploy) from re-flipping
// a type an admin has since deliberately re-enabled through the real UI.
try {
    $alreadyDefaulted = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'public_board_never_publish_defaulted' LIMIT 1"
    );
    if ($alreadyDefaulted === null || $alreadyDefaulted === false) {
        $toFlip = db_fetch_all(
            "SELECT `id`, `type` FROM `{$prefix}in_types` WHERE `public_board_never_publish` = 0"
        );
        if (!empty($toFlip)) {
            db_query("UPDATE `{$prefix}in_types` SET `public_board_never_publish` = 1 WHERE `public_board_never_publish` = 0");
            echo "[OK] Defaulted public_board_never_publish=1 for " . count($toFlip) . " existing type(s) (one-time):\n";
            foreach ($toFlip as $m) {
                echo "     - #{$m['id']} \"{$m['type']}\"\n";
            }
        } else {
            echo "[OK] No in_types rows at public_board_never_publish=0 to default (0 touched)\n";
        }
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('public_board_never_publish_defaulted', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [date('Y-m-d H:i:s')]
        );
        echo "[OK] Marked public_board_never_publish_defaulted (won't re-run)\n";
    } else {
        echo "[OK] public_board_never_publish already defaulted on " . $alreadyDefaulted . " — skipping (idempotent)\n";
    }
} catch (Exception $e) {
    echo "[WARN] never-publish default migration: " . $e->getMessage() . "\n";
}

// New in_types rows created AFTER this ships should also default to
// never-publish — changes the COLUMN's own default so a plain INSERT with
// no explicit value (the normal "add incident type" path) lands safe.
try {
    db_query("ALTER TABLE `{$prefix}in_types` MODIFY COLUMN `public_board_never_publish`
              TINYINT(1) NOT NULL DEFAULT 1
              COMMENT 'Phase 138 - 1 = this incident type never appears on any public board, no matter what else is configured. Defaults to 1 (2026-08-14) -- an admin must explicitly opt a type in.'");
    echo "[OK] in_types.public_board_never_publish column default is now 1\n";
} catch (Exception $e) {
    echo "[WARN] could not update public_board_never_publish column default: " . $e->getMessage() . "\n";
}

// ── A3. organizations — two new columns + unique slug key (plan.md §1b) ─
$orgCols = [
    'public_board_enabled' =>
        "TINYINT(1) NOT NULL DEFAULT 0
         COMMENT 'Phase 138 - this org has opted into its own public board URL'",
    'public_board_slug' =>
        "VARCHAR(64) NULL
         COMMENT 'Phase 138 - URL segment, e.g. public-board.php?org=your-server'",
];
foreach ($orgCols as $col => $def) {
    if (!_p138_col_exists('organizations', $col)) {
        try {
            db_query("ALTER TABLE `{$prefix}organizations` ADD COLUMN `{$col}` {$def}");
            echo "[OK] Added organizations.{$col}\n";
        } catch (Exception $e) {
            echo "[WARN] organizations.{$col}: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[OK] organizations.{$col} already exists\n";
    }
}

try {
    $keyExists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_public_board_slug'",
        [$prefix . 'organizations']
    );
    if ((int) $keyExists === 0) {
        db_query("ALTER TABLE `{$prefix}organizations` ADD UNIQUE KEY `uk_public_board_slug` (`public_board_slug`)");
        echo "[OK] Added organizations.uk_public_board_slug unique key\n";
    } else {
        echo "[OK] organizations.uk_public_board_slug already exists\n";
    }
} catch (Exception $e) {
    echo "[WARN] uk_public_board_slug: " . $e->getMessage() . "\n";
}

// ── A4. Board-wide settings (plan.md §1c) — settings TABLE via name/value,
// NOT the separate `config` table (CLAUDE.md's "TWO settings stores"
// pitfall). INSERT IGNORE only — never overwrites an admin's already-
// configured value on re-run.
$settings = [
    'public_board_enabled'                => '0',
    'public_board_address_precision'      => 'block',
    'public_board_excluded_groups'        => '',
    'public_board_default_delay_secs'     => '90',
    'public_board_rate_limit_requests'    => '30',
    'public_board_rate_limit_window_secs' => '60',
];
foreach ($settings as $name => $value) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            [$name, $value]
        );
        echo "[OK] settings.{$name} ready (seeded or already present)\n";
    } catch (Exception $e) {
        echo "[WARN] settings.{$name}: " . $e->getMessage() . "\n";
    }
}

// ── A6. RBAC permissions (plan.md §4) — split by blast radius ───────────
// action.manage_public_board:      install-wide, Super Admin only.
// action.manage_public_board_org:  org-scoped self-service, Super Admin +
//                                   Org Admin. The org id is forced
//                                   server-side from the session in
//                                   api/public-board-admin.php (built in
//                                   Section C) — this grant alone cannot
//                                   cross an org boundary; that's enforced
//                                   in code, not by the permission itself.
$perms = [
    ['action.manage_public_board', 'Manage Public Incident Board (install-wide)',
     'action', 'public_board', 'manage',
     "Install-wide public-board settings, precision/delay/rate limits, and the shared in_types publish rules. Super Admin only."],
    ['action.manage_public_board_org', "Manage Own Org's Public Board URL",
     'action', 'public_board', 'manage_org',
     "Org-scoped self-service: enable/set slug for the caller's OWN org's public board URL only. Does not grant install-wide settings."],
];
foreach ($perms as $p) {
    [$code, $name, $cat, $res, $verb, $desc] = $p;
    try {
        $exists = db_fetch_value(
            "SELECT 1 FROM `{$prefix}permissions` WHERE code = ? LIMIT 1", [$code]);
        if ($exists) {
            echo "[OK] {$code} already exists\n";
            continue;
        }
        db_query(
            "INSERT INTO `{$prefix}permissions` (code, name, category, resource, verb, description)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$code, $name, $cat, $res, $verb, $desc]
        );
        echo "[OK] Added permission {$code}\n";
    } catch (Exception $e) {
        echo "[WARN] perm {$code}: " . $e->getMessage() . "\n";
    }
}

// Default grants. INSERT IGNORE against the (role_id, permission_id)
// primary key makes this idempotent regardless of NULL-column pitfalls
// (Phase 129) — there's no nullable column in this junction table's key.
$grants = [
    'action.manage_public_board'     => "r.is_super = 1 OR r.name = 'Super Admin'",
    'action.manage_public_board_org' => "r.is_super = 1 OR r.name IN ('Super Admin', 'Org Admin')",
];
foreach ($grants as $code => $where) {
    try {
        db_query("
            INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
            SELECT r.id,
                   (SELECT id FROM `{$prefix}permissions` WHERE code = ? LIMIT 1)
              FROM `{$prefix}roles` r
             WHERE {$where}", [$code]);
    } catch (Exception $e) {
        echo "[WARN] grant {$code}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] Default grants applied for Phase 138 permissions\n";

echo "\nPhase 138 schema/migration done.\n";
