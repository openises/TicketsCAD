<?php
/**
 * Public HTTP audio-stream channel admin endpoint (2026-09-08, Eric: "a
 * source of audio we can listen to while testing services... I want to
 * create multiple streams as needed").
 *
 * GET  ?action=list              — every http_stream channel
 * GET  ?action=get&id=N          — one channel
 * POST action=create             — new channel: channel_key, label,
 *                                   regulatory_class, url
 * POST action=update             — id + label/regulatory_class/url/enabled
 * POST action=delete             — id
 *
 * Every successful DB write is ALSO applied to the live running matrix
 * service immediately (inc/matrix-control-client.php) — same fail-closed
 * discipline as api/matrix.php's route CRUD: if the control plane is
 * unreachable or rejects the change, the DB write is rolled back and the
 * caller gets a 502 naming "matrix service unreachable," never a silently-
 * queued channel the running service doesn't know about.
 *
 * RBAC: reuses action.manage_matrix (the SAME permission api/matrix.php
 * gates on) rather than minting a new code — this is squarely audio-matrix
 * administration, and this project has a well-documented history of RBAC
 * exclusion-list leaks from new permission codes; reusing an existing,
 * already-correctly-scoped one avoids adding a new surface for that bug
 * class. Deliberately NOT `|| is_admin()` — is_admin()'s action.manage_
 * config fallback is the wrong idiom the moment a permission is meant to
 * travel narrower than every admin-tier grant (this project's own Phase
 * 138 lesson); rbac_can()'s own is_super short-circuit already covers
 * every real Super Admin.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/http-stream-channels.php';
require_once __DIR__ . '/../inc/matrix-control-client.php';

if (!rbac_can('action.manage_matrix')) {
    json_error('Insufficient permissions: manage audio matrix', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

function hsc_read_json_body(): array {
    $input = json_decode((string) file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function hsc_csrf_check(array $input): void {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid or expired security token. Please refresh the page.', 403);
    }
}

if ($method === 'GET') {

    if ($action === 'list') {
        try {
            json_response(['channels' => http_stream_channel_list()]);
        } catch (Exception $e) {
            error_log('[http-stream-channels list] ' . $e->getMessage());
            json_error('list query failed', 500);
        }
        exit;
    }

    if ($action === 'get') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { json_error('id required'); }
        $row = http_stream_channel_get($id);
        if (!$row) { json_error('not found', 404); }
        json_response(['channel' => $row]);
        exit;
    }

    json_error('Unknown action', 404);
}

if ($method === 'POST') {

    $input = hsc_read_json_body();

    if ($action === 'create') {
        hsc_csrf_check($input);
        $channelKey = (string) ($input['channel_key'] ?? '');
        $label      = (string) ($input['label'] ?? '');
        $regClass   = (string) ($input['regulatory_class'] ?? 'internal');
        $url        = (string) ($input['url'] ?? '');

        try {
            $id = http_stream_channel_create($channelKey, $label, $regClass, $url);
            $row = http_stream_channel_get($id);

            $applied = matrix_control_apply_http_stream_create(
                $row['channel_key'], $row['label'], $row['regulatory_class'], $row['url']
            );
            if (!$applied['ok']) {
                http_stream_channel_delete($id);   // roll back — never a silently-queued channel
                json_error('Matrix service unreachable — stream channel was NOT created. '
                    . $applied['error'], 502);
            }

            audit_log('config', 'http_stream.create', 'comm_channels', $id,
                "Public audio-stream channel created: '{$row['label']}' ({$row['channel_key']}) -> {$row['url']}",
                $row);
            json_response(['ok' => true, 'id' => $id, 'channel' => $row]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 409);
        } catch (Exception $e) {
            error_log('[http-stream-channels create] ' . $e->getMessage());
            json_error('Failed to create stream channel', 500);
        }
        exit;
    }

    if ($action === 'update') {
        hsc_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) { json_error('id required'); }
        try {
            $existing = http_stream_channel_get($id);
            if (!$existing) { json_error('not found', 404); }

            $label    = isset($input['label']) ? (string) $input['label'] : $existing['label'];
            $regClass = isset($input['regulatory_class']) ? (string) $input['regulatory_class'] : $existing['regulatory_class'];
            $url      = isset($input['url']) ? (string) $input['url'] : $existing['url'];
            $enabled  = array_key_exists('enabled', $input) ? !empty($input['enabled']) : (bool) $existing['enabled'];

            $row = http_stream_channel_update($id, $label, $regClass, $url, $enabled);

            // Only re-apply to the live matrix when the channel is (still)
            // enabled -- disabling it live-removes the leg; the row itself
            // stays in the DB either way (matching every other adapter's
            // enabled=0 convention: present, configured, just not fetched).
            if ($enabled) {
                $applied = matrix_control_apply_http_stream_create(
                    $row['channel_key'], $row['label'], $row['regulatory_class'], $row['url']
                );
                if (!$applied['ok']) {
                    json_error('Matrix service unreachable — the update was saved but could NOT '
                        . 'be applied to the running service. ' . $applied['error']
                        . ' It will take effect on the next service restart.', 502);
                }
            } else {
                matrix_control_apply_http_stream_delete($row['channel_key']); // best-effort; row already saved disabled
            }

            audit_log('config', 'http_stream.update', 'comm_channels', $id,
                "Public audio-stream channel updated: '{$row['label']}' ({$row['channel_key']})",
                $row);
            json_response(['ok' => true, 'channel' => $row]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 404);
        } catch (Exception $e) {
            error_log('[http-stream-channels update] ' . $e->getMessage());
            json_error('Failed to update stream channel', 500);
        }
        exit;
    }

    if ($action === 'delete') {
        hsc_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) { json_error('id required'); }
        try {
            $row = http_stream_channel_delete($id);
            if (!$row) { json_error('not found', 404); }

            // Best-effort on delete: the DB row is already gone (there is
            // nothing left to roll back to), and a live service that's
            // unreachable right now will simply not have this channel to
            // begin with on its next restart either way -- unlike create/
            // update, a failed live-removal here can never leave a "silent"
            // channel the DB doesn't know about.
            matrix_control_apply_http_stream_delete($row['channel_key']);

            audit_log('config', 'http_stream.delete', 'comm_channels', $id,
                "Public audio-stream channel deleted: '{$row['label']}' ({$row['channel_key']})",
                $row);
            json_response(['ok' => true]);
        } catch (Exception $e) {
            error_log('[http-stream-channels delete] ' . $e->getMessage());
            json_error('Failed to delete stream channel', 500);
        }
        exit;
    }

    json_error('Unknown action', 404);
}

json_error('Method not allowed', 405);
