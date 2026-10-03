<?php
/**
 * Phase 155 (GH#144) - the notification event catalogue: the SINGLE source of
 * truth for what a Notification Rule can react to, what text it can use, and
 * the ready-made starting points offered in the rules panel.
 *
 * Before this file the same facts lived in four places that had drifted:
 * the ENUM in sql/notification_rules.sql, two `switch` tables in the engine
 * (default subject / default body), the docblock at the top of the engine, and
 * the placeholder map inside the renderer. Two of the seven events
 * (`unit_clear`, `has_broadcast`) had default templates and a place in the ENUM
 * but NOTHING in the application ever fired them - a selectable event that
 * could never match. Every event listed here is wired to a real writer (see
 * the "Hooked at" lines); tests/test_notification_rules_fire.php fires each.
 *
 * Pure data + pure functions: no database, so tests can read the catalogue
 * without a connection and the rules API can hand it to the browser as-is.
 */

declare(strict_types=1);

/**
 * The events a rule can react to, in the order the panel lists them.
 *
 * Keys per event:
 *   label        short name shown in the rule table and picker
 *   description  one sentence for the picker's help text
 *   has_ticket   true = the event is about an incident, so the severity and
 *                incident-type filters apply. A rule that sets a filter on an
 *                event whose context has no matching value does NOT match
 *                (fail closed); a no-ticket event ignores the filters.
 *   once_capable the "send only for the first ..." switch is offered
 *   hooked       where the event fires (documentation + a test cross-check)
 *   sample       a realistic context, for the preview pane and the docs
 *   subject/body default templates used when a rule leaves them blank
 *
 * @return array<string,array>
 */
function notification_events(): array
{
    static $events = null;
    if ($events !== null) return $events;

    $ticketSample = [
        'ticket_id' => 1042, 'incident_number' => '26-0142', 'scope' => 'Structure fire - smoke from the roof',
        'description' => 'Caller reports heavy smoke from the second floor.', 'incident_type' => 'Structure Fire',
        'street' => '123 Main St', 'city' => 'Springfield', 'state' => 'MN', 'lat' => '44.9778', 'lng' => '-93.2650',
        'severity' => 2, 'severity_label' => 'High', 'status' => 2,
        'units' => 'E1, L1', 'unit_count' => 2, 'responder_name' => 'E1',
        'old_status_label' => 'Open', 'new_status_label' => 'Closed',
        'user' => 'dispatcher1',
    ];

    $events = [
        'incident_create' => [
            'label' => 'New incident',
            'description' => 'A new incident is created (the New Incident form, the external API, or a message turned into an incident).',
            'has_ticket' => true, 'once_capable' => false,
            'hooked' => 'inc/incident-write.php incident_create_internal()',
            'sample' => $ticketSample,
            'subject' => '[Tickets CAD] New Incident #{ticket_id}: {scope}',
            'body' => "New incident created:\n\nIncident: #{ticket_id}\nType: {incident_type}\nScope: {scope}\nSeverity: {severity_label}\nAddress: {address}\nTime: {datetime}\nCreated by: {user}",
        ],
        'unit_assign' => [
            'label' => 'Unit dispatched',
            'description' => 'A unit is assigned to an incident - from the incident page, the New Incident form (one message for all the units ticked), or the external API.',
            'has_ticket' => true, 'once_capable' => true,
            'hooked' => 'inc/assignment-write.php assign_create_internal(); inc/incident-write.php incident_create_internal() (batched)',
            'sample' => $ticketSample,
            'subject' => '[Tickets CAD] Unit Assigned to #{ticket_id}: {scope}',
            'body' => "Unit assigned:\n\nIncident: #{ticket_id}\nType: {incident_type}\nScope: {scope}\nAddress: {address}\nUnit: {responder}\nAssigned at: {datetime}\nAssigned by: {user}",
        ],
        'unit_clear' => [
            'label' => 'Unit cleared',
            'description' => 'A unit is cleared from an incident or removed from it. Closing the incident is reported by the Incident closed event, not once per unit.',
            'has_ticket' => true, 'once_capable' => false,
            'hooked' => 'inc/assignment-write.php assign_update_status_internal() + assign_unassign_internal(); inc/responder-write.php responder_set_status_internal()',
            'sample' => $ticketSample,
            'subject' => '[Tickets CAD] Unit Cleared from #{ticket_id}: {scope}',
            'body' => "Unit cleared:\n\nIncident: #{ticket_id}\nScope: {scope}\nUnit: {responder}\nCleared at: {datetime}",
        ],
        'incident_close' => [
            'label' => 'Incident closed',
            'description' => 'An incident is closed - by a dispatcher, the external API, or the automatic close.',
            'has_ticket' => true, 'once_capable' => false,
            'hooked' => 'inc/incident-write.php incident_update_status_internal()',
            'sample' => $ticketSample,
            'subject' => '[Tickets CAD] Incident #{ticket_id} Closed: {scope}',
            'body' => "Incident closed:\n\nIncident: #{ticket_id}\nType: {incident_type}\nScope: {scope}\nClosed at: {datetime}\nClosed by: {user}",
        ],
        'incident_status' => [
            'label' => 'Incident reopened or rescheduled',
            'description' => 'An incident is reopened or moved to Scheduled. Closing is a separate event.',
            'has_ticket' => true, 'once_capable' => true,
            'hooked' => 'inc/incident-write.php incident_update_status_internal()',
            'sample' => $ticketSample,
            'subject' => '[Tickets CAD] Incident #{ticket_id} Status Change: {scope}',
            'body' => "Incident status changed:\n\nIncident: #{ticket_id}\nScope: {scope}\nOld Status: {old_status}\nNew Status: {new_status}\nChanged at: {datetime}\nChanged by: {user}",
        ],
        'severity_high' => [
            'label' => 'High-alert incident',
            'description' => 'An incident is created at, or raised to, a severity level that Settings -> Severity Levels marks as High alert.',
            'has_ticket' => true, 'once_capable' => false,
            'hooked' => 'inc/incident-write.php incident_create_internal() + incident_update_fields_internal()',
            'sample' => $ticketSample,
            'subject' => '[Tickets CAD] HIGH SEVERITY #{ticket_id}: {scope}',
            'body' => "HIGH SEVERITY INCIDENT:\n\nIncident: #{ticket_id}\nType: {incident_type}\nScope: {scope}\nSeverity: {severity_label}\nAddress: {address}\nTime: {datetime}",
        ],
        'has_broadcast' => [
            'label' => 'HAS broadcast sent',
            'description' => 'A dispatcher sends a HAS (all-users) broadcast alert. This event is not about an incident, so the severity and type filters do not apply.',
            'has_ticket' => false, 'once_capable' => false,
            'hooked' => 'api/messaging.php (action=broadcast)',
            'sample' => ['message_subject' => 'Severe weather', 'message' => 'Tornado warning - take shelter.', 'user' => 'dispatcher1'],
            'subject' => '[Tickets CAD] HAS Broadcast Alert: {message_subject}',
            'body' => "HAS Broadcast Alert\n\n{message_subject}\n{message}\n\nTime: {datetime}\nIssued by: {user}",
        ],
    ];
    return $events;
}

