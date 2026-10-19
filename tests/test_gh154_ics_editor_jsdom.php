<?php
/**
 * GH#154 — the ICS forms editor, end to end, for every built-in form.
 *
 * The REAL page (ics-forms.php, rendered as the administrator), the REAL
 * script (assets/js/ics-forms.js) and the REAL templates run in jsdom
 * (tests/_gh154_ics_editor_harness.cjs). For every built-in type the harness
 * clicks New, links an incident through the search box as a person would,
 * fills every input and presses Save. This test then checks what the editor
 * drew, what it pre-filled from the incident, and REPLAYS every request body
 * it sent against the real api/ics-forms.php: each must save, read back intact
 * and print. Forms stored in the old shape are opened from the endpoint's own
 * GET ?id= answer and saved back, proving the mapped values reach the new
 * fields.
 *
 * Needs Node and the jsdom package (CI installs it); without them it prints
 * SKIP after the structural checks.
 *
 * @requires-db
 */
require_once __DIR__ . '/_gh154_helpers.php';
require_once __DIR__ . '/_test_admin.php';

echo "=== GH#154 — the editor in jsdom, every built-in form ===\n\n";

$root = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$js = (string) file_get_contents($root . '/assets/js/ics-forms.js');
$code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
gh154_t('ics-forms.js is still ES5 (no arrow functions, let, const or template literals)',
    !preg_match('/=>|\blet\s|\bconst\s|`/', $code));

$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
$node = $node === '' ? '' : strtok($node, "\r\n");
$jsdomOk = false;
if ($node !== '') {
    $probe = @shell_exec('"' . $node . '" -e "require(\'jsdom\');console.log(\'ok\')" 2>&1');
    $jsdomOk = is_string($probe) && strpos($probe, 'ok') !== false;
}
if (!$jsdomOk) {
    echo "SKIP: needs Node + the jsdom package (npm install jsdom); the structural check above still ran\n";
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
        error_log('[gh154 jsdom cleanup] ' . $e->getMessage());
    }
});

