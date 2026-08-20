<?php
/**
 * Phase 73v regression tests — location spoofing trio.
 *
 * Static-text proof that the three CRITICAL findings from the
 * 2026-06-14 location audit have landed:
 *
 *   1. OwnTracks unauthenticated ingest — now fail-closed unless
 *      `owntracks_allow_anonymous=1` is explicitly set.
 *   2. /api/location.php action=report — RBAC gate added.
 *   3. /api/mobile-data.php action=report_location — client
 *      responder_id is discarded; the server resolves the caller's
 *      own responder.
 */

$tests = 0; $fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

// ── 1. OwnTracks fail-closed ──────────────────────────────────────
$locSrc = file_get_contents(__DIR__ . '/../api/location.php');
tcheck(strpos($locSrc, 'owntracks_allow_anonymous') !== false,
    'location.php references the owntracks_allow_anonymous opt-in setting');
tcheck(preg_match('/if \(!\$allowAnon\) \{\s*header\(\'HTTP\/1\.1 403/', $locSrc) === 1,
    'location.php fails closed when no secret + no token + no opt-in');
tcheck(strpos($locSrc, 'hash_equals((string) $otSecret, (string) $authHeader)') !== false,
    'location.php uses hash_equals for shared-secret comparison (timing-safe)');

// ── 2. action=report RBAC ────────────────────────────────────────
tcheck(preg_match('/if \(\$action === \'report\'\) \{\s*\/\/[^\n]*Phase 73v/', $locSrc) === 1,
    'location.php action=report block carries the Phase 73v marker');
tcheck(strpos($locSrc, "rbac_can('action.change_unit_status')") !== false
    && strpos($locSrc, "rbac_can('action.dispatch_unit')") !== false,
    'location.php action=report gated by dispatch / unit-status / admin');

// ── 3. mobile-data.php report_location server-side resolve ───────
// GH #77 (2026-08-18): report_location's resolution moved from its own
// inline 3-path lookup into the shared mobile_resolve_responder_id()
// helper (also used by add_note and the GET handler, so the three call
// sites can't independently drift the way add_note/report_location had —
// see GH #77). The resolver still takes ONLY session-derived values
// ($current_user_id, $current_member_id, $current_user) and never a
// client-supplied id, so the Phase 73v guarantee this file protects is
// unchanged; these two assertions were updated to match the new call
// shape instead of grepping for the exact inline code it replaced.
$mobSrc = file_get_contents(__DIR__ . '/../api/mobile-data.php');
tcheck(strpos($mobSrc, "// Phase 73v — CRITICAL") !== false,
    'mobile-data.php carries Phase 73v marker on report_location');
tcheck(
    (bool) preg_match(
        '/\$responderId\s*=\s*mobile_resolve_responder_id\(\s*\$prefix,\s*\$current_user_id,\s*\$current_member_id,\s*\$current_user\s*\);/',
        $mobSrc
    ),
    'mobile-data.php report_location resolves responder server-side via the shared resolver'
);
// The report_location action block itself must never read a responder id
// out of the client-supplied POST body. Isolate that block's text (from
// its `if ($action === 'report_location')` to its own closing
// `json_response(['success' => true]);`) and confirm it never references
// input['responder_id'] to decide $responderId.
$blockStart = strpos($mobSrc, "if (\$action === 'report_location')");
$blockEnd = $blockStart !== false
    ? strpos($mobSrc, "json_response(['success' => true]);", $blockStart)
    : false;
$reportLocationBlock = ($blockStart !== false && $blockEnd !== false)
    ? substr($mobSrc, $blockStart, $blockEnd - $blockStart)
    : '';
tcheck(
    $reportLocationBlock !== '' && strpos($reportLocationBlock, "responder_id'") === false,
    'mobile-data.php discards client-supplied responder_id (report_location never reads a responder_id out of the POST body)'
);
tcheck(strpos($mobSrc, 'No responder linked to this account') !== false,
    'mobile-data.php returns a clear error when caller has no linked responder');

echo "Phase 73v location-spoof regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
