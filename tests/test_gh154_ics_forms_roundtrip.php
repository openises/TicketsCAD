<?php
/**
 * GH#154 — create → save → reload → print, for EVERY built-in ICS form type,
 * through the REAL api/ics-forms.php (tests/_gh154_ics_api_probe.php includes
 * the endpoint file with a real session and CSRF token; no web server).
 *
 * For each type: the blank template comes from GET ?template=, a form with
 * every field and table cell filled in is saved with POST action=save, read
 * back with GET ?id= (every value must come back), printed with POST
 * action=export_pdf (FEMA heading, block titles and every value on paper),
 * and the save must have written an audit entry. Then, for the two forms in
 * the issue, a form stored in the OLD shape (as the previous release wrote it)
 * is read back through the same endpoint: GET ?id= returns it mapped onto the
 * FEMA fields, and the printout shows the old values. ICS 213's Winlink export
 * runs through the endpoint too.
 *
 * @requires-db
 */
require_once __DIR__ . '/_gh154_helpers.php';
require_once __DIR__ . '/_test_admin.php';

echo "=== GH#154 — every built-in ICS form, through the real endpoint ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    db_fetch_value("SELECT 1 FROM `{$prefix}ics_forms` LIMIT 1");
} catch (Throwable $e) {
    echo "SKIP: ics_forms table missing\n";
    gh154_done();
}
$admin = test_admin_user_id();
$created = [];
register_shutdown_function(function () use (&$created, $prefix) {
    if (!$created) return;
    $in = implode(',', array_map('intval', $created));
    try {
        db_query("DELETE FROM `{$prefix}ics_forms` WHERE `id` IN ({$in})");
        db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ics_forms' AND `target_id` IN ({$in})");
    } catch (Throwable $e) {
        error_log('[gh154 roundtrip cleanup] ' . $e->getMessage());
    }
});

$ref = require __DIR__ . '/_gh154_fema_reference.php';

foreach (ics_builtin_slugs() as $i => $slug) {
    $tag = 'r' . $i;
    $tplResp = gh154_probe('GET', $admin, 'template=' . rawurlencode($slug));
    $tpl = $tplResp['body'] ?? null;
    gh154_t("ICS {$slug}: GET ?template= returns its template", ($tplResp['status'] ?? 0) === 200
        && is_array($tpl) && ($tpl['form_type'] ?? '') === $slug, json_encode($tplResp));
    if (!is_array($tpl) || empty($tpl['fields'])) {
        continue;
    }

    $data = gh154_sample_data($tpl, $tag);
    $save = gh154_probe('POST', $admin, json_encode([
        'action' => 'save', 'form_type' => $slug, 'title' => 'GH154 roundtrip ' . $slug,
        'status' => 'draft', 'form_data' => $data,
    ]));
    $id = (int) ($save['body']['id'] ?? 0);
    if ($id > 0) $created[] = $id;
    gh154_t("ICS {$slug}: saved through action=save", ($save['status'] ?? 0) === 200 && $id > 0, json_encode($save));
    if ($id <= 0) {
        continue;
    }

    $get = gh154_probe('GET', $admin, 'id=' . $id);
    $back = $get['body']['form_data'] ?? null;
    $lost = [];
    if (is_array($back)) {
        foreach ($data as $k => $v) {
            if (json_encode($back[$k] ?? null) !== json_encode($v)) $lost[] = $k;
        }
    }
    gh154_t("ICS {$slug}: GET ?id= returns every value that was saved", is_array($back) && $lost === [],
        'differs: ' . implode(', ', $lost));

    $pdf = gh154_probe('POST', $admin, json_encode(['action' => 'export_pdf', 'id' => $id]));
    $html = (string) ($pdf['body']['html'] ?? '');
    $number = (string) ($tpl['form_number'] ?? '');
    gh154_t("ICS {$slug}: export_pdf prints the FEMA heading \"... ({$number})\"",
        $html !== '' && strpos($html, '<h1>' . htmlspecialchars(($tpl['print_title'] ?? '') . ' (' . $number . ')', ENT_QUOTES, 'UTF-8') . '</h1>') !== false);
    $missing = [];
    foreach (gh154_sentinels($data, $tag) as $s) {
        if (strpos($html, $s) === false) $missing[] = $s;
    }
    gh154_t("ICS {$slug}: every value is on the printout", $html !== '' && $missing === [],
        'missing: ' . implode(', ', array_slice($missing, 0, 6)));
    if (!empty($ref[$slug]['blocks'])) {
        $noBlock = [];
        foreach (ics_form_template_blocks($tpl) as $b) {
            if (strpos($html, htmlspecialchars($b['block'] . '. ', ENT_QUOTES, 'UTF-8')) === false) $noBlock[] = $b['block'];
        }
        gh154_t("ICS {$slug}: every FEMA block number appears on the printout", $noBlock === [], implode(',', $noBlock));
    }
    gh154_t("ICS {$slug}: the printout carries the FEMA footer (\"{$number}\")",
        strpos($html, '<table class="foot"><tr><td>' . htmlspecialchars($number, ENT_QUOTES, 'UTF-8') . '</td>') !== false);

    $audit = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ics_forms' AND `target_id` = ? AND `activity` = 'create'",
        [(string) $id]
    );
    gh154_t("ICS {$slug}: the save wrote an audit entry", $audit === 1);
}

