<?php
/**
 * Security Audit — Endpoint Inventory
 *
 * Catalogs every API endpoint and identifies its security posture:
 *   - Does it require authentication?
 *   - Does it call rbac_can()?
 *   - Does it verify CSRF on POST?
 *   - Does it accept an ID parameter that needs ownership checks?
 *   - What HTTP methods does it accept?
 *
 * Output: CSV-style report ready for the audit checklist.
 *
 * Usage: php tools/security_audit_inventory.php > specs/security-audit-2026-04/endpoint-inventory.md
 */

$apiDir = __DIR__ . '/../api';
$files = glob($apiDir . '/*.php');

echo "# NewUI API Endpoint Security Inventory\n\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n";
echo "Total endpoints: " . count($files) . "\n\n";

echo "| Endpoint | Auth | RBAC | CSRF | ID Param | Methods | Notes |\n";
echo "|----------|------|------|------|----------|---------|-------|\n";

$totals = [
    'auth' => 0,
    'rbac' => 0,
    'csrf' => 0,
    'id_param' => 0,
    'no_auth' => [],
    'no_rbac' => [],
    'no_csrf' => [],
    'id_no_ownership' => [],
];

foreach ($files as $file) {
    $name = basename($file);
    $content = file_get_contents($file);

    // Auth check
    $hasAuth = strpos($content, "require_once __DIR__ . '/auth.php'") !== false
            || strpos($content, 'require_once __DIR__ . "/auth.php"') !== false
            || strpos($content, "require __DIR__ . '/auth.php'") !== false;

    // RBAC check
    $hasRbac = preg_match('/rbac_can\s*\(/', $content);

    // CSRF check
    $hasCsrf = preg_match('/csrf_verify\s*\(/', $content);

    // ID parameter (potential IDOR)
    $hasIdParam = preg_match('/\$_(GET|POST|REQUEST)\s*\[\s*[\'"](?:id|incident_id|ticket_id|user_id|member_id|responder_id|facility_id|patient_id)[\'"]/i', $content);

    // HTTP methods
    $methods = [];
    if (strpos($content, "REQUEST_METHOD'] === 'GET'") !== false || strpos($content, '$_GET[') !== false) $methods[] = 'GET';
    if (strpos($content, "REQUEST_METHOD'] === 'POST'") !== false || strpos($content, '$_POST[') !== false) $methods[] = 'POST';
    if (strpos($content, "REQUEST_METHOD'] === 'DELETE'") !== false) $methods[] = 'DELETE';
    if (strpos($content, "REQUEST_METHOD'] === 'PUT'") !== false) $methods[] = 'PUT';
    $methodsStr = implode(',', $methods) ?: '?';

    // Notes
    $notes = [];
    if (preg_match('/file_get_contents\s*\(\s*[\'"]php:\/\/input/', $content)) $notes[] = 'JSON body';
    if (preg_match('/move_uploaded_file/', $content)) $notes[] = 'FILE UPLOAD';
    if (preg_match('/exec\s*\(|shell_exec|system\s*\(|passthru/', $content)) $notes[] = 'SHELL';
    if (preg_match('/\beval\s*\(/', $content)) $notes[] = 'EVAL';
    if (preg_match('/header\s*\(\s*[\'"]Location:/', $content)) $notes[] = 'redirect';
    if (preg_match('/sse_publish/', $content)) $notes[] = 'SSE';

    // Tally
    if ($hasAuth) $totals['auth']++; else $totals['no_auth'][] = $name;
    if ($hasRbac) $totals['rbac']++; else $totals['no_rbac'][] = $name;
    if ($hasCsrf) $totals['csrf']++; else if (in_array('POST', $methods)) $totals['no_csrf'][] = $name;
    if ($hasIdParam) {
        $totals['id_param']++;
        // Heuristic for ownership check
        $hasOwnership = preg_match('/(WHERE.*user_id|WHERE.*owner|org_id|getUserMemberId|current_user_id)/i', $content);
        if (!$hasOwnership) $totals['id_no_ownership'][] = $name;
    }

    $authStr = $hasAuth ? '✓' : '**✗**';
    $rbacStr = $hasRbac ? '✓' : '**✗**';
    $csrfStr = $hasCsrf ? '✓' : (in_array('POST', $methods) ? '**✗**' : '—');
    $idStr = $hasIdParam ? '⚠' : '—';

    echo "| `$name` | $authStr | $rbacStr | $csrfStr | $idStr | $methodsStr | " . implode(', ', $notes) . " |\n";
}

echo "\n## Summary\n\n";
echo "- Endpoints with auth check: " . $totals['auth'] . " / " . count($files) . "\n";
echo "- Endpoints with RBAC check: " . $totals['rbac'] . " / " . count($files) . "\n";
echo "- POST endpoints with CSRF: " . $totals['csrf'] . "\n";
echo "- Endpoints accepting ID param: " . $totals['id_param'] . " (potential IDOR)\n";

echo "\n## ⚠ HIGH PRIORITY: Endpoints WITHOUT Auth Check\n\n";
foreach ($totals['no_auth'] as $f) echo "- `$f`\n";

echo "\n## ⚠ HIGH PRIORITY: Endpoints WITHOUT RBAC Check\n\n";
foreach ($totals['no_rbac'] as $f) echo "- `$f`\n";

echo "\n## ⚠ HIGH PRIORITY: POST Endpoints WITHOUT CSRF Check\n\n";
foreach ($totals['no_csrf'] as $f) echo "- `$f`\n";

echo "\n## ⚠ Endpoints with ID Param but No Visible Ownership Check (IDOR risk)\n\n";
echo "_(Heuristic — manual verification required)_\n\n";
foreach ($totals['id_no_ownership'] as $f) echo "- `$f`\n";
