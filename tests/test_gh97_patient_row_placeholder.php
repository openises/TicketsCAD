<?php
/**
 * GH TicketsCAD#97 (rjonesbsink, 2026-08-20) — the Patients section on
 * incident-detail.php never showed more than one patient. addPatientRow()'s
 * placeholder-clearing guard matched the "No patients recorded." placeholder
 * by the shared Bootstrap utility class `.text-body-secondary` — but a real
 * rendered patient row carries that SAME class four times over (the
 * Insurance / Facility / Facility Contact labels, plus the patient-dob-age
 * span). So the moment the container held exactly one patient row,
 * `container.querySelector('.text-body-secondary')` matched a label INSIDE
 * that row (not the placeholder), and `container.innerHTML = ''` wiped it.
 * This broke both directions: adding a second patient made the first
 * vanish, and simply loading an incident that already had 2+ patients in
 * the database rendered only the last one (the loop in loadPatients() calls
 * addPatientRow() once per row, so the same wrong-match fires on every
 * iteration after the first). No data was ever lost — this is a pure
 * rendering bug (confirmed by the reporter directly against the API).
 *
 * THE FIX: the placeholder now carries its own data-placeholder="1" marker
 * (assets/js/incident-detail.js, loadPatients()'s empty-state branch), and
 * addPatientRow()'s guard checks that the container's SINGLE existing child
 * genuinely IS that placeholder (container.children[0].getAttribute(
 * 'data-placeholder') === '1') rather than that the container merely
 * CONTAINS a descendant sharing a class name with it.
 *
 * ── Coverage shape, matching this project's established conventions ──
 *
 * 1. Structural checks (always run, no runtime needed) — same convention
 *    as tests/test_incident_detail_ui_fixes.php for this exact file
 *    (docs/CI-ENVIRONMENT.md: CI has no Node, PHP only).
 *
 * 2. Functional checks under Node, when available locally (same
 *    node-detection + graceful-SKIP pattern as
 *    tests/test_gh62_callboard_elapsed_freeze.php) — these extract and
 *    RUN the real, unmodified loadPatients() and addPatientRow() function
 *    bodies verbatim from the shipped file (the same brace-matching
 *    extraction technique test_gh62 uses), wrapped in a minimal
 *    purpose-built DOM shim (this codebase has no jsdom dependency). The
 *    shim is deliberately narrow — just enough surface for these two
 *    functions to run against real object identity — not a general HTML
 *    parser. Functions outside this bug's scope (getIncidentId,
 *    updatePatientCountBadge, loadInsuranceTypesList, loadFacilitiesList,
 *    escHtml, escHtmlAttr) are stubbed, not extracted, since faithfully
 *    reproducing their own behavior is not what this bug is about.
 *
 *    Scenario A drives the real addPatientRow() directly: start from the
 *    exact placeholder markup, add a first patient, capture the resulting
 *    row's object identity, add a second patient, and assert the FIRST
 *    row is still present (same object, not wiped-and-regrown) and the
 *    container now holds both.
 *
 *    Scenario B drives the real loadPatients() (its actual fetch().then()
 *    .then() promise chain, against a stubbed fetch() returning two
 *    patients) and asserts BOTH rows render, not just the last one —
 *    reproducing the reporter's second report verbatim ("opening an
 *    incident that already has two patients shows only the last one").
 *
 * Proven to catch the original bug: this file was run once against the
 * PRE-FIX source (the old `.text-body-secondary` querySelector guard,
 * before the data-placeholder marker existed) via a throwaway `git stash`
 * of the fix — both [js] Scenario A/B assertions failed exactly as the
 * report describes (row count froze at 1 instead of growing to 2). See
 * the commit message / handoff notes for that run's output.
 */

$root = dirname(__DIR__);
$jsPath = $root . '/assets/js/incident-detail.js';
$src = (string) file_get_contents($jsPath);

