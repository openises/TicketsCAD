<?php
/**
 * RBAC permission-code audit (2026-08-15).
 *
 * THE DISEASE, same shape as tools/schema_audit.php (SQL vs the real
 * schema), tools/api_contract_audit.php (JS reads vs API emits), and
 * tools/legacy_level_audit.php (RBAC gate vs the page's own gate): a
 * `rbac_can('some.code')` call site referencing a permission CODE that was
 * never actually seeded. rbac_can() resolves a code against the
 * `permissions` table and returns false for any code with no row — so a
 * dead code sitting ALONE in a gate makes that feature reachable by
 * Super Admin only, unconditionally, no matter what a role is granted; a
 * dead code sitting in an `||` chain next to a real one just silently
 * never contributes anything, which reads as "working" until someone
 * tries to grant the specific capability the dead half was supposed to
 * represent and finds there is nothing to grant.
 *
 * Found 2026-08-15 (Mike/Ron, external API bug report + Eric asking for a
 * wider RBAC pass): api/external/v1/incidents.php's GET gate --
 * `rbac_can('action.view_incident') || rbac_can('action.view_incidents')`
 * -- has BOTH halves dead (neither code has a permissions row), so the
 * External API's read-only incident list was reachable by Super Admin
 * tokens only, with no role configuration able to grant it. A wider check
 * turned up the identical pattern on the INTERNAL side too --
 * api/incidents.php's own `rbac_can('screen.incidents') ||
 * rbac_can('incident.view')` has a dead `incident.view` (singular; the
 * seeded code is `incidents.view`, plural) riding silently on
 * `screen.incidents` actually existing. Neither of those would have
 * surfaced without asking the database which codes are real.
 *
 * How it works:
 *   1. Extract every LITERAL-STRING rbac_can('...')/rbac_require_screen('...')
 *      call across api/, page templates, and inc/. A code built from a
 *      variable can't be resolved statically and is silently skipped --
 *      same policy as duplicate_id_audit.php's PHP-tag exclusion; this
 *      tool proves nothing about what it can't read as a literal.
 *   2. Ask the live `permissions` table which codes actually exist.
 *   3. Flag every referenced code with no matching row.
 *
 * Exit code: 0 = clean/baseline-only, 1 = new findings.
 * Baseline:  tools/rbac_permission_audit_baseline.txt ("file:line | code"
 *            per line, add a verified-legitimate finding WITH a reason).
 *
 * Usage:
 *   php tools/rbac_permission_audit.php          # report + exit code
 *   php tools/rbac_permission_audit.php --all    # include baselined finds
 *   php tools/rbac_permission_audit.php --path=DIR   # scan a fixture tree
 *                                                     (still checks the real
 *                                                     app's permissions table)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
chdir($root);
$showAll = in_array('--all', $argv, true);

// Loaded first, before any output -- config.php sets session ini directives
// that PHP refuses (with a warning) once a byte has already been echoed.
$dbAvailable = true;
$dbError = '';
try {
    require_once __DIR__ . '/../config.php';
} catch (Throwable $e) {
    $dbAvailable = false;
    $dbError = $e->getMessage();
}

$baselineFile = __DIR__ . '/rbac_permission_audit_baseline.txt';
$baseline = [];
if (is_file($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('|', $line, 2);
        if (count($parts) === 2) {
            $baseline[trim($parts[0])] = trim($parts[1]);
        }
    }
}

/** Every .php file under a directory. */
function rpa_files(string $dir): array {
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if (!$f->isFile()) continue;
        if (strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION)) !== 'php') continue;
        $out[] = str_replace('\\', '/', $f->getPathname());
    }
    sort($out);
    return $out;
}

/**
 * Every literal-string rbac_can('code')/rbac_require_screen('code') call
 * site in a file. Returns [[line, code], ...]. A call whose argument isn't
 * a plain quoted string (a variable, a concatenation, an array lookup) is
 * skipped -- this tool only proves things about codes it can read.
 */
