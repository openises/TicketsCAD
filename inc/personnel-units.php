<?php
/**
 * Phase 54 (2026-06-14) — Personal-resource clock-in.
 *
 * Some members work alone (ICs, lone field volunteers) and shouldn't
 * have to be added to an organizational unit just to be assignable
 * or trackable. This module gives every member exactly ONE personal
 * unit (a `responder` row tagged `personal_for_member_id`) that they
 * can clock in/out of at will.
 *
 *   Clock In   → create unit if needed, set un_status_id = Available,
 *                set status_updated = NOW(). The unit becomes visible
 *                on the dispatch board and is assignable to incidents.
 *   Clock Out  → set un_status_id = Released/Inactive. Unit is hidden
 *                from the default units view. Row is NOT deleted —
 *                history, prior assignments, location bindings all
 *                persist for the next clock-in.
 *
 * Helpers:
 *   pu_ensure_schema()          — self-heal personal_for_member_id col
 *   pu_personal_unit_name($m)   — primary callsign, else first+last
 *   pu_get_personal_unit($mid)  — fetch the row (NULL if never created)
 *   pu_clock_in($mid)           — get-or-create + Available + audit
 *   pu_clock_out($mid)          — set Inactive + audit
 *   pu_is_clocked_in($mid)      — bool helper for UI
 *   pu_status_for_member($mid)  — { clocked_in, unit_id, unit_name, since }
 */

if (!function_exists('db_query')) {
    require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/audit.php';

function pu_ensure_schema(): void {
    global $prefix;
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}responder` LIKE 'personal_for_member_id'");
        if (!$cols) {
            db_query("ALTER TABLE `{$prefix}responder`
                      ADD COLUMN `personal_for_member_id` INT NULL,
                      ADD UNIQUE INDEX `uk_personal_member` (`personal_for_member_id`)");
        }
    } catch (Exception $e) {
        error_log('pu_ensure_schema: ' . $e->getMessage());
    }
    // a beta tester beta 2026-06-30 (round 2) — guarantee an off-shift
    // un_status row exists. Without one, pu_clock_out's call to
    // _pu_status_id falls through ALL its candidate names (Inactive /
    // Released / Off-duty / Out of Service / Unavailable / In Quarters)
    // and lands on the `return 1` fallback — which is the Available
    // status. Result: clicking Clock-Out marks the unit Available, same
    // as Clock-In. a beta tester's install has none of those names in un_status.
    //
    // Insert "Off Shift" idempotently. We check by name (case-insensitive)
    // so admins who already created an equivalent row aren't duplicated.
    try {
        $hasOff = db_fetch_value(
            "SELECT id FROM `{$prefix}un_status`
              WHERE LOWER(status_val) IN ('off shift','off-shift','inactive','released','off-duty','off duty','out of service','out-of-service','unavailable','in quarters')
              LIMIT 1"
        );
        if (!$hasOff) {
            db_query(
                "INSERT INTO `{$prefix}un_status` (status_val, description)
                 VALUES ('Off Shift', 'Auto-created — used for personal-unit clock out')"
            );
        }
    } catch (Exception $e) {
        // un_status may not exist on extremely old installs; the
        // status_id lookup below will return 1 in that case, which
        // is no worse than the prior behaviour.
        error_log('pu_ensure_schema off-shift seed: ' . $e->getMessage());
    }
}

/**
 * Pick a display name for a member's personal unit. Tries:
 *   1. Member's primary callsign (member_callsigns.is_primary = 1)
 *   2. First callsign on file
 *   3. First + last name
 *   4. Fallback "Member #N"
 */
function pu_personal_unit_name(int $memberId): array {
    global $prefix;
    $m = db_fetch_one(
        "SELECT first_name, last_name FROM `{$prefix}member` WHERE id = ?",
        [$memberId]
    );
    if (!$m) return ['name' => 'Member #' . $memberId, 'handle' => 'M' . $memberId];

    // Try primary callsign
    $callsign = null;
    try {
        $row = db_fetch_one(
            "SELECT callsign FROM `{$prefix}member_callsigns`
              WHERE member_id = ?
              ORDER BY is_primary DESC, id ASC LIMIT 1",
            [$memberId]
        );
        if ($row && !empty($row['callsign'])) $callsign = trim($row['callsign']);
    } catch (Exception $e) { /* table may not exist */ }

    $first = trim((string) ($m['first_name'] ?? ''));
    $last  = trim((string) ($m['last_name'] ?? ''));
    $fullName = trim($first . ' ' . $last) ?: ('Member #' . $memberId);

    if ($callsign) {
        // Callsign wins for both name + handle (handle is truncated to 24)
        return ['name' => $callsign . ' — ' . $fullName, 'handle' => substr($callsign, 0, 24)];
    }
    // No callsign — use full name. Handle is initials or first-name truncated.
    $handle = strtoupper(substr($first, 0, 1) . substr($last, 0, 1)) ?: substr($fullName, 0, 24);
    return ['name' => $fullName, 'handle' => substr($handle, 0, 24)];
}

function pu_get_personal_unit(int $memberId): ?array {
    global $prefix;
    pu_ensure_schema();
    try {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}responder` WHERE personal_for_member_id = ?",
            [$memberId]
        );
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Find or create the personal unit and set its status to Available.
 * Returns the responder row (with personal_for_member_id set).
 */
