<?php
/**
 * NewUI v4.0 API - Agency logo and branding: admin (GH#142, Phase 155).
 *
 * Owns ALL branding WRITES. Two RBAC tiers split by blast radius (the Phase
 * 138/140 template):
 *
 *   action.manage_branding      - install-wide. Super Admin only (tier 2).
 *       The install-wide logo, ANY organization's logo, the eleven settings.
 *   action.manage_branding_org  - org-scoped self-service. Super Admin + Org
 *       Admin (tier 1). The caller's OWN organization's logo only; the
 *       organization is resolved server-side by
 *       branding_resolve_caller_org_id() and FORCED, never taken from the
 *       request. A client naming a different organization is a 403.
 *
 * GET  ?action=status                        (either permission)
 * POST action=upload_logo                    (multipart: scope, org_id, variant,
 *                                             logo, csrf_token)
 * POST action=delete_logo                    (JSON: scope, org_id, variant)
 * POST action=save_settings                  (JSON: settings{}; install-wide only)
 *
 * Every write verifies CSRF, re-derives its authority from the session's own
 * grants (a delete takes a scope, not a row id, so there is no id-based IDOR),
 * writes one audit_log() entry in the same request (never image bytes in the
 * payload) and never touches the filesystem: the image is validated, re-encoded
 * and stored in the database by inc/branding.php.
 *
 * A gate here is `rbac_can('action.manage_branding')` and
 * `rbac_can('action.manage_branding_org')` ALONE, never `|| is_admin()`:
 * is_admin()'s own fallback is action.manage_config, which a correctly-scoped
 * Org Admin can end up holding, and that would hand them the install-wide
 * control this split exists to withhold (see api/public-board-admin.php).
 *
 * A new logo takes effect on the next page load (its serving URL changes with
 * it), so there is deliberately no SSE event here.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/branding.php';

ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $ctype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ctype, 'multipart/form-data') === 0) {
        // A multipart body larger than post_max_size arrives with $_POST and
        // $_FILES both empty. Say so, instead of letting it surface as a
        // misleading "Invalid CSRF token".
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            json_error('The upload is larger than this server allows (post_max_size). Use a smaller image.', 413);
        }
        $input  = $_POST;
        $action = (string) ($input['action'] ?? $action);
    } else {
        $input  = json_decode((string) file_get_contents('php://input'), true);
        $input  = is_array($input) ? $input : [];
        $action = (string) ($input['action'] ?? $action);
    }
}

$isBrandAdmin = rbac_can('action.manage_branding');
$isOrgSelf    = rbac_can('action.manage_branding_org');
if (!$isBrandAdmin && !$isOrgSelf) {
    json_error('Insufficient permissions: manage branding', 403);
}

$callerUserId = (int) ($_SESSION['user_id'] ?? 0);
$callerOrgId  = $isOrgSelf ? branding_resolve_caller_org_id($callerUserId) : 0;

/** CSRF on every write. */
function _branding_require_csrf(array $input): void
{
    $token = (string) ($input['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

/** A write against an install that has not run the migration yet. */
function _branding_require_schema(): void
{
    if (!branding_table_exists()) {
        json_error('The branding tables are missing on this install. Run: php sql/run_migrations.php', 409);
    }
}

/** Does an organization with this id exist? */
function _branding_org_exists(int $orgId): bool
{
    try {
        return (bool) db_fetch_value("SELECT 1 FROM " . db_table('organizations') . " WHERE `id` = ?", [$orgId]);
    } catch (Throwable $e) {
        return false;
    }
}

/** Human label for an audit summary. */
function _branding_scope_label(int $orgId): string
{
    return $orgId === 0 ? 'install-wide' : ('organization #' . $orgId);
}

/**
 * Validate the (scope, org_id, variant) a write names and decide whether the
 * caller may write it. Returns [orgId, variant]; exits with the right error
 * otherwise. The ONE place a write's authority is derived.
 */
function _branding_authorize_target(array $input, bool $isBrandAdmin, bool $isOrgSelf, int $callerOrgId): array
{
    $scope   = (string) ($input['scope'] ?? '');
    $variant = (string) ($input['variant'] ?? 'light');
    if (!branding_valid_variant($variant)) {
        json_error('Invalid variant.', 400);
    }
    $requested = isset($input['org_id']) && $input['org_id'] !== '' ? (int) $input['org_id'] : null;
    $t = branding_resolve_write_target($isBrandAdmin, $isOrgSelf, $callerOrgId, $scope, $requested);
    if (!$t['ok']) {
        json_error((string) $t['error'], (int) $t['status']);
    }
    $orgId = (int) $t['org_id'];
    if ($scope === 'org') {
        if (!_branding_org_exists($orgId)) {
            json_error('Unknown organization.', 404);
        }
        // An org-scoped caller is writing something that would be ignored if
        // the Super Administrator has switched organization logos off.
        if (!$isBrandAdmin && branding_setting('branding_org_logos') !== '1') {
            json_error('Organization logos are switched off for this installation.', 403);
        }
    }
    return [$orgId, $variant];
}

// ═══════════════════════════════════════════════════════════════════════
//  GET status
// ═══════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    if ($action !== 'status') {
        json_error('Unknown action.', 400);
    }

    $caps = [
        'gd'               => branding_gd_available(),
        'accepted'         => branding_accepted_types(),
        'max_upload_bytes' => BRANDING_MAX_UPLOAD_BYTES,
        'max_stored_bytes' => BRANDING_MAX_STORED_BYTES,
        'max_edge'         => BRANDING_MAX_EDGE,
    ];

    if (!branding_table_exists()) {
        json_response([
            'schema_ready'  => false,
            'can_install'   => $isBrandAdmin,
            'can_org'       => $isOrgSelf,
            'caller_org_id' => $callerOrgId,
            'caps'          => $caps,
            'settings'      => branding_all_settings(),
            'install'       => ['light' => null, 'dark' => null],
            'orgs'          => [],
        ]);
    }

    // Logo metadata, grouped by scope. Never data_b64.
    $byOrg = [];
    foreach (branding_list_rows(null) as $r) {
        $byOrg[$r['org_id']][$r['variant']] = $r;
    }
    $install = ['light' => $byOrg[0]['light'] ?? null, 'dark' => $byOrg[0]['dark'] ?? null];
    // An organization-scoped caller sees the install-wide logo (it is public on the sign-in
    // screen anyway) but not WHO uploaded it: that is a Super Admin's username.
    if (!$isBrandAdmin) {
        foreach (['light', 'dark'] as $v) {
            if ($install[$v] !== null) {
                $install[$v]['uploaded_by_name'] = '';
            }
        }
    }

    $orgs = [];
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `name`, `parent_org_id`, `active` FROM " . db_table('organizations') . " ORDER BY `sort_order`, `name`"
        );
    } catch (Throwable $e) {
        $rows = [];
    }
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        // An organization-scoped caller sees only their own organization.
        if (!$isBrandAdmin && $id !== $callerOrgId) {
            continue;
        }
        // Where an organization with no logo of its own inherits one from.
        $inherits = '';
        if (!isset($byOrg[$id]['light'])) {
            $seen = [$id => true];
            $cur  = isset($byId[$id]['parent_org_id']) ? (int) $byId[$id]['parent_org_id'] : 0;
            for ($i = 0; $i < BRANDING_MAX_ORG_DEPTH && $cur > 0 && !isset($seen[$cur]) && isset($byId[$cur]); $i++) {
                if (isset($byOrg[$cur]['light'])) {
                    $inherits = (string) $byId[$cur]['name'];
                    break;
                }
                $seen[$cur] = true;
                $cur = (int) ($byId[$cur]['parent_org_id'] ?? 0);
            }
            if ($inherits === '' && $install['light'] !== null) {
                $inherits = 'Install-wide logo';
            }
        }
        $orgs[] = [
            'id'            => $id,
            'name'          => (string) $r['name'],
            'parent_org_id' => $r['parent_org_id'] === null ? null : (int) $r['parent_org_id'],
            'active'        => (int) $r['active'] === 1,
            'light'         => $byOrg[$id]['light'] ?? null,
            'dark'          => $byOrg[$id]['dark'] ?? null,
            'inherits'      => $inherits,
        ];
    }

    json_response([
        'schema_ready'  => true,
        'can_install'   => $isBrandAdmin,
        'can_org'       => $isOrgSelf,
        'caller_org_id' => $callerOrgId,
        'caps'          => $caps,
        'settings'      => branding_all_settings(),
        'install'       => $install,
        'orgs'          => $orgs,
    ]);
}

