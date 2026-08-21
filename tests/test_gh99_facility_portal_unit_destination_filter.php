<?php
/**
 * GH#99 (2026-08-20) — facility-portal unit-destination confinement,
 * adversarial. Reported the same night Phase 145/GH#90 shipped: a
 * multi-unit incident with units transporting to DIFFERENT facilities
 * showed EVERY unit on the call to EVERY facility with any leg on the
 * ticket — including a diverted unit's `en_route_at`/`arrived_at`
 * (assigns.u2fenr/u2farr, the FACILITY-leg timestamps GH#64 shipped),
 * which read as "coming to you" regardless of where the unit was
 * actually headed. See inc/facility-scope.php's
 * facility_portal_visible_units() docblock for the fix and its
 * origin-vs-receiving reasoning.
 *
 * This drives the REAL api/facility-portal.php endpoint over REAL HTTP
 * (php -S rooted at THIS checkout, matching tests/_pb_test_server.php's
 * established pattern — never a hand-simulated router), through a REAL
 * facility login (login.php's genuine CSRF+cookie+session flow, matching
 * tools/test_api_endpoints.php's established login helper), against
 * fixtures created through plain INSERTs shaped exactly like a real
 * dispatch would produce them (an open, unresolved `assigns` row per
 * unit — never a hand-seeded "ideal" row) — reproducing the reporter's
 * exact two-unit/two-destination scenario:
 *
 * NOT @requires-http: this spins up its OWN local PHP server (same
 * self-contained pattern as tests/_pb_test_server.php /
 * tests/test_public_board_org_scope.php, which are likewise unmarked) —
 * it never touches a live Apache/localhost install, so it runs fine
 * under NEWUI_TEST_NO_HTTP=1 / in CI. It DOES need a reachable
 * MySQL/MariaDB, same as every other DB-backed test.
 *
 *   Ticket: receiving facility = Facility A (MetroHealth).
 *   MEDIC 01 — assigns.rec_facility_id NULL → inherits ticket.rec_facility (A).
 *   MEDIC 04 — assigns.rec_facility_id = Facility B (Fire Station 1) — diverted.
 *
 * Sections:
 *   A. Facility A's real session sees ONLY MEDIC 01 (never MEDIC 04, never
 *      its facility-leg timestamps) for the receiving-only leg.
 *   B. Facility B's real session — reached via the SAME ticket through its
 *      own per-unit leg (Phase 116) — sees ONLY MEDIC 04, never MEDIC 01.
 *      Proves the filter isn't merely "hide everything," and that the two
 *      facilities' views are genuinely disjoint on the same incident.
 *   C. Origin-leg control: a THIRD facility that is the ticket's origin
 *      (ticket.facility, e.g. "the incident is physically at this
 *      facility") sees BOTH units regardless of destination — proving the
 *      fix didn't regress the pre-existing, legitimate "who responded to
 *      my own incident" visibility into a false negative.
 *   D. Direct function-level checks of facility_portal_visible_units()
 *      itself, isolating the origin/receiving branches independently of
 *      HTTP/session plumbing.
 *
 * @requires-db
 * Usage: php tests/test_gh99_facility_portal_unit_destination_filter.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/facility-scope.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#99 — facility-portal unit-destination confinement (adversarial) ===\n\n";

// The ephemeral-server sections (A-C) need proc_open + curl to spin up and
// drive our OWN local server — genuinely absent on some environments, as
// opposed to "no web server exists" (which NEWUI_TEST_NO_HTTP=1 signals and
// does not apply here — see the docblock's NOT @requires-http note). The
// direct function-level checks (Section D) never depend on either, so they
// still run and still count even when this narrower capability is missing.
$httpCapable = function_exists('proc_open') && function_exists('curl_init');

$prefix = $GLOBALS['db_prefix'] ?? '';

// Resolved by NAME, never a hardcoded id — same reasoning as
// tests/test_facility_scope_confinement.php's own comment on this.
$facilityRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Facility' LIMIT 1");
if ($facilityRoleId <= 0) {
    echo "SKIP: Facility role not found — run sql/run_00_rbac.php first\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// ── Fixture ids (zz99 range, distinct from other phases' zz-fixture pools) ──
$facAId  = 900199001; // receiving facility — MetroHealth analogue
$facBId  = 900199002; // diversion target — Fire Station 1 analogue
$facCId  = 900199003; // origin facility (control) — the incident's own site
$respM01 = 900199011; // MEDIC 01 — inherits ticket-level rec_facility (A)
$respM04 = 900199012; // MEDIC 04 — diverted to B
$userAId = 900199021;
$userBId = 900199022;
$userCId = 900199023;
$ticketReceivingId = 0;
$ticketOriginId = 0;
$assignIds = [];

$plainPwA = 'Zz99GH99FacilityA!' . mt_rand(1000, 9999);
$plainPwB = 'Zz99GH99FacilityB!' . mt_rand(1000, 9999);
$plainPwC = 'Zz99GH99FacilityC!' . mt_rand(1000, 9999);

$cleanup = function () use ($prefix, $facAId, $facBId, $facCId, $respM01, $respM04, $userAId, $userBId, $userCId, &$ticketReceivingId, &$ticketOriginId, &$assignIds) {
    foreach ($assignIds as $id) { try { db_query("DELETE FROM {$prefix}assigns WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    if ($ticketReceivingId) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$ticketReceivingId]); } catch (Throwable $e) {} }
    if ($ticketOriginId) { try { db_query("DELETE FROM {$prefix}ticket WHERE id = ?", [$ticketOriginId]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}responder WHERE id IN (?, ?)", [$respM01, $respM04]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?, ?)", [$userAId, $userBId, $userCId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user WHERE id IN (?, ?, ?)", [$userAId, $userBId, $userCId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}facilities WHERE id IN (?, ?, ?)", [$facAId, $facBId, $facCId]); } catch (Throwable $e) {}
};
$cleanup();

// ── Ephemeral php -S server + HTTP helpers (mirrors tests/_pb_test_server.php) ──
function gh99_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function gh99_start_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = gh99_free_port();
    if ($port === null) return null;

    $tmpdir = sys_get_temp_dir() . '/tcad-gh99-' . getmypid() . '-' . mt_rand();
    if (!@mkdir($tmpdir, 0777, true) && !is_dir($tmpdir)) return null;
    $logdir = $tmpdir . '/logs';
    @mkdir($logdir, 0777, true);

    $docroot = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
    $env = array_merge($_ENV ?: [], getenv() ?: []);

    // Deliberately do NOT override session.save_path here — pointing it at
    // an ephemeral dir under a Windows short-name (8.3, "~1") path broke
    // session_start() silently truncating at the "~1" boundary during
    // development of this test. The default php.ini save_path (already a
    // real, writable temp directory on every environment this suite runs
    // on) works correctly; there's nothing test-specific to isolate here
    // since each test run uses its own randomly-generated session id.
    $desc = [1 => ['file', $logdir . '/out.log', 'a'], 2 => ['file', $logdir . '/err.log', 'a']];
    $proc = @proc_open(
        [$bin, '-S', '127.0.0.1:' . $port, '-t', $docroot],
        $desc, $pipes, $docroot, $env
    );
    if (!is_resource($proc)) return null;

    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if (is_resource($c)) { fclose($c); return ['proc' => $proc, 'port' => $port, 'tmpdir' => $tmpdir]; }
        usleep(50000);
    }
    @proc_terminate($proc);
    @proc_close($proc);
    return null;
}

function gh99_stop_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    gh99_rrmdir($srv['tmpdir']);
}

function gh99_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) gh99_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Real login via login.php's own CSRF+cookie+redirect flow (matches
 * tools/test_api_endpoints.php's getAuthCookie() pattern). Returns a
 * cookie-jar path on success, null on failure. These fixture accounts
 * are never TFA-enrolled, so no TOTP branch is needed.
 */
