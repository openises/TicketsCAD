<?php
/**
 * Phase 152 prerequisite #4 — PHP client for the audio-matrix service's
 * control plane (services/audio-matrix/control_http.py), used by
 * api/matrix.php to apply a comm_routes write to the LIVE running
 * service immediately, not just on its next restart (service.py already
 * loads comm_routes at boot — this is what makes a change ALSO take
 * effect without one, closing the "no silent routes" guardrail's other
 * half: inc/matrix-routes.php already guarantees a saved row can never be
 * one the live service would silently skip at load time; this guarantees
 * a saved row doesn't sit there UNAPPLIED until someone happens to
 * restart the service).
 *
 * Fails CLOSED by design: every public function here returns
 * ['ok'=>bool, 'error'=>string|null, ...]. api/matrix.php treats ok=false
 * as fatal for the whole request — rolling back whatever DB write it just
 * made and surfacing "matrix service unreachable," never silently
 * queuing a change the running service doesn't know about.
 *
 * Configuration: `matrix_control_url` (e.g. "http://127.0.0.1:18092") and
 * `matrix_control_token` (the SAME shared secret
 * api/matrix-id-check.php already documents as "the shared secret the
 * PHP console uses," used in both directions of the PHP<->matrix-service
 * relationship). Neither is seeded by a migration — like the rest of this
 * subsystem's infra (the Python service itself has no systemd unit or
 * deploy step on any install yet, per CLAUDE.md's Phase 114c entry), an
 * admin sets these directly in the settings table when the matrix
 * service is actually deployed. Unconfigured is the default, correct
 * state on every install today: matrix_control_request() reports it as a
 * clean ok=false/"not configured" failure, not a crash, and every caller
 * here treats that exactly like "the service is unreachable."
 */

if (!function_exists('matrix_control_base_url')) {

function matrix_control_base_url() {
    $url = trim((string) get_variable('matrix_control_url'));
    return $url !== '' ? rtrim($url, '/') : '';
}

function matrix_control_token() {
    return (string) get_variable('matrix_control_token');
}

/**
 * Low-level HTTP call to the control plane. Returns
 * ['ok'=>bool, 'status'=>int, 'body'=>array|null, 'error'=>string|null].
 * Never throws — every failure mode (unconfigured, unreachable, timeout,
 * non-2xx, malformed JSON) folds into ok=false + a human-readable error,
 * since every caller needs one uniform "did this actually apply?" signal
 * regardless of WHY it didn't.
 *
 * Short timeouts (3s connect / 5s total) deliberately — this runs inside
 * an interactive admin request; a hung matrix service must fail fast with
 * a clear error, not leave the browser spinning.
 */
function matrix_control_request($method, $path, ?array $body = null) {
    $base = matrix_control_base_url();
    if ($base === '') {
        return ['ok' => false, 'status' => 0, 'body' => null,
                'error' => 'matrix_control_url is not configured'];
    }
    $token = matrix_control_token();
    if ($token === '') {
        return ['ok' => false, 'status' => 0, 'body' => null,
                'error' => 'matrix_control_token is not configured'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => null,
                'error' => 'curl extension not available'];
    }

    $ch = curl_init($base . $path);
    $headers = ['Authorization: Bearer ' . $token];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body !== null ? json_encode($body) : null,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'body' => null,
                'error' => 'matrix service unreachable: ' . $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : ('HTTP ' . $status);
        return ['ok' => false, 'status' => $status, 'body' => $decoded,
                'error' => 'matrix service rejected the change: ' . $msg];
    }
    return ['ok' => true, 'status' => $status, 'body' => $decoded, 'error' => null];
}

/**
 * Apply a newly-created route to the live matrix. $route is
 * matrix_route_full()'s row shape (must have real src_key/dst_key —
 * matrix_route_create() only ever inserts rows whose channels exist, so a
 * freshly-created route can never be an orphan).
 */
