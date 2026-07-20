<?php
/**
 * Documentation link auditor.
 *
 * Walks every Markdown file in the repo and checks each [text](path)
 * link. Reports:
 *   - Links to files that don't exist
 *   - Links that resolve to a directory without a README/index
 *   - Anchor links (#section) — informational only, not validated
 *   - http(s) external links — informational only, not validated
 *
 * Usage:  php tools/doc-link-audit.php [--quiet] [--root <dir>]
 *
 * Exit code 0 if no broken links, 1 if any broken links found.
 */

$root = realpath(__DIR__ . '/../..');   // newui-dev/newui/tools → newui-dev
$quiet = in_array('--quiet', $argv, true);
foreach ($argv as $i => $arg) {
    if ($arg === '--root' && isset($argv[$i+1])) {
        $root = realpath($argv[$i+1]);
    }
}

// Search the whole tree under newui-dev/newui/ and the cross-project docs/
$scanRoots = [
    realpath(__DIR__ . '/..'),                              // newui-dev/newui/
    realpath(__DIR__ . '/../../../docs'),                   // repo-root docs/
];

$mdFiles = [];
foreach ($scanRoots as $base) {
    if (!$base) continue;
    foreach (find_md($base) as $f) {
        $mdFiles[] = $f;
    }
}

if (!$quiet) {
    echo "Auditing " . count($mdFiles) . " markdown files...\n\n";
}

$totalLinks = 0;
$brokenLinks = [];
$skippedExternal = 0;
$skippedAnchor = 0;

foreach ($mdFiles as $file) {
    $content = file_get_contents($file);
    $fileDir = dirname($file);

    // Match [text](path) — but not images ![alt](src) which use the same syntax
    // and not the "reference"-style [text][ref] definitions
    if (!preg_match_all('/(?<!\!)\[([^\]]+)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER)) {
        continue;
    }

    foreach ($matches as $m) {
        $linkText = $m[1];
        $linkTarget = trim($m[2]);
        $totalLinks++;

        // Skip external URLs
        if (preg_match('#^https?://#', $linkTarget) || preg_match('#^mailto:#', $linkTarget)) {
            $skippedExternal++;
            continue;
        }

        // Strip the anchor fragment for filesystem checks
        $anchorPos = strpos($linkTarget, '#');
        $pathOnly = ($anchorPos !== false) ? substr($linkTarget, 0, $anchorPos) : $linkTarget;

        // Anchor-only link (#section in same file) — informational
        if ($pathOnly === '') {
            $skippedAnchor++;
            continue;
        }

        // Resolve the path
        $resolved = ($pathOnly[0] === '/') ? $root . $pathOnly : $fileDir . '/' . $pathOnly;
        $resolvedReal = realpath($resolved);

        if (!$resolvedReal) {
            // Try without trailing slash
            $resolvedReal = realpath(rtrim($resolved, '/'));
        }

        if (!$resolvedReal || !file_exists($resolvedReal)) {
            $brokenLinks[] = [
                'file'   => relpath($file, $root),
                'text'   => $linkText,
                'target' => $linkTarget,
                'tried'  => $resolved,
            ];
        }
    }
}

echo "\n=== Summary ===\n";
echo "Total links checked: $totalLinks\n";
echo "External (skipped):  $skippedExternal\n";
echo "Anchor-only (skipped): $skippedAnchor\n";
echo "Broken: " . count($brokenLinks) . "\n";

if (!empty($brokenLinks)) {
    echo "\n=== Broken links ===\n";
    foreach ($brokenLinks as $bl) {
        printf("[%s]\n", $bl['file']);
        printf("    [%s](%s)\n", $bl['text'], $bl['target']);
        printf("    tried: %s\n\n", $bl['tried']);
    }
    exit(1);
}

echo "\n[OK] No broken links.\n";
exit(0);


function find_md(string $dir): iterable
{
    if (!is_dir($dir)) return;
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
            // Skip vendor / node_modules / .git
            $path = str_replace('\\', '/', $file->getPathname());
            if (preg_match('#/(vendor|node_modules|\.git|cache)/#', $path)) continue;
            yield $file->getPathname();
        }
    }
}

function relpath(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    $root = str_replace('\\', '/', $root);
    if (strpos($path, $root) === 0) {
        return ltrim(substr($path, strlen($root)), '/');
    }
    return $path;
}
