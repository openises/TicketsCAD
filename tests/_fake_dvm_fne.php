<?php
/**
 * A SIMULATOR of the read-only subset of a DVMProject FNE's REST API, as a
 * `php -S` router script (Phase 155, GH#151). Built from the FNE REST
 * documentation (TN.1100 in the DVMProject repository, read 2026-10-02):
 *
 *   PUT /auth {"auth": "<sha256 hex of the password>"}
 *        -> 200 {"status":200,"token":"..."}   or 400 {"status":400,"message":"invalid password"}
 *        Every successful auth issues a NEW token and INVALIDATES the
 *        previous one (the real FNE binds one token per client address).
 *   GET /status | /peer/query | /tg/query   (header X-DVM-Auth-Token)
 *        -> 200 {...}   or 401 {"status":401,"message":"unauthorized"}
 *
 * IT IS NOT AN FNE. Nothing here says a real FNE answers this way; the shapes
 * come from the document, not from a running instance.
 *
 * State lives in a JSON file named by $_SERVER/ENV FAKE_FNE_STATE so a test
 * can reshape the world between calls: POST /__control with a JSON object
 * merges it into the state ({"peers":[...]}, {"invalidate_token":true},
 * {"redirect_peer_query":"http://..."}, {"delay_ms":N}, {"auth_delay_ms":N}). Every request is
 * appended to FAKE_FNE_LOG as one JSON line (method, uri, token header, raw
 * body), so a test can prove what was sent — including that the plaintext
 * password never was.
 */

$stateFile = getenv('FAKE_FNE_STATE');
$logFile   = getenv('FAKE_FNE_LOG');
if (!$stateFile || !$logFile) {
    http_response_code(500);
    echo 'FAKE_FNE_STATE / FAKE_FNE_LOG not set';
    return true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$raw    = (string) file_get_contents('php://input');
$token  = $_SERVER['HTTP_X_DVM_AUTH_TOKEN'] ?? '';

@file_put_contents($logFile, json_encode(['method' => $method, 'uri' => $uri, 'token' => $token, 'body' => $raw]) . "\n", FILE_APPEND | LOCK_EX);

function fake_state_read($file) {
    $j = json_decode((string) @file_get_contents($file), true);
    return is_array($j) ? $j : [];
}
function fake_state_write($file, array $s) {
    file_put_contents($file, json_encode($s), LOCK_EX);
}
function fake_out($status, array $body) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
}

$fp = fopen($stateFile . '.lock', 'c');
flock($fp, LOCK_EX);
$state = fake_state_read($stateFile);

if (!empty($state['delay_ms'])) { usleep((int) $state['delay_ms'] * 1000); }

if ($uri === '/__control' && $method === 'POST') {
    $patch = json_decode($raw, true) ?: [];
    if (!empty($patch['invalidate_token'])) { $state['current_token'] = ''; unset($patch['invalidate_token']); }
    $state = array_merge($state, $patch);
    fake_state_write($stateFile, $state);
    fake_out(200, ['ok' => true]);
    flock($fp, LOCK_UN);
    return true;
}

if ($uri === '/auth' && $method === 'PUT') {
    // auth_delay_ms: a slow auth, so concurrent callers overlap and a missing
    // single-flight lock on the client side shows up as several PUT /auth.
    if (!empty($state['auth_delay_ms'])) { usleep((int) $state['auth_delay_ms'] * 1000); }
    $j = json_decode($raw, true) ?: [];
    if (isset($j['auth']) && hash_equals((string) ($state['password_sha256'] ?? ''), (string) $j['auth'])) {
        $state['token_seq'] = (int) ($state['token_seq'] ?? 0) + 1;
        $state['current_token'] = 'tok' . $state['token_seq'] . '-' . bin2hex(random_bytes(4));
        fake_state_write($stateFile, $state);
        fake_out(200, ['status' => 200, 'token' => $state['current_token']]);
    } else {
        fake_out(400, ['status' => 400, 'message' => 'invalid password']);
    }
    flock($fp, LOCK_UN);
    return true;
}

$authed = ($token !== '' && hash_equals((string) ($state['current_token'] ?? ''), $token));
if (!$authed) {
    fake_out(401, ['status' => 401, 'message' => 'unauthorized']);
    flock($fp, LOCK_UN);
    return true;
}

if ($method === 'GET' && $uri === '/status') {
    fake_out(200, ['status' => 200, 'state' => 1, 'dmrEnabled' => true, 'p25Enabled' => true,
                   'nxdnEnabled' => false, 'peerId' => 10001]);
} elseif ($method === 'GET' && $uri === '/peer/query') {
    if (!empty($state['redirect_peer_query'])) {
        http_response_code(302);
        header('Location: ' . $state['redirect_peer_query']);
    } else {
        fake_out(200, ['status' => 200, 'peers' => $state['peers'] ?? []]);
    }
} elseif ($method === 'GET' && $uri === '/tg/query') {
    fake_out(200, ['status' => 200, 'tgs' => $state['tgs'] ?? []]);
} else {
    fake_out(404, ['status' => 404, 'message' => 'not found']);
}
flock($fp, LOCK_UN);
return true;
