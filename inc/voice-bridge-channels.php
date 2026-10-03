<?php
/**
 * Digital voice bridge channels (Phase 155, GH#151 + GH#129) — admin CRUD,
 * validation, capability derivation and the bridge-config snippet.
 *
 * comm_channels rows with adapter='dvmproject' or adapter='usrp_bridge': one
 * row per talkgroup / bridge, patched into the audio matrix like any other
 * channel. Both adapters are the SAME leg in the Python service
 * (services/audio-matrix/legs/usrp.py): a UDP "USRP" socket exchanging 8 kHz
 * PCM with a bridge program the operator installs themselves (DVMProject's
 * `dvmbridge`, DVSwitch's Analog_Bridge, AllStar's chan_usrp). TicketsCAD
 * bundles no codec and speaks no radio-network protocol.
 *
 * LISTEN-ONLY (Release A). Nothing in this file, the leg, or the console can
 * make one of these channels transmit; VBC_TX_SUPPORTED below is the single
 * switch a later release flips, together with the station-ID machinery that
 * does not exist for these channels yet. See
 * specs/phase-155-community-backlog/151-dvm-host-and-129-p25-bridge.md.
 *
 * Always created managed=0 (channel_registry_sync() only touches managed=1
 * rows) with regulatory_class_locked=1 where that column exists. The class is
 * limited to amateur|commercial: a digital-voice bridge is a radio network,
 * and 'internal'/'pstn' would exempt it from the cross-class patch guard.
 *
 * api/voice-bridge-channels.php is the thin HTTP wrapper that also applies
 * every write to the LIVE matrix service (inc/matrix-control-client.php),
 * rolling the database back when the service refuses.
 *
 * Secrets never live in config_json: channels_all() returns config_json to
 * every screen.console holder. The only secret in this feature (the FNE REST
 * password) is a `settings` row ending _password, which is masked everywhere
 * (inc/settings-secrets.php).
 */

if (!function_exists('vbc_adapters')) {

/** Release A is listen-only. A later release flips this together with the
 *  compliance prerequisites; nothing else may decide it. */
if (!defined('VBC_TX_SUPPORTED')) { define('VBC_TX_SUPPORTED', false); }

/** Bump when the policy statement below changes materially. Recorded with
 *  each acknowledgment. */
if (!defined('VBC_POLICY_VERSION')) { define('VBC_POLICY_VERSION', '2026-10-02'); }

/** Adapter keys served by the generic USRP leg. */
function vbc_adapters() {
    return ['dvmproject', 'usrp_bridge'];
}

function vbc_is_voice_bridge_adapter($adapter) {
    return in_array((string) $adapter, vbc_adapters(), true);
}

/** channel_key prefix per adapter. Keeps these keys out of every other
 *  namespace (zello:, dmr_bm:, broker:, and the matrix's own dynamic
 *  browser:<id> channels). */
function vbc_key_prefix($adapter) {
    return $adapter === 'dvmproject' ? 'dvm:' : 'usrp:';
}

/** Radio classes an admin may choose. See the file docblock. */
function vbc_allowed_classes() {
    return ['amateur', 'commercial'];
}

/** mode => label. 'mode' is a display and snippet hint only: the real mode
 *  lives in the bridge program's own configuration. */
function vbc_modes() {
    return ['p25' => 'P25', 'dmr' => 'DMR', 'analog' => 'Analog', 'other' => 'Other / not stated'];
}

// ── Policy acknowledgment ───────────────────────────────────────────────

/**
 * The DVMProject usage-policy statement, as paragraphs of plain text. Shown
 * in the admin page (rendered with textContent) and mirrored in
 * docs/DIGITAL-VOICE-USRP.md. Deliberately a neutral statement of what the
 * project's own guidelines say and where responsibility lies — no
 * recommendation, no marketing.
 *
 * @return string[]
 */
function vbc_policy_paragraphs() {
    return [
        "DVMProject's published usage guidelines (usage_guidelines.md in its repository) say that its software is not intended for public safety, fire, EMS, law enforcement or emergency management use, including in a backup, interoperability or support role, and not for dispatch integration, and that the project does not give support for such use. They also say passive hobby monitoring of public radio traffic is allowed. This is a summary, not the authority: read the current text yourself.",
        "The software is released under the GPL-2.0 licence, and the guidelines state that they do not restrict the rights that licence grants. TicketsCAD bundles and redistributes no DVMProject software. This adapter only exchanges audio with a bridge program (dvmbridge) that you install, configure and operate yourself.",
        "Using this adapter is your decision and your responsibility, as is compliance with the DVMProject guidelines, the licence of every program involved, the rules of the radio service your frequencies are in, and your own agency's policies. TicketsCAD cannot check any of that for you.",
        "This build is listen-only: it receives audio from the bridge and never transmits.",
        "Acknowledging records your username and the time in the audit log. Withdrawing the acknowledgment disables every DVMProject channel.",
    ];
}

/** Read one settings value directly from the database (never get_variable():
 *  its per-process cache would hide a write made earlier in this request, and
 *  the Python service reads the table directly too). */
function vbc_setting_get($name, $default = '') {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        return ($v === false || $v === null) ? $default : (string) $v;
    } catch (Exception $e) {
        return $default;
    }
}

