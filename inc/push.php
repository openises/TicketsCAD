<?php
/**
 * Phase 96 — Web Push helper.
 *
 * Mirrors the inc/webhooks.php audit-driven fan-out pattern.
 * audit_log() in inc/audit.php calls push_fire($eventType, $payload)
 * after webhook_fire(); push_fire iterates push_subscriptions and
 * delivers an encrypted push to each subscriber via the minishlink/
 * web-push library.
 *
 * Failure handling:
 *   - 404/410 Gone from the push service → subscription is dead, mark
 *     with last_error and (in a future cleanup pass) delete
 *   - Any other failure → stamp last_error for admin visibility but
 *     keep the row (transient)
 *
 * Settings:
 *   push_enabled            — '1' to enable; '0' = no-op (default)
 *   push_vapid_public_key   — base64url-encoded P-256 public key
 *   push_vapid_private_key  — base64url-encoded P-256 private scalar
 *   push_vapid_subject      — 'mailto:admin@yoursite' per RFC 8292
 *
 * Generate keys via tools/generate_vapid_keys.php.
 */

declare(strict_types=1);

// Vendor autoload is required for the minishlink/web-push library
// (Minishlink\WebPush\{WebPush,Subscription}). It's OPTIONAL at file-load
// time so:
//   * fresh installs before `composer install` runs (issue #30 —
//     a beta tester 2026-07-03: run_migrations.php failed with
//     "Failed opening required '/var/www/newui/inc/../vendor/autoload.php'"
//     because run_route_subaddress.php require_onces broker.php which
//     in turn require_onces channels/push.php → inc/push.php)
//   * CLI-only environments (shared hosting, cron) that never call
//     push_fire() shouldn't crash on load
//
// _push_enabled() checks the runtime prerequisite (class_exists +
// settings gate) so callers that DO need push get the same graceful
// bail as the "settings say push is off" path.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    error_log('[push] vendor/autoload.php missing — push delivery disabled until `composer install` runs');
}

// These `use` aliases are safe even when the classes aren't loaded —
// they're just compile-time namespace shortcuts. Instantiation happens
// only inside push_fire(), which is gated by _push_enabled().
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Fire a push to every push_subscriptions row that should receive
 * this event. Mirrors webhook_fire's signature so audit.php can call
 * both in parallel without conditional logic.
 *
 * @param string $eventType  canonical dotted name (incident.created, etc.)
 * @param array  $payload    same shape webhook_fire receives
 * @return int  count of subscriptions a push was attempted for
 */
