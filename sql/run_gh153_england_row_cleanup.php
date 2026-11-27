<?php
/**
 * GH#153 follow-up (panel 2026-10-20, Q1) — remove the stray "England" / "UK"
 * row from `states_translator`, but ONLY on an install where doing so is safe
 * and unambiguous.
 *
 * WHERE THE ROW CAME FROM. Before the GH#153 build, both sql/base_schema.sql
 * and sql/run_phase84_states_seed.php seeded (51, 'England', 'UK') beside the
 * 50 US states and DC. It is no longer seeded; a fresh install never has it.
 * Existing installs keep it until an administrator removes it — and most never
 * will, because it is one harmless extra dropdown choice. But it collides with
 * the ISO code for the Indian state Uttarakhand (also "UK"), so leaving it is
 * not free either.
 *
 * WHEN THIS MIGRATION REMOVES IT. All three must hold:
 *   (a) the row is still EXACTLY name='England', code='UK' (an admin who
 *       renamed or recoded it has made it their own — leave it alone);
 *   (b) the list was never edited through the Settings panel
 *       (states_list_customized is false/unset) — on a customised list the
 *       administrator owns every row and we touch nothing;
 *   (c) NO saved record anywhere references the code 'UK', across every
 *       state-bearing column on this install, soft-deleted rows INCLUDED.
 *       states_usage() (inc/states.php) is the single source of that count, so
 *       this migration checks exactly the columns the Settings page's own
 *       preview and per-row delete check — nothing drifts.
 *
 * If any condition fails this migration changes NOTHING: the States / Provinces
 * admin page and the System Health check surface a notice with the usage count
 * and a Remove button (the normal, audited delete path) so a person decides.
 *
 * Idempotent: a second run finds no England/UK row and does nothing.
 * Schema-resilient: if `states_translator` does not exist yet, it is a clean
 * no-op. Verifies its own outcome and exits non-zero only on a real failure.
 *
 * Usage: php sql/run_gh153_england_row_cleanup.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/states.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'states_translator';

echo "GH#153 — conditional England/UK row cleanup\n";
echo "===========================================\n\n";

try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?",
        [$table]
    );
    if (!$exists) {
        echo "[SKIP] {$table} does not exist yet — nothing to clean up.\n";
        echo "\nDone.\n";
        return;
    }

    // (a) Is the stray row still EXACTLY England / UK?
    $rows = db_fetch_all(
        'SELECT `id`, `code`, `name` FROM ' . db_table('states_translator')
        . " WHERE `code` = 'UK' AND `name` = 'England'"
    );
    if (!$rows) {
        echo "[SKIP] no England/UK row present (fresh install, or it was already removed/edited).\n";
        echo "\nDone.\n";
        return;
    }

    // (b) Has the list ever been edited through the panel?
    if (states_is_customized()) {
        echo "[KEEP] the England/UK row is present, but the list has been customised "
            . "(states_list_customized is set) — leaving every row as the administrator left it.\n";
        echo "       The States / Provinces page and System Health offer a Remove button.\n";
        echo "\nDone.\n";
        return;
    }

    // (c) Does any saved record reference the code 'UK' (soft-deleted included)?
    $usage = states_usage(['UK']);
    $total = (int) ($usage['UK']['total'] ?? 0);
    if ($total > 0) {
        $where = [];
        foreach ($usage['UK']['by'] ?? [] as $b) {
            $where[] = $b['label'] . ': ' . $b['count'];
        }
        echo "[KEEP] the England/UK row is present and {$total} saved record(s) use the code UK ("
            . implode(', ', $where) . ").\n";
        echo "       Records are never rewritten; a person removes the row from the States / Provinces "
            . "page (or System Health) when ready.\n";
        echo "\nDone.\n";
        return;
    }

    // All three conditions hold — remove the stray row(s).
    $removed = 0;
    foreach ($rows as $r) {
        db_query('DELETE FROM ' . db_table('states_translator') . ' WHERE `id` = ?', [(int) $r['id']]);
        $removed++;
        echo "[OK] removed the stray England/UK row (id {$r['id']}).\n";
    }

    // Verify the outcome, not just that the DELETE ran.
    $still = (int) db_fetch_value(
        'SELECT COUNT(*) FROM ' . db_table('states_translator') . " WHERE `code` = 'UK' AND `name` = 'England'"
    );
    if ($still !== 0) {
        fwrite(STDERR, "[FAIL] an England/UK row is still present after the cleanup.\n");
        exit(1);
    }
    echo "\nDone. Removed {$removed} England/UK row(s); this install qualified "
        . "(not customised, zero records using UK).\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
