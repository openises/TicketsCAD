<?php
/**
 * Follow-up to Phase 150 (GH #135) — api/log.php had no org-scoping or
 * security-label redaction anywhere in the file: any authenticated user
 * could see free-text notes and activity for every ticket across every
 * org, including tickets tagged Restricted/Confidential.
 *
 * Drives the REAL api/log.php endpoint (via the CLI probe used by
 * tests/test_gh135_quick_incident_notes.php, extended to accept a
 * user_id/active_org_id so it can impersonate an org-scoped, non-Super-
 * Admin session) against real fixtures — never a hand-seeded ideal case.
 *
 * @requires-db
 * Usage: php tests/test_log_org_seclabel_scope.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/security-labels.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== api/log.php — org-scoping + security-label redaction ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();

$orgAId = 900153201;
$orgBId = 900153202;
$scopedUserId = 900153203;
$ticketAId = 0;   // owned by Org A, plain (no restricted label)
$ticketRId = 0;   // owned by nobody in particular, restricted label
$restrictedLabelId = 0;
$respAId = 900153211; // Org A responder, has a unit note
$respBId = 900153212; // Org B responder — control
$facAId = 900153221;  // Org A facility, has a facility note
$facBId = 900153222;  // Org B facility — control

function gh_log_probe(int $userId = 0, int $activeOrgId = 0): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_gh135_log_probe.php')
         . ' ' . escapeshellarg('30');
    if ($userId > 0) {
        $cmd .= ' ' . escapeshellarg((string) $userId) . ' ' . escapeshellarg((string) $activeOrgId);
    }
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

function gh_log_has_marker(?array $payload, string $marker): bool {
    if (!$payload || empty($payload['entries'])) return false;
    foreach ($payload['entries'] as $e) {
        if (strpos((string) ($e['info'] ?? ''), $marker) !== false) return true;
    }
    return false;
}

$cleanup = function () use (
    $prefix, $orgAId, $orgBId, $scopedUserId, &$ticketAId, &$ticketRId,
    $respAId, $respBId, $facAId, $facBId, &$restrictedLabelId
) {
    try { if ($ticketAId) db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$ticketAId]); } catch (Throwable $e) {}
    try { if ($ticketRId) db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$ticketRId]); } catch (Throwable $e) {}
    try { if ($ticketAId) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketAId]); } catch (Throwable $e) {}
    try { if ($ticketRId) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketRId]); } catch (Throwable $e) {}
    try { if ($restrictedLabelId) db_query("DELETE FROM `{$prefix}security_labels` WHERE id = ?", [$restrictedLabelId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}responder_notes` WHERE responder_id IN (?, ?)", [$respAId, $respBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facility_notes` WHERE facility_id IN (?, ?)", [$facAId, $facBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}responder` WHERE id IN (?, ?)", [$respAId, $respBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}facilities` WHERE id IN (?, ?)", [$facAId, $facBId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$scopedUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$scopedUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}organizations` WHERE id IN (?, ?)", [$orgAId, $orgBId]); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {
    // ══════════════════════════════════════════════════════════════
    // Fixtures
    // ══════════════════════════════════════════════════════════════
    db_query("INSERT INTO `{$prefix}organizations` (id, name) VALUES (?, 'GH log-scope Org A')", [$orgAId]);
    db_query("INSERT INTO `{$prefix}organizations` (id, name) VALUES (?, 'GH log-scope Org B')", [$orgBId]);

    db_query(
        "INSERT INTO `{$prefix}ticket` (in_types_id, org_id, scope, description, date, status, severity)
         VALUES (0, ?, 'gh-log-scope org-a incident', 'fixture', NOW(), 2, 0)",
        [$orgAId]
    );
    $ticketAId = (int) db_insert_id();
    t('fixture: Org A ticket created', $ticketAId > 0);

    db_query(
        "INSERT INTO `{$prefix}ticket` (in_types_id, org_id, scope, description, date, status, severity)
         VALUES (0, ?, 'gh-log-scope restricted incident', 'fixture', NOW(), 2, 0)",
        [$orgAId]
    );
    $ticketRId = (int) db_insert_id();
    t('fixture: restricted-label ticket created', $ticketRId > 0);

    // Org-B-scoped user: Org Admin (role 2), NOT Super Admin, scoped to Org
    // B only. Both org_id AND scope_id must carry orgBId (CLAUDE.md's own
    // documented pitfall: an 'org'-scope_kind grant with only org_id set
    // passes org_visible_ids() but silently fails rbac_can()).
    db_query("INSERT INTO `{$prefix}user` (id, user, passwd, can_login) VALUES (?, 'gh-log-scope-orgb', 'x', 0)", [$scopedUserId]);
    db_query(
        "INSERT INTO `{$prefix}user_roles` (user_id, role_id, org_id, scope_kind, scope_id) VALUES (?, 2, ?, 'org', ?)",
        [$scopedUserId, $orgBId, $orgBId]
    );

    $marker = 'ghlogscope-' . bin2hex(random_bytes(4));
    $result = incident_add_note_internal($ticketAId, "Org A note {$marker}", $adminId);
    t('note added to Org A ticket via the real writer', empty($result['errors']));

    $restrictedMarker = 'ghlogscope-restricted-' . bin2hex(random_bytes(4));
    $resultR = incident_add_note_internal($ticketRId, "Restricted note {$restrictedMarker}", $adminId);
    t('note added to the restricted ticket via the real writer', empty($resultR['errors']));

    // A real security label with routing_allow_broadcast = 0, applied via
    // the real writer (seclabel_apply_override), same mechanism api/feed.php
    // already relies on for its own Phase 138 redaction.
    $restrictedLabelId = seclabel_create([
        'name' => 'GH Log-Scope Test Restricted',
        'code' => 'ghlogscopetest',
        'routing_allow_broadcast' => 0,
    ]);
    t('restricted security label created', $restrictedLabelId > 0);
    if ($restrictedLabelId > 0) {
        $ov = seclabel_apply_override($ticketRId, $restrictedLabelId, null, $adminId);
        t('restricted label applied to the ticket', !empty($ov['ok']));
    }

    // ══════════════════════════════════════════════════════════════
    // Org scoping — the base log query + incident-notes merge
    // ══════════════════════════════════════════════════════════════
    echo "\n--- Org scoping ---\n";

    $asAdmin = gh_log_probe();
    t('Super Admin sees the Org A note (positive control)',
        gh_log_has_marker($asAdmin, $marker));

    $asOrgB = gh_log_probe($scopedUserId, $orgBId);
    t('an Org-B-scoped, non-Super-Admin viewer does NOT see the Org A note',
        !gh_log_has_marker($asOrgB, $marker));

    // ══════════════════════════════════════════════════════════════
    // Security-label redaction — universal, not org-gated
    // ══════════════════════════════════════════════════════════════
    echo "\n--- Security-label redaction ---\n";

    t('Super Admin does NOT see the Restricted-labeled ticket\'s note',
        !gh_log_has_marker($asAdmin, $restrictedMarker));

    $asAdmin2 = gh_log_probe();
    t('re-probed as Super Admin: restricted note still absent (not a one-off)',
        !gh_log_has_marker($asAdmin2, $restrictedMarker));

    // ══════════════════════════════════════════════════════════════
    // responder_notes / facility_notes — org-scoped on r.org_id / f.org_id
    // ══════════════════════════════════════════════════════════════
    echo "\n--- Unit + Facility note org scoping ---\n";

    $hasResponderOrgCol = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='org_id'",
        [$prefix . 'responder']
    );
    $hasFacilityOrgCol = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='org_id'",
        [$prefix . 'facilities']
    );

    if ($hasResponderOrgCol) {
        db_query(
            "INSERT INTO `{$prefix}responder` (id, name, handle, description, org_id) VALUES (?, 'GH Log Scope Unit A', 'GHLogA', '', ?)",
            [$respAId, $orgAId]
        );
        $unitMarker = 'ghlogscope-unit-' . bin2hex(random_bytes(4));
        db_query(
            "INSERT INTO `{$prefix}responder_notes` (responder_id, note, by_username, created_at) VALUES (?, ?, 'fixture', NOW())",
            [$respAId, $unitMarker]
        );
        $asAdmin3 = gh_log_probe();
        t('Super Admin sees the Org A unit note (positive control)', gh_log_has_marker($asAdmin3, $unitMarker));
        $asOrgB2 = gh_log_probe($scopedUserId, $orgBId);
        t('an Org-B-scoped viewer does NOT see the Org A unit note', !gh_log_has_marker($asOrgB2, $unitMarker));
    } else {
        echo "[SKIP] responder.org_id absent on this install -- unit-note scoping assertions skipped\n";
    }

    if ($hasFacilityOrgCol) {
        db_query(
            "INSERT INTO `{$prefix}facilities` (id, name, description, org_id) VALUES (?, 'GH Log Scope Facility A', '', ?)",
            [$facAId, $orgAId]
        );
        $facMarker = 'ghlogscope-fac-' . bin2hex(random_bytes(4));
        db_query(
            "INSERT INTO `{$prefix}facility_notes` (facility_id, note, username, created_at) VALUES (?, ?, 'fixture', NOW())",
            [$facAId, $facMarker]
        );
        $asAdmin4 = gh_log_probe();
        t('Super Admin sees the Org A facility note (positive control)', gh_log_has_marker($asAdmin4, $facMarker));
        $asOrgB3 = gh_log_probe($scopedUserId, $orgBId);
        t('an Org-B-scoped viewer does NOT see the Org A facility note', !gh_log_has_marker($asOrgB3, $facMarker));
    } else {
        echo "[SKIP] facilities.org_id absent on this install -- facility-note scoping assertions skipped\n";
    }

} catch (Throwable $e) {
    t('no exception thrown', false);
    echo 'Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
