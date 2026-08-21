<?php
/**
 * NewUI — Lookup Data Updater
 *
 * Downloads and imports FCC license data (Amateur + GMRS) and US zip codes
 * into the local database for offline callsign and address lookups.
 *
 * Usage:
 *   php tools/update-lookup-data.php                 # Update all three datasets
 *   php tools/update-lookup-data.php --amateur       # Amateur radio only
 *   php tools/update-lookup-data.php --gmrs          # GMRS only
 *   php tools/update-lookup-data.php --zipcodes      # Zip codes only
 *   php tools/update-lookup-data.php --amateur --gmrs  # Multiple datasets
 *   php tools/update-lookup-data.php --help          # Show help
 *
 * Data Sources (sizes verified 2026-08-20 against openises/TicketsCAD #93 —
 * the docblock previously understated these by roughly half):
 *   Amateur: https://data.fcc.gov/download/pub/uls/complete/l_amat.zip   (~189MB compressed)
 *   GMRS:    https://data.fcc.gov/download/pub/uls/complete/l_gmrs.zip   (~54MB compressed)
 *   Zips:    https://download.geonames.org/export/zip/US.zip             (~1MB compressed)
 *
 * Files are downloaded to tools/data/, extracted, imported, then cleaned up.
 * Run monthly or weekly to keep data current.
 *
 * Requirements:
 *   - PHP with curl extension
 *   - PHP zip extension (ZipArchive) — tried first for extraction, purely in
 *     -process, so it works even when a hardened host blocks the exec()
 *     family via `disable_functions`. Falls back to the `unzip` command
 *     (Git Bash) or PowerShell's Expand-Archive (Windows) ONLY when
 *     ZipArchive itself is unavailable — and that fallback additionally
 *     needs exec() to be allowed, which it will say plainly if it is not.
 *   - PHP with proc_open() enabled — used to run the amateur/GMRS/zip-code
 *     importer as a subprocess while streaming its live progress to this
 *     terminal (a multi-minute import with no visible output looks hung).
 *     proc_open() is a SEPARATE function from popen()/exec() and is not part
 *     of any commonly-hardened disable_functions preset, but is checked with
 *     function_exists() and explained plainly if it is ever disabled too,
 *     rather than crashing with an undefined-function fatal (openises/
 *     TicketsCAD #93 follow-up — the original report's exact php.ini also
 *     listed `popen` in disable_functions, which this file used to call
 *     unconditionally for exactly this streaming step).
 *   - ~1.2GB free disk space at peak during import (the downloaded archive
 *     and its extracted .dat files coexist briefly before cleanup; the
 *     amateur dataset alone extracts to EN.dat ~209MB + HD.dat ~228MB
 *     alongside its own still-present ~189MB .zip)
 */

// Increase limits for large imports

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

ini_set('memory_limit', '512M');
set_time_limit(0);

require_once __DIR__ . '/../config.php';

// ═══════════════════════════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════════════════════════

$SOURCES = [
    'amateur' => [
        'url'   => 'https://data.fcc.gov/download/pub/uls/complete/l_amat.zip',
        'label' => 'FCC Amateur Radio Licenses',
        'zip'   => 'l_amat.zip',
        'dir'   => 'l_amat',
    ],
    'gmrs' => [
        'url'   => 'https://data.fcc.gov/download/pub/uls/complete/l_gmrs.zip',
        'label' => 'FCC GMRS Licenses',
        'zip'   => 'l_gmrs.zip',
        'dir'   => 'l_gmrs',
    ],
    'zipcodes' => [
        'url'   => 'https://download.geonames.org/export/zip/US.zip',
        'label' => 'US Zip Codes (GeoNames)',
        'zip'   => 'US.zip',
        'dir'   => 'US_zips',
    ],
];

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';

// ═══════════════════════════════════════════════════════════════
// PARSE ARGUMENTS
// ═══════════════════════════════════════════════════════════════