function push_fire(string $eventType, array $payload = []): int {
    // Settings gate — bail fast if push is disabled or VAPID not set.
    if (! _push_enabled()) return 0;
    if (! _push_vapid_config()) return 0;

    // Phase 99v-3-cleanup (a beta tester/Eric beta 2026-06-30): push delivery
    // is now driven entirely by the routing engine. Authoritative
    // routes:
    //   - "Phase 99v seed: incident events → push, recipients assigned
    //      to incident" (mobile / field responder audience)
    //   - "Phase 99v seed: incident events → push, recipients with
    //      dispatch screen access" (desktop dispatcher audience —
    //      preserves the legacy direct-broadcast firehose, now driven
    //      by role rather than by which device installed the PWA)
    //
    // Both seed routes are admin-editable from Settings → Message
    // Routing. Admins can add additional routes for any audience
    // expressible via the 6 recipient predicates + any_of/all_of
    // composition.
    //
    // Removed in this commit:
    //   - The legacy direct-broadcast loop that fired to every
    //     push_subscriptions row regardless of audience
    //   - The Phase 99t per-subscription filters_json gate, which was
    //     a band-aid for the "field responders shouldn't get the
    //     firehose" complaint — now solved cleanly by the routing
    //     engine's recipient predicates
    //
    // The push_subscriptions.filters_json column is kept for future
    // use (per-user channel-mute matrix) but no longer consulted on
    // push_fire.
    // push_fire is driven entirely by the routing engine (Phase 99v-3), so it
    // needs the FULL routing stack: router_evaluate() from inc/router.php AND the
    // 'push' destination channel registered with the broker (done by
    // inc/channels/push.php, which inc/broker.php's channel auto-loader pulls in).
    // It historically assumed some other include (broker, etc.) had already set
    // that up — but the incident-create path loads NONE of it, so incident pushes
    // silently died: first "router_evaluate not loaded", then (once that's loaded)
    // the 'push' dest was "not registered". EITHER way the push was skipped with
    // only an error_log, while unit/other paths that happened to pull the broker
    // in worked — THAT is the "unit-update pushes arrive but incident pushes don't"
    // bug (GH #8, confirmed on training 2026-07-14). Loading inc/broker.php makes
    // push_fire self-sufficient on EVERY caller path: it auto-loads every channel
    // handler (registering 'push') AND require_once's inc/router.php. Idempotent —
    // a no-op in the normal broker context.
    if (is_file(__DIR__ . '/broker.php')) {
        require_once __DIR__ . '/broker.php';
    }
    if (! function_exists('router_evaluate')) {
        error_log('[push_fire] router_evaluate not loaded — push delivery skipped');
        return 0;
    }
    $routedMessage = array_merge($payload, [
        '_event_type'    => $eventType,
        '_event_payload' => $payload,
        'body'           => $payload['summary'] ?? $eventType,
    ]);
    try {
        $results = router_evaluate('audit_event', 'outbound', $routedMessage);
    } catch (Throwable $e) {
        error_log('[push_fire] router_evaluate failed: ' . $e->getMessage());
        return 0;
    }
    $forwarded = 0;
    foreach ($results as $r) {
        if (($r['status'] ?? '') === 'forwarded') $forwarded++;
    }
    return $forwarded;
}

/**
 * Send a single push to one specific subscription. Used by the admin
 * "send test push" button.
 */
function push_send_test(int $subscriptionId, string $title, string $body): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $vapid = _push_vapid_config();
    if (!$vapid) return ['ok' => false, 'error' => 'vapid_not_configured'];

    $sub = db_fetch_one(
        "SELECT * FROM `{$prefix}push_subscriptions` WHERE id = ?",
        [$subscriptionId]
    );
    if (!$sub) return ['ok' => false, 'error' => 'subscription_not_found'];

    try {
        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => $vapid['subject'],
                'publicKey'  => $vapid['publicKey'],
                'privateKey' => $vapid['privateKey'],
            ],
        ]);
        $subscription = Subscription::create([
            'endpoint'        => $sub['endpoint'],
            'publicKey'       => $sub['p256dh'],
            'authToken'       => $sub['auth'],
            'contentEncoding' => 'aes128gcm',
        ]);
        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => '/',
            'tag'   => 'test',
        ]);
        $report = $webPush->sendOneNotification($subscription, $payload);
        if ($report->isSuccess()) {
            return ['ok' => true];
        }
        $resp = $report->getResponse();
        $code = $resp ? $resp->getStatusCode() : 0;
        return ['ok' => false, 'error' => "HTTP {$code}", 'reason' => $report->getReason()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'exception', 'reason' => $e->getMessage()];
    }
}

// ── Internal helpers ─────────────────────────────────────────────

// Phase 99v-3-cleanup (2026-06-30) — removed:
//   _push_subscription_matches()  : per-subscription filters_json gate
//   _push_user_responder_ids()    : user→responder lookup for filter
//   _push_user_assigned_to_ticket(): assignment check for filter
//
// Recipient targeting is now expressed as routing-engine predicates
// (see inc/router_recipients.php). The "assigned to ticket" use case
// is handled by the `assigned_to_incident` predicate, and the broader
// "user owns these responders" lookup isn't needed by push_fire any
// more — channel adapters target users directly via the resolver's
// user-id list. The push_subscriptions.filters_json column is kept
// for future use as a per-user channel-mute matrix.

