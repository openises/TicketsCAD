<?php
/**
 * v4.0.3 — Traccar/OwnTracks/OpenGTS ingest URL, GET-in-a-browser hint.
 *
 * Beta report (2026-07-24, Traccar upgrade): a user opened the position-ingest
 * URL in a browser to test it and got {"error":"Not authenticated"}, then spent
 * time chasing a phantom auth problem. Root cause: the ingest branches in
 * api/location.php are POST-only, so a GET falls through to the auth-gated admin
 * handler. The fix returns a friendly 405 explaining "right URL, wrong method —
 * this is NOT an auth failure" BEFORE the auth requirement.
 *
 * This is a source-level assertion test (no Apache needed) so it runs on the
 * fresh-install CI. It guards the ORDER (hint must precede the auth gate) and
 * the content (405 + all three providers + the "not an authentication" wording).
 */

$base = realpath(__DIR__ . '/..');
echo "=== v4.0.3 — location ingest GET hint ===\n\n";
$pass = 0; $fail = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }

$src = @file_get_contents($base . '/api/location.php');
if ($src === false) {
    bad('api/location.php readable');
    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}

// 1. The friendly-GET handler exists and gates on GET + the three providers.
$hasHandler = (bool) preg_match(
    "/\\\$method === 'GET'\\s*&&\\s*isset\\(\\\$_GET\\['provider'\\]\\)\\s*&&\\s*in_array\\(\\\$_GET\\['provider'\\],\\s*\\['owntracks',\\s*'traccar',\\s*'opengts'\\]/s",
    $src
);
$hasHandler ? ok('GET+provider handler present for owntracks/traccar/opengts')
            : bad('GET+provider handler present', 'not found (regex)');

// 2. It returns 405 with an Allow: POST header (correct "wrong method" status).
(strpos($src, 'http_response_code(405)') !== false && stripos($src, 'Allow: POST') !== false)
    ? ok('handler answers 405 + Allow: POST')
    : bad('handler answers 405 + Allow: POST', 'missing 405 or Allow header');

// 3. The body explicitly says it is NOT an auth failure (de-confuses the browser test).
(stripos($src, 'NOT an authentication failure') !== false)
    ? ok('body states this is not an auth failure')
    : bad('body states this is not an auth failure', 'reassurance wording missing');

// 4. ORDER: the handler must come BEFORE the auth requirement, or the auth gate
//    fires first and the whole fix is dead code.
$hintPos = stripos($src, "Friendly GET on an INGEST url");
$authPos = strpos($src, "require_once __DIR__ . '/auth.php'");
if ($hintPos !== false && $authPos !== false && $hintPos < $authPos) {
    ok('GET hint precedes the auth.php gate');
} else {
    bad('GET hint precedes the auth.php gate',
        "hint@" . var_export($hintPos, true) . " auth@" . var_export($authPos, true));
}

// 5. Still POST-only for real ingest — the POST branch must remain (no regression).
(strpos($src, "in_array(\$_GET['provider'], ['traccar', 'opengts'], true)") !== false)
    ? ok('POST ingest branch for traccar/opengts intact')
    : bad('POST ingest branch for traccar/opengts intact', 'POST branch guard changed');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
