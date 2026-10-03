<?php
/**
 * Read-only client for a DVMProject FNE's REST API (Phase 155, GH#151).
 *
 * Purpose: answer ONE question honestly — "is this bridge's peer actually
 * connected to the FNE right now?" — so a console strip's status light means
 * something. It is optional: with no REST URL/password configured every
 * channel's link state is 'unknown', and 'unknown' is never shown as
 * 'connected'. The audio path does not depend on this file at all.
 *
 * Only these read-only calls are ever made: PUT /auth, GET /status,
 * GET /peer/query, GET /tg/query. Nothing here can change the FNE (no
 * inhibit, page, regroup, talkgroup or peer edits) and none is planned.
 *
 * Request/response shapes follow the FNE REST documentation (TN.1100 in the
 * DVMProject repository, read 2026-10-02): PUT /auth with {"auth": "<sha256
 * hex of the password>"} returns {"status":200,"token":"..."}; the token is
 * sent as the X-DVM-Auth-Token header; GET /peer/query returns {"peers":[
 * {peerId, connected, connectionState (4 = running), lastPing (unix time),
 * config:{identity, software}, ...}]}. THIS CODE HAS BEEN RUN ONLY AGAINST A
 * SIMULATOR (tests/_fake_dvm_fne.php) BUILT FROM THAT DOCUMENT; it has not
 * been run against a real FNE.
 *
 * TOKEN HANDLING (a design requirement, not an optimisation). The FNE's
 * token is bound to the client address and is INVALIDATED whenever that
 * client authenticates again. Every PHP worker on this host shares one source
 * address, so naive per-request authentication makes concurrent requests
 * invalidate each other's tokens and every one of them then fails. Instead:
 * one token is cached on disk (the same runtime-state directory as the
 * bridge-health verdicts), reused until the FNE answers 401, and refreshed
 * SINGLE-FLIGHT under an exclusive lock — a worker whose token was rejected
 * first checks whether another worker already replaced it.
 *
 * Secrets: the REST password is a `settings` row ending _password (masked by
 * inc/settings-secrets.php everywhere it could reach a browser). It is sent
 * only as its SHA-256 hex digest — that is the protocol — so a captured
 * digest is replayable. Hence the URL rules below: the host must be an IPv4
 * address on loopback or a private network, and plain http:// to anything
 * other than loopback is accepted but flagged for a visible warning. No
 * redirects are followed (SSRF).
 */

require_once __DIR__ . '/channel_registry.php';   // runtime-state dir + the shared health-verdict cache

if (!function_exists('dvm_fne_rest_settings')) {

/** The four install-wide settings, read straight from the database. */
function dvm_fne_rest_settings() {
    $get = function ($k, $d) {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
            return ($v === false || $v === null) ? $d : (string) $v;
        } catch (Exception $e) {
            return $d;
        }
    };
    $stale = (int) $get('dvm_fne_ping_stale_secs', '30');
    return [
        'url'        => trim($get('dvm_fne_rest_url', '')),
        'password'   => $get('dvm_fne_rest_password', ''),
        'verify_tls' => $get('dvm_fne_rest_verify_tls', '1') !== '0',
        'stale_secs' => ($stale >= 5 && $stale <= 3600) ? $stale : 30,
    ];
}

function dvm_fne_rest_configured() {
    $s = dvm_fne_rest_settings();
    return $s['url'] !== '' && $s['password'] !== '';
}

/**
 * Validate a REST base URL. Returns ['url' => normalised, 'warning' => string|null].
 * @throws InvalidArgumentException
 */
function dvm_fne_rest_validate_url($url) {
    require_once __DIR__ . '/voice-bridge-channels.php';
    $url = trim((string) $url);
    $p = @parse_url($url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        throw new InvalidArgumentException('FNE REST address must look like http://10.0.0.5:9990');
    }
    $scheme = strtolower($p['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new InvalidArgumentException('FNE REST address must start with http:// or https://');
    }
    if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
        || (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/')) {
        throw new InvalidArgumentException('FNE REST address must be just scheme, address and port (no path, user name, query or fragment).');
    }
    $host = $p['host'];
    if (!vbc_host_allowed($host)) {
        throw new InvalidArgumentException(
            'FNE REST address must be an IPv4 address on this host or your private network '
            . '(127.x.x.x, 10.x.x.x, 172.16-31.x.x or 192.168.x.x). A hostname or a public address is refused.');
    }
    $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 9443 : 9990);
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('FNE REST port is out of range.');
    }
    $warning = null;
    if ($scheme === 'http' && !vbc_is_loopback($host)) {
        $warning = 'This address uses plain http:// across your network. The REST password is sent as a SHA-256 '
                 . 'digest, which anyone who can capture the traffic can replay. Prefer https:// or keep the FNE '
                 . 'REST interface on the same host.';
    }
    return ['url' => $scheme . '://' . $host . ':' . $port, 'warning' => $warning];
}

