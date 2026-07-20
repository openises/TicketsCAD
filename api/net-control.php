<?php
/**
 * NewUI v4.0 — Net Control Board payload (Phase 109 Slice A)
 *
 * GET /api/net-control.php?ticket_id=N
 *   → {
 *       event: { id, scope },
 *       zones: [ {id, name, code, color, sort_order} ],
 *       units: [ {
 *          assign_id, responder_id, name, callsign,
 *          current_zone_id, zone_name, zone_color,
 *          last_checkin_at, last_checkin_ago,
 *          global_status, global_status_color,
 *          roster: [ {member_id, name, handle, role, is_lead} ]
 *       } ]
 *     }
 *
 * "units" = responders on ACTIVE assignments to this ticket (clear IS
 * NULL / '00' year). roster = the whole unit_personnel_assignments
 * active roster per responder (Eric decision #5: whole roster, not just
 * the principal), batched in one IN() query — no N+1.
 *
 * RBAC: screen.net_control (read gate for the whole board).
 * Defensive: assigns/responder/member columns vary by install — every
 * optional query is wrapped so a missing column degrades, never crashes.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
// PAR cadence drives the board's last-check-in ramp (Slice B). Optional —
// degrade gracefully if the PAR engine isn't present on this install.
if (is_file(__DIR__ . '/../inc/par.php')) { require_once __DIR__ . '/../inc/par.php'; }

$prefix = $GLOBALS['db_prefix'] ?? '';

// Read gate. rbac_require_screen would render an HTML denial page,
// which is wrong for a JSON endpoint — check + json_error instead.
if (!rbac_can('screen.net_control')) {
    json_error('Insufficient permissions: net control board', 403);
}

/**
 * Local defensive fetch — log + return [] on SQL error.
 */
function _nc_fetch_all(string $sql, array $params = []): array {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        error_log('[net-control] ' . $e->getMessage() . ' :: ' . substr($sql, 0, 200));
        return [];
    }
}

/**
 * Humanise a "seconds ago" delta into a compact label.
 */
function _nc_ago(?string $ts): array {
    if (!$ts) return ['seconds' => null, 'label' => '—'];
    $t = strtotime($ts);
    if ($t === false) return ['seconds' => null, 'label' => '—'];
    $secs = time() - $t;
    if ($secs < 0) $secs = 0;
    if ($secs < 60)      $label = $secs . 's ago';
    elseif ($secs < 3600) $label = floor($secs / 60) . 'm ago';
    elseif ($secs < 86400) $label = floor($secs / 3600) . 'h ago';
    else                   $label = floor($secs / 86400) . 'd ago';
    return ['seconds' => $secs, 'label' => $label];
}

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    json_error('ticket_id is required');
}

// ── Event header ──
$event = db_fetch_one(
    "SELECT `id`, `scope` FROM `{$prefix}ticket` WHERE `id` = ?",
    [$ticketId]
);
if (!$event) {
    json_error('Event / incident not found', 404);
}

// ── Zones ──
$zoneRows = _nc_fetch_all(
    "SELECT `id`, `name`, `code`, `color`, `sort_order`
     FROM `{$prefix}event_zones`
     WHERE `ticket_id` = ? AND (`hide` = 0 OR `hide` IS NULL)
     ORDER BY `sort_order` ASC, `id` ASC",
    [$ticketId]
);
$zones = [];
$zoneById = [];
foreach ($zoneRows as $z) {
    $zone = [
        'id'         => (int) $z['id'],
        'name'       => (string) $z['name'],
        'code'       => (string) $z['code'],
        'color'      => $z['color'] !== null ? (string) $z['color'] : null,
        'sort_order' => (int) $z['sort_order'],
    ];
    $zones[] = $zone;
    $zoneById[(int) $z['id']] = $zone;
}

