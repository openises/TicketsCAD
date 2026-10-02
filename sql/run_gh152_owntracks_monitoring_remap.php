<?php
/**
 * GH#152 (d3xter, 2026-09-28) — one-time remap of any ALREADY-STORED
 * OwnTracks "monitoring" value from the old, wrong scale to OwnTracks's
 * real published scale.
 *
 * api/owntracks-config.php's `monitoring` knob has, since it was written,
 * used the scale 0=Quiet, 1=Manual, 2=Significant, 3=Move. OwnTracks's own
 * real scale (https://owntracks.org/booklet/tech/json/) is -1=Quiet,
 * 0=Manual, 1=Significant, 2=Move — this codebase's whole scale was
 * shifted up by exactly one, with a 4th value (3) OwnTracks has never had.
 * The code-level fix (this same commit) corrects the hardcoded defaults in
 * _ot_build_layered_config() and the UI's options map in
 * _ot_tunable_keys() — but an admin who had ALREADY saved a global default
 * (settings.owntracks_default_monitoring) or a per-member override
 * (member.owntracks_overrides JSON) under the OLD, mislabeled dropdown is
 * still sitting on an old-scale integer meaning something different now
 * that the labels are fixed. This script remaps those stored values by the
 * same uniform "subtract 1" transform (the two scales differ by exactly
 * one across their whole range) so they keep meaning what the admin
 * actually picked, not what the integer now literally reads as under the
 * corrected scale.
 *
 * Checked on the shared dev database before writing this: zero stored
 * defaults and zero per-member overrides existed there (everyone was on
 * the hardcoded fallback) — so this is written for OTHER installs
 * (your-server.example.com, your-server, or any self-hosted
 * install) that may have set one, not because this dev tree needed it.
 *
 * SAFE-TO-RE-RUN: a dedicated marker setting
 * (owntracks_monitoring_scale_fixed) is checked first and set at the end.
 * Range-checking alone cannot safely distinguish an old-scale value from
 * an already-remapped one (0, 1, 2 exist in BOTH scales with different
 * meanings) — the marker is the only thing that can. Values outside the
 * old scale's valid range (0-3) are left untouched either way (not this
 * script's problem to fix a value nothing in this codebase ever wrote).
 *
 * Usage: php sql/run_gh152_owntracks_monitoring_remap.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$MARKER = 'owntracks_monitoring_scale_fixed';

function gh152_get_setting(string $prefix, string $name) {
    return db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1", [$name]);
}

$already = gh152_get_setting($prefix, $MARKER);
if ($already === '1') {
    echo "[SKIP] already applied ({$MARKER}=1) — nothing to do\n";
    exit(0);
}

$remappedDefault = false;
$remappedMembers = 0;

try {
    // ── Global default ──────────────────────────────────────────────
    $cur = gh152_get_setting($prefix, 'owntracks_default_monitoring');
    if ($cur !== null && $cur !== false && $cur !== '' && ctype_digit(ltrim((string) $cur, '-')) ) {
        $curInt = (int) $cur;
        if ($curInt >= 0 && $curInt <= 3) {
            $new = $curInt - 1;
            db_query(
                "UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'owntracks_default_monitoring'",
                [(string) $new]
            );
            echo "[FIX] owntracks_default_monitoring: {$curInt} -> {$new}\n";
            $remappedDefault = true;
        } else {
            echo "[SKIP] owntracks_default_monitoring={$curInt} is outside the old scale's 0-3 range — left untouched\n";
        }
    } else {
        echo "[OK] no global default set (admin never overrode it) — nothing to remap\n";
    }

    // ── Per-member overrides ─────────────────────────────────────────
    //
    // GH#152 CI fix (2026-10-01): run_migrations.php discovers run_*.php
    // scripts in ksort() LEXICOGRAPHIC filename order, and "run_gh152..."
    // sorts BEFORE "run_gh80_owntracks_overrides.php" (the migration that
    // actually adds this column) -- '1' < '8' -- so on a genuinely fresh
    // install this script ran before that column existed at all, and CI's
    // own fresh-install job caught it (SQLSTATE 42S22, unknown column).
    // This dev tree's own shared database had long since run both, in
    // whichever order, so nothing here ever reproduced it locally. Guard
    // directly against the column's existence rather than relying on
    // migration order at all, the same fix this project used for Phase
    // 152's own beacon-discovery migration hitting the identical ksort()
    // ordering trap.
    $memberHasOverridesCol = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'owntracks_overrides'",
        [$prefix . 'member']
    );
    $rows = $memberHasOverridesCol ? db_fetch_all(
        "SELECT `id`, `owntracks_overrides` FROM `{$prefix}member`
          WHERE `owntracks_overrides` IS NOT NULL AND `owntracks_overrides` != ''"
    ) : [];
    if (!$memberHasOverridesCol) {
        echo "[OK] member.owntracks_overrides does not exist yet on this install -- nothing to remap there (a later run_gh80_owntracks_overrides.php, or re-running this script after it, will find nothing stored under the old scale either, since nothing could have been saved before the column existed)\n";
    }
    foreach ($rows as $row) {
        $decoded = json_decode((string) $row['owntracks_overrides'], true);
        if (!is_array($decoded) || !array_key_exists('monitoring', $decoded)) {
            continue;
        }
        $val = $decoded['monitoring'];
        if (!is_numeric($val)) {
            continue;
        }
        $valInt = (int) $val;
        if ($valInt < 0 || $valInt > 3) {
            echo "[SKIP] member {$row['id']}: monitoring override {$valInt} is outside the old scale's range — left untouched\n";
            continue;
        }
        $decoded['monitoring'] = $valInt - 1;
        db_query(
            "UPDATE `{$prefix}member` SET `owntracks_overrides` = ? WHERE `id` = ?",
            [json_encode($decoded, JSON_UNESCAPED_UNICODE), $row['id']]
        );
        echo "[FIX] member {$row['id']}: monitoring override {$valInt} -> " . ($valInt - 1) . "\n";
        $remappedMembers++;
    }

    // ── Mark done ────────────────────────────────────────────────────
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, '1')
         ON DUPLICATE KEY UPDATE `value` = '1'",
        [$MARKER]
    );
} catch (Exception $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Verify (Phase 128 A9 lesson — confirm the outcome, don't trust the
//    absence of a thrown exception) ──────────────────────────────────
$markerNow = gh152_get_setting($prefix, $MARKER);
if ($markerNow !== '1') {
    fwrite(STDERR, "FAILED: marker was not persisted — remap state is unverifiable\n");
    exit(1);
}

echo "Done. Global default remapped: " . ($remappedDefault ? 'yes' : 'no (none was set)')
    . ". Per-member overrides remapped: {$remappedMembers}.\n";
exit(0);
