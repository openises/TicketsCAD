<?php
/**
 * Phase 153 (2026-09-08) — mock AllStar relay trigger.
 *
 * "Transmit a message on an AllStar channel configured in a different
 * instance" (Eric's own framing) -- simulates paging/calling a responder
 * over ham radio by relaying a short spoken incident summary to the mock
 * AllStar node built for this phase (VM 966, allstar-mock,
 * 10.0.0.10), which genuinely Answers/Plays/Records it (see that
 * node's own `[internal]` dialplan, extension 200 -- built by a dedicated
 * infrastructure agent and independently verified there with real SIP
 * audio, spectral analysis, and byte-for-byte waveform matching; this
 * file's job is only to drive that mechanism correctly from an incident,
 * not to re-prove the audio path itself).
 *
 * SYNTHESIS: prefers this project's own TTS engine registry
 * (tts_synthesize('sip_callout', ...), inc/tts/engine.php) -- the SAME
 * path Weather Alerts/Radio AI/Zello read-outs use, so a real install
 * with Piper (or another engine) configured gets a real voice. Falls
 * back to running `espeak-ng` ON THE RELAY NODE ITSELF (already
 * installed there for exactly this purpose) when no engine is
 * configured -- documented, not hidden, and it's how this feature was
 * verified end-to-end on a dev machine with no TTS engine installed.
 *
 * SECURITY: the incident-derived message TEXT never appears in an SSH/
 * shell command line, on this host OR the remote one -- it crosses only
 * as FILE CONTENT (via scp), and every remote command run over ssh uses
 * ONLY system-generated, hex-charset job ids as its dynamic pieces. This
 * matters because ssh's own remote-execution model concatenates argv
 * into a string a REMOTE shell parses (unlike this project's local
 * proc_open calls, which use an argv array specifically to avoid any
 * shell -- ssh has no such passthrough, so untrusted text in an ssh
 * command line would be a real remote-injection risk).
 */

/**
 * Phase 155 (GH#108 S5, "AllStar honesty") -- what this feature IS and ISN'T.
 *
 * This is a SIMULATED relay: it sends a spoken incident summary, over SSH and
 * the Asterisk Manager Interface, to a plain Asterisk test server and measures
 * the recording that comes back. It does not speak AllStarLink, key a radio,
 * or touch a repeater, and no AllStar node exists in this system (real
 * AllStarLink voice integration is a separate, unbuilt piece of work). Until
 * Phase 155 the incident button said "AllStar Relay" on EVERY install, the
 * connection defaults pointed at the maintainer's own lab, the settings had no
 * screen, and a click froze the operator's whole session for 20-30 seconds.
 * Now it is OFF unless an administrator turns it on, has no lab defaults, is
 * configured on a Super-Admin-only page that says what it is, is labelled
 * "Relay test page", and releases the session lock before it waits.
 */
/**
 * An error whose message is SAFE to show to the person who clicked the button
 * (nothing about the relay node's address, its SSH output or its Manager
 * Interface replies). Every other RuntimeException from this file carries
 * infrastructure detail (ssh stderr, AMI replies, host names): the endpoint
 * logs those in full and shows the caller a generic sentence instead, because
 * the person clicking is a dispatcher, not the administrator who set the node up.
 */
if (!class_exists('AllstarRelayUserError')) {
    class AllstarRelayUserError extends RuntimeException {}
}

if (!function_exists('allstar_relay_settings')) {

/**
 * Is the relay test feature switched on? OFF unless the stored value is
 * exactly '1' (a fresh install has no row). The incident button is not
 * rendered, and the endpoint's trigger answers 404, while this is false.
 */
function allstar_relay_enabled(bool $fresh = false): bool {
    return _allstar_relay_get('allstar_relay_enabled', $fresh) === '1';
}

/**
 * Read one setting. get_variable() caches the whole settings table for the
 * life of the process, so a request that has just WRITTEN a setting and wants
 * to report it ($fresh) must read the row itself.
 */
function _allstar_relay_get(string $name, bool $fresh = false): string {
    if ($fresh) {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        return $v === false || $v === null ? '' : (string) $v;
    }
    $v = get_variable($name);
    return $v === false ? '' : (string) $v;
}

function allstar_relay_save_enabled(bool $enabled): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('allstar_relay_enabled', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$enabled ? '1' : '0']
    );
    return $enabled;
}

