<?php
/**
 * Phase 46 regression tests for the Add Identifier dialog.
 *
 * Three bugs we are guarding against:
 *
 *   1. assets/js/roster.js used to drop fields_json into a data-* HTML
 *      attribute via esc(). esc() escapes <,>,& but NOT ". A fields_json
 *      blob like `[{"key":"radio_id",...}]` therefore broke the attribute
 *      at the first inner quote and the dynamic fields never rendered.
 *      We now keep fields_json in a JS map keyed by mode id and read it
 *      directly when the dropdown changes. Make sure the old broken
 *      pattern (`data-fields="' + esc(...)`) doesn't sneak back in.
 *
 *   2. doRadioIdLookup() used to query for `.comm-field-input` and
 *      `radioIdStatus` — neither of which the modal actually renders.
 *      The modal uses `.comm-modal-field` and `radioIdModalStatus`.
 *      The lookup was a no-op. Verify the modal-aware selectors are
 *      still present.
 *
 *   3. api/owntracks-config.php?action=link used to wrap the config
 *      payload in `{"_type":"setConfiguration","configuration":{…}}`
 *      before generating the `owntracks:///?inline=` URL. The OwnTracks
 *      Android app rejects the setConfiguration envelope on direct
 *      provisioning — the payload must be the bare configuration
 *      object. Make sure the wrapper has been removed from the link
 *      path while still present in the push/rotate outbox path.
 */

require_once __DIR__ . '/../config.php';

$total   = 0;
$passed  = 0;
$failed  = [];

function p46_assert(string $name, bool $cond, string $detail = '') {
    global $total, $passed, $failed;
    $total++;
    if ($cond) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed[] = "$name — $detail";
        echo "  FAIL  $name — $detail\n";
    }
}

echo "Phase 46 — Add Identifier dialog regression tests\n";
echo "===================================================\n";

// ── Bug 1: roster.js must not drop fields_json into a data-* attribute
// via esc(). The replacement pattern stores per-mode metadata in a JS
// map (modeMeta) keyed by mode id.
$rosterJs = file_get_contents(__DIR__ . '/../assets/js/roster.js');
p46_assert(
    'roster.js — old data-fields="…esc(am.fields_json)" pattern is gone',
    strpos($rosterJs, "data-fields=\"' + esc(am.fields_json") === false,
    'broken esc(JSON)-in-attribute pattern is back'
);
p46_assert(
    'roster.js — modeMeta map is populated in openCommModal',
    strpos($rosterJs, 'modeMeta[String(am.id)]') !== false,
    'modeMeta map missing — fields_json lookup will fall back to broken attribute parse'
);
p46_assert(
    'roster.js — modeMeta lookup drives buildFields on change',
    strpos($rosterJs, 'modeMeta[String(modeId)]') !== false,
    'change handler is not reading modeMeta'
);

// ── Bug 2: doRadioIdLookup must use modal-aware selectors.
p46_assert(
    'roster.js — DMR lookup queries .comm-modal-field',
    strpos($rosterJs, '.comm-modal-field[data-field-key="radio_id"]') !== false,
    'modal-aware selector missing — lookup result has nowhere to land'
);
p46_assert(
    'roster.js — DMR lookup uses radioIdModalStatus element',
    strpos($rosterJs, "getElementById('radioIdModalStatus')") !== false,
    'status element id mismatch — lookup spinner will be invisible'
);
p46_assert(
    'roster.js — DMR lookup auto-pulls primary callsign from _memberCallsigns',
    strpos($rosterJs, '_memberCallsigns[pc].is_primary') !== false
        && strpos($rosterJs, '_memberCallsigns[0].callsign') !== false,
    'auto-prefill removed — admin will be prompted instead'
);

// ── Bug 3: owntracks-config.php link path emits the bare configuration
// payload (no setConfiguration wrapper); rotate path keeps the wrapper.
$otCfg = file_get_contents(__DIR__ . '/../api/owntracks-config.php');

// Find the action=link block (between `if ($action === 'link'` and
// the next `if ($action ===` line) and confirm it does NOT contain
// `setConfiguration`.
$linkStart = strpos($otCfg, "if (\$action === 'link'");
$linkEnd   = strpos($otCfg, "if (\$action === ", $linkStart + 10);
$linkBlock = ($linkStart !== false && $linkEnd !== false)
    ? substr($otCfg, $linkStart, $linkEnd - $linkStart)
    : '';
p46_assert(
    "owntracks-config.php — action=link block found",
    $linkBlock !== '',
    'could not locate action=link handler'
);
// Match the actual code construction, not the substring — the
// fix-explanation comment legitimately mentions setConfiguration.
$linkCodeOnly = preg_replace('!//.*$!m', '', $linkBlock);
p46_assert(
    "owntracks-config.php — link path does NOT wrap in setConfiguration",
    $linkCodeOnly !== '' && strpos($linkCodeOnly, "'_type' => 'setConfiguration'") === false,
    'setConfiguration wrapper sneaked back into the URL/QR provisioning path'
);
// Phase 46b: URL must match the OwnTracks AndroidManifest path filter and
// LoadViewModel decoding. Path must be /config (not / alone), the inline
// parameter must be Base64 (not URL-encoded JSON), and the wrapper must
// stay the bare configuration object.
p46_assert(
    "owntracks-config.php — link path uses owntracks:///config?inline= (with /config path)",
    strpos($linkBlock, "'owntracks:///config?inline='") !== false,
    'URL is missing the /config path that OwnTracks AndroidManifest requires'
);
p46_assert(
    "owntracks-config.php — link path Base64-encodes the inline payload",
    strpos($linkBlock, 'base64_encode($cfgJson)') !== false,
    'inline= must be Base64 per LoadViewModel.extractPreferencesFromUri (Base64.decode)'
);
p46_assert(
    "owntracks-config.php — old urlencode(json_encode(\$cfg)) pattern is gone",
    strpos($linkBlock, 'urlencode(json_encode($cfg))') === false,
    'URL-encoded JSON pattern is back — OwnTracks Android will reject this'
);
p46_assert(
    "owntracks-config.php — mode=file branch emits .otrc Content-Disposition",
    strpos($linkBlock, "'Content-Disposition: attachment; filename=\"' . \$fname . '\"'") !== false
        && strpos($linkBlock, "'.otrc'") !== false,
    'mode=file download branch is missing or not naming the file .otrc'
);
p46_assert(
    "owntracks-config.php — mode=file sets Cache-Control: no-store",
    strpos($linkBlock, 'no-store') !== false,
    'mode=file response is cacheable — bearer token could be reused'
);

