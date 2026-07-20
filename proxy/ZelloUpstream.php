<?php
/**
 * NewUI v4.0 - Zello Upstream Connection
 *
 * Manages the WebSocket connection from the proxy to Zello servers.
 * Uses ratchet/pawl (ReactPHP WebSocket client) to connect upstream.
 * Handles JWT token generation, logon, and message relay.
 */

namespace NewUI\Proxy;

use Ratchet\Client\WebSocket;
use NewUI\Proxy\WebSocketConnector;
use React\EventLoop\LoopInterface;
use Firebase\JWT\JWT;

class ZelloUpstream
{
    /** @var LoopInterface */
    private $loop;

    /** @var array Zello settings from DB */
    private $config;

    /** @var WebSocket|null Active upstream connection */
    private $upstream = null;

    /** @var callable Callback for incoming messages: fn(array $data) */
    private $onMessage;

    /** @var callable Callback for status changes: fn(string $status, string $detail) */
    private $onStatus;

    /** @var callable|null Callback for incoming audio packets: fn(int $streamId, int $packetId, string $opusData) */
    private $onAudioPacket;

    /**
     * @var callable|null Callback for incoming image binary frames:
     *   fn(int $imageId, int $imageType, string $jpegBytes)
     * imageType: 1 = full, 2 = thumbnail. Phase 100 (2026-07-01).
     */
    private $onImagePacket;

    /**
     * @var array<string, bool> Per-channel images_supported flag, tracked
     * from on_channel_status events. Phase 100 — used to reject
     * client send_image early if the channel disallows images.
     */
    private $channelImagesSupported = [];

    /** @var int Sequence counter for Zello commands */
    private $seq = 1;

    /** @var bool Whether we are authenticated with Zello */
    private $authenticated = false;

    /** @var int Reconnect attempt counter */
    private $reconnectAttempts = 0;

    /** @var int Max reconnect attempts before giving up */
    private $maxReconnectAttempts = 10;

    /** @var \React\EventLoop\TimerInterface|null */
    private $reconnectTimer = null;

    /**
     * Phase 99ai (Eric beta 2026-06-30) — hardening against the tonight's
     * 429 cascade. Three new pieces of state:
     *
     *   $connecting — true from the moment connect() dispatches the WSS
     *     handshake until the WebSocket has either connected OR the
     *     failure branch has run. Prevents scheduleReconnect() from
     *     firing a second connect() while the first is still pending,
     *     which was the primary trigger of the "kick loop" — two
     *     concurrent sessions authenticating as 'eric dispatch', Zello
     *     booting one, our reconnect firing again, etc.
     *
     *   $rateLimitedUntil — Unix timestamp. If non-null and in the
     *     future, connect() refuses to try. Set when we detect HTTP
     *     429 on the WSS upgrade; cleared on successful connect.
     *
     *   $fatalAuth — true after Zello closes with 3001 "unable to
     *     verify". Auto-reconnect disabled until the operator changes
     *     credentials + restarts the daemon (reconnecting hammering
     *     the wrong creds would just re-trigger rate limits).
     *
     * @var bool
     */
    private $connecting = false;

    /** @var int|null Unix time until which we must NOT reconnect (429 cool-off) */
    private $rateLimitedUntil = null;

    /** @var bool Zello returned fatal-auth close — do not auto-reconnect */
    private $fatalAuth = false;

    /**
     * Phase 99ai — proactive connect-rate limiter. Zello 429s per IP
     * after roughly 3-5 WSS handshakes in a short window. We hit this
     * tonight because a kick loop (3003 close → reconnect → kicked
     * again) opened dozens of WSS attempts in <10 s. Track our own
     * connect timestamps; if we're about to exceed the budget, DEFER.
     * This is self-defense — kicks in *before* we hand anything to
     * Zello, based purely on our own recent behavior.
     * @var array<int>
     */
    private $recentConnects = [];

    /** @var int Max WSS connect attempts per rolling window */
    private $maxConnectsPerWindow = 3;

    /** @var int Rolling window in seconds */
    private $connectWindowSec = 60;

