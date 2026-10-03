<?php
/**
 * GH#142 (Phase 155) - agency logo: the public serving endpoint.
 *
 * Drives the REAL api/branding-logo.php over real HTTP (a local `php -S` rooted
 * at the project tree). The endpoint is public and unauthenticated by design,
 * so what makes it safe is asserted here, not assumed:
 *
 *   - the right Content-Type from the STORED allow-listed MIME, nosniff, a
 *     sandboxed default-src 'none' CSP, Cross-Origin-Resource-Policy, noindex,
 *     immutable year-long Cache-Control, an ETag and Content-Length;
 *   - If-None-Match returns a bodiless 304 (also the weak form);
 *   - an unknown key, a malformed key, a missing key and a key that is too long
 *     are INDISTINGUISHABLE (identical status, body and header names);
 *   - POST/PUT/DELETE are 405; HEAD works and sends no body;
 *   - NO Set-Cookie and no session on any response;
 *   - after a replace, the old key is 404 and the new key serves;
 *   - a corrupted row (sha256 no longer matches) is never served: same 404;
 *   - a missing table is the same 404 (no error text);
 *   - the rate limiter is engaged (429 past BRANDING_LOGO_RATE_LIMIT);
 *   - the served bytes are exactly the stored bytes (hash compared).
 *
 * @requires-db
 * Usage: php tests/test_gh142_branding_serve.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/_gh142_http.php';
require_once __DIR__ . '/_gh142_branding_fixtures.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding-logo.php serving endpoint ===\n\n";

if (!branding_table_exists() || !branding_gd_available()) {
    echo "SKIP: branding_logos is missing or GD is not loaded\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$srv = gh142_start_server();
if ($srv === null) {
    echo "SKIP: could not start a local PHP server (proc_open/curl unavailable)\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
$snapshot = db_fetch_all("SELECT * FROM " . db_table('branding_logos'));
db_query("DELETE FROM " . db_table('branding_logos'));
register_shutdown_function(function () use ($srv, $snapshot) {
    pb_test_stop_server($srv);
    try {
        db_query("DELETE FROM " . db_table('branding_logos'));
        foreach ($snapshot as $row) {
            $cols = array_keys($row);
            db_query("INSERT INTO " . db_table('branding_logos') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($row));
        }
    } catch (Throwable $e) { /* best effort */ }
});
$base = 'http://127.0.0.1:' . $srv['port'];

$png = gh142_png(200, 80);
$p1 = branding_process_upload($png);
$s1 = branding_store_logo(0, 'light', $p1, 1, 'zz142');
$key = $s1['asset_key'];
$url = $base . '/api/branding-logo.php?k=' . $key;

// ── 1. A good request ──────────────────────────────────────────────────
$r = gh142_request('GET', $url);
t('GET a valid key: 200', $r !== null && $r['status'] === 200);
$h = $r['headers'] ?? [];
t('Content-Type is the stored allow-listed MIME', ($h['content-type'] ?? '') === 'image/png');
t('X-Content-Type-Options: nosniff', ($h['x-content-type-options'] ?? '') === 'nosniff');
t("Content-Security-Policy is the sandboxed default-src 'none' (not the page policy)", ($h['content-security-policy'] ?? '') === "default-src 'none'; sandbox");
t('Cross-Origin-Resource-Policy: same-origin', ($h['cross-origin-resource-policy'] ?? '') === 'same-origin');
t('X-Robots-Tag: noindex', strpos($h['x-robots-tag'] ?? '', 'noindex') !== false);
t('Cache-Control is public, one year, immutable (the page default no-store is overridden)',
    ($h['cache-control'] ?? '') === 'public, max-age=31536000, immutable');
