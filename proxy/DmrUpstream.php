<?php
/**
 * NewUI v4.0 — DMR Upstream (Phase 85c)
 *
 * Per-WS-connection holder for the streaming HTTP POST to the bridge's
 * /tx/stream endpoint. We use raw React\Socket to write chunked
 * transfer-encoded HTTP/1.1 because:
 *   - We control both sides; protocol can be hand-rolled
 *   - react/http isn't installed (would be a new composer dep)
 *   - The bridge tested clean with chunked POST from curl in Phase 85b
 *   - Server-side streaming is reliable, unlike the browser-side
 *     fetch(body: ReadableStream) we found unworkable
 *
 * Lifecycle:
 *   ptt_start command -> openTx() opens TCP, sends HTTP headers
 *   binary WS frame  -> writeFrame() emits one chunked-encoded chunk
 *   ptt_end command  -> closeTx() writes "0\r\n\r\n" and reads response
 *
 * One Upstream per active TX. The proxy can hold a separate one for
 * each connected browser; the bridge accepts concurrent TX requests
 * and serialises them internally (one active stream_id at a time per
 * channel; trying to TX while another stream is active produces a
 * collision that the user would notice on the radio).
 */

namespace NewUI\Proxy;

use React\EventLoop\LoopInterface;
use React\Socket\TcpConnector;
use React\Socket\ConnectionInterface;

class DmrUpstream
{
    /** @var LoopInterface */
    private $loop;
    /** @var array{bridge_host:string,bridge_port:int,bridge_token:string,label:string,id:int} */
    private $channel;
    /** @var callable Called when the bridge returns its final response: fn(string $jsonBody, int $httpCode) */
    private $onComplete;
    /** @var callable|null Called on any error: fn(string $msg) */
    private $onError;
    /** @var string|null Hex stream-id we ask the bridge to use; lets us predict the WAV path. */
    private $streamHex = null;

    /** @var ConnectionInterface|null */
    private $conn = null;
    /** @var bool */
    private $headersSent = false;
    /** @var bool */
    private $closed = false;
    /** @var int total PCM bytes written to upstream (for diagnostics) */
    private $bytesSent = 0;
    /** @var int number of chunks written */
    private $chunkCount = 0;
    /** @var string accumulated response from bridge */
    private $responseBuf = '';
    /** @var float|null monotonic time when TX started */
    private $startedAt = null;

    public function __construct(
        LoopInterface $loop,
        array $channel,
        callable $onComplete,
        ?callable $onError = null,
        ?string $streamHex = null
    ) {
        $this->loop = $loop;
        $this->channel = $channel;
        $this->onComplete = $onComplete;
        $this->onError = $onError;
        $this->streamHex = $streamHex;
    }

    public function streamHex(): ?string { return $this->streamHex; }

    /**
     * Open the TCP connection to the bridge and send the HTTP headers
     * for the streaming POST. Returns a promise that resolves once
     * headers are flushed (or rejects on connect error).
     */
    public function openTx(): \React\Promise\PromiseInterface
    {
        $host = $this->channel['bridge_host'];
        $port = (int) $this->channel['bridge_port'];
        $token = $this->channel['bridge_token'];

        // Use TcpConnector directly (NOT the higher-level Connector
        // which pulls in React\Cache via the DNS resolver). Our
        // bridge_host is always a literal IP from the dmr_channels
        // table, so DNS is unnecessary and the cache dependency
        // isn't installed.
        $connector = new TcpConnector($this->loop);
        $deferred = new \React\Promise\Deferred();
        $this->startedAt = microtime(true);

        $connector->connect("{$host}:{$port}")->then(
            function (ConnectionInterface $conn) use ($host, $port, $token, $deferred) {
                $this->conn = $conn;
                $streamHeader = $this->streamHex
                    ? "X-Stream-Id: {$this->streamHex}\r\n"
                    : "";
                $headers =
                    "POST /tx/stream HTTP/1.1\r\n" .
                    "Host: {$host}:{$port}\r\n" .
                    "Authorization: Bearer {$token}\r\n" .
                    "Content-Type: application/octet-stream\r\n" .
                    "Transfer-Encoding: chunked\r\n" .
                    "X-Source: dmr-proxy\r\n" .
                    $streamHeader .
                    "Connection: close\r\n" .
                    "\r\n";
                $conn->write($headers);
                $this->headersSent = true;

                // Collect response bytes; on EOF parse status + body.
                $conn->on('data', function ($data) {
                    $this->responseBuf .= $data;
                });
                $conn->on('end', function () { $this->finishResponse(); });
                $conn->on('close', function () { $this->finishResponse(); });
                $conn->on('error', function ($e) {
                    if ($this->onError) ($this->onError)("upstream error: " . $e->getMessage());
                });
                $deferred->resolve(null);
            },
            function ($e) use ($deferred, $host, $port) {
                $msg = "could not connect to bridge {$host}:{$port}: " . $e->getMessage();
                if ($this->onError) ($this->onError)($msg);
                $deferred->reject(new \Exception($msg));
            }
        );

        return $deferred->promise();
    }

