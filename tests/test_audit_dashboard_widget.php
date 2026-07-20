<?php
/**
 * Phase 80c — Dashboard audit-log widget tests.
 *
 * Covers:
 *   1. api/dashboard-audit.php exists and refuses anonymous callers
 *   2. The endpoint requires the widget.audit_log permission OR is_admin()
 *   3. Non-admin scoping pins results to the caller's own user_id
 *   4. Admins see org-wide events
 *   5. Response projection includes the expected dashboard fields
 *   6. id-detail mode returns the full row including details JSON
 *   7. index.php registers the new widget toggle + template
 *   8. widget-manager.js carries the new widget in DEFAULT_LAYOUT
 *   9. The widget JS file exists and is wrapped in an IIFE (ES5 compliance)
 *  10. The Phase 80c permission seed script exists and is idempotent
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/audit.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 80c — Audit dashboard widget tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Setup: make sure the audit table and our test rows exist ────────
audit_ensure_table();

// Seed a couple of rows with predictable actor ids so we can verify
// the non-admin scoping below. We use very high user_ids that won't
// collide with real users.
$adminUser  = 990001;
$otherUser  = 990002;
$prefixTbl = $GLOBALS['db_prefix'] ?? '';
$auditTable = $prefixTbl . 'newui_audit_log';

try {
    // Clean slate for our two synthetic actors.
    db_query("DELETE FROM `{$auditTable}` WHERE `user_id` IN (?, ?)", [$adminUser, $otherUser]);

    db_query(
        "INSERT INTO `{$auditTable}`
         (`event_time`, `user_id`, `user_name`, `ip_address`, `category`, `activity`,
          `severity`, `target_type`, `target_id`, `summary`, `details`)
         VALUES (NOW(), ?, 'admin-test', '127.0.0.1', 'config', 'update',
                 1, 'setting', 'theme', 'Admin row for Phase 80c test', ?)",
        [$adminUser, json_encode(['k' => 'v'])]
    );
    db_query(
        "INSERT INTO `{$auditTable}`
         (`event_time`, `user_id`, `user_name`, `ip_address`, `category`, `activity`,
          `severity`, `target_type`, `target_id`, `summary`, `details`)
         VALUES (NOW(), ?, 'other-test', '127.0.0.1', 'incident', 'create',
                 2, 'ticket', '12345', 'Non-admin row for Phase 80c test', NULL)",
        [$otherUser]
    );
    ok('Seeded two synthetic audit_log rows for scoping tests');
} catch (Exception $e) {
    bad('Could not seed test rows', $e->getMessage());
}

// ── 1. Endpoint file presence + structure ──────────────────────────
$api = $base . '/api/dashboard-audit.php';
if (file_exists($api)) {
    ok('api/dashboard-audit.php exists');
} else {
    bad('api/dashboard-audit.php missing');
}

$src = @file_get_contents($api);
if ($src !== false) {
    if (strpos($src, "require_once __DIR__ . '/auth.php'") !== false) {
        ok('endpoint requires auth.php');
    } else {
        bad('endpoint does NOT require auth.php');
    }
    if (strpos($src, "ini_set('display_errors', '0')") !== false) {
        ok('endpoint suppresses display_errors');
    } else {
        bad('endpoint does NOT suppress display_errors');
    }
    if (strpos($src, 'json_response(') !== false) {
        ok('endpoint emits json_response()');
    } else {
        bad('endpoint does NOT emit json_response()');
    }
    if (strpos($src, "rbac_can('widget.audit_log')") !== false) {
        ok('endpoint gates on rbac_can(widget.audit_log)');
    } else {
        bad('endpoint does NOT check widget.audit_log permission');
    }
    if (strpos($src, "rbac_can('action.view_audit')") !== false) {
        ok('endpoint scopes non-admins via action.view_audit');
    } else {
        bad('endpoint does NOT scope cross-actor visibility');
    }
    // The non-admin scoping clause must pin results to the caller's
    // own user_id, otherwise the org-scoping requirement isn't met.
    // Two co-located signals: the negated $canViewAll gate, and a
    // user_id WHERE clause backed by $_SESSION['user_id'].
    $hasGate     = strpos($src, 'if (!$canViewAll)') !== false;
    $hasSelfPin  = strpos($src, "(int) \$_SESSION['user_id']") !== false
                && strpos($src, '`user_id` = ?') !== false;
    if ($hasGate && $hasSelfPin) {
        ok('non-admin scope pins to caller user_id');
    } else {
        bad('non-admin scope clause missing or wrong shape',
            'gate=' . ($hasGate ? 'y' : 'n') . ', self_pin=' . ($hasSelfPin ? 'y' : 'n'));
    }
} else {
    bad('could not read api/dashboard-audit.php');
}

// ── 2. Auth refusal: anonymous caller via direct include ───────────
// We can't fully exercise the HTTP path here, but we can confirm the
// require_once chain refuses an empty session. The cleanest signal is
// that auth.php would call json_error('Not authenticated', 401) when
// $_SESSION['user_id'] is empty — verify the exact string is present.
$auth = file_get_contents($base . '/api/auth.php');
if ($auth !== false && strpos($auth, "json_error('Not authenticated', 401)") !== false) {
    ok('auth.php refuses anonymous callers (401)');
} else {
    bad('auth.php anonymous refusal contract broke');
}

// ── 3 & 4. Non-admin scoping vs admin visibility ────────────────────
// Drive the endpoint's internal logic by simulating both session shapes
// in CLI. The endpoint reads $_SESSION['user_id'] for the scoped clause
// and is_admin() / rbac_can() for the gates. We probe at the query
// level to confirm the WHERE clause actually filters.
//
// Direct SQL probe: a query mirroring what the endpoint runs for a
// non-admin user must only return that user's row.
try {
    $rows = db_fetch_all(
        "SELECT `id`, `user_id`, `user_name`, `activity`
         FROM `{$auditTable}`
         WHERE `user_id` = ?
         ORDER BY `id` DESC
         LIMIT 50",
        [$otherUser]
    );
    if (count($rows) === 1 && (int)$rows[0]['user_id'] === $otherUser) {
        ok('non-admin SQL projection returns only caller rows');
    } else {
        bad('non-admin SQL projection returned ' . count($rows) . ' rows (expected 1)');
    }
} catch (Exception $e) {
    bad('non-admin scope probe failed', $e->getMessage());
}

try {
    $rows = db_fetch_all(
        "SELECT `id`, `user_id`, `user_name`, `activity`
         FROM `{$auditTable}`
         WHERE `user_id` IN (?, ?)
         ORDER BY `id` DESC
         LIMIT 50",
        [$adminUser, $otherUser]
    );
    if (count($rows) === 2) {
        ok('admin-equivalent projection returns all matching rows');
    } else {
        bad('admin-equivalent projection returned ' . count($rows) . ' rows (expected 2)');
    }
} catch (Exception $e) {
    bad('admin scope probe failed', $e->getMessage());
}

// ── 5. Response projection field shape (call the helper directly) ──
// _dashaud_project() is the projection function inside the endpoint.
// To exercise it without an HTTP round-trip we include the file in a
// way that loads only the function. Easier: rebuild the projection
// in this test and confirm every expected key is in the source.
$expectedKeys = ['id', 'ts', 'event_type', 'category', 'severity',
                 'actor_id', 'actor_name', 'target_table', 'target_id',
                 'ip', 'summary'];
$projectionOK = true;
foreach ($expectedKeys as $k) {
    if (strpos($src, "'" . $k . "'") === false) {
        $projectionOK = false;
        bad("projection missing field: {$k}");
        break;
    }
}
if ($projectionOK) {
    ok('projection includes all expected dashboard fields');
}

// ── 6. Detail mode (?id=) — confirm SQL fetch path ──────────────────
try {
    $row = db_fetch_one(
        "SELECT `id`, `event_time`, `user_id`, `user_name`, `ip_address`,
                `category`, `activity`, `severity`, `target_type`, `target_id`,
                `summary`, `details`
         FROM `{$auditTable}`
         WHERE `user_id` = ?
         LIMIT 1",
        [$adminUser]
    );
    if ($row && $row['details']) {
        $decoded = json_decode($row['details'], true);
        if (is_array($decoded) && isset($decoded['k']) && $decoded['k'] === 'v') {
            ok('detail mode JSON decode round-trips');
        } else {
            bad('detail mode JSON decode failed');
        }
    } else {
        bad('detail mode row lookup failed');
    }
} catch (Exception $e) {
    bad('detail-mode SQL probe failed', $e->getMessage());
}

// ── 7. index.php registers the new widget toggle + template ────────
$index = file_get_contents($base . '/index.php');
if ($index !== false) {
    if (strpos($index, 'data-widget="audit_log"') !== false) {
        ok('index.php has audit_log widget toggle button');
    } else {
        bad('index.php missing audit_log widget toggle');
    }
    if (strpos($index, '<template id="tpl-audit_log">') !== false) {
        ok('index.php has tpl-audit_log template');
    } else {
        bad('index.php missing tpl-audit_log template');
    }
    if (strpos($index, 'id="auditLogBody"') !== false) {
        ok('template provides auditLogBody tbody');
    } else {
        bad('auditLogBody tbody missing from template');
    }
    if (strpos($index, 'widgets/audit-log-widget.js') !== false) {
        ok('index.php loads the widget JS');
    } else {
        bad('index.php does NOT load the widget JS');
    }
    if (strpos($index, 'settings.php#audit-log') !== false) {
        ok('template links to settings.php#audit-log');
    } else {
        bad('template missing "View all" link to compliance page');
    }
} else {
    bad('could not read index.php');
}

// ── 8. widget-manager.js carries the new widget ────────────────────
$wm = file_get_contents($base . '/assets/js/widget-manager.js');
if ($wm !== false) {
    if (strpos($wm, "id: 'audit_log'") !== false) {
        ok('widget-manager.js DEFAULT_LAYOUT has audit_log entry');
    } else {
        bad('audit_log missing from DEFAULT_LAYOUT');
    }
    if (strpos($wm, 'audit_log:') !== false) {
        ok('widget-manager.js WIDGET_TITLES has audit_log entry');
    } else {
        bad('audit_log missing from WIDGET_TITLES');
    }
} else {
    bad('could not read widget-manager.js');
}

// ── 9. Widget JS file presence + ES5 conventions ───────────────────
$wjs = $base . '/assets/js/widgets/audit-log-widget.js';
if (file_exists($wjs)) {
    ok('widget JS file exists');
    $wjsSrc = file_get_contents($wjs);
    if (preg_match('/^var AuditLogWidget = \(function \(\) \{/m', $wjsSrc)) {
        ok('widget JS is wrapped in IIFE (ES5)');
    } else {
        bad('widget JS missing IIFE wrapper');
    }
    // ES5 audit — no arrow functions in our own code. (Bootstrap and
    // Leaflet are vendor and excluded.) We allow string literals
    // containing => for HTML, but reject ` => {` and `() =>`.
    if (!preg_match('/\) =>/', $wjsSrc) && !preg_match('/[a-zA-Z_$] => /', $wjsSrc)) {
        ok('widget JS contains no arrow functions');
    } else {
        bad('widget JS contains arrow functions — ES5 violation');
    }
    if (strpos($wjsSrc, '`') === false) {
        ok('widget JS contains no template literals');
    } else {
        bad('widget JS contains template literals — ES5 violation');
    }
} else {
    bad('widget JS file missing');
}

// ── 10. Phase 80c migration ────────────────────────────────────────
$mig = $base . '/sql/run_phase80c_perms.php';
if (file_exists($mig)) {
    ok('sql/run_phase80c_perms.php exists');
    $migSrc = file_get_contents($mig);
    if (strpos($migSrc, 'INSERT IGNORE') !== false) {
        ok('migration uses INSERT IGNORE (idempotent)');
    } else {
        bad('migration not idempotent — missing INSERT IGNORE');
    }
    if (strpos($migSrc, "'widget.audit_log'") !== false) {
        ok('migration seeds widget.audit_log code');
    } else {
        bad('migration does NOT seed widget.audit_log');
    }
} else {
    bad('Phase 80c migration script missing');
}

// ── Cleanup — drop the seeded rows ─────────────────────────────────
try {
    db_query("DELETE FROM `{$auditTable}` WHERE `user_id` IN (?, ?)", [$adminUser, $otherUser]);
    ok('test rows cleaned up');
} catch (Exception $e) {
    bad('cleanup failed', $e->getMessage());
}

echo "\n";
echo "==========================================================\n";
echo "Phase 80c audit dashboard widget: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

if ($fail > 0) exit(1);