    /**
     * @param LoopInterface $loop          ReactPHP event loop
     * @param array         $config        Zello settings (zello_ws_url, zello_username, etc.)
     * @param callable      $onMessage     Called with decoded JSON for each incoming message
     * @param callable      $onStatus      Called with (status, detail) on connection state changes
     * @param callable|null $onAudioPacket Called with (streamId, packetId, opusData) for binary audio
     * @param callable|null $onImagePacket Called with (imageId, imageType, jpegBytes) for binary image (Phase 100)
     */
    public function __construct(LoopInterface $loop, array $config, callable $onMessage, callable $onStatus, callable $onAudioPacket = null, callable $onImagePacket = null)
    {
        $this->loop          = $loop;
        $this->config        = $config;
        $this->onMessage     = $onMessage;
        $this->onStatus      = $onStatus;
        $this->onAudioPacket = $onAudioPacket;
        $this->onImagePacket = $onImagePacket;
    }

    /**
     * Connect to Zello upstream server.
     *
     * Phase 99ai (Eric beta 2026-06-30) — GUARDED. Refuses to open a
     * second concurrent WSS handshake. Also honors the rate-limit
     * cool-off (from a prior 429) and the fatal-auth flag (from a
     * prior 3001 "unable to verify" close).
     */
    public function connect(): void
    {
        // ── Guard 1: fatal-auth latched ──
        if ($this->fatalAuth) {
            \plog("[Upstream] Refusing to reconnect — fatal auth error (3001) previously received; fix credentials in Settings and restart the proxy daemon.");
            return;
        }

        // ── Guard 2: rate-limit cool-off ──
        if ($this->rateLimitedUntil !== null && time() < $this->rateLimitedUntil) {
            $remaining = $this->rateLimitedUntil - time();
            \plog("[Upstream] Refusing to connect — Zello rate-limit cool-off active, {$remaining}s remaining");
            return;
        }
        if ($this->rateLimitedUntil !== null && time() >= $this->rateLimitedUntil) {
            \plog("[Upstream] Rate-limit cool-off expired — attempting reconnect");
            $this->rateLimitedUntil = null;
            $this->reconnectAttempts = 0; // fresh start after cool-off
        }

        // ── Guard 3: no concurrent connects ──
        if ($this->connecting) {
            \plog("[Upstream] Ignoring connect() — a WSS handshake is already in flight.");
            return;
        }
        if ($this->upstream !== null) {
            \plog("[Upstream] Ignoring connect() — already connected.");
            return;
        }

        // ── Guard 4: proactive connect-rate limiter ──
        // Trim expired timestamps from the rolling window; if we're
        // over budget, defer instead of hitting Zello.
        $now = time();
        $cutoff = $now - $this->connectWindowSec;
        $this->recentConnects = array_values(array_filter(
            $this->recentConnects,
            function ($t) use ($cutoff) { return $t > $cutoff; }
        ));
        if (count($this->recentConnects) >= $this->maxConnectsPerWindow) {
            $oldest = min($this->recentConnects);
            $waitSec = max(1, $this->connectWindowSec - ($now - $oldest));
            \plog("[Upstream] Self-limit: {$this->maxConnectsPerWindow} connects in the last {$this->connectWindowSec}s — deferring {$waitSec}s to avoid tripping Zello rate limit.");
            ($this->onStatus)('rate_limited', "Self-throttling — retrying in {$waitSec}s");
            $this->scheduleReconnectIn($waitSec);
            return;
        }
        $this->recentConnects[] = $now;

        $this->connecting = true;

        // Phase 98 (2026-06-28) — branch the WebSocket URL by service
        // type. Admins can still override via zello_ws_url; only auto-
        // compute when blank.
        //
        // service=consumer  → wss://zello.io/ws (default)
        // service=work      → wss://zellowork.io/ws/<network-name>
        // service=''        → don't connect (UI Service Type "Disabled")
        $service = strtolower(trim((string) ($this->config['zello_service'] ?? '')));
        $wsUrl   = trim((string) ($this->config['zello_ws_url'] ?? ''));
        if ($wsUrl === '') {
            if ($service === 'work') {
                $network = trim((string) ($this->config['zello_network'] ?? ''));
                $network = trim($network, "/ \t\n\r\0\x0B");
                // Tolerate admins pasting the full URL by accident.
                $network = preg_replace('#^https?://([^/.]+)\..*$#i', '$1', $network);
                if ($network === '') {
                    ($this->onStatus)('error', 'Zello Work selected but Network Name is empty');
                    \plog("[Upstream] Zello Work mode but zello_network is empty — refusing to connect");
                    $this->connecting = false;
                    return;
                }
                $wsUrl = 'wss://zellowork.io/ws/' . rawurlencode($network);
            } elseif ($service === '' || $service === 'disabled') {
                ($this->onStatus)('disabled', 'Zello service is disabled in Settings');
                \plog("[Upstream] zello_service is empty/disabled — proxy idle");
                $this->connecting = false;
                return;
            } else {
                $wsUrl = 'wss://zello.io/ws';
            }
        }

        ($this->onStatus)('connecting', 'Connecting to ' . $wsUrl);
        \plog("[Upstream] Connecting to {$wsUrl} (service=" . ($service ?: '(consumer-default)') . ')');

        $connector = new WebSocketConnector($this->loop);
        $connector($wsUrl)->then(
            function (WebSocket $conn) {
                $this->connecting = false; // Phase 99ai — release guard on success
                $this->upstream = $conn;
                $this->reconnectAttempts = 0;
                $this->rateLimitedUntil = null;
                \plog("[Upstream] WebSocket connected");
                ($this->onStatus)('connected', 'WebSocket connected, authenticating...');

                $conn->on('message', function ($msg) {
                    $this->handleUpstreamMessage((string) $msg);
                });

                $conn->on('close', function ($code = null, $reason = null) {
                    \plog("[Upstream] Connection closed: {$code} {$reason}");
                    $this->upstream = null;
                    $this->authenticated = false;
                    ($this->onStatus)('disconnected', "Connection closed: {$reason}");

                    // Phase 99ai (Eric beta 2026-06-30) — Zello close-code
                    // semantics observed in the 22:37 CDT reconnect storm:
                    //
                    //   3003 "kicked" — another session logged in as the
                    //     same user. Reconnecting instantly makes us fight
                    //     the winner, which Zello punishes with another
                    //     kick. Wait 30 s so the other session settles.
                    //
                    //   3001 "unable to verify" — fatal auth error.
                    //     Reconnecting with the same credentials will fail
                    //     the same way and eat into the rate-limit budget.
                    //     Latch $fatalAuth and stop until operator fixes.
                    //
                    //   others — treat as transient, use normal exponential.
                    $codeInt = is_numeric($code) ? (int) $code : 0;
                    if ($codeInt === 3003) {
                        \plog("[Upstream] Kicked (3003) — another session took our username. Waiting 30s before reconnect to avoid a kick loop.");
                        ($this->onStatus)('kicked', 'Kicked by another session — waiting 30s');
                        $this->reconnectAttempts = 0;
                        $this->scheduleReconnectIn(30);
                        return;
                    }
                    if ($codeInt === 3001) {
                        \plog("[Upstream] Fatal auth error (3001 unable to verify) — auto-reconnect disabled. Check Zello credentials + zello_network in Settings.");
                        ($this->onStatus)('auth_failed', 'Zello rejected credentials — check Settings');
                        $this->fatalAuth = true;
                        return;
                    }
                    $this->scheduleReconnect();
                });

                // Send logon command
                $this->sendLogon();
            },
            function (\Exception $e) {
                $this->connecting = false; // Phase 99ai — release guard on failure
                $msg = $e->getMessage();
                \plog("[Upstream] Connection failed: " . $msg);
                ($this->onStatus)('error', 'Connection failed: ' . $msg);

                // Phase 99ai (Eric beta 2026-06-30) — Zello returns
                // HTTP 429 "Too Many Requests" on the WSS upgrade when
                // we've reconnected too many times in a short window
                // (~5-15 min per IP). Continuing to poll every 30s
                // during that window keeps the ban warm. Cool off for
                // 15 min instead of the normal exponential.
                if (strpos($msg, '429') !== false || stripos($msg, 'Too Many Requests') !== false) {
                    $cooloffSec = 900;
                    $this->rateLimitedUntil = time() + $cooloffSec;
                    \plog("[Upstream] Zello rate-limited (HTTP 429). Cooling off {$cooloffSec}s (until " . date('H:i:s', $this->rateLimitedUntil) . ").");
                    ($this->onStatus)('rate_limited', "Zello rate-limited — retrying in " . (int) ($cooloffSec / 60) . " min");
                    $this->scheduleReconnectIn($cooloffSec);
                    return;
                }
                $this->scheduleReconnect();
            }
        );
    }

