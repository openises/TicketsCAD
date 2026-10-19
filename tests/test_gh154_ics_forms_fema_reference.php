<?php
/**
 * GH#154 — every built-in ICS form matches its FEMA form.
 *
 * The block numbers and titles a template displays (ics_form_template_blocks(),
 * derived from the same field labels the editor and the printout show) are
 * compared, in order, with tests/_gh154_fema_reference.php — a table typed by
 * hand from the NIMS ICS Forms Booklet (FEMA 502-2). Forms with no numbered
 * blocks (ICS 217A, the ICS 219 cards) are checked label by label instead.
 *
 * Also checks each template is well formed (unique keys, every drop-down has
 * options, widths on the 12-column grid, known field types), and that the
 * built-in list is exactly the FEMA forms plus the pre-2010 ICS 214a.
 *
 * Pure PHP: no database writes, no HTTP.
 */
require_once __DIR__ . '/_gh154_helpers.php';

echo "=== GH#154 — built-in ICS forms vs the FEMA booklet ===\n\n";

$ref = require __DIR__ . '/_gh154_fema_reference.php';

/** Lower-case, collapse whitespace, drop a trailing colon. */
function gh154_norm(string $s): string
{
    $s = strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    return rtrim($s, ': ');
}

// Forms whose templates are checked against the reference so far.
$checked = array_map('strval', array_keys($ref)); // every FEMA form in the reference (214a is pre-2010: none)

foreach ($checked as $slug) {
    $tpl = ics_builtin_template($slug);
    gh154_t("ICS {$slug}: a built-in template exists", is_array($tpl));
    if (!is_array($tpl) || !isset($ref[$slug])) {
        gh154_t("ICS {$slug}: has a FEMA reference entry", isset($ref[$slug]));
        continue;
    }
    $got = ics_form_template_blocks($tpl);
    $want = $ref[$slug]['blocks'];
    $gotList = array_map(function ($b) { return $b['block'] . '. ' . gh154_norm($b['title']); }, $got);
    $wantList = [];
    foreach ($want as $n => $title) {
        $wantList[] = $n . '. ' . gh154_norm($title);
    }
    gh154_t("ICS {$slug}: blocks are FEMA's, in FEMA's order (" . count($wantList) . ' blocks)',
        $gotList === $wantList,
        'missing: ' . implode(' | ', array_diff($wantList, $gotList))
        . ' ; extra: ' . implode(' | ', array_diff($gotList, $wantList)));

    if (!empty($ref[$slug]['labels'])) {
        $labels = [];
        foreach ($tpl['fields'] as $f) {
            $labels[] = gh154_norm(preg_replace('/^\d+\.\s*/', '', (string) ($f['label'] ?? '')));
            foreach ($f['columns'] ?? [] as $c) {
                $labels[] = gh154_norm((string) ($c['label'] ?? ''));
            }
        }
        $missing = [];
        foreach ($ref[$slug]['labels'] as $l) {
            if (!in_array(gh154_norm($l), $labels, true)) {
                $missing[] = $l;
            }
        }
        gh154_t("ICS {$slug}: every FEMA field label is present", $missing === [], implode(', ', $missing));
    }
}

// ── ICS 205 / 214: the columns the issue is about, by name and in order ──
$t205 = ics_builtin_template('205');
$cols = [];
foreach ($t205['fields'] as $f) {
    if (($f['key'] ?? '') === 'radio_channels') {
        $cols = array_map(function ($c) { return $c['label']; }, $f['columns']);
    }
}
gh154_t('ICS 205 block 4 has FEMA\'s columns in order (N/W as its own drop-down after each frequency)',
    $cols === ['Zone Grp.', 'Ch #', 'Function', 'Channel Name/Trunked Radio System Talkgroup', 'Assignment',
               'RX Freq', 'N or W', 'RX Tone/NAC', 'TX Freq', 'N or W', 'TX Tone/NAC', 'Mode (A, D, or M)', 'Remarks'],
    implode(' | ', $cols));
$t214 = ics_builtin_template('214');
$res = $log = [];
foreach ($t214['fields'] as $f) {
    if (($f['key'] ?? '') === 'resources_assigned') $res = array_column($f['columns'], 'label');
    if (($f['key'] ?? '') === 'activity_log') $log = array_column($f['columns'], 'label');
}
gh154_t('ICS 214 block 6 Resources Assigned: Name, ICS Position, Home Agency (and Unit)',
    $res === ['Name', 'ICS Position', 'Home Agency (and Unit)'], implode(' | ', $res));
