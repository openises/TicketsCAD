<?php
/**
 * NewUI v4.0 — DMR Proxy App (Phase 85c)
 *
 * Ratchet MessageComponentInterface handler. Each browser opens a
 * WebSocket to wss://host/dmr-ws; this class manages those connections
 * and bridges them to the DMR backend (hbp_client.py).
 *
 * Protocol (browser -> proxy):
 *   Text JSON  {"cmd":"auth","token":"...","channel":<id>}
 *   Text JSON  {"cmd":"ptt_start"}
 *   Binary     raw 8 kHz s16le mono PCM frames (any size, typically 20 ms)
 *   Text JSON  {"cmd":"ptt_end"}
 *
 * Protocol (proxy -> browser):
 *   Text JSON  {"type":"auth_ok","user":"...","channel":{...}}
 *   Text JSON  {"type":"error","message":"..."}
 *   Text JSON  {"type":"tx_started","stream_id":"..."}      (optional)
 *   Text JSON  {"type":"tx_ack","ok":true,"packets_sent":N,
 *                "bytes_sent":N,"chunk_count":N,"http_code":200}
 *
 * RX (incoming traffic on the talkgroup) is NOT proxied through this
 * WS in Phase 85c v1 — the existing api/dmr-stream.php SSE path is
 * left in place and the widget continues to use it. Phase 85c v2 will
 * fold RX into the same WS so dispatchers have one socket for both
 * directions.
 */

namespace NewUI\Proxy;

// Phase 85c-fix-19: use the WebSocket-aware interface, NOT the legacy
// Ratchet\MessageComponentInterface. WsServer routes the legacy one
// through $msg->getPayload() which discards the frame type — leaving
// onMessage() with no reliable way to tell PCM from JSON. The
// WebSocket interface delivers the raw MessageInterface so isBinary()
// works as advertised.
use Ratchet\WebSocket\MessageComponentInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;

class DmrProxyApp implements MessageComponentInterface
{
    /** @var LoopInterface */
    private $loop;
    /** @var array Settings from DB */
    private $config;
    /** @var \PDO */
    private $pdo;
    /** @var string */
    private $prefix;
    /** @var array<int, array> channel_id => row from dmr_channels */
    private $channels;
    /** @var array{host:string,user:string,pass:string,name:string} for reconnect */
    private $dbCreds;

    /** @var \SplObjectStorage<ConnectionInterface> */
    private $clients;
    /** @var array<int, array{user_id:int,user:string,level:int,channel_id:int}> resourceId => auth context */
    private $auth = [];
    /** @var array<int, DmrUpstream> resourceId => active TX upstream */
    private $tx = [];

    public function __construct(
        LoopInterface $loop,
        array $config,
        \PDO $pdo,
        string $prefix,
        array $channels,
        array $dbCreds = []
    ) {
        $this->loop     = $loop;
        $this->config   = $config;
        $this->pdo      = $pdo;
        $this->prefix   = $prefix;
        $this->channels = $channels;
        $this->dbCreds  = $dbCreds;
        $this->clients  = new \SplObjectStorage();

        // Phase 85c-fix-18: keep the MySQL connection alive. Without
        // this, an idle daemon eventually hits MySQL's wait_timeout
        // (default 8h) and every subsequent query 500s with
        // "MySQL server has gone away" — which is what users see as
        // "TX: Not authenticated" / "TX: Auth DB error". Restarting
        // the proxy was a workaround; this is the actual fix.
        // 4-min ping is comfortably under both wait_timeout (8h) and
        // any conservative TCP keepalive a NAT might enforce.
        $this->loop->addPeriodicTimer(240, function () {
            $this->pingDb();
        });
    }