    /**
     * Disconnect from Zello.
     */
    public function disconnect(): void
    {
        if ($this->reconnectTimer) {
            $this->loop->cancelTimer($this->reconnectTimer);
            $this->reconnectTimer = null;
        }
        $this->reconnectAttempts = $this->maxReconnectAttempts; // prevent auto-reconnect

        if ($this->upstream) {
            $this->upstream->close();
            $this->upstream = null;
        }
        $this->authenticated = false;
        ($this->onStatus)('disconnected', 'Disconnected by request');
    }

    /**
     * Send a text message to a channel, or — when $recipient is given — a
     * direct message to one user within that channel.
     *
     * Phase E (messaging-send-gaps-2026-06): the Zello Channel API addresses
     * a user-DM with the OPTIONAL `for` field on send_text_message — the
     * message is still scoped to a `channel`, but only the named user
     * receives it ("Other users in the channel won't be receiving this text
     * message"). The same command/field works on both Zello Consumer
     * (Friends & Family) and Zello Work. There is NO separate user-only
     * transport: a DM is a channel message with `for`, so a non-empty
     * channel is still required and the recipient must be reachable on that
     * channel. See the live-smoke note in the Phase E spec.
     *
     * @param string $text      Message body
     * @param string $channel   Channel name (blank → caller's default)
     * @param string $recipient Zello username for a DM; '' = channel broadcast
     */
    public function sendTextMessage(string $text, string $channel = '', string $recipient = ''): bool
    {
        if (!$this->upstream || !$this->authenticated) {
            return false;
        }

        $cmd = [
            'command' => 'send_text_message',
            'seq'     => $this->seq++,
            'text'    => $text,
        ];
        if ($channel !== '') {
            $cmd['channel'] = $channel;
        }
        // `for` turns a channel send into a per-user DM. Zello requires the
        // channel context even for a DM, so we leave $channel in place.
        $recipient = trim($recipient);
        if ($recipient !== '') {
            $cmd['for'] = $recipient;
        }

        $this->upstream->send(json_encode($cmd));
        return true;
    }

