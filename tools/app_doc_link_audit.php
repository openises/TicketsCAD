<?php
/**
 * In-app documentation link audit (GH#81, 2026-08-18).
 *
 * WHY THIS EXISTS. A reporter on Windows/IIS found that every in-app "Learn
 * more" / "Setup guide" / "Operator guide" link — help.php, the migrations
 * banner, the navbar's HTTPS-encryption notice, several Settings panels —
 * pointed straight at a filesystem path: `<a href="docs/HTTPS-SETUP.md">`.
 * That 404s on IIS (404.3 — no MIME mapping for `.md`, and there is no
 * `web.config` under docs/ to add one) even though the file exists and is
 * readable; the same link happens to render fine on Apache (which serves
 * `.md` as plain text), which is exactly why 15 of these accumulated over
 * many phases without anyone on Apache ever seeing the break.
 *
 * This project ships a working, platform-neutral answer to "serve a
 * markdown doc to a logged-in operator" already: `documentation/index.php`
 * (routed as `documentation/?doc=NAME`, no `.md`, no server rewrite rules
 * needed — IIS resolves `documentation/` to its default document exactly
 * like Apache does). One link (settings.php, EXTERNAL-API.md) already used
 * it correctly; the other 15 did not. All 15 were repointed to the viewer
 * in the same commit that added this gate.
 *
 * WHAT THIS CATCHES. Any `href="docs/NAME.md"` (with or without a leading
 * slash, with or without a `#fragment`) in a `.php` or `.js` file under the
 * app tree — the same shape as every one of the 15 originals. It does NOT
 * chase markdown-internal cross-references between docs (a different,
 * already-covered problem — see tools/check-doc-links.php) and does not
 * touch `docs/` or `documentation/` themselves, since a doc's own body text
 * mentioning `docs/X.md` in prose is not a clickable in-app link.
 *
 * Usage:
 *   php tools/app_doc_link_audit.php              # report + exit code
 *   php tools/app_doc_link_audit.php --path=DIR    # scan a fixture tree
 *                                                   # (how the gate test
 *                                                   #  drives the REAL
 *                                                   #  detector)
 *
 * Exit code: 0 = clean (or only exceptions-file-listed findings), 1 = new
 * raw doc-file links found.
 * Exceptions file: tools/app_doc_link_audit_exceptions.txt — one
 * `relpath#href` per line, with a reason after a `|`. Empty by design: the
 * fix that shipped alongside this gate closed every known instance, and a
 * legitimate reason to add a new raw docs/*.md link is hard to picture —
 * the viewer serves every document under docs/, including the
 * cross-project fallback.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$argvSafe = $argv ?? [];
$scanRoot = null;
foreach ($argvSafe as $a) {
    if (strpos($a, '--path=') === 0) { $scanRoot = substr($a, 7); }
}
$root = $scanRoot !== null ? rtrim(str_replace('\\', '/', realpath($scanRoot) ?: $scanRoot), '/')
                            : rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
if (!$root || !is_dir($root)) { fwrite(STDERR, "not a directory\n"); exit(2); }

// Directories that are never in-app UI — either they ARE the docs (so a
// docs/*.md mention in their own text is prose, not a link), or they are
// vendored/generated/test-only trees where a fixture is deliberately
// simulating the bug, not committing it.
$excludeDirs = ['/docs/', '/documentation/', '/vendor/', '/node_modules/', '/.git/',
                '/tests/', '/tools/', '/sql/', '/specs/', '/coordination/', '/cache/'];

$exceptionsFile = __DIR__ . '/app_doc_link_audit_exceptions.txt';
$exceptions = [];
if (is_file($exceptionsFile)) {
    foreach (file($exceptionsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('|', $line, 2);
        if (count($parts) >= 1) {
            $exceptions[trim($parts[0])] = trim($parts[1] ?? '');
        }
    }
}

$newFindings = [];
$matchedExceptions = [];
$filesScanned = 0;

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    $ext = strtolower($f->getExtension());
    if ($ext !== 'php' && $ext !== 'js') continue;

    $skip = false;
    foreach ($excludeDirs as $dir) {
        if (strpos($p, $dir) !== false) { $skip = true; break; }
    }
    if ($skip) continue;

    $relPath = ltrim(substr($p, strlen($root)), '/');
    $src = file_get_contents($p);
    $filesScanned++;

    // href="docs/NAME.md[#frag]" or href='...' — optional leading slash.
    // Anchored to an href attribute so this never flags a code comment or a
    // <code>docs/X.md</code> mention (those are prose, not a link a user
    // can click and have the browser 404 on).
    if (!preg_match_all(
        '/href\s*=\s*(["\'])\/?docs\/([A-Za-z0-9_\-\/]+\.md)((?:#[^"\']*)?)\1/i',
        $src, $m, PREG_OFFSET_CAPTURE
    )) continue;

    foreach ($m[0] as $i => $whole) {
        $offset = $whole[1];
        $line = substr_count($src, "\n", 0, $offset) + 1;
        $href = $m[1][$i][0] . 'docs/' . $m[2][$i][0] . $m[3][$i][0];
        $key = "{$relPath}#{$href}";
        if (isset($exceptions[$key])) {
            $matchedExceptions[] = $key;
            continue;
        }
        $newFindings[] = [$relPath, $line, $href];
    }
}

echo "{$filesScanned} .php/.js file(s) scanned outside docs/documentation/vendor/tests/tools/sql/specs\n";
echo count($exceptions) . " entries in " . basename($exceptionsFile) . "\n\n";

if ($newFindings) {
    echo "NEW findings (raw docs/*.md link, no exceptions-file entry):\n";
    foreach ($newFindings as [$relPath, $line, $href]) {
        echo "  [NEW] {$relPath}:{$line} — href=\"{$href}\"\n";
    }
    echo "\n  Fix: route through the doc viewer instead of the raw filesystem path —\n";
    echo "  href=\"docs/NAME.md\" -> href=\"documentation/?doc=NAME\" (fragment, if any,\n";
    echo "  carries over unchanged: it's never sent to the server). A raw docs/*.md\n";
    echo "  link 404s on IIS (no .md MIME mapping) even though it renders fine on\n";
    echo "  Apache — see GH#81.\n\n";
}

$staleExceptions = array_diff(array_keys($exceptions), $matchedExceptions);
if ($staleExceptions) {
    echo "STALE exception(s) — no longer match any raw link (fixed, or the code moved):\n";
    foreach ($staleExceptions as $key) {
        echo "  {$key} | {$exceptions[$key]}\n";
    }
    echo "\n";
}

$candidateCount = count($newFindings) + count($matchedExceptions);
echo "{$candidateCount} raw doc-file link(s) found, " . count($newFindings) . " new, "
    . count($matchedExceptions) . " exception(s) matched, " . count($staleExceptions) . " stale exception(s)\n";

exit((!empty($newFindings) || !empty($staleExceptions)) ? 1 : 0);