/**
 * Connection settings. There are NO built-in defaults for the host, the SSH
 * alias or the AMI user: the old defaults (ssh alias "allstar-mock", AMI host
 * 10.0.0.10, user "ticketscad-relay") were the maintainer's lab, so on any
 * other install the relay "worked" only on a machine with that exact ssh
 * configuration. The dialplan extension and the Asterisk directory paths keep
 * their defaults: those are the conventions of the documented test-node setup.
 */
function allstar_relay_settings(bool $fresh = false): array {
    $g = static function (string $key, string $default) use ($fresh): string {
        $v = _allstar_relay_get('allstar_relay_' . $key, $fresh);
        return $v !== '' ? $v : $default;
    };
    return [
        'ssh_alias'             => $g('ssh_alias', ''),
        'ami_host'              => $g('ami_host', ''),
        'ami_port'              => (int)    $g('ami_port', '5038'),
        'ami_user'              => $g('ami_user', ''),
        'ami_secret'            => $g('ami_secret', ''),
        'extension'             => $g('extension', '200'),
        // Asterisk's Playback() only reliably resolves files that live
        // under its own configured sounds directory -- a file dropped in
        // an arbitrary absolute path (e.g. /tmp) was found live-tested to
        // silently fail ("does not exist in any format"), even though
        // the exact same bytes readable-by-asterisk play fine from here.
        // The SSH account can't write here directly (owned by the
        // `asterisk` system user) -- delivery goes through a writable
        // staging directory first, then a `sudo mv` into place.
        'remote_audio_dir'      => $g('remote_audio_dir', '/var/lib/asterisk/sounds/relay'),
        'remote_staging_dir'    => $g('remote_staging_dir', '/tmp/ticketscad-relay'),
        'remote_recordings_dir' => $g('remote_recordings_dir', '/var/spool/asterisk/allstar-mock-recordings'),
    ];
}

/**
 * Validate one connection setting. Returns the cleaned value or throws
 * InvalidArgumentException naming the field. These values are not decoration:
 *   - ssh_alias is the first non-option argument to `ssh`/`scp`; one starting
 *     with a dash would be parsed as an OPTION (e.g. ProxyCommand), i.e. a
 *     command run on this web server;
 *   - the three directories are interpolated into command lines that a REMOTE
 *     shell parses, so they must be plain absolute paths;
 *   - ami_user / ami_secret / extension go into AMI header lines, so a CR or LF
 *     in any of them would inject extra AMI headers.
 * Only an administrator can set them, but a setting that becomes a command
 * line deserves a whitelist anyway.
 */
function allstar_relay_validate_setting(string $key, $raw): string {
    $v = trim((string) $raw);
    $bad = static function (string $why): InvalidArgumentException { return new InvalidArgumentException($why); };
    switch ($key) {
        case 'ssh_alias':
            if ($v !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{0,99}\z/', $v)) {
                throw $bad('SSH alias may contain only letters, digits and . _ @ - and must not start with a dash.');
            }
            return $v;
        case 'ami_host':
            if ($v !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}\z/', $v) && !filter_var($v, FILTER_VALIDATE_IP)) {
                throw $bad('AMI host must be a host name or an IP address (no spaces, no scheme, no port).');
            }
            return $v;
        case 'ami_port':
            if (!preg_match('/^[0-9]{1,5}\z/', $v) || (int) $v < 1 || (int) $v > 65535) {
                throw $bad('AMI port must be a number from 1 to 65535.');
            }
            return (string) (int) $v;
        case 'ami_user':
            if ($v !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{0,63}\z/', $v)) {
                throw $bad('AMI user may contain only letters, digits and . _ @ -');
            }
            return $v;
        case 'ami_secret':
            if (strlen($v) > 128 || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                throw $bad('AMI secret must be at most 128 characters with no control characters.');
            }
            return $v;
        case 'extension':
            if (!preg_match('/^[A-Za-z0-9_]{1,32}\z/', $v)) {
                throw $bad('Extension may contain only letters, digits and underscores.');
            }
            return $v;
        case 'remote_audio_dir':
        case 'remote_staging_dir':
        case 'remote_recordings_dir':
            if (!preg_match('#^/[A-Za-z0-9._/-]{1,200}\z#', $v) || strpos($v, '..') !== false) {
                throw $bad('Directories must be plain absolute paths (letters, digits and . _ / - only, no "..").');
            }
            return rtrim($v, '/') === '' ? '/' : rtrim($v, '/');
    }
    throw $bad('Unknown setting.');
}

