<?php
/**
 * Phase 152 (Console rebuild) — strip templates replace pixel/component
 * placement. `console_view_strips.controls_json` changes MEANING from a
 * positioned-component array ({type,x,y,w,h,props}, Phase 114b3) or a
 * flat control-key list (["activity","voice","text"], Phase 114b2) to a
 * flat "show" template: {show:{ptt,sel,mon,mute,vol,vu,recall,
 * patchchips,text}, hotkey}. `layout_json` (the pixel rectangle) is
 * DEPRECATED IN PLACE, not dropped — this project's own established
 * precedent (Phase 128's user.level, Phase 144's member.team_id):
 * "eliminated from behavior, not necessarily dropped from schema." No
 * column is added or removed by this migration; it is a pure DATA
 * conversion of `controls_json`'s existing content, run once per row.
 * `width`/`position` (own existing columns) and `overrides_json`
 * (label/short_label/color) are UNCHANGED — they already match the
 * template shape's remaining pieces.
 *
 * The migration derives `show` flags from whichever component types a
 * row's OLD controls_json already listed (never invents new capability a
 * row didn't have): ptt<-'ptt' component present, mon/mute/vol<-
 * 'monitor'/'mute'/'volume' present, text<-'text' present. For the
 * legacy Phase 114b2 flat string list, 'voice' maps to ptt+mon+mute+vol
 * together (that format had no way to request them independently) and
 * 'text' maps to text, matching console_components_default()'s own
 * capability->component mapping (inc/console-views.php) that the b2
 * format was always a shorthand for. `sel` (Select) is set true for
 * EVERY migrated row, matching the fact that Select/Simulselect are
 * today universal strip chrome (buildSelectChrome(), injected outside
 * controls_json entirely) — every existing strip already behaves as if
 * it shows Select, so migrating to show.sel=true preserves behavior
 * exactly rather than silently hiding a control every operator already
 * has today.
 *
 * Controls that did not exist before this phase (`vu`, `recall`,
 * `patchchips`, and the `hotkey` field) have no prior signal to migrate
 * from and default to false/null for every migrated row — an admin opts
 * a strip into them explicitly via the new designer.
 *
 * Idempotent: a row whose controls_json is already in the new {show:...}
 * shape (detected by the top-level "show" key) is left untouched, so
 * re-running this migration is always safe.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
require_once 'inc/functions.php';
require_once 'inc/channel_registry.php';
require_once 'inc/console-views.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- console_view_strips strip-template migration\n";
echo "============================================================\n\n";

$exists = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'console_view_strips']
);
if ((int) $exists !== 1) {
    echo "[OK] console_view_strips table not present -- nothing to migrate\n";
    exit(0);
}

$rows = db_fetch_all("SELECT id, channel_id, controls_json FROM `{$prefix}console_view_strips`");
$migrated = 0;
$alreadyNew = 0;
$emptyDefaulted = 0;

foreach ($rows as $row) {
    $raw = $row['controls_json'];
    $decoded = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;

    // Already in the new shape -- leave alone (idempotent re-run).
    if (is_array($decoded) && array_key_exists('show', $decoded)) {
        $alreadyNew++;
        continue;
    }

    $ch = channel_get((int) $row['channel_id']);
    $caps = $ch ? $ch['capabilities'] : [];
    // Shared with console_view_attach_strips()'s own defensive fallback
    // (inc/console-views.php) so the two can never drift apart -- a row
    // this migration hasn't reached yet must render IDENTICALLY to one
    // it has, just computed on the fly instead of persisted.
    $template = console_strip_show_from_legacy_controls($decoded, $caps);

    if (is_array($decoded) && !empty($decoded)) {
        $migrated++;
    } else {
        // NULL/empty controls_json -- nothing to derive from; a fully
        // default template (Select only) is the honest migration, not a
        // guess at what the row "should" have shown.
        $emptyDefaulted++;
    }

    $newJson = json_encode($template);
    db_query("UPDATE `{$prefix}console_view_strips` SET controls_json = ? WHERE id = ?", [$newJson, $row['id']]);
}

echo "[OK] {$alreadyNew} row(s) already in the new template shape (untouched)\n";
echo "[OK] {$migrated} row(s) migrated from an old component/control-key shape\n";
echo "[OK] {$emptyDefaulted} row(s) had no controls_json -- defaulted to Select-only\n";

// Verify (Phase 128 A9b lesson): every row must now decode with a 'show' key.
$stillOld = db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}console_view_strips`
      WHERE controls_json IS NOT NULL AND controls_json NOT LIKE '%\"show\"%'"
);
if ((int) $stillOld > 0) {
    fwrite(STDERR, "[FAIL] verification: {$stillOld} row(s) still not in the new template shape\n");
    exit(1);
}

echo "\n[OK] verified.\n";
exit(0);
