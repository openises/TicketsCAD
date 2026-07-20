<?php
/**
 * NewUI v4.0 API - Incidents
 *
 * GET /api/incidents.php?func=0&offset=0
 *   func: 0=open+scheduled+recent, 1-9=closed ranges, 10=future scheduled
 *   offset: pagination offset (default 0)
 *
 * Returns JSON with named keys instead of numeric arrays.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/security-labels.php';
// Phase 104b (a beta tester GH #17) — inc/par.php provides par_due_at() so
// we can surface per-incident PAR-overdue state on the incidents API,
// which situation.php reads to render a warning icon.
try { require_once __DIR__ . '/../inc/par.php'; } catch (Throwable $e) {}

// Phase 104d (a beta tester GH #11) — sweep any tickets whose auto-close
// scheduled time has passed BEFORE we render the list. Bounded (20
// per call) so a large backlog can't stall the request. Runs before
// the main query so newly-closed tickets get their status reflected
// in the response we're about to build.
try {
    require_once __DIR__ . '/../inc/auto_close.php';
    auto_close_sweep(20);
} catch (Throwable $e) { error_log('[incidents] auto_close_sweep: ' . $e->getMessage()); }

$func   = (int) ($_GET['func'] ?? 0);
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Text search (for ICS form incident linking, etc.) ──
if (!empty($_GET['search'])) {
    $searchTerm = '%' . trim($_GET['search']) . '%';
    $limit = min((int) ($_GET['limit'] ?? 10), 50);
    $sort = ($_GET['sort'] ?? 'updated') === 'updated' ? 't.updated' : 't.date';
    $dir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    // QA #12 — the search branch previously returned WITHOUT the org-scope and
    // group/RBAC row filters the main path enforces, so any authenticated user
    // could search across other tenants'/groups' incidents. Apply the SAME
    // visibility filter here (org-scope + legacy group), with the allocates
    // join + GROUP BY the main query uses.
    $sParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
    $sFilter = '';
    $sUserGroups = $_SESSION['user_groups'] ?? [];
    $sRbacView = (function_exists('rbac_can')
        && (rbac_can('screen.incidents') || rbac_can('incident.view')));
    if (is_admin() || $sRbacView) {
        $sFilter = '';                       // admin / RBAC-granted: no group filter
    } elseif (!empty($sUserGroups)) {
        $ph = implode(',', array_fill(0, count($sUserGroups), '?'));
        $sFilter = " AND `a`.`group` IN ({$ph})";
        $sParams = array_merge($sParams, $sUserGroups);
    } else {
        $sFilter = ' AND 1=0';               // no view perm, no groups → nothing
    }
    require_once __DIR__ . '/../inc/org-scope.php';
    [$sOrgFrag, $sOrgVars] = org_query_filter('t.org_id');
    if ($sOrgFrag !== '') {
        $sFilter .= $sOrgFrag;
        $sParams = array_merge($sParams, $sOrgVars);
    }
    $sParams[] = $limit;

    $searchRows = db_fetch_all(
        "SELECT t.id, t.scope, t.street, t.city, t.description,
                t.date, t.updated, t.status, t.severity,
                it.type AS type_name
         FROM `{$prefix}ticket` t
         LEFT JOIN `{$prefix}allocates` `a` ON `t`.`id` = `a`.`resource_id` AND `a`.`type` = 1
         LEFT JOIN `{$prefix}in_types` it ON t.in_types_id = it.id
         WHERE (t.street LIKE ? OR t.city LIKE ? OR t.scope LIKE ?
                OR t.description LIKE ? OR CAST(t.id AS CHAR) LIKE ?)
               {$sFilter}
         GROUP BY t.id
         ORDER BY {$sort} {$dir}
         LIMIT ?",
        $sParams
    );

    json_response(['incidents' => $searchRows]);
}

// Pre-fetch action and patient counts per ticket
$acts = [];
$rows = db_fetch_all("SELECT `ticket_id`, COUNT(*) AS `cnt` FROM `{$prefix}action` GROUP BY `ticket_id`");
foreach ($rows as $r) {
    $acts[(int) $r['ticket_id']] = (int) $r['cnt'];
}

$pats = [];
$rows = db_fetch_all("SELECT `ticket_id`, COUNT(*) AS `cnt` FROM `{$prefix}patient` GROUP BY `ticket_id`");
foreach ($rows as $r) {
    $pats[(int) $r['ticket_id']] = (int) $r['cnt'];
}

// Build WHERE clause based on func
$params = [];
$now = date('Y-m-d H:i:s');

if ($func === 0) {
    // Eric 2026-07-07 (training incident #217 "SummerFet"): booked
    // incidents must AUTO-ACTIVATE when their scheduled time arrives.
    // Nothing ever promoted status 3 (scheduled) -> 2 (active), so
    // scheduled events stayed "scheduled" through and past their event.
    // Lazy activation on the hottest read path — every incidents-list
    // request promotes due bookings first. Best-effort; never blocks
    // the listing.
    try {
        $due = db_fetch_all(
            "SELECT `id` FROM `{$prefix}ticket`
              WHERE `status` = 3 AND `booked_date` IS NOT NULL
                AND `booked_date` <= NOW()
                AND (`deleted_at` IS NULL)"
        );
        if ($due) {
            db_query(
                "UPDATE `{$prefix}ticket` SET `status` = 2, `updated` = NOW()
                  WHERE `status` = 3 AND `booked_date` IS NOT NULL
                    AND `booked_date` <= NOW() AND (`deleted_at` IS NULL)"
            );
            if (is_file(__DIR__ . '/../inc/audit.php')) require_once __DIR__ . '/../inc/audit.php';
            if (is_file(__DIR__ . '/../inc/sse.php'))   require_once __DIR__ . '/../inc/sse.php';
            foreach ($due as $d) {
                $tid = (int) $d['id'];
                if (function_exists('audit_log')) {
                    audit_log('incident', 'update', 'ticket', $tid,
                        "Scheduled incident #$tid auto-activated (booked time reached)");
                }
                if (function_exists('sse_publish_for_incident')) {
                    try {
                        sse_publish_for_incident('incident:update',
                            ['ticket_id' => $tid, 'activated' => true], $tid);
                    } catch (Throwable $sseE) { /* non-fatal */ }
                }
            }
        }
    } catch (Exception $e) {
        error_log('[incidents] booked auto-activate failed: ' . $e->getMessage());
    }

    // Open + scheduled (within booking window) + recently closed.
    //
    // 2026-06-11 — recent_close_mins is now user-overridable via the
    // situation page's per-user screen prefs (?recent_close_mins=N).
    // Caps at 7 days (10080 min) to keep query reasonable.
    $booking_hrs = (int) (get_variable('booking_hrs') ?: 24);
    $recent_mins = (int) (get_variable('recent_close_mins') ?: 30);
    if (isset($_GET['recent_close_mins'])) {
        $clientReq = (int) $_GET['recent_close_mins'];
        if ($clientReq >= 0 && $clientReq <= 10080) $recent_mins = $clientReq;
    }
    $where = "WHERE (
        `t`.`status` = 2
        OR `t`.`status` = 3
        OR (`t`.`status` = 1 AND `t`.`problemend` >= DATE_SUB(NOW(), INTERVAL ? MINUTE))
        OR (`t`.`status` = 3 AND `t`.`booked_date` <= DATE_ADD(NOW(), INTERVAL ? HOUR))
    )";
    $params[] = $recent_mins;
    $params[] = $booking_hrs;
} elseif ($func >= 1 && $func <= 9) {
    // Closed incidents within date ranges
    $days_map = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 7, 6 => 14, 7 => 30, 8 => 90, 9 => 365];
    $days = $days_map[$func] ?? 0;
    $where = "WHERE `t`.`status` = 1 AND `t`.`problemend` >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params[] = $days;
} elseif ($func === 10) {
    // Future scheduled
    $booking_hrs = (int) (get_variable('booking_hrs') ?: 24);
    $where = "WHERE `t`.`status` = 3 AND `t`.`booked_date` >= DATE_ADD(NOW(), INTERVAL ? HOUR)";
    $params[] = $booking_hrs;
} else {
    $where = "WHERE `t`.`status` IN (2, 3)";
}

