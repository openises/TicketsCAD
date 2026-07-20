<?php
/**
 * Phase 99h (2026-06-29) — APRS watchlist API.
 *
 * Single system-wide list of "interesting" APRS callsigns
 * curated by admins, visible (as a toggleable layer) to all
 * authenticated viewers.
 *
 *   GET                              — list all watched entries
 *   POST  {action:'add', callsign, note?}    — add (idempotent)
 *   POST  {action:'remove', callsign}        — remove
 *
 * Read: any logged-in user (the map layer is visible to all).
 * Write: action.manage_aprs_watchlist OR is_admin().
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function _wl_write_gate() {
    if (!rbac_can('action.manage_aprs_watchlist') && !is_admin()) {
        http_response_code(403);
        echo json_encode(['error' => 'action.manage_aprs_watchlist required']);
        exit;
    }
}

function _wl_csrf_gate(array $body) {
    if (!function_exists('csrf_check')) return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? ($body['csrf_token'] ?? ''));
    if (!csrf_check($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }
}

if ($method === 'GET') {
    try {
        $rows = db_fetch_all(
            "SELECT id, callsign, note, added_by_name, added_at
               FROM `{$prefix}aprs_watchlist`
              ORDER BY callsign ASC"
        );
        echo json_encode([
            'watchlist' => $rows,
            'count'     => count($rows),
            'can_write' => rbac_can('action.manage_aprs_watchlist') || is_admin(),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'List failed: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    _wl_csrf_gate($body);
    _wl_write_gate();

    $action   = (string) ($body['action'] ?? '');
    $callsign = strtoupper(trim((string) ($body['callsign'] ?? '')));
    if ($callsign === '' || !preg_match('/^[A-Z0-9-]{3,16}$/', $callsign)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid callsign — must be 3-16 alphanumeric chars + optional SSID']);
        exit;
    }

    $userId   = (int) ($_SESSION['user_id'] ?? 0);
    $userName = (string) ($_SESSION['user'] ?? '');

    try {
        if ($action === 'add') {
            $note = trim((string) ($body['note'] ?? ''));
            db_query(
                "INSERT INTO `{$prefix}aprs_watchlist` (callsign, note, added_by, added_by_name)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     note = COALESCE(VALUES(note), note),
                     added_by = VALUES(added_by),
                     added_by_name = VALUES(added_by_name),
                     added_at = NOW()",
                [$callsign, $note !== '' ? $note : null, $userId ?: null, $userName ?: null]
            );
            echo json_encode(['ok' => true, 'callsign' => $callsign, 'action' => 'added']);
        } elseif ($action === 'remove') {
            db_query(
                "DELETE FROM `{$prefix}aprs_watchlist` WHERE callsign = ?",
                [$callsign]
            );
            echo json_encode(['ok' => true, 'callsign' => $callsign, 'action' => 'removed']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'action must be add or remove']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Write failed: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
