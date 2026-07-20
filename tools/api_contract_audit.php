<?php
/**
 * API ↔ JS contract audit (Eric 2026-07-07, after the EOC Units tab
 * shipped reading currstatus/location/last_seen_ago — keys no endpoint
 * has ever emitted).
 *
 * Companion to tools/schema_audit.php, one boundary up: schema_audit
 * validates SQL against the database; this validates what JavaScript
 * READS against what PHP can EMIT.
 *
 * How it works:
 *   1. EMITTABLE set — scan every PHP file for keys that can appear in
 *      a JSON response: array-literal keys ('k' =>), array subscripts
 *      ($row['k']), SQL SELECT aliases (AS `k`), and backticked SQL
 *      identifiers (columns pass through fetch_all into JSON). This is
 *      a deliberately BROAD union: a key flagged as unknown is read by
 *      JS while appearing NOWHERE in server code — the strongest form
 *      of the contract bug.
 *   2. JS READS — scan assets/js/*.js plus inline <script> blocks in
 *      the page roots, but only files that actually consume APIs
 *      (fetch/EventSource/apiGet). Extract property READS (x.key),
 *      excluding method calls, assignments (x.key = ...), and a DOM/
 *      builtin blocklist.
 *   3. Flag reads not in EMITTABLE ∪ builtins ∪ baseline, with the
 *      endpoints each file fetches shown for context.
 *
 * Limits (honest): attribution is per-file, not per-variable, and the
 * EMITTABLE set is global — a key emitted by endpoint A but read from
 * endpoint B's response will NOT be flagged. What IS flagged has no
 * server-side source at all, which was every real instance so far.
 *
 * Exit code: 0 = clean/baseline-only, 1 = new findings.
 * Baseline: tools/api_contract_baseline.txt (one key per line).
 *
 * Usage:
 *   php tools/api_contract_audit.php          # report + exit code
 *   php tools/api_contract_audit.php --all    # include baseline finds
 */

chdir(__DIR__ . '/..');
$showAll = in_array('--all', $argv ?? [], true);

// ── 1. EMITTABLE keys from all PHP ───────────────────────────────────────
$emittable = [];
$phpFiles = [];
// proxy/ included: the Zello proxy pushes JSON frames the widgets read.
foreach (['api', 'inc', 'proxy'] as $dir) {
    if (!is_dir($dir)) { continue; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $phpFiles[] = str_replace('\\', '/', $f->getPathname());
        }
    }
}
foreach (glob('*.php') as $f) { $phpFiles[] = $f; }

// Python services (DMR bridge, mesh bridge) emit JSON the widgets consume
// over SSE/WS: harvest their dict-literal keys too.
if (is_dir('services')) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('services')) as $f) {
        if (!$f->isFile() || substr($f->getFilename(), -3) !== '.py') { continue; }
        $src = file_get_contents($f->getPathname());
        if ($src === false) { continue; }
        if (preg_match_all('/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*:/', $src, $m)) {
            foreach ($m[1] as $k) { $emittable[strtolower($k)] = true; }
        }
        if (preg_match_all('/\[\s*[\'"]([a-z_][a-z0-9_]*)[\'"]\s*\]/', $src, $m)) {
            foreach ($m[1] as $k) { $emittable[strtolower($k)] = true; }
        }
    }
}

