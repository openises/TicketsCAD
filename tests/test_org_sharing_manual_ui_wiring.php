<?php
/**
 * Phase 142 (2026-08-17) — Manual cross-org sharing UI wiring.
 *
 * Two layers, same split this project already established for this exact
 * page/feature pair (tests/test_org_sharing_shared_indicator.php's own
 * docblock, cited here verbatim as precedent):
 *
 *   1. LIVE FIXTURE: api/incident-detail.php's can_manage_sharing field —
 *      true for the real owning-org caller who holds RBAC, false for a
 *      shared-in viewer (even one who holds RBAC), through a REAL ticket.
 *      This is the field incident-detail.js's renderHeader() gates
 *      #btnShareIncident on — driven through the real endpoint, not a
 *      reproduction, since api/incident-detail.php's GET has no request
 *      body to simulate (the same CLI-subprocess session-attach trick
 *      tests/test_org_sharing_manual_api.php's Part 1 already uses and
 *      documents in full).
 *
 *   2. STRUCTURAL: incident-detail.php / incident-detail.js — source
 *      inspection (un-exported per-page IIFE, can't be driven via node —
 *      same rationale test_org_sharing_shared_indicator.php's own
 *      docblock gives for this exact file). Covers: the button/modal
 *      start CSS-hidden via d-none (toggled via classList, matching
 *      #sharedFromBadge's own established convention exactly — NOT
 *      DOM insertion/removal); the right endpoints are called at the
 *      right points with the right actions and CSRF token; server text
 *      reaching a FIXED, single-purpose element (#shareModalError) goes
 *      through .textContent, never .innerHTML, matching this codebase's
 *      hard rule for #secLabelBadge/#sharedFromBadge; the target-org
 *      picker reuses api/organizations.php?action=list and filters to
 *      active orgs excluding the ticket's own owning org, matching
 *      org-routing-admin.js's own established populateTargetOrgSelect()
 *      shape; and the five SSE client-wiring points from plan.md's own
 *      checklist are genuinely present in each of the five named files.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_manual_ui_wiring.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 142 — Manual cross-org sharing UI wiring ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

$hasNewCols = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'revoked_by'",
    [$prefix . 'incident_shares']
);
if (!$hasNewCols) {
    echo "\nSKIP: incident_shares.revoked_by not present -- run sql/run_phase142_cross_org_manual_sharing.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — LIVE FIXTURE: api/incident-detail.php's can_manage_sharing,
// driven through the real endpoint via the same CLI session-attach probe
// technique test_org_sharing_manual_api.php's Part 1 already established.
// ═══════════════════════════════════════════════════════════════════════

echo "--- Part 1: can_manage_sharing -- live, through the real api/incident-detail.php ---\n\n";

$ownerOrgId    = 900005200;
$sharedInOrgId = 900005201;
$ownerUserId    = 900005210; // role 3 Dispatcher @ ownerOrgId -- holds RBAC, OWNS the ticket
$sharedInUserId = 900005211; // role 3 Dispatcher @ sharedInOrgId -- holds RBAC, does NOT own the ticket
$ownerNoRbacUserId = 900005212; // role 4 Operator @ ownerOrgId -- OWNS the ticket, holds NO RBAC

$createdOrgIds = [$ownerOrgId, $sharedInOrgId];
$createdUserIds = [$ownerUserId, $sharedInUserId, $ownerNoRbacUserId];
$createdTicketIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, $createdUserIds, &$createdTicketIds) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (" . implode(',', array_fill(0, count($createdUserIds), '?')) . ")", $createdUserIds); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$ownerOrgId, 'ZZ142UI Owner']);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$sharedInOrgId, 'ZZ142UI SharedIn']);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$sharedInUserId, $sharedInOrgId, $sharedInOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 4, ?, 'org', ?)", [$ownerNoRbacUserId, $ownerOrgId, $ownerOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 Ui142 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142ui ticket', 'zz142ui ticket', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $ticketId = (int) db_insert_id();
    $createdTicketIds[] = $ticketId;

    $seedShare = org_sharing_create_manual_share($ticketId, $sharedInOrgId, 'view', 'zz142ui fixture share', $ownerUserId, 'ZZ142UI Owner');
    t('fixture: seeding the shared-in org\'s view share succeeded', $seedShare['success'] === true);

    function p142ui_incident_detail_probe(int $userId, int $ticketId, int $activeOrgId): ?array {
        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_incident_detail_can_manage_sharing_probe.php')
            . ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg((string) $ticketId)
            . ' ' . escapeshellarg((string) $activeOrgId);
        $out = @shell_exec($cmd . ' 2>&1');
        $decoded = json_decode(trim((string) $out), true);
        return is_array($decoded) ? $decoded : null;
    }

    $r = p142ui_incident_detail_probe($ownerUserId, $ticketId, $ownerOrgId);
    t('owning-org caller (has RBAC): api/incident-detail.php returns can_manage_sharing=true', $r !== null && isset($r['incident']) && $r['incident']['can_manage_sharing'] === true);

    $r = p142ui_incident_detail_probe($ownerNoRbacUserId, $ticketId, $ownerOrgId);
    t('owning-org caller WITHOUT RBAC: can_manage_sharing=false (RBAC gate)', $r !== null && isset($r['incident']) && $r['incident']['can_manage_sharing'] === false);

    $r = p142ui_incident_detail_probe($sharedInUserId, $ticketId, $sharedInOrgId);
    t('shared-in caller (has RBAC, but does not own the ticket): can_manage_sharing=false (ownership gate)', $r !== null && isset($r['incident']) && $r['incident']['can_manage_sharing'] === false);
    t('shared-in caller: still sees the ticket at all (shared_from_org_name present -- distinguishes "denied" from "not found")', $r !== null && isset($r['incident']) && !empty($r['incident']['shared_from_org_name']));

} finally {
    $cleanup();
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — STRUCTURAL: incident-detail.php markup + incident-detail.js wiring.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Part 2: structural -- incident-detail.php / incident-detail.js ---\n\n";

$phpSrc = @file_get_contents($base . '/incident-detail.php');
$jsSrc  = @file_get_contents($base . '/assets/js/incident-detail.js');
$apiSrc = @file_get_contents($base . '/api/incident-detail.php');

t('incident-detail.php exists', $phpSrc !== false);
t('assets/js/incident-detail.js exists', $jsSrc !== false);
t('api/incident-detail.php exists', $apiSrc !== false);

if ($phpSrc !== false) {
    t('#btnShareIncident is defined, starts with d-none (CSS-hidden by default, matching #sharedFromBadge\'s convention exactly -- NOT absent from the DOM)',
        (bool) preg_match('/class="btn btn-sm btn-outline-primary d-none" id="btnShareIncident"/', $phpSrc));
    t('#shareIncidentModal is defined', strpos($phpSrc, 'id="shareIncidentModal"') !== false);
    t('#shareModalRows (current-shares table body) is defined', strpos($phpSrc, 'id="shareModalRows"') !== false);
    t('#shareModalAddForm (conditionally-rendered add-a-share form) starts hidden (d-none)', (bool) preg_match('/id="shareModalAddForm"[^>]*class="d-none[^"]*"|class="[^"]*d-none[^"]*"[^>]*id="shareModalAddForm"/', $phpSrc));
    t('#shareModalTargetOrg select is defined', strpos($phpSrc, 'id="shareModalTargetOrg"') !== false);
    t('#shareModalReason textarea has maxlength=255 (matches share_reason\'s VARCHAR(255) column width)', strpos($phpSrc, 'id="shareModalReason" maxlength="255"') !== false);
    t('#shareModalError element exists (dedicated child element for error text)', strpos($phpSrc, 'id="shareModalError"') !== false);
}

if ($apiSrc !== false) {
    t('api/incident-detail.php computes can_manage_sharing from RBAC OR-check', strpos($apiSrc, "rbac_can('action.share_incident') || rbac_can('action.revoke_incident_share')") !== false);
    t('api/incident-detail.php gates can_manage_sharing on org_ticket_is_owned_by_caller()', (bool) preg_match('/can_manage_sharing.*org_ticket_is_owned_by_caller\(\$id\)/s', $apiSrc));
    // Deliberately computed OUTSIDE the `if ($shareCtx !== null)` block --
    // see api/incident-detail.php's own comment on this field: it must be
    // TRUE for the owning org's own dispatcher, who by construction is
    // NEVER inside that block ($shareCtx is null for same-org callers).
    // 'view_shared' is the audit activity fired from deep inside that
    // block, near its own close -- a reliable "this is still INSIDE the
    // block" marker, unlike the block's opening brace (trivially true for
    // anything after it, inside or outside).
    $viewSharedPos = strpos($apiSrc, "'incident', 'view_shared'");
    // strrpos, not strpos -- this exact "restore display_errors" line
    // appears multiple times earlier in the file (each early-return guard
    // clause has its own copy); the one that matters here is the LAST
    // one, right before json_response() at the very end of the file.
    $endOfDisplayErrorsRestore = strrpos($apiSrc, "ini_set('display_errors', \$prevDisplay);");
    $canManagePos = strpos($apiSrc, "\$result_incident['can_manage_sharing']");
    t('can_manage_sharing is computed AFTER the shareCtx-gated block closes (unconditional, not trapped inside the shared-viewer-only branch)',
        $viewSharedPos !== false && $endOfDisplayErrorsRestore !== false && $canManagePos !== false
        && $canManagePos > $viewSharedPos && $canManagePos < $endOfDisplayErrorsRestore);
}

if ($jsSrc !== false) {
    // renderHeader() toggle -- same per-response gating pattern as sharedBadge.
    t('renderHeader() toggles #btnShareIncident from inc.can_manage_sharing', (bool) preg_match('/shareBtn\.classList\.toggle\(\'d-none\',\s*!inc\.can_manage_sharing\)/', $jsSrc));

    // Endpoint calls -- right URL, right actions.
    t('fetches GET api/incident-share.php?ticket_id=... on modal open', strpos($jsSrc, "'api/incident-share.php?ticket_id='") !== false);
    t('POSTs action:\'create\' to api/incident-share.php', (bool) preg_match('/fetch\(\'api\/incident-share\.php\'.*?action:\s*\'create\'/s', $jsSrc));
    t('POSTs action:\'revoke\' to api/incident-share.php', (bool) preg_match('/fetch\(\'api\/incident-share\.php\'.*?action:\s*\'revoke\'/s', $jsSrc));
    // Both POSTs carry the CSRF token.
    $submitCreateFnStart = strpos($jsSrc, 'function submitCreateShare()');
    $submitCreateFnEnd = strpos($jsSrc, "\n        }\n\n        function revokeShare");
    $createFnBody = ($submitCreateFnStart !== false && $submitCreateFnEnd !== false) ? substr($jsSrc, $submitCreateFnStart, $submitCreateFnEnd - $submitCreateFnStart) : '';
    t('POST create carries csrf_token: getCsrfToken()', strpos($createFnBody, 'csrf_token: getCsrfToken()') !== false);
    $revokeFnStart = strpos($jsSrc, 'function revokeShare(');
    $revokeFnBody = $revokeFnStart !== false ? substr($jsSrc, $revokeFnStart, 900) : '';
    t('POST revoke carries csrf_token: getCsrfToken()', strpos($revokeFnBody, 'csrf_token: getCsrfToken()') !== false);

    // Error text goes through .textContent on a DEDICATED element, never a
    // parent .innerHTML replace -- this codebase's hard rule
    // (#secLabelBadge/#sharedFromBadge precedent, restated in plan.md's UI
    // section specifically for this modal's error/reason text).
    t('showShareModalError() sets text via .textContent on the dedicated #shareModalError element', (bool) preg_match('/el\.textContent\s*=\s*message;.*shareModalError/s', $jsSrc) || (bool) preg_match("/getElementById\\('shareModalError'\\)[\\s\\S]{0,200}?\\.textContent\\s*=/", $jsSrc));
    t('showShareModalError() never assigns .innerHTML for the error element', !preg_match("/shareModalError['\"]\\)[\\s\\S]{0,80}?\\.innerHTML\\s*=/", $jsSrc));

    // Table rows use the established escHtml()-before-innerHTML convention
    // (same shape org-routing-admin.js's renderRulesList() and this file's
    // own renderActions() already use) -- share_reason and org name are
    // never concatenated into the row HTML unescaped.
    t('renderShareRows() escapes shared_with_org_name via escHtml()', (bool) preg_match('/escHtml\(s\.shared_with_org_name\)/', $jsSrc));
    t('renderShareRows() escapes share_reason via escHtml()', (bool) preg_match('/escHtml\(s\.share_reason\)/', $jsSrc));
    t('renderShareRows() escapes created_by_name via escHtml() (the "source" column)', (bool) preg_match('/escHtml\(s\.created_by_name/', $jsSrc));

    // Target-org picker -- reuses api/organizations.php?action=list
    // verbatim, filters active orgs, excludes the ticket's own owning org.
    t('loadOrgList() fetches api/organizations.php?action=list (same endpoint org-routing-admin.js already uses)', strpos($jsSrc, "fetch('api/organizations.php?action=list'") !== false);
    t('populateTargetOrgSelect() filters to active orgs only', (bool) preg_match('/parseInt\(o\.active,\s*10\)\s*!==\s*1\)\s*return/', $jsSrc));
    t('populateTargetOrgSelect() excludes the ticket\'s own owning org', (bool) preg_match('/owningOrgId\s*&&\s*parseInt\(o\.id,\s*10\)\s*===\s*parseInt\(owningOrgId,\s*10\)\)\s*return/', $jsSrc));

    // initShareModal() is actually wired into init().
    t('init() calls initShareModal(id)', strpos($jsSrc, 'initShareModal(id)') !== false);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — SSE client-wiring points (plan.md's own five-file checklist).
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Part 3: SSE client-wiring -- the five files plan.md names ---\n\n";

$eventBusSrc     = @file_get_contents($base . '/assets/js/event-bus.js');
$incidentListSrc = @file_get_contents($base . '/assets/js/incident-list.js');
$callboardSrc    = @file_get_contents($base . '/assets/js/callboard.js');
$appSrc          = @file_get_contents($base . '/assets/js/app.js');

t('event-bus.js SSE_TYPES includes incident:shared', $eventBusSrc !== false && strpos($eventBusSrc, "'incident:shared'") !== false);
t('event-bus.js SSE_TYPES includes incident:unshared', $eventBusSrc !== false && strpos($eventBusSrc, "'incident:unshared'") !== false);

t('incident-list.js events array includes incident:shared', $incidentListSrc !== false && strpos($incidentListSrc, "'incident:shared'") !== false);
t('incident-list.js events array includes incident:unshared', $incidentListSrc !== false && strpos($incidentListSrc, "'incident:unshared'") !== false);

t('callboard.js has an EventBus.on(\'incident:shared\', ...) handler', $callboardSrc !== false && strpos($callboardSrc, "EventBus.on('incident:shared'") !== false);
t('callboard.js has an EventBus.on(\'incident:unshared\', ...) handler', $callboardSrc !== false && strpos($callboardSrc, "EventBus.on('incident:unshared'") !== false);

t('app.js has an EventBus.on(\'incident:shared\', ...) handler', $appSrc !== false && strpos($appSrc, "EventBus.on('incident:shared'") !== false);
t('app.js has an EventBus.on(\'incident:unshared\', ...) handler', $appSrc !== false && strpos($appSrc, "EventBus.on('incident:unshared'") !== false);

if ($jsSrc !== false) {
    t('incident-detail.js per-incident events array includes incident:shared', (bool) preg_match("/'incident:shared',\s*'incident:unshared'/", $jsSrc));
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
