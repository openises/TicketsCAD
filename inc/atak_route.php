<?php
/**
 * Phase 91 Slice 3 — inbound CoT routing logic.
 *
 * One public function: atak_route_inbound($entity, $transport,
 * $channelRef, $authTokenId).
 *
 * Routes by entity kind per decisions 3 + 4:
 *   - responder/position → location_reports row, attributed to the
 *     personnel record bound to the ATAK uid via comm_identifiers,
 *     OR upserted into atak_unbound_uids if no binding exists
 *     (decision 4)
 *   - marker subtype=u-d-c-c (circle) → always new geofenced incident
 *     (decision 3 override)
 *   - marker subtype=b-m-p-w (waypoint) → channel's marker_default_action
 *     decides: new_incident OR note on nearest open incident within
 *     atak_marker_nearest_radius_meters
 *   - chat → broker_send('local_chat', ...) with source='atak-<uid>'
 *   - other → log only (already happens in atak-ingest.php)
 *
 * Returns a string describing what happened, e.g.:
 *   'position_logged_to_personnel_42'
 *   'position_logged_to_unbound_uid'
 *   'new_incident_99_created'
 *   'note_appended_to_incident_42'
 *   'chat_broadcast'
 *   'noop_other'
 */

declare(strict_types=1);

if (!function_exists('atak_route_inbound')) {

function atak_route_inbound(array $entity, string $transport,
                            string $channelRef, ?int $authTokenId): string {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $kind   = (string) ($entity['kind'] ?? '');

    switch ($kind) {
        case 'responder':
            return _atak_route_position($prefix, $entity, $transport, $channelRef);

        case 'marker':
            $sub = (string) ($entity['marker_subtype'] ?? 'b-m-p-w');
            if ($sub === 'u-d-c-c') {
                // Decision 3 override: circle marker always → new incident
                return _atak_route_new_incident($prefix, $entity, true);
            }
            // Waypoint → channel default
            $action = _atak_channel_marker_action($prefix, $transport, $channelRef);
            if ($action === 'note_nearest') {
                $noted = _atak_route_note_nearest($prefix, $entity);
                if ($noted !== null) return $noted;
                // Fall through to create if no nearby incident found
            }
            return _atak_route_new_incident($prefix, $entity, false);

        case 'chat':
            return _atak_route_chat($entity);

        case 'incident':
        case 'facility':
        case 'other':
        default:
            return 'noop_other';
    }
}

// ── Position routing (decision 4) ───────────────────────────────

function _atak_route_position(string $prefix, array $entity,
                              string $transport, string $channelRef): string {
    $uid = (string) ($entity['uid'] ?? '');
    $lat = (float) ($entity['lat'] ?? 0.0);
    $lng = (float) ($entity['lng'] ?? 0.0);

    // Look up binding via the existing comm_identifiers UI
    $bound = _atak_lookup_bound_member($prefix, $uid);

    // Find the ATAK provider id (guaranteed present — atak-ingest.php
    // checks at the top, but defensive query keeps this function
    // standalone for tests).
    $providerId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}location_providers` WHERE code = 'atak'"
    );

    $extras = [
        'altitude' => isset($entity['altitude']) ? (float) $entity['altitude'] : null,
        'speed'    => isset($entity['speed'])    ? (float) $entity['speed']    : null,
        'heading'  => isset($entity['course'])   ? (float) $entity['course']   : null,
        'accuracy' => isset($entity['accuracy']) ? (float) $entity['accuracy'] : null,
    ];

    $reportedAt = (string) ($entity['reported_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
    $reportedSql = date('Y-m-d H:i:s', strtotime($reportedAt) ?: time());

    // INSERT into location_reports with the raw uid as
    // unit_identifier so historical lookup by uid stays clean
    // regardless of whether binding existed at time of report.
    try {
        db_query(
            "INSERT INTO `{$prefix}location_reports`
                (provider_id, unit_identifier, lat, lng, altitude,
                 speed, heading, accuracy, battery, raw_data, reported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?)",
            [$providerId, $uid, $lat, $lng,
             $extras['altitude'], $extras['speed'], $extras['heading'],
             $extras['accuracy'], $reportedSql]
        );
    } catch (Exception $e) {
        error_log("[atak_route] location_reports insert failed: " . $e->getMessage());
        throw $e;
    }

    if ($bound !== null) {
        return "position_logged_to_personnel_{$bound}";
    }

    // Decision 4: upsert into atak_unbound_uids so the operator's
    // review panel knows this uid is calling in.
    _atak_upsert_unbound($prefix, $uid, $entity['callsign'] ?? null,
                        $transport, $channelRef, $lat, $lng);
    return 'position_logged_to_unbound_uid';
}

/**
 * Look up the personnel record bound to an ATAK uid via
 * member_comm_identifiers (joined to comm_modes WHERE code='atak').
 * Returns member.id or null.
 *
 * Field shape: comm_modes.code = 'atak', member_comm_identifiers
 * .values_json contains {"atak_uid": "...", "atak_callsign": "..."}
 * matching the fields_json seeded by run_atak_schema.php.
 */
function _atak_lookup_bound_member(string $prefix, string $uid): ?int {
    try {
        $row = db_fetch_one(
            "SELECT mci.member_id
               FROM `{$prefix}member_comm_identifiers` mci
               JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
              WHERE cm.code = 'atak'
                AND JSON_EXTRACT(mci.values_json, '$.atak_uid') = JSON_QUOTE(?)
              LIMIT 1",
            [$uid]
        );
        if ($row && !empty($row['member_id'])) return (int) $row['member_id'];
    } catch (Exception $e) {
        // Table/JSON-function missing on very old installs — return null.
    }
    return null;
}

function _atak_upsert_unbound(string $prefix, string $uid, ?string $callsign,
                              string $transport, string $channelRef,
                              float $lat, float $lng): void {
    try {
        db_query(
            "INSERT INTO `{$prefix}atak_unbound_uids`
                (atak_uid, callsign_seen, transport, channel_ref,
                 last_lat, last_lng, position_count)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                last_seen      = CURRENT_TIMESTAMP,
                position_count = position_count + 1,
                last_lat       = VALUES(last_lat),
                last_lng       = VALUES(last_lng),
                callsign_seen  = COALESCE(VALUES(callsign_seen), callsign_seen)",
            [$uid, $callsign, $transport, $channelRef, $lat, $lng]
        );
    } catch (Exception $e) {
        error_log("[atak_route] atak_unbound_uids upsert failed: " . $e->getMessage());
    }
}

// ── Marker routing (decision 3) ─────────────────────────────────

function _atak_channel_marker_action(string $prefix, string $transport,
                                     string $channelRef): string {
    // Post-consolidation: channel policy lives on mesh_channels rows
    // (with atak_enabled=1 + the atak_* policy columns added by
    // sql/run_atak_consolidation.php). Lookup by channel name; the
    // transport arg is kept in the signature for the future v1.5
    // TAK Server path (which won't be a mesh_channels row).
    if ($transport !== 'meshtastic') return 'new_incident';
    try {
        $v = db_fetch_value(
            "SELECT atak_marker_action FROM `{$prefix}mesh_channels`
              WHERE name = ? AND atak_enabled = 1 AND archived_at IS NULL
              LIMIT 1",
            [$channelRef]
        );
        if (in_array($v, ['new_incident','note_nearest'], true)) return (string) $v;
    } catch (Exception $e) { /* mesh_channels not present or not consolidated yet */ }
    return 'new_incident'; // safe default
}

function _atak_route_new_incident(string $prefix, array $entity, bool $isCircle): string {
    // Map an ATAK marker to a row in the existing `ticket` table.
    // Real schema (verified on training 2026-06-24):
    //   description TEXT NOT NULL, scope TEXT NOT NULL, contact VARCHAR NOT NULL
    //   in_types_id INT NOT NULL (FK), status TINYINT (0 = open),
    //   severity INT, lat DOUBLE, lng DOUBLE, date DATETIME
    $lat = (float) ($entity['lat'] ?? 0.0);
    $lng = (float) ($entity['lng'] ?? 0.0);
    $callsign = trim((string) ($entity['callsign'] ?? 'ATAK'));
    $remarks  = trim((string) ($entity['remarks'] ?? ''));
    $desc     = $remarks !== '' ? $remarks : "ATAK marker from {$callsign}";
    $scope    = $isCircle ? "ATAK circle marker from {$callsign}" : "ATAK marker from {$callsign}";

    // Pick whatever in_types row exists — the lowest id is a stable
    // fallback. Operator can re-classify after.
    $inTypeId = (int) db_fetch_value("SELECT MIN(id) FROM `{$prefix}in_types`");
    if (!$inTypeId) $inTypeId = 1; // last-resort fallback if the install has no in_types yet

    try {
        db_query(
            "INSERT INTO `{$prefix}ticket`
                (in_types_id, org, contact, description, scope,
                 lat, lng, severity, status, `date`)
             VALUES (?, 0, ?, ?, ?, ?, ?, ?, 0, NOW())",
            [$inTypeId, "atak:{$callsign}", $desc, $scope,
             $lat, $lng, 2]
        );
        $id = (int) db_insert_id();
        return "new_incident_{$id}_created" . ($isCircle ? '_geofenced' : '');
    } catch (Exception $e) {
        error_log("[atak_route] ticket insert failed: " . $e->getMessage());
        throw $e;
    }
}

function _atak_route_note_nearest(string $prefix, array $entity): ?string {
    $lat = (float) ($entity['lat'] ?? 0.0);
    $lng = (float) ($entity['lng'] ?? 0.0);
    $remarks = trim((string) ($entity['remarks'] ?? '(no note)'));
    $callsign = trim((string) ($entity['callsign'] ?? 'ATAK'));

    $radiusM = (int) get_variable('atak_marker_nearest_radius_meters') ?: 500;

    // Find the nearest open incident within radius — approximate
    // (1 degree ≈ 111km at equator); for SAR/Skywarn distances this
    // is plenty close enough. Switch to spherical-law-of-cosines if
    // we ever need accuracy at high latitudes.
    $approxDegrees = $radiusM / 111000.0;

    try {
        // Real ticket schema: status is TINYINT(1) where 0 = open
        // (the existing dispatch convention). deleted_at IS NULL
        // filters out soft-deleted incidents.
        $row = db_fetch_one(
            "SELECT id, lat, lng,
                    SQRT(POW((lat - ?) * 111000, 2) + POW((lng - ?) * 111000 * COS(RADIANS(?)), 2)) AS dist_m
               FROM `{$prefix}ticket`
              WHERE status = 0
                AND deleted_at IS NULL
                AND lat IS NOT NULL AND lng IS NOT NULL
                AND lat BETWEEN ? - ? AND ? + ?
                AND lng BETWEEN ? - ? AND ? + ?
           ORDER BY dist_m ASC
              LIMIT 1",
            [$lat, $lng, $lat,
             $lat, $approxDegrees, $lat, $approxDegrees,
             $lng, $approxDegrees, $lng, $approxDegrees]
        );
        if (!$row) return null;
        if ((float) $row['dist_m'] > $radiusM) return null;

        $incidentId = (int) $row['id'];
        $noteText = "[ATAK marker from {$callsign}] {$remarks}";

        // Action table (verified): ticket_id, description NOT NULL,
        // date, action_type INT, user, responder, updated.
        db_query(
            "INSERT INTO `{$prefix}action`
                (ticket_id, description, `date`, action_type, `user`)
             VALUES (?, ?, NOW(), 0, NULL)",
            [$incidentId, $noteText]
        );
        return "note_appended_to_incident_{$incidentId}";
    } catch (Exception $e) {
        error_log("[atak_route] note_nearest lookup/insert failed: " . $e->getMessage());
        return null;
    }
}

// ── Chat routing ────────────────────────────────────────────────

function _atak_route_chat(array $entity): string {
    $remarks = trim((string) ($entity['remarks'] ?? ''));
    $callsign = trim((string) ($entity['callsign'] ?? 'ATAK'));
    if ($remarks === '') return 'chat_empty_dropped';

    if (function_exists('broker_send')) {
        try {
            broker_send('local_chat', [
                'to'   => 'all',
                'body' => "[ATAK:{$callsign}] {$remarks}",
                'type' => 'atak_chat',
            ]);
            return 'chat_broadcast';
        } catch (Exception $e) {
            error_log("[atak_route] broker_send chat failed: " . $e->getMessage());
        }
    }
    return 'chat_broker_unavailable';
}

} // !function_exists guard