$pass = 0; $fail = 0;
function g97(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH TicketsCAD#97: incident-detail patient row placeholder-clearing bug ===\n\n";

// ── 1. Structural checks (always run) ────────────────────────────────────

g97('loadPatients()\'s empty-state placeholder carries data-placeholder="1"',
    strpos($src, '<div class="text-body-secondary small" data-placeholder="1">No patients recorded.</div>') !== false);

$addStart = strpos($src, 'function addPatientRow(patient)');
g97('found addPatientRow()', $addStart !== false);
$addGuardWindow = $addStart !== false ? substr($src, $addStart, 1200) : '';

g97('addPatientRow()\'s guard no longer queries the shared ".text-body-secondary" class',
    strpos($addGuardWindow, "querySelector('.text-body-secondary')") === false,
    'the old, buggy selector is still present — it matches a label INSIDE a real patient row');

g97('addPatientRow()\'s guard checks the single child\'s data-placeholder attribute',
    (bool) preg_match('/container\.children\.length === 1\)\s*\?\s*container\.children\[0\]\s*:\s*null;\s*\n\s*if \(onlyChild && onlyChild\.getAttribute\([\'"]data-placeholder[\'"]\) === [\'"]1[\'"]\)/', $addGuardWindow));

// Regression guard on the bug's own root cause: the row template (the
// div.innerHTML template string, AFTER the guard/comment block above it)
// must STILL carry '.text-body-secondary' in multiple places — proving the
// fix works despite that overlap, not because the overlap was removed.
$rowTemplateStart = $addStart !== false ? strpos($src, "var id = patient", $addStart) : false;
$rowTemplateEnd = ($rowTemplateStart !== false) ? strpos($src, 'container.appendChild(div);', $rowTemplateStart) : false;
$rowTemplate = ($rowTemplateStart !== false && $rowTemplateEnd !== false) ? substr($src, $rowTemplateStart, $rowTemplateEnd - $rowTemplateStart) : '';
$overlapCount = substr_count($rowTemplate, 'text-body-secondary');
g97('a real patient row template still carries "text-body-secondary" 4 times (the bug\'s actual root cause is still present, unrelated to the fix)',
    $overlapCount === 4, "found $overlapCount occurrence(s)");

// ── 2. Functional checks under Node (skip gracefully if unavailable) ────

function extract_fn(string $src, string $name): ?string {
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) return null;
    $depth = 0; $i = $start; $len = strlen($src); $started = false;
    for (; $i < $len; $i++) {
        if ($src[$i] === '{') { $depth++; $started = true; }
        elseif ($src[$i] === '}') {
            $depth--;
            if ($started && $depth === 0) { $i++; break; }
        }
    }
    return substr($src, $start, $i - $start);
}

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the addPatientRow()/loadPatients() execution checks were not run\n";
} else {
    $loadPatientsSrc = extract_fn($src, 'loadPatients');
    $addPatientRowSrc = extract_fn($src, 'addPatientRow');

    g97('extracted loadPatients() from incident-detail.js', $loadPatientsSrc !== null);
    g97('extracted addPatientRow() from incident-detail.js', $addPatientRowSrc !== null);

    if ($loadPatientsSrc !== null && $addPatientRowSrc !== null) {
        $harness = build_gh97_harness($loadPatientsSrc, $addPatientRowSrc);
        $h = sys_get_temp_dir() . '/tcad_gh97_harness_' . getmypid() . '_' . mt_rand() . '.js';
        file_put_contents($h, $harness);
        $raw = @shell_exec($node . ' ' . escapeshellarg($h) . ' 2>&1');
        @unlink($h);

        if (!is_string($raw) || trim($raw) === '') {
            g97('node harness produced output', false, 'no output — see harness for a syntax error');
        } else {
            $sawSummary = false;
            foreach (explode("\n", trim($raw)) as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) >= 2 && ($parts[0] === 'PASS' || $parts[0] === 'FAIL')) {
                    g97('[js] ' . $parts[1], $parts[0] === 'PASS', $parts[2] ?? '');
                    $sawSummary = true;
                } else {
                    echo "  (harness) $line\n";
                }
            }
            if (!$sawSummary) {
                g97('node harness reported at least one result', false, "raw output: $raw");
            }
        }
    }
}

/**
 * Builds the Node harness: a minimal, purpose-built DOM shim (no jsdom
 * dependency in this project) sufficient to run the REAL, verbatim-
 * extracted loadPatients()/addPatientRow() function bodies and observe
 * object identity across calls.
 */
