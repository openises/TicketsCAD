<?php
/**
 * Phase 41 — OwnTracks config push + provisioning + secret rotation.
 *
 * Routes:
 *
 *   GET  ?action=link&member_id=N&mode=qr|url|email
 *        — provision a new tracking token for the member and return either:
 *            mode=url  → owntracks:///?inline=<json>  (default)
 *            mode=qr   → SVG / PNG QR encoded with the same URL
 *            mode=email → send the URL to the member via SMTP; reply with delivery status
 *
 *   POST ?action=push_config&member_id=N&payload=…
 *        — queue a setConfiguration payload that will piggy-back on the
 *          NEXT position POST from this member's OwnTracks client.
 *
 *   POST ?action=rotate&member_id=N&label=…
 *        — generate a new tracking token for the member with the configured
 *          dual-window expiry on the OLD tokens; returns the new secret ONCE.
 *
 *   GET  ?action=stale&days=N
 *        — report members still using a token older than N days (default 30).
 *
 *   GET  ?action=push_pending&member_id=N
 *        — INTERNAL: the OwnTracks ingest handler calls this to fetch any
 *          queued setConfiguration payload for the member, so it can be
 *          included in the position-POST response.
 *
 * RBAC: action.manage_config for the admin-side actions (link/push/rotate/stale).
 *
 * NOTE: the actual /api/location.php ingest needs a one-line patch to look
 * up auth_token_id when accepting an OwnTracks basic-auth header. Tracked
 * separately as a follow-on.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
// 2026-06-14 (Phase 52b) — owntracks-config.php can now be require_once'd
// by other endpoints (incident-assign.php for the active-incident push
// hook). When OT_CONFIG_LIBRARY_ONLY is defined before include, we
// declare the helper functions but skip auth.php (which can short-
// circuit the calling request) and the action dispatch at the bottom.
if (!defined('OT_CONFIG_LIBRARY_ONLY')) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : $_POST;
    if (!$action && !empty($input['action'])) $action = $input['action'];
}

// ─── shared helpers ────────────────────────────────────────────
function _mint_token(int $memberId, ?string $label, ?int $createdBy, int $dualWindowDays = 7): array {
    global $prefix;
    // Generate a fresh secret (RFC 4648 base32-ish, URL-safe)
    $raw = bin2hex(random_bytes(24)); // 48-char hex
    $hash = hash('sha256', $raw);

    // Expire old tokens for this member after the dual-window.
    db_query(
        "UPDATE `{$prefix}member_tracking_tokens`
            SET valid_until = COALESCE(valid_until, DATE_ADD(NOW(), INTERVAL ? DAY))
          WHERE member_id = ? AND revoked_at IS NULL AND valid_until IS NULL",
        [$dualWindowDays, $memberId]
    );

    db_query(
        "INSERT INTO `{$prefix}member_tracking_tokens`
            (member_id, token_label, secret_hash, created_by)
         VALUES (?, ?, ?, ?)",
        [$memberId, $label, $hash, $createdBy ?: null]
    );
    return [
        'id'         => (int) db_insert_id(),
        'secret_raw' => $raw,         // show ONCE; not persisted in clear
    ];
}

function _config_payload_for_member(int $memberId, string $secret): array {
    global $prefix;
    $cad = (function () {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    })();
    // member's login lives on the user table. Two linkage patterns exist —
    // `member.user_id → user.id` (newer) and `user.member → member.id` (legacy).
    $m = db_fetch_one(
        "SELECT u.user AS username, m.email
           FROM `{$prefix}member` m
           LEFT JOIN `{$prefix}user` u ON (u.id = m.user_id OR u.member = m.id)
          WHERE m.id = ?
          ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
          LIMIT 1",
        [$memberId]
    );
    $username = (!empty($m['username'])) ? $m['username'] : ('member-' . $memberId);

    // 2026-06-14 (Phase 51): three-layer config.
    //   Layer A — hardcoded fallback (battery-friendly defaults from Phase 50)
    //   Layer B — admin global defaults (owntracks_default_* settings)
    //   Layer C — per-member overrides (member.owntracks_overrides JSON)
    // Settings panel writes Layer B, roster member-detail card writes
    // Layer C. Both auto-push via the outbox so changes reach phones
    // on the next post.
    return _ot_build_layered_config($memberId, $username, $secret, $cad);
}

/**
 * Three-layer OwnTracks config builder. Keep this exposed so the
 * settings.php admin and the roster member-detail card can both call
 * it (one to preview the effective config, the other to push).
 */
