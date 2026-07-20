<?php
/**
 * NewUI v4.0 API — PAR endpoint (Phase 16a).
 *
 * GET    ?action=cycle&id=N           → cycle summary
 * GET    ?action=for_ticket&ticket=N  → latest cycle for incident + due_at
 * GET    ?action=config               → effective cadence config (admin)
 * POST   action=initiate              → start a PAR cycle (manual/mayday/benchmark)
 * POST   action=ack                   → acknowledge a unit
 * POST   action=abort                 → cancel a cycle
 * POST   action=save_config           → admin save agency-default config
 * POST   action=set_enabled           → admin master switch
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/par.php';
require_once __DIR__ . '/../inc/audit.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = ($method === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
}

// ── GET endpoints ─────────────────────────────────────────────────────
// Phase 16c — mobile clients ask "is there any pending PAR for my unit?"
// Returns the FIRST active cycle (status=pending) where this responder
// has a still-pending ack row. mobile.js polls this every 15s.
if ($method === 'GET' && $action === 'for_responder') {
    $rid = (int) ($_GET['responder_id'] ?? 0);
    if ($rid <= 0) json_error('responder_id required');

    try {
        $row = db_fetch_one(
            "SELECT a.par_cycle_id, a.responder_id, r.name AS unit_name,
                    c.ticket_id, c.initiated_at, c.cycle_window_s
               FROM `{$prefix}par_unit_acks` a
               JOIN `{$prefix}par_cycles`    c ON c.id = a.par_cycle_id
               JOIN `{$prefix}responder`     r ON r.id = a.responder_id
              WHERE a.responder_id = ?
                AND a.state = 'pending'
                AND c.status = 'pending'
              ORDER BY c.initiated_at DESC
              LIMIT 1",
            [$rid]
        );
        if (!$row) {
            json_response(['enabled' => par_enabled(), 'active' => null]);
        }
        $window  = (int) $row['cycle_window_s'];
        $started = strtotime($row['initiated_at']);
        $expires = $started + $window;
        json_response([
            'enabled' => par_enabled(),
            'active'  => [
                'cycle_id'       => (int) $row['par_cycle_id'],
                'responder_id'   => (int) $row['responder_id'],
                'unit_name'      => (string) $row['unit_name'],
                'ticket_id'      => (int) $row['ticket_id'],
                'initiated_at'   => $row['initiated_at'],
                'expires_at'     => $expires,
                'seconds_remaining' => max(0, $expires - time()),
            ],
        ]);
    } catch (Exception $e) {
        json_error($e->getMessage());
    }
}

// Phase 29B (2026-06-12) — PAR-overdue trigger + viewer.
//
// Iterates open incidents. For each one whose next PAR cycle is past
// due, fires an urgent broadcast into the existing internal-messaging
// system IF we haven't broadcast recently (dedup: only re-broadcast
// after one full cadence interval). The notification-tray + bell
// badge + audio + SSE-push pattern already in place delivers the
// alert to every connected dispatcher's screen across every page.
//
// Also returns the list so any admin tooling can display it.
// Originally (Phase 28A) this powered a custom banner that polled
// every 10s; per Eric on 2026-06-12 we pivoted to the messaging
// pattern because reinventing was the wrong call.
if ($method === 'GET' && $action === 'overdue') {
    require_once __DIR__ . '/../inc/incident-number.php';   // incnum_display()
    if (!rbac_can('action.view_incident') && !rbac_can('action.manage_par')) {
        json_error('Forbidden', 403);
    }
    if (!par_enabled()) {
        json_response(['enabled' => false, 'incidents' => [], 'broadcast' => 0]);
    }
    $now = time();
    $out = [];
    $broadcasted = 0;
    try {
        $rows = db_fetch_all(
            "SELECT id, scope, in_types_id, par_last_overdue_broadcast_at
               FROM `{$prefix}ticket`
              WHERE status IN (2, 3)
              ORDER BY id DESC
              LIMIT 200"
        );
        foreach ($rows as $r) {
            $tid = (int) $r['id'];
            $due = par_due_at($tid);
            if ($due === null) continue;            // PAR disabled for this incident or cadence=0
            if ($due > $now)   continue;            // not overdue yet
            $cad = par_resolve_cadence($tid);
            $overdueSecs = $now - $due;

            // Dedup: have we broadcast about this incident already? If yes,
            // only re-broadcast after a full cadence interval has elapsed
            // (so a 20-min cadence renews the alert every 20 min while it
            // remains overdue, instead of every 10 seconds).
            $lastBroadcast = $r['par_last_overdue_broadcast_at'] ?? null;
            $shouldBroadcast = false;
            if (!$lastBroadcast || substr($lastBroadcast, 0, 4) === '0000') {
                $shouldBroadcast = true;       // never broadcast yet
            } else {
                $cadSecs = max(60, (int) $cad['cadence_minutes'] * 60);
                if ((time() - strtotime($lastBroadcast)) >= $cadSecs) {
                    $shouldBroadcast = true;   // overdue long enough for a fresh nudge
                }
            }
            if ($shouldBroadcast) {
                $mid = par_broadcast_overdue($tid, $overdueSecs);
                if ($mid > 0) $broadcasted++;
            }

            $out[] = [
                'ticket_id'         => $tid,
                'incident_number'   => incnum_display((int) $tid),   // #86 — case number for the alarm banner
                'scope'             => (string) ($r['scope'] ?? ''),
                'due_at'            => $due,
                'overdue_seconds'   => $overdueSecs,
                'cadence_minutes'   => (int) $cad['cadence_minutes'],
                'cadence_source'    => (string) $cad['source'],
                'last_broadcast_at' => $lastBroadcast,
            ];
        }
    } catch (Exception $e) {
        json_error($e->getMessage());
    }
    usort($out, function ($a, $b) { return $b['overdue_seconds'] - $a['overdue_seconds']; });
    json_response([
        'enabled'   => true,
        'incidents' => $out,
        'count'     => count($out),
        'broadcast' => $broadcasted,
    ]);
}

if ($method === 'GET' && $action === 'cycle') {
    if (!rbac_can('action.view_incident') && !rbac_can('action.manage_par')) {
        json_error('Forbidden', 403);
    }
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    json_response(par_cycle_summary($id));
}

if ($method === 'GET' && $action === 'for_ticket') {
    if (!rbac_can('action.view_incident') && !rbac_can('action.manage_par')) {
        json_error('Forbidden', 403);
    }
    $ticketId = (int) ($_GET['ticket'] ?? 0);
    if ($ticketId <= 0) json_error('ticket required');

    $cadence = par_resolve_cadence($ticketId);
    $dueAt   = par_due_at($ticketId);

    $latest = null;
    try {
        $row = db_fetch_one(
            "SELECT id FROM `{$prefix}par_cycles`
              WHERE ticket_id = ?
              ORDER BY initiated_at DESC LIMIT 1",
            [$ticketId]
        );
        if ($row) $latest = par_cycle_summary((int) $row['id']);
    } catch (Exception $e) {}

    // Also surface the override value so the UI can populate the
    // inline override input on initial load.
    $override = null;
    try {
        $override = db_fetch_value(
            "SELECT par_cadence_override_min FROM `{$prefix}ticket` WHERE id = ? LIMIT 1",
            [$ticketId]
        );
        if ($override === false) $override = null;
    } catch (Exception $e) {}

    json_response([
        'enabled'    => par_enabled(),
        'cadence'    => $cadence,
        'due_at'     => $dueAt,
        'latest'     => $latest,
        'override'   => $override,
    ]);
}

// Phase 16d — per-incident cadence override.
if ($method === 'POST' && $action === 'set_override') {
    if (!rbac_can('action.manage_par')) json_error('Forbidden', 403);
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) json_error('ticket_id required');
    $minRaw = $input['cadence_minutes'] ?? null;
    $minutes = ($minRaw === '' || $minRaw === null) ? null : max(0, (int) $minRaw);
    try {
        db_query(
            "UPDATE `{$prefix}ticket` SET par_cadence_override_min = ? WHERE id = ?",
            [$minutes, $ticketId]
        );
        audit_log('par', 'set_override', 'ticket', $ticketId,
            "PAR cadence override for incident #{$ticketId}: " .
            ($minutes === null ? 'cleared (use default)' : "{$minutes} min"));
        json_response(['saved' => true, 'cadence_minutes' => $minutes]);
    } catch (Exception $e) { json_error($e->getMessage()); }
}

// Phase 16d — incident PAR history.
if ($method === 'GET' && $action === 'history') {
    if (!rbac_can('action.view_incident') && !rbac_can('action.manage_par')) {
        json_error('Forbidden', 403);
    }
    $ticketId = (int) ($_GET['ticket'] ?? 0);
    if ($ticketId <= 0) json_error('ticket required');
    try {
        $rows = db_fetch_all(
            "SELECT c.id, c.initiated_at, c.initiated_kind, c.status, c.completed_at,
                    (SELECT COUNT(*) FROM `{$prefix}par_unit_acks` a
                      WHERE a.par_cycle_id = c.id) AS units,
                    (SELECT COUNT(*) FROM `{$prefix}par_unit_acks` a
                      WHERE a.par_cycle_id = c.id AND a.state = 'acked') AS acked,
                    (SELECT COUNT(*) FROM `{$prefix}par_unit_acks` a
                      WHERE a.par_cycle_id = c.id AND a.state = 'missed') AS missed
               FROM `{$prefix}par_cycles` c
              WHERE c.ticket_id = ?
              ORDER BY c.initiated_at DESC
              LIMIT 50",
            [$ticketId]
        );
        json_response(['history' => $rows]);
    } catch (Exception $e) { json_error($e->getMessage()); }
}

// Phase 16 follow-on (2026-06-11) — agency PAR compliance report.
// Counts cycles + acks/misses in a date window. CSV format optional.
if ($method === 'GET' && $action === 'report_compliance') {
    if (!rbac_can('action.manage_par') && !rbac_can('action.view_incident') && !is_admin()) {
        json_error('Forbidden', 403);
    }
    $from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        json_error('Invalid date format (YYYY-MM-DD)');
    }
    $fromTs = $from . ' 00:00:00';
    $toTs   = $to   . ' 23:59:59';

    try {
        $total = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_cycles`
              WHERE initiated_at BETWEEN ? AND ?", [$fromTs, $toTs]);

        $acks = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` a
               JOIN `{$prefix}par_cycles` c ON c.id = a.par_cycle_id
              WHERE c.initiated_at BETWEEN ? AND ?
                AND a.state = 'acked'", [$fromTs, $toTs]);

        $missed = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}par_unit_acks` a
               JOIN `{$prefix}par_cycles` c ON c.id = a.par_cycle_id
              WHERE c.initiated_at BETWEEN ? AND ?
                AND a.state = 'missed'", [$fromTs, $toTs]);

        $totalAcks = $acks + $missed;
        $ackRate = $totalAcks > 0 ? round(100.0 * $acks / $totalAcks, 1) : 0;

        // Breakdown by kind
        $byKind = db_fetch_all(
            "SELECT c.initiated_kind AS kind,
                    COUNT(DISTINCT c.id) AS cycles,
                    SUM(CASE WHEN a.state = 'acked'  THEN 1 ELSE 0 END) AS acked,
                    SUM(CASE WHEN a.state = 'missed' THEN 1 ELSE 0 END) AS missed
               FROM `{$prefix}par_cycles` c
               LEFT JOIN `{$prefix}par_unit_acks` a ON a.par_cycle_id = c.id
              WHERE c.initiated_at BETWEEN ? AND ?
              GROUP BY c.initiated_kind
              ORDER BY cycles DESC", [$fromTs, $toTs]);
        foreach ($byKind as &$r) {
            $r['acked']  = (int) $r['acked'];
            $r['missed'] = (int) $r['missed'];
            $r['cycles'] = (int) $r['cycles'];
            $denom = $r['acked'] + $r['missed'];
            $r['ack_rate_pct'] = $denom > 0 ? round(100.0 * $r['acked'] / $denom, 1) : 0;
        }
        unset($r);

        // CSV?
        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="par_compliance_' . $from . '_to_' . $to . '.csv"');
            // Explicit $escape for PHP 8.4+ (deprecation 2026-06)
            $out = fopen('php://output', 'w');
            fputcsv($out, ['kind', 'cycles', 'acked', 'missed', 'ack_rate_pct'], ',', '"', '\\');
            fputcsv($out, ['__total__', $total, $acks, $missed, $ackRate], ',', '"', '\\');
            foreach ($byKind as $r) {
                fputcsv($out, [$r['kind'], $r['cycles'], $r['acked'], $r['missed'], $r['ack_rate_pct']], ',', '"', '\\');
            }
            fclose($out);
            exit;
        }

        json_response([
            'summary' => [
                'from'         => $from,
                'to'           => $to,
                'total_cycles' => $total,
                'acks'         => $acks,
                'missed'       => $missed,
                'ack_rate_pct' => $ackRate,
            ],
            'by_kind' => $byKind,
        ]);
    } catch (Exception $e) {
        json_error($e->getMessage(), 500);
    }
}

if ($method === 'GET' && $action === 'config') {
    if (!is_admin() && !rbac_can('action.manage_par')) json_error('Forbidden', 403);

    $cfg = [];
    try {
        $cfg['enabled'] = (int) db_fetch_value(
            "SELECT value FROM `{$prefix}settings` WHERE name='par_enabled' LIMIT 1") === 1;
        foreach (['par_default_cadence_min','par_first_window_s','par_retry_window_s',
                  'par_max_misses','par_escalation_chat_channel',
                  'par_mayday_auto_trigger','par_standby_unit_behavior'] as $k) {
            $cfg[$k] = db_fetch_value(
                "SELECT value FROM `{$prefix}settings` WHERE name=? LIMIT 1", [$k]);
        }
    } catch (Exception $e) { json_error($e->getMessage()); }

    json_response(['config' => $cfg]);
}

// ── POST endpoints ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'initiate') {
    if (!rbac_can('action.manage_par')) json_error('Forbidden', 403);
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) json_error('ticket_id required');
    $kind = (string) ($input['kind'] ?? 'manual');
    if (!in_array($kind, ['scheduled','manual','mayday','benchmark'], true)) $kind = 'manual';
    $notes = isset($input['notes']) ? (string) $input['notes'] : null;
    $result = par_initiate_cycle($ticketId, $kind, (int) ($_SESSION['user_id'] ?? 0) ?: null, $notes);
    if (isset($result['error'])) json_error($result['error']);
    json_response($result);
}

if ($method === 'POST' && $action === 'ack') {
    $cycleId    = (int) ($input['cycle_id'] ?? 0);
    $responder  = (int) ($input['responder_id'] ?? 0);
    if ($cycleId <= 0 || $responder <= 0) json_error('cycle_id + responder_id required');

    // Issue #22 (a beta tester, 2026-07-02): the Field Unit role — the exact
    // role a phone-carrying responder has — was granted
    // action.change_unit_status but neither action.manage_par nor
    // action.create_incident. The old gate refused those users with
    // 403 even though the whole point of PAR is that the polled unit
    // members hit "yes I'm accounted for" from their phones. Fix:
    // three-tier authorization —
    //   * manage_par         → ack any unit (dispatcher / IC)
    //   * create_incident    → ack any unit (dispatcher-adjacent role)
    //   * change_unit_status → ack ONLY units the caller owns (a
    //                          field responder acknowledging themselves)
    // Ownership is proved by matching the session user against the
    // responder row via the same 3-path lookup api/mobile-data.php uses.
    $can_ack_anyone = rbac_can('action.manage_par') || rbac_can('action.create_incident');
    $can_ack_self   = rbac_can('action.change_unit_status');
    if (!$can_ack_anyone && !$can_ack_self) {
        json_error('Forbidden', 403);
    }
    if (!$can_ack_anyone && !par_user_owns_responder((int) ($_SESSION['user_id'] ?? 0), $responder)) {
        json_error('Forbidden — you can only ack PAR for your own unit', 403);
    }

    $args = [
        'by_user_id'   => (int) ($_SESSION['user_id'] ?? 0) ?: null,
        'via'          => (string) ($input['via'] ?? 'dispatcher_manual'),
        'member_count' => isset($input['member_count']) ? (int) $input['member_count'] : null,
        'comments'     => $input['comments'] ?? null,
        'notes'        => $input['notes'] ?? null,
    ];
    $result = par_ack_unit($cycleId, $responder, $args);
    if (isset($result['error'])) json_error($result['error']);
    json_response($result);
}

if ($method === 'POST' && $action === 'abort') {
    if (!rbac_can('action.manage_par')) json_error('Forbidden', 403);
    $cycleId = (int) ($input['cycle_id'] ?? 0);
    $reason  = isset($input['reason']) ? (string) $input['reason'] : null;
    if ($cycleId <= 0) json_error('cycle_id required');
    if (par_abort_cycle($cycleId, (int) ($_SESSION['user_id'] ?? 0) ?: null, $reason)) {
        json_response(['ok' => true]);
    } else {
        json_error('abort failed');
    }
}

if ($method === 'POST' && $action === 'save_config') {
    if (!is_admin() && !rbac_can('action.manage_par')) json_error('Forbidden', 403);

    $writes = [
        'par_default_cadence_min'     => isset($input['cadence_min']) ? max(0, (int) $input['cadence_min']) : null,
        'par_first_window_s'          => isset($input['first_window_s']) ? max(10, (int) $input['first_window_s']) : null,
        'par_retry_window_s'          => isset($input['retry_window_s']) ? max(10, (int) $input['retry_window_s']) : null,
        'par_max_misses'              => isset($input['max_misses']) ? max(1, (int) $input['max_misses']) : null,
        'par_escalation_chat_channel' => isset($input['chat_channel']) ? (string) $input['chat_channel'] : null,
        'par_standby_unit_behavior'   => isset($input['standby_behavior']) &&
                                          in_array($input['standby_behavior'], ['include','exclude','recommended'], true)
                                          ? $input['standby_behavior'] : null,
        'par_mayday_auto_trigger'     => isset($input['mayday_auto']) ? (((int) $input['mayday_auto']) === 1 ? '1' : '0') : null,
    ];
    try {
        foreach ($writes as $k => $v) {
            if ($v === null) continue;
            db_query(
                "INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$k, (string) $v]
            );
        }
        audit_log('config', 'update', 'par_config', null, 'Updated PAR config', $writes);
        json_response(['saved' => true]);
    } catch (Exception $e) { json_error($e->getMessage()); }
}

if ($method === 'POST' && $action === 'set_enabled') {
    if (!is_admin()) json_error('Forbidden', 403);
    $on = !empty($input['enabled']) ? '1' : '0';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (name, value) VALUES ('par_enabled', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$on]
        );
        audit_log('config', 'update', 'par_enabled', null, "PAR " . ($on === '1' ? 'enabled' : 'disabled'));
        json_response(['enabled' => $on === '1']);
    } catch (Exception $e) { json_error($e->getMessage()); }
}

json_error('Unknown action');
