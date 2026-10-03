<?php
/**
 * Digital voice bridge channel admin endpoint (Phase 155, GH#151 + GH#129).
 *
 * comm_channels rows with adapter 'dvmproject' (DVMProject dvmbridge) or
 * 'usrp_bridge' (any other USRP-speaking bridge), served by the generic USRP
 * leg in the audio-matrix service. LISTEN-ONLY in this release. See
 * inc/voice-bridge-channels.php and
 * specs/phase-155-community-backlog/151-dvm-host-and-129-p25-bridge.md.
 *
 * GET  ?action=list              — channels (+ live leg state), the policy
 *                                   status, and the FNE REST settings
 * GET  ?action=get&id=N          — one channel
 * GET  ?action=snippet&id=N      — the bridge-config text for that channel
 * GET  ?action=policy            — the usage-policy statement + who/when
 * GET  ?action=fne_status[&id=N] — read-only link check against the FNE REST
 *                                   API (all channels, or one)
 * POST action=create             — adapter, slug, label, regulatory_class,
 *                                   enabled + config fields
 * POST action=update             — id + any of label/regulatory_class/
 *                                   enabled/config fields
 * POST action=delete             — id
 * POST action=policy_ack         — acknowledged:true records, false withdraws
 *                                   (withdrawing disables every dvmproject row)
 * POST action=fne_settings       — url, password (write-only), verify_tls,
 *                                   stale_secs
 *
 * RBAC: action.manage_voice_bridges (tier 1: Super Admin + Org Admin) for
 * everything here — including the GETs, because a channel's full config names
 * the bridge's address and ports. Deliberately NOT `|| is_admin()`: that
 * fallback's action.manage_config branch is the wrong idiom the moment a
 * permission is meant to travel narrower than every admin-tier grant.
 * Operating a channel uses screen.console; patching it uses the existing
 * action.manage_matrix / action.patch_create (unchanged).
 *
 * Every successful DB write is ALSO applied to the live matrix service, and
 * rolled back when the service refuses (a channel row whose leg never
 * attached is a strip that silently carries nothing). CSRF on every POST.
 * Audit trail: voice_bridge.create/.update/.delete/.policy_ack/
 * .policy_revoke/.fne_settings; the REST password is never logged, returned
 * or echoed.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/voice-bridge-channels.php';
require_once __DIR__ . '/../inc/matrix-control-client.php';
require_once __DIR__ . '/../inc/matrix-routes.php';
require_once __DIR__ . '/../inc/dvm-fne-rest.php';

if (!rbac_can('action.manage_voice_bridges')) {
    json_error('Insufficient permissions: manage digital voice bridges', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

function vbca_read_json_body(): array {
    $input = json_decode((string) file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function vbca_csrf_check(array $input): void {
    if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
        json_error('Invalid or expired security token. Please refresh the page.', 403);
    }
}

/** The leg config fields an admin form may send (everything else ignored). */
function vbca_config_input(array $input): array {
    $out = [];
    foreach (['mode', 'bridge_host', 'bridge_tx_port', 'listen_host', 'listen_port', 'rx_hang_ms',
              'talkgroup', 'fne_peer_id', 'tx_enabled'] as $k) {
        if (array_key_exists($k, $input)) { $out[$k] = $input[$k]; }
    }
    return $out;
}

/** Re-send this channel's enabled patches to the live matrix after a
 *  (re)attach: detaching a channel prunes its routes from the running
 *  service, but the comm_routes rows are still the truth. "Already exists"
 *  is the expected answer when the channel was edited without a detach. */
function vbca_reapply_routes(int $channelId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $failed = 0; $applied = 0;
    try {
        $ids = db_fetch_all(
            "SELECT id FROM `{$prefix}comm_routes`
              WHERE enabled = 1 AND (src_channel_id = ? OR dst_channel_id = ?)",
            [$channelId, $channelId]
        );
    } catch (Exception $e) {
        return ['applied' => 0, 'failed' => 0];
    }
    foreach ($ids as $r) {
        $full = matrix_route_full((int) $r['id']);
        if (!$full || empty($full['src_key']) || empty($full['dst_key'])) { continue; }
        $res = matrix_control_apply_create($full);
        $already = !$res['ok'] && is_array($res['body']) && stripos((string) ($res['body']['error'] ?? ''), 'exists') !== false;
        if ($res['ok'] || $already) { $applied++; } else { $failed++; }
    }
    return ['applied' => $applied, 'failed' => $failed];
}

/** Apply a row to the live matrix. Returns the matrix_control_request() shape. */
function vbca_apply_live(array $row): array {
    return matrix_control_apply_leg_create(
        $row['channel_key'], $row['label'], $row['regulatory_class'], $row['adapter'], vbc_leg_config($row['config'])
    );
}