function vbc_setting_set($name, $value) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$name, (string) $value]
    );
}

/**
 * Has the DVMProject usage-policy acknowledgment been recorded? Mirrors
 * services/audio-matrix/service.py's _dvm_policy_acknowledged() EXACTLY
 * (a non-blank value): the PHP admin API and the Python service must agree on
 * what "acknowledged" means or the admin page could show a channel as
 * enabled while the service silently refuses to bind it.
 */
function vbc_policy_acknowledged() {
    return trim(vbc_setting_get('dvm_policy_ack', '')) !== '';
}

/** The recorded acknowledgment, decoded, or null. A value that is not our
 *  JSON (hand-written) still counts as acknowledged but carries no detail. */
function vbc_policy_ack_get() {
    $raw = trim(vbc_setting_get('dvm_policy_ack', ''));
    if ($raw === '') { return null; }
    $j = json_decode($raw, true);
    if (is_array($j)) { return $j; }
    return ['version' => null, 'user_id' => null, 'username' => null, 'at' => null, 'note' => 'value written outside the admin page'];
}

/** Record the acknowledgment. Returns the stored record. */
function vbc_policy_ack_record($userId, $username) {
    $rec = [
        'version'  => VBC_POLICY_VERSION,
        'user_id'  => (int) $userId,
        'username' => (string) $username,
        'at'       => date('Y-m-d H:i:s'),
    ];
    vbc_setting_set('dvm_policy_ack', json_encode($rec));
    return $rec;
}

/** Withdraw the acknowledgment. The caller disables every dvmproject row
 *  (vbc_disable_all_dvmproject()). */
function vbc_policy_ack_revoke() {
    vbc_setting_set('dvm_policy_ack', '');
}

// ── Reads ───────────────────────────────────────────────────────────────

function vbc_row_from_db(array $r) {
    $r['id'] = (int) $r['id'];
    $r['enabled'] = (int) $r['enabled'];
    $r['config'] = $r['config_json'] ? (json_decode($r['config_json'], true) ?: []) : [];
    $r['capabilities'] = $r['capabilities_json'] ? (json_decode($r['capabilities_json'], true) ?: []) : [];
    unset($r['config_json'], $r['capabilities_json']);
    return $r;
}

/** Every digital-voice-bridge channel, in the order an admin created them. */
function vbc_list() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ph = implode(',', array_fill(0, count(vbc_adapters()), '?'));
    $rows = db_fetch_all(
        "SELECT c.id, c.channel_key, c.adapter, c.label, c.regulatory_class, c.enabled, c.config_json,
                c.capabilities_json, c.created_at, c.updated_at,
                s.state AS link_state, s.last_rx_at, s.last_error
           FROM `{$prefix}comm_channels` c
      LEFT JOIN `{$prefix}comm_channel_state` s ON s.channel_id = c.id
          WHERE c.adapter IN ($ph)
          ORDER BY c.id",
        vbc_adapters()
    );
    return array_map('vbc_row_from_db', $rows);
}