function matrix_control_apply_create(array $route) {
    return matrix_control_request('POST', '/routes', [
        'src' => (string) $route['src_key'],
        'dst' => (string) $route['dst_key'],
        'gain_db' => (float) $route['gain_db'],
        'priority' => (int) $route['priority'],
        'ducking' => (bool) $route['ducking'],
        'enabled' => (bool) $route['enabled'],
        'allow_cross_class' => (bool) $route['allow_cross_class'],
        // Phase 152 prereq #6 — sent as a Unix epoch (int), never a
        // formatted string, so there's no timezone ambiguity between PHP
        // and the Python service. null = never expires.
        'expires_at' => !empty($route['expires_at']) ? strtotime($route['expires_at']) : null,
    ]);
}

/**
 * Apply an updated route. $oldRoute is the PRE-update row (locates the
 * live route by its OLD src/dst channel key), $newRoute is
 * matrix_route_full()'s row taken AFTER the DB update. The control plane
 * has no field-level "patch a route" verb (control_http.py's POST
 * /routes always ADDS, and would immediately reject the common case of
 * "same src/dst, just a new gain" as a duplicate) — so this is a remove-
 * then-add on the Python side (see control_http.py's own /routes/update
 * handler docblock for the narrow atomicity gap that implies, and why
 * it's an acceptable one here).
 *
 * An orphan route (src_key or dst_key NULL — its channel was pruned by
 * channel_registry_sync() after this route was created) has nothing live
 * to update in the first place; skipped as a no-op success rather than a
 * failure.
 */
function matrix_control_apply_update(array $oldRoute, array $newRoute) {
    if (empty($oldRoute['src_key']) || empty($oldRoute['dst_key'])) {
        return ['ok' => true, 'status' => 0, 'body' => null, 'error' => null, 'skipped' => true];
    }
    return matrix_control_request('POST', '/routes/update', [
        'old_src' => (string) $oldRoute['src_key'],
        'old_dst' => (string) $oldRoute['dst_key'],
        'src' => (string) $newRoute['src_key'],
        'dst' => (string) $newRoute['dst_key'],
        'gain_db' => (float) $newRoute['gain_db'],
        'priority' => (int) $newRoute['priority'],
        'ducking' => (bool) $newRoute['ducking'],
        'enabled' => (bool) $newRoute['enabled'],
        'allow_cross_class' => (bool) $newRoute['allow_cross_class'],
        'expires_at' => !empty($newRoute['expires_at']) ? strtotime($newRoute['expires_at']) : null,
    ]);
}

/**
 * Apply a route deletion to the live matrix. $route is the row about to
 * be removed. An orphan route (NULL src_key/dst_key) has nothing live to
 * remove — skipped as a no-op success, matching matrix_control_apply_update()'s
 * same reasoning.
 */
function matrix_control_apply_delete(array $route) {
    if (empty($route['src_key']) || empty($route['dst_key'])) {
        return ['ok' => true, 'status' => 0, 'body' => null, 'error' => null, 'skipped' => true];
    }
    $q = http_build_query(['src' => (string) $route['src_key'], 'dst' => (string) $route['dst_key']]);
    return matrix_control_request('DELETE', '/routes?' . $q);
}

/**
 * Push ONE direction of an adjacent-transmit-mute pairing (Phase 152
 * plan.md section 3.5) to the live matrix. Deliberately BEST-EFFORT,
 * unlike the route-apply functions above: those fail closed because a
 * comm_routes write that silently never reaches the running service would
 * leave a real, currently-active audio path unpatched or un-torn-down.
 * This feature has no such failure mode — console_workstation_mutes is
 * independently useful storage (an admin can audit "who has muted whom"
 * regardless of whether a matrix service is even deployed), and
 * service.py's own load_workstation_mutes() re-syncs every pairing from
 * the database at boot, so a push that fails here (service unreachable,
 * not configured) is caught up automatically the next time the service
 * starts — never a silent, permanent gap the way an un-applied route
 * would be. The caller (api/console-workstation-mutes.php) is expected to
 * ignore this call's ok=false, not treat it as fatal.
 *
 * $workstationToken is the one whose speakers should (muted=true) or
 * should no longer (muted=false) suppress $mutedWorkstationToken's own
 * transmissions — same direction convention as MatrixCore.
 * set_workstation_mute() and console_workstation_mutes.workstation_id /
 * .muted_workstation_id.
 */
