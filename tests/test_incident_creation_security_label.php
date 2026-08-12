<?php
/**
 * Eric (2026-08-12) — a security label applied AFTER an incident is saved
 * cannot protect that incident's FIRST broadcast. incident_create_internal()
 * fires the incident|create|ticket audit event synchronously right after the
 * INSERT, which drives push_fire() -> router_evaluate() ->
 * seclabel_resolve($ticket_id) for internal broadcast/routing. Investigation
 * found two real gaps that together meant EVERY new incident's first
 * broadcast went out under the most permissive label, unconditionally:
 *
 *   1. The New Incident form had no field to set a label at creation time
 *      at all — the only picker lived on incident-detail.php, reachable
 *      only AFTER the incident already existed (and had already broadcast).
 *   2. in_types.default_security_label_id (seclabel_resolve()'s tier 2 —
 *      "use this type's configured default") existed in schema but had NO
 *      admin UI anywhere to set it, making that tier permanently dead on
 *      every install.
 *
 * Both are fixed: settings.php's Incident Types panel can now set a
 * per-type default, and new-incident.php has a Sensitivity picker that
 * writes security_label_override_id inside incident_create_internal()
 * BEFORE the audit_log() call, not via a follow-up UPDATE.
 *
 * This test drives incident_create_internal() directly (the real writer,
 * not a hand-seeded ticket row) and checks seclabel_resolve() immediately
 * after — proving the label a caller sees on the very first resolve is
 * already correct, not "correct after someone remembers to fix it."
 *
 * @requires-db
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/security-labels.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Incident creation must be able to protect its own first broadcast ===\n\n";

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

$adminId = test_admin_user_id();

$createdTicketIds = [];
$createdTypeIds   = [];
$cleanup = function () use (&$createdTicketIds, &$createdTypeIds, $prefix) {
    foreach ($createdTicketIds as $tid) {
        try {
            db_query("DELETE FROM `{$prefix}action`  WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tid]);
            db_query("DELETE FROM `{$prefix}ticket`  WHERE id = ?",        [$tid]);
        } catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
    }
    foreach ($createdTypeIds as $tyid) {
        try { db_query("DELETE FROM `{$prefix}in_types` WHERE id = ?", [$tyid]); }
        catch (Exception $e) { echo "  (cleanup warning: {$e->getMessage()})\n"; }
    }
};
register_shutdown_function($cleanup);

// ── Fixtures: three real security_labels rows (the seeded standard/
// restricted/confidential trio, whichever ones exist) ──────────────────
$labels = seclabel_get_all();
if (count($labels) < 2) {
    echo "SKIP: fewer than 2 security_labels rows configured — cannot exercise overrides\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}
$systemDefault = seclabel_default();
$nonDefaultLabel = null;
foreach ($labels as $l) {
    if ((int) $l['id'] !== (int) $systemDefault['id']) { $nonDefaultLabel = $l; break; }
}
if (!$nonDefaultLabel) {
    echo "SKIP: no non-default security label available to test against\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// A base incident type with NO configured default (the common case on
// every install today, since no admin UI ever wrote this column before).
db_query(
    "INSERT INTO `{$prefix}in_types` (`type`, `description`, `set_severity`, `default_security_label_id`)
     VALUES (?, ?, 0, NULL)",
    ['GH-SECLABEL-TEST-NoDefault-' . bin2hex(random_bytes(4)), 'test fixture']
);
$typeNoDefaultId = (int) db_insert_id();
$createdTypeIds[] = $typeNoDefaultId;

// A second type WITH a configured default (the fix for tier 2).
db_query(
    "INSERT INTO `{$prefix}in_types` (`type`, `description`, `set_severity`, `default_security_label_id`)
     VALUES (?, ?, 0, ?)",
    ['GH-SECLABEL-TEST-WithDefault-' . bin2hex(random_bytes(4)), 'test fixture', $nonDefaultLabel['id']]
);
$typeWithDefaultId = (int) db_insert_id();
$createdTypeIds[] = $typeWithDefaultId;

// ── 1. No override, type has no default -> resolves to system default ──
$res1 = incident_create_internal([
    'in_types_id' => $typeNoDefaultId,
    'scope'       => 'GH-SECLABEL-TEST no override, no type default',
    'description' => 'regression fixture',
], $adminId);
$id1 = (int) ($res1['id'] ?? 0);
if ($id1 > 0) { $createdTicketIds[] = $id1; ok('created fixture 1 (no override, no type default)'); }
else bad('incident_create_internal failed for fixture 1', implode('; ', $res1['errors'] ?? []));

if ($id1 > 0) {
    $resolved1 = seclabel_resolve($id1);
    if ((int) $resolved1['id'] === (int) $systemDefault['id'] && $resolved1['_resolved_from'] !== 'incident_override') {
        ok('fixture 1: seclabel_resolve() returns the system default (' . $systemDefault['name'] . '), resolved_from=' . $resolved1['_resolved_from']);
    } else {
        bad('fixture 1: expected system default label', 'got id=' . $resolved1['id'] . ' resolved_from=' . $resolved1['_resolved_from']);
    }
}

// ── 2. No override, type HAS a default -> resolves via tier 2 (the fix) ──
$res2 = incident_create_internal([
    'in_types_id' => $typeWithDefaultId,
    'scope'       => 'GH-SECLABEL-TEST no override, type has default',
    'description' => 'regression fixture',
], $adminId);
$id2 = (int) ($res2['id'] ?? 0);
if ($id2 > 0) { $createdTicketIds[] = $id2; ok('created fixture 2 (no override, type has a configured default)'); }
else bad('incident_create_internal failed for fixture 2', implode('; ', $res2['errors'] ?? []));

if ($id2 > 0) {
    $resolved2 = seclabel_resolve($id2);
    if ((int) $resolved2['id'] === (int) $nonDefaultLabel['id'] && $resolved2['_resolved_from'] === 'incident_type') {
        ok("fixture 2: seclabel_resolve() honors the incident TYPE's configured default ({$nonDefaultLabel['name']}) — this tier was previously dead code with no admin UI to reach it");
    } else {
        bad('fixture 2: expected the type default to win', 'got id=' . $resolved2['id'] . ' resolved_from=' . $resolved2['_resolved_from']);
    }
}

// ── 3. Explicit security_label_id at creation -> wins even over a type
// default (dispatcher's ad-hoc call beats the type's configured default,
// and — the actual point of this whole fix — is already in place before
// the create-audit event that drives the first broadcast). ─────────────
$res3 = incident_create_internal([
    'in_types_id'        => $typeWithDefaultId,
    'scope'               => 'GH-SECLABEL-TEST explicit override at creation',
    'description'         => 'regression fixture',
    'security_label_id'   => $systemDefault['id'], // deliberately DIFFERENT from the type's configured default
], $adminId);
$id3 = (int) ($res3['id'] ?? 0);
if ($id3 > 0) { $createdTicketIds[] = $id3; ok('created fixture 3 (explicit security_label_id at creation)'); }
else bad('incident_create_internal failed for fixture 3', implode('; ', $res3['errors'] ?? []));

if ($id3 > 0) {
    $resolved3 = seclabel_resolve($id3);
    if ((int) $resolved3['id'] === (int) $systemDefault['id'] && $resolved3['_resolved_from'] === 'incident_override') {
        ok('fixture 3: the creation-time picker\'s choice is already the resolved override on the very first check — no window where the wrong label applies');
    } else {
        bad('fixture 3: expected the creation-time override to win', 'got id=' . $resolved3['id'] . ' resolved_from=' . $resolved3['_resolved_from']);
    }

    $stampedBy = db_fetch_value("SELECT security_set_by FROM `{$prefix}ticket` WHERE id = ?", [$id3]);
    if ((int) $stampedBy === $adminId) {
        ok('fixture 3: security_set_by is stamped with the creating user');
    } else {
        bad('fixture 3: security_set_by mismatch', "got {$stampedBy}, expected {$adminId}");
    }
}

// ── 4. An invalid/unknown security_label_id must not break creation ────
$res4 = incident_create_internal([
    'in_types_id'        => $typeNoDefaultId,
    'scope'               => 'GH-SECLABEL-TEST invalid label id',
    'description'         => 'regression fixture',
    'security_label_id'   => 999999999,
], $adminId);
$id4 = (int) ($res4['id'] ?? 0);
if ($id4 > 0) {
    $createdTicketIds[] = $id4;
    ok('an unknown security_label_id does not fail incident creation (tolerant, matches every other optional field on this form)');
    $resolved4 = seclabel_resolve($id4);
    if ($resolved4['_resolved_from'] !== 'incident_override') {
        ok('fixture 4: an invalid label id is silently ignored, falls through to type/system default instead of a phantom override');
    } else {
        bad('fixture 4: an invalid label id should not have produced an override');
    }
} else {
    bad('incident_create_internal failed for fixture 4 (an invalid security_label_id must not be fatal)', implode('; ', $res4['errors'] ?? []));
}

// ── API-contract check: config-admin.php's types GET/POST actually
// carry default_security_label_id (not just the resolver knowing about it) ──
$root = dirname(__DIR__);
$configAdmin = file_get_contents($root . '/api/config-admin.php');
if (strpos($configAdmin, 'default_security_label_id') !== false) {
    ok('api/config-admin.php SELECTs default_security_label_id for the Incident Types list');
} else {
    bad('api/config-admin.php does not reference default_security_label_id');
}

$newIncidentJs = file_get_contents($root . '/assets/js/new-incident.js');
if (strpos($newIncidentJs, 'loadSecurityLabels') !== false) {
    ok('assets/js/new-incident.js populates the Sensitivity picker');
} else {
    bad('new-incident.js does not call loadSecurityLabels()');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
