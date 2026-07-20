<?php
/**
 * Phase 94 Stage 2 — Incident write helpers.
 *
 * Pure write functions extracted so both api/incident-create.php
 * (CSRF + session auth) and api/external/v1/incidents.php (bearer
 * token auth) can call the same logic without duplicating it.
 *
 * Per plan.md §2.4: scope LIMITS but RBAC GRANTS. The CSRF/bearer
 * check happens at the caller; the rbac_can() check happens at the
 * caller; THIS file just does the write.
 *
 * History:
 *   2026-06-27 — extracted from api/incident-create.php. Initial
 *                version covers create only. update/delete helpers
 *                land as Stage 4a continues.
 */

declare(strict_types=1);

// Phase 99q-fix (a beta tester beta 2026-06-29) — eagerly load
// inc/incident-number.php so incnum_allocate() is always defined
// when this helper is called. Previously the file relied on
// callers to require it, and api/external/v1/incidents.php didn't
// — so new incidents created via the external API skipped case-
// number allocation and rendered as "#16", "#15" etc. in the UI
// instead of "26-0007".
require_once __DIR__ . '/incident-number.php';

/**
 * Create an incident from a key-value input array. Mirrors the input
 * shape api/incident-create.php expects (in_types_id, contact, street,
 * city, state, phone, address_about, etc., plus the patient_name_N /
 * patient_dob_N / patient_gender_N / patient_desc_N arrays).
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth (this function trusts the input)
 *   - rbac_can('action.create_incident') check
 *   - $_SESSION['user_id'] being set (used for _by attribution)
 *
 * @return array ['id' => int, 'incident_number' => string|null, 'patient_count' => int, 'errors' => string[]]
 *         On validation error: ['errors' => [...]] with no id.
 *         On DB error: throws.
 */