$tasks = [];
$showHelp = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--help' || $arg === '-h') { $showHelp = true; break; }
    elseif ($arg === '--amateur')  $tasks[] = 'amateur';
    elseif ($arg === '--gmrs')     $tasks[] = 'gmrs';
    elseif ($arg === '--zipcodes') $tasks[] = 'zipcodes';
    elseif ($arg === '--all')      $tasks = ['amateur', 'gmrs', 'zipcodes'];
}

// Default: update all if no flags given
if (empty($tasks) && !$showHelp) {
    $tasks = ['amateur', 'gmrs', 'zipcodes'];
}

if ($showHelp) {
    echo <<<HELP
╔══════════════════════════════════════════════════════════════╗
║          NewUI — Lookup Data Updater                        ║
╚══════════════════════════════════════════════════════════════╝

Downloads FCC license data and US zip codes for offline lookups.

USAGE:
  php tools/update-lookup-data.php [OPTIONS]

OPTIONS:
  --amateur     Download & import FCC Amateur Radio licenses (~189MB)
  --gmrs        Download & import FCC GMRS licenses (~54MB)
  --zipcodes    Download & import US zip codes (~1MB)
  --all         All of the above (default if no flags given)
  --help, -h    Show this help message

EXAMPLES:
  php tools/update-lookup-data.php              # Update everything
  php tools/update-lookup-data.php --amateur    # Just amateur data
  php tools/update-lookup-data.php --gmrs --zipcodes  # GMRS + zips

DATA SOURCES:
  Amateur: https://data.fcc.gov/download/pub/uls/complete/l_amat.zip
  GMRS:    https://data.fcc.gov/download/pub/uls/complete/l_gmrs.zip
  Zips:    https://download.geonames.org/export/zip/US.zip

SCHEDULE:
  Run monthly (or weekly) to keep data current.
  FCC updates their bulk data daily; GeoNames updates periodically.

HELP;
    exit(0);
}

// ═══════════════════════════════════════════════════════════════
// UTILITY FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function banner($text) {
    $line = str_repeat('═', 60);
    echo "\n$line\n  $text\n$line\n\n";
}

function step($text) {
    echo "  ▸ $text\n";
}

function ok($text) {
    echo "  ✓ $text\n";
}

function warn($text) {
    echo "  ⚠ $text\n";
}

function fail($text) {
    echo "  ✗ $text\n";
}

/**
 * Download a file using PHP curl
 */
function downloadFile($url, $destPath) {
    step("Downloading " . basename($destPath) . " ...");
    echo "    URL: $url\n";

    $fp = fopen($destPath, 'wb');
    if (!$fp) {
        fail("Cannot open $destPath for writing");
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_TIMEOUT         => 600,  // 10 minutes
        CURLOPT_CONNECTTIMEOUT  => 30,
        CURLOPT_SSL_VERIFYPEER  => false, // Some XAMPP setups lack CA certs
        CURLOPT_USERAGENT       => 'NewUI-CAD/4.0 (lookup-data-updater)',
        CURLOPT_NOPROGRESS      => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
            if ($dlTotal > 0) {
                $pct = round(($dlNow / $dlTotal) * 100);
                $mb  = round($dlNow / 1048576, 1);
                $totalMb = round($dlTotal / 1048576, 1);
                echo "\r    Progress: {$mb}MB / {$totalMb}MB ({$pct}%)    ";
            }
            return 0; // non-zero aborts
        },
    ]);

    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    echo "\n"; // newline after progress

    if (!$success || $httpCode >= 400) {
        fail("Download failed (HTTP $httpCode): $error");
        @unlink($destPath);
        return false;
    }

    $size = filesize($destPath);
    ok("Downloaded " . round($size / 1048576, 1) . "MB");
    return true;
}

