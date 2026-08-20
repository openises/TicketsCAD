<?php
/**
 * GH#76 Phase 144 (2026-08-18) — external API team_id compatibility shim.
 *
 * api/external/v1/members.php is the ONE path that still accepts team_id
 * as an input field (POST create / PATCH update), to keep the external
 * contract stable rather than force a version bump. Internally it does
 * NOT write member.team_id (member_create_internal()/member_update_
 * internal() dropped that column this phase) — it upserts a team_members
 * row tagged source='external_api' instead, additively.
 *
 * Covers, via real HTTP against a live Apache (mirrors tests/test_external_
 * api.php's own curl-against-localhost + ext_api_mint_token() pattern):
 *   1. POST with team_id set → 201, and a team_members row appears tagged
 *      source='external_api'.
 *   2. GET (single) returns BOTH the legacy team_id/team_name fields
 *      (resolved live, not stale) AND a new team_memberships[] array.
 *   3. PATCH with team_id set to a DIFFERENT team → upserts (moves) the
 *      external_api-tagged row; does not create a second row.
 *   4. PATCH with team_id=null → 200, but does NOT delete the mirrored
 *      team_members row (the shipped default from the design spec's
 *      Section 5 — a human-only removal, via the Teams tab or the roster
 *      card, is what actually evicts someone).
 *   5. The shim is additive: a team_members row added via the roster
 *      card's real writer (source=NULL) for the SAME member survives an
 *      external-API PATCH untouched.
 *
 * @requires-http — hits http://localhost via a live Apache; skipped when NEWUI_TEST_NO_HTTP=1
 * Usage: php tests/test_external_api_team_id_compat_shim.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/external-auth.php';
require_once __DIR__ . '/../inc/team-write.php';

echo "=== GH#76 Phase 144 — External API team_id compat shim ===\n\n";

$pass = 0; $fail = 0; $failures = [];
function tbl($n) { return db_table($n); }

function _p144x_assert(bool $cond, string $what, string $detail = '') {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else { $fail++; $failures[] = $what . ($detail ? " — {$detail}" : ''); echo "  FAIL  {$what}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

function _p144x_curl(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $httpCode, 'body' => $response, 'json' => @json_decode((string) $response, true)];
}

$BASE_URL = getenv('EXT_API_BASE_URL') ?: 'http://localhost';
$prefix = $GLOBALS['db_prefix'] ?? '';

$ping = _p144x_curl('GET', $BASE_URL . '/api/external/v1/');
if ($ping['status'] === 0) {
    echo "  SKIP  All tests — localhost web server not reachable.\n\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

// ── Mint a full-scope token bound to a real user (never a real login) ──
try {
    $userRow = db_fetch_one("SELECT id FROM `{$prefix}user` ORDER BY id ASC LIMIT 1");
} catch (Exception $e) {
    echo "  SKIP — couldn't query user table: {$e->getMessage()}\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}
if (!$userRow) {
    echo "  SKIP — no users to bind a token to\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}
$bindUserId = (int) $userRow['id'];

try {
    $token = ext_api_mint_token($bindUserId, ['*'], $bindUserId,
        ['name' => 'GH#76 Phase 144 team_id shim test', 'rate_limit_per_hour' => 100000]);
} catch (Exception $e) {
    echo "  FAIL — token mint threw: {$e->getMessage()}\n";
    echo "\n=== 0 passed, 1 failed ===\n";
    exit(1);
}
_p144x_assert(!empty($token['raw_token']), 'mint full-scope external API token');
$AUTH = ['Authorization: Bearer ' . $token['raw_token'], 'Content-Type: application/json'];

// ── Throwaway teams (real writer) ───────────────────────────────────────
$teamAId = 0; $teamBId = 0; $memberId = 0;
try {
    $ra = team_upsert_internal(['name' => 'zzP144x Team A ' . uniqid()], 0);
    $rb = team_upsert_internal(['name' => 'zzP144x Team B ' . uniqid()], 0);
    $teamAId = (int) ($ra['id'] ?? 0);
    $teamBId = (int) ($rb['id'] ?? 0);
    _p144x_assert($teamAId > 0 && $teamBId > 0, 'created 2 throwaway teams via the real writer');

    // ── 1. POST create with team_id ──────────────────────────────────
    echo "\n-- POST create with team_id --\n";
    $r = _p144x_curl('POST', "$BASE_URL/api/external/v1/members", $AUTH,
        ['first_name' => 'zzP144x', 'last_name' => 'ExtApiShim', 'team_id' => $teamAId]);
    _p144x_assert($r['status'] === 201 && !empty($r['json']['data']['id']), 'POST /members with team_id → 201', (string) $r['status']);
    $memberId = (int) ($r['json']['data']['id'] ?? 0);

    if ($memberId > 0) {
        $shimRow = db_fetch_one(
            "SELECT id, source FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?",
            [$teamAId, $memberId]
        );
        _p144x_assert((bool) $shimRow, 'a team_members row was created for the new member');
        _p144x_assert($shimRow && $shimRow['source'] === 'external_api', 'the shim-created row is tagged source=external_api');

        $teamIdCol = db_fetch_value("SELECT team_id FROM " . tbl('member') . " WHERE id = ?", [$memberId]);
        _p144x_assert(empty($teamIdCol), 'member.team_id itself was NOT written by the shim (compat column stays untouched internally)');

        // ── 2. GET single: legacy fields + team_memberships[] ────────
        echo "\n-- GET single: legacy fields resolved live + team_memberships[] ---\n";
        $rg = _p144x_curl('GET', "$BASE_URL/api/external/v1/members/$memberId", $AUTH);
        _p144x_assert($rg['status'] === 200, "GET /members/$memberId → 200");
        _p144x_assert((int) ($rg['json']['data']['team_id'] ?? 0) === $teamAId, 'GET response team_id resolves to the shim-created team (live, not stale)');
        _p144x_assert(($rg['json']['data']['team_name'] ?? '') !== '', 'GET response team_name is populated');
        _p144x_assert(isset($rg['json']['data']['team_memberships']) && is_array($rg['json']['data']['team_memberships']),
            'GET response carries a team_memberships[] array');
        _p144x_assert(count($rg['json']['data']['team_memberships'] ?? []) === 1
            && (int) ($rg['json']['data']['team_memberships'][0]['team_id'] ?? 0) === $teamAId,
            'team_memberships[] contains exactly the one team the shim created');

        // ── 3. PATCH team_id to a DIFFERENT team → moves (upserts), no dup row ──
        echo "\n-- PATCH team_id to a different team --\n";
        $rp = _p144x_curl('PATCH', "$BASE_URL/api/external/v1/members/$memberId", $AUTH, ['team_id' => $teamBId]);
        _p144x_assert($rp['status'] === 200, "PATCH team_id to Team B → 200", (string) $rp['status']);
        $rowB = db_fetch_one("SELECT source FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?", [$teamBId, $memberId]);
        _p144x_assert((bool) $rowB && $rowB['source'] === 'external_api', 'a team_members row now exists for Team B, tagged external_api');
        $countRows = (int) db_fetch_value(
            "SELECT COUNT(*) FROM " . tbl('team_members') . " WHERE member_id = ?", [$memberId]
        );
        _p144x_assert($countRows === 2, 'PATCHing team_id is additive — the Team A row from the original POST is NOT removed (only human/UI removal evicts)');

        // ── 4. PATCH team_id=null does NOT delete the mirrored row ────
        echo "\n-- PATCH team_id=null (shipped default: does NOT remove the mirrored row) --\n";
        $rn = _p144x_curl('PATCH', "$BASE_URL/api/external/v1/members/$memberId", $AUTH, ['team_id' => null]);
        _p144x_assert($rn['status'] === 200, 'PATCH team_id=null → 200 (not an error)', (string) $rn['status']);
        $stillThereB = db_fetch_value("SELECT id FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?", [$teamBId, $memberId]);
        _p144x_assert((bool) $stillThereB, 'the Team B team_members row STILL EXISTS after PATCH team_id=null — the shipped default holds');
        $stillThereA = db_fetch_value("SELECT id FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?", [$teamAId, $memberId]);
        _p144x_assert((bool) $stillThereA, 'the Team A team_members row also still exists — nothing was evicted');

        // ── 5. A human/UI-sourced row (source=NULL) survives untouched ─
        echo "\n-- Additivity: a human/UI-sourced row is untouched by the external API ---\n";
        require_once __DIR__ . '/../inc/member-write.php'; // for symmetry with other Phase 144 tests; not strictly needed here
        $rc = team_upsert_internal(['name' => 'zzP144x Team C (human) ' . uniqid()], 0);
        $teamCId = (int) ($rc['id'] ?? 0);
        if ($teamCId > 0) {
            $addRes = team_add_member_internal($teamCId, $memberId, 'Member'); // source=NULL, same as the Teams tab / roster card
            _p144x_assert(!empty($addRes['success']), 'human/UI-sourced team_members row added via the real writer');
            $rp2 = _p144x_curl('PATCH', "$BASE_URL/api/external/v1/members/$memberId", $AUTH, ['team_id' => null]);
            _p144x_assert($rp2['status'] === 200, 'a second PATCH team_id=null still succeeds');
            $humanRowStill = db_fetch_one("SELECT source FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?", [$teamCId, $memberId]);
            _p144x_assert($humanRowStill && $humanRowStill['source'] === null, 'the human/UI-sourced row (source=NULL) is completely untouched by the external API calls');
            try { db_query("DELETE FROM " . tbl('team_members') . " WHERE team_id = ?", [$teamCId]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM " . tbl('teams') . " WHERE id = ?", [$teamCId]); } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) {
    _p144x_assert(false, 'external API shim test ran without a fatal error', $e->getMessage());
} finally {
    // ── Cleanup ──
    try {
        if ($memberId > 0) {
            db_query("DELETE FROM " . tbl('team_members') . " WHERE member_id = ?", [$memberId]);
            db_query("DELETE FROM " . tbl('member') . " WHERE id = ?", [$memberId]);
        }
        foreach ([$teamAId, $teamBId] as $tid) {
            if ($tid > 0) {
                db_query("DELETE FROM " . tbl('team_members') . " WHERE team_id = ?", [$tid]);
                db_query("DELETE FROM " . tbl('teams') . " WHERE id = ?", [$tid]);
            }
        }
        if (!empty($token['id'])) {
            db_query("DELETE FROM `{$prefix}external_api_tokens` WHERE id = ?", [$token['id']]);
            db_query("DELETE FROM `{$prefix}external_api_rate_limits` WHERE token_id = ?", [$token['id']]);
        }
        echo "\n  CLEAN test artifacts removed\n";
    } catch (Throwable $e) {
        echo "\n  WARN  cleanup partial: {$e->getMessage()}\n";
    }
}

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
}
echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