    /**
     * Try a no-op query; if the connection is dead and we have creds
     * to reconnect, rebuild $this->pdo so the NEXT real query works.
     * Called every 4 minutes by a loop timer.
     */
    private function pingDb(): void
    {
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
        } catch (\Throwable $e) {
            \plog("[Proxy] DB ping failed: " . $e->getMessage());
            if (!$this->dbCreds) return;
            try {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    $this->dbCreds['host'], $this->dbCreds['name']
                );
                $newPdo = new \PDO($dsn, $this->dbCreds['user'], $this->dbCreds['pass'], [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                try {
                    $offset = (new \DateTime('now'))->format('P');
                    // The regex above guarantees $offset is exactly
                    // "[+-]\d{2}:\d{2}" — 6 ASCII chars from a fixed
                    // alphabet, not user input. SET time_zone doesn't
                    // accept placeholders, so interpolation is the
                    // only option; the validation makes it safe.
                    if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
                        $newPdo->exec("SET time_zone = '{$offset}'"); // NOSONAR S2077: validated regex above
                    }
                } catch (\Throwable $tzErr) { /* non-fatal */ }
                $this->pdo = $newPdo;
                \plog("[Proxy] DB reconnected");
            } catch (\Throwable $reconnectErr) {
                \plog("[Proxy] DB reconnect FAILED: " . $reconnectErr->getMessage());
            }
        }
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        \plog("[Proxy] client {$conn->resourceId} connected (" . count($this->clients) . " total)");
        // Send a hello so the browser knows the socket is alive and
        // can prompt the user/widget to send the auth token.
        $conn->send(json_encode(['type' => 'hello', 'requires' => 'auth']));
    }

    public function onMessage(ConnectionInterface $from, MessageInterface $msg): void
    {
        $id = $from->resourceId;

        // Phase 85c-fix-19: with the WebSocket interface, isBinary()
        // reflects the actual WebSocket opcode (text=0x1, binary=0x2)
        // and is the only correct way to tell PCM from JSON. Earlier
        // size+content heuristics misclassified short PCM chunks that
        // happened to start with 0x7B/0x5B as text, returning a noisy
        // "TX: Invalid command" to the user mid-transmission.
        $isBinary = $msg->isBinary();
        $msg = (string) $msg;

        if ($isBinary) {
            $this->handleBinaryFrame($from, $msg);
            return;
        }

        $data = json_decode($msg, true);
        if (!is_array($data) || !isset($data['cmd'])) {
            \plog("[Proxy] client {$id} sent invalid text frame: " . substr($msg, 0, 60));
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid command']));
            return;
        }

        $cmd = $data['cmd'];

        // ── Auth ─────────────────────────────────────────────────
        if ($cmd === 'auth') {
            $this->handleAuth($from, $data);
            return;
        }

        // All other commands require auth.
        if (!isset($this->auth[$id])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Not authenticated']));
            return;
        }

        if ($cmd === 'ptt_start')   { $this->handlePttStart($from); return; }
        if ($cmd === 'ptt_end')     { $this->handlePttEnd($from);   return; }
        if ($cmd === 'ping')        { $from->send(json_encode(['type' => 'pong'])); return; }

        $from->send(json_encode(['type' => 'error', 'message' => "Unknown command: {$cmd}"]));
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $id = $conn->resourceId;
        if (isset($this->tx[$id])) {
            // If ptt_end already closed the body and we're just
            // waiting for the bridge response, let it finish so
            // recordTxToDb still fires. Otherwise (mid-stream
            // disconnect) cut it loose — the bridge will see EOF
            // and drop the partial call.
            if ($this->tx[$id]->isAwaitingResponse()) {
                \plog("[Proxy] client {$id} disconnected mid-bridge-response — letting upstream finish");
            } else {
                $this->tx[$id]->abort();
                unset($this->tx[$id]);
            }
        }
        unset($this->auth[$id]);
        $this->clients->detach($conn);
        \plog("[Proxy] client {$id} disconnected");
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        \plog("[Proxy] error on client {$conn->resourceId}: " . $e->getMessage());
        $conn->close();
    }

    // ── Auth: verify one-shot token from api/dmr-token.php ────────

    private function handleAuth(ConnectionInterface $conn, array $data): void
    {
        $id    = $conn->resourceId;
        $token = trim($data['token'] ?? '');
        $requestedChannelId = (int) ($data['channel'] ?? 0);

        if ($token === '') {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Missing auth token']));
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT `token`, `user_id`, `user`, `user_level`, `channel_id`, `created`
                 FROM `{$this->prefix}dmr_ws_tokens`
                 WHERE `token` = ?
                   AND `created` > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                 LIMIT 1"
            );
            $stmt->execute([$token]);
            $row = $stmt->fetch();
        } catch (\Exception $e) {
            \plog("[Proxy] auth DB error: " . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'message' => 'Auth DB error']));
            return;
        }

        if (!$row) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid or expired token']));
            \plog("[Proxy] auth failed for client {$id}: token not found");
            return;
        }

        // Consume the token (one-shot)
        try {
            $this->pdo->prepare(
                "DELETE FROM `{$this->prefix}dmr_ws_tokens` WHERE `token` = ?"
            )->execute([$token]);
        } catch (\Exception $e) { /* non-fatal */ }

        // Pick the channel: prefer the auth request's, then the token's,
        // then the first enabled channel.
        $channelId = $requestedChannelId
            ?: (int) ($row['channel_id'] ?? 0)
            ?: (int) array_key_first($this->channels);
        $channel = $this->channels[$channelId] ?? null;
        if (!$channel) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'No DMR channel available']));
            return;
        }

        $this->auth[$id] = [
            'user_id'    => (int) $row['user_id'],
            'user'       => $row['user'] ?? 'unknown',
            'level'      => (int) ($row['user_level'] ?? 99),
            'channel_id' => $channelId,
        ];

        \plog("[Proxy] client {$id} authenticated as " . $this->auth[$id]['user'] .
              " (user_id=" . $this->auth[$id]['user_id'] . ", channel #{$channelId} " .
              "{$channel['label']} TG {$channel['talkgroup']})");

        $conn->send(json_encode([
            'type'    => 'auth_ok',
            'user'    => $this->auth[$id]['user'],
            'channel' => [
                'id'        => $channelId,
                'label'     => $channel['label'],
                'talkgroup' => $channel['talkgroup'],
            ],
        ]));
    }

    // ── TX lifecycle ──────────────────────────────────────────────

    private function handlePttStart(ConnectionInterface $conn): void
    {
        $id = $conn->resourceId;
        if (isset($this->tx[$id])) {
            // Already in TX — silently ignore; pttEnd should be sent first
            return;
        }
        $channelId = $this->auth[$id]['channel_id'];
        $channel = $this->channels[$channelId] ?? null;
        if (!$channel) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Channel disappeared']));
            return;
        }

        // Phase 85c-fix-6: proxy generates the stream_id and tells the
        // bridge to adopt it. Both sides then derive the same WAV path,
        // so the DB row can be inserted at PTT-start time without
        // depending on the bridge's HTTP response (which has proven
        // racey through our React socket).
        $streamHex = bin2hex(random_bytes(4));
        $startedAt = time();
        $this->recordTxStart([
            'channel_id' => $channelId,
            'talkgroup'  => $channel['talkgroup'] ?? '',
            'stream_hex' => $streamHex,
            'user_id'    => $this->auth[$id]['user_id'] ?? null,
            'user'       => $this->auth[$id]['user'] ?? null,
            'started_at' => $startedAt,
        ]);

        $upstream = new DmrUpstream(
            $this->loop,
            $channel,
            function (string $body, int $httpCode) use ($conn, $id, $channelId, $channel, $streamHex, $startedAt) {
                // Bridge has responded — relay tx_ack to the browser.
                $bridgeResp = json_decode($body, true);
                $ack = [
                    'type'      => 'tx_ack',
                    'ok'        => $httpCode >= 200 && $httpCode < 300,
                    'http_code' => $httpCode,
                ];
                if (is_array($bridgeResp)) {
                    $ack['stream_id']      = $bridgeResp['stream_id']      ?? null;
                    $ack['packets_sent']   = $bridgeResp['packets_sent']   ?? null;
                    $ack['bytes_received'] = $bridgeResp['bytes_received'] ?? null;
                    $ack['chunks']         = $bridgeResp['chunks']         ?? null;
                }
                if (!empty($this->tx[$id])) {
                    $ack['bytes_sent']  = $this->tx[$id]->bytesSent();
                    $ack['chunk_count'] = $this->tx[$id]->chunkCount();
                }
                if ($conn->resourceId === $id) {
                    $conn->send(json_encode($ack));
                }
                // Record the outbound transmission so the dispatcher
                // sees it in history. BM doesn't echo our own TX back
                // to our peer, so without this the operator can never
                // confirm what they sent. Also helps the next-seat
                // dispatcher review what just went out.
                // Phase 85c-fix-6: row was already inserted at ptt_start
                // (with predictable audio_path from streamHex). Just
                // update duration once we know the actual byte count.
                $bytesSent = !empty($this->tx[$id]) ? $this->tx[$id]->bytesSent() : 0;
                $this->updateTxDuration($streamHex, $startedAt, $bytesSent);
                unset($this->tx[$id]);
            },
            function (string $err) use ($conn, $id) {
                if ($conn->resourceId === $id) {
                    $conn->send(json_encode(['type' => 'error', 'message' => 'TX upstream: ' . $err]));
                }
                if (isset($this->tx[$id])) {
                    $this->tx[$id]->abort();
                    unset($this->tx[$id]);
                }
            },
            $streamHex
        );

        $this->tx[$id] = $upstream;
        \plog("[Proxy] client {$id} ptt_start on channel #{$channelId} stream={$streamHex}");

        $upstream->openTx()->then(
            function () use ($conn, $id) {
                if ($conn->resourceId === $id) {
                    $conn->send(json_encode(['type' => 'tx_started']));
                }
            },
            function ($e) use ($conn, $id) {
                if ($conn->resourceId === $id) {
                    $conn->send(json_encode([
                        'type'    => 'error',
                        'message' => 'Failed to open upstream: ' . $e->getMessage(),
                    ]));
                }
                unset($this->tx[$id]);
            }
        );
    }

    private function handlePttEnd(ConnectionInterface $conn): void
    {
        $id = $conn->resourceId;
        if (!isset($this->tx[$id])) return;
        \plog("[Proxy] client {$id} ptt_end ({$this->tx[$id]->bytesSent()} bytes, " .
              "{$this->tx[$id]->chunkCount()} chunks)");
        $this->tx[$id]->closeTx();
        // tx_ack is sent from the onComplete callback when the bridge
        // responds; don't unset $this->tx[$id] here.
    }

    /**
     * Insert the dmr_messages row at PTT-start, with the WAV path
     * predicted from the streamHex we asked the bridge to use. This
     * decouples the history-card creation from the bridge HTTP
     * response, which has proven unreliable through our React socket.
     *
     * Duration is filled in by updateTxDuration() when ptt_end /
     * tx_ack arrives. If the bridge response never lands, the row
     * still shows up — with duration_ms=0 and audio_path set, which
     * is good enough for the dispatcher to see "I sent something."
     */
    private function recordTxStart(array $info): void
    {
        try {
            $startedAt = date('Y-m-d H:i:s', $info['started_at']);
            $audioPath = "/var/cache/ticketscad-dvswitch/recordings/tx-" .
                         $info['stream_hex'] . ".wav";

            $this->pdo->prepare(
                "INSERT INTO `{$this->prefix}dmr_messages`
                    (channel_id, direction, call_started_at, call_ended_at,
                     duration_ms, talkgroup, radio_id, radio_callsign,
                     member_id, audio_path, audio_format, created_at)
                 VALUES (?, 'tx', ?, NULL, 0, ?, NULL, ?, ?, ?, 'wav', NOW())"
            )->execute([
                (int) ($info['channel_id'] ?? 0),
                $startedAt,
                (string) ($info['talkgroup'] ?? ''),
                $info['user'] ?? 'dispatcher',
                $info['user_id'] ?? null,
                $audioPath,
            ]);
            \plog("[Proxy] TX start recorded: stream={$info['stream_hex']} " .
                  "audio={$audioPath}");
        } catch (\Exception $e) {
            \plog("[Proxy] failed to record TX start: " . $e->getMessage());
        }
    }

    /**
     * Update the duration on the row we inserted at ptt_start once
     * the upstream response lets us know the actual byte count.
     * Idempotent — if the row was deleted (unlikely) this is a no-op.
     */
    private function updateTxDuration(string $streamHex, int $startedAt, int $bytesSent): void
    {
        try {
            $audioPath = "/var/cache/ticketscad-dvswitch/recordings/tx-{$streamHex}.wav";
            $durationMs = $bytesSent > 0 ? (int) round($bytesSent / 16) : 0;
            $endedAt = date('Y-m-d H:i:s', $startedAt + (int) ceil($durationMs / 1000));
            $stmt = $this->pdo->prepare(
                "UPDATE `{$this->prefix}dmr_messages`
                    SET duration_ms = ?, call_ended_at = ?
                  WHERE audio_path = ? AND direction = 'tx'"
            );
            $stmt->execute([$durationMs, $endedAt, $audioPath]);
            \plog("[Proxy] TX duration updated: stream={$streamHex} " .
                  "dur={$durationMs}ms rows={$stmt->rowCount()}");
        } catch (\Exception $e) {
            \plog("[Proxy] failed to update TX duration: " . $e->getMessage());
        }
    }

    private function handleBinaryFrame(ConnectionInterface $conn, string $bytes): void
    {
        $id = $conn->resourceId;
        if (!isset($this->auth[$id])) return;  // unauth: drop
        if (!isset($this->tx[$id]) || !$this->tx[$id]->isOpen()) {
            // PCM arrived without a ptt_start — race during start;
            // silently buffer? For MVP just drop and log.
            \plog("[Proxy] client {$id} sent " . strlen($bytes) .
                  " binary bytes with no active TX — dropping");
            return;
        }
        $this->tx[$id]->writeFrame($bytes);
    }
}