function vbca_policy_status(): array {
    return [
        'acknowledged' => vbc_policy_acknowledged(),
        'ack'          => vbc_policy_ack_get(),
        'version'      => VBC_POLICY_VERSION,
        'paragraphs'   => vbc_policy_paragraphs(),
    ];
}

function vbca_fne_settings_public(): array {
    $s = dvm_fne_rest_settings();
    return [
        'url'          => $s['url'],
        'password_set' => $s['password'] !== '',   // the password itself is never sent to a browser
        'verify_tls'   => (bool) $s['verify_tls'],
        'stale_secs'   => (int) $s['stale_secs'],
    ];
}

// ═══════════════════════════ GET ═══════════════════════════════════════
if ($method === 'GET') {

    if ($action === 'list') {
        try {
            $rows = vbc_list();
            $legs = matrix_control_legs();
            foreach ($rows as &$r) {
                if ($legs['ok']) {
                    $h = $legs['legs'][$r['channel_key']] ?? null;
                    $r['leg'] = $h ? [
                        'attached'  => true,
                        'running'   => !empty($h['running']),
                        'receiving' => !empty($h['receiving']),
                        'rx_calls'  => (int) ($h['rx_calls'] ?? 0),
                        'rx_frames' => (int) ($h['rx_frames'] ?? 0),
                        'rx_dropped_source' => (int) ($h['rx_dropped_source'] ?? 0),
                        'tx_frames_blocked' => (int) ($h['tx_frames_blocked'] ?? 0),
                    ] : ['attached' => false];
                } else {
                    $r['leg'] = null;   // unknown: the service could not be asked
                }
            }
            unset($r);
            json_response([
                'channels'     => $rows,
                'legs_error'   => $legs['ok'] ? null : $legs['error'],
                'policy'       => vbca_policy_status(),
                'fne'          => vbca_fne_settings_public(),
            ]);
        } catch (Exception $e) {
            json_error_safe('list query failed', $e, 'voice-bridge-channels list');
        }
        exit;
    }

    if ($action === 'get') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { json_error('id required'); }
        $row = vbc_get($id);
        if (!$row) { json_error('not found', 404); }
        json_response(['channel' => $row]);
        exit;
    }

    if ($action === 'snippet') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { json_error('id required'); }
        $row = vbc_get($id);
        if (!$row) { json_error('not found', 404); }
        json_response(['snippet' => vbc_snippet($row), 'adapter' => $row['adapter']]);
        exit;
    }

    if ($action === 'policy') {
        json_response(vbca_policy_status());
        exit;
    }

    if ($action === 'fne_status') {
        $s = dvm_fne_rest_settings();
        $out = ['configured' => dvm_fne_rest_configured(), 'url' => $s['url'], 'reachable' => null,
                'error' => null, 'peers_total' => null, 'peers_connected' => null, 'channels' => []];
        if (!$out['configured']) {
            json_response($out);
            exit;
        }
        $only = (int) ($_GET['id'] ?? 0);
        $rows = array_values(array_filter(vbc_list(), function ($r) use ($only) {
            return $r['adapter'] === 'dvmproject' && ($only <= 0 || (int) $r['id'] === $only);
        }));
        $q = dvm_fne_peers();
        $out['reachable'] = (bool) $q['ok'];
        if (!$q['ok']) {
            $out['error'] = $q['error'];
            foreach ($rows as $r) {
                $out['channels'][$r['id']] = ['state' => 'unknown', 'reason' => (string) $q['error']];
            }
            json_response($out);
            exit;
        }
        $out['peers_total'] = count($q['peers']);
        $out['peers_connected'] = count(array_filter($q['peers'], function ($p) { return is_array($p) && !empty($p['connected']); }));
        $tgs = null;
        foreach ($rows as $r) {
            $peerId = (int) ($r['config']['fne']['peer_id'] ?? 0);
            $entry = ['peer_id' => $peerId ?: null, 'state' => 'unknown', 'reason' => 'no FNE peer ID is set for this channel'];
            if ($peerId > 0) {
                $found = null;
                foreach ($q['peers'] as $p) {
                    if (is_array($p) && isset($p['peerId']) && (int) $p['peerId'] === $peerId) { $found = $p; break; }
                }
                $entry = array_merge(['peer_id' => $peerId], dvm_fne_classify_peer($found, time(), $s['stale_secs']));
                if ($found) {
                    $entry['identity'] = (string) ($found['config']['identity'] ?? '');
                    $entry['software'] = (string) ($found['config']['software'] ?? '');
                }
            }
            $tg = (string) ($r['config']['talkgroup'] ?? '');
            if ($tg !== '') {
                if ($tgs === null) { $tgs = dvm_fne_tgs(); }
                $found = null;
                if ($tgs['ok']) {
                    $found = false;
                    foreach ($tgs['tgs'] as $t) {
                        if (is_array($t) && isset($t['source']['tgid']) && (int) $t['source']['tgid'] === (int) $tg) { $found = true; break; }
                    }
                }
                $entry['talkgroup'] = ['tgid' => (int) $tg, 'found' => $found];
            }
            $out['channels'][$r['id']] = $entry;
        }
        json_response($out);
        exit;
    }

    json_error('Unknown action', 404);
}

