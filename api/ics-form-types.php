<?php
/**
 * NewUI v4.0 API — Custom ICS Form Types (Phase 140, GH#69).
 *
 * GET  ?list=1                  — types available to START a new form:
 *                                 status='active', in the caller's scope,
 *                                 and any restrict_to_permission satisfied.
 *                                 Failing types are omitted, never shown-disabled.
 * GET  ?list=1&manage=1         — types visible to the AUTHORING UI: every
 *                                 status, requires an authoring permission,
 *                                 includes instance_count.
 * GET  ?id=X                    — a single type. Delegates straight to
 *                                 ics_form_custom_template() (inc/ics-form-types.php)
 *                                 -- the SAME choke point api/ics-forms.php's
 *                                 getFormTemplate('custom', ...) branch uses,
 *                                 so this endpoint can never be a second,
 *                                 divergent copy of that access check.
 * POST action=create            — authoring gate; org_id forced server-side
 *                                 for an org-scoped-only author; slug immutable
 *                                 from here on; audit-logged.
 * POST action=update            — authoring gate scoped to the row's actual
 *                                 org_id; rejects a changed slug; audit-logged.
 * POST action=archive / restore — authoring gate scoped to the row's org;
 *                                 never blocked by instance count; audit-logged.
 *
 * No delete action in v1 (plan.md — archiving is always safe because every
 * saved instance renders from its own frozen _meta snapshot, never a fresh
 * lookup of this table).
 *
 * RBAC (plan.md "RBAC" section — no `|| is_admin()` on EITHER gate; see
 * api/public-board-admin.php's identical, already-documented reasoning for
 * why that fallback is a leak here, not a convenience):
 *   action.manage_ics_form_types      — install-wide. Super Admin only.
 *   action.manage_ics_form_types_org  — org-scoped self-service authoring.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/ics-form-types.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $action;
}

// See plan.md's RBAC section + api/public-board-admin.php's documented
// reasoning: deliberately `rbac_can(...)` alone, never `|| is_admin()`.
// rbac_can()'s own is_super short-circuit already covers every real Super
// Admin; is_admin()'s extra action.manage_config fallback only adds the
// exact cross-org leak this permission split exists to prevent.
$canAuthorGlobal = rbac_can('action.manage_ics_form_types');
$canAuthorOrgAny = rbac_can('action.manage_ics_form_types_org');

function _icsft_require_csrf(array $input): void {
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) json_error('Invalid CSRF token', 403);
}

/** Does the caller hold authoring rights over THIS SPECIFIC org (or global)? */
function _icsft_can_author_org(bool $canAuthorGlobal, ?int $orgId): bool {
    if ($canAuthorGlobal) return true;
    if ($orgId === null) return false; // install-wide rows are global-author-only to edit
    return rbac_can('action.manage_ics_form_types_org', ['org_id' => $orgId]);
}

if (!ics_forms_has_custom_type_columns()) {
    json_error('Custom ICS form types are not available on this install yet '
        . '-- run sql/run_phase140_custom_ics_form_types.php.', 503);
}

// ═══════════════════════════════════════════════════════════════════════
//  GET — reads
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'GET' && isset($_GET['list'])) {
    $manage = !empty($_GET['manage']);

    if ($manage) {
        if (!$canAuthorGlobal && !$canAuthorOrgAny) {
            json_error('Insufficient permissions: manage custom ICS form types', 403);
        }
        try {
            // Super Admin / global author sees every type. An org-scoped-
            // only author sees install-wide types (read-only reference)
            // plus their OWN org's types (every status). Never another
            // org's types -- authoring visibility is scope-bound exactly
            // like write access.
            if ($canAuthorGlobal) {
                $rows = db_fetch_all(
                    "SELECT t.*, o.name AS org_name,
                            (SELECT COUNT(*) FROM `{$prefix}ics_forms` f WHERE f.custom_type_id = t.id) AS instance_count
                       FROM `{$prefix}ics_form_types` t
                       LEFT JOIN `{$prefix}organizations` o ON o.id = t.org_id
                      ORDER BY t.org_id IS NULL DESC, t.form_title ASC"
                );
            } else {
                $callerOrgId = ics_form_types_resolve_caller_org_id((int) ($_SESSION['user_id'] ?? 0));
                $rows = db_fetch_all(
                    "SELECT t.*, o.name AS org_name,
                            (SELECT COUNT(*) FROM `{$prefix}ics_forms` f WHERE f.custom_type_id = t.id) AS instance_count
                       FROM `{$prefix}ics_form_types` t
                       LEFT JOIN `{$prefix}organizations` o ON o.id = t.org_id
                      WHERE t.org_id IS NULL OR t.org_id = ?
                      ORDER BY t.org_id IS NULL DESC, t.form_title ASC",
                    [$callerOrgId]
                );
            }
            $out = array_map('_icsft_row_out', $rows);
            json_response(['types' => $out]);
        } catch (Throwable $e) {
            json_error_safe('Failed to load form types.', $e, 'ics_form_types.list_manage');
        }
    } else {
        // Start-new-form list: active only, in scope, restrict_to_permission
        // satisfied. Failing types are omitted entirely -- no enumeration
        // signal, no disabled-but-visible entries.
        try {
            $callerOrgId = (int) ($_SESSION['active_org_id'] ?? 0);
            if ($callerOrgId <= 0) {
                $userId = (int) ($_SESSION['user_id'] ?? 0);
                if ($userId > 0 && function_exists('org_user_home_id')) {
                    $callerOrgId = org_user_home_id($userId);
                }
            }
            $rows = db_fetch_all(
                "SELECT * FROM `{$prefix}ics_form_types`
                  WHERE `status` = 'active' AND (`org_id` IS NULL OR `org_id` = ?)
                  ORDER BY `org_id` IS NULL DESC, `form_title` ASC",
                [$callerOrgId]
            );
            $out = [];
            foreach ($rows as $row) {
                $restrictTo = (string) ($row['restrict_to_permission'] ?? '');
                if ($restrictTo !== '' && !rbac_can($restrictTo)) continue;
                $out[] = [
                    'id'           => (int) $row['id'],
                    'slug'         => $row['slug'],
                    'form_number'  => $row['form_number'],
                    'form_title'   => $row['form_title'],
                    'description'  => $row['description'],
                    'badge_color'  => $row['badge_color'],
                    'icon'         => $row['icon'],
                ];
            }
            json_response(['types' => $out]);
        } catch (Throwable $e) {
            json_error_safe('Failed to load form types.', $e, 'ics_form_types.list');
        }
    }
}

