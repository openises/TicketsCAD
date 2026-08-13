<?php
/**
 * Phase 138 — api/feed.php Security-Label patch (tasks.md F3).
 *
 * Scope reminder (plan.md §8): api/feed.php is a TRUSTED, keyed consumer
 * (feed_api_key), not "any stranger on the internet." The ONLY behavior
 * change under test here is Security-Label awareness:
 *   - a routing_allow_broadcast=0 incident drops out of the feed entirely
 *   - a label's eoc_show_map_marker still caps precision (coarser only,
 *     never finer) even though this feed's own ceiling is 'exact'
 *   - the JSON opened/updated timestamp bug is fixed (regression, parsed
 *     not pattern-matched)
 *   - a type marked public_board_visibility='presence_only' (the PUBLIC
 *     BOARD's own mechanism) comes through with FULL, non-stubbed detail
 *     here — proving pb_build_public_record()'s $applyTypeVisibility=false
 *     argument actually took effect (security review finding #2) and the
 *     public-board-only mechanism did not leak into this trusted feed
 *
 * Drives the REAL api/feed.php over real HTTP against a local self-hosted
 * server (tests/_pb_test_server.php) with a test-owned feed_api_key —
 * never a hand-simulated filter.
 *
 * @requires-db
 * Usage: php tests/test_public_board_feed_patch.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_pb_test_server.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — api/feed.php Security-Label patch ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Precondition: this phase's schema must exist (public_board_visibility
//    is what proves $applyTypeVisibility=false — no schema, no test) ──────
try {
    $hasCol = db_fetch_value(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'public_board_visibility'",
        [$prefix . 'in_types']
    );
} catch (Throwable $e) { $hasCol = false; }
if (!$hasCol) {
    echo "SKIP: Phase 138 schema not present (run sql/run_phase138_public_board.php first)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$srv = pb_test_start_server();
if ($srv === null) {
    echo "SKIP: could not start a local PHP server for this test (proc_open/curl unavailable)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$createdTypeIds  = [];
$createdTicketIds = [];
$createdLabelIds = [];
$origFeedKey = null;

function _pbf_make_type(array $overrides = []): int {
    global $prefix, $createdTypeIds;
    $fields = array_merge([
        'type'        => 'zz138feed-' . uniqid(),
        'description' => 'Phase 138 feed-patch test type',
    ], $overrides);
    $cols = array_keys($fields);
    db_query(
        "INSERT INTO `{$prefix}in_types` (`" . implode('`,`', $cols) . "`) VALUES (" .
        implode(',', array_fill(0, count($cols), '?')) . ")",
        array_values($fields)
    );
    $id = (int) db_insert_id();
    $createdTypeIds[] = $id;
    return $id;
}

function _pbf_make_ticket(int $typeId, array $overrides = []): int {
    global $prefix, $createdTicketIds;
    $fields = array_merge([
        'in_types_id' => $typeId,
        'contact'     => '',
        'street'      => '456 Feed Test Ave',
        'city'        => 'Feedville',
        'state'       => 'MN',
        'lat'         => 44.98765,
        'lng'         => -93.12345,
        'date'        => date('Y-m-d H:i:s', time() - 3600),
        'scope'       => 'Phase 138 feed test',
        'description' => 'Phase 138 feed test',
        'status'      => 2,
        'severity'    => 1,
        'updated'     => date('Y-m-d H:i:s', time() - 60),
    ], $overrides);
    $cols = array_keys($fields);
    db_query(
        "INSERT INTO `{$prefix}ticket` (`" . implode('`,`', $cols) . "`) VALUES (" .
        implode(',', array_fill(0, count($cols), '?')) . ")",
        array_values($fields)
    );
    $id = (int) db_insert_id();
    $createdTicketIds[] = $id;
    return $id;
}

try {
    $uniq = 'zz138feed-' . substr(md5((string) mt_rand()), 0, 8);
    $testKey = 'zz138-feed-key-' . $uniq;

    $origFeedKey = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='feed_api_key'");
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('feed_api_key', ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$testKey]
    );

    // ── Label A: broadcast-blocked (e.g. Restricted/Confidential) ────────
    db_query(
        "INSERT INTO `{$prefix}security_labels`
            (`code`,`name`,`sort_order`,`is_default`,`eoc_show_address`,`eoc_show_map_marker`,
             `routing_allow_broadcast`,`routing_allow_direct`,`audit_required_reason`)
         VALUES (?,?,999,0,1,'full',0,0,0)",
        [$uniq . '-blocked', 'ZZ138 Feed Blocked']
    );
    $labelBlocked = (int) db_insert_id(); $createdLabelIds[] = $labelBlocked;

    // ── Label B: dim marker — coordinates must round to 'city' precision
    //    even though feed.php's own ceiling is 'exact' (never loosened). ──
    db_query(
        "INSERT INTO `{$prefix}security_labels`
            (`code`,`name`,`sort_order`,`is_default`,`eoc_show_address`,`eoc_show_map_marker`,
             `routing_allow_broadcast`,`routing_allow_direct`,`audit_required_reason`)
         VALUES (?,?,999,0,1,'dim',1,1,0)",
        [$uniq . '-dim', 'ZZ138 Feed Dim']
    );
    $labelDim = (int) db_insert_id(); $createdLabelIds[] = $labelDim;

    // ── Label C: eoc_show_scope=0, eoc_show_address=1 — narrative hidden,
    //    address still visible. Correctness review finding: eoc_show_scope
    //    is a SEPARATE flag from eoc_show_address; this combination used to
    //    reach the feed's scope/description fields completely unredacted.
    db_query(
        "INSERT INTO `{$prefix}security_labels`
            (`code`,`name`,`sort_order`,`is_default`,`eoc_show_scope`,`eoc_show_address`,`eoc_show_map_marker`,
             `eoc_placeholder_text`,`routing_allow_broadcast`,`routing_allow_direct`,`audit_required_reason`)
         VALUES (?,?,999,0,0,1,'full',?,1,1,0)",
        [$uniq . '-hidescope', 'ZZ138 Feed Hide Scope', 'ZZ138 NARRATIVE WITHHELD']
    );
    $labelHideScope = (int) db_insert_id(); $createdLabelIds[] = $labelHideScope;

    $plainType    = _pbf_make_type();
    $presenceType = _pbf_make_type(['public_board_visibility' => 'presence_only']);

    $tBlocked  = _pbf_make_ticket($plainType, ['security_label_override_id' => $labelBlocked]);
    $tDim      = _pbf_make_ticket($plainType, ['security_label_override_id' => $labelDim]);
    $tHideScope = _pbf_make_ticket($plainType, ['security_label_override_id' => $labelHideScope]);
    $tPresence = _pbf_make_ticket($presenceType); // default label -> routing_allow_broadcast=1
    $tPlain    = _pbf_make_ticket($plainType);

    $base = 'http://127.0.0.1:' . $srv['port'] . '/api/feed.php';
    $r = pb_test_http_get($base . '?format=json&key=' . urlencode($testKey));
    t('feed responds 200', $r !== null && $r['status'] === 200);
    $j = $r !== null ? json_decode($r['body'], true) : null;
    t('feed returns valid JSON with an incidents array', $j !== null && isset($j['incidents']) && is_array($j['incidents']));

    $byId = [];
    foreach (($j['incidents'] ?? []) as $inc) { $byId[(int) $inc['id']] = $inc; }

    // ── Broadcast-block drop ─────────────────────────────────────────────
    t('routing_allow_broadcast=0 incident is ABSENT from the feed', !isset($byId[$tBlocked]));

    // ── Label-driven coordinate rounding despite feed's exact ceiling ────
    t('dim-labeled incident is present', isset($byId[$tDim]));
    if (isset($byId[$tDim])) {
        $lat = $byId[$tDim]['lat'] ?? null;
        $lng = $byId[$tDim]['lng'] ?? null;
        t('dim label rounds lat to city precision (2dp) despite feed\'s exact ceiling',
            $lat !== null && abs($lat - round(44.98765, 2)) < 0.0000001);
        t('dim label rounds lng to city precision (2dp) despite feed\'s exact ceiling',
            $lng !== null && abs($lng - round(-93.12345, 2)) < 0.0000001);
    }

    // ── eoc_show_scope=0 masks scope/description even though eoc_show_
    //    address=1 keeps street/city/lat/lng untouched (two INDEPENDENT
    //    flags — correctness review finding). ──
    t('eoc_show_scope=0 incident is present (still passes the broadcast gate)', isset($byId[$tHideScope]));
    if (isset($byId[$tHideScope])) {
        $inc = $byId[$tHideScope];
        t('eoc_show_scope=0: scope is replaced with the label placeholder, not the raw narrative',
            ($inc['scope'] ?? '') === 'ZZ138 NARRATIVE WITHHELD');
        t('eoc_show_scope=0: description is replaced with the label placeholder, not the raw narrative',
            ($inc['description'] ?? '') === 'ZZ138 NARRATIVE WITHHELD');
        t('eoc_show_scope=0: street is STILL shown in full (eoc_show_address=1 is independent)',
            ($inc['street'] ?? '') === '456 Feed Test Ave');
        t('eoc_show_scope=0: city is STILL shown in full (eoc_show_address=1 is independent)',
            ($inc['city'] ?? '') === 'Feedville');
    }

    // ── Presence-only type comes through FULL ($applyTypeVisibility=false) ──
    t('presence-only-typed incident is present in the feed (not dropped)', isset($byId[$tPresence]));
    if (isset($byId[$tPresence])) {
        $inc = $byId[$tPresence];
        t('presence-only incident is NOT stubbed — carries the real street',
            ($inc['street'] ?? '') === '456 Feed Test Ave');
        t('presence-only incident is NOT stubbed — carries the real city',
            ($inc['city'] ?? '') === 'Feedville');
        t('presence-only incident is NOT stubbed — scope/description keys are present (feed baseline, untouched by the public-board stub)',
            array_key_exists('scope', $inc) && array_key_exists('description', $inc));
        t('presence-only incident type is NOT the generic "Response" stub',
            ($inc['type'] ?? '') !== 'Response');
    }

    // ── Baseline: a plain incident keeps full detail ──────────────────────
    t('plain incident present with full street',
        isset($byId[$tPlain]) && ($byId[$tPlain]['street'] ?? '') === '456 Feed Test Ave');
    t('plain incident carries unrounded (exact) coordinates',
        isset($byId[$tPlain]) && abs(((float) $byId[$tPlain]['lat']) - 44.98765) < 0.0000001);

    // ── ISO-8601 timestamp regression (json branch) — parsed, not pattern-matched ──
    if (isset($byId[$tPlain])) {
        $openedStr = $byId[$tPlain]['opened'] ?? '';
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $openedStr, new DateTimeZone('UTC'));
        t('json "opened" parses as valid ISO-8601 UTC and round-trips byte-for-byte',
            $dt !== false && $dt->format('Y-m-d\TH:i:s\Z') === $openedStr);

        $updatedStr = $byId[$tPlain]['updated'] ?? '';
        $dt2 = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $updatedStr, new DateTimeZone('UTC'));
        t('json "updated" parses as valid ISO-8601 UTC and round-trips byte-for-byte',
            $dt2 !== false && $dt2->format('Y-m-d\TH:i:s\Z') === $updatedStr);
    }

    // ── atom/rss unaffected regression: still 200, still same-shaped output ──
    $rXml = pb_test_http_get($base . '?format=xml&key=' . urlencode($testKey));
    t('rss (default xml format) still responds 200 after the patch', $rXml !== null && $rXml['status'] === 200);
    t('rss body still contains the plain incident', $rXml !== null && strpos($rXml['body'], 'Incident #' . $tPlain) !== false);
    t('rss body does NOT contain the broadcast-blocked incident', $rXml !== null && strpos($rXml['body'], 'Incident #' . $tBlocked) === false);

} finally {
    pb_test_stop_server($srv);
    foreach ($createdTicketIds as $id) { try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdTypeIds as $id) { try { db_query("DELETE FROM `{$prefix}in_types` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdLabelIds as $id) { try { db_query("DELETE FROM `{$prefix}security_labels` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    if ($origFeedKey !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'feed_api_key'", [$origFeedKey]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'feed_api_key'");
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
