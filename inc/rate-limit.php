<?php
/**
 * Phase 73x — Simple in-memory rate limiter for ingest endpoints.
 *
 * Used by /api/location.php (OwnTracks), /api/mesh.php (mesh bridges),
 * /api/dmr-ingest.php (DVSwitch bridges). Without a limit, a flood
 * of valid-token POSTs can fill location_reports / mesh_packet_log /
 * dmr_messages (rows with up to 65KB raw_data each) and DoS the DB
 * + spam SSE/broker.
 *
 * Strategy: per-bucket sliding window counter. APCu backs it when
 * available (process-shared, no DB hit). Falls back to a tmp file
 * bucket so single-process testing keeps working.
 *
 * Usage:
 *   require_once __DIR__ . '/../inc/rate-limit.php';
 *   if (!rate_limit_ok('owntracks:' . $tid, 60, 60)) {
 *       header('HTTP/1.1 429 Too Many Requests');
 *       header('Retry-After: 60');
 *       exit;
 *   }
 *
 * Both `limit` and `window_seconds` are caller-controlled, so an
 * operator can tune per-endpoint via the calling code without
 * needing config knobs.
 */

if (!function_exists('rate_limit_ok')) {

    /**
     * Returns true if the bucket has fewer than $limit hits within
     * the trailing $windowSeconds. Increments the counter as part
     * of the check (single round-trip).
     */
    function rate_limit_ok(string $bucket, int $limit, int $windowSeconds): bool
    {
        $now = time();
        $key = 'tcad:rl:' . sha1($bucket);

        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $entry = apcu_fetch($key);
            if (!is_array($entry) || ($now - (int) ($entry['t'] ?? 0)) >= $windowSeconds) {
                apcu_store($key, ['t' => $now, 'n' => 1], $windowSeconds);
                return true;
            }
            $entry['n'] = (int) $entry['n'] + 1;
            $remaining = max(1, $windowSeconds - ($now - (int) $entry['t']));
            apcu_store($key, $entry, $remaining);
            return $entry['n'] <= $limit;
        }

        // File-based fallback. Acceptable for low-volume installs.
        $dir = sys_get_temp_dir() . '/ticketscad-rate-limit';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $path = $dir . '/' . sha1($bucket) . '.bin';
        $entry = ['t' => $now, 'n' => 0];
        $fh = @fopen($path, 'c+b');
        if (!$fh) return true;  // can't open the bucket — fail open rather than block legit traffic
        try {
            @flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            if ($raw) {
                $decoded = @json_decode($raw, true);
                if (is_array($decoded)) $entry = $decoded;
            }
            if (($now - (int) ($entry['t'] ?? 0)) >= $windowSeconds) {
                $entry = ['t' => $now, 'n' => 1];
            } else {
                $entry['n'] = (int) $entry['n'] + 1;
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($entry));
            return $entry['n'] <= $limit;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * Emit a 429 response and exit. Caller decides what payload to
     * surface; this is the standard shape across all ingest endpoints.
     */
    function rate_limit_reject(int $retryAfterSeconds = 30): void
    {
        header('HTTP/1.1 429 Too Many Requests');
        header('Retry-After: ' . $retryAfterSeconds);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'rate limited']);
        exit;
    }
}