if ($method === 'GET' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $tpl = ics_form_custom_template($id);
    if (!$tpl) json_error('Form type not found', 404);
    json_response(['type' => $tpl]);
}

if ($method === 'GET') {
    json_error('Unknown action');
}

// ═══════════════════════════════════════════════════════════════════════
//  POST — writes
// ═══════════════════════════════════════════════════════════════════════

if ($method === 'POST') {
    if (!$canAuthorGlobal && !$canAuthorOrgAny) {
        json_error('Insufficient permissions: manage custom ICS form types', 403);
    }
    _icsft_require_csrf($input);

    if ($action === 'create') {
        $requestedOrgId = array_key_exists('org_id', $input) && $input['org_id'] !== null && $input['org_id'] !== ''
            ? (int) $input['org_id'] : null;
        $callerOrgId = ics_form_types_resolve_caller_org_id((int) ($_SESSION['user_id'] ?? 0));
        $decision = ics_form_types_resolve_create_org($canAuthorGlobal, $canAuthorOrgAny, $callerOrgId, $requestedOrgId);
        if (!$decision['ok']) json_error($decision['error'], $decision['status']);
        $targetOrgId = $decision['org_id'];

        $meta = [
            'slug'                   => trim((string) ($input['slug'] ?? '')),
            'form_number'            => (string) ($input['form_number'] ?? ''),
            'form_title'             => (string) ($input['form_title'] ?? ''),
            'description'            => (string) ($input['description'] ?? ''),
            'badge_color'            => (string) ($input['badge_color'] ?? 'secondary'),
            'icon'                   => (string) ($input['icon'] ?? 'bi-file-earmark-text'),
            'restrict_to_permission' => $input['restrict_to_permission'] ?? null,
        ];
        $metaCheck = ics_form_type_validate_metadata($meta, null);
        if (!$metaCheck['valid']) json_error(implode(' ', $metaCheck['errors']));

        $fields = $input['fields'] ?? [];
        $fieldsCheck = ics_form_type_validate_fields($fields);
        if (!$fieldsCheck['valid']) json_error(implode(' ', $fieldsCheck['errors']));

        $userName = $_SESSION['user'] ?? '';
        try {
            $dup = db_fetch_value(
                "SELECT 1 FROM `{$prefix}ics_form_types` WHERE `slug` = ? AND `org_key` = COALESCE(?, -1) LIMIT 1",
                [$meta['slug'], $targetOrgId]
            );
            if ($dup) json_error('A form type with that slug already exists in this scope.', 409);

            db_query(
                "INSERT INTO `{$prefix}ics_form_types`
                 (`slug`, `form_number`, `form_title`, `description`, `fields_json`,
                  `badge_color`, `icon`, `org_id`, `status`, `restrict_to_permission`,
                  `created_by`, `created_by_name`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)",
                [
                    $meta['slug'], $meta['form_number'], $meta['form_title'], $meta['description'],
                    json_encode($fields, JSON_UNESCAPED_UNICODE),
                    $meta['badge_color'], $meta['icon'], $targetOrgId,
                    $meta['restrict_to_permission'] ?: null,
                    (int) ($_SESSION['user_id'] ?? 0), $userName,
                ]
            );
            $newId = (int) db_insert_id();

            audit_log('config', 'create', 'ics_form_types', $newId,
                'Created custom ICS form type "' . $meta['form_title'] . '" (' . $meta['slug'] . ')',
                ['slug' => $meta['slug'], 'org_id' => $targetOrgId], AUDIT_MEDIUM);

            json_response(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            json_error_safe('Failed to create form type.', $e, 'ics_form_types.create');
        }
    }

    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id is required');

        try {
            $existing = db_fetch_one("SELECT * FROM `{$prefix}ics_form_types` WHERE `id` = ?", [$id]);
        } catch (Throwable $e) {
            json_error_safe('Failed to load form type.', $e, 'ics_form_types.update.load');
        }
        if (!$existing) json_error('Form type not found', 404);

        $existingOrgId = $existing['org_id'] !== null ? (int) $existing['org_id'] : null;
        if (!_icsft_can_author_org($canAuthorGlobal, $existingOrgId)) {
            json_error('Form type not found', 404); // no enumeration signal
        }

        $meta = [
            'slug'                   => trim((string) ($input['slug'] ?? $existing['slug'])),
            'form_number'            => (string) ($input['form_number'] ?? ''),
            'form_title'             => (string) ($input['form_title'] ?? ''),
            'description'            => (string) ($input['description'] ?? ''),
            'badge_color'            => (string) ($input['badge_color'] ?? 'secondary'),
            'icon'                   => (string) ($input['icon'] ?? 'bi-file-earmark-text'),
            'restrict_to_permission' => $input['restrict_to_permission'] ?? null,
        ];
        $metaCheck = ics_form_type_validate_metadata($meta, $existing['slug']);
        if (!$metaCheck['valid']) json_error(implode(' ', $metaCheck['errors']));

        $fields = $input['fields'] ?? [];
        $fieldsCheck = ics_form_type_validate_fields($fields);
        if (!$fieldsCheck['valid']) json_error(implode(' ', $fieldsCheck['errors']));

        try {
            db_query(
                "UPDATE `{$prefix}ics_form_types`
                    SET `form_number` = ?, `form_title` = ?, `description` = ?, `fields_json` = ?,
                        `badge_color` = ?, `icon` = ?, `restrict_to_permission` = ?
                  WHERE `id` = ?",
                [
                    $meta['form_number'], $meta['form_title'], $meta['description'],
                    json_encode($fields, JSON_UNESCAPED_UNICODE),
                    $meta['badge_color'], $meta['icon'], $meta['restrict_to_permission'] ?: null, $id,
                ]
            );
            audit_log('config', 'update', 'ics_form_types', $id,
                'Updated custom ICS form type "' . $meta['form_title'] . '" (' . $existing['slug'] . ')',
                ['slug' => $existing['slug'], 'org_id' => $existingOrgId], AUDIT_MEDIUM);
            json_response(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            json_error_safe('Failed to update form type.', $e, 'ics_form_types.update');
        }
    }

    if ($action === 'archive' || $action === 'restore') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id is required');

        try {
            $existing = db_fetch_one("SELECT `id`, `org_id`, `status`, `slug`, `form_title` FROM `{$prefix}ics_form_types` WHERE `id` = ?", [$id]);
        } catch (Throwable $e) {
            json_error_safe('Failed to load form type.', $e, 'ics_form_types.archive.load');
        }
        if (!$existing) json_error('Form type not found', 404);

        $existingOrgId = $existing['org_id'] !== null ? (int) $existing['org_id'] : null;
        if (!_icsft_can_author_org($canAuthorGlobal, $existingOrgId)) {
            json_error('Form type not found', 404);
        }

        $newStatus = $action === 'archive' ? 'archived' : 'active';
        try {
            db_query("UPDATE `{$prefix}ics_form_types` SET `status` = ? WHERE `id` = ?", [$newStatus, $id]);
            audit_log('config', $action, 'ics_form_types', $id,
                ucfirst($action) . 'd custom ICS form type "' . $existing['form_title'] . '" (' . $existing['slug'] . ')',
                ['slug' => $existing['slug'], 'org_id' => $existingOrgId], AUDIT_MEDIUM);
            json_response(['ok' => true, 'id' => $id, 'status' => $newStatus]);
        } catch (Throwable $e) {
            json_error_safe('Failed to ' . $action . ' form type.', $e, 'ics_form_types.' . $action);
        }
    }

    json_error('Unknown action: ' . $action);
}

json_error('Method not allowed', 405);

// ═══════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════

function _icsft_row_out(array $row): array {
    $fields = json_decode((string) $row['fields_json'], true);
    return [
        'id'                     => (int) $row['id'],
        'slug'                   => $row['slug'],
        'form_number'            => $row['form_number'],
        'form_title'             => $row['form_title'],
        'description'            => $row['description'],
        'fields'                 => is_array($fields) ? $fields : [],
        'badge_color'            => $row['badge_color'],
        'icon'                   => $row['icon'],
        'org_id'                 => $row['org_id'] !== null ? (int) $row['org_id'] : null,
        'org_name'               => $row['org_name'] ?? null,
        'status'                 => $row['status'],
        'restrict_to_permission' => $row['restrict_to_permission'],
        'created_by_name'        => $row['created_by_name'],
        'created_at'             => $row['created_at'],
        'updated_at'             => $row['updated_at'],
        'instance_count'         => (int) ($row['instance_count'] ?? 0),
    ];
}