if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

// ═══════════════════════════════════════════════════════════════════════
//  POST upload_logo
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'upload_logo') {
    _branding_require_csrf($input);
    _branding_require_schema();
    [$orgId, $variant] = _branding_authorize_target($input, $isBrandAdmin, $isOrgSelf, $callerOrgId);

    $f = $_FILES['logo'] ?? null;
    if (!is_array($f) || is_array($f['tmp_name'] ?? null)) {
        json_error('No file was uploaded.', 400);
    }
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $msg = [
            UPLOAD_ERR_INI_SIZE   => 'The file is larger than this server allows (upload_max_filesize). Use a smaller image.',
            UPLOAD_ERR_FORM_SIZE  => 'The file is too large. Use a smaller image.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not receive the upload.',
            UPLOAD_ERR_EXTENSION  => 'An extension stopped the upload.',
        ][$err] ?? 'The upload failed.';
        json_error($msg, $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 413 : 400);
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    // The temporary path is PHP's own and is only ever READ here, then PHP
    // deletes it. Nothing from the client (file name, MIME type) is used.
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_error('The upload could not be verified.', 400);
    }
    $bytes = @file_get_contents($tmp, false, null, 0, BRANDING_MAX_UPLOAD_BYTES + 1);
    if (!is_string($bytes)) {
        json_error('The upload could not be read.', 400);
    }

    $p = branding_process_upload($bytes);
    if (empty($p['ok'])) {
        // A refused upload is logged so a probing attempt is visible. Medium
        // severity; the payload names the refusal and size, never the bytes.
        audit_log('config', 'update', 'branding_logo', null,
            'Refused logo upload (' . $p['code'] . ', ' . _branding_scope_label($orgId) . ')',
            ['org_id' => $orgId, 'variant' => $variant, 'refusal' => $p['code'], 'bytes' => strlen($bytes)],
            AUDIT_MEDIUM);
        json_error((string) $p['message'], 422);
    }

    $stored = branding_store_logo($orgId, $variant, $p, $callerUserId, (string) ($_SESSION['user'] ?? ''));
    if (empty($stored['ok'])) {
        $status = ($stored['code'] ?? '') === 'light_first' ? 409 : 400;
        json_error((string) $stored['message'], $status);
    }

    audit_log('config', 'update', 'branding_logo', $stored['id'],
        ($stored['replaced'] ? 'Replaced' : 'Uploaded') . ' logo (' . $variant . ', ' . _branding_scope_label($orgId) . ')',
        ['org_id' => $orgId, 'variant' => $variant, 'mime' => $p['mime'], 'bytes' => strlen($p['bytes']),
         'sha256' => $p['sha256'], 'width' => $p['width'], 'height' => $p['height']]);

    $meta = null;
    foreach (branding_list_rows($orgId) as $r) {
        if ($r['variant'] === $variant) {
            $meta = $r;
        }
    }
    json_response(['success' => true, 'logo' => $meta, 'warnings' => $p['warnings']]);
}