// The real page, as the administrator.
$proc = proc_open([PHP_BINARY ?: 'php', __DIR__ . '/_p155_page_probe.php', 'ics-forms.php', (string) $admin],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
$pageOut = stream_get_contents($pipes[1]);
fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
$page = json_decode((string) $pageOut, true);
$html = (string) ($page['body'] ?? '');
gh154_t('ics-forms.php renders for the administrator', ($page['status'] ?? 0) === 200 && strpos($html, 'id="hubSection"') !== false);

$slugs = ics_builtin_slugs();
$templates = [];
foreach ($slugs as $s) {
    $templates[$s] = ics_builtin_template($s);
    gh154_t("the hub has a New button for ICS {$s}", strpos($html, 'data-new-form="' . $s . '"') !== false);
}

// Forms stored in the OLD shape, opened through the real endpoint.
$insertOld = function (string $type, array $data) use ($prefix, $admin, &$created): int {
    db_query("INSERT INTO `{$prefix}ics_forms` (`form_type`, `title`, `form_data_json`, `created_by`, `created_by_name`, `status`)
              VALUES (?, ?, ?, ?, 'gh154', 'draft')", [$type, 'GH154 jsdom old ' . $type, json_encode($data), $admin]);
    $id = (int) db_insert_id();
    $created[] = $id;
    return $id;
};
$old = [
    '205' => ['incident_name' => 'Old Flood', 'radio_channels' => [['channel' => '4', 'frequency_rx' => '151.4300', 'tone' => '136.5']]],
    '214' => ['incident_name' => 'Old Flood', 'team_name' => 'Rescue 2', 'team_leader_name' => 'A. Lee',
              'team_leader_position' => 'Team Leader', 'activity_log' => [['time' => '08:15', 'activity' => 'Briefed crew']]],
    '206' => ['aid_stations' => [['name' => 'Aid 1', 'paramedics' => 'Maybe']],
              'transportation' => [['service' => 'Medic 1', 'paramedics' => 'Y']]],
    '213rr' => ['to_name' => 'Logistics Chief', 'resource_requests' => [['qty' => '2', 'kind' => 'Engine', 'priority' => 'High']]],
    '221' => ['chk_supply' => 'Y', 'chk_communications' => 'radio #44 returned', 'leader_name' => 'C. Diaz'],
];
$saved = [];
$oldIds = [];
foreach ($old as $type => $data) {
    $id = $insertOld($type, $data);
    $oldIds[$type] = $id;
    $g = gh154_probe('GET', $admin, 'id=' . $id);
    $saved[(string) $id] = $g['body'];
}

$fx = [
    'html' => $html, 'slugs' => $slugs, 'templates' => $templates, 'saved' => $saved,
    'incident' => ['id' => 4242, 'scope' => 'GH154 Flood', 'incident_number' => '2026-0042', 'type_name' => 'Flood',
                   'street' => '1 Main St', 'city' => 'Testville', 'state' => 'MN',
                   'problemstart' => '2026-10-19 07:45:00', 'description' => 'GH154 fixture'],
];
$fxFile = tempnam(sys_get_temp_dir(), 'gh154fx');
file_put_contents($fxFile, json_encode($fx));
$errFile = tempnam(sys_get_temp_dir(), 'gh154err');
$proc = proc_open([$node, __DIR__ . '/_gh154_ics_editor_harness.cjs', $root, $fxFile],
    [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, null, ['bypass_shell' => true]);
$raw = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
if (is_resource($proc)) { fclose($pipes[1]); proc_close($proc); }
@unlink($fxFile);
$run = json_decode(trim((string) $raw), true);
if (!is_array($run)) {
    echo "harness output was not JSON:\n" . substr((string) $raw, 0, 800) . "\n" . substr((string) @file_get_contents($errFile), 0, 800) . "\n";
}
@unlink($errFile);
gh154_t('the jsdom harness ran with no script error', is_array($run) && empty($run['errors']),
    is_array($run) ? implode(' | ', array_slice($run['errors'], 0, 3)) : '');
if (!is_array($run)) {
    gh154_done();
}

// "Find a form"
gh154_t('Find a form: "radio communications" leaves the radio forms visible',
    in_array('205', $run['find']['visible'] ?? [], true) && !in_array('214', $run['find']['visible'] ?? [], true),
    json_encode($run['find']['visible'] ?? []));
gh154_t('Find a form: Enter opens the first match', strpos((string) ($run['find']['opened'] ?? ''), 'ICS 205') === 0,
    (string) ($run['find']['opened'] ?? ''));
gh154_t('Find a form: Escape shows every card again', !empty($run['find']['clearedAll']));

$nowShape = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';
foreach ($slugs as $s) {
    $r = $run['types'][$s] ?? [];
    $tpl = $templates[$s];
    gh154_t("ICS {$s}: New opens the editor titled \"{$tpl['form_number']} — {$tpl['form_title']}\"",
        !empty($r['opened']) && ($r['title'] ?? '') === $tpl['form_number'] . ' — ' . $tpl['form_title'], (string) ($r['title'] ?? ''));
    gh154_t("ICS {$s}: every input and table of the template is drawn", ($r['missing'] ?? ['?']) === [],
        implode(', ', $r['missing'] ?? []));
    $sigs = count(array_filter($tpl['fields'], function ($f) { return ($f['type'] ?? '') === 'signature'; }));
    if ($sigs) gh154_t("ICS {$s}: its {$sigs} signature line(s) are shown as notes, not inputs", ($r['signatures'] ?? -1) === $sigs);
    foreach ($tpl['fields'] as $f) {
        if (!empty($f['fixed_rows'])) {
            gh154_t("ICS {$s}: {$f['key']} has exactly its " . count($f['fixed_rows']) . ' labelled rows',
                ($r['fixedRows'][$f['key']] ?? -1) === count($f['fixed_rows']));
        }
        if (!empty($f['default_data'])) {
            gh154_t("ICS {$s}: a new form starts {$f['key']} with FEMA's " . count($f['default_data']) . ' rows',
                ($r['defaultRows'][$f['key']] ?? -1) === count($f['default_data']));
        }
    }

    // Pre-fill from the linked incident.
    $p = $r['prefill'] ?? [];
    $keys = [];
    foreach ($tpl['fields'] as $f) if (isset($f['key'])) $keys[$f['key']] = $f;
    $bad = [];
    if (isset($keys['incident_name']) && ($p['incident_name'] ?? '') !== 'GH154 Flood') $bad[] = 'incident_name';
    if (isset($keys['incident_number']) && ($p['incident_number'] ?? '') !== '2026-0042') $bad[] = 'incident_number';
    if (isset($keys['op_period_from']) && ($p['op_period_from'] ?? '') !== '2026-10-19T07:45') $bad[] = 'op_period_from';
    foreach (['date_prepared', 'prepared_datetime'] as $k) {
        if (!isset($keys[$k])) continue;
        $type = $keys[$k]['type'];
        $v = (string) ($p[$k] ?? '');
        $ok = $type === 'date' ? (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) : (bool) preg_match($nowShape, $v);
        if (!$ok) $bad[] = $k;
    }
    // Per-form extras the template declares (`prefill`: key -> source).
    $expectFrom = ['incident_start' => '2026-10-19T07:45', 'incident_type' => 'Flood', 'incident_state' => 'MN',
                   'incident_city' => 'Testville', 'incident_location' => '1 Main St, Testville, MN'];
    foreach ($tpl['prefill'] ?? [] as $k => $src) {
        if (!isset($keys[$k])) { $bad[] = $k . ' (not a field)'; continue; }
        $v = (string) ($p[$k] ?? '');
        if ($src === 'now') {
            $ok = $keys[$k]['type'] === 'time' ? (bool) preg_match('/^\d{2}:\d{2}$/', $v)
                : ($keys[$k]['type'] === 'date' ? (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) : (bool) preg_match($nowShape, $v));
        } else {
            $ok = $v === ($expectFrom[$src] ?? "\0");
        }
        if (!$ok) $bad[] = $k . '<-' . $src;
    }
    gh154_t("ICS {$s}: linking an incident pre-fills its name, number, start, and today's date/time", $bad === [],
        'not filled: ' . implode(', ', $bad) . ' / got ' . json_encode($p));
    gh154_t("ICS {$s}: ...and the form title", ($r['formTitle'] ?? '') === 'GH154 Flood');

    // Replay what the editor sent against the real endpoint.
    $payload = $r['payload'] ?? null;
    gh154_t("ICS {$s}: Save sent action=save for this form type", is_array($payload) && ($payload['action'] ?? '') === 'save'
        && ($payload['form_type'] ?? '') === $s && (string) ($payload['incident_id'] ?? '') === '4242');
    if (!is_array($payload)) continue;
    unset($payload['csrf_token'], $payload['incident_id']);
    $sv = gh154_probe('POST', $admin, json_encode($payload));
    $id = (int) ($sv['body']['id'] ?? 0);
    if ($id > 0) $created[] = $id;
    $g = gh154_probe('GET', $admin, 'id=' . $id);
    $back = $g['body']['form_data'] ?? [];
    $diff = [];
    foreach ($payload['form_data'] as $k => $v) {
        if (json_encode($back[$k] ?? null) !== json_encode($v)) $diff[] = $k;
    }
    gh154_t("ICS {$s}: the real endpoint saves exactly what the editor collected", $id > 0 && $diff === [], implode(', ', $diff));
    $pdf = gh154_probe('POST', $admin, json_encode(['action' => 'export_pdf', 'id' => $id]));
    $html2 = (string) ($pdf['body']['html'] ?? '');
    $missing = [];
    array_walk_recursive($payload['form_data'], function ($v) use (&$missing, $html2, $s) {
        if (is_string($v) && strpos($v, 'J' . str_replace('-', '', $s) . '_') === 0 && strpos($html2, $v) === false) $missing[] = $v;
    });
    gh154_t("ICS {$s}: ...and prints every value typed into the editor", $html2 !== '' && $missing === [],
        implode(', ', array_slice($missing, 0, 5)));
}

// Forms saved by the previous release, opened and saved back.
$r = $run['reopen'][(string) $oldIds['205']] ?? [];
gh154_t('an old ICS 205 opens in the editor with its Tone/NAC in RX and TX Tone/NAC',
    ($r['cells']['radio_channels[0][rx_tone]'] ?? '') === '136.5' && ($r['cells']['radio_channels[0][tx_tone]'] ?? '') === '136.5'
    && ($r['cells']['radio_channels[0][frequency_rx]'] ?? '') === '151.4300', json_encode($r['cells'] ?? []));
$row = $r['payload']['form_data']['radio_channels'][0] ?? [];
gh154_t('...and saving it writes the value into the new columns', ($row['rx_tone'] ?? '') === '136.5' && ($row['tx_tone'] ?? '') === '136.5');
$r = $run['reopen'][(string) $oldIds['214']] ?? [];
gh154_t('an old ICS 214 opens with the team as 3. Name, the leader as 4. ICS Position, and 5. Home Agency empty',
    ($r['values']['name'] ?? '') === 'Rescue 2' && ($r['values']['ics_position'] ?? '') === 'A. Lee, Team Leader'
    && ($r['values']['home_agency'] ?? 'x') === '', json_encode($r['values'] ?? []));
gh154_t('...its old log entry keeps its time', ($r['cells']['activity_log[0][time]'] ?? '') === '08:15'
    && ($r['cells']['activity_log[0][activity]'] ?? '') === 'Briefed crew');
$fd = $r['payload']['form_data'] ?? [];
gh154_t('...and saving it writes those values into blocks 3 and 4', ($fd['name'] ?? '') === 'Rescue 2'
    && ($fd['ics_position'] ?? '') === 'A. Lee, Team Leader' && ($fd['activity_log'][0]['time'] ?? '') === '08:15');

$r = $run['reopen'][(string) $oldIds['206']] ?? [];
gh154_t('an old ICS 206 with a free-text "Maybe" in a Yes/No column shows it (offered as its own choice)',
    ($r['cells']['aid_stations[0][paramedics]'] ?? '') === 'Maybe', json_encode($r['cells'] ?? []));
gh154_t('...and saving it keeps it rather than blanking it',
    ($r['payload']['form_data']['aid_stations'][0]['paramedics'] ?? '') === 'Maybe');
gh154_t('...while the ambulance\'s old "Paramedics: Y" opens as Level of Service ALS',
    ($r['cells']['transportation[0][level_of_service]'] ?? '') === 'ALS');

$r = $run['reopen'][(string) $oldIds['213rr']] ?? [];
gh154_t('an old ICS 213 RR shows its "To" as an earlier-version field, badged',
    ($r['values']['to_name'] ?? '') === 'Logistics Chief' && ($r['legacyBadges'] ?? 0) >= 1, json_encode($r['values'] ?? []));
gh154_t('...shows its per-row Priority in the earlier-version column', ($r['cells']['resource_requests[0][priority]'] ?? '') === 'High');
gh154_t('...hides earlier-version fields that are empty (From)', !array_key_exists('from_name', $r['values'] ?? []));
gh154_t('...and saving it keeps both', ($r['payload']['form_data']['to_name'] ?? '') === 'Logistics Chief'
    && ($r['payload']['form_data']['resource_requests'][0]['priority'] ?? '') === 'High');

$r = $run['reopen'][(string) $oldIds['221']] ?? [];
$rows = $r['payload']['form_data']['checkout'] ?? [];
$sup = $com = null;
foreach ($rows as $row) {
    if (($row['unit'] ?? '') === 'Supply Unit') $sup = $row;
    if (($row['unit'] ?? '') === 'Communications Unit') $com = $row;
}
gh154_t('an old ICS 221 opens with its check-out boxes as FEMA block 6 rows, and saves them',
    count($rows) === 8 && ($sup['check'] ?? '') === '1' && ($com['remarks'] ?? '') === 'radio #44 returned', json_encode($rows));
gh154_t('...with its old Leader Name visible as an earlier-version field', ($r['values']['leader_name'] ?? '') === 'C. Diaz');

gh154_done();
