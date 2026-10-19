<?php
/**
 * GH#154 — every built-in FEMA form's code is reserved for new custom types,
 * and a custom type that ALREADY holds a code which has since been reserved
 * keeps working: it resolves, its forms save, reopen and print, and it can
 * still be edited (its immutable slug is grandfathered).
 *
 * The grandfathered type is inserted directly (as an import, a hand edit or an
 * older build could have left it) — the create path would refuse it, which is
 * the point — and then driven through the REAL endpoints:
 * api/ics-form-types.php (update) and api/ics-forms.php (save, GET, print).
 *
 * @requires-db
 */
require_once __DIR__ . '/_gh154_helpers.php';
require_once __DIR__ . '/_test_admin.php';

echo "=== GH#154 — reserved form codes and grandfathered custom types ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. The reserved list is the built-in list plus 'custom' ──────────────
$reserved = ics_form_type_reserved_slugs();
$builtins = ics_builtin_slugs();
gh154_t('every built-in form code is reserved (' . count($builtins) . ' codes)',
    array_diff($builtins, $reserved) === []);
gh154_t("'custom' itself is reserved", in_array('custom', $reserved, true));
$notRefused = [];
foreach ($reserved as $slug) {
    if (ics_form_type_check_impersonation($slug, '')['valid']) $notRefused[] = $slug;
}
gh154_t('the reserved-code check refuses every reserved code', $notRefused === [], implode(', ', $notRefused));
$m = ics_form_type_validate_metadata(['slug' => 'custom', 'form_title' => 'X'], null);
gh154_t('creating a custom type with a reserved code is refused with the reserved-code message',
    !$m['valid'] && strpos(implode(' ', $m['errors']), 'reserved') !== false, implode(' ', $m['errors']));

if (!ics_forms_has_custom_type_columns(true)) {
    echo "SKIP: custom ICS form types are not installed on this database\n";
    gh154_done();
}

// ── 2. A custom type created before its code became reserved ────────────
$gslug = in_array('201', $builtins, true) ? '201' : '221';
$admin = test_admin_user_id();
$fields = [['key' => 'subject_line', 'label' => 'Subject line', 'type' => 'text', 'required' => false]];
db_query("DELETE FROM `{$prefix}ics_form_types` WHERE `slug` = ? AND `org_id` IS NULL AND `form_title` LIKE 'GH154 grandfathered%'", [$gslug]);
db_query(
    "INSERT INTO `{$prefix}ics_form_types` (`slug`, `form_number`, `form_title`, `description`, `fields_json`, `badge_color`, `icon`, `org_id`, `status`, `created_by`, `created_by_name`)
     VALUES (?, ?, ?, '', ?, 'secondary', 'bi-file-earmark-text', NULL, 'active', ?, 'gh154')",
    [$gslug, 'AGENCY ' . $gslug, 'GH154 grandfathered agency form', json_encode($fields), $admin]
);
$typeId = (int) db_insert_id();
$formIds = [];
register_shutdown_function(function () use ($prefix, $typeId, &$formIds) {
    try {
        if ($formIds) {
            $in = implode(',', array_map('intval', $formIds));
            db_query("DELETE FROM `{$prefix}ics_forms` WHERE `id` IN ({$in})");
            db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ics_forms' AND `target_id` IN ({$in})");
        }
        db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ics_form_types' AND `target_id` = ?", [(string) $typeId]);
        db_query("DELETE FROM `{$prefix}ics_form_types` WHERE `id` = ?", [$typeId]);
    } catch (Throwable $e) {
        error_log('[gh154 reserved cleanup] ' . $e->getMessage());
    }
});
gh154_t("fixture: a custom type holding the now-reserved code '{$gslug}'", $typeId > 0);

$v = ics_form_type_validate_metadata(['slug' => $gslug, 'form_title' => 'GH154 grandfathered agency form'], $gslug);
gh154_t('editing it is allowed: its own (immutable) code is grandfathered', $v['valid'], implode(' ', $v['errors']));
$v = ics_form_type_validate_metadata(['slug' => $gslug, 'form_title' => 'X', 'form_number' => 'ICS-201'], $gslug);
gh154_t('...the form-number rule still applies to what the author types now', !$v['valid']);
$v = ics_form_type_validate_metadata(['slug' => $gslug . 'x', 'form_title' => 'X'], $gslug);
gh154_t('...and the code still cannot be changed', !$v['valid']);
$v = ics_form_type_validate_metadata(['slug' => $gslug, 'form_title' => 'X'], null);
gh154_t('...while a NEW type may not take that code', !$v['valid']);

// Through the real authoring endpoint.
$upd = gh154_probe('POST', $admin, json_encode([
    'action' => 'update', 'id' => $typeId, 'slug' => $gslug, 'form_number' => 'AGENCY ' . $gslug,
    'form_title' => 'GH154 grandfathered agency form (edited)', 'badge_color' => 'secondary',
    'icon' => 'bi-file-earmark-text', 'fields' => $fields,
]), 'api/ics-form-types.php');
gh154_t('api/ics-form-types.php action=update saves the grandfathered type', ($upd['status'] ?? 0) === 200 && !empty($upd['body']['ok']),
    json_encode($upd));

// Its forms: save, reopen, print.
$tpl = gh154_probe('GET', $admin, 'template=custom&custom_type_id=' . $typeId);
gh154_t('its template still resolves (GET ?template=custom&custom_type_id=)', ($tpl['body']['slug'] ?? '') === $gslug, json_encode($tpl));
$b = gh154_probe('GET', $admin, 'template=' . $gslug);
gh154_t("...while ?template={$gslug} is the built-in FEMA form, untouched by it", ($b['body']['form_type'] ?? '') === $gslug
    && ($b['body']['custom_type_id'] ?? null) === null);
$save = gh154_probe('POST', $admin, json_encode(['action' => 'save', 'form_type' => 'custom', 'custom_type_id' => $typeId,
    'title' => 'GH154 grandfathered form', 'form_data' => ['subject_line' => 'GH154 kept working']]));
$fid = (int) ($save['body']['id'] ?? 0);
if ($fid > 0) $formIds[] = $fid;
gh154_t('a form of the grandfathered type saves', ($save['status'] ?? 0) === 200 && $fid > 0, json_encode($save));
$get = gh154_probe('GET', $admin, 'id=' . $fid);
gh154_t('...reopens as a custom form with its value', ($get['body']['form_type'] ?? '') === 'custom'
    && ($get['body']['form_data']['subject_line'] ?? '') === 'GH154 kept working');
$pdf = gh154_probe('POST', $admin, json_encode(['action' => 'export_pdf', 'id' => $fid]));
$html = (string) ($pdf['body']['html'] ?? '');
gh154_t('...and prints as itself (its own number, its own field), not as the built-in form',
    strpos($html, '<h1>AGENCY ' . strtoupper($gslug) . '</h1>') !== false && strpos($html, 'GH154 kept working') !== false);

gh154_done();
