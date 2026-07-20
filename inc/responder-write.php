<?php
/**
 * Phase 94 Stage 4e — Responder (unit) write helpers.
 *
 * Extracted from api/responder-save.php / api/responder-delete.php /
 * api/responder-status.php so both the internal CSRF-checked endpoints
 * and the external token-auth endpoints call into the same write path.
 * Caller does CSRF/bearer auth + RBAC — this file just writes.
 *
 * Helpers:
 *   responder_upsert_internal($input, $userId, $existingId = null)
 *     → ['id' => int, 'errors' => string[], 'is_new' => bool]
 *
 *   responder_soft_delete_internal($id, $userId)
 *     → ['deleted' => bool, 'soft' => bool, 'errors' => string[]]
 *
 *   responder_set_status_internal($id, $statusId, $userId, $statusAbout = '')
 *     → ['updated' => bool, 'status_name' => str, 'incidents_logged' => int,
 *        'timestamps_set' => int, 'errors' => string[]]
 *
 * The status helper mirrors api/responder-status.php's open-assigns
 * timestamp stamping (Phase 90-pre fix, commit 82efc55, 2026-06-27).
 */

declare(strict_types=1);

/**
 * Upsert a responder. If $existingId > 0 (or $input['id']), updates that
 * row; otherwise creates a new responder.
 *
 * Required for create: name, description. Update: same (or omit and re-use
 * existing values? — current behavior is fields-as-passed, mirroring
 * api/responder-save.php which always writes every column).
 *
 * Caller is responsible for:
 *   - CSRF or bearer auth
 *   - rbac_can('action.manage_members') check
 *   - $_SESSION['user_id'] for created_by attribution
 *
 * @return array ['id' => int, 'errors' => string[], 'is_new' => bool]
 */
