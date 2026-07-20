<?php
/**
 * CLI helper to revoke an external API token (Phase 94 Stage 6 sibling
 * to tools/mint_external_api_token.php). The Settings → External API
 * Tokens UI also has a Revoke action; this CLI tool gives ops a
 * scripted path for emergency revocation without a browser.
 *
 * Usage:
 *   sudo -u www-data php tools/revoke_external_api_token.php \
 *        --id=<token_id> [--reason="text"]
 *   sudo -u www-data php tools/revoke_external_api_token.php \
 *        --prefix=tcad_p_xxxxxxx [--reason="text"]
 *
 * Either --id (the database row id) or --prefix (the visible 14-char
 * prefix that appears in the admin UI + token-prefix column) selects
 * the token. The raw token itself is NEVER required for revocation
 * (we don't have it; only sha256 hash is stored).
 *
 * Sets revoked_at = NOW(), revoked_by = NULL (CLI invocation has no
 * authenticated user), revoked_reason = the --reason value.
 *
 * Future external API requests with this token return
 * 401 token_revoked immediately (the bearer resolver checks
 * revoked_at on every call — no cache).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(2);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$opts = getopt('', ['id::', 'prefix::', 'reason::', 'help']);

if (isset($opts['help']) || (empty($opts['id']) && empty($opts['prefix']))) {
    echo "Usage: revoke_external_api_token.php (--id=N | --prefix=tcad_p_xxxxxxx) [--reason=\"text\"]\n";
    echo "\n";
    echo "Either --id or --prefix is required. Use --id if you know the\n";
    echo "database row id (visible in the admin UI Token detail page); use\n";
    echo "--prefix when you only have the visible 14-char token prefix\n";
    echo "(also visible in the admin UI, displayed beneath the token name).\n";
    echo "\n";
    echo "Both flags can be combined for unambiguous selection.\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// Find the token row
try {
    if (!empty($opts['id'])) {
        $token = db_fetch_one(
            "SELECT id, name, token_prefix, revoked_at FROM `{$prefix}external_api_tokens` WHERE id = ?",
            [(int) $opts['id']]
        );
    } else {
        // Prefix-only lookup
        $token = db_fetch_one(
            "SELECT id, name, token_prefix, revoked_at FROM `{$prefix}external_api_tokens` WHERE token_prefix = ?",
            [trim((string) $opts['prefix'])]
        );
    }
} catch (Exception $e) {
    fwrite(STDERR, "Token lookup failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$token) {
    fwrite(STDERR, "Token not found.\n");
    exit(1);
}

if (!empty($token['revoked_at'])) {
    echo "Token #{$token['id']} ('{$token['name']}') is already revoked at {$token['revoked_at']}. Nothing to do.\n";
    exit(0);
}

$reason = isset($opts['reason']) ? trim((string) $opts['reason']) : 'CLI revoke';

try {
    db_query(
        "UPDATE `{$prefix}external_api_tokens`
         SET revoked_at = NOW(), revoked_by = NULL, revoked_reason = ?
         WHERE id = ?",
        [$reason, (int) $token['id']]
    );
} catch (Exception $e) {
    fwrite(STDERR, "Revoke failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Best-effort audit log entry — the audit helpers may or may not be
// reachable from a CLI context, so try/catch and don't fail the revoke
// if logging breaks.
try {
    require_once __DIR__ . '/../inc/audit.php';
    if (function_exists('audit_log')) {
        $_SESSION = $_SESSION ?? [];
        audit_log('config', 'revoke', 'external_api_token', (int) $token['id'],
            "CLI revoke: token '{$token['name']}' — {$reason}");
    }
} catch (Throwable $e) { /* non-fatal */ }

echo "\n";
echo "================================================================\n";
echo " External API token revoked\n";
echo "================================================================\n";
echo "  Token id:     {$token['id']}\n";
echo "  Name:         {$token['name']}\n";
echo "  Prefix:       {$token['token_prefix']}\n";
echo "  Reason:       {$reason}\n";
echo "  Revoked at:   " . date('Y-m-d H:i:s') . "\n";
echo "\n";
echo " Subsequent API calls with this token will return 401 token_revoked.\n";
echo "================================================================\n";
exit(0);
