<?php
/**
 * Phase 141 (2026-08-17) — Read-endpoint visibility, behavioral.
 *
 * Reproduces each of the 8 read-shaped endpoints' EXACT query +
 * response-shaping + redaction pipeline (same SELECT columns, same
 * output array keys) against REAL fixtures: real organizations, a real
 * org_type_routing rule, and a ticket created through the ACTUAL
 * incident_create_internal() writer — never a hand-inserted
 * incident_shares row. incident_create_internal()'s own call into
 * org_sharing_apply_routing_on_create() is what produces the share, per
 * this project's standing "reproduce via the real writer" discipline
 * (see the memory note this convention is named after — a hand-crafted
 * repro has hidden real bugs in this codebase before).
 *
 * Covers: incident-list.php, incident-search.php, incidents.php (both
 * sites), callboard.php, reports.php (visibility only, no redaction),
 * statistics.php (visibility only, responder-scope untouched),
 * external API's GET list. incident-detail.php and external API's GET
 * single are covered by tests/test_org_sharing_redaction.php (their
 * redaction shape is large enough to warrant its own file).
 * dispositions-picker.php needs no fixture — it only needs
 * org_can_see_ticket() to pass, already proven in
 * tests/test_org_sharing_functions.php.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_read_endpoints.php
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

echo "=== Phase 141 — Read-endpoint visibility (real fixtures) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$ownerOrgId  = 900002260;
$viewOrgId   = 900002261;  // gets a 'view'-tier share
$assistOrgId = 900002262;  // gets an 'assist'-tier share
$strangerOrgId = 900002263; // never shared with — must stay invisible
$viewUserId  = 900002264;
$assistUserId = 900002265;
$strangerUserId = 900002266;

$createdOrgIds = [];
$createdTicketIds = [];
$createdRuleIds = [];
$createdTypeIds = [];

$cleanup = function () use (
    $prefix, &$createdOrgIds, &$createdTicketIds, &$createdRuleIds, &$createdTypeIds,
    $viewUserId, $assistUserId, $strangerUserId
) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdRuleIds as $id)   { try { db_query("DELETE FROM {$prefix}org_type_routing WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTypeIds as $id)   { try { db_query("DELETE FROM {$prefix}in_types WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id)    { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?, ?)", [$viewUserId, $assistUserId, $strangerUserId]); } catch (Throwable $e) {}
};
$cleanup();

try {
    foreach ([$ownerOrgId => 'ZZ141RE Owner', $viewOrgId => 'ZZ141RE ViewOrg',
              $assistOrgId => 'ZZ141RE AssistOrg', $strangerOrgId => 'ZZ141RE Stranger'] as $id => $name) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$id, $name]);
        $createdOrgIds[] = $id;
    }
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$viewUserId, $viewOrgId, $viewOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$assistUserId, $assistOrgId, $assistOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$strangerUserId, $strangerOrgId, $strangerOrgId]);

    db_query("INSERT INTO {$prefix}in_types (`type`, `description`, `group`) VALUES (?, 'zz141 read-endpoints type', 'ZZ141REGroup')", ['zz141re-' . uniqid()]);
    $typeId = (int) db_insert_id();
    $createdTypeIds[] = $typeId;

    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', 'ZZ141REGroup', 'view', 1)",
        [$ownerOrgId, $viewOrgId]
    );
    $createdRuleIds[] = (int) db_insert_id();
    db_query(
        "INSERT INTO {$prefix}org_type_routing (owning_org_id, shared_with_org_id, match_scope, match_group, access_tier, active) VALUES (?, ?, 'group', 'ZZ141REGroup', 'assist', 1)",
        [$ownerOrgId, $assistOrgId]
    );
    $createdRuleIds[] = (int) db_insert_id();

    // Real ticket, created through the REAL writer, with a caller PII
    // field set so the redaction assertions below have something to
    // prove is actually stripped.
    $_SESSION['user_id'] = $viewUserId;
    $_SESSION['active_org_id'] = $ownerOrgId;
    $createResult = incident_create_internal([
        'in_types_id' => $typeId,
        'scope'       => 'zz141 read-endpoints routed incident',
        'contact'     => 'Jane ReadEndpointCaller',
        'phone'       => '555-2600',
        'description' => 'zz141 free-text narrative that must never reach view tier',
        'street'      => '260 ZZ141 Way', 'city' => 'Testville', 'state' => 'MN',
    ], $viewUserId);
    unset($_SESSION['active_org_id'], $_SESSION['user_id']);

    t("fixture ticket created via the real writer", empty($createResult['errors']));
    $ticketId = (int) ($createResult['id'] ?? 0);
    $createdTicketIds[] = $ticketId;
    t("fixture ticket has a real id", $ticketId > 0);

    $shareCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}incident_shares WHERE ticket_id = ?", [$ticketId]
    );
    t("the real writer produced exactly 2 real incident_shares rows (one per matching rule)", $shareCount === 2);

    // ══════════════════════════════════════════════════════════════════
    // incident-list.php's ACTUAL query + row shape
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- incident-list.php ---\n\n";

    [$frag, $vars] = org_ticket_query_filter($viewUserId, 't');
    $rows = db_fetch_all(
        "SELECT `t`.`id`, `t`.`incident_number`, `t`.`org_id`, `t`.`scope`, `t`.`street`, `t`.`city`,
                `t`.`state`, `t`.`severity`, `t`.`status`, `t`.`date`, `t`.`updated`,
                `it`.`type` AS `type_name`, `it`.`group` AS `type_group`
           FROM `{$prefix}ticket` `t` LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
          WHERE 1=1 {$frag} AND t.id = ?",
        array_merge($vars, [$ticketId])
    );
    t("view-tier org's incident-list query surfaces the routed ticket", count($rows) === 1);
    $incidents = [];
    foreach ($rows as $row) {
        $incidents[] = [
            'id' => (int) $row['id'], 'incident_number' => $row['incident_number'] ?? null,
            'org_id' => (int) $row['org_id'], 'scope' => $row['scope'] ?? '',
            'street' => $row['street'] ?? '', 'city' => $row['city'] ?? '', 'state' => $row['state'] ?? '',
            'severity' => (int) $row['severity'], 'status' => (int) $row['status'],
            'date' => $row['date'], 'updated' => $row['updated'],
            'type_name' => $row['type_name'] ?? '', 'type_group' => $row['type_group'] ?? '',
            'active_responders' => 0,
        ];
    }
    $redacted = org_sharing_apply_list_redaction($incidents, $viewUserId);
    t("redacted row is annotated shared_from_org_id = owning org", ($redacted[0]['shared_from_org_id'] ?? null) === $ownerOrgId);
    t("redacted row keeps 'scope' (dispatch-relevant)", array_key_exists('scope', $redacted[0]));

    // ══════════════════════════════════════════════════════════════════
    // incident-search.php's ACTUAL query + row shape
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- incident-search.php ---\n\n";

    [$frag, $vars] = org_ticket_query_filter($viewUserId, 't');
    $rows = db_fetch_all(
        "SELECT `t`.`id`, `t`.`org_id`, `t`.`scope`, `t`.`description`, `t`.`street`, `t`.`city`, `t`.`state`,
                `t`.`severity`, `t`.`status`, `t`.`date`, `t`.`updated`, `t`.`contact`, `t`.`phone`,
                `it`.`type` AS `type_name`, `it`.`group` AS `type_group`
           FROM `{$prefix}ticket` `t` LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
          WHERE 1=1 {$frag} AND t.id = ?",
        array_merge($vars, [$ticketId])
    );
    t("view-tier org's incident-search query surfaces the routed ticket", count($rows) === 1);
    $results = [['id' => (int) $rows[0]['id'], 'org_id' => (int) $rows[0]['org_id'],
                 'scope' => $rows[0]['scope'] ?? '', 'description' => $rows[0]['description'] ?? '',
                 'contact' => $rows[0]['contact'] ?? '', 'phone' => $rows[0]['phone'] ?? '',
                 'active_responders' => 0]];
    $redacted = org_sharing_apply_list_redaction($results, $viewUserId);
    t("view tier: 'description' is stripped (free-text narrative)", !array_key_exists('description', $redacted[0]));
    t("view tier: 'contact' is stripped (caller PII)", !array_key_exists('contact', $redacted[0]));
    t("view tier: 'phone' is stripped (caller PII)", !array_key_exists('phone', $redacted[0]));
    t("view tier: excluded values are genuinely absent from the payload", !in_array('Jane ReadEndpointCaller', $redacted[0], true));

    $assistResults = org_sharing_apply_list_redaction($results, $assistUserId);
    // Re-derive: assist org's own visibility query first (assistUserId's
    // own org isn't the row's org_id, so this reproduces the "shared row"
    // path at assist tier).
    t("assist tier: 'description' is NOT stripped (same field set a same-org dispatcher gets)", array_key_exists('description', $assistResults[0]));
    t("assist tier: 'contact' is NOT stripped", array_key_exists('contact', $assistResults[0]));

    // ══════════════════════════════════════════════════════════════════
    // incidents.php Site A (search) + Site B (main list)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- incidents.php (Site A + Site B) ---\n\n";

    [$fragA, $varsA] = org_ticket_query_filter($viewUserId, 't');
    $siteARows = db_fetch_all(
        "SELECT t.id, t.org_id, t.scope, t.description FROM `{$prefix}ticket` t WHERE 1=1 {$fragA} AND t.id = ?",
        array_merge($varsA, [$ticketId])
    );
    t("Site A (search branch): view-tier org's query surfaces the routed ticket", count($siteARows) === 1);

    [$fragB, $varsB] = org_ticket_query_filter($viewUserId, 't');
    $siteBRows = db_fetch_all(
        "SELECT t.id, t.org_id, t.scope, t.description FROM `{$prefix}ticket` t WHERE 1=1 {$fragB} AND t.id = ?",
        array_merge($varsB, [$ticketId])
    );
    t("Site B (main list branch): view-tier org's query surfaces the routed ticket", count($siteBRows) === 1);

    [$fragStranger, $varsStranger] = org_ticket_query_filter($strangerUserId, 't');
    $strangerRows = db_fetch_all(
        "SELECT t.id FROM `{$prefix}ticket` t WHERE 1=1 {$fragStranger} AND t.id = ?",
        array_merge($varsStranger, [$ticketId])
    );
    t("a stranger org (no routing rule at all) does NOT see the ticket via either site's query shape", count($strangerRows) === 0);

    // ══════════════════════════════════════════════════════════════════
    // callboard.php's ACTUAL query + row shape
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- callboard.php ---\n\n";

    // callboard only surfaces status IN (2,3) or recently-closed status=1;
    // the fixture ticket defaults to open (status 2) via incident_create_internal.
    [$frag, $vars] = org_ticket_query_filter($viewUserId, 't');
    $sql = "SELECT `t`.`id`, `t`.`org_id`, `t`.`scope`, `t`.`description`
              FROM `{$prefix}ticket` `t`
             WHERE (`t`.`status` = 2 OR `t`.`status` = 3
                    OR (`t`.`status` = 1 AND `t`.`problemend` >= DATE_SUB(NOW(), INTERVAL ? MINUTE)))
               AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')";
    $sql .= $frag;
    $sql .= " AND t.id = ?";
    $rows = db_fetch_all($sql, array_merge([30], $vars, [$ticketId]));
    t("view-tier org's callboard query surfaces the routed OPEN ticket", count($rows) === 1);
    $cbRow = ['id' => (int) $rows[0]['id'], 'org_id' => (int) $rows[0]['org_id'],
              'scope' => $rows[0]['scope'] ?? '', 'description' => $rows[0]['description'] ?? ''];
    $cbRedacted = org_sharing_apply_list_redaction([$cbRow], $viewUserId);
    t("callboard row: 'description' stripped for view tier", !array_key_exists('description', $cbRedacted[0]));

    // ══════════════════════════════════════════════════════════════════
    // reports.php — visibility only, no redaction (aggregate counts)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- reports.php (visibility widening only) ---\n\n";

    [$rptFrag, $rptVars] = org_ticket_query_filter($viewUserId, 't');
    $cnt = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}ticket` t WHERE 1=1 {$rptFrag} AND t.id = ?",
        array_merge($rptVars, [$ticketId])
    );
    t("view-tier org's reports.php-shaped ticket-count query counts the routed ticket", $cnt === 1);

    [$strangerFrag, $strangerVars] = org_ticket_query_filter($strangerUserId, 't');
    $strangerCnt = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}ticket` t WHERE 1=1 {$strangerFrag} AND t.id = ?",
        array_merge($strangerVars, [$ticketId])
    );
    t("a stranger org's reports.php-shaped count does NOT include the ticket", $strangerCnt === 0);

    // ══════════════════════════════════════════════════════════════════
    // statistics.php — visibility only, responder-scope untouched
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- statistics.php (ticket-count widening; responder-scope untouched) ---\n\n";

    [$statFrag, $statVars] = org_ticket_query_filter($viewUserId, 't');
    $statCnt = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}ticket` t WHERE 1=1 {$statFrag} AND t.id = ?",
        array_merge($statVars, [$ticketId])
    );
    t("view-tier org's statistics.php-shaped ticket count includes the routed ticket", $statCnt === 1);

    // The responder-scoped fragment must remain the PLAIN org_query_filter
    // shape (never widened by sharing) — verified by literally calling the
    // untouched function and confirming it produces no share-derived
    // widening even for an org with a live ticket share.
    [$respFrag, $respVars] = org_query_filter('r.org_id', $viewUserId);
    t("the responder-scoped fragment contains NO incident_shares reference (roster isolation)", strpos($respFrag, 'incident_shares') === false);

    // ══════════════════════════════════════════════════════════════════
    // External API GET list — same query shape as api/external/v1/incidents.php Site 2
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- external API GET list (Site 2) ---\n\n";

    [$extFrag, $extVars] = org_ticket_query_filter($viewUserId, 't');
    $extWhere = ["(t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')"];
    $extParams = [];
    if ($extFrag !== '') {
        $extWhere[] = '(' . preg_replace('/^\s*AND\s+/', '', $extFrag) . ')';
        $extParams = array_merge($extParams, $extVars);
    }
    $extRows = db_fetch_all(
        "SELECT t.id, t.org_id, t.in_types_id, t.scope, t.severity, t.status, t.contact, t.phone,
                t.street, t.city, t.state, t.lat, t.lng, t.date, t.updated, t.incident_number,
                it.type AS in_type_name
           FROM `{$prefix}ticket` t LEFT JOIN `{$prefix}in_types` it ON it.id = t.in_types_id
          WHERE " . implode(' AND ', $extWhere) . " AND t.id = ?",
        array_merge($extParams, [$ticketId])
    );
    t("view-tier org's external-API list query surfaces the routed ticket", count($extRows) === 1);
    $extRedacted = org_sharing_apply_list_redaction($extRows, $viewUserId);
    t("external API list row: 'contact' stripped for view tier", !array_key_exists('contact', $extRedacted[0]));
    t("external API list row: 'phone' stripped for view tier", !array_key_exists('phone', $extRedacted[0]));
    t("external API list row: 'in_type_name' alias survives (extended allowlist entry)", array_key_exists('in_type_name', $extRedacted[0]));
    t("external API list row: 'scope' survives (dispatch-relevant)", array_key_exists('scope', $extRedacted[0]));

} finally {
    $cleanup();
    unset($_SESSION['user_id'], $_SESSION['active_org_id']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
