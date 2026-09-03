<?php
/**
 * Phase 150 (GH #135) — quick incident-note capture.
 *
 * Three things this covers, each driven through the real code rather than
 * asserting the pieces exist in isolation (this project has been bitten
 * before by "plumbing exists, last mile unwired"):
 *
 *  1. Source-level wiring: the 'n' hotkey actually reaches
 *     _openIncidentNoteModal via executeAction()'s dispatch switch, and
 *     both note modals now focus their textarea only after
 *     'shown.bs.modal' (the actual fix for the keyboard leak-through bug —
 *     see specs/phase-150-quick-incident-notes/plan.md §1 for why focusing
 *     any earlier would be stolen back by Bootstrap's own focus trap).
 *  2. A DB-driven round trip: the REAL writer (incident_add_note_internal())
 *     writes a note, and the REAL api/log.php endpoint (via a CLI probe,
 *     matching tests/_gh96_mileage_report_probe.php's discipline) surfaces
 *     it with code_type 'Incident Note' and the correct ticket_id.
 *  3. A negative control: an action row with action_type != 0 (the shape a
 *     system-written assignment/status-change entry takes) must NOT be
 *     merged as an incident note — proving the action_type = 0 filter is
 *     actually applied, not just documented.
 *
 * @requires-db
 * Usage: php tests/test_gh135_quick_incident_notes.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== Phase 150 (GH#135) — quick incident-note capture ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ══════════════════════════════════════════════════════════════════
// 1. Source-level wiring
// ══════════════════════════════════════════════════════════════════
echo "--- Wiring ---\n";

$kbNav = file_get_contents(__DIR__ . '/../assets/js/keyboard-nav.js');
t("keyboard-nav.js: ACTION_KEYS maps 'n' to 'note'",
    (bool) preg_match("/var ACTION_KEYS = \{[^}]*'n':\s*'note'[^}]*\}/s", $kbNav));

$appJs = file_get_contents(__DIR__ . '/../assets/js/app.js');

t("app.js: executeAction() has a 'note' case calling _openIncidentNoteModal",
    (bool) preg_match("/case 'note':\s*\n\s*_openIncidentNoteModal\(id\);/", $appJs));

t("app.js: _openIncidentNoteModal() is defined",
    strpos($appJs, 'function _openIncidentNoteModal(ticketId)') !== false);

t("app.js: _openIncidentNoteModal() posts action:'add_note' to api/incident-update.php",
    (bool) preg_match(
        "/_openIncidentNoteModal[\\s\\S]*?fetch\\('api\\/incident-update\\.php'[\\s\\S]{0,400}?action:\\s*'add_note'/",
        $appJs
    ));

// The actual bug fix: focus must happen on 'shown.bs.modal', not
// synchronously before modal.show() -- focusing earlier gets stolen back
// by Bootstrap's own focus trap, which is exactly why the pre-existing
// Responder note modal leaked keystrokes to the underlying widget's hotkeys.
t("app.js: _openResponderModal's note-mode focus is deferred to 'shown.bs.modal'",
    (bool) preg_match(
        "/mode === 'note'[\\s\\S]{0,900}?addEventListener\\('shown\\.bs\\.modal'[\\s\\S]{0,200}?respNoteText/",
        $appJs
    ));
t("app.js: _openIncidentNoteModal's focus is deferred to 'shown.bs.modal'",
    (bool) preg_match(
        "/_openIncidentNoteModal[\\s\\S]*?addEventListener\\('shown\\.bs\\.modal'[\\s\\S]{0,200}?incNoteText/",
        $appJs
    ));

$helpPhp = file_get_contents(__DIR__ . '/../help.php');
t("help.php documents the N hotkey for incidents",
    (bool) preg_match('/<kbd>N<\/kbd>.*?[Nn]ote/', $helpPhp));

// ══════════════════════════════════════════════════════════════════
// DB fixtures
// ══════════════════════════════════════════════════════════════════
$ticketId = 0;
$rogueActionId = 0;

$cleanup = function () use ($prefix, &$ticketId, &$rogueActionId) {
    try { db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$ticketId]); } catch (Throwable $e) {}
    try { if ($rogueActionId) db_query("DELETE FROM `{$prefix}action` WHERE id = ?", [$rogueActionId]); } catch (Throwable $e) {}
    try { if ($ticketId) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]); } catch (Throwable $e) {}
};
register_shutdown_function($cleanup);

try {
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `date`, `status`, `severity`)
         VALUES (0, 'GH135 quick-note fixture incident', 'gh135 fixture', NOW(), 2, 0)"
    );
    $ticketId = (int) db_insert_id();
    t('fixture incident created', $ticketId > 0);

    $marker = 'gh135-note-marker-' . bin2hex(random_bytes(4));

    // ══════════════════════════════════════════════════════════════
    // 2. Real writer -> real api/log.php endpoint
    // ══════════════════════════════════════════════════════════════
    echo "\n--- Real writer -> real api/log.php ---\n";

    $adminId = test_admin_user_id();
    $result = incident_add_note_internal($ticketId, $marker, $adminId);
    t('incident_add_note_internal() succeeded (no errors)', empty($result['errors']));

    $out = gh135_probe();
    t('api/log.php returned a decodable payload', is_array($out) && isset($out['entries']));

    $match = null;
    foreach (($out['entries'] ?? []) as $e) {
        if (($e['ticket_id'] ?? 0) === $ticketId && strpos((string) ($e['info'] ?? ''), $marker) !== false) {
            $match = $e;
            break;
        }
    }
    t('the note appears in api/log.php\'s merged feed', $match !== null);
    if ($match !== null) {
        t("merged row has code_type 'Incident Note'", ($match['code_type'] ?? '') === 'Incident Note');
        t('merged row carries the real ticket_id (not 0, unlike the responder/facility merges)',
            ($match['ticket_id'] ?? 0) === $ticketId);
        t('merged row attributes the note to the acting admin user',
            !empty($match['by']));
    }

    // ══════════════════════════════════════════════════════════════
    // 3. Negative control — action_type != 0 must NOT be merged
    // ══════════════════════════════════════════════════════════════
    echo "\n--- Negative control ---\n";

    $rogueMarker = 'gh135-rogue-marker-' . bin2hex(random_bytes(4));
    db_query(
        "INSERT INTO `{$prefix}action` (`ticket_id`, `date`, `description`, `user`, `action_type`)
         VALUES (?, NOW(), ?, ?, 30)",
        [$ticketId, $rogueMarker, $adminId]
    );
    $rogueActionId = (int) db_insert_id();

    $out2 = gh135_probe();
    $rogueMatch = null;
    foreach (($out2['entries'] ?? []) as $e) {
        if (strpos((string) ($e['info'] ?? ''), $rogueMarker) !== false) {
            $rogueMatch = $e;
            break;
        }
    }
    t('an action_type=30 row (a status-change shape) is NOT merged as an incident note',
        $rogueMatch === null);

} catch (Throwable $e) {
    t('no exception thrown', false);
    echo 'Exception: ' . $e->getMessage() . "\n";
}

function gh135_probe(): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_gh135_log_probe.php') . ' 30';
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