function _ot_build_layered_config(int $memberId, string $username, string $secret, string $cad): array {
    global $prefix;

    // Layer A — hardcoded baseline. 2026-06-14 (Phase 55) — rewritten
    // to be conservative-by-default for off-duty / unassigned members.
    //
    // Per OwnTracks Android docs (https://owntracks.org/booklet/features/location/):
    //   monitoring=2 (Significant) "relies mostly on cell tower and WiFi
    //   location to conserve power" — i.e. NO GPS at all most of the
    //   time. This is the lowest battery mode that still publishes
    //   occasional updates.
    //
    // Off-duty target: barely noticeable battery cost, hourly heartbeat
    // so dispatch knows the phone is alive, position only reported when
    // the member actually moves a meaningful distance (500m+).
    //
    //   monitoring                = 2 (Significant)     — cell/wifi-only, no GPS
    //   pubInterval               = 60 minutes          — hourly still-alive ping
    //   locatorInterval           = 600 seconds (10min) — hard floor between updates
    //   locatorDisplacement       = 500m                — only publish if moved
    //                                                     significantly (a block+)
    //   locatorPriority           = 2 (BalancedPower)   — Wi-Fi/cell first,
    //                                                     GPS only when needed
    //   ignoreInaccurateLocations = 500m                — drop poor fixes
    //
    // When a member is assigned to an incident, Layer D (added in
    // Phase 52b) takes over and dramatically tightens the locator —
    // 30s/5min in Move mode with HighAccuracy GPS. That's the only
    // time we burn battery. The result over a 12-hour shift: minor
    // background drain off-duty + a focused burst when actually on
    // a call.
    $cfg = [
        '_type'                               => 'configuration',
        'mode'                                => 3,
        'url'                                 => $cad . '/api/location.php?provider=owntracks&u=' . urlencode($username),
        'auth'                                => true,
        'username'                            => $username,
        'password'                            => $secret,
        'deviceId'                            => 'phone',
        'tid'                                 => strtoupper(substr($username, 0, 2)),
        'monitoring'                          => 2,
        'locatorInterval'                     => 600,
        'locatorDisplacement'                 => 500,
        'pegLocatorFastestIntervalToInterval' => true,
        'moveModeLocatorInterval'             => 60,
        'locatorPriority'                     => 2,
        'pubInterval'                         => 60,
        'ignoreInaccurateLocations'           => 500,
    ];

    // Layer B — admin global defaults. Settings panel writes them as
    // named keys (owntracks_default_monitoring, etc.). Map back into
    // the OwnTracks config keys.
    $tunable = _ot_tunable_keys();
    foreach ($tunable as $cfgKey => $meta) {
        $val = get_setting('owntracks_default_' . $meta['settings_key'], null);
        if ($val !== null && $val !== '') {
            $cfg[$cfgKey] = $meta['cast']($val);
        }
    }

    // Phase 50 legacy — owntracks_config_overrides was a raw JSON blob
    // before the Settings panel existed. Honor it for backward compat,
    // but Layer C (per-member) still wins below.
    $rawOverride = get_setting('owntracks_config_overrides', '');
    if (!empty($rawOverride)) {
        $over = json_decode($rawOverride, true);
        if (is_array($over)) {
            foreach ($over as $k => $v) { $cfg[$k] = $v; }
        }
    }

    // Layer C — per-member overrides. JSON object on member.owntracks_
    // overrides; an empty/missing entry means "inherit from defaults".
    try {
        $memberRow = db_fetch_one(
            "SELECT owntracks_overrides FROM `{$prefix}member` WHERE id = ?",
            [$memberId]
        );
        if ($memberRow && !empty($memberRow['owntracks_overrides'])) {
            $perMember = json_decode($memberRow['owntracks_overrides'], true) ?: [];
            foreach ($tunable as $cfgKey => $meta) {
                if (array_key_exists($meta['settings_key'], $perMember)
                        && $perMember[$meta['settings_key']] !== ''
                        && $perMember[$meta['settings_key']] !== null) {
                    $cfg[$cfgKey] = $meta['cast']($perMember[$meta['settings_key']]);
                }
            }
        }
    } catch (Exception $e) { /* column may be absent — self-heal handles it */ }

    // Layer D — incident-active. 2026-06-14 (Phase 52b, refined Phase 55):
    // if the member's currently assigned to any unit that has an open
    // (not-cleared) incident assignment, FLIP TO MOVE MODE and tighten
    // the locator. This is the only time we burn GPS battery.
    //
    // Eric's spec: 5min stationary, 30s when moving. Plus high-accuracy
    // GPS so dispatch can see the actual position on the incident map.
    //
    //   monitoring                = 3 (Move)         — overrides baseline=2
    //   moveModeLocatorInterval   = 30s              — wake every 30s
    //   pubInterval               = 5 minutes        — still-alive ping
    //   locatorDisplacement       = 20m              — tighter movement floor
    //   locatorPriority           = 1 (HighAccuracy) — GPS fused with cell/wifi
    //   ignoreInaccurateLocations = 100m             — tighter accuracy floor
    if (_ot_member_has_active_incident($memberId)) {
        $cfg['monitoring']               = 3;
        $cfg['moveModeLocatorInterval']  = 30;
        $cfg['locatorInterval']          = 30;
        $cfg['pubInterval']              = 5;
        $cfg['locatorDisplacement']      = 20;
        $cfg['locatorPriority']          = 1;
        $cfg['ignoreInaccurateLocations'] = 100;
    }

    // tid pulled from the member's comm_identifier row if set (Phase 50).
    try {
        $row = db_fetch_one(
            "SELECT mci.values_json
               FROM `{$prefix}member_comm_identifiers` mci
               JOIN `{$prefix}comm_modes` cm ON mci.comm_mode_id = cm.id
              WHERE mci.member_id = ? AND cm.code = 'owntracks'
              ORDER BY COALESCE(NULLIF(mci.sort_order, 0), mci.id) LIMIT 1",
            [$memberId]
        );
        if ($row && !empty($row['values_json'])) {
            $vals = json_decode($row['values_json'], true) ?: [];
            $tid = $vals['tracker_id'] ?? $vals['tid'] ?? null;
            if ($tid) {
                $cfg['tid'] = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tid), 0, 2));
            }
        }
    } catch (Exception $e) { /* table may be absent on fresh installs */ }

    return $cfg;
}

/**
 * Canonical list of admin-tunable OwnTracks knobs. Each entry maps an
 * OwnTracks config key (passed verbatim to the phone) to:
 *   settings_key — column suffix in the settings table + the JSON key
 *                  used in member.owntracks_overrides
 *   label, hint  — for the Settings panel + per-member UI
 *   type         — 'int' | 'select' | 'bool', drives the form widget
 *   options      — for type=select: [value => label, ...]
 *   cast         — coerces the stored string into the right PHP type
 *                  for the JSON payload
 */
function _ot_tunable_keys(): array {
    $int = function ($v) { return (int) $v; };
    $bool = function ($v) { return (bool) (int) $v; };
    return [
        'monitoring' => [
            'settings_key' => 'monitoring',
            'label'        => 'Monitoring mode',
            'hint'         => 'Quiet = no auto publish. Manual = on-demand. Significant = OS-driven, very low battery (recommended). Move = aggressive, for active vehicle tracking.',
            'type'         => 'select',
            'options'      => [0 => 'Quiet (0)', 1 => 'Manual (1)', 2 => 'Significant (2)', 3 => 'Move (3)'],
            'cast'         => $int,
        ],
        'locatorInterval' => [
            'settings_key' => 'locator_interval',
            'label'        => 'Locator interval (seconds)',
            'hint'         => 'Hard floor between any publishes. Lower = more reports, more battery. Recommended 60s for daily use.',
            'type'         => 'int',
            'cast'         => $int,
        ],
        'locatorDisplacement' => [
            'settings_key' => 'locator_displacement',
            'label'        => 'Locator displacement (meters)',
            'hint'         => 'Skip publish if phone has not moved at least this far. 50m is a good general default; 10m for foot patrol, 100m for vehicle.',
            'type'         => 'int',
            'cast'         => $int,
        ],
        'moveModeLocatorInterval' => [
            'settings_key' => 'move_locator_interval',
            'label'        => 'Move-mode interval (seconds)',
            'hint'         => 'Used only when Monitoring = Move. Aggressive: 15-30s. Battery-friendly: 60-120s.',
            'type'         => 'int',
            'cast'         => $int,
        ],
        'locatorPriority' => [
            'settings_key' => 'locator_priority',
            'label'        => 'Locator priority',
            'hint'         => 'BalancedPower uses Wi-Fi/cell first; HighAccuracy uses GPS always (~10x more battery). NoPower only fires when other apps have asked for a location.',
            'type'         => 'select',
            'options'      => [0 => 'HighAccuracy (GPS)', 1 => 'BalancedPower (Wi-Fi/cell + GPS)', 2 => 'LowPower (cell-only)', 3 => 'NoPower (piggyback)'],
            'cast'         => $int,
        ],
        'pubInterval' => [
            'settings_key' => 'pub_interval',
            'label'        => 'Periodic publish interval (minutes)',
            'hint'         => 'Still-alive ping when the phone is stationary. 30 minutes is a good default for daily use.',
            'type'         => 'int',
            'cast'         => $int,
        ],
        'ignoreInaccurateLocations' => [
            'settings_key' => 'ignore_inaccurate',
            'label'        => 'Ignore inaccurate fixes (meters)',
            'hint'         => 'Drop GPS fixes worse than this accuracy. 200m is generous; tighten to 50m only if the area has good sky view.',
            'type'         => 'int',
            'cast'         => $int,
        ],
    ];
}

