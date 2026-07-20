<?php
/**
 * Phase 84x — radioid.net bulk import.
 *
 * Pulls the full radioid.net DMR user database (a JSON dump, NOT the
 * old CSV — radioid.net changed formats) and chunk-inserts it into
 * `radioid_users`. ~250k rows; runs in ~30-60 seconds depending on
 * network and disk speed.
 *
 * Usage:    php tools/radioid_bulk_import.php
 *           php tools/radioid_bulk_import.php --url=https://...   (override URL)
 *           php tools/radioid_bulk_import.php --dry              (download + parse,
 *                                                                  but don't write)
 *
 * Prereq:   Phase 84x migration (sql/run_phase84x_radioid_users.php) ran.
 *
 * Cadence:  Run once at install, then maybe quarterly. The on-demand
 *           cache-miss path in api/dmr-lookup.php picks up new operators
 *           between bulk runs.
 *
 * Politeness: radioid.net's TOS asks for aggressive caching. This script
 * is the polite version — one download instead of 250k API hits.
 */

require_once __DIR__ . '/../config.php';

$opts = getopt('', ['url:', 'dry']);
$url  = $opts['url'] ?? 'https://radioid.net/static/users.json';
$dry  = isset($opts['dry']);
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 84x — radioid.net bulk import\n";
echo "===================================\n";
echo "Source: {$url}\n";
echo "Dry-run: " . ($dry ? 'YES (no DB writes)' : 'no') . "\n\n";

// ── Confirm the cache table exists ───────────────────────────────
try {
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}radioid_users`");
} catch (Exception $e) {
    fwrite(STDERR, "[ERR] radioid_users table missing. Run first:\n");
    fwrite(STDERR, "       php sql/run_phase84x_radioid_users.php\n");
    exit(2);
}

// ── Download ─────────────────────────────────────────────────────
echo "Downloading… ";
$t0 = microtime(true);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'TicketsCAD/4.0 (DMR radio widget bulk import)',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
if ($body === false || $code !== 200) {
    fwrite(STDERR, "FAILED (HTTP {$code}: {$err})\n");
    exit(3);
}
$bytes = strlen($body);
printf("OK %s in %.1fs\n", number_format($bytes), microtime(true) - $t0);

// ── Parse JSON ───────────────────────────────────────────────────
echo "Parsing… ";
$t1 = microtime(true);
$data = json_decode($body, true);
unset($body);  // free the raw download
if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
    fwrite(STDERR, "FAILED (unexpected JSON shape; got keys: " .
        implode(',', array_keys($data ?? [])) . ")\n");
    exit(4);
}
$users = $data['users'];
unset($data);
$total = count($users);
printf("OK %s rows in %.1fs\n", number_format($total), microtime(true) - $t1);

if ($total === 0) {
    echo "Empty user list — nothing to import.\n";
    exit(0);
}

if ($dry) {
    echo "Dry-run: skipping DB writes.\n";
    $sample = array_slice($users, 0, 3);
    echo "First 3 rows:\n";
    foreach ($sample as $u) {
        echo "  " . json_encode($u) . "\n";
    }
    exit(0);
}

// ── Bulk insert in chunks ────────────────────────────────────────
$chunkSize = 500;
$inserted = 0;
$skipped  = 0;
echo "Importing in chunks of {$chunkSize}…\n";
$t2 = microtime(true);

$db = $GLOBALS['db'] ?? null;
if (!$db) {
    fwrite(STDERR, "[ERR] no \$GLOBALS['db'] PDO handle\n");
    exit(5);
}

for ($i = 0; $i < $total; $i += $chunkSize) {
    $chunk = array_slice($users, $i, $chunkSize);
    $values = [];
    $params = [];
    foreach ($chunk as $u) {
        $rid = (int) ($u['id'] ?? 0);
        if ($rid <= 0) { $skipped++; continue; }
        $values[] = '(?, ?, ?, ?, ?, ?, ?, NOW())';
        $params[] = $rid;
        $params[] = substr((string) ($u['callsign'] ?? ''), 0, 16);
        $params[] = substr((string) ($u['fname']    ?? ''), 0, 64);
        $params[] = substr((string) ($u['surname']  ?? ''), 0, 64);
        $params[] = substr((string) ($u['country']  ?? ''), 0, 64);
        $params[] = substr((string) ($u['state']    ?? ''), 0, 64);
        $params[] = substr((string) ($u['city']     ?? ''), 0, 64);
    }
    if (!$values) continue;
    $sql = "INSERT INTO `{$prefix}radioid_users`
              (dmr_id, callsign, fname, surname, country, state, city, fetched_at)
            VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
              callsign=VALUES(callsign), fname=VALUES(fname),
              surname=VALUES(surname), country=VALUES(country),
              state=VALUES(state), city=VALUES(city),
              fetched_at=NOW()";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $inserted += count($values);
    } catch (Exception $e) {
        fwrite(STDERR, "[WARN] chunk at offset {$i} failed: " . $e->getMessage() . "\n");
        $skipped += count($values);
    }
    if (($i / $chunkSize) % 20 === 0) {
        printf("  %s / %s (%.0f%%)\n",
            number_format(min($i + $chunkSize, $total)),
            number_format($total),
            ($i + $chunkSize) * 100.0 / $total);
    }
}

$elapsed = microtime(true) - $t2;
printf("\nImport done: inserted %s, skipped %s, %.1fs wall\n",
    number_format($inserted), number_format($skipped), $elapsed);
$now = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}radioid_users`");
echo "Cache now holds " . number_format($now) . " row(s).\n";
