<?php
/**
 * Phase 138 — Public incident board: never-publish-by-default (2026-08-14).
 *
 * Ron Jones (@rjonesbsink) reported the original "publish unless a keyword
 * downgrades it" default under-classified sensitive types whenever an
 * install grouped them under a non-EMS category (his CARDIAC/MISSING/MCI
 * types all resolved to full public exposure). Eric's direction: flip the
 * default posture to "publish nothing until an admin explicitly opts a type
 * in" for both new and existing incident types, and expand the keyword
 * list. Full writeup: specs/phase-138-public-incident-board/changes.md
 *
 * Covers:
 *   1. Structural — the touched files actually carry the new logic.
 *   2. The in_types.public_board_never_publish column's real DEFAULT on
 *      this install is 1 (proves the ALTER TABLE MODIFY COLUMN step
 *      actually ran here, not just that the script text says it should).
 *   3. A brand-new in_types row with no explicit value defaults to 1 —
 *      the real path every "add incident type" call goes through.
 *   4. pb_sensitive_keywords() carries the 8 terms Ron's report named,
 *      without dropping any original term.
 *   5. pb_sensitive_types_still_full() flags a sensitive type only while
 *      it can actually publish (never_publish = 0) — not a stored-but-
 *      inert visibility value.
 *   6. THE idempotency guarantee (changes.md's own verification bullet):
 *      re-running the real migration script must NOT re-flip a row an
 *      admin has since deliberately set back to never_publish = 0.
 *
 * @requires-db
 * Usage: php tests/test_public_board_never_publish_default.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/public-board.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — Public board never-publish-by-default ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$root = dirname(__DIR__);

function _pbnp_extract_function(string $src, string $name): string {
    $start = strpos($src, "function {$name}(");
    if ($start === false) return '';
    $nextFn = strpos($src, 'function ', $start + 10);
    return $nextFn === false ? substr($src, $start) : substr($src, $start, $nextFn - $start);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — structural: the touched files actually carry the new logic
// ═══════════════════════════════════════════════════════════════════════

echo "--- Structural checks ---\n\n";

$migrationSrc = (string) file_get_contents($root . '/sql/run_phase138_public_board.php');

t('migration: column default comment says "Defaults to 1" in BOTH the ADD COLUMN def and the later ALTER MODIFY COLUMN step',
    substr_count($migrationSrc, 'Defaults to 1 (2026-08-14)') === 2);

t('migration: one-time marker key is public_board_never_publish_defaulted',
    strpos($migrationSrc, "'public_board_never_publish_defaulted'") !== false);

$markerCheckPos = strpos($migrationSrc, 'public_board_never_publish_defaulted');
$updatePos = strpos($migrationSrc, 'SET `public_board_never_publish` = 1 WHERE `public_board_never_publish` = 0');
t('migration: the marker check appears before the one-time flip UPDATE (guards it)',
    $markerCheckPos !== false && $updatePos !== false && $markerCheckPos < $updatePos);

t('migration: ALTER TABLE MODIFY COLUMN changes the column default for future rows',
    strpos($migrationSrc, 'ALTER TABLE `{$prefix}in_types` MODIFY COLUMN `public_board_never_publish`') !== false);

$pbSrc = (string) file_get_contents($root . '/inc/public-board.php');
$stillFullFn = _pbnp_extract_function($pbSrc, 'pb_sensitive_types_still_full');
t('pb_sensitive_types_still_full() function was found in inc/public-board.php',
    $stillFullFn !== '');
t('pb_sensitive_types_still_full() checks never_publish = 0',
    strpos($stillFullFn, 'public_board_never_publish` = 0') !== false);
t('pb_sensitive_types_still_full() no longer keys on the now-inert visibility = full',
    strpos($stillFullFn, "public_board_visibility") === false);

$jsSrc = (string) file_get_contents($root . '/assets/js/public-board-admin.js');
t('public-board-admin.js: neverPublish variable derived from public_board_never_publish',
    strpos($jsSrc, "var neverPublish = String(t.public_board_never_publish) === '1';") !== false);
t('public-board-admin.js: sensitive-row highlight keys on never_publish, not the old isFull',
    strpos($jsSrc, 'sensitiveIds[String(t.id)] && !neverPublish') !== false);
t('public-board-admin.js: the old isFull-gated highlight condition is gone',
    strpos($jsSrc, 'sensitiveIds[String(t.id)] && isFull') === false);

$docSrc = (string) file_get_contents($root . '/docs/PUBLIC-INCIDENT-BOARD.md');
t('docs: states every incident type defaults to Never Publish',
    stripos($docSrc, 'Every incident type defaults to Never Publish') !== false);
t('docs: keyword prose mentions at least one of the newly-added terms',
    stripos($docSrc, 'cardiac') !== false);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — live behavior against this install's real database
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Live schema + behavior ---\n\n";

$hasCol = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'public_board_never_publish'",
    [$prefix . 'in_types']
);
if (!$hasCol) {
    echo "SKIP: in_types.public_board_never_publish not present — Phase 138 migration never applied to this DB.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$migrationScript = $root . '/sql/run_phase138_public_board.php';
$php = PHP_BINARY ?: 'php';

// Run the real migration once up front so this install's column default +
// settings marker reflect the fix regardless of whether Phase 138 was
// already applied here with the OLD (default-0) column definition.
$out1 = @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($migrationScript) . ' 2>&1');

if ($out1 === null) {
    echo "[SKIP] could not run the migration script as a subprocess — skipping all live/subprocess checks below.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

t('migration subprocess ran without a fatal error',
    stripos($out1, 'Fatal error') === false && stripos($out1, 'Uncaught') === false);

$columnDefault = db_fetch_value(
    "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'public_board_never_publish'",
    [$prefix . 'in_types']
);
t("in_types.public_board_never_publish column DEFAULT is really '1' on this install",
    (string) $columnDefault === '1');

$markerVal = db_fetch_value(
    "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'public_board_never_publish_defaulted' LIMIT 1"
);
t('settings.public_board_never_publish_defaulted marker is set after running the migration',
    $markerVal !== null && $markerVal !== false);

$createdTypeIds = [];
function _pbnp_make_type(array $overrides = []): int {
    global $prefix, $createdTypeIds;
    $fields = array_merge([
        'type'        => 'zz138np-' . uniqid(),
        'description' => 'never-publish-default test type',
        'group'       => null,
    ], $overrides);
    $cols = array_keys($fields);
    db_query(
        "INSERT INTO `{$prefix}in_types` (`" . implode('`,`', $cols) . "`) VALUES (" .
        implode(',', array_fill(0, count($cols), '?')) . ")",
        array_values($fields)
    );
    $id = (int) db_insert_id();
    $createdTypeIds[] = $id;
    return $id;
}

try {
    // A brand-new row with NO explicit value for the column — the real
    // path every "add incident type" UI/API call goes through.
    $freshId = _pbnp_make_type();
    $freshVal = db_fetch_value("SELECT `public_board_never_publish` FROM `{$prefix}in_types` WHERE `id` = ?", [$freshId]);
    t('a brand-new in_types row with no explicit value defaults to never_publish = 1',
        (int) $freshVal === 1);

    // ── pb_sensitive_keywords() — call the real function, not a re-read ──
    $newKeywords = ['cardiac', 'arrest', 'casualty', 'missing', 'unconscious', 'stroke', 'respiratory', 'seizure'];
    $realKeywords = pb_sensitive_keywords();
    foreach ($newKeywords as $kw) {
        t("pb_sensitive_keywords() includes '{$kw}' (Ron Jones's report)", in_array($kw, $realKeywords, true));
    }
    t('pb_sensitive_keywords() kept every original term (not a replace)',
        in_array('welfare check', $realKeywords, true)
        && in_array('mental health', $realKeywords, true)
        && in_array('overdose', $realKeywords, true));

    // ── pb_sensitive_types_still_full(): the corrected live re-check ──
    $sensitiveExposed = _pbnp_make_type([
        'type'                       => 'zz138np-cardiac-' . uniqid(),
        'description'                => 'Cardiac Arrest response',
        'public_board_never_publish' => 0,
    ]);
    $sensitiveHidden = _pbnp_make_type([
        'type'                       => 'zz138np-stroke-' . uniqid(),
        'description'                => 'Stroke / respiratory distress',
        'public_board_never_publish' => 1,
    ]);
    $stillFull = pb_sensitive_types_still_full();
    $stillFullIds = array_map(function ($r) { return (int) $r['id']; }, $stillFull);

    t('pb_sensitive_types_still_full(): a sensitive type that CAN publish (never_publish=0) IS flagged',
        in_array($sensitiveExposed, $stillFullIds, true));
    t('pb_sensitive_types_still_full(): the same sensitive wording with never_publish=1 is NOT flagged (already hidden, not a false alarm)',
        !in_array($sensitiveHidden, $stillFullIds, true));

    // ═══════════════════════════════════════════════════════════════════
    // Part 3 — THE idempotency guarantee, against the real migration
    // script run a second time as a subprocess.
    // ═══════════════════════════════════════════════════════════════════

    echo "\n--- Migration idempotency (real subprocess, second run) ---\n\n";

    // Simulate "an admin later deliberately opted a type back in" — a row
    // set to never_publish = 0 AFTER the marker already exists (post the
    // first run above).
    $adminChoiceId = _pbnp_make_type([
        'type'                       => 'zz138np-admin-choice-' . uniqid(),
        'public_board_never_publish' => 0,
    ]);
    $beforeSecondRun = (int) db_fetch_value(
        "SELECT `public_board_never_publish` FROM `{$prefix}in_types` WHERE `id` = ?", [$adminChoiceId]
    );
    t('setup: the admin-choice row really is at never_publish = 0 before the second migration run',
        $beforeSecondRun === 0);

    // Re-run the migration — exactly what a later deploy's migration
    // runner does on every push.
    $out2 = @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($migrationScript) . ' 2>&1');
    t('second migration subprocess ran without a fatal error',
        is_string($out2) && stripos($out2, 'Fatal error') === false && stripos($out2, 'Uncaught') === false);
    t('second run took the idempotent skip branch (marker already defaulted)',
        is_string($out2) && stripos($out2, 'already defaulted') !== false);

    $afterSecondRun = (int) db_fetch_value(
        "SELECT `public_board_never_publish` FROM `{$prefix}in_types` WHERE `id` = ?", [$adminChoiceId]
    );
    t('THE GUARANTEE: an admin\'s later never_publish=0 choice survives a second migration run (not re-flipped to 1)',
        $afterSecondRun === 0);

} finally {
    foreach ($createdTypeIds as $id) {
        try { db_query("DELETE FROM `{$prefix}in_types` WHERE `id` = ?", [$id]); } catch (Throwable $e) {}
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