/**
 * Add member.owntracks_overrides column if missing. Self-healing pattern
 * (matches Phase 48's _ensure_sort_order_column). Idempotent.
 */
function _ot_ensure_member_overrides_column(): void {
    global $prefix;
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}member` LIKE 'owntracks_overrides'");
        if (!$cols) {
            db_query("ALTER TABLE `{$prefix}member` ADD COLUMN `owntracks_overrides` TEXT NULL");
        }
    } catch (Exception $e) {
        error_log('owntracks _ensure_member_overrides_column: ' . $e->getMessage());
    }
}

/**
 * Queue a setConfiguration push to one member's outbox. Phones pick
 * it up on the next position POST (within seconds for active devices).
 */
function _ot_push_to_member(int $memberId, array $cfg, ?int $byUserId = null): void {
    global $prefix;
    // 2026-06-14 (Phase 52a): OwnTracks Android silently drops the WHOLE
    // setConfiguration message if it contains immutable keys. Per the
    // OwnTracks docs, the connection-layer keys (mode, deviceId, host,
    // port, clientId, tls, keepalive, username, password, url, auth)
    // can only be set via initial config import — not via remote push.
    // Stripping them lets the locator-tuning keys (monitoring, locator*,
    // pubInterval, tid, etc.) actually land. That's why Eric's TID
    // stayed "ng" instead of "EO" after a Phase 50 push even though the
    // outbox row was consumed — the entire message got dropped.
    $immutableViaRemote = [
        'mode', 'deviceId', 'username', 'password', 'url', 'auth',
        'host', 'port', 'clientId', 'tls', 'keepalive', 'allowRemoteLocation',
    ];
    foreach ($immutableViaRemote as $k) {
        unset($cfg[$k]);
    }
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}owntracks_outbox` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `member_id` INT NOT NULL,
        `payload_json` TEXT NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `consumed_at` DATETIME NULL,
        `created_by` INT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_pending` (`member_id`, `consumed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 2026-06-14 (Phase 52c): correct setConfiguration message shape per
    // OwnTracks Android's deserializer. Verified against the source
    // fixture project/app/src/test/resources/fixtures/cmd_set_configuration.json:
    //
    //   { "_type": "cmd",
    //     "action": "setConfiguration",
    //     "configuration": { "_type": "configuration", ... } }
    //
    // We were sending `_type: "setConfiguration"` (no action key) which
    // matches NO registered message type. Jackson threw "unable to parse
    // JSON" in the app logs, exactly what Eric reported, and the message
    // was discarded silently. That's why TID stayed "ng" and post rate
    // didn't change despite outbox rows consuming.
    db_query(
        "INSERT INTO `{$prefix}owntracks_outbox` (member_id, payload_json, created_by) VALUES (?, ?, ?)",
        [$memberId, json_encode([
            '_type'         => 'cmd',
            'action'        => 'setConfiguration',
            'configuration' => $cfg,
        ]), $byUserId]
    );
}

/**
 * Phase 52b — does this member currently have any assignment to a
 * responder (unit) that has an open ticket assignment? Walks:
 *   member → unit_personnel_assignments → responder → assigns
 * and returns true if any active assigns row exists (`clear` is NULL).
 */
function _ot_member_has_active_incident(int $memberId): bool {
    global $prefix;
    try {
        $row = db_fetch_one(
            "SELECT 1
               FROM `{$prefix}unit_personnel_assignments` upa
               JOIN `{$prefix}assigns` a ON a.responder_id = upa.responder_id
              WHERE upa.member_id = ?
                AND (upa.status IS NULL OR upa.status != 'released')
                AND (a.clear IS NULL OR a.clear = '' OR a.clear = '0000-00-00 00:00:00')
              LIMIT 1",
            [$memberId]
        );
        return !empty($row);
    } catch (Exception $e) {
        // unit_personnel_assignments table may be absent on older installs.
        return false;
    }
}

/**
 * Phase 52b — for every member currently on the given responder (unit),
 * recompute and push their OwnTracks config. Use after an assign INSERT
 * or a clear UPDATE so phones converge to the right intensity on the
 * next post.
 */
function _ot_recompute_for_responder(int $responderId, ?int $byUserId = null): int {
    global $prefix;
    try {
        $members = db_fetch_all(
            "SELECT DISTINCT upa.member_id
               FROM `{$prefix}unit_personnel_assignments` upa
              WHERE upa.responder_id = ?
                AND (upa.status IS NULL OR upa.status != 'released')",
            [$responderId]
        );
    } catch (Exception $e) {
        return 0;
    }
    $count = 0;
    $cad = (function () {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    })();
    foreach ($members as $m) {
        $mid = (int) $m['member_id'];
        // Only push to members with an un-revoked tracking token —
        // otherwise the outbox row sits forever.
        $hasToken = db_fetch_one(
            "SELECT 1 FROM `{$prefix}member_tracking_tokens`
              WHERE member_id = ? AND revoked_at IS NULL
                AND (valid_until IS NULL OR valid_until > NOW()) LIMIT 1",
            [$mid]
        );
        if (!$hasToken) continue;
        $u = db_fetch_one(
            "SELECT u.user AS username FROM `{$prefix}member` me
             LEFT JOIN `{$prefix}user` u ON (u.id = me.user_id OR u.member = me.id)
             WHERE me.id = ?
             ORDER BY (CASE WHEN u.member = me.id THEN 0 ELSE 1 END), u.id
             LIMIT 1",
            [$mid]
        );
        $username = $u['username'] ?? ('member-' . $mid);
        $cfg = _ot_build_layered_config($mid, $username, '<unchanged>', $cad);
        _ot_push_to_member($mid, $cfg, $byUserId);
        $count++;
    }
    return $count;
}

/**
 * Auto-push the current effective config to every member that has an
 * un-revoked OwnTracks tracking token. Called after a global default
 * save so all active phones converge on the new settings.
 */
function _ot_push_to_all_active(?int $byUserId = null): int {
    global $prefix;
    $rows = db_fetch_all(
        "SELECT DISTINCT member_id FROM `{$prefix}member_tracking_tokens`
          WHERE revoked_at IS NULL
            AND (valid_until IS NULL OR valid_until > NOW())"
    );
    $count = 0;
    foreach ($rows as $r) {
        $mid = (int) $r['member_id'];
        $m = db_fetch_one(
            "SELECT u.user AS username FROM `{$prefix}member` m
             LEFT JOIN `{$prefix}user` u ON (u.id = m.user_id OR u.member = m.id)
             WHERE m.id = ?
             ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
             LIMIT 1",
            [$mid]
        );
        $username = $m['username'] ?? ('member-' . $mid);
        $cad = (function () {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        })();
        // secret is unused by _ot_push_to_member (stripped before queue)
        $cfg = _ot_build_layered_config($mid, $username, '<unchanged>', $cad);
        _ot_push_to_member($mid, $cfg, $byUserId);
        $count++;
    }
    return $count;
}

function _admin_check(): void {
    if (!rbac_can('action.manage_config')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden — requires action.manage_config']);
        exit;
    }
}