// ── Units: active assignments on this ticket, joined to responder +
//    current zone + global (un_status). Defensive: the zone columns
//    might be absent on a pre-migration assigns table, so try the rich
//    query first and fall back to a lean one. ──
$unitRows = _nc_fetch_all(
    "SELECT `a`.`id` AS `assign_id`,
            `a`.`responder_id`,
            `a`.`current_zone_id`,
            `a`.`last_checkin_at`,
            `a`.`zone_updated_at`,
            `a`.`signed_out_at`,
            `r`.`name` AS `name`,
            `r`.`handle` AS `callsign`,
            `r`.`un_status_id`,
            `us`.`status_val` AS `global_status`,
            `us`.`bg_color`   AS `global_status_color`
     FROM `{$prefix}assigns` `a`
     LEFT JOIN `{$prefix}responder` `r` ON `r`.`id` = `a`.`responder_id`
     LEFT JOIN `{$prefix}un_status` `us` ON `us`.`id` = `r`.`un_status_id`
     WHERE `a`.`ticket_id` = ?
       AND (`a`.`clear` IS NULL OR DATE_FORMAT(`a`.`clear`, '%y') = '00')
     ORDER BY `r`.`handle` ASC, `r`.`name` ASC",
    [$ticketId]
);
if (empty($unitRows)) {
    // Fallback for pre-migration assigns (no zone columns). Still
    // returns the roster + global status so the board is useful.
    $unitRows = _nc_fetch_all(
        "SELECT `a`.`id` AS `assign_id`,
                `a`.`responder_id`,
                `r`.`name` AS `name`,
                `r`.`handle` AS `callsign`,
                `r`.`un_status_id`,
                `us`.`status_val` AS `global_status`,
                `us`.`bg_color`   AS `global_status_color`
         FROM `{$prefix}assigns` `a`
         LEFT JOIN `{$prefix}responder` `r` ON `r`.`id` = `a`.`responder_id`
         LEFT JOIN `{$prefix}un_status` `us` ON `us`.`id` = `r`.`un_status_id`
         WHERE `a`.`ticket_id` = ?
           AND (`a`.`clear` IS NULL OR DATE_FORMAT(`a`.`clear`, '%y') = '00')
         ORDER BY `r`.`handle` ASC, `r`.`name` ASC",
        [$ticketId]
    );
}

// ── Batch the roster: one IN() query over all responders on the board.
//    unit_personnel_assignments.member_id references the `member` table
//    on modern installs (first_name/last_name/callsign). On some
//    installs it references `personnel` (forenames/surname). Try member
//    first, fall back to personnel. Whole active roster per Eric #5. ──
$responderIds = [];
foreach ($unitRows as $u) {
    $rid = (int) $u['responder_id'];
    if ($rid > 0) $responderIds[$rid] = true;
}
$responderIds = array_keys($responderIds);

$rosterByRid = [];
if (!empty($responderIds)) {
    $ph = implode(',', array_fill(0, count($responderIds), '?'));

    // Attempt 1 — `member` table (first_name/last_name/callsign).
    $rosterRows = _nc_fetch_all(
        "SELECT upa.responder_id,
                m.id AS member_id,
                TRIM(CONCAT(COALESCE(m.first_name,''), ' ', COALESCE(m.last_name,''))) AS name,
                m.callsign AS handle,
                upa.role AS role
         FROM `{$prefix}unit_personnel_assignments` upa
         JOIN `{$prefix}member` m ON m.id = upa.member_id
         WHERE upa.responder_id IN ({$ph})
           AND upa.status = 'active'
         ORDER BY (LOWER(upa.role) IN ('team lead','commander','lead')) DESC,
                  upa.assigned_at DESC, upa.id ASC",
        $responderIds
    );

    // Attempt 2 — `personnel` table (forenames/surname) if member gave
    // nothing (schema divergence handled per CLAUDE.md).
    if (empty($rosterRows)) {
        $rosterRows = _nc_fetch_all(
            "SELECT upa.responder_id,
                    p.id AS member_id,
                    TRIM(CONCAT(COALESCE(p.forenames,''), ' ', COALESCE(p.surname,''))) AS name,
                    p.amateur_radio_callsign AS handle,
                    upa.role AS role
             FROM `{$prefix}unit_personnel_assignments` upa
             JOIN `{$prefix}personnel` p ON p.id = upa.member_id
             WHERE upa.responder_id IN ({$ph})
               AND upa.status = 'active'
             ORDER BY (LOWER(upa.role) IN ('team lead','commander','lead')) DESC,
                      upa.assigned_at DESC, upa.id ASC",
            $responderIds
        );
    }

    foreach ($rosterRows as $rr) {
        $rid = (int) $rr['responder_id'];
        if (!isset($rosterByRid[$rid])) $rosterByRid[$rid] = [];
        $role = trim((string) ($rr['role'] ?? ''));
        $isLead = in_array(strtolower($role), ['team lead', 'commander', 'lead'], true)
                  && empty($rosterByRid[$rid]); // first (top-sorted) lead only
        $rosterByRid[$rid][] = [
            'member_id' => (int) $rr['member_id'],
            'name'      => trim((string) ($rr['name'] ?? '')) !== ''
                             ? trim((string) $rr['name'])
                             : ('#' . (int) $rr['member_id']),
            'handle'    => trim((string) ($rr['handle'] ?? '')),
            'role'      => $role,
            'is_lead'   => $isLead,
            'equipment' => [],   // filled by the batch below (Slice C)
        ];
    }
}

