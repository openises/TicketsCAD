<?php
/**
 * Phase 114a — unified communications channel registry
 *
 * One registry (`comm_channels`) for every communications channel the
 * system knows about: Zello channels, BrandMeister talkgroups, Meshtastic
 * channels, broker text channels, and the virtual source channels
 * (NWS weather alerts, event bus). Design: specs/phase-114-audio-matrix/
 * channel-catalog.md §1–§5.
 *
 * Existing configuration is WRAPPED, not migrated: channel_registry_sync()
 * derives managed rows (managed=1) from the current sources and prunes
 * managed rows whose source disappeared. User-editable presentation fields
 * (label, short_label, color, sort_order) and the enabled flag are set on
 * first creation only — sync never clobbers an admin's overrides.
 *
 * Adapters not yet built (allstar, sip, intercom, ptt1, dmr_local) get
 * catalog entries so the console designer knows their capabilities the
 * moment a row appears; they contribute no rows until their phase lands.
 */

if (!function_exists('channel_adapter_catalog')) {

/**
 * Static adapter catalog: capabilities + regulatory class per adapter.
 * Capability keys (channel-catalog.md §2): voice_rx, voice_tx, full_duplex,
 * ptt_floor, text_rx, text_tx, image, tts_out, record, location, source,
 * actuators (array). id_policy/max_tx_secs ride in per-channel config_json.
 */
function channel_adapter_catalog() {
    return [
        'zello' => [
            'label' => 'Zello',
            'regulatory_class' => 'internal',
            'capabilities' => [
                'voice_rx' => true, 'voice_tx' => true, 'ptt_floor' => true,
                'text_rx' => true, 'text_tx' => true, 'image' => true,
                'tts_out' => true, 'record' => true, 'location' => true,
            ],
        ],
        'dmr_bm' => [
            'label' => 'DMR (BrandMeister)',
            'regulatory_class' => 'amateur',
            'capabilities' => [
                'voice_rx' => true, 'voice_tx' => true, 'ptt_floor' => true,
                'tts_out' => true, 'record' => true,
            ],
        ],
        'dmr_local' => [ // 114i exploration spike — no rows yet
            'label' => 'DMR Simplex (local)',
            'regulatory_class' => 'amateur',
            'capabilities' => [
                'voice_rx' => true, 'voice_tx' => true, 'ptt_floor' => true,
                'tts_out' => true, 'record' => true,
            ],
        ],
        'mesh' => [
            'label' => 'Meshtastic',
            'regulatory_class' => 'internal',
            'capabilities' => [
                'text_rx' => true, 'text_tx' => true, 'location' => true,
            ],
        ],
        'meshcore' => [
            'label' => 'MeshCore',
            'regulatory_class' => 'internal',
            'capabilities' => ['text_rx' => true, 'text_tx' => true],
        ],
        'aprs' => [
            'label' => 'APRS',
            'regulatory_class' => 'amateur',
            'capabilities' => [
                'text_rx' => true, 'text_tx' => true, 'location' => true,
            ],
        ],
        'local_chat' => [
            'label' => 'Local Chat',
            'regulatory_class' => 'internal',
            'capabilities' => ['text_rx' => true, 'text_tx' => true],
        ],
        'smtp' => [
            'label' => 'Email (SMTP)',
            'regulatory_class' => 'internal',
            'capabilities' => ['text_tx' => true],
        ],
        'sms' => [
            'label' => 'SMS',
            'regulatory_class' => 'pstn',
            'capabilities' => ['text_tx' => true],
        ],
        'slack' => [
            'label' => 'Slack',
            'regulatory_class' => 'internal',
            'capabilities' => ['text_tx' => true],
        ],
        'push' => [
            'label' => 'Push Notifications',
            'regulatory_class' => 'internal',
            'capabilities' => ['text_tx' => true],
        ],
        'nws' => [
            'label' => 'NWS Weather Alerts',
            'regulatory_class' => 'internal',
            'capabilities' => ['source' => true, 'text_rx' => true],
        ],
        'eventbus' => [
            'label' => 'Event Bus',
            'regulatory_class' => 'internal',
            'capabilities' => ['source' => true, 'text_rx' => true],
        ],
        // Future adapters (114c+) — catalog entries only, no sync source yet.
        'allstar' => [
            'label' => 'AllStarLink',
            'regulatory_class' => 'amateur',
            'capabilities' => [
                'voice_rx' => true, 'voice_tx' => true, 'ptt_floor' => true,
                'tts_out' => true, 'record' => true,
            ],
        ],
        'sip' => [
            'label' => 'SIP Telephone',
            'regulatory_class' => 'pstn',
            'capabilities' => [
                'voice_rx' => true, 'voice_tx' => true, 'full_duplex' => true,
                'tts_out' => true, 'record' => true,
            ],
        ],
        'intercom' => [
            'label' => 'Intercom Station',
            'regulatory_class' => 'internal',
            'capabilities' => [
                'voice_rx' => true, 'voice_tx' => true, 'full_duplex' => true,
                'record' => true, 'actuators' => ['door'],
            ],
        ],
        'ptt1' => [
            'label' => 'TicketsCAD PTT',
            'regulatory_class' => 'internal',
            'capabilities' => [
                'voice_rx' => true, 'voice_tx' => true, 'ptt_floor' => true,
                'text_rx' => true, 'text_tx' => true, 'tts_out' => true,
                'record' => true,
            ],
        ],
    ];
}

/** Read one settings value ('' when missing/unreadable). */
function _chreg_setting($name) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        return $v !== null ? (string) $v : '';
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Compute the desired managed channel set from current config sources.
 * Returns [channel_key => row-fields] (no DB writes).
 */
function channel_registry_sources() {
    $prefix  = $GLOBALS['db_prefix'] ?? '';
    $catalog = channel_adapter_catalog();
    $want    = [];
    $mk = function ($adapter, $key, $label, $enabled, array $config = [], $sort = 100) use ($catalog) {
        $cat = $catalog[$adapter];
        return [
            'channel_key'       => $key,
            'adapter'           => $adapter,
            'label'             => $label,
            'enabled'           => $enabled ? 1 : 0,
            'config_json'       => $config ? json_encode($config) : null,
            'capabilities_json' => json_encode($cat['capabilities']),
            'regulatory_class'  => $cat['regulatory_class'],
            'sort_order'        => $sort,
        ];
    };

    // Zello — dispatch channel + comma-separated extras (api/zello-token.php
    // parsing mirrored). Configured = a dispatch channel is set.
    $dispatch = trim(_chreg_setting('zello_dispatch_channel'));
    $zChannels = [];
    if ($dispatch !== '') { $zChannels[] = $dispatch; }
    foreach (explode(',', _chreg_setting('zello_extra_channels')) as $c) {
        $c = trim($c);
        if ($c !== '' && !in_array($c, $zChannels, true)) { $zChannels[] = $c; }
    }
    foreach ($zChannels as $i => $name) {
        $want['zello:' . $name] = $mk(
            'zello', 'zello:' . $name, 'Zello: ' . $name, true,
            ['channel' => $name, 'dispatch' => $name === $dispatch], 10 + $i
        );
    }

    // DMR BrandMeister — one row per dmr_channels row (enabled follows source).
    try {
        $rows = db_fetch_all(
            "SELECT id, label, talkgroup, link_mode, enabled FROM `{$prefix}dmr_channels`"
        );
        foreach ($rows as $i => $r) {
            $want['dmr_bm:' . $r['id']] = $mk(
                'dmr_bm', 'dmr_bm:' . $r['id'],
                $r['label'] . ' (TG ' . $r['talkgroup'] . ')',
                (int) $r['enabled'] === 1,
                ['dmr_channel_id' => (int) $r['id'], 'talkgroup' => $r['talkgroup'],
                 'link_mode' => $r['link_mode'],
                 'id_policy' => true, 'max_tx_secs' => 180],
                20 + $i
            );
        }
    } catch (Exception $e) { /* table may not exist on this install */ }

    // Meshtastic — one row per active mesh channel.
    try {
        $rows = db_fetch_all(
            "SELECT id, name FROM `{$prefix}mesh_channels` WHERE archived_at IS NULL"
        );
        foreach ($rows as $i => $r) {
            $want['mesh:' . $r['id']] = $mk(
                'mesh', 'mesh:' . $r['id'], 'Meshtastic: ' . $r['name'], true,
                ['mesh_channel_id' => (int) $r['id'], 'name' => $r['name']], 30 + $i
            );
        }
    } catch (Exception $e) { /* optional feature */ }

    // Broker text channels (code-registered adapters with settings-based
    // config). Rows exist so the console can show them; default enabled
    // only for local_chat — admins opt the rest in.
    $brokerCodes = [
        'local_chat' => ['Local Chat', true, 40],
        'aprs'       => ['APRS', false, 41],
        'meshcore'   => ['MeshCore', false, 42],
        'sms'        => ['SMS', false, 43],
        'smtp'       => ['Email (SMTP)', false, 44],
        'slack'      => ['Slack', false, 45],
        'push'       => ['Push Notifications', false, 46],
    ];
    foreach ($brokerCodes as $code => [$label, $defaultEnabled, $sort]) {
        $want['broker:' . $code] = $mk(
            $code, 'broker:' . $code, $label, $defaultEnabled,
            ['broker_channel' => $code], $sort
        );
    }

    // Virtual source channels.
    if (_chreg_setting('weather_alerts_enabled') === '1') {
        $want['nws:alerts'] = $mk('nws', 'nws:alerts', 'NWS Weather Alerts', true, [], 50);
    }
    $want['eventbus:main'] = $mk('eventbus', 'eventbus:main', 'Event Bus', true, [], 51);

    return $want;
}

/**
 * Sync the registry with current config sources. Creates missing managed
 * rows, refreshes derived fields (config/capabilities/class) on existing
 * managed rows, prunes managed rows whose source vanished. Never touches
 * unmanaged (hand-created) rows; never overwrites label/short_label/color/
 * sort_order/enabled on existing rows.
 *
 * Exception: dmr_bm rows track their source's enabled flag — the DMR panel
 * is that channel's own admin surface, so it stays authoritative.
 */
function channel_registry_sync() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $want   = channel_registry_sources();
    $created = 0; $updated = 0; $pruned = 0;

    $existing = [];
    foreach (db_fetch_all("SELECT * FROM `{$prefix}comm_channels`") as $row) {
        $existing[$row['channel_key']] = $row;
    }

    foreach ($want as $key => $w) {
        if (!isset($existing[$key])) {
            db_query(
                "INSERT INTO `{$prefix}comm_channels`
                    (channel_key, adapter, label, config_json, capabilities_json,
                     regulatory_class, enabled, managed, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)",
                [$w['channel_key'], $w['adapter'], $w['label'], $w['config_json'],
                 $w['capabilities_json'], $w['regulatory_class'], $w['enabled'],
                 $w['sort_order']]
            );
            db_query(
                "INSERT IGNORE INTO `{$prefix}comm_channel_state` (channel_id, state)
                 VALUES (?, 'unknown')",
                [db_insert_id()]
            );
            $created++;
            continue;
        }
        $e = $existing[$key];
        if ((int) $e['managed'] !== 1) { continue; } // hand-created row: hands off
        $sets = []; $args = [];
        if ((string) $e['config_json'] !== (string) $w['config_json']) {
            $sets[] = 'config_json = ?';       $args[] = $w['config_json'];
        }
        if ((string) $e['capabilities_json'] !== (string) $w['capabilities_json']) {
            $sets[] = 'capabilities_json = ?'; $args[] = $w['capabilities_json'];
        }
        if ($e['regulatory_class'] !== $w['regulatory_class']) {
            $sets[] = 'regulatory_class = ?';  $args[] = $w['regulatory_class'];
        }
        if ($w['adapter'] === 'dmr_bm' && (int) $e['enabled'] !== (int) $w['enabled']) {
            $sets[] = 'enabled = ?';           $args[] = $w['enabled'];
        }
        if ($sets) {
            $args[] = $e['id'];
            db_query(
                "UPDATE `{$prefix}comm_channels` SET " . implode(', ', $sets) . " WHERE id = ?",
                $args
            );
            $updated++;
        }
    }

    // Prune managed rows whose source is gone (and their state rows).
    foreach ($existing as $key => $e) {
        if ((int) $e['managed'] === 1 && !isset($want[$key])) {
            db_query("DELETE FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [$e['id']]);
            db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$e['id']]);
            $pruned++;
        }
    }

    return ['created' => $created, 'updated' => $updated, 'pruned' => $pruned,
            'channels' => array_keys($want)];
}