// ═══════════════════════════ POST ══════════════════════════════════════
if ($method === 'POST') {

    $input = vbca_read_json_body();

    if ($action === 'create') {
        vbca_csrf_check($input);
        $adapter  = (string) ($input['adapter'] ?? '');
        $slug     = (string) ($input['slug'] ?? '');
        $label    = (string) ($input['label'] ?? '');
        $regClass = (string) ($input['regulatory_class'] ?? 'amateur');
        $enabled  = !array_key_exists('enabled', $input) || !empty($input['enabled']);

        try {
            $id  = vbc_create($adapter, $slug, $label, $regClass, vbca_config_input($input), $enabled);
            $row = vbc_get($id);

            if ($enabled) {
                $applied = vbca_apply_live($row);
                if (!$applied['ok']) {
                    vbc_delete($id);   // roll back — never a silently-queued channel
                    json_error('The audio-matrix service did not accept the channel, so it was NOT created. '
                        . $applied['error'], 502);
                }
            }

            audit_log('config', 'voice_bridge.create', 'comm_channels', $id,
                "Digital voice bridge channel created: '{$row['label']}' ({$row['channel_key']}), "
                . "{$row['adapter']}, {$row['regulatory_class']}, listen-only",
                ['channel_key' => $row['channel_key'], 'adapter' => $row['adapter'],
                 'regulatory_class' => $row['regulatory_class'], 'enabled' => (int) $row['enabled'],
                 'config' => $row['config']]);
            json_response(['ok' => true, 'id' => $id, 'channel' => $row]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (RuntimeException $e) {
            if ((int) $e->getCode() === 403) {
                json_response(['error' => $e->getMessage(), 'needs_policy_ack' => true], 403);
            }
            json_error($e->getMessage(), (int) $e->getCode() === 409 ? 409 : 400);
        } catch (Exception $e) {
            json_error_safe('Failed to create the channel', $e, 'voice-bridge-channels create');
        }
        exit;
    }

    if ($action === 'update') {
        vbca_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) { json_error('id required'); }
        $edit = vbca_config_input($input);
        foreach (['label', 'regulatory_class', 'enabled'] as $k) {
            if (array_key_exists($k, $input)) { $edit[$k] = $input[$k]; }
        }
        try {
            $res = vbc_update($id, $edit);
            $row = $res['row']; $before = $res['before'];

            // Apply to the live matrix: enabled -> (re)attach, then restore its
            // patches; disabled -> detach (best effort — the row is saved
            // disabled either way).
            if ((int) $row['enabled'] === 1) {
                $applied = vbca_apply_live($row);
                if (!$applied['ok']) {
                    vbc_restore($before);   // the database must not disagree with the running service
                    json_error('The audio-matrix service did not accept the change, so it was NOT saved. '
                        . $applied['error'], 502);
                }
                if ((int) $before['enabled'] !== 1) { vbca_reapply_routes($id); }
            } else {
                matrix_control_apply_leg_delete($row['channel_key']);
            }

            audit_log('config', 'voice_bridge.update', 'comm_channels', $id,
                "Digital voice bridge channel updated: '{$row['label']}' ({$row['channel_key']})",
                ['before' => ['label' => $before['label'], 'regulatory_class' => $before['regulatory_class'],
                              'enabled' => (int) $before['enabled'], 'config' => $before['config']],
                 'after'  => ['label' => $row['label'], 'regulatory_class' => $row['regulatory_class'],
                              'enabled' => (int) $row['enabled'], 'config' => $row['config']]]);
            json_response(['ok' => true, 'channel' => $row]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (RuntimeException $e) {
            if ((int) $e->getCode() === 403) {
                json_response(['error' => $e->getMessage(), 'needs_policy_ack' => true], 403);
            }
            json_error($e->getMessage(), (int) $e->getCode() === 404 ? 404 : 400);
        } catch (Exception $e) {
            json_error_safe('Failed to update the channel', $e, 'voice-bridge-channels update');
        }
        exit;
    }

    if ($action === 'delete') {
        vbca_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) { json_error('id required'); }
        try {
            $gone = vbc_delete($id);
            if (!$gone) { json_error('not found', 404); }
            $row = $gone['row'];
            // Best effort: the row is already gone, so a service that cannot
            // be reached now simply will not load this channel at its next
            // start either.
            matrix_control_apply_leg_delete($row['channel_key']);
            audit_log('config', 'voice_bridge.delete', 'comm_channels', $id,
                "Digital voice bridge channel deleted: '{$row['label']}' ({$row['channel_key']}); "
                . $gone['routes_removed'] . ' patch(es) removed',
                ['channel_key' => $row['channel_key'], 'adapter' => $row['adapter'],
                 'routes_removed' => $gone['routes_removed']]);
            json_response(['ok' => true, 'routes_removed' => $gone['routes_removed']]);
        } catch (Exception $e) {
            json_error_safe('Failed to delete the channel', $e, 'voice-bridge-channels delete');
        }
        exit;
    }

    if ($action === 'policy_ack') {
        vbca_csrf_check($input);
        try {
            if (!empty($input['acknowledged'])) {
                $rec = vbc_policy_ack_record($_SESSION['user_id'] ?? 0, $_SESSION['user'] ?? '');
                audit_log('config', 'voice_bridge.policy_ack', 'settings', 'dvm_policy_ack',
                    'DVMProject usage-policy statement acknowledged by ' . $rec['username'],
                    $rec, AUDIT_HIGH);
                json_response(['ok' => true, 'policy' => vbca_policy_status()]);
            } else {
                $was = vbc_disable_all_dvmproject();
                vbc_policy_ack_revoke();
                foreach ($was as $w) { matrix_control_apply_leg_delete($w['channel_key']); }
                audit_log('config', 'voice_bridge.policy_revoke', 'settings', 'dvm_policy_ack',
                    'DVMProject usage-policy acknowledgment withdrawn; ' . count($was) . ' channel(s) disabled',
                    ['disabled' => array_map(function ($w) { return $w['channel_key']; }, $was)], AUDIT_HIGH);
                json_response(['ok' => true, 'disabled' => count($was), 'policy' => vbca_policy_status()]);
            }
        } catch (Exception $e) {
            json_error_safe('Failed to record the acknowledgment', $e, 'voice-bridge-channels policy_ack');
        }
        exit;
    }

    if ($action === 'fne_settings') {
        vbca_csrf_check($input);
        try {
            $urlIn = trim((string) ($input['url'] ?? ''));
            $warning = null;
            if ($urlIn !== '') {
                $v = dvm_fne_rest_validate_url($urlIn);
                $urlIn = $v['url'];
                $warning = $v['warning'];
            }
            $stale = $input['stale_secs'] ?? 30;
            if (!preg_match('/^[0-9]{1,4}$/', (string) $stale) || (int) $stale < 5 || (int) $stale > 3600) {
                json_error('The stale-ping threshold must be between 5 and 3600 seconds.');
            }
            vbc_setting_set('dvm_fne_rest_url', $urlIn);
            vbc_setting_set('dvm_fne_rest_verify_tls', !empty($input['verify_tls']) ? '1' : '0');
            vbc_setting_set('dvm_fne_ping_stale_secs', (string) (int) $stale);
            // Write-only: a blank or placeholder password means "unchanged",
            // never "clear" (a browser cannot echo the real one back).
            $pwChanged = false;
            if (array_key_exists('password', $input)) {
                require_once __DIR__ . '/../inc/settings-secrets.php';
                if (!is_masked_secret_value($input['password'])) {
                    vbc_setting_set('dvm_fne_rest_password', (string) $input['password']);
                    $pwChanged = true;
                }
            }
            if ($urlIn === '') {
                // Clearing the address turns the status check off entirely.
                vbc_setting_set('dvm_fne_rest_password', '');
                $pwChanged = true;
            }
            dvm_fne_token_forget();   // a new url/password invalidates any cached token
            audit_log('config', 'voice_bridge.fne_settings', 'settings', 'dvm_fne_rest',
                'FNE REST status settings updated' . ($urlIn === '' ? ' (status check turned off)' : ''),
                ['url' => $urlIn, 'verify_tls' => !empty($input['verify_tls']),
                 'stale_secs' => (int) $stale, 'password_changed' => $pwChanged]);
            json_response(['ok' => true, 'fne' => vbca_fne_settings_public(), 'warning' => $warning]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (Exception $e) {
            json_error_safe('Failed to save the FNE settings', $e, 'voice-bridge-channels fne_settings');
        }
        exit;
    }

    json_error('Unknown action', 404);
}

json_error('Method not allowed', 405);