    /**
     * Check if upstream is connected and authenticated.
     */
    public function isConnected(): bool
    {
        return $this->upstream !== null && $this->authenticated;
    }

    /**
     * Phase 100 (2026-07-01) — return whether the given channel
     * supports images (per Zello's on_channel_status.images_supported).
     * Defaults to true if we haven't seen a status for the channel yet;
     * Zello will error the send_image if wrong, and we surface that
     * error to the widget.
     */
    public function channelImagesSupported(string $channel): bool
    {
        return $this->channelImagesSupported[$channel] ?? true;
    }

    /**
     * Phase 100 (2026-07-01) — send an image to Zello.
     *
     * Zello's protocol per API.md:
     *   1. Send send_image JSON with content_length + thumbnail_content_length
     *   2. Wait for {seq, success:true, image_id: N} response
     *   3. Send thumbnail binary [0x02][image_id BE u32][0x00000002 BE u32][thumb JPEG]
     *   4. Send full binary       [0x02][image_id BE u32][0x00000001 BE u32][full JPEG]
     *
     * This method fires step 1 and returns the seq number. Steps 3+4
     * are fired by the caller (ZelloProxyApp) inside the pendingImageSends
     * ACK handler when the server-assigned image_id arrives. We hold the
     * two binaries in memory until then, keyed by seq.
     *
     * @return int|false The seq number, or false if not connected.
     */
    public function sendImageStart(string $channel, string $recipient, int $contentLength, int $thumbnailLength, int $width, int $height, string $source = 'library')
    {
        if (!$this->isConnected()) return false;
        $cmd = [
            'command'                  => 'send_image',
            'channel'                  => $channel,
            'type'                     => 'jpeg',
            'thumbnail_content_length' => $thumbnailLength,
            'content_length'           => $contentLength,
            'width'                    => $width,
            'height'                   => $height,
            'source'                   => $source,
        ];
        if ($recipient !== '') $cmd['for'] = $recipient;
        return $this->sendCommand($cmd);
    }