// ── Update path: a second save with the id updates, and is audited ──────
$t214 = ics_builtin_template('214');
$d = gh154_sample_data($t214, 'u');
$s1 = gh154_probe('POST', $admin, json_encode(['action' => 'save', 'form_type' => '214', 'title' => 'GH154 update', 'form_data' => $d]));
$uid214 = (int) ($s1['body']['id'] ?? 0);
if ($uid214 > 0) $created[] = $uid214;
$d['name'] = 'Changed Name';
$s2 = gh154_probe('POST', $admin, json_encode(['action' => 'save', 'id' => $uid214, 'form_type' => '214', 'title' => 'GH154 update', 'form_data' => $d]));
$g = gh154_probe('GET', $admin, 'id=' . $uid214);
gh154_t('ICS 214: re-saving with the id updates the form', ($s2['body']['id'] ?? 0) === $uid214
    && ($g['body']['form_data']['name'] ?? '') === 'Changed Name');
gh154_t('ICS 214: ...and the update is audited', (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `target_type` = 'ics_forms' AND `target_id` = ? AND `activity` = 'update'",
    [(string) $uid214]) === 1);

// ── Forms stored in the OLD shape come back mapped ──────────────────────
$insertOld = function (string $type, array $data) use ($prefix, $admin, &$created): int {
    db_query("INSERT INTO `{$prefix}ics_forms` (`form_type`, `title`, `form_data_json`, `created_by`, `created_by_name`, `status`)
              VALUES (?, ?, ?, ?, 'gh154', 'draft')",
        [$type, 'GH154 old shape ' . $type, json_encode($data), $admin]);
    $id = (int) db_insert_id();
    $created[] = $id;
    return $id;
};
$o205 = $insertOld('205', ['incident_name' => 'Old Flood', 'radio_channels' => [
    ['zone_group' => 'Z9', 'channel' => '4', 'function' => 'Tac', 'frequency_rx' => '151.4300',
     'frequency_tx' => '159.4500', 'tone' => '136.5', 'assignment' => 'Div B']]]);
$g = gh154_probe('GET', $admin, 'id=' . $o205);
$row = $g['body']['form_data']['radio_channels'][0] ?? [];
gh154_t('an ICS 205 saved by the previous release opens with its Tone/NAC in both RX and TX Tone/NAC',
    ($row['rx_tone'] ?? '') === '136.5' && ($row['tx_tone'] ?? '') === '136.5' && ($row['frequency_tx'] ?? '') === '159.4500');
$stored = json_decode((string) db_fetch_value("SELECT `form_data_json` FROM `{$prefix}ics_forms` WHERE `id` = ?", [$o205]), true);
gh154_t('...and opening it did not rewrite what is stored', !isset($stored['radio_channels'][0]['rx_tone']));
$p = gh154_probe('POST', $admin, json_encode(['action' => 'export_pdf', 'id' => $o205]));
gh154_t('...and it prints with the old tone in both Tone/NAC columns', substr_count((string) ($p['body']['html'] ?? ''), '>136.5<') === 2);

$o214 = $insertOld('214', ['incident_name' => 'Old Flood', 'team_name' => 'Rescue 2', 'team_leader_name' => 'A. Lee',
    'team_leader_position' => 'Team Leader', 'activity_log' => [['time' => '08:15', 'activity' => 'Briefed crew']]]);
$g = gh154_probe('GET', $admin, 'id=' . $o214);
$fd = $g['body']['form_data'] ?? [];
gh154_t('an ICS 214 saved by the previous release opens with the team as 3. Name and the leader as 4. ICS Position (FEMA\x27s block meanings)',
    ($fd['name'] ?? '') === 'Rescue 2' && ($fd['ics_position'] ?? '') === 'A. Lee, Team Leader'
    && ($fd['home_agency'] ?? '') === '', json_encode($fd));
$p = gh154_probe('POST', $admin, json_encode(['action' => 'export_pdf', 'id' => $o214]));
$html = (string) ($p['body']['html'] ?? '');
gh154_t('...and it prints with those values and its time-only log entry',
    strpos($html, 'A. Lee, Team Leader') !== false && strpos($html, 'Rescue 2') !== false && strpos($html, '08:15') !== false
    && strpos($html, 'Briefed crew') !== false);

// ── ICS 213 Winlink export through the endpoint ─────────────────────────
$x = gh154_probe('POST', $admin, json_encode(['action' => 'save', 'form_type' => '213', 'title' => 'GH154 winlink',
    'form_data' => ['to_name' => 'GH154 To', 'from_name' => 'GH154 From', 'subject' => 'GH154 Subj', 'message' => 'GH154 body',
                    'incident_name' => 'GH154 Inc', 'reply_position' => 'GH154 RP']]));
$xid = (int) ($x['body']['id'] ?? 0);
if ($xid > 0) $created[] = $xid;
$xml = gh154_probe('POST', $admin, json_encode(['action' => 'export_xml', 'id' => $xid]));
$xs = (string) ($xml['body']['xml'] ?? '');
gh154_t('ICS 213 export_xml still answers with the same RMS Express form fields',
    strpos($xs, '<to>GH154 To</to>') !== false && strpos($xs, '<subject>GH154 Subj</subject>') !== false
    && strpos($xs, '<reply_position></reply_position>') !== false && strpos($xs, 'GH154 Inc') === false);
$bad = gh154_probe('POST', $admin, json_encode(['action' => 'export_xml', 'id' => $uid214]));
gh154_t('export_xml is still refused for a non-213 form', ($bad['status'] ?? 0) === 400);

// ── Validation: an unknown form_type is still refused ───────────────────
$bad = gh154_probe('POST', $admin, json_encode(['action' => 'save', 'form_type' => '999', 'title' => 'x', 'form_data' => []]));
gh154_t('save refuses a form_type that is not a built-in or custom type', ($bad['status'] ?? 0) === 400);

gh154_done();