// Rotate path SHOULD use the cmd-wrapped setConfiguration envelope
// (Phase 52c — Android Jackson parser rejects the bare _type:
// setConfiguration shape; the action=setConfiguration must live under
// _type: cmd).
$rotateStart = strpos($otCfg, "if (\$action === 'rotate'");
p46_assert(
    "owntracks-config.php — rotate path queues cmd-wrapped setConfiguration",
    $rotateStart !== false
        && preg_match("/'_type'\s*=>\s*'cmd'.*?'action'\s*=>\s*'setConfiguration'/s", substr($otCfg, $rotateStart)) === 1,
    'rotate path is not using the cmd wrapper — Android will silently drop the message'
);

// ── Bug 3b: the QR popup tells the user to scan via the OwnTracks app
// itself (system camera cannot route a custom URL scheme).
p46_assert(
    'roster.js — QR popup instructs to scan inside the OwnTracks app',
    strpos($rosterJs, 'Configuration management') !== false
        && strpos($rosterJs, 'system Camera') !== false,
    'guidance text missing — user will hit the "app not found" error and bounce'
);

// ── Phase 46c: cache invalidation must use asset_v() (mtime-based) so
// shipping a new roster.js actually reaches the browser on the next page
// load. NEWUI_VERSION is pinned at 4.0.0-dev across installs — using it
// alone bakes the cached file in until someone manually bumps the
// constant. That's the bug Eric hit: Phase 46 shipped but his browser
// kept serving the pre-Phase-46 roster.js.
$rosterPhp = file_get_contents(__DIR__ . '/../roster.php');
p46_assert(
    "roster.php — roster.js cache-buster uses asset_v() not NEWUI_VERSION",
    strpos($rosterPhp, "asset_v('assets/js/roster.js')") !== false,
    'roster.js cache-buster is still pinned to NEWUI_VERSION — browsers will not see new ships'
);
p46_assert(
    "roster.php — roster.css cache-buster uses asset_v()",
    strpos($rosterPhp, "asset_v('assets/css/roster.css')") !== false,
    'roster.css cache-buster is still pinned'
);

// ── Phase 46c: defensive console warning when fields_json fails to parse
// or arrives empty. Silent fallback to [] was the original symptom.
p46_assert(
    'roster.js — emits console.error on fields_json parse failure',
    strpos($rosterJs, "console.error('Add Identifier: fields_json failed to parse") !== false,
    'parse failures are silent again — the bug class will recur invisibly'
);
p46_assert(
    'roster.js — surfaces user-visible alert when no fields are defined',
    strpos($rosterJs, 'This mode has no field definitions yet') !== false,
    'empty fields_json silently shows an empty dialog — the original "looks broken" UX'
);

// ── Phase 46d: multi-result DMR picker. Eric has 3 Radio IDs on his
// callsign; most volunteers have 2 (mobile + portable). Auto-filling
// the first result silently dropped the others. The new flow renders
// all results in a table with Use/Add buttons.
p46_assert(
    'roster.js — DMR lookup renders all results, not just auto-fill first',
    strpos($rosterJs, '_renderRadioIdResults(') !== false,
    'multi-result picker missing — admin can only see the first Radio ID'
);
p46_assert(
    'roster.js — DMR picker has Use button to fill the form',
    strpos($rosterJs, 'radioid-use-btn') !== false,
    'Use button missing — admin cannot pick which Radio ID goes into the current form'
);
p46_assert(
    'roster.js — DMR picker has Add button to save the Radio ID as a new identifier',
    strpos($rosterJs, 'radioid-add-btn') !== false
        && strpos($rosterJs, "action: 'save_identifier'") !== false,
    'Add button missing — admin cannot attach multiple Radio IDs in one pass'
);
p46_assert(
    'roster.js — DMR picker shows callsign holder name + location columns',
    strpos($rosterJs, 'r.fname') !== false
        && strpos($rosterJs, 'r.city') !== false,
    'picker is not showing enough metadata to disambiguate Radio IDs'
);

// ── Phase 47: mobile navbar — Bootstrap-collapse pair (expand class +
// toggler + collapse target) so the global nav doesn't overflow the
// viewport on phones. Eric noticed this while provisioning OwnTracks
// from his Android.
$navbarPhp = file_get_contents(__DIR__ . '/../inc/navbar.php');
p46_assert(
    'navbar.php — uses navbar-expand-xl class (Bootstrap collapse trigger)',
    strpos($navbarPhp, 'navbar-expand-xl') !== false,
    'navbar is missing the responsive expand class — items will not collapse on phones'
);
p46_assert(
    'navbar.php — has a navbar-toggler hamburger button',
    strpos($navbarPhp, 'class="navbar-toggler"') !== false
        && strpos($navbarPhp, 'data-bs-target="#mainNavCollapse"') !== false,
    'no hamburger button — users on narrow viewports have no way to expand the menu'
);
p46_assert(
    'navbar.php — wraps menu + right-controls in #mainNavCollapse',
    strpos($navbarPhp, 'class="collapse navbar-collapse" id="mainNavCollapse"') !== false,
    'navbar-collapse wrapper missing — toggler has nothing to show/hide'
);
$dashboardCss = file_get_contents(__DIR__ . '/../assets/css/dashboard.css');
p46_assert(
    'dashboard.css — has mobile media query overriding the desktop nowrap',
    strpos($dashboardCss, '@media (max-width: 1199.98px)') !== false
        && strpos($dashboardCss, 'navbar-toggler-icon') !== false,
    'mobile-only navbar styles are missing — collapse will render unreadably'
);

