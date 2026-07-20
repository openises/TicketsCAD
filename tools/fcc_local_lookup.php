<?php
/**
 * Re-run FCC license enrichment using the LOCAL fcc_amateur / fcc_gmrs tables
 * (now populated from the dev install — see CLAUDE.md SonarQube/dev-data import).
 *
 * For each member:
 *   - If a callsign is on file, look it up in fcc_amateur. Refresh the
 *     member_callsigns row with the latest grant/expiry/operating-class data.
 *   - Search fcc_gmrs by last_name + zip (when both are available); fall back
 *     to last_name alone if no zip is on file. Records the GMRS callsign(s).
 *
 * Idempotent — uses the (member_id, callsign) UNIQUE key.
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

function fmt_date($d): ?string {
    if (!$d) return null;
    $d = trim((string) $d);
    if ($d === '' || $d === '0000-00-00') return null;
    return $d;
}

$members = db_fetch_all(
    "SELECT m.id, m.field2 AS first, m.field1 AS last, m.field4 AS callsign,
            m.field11 AS state, m.zip
     FROM `{$prefix}member` m
     WHERE m.deleted_at IS NULL
     ORDER BY m.field1, m.field2"
);
echo "[fcc-local] Members on roster: " . count($members) . "\n\n";

$amOk = 0; $amBad = 0; $gmrsOk = 0;
$invalidCallsigns = [];

foreach ($members as $m) {
    $mid  = (int) $m['id'];
    $name = $m['first'] . ' ' . $m['last'];
    $cs   = strtoupper(trim((string) $m['callsign']));

    // ── Amateur lookup ──
    if ($cs !== '') {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}fcc_amateur` WHERE callsign = ? LIMIT 1",
            [$cs]
        );
        if ($row) {
            db_query(
                "INSERT INTO `{$prefix}member_callsigns`
                    (member_id, callsign, license_type, oper_class, frn,
                     grant_date, expiry_date, grid_square, is_primary, source)
                 VALUES (?, ?, 'amateur', ?, ?, ?, ?, ?, 1, 'fcc_amateur')
                 ON DUPLICATE KEY UPDATE
                    oper_class  = VALUES(oper_class),
                    frn         = VALUES(frn),
                    grant_date  = VALUES(grant_date),
                    expiry_date = VALUES(expiry_date),
                    grid_square = VALUES(grid_square),
                    source      = VALUES(source)",
                [$mid, $cs, $row['oper_class'] ?: null, $row['frn'] ?: null,
                 fmt_date($row['grant_date']), fmt_date($row['expiry_date']),
                 $row['grid_square'] ?: null]
            );
            printf("  [AM]   %-20s %-8s class=%-7s exp=%s\n",
                $name, $cs, $row['oper_class'] ?: '-', fmt_date($row['expiry_date']) ?? '-');
            $amOk++;
        } else {
            $invalidCallsigns[] = "$name ($cs)";
            $amBad++;
            printf("  [AM!]  %-20s %-8s NOT FOUND in fcc_amateur (typo / cancelled?)\n", $name, $cs);
        }
    }

    // ── GMRS lookup by last name (+ optional zip) ──
    $params = [strtoupper((string) $m['last'])];
    $sql = "SELECT * FROM `{$prefix}fcc_gmrs` WHERE UPPER(last_name) = ?";
    $zip = preg_replace('/\D+/', '', (string) $m['zip']);
    if ($zip !== '') {
        $sql .= " AND zip LIKE ?";
        $params[] = $zip . '%';
    }
    $sql .= " LIMIT 5";
    try {
        $gmrs = db_fetch_all($sql, $params);
    } catch (Exception $e) { $gmrs = []; }

    // Filter by first-name match where possible (initial or full)
    $firstUpper = strtoupper((string) $m['first']);
    $matches = [];
    foreach ($gmrs as $g) {
        $gFirst = strtoupper((string) ($g['first_name'] ?? ''));
        if ($gFirst === '' || $gFirst === $firstUpper
            || strpos($gFirst, $firstUpper) === 0
            || strpos($firstUpper, $gFirst) === 0) {
            $matches[] = $g;
        }
    }

    foreach ($matches as $g) {
        $gcs = strtoupper((string) ($g['callsign'] ?? ''));
        if ($gcs === '') continue;
        db_query(
            "INSERT INTO `{$prefix}member_callsigns`
                (member_id, callsign, license_type, frn,
                 grant_date, expiry_date, is_primary, source)
             VALUES (?, ?, 'gmrs', ?, ?, ?, 0, 'fcc_gmrs')
             ON DUPLICATE KEY UPDATE
                frn         = VALUES(frn),
                grant_date  = VALUES(grant_date),
                expiry_date = VALUES(expiry_date),
                source      = VALUES(source)",
            [$mid, $gcs, $g['frn'] ?: null,
             fmt_date($g['grant_date']), fmt_date($g['expiry_date'])]
        );
        printf("  [GMRS] %-20s %-8s GMRS callsign for %s %s\n",
            $name, $gcs, $g['first_name'], $g['last_name']);
        $gmrsOk++;
    }
}

echo "\n[fcc-local] Amateur: $amOk verified, $amBad missing in fcc_amateur\n";
echo "[fcc-local] GMRS:    $gmrsOk records added\n";
if (!empty($invalidCallsigns)) {
    echo "\n[fcc-local] Callsigns on file but NOT in fcc_amateur (review manually):\n";
    foreach ($invalidCallsigns as $x) echo "    - $x\n";
}

echo "\n[fcc-local] Final license picture:\n";
$rows = db_fetch_all(
    "SELECT m.field2 AS first, m.field1 AS last, mc.callsign, mc.license_type,
            mc.oper_class, mc.expiry_date
     FROM `{$prefix}member_callsigns` mc
     JOIN `{$prefix}member` m ON mc.member_id = m.id
     ORDER BY m.field1, mc.license_type"
);
foreach ($rows as $r) {
    printf("    %-20s %-8s %-7s class=%-7s exp=%s\n",
        $r['first'] . ' ' . $r['last'],
        $r['callsign'],
        $r['license_type'],
        $r['oper_class'] ?? '-',
        $r['expiry_date'] ?? '-');
}
