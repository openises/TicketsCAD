<?php
/**
 * Phase 141 (2026-08-17) — Endpoint-integration wiring verification.
 *
 * Every one of the 11 ticket-facing endpoints delegates its actual
 * visibility/mutation decision to the SAME shared functions
 * (org_ticket_query_filter / org_can_see_ticket / org_can_mutate_ticket /
 * org_ticket_is_owned_by_caller), already proven correct in isolation by
 * tests/test_org_sharing_functions.php and tests/test_org_sharing_noop.php.
 * What THIS file proves is different: that each endpoint's OWN source
 * actually calls the RIGHT function, at the RIGHT call site, with the
 * RIGHT denial shape — a source-level wiring check, same technique
 * tests/test_org_sharing_noop.php's own Item 3 already uses (and the
 * project's established tools/api_contract_audit.php /
 * tools/schema_audit.php pattern of grepping real files rather than
 * trusting a docblock's claim).
 *
 * A full live-session HTTP round trip through each of the 8 internal
 * (cookie-authenticated) endpoints was evaluated and NOT used: this
 * codebase has no existing session-login HTTP test harness anywhere
 * (confirmed by inspecting every @requires-http test file — only the
 * bearer-token external API gets real HTTP integration tests, via
 * tests/test_external_api.php's established curl pattern), and standing
 * one up from scratch is a materially larger undertaking than this task
 * warrants. tests/test_org_sharing_read_endpoints.php and
 * tests/test_org_sharing_write_endpoints.php cover the BEHAVIORAL side
 * (real fixtures, real routing rules, real incident_create_internal())
 * by reproducing each endpoint's exact query/response shape in-process;
 * this file proves that shape is what the actual shipped file contains.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_endpoint_wiring.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 141 — Endpoint-integration wiring verification ===\n\n";

$base = realpath(__DIR__ . '/..');
function _src(string $rel): string {
    global $base;
    $p = $base . '/' . $rel;
    if (!is_file($p)) { echo "MISSING FILE: $rel\n"; return ''; }
    return file_get_contents($p);
}

// ── 5.1 incident-list.php ──────────────────────────────────────────────
echo "--- 5.1 api/incident-list.php ---\n\n";
$src = _src('api/incident-list.php');
t("calls org_ticket_query_filter()", strpos($src, 'org_ticket_query_filter(') !== false);
t("no longer calls the bare org_query_filter('t.org_id')", strpos($src, "org_query_filter('t.org_id')") === false);
t("SELECTs t.org_id (needed by the redaction choke point)", strpos($src, '`t`.`org_id`') !== false);
t("calls org_sharing_apply_list_redaction()", strpos($src, 'org_sharing_apply_list_redaction(') !== false);

// ── 5.2 incident-search.php ────────────────────────────────────────────
echo "\n--- 5.2 api/incident-search.php ---\n\n";
$src = _src('api/incident-search.php');
t("calls org_ticket_query_filter()", strpos($src, 'org_ticket_query_filter(') !== false);
t("no longer calls the bare org_query_filter('t.org_id')", strpos($src, "org_query_filter('t.org_id')") === false);
t("SELECTs t.org_id", strpos($src, '`t`.`org_id`') !== false);
t("calls org_sharing_apply_list_redaction()", strpos($src, 'org_sharing_apply_list_redaction(') !== false);

// ── 5.3 incidents.php — two sites ──────────────────────────────────────
echo "\n--- 5.3 api/incidents.php (Site A search + Site B main list) ---\n\n";
$src = _src('api/incidents.php');
t("calls org_ticket_query_filter() (both sites use it)", substr_count($src, 'org_ticket_query_filter(') >= 2);
t("no longer calls the bare org_query_filter('t.org_id')", strpos($src, "org_query_filter('t.org_id')") === false);
t("Site A SELECTs t.org_id", strpos($src, 'SELECT t.id, t.org_id, t.scope') !== false);
t("Site B SELECTs t.org_id", strpos($src, '`t`.`org_id`') !== false);
t("calls org_sharing_apply_list_redaction() at least twice (search branch + main list)", substr_count($src, 'org_sharing_apply_list_redaction(') >= 2);

// ── 5.4 callboard.php ──────────────────────────────────────────────────
echo "\n--- 5.4 api/callboard.php ---\n\n";
$src = _src('api/callboard.php');
t("calls org_ticket_query_filter()", strpos($src, 'org_ticket_query_filter(') !== false);
t("no longer calls the bare org_query_filter('t.org_id')", strpos($src, "org_query_filter('t.org_id')") === false);
t("still uses the direct '.=' concatenation pattern (not the array-of-conditions pattern)", strpos($src, '$sql .= $orgFrag;') !== false);
t("calls org_sharing_apply_list_redaction()", strpos($src, 'org_sharing_apply_list_redaction(') !== false);

// ── 5.5 reports.php ────────────────────────────────────────────────────
echo "\n--- 5.5 api/reports.php ---\n\n";
$src = _src('api/reports.php');
t("calls org_ticket_query_filter() for the ticket-scoped fragment", strpos($src, '[$rptTicketFrag, $rptTicketVars] = org_ticket_query_filter(') !== false);
t("the adjacent org_member_query_filter('m.id') call is UNTOUCHED", strpos($src, "org_member_query_filter('m.id')") !== false);
t("does NOT call org_sharing_apply_list_redaction() (aggregate counts only, per plan.md's tier matrix)", strpos($src, 'org_sharing_apply_list_redaction(') === false);

// ── 5.6 statistics.php ─────────────────────────────────────────────────
echo "\n--- 5.6 api/statistics.php ---\n\n";
$src = _src('api/statistics.php');
t("calls org_ticket_query_filter() for the t.org_id fragment ONLY", strpos($src, "org_ticket_query_filter(null, 't')") !== false);
t("the r.org_id (responder) call is UNTOUCHED — still the bare org_query_filter('r.org_id')", strpos($src, "org_query_filter('r.org_id')") !== false);
t("a deliberate-untouched comment exists at the r.org_id line", strpos($src, 'DELIBERATELY LEFT UNTOUCHED') !== false);
t("does NOT call org_sharing_apply_list_redaction() (aggregate counts only)", strpos($src, 'org_sharing_apply_list_redaction(') === false);

// ── 5.7 incident-detail.php ────────────────────────────────────────────
echo "\n--- 5.7 api/incident-detail.php ---\n\n";
$src = _src('api/incident-detail.php');
t("org_can_see_ticket() call site is UNCHANGED (inherits share-awareness automatically)", strpos($src, 'if (!org_can_see_ticket($id)) {') !== false);
t("calls org_share_context_for_ticket()", strpos($src, 'org_share_context_for_ticket($id)') !== false);
t("calls org_share_redact_ticket_fields() on the ticket-level result", strpos($src, 'org_share_redact_ticket_fields($result_incident') !== false);
t("calls org_share_redact_assignment_fields() per assignment row (roster boundary)", strpos($src, 'org_share_redact_assignment_fields(') !== false);
t("empties the action log for view tier (never field-filters narrative)", strpos($src, '$actions = [];') !== false);
t("fires the 'view_shared' audit_log category/activity", strpos($src, "'incident', 'view_shared', 'ticket', \$id") !== false);
t("adds shared_from_org_id / shared_from_org_name to the response", strpos($src, "shared_from_org_id") !== false && strpos($src, "shared_from_org_name") !== false);

// ── 5.8 incident-update.php ────────────────────────────────────────────
echo "\n--- 5.8 api/incident-update.php ---\n\n";
$src = _src('api/incident-update.php');
t("gate calls org_can_mutate_ticket() instead of the plain read gate", strpos($src, 'if (!org_can_mutate_ticket($ticket_id)) {') !== false);
t("denial is a 403 with the exact plan.md message when the caller HAS view visibility", strpos($src, "Your organization's access to this incident does not permit this action\", 403") !== false);
t("falls through to the original 404 'Ticket not found' when the caller has NO visibility at all", strpos($src, "json_error('Ticket not found', 404);") !== false);

// ── 5.9 incident-assign.php ────────────────────────────────────────────
echo "\n--- 5.9 api/incident-assign.php ---\n\n";
$src = _src('api/incident-assign.php');
t("gate calls org_can_mutate_ticket() instead of the plain read gate", strpos($src, 'if (!org_can_mutate_ticket($ticket_id)) {') !== false);
t("denial is a 403 with the exact plan.md message when the caller HAS view visibility", strpos($src, "Your organization's access to this incident does not permit this action\", 403") !== false);
t("falls through to the original 404 'Ticket not found' when the caller has NO visibility at all", strpos($src, "json_error('Ticket not found', 404);") !== false);

// ── 5.10 dispositions-picker.php ───────────────────────────────────────
echo "\n--- 5.10 api/dispositions-picker.php ---\n\n";
$src = _src('api/dispositions-picker.php');
t("still calls the plain read gate org_can_see_ticket() (view tier is sufficient; NO tier-aware gate needed)", strpos($src, 'if (!org_can_see_ticket($ticketId))') !== false);
t("does NOT call org_can_mutate_ticket() (this is a read-only reference-list endpoint)", strpos($src, 'org_can_mutate_ticket(') === false);

// ── 5.11 external/v1/incidents.php — four sites ────────────────────────
echo "\n--- 5.11 api/external/v1/incidents.php (Sites 1-4) ---\n\n";
$src = _src('api/external/v1/incidents.php');
// Site 1 — GET single
t("Site 1 (GET single): org_can_see_ticket() call site UNCHANGED", strpos($src, 'if (!org_can_see_ticket($id)) ext_api_error(') !== false);
t("Site 1: applies redaction via org_share_redact_ticket_fields()", strpos($src, 'org_share_redact_ticket_fields($row') !== false);
t("Site 1: fires 'view_shared' audit on a share-derived read", strpos($src, "'incident', 'view_shared', 'ticket', \$id") !== false);
// Site 2 — GET list
t("Site 2 (GET list): calls org_ticket_query_filter()", strpos($src, 'org_ticket_query_filter(null, \'t\')') !== false);
t("Site 2: SELECTs t.org_id", strpos($src, 't.org_id, t.in_types_id') !== false);
t("Site 2: calls org_sharing_apply_list_redaction()", strpos($src, 'org_sharing_apply_list_redaction($rows)') !== false);
// Site 3 — PATCH
t("Site 3 (PATCH): gate calls org_can_mutate_ticket()", strpos($src, 'if (!org_can_mutate_ticket($ticketId)) {') !== false);
t("Site 3: denial is ext_api_error('forbidden', 403) when the caller HAS view visibility", strpos($src, "ext_api_error('forbidden', 403);") !== false);
// Site 4 — DELETE
t("Site 4 (DELETE): gate calls org_ticket_is_owned_by_caller(), NOT org_can_mutate_ticket()", strpos($src, 'if (!org_ticket_is_owned_by_caller($ticketId)) ext_api_error(') !== false);
t("Site 4: the DELETE site does NOT call org_can_mutate_ticket() anywhere near it", substr_count($src, 'org_can_mutate_ticket($ticketId)') === 1 /* only Site 3's PATCH call */);

// ── inc/org-sharing.php — routing-rule write layer (audit logging) ─────
echo "\n--- inc/org-sharing.php — routing-rule CRUD + audit ---\n\n";
$src = _src('inc/org-sharing.php');
t("org_routing_rule_create() exists", strpos($src, 'function org_routing_rule_create(') !== false);
t("org_routing_rule_update() exists", strpos($src, 'function org_routing_rule_update(') !== false);
t("org_routing_rule_deactivate() exists", strpos($src, 'function org_routing_rule_deactivate(') !== false);
t("create fires the 'config'/'create' audit category", strpos($src, "'config', 'create', 'org_type_routing'") !== false);
t("update fires the 'config'/'update' audit category", strpos($src, "'config', 'update', 'org_type_routing'") !== false);
t("deactivate fires the 'config'/'deactivate' audit category", strpos($src, "'config', 'deactivate', 'org_type_routing'") !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