function pu_clock_in(int $memberId): array {
    global $prefix;
    pu_ensure_schema();

    // Resolve member's linked user + contact fields. Phase 61 mirrors
    // the contact info onto the responder row on every clock-in so
    // dispatch never has to re-enter what's already on the roster.
    $userId = null;
    $member = null;
    try {
        // Phase 99y (Eric beta 2026-06-30) — disambiguate when both
        // linkage paths exist. Stale m.user_id back-references can
        // collide with the canonical u.member pointer; prefer the
        // canonical path. Mirrors the fix in api/personal-unit.php.
        $u = db_fetch_one(
            "SELECT u.id FROM `{$prefix}member` m
             LEFT JOIN `{$prefix}user` u ON (u.id = m.user_id OR u.member = m.id)
             WHERE m.id = ?
             ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
             LIMIT 1",
            [$memberId]
        );
        $userId = $u['id'] ?? null;
        $member = db_fetch_one(
            "SELECT first_name, last_name, callsign, email, phone, phone_cell
               FROM `{$prefix}member` WHERE id = ?",
            [$memberId]
        );
    } catch (Exception $e) { /* keep null */ }
    $contactName = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : '';
    $cellphone   = $member ? trim($member['phone_cell'] ?: ($member['phone'] ?? '')) : '';
    $contactVia  = $cellphone ? 'cell' : ($member['email'] ?? '');

    // Phase 58 — try common "on" names across installs. Most legacy
    // installs use "Active" rather than "Available".
    $available = _pu_status_id('Available', 'Active', 'On Duty', 'On-duty', 'On Call', 'In Service');
    $existing = pu_get_personal_unit($memberId);

    if ($existing) {
        // Already exists — flip to Available AND refresh contact info
        // in case the member updated their phone/callsign since the
        // previous clock-in.
        db_query(
            "UPDATE `{$prefix}responder`
                SET un_status_id = ?, status_updated = NOW(), updated = NOW(),
                    contact_name = ?, cellphone = ?, contact_via = ?,
                    callsign = COALESCE(NULLIF(?, ''), callsign)
              WHERE id = ?",
            [$available, $contactName, $cellphone, $contactVia,
             $member['callsign'] ?? '', $existing['id']]
        );
        // Phase 60 — make sure location bindings exist + are active so
        // the units page / map can resolve incoming position reports.
        pu_autobind_locations($memberId, (int) $existing['id']);
        audit_log('personnel_unit', 'clock_in', 'responder', (int) $existing['id'],
            "Clocked in as personal resource (existing unit)", ['member_id' => $memberId]);
        return db_fetch_one("SELECT * FROM `{$prefix}responder` WHERE id = ?", [$existing['id']]);
    }

    $names = pu_personal_unit_name($memberId);
    db_query(
        "INSERT INTO `{$prefix}responder`
           (name, handle, type, un_status_id, status_updated, updated, user_id,
            description, capab, personal_for_member_id,
            contact_name, cellphone, contact_via, callsign)
         VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?,
                 ?, ?, ?, ?)",
        [
            $names['name'],
            $names['handle'],
            1,  // type=1 → personnel-style unit (1-person resource)
            $available,
            $userId,
            'Personal resource for member #' . $memberId . ' (auto-created on clock-in)',
            'personal',
            $memberId,
            $contactName,
            $cellphone,
            $contactVia,
            $member['callsign'] ?? '',
        ]
    );
    $newId = (int) db_insert_id();
    audit_log('personnel_unit', 'create', 'responder', $newId,
        "Created personal unit '{$names['name']}' for member #{$memberId}");
    // Phase 60 — first-time activation: bind every comm-identifier-derived
    // source so the member shows up on the units page immediately.
    pu_autobind_locations($memberId, $newId);
    return db_fetch_one("SELECT * FROM `{$prefix}responder` WHERE id = ?", [$newId]);
}

function pu_clock_out(int $memberId): ?array {
    global $prefix;
    pu_ensure_schema();
    $existing = pu_get_personal_unit($memberId);
    if (!$existing) return null;
    $inactive = _pu_status_id('Inactive', 'Released', 'Off-duty');
    db_query(
        "UPDATE `{$prefix}responder` SET un_status_id = ?, status_updated = NOW(), updated = NOW() WHERE id = ?",
        [$inactive, $existing['id']]
    );
    audit_log('personnel_unit', 'clock_out', 'responder', (int) $existing['id'],
        "Clocked out as personal resource", ['member_id' => $memberId]);
    return db_fetch_one("SELECT * FROM `{$prefix}responder` WHERE id = ?", [$existing['id']]);
}

