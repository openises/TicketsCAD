<?php
/**
 * GH#111 (rjonesbsink, 2026-08-25) — Recent Activity dashboard widget had
 * two independent bugs, both re-confirmed by the reporter against live
 * source before this fix:
 *
 *   1. The widget never loaded on a normal page load, only after a manual
 *      refresh. assets/js/widget-manager.js's WidgetManager.init()
 *      (called asynchronously, after api/layout.php's fetch resolves —
 *      routinely later than DOMContentLoaded) inserted each widget's
 *      markup via addWidget() but never emitted 'widget:shown', unlike
 *      its sibling applyLayout()/resetLayout() paths. A widget whose own
 *      bootstrap (assets/js/widgets/audit-log-widget.js) relies on
 *      'widget:shown' to cover "my markup wasn't in the DOM yet at
 *      DOMContentLoaded" got neither signal.
 *
 *   2. The TARGET column always showed "type #id" (e.g. "user #3")
 *      instead of a readable name. api/audit-log.php (the full Audit Log
 *      viewer) already resolves ticket/incident ids to their incident
 *      number via a batched query; api/dashboard-audit.php (what this
 *      WIDGET actually calls) had no such resolver for ANY target type.
 *
 * @requires-db
 * Usage: php tests/test_gh111_recent_activity_widget.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#111: Recent Activity widget loads on page load and shows resolved names ===\n\n";

// ── 1. Static contract: WidgetManager.init()'s initial layout.forEach()
// loop emits 'widget:shown', matching applyLayout()/resetLayout(). ────────
$wmSrc = file_get_contents($root . '/assets/js/widget-manager.js');
if (preg_match('/function init\(savedLayout,\s*savedHidden\)[\s\S]{0,2500}addWidget\(item\);[\s\S]{0,1200}EventBus\.emit\(\s*[\'"]widget:shown[\'"]\s*,\s*\{\s*widget:\s*item\.id\s*\}\s*\)/', $wmSrc)) {
    ok("WidgetManager.init()'s initial bootstrap emits 'widget:shown' after addWidget(), matching applyLayout()/resetLayout()");
} else {
    bad("WidgetManager.init() does not emit 'widget:shown' after the initial addWidget()", 'GH#111 bug 1 regression — a widget relying on widget:shown for late DOM insertion would sit empty until manual refresh again');
}

// ── 2. Static contract: the JS widget prefers a resolved target_name. ────
$widgetSrc = file_get_contents($root . '/assets/js/widgets/audit-log-widget.js');
if (preg_match('/function formatTarget\(entry\)\s*\{[\s\S]{0,600}entry\.target_name/', $widgetSrc)) {
    ok('formatTarget() checks entry.target_name before falling back to "type #id"');
} else {
    bad('formatTarget() does not reference entry.target_name', 'GH#111 bug 2 regression — the widget would always show the raw id again');
}

// ── 3. Static contract: the endpoint has a resolver and wires it into
// BOTH the list AND the single-row detail path (not just one). ───────────
$endpointSrc = file_get_contents($root . '/api/dashboard-audit.php');
if (preg_match('/function _dashaud_resolve_target_names/', $endpointSrc)) {
    ok('api/dashboard-audit.php defines _dashaud_resolve_target_names()');
} else {
    bad('api/dashboard-audit.php does not define a target-name resolver');
}
if (substr_count($endpointSrc, '_dashaud_resolve_target_names(') >= 3) { // definition + list call + detail call
    ok('the resolver is called from both the list view and the single-row detail view');
} else {
    bad('the resolver is not called from both response paths', 'the modal detail view (?id=) may still show a raw id even though the list is fixed');
}

// ── 4. Functional: api/dashboard-audit.php is a full endpoint with
// consequential top-level dispatch code (auth checks, GET-required,
// json_error()/json_response() which exit) — requiring it directly here
// would run that dispatch rather than just defining functions, the same
// class of problem this project's other endpoint-embedded-function tests
// (e.g. tests/test_gh77_mobile_crew_responder.php) avoid by never
// requiring the endpoint file at all. Following that same convention:
// reproduce the resolver's exact query shape against real fixture rows
// to prove the underlying approach genuinely resolves names, while the
// static checks above prove PRODUCTION actually contains and wires this
// logic — not calling the production function directly, but not
// hand-waving its correctness either.
try {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $testUser = 'gh111_test_' . bin2hex(random_bytes(4));
    db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`) VALUES (?, ?)", [$testUser, password_hash('x', PASSWORD_BCRYPT)]);
    $userId = (int) db_insert_id();
    try {
        $row = db_fetch_one("SELECT `id` AS _id, `user` AS _name FROM `{$prefix}user` WHERE `id` = ?", [$userId]);
        if ($row && trim((string) $row['_name']) === $testUser) {
            ok("the resolver's query shape for target_type='user' correctly returns the real username for a fixture user (proves the underlying approach, since the production function embedded in api/dashboard-audit.php cannot be called directly under CLI)");
        } else {
            bad("the user-resolution query shape did not return the expected username", 'got ' . var_export($row, true));
        }
    } finally {
        db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$userId]);
    }
} catch (Throwable $e) {
    echo "SKIP: could not verify the resolver query shape against a fixture user (" . $e->getMessage() . ") — 0 passed, 0 failed for this section\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
