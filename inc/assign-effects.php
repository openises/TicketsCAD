<?php
/**
 * GH#141 (Phase 155) — the announcements that follow a unit really being
 * dispatched to an incident ("assign created" effects), in ONE place.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * assign_create_internal() writes the row and sets the unit's status. What makes
 * a dispatch VISIBLE -- the audit row that drives the `assign.created` webhook
 * and push, the SSE event the boards listen for, the OwnTracks tracking-config
 * push, the notification rules -- was inlined, separately, in two HTTP
 * endpoints (api/incident-assign.php and api/external/v1/assignments.php). A
 * unit promoted from a reservation at the booked time has no HTTP request
 * behind it, but must be announced exactly like any other dispatch; copying the
 * block a third time is precisely how GH#8 happened (push never fired because
 * one path forgot a step). So the block lives here and the two endpoints call
 * it, behaviour-preserving: each keeps the exact audit summary, audit details
 * and SSE payload shape it always emitted.
 *
 * NEVER throws. A failed announcement must not undo or hide the assignment it
 * describes; each step is independent and logged on failure.
 */

require_once __DIR__ . '/audit.php';

/**
 * @param array $ctx
 *   'via'            ''|'external_api'  selects the historical audit/SSE shapes
 *   'token_id'       External API token id (external shape only)
 *   'role'           role label (external shape only)
 *   'summary'        override the audit summary text
 *   'scope'          the incident's scope text, for the notification rule context
 *   'owntracks'      bool (default true)  push the tightened OwnTracks config
 *   'notify'         IGNORED -- notification rules fire from assign_create_internal(), not here
 *   'actor'          audit actor override (AUDIT_ACTOR_SYSTEM for a timer promotion)
 *   'reservation_id' int  set when this dispatch is a promoted reservation
 */
function assign_emit_created_effects(int $ticketId, int $responderId, int $assignId, string $respName, array $ctx = []): void {
    $external = (($ctx['via'] ?? '') === 'external_api');
    $actor    = isset($ctx['actor']) && is_array($ctx['actor']) ? $ctx['actor'] : null;

    // ── Audit row -> assign.created webhook + push. ──
    try {
        if ($external) {
            $details = [
                'token_id'         => $ctx['token_id'] ?? null,
                'ticket_id'        => $ticketId,
                'responder_id'     => $responderId,
                'role'             => (string) ($ctx['role'] ?? ''),
                'via_external_api' => true,
            ];
            $summary = $ctx['summary'] ?? "External API assigned responder #{$responderId} to incident #{$ticketId}";
        } else {
            $details = [
                'ticket_id'    => $ticketId,
                'responder_id' => $responderId,
                'assign_id'    => $assignId,
            ];
            $summary = $ctx['summary'] ?? "Assigned '{$respName}' to incident #{$ticketId}";
        }
        if (!empty($ctx['reservation_id'])) {
            $details['reservation_id']    = (int) $ctx['reservation_id'];
            $details['from_reservation']  = true;
        }
        // Canonical webhook-eligible event: 'incident|assign|assigns' -> assign.created.
        audit_log('incident', 'assign', 'assigns', $assignId, $summary, $details, AUDIT_INFO, $actor);
    } catch (Throwable $e) {
        error_log('[assign-effects] audit failed for assign #' . $assignId . ': ' . $e->getMessage());
    }

    // ── SSE: the boards and the incident page refresh. ──
    try {
        if (!function_exists('sse_publish_for_incident') && is_file(__DIR__ . '/sse.php')) {
            require_once __DIR__ . '/sse.php';
        }
        if (function_exists('sse_publish_for_incident')) {
            if ($external) {
                $payload = ['ticket_id' => $ticketId, 'responder_id' => $responderId,
                            'assign_id' => $assignId, 'via' => 'external_api'];
            } else {
                $payload = ['ticket_id' => $ticketId, 'responder' => $respName, 'action' => 'assign'];
            }
            if (!empty($ctx['reservation_id'])) {
                $payload['from_reservation'] = true;
            }
            sse_publish_for_incident('responder:assign', $payload, $ticketId);
        }
    } catch (Throwable $e) { /* SSE is non-fatal */ }

    // ── Phase 52b: push the tightened OwnTracks config to everyone assigned
    // to this unit. Best-effort; if the helper file or table is missing we just
    // skip. OT_CONFIG_LIBRARY_ONLY stops the included endpoint file from
    // re-dispatching against this request's $_GET/$_POST. ──
    if (($ctx['owntracks'] ?? true) !== false) {
        try {
            if (!defined('OT_CONFIG_LIBRARY_ONLY')) define('OT_CONFIG_LIBRARY_ONLY', 1);
            require_once __DIR__ . '/../api/owntracks-config.php';
            if (function_exists('_ot_recompute_for_responder')) {
                _ot_recompute_for_responder($responderId, (int) ($_SESSION['user_id'] ?? 0) ?: null);
            }
        } catch (Throwable $e) { /* never block an assignment for a config push */ }
    }

    // Notification rules ('unit_assign') are NOT fired here any more: they fire from
    // assign_create_internal() itself (Phase 155, GH#144), which every dispatch path
    // goes through -- including the promotion of a reservation -- so firing here too
    // would send each dispatch twice. 'notify' / 'scope' in $ctx are accepted and ignored.
}
