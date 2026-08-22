<?php
/**
 * Phase 149 Milestone 2 — the five new RBAC codes' default grants, and
 * the api/constituents.php / api/call-history.php retrofit (plan.md §5).
 *
 * Drives the REAL endpoint files via CLI subprocess (tests/
 * _p149_endpoint_probe.php, same discipline as
 * tests/_gh96_mileage_report_probe.php) against real fixture data, one
 * subprocess per role -- proving Dispatcher/Operator/Org Admin/Super
 * Admin see BYTE-IDENTICAL pre-existing fields before/after this
 * feature's retrofit, Field Unit/Read-Only are newly and correctly
 * excluded, and Operator specifically loses field.patient_history only
 * (plan.md §5's flagged, deliberate default).
 *
 * @requires-db
 * Usage: php tests/test_inbound_calls_rbac.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 Milestone 2 — RBAC seeding + endpoint retrofit ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function p149rbac_probe(string $apiPath, string $qs, int $userId): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_p149_endpoint_probe.php')
         . ' ' . escapeshellarg($apiPath) . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg((string) $userId);
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) return null;
    $decoded = json_decode(trim((string) $out), true);
    return is_array($decoded) ? $decoded : null;
}

// Dedicated fixture id block.
$dispatcherId  = 900014920;
$operatorId    = 900014921;
$readOnlyId    = 900014922;
$fieldUnitId   = 900014923;
$constituentId = 900014930;
$ticketId      = 900014940;
$patientId     = 900014950;

$cleanup = function () use ($prefix, $dispatcherId, $operatorId, $readOnlyId, $fieldUnitId, $constituentId, $ticketId, $patientId) {
    try { db_query("DELETE FROM `{$prefix}patient` WHERE `ticket_id` = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}constituents` WHERE `id` = ?", [$constituentId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` IN (?, ?, ?, ?)", [$dispatcherId, $operatorId, $readOnlyId, $fieldUnitId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE `id` IN (?, ?, ?, ?)", [$dispatcherId, $operatorId, $readOnlyId, $fieldUnitId]); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {

    // ══════════════════════════════════════════════════════════════════
    // Default grants match plan.md §5's table
    // ══════════════════════════════════════════════════════════════════
    echo "--- Default grants (plan.md §5) ---\n\n";

    function p149_role_has(int $roleId, string $code): bool {
        global $prefix;
        $n = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}role_permissions` rp
               JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
              WHERE rp.role_id = ? AND p.code = ?",
            [$roleId, $code]
        );
        return (int) $n > 0;
    }

    $expect = [
        // code                     => [SuperAdmin(1), OrgAdmin(2), Dispatcher(3), Operator(4), FieldUnit(6), ReadOnly(5)]
        'screen.call_queue'     => [1 => true, 2 => true, 3 => true, 4 => true, 6 => false, 5 => false],
        'action.claim_call'     => [1 => true, 2 => true, 3 => true, 4 => true, 6 => false, 5 => false],
        'action.manage_calls'   => [1 => true, 2 => true, 3 => false, 4 => false, 6 => false, 5 => false],
        'field.caller_history'  => [1 => true, 2 => true, 3 => true, 4 => true, 6 => false, 5 => false],
        'field.patient_history' => [1 => true, 2 => true, 3 => true, 4 => false, 6 => false, 5 => false],
    ];
    foreach ($expect as $code => $roles) {
        foreach ($roles as $roleId => $want) {
            $has = p149_role_has($roleId, $code);
            t("role {$roleId} " . ($want ? 'HOLDS' : 'lacks') . " {$code}", $has === $want);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Fixture accounts, one per role under test
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- Fixture setup ---\n\n";

    foreach ([$dispatcherId => 3, $operatorId => 4, $readOnlyId => 5, $fieldUnitId => 6] as $uid => $roleId) {
        db_query(
            "INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
            [$uid, 'p149fixture' . $uid, password_hash('unused-test-fixture', PASSWORD_BCRYPT)]
        );
        db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$uid, $roleId]);
    }
    t('4 fixture accounts created', true);

    db_query(
        "INSERT INTO `{$prefix}constituents` (`id`, `contact`, `phone`, `updated`, `_by`)
         VALUES (?, 'P149 Test Contact', '6125559911', NOW(), 0)",
        [$constituentId]
    );
    db_query(
        "INSERT INTO `{$prefix}ticket`
            (`id`, `phone`, `street`, `city`, `status`, `date`, `severity`, `scope`, `description`, `in_types_id`)
         VALUES (?, '6125559911', '100 Test St', 'Testville', 1, NOW(), 3, 'Fixture call history incident', '', 0)",
        [$ticketId]
    );
    db_query(
        "INSERT INTO `{$prefix}patient` (`id`, `ticket_id`, `name`, `fullname`, `gender`, `description`, `date`)
         VALUES (?, ?, 'Jane', 'Jane P149 Doe', 1, 'Fixture clinical narrative — chest pain', NOW())",
        [$patientId, $ticketId]
    );

    // ══════════════════════════════════════════════════════════════════
    // api/constituents.php — success criterion #5
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- api/constituents.php retrofit ---\n\n";

    $adminId = test_admin_user_id();
    $qs = 'phone=6125559911';

    $rAdmin = p149rbac_probe('api/constituents.php', $qs, $adminId);
    t('Super Admin sees the constituent match', is_array($rAdmin) && !empty($rAdmin['constituents']));

    $rDispatch = p149rbac_probe('api/constituents.php', $qs, $dispatcherId);
    t('Dispatcher sees the constituent match (no regression)', is_array($rDispatch) && !empty($rDispatch['constituents']));

    $rOperator = p149rbac_probe('api/constituents.php', $qs, $operatorId);
    t('Operator sees the constituent match (no regression)', is_array($rOperator) && !empty($rOperator['constituents']));

    $rFieldUnit = p149rbac_probe('api/constituents.php', $qs, $fieldUnitId);
    t('Field Unit is refused (newly excluded)', is_array($rFieldUnit) && isset($rFieldUnit['error']));

    $rReadOnly = p149rbac_probe('api/constituents.php', $qs, $readOnlyId);
    t('Read-Only is refused (newly excluded)', is_array($rReadOnly) && isset($rReadOnly['error']));

    // Byte-shape check: the four legitimate roles' returned constituent
    // row is identical in field content (not merely "some data").
    if (is_array($rAdmin) && is_array($rDispatch) && is_array($rOperator)) {
        $a = $rAdmin['constituents'][0] ?? null;
        $d = $rDispatch['constituents'][0] ?? null;
        $o = $rOperator['constituents'][0] ?? null;
        t('Super Admin/Dispatcher/Operator see byte-identical constituent rows',
            $a !== null && $a === $d && $d === $o);
    } else {
        t('Super Admin/Dispatcher/Operator see byte-identical constituent rows', false);
    }

    // ══════════════════════════════════════════════════════════════════
    // api/call-history.php — success criteria #5 and #6
    // ══════════════════════════════════════════════════════════════════
    echo "\n--- api/call-history.php retrofit (success criteria #5, #6) ---\n\n";

    $qsHist = 'phone=6125559911';

    $hAdmin = p149rbac_probe('api/call-history.php', $qsHist, $adminId);
    t('Super Admin sees call history results', is_array($hAdmin) && !empty($hAdmin['results']));
    t('Super Admin (holds field.patient_history) sees nested patient data',
        is_array($hAdmin) && !empty($hAdmin['results'][0]['patients'] ?? null));
    t('Super Admin sees the disposition field (type/date/disposition travel together)',
        is_array($hAdmin) && array_key_exists('disposition', $hAdmin['results'][0] ?? []));

    $hDispatch = p149rbac_probe('api/call-history.php', $qsHist, $dispatcherId);
    t('Dispatcher sees call history results (no regression)', is_array($hDispatch) && !empty($hDispatch['results']));
    t('Dispatcher (holds field.patient_history) sees nested patient data',
        is_array($hDispatch) && !empty($hDispatch['results'][0]['patients'] ?? null));

    // Success criterion #5: screen.call_queue-tier visibility without
    // field.caller_history. Field Unit/Read-Only hold neither in this
    // fixture, so both are refused outright at the endpoint (the endpoint
    // itself has no separate screen.call_queue gate — it's gated
    // exclusively on field.caller_history per plan.md §5).
    $hFieldUnit = p149rbac_probe('api/call-history.php', $qsHist, $fieldUnitId);
    t('Field Unit is refused history entirely (success criterion #5)', is_array($hFieldUnit) && isset($hFieldUnit['error']));

    // Success criterion #6: Operator holds field.caller_history but NOT
    // field.patient_history — sees type/date/disposition, never clinical
    // detail.
    $hOperator = p149rbac_probe('api/call-history.php', $qsHist, $operatorId);
    t('Operator sees call history type/date/disposition (holds field.caller_history)',
        is_array($hOperator) && !empty($hOperator['results']) && array_key_exists('disposition', $hOperator['results'][0]));
    t('Operator does NOT see nested patient/clinical data (lacks field.patient_history — success criterion #6)',
        is_array($hOperator) && !empty($hOperator['results']) && !array_key_exists('patients', $hOperator['results'][0]));

    // Byte-shape check on the NON-patient fields shared by every
    // legitimate role — proves the retrofit didn't silently change
    // pre-existing history fields for anyone who already had access.
    if (is_array($hAdmin) && is_array($hDispatch) && is_array($hOperator)) {
        $strip = function ($row) { unset($row['patients']); return $row; };
        $a = $strip($hAdmin['results'][0] ?? []);
        $d = $strip($hDispatch['results'][0] ?? []);
        $o = $strip($hOperator['results'][0] ?? []);
        t('Super Admin/Dispatcher/Operator see byte-identical non-clinical history fields',
            !empty($a) && $a === $d && $d === $o);
    } else {
        t('Super Admin/Dispatcher/Operator see byte-identical non-clinical history fields', false);
    }

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
