<?php
/**
 * GH openises/TicketsCAD#3 — renaming a map markup must not destroy its geometry.
 *
 * The bug: api/map-markups.php's `save` action built a FULL column set with
 * hard-coded defaults and UPDATEd every one of them. The Rename button posts
 * only {id, name}, so every other column was overwritten — line_data (the
 * geometry) became '', line_type became 'P', line_ident (circle radius) became
 * ''. The row survived and still listed, but every renderer guards on a
 * non-empty, parseable line_data, so the shape silently vanished from every map
 * and its coordinates were unrecoverable.
 *
 * This test drives the REAL writer over HTTP with the EXACT payload the UI
 * sends — deliberately NOT hand-inserting rows and NOT adding "coordinates just
 * to be safe", because doing either would reproduce a state the real Rename
 * button never produces and the test would pass against the broken code.
 * (Project rule: reproduce through the real writer. See the bed-automation
 * episode in CLAUDE.md.)
 *
 * @requires-http — hits http://localhost/newui via a live Apache; skipped when NEWUI_TEST_NO_HTTP=1
 */
require __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function ok($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else       { $fail++; echo "  FAIL  $label" . ($detail !== '' ? " — $detail" : "") . "\n"; }
}

if (getenv('NEWUI_TEST_NO_HTTP') === '1') {
    echo "SKIP: needs a live Apache (NEWUI_TEST_NO_HTTP=1)\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$BASE = 'http://localhost/newui';

// ── auth ────────────────────────────────────────────────────────────────
function authCookie($BASE) {
    // Credentials come from the environment so no password is ever hard-coded
    // here and CI can point at its own throwaway account.
    $USER = getenv('NEWUI_TEST_USER') ?: 'admin';
    $PASS = getenv('NEWUI_TEST_PASS') ?: 'testing';
    $jar = tempnam(sys_get_temp_dir(), 'gh3cookie');
    $ch = curl_init("$BASE/login.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar]);
    $html = curl_exec($ch); curl_close($ch);
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
    $csrf = $m[1] ?? '';

    $ch = curl_init("$BASE/login.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['username'=>$USER,'password'=>$PASS,'csrf_token'=>$csrf]),
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HEADER=>true,
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

    // 2FA step, when the admin account has it enrolled
    if ($code === 200 && strpos($resp, 'tfa_verify') !== false) {
        require_once __DIR__ . '/../inc/tfa.php';
        require_once __DIR__ . '/../inc/totp.php';
        $row = db_fetch_one("SELECT id FROM " . db_table('user') . " WHERE user = ?", [$USER]);
        $uid = $row ? (int)$row['id'] : 1;
        $t = null;
        try { $t = db_fetch_one("SELECT `secret_encrypted` FROM " . db_table('user_tfa')
                                . " WHERE `user_id` = ? AND `confirmed` = 1", [$uid]); } catch (Exception $e) {}
        if ($t && !empty($t['secret_encrypted'])) {
            $sec = tfa_decrypt($t['secret_encrypted']);
            if ($sec) {
                preg_match('/name="csrf_token"\s+value="([^"]+)"/', $resp, $cm);
                $ch = curl_init("$BASE/login.php");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>http_build_query([
                        'tfa_verify'=>'1', 'code'=>totp_get_code($sec), 'csrf_token'=>$cm[1] ?? '']),
                    CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_FOLLOWLOCATION=>false,
                ]);
                curl_exec($ch); curl_close($ch);
            }
        }
    }
    return $jar;
}

function apiPost($BASE, $jar, array $payload) {
    $ch = curl_init("$BASE/api/map-markups.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar,
    ]);
    $out = curl_exec($ch); curl_close($ch);
    return json_decode($out, true);
}

function csrfFrom($BASE, $jar) {
    // Try several authenticated pages: a brand-new account is gated to
    // profile.php?force_pw=1 until it sets a password, so settings.php may
    // redirect. navbar.php emits window.CSRF_TOKEN on every authenticated page.
    foreach (['settings.php', 'profile.php', 'index.php'] as $page) {
        $ch = curl_init("$BASE/$page");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEJAR=>$jar,
                                CURLOPT_COOKIEFILE=>$jar, CURLOPT_FOLLOWLOCATION=>true]);
        $html = curl_exec($ch); curl_close($ch);
        if (preg_match('/window\.CSRF_TOKEN\s*=\s*window\.CSRF_TOKEN\s*\|\|\s*"([^"]+)"/', $html, $m)) return $m[1];
        if (preg_match('/id="csrfToken"[^>]*value="([^"]+)"/', $html, $m)) return $m[1];
        if (preg_match('/name="csrf_token"\s+(?:content|value)="([^"]+)"/', $html, $m)) return $m[1];
    }
    return '';
}

echo "=== GH #3 — markup rename must preserve geometry ===\n\n";

$jar  = authCookie($BASE);
$csrf = csrfFrom($BASE, $jar);