function incident_create_internal(array $input, int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $errors = [];
    $in_types_id = (int) ($input['in_types_id'] ?? 0);
    if ($in_types_id <= 0) {
        $errors[] = 'Incident type is required';
    }
    $scope = trim((string) ($input['scope'] ?? ''));
    if ($scope === '') {
        $errors[] = 'Incident description / scope is required';
    }
    if (!empty($errors)) {
        return ['errors' => $errors];
    }

    // ── Sanitise optional fields ──
    $contact       = trim((string) ($input['contact'] ?? ''));
    $street        = trim((string) ($input['street'] ?? ''));
    $city          = trim((string) ($input['city'] ?? ''));
    $state         = trim((string) ($input['state'] ?? ''));
    $phone         = trim((string) ($input['phone'] ?? ''));
    $address_about = trim((string) ($input['address_about'] ?? ''));
    $affected      = trim((string) ($input['affected'] ?? ''));
    $comments      = trim((string) ($input['comments'] ?? ''));
    $nine_one_one  = trim((string) ($input['nine_one_one'] ?? ''));
    $description   = trim((string) ($input['description'] ?? ''));

    $lat = isset($input['lat']) && $input['lat'] !== '' ? (float) $input['lat'] : null;
    $lng = isset($input['lng']) && $input['lng'] !== '' ? (float) $input['lng'] : null;

    $severity = max(0, min(2, (int) ($input['severity'] ?? 0)));
    $status   = (int) ($input['status'] ?? 2);
    if (!in_array($status, [1, 2, 3], true)) $status = 2;

    $facility     = (int) ($input['facility'] ?? 0);
    $rec_facility = (int) ($input['rec_facility'] ?? 0);

    $now = date('Y-m-d H:i:s');

    $problemstart = !empty($input['problemstart']) ? $input['problemstart'] : $now;
    $problemend   = !empty($input['problemend'])   ? $input['problemend']   : null;
    $booked_date  = !empty($input['booked_date'])  ? $input['booked_date']  : null;

    if ($status === 3 && empty($booked_date)) {
        return ['errors' => ['Booked date is required for scheduled incidents']];
    }

    $to_address = trim((string) ($input['to_address'] ?? ''));
    $signal     = trim((string) ($input['signal'] ?? ''));

    // Auto-set severity from incident type if configured
    try {
        $type_row = db_fetch_one(
            "SELECT `set_severity` FROM `{$prefix}in_types` WHERE `id` = ?",
            [$in_types_id]
        );
        if ($type_row && (int) $type_row['set_severity'] > 0) {
            $severity = (int) $type_row['set_severity'];
        }
    } catch (Exception $e) { /* in_types may differ on legacy installs */ }

    // ── Insert ticket (with org_id fallback) ──
    // Phase 99j-4 (Billy beta 2026-06-29) — default the new ticket's
    // org to the user's home org so newly-created incidents land in
    // the right tenant from the start, instead of NULL (which leaks
    // them as cross-org via the legacy `OR org_id IS NULL` clause).
    // session.active_org_id (if set by the UI) still wins; otherwise
    // org_user_home_id() resolves from user.home_org_id.
    require_once __DIR__ . '/org-scope.php';
    $orgId = isset($_SESSION['active_org_id']) ? (int) $_SESSION['active_org_id'] : null;
    if ($orgId === null) {
        try { $orgId = org_user_home_id((int) $userId); } catch (Exception $e) { $orgId = null; }
    }
    $ticket_id = 0;

    try {
        $sql = "INSERT INTO `{$prefix}ticket`
            (`in_types_id`, `contact`, `street`, `city`, `state`, `phone`,
             `address_about`, `lat`, `lng`, `scope`, `affected`, `description`,
             `comments`, `nine_one_one`, `to_address`, `status`, `severity`, `facility`,
             `rec_facility`, `date`, `problemstart`, `problemend`, `booked_date`,
             `updated`, `_by`, `owner`, `org_id`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)";
        $params = [
            $in_types_id, $contact, $street, $city, $state, $phone,
            $address_about, $lat, $lng, $scope, $affected, $description,
            $comments, $nine_one_one, $to_address, $status, $severity, $facility,
            $rec_facility, $now, $problemstart, $problemend, $booked_date,
            $now, $userId, $orgId,
        ];
        db_query($sql, $params);
        $ticket_id = (int) db_insert_id();
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'org_id') !== false) {
            // Fallback for pre-org_id installs
            $sql = "INSERT INTO `{$prefix}ticket`
                (`in_types_id`, `contact`, `street`, `city`, `state`, `phone`,
                 `address_about`, `lat`, `lng`, `scope`, `affected`, `description`,
                 `comments`, `nine_one_one`, `to_address`, `status`, `severity`, `facility`,
                 `rec_facility`, `date`, `problemstart`, `problemend`, `booked_date`,
                 `updated`, `_by`, `owner`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
            $params = [
                $in_types_id, $contact, $street, $city, $state, $phone,
                $address_about, $lat, $lng, $scope, $affected, $description,
                $comments, $nine_one_one, $to_address, $status, $severity, $facility,
                $rec_facility, $now, $problemstart, $problemend, $booked_date,
                $now, $userId,
            ];
            db_query($sql, $params);
            $ticket_id = (int) db_insert_id();
        } else {
            throw $e;
        }
    }

    if (!$ticket_id) {
        return ['errors' => ['Failed to create incident — no id returned']];
    }

    // ── Allocate to user's groups (best-effort) ──
    $user_groups = $_SESSION['user_groups'] ?? [];
    foreach ($user_groups as $gid) {
        try {
            db_query(
                "INSERT INTO `{$prefix}allocates` (`group`, `type`, `al_as_of`, `resource_id`, `user_id`)
                 VALUES (?, 1, ?, ?, ?)",
                [(int) $gid, $now, $ticket_id, $userId]
            );
        } catch (Exception $e) { /* allocates schema may differ */ }
    }

    // ── Initial action record ──
    try {
        db_query(
            "INSERT INTO `{$prefix}action` (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`)
             VALUES (?, ?, ?, ?, 100, ?)",
            [$ticket_id, $now, 'Incident created', $userId, $now]
        );
    } catch (Exception $e) { /* action table may differ */ }

    // ── Log entry ──
    try {
        db_query(
            "INSERT INTO `{$prefix}log` (`who`, `from`, `when`, `code`, `ticket_id`, `info`)
             VALUES (?, ?, ?, 10, ?, ?)",
            [$userId, $_SERVER['REMOTE_ADDR'] ?? '', $now, $ticket_id, $scope]
        );
    } catch (Exception $e) { /* log table may differ */ }

    // ── Assign initial responders ──
    //
    // Issue #47 (a beta tester 2026-07-03): the loop below used to do a raw
    // INSERT into `assigns` only, which correctly created the
    // assignment row but never updated the responder's un_status_id.
    // Result: dispatching a unit as part of incident creation left
    // the unit displayed as "Available" instead of "Dispatched" — a
    // dispatcher watching the unit board would think the unit wasn't
    // actually dispatched. The manual "assign after creation" path
    // (via api/incident-assign.php) delegates to
    // assign_create_internal() in inc/assignment-write.php which
    // stamps the responder's status, writes the action-log entry,
    // rejects duplicates, and cancels any auto-close grace window.
    // Delegate to the same helper here so the two dispatch paths
    // behave identically.
    $assign_ids = $input['assign_responders'] ?? [];
    if (is_array($assign_ids) && $assign_ids) {
        require_once __DIR__ . '/assignment-write.php';
        foreach ($assign_ids as $rid) {
            $rid = (int) $rid;
            if ($rid > 0) {
                try {
                    assign_create_internal($ticket_id, $rid, '', $userId);
                } catch (Throwable $e) {
                    // assigns table may differ on very old installs;
                    // don't fail the incident create just because one
                    // dispatch call didn't stick.
                    error_log('[incident-write] assign_create_internal failed for responder ' . $rid . ': ' . $e->getMessage());
                }
            }
        }
    }

    // ── Stamp incident number (Phase 15) ──
    $incidentNumber = null;
    if (function_exists('incnum_allocate')) {
        try {
            $incnum = incnum_allocate();
            if (!empty($incnum['number'])) {
                $incidentNumber = $incnum['number'];
                db_query(
                    "UPDATE `{$prefix}ticket` SET `incident_number` = ? WHERE `id` = ?",
                    [$incidentNumber, $ticket_id]
                );
            }
        } catch (Exception $e) { /* pre-Phase-15 column missing */ }
    }

    // ── Stamp the signal code (2026-06-27 fix) ──
    if ($signal !== '') {
        try {
            db_query(
                "UPDATE `{$prefix}ticket` SET `signal` = ? WHERE `id` = ?",
                [$signal, $ticket_id]
            );
        } catch (Exception $e) { /* column missing — pre-Phase-94 install */ }
    }

    // ── Persist patients (2026-06-27 fix) ──
    $patientCount = 0;
    foreach ($input as $key => $val) {
        if (!preg_match('/^patient_name_(\d+)$/', $key, $m)) continue;
        $idx = $m[1];
        $pname = trim((string) $val);
        if ($pname === '') continue;
        $pdob = trim((string) ($input['patient_dob_' . $idx] ?? ''));
        $pgen = (int) ($input['patient_gender_' . $idx] ?? 0);
        $pdesc = trim((string) ($input['patient_desc_' . $idx] ?? ''));
        try {
            db_query(
                "INSERT INTO `{$prefix}patient`
                 (`ticket_id`, `name`, `fullname`, `dob`, `gender`, `description`, `date`, `user`, `updated`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$ticket_id, $pname, $pname, $pdob, $pgen, $pdesc, $now, $userId, $now]
            );
            $patientCount++;
        } catch (Exception $e) { /* patient table may be missing on very old installs */ }
    }

    // ── Audit the create CENTRALLY (GH #8, 2026-07-14) ──
    // The audit row is what drives the outbound webhook + Web Push fan-out
    // (inc/audit.php maps incident|create|ticket → incident.created → push_fire).
    // Doing it HERE, in the shared writer, means EVERY create path fires it —
    // the New Incident form, the external API, message→incident routing, and
    // any future path — instead of each caller having to remember. Before this,
    // only the form + external API audited, so incidents created any other way
    // (e.g. message-tray sub-incidents) never notified dispatchers. Callers must
    // NOT also audit incident|create|ticket or the push double-fires.
    if (!function_exists('audit_log') && is_file(__DIR__ . '/audit.php')) {
        require_once __DIR__ . '/audit.php';
    }
    if (function_exists('audit_log')) {
        $assignIds = $input['assign_responders'] ?? [];
        audit_log('incident', 'create', 'ticket', $ticket_id,
            "Created incident #{$ticket_id}: {$scope}", [
                'in_types_id'     => $in_types_id,
                'severity'        => $severity,
                'status'          => $status,
                'signal'          => $signal !== '' ? $signal : null,
                'patient_count'   => $patientCount,
                'assigned_count'  => is_array($assignIds) ? count($assignIds) : 0,
                'incident_number' => $incidentNumber,
            ]);
    }

    return [
        'id'              => $ticket_id,
        'incident_number' => $incidentNumber,
        'patient_count'   => $patientCount,
        'errors'          => [],
    ];
}

