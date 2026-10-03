<?php
/**
 * NewUI v4.0 API — Inbound SIP/PBX trunk admin endpoint
 *
 * GET    ?action=trunks              — list pbx_trunks (never the token — has_token boolean)
 * GET    ?action=trunk&id=N          — single row (never the token)
 * POST   action=trunk_create         — new trunk, mints + returns the bearer token ONCE
 * POST   action=trunk_update         — edit (label/org_id/mute_bypass/wrapup/grace/enabled)
 * POST   action=trunk_toggle         — enable / disable
 * POST   action=trunk_delete         — hard delete (a trunk with no calls is a pure config row)
 * POST   action=trunk_rotate_token   — mint a new bearer token (returned ONCE)
 * POST   action=trunk_test_call      — ring ONE clearly-labelled TEST call through this
 *                                      trunk so an admin can prove the TicketsCAD half
 *                                      (banner, tone, SSE) works before the PBX is wired
 * POST   action=trunk_test_call_end  — end that test call and mark it reviewed so it
 *                                      never lingers in anyone's Missed Calls panel
 *
 * Phase 155: the trunks list also reports each trunk's bridge connection state
 * (never / connected / silent), derived from the heartbeat the bridge POSTs to
 * api/sip-ingest.php, plus the time of the last real call.
 *
 * All actions gated on action.manage_calls (plan.md §8). Every mutation is
 * audit-logged per this project's standing UI-changes-state rule.
 *
 * TOKEN STORAGE — plaintext, masked on GET, shown once at mint/rotate
 * time. Same deliberate, documented convention as inc/dmr_token.php /
 * api/dvswitch.php for this exact class of credential — see
 * inc/sip_token.php's own docblock for the full rationale.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/sip_token.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/inbound-calls.php';
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

/**
 * Trunk rows for the admin page, never including the token. Phase 155 adds
 * the bridge heartbeat (connection_state / heartbeat_age_seconds /
 * bridge_info) and the last real call time. The age is computed by the
 * DATABASE (TIMESTAMPDIFF against its own NOW()) so a PHP-vs-DB clock offset
 * can never make a live bridge look dead. Falls back to the pre-Phase-155
 * column set on an install that has not yet run
 * sql/run_phase155_pbx_trunk_heartbeat.php, so the page still loads.
 */
function st_fetch_trunks(?int $onlyId = null): array
{
    global $prefix;
    $where  = $onlyId !== null ? 'WHERE t.id = ?' : '';
    $params = $onlyId !== null ? [$onlyId] : [];
    $supported = true;
    try {
        $rows = db_fetch_all(
            "SELECT t.id, t.label, t.org_id, t.mute_bypass_enabled, t.wrapup_seconds,
                    t.reassign_grace_seconds, t.enabled, t.created_at, t.updated_at,
                    (t.`bearer_token` IS NOT NULL AND t.`bearer_token` <> '') AS has_token,
                    t.bridge_info,
                    t.last_heartbeat_at,
                    TIMESTAMPDIFF(SECOND, t.last_heartbeat_at, NOW()) AS heartbeat_age_seconds,
                    (SELECT MAX(c.ringing_at) FROM `{$prefix}inbound_calls` c WHERE c.trunk_id = t.id) AS last_call_at
               FROM `{$prefix}pbx_trunks` t
               {$where}
              ORDER BY t.enabled DESC, t.label",
            $params
        );
    } catch (Exception $e) {
        $supported = false;
        $rows = db_fetch_all(
            "SELECT t.id, t.label, t.org_id, t.mute_bypass_enabled, t.wrapup_seconds,
                    t.reassign_grace_seconds, t.enabled, t.created_at, t.updated_at,
                    (t.`bearer_token` IS NOT NULL AND t.`bearer_token` <> '') AS has_token
               FROM `{$prefix}pbx_trunks` t
               {$where}
              ORDER BY t.enabled DESC, t.label",
            $params
        );
    }
    foreach ($rows as &$r) {
        $r['heartbeat_supported'] = $supported;
        $age = (isset($r['heartbeat_age_seconds']) && $r['heartbeat_age_seconds'] !== null)
            ? (int) $r['heartbeat_age_seconds'] : null;
        $r['heartbeat_age_seconds'] = $age;
        $r['connection_state'] = inbound_trunk_connection_state($age);
    }
    unset($r);
    return $rows;
}

function st_require_perm(): void
{
    // Deliberately NOT `|| is_admin()` -- this project's own documented
    // lesson (Phase 138): is_admin()'s action.manage_config fallback is
    // the wrong idiom the moment a feature has a permission code that
    // isn't meant to travel with every admin-tier grant. rbac_can()'s own
    // is_super short-circuit already covers every real Super Admin.
    if (!rbac_can('action.manage_calls')) {
        json_error('Insufficient permissions: manage inbound calls', 403);
    }
}

