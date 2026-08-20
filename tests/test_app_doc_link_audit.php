<?php
/**
 * In-app documentation link audit gate (GH#81, 2026-08-18).
 *
 * A Windows/IIS user reported that every in-app "Learn more" / "Setup guide"
 * / "Operator guide" link 404s: they were raw filesystem paths
 * (`<a href="docs/HTTPS-SETUP.md">`), and IIS has no MIME mapping for `.md`
 * and no rewrite rule under docs/ — 404.3, even though the file exists and
 * is readable. Apache happens to serve `.md` as plain text, which is why 15
 * of these accumulated across many phases without ever failing on a
 * developer's local (Apache-fronted XAMPP) box. The fix repoints every one
 * of them at the doc viewer this project already ships
 * (`documentation/?doc=NAME` — a plain folder + query string, no rewrite
 * rules needed on any server), the same mechanism one link (settings.php's
 * EXTERNAL-API.md reference) already used correctly.
 *
 * This drives the REAL tool (tools/app_doc_link_audit.php) two ways:
 *   1. Against the actual app tree — proves the 15 known instances are
 *      fixed and stay fixed (a regression here means someone pasted a raw
 *      docs/*.md href back in).
 *   2. Against synthetic fixtures outside the repo — proves the detector
 *      actually fires on the bad shape and stays silent on the fixed shape,
 *      the leading-slash variant, a fragment-carrying variant, and on a
 *      docs/*.md filename mentioned only in prose (no href= — not a
 *      clickable link, not what IIS 404s on).
 *
 * Usage: php tests/test_app_doc_link_audit.php
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');
$tool = $base . '/tools/app_doc_link_audit.php';

echo "=== In-app documentation link audit gate (GH#81) ===\n\n";
$pass = 0;
$fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void {
    global $fail; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

/** Run the audit against a directory; return [exitCode, output]. */
function adla_run(string $tool, string $path): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool)
         . ' ' . escapeshellarg('--path=' . $path);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

// ── 1. The real app tree, right now, must be clean ──────────────────────────
[$code, $out] = adla_run($tool, $base);
echo $out . "\n\n";
is_true($code === 0, 'no NEW raw docs/*.md links in the app tree (the 15 GH#81 instances stay fixed)',
    'app_doc_link_audit.php exited ' . $code . ' against the real tree');

// ── 2. Synthetic fixtures, outside the repo so a crash can't leave drifted
//    markup behind for the next real-tree run to trip over ────────────────
$tmp = sys_get_temp_dir() . '/adla_fixtures_' . getmypid();
@mkdir($tmp, 0777, true);

function adla_write(string $path, string $content): void
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}

// Bad: plain raw link, the exact GH#81 shape.
adla_write("$tmp/plain-bad/page.php",
    '<a href="docs/HTTPS-SETUP.md" target="_blank">How to enable HTTPS</a>');

// Bad: leading slash — same 404 on IIS, just an absolute path.
adla_write("$tmp/slash-bad/page.php",
    '<a href="/docs/INSTALL.md">Install Guide</a>');

// Bad: fragment-carrying raw link (time-entries.php's exact original shape).
adla_write("$tmp/frag-bad/page.php",
    '<a href="docs/NEWUI-USER-GUIDE.md#part-13b-logging-volunteer-hours">user guide</a>');

// Bad: single-quoted, JS-built string (roster.js's exact original shape).
adla_write("$tmp/js-bad/widget.js",
    "html += '<a href=\"docs/ACCESS-CHAIN.md\" target=\"_blank\">Learn more</a>';");

// Good: routed through the viewer — the actual fix shape.
adla_write("$tmp/good/page.php",
    '<a href="documentation/?doc=HTTPS-SETUP" target="_blank">How to enable HTTPS</a>');

// Good: viewer link with a fragment carried over unchanged.
adla_write("$tmp/good-frag/page.php",
    '<a href="documentation/?doc=NEWUI-USER-GUIDE#part-13b-logging-volunteer-hours">user guide</a>');

// Good: the doc's filename mentioned in prose, never inside an href= — this
// is not a clickable link, so it is not what a browser 404s on, and must
// not be flagged (help.php has several of these on purpose, e.g.
// "See <code>docs/PUBLIC-INCIDENT-BOARD.md</code> in the repository").
adla_write("$tmp/good-prose/page.php",
    '<p>See <code>docs/PUBLIC-INCIDENT-BOARD.md</code> in the repository for details.</p>');

[$c1, $o1] = adla_run($tool, "$tmp/plain-bad");
is_true($c1 === 1 && strpos($o1, 'HTTPS-SETUP.md') !== false,
    'plain raw href="docs/HTTPS-SETUP.md" IS flagged');

[$c2, $o2] = adla_run($tool, "$tmp/slash-bad");
is_true($c2 === 1 && strpos($o2, 'INSTALL.md') !== false,
    'leading-slash href="/docs/INSTALL.md" IS flagged');

[$c3, $o3] = adla_run($tool, "$tmp/frag-bad");
is_true($c3 === 1 && strpos($o3, 'NEWUI-USER-GUIDE.md#part-13b-logging-volunteer-hours') !== false,
    'fragment-carrying raw link IS flagged, fragment included in the finding');

[$c4, $o4] = adla_run($tool, "$tmp/js-bad");
is_true($c4 === 1 && strpos($o4, 'ACCESS-CHAIN.md') !== false,
    'raw link built as a JS string (.js file) IS flagged, not just .php');

[$c5, $o5] = adla_run($tool, "$tmp/good");
is_true($c5 === 0 && strpos($o5, '0 new') !== false,
    'the fixed shape (documentation/?doc=NAME) is NOT flagged');

[$c6, $o6] = adla_run($tool, "$tmp/good-frag");
is_true($c6 === 0, 'the fixed shape with a fragment is NOT flagged');

[$c7, $o7] = adla_run($tool, "$tmp/good-prose");
is_true($c7 === 0,
    'a docs/*.md filename mentioned in prose (no href=) is NOT flagged — not a clickable link');

// ── 3. Excluded directories (docs/, documentation/, tests/, tools/, sql/,
//    specs/) must not be scanned even if they contain the bad shape — a
//    doc's own body text or a test fixture simulating the bug is not the
//    app UI this gate exists to protect ───────────────────────────────────
adla_write("$tmp/excl/docs/somefile.php",
    '<a href="docs/HTTPS-SETUP.md">bad but inside docs/</a>');
adla_write("$tmp/excl/tests/fixture.php",
    '<a href="docs/HTTPS-SETUP.md">bad but inside tests/</a>');
[$c8, $o8] = adla_run($tool, "$tmp/excl");
is_true($c8 === 0, 'the bad shape inside docs/ or tests/ is NOT scanned (excluded directories)');

// ── Cleanup ──────────────────────────────────────────────────────────────
function adla_rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) ? adla_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
adla_rrmdir($tmp);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