function responder_upsert_internal(array $input, int $userId, ?int $existingId = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Resolve id from explicit arg first, then body
    $id = $existingId !== null ? (int) $existingId : (int) ($input['id'] ?? 0);
    $isNew = ($id <= 0);

    // Validate required fields
    $name        = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    if ($name === '') {
        return ['id' => 0, 'errors' => ['name is required'], 'is_new' => $isNew];
    }
    // Issue #28 (a beta tester 2026-07-02): units created via clock-in have
    // no description on file. On UPDATE the edit form doesn't always
    // show a description field either, so submitting empty here would
    // reject a legitimate save of identity / contact / location edits.
    // Preserve the existing description if the request didn't supply
    // one; if there was none in the DB and none in the request, fall
    // back to name (the responder table has description NOT NULL and
    // no default).
    if ($description === '') {
        if (!$isNew) {
            try {
                $prefix = $GLOBALS['db_prefix'] ?? '';
                $existing = db_fetch_value(
                    "SELECT description FROM `{$prefix}responder` WHERE id = ? LIMIT 1",
                    [$id]
                );
                if ($existing !== null && $existing !== '') {
                    $description = (string) $existing;
                }
            } catch (Exception $e) { /* fall through to name-fallback */ }
        }
        if ($description === '') {
            $description = $name;   // schema is NOT NULL; use name as a sane default
        }
    }

    // Identity & Location
    $handle       = trim((string) ($input['handle'] ?? ''));
    $callsign     = trim((string) ($input['callsign'] ?? ''));
    $street       = trim((string) ($input['street'] ?? ''));
    $city         = trim((string) ($input['city'] ?? ''));
    $state        = trim((string) ($input['state'] ?? ''));
    $lat          = (isset($input['lat']) && $input['lat'] !== '') ? (float) $input['lat'] : null;
    $lng          = (isset($input['lng']) && $input['lng'] !== '') ? (float) $input['lng'] : null;
    $type         = (int) ($input['type'] ?? 0);

    // Contact & Messaging
    $phone        = trim((string) ($input['phone'] ?? ''));
    $cellphone    = trim((string) ($input['cellphone'] ?? ''));
    $contact_name = trim((string) ($input['contact_name'] ?? ''));
    $contact_via  = trim((string) ($input['contact_via'] ?? ''));
    $smsg_id      = trim((string) ($input['smsg_id'] ?? ''));
    $pager_p      = trim((string) ($input['pager_p'] ?? ''));
    $pager_s      = trim((string) ($input['pager_s'] ?? ''));
    $send_no      = trim((string) ($input['send_no'] ?? ''));

    // Configuration
    $mobile       = (int) ($input['mobile'] ?? 0);
    $multi        = (int) ($input['multi'] ?? 0);
    $direcs       = (int) ($input['direcs'] ?? 0);
    $capab        = trim((string) ($input['capab'] ?? ''));
    $at_facility  = (int) ($input['at_facility'] ?? 0);
    $icon_str     = trim((string) ($input['icon_str'] ?? ''));
    $other        = trim((string) ($input['other'] ?? ''));

    // Status
    $un_status_id = isset($input['un_status_id']) ? (int) $input['un_status_id'] : null;
    $status_about = trim((string) ($input['status_about'] ?? ''));

    // Tracking — single dropdown maps to individual boolean columns
    $tracking_provider = trim((string) ($input['tracking_provider'] ?? ''));
    $tracking_columns = [
        'aprs', 'instam', 'ogts', 't_tracker', 'mob_tracker',
        'xastir_tracker', 'traccar', 'javaprssrvr', 'locatea',
        'gtrack', 'glat', 'followmee_tracker',
    ];
    $tracking_map = [
        'aprs'       => 'aprs',
        'instam'     => 'instam',
        'ogts'       => 'ogts',
        't_tracker'  => 't_tracker',
        'mob_tracker'=> 'mob_tracker',
        'xastir'     => 'xastir_tracker',
        'traccar'    => 'traccar',
        'javaprssrvr'=> 'javaprssrvr',
        'locatea'    => 'locatea',
        'gtrack'     => 'gtrack',
        'glat'       => 'glat',
        'followmee'  => 'followmee_tracker',
    ];
    $tracking_values = [];
    foreach ($tracking_columns as $col) {
        $tracking_values[$col] = 0;
    }
    if ($tracking_provider !== '' && isset($tracking_map[$tracking_provider])) {
        $tracking_values[$tracking_map[$tracking_provider]] = 1;
    }

    // Boundaries
    $ring_fence = (int) ($input['ring_fence'] ?? 0);
    $excl_zone  = (int) ($input['excl_zone'] ?? 0);

    if (!$isNew) {
        // UPDATE — verify existence first
        try {
            $existing = db_fetch_one(
                "SELECT `id`, `un_status_id` FROM `{$prefix}responder` WHERE `id` = ?",
                [$id]
            );
        } catch (Exception $e) {
            throw $e;
        }
        if (!$existing) {
            return ['id' => 0, 'errors' => ['not_found'], 'is_new' => false];
        }

        $status_changed = ($un_status_id !== null && (int) $existing['un_status_id'] !== $un_status_id);
        $status_updated_sql = $status_changed ? ', `status_updated` = NOW()' : '';
        $status_sql = '';
        $status_params = [];
        if ($un_status_id !== null) {
            $status_sql = ', `un_status_id` = ?, `status_about` = ?' . $status_updated_sql;
            $status_params = [$un_status_id, $status_about];
        }

        db_query(
            "UPDATE `{$prefix}responder` SET
                `name` = ?, `handle` = ?, `callsign` = ?, `description` = ?,
                `street` = ?, `city` = ?, `state` = ?,
                `lat` = ?, `lng` = ?, `type` = ?,
                `phone` = ?, `cellphone` = ?, `contact_name` = ?,
                `contact_via` = ?, `smsg_id` = ?, `pager_p` = ?, `pager_s` = ?, `send_no` = ?,
                `mobile` = ?, `multi` = ?, `direcs` = ?, `capab` = ?, `at_facility` = ?,
                `icon_str` = ?, `other` = ?,
                `aprs` = ?, `instam` = ?, `ogts` = ?, `t_tracker` = ?, `mob_tracker` = ?,
                `xastir_tracker` = ?, `traccar` = ?, `javaprssrvr` = ?, `locatea` = ?,
                `gtrack` = ?, `glat` = ?, `followmee_tracker` = ?,
                `ring_fence` = ?, `excl_zone` = ?,
                `updated` = NOW()
                {$status_sql}
             WHERE `id` = ?",
            array_merge(
                [
                    $name, $handle, $callsign, $description,
                    $street, $city, $state, $lat, $lng, $type,
                    $phone, $cellphone, $contact_name,
                    $contact_via, $smsg_id, $pager_p, $pager_s, $send_no,
                    $mobile, $multi, $direcs, $capab, $at_facility,
                    $icon_str, $other,
                    $tracking_values['aprs'], $tracking_values['instam'],
                    $tracking_values['ogts'], $tracking_values['t_tracker'],
                    $tracking_values['mob_tracker'], $tracking_values['xastir_tracker'],
                    $tracking_values['traccar'], $tracking_values['javaprssrvr'],
                    $tracking_values['locatea'], $tracking_values['gtrack'],
                    $tracking_values['glat'], $tracking_values['followmee_tracker'],
                    $ring_fence, $excl_zone,
                ],
                $status_params,
                [$id]
            )
        );

        // Site-wide log row — best-effort
        try {
            db_query(
                "INSERT INTO `{$prefix}log` (`who`, `from`, `when`, `code`, `info`)
                 VALUES (?, ?, NOW(), 32, ?)",
                [
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    'Responder updated: ' . $name . ' (ID ' . $id . ')',
                ]
            );
        } catch (Exception $e) { /* non-fatal */ }

        // Geofence check (best-effort, requires lat/lng)
        if ($lat !== null && $lng !== null) {
            try {
                require_once __DIR__ . '/geofence.php';
                $unitId = $handle ?: ($callsign ?: 'unit-' . $id);
                @geofence_check($lat, $lng, $unitId);
            } catch (Exception $e) { /* non-fatal */ }
        }

        return ['id' => $id, 'errors' => [], 'is_new' => false];
    }

    // INSERT — new responder
    $initial_status = ($un_status_id !== null) ? $un_status_id : 1;

    db_query(
        "INSERT INTO `{$prefix}responder`
            (`name`, `handle`, `callsign`, `description`, `street`, `city`, `state`,
             `lat`, `lng`, `type`, `phone`, `cellphone`, `contact_name`,
             `contact_via`, `smsg_id`, `pager_p`, `pager_s`, `send_no`,
             `mobile`, `multi`, `direcs`, `capab`, `at_facility`,
             `icon_str`, `other`,
             `aprs`, `instam`, `ogts`, `t_tracker`, `mob_tracker`,
             `xastir_tracker`, `traccar`, `javaprssrvr`, `locatea`,
             `gtrack`, `glat`, `followmee_tracker`,
             `ring_fence`, `excl_zone`,
             `un_status_id`, `status_about`, `updated`, `status_updated`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
        [
            $name, $handle, $callsign, $description,
            $street, $city, $state, $lat, $lng, $type,
            $phone, $cellphone, $contact_name,
            $contact_via, $smsg_id, $pager_p, $pager_s, $send_no,
            $mobile, $multi, $direcs, $capab, $at_facility,
            $icon_str, $other,
            $tracking_values['aprs'], $tracking_values['instam'],
            $tracking_values['ogts'], $tracking_values['t_tracker'],
            $tracking_values['mob_tracker'], $tracking_values['xastir_tracker'],
            $tracking_values['traccar'], $tracking_values['javaprssrvr'],
            $tracking_values['locatea'], $tracking_values['gtrack'],
            $tracking_values['glat'], $tracking_values['followmee_tracker'],
            $ring_fence, $excl_zone,
            $initial_status, $status_about,
        ]
    );
    $newId = (int) db_insert_id();

    try {
        db_query(
            "INSERT INTO `{$prefix}log` (`who`, `from`, `when`, `code`, `info`)
             VALUES (?, ?, NOW(), 31, ?)",
            [
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? '',
                'Responder created: ' . $name . ' (ID ' . $newId . ')',
            ]
        );
    } catch (Exception $e) { /* non-fatal */ }

    return ['id' => $newId, 'errors' => [], 'is_new' => true];
}

/**
 * Soft-delete a responder: set deleted_at + deleted_by. Falls back to
 * hard-delete if the deleted_at column doesn't exist (pre-wastebasket
 * installs) — matches api/responder-delete.php behavior exactly.
 *
 * Refuses to delete responders with active assignments — same guard as
 * the internal endpoint, so external API can't bypass that check.
 *
 * @return array ['deleted' => bool, 'soft' => bool, 'errors' => string[]]
 */
function responder_soft_delete_internal(int $id, int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if ($id <= 0) {
        return ['deleted' => false, 'soft' => false, 'errors' => ['invalid_id']];
    }

    try {
        $responder = db_fetch_one(
            "SELECT `id`, `name` FROM `{$prefix}responder` WHERE `id` = ?",
            [$id]
        );
    } catch (Exception $e) {
        throw $e;
    }
    if (!$responder) {
        return ['deleted' => false, 'soft' => false, 'errors' => ['not_found']];
    }

    // Active-assignment guard
    try {
        $activeCount = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}assigns`
             WHERE `responder_id` = ?
               AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')",
            [$id]
        );
    } catch (Exception $e) {
        $activeCount = 0; // assigns table missing → don't block delete
    }
    if ($activeCount > 0) {
        return ['deleted' => false, 'soft' => false,
                'errors' => ['has_active_assignments'],
                'active_count' => $activeCount];
    }

    $softDeleted = false;
    try {
        db_query(
            "UPDATE `{$prefix}responder` SET `deleted_at` = NOW(), `deleted_by` = ? WHERE `id` = ?",
            [$userId, $id]
        );
        $softDeleted = true;
    } catch (Exception $sdErr) {
        $msg = $sdErr->getMessage();
        if (strpos($msg, 'deleted_at') !== false || strpos($msg, 'Unknown column') !== false) {
            db_query("DELETE FROM `{$prefix}responder` WHERE `id` = ?", [$id]);
            try {
                db_query("DELETE FROM `{$prefix}allocates` WHERE `resource_id` = ? AND `type` = 2", [$id]);
            } catch (Exception $e) { /* non-fatal */ }
        } else {
            throw $sdErr;
        }
    }

    try {
        db_query(
            "INSERT INTO `{$prefix}log` (`who`, `from`, `when`, `code`, `info`)
             VALUES (?, ?, NOW(), 33, ?)",
            [
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? '',
                'Responder ' . ($softDeleted ? 'soft-deleted' : 'deleted') . ': '
                    . $responder['name'] . ' (ID ' . $id . ')',
            ]
        );
    } catch (Exception $e) { /* non-fatal */ }

    return ['deleted' => true, 'soft' => $softDeleted, 'errors' => [],
            'name' => $responder['name']];
}

/**
 * Update a responder's status. Mirrors api/responder-status.php exactly,
 * including the Phase 90-pre fix (2026-06-27) that stamps the matching
 * assigns timestamp column (responding/on_scene/clear) on each open
 * assignment based on the new status's `incident_action`.
 *
 * @return array ['updated' => bool, 'status_name' => string,
 *                'incidents_logged' => int, 'timestamps_set' => int,
 *                'errors' => string[]]
 */
function responder_set_status_internal(
    int $responderId,
    int $statusId,
    int $userId,
    string $statusAbout = '',
    $extraData = null
): array {
    // $extraData (Phase 95, 2026-06-28) — optional payload collected
    // by the UI when the status has un_status.extra_data_type !=
    // 'none'. Shape:
    //   ['type' => 'facility|mileage|location|note|numeric',
    //    'value' => mixed (int facility id, int odometer, [lat,lng],
    //                       string note, float numeric)]
    // The helper validates against the un_status.extra_data_required
    // flag and routes per un_status.extra_data_target:
    //   - 'incident' → stamp on open assignment's ticket (facility =>
    //     ticket.rec_facility, mileage => mileage_log row, location =>
    //     ticket.lat/lng if currently empty)
    //   - 'unit' → stamp on responder row (location => responder.lat/lng)
    //   - 'action_log' → just goes in the action note text (always)
    //
    // Universal: every extra_data value is appended to the action log
    // entry's description text so the audit trail captures it
    // regardless of target.
    $prefix = $GLOBALS['db_prefix'] ?? '';

    if ($responderId <= 0) {
        return ['updated' => false, 'status_name' => '',
                'incidents_logged' => 0, 'timestamps_set' => 0,
                'errors' => ['invalid_responder_id']];
    }
    if ($statusId <= 0) {
        return ['updated' => false, 'status_name' => '',
                'incidents_logged' => 0, 'timestamps_set' => 0,
                'errors' => ['invalid_status_id']];
    }

    $responder = db_fetch_one(
        "SELECT `id`, `name`, `handle`, `un_status_id` FROM `{$prefix}responder` WHERE `id` = ?",
        [$responderId]
    );
    if (!$responder) {
        return ['updated' => false, 'status_name' => '',
                'incidents_logged' => 0, 'timestamps_set' => 0,
                'errors' => ['responder_not_found']];
    }

    // Phase 90-pre: pull incident_action so we can stamp the matching
    // assigns timestamp column on every open assignment below.
    // Phase 95 (2026-06-28): also pull extra_data_* config so we can
    // validate the optional $extraData payload + route it correctly.
    // Wrapped in try/catch: installs pre-Phase-95 won't have the
    // extra_data_* columns; the catch fetches the legacy column set
    // and synthesizes 'none' defaults.
    try {
        $status = db_fetch_one(
            "SELECT `id`, `status_val`, `incident_action`,
                    `extra_data_type`, `extra_data_required`,
                    `extra_data_label`, `extra_data_target`
             FROM `{$prefix}un_status` WHERE `id` = ?",
            [$statusId]
        );
    } catch (Exception $e) {
        $status = db_fetch_one(
            "SELECT `id`, `status_val`, `incident_action`
             FROM `{$prefix}un_status` WHERE `id` = ?",
            [$statusId]
        );
        if ($status) {
            $status['extra_data_type']     = 'none';
            $status['extra_data_required'] = 0;
            $status['extra_data_label']    = null;
            $status['extra_data_target']   = 'action_log';
        }
    }
    if (!$status) {
        return ['updated' => false, 'status_name' => '',
                'incidents_logged' => 0, 'timestamps_set' => 0,
                'errors' => ['status_not_found']];
    }

    // Phase 95 — validate $extraData against the status's config.
    $extraType   = (string) ($status['extra_data_type']   ?? 'none');
    $extraReq    = (int)    ($status['extra_data_required'] ?? 0);
    $extraTarget = (string) ($status['extra_data_target'] ?? 'action_log');
    $extraValue  = null;
    if ($extraType !== 'none') {
        $supplied = (is_array($extraData) && isset($extraData['value']))
            ? $extraData['value']
            : null;
        // Empty / null / 0 / empty-array all count as "not supplied"
        $isEmpty = ($supplied === null || $supplied === ''
                    || (is_array($supplied) && empty($supplied)));
        if ($extraReq && $isEmpty) {
            return ['updated' => false, 'status_name' => '',
                    'incidents_logged' => 0, 'timestamps_set' => 0,
                    'errors' => ['extra_data_required',
                                 'label:' . (string) ($status['extra_data_label'] ?? $extraType)]];
        }
        if (!$isEmpty) {
            $extraValue = $supplied;
        }
    }

    $oldStatusId = (int) ($responder['un_status_id'] ?? 0);

    // Phase 105 (a beta tester GH #16) — status-workflow gate. One check here
    // covers every caller of this internal function: the status modal
    // (api/responder-status.php), mobile, the /s command bar, and the
    // external API (api/external/v1/responder-status.php).
    //   mode 'off'     → no-op (default; fully backwards compatible)
    //   mode 'enforce' → blocked transition returns errors so the
    //                    caller can 422 with the reason
    //   mode 'warn'    → change applies, but 'workflow_warning' rides
    //                    back on the result for the caller to surface
    //                    + audit. sw_check_transition fails OPEN on any
    //                    DB problem, so a broken workflow table can
    //                    never lock dispatch.
    $workflowWarning = null;
    try {
        require_once __DIR__ . '/status-workflow.php';
        $swCheck = sw_check_transition($responderId, $oldStatusId, $statusId);
        if (!$swCheck['allowed']) {
            if ($swCheck['mode'] === 'enforce') {
                return ['updated' => false, 'status_name' => '',
                        'incidents_logged' => 0, 'timestamps_set' => 0,
                        'errors' => ['workflow_blocked',
                                     'reason:' . $swCheck['reason']]];
            }
            if ($swCheck['mode'] === 'warn') {
                $workflowWarning = $swCheck['reason'];
            }
        }
    } catch (Exception $e) {
        // Fail-open — never let the workflow gate break a status change.
        error_log('[responder-write] status-workflow gate exception: ' . $e->getMessage());
    }

    db_query(
        "UPDATE `{$prefix}responder` SET
            `un_status_id` = ?,
            `status_about` = ?,
            `status_updated` = NOW(),
            `updated` = NOW()
         WHERE `id` = ?",
        [$statusId, $statusAbout, $responderId]
    );

    try {
        db_query(
            "INSERT INTO `{$prefix}log` (`who`, `from`, `when`, `code`, `info`)
             VALUES (?, ?, NOW(), 30, ?)",
            [
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? '',
                'Status change: ' . $responder['name'] . ' set to ' . $status['status_val']
                    . ($statusAbout !== '' ? ' (' . $statusAbout . ')' : ''),
            ]
        );
    } catch (Exception $e) { /* non-fatal */ }

    // Phase 72 + Phase 90-pre: action row + assigns timestamp stamping
    // for every open assignment.
    $incidentAction  = trim((string) ($status['incident_action'] ?? ''));
    $stampableActions = ['dispatched', 'responding', 'on_scene', 'clear'];
    $shouldStamp = in_array($incidentAction, $stampableActions, true);

    $actionLogged  = 0;
    $timestampsSet = 0;
    try {
        $openAssigns = db_fetch_all(
            "SELECT a.id, a.ticket_id, a.responding, a.on_scene, a.clear
             FROM `{$prefix}assigns` a
             WHERE a.responder_id = ?
               AND (a.clear IS NULL
                    OR a.clear = ''
                    OR a.clear = '0000-00-00 00:00:00')",
            [$responderId]
        );
        $statusLabel = trim((string) $status['status_val']);
        $unitLabel   = trim((string) ($responder['handle'] ?: $responder['name']));
        $desc        = $unitLabel . ': ' . $statusLabel
                     . ($statusAbout !== '' ? ' — ' . $statusAbout : '');

        // Phase 95: append extra-data summary to the action description
        // so the audit trail captures it regardless of target. Cheap
        // text rendering — the canonical typed values still land on
        // their target columns below per extra_data_target.
        $extraSummary = _phase95_summarize_extra($extraType, $extraValue, (string) ($status['extra_data_label'] ?? ''));
        if ($extraSummary !== '') {
            $desc .= ' [' . $extraSummary . ']';
        }

        foreach ($openAssigns as $oa) {
            try {
                db_query(
                    "INSERT INTO `{$prefix}action`
                     (`ticket_id`, `date`, `description`, `user`,
                      `action_type`, `responder`, `updated`)
                     VALUES (?, NOW(), ?, ?, 30, ?, NOW())",
                    [(int) $oa['ticket_id'], $desc, $userId, $responderId]
                );
                $actionLogged++;
            } catch (Exception $inner) { /* non-fatal */ }

            if ($shouldStamp) {
                try {
                    if ($incidentAction === 'responding') {
                        if (empty($oa['responding']) || substr((string) $oa['responding'], 0, 4) === '0000') {
                            db_query(
                                "UPDATE `{$prefix}assigns` SET `responding` = NOW() WHERE `id` = ?",
                                [(int) $oa['id']]
                            );
                            $timestampsSet++;
                        }
                    } elseif ($incidentAction === 'on_scene') {
                        $needResp = empty($oa['responding']) || substr((string) $oa['responding'], 0, 4) === '0000';
                        if ($needResp && (empty($oa['on_scene']) || substr((string) $oa['on_scene'], 0, 4) === '0000')) {
                            db_query(
                                "UPDATE `{$prefix}assigns` SET `responding` = NOW(), `on_scene` = NOW() WHERE `id` = ?",
                                [(int) $oa['id']]
                            );
                        } elseif (empty($oa['on_scene']) || substr((string) $oa['on_scene'], 0, 4) === '0000') {
                            db_query(
                                "UPDATE `{$prefix}assigns` SET `on_scene` = NOW() WHERE `id` = ?",
                                [(int) $oa['id']]
                            );
                        }
                        $timestampsSet++;
                    } elseif ($incidentAction === 'clear') {
                        if (empty($oa['clear']) || substr((string) $oa['clear'], 0, 4) === '0000') {
                            db_query(
                                "UPDATE `{$prefix}assigns` SET `clear` = NOW() WHERE `id` = ?",
                                [(int) $oa['id']]
                            );
                            $timestampsSet++;
                        }
                    }
                    // 'dispatched' is auto-set at initial assign time;
                    // re-stamping it here is a no-op (matches
                    // api/incident-assign.php behavior).
                } catch (Exception $tsErr) { /* per-row non-fatal */ }
            }

            // Phase 95: route extra_data to per-target columns on each
            // open incident's ticket row (when target='incident').
            // GH #20 round 4 (a beta tester 2026-07-17) — EXCEPT a `facility`, which is
            // NEVER incident-level from a unit's status change (see below).
            if ($extraTarget === 'incident' && $extraValue !== null && $extraType !== 'facility') {
                _phase95_route_to_incident(
                    $prefix, (int) $oa['ticket_id'], $extraType, $extraValue,
                    (int) $responderId, (int) $userId  // QA #14 — were out of scope
                );
            }

            // Phase 116: route a destination facility to THIS unit's assignment
            // row (assigns.rec_facility_id). This is the per-unit receiving
            // facility — a mass-casualty incident can send each transporting unit
            // to a DIFFERENT hospital. bed_auto resolves COALESCE(assign, ticket),
            // so the per-unit value takes precedence.
            //
            // GH #20 round 4 (a beta tester 2026-07-17): a `facility` collected on a
            // unit's status change is ALWAYS that unit's destination, so route it
            // per-assignment regardless of the status's configured
            // extra_data_target. Phase 116 widened the ENUM to allow 'assignment'
            // but nothing ever SET delivery statuses to use it — in the field they
            // default to target='incident', which wrote the shared
            // ticket.rec_facility for every open assign (last-write-wins), so two
            // ambulances to two hospitals both decremented ONE facility's beds.
            // The unit test masked this by hand-configuring target='assignment'.
            if ($extraValue !== null && ($extraTarget === 'assignment' || $extraType === 'facility')) {
                _phase95_route_to_assignment(
                    $prefix, (int) $oa['id'], $extraType, $extraValue, (int) $userId
                );
            }
        }
    } catch (Exception $e) { /* assigns lookup non-fatal */ }

    // Issue #13 (a beta tester 2026-07-05) — real-time cross-client refresh. A status
    // change (from the desktop status modal, the /s command, OR the mobile app —
    // all funnel through here) must push to any OTHER dispatcher viewing the
    // affected incident. Nothing published responder:status for these open
    // assignments before, so a mobile DP→EN never reached the open CAD incident
    // window. Publish scoped to each open-assignment incident; the payload
    // carries ticket_id so incident-detail.js's forThisIncident() matches.
    // Best-effort — never breaks the status write.
    if (!empty($openAssigns)) {
        if (is_file(__DIR__ . '/sse.php')) require_once __DIR__ . '/sse.php';
        if (function_exists('sse_publish_for_incident')) {
            $__sseDone = [];
            foreach ($openAssigns as $oa) {
                $tid = (int) ($oa['ticket_id'] ?? 0);
                if ($tid > 0 && empty($__sseDone[$tid])) {
                    try {
                        sse_publish_for_incident('responder:status', [
                            'ticket_id'    => $tid,
                            'responder_id' => $responderId,
                            'status'       => (string) ($status['status_val'] ?? ''),
                        ], $tid);
                    } catch (Throwable $sseE) { /* non-fatal */ }
                    $__sseDone[$tid] = true;
                }
            }
        }
    }

    // GH #13 round 5 (a beta tester 2026-07-07) — the per-incident publish above
    // only fires for units holding an OPEN assignment. A status change on
    // an UNASSIGNED unit published no event at all, so mobile (and every
    // other SSE listener) saw nothing until a manual refresh — which is
    // the exact "no change on this new commit" repro when the test unit
    // isn't on a call. Publish a responder-scoped copy unconditionally;
    // it rides the same entitled/group visibility as the unit pages.
    if (is_file(__DIR__ . '/sse.php')) require_once __DIR__ . '/sse.php';
    if (function_exists('sse_publish_for_responder')) {
        try {
            sse_publish_for_responder('responder:status', [
                'responder_id' => $responderId,
                'status'       => (string) ($status['status_val'] ?? ''),
            ], $responderId);
        } catch (Throwable $sseE) { /* non-fatal */ }
    }

    // a beta tester GH #11 (2026-07-04) — clearing a unit's status (incident_action
    // 'clear') via the /s command, the status modal, or the situation page
    // can leave an incident with zero active units, which should schedule
    // auto-close. Previously only assign_update_status_internal()'s 'clear'
    // branch called auto_close_maybe_schedule(); this responder-status clear
    // path did not, so incidents emptied this way never got scheduled
    // (matches the assign_unassign_internal gap fixed the same day).
    if ($incidentAction === 'clear' && !empty($openAssigns)) {
        try {
            require_once __DIR__ . '/auto_close.php';
            $__ac_done = [];
            foreach ($openAssigns as $oa) {
                $tid = (int) ($oa['ticket_id'] ?? 0);
                if ($tid > 0 && empty($__ac_done[$tid])) {
                    auto_close_maybe_schedule($tid, $userId);
                    $__ac_done[$tid] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('[responder-write] auto_close_schedule (clear): ' . $e->getMessage());
        }
    }

    // Phase 95: route extra_data to per-target columns on the responder
    // row itself (when target='unit'). Independent of any assignments.
    if ($extraTarget === 'unit' && $extraValue !== null) {
        _phase95_route_to_unit($prefix, $responderId, $extraType, $extraValue);
    }

    // Phase 103 (a beta tester GH #20) — facility bed-count automation.
    // When a unit assigned to a facility as its rec_facility_id
    // transitions into a delivery/arrival status, the facility's
    // simple beds_a/beds_o counters shift by 1 IF the facility opted
    // in via bed_auto_mode='auto'. Deduped per (assign_id, facility_id)
    // so toggling status doesn't double-count. Fully soft-failing —
    // any error here is logged and swallowed so a bad automation
    // config can never block a legitimate status change.
    $bedAutoSummary = null;
    try {
        require_once __DIR__ . '/bed_auto.php';
        $bedAutoSummary = bed_auto_apply_on_status_change(
            $responderId, $statusId, (string) $status['status_val'], $userId
        );
    } catch (Exception $e) {
        error_log('[responder-write] bed_auto exception: ' . $e->getMessage());
    }

    $result = [
        'updated'          => true,
        'status_name'      => $status['status_val'],
        'old_status_id'    => $oldStatusId,
        'new_status_id'    => $statusId,
        'incidents_logged' => $actionLogged,
        'timestamps_set'   => $timestampsSet,
        'extra_data_type'  => $extraType,
        'extra_data_logged'=> $extraValue !== null,
        'bed_auto'         => $bedAutoSummary,
        'errors'           => [],
    ];
    // Phase 105 — 'warn' mode: the change was applied above, but the
    // caller should surface (and audit) the workflow warning.
    if ($workflowWarning !== null) {
        $result['workflow_warning'] = $workflowWarning;
    }
    return $result;
}

/**
 * Phase 95 helper — render extra_data into a human-readable suffix
 * for the action log description. Returns '' if nothing to render.
 */
function _phase95_summarize_extra(string $type, $value, string $label): string {
    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
        return '';
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $caption = $label !== '' ? $label : ucfirst($type);
    switch ($type) {
        case 'facility':
            $fid = (int) $value;
            try {
                $name = db_fetch_value(
                    "SELECT `name` FROM `{$prefix}facilities` WHERE `id` = ? LIMIT 1",
                    [$fid]
                );
                if ($name) return $caption . ': ' . $name;
            } catch (Exception $e) { /* table missing */ }
            return $caption . ' #' . $fid;
        case 'mileage':
        case 'numeric':
            return $caption . ': ' . (string) $value;
        case 'location':
            if (is_array($value) && isset($value[0], $value[1])) {
                return $caption . ': ' . round((float) $value[0], 5) . ',' . round((float) $value[1], 5);
            }
            return $caption . ': ' . (string) $value;
        case 'note':
            $s = trim((string) $value);
            return $s !== '' ? $caption . ': ' . substr($s, 0, 240) : '';
    }
    return '';
}

/**
 * Phase 95 helper — stamp extra_data onto the ticket row when
 * target='incident'. Best-effort per-type routing; failures are
 * non-fatal (caught + ignored).
 */
function _phase95_route_to_incident(string $prefix, int $ticketId, string $type, $value, int $responderId = 0, int $userId = 0): void {
    if ($ticketId <= 0) return;
    try {
        switch ($type) {
            case 'facility':
                $fid = (int) $value;
                if ($fid > 0) {
                    db_query(
                        "UPDATE `{$prefix}ticket` SET `rec_facility` = ?, `updated` = NOW() WHERE `id` = ?",
                        [$fid, $ticketId]
                    );
                }
                break;
            case 'mileage':
                $miles = (int) $value;
                if ($miles > 0) {
                    // Schema audit 2026-07-07: the old INSERT used columns
                    // that never existed (start_odometer/logged_at — real
                    // schema is start_odo/end_odo/miles/started_at) and the
                    // silent catch meant status-prompted mileage was never
                    // recorded on ANY install. Keep the catch (table is
                    // optional on old installs) but LOG failures.
                    try {
                        // QA #14 — this INSERT (a) referenced $responderId /
                        // $userId that were out of scope (now passed in), and
                        // (b) wrote the `miles` column directly. On installs
                        // where soft_delete_mileage.sql made `miles` a GENERATED
                        // STORED column (miles = end_odo - start_odo), writing
                        // it throws. Store the value as an odometer delta
                        // (start_odo 0 -> end_odo $miles) so a generated `miles`
                        // resolves to $miles, and set `miles` directly ONLY when
                        // it's a plain column — correct on every install.
                        static $milesGenerated = null;
                        if ($milesGenerated === null) {
                            $extra = (string) db_fetch_value(
                                "SELECT EXTRA FROM information_schema.columns
                                  WHERE table_schema = DATABASE()
                                    AND table_name = ? AND column_name = 'miles'",
                                [$prefix . 'mileage_log']);
                            $milesGenerated = (stripos($extra, 'GENERATED') !== false);
                        }
                        if ($milesGenerated) {
                            db_query(
                                "INSERT INTO `{$prefix}mileage_log`
                                 (`responder_id`, `user_id`, `ticket_id`,
                                  `start_odo`, `end_odo`, `notes`, `started_at`, `created_at`)
                                 VALUES (?, ?, ?, 0, ?, ?, NOW(), NOW())",
                                [$responderId, $userId, $ticketId, $miles,
                                 'Status extra-data entry']
                            );
                        } else {
                            db_query(
                                "INSERT INTO `{$prefix}mileage_log`
                                 (`responder_id`, `user_id`, `ticket_id`,
                                  `start_odo`, `end_odo`, `miles`, `notes`, `started_at`, `created_at`)
                                 VALUES (?, ?, ?, 0, ?, ?, ?, NOW(), NOW())",
                                [$responderId, $userId, $ticketId, $miles, $miles,
                                 'Status extra-data entry']
                            );
                        }
                    } catch (Exception $e) {
                        error_log('[responder-write] mileage_log insert failed: ' . $e->getMessage());
                    }
                }
                break;
            case 'location':
                if (is_array($value) && isset($value[0], $value[1])) {
                    $lat = (float) $value[0];
                    $lng = (float) $value[1];
                    // Only refine if the ticket has no lat/lng yet — never
                    // overwrite an existing scene location.
                    db_query(
                        "UPDATE `{$prefix}ticket`
                         SET `lat` = ?, `lng` = ?, `updated` = NOW()
                         WHERE `id` = ?
                           AND (`lat` IS NULL OR `lat` = 0 OR `lat` = '')",
                        [$lat, $lng, $ticketId]
                    );
                }
                break;
            // note + numeric: action-log only; no per-incident stamping
        }
    } catch (Exception $e) { /* non-fatal — captured in action log */ }
}

/**
 * Phase 95 helper — stamp extra_data onto the responder row when
 * target='unit'. Today supports location only (mileage + note may
 * be added when there's a use case; for now they live in the
 * action log).
 */
function _phase95_route_to_unit(string $prefix, int $responderId, string $type, $value): void {
    if ($responderId <= 0) return;
    try {
        switch ($type) {
            case 'location':
                if (is_array($value) && isset($value[0], $value[1])) {
                    db_query(
                        "UPDATE `{$prefix}responder`
                         SET `lat` = ?, `lng` = ?, `updated` = NOW()
                         WHERE `id` = ?",
                        [(float) $value[0], (float) $value[1], $responderId]
                    );
                }
                break;
            // mileage + note + numeric on unit: action-log only for now.
            // Future: add responder.last_odometer + responder.last_status_note
            // if a real use case emerges.
        }
    } catch (Exception $e) { /* non-fatal */ }
}

/**
 * Phase 116 helper — stamp extra_data onto ONE assignment row when
 * target='assignment'. Today supports facility only: the destination hospital
 * for THIS unit's transport (assigns.rec_facility_id). This is the per-unit
 * receiving facility the legacy app carried and the NewUI rewrite had dropped;
 * restoring it lets each unit on a multi-casualty incident go to a different
 * facility. Delegates the actual write to assign_set_rec_facility() so the
 * incident-detail path and the /s path share one writer.
 */
function _phase95_route_to_assignment(string $prefix, int $assignId, string $type, $value, int $userId): void {
    if ($assignId <= 0) return;
    if ($type !== 'facility') return;              // facility is the only assignment-scoped datum today
    $fid = (int) $value;
    if ($fid <= 0) return;
    if (!function_exists('assign_set_rec_facility')) {
        if (is_file(__DIR__ . '/assignment-write.php')) require_once __DIR__ . '/assignment-write.php';
    }
    if (function_exists('assign_set_rec_facility')) {
        assign_set_rec_facility($assignId, $fid, $userId);
    } else {
        // Fallback: write directly if the shared writer isn't loadable.
        try {
            db_query(
                "UPDATE `{$prefix}assigns` SET `rec_facility_id` = ? WHERE `id` = ?",
                [$fid, $assignId]
            );
        } catch (Exception $e) { error_log('[responder-write] route_to_assignment: ' . $e->getMessage()); }
    }
}
