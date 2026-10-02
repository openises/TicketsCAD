<?php
/**
 * Public HTTP audio-stream channel management (2026-09-08, Eric: "a source
 * of audio we can listen to while testing services... I want to create
 * multiple streams as needed" — a full channel type, not a CLI tool).
 *
 * comm_channels rows with adapter='http_stream' — Broadcastify, LiveATC,
 * NOAA Weather Radio, Icecast, or any plain HTTP MP3/AAC/OGG feed, patched
 * into the audio matrix like any other channel. The URL lives in
 * config_json (`{"url":"..."}`) — there is no separate config-file
 * mechanism the way DMR's bridge_url has; every row is independently
 * admin-managed. Always created `managed=0` (channel_registry_sync() only
 * ever touches managed=1 rows — inc/channel_registry.php's own documented
 * convention — so a hand-created stream channel is never pruned or
 * silently overwritten by a sync pass for a subsystem it has nothing to
 * do with).
 *
 * api/http-stream-channels.php is the thin HTTP wrapper that also applies
 * every write to the LIVE running matrix service via matrix_control_apply_
 * http_stream_create()/_delete() (inc/matrix-control-client.php), rolling
 * back the DB write on failure — same fail-closed discipline as
 * inc/matrix-routes.php's own route CRUD (api/matrix.php's 'create'
 * action).
 */

if (!function_exists('http_stream_channel_list')) {

/** Every http_stream channel, most recently created first. */
function http_stream_channel_list() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all(
        "SELECT id, channel_key, label, regulatory_class, enabled, config_json, created_at, updated_at
           FROM `{$prefix}comm_channels`
          WHERE adapter = 'http_stream'
          ORDER BY id DESC"
    );
    foreach ($rows as &$r) {
        $r['url'] = http_stream_channel_url_from_config($r['config_json']);
        unset($r['config_json']);
    }
    unset($r);
    return $rows;
}

/** One http_stream channel by id, or null. Same url-extraction as the list. */
function http_stream_channel_get($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $row = db_fetch_one(
        "SELECT id, channel_key, label, regulatory_class, enabled, config_json, created_at, updated_at
           FROM `{$prefix}comm_channels`
          WHERE adapter = 'http_stream' AND id = ?",
        [(int) $id]
    );
    if (!$row) { return null; }
    $row['url'] = http_stream_channel_url_from_config($row['config_json']);
    unset($row['config_json']);
    return $row;
}

/** Mirrors service.py's own _http_stream_url_from_config() exactly — the
 *  two must agree on what counts as a usable url, or the admin UI could
 *  show a channel as configured while the live service silently skips it
 *  (or vice versa). */
function http_stream_channel_url_from_config($configJson) {
    if (!$configJson) { return ''; }
    $decoded = json_decode((string) $configJson, true);
    if (!is_array($decoded) || !isset($decoded['url'])) { return ''; }
    $url = trim((string) $decoded['url']);
    return $url;
}

/**
 * Validate a channel_key + label + regulatory_class + url. Throws
 * InvalidArgumentException with a human-readable message on any problem —
 * the caller (api/http-stream-channels.php) turns that into a 400, same
 * convention as inc/matrix-routes.php's matrix_route_validate().
 */
