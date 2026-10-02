<?php
/**
 * Phase 153 (2026-09-08) — SIP extension admin endpoint.
 *
 * GET  ?action=extensions          — list (sip_password masked -- has_password boolean)
 * GET  ?action=extension&id=N      — single row (sip_password masked)
 * POST action=extension_create     — new extension; returns sip_username + sip_password ONCE
 * POST action=extension_update     — label/is_general/workstation_token/org_id/enabled
 * POST action=extension_delete     — hard delete
 * POST action=extension_rotate_password — mint a new password, returned ONCE
 *
 * RBAC: action.manage_calls, reused from Phase 149's sip-trunks admin
 * (same telephony domain) rather than a new permission code.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/phone-extensions.php';
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

function pe_require_perm(): void
{
    // Deliberately NOT `|| is_admin()` -- this project's own documented
    // Phase 138 lesson applies the same way it did for sip-trunks.php.
    if (!rbac_can('action.manage_calls')) {
        json_error('Insufficient permissions: manage inbound calls', 403);
    }
}

function pe_csrf_check(array $input): void
{
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid or expired security token. Please refresh the page.', 403);
    }
}

function pe_read_json_body(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function pe_mask(array $row): array
{
    $row['has_password'] = !empty($row['sip_password']);
    unset($row['sip_password']);
    return $row;
}

// my_extension: ANY logged-in operator's own browser asks "which extension
// (if any) is bound to me" -- gated only by auth.php's session check above,
// deliberately NOT action.manage_calls. The workstation_token is the same
// opaque per-browser secret console-workstation.js already generates; a
// browser that doesn't hold the real token for a bound workstation simply
// gets 'bound: false', same as one with no binding at all.
if ($method === 'GET' && $action === 'my_extension') {
    try {
        $token = (string) ($_GET['workstation_token'] ?? '');
        $ext = phone_extension_find_by_workstation_token($token);
        $pbx = phone_pbx_settings_get();
        $general = phone_extension_find_general();
        if (!$ext) {
            json_response([
                'bound' => false,
                'wss_url' => $pbx['wss_url'],
                'general_number' => $general ? $general['extension'] : $pbx['general_number'],
            ]);
        }
        json_response([
            'bound' => true,
            'extension' => $ext['extension'],
            'label' => $ext['label'],
            'sip_username' => $ext['sip_username'],
            'sip_password' => $ext['sip_password'],
            'wss_url' => $pbx['wss_url'],
            'general_number' => $general ? $general['extension'] : $pbx['general_number'],
        ]);
    } catch (Exception $e) {
        error_log('[phone-extensions my_extension] ' . $e->getMessage());
        json_error('lookup failed', 500);
    }
    exit;
}

pe_require_perm();

if ($method === 'GET') {

    if ($action === 'pbx_settings') {
        json_response(['settings' => phone_pbx_settings_get()]);
        exit;
    }

    if ($action === 'extensions') {
        try {
            $rows = array_map('pe_mask', phone_extension_list());
            json_response(['extensions' => $rows]);
        } catch (Exception $e) {
            error_log('[phone-extensions extensions] ' . $e->getMessage());
            json_error('list query failed', 500);
        }
        exit;
    }

    if ($action === 'extension') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        $row = phone_extension_get($id);
        if (!$row) json_error('not found', 404);
        json_response(['extension' => pe_mask($row)]);
        exit;
    }

    json_error('Unknown action', 404);
}

if ($method === 'POST') {

    $input = pe_read_json_body();

    if ($action === 'extension_create') {
        pe_csrf_check($input);
        try {
            $result = phone_extension_create(
                $input['extension'] ?? '',
                $input['label'] ?? '',
                !empty($input['is_general']),
                $input['workstation_token'] ?? '',
                $input['org_id'] ?? null
            );
            audit_log('comms', 'phone_extension.create', 'phone_extensions', $result['id'],
                "Created SIP extension '{$input['extension']}' (" . ($input['label'] ?? '') . ')');
            json_response([
                'extension_id' => $result['id'],
                'sip_username' => $result['sip_username'],
                'sip_password' => $result['sip_password'], // shown ONCE
                'note' => 'Configure the browser\'s phone widget with this username/password to '
                        . 'register against the Asterisk PBX. It will not be shown again -- rotate '
                        . 'it from this panel if it is lost.',
            ]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 409);
        } catch (Exception $e) {
            error_log('[phone-extensions extension_create] ' . $e->getMessage());
            json_error('Failed to create extension', 500);
        }
        exit;
    }

    if ($action === 'extension_update') {
        pe_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        try {
            $existing = phone_extension_get($id);
            if (!$existing) json_error('not found', 404);
            $row = phone_extension_update(
                $id,
                $input['label'] ?? $existing['label'],
                array_key_exists('is_general', $input) ? !empty($input['is_general']) : (bool) $existing['is_general'],
                array_key_exists('workstation_token', $input) ? $input['workstation_token'] : $existing['workstation_token'],
                array_key_exists('org_id', $input) ? $input['org_id'] : $existing['org_id'],
                array_key_exists('enabled', $input) ? !empty($input['enabled']) : (bool) $existing['enabled']
            );
            audit_log('comms', 'phone_extension.update', 'phone_extensions', $id,
                "Updated SIP extension '{$row['extension']}'");
            json_response(['ok' => true, 'extension' => pe_mask($row)]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 404);
        } catch (Exception $e) {
            error_log('[phone-extensions extension_update] ' . $e->getMessage());
            json_error('Failed to update extension', 500);
        }
        exit;
    }

    if ($action === 'extension_rotate_password') {
        pe_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        try {
            $existing = phone_extension_get($id);
            if (!$existing) json_error('not found', 404);
            $newPassword = phone_extension_rotate_password($id);
            audit_log('comms', 'phone_extension.rotate', 'phone_extensions', $id,
                "Rotated SIP password for extension '{$existing['extension']}'");
            json_response([
                'extension_id' => $id,
                'sip_password' => $newPassword, // shown ONCE
                'note' => 'Update the browser phone widget\'s stored credential immediately -- the '
                        . 'old password stops working the moment this is saved.',
            ]);
        } catch (Exception $e) {
            error_log('[phone-extensions extension_rotate_password] ' . $e->getMessage());
            json_error('Failed to rotate password', 500);
        }
        exit;
    }

    if ($action === 'pbx_settings_save') {
        pe_csrf_check($input);
        try {
            $saved = phone_pbx_settings_save($input['wss_url'] ?? '', $input['general_number'] ?? '');
            audit_log('comms', 'phone_pbx_settings.update', 'settings', 0, 'Updated PBX connection settings');
            json_response(['ok' => true, 'settings' => $saved]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (Exception $e) {
            error_log('[phone-extensions pbx_settings_save] ' . $e->getMessage());
            json_error('Failed to save PBX settings', 500);
        }
        exit;
    }

    if ($action === 'extension_delete') {
        pe_csrf_check($input);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        try {
            $row = phone_extension_delete($id);
            if (!$row) json_error('not found', 404);
            audit_log('comms', 'phone_extension.delete', 'phone_extensions', $id,
                "Deleted SIP extension '{$row['extension']}'");
            json_response(['ok' => true]);
        } catch (Exception $e) {
            error_log('[phone-extensions extension_delete] ' . $e->getMessage());
            json_error('Failed to delete extension', 500);
        }
        exit;
    }

    json_error('Unknown action', 404);
}

json_error('Method not allowed', 405);