/**
 * Phase 111 Slice A — does the `action` table carry the message-source
 * attribution columns (source_channel, source_message_id,
 * author_member_id) added by sql/run_message_incident_link.php?
 *
 * Probed once per process and cached. A failed probe returns false so the
 * note writer degrades to the original bare INSERT rather than 1054-erroring
 * on a column that doesn't exist. All three must be present for the meta
 * INSERT to be used.
 */
function _incident_action_has_source_cols(): bool {
    static $has = null;
    if ($has !== null) return $has;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $count = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME IN ('source_channel','source_message_id','author_member_id')",
            [$prefix . 'action']
        );
        $has = ($count === 3);
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * Add a free-text activity note to an incident's action log.
 *
 * Mirrors api/incident-update.php's `add_note` action body:
 *   - INSERT into the `action` table with action_type = 0 (general note)
 *   - Touch the parent ticket's `updated` timestamp
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.add_note') OR rbac_can('action.edit_incident') check
 *   - Verifying the ticket exists (this helper does not re-query for it)
 *
 * On success returns ['id' => <newActionId>, 'errors' => []].
 * On validation error returns ['errors' => [...]] with no id.
 * On DB error throws.
 *
 * Phase 94 Stage 4b extraction (2026-06-27 → 2026-06-28).
 *
 * Phase 111 Slice A (2026-07-04) — OPTIONAL trailing $meta arg carries
 * message-source attribution so an auto-logged inbound message records
 * where it came from and who reported it:
 *
 *   $meta = [
 *     'source_channel'    => 'zello',   // meshtastic|zello|dmr|aprs|local_chat|manual
 *     'source_message_id' => 42,        // messages.id it originated from
 *     'author_member_id'  => 7,         // resolved sender (Link 1), or null
 *   ]
 *
 * These columns are added by sql/run_message_incident_link.php. On an
 * install that hasn't run that migration the columns are absent, so we
 * probe once (static-cached) and only include them in the INSERT when they
 * all exist. EXISTING CALLERS pass no $meta ([]), so this is a pure no-op
 * for them — the INSERT is byte-for-byte the original three-arg one.
 */