// ── Phase 48: Edit button used to open a blank Add form because esc()
// doesn't escape `"` (same root cause as Phase 46). Verify the broken
// data-comm-row="esc(JSON.stringify(ci))" pattern is gone and the new
// id-based lookup is in place.
p46_assert(
    'roster.js — Edit button no longer stuffs JSON into data-comm-row',
    strpos($rosterJs, 'data-comm-row="\' + esc(JSON.stringify(ci))') === false,
    'broken esc(JSON)-in-attribute pattern is back on the Edit button'
);
p46_assert(
    'roster.js — Edit button reads from _commIdRowsById map by id',
    strpos($rosterJs, '_commIdRowsById[String(id)]') !== false
        && strpos($rosterJs, 'data-comm-id="\' + ci.id') !== false,
    'Edit handler is not using the id-keyed map — opens blank form again'
);
p46_assert(
    'roster.js — per-row Move Up / Move Down buttons exist',
    strpos($rosterJs, 'move-comm-up-btn') !== false
        && strpos($rosterJs, 'move-comm-down-btn') !== false,
    'no reorder buttons — admin cannot prioritise multiple identifiers'
);
p46_assert(
    'roster.js — reorder click POSTs action=reorder_identifier',
    strpos($rosterJs, "action: 'reorder_identifier'") !== false
        && strpos($rosterJs, "direction: direction") !== false,
    'reorder buttons rendered but never call the backend'
);
p46_assert(
    'roster.js — DMR picker row has Label input',
    strpos($rosterJs, 'radioid-label-input') !== false
        && strpos($rosterJs, 'placeholder="e.g. Mobile HT"') !== false,
    'per-row Label input missing — admin cannot custom-label DMR IDs at Add time'
);

// ── Phase 48 backend: sort_order column + reorder_identifier action.
$commPhp = file_get_contents(__DIR__ . '/../api/comm-identifiers.php');
p46_assert(
    'comm-identifiers.php — self-healing sort_order column migration',
    strpos($commPhp, '_ensure_sort_order_column') !== false
        && strpos($commPhp, 'ADD COLUMN `sort_order` INT') !== false,
    'sort_order migration missing — older installs will 500 on reorder'
);
p46_assert(
    'comm-identifiers.php — reorder_identifier action handler exists',
    strpos($commPhp, "if (\$action === 'reorder_identifier')") !== false,
    'reorder backend missing — UI buttons will get Unknown action error'
);
p46_assert(
    'comm-identifiers.php — list query orders by per-identifier sort_order',
    preg_match('/ORDER BY\s+COALESCE\(NULLIF\(mci\.sort_order,\s*0\),\s*mci\.id\),\s*cm\.sort_order,\s*mci\.id/i', $commPhp) === 1,
    'list query is no longer ordering by per-identifier sort_order — reorder has no visible effect'
);
p46_assert(
    'comm-identifiers.php — new identifier appends with MAX(sort_order)+1',
    strpos($commPhp, "COALESCE(MAX(sort_order), 0)") !== false
        && strpos($commPhp, "\$data['sort_order']") !== false,
    'new rows are not appending to the end — order is unpredictable'
);

// ── Phase 49: PWA manifest — start_url was hardcoded to /newui/ which
// broke mobile install ("URL could not be found") on root-served
// installs (your-server.example.com). Icon sizes were declared 192
// while the actual PNG is 600 — Chrome rejects size mismatches. The
// manifest must also be linked from the page Chrome triggers install
// from (login.php is the first mobile entry point).
$manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true);
p46_assert(
    'manifest.json — parses as JSON',
    is_array($manifest),
    'manifest.json is malformed'
);
p46_assert(
    'manifest.json — start_url is relative (works on root + sub-path installs)',
    isset($manifest['start_url']) && $manifest['start_url'][0] !== '/'
        && strpos($manifest['start_url'], '/newui/') === false,
    'start_url is absolute or hardcoded — install will 404 on non-/newui/ deploys'
);
p46_assert(
    'manifest.json — has scope to bound the PWA',
    isset($manifest['scope']),
    'scope missing — PWA boundary undefined, install may misbehave'
);
p46_assert(
    'manifest.json — icon sizes match the actual PNG (600x600)',
    isset($manifest['icons']) && $manifest['icons'][0]['sizes'] === '600x600',
    'icon size mismatch — Chrome rejects manifests with incorrect sizes'
);
p46_assert(
    'manifest.json — dark logo not falsely declared maskable',
    isset($manifest['icons'][1]) && $manifest['icons'][1]['purpose'] !== 'maskable',
    'logo fills the canvas edge-to-edge — maskable claim chops the content'
);

// Login.php must link the manifest — Chrome only shows "Install" if
// the current page references it.
$loginPhp = file_get_contents(__DIR__ . '/../login.php');
p46_assert(
    'login.php — links the PWA manifest',
    strpos($loginPhp, 'rel="manifest"') !== false,
    'first mobile entry point has no manifest link — Install option will not appear'
);
p46_assert(
    'login.php — has theme-color meta (Chrome installability check)',
    strpos($loginPhp, 'name="theme-color"') !== false,
    'theme-color missing — Chrome treats the install as partially configured'
);

// ── Phase 48b: api/members.php is the actual feed for the roster
// detail panel's comm_identifiers section. Phase 48 added sort_order
// + reorder action but updated only api/comm-identifiers.php's query.
// The members.php query was still ORDER BY cm.sort_order, mci.label,
// so Move Up / Down successfully wrote the new sort_order but the
// rendered list arrived in unchanged mode-order — buttons appeared
// dead.
$membersPhp = file_get_contents(__DIR__ . '/../api/members.php');
p46_assert(
    'members.php — comm_identifiers query orders by sort_order, not just mode',
    preg_match('/ORDER BY\s+COALESCE\(NULLIF\(mci\.sort_order,\s*0\),\s*mci\.id\)/i', $membersPhp) === 1,
    'reorder buttons will look broken — list re-renders in mode order regardless of sort_order'
);
p46_assert(
    'members.php — comm_identifiers query NOT still using bare cm.sort_order',
    !preg_match('/ORDER BY\s+cm\.sort_order,\s*mci\.label/i', $membersPhp),
    'old "cm.sort_order, mci.label" pattern still present — reorder will be invisible'
);
$commPhp2 = file_get_contents(__DIR__ . '/../api/comm-identifiers.php');
p46_assert(
    'comm-identifiers.php — uses COALESCE(NULLIF(sort_order)) for legacy NULL/0 rows',
    preg_match('/COALESCE\(NULLIF\(mci\.sort_order,\s*0\),\s*mci\.id\)/', $commPhp2) === 1,
    'legacy rows with sort_order=0 will cluster instead of falling back to id order'
);

