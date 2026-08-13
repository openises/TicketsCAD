<?php
/**
 * NewUI v4.0 — Public incident board data API (Phase 138).
 *
 * PUBLIC, UNAUTHENTICATED BY DESIGN (specs/security/constitution.md rule 2):
 * this endpoint is meant to be fetched by anonymous browsers — an agency's
 * public website or a lobby display holds no credential and none is issued.
 * Every field returned here has already passed through eligibility (never-
 * publish type / excluded group / publish delay / Security Label broadcast
 * block) and redaction (Security Label address/marker rules capped further
 * by the board's own precision setting) BEFORE this file ever sees it — see
 * inc/public-board.php. Nothing this file emits requires privilege to view;
 * that is the entire design constraint, not a gap.
 *
 * GET /api/public-board.php               — the shared (no-org) board.
 *                                            Gated by the public_board_enabled
 *                                            setting (off by default).
 * GET /api/public-board.php?org=<slug>     — a single org's board. Gated
 *                                            ONLY by that org's own
 *                                            public_board_enabled/slug —
 *                                            independent of the global
 *                                            switch above (plan.md §1b).
 *                                            An unknown or disabled slug
 *                                            both 404 identically — never
 *                                            distinguish "wrong slug" from
 *                                            "right slug, disabled".
 */

require_once __DIR__ . '/../inc/api_guard.php';
api_guard_install();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rate-limit.php';
require_once __DIR__ . '/../inc/client-ip.php';
require_once __DIR__ . '/../inc/security-labels.php';
require_once __DIR__ . '/../inc/public-board.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

// Step 1 — noindex, unconditionally, before anything else. Protects the
// JSON response itself (the HTML wrapper page carries its own <meta>
// robots tag — belt-and-suspenders, plan.md §9).
header('X-Robots-Tag: noindex, nofollow');

function _pb_public_fail(int $status, string $message): void
{
    global $prevDisplay;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    ini_set('display_errors', $prevDisplay);
    exit;
}

// Step 2 — rate limit. This IS the entire rate-limiting implementation —
// rate_limit_ok() already fails open on a counter-write failure per its own
// docblock (inc/rate-limit.php), exactly spec.md's fail-open requirement.
// Operational caveat (security review finding #8): client_ip() only trusts
// XFF/X-Real-IP/CF-Connecting-IP when REMOTE_ADDR is a configured trusted
// proxy — an install fronting this board with an unconfigured reverse
// proxy collapses every visitor into one shared bucket. Documented in
// docs/PUBLIC-INCIDENT-BOARD.md (Section I, not yet written this stage).
$rlRequestsRaw = get_variable('public_board_rate_limit_requests');
$rlWindowRaw   = get_variable('public_board_rate_limit_window_secs');
$rlRequests = ($rlRequestsRaw !== false && (int) $rlRequestsRaw > 0) ? (int) $rlRequestsRaw : 30;
$rlWindow   = ($rlWindowRaw !== false && (int) $rlWindowRaw > 0) ? (int) $rlWindowRaw : 60;

if (!rate_limit_ok('public_board:' . client_ip(), $rlRequests, $rlWindow)) {
    rate_limit_reject($rlWindow);
}

// Step 3 — resolve scope: shared board vs. a single org's board.
$orgSlug = isset($_GET['org']) ? trim((string) $_GET['org']) : '';
$orgId   = null;
$orgName = null;

if ($orgSlug !== '') {
    try {
        // Security review finding #2 (2026-08-13): a deactivated org
        // (organizations.active = 0 — the flag the rest of the app treats
        // as "this org is gone/archived", e.g. login.php's own org
        // queries) used to keep serving its board indefinitely once
        // public_board_enabled had been set to 1, with nothing anywhere
        // in the app auto-clearing it and health_check_public_board()
        // never checking org.active either. Deactivating an org is
        // exactly the moment its public board should stop resolving.
        $org = db_fetch_one(
            "SELECT `id`, `name` FROM " . db_table('organizations') . "
              WHERE `public_board_slug` = ? AND `public_board_enabled` = 1 AND `active` = 1 LIMIT 1",
            [$orgSlug]
        );
    } catch (Exception $e) {
        $org = null;
    }
    if (!$org) {
        // Generic 404 — never differentiate "no such slug" from "that slug
        // exists but the org hasn't enabled its board" (plan.md §5 step 3).
        _pb_public_fail(404, 'Not found.');
    }
    $orgId   = (int) $org['id'];
    $orgName = (string) $org['name'];
} else {
    $enabled = get_variable('public_board_enabled');
    if ($enabled !== '1') {
        _pb_public_fail(503, 'The public incident board is not enabled.');
    }
}

// Step 4 — fetch + redact.
$precision = (string) (get_variable('public_board_address_precision') ?: 'block');
if (!in_array($precision, ['exact', 'block', 'city', 'hidden'], true)) {
    $precision = 'block';
}

$eligible = pb_eligible_incidents($orgId);

$incidents = [];
$maxUpdatedTs = 0;
foreach ($eligible as $row) {
    $incidents[] = pb_build_public_record($row['ticket'], $row['label'], $precision, true);
    $u = $row['ticket']['updated'] ?? null;
    if ($u) {
        $ts = strtotime((string) $u);
        if ($ts !== false && $ts > $maxUpdatedTs) $maxUpdatedTs = $ts;
    }
}

// Step 5 — ETag, computed so a config change (precision, excluded groups,
// default delay) invalidates the cache even if no incident itself changed.
$configVersion = sha1(
    $precision . '|'
    . (string) get_variable('public_board_excluded_groups') . '|'
    . (string) get_variable('public_board_default_delay_secs')
);
$etag = '"' . sha1($maxUpdatedTs . ':' . count($incidents) . ':' . $configVersion) . '"';
header('ETag: ' . $etag);
// `public` (not `private`) is deliberate (security review finding #6) —
// this response is, by design, already fully redacted for an anonymous
// audience, so a shared/intermediate cache holding a short-lived copy is
// an accepted embeddability tradeoff, bounded by the 15s ceiling and the
// config-version marker above.
header('Cache-Control: public, max-age=15');

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);
    ini_set('display_errors', $prevDisplay);
    exit;
}

// Step 6/7 — timestamps + envelope.
$titleOrg = $orgName;
if ($titleOrg === null) {
    $titleOrg = (string) (get_variable('org_name') ?: 'Tickets CAD');
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', $prevDisplay);
echo json_encode([
    'board' => [
        'title'           => $titleOrg . ' — Active Incidents',
        // "Now" in UTC directly — no strtotime() round-trip needed (that
        // path is for converting a DB LOCAL-time string, not the current
        // instant, which gmdate() already gives unambiguously).
        'generated'       => gmdate('Y-m-d\TH:i:s\Z'),
        'precision_level' => $precision,
        'org_scoped'      => $orgId !== null,
        'count'           => count($incidents),
    ],
    'incidents' => $incidents,
], JSON_UNESCAPED_UNICODE);