function _push_enabled(): bool {
    // Runtime prerequisite: the minishlink/web-push library must be
    // reachable (i.e., composer install has run). If not, disable push
    // silently regardless of the settings flag — no crash.
    if (!class_exists('Minishlink\\WebPush\\WebPush')) return false;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT value FROM `{$prefix}settings` WHERE name = 'push_enabled' LIMIT 1"
        );
        return (string) $v === '1';
    } catch (Exception $e) {
        return false;
    }
}

function _push_vapid_config(): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT name, value FROM `{$prefix}settings`
             WHERE name IN ('push_vapid_public_key', 'push_vapid_private_key', 'push_vapid_subject')"
        );
    } catch (Exception $e) { return null; }
    $cfg = [];
    foreach ($rows as $r) $cfg[$r['name']] = (string) $r['value'];
    if (empty($cfg['push_vapid_public_key'])
        || empty($cfg['push_vapid_private_key'])
        || empty($cfg['push_vapid_subject'])) {
        return null;
    }
    return [
        'publicKey'  => $cfg['push_vapid_public_key'],
        'privateKey' => $cfg['push_vapid_private_key'],
        'subject'    => $cfg['push_vapid_subject'],
    ];
}

/**
 * Render a Web Push notification body from an audit-driven event.
 * Returns ['title', 'body', 'url', 'tag', 'data'] — the service worker
 * uses these to show a system notification.
 */
function _push_build_notification(string $eventType, array $payload): array {
    $data = (array) ($payload['data'] ?? []);
    $targetId = (int) ($payload['target_id'] ?? 0);
    $summary  = (string) ($payload['summary'] ?? '');

    // Default routing: most events open the relevant entity's detail page
    $urlByEvent = [
        'incident.created'  => '/incident-detail.php?id=' . $targetId,
        'incident.updated'  => '/incident-detail.php?id=' . $targetId,
        'incident.closed'   => '/incident-detail.php?id=' . $targetId,
        'incident.note_added' => '/incident-detail.php?id=' . $targetId,
        'assign.created'    => '/incident-detail.php?id=' . ((int) ($data['ticket_id'] ?? 0)),
        'responder.status_changed' => '/unit-detail.php?id=' . $targetId,
        'member.location_updated'  => '/index.php',
    ];
    $url = $urlByEvent[$eventType] ?? '/index.php';

    // Friendly title per event
    $titleByEvent = [
        'incident.created'   => 'New incident',
        'incident.updated'   => 'Incident updated',
        'incident.closed'    => 'Incident closed',
        'incident.note_added'=> 'Incident note',
        'assign.created'     => 'Unit assigned',
        'assign.removed'     => 'Unit released',
        'responder.created'  => 'New responder',
        'responder.updated'  => 'Responder updated',
        'responder.status_changed' => 'Unit status',
        'member.created'     => 'New member',
        'member.updated'     => 'Member updated',
    ];
    $title = $titleByEvent[$eventType] ?? $eventType;

    // Body: prefer the audit summary the helper already built (it has
    // the meaningful context like "Created incident #42: Structure fire
    // at 123 Main")
    $body = $summary !== '' ? $summary : $eventType;

    return [
        'title' => $title,
        'body'  => substr($body, 0, 240),
        'url'   => $url,
        'tag'   => $eventType,         // newer push with same tag replaces older
        'data'  => ['eventType' => $eventType, 'target_id' => $targetId],
    ];
}

function _push_stamp_success(string $endpoint): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}push_subscriptions`
             SET last_used_at = NOW(), last_error = NULL
             WHERE endpoint = ?",
            [$endpoint]
        );
    } catch (Exception $e) { /* non-fatal */ }
}

function _push_stamp_error(string $endpoint, string $error): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}push_subscriptions`
             SET last_error = ? WHERE endpoint = ?",
            [substr($error, 0, 255), $endpoint]
        );
    } catch (Exception $e) { /* non-fatal */ }
}
