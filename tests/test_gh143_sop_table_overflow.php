<?php
/**
 * GH#143 -- a table wider than the screen in an SOP page must scroll horizontally.
 *
 * The fix is one CSS rule (assets/css/sop.css: `.sop-content table { display:block;
 * max-width:100%; overflow-x:auto }`), verified once by hand and in a browser during the
 * Phase 155 re-check. It shipped with no regression test, and the reporter's retest failed
 * for two reasons that were not the rule: the public repo did not have it yet, and sop.php
 * linked the stylesheet with no version, so a cached copy masked the fix (Apache sends no
 * Cache-Control for static files; training sits behind a 4-hour Cloudflare cache; VERSION
 * does not change between syncs). This test pins all of it.
 *
 * It parses the REAL rule blocks of sop.css (not a substring search of a file whose
 * comments mention these words) and the real sop.php / sop.js.
 *
 * Usage: php tests/test_gh143_sop_table_overflow.php
 */
$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}
$root = dirname(__DIR__);
$css = file_get_contents($root . '/assets/css/sop.css');
$php = file_get_contents($root . '/sop.php');
$js  = file_get_contents($root . '/assets/js/sop.js');

/** Declarations of the first rule whose selector list contains $selector, within $text. */
function css_rule(string $text, string $selector): ?array {
    $text = preg_replace('#/\*.*?\*/#s', '', $text);      // comments can mention anything
    if (!preg_match_all('/([^{}]+)\{([^{}]*)\}/', $text, $m, PREG_SET_ORDER)) return null;
    foreach ($m as $rule) {
        $selectors = array_map('trim', explode(',', $rule[1]));
        if (in_array($selector, $selectors, true)) {
            $decl = [];
            foreach (explode(';', $rule[2]) as $d) {
                if (strpos($d, ':') === false) continue;
                [$k, $v] = array_map('trim', explode(':', $d, 2));
                $decl[strtolower($k)] = strtolower(preg_replace('/\s*!important/', '', $v));
            }
            return $decl;
        }
    }
    return null;
}

echo "=== GH#143 SOP wide-table overflow ===\n\n--- the rule ---\n\n";
$screen = css_rule(preg_replace('/@media print\s*\{.*\}\s*$/s', '', $css), '.sop-content table');
t('a screen rule for ".sop-content table" exists', $screen !== null);
t('...it is display:block (so overflow applies to the table box)', ($screen['display'] ?? '') === 'block');
t('...it never exceeds its container (max-width:100%)', ($screen['max-width'] ?? '') === '100%');
t('...it scrolls horizontally instead of overflowing the page (overflow-x:auto)', ($screen['overflow-x'] ?? '') === 'auto');

preg_match('/@media print\s*\{(.*)\}\s*$/s', $css, $pm);
$print = css_rule($pm[1] ?? '', '.sop-content table');
t('printing resets the table to a real table (display:table) so the page prints in full', ($print['display'] ?? '') === 'table');
t('...and does not clip it (overflow-x:visible)', ($print['overflow-x'] ?? '') === 'visible');

echo "\n--- every place rendered markdown lands uses that class ---\n\n";
foreach (['pageContent' => 'viewer', 'revisionContent' => 'revision view', 'editPreview' => 'editor preview'] as $id => $label) {
    t("sop.js renders markdown into #$id ($label)", (bool) preg_match('/\b' . $id . '\.innerHTML\s*=\s*renderMarkdown\(/', $js));
    t("sop.php gives #$id the sop-content class", (bool) preg_match('/<div[^>]*\bid="' . $id . '"[^>]*>/', $php, $dm) && strpos($dm[0], 'sop-content') !== false
        || (bool) preg_match('/<div[^>]*class="[^"]*sop-content[^"]*"[^>]*\bid="' . $id . '"/', $php));
}

echo "\n--- a cached stylesheet must not mask the fix ---\n\n";
t('sop.css is linked with a cache-busting ?v=', (bool) preg_match('#assets/css/sop\.css\?v=#', $php));
t('...that version is the file\'s own mtime (changes exactly when the file does), not VERSION',
    (bool) preg_match('#sop\.css\?v=<\?php echo file_exists\(__DIR__ \. \'/assets/css/sop\.css\'\) \? filemtime\(#', $php));
t('sop.js is versioned by mtime too (VERSION does not change between syncs)', (bool) preg_match('#sop\.js\?v=<\?php echo file_exists\(__DIR__ \. \'/assets/js/sop\.js\'\) \? filemtime\(#', $php));
t('dashboard.css is versioned', (bool) preg_match('#dashboard\.css\?v=<\?php#', $php));

echo "\n--- long unbroken text in the related plain-text surfaces ---\n\n";
$detail = file_get_contents($root . '/assets/js/incident-detail.js');
t('activity-log entries wrap a long unbroken token (text-break)', (bool) preg_match('/fw-semibold text-break"><i class="bi \' \+ icon/', $detail));
$page = file_get_contents($root . '/incident-detail.php');
t('the incident description wraps a long unbroken token (overflow-wrap)', (bool) preg_match('/id="incidentDesc"[^>]*overflow-wrap:\s*anywhere/', $page));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
