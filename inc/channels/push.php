<?php
/**
 * Channel: Web Push (Phase 99v-2, 2026-06-29).
 *
 * Registers `push` as a first-class routing-engine channel so a
 * `message_routes` row can target `dest_channel = 'push'` with a
 * recipient_predicate_json that resolves to a set of user IDs.
 *
 * The routing engine resolves the predicate, attaches the resulting
 * user-id list to $message['_recipient_user_ids'], then calls into
 * the registered send handler. Without recipients (legacy callers
 * that don't resolve a predicate), this channel is a no-op — push
 * fans out per user, not per channel-broadcast.
 *
 * The actual VAPID/WebPush plumbing lives in inc/push.php; this file
 * is a thin shim that turns "delivering to N users" into "queueing
 * pushes to their subscriptions."
 *
 * Settings reused: push_enabled, push_vapid_public_key,
 * push_vapid_private_key, push_vapid_subject (see inc/push.php).
 */

require_once __DIR__ . '/../push.php';

broker_register('push', [
    'name'    => 'Web Push (per-user)',
    'send'    => '_push_channel_send',
    'receive' => null,    // push is outbound-only
    'status'  => '_push_channel_status',
]);

/**
 * Routing-engine entry point. Called by router_forward when a route's
 * dest_channel = 'push'. Expects $message['_recipient_user_ids'] to
 * carry the resolved user-id list; without recipients we no-op
 * (channel broadcast doesn't apply to push).
 *
 * @param array $message Forwarded message. Must include:
 *   - _recipient_user_ids : int[]  resolved from predicate
 *   - body                : string for the notification body
 *   - subject (optional)  : string notification title (otherwise default)
 *   - _event_type (optional) : canonical event name for grouping
 *
 * @return array {success: bool, error?: string, delivered?: int, gone?: int}
 */
function _push_channel_send(array $message): array
{
    $recipients = $message['_recipient_user_ids'] ?? null;
    if (!is_array($recipients) || empty($recipients)) {
        return [
            'success' => false,
            'error'   => 'push channel requires a recipient predicate; no user IDs resolved',
        ];
    }
    $recipients = array_values(array_unique(array_map('intval', $recipients)));

    // GH #8 (self-echo suppression, a beta tester 2026-07-16) — never push a user a
    // notification about an action THEY just performed. When a mobile unit changes
    // its OWN status, the acting user is also an assigned-to-incident recipient, so
    // without this the device buzzed for its own change ("notification overload").
    // The originating user is the audit actor, carried on the payload by
    // inc/audit.php (actor_id); tolerate it nested under _event_payload too. A
    // dispatcher changing a unit's status is a DIFFERENT actor than the unit's
    // crew, so they still get notified — only the initiator is filtered.
    $actorId = (int) ($message['actor_id'] ?? ($message['_event_payload']['actor_id'] ?? 0));
    if ($actorId > 0) {
        $recipients = array_values(array_filter($recipients, function ($u) use ($actorId) {
            return (int) $u !== $actorId;
        }));
        if (empty($recipients)) {
            return [
                'success'               => true,
                'delivered'             => 0,
                'recipients_resolved'   => 0,
                'subscriptions_matched' => 0,
                'note'                  => 'only recipient was the originating actor (self-echo suppressed)',
            ];
        }
    }

    if (! _push_enabled()) {
        return ['success' => false, 'error' => 'push disabled in settings'];
    }
    $vapid = _push_vapid_config();
    if (!$vapid) {
        return ['success' => false, 'error' => 'push VAPID keys not configured'];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $place  = implode(',', array_fill(0, count($recipients), '?'));
    try {
        $subs = db_fetch_all(
            "SELECT id, user_id, endpoint, p256dh, auth
               FROM `{$prefix}push_subscriptions`
              WHERE channel = 'web'
                AND user_id IN ($place)
                AND (last_error IS NULL OR last_error NOT LIKE 'gone:%')",
            $recipients
        );
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'push_subscriptions query failed: ' . $e->getMessage()];
    }
    if (empty($subs)) {
        return [
            'success'             => true,
            'delivered'           => 0,
            'recipients_resolved' => count($recipients),
            'subscriptions_matched' => 0,
            'error'               => 'no active subscriptions for the resolved recipients',
        ];
    }

    // Build the notification body. We honour _event_type +
    // payload-shaped fields so the existing _push_build_notification
    // logic can be reused, but route-driven invocations may also
    // carry an admin-authored body/subject directly.
    $eventType = (string) ($message['_event_type'] ?? 'route.push');
    $payload   = $message['_event_payload'] ?? $message;
    if (!is_array($payload)) $payload = ['body' => (string) $payload];

    if (!empty($message['subject']) || !empty($message['body'])) {
        $notif = [
            'title'     => (string) ($message['subject'] ?? 'TicketsCAD'),
            'body'      => (string) ($message['body'] ?? ''),
            'eventType' => $eventType,
        ];
        if (isset($payload['ticket_id'])) $notif['url'] = '/index.php#ticket-' . (int) $payload['ticket_id'];
    } else {
        $notif = _push_build_notification($eventType, $payload);
    }
    $bodyJson = json_encode($notif);

    // Reuse the WebPush instance pattern from push_fire.
    try {
        $webPush = new Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject'    => $vapid['subject'],
                'publicKey'  => $vapid['publicKey'],
                'privateKey' => $vapid['privateKey'],
            ],
        ]);
        $webPush->setDefaultOptions(['TTL' => 30, 'urgency' => 'high']);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'WebPush init failed: ' . $e->getMessage()];
    }

    $queued = 0;
    foreach ($subs as $sub) {
        try {
            $subscription = Minishlink\WebPush\Subscription::create([
                'endpoint'        => $sub['endpoint'],
                'publicKey'       => $sub['p256dh'],
                'authToken'       => $sub['auth'],
                'contentEncoding' => 'aes128gcm',
            ]);
            $webPush->queueNotification($subscription, $bodyJson);
            $queued++;
        } catch (Throwable $e) {
            error_log("[push_channel_send] queue failed for sub {$sub['id']}: " . $e->getMessage());
        }
    }

    $delivered = 0; $gone = 0; $failed = 0;
    try {
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();
            if ($report->isSuccess()) {
                _push_stamp_success($endpoint);
                $delivered++;
            } else {
                $resp = $report->getResponse();
                $statusCode = $resp ? $resp->getStatusCode() : 0;
                $reason = $report->getReason();
                if ($statusCode === 404 || $statusCode === 410) {
                    _push_stamp_error($endpoint, "gone:{$statusCode}");
                    $gone++;
                } else {
                    _push_stamp_error($endpoint, "fail:{$statusCode}:" . substr((string) $reason, 0, 200));
                    $failed++;
                }
            }
        }
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'WebPush flush failed: ' . $e->getMessage()];
    }

    return [
        'success'   => true,
        'queued'    => $queued,
        'delivered' => $delivered,
        'gone'      => $gone,
        'failed'    => $failed,
        'recipients_resolved' => count($recipients),
        'subscriptions_matched' => count($subs),
    ];
}

function _push_channel_status(): array
{
    $configured = _push_vapid_config() ? true : false;
    return [
        'enabled'    => _push_enabled() && $configured,
        'configured' => $configured,
    ];
}