// ═══════════════════════════════════════════════════════════════════════
//  POST delete_logo
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'delete_logo') {
    _branding_require_csrf($input);
    _branding_require_schema();
    [$orgId, $variant] = _branding_authorize_target($input, $isBrandAdmin, $isOrgSelf, $callerOrgId);

    $res = branding_delete_logo($orgId, $variant);
    if (empty($res['ok'])) {
        json_error((string) $res['message'], 400);
    }
    if ($res['deleted'] > 0) {
        audit_log('config', 'delete', 'branding_logo', $res['ids'][0],
            'Removed logo (' . $variant . ', ' . _branding_scope_label($orgId) . ')',
            ['org_id' => $orgId, 'variant' => $variant, 'removed' => $res['removed']],
            AUDIT_LOW);
    }
    json_response(['success' => true, 'deleted' => $res['deleted']]);
}

// ═══════════════════════════════════════════════════════════════════════
//  POST save_settings (install-wide permission ONLY)
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'save_settings') {
    _branding_require_csrf($input);
    if (!$isBrandAdmin) {
        json_error('Forbidden: the branding settings need the Manage Branding permission.', 403);
    }
    _branding_require_schema();

    $incoming = $input['settings'] ?? null;
    if (!is_array($incoming) || $incoming === []) {
        json_error('No settings were sent.', 400);
    }
    $defs = branding_setting_definitions();
    $clean = [];
    foreach ($incoming as $name => $value) {
        if (!is_string($name) || !isset($defs[$name])) {
            json_error('Unknown setting.', 400);
        }
        if (!is_scalar($value)) {
            json_error('Invalid value for ' . $name . '.', 400);
        }
        $value = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);
        // The writer is STRICT where the reader is lenient: an invalid value is
        // a 400 naming the setting, not a silent fall-back to the default.
        if (branding_normalize_setting($name, $value) !== $value) {
            json_error('Invalid value for ' . $name . '.', 400);
        }
        $clean[$name] = $value;
    }

    $before = branding_all_settings();
    $changes = [];
    try {
        foreach ($clean as $name => $value) {
            db_query(
                "INSERT INTO " . db_table('settings') . " (`name`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$name, $value]
            );
            if (($before[$name] ?? '') !== $value) {
                $changes[$name] = ['old' => $before[$name] ?? '', 'new' => $value];
            }
        }
    } catch (Throwable $e) {
        json_error_safe('The settings could not be saved.', $e, 'branding.save_settings');
    }
    if ($changes) {
        audit_log('config', 'update', 'branding_settings', null,
            'Changed branding settings: ' . implode(', ', array_keys($changes)), ['changes' => $changes]);
    }
    $after = $before;
    foreach ($clean as $name => $value) {
        $after[$name] = branding_normalize_setting($name, $value);
    }
    json_response(['success' => true, 'settings' => $after]);
}

json_error('Unknown action.', 400);