// ── Phase 50: OwnTracks battery defaults. Without locatorInterval +
// locatorDisplacement floors, Android's significant-change callback
// fires every 1-2 seconds on GPS jitter even when stationary — would
// drain a phone in a working day. The new defaults cap at ~1 post/min
// stationary, ~1/30s when actively moving 50m+.
$otCfgPhp = file_get_contents(__DIR__ . '/../api/owntracks-config.php');
p46_assert(
    'owntracks-config.php — sets locatorInterval (post floor)',
    preg_match("/'locatorInterval'\s*=>\s*60/", $otCfgPhp) === 1,
    'no locatorInterval — Android will post on every GPS jitter'
);
p46_assert(
    'owntracks-config.php — sets locatorDisplacement (movement floor)',
    preg_match("/'locatorDisplacement'\s*=>\s*50/", $otCfgPhp) === 1,
    'no displacement floor — stationary phone still spams updates'
);
p46_assert(
    'owntracks-config.php — pegLocatorFastestIntervalToInterval honors the floor',
    strpos($otCfgPhp, "pegLocatorFastestIntervalToInterval") !== false,
    'without this, OS can override locatorInterval and post faster anyway'
);
p46_assert(
    'owntracks-config.php — locatorPriority defaults to BalancedPower',
    preg_match("/'locatorPriority'\s*=>\s*2/", $otCfgPhp) === 1,
    'HighAccuracy GPS is ~10x more battery than balanced — keep this at 2'
);
p46_assert(
    'owntracks-config.php — pulls tid from member comm_identifier if set',
    strpos($otCfgPhp, "cm.code = 'owntracks'") !== false
        && strpos($otCfgPhp, 'tracker_id') !== false,
    'admin-set tid in the comm_identifier modal is ignored — phone defaults to last 2 chars of username'
);
p46_assert(
    'owntracks-config.php — admin can override via owntracks_config_overrides setting',
    strpos($otCfgPhp, 'owntracks_config_overrides') !== false,
    'no admin override hook — per-install tuning requires a code change'
);

// ── Phase 51: two-layer config (global defaults + per-member overrides)
// with auto-push to outbox on save.
p46_assert(
    'owntracks-config.php — three-layer builder _ot_build_layered_config',
    strpos($otCfgPhp, 'function _ot_build_layered_config') !== false,
    'layered config builder missing — single-call path is back'
);
p46_assert(
    'owntracks-config.php — tunable key registry _ot_tunable_keys',
    strpos($otCfgPhp, 'function _ot_tunable_keys') !== false
        && strpos($otCfgPhp, "'monitoring'") !== false
        && strpos($otCfgPhp, "'locatorInterval'") !== false,
    'tunable key registry missing — Settings panel has nothing to bind to'
);
p46_assert(
    'owntracks-config.php — self-healing member.owntracks_overrides column',
    strpos($otCfgPhp, '_ot_ensure_member_overrides_column') !== false
        && strpos($otCfgPhp, 'ADD COLUMN `owntracks_overrides`') !== false,
    'per-member overrides column migration missing — fresh installs will 500'
);
p46_assert(
    'owntracks-config.php — auto-push helpers',
    strpos($otCfgPhp, 'function _ot_push_to_member') !== false
        && strpos($otCfgPhp, 'function _ot_push_to_all_active') !== false,
    'auto-push helpers missing — saves wont propagate to phones'
);
p46_assert(
    'owntracks-config.php — get_defaults + save_defaults actions',
    strpos($otCfgPhp, "if (\$action === 'get_defaults'") !== false
        && strpos($otCfgPhp, "if (\$action === 'save_defaults'") !== false,
    'Settings panel API endpoints missing'
);
p46_assert(
    'owntracks-config.php — get_member_overrides + save_member_overrides actions',
    strpos($otCfgPhp, "if (\$action === 'get_member_overrides'") !== false
        && strpos($otCfgPhp, "if (\$action === 'save_member_overrides'") !== false,
    'per-member API endpoints missing'
);
p46_assert(
    'owntracks-config.php — save_defaults pushes to all active members',
    preg_match("/save_defaults.*_ot_push_to_all_active/s", $otCfgPhp) === 1,
    'save_defaults does not call the push helper — phones wont converge'
);

$settingsPhp = file_get_contents(__DIR__ . '/../settings.php');
p46_assert(
    'settings.php — OwnTracks Defaults panel exists',
    strpos($settingsPhp, 'id="panel-owntracks-defaults"') !== false,
    'Settings panel container missing — sidebar tab will 404 the anchor'
);
p46_assert(
    'settings.php — OwnTracks Defaults panel has the form + actions divs',
    strpos($settingsPhp, 'id="otDefaultsFields"') !== false
        && strpos($settingsPhp, 'id="btnSaveOtDefaults"') !== false,
    'panel skeleton missing — config.js renderer has no targets'
);

$sidebarPhp = file_get_contents(__DIR__ . '/../inc/config-sidebar.php');
p46_assert(
    'config-sidebar.php — OwnTracks Defaults tab added',
    strpos($sidebarPhp, "owntracks-defaults") !== false,
    'sidebar entry missing — panel is unreachable'
);

$rosterPhp = file_get_contents(__DIR__ . '/../roster.php');
p46_assert(
    'roster.php — per-member overrides card added',
    strpos($rosterPhp, 'id="collapseDetailOtOverrides"') !== false
        && strpos($rosterPhp, 'id="detailOtOverrides"') !== false,
    'roster overrides card missing — per-member UI is unreachable'
);

$configJs = file_get_contents(__DIR__ . '/../assets/js/config.js');
p46_assert(
    'config.js — initOtDefaults() loader + render',
    strpos($configJs, 'function initOtDefaults') !== false
        && strpos($configJs, '_renderOtDefaults') !== false,
    'config.js wiring missing — Settings panel will stay on Loading…'
);

$rosterJs2 = file_get_contents(__DIR__ . '/../assets/js/roster.js');
p46_assert(
    'roster.js — per-member overrides lazy-loader',
    strpos($rosterJs2, '_loadOtOverrides') !== false
        && strpos($rosterJs2, "shown.bs.collapse") !== false,
    'roster overrides lazy-loader missing — card stays empty when expanded'
);
p46_assert(
    'roster.js — save calls save_member_overrides + auto-push',
    strpos($rosterJs2, "action=save_member_overrides") !== false,
    'roster overrides save not wired to backend'
);

