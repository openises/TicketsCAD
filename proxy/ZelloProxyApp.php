<?php
/**
 * NewUI v4.0 - Zello WebSocket Proxy Application
 *
 * Ratchet MessageComponentInterface that handles browser WebSocket clients.
 * Authenticates clients via PHP session, relays messages between browsers
 * and the ZelloUpstream connection, and logs messages to the database.
 */

namespace NewUI\Proxy;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;

class ZelloProxyApp implements MessageComponentInterface
{
    /** @var \SplObjectStorage Browser client connections */
    private $clients;

    /** @var LoopInterface */
    private $loop;

    /** @var array Zello settings from DB */
    private $config;

    /** @var ZelloUpstream|null */
    private $upstream = null;

    /** @var \PDO Database connection for message logging */
    private $pdo;

    /** @var string DSN for reconnecting */
    private $dsn;

    /** @var string DB user for reconnecting */
    private $dbUser;

    /** @var string DB password for reconnecting */
    private $dbPass;

    /** @var string DB table prefix */
    private $prefix;

    /** @var array Map of connection resource IDs to user info */
    private $clientAuth = [];

    /** @var array Active incoming audio streams: stream_id => ['from'=>, 'channel'=>, 'packets'=>[], 'started'=>time(), 'chunk_buffer'=>[]] */
    private $activeStreams = [];

    /** @var int Number of audio packets to buffer before sending a streaming chunk (10 × 60ms = 600ms) */
    private $streamChunkSize = 10;

    /** @var string Directory for cached audio files */
    private $audioDir;

    /** @var string Phase 100 — directory for cached image files */
    private $imageDir;

    /**
     * Phase 101 (Eric beta 2026-07-01) — persistent set of image
     * SHA-256 hashes we've already delivered to widgets. Zello Work
     * re-sends undelivered images on every reconnect (there's no
     * client-side ACK we can send back). Without dedup, the same
     * "sticky" image reappears every time the proxy restarts.
     *
     * Storage: /cache/zello-images/.dedup — one hex-sha256 per line,
     * newest at end. On boot we read into $this->imageDedupSeen
     * (associative array for O(1) lookup); on new finalize we append
     * to both. Capped at $imageDedupMaxEntries to prevent unbounded
     * growth; oldest entries get trimmed when we cross the cap.
     *
     * Deleting an image from disk / DB does NOT unblock its hash —
     * that's a feature, not a bug. If a user wants to "remove and
     * stop it reappearing" they delete the local file + the DB row;
     * the hash stays in the dedup log so Zello's next redelivery is
     * dropped silently.
     *
     * Why we can't do this "the right way" (2026-07-01 research
     * agent verified against github.com/zelloptt/zello-channel-api
     * API.md + JS SDK session.js): the Zello Channel API has NO
     * client-to-server message-acknowledgment command. The `logon`
     * command's fields are (auth_token, refresh_token, username,
     * password, channels, listen_only, version, platform_type,
     * platform_name, language, features) — no `last_message_id` /
     * `since_message_id` / `resume_from` / equivalent. The full
     * client-to-server command set is (logon, start_stream,
     * stop_stream, send_image, send_text_message, send_location,
     * keepalive). There is no path to tell Zello "I got it, stop
     * redelivering." Same behavior on Zello Consumer and Zello Work.
     * Content-hash dedup is the pragmatic fix; there's no cleaner one.
     *
     * @var array<string,bool>
     */
    private $imageDedupSeen = [];

    /** @var int Cap on dedup log size (bounded to prevent growth) */
    private $imageDedupMaxEntries = 1000;

    /** @var string Path to the on-disk dedup log */
    private $imageDedupFile = '';

    /**
     * Phase 100 (2026-07-01) — Incoming image buffer. SINGLE SLOT.
     *
     * Zello Work sets image_id = 0 in the binary frames (empirical —
     * contradicts API.md which describes a matching id, but confirmed
     * on a beta tester's install at 10:14 CDT). Meanwhile the on_image event
     * carries the real 40-bit message_id. So we can't key the buffer
     * by image_id — the JSON meta and the two binaries would land in
     * separate buckets.
     *
     * Per API.md the sequence is on_image → thumb → full and there's
     * no interleaving. Track one image at a time; reconcile pieces
     * by ARRIVAL ORDER, not by id. Finalize when BOTH binaries are
     * present (meta is nice-to-have; if it arrived first we use it,
     * if not we fall back to sender=Unknown and log the anomaly).
     *
     * Shape: null OR [
     *   'meta'       => (array) from on_image, or [] if not seen yet,
     *   'meta_id'    => (int) message_id from meta, or 0,
     *   'thumb'      => (string|null) JPEG bytes,
     *   'full'       => (string|null) JPEG bytes,
     *   'started'    => (int) unix time,
     * ]
     * @var array|null
     */
    private $pendingImage = null;

    /**
     * Phase 100 — Pending send_image ACKs from Zello, keyed by seq.
     * Zello assigns the image_id in its response; we hold the two
     * pre-encoded binaries here until then.
     *
     * Shape: [seq => [
     *   'client_id' => (int) source client resource id,
     *   'channel'   => (string),
     *   'recipient' => (string),
     *   'thumb'     => (string) JPEG bytes,
     *   'full'      => (string) JPEG bytes,
     *   'width'     => (int),
     *   'height'    => (int),
     *   'started'   => (int) unix time,
     * ]]
     * @var array
     */
    private $pendingImageSends = [];

    /** @var array Per-client outgoing audio state: resourceId => ['webm_data'=>, 'frames_sent'=>0, 'all_frames'=>[], 'user'=>, 'channel'=>, 'stream_id'=>null, 'local_stream_id'=>] */
    private $clientAudioBuffers = [];

    /** @var array Per-client outgoing stream state: resourceId => ['stream_id'=>, 'seq'=>, 'packet_id'=>] */
    private $clientOutgoingStreams = [];

    /** @var array Pending start_stream responses: seq => resourceId */
    private $pendingStreamStarts = [];

    /**
     * Gap 1 (zello-config-video-brief.md) — pending TTS start_stream
     * responses: seq => ['frames'=>string[], 'channel'=>, 'text'=>, 'outbox_id'=>].
     * A TTS send (synthesised speech keyed onto the channel) has ALL its Opus
     * frames ready up front (unlike the browser mic, which streams them in),
     * so once Zello assigns a stream_id we pace the whole frame array onto the
     * stream with a real-time timer and then stop_stream. Tracked separately
     * from $pendingStreamStarts because there is no browser client behind it.
     */
    private $pendingTtsStarts = [];

    /** @var int Counter for outgoing TTS stream tracking IDs */
    private $ttsStreamCounter = 800000;

    /**
     * Gap 1 — TTS config, lazy-loaded from settings on first TTS send.
     * Keys: piper_bin, piper_voice, ffmpeg_bin, sample_rate, frame_ms.
     * @var array|null
     */
    private $ttsConfig = null;

    /** @var int Counter for local outgoing stream IDs (used for browser-side voice_start/chunks before Zello assigns stream_id) */
    private $localStreamCounter = 900000;

    /** @var bool|null Phase E: cached probe for the zello_messages.recipient column */
    private $hasRecipientCol = null;

    /** @var int|null Zello location-provider id (0 = absent), cached once per process */
    private $zelloProviderId = null;

    /** @var bool Whether the zello location provider is enabled, cached with the id */
    private $zelloProviderEnabled = false;

    /** @var bool|null Cached probe for the unit_location_bindings.source column */
    private $bindingHasSourceCol = null;

