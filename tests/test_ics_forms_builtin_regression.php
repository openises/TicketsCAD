<?php
/**
 * Built-in ICS forms — regression guard for forms saved by earlier versions.
 *
 * HISTORY. Phase 140 (2026-08-16) asserted that getFormTemplate() and
 * generatePrintHtml() for the nine original built-in types were BYTE-FOR-BYTE
 * identical to a pre-Phase-140 snapshot, to stop "someone cleans up the
 * switch while they're in the file". GH#154 (2026-10-19) deliberately changed
 * both — the nine forms were matched to the FEMA booklet (new blocks, new
 * columns, block numbers, one shared print renderer) — so a byte-for-byte
 * check would now only prove the fix was not made. What that test really
 * protected was this: a form someone already saved must keep working. That is
 * what this file now proves, and it proves it more strongly than a snapshot
 * diff did:
 *
 *  1. For each of the nine original types, a form is filled in with EVERY
 *     field and table column its old template had (tests/_gh154_pre_fema_
 *     templates.php — a frozen copy of the pre-GH#154 getFormTemplate(),
 *     committed so this runs on CI's shallow clones). Every value must still
 *     be visible after the read-time mapping (ics_form_normalize_data()) — in
 *     a field or column the editor shows today — and must appear on the
 *     printout.
 *  2. The Winlink ICS-213 XML export is byte-for-byte unchanged: same
 *     function, same output for the same form (golden output below, taken
 *     from the pre-GH#154 code).
 *  3. Where git history is available, the frozen copy is checked against the
 *     real pre-GH#154 api/ics-forms.php, so the fixture cannot drift.
 *
 * Pure PHP (no HTTP). tests/test_gh154_ics_forms_roundtrip.php drives the same
 * paths through the real endpoint.
 */
require_once __DIR__ . '/_gh154_helpers.php';

echo "=== Built-in ICS forms — data saved by earlier versions still opens and prints ===\n\n";

$old = require __DIR__ . '/_gh154_pre_fema_templates.php';
$base = realpath(__DIR__ . '/..');

/** Old field type -> a value and how it looks on paper. */
function reg_value(array $f, string $sentinel): array
{
    switch ((string) ($f['type'] ?? 'text')) {
        case 'date':           return ['2026-03-04', '03/04/2026'];
        case 'time':           return ['13:37', '13:37'];
        case 'datetime-local': return ['2026-03-04T13:37', '03/04/2026 13:37'];
        default:               return [$sentinel, $sentinel];
    }
}

foreach ($old as $type => $oldTpl) {
    $type = (string) $type;
    $data = [];
    $expect = []; // [what, editorValue, printValue]
    foreach ($oldTpl['fields'] as $f) {
        $key = $f['key'];
        if ($f['type'] === 'table') {
            $rows = [];
            for ($r = 0; $r < 2; $r++) {
                $row = [];
                foreach ($f['columns'] as $c) {
                    [$v, $pv] = reg_value($c, 'Sold' . $type . '_' . $key . '_' . $c['key'] . '_' . $r);
                    $row[$c['key']] = $v;
                    $expect[] = ["{$key}[{$r}].{$c['key']}", $v, $pv];
                }
                $rows[] = $row;
            }
            $data[$key] = $rows;
        } else {
            [$v, $pv] = reg_value($f, 'Sold' . $type . '_' . $key);
            $data[$key] = $v;
            $expect[] = [$key, $v, $pv];
        }
    }

    $tpl = ics_builtin_template($type);
    gh154_t("ICS {$type}: still a built-in type", is_array($tpl));
    if (!is_array($tpl)) {
        continue;
    }
    $norm = ics_form_normalize_data($type, $data);
    $shown = gh154_displayed_values($tpl, $norm);
    $html = generatePrintHtml($type, $data, ['id' => 1, 'title' => 'Regression ' . $type]);

    $lostEditor = $lostPrint = [];
    foreach ($expect as [$what, $v, $pv]) {
        if (!gh154_shown($shown, $v)) $lostEditor[] = $what;
        if (strpos($html, htmlspecialchars($pv, ENT_QUOTES, 'UTF-8')) === false) $lostPrint[] = $what;
    }
    gh154_t("ICS {$type}: every value of a form saved by the old version is shown in the editor (" . count($expect) . ' values)',
        $lostEditor === [], 'not shown: ' . implode(', ', $lostEditor));
    gh154_t("ICS {$type}: ...and every one of them is on the printout",
        $lostPrint === [], 'not printed: ' . implode(', ', $lostPrint));
    gh154_t("ICS {$type}: the mapping is idempotent (mapping mapped data changes nothing)",
        ics_form_normalize_data($type, $norm) === $norm);
}

