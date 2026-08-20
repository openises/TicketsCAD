<?php
/**
 * Phase 140 (2026-08-16) — Custom (data-driven) ICS Form Types, GH#69.
 *
 * Pure, testable logic for agency-authored ICS form type definitions. This
 * file never touches the nine existing hardcoded ICS form types' code
 * paths -- api/ics-forms.php gains exactly one new-branch call into this
 * file per touch point (getFormTemplate, generatePrintHtml, ics_form_label,
 * the action=save handler). See specs/phase-140-custom-ics-form-types/plan.md.
 *
 * The `_meta` snapshot (versioning, denormalization, and orphan-safety in
 * one mechanism): on a custom form's first successful save, the caller
 * (api/ics-forms.php) freezes a snapshot of this type's fields into the
 * instance's own form_data_json._meta. Every later read of that instance
 * -- editor re-open, print, PDF -- renders from _meta, NEVER a fresh
 * ics_form_types lookup. That is why ics_form_custom_print_html() below
 * takes the already-decoded instance $data (with its _meta) and does not
 * query the database at all. ics_form_custom_template() (the DB-backed
 * lookup) is only ever needed to start a NEW instance, or to build _meta
 * on that instance's first save.
 */

require_once __DIR__ . '/rbac.php';

define('ICS_FORM_TYPE_MAX_SIMPLE_FIELDS', 40);
define('ICS_FORM_TYPE_MAX_TABLE_FIELDS', 3);
define('ICS_FORM_TYPE_MAX_TABLE_COLUMNS', 12);
define('ICS_FORM_TYPE_MAX_TABLE_ROWS', 200);

/** The nine built-in type codes, plus 'custom' itself -- a custom slug may never collide with one. */
function ics_form_type_reserved_slugs(): array {
    return ['213', '214', '202', '205', '205a', '213rr', '206', '214a', '221', 'custom'];
}

/** Field/column keys a custom definition may never use -- JS prototype-pollution-adjacent words. */
function ics_form_type_reserved_keys(): array {
    return ['_meta', 'constructor', 'prototype', '__proto__', 'tostring', 'valueof'];
}

/**
 * The acting user's own org id for FORCING on a new org-scoped type
 * (never trusted from the request body). Mirrors Phase 138's
 * pb_resolve_caller_org_id() exactly: resolves via the specific
 * action.manage_ics_form_types_org grant, not via
 * $_SESSION['active_org_id'] -- a multi-org account's "currently active"
 * org need not be the one their org-scoped AUTHORING grant is actually
 * scoped to, and a create must land in the org the grant names, not
 * wherever the session happens to be pointed.
 *
 * Returns 0 when the caller has no matching org-scoped grant (no grant at
 * all, only a global grant, or more than one distinct org) -- callers
 * turn 0 into a 403 "No organization on this account" for a caller who
 * only holds the org-scoped permission, since global authors pass their
 * own org_id (including null) explicitly.
 */
function ics_form_types_resolve_caller_org_id(int $userId): int {
    if ($userId <= 0) return 0;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT ur.scope_id
               FROM `{$prefix}user_roles` ur
               JOIN `{$prefix}role_permissions` rp ON rp.role_id = ur.role_id
               JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
              WHERE ur.user_id = ?
                AND ur.scope_kind = 'org'
                AND ur.scope_id IS NOT NULL
                AND p.code = 'action.manage_ics_form_types_org'
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            [$userId]
        );
    } catch (Throwable $e) {
        error_log('[ics_form_types_resolve_caller_org_id] grant lookup failed: ' . $e->getMessage());
        return 0;
    }

    if (count($rows) === 1) {
        return (int) $rows[0]['scope_id'];
    }
    return 0;
}

/**
 * Resolve which org a CREATE should target, mirroring Phase 138's
 * pb_resolve_admin_write_org() exactly (inc/public-board.php):
 *   - Global author (action.manage_ics_form_types): org id comes from the
 *     request as-is, including null/0 for an install-wide type.
 *   - Org-scoped-only author: org id is FORCED from their own resolved
 *     grant, never trusted from the client. A client-supplied org_id that
 *     disagrees with the caller's own is rejected outright (evidence of a
 *     crafted request) rather than silently overridden.
 *   - Neither: forbidden.
 */