/**
 * Extract a ZIP file.
 *
 * Order matters here (openises/TicketsCAD #93, reported by @rjonesbsink):
 * PHP's native ZipArchive is tried FIRST, because it runs entirely in-process
 * — no subprocess, so it is unaffected by a hardened host's `disable_functions`
 * policy. The old code tried `unzip`/PowerShell via exec() first and only fell
 * back to ZipArchive as a last resort, so a host that blocks the exec() family
 * (a common hardening setting, unrelated to whether unzip is installed or the
 * download is intact) died here with a bare "exit 255" and no explanation —
 * calling a disabled function is a fatal "Call to undefined function", which
 * PHP's @ operator does not suppress. See inc/backup.php's
 * backup_create_zip() / api/map-markups.php's KMZ reader for the sibling
 * ZipArchive-first-with-graceful-fallback pattern this mirrors, and
 * tests/test_no_shell_command_execution.php's docblock for the fuller history
 * of this codebase moving off the exec() family for exactly this reason.
 *
 * The shell fallback below still exists for the rare host with no PHP zip
 * extension compiled in — but function_exists('exec') is checked BEFORE
 * calling exec(), both to avoid the fatal error above and to report the real
 * cause plainly instead of "no working extraction method found".
 */
function extractZip($zipPath, $destDir) {
    step("Extracting " . basename($zipPath) . " ...");

    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    // ── 1. PHP ZipArchive — native, in-process, unaffected by disable_functions ──
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            // Phase 43d (Sonar S5042 decompression-bomb mitigation): refuse to
            // extract if the archive's uncompressed payload exceeds 2 GB, or if
            // any individual member would write more than 256 MB. Lookup-data
            // archives (FCC, USGS) are typically 50-300 MB compressed and
            // 200 MB - 1.5 GB uncompressed; these limits leave generous headroom
            // while blocking a tampered upstream from filling the disk.
            $TOTAL_LIMIT  = 2 * 1024 * 1024 * 1024;
            $MEMBER_LIMIT = 256 * 1024 * 1024;
            $RATIO_LIMIT  = 100;            // refuse if any file claims >100x compression
            $totalSize = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) continue;
                $totalSize += (int) $stat['size'];
                if ((int) $stat['size'] > $MEMBER_LIMIT) {
                    $zip->close();
                    fail("Archive member '{$stat['name']}' is " . round($stat['size']/1024/1024) . " MB — refusing to extract (member limit " . round($MEMBER_LIMIT/1024/1024) . " MB)");
                    return false;
                }
                if ($stat['comp_size'] > 0 && ($stat['size'] / max(1, $stat['comp_size'])) > $RATIO_LIMIT) {
                    $zip->close();
                    fail("Archive member '{$stat['name']}' has suspicious compression ratio " . round($stat['size']/$stat['comp_size']) . "x — refusing to extract (decompression-bomb guard)");
                    return false;
                }
            }
            if ($totalSize > $TOTAL_LIMIT) {
                $zip->close();
                fail("Archive total uncompressed size " . round($totalSize/1024/1024/1024, 1) . " GB exceeds " . round($TOTAL_LIMIT/1024/1024/1024) . " GB limit");
                return false;
            }
            $zip->extractTo($destDir);
            $zip->close();
            ok("Extracted with PHP ZipArchive (passed " . round($totalSize/1024/1024) . " MB size + compression-ratio checks)");
            return true;
        }
        warn("PHP ZipArchive could not open the archive (possibly corrupt) — trying a shell extractor");
    } else {
        warn("PHP zip extension (ZipArchive) is not available — trying a shell extractor");
    }

    // ── 2. Shell fallback — only reached when ZipArchive can't do the job ──
    if (!function_exists('exec')) {
        fail("PHP cannot run unzip or PowerShell: disable_functions blocks exec()");
        fail("This host has no working extraction method: the PHP zip extension is " .
             (class_exists('ZipArchive') ? "available but could not open this archive" : "not installed") .
             ", and exec() is disabled by this php.ini's disable_functions setting.");
        fail("Fix: enable the PHP zip extension (extension=zip) so extraction never needs a subprocess, " .
             "or remove exec from disable_functions.");
        return false;
    }

    // Try unzip command (Git Bash on Windows, or native on Linux/macOS)
    $cmd = 'unzip -o ' . escapeshellarg($zipPath) . ' -d ' . escapeshellarg($destDir) . ' 2>&1';
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0) {
        ok("Extracted with unzip");
        return true;
    }

    // Try PowerShell Expand-Archive (Windows native)
    $psCmd = 'powershell -NoProfile -Command "Expand-Archive -Path ' .
             escapeshellarg($zipPath) . ' -DestinationPath ' .
             escapeshellarg($destDir) . ' -Force" 2>&1';
    $output = [];
    exec($psCmd, $output, $returnCode);

    if ($returnCode === 0) {
        ok("Extracted with PowerShell");
        return true;
    }

    fail("Could not extract ZIP — no working extraction method found");
    fail("Neither unzip nor PowerShell's Expand-Archive succeeded; install unzip, " .
         "or enable the PHP zip extension for a subprocess-free extractor");
    return false;
}