function build_gh97_harness(string $loadPatientsSrc, string $addPatientRowSrc): string {
    $stubs = <<<'JS'
"use strict";

// ── minimal, purpose-built DOM shim (this project has no jsdom dep) ──
function parseAttrs(attrStr) {
    var attrs = {};
    var re = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*"([^"]*)"/g;
    var m;
    while ((m = re.exec(attrStr))) { attrs[m[1]] = m[2]; }
    return attrs;
}
function classInHtml(html, cls) {
    var re = /class="([^"]*)"/g, mm;
    while ((mm = re.exec(html))) {
        if (mm[1].split(/\s+/).indexOf(cls) !== -1) return true;
    }
    return false;
}
function FakeElement(tag) {
    this.tagName = (tag || 'div').toUpperCase();
    this._innerHTML = '';
    this._children = [];
    this._attrs = {};
    this.className = '';
    this.dataset = {};
}
Object.defineProperty(FakeElement.prototype, 'innerHTML', {
    get: function () { return this._innerHTML; },
    set: function (v) {
        this._innerHTML = v;
        this._children = [];
        if (v && v.trim() !== '') {
            var m = v.match(/^<(\w+)([^>]*)>/);
            if (m) {
                var child = new FakeElement(m[1]);
                child._attrs = parseAttrs(m[2]);
                child.className = child._attrs['class'] || '';
                child._innerHTML = v;
                this._children.push(child);
            }
        }
    }
});
Object.defineProperty(FakeElement.prototype, 'children', {
    get: function () { return this._children; }
});
FakeElement.prototype.getAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(this._attrs, name) ? this._attrs[name] : null;
};
FakeElement.prototype.setAttribute = function (name, val) { this._attrs[name] = String(val); };
FakeElement.prototype.appendChild = function (el) { this._children.push(el); return el; };
FakeElement.prototype.querySelector = function (sel) {
    if (sel.charAt(0) !== '.') return null;
    var cls = sel.slice(1);
    for (var i = 0; i < this._children.length; i++) {
        var c = this._children[i];
        if ((c.className || '').split(/\s+/).indexOf(cls) !== -1) return c;
        if (c._innerHTML && classInHtml(c._innerHTML, cls)) {
            var stub = new FakeElement('span');
            stub.className = cls;
            return stub;
        }
    }
    return null;
};
FakeElement.prototype.querySelectorAll = function (sel) {
    if (sel.charAt(0) !== '.') return [];
    var cls = sel.slice(1);
    return this._children.filter(function (c) {
        return (c.className || '').split(/\s+/).indexOf(cls) !== -1;
    });
};
FakeElement.prototype.addEventListener = function () {};
FakeElement.prototype.focus = function () {};

var __container = new FakeElement('div');
var document = {
    getElementById: function (id) { return (id === 'patientList') ? __container : null; },
    createElement: function (tag) { return new FakeElement(tag); }
};
var window = {};

// ── stubs for functions outside this bug's scope ──
function getIncidentId() { return '999'; }
function updatePatientCountBadge() {}
function loadInsuranceTypesList(cb) { /* deliberately never invokes cb — async populate is not part of this bug */ }
function loadFacilitiesList(cb) { /* deliberately never invokes cb */ }
function escHtml(s) { return (s === null || s === undefined) ? '' : String(s); }
function escHtmlAttr(s) { return (s === null || s === undefined) ? '' : String(s); }

var __fetchPayload = { patients: [ { id: 3, name: 'John Miller' }, { id: 4, name: 'Jake Smith' } ] };
function fetch() {
    return Promise.resolve({ json: function () { return Promise.resolve(__fetchPayload); } });
}

var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

JS;

    $scenarios = <<<'JS'

// ═══ Scenario A: add a SECOND patient to a ticket that already has one ═══
// (drives the real, unmodified addPatientRow() directly)
__container.innerHTML = '<div class="text-body-secondary small" data-placeholder="1">No patients recorded.</div>';
check('[A] starts with exactly one child (the placeholder)', __container.children.length === 1,
      'children=' + __container.children.length);

addPatientRow({ id: 101, name: 'Patient A' });
check('[A] after adding the FIRST patient, the placeholder is gone and exactly one row remains',
      __container.children.length === 1, 'children=' + __container.children.length);
var firstRowRef = __container.children.length === 1 ? __container.children[0] : null;

addPatientRow({ id: 102, name: 'Patient B' });
check('[A] after adding a SECOND patient, both rows are present (container has 2 children)',
      __container.children.length === 2, 'children=' + __container.children.length);
check('[A] the FIRST row is the SAME object as before — not wiped and silently regrown',
      firstRowRef !== null && __container.children.indexOf(firstRowRef) !== -1,
      'indexOf=' + (firstRowRef ? __container.children.indexOf(firstRowRef) : 'n/a'));

// ═══ Scenario B: load an incident that ALREADY has 2+ patients in the DB ═══
// (drives the real, unmodified loadPatients(), including its actual
// fetch().then().then() promise chain, against a stubbed fetch())
__container.innerHTML = ''; // simulate a fresh page load
loadPatients();

// loadPatients()'s chain runs over real native Promises already resolved
// synchronously by the fetch() stub; a few chained .then() ticks drain the
// microtask queue before we assert.
Promise.resolve().then(function () {}).then(function () {}).then(function () {}).then(function () {
    check('[B] loadPatients() with 2 existing patients renders BOTH rows, not just the last one',
          __container.children.length === 2, 'children=' + __container.children.length);
    console.log(out.join('\n'));
});
JS;

    return $stubs . "\n" . $loadPatientsSrc . "\n" . $addPatientRowSrc . "\n" . $scenarios . "\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