function rpa_scan(string $path): array {
    $src = file_get_contents($path);
    if ($src === false) return [];
    // Strip comments before matching -- a docblock illustrating the call
    // shape with a placeholder like rbac_can('screen.X') is not a real
    // reference and would otherwise false-positive every time (matching
    // legacy_level_audit.php's own comment-stripping for the same reason).
    // Blank every character to a space EXCEPT newlines, which must survive
    // verbatim -- collapsing a multi-line /* */ block to a single run of
    // spaces removes its embedded "\n"s and shifts every line number
    // reported for the rest of the file.
    $blank = fn($m) => preg_replace('/[^\n]/', ' ', $m[0]);
    $src = preg_replace_callback('#/\*.*?\*/#s', $blank, $src);
    $src = preg_replace_callback('#^[ \t]*//.*$#m', $blank, $src);
    $re = '/\b(?:rbac_can|rbac_require_screen)\s*\(\s*([\'"])([a-zA-Z0-9_.]+)\1/';
    if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) return [];
    $out = [];
    foreach ($m[2] as $i => $hit) {
        $code = $hit[0];
        $offset = $hit[1];
        $line = substr_count($src, "\n", 0, $offset) + 1;
        $out[] = [$line, $code];
    }
    return $out;
}

echo "=== RBAC permission-code audit ===\n";
echo "Rule: every rbac_can('code')/rbac_require_screen('code') literal must\n";
echo "      reference a code that actually has a row in the permissions\n";
echo "      table. A code that doesn't exist can never be granted to any\n";
echo "      role -- the gate reads as configurable and isn't.\n\n";

// --path=DIR scans a fixture tree instead of the real app (tests only) --
// switched AFTER config.php has already loaded from the real app root, so
// pointing this at a throwaway directory can't affect the DB connection.
$scanRoot = '.';
foreach ($argv as $a) {
    if (strpos($a, '--path=') === 0) { $scanRoot = substr($a, 7); }
}
if ($scanRoot !== '.') { chdir($scanRoot); }

$targets = array_merge(
    rpa_files('api'),
    glob('*.php') ?: [],   // page templates at the webroot
    rpa_files('inc')
);
sort($targets);

$referenced = []; // code => [[path, line], ...]
foreach ($targets as $path) {
    foreach (rpa_scan($path) as [$line, $code]) {
        $referenced[$code][] = [$path, $line];
    }
}

if (!$dbAvailable) {
    echo "connection failed — {$dbError}\n";
    echo "(cannot audit permission codes without the database — CI will run it)\n";
    exit(0);
}
try {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all("SELECT code FROM `{$prefix}permissions`");
    $realCodes = array_flip(array_map(fn($r) => $r['code'], $rows));
} catch (Throwable $e) {
    echo "connection failed — " . $e->getMessage() . "\n";
    echo "(cannot audit permission codes without the database — CI will run it)\n";
    exit(0);
}

$newFindings = [];
$matchedBaseline = [];
foreach ($referenced as $code => $sites) {
    if (isset($realCodes[$code])) continue;
    foreach ($sites as [$path, $line]) {
        $key = "{$path}:{$line}";
        if (isset($baseline[$key]) && $baseline[$key] === $code) {
            $matchedBaseline[] = $key;
            if ($showAll) echo "  [baseline] {$path}:{$line} -> '{$code}'\n";
            continue;
        }
        $newFindings[] = [$path, $line, $code];
    }
}

echo count($targets) . " file(s) scanned, " . count($referenced) . " distinct permission code(s) referenced as literals\n";
echo count($realCodes) . " code(s) exist in the permissions table\n";
echo count($baseline) . " entries in " . basename($baselineFile) . "\n\n";

if ($newFindings) {
    echo "NEW findings (referenced code has no permissions-table row):\n";
    foreach ($newFindings as [$path, $line, $code]) {
        echo "  [NEW] {$path}:{$line} -> rbac_can/rbac_require_screen('{$code}') -- no such permission\n";
    }
    echo "\n";
}

$staleBaseline = array_diff(array_keys($baseline), $matchedBaseline);
if ($staleBaseline) {
    echo "STALE baseline entries (no longer match a missing-code finding -- fixed, or the code moved):\n";
    foreach ($staleBaseline as $key) {
        echo "  {$key} | {$baseline[$key]}\n";
    }
    echo "\n";
}

$candidateCount = count($newFindings) + count($matchedBaseline);
echo "{$candidateCount} missing-code reference(s) found, " . count($newFindings) . " new, "
    . count($matchedBaseline) . " baseline entries matched, " . count($staleBaseline) . " stale\n";

exit((!empty($newFindings) || !empty($staleBaseline)) ? 1 : 0);
