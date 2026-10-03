<?php
/**
 * GH#142 (Phase 155) - agency logo: the print letterhead script
 * (assets/js/print-letterhead.js), driven under Node.
 *
 * The real, unmodified file is loaded with require() and exercised against a
 * minimal DOM stand-in (this project's established convention for pure-JS
 * logic; no jsdom needed):
 *
 *   - config text -> letterhead model: a valid config; broken JSON; a src that is
 *     not EXACTLY the capability URL (absolute, protocol-relative, javascript:,
 *     data:, traversal, trailing parameter, uppercase, wrong length, trailing
 *     newline) drops the letterhead; unknown size/align/banner fall back to
 *     safe defaults; alt is capped at 100 characters;
 *   - the element is built AT LOAD (synchronously in init), not lazily in
 *     beforeprint, and sits FIRST in <body>, with the size and alignment classes;
 *   - banner = replace adds body.has-letterhead, keep does not;
 *   - data-print-date is stamped on beforeprint for ANY page, including a page
 *     with no config block at all (the 8 pages that printed "Printed " with an
 *     empty date), and not before;
 *   - init is idempotent; a page with no block gets no letterhead;
 *   - the file never assigns innerHTML and never writes a closing script tag.
 *
 * Not @requires-db / @requires-http: it parses static source only.
 *
 * Usage: php tests/test_gh142_branding_letterhead_js.php
 */
$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - print-letterhead.js ===\n\n";

$base = dirname(__DIR__);
$jsPath = $base . '/assets/js/print-letterhead.js';
$src = (string) file_get_contents($jsPath);
t('print-letterhead.js exists', $src !== '');

