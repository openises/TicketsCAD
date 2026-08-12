<?php
/**
 * GHSA-x9x6-w4fg-pmcc — move existing Zello recordings out of the web root
 *
 * Recordings made before this fix live in cache/zello-audio/, which the web
 * server has always served directly. proxy/ZelloProxyApp.php now writes new
 * recordings to a private sibling directory (see inc/zello_audio_dir.php),
 * but that alone does nothing for files already on disk -- they stay
 * reachable by URL until they're actually moved. This does the one-time
 * move; api/zello-audio.php's fallback (checking the legacy path second)
 * means playback keeps working throughout, both before and after.
 *
 * Idempotent -- safe to re-run. Only moves a file if it exists at the
 * legacy path and does NOT already exist at the private one (never
 * overwrites). Does not touch cache/zello-images/ -- that directory is not
 * in scope for this advisory.
 *
 * Usage:
 *   php sql/run_zello_audio_relocate.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/zello_audio_dir.php';

echo "Zello audio relocation (GHSA-x9x6-w4fg-pmcc)\n";
echo "==============================================\n\n";

$legacy  = zello_audio_dir_legacy();
$private = zello_audio_dir();

if (!is_dir($legacy)) {
    echo "No legacy directory at {$legacy} -- nothing to move.\n";
    exit(0);
}

if (!is_dir($private)) {
    if (!@mkdir($private, 0775, true)) {
        fwrite(STDERR, "ERROR: could not create {$private}\n");
        exit(1);
    }
    echo "Created {$private}\n";
}

$entries = @scandir($legacy);
if (!is_array($entries)) {
    fwrite(STDERR, "ERROR: could not list {$legacy}\n");
    exit(1);
}

$moved = 0;
$skipped = 0;
$failed = 0;

foreach ($entries as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }
    // Only move actual recordings. The legacy directory also carries
    // web.config (the defense-in-depth deny for whatever's left here) and
    // .gitkeep -- an earlier version of this script moved EVERY file it
    // found and relocated web.config right along with the recordings,
    // silently undoing the deny it exists to provide. Never repeat that.
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['ogg', 'webm'], true)) {
        continue;
    }
    $from = $legacy . '/' . $name;
    if (!is_file($from)) {
        continue; // skip subdirectories / non-files defensively
    }
    $to = $private . '/' . $name;
    if (is_file($to)) {
        $skipped++;
        continue;
    }
    if (@rename($from, $to)) {
        $moved++;
    } else {
        fwrite(STDERR, "  [fail] could not move {$name}\n");
        $failed++;
    }
}

echo "Moved {$moved} file(s); {$skipped} already present at destination; {$failed} failed.\n";
if ($failed > 0) {
    echo "Files that failed to move are still readable from the legacy path\n"
       . "(api/zello-audio.php falls back to it), so playback isn't broken --\n"
       . "but they remain in the web-servable directory until moved by hand\n"
       . "or a re-run once the underlying issue (likely a permissions problem) is fixed.\n";
    exit(1);
}
