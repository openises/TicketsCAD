<?php
/**
 * inc/dmr_stream_relay.php — the cURL-based NDJSON-to-SSE relay engine used
 * by api/dmr-stream.php, pulled out into its own include so it can be driven
 * directly by a test against a real loopback server instead of only through
 * api/dmr-stream.php's full session/RBAC-gated HTTP entry point (which a CLI
 * test can't reach the same way api/dmr-audio.php's tests couldn't -- see
 * inc/unit_owntracks.php's docblock for the same rationale, applied here).
 *
 * Reported by kmk1971 (openises/tickets#10, filed against the legacy repo by
 * mistake): the earlier fopen()+non-blocking+feof() reader died every
 * ~10-12s even during live traffic. Root cause, in full, lives in
 * api/dmr-stream.php's git history at the commit that replaced it; the short
 * version: the stream context's `http.timeout` governs socket READS on the
 * http:// wrapper, not just the initial connect, and feof() on a
 * non-blocking http:// wrapper stream can spuriously report EOF whenever its
 * internal buffer is momentarily empty -- both of which fire during totally
 * normal silence between DMR transmissions on a quiet talkgroup, which is
 * not a stall and must not be treated like one.
 */

/**
 * Consume an NDJSON stream and forward each `{"event": "...", ...}` line.
 *
 * @param string   $streamUrl    Full URL to GET (NDJSON, one event per line).
 * @param string   $token        Bearer token to send.
 * @param callable $onEvent      function(string $event, array $data): void
 * @param callable $onKeepalive  function(): void — called on a quiet tick.
 * @param callable $shouldStop   function(): bool — checked ~1x/sec AND on
 *                                every chunk; returning true aborts cleanly.
 * @param int      $keepaliveIntervalSec
 * @return array{eventCounts: array<string,int>, errno: int, error: string}
 */
function dmr_stream_relay(
    string $streamUrl,
    string $token,
    callable $onEvent,
    callable $onKeepalive,
    callable $shouldStop,
    int $keepaliveIntervalSec = 15
): array {
    $eventCounts = [];
    $buffer = '';
    $lastKeepalive = time();

    $forwardLine = function (string $line) use (&$eventCounts, $onEvent) {
        $line = trim($line);
        if ($line === '') return;
        $msg = json_decode($line, true);
        if (!is_array($msg) || empty($msg['event'])) return;

        $event = preg_replace('/[^a-z_]/', '', strtolower((string) $msg['event']));
        if ($event === '') $event = 'message';
        unset($msg['event']);

        // Phase 85c-fix-12: per-event counter for diagnosis.
        $eventCounts[$event] = ($eventCounts[$event] ?? 0) + 1;
        $onEvent($event, $msg);
    };

    $ch = curl_init($streamUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER      => ['Authorization: Bearer ' . $token, 'Accept: application/x-ndjson'],
        CURLOPT_CONNECTTIMEOUT  => 10,
        // No read-inactivity ceiling. A quiet DMR talkgroup can legitimately
        // send nothing for minutes; that is not a failure. Worst-case
        // runtime is bounded by $shouldStop() below, not by this.
        CURLOPT_TIMEOUT         => 0,
        CURLOPT_RETURNTRANSFER  => false,
        CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$buffer, $forwardLine, $shouldStop, &$lastKeepalive) {
            if ($shouldStop()) return 0; // aborts with CURLE_ABORTED_BY_CALLBACK
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $forwardLine(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
            }
            $lastKeepalive = time();
            return strlen($chunk);
        },
        // CURLOPT_PROGRESSFUNCTION fires roughly once a second for the life
        // of the transfer REGARDLESS of whether any bytes are moving --
        // unlike CURLOPT_WRITEFUNCTION, which only fires on data. That tick
        // is what lets a genuinely idle connection still send keepalives and
        // still get torn down on schedule when $shouldStop() flips true.
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow)
            use ($shouldStop, &$lastKeepalive, $onKeepalive, $keepaliveIntervalSec) {
            if ($shouldStop()) return 1; // non-zero also aborts
            if (time() - $lastKeepalive >= $keepaliveIntervalSec) {
                $onKeepalive();
                $lastKeepalive = time();
            }
            return 0;
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return ['eventCounts' => $eventCounts, 'errno' => $errno, 'error' => $error];
}
