<?php
/**
 * Phase 151 (GH#138, follow-on to GH#16) — Primary / Responsible Unit.
 *
 * Drives the REAL writers (incident_set_primary_internal(),
 * assign_create_internal(), assign_unassign_internal(),
 * assign_update_status_internal()) against throwaway fixtures — never
 * hand-seeded ideal state — per this project's own standing discipline.
 *
 * @requires-db
 * Usage: php tests/test_phase151_primary_unit.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/webhooks.php';
require_once __DIR__ . '/../inc/org-sharing.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

/**
 * Runs one scenario in a FRESH subprocess via tests/_phase151_worker.php --
 * see that file's docblock for why (get_variable()'s static per-process
 * settings cache). Returns the decoded JSON, or null on failure.
 */
function phase151_run(string $mode, string $action, array $args): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_phase151_worker.php')
         . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($action);
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

echo "=== Phase 151 (GH#138) — Primary / Responsible Unit ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();

// ══════════════════════════════════════════════════════════════════
// 1. Source-level wiring
// ══════════════════════════════════════════════════════════════════
echo "--- Wiring ---\n";

$assignApi = file_get_contents(__DIR__ . '/../api/incident-assign.php');
t("api/incident-assign.php: set_primary action checks action.set_primary_unit",
    (bool) preg_match("/elseif \(\\\$action === 'set_primary'\\) \{\\s*\n\\s*if \(!rbac_can\('action\.set_primary_unit'\)\)/", $assignApi));
t("api/incident-assign.php: set_primary action calls incident_set_primary_internal()",
    (bool) preg_match("/'set_primary'[\\s\\S]{0,600}?incident_set_primary_internal\(/", $assignApi));

$extApi = file_get_contents(__DIR__ . '/../api/external/v1/incidents.php');
t("api/external/v1/incidents.php: PATCH extracts primary_responder_id as a dedicated action",
    strpos($extApi, "array_key_exists('primary_responder_id', \$fields)") !== false);
t("api/external/v1/incidents.php: primary write checks action.set_primary_unit",
    (bool) preg_match("/primaryFieldPresent\\)\\s*\\{\\s*\n\\s*if \(!rbac_can\('action\.set_primary_unit'\)\)/", $extApi));
t("api/external/v1/incidents.php: GET flows primary_responder_name via a schema-resilient join",
    strpos($extApi, 'primary_responder_name') !== false);

$webhooksSrc = file_get_contents(__DIR__ . '/../inc/webhooks.php');
t("inc/webhooks.php: maps incident|primary_change|ticket -> incident.primary_changed",
    strpos($webhooksSrc, "'incident|primary_change|ticket' => 'incident.primary_changed'") !== false);
t("_audit_to_webhook_event() resolves the mapping for real",
    _audit_to_webhook_event('incident', 'primary_change', 'ticket') === 'incident.primary_changed');

$settingsSrc = file_get_contents(__DIR__ . '/../settings.php');
t("settings.php: has its own wh-evt checkbox for incident.primary_changed (not just the mapping)",
    strpos($settingsSrc, 'value="incident.primary_changed"') !== false);

$orgSharingSrc = file_get_contents(__DIR__ . '/../inc/org-sharing.php');
t("inc/org-sharing.php: primary_responder_id/name are in the view-tier allowlist",
    strpos($orgSharingSrc, "'primary_responder_id', 'primary_responder_name'") !== false);

// ══════════════════════════════════════════════════════════════════
// RBAC seeding
// ══════════════════════════════════════════════════════════════════
echo "\n--- RBAC ---\n";
$permId = (int) db_fetch_value("SELECT id FROM `{$prefix}permissions` WHERE code = ?", ['action.set_primary_unit']);
t('action.set_primary_unit permission exists', $permId > 0);
if ($permId > 0) {
    foreach ([1 => 'Super Admin', 2 => 'Org Admin', 3 => 'Dispatcher'] as $roleId => $roleName) {
        $has = (bool) db_fetch_value(
            "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
            [$roleId, $permId]);
        t("$roleName (role $roleId) holds action.set_primary_unit", $has);
    }
}

// ══════════════════════════════════════════════════════════════════
// Fixtures
// ══════════════════════════════════════════════════════════════════
$respAId = 900151101;
$respBId = 900151102;
$ticket1Id = 0;
$ticket2Id = 0;
$assignIds = [];
$originalMode = get_variable('primary_unit_mode');