function matrix_control_apply_workstation_mute(string $workstationToken, string $mutedWorkstationToken, bool $muted) {
    return matrix_control_request('POST', '/workstation-mutes', [
        'workstation_token' => $workstationToken,
        'muted_workstation_token' => $mutedWorkstationToken,
        'muted' => $muted,
    ]);
}

/**
 * Apply a newly-created (or edited) public-stream channel to the live
 * matrix — hot-attaches a real HttpStreamLeg immediately, no service
 * restart (2026-09-08, Eric: "I want to create multiple streams as
 * needed"). Fails CLOSED like the route-apply functions above, NOT
 * best-effort like the workstation-mute one: an http_stream row with no
 * live leg attached is a channel that silently never carries any audio,
 * which is exactly the kind of gap this project's "no silent routes"
 * discipline exists to prevent for routes — the same principle applies
 * here one layer up, to the channel itself.
 */
function matrix_control_apply_http_stream_create(string $channelKey, string $label, string $regClass, string $url) {
    return matrix_control_request('POST', '/channels/http_stream', [
        'channel_id' => $channelKey,
        'name' => $label,
        'reg_class' => $regClass,
        'url' => $url,
    ]);
}

/**
 * Remove a public-stream channel from the live matrix (stops its
 * HttpStreamLeg's ffmpeg subprocess + RX thread, and removes the channel
 * — which also prunes any live routes referencing it, per MatrixCore.
 * remove_channel()'s own behavior). Fails closed, same reasoning as
 * matrix_control_apply_http_stream_create().
 */
function matrix_control_apply_http_stream_delete(string $channelKey) {
    return matrix_control_request('DELETE', '/channels/http_stream?' . http_build_query(['channel_id' => $channelKey]));
}

/**
 * Phase 155 (GH#151/GH#129) — hot-attach (or re-attach after an edit) the
 * generic USRP leg for a digital-voice-bridge channel, no service restart.
 * $config is inc/voice-bridge-channels.php's vbc_leg_config() (bridge_host,
 * bridge_tx_port, listen_host, listen_port, framing, rx_hang_ms). Fails
 * CLOSED like the http_stream and route functions above: a channel row with
 * no live leg is a strip that silently never carries audio.
 */
function matrix_control_apply_leg_create(string $channelKey, string $label, string $regClass, string $adapter, array $config) {
    return matrix_control_request('POST', '/channels/leg', [
        'channel_id' => $channelKey,
        'name'       => $label,
        'reg_class'  => $regClass,
        'adapter'    => $adapter,
        'config'     => $config,
    ]);
}

/** Detach a USRP-family leg: stops it, frees its UDP port and removes the
 *  channel (and its patches) from the live matrix. */
function matrix_control_apply_leg_delete(string $channelKey) {
    return matrix_control_request('DELETE', '/channels/leg?' . http_build_query(['channel_id' => $channelKey]));
}

/**
 * Per-leg health from the running service (GET /legs, bearer-protected).
 * Returns ['ok'=>bool, 'legs'=>array keyed by channel key, 'error'=>?string].
 * ok=false with a message when the service is unconfigured, unreachable, or
 * too old to know /legs (501/404) — callers show that as "unknown", never as
 * "no legs".
 */
function matrix_control_legs() {
    $r = matrix_control_request('GET', '/legs');
    if (!$r['ok']) {
        return ['ok' => false, 'legs' => [], 'error' => $r['error']];
    }
    $byKey = [];
    foreach ((is_array($r['body']) ? ($r['body']['legs'] ?? []) : []) as $leg) {
        if (is_array($leg) && isset($leg['channel_id'])) { $byKey[(string) $leg['channel_id']] = $leg; }
    }
    return ['ok' => true, 'legs' => $byKey, 'error' => null];
}

}