// ── Phase 52a: setConfiguration must strip OwnTracks-immutable keys
// (mode, deviceId, host, port, clientId, tls, keepalive, etc.).
// Including them causes OwnTracks Android to silently drop the
// entire setConfiguration message — that's why Eric's TID never
// updated from "ng" to "EO" after a Phase 50 push despite the
// outbox row consuming successfully.
$otCfgPhp2 = file_get_contents(__DIR__ . '/../api/owntracks-config.php');
p46_assert(
    'owntracks-config.php — _ot_push_to_member strips immutable keys',
    strpos($otCfgPhp2, "'mode', 'deviceId'") !== false
        && strpos($otCfgPhp2, "immutableViaRemote") !== false,
    'setConfiguration still includes immutable keys — Android will silently drop the message'
);
// Phase 55 — baseline rewritten to be conservative-by-default.
// Move mode burned battery off-duty; Significant uses cell/wifi only
// (no GPS) per OwnTracks docs. Layer D (incident-active) overrides
// monitoring to Move when actually on a call.
p46_assert(
    'owntracks-config.php — baseline uses Significant mode (low battery)',
    preg_match("/'monitoring'\s*=>\s*2,\s*\n/s", $otCfgPhp2) === 1,
    'baseline is not in Significant mode — off-duty members will drain battery'
);
p46_assert(
    'owntracks-config.php — baseline locatorInterval 600s + displacement 500m',
    preg_match("/'locatorInterval'\s*=>\s*600/", $otCfgPhp2) === 1
        && preg_match("/'locatorDisplacement'\s*=>\s*500/", $otCfgPhp2) === 1,
    'baseline locator floors are too tight — battery cost will spike'
);
p46_assert(
    'owntracks-config.php — baseline pubInterval = 60 min (hourly heartbeat)',
    preg_match("/'pubInterval'\s*=>\s*60/", $otCfgPhp2) === 1,
    'baseline pubInterval is not 60 min — off-duty pings will be too frequent'
);

// ── Phase 52b: incident-active Layer D + assign/unassign hook.
p46_assert(
    'owntracks-config.php — _ot_member_has_active_incident helper',
    strpos($otCfgPhp2, 'function _ot_member_has_active_incident') !== false
        && strpos($otCfgPhp2, 'unit_personnel_assignments') !== false
        && strpos($otCfgPhp2, 'a.responder_id = upa.responder_id') !== false,
    'incident-active detector missing — Layer D will never fire'
);
p46_assert(
    'owntracks-config.php — Layer D applies tightened settings',
    strpos($otCfgPhp2, '_ot_member_has_active_incident($memberId)') !== false
        && preg_match("/\\\$cfg\\['pubInterval'\\]\s*=\s*5/", $otCfgPhp2) === 1
        && preg_match("/\\\$cfg\\['moveModeLocatorInterval'\\]\s*=\s*30/", $otCfgPhp2) === 1,
    'Layer D never applies the 30s/5min incident-active spec'
);
p46_assert(
    'owntracks-config.php — Layer D flips monitoring to Move mode',
    preg_match("/\\\$cfg\\['monitoring'\\]\s*=\s*3/", $otCfgPhp2) === 1,
    'Layer D no longer escalates to Move — baseline is Significant, so without this Layer D wont actually use GPS'
);
p46_assert(
    'owntracks-config.php — _ot_recompute_for_responder helper',
    strpos($otCfgPhp2, 'function _ot_recompute_for_responder') !== false,
    'no recompute-by-responder helper — assign hook has nothing to call'
);
p46_assert(
    'owntracks-config.php — OT_CONFIG_LIBRARY_ONLY guard skips action dispatch',
    strpos($otCfgPhp2, "if (defined('OT_CONFIG_LIBRARY_ONLY')) return") !== false,
    'no library-mode guard — including from incident-assign re-dispatches the request'
);
// 2026-06-28 Phase 94 Stage 4j refactor: api/incident-assign.php now
// delegates the assigns INSERT/UPDATE to inc/assignment-write.php. The
// OwnTracks recompute calls still live in the endpoint (after the
// helper returns), but they're no longer adjacent to the SQL — they're
// adjacent to the helper call. Scan the helper for the SQL and the
// endpoint for the recompute calls, then make sure both are present
// alongside the helper-call site.
$assignPhp     = file_get_contents(__DIR__ . '/../api/incident-assign.php');
$assignHelper  = file_get_contents(__DIR__ . '/../inc/assignment-write.php');
p46_assert(
    'incident-assign.php — INSERT path calls _ot_recompute_for_responder',
    // SQL lives in the helper, recompute call lives in the endpoint
    preg_match("/INSERT INTO.*assigns/s", $assignHelper) === 1
        && preg_match("/assign_create_internal[\s\S]*?_ot_recompute_for_responder/s", $assignPhp) === 1,
    'no incident-active push on new assignments'
);
p46_assert(
    'incident-assign.php — UPDATE clear path also pushes recompute',
    preg_match("/UPDATE.*assigns.*SET.*clear/s", $assignHelper) === 1
        && preg_match("/assign_unassign_internal[\s\S]*?_ot_recompute_for_responder/s", $assignPhp) === 1,
    'no baseline-revert push when assignment clears'
);

// ── Phase 52c: setConfiguration wrapper MUST be the cmd shape that
// OwnTracks Android's Jackson deserializer expects. Verified against
// the fixture project/app/src/test/resources/fixtures/cmd_set_
// configuration.json:
//   {"_type":"cmd","action":"setConfiguration","configuration":{...}}
// We were sending {"_type":"setConfiguration","configuration":{...}}
// which has no registered deserializer — Jackson threw "unable to
// parse JSON" in the app logs (exactly what Eric reported) and the
// message was discarded silently.
$otCfgPhp3 = file_get_contents(__DIR__ . '/../api/owntracks-config.php');
p46_assert(
    'owntracks-config.php — _ot_push_to_member uses cmd-wrapped shape',
    preg_match("/'_type'\s*=>\s*'cmd'.*?'action'\s*=>\s*'setConfiguration'/s", $otCfgPhp3) === 1,
    'queued setConfiguration is not wrapped in {_type:cmd, action:setConfiguration} — Android will silently drop it'
);
p46_assert(
    'owntracks-config.php — no orphaned _type:setConfiguration writes left',
    !preg_match("/'_type'\s*=>\s*'setConfiguration'/", $otCfgPhp3),
    'a stale setConfiguration outbox INSERT still uses the wrong _type wrapper'
);
p46_assert(
    'owntracks-config.php — push_config + rotate also use cmd wrapper',
    substr_count($otCfgPhp3, "'action'        => 'setConfiguration'") >= 3,
    'one of the three setConfiguration writers (push_to_member, push_config, rotate) is still on the old shape'
);