    /**
     * Phase 100 — send one image binary frame (thumbnail OR full).
     * imageType: 1 = full, 2 = thumbnail. Sends as WSS binary frame
     * via the existing sendBinary() helper (OP_BINARY, not text).
     */
    public function sendImageBinary(int $imageId, int $imageType, string $jpegBytes): bool
    {
        $payload = chr(0x02) . pack('N', $imageId) . pack('N', $imageType) . $jpegBytes;
        return $this->sendBinary($payload);
    }

    // ── Internal methods ─────────────────────────────────────────

    /**
     * Generate a JWT auth token using the configured issuer and private key.
     * Falls back to the static auth_token if JWT config is not available.
     *
     * Phase 98 (2026-06-28) — Zello Work skips JWT entirely. Work
     * networks authenticate the session via the `username` + `password`
     * fields sent in the logon command, validated against the network's
     * user database. The `auth_token` field is sent empty.
     */
    private function getAuthToken(): string
    {
        $service = strtolower(trim((string) ($this->config['zello_service'] ?? '')));
        if ($service === 'work') {
            // Zello Work: no JWT, no static token. Logon's
            // username + password do the auth.
            return '';
        }

        $issuer     = $this->config['zello_issuer'] ?? '';
        $privateKey = $this->config['zello_private_key'] ?? '';

        // If JWT credentials are configured, generate a fresh token
        if ($issuer !== '' && $privateKey !== '') {
            $now = time();
            $payload = [
                'iss' => $issuer,
                'exp' => $now + 60, // 60 second expiry — short-lived
            ];
            try {
                return JWT::encode($payload, $privateKey, 'RS256');
            } catch (\Exception $e) {
                \plog("[Upstream] JWT generation failed: " . $e->getMessage());
                \plog("[Upstream] Falling back to static auth token");
            }
        }

        // Fall back to static dev auth token
        $token = $this->config['zello_auth_token'] ?? '';
        if ($token === '') {
            \plog("[Upstream] WARNING: No auth token configured. Logon will fail.");
        }
        return $token;
    }

    /**
     * Send logon command to Zello.
     */
    private function sendLogon(): void
    {
        $token    = $this->getAuthToken();
        $username = $this->config['zello_username'] ?? '';
        $password = $this->config['zello_password'] ?? '';
        $channel  = $this->config['zello_dispatch_channel'] ?? '';

        $cmd = [
            'command'    => 'logon',
            'seq'        => $this->seq++,
            'auth_token' => $token,
            'username'   => $username,
            'password'   => $password,
            'channel'    => $channel,
        ];

        // For Zello Work, include channels list
        $extra = $this->config['zello_extra_channels'] ?? '';
        if ($extra !== '') {
            $channels = array_map('trim', explode(',', $channel . ',' . $extra));
            $channels = array_filter($channels);
            $cmd['channels'] = array_values($channels);
            unset($cmd['channel']);
        }

        \plog("[Upstream] Sending logon as '{$username}' to channel '{$channel}'");
        $this->upstream->send(json_encode($cmd));
    }