t('no Pragma: no-cache header survives from the page defaults', !isset($h['pragma']));
t('an ETag is sent (the sha256 prefix)', isset($h['etag']) && preg_match('/^"[a-f0-9]{32}"$/', $h['etag']) === 1);
t('Content-Length equals the body length', (int) ($h['content-length'] ?? -1) === strlen($r['body'] ?? ''));
t('the served bytes are EXACTLY the stored bytes', hash('sha256', $r['body'] ?? '') === $p1['sha256']);
t('the body is a real PNG', ($i = @getimagesizefromstring($r['body'] ?? '')) !== false && $i['mime'] === 'image/png');
t('NO Set-Cookie on the response (no session was started)', ($r['set_cookies'] ?? ['x']) === []);

// ── 2. Conditional requests ────────────────────────────────────────────
$etag = $h['etag'] ?? '';
$c = gh142_request('GET', $url, null, null, ['If-None-Match: ' . $etag]);
t('If-None-Match with the current ETag: 304', $c !== null && $c['status'] === 304);
t('...with NO body', ($c['body'] ?? 'x') === '');
t('...and still no Set-Cookie', ($c['set_cookies'] ?? ['x']) === []);
$cw = gh142_request('GET', $url, null, null, ['If-None-Match: W/' . $etag]);
t('the weak form of the ETag also gets a 304', $cw !== null && $cw['status'] === 304);
$cs = gh142_request('GET', $url, null, null, ['If-None-Match: "00000000000000000000000000000000"']);
t('a stale ETag gets the full 200', $cs !== null && $cs['status'] === 200 && strlen($cs['body']) > 0);

// ── 3. Unknown, malformed and missing keys are indistinguishable ───────
$ref = gh142_request('GET', $base . '/api/branding-logo.php?k=' . str_repeat('a', 32));
$variants = [
    'an unknown (well-formed) key' => $ref,
    'a malformed key (not hex)'    => gh142_request('GET', $base . '/api/branding-logo.php?k=' . str_repeat('z', 32)),
    'a too-short key'              => gh142_request('GET', $base . '/api/branding-logo.php?k=abc'),
    'a too-long key'               => gh142_request('GET', $base . '/api/branding-logo.php?k=' . str_repeat('a', 33)),
    'an uppercase key'             => gh142_request('GET', $base . '/api/branding-logo.php?k=' . strtoupper($key)),
    'a VALID key with a trailing newline' => gh142_request('GET', $base . '/api/branding-logo.php?k=' . $key . '%0A'),
    'no key at all'                => gh142_request('GET', $base . '/api/branding-logo.php'),
    'an array key'                 => gh142_request('GET', $base . '/api/branding-logo.php?k[]=' . $key),
    'a SQL-injection-shaped key'   => gh142_request('GET', $base . '/api/branding-logo.php?k=' . urlencode("' OR '1'='1")),
    'a path-traversal-shaped key'  => gh142_request('GET', $base . '/api/branding-logo.php?k=' . urlencode('../../config.php')),
];
foreach ($variants as $label => $resp) {
    t("{$label}: 404", $resp !== null && $resp['status'] === 404);
    t("{$label}: body identical to the unknown-key 404", $resp !== null && $resp['body'] === $ref['body']);
    $hdrNames = $resp ? array_keys($resp['headers']) : [];
    $refNames = array_keys($ref['headers']);
    $a = array_diff($hdrNames, ['date', 'content-length', 'connection', 'host']);
    $b = array_diff($refNames, ['date', 'content-length', 'connection', 'host']);
    sort($a); sort($b);
    t("{$label}: same response header names", $a === $b);
    t("{$label}: no Set-Cookie", $resp !== null && $resp['set_cookies'] === []);
}
t('the 404 body leaks nothing (no key, no SQL, no path)', !preg_match('/branding|SELECT|\\.php|key/i', $ref['body'] ?? ''));
t('the 404 is not cacheable', strpos($ref['headers']['cache-control'] ?? '', 'no-store') !== false);

