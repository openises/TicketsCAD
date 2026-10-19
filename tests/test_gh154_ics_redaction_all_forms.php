<?php
/**
 * GH#154 — the security-label watermark and redaction (Phase 18f,
 * _ics_apply_security_wrap() in api/ics-forms.php) still work on the printout
 * of EVERY built-in ICS form, table cells included.
 *
 * Before GH#154 the per-form printers wrote table cells bare (<td>value</td>),
 * outside the <span class="value"> the redactor matches, so a label set to
 * hide content left radio channels, hospitals and activity-log entries in the
 * export. The shared renderer puts every value in that span; this test fills
 * every field and cell of every form with a distinct value and proves none of
 * them survives redaction, and that the watermark is applied.
 *
 * The REAL _ics_apply_security_wrap() is read out of api/ics-forms.php (the
 * same way tests/test_ics_forms_redaction.php does it). Pure PHP.
 */
require_once __DIR__ . '/_gh154_helpers.php';

echo "=== GH#154 — redaction and watermark on every built-in form ===\n\n";

$src = (string) file_get_contents(__DIR__ . '/../api/ics-forms.php');
if (!preg_match('/function _ics_apply_security_wrap\(.*?\n\}\n/s', $src, $m)) {
    gh154_t('found _ics_apply_security_wrap() in api/ics-forms.php', false);
    gh154_done();
}
eval($m[0]); // test-only: the endpoint's own redaction function, copied from the file under test

$label = ['ics_export_show_full' => 0, 'name' => 'Restricted', 'ics_watermark_text' => 'RESTRICTED'];
foreach (ics_builtin_slugs() as $i => $slug) {
    $tpl = ics_builtin_template($slug);
    $data = gh154_sample_data($tpl, 'x' . $i);
    $html = generatePrintHtml($slug, $data, ['id' => 1, 'title' => 'Redaction test']);
    $sentinels = gh154_sentinels($data, 'x' . $i);
    $printed = 0;
    foreach ($sentinels as $s) {
        if (strpos($html, $s) !== false) $printed++;
    }
    gh154_t("ICS {$slug}: unredacted, the printout carries every value (" . count($sentinels) . ')',
        $sentinels !== [] && $printed === count($sentinels));
    $wrapped = _ics_apply_security_wrap($html, $label);
    $leaked = [];
    foreach ($sentinels as $s) {
        if (strpos($wrapped, $s) !== false) $leaked[] = $s;
    }
    gh154_t("ICS {$slug}: with a hide-content label, no value survives — table cells included", $leaked === [],
        'leaked: ' . implode(', ', array_slice($leaked, 0, 5)));
    gh154_t("ICS {$slug}: the label's watermark is applied", strpos($wrapped, 'class="p18-watermark"') !== false
        && strpos($wrapped, '*** Restricted ***') !== false);
}

gh154_done();
