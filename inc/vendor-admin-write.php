<?php
/**
 * GH#148 (Phase 155) -- Towing / roadside vendor dispatch: the ADMIN write library.
 *
 * Providers, rotation lists, list members, service types and the five settings. Everything an administrator
 * (action.manage_vendors, tier 1) does lives here; the dispatcher's operational writers are in
 * inc/vendor-dispatch.php. Like those, every function enforces its own business rules (org scope, "a referenced
 * provider can only be retired", "no jumping a company to the front") and returns
 * ['ok' => true, ...] or vendor_fail()'s ['ok' => false, 'http', 'code', 'message'] -- the endpoint
 * (api/vendor-admin.php) checks RBAC and CSRF, this file never trusts it did.
 *
 * Fairness rules that are enforced HERE, not in the UI (spec 6.2 rule 7, 6.6):
 *   - a company added to a list that already has history goes to the BACK (a joined_list ledger row consumes a turn)
 *   - there is deliberately NO "jump to the front": an admin can move a company to the END (reason required, audited,
 *     a ledger row), and in round-robin mode the position of a company on a list WITH history is not reorderable at
 *     all because the order follows the ledger (a one-off preference is an override on the dispatch, which is audited)
 *   - history is never deleted: a provider or list referenced by any ledger or dispatch row can only be retired
 */

require_once __DIR__ . '/vendor-dispatch.php';
require_once __DIR__ . '/audit.php';   // the AUDIT_* severity constants are used at the call sites below

/** audit_log wrapper that can never break the action it records. */
function vendor_admin_audit(string $activity, string $targetType, $targetId, string $summary, array $details = [], int $severity = 1): void
{
    try {
        if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) require_once __DIR__ . '/audit.php';
        if (function_exists('audit_log')) {
            audit_log('vendor', $activity, $targetType, $targetId, $summary, $details ?: null, $severity);
        }
    } catch (Throwable $e) { error_log('[vendor_admin_audit] ' . $e->getMessage()); }
}

function vendor_admin_err(Throwable $e, string $tag): array
{
    error_log('[' . $tag . '] ' . $e->getMessage());
    $busy = $e instanceof PDOException && isset($e->errorInfo[1]) && in_array((int) $e->errorInfo[1], [1205, 1213], true);
    if ($busy) return vendor_fail(503, 'busy', 'The rotation is busy. Please try again.');
    return vendor_fail(500, 'internal', 'That change could not be saved.');
}

/**
 * The service types and the settings are INSTALL-WIDE: every agency's dispatch dialog reads them, so an Org Admin of one
 * agency changing them changes (or switches off) the feature for all of them. Only a caller with unrestricted org
 * visibility (a Super Admin) may. null = allowed.
 */
function vendor_require_install_wide(): ?array
{
    if (vendor_org_unrestricted()) return null;
    return vendor_fail(403, 'forbidden_scope', 'These are install-wide settings shared by every agency, so only a Super Admin can change them.');
}

// ═══════════════════════════════════════════════════════════════════════════
// Service types
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Create (id absent/0) or update a service type. The `code` is a stable slug set at creation and never changes;
 * the label, the needs-destination flag, the active flag and the sort order are editable. There is no delete: a
 * type referenced by lists and dispatches is retired (is_active=0).
 */
