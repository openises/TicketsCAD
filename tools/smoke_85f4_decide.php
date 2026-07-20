<?php
/**
 * Phase 85f-4 / 85f-6 — smoke test for the operator-approve path
 * against a SMOKE-marked pending row using dry_run = true so nothing
 * goes on-air.
 *
 * Run on training-ticketscad:
 *   sudo php /tmp/smoke_85f4_decide.php
 */
require '/var/www/newui/config.php';

$pdo = new PDO("mysql:host={$db_host};dbname={$db_name}", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$row = $pdo->query(
    "SELECT * FROM ai_pending_responses
     WHERE caller_callsign = 'SMOKE' AND status = 'pending_approval'
     ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$row) { fwrite(STDERR, "no SMOKE pending row\n"); exit(1); }
echo "approving row #{$row['id']}: \"{$row['draft_response']}\"\n";

$ch = $pdo->prepare(
    "SELECT bridge_host, bridge_port, bridge_token, talkgroup
     FROM dmr_channels WHERE id = ?"
);
$ch->execute([(int) $row['channel_id']]);
$channel = $ch->fetch(PDO::FETCH_ASSOC);

$payload = json_encode([
    'text'      => $row['draft_response'],
    'talkgroup' => (int) $channel['talkgroup'],
    'dry_run'   => true,
]);

$curl = curl_init("http://{$channel['bridge_host']}:{$channel['bridge_port']}/tx/text");
curl_setopt_array($curl, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $channel['bridge_token'],
    ],
    CURLOPT_POSTFIELDS     => $payload,
]);
$resp = curl_exec($curl);
$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
echo "bridge HTTP {$code}: {$resp}\n";

$bridge = json_decode((string) $resp, true);
if ($code !== 200 || !is_array($bridge) || empty($bridge['ok'])) {
    fwrite(STDERR, "bridge call failed\n");
    exit(2);
}

$pdo->prepare(
    "UPDATE ai_pending_responses
        SET final_response = ?, status = 'sent', decided_at = NOW(), decided_by = ?
      WHERE id = ?"
)->execute([$row['draft_response'], 1, $row['id']]);

// Prepared statement (not string concat) just to keep Sonar happy and
// not normalise the pattern in adjacent code. The (int) cast above
// would already make this injection-safe.
$check = $pdo->prepare(
    "SELECT id, status, decided_at, final_response
     FROM ai_pending_responses WHERE id = ?"
);
$check->execute([(int) $row['id']]);
$check = $check->fetch(PDO::FETCH_ASSOC);
echo "row after approve:\n";
print_r($check);

echo "smoke OK — bridge accepted dry_run TX and DB transitioned to 'sent'\n";