// A freshly created account is gated with force_pw_change until it sets its own
// password; clear that so the API is reachable. Only ever done for the
// dedicated throwaway test account named in NEWUI_TEST_USER — never for a real
// user (see the "NEVER reset live passwords" rule in CLAUDE.md).
if ($csrf !== '' && getenv('NEWUI_TEST_USER')) {
    $ch = curl_init("$BASE/api/profile.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode([
            'action'=>'change_password', 'csrf_token'=>$csrf,
            'current_password'=>getenv('NEWUI_TEST_PASS'),
            'new_password'=>getenv('NEWUI_TEST_PASS') . 'A9',
        ]),
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar,
    ]);
    curl_exec($ch); curl_close($ch);
    $csrf = csrfFrom($BASE, $jar) ?: $csrf;
}
if ($csrf === '') { echo "SKIP: could not obtain a CSRF token (is the local admin password still 'testing'?)\n";
                    echo "\n=== 0 passed, 0 failed ===\n"; exit(0); }

$tbl = db_table('mmarkup');
$created = [];

// ── 1. create shapes through the REAL endpoint, exactly as the drawing editor does ──
$cases = [
    'marker'  => ['type'=>'M', 'coordinates'=>'[[44.9778,-93.2650]]', 'ident'=>''],
    'circle'  => ['type'=>'C', 'coordinates'=>'[[44.9778,-93.2650]]', 'ident'=>'250'],
    'polygon' => ['type'=>'P', 'coordinates'=>'[[44.97,-93.26],[44.98,-93.26],[44.98,-93.25]]', 'ident'=>''],
];

foreach ($cases as $label => $c) {
    $r = apiPost($BASE, $jar, [
        'action'=>'save', 'csrf_token'=>$csrf,
        'name'=>"GH3 $label", 'visible'=>1, 'category_id'=>0,
        'type'=>$c['type'], 'ident'=>$c['ident'], 'coordinates'=>$c['coordinates'],
        'color'=>'#1976d2', 'fill_color'=>'#1976d2', 'filled'=>0,
        'width'=>2, 'opacity'=>1, 'fill_opacity'=>0.2,
    ]);
    $id = (int)($r['id'] ?? 0);
    ok("create $label via real endpoint", $id > 0, json_encode($r));
    if (!$id) continue;
    $created[$label] = $id;

    $before = db_fetch_one("SELECT * FROM $tbl WHERE id = ?", [$id]);

    // ── 2. rename with the EXACT payload __mo_rename sends: id + name ONLY ──
    $rr = apiPost($BASE, $jar, [
        'action'=>'save', 'csrf_token'=>$csrf, 'id'=>$id, 'name'=>"GH3 $label renamed",
    ]);
    ok("rename $label accepted", !empty($rr['success']), json_encode($rr));

    $after = db_fetch_one("SELECT * FROM $tbl WHERE id = ?", [$id]);

    // ── 3. the name changed, and NOTHING else did ──
    ok("$label: name updated", $after['line_name'] === "GH3 $label renamed", $after['line_name']);
    ok("$label: geometry PRESERVED (line_data)", $after['line_data'] === $before['line_data'],
       "before=" . var_export($before['line_data'], true) . " after=" . var_export($after['line_data'], true));
    ok("$label: line_data not blank", trim((string)$after['line_data']) !== '', 'geometry was wiped');
    ok("$label: line_type preserved ({$c['type']})", $after['line_type'] === $c['type'], $after['line_type']);
    ok("$label: line_ident preserved", (string)$after['line_ident'] === (string)$c['ident'], var_export($after['line_ident'], true));
    ok("$label: line_color preserved", $after['line_color'] === $before['line_color'], var_export($after['line_color'], true));

    // ── 4. renderable by the guard the real renderers use ──
    $coords = json_decode((string)$after['line_data'], true);
    $minPts = $c['type'] === 'P' ? 3 : 1;
    ok("$label: still renderable (coords >= $minPts)", is_array($coords) && count($coords) >= $minPts);
}

// ── 5. colour change (GH #3 second request) must also preserve geometry ──
if (!empty($created['polygon'])) {
    $id = $created['polygon'];
    $before = db_fetch_one("SELECT * FROM $tbl WHERE id = ?", [$id]);
    $cr = apiPost($BASE, $jar, [
        'action'=>'save', 'csrf_token'=>$csrf, 'id'=>$id,
        'name'=>$before['line_name'], 'color'=>'#00ff00', 'fill_color'=>'#00ff00',
    ]);
    ok("colour change accepted", !empty($cr['success']), json_encode($cr));
    $after = db_fetch_one("SELECT * FROM $tbl WHERE id = ?", [$id]);
    ok("colour applied", strtolower((string)$after['line_color']) === '#00ff00', var_export($after['line_color'], true));
    ok("colour change PRESERVED geometry", $after['line_data'] === $before['line_data']);
    ok("colour change preserved type", $after['line_type'] === $before['line_type']);
}

// ── cleanup through the real delete path ──
foreach ($created as $label => $id) {
    apiPost($BASE, $jar, ['action'=>'delete', 'csrf_token'=>$csrf, 'id'=>$id]);
}
$left = db_fetch_value("SELECT COUNT(*) FROM $tbl WHERE line_name LIKE 'GH3 %'");
ok("cleanup removed test shapes", (int)$left === 0, "$left left behind");

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