function vbc_get($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ph = implode(',', array_fill(0, count(vbc_adapters()), '?'));
    $row = db_fetch_one(
        "SELECT c.id, c.channel_key, c.adapter, c.label, c.regulatory_class, c.enabled, c.config_json,
                c.capabilities_json, c.created_at, c.updated_at,
                s.state AS link_state, s.last_rx_at, s.last_error
           FROM `{$prefix}comm_channels` c
      LEFT JOIN `{$prefix}comm_channel_state` s ON s.channel_id = c.id
          WHERE c.id = ? AND c.adapter IN ($ph)",
        array_merge([(int) $id], vbc_adapters())
    );
    return $row ? vbc_row_from_db($row) : null;
}

/**
 * The part of a channel's config an ordinary console operator may see. The
 * full config names the bridge's address and ports, which only the people
 * who administer the bridges need (api/channels.php applies this to anyone
 * without action.manage_voice_bridges).
 */
function vbc_public_config(array $config) {
    $out = [];
    foreach (['mode', 'talkgroup'] as $k) {
        if (isset($config[$k]) && $config[$k] !== '') { $out[$k] = $config[$k]; }
    }
    $out['listen_only'] = true;
    return $out;
}

// ── Validation ──────────────────────────────────────────────────────────

/** IPv4 loopback or RFC1918. A public address is refused: the bridge's UDP
 *  port has no authentication, so the only safe peers are on this host or the
 *  private LAN. */
function vbc_host_allowed($ip) {
    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    $n = ip2long($ip);
    if ($n === false) { return false; }
    $ranges = [
        ['127.0.0.0', '127.255.255.255'],
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
    ];
    foreach ($ranges as $r) {
        if ($n >= ip2long($r[0]) && $n <= ip2long($r[1])) { return true; }
    }
    return false;
}

function vbc_is_loopback($ip) {
    $n = ip2long((string) $ip);
    return $n !== false && $n >= ip2long('127.0.0.0') && $n <= ip2long('127.255.255.255');
}

function vbc_check_port($value, $what) {
    if (is_string($value)) { $value = trim($value); }
    if ($value === '' || $value === null || !preg_match('/^[0-9]{1,5}$/', (string) $value)) {
        throw new InvalidArgumentException("$what must be a port number between 1024 and 65535.");
    }
    $p = (int) $value;
    if ($p < 1024 || $p > 65535) {
        throw new InvalidArgumentException("$what must be between 1024 and 65535.");
    }
    return $p;
}

/** Is a listen port already claimed by another digital-voice channel
 *  (enabled or not — a disabled row binds the moment it is enabled)? */
function vbc_listen_port_owner($port, $excludeId = null) {
    foreach (vbc_list() as $r) {
        if ($excludeId !== null && (int) $r['id'] === (int) $excludeId) { continue; }
        if ((int) ($r['config']['listen_port'] ?? 0) === (int) $port) {
            return $r;
        }
    }
    return null;
}

/**
 * Validate and normalise a channel's non-secret config from raw input.
 * Throws InvalidArgumentException with a human-readable message. The Python
 * builder (service.py build_usrp_leg) enforces the same rules; the contract
 * test in tests/test_voice_bridge_channels.php feeds this function's output
 * straight into it.
 *
 * @return array the config to store (and, minus display-only keys, to send
 *               to the live service)
 */