function st_csrf_check(array $input): void
{
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid or expired security token. Please refresh the page.', 403);
    }
}

function st_read_json_body(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

st_require_perm();

if ($method === 'GET') {

    if ($action === 'trunks') {
        try {
            $rows = st_fetch_trunks();
            json_response(['trunks' => $rows]);
        } catch (Exception $e) {
            error_log('[sip-trunks trunks] ' . $e->getMessage());
            json_error('trunks query failed', 500);
        }
    }

    if ($action === 'trunk') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        try {
            $rows = st_fetch_trunks($id);
            if (!$rows) json_error('not found', 404);
            json_response(['trunk' => $rows[0]]);
        } catch (Exception $e) {
            json_error('query failed', 500);
        }
    }

    json_error('Unknown action', 404);
}

if ($method === 'POST') {

    $input = st_read_json_body();

    if ($action === 'trunk_create') {
        st_csrf_check($input);
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') json_error('label required');
        $orgId = isset($input['org_id']) && $input['org_id'] !== '' && $input['org_id'] !== null
            ? (int) $input['org_id'] : null;
        $muteBypass = !empty($input['mute_bypass_enabled']) ? 1 : 0;
        $wrapupSecs = max(0, (int) ($input['wrapup_seconds'] ?? 90));
        $graceSecs  = max(0, (int) ($input['reassign_grace_seconds'] ?? 20));

        try {
            $token = sip_token_mint();
            db_query(
                "INSERT INTO `{$prefix}pbx_trunks`
                    (label, org_id, bearer_token, mute_bypass_enabled, wrapup_seconds,
                     reassign_grace_seconds, enabled)
                 VALUES (?, ?, ?, ?, ?, ?, 1)",
                [$label, $orgId, $token, $muteBypass, $wrapupSecs, $graceSecs]
            );
            $id = (int) db_insert_id();
            audit_log('comms', 'create', 'pbx_trunk', $id, "Created inbound-call trunk '{$label}'");
            json_response([
                'trunk_id' => $id,
                'bearer_token' => $token, // shown ONCE
                'note' => 'Configure your PBX/SIP adapter (services/sip-bridge/) with this '
                        . 'token as its Authorization: Bearer header when POSTing to '
                        . 'api/sip-ingest.php. It will not be shown again — rotate it '
                        . 'from this panel if it is lost.',
            ]);
        } catch (Exception $e) {
            error_log('[sip-trunks trunk_create] ' . $e->getMessage());
            json_error('create failed', 500);
        }
    }

    if ($action === 'trunk_update') {
        st_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $existing = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]);
        if (!$existing) json_error('not found', 404);

        $label = isset($input['label']) ? trim((string) $input['label']) : (string) $existing['label'];
        if ($label === '') json_error('label required');
        $orgId = array_key_exists('org_id', $input) && $input['org_id'] !== '' && $input['org_id'] !== null
            ? (int) $input['org_id'] : null;
        $muteBypass = !empty($input['mute_bypass_enabled']) ? 1 : 0;
        $wrapupSecs = max(0, (int) ($input['wrapup_seconds'] ?? $existing['wrapup_seconds']));
        $graceSecs  = max(0, (int) ($input['reassign_grace_seconds'] ?? $existing['reassign_grace_seconds']));

        try {
            db_query(
                "UPDATE `{$prefix}pbx_trunks`
                    SET label = ?, org_id = ?, mute_bypass_enabled = ?, wrapup_seconds = ?,
                        reassign_grace_seconds = ?
                  WHERE id = ?",
                [$label, $orgId, $muteBypass, $wrapupSecs, $graceSecs, $id]
            );
            audit_log('comms', 'update', 'pbx_trunk', $id, "Updated inbound-call trunk '{$label}'");
            json_response(['success' => true]);
        } catch (Exception $e) {
            error_log('[sip-trunks trunk_update] ' . $e->getMessage());
            json_error('update failed', 500);
        }
    }

    if ($action === 'trunk_toggle') {
        st_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $existing = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]);
        if (!$existing) json_error('not found', 404);
        $newEnabled = (int) $existing['enabled'] === 1 ? 0 : 1;
        try {
            db_query("UPDATE `{$prefix}pbx_trunks` SET enabled = ? WHERE id = ?", [$newEnabled, $id]);
            audit_log('comms', $newEnabled ? 'enable' : 'disable', 'pbx_trunk', $id,
                ($newEnabled ? 'Enabled' : 'Disabled') . " inbound-call trunk '{$existing['label']}'");
            json_response(['success' => true, 'enabled' => $newEnabled]);
        } catch (Exception $e) {
            json_error('toggle failed', 500);
        }
    }

    if ($action === 'trunk_delete') {
        st_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $existing = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]);
        if (!$existing) json_error('not found', 404);
        try {
            // No FK on inbound_calls.trunk_id (matches this project's
            // existing convention for this table family — plain,
            // unenforced INT references) -- historical call records are
            // deliberately left in place for audit-trail continuity even
            // after the trunk config row is removed.
            db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]);
            audit_log('comms', 'delete', 'pbx_trunk', $id, "Deleted inbound-call trunk '{$existing['label']}'");
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('delete failed', 500);
        }
    }

    if ($action === 'trunk_rotate_token') {
        st_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $existing = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]);
        if (!$existing) json_error('not found', 404);
        try {
            $token = sip_token_mint();
            sip_token_store($id, $token);
            audit_log('comms', 'rotate', 'pbx_trunk', $id, "Rotated bearer token for trunk '{$existing['label']}'");
            json_response([
                'trunk_id' => $id,
                'bearer_token' => $token, // shown ONCE
                'note' => 'Update your PBX/SIP adapter\'s token immediately -- the old '
                        . 'token stops working the moment this is saved.',
            ]);
        } catch (Exception $e) {
            json_error('rotate failed', 500);
        }
    }

    if ($action === 'trunk_test_call') {
        st_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $trunk = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]);
        if (!$trunk) json_error('not found', 404);
        if ((int) $trunk['enabled'] !== 1) {
            json_error('Enable this trunk first -- a disabled trunk drops every call, including a test.', 409);
        }
        // The banner shows caller_number, so it says TEST CALL where a number
        // would be -- a dispatcher cannot mistake it for a caller. It has no
        // digits, and _p153_resolve_constituent() ignores anything under four
        // digits, so no Constituents row is created for a call that never
        // happened.
        $testCallId = 'test-' . bin2hex(random_bytes(4));
        try {
            $res = inbound_calls_ingest_event($trunk, [
                'event'         => 'ringing',
                'call_id'       => $testCallId,
                'caller_number' => 'TEST CALL',
                'caller_name'   => 'TEST CALL - setup check',
                'called_number' => 'TEST',
                'event_ts'      => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            if (empty($res['ok'])) {
                json_error('test call failed: ' . ($res['reason'] ?? 'unknown'), 500);
            }
            audit_log('comms', 'test_call', 'pbx_trunk', $id, "Rang a TEST call through inbound-call trunk '{$trunk['label']}'");
            json_response(['success' => true, 'provider_call_id' => $testCallId]);
        } catch (Exception $e) {
            error_log('[sip-trunks trunk_test_call] ' . $e->getMessage());
            json_error('test call failed', 500);
        }
    }

    if ($action === 'trunk_test_call_end') {
        st_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        $providerCallId = (string) ($input['provider_call_id'] ?? '');
        // Only ever act on a call THIS endpoint created -- never on a real one.
        if ($id <= 0 || strpos($providerCallId, 'test-') !== 0) json_error('id and a test call id are required');
        $trunk = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]);
        if (!$trunk) json_error('not found', 404);
        try {
            // End it as `ended`, not `abandoned`: abandoned would publish
            // call:abandoned, which every open browser files under Missed
            // Calls (and keeps there until its next refresh) -- a test must
            // leave no trace on anyone's screen. The row is also marked
            // reviewed so the server-side Missed list never offers it either.
            $call = inbound_call_find_by_provider((int) $trunk['id'], $providerCallId);
            if ($call && $call['state'] === 'ringing') {
                $uid = (int) ($_SESSION['user_id'] ?? 0);
                db_query(
                    "UPDATE `{$prefix}inbound_calls`
                        SET `state` = 'ended', `ended_at` = NOW(), `last_event_at` = NOW(),
                            `reviewed_at` = NOW(), `reviewed_by` = ?
                      WHERE `id` = ? AND `state` = 'ringing'",
                    [$uid, (int) $call['id']]
                );
                inbound_call_audit((int) $call['id'], 'ended', $uid, (string) ($_SESSION['user'] ?? 'admin'), 'test call finished');
                $updated = inbound_call_get((int) $call['id']);
                if ($updated) {
                    _p149_sse((int) $call['id'], 'call:ended', inbound_call_broadcast_payload($updated, $trunk),
                        $updated['org_id'] !== null ? (int) $updated['org_id'] : null);
                }
            }
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('could not end test call', 500);
        }
    }

    json_error('Unknown action', 404);
}

json_error('Method not allowed', 405);
