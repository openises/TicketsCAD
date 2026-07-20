<?php
/**
 * F-007 regression — SSE events must filter by per-user visibility.
 *
 * Validates the schema change + publisher API + stream.php WHERE clause that
 * together stop SSE from broadcasting every event to every connected user.
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/sse.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== F-007 SSE Visibility Filtering Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── 1. Schema must include visibility columns after _sse_ensure_schema() ──
_sse_ensure_schema();
$cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}sse_events`");
$names = array_column($cols, 'Field');
foreach (['visibility_scope', 'visibility_ids'] as $c) {
    if (in_array($c, $names, true)) {
        ok("sse_events.$c column present");
    } else {
        bad("sse_events.$c column present");
    }
}

// ── 2. New helpers exist ──
foreach (['sse_publish_for_incident', 'sse_publish_for_responder',
          'sse_publish_for_user', 'sse_publish_for_admin'] as $fn) {
    if (function_exists($fn)) {
        ok("$fn() defined");
    } else {
        bad("$fn() defined");
    }
}

// ── 3. Public publish path is backward-compatible (3-arg signature) ──
$published = sse_publish('test_f007:public', ['k' => 1], 1);
if ($published) {
    $row = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'test_f007:public' ORDER BY id DESC LIMIT 1"
    );
    if ($row && $row['visibility_scope'] === 'public' && empty($row['visibility_ids'])) {
        ok('default scope is public for legacy 3-arg sse_publish()');
    } else {
        bad('default scope is public for legacy 3-arg sse_publish()',
            'scope=' . ($row['visibility_scope'] ?? '?'));
    }
    db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$row['id']]);
} else {
    bad('legacy sse_publish() succeeds');
}

// ── 4. Admin publish stores scope='admin' ──
sse_publish_for_admin('test_f007:admin', ['k' => 2], 1);
$row = db_fetch_one(
    "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'test_f007:admin' ORDER BY id DESC LIMIT 1"
);
if ($row && $row['visibility_scope'] === 'admin') {
    ok("sse_publish_for_admin() stores scope='admin'");
} else {
    bad("sse_publish_for_admin() stores scope='admin'");
}
if ($row) db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$row['id']]);

// ── 5. User publish stores scope='user' with comma-list of ids ──
sse_publish_for_user('test_f007:user', ['k' => 3], 42, 1);
$row = db_fetch_one(
    "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'test_f007:user' ORDER BY id DESC LIMIT 1"
);
if ($row && $row['visibility_scope'] === 'user' && $row['visibility_ids'] === '42') {
    ok("sse_publish_for_user(42) stores scope='user' visibility_ids='42'");
} else {
    bad("sse_publish_for_user(42) stores scope='user' visibility_ids='42'",
        'scope=' . ($row['visibility_scope'] ?? '?') . ', ids=' . ($row['visibility_ids'] ?? '?'));
}
if ($row) db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$row['id']]);

// User publish accepts an array of recipients (multi-user notification)
sse_publish('test_f007:multi_user', ['k' => 4], 1, 'user', [10, 20, 30]);
$row = db_fetch_one(
    "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'test_f007:multi_user' ORDER BY id DESC LIMIT 1"
);
if ($row && $row['visibility_scope'] === 'user' && $row['visibility_ids'] === '10,20,30') {
    ok("sse_publish() multi-user array stored as comma list '10,20,30'");
} else {
    bad("sse_publish() multi-user array stored as comma list", 'ids=' . ($row['visibility_ids'] ?? '?'));
}
if ($row) db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$row['id']]);

// ── 6. group/user scope rejects empty recipient list (no fail-open) ──
$result = sse_publish('test_f007:empty_group', ['k' => 5], 1, 'group', []);
if ($result === false) {
    ok("group-scoped event with empty ids returns false (no fail-open)");
} else {
    bad("group-scoped event with empty ids returns false", 'unexpectedly succeeded');
}
$result = sse_publish('test_f007:empty_user', ['k' => 6], 1, 'user', []);
if ($result === false) {
    ok("user-scoped event with empty ids returns false (no fail-open)");
} else {
    bad("user-scoped event with empty ids returns false");
}

// ── 7. sse_publish_for_incident with no allocates → 'entitled' scope ──
// GH #13 (2026-07-07): the fallback changed from 'admin' to 'entitled' —
// delivered to admins AND RBAC view-permission holders (stream.php matches
// by event_type prefix), mirroring the read path. Still fail-closed: it is
// NOT 'public', and a subscriber with no view permission and no group
// receives nothing (verified below).
$result = sse_publish_for_incident('incident:test_f007_no_alloc', ['ticket_id' => 999999], 999999, 1);
$row = db_fetch_one(
    "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'incident:test_f007_no_alloc' ORDER BY id DESC LIMIT 1"
);
if ($row && $row['visibility_scope'] === 'entitled') {
    ok("sse_publish_for_incident with no allocates uses 'entitled' scope (GH #13, still not public)");
} else {
    bad("sse_publish_for_incident with no allocates uses 'entitled' scope", 'scope=' . ($row['visibility_scope'] ?? '?'));
}
// Replicate stream.php's WHERE for a NO-permission, no-group, non-admin
// subscriber: only public + own user-scoped events. The entitled row must
// NOT match (fail-closed for unentitled users).
if ($row) {
    $leak = db_fetch_one(
        "SELECT id FROM `{$prefix}sse_events`
          WHERE id = ?
            AND (`visibility_scope` = 'public'
                 OR (`visibility_scope` = 'user' AND FIND_IN_SET('424242', `visibility_ids`) > 0))",
        [$row['id']]
    );
    if (!$leak) {
        ok("'entitled' event invisible to a subscriber with no view permission (no leak)");
    } else {
        bad("'entitled' event invisible to unentitled subscriber", 'row leaked');
    }
    // And it MUST match the entitled clause stream.php adds for an RBAC
    // incident-view holder.
    $ent = db_fetch_one(
        "SELECT id FROM `{$prefix}sse_events`
          WHERE id = ?
            AND `visibility_scope` IN ('group','entitled') AND `event_type` LIKE 'incident:%'",
        [$row['id']]
    );
    if ($ent) {
        ok("'entitled' event visible via the RBAC-holder prefix clause (CAD→mobile fix)");
    } else {
        bad("'entitled' event visible via the RBAC-holder prefix clause", 'no match');
    }
}
if ($row) db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$row['id']]);

// ── 8. sse_publish_for_incident WITH allocates → group scope ──
$insertedAlloc = false;
try {
    db_query(
        "INSERT INTO `{$prefix}allocates` (`resource_id`, `type`, `group`) VALUES (?, ?, ?)",
        [987654, 1, 7]
    );
    db_query(
        "INSERT INTO `{$prefix}allocates` (`resource_id`, `type`, `group`) VALUES (?, ?, ?)",
        [987654, 1, 9]
    );
    $insertedAlloc = true;
} catch (Exception $e) {}

if ($insertedAlloc) {
    sse_publish_for_incident('test_f007:with_alloc', ['ticket_id' => 987654], 987654, 1);
    $row = db_fetch_one(
        "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'test_f007:with_alloc' ORDER BY id DESC LIMIT 1"
    );
    if ($row && $row['visibility_scope'] === 'group') {
        $ids = explode(',', (string) $row['visibility_ids']);
        sort($ids);
        if ($ids === ['7', '9']) {
            ok('sse_publish_for_incident with allocates uses group scope + correct ids');
        } else {
            bad('sse_publish_for_incident allocates ids', 'got ' . implode(',', $ids));
        }
    } else {
        bad('sse_publish_for_incident with allocates uses group scope',
            'scope=' . ($row['visibility_scope'] ?? '?'));
    }
    if ($row) db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$row['id']]);
    db_query(
        "DELETE FROM `{$prefix}allocates` WHERE `resource_id` = ? AND `type` = ?",
        [987654, 1]
    );
} else {
    echo "[SKIP] allocates-positive-path test (schema variance)\n";
}

// ── 9. stream.php builds the visibility WHERE clause ──
$base = realpath(__DIR__ . '/..');
$streamSrc = file_get_contents($base . '/api/stream.php');
if (strpos($streamSrc, 'visibility_scope') !== false
    && strpos($streamSrc, "FIND_IN_SET") !== false
    && strpos($streamSrc, '$visibilityWhere') !== false) {
    ok('stream.php has F-007 visibility WHERE clause');
} else {
    bad('stream.php has F-007 visibility WHERE clause');
}

// ── 10. stream.php pulls user level + groups from session ──
if (strpos($streamSrc, "\$_SESSION['level']") !== false
    && strpos($streamSrc, "\$_SESSION['user_groups']") !== false) {
    ok('stream.php reads user level + groups from session');
} else {
    bad('stream.php reads user level + groups from session');
}

// ── 11. Filter behavior: admin sees admin events; non-admin in same db doesn't ──
sse_publish_for_admin('test_f007:visible_to_admin', ['k' => 7], 1);
$row = db_fetch_one(
    "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'test_f007:visible_to_admin' ORDER BY id DESC LIMIT 1"
);
if (!$row) { bad('admin event seed'); }
$adminId = (int) $row['id'];

// Reproduce the WHERE logic used by stream.php (verifies the algorithm matches
// the runtime). Caller is non-admin, no groups.
$nonAdminWhere = "(\n    (`visibility_scope` = 'public')\n    OR (`visibility_scope` = 'user' AND FIND_IN_SET(?, `visibility_ids`) > 0)\n)";
$visible = db_fetch_all(
    "SELECT id FROM `{$prefix}sse_events` WHERE id = ? AND $nonAdminWhere",
    [$adminId, '99']
);
if (empty($visible)) {
    ok('non-admin would NOT see scope=admin event (per-user filter applied)');
} else {
    bad('non-admin would NOT see scope=admin event');
}

$adminWhere = "(\n    (`visibility_scope` = 'public')\n    OR `visibility_scope` IN ('admin','group')\n    OR (`visibility_scope` = 'user' AND FIND_IN_SET(?, `visibility_ids`) > 0)\n)";
$visible = db_fetch_all(
    "SELECT id FROM `{$prefix}sse_events` WHERE id = ? AND $adminWhere",
    [$adminId, '1']
);
if (!empty($visible)) {
    ok('admin DOES see scope=admin event');
} else {
    bad('admin DOES see scope=admin event');
}
db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$adminId]);

// ── 12. User-scoped event: targeted user sees it, others don't ──
sse_publish_for_user('test_f007:visible_to_user', ['k' => 8], 42, 1);
$row = db_fetch_one(
    "SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'test_f007:visible_to_user' ORDER BY id DESC LIMIT 1"
);
$userEvtId = (int) ($row['id'] ?? 0);
if ($userEvtId > 0) {
    $userWhere = "(`visibility_scope` = 'user' AND FIND_IN_SET(?, `visibility_ids`) > 0)";
    $hit42 = db_fetch_all(
        "SELECT id FROM `{$prefix}sse_events` WHERE id = ? AND $userWhere",
        [$userEvtId, '42']
    );
    $hit99 = db_fetch_all(
        "SELECT id FROM `{$prefix}sse_events` WHERE id = ? AND $userWhere",
        [$userEvtId, '99']
    );
    if (!empty($hit42) && empty($hit99)) {
        ok('user 42 sees their own message; user 99 does not');
    } else {
        bad('user-scoped filter rejects non-recipients',
            'hit42=' . count($hit42) . ', hit99=' . count($hit99));
    }
    db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$userEvtId]);
}

// ── 13. Existing publishers updated to use scope-aware helpers ──
$incidentCreate = file_get_contents($base . '/api/incident-create.php');
if (strpos($incidentCreate, 'sse_publish_for_incident(') !== false) {
    ok('incident-create.php uses sse_publish_for_incident');
} else {
    bad('incident-create.php uses sse_publish_for_incident');
}
$incidentUpdate = file_get_contents($base . '/api/incident-update.php');
$cnt = preg_match_all('/sse_publish_for_incident\s*\(/', $incidentUpdate);
if ($cnt >= 3) {
    ok("incident-update.php uses sse_publish_for_incident ($cnt sites)");
} else {
    bad('incident-update.php uses sse_publish_for_incident', "found $cnt, expected ≥3");
}
$routing = file_get_contents($base . '/api/routing.php');
$cnt = preg_match_all('/sse_publish_for_admin\s*\(/', $routing);
if ($cnt >= 4) {
    ok("routing.php uses sse_publish_for_admin ($cnt sites)");
} else {
    bad('routing.php uses sse_publish_for_admin', "found $cnt, expected ≥4");
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