// ── Token cache ─────────────────────────────────────────────────────────

function dvm_fne_state_dir() {
    $dir = dirname(BRIDGE_HEALTH_STATE_FILE);
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return $dir;
}

function dvm_fne_token_path() { return dvm_fne_state_dir() . '/dvm-fne-token.json'; }

/** A token is only valid for the exact url + password it was obtained with. */
function dvm_fne_token_key(array $s) {
    return hash('sha256', $s['url'] . "\n" . hash('sha256', $s['password']));
}

function dvm_fne_token_read(array $s) {
    $raw = @file_get_contents(dvm_fne_token_path());
    if ($raw === false || $raw === '') { return null; }
    $j = json_decode($raw, true);
    if (!is_array($j) || ($j['key'] ?? '') !== dvm_fne_token_key($s) || empty($j['token'])) { return null; }
    return (string) $j['token'];
}

function dvm_fne_token_forget() {
    @unlink(dvm_fne_token_path());
}

/** How many PUT /auth calls THIS process has made (tests assert that N reads
 *  cost one). */
function dvm_fne_auth_call_count($reset = false) {
    if ($reset) { $GLOBALS['_dvm_fne_auth_calls'] = 0; }
    return (int) ($GLOBALS['_dvm_fne_auth_calls'] ?? 0);
}
function _dvm_fne_auth_count_inc() {
    $GLOBALS['_dvm_fne_auth_calls'] = ($GLOBALS['_dvm_fne_auth_calls'] ?? 0) + 1;
}

/** The TLS options for a request, from the "verify the FNE's certificate"
 *  setting. Separate so the setting's effect is testable without a TLS server. */
function dvm_fne_ssl_options($verify) {
    return [
        CURLOPT_SSL_VERIFYPEER => (bool) $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ];
}

/**
 * Low-level HTTP. Returns ['ok'=>bool, 'status'=>int, 'body'=>array|null, 'error'=>string|null].
 * Never throws. ok means "an HTTP response arrived"; callers inspect status.
 */
function dvm_fne_http($method, $path, array $s, $token = null, $jsonBody = null) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl extension not available'];
    }
    $headers = ['Accept: application/json'];
    if ($token !== null) { $headers[] = 'X-DVM-Auth-Token: ' . $token; }
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ] + dvm_fne_ssl_options((bool) $s['verify_tls']);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    $ch = curl_init($s['url'] . $path);
    // Bounded: this runs inside the Status page and the console's probe, and an
    // unreachable FNE must cost seconds, not libcurl's 300 s default.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'FNE REST unreachable: ' . $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return ['ok' => true, 'status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'error' => null];
}

/** Did the FNE reject our token (or credentials)? */
function dvm_fne_is_auth_failure(array $r) {
    if ($r['status'] === 401 || $r['status'] === 403) { return true; }
    $bs = is_array($r['body']) ? ($r['body']['status'] ?? null) : null;
    return $bs === 401 || $bs === 403;
}

/** PUT /auth. Returns ['ok'=>bool,'token'=>string|null,'error'=>string|null]. */
function dvm_fne_authenticate(array $s) {
    _dvm_fne_auth_count_inc();
    // The password is sent ONLY as its SHA-256 hex digest (the protocol).
    $r = dvm_fne_http('PUT', '/auth', $s, null, ['auth' => hash('sha256', $s['password'])]);
    if (!$r['ok']) { return ['ok' => false, 'token' => null, 'error' => $r['error']]; }
    $tok = is_array($r['body']) ? ($r['body']['token'] ?? null) : null;
    if ($r['status'] === 200 && is_string($tok) && $tok !== '') {
        return ['ok' => true, 'token' => $tok, 'error' => null];
    }
    $msg = is_array($r['body']) ? (string) ($r['body']['message'] ?? '') : '';
    return ['ok' => false, 'token' => null,
            'error' => 'FNE refused the REST password' . ($msg !== '' ? ' (' . $msg . ')' : ' (HTTP ' . $r['status'] . ')')];
}

