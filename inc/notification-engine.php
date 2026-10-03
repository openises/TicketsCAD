<?php
/**
 * NewUI v4.0 - Notification Rules engine.
 *
 * "When THIS happens in the CAD, tell THESE people, over THIS channel, with
 * THIS message." Rules live in `notification_rules` and are authored in
 * Settings -> Notification Rules (api/notification-rules.php).
 *
 * HISTORY - WHY THIS FILE IS SHAPED THE WAY IT IS (Phase 155, GH#144)
 * -------------------------------------------------------------------
 * For months a public statement said this engine was "fully built" and only the
 * admin page was missing. Reading it end to end for GH#144 found seventeen
 * defects between "a rule row exists" and "the right person is told, with the
 * right text, without stalling the dispatcher". The ones that shaped this file:
 *
 *  - It was fired from three ENDPOINT files, not from the writers. So the most
 *    common dispatch path - units ticked on the New Incident form - never
 *    fired `unit_assign`, nor did the external API or a message turned into an
 *    incident. Events now fire from the writers (inc/incident-write.php,
 *    inc/assignment-write.php, inc/responder-write.php, api/messaging.php) via
 *    notification_hook(); the endpoint hooks are gone, and a test asserts
 *    exactly-once.
 *  - The context was whatever each endpoint happened to have in hand. So
 *    {street} {city} {incident_type} rendered EMPTY on dispatch, severity and
 *    type filters were skipped whenever the context lacked the value
 *    (`if isset(...)`), and `incident_close` hard-coded `severity => 0` so a
 *    severity-filtered close rule could never match. notification_build_context()
 *    now loads the ticket itself; a filter on an event with no value for it
 *    does not match (fail closed).
 *  - `{incident_type}` was ALWAYS blank, even on incident_create: the caller
 *    read `in_types.name`, and the column is `type`.
 *  - A user recipient on an SMS rule was handed the user's EMAIL as the phone
 *    number; chat got the email instead of a user id; a typed phone number made
 *    of digits was mistaken for a user id and silently dropped. Addresses are
 *    now resolved per channel kind, and a recipient with no usable address is
 *    logged as skipped with the reason - never silently dropped.
 *  - Email-list recipients never resolved (see inc/email-lists.php).
 *  - Delivery was synchronous INSIDE the dispatcher's request, one SMTP session
 *    per recipient - exactly the stall inc/notify-fanout.php removed for
 *    webhooks and push. Deliveries are now written to the notification queue
 *    (inc/notification-delivery.php) and sent by the scheduled sweep, with a
 *    bounded inline attempt when no scheduler is running.
 *  - Mail went out as text/html with unescaped values and "\n" newlines, so a
 *    multi-line template collapsed into one run-on line and an inbound
 *    message's text was injected as HTML.
 *  - The security-label gate that Message Routing consults was never consulted
 *    here: a rule could email the address of a Restricted incident.
 *
 * SHAPE
 * -----
 *   notification_hook($event, $ctx)   what the writers call. Cheap: one indexed
 *                                     query, and it never loads this file's
 *                                     weight when no rule listens. Never throws.
 *   notification_fire($event, $ctx)   match -> plan -> persist -> dispatch
 *   notification_check($event, $ctx)  compatibility wrapper: fire, then deliver
 *                                     SYNCHRONOUSLY and return what was attempted
 *   notification_plan_rule()          the side-effect-free half (also what the
 *                                     panel's Preview uses: it sends nothing)
 *   notification_build_context()      the ticket/unit/actor facts for templates
 *   notification_render()             {placeholder} substitution, text or html
 *
 * Delivery (queue, replay, retention) is in inc/notification-delivery.php.
 */

// Load broker if not already loaded
if (!function_exists('broker_send')) {
    require_once __DIR__ . '/broker.php';
}
require_once __DIR__ . '/severity.php';
require_once __DIR__ . '/email-lists.php';
require_once __DIR__ . '/notification-events.php';
require_once __DIR__ . '/notify-fanout.php';
require_once __DIR__ . '/notification-delivery.php';
if (is_file(__DIR__ . '/security-labels.php')) {
    require_once __DIR__ . '/security-labels.php';
}

/** Largest rendered body we store / send (the log column is TEXT). */
if (!defined('NOTIFICATION_BODY_MAX')) define('NOTIFICATION_BODY_MAX', 10000);

// ─────────────────────────────────────────────────────────────────────────
// Settings (uncached on purpose - see inc/email-lists.php email_list_options())
// ─────────────────────────────────────────────────────────────────────────

/**
 * One of the three delivery settings, read DIRECTLY from the settings table.
 *
 * Not get_variable(): that caches the whole table for the life of the process,
 * so a value an administrator just saved would not take effect inside the same
 * request, and a test that needs two values would need two processes. These are
 * read once per fired event with at least one matching rule - not per row.
 *
 *   notification_email_format         text | html            default text
 *   notification_prefs_mode           explicit_only |        default explicit_only
 *                                     defaults_apply
 *   notification_log_retention_days   0 = keep forever       default 180
 */
function notification_setting(string $name): string
{
    static $defaults = [
        'notification_email_format'       => 'text',
        'notification_prefs_mode'         => 'explicit_only',
        'notification_log_retention_days' => '180',
    ];
    $default = $defaults[$name] ?? '';
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1", [$name]);
    } catch (\Throwable $e) {
        return $default;
    }
    if ($v === false || $v === null) return $default;
    $v = trim((string) $v);
    if ($v === '') return $default;
    if ($name === 'notification_email_format' && !in_array($v, ['text', 'html'], true)) return $default;
    if ($name === 'notification_prefs_mode' && !in_array($v, ['explicit_only', 'defaults_apply'], true)) return $default;
    if ($name === 'notification_log_retention_days' && !ctype_digit($v)) return $default;
    return $v;
}

// ─────────────────────────────────────────────────────────────────────────
// The hook the writers call
// ─────────────────────────────────────────────────────────────────────────
//
// notification_hook() lives in inc/notification-hook.php, NOT here: the writers
// run on every dispatch action and must not pay this file's include weight (the
// whole message broker) when no rule listens. It is the cheap gate that loads
// this file only once an active rule exists for the event.
require_once __DIR__ . '/notification-hook.php';

/**
 * Compatibility wrapper (the Phase 84/145 contract tests/test_notification_rule_channels.php
 * drives): fire the event, then deliver what it queued SYNCHRONOUSLY and return one
 * row per ATTEMPTED delivery: rule_id, channel, recipient, success, error.
 * Skipped deliveries are logged in notification_log but are not "attempts",
 * so they are absent from the return value, as before.
 *
 * Production code calls notification_hook(), which queues and returns.
 */
