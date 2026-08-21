<?php
/**
 * Phase 114b3 — personal / shareable console layouts.
 *
 * Drives inc/console-views.php's business logic directly (the established
 * pattern for this codebase's admin-CRUD-UI testing — see test_org_
 * sharing_admin_api.php's own docblock for the rationale: api/console-
 * views.php requires auth.php, so its logic lives in inc/ specifically so
 * it can be exercised here without a session/HTTP round trip).
 *
 * Covers:
 *   1. Schema — console_views.is_shared + its index exist (post-migration).
 *   2. console_component_catalog() — monitor/mute/volume are real now
 *      (future=>false); say stays honestly future=>true.
 *   3. console_view_can_write() — the pure RBAC boundary, all four shapes:
 *      shared+canDesign, shared+!canDesign, personal-owned, personal-NOT-
 *      owned (must read as 404, never reveal existence), missing view.
 *   4. console_view_visible_as_clone_source() — shared always, own
 *      personal always, another user's personal only when is_shared=1.
 *   5. Full CRUD round trip through the REAL writers: create a personal
 *      view, save real strips against a real registry channel, confirm it
 *      appears in console_personal_views_for_user() and NOT in
 *      console_shared_views(); toggle is_shared and confirm console_
 *      shared_personal_views() (excluding the owner) picks it up; clone it
 *      into a second personal view via based_on_view_id and confirm the
 *      strips were copied without mutating the original; delete cleans up
 *      both the view and its strips.
 *   6. api/console-views.php wiring — the file requires auth.php, gates
 *      GET on screen.console, and does NOT gate the whole POST handler
 *      behind a blanket console.design check any more (personal-view
 *      mutations must reach console_view_can_write() instead).
 *
 * @requires-db
 * Usage: php tests/test_console_personal_layouts.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/channel_registry.php';
require_once __DIR__ . '/../inc/console-views.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 114b3 — personal / shareable console layouts ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — schema
// ═══════════════════════════════════════════════════════════════════════
t('console_views.is_shared column exists', console_views_column_exists('console_views', 'is_shared'));
$idxExists = (int) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_owner_shared'",
    [$prefix . 'console_views']
);
t('idx_owner_shared index exists', $idxExists > 0);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — component catalog: monitor/mute/volume real, say honestly future
// ═══════════════════════════════════════════════════════════════════════
$cat = console_component_catalog();
t('monitor component is no longer future (real backend, Phase 114b3)', $cat['monitor']['future'] === false);
t('mute component is no longer future', $cat['mute']['future'] === false);
t('volume component is no longer future', $cat['volume']['future'] === false);
t('say (TTS) component STAYS future=true — no backend exists yet, stays honest', $cat['say']['future'] === true);
t('ptt/text/label/led/activity remain future=false (unaffected by this phase)',
    $cat['ptt']['future'] === false && $cat['text']['future'] === false && $cat['label']['future'] === false
    && $cat['led']['future'] === false && $cat['activity']['future'] === false);

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — console_view_can_write() — the pure RBAC boundary
// ═══════════════════════════════════════════════════════════════════════
$sharedRow = ['id' => 1, 'owner_user_id' => null, 'name' => 'Shared X'];
$myPersonalRow = ['id' => 2, 'owner_user_id' => 900001500, 'name' => 'My Personal'];
$otherPersonalRow = ['id' => 3, 'owner_user_id' => 900001501, 'name' => 'Their Personal'];

$r = console_view_can_write($sharedRow, 900001500, true);
t('shared view + caller HAS console.design -> allowed', $r['ok'] === true);
$r = console_view_can_write($sharedRow, 900001500, false);
t('shared view + caller LACKS console.design -> 403, not silently allowed', $r['ok'] === false && $r['status'] === 403);

$r = console_view_can_write($myPersonalRow, 900001500, false);
t('personal view OWNED by the caller -> allowed WITHOUT console.design (the whole point of b3)', $r['ok'] === true);
$r = console_view_can_write($myPersonalRow, 900001500, true);
t('personal view owned by the caller -> allowed even when they ALSO have console.design (irrelevant to personal)', $r['ok'] === true);

$r = console_view_can_write($otherPersonalRow, 900001500, true);
t("ANOTHER user's personal view -> 404 even for a console.design holder (never reveal it exists, never let admin edit someone's personal layout)",
    $r['ok'] === false && $r['status'] === 404);

$r = console_view_can_write(null, 900001500, true);
t('no such view -> 404', $r['ok'] === false && $r['status'] === 404);

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — console_view_visible_as_clone_source()
// ═══════════════════════════════════════════════════════════════════════
t('a shared view is always a visible clone source',
    console_view_visible_as_clone_source($sharedRow, 900001500) === true);
t("the caller's OWN personal view is always visible",
    console_view_visible_as_clone_source($myPersonalRow, 900001500) === true);
$otherSharedFalse = $otherPersonalRow; $otherSharedFalse['is_shared'] = 0;
t("another user's personal view NOT marked is_shared -> not a visible clone source",
    console_view_visible_as_clone_source($otherSharedFalse, 900001500) === false);
$otherSharedTrue = $otherPersonalRow; $otherSharedTrue['is_shared'] = 1;
t("another user's personal view WITH is_shared=1 -> visible clone source",
    console_view_visible_as_clone_source($otherSharedTrue, 900001500) === true);
t('null source -> never visible', console_view_visible_as_clone_source(null, 900001500) === false);

// ═══════════════════════════════════════════════════════════════════════
// Part 5 — full CRUD round trip through the real writers
// ═══════════════════════════════════════════════════════════════════════
$ch = channel_get('eventbus:main'); // always present per test_channel_registry.php
$createdViewIds = [];

try {
    if (!$ch) {
        echo "SKIP: eventbus:main channel not found — run channel_registry_sync() first (see tests/test_channel_registry.php)\n";
    } else {
        $ownerA = 900001510; // fake user ids, well outside any real range
        $ownerB = 900001511;

        // --- create a personal view owned by A ---
        $r = console_view_create(['name' => '_test_personal_a', 'icon' => '', 'ownerUserId' => $ownerA, 'createdBy' => $ownerA]);
        t('console_view_create: personal view created', $r['ok'] === true && $r['id'] > 0);
        $viewA = $r['id'];
        $createdViewIds[] = $viewA;

        $mine = console_personal_views_for_user($ownerA);
        $foundMine = false;
        foreach ($mine as $v) { if ($v['id'] === $viewA) { $foundMine = true; } }
        t("console_personal_views_for_user(ownerA) includes the new view", $foundMine);

        $shared = console_shared_views();
        $leakedIntoShared = false;
        foreach ($shared as $v) { if ($v['id'] === $viewA) { $leakedIntoShared = true; } }
        t('a personal view NEVER appears in console_shared_views()', !$leakedIntoShared);

        // --- save real strips against it ---
        $strips = [[
            'channel_id' => $ch['id'],
            'layout' => ['x' => 0, 'y' => 0, 'w' => 3, 'h' => 14],
            'overrides' => ['label' => 'Test Strip'],
            'components' => [['type' => 'label', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 3]],
        ]];
        $r = console_view_save_strips($viewA, $strips);
        t('console_view_save_strips: personal view accepts a valid strip', $r['ok'] === true && $r['count'] === 1);

        $mineAfter = console_personal_views_for_user($ownerA);
        $stripCount = 0;
        foreach ($mineAfter as $v) { if ($v['id'] === $viewA) { $stripCount = count($v['strips']); } }
        t('the saved strip round-trips back through console_personal_views_for_user()', $stripCount === 1);

        // --- is_shared toggle ---
        $r = console_view_update($viewA, ['is_shared' => true]);
        t('console_view_update: is_shared toggled on', $r['ok'] === true);

        $browsable = console_shared_personal_views($ownerB);
        $foundBrowsable = false;
        foreach ($browsable as $v) { if ($v['id'] === $viewA) { $foundBrowsable = true; } }
        t('console_shared_personal_views(ownerB) now surfaces ownerA\'s view once is_shared=1', $foundBrowsable);

        $browsableSelf = console_shared_personal_views($ownerA);
        $selfLeak = false;
        foreach ($browsableSelf as $v) { if ($v['id'] === $viewA) { $selfLeak = true; } }
        t("console_shared_personal_views() NEVER includes the caller's own views (that's my_views' job)", !$selfLeak);

        $r = console_view_update($viewA, ['is_shared' => false]);
        $browsable2 = console_shared_personal_views($ownerB);
        $stillThere = false;
        foreach ($browsable2 as $v) { if ($v['id'] === $viewA) { $stillThere = true; } }
        t('turning is_shared back off removes it from the browse list', !$stillThere);
        // Leave is_shared ON for the clone test below.
        console_view_update($viewA, ['is_shared' => true]);

        // --- clone: ownerB clones ownerA's shared personal view ---
        $srcRow = console_view_get_row($viewA);
        t('ownerB may legally clone it (console_view_visible_as_clone_source)',
            console_view_visible_as_clone_source($srcRow, $ownerB) === true);
        $r = console_view_create([
            'name' => '_test_personal_b_clone', 'icon' => '', 'ownerUserId' => $ownerB,
            'createdBy' => $ownerB, 'basedOnViewId' => $viewA,
        ]);
        t('clone create: succeeded', $r['ok'] === true);
        $viewB = $r['id'];
        $createdViewIds[] = $viewB;
        t('clone create: copied exactly 1 strip from the source', $r['strips_copied'] === 1);

        $mineB = console_personal_views_for_user($ownerB);
        $clonedStripCount = -1; $clonedLabel = null;
        foreach ($mineB as $v) {
            if ($v['id'] === $viewB) {
                $clonedStripCount = count($v['strips']);
                $clonedLabel = $v['strips'][0]['overrides']['label'] ?? null;
            }
        }
        t('the clone owns its OWN copy of the strip (count matches)', $clonedStripCount === 1);
        t('the clone copied the override content too', $clonedLabel === 'Test Strip');

        // Mutate the clone's strips and confirm the ORIGINAL is untouched
        // (clone is a real copy, not a reference/alias).
        console_view_save_strips($viewB, [[
            'channel_id' => $ch['id'],
            'layout' => ['x' => 0, 'y' => 0, 'w' => 3, 'h' => 14],
            'overrides' => ['label' => 'Mutated In Clone'],
            'components' => [],
        ]]);
        $origAfterMutate = console_personal_views_for_user($ownerA);
        $origLabel = null;
        foreach ($origAfterMutate as $v) {
            if ($v['id'] === $viewA) { $origLabel = $v['strips'][0]['overrides']['label'] ?? null; }
        }
        t("mutating the CLONE's strips never touches the ORIGINAL view's strips", $origLabel === 'Test Strip');

        // --- delete cleans up strips too ---
        $r = console_view_delete($viewB);
        t('console_view_delete: ok', $r['ok'] === true);
        $stripsLeft = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}console_view_strips` WHERE view_id = ?", [$viewB]
        );
        t('deleting a view deletes its strips too (no orphaned rows)', $stripsLeft === 0);
        $createdViewIds = array_values(array_diff($createdViewIds, [$viewB]));
    }
} finally {
    // Cleanup — never leaves throwaway fixture rows behind regardless of
    // which assertion above failed.
    foreach ($createdViewIds as $vid) {
        try { db_query("DELETE FROM `{$prefix}console_view_strips` WHERE view_id = ?", [$vid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}console_views` WHERE id = ?", [$vid]); } catch (Throwable $e) {}
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Part 6 — api/console-views.php wiring guards
// ═══════════════════════════════════════════════════════════════════════
$api = (string) @file_get_contents(__DIR__ . '/../api/console-views.php');
t('api/console-views.php requires auth.php', strpos($api, "require_once __DIR__ . '/auth.php'") !== false);
t('api/console-views.php gates on screen.console (file-wide)', strpos($api, "rbac_can('screen.console')") !== false);
t('api/console-views.php delegates its RBAC boundary to console_view_can_write() '
    . '(the pure function Part 3 above drives directly) instead of re-deriving the logic inline',
    strpos($api, 'console_view_can_write(') !== false);
t('api/console-views.php requires inc/console-views.php (the extracted, directly-testable logic)',
    strpos($api, "require_once __DIR__ . '/../inc/console-views.php'") !== false);
// THE regression this file exists to prevent: a blanket "every POST action
// needs console.design" gate would silently re-close the personal layer
// this whole phase built. Assert the create action does NOT unconditionally
// demand it — only the non-personal branch does.
t('create action checks console.design ONLY for non-personal (shared) creates, not unconditionally',
    preg_match('/if\s*\(!\$personal\s*&&\s*!\$canDesign\)/', $api) === 1);
t('csrf_verify() still gated on every POST (personal-view mutations are not exempt from CSRF)',
    strpos($api, 'csrf_verify(') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