// ── 4. Methods ─────────────────────────────────────────────────────────
foreach (['POST', 'PUT', 'DELETE'] as $m) {
    $x = gh142_request($m, $url, null, 'a=1');
    t("{$m}: 405", $x !== null && $x['status'] === 405);
    t("{$m}: names the allowed methods", ($x['headers']['allow'] ?? '') === 'GET, HEAD');
}
$hd = gh142_request('HEAD', $url);
t('HEAD: 200 with the image headers and NO body', $hd !== null && $hd['status'] === 200 && $hd['body'] === ''
    && ($hd['headers']['content-type'] ?? '') === 'image/png' && isset($hd['headers']['etag']));

// ── 5. Replace: the old key dies, the new key serves ───────────────────
$p2 = branding_process_upload(gh142_png(150, 60));
$s2 = branding_store_logo(0, 'light', $p2, 1, 'zz142');
$old = gh142_request('GET', $url);
t('after a replace the OLD key is 404', $old !== null && $old['status'] === 404 && $old['body'] === $ref['body']);
$new = gh142_request('GET', $base . '/api/branding-logo.php?k=' . $s2['asset_key']);
t('...and the NEW key serves the new bytes', $new !== null && $new['status'] === 200 && hash('sha256', $new['body']) === $p2['sha256']);
$url2 = $base . '/api/branding-logo.php?k=' . $s2['asset_key'];

// ── 6. A corrupt row is never served ───────────────────────────────────
db_query("UPDATE " . db_table('branding_logos') . " SET `sha256` = ? WHERE `asset_key` = ?", [str_repeat('f', 64), $s2['asset_key']]);
$bad = gh142_request('GET', $url2);
t('a row whose sha256 no longer matches its bytes is a 404, never served', $bad !== null && $bad['status'] === 404 && $bad['body'] === $ref['body']);
t('...with the SAME headers as any other 404 (no leftover image headers)',
    !isset($bad['headers']['etag']) && !isset($bad['headers']['cross-origin-resource-policy']) && !isset($bad['headers']['content-disposition']));
db_query("UPDATE " . db_table('branding_logos') . " SET `sha256` = ? WHERE `asset_key` = ?", [$p2['sha256'], $s2['asset_key']]);
db_query("UPDATE " . db_table('branding_logos') . " SET `mime` = 'text/html' WHERE `asset_key` = ?", [$s2['asset_key']]);
$html = gh142_request('GET', $url2);
t('a row whose mime is not an allow-listed image type is a 404 (a tampered row cannot serve HTML)', $html !== null && $html['status'] === 404);
db_query("UPDATE " . db_table('branding_logos') . " SET `mime` = 'image/png' WHERE `asset_key` = ?", [$s2['asset_key']]);
$ok = gh142_request('GET', $url2);
t('once repaired it serves again', $ok !== null && $ok['status'] === 200);

// ── 7. A missing table is the same 404 ─────────────────────────────────
db_query("RENAME TABLE " . db_table('branding_logos') . " TO " . db_table('branding_logos_zz142bak'));
try {
    $nt = gh142_request('GET', $url2);
    t('with the table missing, the endpoint answers the same 404 (no error text)', $nt !== null && $nt['status'] === 404 && $nt['body'] === $ref['body']);
} finally {
    db_query("RENAME TABLE " . db_table('branding_logos_zz142bak') . " TO " . db_table('branding_logos'));
}

// ── 8. The rate limiter is engaged ─────────────────────────────────────
// The limiter is keyed by client address, so this must come last: it exhausts
// the bucket for 127.0.0.1 for the rest of the window.
$limited = 0;
$last = null;
for ($i = 0; $i < BRANDING_LOGO_RATE_LIMIT + 20; $i++) {
    $last = gh142_request('GET', $url2, null, null, ['If-None-Match: ' . ($ok['headers']['etag'] ?? '')]);
    if ($last !== null && $last['status'] === 429) { $limited++; }
}
t('past the per-address limit the endpoint answers 429', $limited > 0);
t('...with a Retry-After', $last !== null && $last['status'] === 429 && isset($last['headers']['retry-after']));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