function vbc_validate_config($adapter, array $in, $excludeId = null) {
    $modes = vbc_modes();
    $mode = strtolower(trim((string) ($in['mode'] ?? ($adapter === 'dvmproject' ? 'p25' : 'other'))));
    if (!isset($modes[$mode])) {
        throw new InvalidArgumentException('Mode must be one of: ' . implode(', ', array_keys($modes)) . '.');
    }

    $bridgeHost = trim((string) ($in['bridge_host'] ?? '127.0.0.1'));
    if (!vbc_host_allowed($bridgeHost)) {
        throw new InvalidArgumentException(
            'Bridge address must be an IPv4 address on this host or your private network '
            . '(127.x.x.x, 10.x.x.x, 172.16-31.x.x or 192.168.x.x). A hostname or a public address is refused: '
            . 'the bridge\'s UDP port has no authentication.');
    }
    $listenHost = trim((string) ($in['listen_host'] ?? '127.0.0.1'));
    if (!vbc_host_allowed($listenHost)) {
        throw new InvalidArgumentException(
            'Listen address must be an IPv4 address of this host on a loopback or private network '
            . '(127.x.x.x, 10.x.x.x, 172.16-31.x.x or 192.168.x.x). 0.0.0.0 is refused.');
    }

    $txPort = vbc_check_port($in['bridge_tx_port'] ?? 32001, 'Bridge receive port');
    $listenPort = vbc_check_port($in['listen_port'] ?? 34001, 'Listen port');
    if ($bridgeHost === $listenHost && $txPort === $listenPort) {
        throw new InvalidArgumentException(
            'The listen port and the bridge receive port cannot be the same when both are on '
            . $listenHost . ' — they are two different sockets.');
    }
    $owner = vbc_listen_port_owner($listenPort, $excludeId);
    if ($owner) {
        throw new InvalidArgumentException(
            "Listen port $listenPort is already used by the channel '{$owner['label']}'. Each channel needs its own port.");
    }

    $hang = $in['rx_hang_ms'] ?? 400;
    if (is_string($hang)) { $hang = trim($hang); }
    if ($hang === '' || !preg_match('/^[0-9]{1,5}$/', (string) $hang)) {
        throw new InvalidArgumentException('End-of-call silence must be a number of milliseconds between 100 and 5000.');
    }
    $hang = (int) $hang;
    if ($hang < 100 || $hang > 5000) {
        throw new InvalidArgumentException('End-of-call silence must be between 100 and 5000 milliseconds.');
    }

    $tg = trim((string) ($in['talkgroup'] ?? ''));
    if ($tg !== '' && !preg_match('/^[0-9]{1,8}$/', $tg)) {
        throw new InvalidArgumentException('Talkgroup must be a number (or left blank).');
    }

    $peer = trim((string) ($in['fne_peer_id'] ?? ($in['fne']['peer_id'] ?? '')));
    if ($peer !== '' && (!preg_match('/^[0-9]{1,10}$/', $peer) || (int) $peer < 1 || (int) $peer > 4294967295)) {
        throw new InvalidArgumentException('FNE peer ID must be a positive whole number (or left blank).');
    }

    if (!empty($in['tx_enabled'])) {
        throw new InvalidArgumentException(
            'Transmit is not available: digital voice bridge channels are listen-only in this release.');
    }

    $config = [
        'mode'           => $mode,
        'bridge_host'    => $bridgeHost,
        'bridge_tx_port' => $txPort,
        'listen_host'    => $listenHost,
        'listen_port'    => $listenPort,
        'framing'        => 'usrp',
        'rx_hang_ms'     => $hang,
        'tx_enabled'     => false,
        'talkgroup'      => $tg,
    ];
    if ($peer !== '') { $config['fne'] = ['peer_id' => (int) $peer]; }
    return $config;
}

/** Validate key slug + label + class; returns normalised parts. */
function vbc_validate_identity($adapter, $slug, $label, $regClass) {
    if (!vbc_is_voice_bridge_adapter($adapter)) {
        throw new InvalidArgumentException('Unknown channel type.');
    }
    $slug = strtolower(trim((string) $slug));
    if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $slug)) {
        throw new InvalidArgumentException(
            'Channel key may only contain lower-case letters, numbers, - and _ (1-40 characters, starting with a letter or number).');
    }
    $label = trim((string) $label);
    if ($label === '') { throw new InvalidArgumentException('Label is required.'); }
    if (strlen($label) > 120) { throw new InvalidArgumentException('Label must be 120 characters or fewer.'); }
    if (!in_array((string) $regClass, vbc_allowed_classes(), true)) {
        throw new InvalidArgumentException(
            'Regulatory class must be amateur or commercial. A digital voice bridge is a radio network; '
            . "'internal' or 'pstn' would exempt it from the cross-class patch guard.");
    }
    return ['channel_key' => vbc_key_prefix($adapter) . $slug, 'label' => $label, 'regulatory_class' => (string) $regClass];
}

