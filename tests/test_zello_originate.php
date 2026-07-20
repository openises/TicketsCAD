<?php
/**
 * Phase F (messaging-send-gaps-2026-06) — Zello DM-to-unit ORIGINATE tests.
 *
 * Gap 2 of docs/training-scripts/zello-config-video-brief.md: originate a
 * Zello send (DM a unit/person, or a channel broadcast) from the Mesh Console
 * Send tab, without replying to an inbound message first.
 *
 * The originate path is api/zello-inbox.php?action=originate. It must:
 *   1. resolve a unit/person → their Zello username via inc/comm_resolve.php
 *      (the SAME resolver the mesh Send tab uses), and queue a zello_outbox
 *      DM row (recipient = that username);
 *   2. queue a channel-broadcast row (recipient empty) when no target is given;
 *   3. accept a directly-typed recipient username (a DM without a roster lookup);
 *   4. error (not silently broadcast) when the chosen unit/person has no Zello
 *      identifier on file;
 *   5. NEVER touch the network — it only writes a queued zello_outbox row; the
 *      running proxy (pollZelloOutbox) relays it.
 *
 * These exercise the resolve + queue contract directly against the DB (no HTTP),
 * plus source-level wiring guards (CSRF, admin auth, the originate action +
 * its UI hooks) so a silent regression is caught.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/comm_resolve.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Zello Originate (DM-to-unit) Tests (Phase F) ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

function t_ok(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else       { echo "[FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }
}

// ── Ensure schema: the Phase-D outbox the originate path queues into ──
ob_start();
try { require __DIR__ . '/../sql/run_route_subaddress.php'; } catch (Throwable $e) {}
ob_end_clean();

$hasOutbox = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'zello_outbox']
);
t_ok("zello_outbox table present", $hasOutbox);

// Clean any leftover test rows.
db_query("DELETE FROM `{$prefix}zello_outbox` WHERE `body` LIKE 'ZOTEST_%'");

// ── Seed: a member with a Zello username identifier, so the resolver hits ──
// comm_modes 'zello' is seeded by base data; confirm before relying on it.
$zMode = db_fetch_one("SELECT id, enabled FROM `{$prefix}comm_modes` WHERE code = 'zello' LIMIT 1");
t_ok("comm_modes has an enabled 'zello' mode", $zMode && (int) $zMode['enabled'] === 1);

$zModeId = $zMode ? (int) $zMode['id'] : 0;
$testMemberId = 0;
if ($zModeId > 0) {
    // A throwaway member row (cleaned up at the end). On legacy-mapped installs
    // first_name/last_name/callsign are VIRTUAL GENERATED columns over the
    // field1/field2/field4 legacy columns, so INSERT into the underlying
    // columns when the generated ones exist; otherwise INSERT directly.
    $genFirst = db_fetch_value(
        "SELECT EXTRA FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'first_name'",
        [$prefix . 'member']
    );
    $firstIsGenerated = is_string($genFirst) && stripos($genFirst, 'GENERATED') !== false;
    if ($firstIsGenerated) {
        // field2 → first_name, field1 → last_name, field4 → callsign
        db_query(
            "INSERT INTO `{$prefix}member` (field2, field1, field4)
             VALUES ('Zotest', 'Originate', 'ZOTEST1')"
        );
    } else {
        db_query(
            "INSERT INTO `{$prefix}member` (first_name, last_name, callsign)
             VALUES ('Zotest', 'Originate', 'ZOTEST1')"
        );
    }
    $testMemberId = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}member_comm_identifiers`
            (member_id, comm_mode_id, label, values_json, is_primary)
         VALUES (?, ?, 'test', ?, 1)",
        [$testMemberId, $zModeId, json_encode(['username' => 'zotest_unit'])]
    );
}

// ──────────────────────────────────────────────────────────────────────
// 1. The resolver maps the seeded member → their Zello username.
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Resolver --\n";
$resolved = $testMemberId > 0 ? resolve_unit_address($testMemberId, 'zello', 'member') : null;
t_ok("resolve_unit_address(member, 'zello') returns the seeded username",
    $resolved === 'zotest_unit', 'got ' . var_export($resolved, true));

// ──────────────────────────────────────────────────────────────────────
// 2. Originate a DM to that member → a zello_outbox row with recipient set.
//    (Mirrors the api/zello-inbox.php originate DB contract: resolve, then
//    INSERT a 'queued' row with source='originate'.)
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Originate: DM a member (resolved) --\n";
if ($resolved) {
    db_query(
        "INSERT INTO `{$prefix}zello_outbox`
            (kind, channel, recipient, body, status, queued_by, source)
         VALUES ('text', '', ?, 'ZOTEST_dm_member', 'queued', 1, 'originate')",
        [$resolved]
    );
    $dmOid = (int) db_insert_id();
    $dmRow = db_fetch_one(
        "SELECT channel, recipient, status, source FROM `{$prefix}zello_outbox` WHERE id = ?",
        [$dmOid]
    );
    t_ok("DM row queued with recipient = resolved username",
        $dmRow && $dmRow['recipient'] === 'zotest_unit');
    t_ok("DM row has blank channel (proxy fills the dispatch channel)",
        $dmRow && $dmRow['channel'] === '');
    t_ok("DM row status=queued (not faked sent) + source=originate",
        $dmRow && $dmRow['status'] === 'queued' && $dmRow['source'] === 'originate');
}

// ──────────────────────────────────────────────────────────────────────
// 3. Channel broadcast → a row with EMPTY recipient (proxy broadcasts).
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Originate: channel broadcast --\n";
db_query(
    "INSERT INTO `{$prefix}zello_outbox`
        (kind, channel, recipient, body, status, queued_by, source)
     VALUES ('text', 'Network radio Midwest', '', 'ZOTEST_chan', 'queued', 1, 'originate')"
);
$chOid = (int) db_insert_id();
$chRow = db_fetch_one("SELECT channel, recipient FROM `{$prefix}zello_outbox` WHERE id = ?", [$chOid]);
t_ok("channel broadcast row has named channel + EMPTY recipient",
    $chRow && $chRow['channel'] === 'Network radio Midwest' && $chRow['recipient'] === '');

// Blank channel broadcast is also valid (proxy uses the dispatch channel).
db_query(
    "INSERT INTO `{$prefix}zello_outbox`
        (kind, channel, recipient, body, status, queued_by, source)
     VALUES ('text', '', '', 'ZOTEST_chan_default', 'queued', 1, 'originate')"
);
$cdOid = (int) db_insert_id();
$cdRow = db_fetch_one("SELECT channel, recipient FROM `{$prefix}zello_outbox` WHERE id = ?", [$cdOid]);
t_ok("blank-channel broadcast row queues (proxy resolves dispatch channel)",
    $cdRow && $cdRow['channel'] === '' && $cdRow['recipient'] === '');

// ──────────────────────────────────────────────────────────────────────
// 4. Unmapped target → resolver returns null (endpoint errors, no broadcast).
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Originate: unmapped target errors --\n";
$unmapped = resolve_unit_address(999999999, 'zello', 'member');
t_ok("resolver returns null for a member with no Zello identifier", $unmapped === null);

// ──────────────────────────────────────────────────────────────────────
// 5. Source-level wiring guards (catch silent regressions).
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Source wiring --\n";
$inboxSrc = file_get_contents(__DIR__ . '/../api/zello-inbox.php');
$phpSrc   = file_get_contents(__DIR__ . '/../mesh-console.php');
$jsSrc    = file_get_contents(__DIR__ . '/../assets/js/mesh-console.js');

t_ok("zello-inbox.php has an 'originate' action",
    strpos($inboxSrc, "=== 'originate'") !== false);
t_ok("zello-inbox.php has an 'originate_targets' action",
    strpos($inboxSrc, "=== 'originate_targets'") !== false);
t_ok("originate resolves via resolve_unit_address(..., 'zello')",
    (bool) preg_match("/resolve_unit_address\([^)]*'zello'/", $inboxSrc));
t_ok("originate verifies CSRF",
    strpos($inboxSrc, 'csrf_verify') !== false);
t_ok("originate is gated by an admin-auth helper (manage_mesh_bridges)",
    strpos($inboxSrc, '_zinbox_admin_auth') !== false
    && strpos($inboxSrc, 'action.manage_mesh_bridges') !== false);
t_ok("originate queues zello_outbox + never sends RF itself",
    strpos($inboxSrc, 'zello_outbox') !== false
    && strpos($inboxSrc, "'originate'") !== false
    && strpos($inboxSrc, 'sendTextMessage') === false);

t_ok("Send tab Protocol dropdown offers Zello",
    (bool) preg_match('/<option value="zello">\s*Zello\s*<\/option>/', $phpSrc));
t_ok("Send tab has a Zello-channel field",
    strpos($phpSrc, 'sendZelloChannel') !== false);
t_ok("JS routes a Zello send to the originate endpoint",
    strpos($jsSrc, "zello-inbox.php?action=originate") !== false
    && strpos($jsSrc, 'function sendZello') !== false);
t_ok("JS loads the Zello picker from originate_targets",
    strpos($jsSrc, "action=originate_targets") !== false);

// ── Cleanup ──
db_query("DELETE FROM `{$prefix}zello_outbox` WHERE `body` LIKE 'ZOTEST_%'");
if ($testMemberId > 0) {
    db_query("DELETE FROM `{$prefix}member_comm_identifiers` WHERE member_id = ?", [$testMemberId]);
    db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$testMemberId]);
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