$cleanup = function () use ($prefix, $respAId, $respBId, &$ticket1Id, &$ticket2Id, &$assignIds, $originalMode) {
    try { db_query("DELETE FROM `{$prefix}assigns` WHERE responder_id IN (?, ?)", [$respAId, $respBId]); } catch (Throwable $e) {}
    try { if ($ticket1Id) db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$ticket1Id]); } catch (Throwable $e) {}
    try { if ($ticket2Id) db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$ticket2Id]); } catch (Throwable $e) {}
    try { if ($ticket1Id) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticket1Id]); } catch (Throwable $e) {}
    try { if ($ticket2Id) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticket2Id]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}responder` WHERE id IN (?, ?)", [$respAId, $respBId]); } catch (Throwable $e) {}
    try {
        if ($originalMode !== false) {
            db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = ?", [$originalMode, 'primary_unit_mode']);
        }
    } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {
    db_query(
        "INSERT INTO `{$prefix}responder` (`id`, `name`, `handle`, `description`)
         VALUES (?, 'GH138 Test Unit A', 'GH138-A', ''), (?, 'GH138 Test Unit B', 'GH138-B', '')",
        [$respAId, $respBId]
    );
    t('fixture responders created', true);

    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `date`, `status`, `severity`)
         VALUES (0, 'GH138 fixture incident 1', 'gh138 fixture', NOW(), 2, 0)"
    );
    $ticket1Id = (int) db_insert_id();
    t('fixture incident 1 created', $ticket1Id > 0);

    // ══════════════════════════════════════════════════════════════
    // off mode — the no-op guarantee. Every scenario below runs through
    // phase151_run() (a fresh subprocess per call) rather than in-process
    // — see tests/_phase151_worker.php's docblock: get_variable()'s
    // per-process settings cache means a mode flipped mid-process via
    // direct SQL is invisible to every later in-process writer call.
    // ══════════════════════════════════════════════════════════════
    echo "\n--- off mode (no-op) ---\n";
    if (!db_fetch_value("SELECT 1 FROM `{$prefix}settings` WHERE name = ?", ['primary_unit_mode'])) {
        db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES ('primary_unit_mode', 'off')");
    }

    // audit_log() writes to newui_audit_log (target_type='ticket',
    // target_id=<ticket id as string>), a SEPARATE table from `action`
    // (which is populated only by incident_add_note_internal() and
    // _assign_log_action() — confirmed by reading inc/audit.php directly).
    $auditCountBefore = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`
          WHERE category = 'incident' AND activity = 'primary_change' AND target_id = ?",
        [(string) $ticket1Id]);

    $off = phase151_run('off', 'set_primary', [$ticket1Id, $respAId, $adminId]);
    t('off mode: worker ran', $off !== null);
    t('off mode: updated=false', $off && $off['result']['updated'] === false);
    t("off mode: noop_reason='mode_off'", $off && ($off['result']['noop_reason'] ?? '') === 'mode_off');
    t('off mode: ticket.primary_responder_id unchanged (NULL)', $off && $off['primary_responder_id'] === null);

    $auditCountAfter = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`
          WHERE category = 'incident' AND activity = 'primary_change' AND target_id = ?",
        [(string) $ticket1Id]);
    t('off mode: no audit_log row written', $auditCountAfter === $auditCountBefore);

    // ══════════════════════════════════════════════════════════════
    // manual mode
    // ══════════════════════════════════════════════════════════════
    echo "\n--- manual mode ---\n";

    $refuse = phase151_run('manual', 'set_primary', [$ticket1Id, $respAId, $adminId]);
    t('manual mode: refused when candidate has no assigns row on this ticket',
        $refuse && !empty($refuse['result']['errors']));

    $a1r = phase151_run('manual', 'assign_create', [$ticket1Id, $respAId, $adminId]);
    t('fixture: unit A assigned to incident 1', $a1r && empty($a1r['result']['errors']));
    $a1Id = $a1r ? (int) ($a1r['result']['id'] ?? 0) : 0;
    if ($a1Id) $assignIds[] = $a1Id;

    $set1 = phase151_run('manual', 'set_primary', [$ticket1Id, $respAId, $adminId]);
    t('manual mode: set succeeds once candidate has an assigns row',
        $set1 && $set1['result']['updated'] === true);
    t('manual mode: ticket.primary_responder_id set', $set1 && $set1['primary_responder_id'] === $respAId);

    $tRow = db_fetch_one(
        "SELECT primary_set_at, primary_set_by FROM `{$prefix}ticket` WHERE id = ?", [$ticket1Id]);
    t('manual mode: primary_set_at stamped', !empty($tRow['primary_set_at']));
    t('manual mode: primary_set_by stamped', (int) $tRow['primary_set_by'] === $adminId);

    $auditRow = db_fetch_one(
        "SELECT summary, details FROM `{$prefix}newui_audit_log`
          WHERE category = 'incident' AND activity = 'primary_change' AND target_id = ?
          ORDER BY id DESC LIMIT 1", [(string) $ticket1Id]);
    t('manual mode: an audit_log row was written for the change',
        $auditRow && strpos((string) $auditRow['summary'], (string) $ticket1Id) !== false);
    $auditDetails = $auditRow ? json_decode((string) $auditRow['details'], true) : [];
    t('manual mode: audit details carry new_responder_id + reason',
        ($auditDetails['new_responder_id'] ?? null) === $respAId
        && ($auditDetails['reason'] ?? null) === 'manual'
        && array_key_exists('via_external_api', $auditDetails)
        && $auditDetails['via_external_api'] === false);

    $auditCountBeforeRepeat = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`
          WHERE category = 'incident' AND activity = 'primary_change' AND target_id = ?",
        [(string) $ticket1Id]);
    $repeat = phase151_run('manual', 'set_primary', [$ticket1Id, $respAId, $adminId]);
    t('manual mode: re-setting the SAME value still succeeds',
        $repeat && $repeat['result']['updated'] === true);
    $auditCountAfterRepeat = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_audit_log`
          WHERE category = 'incident' AND activity = 'primary_change' AND target_id = ?",
        [(string) $ticket1Id]);
    t('manual mode: a no-op re-set is STILL audited (matches incident_set_disposition_internal()\'s '
        . 'own "audit every successful write" convention -- verified by reading it directly, not assumed)',
        $auditCountAfterRepeat === $auditCountBeforeRepeat + 1);

    $clear1 = phase151_run('manual', 'set_primary', [$ticket1Id, 0, $adminId]);
    t('manual mode: clearing (null) succeeds',
        $clear1 && $clear1['result']['updated'] === true && $clear1['primary_responder_id'] === null);

    // Re-set for the unassign test below.
    phase151_run('manual', 'set_primary', [$ticket1Id, $respAId, $adminId]);

    // ══════════════════════════════════════════════════════════════
    // unassign clears the primary; a normal clear does NOT
    // ══════════════════════════════════════════════════════════════
    echo "\n--- unassign vs. normal clear ---\n";
    $un = phase151_run('manual', 'assign_unassign', [$a1Id, $adminId]);
    t('assign_unassign_internal() succeeded', $un && empty($un['result']['errors']));
    t('unassigning the primary unit outright clears the designation',
        $un && $un['primary_responder_id'] === null);

    // Re-assign A and re-mark primary, then drive a REAL normal clear
    // (assign_update_status_internal, not assign_unassign_internal) and
    // confirm the designation PERSISTS -- the feature's own stated rule.
    $a1br = phase151_run('manual', 'assign_create', [$ticket1Id, $respAId, $adminId]);
    t('fixture: unit A re-assigned to incident 1', $a1br && empty($a1br['result']['errors']));
    $a1bId = $a1br ? (int) ($a1br['result']['id'] ?? 0) : 0;
    if ($a1bId) $assignIds[] = $a1bId;
    phase151_run('manual', 'set_primary', [$ticket1Id, $respAId, $adminId]);

    $clearStatusId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}un_status` WHERE incident_action = 'clear' LIMIT 1");
    if ($clearStatusId > 0 && $a1bId) {
        $clearStatusResult = phase151_run('manual', 'assign_update_status', [$a1bId, $clearStatusId, $adminId]);
        t('assign_update_status_internal() clear succeeded',
            $clearStatusResult && empty($clearStatusResult['result']['errors']));
        t('a normal status-driven clear does NOT clear the primary designation',
            $clearStatusResult && $clearStatusResult['primary_responder_id'] === $respAId);
    } else {
        echo "[SKIP] no un_status row with incident_action='clear' on this install -- "
            . "normal-clear-persists assertion skipped\n";
    }

    // ══════════════════════════════════════════════════════════════
    // auto mode
    // ══════════════════════════════════════════════════════════════
    echo "\n--- auto mode ---\n";

    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `date`, `status`, `severity`)
         VALUES (0, 'GH138 fixture incident 2', 'gh138 fixture', NOW(), 2, 0)"
    );
    $ticket2Id = (int) db_insert_id();
    t('fixture incident 2 created', $ticket2Id > 0);

    $a2r = phase151_run('auto', 'assign_create', [$ticket2Id, $respAId, $adminId]);
    t('auto mode: unit A assigned to incident 2 (the ONLY unit)', $a2r && empty($a2r['result']['errors']));
    $a2Id = $a2r ? (int) ($a2r['result']['id'] ?? 0) : 0;
    if ($a2Id) $assignIds[] = $a2Id;
    t('auto mode: single-unit incident auto-set the primary unit',
        $a2r && $a2r['primary_responder_id'] === $respAId);

    $autoAuditRow = db_fetch_one(
        "SELECT details FROM `{$prefix}newui_audit_log`
          WHERE category = 'incident' AND activity = 'primary_change' AND target_id = ?
          ORDER BY id DESC LIMIT 1", [(string) $ticket2Id]);
    $autoAuditDetails = $autoAuditRow ? json_decode((string) $autoAuditRow['details'], true) : [];
    t('auto mode: the audit_log row exists with reason=auto_single_unit',
        ($autoAuditDetails['reason'] ?? null) === 'auto_single_unit');

    $a3r = phase151_run('auto', 'assign_create', [$ticket2Id, $respBId, $adminId]);
    t('auto mode: unit B added as a SECOND unit', $a3r && empty($a3r['result']['errors']));
    $a3Id = $a3r ? (int) ($a3r['result']['id'] ?? 0) : 0;
    if ($a3Id) $assignIds[] = $a3Id;
    t('auto mode: a second unit being added does NOT re-trigger auto-population',
        $a3r && $a3r['primary_responder_id'] === $respAId);

} catch (Throwable $e) {
    t('no exception thrown', false);
    echo 'Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