function notification_check($event_type, array $context = []) {
    $res = notification_fire((string) $event_type, $context, ['sync' => true]);
    $out = [];
    foreach ($res as $r) {
        if (($r['status'] ?? '') === 'skipped') continue;
        $out[] = [
            'rule_id'   => $r['rule_id'],
            'channel'   => $r['channel'],
            'recipient' => $r['recipient'],
            'success'   => (bool) ($r['success'] ?? false),
            'error'     => $r['error'] ?? null,
        ];
    }
    return $out;
}

/**
 * Match an event against every active rule, plan the deliveries, persist them
 * (one notification_log row each; one queue row per deliverable one), then
 * dispatch.
 *
 * $opts['sync'] = true  deliver every queued delivery before returning (tests,
 *                       notification_check()); the production default queues
 *                       and returns - a dispatch never waits on the internet.
 *
 * @return array<int,array> one row per delivery:
 *         rule_id, channel, recipient, status (queued|skipped|sent|failed),
 *         success (only meaningful once sent/failed), error, log_id
 */
function notification_fire(string $event, array $context = [], array $opts = []): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $results = [];

    $events = notification_events();
    if (!isset($events[$event])) {
        error_log('[notification] unknown event "' . $event . '" - not in the catalogue');
        return $results;
    }

    try {
        $rules = db_fetch_all(
            "SELECT * FROM `{$prefix}notification_rules`
              WHERE `active` = 1 AND `event_type` = ?
              ORDER BY `id`",
            [$event]
        );
    } catch (\Exception $e) {
        // Table probably doesn't exist yet - create it for next time.
        _notification_ensure_tables();
        return $results;
    }
    if (empty($rules)) return $results;

    $ctx = notification_build_context($context + ['event_id' => $event]);
    $queueIds = [];
    $logByQueue = [];

    foreach ($rules as $rule) {
        [$matches, ] = notification_rule_matches($rule, $ctx, $events[$event]);
        if (!$matches) continue;   // a filter mismatch is normal, not worth a log row

        $tid = (int) ($ctx['ticket_id'] ?? 0);
        if (!empty($rule['once_per_incident']) && $tid > 0
            && notification_rule_already_notified((int) $rule['id'], $tid)) {
            $r = notification_log_insert([
                'rule_id' => (int) $rule['id'], 'event_type' => $event, 'ticket_id' => $tid,
                'channel' => (string) $rule['channel'], 'recipient' => 'rule:' . $rule['id'],
                'subject' => '', 'body' => '', 'status' => 'skipped',
                'error' => 'once per incident: this rule already notified for incident #' . $tid,
            ]);
            $results[] = ['rule_id' => (int) $rule['id'], 'channel' => (string) $rule['channel'],
                'recipient' => 'rule:' . $rule['id'], 'status' => 'skipped', 'success' => false,
                'error' => 'once per incident', 'log_id' => $r];
            continue;
        }

        $plan = notification_plan_rule($rule, $ctx);
        $persisted = notification_delivery_persist($rule, $ctx, $plan);
        foreach ($persisted as $p) {
            $results[] = $p;
            if (!empty($p['queue_id'])) { $queueIds[] = (int) $p['queue_id']; $logByQueue[(int) $p['queue_id']] = count($results) - 1; }
        }
    }

    if (!$queueIds) return $results;

    if (!empty($opts['sync'])) {
        // Deliver exactly what THIS call queued, in order, no budget.
        foreach ($queueIds as $qid) {
            $out = notification_delivery_process_queue_id($qid, null);
            $i = $logByQueue[$qid];
            $results[$i]['status']  = $out['outcome'] === 'sent' ? 'sent' : ($out['outcome'] === 'failed' ? 'failed' : 'queued');
            $results[$i]['success'] = ($out['outcome'] === 'sent');
            $results[$i]['error']   = $out['error'] !== '' ? $out['error'] : null;
        }
        return $results;
    }

    notification_delivery_kick();
    return $results;
}

// ─────────────────────────────────────────────────────────────────────────
// Context
// ─────────────────────────────────────────────────────────────────────────

/**
 * Everything a template or filter may need, gathered ONCE per event.
 *
 * The caller passes what it knows (ticket_id, the unit that was assigned, the
 * old/new status, a broadcast's text). The TICKET ROW is authoritative for the
 * incident facts: severity, type, address, case number. Callers used to supply
 * their own copies and got them wrong (`'severity' => 0` on close; the type's
 * `name`, which does not exist) - so a caller's value for a key the ticket
 * provides is ignored.
 *
 * `user` and the event time are captured here, at fire time: a queued delivery
 * is replayed from the command line, where there is no session.
 */
function notification_build_context(array $in): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ctx = $in;
    $tid = (int) ($in['ticket_id'] ?? 0);

    if ($tid > 0) {
        $row = null;
        $sqls = [
            "SELECT t.`id`, t.`scope`, t.`description`, t.`street`, t.`city`, t.`state`, t.`lat`, t.`lng`,
                    t.`severity`, t.`in_types_id`, t.`incident_number`, t.`status`, t.`org_id`,
                    it.`type` AS `incident_type`
               FROM `{$prefix}ticket` t
               LEFT JOIN `{$prefix}in_types` it ON it.`id` = t.`in_types_id`
              WHERE t.`id` = ? AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')",
            // pre-incident_number / pre-org_id installs - still excluding the wastebasket.
            "SELECT t.`id`, t.`scope`, t.`description`, t.`street`, t.`city`, t.`state`, t.`lat`, t.`lng`,
                    t.`severity`, t.`in_types_id`, t.`status`, it.`type` AS `incident_type`
               FROM `{$prefix}ticket` t
               LEFT JOIN `{$prefix}in_types` it ON it.`id` = t.`in_types_id`
              WHERE t.`id` = ? AND (t.`deleted_at` IS NULL OR t.`deleted_at` = '0000-00-00 00:00:00')",
            // pre-wastebasket installs (no deleted_at column at all): the oldest shape.
            "SELECT t.`id`, t.`scope`, t.`description`, t.`street`, t.`city`, t.`state`, t.`lat`, t.`lng`,
                    t.`severity`, t.`in_types_id`, t.`status`, it.`type` AS `incident_type`
               FROM `{$prefix}ticket` t
               LEFT JOIN `{$prefix}in_types` it ON it.`id` = t.`in_types_id`
              WHERE t.`id` = ?",
        ];
        foreach ($sqls as $sql) {
            try { $row = db_fetch_one($sql, [$tid]); break; } catch (\Throwable $e) { $row = null; }
        }
        if ($row) {
            foreach (['scope', 'description', 'street', 'city', 'state', 'incident_type'] as $k) {
                $ctx[$k] = (string) ($row[$k] ?? '');
            }
            $ctx['lat'] = ($row['lat'] !== null && $row['lat'] !== '') ? (string) $row['lat'] : '';
            $ctx['lng'] = ($row['lng'] !== null && $row['lng'] !== '') ? (string) $row['lng'] : '';
            $ctx['severity'] = (int) $row['severity'];
            $ctx['in_types_id'] = (int) $row['in_types_id'];
            $ctx['status'] = (int) $row['status'];
            $ctx['org_id'] = isset($row['org_id']) ? (int) $row['org_id'] : null;
            $num = isset($row['incident_number']) ? trim((string) $row['incident_number']) : '';
            $ctx['incident_number'] = $num !== '' ? $num : '#' . $tid;
        } else {
            // The ticket vanished (deleted between the event and now). Keep what the
            // caller gave, but the incident-keyed facts are unknown: a filter that
            // needs them will not match.
            unset($ctx['severity'], $ctx['in_types_id']);
        }
    }

    if (isset($ctx['severity'])) {
        $ctx['severity_label'] = severity_label((int) $ctx['severity']);
    }

    // Units: a single assignment passes responder_id (+ optionally the name); a
    // batch passes `units` (names) already.
    $names = [];
    if (!empty($in['units']) && is_array($in['units'])) {
        foreach ($in['units'] as $u) { $u = trim((string) $u); if ($u !== '') $names[] = $u; }
    } elseif (!empty($in['responder_id'])) {
        $n = trim((string) ($in['responder_name'] ?? ''));
        if ($n === '') $n = _notification_responder_name((int) $in['responder_id']);
        if ($n !== '') $names[] = $n;
    } elseif (!empty($in['responder_name'])) {
        $names[] = trim((string) $in['responder_name']);
    }
    $ctx['units'] = implode(', ', $names);
    $ctx['unit_count'] = count($names);
    if ($names) $ctx['responder_name'] = $ctx['units'];

    $ctx['user'] = (string) ($in['user'] ?? ($_SESSION['user'] ?? 'System'));
    $ctx['_when'] = time();
    $events = notification_events();
    if (!empty($in['event_id']) && isset($events[$in['event_id']])) {
        $ctx['event'] = $events[$in['event_id']]['label'];
    }
    return $ctx;
}

