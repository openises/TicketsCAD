<?php
/**
 * Phase 153 (2026-09-08) — SIMULATED AllStar relay endpoint.
 * Phase 155 (GH#108 S5) — made honest: off by default, configured on a real
 * page, behind action.manage_config, and no longer freezes the caller's session.
 *
 * This relays a spoken incident summary to a plain Asterisk TEST node over SSH
 * and the Asterisk Manager Interface and measures the recording. It is NOT
 * AllStarLink: no AllStar node, no radio, no PTT is involved.
 *
 * GET  ?action=settings         — connection settings + enabled flag
 *                                  (action.manage_config; the secret is never returned)
 * POST action=save_settings     — update them (action.manage_config; validated; audited)
 * POST action=test_connection   — AMI login and logoff only: nothing sent or played
 *                                  (action.manage_config)
 * POST action=trigger           — {ticket_id, message?} -- relay a spoken summary of an
 *                                  incident (action.dispatch_unit). 404 unless the feature
 *                                  has been switched on; the caller must be able to see the
 *                                  incident.
 *
 * RBAC: trigger reuses action.dispatch_unit (the existing dispatch domain);
 * configuration needs action.manage_config -- deliberately NOT `|| is_admin()`,
 * and not is_admin() alone (Phase 138 lesson: an Org Admin must not reach
 * install-wide settings that name servers and hold a secret).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/allstar-relay.php';
ini_set('display_errors', '0');
set_time_limit(60); // synthesis + delivery + real-time playback can take 30+ seconds

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

function ar_csrf_check(array $input): void
{
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid or expired security token. Please refresh the page.', 403);
    }
}

function ar_read_json_body(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function ar_require_config_perm(): void
{
    if (!rbac_can('action.manage_config')) {
        json_error('Insufficient permissions: change install-wide settings', 403);
    }
}

/** Settings for the browser: the secret is NEVER included, only whether one is stored.
 *  Read uncached: after a save this request must report what it just wrote
 *  (get_variable() caches the whole settings table for the life of the process). */
function ar_public_settings(): array
{
    $s = allstar_relay_settings(true);
    $s['ami_secret_set'] = $s['ami_secret'] !== '';
    unset($s['ami_secret']);
    $s['enabled'] = allstar_relay_enabled(true);
    return $s;
}

if ($method === 'GET' && $action === 'settings') {
    ar_require_config_perm();
    json_response(['settings' => ar_public_settings()]);
    exit;
}

if ($method === 'POST' && $action === 'save_settings') {
    ar_require_config_perm();
    $input = ar_read_json_body();
    ar_csrf_check($input);
    try {
        $changed = allstar_relay_settings_save($input);
        if (array_key_exists('enabled', $input)) {
            $wasEnabled = allstar_relay_enabled(true);
            $nowEnabled = !empty($input['enabled']);
            allstar_relay_save_enabled($nowEnabled);
            if ($wasEnabled !== $nowEnabled) { $changed[] = 'enabled=' . ($nowEnabled ? '1' : '0'); }
        }
        // Key NAMES only -- a secret never reaches the audit row.
        audit_log('comms', 'allstar_relay_settings.update', 'settings', 0,
            'Updated the AllStar relay test settings' . ($changed ? ' (' . implode(', ', $changed) . ')' : ' (no change)'));
        $public = ar_public_settings();
        json_response(['ok' => true, 'changed' => $changed, 'settings' => $public]);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage());
    } catch (Exception $e) {
        error_log('[allstar-relay save_settings] ' . $e->getMessage());
        json_error('Failed to save settings', 500);
    }
    exit;
}

if ($method === 'POST' && $action === 'test_connection') {
    ar_require_config_perm();
    $input = ar_read_json_body();
    ar_csrf_check($input);
    // The check waits on the network; release the session lock first so the
    // administrator's other tabs are not queued behind it.
    session_write_close();
    $result = allstar_relay_test_connection(allstar_relay_settings());
    audit_log('comms', 'allstar_relay.test_connection', 'settings', 0,
        'AllStar relay test connection: ' . ($result['ok'] ? 'ok' : 'failed'));
    json_response($result);
    exit;
}

if ($method === 'POST' && $action === 'trigger') {
    // Switched off (the default) is indistinguishable from "no such feature":
    // a 404 whatever the caller's permissions.
    if (!allstar_relay_enabled()) {
        json_error('Not found', 404);
    }
    if (!rbac_can('action.dispatch_unit')) {
        json_error('Insufficient permissions: dispatch a unit', 403);
    }
    $input = ar_read_json_body();
    ar_csrf_check($input);
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) { json_error('ticket_id required'); }
    // Multi-tenant: only an incident the caller may see. (Before Phase 155 any
    // dispatcher could relay ANY organisation's incident summary by id.)
    if (!org_can_see_ticket($ticketId)) {
        json_error('Incident not found', 404);
    }
    $message = isset($input['message']) ? (string) $input['message'] : null;

    // THE SESSION LOCK. api/auth.php opened the session with PHP's default file
    // handler, which holds an exclusive lock on it until the script ends or the
    // session is closed. The relay below waits (it sleeps for the message's own
    // length while the test node plays it out, 20-30 seconds), so every other
    // request from the same login -- every page, every poll -- queued behind
    // it and the whole session appeared frozen. Everything that needs the
    // session (authentication, CSRF, RBAC, the visibility check) has run; the
    // values stay readable in $_SESSION for the audit row, they are just no
    // longer locked.
    session_write_close();

    try {
        $result = allstar_relay_trigger($ticketId, $message);
        audit_log('comms', 'allstar_relay.trigger', 'ticket', $ticketId,
            'Relay TEST page sent to the simulated node: ' . $result['duration_sec'] . 's, RMS ' . $result['rms']
            . ($result['ok'] ? ' (recording verified audible)' : ' (verification inconclusive)'));
        json_response($result);
    } catch (AllstarRelayUserError $e) {
        json_error($e->getMessage(), 502);
    } catch (RuntimeException $e) {
        // Full detail (ssh stderr, AMI replies, addresses) goes to the log for the
        // administrator; the dispatcher who clicked gets a sentence that names none of it.
        error_log('[allstar-relay trigger] ' . $e->getMessage());
        json_error('The relay test did not complete. The test node did not respond as expected; an administrator can '
            . 'see the details in the server log, or run Test saved connection on the settings page.', 502);
    } catch (Exception $e) {
        error_log('[allstar-relay trigger] ' . $e->getMessage());
        json_error('Relay trigger failed unexpectedly', 500);
    }
    exit;
}

json_error('Unknown action', 404);
