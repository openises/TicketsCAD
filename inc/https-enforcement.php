<?php
/**
 * NewUI v4 — `require_https` enforcement status (SPEC-STATUS.md gap B16).
 *
 * Closes a real gap: `require_https` has been a Settings checkbox
 * (settings.php, "Login Settings" panel) since it was added, saves to the
 * `settings` table via the ordinary settings API, and until this file
 * existed had ZERO PHP consumer anywhere in the tree — an administrator
 * could tick it and reasonably believe a protection was active when
 * nothing happened. Confirmed by grep before this file was written: its
 * only references were the checkbox itself and the load/save pair in
 * assets/js/config.js.
 *
 * ── What this deliberately is NOT ───────────────────────────────────────
 * This does not redirect, refuse, or gate anything. Eric's explicit
 * direction (2026-08-2x): "I want administrators informed and taught how
 * to secure their systems. I do not want anyone blocked from using the
 * system, regardless, just informed." So `require_https` becomes a
 * BANNER TRIGGER, not an access gate — the opposite of how
 * `external_api_require_tls` (api/external/v1/_auth.php) uses the same
 * detection functions to return HTTP 426. Both consumers share the same
 * underlying truth (is_https_verified() / https_verification_failure_
 * reason(), inc/https.php) — only what they DO with the answer differs.
 * Read inc/https.php's own docblock before touching this file; it carries
 * the full history of why is_https() and is_https_verified() disagree on
 * purpose and which one a caller should use.
 *
 * ── Why is_https_verified(), never is_https() ───────────────────────────
 * is_https() believes X-Forwarded-Proto from ANY peer — right for URL
 * building and cookie flags, wrong for anything that tells an admin
 * "you are protected." An admin who turned this setting on is asking the
 * one question is_https_verified() answers honestly: is this connection
 * PROVABLY TLS, given who is allowed to vouch for it (trusted_proxies)?
 * Using is_https() here would let anyone who can reach the server spoof
 * their way past the very banner meant to warn about exactly that gap.
 *
 * ── The three states, and why they get different words ──────────────────
 * https_verification_failure_reason() returns 'tls' / 'untrusted_proxy' /
 * 'plaintext'. Collapsing the last two into one generic "not secure"
 * message would be actively misleading for the 'untrusted_proxy' case: an
 * admin genuinely behind a real HTTPS-terminating proxy who simply hasn't
 * added it to Settings -> Login Settings -> Trusted Reverse Proxies yet
 * needs to be told THAT, specifically — not left guessing whether their
 * whole proxy setup is broken.
 */

require_once __DIR__ . '/https.php'; // is_https_verified(), https_verification_failure_reason()

if (!function_exists('https_enforcement_status')) {

/**
 * Is the require_https checkbox currently on?
 *
 * get_variable(), NOT get_setting() — this is a settings-table (name/value)
 * runtime feature toggle saved by the ordinary Settings UI, and the two
 * stores are a documented trap in this codebase (CLAUDE.md, "TWO settings
 * stores — don't cross the wires", GH #79): get_setting() reads a
 * different, tiny bootstrap table the Settings UI never writes to, and
 * would silently return the default forever.
 *
 * get_variable() returns `false` (not null) when the row is absent, so
 * a fresh install with no row for this key reads as "disabled" — the
 * correct default (never-enforced-until-opted-in).
 */
function require_https_enabled(): bool
{
    return function_exists('get_variable') && get_variable('require_https') === '1';
}

/**
 * Human-readable explanation for each of the three verification states,
 * written for an administrator with no prior background in reverse-proxy
 * trust models — see docs/HTTPS-VERIFICATION.md for the long version this
 * links to.
 */
function https_enforcement_reason_message(string $reason): string
{
    switch ($reason) {
        case 'tls':
            return 'This connection is verified as TLS-encrypted.';
        case 'untrusted_proxy':
            return 'Require HTTPS is turned on, and this request arrived carrying a '
                 . 'proxy header that claims HTTPS — but the proxy that sent it is not '
                 . 'in your Trusted Reverse Proxies list, so TicketsCAD cannot verify '
                 . 'the claim and is treating this connection as unverified rather than '
                 . 'trusting it blindly. If you are genuinely behind an HTTPS-terminating '
                 . 'reverse proxy or CDN (Cloudflare, nginx, IIS ARR, a Docker/NPM setup, '
                 . 'etc.), add that proxy\'s IP address to the Trusted Reverse '
                 . 'Proxies field under Settings, Login Settings.';
        case 'plaintext':
        default:
            return 'Require HTTPS is turned on, but this connection shows no evidence '
                 . 'of TLS at all -- traffic between browsers and this server '
                 . 'does not appear to be encrypted in transit.';
    }
}

/**
 * The single canonical answer every surface (banner, Settings live-state
 * box, Status page health check) reads from — so the three can never
 * disagree about what "right now" means. Evaluated fresh on every call
 * against the CURRENT request's $_SERVER, exactly like
 * https_verification_failure_reason() itself; there is no caching here
 * because the answer is per-request, not per-install.
 *
 * @return array{
 *   enabled: bool,      Is the require_https setting on?
 *   verified: bool,     Does THIS request verify as TLS?
 *   reason: string,     'tls' | 'untrusted_proxy' | 'plaintext'
 *   message: string,    Human-readable explanation of `reason`.
 *   show_banner: bool,  enabled AND NOT verified. The only field any
 *                       banner-trigger logic should read — never gate on
 *                       `enabled` or `verified` alone.
 * }
 */
function https_enforcement_status(): array
{
    $enabled  = require_https_enabled();
    $reason   = https_verification_failure_reason();
    $verified = ($reason === 'tls');

    return [
        'enabled'     => $enabled,
        'verified'    => $verified,
        'reason'      => $reason,
        'message'     => https_enforcement_reason_message($reason),
        'show_banner' => $enabled && !$verified,
    ];
}

} // end if (!function_exists('https_enforcement_status'))
