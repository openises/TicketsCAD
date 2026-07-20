<?php
/**
 * Look up amateur (and optionally GMRS) FCC license details for every member
 * with a callsign on file, and store the result in `member_callsigns`.
 *
 * Amateur lookups: callook.info public JSON API (no key needed).
 *   GET https://callook.info/<CALL>/json
 *
 * GMRS: typical practice is to look up by name+zip in the FCC ULS database.
 *   This requires either the FCC-ULS-API service or a local fcc_gmrs table.
 *   Both are absent on a fresh install, so this script reports GMRS as
 *   "skipped — needs FCC ULS data" rather than fabricating fake matches.
 *
 * Idempotent — uses the existing (member_id, callsign) UNIQUE key. Re-runs
 * just refresh the grant/expiry/operating-class fields on existing rows.
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

function callook_lookup(string $callsign, int $timeout = 6) {
    $url = 'https://callook.info/' . urlencode($callsign) . '/json';
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'user_agent'    => 'NewUI-CAD/4.0 Bloomington-AUXCOMM',
        ],
        'ssl'  => ['verify_peer' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['ok' => false, 'err' => 'fetch_failed'];
    $data = json_decode($body, true);
    if (!is_array($data)) return ['ok' => false, 'err' => 'bad_json'];
    if (($data['status'] ?? '') !== 'VALID') {
        return ['ok' => false, 'err' => 'status=' . ($data['status'] ?? '?')];
    }
    return ['ok' => true, 'data' => $data];
}

function parse_callook_date(?string $s): ?string {
    // Callook returns MM/DD/YYYY; convert to ISO YYYY-MM-DD
    $s = trim((string) $s);
    if ($s === '') return null;
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) {
        return $m[3] . '-' . $m[1] . '-' . $m[2];
    }
    return null;
}

// ── Pull every (member_id, callsign) we have on file ─────────────
$rows = db_fetch_all(
    "SELECT id, field2 AS first, field1 AS last, field4 AS callsign
     FROM `{$prefix}member`
     WHERE field4 IS NOT NULL AND field4 != ''
     ORDER BY field1, field2"
);
echo "[fcc] Members with callsigns on file: " . count($rows) . "\n\n";

$ok = 0; $miss = 0;
foreach ($rows as $r) {
    $mid  = (int) $r['id'];
    $cs   = strtoupper(trim((string) $r['callsign']));
    $name = $r['first'] . ' ' . $r['last'];

    printf("  %-20s %-8s ... ", $name, $cs);

    $res = callook_lookup($cs);
    if (!$res['ok']) {
        echo "MISS (" . $res['err'] . ")\n";
        $miss++;
        continue;
    }
    $d = $res['data'];

    $operClass  = $d['current']['operClass'] ?? null;
    $frn        = $d['otherInfo']['frn']     ?? null;
    $grantDate  = parse_callook_date($d['otherInfo']['grantDate']  ?? null);
    $expiryDate = parse_callook_date($d['otherInfo']['expiryDate'] ?? null);
    $gridSquare = $d['location']['gridsquare'] ?? null;

    db_query(
        "INSERT INTO `{$prefix}member_callsigns`
            (member_id, callsign, license_type, oper_class, frn,
             grant_date, expiry_date, grid_square, is_primary, source)
         VALUES (?, ?, 'amateur', ?, ?, ?, ?, ?, 1, 'callook.info')
         ON DUPLICATE KEY UPDATE
            oper_class  = VALUES(oper_class),
            frn         = VALUES(frn),
            grant_date  = VALUES(grant_date),
            expiry_date = VALUES(expiry_date),
            grid_square = VALUES(grid_square),
            source      = 'callook.info'",
        [$mid, $cs, $operClass, $frn, $grantDate, $expiryDate, $gridSquare]
    );

    printf("OK  class=%-7s exp=%s  grid=%s\n",
        $operClass ?? '?',
        $expiryDate ?? '?',
        $gridSquare ?? '?');
    $ok++;
}

echo "\n[fcc] Amateur lookups: $ok updated, $miss missed\n";

// ── Final dump ──
echo "\n[fcc] member_callsigns table:\n";
$rows = db_fetch_all(
    "SELECT m.field2 AS first, m.field1 AS last, mc.callsign, mc.license_type,
            mc.oper_class, mc.expiry_date, mc.grid_square, mc.frn
     FROM `{$prefix}member_callsigns` mc
     JOIN `{$prefix}member` m ON mc.member_id = m.id
     ORDER BY m.field1, mc.license_type"
);
foreach ($rows as $r) {
    printf("  %-20s %-8s %-9s class=%-7s exp=%-10s grid=%-6s FRN=%s\n",
        $r['first'] . ' ' . $r['last'],
        $r['callsign'],
        $r['license_type'],
        $r['oper_class'] ?? '-',
        $r['expiry_date'] ?? '-',
        $r['grid_square'] ?? '-',
        $r['frn'] ?? '-');
}

// ── GMRS note ──
echo "\n[fcc] GMRS lookups — SKIPPED.\n";
echo "      callook.info doesn't index GMRS. To enrich GMRS for this roster:\n";
echo "        (a) install the FCC-ULS-API service (https://github.com/porcej/FCC-ULS-API)\n";
echo "            and configure the URL in Settings > Integrations > Callsign Lookup, OR\n";
echo "        (b) download the FCC ULS L_gmrs.zip dump and import via tools/import-fcc.php.\n";
echo "      Once either is in place, this script can be extended to look up by\n";
echo "      (last name + zip) for each member; today both data sources are absent.\n";