// ── Phase 53: diagnostics page + API endpoints. After spending five
// phases debugging silent setConfiguration drops, build a page that
// shows admins WHY a push isn't landing without inspecting the DB.
p46_assert(
    'owntracks-config.php — get_install_diagnostics endpoint exists',
    strpos($otCfgPhp3, "if (\$action === 'get_install_diagnostics'") !== false
        && strpos($otCfgPhp3, 'posts_1h') !== false
        && strpos($otCfgPhp3, 'outbox_pending') !== false,
    'install-wide diagnostics endpoint missing — diagnostics page has nothing to render'
);
p46_assert(
    'owntracks-config.php — get_member_diagnostics endpoint exists',
    strpos($otCfgPhp3, "if (\$action === 'get_member_diagnostics'") !== false
        && strpos($otCfgPhp3, 'layer_breakdown') !== false
        && strpos($otCfgPhp3, 'expected_tid') !== false
        && strpos($otCfgPhp3, 'tid_match') !== false,
    'per-member diagnostics endpoint missing layer breakdown or TID canary'
);
$diagPath = __DIR__ . '/../owntracks-diagnostics.php';
p46_assert(
    'owntracks-diagnostics.php — page exists',
    file_exists($diagPath),
    'diagnostics page is missing from the deploy'
);
if (file_exists($diagPath)) {
    $diagPhp = file_get_contents($diagPath);
    p46_assert(
        'owntracks-diagnostics.php — calls both diagnostics endpoints',
        strpos($diagPhp, 'get_install_diagnostics') !== false
            && strpos($diagPhp, 'get_member_diagnostics') !== false,
        'page is not wired to either backend endpoint'
    );
    p46_assert(
        'owntracks-diagnostics.php — RBAC gate on action.manage_config',
        strpos($diagPhp, "rbac_can('action.manage_config')") !== false,
        'page is missing the admin-only RBAC check'
    );
}
$sidebarPhp2 = file_get_contents(__DIR__ . '/../inc/config-sidebar.php');
p46_assert(
    'config-sidebar.php — OwnTracks Diagnostics link added',
    strpos($sidebarPhp2, 'owntracks-diagnostics.php') !== false,
    'sidebar link to the diagnostics page missing'
);

// ── Phase 54: personal-resource clock-in. Volunteers/IC's who work
// alone shouldn't have to be added to a unit just to be assignable
// or trackable. Each member gets one personal `responder` row tagged
// personal_for_member_id; Clock In activates it.
$personnelInc = __DIR__ . '/../inc/personnel-units.php';
p46_assert(
    'inc/personnel-units.php — helper module exists',
    file_exists($personnelInc),
    'personnel-units helper missing'
);
if (file_exists($personnelInc)) {
    $puInc = file_get_contents($personnelInc);
    p46_assert(
        'personnel-units.php — has self-healing schema migration',
        strpos($puInc, 'function pu_ensure_schema') !== false
            && strpos($puInc, 'ADD COLUMN `personal_for_member_id`') !== false,
        'self-heal for personal_for_member_id missing'
    );
    p46_assert(
        'personnel-units.php — clock_in / clock_out / status helpers',
        strpos($puInc, 'function pu_clock_in') !== false
            && strpos($puInc, 'function pu_clock_out') !== false
            && strpos($puInc, 'function pu_status_for_member') !== false,
        'one of the clock-in lifecycle helpers is missing'
    );
    p46_assert(
        'personnel-units.php — names personal unit from callsign or full name',
        strpos($puInc, 'function pu_personal_unit_name') !== false
            && strpos($puInc, 'member_callsigns') !== false,
        'personal unit naming helper missing or not pulling callsign'
    );
}
$puApi = __DIR__ . '/../api/personal-unit.php';
p46_assert(
    'api/personal-unit.php — endpoint exists',
    file_exists($puApi),
    'clock-in API endpoint missing'
);
if (file_exists($puApi)) {
    $puApiSrc = file_get_contents($puApi);
    p46_assert(
        'api/personal-unit.php — supports status + clock_in + clock_out actions',
        strpos($puApiSrc, "action === 'status'") !== false
            && strpos($puApiSrc, "action === 'clock_in'") !== false
            && strpos($puApiSrc, "action === 'clock_out'") !== false,
        'one of the three API actions is missing'
    );
    p46_assert(
        'api/personal-unit.php — self-clock allowed for any logged-in member',
        strpos($puApiSrc, '_pu_resolve_member_id') !== false
            && strpos($puApiSrc, 'action.manage_members') !== false,
        'permission boundary is wrong — should allow self-clock but require operator for others'
    );
}
$respondersPhp = file_get_contents(__DIR__ . '/../api/responders.php');
// Phase 58 — flipped default: clocked-IN personal units now appear in
// the default units list. Eric pointed out that he could be assigned
// to incidents but didn't show up as an available responder.
p46_assert(
    'responders.php — default shows CLOCKED-IN personal units (not hidden)',
    strpos($respondersPhp, "\$includePersonal = 'active'") !== false
        && strpos($respondersPhp, "NOT EXISTS") !== false
        && strpos($respondersPhp, 'inactive') !== false,
    'default still hides all personal units — they should be visible when clocked in'
);
p46_assert(
    'responders.php — ?include_personal=all bypasses the filter',
    strpos($respondersPhp, "'all'") !== false
        && strpos($respondersPhp, '$personalFilter = null') !== false,
    'no escape hatch to see clocked-out personal units (admin "where is everyone" view)'
);
p46_assert(
    'responders.php — ?include_personal=none restores legacy hide-all',
    strpos($respondersPhp, "'none'") !== false
        && strpos($respondersPhp, "personal_for_member_id` IS NULL") !== false,
    'no way to suppress personal units for equipment-only reports'
);
$profilePhp = file_get_contents(__DIR__ . '/../profile.php');
p46_assert(
    'profile.php — Personal Resource card with Clock In/Out buttons',
    strpos($profilePhp, 'btnClockIn') !== false
        && strpos($profilePhp, 'btnClockOut') !== false
        && strpos($profilePhp, 'api/personal-unit.php') !== false,
    'profile.php is missing the clock-in UI or backend wiring'
);

