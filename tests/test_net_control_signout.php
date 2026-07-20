<?php
/**
 * Net Control Board — sign-out tray (Phase 109 Slice B)
 *
 * Net control can explicitly sign a unit OUT of an event; it drops into a
 * "signed out" tray (independent of zone + global status) and can be signed
 * back IN, which restores it to the active board with a fresh check-in.
 * Closes the "vanished without signing out" gap from Eric's real op.
 *
 * Usage: php tests/test_net_control_signout.php
 */
require_once __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Net Control sign-out tray (Phase 109 Slice B) ===\n\n";

// ── Schema ──
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $has = (bool) db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='signed_out_at'",
        [$prefix . 'assigns']);
    t('assigns.signed_out_at column exists (migration applied)', $has);
} catch (Throwable $e) { t('schema check', false); }

// ── Endpoint shape ──
$ep = @file_get_contents($base . '/api/net-signout.php');
if ($ep !== false) {
    t('net-signout gated on action.update_zone + CSRF',
        strpos($ep, "rbac_can('action.update_zone')") !== false &&
        strpos($ep, 'csrf_verify') !== false);
    t('net-signout handles both signout and signin',
        strpos($ep, "'signout'") !== false && strpos($ep, "'signin'") !== false);
    t('signout sets signed_out_at; signin clears it + refreshes check-in',
        (bool) preg_match('/signout.*?SET `signed_out_at` = NOW\(\)/s', $ep) &&
        (bool) preg_match('/SET `signed_out_at` = NULL, `last_checkin_at` = NOW\(\)/s', $ep));
    t('net-signout writes an ICS-214 activity note',
        strpos($ep, 'incident_add_note_internal') !== false);
} else { t('api/net-signout.php present', false); }

// ── Board payload partitions signed-out units into a tray ──
$nc = @file_get_contents($base . '/api/net-control.php');
if ($nc !== false) {
    t('board query selects signed_out_at', strpos($nc, '`a`.`signed_out_at`') !== false);
    t('board splits active units from a signed_out tray',
        strpos($nc, "'signed_out' => \$signedOut") !== false &&
        (bool) preg_match('/if \(\$isSignedOut\) \{ \$signedOut\[\] = \$row; \} else \{ \$units\[\] = \$row; \}/', $nc));
} else { t('api/net-control.php present', false); }

// ── UI ──
$js = @file_get_contents($base . '/assets/js/net-control.js');
$ph = @file_get_contents($base . '/net-control.php');
t('net-control.js posts to api/net-signout.php with signout/signin',
    $js !== false && strpos($js, "postJson('api/net-signout.php'") !== false &&
    strpos($js, 'renderSignedOutTray') !== false);
t('net-control.js gates sign-out controls on canUpdateZone',
    $js !== false && (bool) preg_match('/function signoutBtnHtml\(u\)\s*\{\s*if \(!CFG\.canUpdateZone\) return/s', $js));
t('net-control.php has the signed-out tray container',
    $ph !== false && strpos($ph, 'id="ncSignedOutTray"') !== false &&
    strpos($ph, 'id="ncSignedOutList"') !== false);

// ── Configurable check-in ramp off PAR cadence (Slice B part 2) ──
t('board payload exposes PAR cadence for the check-in ramp',
    $nc !== false && strpos($nc, "'par'        => ['enabled' => \$parEnabled, 'cadence_secs' => \$parCadenceSecs]") !== false &&
    strpos($nc, 'par_resolve_cadence') !== false);
t('board requires the PAR engine only when present (graceful)',
    $nc !== false && strpos($nc, "is_file(__DIR__ . '/../inc/par.php')") !== false);
t('net-control.js ramps last-check-in off the configured cadence (falls back to fixed)',
    $js !== false && strpos($js, 'state.parCadenceSecs') !== false &&
    (bool) preg_match('/warnAt = cad > 0 \? cad : 600/', $js) &&
    (bool) preg_match('/dangerAt = cad > 0 \? Math\.round\(cad \* 1\.5\) : 1200/', $js));

// ── Partition predicate (mirrors the board) ──
$isSignedOut = function ($v) { return $v !== null && substr((string) $v, 0, 4) !== '0000'; };
t('NULL signed_out_at → active (not in tray)', $isSignedOut(null) === false);
t("zero-date '0000-00-00 00:00:00' → active", $isSignedOut('0000-00-00 00:00:00') === false);
t('a real datetime → signed out (tray)', $isSignedOut('2026-07-05 12:00:00') === true);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
