<?php
/**
 * Phase 54 (2026-06-14) — Personal-resource clock-in API.
 *
 * Routes:
 *
 *   GET  ?action=status[&member_id=N]
 *        — return clock-in status for member_id (defaults to the
 *          calling user's member). Any logged-in user can call this
 *          for themselves; admins can query any member.
 *
 *   POST ?action=clock_in   { csrf_token, [member_id] }
 *        — create-or-activate the member's personal unit. Same scope
 *          as GET: self-clock allowed for any logged-in user, admin
 *          can clock anyone in.
 *
 *   POST ?action=clock_out  { csrf_token, [member_id] }
 *        — flip the personal unit to Inactive.
 *
 * The self-clock-in flow is explicitly NOT RBAC-gated beyond "logged
 * in with a linked member record" — Eric's spec: "Anyone with a
 * member record. Field volunteers self-activate when arriving on
 * scene; no dispatcher action required."
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/personnel-units.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : $_POST;
    if (!$action && !empty($input['action'])) $action = $input['action'];
}

/**
 * Resolve the member_id we should act on. Self-acting if no member_id
 * passed (or it matches the caller's linked member). Otherwise needs
 * action.manage_members (operator+).
 */
function _pu_resolve_member_id($input, string $intent = 'status'): int {
    global $prefix;
    $callerUser = (int) ($_SESSION['user_id'] ?? 0);

    // 2026-06-30 (Eric beta) — disambiguate when both linkage paths
    // exist and disagree. A user can be linked to a member two ways:
    //   1. u.member  = m.id          (canonical: set by user admin UI)
    //   2. m.user_id = u.id          (back-reference: set during member edit)
    //
    // On Eric's training install, Steven Peterson's member.user_id had
    // been (incorrectly) set to Eric's user_id=29, while Eric's
    // user.member correctly pointed to his own member id=132. The old
    // OR-JOIN matched both rows; MariaDB returned Steven first, so
    // Eric's mobile widget operated Steven's personal unit (WA0HDC)
    // — a both-visible and a permission-escape bug.
    //
    // The fix: prefer the u.member pointer because that's the field
    // the user-management UI maintains; m.user_id is only the back-
    // reference. ORDER BY a CASE expression that ranks the canonical
    // match first, then LIMIT 1.
    $linked = db_fetch_one(
        "SELECT m.id AS mid
           FROM `{$prefix}user` u
           LEFT JOIN `{$prefix}member` m
             ON (m.user_id = u.id OR u.member = m.id)
          WHERE u.id = ?
            AND m.id IS NOT NULL
          ORDER BY (CASE WHEN m.id = u.member THEN 0 ELSE 1 END), m.id
          LIMIT 1",
        [$callerUser]
    );
    $callerMid = (int) ($linked['mid'] ?? 0);

    $reqMid = (int) ($input['member_id'] ?? $_GET['member_id'] ?? $callerMid);
    if (!$reqMid) {
        json_error('No member linked to this user account — ask your admin to link your roster record.', 400);
    }
    if ($reqMid !== $callerMid) {
        // Acting on someone else — admin/operator scope required.
        if (!is_admin() && !rbac_can('action.manage_members')) {
            json_error('Operator access required to clock another member in/out.', 403);
        }
    } elseif ($intent === 'clock_in' || $intent === 'clock_out') {
        // Phase 57 — self-clock-in is gated by action.self_clock_in. Admins
        // can revoke it per-role on the Roles & Permissions page to block
        // certain user categories (Read-Only, Constituent) from acting as
        // personal resources. The status read remains ungated — the
        // navbar/profile/mobile UI needs to fetch state to decide whether
        // to show the toggle at all.
        if (!is_admin() && !rbac_can('action.self_clock_in')) {
            json_error('Your role does not have permission to self-clock-in as a personal resource. Ask an admin if you need this access.', 403);
        }
    }
    return $reqMid;
}

if ($method === 'GET' && $action === 'status') {
    $mid = _pu_resolve_member_id($_GET, 'status');
    $status = pu_status_for_member($mid);
    // Tell the UI whether self-clock is permitted so it can hide the
    // toggle for blocked roles instead of letting the user click and
    // hit a 403.
    $status['can_self_clock'] = (function_exists('is_admin') && is_admin())
        || (function_exists('rbac_can') && rbac_can('action.self_clock_in'));
    json_response($status);
}

if ($method === 'POST' && $action === 'clock_in') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $mid = _pu_resolve_member_id($input, 'clock_in');
    $unit = pu_clock_in($mid);
    json_response([
        'success' => true,
        'unit'    => $unit,
        'status'  => pu_status_for_member($mid),
    ]);
}

if ($method === 'POST' && $action === 'clock_out') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $mid = _pu_resolve_member_id($input, 'clock_out');
    $unit = pu_clock_out($mid);
    json_response([
        'success' => true,
        'unit'    => $unit,
        'status'  => pu_status_for_member($mid),
    ]);
}

json_error('Unknown action: ' . $action);
