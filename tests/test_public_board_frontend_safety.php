<?php
/**
 * Phase 138 — public-board.php frontend safety (tasks.md E1b), the
 * diff-only aria-live announcer (E2), and the server-driven zoom cap (E3).
 *
 * Feeds mock incident records containing <script>/<img>/<svg>-shaped
 * strings in type/type_group/street_display/city through the REAL render
 * functions public-board.php exposes as window.PublicBoardRender — via
 * node, same convention as tests/test_tile_proxy.php's map-prefs.js
 * harness — and asserts:
 *   1. every field reaches the node tree ONLY as inert .textContent /
 *      text-node data (proven by checking the malicious string appears
 *      verbatim in the collected text, i.e. it was passed through, not
 *      dropped or altered);
 *   2. the SET of element tags the render function creates never grows
 *      to include script/img/svg/iframe/object/embed regardless of what
 *      the incident data contains — the render path never interprets
 *      content as markup, it only ever builds a fixed, hardcoded shape
 *      and hands data to .textContent (security review finding #3);
 *   3. a static guard that public-board.php's source never assigns
 *      .innerHTML or calls document.write anywhere, so a second, unsafe
 *      render path can't quietly appear beside the safe one.
 *
 * Not @requires-db / @requires-http — this never touches a database or a
 * live server; it parses and executes ONLY the static page source.
 *
 * Usage: php tests/test_public_board_frontend_safety.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — public-board.php frontend safety + diff announcer ===\n\n";

$base = realpath(__DIR__ . '/..');
$pagePath = $base . '/public-board.php';

t('public-board.php exists', is_file($pagePath));
$src = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';

/**
 * Reconstruct the file with PHP-level comments (T_COMMENT/T_DOC_COMMENT)
 * stripped, so the static assertions below check actual CODE rather than
 * matching a comment that is explaining/prohibiting the very pattern it
 * mentions (this file's own top docblock says "no session_start()" and
 * "NEVER innerHTML" in prose — a plain substring search would flag its
 * own warning as a violation). Inline HTML/JS (including the page's own
 * JS comments inside <script>) is NOT PHP-tokenized and passes through
 * untouched — this only strips the PHP-side comments.
 */
function pb_strip_php_comments(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}
$srcNoComments = $src !== '' ? pb_strip_php_comments($src) : '';

// ── Static guard: no innerHTML assignment / document.write anywhere ─────
// Dot-prefixed so a JS comment that merely says the word "innerHTML" (no
// leading `.`, i.e. not a property access) does not false-positive.
t('page source never assigns .innerHTML', strpos($srcNoComments, '.innerHTML') === false);
t('page source never calls document.write', strpos($srcNoComments, 'document.write') === false);
t('page source carries the noindex meta tag (belt-and-suspenders with the API header)',
    strpos($src, 'name="robots" content="noindex,nofollow"') !== false);
t('page never calls session_start() or requires auth.php (no credential, ever)',
    strpos($srcNoComments, 'session_start(') === false && strpos($srcNoComments, "auth.php") === false);

// ── Static guard: pbUpdateMap() must never call bindPopup() with a plain
//    string — it must go through pbPopupContentNode() (a DOM node).
//    Leaflet's DivOverlay._updateContent() sets .innerHTML directly for a
//    STRING popup-content argument with no escaping at all (verified
//    against assets/vendor/leaflet/leaflet.js — a prior comment on this
//    line claiming "Leaflet escapes bound popup text by default" was
//    simply wrong). This guard catches a regression reintroducing
//    `marker.bindPopup(String(...))` even though that line itself never
//    contains the literal ".innerHTML" the guard above already checks for.
t('pbUpdateMap() calls bindPopup() with pbPopupContentNode(...), never a bare String(...)',
    strpos($srcNoComments, 'marker.bindPopup(pbPopupContentNode(') !== false
    && strpos($srcNoComments, 'bindPopup(String(') === false);

// ── Extract the ONE inline <script> with no src= attribute ──────────────
$jsBody = '';
if (preg_match('/<script>([\s\S]*)<\/script>/', $src, $m)) {
    $jsBody = $m[1];
}
t('extracted the inline <script> body from public-board.php', trim($jsBody) !== '');