function ics_form_types_resolve_create_org(bool $canAuthorGlobal, bool $canAuthorOrg, int $callerOrgId, ?int $requestedOrgId): array {
    if ($canAuthorGlobal) {
        $target = ($requestedOrgId !== null && $requestedOrgId > 0) ? $requestedOrgId : null;
        return ['ok' => true, 'org_id' => $target, 'error' => null, 'status' => 200];
    }

    if ($canAuthorOrg) {
        if ($callerOrgId <= 0) {
            return ['ok' => false, 'org_id' => null, 'error' => 'No organization on this account', 'status' => 403];
        }
        if ($requestedOrgId !== null && $requestedOrgId > 0 && $requestedOrgId !== $callerOrgId) {
            return ['ok' => false, 'org_id' => null, 'error' => 'Forbidden: cannot create a type for another organization', 'status' => 403];
        }
        return ['ok' => true, 'org_id' => $callerOrgId, 'error' => null, 'status' => 200];
    }

    return ['ok' => false, 'org_id' => null, 'error' => 'Forbidden', 'status' => 403];
}

/**
 * Detector for the Phase 140 schema, mirroring the existing
 * ics_forms_has_soft_delete() pattern in inc/ics-forms-write.php.
 */
function ics_forms_has_custom_type_columns(bool $forceReload = false): bool {
    static $cached = null;
    if ($forceReload) $cached = null;
    if ($cached !== null) return $cached;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $n = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND COLUMN_NAME = 'custom_type_id'",
            [$prefix . 'ics_forms']
        );
        return $cached = ($n === 1);
    } catch (Throwable $e) {
        error_log('[ics_forms_has_custom_type_columns] ' . $e->getMessage());
        return $cached = false;
    }
}

/**
 * Narrow impersonation guard. Deliberately NOT a broad word-blocklist (a
 * "FEMA"/"Winlink" blocklist would false-positive on real agency paperwork
 * like "FEMA Reimbursement Worksheet") -- only exact reserved-slug matches
 * and an official-looking form_number are rejected.
 */
function ics_form_type_check_impersonation(string $slug, string $formNumber): array {
    $slug = strtolower(trim($slug));
    if (in_array($slug, ics_form_type_reserved_slugs(), true)) {
        return ['valid' => false, 'error' => "Slug '$slug' is reserved for a built-in ICS form type."];
    }
    if (preg_match('/^ICS-?\d/i', trim($formNumber))) {
        return ['valid' => false, 'error' => 'Form number cannot impersonate an official ICS form number (e.g. "ICS-213").'];
    }
    return ['valid' => true, 'error' => null];
}

/** Lenient integer-in-range check -- accepts int, numeric string, or whole-number float from JSON. */
function _ics_ft_int_in_range($v, int $min, int $max): bool {
    if (!is_numeric($v)) return false;
    $f = (float) $v;
    if ($f != (int) $f) return false;
    $i = (int) $f;
    return $i >= $min && $i <= $max;
}

/**
 * Validate a select/table-column option list: array of strings, or array
 * of {label, value} objects. Returns an error string, or null if valid.
 */
function ics_form_type_validate_options($options): ?string {
    if (!is_array($options) || empty($options)) {
        return 'must define at least one option';
    }
    if (count($options) > 50) {
        return 'may define at most 50 options';
    }
    foreach ($options as $opt) {
        $s = is_array($opt) ? (string) ($opt['label'] ?? $opt['value'] ?? '') : (string) $opt;
        if (trim($s) === '') {
            return 'options cannot be blank';
        }
        if (mb_strlen($s) > 80) {
            return 'each option must be 80 characters or fewer';
        }
    }
    return null;
}

/**
 * Validate a type definition's metadata (everything except fields_json).
 * $existingSlug: pass the row's current slug on an update so a changed
 * slug is caught (slugs are immutable after creation); pass null on create.
 */
