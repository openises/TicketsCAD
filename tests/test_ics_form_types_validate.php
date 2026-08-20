<?php
/**
 * Phase 140 (2026-08-16) — Custom ICS Form Types: core logic.
 *
 * Drives inc/ics-form-types.php directly:
 *   1. ics_form_type_validate_metadata() — every rule + negative cases.
 *   2. ics_form_type_validate_fields() — the full field-type palette,
 *      every cap, the reserved-word denylist, unknown-property rejection.
 *   3. ics_form_type_check_impersonation() — narrow, not a broad blocklist.
 *   4. ics_form_custom_build_meta() — first-save snapshot vs. carry-forward.
 *   5. ics_form_custom_print_html() — markup shape, AND a live proof that
 *      _ics_apply_security_wrap() (extracted from api/ics-forms.php,
 *      exactly like tests/test_ics_forms_redaction.php does for the
 *      built-in types) actually redacts a custom form's rendered output.
 *   6. ics_form_custom_template() — the org-scope + restrict_to_permission
 *      choke point, against REAL fixtures (throwaway org, user, role
 *      grant, and both an install-wide and an org-scoped type row) --
 *      never hand-simulated state.
 *   7. ics_forms_has_custom_type_columns() detector.
 *
 * @requires-db
 * Usage: php tests/test_ics_form_types_validate.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/ics-form-types.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}
function tbl($n) { return db_table($n); }

echo "=== Phase 140 — Custom ICS Form Types: Core Logic ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — ics_form_type_validate_metadata()
// ═══════════════════════════════════════════════════════════════════════

echo "--- validate_metadata ---\n\n";

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'Medical Triage Log']);
t("valid minimal metadata passes", $r['valid'] === true);

$r = ics_form_type_validate_metadata(['slug' => 'Bad_Slug!', 'form_title' => 'X']);
t("slug with uppercase/punctuation is rejected", $r['valid'] === false);

$r = ics_form_type_validate_metadata(['slug' => 'ab', 'form_title' => 'X']);
t("slug shorter than 3 chars is rejected", $r['valid'] === false);

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X'], 'old-slug');
t("changing slug on update is rejected (immutable)", $r['valid'] === false);

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X'], 'medical-triage');
t("keeping the same slug on update passes", $r['valid'] === true);

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => '']);
t("empty form_title is rejected", $r['valid'] === false);

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => str_repeat('x', 256)]);
t("form_title over 255 chars is rejected", $r['valid'] === false);

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X', 'description' => str_repeat('x', 501)]);
t("description over 500 chars is rejected", $r['valid'] === false);

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X', 'badge_color' => 'chartreuse']);
t("invalid badge_color is rejected", $r['valid'] === false);
foreach (['primary','secondary','success','danger','warning','info','dark'] as $c) {
    $r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X', 'badge_color' => $c]);
    t("badge_color '$c' is accepted", $r['valid'] === true);
}

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X', 'icon' => 'fa-heart']);
t("non bi- prefixed icon is rejected", $r['valid'] === false);
$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X', 'icon' => 'bi-heart-pulse']);
t("valid bi- icon is accepted", $r['valid'] === true);

$r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X', 'restrict_to_permission' => 'action.__definitely_not_real__']);
t("restrict_to_permission referencing a non-existent code is rejected", $r['valid'] === false);
$realPerm = db_fetch_value("SELECT code FROM `{$prefix}permissions` LIMIT 1");
if ($realPerm) {
    $r = ics_form_type_validate_metadata(['slug' => 'medical-triage', 'form_title' => 'X', 'restrict_to_permission' => $realPerm]);
    t("restrict_to_permission referencing a real code ('$realPerm') is accepted", $r['valid'] === true);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — ics_form_type_check_impersonation()
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- check_impersonation (narrow, not a broad blocklist) ---\n\n";

foreach (['213', '214', '202', '205', '205a', '213rr', '206', '214a', '221', 'custom'] as $reserved) {
    $r = ics_form_type_check_impersonation($reserved, '');
    t("slug '$reserved' (exact built-in match) is rejected", $r['valid'] === false);
}
$r = ics_form_type_check_impersonation('medical-triage', '');
t("an unrelated slug is accepted", $r['valid'] === true);
$r = ics_form_type_check_impersonation('medical-triage', 'ICS-213');
t("form_number 'ICS-213' is rejected", $r['valid'] === false);
$r = ics_form_type_check_impersonation('medical-triage', 'ICS99');
t("form_number 'ICS99' (no hyphen) is rejected", $r['valid'] === false);
$r = ics_form_type_check_impersonation('medical-triage', 'MED-1');
t("form_number 'MED-1' is accepted", $r['valid'] === true);
$r = ics_form_type_check_impersonation('fema-reimbursement-worksheet', 'FEMA-90-49');
t("real agency paperwork naming ('FEMA...') is NOT falsely blocked (deliberately narrow, not a word-blocklist)", $r['valid'] === true);

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — ics_form_type_validate_fields()
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- validate_fields: palette + caps ---\n\n";

$r = ics_form_type_validate_fields([]);
t("empty field list is rejected", $r['valid'] === false);

$r = ics_form_type_validate_fields([
    ['key' => 'patient_name', 'label' => 'Patient Name', 'type' => 'text'],
    ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'rows' => 4],
    ['key' => 'age', 'label' => 'Age', 'type' => 'number', 'min' => 0, 'max' => 120],
    ['key' => 'seen_at', 'label' => 'Seen At', 'type' => 'datetime-local'],
    ['key' => 'triage', 'label' => 'Triage Category', 'type' => 'select', 'options' => ['Red', 'Yellow', 'Green', 'Black']],
    ['key' => 'transported', 'label' => 'Transported', 'type' => 'checkbox'],
    ['key' => 'section1', 'label' => 'Vitals', 'type' => 'section_header'],
]);
t("a realistic mixed-type definition passes", $r['valid'] === true, );
if ($r['valid'] !== true) echo "  errors: " . implode('; ', $r['errors']) . "\n";

$r = ics_form_type_validate_fields([['key' => 'Bad Key!', 'label' => 'X', 'type' => 'text']]);
t("invalid field key format is rejected", $r['valid'] === false);

$r = ics_form_type_validate_fields([
    ['key' => 'dup', 'label' => 'A', 'type' => 'text'],
    ['key' => 'dup', 'label' => 'B', 'type' => 'text'],
]);
t("duplicate field keys are rejected", $r['valid'] === false);

foreach (['_meta', 'constructor', 'prototype', '__proto__', 'toString', 'valueOf'] as $bad) {
    $r = ics_form_type_validate_fields([['key' => $bad, 'label' => 'X', 'type' => 'text']]);
    t("reserved key '$bad' is rejected", $r['valid'] === false);
}

$r = ics_form_type_validate_fields([['key' => 'k', 'label' => 'X', 'type' => 'text', 'bogus_prop' => 1]]);
t("unknown top-level field property is a hard error, not silently stripped", $r['valid'] === false);

$r = ics_form_type_validate_fields([['key' => 'k', 'label' => 'X', 'type' => 'select']]);
t("select with no options is rejected", $r['valid'] === false);
$manyOpts = array_map(function ($i) { return "Option $i"; }, range(1, 51));
$r = ics_form_type_validate_fields([['key' => 'k', 'label' => 'X', 'type' => 'select', 'options' => $manyOpts]]);
t("select with 51 options exceeds the 50-option cap and is rejected", $r['valid'] === false);
$r = ics_form_type_validate_fields([['key' => 'k', 'label' => 'X', 'type' => 'select', 'options' => [str_repeat('x', 81)]]]);
t("select option over 80 chars is rejected", $r['valid'] === false);

$r = ics_form_type_validate_fields([['key' => 'k', 'label' => 'X', 'type' => 'number', 'min' => 10, 'max' => 5]]);
t("number field with min > max is rejected", $r['valid'] === false);

$r = ics_form_type_validate_fields([['key' => 'k', 'label' => 'X', 'type' => 'textarea', 'rows' => 999]]);
t("textarea rows out of 1-30 range is rejected", $r['valid'] === false);

$r = ics_form_type_validate_fields([['key' => 'k', 'label' => 'X', 'type' => 'made_up_type']]);
t("unknown field type is rejected", $r['valid'] === false);

// Simple-field cap: 41 simple fields
$manyFields = [];
for ($i = 0; $i < 41; $i++) {
    $manyFields[] = ['key' => "f$i", 'label' => "Field $i", 'type' => 'text'];
}
$r = ics_form_type_validate_fields($manyFields);
t("41 simple fields exceeds the 40-field cap and is rejected", $r['valid'] === false);
$okFields = array_slice($manyFields, 0, 40);
$r = ics_form_type_validate_fields($okFields);
t("exactly 40 simple fields is accepted", $r['valid'] === true);

// Table field validation
$r = ics_form_type_validate_fields([
    ['key' => 'log', 'label' => 'Activity Log', 'type' => 'table', 'columns' => [
        ['key' => 'time', 'label' => 'Time', 'type' => 'time'],
        ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
    ], 'default_rows' => 2, 'max_rows' => 50],
]);
t("a valid table field passes", $r['valid'] === true);
if ($r['valid'] !== true) echo "  errors: " . implode('; ', $r['errors']) . "\n";

$r = ics_form_type_validate_fields([['key' => 'log', 'label' => 'X', 'type' => 'table']]);
t("table field with no columns is rejected", $r['valid'] === false);

$manyCols = [];
for ($i = 0; $i < 13; $i++) $manyCols[] = ['key' => "c$i", 'label' => "C$i", 'type' => 'text'];
$r = ics_form_type_validate_fields([['key' => 'log', 'label' => 'X', 'type' => 'table', 'columns' => $manyCols]]);
t("table with 13 columns exceeds the 12-column cap and is rejected", $r['valid'] === false);

$r = ics_form_type_validate_fields([['key' => 'log', 'label' => 'X', 'type' => 'table',
    'columns' => [['key' => 'c1', 'label' => 'C1', 'type' => 'text']], 'max_rows' => 500]]);
t("table max_rows above the 200-row DoS cap is rejected", $r['valid'] === false);

$r = ics_form_type_validate_fields([['key' => 'log', 'label' => 'X', 'type' => 'table',
    'columns' => [['key' => 'c1', 'label' => 'C1', 'type' => 'unsupported_col_type']]]]);
t("table column with an unsupported type is rejected", $r['valid'] === false);

$r = ics_form_type_validate_fields([['key' => 'log', 'label' => 'X', 'type' => 'table',
    'columns' => [['key' => 'dup', 'label' => 'A', 'type' => 'text'], ['key' => 'dup', 'label' => 'B', 'type' => 'text']]]]);
t("table with duplicate column keys is rejected", $r['valid'] === false);

// Table-field cap: 4 table fields
$manyTables = [];
for ($i = 0; $i < 4; $i++) {
    $manyTables[] = ['key' => "t$i", 'label' => "T$i", 'type' => 'table', 'columns' => [['key' => 'c', 'label' => 'C', 'type' => 'text']]];
}
$r = ics_form_type_validate_fields($manyTables);
t("4 table fields exceeds the 3-table-field cap and is rejected", $r['valid'] === false);

// ═══════════════════════════════════════════════════════════════════════
// Part 4 — ics_form_custom_build_meta()
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- build_meta: first-save snapshot vs. carry-forward ---\n\n";

$typeTemplate = [
    'custom_type_id' => 42, 'slug' => 'medical-triage', 'form_number' => 'MED-1',
    'form_title' => 'Medical Triage Log', 'badge_color' => 'warning', 'icon' => 'bi-heart-pulse',
    'fields' => [['key' => 'patient_name', 'label' => 'Patient Name', 'type' => 'text']],
];
$meta = ics_form_custom_build_meta($typeTemplate, null);
t("first save builds a fresh snapshot with the type's current fields", $meta['fields'] === $typeTemplate['fields']);
t("snapshot records type_id", $meta['type_id'] === 42);
t("snapshot records a snapshot_at timestamp", !empty($meta['snapshot_at']));

$olderMeta = ['type_id' => 42, 'fields' => [['key' => 'ancient_field', 'label' => 'Old', 'type' => 'text']], 'snapshot_at' => '2020-01-01 00:00:00'];
$carried = ics_form_custom_build_meta($typeTemplate, $olderMeta);
t("update carries the EXISTING snapshot forward unchanged, even though the type's fields have since changed",
    $carried === $olderMeta);
t("carried-forward snapshot still shows the frozen old field, not the type's current one",
    $carried['fields'][0]['key'] === 'ancient_field');

// ═══════════════════════════════════════════════════════════════════════
// Part 4a — ics_form_custom_validate_data(): instance-save server-side
// checks. This is the choke point api/ics-forms.php's action=save handler
// calls BEFORE writing an instance to ics_forms -- the one place a number
// field's min/max (set while authoring the TYPE, e.g. a rehab heart-rate
// field bounded 40-220) is enforced against the actual VALUE a user
// submits. Added alongside the existing select-value check because a
// stray out-of-range number typed past the browser's <input min max>
// (which this codebase's fetch()-based save never runs through native
// HTML5 constraint validation for) previously saved unchecked.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- ics_form_custom_validate_data(): number bounds + select values on instance save ---\n\n";

$vitalsFields = [
    ['key' => 'heart_rate', 'label' => 'Heart Rate', 'type' => 'number', 'min' => 40, 'max' => 220],
    ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Entered', 'Monitoring', 'Released']],
    ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
];

$r = ics_form_custom_validate_data($vitalsFields, ['heart_rate' => 88, 'status' => 'Monitoring']);
t("in-range number + valid select value: accepted", $r['valid'] === true);

$r = ics_form_custom_validate_data($vitalsFields, ['heart_rate' => 900]);
t("heart rate of 900 against a 40-220 bound is REJECTED server-side (the exact claim this feature's own training video makes)",
    $r['valid'] === false);

$r = ics_form_custom_validate_data($vitalsFields, ['heart_rate' => 10]);
t("a number below the minimum is rejected", $r['valid'] === false);

$r = ics_form_custom_validate_data($vitalsFields, ['heart_rate' => 220]);
t("a number exactly AT the maximum is accepted (inclusive bound)", $r['valid'] === true);

$r = ics_form_custom_validate_data($vitalsFields, ['heart_rate' => 40]);
t("a number exactly AT the minimum is accepted (inclusive bound)", $r['valid'] === true);

$r = ics_form_custom_validate_data($vitalsFields, ['heart_rate' => '']);
t("a blank number value is always allowed server-side (matches the existing select-blank rule)", $r['valid'] === true);

$r = ics_form_custom_validate_data($vitalsFields, []);
t("a number key entirely absent from form_data is allowed (matches the existing select-absent rule)", $r['valid'] === true);

$r = ics_form_custom_validate_data($vitalsFields, ['heart_rate' => 'not-a-number']);
t("a non-numeric value in a number field's slot is rejected outright, not silently coerced", $r['valid'] === false);

$noBoundsFields = [['key' => 'age', 'label' => 'Age', 'type' => 'number']];
$r = ics_form_custom_validate_data($noBoundsFields, ['age' => 9999]);
t("a number field with no min/max defined accepts any numeric value", $r['valid'] === true);

$oneSidedFields = [['key' => 'age', 'label' => 'Age', 'type' => 'number', 'min' => 0]];
$r = ics_form_custom_validate_data($oneSidedFields, ['age' => -5]);
t("a min-only bound still rejects a value below it", $r['valid'] === false);
$r = ics_form_custom_validate_data($oneSidedFields, ['age' => 500]);
t("a min-only bound places no ceiling", $r['valid'] === true);

$r = ics_form_custom_validate_data($vitalsFields, ['status' => 'Not A Real Status']);
t("an invalid select value is still rejected (pre-existing behavior, unaffected by the number-bounds addition)",
    $r['valid'] === false);

$tableFields = [[
    'key' => 'vitals_log', 'label' => 'Vitals Log', 'type' => 'table',
    'columns' => [['key' => 'reading', 'label' => 'Reading', 'type' => 'number']],
]];
$r = ics_form_custom_validate_data($tableFields, ['vitals_log' => [['reading' => 12345]]]);
t("a table column of type number has no min/max to enforce (the field-builder never exposes bounds on table columns) -- any numeric value passes",
    $r['valid'] === true);

// ═══════════════════════════════════════════════════════════════════════
// Part 5 — ics_form_custom_print_html(): markup shape + live redaction
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- print_html: markup shape + live redaction proof ---\n\n";

$instanceData = [
    '_meta' => [
        'form_title' => 'Medical Triage Log',
        'fields' => [
            ['key' => 'patient_name', 'label' => 'Patient Name', 'type' => 'text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ['key' => 'section1', 'label' => 'Vitals', 'type' => 'section_header'],
            ['key' => 'transported', 'label' => 'Transported', 'type' => 'checkbox'],
            ['key' => 'log', 'label' => 'Activity Log', 'type' => 'table', 'columns' => [
                ['key' => 'time', 'label' => 'Time', 'type' => 'time'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
            ]],
        ],
    ],
    'patient_name' => '123 Maple Street Apt B',
    'notes' => "line one\nline two",
    'transported' => true,
    'log' => [['time' => '14:30', 'note' => 'first entry']],
];
$html = ics_form_custom_print_html($instanceData, ['id' => 1]);

t("print output contains a <span class=\"value\"> for the text field (matches built-ins' markup convention)",
    strpos($html, '<span class="value">123 Maple Street Apt B</span>') !== false);
t("print output escapes textarea content and converts newlines to <br />",
    strpos($html, 'line one<br') !== false);
t("print output renders section_header as a <th colspan=\"2\">, excluded from data collection",
    strpos($html, '<th colspan="2" style="background:#e5e5e5">Vitals</th>') !== false);
t("checkbox true renders the checked-box glyph", strpos($html, '&#9745;') !== false);
t("table field renders its row data", strpos($html, '<td>14:30</td>') !== false && strpos($html, '<td>first entry</td>') !== false);

// Extract the REAL _ics_apply_security_wrap() from api/ics-forms.php, exactly
// like tests/test_ics_forms_redaction.php does for the built-in types --
// never a hand-simulated copy of the redaction logic.
function _p140_load_ics_apply_wrap(): void {
    $src = file_get_contents(__DIR__ . '/../api/ics-forms.php');
    if (!preg_match('/function _ics_apply_security_wrap\(.*?\n\}\n/s', $src, $m)) {
        throw new RuntimeException('could not extract _ics_apply_security_wrap');
    }
    eval($m[0]);
}
_p140_load_ics_apply_wrap();

$wrapped = _ics_apply_security_wrap($html, ['ics_export_show_full' => 0, 'name' => 'Confidential']);
t("_ics_apply_security_wrap ACTUALLY redacts a custom form's rendered field value (live proof, not an assumption about markup shape)",
    strpos($wrapped, '123 Maple Street Apt B') === false && strpos($wrapped, 'Confidential') !== false);

$wrappedFull = _ics_apply_security_wrap($html, ['ics_export_show_full' => 1]);
t("show_full=1 leaves the custom form's content untouched",
    strpos($wrappedFull, '123 Maple Street Apt B') !== false);

// ═══════════════════════════════════════════════════════════════════════
// Part 6 — ics_forms_has_custom_type_columns()
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- has_custom_type_columns detector ---\n\n";
t("detector reflects the real schema state", ics_forms_has_custom_type_columns(true) === (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'custom_type_id'",
    [$prefix . 'ics_forms']
));

// ═══════════════════════════════════════════════════════════════════════
// Part 7 — ics_form_custom_template(): the org-scope choke point, against
// REAL fixtures (never hand-simulated state)
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- ics_form_custom_template(): org-scope + restrict_to_permission (live fixtures) ---\n\n";

$hasTypesTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'ics_form_types']
);
if (!$hasTypesTable) {
    echo "SKIP: ics_form_types table not present -- run sql/run_phase140_custom_ics_form_types.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$createdOrgA = null; $createdOrgB = null; $createdUser = null; $createdUR = null;
$typeGlobal = null; $typeOrgA = null; $typeArchived = null; $typeRestricted = null;

try {
    $oa = db_fetch_one("SELECT id FROM " . tbl('roles') . " WHERE name = 'Org Admin' LIMIT 1");
    if (!$oa) {
        echo "SKIP: Org Admin role not present -- nothing to test.\n";
        echo "\n=== $pass passed, $fail failed ===\n";
        exit(0);
    }
    $orgAdminRoleId = (int) $oa['id'];

    db_query("INSERT INTO " . tbl('organizations') . " (name, short_name, active, sort_order) VALUES (?,?,1,999)",
        ['zz-test-140-org-a', 'ZZ140A']);
    $createdOrgA = (int) db_insert_id();
    db_query("INSERT INTO " . tbl('organizations') . " (name, short_name, active, sort_order) VALUES (?,?,1,999)",
        ['zz-test-140-org-b', 'ZZ140B']);
    $createdOrgB = (int) db_insert_id();

    $cols = array_column(db_fetch_all("DESCRIBE " . tbl('user')), null, 'Field');
    $fields = [];
    if (isset($cols['user']))          $fields['user']     = 'zz-test-140';
    elseif (isset($cols['username']))  $fields['username'] = 'zz-test-140';
    if (isset($cols['passwd']))        $fields['passwd']   = password_hash('unused', PASSWORD_BCRYPT);
    elseif (isset($cols['password']))  $fields['password'] = password_hash('unused', PASSWORD_BCRYPT);
    if (isset($cols['level']))         $fields['level']    = 5;
    if (isset($cols['email']))         $fields['email']    = 'zz140@example.invalid';
    $fn = array_keys($fields);
    db_query("INSERT INTO " . tbl('user') . " (`" . implode('`,`', $fn) . "`) VALUES (" .
        implode(',', array_fill(0, count($fn), '?')) . ")", array_values($fields));
    $createdUser = (int) db_insert_id();

    // Org Admin scoped to Org A only.
    $urCols = array_column(db_fetch_all("DESCRIBE " . tbl('user_roles')), null, 'Field');
    $ur = ['user_id' => $createdUser, 'role_id' => $orgAdminRoleId, 'scope_kind' => 'org', 'scope_id' => $createdOrgA];
    if (isset($urCols['org_id']))     $ur['org_id'] = $createdOrgA;
    if (isset($urCols['granted_at'])) $ur['granted_at'] = date('Y-m-d H:i:s');
    $un = array_keys($ur);
    db_query("INSERT INTO " . tbl('user_roles') . " (`" . implode('`,`', $un) . "`) VALUES (" .
        implode(',', array_fill(0, count($un), '?')) . ")", array_values($ur));
    $createdUR = (int) db_insert_id();

    // This assertion once flaked on a long-lived dev database and looked
    // like test pollution -- it wasn't. Root cause (2026-08-16, RBAC
    // canonical-alias privilege-leak fix): sql/rbac.sql and
    // sql/run_00_rbac.php's Org Admin grant excludes admin-only codes by
    // LITERAL STRING, but sql/run_rbac_v2.php's A8 step independently
    // creates a canonical `<resource>.<verb>` alias for every permission,
    // and rbac_can() treats a code and its canonical alias as
    // interchangeable. Any re-import of the seed files after A8 has
    // canonicalized an excluded code re-grants Org Admin the alias under
    // its new name -- confirmed live, and not limited to this phase's own
    // permission (action.manage_config and action.manage_roles leaked the
    // same way). Both seed files now carry a self-healing repair DELETE
    // right after their broad grant, so this converges to correct on every
    // re-seed regardless of what ran before it; no test-side workaround
    // needed.

    $_SESSION['user_id'] = $createdUser;
    $_SESSION['member_id'] = null;
    $_SESSION['level'] = 5;
    $_SESSION['active_org_id'] = $createdOrgA;
    rbac_reset_cache();

    // NOTE: deliberately not asserting is_admin() === false here -- that
    // reflects whatever action.manage_config grants happen to exist on
    // THIS database (dev-DB state unrelated to Phase 140) and is_admin()
    // is never even called by ics_form_custom_template(). What actually
    // matters for this test is the two permission codes Phase 140 itself
    // checks, asserted directly below.
    $grantsCache = _rbac_load_grants();
    t("throwaway Org Admin (scoped to Org A) is not a true Super Admin", $grantsCache !== false && empty($grantsCache['is_super']));
    t("throwaway Org Admin holds action.manage_ics_form_types_org", rbac_can('action.manage_ics_form_types_org') === true);
    t("throwaway Org Admin does NOT hold the install-wide permission", rbac_can('action.manage_ics_form_types') === false);

    // Fixture types.
    db_query("INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id, status) VALUES (?, ?, ?, NULL, 'active')",
        ['zz140-global', 'Install-wide Type', '[{"key":"x","label":"X","type":"text"}]']);
    $typeGlobal = (int) db_insert_id();

    db_query("INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id, status) VALUES (?, ?, ?, ?, 'active')",
        ['zz140-org-a', 'Org A Type', '[{"key":"x","label":"X","type":"text"}]', $createdOrgA]);
    $typeOrgA = (int) db_insert_id();

    db_query("INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id, status) VALUES (?, ?, ?, ?, 'archived')",
        ['zz140-archived', 'Archived Org A Type', '[{"key":"x","label":"X","type":"text"}]', $createdOrgA]);
    $typeArchived = (int) db_insert_id();

    $noPermCode = 'action.__zz140_never_granted__';
    db_query("INSERT INTO {$prefix}ics_form_types (slug, form_title, fields_json, org_id, status, restrict_to_permission) VALUES (?, ?, ?, NULL, 'active', ?)",
        ['zz140-restricted', 'Restricted Type', '[{"key":"x","label":"X","type":"text"}]', $noPermCode]);
    $typeRestricted = (int) db_insert_id();

    // Org A member reading the install-wide type: allowed.
    $tpl = ics_form_custom_template($typeGlobal);
    t("Org A caller CAN load the install-wide type", $tpl !== null && $tpl['custom_type_id'] === $typeGlobal);

    // Org A member reading Org A's own type: allowed.
    $tpl = ics_form_custom_template($typeOrgA);
    t("Org A caller CAN load Org A's own org-scoped type", $tpl !== null && $tpl['custom_type_id'] === $typeOrgA);

    // Org A member reading Org A's archived type (they hold org-authoring rights): allowed.
    $tpl = ics_form_custom_template($typeArchived);
    t("Org A caller (holds org-authoring rights) CAN still load Org A's own ARCHIVED type", $tpl !== null);

    // Org A member reading a type gated on a permission they don't hold: denied.
    $tpl = ics_form_custom_template($typeRestricted);
    t("Org A caller is DENIED a type restricted to a permission they don't hold, even though it's install-wide",
        $tpl === null);

    // Nonexistent id: denied, same shape as every other denial (no enumeration signal).
    $tpl = ics_form_custom_template(999999999);
    t("a nonexistent custom_type_id returns null (same as every other denial)", $tpl === null);

    // This user's REAL authoring grant is scoped to Org A (in user_roles).
    // Switching active_org_id to Org B must NOT revoke that real grant --
    // authoring rights are checked against the row's own org via
    // rbac_can()'s $context['org_id'] override, not by comparing
    // active_org_id to the row's org_id. This is the multi-org-account
    // correctness fix: a caller's real authoring grants must not evaporate
    // just because a different org happens to be "active" in their session.
    $_SESSION['active_org_id'] = $createdOrgB;
    rbac_reset_cache();
    $tpl = ics_form_custom_template($typeOrgA);
    t("a caller with a REAL Org A authoring grant can still load Org A's type even while active_org_id=Org B",
        $tpl !== null && $tpl['custom_type_id'] === $typeOrgA);
    $tpl = ics_form_custom_template($typeGlobal);
    t("the same caller can still load the install-wide type", $tpl !== null);

    // The IDOR check proper: a SEPARATE caller who genuinely holds NO
    // authoring grant for Org A at all (only ordinary membership in Org B)
    // must be denied Org A's org-scoped type -- this is plan.md's actual
    // "the one concrete vulnerability found in review" scenario: a caller
    // with no legitimate relationship to Org A probing its type ids.
    db_query("DELETE FROM " . tbl('user_roles') . " WHERE id=?", [$createdUR]);
    $createdUR = null;
    $_SESSION['active_org_id'] = $createdOrgB;
    rbac_reset_cache();
    t("with the org-scoped role gone, the caller no longer holds the org authoring permission",
        rbac_can('action.manage_ics_form_types_org') === false);
    $tpl = ics_form_custom_template($typeOrgA);
    t("a genuinely unrelated caller (ordinary Org B membership, no Org A grant) is DENIED Org A's org-scoped type (the IDOR fix from plan.md)",
        $tpl === null);
    $tpl = ics_form_custom_template($typeGlobal);
    t("the same unrelated caller CAN still load the install-wide type", $tpl !== null);

} catch (Throwable $e) {
    t('setup/exec without error: ' . $e->getMessage(), false);
} finally {
    try { if ($typeGlobal)     db_query("DELETE FROM {$prefix}ics_form_types WHERE id=?", [$typeGlobal]); } catch (Throwable $e) {}
    try { if ($typeOrgA)       db_query("DELETE FROM {$prefix}ics_form_types WHERE id=?", [$typeOrgA]); } catch (Throwable $e) {}
    try { if ($typeArchived)   db_query("DELETE FROM {$prefix}ics_form_types WHERE id=?", [$typeArchived]); } catch (Throwable $e) {}
    try { if ($typeRestricted) db_query("DELETE FROM {$prefix}ics_form_types WHERE id=?", [$typeRestricted]); } catch (Throwable $e) {}
    try { if ($createdUR)      db_query("DELETE FROM " . tbl('user_roles') . " WHERE id=?", [$createdUR]); } catch (Throwable $e) {}
    try { if ($createdUser)    db_query("DELETE FROM " . tbl('user') . " WHERE id=?", [$createdUser]); } catch (Throwable $e) {}
    try { if ($createdOrgA)    db_query("DELETE FROM " . tbl('organizations') . " WHERE id=?", [$createdOrgA]); } catch (Throwable $e) {}
    try { if ($createdOrgB)    db_query("DELETE FROM " . tbl('organizations') . " WHERE id=?", [$createdOrgB]); } catch (Throwable $e) {}
    unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['active_org_id'], $_SESSION['level']);
    rbac_reset_cache();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
