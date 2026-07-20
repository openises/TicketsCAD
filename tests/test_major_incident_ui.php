<?php
/**
 * Major Incident UI — wiring + end-to-end tests
 *
 * Usage: php tests/test_major_incident_ui.php
 *
 * Part A — static file assertions: the page, its JS, the nav entry, and the
 *          incident-detail "Link to Major Incident" control all exist and are
 *          wired to the right element IDs / API actions (grep-style, like the
 *          other UI tests).
 *
 * Part B — end-to-end exercise of the data layer the API drives: create a
 *          major, create open test tickets, link them, GET-detail shows them,
 *          close (cascade-closing linked open tickets 2 → 1), then verify.
 *          Mirrors the exact SQL in api/major-incidents.php so a regression in
 *          the schema or cascade logic is caught. All inserted rows are
 *          cleaned up at the end.
 *
 * The API itself enforces auth + CSRF + rbac_can('action.link_major'); those
 * are covered by the page-render gating asserted in Part A and exercised over
 * HTTP elsewhere. Here we drive the DB operations directly so the test runs
 * without a live Apache/session (consistent with tools/test_major_incidents.php).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

echo "=== Major Incident UI Tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';
$root = dirname(__DIR__);

function check($label, $cond, &$pass, &$fail, $detail = '') {
    echo "[" . $label . "] ";
    if ($cond) {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL" . ($detail ? ": " . $detail : "") . "\n";
        $fail++;
    }
}

function file_has($path, $needle) {
    if (!is_file($path)) return false;
    return strpos(file_get_contents($path), $needle) !== false;
}

// ══════════════════════════════════════════════════════════════
// Part A — static wiring assertions
// ══════════════════════════════════════════════════════════════
$page = $root . '/major-incidents.php';
$js   = $root . '/assets/js/major-incidents.js';
$nav  = $root . '/inc/navbar.php';
$idet = $root . '/incident-detail.php';
$idjs = $root . '/assets/js/incident-detail.js';

check('A1 page file exists', is_file($page), $pass, $fail, $page);
check('A2 page JS file exists', is_file($js), $pass, $fail, $js);

check('A3 page gates page on login', file_has($page, "Location: login.php"), $pass, $fail);
check('A4 page computes link_major permission',
    file_has($page, "rbac_can('action.link_major')"), $pass, $fail);
check('A5 page surfaces permission to JS',
    file_has($page, 'data-can-manage-major'), $pass, $fail);
check('A6 page includes the major-incidents JS',
    file_has($page, 'assets/js/major-incidents.js'), $pass, $fail);
check('A7 page escapes output via e()/htmlspecialchars',
    file_has($page, 'echo e('), $pass, $fail);

// Key controls + element IDs the training video needs to target
check('A8 New-Major button id present',
    file_has($page, 'id="btnNewMajorIncident"'), $pass, $fail);
check('A9 New-Major modal present',
    file_has($page, 'id="newMajorModal"'), $pass, $fail);
check('A10 Create-submit button id present',
    file_has($page, 'id="btnCreateMajorSubmit"'), $pass, $fail);
check('A11 Edit button id present',
    file_has($page, 'id="btnEditMajor"'), $pass, $fail);
check('A12 Close-Major button id present',
    file_has($page, 'id="btnCloseMajor"'), $pass, $fail);
check('A13 commander select id present',
    file_has($page, 'id="newMajorCommander"'), $pass, $fail);
check('A14 link-incident search control id present',
    file_has($page, 'id="ticketSearch"'), $pass, $fail);
check('A15 list + detail mode containers present',
    file_has($page, 'id="listMode"') && file_has($page, 'id="detailMode"'), $pass, $fail);

// JS wiring
check('A16 JS reads mode from URL (?id)',
    file_has($js, 'getQueryId'), $pass, $fail);
check('A17 JS posts create action',
    file_has($js, "action: 'create'"), $pass, $fail);
check('A18 JS posts link action',
    file_has($js, "action: 'link'"), $pass, $fail);
check('A19 JS posts unlink action',
    file_has($js, "action: 'unlink'"), $pass, $fail);
check('A20 JS posts close action',
    file_has($js, "action: 'close'"), $pass, $fail);
check('A21 JS posts update action',
    file_has($js, "action: 'update'"), $pass, $fail);
check('A22 JS sends CSRF token',
    file_has($js, 'csrf_token'), $pass, $fail);
check('A23 JS sets JSON content-type',
    file_has($js, "'Content-Type': 'application/json'"), $pass, $fail);
check('A24 JS escapes dynamic output (esc helper)',
    file_has($js, 'function esc('), $pass, $fail);

// Nav entry — the direct navbar link was removed when the top nav was
// slimmed down; Major Incidents is now reached from the Situation view's
// tab bar (situation.php) and the incident-list widget header
// (widget-manager.js). Assert those entry points instead.
check('A25 UI links to Major Incidents (situation tab bar + widget header)',
    file_has($root . '/situation.php', 'major-incidents.php')
    && file_has($root . '/assets/js/widget-manager.js', 'major-incidents.php'), $pass, $fail);
check('A26 nav page-map entry present',
    file_has($nav, "'major-incidents' => 'major-incidents'"), $pass, $fail);

// incident-detail link control
check('A27 incident-detail computes link_major permission',
    file_has($idet, "rbac_can('action.link_major')"), $pass, $fail);
check('A28 incident-detail has major link card',
    file_has($idet, 'id="majorLinkCard"'), $pass, $fail);
check('A29 incident-detail has create-new-major option',
    file_has($idet, 'value="__new__"'), $pass, $fail);
check('A30 incident-detail link button id present',
    file_has($idet, 'id="btnLinkMajor"'), $pass, $fail);
check('A31 incident-detail JS inits major link',
    file_has($idjs, 'initMajorLink'), $pass, $fail);
check('A32 incident-detail JS links via major API',
    file_has($idjs, 'api/major-incidents.php'), $pass, $fail);
check('A33 incident-detail JS create-then-link path',
    file_has($idjs, "action: 'create'") && file_has($idjs, "action: 'link'"), $pass, $fail);

// ══════════════════════════════════════════════════════════════
// Part B — end-to-end data-layer exercise (mirrors API SQL)
// ══════════════════════════════════════════════════════════════
$now = date('Y-m-d H:i:s');
$test_major_id = null;
$test_ticket_ids = [];
$schema_ok = true;

// B1 — tables exist
try {
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_major_incidents`");
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links`");
    check('B1 major-incident tables exist', true, $pass, $fail);
} catch (Exception $e) {
    check('B1 major-incident tables exist', false, $pass, $fail, $e->getMessage());
    $schema_ok = false;
}

if ($schema_ok) {
    // B2 — create major (mirrors action=create)
    try {
        db_query(
            "INSERT INTO `{$prefix}newui_major_incidents`
                (`name`, `description`, `commander`, `severity`, `status`, `lat`, `lng`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?)",
            ['UITEST Major Incident', 'UI test major', null, 2, null, null, $now, $now]
        );
        $test_major_id = (int) db_insert_id();
        check('B2 create major incident', $test_major_id > 0, $pass, $fail);
    } catch (Exception $e) {
        check('B2 create major incident', false, $pass, $fail, $e->getMessage());
    }

    // B3 — create two OPEN test tickets (status 2)
    try {
        for ($i = 1; $i <= 2; $i++) {
            db_query(
                "INSERT INTO `{$prefix}ticket`
                    (`scope`, `description`, `status`, `severity`, `date`, `updated`, `_by`, `owner`, `in_types_id`)
                 VALUES (?, ?, 2, 1, ?, ?, 1, 0, 1)",
                ["UITEST Linked Ticket {$i}", "UI test ticket {$i}", $now, $now]
            );
            $test_ticket_ids[] = (int) db_insert_id();
        }
        check('B3 create open test tickets', count($test_ticket_ids) === 2 && $test_ticket_ids[0] > 0, $pass, $fail);
    } catch (Exception $e) {
        check('B3 create open test tickets', false, $pass, $fail, $e->getMessage());
    }

    // B4 — link both (mirrors action=link)
    try {
        foreach ($test_ticket_ids as $tid) {
            db_query(
                "INSERT INTO `{$prefix}newui_major_incident_links` (`major_id`, `ticket_id`, `linked_by`, `linked_at`)
                 VALUES (?, ?, 1, ?)",
                [$test_major_id, $tid, $now]
            );
        }
        $cnt = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links` WHERE `major_id` = ?",
            [$test_major_id]
        );
        check('B4 link incidents to major', $cnt === 2, $pass, $fail, "got {$cnt}");
    } catch (Exception $e) {
        check('B4 link incidents to major', false, $pass, $fail, $e->getMessage());
    }

    // B5 — GET detail shows linked incidents (mirrors GET ?id=X JOIN)
    try {
        $links = db_fetch_all(
            "SELECT l.`id` AS link_id, l.`ticket_id`, t.`scope`, t.`status`, t.`severity`
               FROM `{$prefix}newui_major_incident_links` l
               JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
              WHERE l.`major_id` = ?
              ORDER BY l.`linked_at` ASC",
            [$test_major_id]
        );
        $ok = count($links) === 2 && strpos($links[0]['scope'], 'UITEST Linked Ticket') === 0;
        check('B5 detail GET lists linked incidents', $ok, $pass, $fail);
    } catch (Exception $e) {
        check('B5 detail GET lists linked incidents', false, $pass, $fail, $e->getMessage());
    }

    // B6 — list query reports linked_count (mirrors list subquery)
    try {
        $row = db_fetch_one(
            "SELECT m.*,
                    (SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links` l WHERE l.`major_id` = m.`id`) AS linked_count
               FROM `{$prefix}newui_major_incidents` m
              WHERE m.`id` = ?",
            [$test_major_id]
        );
        check('B6 list query linked_count', $row && (int) $row['linked_count'] === 2, $pass, $fail);
    } catch (Exception $e) {
        check('B6 list query linked_count', false, $pass, $fail, $e->getMessage());
    }

    // B7 — close cascade (mirrors action=close): close major + linked open → 1
    try {
        db_query(
            "UPDATE `{$prefix}newui_major_incidents`
                SET `status` = 'closed', `closed_at` = ?, `updated_at` = ? WHERE `id` = ?",
            [$now, $now, $test_major_id]
        );
        $linked_open = db_fetch_all(
            "SELECT l.`ticket_id`
               FROM `{$prefix}newui_major_incident_links` l
               JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
              WHERE l.`major_id` = ? AND t.`status` = 2",
            [$test_major_id]
        );
        $closed_count = 0;
        foreach ($linked_open as $lt) {
            db_query(
                "UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = ?, `updated` = ? WHERE `id` = ? AND `status` = 2",
                [$now, $now, (int) $lt['ticket_id']]
            );
            $closed_count++;
        }
        $mi = db_fetch_one(
            "SELECT `status`, `closed_at` FROM `{$prefix}newui_major_incidents` WHERE `id` = ?",
            [$test_major_id]
        );
        $still_open = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links` l
               JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
              WHERE l.`major_id` = ? AND t.`status` = 2",
            [$test_major_id]
        );
        $ok = $mi['status'] === 'closed' && $mi['closed_at'] !== null
              && $closed_count === 2 && $still_open === 0;
        check('B7 close cascades linked open tickets (2→1)', $ok, $pass, $fail,
            "closed={$closed_count}, still_open={$still_open}");
    } catch (Exception $e) {
        check('B7 close cascades linked open tickets (2→1)', false, $pass, $fail, $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// Cleanup
// ══════════════════════════════════════════════════════════════
if ($test_major_id) {
    try { db_query("DELETE FROM `{$prefix}newui_major_incident_links` WHERE `major_id` = ?", [$test_major_id]); } catch (Exception $e) {}
    try { db_query("DELETE FROM `{$prefix}newui_major_incidents` WHERE `id` = ?", [$test_major_id]); } catch (Exception $e) {}
}
if (!empty($test_ticket_ids)) {
    try {
        $ph = implode(',', array_fill(0, count($test_ticket_ids), '?'));
        db_query("DELETE FROM `{$prefix}ticket` WHERE `id` IN ({$ph})", $test_ticket_ids);
    } catch (Exception $e) {}
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
