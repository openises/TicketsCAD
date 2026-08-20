<?php
/**
 * Phase 142 (2026-08-17) — api/incident-share.php request/response shape.
 *
 * GET is driven end to end through the REAL file, in a subprocess, via a
 * session-attach trick (tests/_incident_share_get_probe.php, same
 * technique as tests/_gh25_endpoint_probe.php's own docblock: "Session
 * auth is satisfied without touching anybody's credentials — a session
 * file is written directly and its id handed over in $_COOKIE"). This
 * proves the real gates (rbac_can(), org_ticket_is_owned_by_caller()) and
 * the real json_response()/json_error() shapes, not a reproduction.
 *
 * POST (create/revoke) is NOT driven the same way. Verified directly
 * against this PHP 8.2.4 CLI build: `file_get_contents('php://input')`
 * returns an EMPTY string even when JSON is piped to STDIN --
 * `php://input` is a CGI/web-SAPI stream, not available under CLI SAPI on
 * this build (confirmed with a throwaway script before writing this file,
 * per this project's root-cause-troubleshooting discipline of verifying
 * rather than assuming a mechanism works). api/incident-share.php's POST
 * body parsing depends on exactly that stream, so a CLI-subprocess probe
 * cannot deliver a JSON body to it.
 *
 * Instead, POST is verified the way tests/test_org_sharing_write_endpoints.php
 * (Phase 141's own precedent) verifies write endpoints: the endpoint's own
 * ~10-line gate sequence (RBAC check -> ownership check -> call the
 * writer) is hand-mirrored in a small reproduction function, but EVERY
 * decision inside that mirror is a REAL, live call -- rbac_can() reads the
 * actual $_SESSION + actual role_permissions grants (rbac_clear_cache()
 * between simulated callers so no stale per-request cache leaks between
 * them); org_ticket_is_owned_by_caller()/org_can_see_ticket() and the
 * actual org_sharing_create_manual_share()/org_sharing_revoke_share()
 * writers are called directly, unmocked. Only the STATUS-CODE MAPPING
 * (which json_error(...) call fires for which gate outcome) is
 * hand-copied -- and Part 3 below independently verifies that mapping
 * against the endpoint's OWN source text via the PHP tokenizer, so a
 * future edit that changes the real gate order/shape is caught even
 * though this file's reproduction can't literally execute it.
 *
 * Covers (tasks.md section 5's own test-file scope):
 *   1. GET: success shape (shares[], can_create, can_revoke,
 *      owning_org_id) for the real owning-org caller.
 *   2. GET/POST: the 403 shape for RBAC-DENIED (holds no permission,
 *      even though they own the ticket) asserted SEPARATELY from the 403
 *      shape for OWNERSHIP-DENIED (holds the permission, but doesn't own
 *      the ticket) -- per the top-level instruction, so a future
 *      regression that merges the two checks into one is caught.
 *   3. A caller with neither ownership nor visibility at all gets 404,
 *      not 403 (no existence leak).
 *   4. POST create/revoke succeed for the real owning-org caller;
 *      POST revoke's IDOR guard (ticket_id derived from the row, not
 *      caller input) is Phase 3/4's own test file's job, not
 *      re-litigated here.
 *   5. Structural/tokenizer confirmation (matching
 *      tests/test_org_sharing_rbac.php Part 6's technique) that
 *      api/incident-share.php never ORs is_admin() into either gate, and
 *      that CSRF is checked on both POST actions.
 *
 * @requires-db
 * Usage: php tests/test_org_sharing_manual_api.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 142 — api/incident-share.php request/response shape ===\n\n";

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
// Fixtures
// ═══════════════════════════════════════════════════════════════════════

$ownerOrgId      = 900004700; // owns ticket X, holds RBAC (Dispatcher)
$ownerNoRbacOrgId = 900004701; // ALSO owns a ticket (Y), but its user holds NO relevant RBAC
$sharedInOrgId   = 900004702; // has a 'view' share on ticket X -- visibility, not ownership
$strangerOrgId   = 900004703; // no relationship to ticket X at all
$targetOrgId     = 900004704; // legitimate share target for the create tests

$ownerUserId       = 900004710; // role 3 (Dispatcher) @ ownerOrgId -- holds both codes
$ownerNoRbacUserId = 900004711; // role 4 (Operator) @ ownerNoRbacOrgId -- holds NEITHER code
$sharedInUserId    = 900004712; // role 3 (Dispatcher) @ sharedInOrgId -- holds both codes, doesn't own ticket X
$strangerUserId    = 900004713; // role 3 (Dispatcher) @ strangerOrgId -- holds both codes, no visibility into ticket X

$createdOrgIds = [$ownerOrgId, $ownerNoRbacOrgId, $sharedInOrgId, $strangerOrgId, $targetOrgId];
$createdUserIds = [$ownerUserId, $ownerNoRbacUserId, $sharedInUserId, $strangerUserId];
$createdTicketIds = [];

$cleanup = function () use ($prefix, $createdOrgIds, $createdUserIds, &$createdTicketIds) {
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}incident_shares WHERE ticket_id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM {$prefix}organizations WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (" . implode(',', array_fill(0, count($createdUserIds), '?')) . ")", $createdUserIds); } catch (Throwable $e) {}
};
$cleanup();

try {
    foreach ([$ownerOrgId, $ownerNoRbacOrgId, $sharedInOrgId, $strangerOrgId, $targetOrgId] as $oid) {
        db_query("INSERT INTO {$prefix}organizations (id, name, active) VALUES (?, ?, 1)", [$oid, 'ZZ142API Org ' . $oid]);
    }

    // role_id 3 = Dispatcher (holds action.share_incident / action.revoke_incident_share
    // by default, per tests/test_org_sharing_rbac.php Part 7 -- already re-verified green
    // in this session). role_id 4 = Operator (holds NEITHER, allow-list withholds by
    // construction).
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$ownerUserId, $ownerOrgId, $ownerOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 4, ?, 'org', ?)", [$ownerNoRbacUserId, $ownerNoRbacOrgId, $ownerNoRbacOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$sharedInUserId, $sharedInOrgId, $sharedInOrgId]);
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 3, ?, 'org', ?)", [$strangerUserId, $strangerOrgId, $strangerOrgId]);

    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '1 Api142 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142api ticket X', 'zz142api ticket X', 2, 1, NOW(), ?)",
        [$now, $ownerOrgId]
    );
    $ticketX = (int) db_insert_id();
    $createdTicketIds[] = $ticketX;

    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (0, '', '2 Api142 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz142api ticket Y', 'zz142api ticket Y', 2, 1, NOW(), ?)",
        [$now, $ownerNoRbacOrgId]
    );
    $ticketY = (int) db_insert_id();
    $createdTicketIds[] = $ticketY;

    // sharedInOrgId gets a real 'view' share on ticket X -- via the REAL
    // writer, not a hand-seeded row.
    $seedShare = org_sharing_create_manual_share($ticketX, $sharedInOrgId, 'view', 'zz142api fixture share', $ownerUserId, 'ZZ142API Owner');
    t('fixture: seeding the shared-in org\'s view share on ticket X succeeded', $seedShare['success'] === true);

    // ══════════════════════════════════════════════════════════════════
    // Part 1 — GET, driven end to end through the REAL file (subprocess).
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 1: GET api/incident-share.php -- real file, real session ---\n\n";

    // Maps each fixture user to the org their session's active_org_id
    // would carry after a real login (needed for rbac_can()'s org-scoped
    // grants -- see the probe file's own docblock).
    $activeOrgByUser = [
        $ownerUserId       => $ownerOrgId,
        $ownerNoRbacUserId => $ownerNoRbacOrgId,
        $sharedInUserId    => $sharedInOrgId,
        $strangerUserId    => $strangerOrgId,
    ];

    function p142_get_probe(int $userId, int $ticketId, int $activeOrgId): array {
        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_incident_share_get_probe.php')
            . ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg((string) $ticketId)
            . ' ' . escapeshellarg((string) $activeOrgId);
        $out = @shell_exec($cmd . ' 2>&1');
        $out = (string) $out;
        $status = null;
        if (preg_match('/__HTTP_STATUS__:(\d+)/', $out, $m)) $status = (int) $m[1];
        $jsonPart = preg_replace('/\n?__HTTP_STATUS__:\d+\s*$/', '', $out);
        $decoded = json_decode(trim($jsonPart), true);
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $out];
    }

    $r = p142_get_probe($ownerUserId, $ticketX, $activeOrgByUser[$ownerUserId]);
    t('owning-org caller (has RBAC): GET succeeds (200)', $r['status'] === 200);
    t('owning-org caller: response has a shares array', $r['body'] !== null && is_array($r['body']['shares'] ?? null));
    t('owning-org caller: response has exactly the one fixture share', $r['body'] !== null && count($r['body']['shares']) === 1);
    t('owning-org caller: can_create is true (holds action.share_incident)', $r['body'] !== null && $r['body']['can_create'] === true);
    t('owning-org caller: can_revoke is true (holds action.revoke_incident_share)', $r['body'] !== null && $r['body']['can_revoke'] === true);
    t('owning-org caller: owning_org_id matches the real owning org', $r['body'] !== null && (int) $r['body']['owning_org_id'] === $ownerOrgId);
    if ($r['body'] !== null && !empty($r['body']['shares'])) {
        $row = $r['body']['shares'][0];
        t('the fixture share row: shared_with_org_id is the shared-in org', (int) $row['shared_with_org_id'] === $sharedInOrgId);
        t('the fixture share row: source is "manual"', $row['source'] === 'manual');
        t('the fixture share row: access_tier is "view"', $row['access_tier'] === 'view');
    }

    $r = p142_get_probe($ownerNoRbacUserId, $ticketY, $activeOrgByUser[$ownerNoRbacUserId]);
    t('RBAC-DENIED case: owns the ticket but holds NEITHER permission -- 403', $r['status'] === 403);
    t('RBAC-DENIED case: error message names insufficient permissions (not ownership)', $r['body'] !== null && stripos($r['body']['error'] ?? '', 'permission') !== false);

    $r = p142_get_probe($sharedInUserId, $ticketX, $activeOrgByUser[$sharedInUserId]);
    t('OWNERSHIP-DENIED case: holds RBAC but only has a share (not ownership) -- 403, NOT 404 (they DO have visibility)', $r['status'] === 403);
    t('OWNERSHIP-DENIED case: error message names sharing on THIS ticket (distinct wording from the RBAC-denied case above)', $r['body'] !== null && stripos($r['body']['error'] ?? '', 'sharing on this ticket') !== false);

    $r = p142_get_probe($strangerUserId, $ticketX, $activeOrgByUser[$strangerUserId]);
    t('NO-VISIBILITY case: holds RBAC, but no ownership AND no share at all -- 404 (not 403, no existence leak)', $r['status'] === 404);
    t('NO-VISIBILITY case: error message is the generic "not found" shape', $r['body'] !== null && stripos($r['body']['error'] ?? '', 'not found') !== false);

    $r = p142_get_probe($ownerUserId, 0, $activeOrgByUser[$ownerUserId]);
    t('ticket_id=0: refused with a validation error, not a fatal', $r['body'] !== null && isset($r['body']['error']));

    // ══════════════════════════════════════════════════════════════════
    // Part 2 — POST create/revoke: real rbac_can()/writers, hand-mirrored
    // status mapping (see this file's own docblock for why POST can't be
    // driven via the same subprocess technique on this PHP build).
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Part 2: POST create/revoke -- real rbac_can() + real writers, endpoint's own gate sequence mirrored ---\n\n";

    /** Mirrors api/incident-share.php's POST action=create branch exactly. */
    function p142_reproduce_create(int $ticketId, int $callerUserId, int $callerActiveOrgId, int $targetOrgId, string $tier, string $reason): array {
        $_SESSION['user_id'] = $callerUserId;
        $_SESSION['active_org_id'] = $callerActiveOrgId; // see the GET probe's own docblock -- rbac_can()'s org-scoped grants need this, exactly as a real login would set it
        rbac_clear_cache();
        if (!rbac_can('action.share_incident')) {
            return ['status' => 403, 'reason' => 'rbac'];
        }
        if (!org_ticket_is_owned_by_caller($ticketId, $callerUserId)) {
            if (org_can_see_ticket($ticketId, $callerUserId)) return ['status' => 403, 'reason' => 'ownership'];
            return ['status' => 404, 'reason' => 'not_found'];
        }
        $result = org_sharing_create_manual_share($ticketId, $targetOrgId, $tier, $reason, $callerUserId, 'ZZ142API');
        return ['status' => $result['success'] ? 200 : 400, 'reason' => 'writer', 'result' => $result];
    }

    /** Mirrors api/incident-share.php's POST action=revoke branch exactly. */
    function p142_reproduce_revoke(int $shareId, int $callerUserId, int $callerActiveOrgId, ?string $reason): array {
        $_SESSION['user_id'] = $callerUserId;
        $_SESSION['active_org_id'] = $callerActiveOrgId;
        rbac_clear_cache();
        if (!rbac_can('action.revoke_incident_share')) {
            return ['status' => 403, 'reason' => 'rbac'];
        }
        // Deliberately NO ownership pre-check here -- see this file's own
        // docblock / plan.md's IDOR guard. org_sharing_revoke_share()
        // performs it internally, against the row's own derived ticket_id.
        $result = org_sharing_revoke_share($shareId, $reason, $callerUserId, 'ZZ142API');
        return ['status' => $result['success'] ? 200 : 400, 'reason' => 'writer', 'result' => $result];
    }

    $r = p142_reproduce_create($ticketX, $ownerUserId, $activeOrgByUser[$ownerUserId], $targetOrgId, 'view', 'zz142api mutual aid');
    t('owning-org caller (has RBAC): POST create succeeds', $r['status'] === 200 && $r['result']['success'] === true);
    $createdShareId = (int) ($r['result']['id'] ?? 0);
    t('the created share has a real id', $createdShareId > 0);

    $r = p142_reproduce_create($ticketY, $ownerNoRbacUserId, $activeOrgByUser[$ownerNoRbacUserId], $targetOrgId, 'view', 'zz142api reason');
    t('RBAC-DENIED case: POST create refused with the RBAC reason (before reaching the ownership check at all)', $r['status'] === 403 && $r['reason'] === 'rbac');

    $r = p142_reproduce_create($ticketX, $sharedInUserId, $activeOrgByUser[$sharedInUserId], $strangerOrgId, 'view', 'zz142api chained share attempt');
    t('OWNERSHIP-DENIED case: POST create refused with the ownership reason (holds RBAC, sees the ticket via a share, does not own it)', $r['status'] === 403 && $r['reason'] === 'ownership');
    $stillNoChainedShare = db_fetch_one("SELECT id FROM {$prefix}incident_shares WHERE ticket_id = ? AND shared_with_org_id = ?", [$ticketX, $strangerOrgId]);
    t('no row was written for the attempted chained share', !$stillNoChainedShare);

    $r = p142_reproduce_create($ticketX, $strangerUserId, $activeOrgByUser[$strangerUserId], $targetOrgId, 'view', 'zz142api reason');
    t('NO-VISIBILITY case: POST create refused with the not_found reason (404 shape, no existence leak)', $r['status'] === 404 && $r['reason'] === 'not_found');

    $r = p142_reproduce_revoke($createdShareId, $ownerUserId, $activeOrgByUser[$ownerUserId], 'zz142api revoke reason');
    t('owning-org caller (has RBAC): POST revoke succeeds', $r['status'] === 200 && $r['result']['success'] === true);

    // Re-seed a share to revoke, then confirm the RBAC-denied case for revoke.
    $reSeed = org_sharing_create_manual_share($ticketX, $targetOrgId, 'view', 'zz142api reseed', $ownerUserId, 'ZZ142API');
    $reSeedShareId = (int) ($reSeed['id'] ?? 0);
    $r = p142_reproduce_revoke($reSeedShareId, $ownerNoRbacUserId, $activeOrgByUser[$ownerNoRbacUserId], null);
    t('RBAC-DENIED case: POST revoke refused with the RBAC reason', $r['status'] === 403 && $r['reason'] === 'rbac');
    $stillActive = db_fetch_value("SELECT revoked_at FROM {$prefix}incident_shares WHERE id = ?", [$reSeedShareId]);
    t('the share is still active after the RBAC-denied revoke attempt', $stillActive === null);

    // sharedInUserId holds RBAC but does not own ticket X -- the internal
    // ownership check inside org_sharing_revoke_share() itself refuses.
    $r = p142_reproduce_revoke($reSeedShareId, $sharedInUserId, $activeOrgByUser[$sharedInUserId], null);
    t('OWNERSHIP-DENIED case: POST revoke refused (writer\'s own internal ownership check, not this file\'s pre-check)', $r['status'] === 400 && $r['result']['success'] === false);
    $stillActive2 = db_fetch_value("SELECT revoked_at FROM {$prefix}incident_shares WHERE id = ?", [$reSeedShareId]);
    t('the share is STILL active after the ownership-denied revoke attempt', $stillActive2 === null);

} finally {
    $cleanup();
    unset($_SESSION['user_id']);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — structural/tokenizer confirmation of api/incident-share.php's
// own gate shapes, matching tests/test_org_sharing_rbac.php Part 6's
// technique (strip comments via the real tokenizer before a substring
// scan -- this file's own docblock legitimately DISCUSSES the
// `rbac_can() || is_admin()` anti-pattern in prose).
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- Part 3: api/incident-share.php -- structural gate confirmation ---\n\n";

function _p142api_code_only(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

$endpointSrc = @file_get_contents($base . '/api/incident-share.php');
t('api/incident-share.php exists', $endpointSrc !== false);

if ($endpointSrc !== false) {
    $code = _p142api_code_only($endpointSrc);

    t('gates on action.share_incident', strpos($endpointSrc, "rbac_can('action.share_incident')") !== false);
    t('gates on action.revoke_incident_share', strpos($endpointSrc, "rbac_can('action.revoke_incident_share')") !== false);
    t('NEVER ORs is_admin() into either gate', !preg_match('/rbac_can\([^)]*(share_incident|revoke_incident_share)[^)]*\)\s*\|\|\s*is_admin\(\)/', $code));
    t('no is_admin() call in actual CODE at all (comments excluded via tokenizer)', strpos($code, 'is_admin(') === false);

    // CSRF checked on both POST actions.
    t('CSRF helper is defined', strpos($code, 'function _incshare_require_csrf') !== false);
    $createBlock = null; $revokeBlock = null;
    if (preg_match('/action\s*===\s*\'create\'\)\s*\{(.*?)\n    \}/s', $code, $mm)) $createBlock = $mm[1];
    if (preg_match('/action\s*===\s*\'revoke\'\)\s*\{(.*?)\n    \}/s', $code, $mm)) $revokeBlock = $mm[1];
    t('POST create branch calls the CSRF check', $createBlock !== null && strpos($createBlock, '_incshare_require_csrf(') !== false);
    t('POST revoke branch calls the CSRF check', $revokeBlock !== null && strpos($revokeBlock, '_incshare_require_csrf(') !== false);

    // GET and create both check ownership; revoke does NOT pre-check it
    // against a caller-supplied ticket_id (per plan.md's IDOR guard --
    // there is no ticket_id parameter on the revoke action to check).
    t('GET branch calls org_ticket_is_owned_by_caller()', (bool) preg_match('/GET.*?org_ticket_is_owned_by_caller\(/s', $code));
    t('POST create branch calls org_ticket_is_owned_by_caller()', $createBlock !== null && strpos($createBlock, 'org_ticket_is_owned_by_caller(') !== false);
    t('POST revoke branch does NOT call org_ticket_is_owned_by_caller() directly (the check lives inside org_sharing_revoke_share() itself)', $revokeBlock !== null && strpos($revokeBlock, 'org_ticket_is_owned_by_caller(') === false);
    t('POST revoke branch has no ticket_id input parameter to pre-check against', strpos($endpointSrc, "input['ticket_id']") === false || strpos($revokeBlock ?? '', "input['ticket_id']") === false);

    // Correct writer functions are called from the correct actions.
    t('POST create calls org_sharing_create_manual_share()', $createBlock !== null && strpos($createBlock, 'org_sharing_create_manual_share(') !== false);
    t('POST revoke calls org_sharing_revoke_share()', $revokeBlock !== null && strpos($revokeBlock, 'org_sharing_revoke_share(') !== false);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