    /**
     * Handle an incoming message from the Zello upstream server.
     */
    private function handleUpstreamMessage(string $raw): void
    {
        $data = json_decode($raw, true);
        if (!$data) {
            // Binary frame — check for audio data (type byte 0x01)
            $this->handleBinaryFrame($raw);
            return;
        }

        // Logon response
        if (isset($data['command']) && $data['command'] === 'logon') {
            // Note: Zello doesn't send command='logon' in response.
            // The response is identified by matching seq number. But the success
            // field tells us if auth worked.
        }

        // Check for success/error on any response with a seq
        if (isset($data['success'])) {
            if ($data['success']) {
                if (!$this->authenticated) {
                    $this->authenticated = true;
                    $refreshUrl = $data['refresh_token_url'] ?? '';
                    \plog("[Upstream] Authenticated successfully");

                    // Phase 99ak (Eric beta 2026-07-01) — include the
                    // Zello login username + dispatch channel in the
                    // widget-facing status so the connection-log trail
                    // matches what the proxy log shows. Previously the
                    // widget only said "Logged in to Zello" — leaving
                    // Eric guessing whether the config change took
                    // effect. Now: "Logged in as 'eric dispatch' to
                    // channel 'TicketsCAD-Group'".
                    $username = (string) ($this->config['zello_username'] ?? '');
                    $channel  = (string) ($this->config['zello_dispatch_channel'] ?? '');
                    $detail = 'Logged in to Zello';
                    if ($username !== '' && $channel !== '') {
                        $detail = "Logged in as '{$username}' to channel '{$channel}'";
                    } elseif ($username !== '') {
                        $detail = "Logged in as '{$username}'";
                    } elseif ($channel !== '') {
                        $detail = "Logged in to channel '{$channel}'";
                    }
                    ($this->onStatus)('authenticated', $detail);
                }
            } else {
                $error = $data['error'] ?? 'Unknown error';
                \plog("[Upstream] Command failed: {$error}");
                ($this->onStatus)('error', 'Zello error: ' . $error);
            }
        }

        // Channel status event — Phase 99ak also forwards this to the
        // widget so the connection-log trail shows "Channel
        // TicketsCAD-Group is online" alongside the proxy log.
        // Zello sends this per channel on login and whenever the
        // channel's online-status changes; forwarding all of them
        // matches log fidelity without extra filtering.
        if (isset($data['command']) && $data['command'] === 'on_channel_status') {
            $ch = (string) ($data['channel'] ?? '?');
            $st = (string) ($data['status'] ?? '?');
            \plog("[Upstream] Channel status: {$ch} - {$st}");
            ($this->onStatus)('channel_status', "Channel '{$ch}' is {$st}");
            // Phase 100 — remember per-channel images_supported so we
            // can pre-reject client send_image on channels that
            // disallow images. Zello sets this at logon; may update on
            // channel-status changes.
            if (isset($data['images_supported'])) {
                $this->channelImagesSupported[$ch] = (bool) $data['images_supported'];
            }
        }

        // Text message event
        if (isset($data['command']) && $data['command'] === 'on_text_message') {
            \plog("[Upstream] Text from " . ($data['from'] ?? '?') . ": " . ($data['message_text'] ?? $data['text'] ?? ''));
        }

        // Stream start event (voice — Phase 2)
        if (isset($data['command']) && $data['command'] === 'on_stream_start') {
            \plog("[Upstream] Voice stream started from " . ($data['from'] ?? '?'));
        }

        // Location event
        if (isset($data['command']) && $data['command'] === 'on_location') {
            \plog("[Upstream] Location from " . ($data['from'] ?? '?'));
        }

        // Image event
        if (isset($data['command']) && $data['command'] === 'on_image') {
            \plog("[Upstream] Image from " . ($data['from'] ?? '?'));
        }

        // Forward all parsed messages to the proxy app
        ($this->onMessage)($data);
    }

