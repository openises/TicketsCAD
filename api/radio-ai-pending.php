<?php
/**
 * NewUI v4.0 API — Radio AI pending-approval queue (Phase 85f-4).
 *
 * GET /api/radio-ai-pending.php
 *   Returns the pending_approval rows the operator needs to review.
 *   Also returns 'filtered' rows (content filter caught something)
 *   and the most recent 'error' rows from the past hour so the
 *   operator can see when the listener is failing silently.
 *
 * RBAC: action.dmr_transmit (approving an AI draft = causing a TX,
 * same trust level as keying up manually).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

$rbacOk = function_exists('rbac_can') && rbac_can('action.dmr_transmit');
if (!is_admin() && !$rbacOk) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing required permission: action.dmr_transmit']);
    exit;
}

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $pdo = db();
    // Pending + filtered: always include. Error: only past hour so the
    // queue doesn't get cluttered with old failures.
    // target_kind/target_ref (Phase 112 Phase 6) label non-DMR cards (Zello
    // weather read-outs); the fallback keeps pre-migration installs working.
    $baseWhere =
        "WHERE status IN ('pending_generation','pending_approval','filtered')
            OR (status = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR))
         ORDER BY created_at ASC
         LIMIT 50";
    try {
        $stmt = $pdo->prepare(
            "SELECT id, channel_id, target_kind, target_ref, caller_src_id,
                    caller_callsign, inbound_call_id, transcript, draft_response,
                    status, error_msg, api_tokens_in, api_tokens_out, created_at,
                    TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
               FROM `{$prefix}ai_pending_responses` {$baseWhere}"
        );
        $stmt->execute();
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            "SELECT id, channel_id, caller_src_id, caller_callsign,
                    inbound_call_id, transcript, draft_response, status,
                    error_msg, api_tokens_in, api_tokens_out, created_at,
                    TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
               FROM `{$prefix}ai_pending_responses` {$baseWhere}"
        );
        $stmt->execute();
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id']             = (int) $row['id'];
        $row['channel_id']     = (int) $row['channel_id'];
        $row['caller_src_id']  = (int) $row['caller_src_id'];
        $row['api_tokens_in']  = $row['api_tokens_in']  !== null ? (int) $row['api_tokens_in']  : null;
        $row['api_tokens_out'] = $row['api_tokens_out'] !== null ? (int) $row['api_tokens_out'] : null;
        $row['age_sec']        = (int) $row['age_sec'];
    }
    unset($row);

    echo json_encode(['ok' => true, 'rows' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed: ' . $e->getMessage()]);
}