/**
 * Regression guard for a bug this test's own PHP-regex extraction above
 * CANNOT catch: a real browser's HTML tokenizer terminates a <script>
 * element at the very FIRST literal "</script" byte sequence it sees in
 * the element's raw text — including inside a JS block comment, and with
 * zero regard for JS syntax. This bit public-board.php once already: a
 * docblock comment spelled out an example "...</script>" string to
 * explain WHY textContent is safe, and that literal sequence silently
 * truncated the real script block mid-function, producing a live
 * "Uncaught SyntaxError: Invalid or unexpected token" in the browser that
 * every check above (PHP lint, this file's OWN node harness — which
 * greedily regex-extracts to the LAST "</script>", not the first, so it
 * never reproduced the browser's actual behavior) missed entirely. Only
 * loading the real page in a real browser caught it. This assertion
 * encodes that HTML-tokenization rule directly so a regression fails a
 * fast, no-browser test instead of shipping silently again.
 */
$openPos = strpos($src, '<script>');
t('found the inline <script> opening tag (no src=) to scan', $openPos !== false);
if ($openPos !== false) {
    $searchFrom = $openPos + strlen('<script>');
    $firstClose = stripos($src, '</script', $searchFrom);
    $lastClose  = strripos($src, '</script');
    t('no stray "</script" sequence appears anywhere before the real closing tag '
        . '(a literal occurrence inside a comment silently truncates the script — see comment above)',
        $firstClose !== false && $lastClose !== false && $firstClose === $lastClose);
}

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null || trim($jsBody) === '') {
    echo "SKIP: node not available (or script extraction failed) — the JS execution checks were not run\n";
} else {
    $harness = sys_get_temp_dir() . '/tcad_pb_frontend_harness_' . getmypid() . '.js';

    $prelude = <<<'JS'
// Minimal stand-in for `document`/`window`. Deliberately NOT a real DOM:
// it never parses .textContent/.createTextNode content as markup — same
// as a real browser never does either. The point of this harness is to
// prove the render function only ever hands incident-sourced strings to
// properties a real browser treats as inert text, never to innerHTML or
// anything that would parse them.
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var createdTags = [];

function makeNode(tag) {
    createdTags.push(tag);
    return {
        tagName: tag,
        className: '',
        textContent: '',
        _attrs: {},
        _children: [],
        setAttribute: function (k, v) { this._attrs[k] = v; },
        appendChild: function (child) { this._children.push(child); return child; }
    };
}

var fakeDoc = {
    createElement: function (tag) { return makeNode(tag); },
    createTextNode: function (text) { return { nodeType: 3, textContent: text }; }
};

function collectText(node, acc) {
    if (node.textContent) acc.push(node.textContent);
    var i;
    for (i = 0; i < node._children.length; i++) {
        var c = node._children[i];
        if (c.nodeType === 3) { if (c.textContent) acc.push(c.textContent); }
        else { collectText(c, acc); }
    }
}

global.window = global; // so `window.PublicBoardRender = ...` inside the page script lands somewhere we can read

JS;

    $postlude = <<<'JS'

var PB = (typeof window !== 'undefined' && window.PublicBoardRender) ? window.PublicBoardRender : null;
check('PublicBoardRender loaded from public-board.php', !!PB);

var EVIL_TYPE   = '<script>alert(1)</script>Grass Fire';
var EVIL_STREET = '<img src=x onerror=alert(2)>Main St';
var EVIL_CITY   = '<b>Notacity</b>';
var EVIL_GROUP  = '"><svg onload=alert(3)>';

var incident = {
    id: 4821,
    type: EVIL_TYPE,
    type_group: EVIL_GROUP,
    severity_text: 'High',
    opened: new Date().toISOString(),
    updated: new Date().toISOString(),
    assigned_units: 2,
    street_display: EVIL_STREET,
    city: EVIL_CITY,
    state: 'MN',
    lat: 44.8,
    lng: -93.3
};

createdTags = [];
var card = PB ? PB.createCard(fakeDoc, incident) : null;
check('createCard returns a node', !!card);

var texts = [];
if (card) collectText(card, texts);
var joined = texts.join(' | ');

check('evil type string reached the tree as inert text', joined.indexOf(EVIL_TYPE) !== -1, joined);
check('evil street string reached the tree as inert text', joined.indexOf(EVIL_STREET) !== -1, joined);
check('evil group string reached the tree as inert text', joined.indexOf(EVIL_GROUP) !== -1, joined);

var unsafeTags = ['script', 'img', 'svg', 'iframe', 'object', 'embed'];
var sawUnsafeTag = false;
var i;
for (i = 0; i < createdTags.length; i++) {
    if (unsafeTags.indexOf(String(createdTags[i]).toLowerCase()) !== -1) sawUnsafeTag = true;
}
check('render function never created a script/img/svg/iframe/object/embed element from incident data',
      !sawUnsafeTag, createdTags.join(','));
check('render function created only a small, fixed element set (structure did not grow with the payload)',
      createdTags.length > 0 && createdTags.length < 10, createdTags.join(','));

// ── Map popup content (correctness/value review finding) — Leaflet's
//    bindPopup() sets .innerHTML for a STRING argument with no escaping at
//    all; the fix is to hand it a DOM node built the same textContent-only
//    way as pbCreateCard(). Verify popupContentNode() never creates an
//    unsafe tag and carries the incident type as inert text only. ──
createdTags = [];
var popupNode = PB && PB.popupContentNode ? PB.popupContentNode(fakeDoc, { type: EVIL_TYPE }) : null;
check('popupContentNode exists and returns a node', !!popupNode);
var popupTexts = [];
if (popupNode) collectText(popupNode, popupTexts);
check('popup node carries the evil type string as inert text only', popupTexts.join(' ').indexOf(EVIL_TYPE) !== -1);
check('popup node is not a script/img/svg/iframe/object/embed element',
      unsafeTags.indexOf(String(popupNode && popupNode.tagName).toLowerCase()) === -1);
check('popupContentNode never created an unsafe element',
      createdTags.every(function (tg) { return unsafeTags.indexOf(String(tg).toLowerCase()) === -1; }),
      createdTags.join(','));

// ── Presence-only stub (4-key payload, tasks.md B5's exact shape) — must
//    render without throwing and without inventing address/severity text
//    that was never in the payload. ──
createdTags = [];
var stub = { id: 99, type: 'Response', opened: new Date().toISOString(), assigned_units: 1 };
var stubCard = PB ? PB.createCard(fakeDoc, stub) : null;
check('presence-only stub renders without throwing', !!stubCard);
var stubTexts = [];
if (stubCard) collectText(stubCard, stubTexts);
check('presence-only stub shows no location text (no street/city keys at all)',
      PB && PB.locationText(stub) === '');

// ── locationText: placeholder collapsing + full detail (pure function) ──
check('locationText joins street/city/state when distinct',
      PB.locationText({ street_display: '123 Main St', city: 'your deployment', state: 'MN' }) === '123 Main St, your deployment, MN');
check('locationText collapses a repeated placeholder (eoc_show_address=0) to ONE copy',
      PB.locationText({ street_display: 'Location withheld', city: 'Location withheld' }) === 'Location withheld');
check('locationText falls back to city/state when no street_display key',
      PB.locationText({ city: 'your deployment', state: 'MN' }) === 'your deployment, MN');
check('locationText is empty when no location keys at all', PB.locationText({}) === '');

// ── Diff-only announcer (E2) — pure, no DOM ──────────────────────────────
var d1 = PB.diffIncidentIds([1, 2, 3], [1, 2, 3]);
check('unchanged incident set: no additions', d1.added.length === 0);
check('unchanged incident set: no removals', d1.removed.length === 0);
check('unchanged incident set: no announcement text (must not chatter every 15s poll)', PB.announcementText(d1) === '');

var d2 = PB.diffIncidentIds([1, 2], [1, 2, 3]);
check('new incident detected', d2.added.length === 1 && d2.added[0] === 3);
check('announcement mentions the new incident (singular)', PB.announcementText(d2) === '1 new incident');

var d3 = PB.diffIncidentIds([1, 2, 3], [1]);
check('closed incidents detected', d3.removed.length === 2);
check('announcement mentions closed incidents (plural)', PB.announcementText(d3) === '2 incidents closed');

var d4 = PB.diffIncidentIds([1], [2]);
check('simultaneous add+remove both reported', d4.added.length === 1 && d4.removed.length === 1);
check('announcement joins add+remove clauses', PB.announcementText(d4) === '1 new incident; 1 incident closed');

// ── Zoom cap (E3) — driven by server precision_level, never guessed ─────
check('maxZoomFor(block) === 16', PB.maxZoomFor('block') === 16);
check('maxZoomFor(city) === 13', PB.maxZoomFor('city') === 13);
check('maxZoomFor(exact) === null (no cap)', PB.maxZoomFor('exact') === null);
check('maxZoomFor(hidden) === null (no cap)', PB.maxZoomFor('hidden') === null);

console.log(out.join('\n'));
JS;

    file_put_contents($harness, $prelude . "\n" . $jsBody . "\n" . $postlude);
    $raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' 2>&1');
    @unlink($harness);

    if (!is_string($raw) || strpos($raw, '|') === false) {
        t('node harness ran public-board.php\'s inline script', false);
        echo "  raw output: " . trim((string) $raw) . "\n";
    } else {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', $line, 3);
            if (count($parts) < 2) continue;
            $detail = isset($parts[2]) && $parts[2] !== '' ? (' — ' . $parts[2]) : '';
            t('[js] ' . $parts[1] . ($parts[0] === 'PASS' ? '' : $detail), $parts[0] === 'PASS');
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