function _notification_responder_name(int $id): string
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_one("SELECT `name`, `handle` FROM `{$prefix}responder` WHERE `id` = ?", [$id]);
    } catch (\Throwable $e) { return ''; }
    if (!$r) return '';
    return (string) (($r['handle'] ?? '') !== '' ? $r['handle'] : ($r['name'] ?? ''));
}

/**
 * Does this rule apply to this event? Returns [bool, reason].
 *
 * The severity / incident-type filters are EXACT-value matches (severity
 * values are unordered since GH#88 - there is no ">=").
 *
 * A filter on a ticket event whose context has no value for it does NOT match.
 * The old code skipped the check when the value was absent - so a rule
 * restricted to "Fire incidents only" fired for EVERY unit dispatch, because
 * that endpoint never supplied the type. A no-ticket event (a HAS broadcast)
 * ignores the filters: there is nothing to filter on, and the panel does not
 * offer them.
 */
function notification_rule_matches(array $rule, array $ctx, array $eventDef): array
{
    if (empty($eventDef['has_ticket'])) return [true, 'no incident filters apply to this event'];

    if ($rule['severity_filter'] !== null && $rule['severity_filter'] !== '') {
        if (!isset($ctx['severity']) || (int) $rule['severity_filter'] !== (int) $ctx['severity']) {
            return [false, 'severity filter'];
        }
    }
    if ($rule['incident_type_filter'] !== null && $rule['incident_type_filter'] !== '') {
        if (!isset($ctx['in_types_id']) || (int) $rule['incident_type_filter'] !== (int) $ctx['in_types_id']) {
            return [false, 'incident type filter'];
        }
    }
    return [true, ''];
}

/**
 * Has this rule already sent (or queued) something for this incident?
 *
 * Used by "once per incident". Counts `queued` and `sent`; deliberately NOT
 * `failed` or `skipped`: a delivery that never arrived has not notified anyone,
 * so a later event (the next unit dispatched) may legitimately try again.
 */
