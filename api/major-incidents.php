<?php
/**
 * NewUI v4.0 API - Major Incidents
 *
 * GET  /api/major-incidents.php             List all major incidents with linked counts
 * GET  /api/major-incidents.php?id=X        Get one major incident with all linked incidents
 * POST /api/major-incidents.php  action=create   Create a new major incident
 * POST /api/major-incidents.php  action=link     Link an incident to a major incident
 * POST /api/major-incidents.php  action=unlink   Unlink an incident from a major incident
 * POST /api/major-incidents.php  action=close    Close a major incident (cascading)
 * POST /api/major-incidents.php  action=update   Update major incident details
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/incident-number.php';   // Phase 99p — incnum_display()

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
                "SELECT l.`id` AS link_id, l.`ticket_id`, l.`linked_by`, l.`linked_at`,
                        t.`incident_number`, t.`scope`, t.`street`, t.`city`, t.`status`, t.`severity`,
                        t.`date`, t.`in_types_id`,
                        u.`user` AS linked_by_name
                   FROM `{$prefix}newui_major_incident_links` l
                   JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
                   LEFT JOIN `{$prefix}user` u ON u.`id` = l.`linked_by`
                  WHERE l.`major_id` = ?
                  ORDER BY l.`linked_at` ASC",
                [$id]
            );
        } catch (Exception $e) {
            $links = [];
        }

        $major['linked_incidents'] = $links;

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

// RBAC: all write actions require action.link_major
if (!rbac_can('action.link_major')) {
    json_error('Insufficient permissions: manage major incidents', 403);
}

$action = trim($input['action'] ?? '');
$now    = date('Y-m-d H:i:s');

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

    try {
        db_query(
            "INSERT INTO `{$prefix}newui_major_incidents`
                (`name`, `description`, `commander`, `severity`, `status`, `lat`, `lng`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?)",
            [$name, $description, $commander, $severity, $lat, $lng, $now, $now]
        );
        $major_id = db_insert_id();
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Database error creating major incident: ' . $e->getMessage(), 500);
    }

    audit_log('incident', 'create', 'major_incident', $major_id, "Created major incident #{$major_id}: {$name}", [
        'severity' => $severity,
        'commander' => $commander,
    ]);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success'  => true,
        'major_id' => (int) $major_id,
        'message'  => "Major incident #{$major_id} created: {$name}",
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
    try {
        $ticket = db_fetch_one(
            "SELECT `id`, `scope` FROM `{$prefix}ticket` WHERE `id` = ?",
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
    $closed_count = 0;
    try {
        $linked = db_fetch_all(
            "SELECT l.`ticket_id`, t.`status`
               FROM `{$prefix}newui_major_incident_links` l
               JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
              WHERE l.`major_id` = ? AND t.`status` = 2",
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
        'name'        => 'string',
        'description' => 'string',
        'commander'   => 'int_null',
        'severity'    => 'int',
        'lat'         => 'float_null',
        'lng'         => 'float_null',
    ];

    $sets    = [];
    $params  = [];
    $changed = [];

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

// ── Unknown action ──
else {
    ini_set('display_errors', $prevDisplay);
    json_error('Unknown action: ' . $action . '. Valid actions: create, link, unlink, close, update');
}
