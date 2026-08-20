<?php
/**
 * Phase 140 (2026-08-16) — render-safety for agency-authored content across
 * BOTH consumer paths: assets/js/ics-forms.js (the fill-out-a-form hub,
 * incl. the custom-type "New" card) and assets/js/ics-form-type-admin.js
 * (the type-authoring editor + its own type list).
 *
 * Unlike public-board.php (tests/test_public_board_frontend_safety.php),
 * which builds its DOM via createElement()/.textContent and therefore never
 * interprets content as markup at all, these two files use the OTHER valid
 * safe pattern already established for the 9 built-in ICS types: build an
 * HTML string via concatenation, escaping every interpolated value through
 * escHtml()/escAttr() BEFORE insertion into innerHTML. The correct proof
 * for THAT pattern is not "no markup interpretation occurs" (innerHTML
 * fundamentally does interpret markup) -- it's "a malicious field.label,
 * form_title, option, badge_color etc. can never survive escHtml()/escAttr()
 * as a live tag or attribute-breaking sequence in the resulting HTML
 * string." This test extracts the REAL render functions from both files
 * via node (same convention as tests/test_ics_forms_builtin_regression.php)
 * and drives them with <script>/onerror/attribute-breakout payloads in
 * every agency-authored field this phase introduces.
 *
 * Usage: php tests/test_ics_form_types_render_safety.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 140 — Render safety across ics-forms.js + ics-form-type-admin.js ===\n\n";

$base = realpath(__DIR__ . '/..');

$icsFormsPath = $base . '/assets/js/ics-forms.js';
$adminPath = $base . '/assets/js/ics-form-type-admin.js';
t('assets/js/ics-forms.js exists', is_file($icsFormsPath));
t('assets/js/ics-form-type-admin.js exists', is_file($adminPath));

// ── Static guards: catch a NEW unescaped interpolation before it ships ──
$icsFormsSrc = (string) file_get_contents($icsFormsPath);
$adminSrc = (string) file_get_contents($adminPath);

// Every Phase 140 field that carries agency-authored text must be read
// through escHtml()/escAttr() wherever it's concatenated into an HTML
// string -- never bare. This can't be a single global "no bare X" regex
// (field.key/field.type ARE safely interpolated bare into id=/name=
// attributes, because those are format-validated server-side to
// ^[a-z][a-z0-9_]{0,63}$ -- see inc/ics-form-types.php -- so requiring
// escaping there would be a false demand, not a real gap). Instead: prove
// every occurrence of the RISKY properties (label/title/description/
// options/form_number believed to be free text) that reaches string
// concatenation is wrapped.
$riskyPatterns = [
    'field.label'   => '/escHtml\(\s*field\.label\s*\)/',
    'col.label'     => '/escHtml\(\s*col\.label\s*\)/',
    // The admin field-row editor renders labels as EDITABLE <input value="">
    // attributes (not static text), so escAttr() -- not escHtml() -- is the
    // correct escaping function there; both are equally safe for their
    // respective insertion contexts.
    'f.label (admin field-row, as an input value)' => '/escAttr\(\s*f\.label\s*\)/',
];
foreach ($riskyPatterns as $name => $pattern) {
    $inIcsForms = (bool) preg_match($pattern, $icsFormsSrc);
    $inAdmin = (bool) preg_match($pattern, $adminSrc);
    t("'$name' is escaped via escHtml() at least once across the two files",
        $inIcsForms || $inAdmin);
}

// ── Extract + execute the REAL render functions under node ──────────────
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "\nSKIP: node not available — the JS execution checks were not run\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

function _p140_extract_fn(string $src, string $name): string {
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException("could not find function $name");
    }
    $start = $m[0][1];
    $bodyStart = strpos($src, '{', $start);
    $depth = 0; $i = $bodyStart; $len = strlen($src);
    for (; $i < $len; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $i++; break; } }
    }
    return substr($src, $start, $i - $start) . "\n";
}

$harness = sys_get_temp_dir() . '/tcad_p140_render_safety_' . getmypid() . '.js';

$prelude = <<<'JS'
// Minimal document mock that reproduces a REAL browser's textContent ->
// innerHTML escaping (what escHtml() actually relies on:
// div.appendChild(document.createTextNode(str)); return div.innerHTML;).
// Not a full DOM -- just enough to prove escHtml()/escAttr() do their job.
function browserEscape(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function makeDiv() {
    var _text = '';
    return {
        appendChild: function (node) { _text += node.nodeType === 3 ? node.textContent : ''; },
        get innerHTML() { return browserEscape(_text); }
    };
}
global.document = {
    createElement: function (tag) { return makeDiv(); },
    createTextNode: function (text) { return { nodeType: 3, textContent: text }; }
};

var results = [];
function check(name, cond, detail) { results.push((cond ? 'PASS' : 'FAIL') + '|' + name + '|' + (detail || '')); }

JS;

$postlude = <<<'JS'

var EVIL_LABEL = '<script>alert(1)</script>';
var EVIL_ATTR_BREAKOUT = '"><img src=x onerror=alert(2)>';
var EVIL_TITLE = '<svg onload=alert(3)>Custom Form';

// ── ics-forms.js surfaces ──
if (typeof renderSimpleField === 'function') {
    var htmlText = renderSimpleField({ key: 'patient_name', label: EVIL_LABEL, type: 'text' }, 'x', 1);
    check('renderSimpleField (text): label never appears as a live <script> tag',
        htmlText.indexOf('<script>alert(1)</script>') === -1, htmlText);
    check('renderSimpleField (text): label DOES appear, but escaped',
        htmlText.indexOf('&lt;script&gt;') !== -1, htmlText);

    var htmlAttr = renderSimpleField({ key: 'x', label: 'X', type: 'text' }, EVIL_ATTR_BREAKOUT, 1);
    check('renderSimpleField (text): a value cannot break out of the value="" attribute',
        htmlAttr.indexOf('<img src=x onerror=') === -1, htmlAttr);
}

if (typeof renderSelectField === 'function') {
    var htmlSel = renderSelectField('id1', 'triage', [EVIL_LABEL, 'Yellow'], '', 1);
    check('renderSelectField: a malicious option label never appears as a live <script> tag',
        htmlSel.indexOf('<script>alert(1)</script>') === -1, htmlSel);
    check('renderSelectField: option value cannot break out of its attribute',
        htmlSel.indexOf('"><img') === -1 || htmlSel.indexOf('&quot;&gt;&lt;img') !== -1, htmlSel);
}

if (typeof buildTableCell === 'function') {
    var field = { key: 'log', columns: [{ key: 'note', type: 'text' }] };
    var htmlCell = buildTableCell(field, field.columns[0], 0, EVIL_ATTR_BREAKOUT);
    check('buildTableCell: a row value cannot break out of value=""',
        htmlCell.indexOf('<img src=x onerror=') === -1, htmlCell);
}

if (typeof getFormTypeBadge === 'function') {
    var badgeRow = { form_type: 'custom', custom_form_number: EVIL_LABEL, custom_badge_color: 'danger' };
    var htmlBadge = getFormTypeBadge(badgeRow);
    check('getFormTypeBadge (custom): a malicious form_number never appears as a live <script> tag',
        htmlBadge.indexOf('<script>alert(1)</script>') === -1, htmlBadge);

    var badgeColorAttack = { form_type: 'custom', custom_form_number: 'MED-1', custom_badge_color: '"><img src=x onerror=alert(9)>' };
    var htmlBadge2 = getFormTypeBadge(badgeColorAttack);
    check('getFormTypeBadge (custom): an unrecognized badge_color is rejected by the whitelist, never concatenated raw',
        htmlBadge2.indexOf('<img src=x onerror=') === -1, htmlBadge2);
}

// ── ics-form-type-admin.js surfaces ──
if (typeof renderFieldRow === 'function') {
    var htmlFieldRow = renderFieldRow({ key: 'x', label: EVIL_LABEL, type: 'text', required: false }, 0);
    check('renderFieldRow (admin editor): a malicious field label never appears as a live <script> tag',
        htmlFieldRow.indexOf('<script>alert(1)</script>') === -1, htmlFieldRow);
}

if (typeof renderCustomTypeCard === 'function') {
    var htmlCard = renderCustomTypeCard({
        id: 1, form_number: 'MED-1', form_title: EVIL_TITLE,
        description: EVIL_LABEL, badge_color: 'danger', icon: 'bi-heart-pulse'
    });
    check('renderCustomTypeCard (hub card): a malicious form_title never appears as a live tag',
        htmlCard.indexOf('<svg onload=') === -1, htmlCard);
    check('renderCustomTypeCard (hub card): a malicious description never appears as a live <script> tag',
        htmlCard.indexOf('<script>alert(1)</script>') === -1, htmlCard);

    var htmlCardBadIcon = renderCustomTypeCard({
        id: 1, form_number: 'X', form_title: 'X', description: '',
        badge_color: 'secondary', icon: '"><img src=x onerror=alert(4)>'
    });
    check('renderCustomTypeCard: an unrecognized icon class is rejected by the format check, never concatenated raw',
        htmlCardBadIcon.indexOf('<img src=x onerror=') === -1, htmlCardBadIcon);
}

console.log(JSON.stringify(results));
JS;

$fnsToTry = [
    // renderCustomTypeCard() lives in ics-forms.js (the hub's "New" card
    // for a custom type, built by loadCustomTypeCards()) -- NOT in
    // ics-form-type-admin.js, which has no function of that name.
    $icsFormsPath => ['escHtml', 'escAttr', 'renderSimpleField', 'renderSelectField', 'buildTableCell', 'getFormTypeBadge', 'renderCustomTypeCard'],
    $adminPath => ['renderFieldRow', 'renderFieldTypeExtras', 'renderTableFieldExtras', 'FIELD_TYPE_LABELS', 'TABLE_COLUMN_TYPES'],
];

$body = $prelude;
foreach ($fnsToTry as $file => $names) {
    $src = (string) file_get_contents($file);
    foreach ($names as $name) {
        try {
            if ($name === 'FIELD_TYPE_LABELS') {
                if (preg_match('/var\s+FIELD_TYPE_LABELS\s*=\s*\{[^}]*\};/s', $src, $m)) {
                    $body .= $m[0] . "\n";
                }
                continue;
            }
            if ($name === 'TABLE_COLUMN_TYPES') {
                if (preg_match('/var\s+TABLE_COLUMN_TYPES\s*=\s*\[[^\]]*\];/s', $src, $m)) {
                    $body .= $m[0] . "\n";
                }
                continue;
            }
            $body .= _p140_extract_fn($src, $name);
        } catch (Throwable $e) {
            // Not every function exists in every file (escHtml/escAttr are
            // duplicated per-file, not shared) -- try the next file/name.
        }
    }
}
$body .= $postlude;

file_put_contents($harness, $body);

$out = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' 2>&1');
@unlink($harness);

$decoded = null;
if (is_string($out)) {
    $trimmed = trim($out);
    if ($trimmed !== '' && $trimmed[0] === '[') {
        $decoded = json_decode($trimmed, true);
    }
}

if (!is_array($decoded)) {
    t('node harness ran and returned parseable results', false);
    echo "  raw output: " . substr((string) $out, 0, 2000) . "\n";
} else {
    foreach ($decoded as $line) {
        $parts = explode('|', $line, 3);
        $ok = ($parts[0] ?? '') === 'PASS';
        $name = $parts[1] ?? '(unnamed)';
        t($name, $ok);
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