function gh99_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'gh99cookie');

    $ch = curl_init($base . '/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $html = curl_exec($ch);
    curl_close($ch);
    if ($html === false) return null;

    preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string) $html, $m);
    $csrf = $m[1] ?? '';

    $ch = curl_init($base . '/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username'   => $username,
        'password'   => $password,
        'csrf_token' => $csrf,
    ]));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $loginResp = curl_exec($ch);
    $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($loginResp === false) return null;

    if (($loginCode === 302 || $loginCode === 301)) {
        preg_match('/Location:\s*(.+)/i', (string) $loginResp, $locMatch);
        if (!empty($locMatch[1])) {
            $redirectUrl = trim($locMatch[1]);
            if (strpos($redirectUrl, 'http') !== 0) {
                $redirectUrl = $base . '/' . ltrim($redirectUrl, '/');
            }
            $ch = curl_init($redirectUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_exec($ch);
            curl_close($ch);
        }
        return $cookieFile;
    }

    @unlink($cookieFile);
    return null;
}

function gh99_get_json(string $url, string $cookieFile): ?array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false) return null;
    $json = json_decode((string) $body, true);
    return is_array($json) ? $json : null;
}

$srv = null;

try {
    // ══════════════════════════════════════════════════════════════════
    // Fixtures
    // ══════════════════════════════════════════════════════════════════
    db_query("INSERT INTO {$prefix}facilities (id, name, description) VALUES (?, 'ZZ99 MetroHealth (receiving)', 'zz99 fixture')", [$facAId]);
    db_query("INSERT INTO {$prefix}facilities (id, name, description) VALUES (?, 'ZZ99 Fire Station 1 (diversion)', 'zz99 fixture')", [$facBId]);
    db_query("INSERT INTO {$prefix}facilities (id, name, description) VALUES (?, 'ZZ99 Origin Site (control)', 'zz99 fixture')", [$facCId]);
    t('fixture facilities A/B/C created', true);

    db_query(
        "INSERT INTO {$prefix}responder (id, name, handle, un_status_id, description) VALUES (?, 'ZZ99 MEDIC 01', 'MEDIC01', 1, 'zz99 fixture')",
        [$respM01]
    );
    db_query(
        "INSERT INTO {$prefix}responder (id, name, handle, un_status_id, description) VALUES (?, 'ZZ99 MEDIC 04', 'MEDIC04', 1, 'zz99 fixture')",
        [$respM04]
    );
    t('fixture responders MEDIC 01 / MEDIC 04 created', true);

    // Facility-role accounts, real bcrypt passwords, never TFA-enrolled,
    // must_change_password=0 so login completes without an extra hop.
    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, facility_id, must_change_password) VALUES (?, ?, ?, ?, 0)",
        [$userAId, 'zz99-gh99-facA', password_hash($plainPwA, PASSWORD_BCRYPT), $facAId]
    );
    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, facility_id, must_change_password) VALUES (?, ?, ?, ?, 0)",
        [$userBId, 'zz99-gh99-facB', password_hash($plainPwB, PASSWORD_BCRYPT), $facBId]
    );
    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, facility_id, must_change_password) VALUES (?, ?, ?, ?, 0)",
        [$userCId, 'zz99-gh99-facC', password_hash($plainPwC, PASSWORD_BCRYPT), $facCId]
    );
    foreach ([$userAId, $userBId, $userCId] as $uid) {
        db_query(
            "INSERT INTO {$prefix}user_roles (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, ?, NULL, 'global', NULL)",
            [$uid, $facilityRoleId]
        );
    }
    t('fixture facility-role user accounts A/B/C created (real bcrypt passwords, no TFA)', true);

    $now = date('Y-m-d H:i:s');

    // ── The reporter's exact scenario: receiving-only leg at Facility A ──
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`facility`,`rec_facility`)
         VALUES (0, '', '431 ZZ99 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz99 GH#99 receiving ticket', 'zz99 GH#99 receiving ticket', 2, 1, NOW(), 0, ?)",
        [$now, $facAId]
    );
    $ticketReceivingId = (int) db_insert_id();
    t('fixture ticket (receiving-only leg at Facility A) created', $ticketReceivingId > 0);

    // MEDIC 01 — no per-unit override, inherits ticket.rec_facility (A).
    db_query(
        "INSERT INTO {$prefix}assigns (ticket_id, user_id, responder_id, rec_facility_id, clear, u2fenr, u2farr)
         VALUES (?, 1, ?, NULL, NULL, ?, ?)",
        [$ticketReceivingId, $respM01, date('Y-m-d H:i:s', strtotime('-6 minutes')), date('Y-m-d H:i:s', strtotime('-1 minute'))]
    );
    $assignIds[] = (int) db_insert_id();

    // MEDIC 04 — explicitly diverted to Facility B.
    db_query(
        "INSERT INTO {$prefix}assigns (ticket_id, user_id, responder_id, rec_facility_id, clear, u2fenr, u2farr)
         VALUES (?, 1, ?, ?, NULL, ?, ?)",
        [$ticketReceivingId, $respM04, $facBId, date('Y-m-d H:i:s', strtotime('-4 minutes')), null]
    );
    $assignIds[] = (int) db_insert_id();
    t('fixture assigns created — MEDIC 01 inherits A, MEDIC 04 diverted to B', true);

    // ── Origin-leg control ticket at Facility C — same two units, NEITHER
    //    destined to C, proving the origin branch still shows everyone. ──
    db_query(
        "INSERT INTO {$prefix}ticket (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`facility`,`rec_facility`)
         VALUES (0, '', '432 ZZ99 Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz99 GH#99 origin ticket', 'zz99 GH#99 origin ticket', 2, 1, NOW(), ?, 0)",
        [$now, $facCId]
    );
    $ticketOriginId = (int) db_insert_id();
    t('fixture ticket (origin leg at Facility C) created', $ticketOriginId > 0);

    db_query(
        "INSERT INTO {$prefix}assigns (ticket_id, user_id, responder_id, rec_facility_id, clear, u2fenr, u2farr)
         VALUES (?, 1, ?, ?, NULL, NULL, NULL)",
        [$ticketOriginId, $respM01, $facAId]
    );
    $assignIds[] = (int) db_insert_id();
    db_query(
        "INSERT INTO {$prefix}assigns (ticket_id, user_id, responder_id, rec_facility_id, clear, u2fenr, u2farr)
         VALUES (?, 1, ?, ?, NULL, NULL, NULL)",
        [$ticketOriginId, $respM04, $facBId]
    );
    $assignIds[] = (int) db_insert_id();
    t('fixture assigns created for origin-leg control ticket (both units destined elsewhere)', true);

    // ══════════════════════════════════════════════════════════════════
    // Section D — direct function-level checks (isolate the SQL logic
    // from HTTP/session plumbing before trusting the end-to-end result)
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- D. facility_portal_visible_units() direct ---\n\n";

    $unitsForA = facility_portal_visible_units($ticketReceivingId, $facAId, 0, $facAId);
    $namesA = array_column($unitsForA, 'responder_name');
    t('Facility A (receiving-only): sees exactly [MEDIC 01]', $namesA === ['ZZ99 MEDIC 01']);

    $unitsForB = facility_portal_visible_units($ticketReceivingId, $facBId, 0, $facAId);
    $namesB = array_column($unitsForB, 'responder_name');
    t('Facility B (per-unit leg only): sees exactly [MEDIC 04]', $namesB === ['ZZ99 MEDIC 04']);

    $unitsForC = facility_portal_visible_units($ticketOriginId, $facCId, $facCId, 0);
    $namesC = array_column($unitsForC, 'responder_name');
    sort($namesC);
    t('Facility C (origin leg): sees BOTH units regardless of their destinations',
        $namesC === ['ZZ99 MEDIC 01', 'ZZ99 MEDIC 04']);

    // Facility B is NOT the origin-control ticket's origin (Facility C is),
    // but B IS the per-unit destination MEDIC 04 was given on that same
    // ticket — a real "this ticket also has an unrelated transport leg"
    // shape. The receiving-only filter must still apply consistently for
    // B here: exactly the unit actually destined to B, never MEDIC 01.
    $unitsForBOnOriginTicket = facility_portal_visible_units($ticketOriginId, $facBId, $facCId, 0);
    $namesBOnOrigin = array_column($unitsForBOnOriginTicket, 'responder_name');
    t('Facility B (receiving-only leg on a ticket that is someone else\'s origin) sees exactly [MEDIC 04], never MEDIC 01',
        $namesBOnOrigin === ['ZZ99 MEDIC 04']);

    // ══════════════════════════════════════════════════════════════════
    // Sections A-C — real HTTP, real login, real endpoint. Section D
    // above already proved the confinement boundary at the function
    // level and its pass count stands regardless of what happens here;
    // this end-to-end layer is additive proof, not the only proof.
    // ══════════════════════════════════════════════════════════════════
    $base = null;
    if (!$httpCapable) {
        echo "\nSKIP: proc_open/curl unavailable in this environment — Sections A-C (real end-to-end HTTP) not run; Section D's direct-function proof above still stands.\n";
    } else {
        echo "\n--- Starting ephemeral php -S server for real end-to-end HTTP verification ---\n\n";
        $srv = gh99_start_server();
        t('ephemeral php -S test server started', $srv !== null);
        if ($srv !== null) {
            $base = 'http://127.0.0.1:' . $srv['port'];
        }
    }

    if ($base !== null) {

    echo "\n--- A. Facility A's real session sees ONLY MEDIC 01 (never MEDIC 04 or its timestamps) ---\n\n";
    $cookieA = gh99_login($base, 'zz99-gh99-facA', $plainPwA);
    t('Facility A real HTTP login succeeded (session cookie obtained)', $cookieA !== null);
    if ($cookieA !== null) {
        $resp = gh99_get_json($base . '/api/facility-portal.php?action=incidents', $cookieA);
        t('api/facility-portal.php?action=incidents returned JSON', is_array($resp));
        $incident = null;
        foreach (($resp['incidents'] ?? []) as $inc) {
            if ((int) ($inc['id'] ?? 0) === $ticketReceivingId) { $incident = $inc; break; }
        }
        t('Facility A sees the receiving-leg ticket in its incident list', $incident !== null);
        if ($incident !== null) {
            $unitNames = array_map(function ($u) { return $u['responder_name'] ?? ''; }, $incident['units'] ?? []);
            t('Facility A\'s units list is EXACTLY [MEDIC 01] over real HTTP', $unitNames === ['ZZ99 MEDIC 01']);
            t('Facility A\'s units list does NOT contain MEDIC 04', !in_array('ZZ99 MEDIC 04', $unitNames, true));
            $m01 = $incident['units'][0] ?? [];
            t('MEDIC 01\'s en_route_at/arrived_at ARE exposed to its own receiving facility',
                !empty($m01['en_route_at']) && !empty($m01['arrived_at']));
        }
        @unlink($cookieA);
    }

    echo "\n--- B. Facility B's real session sees ONLY MEDIC 04 on the SAME ticket ---\n\n";
    $cookieB = gh99_login($base, 'zz99-gh99-facB', $plainPwB);
    t('Facility B real HTTP login succeeded (session cookie obtained)', $cookieB !== null);
    if ($cookieB !== null) {
        $resp = gh99_get_json($base . '/api/facility-portal.php?action=incidents', $cookieB);
        $incident = null;
        foreach (($resp['incidents'] ?? []) as $inc) {
            if ((int) ($inc['id'] ?? 0) === $ticketReceivingId) { $incident = $inc; break; }
        }
        t('Facility B ALSO sees this ticket (via its own per-unit destination leg — Phase 116)', $incident !== null);
        if ($incident !== null) {
            $unitNames = array_map(function ($u) { return $u['responder_name'] ?? ''; }, $incident['units'] ?? []);
            t('Facility B\'s units list is EXACTLY [MEDIC 04] over real HTTP — the core GH#99 assertion',
                $unitNames === ['ZZ99 MEDIC 04']);
            t('Facility B\'s units list does NOT contain MEDIC 01 or its facility-leg timestamps',
                !in_array('ZZ99 MEDIC 01', $unitNames, true));
        }
        @unlink($cookieB);
    }

    echo "\n--- C. Facility C's real session (origin leg) sees BOTH units — no regression ---\n\n";
    $cookieC = gh99_login($base, 'zz99-gh99-facC', $plainPwC);
    t('Facility C real HTTP login succeeded (session cookie obtained)', $cookieC !== null);
    if ($cookieC !== null) {
        $resp = gh99_get_json($base . '/api/facility-portal.php?action=incidents', $cookieC);
        $incident = null;
        foreach (($resp['incidents'] ?? []) as $inc) {
            if ((int) ($inc['id'] ?? 0) === $ticketOriginId) { $incident = $inc; break; }
        }
        t('Facility C sees the origin-leg control ticket', $incident !== null);
        if ($incident !== null) {
            $unitNames = array_map(function ($u) { return $u['responder_name'] ?? ''; }, $incident['units'] ?? []);
            sort($unitNames);
            t('Facility C (origin) sees BOTH MEDIC 01 and MEDIC 04 over real HTTP — origin visibility not regressed',
                $unitNames === ['ZZ99 MEDIC 01', 'ZZ99 MEDIC 04']);
        }
        // Adversarial: Facility C must NOT see the receiving-only ticket
        // it has no leg on at all.
        $leak = null;
        foreach (($resp['incidents'] ?? []) as $inc) {
            if ((int) ($inc['id'] ?? 0) === $ticketReceivingId) { $leak = $inc; break; }
        }
        t('Facility C does NOT see the unrelated receiving-only ticket (ticket-level confinement still holds)', $leak === null);
        @unlink($cookieC);
    }

    } // end if ($base !== null)

} finally {
    gh99_stop_server($srv);
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