/** @return string[] */
function notification_event_ids(): array
{
    return array_keys(notification_events());
}

function notification_default_subject(string $event): string
{
    $e = notification_events();
    return $e[$event]['subject'] ?? '[Tickets CAD] Notification';
}

function notification_default_body(string $event): string
{
    $e = notification_events();
    return $e[$event]['body'] ?? "Notification from Tickets CAD\n\nTime: {datetime}";
}

/**
 * Every placeholder a template may use. Unknown tokens are left LITERAL in the
 * output and reported as warnings in the preview - never evaluated: this is a
 * str_replace/regex substitution, there is no eval and no expression language.
 *
 * Append `|clean` to any token to strip semicolons and line breaks from the
 * value, for delimiter formats such as Active911 StandardA
 * (NATURE;ADDRESS;CITY;DETAILS): {street|clean}.
 *
 * `{incident_url}` is deliberately absent: there is no site base-URL setting,
 * and a queued delivery is replayed from the command line with no Host header
 * to derive one from.
 *
 * @return array<string,array{description:string,example:string}> keyed by token name (no braces)
 */
function notification_placeholders(): array
{
    return [
        'ticket_id'       => ['description' => 'Internal incident id', 'example' => '1042'],
        'incident_number' => ['description' => 'Incident case number', 'example' => '26-0142'],
        'incident_type'   => ['description' => 'Incident type name', 'example' => 'Structure Fire'],
        'scope'           => ['description' => 'Incident title / scope', 'example' => 'Structure fire - smoke from the roof'],
        'description'     => ['description' => 'Incident description', 'example' => 'Caller reports heavy smoke'],
        'severity'        => ['description' => 'Severity value (number)', 'example' => '2'],
        'severity_label'  => ['description' => 'Severity name', 'example' => 'High'],
        'street'          => ['description' => 'Street address', 'example' => '123 Main St'],
        'city'            => ['description' => 'City', 'example' => 'Springfield'],
        'state'           => ['description' => 'State', 'example' => 'MN'],
        'address'         => ['description' => 'Street and city together', 'example' => '123 Main St Springfield'],
        'lat'             => ['description' => 'Latitude, if the incident has a map position', 'example' => '44.9778'],
        'lng'             => ['description' => 'Longitude, if the incident has a map position', 'example' => '-93.2650'],
        'old_status'      => ['description' => 'Previous incident status (status events)', 'example' => 'Open'],
        'new_status'      => ['description' => 'New incident status (status events)', 'example' => 'Closed'],
        'responder'       => ['description' => 'Unit name (unit events); the whole list for a batch dispatch', 'example' => 'E1'],
        'units'           => ['description' => 'All units named by this event, comma separated', 'example' => 'E1, L1'],
        'unit_count'      => ['description' => 'How many units this event names', 'example' => '2'],
        'message_subject' => ['description' => 'HAS broadcast subject (broadcast event)', 'example' => 'Severe weather'],
        'message'         => ['description' => 'HAS broadcast text (broadcast event)', 'example' => 'Tornado warning'],
        'event'           => ['description' => 'The event name', 'example' => 'Unit dispatched'],
        'user'            => ['description' => 'The person who caused the event', 'example' => 'dispatcher1'],
        'time'            => ['description' => 'Time of day (HH:MM:SS)', 'example' => '14:05:09'],
        'date'            => ['description' => 'Date (YYYY-MM-DD)', 'example' => '2026-10-02'],
        'datetime'        => ['description' => 'Date and time', 'example' => '2026-10-02 14:05:09'],
    ];
}