/**
 * Save connection settings. Every supplied value is validated BEFORE any is
 * written (a refused save changes nothing). A blank or placeholder secret
 * means "keep the stored one" (the secret is never sent to the browser, so a
 * blank can only mean "I did not retype it"). Returns the names of the keys
 * that actually changed -- never their values.
 *
 * @return string[]
 */
function allstar_relay_settings_save(array $in): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    require_once __DIR__ . '/settings-secrets.php';
    $keys = ['ssh_alias', 'ami_host', 'ami_port', 'ami_user', 'ami_secret', 'extension', 'remote_audio_dir', 'remote_staging_dir', 'remote_recordings_dir'];
    $clean = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $in)) { continue; }
        if ($k === 'ami_secret' && is_masked_secret_value($in[$k])) { continue; }
        $clean[$k] = allstar_relay_validate_setting($k, $in[$k]);
    }
    $before = allstar_relay_settings(true);
    $changed = [];
    foreach ($clean as $k => $val) {
        // Always stored (an explicit save of a default is a real choice), but
        // only a different EFFECTIVE value is reported as a change.
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            ['allstar_relay_' . $k, $val]
        );
        if ((string) $before[$k] !== (string) $val) { $changed[] = $k; }
    }
    return $changed;
}

/** Names of the connection settings that are still empty and required. */
function allstar_relay_missing_settings(array $s): array {
    $missing = [];
    foreach (['ssh_alias' => 'SSH alias', 'ami_host' => 'AMI host', 'ami_user' => 'AMI user', 'ami_secret' => 'AMI secret'] as $k => $label) {
        if ((string) ($s[$k] ?? '') === '') { $missing[] = $label; }
    }
    return $missing;
}

/**
 * "Test connection": log in to the Asterisk Manager Interface and log out.
 * That is ALL -- no Originate, no audio, nothing played or recorded. Reports
 * where it stopped in words an administrator can act on. Never returns the
 * server's own response text (it can name accounts) or the secret.
 *
 * @return array{ok:bool, detail:string}
 */
function allstar_relay_test_connection(array $settings): array {
    $missing = allstar_relay_missing_settings($settings);
    // ssh_alias is not needed to LOG IN to AMI; only the AMI trio is.
    $needAmi = array_values(array_intersect($missing, ['AMI host', 'AMI user', 'AMI secret']));
    if ($needAmi) {
        return ['ok' => false, 'detail' => 'Not configured yet: ' . implode(', ', $needAmi) . '. Save the settings first.'];
    }
    $errno = 0; $errstr = '';
    $sock = @fsockopen($settings['ami_host'], (int) $settings['ami_port'], $errno, $errstr, 6);
    if (!$sock) {
        return ['ok' => false, 'detail' => 'Cannot connect to ' . $settings['ami_host'] . ':' . (int) $settings['ami_port']
            . ' (' . ($errstr !== '' ? $errstr : 'no answer') . '). Check the host, the port and the firewall.'];
    }
    stream_set_timeout($sock, 6);
    $banner = (string) fgets($sock);
    if (stripos($banner, 'Asterisk Call Manager') === false) {
        fclose($sock);
        return ['ok' => false, 'detail' => 'Something answered, but it is not an Asterisk Manager Interface. Check the port (the default is 5038).'];
    }
    fwrite($sock, "Action: Login\r\nUsername: {$settings['ami_user']}\r\nSecret: {$settings['ami_secret']}\r\n\r\n");
    $resp = _allstar_relay_read_ami_block($sock);
    $ok = strpos($resp, 'Response: Success') !== false;
    if ($ok) { fwrite($sock, "Action: Logoff\r\n\r\n"); }
    fclose($sock);
    return $ok
        ? ['ok' => true, 'detail' => 'Connected and logged in to the Asterisk Manager Interface. Nothing was sent or played.']
        : ['ok' => false, 'detail' => 'The Manager Interface answered but refused the login. Check the AMI user and secret (and that the user may connect from this server).'];
}

