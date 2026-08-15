<?php
/**
 * architecture.md §6 item 1 follow-up (2026-08-14) — uploads/web.config and
 * cache/web.config were first written directly to disk with the Write tool,
 * which worked locally but reaches NO real install: both uploads/ and
 * cache/ are wholesale `.gitignore`'d (uploaded/cached content is runtime
 * data, never checked in), so a file that only exists because it was
 * hand-created on one machine is invisible to `git pull` and to a fresh CI
 * checkout. This is the exact same shape as uploads/.htaccess, which is why
 * that file is written by tools/install_fresh.php + api/upload.php at
 * runtime rather than shipped as a tracked file — served_dir_harden_
 * allowlist() (inc/served-dir.php) is the same mechanism, generalized to
 * write web.config with a narrow extension allow-list instead of denying
 * everything.
 *
 * This test proves the FUNCTION works (writes valid, correctly-scoped IIS
 * config to an arbitrary directory) and that every call site that needs it
 * actually calls it — not just that the two files happen to exist on this
 * machine right now.
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/served-dir.php';

$pass = 0; $fail = 0;
function tSdha(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== served_dir_harden_allowlist() ===\n\n";

// ── 1. The function itself, against a scratch directory (not the real
//       uploads/cache -- never touch those from a test) ──
$scratch = sys_get_temp_dir() . '/tcad_sdha_test_' . getmypid() . '_' . mt_rand();
@mkdir($scratch, 0755, true);

served_dir_harden_allowlist($scratch, 'Scratch test directory', ['.png', '.pdf']);
$wc = $scratch . '/web.config';
tSdha('writes web.config into a directory that did not have one', file_exists($wc));

$text = (string) @file_get_contents($wc);
$prev = libxml_use_internal_errors(true);
$doc = new DOMDocument();
$parsed = $doc->loadXML($text);
libxml_clear_errors();
libxml_use_internal_errors($prev);
tSdha('the written file is well-formed XML', $parsed, 'not parseable');
tSdha('declares both requested extensions as allowed',
    strpos($text, 'fileExtension=".png" allowed="true"') !== false
    && strpos($text, 'fileExtension=".pdf" allowed="true"') !== false);
tSdha('denies unlisted extensions (allowUnlisted="false")',
    strpos($text, 'allowUnlisted="false"') !== false);
tSdha('resets the inherited list with <clear /> before its own entries',
    strpos($text, '<clear />') !== false);
tSdha('carries allowDoubleEscaping="false" (architecture.md §6 item 2)',
    (bool) preg_match('/<requestFiltering\s+allowDoubleEscaping="false"\s*>/', $text));
tSdha('directory browsing stays off', strpos($text, '<directoryBrowse enabled="false" />') !== false);

// Idempotent — a second call must not touch an existing file (matches
// served_dir_harden()'s own file_exists() gate for .htaccess/web.config).
$firstWrite = filemtime($wc);
clearstatcache();
sleep(1);
served_dir_harden_allowlist($scratch, 'Scratch test directory', ['.png', '.pdf', '.docx']);
clearstatcache();
tSdha('a second call does not overwrite an existing web.config',
    filemtime($wc) === $firstWrite,
    'file was rewritten -- an operator\'s own hand edits would be silently lost');

@unlink($wc);
@rmdir($scratch);

// ── 2. Every call site that needs this actually calls it ──
$sites = [
    'tools/install_fresh.php' => ["served_dir_harden_allowlist(\$uploadsDir,", "served_dir_harden_allowlist(\$cacheDir,"],
    'api/upload.php'          => ["served_dir_harden_allowlist(\$uploadDir,"],
    'api/file-upload.php'     => ["served_dir_harden_allowlist(\$uploadDir,"],
    'inc/weather_provider_nws.php' => ["served_dir_harden_allowlist(\$cacheDir,"],
];
foreach ($sites as $file => $needles) {
    $src = (string) @file_get_contents($root . '/' . $file);
    foreach ($needles as $needle) {
        tSdha("$file calls served_dir_harden_allowlist()", strpos($src, $needle) !== false,
            "expected to find: $needle");
    }
}

// api/upload.php and api/file-upload.php must derive the extension list
// from their OWN $ALLOWED_EXT_MIME, not a sixth hand-copied duplicate --
// the whole point of test_web_upload_extension_sync.php is that there is
// exactly one place per file that defines this list.
foreach (['api/upload.php', 'api/file-upload.php'] as $file) {
    $src = (string) @file_get_contents($root . '/' . $file);
    tSdha("$file derives the web.config allow-list from \$ALLOWED_EXT_MIME (not a new hardcoded copy)",
        strpos($src, 'array_keys($ALLOWED_EXT_MIME)') !== false);
}

// tools/install_fresh.php's copy IS necessarily hardcoded (it runs before
// any request-scoped $ALLOWED_EXT_MIME exists), so it's the one place that
// must be kept in sync by hand -- confirm it actually matches right now.
$installSrc = (string) @file_get_contents($root . '/tools/install_fresh.php');
if (preg_match('/served_dir_harden_allowlist\(\$uploadsDir,[^\[]*\[(.*?)\]\s*\)/s', $installSrc, $m)) {
    preg_match_all('/\'(\.[a-z0-9]+)\'/', $m[1], $em);
    $installList = $em[1];
    sort($installList);

    $uploadSrc = (string) @file_get_contents($root . '/api/upload.php');
    preg_match('/\$ALLOWED_EXT_MIME\s*=\s*\[(.*?)\n\];/s', $uploadSrc, $um);
    preg_match_all('/^\s*\'([a-z0-9]+)\'\s*=>/m', $um[1] ?? '', $ukm);
    $uploadList = array_map(static fn($e) => '.' . $e, $ukm[1] ?? []);
    sort($uploadList);

    tSdha('tools/install_fresh.php\'s hand-copied extension list matches api/upload.php\'s $ALLOWED_EXT_MIME',
        $installList === $uploadList,
        'install_fresh: ' . implode(',', $installList) . ' | upload.php: ' . implode(',', $uploadList));
} else {
    tSdha('located install_fresh.php\'s uploads extension list to compare', false);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
