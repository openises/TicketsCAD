<?php
/**
 * GH#146 (rjonesbsink, 2026-09-15) — the External API's
 * `PATCH /api/external/v1/incidents/<id>` silently dropped `status` (not in
 * incident_update_fields_internal()'s generic field whitelist, and unknown
 * keys are silently dropped there), even though it's docs/EXTERNAL-API.md's
 * own PATCH example payload. Also: `disposition_id` had no path through the
 * external API at all, independent of a status change.
 *
 * Fix: both are now DEDICATED actions in api/external/v1/incidents.php,
 * extracted from the generic field whitelist the same way Phase 151's
 * primary_responder_id already is — status routes through
 * incident_update_status_internal() (the same writer the internal
 * update_status action uses: close cascade, scheduled-date requirement,
 * disposition-required-on-close gate, close/reopen webhook-eligible audit
 * naming all apply identically), and disposition_id (when NOT part of the
 * same close) routes through incident_set_disposition_internal() (same as
 * the internal set_disposition action).
 *
 * The External API's own HTTP layer (bearer auth, scope checks) is
 * deliberately NOT re-driven here — matching the established pattern for
 * this exact class of fix (see tests/test_phase151_primary_unit.php's own
 * structural checks on the identical primary_responder_id wiring): the
 * endpoint's ROUTING is verified structurally against the real file, and
 * the underlying writer behavior is verified directly against the real DB.
 *
 * @requires-db
 * Usage: php tests/test_gh146_external_api_status.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#146 -- External API status + disposition_id ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$userId = test_admin_user_id();

// ── 1. Structural: the External API wiring itself ──
$extApi = (string) @file_get_contents(__DIR__ . '/../api/external/v1/incidents.php');

t('PATCH extracts status as a dedicated action, out of the generic field whitelist',
    strpos($extApi, "array_key_exists('status', \$fields)") !== false);
t('PATCH extracts disposition_id as a dedicated action too',
    strpos($extApi, "array_key_exists('disposition_id', \$fields)") !== false);
// GH#147 (F7): the permission checks moved to BEFORE the first write (they used
// to sit inside the status/disposition branches, after the generic fields had
// already been saved and audited). Same gates, same permission codes -- the
// regexes below match the new `$flag && !rbac_can(...)` shape, and the position
// assertions pin the ordering (tests/test_gh147_every_path_fires_once.php drives
// it for real).
t('status write checks action.close_incident (same gate the internal update_status action uses)',
    (bool) preg_match("/\\\$statusFieldPresent && !rbac_can\\('action\\.close_incident'\\)/", $extApi));
t('disposition write checks action.edit_incident (same gate the internal set_disposition action uses)',
    (bool) preg_match("/\\\$dispositionFieldPresent && !rbac_can\\('action\\.edit_incident'\\)/", $extApi));
$firstWrite = strpos($extApi, '= incident_update_fields_internal(');   // the CALL (comments name the function too)
$statusGate = strpos($extApi, "\$statusFieldPresent && !rbac_can('action.close_incident')");
$dispGate   = strpos($extApi, "\$dispositionFieldPresent && !rbac_can('action.edit_incident')");
t('GH#147: the status permission is decided BEFORE the first write', $statusGate !== false && $firstWrite !== false && $statusGate < $firstWrite);
t('GH#147: the disposition permission is decided BEFORE the first write', $dispGate !== false && $firstWrite !== false && $dispGate < $firstWrite);
t('status routes through incident_update_status_internal() -- the real business-logic writer, not a raw UPDATE',
    (bool) preg_match('/statusFieldPresent[\s\S]{0,900}?incident_update_status_internal\(/', $extApi));
t('standalone disposition_id routes through incident_set_disposition_internal()',
    (bool) preg_match('/dispositionFieldPresent[\s\S]{0,900}?incident_set_disposition_internal\(/', $extApi));
t('a disposition_id sent alongside status=1 is folded into the SAME close call (not double-processed)',
    strpos($extApi, "'disposition_id' => \$dispositionForClose") !== false);
t('status change audits with the canonical close/reopen/update activity naming (so the existing webhook map fires)',
    (bool) preg_match('/\$auditActivity\s*=\s*\(\$newStatus === 1\) \? .close.\s*:/', $extApi));
t('no-fields check now also requires status/disposition absent, not just the generic $fields array',
    strpos($extApi, '!$statusFieldPresent && !$dispositionFieldPresent') !== false);

// ── 2. docs/EXTERNAL-API.md's own example payload is no longer a lie ──
$doc = (string) @file_get_contents(__DIR__ . '/../docs/EXTERNAL-API.md');
t('docs/EXTERNAL-API.md still shows the { "severity": 3, "status": 2 } example (unchanged -- it is correct now)',
    strpos($doc, '"status": 2') !== false);

// ── 3. incident_set_disposition_internal()'s new via_external_api flag ──
// Fixture ticket, disposition rows, cleaned up at the end regardless of outcome.
$ticketId = null; $dispId = null;
register_shutdown_function(function () use (&$ticketId, &$dispId, $prefix) {
    if ($ticketId) {
        try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]); } catch (Exception $e) {}
        try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE target_type = 'ticket' AND target_id = ?", [(string) $ticketId]); } catch (Exception $e) {}
    }
    if ($dispId) {
        try { db_query("DELETE FROM `{$prefix}ticket_disposition` WHERE id = ?", [$dispId]); } catch (Exception $e) {}
    }
});

try {
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `date`, `status`, `severity`)
         VALUES (0, 'GH146 fixture incident', 'gh146 fixture', NOW(), 2, 0)"
    );
    $ticketId = (int) db_insert_id();
    t('fixture ticket created', $ticketId > 0);

    db_query(
        "INSERT INTO `{$prefix}ticket_disposition` (`status_val`, `description`, `code`, `active`)
         VALUES ('GH146 Test Disposition', 'gh146 fixture', 'GH146TEST', 1)"
    );
    $dispId = (int) db_insert_id();
    t('fixture disposition created', $dispId > 0);
} catch (Exception $e) {
    t('fixture setup', false, $e->getMessage());
}

if ($ticketId && $dispId) {
    $result = incident_set_disposition_internal($ticketId, $dispId, $userId, true);
    t('incident_set_disposition_internal() with viaExternalApi=true still succeeds',
        !empty($result['updated']) && empty($result['errors']));

    $logged = db_fetch_value(
        "SELECT details FROM `{$prefix}newui_audit_log`
          WHERE category = 'incident' AND activity = 'disposition_set' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $ticketId]
    );
    $decoded = $logged ? json_decode((string) $logged, true) : null;
    t('the audit entry carries via_external_api=true when called from the external-API path',
        is_array($decoded) && ($decoded['via_external_api'] ?? null) === true,
        'got: ' . var_export($decoded, true));

    // Backward-compat: existing 3-arg callers (the internal UI path) must
    // still default to via_external_api=false, not break or require updating.
    $result2 = incident_set_disposition_internal($ticketId, $dispId, $userId);
    $logged2 = db_fetch_value(
        "SELECT details FROM `{$prefix}newui_audit_log`
          WHERE category = 'incident' AND activity = 'disposition_set' AND target_id = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $ticketId]
    );
    $decoded2 = $logged2 ? json_decode((string) $logged2, true) : null;
    t('a 3-arg call (the pre-existing internal-UI shape) still works and defaults via_external_api to false',
        !empty($result2['updated']) && is_array($decoded2) && ($decoded2['via_external_api'] ?? null) === false,
        'got: ' . var_export($decoded2, true));
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
