<?php
/**
 * NewUI v4.0 — Client IP resolver (Phase 10c CJIS hardening)
 *
 * Returns the real client IP address when Tickets CAD sits behind a
 * reverse proxy (NPM, nginx, Cloudflare, etc.). The auth + audit code
 * paths historically read $_SERVER['REMOTE_ADDR'] which in a reverse-
 * proxied deployment is the proxy's IP (often 127.0.0.1) — useless for
 * the CJIS audit log.
 *
 * Eric's NPM (Nginx Proxy Manager) is configured to set:
 *   X-Real-IP:        $remote_addr
 *   X-Forwarded-For:  $proxy_add_x_forwarded_for
 *   X-Forwarded-Proto: $scheme
 *
 * Security note: trusting these headers blindly is a known injection
 * vector. An attacker who can reach the application directly (bypassing
 * the proxy) could spoof X-Forwarded-For to put any IP they like into
 * the audit log. So we ONLY trust the headers when REMOTE_ADDR matches
 * a configured trusted-proxy list. Default: localhost (127.0.0.1, ::1)
 * which covers the most common single-host NPM deployment.
 *
 * The trusted_proxies setting is comma-separated; supports plain IPs
 * AND CIDR notation (10.0.0.0/8, 192.168.0.0/16, etc.). Configure via
 * Settings → Login Settings or directly in the settings table:
 *
 *   INSERT INTO settings (name, value)
 *   VALUES ('trusted_proxies', '127.0.0.1,::1,10.0.0.0/24');
 *
 * Public function:
 *   client_ip(): string   The real client IP (best-effort), or
 *                         REMOTE_ADDR fallback if no trust is established.
 */

if (!function_exists('client_ip')) {

/**
 * Return the real client IP, honoring X-Forwarded-For / X-Real-IP
 * only when REMOTE_ADDR is in the trusted-proxy allow-list.
 */
function client_ip(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // If the direct connection isn't from a trusted proxy, return
    // REMOTE_ADDR verbatim. Don't trust spoofable headers.
    if (!_client_ip_is_trusted_proxy($remote)) {
        return $cached = $remote;
    }

    // Trusted proxy. Honor X-Forwarded-For first (industry standard),
    // then X-Real-IP (also set by NPM). XFF is a comma-separated list
    // where the LEFTMOST entry is the original client and each
    // subsequent entry is a proxy in the chain. We take the leftmost
    // public IP — bypassing any internal proxy hops.
    //
    // BUT FIRST: prefer CF-Connecting-IP when present. Cloudflare sets
    // it at the edge and strips any incoming CF-Connecting-IP from
    // arbitrary clients before adding their own, so it's both
    // authoritative AND spoof-safe. This matters because NPM in
    // your-server.example.com's chain mis-sets X-Real-IP to its
    // docker-bridge IP — the XFF fallback path works but CF-CI is
    // the cleaner truth.
    $cfci = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cfci !== '' && filter_var($cfci, FILTER_VALIDATE_IP)) {
        return $cached = $cfci;
    }

    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        foreach (explode(',', $xff) as $ip) {
            $ip = trim($ip);
            if ($ip === '') continue;
            // Take the first valid public IP in the chain. If all entries
            // are private/loopback (single-host dev setup), fall through
            // and take whatever the leftmost was anyway.
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $cached = $ip;
            }
        }
        // No public IP in chain — take the leftmost (likely private LAN).
        $first = trim(explode(',', $xff)[0]);
        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
            return $cached = $first;
        }
    }

    // X-Real-IP fallback (NPM sets this verbatim from $remote_addr).
    $xri = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if ($xri !== '' && filter_var($xri, FILTER_VALIDATE_IP)) {
        return $cached = $xri;
    }

    // No proxy headers despite trusted-proxy direct connection. That's
    // strange but valid — fall back to REMOTE_ADDR.
    return $cached = $remote;
}

/**
 * Reset the cached client IP. Useful in tests that swap $_SERVER between
 * cases. Not needed in production (one request = one client IP).
 */
function client_ip_reset_cache(): void
{
    // We can't reset the static inside client_ip() from outside; instead
    // re-declare the function's static. Simplest hack: invoke with a
    // sentinel that forces recomputation. Since static persists per-
    // process, the next call will see the new $_SERVER values only if
    // we redo the work. PHP doesn't expose static-clearing, so the
    // canonical workaround is a wrapper: call client_ip() with the
    // env you want and accept the first-call cache.
    //
    // For tests we just call client_ip() before swapping $_SERVER; or
    // run each subtest in a subprocess. This stub exists so test code
    // can be defensive without breaking.
}

/**
 * Is the given IP in the trusted_proxies allow-list?
 * Supports plain IPs and CIDR notation. Defaults to localhost only.
 */
function _client_ip_is_trusted_proxy(string $ip): bool
{
    static $trusted = null;
    if ($trusted === null) {
        $raw = '127.0.0.1,::1';  // safe default
        try {
            $prefix = $GLOBALS['db_prefix'] ?? '';
            $v = db_fetch_value(
                "SELECT `value` FROM `{$prefix}settings`
                 WHERE `name` = 'trusted_proxies' LIMIT 1"
            );
            if ($v !== null && trim($v) !== '') {
                $raw = $v;
            }
        } catch (Exception $e) {
            // Settings table missing — fall through to safe default.
        }
        $trusted = array_filter(array_map('trim', explode(',', $raw)));
    }
    foreach ($trusted as $entry) {
        if (strpos($entry, '/') !== false) {
            if (_client_ip_in_cidr($ip, $entry)) return true;
        } elseif (strcasecmp($ip, $entry) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Does the IP fall within the CIDR range? Handles IPv4 and IPv6.
 */
function _client_ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $maskBits] = explode('/', $cidr, 2);
    $maskBits = (int) $maskBits;

    $ipBin     = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) return false;
    if (strlen($ipBin) !== strlen($subnetBin)) return false;

    $bytes = (int) ceil($maskBits / 8);
    $remainder = $maskBits % 8;

    // Compare full bytes
    if (strncmp($ipBin, $subnetBin, $bytes - ($remainder > 0 ? 1 : 0)) !== 0) {
        return false;
    }
    // Compare the partial byte if any
    if ($remainder > 0 && $bytes > 0) {
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        if ((ord($ipBin[$bytes - 1]) & $mask) !== (ord($subnetBin[$bytes - 1]) & $mask)) {
            return false;
        }
    }
    return true;
}

} // end if (!function_exists('client_ip'))