// User group filtering — admins (legacy level 0,1) see all.
//
// Pre-v4 behaviour: non-admins were filtered by their legacy `allocates`
// group memberships, and non-admins with NO group memberships saw nothing
// (AND 1=0). That made sense when groups were the primary auth model.
//
// v4-aware behaviour: if the user holds the RBAC screen.incidents (or
// canonical incidents.view) permission, they have explicit grant via
// RBAC and the legacy group filter is bypassed. Without this bypass,
// newly-created Operator/Dispatcher users with no allocates rows see
// an empty dashboard even though their RBAC role grants the view.
// Caught by Eric on your-server.example.com 2026-05-26 — user 'shoreas'
// with Read-Only RBAC role saw zero incidents.
//
// This is the narrow first step of the row-level filter consolidation
// in specs/future-phases.md (Phase 7d). Full unification of allocates
// + RBAC stays in that phase; here we just stop the false-negative.
$user_groups = $_SESSION['user_groups'] ?? [];
$is_admin = is_admin();
$rbacIncidentView = (function_exists('rbac_can')
    && (rbac_can('screen.incidents') || rbac_can('incident.view')));
$group_filter = '';
if ($is_admin || $rbacIncidentView) {
    // Admin OR RBAC-granted: no legacy group filter.
    $group_filter = '';
} elseif (!empty($user_groups)) {
    // Legacy non-admin with explicit group memberships — filter to them.
    $placeholders = implode(',', array_fill(0, count($user_groups), '?'));
    $group_filter = " AND `a`.`group` IN ({$placeholders})";
    $params = array_merge($params, $user_groups);
} else {
    // Non-admin, no RBAC view perm, no legacy groups — show nothing.
    $group_filter = " AND 1=0";
}

