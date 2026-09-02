<?php
/**
 * NewUI v4.0 API - Major Incidents
 *
 * GET  /api/major-incidents.php             List all major incidents with linked counts
 * GET  /api/major-incidents.php?id=X        Get one major incident with all linked incidents
 * POST /api/major-incidents.php  action=create          Create a new major incident
 * POST /api/major-incidents.php  action=escalate        Escalate an existing incident into a new major incident
 * POST /api/major-incidents.php  action=link            Link an incident to a major incident
 * POST /api/major-incidents.php  action=unlink           Unlink an incident from a major incident
 * POST /api/major-incidents.php  action=close            Close a major incident (cascading)
 * POST /api/major-incidents.php  action=update           Update major incident details
 * POST /api/major-incidents.php  action=add_command      Add a unified-command roster member
 * POST /api/major-incidents.php  action=remove_command   Remove a unified-command roster member
 *
 * RBAC (Phase 86, 2026-09-02 revision — see specs/phase-86-major-events/
 * changes.md): three tiers, not one blanket permission.
 *   - action.link_major             — link/unlink/update (routine dispatch work)
 *   - action.create_major_event     — create/escalate (supervisor-tier: a
 *     part-time/junior dispatcher must not unilaterally declare a major
 *     event on an ordinary night)
 *   - action.manage_major_event_command — close/add_command/remove_command
 *     (command-tier: managing who is actually in charge of the event)
 * This is a DELIBERATE TIGHTENING versus the single `action.link_major`
 * gate every action shared before this revision — anyone who could
 * previously create/close a major incident via that one broad permission
 * now needs the (Org-Admin-tier-by-default) create/manage permission
 * instead. See changes.md for the full rationale from the design review
 * that recommended it.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/incident-number.php';   // Phase 99p — incnum_display()
require_once __DIR__ . '/../inc/org-scope.php';         // Phase 86 (2026-09-02) — cross-org visibility
require_once __DIR__ . '/../inc/major-events-rollup.php'; // Phase 86 (2026-09-02) — live resource rollup

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

// ══════════════════════════════════════════════════════════════
// GET: List or Detail
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id > 0) {
        // ── Single major incident detail ──
        try {
            $major = db_fetch_one(
                "SELECT m.*, u.`user` AS commander_name
                   FROM `{$prefix}newui_major_incidents` m
                   LEFT JOIN `{$prefix}user` u ON u.`id` = m.`commander`
                  WHERE m.`id` = ?",
                [$id]
            );
        } catch (Exception $e) {
            ini_set('display_errors', $prevDisplay);
            json_error('Database error: ' . $e->getMessage(), 500);
        }

        if (!$major) {
            ini_set('display_errors', $prevDisplay);
            json_error('Major incident not found', 404);
        }

        // Fetch linked incidents
        try {
            $links = db_fetch_all(
                // Phase 99p — surface the case number in the linked-
                // incident list so the major-incident UI can render it.
                // Soft-delete sweep (issue #25 follow-up) — a soft-deleted
                // child incident must not keep showing under the major
                // incident.
                "SELECT l.`id` AS link_id, l.`ticket_id`, l.`linked_by`, l.`linked_at`,
                        t.`incident_number`, t.`scope`, t.`street`, t.`city`, t.`status`, t.`severity`,
                        t.`date`, t.`in_types_id`,
                        u.`user` AS linked_by_name
                   FROM `{$prefix}newui_major_incident_links` l
                   JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
                   LEFT JOIN `{$prefix}user` u ON u.`id` = l.`linked_by`
                  WHERE l.`major_id` = ?
                    AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')
                  ORDER BY l.`linked_at` ASC",
                [$id]
            );
        } catch (Exception $e) {
            $links = [];
        }

        // Phase 86 (2026-09-02) — a linked incident from an org the caller
        // cannot otherwise see must not become visible just because it's
        // linked to a major event the caller CAN see (Priya's review point:
        // linking must never become a bypass of org-scoped ticket
        // visibility). Filtered here rather than at link-time only, since
        // an org's own visibility grants can change after the link was
        // made (a share revoked, a relationship deactivated).
        $links = array_values(array_filter($links, function ($row) {
            return org_can_see_ticket((int) $row['ticket_id']);
        }));

        $major['linked_incidents'] = $links;

        // Phase 86 (2026-09-02) — unified-command roster (a real table, not
        // a JSON blob — see changes.md) and the live resource rollup.
        try {
            $major['command'] = db_fetch_all(
                "SELECT c.`id`, c.`member_id`, c.`external_name`, c.`agency`, c.`role`,
                        c.`joined_at`, c.`left_at`, u.`user` AS member_name
                   FROM `{$prefix}newui_major_incident_command` c
                   LEFT JOIN `{$prefix}user` u ON u.`id` = c.`member_id`
                  WHERE c.`major_incident_id` = ? AND c.`left_at` IS NULL
                  ORDER BY c.`joined_at` ASC",
                [$id]
            );
        } catch (Exception $e) {
            $major['command'] = [];
        }
        $major['resource_rollup'] = major_event_resource_rollup($id);

        ini_set('display_errors', $prevDisplay);
        json_response($major);
    }

    // ── List all major incidents ──
    try {
        $rows = db_fetch_all(
            "SELECT m.*,
                    u.`user` AS commander_name,
                    (SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links` l WHERE l.`major_id` = m.`id`) AS linked_count
               FROM `{$prefix}newui_major_incidents` m
               LEFT JOIN `{$prefix}user` u ON u.`id` = m.`commander`
              ORDER BY m.`status` ASC, m.`created_at` DESC"
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }

    ini_set('display_errors', $prevDisplay);
    json_response($rows);
}

// ══════════════════════════════════════════════════════════════
// POST: Actions (create, link, unlink, close, update)
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ini_set('display_errors', $prevDisplay);
    json_error('GET or POST required', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error('Invalid JSON body');
}

// CSRF check
if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$action = trim($input['action'] ?? '');
$now    = date('Y-m-d H:i:s');

// RBAC: per-action tiers (see the file docblock for the full rationale).
$rbacByAction = [
    'create'          => 'action.create_major_event',
    'escalate'        => 'action.create_major_event',
    'link'            => 'action.link_major',
    'unlink'          => 'action.link_major',
    'update'          => 'action.link_major',
    'close'           => 'action.manage_major_event_command',
    'add_command'     => 'action.manage_major_event_command',
    'remove_command'  => 'action.manage_major_event_command',
];
$requiredPerm = $rbacByAction[$action] ?? null;
if ($requiredPerm === null) {
    ini_set('display_errors', $prevDisplay);
    json_error('Unknown action: ' . $action);
}
if (!rbac_can($requiredPerm)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Insufficient permissions for this action', 403);
}

// ── ACTION: create ──────────────────────────────────────────
if ($action === 'create') {
    $name = trim($input['name'] ?? '');
    if ($name === '') {
        ini_set('display_errors', $prevDisplay);
        json_error('Name is required');
    }

    $description = trim($input['description'] ?? '');
    $commander   = isset($input['commander']) && $input['commander'] !== '' ? (int) $input['commander'] : null;
    $severity    = max(0, min(2, (int) ($input['severity'] ?? 0)));
    $lat         = isset($input['lat']) && $input['lat'] !== '' ? (float) $input['lat'] : null;
    $lng         = isset($input['lng']) && $input['lng'] !== '' ? (float) $input['lng'] : null;

    // Phase 86 (2026-09-02) — optional fields from the design review.
    // event_type is validated against a fixed allowlist (per Marcus's/
    // Walt's review: mutual-aid is a resource arrangement that can apply to
    // ANY type, not a type of its own — it's the separate boolean below).
    $validEventTypes = ['structure-fire', 'mci', 'hazmat', 'severe-weather', 'planned-event', 'other'];
    $eventType = trim((string) ($input['event_type'] ?? ''));
    $eventType = in_array($eventType, $validEventTypes, true) ? $eventType : null;
    $mutualAid = !empty($input['mutual_aid_requested']) ? 1 : 0;
    $boundaryId = isset($input['boundary_id']) && $input['boundary_id'] !== '' ? (int) $input['boundary_id'] : null;

    try {
        db_query(
            "INSERT INTO `{$prefix}newui_major_incidents`
                (`name`, `description`, `commander`, `severity`, `status`, `lat`, `lng`,
                 `event_type`, `mutual_aid_requested`, `boundary_id`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?)",
            [$name, $description, $commander, $severity, $lat, $lng,
             $eventType, $mutualAid, $boundaryId, $now, $now]
        );
        $major_id = db_insert_id();
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error creating major incident: ' . $e->getMessage(), 500);
    }

    audit_log('incident', 'create', 'major_incident', $major_id, "Created major incident #{$major_id}: {$name}", [
        'severity' => $severity,
        'commander' => $commander,
        'event_type' => $eventType,
    ]);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success'  => true,
        'major_id' => (int) $major_id,
        'message'  => "Major incident #{$major_id} created: {$name}",
    ]);
}

// ── ACTION: escalate ────────────────────────────────────────
// Phase 86 (2026-09-02) — one action: creates a new major incident with
// parent_incident_id set to the originating ticket, copies severity/
// location, and links the ticket in the same step. This is a genuinely
// different path from `create` + `link` done manually — it exists so
// "this call is becoming a big deal" is one click, per Dana's/the
// original spec's own success criteria.
elseif ($action === 'escalate') {
    $incident_id = (int) ($input['incident_id'] ?? 0);
    if ($incident_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('incident_id is required');
    }

    try {
        $ticket = db_fetch_one(
            "SELECT `id`, `scope`, `severity`, `lat`, `lng` FROM `{$prefix}ticket`
              WHERE `id` = ?
                AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
            [$incident_id]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }
    if (!$ticket) {
        ini_set('display_errors', $prevDisplay);
        json_error('Incident not found', 404);
    }
    if (!org_can_see_ticket($incident_id)) {
        ini_set('display_errors', $prevDisplay);
        json_error('You do not have visibility into that incident', 403);
    }

    $name = trim($input['name'] ?? '') ?: ('Escalated from ' . incnum_display($incident_id));
    $commander = isset($input['commander']) && $input['commander'] !== '' ? (int) $input['commander'] : null;
    $validEventTypes = ['structure-fire', 'mci', 'hazmat', 'severe-weather', 'planned-event', 'other'];
    $eventType = trim((string) ($input['event_type'] ?? ''));
    $eventType = in_array($eventType, $validEventTypes, true) ? $eventType : null;
    $severity = isset($ticket['severity']) ? max(0, min(2, (int) $ticket['severity'])) : 1;

    try {
        db_query(
            "INSERT INTO `{$prefix}newui_major_incidents`
                (`name`, `description`, `commander`, `severity`, `status`, `lat`, `lng`,
                 `event_type`, `parent_incident_id`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?)",
            [$name, trim($input['description'] ?? ''), $commander, $severity,
             $ticket['lat'], $ticket['lng'], $eventType, $incident_id, $now, $now]
        );
        $major_id = db_insert_id();

        db_query(
            "INSERT INTO `{$prefix}newui_major_incident_links` (`major_id`, `ticket_id`, `linked_by`, `linked_at`)
             VALUES (?, ?, ?, ?)",
            [$major_id, $incident_id, $current_user_id, $now]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error escalating incident: ' . $e->getMessage(), 500);
    }

    audit_log('incident', 'create', 'major_incident', $major_id,
        "Escalated incident " . incnum_display($incident_id) . " to major incident #{$major_id}: {$name}", [
        'parent_incident_id' => $incident_id,
        'event_type' => $eventType,
    ], AUDIT_MEDIUM);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success'  => true,
        'major_id' => (int) $major_id,
        'message'  => "Incident " . incnum_display($incident_id) . " escalated to major incident #{$major_id}: {$name}",
    ]);
}

// ── ACTION: link ────────────────────────────────────────────
elseif ($action === 'link') {
    $major_id  = (int) ($input['major_id'] ?? 0);
    $ticket_id = (int) ($input['ticket_id'] ?? 0);

    if ($major_id <= 0 || $ticket_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('major_id and ticket_id are required');
    }

    // Verify major incident exists and is open
    try {
        $major = db_fetch_one(
            "SELECT `id`, `name`, `status` FROM `{$prefix}newui_major_incidents` WHERE `id` = ?",
            [$major_id]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }
    if (!$major) {
        ini_set('display_errors', $prevDisplay);
        json_error('Major incident not found', 404);
    }
    if ($major['status'] === 'closed') {
        ini_set('display_errors', $prevDisplay);
        json_error('Cannot link to a closed major incident');
    }

    // Verify ticket exists
    //
    // Soft-delete sweep (issue #25 follow-up) — a soft-deleted incident
    // must not be linkable as a child of a major incident.
    try {
        $ticket = db_fetch_one(
            "SELECT `id`, `scope` FROM `{$prefix}ticket`
              WHERE `id` = ?
                AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
            [$ticket_id]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }
    if (!$ticket) {
        ini_set('display_errors', $prevDisplay);
        json_error('Ticket not found', 404);
    }

    // Phase 86 (2026-09-02) — never let a dispatcher link (and thereby
    // expose, per the detail-view filter above) a ticket they cannot
    // otherwise see. This is the write-side half of Priya's review point.
    if (!org_can_see_ticket($ticket_id)) {
        ini_set('display_errors', $prevDisplay);
        json_error('You do not have visibility into that incident', 403);
    }

    // Insert link (unique constraint prevents duplicates)
    try {
        db_query(
            "INSERT INTO `{$prefix}newui_major_incident_links` (`major_id`, `ticket_id`, `linked_by`, `linked_at`)
             VALUES (?, ?, ?, ?)",
            [$major_id, $ticket_id, $current_user_id, $now]
        );
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            ini_set('display_errors', $prevDisplay);
            json_error('Incident is already linked to this major incident');
        }
        ini_set('display_errors', $prevDisplay);
        json_error('Database error linking incident: ' . $e->getMessage(), 500);
    }

    // Update major incident timestamp
    try {
        db_query(
            "UPDATE `{$prefix}newui_major_incidents` SET `updated_at` = ? WHERE `id` = ?",
            [$now, $major_id]
        );
    } catch (Exception $e) {
        // non-fatal
    }

    audit_log('incident', 'assign', 'major_incident', $major_id,
        "Linked ticket #{$ticket_id} to major incident #{$major_id} ({$major['name']})", [
        'ticket_id' => $ticket_id,
        'ticket_scope' => $ticket['scope'],
    ]);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        // Phase 99p — toast uses case number, not internal ids.
        'message' => 'Incident ' . incnum_display((int) $ticket_id) . ' linked to major incident ' . incnum_display((int) $major_id),
    ]);
}

// ── ACTION: unlink ──────────────────────────────────────────
elseif ($action === 'unlink') {
    $major_id  = (int) ($input['major_id'] ?? 0);
    $ticket_id = (int) ($input['ticket_id'] ?? 0);

    if ($major_id <= 0 || $ticket_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('major_id and ticket_id are required');
    }

    // Check link exists
    try {
        $link = db_fetch_one(
            "SELECT `id` FROM `{$prefix}newui_major_incident_links`
              WHERE `major_id` = ? AND `ticket_id` = ?",
            [$major_id, $ticket_id]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }

    if (!$link) {
        ini_set('display_errors', $prevDisplay);
        json_error('Link not found', 404);
    }

    try {
        db_query(
            "DELETE FROM `{$prefix}newui_major_incident_links`
              WHERE `major_id` = ? AND `ticket_id` = ?",
            [$major_id, $ticket_id]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error unlinking incident: ' . $e->getMessage(), 500);
    }

    // Update major incident timestamp
    try {
        db_query(
            "UPDATE `{$prefix}newui_major_incidents` SET `updated_at` = ? WHERE `id` = ?",
            [$now, $major_id]
        );
    } catch (Exception $e) {
        // non-fatal
    }

    audit_log('incident', 'unassign', 'major_incident', $major_id,
        "Unlinked ticket #{$ticket_id} from major incident #{$major_id}", [
        'ticket_id' => $ticket_id,
    ]);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => 'Incident ' . incnum_display((int) $ticket_id) . ' unlinked from major incident ' . incnum_display((int) $major_id),
    ]);
}

// ── ACTION: close ───────────────────────────────────────────
elseif ($action === 'close') {
    $major_id = (int) ($input['major_id'] ?? 0);

    if ($major_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('major_id is required');
    }

    // Verify major incident exists and is open
    try {
        $major = db_fetch_one(
            "SELECT `id`, `name`, `status` FROM `{$prefix}newui_major_incidents` WHERE `id` = ?",
            [$major_id]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }
    if (!$major) {
        ini_set('display_errors', $prevDisplay);
        json_error('Major incident not found', 404);
    }
    if ($major['status'] === 'closed') {
        ini_set('display_errors', $prevDisplay);
        json_error('Major incident is already closed');
    }

    // Close the major incident
    try {
        db_query(
            "UPDATE `{$prefix}newui_major_incidents`
                SET `status` = 'closed', `closed_at` = ?, `updated_at` = ?
              WHERE `id` = ?",
            [$now, $now, $major_id]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error closing major incident: ' . $e->getMessage(), 500);
    }

    // Cascade: close all linked open tickets
    //
    // Soft-delete sweep (issue #25 follow-up) — a soft-deleted child
    // ticket must not be mutated by this cascade (it would flip its
    // status even though it's supposed to be untouched pending recovery
    // from the wastebasket).
    $closed_count = 0;
    try {
        $linked = db_fetch_all(
            "SELECT l.`ticket_id`, t.`status`
               FROM `{$prefix}newui_major_incident_links` l
               JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
              WHERE l.`major_id` = ? AND t.`status` = 2
                AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')",
            [$major_id]
        );

        foreach ($linked as $lt) {
            try {
                db_query(
                    "UPDATE `{$prefix}ticket`
                        SET `status` = 1, `problemend` = ?, `updated` = ?
                      WHERE `id` = ? AND `status` = 2",
                    [$now, $now, (int) $lt['ticket_id']]
                );
                $closed_count++;

                // Log action on each closed ticket (best-effort)
                try {
                    db_query(
                        "INSERT INTO `{$prefix}action`
                            (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`)
                         VALUES (?, ?, ?, ?, 10, ?)",
                        [
                            (int) $lt['ticket_id'],
                            $now,
                            'Closed via major incident #' . $major_id . ' (' . $major['name'] . ')',
                            $current_user_id,
                            $now,
                        ]
                    );
                } catch (Exception $e) {
                    // non-fatal
                }
            } catch (Exception $e) {
                // non-fatal — continue closing others
            }
        }
    } catch (Exception $e) {
        // non-fatal — the major incident itself was closed
    }

    // Phase 86 (2026-09-02) — end any still-active unified-command roster
    // entries (stamp left_at, never DELETE — the history stays for
    // after-action review). Closing the event closes the command
    // structure over it; this is separate from, and does not touch,
    // linked-incident status (the existing cascade above already handles
    // that, deliberately WITHOUT closing tickets a different handler is
    // still actively working — unchanged from before this revision).
    try {
        db_query(
            "UPDATE `{$prefix}newui_major_incident_command` SET `left_at` = ?
              WHERE `major_incident_id` = ? AND `left_at` IS NULL",
            [$now, $major_id]
        );
    } catch (Exception $e) {
        // non-fatal — the major incident itself was closed
    }

    audit_log('incident', 'update', 'major_incident', $major_id,
        "Closed major incident #{$major_id} ({$major['name']}), cascade-closed {$closed_count} tickets", [
        'closed_tickets' => $closed_count,
    ], AUDIT_MEDIUM);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success'        => true,
        'message'        => "Major incident #{$major_id} closed. {$closed_count} linked ticket(s) also closed.",
        'closed_tickets' => $closed_count,
    ]);
}

// ── ACTION: update ──────────────────────────────────────────
elseif ($action === 'update') {
    $major_id = (int) ($input['major_id'] ?? 0);

    if ($major_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('major_id is required');
    }

    // Verify exists
    try {
        $major = db_fetch_one(
            "SELECT `id`, `name` FROM `{$prefix}newui_major_incidents` WHERE `id` = ?",
            [$major_id]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }
    if (!$major) {
        ini_set('display_errors', $prevDisplay);
        json_error('Major incident not found', 404);
    }

    // Build dynamic update
    $allowed = [
        'name'                  => 'string',
        'description'           => 'string',
        'commander'             => 'int_null',
        'severity'              => 'int',
        'lat'                   => 'float_null',
        'lng'                   => 'float_null',
        'event_type'            => 'event_type',
        'mutual_aid_requested'  => 'bool',
    ];

    $sets    = [];
    $params  = [];
    $changed = [];
    $validEventTypes = ['structure-fire', 'mci', 'hazmat', 'severe-weather', 'planned-event', 'other'];

    foreach ($allowed as $key => $type) {
        if (!array_key_exists($key, $input)) continue;

        $val = $input[$key];
        if ($type === 'string') {
            $sets[]   = "`{$key}` = ?";
            $params[] = trim((string) $val);
        } elseif ($type === 'int') {
            $sets[]   = "`{$key}` = ?";
            $params[] = (int) $val;
        } elseif ($type === 'int_null') {
            $sets[]   = "`{$key}` = ?";
            $params[] = ($val !== null && $val !== '') ? (int) $val : null;
        } elseif ($type === 'float_null') {
            $sets[]   = "`{$key}` = ?";
            $params[] = ($val !== null && $val !== '') ? (float) $val : null;
        } elseif ($type === 'event_type') {
            $sets[]   = "`{$key}` = ?";
            $params[] = in_array($val, $validEventTypes, true) ? $val : null;
        } elseif ($type === 'bool') {
            $sets[]   = "`{$key}` = ?";
            $params[] = !empty($val) ? 1 : 0;
        }
        $changed[] = $key;
    }

    if (empty($sets)) {
        ini_set('display_errors', $prevDisplay);
        json_error('No valid fields to update');
    }

    $sets[]   = "`updated_at` = ?";
    $params[] = $now;
    $params[] = $major_id;

    try {
        db_query(
            "UPDATE `{$prefix}newui_major_incidents` SET " . implode(', ', $sets) . " WHERE `id` = ?",
            $params
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error updating major incident: ' . $e->getMessage(), 500);
    }

    audit_log('incident', 'update', 'major_incident', $major_id,
        "Updated major incident #{$major_id}: " . implode(', ', $changed), [
        'fields_changed' => $changed,
    ]);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => "Major incident #{$major_id} updated (" . implode(', ', $changed) . ")",
    ]);
}

// ── ACTION: add_command ─────────────────────────────────────
// Phase 86 (2026-09-02) — a real junction-table row per unified-command
// member, not a JSON array (see changes.md): member_id nullable so an
// external agency's commander with no TicketsCAD account can still be
// recorded via external_name/agency.
elseif ($action === 'add_command') {
    $major_id = (int) ($input['major_id'] ?? 0);
    $agency   = trim($input['agency'] ?? '');
    $role     = trim($input['role'] ?? '') ?: 'incident_commander';
    $memberId = isset($input['member_id']) && $input['member_id'] !== '' ? (int) $input['member_id'] : null;
    $externalName = trim($input['external_name'] ?? '');

    if ($major_id <= 0 || $agency === '') {
        ini_set('display_errors', $prevDisplay);
        json_error('major_id and agency are required');
    }
    if ($memberId === null && $externalName === '') {
        ini_set('display_errors', $prevDisplay);
        json_error('Either member_id or external_name is required');
    }

    try {
        $major = db_fetch_one("SELECT `id`, `name` FROM `{$prefix}newui_major_incidents` WHERE `id` = ?", [$major_id]);
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }
    if (!$major) {
        ini_set('display_errors', $prevDisplay);
        json_error('Major incident not found', 404);
    }

    try {
        db_query(
            "INSERT INTO `{$prefix}newui_major_incident_command`
                (`major_incident_id`, `member_id`, `external_name`, `agency`, `role`, `joined_at`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$major_id, $memberId, $externalName ?: null, $agency, $role, $now]
        );
        $commandId = db_insert_id();
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error adding command member: ' . $e->getMessage(), 500);
    }

    audit_log('incident', 'assign', 'major_incident', $major_id,
        "Added {$agency} ({$role}) to unified command of major incident #{$major_id} ({$major['name']})", [
        'command_id' => $commandId,
        'member_id' => $memberId,
        'agency' => $agency,
    ]);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success'    => true,
        'command_id' => (int) $commandId,
        'message'    => "{$agency} added to unified command",
    ]);
}

// ── ACTION: remove_command ──────────────────────────────────
elseif ($action === 'remove_command') {
    $commandId = (int) ($input['command_id'] ?? 0);
    if ($commandId <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('command_id is required');
    }

    try {
        $row = db_fetch_one(
            "SELECT `id`, `major_incident_id`, `agency` FROM `{$prefix}newui_major_incident_command` WHERE `id` = ?",
            [$commandId]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error: ' . $e->getMessage(), 500);
    }
    if (!$row) {
        ini_set('display_errors', $prevDisplay);
        json_error('Command roster entry not found', 404);
    }

    // Soft-remove: stamp left_at rather than DELETE, so the transfer-of-
    // command history survives for after-action review (Marcus's review
    // point — "who had command when" matters).
    try {
        db_query(
            "UPDATE `{$prefix}newui_major_incident_command` SET `left_at` = ? WHERE `id` = ? AND `left_at` IS NULL",
            [$now, $commandId]
        );
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error removing command member: ' . $e->getMessage(), 500);
    }

    audit_log('incident', 'unassign', 'major_incident', (int) $row['major_incident_id'],
        "Removed {$row['agency']} from unified command of major incident #{$row['major_incident_id']}", [
        'command_id' => $commandId,
    ]);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => "{$row['agency']} removed from unified command",
    ]);
}

// ── Unknown action ──
else {
    ini_set('display_errors', $prevDisplay);
    json_error('Unknown action: ' . $action . '. Valid actions: create, escalate, link, unlink, close, update, add_command, remove_command');
}