/**
 * Run a program as a list of discrete arguments, streaming its combined
 * stdout+stderr to OUR OWN stdout AS IT ARRIVES — not just once at the end —
 * and return its exit code (or null when the subprocess could not be started
 * at all, e.g. proc_open() itself is disabled).
 *
 * ── WHY THIS EXISTS (openises/TicketsCAD #93 follow-up, 2026-08-20) ────────
 *
 * The amateur/GMRS/zip-code importers (import-fcc.php / import-zipcodes.php)
 * used to be run with `popen($cmd, 'r')` + a `while (!feof($handle))
 * fgets($handle)` loop, purely so a dispatcher/admin watching the terminal
 * during a multi-minute import sees live progress instead of silence. But
 * popen() is in the SAME disable_functions family as the exec() this file's
 * extractZip() was already fixed to avoid — the reporter's own php.ini
 * listed `disable_functions = shell_exec, exec, system, passthru, popen`
 * verbatim — so a host that blocks popen() specifically (independent of
 * exec(), since disable_functions entries are independent settings) got
 * past the fixed extraction step, downloaded and extracted cleanly, and then
 * hit the identical "PHP Fatal error: Uncaught Error: Call to undefined
 * function popen()" crash one step later, at import.
 *
 * proc_open() is a DIFFERENT function from popen() and is not part of any
 * commonly-hardened disable_functions preset — but is still guarded with
 * function_exists() below and explained plainly if it is ever disabled too,
 * exactly like extractZip()'s exec() guard, rather than crashing.
 *
 * ── LIVE STREAMING WITHOUT stream_set_blocking() ────────────────────────
 *
 * CLAUDE.md documents (from the Zello proxy + TTS pipe outages) that
 * stream_set_blocking() is a NO-OP on a proc_open PIPE on Windows — it
 * returns false and the stream stays blocking — so a naive `fread()` on a
 * proc_open pipe can wedge forever waiting for bytes that will never come
 * while the child fills a DIFFERENT, undrained pipe's buffer. The fix used
 * throughout this codebase (tts_run_pipe() in inc/tts/engine.php, runPipe()
 * in proxy/ZelloProxyApp.php) is to make stdout/stderr FILES instead of
 * pipes, so nothing can ever block reading OR writing them — but both of
 * those helpers only read the file back ONCE, after the child has fully
 * exited, which would silently drop this function's whole reason for
 * existing (live progress on a multi-minute run).
 *
 * This function keeps the file-based descriptors (so it is exactly as
 * deadlock-proof as tts_run_pipe()/runPipe()) but TAILS the output file
 * while the child is still running: each poll iteration re-opens the file,
 * seeks to the byte offset already printed, reads whatever new bytes have
 * landed, and echoes only complete lines (holding back a trailing partial
 * line until either a newline arrives or the process exits). A 100ms poll
 * interval is not truly push-based streaming, but is indistinguishable from
 * it to a human watching a multi-minute import — and, critically, nothing in
 * this loop can ever block: filesystem reads of a file another process is
 * appending to do not wait for new data the way a pipe read does.
 *
 * No shell is involved — $cmdArgv is an argv-array, handed straight to
 * execvp()/CreateProcess() by proc_open(), so escapeshellarg() is neither
 * needed nor present. See tools/check-schema.php's run_via_proc_open() /
 * tests/test_no_shell_command_execution.php's docblock for the sibling
 * pattern and the fuller history of this codebase moving off the exec()
 * family.
 *
 * @param array $cmdArgv argv-array: [$binary, $arg1, $arg2, ...]
 * @return int|null Exit code, or null if the subprocess could not be
 *                   started (the caller treats this as a failure).
 */
