<?php
/**
 * Look up DMR IDs from radioid.net for every member.
 *
 * Strategy:
 *   1. For members WITH a callsign: query radioid.net/api/dmr/user/?callsign=XXX.
 *      The callsign filter is exact and reliable.
 *   2. For members WITHOUT a callsign (or whose callsign came back INVALID):
 *      pull the full state=Minnesota result set (paginated) and match locally
 *      on (first_name, last_name). The API ignores ?fname=/?surname=, so the
 *      state pull is the only practical name search.
 *
 * Result handling:
 *   - DMR ID(s) are appended to member.notes in the form
 *       "DMR ID: 3175576 (W0AM)\nDMR ID: 3175577 (also)"
 *     so a person with multiple radio IDs gets all of them.
 *   - If a member with no callsign on file matches by name and the radioid
 *     record DOES have a callsign, that callsign is added to member.field4
 *     too (the legacy callsign column).
 *   - Idempotent: existing "DMR ID:" lines in notes are stripped and rewritten.
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Helpers ──
function radioid_get(string $url, int $timeout = 10) {
    // Simple UA — radioid.net appears to throttle/filter custom UA strings.
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'user_agent'    => 'Mozilla/5.0 (compatible; NewUI/4.0)',
            'header'        => "Accept: application/json\r\n",
        ],
        'ssl'  => ['verify_peer' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function lookup_by_callsign(string $cs): array {
    $url = 'https://www.radioid.net/api/dmr/user/?callsign=' . urlencode($cs);
    $d = radioid_get($url);
    return $d['results'] ?? [];
}

function fetch_all_state(string $state): array {
    $all = [];
    $page = 1;
    $expected = null;
    while (true) {
        $url = 'https://www.radioid.net/api/dmr/user/?state=' . urlencode($state) . '&page=' . $page;
        $d = radioid_get($url, 15);

        // Retry-on-rate-limit: an empty response with more pages expected means
        // we got throttled. Sleep 3s and try the same page once more.
        if ((!$d || empty($d['results'])) && $expected !== null && $page <= $expected) {
            usleep(3_000_000);
            $d = radioid_get($url, 15);
        }
        if (!$d || empty($d['results'])) break;

        $all = array_merge($all, $d['results']);
        $expected = (int) ($d['pages'] ?? 1);
        if ($page >= $expected) break;
        $page++;
        if ($page > 50) break;       // sanity limit
        usleep(400_000);             // 0.4s between page fetches — keeps us under the rate limit
    }
    return $all;
}

function strip_dmr_lines(string $notes): string {
    // Drop any line beginning with "DMR ID:" so we can rewrite
    $lines = preg_split("/\r\n|\n|\r/", $notes);
    $kept = array_filter($lines, function ($l) {
        return !preg_match('/^\s*DMR ID:/i', $l);
    });
    return rtrim(implode("\n", $kept));
}

function append_dmr(string $notes, array $hits): string {
    $base = strip_dmr_lines($notes);
    $lines = [];
    foreach ($hits as $h) {
        $rid  = (int) ($h['radio_id'] ?? $h['id'] ?? 0);
        if ($rid <= 0) continue;
        $tag = $h['callsign'] ?? '';
        $tag = $tag !== '' ? " ({$tag})" : '';
        $lines[] = "DMR ID: {$rid}{$tag}";
    }
    if (empty($lines)) return $base;
    return ($base !== '' ? $base . "\n" : '') . implode("\n", $lines);
}

// ── Pull every member ──
$members = db_fetch_all(
    "SELECT id, field2 AS first, field1 AS last, field4 AS callsign, notes
     FROM `{$prefix}member`
     WHERE deleted_at IS NULL
     ORDER BY field1, field2"
);
echo "[radioid] Members on roster: " . count($members) . "\n\n";

// ── Pre-load DMR rosters for the states we expect members in ──
//    (radioid.net's API ignores ?fname=/?surname= so we have to pull the
//     state set and do the name match locally.)
$states = ['Minnesota', 'South Dakota', 'Wisconsin', 'Iowa', 'North Dakota'];
$pool = [];
foreach ($states as $st) {
    echo "[radioid] Fetching DMR roster for {$st}...\n";
    $rows = fetch_all_state($st);
    echo "[radioid]   {$st}: " . count($rows) . " records\n";
    $pool = array_merge($pool, $rows);
}
echo "[radioid] Combined pool: " . count($pool) . " records\n\n";

// Index by callsign and by (first, last) for quick local lookup. We avoid
// re-querying ?callsign= per member after pulling the state pools because
// radioid.net rate-limits aggressive callers — the pool already has every
// record for the states we fetched.
$byCallsign = [];
foreach ($pool as $r) {
    $cs = strtoupper(trim((string) ($r['callsign'] ?? '')));
    if ($cs !== '') {
        if (!isset($byCallsign[$cs])) $byCallsign[$cs] = [];
        $byCallsign[$cs][] = $r;
    }
}
echo "[radioid] Indexed " . count($byCallsign) . " unique callsigns from the pool\n";
foreach (['W0AM','KE0OR','AD0UQ','AE0EE'] as $debug) {
    echo "[radioid]   debug: " . $debug . " in pool? " . (isset($byCallsign[$debug]) ? 'YES (' . count($byCallsign[$debug]) . ')' : 'NO') . "\n";
}
echo "\n";

$byName = [];
foreach ($pool as $r) {
    $first = strtolower(trim((string) ($r['fname']   ?? '')));
    $last  = strtolower(trim((string) ($r['surname'] ?? '')));
    if ($first === '' && $last === '') continue;
    // Some entries put the full name in `fname` and leave surname blank — be tolerant
    if ($last === '' && strpos($first, ' ') !== false) {
        $parts = explode(' ', $first, 2);
        $first = $parts[0];
        $last  = $parts[1];
    }
    $key = $first . '|' . $last;
    if (!isset($byName[$key])) $byName[$key] = [];
    $byName[$key][] = $r;
}

$totalAdded = 0;
$totalCallsigns = 0;
foreach ($members as $m) {
    $name = trim($m['first'] . ' ' . $m['last']);
    $cs   = strtoupper(trim((string) $m['callsign']));
    $hits = [];
    $matchedHow = '';

    if ($cs !== '') {
        // Look up in the pre-pulled state pool first (avoids per-call API hits
        // that get rate-limited). Fall back to the live API for callsigns
        // outside our pulled states.
        if (isset($byCallsign[$cs])) {
            $hits = $byCallsign[$cs];
            $matchedHow = "callsign=$cs (pool)";
        } else {
            $hits = lookup_by_callsign($cs);
            $matchedHow = "callsign=$cs (api)";
        }
    }

    // Fallback / supplement: name match on the state pool
    if (empty($hits)) {
        $first = strtolower(trim($m['first']));
        $last  = strtolower(trim($m['last']));
        $key = $first . '|' . $last;
        if (isset($byName[$key])) {
            $hits = $byName[$key];
            $matchedHow = 'name=' . $key;
        }
    }

    // Last resort: fuzzy last-name match (substring) for typos like
    // "Holden" vs "Holdman". Only fires when the exact match misses;
    // we still require the first-name to match (initial OK).
    if (empty($hits)) {
        $first = strtolower(trim($m['first']));
        $last  = strtolower(trim($m['last']));
        if (strlen($last) >= 4) {
            $stem = substr($last, 0, 4);    // e.g. "hold"
            $candidates = [];
            foreach ($pool as $r) {
                $rFirst = strtolower(trim((string) ($r['fname']   ?? '')));
                $rLast  = strtolower(trim((string) ($r['surname'] ?? '')));
                if ($rLast === '' && strpos($rFirst, ' ') !== false) {
                    [$rFirst, $rLast] = array_pad(explode(' ', $rFirst, 2), 2, '');
                }
                if (strpos($rLast, $stem) === 0
                    && ($rFirst === $first
                        || strpos($rFirst, $first) === 0
                        || (strlen($first) > 0 && $rFirst[0] === $first[0]))) {
                    $candidates[] = $r;
                }
            }
            if (!empty($candidates)) {
                $hits = $candidates;
                $matchedHow = "fuzzy=$first $stem*";
            }
        }
    }

    if (empty($hits)) {
        printf("  %-22s %-8s no DMR ID found\n", $name, $cs ?: '-');
        continue;
    }

    // De-dup hits by radio_id (a single member can have multiple DMR IDs)
    $byId = [];
    foreach ($hits as $h) {
        $rid = (int) ($h['radio_id'] ?? $h['id'] ?? 0);
        if ($rid > 0 && !isset($byId[$rid])) $byId[$rid] = $h;
    }
    $uniqueHits = array_values($byId);

    // If member had no callsign but radioid does, adopt it
    $newCallsign = null;
    if ($cs === '') {
        foreach ($uniqueHits as $h) {
            if (!empty($h['callsign'])) {
                $newCallsign = strtoupper($h['callsign']);
                break;
            }
        }
    }

    $newNotes = append_dmr((string) $m['notes'], $uniqueHits);

    $sets = ['notes' => $newNotes];
    if ($newCallsign !== null) {
        $sets['field4'] = $newCallsign; // legacy callsign column
        $totalCallsigns++;
    }

    $setSql = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($sets)));
    $params = array_values($sets);
    $params[] = (int) $m['id'];
    db_query("UPDATE `{$prefix}member` SET {$setSql} WHERE id = ?", $params);

    $ridList = implode(',', array_keys($byId));
    $extra   = $newCallsign ? " (added callsign $newCallsign)" : '';
    printf("  %-22s %-8s %s -> DMR=[%s]%s\n",
        $name, $cs ?: ($newCallsign ?? '-'), $matchedHow, $ridList, $extra);
    $totalAdded += count($uniqueHits);
}

echo "\n[radioid] DMR IDs added/refreshed: $totalAdded across the roster\n";
echo "[radioid] New callsigns recovered:  $totalCallsigns\n";