/** All channels (optionally enabled-only) with state joined, sorted. */
function channels_all($enabledOnly = false) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $where  = $enabledOnly ? 'WHERE c.enabled = 1' : '';
    $rows = db_fetch_all(
        "SELECT c.*, s.state, s.last_rx_at, s.last_tx_at, s.last_caller,
                s.last_error, s.updated_at AS state_updated_at
           FROM `{$prefix}comm_channels` c
      LEFT JOIN `{$prefix}comm_channel_state` s ON s.channel_id = c.id
         $where
       ORDER BY c.sort_order, c.label"
    );
    foreach ($rows as &$r) {
        $r['capabilities'] = $r['capabilities_json'] ? (json_decode($r['capabilities_json'], true) ?: []) : [];
        $r['config']       = $r['config_json'] ? (json_decode($r['config_json'], true) ?: []) : [];
        unset($r['capabilities_json'], $r['config_json']);
    }
    return $rows;
}

/** One channel by numeric id or channel_key (with state); null if absent. */
function channel_get($idOrKey) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $field  = is_numeric($idOrKey) ? 'c.id' : 'c.channel_key';
    $row = db_fetch_one(
        "SELECT c.*, s.state, s.last_rx_at, s.last_tx_at, s.last_caller, s.last_error
           FROM `{$prefix}comm_channels` c
      LEFT JOIN `{$prefix}comm_channel_state` s ON s.channel_id = c.id
          WHERE $field = ?",
        [$idOrKey]
    );
    if (!$row) { return null; }
    $row['capabilities'] = $row['capabilities_json'] ? (json_decode($row['capabilities_json'], true) ?: []) : [];
    $row['config']       = $row['config_json'] ? (json_decode($row['config_json'], true) ?: []) : [];
    unset($row['capabilities_json'], $row['config_json']);
    return $row;
}