function notification_rule_already_notified(int $ruleId, int $ticketId): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}notification_log`
              WHERE `rule_id` = ? AND `ticket_id` = ? AND `status` IN ('queued', 'sent')",
            [$ruleId, $ticketId]
        ) > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Channels
// ─────────────────────────────────────────────────────────────────────────

/**
 * GH #84: resolve a notification_rules.channel value to the list of
 * broker channel codes it targets. Pure function - no DB, no globals -
 * so it can be unit tested without a database.
 *
 * 'all' resolves to every key of the supplied registered-channels map
 * (i.e. array_keys($_broker_channels)) rather than a hardcoded subset,
 * so a newly-registered broker channel is reachable via 'all' the
 * instant its inc/channels/*.php file registers it - no code change
 * needed here. The rules panel never offers 'all'; it is kept for rows
 * created by hand before the panel existed.
 *
 * @param string $ruleChannel        The rule's `channel` column value.
 * @param array  $registeredChannels The live $_broker_channels map
 *                                   (code => handler array).
 * @return string[] Channel codes to dispatch to.
 */
function _notification_resolve_rule_channels($ruleChannel, array $registeredChannels) {
    if ($ruleChannel === 'all') {
        return array_keys($registeredChannels);
    }
    return [$ruleChannel];
}

/**
 * GH #84: split a list of channel codes into "shared destination"
 * (adapter posts to one fixed, server-configured destination regardless
 * of the message's `to` - e.g. Slack's `slack_channel`, Telegram's
 * `telegram_chat_id`) versus "per recipient" (the destination is
 * genuinely the message's `to` - email, SMS, local chat, push, and
 * everything else).
 *
 * A channel opts into the shared bucket by declaring
 * `'shared_destination' => true` on its broker_register() call.
 *
 * Pure function - no DB, no globals.
 *
 * @param string[] $channels           Channel codes to classify.
 * @param array    $registeredChannels The live $_broker_channels map.
 * @return array{0: string[], 1: string[]} [sharedChannels, perRecipientChannels]
 */
function _notification_classify_channels(array $channels, array $registeredChannels) {
    $shared = [];
    $perRecipient = [];
    foreach ($channels as $channel) {
        if (!empty($registeredChannels[$channel]['shared_destination'])) {
            $shared[] = $channel;
        } else {
            $perRecipient[] = $channel;
        }
    }
    return [$shared, $perRecipient];
}

/**
 * What kind of address does this channel deliver to?
 *
 *   email   an email address        (email, smtp)
 *   phone   a phone number          (sms)
 *   user    a CAD user account      (local_chat, push)
 *   shared  one fixed destination   (slack, telegram - no recipient at all)
 *   legacy  whatever the adapter takes (aprs, dmr, meshtastic, meshcore, and
 *           any future channel): the pre-Phase-155 behaviour, unchanged
 *
 * Pure.
 */
function notification_channel_kind(string $channel, array $registeredChannels): string
{
    if (!empty($registeredChannels[$channel]['shared_destination'])) return 'shared';
    switch ($channel) {
        case 'email': case 'smtp': return 'email';
        case 'sms': return 'phone';
        case 'local_chat': case 'push': return 'user';
    }
    return 'legacy';
}

// ─────────────────────────────────────────────────────────────────────────
// Recipients
// ─────────────────────────────────────────────────────────────────────────

/**
 * Normalise a phone number for the SMS adapters: digits with an optional
 * leading '+', 7-15 digits. The raw text must look like a phone number to
 * begin with - parentheses, dots, dashes and spaces only - because the generic
 * SMS provider substitutes {to} straight into a URL and a request body, and a
 * profile's phone field is editable by the account's own holder. `5551234&x=1`
 * must not become a parameter.
 *
 * @return string|null null when it is not a usable number. Pure.
 */
function notification_normalize_phone(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '' || !preg_match('/^\+?[\d\s().\-]+$/', $s)) return null;
    $digits = preg_replace('/\D+/', '', $s);
    if (strlen($digits) < 7 || strlen($digits) > 15) return null;
    return (($s[0] === '+') ? '+' : '') . $digits;
}

/**
 * Parse one entry of notification_rules.recipients.
 *
 * Canonical form (what the panel writes):
 *   user:12                 a CAD user account
 *   email:ops@example.org   an email address
 *   tel:+15551234567        a phone number
 *
 * Legacy bare values from hand-made rows still parse: an int or a string of at
 * most nine digits is a USER id; anything that validates as an email is an
 * email; a phone-looking string is a phone. (A ten-digit string is a phone, not
 * a user id: the old code used is_numeric() and so treated "5551234567" as user
 * 5551234567, found no such user, and dropped the recipient without a word.)
 *
 * Pure.
 *
 * @return array|null ['kind' => user|email|tel, ...] or null if unparseable
 */
function _notification_parse_recipient_entry($entry): ?array
{
    if (is_int($entry)) {
        return $entry > 0 ? ['kind' => 'user', 'user_id' => $entry] : null;
    }
    if (!is_string($entry)) return null;
    $e = trim($entry);
    if ($e === '') return null;

    if (preg_match('/^user:(\d+)$/i', $e, $m)) {
        return ((int) $m[1] > 0) ? ['kind' => 'user', 'user_id' => (int) $m[1]] : null;
    }
    if (preg_match('/^email:(.+)$/i', $e, $m)) {
        $a = trim($m[1]);
        return (strlen($a) <= 254 && filter_var($a, FILTER_VALIDATE_EMAIL) !== false)
            ? ['kind' => 'email', 'address' => $a] : null;
    }
    if (preg_match('/^tel:(.+)$/i', $e, $m)) {
        $p = notification_normalize_phone($m[1]);
        return $p !== null ? ['kind' => 'tel', 'address' => $p] : null;
    }
    if (ctype_digit($e) && strlen($e) <= 9) {
        return ((int) $e > 0) ? ['kind' => 'user', 'user_id' => (int) $e] : null;
    }
    if (strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false) {
        return ['kind' => 'email', 'address' => $e];
    }
    $p = notification_normalize_phone($e);
    return $p !== null ? ['kind' => 'tel', 'address' => $p] : null;
}

/**
 * Contact details for CAD users: email, mobile number, display name.
 *
 * Email:  user.email, else the linked roster member's email (member.user_id).
 * Phone:  the first usable of user.phone_m, user.phone_p, member.phone_cell.
 *         `user.phone_m` is read by this engine but written by no screen (the
 *         profile editor writes phone_p), which is why the old code resolved a
 *         NULL number for every user on every install; falling back through
 *         phone_p and the roster's cell number resolves that without adding a
 *         profile field.
 *
 * One batched query per table, not one per user.
 *
 * @param int[] $userIds
 * @return array<int,array{email:string,phone:string,name:string}> only users that exist
 */
function notification_user_contacts(array $userIds): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));

    try {
        $rows = db_fetch_all(
            "SELECT `id`, `user`, `name_f`, `name_l`, `email`, `phone_m`, `phone_p`
               FROM `{$prefix}user` WHERE `id` IN ({$in})", $ids);
    } catch (\Throwable $e) {
        error_log('[notification-engine] user contact lookup failed: ' . $e->getMessage());
        return $out;
    }
    foreach ($rows as $r) {
        $name = trim(((string) ($r['name_f'] ?? '')) . ' ' . ((string) ($r['name_l'] ?? '')));
        $email = '';
        $split = email_list_split_addresses((string) ($r['email'] ?? ''));
        if ($split['valid']) $email = $split['valid'][0];
        $phone = '';
        foreach (['phone_m', 'phone_p'] as $col) {
            $p = notification_normalize_phone((string) ($r[$col] ?? ''));
            if ($p !== null) { $phone = $p; break; }
        }
        $out[(int) $r['id']] = ['email' => $email, 'phone' => $phone,
                                'name' => $name !== '' ? $name : (string) $r['user']];
    }

    // Fallback through the roster member linked to the account.
    $needFallback = [];
    foreach ($out as $id => $c) if ($c['email'] === '' || $c['phone'] === '') $needFallback[] = $id;
    if ($needFallback) {
        $in2 = implode(',', array_fill(0, count($needFallback), '?'));
        $mrows = null;
        foreach ([
            "SELECT `user_id`, `email`, `phone_cell` FROM `{$prefix}member`
              WHERE `user_id` IN ({$in2}) AND (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')",
            "SELECT `user_id`, `email`, `phone_cell` FROM `{$prefix}member` WHERE `user_id` IN ({$in2})",
            "SELECT `user_id`, `email`, NULL AS `phone_cell` FROM `{$prefix}member` WHERE `user_id` IN ({$in2})",
        ] as $sql) {
            try { $mrows = db_fetch_all($sql, $needFallback); break; } catch (\Throwable $e) { $mrows = null; }
        }
        foreach ($mrows ?? [] as $m) {
            $uid = (int) $m['user_id'];
            if (!isset($out[$uid])) continue;
            if ($out[$uid]['email'] === '') {
                $sp = email_list_split_addresses((string) ($m['email'] ?? ''));
                if ($sp['valid']) $out[$uid]['email'] = $sp['valid'][0];
            }
            if ($out[$uid]['phone'] === '') {
                $p = notification_normalize_phone((string) ($m['phone_cell'] ?? ''));
                if ($p !== null) $out[$uid]['phone'] = $p;
            }
        }
    }
    return $out;
}

/**
 * Resolve recipients from a notification rule.
 *
 * Returns entries shaped:
 *   ['kind' => 'user',  'user_id' => int, 'address' => email, 'phone' => phone, 'name' => string]
 *   ['kind' => 'user',  'user_id' => int, 'missing_user' => true]     (account deleted)
 *   ['kind' => 'email', 'address' => string]
 *   ['kind' => 'tel',   'address' => digits, 'phone' => digits]
 *   ['kind' => 'email', 'address' => string, 'email_only' => true, 'from_list' => true, 'user_id' => ?int]
 *   ['kind' => 'invalid', 'raw' => string]                            (unparseable entry)
 *
 * @param array      $rule
 * @param array|null $listStatus  out-param (pass a variable holding null to
 *        receive it): ['state' => ok|missing|archived|tables_missing,
 *        'message' => string] describing the rule's email list, so the caller
 *        can LOG an unusable list instead of treating it as an empty one.
 */
function _notification_resolve_recipients(array $rule, ?array &$listStatus = null) {
    $recipients = [];

    // Parse JSON recipients list
    $recipientList = [];
    if (!empty($rule['recipients'])) {
        $recipientList = json_decode((string) $rule['recipients'], true);
        if (!is_array($recipientList)) {
            $recipientList = [];
        }
    }

    $entries = [];
    $userIds = [];
    foreach ($recipientList as $raw) {
        $p = _notification_parse_recipient_entry($raw);
        if ($p === null) {
            $entries[] = ['kind' => 'invalid', 'raw' => is_scalar($raw) ? substr((string) $raw, 0, 80) : '(not text)'];
            continue;
        }
        if ($p['kind'] === 'user') $userIds[] = $p['user_id'];
        $entries[] = $p;
    }
    $contacts = $userIds ? notification_user_contacts($userIds) : [];

    foreach ($entries as $p) {
        if ($p['kind'] === 'user') {
            $c = $contacts[$p['user_id']] ?? null;
            if ($c === null) {
                $recipients[] = ['kind' => 'user', 'user_id' => $p['user_id'], 'missing_user' => true];
                continue;
            }
            $recipients[] = ['kind' => 'user', 'user_id' => $p['user_id'],
                             'address' => $c['email'], 'phone' => $c['phone'], 'name' => $c['name']];
        } elseif ($p['kind'] === 'email') {
            $recipients[] = ['kind' => 'email', 'address' => $p['address']];
        } elseif ($p['kind'] === 'tel') {
            $recipients[] = ['kind' => 'tel', 'address' => $p['address'], 'phone' => $p['address']];
        } else {
            $recipients[] = $p;
        }
    }

    // Email list recipients - Phase 155 (GH#145).
    //
    // This branch used to run `SELECT email FROM email_list_members`, a column
    // that does not exist (the table has `inline_email`, plus three entry types
    // that are references), inside an EMPTY catch whose comment said "Email
    // lists table may not exist". So a rule that named a list resolved NOTHING,
    // for inline entries too, and no log line said so. The shared resolver
    // (inc/email-lists.php) is now the only way in: it expands member / contact /
    // inline / nested-list entries from live rows at send time, so adding or
    // removing a recipient takes effect on the very next event.
    //
    // honor_email_opt_out is FALSE on purpose: the resolver hands back the
    // opted-out member WITH the user id, and the preference gate in the planner
    // decides (and must be able to bypass it for a high-alert incident).
    // Dropping them here would hide them from that gate.
    $listStatus = ['state' => 'ok', 'message' => ''];
    if (!empty($rule['email_list_id'])) {
        $listId = (int) $rule['email_list_id'];
        $res = email_list_resolve($listId, ['honor_email_opt_out' => false]);
        if (!empty($res['missing_tables'])) {
            error_log('[notification-engine] email list tables are missing; rule ' . ($rule['id'] ?? '?') . ' has no list recipients');
            $listStatus = ['state' => 'tables_missing', 'message' => 'email list tables are missing - run php sql/run_migrations.php'];
        } elseif (!empty($res['missing'])) {
            $listStatus = ['state' => 'missing', 'message' => "email list #{$listId} is missing (deleted?)"];
        } elseif (!empty($res['archived'])) {
            $listStatus = ['state' => 'archived', 'message' => "email list #{$listId} is archived"];
        } else {
            foreach ($res['recipients'] as $rc) {
                $entry = ['kind' => 'email', 'address' => $rc['address'], 'email_only' => true, 'from_list' => true];
                if (!empty($rc['user_id'])) $entry['user_id'] = (int) $rc['user_id'];
                $recipients[] = $entry;
            }
        }
    }

    return _notification_dedupe_recipients($recipients);
}

/**
 * Collapse recipients that share an address (case-insensitively), preferring the
 * entry that carries a user id - so a user named explicitly in the rule AND
 * present in a list (a linked member) gets ONE message, with their preferences
 * applied. Recipients with no address are kept as they are (a user with no
 * email may still have a phone, or be reachable by chat).
 *
 * A user named twice (once explicitly, once via a list member link) is also
 * collapsed on the user id.
 *
 * Pure: no database.
 */
function _notification_dedupe_recipients(array $recipients): array {
    $out = [];
    $byAddr = [];
    $byUser = [];
    foreach ($recipients as $r) {
        $addr = strtolower(trim((string) ($r['address'] ?? '')));
        $uid  = (int) ($r['user_id'] ?? 0);
        $hit = null;
        if ($addr !== '' && isset($byAddr[$addr])) $hit = $byAddr[$addr];
        elseif ($uid > 0 && isset($byUser[$uid]) && ($r['kind'] ?? '') === 'user') $hit = $byUser[$uid];
        if ($hit === null) {
            $i = count($out);
            $out[] = $r;
            if ($addr !== '') $byAddr[$addr] = $i;
            if ($uid > 0) $byUser[$uid] = $i;
            continue;
        }
        if (empty($out[$hit]['user_id']) && $uid > 0) {
            // Keep the first spelling/position but adopt the user identity.
            $out[$hit]['user_id'] = $uid;
            $byUser[$uid] = $hit;
        }
        // The merged recipient is only "email only" if EVERY source was.
        if (!empty($out[$hit]['email_only']) && empty($r['email_only'])) {
            unset($out[$hit]['email_only']);
        }
        // An explicit (non-list) user entry carries phone/name the list entry lacks.
        foreach (['phone', 'name'] as $k) {
            if (empty($out[$hit][$k]) && !empty($r[$k])) $out[$hit][$k] = $r[$k];
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// Preferences
// ─────────────────────────────────────────────────────────────────────────

/**
 * Get a user's notification preferences (legacy contract: defaults when there
 * is no saved row - email + chat on, SMS off).
 */
function _notification_get_user_prefs($userId) {
    $row = _notification_get_user_prefs_row((int) $userId);
    if ($row) return $row;

    // Defaults: email + chat on, SMS off
    return [
        'channel_email' => 1,
        'channel_sms'   => 0,
        'channel_chat'  => 1,
        'quiet_start'   => null,
        'quiet_end'     => null,
    ];
}

/** The saved row, or null when the user never saved preferences. */
function _notification_get_user_prefs_row(int $userId): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}notification_preferences` WHERE `user_id` = ?",
            [$userId]
        );
        if ($row) return $row;
    } catch (\Exception $e) {
        // The table may not exist yet on a pre-migration install. Say so (once per
        // request would be better, but a log line per lookup is still better than
        // the silence that hid every other defect in this file).
        error_log('[notification-engine] preference lookup failed: ' . $e->getMessage());
    }
    return null;
}