function ics_form_type_validate_metadata(array $data, ?string $existingSlug = null): array {
    $errors = [];

    $slug = (string) ($data['slug'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_-]{2,59}$/', $slug)) {
        $errors[] = 'Slug must start with a lowercase letter and contain only lowercase letters, digits, underscore, or hyphen (3-60 characters total).';
    } elseif ($existingSlug !== null && $slug !== $existingSlug) {
        $errors[] = 'Slug cannot be changed after a type is created.';
    }

    $formTitle = trim((string) ($data['form_title'] ?? ''));
    if ($formTitle === '') {
        $errors[] = 'Form title is required.';
    } elseif (mb_strlen($formTitle) > 255) {
        $errors[] = 'Form title must be 255 characters or fewer.';
    }

    $formNumber = (string) ($data['form_number'] ?? '');
    if (mb_strlen($formNumber) > 40) {
        $errors[] = 'Form number must be 40 characters or fewer.';
    }

    if (mb_strlen((string) ($data['description'] ?? '')) > 500) {
        $errors[] = 'Description must be 500 characters or fewer.';
    }

    $badgeColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'];
    $badgeColor = (string) ($data['badge_color'] ?? 'secondary');
    if (!in_array($badgeColor, $badgeColors, true)) {
        $errors[] = 'Badge color must be one of: ' . implode(', ', $badgeColors) . '.';
    }

    $icon = (string) ($data['icon'] ?? 'bi-file-earmark-text');
    if (!preg_match('/^bi-[a-z0-9-]+$/', $icon)) {
        $errors[] = 'Icon must be a Bootstrap Icons class name matching ^bi-[a-z0-9-]+$ (e.g. "bi-file-earmark-text").';
    }

    $restrictTo = $data['restrict_to_permission'] ?? null;
    if ($restrictTo !== null && $restrictTo !== '') {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $exists = (bool) db_fetch_value(
            "SELECT 1 FROM `{$prefix}permissions` WHERE `code` = ?",
            [(string) $restrictTo]
        );
        if (!$exists) {
            $errors[] = 'restrict_to_permission must reference an existing permission code.';
        }
    }

    if ($slug !== '') {
        $imp = ics_form_type_check_impersonation($slug, $formNumber);
        if (!$imp['valid']) $errors[] = $imp['error'];
    }

    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Validate a field-definition list against the full Phase 1 palette --
 * caps, key format/uniqueness, the reserved-word denylist, and every
 * type-specific structural rule. Unknown top-level field properties are a
 * hard error, never silently stripped (plan.md's explicit requirement).
 */
function ics_form_type_validate_fields($fields): array {
    if (!is_array($fields) || empty($fields)) {
        return ['valid' => false, 'errors' => ['At least one field is required.']];
    }

    $simpleTypes = ['text', 'textarea', 'number', 'date', 'time', 'datetime-local', 'select', 'checkbox', 'section_header'];
    $columnTypes = ['text', 'number', 'date', 'time', 'select'];
    $allowedTop  = ['key', 'label', 'type', 'required', 'rows', 'min', 'max', 'step', 'options', 'columns', 'default_rows', 'max_rows', 'width'];
    $reserved    = ics_form_type_reserved_keys();

    $errors = [];
    $simpleCount = 0;
    $tableCount = 0;
    $seenKeys = [];

    foreach ($fields as $i => $f) {
        if (!is_array($f)) {
            $errors[] = "Field #$i is not a valid object.";
            continue;
        }

        foreach (array_keys($f) as $k) {
            if (!in_array($k, $allowedTop, true)) {
                $errors[] = "Field #$i has an unknown property '$k'.";
            }
        }

        $key = (string) ($f['key'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            $errors[] = "Field #$i key '$key' is invalid -- must start with a lowercase letter and contain only lowercase letters, digits, or underscore (max 64 chars).";
        } elseif (in_array(strtolower($key), $reserved, true)) {
            $errors[] = "Field #$i key '$key' is a reserved word and cannot be used.";
        } elseif (isset($seenKeys[$key])) {
            $errors[] = "Field #$i key '$key' duplicates an earlier field's key.";
        } else {
            $seenKeys[$key] = true;
        }

        $label = trim((string) ($f['label'] ?? ''));
        if ($label === '') {
            $errors[] = "Field #$i (key '$key') is missing a label.";
        } elseif (mb_strlen($label) > 200) {
            $errors[] = "Field #$i (key '$key') label must be 200 characters or fewer.";
        }

        $type = (string) ($f['type'] ?? '');

        if ($type === 'table') {
            $tableCount++;
            $columns = $f['columns'] ?? null;
            if (!is_array($columns) || empty($columns)) {
                $errors[] = "Table field '$key' must define at least one column.";
            } else {
                if (count($columns) > ICS_FORM_TYPE_MAX_TABLE_COLUMNS) {
                    $errors[] = "Table field '$key' has " . count($columns) . " columns; max is " . ICS_FORM_TYPE_MAX_TABLE_COLUMNS . ".";
                }
                $seenColKeys = [];
                foreach ($columns as $ci => $col) {
                    if (!is_array($col)) { $errors[] = "Table field '$key' column #$ci is not a valid object."; continue; }
                    $colKey = (string) ($col['key'] ?? '');
                    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $colKey)) {
                        $errors[] = "Table field '$key' column #$ci key '$colKey' is invalid.";
                    } elseif (in_array(strtolower($colKey), $reserved, true)) {
                        $errors[] = "Table field '$key' column #$ci key '$colKey' is a reserved word.";
                    } elseif (isset($seenColKeys[$colKey])) {
                        $errors[] = "Table field '$key' has a duplicate column key '$colKey'.";
                    } else {
                        $seenColKeys[$colKey] = true;
                    }
                    if (trim((string) ($col['label'] ?? '')) === '') {
                        $errors[] = "Table field '$key' column '$colKey' is missing a label.";
                    }
                    $colType = (string) ($col['type'] ?? 'text');
                    if (!in_array($colType, $columnTypes, true)) {
                        $errors[] = "Table field '$key' column '$colKey' has an invalid type '$colType'.";
                    } elseif ($colType === 'select') {
                        $optErr = ics_form_type_validate_options($col['options'] ?? null);
                        if ($optErr !== null) $errors[] = "Table field '$key' column '$colKey': $optErr.";
                    }
                }
            }

            $defaultRows = array_key_exists('default_rows', $f) ? $f['default_rows'] : 1;
            if (!_ics_ft_int_in_range($defaultRows, 1, 5)) {
                $errors[] = "Table field '$key' default_rows must be an integer 1-5.";
            }
            $maxRows = array_key_exists('max_rows', $f) ? $f['max_rows'] : ICS_FORM_TYPE_MAX_TABLE_ROWS;
            if (!_ics_ft_int_in_range($maxRows, 1, ICS_FORM_TYPE_MAX_TABLE_ROWS)) {
                $errors[] = "Table field '$key' max_rows must be an integer 1-" . ICS_FORM_TYPE_MAX_TABLE_ROWS . ".";
            }
        } elseif (in_array($type, $simpleTypes, true)) {
            $simpleCount++;

            if ($type === 'select') {
                $optErr = ics_form_type_validate_options($f['options'] ?? null);
                if ($optErr !== null) $errors[] = "Field '$key': $optErr.";
            }

            if ($type === 'number') {
                $min = array_key_exists('min', $f) ? $f['min'] : null;
                $max = array_key_exists('max', $f) ? $f['max'] : null;
                if ($min !== null && $max !== null && is_numeric($min) && is_numeric($max) && (float) $min > (float) $max) {
                    $errors[] = "Field '$key' min cannot be greater than max.";
                }
            }

            if ($type === 'textarea') {
                $rows = array_key_exists('rows', $f) ? $f['rows'] : 4;
                if (!_ics_ft_int_in_range($rows, 1, 30)) {
                    $errors[] = "Field '$key' rows must be an integer 1-30.";
                }
            }
        } else {
            $errors[] = "Field #$i (key '$key') has an unknown type '$type'.";
        }
    }

    if ($simpleCount > ICS_FORM_TYPE_MAX_SIMPLE_FIELDS) {
        $errors[] = "Too many simple fields ($simpleCount); max is " . ICS_FORM_TYPE_MAX_SIMPLE_FIELDS . ".";
    }
    if ($tableCount > ICS_FORM_TYPE_MAX_TABLE_FIELDS) {
        $errors[] = "Too many table fields ($tableCount); max is " . ICS_FORM_TYPE_MAX_TABLE_FIELDS . ".";
    }

    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * THE choke point (plan.md "the one concrete vulnerability found in
 * review, and its fix"): the single place that loads a type definition
 * for use, enforcing org-scope + restrict_to_permission uniformly for
 * both getFormTemplate('custom', ...) and the save handler's first-save
 * _meta build. Reads the caller's identity from $_SESSION, matching this
 * codebase's rbac_can()/audit_log() convention -- callers never pass
 * context explicitly.
 *
 * Returns null (never a distinguishable "forbidden" vs "not found") for:
 * row doesn't exist; row is archived and caller cannot manage it; row is
 * out of the caller's org scope and caller cannot manage it; or the row's
 * restrict_to_permission is set and the caller does not hold it. A denial
 * always looks identical to "type doesn't exist" -- no enumeration signal
 * for a caller probing another org's type ids.
 */
function ics_form_custom_template($customTypeId): ?array {
    $customTypeId = (int) $customTypeId;
    if ($customTypeId <= 0) return null;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $row = db_fetch_one(
        "SELECT * FROM `{$prefix}ics_form_types` WHERE `id` = ?",
        [$customTypeId]
    );
    if (!$row) return null;

    $rowOrgId = $row['org_id'] !== null ? (int) $row['org_id'] : null;

    // Authoring rights over THIS ROW'S specific org. Checked via
    // rbac_can()'s $context['org_id'] override (inc/rbac.php
    // _rbac_scope_satisfied()) against the row's actual org, NOT by
    // comparing $_SESSION['active_org_id'] to rowOrgId. A caller can hold
    // a legitimate org-scoped authoring grant for an org that isn't their
    // currently-active one (multi-org accounts) -- the established pattern
    // for exactly this situation is Phase 138's pb_resolve_caller_org_id(),
    // which checks a SPECIFIC org's grant directly rather than resolving a
    // single "caller org" first and comparing equality. Authoring rights
    // must not evaporate just because a different org happens to be
    // selected in the caller's session at the moment.
    $canAuthorGlobal = rbac_can('action.manage_ics_form_types');
    $canAuthorThisOrg = $canAuthorGlobal
        || ($rowOrgId !== null && rbac_can('action.manage_ics_form_types_org', ['org_id' => $rowOrgId]));

    // Archived types are only reachable by someone who can manage them.
    if ((string) $row['status'] !== 'active' && !$canAuthorThisOrg) {
        return null;
    }

    // Ordinary USE scope (not authoring): install-wide (NULL) rows are
    // visible to everyone; an org-scoped row is visible to ordinary
    // members of that org via $_SESSION['active_org_id'] (the session's
    // general org-membership signal -- correct to use here, since this is
    // about "what org is this caller operating as", not an authoring
    // grant), OR to a caller who holds authoring rights over it regardless
    // of which org is currently active. Falls back to org_user_home_id()
    // for a session with a user_id but no active_org_id yet -- this
    // project's own documented RBAC pitfall on that exact session key.
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $callerActiveOrg = (int) ($_SESSION['active_org_id'] ?? 0);
    if ($callerActiveOrg <= 0 && $userId > 0 && function_exists('org_user_home_id')) {
        $callerActiveOrg = org_user_home_id($userId);
    }
    $inScope = ($rowOrgId === null) || ($rowOrgId === $callerActiveOrg) || $canAuthorThisOrg;
    if (!$inScope) {
        return null;
    }

    // The type's own optional extra gate, checked unconditionally --
    // authoring rights over the TYPE do not imply the extra USE permission
    // the type itself may require (e.g. a medical-only form restricted to
    // action.view_patient).
    $restrictTo = (string) ($row['restrict_to_permission'] ?? '');
    if ($restrictTo !== '' && !rbac_can($restrictTo)) {
        return null;
    }

    $fields = json_decode((string) $row['fields_json'], true);
    if (!is_array($fields)) $fields = [];

    return [
        'form_type'              => 'custom',
        'custom_type_id'         => (int) $row['id'],
        'slug'                   => (string) $row['slug'],
        'form_number'            => (string) $row['form_number'],
        'form_title'             => (string) $row['form_title'],
        'description'            => (string) $row['description'],
        'badge_color'            => (string) $row['badge_color'],
        'icon'                   => (string) $row['icon'],
        'org_id'                 => $rowOrgId,
        'status'                 => (string) $row['status'],
        'restrict_to_permission' => $restrictTo !== '' ? $restrictTo : null,
        'fields'                 => $fields,
    ];
}

/**
 * Build (on first save) or carry forward (on update) the _meta snapshot
 * for a custom instance. $existingMeta is the previous form_data_json's
 * '_meta' key (null on first save) -- once a snapshot exists it is never
 * regenerated, so editing the type definition later can never change how
 * an existing submission renders.
 */
function ics_form_custom_build_meta(array $typeTemplate, ?array $existingMeta = null): array {
    if ($existingMeta !== null) {
        return $existingMeta;
    }
    return [
        'type_id'     => $typeTemplate['custom_type_id'],
        'type_slug'   => $typeTemplate['slug'],
        'form_number' => $typeTemplate['form_number'],
        'form_title'  => $typeTemplate['form_title'],
        'badge_color' => $typeTemplate['badge_color'],
        'icon'        => $typeTemplate['icon'],
        'fields'      => $typeTemplate['fields'],
        'snapshot_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * Validate submitted instance data against the type's field definitions
 * on save -- the select-value check plan.md calls out specifically
 * ("value validated server-side against the option list at save"),
 * covering both simple select fields and select-type table columns, PLUS
 * a number field's min/max bounds (the same bounds the field-builder lets
 * an author set on the type -- a value outside them must be refused here,
 * not merely left to the browser's `<input type=number min max>`, which
 * this codebase's JS-driven save path never runs through native HTML5
 * constraint validation for). Matches this codebase's existing depth of
 * server-side validation for the built-in types (which do not re-validate
 * `required` server-side either -- that stays a client-side concern for
 * both built-in and custom types alike).
 */
function ics_form_custom_validate_data(array $fields, array $formData): array {
    $errors = [];

    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $type = (string) ($f['type'] ?? '');
        $key = (string) ($f['key'] ?? '');
        if ($key === '') continue;

        if ($type === 'select') {
            if (!array_key_exists($key, $formData)) continue;
            $val = $formData[$key];
            if ($val === '' || $val === null) continue; // blank is always allowed server-side
            $optErr = _ics_ft_value_in_options($val, $f['options'] ?? []);
            if ($optErr !== null) $errors[] = "Field '$key': $optErr";
        } elseif ($type === 'number') {
            if (!array_key_exists($key, $formData)) continue;
            $val = $formData[$key];
            if ($val === '' || $val === null) continue; // blank is always allowed server-side
            $rangeErr = _ics_ft_number_in_range($val, $f['min'] ?? null, $f['max'] ?? null);
            if ($rangeErr !== null) $errors[] = "Field '$key': $rangeErr";
        } elseif ($type === 'table') {
            $columns = isset($f['columns']) && is_array($f['columns']) ? $f['columns'] : [];
            $selectCols = [];
            foreach ($columns as $col) {
                if (is_array($col) && ($col['type'] ?? '') === 'select') {
                    $selectCols[(string) ($col['key'] ?? '')] = $col['options'] ?? [];
                }
            }
            if (empty($selectCols)) continue;
            $rows = isset($formData[$key]) && is_array($formData[$key]) ? $formData[$key] : [];
            foreach ($rows as $ri => $rowData) {
                if (!is_array($rowData)) continue;
                foreach ($selectCols as $colKey => $options) {
                    if (!array_key_exists($colKey, $rowData)) continue;
                    $val = $rowData[$colKey];
                    if ($val === '' || $val === null) continue;
                    $optErr = _ics_ft_value_in_options($val, $options);
                    if ($optErr !== null) $errors[] = "Field '$key' row #$ri, column '$colKey': $optErr";
                }
            }
        }
    }

    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Is $val within [$min, $max]? Either bound may be null/absent/non-numeric
 * (treated as "no bound on that side" -- mirrors the field-builder, which
 * lets an author leave min or max blank). $val must itself be numeric --
 * a non-numeric value in a number field's slot is rejected outright rather
 * than silently coerced, since a stray string there is exactly the kind of
 * malformed-request-built-by-hand case the server is the last line for.
 */
function _ics_ft_number_in_range($val, $min, $max): ?string {
    if (!is_numeric($val)) {
        return 'must be a number';
    }
    $f = (float) $val;
    if ($min !== null && $min !== '' && is_numeric($min) && $f < (float) $min) {
        return "value $val is below the minimum of $min";
    }
    if ($max !== null && $max !== '' && is_numeric($max) && $f > (float) $max) {
        return "value $val is above the maximum of $max";
    }
    return null;
}

/** Is $val one of $options (string list, or {label,value} object list)? */
function _ics_ft_value_in_options($val, $options): ?string {
    if (!is_array($options)) return 'no options are defined for this field';
    $val = (string) $val;
    foreach ($options as $opt) {
        $candidate = is_array($opt) ? (string) ($opt['value'] ?? $opt['label'] ?? '') : (string) $opt;
        if ($candidate === $val) return null;
    }
    return "'$val' is not one of this field's defined options";
}

/**
 * The one generic print renderer for every custom type. Renders from the
 * instance's own frozen _meta -- NEVER a fresh ics_form_types lookup, so
 * this never queries the database. $row is the ics_forms instance row
 * (matching generatePrintHtml($formType, $data, $row)'s existing shape);
 * only $data is used here.
 *
 * Markup shape is deliberately identical to the built-in printICSxxx()
 * functions' <span class="label">/<span class="value"> convention, so
 * _ics_apply_security_wrap()'s redaction regex (which matches
 * <span class="value">...</span>) works on custom forms exactly as it does
 * on the nine built-ins -- verified against that function's actual regex,
 * not assumed.
 */
function ics_form_custom_print_html($data, $row): string {
    $data = is_array($data) ? $data : [];
    $meta = isset($data['_meta']) && is_array($data['_meta']) ? $data['_meta'] : [];
    $fields = isset($meta['fields']) && is_array($meta['fields']) ? $meta['fields'] : [];

    $h = '<table>';
    $open = false; // a <tr> is open, awaiting its second <td>

    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $type  = (string) ($f['type'] ?? 'text');
        $key   = (string) ($f['key'] ?? '');
        $label = (string) ($f['label'] ?? $key);

        if ($type === 'section_header') {
            if ($open) { $h .= '<td>&nbsp;</td></tr>'; $open = false; }
            $h .= '<tr><th colspan="2" style="background:#e5e5e5">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th></tr>';
            continue;
        }

        if ($type === 'table') {
            if ($open) { $h .= '<td>&nbsp;</td></tr>'; $open = false; }
            $columns = isset($f['columns']) && is_array($f['columns']) ? $f['columns'] : [];
            $h .= '<tr><th colspan="2" style="background:#e5e5e5">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th></tr></table><table><tr>';
            foreach ($columns as $col) {
                $h .= '<th>' . htmlspecialchars((string) ($col['label'] ?? ($col['key'] ?? '')), ENT_QUOTES, 'UTF-8') . '</th>';
            }
            $h .= '</tr>';
            $rows = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
            if (!empty($rows)) {
                foreach ($rows as $rowData) {
                    $h .= '<tr>';
                    foreach ($columns as $col) {
                        $colKey = (string) ($col['key'] ?? '');
                        $val = is_array($rowData) ? ($rowData[$colKey] ?? '') : '';
                        $h .= '<td>' . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . '</td>';
                    }
                    $h .= '</tr>';
                }
            } else {
                $h .= '<tr><td colspan="' . max(1, count($columns)) . '" style="height:22px">&nbsp;</td></tr>';
            }
            $h .= '</table><table>';
            continue;
        }

        $value = $data[$key] ?? '';
        if ($type === 'checkbox') {
            $display = !empty($value) ? '&#9745;' : '&#9744;';
        } elseif ($type === 'textarea') {
            $display = nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
        } else {
            $display = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        if (!$open) { $h .= '<tr>'; }
        $h .= '<td' . ($open ? '' : ' style="width:50%"') . '>'
            . '<span class="label">' . htmlspecialchars(strtoupper($label), ENT_QUOTES, 'UTF-8') . ':</span>'
            . '<span class="value">' . $display . '</span></td>';
        if ($open) { $h .= '</tr>'; }
        $open = !$open;
    }
    if ($open) { $h .= '<td>&nbsp;</td></tr>'; }
    $h .= '</table>';
    return $h;
}
