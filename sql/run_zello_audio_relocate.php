<?php
/**
 * GHSA-x9x6-w4fg-pmcc — move existing Zello recordings to the current,
 * platform-aware private directory (see inc/zello_audio_dir.php for the
 * full history — this is round 2 of a two-round fix).
 *
 * Moves from BOTH earlier locations, because an install can be sitting in
 * either one depending on when it last updated:
 *   - round 1: cache/zello-audio/ (inside the web root, always served)
 *   - round 2: a sibling of the app root (safe on POSIX, but INSIDE IIS's
 *     Default Web Site on a stock Windows install — the exact exposure
 *     @rjonesbsink reported after running round 2's version of this script)
 *
 * Idempotent and safe to re-run any number of times, on any install, in any
 * state: only moves a file that exists at a source and does NOT already
 * exist at the destination (never overwrites), and hardens every directory
 * it touches — including the sources, so files left behind after a failed
 * move (e.g. a permissions problem) are still fenced rather than exposed.
 *
 * Usage:
 *   php sql/run_zello_audio_relocate.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/zello_audio_dir.php';

echo "Zello audio relocation (GHSA-x9x6-w4fg-pmcc)\n";
echo "==============================================\n\n";

$destination = zello_audio_dir();
if (!is_dir($destination)) {
    if (!@mkdir($destination, 0775, true)) {
        fwrite(STDERR, "ERROR: could not create {$destination}\n");
        exit(1);
    }
    echo "Created {$destination}\n";
}
zello_audio_harden_dir($destination);
echo "Hardened {$destination} (deny rules written or already present)\n\n";

/**
 * Move every recording found directly in $source into $destination.
 * Never touches web.config/.gitkeep/subdirectories — those aren't
 * recordings, and an earlier version of this script that moved EVERY file
 * it found relocated web.config right along with the recordings, silently
 * undoing the deny it exists to provide. Never repeat that.
 */
function zar_relocate(string $source, string $destination, string $label): array
{
    $moved = 0; $skipped = 0; $failed = 0;

    if (!is_dir($source)) {
        echo "No {$label} directory at {$source} -- nothing to move.\n";
        return [$moved, $skipped, $failed];
    }

    $entries = @scandir($source);
    if (!is_array($entries)) {
        fwrite(STDERR, "ERROR: could not list {$source}\n");
        return [0, 0, 1];
    }

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['ogg', 'webm'], true)) {
            continue;
        }
        $from = $source . '/' . $name;
        if (!is_file($from)) {
            continue; // skip subdirectories / non-files defensively
        }
        $to = $destination . '/' . $name;
        if (is_file($to)) {
            $skipped++;
            continue;
        }
        if (@rename($from, $to)) {
            $moved++;
        } else {
            fwrite(STDERR, "  [fail] could not move {$name} from {$label}\n");
            $failed++;
        }
    }

    echo "{$label}: moved {$moved}; {$skipped} already present at destination; {$failed} failed.\n";

    // Fence the source too, in case anything is left behind (a failed move,
    // or this being a re-run against a location that still has other
    // people's files an operator hasn't cleaned up yet). Cheap, and it is
    // exactly the mitigation the reporter applied by hand before this fix
    // shipped -- "safe wherever the destination turns out to be" applies
    // just as much to a source that still has files in it.
    if (is_dir($source)) {
        zello_audio_harden_dir($source);
    }

    return [$moved, $skipped, $failed];
}

// Round 2's sibling-of-root location. On POSIX this is the SAME directory
// as $destination (zello_audio_dir() returns the identical path there), so
// this pass is a harmless no-op scan of the destination against itself —
// scandir() finds nothing to move because every file already "exists at
// destination". On Windows it is round 2's exposed location, and this is
// the pass that rescues files like the reporter's 210 recordings onward to
// the now-safe %ProgramData% location.
[$m1, $s1, $f1] = zar_relocate(zello_audio_dir_sibling_legacy(), $destination, 'round-2 sibling location');

// Round 1's in-tree location, inside the web root.
[$m2, $s2, $f2] = zar_relocate(zello_audio_dir_legacy(), $destination, 'round-1 in-tree location');

$moved = $m1 + $m2;
$skipped = $s1 + $s2;
$failed = $f1 + $f2;

echo "\nTotal: moved {$moved} file(s); {$skipped} already present; {$failed} failed.\n";
if ($failed > 0) {
    echo "Files that failed to move are still readable from their source\n"
       . "(api/zello-audio.php falls back to both legacy locations), so playback\n"
       . "isn't broken -- and the source directory has been hardened with deny\n"
       . "rules regardless. Re-run once the underlying issue (likely a permissions\n"
       . "problem) is fixed to finish the move.\n";
    exit(1);
}
