<?php
/**
 * Phase 155 (GH#144) - the cheap doorway the WRITERS call to fire a
 * Notification Rules event.
 *
 * It lives apart from inc/notification-engine.php on purpose. The engine pulls
 * in the whole message broker (every channel adapter, the routing engine, the
 * push library probe) - hundreds of milliseconds of include for work an install
 * with no rules will never do. The writers (incident_create_internal,
 * assign_create_internal, ...) run on EVERY dispatch action, so what they
 * require has to be nearly free. This file is: one function, one indexed query.
 *
 *   notification_hook('unit_assign', ['ticket_id' => 42, 'responder_id' => 7]);
 *
 * Contract (the writers rely on all three):
 *   1. NEVER throws - a notification problem must not fail the dispatch.
 *   2. NEVER does outbound network work itself - deliveries are queued
 *      (inc/notification-delivery.php); at most one bounded inline attempt
 *      happens when no scheduler is running.
 *   3. With no ACTIVE rule for the event, nothing else is loaded or run.
 *
 * Why the events fire from the WRITERS and not from the endpoints (they used to
 * fire from three endpoint files): the most common dispatch path - units ticked
 * on the New Incident form - goes through incident_create_internal() ->
 * assign_create_internal() and never touched an endpoint hook, and neither did
 * the external API or a message turned into an incident. This is the same
 * disease GH#8 fixed for the audit/webhook/push fan-out.
 */

/**
 * Fire a Notification Rules event if (and only if) an active rule listens.
 *
 * @param string $event   one of inc/notification-events.php notification_event_ids()
 * @param array  $context ticket_id, responder_id / responder_name / units,
 *                        old_status / new_status (+ _label), message / message_subject
 */
function notification_hook(string $event, array $context = []): void
{
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $has = db_fetch_value(
                "SELECT 1 FROM `{$prefix}notification_rules` WHERE `active` = 1 AND `event_type` = ? LIMIT 1",
                [$event]
            );
        } catch (\Throwable $e) {
            // The table is absent on an install that never created it (the
            // engine used to do that lazily). Create it for next time; this
            // event has no rules.
            require_once __DIR__ . '/notification-engine.php';
            _notification_ensure_tables();
            return;
        }
        if (!$has) return;
        require_once __DIR__ . '/notification-engine.php';
        notification_fire($event, $context);
    } catch (\Throwable $e) {
        error_log('[notification] ' . $event . ' hook failed: ' . $e->getMessage());
    }
}
