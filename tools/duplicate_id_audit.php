<?php
/**
 * Duplicate DOM `id="..."` audit.
 *
 * GH#37 follow-up (2026-08-08, caught by a QA review before release, not by
 * a user report): the new Audit Log export dropdown reused id="btnAuditExport",
 * already used by the unrelated Roles & Permissions -> Audit Trail export
 * button (assets/js/roles-audit.js:16). Both panels render into the same
 * settings.php page every load. `document.getElementById()` silently
 * resolves to whichever element comes first in the DOM, so the collision
 * didn't error -- it broke one button's click handler and made the other
 * one fire an unrelated CSV download as a side effect. Nothing in the test
 * suite could have caught that; only reading the rendered HTML can.
 *
 * This scans a page for literal (non-PHP-interpolated) `id="..."` /
 * `id='...'` attributes and fails on any value that appears more than once.
 * IDs built from a loop variable (containing `<?php`/`?>`/`<?=`) are
 * deliberately excluded -- those are unique per rendered row, not a static
 * collision, and a literal-string checker has no way to prove that one way
 * or the other, so it stays silent on them rather than guessing.
 *
 * Usage: php tools/duplicate_id_audit.php [--files=path1,path2,...]
 *   Default file set: settings.php (the one page big enough, and old
 *   enough, for this to have actually happened).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
$files = ['settings.php'];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--files=')) {
        $files = array_filter(array_map('trim', explode(',', substr($arg, 8))));
    }
}

$exceptionsFile = __DIR__ . '/duplicate_id_audit_exceptions.txt';
$exceptions = [];
if (is_file($exceptionsFile)) {
    foreach (file($exceptionsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('|', $line, 2);
        if (count($parts) === 2) {
            $exceptions[trim($parts[0])] = trim($parts[1]);
        }
    }
}

$newFindings = [];
$matchedExceptions = [];
$totalIds = 0;

foreach ($files as $relPath) {
    $path = $root . '/' . $relPath;
    if (!is_file($path)) {
        echo "  skip {$relPath} — not found\n";
        continue;
    }
    $src = file_get_contents($path);

    // Literal id="..." / id='...' — skip any value containing PHP tags,
    // since those are per-iteration dynamic ids, not static duplicates.
    //
    // The leading boundary is a negative lookbehind, NOT \b: \b treats '-' as
    // a non-word character, so \bid matches the "id" inside data-id="..." and
    // data-col-id="..." just as readily as a real id="..." attribute — both
    // "words" start right after a hyphen. That false-positived every
    // data-*id attribute in the codebase as a literal id, and flagged two
    // elements sharing a data-id/data-col-id value as a duplicate DOM id
    // (2026-08-14, found while extending the a11y-label pass, which added
    // many new data-* attributes). (?<![\w-]) instead requires the character
    // immediately before "id" to be neither a word character nor a hyphen —
    // true only when "id" starts its own attribute name.
    preg_match_all('/(?<![\w-])id\s*=\s*(["\'])([^"\']*)\1/i', $src, $m, PREG_OFFSET_CAPTURE);
    $seen = []; // id value => [line numbers]
    foreach ($m[2] as $i => $match) {
        [$value, $offset] = $match;
        if ($value === '' || strpos($value, '<?') !== false) continue;
        $line = substr_count($src, "\n", 0, $offset) + 1;
        $seen[$value][] = $line;
        $totalIds++;
    }

    foreach ($seen as $value => $lines) {
        if (count($lines) < 2) continue;
        $key = "{$relPath}#{$value}";
        if (isset($exceptions[$key])) {
            $matchedExceptions[] = $key;
            continue;
        }
        $newFindings[] = [$relPath, $value, $lines];
    }
}

echo count($files) . " file(s) scanned, {$totalIds} static id=\"...\" attribute(s) found\n";
echo count($exceptions) . " entries in " . basename($exceptionsFile) . "\n\n";

if ($newFindings) {
    echo "NEW findings (duplicate id, no exceptions-file entry):\n";
    foreach ($newFindings as [$relPath, $value, $lines]) {
        echo "  [NEW] {$relPath}: id=\"{$value}\" appears on lines " . implode(', ', $lines) . "\n";
    }
    echo "\n";
}

$staleExceptions = array_diff(array_keys($exceptions), $matchedExceptions);
if ($staleExceptions) {
    echo "STALE exception(s) — no longer match any duplicate (fixed, or the code moved):\n";
    foreach ($staleExceptions as $key) {
        echo "  {$key} | {$exceptions[$key]}\n";
    }
    echo "\n";
}

$candidateCount = count($newFindings) + count($matchedExceptions);
echo "{$candidateCount} duplicate id(s) found, " . count($newFindings) . " new, "
    . count($matchedExceptions) . " exception(s) matched, " . count($staleExceptions) . " stale exception(s)\n";

exit((!empty($newFindings) || !empty($staleExceptions)) ? 1 : 0);