function pu_status_for_member(int $memberId): array {
    $row = pu_get_personal_unit($memberId);
    if (!$row) {
        return ['exists' => false, 'clocked_in' => false, 'unit_id' => null, 'unit_name' => null, 'since' => null];
    }
    // 2026-06-14 (Phase 58): "Clocked in" is the inverse of an explicit
    // off-state — Inactive / Released / Off-duty / Unavailable / Out of
    // Service. Earlier whitelist-only check ("available"/"on-duty"/
    // "dispatch") returned false for the very common case where the
    // status was just named "Active", so the UI thought clock-in did
    // nothing even when the DB write succeeded. Inverting the check
    // means new installs with custom status names (Standby, En Route,
    // On Scene, etc.) all count as clocked-in by default.
    $statusName = '';
    try {
        global $prefix;
        // a beta tester beta 2026-06-29 — same wrong-table bug as
        // _pu_status_id: responder.un_status_id references un_status
        // (unit statuses), not member_status (person statuses). The
        // old query lookup returned empty/null, statusName stayed '',
        // and the off-indicator scan defaulted to clocked_in=true.
        $s = db_fetch_one("SELECT status_val FROM `{$prefix}un_status` WHERE id = ?", [(int) $row['un_status_id']]);
        $statusName = $s['status_val'] ?? '';
    } catch (Exception $e) { /* OK */ }
    $offIndicators = ['inactive', 'released', 'off-duty', 'off duty',
                      'unavailable', 'out of service', 'out-of-service',
                      'off shift', 'off-shift'];
    $clockedIn = true;
    foreach ($offIndicators as $needle) {
        if (stripos($statusName, $needle) !== false) { $clockedIn = false; break; }
    }
    // Empty status name means we couldn't look it up — assume clocked in
    // since the un_status_id is non-zero and the unit exists.
    return [
        'exists'      => true,
        'clocked_in'  => $clockedIn,
        'unit_id'     => (int) $row['id'],
        'unit_name'   => $row['name'],
        'unit_handle' => $row['handle'],
        'status_name' => $statusName,
        'since'       => $row['status_updated'],
    ];
}

function pu_is_clocked_in(int $memberId): bool {
    return (bool) pu_status_for_member($memberId)['clocked_in'];
}

/**
 * Phase 60 (2026-06-14) — auto-bind the member's location sources to
 * their personal unit on clock-in so the units page / map / responders
 * widget can resolve their position.
 *
 * Walks the member's comm_identifiers, pulls the per-mode source key
 * (OwnTracks tracker_id, APRS callsign-ssid, DMR radio_id, Meshtastic
 * node_id) out of values_json, and INSERTs or re-activates a row in
 * unit_location_bindings that points the matching location provider
 * at this responder.
 *
 * Idempotent — re-running for an already-bound combo just bumps the
 * existing row's active=1 + updated_at. Safe to call from pu_clock_in
 * every time.
 */
/**
 * Phase 62 (2026-06-14) — make sure unit_location_bindings has the
 * `source` and `assignment_id` columns that responder-detail.php
 * SELECTs and the unit-edit UI labels with. The SELECT was failing
 * silently inside a try/catch → empty $location_bindings → UI said
 * "No location sources configured" even though active rows existed.
 *
 * Phase 39A code already INSERTs these on multi-person assignments;
 * the columns just never got added. Add on first call, idempotent.
 */