/** A short, radio-appropriate spoken summary of an incident. */
function allstar_relay_build_message(int $ticketId): string {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $row = db_fetch_one(
        "SELECT t.incident_number, t.scope, t.street, t.city, t.state, t.description,
                it.type AS type_name
           FROM `{$prefix}ticket` t
           LEFT JOIN `{$prefix}in_types` it ON it.id = t.in_types_id
          WHERE t.id = ? AND (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')",
        [$ticketId]
    );
    if (!$row) {
        throw new AllstarRelayUserError('Incident not found.');
    }
    $parts = [];
    $parts[] = 'Dispatch relay.';
    $caseLabel = $row['incident_number'] !== '' && $row['incident_number'] !== null
        ? 'Incident ' . $row['incident_number']
        : 'Incident ' . $ticketId;
    $typeLabel = trim((string) ($row['type_name'] ?? ''));
    $parts[] = $typeLabel !== '' ? $caseLabel . ', ' . $typeLabel . '.' : $caseLabel . '.';

    $location = trim(implode(', ', array_filter([$row['street'] ?? '', $row['city'] ?? '', $row['state'] ?? ''])));
    if ($location !== '') { $parts[] = 'Location: ' . $location . '.'; }

    $scope = trim((string) ($row['scope'] ?? ''));
    if ($scope !== '') { $parts[] = $scope . '.'; }

    $parts[] = 'Please respond and advise.';
    return implode(' ', $parts);
}

/** Run a FIXED remote command (no caller-influenced text in the command
 *  line -- only system-generated hex job ids) over ssh, using the user's
 *  own ~/.ssh/config alias for host/user/key resolution. Local proc_open
 *  call is argv-form (no local shell); the remote side is inherently
 *  ssh's own single-string-to-remote-shell model, which is why every
 *  value placed there is validated to be job-id-shaped before use. */
/** Runs a child process with its stdout/stderr staged to TEMP FILES,
 *  never pipes -- this project's own established fix (inc/tts/engine.php's
 *  tts_run_pipe()) for a real, documented Windows bug: stream_set_blocking()
 *  is a no-op on a proc_open pipe on Windows, so a child that fills its
 *  pipe buffer while this process is doing anything else (including a
 *  blocking read of the OTHER pipe) deadlocks both sides permanently.
 *  Temp files can't deadlock in either direction. */
function _allstar_relay_run(array $argv, int $timeoutSec): array {
    $tag  = 'allstarrelay_' . getmypid() . '_' . bin2hex(random_bytes(6));
    $dir  = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR;
    $fOut = $dir . $tag . '.out';
    $fErr = $dir . $tag . '.err';
    $cleanup = static function () use ($fOut, $fErr) {
        foreach ([$fOut, $fErr] as $f) { if (@is_file($f)) @unlink($f); }
    };

    $descriptors = [1 => ['file', $fOut, 'w'], 2 => ['file', $fErr, 'w']];
    $proc = @proc_open($argv, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        $cleanup();
        return ['ok' => false, 'stdout' => '', 'stderr' => 'failed to start ' . ($argv[0] ?? '?'), 'exit_code' => -1];
    }

    $timedOut = false;
    $deadline = microtime(true) + $timeoutSec;
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) { break; }
        if (microtime(true) > $deadline) { $timedOut = true; proc_terminate($proc, 9); break; }
        usleep(50000);
    }
    $exitCode = proc_close($proc);

    $stdout = (string) @file_get_contents($fOut);
    $stderr = (string) @file_get_contents($fErr);
    $cleanup();

    if ($timedOut) {
        return ['ok' => false, 'stdout' => $stdout, 'stderr' => trim($stderr) . ' (timed out)', 'exit_code' => -1];
    }
    return ['ok' => $exitCode === 0, 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
}