function vendor_service_type_save(array $in, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (($deny = vendor_require_install_wide()) !== null) return $deny;
    $id = (int) ($in['id'] ?? 0);
    $label = vendor_clean_str($in['label'] ?? null, 64);
    if ($label === null) return vendor_fail(422, 'validation', 'Enter a label for the service type.');
    $needs = !empty($in['needs_destination']) ? 1 : 0;
    $active = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;
    $sort = (int) ($in['sort_order'] ?? 0);
    try {
        if ($id > 0) {
            $row = db_fetch_one("SELECT * FROM `{$prefix}vendor_service_types` WHERE `id` = ?", [$id]);
            if (!$row) return vendor_fail(404, 'not_found', 'Service type not found.');
            db_query("UPDATE `{$prefix}vendor_service_types` SET `label` = ?, `needs_destination` = ?, `is_active` = ?, `sort_order` = ? WHERE `id` = ?",
                [$label, $needs, $active, $sort, $id]);
            vendor_admin_audit('service_type.save', 'vendor_service_type', $id, 'Updated service type "' . $row['code'] . '"',
                ['label' => $label, 'needs_destination' => $needs, 'is_active' => $active, 'sort_order' => $sort]);
        } else {
            $code = strtolower((string) vendor_clean_str($in['code'] ?? null, 32));
            if ($code === '') {
                $code = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
            }
            if (!preg_match('/^[a-z0-9_]{1,32}$/', $code)) return vendor_fail(422, 'validation', 'The code may contain only lowercase letters, digits and underscores.');
            $dup = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_service_types` WHERE `code` = ?", [$code]);
            if ($dup > 0) return vendor_fail(409, 'duplicate', 'A service type with that code already exists.');
            db_query("INSERT INTO `{$prefix}vendor_service_types` (`code`, `label`, `needs_destination`, `is_active`, `sort_order`) VALUES (?, ?, ?, ?, ?)",
                [$code, $label, $needs, $active, $sort]);
            $id = (int) db_insert_id();
            vendor_admin_audit('service_type.save', 'vendor_service_type', $id, 'Created service type "' . $code . '"',
                ['label' => $label, 'needs_destination' => $needs]);
        }
    } catch (Throwable $e) {
        if (vendor_is_dup_key($e)) return vendor_fail(409, 'duplicate', 'A service type with that code already exists.');
        return vendor_admin_err($e, 'vendor_service_type_save');
    }
    return ['ok' => true, 'service_type' => db_fetch_one("SELECT * FROM `{$prefix}vendor_service_types` WHERE `id` = ?", [$id])];
}

// ═══════════════════════════════════════════════════════════════════════════
// Providers
// ═══════════════════════════════════════════════════════════════════════════

function vendor_provider_get(int $id): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one("SELECT * FROM `{$prefix}vendor_providers` WHERE `id` = ?", [$id]);
}

/** Is any ledger or dispatch row pointing at this provider? Then it can only be retired, never deleted. */
function vendor_provider_referenced(int $id): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `provider_id` = ? OR `expected_head_provider_id` = ?", [$id, $id]) > 0
        || (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `provider_id` = ?", [$id]) > 0;
}

/**
 * Create (id absent/0) or update a provider (a company we call). org_id is decided at creation only
 * (vendor_resolve_create_org: the caller's own org, 'all' = "all agencies" for a Super Admin, or an org they can see)
 * and never changes afterwards. Setting is_active=0 here is the same as retiring.
 */
function vendor_provider_save(array $in, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $id = (int) ($in['id'] ?? 0);

    $name = vendor_clean_str($in['name'] ?? null, 120);
    if ($name === null) return vendor_fail(422, 'validation', 'Enter the company name.');
    $phone = vendor_clean_phone($in['phone'] ?? null);
    if ($phone === null) return vendor_fail(422, 'validation', 'Enter a valid phone number (at least three digits).');
    $phoneAlt = null;
    if (vendor_clean_str($in['phone_alt'] ?? null, 64) !== null) {
        $phoneAlt = vendor_clean_phone($in['phone_alt']);
        if ($phoneAlt === null) return vendor_fail(422, 'validation', 'The alternate phone number is not valid.');
    }
    $yard = null;
    if (!empty($in['yard_facility_id'])) {
        $yard = (int) $in['yard_facility_id'];
        if (!vendor_facility_exists($yard)) return vendor_fail(422, 'validation', 'The yard facility does not exist.');
    }
    $fields = [
        'name' => $name, 'contact_name' => vendor_clean_str($in['contact_name'] ?? null, 64), 'phone' => $phone,
        'phone_alt' => $phoneAlt, 'service_area' => vendor_clean_str($in['service_area'] ?? null, 160),
        'hours_note' => vendor_clean_str($in['hours_note'] ?? null, 255), 'notes' => vendor_clean_str($in['notes'] ?? null, 4000),
        'yard_facility_id' => $yard,
        'is_active' => array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1,
    ];

    try {
        if ($id > 0) {
            $row = vendor_provider_get($id);
            if (!$row || !vendor_org_visible($row['org_id'] !== null ? (int) $row['org_id'] : null)) return vendor_fail(404, 'not_found', 'Company not found.');
            if (!vendor_org_writable($row['org_id'] !== null ? (int) $row['org_id'] : null)) {
                return vendor_fail(403, 'forbidden_scope', 'That company belongs to "all agencies" and only a Super Admin can change it.');
            }
            $changed = [];
            foreach ($fields as $k => $v) { if ((string) $row[$k] !== (string) $v) $changed[] = $k; }
            db_query(
                "UPDATE `{$prefix}vendor_providers`
                    SET `name` = ?, `contact_name` = ?, `phone` = ?, `phone_alt` = ?, `service_area` = ?, `hours_note` = ?,
                        `notes` = ?, `yard_facility_id` = ?, `is_active` = ?
                  WHERE `id` = ?",
                [$fields['name'], $fields['contact_name'], $fields['phone'], $fields['phone_alt'], $fields['service_area'],
                 $fields['hours_note'], $fields['notes'], $fields['yard_facility_id'], $fields['is_active'], $id]);
            vendor_admin_audit('provider.update', 'vendor_provider', $id, 'Updated company "' . $name . '"', ['changed' => $changed]);
        } else {
            [$ok, $orgId, $err] = vendor_resolve_create_org($in['org_id'] ?? null);
            if (!$ok) return vendor_fail(403, 'forbidden_scope', $err);
            db_query(
                "INSERT INTO `{$prefix}vendor_providers`
                    (`org_id`, `name`, `contact_name`, `phone`, `phone_alt`, `service_area`, `hours_note`, `notes`, `yard_facility_id`, `is_active`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$orgId, $fields['name'], $fields['contact_name'], $fields['phone'], $fields['phone_alt'], $fields['service_area'],
                 $fields['hours_note'], $fields['notes'], $fields['yard_facility_id'], $fields['is_active']]);
            $id = (int) db_insert_id();
            vendor_admin_audit('provider.create', 'vendor_provider', $id, 'Added company "' . $name . '"', ['org_id' => $orgId]);
        }
    } catch (Throwable $e) {
        return vendor_admin_err($e, 'vendor_provider_save');
    }
    return ['ok' => true, 'provider' => vendor_provider_get($id)];
}

/** Load a provider the caller may change, or the failure result. @return array [row|null, failure|null] */
function _vendor_provider_for_write(int $id): array
{
    $row = vendor_provider_get($id);
    if (!$row || !vendor_org_visible($row['org_id'] !== null ? (int) $row['org_id'] : null)) return [null, vendor_fail(404, 'not_found', 'Company not found.')];
    if (!vendor_org_writable($row['org_id'] !== null ? (int) $row['org_id'] : null)) {
        return [null, vendor_fail(403, 'forbidden_scope', 'That company belongs to "all agencies" and only a Super Admin can change it.')];
    }
    return [$row, null];
}

/** Suspend a company until a date/time (it stays on its lists but is skipped, flagged "suspended until ..."). */
function vendor_provider_suspend(int $id, $until, $reason, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$row, $fail] = _vendor_provider_for_write($id);
    if ($fail) return $fail;
    $ts = is_string($until) ? strtotime($until) : false;
    $nowTs = vendor_db_now_ts();       // the database clock: suspended_until is later compared with the database's NOW()
    if ($ts === false || $ts <= $nowTs) return vendor_fail(422, 'validation', 'Choose a date and time in the future.');
    if ($ts > $nowTs + 400 * 86400) return vendor_fail(422, 'validation', 'A suspension cannot run longer than a year. Retire the company instead.');
    $reason = vendor_clean_str($reason, 255);
    if ($reason === null) return vendor_fail(422, 'reason_required', 'A reason is required to suspend a company.');
    try {
        db_query("UPDATE `{$prefix}vendor_providers` SET `suspended_until` = ?, `suspend_reason` = ? WHERE `id` = ?", [date('Y-m-d H:i:s', $ts), $reason, $id]);
        vendor_admin_audit('provider.suspend', 'vendor_provider', $id, 'Suspended "' . $row['name'] . '" until ' . date('Y-m-d H:i', $ts),
            ['reason' => $reason, 'until' => date('Y-m-d H:i:s', $ts)], AUDIT_MEDIUM);
    } catch (Throwable $e) { return vendor_admin_err($e, 'vendor_provider_suspend'); }
    return ['ok' => true, 'provider' => vendor_provider_get($id)];
}

function vendor_provider_unsuspend(int $id, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$row, $fail] = _vendor_provider_for_write($id);
    if ($fail) return $fail;
    try {
        db_query("UPDATE `{$prefix}vendor_providers` SET `suspended_until` = NULL, `suspend_reason` = NULL WHERE `id` = ?", [$id]);
        vendor_admin_audit('provider.unsuspend', 'vendor_provider', $id, 'Lifted the suspension on "' . $row['name'] . '"', [], AUDIT_MEDIUM);
    } catch (Throwable $e) { return vendor_admin_err($e, 'vendor_provider_unsuspend'); }
    return ['ok' => true, 'provider' => vendor_provider_get($id)];
}

/** Retire = inactive. History keeps its snapshots; the company stops appearing in any queue. */
function vendor_provider_retire(int $id, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$row, $fail] = _vendor_provider_for_write($id);
    if ($fail) return $fail;
    try {
        db_query("UPDATE `{$prefix}vendor_providers` SET `is_active` = 0 WHERE `id` = ?", [$id]);
        vendor_admin_audit('provider.retire', 'vendor_provider', $id, 'Retired company "' . $row['name'] . '"', [], AUDIT_MEDIUM);
    } catch (Throwable $e) { return vendor_admin_err($e, 'vendor_provider_retire'); }
    return ['ok' => true, 'provider' => vendor_provider_get($id)];
}

/** Hard delete, only for a company nothing ever pointed at (a fat-fingered entry). Otherwise retire it. */
function vendor_provider_delete(int $id, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$row, $fail] = _vendor_provider_for_write($id);
    if ($fail) return $fail;
    $owned = vendor_tx_begin();
    try {
        // Lock every list this provider sits on so no offer can reference it between the check and the delete.
        db_query("SELECT l.`id` FROM `{$prefix}vendor_rotation_lists` l
                    JOIN `{$prefix}vendor_rotation_members` m ON m.`list_id` = l.`id`
                   WHERE m.`provider_id` = ? ORDER BY l.`id` FOR UPDATE", [$id]);
        // Then the provider row itself: an offer that names this company through "Other provider" (no list) locks only
        // the provider, so this is the lock the two sides actually meet on. Order everywhere: lists, then provider.
        db_query("SELECT `id` FROM `{$prefix}vendor_providers` WHERE `id` = ? FOR UPDATE", [$id]);
        if (vendor_provider_referenced($id)) {
            vendor_tx_rollback($owned);
            return vendor_fail(409, 'provider_referenced', 'That company appears in the dispatch history, so it cannot be deleted. Retire it instead.');
        }
        db_query("DELETE FROM `{$prefix}vendor_rotation_members` WHERE `provider_id` = ?", [$id]);
        db_query("DELETE FROM `{$prefix}vendor_providers` WHERE `id` = ?", [$id]);
        vendor_tx_commit($owned);
    } catch (Throwable $e) { vendor_tx_rollback($owned); return vendor_admin_err($e, 'vendor_provider_delete'); }
    vendor_admin_audit('provider.delete', 'vendor_provider', $id, 'Deleted company "' . $row['name'] . '"', [], AUDIT_HIGH);
    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════════════
// Rotation lists
// ═══════════════════════════════════════════════════════════════════════════

/** Is any ledger or dispatch row pointing at this list? */
function vendor_list_referenced(int $id): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ?", [$id]) > 0
        || (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `list_id` = ?", [$id]) > 0;
}

/** Load a list the caller may change, or the failure result. @return array [row|null, failure|null] */
function _vendor_list_for_write(int $id): array
{
    $row = vendor_list_get($id);
    if (!$row || !vendor_org_visible($row['org_id'] !== null ? (int) $row['org_id'] : null)) return [null, vendor_fail(404, 'not_found', 'Rotation list not found.')];
    if (!vendor_org_writable($row['org_id'] !== null ? (int) $row['org_id'] : null)) {
        return [null, vendor_fail(403, 'forbidden_scope', 'That list belongs to "all agencies" and only a Super Admin can change it.')];
    }
    return [$row, null];
}

/** Create (id absent/0) or update a rotation list. The service type is fixed once the list has any history. */
function vendor_list_save(array $in, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $id = (int) ($in['id'] ?? 0);
    $name = vendor_clean_str($in['name'] ?? null, 120);
    if ($name === null) return vendor_fail(422, 'validation', 'Enter a name for the list.');
    $stId = (int) ($in['service_type_id'] ?? 0);
    $st = $stId > 0 ? db_fetch_one("SELECT * FROM `{$prefix}vendor_service_types` WHERE `id` = ?", [$stId]) : null;
    if (!$st) return vendor_fail(422, 'validation', 'Choose the service type this list serves.');
    $mode = vendor_clean_str($in['mode'] ?? null, 16);
    if ($mode !== null && !in_array($mode, ['round_robin', 'strict_order', 'manual'], true)) {
        return vendor_fail(422, 'validation', 'Unknown rotation mode.');
    }
    $desc = vendor_clean_str($in['description'] ?? null, 255);
    $sort = (int) ($in['sort_order'] ?? 0);
    $active = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;

    try {
        if ($id > 0) {
            [$row, $fail] = _vendor_list_for_write($id);
            if ($fail) return $fail;
            if ((int) $row['service_type_id'] !== $stId && vendor_list_referenced($id)) {
                return vendor_fail(409, 'list_in_use', 'This list already has dispatch history, so its service type cannot change. Create a new list instead.');
            }
            // "One default per (organization, service type)": a list that changes service type cannot carry its default
            // flag to the new type, where another list may already hold it. (Computed here, not in SQL: in an UPDATE the
            // later SET expressions see the NEW service_type_id.)
            $typeChanged = (int) $row['service_type_id'] !== $stId ? 1 : 0;
            db_query(
                "UPDATE `{$prefix}vendor_rotation_lists`
                    SET `name` = ?, `service_type_id` = ?, `description` = ?, `mode` = ?, `sort_order` = ?, `is_active` = ?,
                        `is_default` = IF(? = 0 OR ? = 1, 0, `is_default`)
                  WHERE `id` = ?",
                [$name, $stId, $desc, $mode, $sort, $active, $active, $typeChanged, $id]);
            // Switching a list's mode is a way to reorder it (strict order, move, switch back), so on a list that has
            // history it leaves a ledger row the same way a move does.
            if ((string) ($row['mode'] ?? '') !== (string) ($mode ?? '') && vendor_list_has_history($id)) {
                vendor_ledger_insert([
                    'event_type' => 'order_changed', 'list_id' => $id,
                    'detail' => 'Rotation mode changed from "' . (($row['mode'] ?? '') !== '' ? $row['mode'] : 'install default') . '" to "'
                                . (($mode ?? '') !== '' ? $mode : 'install default') . '".',
                ], $actor);
            }
            vendor_admin_audit('list.update', 'vendor_list', $id, 'Updated rotation list "' . $name . '"',
                ['mode' => $mode, 'service_type_id' => $stId, 'is_active' => $active]);
        } else {
            [$ok, $orgId, $err] = vendor_resolve_create_org($in['org_id'] ?? null);
            if (!$ok) return vendor_fail(403, 'forbidden_scope', $err);
            db_query(
                "INSERT INTO `{$prefix}vendor_rotation_lists` (`org_id`, `name`, `service_type_id`, `description`, `mode`, `is_default`, `is_active`, `sort_order`)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?)",
                [$orgId, $name, $stId, $desc, $mode, $active, $sort]);
            $id = (int) db_insert_id();
            vendor_admin_audit('list.create', 'vendor_list', $id, 'Created rotation list "' . $name . '"', ['org_id' => $orgId, 'service_type_id' => $stId]);
        }
    } catch (Throwable $e) { return vendor_admin_err($e, 'vendor_list_save'); }
    return ['ok' => true, 'list' => vendor_list_get($id)];
}

/**
 * Make a list the default for its (organization, service type), or clear the flag. "At most one default per
 * (org, service type)" is enforced HERE in a transaction, not by a unique key: org_id is NULLable and MariaDB
 * treats NULLs as distinct in a unique index (the Phase 129 / Phase 143 lesson).
 */
function vendor_list_set_default(int $id, bool $isDefault, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$row, $fail] = _vendor_list_for_write($id);
    if ($fail) return $fail;
    $owned = vendor_tx_begin();
    try {
        $row = db_fetch_one("SELECT * FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ? FOR UPDATE", [$id]);
        if (!$row) { vendor_tx_rollback($owned); return vendor_fail(404, 'not_found', 'Rotation list not found.'); }
        if ($isDefault) {
            if ((int) $row['is_active'] !== 1) { vendor_tx_rollback($owned); return vendor_fail(409, 'list_inactive', 'A retired list cannot be the default.'); }
            db_query("UPDATE `{$prefix}vendor_rotation_lists` SET `is_default` = 0
                       WHERE `service_type_id` = ? AND `org_id` <=> ? AND `id` <> ?",
                [(int) $row['service_type_id'], $row['org_id'] !== null ? (int) $row['org_id'] : null, $id]);
        }
        db_query("UPDATE `{$prefix}vendor_rotation_lists` SET `is_default` = ? WHERE `id` = ?", [$isDefault ? 1 : 0, $id]);
        vendor_tx_commit($owned);
    } catch (Throwable $e) { vendor_tx_rollback($owned); return vendor_admin_err($e, 'vendor_list_set_default'); }
    vendor_admin_audit('list.set_default', 'vendor_list', $id, ($isDefault ? 'Made' : 'Cleared') . ' "' . $row['name'] . '" the default list', ['is_default' => $isDefault ? 1 : 0]);
    return ['ok' => true, 'list' => vendor_list_get($id)];
}

function vendor_list_retire(int $id, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$row, $fail] = _vendor_list_for_write($id);
    if ($fail) return $fail;
    try {
        db_query("UPDATE `{$prefix}vendor_rotation_lists` SET `is_active` = 0, `is_default` = 0 WHERE `id` = ?", [$id]);
        vendor_admin_audit('list.retire', 'vendor_list', $id, 'Retired rotation list "' . $row['name'] . '"', [], AUDIT_MEDIUM);
    } catch (Throwable $e) { return vendor_admin_err($e, 'vendor_list_retire'); }
    return ['ok' => true, 'list' => vendor_list_get($id)];
}

/** Delete a list nothing ever used (history is never erased). */
function vendor_list_delete(int $id, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$row, $fail] = _vendor_list_for_write($id);
    if ($fail) return $fail;
    $owned = vendor_tx_begin();
    try {
        db_query("SELECT `id` FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ? FOR UPDATE", [$id]);
        if (vendor_list_referenced($id)) {
            vendor_tx_rollback($owned);
            return vendor_fail(409, 'list_referenced', 'That list appears in the dispatch history, so it cannot be deleted. Retire it instead.');
        }
        db_query("DELETE FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ?", [$id]);
        db_query("DELETE FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ?", [$id]);
        vendor_tx_commit($owned);
    } catch (Throwable $e) { vendor_tx_rollback($owned); return vendor_admin_err($e, 'vendor_list_delete'); }
    vendor_admin_audit('list.delete', 'vendor_list', $id, 'Deleted rotation list "' . $row['name'] . '"', [], AUDIT_HIGH);
    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════════════
// List members
// ═══════════════════════════════════════════════════════════════════════════

/** Renumber a list's active members 1..n in their current order (positions only break ties / define strict order). */
function _vendor_member_renumber(int $listId): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all(
        "SELECT `id` FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ? AND `removed_at` IS NULL ORDER BY `position`, `id`", [$listId]);
    $n = 1;
    foreach ($rows as $r) {
        db_query("UPDATE `{$prefix}vendor_rotation_members` SET `position` = ? WHERE `id` = ?", [$n++, (int) $r['id']]);
    }
}

/**
 * Add a company to a list. A provider must be visible to the caller and either org-NULL or in the list's org. A
 * company added to a list that already has history goes to the BACK of the round-robin (a joined_list row consumes a
 * turn). Re-adding a removed member clears removed_at and puts it at the end.
 */
function vendor_member_add(int $listId, int $providerId, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$list, $fail] = _vendor_list_for_write($listId);
    if ($fail) return $fail;
    $p = vendor_provider_get($providerId);
    if (!$p || !vendor_org_visible($p['org_id'] !== null ? (int) $p['org_id'] : null)) return vendor_fail(404, 'not_found', 'Company not found.');
    if ($p['org_id'] !== null && ($list['org_id'] === null || (int) $p['org_id'] !== (int) $list['org_id'])) {
        return vendor_fail(422, 'validation', 'That company belongs to a different organization than this list.');
    }
    $owned = vendor_tx_begin();
    try {
        db_query("SELECT `id` FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ? FOR UPDATE", [$listId]);
        $max = (int) db_fetch_value("SELECT COALESCE(MAX(`position`), 0) FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ?", [$listId]);
        $existing = db_fetch_one("SELECT * FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ? AND `provider_id` = ?", [$listId, $providerId]);
        if ($existing && $existing['removed_at'] === null) {
            vendor_tx_rollback($owned);
            return vendor_fail(409, 'already_member', 'That company is already on this list.');
        }
        if ($existing) {
            db_query("UPDATE `{$prefix}vendor_rotation_members` SET `removed_at` = NULL, `position` = ?, `added_at` = NOW() WHERE `id` = ?", [$max + 1, (int) $existing['id']]);
        } else {
            db_query("INSERT INTO `{$prefix}vendor_rotation_members` (`list_id`, `provider_id`, `position`) VALUES (?, ?, ?)", [$listId, $providerId, $max + 1]);
        }
        if (vendor_list_has_history($listId)) {
            vendor_ledger_insert([
                'event_type' => 'joined_list', 'list_id' => $listId, 'provider_id' => $providerId,
                'provider_name' => $p['name'], 'provider_phone' => $p['phone'], 'consumed_turn' => 1,
                'detail' => 'Added to the list after it already had history: placed at the back of the rotation.',
            ], $actor);
        }
        vendor_tx_commit($owned);
    } catch (Throwable $e) { vendor_tx_rollback($owned); return vendor_admin_err($e, 'vendor_member_add'); }
    vendor_admin_audit('member.add', 'vendor_list', $listId, 'Added "' . $p['name'] . '" to rotation list "' . $list['name'] . '"', ['provider_id' => $providerId], AUDIT_MEDIUM);
    return ['ok' => true];
}

function vendor_member_remove(int $listId, int $providerId, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$list, $fail] = _vendor_list_for_write($listId);
    if ($fail) return $fail;
    $p = vendor_provider_get($providerId);
    $owned = vendor_tx_begin();
    try {
        db_query("SELECT `id` FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ? FOR UPDATE", [$listId]);
        $m = db_fetch_one("SELECT * FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ? AND `provider_id` = ? AND `removed_at` IS NULL", [$listId, $providerId]);
        if (!$m) { vendor_tx_rollback($owned); return vendor_fail(404, 'not_found', 'That company is not on this list.'); }
        db_query("UPDATE `{$prefix}vendor_rotation_members` SET `removed_at` = NOW() WHERE `id` = ?", [(int) $m['id']]);
        _vendor_member_renumber($listId);
        if (vendor_list_has_history($listId)) {
            vendor_ledger_insert([
                'event_type' => 'removed_from_list', 'list_id' => $listId, 'provider_id' => $providerId,
                'provider_name' => $p ? $p['name'] : '', 'provider_phone' => $p ? $p['phone'] : null,
            ], $actor);
        }
        vendor_tx_commit($owned);
    } catch (Throwable $e) { vendor_tx_rollback($owned); return vendor_admin_err($e, 'vendor_member_remove'); }
    vendor_admin_audit('member.remove', 'vendor_list', $listId, 'Removed "' . ($p ? $p['name'] : '#' . $providerId) . '" from rotation list "' . $list['name'] . '"', ['provider_id' => $providerId], AUDIT_MEDIUM);
    return ['ok' => true];
}

/**
 * Move a member up or down one place. Refused in round-robin mode on a list that already has history: there the
 * order FOLLOWS THE LEDGER, so a position move would either do nothing or let an admin jump a company to the front,
 * which this feature deliberately does not allow. In strict-order and manual lists position IS the order, so it is allowed.
 */
function vendor_member_move(int $listId, int $providerId, string $direction, array $actor, ?string $reason = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!in_array($direction, ['up', 'down'], true)) return vendor_fail(422, 'validation', 'Direction must be up or down.');
    $reason = vendor_clean_str($reason, 255);
    [$list, $fail] = _vendor_list_for_write($listId);
    if ($fail) return $fail;
    $owned = vendor_tx_begin();
    try {
        db_query("SELECT `id` FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ? FOR UPDATE", [$listId]);
        if (vendor_effective_mode($list) === 'round_robin' && vendor_list_has_history($listId)) {
            vendor_tx_rollback($owned);
            return vendor_fail(409, 'order_follows_ledger',
                'In round-robin mode the order follows the dispatch history, so it cannot be reordered by hand. Use "Move to end" (audited) for a company that should wait.');
        }
        // On a list that already has history, reordering is a fairness-relevant act: it needs a reason and leaves a ledger
        // row (the audit log alone is purgeable), so a company cannot be walked to the top of a strict-order list unseen.
        $hasHistory = vendor_list_has_history($listId);
        if ($hasHistory && $reason === null) {
            vendor_tx_rollback($owned);
            return vendor_fail(422, 'reason_required', 'A reason is required to reorder a list that already has dispatch history.');
        }
        _vendor_member_renumber($listId);
        $rows = db_fetch_all("SELECT m.`id`, m.`provider_id`, m.`position`, p.`name` FROM `{$prefix}vendor_rotation_members` m
                                LEFT JOIN `{$prefix}vendor_providers` p ON p.`id` = m.`provider_id`
                               WHERE m.`list_id` = ? AND m.`removed_at` IS NULL ORDER BY m.`position`, m.`id`", [$listId]);
        $idx = null;
        foreach ($rows as $i => $r) { if ((int) $r['provider_id'] === $providerId) $idx = $i; }
        if ($idx === null) { vendor_tx_rollback($owned); return vendor_fail(404, 'not_found', 'That company is not on this list.'); }
        $swap = $direction === 'up' ? $idx - 1 : $idx + 1;
        if ($swap >= 0 && $swap < count($rows)) {
            db_query("UPDATE `{$prefix}vendor_rotation_members` SET `position` = ? WHERE `id` = ?", [(int) $rows[$swap]['position'], (int) $rows[$idx]['id']]);
            db_query("UPDATE `{$prefix}vendor_rotation_members` SET `position` = ? WHERE `id` = ?", [(int) $rows[$idx]['position'], (int) $rows[$swap]['id']]);
            if ($hasHistory) {
                vendor_ledger_insert([
                    'event_type' => 'order_changed', 'list_id' => $listId, 'provider_id' => $providerId,
                    'provider_name' => (string) ($rows[$idx]['name'] ?? ''), 'reason' => $reason,
                    'detail' => 'Moved ' . $direction . ' from place ' . ($idx + 1) . ' to place ' . ($swap + 1) . ' (swapped with "'
                                . (string) ($rows[$swap]['name'] ?? '') . '").',
                ], $actor);
            }
        }
        vendor_tx_commit($owned);
    } catch (Throwable $e) { vendor_tx_rollback($owned); return vendor_admin_err($e, 'vendor_member_move'); }
    vendor_admin_audit('member.move', 'vendor_list', $listId, 'Moved a company ' . $direction . ' on rotation list "' . $list['name'] . '"',
        ['provider_id' => $providerId, 'direction' => $direction, 'reason' => $reason], AUDIT_MEDIUM);
    return ['ok' => true];
}

/**
 * Move a company to the END of the rotation (the only "reordering" fairness allows). A reason is required. It writes
 * a moved_to_end ledger row that consumes a turn, so in round-robin the company waits behind everyone, and it sets
 * position to the end so strict-order lists agree.
 */
function vendor_member_move_to_end(int $listId, int $providerId, string $reason, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $reason = vendor_clean_str($reason, 255);
    if ($reason === null) return vendor_fail(422, 'reason_required', 'A reason is required to move a company to the end.');
    [$list, $fail] = _vendor_list_for_write($listId);
    if ($fail) return $fail;
    $p = vendor_provider_get($providerId);
    $owned = vendor_tx_begin();
    try {
        db_query("SELECT `id` FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ? FOR UPDATE", [$listId]);
        $m = db_fetch_one("SELECT * FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ? AND `provider_id` = ? AND `removed_at` IS NULL", [$listId, $providerId]);
        if (!$m) { vendor_tx_rollback($owned); return vendor_fail(404, 'not_found', 'That company is not on this list.'); }
        $max = (int) db_fetch_value("SELECT COALESCE(MAX(`position`), 0) FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ?", [$listId]);
        db_query("UPDATE `{$prefix}vendor_rotation_members` SET `position` = ? WHERE `id` = ?", [$max + 1, (int) $m['id']]);
        _vendor_member_renumber($listId);
        vendor_ledger_insert([
            'event_type' => 'moved_to_end', 'list_id' => $listId, 'provider_id' => $providerId,
            'provider_name' => $p ? $p['name'] : '', 'provider_phone' => $p ? $p['phone'] : null,
            'consumed_turn' => 1, 'reason' => $reason,
        ], $actor);
        vendor_tx_commit($owned);
    } catch (Throwable $e) { vendor_tx_rollback($owned); return vendor_admin_err($e, 'vendor_member_move_to_end'); }
    vendor_admin_audit('member.move_to_end', 'vendor_list', $listId, 'Moved "' . ($p ? $p['name'] : '#' . $providerId) . '" to the end of rotation list "' . $list['name'] . '"',
        ['provider_id' => $providerId, 'reason' => $reason], AUDIT_MEDIUM);
    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════════════
// Settings
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Save the vendor settings. The ONLY writer of these keys (the generic settings endpoint can store garbage, so
 * the readers clamp too). Enum-validated; the before/after of every key that changed is audited at AUDIT_MEDIUM.
 * Keys not present in $in are left untouched.
 */
function vendor_settings_save(array $in, array $actor): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (($deny = vendor_require_install_wide()) !== null) return $deny;
    $defs = vendor_setting_defs();
    $clean = [];
    foreach ($defs as $name => $def) {
        if (!array_key_exists($name, $in)) continue;
        $v = is_bool($in[$name]) ? ($in[$name] ? '1' : '0') : (string) $in[$name];
        if (!in_array($v, $def[1], true)) {
            return vendor_fail(422, 'validation', 'The value for "' . $name . '" is not one of: ' . implode(', ', $def[1]) . '.');
        }
        $clean[$name] = $v;
    }
    $before = []; $after = [];
    try {
        foreach ($clean as $name => $v) {
            $old = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
            $before[$name] = $old === false ? null : (string) $old;
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, $v]);
            $after[$name] = $v;
        }
    } catch (Throwable $e) { return vendor_admin_err($e, 'vendor_settings_save'); }
    $changed = [];
    foreach ($after as $name => $v) { if (($before[$name] ?? null) !== $v) $changed[$name] = ['before' => $before[$name] ?? null, 'after' => $v]; }
    if ($changed) {
        vendor_admin_audit('settings.update', 'vendor_settings', 'vendor', 'Updated towing / roadside dispatch settings', ['changed' => $changed], AUDIT_MEDIUM);
    }
    return ['ok' => true, 'changed' => array_keys($changed)];
}

/**
 * Current effective values (clamped to the default when the stored value is not allowed) for the Settings tab.
 * Read straight from the table, NOT through get_variable(): that caches the whole settings table for the life of the
 * process, so right after a save it would still show the value from before it.
 */
function vendor_settings_current(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $defs = vendor_setting_defs();
    $stored = [];
    try {
        $ph = implode(',', array_fill(0, count($defs), '?'));
        foreach (db_fetch_all("SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` IN ($ph)", array_keys($defs)) as $r) {
            $stored[$r['name']] = (string) $r['value'];
        }
    } catch (Throwable $e) { error_log('[vendor_settings_current] ' . $e->getMessage()); }
    $out = [];
    foreach ($defs as $name => $def) {
        $v = $stored[$name] ?? $def[0];
        $out[$name] = in_array($v, $def[1], true) ? $v : $def[0];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════
// Admin readers (the page and the overview endpoint)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * The organizations the caller may create providers/lists for: a Super Admin gets every active organization plus the
 * "all agencies" option; anyone else gets the organizations they can see (their own and its descendants).
 * @return array [orgs => [[id, name]...], can_all_agencies => bool]
 */
function vendor_admin_org_choices(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    vendor_load_org_scope();
    $all = vendor_org_unrestricted();
    $rows = [];
    try {
        $rows = db_fetch_all("SELECT `id`, `name` FROM `{$prefix}organizations` WHERE `active` = 1 ORDER BY `sort_order`, `name`");
    } catch (Throwable $e) { error_log('[vendor_admin_org_choices] ' . $e->getMessage()); }
    $vis = $all ? null : array_map('intval', (array) org_visible_ids());
    $out = [];
    foreach ($rows as $r) {
        if ($vis !== null && !in_array((int) $r['id'], $vis, true)) continue;
        $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
    }
    return ['orgs' => $out, 'can_all_agencies' => $all];
}

/**
 * Everything the admin page needs in one call: providers (with the lists each sits on, the services it offers -- DERIVED
 * from those lists, so there is no second place to keep in sync -- and whether it can be deleted), lists (with member
 * count and the computed next-up so a supervisor sees the rotation state at a glance), service types and counts.
 */
function vendor_admin_overview(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $types = db_fetch_all("SELECT * FROM `{$prefix}vendor_service_types` ORDER BY `sort_order`, `id`");
    $typeById = [];
    foreach ($types as $i => $t) {
        $types[$i] = ['id' => (int) $t['id'], 'code' => $t['code'], 'label' => $t['label'],
                      'needs_destination' => (int) $t['needs_destination'], 'is_active' => (int) $t['is_active'], 'sort_order' => (int) $t['sort_order']];
        $typeById[(int) $t['id']] = $t['label'];
    }

    [$lf, $lv] = vendor_org_frag('l.`org_id`');
    $lists = db_fetch_all(
        "SELECT l.* FROM `{$prefix}vendor_rotation_lists` l WHERE 1=1" . $lf . " ORDER BY l.`is_active` DESC, l.`sort_order`, l.`name`, l.`id`", $lv);
    $listOut = [];
    $listNamesByProvider = []; $servicesByProvider = [];
    foreach ($lists as $l) {
        $q = vendor_rotation_queue((int) $l['id']);
        $head = null; $headAt = null;
        foreach ($q['candidates'] as $c) {
            if ($c['is_head']) { $head = $c['name']; $headAt = $c['last_turn_at']; }
            $listNamesByProvider[$c['provider_id']][] = $l['name'];
            $servicesByProvider[$c['provider_id']][$typeById[(int) $l['service_type_id']] ?? ''] = true;
        }
        $listOut[] = [
            'id' => (int) $l['id'], 'name' => $l['name'], 'org_id' => $l['org_id'] !== null ? (int) $l['org_id'] : null,
            'service_type_id' => (int) $l['service_type_id'], 'service_label' => $typeById[(int) $l['service_type_id']] ?? '',
            'description' => $l['description'], 'mode' => $l['mode'], 'effective_mode' => $q['mode'],
            'is_default' => (int) $l['is_default'], 'is_active' => (int) $l['is_active'], 'sort_order' => (int) $l['sort_order'],
            'member_count' => count($q['candidates']), 'next_up_name' => $head, 'next_up_last_turn_at' => $headAt,
            'can_edit' => vendor_org_writable($l['org_id'] !== null ? (int) $l['org_id'] : null),
        ];
    }

    [$pf, $pv] = vendor_org_frag('p.`org_id`');
    $providers = db_fetch_all("SELECT p.* FROM `{$prefix}vendor_providers` p WHERE 1=1" . $pf . " ORDER BY p.`is_active` DESC, p.`name`, p.`id`", $pv);
    $provOut = [];
    $overviewNowTs = vendor_db_now_ts();
    foreach ($providers as $p) {
        $pid = (int) $p['id'];
        $suspended = $p['suspended_until'] !== null && strtotime((string) $p['suspended_until']) > $overviewNowTs;
        $provOut[] = [
            'id' => $pid, 'name' => $p['name'], 'org_id' => $p['org_id'] !== null ? (int) $p['org_id'] : null,
            'contact_name' => $p['contact_name'], 'phone' => $p['phone'], 'phone_alt' => $p['phone_alt'],
            'service_area' => $p['service_area'], 'hours_note' => $p['hours_note'], 'notes' => $p['notes'],
            'yard_facility_id' => $p['yard_facility_id'] !== null ? (int) $p['yard_facility_id'] : null,
            'is_active' => (int) $p['is_active'], 'suspended' => $suspended, 'suspended_until' => $suspended ? $p['suspended_until'] : null,
            'suspend_reason' => $suspended ? $p['suspend_reason'] : null, 'updated_at' => $p['updated_at'],
            'lists' => array_values(array_unique($listNamesByProvider[$pid] ?? [])),
            'services' => array_values(array_filter(array_keys($servicesByProvider[$pid] ?? []))),
            'can_delete' => !vendor_provider_referenced($pid),
            'can_edit' => vendor_org_writable($p['org_id'] !== null ? (int) $p['org_id'] : null),
        ];
    }

    $orgChoices = vendor_admin_org_choices();
    $orgNames = [];
    foreach ($orgChoices['orgs'] as $o) $orgNames[$o['id']] = $o['name'];
    foreach ($provOut as $i => $p) $provOut[$i]['org_name'] = $p['org_id'] === null ? 'All agencies' : ($orgNames[$p['org_id']] ?? ('#' . $p['org_id']));
    foreach ($listOut as $i => $l) $listOut[$i]['org_name'] = $l['org_id'] === null ? 'All agencies' : ($orgNames[$l['org_id']] ?? ('#' . $l['org_id']));

    return [
        'service_types' => $types, 'lists' => $listOut, 'providers' => $provOut,
        'orgs' => $orgChoices['orgs'], 'can_all_agencies' => $orgChoices['can_all_agencies'],
        'counts' => ['providers' => count($provOut), 'lists' => count($listOut)],
        'settings' => vendor_settings_current(),
    ];
}

/** One list with its full ordered queue (members, last-turn times, next-up) for the editor. Null if not visible. */
function vendor_admin_list_detail(int $listId): ?array
{
    $list = vendor_list_get($listId);
    if (!$list || !vendor_org_visible($list['org_id'] !== null ? (int) $list['org_id'] : null)) return null;
    $q = vendor_rotation_queue($listId);
    $members = [];
    foreach ($q['candidates'] as $c) {
        $members[] = [
            'provider_id' => $c['provider_id'], 'name' => $c['name'], 'phone' => $c['phone'], 'contact_name' => $c['contact_name'],
            'position' => $c['position'], 'rank' => $c['rank'], 'eligible' => $c['eligible'], 'is_head' => $c['is_head'],
            'last_turn_at' => $c['last_turn_at'],
            'is_suspended' => (int) $c['is_suspended'] === 1, 'suspended_until' => (int) $c['is_suspended'] === 1 ? $c['suspended_until'] : null,
            'is_active' => (int) $c['is_active'],
        ];
    }
    return ['list_id' => $listId, 'mode' => $q['mode'], 'has_history' => vendor_list_has_history($listId),
            'order_locked' => ($q['mode'] === 'round_robin' && vendor_list_has_history($listId)), 'members' => $members,
            'head_provider_id' => $q['head_provider_id']];
}