// ── Slice C: batch the issued equipment per member (one IN() over every member
//    on the board), so each roster row shows the gear that person carries
//    (📻 DMR-4, 📡 Mesh-2) — "who's in Zone 3 and has DMR?". Gear follows the
//    person via the open equipment_assignments ledger. Defensive: the table is
//    absent until Slice C's migration runs, so a failure just leaves rows bare.
$memberIds = [];
foreach ($rosterByRid as $list) {
    foreach ($list as $mm) { if ((int) $mm['member_id'] > 0) $memberIds[(int) $mm['member_id']] = true; }
}
$memberIds = array_keys($memberIds);
if (!empty($memberIds)) {
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $eqRows = _nc_fetch_all(
        "SELECT a.member_id, e.id AS equipment_id, e.name, e.serial_number, e.asset_tag,
                t.name AS type_name, t.icon AS type_icon
         FROM `{$prefix}equipment_assignments` a
         JOIN `{$prefix}newui_equipment` e ON e.id = a.equipment_id
         LEFT JOIN `{$prefix}newui_equipment_types` t ON t.id = e.equipment_type_id
         WHERE a.member_id IN ({$ph}) AND a.returned_at IS NULL
         ORDER BY t.name, e.name",
        $memberIds
    );
    $eqByMember = [];
    foreach ($eqRows as $er) {
        $mid = (int) $er['member_id'];
        $label = trim((string) ($er['name'] ?? '')) !== '' ? (string) $er['name'] : (string) ($er['type_name'] ?? 'Item');
        $tag = trim((string) ($er['asset_tag'] ?? '')) !== '' ? (string) $er['asset_tag'] : (string) ($er['serial_number'] ?? '');
        $eqByMember[$mid][] = [
            'equipment_id' => (int) $er['equipment_id'],
            'label'        => $label . ($tag !== '' ? ' (' . $tag . ')' : ''),
            'type_name'    => (string) ($er['type_name'] ?? ''),
            'type_icon'    => $er['type_icon'] !== null ? (int) $er['type_icon'] : null,
        ];
    }
    foreach ($rosterByRid as $rid => &$list) {
        foreach ($list as &$mm) {
            $mm['equipment'] = $eqByMember[(int) $mm['member_id']] ?? [];
        }
        unset($mm);
    }
    unset($list);
}

// ── Assemble units. Signed-out units (Slice B) are split into a separate
//    tray so they drop off the active board but can be signed back in. ──
$units      = [];
$signedOut  = [];
foreach ($unitRows as $u) {
    $rid  = (int) $u['responder_id'];
    $zid  = isset($u['current_zone_id']) ? (int) $u['current_zone_id'] : 0;
    $zone = ($zid > 0 && isset($zoneById[$zid])) ? $zoneById[$zid] : null;
    $ago  = _nc_ago($u['last_checkin_at'] ?? null);
    $signedOutAt = $u['signed_out_at'] ?? null;
    $isSignedOut = ($signedOutAt !== null && substr((string) $signedOutAt, 0, 4) !== '0000');

    $row = [
        'assign_id'           => (int) $u['assign_id'],
        'responder_id'        => $rid,
        'name'                => (string) ($u['name'] ?? ('Unit #' . $rid)),
        'callsign'            => (string) ($u['callsign'] ?? ''),
        'current_zone_id'     => $zone ? $zone['id'] : null,
        'zone_name'           => $zone ? $zone['name'] : null,
        'zone_color'          => $zone ? $zone['color'] : null,
        'last_checkin_at'     => $u['last_checkin_at'] ?? null,
        'last_checkin_ago'    => $ago['label'],
        'last_checkin_secs'   => $ago['seconds'],
        'global_status'       => $u['global_status'] !== null ? (string) $u['global_status'] : null,
        'global_status_color' => $u['global_status_color'] !== null ? (string) $u['global_status_color'] : null,
        'roster'              => $rosterByRid[$rid] ?? [],
        'signed_out'          => $isSignedOut,
        'signed_out_at'       => $isSignedOut ? (string) $signedOutAt : null,
    ];
    if ($isSignedOut) { $signedOut[] = $row; } else { $units[] = $row; }
}

// PAR cadence for the last-check-in ramp — configurable (per-incident override
// → incident-type → agency default → settings), 0 = PAR disabled for this
// incident. The board colors "last check-in" amber at the cadence, red past it.
$parEnabled = function_exists('par_enabled') ? par_enabled() : false;
$parCadenceSecs = 0;
if ($parEnabled && function_exists('par_resolve_cadence')) {
    try {
        $cad = par_resolve_cadence($ticketId);
        $parCadenceSecs = max(0, (int) ($cad['cadence_minutes'] ?? 0) * 60);
    } catch (Throwable $e) { $parCadenceSecs = 0; }
}

json_response([
    'event' => [
        'id'    => (int) $event['id'],
        'scope' => (string) ($event['scope'] ?? ''),
    ],
    'zones'      => $zones,
    'units'      => $units,
    'signed_out' => $signedOut,
    'par'        => ['enabled' => $parEnabled, 'cadence_secs' => $parCadenceSecs],
    'caps'       => ['can_issue_equipment' => rbac_can('action.issue_equipment') || is_admin()],
]);