function http_stream_channel_validate($channelKey, $label, $regClass, $url) {
    $channelKey = trim((string) $channelKey);
    $label      = trim((string) $label);
    $url        = trim((string) $url);

    if ($channelKey === '') {
        throw new InvalidArgumentException('Channel key is required.');
    }
    if (!preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/', $channelKey)) {
        throw new InvalidArgumentException(
            'Channel key may only contain letters, numbers, and . _ : -  (max 120 chars).'
        );
    }
    if ($label === '') {
        throw new InvalidArgumentException('Label is required.');
    }
    if (strlen($label) > 120) {
        throw new InvalidArgumentException('Label must be 120 characters or fewer.');
    }
    $validRegClasses = ['amateur', 'commercial', 'pstn', 'internal'];
    if (!in_array($regClass, $validRegClasses, true)) {
        throw new InvalidArgumentException(
            'Regulatory class must be one of: ' . implode(', ', $validRegClasses) . '.'
        );
    }
    if ($url === '') {
        throw new InvalidArgumentException('Stream URL is required.');
    }
    if (strlen($url) > 2000) {
        throw new InvalidArgumentException('Stream URL is too long (max 2000 characters).');
    }
    // Scheme check only -- deliberately not a reachability probe. A probe
    // would mean "Save" silently blocks on a slow/unreachable stream for
    // however long a socket connect takes, and a stream that's briefly
    // down when saved but comes back later would be wrongly refused.
    // http:// is common for these feeds (Icecast rarely bothers with TLS)
    // so https-only would reject legitimate real-world stream URLs.
    if (!preg_match('~^https?://~i', $url)) {
        throw new InvalidArgumentException('Stream URL must start with http:// or https://');
    }
    return ['channel_key' => $channelKey, 'label' => $label, 'url' => $url];
}

/**
 * Create a new http_stream comm_channels row. Returns the new id.
 * Throws InvalidArgumentException on validation failure (caller -> 400)
 * or a plain Exception on a duplicate channel_key (caller -> 409, matching
 * the UNIQUE KEY constraint's own meaning: this is a name collision, not
 * a malformed request).
 */
function http_stream_channel_create($channelKey, $label, $regClass, $url) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $v = http_stream_channel_validate($channelKey, $label, $regClass, $url);

    $existing = db_fetch_one(
        "SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?",
        [$v['channel_key']]
    );
    if ($existing) {
        throw new RuntimeException(
            "A channel with key '{$v['channel_key']}' already exists. Pick a different channel key."
        );
    }

    $configJson = json_encode(['url' => $v['url']], JSON_UNESCAPED_SLASHES);
    db_query(
        "INSERT INTO `{$prefix}comm_channels`
            (channel_key, adapter, label, regulatory_class, config_json, enabled, managed, sort_order)
         VALUES (?, 'http_stream', ?, ?, ?, 1, 0, 500)",
        [$v['channel_key'], $v['label'], $regClass, $configJson]
    );
    return (int) db_insert_id();
}

/**
 * Update an existing http_stream channel's label/regulatory_class/url/
 * enabled. channel_key is immutable once created (it's the live matrix's
 * own channel id — renaming it out from under an existing patch/route
 * would orphan every comm_routes row pointing at the old key; delete +
 * recreate is the correct path for a genuine rename, same as every other
 * adapter's channel_key in this codebase).
 */
function http_stream_channel_update($id, $label, $regClass, $url, $enabled) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $existing = db_fetch_one(
        "SELECT * FROM `{$prefix}comm_channels` WHERE id = ? AND adapter = 'http_stream'",
        [(int) $id]
    );
    if (!$existing) {
        throw new RuntimeException('Stream channel not found.');
    }
    // channel_key is fixed; validate the rest against it.
    $v = http_stream_channel_validate($existing['channel_key'], $label, $regClass, $url);
    $configJson = json_encode(['url' => $v['url']], JSON_UNESCAPED_SLASHES);
    $enabledInt = $enabled ? 1 : 0;

    db_query(
        "UPDATE `{$prefix}comm_channels`
            SET label = ?, regulatory_class = ?, config_json = ?, enabled = ?
          WHERE id = ?",
        [$v['label'], $regClass, $configJson, $enabledInt, (int) $id]
    );
    return http_stream_channel_get($id);
}

/** Delete an http_stream channel row. Returns the deleted row (for the
 *  caller to apply the matching live-matrix removal + audit log) or null
 *  if it didn't exist. */
function http_stream_channel_delete($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $existing = http_stream_channel_get($id);
    if (!$existing) { return null; }
    db_query(
        "DELETE FROM `{$prefix}comm_channels` WHERE id = ? AND adapter = 'http_stream'",
        [(int) $id]
    );
    return $existing;
}

}