gh154_t('ICS 214 block 7 Activity Log: a Date as well as a Time on every entry',
    $log === ['Date', 'Time', 'Notable Activities'], implode(' | ', $log));

// ── Every built-in template is well formed ───────────────────────────────
$allowed = ['text', 'textarea', 'number', 'select', 'checkbox', 'date', 'time', 'datetime-local',
            'section_header', 'table', 'signature'];
$colTypes = ['text', 'number', 'select', 'date', 'time', 'datetime-local', 'checkbox'];
foreach (ics_builtin_slugs() as $slug) {
    $tpl = ics_builtin_template($slug);
    if (!is_array($tpl)) {
        gh154_t("ICS {$slug}: template resolves", false);
        continue;
    }
    $problems = [];
    if ($tpl['form_type'] !== $slug) $problems[] = 'form_type mismatch';
    if (trim((string) $tpl['form_number']) === '' || trim((string) $tpl['print_title']) === '') $problems[] = 'number/title missing';
    if (strlen($slug) > 10) $problems[] = 'slug longer than ics_forms.form_type VARCHAR(10)';
    $keys = [];
    foreach ($tpl['fields'] as $i => $f) {
        $type = (string) ($f['type'] ?? '');
        if (!in_array($type, $allowed, true)) $problems[] = "field #{$i} has unknown type '{$type}'";
        if (isset($f['col']) && ((int) $f['col'] < 1 || (int) $f['col'] > 12)) $problems[] = "field #{$i} col out of range";
        if (in_array($type, ['section_header', 'signature'], true)) continue;
        $key = (string) ($f['key'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) $problems[] = "bad key '{$key}'";
        if (isset($keys[$key])) $problems[] = "duplicate key '{$key}'";
        $keys[$key] = true;
        if ($type === 'select' && empty($f['options'])) $problems[] = "select '{$key}' has no options";
        if ($type === 'table') {
            if (empty($f['columns'])) $problems[] = "table '{$key}' has no columns";
            $ck = [];
            foreach ($f['columns'] ?? [] as $c) {
                $k = (string) ($c['key'] ?? '');
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $k)) $problems[] = "table '{$key}' bad column key '{$k}'";
                if (isset($ck[$k])) $problems[] = "table '{$key}' duplicate column '{$k}'";
                $ck[$k] = true;
                if (!in_array((string) ($c['type'] ?? 'text'), $colTypes, true)) $problems[] = "table '{$key}' column '{$k}' unknown type";
                if (($c['type'] ?? '') === 'select' && empty($c['options'])) $problems[] = "table '{$key}' column '{$k}' has no options";
                foreach ($c['print_append'] ?? [] as $ak) {
                    if (!in_array($ak, array_column($f['columns'], 'key'), true)) $problems[] = "table '{$key}' appends unknown column '{$ak}'";
                }
            }
        }
    }
    gh154_t("ICS {$slug}: template is well formed", $problems === [], implode('; ', $problems));
}

gh154_t('ICS 214a is marked as the pre-2010 form', !empty(ics_builtin_type_meta('214a')['legacy']));

// The built-in list is exactly the FEMA booklet, plus the pre-2010 ICS 214a.
$want = array_merge(array_map('strval', array_keys($ref)), ['214a']);
$have = ics_builtin_slugs();
sort($want); sort($have);
gh154_t('every form in the FEMA booklet is built in, and nothing else (' . count($want) . ' types)', $want === $have,
    'missing: ' . implode(', ', array_diff($want, $have)) . ' ; extra: ' . implode(', ', array_diff($have, $want)));
$groups = array_keys(ics_builtin_groups());
$bad = [];
foreach (ics_builtin_form_types() as $m) { if (!in_array($m['group'], $groups, true)) $bad[] = $m['slug']; }
gh154_t('every type belongs to a hub group', $bad === [], implode(', ', $bad));
$cards = array_values(array_filter(ics_builtin_form_types(), function ($m) { return $m['group'] === 'tcards'; }));
gh154_t('the nine ICS 219 cards are grouped under one heading', count($cards) === 9);

gh154_done();
