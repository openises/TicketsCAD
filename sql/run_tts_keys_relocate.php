<?php
/**
 * Move text-to-speech API keys out of the web root.
 *
 * Every version before this one kept them in NEWUI_ROOT/keys/tts, inside the
 * application tree (see inc/tts/keys.php for the full history). They now live
 * in the `tts` subdirectory of the keys directory, outside every web root this
 * install can see, and on the app_keys volume under Docker. This script moves
 * whatever is still in the old place.
 *
 * What it does, per key file: copy → verify the bytes → delete the original.
 * Nothing is ever overwritten; if the destination already has a file of that
 * name it wins and the old copy is deleted as stale. Only `*.key` files are
 * touched.
 *
 * Exit status follows the rule every migration here lives by — a run that
 * leaves a key in the web tree must not look like success:
 *   0  nothing to move, or everything moved and verified
 *   1  at least one key could not be moved (still readable from where it is, so
 *      Voice & Speech keeps working; the Status page names what is left)
 * A fresh install has nothing to move and never creates a directory.
 *
 * Run it as the web server account — `sql/run_migrations.php` already is, from
 * tools/deploy.sh — so the new directory is created with the right owner.
 * Created by anyone else, the web server cannot read it and an engine silently
 * fails over to Piper (the same wrong-first-creator trap geocode-cache had).
 *
 * Usage:
 *   php sql/run_tts_keys_relocate.php
 *   php sql/run_tts_keys_relocate.php --dry-run       (list; change nothing)
 *   php sql/run_tts_keys_relocate.php --legacy-dir=DIR --keys-dir=DIR
 *                                                           (unusual layouts)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/tts/keys.php';

$dryRun    = false;
$legacyArg = null;
$keysArg   = null;
foreach (array_slice($argv ?? [], 1) as $a) {
    if ($a === '--dry-run') {
        $dryRun = true;
    } elseif (strpos($a, '--legacy-dir=') === 0) {
        $legacyArg = substr($a, 13);
    } elseif (strpos($a, '--keys-dir=') === 0) {
        $keysArg = substr($a, 11);
    } else {
        fwrite(STDERR, "Unknown argument: {$a}\n");
        exit(2);
    }
}

echo "Text-to-speech key relocation\n";
echo "==========================================\n";

$r = tts_keys_relocate($legacyArg, $keysArg, $dryRun);
echo "From: {$r['legacy_dir']}\n";
echo "To:   {$r['new_dir']}\n\n";

if ($r['same_dir']) {
    echo "[OK] TTS_KEYS_DIR points at the old directory on purpose - nothing to move.\n";
    echo "     That directory is inside the web root; the Status page will say so.\n";
    exit(0);
}

foreach ($r['moved'] as $n) {
    echo ($dryRun ? '[WOULD MOVE] ' : '[MOVED] ') . $n . "\n";
}
foreach ($r['duplicates'] as $n) {
    echo "[OK] {$n} was already at the destination (identical) - removed the old copy\n";
}
foreach ($r['stale'] as $n) {
    echo "[OK] {$n} was already at the destination with newer content - kept that, removed the old copy\n";
}
foreach ($r['errors'] as $e) {
    fwrite(STDERR, "[FAIL] {$e}\n");
}

// Verify the outcome rather than trust the run: a copy-and-delete script that
// printed success while a key was still in the web tree is the failure this
// whole change exists to end. (Skipped for a dry run, which changes nothing.)
$left = $dryRun ? [] : tts_keys_legacy_files($r['legacy_dir']);
if (!empty($left)) {
    fwrite(STDERR, "\n[FAIL] key file(s) still inside the web root: " . implode(', ', $left) . "\n");
    fwrite(STDERR, "       They remain readable from there, so nothing is broken - but they are\n");
    fwrite(STDERR, "       in a directory a web server may publish. Re-run this as the web\n");
    fwrite(STDERR, "       server account (e.g. sudo -u www-data php sql/run_tts_keys_relocate.php).\n");
    exit(1);
}
if (!$r['ok']) {
    exit(1);
}

if (empty($r['moved']) && empty($r['duplicates']) && empty($r['stale'])) {
    echo "[OK] No text-to-speech key files in the old location - nothing to do.\n";
} elseif ($dryRun) {
    echo "\nDry run only - nothing was changed.\n";
} else {
    echo "\n[OK] Done. Text-to-speech keys are outside the web root.\n";
}
exit(0);