/**
 * Check if the current time is within a user's quiet hours.
 */
function _notification_in_quiet_hours(array $prefs) {
    if (empty($prefs['quiet_start']) || empty($prefs['quiet_end'])) {
        return false;
    }

    $now   = date('H:i:s');
    $start = $prefs['quiet_start'];
    $end   = $prefs['quiet_end'];

    // Handle overnight ranges (e.g. 22:00 - 07:00)
    if ($start > $end) {
        return ($now >= $start || $now <= $end);
    }

    return ($now >= $start && $now <= $end);
}

/**
 * May this user be notified on this channel right now? Returns [bool, reason].
 *
 * Two policies, the `notification_prefs_mode` setting:
 *
 *   explicit_only (default)  An administrator naming a person in a rule IS the
 *       consent. A user with NO saved preference row gets everything a rule
 *       names. A SAVED row is honoured: channel switches and quiet hours.
 *   defaults_apply           The pre-Phase-155 behaviour: a user with no row is
 *       treated as email on, chat on, SMS OFF - which made user-addressed SMS
 *       inert for everyone until a preferences screen existed.
 *
 * BYPASS (not configurable, with reason): when the incident's severity is
 * flagged High alert, or the event is `severity_high`, no preference and no
 * quiet hour suppresses the delivery. A suppressed life-safety callout is a
 * hazard; making that a setting would be a foot-gun.
 *
 * Only email / sms / local_chat carry a per-channel switch; quiet hours apply
 * to every per-recipient channel.
 */
