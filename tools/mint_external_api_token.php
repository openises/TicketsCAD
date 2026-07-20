<?php
/**
 * CLI helper to mint an external API token (Phase 94 Stage 2).
 *
 * Until the Settings UI admin panel ships (Stage 6), this is the
 * supported way for an admin to create a token. The raw token is
 * printed ONCE to stdout — copy it and store it securely.
 *
 * Usage:
 *   sudo -u www-data php tools/mint_external_api_token.php \
 *        --user=<user_id_or_username> \
 *        --name="Acme Agency iOS v1.4" \
 *        --scopes="incidents:read,incidents:write" \
 *        [--description="optional notes"] \
 *        [--ip-allowlist="10.0.0.0/8,192.168.0.0/16"] \
 *        [--expires="2027-12-31 23:59:59"] \
 *        [--rate-limit=1000]
 *
 * Example:
 *   sudo -u www-data php tools/mint_external_api_token.php \
 *        --user=admin --name="a beta tester test" --scopes="incidents:write,incidents:read"
 *
 * The token's binding user (--user) determines which RBAC permissions
 * apply when the token authenticates (Decision #1, real-user binding).
 * The token's scope LIMITS what it can hit but does NOT GRANT new
 * capability — the bound user must hold the underlying RBAC permission.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(2);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/external-auth.php';

// ── Parse args ──
$opts = getopt('', [
    'user:', 'name:', 'scopes:',
    'description::', 'ip-allowlist::', 'expires::', 'rate-limit::',
    'help',
]);

if (isset($opts['help']) || empty($opts['user']) || empty($opts['scopes']) || empty($opts['name'])) {
    echo "Usage: mint_external_api_token.php --user=<id_or_name> --name=\"Label\" --scopes=\"a,b,c\"\n";
    echo "                                   [--description=\"text\"] [--ip-allowlist=\"cidr,cidr\"]\n";
    echo "                                   [--expires=\"YYYY-MM-DD HH:MM:SS\"] [--rate-limit=N]\n";
    echo "\n";
    echo "Scopes (comma-separated, no spaces):\n";
    echo "  incidents:read | incidents:write\n";
    echo "  members:read   | members:write\n";
    echo "  responders:read | responders:write\n";
    echo "  facilities:read | facilities:write\n";
    echo "  teams:read | teams:write\n";
    echo "  incident_types:read | incident_types:write\n";
    echo "  *:read         (read-everything wildcard)\n";
    echo "  *              (superuser — still bounded by the bound user's RBAC)\n";
    exit(0);
}

// ── Resolve user ──
$prefix = $GLOBALS['db_prefix'] ?? '';
$userArg = $opts['user'];
try {
    if (ctype_digit((string) $userArg)) {
        $u = db_fetch_one("SELECT id, user FROM `{$prefix}user` WHERE id = ?", [(int) $userArg]);
    } else {
        $u = db_fetch_one("SELECT id, user FROM `{$prefix}user` WHERE user = ?", [(string) $userArg]);
    }
} catch (Exception $e) {
    fwrite(STDERR, "User lookup failed: " . $e->getMessage() . "\n");
    exit(1);
}
if (!$u) {
    fwrite(STDERR, "User not found: {$userArg}\n");
    exit(1);
}

$userId = (int) $u['id'];
$userName = $u['user'];

// ── Parse scopes ──
$scopes = array_filter(array_map('trim', explode(',', $opts['scopes'])));
if (empty($scopes)) {
    fwrite(STDERR, "At least one scope is required\n");
    exit(1);
}

// ── Parse optional fields ──
$mintOpts = ['name' => $opts['name']];
if (!empty($opts['description'])) $mintOpts['description'] = $opts['description'];
if (!empty($opts['ip-allowlist'])) {
    $mintOpts['ip_allowlist'] = array_filter(array_map('trim', explode(',', $opts['ip-allowlist'])));
}
if (!empty($opts['expires'])) $mintOpts['expires_at'] = $opts['expires'];
if (!empty($opts['rate-limit'])) $mintOpts['rate_limit_per_hour'] = (int) $opts['rate-limit'];

// ── Mint ──
try {
    $result = ext_api_mint_token($userId, $scopes, $userId, $mintOpts);
} catch (Exception $e) {
    fwrite(STDERR, "Mint failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Print results — the raw token is ONLY shown here, never stored ──
echo "\n";
echo "================================================================\n";
echo " External API token minted\n";
echo "================================================================\n";
echo "  Token id:     " . $result['id'] . "\n";
echo "  Name:         " . $opts['name'] . "\n";
echo "  Bound to:     " . $userName . " (user id " . $userId . ")\n";
echo "  Scopes:       " . implode(', ', $scopes) . "\n";
echo "  Token prefix: " . $result['token_prefix'] . " (visible in admin UI)\n";
if (isset($mintOpts['ip_allowlist'])) {
    echo "  IP allowlist: " . implode(', ', $mintOpts['ip_allowlist']) . "\n";
}
if (isset($mintOpts['expires_at'])) {
    echo "  Expires:      " . $mintOpts['expires_at'] . "\n";
}
echo "\n";
echo " RAW TOKEN (copy now — will never be shown again):\n";
echo "\n";
echo "    " . $result['raw_token'] . "\n";
echo "\n";
echo " Use in API calls:\n";
echo "    curl -H \"Authorization: Bearer " . $result['raw_token'] . "\" \\\n";
echo "         https://your.host/api/external/v1/incidents.php\n";
echo "================================================================\n";
exit(0);