function runStreamingImport(array $cmdArgv) {
    if (!function_exists('proc_open')) {
        fail("PHP cannot run a subprocess: disable_functions blocks proc_open()");
        fail("This host's php.ini disables proc_open() — importing needs SOME way to " .
             "run a child PHP process, and there is no in-process alternative the way " .
             "ZipArchive is for extraction. Remove proc_open (and popen, if also " .
             "listed) from this php.ini's disable_functions.");
        return null;
    }

    $tag  = 'lookupdata_' . getmypid() . '_' . bin2hex(random_bytes(6));
    $fOut = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . $tag . '.out';

    // stdin closed immediately below (these importers read no stdin).
    //
    // stdout and stderr must share ONE already-open resource — NOT two
    // separate ['file', $fOut, 'w'] descriptor specs for the same path. Two
    // independent specs open two independent file positions, so concurrent
    // stdout/stderr writes overwrite each other instead of interleaving
    // (proven the hard way: tests/test_gh93_streaming_import_popen_followup.php's
    // Test A/B first caught this — a child writing to both streams came back
    // with corrupted, partially-overwritten lines). Passing ONE open handle
    // for both descriptor slots makes proc_open dup() that handle for the
    // child, so both streams share its offset and interleave exactly like a
    // real `2>&1` — the same technique tools/check-schema.php's
    // run_via_proc_open() / sql/run_migrations.php's migration_run_argv()
    // already use (a single tmpfile() sink handed to both slots). This
    // function uses a NAMED file instead of an anonymous tmpfile() so it can
    // additionally be tailed from a second, independent read handle while
    // the child is still running (see $drain() below).
    $sink = @fopen($fOut, 'wb');
    if (!$sink) {
        fail("Could not open a temporary file to capture import output");
        return null;
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink];
    $pipes = [];
    $proc = @proc_open($cmdArgv, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fclose($sink);
        @unlink($fOut);
        fail("Could not start the import subprocess");
        return null;
    }
    fclose($pipes[0]); // nothing to write; closing signals EOF to the child at once

    $pos   = 0;
    $carry = '';

    // $sink itself is never read from — reading through the SAME handle used
    // for the child's (dup'd) writes would move the shared offset out from
    // under it. $drain() always opens a FRESH, independent read handle.
    $drain = function () use (&$pos, &$carry, $fOut) {
        clearstatcache(true, $fOut);
        $end = @filesize($fOut);
        if ($end === false || $end <= $pos) return;
        $reader = @fopen($fOut, 'rb');
        if (!$reader) return;
        fseek($reader, $pos, SEEK_SET);
        $chunk = fread($reader, $end - $pos);
        fclose($reader);
        if ($chunk === false || $chunk === '') return;
        $pos = $end;
        $carry .= $chunk;
        while (($nl = strpos($carry, "\n")) !== false) {
            echo "    " . substr($carry, 0, $nl + 1);
            $carry = substr($carry, $nl + 1);
        }
        if (function_exists('flush')) flush();
    };

    // The exit code MUST come from proc_get_status()'s 'exitcode' field, taken
    // at the exact poll where 'running' first flips false — NOT from
    // proc_close()'s return value. Per PHP's own documented behaviour (and
    // confirmed here the hard way — this passed on a Windows PHP-CLI build
    // but failed CI's Linux runner, returning -1 for a clean exit(0) child):
    // "exitcode: only the FIRST call [to proc_get_status()] after the
    // process exits returns the real value; subsequent calls return -1."
    // Polling proc_get_status() in this loop consumes that one real reading;
    // by the time proc_close() is called afterward, the value has already
    // been "spent" and it returns -1 even for a successful child.
    $exitCode = null;
    while (true) {
        $status = proc_get_status($proc);
        $drain(); // read whatever landed since the last poll, before checking exit
        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }
        usleep(100000); // 100ms — file-based, so nothing here can ever block
    }
    proc_close($proc); // reap the process; its return value is NOT the exit code here (see above)
    fclose($sink); // safe to close now — the child (and its dup'd fds) has exited
    $drain(); // final catch-up for bytes written between the last poll and exit
    if ($carry !== '') {
        echo "    " . $carry . "\n"; // trailing line with no terminating newline
        if (function_exists('flush')) flush();
    }
    @unlink($fOut);

    return $exitCode;
}