// 2026-06-14 (Phase 52b) — everything below this line is the HTTP
// action dispatch. When this file is require_once'd from another
// endpoint (for the incident-assign hook), OT_CONFIG_LIBRARY_ONLY
// is defined and we skip the dispatch entirely so we don't double-
// handle the calling request's $_GET/$_POST.
if (defined('OT_CONFIG_LIBRARY_ONLY')) return;

// ─── action=get_defaults ───────────────────────────────────────
// Returns the admin-tunable knob metadata + each one's currently
// stored global default (NULL means "use hardcoded fallback").
if ($action === 'get_defaults' && $method === 'GET') {
    _admin_check();
    $tunable = _ot_tunable_keys();
    $out = [];
    foreach ($tunable as $cfgKey => $meta) {
        $stored = get_setting('owntracks_default_' . $meta['settings_key'], null);
        $out[] = [
            'config_key'   => $cfgKey,
            'settings_key' => $meta['settings_key'],
            'label'        => $meta['label'],
            'hint'         => $meta['hint'],
            'type'         => $meta['type'],
            'options'      => $meta['options'] ?? null,
            'value'        => $stored,  // null = inherit hardcoded
        ];
    }
    json_response(['defaults' => $out]);
}

// ─── action=save_defaults ──────────────────────────────────────
// Body: { csrf_token, settings: { monitoring: 2, locator_interval: 60, ... } }
// Empty/null value = clear the override (revert to hardcoded fallback).
// Saves THEN queues a setConfiguration push to every active member.
if ($action === 'save_defaults' && $method === 'POST') {
    _admin_check();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $incoming = $input['settings'] ?? [];
    if (!is_array($incoming)) json_error('settings object required');
    $tunable = _ot_tunable_keys();
    $allowedKeys = [];
    foreach ($tunable as $meta) { $allowedKeys[$meta['settings_key']] = true; }
    foreach ($incoming as $k => $v) {
        if (!isset($allowedKeys[$k])) continue;
        $name = 'owntracks_default_' . $k;
        // Empty string / null = clear it; otherwise store as string
        // (get_setting handles type coercion via the tunable cast fn).
        if ($v === '' || $v === null) {
            db_query("DELETE FROM " . db_table('settings') . " WHERE name = ?", [$name]);
        } else {
            set_setting($name, (string) $v);
        }
    }
    audit_log('config', 'update', 'owntracks_defaults', 0, 'Updated OwnTracks global defaults');
    $pushed = _ot_push_to_all_active((int) ($_SESSION['user_id'] ?? 0) ?: null);
    json_response(['saved' => true, 'pushed_to' => $pushed]);
}

// ─── action=get_member_overrides ───────────────────────────────
// Returns the per-member JSON overrides + the effective config (post-
// layering) so the UI can show "this is what the phone gets right now".
if ($action === 'get_member_overrides' && $method === 'GET') {
    _admin_check();
    _ot_ensure_member_overrides_column();
    $mid = (int) ($_GET['member_id'] ?? 0);
    if ($mid <= 0) json_error('member_id required');
    $row = db_fetch_one("SELECT owntracks_overrides FROM " . db_table('member') . " WHERE id = ?", [$mid]);
    $overrides = $row && !empty($row['owntracks_overrides'])
        ? (json_decode($row['owntracks_overrides'], true) ?: [])
        : [];

    // Compute the effective config so the UI can preview what the phone
    // sees. Use placeholder credentials — the preview is informational.
    $m = db_fetch_one(
        "SELECT u.user AS username FROM " . db_table('member') . " m
         LEFT JOIN " . db_table('user') . " u ON (u.id = m.user_id OR u.member = m.id)
         WHERE m.id = ?
         ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
         LIMIT 1",
        [$mid]
    );
    $username = $m['username'] ?? ('member-' . $mid);
    $cad = (function () {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    })();
    $effective = _ot_build_layered_config($mid, $username, '<not shown>', $cad);
    unset($effective['password'], $effective['url']);

    json_response([
        'overrides' => $overrides,
        'effective' => $effective,
    ]);
}

// ─── action=save_member_overrides ──────────────────────────────
// Body: { csrf_token, member_id, overrides: { monitoring: 3, ... } }
// Empty object clears all per-member overrides. Auto-pushes to that
// one member only.
if ($action === 'save_member_overrides' && $method === 'POST') {
    _admin_check();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    _ot_ensure_member_overrides_column();
    $mid = (int) ($input['member_id'] ?? 0);
    $incoming = $input['overrides'] ?? [];
    if ($mid <= 0) json_error('member_id required');
    if (!is_array($incoming)) json_error('overrides object required');

    // Filter to known keys; drop empty/null entries so the JSON only
    // contains real overrides ("inherit" is the absence of a key).
    $tunable = _ot_tunable_keys();
    $allowedKeys = [];
    foreach ($tunable as $meta) { $allowedKeys[$meta['settings_key']] = true; }
    $clean = [];
    foreach ($incoming as $k => $v) {
        if (!isset($allowedKeys[$k])) continue;
        if ($v === '' || $v === null) continue;
        $clean[$k] = $v;
    }

    $json = empty($clean) ? null : json_encode($clean);
    db_query(
        "UPDATE " . db_table('member') . " SET owntracks_overrides = ? WHERE id = ?",
        [$json, $mid]
    );
    audit_log('config', 'update', 'owntracks_overrides', $mid, 'Updated per-member OwnTracks overrides');

    // Auto-push to this member only.
    $m = db_fetch_one(
        "SELECT u.user AS username FROM " . db_table('member') . " m
         LEFT JOIN " . db_table('user') . " u ON (u.id = m.user_id OR u.member = m.id)
         WHERE m.id = ?
         ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
         LIMIT 1",
        [$mid]
    );
    $username = $m['username'] ?? ('member-' . $mid);
    $cad = (function () {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    })();
    $cfg = _ot_build_layered_config($mid, $username, '<unchanged>', $cad);
    _ot_push_to_member($mid, $cfg, (int) ($_SESSION['user_id'] ?? 0) ?: null);

    json_response(['saved' => true, 'pushed' => true, 'overrides' => $clean]);
}