function incident_add_note_internal(int $ticketId, string $note, int $userId, array $meta = []): array {
    $note = trim($note);
    if ($ticketId <= 0) {
        return ['errors' => ['Invalid ticket ID']];
    }
    if ($note === '') {
        return ['errors' => ['Note text is required']];
    }

    $now = date('Y-m-d H:i:s');
    $actionTbl = db_table('action');
    $ticketTbl = db_table('ticket');

    // Phase 111 — decide whether to write the source/author metadata.
    // Only when (a) the caller supplied at least one meta key AND (b) the
    // action table actually has all three columns (older installs lack
    // them). Otherwise fall through to the original bare INSERT so
    // existing callers are completely unaffected.
    $writeMeta = !empty($meta) && _incident_action_has_source_cols();

    if ($writeMeta) {
        $srcChannel = isset($meta['source_channel']) && $meta['source_channel'] !== ''
            ? substr((string) $meta['source_channel'], 0, 32) : null;
        $srcMsgId   = isset($meta['source_message_id']) && $meta['source_message_id'] !== null
            ? (int) $meta['source_message_id'] : null;
        $authorMid  = isset($meta['author_member_id']) && $meta['author_member_id'] !== null
            ? (int) $meta['author_member_id'] : null;

        db_query(
            "INSERT INTO {$actionTbl}
                (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`,
                 `source_channel`, `source_message_id`, `author_member_id`)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)",
            [$ticketId, $now, $note, $userId, $now, $srcChannel, $srcMsgId, $authorMid]
        );
    } else {
        // action_type = 0 is the legacy "general note" code (mirrors
        // api/incident-update.php's add_note path).
        db_query(
            "INSERT INTO {$actionTbl}
                (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`)
             VALUES (?, ?, ?, ?, 0, ?)",
            [$ticketId, $now, $note, $userId, $now]
        );
    }
    $newId = (int) db_insert_id();

    // Touch parent ticket so list views re-sort (best-effort).
    try {
        db_query(
            "UPDATE {$ticketTbl} SET `updated` = ? WHERE `id` = ?",
            [$now, $ticketId]
        );
    } catch (Exception $e) { /* non-fatal */ }

    return [
        'id'     => $newId,
        'errors' => [],
    ];
}

