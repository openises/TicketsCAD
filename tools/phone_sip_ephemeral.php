<?php
/**
 * GH#108 (panel-review 2026-10-20, finding C3 / spec section 4) — manage the
 * browser phone's SIP credential mode from the SERVER.
 *
 * Ephemeral mode is configured on the server, not in the browser: the shared
 * secret it derives credentials from must never reach a browser, and the
 * operator is already on the server wiring up the PBX's realtime-auth backend
 * (see docs/PHONE-TELEPHONY-GUIDE.md). This tool is the server-side exchange
 * path the guide refers to — it reads/sets the mode and TTL, and reveals or
 * rotates the shared secret so the operator can put the SAME value into the
 * PBX.
 *
 * Usage:
 *   php tools/phone_sip_ephemeral.php                 show mode, TTL, whether a secret exists
 *   php tools/phone_sip_ephemeral.php --status        same (explicit)
 *   php tools/phone_sip_ephemeral.php --mode=static   switch back to the legacy stored-password mode
 *   php tools/phone_sip_ephemeral.php --mode=ephemeral switch to short-TTL HMAC credentials
 *   php tools/phone_sip_ephemeral.php --ttl=600       set the credential lifetime in seconds (60..3600)
 *   php tools/phone_sip_ephemeral.php --show-secret   print the shared secret (generates one if absent)
 *   php tools/phone_sip_ephemeral.php --rotate-secret mint a NEW shared secret (invalidates every
 *                                                     outstanding credential — update the PBX in the
 *                                                     same change)
 *
 * The secret is printed ONLY for --show-secret / --rotate-secret, never for a
 * plain status. Exit code 0 on success, 2 on a bad argument.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/phone-extensions.php';

$argvv = $argv ?? [];
$modeArg = null;
$ttlArg  = null;
$showSecret   = false;
$rotateSecret = false;

foreach (array_slice($argvv, 1) as $a) {
    if ($a === '--status') { continue; }
    if ($a === '--show-secret') { $showSecret = true; continue; }
    if ($a === '--rotate-secret') { $rotateSecret = true; continue; }
    if (strpos($a, '--mode=') === 0) { $modeArg = substr($a, 7); continue; }
    if (strpos($a, '--ttl=') === 0)  { $ttlArg  = substr($a, 6); continue; }
    fwrite(STDERR, "Unknown argument: $a\n");
    exit(2);
}

if ($modeArg !== null) {
    try {
        $saved = phone_sip_mode_save($modeArg);
        echo "SIP credential mode set to: $saved\n";
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
}

if ($ttlArg !== null) {
    if (!preg_match('/^[0-9]+$/', (string) $ttlArg)) {
        fwrite(STDERR, "TTL must be a whole number of seconds.\n");
        exit(2);
    }
    $clamped = phone_sip_cred_ttl_save((int) $ttlArg);
    echo "Credential TTL set to: {$clamped}s (clamped to "
        . phone_sip_cred_ttl_min() . ".." . phone_sip_cred_ttl_max() . ")\n";
}

if ($rotateSecret) {
    $secret = phone_sip_rotate_shared_secret();
    echo "Shared secret ROTATED. Put this exact value in the PBX's realtime-auth backend:\n";
    echo $secret . "\n";
    echo "(Every outstanding ephemeral credential is now invalid until the PBX is updated.)\n";
}

if ($showSecret && !$rotateSecret) {
    $secret = phone_sip_ensure_shared_secret();   // generates one if none exists yet
    echo "Shared secret (put this exact value in the PBX's realtime-auth backend):\n";
    echo $secret . "\n";
}

// Always finish with a status line (never printing the secret here).
$status = phone_sip_ephemeral_status();
echo "\nStatus:\n";
echo "  mode              = {$status['mode']}\n";
echo "  cred_ttl          = {$status['cred_ttl']}s\n";
echo "  shared_secret_set = " . ($status['shared_secret_set'] ? 'yes' : 'no') . "\n";
if ($status['mode'] === 'static') {
    echo "\nNOTE: 'static' mode hands the browser a permanent, reusable SIP password.\n";
    echo "It is toll-fraud-exposed and acceptable only on an isolated LAN. Switch to\n";
    echo "'ephemeral' (and configure the PBX to validate the HMAC) for an exposed PBX.\n";
}
exit(0);