/** Capabilities stored on the row and read by the console (console.js). */
function vbc_capabilities(array $config) {
    $caps = ['voice_rx' => true];
    if (VBC_TX_SUPPORTED && !empty($config['tx_enabled'])) {
        $caps['voice_tx'] = true;
        $caps['ptt_floor'] = true;
    }
    return $caps;
}

/** The subset of a row's config the live service needs. Display-only keys
 *  (mode, talkgroup, fne) stay in PHP. */
function vbc_leg_config(array $config) {
    $out = [];
    foreach (['bridge_host', 'bridge_tx_port', 'listen_host', 'listen_port', 'framing', 'rx_hang_ms'] as $k) {
        if (array_key_exists($k, $config)) { $out[$k] = $config[$k]; }
    }
    $out['tx_enabled'] = false;
    return $out;
}

function vbc_comm_channels_has_lock_column() {
    static $has = null;
    if ($has === null) {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $has = (bool) db_fetch_value(
                "SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'regulatory_class_locked'",
                [$prefix . 'comm_channels']
            );
        } catch (Exception $e) {
            $has = false;
        }
    }
    return $has;
}

// ── Writes ──────────────────────────────────────────────────────────────

/**
 * Create a channel row. Returns the new id.
 * @throws InvalidArgumentException validation (-> 400)
 * @throws RuntimeException duplicate key (-> 409); code 403 = the DVMProject
 *         acknowledgment has not been recorded
 */
function vbc_create($adapter, $slug, $label, $regClass, array $in, $enabled = true) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $id = vbc_validate_identity($adapter, $slug, $label, $regClass);
    $config = vbc_validate_config($adapter, $in);

    if ($enabled && $adapter === 'dvmproject' && !vbc_policy_acknowledged()) {
        throw new RuntimeException(
            'The DVMProject usage-policy acknowledgment has not been recorded. Read and acknowledge it first.', 403);
    }
    if (db_fetch_one("SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?", [$id['channel_key']])) {
        throw new RuntimeException("A channel with key '{$id['channel_key']}' already exists. Pick a different key.", 409);
    }

    $caps = vbc_capabilities($config);
    $cols = '(channel_key, adapter, label, regulatory_class, config_json, capabilities_json, enabled, managed, sort_order';
    $vals = '(?, ?, ?, ?, ?, ?, ?, 0, 500';
    $args = [$id['channel_key'], $adapter, $id['label'], $id['regulatory_class'],
             json_encode($config, JSON_UNESCAPED_SLASHES), json_encode($caps), $enabled ? 1 : 0];
    if (vbc_comm_channels_has_lock_column()) {
        $cols .= ', regulatory_class_locked';
        $vals .= ', 1';
    }
    db_query("INSERT INTO `{$prefix}comm_channels` $cols) VALUES $vals)", $args);
    $newId = (int) db_insert_id();
    try {
        db_query("INSERT IGNORE INTO `{$prefix}comm_channel_state` (channel_id, state) VALUES (?, 'unknown')", [$newId]);
    } catch (Exception $e) { /* health state is optional */ }
    return $newId;
}

/**
 * Update label / class / config / enabled. channel_key and adapter are
 * immutable (the key is the live matrix's channel id; renaming it would
 * orphan every route). Returns ['row' => after, 'before' => before].
 * @throws InvalidArgumentException|RuntimeException as vbc_create()
 */