/**
 * Upsert health state for a channel. $fields may include state, last_rx_at,
 * last_tx_at, last_caller, last_error. Failures are swallowed — health
 * writes must never break the action that triggered them.
 */
function channel_state_set($channelId, array $fields) {
    $prefix  = $GLOBALS['db_prefix'] ?? '';
    $allowed = ['state', 'last_rx_at', 'last_tx_at', 'last_caller', 'last_error'];
    $fields  = array_intersect_key($fields, array_flip($allowed));
    if (!$fields) { return false; }
    try {
        $cols = array_keys($fields);
        $sets = implode(', ', array_map(function ($c) { return "`$c` = VALUES(`$c`)"; }, $cols));
        db_query(
            "INSERT INTO `{$prefix}comm_channel_state` (channel_id, `" . implode('`, `', $cols) . "`)
             VALUES (?" . str_repeat(', ?', count($cols)) . ")
             ON DUPLICATE KEY UPDATE $sets",
            array_merge([$channelId], array_values($fields))
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Ask a DMR bridge's authenticated /health endpoint whether it is alive.
 * Returns 'connected' | 'degraded' | 'down'. Cached per host:port within
 * the request so N talkgroup rows on one bridge cost one HTTP call.
 *
 * IMPORTANT (quiet ≠ dead): dmr_channels.last_seen_at is stamped on RX
 * INGEST, not by a heartbeat — a quiet talkgroup goes stale within
 * minutes. Liveness must come from the bridge process itself.
 */
function _chreg_dmr_bridge_state($host, $port, $token) {
    static $cache = [];
    $key = $host . ':' . $port;
    if (isset($cache[$key])) { return $cache[$key]; }
    $state = 'down';
    try {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 1.5,
                'header'  => "Authorization: Bearer " . $token . "\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents("http://{$host}:{$port}/health", false, $ctx);
        if ($body !== false) {
            $j = json_decode($body, true);
            if (!empty($j['ok']) && !empty($j['running'])) {
                $state = 'connected';
            } elseif (is_array($j)) {
                $state = 'degraded'; // bridge answers but HBP session not running
            }
        }
    } catch (Exception $e) {
        // unreachable → down
    }
    return $cache[$key] = $state;
}

/**
 * Best-effort health probe from signals the server already has (Phase 114a;
 * adapters report their own state directly from 114c on). Sets:
 *  - dmr_bm: connected/degraded/down from the bridge /health endpoint,
 *            last_rx from dmr_messages.
 *  - zello:  last_rx/last_caller from zello_messages (state stays as the
 *            proxy reported it, or 'unknown' — quiet ≠ dead).
 *  - broker/mesh: last activity from the messages table per channel.
 */
function channel_registry_probe() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $probed = 0;
    foreach (channels_all() as $ch) {
        $f = [];
        try {
            if ($ch['adapter'] === 'dmr_bm' && !empty($ch['config']['dmr_channel_id'])) {
                $src = db_fetch_one(
                    "SELECT bridge_host, bridge_port, bridge_token, last_error
                       FROM `{$prefix}dmr_channels` WHERE id = ?",
                    [$ch['config']['dmr_channel_id']]
                );
                if ($src && $src['bridge_host']) {
                    $f['state'] = _chreg_dmr_bridge_state(
                        $src['bridge_host'], (int) $src['bridge_port'], $src['bridge_token']
                    );
                    if ($src['last_error']) { $f['last_error'] = $src['last_error']; }
                }
                $rx = db_fetch_one(
                    "SELECT call_started_at, radio_callsign FROM `{$prefix}dmr_messages`
                      WHERE channel_id = ? AND direction = 'rx'
                      ORDER BY id DESC LIMIT 1",
                    [$ch['config']['dmr_channel_id']]
                );
                if ($rx) {
                    $f['last_rx_at']  = $rx['call_started_at'];
                    $f['last_caller'] = $rx['radio_callsign'] ?: null;
                }
            } elseif ($ch['adapter'] === 'zello' && !empty($ch['config']['channel'])) {
                $rx = db_fetch_one(
                    "SELECT created, sender_display, sender_username
                       FROM `{$prefix}zello_messages`
                      WHERE channel = ? AND direction = 'incoming'
                      ORDER BY id DESC LIMIT 1",
                    [$ch['config']['channel']]
                );
                if ($rx) {
                    $f['last_rx_at']  = $rx['created'];
                    $f['last_caller'] = $rx['sender_display'] ?: ($rx['sender_username'] ?: null);
                }
            } elseif (!empty($ch['config']['broker_channel']) || $ch['adapter'] === 'mesh') {
                $code = $ch['config']['broker_channel'] ?? 'meshtastic';
                $rx = db_fetch_one(
                    "SELECT created_at, sender FROM `{$prefix}messages`
                      WHERE channel = ? ORDER BY id DESC LIMIT 1",
                    [$code]
                );
                if ($rx) {
                    $f['last_rx_at']  = $rx['created_at'];
                    $f['last_caller'] = $rx['sender'] ?: null;
                }
            }
        } catch (Exception $e) {
            // Probe sources are optional per install — skip quietly.
        }
        if ($f && channel_state_set($ch['id'], $f)) { $probed++; }
    }
    return $probed;
}

} // function_exists guard