function _notification_user_allows(int $userId, string $channel, bool $bypass, string $mode): array
{
    if ($bypass) return [true, null];
    $row = _notification_get_user_prefs_row($userId);
    if ($row === null) {
        if ($mode !== 'defaults_apply') return [true, null];
        $row = _notification_get_user_prefs($userId);
    }
    if (($channel === 'email' || $channel === 'smtp') && empty($row['channel_email'])) {
        return [false, 'the user switched email notifications off'];
    }
    if ($channel === 'sms' && empty($row['channel_sms'])) {
        return [false, 'the user has not enabled SMS notifications'];
    }
    if ($channel === 'local_chat' && empty($row['channel_chat'])) {
        return [false, 'the user switched chat notifications off'];
    }
    if (_notification_in_quiet_hours($row)) {
        return [false, "inside the user's quiet hours"];
    }
    return [true, null];
}

// ─────────────────────────────────────────────────────────────────────────
// Planning (no side effects)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Turn a matched rule into the list of deliveries it would make, WITHOUT
 * sending, logging or queueing anything. notification_fire() persists the
 * result; the panel's Preview calls this alone, so "what will happen" and
 * "what happens" are the same code.
 *
 * Each delivery:
 *   channel, kind (recipient|shared), address (what is shown in the log),
 *   to (what the channel receives), user_id, subject, body, content_type,
 *   priority, status (queued|skipped), reason (why skipped), label,
 *   scheduled_send_at (a security-label send delay), label_name
 *
 * @return array{deliveries:array,warnings:string[],list_status:array,recipient_count:int}
 */