// ── Phase 56: clock-in moved to navbar user-menu (one-click from
// anywhere) + prominent mobile UI + auto-mutual-exclusion with
// multi-person unit assignments.
$navbarPhp2 = file_get_contents(__DIR__ . '/../inc/navbar.php');
p46_assert(
    'navbar.php — clock-in toggle in user dropdown',
    strpos($navbarPhp2, 'id="navPuToggle"') !== false
        && strpos($navbarPhp2, 'id="navPuBadge"') !== false,
    'navbar user dropdown missing the clock-in toggle item'
);
p46_assert(
    'navbar.php — lazy-loads status on dropdown open',
    strpos($navbarPhp2, "shown.bs.dropdown") !== false
        && strpos($navbarPhp2, "action=status") !== false,
    'status lazy-load wiring missing — badge will never paint'
);
p46_assert(
    'navbar.php — POSTs clock_in/clock_out on toggle click',
    strpos($navbarPhp2, "action=' + action") !== false
        && strpos($navbarPhp2, 'clock_in') !== false
        && strpos($navbarPhp2, 'clock_out') !== false,
    'toggle button is not wired to the backend'
);
$mobilePhp = file_get_contents(__DIR__ . '/../mobile.php');
p46_assert(
    'mobile.php — Personal Resource card at top of main',
    strpos($mobilePhp, 'id="puCard"') !== false
        && strpos($mobilePhp, 'id="btnClockIn"') !== false
        && strpos($mobilePhp, 'id="btnClockOut"') !== false,
    'mobile clock-in card missing'
);
p46_assert(
    'mobile.php — wired to personal-unit API',
    strpos($mobilePhp, 'api/personal-unit.php') !== false,
    'mobile clock-in card not wired to backend'
);
$unitAssignPhp = file_get_contents(__DIR__ . '/../api/unit-assignments.php');
p46_assert(
    'unit-assignments.php — auto-clock-out personal unit on assign',
    strpos($unitAssignPhp, 'pu_get_personal_unit') !== false
        && strpos($unitAssignPhp, 'pu_clock_out') !== false
        && strpos($unitAssignPhp, 'auto_clock_out') !== false,
    'mutual exclusion missing — member assigned to a unit will still appear as personal resource too'
);
p46_assert(
    'unit-assignments.php — self-assign-to-personal-unit guard',
    strpos($unitAssignPhp, '(int) $personal[\'id\'] !== (int) $responderId') !== false,
    'assigning member to their own personal unit would trigger an infinite clock-out loop'
);

// ── Phase 57: RBAC gate on self-clock-in + PWA install button.
$puApi2 = file_get_contents(__DIR__ . '/../api/personal-unit.php');
p46_assert(
    'personal-unit.php — checks action.self_clock_in on self clock_in/out',
    strpos($puApi2, "rbac_can('action.self_clock_in')") !== false
        && strpos($puApi2, "intent === 'clock_in'") !== false,
    'self-clock gate missing — admins can no longer block specific roles'
);
p46_assert(
    'personal-unit.php — status returns can_self_clock so UI can hide toggle',
    strpos($puApi2, "'can_self_clock'") !== false,
    'status endpoint must expose can_self_clock so UI hides the button for blocked roles'
);
$navbarPhp3 = file_get_contents(__DIR__ . '/../inc/navbar.php');
p46_assert(
    'navbar.php — hides clock-in toggle when can_self_clock is false',
    strpos($navbarPhp3, 'status.can_self_clock === false') !== false,
    'navbar shows the toggle to all users regardless of permission'
);
$mobilePhp2 = file_get_contents(__DIR__ . '/../mobile.php');
p46_assert(
    'mobile.php — hides Personal Resource card when can_self_clock is false',
    strpos($mobilePhp2, 'can_self_clock === false') !== false,
    'mobile shows the card to all users regardless of permission'
);
$profilePhp2 = file_get_contents(__DIR__ . '/../profile.php');
p46_assert(
    'profile.php — shows "not permitted" state when can_self_clock is false',
    strpos($profilePhp2, 'not permitted') !== false
        && strpos($profilePhp2, 'can_self_clock === false') !== false,
    'profile.php is not honoring the RBAC gate'
);

// PWA install button
p46_assert(
    'profile.php — has Install App card + beforeinstallprompt capture',
    strpos($profilePhp2, 'id="pwaInstallCard"') !== false
        && strpos($profilePhp2, 'beforeinstallprompt') !== false
        && strpos($profilePhp2, 'btnPwaInstall') !== false,
    'PWA install card or beforeinstallprompt capture missing'
);
p46_assert(
    'profile.php — shows manual menu fallback when prompt unavailable',
    strpos($profilePhp2, 'pwaManualHint') !== false
        && strpos($profilePhp2, 'Add to Home Screen') !== false,
    'manual install hint missing — uninstalled users have no path back'
);
p46_assert(
    'profile.php — detects standalone display mode (already installed)',
    strpos($profilePhp2, 'display-mode: standalone') !== false
        && strpos($profilePhp2, 'pwaInstalledMsg') !== false,
    'standalone detection missing — installed app shows install button anyway'
);

// Permission seeded in both run_00_rbac.php (fresh installs) and the
// one-off run_phase57 migration (existing installs).
$rbacSql = file_get_contents(__DIR__ . '/../sql/run_00_rbac.php');
p46_assert(
    'run_00_rbac.php — action.self_clock_in seeded for fresh installs',
    strpos($rbacSql, "action.self_clock_in") !== false,
    'fresh installs will not have the permission seeded'
);
$ph57 = __DIR__ . '/../sql/run_phase57_self_clock_in_perm.php';
p46_assert(
    'Phase 57 migration script exists',
    file_exists($ph57),
    'one-off migration missing — existing installs cannot adopt the permission'
);
if (file_exists($ph57)) {
    $ph57Src = file_get_contents($ph57);
    p46_assert(
        'Phase 57 migration revokes from Read-Only by name',
        strpos($ph57Src, 'read-only') !== false
            && strpos($ph57Src, 'DELETE FROM') !== false,
        'Read-Only carve-out missing — migration grants to everyone or nobody'
    );
}