function vbc_update($id, array $in) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $before = vbc_get($id);
    if (!$before) { throw new RuntimeException('Channel not found.', 404); }

    $label = array_key_exists('label', $in) ? (string) $in['label'] : $before['label'];
    $regClass = array_key_exists('regulatory_class', $in) ? (string) $in['regulatory_class'] : $before['regulatory_class'];
    $enabled = array_key_exists('enabled', $in) ? !empty($in['enabled']) : (bool) $before['enabled'];

    vbc_validate_identity($before['adapter'], substr($before['channel_key'], strlen(vbc_key_prefix($before['adapter']))), $label, $regClass);
    $merged = array_merge($before['config'], $in);
    // 'fne' is stored nested but arrives flat from the form.
    if (array_key_exists('fne_peer_id', $in)) { unset($merged['fne']); }
    $config = vbc_validate_config($before['adapter'], $merged, (int) $id);

    if ($enabled && $before['adapter'] === 'dvmproject' && !vbc_policy_acknowledged()) {
        throw new RuntimeException(
            'The DVMProject usage-policy acknowledgment has not been recorded. Read and acknowledge it first.', 403);
    }

    $sets = 'label = ?, regulatory_class = ?, config_json = ?, capabilities_json = ?, enabled = ?';
    $args = [trim($label), $regClass, json_encode($config, JSON_UNESCAPED_SLASHES),
             json_encode(vbc_capabilities($config)), $enabled ? 1 : 0];
    if (vbc_comm_channels_has_lock_column()) { $sets .= ', regulatory_class_locked = 1'; }
    $args[] = (int) $id;
    db_query("UPDATE `{$prefix}comm_channels` SET $sets WHERE id = ? AND adapter IN ('dvmproject', 'usrp_bridge')", $args);
    return ['row' => vbc_get($id), 'before' => $before];
}

/** Put a row back exactly as it was (used when the live service refuses an
 *  edit, so the database and the running matrix never disagree). */
function vbc_restore(array $before) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "UPDATE `{$prefix}comm_channels`
            SET label = ?, regulatory_class = ?, config_json = ?, capabilities_json = ?, enabled = ?
          WHERE id = ?",
        [$before['label'], $before['regulatory_class'], json_encode($before['config'], JSON_UNESCAPED_SLASHES),
         json_encode($before['capabilities']), (int) $before['enabled'], (int) $before['id']]
    );
}

/**
 * Delete a channel, its health state and every patch that referenced it
 * (a patch to a vanished channel is meaningless and the service skips it at
 * boot anyway). Returns ['row' => deleted row, 'routes_removed' => n], or
 * null if it did not exist.
 */
function vbc_delete($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $row = vbc_get($id);
    if (!$row) { return null; }
    $routes = 0;
    try {
        $stmt = db_query("DELETE FROM `{$prefix}comm_routes` WHERE src_channel_id = ? OR dst_channel_id = ?",
                         [(int) $id, (int) $id]);
        $routes = $stmt ? (int) $stmt->rowCount() : 0;
    } catch (Exception $e) { /* comm_routes may not exist on this install */ }
    try {
        db_query("DELETE FROM `{$prefix}comm_channel_state` WHERE channel_id = ?", [(int) $id]);
    } catch (Exception $e) { /* optional */ }
    db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ? AND adapter IN ('dvmproject', 'usrp_bridge')", [(int) $id]);
    return ['row' => $row, 'routes_removed' => $routes];
}

/** Withdrawing the acknowledgment disables every dvmproject channel.
 *  Returns the rows that were enabled (the caller detaches their legs). */
function vbc_disable_all_dvmproject() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $was = [];
    foreach (vbc_list() as $r) {
        if ($r['adapter'] === 'dvmproject' && (int) $r['enabled'] === 1) { $was[] = $r; }
    }
    if ($was) {
        db_query("UPDATE `{$prefix}comm_channels` SET enabled = 0 WHERE adapter = 'dvmproject'");
    }
    return $was;
}

// ── bridge-config snippet ───────────────────────────────────────────────

/**
 * The lines to put in the BRIDGE program's own configuration so its UDP
 * ports and framing mirror this channel's. The number one misconfiguration is
 * mirrored ports and flags, so this is generated, not hand-written.
 *
 * dvmproject: a YAML fragment shaped like the example configuration that
 * ships with dvmbridge (read 2026-10-02; compare it with the example file of
 * YOUR version). The password is a placeholder: TicketsCAD never has it.
 * usrp_bridge: the values to point the program at, without inventing a file
 * syntax this project has not verified.
 */