    /**
     * Write one PCM frame as a chunked-transfer-encoding chunk.
     * The bridge feeds these directly into StreamingDMRTransmitter
     * and emits AMBE bursts at 60 ms wire pace.
     */
    public function writeFrame(string $pcmBytes): void
    {
        if ($this->closed || !$this->headersSent || !$this->conn) return;
        $len = strlen($pcmBytes);
        if ($len === 0) return;
        // chunked-encoding: <hex-length>\r\n<data>\r\n
        $this->conn->write(dechex($len) . "\r\n" . $pcmBytes . "\r\n");
        $this->bytesSent += $len;
        $this->chunkCount++;
    }

    /**
     * Close the request body cleanly. Sends the 0-chunk terminator;
     * the bridge sees EOF, fires the DMR terminator bursts, and
     * responds with {ok:true, packets_sent:N, ...}. onComplete fires
     * once we read that response.
     */
    public function closeTx(): void
    {
        if ($this->closed) return;
        if ($this->conn && $this->headersSent) {
            $this->conn->write("0\r\n\r\n");
        }
        $this->closed = true;
        // Connection will close after bridge sends response (we asked
        // for Connection: close); finishResponse() fires from the
        // 'end' or 'close' event.
    }

    /**
     * Hard-close — used on browser disconnect or fatal error.
     * Doesn't wait for the bridge response.
     */
    public function abort(): void
    {
        if ($this->conn) {
            $this->conn->close();
            $this->conn = null;
        }
        $this->closed = true;
    }

    public function isOpen(): bool { return $this->conn !== null && !$this->closed; }
    /** True once closeTx() has been called — the request body is done
     *  and we're awaiting the bridge response. Used by the proxy to
     *  decide whether a browser disconnect should abort the upstream
     *  (still streaming) or let it finish (recording will land in DB). */
    public function isAwaitingResponse(): bool { return $this->closed && !$this->finished; }
    public function bytesSent(): int { return $this->bytesSent; }
    public function chunkCount(): int { return $this->chunkCount; }

    private $finished = false;
    private function finishResponse(): void
    {
        if ($this->finished) return;
        $this->finished = true;

        $code = 0;
        $body = '';
        if ($this->responseBuf !== '') {
            // Parse HTTP/1.1 response: first line is status, headers
            // follow, blank line separates headers from body.
            $split = strpos($this->responseBuf, "\r\n\r\n");
            if ($split !== false) {
                $headerBlock = substr($this->responseBuf, 0, $split);
                $body = substr($this->responseBuf, $split + 4);
                if (preg_match('/^HTTP\/1\.[01]\s+(\d{3})/', $headerBlock, $m)) {
                    $code = (int) $m[1];
                }
            } else {
                $body = $this->responseBuf;
            }
        }
        $elapsed = $this->startedAt ? (microtime(true) - $this->startedAt) : 0.0;
        \plog(sprintf("[Upstream] TX done: HTTP %d, %d bytes sent in %d chunks over %.2fs",
            $code, $this->bytesSent, $this->chunkCount, $elapsed));
        ($this->onComplete)($body, $code);
    }
}
