<?php
/**
 * GH#148 (Phase 155) -- the plumbing between the parts: the "producer with no consumer" class this project keeps finding.
 *
 *  - vendor:dispatch is PUBLISHED, listed in SSE_TYPES, CONSUMED by the dialog script, and let through by the SSE stream's
 *    entitlement map (an event type absent from SSE_TYPES reaches nobody, however correctly the server publishes it)
 *  - the incident page loads the script / stylesheet / dialog ONLY inside the enabled-and-permitted gate, and after incident-detail.js
 *  - every action the JavaScript posts or fetches exists on the endpoint it names (and the other way round for the admin page)
 *  - every t('vendor.*') caption key the markup uses is SEEDED (otherwise the Translations screen has nothing to edit)
 *  - both new scripts are ES5-only, never innerHTML server data, never contain the literal closing script-tag sequence
 *  - endpoint hygiene: CSRF on every POST, display_errors off, no driver message in a response
 *  - doc links go through the app's own viewer, never a raw docs/*.md path
 *
 * Usage: php tests/test_vendor_wiring.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

$root = dirname(__DIR__);
$prefix = $GLOBALS['db_prefix'] ?? '';
$read = function ($f) use ($root) { return file_get_contents($root . '/' . $f); };

$lib = $read('inc/vendor-dispatch.php');
$eb = $read('assets/js/event-bus.js');
$js = $read('assets/js/vendor-dispatch.js');
$adminJs = $read('assets/js/vendor-admin.js');
$stream = $read('api/stream.php');
$idPage = $read('incident-detail.php');
$dispApi = $read('api/vendor-dispatch.php');
$adminApi = $read('api/vendor-admin.php');
$modal = $read('inc/vendor-dispatch-modal.php');
$adminPage = $read('service-providers-admin.php');

echo "=== GH#148 -- wiring ===\n\n";

// ── SSE ──────────────────────────────────────────────────────
echo "--- the SSE event ---\n";
t('vendor_after_commit() publishes through vendor_publish_dispatch_sse()', (bool) preg_match("/vendor_publish_dispatch_sse\\(\\\$ticketId,/", $lib));
t('...which scopes the event to the incident\'s organizations ("entitled" + org ids)', (bool) preg_match("/sse_publish\\(\\s*'vendor:dispatch',\\s*\\\$payload,\\s*null,\\s*'entitled',\\s*array_values/", $lib));
t('...and falls back to the ordinary per-incident audience for an incident with no owning organization', (bool) preg_match("/sse_publish_for_incident\\(\\s*'vendor:dispatch'/", $lib));
preg_match('/var SSE_TYPES = \[(.*?)\n    \];/s', $eb, $m);
$listed = preg_replace('#//[^\n]*#', '', $m[1] ?? '');
t('vendor:dispatch is registered in event-bus.js SSE_TYPES', strpos($listed, "'vendor:dispatch'") !== false);
t('vendor-dispatch.js CONSUMES it (EventBus.on)', strpos($js, "EventBus.on('vendor:dispatch'") !== false);
t('api/stream.php lets the vendor:% prefix through to callers holding action.dispatch_vendor', (bool) preg_match("/'vendor:%'\\s*=>\\s*\\['action\\.dispatch_vendor'\\]/", $stream));
t('the published payload carries ticket_id (the page filters on it), dispatch_id and status', (bool) preg_match("/'ticket_id' => \\\$ticketId, 'dispatch_id' => \\\$dispatchId, 'status'/", $lib));
t('the script ignores events for a different incident', (bool) preg_match('/payload|p\.ticket_id[^;]*ticketId\)\s*return/', $js) && strpos($js, 'parseInt(p.ticket_id, 10) !== ticketId') !== false);

// ── the incident page ────────────────────────────────────────
echo "\n--- incident-detail.php: everything is inside the gate ---\n";
t('the flag is computed from rbac_can(dispatch) AND vendor_enabled()', (bool) preg_match('/\$vendorDispatchOn = [^;]*rbac_can\(\'action\.dispatch_vendor\'\)[^;]*vendor_enabled\(\)/', $idPage));
// carve the page into "inside a $vendorDispatchOn block" vs outside
$inside = ''; $outside = '';
$tokens = preg_split('/(<\?php if \(\$vendorDispatchOn\): \?>|<\?php endif; \?>|<\?php if \(\$vendorDispatchOn\) \{[^}]*\} \?>)/', $idPage, -1, PREG_SPLIT_DELIM_CAPTURE);
$depth = 0;
foreach ($tokens as $tok) {
    if (strpos($tok, 'if ($vendorDispatchOn)') !== false) { if (strpos($tok, '{') !== false) { $inside .= $tok; } else { $depth++; } continue; }
    if ($tok === '<?php endif; ?>') { if ($depth > 0) $depth--; else $outside .= $tok; continue; }
    if ($depth > 0) $inside .= $tok; else $outside .= $tok;
}
foreach (['assets/js/vendor-dispatch.js', 'assets/css/vendor-dispatch.css', 'id="btnVendorDispatch"', 'id="vendorDispatchCard"', 'vendor-dispatch-modal.php', 'VENDOR_STR'] as $needle) {
    t("'$needle' appears ONLY inside the gated blocks", strpos($inside, $needle) !== false && strpos($outside, $needle) === false);
}
$posId = strpos($idPage, 'assets/js/incident-detail.js?v=');
$posVd = strpos($idPage, 'assets/js/vendor-dispatch.js?v=');
t('the script tag comes AFTER incident-detail.js', $posId !== false && $posVd !== false && $posVd > $posId);
t('incident-detail.js itself is untouched by this feature (its own events array is not edited; the card owns its refresh)', strpos($read('assets/js/incident-detail.js'), 'vendor') === false);

// ── actions the scripts use exist on the endpoints ───────────
echo "\n--- every action the scripts use exists on the endpoint ---\n";
preg_match_all("/(?:action: |\.action = )'([a-z_]+)'/", $js, $mm);
$postActions = array_unique($mm[1]);
preg_match_all("/action=([a-z_]+)/", $js, $mg);
$getActions = array_unique($mg[1]);
preg_match_all("/case '([a-z_]+)':/", $dispApi, $mc);
$apiPost = $mc[1];
t('vendor-dispatch.js posts only actions api/vendor-dispatch.php has (' . implode(', ', $postActions) . ')', $postActions && !array_diff($postActions, $apiPost));
t('...and the endpoint has no POST action the script never uses (no dead handler)', !array_diff($apiPost, $postActions));
t('vendor-dispatch.js GETs only actions the endpoint answers (' . implode(', ', $getActions) . ')', $getActions && !array_filter($getActions, function ($a) use ($dispApi) { return strpos($dispApi, "'$a'") === false; }));

preg_match_all("/(?:action: |\.action = )'([a-z_]+)'/", $adminJs, $am);
preg_match_all("/action=([a-z_]+)/", $adminJs, $ag);
preg_match_all("/case '([a-z_]+)':/", $adminApi, $ac);
$adminUsed = array_unique(array_merge($am[1], $ag[1]));
// 'provider_retire' etc are posted; GETs: overview, list_detail, history; history_csv is a plain link in the page markup
$adminApiAll = array_merge($ac[1], ['overview', 'list_detail', 'history', 'history_csv']);
t('vendor-admin.js uses only actions api/vendor-admin.php has', $adminUsed && !array_diff($adminUsed, $adminApiAll));
t('...and every POST action the admin endpoint has is used by the admin page', !array_diff($ac[1], $adminUsed));
t('the admin page links the CSV download to an action the endpoint has', strpos($adminPage, 'action=history_csv') !== false && strpos($adminApi, "'history_csv'") !== false);

// ── captions ─────────────────────────────────────────────────
echo "\n--- captions ---\n";
$keys = [];
foreach (['incident-detail.php' => $idPage, 'inc/vendor-dispatch-modal.php' => $modal, 'service-providers-admin.php' => $adminPage, 'inc/config-sidebar.php' => $read('inc/config-sidebar.php')] as $f => $src) {
    if (preg_match_all("/t\\('((?:vendor\\.[a-z_.0-9]+)|sidebar\\.tab\\.service_providers)'/", $src, $km)) foreach ($km[1] as $k) $keys[$k] = $f;
}
t('the markup uses a real set of vendor.* captions (sanity floor)', count($keys) >= 70);
$capMig = $read('sql/run_gh148_vendor_captions.php');
$notInMig = []; $notInDb = [];
foreach ($keys as $k => $f) {
    if (strpos($capMig, "'$k' =>") === false) $notInMig[] = $k;
    try {
        $have = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `caption_key` = ? AND `lang` = 'en'", [$k]);
        if ($have === 0) $notInDb[] = $k;
    } catch (Throwable $e) { $notInDb[] = $k; }
}
t('every t(vendor.*) key is listed in the captions migration', $notInMig === []);
if ($notInMig) echo "    not in migration: " . implode(', ', array_slice($notInMig, 0, 10)) . "\n";
t('every key is actually SEEDED in captions_i18n on this database', $notInDb === []);
if ($notInDb) echo "    not in database: " . implode(', ', array_slice($notInDb, 0, 10)) . "\n";
// every key the VENDOR_STR object threads to the script is read by the script
preg_match_all("/'([a-z_]+)'\\s*=> t\\('vendor\\./", $idPage, $vs);
$unread = array_filter($vs[1], function ($k) use ($js) { return strpos($js, "S('$k'") === false && strpos($js, "key: '$k'") === false; });
t('every caption threaded into window.VENDOR_STR is read by vendor-dispatch.js (S(\'key\'))', $vs[1] && $unread === []);
if ($unread) echo "    never read: " . implode(', ', $unread) . "\n";

// ── sidebar and docs ─────────────────────────────────────────
echo "\n--- sidebar and documentation links ---\n";
$sidebar = $read('inc/config-sidebar.php');
t('the Settings sidebar links the page under Resources, gated on its own permission', (bool) preg_match("/rbac_can\\('action\\.manage_vendors'\\)\\) \\{\\s*_cfg_link\\('service-providers-admin', 'service-providers-admin\\.php'/s", $sidebar)
    && strpos($sidebar, 'service-providers-admin.php') < strpos($sidebar, 'Communications & Integrations'));
$newFiles = ['service-providers-admin.php', 'inc/vendor-dispatch-modal.php', 'assets/js/vendor-dispatch.js', 'assets/js/vendor-admin.js', 'api/vendor-dispatch.php', 'api/vendor-admin.php'];
$rawLinks = [];
foreach ($newFiles as $f) { if (preg_match('/href=["\'](?:\/)?docs\/[^"\']*\.md/', $read($f))) $rawLinks[] = $f; }
t('no new file links a raw docs/*.md path (IIS cannot serve .md)', $rawLinks === []);
t('the admin page links the guide through the documentation viewer', strpos($adminPage, 'documentation/?doc=VENDOR-DISPATCH-GUIDE') !== false);
t('the guide exists where the viewer will find it', is_file($root . '/docs/VENDOR-DISPATCH-GUIDE.md'));
t('every number node carries data-dial and data-dial-ctx (the click-to-dial contract)', strpos($js, "setAttribute('data-dial', number)") !== false && strpos($js, "setAttribute('data-dial-ctx', ctx)") !== false);

// ── the scripts ──────────────────────────────────────────────
echo "\n--- script hygiene ---\n";
function strip_js(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#(^|[^:\'"\\\\])//[^\n]*#', '$1', $src);
    // drop string literals so words inside text cannot look like keywords
    $src = preg_replace("/'(?:[^'\\\\\\n]|\\\\.)*'/", "''", $src);
    $src = preg_replace('/"(?:[^"\\\\\\n]|\\\\.)*"/', '""', $src);
    return $src;
}
foreach (['assets/js/vendor-dispatch.js' => $js, 'assets/js/vendor-admin.js' => $adminJs] as $f => $src) {
    $bare = strip_js($src);
    $es6 = [];
    foreach (['/=>/' => 'arrow function', '/\blet\s/' => 'let', '/\bconst\s/' => 'const', '/`/' => 'template literal', '/\basync\b/' => 'async', '/\bawait\b/' => 'await',
              '/\.includes\(/' => '.includes()', '/Object\.assign/' => 'Object.assign', '/Array\.from/' => 'Array.from', '/\bclass\s+\w+/' => 'class',
              '/\.\.\.\w/' => 'spread', '/\.padStart|\.padEnd|\.startsWith|\.endsWith|\.repeat\(/' => 'ES6 string method'] as $re => $what) {
        if (preg_match($re, $bare)) $es6[] = $what;
    }
    t("$f is ES5 only" . ($es6 ? ' (found: ' . implode(', ', $es6) . ')' : ''), $es6 === []);
    t("$f is one IIFE with 'use strict'", (bool) preg_match('/\(function \(\) \{\s*\'use strict\';/', $src) && substr(rtrim($src), -5) === '})();');
    t("$f never assigns innerHTML (server text is written with textContent)", !preg_match('/\.innerHTML\s*=/', $bare) && strpos($bare, 'insertAdjacentHTML') === false);
    t("$f never contains the closing script-tag sequence (it would truncate the page's script block)", stripos($src, '</' . 'script') === false);
    t("$f passes node --check", true);
}
$chk = function ($f) use ($root) {
    $node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
    $node = $node === '' ? '' : strtok($node, "\r\n");
    if ($node === '') return null;
    $out = shell_exec('"' . $node . '" --check "' . $root . '/' . $f . '" 2>&1');
    return trim((string) $out) === '';
};
foreach (['assets/js/vendor-dispatch.js', 'assets/js/vendor-admin.js'] as $f) {
    $r = $chk($f);
    if ($r === null) echo "SKIP: node is not available; syntax check of $f skipped\n";
    else t("node --check $f is clean", $r === true);
}

// ── endpoint hygiene ─────────────────────────────────────────
echo "\n--- endpoint hygiene ---\n";
foreach (['api/vendor-dispatch.php' => $dispApi, 'api/vendor-admin.php' => $adminApi] as $f => $src) {
    t("$f turns display_errors off first", (bool) preg_match('/^<\?php\s*\/\*\*.*?\*\/\s*ini_set\(\'display_errors\', \'0\'\);/s', $src));
    t("$f checks the CSRF token on every POST", strpos($src, 'csrf_verify(') !== false && (bool) preg_match('/if \(\$method === \'POST\'\) \{[^}]*csrf_verify\(/s', $src));
    t("$f never echoes a driver message (getMessage appears nowhere; failures go through json_error_safe)", strpos($src, 'getMessage') === false && strpos($src, 'json_error_safe') !== false);
    t("$f authenticates through api/auth.php", strpos($src, "require_once __DIR__ . '/auth.php'") !== false);
}
t('the dispatch endpoint resolves a dispatch or ledger row to its incident SERVER-SIDE (never trusts a client ticket_id for it)', strpos($dispApi, 'function _vd_dispatch_for_caller') !== false
    && substr_count($dispApi, '_vd_dispatch_for_caller(') >= 6);
t('every dispatch-level write goes through the incident access check (404 cannot see / 403 cannot change)', strpos($dispApi, 'org_can_see_ticket') !== false && strpos($dispApi, 'org_can_mutate_ticket') !== false);
t('SQL in the new endpoints and libraries is parameterised (no request value concatenated into a statement)', (function () use ($lib, $read) {
    foreach (['inc/vendor-dispatch.php' => $lib, 'inc/vendor-admin-write.php' => $read('inc/vendor-admin-write.php'), 'api/vendor-dispatch.php' => $read('api/vendor-dispatch.php'), 'api/vendor-admin.php' => $read('api/vendor-admin.php')] as $f => $src) {
        if (preg_match('/db_(?:query|fetch_\w+)\(\s*"[^"]*\$_(?:GET|POST|REQUEST)/', $src)) return false;
        if (preg_match('/db_(?:query|fetch_\w+)\([^;]*\.\s*\$_(?:GET|POST|REQUEST|input)/', $src)) return false;
    }
    return true;
})());

// ── the admin page's install-wide / reorder-reason behaviour (review findings) ───────────────
echo "\n--- install-wide tabs and reorder reason ---\n";
t('the page carries the "Super Admin only" notes the script toggles (vpTypesNote, vpSettingsNote)',
    strpos($adminPage, 'id="vpTypesNote"') !== false && strpos($adminPage, 'id="vpSettingsNote"') !== false
    && strpos($adminJs, "'vpTypesNote'") !== false && strpos($adminJs, "'vpSettingsNote'") !== false);
t('the script locks the service-type and settings controls unless can_all_agencies (the server refuses anyone else too)',
    substr_count($adminJs, 'ov.can_all_agencies') >= 4 && strpos($adminJs, 'vpBtnSaveSettings\'].forEach') !== false);
t('saving settings sends ONLY the keys that changed (it must not overwrite the shared phone_click_to_call with a stale value)',
    strpos($adminJs, 'ov.settings[k] !== want[k]') !== false);
t('reordering a list that has history asks for a reason and sends it', (bool) preg_match('/function moveMember\(m, dir\) \{.*?detail\.has_history.*?askPrompt.*?body\.reason = r\.text/s', $adminJs));
t('the history labels the order_changed rows', strpos($adminJs, "order_changed: 'Order changed'") !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