function vbc_snippet(array $row) {
    $c = $row['config'];
    $mode = $c['mode'] ?? 'other';
    $lines = [];
    if ($row['adapter'] === 'dvmproject') {
        $txMode = ['dmr' => 1, 'p25' => 2, 'analog' => 3];
        $lines[] = '# Merge into your dvmbridge configuration (bridge-config.yml).';
        $lines[] = '# Generated by TicketsCAD for the channel "' . preg_replace('/[\r\n]+/', ' ', $row['label']) . '".';
        $lines[] = '# Compare with the example file shipped with YOUR dvmbridge version before use.';
        $lines[] = 'network:';
        $lines[] = '  id: ' . (!empty($c['fne']['peer_id']) ? (int) $c['fne']['peer_id'] : '<YOUR-FNE-PEER-ID>');
        if (($c['talkgroup'] ?? '') !== '') {
            $lines[] = '  destinationId: ' . (int) $c['talkgroup'];
        } else {
            $lines[] = '  destinationId: <YOUR-TALKGROUP-ID>';
        }
        $lines[] = '  password: "<FNE-PASSWORD>"   # TicketsCAD never has, shows or stores this';
        $lines[] = 'system:';
        if (isset($txMode[$mode])) {
            $lines[] = '  txMode: ' . $txMode[$mode] . '   # 1 = DMR, 2 = P25, 3 = Analog';
        } else {
            $lines[] = '  txMode: <1 DMR | 2 P25 | 3 Analog>';
        }
        $lines[] = '  localAudio: false';
        $lines[] = '  udpAudio: true';
        $lines[] = '  udpUsrp: true';
        $lines[] = '  udpRTPFrames: false';
        $lines[] = '  udpSendAddress: "' . $c['listen_host'] . '"';
        $lines[] = '  udpSendPort: ' . (int) $c['listen_port'] . '   # the bridge sends audio HERE (TicketsCAD listens)';
        $lines[] = '  udpReceiveAddress: "' . $c['bridge_host'] . '"';
        $lines[] = '  udpReceivePort: ' . (int) $c['bridge_tx_port'] . '   # the bridge listens HERE (unused: this release does not transmit)';
    } else {
        $lines[] = '# Point your USRP-speaking bridge program (DVSwitch Analog_Bridge, AllStar chan_usrp, ...) at TicketsCAD:';
        $lines[] = '#   send USRP audio to        ' . $c['listen_host'] . ' UDP port ' . (int) $c['listen_port'];
        $lines[] = '#   listen for USRP audio on  ' . $c['bridge_host'] . ' UDP port ' . (int) $c['bridge_tx_port'] . '   (unused: this release does not transmit)';
        $lines[] = '# Audio format: 8 kHz, signed 16-bit little-endian PCM, 160 samples (320 bytes) per 20 ms frame.';
        $lines[] = '# TicketsCAD accepts datagrams only from ' . $c['bridge_host'] . '.';
    }
    return implode("\n", $lines) . "\n";
}

// ── Status probe (console strip LED) ────────────────────────────────────

/**
 * comm_channel_state fields for one digital-voice channel, for
 * channel_registry_probe(). Honest by construction: the only way to read
 * 'connected' is the FNE's own REST answer. Without REST configured (or
 * without this channel's FNE peer id) the answer is 'unknown' — never
 * 'connected': the audio path being quiet proves nothing about the link.
 *
 * @return array fields for channel_state_set()
 */
function vbc_probe_fields(array $ch) {
    $f = [];
    if ((int) ($ch['enabled'] ?? 0) !== 1) {
        return $f;
    }
    require_once __DIR__ . '/dvm-fne-rest.php';
    $peer = (int) ($ch['config']['fne']['peer_id'] ?? 0);
    if ($ch['adapter'] !== 'dvmproject' || $peer <= 0 || !dvm_fne_rest_configured()) {
        $f['state'] = 'unknown';
        return $f;
    }
    $res = dvm_fne_peer_state_cached($peer);
    $f['state'] = $res['state'];
    if ($res['state'] === 'unknown') {
        // The raw reason of an unreachable REST endpoint names its address and
        // port (a curl error); last_error reaches EVERY console operator via
        // api/channels.php, who must not learn the FNE's address. Admins get
        // the detail from the Digital Voice Bridges page ("Check now").
        $f['last_error'] = 'FNE link status unavailable (an administrator can see why under Settings > Digital Voice Bridges)';
    } elseif (!empty($res['reason']) && $res['state'] !== 'connected') {
        // down / degraded reasons describe the peer, never an address.
        $f['last_error'] = 'FNE: ' . $res['reason'];
    }
    return $f;
}

}