/**
 * Find a file by name inside a directory (recursive)
 */
function findFile($dir, $filename) {
    // Direct match
    $direct = $dir . DIRECTORY_SEPARATOR . $filename;
    if (is_file($direct)) return $direct;

    // Search subdirectories (one level)
    $subs = glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
    foreach ($subs as $sub) {
        $path = $sub . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) return $path;
    }

    return null;
}

/**
 * Clean up extracted files
 */
function cleanup($zipPath, $extractDir) {
    step("Cleaning up temporary files ...");

    // Remove extracted directory
    if (is_dir($extractDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($extractDir);
    }

    // Remove ZIP file
    if (is_file($zipPath)) {
        @unlink($zipPath);
    }

    ok("Temporary files removed");
}

// Library mode: a test harness defines this constant before require()'ing
// this file, so it gets the functions above (extractZip() in particular, for
// tests/test_gh93_lookup_data_extraction.php) without triggering an actual
// download/import run. Same pattern as OT_CONFIG_LIBRARY_ONLY in
// api/owntracks-config.php.
if (defined('UPDATE_LOOKUP_DATA_LIBRARY_ONLY')) {
    return;
}

// ═══════════════════════════════════════════════════════════════
// MAIN
// ═══════════════════════════════════════════════════════════════

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║          NewUI — Lookup Data Updater                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Tasks: " . implode(', ', $tasks) . "\n";
echo "Data directory: $dataDir\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";

// Ensure data directory exists
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$results = [];

// ── Amateur Radio ───────────────────────────────────────────
if (in_array('amateur', $tasks)) {
    banner('FCC Amateur Radio Licenses');

    $src       = $SOURCES['amateur'];
    $zipPath   = $dataDir . DIRECTORY_SEPARATOR . $src['zip'];
    $extractTo = $dataDir . DIRECTORY_SEPARATOR . $src['dir'];

    $ok = true;

    // Download
    if (!downloadFile($src['url'], $zipPath)) {
        $ok = false;
    }

    // Extract
    if ($ok && !extractZip($zipPath, $extractTo)) {
        $ok = false;
    }

    // Find EN.dat and HD.dat
    if ($ok) {
        $enFile = findFile($extractTo, 'EN.dat');
        $hdFile = findFile($extractTo, 'HD.dat');

        if (!$enFile || !$hdFile) {
            fail("Could not find EN.dat and HD.dat in extracted files");
            $ok = false;
        } else {
            // Use the directory containing the .dat files
            $datDir = dirname($enFile);
            ok("Found data files in: $datDir");

            // Run the import
            step("Running amateur import (this may take several minutes) ...");
            echo "\n";

            $importerPath = __DIR__ . '/import-fcc.php';
            $exitCode = runStreamingImport([PHP_BINARY, $importerPath, 'amateur', $datDir]);
            if ($exitCode === 0) {
                ok("Amateur import complete");
            } elseif ($exitCode === null) {
                fail("Amateur import did not run");
                $ok = false;
            } else {
                fail("Amateur import exited with code $exitCode");
                $ok = false;
            }
        }
    }

    // Cleanup
    cleanup($zipPath, $extractTo);

    $results['amateur'] = $ok;
}

// ── GMRS ────────────────────────────────────────────────────
if (in_array('gmrs', $tasks)) {
    banner('FCC GMRS Licenses');

    $src       = $SOURCES['gmrs'];
    $zipPath   = $dataDir . DIRECTORY_SEPARATOR . $src['zip'];
    $extractTo = $dataDir . DIRECTORY_SEPARATOR . $src['dir'];

    $ok = true;

    if (!downloadFile($src['url'], $zipPath)) {
        $ok = false;
    }

    if ($ok && !extractZip($zipPath, $extractTo)) {
        $ok = false;
    }

    if ($ok) {
        $enFile = findFile($extractTo, 'EN.dat');
        $hdFile = findFile($extractTo, 'HD.dat');

        if (!$enFile || !$hdFile) {
            fail("Could not find EN.dat and HD.dat in extracted files");
            $ok = false;
        } else {
            $datDir = dirname($enFile);
            ok("Found data files in: $datDir");

            step("Running GMRS import ...");
            echo "\n";

            $importerPath = __DIR__ . '/import-fcc.php';
            $exitCode = runStreamingImport([PHP_BINARY, $importerPath, 'gmrs', $datDir]);
            if ($exitCode === 0) {
                ok("GMRS import complete");
            } elseif ($exitCode === null) {
                fail("GMRS import did not run");
                $ok = false;
            } else {
                fail("GMRS import exited with code $exitCode");
                $ok = false;
            }
        }
    }

    cleanup($zipPath, $extractTo);
    $results['gmrs'] = $ok;
}

// ── Zip Codes ───────────────────────────────────────────────
if (in_array('zipcodes', $tasks)) {
    banner('US Zip Codes (GeoNames)');

    $src       = $SOURCES['zipcodes'];
    $zipPath   = $dataDir . DIRECTORY_SEPARATOR . $src['zip'];
    $extractTo = $dataDir . DIRECTORY_SEPARATOR . $src['dir'];

    $ok = true;

    if (!downloadFile($src['url'], $zipPath)) {
        $ok = false;
    }

    if ($ok && !extractZip($zipPath, $extractTo)) {
        $ok = false;
    }

    if ($ok) {
        // GeoNames US.zip extracts to US.txt — tab-delimited, no header row
        $txtFile = findFile($extractTo, 'US.txt');

        if (!$txtFile) {
            fail("Could not find US.txt in extracted files");
            $ok = false;
        } else {
            ok("Found: $txtFile");

            // Run the import — importer auto-detects GeoNames raw format
            step("Running zip code import ...");
            echo "\n";

            $importerPath = __DIR__ . '/import-zipcodes.php';
            $exitCode = runStreamingImport([PHP_BINARY, $importerPath, $txtFile, '--format=geonames']);
            if ($exitCode === 0) {
                ok("Zip code import complete");
            } elseif ($exitCode === null) {
                fail("Zip code import did not run");
                $ok = false;
            } else {
                fail("Zip code import exited with code $exitCode");
                $ok = false;
            }
        }
    }

    cleanup($zipPath, $extractTo);
    $results['zipcodes'] = $ok;
}

// ═══════════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════════

banner('Summary');

$allOk = true;
foreach ($results as $task => $success) {
    $icon = $success ? '✓' : '✗';
    $label = $SOURCES[$task]['label'];
    echo "  $icon $label\n";
    if (!$success) $allOk = false;
}

echo "\nCompleted: " . date('Y-m-d H:i:s') . "\n";

// Show record counts
echo "\nDatabase record counts:\n";
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}fcc_amateur`");
    echo "  Amateur licenses:  " . number_format((int) $count) . "\n";
} catch (Exception $e) {
    echo "  Amateur licenses:  (table not found)\n";
}
try {
    $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}fcc_gmrs`");
    echo "  GMRS licenses:    " . number_format((int) $count) . "\n";
} catch (Exception $e) {
    echo "  GMRS licenses:    (table not found)\n";
}
try {
    $count = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}zipcodes`");
    echo "  Zip codes:         " . number_format((int) $count) . "\n";
} catch (Exception $e) {
    echo "  Zip codes:         (table not found)\n";
}

echo "\n";
exit($allOk ? 0 : 1);
