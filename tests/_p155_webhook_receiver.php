<?php
/**
 * Phase 155 -- router script for a throwaway local webhook RECEIVER, used by
 * tests/test_gh147_webhook_delivery_once.php.
 *
 * Started with `php -S 127.0.0.1:<port> tests/_p155_webhook_receiver.php`. It
 * appends one JSON line per POST to the file named by the P155_RECV_FILE
 * environment variable and answers 200, so a test can count how many
 * deliveries each real status change really produced -- which an unreachable
 * endpoint cannot show (a failed delivery is retried and logs again, so a
 * failure count is not an event count).
 *
 * File name starts with `_` so tools/test_all.php does not run it as a test.
 */
$file = getenv('P155_RECV_FILE');
if ($file !== false && $file !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $body = file_get_contents('php://input');
    $decoded = json_decode((string) $body, true);
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $uid = '';
    foreach ($headers as $k => $v) {
        if (strcasecmp($k, 'X-Webhook-Delivery') === 0) { $uid = (string) $v; }
    }
    @file_put_contents($file, json_encode([
        'event_type' => is_array($decoded) ? ($decoded['event_type'] ?? null) : null,
        'uid'        => $uid,
        'data'       => is_array($decoded) ? ($decoded['data'] ?? null) : null,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
