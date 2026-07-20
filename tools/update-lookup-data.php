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
 * Data Sources:
 *   Amateur: https://data.fcc.gov/download/pub/uls/complete/l_amat.zip   (~90MB)
 *   GMRS:    https://data.fcc.gov/download/pub/uls/complete/l_gmrs.zip   (~15MB)
 *   Zips:    https://download.geonames.org/export/zip/US.zip             (~2MB)
 *
 * Files are downloaded to tools/data/, extracted, imported, then cleaned up.
 * Run monthly or weekly to keep data current.
 *
 * Requirements:
 *   - PHP with curl extension
 *   - unzip command (Git Bash) or PowerShell (Windows)
 *   - ~500MB free disk space during import (cleaned up after)
 */

// Increase limits for large imports
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
  --amateur     Download & import FCC Amateur Radio licenses (~90MB)
  --gmrs        Download & import FCC GMRS licenses (~15MB)
  --zipcodes    Download & import US zip codes (~2MB)
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
 * Extract a ZIP file
 */
function extractZip($zipPath, $destDir) {
    step("Extracting " . basename($zipPath) . " ...");

    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    // Try unzip command first (Git Bash on Windows)
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

    // Try PHP ZipArchive as last resort
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
    }

    fail("Could not extract ZIP — no working unzip method found");
    fail("Install unzip or enable PHP zip extension");
    return false;
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
            $importCmd = escapeshellarg(PHP_BINARY) . ' ' .
                         escapeshellarg(__DIR__ . '/import-fcc.php') . ' amateur ' .
                         escapeshellarg($datDir) . ' 2>&1';
            echo "\n";

            $handle = popen($importCmd, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    $line = fgets($handle);
                    if ($line !== false) echo "    " . $line;
                }
                $exitCode = pclose($handle);
                if ($exitCode === 0) {
                    ok("Amateur import complete");
                } else {
                    fail("Amateur import exited with code $exitCode");
                    $ok = false;
                }
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
            $importCmd = escapeshellarg(PHP_BINARY) . ' ' .
                         escapeshellarg(__DIR__ . '/import-fcc.php') . ' gmrs ' .
                         escapeshellarg($datDir) . ' 2>&1';
            echo "\n";

            $handle = popen($importCmd, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    $line = fgets($handle);
                    if ($line !== false) echo "    " . $line;
                }
                $exitCode = pclose($handle);
                if ($exitCode === 0) {
                    ok("GMRS import complete");
                } else {
                    fail("GMRS import exited with code $exitCode");
                    $ok = false;
                }
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
            $importCmd = escapeshellarg(PHP_BINARY) . ' ' .
                         escapeshellarg(__DIR__ . '/import-zipcodes.php') . ' ' .
                         escapeshellarg($txtFile) . ' --format=geonames 2>&1';
            echo "\n";

            $handle = popen($importCmd, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    $line = fgets($handle);
                    if ($line !== false) echo "    " . $line;
                }
                $exitCode = pclose($handle);
                if ($exitCode === 0) {
                    ok("Zip code import complete");
                } else {
                    fail("Zip code import exited with code $exitCode");
                    $ok = false;
                }
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
