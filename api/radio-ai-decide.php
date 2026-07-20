<?php
/**
 * NewUI v4.0 API — Radio AI operator decision (Phase 85f-4).
 *
 * POST /api/radio-ai-decide.php
 *   { "id": 123,
 *     "action": "approve" | "reject" | "edit",
 *     "edited_text": "..."         (required for edit; optional for approve)
 *     "dry_run": false             (optional; passed through to bridge)
 *   }
 *
 * approve:
 *   - Reads the row's draft_response (or the edited_text override).
 *   - POSTs /tx/text to the channel's bridge with that text.
 *   - Marks row as 'sent' on success, 'error' on failure.
 *   - Returns the bridge's response so the widget can show packet count.
 *   - NOTE: this does NOT do a clear-channel wait. Operator picks the
 *     timing — they're watching the same widget that shows live RX.
 *     Phase 85f-8 (ID scheduler) will add automatic clear-channel +
 *     callsign sign-off logic for the always-on listener path.
 *
 * reject:
 *   - Marks row as 'discarded'. No TX.
 *
 * edit:
 *   - Updates draft_response with edited_text. Status stays
 *     'pending_approval'. Operator clicks approve next.
 *
 * RBAC: action.dmr_transmit. Same gate as manual PTT.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/functions.php';
ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$rbacOk = function_exists('rbac_can') && rbac_can('action.dmr_transmit');
if (!is_admin() && !$rbacOk) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing required permission: action.dmr_transmit']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON body required']);
    exit;
}

// CSRF: same pattern as chat.php / messaging.php — accept token in
// body csrf_token field or X-CSRF-Token header.
$csrf = $body['csrf_token']
     ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_verify((string) $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$id          = (int) ($body['id'] ?? 0);
$action      = (string) ($body['action'] ?? '');
$editedText  = isset($body['edited_text']) ? trim((string) $body['edited_text']) : null;
$dryRun      = !empty($body['dry_run']);

if ($id <= 0 || !in_array($action, ['approve', 'reject', 'edit'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'id (int) and action (approve|reject|edit) required']);
    exit;
}
if ($action === 'edit' && ($editedText === null || $editedText === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'edit requires non-empty edited_text']);
    exit;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    $pdo = db();

    // Lock the row to avoid two operators acting on it at once.
    //
    // Security note: this fetches by primary key WITHOUT additional
    // tenant/org filtering. That matches the rest of the NewUI dispatch
    // surface today — there's no per-org scoping on most endpoints yet
    // (see project_newui_backlog "multi-tenant DB"). action.dmr_transmit
    // is the only authorization gate; an operator with that permission
    // can theoretically approve a row whose channel_id maps to a
    // bridge they don't intend to TX through. When multi-org lands,
    // add `AND channel_id IN (SELECT id FROM dmr_channels WHERE org_id = ?)`
    // here and to api/radio-ai-pending.php as a parallel change.
    $pdo->beginTransaction();
    // target_kind/target_ref (Phase 112 Phase 6) route non-DMR approvals; the
    // fallback SELECT keeps pre-migration installs working (DMR-only).
    try {
        $stmt = $pdo->prepare(
            "SELECT id, channel_id, target_kind, target_ref, caller_callsign,
                    caller_src_id, draft_response, status
               FROM `{$prefix}ai_pending_responses`
              WHERE id = ?
              FOR UPDATE"
        );
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            "SELECT id, channel_id, caller_callsign, caller_src_id,
                    draft_response, status
               FROM `{$prefix}ai_pending_responses`
              WHERE id = ?
              FOR UPDATE"
        );
        $stmt->execute([$id]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'pending row not found']);
        exit;
    }

    // edit is the only action that's valid for non-pending_approval rows
    if ($action !== 'edit' && !in_array($row['status'], ['pending_approval','filtered'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'error' => 'row is in status "' . $row['status'] . '" — cannot ' . $action
        ]);
        exit;
    }

    if ($action === 'edit') {
        $upd = $pdo->prepare(
            "UPDATE `{$prefix}ai_pending_responses`
                SET draft_response = ?, status = 'pending_approval'
              WHERE id = ?"
        );
        $upd->execute([$editedText, $id]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'action' => 'edit', 'id' => $id]);
        exit;
    }

    if ($action === 'reject') {
        $upd = $pdo->prepare(
            "UPDATE `{$prefix}ai_pending_responses`
                SET status = 'discarded', decided_at = NOW(), decided_by = ?
              WHERE id = ?"
        );
        $upd->execute([$userId, $id]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'action' => 'reject', 'id' => $id]);
        exit;
    }

    // action == 'approve'
    $text = $editedText !== null && $editedText !== '' ? $editedText : (string) $row['draft_response'];
    if (trim($text) === '') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'nothing to transmit (empty draft and no edited_text)']);
        exit;
    }

    // ── Zello card (Phase 112 Phase 6): approve = queue a zello_outbox
    //    kind='tts' row; the Zello proxy synthesizes (Piper) and keys the
    //    audio onto the channel. No bridge POST — nothing here touches RF.
    if (($row['target_kind'] ?? 'dmr') === 'zello') {
        $zchannel = trim((string) ($row['target_ref'] ?? ''));
        if ($dryRun) {
            // Dry run: decide nothing, queue nothing — report what WOULD go.
            $pdo->rollBack();
            echo json_encode([
                'ok' => true, 'action' => 'approve', 'id' => $id, 'dry_run' => true,
                'zello' => ['channel' => $zchannel !== '' ? $zchannel : '(default dispatch channel)',
                            'text' => $text],
            ]);
            exit;
        }
        $upd = $pdo->prepare(
            "UPDATE `{$prefix}ai_pending_responses`
                SET final_response = ?, status = 'sent',
                    decided_at = NOW(), decided_by = ?
              WHERE id = ?"
        );
        $upd->execute([$text, $userId, $id]);
        $ob = $pdo->prepare(
            "INSERT INTO `{$prefix}zello_outbox`
                (`kind`, `channel`, `recipient`, `body`, `status`, `queued_by`, `source`)
             VALUES ('tts', ?, '', ?, 'queued', ?, 'weather-approve')"
        );
        $ob->execute([mb_substr($zchannel, 0, 100), mb_substr($text, 0, 1000), $userId ?: null]);
        $outboxId = (int) $pdo->lastInsertId();
        $pdo->commit();
        echo json_encode([
            'ok' => true, 'action' => 'approve', 'id' => $id, 'dry_run' => false,
            'zello' => ['outbox_id' => $outboxId,
                        'channel' => $zchannel !== '' ? $zchannel : '(default dispatch channel)'],
        ]);
        exit;
    }

    // Look up the channel's bridge so we know where to POST.
    $chStmt = $pdo->prepare(
        "SELECT id, bridge_host, bridge_port, bridge_token, talkgroup
           FROM `{$prefix}dmr_channels`
          WHERE id = ? AND enabled = 1"
    );
    $chStmt->execute([(int) $row['channel_id']]);
    $channel = $chStmt->fetch(PDO::FETCH_ASSOC);
    if (!$channel) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'channel not found or disabled']);
        exit;
    }

    // Mark sent BEFORE the TX so we don't double-fire if the operator
    // clicks twice while the bridge is processing. Update with tx
    // metadata after.
    $upd = $pdo->prepare(
        "UPDATE `{$prefix}ai_pending_responses`
            SET final_response = ?, status = 'sent',
                decided_at = NOW(), decided_by = ?
          WHERE id = ?"
    );
    $upd->execute([$text, $userId, $id]);
    $pdo->commit();

    // Fire the TX. cURL out-of-process so we don't have to depend on
    // a PHP WebSocket client; the bridge's HTTP control surface is the
    // operator-approval path's official entry point.
    $url     = "http://{$channel['bridge_host']}:{$channel['bridge_port']}/tx/text";
    $payload = json_encode([
        'text'      => $text,
        'talkgroup' => (int) $channel['talkgroup'],
        'dry_run'   => $dryRun,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channel['bridge_token'],
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $respBody = curl_exec($ch);
    $respCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $bridgeResp = json_decode((string) $respBody, true);
    if ($respCode !== 200 || !is_array($bridgeResp) || empty($bridgeResp['ok'])) {
        // Bridge reported a problem. Flip the row to 'error' so the
        // operator sees what happened. We DO keep final_response so
        // they can retry manually if appropriate.
        $errMsg = $curlErr !== '' ? $curlErr
                : (is_array($bridgeResp) ? json_encode($bridgeResp) : substr((string) $respBody, 0, 400));
        $pdo->prepare(
            "UPDATE `{$prefix}ai_pending_responses`
                SET status = 'error', error_msg = ?
              WHERE id = ?"
        )->execute([substr("bridge HTTP {$respCode}: {$errMsg}", 0, 500), $id]);
        http_response_code(502);
        echo json_encode([
            'error'       => 'bridge TX failed',
            'bridge_code' => $respCode,
            'bridge_body' => $bridgeResp ?: $respBody,
        ]);
        exit;
    }

    echo json_encode([
        'ok'        => true,
        'action'    => 'approve',
        'id'        => $id,
        'dry_run'   => $dryRun,
        'bridge'    => $bridgeResp,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'decide failed: ' . $e->getMessage()]);
}