foreach ($phpFiles as $file) {
    $src = file_get_contents($file);
    if ($src === false) { continue; }
    // Array-literal keys + subscripts
    if (preg_match_all('/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*=>/', $src, $m)) {
        foreach ($m[1] as $k) { $emittable[strtolower($k)] = true; }
    }
    if (preg_match_all('/\[\s*[\'"]([a-z_][a-z0-9_]*)[\'"]\s*\]/', $src, $m)) {
        foreach ($m[1] as $k) { $emittable[strtolower($k)] = true; }
    }
    // SQL aliases + backticked identifiers (columns flow into JSON rows)
    if (preg_match_all('/\bAS\s+`?([a-z_][a-z0-9_]*)`?/i', $src, $m)) {
        foreach ($m[1] as $k) { $emittable[strtolower($k)] = true; }
    }
    if (preg_match_all('/`([a-z_][a-z0-9_]*)`/', $src, $m)) {
        foreach ($m[1] as $k) { $emittable[strtolower($k)] = true; }
    }
    // Settings/config keys often appear in PHP as plain array VALUES
    // (foreach (['aprs_fi_api_key', ...] as $name)) and get emitted via
    // dynamic $out[$row['name']] loops. Underscored snake_case literals
    // are overwhelmingly key names, not prose — include them. (Keys
    // WITHOUT an underscore stay out so orphans like `currstatus` and
    // `fname` remain detectable.)
    if (preg_match_all('/[\'"]([a-z][a-z0-9]*(?:_[a-z0-9]+)+)[\'"]/', $src, $m)) {
        foreach ($m[1] as $k) { $emittable[strtolower($k)] = true; }
    }
    // Un-backticked SQL column lists ("SELECT id, atak_uid, callsign_seen
    // FROM ...", "SET killed_at = NOW()") also become JSON row keys.
    // Harvest every identifier inside query-bearing strings — this only
    // widens the allowlist, so remaining flags stay high-precision.
    // Tokenizer-based (a big-file regex here silently hits the backtrack
    // limit and returns nothing).
    $tokens = @token_get_all($src);
    if ($tokens) {
        $buf = null;
        $harvest = function ($str) use (&$emittable) {
            // Concatenated queries ("UPDATE " . db_table(...) . " SET col =")
            // split the verb from the columns — accept any SQL-shaped
            // fragment, not just verb-bearing strings.
            if ($str === '' || !preg_match(
                '/\b(SELECT|INSERT|UPDATE|DELETE|WHERE|VALUES|JOIN)\b|\sSET\s|\sFROM\s|ORDER\s+BY/i',
                $str
            )) { return; }
            if (preg_match_all('/\b([a-z_][a-z0-9_]{2,})\b/', $str, $mi)) {
                foreach ($mi[1] as $k) { $emittable[strtolower($k)] = true; }
            }
        };
        foreach ($tokens as $tk) {
            if (is_array($tk)) {
                if ($tk[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $harvest(substr($tk[1], 1, -1));
                } elseif ($tk[0] === T_ENCAPSED_AND_WHITESPACE) {
                    if ($buf !== null) { $buf .= $tk[1]; } else { $harvest($tk[1]); }
                } elseif ($tk[0] === T_START_HEREDOC) {
                    $buf = '';
                } elseif ($tk[0] === T_END_HEREDOC) {
                    if ($buf !== null) { $harvest($buf); }
                    $buf = null;
                }
            } elseif ($tk === '"') {
                if ($buf === null) { $buf = ''; }
                else { $harvest($buf); $buf = null; }
            }
        }
    }
}
echo count($phpFiles) . " PHP files -> " . count($emittable) . " emittable keys\n";

// ── 2. JS data reads ─────────────────────────────────────────────────────
// Property-name blocklist: DOM, JS builtins, fetch/Response, libraries.
$builtin = array_flip([
    // JS / stdlib
    'length', 'prototype', 'constructor', 'name', 'call', 'apply', 'bind',
    'toString', 'valueOf', 'hasOwnProperty', 'stringify', 'parse', 'freeze',
    'assign', 'defineproperty', 'getprototypeof', 'now', 'random', 'floor',
    'ceil', 'round', 'abs', 'min', 'max', 'pow', 'sqrt', 'log', 'exp',
    'gettime', 'getfullyear', 'getmonth', 'getdate', 'getday', 'gethours',
    'getminutes', 'getseconds', 'toisostring', 'tolocalestring',
    'tolocaledatestring', 'tolocaletimestring',
    // fetch / Response / promises / events
    'then', 'json', 'text', 'blob', 'arraybuffer', 'headers', 'redirected',
    'credentials', 'method', 'signal', 'aborted', 'onmessage', 'onerror',
    'onopen', 'onclose', 'readystate', 'lastindex',
    // DOM
    'style', 'value', 'innerhtml', 'outerhtml', 'textcontent', 'innertext',
    'classname', 'classlist', 'dataset', 'checked', 'disabled', 'selected',
    'options', 'selectedindex', 'parentnode', 'parentelement', 'children',
    'childnodes', 'firstchild', 'lastchild', 'nextsibling', 'previoussibling',
    'nextelementsibling', 'previouselementsibling', 'tagname', 'nodename',
    'nodetype', 'href', 'src', 'placeholder', 'title', 'hidden', 'files',
    'documentelement', 'activeelement', 'defaultview', 'contentwindow',
    'offsetwidth', 'offsetheight', 'offsettop', 'offsetleft', 'clientwidth',
    'clientheight', 'scrolltop', 'scrollleft', 'scrollheight', 'scrollwidth',
    'pageyoffset', 'pagexoffset', 'innerheight', 'innerwidth', 'maxlength',
    'rows', 'cols', 'form', 'elements', 'localname', 'namespaceuri',
    // events
    'target', 'currenttarget', 'key', 'keycode', 'which', 'button',
    'shiftkey', 'ctrlkey', 'altkey', 'metakey', 'clientx', 'clienty',
    'pagex', 'pagey', 'screenx', 'screeny', 'detail', 'deltay', 'deltax',
    'touches', 'changedtouches', 'relatedtarget', 'defaultprevented',
    // browser objects
    'localstorage', 'sessionstorage', 'history', 'ubiquitous', 'userlanguage',
    'languages', 'useragent', 'platform', 'geolocation', 'clipboard',
    'mediadevices', 'serviceworker', 'permissions', 'onLine', 'online',
    'hash', 'pathname', 'hostname', 'origin', 'protocol', 'search', 'port',
    // audio/canvas/map libs commonly used here
    'currenttime', 'duration', 'paused', 'volume', 'muted', 'playbackrate',
    'srcobject', 'audiocontext', 'destination', 'samplerate', 'fftsize',
    'frequencybindata',
    // promises / element methods that appear uncalled in stripped code
    'catch', 'finally', 'closest', 'matches', 'focus', 'blur', 'click',
    'submit', 'reset', 'select', 'scrollintoview', 'requestfullscreen',
    'collapse', 'confirm', 'alert', 'prompt', 'document', 'display',
    // geometry (getBoundingClientRect / geolocation / Leaflet latlngs)
    'top', 'bottom', 'left', 'right', 'width', 'height', 'coords',
    'latitude', 'longitude', 'accuracy', 'lat', 'lng', 'x', 'y',
    'zoom', 'bounds', 'buffer', 'latlng',
    // browser APIs (Notification / speechSynthesis / serviceWorker /
    // AudioContext nodes / MediaRecorder)
    'permission', 'speak', 'registration', 'gain', 'frequency', 'stop',
    'load', 'init', 'queue', 'repeat', 'redirect', 'visibility',
    // Nominatim (OpenStreetMap geocoder) response fields — external API
    'road', 'house_number', 'suburb', 'neighbourhood', 'hamlet', 'town',
    'village', 'postcode', 'pedestrian', 'municipality', 'county',
    'display_name', 'address',
]);
// Base-variable blocklist: reads off these are never API data.
$baseBlock = array_flip([
    'document', 'window', 'console', 'math', 'json', 'object', 'array',
    'string', 'number', 'date', 'regexp', 'promise', 'navigator',
    'location', 'history', 'localstorage', 'sessionstorage', 'l',
    'bootstrap', 'gridstack', 'this', 'eventbus', 'audiocontext',
]);

$jsSources = [];   // path => code
foreach (glob('assets/js/*.js') as $f) { $jsSources[$f] = file_get_contents($f); }
foreach (glob('assets/js/widgets/*.js') as $f) { $jsSources[$f] = file_get_contents($f); }
foreach (glob('*.php') as $f) {
    $src = file_get_contents($f);
    if ($src === false) { continue; }
    // Inline <script> blocks only (skip src= includes)
    if (preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/is', $src, $m)) {
        $joined = implode("\n", $m[1]);
        if (trim($joined) !== '') { $jsSources[$f . ' (inline)'] = $joined; }
    }
}

/**
 * Strip string literals and comments from JS so URLs in strings
 * ("server.arcgisonline.com") and commented code can't register as
 * property reads. Line count is preserved (replacement keeps \n).
 */
function js_strip($code)
{
    $out = '';
    $len = strlen($code);
    $i = 0;
    $state = '';   // '', '"', "'", '//', '/*'
    while ($i < $len) {
        $c = $code[$i];
        $n = $i + 1 < $len ? $code[$i + 1] : '';
        if ($state === '') {
            if ($c === '"' || $c === "'") { $state = $c; $out .= ' '; $i++; continue; }
            if ($c === '/' && $n === '/') { $state = '//'; $out .= '  '; $i += 2; continue; }
            if ($c === '/' && $n === '*') { $state = '/*'; $out .= '  '; $i += 2; continue; }
            $out .= $c; $i++; continue;
        }
        if ($state === '"' || $state === "'") {
            if ($c === '\\') { $out .= '  '; $i += 2; continue; }
            if ($c === $state) { $state = ''; $out .= ' '; $i++; continue; }
            $out .= ($c === "\n") ? "\n" : ' '; $i++; continue;
        }
        if ($state === '//') {
            if ($c === "\n") { $state = ''; $out .= "\n"; } else { $out .= ' '; }
            $i++; continue;
        }
        // '/*'
        if ($c === '*' && $n === '/') { $state = ''; $out .= '  '; $i += 2; continue; }
        $out .= ($c === "\n") ? "\n" : ' '; $i++;
    }
    return $out;
}

$findings = [];   // key => list of [file, endpoints, sample]
foreach ($jsSources as $file => $code) {
    if (!is_string($code) || $code === '') { continue; }
    // Only audit files that consume APIs.
    $endpoints = [];
    if (preg_match_all('/(?:fetch|EventSource)\(\s*[\'"]([a-z0-9_\-\/\.]*api\/[a-z0-9_\-\/\.]+)/i', $code, $m)) {
        foreach ($m[1] as $ep) { $endpoints[preg_replace('/^.*api\//', 'api/', $ep)] = true; }
    }
    if (preg_match_all('/api(?:Get|Post)\(\s*[\'"]([a-z0-9_\-]+)/', $code, $m)) {
        foreach ($m[1] as $ep) { $endpoints['api/config-admin.php?section=' . $ep] = true; }
    }
    if (!$endpoints) { continue; }
    $endpoints = array_keys($endpoints);

    // Endpoint detection ran on the RAW code (URLs live in strings);
    // property matching runs on stripped code so string/comment content
    // can't fake reads.
    $stripped = js_strip($code);

    // Keys this file CREATES via object literals ({ max_age: ... }) or
    // property writes are its own client-side data — reads of them are
    // not API-contract reads.
    $selfBuilt = [];
    if (preg_match_all('/[{,\s]([a-z_][a-z0-9_]{2,})\s*:/', $stripped, $sb)) {
        foreach ($sb[1] as $k) { $selfBuilt[strtolower($k)] = true; }
    }
    if (preg_match_all('/\.([a-z_][a-z0-9_]{2,})\s*=[^=]/', $stripped, $sw)) {
        foreach ($sw[1] as $k) { $selfBuilt[strtolower($k)] = true; }
    }

    // Property reads: base.prop — not a call, not an assignment target.
    if (!preg_match_all(
        '/\b([A-Za-z_$][\w$]*)\.([a-z_][a-z0-9_]{2,})\b(?!\s*[=(\w])/',
        $stripped, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) { continue; }
    $code = $stripped;
    foreach ($mm as $m2) {
        $base = strtolower($m2[1][0]);
        $prop = strtolower($m2[2][0]);
        // Underscore-prefixed props are the codebase's JS-internal state
        // convention (_bound, _wired, _timer, ...) — never API data.
        if ($prop[0] === '_') { continue; }
        // x.key == / != are reads; x.key = is a write. The lookahead above
        // blocks "= " but also "==": re-allow comparisons.
        $after = substr($code, $m2[2][1] + strlen($m2[2][0]), 2);
        if ($after !== '' && $after[0] === '=' && ($after[1] ?? '') !== '=') { continue; }
        if (isset($baseBlock[$base]) || isset($builtin[$prop])) { continue; }
        if (isset($selfBuilt[$prop])) { continue; }
        if (isset($emittable[$prop])) { continue; }
        $line = substr_count(substr($code, 0, $m2[0][1]), "\n") + 1;
        $findings[$prop][] = [$file, $endpoints, $base . '.' . $m2[2][0] . " (line ~$line)"];
    }
}

// ── 3. Report ─────────────────────────────────────────────────────────────
$baselineFile = __DIR__ . '/api_contract_baseline.txt';
$baseline = is_file($baselineFile)
    ? array_filter(array_map('trim', file($baselineFile)))
    : [];

ksort($findings);
$newCount = 0;
foreach ($findings as $key => $sites) {
    $inBaseline = in_array($key, $baseline, true);
    if ($inBaseline && !$showAll) { continue; }
    if (!$inBaseline) { $newCount++; }
    echo ($inBaseline ? '[baseline] ' : '[NEW]      ') . "key `$key` read by JS, emitted by NO PHP\n";
    $shown = [];
    foreach ($sites as [$f, $eps, $sample]) {
        if (isset($shown[$f])) { continue; }
        $shown[$f] = true;
        echo "             $f — $sample\n";
        echo '               fetches: ' . implode(', ', array_slice($eps, 0, 4))
            . (count($eps) > 4 ? ' …' : '') . "\n";
        if (count($shown) >= 4) { break; }
    }
}

echo "\n" . count($findings) . " distinct key(s) flagged, $newCount new (not in baseline)\n";
exit($newCount === 0 ? 0 : 1);