/** Runs a FIXED remote command (no caller-influenced text in the command
 *  line -- only system-generated hex job ids) over ssh, using the user's
 *  own ~/.ssh/config alias for host/user/key resolution. Local proc_open
 *  call is argv-form (no local shell); the remote side is inherently
 *  ssh's own single-string-to-remote-shell model, which is why every
 *  value placed there is validated to be job-id-shaped before use. */
function _allstar_relay_ssh(string $sshAlias, string $remoteCommand, int $timeoutSec = 25): array {
    return _allstar_relay_run(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $sshAlias, $remoteCommand], $timeoutSec);
}

function _allstar_relay_scp_to(string $sshAlias, string $localPath, string $remotePath, int $timeoutSec = 20): bool {
    $target = $sshAlias . ':' . $remotePath; // scp's own combined host:path syntax -- one argv element
    $r = _allstar_relay_run(['scp', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $localPath, $target], $timeoutSec);
    return $r['ok'];
}

function _allstar_relay_scp_from(string $sshAlias, string $remotePath, string $localPath, int $timeoutSec = 20): bool {
    $source = $sshAlias . ':' . $remotePath; // scp's own combined host:path syntax -- one argv element
    $r = _allstar_relay_run(['scp', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $source, $localPath], $timeoutSec);
    return $r['ok'];
}

/**
 * Produce an 8kHz mono 16-bit WAV of $text. Tries the app's own TTS
 * engine registry first (the SAME path Weather Alerts/Radio AI/Zello
 * use); falls back to espeak-ng ON THE RELAY NODE (never locally --
 * this app server may be Windows and have no TTS binary at all) when no
 * engine is configured. Returns a LOCAL file path on success.
 */
function allstar_relay_synthesize(string $text, array $settings): string {
    if (function_exists('tts_synthesize')) {
        $r = tts_synthesize('sip_callout', $text);
        if (!empty($r['ok']) && $r['pcm'] !== '') {
            $wav = tts_pcm_to_wav($r['pcm'], (int) $r['rate']);
            $tmp = tempnam(sys_get_temp_dir(), 'allstar_relay_') . '.wav';
            file_put_contents($tmp, $wav);
            return $tmp;
        }
    }

    // Fallback: synthesize remotely on the relay node itself via espeak-ng
    // (already installed there) + sox (resample to telephony rate).
    $jobId = bin2hex(random_bytes(8));
    $staging = $settings['remote_staging_dir'];
    $localTxt = tempnam(sys_get_temp_dir(), 'allstar_relay_txt_');
    file_put_contents($localTxt, $text);

    $mk = _allstar_relay_ssh($settings['ssh_alias'], 'mkdir -p ' . $staging);
    if (!$mk['ok']) {
        @unlink($localTxt);
        throw new RuntimeException('Could not reach the relay node over SSH: ' . trim($mk['stderr']));
    }
    if (!_allstar_relay_scp_to($settings['ssh_alias'], $localTxt, $staging . '/' . $jobId . '.txt')) {
        @unlink($localTxt);
        throw new RuntimeException('Failed to deliver the message text to the relay node.');
    }
    @unlink($localTxt);

    $synth = _allstar_relay_ssh(
        $settings['ssh_alias'],
        'espeak-ng -v en-us -s 150 -w ' . $staging . '/' . $jobId . '_raw.wav < ' . $staging . '/' . $jobId . '.txt'
        . ' && sox ' . $staging . '/' . $jobId . '_raw.wav -r 8000 -c 1 -b 16 ' . $staging . '/' . $jobId . '.wav'
    );
    if (!$synth['ok']) {
        throw new RuntimeException('Remote speech synthesis (espeak-ng fallback) failed: ' . trim($synth['stderr']));
    }

    $localWav = tempnam(sys_get_temp_dir(), 'allstar_relay_wav_') . '.wav';
    if (!_allstar_relay_scp_from($settings['ssh_alias'], $staging . '/' . $jobId . '.wav', $localWav)) {
        throw new RuntimeException('Synthesized, but could not fetch a local copy back for inspection.');
    }
    return $localWav;
}