/**
 * Update selected fields on an existing incident. Mirrors
 * api/incident-update.php's `update_fields` action body, with the
 * SAME whitelist of editable columns (severity, description, scope,
 * contact, phone, nine_one_one, street, city, state, address_about,
 * to_address, comments, affected, facility, rec_facility).
 *
 * Unrecognized fields are silently ignored (NOT an error) so partial-
 * update PATCH semantics work cleanly — clients pass whatever they
 * want changed, the rest stays untouched.
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.edit_incident') check
 *   - $_SESSION['user_id'] for the action-log attribution
 *
 * @return array ['id' => int, 'fields_changed' => string[], 'errors' => string[]]
 *         If no whitelisted fields were sent, returns errors with no
 *         changes attempted.
 */
function incident_update_fields_internal(int $ticketId, array $fields, int $userId): array {
    if ($ticketId <= 0) {
        return ['errors' => ['invalid ticket_id']];
    }
    if (empty($fields)) {
        return ['errors' => ['no fields to update']];
    }

    // Same whitelist as api/incident-update.php's update_fields action.
    // Keep these two in sync. If you add a new editable field here,
    // also add it to the internal endpoint AND the EXTERNAL-API.md
    // docs.
    static $allowed = [
        'severity'      => 'int',
        'description'   => 'string',
        'scope'         => 'string',
        'contact'       => 'string',
        'phone'         => 'string',
        'nine_one_one'  => 'string',
        'street'        => 'string',
        'city'          => 'string',
        'state'         => 'string',
        'address_about' => 'string',
        'to_address'    => 'string',
        'comments'      => 'string',
        'affected'      => 'string',
        'facility'      => 'int',
        'rec_facility'  => 'int',
        // 2026-06-28 — allow lat/lng updates on existing incidents.
        // Use case (Eric): SAR in a large park, dispatcher drops
        // a pin on the incident-detail map when the victim's
        // position is reported by field responders. Without this,
        // lat/lng were only writable at create time.
        'lat'           => 'float',
        'lng'           => 'float',
    ];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');

    $sets = [];
    $params = [];
    $changed = [];
    foreach ($fields as $key => $value) {
        if (!isset($allowed[$key])) continue;
        $type = $allowed[$key];
        if ($type === 'int') {
            $sets[] = "`{$key}` = ?";
            $params[] = (int) $value;
        } elseif ($type === 'float') {
            // 2026-06-28 — lat/lng. Empty string OR null clears the field
            // (NULL in DB). Numeric input casts to float with light range
            // validation to prevent garbage / spoofing.
            if ($value === '' || $value === null) {
                $sets[] = "`{$key}` = NULL";
            } else {
                $f = (float) $value;
                if ($key === 'lat' && ($f < -90 || $f > 90))   { continue; }
                if ($key === 'lng' && ($f < -180 || $f > 180)) { continue; }
                $sets[] = "`{$key}` = ?";
                $params[] = $f;
            }
        } else {
            $sets[] = "`{$key}` = ?";
            $params[] = trim((string) $value);
        }
        $changed[] = $key;
    }
    if (empty($sets)) {
        return ['id' => $ticketId, 'fields_changed' => [], 'errors' => ['no whitelisted fields in request']];
    }

    $sets[] = "`updated` = ?";
    $params[] = $now;
    $params[] = $ticketId;

    try {
        db_query(
            "UPDATE `{$prefix}ticket` SET " . implode(', ', $sets) . " WHERE `id` = ?",
            $params
        );
    } catch (Exception $e) {
        return ['id' => $ticketId, 'fields_changed' => [], 'errors' => ['update failed: ' . $e->getMessage()]];
    }

    // Log to the per-incident action table (best-effort, mirrors the
    // internal endpoint's logAction helper).
    try {
        db_query(
            "INSERT INTO `{$prefix}action` (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`)
             VALUES (?, ?, ?, ?, 11, ?)",
            [$ticketId, $now, 'Updated: ' . implode(', ', $changed), $userId, $now]
        );
    } catch (Exception $e) { /* action table differs on legacy installs */ }

    return ['id' => $ticketId, 'fields_changed' => $changed, 'errors' => []];
}

