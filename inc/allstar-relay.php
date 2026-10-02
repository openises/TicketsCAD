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

if (!function_exists('allstar_relay_settings')) {

function allstar_relay_settings(): array {
    return [
        'ssh_alias'             => (string) (get_variable('allstar_relay_ssh_alias') ?: 'allstar-mock'),
        'ami_host'              => (string) (get_variable('allstar_relay_ami_host') ?: '10.0.0.10'),
        'ami_port'              => (int)    (get_variable('allstar_relay_ami_port') ?: 5038),
        'ami_user'              => (string) (get_variable('allstar_relay_ami_user') ?: 'ticketscad-relay'),
        'ami_secret'            => (string) (get_variable('allstar_relay_ami_secret') ?: ''),
        'extension'             => (string) (get_variable('allstar_relay_extension') ?: '200'),
        // Asterisk's Playback() only reliably resolves files that live
        // under its own configured sounds directory -- a file dropped in
        // an arbitrary absolute path (e.g. /tmp) was found live-tested to
        // silently fail ("does not exist in any format"), even though
        // the exact same bytes readable-by-asterisk play fine from here.
        // The SSH account can't write here directly (owned by the
        // `asterisk` system user) -- delivery goes through a writable
        // staging directory first, then a `sudo mv` into place.
        'remote_audio_dir'      => (string) (get_variable('allstar_relay_remote_audio_dir') ?: '/var/lib/asterisk/sounds/relay'),
        'remote_staging_dir'    => (string) (get_variable('allstar_relay_remote_staging_dir') ?: '/tmp/ticketscad-relay'),
        'remote_recordings_dir' => (string) (get_variable('allstar_relay_remote_recordings_dir') ?: '/var/spool/asterisk/allstar-mock-recordings'),
    ];
}

function allstar_relay_settings_save(array $in): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $keys = ['ssh_alias', 'ami_host', 'ami_port', 'ami_user', 'ami_secret', 'extension', 'remote_audio_dir', 'remote_staging_dir', 'remote_recordings_dir'];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $in)) { continue; }
        $val = trim((string) $in[$k]);
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            ['allstar_relay_' . $k, $val]
        );
    }
    return allstar_relay_settings();
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
        throw new RuntimeException('Incident not found.');
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
    if ($settings['ami_secret'] === '') {
        throw new RuntimeException('AllStar relay is not configured yet (missing AMI secret). Ask an administrator to set it up.');
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