// ─── action=get_install_diagnostics ────────────────────────────
// 2026-06-14 (Phase 53) — install-wide OwnTracks summary for the
// diagnostics page. Returns one row per member with an active
// (un-revoked) tracking token. Counts come from location_reports.
if ($action === 'get_install_diagnostics' && $method === 'GET') {
    _admin_check();
    try {
        // One row per ACTIVE token's member (a member with multiple
        // tokens shows up once with their newest token).
        $rows = db_fetch_all(
            "SELECT m.id AS member_id,
                    CONCAT_WS(' ', m.first_name, m.last_name) AS member_name,
                    (SELECT u.user FROM `{$prefix}user` u
                      WHERE u.member = m.id OR u.id = m.user_id
                      ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
                      LIMIT 1) AS username,
                    t.id AS token_id,
                    t.token_label,
                    t.created_at AS token_created,
                    t.last_used_at,
                    (SELECT COUNT(*) FROM `{$prefix}location_reports` lr
                       WHERE lr.auth_token_id = t.id
                         AND lr.received_at > NOW() - INTERVAL 1 HOUR) AS posts_1h,
                    (SELECT COUNT(*) FROM `{$prefix}location_reports` lr
                       WHERE lr.auth_token_id = t.id
                         AND lr.received_at > NOW() - INTERVAL 24 HOUR) AS posts_24h,
                    (SELECT MAX(received_at) FROM `{$prefix}location_reports` lr
                       WHERE lr.auth_token_id = t.id) AS last_post,
                    (SELECT COUNT(*) FROM `{$prefix}owntracks_outbox` ob
                       WHERE ob.member_id = m.id
                         AND ob.consumed_at IS NULL) AS outbox_pending,
                    m.owntracks_overrides IS NOT NULL AS has_overrides
               FROM `{$prefix}member_tracking_tokens` t
               JOIN `{$prefix}member` m ON m.id = t.member_id
              WHERE t.revoked_at IS NULL
                AND (t.valid_until IS NULL OR t.valid_until > NOW())
                AND t.id = (
                    SELECT MAX(t2.id) FROM `{$prefix}member_tracking_tokens` t2
                     WHERE t2.member_id = m.id AND t2.revoked_at IS NULL
                       AND (t2.valid_until IS NULL OR t2.valid_until > NOW())
                )
              ORDER BY last_post DESC, m.last_name, m.first_name"
        );

        // Install-wide totals + stale list
        $totals = db_fetch_one(
            "SELECT
                COUNT(DISTINCT t.id) AS active_tokens,
                COUNT(DISTINCT t.member_id) AS active_members,
                (SELECT COUNT(*) FROM `{$prefix}location_reports`
                  WHERE received_at > NOW() - INTERVAL 1 HOUR) AS posts_1h_total,
                (SELECT COUNT(*) FROM `{$prefix}owntracks_outbox`
                  WHERE consumed_at IS NULL) AS outbox_pending_total
               FROM `{$prefix}member_tracking_tokens` t
              WHERE t.revoked_at IS NULL
                AND (t.valid_until IS NULL OR t.valid_until > NOW())"
        );

        json_response([
            'members' => $rows,
            'totals'  => $totals,
            'now'     => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        json_error('diagnostics failed: ' . $e->getMessage(), 500);
    }
}

// ─── action=get_member_diagnostics ─────────────────────────────
// 2026-06-14 (Phase 53) — drill-down view for one member. Returns:
//   * effective_config — what _ot_build_layered_config produces RIGHT
//     NOW (credentials redacted)
//   * layer_breakdown — for each tunable key, which layer set the
//     value (hardcoded / admin / per-member / incident-active)
//   * recent_posts — last 20 location_reports raw_data + interpreted
//     tid / battery / accuracy / trigger fields
//   * outbox — last 10 outbox rows (pending + consumed) with payload
//   * post_gaps — array of consecutive-post gap seconds (last 30)
//   * tid_check — expected vs actual tid (visible regression signal
//     for "did setConfiguration actually land")
if ($action === 'get_member_diagnostics' && $method === 'GET') {
    _admin_check();
    _ot_ensure_member_overrides_column();
    $mid = (int) ($_GET['member_id'] ?? 0);
    if ($mid <= 0) json_error('member_id required');

    // Member + username
    $m = db_fetch_one(
        "SELECT m.id, CONCAT_WS(' ', m.first_name, m.last_name) AS member_name,
                u.user AS username, m.owntracks_overrides
           FROM `{$prefix}member` m
           LEFT JOIN `{$prefix}user` u ON (u.id = m.user_id OR u.member = m.id)
          WHERE m.id = ?
          ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
          LIMIT 1",
        [$mid]
    );
    if (!$m) json_error('Member not found', 404);
    $username = $m['username'] ?: ('member-' . $mid);
    $cad = (function () {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    })();

    // Effective config (Layer A + B + C + D layered)
    $effective = _ot_build_layered_config($mid, $username, '<redacted>', $cad);
    unset($effective['password'], $effective['url']);

    // Per-layer breakdown — what value would each layer contribute?
    $layerBreakdown = [];
    $tunable = _ot_tunable_keys();
    $perMember = $m['owntracks_overrides']
        ? (json_decode($m['owntracks_overrides'], true) ?: [])
        : [];
    $incidentActive = _ot_member_has_active_incident($mid);
    $incidentOverrides = [
        'moveModeLocatorInterval' => 30,
        'locatorInterval'         => 30,
        'pubInterval'             => 5,
        'locatorDisplacement'     => 20,
        'locatorPriority'         => 1,
    ];

    foreach ($tunable as $cfgKey => $meta) {
        $adminVal      = get_setting('owntracks_default_' . $meta['settings_key'], null);
        $perMemberVal  = $perMember[$meta['settings_key']] ?? null;
        $incidentVal   = $incidentActive && isset($incidentOverrides[$cfgKey])
            ? $incidentOverrides[$cfgKey] : null;

        // Resolve winning layer (highest precedence wins)
        if ($incidentVal !== null) {
            $winner = 'D-incident';
            $value  = $incidentVal;
        } elseif ($perMemberVal !== null && $perMemberVal !== '') {
            $winner = 'C-member';
            $value  = $perMemberVal;
        } elseif ($adminVal !== null && $adminVal !== '') {
            $winner = 'B-admin';
            $value  = $adminVal;
        } else {
            $winner = 'A-hardcoded';
            $value  = $effective[$cfgKey] ?? null;
        }

        $layerBreakdown[] = [
            'config_key'    => $cfgKey,
            'label'         => $meta['label'],
            'effective'     => $effective[$cfgKey] ?? null,
            'winner'        => $winner,
            'hardcoded'     => $effective[$cfgKey] ?? null,  // approximation; pure-A computed only if no overrides
            'admin'         => $adminVal,
            'member'        => $perMemberVal,
            'incident'      => $incidentVal,
        ];
    }

    // Recent posts (last 20)
    $tokenRow = db_fetch_one(
        "SELECT id, token_label FROM `{$prefix}member_tracking_tokens`
          WHERE member_id = ? AND revoked_at IS NULL
            AND (valid_until IS NULL OR valid_until > NOW())
          ORDER BY id DESC LIMIT 1",
        [$mid]
    );
    $recentPosts = [];
    $postGaps = [];
    $actualTid = null;
    if ($tokenRow) {
        $rows = db_fetch_all(
            "SELECT id, lat, lng, accuracy, battery, raw_data, received_at, reported_at
               FROM `{$prefix}location_reports`
              WHERE auth_token_id = ?
              ORDER BY id DESC LIMIT 20",
            [$tokenRow['id']]
        );
        foreach ($rows as $r) {
            $raw = json_decode($r['raw_data'], true) ?: [];
            $recentPosts[] = [
                'id'          => (int) $r['id'],
                'received_at' => $r['received_at'],
                'lat'         => $r['lat'],
                'lng'         => $r['lng'],
                'accuracy'    => $r['accuracy'],
                'battery'     => $r['battery'],
                'tid'         => $raw['tid'] ?? null,
                'trigger'     => $raw['t'] ?? null,    // OwnTracks "trigger" letter (u, t, p, c, b, r, R, m)
                'mode'        => $raw['m'] ?? null,    // monitoring mode trigger code
                'velocity'    => $raw['vel'] ?? null,
                'connection'  => $raw['conn'] ?? null, // w=wifi, m=mobile, o=offline
            ];
            if ($actualTid === null && !empty($raw['tid'])) $actualTid = $raw['tid'];
        }
        // Gap analysis
        for ($i = 0; $i < count($rows) - 1; $i++) {
            $postGaps[] = strtotime($rows[$i]['received_at']) - strtotime($rows[$i + 1]['received_at']);
        }
    }

    // TID expected vs actual — the unambiguous "did setConfiguration land?" signal.
    $expectedTid = $effective['tid'] ?? null;
    $tidMatch = ($expectedTid && $actualTid && strcasecmp($expectedTid, $actualTid) === 0);

    // Outbox history
    $outbox = db_fetch_all(
        "SELECT id, payload_json, created_at, consumed_at, created_by
           FROM `{$prefix}owntracks_outbox`
          WHERE member_id = ?
          ORDER BY id DESC LIMIT 10",
        [$mid]
    );
    foreach ($outbox as &$ob) {
        $ob['payload'] = json_decode($ob['payload_json'], true);
        unset($ob['payload_json']);
    }
    unset($ob);

    // Incident assignments (drives Layer D)
    $activeAssigns = [];
    try {
        $activeAssigns = db_fetch_all(
            "SELECT a.ticket_id, a.responder_id, r.handle AS unit_handle, r.name AS unit_name, a.dispatched
               FROM `{$prefix}unit_personnel_assignments` upa
               JOIN `{$prefix}assigns` a ON a.responder_id = upa.responder_id
               LEFT JOIN `{$prefix}responder` r ON r.id = a.responder_id
              WHERE upa.member_id = ?
                AND (upa.status IS NULL OR upa.status != 'released')
                AND (a.clear IS NULL OR a.clear = '' OR a.clear = '0000-00-00 00:00:00')",
            [$mid]
        );
    } catch (Exception $e) { /* tables may be absent */ }

    // Phase 65: OwnTracks provider runtime state. If the provider row
    // is disabled, api/location.php silently returns [] for every POST
    // — surface that loudly here so it's the first thing the admin
    // sees on the diagnostics page next time it happens. Also count
    // ingest activity for the last hour so it's obvious whether a
    // phone is reaching us at all.
    $providerState = [
        'enabled'             => 1,
        'max_age_seconds'     => null,
        'ingest_count_1h'     => 0,
        'last_ingest_at'      => null,
    ];
    try {
        $prov = db_fetch_one(
            "SELECT id, enabled, max_age_seconds FROM `{$prefix}location_providers` WHERE code = 'owntracks'"
        );
        if ($prov) {
            $providerState['enabled']         = (int) $prov['enabled'];
            $providerState['max_age_seconds'] = (int) $prov['max_age_seconds'];

            // Count recent reports under this provider regardless of unit_identifier
            // — a token-authed phone may rotate tid but still be the same device.
            $providerState['ingest_count_1h'] = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}location_reports`
                  WHERE provider_id = ? AND received_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [(int) $prov['id']]
            );
            $providerState['last_ingest_at'] = db_fetch_value(
                "SELECT MAX(received_at) FROM `{$prefix}location_reports` WHERE provider_id = ?",
                [(int) $prov['id']]
            );
        }
    } catch (Exception $e) {
        // Non-fatal — leave defaults
    }

    json_response([
        'member'            => $m,
        'token'             => $tokenRow,
        'effective_config'  => $effective,
        'layer_breakdown'   => $layerBreakdown,
        'recent_posts'      => $recentPosts,
        'post_gaps'         => $postGaps,
        'outbox'            => $outbox,
        'expected_tid'      => $expectedTid,
        'actual_tid'        => $actualTid,
        'tid_match'         => $tidMatch,
        'incident_active'   => $incidentActive,
        'active_assignments' => $activeAssigns,
        'provider_state'    => $providerState,
        'now'               => date('Y-m-d H:i:s'),
    ]);
}

// ─── action=link ───────────────────────────────────────────────
if ($action === 'link' && $method === 'GET') {
    _admin_check();
    $mid = (int) ($_GET['member_id'] ?? 0);
    $mode = (string) ($_GET['mode'] ?? 'url');
    if ($mid <= 0) json_error('member_id required');
    try {
        $token = _mint_token($mid, 'provisioning-' . date('Y-m-d'), (int) ($_SESSION['user_id'] ?? 0) ?: null);
        $cfg   = _config_payload_for_member($mid, $token['secret_raw']);
        $cfgJson = json_encode($cfg);

        // 2026-06-14 (Phase 46b): URL format verified against OwnTracks
        // Android source — project/app/src/main/java/org/owntracks/android/
        // ui/preferences/load/LoadViewModel.kt @ ~L210:
        //
        //   val configQueryParam = queryParams["inline"] ?: emptyList()
        //   …
        //   val config: ByteArray = Base64.decode(configQueryParam[0].toByteArray())
        //
        // So `inline=` must be BASE64 of the JSON, not URL-encoded JSON, and
        // the URI path must be `/config` (the AndroidManifest restricts the
        // scheme to that path). Phase 46 fixed the payload _type but kept
        // the wrong path + URL-encoding, so even iOS QR scanning wouldn't
        // have worked. _config_payload_for_member() already returns
        // `_type: configuration` — that's the right shape for direct
        // provisioning; setConfiguration is only used by the rotate
        // outbox push below.
        $owntracksUrl = 'owntracks:///config?inline=' . rawurlencode(base64_encode($cfgJson));

        if ($mode === 'qr') {
            json_response(['mode' => 'qr', 'qr_text' => $owntracksUrl, 'token_id' => $token['id']]);
        } elseif ($mode === 'file') {
            // 2026-06-14 (Phase 46b): .otrc file download — the most reliable
            // path on Android. OwnTracks' AndroidManifest registers an
            // intent filter for files matching *.otrc so the user can
            // download this file on their phone and tap it from the
            // notification / file manager → it routes straight to OwnTracks.
            // Inside the OwnTracks app the Configuration management screen's
            // file picker (LoadActivity in load/LoadActivity.kt) also accepts
            // this same JSON.
            $m = db_fetch_one(
                "SELECT u.user AS username FROM `{$prefix}member` m
                 LEFT JOIN `{$prefix}user` u ON (u.id = m.user_id OR u.member = m.id)
                 WHERE m.id = ?
                 ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
                 LIMIT 1",
                [$mid]
            );
            $uname = preg_replace('/[^A-Za-z0-9._-]/', '', $m['username'] ?? ('member-' . $mid));
            $fname = 'owntracks-' . $uname . '-' . date('Ymd') . '.otrc';

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
            header('X-OwnTracks-Token-Id: ' . (int) $token['id']);
            echo $cfgJson;
            exit;
        } elseif ($mode === 'email') {
            $m = db_fetch_one(
                "SELECT u.user AS username, m.email, CONCAT_WS(' ', m.first_name, m.last_name) AS name
                   FROM `{$prefix}member` m
                   LEFT JOIN `{$prefix}user` u ON (u.id = m.user_id OR u.member = m.id)
                  WHERE m.id = ?
                  ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
                  LIMIT 1",
                [$mid]
            );
            if (!$m || empty($m['email'])) json_error('Member has no email on file', 400);
            $tpl = get_setting('owntracks_email_link_template',
                "Hi {name},\n\nTap below on your phone:\n\n{owntracks_url}");
            $body = strtr($tpl, [
                '{name}' => $m['name'] ?: ($m['username'] ?? ''),
                '{org}'  => get_setting('site_name', 'TicketsCAD'),
                '{owntracks_url}' => $owntracksUrl,
            ]);
            $ok = false;
            try {
                require_once __DIR__ . '/../inc/smtp.inc.php';
                if (function_exists('send_smtp_mail')) {
                    $ok = send_smtp_mail($m['email'], 'OwnTracks setup link', $body);
                }
            } catch (Exception $e) { /* fall through */ }
            json_response(['mode' => 'email', 'sent' => $ok, 'address' => $m['email'], 'token_id' => $token['id']]);
        } else {
            json_response(['mode' => 'url', 'url' => $owntracksUrl, 'token_id' => $token['id']]);
        }
    } catch (Exception $e) { json_error('link failed: ' . $e->getMessage(), 500); }
}

// ═══ Phase 117 (GH #84, a beta tester/SAG) — UNIT-level OwnTracks device tracking ═══
// Reusable writers live in inc/unit_owntracks.php so the regression test can
// exercise the real path without going through this HTTP endpoint. No schema
// change — reuses location_ingest_tokens + unit_location_bindings + the resolver.
require_once __DIR__ . '/../inc/unit_owntracks.php';

// GET ?action=unit_status&responder_id=N
if ($action === 'unit_status' && $method === 'GET') {
    _p117_unit_rbac();
    $rid = (int) ($_GET['responder_id'] ?? 0);
    if ($rid <= 0) json_error('responder_id required');
    $prov = _p117_ot_provider();
    if (!$prov) json_error('OwnTracks provider not configured', 400);
    $pid = (int) $prov['id'];
    try {
        $tid = _p117_unit_tid($rid, $pid);
        $tokens = [];
        if ($tid !== null) {
            $tokens = db_fetch_all(
                "SELECT `id`, `label`, `created_at`, `last_used_at`
                   FROM `{$prefix}location_ingest_tokens`
                  WHERE `provider_id` = ? AND `device_unique_id` = ? AND `revoked_at` IS NULL
                  ORDER BY `id` DESC",
                [$pid, $tid]
            );
        }
        json_response([
            'responder_id'     => $rid,
            'tid'              => $tid,
            'has_binding'      => $tid !== null,
            'provider_enabled' => ((int) $prov['enabled'] === 1),
            'endpoint'         => _p117_base_url() . '/api/location.php?provider=owntracks',
            'tokens'           => $tokens,
        ]);
    } catch (Exception $e) { json_error('unit_status failed: ' . $e->getMessage(), 500); }
}

// GET ?action=unit_link&responder_id=N&mode=qr|url|file[&tid=XX]
if ($action === 'unit_link' && $method === 'GET') {
    _p117_unit_rbac();
    $rid  = (int) ($_GET['responder_id'] ?? 0);
    $mode = (string) ($_GET['mode'] ?? 'url');
    if ($rid <= 0) json_error('responder_id required');
    $prov = _p117_ot_provider();
    if (!$prov) json_error('OwnTracks provider not configured', 400);
    $pid = (int) $prov['id'];
    try {
        require_once __DIR__ . '/../inc/audit.php';
        $explicit = isset($_GET['tid']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['tid'])) : '';
        // Real writer — same call the regression test uses.
        $r = _p117_provision_unit_device($rid, $pid, $explicit, (int) ($_SESSION['user_id'] ?? 0) ?: null);
        $tid = $r['tid'];
        $tokenId = $r['token_id'];
        if (function_exists('audit_log')) {
            audit_log('config', 'create', 'unit_owntracks_device', $rid,
                "Provisioned OwnTracks device for unit #{$rid} (tid {$tid}, token #{$tokenId})");
        }
        $cfgJson      = json_encode(_p117_unit_config($rid, $tid, $r['secret_raw']));
        $owntracksUrl = 'owntracks:///config?inline=' . rawurlencode(base64_encode($cfgJson));

        if ($mode === 'qr') {
            json_response(['mode' => 'qr', 'qr_text' => $owntracksUrl, 'tid' => $tid, 'token_id' => $tokenId]);
        } elseif ($mode === 'file') {
            $slug = preg_replace('/[^A-Za-z0-9._-]/', '', 'unit-' . $rid . '-' . $tid);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="owntracks-' . $slug . '-' . date('Ymd') . '.otrc"');
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
            header('X-OwnTracks-Token-Id: ' . $tokenId);
            echo $cfgJson;
            exit;
        } else {
            json_response(['mode' => 'url', 'url' => $owntracksUrl, 'tid' => $tid, 'token_id' => $tokenId]);
        }
    } catch (Exception $e) { json_error('unit_link failed: ' . $e->getMessage(), 500); }
}

// POST ?action=unit_revoke  { responder_id, token_id, csrf_token }
if ($action === 'unit_revoke' && $method === 'POST') {
    _p117_unit_rbac();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $rid     = (int) ($input['responder_id'] ?? 0);
    $tokenId = (int) ($input['token_id'] ?? 0);
    if ($rid <= 0 || $tokenId <= 0) json_error('responder_id + token_id required');
    $prov = _p117_ot_provider();
    if (!$prov) json_error('OwnTracks provider not configured', 400);
    $pid = (int) $prov['id'];
    try {
        require_once __DIR__ . '/../inc/audit.php';
        $res = _p117_revoke_unit_device($rid, $pid, $tokenId);
        if ($res === null) json_error('Token not found', 404);
        if (function_exists('audit_log')) {
            audit_log('config', 'delete', 'unit_owntracks_device', $rid,
                "Revoked OwnTracks device token #{$tokenId} for unit #{$rid} (tid {$res['tid']})");
        }
        json_response(['revoked' => true, 'token_id' => $tokenId, 'binding_deactivated' => $res['binding_deactivated']]);
    } catch (Exception $e) { json_error('unit_revoke failed: ' . $e->getMessage(), 500); }
}

// ─── action=push_config ────────────────────────────────────────
// Queues a delta config. The OwnTracks ingest then piggy-backs it on
// the next position-POST response.
if ($action === 'push_config' && $method === 'POST') {
    _admin_check();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $mid = (int) ($input['member_id'] ?? 0);
    $payload = $input['payload'] ?? null;
    if ($mid <= 0 || !is_array($payload)) json_error('member_id + payload object required');

    // Ensure the queue table exists (lightweight; no separate migration).
    try {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}owntracks_outbox` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `member_id` INT NOT NULL,
            `payload_json` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `consumed_at` DATETIME NULL,
            `created_by` INT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_pending` (`member_id`, `consumed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Phase 52c — cmd-wrapped setConfiguration shape (see _ot_push_to_member).
        db_query(
            "INSERT INTO `{$prefix}owntracks_outbox` (member_id, payload_json, created_by)
             VALUES (?, ?, ?)",
            [$mid, json_encode([
                '_type'         => 'cmd',
                'action'        => 'setConfiguration',
                'configuration' => $payload,
            ]), (int) ($_SESSION['user_id'] ?? 0) ?: null]
        );
        json_response(['queued' => true, 'id' => (int) db_insert_id()]);
    } catch (Exception $e) { json_error('push_config failed: ' . $e->getMessage(), 500); }
}

// ─── action=push_pending ───────────────────────────────────────
// Read+mark a pending payload. Admin-only diagnostic endpoint.
//
// Phase 73w — CRITICAL: previously this endpoint had NO auth at all.
// The original comment claimed "auth is the client's basic-auth
// secret" but no validation existed; ANY unauthenticated attacker
// could call ?action=push_pending&member_id=N to drain a member's
// outbox, which contains rotation payloads with the new OwnTracks
// password in cleartext. The real OwnTracks client never hits this
// endpoint — the ingest path at /api/location.php already drains
// the outbox inline (see line ~230 in that file). So this endpoint
// is only used by admins for diagnostic / forced-replay scenarios.
// Restrict accordingly.
if ($action === 'push_pending' && $method === 'GET') {
    _admin_check();
    $mid = (int) ($_GET['member_id'] ?? 0);
    if ($mid <= 0) json_error('member_id required');
    try {
        $row = db_fetch_one(
            "SELECT id, payload_json FROM `{$prefix}owntracks_outbox`
              WHERE member_id = ? AND consumed_at IS NULL
              ORDER BY id ASC LIMIT 1",
            [$mid]
        );
        if (!$row) { json_response(['pending' => null]); }
        db_query("UPDATE `{$prefix}owntracks_outbox` SET consumed_at = NOW() WHERE id = ?", [(int) $row['id']]);
        json_response(['pending' => json_decode($row['payload_json'], true)]);
    } catch (Exception $e) { json_error('push_pending failed: ' . $e->getMessage(), 500); }
}

// ─── action=rotate ─────────────────────────────────────────────
if ($action === 'rotate' && $method === 'POST') {
    _admin_check();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $mid = (int) ($input['member_id'] ?? 0);
    if ($mid <= 0) json_error('member_id required');
    $window = (int) get_setting('owntracks_token_dual_window_days', 7);
    try {
        $token = _mint_token($mid, $input['label'] ?? ('rotation-' . date('Y-m-d')),
                              (int) ($_SESSION['user_id'] ?? 0) ?: null, $window);
        $cfg   = _config_payload_for_member($mid, $token['secret_raw']);
        // Queue a push so the client gets the new password on its next post.
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}owntracks_outbox` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `member_id` INT NOT NULL,
            `payload_json` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `consumed_at` DATETIME NULL,
            `created_by` INT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_pending` (`member_id`, `consumed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Phase 52c — cmd-wrapped setConfiguration shape (see _ot_push_to_member).
        db_query(
            "INSERT INTO `{$prefix}owntracks_outbox` (member_id, payload_json, created_by)
             VALUES (?, ?, ?)",
            [$mid, json_encode([
                '_type'         => 'cmd',
                'action'        => 'setConfiguration',
                'configuration' => $cfg,
            ]), (int) ($_SESSION['user_id'] ?? 0) ?: null]
        );
        json_response([
            'token_id'    => $token['id'],
            'secret_raw'  => $token['secret_raw'],   // show ONCE
            'dual_window_days' => $window,
            'queued_push'  => true,
            'message'      => 'New token minted. Old tokens will expire in ' . $window . ' days. New secret pushed to the client via setConfiguration on next position post.',
        ]);
    } catch (Exception $e) { json_error('rotate failed: ' . $e->getMessage(), 500); }
}

// ─── action=stale ──────────────────────────────────────────────
if ($action === 'stale' && $method === 'GET') {
    _admin_check();
    $days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
    try {
        $rows = db_fetch_all(
            "SELECT t.id, t.member_id,
                    (SELECT u.user FROM `{$prefix}user` u
                      WHERE u.member = m.id OR u.id = m.user_id
                      ORDER BY (CASE WHEN u.member = m.id THEN 0 ELSE 1 END), u.id
                      LIMIT 1) AS username,
                    m.email, t.token_label,
                    t.created_at, t.valid_until, t.last_used_at
               FROM `{$prefix}member_tracking_tokens` t
               LEFT JOIN `{$prefix}member` m ON m.id = t.member_id
              WHERE t.revoked_at IS NULL
                AND t.created_at < NOW() - INTERVAL ? DAY
              ORDER BY t.last_used_at DESC",
            [$days]
        );
        json_response(['days' => $days, 'tokens' => $rows]);
    } catch (Exception $e) { json_error('stale failed: ' . $e->getMessage(), 500); }
}

// ─── action=list_tokens ────────────────────────────────────────
// Every token (active + expired + revoked) for a single member, newest first.
// Drives the member-page token table so the admin can see what's out there.
if ($action === 'list_tokens' && $method === 'GET') {
    _admin_check();
    $mid = (int) ($_GET['member_id'] ?? 0);
    if ($mid <= 0) json_error('member_id required');
    try {
        $rows = db_fetch_all(
            "SELECT id, token_label, created_at, valid_until, last_used_at, revoked_at,
                    CASE
                        WHEN revoked_at IS NOT NULL THEN 'revoked'
                        WHEN valid_until IS NOT NULL AND valid_until <= NOW() THEN 'expired'
                        WHEN valid_until IS NOT NULL AND valid_until >  NOW() THEN 'expiring'
                        ELSE 'active'
                    END AS status
               FROM `{$prefix}member_tracking_tokens`
              WHERE member_id = ?
              ORDER BY id DESC",
            [$mid]
        );
        json_response(['member_id' => $mid, 'tokens' => $rows]);
    } catch (Exception $e) { json_error('list_tokens failed: ' . $e->getMessage(), 500); }
}

// ─── action=revoke ─────────────────────────────────────────────
// Immediate revocation — no grace window. Use when a phone is lost or
// stolen. Sets revoked_at = NOW(); the ingest path filters out anything
// with revoked_at non-null on the very next POST.
if ($action === 'revoke' && $method === 'POST') {
    _admin_check();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $tokenId = (int) ($input['token_id'] ?? 0);
    if ($tokenId <= 0) json_error('token_id required');
    try {
        $tok = db_fetch_one(
            "SELECT id, member_id, revoked_at FROM `{$prefix}member_tracking_tokens` WHERE id = ?",
            [$tokenId]
        );
        if (!$tok) json_error('token not found', 404);
        if ($tok['revoked_at']) json_response(['ok' => true, 'already_revoked' => true]);
        db_query(
            "UPDATE `{$prefix}member_tracking_tokens` SET revoked_at = NOW() WHERE id = ?",
            [$tokenId]
        );
        json_response(['ok' => true, 'token_id' => $tokenId, 'member_id' => (int) $tok['member_id']]);
    } catch (Exception $e) { json_error('revoke failed: ' . $e->getMessage(), 500); }
}

json_error('Unknown action: ' . $action);