/**
 * Clear every still-open assigns row on a ticket and reset the affected
 * responders back to "Available". This is the close-cascade body, pulled
 * out of incident_update_status_internal() so it can also run as a
 * standalone repair (GH report, Eric 2026-07-07: ticket 131 was closed
 * on 2026-06-24 — four days BEFORE the cascade existed — leaving unit M1
 * pinned "Responding" on a closed incident with no way to fix it from
 * the UI, because re-closing a closed incident was rejected outright).
 *
 * A responder is reset to Available only when ALL of these hold:
 *   - they have no OTHER active assignment on a different ticket
 *   - their current un_status is not flagged excl_from_reset = 'y'
 *     (the config UI has always offered this flag; it is now honored)
 *   - in conservative mode, their current status looks on-call
 *     (incident_action dispatched/responding/on_scene, a busy-ish
 *     group, or a call-ish name). Conservative mode is for heal/repair
 *     paths where time has passed since the close — a dispatcher may
 *     have deliberately set the unit's status in the meantime, and a
 *     repair must not stomp that.
 *
 * @param int   $ticketId Ticket whose open assignments get cleared
 * @param int   $userId   Acting user (0 = system/repair) for the action row
 * @param array $opts     'conservative' => bool  (default false)
 *                        'clear_time'   => 'Y-m-d H:i:s' stamp for assigns.clear
 *                                          (default NOW(); repair passes the
 *                                          ticket's original close time so
 *                                          time-on-task reports stay truthful)
 *                        'action_note'  => string for the action_type-23 row
 * @return array ['cleared_assigns' => int, 'reset_responders' => int, 'errors' => string[]]
 */