/**
 * Ready-made rules. Selecting one in the panel pre-fills the rule form; the
 * administrator still names the recipients and saves.
 *
 * The Active911 recipes are BEST EFFORT. Active911 publishes two formats it can
 * parse from a sender that cannot use its own parser - StandardA
 * (NATURE;ADDRESS;CITY;DETAILS) and Cadpage (NAME: VALUE lines) - but its
 * public documentation does not say which part of the email (subject or body)
 * it reads for StandardA. The recipes put the line in the body; confirm with a
 * real agency (and tell Active911 support which format you use - it does not
 * auto-detect) before relying on one.
 *
 * @return array<int,array>
 */
function notification_rule_presets(): array
{
    return [
        [
            'id' => 'active911_standarda',
            'label' => 'Active911 - StandardA (one page per incident)',
            'description' => 'Emails your Active911 alert address one line, NATURE;ADDRESS;CITY;DETAILS, the first time a unit is dispatched to an incident. Add your Active911 alert address as a recipient.',
            'rule' => [
                'name' => 'Active911 StandardA - first unit dispatched',
                'event_type' => 'unit_assign', 'channel' => 'email',
                'subject_template' => 'CAD dispatch',
                'body_template' => '{incident_type|clean};{street|clean};{city|clean};{scope|clean}',
                'once_per_incident' => 1,
            ],
        ],
        [
            'id' => 'active911_cadpage',
            'label' => 'Active911 - Cadpage (one page per incident)',
            'description' => 'Emails your Active911 alert address Cadpage-style NAME: VALUE lines the first time a unit is dispatched. Add your Active911 alert address as a recipient.',
            'rule' => [
                'name' => 'Active911 Cadpage - first unit dispatched',
                'event_type' => 'unit_assign', 'channel' => 'email',
                'subject_template' => 'CAD dispatch',
                'body_template' => "CALL: {incident_type}\nADDR: {street}\nCITY: {city}\nINFO: {scope}\nID: {incident_number}\nPRI: {severity_label}\nDATE: {date}\nTIME: {time}\nUNIT: {units}",
                'once_per_incident' => 1,
            ],
        ],
        [
            'id' => 'email_high_alert',
            'label' => 'Email supervisors on a high-alert incident',
            'description' => 'Emails the people you name when an incident is created at, or raised to, a High alert severity. Quiet hours and opt-outs never silence it.',
            'rule' => [
                'name' => 'Supervisors - high-alert incident',
                'event_type' => 'severity_high', 'channel' => 'email',
                'subject_template' => '[Tickets CAD] HIGH ALERT #{ticket_id}: {incident_type}',
                'body_template' => '',
                'once_per_incident' => 0,
            ],
        ],
        [
            'id' => 'chat_every_incident',
            'label' => 'Post every new incident to Slack or Telegram',
            'description' => 'Posts one line to the Slack or Telegram channel configured in Settings for every new incident. Needs no recipients.',
            'rule' => [
                'name' => 'Post new incidents to Slack',
                'event_type' => 'incident_create', 'channel' => 'slack',
                'subject_template' => '',
                'body_template' => 'New incident #{ticket_id} ({incident_type}): {scope} - {address}',
                'once_per_incident' => 0,
            ],
        ],
    ];
}