// Static guards, on the code with comments removed (the docblock names these
// things on purpose, to say it never does them).
$code = preg_replace('~/\*.*?\*/~s', '', $src);
$code = preg_replace('~(^|[^:\'"])//[^\n]*~', '$1', $code);
t('the script never assigns innerHTML', strpos($code, 'innerHTML') === false);
t('the script never calls document.write', strpos($code, 'document.write') === false);
t('the script contains no closing script tag byte sequence anywhere (a comment would truncate the block)', stripos($src, '</script') === false);
t('ES5 only: no arrow functions, let, const or template literals', !preg_match('/=>|\blet\s|\bconst\s|`/', $code));
t('wrapped in an IIFE with use strict', strpos($src, "(function () {\n    'use strict';") !== false);

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}
if ($node === null) {
    echo "SKIP: node is not available: the script's behaviour was not exercised\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$harness = sys_get_temp_dir() . '/gh142_letterhead_' . getmypid() . '.js';
$js = "var LH = require(" . json_encode(str_replace('\\', '/', $jsPath)) . ");\n" . <<<'JS'
var out = [];
function chk(n, c, d) { out.push((c ? 'PASS|' : 'FAIL|') + n + (d ? '|' + d : '')); }

// ── a minimal DOM: just enough surface for init() ──────────────────────
function mkNode(tag) {
    var n = { tagName: tag, attrs: {}, children: [], className: '', textContent: '', parentNode: null, _classes: {} };
    n.setAttribute = function (k, v) { n.attrs[k] = String(v); if (k === 'id') n.id = String(v); };
    n.getAttribute = function (k) { return Object.prototype.hasOwnProperty.call(n.attrs, k) ? n.attrs[k] : null; };
    n.appendChild = function (c) { c.parentNode = n; n.children.push(c); return c; };
    n.insertBefore = function (c, ref) {
        c.parentNode = n;
        var i = ref ? n.children.indexOf(ref) : n.children.length;
        n.children.splice(i < 0 ? n.children.length : i, 0, c);
        return c;
    };
    Object.defineProperty(n, 'firstChild', { get: function () { return n.children.length ? n.children[0] : null; } });
    n.classList = {
        add: function (c) { n._classes[c] = true; },
        contains: function (c) { return !!n._classes[c]; }
    };
    return n;
}
function mkDoc(configText) {
    var body = mkNode('body');
    body.appendChild(mkNode('header'));          // the page's own first element
    var doc = { body: body };
    var holder = null;
    if (configText !== null) { holder = mkNode('script'); holder.setAttribute('id', 'brandingConfig'); holder.textContent = configText; body.appendChild(holder); }
    doc.createElement = mkNode;
    doc.getElementById = function (id) {
        function walk(n) { if (n.id === id) return n; for (var i = 0; i < n.children.length; i++) { var r = walk(n.children[i]); if (r) return r; } return null; }
        return walk(body);
    };
    return doc;
}
function mkWin() {
    var w = { handlers: {} };
    w.addEventListener = function (name, fn) { (w.handlers[name] = w.handlers[name] || []).push(fn); };
    w.fire = function (name) { (w.handlers[name] || []).forEach(function (f) { f(); }); };
    return w;
}
var K = 'abcdef0123456789abcdef0123456789';
var SRC = 'api/branding-logo.php?k=' + K;
function cfg(o) {
    var base = { src: SRC, alt: 'Fire Dept', size: 'medium', align: 'center', banner: 'replace', w: 120, h: 60 };
    for (var k in o) { base[k] = o[k]; }
    return JSON.stringify(base);
}

// ── parseConfig ────────────────────────────────────────────────────────
var m = LH.parseConfig(cfg({}));
chk('a valid config parses', m && m.src === SRC && m.alt === 'Fire Dept' && m.size === 'medium' && m.align === 'center' && m.banner === 'replace' && m.w === 120 && m.h === 60);
chk('broken JSON gives no letterhead', LH.parseConfig('{not json') === null);
chk('an empty string gives no letterhead', LH.parseConfig('') === null);
chk('JSON null gives no letterhead', LH.parseConfig('null') === null);
chk('a JSON array gives no letterhead', LH.parseConfig('[1,2]') === null);
var badSrc = [
    'https://evil.example/x.png', '//evil.example/x.png', 'javascript:alert(1)', 'data:image/svg+xml,<svg onload=alert(1)>',
    '../api/branding-logo.php?k=' + K, '/api/branding-logo.php?k=' + K, SRC + '&x=1', SRC.toUpperCase(),
    'api/branding-logo.php?k=' + K.substring(1), 'api/branding-logo.php?k=' + K + 'a', SRC + '\n', 'api/other.php?k=' + K, '', 5, null
];
badSrc.forEach(function (s, i) {
    chk('a src that is not the exact capability URL is refused (#' + i + ')', LH.parseConfig(JSON.stringify({ src: s, alt: 'x' })) === null);
});
chk('a missing src is refused', LH.parseConfig('{"alt":"x"}') === null);
var d = LH.parseConfig(cfg({ size: 'gigantic', align: 'diagonal', banner: 'maybe' }));
chk('an unknown size falls back to medium', d.size === 'medium');
chk('an unknown alignment falls back to center', d.align === 'center');
chk('an unknown banner mode falls back to replace', d.banner === 'replace');
chk('alt is capped at 100 characters', LH.parseConfig(cfg({ alt: new Array(301).join('x') })).alt.length === 100);
chk('a non-string alt becomes empty', LH.parseConfig(cfg({ alt: { a: 1 } })).alt === '');
chk('negative or garbage width/height become 0', LH.parseConfig(cfg({ w: -5, h: 'abc' })).w === 0 && LH.parseConfig(cfg({ w: -5, h: 'abc' })).h === 0);
['small', 'medium', 'large'].forEach(function (s) { chk('size ' + s + ' is kept', LH.parseConfig(cfg({ size: s })).size === s); });
['left', 'center', 'right'].forEach(function (a) { chk('alignment ' + a + ' is kept', LH.parseConfig(cfg({ align: a })).align === a); });

// ── buildLetterhead ────────────────────────────────────────────────────
var doc0 = mkDoc(null);
var box = LH.buildLetterhead(doc0, LH.parseConfig(cfg({ size: 'large', align: 'right', w: 300, h: 100 })));
chk('the letterhead is a div#printLetterhead', box.tagName === 'div' && box.attrs.id === 'printLetterhead');
chk('...with the alignment class', box.className === 'print-letterhead print-letterhead-align-right');
var img = box.children[0];
chk('...holding ONE img with the light-logo and size classes', box.children.length === 1 && img.tagName === 'img' && img.className === 'branding-logo branding-logo-light branding-size-print-large');
chk('...whose src is the capability URL and alt is the configured text', img.attrs.src === SRC && img.attrs.alt === 'Fire Dept');
chk('...with the intrinsic width and height for layout', img.attrs.width === '300' && img.attrs.height === '100');
var evil = '"><script>alert(1)</script>';
var eb = LH.buildLetterhead(doc0, LH.parseConfig(cfg({ alt: evil })));
chk('a hostile alt is stored as an ATTRIBUTE VALUE verbatim (inert), and no script element is created',
    eb.children[0].attrs.alt === evil && eb.children.length === 1 && eb.children[0].tagName === 'img');

// ── init: built at load, first in <body>, banner mode ──────────────────
var docR = mkDoc(cfg({ banner: 'replace', size: 'small', align: 'left' })), winR = mkWin();
var res = LH.init(docR, winR);
chk('init returns the element and puts it FIRST in <body>', res && docR.body.firstChild === res && res.attrs.id === 'printLetterhead');
chk('...before the page\'s own header', docR.body.children[1].tagName === 'header');
chk('the element exists IMMEDIATELY after init (built at load, before any beforeprint)', !!docR.getElementById('printLetterhead') && (winR.handlers.beforeprint || []).length === 1);
chk('banner = replace adds body.has-letterhead', docR.body.classList.contains('has-letterhead'));
chk('size and alignment classes come from the config', res.className.indexOf('print-letterhead-align-left') !== -1 && res.children[0].className.indexOf('branding-size-print-small') !== -1);
var docK = mkDoc(cfg({ banner: 'keep' })), winK = mkWin();
LH.init(docK, winK);
chk('banner = keep does NOT add body.has-letterhead', !docK.body.classList.contains('has-letterhead') && !!docK.getElementById('printLetterhead'));
LH.init(docK, mkWin());
var count = 0; (function walk(n) { if (n.id === 'printLetterhead') count++; n.children.forEach(walk); })(docK.body);
chk('init is idempotent (a second call adds no second letterhead)', count === 1);

// ── print date: every page, only on beforeprint ────────────────────────
chk('before beforeprint fires, no date has been stamped', docR.body.getAttribute('data-print-date') === null);
winR.fire('beforeprint');
var stamped = docR.body.getAttribute('data-print-date');
chk('beforeprint stamps data-print-date (date and time)', typeof stamped === 'string' && stamped.length > 6 && /\d/.test(stamped));
var docN = mkDoc(null), winN = mkWin();
var resN = LH.init(docN, winN);
chk('a page with NO config block gets no letterhead', resN === null && docN.getElementById('printLetterhead') === null && !docN.body.classList.contains('has-letterhead'));
chk('...and no date yet', docN.body.getAttribute('data-print-date') === null);
winN.fire('beforeprint');
chk('...but it STILL gets data-print-date on beforeprint (the pages that printed an empty date)', (docN.body.getAttribute('data-print-date') || '').length > 6);
var docB = mkDoc('{broken'), winB = mkWin();
chk('a broken config block gives no letterhead and does not throw', LH.init(docB, winB) === null && docB.getElementById('printLetterhead') === null);
winB.fire('beforeprint');
chk('...yet the print date is still stamped', (docB.body.getAttribute('data-print-date') || '').length > 6);
chk('init without a document or body does not throw', LH.init(null, mkWin()) === null && LH.init({}, mkWin()) === null);
var docS = mkDoc(JSON.stringify({ src: 'https://evil.example/p.png', alt: 'x' }));
chk('a config whose src is hostile gives no letterhead', LH.init(docS, mkWin()) === null && docS.getElementById('printLetterhead') === null);

console.log(out.join('\n'));
JS;
file_put_contents($harness, $js);
$raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' 2>&1');
@unlink($harness);

if (!is_string($raw) || strpos($raw, '|') === false) {
    t('node harness ran print-letterhead.js', false);
    echo "  raw output: " . trim((string) $raw) . "\n";
} else {
    foreach (explode("\n", trim($raw)) as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) < 2) continue;
        $detail = isset($parts[2]) && $parts[2] !== '' ? (' - ' . $parts[2]) : '';
        t('[js] ' . $parts[1] . ($parts[0] === 'PASS' ? '' : $detail), $parts[0] === 'PASS');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