function incident_clear_stragglers(int $ticketId, int $userId, array $opts = []): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    $clearTime = trim((string) ($opts['clear_time'] ?? '')) ?: $now;
    $conservative = !empty($opts['conservative']);
    $actionNote = trim((string) ($opts['action_note'] ?? '')) ?: 'All responders auto-cleared on close';

    if ($ticketId <= 0) {
        return ['cleared_assigns' => 0, 'reset_responders' => 0, 'errors' => ['invalid ticket_id']];
    }

    $activeAssigns = [];
    try {
        $activeAssigns = db_fetch_all(
            "SELECT `id`, `responder_id` FROM `{$prefix}assigns`
             WHERE `ticket_id` = ?
               AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')",
            [$ticketId]
        );
    } catch (Exception $e) {
        return ['cleared_assigns' => 0, 'reset_responders' => 0,
                'errors' => ['assigns lookup failed: ' . $e->getMessage()]];
    }

    if (empty($activeAssigns)) {
        return ['cleared_assigns' => 0, 'reset_responders' => 0, 'errors' => []];
    }

    try {
        db_query(
            "UPDATE `{$prefix}assigns` SET `clear` = ?
             WHERE `ticket_id` = ?
               AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')",
            [$clearTime, $ticketId]
        );
    } catch (Exception $e) {
        return ['cleared_assigns' => 0, 'reset_responders' => 0,
                'errors' => ['assigns clear failed: ' . $e->getMessage()]];
    }
    $clearedAssigns = count($activeAssigns);
    $resetResponders = 0;

    // Look up the "Available" un_status id. Prefix matches only — the old
    // LIKE '%avail%' also matched "Unavailable", so on installs where the
    // Unavailable row sorts first, closing an incident put every unit OUT
    // OF SERVICE. Prefer the semantic `group` column, then the name.
    $availableStatusId = 1;
    try {
        $row = db_fetch_one(
            "SELECT `id` FROM `{$prefix}un_status`
             WHERE LOWER(`group`) LIKE 'av%' OR LOWER(`status_val`) LIKE 'avail%'
             ORDER BY (LOWER(`group`) LIKE 'av%') DESC, `id` LIMIT 1"
        );
        if ($row) $availableStatusId = (int) $row['id'];
    } catch (Exception $e) { /* table differs on legacy installs */ }

    foreach ($activeAssigns as $aa) {
        $rid = (int) $aa['responder_id'];
        if ($rid <= 0) continue;
        try {
            $other = db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}assigns`
                 WHERE `responder_id` = ?
                   AND `ticket_id` != ?
                   AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')",
                [$rid, $ticketId]
            );
            if ((int) $other !== 0) continue;

            // Inspect the responder's CURRENT status. Best-effort: if the
            // lookup fails (legacy schema), fall back to the historical
            // behavior — reset unconditionally in live mode, skip in
            // conservative mode (repair must not guess).
            $cur = null;
            try {
                $cur = db_fetch_one(
                    "SELECT s.`status_val`, s.`group`, s.`incident_action`, s.`excl_from_reset`
                     FROM `{$prefix}responder` r
                     LEFT JOIN `{$prefix}un_status` s ON s.`id` = r.`un_status_id`
                     WHERE r.`id` = ?",
                    [$rid]
                );
            } catch (Exception $e) { /* legacy schema */ }

            if ($cur !== null && strtolower((string) ($cur['excl_from_reset'] ?? 'n')) === 'y') {
                continue; // status explicitly opted out of auto-reset
            }
            if ($conservative) {
                if ($cur === null) continue;
                $ia = strtolower((string) ($cur['incident_action'] ?? ''));
                $grp = strtolower((string) ($cur['group'] ?? ''));
                $name = strtolower((string) ($cur['status_val'] ?? ''));
                $onCall = in_array($ia, ['dispatched', 'responding', 'on_scene'], true)
                    || preg_match('/^(busy|call|disp|enrt)/', $grp)
                    || preg_match('/respond|dispatch|route|scene|transport|busy/', $name);
                if (!$onCall) continue; // dispatcher set this deliberately — leave it
            }

            db_query(
                "UPDATE `{$prefix}responder` SET `un_status_id` = ?, `status_updated` = ? WHERE `id` = ?",
                [$availableStatusId, $now, $rid]
            );
            $resetResponders++;

            // Real-time push so open unit widgets / situation boards see
            // the reset without a manual refresh (best-effort).
            if (is_file(__DIR__ . '/sse.php')) require_once __DIR__ . '/sse.php';
            if (function_exists('sse_publish_for_incident')) {
                try {
                    sse_publish_for_incident('responder:status', [
                        'ticket_id'    => $ticketId,
                        'responder_id' => $rid,
                        'status'       => 'Available',
                    ], $ticketId);
                } catch (Throwable $sseE) { /* non-fatal */ }
            }
        } catch (Exception $e) { /* non-fatal per-responder */ }
    }

    // action_type 23 = "All responders auto-cleared on close"
    try {
        db_query(
            "INSERT INTO `{$prefix}action` (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`)
             VALUES (?, ?, ?, ?, 23, ?)",
            [$ticketId, $now, $actionNote, $userId, $now]
        );
    } catch (Exception $e) { /* non-fatal */ }

    return ['cleared_assigns' => $clearedAssigns, 'reset_responders' => $resetResponders, 'errors' => []];
}

