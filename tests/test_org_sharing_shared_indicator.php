<?php
/**
 * Phase 141 (2026-08-17) — "Shared from [Org]" client-side indicator.
 *
 * Two layers, per this project's established split (structural checks for
 * admin/display UI, live fixtures for the data the UI depends on --
 * tests/test_public_board_rbac.php's own docblock is the cited precedent):
 *
 *   1. LIVE FIXTURE (not assumed): reproduces incident-list.php's AND
 *      callboard.php's exact query + org_sharing_apply_list_redaction()
 *      pipeline against a REAL ticket created via the real
 *      incident_create_internal() writer through a REAL active routing
 *      rule -- proving shared_from_org_id/shared_from_org_name are present
 *      in the row the responding org's client actually receives, and
 *      GENUINELY ABSENT (never present-but-null, never present-but-empty)
 *      in the row the OWNING org's own client receives for the identical
 *      ticket. test_org_sharing_functions.php already proves this at the
 *      org_sharing_apply_list_redaction() unit level; this file proves it
 *      through incident-list.php's AND callboard.php's own SELECT shapes
 *      specifically (callboard.php was not covered by that pass), and adds
 *      the explicit "absent, not present-but-falsy" assertion for both.
 *
 *   2. STRUCTURAL: the actual shipped incident-list.js / callboard.js /
 *      incident-detail.js / incident-detail.php files reference
 *      shared_from_org_name correctly -- through this codebase's own
 *      established escaping helpers (escHtml/escAttr/esc, never a raw,
 *      unescaped concatenation), and incident-detail.js sets the badge via
 *      .textContent (never .innerHTML) per this codebase's hard XSS-safety
 *      rule. Cannot drive the real render functions via node the way
 *      test_public_board_frontend_safety.php does (public-board.php
 *      deliberately exposes window.PublicBoardRender for exactly that;
 *      these three older files are private, un-exported IIFEs) -- source
 *      inspection is the proportionate check here, mirroring
 *      tests/test_org_sharing_endpoint_wiring.php's own precedent of
 *      proving wiring via source inspection rather than re-implementing
 *      the render path.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_shared_indicator.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/../inc/incident-write.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — \"Shared from [Org]\" indicator ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

$hasSharesTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'incident_shares']
);
if (!$hasSharesTable) {
    echo "SKIP: incident_shares table not present -- run sql/run_phase141_cross_org_ticket_sharing.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — live fixture: present for the shared-with org, ABSENT for the
// owning org, on the SAME ticket, through incident-list.php's AND
// callboard.php's actual query + redaction shapes.
// ═══════════════════════════════════════════════════════════════════════
echo "--- Live fixture: incident-list.php + callboard.php row shapes ---\n\n";

$ownerOrgId  = 900002660;
$sharedOrgId = 900002661;
$ownerUserId  = 900002662;
$sharedUserId = 900002663;

$createdOrgIds = [$ownerOrgId, $sharedOrgId];
$createdTicketIds = [];
$createdRuleIds = [];
$createdTypeIds = [];

$cleanup = function () use ($prefix, &$createdOrgIds, &$createdTicketIds, &$createdRuleIds, &$createdTypeIds, $ownerUserId, $sharedUserId) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdRuleIds as $id) { try { db_query("DELETE FROM {$prefix}org_type_routing WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTypeIds as $id) { try { db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$ownerUserId, $sharedUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, 'ZZ141 Indicator OwnerOrg', 1)", [$ownerOrgId]);
    db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, 'ZZ141 Indicator SharedOrg', 1)", [$sharedOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$sharedUserId, $sharedOrgId, $sharedOrgId]);

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 indicator type', 'ZZ141IndicatorGroup')", ['zz141ind-' . uniqid()]);
    $typeId = (int) db_insert_id();
    $createdTypeIds[] = $typeId;

    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', 'ZZ141IndicatorGroup', 'view', 1)",
        [$ownerOrgId, $sharedOrgId]
    );
    $createdRuleIds[] = (int) db_insert_id();

    $_SESSION['user_id'] = $ownerUserId;
    $_SESSION['active_org_id'] = $ownerOrgId;
    $createResult = incident_create_internal([
        'in_types_id' => $typeId,
        'scope'       => 'zz141 indicator test incident',
        'street'      => '661 ZZ141 Way', 'city' => 'Testville', 'state' => 'MN',
    ], $ownerUserId);
    unset($_SESSION['active_org_id']);

    t("fixture ticket created via the real writer", empty($createResult['errors']));
    $ticketId = (int) ($createResult['id'] ?? 0);
    $createdTicketIds[] = $ticketId;
    t("a real incident_shares row exists for the shared-with org", (bool) db_fetch_value(
        "SELECT 1 FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketId, $sharedOrgId]
    ));

    // -- incident-list.php's exact query + row shape (same as
    // tests/test_org_sharing_read_endpoints.php's incident-list.php block) --
    foreach ([
        'shared org (should see the badge)' => $sharedUserId,
        'owning org (should NOT see any badge)' => $ownerUserId,
    ] as $label => $userId) {
        [$frag, $vars] = org_ticket_query_filter($userId, 't');
        $rows = db_fetch_all(
            "SELECT `t`.`id`, `t`.`org_id`, `t`.`scope`
               FROM `{$prefix}ticket` `t`
              WHERE 1=1 {$frag} AND t.id = ?",
            array_merge($vars, [$ticketId])
        );
        t("incident-list.php query surfaces the ticket for $label", count($rows) === 1);
        $incidents = [['id' => (int) $rows[0]['id'], 'org_id' => (int) $rows[0]['org_id'], 'scope' => $rows[0]['scope'] ?? '']];
        $redacted = org_sharing_apply_list_redaction($incidents, $userId);

        if ($userId === $sharedUserId) {
            t("incident-list.php row: shared_from_org_id IS PRESENT for $label", array_key_exists('shared_from_org_id', $redacted[0]));
            t("incident-list.php row: shared_from_org_id equals the owning org (not null, not another org)", ($redacted[0]['shared_from_org_id'] ?? null) === $ownerOrgId);
            t("incident-list.php row: shared_from_org_name IS PRESENT and resolved for $label", ($redacted[0]['shared_from_org_name'] ?? null) === 'ZZ141 Indicator OwnerOrg');
        } else {
            t("incident-list.php row: shared_from_org_id is GENUINELY ABSENT (key not set at all) for $label", !array_key_exists('shared_from_org_id', $redacted[0]));
            t("incident-list.php row: shared_from_org_name is GENUINELY ABSENT for $label", !array_key_exists('shared_from_org_name', $redacted[0]));
        }
    }

    // -- callboard.php's exact query + row shape --
    foreach ([
        'shared org' => $sharedUserId,
        'owning org' => $ownerUserId,
    ] as $label => $userId) {
        [$frag, $vars] = org_ticket_query_filter($userId, 't');
        $sql = "SELECT `t`.`id`, `t`.`org_id`, `t`.`scope`
                  FROM `{$prefix}ticket` `t`
                 WHERE (`t`.`status` = 2 OR `t`.`status` = 3
                        OR (`t`.`status` = 1 AND `t`.`problemend` >= DATE_SUB(NOW(), INTERVAL ? MINUTE)))
                   AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')";
        $sql .= $frag;
        $sql .= " AND t.id = ?";
        $rows = db_fetch_all($sql, array_merge([30], $vars, [$ticketId]));
        t("callboard.php query surfaces the OPEN ticket for $label", count($rows) === 1);
        $cbRow = ['id' => (int) $rows[0]['id'], 'org_id' => (int) $rows[0]['org_id'], 'scope' => $rows[0]['scope'] ?? ''];
        $cbRedacted = org_sharing_apply_list_redaction([$cbRow], $userId);

        if ($userId === $sharedUserId) {
            t("callboard.php row: shared_from_org_name PRESENT for $label", ($cbRedacted[0]['shared_from_org_name'] ?? null) === 'ZZ141 Indicator OwnerOrg');
        } else {
            t("callboard.php row: shared_from_org_name ABSENT for $label", !array_key_exists('shared_from_org_name', $cbRedacted[0]));
        }
    }
} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — structural: the shipped client files actually render/hide the
// badge, safely.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Structural: shipped client files wire the indicator safely ---\n\n";

$incList = file_get_contents($base . '/assets/js/incident-list.js');
$callboard = file_get_contents($base . '/assets/js/callboard.js');
$incDetailJs = file_get_contents($base . '/assets/js/incident-detail.js');
$incDetailPhp = file_get_contents($base . '/incident-detail.php');

// incident-list.js
t("incident-list.js reads inc.shared_from_org_name", strpos($incList, 'inc.shared_from_org_name') !== false);
t("incident-list.js escapes the org name via escHtml() before concatenation (never a raw template literal)",
    (bool) preg_match('/escHtml\(\s*inc\.shared_from_org_name\s*\)/', $incList));
t("incident-list.js escapes the org name via escAttr() for the title attribute",
    (bool) preg_match('/escAttr\(\s*inc\.shared_from_org_name\s*\)/', $incList));
t("incident-list.js defines its own escAttr() helper (added for this indicator)", strpos($incList, 'function escAttr(') !== false);

// callboard.js
t("callboard.js reads inc.shared_from_org_name", strpos($callboard, 'inc.shared_from_org_name') !== false);
t("callboard.js escapes the org name via esc() before concatenation",
    (bool) preg_match('/esc\(\s*inc\.shared_from_org_name\s*\)/', $callboard));
t("callboard.js escapes the org name via escAttr() for the title attribute",
    (bool) preg_match('/escAttr\(\s*inc\.shared_from_org_name\s*\)/', $callboard));

// incident-detail.js — must use .textContent, never .innerHTML, for the
// badge text (this codebase's hard XSS-safety rule).
t("incident-detail.js reads inc.shared_from_org_name in renderHeader()", strpos($incDetailJs, 'inc.shared_from_org_name') !== false);
t("incident-detail.js sets the badge via .textContent", strpos($incDetailJs, 'sharedBadgeText.textContent') !== false);
t("incident-detail.js never assigns .innerHTML for the shared-from badge",
    !preg_match('/sharedBadge(Text)?\.innerHTML\s*=/', $incDetailJs));
t("incident-detail.js toggles d-none OFF (remove) when a share is present", strpos($incDetailJs, "sharedBadge.classList.remove('d-none')") !== false);
t("incident-detail.js toggles d-none ON (add) when no share is present", strpos($incDetailJs, "sharedBadge.classList.add('d-none')") !== false);

// incident-detail.php — the badge markup itself starts hidden.
t("incident-detail.php defines #sharedFromBadge", strpos($incDetailPhp, 'id="sharedFromBadge"') !== false);
t("incident-detail.php defines #sharedFromBadgeText", strpos($incDetailPhp, 'id="sharedFromBadgeText"') !== false);
t("incident-detail.php's #sharedFromBadge starts with d-none (hidden by default)",
    (bool) preg_match('/class="badge bg-info text-dark d-none" id="sharedFromBadge"/', $incDetailPhp));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