// Phase 99j-4 (Billy beta 2026-06-29) — org-scope filter. Super Admin
// gets an empty fragment (no filter). Org Admin sees own + descendant
// orgs. Ordinary users see their home org. Composes additively with
// the legacy group/RBAC filter above. See specs/phase-99j-org-scoping/.
require_once __DIR__ . '/../inc/org-scope.php';
[$orgFrag, $orgVars] = org_query_filter('t.org_id');
if ($orgFrag !== '') {
    $group_filter .= $orgFrag;
    $params = array_merge($params, $orgVars);
}

// Phase 99o (Eric beta 2026-06-29) — dashboard Incidents widget +
// situation.php both hit THIS endpoint (not api/incident-list.php).
// Phase 99m only added incident_number to incident-list.php, so the
// widget kept showing "—" for every row. Fix: include it here too.
$sql = "SELECT
    `t`.`id` AS `id`,
    `t`.`incident_number`,
    `t`.`scope` AS `scope`,
    `t`.`street` AS `street`,
    `t`.`city` AS `city`,
    `t`.`state` AS `state`,
    `t`.`lat`,
    `t`.`lng`,
    `t`.`severity`,
    `t`.`status`,
    `t`.`description`,
    `it`.`radius`,
    `t`.`date` AS `created`,
    `t`.`updated`,
    `t`.`problemstart`,
    `t`.`problemend`,
    `t`.`booked_date`,
    `it`.`type` AS `incident_type`,
    `it`.`id` AS `type_id`,
    `f`.`name` AS `facility_name`,
    `f`.`lat` AS `facility_lat`,
    `f`.`lng` AS `facility_lng`,
    (SELECT COUNT(*) FROM `{$prefix}assigns`
     WHERE `ticket_id` = `t`.`id` AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00'))
     AS `units_assigned`
FROM `{$prefix}ticket` `t`
LEFT JOIN `{$prefix}allocates` `a` ON `t`.`id` = `a`.`resource_id` AND `a`.`type` = 1
LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
LEFT JOIN `{$prefix}facilities` `f` ON `t`.`rec_facility` = `f`.`id`
{$where} {$group_filter}
GROUP BY `t`.`id`
ORDER BY `t`.`status` DESC, `t`.`severity` DESC, `t`.`updated` DESC
LIMIT 1000 OFFSET ?";

$params[] = $offset;

$rows = db_fetch_all($sql, $params);

// Severity color map
$sev_colors = [
    0 => get_variable('sev_0_color') ?: '#00ff00',
    1 => get_variable('sev_1_color') ?: '#ffff00',
    2 => get_variable('sev_2_color') ?: '#ff0000',
];

$severity_counts = [0 => 0, 1 => 0, 2 => 0];
$incidents = [];

foreach ($rows as $row) {
    $id  = (int) $row['id'];
    $sev = (int) $row['severity'];

    if (isset($severity_counts[$sev])) {
        $severity_counts[$sev]++;
    }

    // Phase 18d — resolve security label and produce display-safe
    // values for surfaces that may run on a wall display. The
    // dispatcher console doesn't filter; situation.php / EOC Display
    // honors the per-label show_scope / show_address columns.
    $sec = seclabel_resolve($id);
    $maskScope   = (int) $sec['eoc_show_scope']   === 0;
    $maskAddress = (int) $sec['eoc_show_address'] === 0;
    $placeholder = $sec['eoc_placeholder_text'] ?: '*** ' . $sec['name'] . ' ***';

    // Phase 104b + Issue #17 reopen (a beta tester, 2026-07-02) — surface
    // PAR-overdue state on the situation row without a second API
    // round-trip. Two independent signals collapse into a single
    // par_overdue_secs field; the larger wins so the row escalates
    // colour on whichever state is more urgent:
    //
    //   A) Next-scheduled cycle is past due
    //      par_due_at() returns null unless PAR is explicitly opted
    //      in (Phase 30A source-whitelist). When it's set and <NOW,
    //      the *upcoming* check is late.
    //
    //   B) An in-flight par_cycle is past its cycle_window_s
    //      A dispatcher hit "Initiate PAR" on this ticket, the row
    //      was written to par_cycles with status='pending', and now
    //      the cycle_window_s has elapsed without every assigned unit
    //      acking. This is the "PAR check is CURRENTLY overdue"
    //      state — the primary case a beta tester's report was about (an
    //      active PAR overdue that the icon logic wasn't picking up
    //      because it ONLY checked signal A).
    $parDueAt      = null;
    $parOverdueSec = 0;
    try {
        if (function_exists('par_due_at')) {
            $parDueAt = par_due_at($id);
            if ($parDueAt !== null && $parDueAt < time()) {
                $parOverdueSec = time() - $parDueAt;
            }
        }
    } catch (Throwable $e) { /* par helper missing/errored — silent */ }
    // Signal B — active-cycle overdue check.
    try {
        $activeCycle = db_fetch_one(
            "SELECT id, UNIX_TIMESTAMP(initiated_at) AS started, cycle_window_s
               FROM `{$prefix}par_cycles`
              WHERE ticket_id = ? AND status = 'pending'
              ORDER BY initiated_at DESC LIMIT 1",
            [$id]
        );
        if ($activeCycle) {
            $activeStarted = (int) $activeCycle['started'];
            $activeWindow  = (int) $activeCycle['cycle_window_s'];
            $activeElapsed = time() - $activeStarted;
            if ($activeElapsed > $activeWindow) {
                $activeOverdue = $activeElapsed - $activeWindow;
                if ($activeOverdue > $parOverdueSec) {
                    $parOverdueSec = $activeOverdue;
                }
            }
        }
    } catch (Throwable $e) { /* par_cycles missing / schema drift — silent */ }

    $incidents[] = [
        'id'              => $id,
        'incident_number' => $row['incident_number'] ?? null,
        'scope'           => $row['scope'],
        'street'          => $row['street'],
        'city'            => $row['city'],
        'state'           => $row['state'],
        'security' => [
            'label_id'           => (int) ($sec['id'] ?? 0),
            'label_code'         => (string) ($sec['code'] ?? ''),
            'label_name'         => (string) ($sec['name'] ?? ''),
            'resolved_from'      => (string) ($sec['_resolved_from'] ?? ''),
            'badge_bg_color'     => (string) ($sec['badge_bg_color']   ?? '#6c757d'),
            'badge_text_color'   => (string) ($sec['badge_text_color'] ?? '#ffffff'),
            'eoc_show_scope'     => (int) $sec['eoc_show_scope'],
            'eoc_show_address'   => (int) $sec['eoc_show_address'],
            'eoc_show_map_marker'=> (string) $sec['eoc_show_map_marker'],
            'eoc_placeholder'    => $placeholder,
        ],
        'scope_display'   => $maskScope   ? $placeholder : (string) $row['scope'],
        'address_display' => $maskAddress ? $placeholder : trim(($row['street'] ?? '') . ' ' . ($row['city'] ?? '')),
        'lat'             => (float) $row['lat'],
        'lng'             => (float) $row['lng'],
        'severity'        => $sev,
        'severity_color'  => $sev_colors[$sev] ?? '#ffffff',
        'status'          => (int) $row['status'],
        'description'     => $row['description'],
        'incident_type'   => $row['incident_type'],
        'type_id'         => (int) ($row['type_id'] ?? 0),
        'radius'          => (float) ($row['radius'] ?? 0),
        'created'         => toIso($row['created']),
        'updated'         => toIso($row['updated']),
        'problemstart'    => toIso($row['problemstart']),
        'problemend'      => toIso($row['problemend']),
        'booked_date'     => toIso($row['booked_date']),
        'actions_count'   => $acts[$id] ?? 0,
        'patients_count'  => $pats[$id] ?? 0,
        'units_assigned'  => (int) $row['units_assigned'],
        'facility_name'   => $row['facility_name'],
        'facility_lat'    => $row['facility_lat'] ? (float) $row['facility_lat'] : null,
        'facility_lng'    => $row['facility_lng'] ? (float) $row['facility_lng'] : null,
        'par_due_at'      => $parDueAt ? gmdate('c', $parDueAt) : null,
        'par_overdue_secs' => $parOverdueSec,
    ];
}

json_response([
    'incidents'       => $incidents,
    'count'           => count($incidents),
    'severity_counts' => $severity_counts,
    'offset'          => $offset,
]);
