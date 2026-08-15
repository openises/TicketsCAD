<?php
/**
 * architecture.md §6 item 1 (2026-08-14) — uploads/web.config and
 * cache/web.config are IIS's "deny every script extension, serve
 * everything the endpoint actually accepts" equivalent of the Apache
 * .htaccess / nginx rules that already existed for these two directories.
 * Unlike sql/web.config or tools/web.config (which deny everything, so
 * there is nothing to keep in sync), uploads/web.config hand-lists every
 * extension api/upload.php's and api/file-upload.php's $ALLOWED_EXT_MIME
 * allowlists accept. Three independent copies of the same set (two PHP
 * arrays, one XML allow-list) is exactly the shape that drifts: someone
 * adds a new upload type to one endpoint and forgets the other two, and
 * nothing notices until either a legitimate upload 404s on IIS, or a type
 * removed from the PHP allowlist stays reachable there forever.
 *
 * This test extracts all three sets independently and asserts they are
 * identical — not that any one of them looks reasonable in isolation.
 */

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function tSync(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/** Extract the string keys of a top-level $ALLOWED_EXT_MIME = [ 'ext' => [...], ... ] array literal. */
function extract_allowed_ext_mime_keys(string $phpSrc): array {
    if (!preg_match('/\$ALLOWED_EXT_MIME\s*=\s*\[(.*?)\n\];/s', $phpSrc, $m)) {
        return [];
    }
    $body = $m[1];
    $keys = [];
    // Only top-level `'ext'  =>` lines — the value side also contains
    // quoted strings (MIME types), so anchor on the arrow.
    if (preg_match_all('/^\s*\'([a-z0-9]+)\'\s*=>/m', $body, $km)) {
        $keys = $km[1];
    }
    sort($keys);
    return $keys;
}

/** Extract <add fileExtension=".ext" allowed="true" /> entries from a web.config's XML. */
function extract_webconfig_allowed_extensions(string $xml): array {
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return [];

    $exts = [];
    foreach ($doc->getElementsByTagName('add') as $add) {
        /** @var DOMElement $add */
        if (strcasecmp($add->getAttribute('allowed'), 'true') !== 0) continue;
        $ext = ltrim(strtolower($add->getAttribute('fileExtension')), '.');
        if ($ext !== '') $exts[] = $ext;
    }
    sort($exts);
    return $exts;
}

// ── uploads/: three independent sources, must all agree ──
$uploadPhp     = (string) @file_get_contents($root . '/api/upload.php');
$fileUploadPhp = (string) @file_get_contents($root . '/api/file-upload.php');
$uploadsWc     = (string) @file_get_contents($root . '/uploads/web.config');

$uploadKeys     = extract_allowed_ext_mime_keys($uploadPhp);
$fileUploadKeys = extract_allowed_ext_mime_keys($fileUploadPhp);
$wcExts         = extract_webconfig_allowed_extensions($uploadsWc);

tSync('api/upload.php: $ALLOWED_EXT_MIME keys were found', count($uploadKeys) > 0);
tSync('api/file-upload.php: $ALLOWED_EXT_MIME keys were found', count($fileUploadKeys) > 0);
tSync('uploads/web.config: allowed extensions were found', count($wcExts) > 0);

tSync('api/upload.php and api/file-upload.php accept the SAME extension set',
    $uploadKeys === $fileUploadKeys,
    'upload.php: ' . implode(',', $uploadKeys) . ' | file-upload.php: ' . implode(',', $fileUploadKeys));

tSync('uploads/web.config allows exactly the extensions api/upload.php accepts (no more, no less)',
    $wcExts === $uploadKeys,
    'web.config: ' . implode(',', $wcExts) . ' | upload.php: ' . implode(',', $uploadKeys));

// A dangerous entry sneaking into either PHP allowlist should fail loudly
// here rather than only being caught by the web.config sync check above
// (which would just mean it becomes servable on IIS too).
$dangerous = ['php', 'phar', 'phtml', 'pht', 'phtm', 'asp', 'aspx', 'exe', 'dll', 'sh', 'cgi'];
tSync('no script extension appears in the upload allowlist',
    count(array_intersect($dangerous, $uploadKeys)) === 0,
    implode(',', array_intersect($dangerous, $uploadKeys)));
tSync('no script extension appears in uploads/web.config\'s allow-list',
    count(array_intersect($dangerous, $wcExts)) === 0,
    implode(',', array_intersect($dangerous, $wcExts)));

// ── cache/: only ever writes .json (inc/weather_provider_nws.php), so its
//    web.config's allow-list should be exactly that, not inherited from
//    uploads/ or left wider than what's actually written. ──
$cacheWc = (string) @file_get_contents($root . '/cache/web.config');
$cacheExts = extract_webconfig_allowed_extensions($cacheWc);
tSync('cache/web.config allows exactly .json (the only extension anything writes there)',
    $cacheExts === ['json'], implode(',', $cacheExts));

$weatherSrc = (string) @file_get_contents($root . '/inc/weather_provider_nws.php');
tSync('inc/weather_provider_nws.php writes into cache/ with a .json filename (matches the allow-list)',
    (bool) preg_match('/cacheDir\s*\.\s*\'\/[a-z0-9_]*\'\s*\.\s*\$state\s*\.\s*\'\.json\'/', $weatherSrc)
    || strpos($weatherSrc, ".json'") !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
