<?php
/**
 * Mesh nodes-tab signal-quality feature (2026-06-26).
 *
 * Covers the SNR/RSSI/hops pipeline that was previously blank, end to end:
 *   1. Schema — mesh_nodes carries last_snr / last_rssi / last_hops.
 *   2. api/mesh.php node_info upsert STORES snr/rssi/hops (both the enriched
 *      MeshCore shape and the base Meshtastic shape), and a subsequent
 *      null-bearing upsert PRESERVES prior values via COALESCE (so an
 *      identity-only NodeInfo broadcast can't wipe good signal data).
 *   3. The nodes API query selects last_snr/last_rssi/last_hops.
 *   4. bridge_v2.py — NodeInfoEvent carries snr/rssi/hops and the node-DB
 *      scan + NODEINFO_APP receive path populate them.
 *   5. mesh-console.js — the nodes table has click-to-sort columns and renders
 *      the SNR/RSSI/Hops columns from the row fields.
 *
 * Self-contained: seeds a temp node row, asserts, cleans up. Never deploys,
 * never touches a live bridge.
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$total  = 0;
$passed = 0;
$failed = [];

function ms_assert(string $name, bool $cond, string $detail = '') {
    global $total, $passed, $failed;
    $total++;
    if ($cond) { $passed++; echo "  PASS  $name\n"; }
    else { $failed[] = "$name — $detail"; echo "  FAIL  $name — $detail\n"; }
}

function ms_has_col(string $table, string $col): bool {
    try {
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $col]
        );
    } catch (Exception $e) { return false; }
}

// ── 1. Schema ──
ms_assert('schema — mesh_nodes.last_snr exists',  ms_has_col($prefix . 'mesh_nodes', 'last_snr'));
ms_assert('schema — mesh_nodes.last_rssi exists', ms_has_col($prefix . 'mesh_nodes', 'last_rssi'));
ms_assert('schema — mesh_nodes.last_hops exists', ms_has_col($prefix . 'mesh_nodes', 'last_hops'));

// ── 2. Upsert stores + COALESCE-preserves snr/rssi/hops ──
$testId = '!sigtest01';
try {
    db_query("DELETE FROM `{$prefix}mesh_nodes` WHERE node_id = ?", [$testId]);
    // Insert with signal values (mirrors the base node_info upsert shape).
    db_query(
        "INSERT INTO `{$prefix}mesh_nodes`
            (node_id, protocol, bridge_id, short_name, long_name, hw_model, role,
             last_lat, last_lng, last_alt_m, last_snr, last_rssi, last_hops, last_seen_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(3))
         ON DUPLICATE KEY UPDATE
            last_snr  = COALESCE(VALUES(last_snr),  last_snr),
            last_rssi = COALESCE(VALUES(last_rssi), last_rssi),
            last_hops = COALESCE(VALUES(last_hops), last_hops),
            last_seen_at = NOW(3)",
        [$testId, 'meshtastic', 0, 'SIG', 'Signal Test', 'HELTEC_V3', 'CLIENT',
         null, null, null, 6.75, -88, 3]
    );
    $r = db_fetch_one("SELECT last_snr, last_rssi, last_hops FROM `{$prefix}mesh_nodes` WHERE node_id = ?", [$testId]);
    ms_assert('upsert — stores last_snr',  abs((float) $r['last_snr'] - 6.75) < 0.001, 'got ' . ($r['last_snr'] ?? 'null'));
    ms_assert('upsert — stores last_rssi', (int) $r['last_rssi'] === -88, 'got ' . ($r['last_rssi'] ?? 'null'));
    ms_assert('upsert — stores last_hops', (int) $r['last_hops'] === 3, 'got ' . ($r['last_hops'] ?? 'null'));

    // Re-upsert with NULL signal (identity-only broadcast) — must preserve.
    db_query(
        "INSERT INTO `{$prefix}mesh_nodes`
            (node_id, protocol, bridge_id, short_name, long_name, hw_model, role,
             last_lat, last_lng, last_alt_m, last_snr, last_rssi, last_hops, last_seen_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(3))
         ON DUPLICATE KEY UPDATE
            last_snr  = COALESCE(VALUES(last_snr),  last_snr),
            last_rssi = COALESCE(VALUES(last_rssi), last_rssi),
            last_hops = COALESCE(VALUES(last_hops), last_hops),
            last_seen_at = NOW(3)",
        [$testId, 'meshtastic', 0, 'SIG', 'Signal Test', 'HELTEC_V3', 'CLIENT',
         null, null, null, null, null, null]
    );
    $r2 = db_fetch_one("SELECT last_snr, last_rssi, last_hops FROM `{$prefix}mesh_nodes` WHERE node_id = ?", [$testId]);
    ms_assert('upsert — COALESCE preserves snr on null re-upsert',  abs((float) $r2['last_snr'] - 6.75) < 0.001);
    ms_assert('upsert — COALESCE preserves rssi on null re-upsert', (int) $r2['last_rssi'] === -88);
    ms_assert('upsert — COALESCE preserves hops on null re-upsert', (int) $r2['last_hops'] === 3);

    db_query("DELETE FROM `{$prefix}mesh_nodes` WHERE node_id = ?", [$testId]);
} catch (Exception $e) {
    ms_assert('upsert — round-trip executed', false, $e->getMessage());
}

// ── Source-level assertions ──
$api    = file_get_contents(__DIR__ . '/../api/mesh.php');
$bridge = file_get_contents(__DIR__ . '/../services/meshtastic/bridge_v2.py');
$js     = file_get_contents(__DIR__ . '/../assets/js/mesh-console.js');

// 3. nodes API selects the signal columns.
ms_assert('api/mesh.php — nodes query selects last_snr/last_rssi',
    strpos($api, 'n.last_snr, n.last_rssi') !== false || strpos($api, 'last_snr, n.last_rssi') !== false);
// node_info upsert references the signal columns + binds $n['snr'].
ms_assert('api/mesh.php — node_info upsert writes last_snr',
    strpos($api, "last_snr      = COALESCE(VALUES(last_snr)") !== false
    || strpos($api, "last_snr    = COALESCE(VALUES(last_snr)") !== false);
ms_assert('api/mesh.php — node_info binds incoming snr/rssi/hops',
    strpos($api, "\$n['snr']") !== false && strpos($api, "\$n['rssi']") !== false && strpos($api, "\$n['hops']") !== false);

// 4. bridge captures + sends snr/rssi/hops.
ms_assert('bridge_v2.py — NodeInfoEvent declares snr/rssi/hops fields',
    strpos($bridge, 'snr:') !== false && strpos($bridge, 'hops:') !== false);
ms_assert('bridge_v2.py — node-DB scan captures node snr/hopsAway',
    strpos($bridge, "n.get('snr')") !== false && strpos($bridge, "n.get('hopsAway')") !== false);
ms_assert('bridge_v2.py — node_info POST forwards snr/rssi/hops',
    strpos($bridge, "'snr', 'rssi', 'hops'") !== false);
ms_assert('bridge_v2.py — NODEINFO_APP receive carries snr/rssi/hops',
    strpos($bridge, 'snr=snr, rssi=rssi, hops=hops') !== false);

// 5. JS renders sortable columns + SNR/RSSI/Hops cells.
ms_assert('mesh-console.js — nodes table is sortable (click handler)',
    strpos($js, 'onNodeSortClick') !== false && strpos($js, 'mesh-sortable') !== false);
ms_assert('mesh-console.js — sort comparator is numeric-aware',
    strpos($js, 'nodeSortVal') !== false && strpos($js, "type: 'num'") !== false);
ms_assert('mesh-console.js — sort indicator (arrows) on active header',
    strpos($js, 'mesh-sort-ind') !== false && (strpos($js, '▲') !== false && strpos($js, '▼') !== false));
ms_assert('mesh-console.js — renders SNR/RSSI/Hops from row fields',
    strpos($js, 'n.last_snr') !== false && strpos($js, 'n.last_rssi') !== false && strpos($js, 'n.last_hops') !== false);
ms_assert('mesh-console.js — live-refresh preserves sort (renderNodesTable reused)',
    strpos($js, 'renderNodesTable()') !== false && strpos($js, 'nodesCache') !== false);

// ── Summary ──
echo "\n";
echo "$passed passed, " . count($failed) . " failed\n";
echo 'Mesh node-signal — ' . $passed . ' / ' . $total . " tests passed\n";
if ($failed) { foreach ($failed as $f) echo "  - $f\n"; exit(1); }
