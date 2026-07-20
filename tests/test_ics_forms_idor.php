<?php
/**
 * Phase 73q regression tests — ICS forms IDOR fix.
 *
 * Verifies ics_form_accessible() denies cross-tenant read/write of
 * saved ICS-213 (and friends) by id enumeration.
 *
 * Pre-fix behaviour: any logged-in user could read or overwrite any
 * ICS form by guessing its id.
 * Post-fix behaviour: only admin, the form's creator, or a user with
 * access to the form's incident may touch it.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$tests = 0;
$fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) {
        $fails++;
        echo "FAIL: $label\n";
    }
}

// Pull the helper into scope without booting the full API stack.
// Doing it this way avoids auth.php's mandatory session redirect and
// lets us drive ics_form_accessible() through synthetic session state.
function _load_ics_form_accessible(): void
{
    $src = file_get_contents(__DIR__ . '/../api/ics-forms.php');
    if (!preg_match('/function ics_form_accessible\(.*?\n\}\n/s', $src, $m)) {
        throw new RuntimeException('could not extract ics_form_accessible from api/ics-forms.php');
    }
    eval($m[0]);
}

// Stub the access-helper + RBAC dependencies so we can run the
// function isolated from a live DB session. ics_form_accessible only
// calls is_admin() and user_can_access_entity(). We define both with
// behaviour we can flip per-test via a global.
$GLOBALS['_t_is_admin']         = false;
$GLOBALS['_t_can_access_inc']   = false;
if (!function_exists('is_admin')) {
    function is_admin(): bool { return $GLOBALS['_t_is_admin'] ?? false; }
}
if (!function_exists('user_can_access_entity')) {
    function user_can_access_entity(string $type, int $id): bool {
        return $GLOBALS['_t_can_access_inc'] ?? false;
    }
}
_load_ics_form_accessible();

// ── Scenario A: admin sees everything ─────────────────────────────
$_SESSION['user_id'] = 99;
$GLOBALS['_t_is_admin'] = true;
$row = ['id' => 1, 'incident_id' => 500, 'created_by' => 1];
tcheck(ics_form_accessible($row) === true, 'admin can read any form');

// ── Scenario B: creator sees their own orphan form ────────────────
$GLOBALS['_t_is_admin'] = false;
$_SESSION['user_id'] = 42;
$row = ['id' => 2, 'incident_id' => null, 'created_by' => 42];
tcheck(ics_form_accessible($row) === true, 'creator can read own orphan form');

// ── Scenario C: stranger cannot read another's orphan form ────────
$_SESSION['user_id'] = 99;
$row = ['id' => 3, 'incident_id' => null, 'created_by' => 42];
tcheck(ics_form_accessible($row) === false, 'stranger denied on other user orphan form');

// ── Scenario D: bound to incident user can access — allowed ───────
$_SESSION['user_id'] = 99;
$GLOBALS['_t_can_access_inc'] = true;
$row = ['id' => 4, 'incident_id' => 500, 'created_by' => 42];
tcheck(ics_form_accessible($row) === true, 'allowed when incident is accessible');

// ── Scenario E: bound to incident user cannot access — denied ─────
$GLOBALS['_t_can_access_inc'] = false;
$row = ['id' => 5, 'incident_id' => 500, 'created_by' => 42];
tcheck(ics_form_accessible($row) === false, 'denied when incident is inaccessible');

// ── Scenario F: user_id=0 (unauthenticated) on orphan — denied ────
$_SESSION['user_id'] = 0;
$row = ['id' => 6, 'incident_id' => null, 'created_by' => 0];
tcheck(ics_form_accessible($row) === false, 'no auth + orphan + creator=0 still denied');

// ── Scenario G: incident_id=0 treated as orphan ───────────────────
$_SESSION['user_id'] = 99;
$GLOBALS['_t_is_admin'] = false;
$GLOBALS['_t_can_access_inc'] = true;  // would allow if we mistakenly check incident=0
$row = ['id' => 7, 'incident_id' => 0, 'created_by' => 42];
tcheck(ics_form_accessible($row) === false, 'incident_id=0 falls back to orphan-owner rule');

// ── GH #79: configurable standalone-form sharing (single-org installs) ──
// The second arg ($shareStandalone) opts a single-organization install into
// team-wide visibility of orphan forms. It must ONLY widen the orphan case,
// and never for an unauthenticated session.

// H: sharing ON — a stranger CAN read another user's orphan form.
$GLOBALS['_t_is_admin'] = false;
$_SESSION['user_id'] = 99;
$row = ['id' => 8, 'incident_id' => null, 'created_by' => 42];
tcheck(ics_form_accessible($row, true) === true,
    'share ON: authenticated stranger can read orphan form');

// I: sharing OFF (default) — same stranger is still denied (regression guard
//    that the default did not change).
tcheck(ics_form_accessible($row, false) === false,
    'share OFF: authenticated stranger still denied on orphan form');

// J: sharing ON but UNAUTHENTICATED — still denied. Sharing must not open
//    forms to a session with no user id.
$_SESSION['user_id'] = 0;
$row = ['id' => 9, 'incident_id' => null, 'created_by' => 42];
tcheck(ics_form_accessible($row, true) === false,
    'share ON but no auth: orphan form still denied');

// K: sharing ON must NOT bypass incident-level access control. A form bound
//    to an incident the user cannot see stays denied regardless of the flag.
$_SESSION['user_id'] = 99;
$GLOBALS['_t_can_access_inc'] = false;
$row = ['id' => 10, 'incident_id' => 500, 'created_by' => 42];
tcheck(ics_form_accessible($row, true) === false,
    'share ON does not bypass incident access control');

// Reset for any followups
$GLOBALS['_t_is_admin'] = false;
$GLOBALS['_t_can_access_inc'] = false;

echo "ICS-forms IDOR regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
