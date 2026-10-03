<?php
/**
 * Phase 155 (GH#151 + GH#129) — digital voice bridge settings.
 *
 * specs/phase-155-community-backlog/151-dvm-host-and-129-p25-bridge.md.
 *
 * NO table or column changes: the channel rows themselves are ordinary
 * `comm_channels` rows (adapter 'dvmproject' / 'usrp_bridge'). This seeds the
 * five install-wide `settings` rows (the store get_variable() reads — NOT the
 * separate `config` table):
 *
 *   dvm_policy_ack           ''    recorded DVMProject usage-policy
 *                                  acknowledgment (JSON). Blank = not
 *                                  acknowledged: a `dvmproject` channel can
 *                                  be neither created nor enabled, and the
 *                                  Python service will not bind its socket.
 *   dvm_fne_rest_url         ''    optional FNE REST base URL (status only)
 *   dvm_fne_rest_password    ''    optional FNE REST password (masked
 *                                  everywhere: the key ends _password)
 *   dvm_fne_rest_verify_tls  '1'   verify the FNE's TLS certificate
 *   dvm_fne_ping_stale_secs  '30'  a peer whose last FNE ping is older than
 *                                  this reads 'degraded'
 *
 * The seeded defaults leave the feature inert: no channels, no socket, no
 * REST calls. INSERT IGNORE so an admin's existing value is never touched,
 * then every row is read back and the script EXITS NON-ZERO if any is
 * missing (a migration that prints an error and exits 0 is a migration that
 * never ran — CLAUDE.md, Phase 128 A9).
 *
 * Self-sufficient in any order (needs only the `settings` table that
 * base_schema.sql creates). Idempotent.
 *
 * Usage: php sql/run_phase155_dvm_bridges_settings.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$fail   = [];

echo "Phase 155 -- digital voice bridge settings\n";
echo "==========================================\n\n";

$defaults = [
    'dvm_policy_ack'          => '',
    'dvm_fne_rest_url'        => '',
    'dvm_fne_rest_password'   => '',
    'dvm_fne_rest_verify_tls' => '1',
    'dvm_fne_ping_stale_secs' => '30',
];

foreach ($defaults as $name => $value) {
    try {
        db_query("INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
    } catch (Exception $e) {
        $fail[] = "seed {$name}: " . $e->getMessage();
        echo "[FAIL] seed {$name}: " . $e->getMessage() . "\n";
    }
}

// Verify the OUTCOME, not that the INSERT ran.
foreach (array_keys($defaults) as $name) {
    try {
        $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if ($n === 1) {
            echo "[OK] setting present: {$name}\n";
        } else {
            $fail[] = "setting {$name} missing after seed";
            echo "[FAIL] setting {$name} missing after seed\n";
        }
    } catch (Exception $e) {
        $fail[] = "verify {$name}: " . $e->getMessage();
        echo "[FAIL] verify {$name}: " . $e->getMessage() . "\n";
    }
}

if ($fail) {
    fwrite(STDERR, "\nPhase 155 digital voice bridge settings FAILED:\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "\nDone.\n";
exit(0);