    /**
     * @param LoopInterface $loop
     * @param array         $config  Zello settings from DB
     * @param \PDO          $pdo     Database connection
     * @param string        $prefix  Table prefix
     * @param string        $dsn     DSN for reconnecting on stale connection
     * @param string        $dbUser  DB user for reconnecting
     * @param string        $dbPass  DB password for reconnecting
     */
    public function __construct(
        LoopInterface $loop,
        array $config,
        \PDO $pdo,
        string $prefix = '',
        string $dsn = '',
        string $dbUser = '',
        string $dbPass = ''
    ) {
        $this->clients = new \SplObjectStorage();
        $this->loop    = $loop;
        $this->config  = $config;
        $this->pdo     = $pdo;
        $this->prefix  = $prefix;
        $this->dsn     = $dsn;
        $this->dbUser  = $dbUser;
        $this->dbPass  = $dbPass;

        // Ensure audio cache directory exists
        $this->audioDir = dirname(__DIR__) . '/cache/zello-audio';
        if (!is_dir($this->audioDir)) {
            @mkdir($this->audioDir, 0755, true);
        }

        // Phase 100 (2026-07-01) — image cache directory. Same
        // pattern as audioDir. Files land here as in_z_<image_id>.jpg
        // (received) or out_z_<image_id>_<ts>.jpg (sent).
        $this->imageDir = dirname(__DIR__) . '/cache/zello-images';
        if (!is_dir($this->imageDir)) {
            @mkdir($this->imageDir, 0755, true);
        }

        // Phase 101 — load persisted image dedup hashes.
        $this->imageDedupFile = $this->imageDir . '/.dedup';
        if (is_readable($this->imageDedupFile)) {
            $lines = @file($this->imageDedupFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $h) {
                    $h = trim($h);
                    // Skip malformed / non-hex-sha256 entries defensively.
                    if (strlen($h) === 64 && ctype_xdigit($h)) {
                        $this->imageDedupSeen[$h] = true;
                    }
                }
                \plog('[Proxy] Loaded ' . count($this->imageDedupSeen) . ' image dedup hashes');
            }
        }
    }

    /**
     * Phase 101 — has this JPEG been delivered already (by content
     * hash)?
     */
    private function imageAlreadyDelivered(string $fullJpegBytes): bool
    {
        if ($fullJpegBytes === '') return false;
        $hash = hash('sha256', $fullJpegBytes);
        return isset($this->imageDedupSeen[$hash]);
    }

    /**
     * Phase 101 — record this JPEG's hash so we won't re-broadcast on
     * the next Zello redelivery. Appends to the on-disk log and trims
     * to $imageDedupMaxEntries when we cross the cap.
     */
    private function markImageDelivered(string $fullJpegBytes): void
    {
        if ($fullJpegBytes === '') return;
        $hash = hash('sha256', $fullJpegBytes);
        if (isset($this->imageDedupSeen[$hash])) return;
        $this->imageDedupSeen[$hash] = true;
        // Append. If we crossed the cap, rewrite the file trimmed to
        // last N. Simple + rare.
        @file_put_contents($this->imageDedupFile, $hash . "\n", FILE_APPEND);
        if (count($this->imageDedupSeen) > $this->imageDedupMaxEntries) {
            $keys = array_keys($this->imageDedupSeen);
            $keep = array_slice($keys, -$this->imageDedupMaxEntries);
            $this->imageDedupSeen = array_flip($keep);
            @file_put_contents($this->imageDedupFile, implode("\n", $keep) . "\n");
        }
    }

    /**
     * Ensure the PDO connection is still alive, reconnect if stale.
     * Long-running proxy processes lose their MySQL connection after wait_timeout.
     */
    private function ensureDb(): void
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (\Exception $e) {
            \plog('[Proxy] DB connection lost, reconnecting...');
            try {
                $this->pdo = new \PDO($this->dsn, $this->dbUser, $this->dbPass, [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                \plog('[Proxy] DB reconnected successfully');
            } catch (\PDOException $re) {
                \plog('[Proxy] DB reconnect FAILED: ' . $re->getMessage());
                throw $re;
            }
        }
        // Pin the session timezone to PHP's (config.php derives it from the
        // area_timezone setting) so DB NOW()/created comparisons match the web
        // connection. A reconnected or raw proxy PDO otherwise defaults to UTC,
        // which skewed the 2-minute auth-token window so every token looked
        // expired ("Invalid or expired token").
        try {
            $this->pdo->exec("SET time_zone = '" . (new \DateTime('now'))->format('P') . "'");
        } catch (\Exception $e) {}
    }

    // ── Ratchet interface ────────────────────────────────────────

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $id = $conn->resourceId;
        \plog("[Proxy] Client {$id} connected (" . count($this->clients) . " total)");

        // Send current status
        $status = $this->upstream && $this->upstream->isConnected() ? 'authenticated' : 'disconnected';
        $conn->send(json_encode([
            'type'   => 'status',
            'status' => $status,
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $id = $from->resourceId;

        // Check if this is a binary message (audio data from browser)
        if ($msg instanceof \Ratchet\RFC6455\Messaging\MessageInterface && !$msg->isBinary()) {
            $msg = (string) $msg;
        } elseif ($msg instanceof \Ratchet\RFC6455\Messaging\MessageInterface && $msg->isBinary()) {
            // Binary message — audio data from browser mic
            if (isset($this->clientAuth[$id])) {
                $this->handleBrowserAudio($from, (string) $msg);
            }
            return;
        }

        // If $msg is a string, check if it looks like binary (non-JSON)
        $msgStr = (string) $msg;
        $data = json_decode($msgStr, true);

        if (!$data || !isset($data['cmd'])) {
            // Could be raw binary that wasn't detected as MessageInterface
            if (strlen($msgStr) > 0 && $msgStr[0] !== '{') {
                if (isset($this->clientAuth[$id])) {
                    $this->handleBrowserAudio($from, $msgStr);
                }
                return;
            }
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid command']));
            return;
        }

        $cmd = $data['cmd'];

        // ── Auth ─────────────────────────────────────────────────
        if ($cmd === 'auth') {
            $this->handleAuth($from, $data);
            return;
        }

        // All other commands require authentication
        if (!isset($this->clientAuth[$id])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Not authenticated']));
            return;
        }

        // ── Connect upstream ─────────────────────────────────────
        if ($cmd === 'connect') {
            $this->connectUpstream();
            return;
        }

        // ── Send text message ────────────────────────────────────
        if ($cmd === 'send_text') {
            $text      = trim($data['text'] ?? '');
            $channel   = trim($data['channel'] ?? '');
            // Phase E: optional `recipient` from the browser → a user-DM.
            $recipient = trim($data['recipient'] ?? '');
            if ($text === '') {
                $from->send(json_encode(['type' => 'error', 'message' => 'Empty message']));
                return;
            }

            $effChannel = $channel !== '' ? $channel : ($this->config['zello_dispatch_channel'] ?? '');

            // Phase 101-5 fix (Eric beta 2026-07-01) — pass $effChannel
            // (the dispatch-channel default) not the raw $channel.
            // Widget's send_text only carries {cmd,text} so $channel
            // is empty; Zello Work silently drops send_text_message
            // without a channel. Voice + image build their own
            // channel explicitly so they weren't affected. Bug was
            // latent pre-101-5 but only surfaced now that a beta tester +
            // Eric are using text on Zello Work.
            if ($this->upstream && $this->upstream->sendTextMessage($text, $effChannel, $recipient)) {
                // Log outgoing message
                $this->logMessage([
                    'channel'         => $effChannel,
                    'recipient'       => $recipient,
                    'sender_username' => $this->clientAuth[$id]['user'] ?? 'dispatch',
                    'sender_display'  => $this->clientAuth[$id]['user'] ?? 'Dispatch',
                    'message_type'    => 'text',
                    'content'         => $text,
                    'direction'       => 'outgoing',
                ]);

                // Echo back to all clients
                $this->broadcast([
                    'type'            => 'text_message',
                    'direction'       => 'outgoing',
                    'sender_username' => $this->clientAuth[$id]['user'] ?? 'dispatch',
                    'sender_display'  => $this->clientAuth[$id]['user'] ?? 'Dispatch',
                    'channel'         => $effChannel,
                    'recipient'       => $recipient,
                    'text'            => $text,
                    'timestamp'       => date('Y-m-d H:i:s'),
                ]);
            } else {
                $from->send(json_encode(['type' => 'error', 'message' => 'Not connected to Zello']));
            }
            return;
        }

        // ── Send image (Phase 100, 2026-07-01) ─────────────────────
        // Browser payload: {cmd:'send_image', channel:'', recipient:'',
        //                   width:N, height:N, thumb_b64:'...', full_b64:'...'}
        // thumb_b64 + full_b64 are base64-encoded JPEG. The widget
        // resamples + JPEG-encodes on the client side (canvas.toBlob).
        //
        // Flow:
        //   1. Validate + decode both blobs
        //   2. Save both to disk as out_z_pending_<ts>.jpg (renamed later)
        //   3. Send send_image JSON to Zello, get seq
        //   4. Stash pendingImageSends[seq] with the two binaries
        //   5. When Zello ACKs with image_id, fire sendImageBinary(thumb)
        //      then sendImageBinary(full), rename disk files, broadcast
        //      image_message to widgets (direction=outgoing)
        if ($cmd === 'send_image') {
            $channel   = trim($data['channel'] ?? '');
            $recipient = trim($data['recipient'] ?? '');
            $width     = (int) ($data['width']  ?? 0);
            $height    = (int) ($data['height'] ?? 0);
            $thumbB64  = (string) ($data['thumb_b64'] ?? '');
            $fullB64   = (string) ($data['full_b64']  ?? '');
            $effChannel = $channel !== '' ? $channel : ($this->config['zello_dispatch_channel'] ?? '');
            if ($thumbB64 === '' || $fullB64 === '' || $width < 1 || $height < 1) {
                $from->send(json_encode(['type' => 'error', 'message' => 'send_image: missing thumb/full/width/height']));
                return;
            }
            $thumbBytes = base64_decode($thumbB64, true);
            $fullBytes  = base64_decode($fullB64, true);
            if ($thumbBytes === false || $fullBytes === false || strlen($thumbBytes) === 0 || strlen($fullBytes) === 0) {
                $from->send(json_encode(['type' => 'error', 'message' => 'send_image: invalid base64 payload']));
                return;
            }
            // Belt-and-suspenders cap so a runaway paste doesn't blow
            // the WS frame. 1 MB full / 32 KB thumb matches Zello's
            // own reference client behavior.
            if (strlen($fullBytes) > 1024 * 1024) {
                $from->send(json_encode(['type' => 'error', 'message' => 'Image too large (max 1 MB after resize)']));
                return;
            }
            if (strlen($thumbBytes) > 32 * 1024) {
                $from->send(json_encode(['type' => 'error', 'message' => 'Thumbnail too large (max 32 KB)']));
                return;
            }
            if (!$this->upstream || !$this->upstream->isConnected()) {
                $from->send(json_encode(['type' => 'error', 'message' => 'Not connected to Zello']));
                return;
            }
            // Pre-flight the per-channel images_supported gate so the
            // dispatcher gets an immediate "not supported" message
            // rather than a Zello error round-trip.
            if (!$this->upstream->channelImagesSupported($effChannel)) {
                $from->send(json_encode(['type' => 'error', 'message' => "Channel '{$effChannel}' does not accept images"]));
                return;
            }
            $seq = $this->upstream->sendImageStart(
                $effChannel,
                $recipient,
                strlen($fullBytes),
                strlen($thumbBytes),
                $width,
                $height,
                'library'
            );
            if ($seq === false) {
                $from->send(json_encode(['type' => 'error', 'message' => 'Zello send_image dispatch failed']));
                return;
            }
            $this->pendingImageSends[$seq] = [
                'client_id' => $id,
                'channel'   => $effChannel,
                'recipient' => $recipient,
                'thumb'     => $thumbBytes,
                'full'      => $fullBytes,
                'width'     => $width,
                'height'    => $height,
                'started'   => time(),
            ];
            \plog("[Proxy] send_image seq={$seq} channel='{$effChannel}' recipient='{$recipient}' {$width}x{$height} thumb=" . strlen($thumbBytes) . "B full=" . strlen($fullBytes) . "B — waiting for image_id");
            return;
        }

        // ── PTT start: begin outgoing audio stream to Zello ────────
        if ($cmd === 'ptt_start') {
            $user = $this->clientAuth[$id]['user'] ?? 'unknown';
            \plog("[Proxy] PTT start from '{$user}'");

            if (!$this->upstream || !$this->upstream->isConnected()) {
                $from->send(json_encode([
                    'type'   => 'ptt_ack',
                    'status' => 'denied',
                    'reason' => 'Not connected to Zello upstream',
                ]));
                return;
            }

            $channel = $this->config['zello_dispatch_channel'] ?? '';

            // Assign a local stream ID for browser-side tracking (before Zello gives us a real one)
            $this->localStreamCounter++;
            $localStreamId = $this->localStreamCounter;

            // Initialize outgoing audio state
            $this->clientAudioBuffers[$id] = [
                'webm_data'       => '',
                'frames_sent'     => 0,
                'all_frames'      => [],
                'chunk_buffer'    => [],
                'user'            => $user,
                'channel'         => $channel,
                'stream_id'       => null,    // Zello stream_id — set when start_stream response arrives
                'local_stream_id' => $localStreamId,
                'packet_id'       => 0,
                'pending_frames'  => [],      // Frames waiting to be sent (before we have stream_id)
                'audio_started'   => false,   // True once first non-DTX frame is seen
                'started'         => time(),
            ];

            // Also create an activeStreams entry for broadcasting to browsers
            // Browser MediaRecorder produces Opus with 20ms frames
            $this->activeStreams[$localStreamId] = [
                'from'              => $user,
                'display_name'      => $user,
                'channel'           => $channel,
                'codec'             => 'opus',
                // Eric beta 2026-06-30 — browser MediaRecorder captures at
                // 48000Hz native regardless of the sampleRate constraint
                // (Chrome/Firefox/Edge all ignore that constraint). The
                // prior hard-coded 16000 caused Zello to decode at 1/3
                // speed and the stored .ogg to play back 3x slower. The
                // codec_header sent to Zello + the OggOpusWriter for
                // stored history playback are both anchored on this key.
                'sample_rate'       => 48000,
                'frame_duration_ms' => 20,
                'packets'           => [],
                'chunk_buffer'      => [],
                'started'           => time(),
                'is_outgoing'       => true,
                'source_client_id'  => $id,
            ];

            // DON'T send start_stream to Zello yet — wait until first real audio
            // frame is extracted (after DTX warmup). This eliminates the dead gap
            // where Zello shows "transmitting" but no audio is flowing.
            // start_stream is triggered in handleBrowserAudio when audio_started flips.

            // Notify all browser clients that this user is transmitting
            $this->broadcast([
                'type'            => 'voice_start',
                'channel'         => $channel,
                'sender_username' => $user,
                'sender_display'  => $user,
                'stream_id'       => $localStreamId,
                'codec'           => 'opus',
                'direction'       => 'outgoing',
            ]);

            // Acknowledge PTT to the sending client
            $from->send(json_encode([
                'type'   => 'ptt_ack',
                'status' => 'ok',
            ]));
            return;
        }

        // ── PTT stop: finalize outgoing audio stream ────────────
        if ($cmd === 'ptt_stop') {
            $user = $this->clientAuth[$id]['user'] ?? 'unknown';
            $dur  = $data['duration_ms'] ?? 0;
            \plog("[Proxy] PTT stop from '{$user}' ({$dur}ms)");

            $this->finalizeOutgoingStream($id);
            return;
        }

        // ── Disconnect upstream ──────────────────────────────────
        if ($cmd === 'disconnect') {
            if ($this->upstream) {
                $this->upstream->disconnect();
            }
            return;
        }

        $from->send(json_encode(['type' => 'error', 'message' => 'Unknown command: ' . $cmd]));
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $id = $conn->resourceId;
        $this->clients->detach($conn);

        // Finalize any active outgoing stream for this client
        if (isset($this->clientAudioBuffers[$id]) && is_array($this->clientAudioBuffers[$id])) {
            $this->finalizeOutgoingStream($id);
        }

        // Stop any active outgoing Zello stream
        if (isset($this->clientOutgoingStreams[$id])) {
            $streamId = $this->clientOutgoingStreams[$id]['stream_id'];
            if ($this->upstream && $this->upstream->isConnected()) {
                $this->upstream->sendCommand([
                    'command'   => 'stop_stream',
                    'stream_id' => $streamId,
                ]);
            }
            unset($this->clientOutgoingStreams[$id]);
        }

        unset($this->clientAuth[$id]);
        unset($this->clientAudioBuffers[$id]);

        \plog("[Proxy] Client {$id} disconnected (" . count($this->clients) . " remaining)");
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        \plog("[Proxy] Error on client {$conn->resourceId}: " . $e->getMessage());
        $conn->close();
    }

    // ── Internal methods ─────────────────────────────────────────

    /**
     * Authenticate a browser client by verifying their token against the DB.
     */
    private function handleAuth(ConnectionInterface $conn, array $data): void
    {
        $id    = $conn->resourceId;
        $token = trim($data['token'] ?? '');

        if ($token === '') {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Missing auth token']));
            return;
        }

        // Look up token in DB (valid for 2 minutes)
        try {
            $this->ensureDb();
            $sql = "SELECT `token`, `user`, `user_level`, `created`
                    FROM `{$this->prefix}zello_ws_tokens`
                    WHERE `token` = ?
                      AND `created` > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                    LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$token]);
            $row = $stmt->fetch();
        } catch (\Exception $e) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Auth DB error']));
            \plog("[Proxy] Auth DB error for client {$id}: " . $e->getMessage());
            return;
        }

        if (!$row) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid or expired token']));
            \plog("[Proxy] Auth failed for client {$id}: token not found or expired");
            return;
        }

        // Consume the token (one-time use)
        try {
            $this->pdo->prepare("DELETE FROM `{$this->prefix}zello_ws_tokens` WHERE `token` = ?")->execute([$token]);
        } catch (\Exception $e) {
            // Non-fatal — token will expire anyway
        }

        $user  = $row['user'] ?? 'unknown';
        $level = (int) ($row['user_level'] ?? 99);

        $this->clientAuth[$id] = [
            'user'  => $user,
            'level' => $level,
        ];

        \plog("[Proxy] Client {$id} authenticated as '{$user}' (level {$level})");

        $channel = $this->config['zello_dispatch_channel'] ?? '';
        $conn->send(json_encode([
            'type'    => 'auth_ok',
            'user'    => $user,
            'channel' => $channel,
        ]));

        // Send channel info so the widget header displays it
        if ($channel !== '') {
            $conn->send(json_encode([
                'type'    => 'channel_status',
                'channel' => $channel,
                'status'  => ($this->upstream && $this->upstream->isConnected()) ? 'online' : 'connecting',
            ]));
        }

        // Auto-connect upstream if not already connected
        if (!$this->upstream || !$this->upstream->isConnected()) {
            $this->connectUpstream();
        }
    }

    /**
     * Create and connect the upstream Zello connection.
     */
    private function connectUpstream(): void
    {
        if ($this->upstream && $this->upstream->isConnected()) {
            \plog("[Proxy] Upstream already connected");
            return;
        }

        // Check for minimum required credentials.
        //
        // Issue #41 (a beta tester 2026-07-03): three auth modes are valid, but
        // this gate only recognized two. a beta tester configured Zello Work
        // (username + password against the network user DB — the config
        // page correctly told him no JWT / private key was needed), the
        // proxy successfully connected upstream and authenticated as
        // justin.gilbert, then this same check fired a
        // "credentials not configured — go set up issuer and private key"
        // status message that contradicts the config page and disconnects
        // the widget.
        //
        // Three valid auth paths per ZelloUpstream::sendLogon() + Phase 98
        // (2026-06-28) work-network integration:
        //   1. JWT signing:  zello_issuer + zello_private_key
        //   2. Static token: zello_auth_token
        //   3. Zello Work:   zello_username + zello_password
        $hasJwt   = !empty($this->config['zello_issuer']) && !empty($this->config['zello_private_key']);
        $hasToken = !empty($this->config['zello_auth_token']);
        $hasWork  = !empty($this->config['zello_username']) && !empty($this->config['zello_password']);
        if (!$hasJwt && !$hasToken && !$hasWork) {
            \plog("[Proxy] Cannot connect upstream: no Zello credentials configured.");
            \plog("[Proxy] Configure one of: JWT (issuer+private_key), auth_token, or Zello Work (username+password) in Config > Zello Network Radio.");
            $this->broadcast([
                'type'   => 'status',
                'status' => 'config_needed',
                'detail' => 'Zello credentials not configured. Go to Config → Zello Network Radio and configure one of: JWT (issuer + private key), a static auth token, or Zello Work username + password.',
            ]);
            return;
        }

        \plog("[Proxy] Creating upstream connection...");

        try {
            $self = $this;

            $this->upstream = new ZelloUpstream(
                $this->loop,
                $this->config,
                // onMessage — handle incoming Zello events
                function (array $data) use ($self) {
                    try {
                        $self->handleUpstreamEvent($data);
                    } catch (\Exception $e) {
                        \plog("[Proxy] Error in handleUpstreamEvent: " . $e->getMessage());
                    }
                },
                // onStatus — broadcast status changes to all clients
                function (string $status, string $detail) use ($self) {
                    try {
                        \plog("[Proxy] Upstream status: {$status} — {$detail}");
                        $self->broadcast([
                            'type'   => 'status',
                            'status' => $status,
                            'detail' => $detail,
                        ]);
                    } catch (\Exception $e) {
                        \plog("[Proxy] Error broadcasting status: " . $e->getMessage());
                    }
                },
                // onAudioPacket — collect incoming audio data
                function (int $streamId, int $packetId, string $opusData) use ($self) {
                    try {
                        $self->handleAudioPacket($streamId, $packetId, $opusData);
                    } catch (\Exception $e) {
                        \plog("[Proxy] Error in handleAudioPacket: " . $e->getMessage());
                    }
                },
                // Phase 100 — onImagePacket — collect incoming image data
                function (int $imageId, int $imageType, string $jpegBytes) use ($self) {
                    try {
                        $self->handleImagePacket($imageId, $imageType, $jpegBytes);
                    } catch (\Exception $e) {
                        \plog("[Proxy] Error in handleImagePacket: " . $e->getMessage());
                    }
                }
            );

            $this->upstream->connect();
        } catch (\Exception $e) {
            \plog("[Proxy] EXCEPTION in connectUpstream: " . $e->getMessage());
            \plog("  " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * Handle an event from the Zello upstream server and relay to browser clients.
     */
    private function handleUpstreamEvent(array $data): void
    {
        $command = $data['command'] ?? '';

        // Text message received
        if ($command === 'on_text_message') {
            $channel = $data['channel'] ?? '';
            $from    = $data['from'] ?? 'unknown';
            $text    = $data['message_text'] ?? $data['text'] ?? '';

            // Phase E: `for` is the DM recipient username when this inbound
            // text was sent as a direct message (false/absent = a channel
            // broadcast). When present it's OUR username (the console is the
            // addressed recipient), so we record the SENDER as the DM origin
            // — a reply DMs `from` back. We persist whether it was a DM so the
            // inbox can offer "reply to sender" vs "reply to channel".
            $forUser = $data['for'] ?? false;
            $isDm    = ($forUser !== false && $forUser !== null && $forUser !== '');

            // Log to database — for an inbound DM, the conversational partner
            // (the address a reply goes to) is `from`, stored in `recipient`
            // so the inbox can thread it.
            $msgId = $this->logMessage([
                'channel'         => $channel,
                'sender_username' => $from,
                'sender_display'  => $data['display_name'] ?? $from,
                'message_type'    => 'text',
                'content'         => $text,
                'direction'       => 'incoming',
                'recipient'       => $isDm ? $from : '',
            ]);

            // Broadcast to browser clients
            $this->broadcast([
                'type'            => 'text_message',
                'id'              => $msgId,
                'direction'       => 'incoming',
                'channel'         => $channel,
                'sender_username' => $from,
                'sender_display'  => $data['display_name'] ?? $from,
                'is_dm'           => $isDm,
                'text'            => $text,
                'timestamp'       => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        // Channel status
        if ($command === 'on_channel_status') {
            $this->broadcast([
                'type'           => 'channel_status',
                'channel'        => $data['channel'] ?? '',
                'status'         => $data['status'] ?? '',
                'users_online'   => $data['users_online'] ?? 0,
            ]);
            return;
        }

        // Voice stream start — begin collecting audio packets
        if ($command === 'on_stream_start') {
            $streamId = $data['stream_id'] ?? 0;
            $from     = $data['from'] ?? 'unknown';
            $channel  = $data['channel'] ?? '';
            $codec    = $data['codec'] ?? '';
            $codecHdr = $data['codec_header'] ?? '';

            // Parse codec_header for sample rate + per-packet duration.
            // Phase 99al (Eric beta 2026-07-01) — the previous parse
            // only read byte 3 (frame_size_ms). Zello mobile packs
            // multiple frames per packet, so the REAL per-packet ms is
            // frames_per_packet × frame_size_ms. Ignoring byte 2 made
            // the WebmStreamWriter timing 1/3 (or 1/2) real → browser
            // MediaSource drifted for a few seconds then stalled the
            // playback (Eric's "cuts off after 3-5s" symptom on 10s
            // mobile TX). Now we compute both and store packet_ms.
            $sampleRate       = 16000;
            $frameDurationMs  = 60;
            $framesPerPacket  = 1;
            $packetDurationMs = 60;
            if ($codecHdr !== '') {
                $decoded = base64_decode($codecHdr);
                if ($decoded !== false && strlen($decoded) >= 4) {
                    $sr = unpack('v', substr($decoded, 0, 2))[1]; // uint16 LE
                    if ($sr > 0) $sampleRate = $sr;
                    $framesPerPacket = max(1, ord($decoded[2]));
                    $frameDurationMs = max(1, ord($decoded[3]));
                    $packetDurationMs = $framesPerPacket * $frameDurationMs;
                    if ($packetDurationMs > 120) $packetDurationMs = 120;
                }
            }

            $this->activeStreams[$streamId] = [
                'from'               => $from,
                'display_name'       => $data['display_name'] ?? $from,
                'channel'            => $channel,
                'codec'              => $codec,
                'sample_rate'        => $sampleRate,
                'frame_duration_ms'  => $frameDurationMs,
                'frames_per_packet'  => $framesPerPacket,
                'packet_duration_ms' => $packetDurationMs,
                'packets'            => [],
                'chunk_buffer'       => [],
                'started'            => time(),
            ];

            \plog("[Proxy] Stream {$streamId} started from '{$from}' ({$codec}, {$sampleRate}Hz, {$framesPerPacket} × {$frameDurationMs}ms = {$packetDurationMs}ms per packet)");

            // Notify browser clients that someone is talking
            $this->broadcast([
                'type'            => 'voice_start',
                'channel'         => $channel,
                'sender_username' => $from,
                'sender_display'  => $data['display_name'] ?? $from,
                'stream_id'       => $streamId,
                'codec'           => $codec,
            ]);
            return;
        }

        // Voice stream stop — build .ogg file and broadcast
        if ($command === 'on_stream_stop') {
            $streamId = $data['stream_id'] ?? 0;
            $this->finalizeStream($streamId);
            return;
        }

        // Phase 100 (2026-07-01) — Image metadata. Zello sends on_image
        // first, then TWO binary frames. Single-slot reconciliation
        // (see $pendingImage doc). If we already have binaries buffered
        // from an earlier orphan sequence, merge into the current slot.
        if ($command === 'on_image') {
            $metaId = (int) ($data['message_id'] ?? $data['image_id'] ?? 0);
            $w = $data['width']  ?? '?';
            $h = $data['height'] ?? '?';
            \plog("[Proxy] on_image message_id={$metaId} from " . ($data['from'] ?? '?') . " channel=" . ($data['channel'] ?? '?') . " {$w}x{$h}");
            if ($this->pendingImage === null) {
                $this->pendingImage = [
                    'meta'    => $data,
                    'meta_id' => $metaId,
                    'thumb'   => null,
                    'full'    => null,
                    'started' => time(),
                ];
            } else {
                $this->pendingImage['meta']    = $data;
                $this->pendingImage['meta_id'] = $metaId;
            }
            $this->tryFinalizePendingImage();
            return;
        }

        // Location received
        if ($command === 'on_location') {
            $channel = $data['channel'] ?? '';
            $from    = $data['from'] ?? 'unknown';

            $this->logMessage([
                'channel'         => $channel,
                'sender_username' => $from,
                'sender_display'  => $data['display_name'] ?? $from,
                'message_type'    => 'location',
                'latitude'        => $data['latitude'] ?? null,
                'longitude'       => $data['longitude'] ?? null,
                'direction'       => 'incoming',
            ]);

            $this->broadcast([
                'type'            => 'location',
                'channel'         => $channel,
                'sender_username' => $from,
                'latitude'        => $data['latitude'] ?? null,
                'longitude'       => $data['longitude'] ?? null,
                'timestamp'       => date('Y-m-d H:i:s'),
            ]);

            // Feed the fix onto the unit-tracking map: resolve the Zello
            // sender → TicketsCAD member → responder (unit), then write a
            // location_reports row + binding the same way the other
            // location providers do. Fully guarded — an unmapped sender or
            // a bad coordinate is logged and skipped, never fatal.
            $this->persistZelloLocation(
                (string) $from,
                $data['latitude']  ?? null,
                $data['longitude'] ?? null
            );
            return;
        }

        // Check for start_stream failure
        if (isset($data['success']) && !$data['success'] && isset($data['error'])) {
            $responseSeq = $data['seq'] ?? 0;
            if (isset($this->pendingStreamStarts[$responseSeq])) {
                $pending = $this->pendingStreamStarts[$responseSeq];
                unset($this->pendingStreamStarts[$responseSeq]);
                \plog("[Proxy] start_stream FAILED for client {$pending['client_id']}: " . ($data['error'] ?? 'unknown'));
            }
            // Gap 1: a TTS start_stream the upstream rejected → fail the row.
            if (isset($this->pendingTtsStarts[$responseSeq])) {
                $pending = $this->pendingTtsStarts[$responseSeq];
                unset($this->pendingTtsStarts[$responseSeq]);
                \plog("[Proxy] TTS start_stream FAILED for outbox #{$pending['outbox_id']}: " . ($data['error'] ?? 'unknown'));
                $this->markOutbox((int) $pending['outbox_id'], 'failed',
                    'start_stream rejected: ' . substr((string) ($data['error'] ?? 'unknown'), 0, 180));
            }
            // Phase 100 — send_image the upstream rejected → tell the widget.
            if (isset($this->pendingImageSends[$responseSeq])) {
                $pending = $this->pendingImageSends[$responseSeq];
                unset($this->pendingImageSends[$responseSeq]);
                $err = (string) ($data['error'] ?? 'unknown');
                \plog("[Proxy] send_image FAILED seq={$responseSeq}: {$err}");
                // Direct-reply to the originating widget so the paste
                // handler can surface the error immediately.
                foreach ($this->clients as $client) {
                    if ($client->resourceId === $pending['client_id']) {
                        $client->send(json_encode(['type' => 'error', 'message' => 'Image send failed: ' . $err]));
                        break;
                    }
                }
            }
        }

        // Phase 100 — send_image response (contains image_id for our seq).
        // On receipt: send the two binary frames (thumbnail then full),
        // save both to disk, logMessage + broadcast image_message.
        if (isset($data['success']) && $data['success'] && isset($data['image_id'])) {
            $responseSeq = $data['seq'] ?? 0;
            if (isset($this->pendingImageSends[$responseSeq])) {
                $pending  = $this->pendingImageSends[$responseSeq];
                $imageId  = (int) $data['image_id'];
                unset($this->pendingImageSends[$responseSeq]);
                \plog("[Proxy] send_image ACK seq={$responseSeq} → image_id={$imageId}");

                // Send binaries — thumbnail (type 2) first, then full (type 1).
                $this->upstream->sendImageBinary($imageId, 2, $pending['thumb']);
                $this->upstream->sendImageBinary($imageId, 1, $pending['full']);

                // Persist to disk for local echo + history.
                $ts = time();
                $baseName = "out_z_{$imageId}_{$ts}";
                $fullFile  = $this->imageDir . '/' . $baseName . '.jpg';
                $thumbFile = $this->imageDir . '/' . $baseName . '.thumb.jpg';
                $fullUrl   = 'cache/zello-images/' . $baseName . '.jpg';
                try {
                    file_put_contents($fullFile,  $pending['full']);
                    file_put_contents($thumbFile, $pending['thumb']);
                } catch (\Exception $e) {
                    \plog("[Proxy] Failed to write outgoing image files: " . $e->getMessage());
                }

                // Log + broadcast to widgets (as outgoing — recipients
                // still auto-render; the sender's widget suppresses
                // autoplay for outgoing per Phase 99am rule, which
                // for images means the card just appears without
                // opening the full-size modal automatically).
                $senderUser = $this->clientAuth[$pending['client_id']]['user'] ?? 'dispatch';
                $msgId = 0;
                try {
                    $msgId = (int) $this->logMessage([
                        'channel'         => $pending['channel'],
                        'recipient'       => $pending['recipient'],
                        'sender_username' => $senderUser,
                        'sender_display'  => $senderUser,
                        'message_type'    => 'image',
                        'content'         => null,
                        'direction'       => 'outgoing',
                        'media_url'       => $fullUrl,
                    ]);
                } catch (\Exception $e) {
                    \plog("[Proxy] logMessage failed for outgoing image id={$imageId}: " . $e->getMessage());
                }

                $thumbDataUri = 'data:image/jpeg;base64,' . base64_encode($pending['thumb']);
                $this->broadcast([
                    'type'            => 'image_message',
                    'id'              => $msgId,
                    'direction'       => 'outgoing',
                    'image_id'        => $imageId,
                    'channel'         => $pending['channel'],
                    'sender_username' => $senderUser,
                    'sender_display'  => $senderUser,
                    'recipient'       => $pending['recipient'],
                    'width'           => $pending['width'],
                    'height'          => $pending['height'],
                    'thumb'           => $thumbDataUri,
                    'full_url'        => $fullUrl,
                    'timestamp'       => date('Y-m-d H:i:s'),
                ]);
                return;
            }
        }

        // Check for start_stream response (contains stream_id for our seq)
        if (isset($data['success']) && $data['success'] && isset($data['stream_id'])) {
            $responseSeq = $data['seq'] ?? 0;
            // Gap 1: a TTS stream just opened → pace the synthesised frames.
            if (isset($this->pendingTtsStarts[$responseSeq])) {
                $this->beginTtsStream($responseSeq, (int) $data['stream_id']);
                return;
            }
            if (isset($this->pendingStreamStarts[$responseSeq])) {
                $pending  = $this->pendingStreamStarts[$responseSeq];
                $zelloStreamId = $data['stream_id'];
                $clientId = $pending['client_id'];
                unset($this->pendingStreamStarts[$responseSeq]);

                \plog("[Proxy] Got Zello stream_id {$zelloStreamId} for client {$clientId}");

                // Link the Zello stream_id to the client's outgoing buffer
                if (isset($this->clientAudioBuffers[$clientId]) && is_array($this->clientAudioBuffers[$clientId])) {
                    $this->clientAudioBuffers[$clientId]['stream_id'] = $zelloStreamId;

                    // Store in outgoing streams map
                    $this->clientOutgoingStreams[$clientId] = [
                        'stream_id' => $zelloStreamId,
                    ];

                    // Flush any frames that arrived before we got the stream_id
                    $pendingFrames = $this->clientAudioBuffers[$clientId]['pending_frames'];
                    if (!empty($pendingFrames)) {
                        $packetId = $this->clientAudioBuffers[$clientId]['packet_id'];
                        foreach ($pendingFrames as $frame) {
                            $packet = chr(0x01)
                                . pack('N', $zelloStreamId)
                                . pack('N', $packetId)
                                . $frame;
                            $this->upstream->sendBinary($packet);
                            $packetId++;
                        }
                        $this->clientAudioBuffers[$clientId]['packet_id'] = $packetId;
                        $this->clientAudioBuffers[$clientId]['pending_frames'] = [];
                        \plog("[Proxy] Flushed " . count($pendingFrames) . " pending frames to Zello stream {$zelloStreamId}");
                    }
                }
                return;
            }
        }

        // Forward any other events as raw data
        if ($command !== '' && !isset($data['success'])) {
            $data['type'] = 'zello_event';
            $this->broadcast($data);
        }
    }

    /**
     * Decode the TOC byte(s) of an Opus packet to get its frame layout.
     *
     * An Opus packet can hold multiple frames — the TOC byte encodes
     *   config    (bits 7-3) → single-frame duration (RFC 6716 §3.1 Table 2)
     *   frame count code (bits 1-0) → number of frames in the packet
     * so the TOTAL packet duration is single_frame_ms × frame_count.
     *
     * Browser MediaRecorder behavior (as observed 2026-06-30 in beta):
     *   Chrome/Edge  → config 13 hybrid SWB 20ms × 3 frames/packet = 60ms per packet
     *   Firefox      → SILK 40ms × 1 frame/packet = 40ms per packet
     *
     * Eric beta 2026-06-30 — the OGG writer credits each packet as one
     * "frame" of duration $frame_duration_ms. If we only stored the
     * single-frame duration (20ms for Chrome) but Chrome packs 3 frames
     * per packet, the stored .ogg reports 1/3 real duration (5-sec TX
     * showed as 1.5 sec). Zello's codec_header also has a separate
     * frames-per-packet byte that we were hard-coding to 1. Fix both by
     * returning frame_ms, frames_per_packet, and packet_ms so callers
     * can pick the right piece.
     *
     * @param string $opusPacket  Raw Opus packet bytes (from WebmOpusExtractor)
     * @return array{frame_ms:int, frames_per_packet:int, packet_ms:int}
     */
    private function _opusPacketInfo(string $opusPacket): array
    {
        $default = ['frame_ms' => 20, 'frames_per_packet' => 1, 'packet_ms' => 20, 'toc_config' => -1, 'toc_frame_code' => -1];
        $len = strlen($opusPacket);
        if ($len < 1) return $default;
        $toc         = ord($opusPacket[0]);
        $config      = ($toc >> 3) & 0x1F;
        $frameCountC = $toc & 0x03;

        // RFC 6716 §3.1 Table 2 — single-frame size per config (in ms).
        // Phase 99al (Eric beta 2026-07-01) — filled in the CELT 2.5/5 ms
        // configs. Turns out Chrome MediaRecorder DOES emit these (its
        // Opus encoder picks CELT-only for higher bitrates and packs
        // multiple sub-frames per packet). Missing entries were causing
        // silent fallback to 20ms → half-speed playback on Zello.
        // Values are STORED × 10 (tenths of ms) so integer math preserves
        // the 2.5/5 ms cases exactly. Callers divide by 10 for ms.
        static $durationsTenths = [
            0  => 100, 1  => 200, 2  => 400, 3  => 600, // SILK NB   10/20/40/60
            4  => 100, 5  => 200, 6  => 400, 7  => 600, // SILK MB
            8  => 100, 9  => 200, 10 => 400, 11 => 600, // SILK WB
            12 => 100, 13 => 200,                       // Hybrid SWB 10/20
            14 => 100, 15 => 200,                       // Hybrid FB  10/20
            16 => 25,  17 => 50,  18 => 100, 19 => 200, // CELT NB    2.5/5/10/20
            20 => 25,  21 => 50,  22 => 100, 23 => 200, // CELT WB
            24 => 25,  25 => 50,  26 => 100, 27 => 200, // CELT SWB
            28 => 25,  29 => 50,  30 => 100, 31 => 200, // CELT FB
        ];
        $frameTenths = $durationsTenths[$config] ?? 200; // default 20ms
        $frameMs = (int) round($frameTenths / 10);       // int for chr()/log

        // Frame count code (RFC 6716 §3.1):
        //   0 → 1 frame
        //   1 → 2 frames (equal size)
        //   2 → 2 frames (different sizes)
        //   3 → arbitrary N frames, N = (byte1 & 0x3F), must be 1..48
        switch ($frameCountC) {
            case 0: $frames = 1; break;
            case 1: $frames = 2; break;
            case 2: $frames = 2; break;
            case 3:
                if ($len < 2) return $default;
                $frames = ord($opusPacket[1]) & 0x3F;
                if ($frames < 1 || $frames > 48) return $default;
                break;
            default: $frames = 1;
        }

        // Per RFC 6716, a packet cannot represent > 120ms of audio.
        // Compute in tenths-of-ms so 2.5×N and 5×N stay exact, then
        // round to int ms for the return value.
        $packetTenths = $frames * $frameTenths;
        if ($packetTenths > 1200) $packetTenths = 1200; // 120ms cap
        $packetMs = (int) round($packetTenths / 10);

        return [
            'frame_ms'          => $frameMs,
            'frames_per_packet' => $frames,
            'packet_ms'         => $packetMs,
            'toc_config'        => $config,       // for logging
            'toc_frame_code'    => $frameCountC,  // for logging
        ];
    }

    /**
     * Handle binary audio data received from a browser client.
     *
     * The browser sends WebM chunks in real-time as they're recorded.
     * We accumulate them, extract new Opus frames incrementally, and:
     * 1. Send new frames to Zello as binary packets (real-time streaming)
     * 2. Buffer frames and broadcast as streaming .ogg chunks to all browser clients
     */
    private function handleBrowserAudio(ConnectionInterface $conn, string $binaryData): void
    {
        $id   = $conn->resourceId;

        // Must have an active outgoing buffer (set by ptt_start)
        if (!isset($this->clientAudioBuffers[$id]) || !is_array($this->clientAudioBuffers[$id])) {
            \plog("[Proxy] Received audio from client {$id} but no active PTT session, ignoring");
            return;
        }

        $buf = &$this->clientAudioBuffers[$id];
        if (!isset($buf['chunk_count'])) $buf['chunk_count'] = 0;
        $buf['chunk_count']++;
        $buf['webm_data'] .= $binaryData;

        $webmLen = strlen($buf['webm_data']);
        \plog("[Proxy] Audio chunk #{$buf['chunk_count']}: +" . strlen($binaryData) . "B, total={$webmLen}B");

        // Re-extract all Opus frames from accumulated WebM data
        try {
            $extractor = new WebmOpusExtractor();
            $allFrames = $extractor->extract($buf['webm_data']);
        } catch (\Exception $e) {
            \plog("[Proxy] WebM extraction failed: " . $e->getMessage());
            return;
        }

        $totalFrames = count($allFrames);
        $previouslySent = $buf['frames_sent'];

        \plog("[Proxy] Extraction: {$totalFrames} total frames (previously {$previouslySent})");

        if ($totalFrames <= $previouslySent) {
            return; // No new frames extracted yet
        }

        // Get only the new frames we haven't processed yet
        $newFrames = array_slice($allFrames, $previouslySent);
        $buf['frames_sent'] = $totalFrames;

        $localStreamId = $buf['local_stream_id'];

        // ── Strip leading DTX silence frames ──
        // MediaRecorder's Opus encoder outputs ~1 second of 3-byte DTX (silence)
        // frames during startup before actual mic audio flows. These cause a
        // dead zone at the start of the transmission. Skip them until we see
        // the first real audio frame (> 6 bytes), then pass everything through.
        if (!isset($buf['audio_started']) || !$buf['audio_started']) {
            $stripped = 0;
            while (!empty($newFrames) && strlen($newFrames[0]) <= 6) {
                array_shift($newFrames);
                $stripped++;
            }
            if (!empty($newFrames)) {
                $buf['audio_started'] = true;
                if ($stripped > 0) {
                    \plog("[Proxy] Stripped {$stripped} leading DTX silence frames");
                }

                // Eric beta 2026-06-30 — inspect the TOC of the first
                // real (non-DTX) Opus packet to learn frame layout.
                // Chrome typically emits 3× 20ms frames per packet =
                // 60ms per packet. Firefox emits 1× 40ms frame per
                // packet. Previous hard-code of 20ms per packet in the
                // OGG writer made Chrome-recorded .ogg files report
                // 1/3 duration (5-sec TX shown as 1.5 sec); the same
                // codec_header field also needed the frames-per-packet
                // byte (previously hard-coded to 1). Persist all three
                // values on $buf + activeStreams for downstream use.
                $opusInfo = $this->_opusPacketInfo($newFrames[0]);
                $frameMs        = $opusInfo['frame_ms'];
                $framesPerPkt   = $opusInfo['frames_per_packet'];
                $packetMs       = $opusInfo['packet_ms'];
                $tocConfig      = $opusInfo['toc_config'];
                $tocFrameCode   = $opusInfo['toc_frame_code'];
                $buf['frame_duration_ms']  = $frameMs;
                $buf['frames_per_packet']  = $framesPerPkt;
                $buf['packet_duration_ms'] = $packetMs;
                if (isset($this->activeStreams[$localStreamId])) {
                    $this->activeStreams[$localStreamId]['frame_duration_ms']  = $frameMs;
                    $this->activeStreams[$localStreamId]['frames_per_packet']  = $framesPerPkt;
                    $this->activeStreams[$localStreamId]['packet_duration_ms'] = $packetMs;
                }
                // Phase 99al — include TOC config + frame-code in the log
                // so misdetection is immediately visible. First byte of
                // the first real frame in hex, plus the decoded fields.
                $firstByteHex = sprintf('0x%02X', ord($newFrames[0][0] ?? "\0"));
                \plog("[Proxy] Detected Opus layout: {$framesPerPkt} × {$frameMs}ms = {$packetMs}ms per packet "
                    . "(TOC={$firstByteHex}, config={$tocConfig}, frame_code={$tocFrameCode}, first-frame-bytes=" . strlen($newFrames[0]) . ")");

                // ── Lazy stream start: first real audio detected → open Zello stream now ──
                $channel = $buf['channel'];
                \plog("[Proxy] First real audio frame detected — sending start_stream to Zello");
                // Eric beta 2026-06-30 — codec_header must match the
                // actual encoding rate the browser produced (48000Hz),
                // not the prior hard-coded 16000. Was making Zello
                // decode at 1/3 speed = garbled audio for a beta tester.
                // Also pulled from the outgoing stream's sample_rate
                // (set to 48000 in ptt_start) so this rate lives in
                // one place if it ever needs to change.
                //
                // codec_header layout is: (sample_rate_LE_u16, frames_per_packet_u8, frame_size_ms_u8).
                // packet_duration is the total ms of audio per packet.
                //
                // Phase 99al (Eric beta 2026-07-01) — SIMPLIFICATION.
                // Prior version reported the internal Opus frame layout
                // (frames_per_packet × frame_ms). Zello Work seems to
                // interpret frame_size_ms as-if it were packet_ms
                // (Chrome multi-frame packets played back at 3x speed,
                // fresh testing on CELT 2.5ms configs made the whole
                // stream 2x slow). The Opus decoder reads the true
                // structure from each packet's own TOC byte anyway,
                // so we always send (frames_per_packet=1, frame_ms=packet_ms).
                // Zello therefore schedules playback correctly and the
                // Opus decoder still decodes each packet's contents right.
                $streamMeta = $this->activeStreams[$buf['local_stream_id']] ?? null;
                $sampleRate = $streamMeta ? (int) $streamMeta['sample_rate'] : 48000;
                // Cap byte value at 255 (RFC 6716 caps at 120 anyway).
                $byteMs = $packetMs > 255 ? 255 : $packetMs;
                $seq = $this->upstream->sendCommand([
                    'command'         => 'start_stream',
                    'channel'         => $channel,
                    'type'            => 'audio',
                    'codec'           => 'opus',
                    'codec_header'    => base64_encode(pack('v', $sampleRate) . chr(1) . chr($byteMs)),
                    'packet_duration' => $packetMs,
                ]);
                $this->pendingStreamStarts[$seq] = [
                    'client_id'       => $conn->resourceId,
                    'local_stream_id' => $buf['local_stream_id'],
                ];
            } else {
                \plog("[Proxy] All {$stripped} frames are DTX silence, waiting for real audio...");
                return;
            }
        }

        $newCount = count($newFrames);
        \plog("[Proxy] Found {$newCount} new Opus frames to send");

        // Add new frames to the complete frames list (for final .ogg archive)
        foreach ($newFrames as $frame) {
            $buf['all_frames'][] = $frame;
        }

        // ── Send new frames to Zello (if we have stream_id) ──
        $this->sendOutgoingFrames($buf, $newFrames);

        // ── Broadcast to browser clients as streaming chunks ──
        // Use the same chunk-based approach as incoming audio
        if (isset($this->activeStreams[$localStreamId])) {
            foreach ($newFrames as $frame) {
                $this->activeStreams[$localStreamId]['packets'][] = $frame;
                $this->activeStreams[$localStreamId]['chunk_buffer'][] = $frame;
            }

            // Flush chunk every N packets (MSE maintains decoder state so small chunks are fine)
            if (count($this->activeStreams[$localStreamId]['chunk_buffer']) >= $this->streamChunkSize) {
                $this->flushStreamChunk($localStreamId, false);
            }
        }
    }

    /**
     * Send Opus frames to Zello upstream, or queue them if stream_id not yet available.
     */
    private function sendOutgoingFrames(array &$buf, array $frames): void
    {
        if (empty($frames)) return;

        $zelloStreamId = $buf['stream_id'];
        if ($zelloStreamId !== null) {
            // Stream is active — send frames immediately
            foreach ($frames as $frame) {
                $packet = chr(0x01)
                    . pack('N', $zelloStreamId)
                    . pack('N', $buf['packet_id'])
                    . $frame;
                $this->upstream->sendBinary($packet);
                $buf['packet_id']++;
            }
            \plog("[Proxy] Sent " . count($frames) . " frames to Zello (packet_id now {$buf['packet_id']})");
        } else {
            // Stream not yet started (waiting for start_stream response)
            foreach ($frames as $frame) {
                $buf['pending_frames'][] = $frame;
            }
            \plog("[Proxy] Queued " . count($frames) . " frames (pending, no stream_id yet)");
        }
    }

    /**
     * Finalize an outgoing audio stream when PTT is released.
     * Stops the Zello stream, flushes remaining chunks, saves .ogg, broadcasts voice_message.
     */
    private function finalizeOutgoingStream(int $clientId): void
    {
        if (!isset($this->clientAudioBuffers[$clientId]) || !is_array($this->clientAudioBuffers[$clientId])) {
            // No active outgoing stream for this client
            if (isset($this->clientOutgoingStreams[$clientId]) && $this->upstream && $this->upstream->isConnected()) {
                $this->upstream->sendCommand([
                    'command'   => 'stop_stream',
                    'stream_id' => $this->clientOutgoingStreams[$clientId]['stream_id'],
                ]);
                unset($this->clientOutgoingStreams[$clientId]);
            }
            return;
        }

        $buf = &$this->clientAudioBuffers[$clientId];
        $localStreamId = $buf['local_stream_id'];
        $zelloStreamId = $buf['stream_id'];
        $user          = $buf['user'];
        $channel       = $buf['channel'];

        // ── Final extraction pass ──
        // MediaRecorder may buffer frames — do one last full extraction
        // to catch any frames that weren't found during incremental extraction.
        $webmData = $buf['webm_data'];
        $previouslySent = $buf['frames_sent'];

        \plog("[Proxy] Final extraction pass on " . strlen($webmData) . " bytes of WebM data ({$previouslySent} frames sent so far)");

        if (strlen($webmData) > 0) {
            try {
                $extractor = new WebmOpusExtractor();
                $finalFrames = $extractor->extract($webmData);
                $totalFinal = count($finalFrames);

                \plog("[Proxy] Final extraction found {$totalFinal} total frames");

                if ($totalFinal > $previouslySent) {
                    $remainingFrames = array_slice($finalFrames, $previouslySent);
                    $remainingCount = count($remainingFrames);
                    \plog("[Proxy] Sending {$remainingCount} remaining frames to Zello");

                    // Add to all_frames for the .ogg archive
                    foreach ($remainingFrames as $frame) {
                        $buf['all_frames'][] = $frame;
                    }

                    // Send remaining frames to Zello
                    $this->sendOutgoingFrames($buf, $remainingFrames);

                    // Also broadcast remaining frames to browsers
                    if (isset($this->activeStreams[$localStreamId])) {
                        foreach ($remainingFrames as $frame) {
                            $this->activeStreams[$localStreamId]['packets'][] = $frame;
                            $this->activeStreams[$localStreamId]['chunk_buffer'][] = $frame;
                        }
                    }
                }
            } catch (\Exception $e) {
                \plog("[Proxy] Final extraction failed: " . $e->getMessage());
            }
        }

        $allFrames = $buf['all_frames'];

        // Stop the Zello upstream stream (AFTER sending remaining frames)
        if ($zelloStreamId !== null && $this->upstream && $this->upstream->isConnected()) {
            \plog("[Proxy] Stopping Zello stream {$zelloStreamId} (sent {$buf['packet_id']} total packets)");
            $this->upstream->sendCommand([
                'command'   => 'stop_stream',
                'stream_id' => $zelloStreamId,
            ]);
        }

        // Flush remaining browser streaming chunks
        if (isset($this->activeStreams[$localStreamId])) {
            $this->flushStreamChunk($localStreamId, true);
        }

        // Clean up
        unset($this->clientAudioBuffers[$clientId]);
        unset($this->clientOutgoingStreams[$clientId]);

        // Eric beta 2026-06-30 — WebmOpusExtractor returns one entry
        // per Opus PACKET (WebM SimpleBlock). Chrome packs 3× 20ms
        // frames per packet = 60ms/packet; Firefox uses 1× 40ms frame
        // per packet = 40ms/packet. Duration must be counted at the
        // packet level. Detected in handleBrowserAudio from the TOC.
        $packetDurationMs = $buf['packet_duration_ms'] ?? 20;
        $durationMs = count($allFrames) * $packetDurationMs;

        \plog("[Proxy] Outgoing stream from '{$user}' ended: " . count($allFrames) . " packets @ {$packetDurationMs}ms = ~{$durationMs}ms");

        // Remove from activeStreams (was used for browser chunk broadcast)
        unset($this->activeStreams[$localStreamId]);

        if (empty($allFrames)) {
            \plog("[Proxy] Outgoing stream had no audio frames");
            $this->broadcast([
                'type'      => 'voice_stop',
                'stream_id' => $localStreamId,
            ]);
            return;
        }

        // Build complete .ogg file for replay/archive.
        // Browser MediaRecorder Opus is always 48kHz mono; packet duration
        // is browser-dependent ($packetDurationMs, detected above).
        //
        // Eric beta 2026-06-30 (fix 1) — was passing 16000 sample_rate,
        // made stored history playback 3× slow. Fixed to 48000.
        // Eric beta 2026-06-30 (fix 2) — was passing 20 ms hard-coded,
        // made Firefox (40 ms/packet) history playback 2× slow.
        // Eric beta 2026-06-30 (fix 3) — must pass PACKET duration, not
        // frame duration. OggOpusWriter treats each array entry as one
        // Opus packet and increments granule by samplesPerFrame per entry.
        // Chrome packs 3× 20ms frames per packet (60 ms/packet); using
        // frame-ms (20) made 5-sec TX show as 1.5-sec. Now uses
        // $packetDurationMs so granule is correct on both browsers.
        $filename = 'out_' . $localStreamId . '_' . time() . '.ogg';
        $filepath = $this->audioDir . '/' . $filename;
        $audioUrl = 'cache/zello-audio/' . $filename;

        try {
            $writer  = new OggOpusWriter(48000, 1, $packetDurationMs);
            $oggData = $writer->build($allFrames);
            file_put_contents($filepath, $oggData);
            \plog("[Proxy] Saved outgoing audio: {$filepath} (" . strlen($oggData) . " bytes)");

            // DEBUG: Also save raw WebM for quality comparison
            if (!empty($webmData)) {
                $webmFile = $this->audioDir . '/out_' . $localStreamId . '_' . time() . '.webm';
                file_put_contents($webmFile, $webmData);
                \plog("[Proxy] DEBUG: Saved raw WebM: {$webmFile} (" . strlen($webmData) . " bytes)");
            }
        } catch (\Exception $e) {
            \plog("[Proxy] Failed to save outgoing .ogg: " . $e->getMessage());
            $this->broadcast([
                'type'      => 'voice_stop',
                'stream_id' => $localStreamId,
            ]);
            return;
        }

        // Log to database
        $msgId = $this->logMessage([
            'channel'         => $channel,
            'sender_username' => $user,
            'sender_display'  => $user,
            'message_type'    => 'voice',
            'content'         => null,
            'direction'       => 'outgoing',
            'duration_ms'     => $durationMs,
            'media_url'       => $audioUrl,
        ]);

        // Broadcast completed voice message to all browser clients
        $this->broadcast([
            'type'            => 'voice_message',
            'id'              => $msgId,
            'direction'       => 'outgoing',
            'stream_id'       => $localStreamId,
            'channel'         => $channel,
            'sender_username' => $user,
            'sender_display'  => $user,
            'duration_ms'     => $durationMs,
            'audio_url'       => $audioUrl,
            'timestamp'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Handle an incoming audio packet from Zello upstream.
     * Appends the Opus frame to the active stream's packet buffer and
     * sends streaming Ogg chunks to browser clients every N packets.
     */
    /**
     * Phase 100 (2026-07-01) — Handle one incoming image binary from
     * Zello upstream. Zello Work sends image_id=0 in the binaries
     * (empirical, see $pendingImage doc), so we reconcile by arrival
     * order rather than by id.
     *
     * Sequence per Zello API.md: on_image → thumbnail binary → full
     * binary. On rare occasion we may see orphan binaries (a
     * disconnected client's queued image being flushed). We tolerate:
     * if both binaries arrive without meta, finalize with anonymous
     * metadata after both are in.
     */
    public function handleImagePacket(int $imageId, int $imageType, string $jpegBytes): void
    {
        if ($this->pendingImage === null) {
            $this->pendingImage = [
                'meta'    => [],
                'meta_id' => 0,
                'thumb'   => null,
                'full'    => null,
                'started' => time(),
            ];
        }
        // If this is a NEW image (both binaries already present from a
        // previous cycle), flush the old one first — finalize it as
        // orphan then start fresh.
        if ($imageType === 2 && $this->pendingImage['thumb'] !== null) {
            \plog("[Proxy] New thumbnail arrived before prior finalize — flushing prior slot as orphan.");
            $this->tryFinalizePendingImage(true);
            $this->pendingImage = [
                'meta'    => [],
                'meta_id' => 0,
                'thumb'   => null,
                'full'    => null,
                'started' => time(),
            ];
        }
        if ($imageType === 2) {
            $this->pendingImage['thumb'] = $jpegBytes;
        } elseif ($imageType === 1) {
            $this->pendingImage['full'] = $jpegBytes;
        } else {
            \plog("[Proxy] Unexpected image_type={$imageType} (raw id={$imageId}) — ignoring binary.");
            return;
        }
        $this->tryFinalizePendingImage();
    }

    /**
     * Phase 100 — finalize the pending image if both binaries are in.
     * Meta is nice-to-have; without it we fall back to sender=unknown.
     *
     * @param bool $force Finalize even if a binary is missing (used
     *   when a new image starts before the old one finished — the old
     *   is toast, log it and move on).
     */
    private function tryFinalizePendingImage(bool $force = false): void
    {
        if ($this->pendingImage === null) return;
        $buf = $this->pendingImage;
        $haveThumb = $buf['thumb'] !== null;
        $haveFull  = $buf['full']  !== null;
        if (!$force && (!$haveThumb || !$haveFull)) {
            return; // still waiting for a binary
        }
        // Under force, if both binaries missing there's nothing to do.
        if ($force && !$haveThumb && !$haveFull) {
            $this->pendingImage = null;
            return;
        }

        $meta = $buf['meta'];
        $from = (string) ($meta['from'] ?? 'unknown');
        $ch   = (string) ($meta['channel'] ?? '');
        $w    = (int) ($meta['width']  ?? 0);
        $h    = (int) ($meta['height'] ?? 0);
        // Phase 101 (Eric beta 2026-07-01) — dedup by content hash.
        // Zello Work re-sends undelivered images on every proxy
        // reconnect; without dedup one "sticky" orphan reappears
        // forever. If we've already seen this exact byte sequence,
        // drop silently (log only) — do NOT save, DB-log, or
        // broadcast. Persisted set survives proxy restarts.
        if ($haveFull && $this->imageAlreadyDelivered($buf['full'])) {
            \plog('[Proxy] Duplicate image (already-delivered hash) — dropping ' . strlen($buf['full']) . 'B silently.');
            $this->pendingImage = null;
            return;
        }

        // Use the on_image's message_id as the persistent id for
        // storage; fall back to a unix-microsecond synth if none.
        $storeId = $buf['meta_id'] > 0 ? $buf['meta_id'] : (int) (microtime(true) * 1000);
        $ts = time();

        $baseName  = "in_z_{$storeId}_{$ts}";
        $fullFile  = $this->imageDir . '/' . $baseName . '.jpg';
        $thumbFile = $this->imageDir . '/' . $baseName . '.thumb.jpg';
        $fullUrl   = 'cache/zello-images/' . $baseName . '.jpg';
        try {
            if ($haveFull)  file_put_contents($fullFile,  $buf['full']);
            if ($haveThumb) file_put_contents($thumbFile, $buf['thumb']);
        } catch (\Exception $e) {
            \plog("[Proxy] Failed to write image files for storeId={$storeId}: " . $e->getMessage());
        }

        // Record hash so the next Zello redelivery is a no-op.
        if ($haveFull) $this->markImageDelivered($buf['full']);

        $metaFlag = empty($meta) ? ' [ORPHAN — no on_image seen]' : '';
        \plog("[Proxy] Image finalized: storeId={$storeId} from='{$from}' channel='{$ch}' {$w}x{$h} thumb=" . ($haveThumb ? strlen($buf['thumb']) . 'B' : 'MISSING') . " full=" . ($haveFull ? strlen($buf['full']) . 'B' : 'MISSING') . $metaFlag);

        // Persist to chat_messages (message_type='image', media_url=full)
        $msgId = 0;
        try {
            $msgId = (int) $this->logMessage([
                'channel'         => $ch,
                'sender_username' => $from,
                'sender_display'  => $meta['display_name'] ?? $from,
                'message_type'    => 'image',
                'content'         => null,
                'direction'       => 'incoming',
                'media_url'       => $fullUrl,
            ]);
        } catch (\Exception $e) {
            \plog("[Proxy] logMessage failed for image storeId={$storeId}: " . $e->getMessage());
        }

        // Broadcast to widgets. Thumb inline as data URI so the card
        // renders instantly; full via URL for click-to-expand. If
        // thumb missing, use full URL for both (browser will scale).
        $thumbPayload = $haveThumb
            ? 'data:image/jpeg;base64,' . base64_encode($buf['thumb'])
            : $fullUrl;
        $this->broadcast([
            'type'            => 'image_message',
            'id'              => $msgId,
            'direction'       => 'incoming',
            'image_id'        => $storeId,
            'channel'         => $ch,
            'sender_username' => $from,
            'sender_display'  => $meta['display_name'] ?? $from,
            'width'           => $w,
            'height'          => $h,
            'thumb'           => $thumbPayload,
            'full_url'        => $fullUrl,
            'timestamp'       => date('Y-m-d H:i:s'),
        ]);

        $this->pendingImage = null;
    }

    public function handleAudioPacket(int $streamId, int $packetId, string $opusData): void
    {
        if (!isset($this->activeStreams[$streamId])) {
            // Stream not tracked — may have started before proxy connected
            \plog("[Proxy] Audio packet for unknown stream {$streamId}, ignoring");
            return;
        }

        $this->activeStreams[$streamId]['packets'][] = $opusData;
        $this->activeStreams[$streamId]['chunk_buffer'][] = $opusData;

        // Send a streaming chunk every N packets (~300ms at 60ms/frame with chunk size 5)
        if (count($this->activeStreams[$streamId]['chunk_buffer']) >= $this->streamChunkSize) {
            $this->flushStreamChunk($streamId, false);
        }

        // Log every 50 packets for progress visibility
        $count = count($this->activeStreams[$streamId]['packets']);
        if ($count % 50 === 0) {
            $from = $this->activeStreams[$streamId]['from'];
            \plog("[Proxy] Stream {$streamId} from '{$from}': {$count} packets received");
        }
    }

    /**
     * Flush buffered audio packets as a WebM cluster for MSE streaming.
     *
     * On first flush for a stream, sends the WebM init segment (EBML header +
     * Tracks) so the browser can set up its MediaSource/SourceBuffer.
     * Subsequent flushes send only Cluster elements. The browser's Opus decoder
     * maintains state across clusters, giving seamless, artifact-free playback.
     */
    private function flushStreamChunk(int $streamId, bool $isLast): void
    {
        if (!isset($this->activeStreams[$streamId])) return;

        $buffer = $this->activeStreams[$streamId]['chunk_buffer'];
        if (empty($buffer)) return;

        $stream = $this->activeStreams[$streamId];

        // Create WebM writer for this stream's parameters.
        // Phase 99al — use packet_duration_ms (real per-packet audio ms)
        // not frame_duration_ms (single-frame ms inside the packet).
        // WebmStreamWriter's third arg drives cluster timestamps → must
        // match actual per-packet audio length or MediaSource stalls.
        // Falls back to frame_duration_ms for streams pre-99al.
        $webmPacketMs = $stream['packet_duration_ms'] ?? $stream['frame_duration_ms'];
        $webmWriter = new WebmStreamWriter(
            $stream['sample_rate'],
            1,
            $webmPacketMs
        );

        // Track timestamp position for this stream
        if (!isset($this->activeStreams[$streamId]['mse_timestamp_ms'])) {
            $this->activeStreams[$streamId]['mse_timestamp_ms'] = 0;
        }
        $timestampMs = $this->activeStreams[$streamId]['mse_timestamp_ms'];

        // Build init segment on first flush
        $initData = null;
        if (empty($this->activeStreams[$streamId]['mse_init_sent'])) {
            $initData = $webmWriter->getInitSegment();
            $this->activeStreams[$streamId]['mse_init_sent'] = true;
        }

        // Build cluster from buffered frames
        $clusterData = $webmWriter->buildCluster($buffer, $timestampMs);

        // Advance timestamp — Phase 99al, use packet_ms (see above).
        $this->activeStreams[$streamId]['mse_timestamp_ms'] =
            $timestampMs + (count($buffer) * $webmPacketMs);

        // Clear the chunk buffer
        $this->activeStreams[$streamId]['chunk_buffer'] = [];

        // Send to all authenticated browser clients
        $msg = [
            'type'      => 'voice_chunk',
            'stream_id' => $streamId,
            'is_last'   => $isLast,
        ];

        if ($initData !== null) {
            // First chunk: send init + cluster together
            $msg['webm_init'] = base64_encode($initData);
        }
        $msg['webm_data'] = base64_encode($clusterData);

        // For outgoing streams, skip the client that is transmitting (avoid echo)
        $skipClientId = null;
        if (!empty($stream['is_outgoing']) && !empty($stream['source_client_id'])) {
            $skipClientId = $stream['source_client_id'];
        }

        $chunkMsg = json_encode($msg);
        foreach ($this->clients as $client) {
            $cid = $client->resourceId;
            if (isset($this->clientAuth[$cid]) && $cid !== $skipClientId) {
                $client->send($chunkMsg);
            }
        }
    }

    /**
     * Finalize a voice stream — flush remaining audio, save .ogg file, log to DB, broadcast to clients.
     */
    private function finalizeStream(int $streamId): void
    {
        if (!isset($this->activeStreams[$streamId])) {
            \plog("[Proxy] Stream stop for unknown stream {$streamId}");
            $this->broadcast([
                'type'      => 'voice_stop',
                'stream_id' => $streamId,
            ]);
            return;
        }

        // Flush any remaining buffered packets as the final chunk (with EOS flag)
        $this->flushStreamChunk($streamId, true);

        $stream  = $this->activeStreams[$streamId];
        $packets = $stream['packets'];
        $from    = $stream['from'];
        $display = $stream['display_name'];
        $channel = $stream['channel'];

        // Calculate duration — Phase 99al, use packet_duration_ms
        // (real per-packet audio ms). frame_duration_ms was only the
        // single-frame value which under-counts on multi-frame packets.
        $streamPacketMs = $stream['packet_duration_ms'] ?? $stream['frame_duration_ms'];
        $durationMs = count($packets) * $streamPacketMs;

        \plog("[Proxy] Stream {$streamId} from '{$from}' ended: " . count($packets) . " packets @ {$streamPacketMs}ms = ~{$durationMs}ms");

        // Remove from active streams
        unset($this->activeStreams[$streamId]);

        if (empty($packets)) {
            \plog("[Proxy] Stream {$streamId} had no audio packets, skipping");
            $this->broadcast([
                'type'      => 'voice_stop',
                'stream_id' => $streamId,
            ]);
            return;
        }

        // Build complete .ogg file from all packets for replay/archive
        $filename = $streamId . '_' . time() . '.ogg';
        $filepath = $this->audioDir . '/' . $filename;
        $audioUrl = 'cache/zello-audio/' . $filename;

        try {
            // Phase 99al — packet_ms for the OGG writer's granule math.
            $writer  = new OggOpusWriter($stream['sample_rate'], 1, $streamPacketMs);
            $oggData = $writer->build($packets);
            file_put_contents($filepath, $oggData);
            \plog("[Proxy] Saved audio: {$filepath} (" . strlen($oggData) . " bytes)");
        } catch (\Exception $e) {
            \plog("[Proxy] Failed to save .ogg file: " . $e->getMessage());
            $this->broadcast([
                'type'      => 'voice_stop',
                'stream_id' => $streamId,
            ]);
            return;
        }

        // Log to database
        $msgId = $this->logMessage([
            'channel'         => $channel,
            'sender_username' => $from,
            'sender_display'  => $display,
            'message_type'    => 'voice',
            'content'         => null,
            'direction'       => 'incoming',
            'duration_ms'     => $durationMs,
            'media_url'       => $audioUrl,
        ]);

        // Broadcast completed voice message to browser clients (with replay URL)
        $this->broadcast([
            'type'            => 'voice_message',
            'id'              => $msgId,
            'direction'       => 'incoming',
            'stream_id'       => $streamId,
            'channel'         => $channel,
            'sender_username' => $from,
            'sender_display'  => $display,
            'duration_ms'     => $durationMs,
            'audio_url'       => $audioUrl,
            'timestamp'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Broadcast a message to all authenticated browser clients.
     */
    private function broadcast(array $message): void
    {
        $json = json_encode($message);
        foreach ($this->clients as $client) {
            $id = $client->resourceId;
            if (isset($this->clientAuth[$id])) {
                $client->send($json);
            }
        }
    }

    /**
     * Phase D (messaging-send-gaps-2026-06) — drain the zello_outbox queue.
     *
     * The web process (router) cannot reach this daemon's WebSocket event
     * loop, so a routed Zello send is queued in `zello_outbox`. This is
     * called on a periodic loop timer (see zello-proxy.php). It claims one
     * queued row at a time, relays it upstream, and marks it sent/failed —
     * it does NOT fake success: a row stays 'failed' with an error if the
     * upstream relay can't happen.
     *
     * Channel text is relayed via sendTextMessage($body, $channel). A row
     * with a non-empty `recipient` is a user-DM: relayed via
     * sendTextMessage($body, $channel, $recipient), which sets the Zello
     * `for` field so only that user receives it (Phase E). A DM still needs
     * a channel context, so an empty channel falls back to the default
     * dispatch channel exactly as a channel send does.
     */
    public function pollZelloOutbox(): void
    {
        // Nothing to do until we can actually relay upstream.
        if (!$this->upstream || !$this->upstream->isConnected()) {
            return;
        }

        try {
            $this->ensureDb();

            // Claim the oldest queued row (race-tolerant: UPDATE ... WHERE
            // status='queued' then re-read only if we won the claim).
            // `kind` ('text' default | 'tts') decides how we relay it: a text
            // row goes out as a Zello text message; a 'tts' row is synthesised
            // to speech and keyed onto the channel as Opus audio (Gap 1).
            $row = $this->pdo->query(
                "SELECT `id`, `kind`, `channel`, `recipient`, `body`
                   FROM `{$this->prefix}zello_outbox`
                  WHERE `status` = 'queued'
                  ORDER BY `queued_at` ASC, `id` ASC
                  LIMIT 1"
            )->fetch();
            if (!$row) {
                return;
            }
            $oid = (int) $row['id'];

            $claim = $this->pdo->prepare(
                "UPDATE `{$this->prefix}zello_outbox`
                    SET `status` = 'claimed', `claimed_at` = NOW()
                  WHERE `id` = ? AND `status` = 'queued'"
            );
            $claim->execute([$oid]);
            if ($claim->rowCount() !== 1) {
                return; // lost the race — another tick will get the next one
            }

            $kind      = trim((string) ($row['kind'] ?? 'text'));
            $channel   = trim((string) ($row['channel'] ?? ''));
            $recipient = trim((string) ($row['recipient'] ?? ''));
            $body      = (string) ($row['body'] ?? '');
            if ($channel === '') {
                $channel = $this->config['zello_dispatch_channel'] ?? '';
            }

            // ── Gap 1: a 'tts' row → synthesise + key audio onto the channel ──
            // Audio TX has no per-user `for` field — Zello voice streams go to
            // the whole channel — so the recipient is informational only here.
            // The synth + stream is handled end-to-end by relayTtsOutbox, which
            // marks the row sent/failed itself (it spans async start_stream).
            if ($kind === 'tts') {
                $this->relayTtsOutbox($oid, $channel, $body);
                return;
            }

            // A non-empty recipient is a user-DM (Zello `for` field); empty =
            // channel broadcast. Both still carry a channel context.
            $ok = $this->upstream->sendTextMessage($body, $channel, $recipient);

            if ($ok) {
                $this->pdo->prepare(
                    "UPDATE `{$this->prefix}zello_outbox`
                        SET `status` = 'sent', `completed_at` = NOW()
                      WHERE `id` = ?"
                )->execute([$oid]);

                $destLabel = $recipient !== ''
                    ? "{$channel}/@{$recipient}"
                    : $channel;

                // Mirror into zello_messages so the radio UI shows it, and
                // echo to connected browser clients. A DM records the
                // recipient username so the history/inbox can thread it.
                $this->logMessage([
                    'channel'         => $channel,
                    'sender_username' => 'router',
                    'sender_display'  => 'Router',
                    'message_type'    => 'text',
                    'content'         => $body,
                    'direction'       => 'outgoing',
                    'recipient'       => $recipient,
                ]);
                $this->broadcast([
                    'type'            => 'text_message',
                    'direction'       => 'outgoing',
                    'sender_username' => 'router',
                    'sender_display'  => 'Router',
                    'channel'         => $channel,
                    'recipient'       => $recipient,
                    'text'            => $body,
                    'timestamp'       => date('Y-m-d H:i:s'),
                ]);
                \plog("[Proxy] zello_outbox #{$oid} relayed to '{$destLabel}'");
            } else {
                $this->pdo->prepare(
                    "UPDATE `{$this->prefix}zello_outbox`
                        SET `status` = 'failed', `error` = ?, `completed_at` = NOW()
                      WHERE `id` = ?"
                )->execute(['upstream relay failed (not connected/authenticated)', $oid]);
                \plog("[Proxy] zello_outbox #{$oid} relay FAILED");
            }
        } catch (\Exception $e) {
            \plog("[Proxy] pollZelloOutbox error: " . $e->getMessage());
        }
    }

    /**
     * Gap 1 (zello-config-video-brief.md) — relay one TTS outbox row: type
     * text → synthesise speech → key it onto the Zello channel as Opus audio.
     *
     * Pipeline (reuses the proven mic path's encode/frame/stream machinery so
     * there is NO new Opus encoder and NO new stream protocol code):
     *
     *   Piper(text)  → 16 kHz mono s16le PCM
     *   ffmpeg       → WebM/Opus (libopus, 16 kHz mono, 20 ms frames)
     *   WebmOpusExtractor → the same raw Opus frames the browser path extracts
     *   start_stream → binary 0x01 packets (one per frame, paced realtime)
     *                  → stop_stream
     *
     * This spans an async start_stream handshake (Zello assigns the stream_id
     * in a later upstream event), so this method does NOT mark the row
     * sent/failed inline on success — it stashes the frames keyed by the
     * command seq in $pendingTtsStarts and finishes in handleUpstreamEvent
     * once the stream_id arrives (or marks failed on a start_stream error).
     * Any *synth* failure (Piper missing, ffmpeg error, empty audio) marks the
     * row 'failed' here and never throws — the proxy must never crash on TTS.
     */
    private function relayTtsOutbox(int $oid, string $channel, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            $this->markOutbox($oid, 'failed', 'empty text');
            return;
        }
        if (!$this->upstream || !$this->upstream->isConnected()) {
            // Leave it claimable again would be ideal, but we already claimed
            // it; mark failed so it doesn't wedge. A retry can re-queue.
            $this->markOutbox($oid, 'failed', 'upstream not connected');
            return;
        }
        if ($channel === '') {
            $channel = $this->config['zello_dispatch_channel'] ?? '';
        }

        // ── 1. Synthesise + encode → Opus frames (never throws) ──
        try {
            $frames = $this->synthesizeTtsFrames($text);
        } catch (\Throwable $e) {
            \plog("[Proxy] TTS synth threw for outbox #{$oid}: " . $e->getMessage());
            $this->markOutbox($oid, 'failed', 'synth error: ' . substr($e->getMessage(), 0, 180));
            return;
        }
        if ($frames === null) {
            // Reason already plogged + row marked by synthesizeTtsFrames.
            $this->markOutbox($oid, 'failed', 'tts not configured or synth produced no audio');
            return;
        }
        if (empty($frames)) {
            $this->markOutbox($oid, 'failed', 'no audio frames extracted');
            return;
        }

        \plog("[Proxy] TTS outbox #{$oid}: " . count($frames) . " Opus frames, opening stream on '{$channel}'");

        // ── 2. Open the Zello voice stream (same codec_header as the mic path:
        //        16 kHz, 1 channel, 20 ms frames) ──
        $cfg = $this->ttsConfig;
        $sampleRate = (int) ($cfg['sample_rate'] ?? 16000);
        $frameMs    = (int) ($cfg['frame_ms'] ?? 20);
        try {
            $seq = $this->upstream->sendCommand([
                'command'         => 'start_stream',
                'channel'         => $channel,
                'type'            => 'audio',
                'codec'           => 'opus',
                'codec_header'    => base64_encode(pack('v', $sampleRate) . chr(1) . chr($frameMs)),
                'packet_duration' => $frameMs,
            ]);
        } catch (\Throwable $e) {
            \plog("[Proxy] TTS start_stream send failed for #{$oid}: " . $e->getMessage());
            $this->markOutbox($oid, 'failed', 'start_stream send failed');
            return;
        }

        // Stash everything the stream_id response needs to finish the send.
        $this->ttsStreamCounter++;
        $this->pendingTtsStarts[$seq] = [
            'frames'      => $frames,
            'channel'     => $channel,
            'text'        => $text,
            'outbox_id'   => $oid,
            'frame_ms'    => $frameMs,
            'local_id'    => $this->ttsStreamCounter,
            'started'     => time(),
        ];
    }

    /**
     * Gap 1 — once Zello assigns a stream_id for a TTS start_stream, pace the
     * pre-synthesised Opus frames onto the stream in real time, then
     * stop_stream and mark the outbox row sent. Called from handleUpstreamEvent.
     */
    private function beginTtsStream(int $seq, int $zelloStreamId): void
    {
        if (!isset($this->pendingTtsStarts[$seq])) {
            return;
        }
        $pending = $this->pendingTtsStarts[$seq];
        unset($this->pendingTtsStarts[$seq]);

        $frames  = $pending['frames'];
        $oid     = (int) $pending['outbox_id'];
        $channel = (string) $pending['channel'];
        $text    = (string) $pending['text'];
        $frameMs = (int) ($pending['frame_ms'] ?? 20);

        \plog("[Proxy] TTS stream {$zelloStreamId} opened for outbox #{$oid} — pacing " . count($frames) . " frames");

        // Pace frames onto the stream at the real audio rate. Zello (like the
        // DMR USRP path) expects each 20 ms voice frame to arrive ~20 ms apart;
        // dumping them all at once makes the upstream drop the burst. We use a
        // periodic React timer so the event loop keeps serving other clients
        // while a TTS clip streams out.
        $total    = count($frames);
        $idx      = 0;
        $packetId = 0;
        $state    = [
            'frames'    => $frames,
            'idx'       => 0,
            'packet_id' => 0,
            'stream_id' => $zelloStreamId,
            'oid'       => $oid,
            'channel'   => $channel,
            'text'      => $text,
            'total'     => $total,
            'frame_ms'  => $frameMs,
        ];

        $self  = $this;
        $intervalS = max(0.005, $frameMs / 1000.0);
        $timer = $this->loop->addPeriodicTimer($intervalS, function () use (&$state, $self) {
            $self->pumpTtsFrame($state);
        });
        $state['timer'] = $timer;
        // Stash the live state on the object so onClose / shutdown could cancel
        // it if needed; keyed by stream id.
        $this->pendingTtsStarts['live_' . $zelloStreamId] = &$state;
    }

    /**
     * Gap 1 — emit ONE TTS frame per timer tick onto an open Zello stream;
     * stop the stream + finalise the outbox row after the last frame.
     * Public only so the periodic-timer closure can reach it; not a command.
     */
    public function pumpTtsFrame(array &$state): void
    {
        try {
            if (!$this->upstream || !$this->upstream->isConnected()) {
                // Upstream dropped mid-clip — stop the timer, fail the row.
                if (isset($state['timer'])) $this->loop->cancelTimer($state['timer']);
                unset($this->pendingTtsStarts['live_' . $state['stream_id']]);
                $this->markOutbox((int) $state['oid'], 'failed', 'upstream lost mid-stream');
                return;
            }

            if ($state['idx'] < $state['total']) {
                $frame  = $state['frames'][$state['idx']];
                $packet = chr(0x01)
                    . pack('N', $state['stream_id'])
                    . pack('N', $state['packet_id'])
                    . $frame;
                $this->upstream->sendBinary($packet);
                $state['idx']++;
                $state['packet_id']++;
                return;
            }

            // All frames sent — stop the stream and finalise.
            if (isset($state['timer'])) $this->loop->cancelTimer($state['timer']);
            unset($this->pendingTtsStarts['live_' . $state['stream_id']]);

            $this->upstream->sendCommand([
                'command'   => 'stop_stream',
                'stream_id' => $state['stream_id'],
            ]);

            $durationMs = $state['total'] * (int) $state['frame_ms'];
            $this->markOutbox((int) $state['oid'], 'sent', null);

            // Mirror into zello_messages + echo to browsers so the radio UI
            // shows the spoken broadcast (as a voice row with the text).
            $this->logMessage([
                'channel'         => $state['channel'],
                'sender_username' => 'dispatch-tts',
                'sender_display'  => 'Dispatch (spoken)',
                'message_type'    => 'voice',
                'content'         => $state['text'],
                'direction'       => 'outgoing',
                'duration_ms'     => $durationMs,
            ]);
            $this->broadcast([
                'type'            => 'text_message',
                'direction'       => 'outgoing',
                'sender_username' => 'dispatch-tts',
                'sender_display'  => 'Dispatch (spoken)',
                'channel'         => $state['channel'],
                'text'            => '🔊 ' . $state['text'],
                'timestamp'       => date('Y-m-d H:i:s'),
            ]);
            \plog("[Proxy] TTS outbox #{$state['oid']} streamed {$state['total']} frames (~{$durationMs}ms), stream stopped");
        } catch (\Throwable $e) {
            // Never let a timer tick crash the proxy.
            if (isset($state['timer'])) {
                try { $this->loop->cancelTimer($state['timer']); } catch (\Throwable $e2) {}
            }
            unset($this->pendingTtsStarts['live_' . $state['stream_id']]);
            \plog("[Proxy] pumpTtsFrame error on outbox #{$state['oid']}: " . $e->getMessage());
            $this->markOutbox((int) $state['oid'], 'failed', 'stream pump error');
        }
    }

    /**
     * Gap 1 — synthesise `text` to a list of raw Opus frames, ready to feed
     * the same start_stream/binary-packet path the browser mic uses.
     *
     * Steps: Piper → 16 kHz mono s16le PCM (stdin text, stdout raw); ffmpeg →
     * WebM/Opus at the proxy's stream sample-rate/frame-size; then the existing
     * WebmOpusExtractor pulls the Opus frames back out. ffmpeg is confirmed
     * present on the proxy host (with libopus); Piper is NOT bundled and is a
     * configurable binary — when it's absent we return null (caller fails the
     * row) and plog the reason, so "type text → spoken" simply reports
     * "TTS not configured" until an admin installs Piper on the proxy host.
     *
     * @return array|null  Opus frame byte-strings, or null when synth can't run.
     */
    private function synthesizeTtsFrames(string $text): ?array
    {
        $cfg = $this->loadTtsConfig();
        $ffmpegBin  = $cfg['ffmpeg_bin'];
        $sampleRate = (int) $cfg['sample_rate'];

        // ── Phase 113e — resolve the 'zello_readout' speech application's
        //    engine via the TTS registry (the Voice & Speech page). The
        //    registry returns s16le mono PCM at the requested rate. If ANY
        //    part of the registry path fails (not loaded, DB stale in this
        //    long-running daemon, engine misconfigured/unreachable), we fall
        //    straight through to the proxy's own inline Piper below — so this
        //    can never do worse than the pre-registry behaviour. ──
        $pcm = null;
        $pcmRate = $sampleRate;
        try {
            if (!function_exists('tts_synthesize') && is_file(__DIR__ . '/../inc/tts/engine.php')) {
                require_once __DIR__ . '/../inc/tts/engine.php';
            }
            if (function_exists('tts_synthesize')) {
                $reg = tts_synthesize('zello_readout', $text, ['rate' => $sampleRate]);
                if (!empty($reg['ok']) && $reg['pcm'] !== '') {
                    $pcm = $reg['pcm'];
                    $pcmRate = (int) ($reg['rate'] ?? $sampleRate);
                    \plog("[Proxy] TTS via registry engine '" . ($reg['engine'] ?? '?') . "'");
                }
            }
        } catch (\Throwable $e) {
            \plog("[Proxy] TTS registry path failed (" . $e->getMessage() . ") — inline Piper fallback");
            $pcm = null;
        }

        // ── Fallback: the proxy's own inline Piper (cached config, no DB) ──
        if ($pcm === null) {
            $piperBin   = $cfg['piper_bin'];
            $piperVoice = $cfg['piper_voice'];
            if ($piperBin === '' || $piperVoice === '') {
                \plog("[Proxy] TTS not configured (registry produced nothing; zello_tts_piper_bin/voice unset)");
                return null;
            }
            if (!is_file($piperBin) && !$this->binOnPath($piperBin)) {
                \plog("[Proxy] TTS piper binary not found: '{$piperBin}'");
                return null;
            }
            if (!is_file($piperVoice)) {
                \plog("[Proxy] TTS piper voice model not found: '{$piperVoice}'");
                return null;
            }
            $piperCmd = escapeshellarg($piperBin) . ' -m ' . escapeshellarg($piperVoice) . ' --output-raw';
            $pcm = $this->runPipe($piperCmd, $text, 30);
            if ($pcm === null || $pcm === '') {
                \plog("[Proxy] Piper produced no PCM");
                return null;
            }
            $pcmRate = (int) ($cfg['piper_rate'] ?? 22050);
        }

        // ── ffmpeg: raw PCM(pcmRate) → WebM/Opus at the Zello stream rate,
        //    mono, 20 ms frames. ──
        $ffmpegCmd = escapeshellarg($ffmpegBin)
            . ' -hide_banner -loglevel error'
            . ' -f s16le -ar ' . $pcmRate . ' -ac 1 -i pipe:0'
            . ' -ar ' . $sampleRate . ' -ac 1'
            . ' -c:a libopus -b:a 24k -frame_duration 20 -application voip'
            . ' -f webm pipe:1';
        $webm = $this->runPipe($ffmpegCmd, $pcm, 20);
        if ($webm === null || $webm === '') {
            \plog("[Proxy] ffmpeg produced no WebM/Opus");
            return null;
        }

        // ── 3. Reuse the browser path's extractor to pull Opus frames. ──
        try {
            $extractor = new WebmOpusExtractor();
            $frames = $extractor->extract($webm);
        } catch (\Throwable $e) {
            \plog("[Proxy] WebM extraction failed for TTS: " . $e->getMessage());
            return null;
        }

        // Strip any leading DTX/comfort-noise frames (<=6 bytes), mirroring the
        // mic path, so the clip starts on real audio.
        while (!empty($frames) && strlen($frames[0]) <= 6) {
            array_shift($frames);
        }
        return $frames;
    }

    /**
     * Gap 1 — load (and cache) the TTS settings from the settings table.
     * All keys optional; sensible defaults. `ffmpeg` defaults to PATH lookup.
     */
    private function loadTtsConfig(): array
    {
        if ($this->ttsConfig !== null) {
            return $this->ttsConfig;
        }
        // The proxy already loaded all zello_* settings into $this->config at
        // construction; the TTS keys live there too (zello_tts_*).
        $c = $this->config;
        $this->ttsConfig = [
            'piper_bin'   => trim((string) ($c['zello_tts_piper_bin'] ?? '')),
            'piper_voice' => trim((string) ($c['zello_tts_piper_voice'] ?? '')),
            'piper_rate'  => (int) ($c['zello_tts_piper_rate'] ?? 22050),
            'ffmpeg_bin'  => trim((string) ($c['zello_tts_ffmpeg_bin'] ?? '')) ?: 'ffmpeg',
            'sample_rate' => (int) ($c['zello_tts_sample_rate'] ?? 16000),
            'frame_ms'    => (int) ($c['zello_tts_frame_ms'] ?? 20),
        ];
        return $this->ttsConfig;
    }

    /** Gap 1 — is a bare command name resolvable on PATH? (which/where). */
    private function binOnPath(string $bin): bool
    {
        if ($bin === '' || strpbrk($bin, "/\\") !== false) {
            return false; // a path was given; is_file() handles that case
        }
        $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'command -v';
        $out = @shell_exec($which . ' ' . escapeshellarg($bin) . ' 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }

    /**
     * Gap 1 — run a shell command, write $input to stdin, return stdout (binary
     * safe). Returns null on spawn failure or non-zero exit. Bounded by $timeoutS
     * so a wedged synth can't block the event loop forever.
     */
    private function runPipe(string $cmd, string $input, int $timeoutS): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],   // stdin
            1 => ['pipe', 'w'],   // stdout
            2 => ['pipe', 'w'],   // stderr
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            \plog("[Proxy] runPipe: failed to spawn: {$cmd}");
            return null;
        }

        // Write all input, then close stdin so the child sees EOF.
        if ($input !== '') {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutS;
        do {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') $stdout .= $chunk;
            $errc = fread($pipes[2], 8192);
            if ($errc !== false && $errc !== '') $stderr .= $errc;

            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Drain any remaining buffered output.
                while (($chunk = fread($pipes[1], 65536)) !== false && $chunk !== '') $stdout .= $chunk;
                break;
            }
            if (microtime(true) > $deadline) {
                \plog("[Proxy] runPipe: timeout, killing: {$cmd}");
                proc_terminate($proc, 9);
                fclose($pipes[1]); fclose($pipes[2]);
                proc_close($proc);
                return null;
            }
            usleep(5000);
        } while (true);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            \plog("[Proxy] runPipe: exit {$exit}: " . trim(substr($stderr, 0, 300)));
            return null;
        }
        return $stdout;
    }

    /** Gap 1 — set an outbox row's terminal status (sent/failed). Best effort. */
    private function markOutbox(int $oid, string $status, ?string $error): void
    {
        try {
            $this->ensureDb();
            $this->pdo->prepare(
                "UPDATE `{$this->prefix}zello_outbox`
                    SET `status` = ?, `error` = ?, `completed_at` = NOW()
                  WHERE `id` = ?"
            )->execute([$status, $error !== null ? substr($error, 0, 255) : null, $oid]);
        } catch (\Throwable $e) {
            \plog("[Proxy] markOutbox #{$oid} → {$status} failed: " . $e->getMessage());
        }
    }

    /**
     * Log a Zello message to the zello_messages table.
     *
     * @return int|null  The inserted message ID, or null on failure
     */
    private function logMessage(array $msg): ?int
    {
        try {
            $this->ensureDb();

            // Phase E: the `recipient` column (DM partner username) is added
            // by sql/run_zello_dm.php and may be absent on a pre-migration
            // install. Probe once per process and INSERT it only when present
            // so the proxy keeps logging on an un-migrated DB.
            if ($this->hasRecipientCol === null) {
                try {
                    $probe = $this->pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME = '{$this->prefix}zello_messages'
                            AND COLUMN_NAME = 'recipient'"
                    );
                    $this->hasRecipientCol = (bool) $probe->fetchColumn();
                } catch (\Exception $e) {
                    $this->hasRecipientCol = false;
                }
            }

            if ($this->hasRecipientCol) {
                $sql = "INSERT INTO `{$this->prefix}zello_messages`
                        (`channel`, `recipient`, `direction`, `message_type`,
                         `sender_username`, `sender_display`,
                         `content`, `incident_id`,
                         `duration_ms`, `media_url`, `created`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $params = [
                    $msg['channel'] ?? '',
                    $msg['recipient'] ?? '',
                    $msg['direction'] ?? 'incoming',
                    $msg['message_type'] ?? 'text',
                    $msg['sender_username'] ?? '',
                    $msg['sender_display'] ?? '',
                    $msg['content'] ?? null,
                    $msg['incident_id'] ?? null,
                    $msg['duration_ms'] ?? null,
                    $msg['media_url'] ?? null,
                ];
            } else {
                $sql = "INSERT INTO `{$this->prefix}zello_messages`
                        (`channel`, `direction`, `message_type`,
                         `sender_username`, `sender_display`,
                         `content`, `incident_id`,
                         `duration_ms`, `media_url`, `created`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $params = [
                    $msg['channel'] ?? '',
                    $msg['direction'] ?? 'incoming',
                    $msg['message_type'] ?? 'text',
                    $msg['sender_username'] ?? '',
                    $msg['sender_display'] ?? '',
                    $msg['content'] ?? null,
                    $msg['incident_id'] ?? null,
                    $msg['duration_ms'] ?? null,
                    $msg['media_url'] ?? null,
                ];
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return (int) $this->pdo->lastInsertId();
        } catch (\Exception $e) {
            \plog("[Proxy] Failed to log message: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Phase: Zello shared-location → unit-tracking map.
     *
     * Take a Zello `on_location` event (sender username + lat/lng) and
     * make it show up on the existing dispatch map by reusing the same
     * storage every other location provider uses:
     *
     *   1. Resolve the Zello `username` → a TicketsCAD member via
     *      member_comm_identifiers (the reverse of inc/comm_resolve.php —
     *      comm mode `zello` keys its address under `username`).
     *   2. Map that member → a responder (unit) via an active
     *      unit_personnel_assignments row, else responder.personal_for_member_id.
     *   3. Write a `location_reports` row tagged with the `zello`
     *      provider, unit_identifier = the username (matching the
     *      provider/binding convention the map joins on).
     *   4. Upsert a `unit_location_bindings` row so the map's
     *      `all_units` query (which JOINs reports→bindings) surfaces it.
     *
     * Every step is guarded: a missing zello provider, an unknown
     * sender, an unassigned member, or a bad coordinate is logged via
     * plog and skipped — the proxy must never crash on a location event.
     *
     * Storage is fully reused (no parallel table): location_reports +
     * unit_location_bindings + location_providers, source tag 'zello'.
     */
    private function persistZelloLocation(string $from, $lat, $lng): void
    {
        $from = trim($from);
        if ($from === '' || $from === 'unknown') {
            return;
        }

        // Coordinates must be present and numeric. location_reports.lat/lng
        // are NOT NULL, so a missing/garbage fix is skipped (not stored).
        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            \plog("[Proxy] Zello location from '{$from}' missing/invalid coordinates, skipping map update");
            return;
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
            \plog("[Proxy] Zello location from '{$from}' out of range ({$latF},{$lngF}), skipping");
            return;
        }

        try {
            $this->ensureDb();

            // ── 1. zello location provider (must exist + be enabled) ──
            // Cached once per process. If the row is missing the operator
            // hasn't run the seed migration; if disabled the operator has
            // chosen not to map Zello locations — either way, skip quietly.
            if ($this->zelloProviderId === null) {
                $stmt = $this->pdo->prepare(
                    "SELECT id, enabled FROM `{$this->prefix}location_providers` WHERE code = 'zello' LIMIT 1"
                );
                $stmt->execute();
                $prov = $stmt->fetch();
                $this->zelloProviderId = $prov ? (int) $prov['id'] : 0;
                $this->zelloProviderEnabled = $prov ? (bool) (int) $prov['enabled'] : false;
            }
            if (!$this->zelloProviderId) {
                \plog("[Proxy] No 'zello' location provider row — run sql/run_zello_location_provider.php; skipping map update");
                return;
            }
            if (!$this->zelloProviderEnabled) {
                // Provider present but turned off in Settings — respect it.
                return;
            }

            // ── 2. reverse-resolve username → member_id ──
            // Reverse of comm_resolve_member_address(): comm mode `zello`
            // stores the address under values_json.username. Case-insensitive
            // because Zello usernames aren't case-sensitive.
            $memberId = null;
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT mci.member_id
                       FROM `{$this->prefix}member_comm_identifiers` mci
                       JOIN `{$this->prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
                      WHERE cm.code = 'zello'
                        AND cm.enabled = 1
                        AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(mci.values_json, '$.username'))) = LOWER(?)
                      ORDER BY mci.is_primary DESC, mci.id
                      LIMIT 1"
                );
                $stmt->execute([$from]);
                $mid = $stmt->fetchColumn();
                if ($mid !== false && $mid !== null && (int) $mid > 0) {
                    $memberId = (int) $mid;
                }
            } catch (\Exception $e) {
                // JSON_EXTRACT unsupported on a very old MariaDB, or column
                // drift — fall through to "unmapped".
                \plog("[Proxy] Zello member lookup failed for '{$from}': " . $e->getMessage());
            }

            if ($memberId === null) {
                \plog("[Proxy] Zello location from unmapped sender '{$from}' (no member with that Zello username) — logged, skipping map update");
                return;
            }

            // ── 3. map member → responder (unit) ──
            // Active personnel assignment wins; else the personal-unit linkage.
            $responderId = $this->resolveResponderForMember($memberId);
            if ($responderId === null) {
                \plog("[Proxy] Zello sender '{$from}' (member {$memberId}) is not assigned to a unit — logged, skipping map update");
                return;
            }

            $reportedAt = date('Y-m-d H:i:s');

            // ── 4. write the fix (same table the other providers use) ──
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}location_reports`
                    (`provider_id`, `unit_identifier`, `lat`, `lng`, `raw_data`, `reported_at`)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $this->zelloProviderId,
                $from,
                $latF,
                $lngF,
                json_encode(['source' => 'zello', 'from' => $from, 'lat' => $latF, 'lng' => $lngF]),
                $reportedAt,
            ]);

            // ── 5. ensure a binding so the map's all_units query sees it ──
            // The map JOINs location_reports→unit_location_bindings by
            // provider_id + unit_identifier; without a binding the fix is
            // stored but invisible. Upsert (active) for this responder.
            $stmt = $this->pdo->prepare(
                "SELECT id FROM `{$this->prefix}unit_location_bindings`
                  WHERE responder_id = ? AND provider_id = ? AND unit_identifier = ?
                  LIMIT 1"
            );
            $stmt->execute([$responderId, $this->zelloProviderId, $from]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false && $existing !== null) {
                $stmt = $this->pdo->prepare(
                    "UPDATE `{$this->prefix}unit_location_bindings` SET active = 1 WHERE id = ?"
                );
                $stmt->execute([(int) $existing]);
            } else {
                // `source` column exists on installs that ran the autobind
                // schema upgrade (pu_ensure_binding_schema). Probe once so a
                // pre-upgrade install still inserts a usable binding.
                if ($this->bindingHasSourceCol === null) {
                    try {
                        $probe = $this->pdo->query(
                            "SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = '{$this->prefix}unit_location_bindings'
                                AND COLUMN_NAME = 'source'"
                        );
                        $this->bindingHasSourceCol = (bool) $probe->fetchColumn();
                    } catch (\Exception $e) {
                        $this->bindingHasSourceCol = false;
                    }
                }
                if ($this->bindingHasSourceCol) {
                    // `source` is the binding-ORIGIN classifier
                    // enum('manual','personnel') — NOT a provider tag. This
                    // binding is personnel-derived (username → member →
                    // unit), so 'personnel', mirroring pu_autobind_locations.
                    // The "this fix is from Zello" signal lives on the
                    // location_reports.provider_id (code='zello') + raw_data.
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO `{$this->prefix}unit_location_bindings`
                            (responder_id, provider_id, unit_identifier, priority, active, source)
                         VALUES (?, ?, ?, 100, 1, 'personnel')"
                    );
                } else {
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO `{$this->prefix}unit_location_bindings`
                            (responder_id, provider_id, unit_identifier, priority, active)
                         VALUES (?, ?, ?, 100, 1)"
                    );
                }
                $stmt->execute([$responderId, $this->zelloProviderId, $from]);
            }

            // Geofence check on the new fix, mirroring the OwnTracks ingest
            // path. Non-fatal if the helper or table isn't present.
            try {
                $gf = dirname(__DIR__) . '/inc/geofence.php';
                if (is_file($gf)) {
                    require_once $gf;
                    if (function_exists('geofence_check')) {
                        \geofence_check($latF, $lngF, $from);
                    }
                }
            } catch (\Exception $e) {
                // Non-fatal.
            }

            \plog("[Proxy] Zello location stored: '{$from}' → member {$memberId} / unit {$responderId} @ {$latF},{$lngF}");
        } catch (\Exception $e) {
            \plog("[Proxy] Failed to persist Zello location for '{$from}': " . $e->getMessage());
        }
    }

    /**
     * Map a member id → a responder (unit) id, mirroring
     * inc/comm_resolve.php::comm_resolve_responder_member_id but in the
     * forward direction (member → responder). Tries an active
     * unit_personnel_assignments row first, then the personal-unit
     * linkage (responder.personal_for_member_id). Returns null when the
     * member isn't currently tied to any unit.
     */
    private function resolveResponderForMember(int $memberId): ?int
    {
        if ($memberId <= 0) {
            return null;
        }

        // 1. Active personnel assignment (most recent wins).
        try {
            $stmt = $this->pdo->prepare(
                "SELECT responder_id
                   FROM `{$this->prefix}unit_personnel_assignments`
                  WHERE member_id = ?
                    AND status = 'active'
                    AND released_at IS NULL
                  ORDER BY assigned_at DESC, id DESC
                  LIMIT 1"
            );
            $stmt->execute([$memberId]);
            $rid = $stmt->fetchColumn();
            if ($rid !== false && $rid !== null && (int) $rid > 0) {
                return (int) $rid;
            }
        } catch (\Exception $e) {
            // table may not exist on older installs — fall through
        }

        // 2. Personal-unit linkage.
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM `{$this->prefix}responder`
                  WHERE personal_for_member_id = ?
                  LIMIT 1"
            );
            $stmt->execute([$memberId]);
            $rid = $stmt->fetchColumn();
            if ($rid !== false && $rid !== null && (int) $rid > 0) {
                return (int) $rid;
            }
        } catch (\Exception $e) {
            // personal_for_member_id column may not exist yet — null
        }

        return null;
    }
}