// ── Phase 58: clock-in status detection inverted (off-list instead of
// on-list) so installs with custom status names ("Active", "Standby",
// "En Route") all count as clocked-in by default.
$puInc2 = file_get_contents(__DIR__ . '/../inc/personnel-units.php');
p46_assert(
    'personnel-units.php — pu_status_for_member uses off-list (not on-list)',
    strpos($puInc2, '$offIndicators') !== false
        && strpos($puInc2, "'inactive'") !== false
        && strpos($puInc2, "'released'") !== false,
    'status detection still uses on-list whitelist — "Active" installs will show as off'
);
p46_assert(
    'personnel-units.php — _pu_status_id tries Active in addition to Available',
    strpos($puInc2, "_pu_status_id('Available', 'Active'") !== false,
    'clock_in fallback only tries Available — installs without that row fall back to id=1'
);

// ── Phase 60: auto-bind location sources to personal unit on clock-in
// so the units page / responders widget can resolve incoming positions.
// Without this, Eric had OwnTracks posts landing in location_reports
// but unit_location_bindings was empty for responder=67 → no location.
p46_assert(
    'personnel-units.php — pu_autobind_locations helper exists',
    strpos($puInc2, 'function pu_autobind_locations') !== false
        && strpos($puInc2, 'unit_location_bindings') !== false,
    'auto-bind helper missing — clocked-in personal units will show no location'
);
p46_assert(
    'personnel-units.php — auto-bind covers OwnTracks/APRS/DMR/Meshtastic',
    strpos($puInc2, "'owntracks'") !== false
        && strpos($puInc2, "'aprs'") !== false
        && strpos($puInc2, "'dmr'") !== false
        && strpos($puInc2, "'meshtastic'") !== false
        && strpos($puInc2, "tracker_id") !== false
        && strpos($puInc2, "callsign_ssid") !== false
        && strpos($puInc2, "radio_id") !== false
        && strpos($puInc2, "node_id") !== false,
    'auto-bind is missing one of the four supported provider/key pairs'
);
p46_assert(
    'personnel-units.php — pu_clock_in calls pu_autobind_locations',
    substr_count($puInc2, 'pu_autobind_locations(') >= 2,
    'clock_in must call the binder on BOTH the create-new and reuse-existing paths'
);
p46_assert(
    'personnel-units.php — auto-bind reads values_json (current schema)',
    strpos($puInc2, "json_decode(\$r['values_json']") !== false
        && strpos($puInc2, "identifier_value") === false,
    'auto-bind is using the legacy identifier_value column — values_json is current'
);

// ── Phase 61: mirror member contact info onto responder + suppress
// the personnel-assignment UI when the unit is a personal resource.
p46_assert(
    'personnel-units.php — clock_in pulls member contact fields',
    strpos($puInc2, "phone_cell") !== false
        && strpos($puInc2, "\$contactName") !== false
        && strpos($puInc2, "\$cellphone") !== false,
    'contact info is not being pulled from the member row'
);
p46_assert(
    'personnel-units.php — UPDATE branch refreshes responder contact fields',
    strpos($puInc2, "contact_name = ?, cellphone = ?, contact_via = ?") !== false,
    'existing personal unit re-clock-in does NOT refresh contact info'
);
p46_assert(
    'personnel-units.php — INSERT branch seeds responder contact fields',
    strpos($puInc2, "contact_name, cellphone, contact_via, callsign") !== false,
    'new personal unit creation does NOT set contact info'
);
$unitEditJs = file_get_contents(__DIR__ . '/../assets/js/unit-edit.js');
p46_assert(
    'unit-edit.js — swaps personnel UI when resp.personal_for_member_id is set',
    strpos($unitEditJs, 'resp.personal_for_member_id') !== false
        && strpos($unitEditJs, '_renderPersonalUnitCard') !== false,
    'unit-edit still shows the multi-person assign UI on single personal resources'
);
p46_assert(
    'unit-edit.js — personal-unit card links to the roster member',
    strpos($unitEditJs, 'roster.php?member=') !== false,
    'no link from personal unit edit page to the underlying member record'
);

// ── Phase 62: self-heal unit_location_bindings.source + assignment_id.
// responder-detail.php SELECTs these columns; they were never added to
// the schema → silent try/catch swallow → empty location_bindings →
// UI said "No location sources configured" even with active rows.
$puInc3 = file_get_contents(__DIR__ . '/../inc/personnel-units.php');
p46_assert(
    'personnel-units.php — pu_ensure_binding_schema migration exists',
    strpos($puInc3, 'function pu_ensure_binding_schema') !== false
        && strpos($puInc3, "ADD COLUMN `source`") !== false
        && strpos($puInc3, "ADD COLUMN `assignment_id`") !== false,
    'self-heal for missing source/assignment_id columns missing'
);
p46_assert(
    'personnel-units.php — pu_autobind tags rows with source=personal',
    strpos($puInc3, "active, source)") !== false
        && strpos($puInc3, "active = 1, source = 'personal'") !== false,
    'personal-unit bindings not tagged with source=personal — UI cannot label them'
);

// Phase 58 — diagnostics fmtAge no longer appends 'Z' (MySQL DATETIME
// is server-local, not UTC).
$diagPhp2 = file_get_contents(__DIR__ . '/../owntracks-diagnostics.php');
p46_assert(
    'owntracks-diagnostics.php — fmtAge no longer appends Z to MySQL timestamps',
    strpos($diagPhp2, "ts.replace(' ', 'T') + 'Z'") === false,
    'fmtAge still treats MySQL local-time as UTC — timestamps will show ~5h skew'
);
p46_assert(
    'owntracks-diagnostics.php — fmtAge handles negative skew (just now)',
    strpos($diagPhp2, 'just now') !== false,
    'no negative-skew guard — small clock drift will display as huge negative ages'
);

$failedCount = count($failed);
echo "\n";
// Runner-compatible summary line (parsed by tools/test_all.php).
echo "$passed passed, $failedCount failed\n";
echo "Phase 46 — $passed / $total tests passed\n";
if (!empty($failed)) {
    echo "\nFAILURES:\n";
    foreach ($failed as $f) echo "  - $f\n";
    exit(1);
}
exit(0);
