<?php
/**
 * check-doc-links.php — catch links that break when THIS repo is published
 * standalone, which a normal local link audit misses.
 *
 * The trap: a markdown link like `../../docs/X.md` resolves fine in a local
 * umbrella checkout (the target really is at ../../docs/), so a filesystem-based
 * link checker passes. But this repo is published on its own — so `../../` climbs
 * ABOVE the repo root and GitHub renders a broken URL pointing at a file that
 * isn't in the repo (404 for everyone).
 *
 * This checker resolves every relative markdown link by PATH SEGMENTS relative to
 * the repo root (not the filesystem), so it flags:
 *   ESCAPE  — the link climbs above the repo root (breaks on GitHub)
 *   MISSING — the link stays in-repo but the target file isn't present
 *
 * Usage:  php tools/check-doc-links.php [repo-root]   (default: cwd)
 * Exit:   0 = clean, 1 = problems found, 2 = bad invocation.
 */

$root = rtrim(str_replace('\\', '/', realpath($argv[1] ?? '.')), '/');
if (!$root || !is_dir($root)) { fwrite(STDERR, "not a directory: " . ($argv[1] ?? '.') . "\n"); exit(2); }

/** Resolve $target (relative to $fileRelDir, both relative to repo root) by segments.
 *  Returns [escapedRepoRoot(bool), pathRelativeToRoot(string)]. */
function resolve_rel(string $fileRelDir, string $target): array {
    $stack = $fileRelDir === '' ? [] : explode('/', $fileRelDir);
    $escaped = false;
    foreach (explode('/', str_replace('\\', '/', $target)) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { if (!$stack) { $escaped = true; } else { array_pop($stack); } }
        else { $stack[] = $seg; }
    }
    return [$escaped, implode('/', $stack)];
}

$mds = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (preg_match('#/(node_modules|vendor|\.git)/#', $p)) continue;
    if (strtolower($f->getExtension()) === 'md') $mds[] = $p;
}
sort($mds);

$problems = [];
foreach ($mds as $file) {
    $relFile = ltrim(substr($file, strlen($root)), '/');
    $relDir  = (strpos($relFile, '/') === false) ? '' : dirname($relFile);
    $txt = file_get_contents($file);
    // inline links [text](target ...) and reference definitions [id]: target
    preg_match_all('/\]\(\s*([^)\s]+)/', $txt, $a);
    preg_match_all('/^\s*\[[^\]]+\]:\s*(\S+)/m', $txt, $b);
    foreach (array_merge($a[1], $b[1]) as $target) {
        if (preg_match('~^(https?:|mailto:|tel:|data:|//|#)~i', $target)) continue; // external / anchor
        $path = preg_replace('/[#?].*$/', '', $target);                              // strip anchor/query
        if ($path === '') continue;
        [$escaped, $rel] = resolve_rel($relDir, $path);
        if ($escaped)                          $problems[] = [$relFile, $target, 'ESCAPE  (climbs above repo root)'];
        elseif (!file_exists("$root/$rel"))    $problems[] = [$relFile, $target, 'MISSING (target not in repo)'];
    }
}

if (!$problems) { echo "doc-link check: OK — no escaping or missing relative links\n"; exit(0); }

usort($problems, fn($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
echo "doc-link check: " . count($problems) . " problem(s)\n\n";
$cur = null;
foreach ($problems as [$file, $target, $why]) {
    if ($file !== $cur) { echo "\n$file\n"; $cur = $file; }
    printf("  %-38s %s\n", $target, $why);
}
echo "\nFix: repoint to a doc that exists in THIS repo (see docs/), using a path that\n";
echo "stays inside the repo (sibling FILE.md from docs/, never ../../docs/...), or remove the link.\n";
exit(1);