/**
 * Deliver a LOCAL wav file to the relay node's Asterisk sounds
 * directory, where Playback() reliably finds it (a file placed at an
 * arbitrary absolute path, e.g. under /tmp, was live-tested to fail --
 * "does not exist in any format" -- even though the exact same bytes
 * are readable by the asterisk user; Asterisk's file-open only resolves
 * against its own configured media search paths). The relay node's SSH
 * account can't write directly into that asterisk-owned directory, so
 * this stages the file in a writable directory first, then `sudo mv`s
 * it into place -- both remote command lines use ONLY system-generated
 * hex job ids, never caller-influenced text. Returns the bare path
 * (no extension) Playback()/SRCFILE expects.
 */
function allstar_relay_deliver(string $localWavPath, array $settings): string {
    $jobId = bin2hex(random_bytes(8));
    $staging = $settings['remote_staging_dir'];
    $mk = _allstar_relay_ssh($settings['ssh_alias'], 'mkdir -p ' . $staging . ' ' . $settings['remote_audio_dir']);
    if (!$mk['ok']) {
        throw new RuntimeException('Could not reach the relay node over SSH: ' . trim($mk['stderr']));
    }
    if (!_allstar_relay_scp_to($settings['ssh_alias'], $localWavPath, $staging . '/' . $jobId . '.wav')) {
        throw new RuntimeException('Failed to deliver the synthesized message to the relay node.');
    }
    $move = _allstar_relay_ssh($settings['ssh_alias'],
        'sudo mv ' . $staging . '/' . $jobId . '.wav ' . $settings['remote_audio_dir'] . '/' . $jobId . '.wav'
        . ' && sudo chown asterisk:asterisk ' . $settings['remote_audio_dir'] . '/' . $jobId . '.wav');
    if (!$move['ok']) {
        throw new RuntimeException('Delivered the message but could not place it where Asterisk can play it: ' . trim($move['stderr']));
    }
    return rtrim($settings['remote_audio_dir'], '/') . '/' . $jobId;
}

/** Minimal WAV PCM stats (duration, RMS-ish energy) without any external
 *  binary -- this app server may not have sox/soxi (e.g. Windows). Reads
 *  the 'data' chunk of a canonical 16-bit PCM WAV. */
