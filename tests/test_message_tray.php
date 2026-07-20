<?php
/**
 * Phase 111 Slice B — Dispatcher Message Tray.
 *
 * Static guards over the API/page/JS/sidebar wiring + an integration check of
 * the attach data-path (the exact incident_add_note_internal meta write the
 * tray relies on, and the source_message_id "logged ticket" lookup).
 *
 * Usage: php tests/test_message_tray.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Phase 111 Slice B — Message Tray ===\n\n";

// ── API wiring ──────────────────────────────────────────────────────────────
$api = rd($base . '/api/message-tray.php');
t('API read-gated on screen.message_tray',
    $api !== false && strpos($api, "rbac_can('screen.message_tray')") !== false);
t('API mutation-gated on action.assign_message + CSRF',
    $api !== false && strpos($api, "rbac_can('action.assign_message')") !== false &&
    strpos($api, 'csrf_verify') !== false);
$actions = ['list', 'incidents', 'members', 'log_active', 'assign', 'copy', 'sub_incident', 'set_sender', 'reply', 'compose'];
$missing = [];
foreach ($actions as $a) { if ($api === false || strpos($api, "'" . $a . "'") === false) $missing[] = $a; }
t('API implements all actions (' . implode(',', $actions) . ')', empty($missing));
t('API reuses broker_send / note writer / create / reverse-resolve / active-event',
    $api !== false &&
    strpos($api, 'broker_send(') !== false &&
    strpos($api, 'incident_add_note_internal(') !== false &&
    strpos($api, 'incident_create_internal(') !== false &&
    strpos($api, 'comm_resolve_member_by_address(') !== false &&
    strpos($api, 'mi_active_event_ticket_id(') !== false);
t('API reply/compose need only the screen (CSRF), not assign',
    $api !== false && strpos($api, '_mt_require_csrf($input)') !== false);

// ── Page ────────────────────────────────────────────────────────────────────
$page = rd($base . '/message-tray.php');
t('page RBAC-gated on screen.message_tray with a friendly 403',
    $page !== false && strpos($page, "rbac_can('screen.message_tray')") !== false &&
    strpos($page, 'Permission required') !== false);
t('page has list + compose + assign/sender modals',
    $page !== false &&
    strpos($page, 'id="mtList"') !== false &&
    strpos($page, 'id="mtComposeBody"') !== false &&
    strpos($page, 'id="mtAssignModal"') !== false &&
    strpos($page, 'id="mtSenderModal"') !== false &&
    strpos($page, 'assets/js/message-tray.js') !== false);

// ── JS controller ───────────────────────────────────────────────────────────
$js = rd($base . '/assets/js/message-tray.js');
t('controller is DOM-safe (textContent, no innerHTML)',
    $js !== false && strpos($js, '.textContent') !== false && strpos($js, '.innerHTML') === false);
t('controller is keyboard-first (Enter to send, Shift+Enter newline)',
    $js !== false && strpos($js, "e.key === 'Enter' && !e.shiftKey") !== false);
t('controller pulls CSRF from #csrfToken',
    $js !== false && strpos($js, "getElementById('csrfToken')") !== false);

// ── Sidebar + RBAC migration ─────────────────────────────────────────────────
$side = rd($base . '/inc/config-sidebar.php');
t('sidebar links message-tray.php gated on screen.message_tray',
    $side !== false &&
    (bool) preg_match("/rbac_can\('screen\.message_tray'\).*?_cfg_link\('message-tray',\s*'message-tray\.php'/s", $side));
$mig = rd($base . '/sql/run_message_tray_perm.php');
t('RBAC migration seeds screen.message_tray + action.assign_message (roles 1,2,3)',
    $mig !== false &&
    strpos($mig, 'screen.message_tray') !== false &&
    strpos($mig, 'action.assign_message') !== false &&
    strpos($mig, '[1, 2, 3]') !== false);

// live: permissions present after migration
$permCount = 0;
try {
    $permCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE code IN ('screen.message_tray','action.assign_message')");
} catch (Throwable $e) {}
t('both permissions exist in the DB (migration applied)', $permCount === 2);

// ── Integration: the attach data-path the tray depends on ────────────────────
$hasSrcCols = (bool) db_fetch_one(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='source_message_id'",
    [$prefix . 'action']);
if (!$hasSrcCols) {
    t('SKIP integration — action.source_message_id absent (pre-Slice-A DB)', true);
} else {
    // Seed an inbound broker message.
    db_query("INSERT INTO `{$prefix}messages` (channel, direction, sender, recipient, body, status, created_at)
              VALUES ('dmr','inbound','TESTHANDLE','all','MT test body','pending',NOW())");
    $msgId = (int) db_insert_id();
    $ticketId = 999113; $authorMember = 990113;

    // Attach exactly as the tray does — note writer with Slice A meta.
    $res = incident_add_note_internal($ticketId, '[DMR: TESTHANDLE] MT test body', 0, [
        'source_channel'    => 'dmr',
        'source_message_id' => $msgId,
        'author_member_id'  => $authorMember,
    ]);
    t('note writer returns an action id', !empty($res['id']));

    // The row round-trips the attribution the per-person 214 + tray rely on.
    $row = db_fetch_one(
        "SELECT ticket_id, source_channel, source_message_id, author_member_id
           FROM `{$prefix}action` WHERE source_message_id = ? ORDER BY id DESC LIMIT 1", [$msgId]);
    t('attach wrote ticket_id + source_channel + author_member_id',
        $row && (int) $row['ticket_id'] === $ticketId &&
        $row['source_channel'] === 'dmr' && (int) $row['author_member_id'] === $authorMember);
    t('"logged ticket" lookup by source_message_id resolves the incident',
        $row && (int) $row['source_message_id'] === $msgId);

    // Cleanup.
    db_query("DELETE FROM `{$prefix}action` WHERE source_message_id = ?", [$msgId]);
    db_query("DELETE FROM `{$prefix}messages` WHERE id = ?", [$msgId]);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
