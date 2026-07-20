<?php
/**
 * Phase 99a #13 (2026-06-28) — APRS-IS passcode helper endpoint.
 *
 * GET /api/aprs-passcode.php?callsign=N0NKI
 * Response: { callsign: "N0NKI", passcode: 15001 }
 *
 * Computes the passcode server-side. The algorithm is public
 * (every APRS-IS client uses the same one) so there's nothing
 * to protect — this just centralizes the calculation so the
 * Settings UI doesn't have to reimplement it in JS.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/aprs.php';

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$cs = trim((string) ($_GET['callsign'] ?? ''));
if ($cs === '') {
    http_response_code(400);
    echo json_encode(['error' => 'callsign required']);
    exit;
}

$pc = aprs_passcode($cs);
if ($pc < 0) {
    echo json_encode([
        'callsign' => $cs,
        'passcode' => null,
        'error'    => 'Invalid callsign shape (expected 3-10 alphanumeric chars before any SSID dash)',
    ]);
    exit;
}

echo json_encode([
    'callsign' => $cs,
    'passcode' => $pc,
]);
