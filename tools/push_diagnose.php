<?php
/**
 * GH #8 — Web Push delivery diagnostic.
 *
 * The push pipeline has five stages and a "no push" report can fail at any one.
 * Recipient resolution is covered by tests/test_push_recipient_resolution.php
 * (green), so when a real install still gets no push the cause is almost always
 * an EARLIER prerequisite (push disabled / VAPID unset) or a LATER one (the
 * user has no browser subscription, or their account isn't linked to the unit
 * that was dispatched). This script makes every stage visible so we stop
 * guessing.
 *
 * Run it ON the affected install:
 *   php tools/push_diagnose.php                 # overall health
 *   php tools/push_diagnose.php --ticket=1234   # who WOULD get a push for #1234
 *   php tools/push_diagnose.php --user=42        # one user's subscription state
 *
 * It only READS — it never sends a push and never changes data.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/router_recipients.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// Tiny arg parser: --key=value
$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
}
$ticketId = isset($args['ticket']) ? (int) $args['ticket'] : 0;
$userArg  = isset($args['user'])   ? (int) $args['user']   : 0;

function line($s = '') { echo $s . "\n"; }
function ok($s)   { line("  [OK]   $s"); }
function bad($s)  { line("  [FAIL] $s"); }
function warn($s) { line("  [WARN] $s"); }

line("=== TicketsCAD Web Push diagnostic (GH #8) ===");
line();

// ── Stage 1: push enabled + VAPID configured ──
line("Stage 1 — server prerequisites");
$pushEnabled = (string) db_fetch_value(
    "SELECT value FROM `{$prefix}settings` WHERE name = 'push_enabled' LIMIT 1");
$pushEnabled === '1'
    ? ok("push_enabled = 1")
    : bad("push_enabled != 1 (Settings → Notifications → enable push). Nothing will ever send.");

$vapidPub  = (string) db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'push_vapid_public_key' LIMIT 1");
$vapidPriv = (string) db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'push_vapid_private_key' LIMIT 1");
($vapidPub !== '' && $vapidPriv !== '')
    ? ok("VAPID keypair present")
    : bad("VAPID keys not configured (Settings → Notifications → generate keys). No push can be signed.");

$hasLib = class_exists('Minishlink\\WebPush\\WebPush');
$hasLib ? ok("minishlink/web-push library loaded")
        : warn("web-push library not autoloaded here (composer). It may still load under Apache.");
line();

// ── Stage 2: seed routes present + enabled ──
line("Stage 2 — push routes");
$routes = db_fetch_all(
    "SELECT id, name, enabled, priority, recipient_predicate_json
       FROM `{$prefix}message_routes`
      WHERE dest_channel = 'push' ORDER BY priority");
if (!$routes) {
    bad("No dest_channel='push' routes exist. They seed on first router_evaluate(); "
        . "hit any dispatch once, or check inc/router_recipients.php seeding.");
} else {
    foreach ($routes as $r) {
        $state = ((int) $r['enabled'] === 1) ? 'enabled' : 'DISABLED';
        $tag = ((int) $r['enabled'] === 1) ? 'ok' : 'warn';
        $tag($state . " · #{$r['id']} {$r['name']}");
    }
    $anyAssigned = false;
    foreach ($routes as $r) {
        if (strpos((string) $r['recipient_predicate_json'], 'assigned_to_incident') !== false
            && (int) $r['enabled'] === 1) $anyAssigned = true;
    }
    $anyAssigned
        ? ok("an enabled route targets assigned_to_incident (mobile/field audience)")
        : warn("no ENABLED route uses assigned_to_incident — field responders won't be targeted");
}
line();

// ── Stage 3: subscriptions ──
line("Stage 3 — browser subscriptions (this is where iPhone PWA usually fails)");
$subSummary = db_fetch_all(
    "SELECT user_id, COUNT(*) n,
            SUM(CASE WHEN last_error LIKE 'gone:%' THEN 1 ELSE 0 END) gone
       FROM `{$prefix}push_subscriptions`
      WHERE channel = 'web'
      GROUP BY user_id ORDER BY user_id");
if (!$subSummary) {
    bad("ZERO web push subscriptions on this install. No device has successfully "
        . "subscribed. On iPhone this means: the site must be ADDED TO HOME SCREEN "
        . "as a PWA (Safari tab push does not work), on iOS 16.4+, and the user must "
        . "tap Allow when prompted. Until a row appears here, no dispatch can push.");
} else {
    foreach ($subSummary as $s) {
        $live = (int) $s['n'] - (int) $s['gone'];
        $msg = "user_id {$s['user_id']}: {$s['n']} subscription(s), {$live} live"
             . ((int) $s['gone'] ? ", {$s['gone']} expired(gone)" : "");
        $live > 0 ? ok($msg) : warn($msg . " — all expired; the device must re-subscribe");
    }
}
line();

// ── Stage 4 (optional): resolve who would get a push for a ticket ──
if ($ticketId > 0) {
    line("Stage 4 — recipient resolution for incident #{$ticketId}");
    $predicate = ['predicate' => 'assigned_to_incident',
                  'params' => ['ticket_id' => '$payload.ticket_id']];
    $ids = router_recipients_resolve($predicate, ['ticket_id' => $ticketId]);
    if (!$ids) {
        bad("assigned_to_incident resolves NOBODY for #{$ticketId}. Either no unit is "
            . "assigned (open assignment), or the assigned unit has no linked login user "
            . "AND no active personnel with a login user. The dispatcher's own account is "
            . "NOT a recipient unless they are on the assignment.");
    } else {
        ok("resolves user_id(s): " . implode(', ', $ids));
        foreach ($ids as $uid) {
            $n = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}push_subscriptions`
                  WHERE channel='web' AND user_id = ?
                    AND (last_error IS NULL OR last_error NOT LIKE 'gone:%')", [$uid]);
            $n > 0 ? ok("  user_id {$uid} has {$n} live subscription(s) → would receive push")
                   : bad("  user_id {$uid} is a recipient but has NO live subscription → no push to them");
        }
    }
    line();
}

// ── Stage 5 (optional): one user's linkage ──
if ($userArg > 0) {
    line("Stage 5 — account linkage for user_id {$userArg}");
    $directResp = db_fetch_all(
        "SELECT id, name FROM `{$prefix}responder` WHERE user_id = ?", [$userArg]);
    $directResp
        ? ok("linked directly to responder(s): "
             . implode(', ', array_map(fn($r) => "#{$r['id']} {$r['name']}", $directResp)))
        : warn("no responder has user_id={$userArg} (direct unit link)");
    try {
        $viaMember = db_fetch_all(
            "SELECT DISTINCT upa.responder_id
               FROM `{$prefix}member` m
               JOIN `{$prefix}unit_personnel_assignments` upa ON upa.member_id = m.id
              WHERE m.user_id = ? AND upa.status IN ('active','standby')", [$userArg]);
        $viaMember
            ? ok("linked via personnel to responder_id(s): "
                 . implode(', ', array_map(fn($r) => $r['responder_id'], $viaMember)))
            : warn("not an active/standby person on any unit (personnel link)");
    } catch (Throwable $e) {
        warn("personnel link check skipped: " . $e->getMessage());
    }
    line();
}

// ── Stage 6 (optional): trace a NEW incident across ALL enabled push routes ──
// GH #8 (a beta tester 2026-07-14): push tests + unit-update pushes arrive, but new
// incidents don't. This stage answers "for a fresh incident, does ANY route
// resolve me?" across BOTH seed routes — the assigned_to_incident (field) route
// AND the dispatch-screen-access (dispatcher) route — so we can tell a role/RBAC
// gap apart from the event simply not firing.
//   php tools/push_diagnose.php --simulate --user=<your id> [--ticket=<real id>]
if (isset($args['simulate']) || array_search('--simulate', $argv, true) !== false) {
    line("Stage 6 — simulated incident.created across ALL enabled push routes");
    $simTicket = $ticketId > 0 ? $ticketId
        : (int) db_fetch_value("SELECT id FROM `{$prefix}ticket` ORDER BY id DESC LIMIT 1");
    $checkUser = $userArg;
    $msg = ['_event_type' => 'incident.created', 'ticket_id' => $simTicket, 'summary' => 'diagnostic'];
    $routesAll = db_fetch_all(
        "SELECT id, name, enabled, recipient_predicate_json
           FROM `{$prefix}message_routes` WHERE dest_channel='push' ORDER BY priority");
    $anyRecipient = false; $reachesCheckUser = false;
    foreach ($routesAll as $r) {
        if ((int) $r['enabled'] !== 1) { warn("route #{$r['id']} DISABLED — skipped"); continue; }
        $pred = json_decode((string) $r['recipient_predicate_json'], true) ?: [];
        $ids = router_recipients_resolve($pred, $msg);
        if (!$ids) { warn("route #{$r['id']} → resolves NOBODY for a new incident"); continue; }
        $anyRecipient = true;
        $withSubs = [];
        foreach ($ids as $uid) {
            $n = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}push_subscriptions`
                  WHERE channel='web' AND user_id = ?
                    AND (last_error IS NULL OR last_error NOT LIKE 'gone:%')", [$uid]);
            if ($n > 0) $withSubs[] = "{$uid}({$n})";
            if ($checkUser && (int) $uid === $checkUser) $reachesCheckUser = true;
        }
        ok("route #{$r['id']} → " . count($ids) . " recipient(s); with LIVE subs: "
            . ($withSubs ? implode(', ', $withSubs) : 'NONE'));
    }
    if (!$anyRecipient) {
        bad("NO enabled route resolves anyone for a new incident — check route enable state + RBAC seeds.");
    }
    if ($checkUser) {
        $reachesCheckUser
            ? ok("user_id {$checkUser} WOULD be a recipient of a new incident push.")
            : bad("user_id {$checkUser} would NOT be resolved for a NEW incident by ANY route. "
                . "Their role likely lacks screen.situation AND widget.incidents (route #160), and "
                . "they aren't on the assignment (route #144). That's why new-incident pushes don't "
                . "arrive even though unit-update pushes (also route #160) do — grant one of those "
                . "screen perms, OR add a route whose recipients include this role.");
    }
    line("  NOTE: this shows recipient RESOLUTION only (no push is sent). If a route resolves");
    line("  you + you have a live sub but a REAL dispatch still sends nothing, then the");
    line("  incident.created event isn't firing on your create path — confirm dispatching writes");
    line("  an audit row (category=incident, activity=create) via Settings → Audit Log.");
    line();
}

// ── Stage 7 (optional): LIVE-fire a synthetic incident.created through the
//    REAL audit → push pipeline, end to end. SENDS REAL PUSH NOTIFICATIONS. ──
// This is the definitive "does the whole chain work on THIS install" test: it
// makes the exact audit_log() call the shared incident writer now makes, so it
// exercises audit → _audit_to_webhook_event mapping → push_fire → router → send.
if (array_search('--fire', $argv, true) !== false) {
    line("Stage 7 — LIVE fire through the real audit->push pipeline");
    warn("this SENDS a REAL push to every resolved recipient that has a live subscription.");
    require_once 'inc/audit.php';
    $simTicket = $ticketId > 0 ? $ticketId
        : (int) db_fetch_value("SELECT id FROM `{$prefix}ticket` ORDER BY id DESC LIMIT 1");
    if (!function_exists('_audit_to_webhook_event')
        && is_file('inc/webhooks.php')) { require_once 'inc/webhooks.php'; }
    if (function_exists('_audit_to_webhook_event')) {
        $mapped = _audit_to_webhook_event('incident', 'create', 'ticket');
        $mapped === 'incident.created'
            ? ok("audit (incident|create|ticket) maps to event 'incident.created' → will fan out to push")
            : bad("audit (incident|create|ticket) maps to '" . var_export($mapped, true)
                . "' (expected 'incident.created') — the mapping is broken on this install.");
    }
    $prevMax = (int) db_fetch_value("SELECT COALESCE(MAX(id),0) FROM `{$prefix}routing_log`");
    audit_log('incident', 'create', 'ticket', $simTicket,
        'PUSH DIAGNOSTIC (GH #8) — NOT a real incident', ['diagnostic' => true, 'ticket_id' => $simTicket]);
    $rows = db_fetch_all(
        "SELECT route_id, dest_channel, status, error FROM `{$prefix}routing_log`
          WHERE id > ? ORDER BY id", [$prevMax]);
    if (!$rows) {
        bad("push_fire produced NO routing_log activity. The audit->push wiring did not run here — "
            . "either push is off/unconfigured (Stage 1), or this install's inc/audit.php predates the "
            . "Phase 96 push fan-out. THIS is the bug if you see it (and unit-update pushes wouldn't work "
            . "either, so double-check those really are arriving).");
    } else {
        $sent = 0;
        foreach ($rows as $r) {
            if (($r['status'] ?? '') === 'forwarded') { $sent++; ok("route #{$r['route_id']} → {$r['dest_channel']}: forwarded"); }
            else { warn("route #{$r['route_id']} → {$r['dest_channel']}: {$r['status']}" . ($r['error'] ? " ({$r['error']})" : "")); }
        }
        $sent > 0
            ? ok("FORWARDED to {$sent} route(s) — a real push was sent to resolved recipients with a live sub.")
            : warn("ran but forwarded to 0 routes — nobody resolved had a live subscription (see Stage 6).");
    }
    line("  (this wrote ONE diagnostic audit row labeled 'PUSH DIAGNOSTIC' against ticket #{$simTicket}.)");
    line();
}

line("Interpretation:");
line("  • All Stage 1-3 OK but no push → the user reporting it is likely not a");
line("    recipient of the specific dispatch (Stage 4), or their device isn't");
line("    subscribed (Stage 3). Run with --ticket=<the incident they tested>.");
line("  • Stage 3 empty on iPhone → it's the home-screen-PWA requirement, not a bug.");
line("  • Stage 1 FAIL → enable push / generate VAPID first; nothing else matters.");