/**
 * Get a usable token, authenticating at most once across all workers.
 * $failedToken: the token the caller just had rejected (null on first use).
 * Returns ['ok'=>bool,'token'=>string|null,'error'=>string|null].
 */
function dvm_fne_token($failedToken = null) {
    $s = dvm_fne_rest_settings();
    if ($s['url'] === '' || $s['password'] === '') {
        return ['ok' => false, 'token' => null, 'error' => 'FNE REST is not configured'];
    }
    $lock = @fopen(dvm_fne_token_path() . '.lock', 'c');
    if ($lock) { @flock($lock, LOCK_EX); }
    try {
        $cached = dvm_fne_token_read($s);
        if ($cached !== null && ($failedToken === null || $cached !== $failedToken)) {
            // Either no token was rejected, or another worker already replaced it.
            return ['ok' => true, 'token' => $cached, 'error' => null];
        }
        $a = dvm_fne_authenticate($s);
        if (!$a['ok']) {
            dvm_fne_token_forget();
            return $a;
        }
        $path = dvm_fne_token_path();
        @file_put_contents($path, json_encode(['key' => dvm_fne_token_key($s), 'token' => $a['token'], 'at' => time()]));
        @chmod($path, 0600);
        return $a;
    } finally {
        if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    }
}

/**
 * Authenticated GET with exactly one re-authentication on a rejected token.
 * Returns the dvm_fne_http() shape plus 'error' set on any failure.
 */
function dvm_fne_get($path) {
    $s = dvm_fne_rest_settings();
    $t = dvm_fne_token();
    if (!$t['ok']) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $t['error']];
    }
    $r = dvm_fne_http('GET', $path, $s, $t['token']);
    if ($r['ok'] && dvm_fne_is_auth_failure($r)) {
        $t2 = dvm_fne_token($t['token']);
        if (!$t2['ok']) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $t2['error']];
        }
        $r = dvm_fne_http('GET', $path, $s, $t2['token']);
        if ($r['ok'] && dvm_fne_is_auth_failure($r)) {
            return ['ok' => false, 'status' => $r['status'], 'body' => null,
                    'error' => 'FNE rejected the REST token twice'];
        }
    }
    if ($r['ok'] && ($r['status'] < 200 || $r['status'] >= 300)) {
        $r['ok'] = false;
        $r['error'] = 'FNE REST answered HTTP ' . $r['status'];
    }
    return $r;
}

// ── The questions we ask ────────────────────────────────────────────────

/** @return array ['ok'=>bool,'peers'=>array,'error'=>?string] */
function dvm_fne_peers() {
    $r = dvm_fne_get('/peer/query');
    if (!$r['ok']) { return ['ok' => false, 'peers' => [], 'error' => $r['error']]; }
    $peers = is_array($r['body']) && isset($r['body']['peers']) && is_array($r['body']['peers']) ? $r['body']['peers'] : [];
    return ['ok' => true, 'peers' => $peers, 'error' => null];
}

/** @return array ['ok'=>bool,'tgs'=>array,'error'=>?string] */
function dvm_fne_tgs() {
    $r = dvm_fne_get('/tg/query');
    if (!$r['ok']) { return ['ok' => false, 'tgs' => [], 'error' => $r['error']]; }
    $tgs = is_array($r['body']) && isset($r['body']['tgs']) && is_array($r['body']['tgs']) ? $r['body']['tgs'] : [];
    return ['ok' => true, 'tgs' => $tgs, 'error' => null];
}

/** @return array ['ok'=>bool,'status'=>array|null,'error'=>?string] */
function dvm_fne_status() {
    $r = dvm_fne_get('/status');
    return ['ok' => $r['ok'], 'status' => $r['ok'] ? $r['body'] : null, 'error' => $r['error']];
}