function notification_plan_rule(array $rule, array $ctx, array $opts = []): array
{
    global $_broker_channels;
    $event = (string) $rule['event_type'];
    $warnings = [];
    $deliveries = [];

    $subjectTpl = ((string) ($rule['subject_template'] ?? '')) !== ''
        ? (string) $rule['subject_template'] : notification_default_subject($event);
    $bodyTpl = ((string) ($rule['body_template'] ?? '')) !== ''
        ? (string) $rule['body_template'] : notification_default_body($event);

    $channels = _notification_resolve_rule_channels((string) $rule['channel'], $_broker_channels);
    [$sharedChannels, $perRecipientChannels] = _notification_classify_channels($channels, $_broker_channels);

    $listStatus = null;
    $recipients = _notification_resolve_recipients($rule, $listStatus);
    if ($listStatus['state'] !== 'ok') {
        $warnings[] = $listStatus['message'];
        $deliveries[] = ['channel' => (string) $rule['channel'], 'kind' => 'recipient',
            'address' => 'email list #' . (int) $rule['email_list_id'], 'to' => '', 'user_id' => null,
            'subject' => '', 'body' => '', 'content_type' => '', 'priority' => 'normal',
            'status' => 'skipped', 'reason' => $listStatus['message'], 'label' => 'email list'];
    }

    // High-alert: the incident's own level, or the event itself.
    $bypass = ($event === 'severity_high')
        || (isset($ctx['severity']) && severity_is_high_alert((int) $ctx['severity']));
    $priority = (isset($ctx['severity']) && severity_is_high_alert((int) $ctx['severity'])) ? 'high' : 'normal';
    $mode = notification_setting('notification_prefs_mode');
    $emailFmt = notification_setting('notification_email_format');

    // Security label (Phase 18e): one resolve per ticket. The same gate Message
    // Routing applies - without it a rule could email the address of an incident
    // an agency labelled Restricted.
    $sec = null;
    $tid = (int) ($ctx['ticket_id'] ?? 0);
    if ($tid > 0 && empty($ctx['_synthetic']) && function_exists('seclabel_resolve')) {
        try { $sec = seclabel_resolve($tid); } catch (\Throwable $e) { $sec = null; }
    }
    $gate = static function (string $routingKind) use ($sec): array {
        // returns [blockedReason|null, sendAt|null, labelName]
        if ($sec === null) return [null, null, ''];
        $name = (string) ($sec['name'] ?? '');
        if ($routingKind === 'broadcast' && (int) ($sec['routing_allow_broadcast'] ?? 1) === 0) {
            return ["blocked by security label '" . $name . "'", null, $name];
        }
        if ($routingKind === 'direct' && (int) ($sec['routing_allow_direct'] ?? 1) === 0) {
            return ["blocked by security label '" . $name . "'", null, $name];
        }
        $delay = (int) ($sec['routing_send_delay_secs'] ?? 0);
        return [null, $delay > 0 ? date('Y-m-d H:i:s', time() + $delay) : null, $name];
    };

    // Shared-destination channels (Slack, Telegram) fire exactly once per rule
    // match: there is one destination no matter how many recipients (zero is
    // fine), and no "user" on the other end to apply preferences to.
    foreach ($sharedChannels as $channel) {
        $subject = notification_render_subject($subjectTpl, $ctx);
        $body = notification_render($bodyTpl, $ctx, 'text', $warnings);
        [$blocked, $sendAt, $labelName] = $gate('broadcast');
        $deliveries[] = ['channel' => $channel, 'kind' => 'shared', 'address' => 'shared:' . $channel,
            'to' => 'all', 'user_id' => null, 'subject' => $subject, 'body' => $body,
            'content_type' => '', 'priority' => $priority,
            'status' => $blocked ? 'skipped' : 'queued', 'reason' => $blocked,
            'scheduled_send_at' => $sendAt, 'label_name' => $labelName, 'label' => 'channel'];
    }

    foreach ($perRecipientChannels as $channel) {
        $kind = notification_channel_kind($channel, $_broker_channels);
        $html = ($kind === 'email' && $emailFmt === 'html');
        $subject = notification_render_subject($subjectTpl, $ctx);
        $body = notification_render($bodyTpl, $ctx, $html ? 'html' : 'text', $warnings);
        $contentType = ($kind === 'email')
            ? ($html ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8') : '';

        foreach ($recipients as $r) {
            $d = ['channel' => $channel, 'kind' => 'recipient', 'address' => '', 'to' => '',
                  'user_id' => !empty($r['user_id']) ? (int) $r['user_id'] : null,
                  'subject' => $subject, 'body' => $body, 'content_type' => $contentType,
                  'priority' => $priority, 'status' => 'queued', 'reason' => null,
                  'label' => (string) ($r['name'] ?? ($r['address'] ?? ''))];

            if (($r['kind'] ?? '') === 'invalid') {
                $d['address'] = 'invalid:' . $r['raw'];
                $d['status'] = 'skipped'; $d['reason'] = 'recipient "' . $r['raw'] . '" is not a user, email address or phone number';
                $deliveries[] = $d; continue;
            }
            if (!empty($r['missing_user'])) {
                $d['address'] = 'user:' . $r['user_id'];
                $d['status'] = 'skipped'; $d['reason'] = 'user #' . $r['user_id'] . ' no longer exists';
                $deliveries[] = $d; continue;
            }
            // An address from an EMAIL list is an email address and nothing else.
            if (!empty($r['email_only']) && $kind !== 'email') {
                $d['address'] = (string) $r['address'];
                $d['status'] = 'skipped'; $d['reason'] = 'email list recipients apply to email/smtp only';
                $deliveries[] = $d; continue;
            }

            // Resolve the address FOR THIS CHANNEL KIND.
            $addr = ''; $why = '';
            switch ($kind) {
                case 'email':
                    if (($r['kind'] ?? '') === 'tel') { $why = 'the recipient is a phone number, not an email address'; }
                    elseif (($r['address'] ?? '') !== '') { $addr = (string) $r['address']; }
                    else { $why = 'no email address on file' . (!empty($r['name']) ? ' for ' . $r['name'] : ''); }
                    $d['to'] = $addr;
                    break;
                case 'phone':
                    if (($r['kind'] ?? '') === 'email') { $why = 'the recipient is an email address, not a phone number'; }
                    elseif (($r['phone'] ?? '') !== '') { $addr = (string) $r['phone']; }
                    else { $why = 'no mobile number on file' . (!empty($r['name']) ? ' for ' . $r['name'] : ''); }
                    $d['to'] = $addr;
                    break;
                case 'user':
                    if (empty($r['user_id'])) { $why = $channel . ' needs a user account; the recipient has none'; }
                    else { $addr = 'user:' . (int) $r['user_id']; $d['to'] = (int) $r['user_id']; }
                    break;
                default: // legacy: unchanged pre-Phase-155 addressing
                    $addr = (string) ($r['address'] ?? '');
                    if ($addr === '' && !empty($r['user_id'])) $addr = (string) (int) $r['user_id'];
                    $d['to'] = $addr !== '' ? $addr : 'all';
                    if ($addr === '') $addr = 'all';
            }
            // What the log shows for this recipient: the address used, else the address on file, else who it was. Never empty -
            // a skipped row with a blank recipient cannot be traced back to anyone.
            $d['address'] = $addr !== '' ? $addr
                : (!empty($r['user_id']) ? 'user:' . (int) $r['user_id']
                : ((string) ($r['address'] ?? '') !== '' ? (string) $r['address'] : (string) ($r['raw'] ?? 'unknown')));
            if ($why !== '') { $d['status'] = 'skipped'; $d['reason'] = $why; $deliveries[] = $d; continue; }

            // Preferences + quiet hours (only when we know the user).
            if (!empty($d['user_id'])) {
                [$ok, $reason] = _notification_user_allows((int) $d['user_id'], $channel, $bypass, $mode);
                if (!$ok) { $d['status'] = 'skipped'; $d['reason'] = $reason; $deliveries[] = $d; continue; }
            }

            // Security label: an email list is a broadcast; a named person is direct.
            [$blocked, $sendAt, $labelName] = $gate(!empty($r['from_list']) ? 'broadcast' : 'direct');
            if ($blocked) { $d['status'] = 'skipped'; $d['reason'] = $blocked; $d['label_name'] = $labelName; $deliveries[] = $d; continue; }
            if ($sendAt !== null) { $d['scheduled_send_at'] = $sendAt; $d['label_name'] = $labelName; }

            $deliveries[] = $d;
        }
    }

    return ['deliveries' => $deliveries, 'warnings' => array_values(array_unique($warnings)),
            'list_status' => $listStatus, 'recipient_count' => count($recipients)];
}

// ─────────────────────────────────────────────────────────────────────────
// Rendering
// ─────────────────────────────────────────────────────────────────────────

/**
 * The value each placeholder takes for this context. Everything is a string.
 * Pure.
 */
function notification_placeholder_values(array $context): array
{
    $when = (int) ($context['_when'] ?? time());
    $street = (string) ($context['street'] ?? '');
    $city = (string) ($context['city'] ?? '');
    $units = (string) ($context['units'] ?? '');
    $responder = $units !== '' ? $units : (string) ($context['responder_name'] ?? ($context['responder_id'] ?? ''));
    return [
        'ticket_id'       => (string) ($context['ticket_id'] ?? ''),
        'incident_number' => (string) ($context['incident_number'] ?? ''),
        'incident_type'   => (string) ($context['incident_type'] ?? ''),
        'scope'           => (string) ($context['scope'] ?? ''),
        'description'     => (string) ($context['description'] ?? ''),
        'severity'        => isset($context['severity']) ? (string) $context['severity'] : '',
        'severity_label'  => isset($context['severity'])
            ? (string) ($context['severity_label'] ?? _notification_severity_label($context['severity'])) : '',
        'street'          => $street,
        'city'            => $city,
        'state'           => (string) ($context['state'] ?? ''),
        'address'         => trim($street . ' ' . $city),
        'lat'             => (string) ($context['lat'] ?? ''),
        'lng'             => (string) ($context['lng'] ?? ''),
        'old_status'      => (string) ($context['old_status_label'] ?? ($context['old_status'] ?? '')),
        'new_status'      => (string) ($context['new_status_label'] ?? ($context['new_status'] ?? '')),
        'responder'       => $responder,
        'units'           => $units,
        'unit_count'      => isset($context['unit_count']) ? (string) $context['unit_count'] : '',
        'message_subject' => (string) ($context['message_subject'] ?? ''),
        'message'         => (string) ($context['message'] ?? ''),
        'event'           => (string) ($context['event'] ?? ''),
        'user'            => (string) ($context['user'] ?? ($_SESSION['user'] ?? 'System')),
        'time'            => date('H:i:s', $when),
        'date'            => date('Y-m-d', $when),
        'datetime'        => date('Y-m-d H:i:s', $when),
    ];
}

/**
 * Render a template: `{token}` and `{token|clean}` substitution.
 *
 *   mode 'text'  values go in as they are (NUL bytes removed)
 *   mode 'html'  every VALUE is htmlspecialchars()-escaped (template text the
 *                administrator wrote is theirs and is kept), and line breaks
 *                become <br>. An incident's scope can originate from an inbound
 *                message; it must not be able to inject markup into outgoing mail.
 *
 * `|clean` removes semicolons and line breaks from the value and collapses
 * whitespace, for delimiter formats (Active911 StandardA is NATURE;ADDRESS;CITY;
 * DETAILS - a semicolon in a street name would shift every later field).
 *
 * An unknown token is left LITERAL and its name appended to $unknown, so the
 * panel's preview can warn. No eval, no expression language.
 *
 * Pure.
 *
 * @param string[]|null $unknown out: unknown placeholder warnings
 */
function notification_render(string $template, array $context, string $mode = 'text', ?array &$unknown = null): string
{
    if ($template === '') return '';
    $values = notification_placeholder_values($context);
    $out = preg_replace_callback('/\{([a-z_]+)(\|clean)?\}/i', function ($m) use ($values, $mode, &$unknown) {
        $name = strtolower($m[1]);
        if (!array_key_exists($name, $values)) {
            if (is_array($unknown)) $unknown[] = 'unknown placeholder {' . $m[1] . '} is left as typed';
            return $m[0];
        }
        $v = str_replace("\0", '', (string) $values[$name]);
        if (!empty($m[2])) {
            $v = trim((string) preg_replace('/\s+/', ' ', str_replace([';', "\r", "\n"], ' ', $v)));
        }
        return $mode === 'html' ? htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $v;
    }, $template);
    if (!is_string($out)) $out = $template;
    if ($mode === 'html') $out = str_replace(["\r\n", "\r", "\n"], "<br>\n", $out);
    if (strlen($out) > NOTIFICATION_BODY_MAX) $out = substr($out, 0, NOTIFICATION_BODY_MAX);
    return $out;
}

/**
 * Render a SUBJECT: text mode, forced to a single line (a CR or LF in a header
 * value is how header injection starts), trimmed, capped at the column width.
 * Pure.
 */
function notification_render_subject(string $template, array $context): string
{
    $s = notification_render($template, $context, 'text');
    $s = trim((string) preg_replace('/[\r\n\0]+/', ' ', $s));
    return strlen($s) > 255 ? substr($s, 0, 255) : $s;
}

/**
 * Legacy name kept for callers/tests that used it: text mode, no warnings.
 */
function _notification_render_template($template, array $context) {
    return notification_render((string) $template, $context, 'text');
}

/**
 * Default subject line for an event type (now sourced from the catalogue).
 */
function _notification_default_subject($event_type) {
    return notification_default_subject((string) $event_type);
}

/**
 * Default body text for an event type (now sourced from the catalogue).
 */
function _notification_default_body($event_type) {
    return notification_default_body((string) $event_type);
}

/**
 * Get a human-readable severity label.
 */
function _notification_severity_label($severity) {
    // GH#87/GH#88 (2026-08-19) - sourced from the configurable
    // severity_levels table (inc/severity.php), not a hardcoded array.
    return severity_label((int) $severity);
}

// ─────────────────────────────────────────────────────────────────────────
// Log + tables
// ─────────────────────────────────────────────────────────────────────────

/**
 * Insert a notification_log row. Returns the new id, or null on failure
 * (logged - a log failure must never break the notification it records).
 *
 * @param array $row rule_id, event_type, ticket_id, channel, recipient,
 *                   subject, body, status, error
 */
function notification_log_insert(array $row): ?int
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}notification_log`
             (`rule_id`, `event_type`, `ticket_id`, `channel`, `recipient`, `subject`, `body`, `status`, `error`, `sent_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $row['rule_id'] ?? null, (string) ($row['event_type'] ?? ''),
                isset($row['ticket_id']) && (int) $row['ticket_id'] > 0 ? (int) $row['ticket_id'] : null,
                substr((string) ($row['channel'] ?? ''), 0, 20),
                substr((string) ($row['recipient'] ?? ''), 0, 255),
                substr((string) ($row['subject'] ?? ''), 0, 255),
                (string) ($row['body'] ?? ''),
                (string) ($row['status'] ?? 'sent'),
                isset($row['error']) && $row['error'] !== null ? (string) $row['error'] : null,
            ]
        );
        return (int) db_insert_id();
    } catch (\Throwable $e) {
        error_log('Notification log failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Log a notification delivery to the notification_log table (legacy signature).
 */
function _notification_log($ruleId, $eventType, $ticketId, $channel, $recipient, $subject, $body, $status, $error = null) {
    notification_log_insert([
        'rule_id' => $ruleId, 'event_type' => $eventType, 'ticket_id' => $ticketId,
        'channel' => $channel, 'recipient' => $recipient, 'subject' => $subject,
        'body' => $body, 'status' => $status, 'error' => $error,
    ]);
}

/**
 * Ensure notification tables exist (idempotent). The SAME final shape as
 * sql/notification_rules.sql + sql/run_phase155_notification_rules.php, so a
 * lazily-created table and an installed one no longer differ (the old lazy DDL
 * used VARCHAR where the install used ENUM, so what an install accepted
 * depended on how its table came to exist).
 */
function _notification_ensure_tables() {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // The DDL lives in ONE place (inc/notification-schema.php) so a lazily
    // created table and an installed one cannot differ again.
    require_once __DIR__ . '/notification-schema.php';

    foreach (notification_schema_ddl($prefix) as $sql) {
        try {
            db_query($sql);
        } catch (\Exception $e) {
            // Non-fatal
            error_log('Notification table creation failed: ' . $e->getMessage());
        }
    }
}