// ── 2. Winlink ICS-213 XML export: byte-for-byte unchanged ──────────────
$apiSrc = (string) file_get_contents($base . '/api/ics-forms.php');
$fnSrc = '';
foreach (['generateICS213Xml', 'xs'] as $name) {
    if (!preg_match('/\nfunction ' . $name . '\([^)]*\)\s*\{/', $apiSrc, $m, PREG_OFFSET_CAPTURE)) {
        gh154_t("api/ics-forms.php still defines {$name}()", false);
        continue;
    }
    $start = $m[0][1] + 1;
    $i = strpos($apiSrc, '{', $start);
    $depth = 0;
    for ($len = strlen($apiSrc); $i < $len; $i++) {
        if ($apiSrc[$i] === '{') $depth++;
        elseif ($apiSrc[$i] === '}') { $depth--; if ($depth === 0) { $i++; break; } }
    }
    $fnSrc .= substr($apiSrc, $start, $i - $start) . "\n";
}
// Rename so the extracted copies cannot clash with anything loaded here.
$fnSrc = str_replace(['function generateICS213Xml(', 'function xs(', ' xs('], ['function reg_ics213xml(', 'function reg_xs(', ' reg_xs('], $fnSrc);
eval($fnSrc); // test-only: evaluates the endpoint's own two pure functions, copied from the file under test

$xmlData = ['to_name' => 'Chief A & Co', 'to_position' => 'IC', 'from_name' => 'Op <B>', 'from_position' => 'Comms',
    'subject' => 'Test "quoted"', 'date' => '2026-10-19', 'time' => '14:30', 'message' => "line one\nline two",
    'approved_name' => 'Planner', 'approved_position' => 'PSC', 'reply' => 'ack', 'reply_by' => 'J. Doe',
    'reply_date' => '2026-10-19', 'reply_time' => '15:00',
    // GH#154 added these two 213 fields; the export must ignore them, exactly as before.
    'incident_name' => 'ignored by the export', 'reply_position' => 'ignored too'];
$golden = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RMS_Express_Form>
  <form_parameters>
    <xml_file_version>1.0</xml_file_version>
    <rms_express_version>TicketsCAD_v4</rms_express_version>
    <submission_datetime>X</submission_datetime>
    <senders_callsign></senders_callsign>
    <grid_square></grid_square>
    <display_form>ICS213_Viewer.html</display_form>
  </form_parameters>
  <variables>
    <msgseqnum></msgseqnum>
    <incidentname>Bridge collapse</incidentname>
    <datetime>2026-10-19 14:30</datetime>
    <to>Chief A &amp; Co</to>
    <toposition>IC</toposition>
    <from>Op &lt;B&gt;</from>
    <fromposition>Comms</fromposition>
    <subject>Test "quoted"</subject>
    <message>line one
line two</message>
    <approved_by>Planner</approved_by>
    <approved_position>PSC</approved_position>
    <approved_datetime></approved_datetime>
    <reply>ack</reply>
    <reply_by>J. Doe</reply_by>
    <reply_position></reply_position>
    <reply_datetime>2026-10-19 15:00</reply_datetime>
  </variables>
</RMS_Express_Form>

XML;
$xml = function_exists('reg_ics213xml') ? reg_ics213xml($xmlData, ['title' => 'Bridge collapse']) : '';
$xml = preg_replace('#<submission_datetime>[^<]*</submission_datetime>#', '<submission_datetime>X</submission_datetime>', (string) $xml);
gh154_t('Winlink ICS-213 XML export is byte-for-byte what it was before GH#154', $xml === $golden,
    'got: ' . substr((string) $xml, 0, 300));

// ── 3. The frozen fixture matches the real pre-GH#154 file, where git has it ─
$code = 1;
$log = [];
exec('git -C ' . escapeshellarg($base) . ' log --format=%H -- api/ics-forms.php 2>&1', $log, $code);
$priorSrc = null;
if ($code === 0) {
    foreach ($log as $sha) {
        $sha = trim($sha);
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) continue;
        $show = [];
        exec('git -C ' . escapeshellarg($base) . ' show ' . escapeshellarg($sha . ':api/ics-forms.php') . ' 2>&1', $show, $sc);
        $content = implode("\n", $show);
        if ($sc === 0 && strpos($content, 'ics_builtin_template') === false && strpos($content, 'function getFormTemplate') !== false) {
            $priorSrc = $content;
            break;
        }
    }
}
if ($priorSrc === null) {
    echo "NOTE: no pre-GH#154 api/ics-forms.php in this checkout's history (shallow clone?) — fixture cross-check not run\n";
} else {
    if (preg_match('/\nfunction getFormTemplate\(\$type, \$customTypeId = null\) \{/', $priorSrc, $m, PREG_OFFSET_CAPTURE)) {
        $start = $m[0][1] + 1;
        $i = strpos($priorSrc, '{', $start);
        $depth = 0;
        for ($len = strlen($priorSrc); $i < $len; $i++) {
            if ($priorSrc[$i] === '{') $depth++;
            elseif ($priorSrc[$i] === '}') { $depth--; if ($depth === 0) { $i++; break; } }
        }
        $fn = str_replace('function getFormTemplate(', 'function reg_old_getFormTemplate(', substr($priorSrc, $start, $i - $start));
        eval($fn); // test-only: the pre-GH#154 template function, read from this repository's own history
        $same = true;
        foreach ($old as $type => $tpl) {
            if (json_encode(reg_old_getFormTemplate((string) $type)) !== json_encode($tpl)) $same = false;
        }
        gh154_t('the frozen pre-GH#154 templates match api/ics-forms.php in git history', $same);
    } else {
        gh154_t('the pre-GH#154 api/ics-forms.php in git history still has getFormTemplate()', false);
    }
}

gh154_done();
