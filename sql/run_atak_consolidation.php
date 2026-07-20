<?php
/**
 * Phase 91 consolidation — fold the parallel atak_* infrastructure
 * into the existing Phase 35 mesh-bridge data model.
 *
 * BEFORE: my Slice 1 created atak_channels + atak_push_log as a
 * parallel stack alongside mesh_channels + mesh_packet_log. The two
 * existing mesh bridges (meshbridge-01/02 on your-host/your-host) couldn't
 * deliver to it because they POST to api/mesh.php?action=ingest.
 *
 * AFTER: ATAK is a *policy* layered onto mesh_channels (per-channel
 * sensitive_flag + push toggles + marker action + position rate
 * limits). Inbound CoT routing hooks into the existing ingest path
 * (api/mesh.php), so the two live bridges deliver ATAK automatically.
 *
 * Changes:
 *   1. ALTER mesh_channels — add atak_enabled + ATAK policy columns
 *   2. DROP atak_channels (parallel, no production data)
 *   3. DROP atak_push_log (parallel, no production data — mesh_packet_log
 *      is now the source of truth for inbound + outbound CoT audit)
 *   4. atak_unbound_uids stays — no equivalent in mesh schema
 *
 * Idempotent. Safe to re-run.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 91 consolidation — mesh_channels gets ATAK policy columns;\n";
echo "atak_channels + atak_push_log dropped (replaced by mesh_* tables).\n";
echo "==============================================================\n";

// ── 1. Confirm mesh_channels exists (it must — Phase 35) ────────
$hasMeshChannels = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'mesh_channels']
);
if (!$hasMeshChannels) {
    echo "  [err] mesh_channels does not exist — Phase 35 mesh-bridge\n";
    echo "        infrastructure isn't installed. Run sql/run_phase35_mesh_bridge.php\n";
    echo "        and sql/run_phase39b_mesh_channels.php first.\n";
    exit(1);
}

// ── 2. Add ATAK policy columns to mesh_channels ────────────────
$columnsToAdd = [
    'atak_enabled'            => "TINYINT(1) NOT NULL DEFAULT 0
                                  COMMENT 'When 1, ATAK CoT routing applies to packets on this channel'",
    'atak_sensitive_flag'     => "TINYINT(1) NOT NULL DEFAULT 1
                                  COMMENT 'Decision 1: strict default — PII stripped from outbound CoT'",
    'atak_push_incidents'     => "TINYINT(1) NOT NULL DEFAULT 1",
    'atak_push_units'         => "TINYINT(1) NOT NULL DEFAULT 1",
    'atak_push_facilities'    => "TINYINT(1) NOT NULL DEFAULT 0
                                  COMMENT 'High-volume; opt-in per channel'",
    'atak_push_chat'          => "TINYINT(1) NOT NULL DEFAULT 1",
    'atak_marker_action'      => "ENUM('new_incident','note_nearest') NOT NULL DEFAULT 'new_incident'
                                  COMMENT 'Decision 3: per-channel default; marker subtype overrides at decode time'",
    'atak_position_min_secs'  => "INT NOT NULL DEFAULT 60
                                  COMMENT 'Rate-limit per-uid position emissions (seconds)'",
    'atak_position_min_m'     => "INT NOT NULL DEFAULT 25
                                  COMMENT 'Skip emission if a uid has not moved this far'",
];

foreach ($columnsToAdd as $col => $def) {
    try {
        $exists = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . 'mesh_channels', $col]
        );
        if (!$exists) {
            db_query("ALTER TABLE `{$prefix}mesh_channels` ADD COLUMN `{$col}` {$def}");
            echo "  [ok] mesh_channels.{$col} added\n";
        } else {
            echo "  [skip] mesh_channels.{$col} already present\n";
        }
    } catch (Exception $e) {
        echo "  [warn] add {$col}: " . $e->getMessage() . "\n";
    }
}

// ── 3. Drop the parallel atak_channels table ───────────────────
try {
    $exists = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'atak_channels']
    );
    if ($exists) {
        $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}atak_channels`");
        if ($count > 0) {
            // Sanity preserve — dump rows to error log so an operator
            // who configured policy here can see what they were.
            $rows = db_fetch_all("SELECT * FROM `{$prefix}atak_channels`");
            error_log("[atak_consolidation] dropping atak_channels with {$count} row(s); contents: " . json_encode($rows));
            echo "  [warn] atak_channels had {$count} row(s) — dumped to error_log before drop\n";
        }
        db_query("DROP TABLE `{$prefix}atak_channels`");
        echo "  [ok] dropped atak_channels (use mesh_channels with atak_enabled=1 instead)\n";
    } else {
        echo "  [skip] atak_channels not present\n";
    }
} catch (Exception $e) {
    echo "  [warn] drop atak_channels: " . $e->getMessage() . "\n";
}

// ── 4. Drop the parallel atak_push_log table ───────────────────
try {
    $exists = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'atak_push_log']
    );
    if ($exists) {
        $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}atak_push_log`");
        if ($count > 0) {
            error_log("[atak_consolidation] dropping atak_push_log with {$count} row(s) (smoke-test data only — mesh_packet_log is now the source of truth)");
            echo "  [warn] atak_push_log had {$count} row(s) — smoke data, replaced by mesh_packet_log\n";
        }
        db_query("DROP TABLE `{$prefix}atak_push_log`");
        echo "  [ok] dropped atak_push_log (use mesh_packet_log filtered to port_kind IN ('ATAK_PLUGIN','TEXT_MESSAGE_APP') instead)\n";
    } else {
        echo "  [skip] atak_push_log not present\n";
    }
} catch (Exception $e) {
    echo "  [warn] drop atak_push_log: " . $e->getMessage() . "\n";
}

// ── 5. atak_unbound_uids stays — no equivalent in mesh schema ──
echo "  [keep] atak_unbound_uids — operator-review surface for orphaned uids, no mesh-side equivalent\n";

echo "\nDone.\n";
echo "Next:\n";
echo "  - Restart Apache so api/mesh.php picks up the routing hook (if not yet running fresh)\n";
echo "  - Stop + uninstall ticketscad-atak-bridge.service on training (separate cleanup step)\n";
echo "  - In the admin UI, edit a mesh_channels row and toggle atak_enabled=1 to begin routing\n";