function allstar_relay_wav_stats(string $wavPath): array {
    $bytes = file_get_contents($wavPath);
    if ($bytes === false || strlen($bytes) < 44) {
        return ['ok' => false, 'duration_sec' => 0.0, 'rms' => 0.0, 'sample_count' => 0];
    }
    $sampleRate = unpack('V', substr($bytes, 24, 4))[1] ?? 8000;
    $bitsPerSample = unpack('v', substr($bytes, 34, 2))[1] ?? 16;
    // Find the 'data' subchunk (it's not always at a fixed offset).
    $pos = 12;
    $dataOffset = null; $dataSize = 0;
    while ($pos + 8 <= strlen($bytes)) {
        $chunkId = substr($bytes, $pos, 4);
        $chunkSize = unpack('V', substr($bytes, $pos + 4, 4))[1] ?? 0;
        if ($chunkId === 'data') { $dataOffset = $pos + 8; $dataSize = $chunkSize; break; }
        $pos += 8 + $chunkSize + ($chunkSize % 2);
    }
    if ($dataOffset === null) {
        return ['ok' => false, 'duration_sec' => 0.0, 'rms' => 0.0, 'sample_count' => 0];
    }
    $pcm = substr($bytes, $dataOffset, $dataSize);
    $bytesPerSample = max(1, (int) ($bitsPerSample / 8));
    $sampleCount = intdiv(strlen($pcm), $bytesPerSample);
    $sumSquares = 0.0;
    if ($bitsPerSample === 16 && $sampleCount > 0) {
        $samples = unpack('v*', $pcm);
        $n = 0;
        foreach ($samples as $u) {
            $s = $u >= 32768 ? $u - 65536 : $u;
            $sumSquares += ($s / 32768.0) ** 2;
            $n++;
        }
        $rms = $n > 0 ? sqrt($sumSquares / $n) : 0.0;
    } else {
        $rms = 0.0;
    }
    return [
        'ok' => true,
        'duration_sec' => $sampleRate > 0 ? round($sampleCount / $sampleRate, 2) : 0.0,
        'rms' => round($rms, 5),
        'sample_count' => $sampleCount,
    ];
}

/**
 * The full relay: synthesize -> deliver -> AMI Originate -> wait ->
 * fetch the recording back -> verify it is genuinely non-silent audio.
 * Returns the verification detail; throws on any hard failure.
 */
function allstar_relay_trigger(int $ticketId, ?string $messageOverride = null): array {
    $settings = allstar_relay_settings();
    $missing = allstar_relay_missing_settings($settings);
    if ($missing) {
        throw new AllstarRelayUserError('The relay test node is not configured yet (missing: ' . implode(', ', $missing)
            . '). An administrator sets it up under Settings, Communications and Integrations, AllStar Relay (test).');
    }
    if ($messageOverride !== null) {
        // The text is spoken; keep it short and printable (it also travels as FILE content, never in a command line).
        $messageOverride = substr((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $messageOverride), 0, 500);
    }

    $text = $messageOverride !== null && trim($messageOverride) !== '' ? trim($messageOverride) : allstar_relay_build_message($ticketId);

    $localWav = allstar_relay_synthesize($text, $settings);
    $expectedDurationSec = allstar_relay_wav_stats($localWav)['duration_sec'];
    try {
        $remotePath = allstar_relay_deliver($localWav, $settings);
    } finally {
        @unlink($localWav);
    }

    // A marker file, timestamped an instant before Originate, makes
    // finding "the recording THIS call produced" unambiguous -- `ls -t`
    // alone was live-tested and found unreliable here (back-to-back
    // relay calls can land on the same whole-second mtime, and its
    // ordering among same-mtime files flips from poll to poll).
    $markerJobId = bin2hex(random_bytes(6));
    $marker = rtrim($settings['remote_staging_dir'], '/') . '/marker-' . $markerJobId;
    _allstar_relay_ssh($settings['ssh_alias'], 'mkdir -p ' . $settings['remote_staging_dir'] . ' && touch ' . $marker);

    $originate = allstar_relay_ami_originate($settings, $remotePath);
    if (!$originate['ok']) {
        _allstar_relay_ssh($settings['ssh_alias'], 'rm -f ' . $marker);
        throw new RuntimeException('AMI Originate to the relay node failed: ' . $originate['detail']);
    }

    // Wait for AT LEAST the message's own known duration before looking
    // for the finished recording, rather than polling for the file size
    // to go "stable" -- MixMonitor's write buffering was live-tested and
    // found to plateau for a full second or more mid-recording (a real,
    // still-growing file can read the SAME size on two consecutive
    // 1-second polls), which repeatedly fooled a stability check into
    // declaring a still-in-progress recording finished early.
    sleep((int) ceil($expectedDurationSec) + 4);

    $find = _allstar_relay_ssh($settings['ssh_alias'],
        'find ' . $settings['remote_recordings_dir'] . ' -maxdepth 1 -type f -newer ' . $marker
        . ' -printf "%T@ %f\\n" 2>/dev/null | sort -rn | head -1 | cut -d" " -f2-');
    $recordingName = trim($find['stdout']);
    _allstar_relay_ssh($settings['ssh_alias'], 'rm -f ' . $marker);
    if ($recordingName === '') {
        throw new RuntimeException('Originate succeeded but no recording appeared on the relay node.');
    }

    $localRecording = tempnam(sys_get_temp_dir(), 'allstar_relay_rec_') . '.wav';
    $fetched = _allstar_relay_scp_from($settings['ssh_alias'],
        rtrim($settings['remote_recordings_dir'], '/') . '/' . $recordingName, $localRecording);
    if (!$fetched) {
        throw new RuntimeException('Recording was made but could not be fetched back for verification.');
    }

    $stats = allstar_relay_wav_stats($localRecording);
    @unlink($localRecording);

    return [
        'ok' => $stats['ok'] && $stats['rms'] > 0.005 && $stats['duration_sec'] > 0.5,
        'message' => $text,
        'recording_name' => $recordingName,
        'duration_sec' => $stats['duration_sec'],
        'rms' => $stats['rms'],
        'sample_count' => $stats['sample_count'],
    ];
}