    /**
     * Handle a binary frame from Zello upstream.
     *
     * Audio packet format (9-byte header + Opus data):
     *   [0]    : 0x01 (audio type marker)
     *   [1-4]  : stream_id  (uint32, big-endian)
     *   [5-8]  : packet_id  (uint32, big-endian)
     *   [9+]   : Opus audio frame data
     */
    private function handleBinaryFrame(string $raw): void
    {
        $len = strlen($raw);
        if ($len < 9) {
            // Too short to be an audio packet
            return;
        }

        $type = ord($raw[0]);

        // Zello binary type table:
        //   0x01 audio (handled below in this method)
        //   0x02 image (Phase 100 — parse header, fire onImagePacket)
        //   other  reserved / rare — log + drop
        if ($type === 0x02) {
            // Phase 100 (2026-07-01) — image binary frame layout per
            // Zello API.md:
            //   byte 0     : 0x02  (already matched)
            //   byte 1-4   : image_id  (uint32 big-endian)
            //   byte 5-8   : image_type (uint32 BE) 1=full, 2=thumbnail
            //   byte 9+    : raw JPEG bytes
            if ($len < 9) {
                \plog("[Upstream] Image binary too short ({$len} bytes) — dropping.");
                return;
            }
            $imageId   = unpack('N', substr($raw, 1, 4))[1];
            $imageType = unpack('N', substr($raw, 5, 4))[1];
            $jpegBytes = substr($raw, 9);
            $jpegLen   = strlen($jpegBytes);
            $kind      = $imageType === 2 ? 'thumbnail' : ($imageType === 1 ? 'full' : "type={$imageType}");
            \plog("[Upstream] Image binary: id={$imageId} {$kind} ({$jpegLen} JPEG bytes)");
            if ($this->onImagePacket) {
                ($this->onImagePacket)($imageId, $imageType, $jpegBytes);
            }
            return;
        }

        if ($type !== 0x01) {
            \plog("[Upstream] Unknown binary type: 0x" . dechex($type) . " ({$len} bytes) — ignoring.");
            return;
        }

        // Parse stream_id (bytes 1-4, big-endian uint32)
        $streamId = unpack('N', substr($raw, 1, 4))[1];

        // Parse packet_id (bytes 5-8, big-endian uint32)
        $packetId = unpack('N', substr($raw, 5, 4))[1];

        // Opus frame data (bytes 9+)
        $opusData = substr($raw, 9);

        if ($this->onAudioPacket) {
            ($this->onAudioPacket)($streamId, $packetId, $opusData);
        }
    }

    /**
     * Send a raw binary frame to the Zello upstream server.
     * Used for outgoing audio packets.
     */
    public function sendBinary(string $data): bool
    {
        if (!$this->upstream || !$this->authenticated) {
            return false;
        }

        $frame = new \Ratchet\RFC6455\Messaging\Frame($data, true, \Ratchet\RFC6455\Messaging\Frame::OP_BINARY);
        $this->upstream->send($frame);
        return true;
    }

    /**
     * Send a JSON command to the Zello upstream server.
     * Returns the seq number used (for matching responses).
     */
    public function sendCommand(array $cmd): int
    {
        $seq = $this->seq++;
        $cmd['seq'] = $seq;
        if ($this->upstream) {
            $this->upstream->send(json_encode($cmd));
        }
        return $seq;
    }

    /**
     * Schedule a reconnection attempt with exponential backoff.
     */
    private function scheduleReconnect(): void
    {
        // Phase 99ai — don't stack a second timer if we already have
        // one queued. Prevents "reconnect scheduled twice" races.
        if ($this->reconnectTimer !== null) {
            \plog("[Upstream] Reconnect already scheduled — skipping duplicate.");
            return;
        }
        if ($this->connecting) {
            \plog("[Upstream] Connect already in progress — skipping reconnect schedule.");
            return;
        }

        if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
            \plog("[Upstream] Max reconnect attempts reached. Giving up.");
            ($this->onStatus)('failed', 'Max reconnect attempts reached');
            return;
        }

        $delay = min(30, pow(2, $this->reconnectAttempts));
        $this->reconnectAttempts++;
        \plog("[Upstream] Reconnecting in {$delay}s (attempt {$this->reconnectAttempts})");
        ($this->onStatus)('reconnecting', "Reconnecting in {$delay}s...");

        $this->reconnectTimer = $this->loop->addTimer($delay, function () {
            $this->reconnectTimer = null;
            $this->connect();
        });
    }

    /**
     * Phase 99ai — schedule a reconnect at a specific delay, bypassing
     * exponential backoff. Used for:
     *   - 3003 kicked cool-off (30 s)
     *   - 429 rate-limit cool-off (900 s)
     * Still respects the "no stacked timers" and "not currently
     * connecting" guards.
     */
    private function scheduleReconnectIn(int $delaySec): void
    {
        if ($this->reconnectTimer !== null) {
            \plog("[Upstream] Reconnect already scheduled — skipping duplicate.");
            return;
        }
        if ($this->connecting) {
            \plog("[Upstream] Connect already in progress — skipping reconnect schedule.");
            return;
        }
        \plog("[Upstream] Reconnect scheduled in {$delaySec}s (fixed delay).");
        $this->reconnectTimer = $this->loop->addTimer($delaySec, function () {
            $this->reconnectTimer = null;
            $this->connect();
        });
    }
}