/**
 * PURE: classify one /peer/query entry (or null = not listed) as a link
 * state. Separated from the network so every branch is testable.
 *
 * connected  — connected, connectionState 4 (running) and a recent ping
 * degraded   — connected but not running, or the ping is stale / missing
 * down       — not connected, or not known to the FNE at all
 *
 * 'unknown' is NOT produced here: it means "we could not ask", which is the
 * caller's answer when the REST call itself failed.
 *
 * @return array{state:string,reason:string}
 */
function dvm_fne_classify_peer($peer, $now, $staleSecs) {
    if (!is_array($peer)) {
        return ['state' => 'down', 'reason' => 'this peer ID is not known to the FNE'];
    }
    if (empty($peer['connected'])) {
        return ['state' => 'down', 'reason' => 'the peer is not connected to the FNE'];
    }
    $cs = isset($peer['connectionState']) ? (int) $peer['connectionState'] : -1;
    if ($cs !== 4) {
        return ['state' => 'degraded', 'reason' => 'connection state ' . $cs . ' (4 means running)'];
    }
    $lp = isset($peer['lastPing']) ? (int) $peer['lastPing'] : 0;
    if ($lp <= 0) {
        return ['state' => 'degraded', 'reason' => 'the FNE has recorded no ping from this peer'];
    }
    $age = (int) $now - $lp;
    if ($age > $staleSecs) {
        return ['state' => 'degraded', 'reason' => 'last ping was ' . $age . ' s ago'];
    }
    if ($age < -$staleSecs) {
        return ['state' => 'degraded', 'reason' => 'the FNE clock is ' . (-$age) . ' s ahead of this server; ping age cannot be judged'];
    }
    return ['state' => 'connected', 'reason' => ''];
}

/**
 * Link state of one peer, asked of the FNE right now.
 * @return array{state:string,reason:string,peer:?array}
 */
function dvm_fne_peer_state($peerId, $now = null) {
    if (!dvm_fne_rest_configured()) {
        return ['state' => 'unknown', 'reason' => 'FNE REST is not configured', 'peer' => null];
    }
    $q = dvm_fne_peers();
    if (!$q['ok']) {
        return ['state' => 'unknown', 'reason' => (string) $q['error'], 'peer' => null];
    }
    $found = null;
    foreach ($q['peers'] as $p) {
        if (is_array($p) && isset($p['peerId']) && (int) $p['peerId'] === (int) $peerId) { $found = $p; break; }
    }
    $s = dvm_fne_rest_settings();
    $c = dvm_fne_classify_peer($found, $now ?? time(), $s['stale_secs']);
    $c['peer'] = $found;
    return $c;
}

/**
 * dvm_fne_peer_state(), but reusing a recent verdict across requests with the
 * SAME asymmetric lifetimes the DMR bridge probe uses (a connected verdict is
 * short-lived, a down verdict is reused longer so opening the console during
 * an outage does not pay the timeout on every page load). State only: the
 * reason is not kept across requests.
 */
function dvm_fne_peer_state_cached($peerId) {
    static $mem = [];
    $s = dvm_fne_rest_settings();
    $key = 'dvmfne:' . substr(sha1($s['url']), 0, 10) . ':' . (int) $peerId;
    if (isset($mem[$key])) { return $mem[$key]; }
    $now = time();
    $stored = chreg_health_cache_read();
    if (chreg_health_cache_valid($stored[$key] ?? null, $now, chreg_health_cache_ttls())) {
        return $mem[$key] = ['state' => (string) $stored[$key]['state'], 'reason' => '', 'peer' => null];
    }
    $res = dvm_fne_peer_state($peerId, $now);
    // 'unknown' (we could not ask) is cached under the down lifetime like
    // any other non-connected verdict.
    chreg_health_cache_write($key, $res['state'], $now);
    return $mem[$key] = $res;
}

/** Does the FNE know this talkgroup? true/false, or null when it cannot be asked. */
function dvm_fne_tg_exists($tgid) {
    $q = dvm_fne_tgs();
    if (!$q['ok']) { return null; }
    foreach ($q['tgs'] as $tg) {
        if (is_array($tg) && isset($tg['source']['tgid']) && (int) $tg['source']['tgid'] === (int) $tgid) {
            return true;
        }
    }
    return false;
}

}