/**
 * Change an incident's status — handles the three legal transitions
 * (1=Closed, 2=Open, 3=Scheduled) with all the side-effects
 * api/incident-update.php's update_status branch used to do inline:
 *
 *   - Closing (status=1): stamps problemend = NOW(), auto-clears every
 *     active assigns row on this ticket, and resets each affected
 *     responder's un_status_id back to "Available" UNLESS the responder
 *     still has another active assignment elsewhere.
 *   - Reopening (status=2): clears problemend back to NULL.
 *   - Scheduling (status=3): stamps booked_date (caller-supplied).
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.close_incident') check
 *   - Pre-fetching the ticket row for "already-in-status" / "not-found"
 *     guards — this helper does NOT re-query for the current status
 *     (so the caller can short-circuit those checks with its own
 *     friendlier error messages).
 *
 * @param int   $ticketId        Ticket id to mutate
 * @param int   $newStatus       1=Closed, 2=Open, 3=Scheduled
 * @param int   $userId          Acting user (for the action-log row)
 * @param array $extra           Per-transition extras:
 *                                 ['booked_date' => 'YYYY-MM-DD HH:MM:SS']
 *                                 required when $newStatus === 3
 * @return array {
 *   'updated'           => bool   true on a real status flip
 *   'cleared_assigns'   => int    rows in `assigns` marked cleared (close only)
 *   'reset_responders'  => int    responders flipped back to Available (close only)
 *   'errors'            => string[] Validation/DB errors
 * }
 *
 * Phase 94 Stage 4j extraction (2026-06-28).
 */
function incident_update_status_internal(int $ticketId, int $newStatus, int $userId, array $extra = []): array {
    static $validStatuses = [1, 2, 3]; // Closed, Open, Scheduled

    if ($ticketId <= 0) {
        return ['updated' => false, 'cleared_assigns' => 0, 'reset_responders' => 0,
                'errors' => ['invalid ticket_id']];
    }
    if (!in_array($newStatus, $validStatuses, true)) {
        return ['updated' => false, 'cleared_assigns' => 0, 'reset_responders' => 0,
                'errors' => ['invalid status: must be 1, 2, or 3']];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    $clearedAssigns = 0;
    $resetResponders = 0;

    try {
        if ($newStatus === 1) {
            // ── Closing: stamp problemend + cascade-clear assignments ──
            db_query(
                "UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = ?, `updated` = ? WHERE `id` = ?",
                [$now, $now, $ticketId]
            );

            $cascade = incident_clear_stragglers($ticketId, $userId);
            $clearedAssigns  = (int) $cascade['cleared_assigns'];
            $resetResponders = (int) $cascade['reset_responders'];
        } elseif ($newStatus === 2) {
            // ── Reopening: clear problemend ──
            db_query(
                "UPDATE `{$prefix}ticket` SET `status` = 2, `problemend` = NULL, `updated` = ? WHERE `id` = ?",
                [$now, $ticketId]
            );
        } elseif ($newStatus === 3) {
            // ── Scheduling: requires booked_date in $extra ──
            $booked = trim((string) ($extra['booked_date'] ?? ''));
            if ($booked === '') {
                return ['updated' => false, 'cleared_assigns' => 0, 'reset_responders' => 0,
                        'errors' => ['booked_date is required for scheduled status']];
            }
            db_query(
                "UPDATE `{$prefix}ticket` SET `status` = 3, `booked_date` = ?, `updated` = ? WHERE `id` = ?",
                [$booked, $now, $ticketId]
            );
        }
    } catch (Exception $e) {
        return ['updated' => false, 'cleared_assigns' => 0, 'reset_responders' => 0,
                'errors' => ['status update failed: ' . $e->getMessage()]];
    }

    return [
        'updated'          => true,
        'cleared_assigns'  => $clearedAssigns,
        'reset_responders' => $resetResponders,
        'errors'           => [],
    ];
}

/**
 * Soft-delete an incident — set deleted_at + deleted_by on the ticket
 * row. Mirrors the wastebasket pattern (api/wastebasket.php uses the
 * same columns). Does NOT cascade — the assigns / action / patient /
 * allocates rows stay in place so an admin can undelete cleanly.
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.delete_incident') OR similar check
 *
 * @return array ['deleted' => bool, 'errors' => string[]]
 */
function incident_soft_delete_internal(int $ticketId, int $userId): array {
    if ($ticketId <= 0) {
        return ['deleted' => false, 'errors' => ['invalid ticket_id']];
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}ticket` SET `deleted_at` = NOW(), `deleted_by` = ? WHERE `id` = ?",
            [$userId, $ticketId]
        );
    } catch (Exception $e) {
        // Pre-wastebasket installs may lack the columns — surface the
        // failure so admin knows to run the wastebasket migration.
        return ['deleted' => false, 'errors' => ['delete failed: ' . $e->getMessage()]];
    }
    return ['deleted' => true, 'errors' => []];
}