function pu_ensure_binding_schema(): void {
    global $prefix;
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}unit_location_bindings`");
        $existing = array_column($cols, 'Field');
        if (!in_array('source', $existing, true)) {
            db_query("ALTER TABLE `{$prefix}unit_location_bindings`
                      ADD COLUMN `source` VARCHAR(32) NOT NULL DEFAULT 'manual'");
        }
        if (!in_array('assignment_id', $existing, true)) {
            db_query("ALTER TABLE `{$prefix}unit_location_bindings`
                      ADD COLUMN `assignment_id` INT NULL");
        }
    } catch (Exception $e) {
        error_log('pu_ensure_binding_schema: ' . $e->getMessage());
    }
}

function pu_autobind_locations(int $memberId, int $responderId): int {
    global $prefix;
    pu_ensure_binding_schema();
    $created = 0;

    // Map our comm_modes.code → location_providers.code → values_json key
    // that holds the identifier the location ingest writes to
    // location_reports.unit_identifier.
    $modeKeyMap = [
        'owntracks'  => ['provider' => 'owntracks',  'json_key' => 'tracker_id'],
        'aprs'       => ['provider' => 'aprs',       'json_key' => 'callsign_ssid'],
        'dmr'        => ['provider' => 'dmr',        'json_key' => 'radio_id'],
        'meshtastic' => ['provider' => 'meshtastic', 'json_key' => 'node_id'],
    ];

    // Resolve provider ids in one shot
    $providerIds = [];
    try {
        foreach (db_fetch_all("SELECT id, code FROM `{$prefix}location_providers` WHERE enabled = 1") as $p) {
            $providerIds[$p['code']] = (int) $p['id'];
        }
    } catch (Exception $e) { return 0; }
    if (!$providerIds) return 0;

    // Walk the member's comm identifiers
    $rows = [];
    try {
        $rows = db_fetch_all(
            "SELECT cm.code AS mode_code, mci.values_json
               FROM `{$prefix}member_comm_identifiers` mci
               JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
              WHERE mci.member_id = ?",
            [$memberId]
        );
    } catch (Exception $e) { return 0; }

    foreach ($rows as $r) {
        $map = $modeKeyMap[$r['mode_code']] ?? null;
        if (!$map) continue;
        $providerId = $providerIds[$map['provider']] ?? null;
        if (!$providerId) continue;
        $vals = json_decode($r['values_json'] ?? '{}', true) ?: [];
        $unitIdent = trim((string) ($vals[$map['json_key']] ?? ''));
        if ($unitIdent === '') continue;

        try {
            $existing = db_fetch_one(
                "SELECT id FROM `{$prefix}unit_location_bindings`
                  WHERE responder_id = ? AND provider_id = ? AND unit_identifier = ?",
                [$responderId, $providerId, $unitIdent]
            );
            if ($existing) {
                db_query(
                    "UPDATE `{$prefix}unit_location_bindings`
                        SET active = 1, source = 'personal' WHERE id = ?",
                    [(int) $existing['id']]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}unit_location_bindings`
                       (responder_id, provider_id, unit_identifier, priority, active, source)
                       VALUES (?, ?, ?, ?, 1, 'personal')",
                    [$responderId, $providerId, $unitIdent, 100]
                );
            }
            $created++;
        } catch (Exception $e) { /* skip; table column drift */ }
    }
    return $created;
}

/**
 * Find a member_status id by trying a list of candidate names (case-
 * insensitive). Returns the first match. Falls back to id=1 if nothing
 * matches — which is the legacy "Available" default on most installs.
 */
function _pu_status_id(string ...$candidates): int {
    global $prefix;
    // a beta tester beta 2026-06-29 bug: pu_clock_out writes this value to
    // responder.un_status_id which references the `un_status` table
    // (unit statuses), not `member_status` (person statuses). The
    // original query looked up the wrong table — no row matched, so
    // every clock_out hit the fallback `return 1` which set the unit
    // to un_status id=1 ("Available"). Result: clicking Clock out
    // actually marked the user Available; pu_status_for_member then
    // also looked up the wrong table, couldn't read the status name,
    // defaulted to clocked_in=true, and the badge stayed CLOCKED IN
    // forever — UI looked like nothing happened.
    //
    // Default un_status tables don't carry "Inactive"/"Released"/
    // "Off-duty". The closest off-duty option is "Out of Service".
    // We still walk the caller's preference list first (in case an
    // admin added matching custom statuses), then fall through to
    // the realistic seed values.
    // GH #73 (a beta tester 2026-07-07): 'Off Shift' — the very row
    // pu_ensure_schema() auto-creates when none of the other names
    // exist — was MISSING from this search list. On installs with fully
    // custom status names (a beta tester's), the walk found nothing, hit the
    // `return 1` fallback, and Clock-Out set the unit to un_status id 1
    // (his available status): the badge stayed CLOCKED IN and the click
    // "did nothing". The auto-created row goes first among fallbacks.
    // NOTE: every name here must also match pu_status_for_member()'s
    // $offIndicators list, or clock-out succeeds in the DB but the UI
    // still reports clocked_in=true.
    // ('In Quarters' was dropped from the fallbacks 2026-07-07: it means
    // "back at station, available" on seeded installs — group 'available'
    // — and it isn't an off-indicator, so clocking out to it re-broke the
    // badge. With Off Shift guaranteed by pu_ensure_schema it's unneeded.)
    $expanded = array_values(array_unique(array_merge($candidates, [
        'Off Shift', 'Off-Shift',
        'Out of Service', 'Unavailable',
    ])));
    foreach ($expanded as $name) {
        try {
            $r = db_fetch_one(
                "SELECT id FROM `{$prefix}un_status` WHERE LOWER(status_val) = LOWER(?) LIMIT 1",
                [$name]
            );
            if ($r) return (int) $r['id'];
        } catch (Exception $e) { /* try next */ }
    }
    return 1;
}