/** Raw AMI Originate over a TCP socket -- no PHP AMI client library in
 *  this codebase (deliberately kept minimal, matching services/sip-
 *  bridge's own stdlib-first convention for adapters). */
function allstar_relay_ami_originate(array $settings, string $srcFilePath): array {
    $sock = @fsockopen($settings['ami_host'], $settings['ami_port'], $errno, $errstr, 8);
    if (!$sock) {
        return ['ok' => false, 'detail' => "cannot connect to AMI ($errstr)"];
    }
    stream_set_timeout($sock, 8);
    fgets($sock); // banner

    fwrite($sock, "Action: Login\r\nUsername: {$settings['ami_user']}\r\nSecret: {$settings['ami_secret']}\r\n\r\n");
    $loginResp = _allstar_relay_read_ami_block($sock);
    if (strpos($loginResp, 'Response: Success') === false) {
        fclose($sock);
        return ['ok' => false, 'detail' => 'AMI login failed'];
    }

    fwrite($sock, "Action: Originate\r\n"
        . "Channel: Local/start@internal\r\n"
        . "Context: internal\r\n"
        . "Exten: {$settings['extension']}\r\n"
        . "Priority: 1\r\n"
        . "Timeout: 15000\r\n"
        . "Async: true\r\n"
        . "Variable: SRCFILE={$srcFilePath}\r\n"
        . "\r\n");
    $originateResp = _allstar_relay_read_ami_block($sock);
    fclose($sock);

    if (strpos($originateResp, 'Response: Success') === false) {
        return ['ok' => false, 'detail' => 'AMI Originate rejected: ' . trim($originateResp)];
    }
    return ['ok' => true, 'detail' => 'ok'];
}

/** Reads AMI blocks (each ending in a blank line) until one is a direct
 *  ACTION RESPONSE ("Response:" header), skipping any unsolicited Event
 *  blocks Asterisk pushes in between (e.g. a FullyBooted event fired
 *  right after Login, which would otherwise be mistaken for the
 *  response to whatever action is sent next). */
function _allstar_relay_read_ami_block($sock): string {
    $deadline = microtime(true) + 8;
    while (microtime(true) < $deadline) {
        $block = '';
        while (microtime(true) < $deadline) {
            $line = fgets($sock, 4096);
            if ($line === false) { break 2; }
            $block .= $line;
            if (trim($line) === '' && $block !== '') { break; }
        }
        if (stripos($block, 'Response:') !== false) {
            return $block;
        }
        // Pure Event block (e.g. FullyBooted) -- discard and keep reading.
    }
    return '';
}

}
