<?php
/**
 * GH#154 (2026-10-19) — the built-in ICS forms, matched to the NIMS ICS Forms
 * Booklet (FEMA 502-2, September 2010).
 *
 * Everything that defines what a built-in form IS lives here, as pure
 * functions with no endpoint dispatch, so api/ics-forms.php, ics-forms.php and
 * the tests all read one definition:
 *
 *   ics_builtin_form_types()        the ordered type list (hub cards, filter,
 *                                   badges, valid form_type values)
 *   ics_builtin_template($type)     a form's field definitions
 *   ics_form_normalize_data()       read-time mapping of data saved by an
 *                                   earlier version onto today's fields
 *   ics_form_template_blocks()      the FEMA block list a template displays
 *   generatePrintHtml()             the print/PDF document for every type
 *
 * Template properties used by the built-ins (custom types never set them, so
 * their editor and print output are unchanged):
 *
 *   block        FEMA block number (string). The FIRST element of a block
 *                names it: its `block_title`, or its label minus the "N. "
 *                prefix. ics_form_template_blocks() derives the list from
 *                that, and tests/test_gh154_ics_forms_fema_reference.php
 *                compares it with a table typed from the booklet.
 *   help         FEMA's short block instruction, shown under the input.
 *   col          width on a 12-column grid, in the editor and on paper.
 *   signature    field type: a signature line on paper; nothing to type.
 *   legacy       a field (or table column) kept only so data saved by an
 *                earlier version stays visible; shown only when it has data.
 *   fixed_rows   (table) labelled rows, e.g. ICS 209 block 31.
 *   default_data (table) the rows a NEW form starts with.
 *   print_rows   (table) blank rows printed when the table is empty.
 *   checkbox     (table column type) stored as '1' or ''.
 *   print_label / print_append / print_hide
 *                (table column) print the FEMA header and merge a column into
 *                its neighbour, e.g. ICS 205 prints "RX Freq N or W" as one
 *                column while the editor keeps N/W as its own drop-down.
 *
 * Saved data is never rewritten here. ics_form_normalize_data() fills a NEW
 * field from the OLD one only when the new one is empty; the editor's load
 * (api/ics-forms.php GET ?id=) and the printer both call it, and the stored
 * JSON changes only when someone saves the form.
 */

// ═════════════════════════════════════════════════════════════════════
// Type list
// ═════════════════════════════════════════════════════════════════════

/** Hub groups, in display order. */
function ics_builtin_groups(): array
{
    return [
        'iap'       => 'Incident Action Plan',
        'planning'  => 'Briefing, planning and status',
        'messages'  => 'Messages and logs',
        'resources' => 'Resources, logistics and personnel',
        'tcards'    => 'ICS 219 Resource Status Cards (T-Cards)',
    ];
}

/**
 * Every built-in form type, in hub order (FEMA number order within each
 * group). `color` is a Bootstrap colour name (teal/indigo/orange/purple/
 * pink/cyan are drawn with the theme's CSS variable). A type is listed only
 * when its template builder exists, so the list can never offer a form the
 * endpoint cannot open; tests/test_gh154_ics_forms_fema_reference.php checks
 * the list is exactly the FEMA booklet plus the pre-2010 ICS 214a.
 */
function ics_builtin_form_types(): array
{
    static $types = null;
    if ($types !== null) {
        return $types;
    }
    $t = function ($slug, $number, $title, $group, $color, $desc, array $extra = []) {
        return array_merge([
            'slug' => $slug, 'number' => $number, 'title' => $title,
            'group' => $group, 'color' => $color, 'desc' => $desc, 'legacy' => false,
        ], $extra);
    };
    $all = [
        $t('201', 'ICS 201', 'Incident Briefing', 'planning', 'pink',
            'The initial incident briefing: map/sketch, situation and safety, objectives, actions, organization and resources.'),
        $t('202', 'ICS 202', 'Incident Objectives', 'iap', 'info',
            'Objectives, command emphasis and the list of what is in the Incident Action Plan.'),
        $t('203', 'ICS 203', 'Organization Assignment List', 'iap', 'info',
            'Who is staffing each activated position, section by section.'),
        $t('204', 'ICS 204', 'Assignment List', 'iap', 'info',
            'A Division or Group\'s assignment: personnel, resources, work, special instructions and communications.'),
        $t('205', 'ICS 205', 'Incident Radio Communications Plan', 'iap', 'warning',
            'Radio channels and talkgroups for the operational period: RX/TX frequencies, tones/NACs and mode.'),
        $t('205a', 'ICS 205A', 'Communications List', 'iap', 'secondary',
            'How to reach everyone assigned to the incident: position, name and method of contact.'),
        $t('206', 'ICS 206', 'Medical Plan', 'iap', 'teal',
            'Medical aid stations, ambulance services, hospitals and medical emergency procedures.'),
        $t('207', 'ICS 207', 'Incident Organization Chart', 'planning', 'info',
            'The organization chart for the wall: every activated position and who fills it.'),
        $t('208', 'ICS 208', 'Safety Message/Plan', 'iap', 'danger',
            'The Safety Officer\'s safety message and whether a site safety plan is required.'),
        $t('208hm', 'ICS 208 HM', 'Site Safety and Control Plan', 'iap', 'danger',
            'Hazardous-materials site safety: organization, entry and decon teams, hazards, monitoring, map and briefing.'),
        $t('209', 'ICS 209', 'Incident Status Summary', 'planning', 'primary',
            'The status report sent up the chain: size, location, casualties, threats, resource needs and commitment.'),
        $t('210', 'ICS 210', 'Resource Status Change', 'resources', 'secondary',
            'Record a resource\'s change of status or assignment as it is reported.'),
        $t('211', 'ICS 211', 'Incident Check-In List', 'resources', 'success',
            'Check personnel and equipment in on arrival: who, from where, when and for what assignment.'),
        $t('213', 'ICS 213', 'General Message', 'messages', 'primary',
            'A message or notification that needs a written record. Winlink XML export.'),
        $t('213rr', 'ICS 213 RR', 'Resource Request Message', 'messages', 'danger',
            'Order personnel, equipment or supplies through Logistics and Finance.'),
        $t('214', 'ICS 214', 'Activity Log', 'messages', 'success',
            'Notable activities for one person or a whole unit, with the resources assigned.'),
        $t('214a', 'ICS 214a', 'Individual Log (pre-2010)', 'messages', 'indigo',
            'The pre-2010 individual log, kept for forms already saved. Use ICS 214 for new logs.',
            ['legacy' => true]),
        $t('215', 'ICS 215', 'Operational Planning Worksheet', 'planning', 'info',
            'The Operations Section\'s plan for the next period: work assignments and the resources required, on hand and to order.'),
        $t('215a', 'ICS 215A', 'Incident Action Plan Safety Analysis', 'planning', 'danger',
            'Hazards and mitigations for each incident area, from the Safety Officer and Operations.'),
        $t('217a', 'ICS 217A', 'Communications Resource Availability Worksheet', 'resources', 'warning',
            'The radio channels and talkgroups available to the incident, by frequency band.'),
        $t('218', 'ICS 218', 'Support Vehicle/Equipment Inventory', 'resources', 'secondary',
            'Every support vehicle and piece of equipment assigned, for the Ground Support Unit.'),
        $t('219-1', 'ICS 219-1', 'Header Card (Gray)', 'tcards', 'secondary', 'Labels a T-card rack column.'),
        $t('219-2', 'ICS 219-2', 'Crew/Team Card (Green)', 'tcards', 'secondary', 'A crew or team.'),
        $t('219-3', 'ICS 219-3', 'Engine Card (Rose)', 'tcards', 'secondary', 'An engine.'),
        $t('219-4', 'ICS 219-4', 'Helicopter Card (Blue)', 'tcards', 'secondary', 'A helicopter.'),
        $t('219-5', 'ICS 219-5', 'Personnel Card (White)', 'tcards', 'secondary', 'One person.'),
        $t('219-6', 'ICS 219-6', 'Fixed-Wing Card (Orange)', 'tcards', 'secondary', 'A fixed-wing aircraft.'),
        $t('219-7', 'ICS 219-7', 'Equipment Card (Yellow)', 'tcards', 'secondary', 'A piece of equipment.'),
        $t('219-8', 'ICS 219-8', 'Miscellaneous Equipment/Task Force Card (Tan)', 'tcards', 'secondary', 'Miscellaneous equipment or a task force.'),
        $t('219-10', 'ICS 219-10', 'Generic Card (Light Purple)', 'tcards', 'secondary', 'Any other resource.'),
        $t('220', 'ICS 220', 'Air Operations Summary', 'planning', 'cyan',
            'Air operations for the period: personnel, frequencies, fixed-wing and helicopters, and their missions.'),
        $t('221', 'ICS 221', 'Demobilization Check-Out', 'resources', 'orange',
            'Release a resource: section sign-offs, travel and reassignment information.'),
        $t('225', 'ICS 225', 'Incident Personnel Performance Rating', 'resources', 'purple',
            'A supervisor\'s rating of an individual\'s performance on the incident.'),
        $t('230', 'ICS 230-CG', 'Daily Meeting Schedule', 'planning', 'secondary',
            'The day\'s meetings: time, purpose, attendees and location.'),
        $t('233', 'ICS 233-CG', 'Incident Open Action Tracker', 'planning', 'secondary',
            'Tasks the Incident Commander assigns that are not incident objectives, and their status.'),
    ];
    $types = array_values(array_filter($all, function ($m) {
        return function_exists('_icsb_tpl_' . str_replace('-', '_', $m['slug']));
    }));
    return $types;
}

/** The built-in slugs, i.e. every valid built-in form_type value. */
function ics_builtin_slugs(): array
{
    return array_column(ics_builtin_form_types(), 'slug');
}

/** One type's list entry, or null. */
function ics_builtin_type_meta(string $slug): ?array
{
    foreach (ics_builtin_form_types() as $m) {
        if ($m['slug'] === $slug) {
            return $m;
        }
    }
    return null;
}

/**
 * The compact label/colour map the hub's JavaScript reads from a data-
 * attribute (assets/js/ics-forms.js getFormTypeBadge()).
 */
function ics_builtin_badge_map(): array
{
    $out = [];
    foreach (ics_builtin_form_types() as $m) {
        $out[$m['slug']] = ['label' => $m['number'], 'color' => $m['color']];
    }
    return $out;
}

/**
 * Class/style fragments for a hub card's number and New button in a type's
 * colour. Bootstrap has text-/btn- classes for its theme colours; the rest
 * (teal, indigo, ...) are drawn with the theme's own CSS variable, so both
 * day and night themes stay correct. Returned fragments are fixed strings
 * built from a whitelist, never from user input.
 */
function ics_builtin_color_classes(string $color): array
{
    if (in_array($color, ['teal', 'indigo', 'orange', 'purple', 'pink', 'cyan'], true)) {
        return [
            'text_class' => '', 'text_style' => ' style="color:var(--bs-' . $color . ')"',
            'btn_class'  => ' btn-secondary', 'btn_style' => ' style="background-color:var(--bs-' . $color . ');border-color:var(--bs-' . $color . ')"',
        ];
    }
    $bs = in_array($color, ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'], true) ? $color : 'secondary';
    return ['text_class' => ' text-' . $bs, 'text_style' => '', 'btn_class' => ' btn-' . $bs, 'btn_style' => ''];
}

// ═════════════════════════════════════════════════════════════════════
// Template building blocks
// ═════════════════════════════════════════════════════════════════════

/** A field. */
function icsb_f(string $key, string $label, string $type = 'text', array $extra = []): array
{
    return array_merge(['key' => $key, 'label' => $label, 'type' => $type, 'required' => false], $extra);
}

/** A section header (a FEMA block title, or an unnumbered section). */
function icsb_h(string $block, string $title, array $extra = []): array
{
    $h = ['type' => 'section_header', 'label' => ($block !== '' ? $block . '. ' : '') . $title];
    if ($block !== '') {
        $h['block'] = $block;
    }
    return array_merge($h, $extra);
}

/** A signature line (print only). */
function icsb_sig(string $block, string $label = 'Signature', int $col = 4): array
{
    $s = ['type' => 'signature', 'label' => $label, 'col' => $col];
    if ($block !== '') {
        $s['block'] = $block;
    }
    return $s;
}

/** A table column. */
function icsb_c(string $key, string $label, string $type = 'text', array $extra = []): array
{
    return array_merge(['key' => $key, 'label' => $label, 'type' => $type], $extra);
}

/** Block 1 — Incident Name. */
function icsb_incident_name(string $block = '1', int $col = 6, string $title = 'Incident Name'): array
{
    return icsb_f('incident_name', $block . '. ' . $title, 'text', [
        'block' => $block, 'col' => $col, 'required' => true,
        'help' => 'The name assigned to the incident.',
    ]);
}

/** Incident Number. */
function icsb_incident_number(string $block = '2', int $col = 3): array
{
    return icsb_f('incident_number', $block . '. Incident Number', 'text', [
        'block' => $block, 'col' => $col,
        'help' => 'The number assigned to the incident.',
    ]);
}

/** The usual "Operational Period" block (Date/Time From, Date/Time To). */
function icsb_op_period(string $block = '2', int $col = 3, string $title = 'Operational Period'): array
{
    return [
        icsb_f('op_period_from', $block . '. ' . $title . ': From', 'datetime-local', [
            'block' => $block, 'block_title' => $title, 'col' => $col, 'required' => true,
            'help' => 'Start date and time (24-hour clock).',
        ]),
        icsb_f('op_period_to', $title . ': To', 'datetime-local', [
            'block' => $block, 'col' => $col, 'required' => true,
            'help' => 'End date and time.',
        ]),
    ];
}

/**
 * The usual "Prepared by" block: Name, Position/Title, Signature, Date/Time.
 * $dateKey lets a form keep the key its saved data already uses.
 */
function icsb_prepared_by(string $block, string $title = 'Prepared by', array $opts = []): array
{
    $dateKey = $opts['date_key'] ?? 'prepared_datetime';
    $out = [
        icsb_f('prepared_by', $block . '. ' . $title . ': Name', 'text', [
            'block' => $block, 'block_title' => $title, 'col' => 3,
            'help' => $opts['help'] ?? 'Name, ICS position/title and signature of the person preparing the form, with the date and time prepared.',
        ]),
    ];
    if (empty($opts['no_position'])) {
        $out[] = icsb_f('prepared_by_position', 'Position/Title', 'text', ['block' => $block, 'col' => 3]);
    }
    $out[] = icsb_sig($block, 'Signature', empty($opts['no_position']) ? 3 : 5);
    $out[] = icsb_f($dateKey, 'Date/Time', 'datetime-local', ['block' => $block, 'col' => 3]);
    return $out;
}

/** Yes/No options for a select. */
function icsb_yes_no(): array
{
    return ['Yes', 'No'];
}

// ═════════════════════════════════════════════════════════════════════
// Templates
// ═════════════════════════════════════════════════════════════════════

/**
 * The blank template for a built-in type, or null for an unknown one.
 * The shape is the same one custom types use ({form_type, form_number,
 * form_title, fields}) plus print_title, footer, iap and prefill.
 */
function ics_builtin_template(string $type): ?array
{
    $meta = ics_builtin_type_meta($type);
    if ($meta === null) {
        return null;
    }
    $fn = '_icsb_tpl_' . str_replace('-', '_', $type);
    if (!function_exists($fn)) {
        return null;
    }
    $tpl = $fn();
    return array_merge([
        'form_type'   => $type,
        'form_number' => $meta['number'],
        'form_title'  => $meta['title'],
        'print_title' => strtoupper($meta['title']),
        'footer'      => $meta['number'],
        'iap'         => false,
        'prefill'     => [],
    ], $tpl);
}

// ── ICS 205 — Incident Radio Communications Plan ──────────────────────
function _icsb_tpl_205(): array
{
    return [
        'landscape' => true,
        'iap' => true,
        'fields' => array_merge(
            [
                icsb_incident_name('1', 4),
                icsb_f('date_prepared', '2. Date/Time Prepared', 'datetime-local', [
                    'block' => '2', 'col' => 4, 'required' => true,
                    'help' => 'Date and time prepared (24-hour clock).',
                ]),
            ],
            icsb_op_period('3', 2),
            [
                icsb_f('radio_channels', '4. Basic Radio Channel Use', 'table', [
                    'block' => '4', 'default_rows' => 3, 'print_rows' => 8,
                    'help' => 'One row per channel or talkgroup, programmed as in a mobile or portable radio. '
                        . 'Frequencies to four decimal places (xxx.xxxx); N = narrowband, W = wideband. '
                        . 'Mode: A analog, D digital, M mixed. For a trunked talkgroup, name the trunked system.',
                    'columns' => [
                        icsb_c('zone_group', 'Zone Grp.', 'text', ['width' => '70px']),
                        icsb_c('channel', 'Ch #', 'text', ['width' => '55px']),
                        icsb_c('function', 'Function', 'text', ['width' => '110px']),
                        icsb_c('channel_name', 'Channel Name/Trunked Radio System Talkgroup', 'text', ['width' => '170px']),
                        icsb_c('assignment', 'Assignment', 'text', ['width' => '120px']),
                        icsb_c('frequency_rx', 'RX Freq', 'text', ['width' => '95px', 'print_label' => 'RX Freq N or W', 'print_append' => ['rx_nw']]),
                        icsb_c('rx_nw', 'N or W', 'select', ['width' => '60px', 'options' => ['N', 'W'], 'print_hide' => true]),
                        icsb_c('rx_tone', 'RX Tone/NAC', 'text', ['width' => '85px']),
                        icsb_c('frequency_tx', 'TX Freq', 'text', ['width' => '95px', 'print_label' => 'TX Freq N or W', 'print_append' => ['tx_nw']]),
                        icsb_c('tx_nw', 'N or W', 'select', ['width' => '60px', 'options' => ['N', 'W'], 'print_hide' => true]),
                        icsb_c('tx_tone', 'TX Tone/NAC', 'text', ['width' => '85px']),
                        icsb_c('mode', 'Mode (A, D, or M)', 'select', ['width' => '70px', 'options' => [
                            ['value' => 'A', 'label' => 'A (analog)'],
                            ['value' => 'D', 'label' => 'D (digital)'],
                            ['value' => 'M', 'label' => 'M (mixed)'],
                        ]]),
                        icsb_c('remarks', 'Remarks', 'text', ['width' => 'auto']),
                    ],
                ]),
                icsb_f('special_instructions', '5. Special Instructions', 'textarea', [
                    'block' => '5', 'rows' => 4,
                    'help' => 'e.g. cross-band repeaters, secure voice, encoders, private line (PL) tones, other emergency communications needs.',
                ]),
                icsb_f('prepared_by', '6. Prepared by (Communications Unit Leader): Name', 'text', [
                    'block' => '6', 'block_title' => 'Prepared by (Communications Unit Leader)', 'col' => 4,
                    'help' => 'Typically the Communications Unit Leader.',
                ]),
                icsb_sig('6', 'Signature', 4),
                icsb_f('prepared_datetime', 'Date/Time', 'datetime-local', ['block' => '6', 'col' => 4]),
            ]
        ),
    ];
}

// ── ICS 214 — Activity Log ─────────────────────────────────────────────
function _icsb_tpl_214(): array
{
    return [
        'prefill' => ['time_prepared' => 'now'],
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('name', '3. Name', 'text', [
                    'block' => '3', 'col' => 4,
                    'help' => 'The unit or resource designator (e.g. Facilities Unit, Safety Officer, Strike Team) — or your own name for a personal log.',
                ]),
                icsb_f('ics_position', '4. ICS Position', 'text', [
                    'block' => '4', 'col' => 4,
                    'help' => 'Name and ICS position of the individual in charge.',
                ]),
                icsb_f('home_agency', '5. Home Agency (and Unit)', 'text', [
                    'block' => '5', 'col' => 4,
                    'help' => 'Home agency of the person completing the form, and unit designator if used.',
                ]),
                icsb_f('resources_assigned', '6. Resources Assigned', 'table', [
                    'block' => '6', 'default_rows' => 2, 'print_rows' => 6,
                    'help' => 'Everyone working with you. Leave empty for an individual log.',
                    'columns' => [
                        icsb_c('name', 'Name', 'text', ['width' => 'auto']),
                        icsb_c('ics_position', 'ICS Position', 'text', ['width' => '220px']),
                        icsb_c('home_agency', 'Home Agency (and Unit)', 'text', ['width' => '260px']),
                    ],
                ]),
                icsb_f('activity_log', '7. Activity Log', 'table', [
                    'block' => '7', 'default_rows' => 3, 'print_rows' => 14,
                    'help' => 'Date, time (24-hour clock) and a short description of each notable activity.',
                    'columns' => [
                        icsb_c('date', 'Date', 'date', ['width' => '140px', 'print_label' => 'Date/Time', 'print_append' => ['time']]),
                        icsb_c('time', 'Time', 'time', ['width' => '110px', 'print_hide' => true]),
                        icsb_c('activity', 'Notable Activities', 'text', ['width' => 'auto']),
                    ],
                ]),
                icsb_f('prepared_by', '8. Prepared by: Name', 'text', [
                    'block' => '8', 'block_title' => 'Prepared by', 'col' => 3,
                    'help' => 'Name, ICS position/title and signature of the person preparing the form.',
                ]),
                icsb_f('prepared_by_position', 'Position/Title', 'text', ['block' => '8', 'col' => 3]),
                icsb_sig('8', 'Signature', 2),
                icsb_f('date_prepared', 'Date', 'date', ['block' => '8', 'col' => 2]),
                icsb_f('time_prepared', 'Time', 'time', ['block' => '8', 'col' => 2]),
            ]
        ),
    ];
}

// ── ICS 213 — General Message ───────────────────────────────────────────
function _icsb_tpl_213(): array
{
    return [
        'fields' => [
            icsb_f('incident_name', '1. Incident Name (Optional)', 'text', [
                'block' => '1', 'col' => 12, 'help' => 'Optional: the name assigned to the incident.',
            ]),
            icsb_f('to_name', '2. To (Name and Position): Name', 'text', [
                'block' => '2', 'block_title' => 'To (Name and Position)', 'col' => 6, 'required' => true,
                'help' => 'At least first initial and last name; for Unified Command, include agency names.',
            ]),
            icsb_f('to_position', 'To: Position', 'text', ['block' => '2', 'col' => 6]),
            icsb_f('from_name', '3. From (Name and Position): Name', 'text', [
                'block' => '3', 'block_title' => 'From (Name and Position)', 'col' => 6, 'required' => true,
            ]),
            icsb_f('from_position', 'From: Position', 'text', ['block' => '3', 'col' => 6]),
            icsb_f('subject', '4. Subject', 'text', ['block' => '4', 'col' => 6, 'required' => true]),
            icsb_f('date', '5. Date', 'date', ['block' => '5', 'col' => 3, 'required' => true]),
            icsb_f('time', '6. Time', 'time', ['block' => '6', 'col' => 3, 'required' => true,
                'help' => '24-hour clock.']),
            icsb_f('message', '7. Message', 'textarea', ['block' => '7', 'rows' => 6, 'required' => true,
                'help' => 'Be as concise as possible.']),
            icsb_f('approved_name', '8. Approved by: Name', 'text', [
                'block' => '8', 'block_title' => 'Approved by', 'col' => 4,
            ]),
            icsb_sig('8', 'Signature', 4),
            icsb_f('approved_position', 'Position/Title', 'text', ['block' => '8', 'col' => 4]),
            icsb_f('reply', '9. Reply', 'textarea', ['block' => '9', 'rows' => 4,
                'help' => 'The recipient enters a reply and returns the message to the originator.']),
            icsb_f('reply_by', '10. Replied by: Name', 'text', [
                'block' => '10', 'block_title' => 'Replied by', 'col' => 3,
            ]),
            icsb_f('reply_position', 'Position/Title', 'text', ['block' => '10', 'col' => 3]),
            icsb_sig('10', 'Signature', 2),
            icsb_f('reply_date', 'Date', 'date', ['block' => '10', 'col' => 2]),
            icsb_f('reply_time', 'Time', 'time', ['block' => '10', 'col' => 2]),
        ],
    ];
}

// ── ICS 202 — Incident Objectives ───────────────────────────────────────
function _icsb_tpl_202(): array
{
    $iap = [];
    foreach (['203' => 'ICS 203', '204' => 'ICS 204', '205' => 'ICS 205', '205a' => 'ICS 205A',
              '206' => 'ICS 206', '207' => 'ICS 207', '208' => 'ICS 208'] as $k => $label) {
        $iap[] = icsb_f('iap_' . $k, $label, 'checkbox', ['block' => '6', 'col' => 2]);
    }
    $iap[] = icsb_f('iap_map', 'Map/Chart', 'checkbox', ['block' => '6', 'col' => 2]);
    $iap[] = icsb_f('iap_weather', 'Weather Forecast/Tides/Currents', 'checkbox', ['block' => '6', 'col' => 4]);
    return [
        'iap' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('objectives', '3. Objective(s)', 'textarea', ['block' => '3', 'rows' => 6, 'required' => true,
                    'help' => 'Clear, concise objectives for managing the response, ideally in priority order '
                        . '(specific, measurable, action-oriented, realistic, time-sensitive).']),
                icsb_f('command_emphasis', '4. Operational Period Command Emphasis', 'textarea', ['block' => '4', 'rows' => 3,
                    'help' => 'Where to place emphasis this period: tactical priorities, sequence of events, e.g. falling debris, secondary explosions.']),
                icsb_f('situational_awareness', 'General Situational Awareness', 'textarea', ['block' => '4', 'rows' => 3,
                    'help' => 'Weather forecast, incident conditions, general safety message (reviewed by the Safety Officer).']),
                icsb_f('site_safety_plan_required', '5. Site Safety Plan Required?', 'select', [
                    'block' => '5', 'col' => 4, 'options' => icsb_yes_no(),
                    'help' => 'Set by the Safety Officer.',
                ]),
                icsb_f('site_safety_plan_location', 'Approved Site Safety Plan(s) Located at', 'text', ['block' => '5', 'col' => 8]),
                icsb_h('6', 'Incident Action Plan', [
                    'help' => 'Check the items included in this Incident Action Plan, and list other attachments.',
                ]),
            ],
            $iap,
            [
                icsb_f('other_attachments', 'Other Attachments', 'textarea', ['block' => '6', 'rows' => 2]),
            ],
            icsb_prepared_by('7', 'Prepared by', ['date_key' => 'date_prepared']),
            [
                icsb_f('approved_by', '8. Approved by Incident Commander: Name', 'text', [
                    'block' => '8', 'block_title' => 'Approved by Incident Commander', 'col' => 4,
                    'help' => 'With a Unified Command, one Incident Commander may approve.',
                ]),
                icsb_sig('8', 'Signature', 4),
                icsb_f('approved_datetime', 'Date/Time', 'datetime-local', ['block' => '8', 'col' => 4]),
                icsb_f('current_actions', 'Summary of Current Actions', 'textarea', ['rows' => 4, 'legacy' => true]),
            ]
        ),
    ];
}

// ── ICS 205A — Communications List ──────────────────────────────────────
function _icsb_tpl_205a(): array
{
    return [
        'iap' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('contacts', '3. Basic Local Communications Information', 'table', [
                    'block' => '3', 'default_rows' => 3, 'print_rows' => 14,
                    'help' => 'Each assigned position\'s methods of contact: radio frequency, phone (with area code), '
                        . 'pager, cell; the vehicle ID if applicable. Mark the form if it holds sensitive numbers.',
                    'columns' => [
                        icsb_c('position', 'Incident Assigned Position', 'text', ['width' => '220px']),
                        icsb_c('name', 'Name (Alphabetized)', 'text', ['width' => '220px']),
                        icsb_c('contact', 'Method(s) of Contact (phone, pager, cell, etc.)', 'text', ['width' => 'auto']),
                    ],
                ]),
            ],
            icsb_prepared_by('4', 'Prepared by', ['date_key' => 'date_prepared'])
        ),
    ];
}

// ── ICS 206 — Medical Plan ──────────────────────────────────────────────
function _icsb_tpl_206(): array
{
    return [
        'iap' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('aid_stations', '3. Medical Aid Stations', 'table', [
                    'block' => '3', 'default_rows' => 2, 'print_rows' => 5,
                    'columns' => [
                        icsb_c('name', 'Name', 'text', ['width' => '200px']),
                        icsb_c('location', 'Location', 'text', ['width' => 'auto']),
                        icsb_c('contact', 'Contact Number(s)/Frequency', 'text', ['width' => '200px']),
                        icsb_c('paramedics', 'Paramedics on Site?', 'select', ['width' => '120px', 'options' => icsb_yes_no()]),
                    ],
                ]),
                icsb_f('transportation', '4. Transportation (indicate air or ground)', 'table', [
                    'block' => '4', 'default_rows' => 2, 'print_rows' => 4,
                    'help' => 'Ambulance services available to the incident; say whether each is air or ground.',
                    'columns' => [
                        icsb_c('service', 'Ambulance Service', 'text', ['width' => '200px']),
                        icsb_c('address', 'Location', 'text', ['width' => 'auto']),
                        icsb_c('phone', 'Contact Number(s)/Frequency', 'text', ['width' => '200px']),
                        icsb_c('level_of_service', 'Level of Service', 'select', ['width' => '120px', 'options' => [
                            ['value' => 'ALS', 'label' => 'ALS (Advanced Life Support)'],
                            ['value' => 'BLS', 'label' => 'BLS (Basic Life Support)'],
                        ]]),
                    ],
                ]),
                icsb_f('hospitals', '5. Hospitals', 'table', [
                    'block' => '5', 'default_rows' => 2, 'print_rows' => 5,
                    'help' => 'Name any predesignated medivac aircraft with its frequency. Give latitude and longitude '
                        . 'when the hospital has a helipad. Trauma Center: the level, if it is one.',
                    'columns' => [
                        icsb_c('name', 'Hospital Name', 'text', ['width' => '170px']),
                        icsb_c('address', 'Address, Latitude & Longitude if Helipad', 'text', ['width' => 'auto']),
                        icsb_c('phone', 'Contact Number(s)/Frequency', 'text', ['width' => '160px']),
                        icsb_c('travel_air', 'Travel Time: Air', 'text', ['width' => '85px']),
                        icsb_c('travel_ground', 'Travel Time: Ground', 'text', ['width' => '85px']),
                        icsb_c('trauma_level', 'Trauma Center (Level)', 'text', ['width' => '100px']),
                        icsb_c('burn_center', 'Burn Center', 'select', ['width' => '90px', 'options' => icsb_yes_no()]),
                        icsb_c('helipad', 'Helipad', 'select', ['width' => '90px', 'options' => icsb_yes_no()]),
                    ],
                ]),
                icsb_f('medical_procedures', '6. Special Medical Emergency Procedures', 'textarea', [
                    'block' => '6', 'rows' => 5,
                    'help' => 'Who to contact and how, who manages an incident within the incident (rescue, accident), '
                        . 'and how to report a medical emergency.',
                ]),
                icsb_f('aviation_rescue', 'Aviation assets are utilized for rescue (coordinate with Air Operations)', 'checkbox', [
                    'block' => '6', 'col' => 12,
                ]),
                icsb_f('prepared_by', '7. Prepared by (Medical Unit Leader): Name', 'text', [
                    'block' => '7', 'block_title' => 'Prepared by (Medical Unit Leader)', 'col' => 4,
                ]),
                icsb_sig('7', 'Signature', 4),
                icsb_f('date_prepared', 'Date/Time', 'datetime-local', ['block' => '7', 'col' => 4]),
                icsb_f('approved_by', '8. Approved by (Safety Officer): Name', 'text', [
                    'block' => '8', 'block_title' => 'Approved by (Safety Officer)', 'col' => 4,
                ]),
                icsb_sig('8', 'Signature', 4),
                icsb_f('approved_datetime', 'Date/Time', 'datetime-local', ['block' => '8', 'col' => 4]),
            ]
        ),
    ];
}

// ── ICS 213 RR — Resource Request Message ───────────────────────────────
function _icsb_tpl_213rr(): array
{
    return [
        'landscape' => true,
        'fields' => [
            icsb_incident_name('1', 5),
            icsb_f('date_prepared', '2. Date/Time', 'datetime-local', ['block' => '2', 'col' => 4, 'required' => true]),
            icsb_f('request_number', '3. Resource Request Number', 'text', ['block' => '3', 'col' => 3]),
            icsb_h('', 'Requestor'),
            icsb_f('resource_requests', '4. Order', 'table', [
                'block' => '4', 'default_rows' => 3, 'print_rows' => 8,
                'help' => 'Use a separate form for each source of supply. Detailed item description: vital characteristics, '
                    . 'brand, specs, experience, size, etc.',
                'columns' => [
                    icsb_c('qty', 'Qty.', 'text', ['width' => '60px']),
                    icsb_c('kind', 'Kind', 'text', ['width' => '110px']),
                    icsb_c('type', 'Type', 'text', ['width' => '90px']),
                    icsb_c('description', 'Detailed Item Description', 'text', ['width' => 'auto']),
                    icsb_c('arrival', 'Arrival Date and Time: Requested', 'text', ['width' => '150px']),
                    icsb_c('arrival_estimated', 'Arrival Date and Time: Estimated', 'text', ['width' => '150px']),
                    icsb_c('cost', 'Cost', 'text', ['width' => '90px']),
                    icsb_c('priority', 'Priority (earlier version)', 'text', ['width' => '90px', 'legacy' => true]),
                ],
            ]),
            icsb_f('delivery_location', '5. Requested Delivery/Reporting Location', 'text', ['block' => '5']),
            icsb_f('substitutes', '6. Suitable Substitutes and/or Suggested Sources', 'textarea', ['block' => '6', 'rows' => 2]),
            icsb_f('requested_by', '7. Requested by Name/Position', 'text', ['block' => '7', 'col' => 5]),
            icsb_f('priority', '8. Priority', 'select', ['block' => '8', 'col' => 3, 'options' => ['Urgent', 'Routine', 'Low']]),
            icsb_f('section_chief_approval', '9. Section Chief Approval', 'text', ['block' => '9', 'col' => 4]),
            icsb_h('', 'Logistics'),
            icsb_f('logistics_order_number', '10. Logistics Order Number', 'text', ['block' => '10', 'col' => 6]),
            icsb_f('supplier_contact', '11. Supplier Phone/Fax/Email', 'text', ['block' => '11', 'col' => 6]),
            icsb_f('supplier_name', '12. Name of Supplier/POC', 'text', ['block' => '12', 'col' => 12]),
            icsb_f('logistics_notes', '13. Notes', 'textarea', ['block' => '13', 'rows' => 2]),
            icsb_f('approved_by', '14. Approval Signature of Auth Logistics Rep', 'text', ['block' => '14', 'col' => 8]),
            icsb_f('approved_date', '15. Date/Time', 'datetime-local', ['block' => '15', 'col' => 4]),
            icsb_f('order_placed_by', '16. Order placed by', 'select', ['block' => '16', 'col' => 4, 'options' => [
                ['value' => 'SPUL', 'label' => 'SPUL (Supply Unit)'],
                ['value' => 'PROC', 'label' => 'PROC (Procurement Unit)'],
            ]]),
            icsb_h('', 'Finance'),
            icsb_f('finance_comments', '17. Reply/Comments from Finance', 'textarea', ['block' => '17', 'rows' => 2]),
            icsb_f('finance_signature', '18. Finance Section Signature', 'text', ['block' => '18', 'col' => 8]),
            icsb_f('finance_datetime', '19. Date/Time', 'datetime-local', ['block' => '19', 'col' => 4]),
            icsb_f('to_name', 'To (Name/Position)', 'text', ['legacy' => true]),
            icsb_f('from_name', 'From (Name/Position)', 'text', ['legacy' => true]),
        ],
    ];
}

// ── ICS 214a — Individual Log (pre-2010) ────────────────────────────────
// FEMA merged the individual log into ICS 214 in 2010. Kept unchanged (same
// fields) so forms already saved keep working; labelled as the old form.
function _icsb_tpl_214a(): array
{
    return [
        'print_title' => 'INDIVIDUAL LOG, PRE-2010',
        'fields' => [
            icsb_h('', 'Pre-2010 form', [
                'help' => 'FEMA replaced the ICS 214a with the ICS 214 in 2010 (its blocks 3-6 cover one person or a team). '
                    . 'Use the ICS 214 for new logs; this form is kept for logs already saved.',
            ]),
            ['key' => 'incident_name',     'label' => 'Incident Name',           'type' => 'text',           'required' => true, 'col' => 6],
            ['key' => 'date_prepared',     'label' => 'Date Prepared',           'type' => 'date',           'required' => true, 'col' => 6],
            ['key' => 'op_period_from',    'label' => 'Operational Period From',  'type' => 'datetime-local', 'required' => true, 'col' => 6],
            ['key' => 'op_period_to',      'label' => 'Operational Period To',    'type' => 'datetime-local', 'required' => true, 'col' => 6],
            ['key' => 'individual_name',   'label' => 'Name',                    'type' => 'text',           'required' => true, 'col' => 4],
            ['key' => 'ics_position',      'label' => 'ICS Position',            'type' => 'text',           'required' => false, 'col' => 4],
            ['key' => 'home_agency',       'label' => 'Home Agency',             'type' => 'text',           'required' => false, 'col' => 4],
            ['key' => 'activity_log',      'label' => 'Activity Log',            'type' => 'table',          'required' => false, 'print_rows' => 14,
             'columns' => [
                 ['key' => 'time',     'label' => 'Time',               'type' => 'time',  'width' => '120px'],
                 ['key' => 'activity', 'label' => 'Notable Activities', 'type' => 'text',  'width' => 'auto'],
             ]],
            ['key' => 'prepared_by',       'label' => 'Prepared By',             'type' => 'text',           'required' => false],
        ],
    ];
}

/** ICS 221 block 6: the check-out rows FEMA prints on a blank form. */
function _icsb_221_checkout_rows(): array
{
    $rows = [];
    foreach ([
        ['Logistics Section', 'Supply Unit'], ['Logistics Section', 'Communications Unit'],
        ['Logistics Section', 'Facilities Unit'], ['Logistics Section', 'Ground Support Unit'],
        ['Logistics Section', 'Security Manager'], ['Finance/Administration Section', 'Time Unit'],
        ['Planning Section', 'Documentation Leader'], ['Planning Section', 'Demobilization Leader'],
    ] as [$section, $unit]) {
        $rows[] = ['check' => '', 'section' => $section, 'unit' => $unit, 'remarks' => '', 'name' => '', 'signature' => ''];
    }
    return $rows;
}

// ── ICS 221 — Demobilization Check-Out ──────────────────────────────────
function _icsb_tpl_221(): array
{
    return [
        'fields' => array_merge(
            [
                icsb_incident_name('1', 6),
                icsb_incident_number('2', 6),
                icsb_f('date_time', '3. Planned Release Date/Time', 'datetime-local', ['block' => '3', 'col' => 4, 'required' => true]),
                icsb_f('resource_unit_id', '4. Resource or Personnel Released', 'text', ['block' => '4', 'col' => 4, 'required' => true,
                    'help' => 'The individual or resource being released.']),
                icsb_f('order_request_number', '5. Order Request Number', 'text', ['block' => '5', 'col' => 4,
                    'help' => 'Or the agency demobilization number.']),
                icsb_f('checkout', '6. Resource or Personnel', 'table', [
                    'block' => '6', 'default_data' => _icsb_221_checkout_rows(), 'print_rows' => 10,
                    'help' => 'Resources are not released until the checked units below have signed off, with the Demobilization '
                        . 'Unit Leader (or Planning Section representative). Check each unit the resource must check out with; add rows for others '
                        . '(e.g. Safety Officer, Agency Representative). Sign on the printed form or type a name.',
                    'columns' => [
                        icsb_c('check', 'Check', 'checkbox', ['width' => '55px']),
                        icsb_c('section', 'Section', 'select', ['width' => '190px', 'options' => [
                            'Logistics Section', 'Finance/Administration Section', 'Other Section/Staff', 'Planning Section',
                        ]]),
                        icsb_c('unit', 'Unit/Leader/Manager/Other', 'text', ['width' => '190px']),
                        icsb_c('remarks', 'Remarks', 'text', ['width' => 'auto']),
                        icsb_c('name', 'Name', 'text', ['width' => '150px']),
                        icsb_c('signature', 'Signature', 'text', ['width' => '150px']),
                    ],
                ]),
                icsb_f('remarks', '7. Remarks', 'textarea', ['block' => '7', 'rows' => 3,
                    'help' => 'e.g. transportation needed, destination, whether a performance rating was completed.']),
                icsb_f('travel_room', '8. Travel Information: Room Overnight', 'select', [
                    'block' => '8', 'block_title' => 'Travel Information', 'col' => 3, 'options' => icsb_yes_no(),
                ]),
                icsb_f('etd', 'Estimated Time of Departure', 'text', ['block' => '8', 'col' => 3, 'help' => '24-hour clock.']),
                icsb_f('actual_release', 'Actual Release Date/Time', 'datetime-local', ['block' => '8', 'col' => 3]),
                icsb_f('destination', 'Destination', 'text', ['block' => '8', 'col' => 3]),
                icsb_f('reassign_eta', 'Estimated Time of Arrival', 'text', ['block' => '8', 'col' => 3, 'help' => '24-hour clock.']),
                icsb_f('travel_method', 'Travel Method', 'text', ['block' => '8', 'col' => 3, 'help' => 'e.g. POV, air.']),
                icsb_f('travel_contact', 'Contact Information While Traveling', 'text', ['block' => '8', 'col' => 6]),
                icsb_f('manifest', 'Manifest', 'select', ['block' => '8', 'col' => 2, 'options' => icsb_yes_no()]),
                icsb_f('manifest_number', 'Manifest Number', 'text', ['block' => '8', 'col' => 2]),
                icsb_f('reassign_area', 'Area/Agency/Region Notified', 'text', ['block' => '8', 'col' => 8,
                    'help' => 'Who was notified of the travel, with the date.']),
                icsb_f('reassigned', '9. Reassignment Information', 'select', [
                    'block' => '9', 'col' => 3, 'options' => icsb_yes_no(),
                    'help' => 'Reassigned to another incident? If so, complete the rest of this block.',
                ]),
                icsb_f('reassign_incident_name', 'Incident Name', 'text', ['block' => '9', 'col' => 3]),
                icsb_f('reassign_incident_number', 'Incident Number', 'text', ['block' => '9', 'col' => 2]),
                icsb_f('reassign_destination', 'Location', 'text', ['block' => '9', 'col' => 2, 'help' => 'City and State.']),
                icsb_f('reassign_order_number', 'Order Request Number', 'text', ['block' => '9', 'col' => 2]),
            ],
            icsb_prepared_by('10'),
            [
                icsb_f('leader_name', 'Leader Name', 'text', ['legacy' => true]),
                icsb_f('approved_unit_leader', 'Approved By (Unit Leader)', 'text', ['legacy' => true]),
                icsb_f('approved_planning', 'Approved By (Planning Section)', 'text', ['legacy' => true]),
                icsb_f('approved_logistics', 'Approved By (Logistics)', 'text', ['legacy' => true]),
            ]
        ),
    ];
}

// ── ICS 201 — Incident Briefing ─────────────────────────────────────────
function _icsb_tpl_201(): array
{
    return [
        'prefill' => ['date_initiated' => 'incident_start'],
        'fields' => array_merge(
            [
                icsb_incident_name('1', 5),
                icsb_incident_number('2', 3),
                icsb_f('date_initiated', '3. Date/Time Initiated', 'datetime-local', ['block' => '3', 'col' => 4,
                    'help' => 'Date and time (24-hour clock) the incident was initiated.']),
                icsb_f('map_sketch', '4. Map/Sketch', 'textarea', ['block' => '4', 'rows' => 5,
                    'help' => 'Describe or attach the map/sketch: total area of operations, incident site, impacted and threatened '
                        . 'areas, overflight results, trajectories, shorelines, resource assignments. North at the top; ICS map symbols.']),
                icsb_f('situation_summary', '5. Situation Summary and Health and Safety Briefing', 'textarea', ['block' => '5', 'rows' => 5,
                    'help' => 'For briefings or transfer of command: potential health and safety hazards and the measures that '
                        . 'protect responders (remove the hazard, provide PPE, warn people).']),
            ],
            icsb_prepared_by('6'),
            [
                icsb_f('objectives', '7. Current and Planned Objectives', 'textarea', ['block' => '7', 'rows' => 4,
                    'help' => 'The objectives used on the incident; note any specific problem areas.']),
                icsb_f('actions', '8. Current and Planned Actions, Strategies, and Tactics', 'table', [
                    'block' => '8', 'default_rows' => 3, 'print_rows' => 12,
                    'help' => 'Actions, strategies and tactics, and the time they may or did occur.',
                    'columns' => [
                        icsb_c('time', 'Time', 'time', ['width' => '110px']),
                        icsb_c('actions', 'Actions', 'text', ['width' => 'auto']),
                    ],
                ]),
                icsb_h('9', 'Current Organization', [
                    'help' => 'Names in each position. Under Unified Command, list each Incident Commander with their agency.',
                ]),
                icsb_f('org_ic', 'Incident Commander(s)', 'text', ['block' => '9', 'col' => 12]),
                icsb_f('org_liaison', 'Liaison Officer', 'text', ['block' => '9', 'col' => 4]),
                icsb_f('org_safety', 'Safety Officer', 'text', ['block' => '9', 'col' => 4]),
                icsb_f('org_pio', 'Public Information Officer', 'text', ['block' => '9', 'col' => 4]),
                icsb_f('org_planning', 'Planning Section Chief', 'text', ['block' => '9', 'col' => 3]),
                icsb_f('org_operations', 'Operations Section Chief', 'text', ['block' => '9', 'col' => 3]),
                icsb_f('org_finance', 'Finance/Admin Section Chief', 'text', ['block' => '9', 'col' => 3]),
                icsb_f('org_logistics', 'Logistics Section Chief', 'text', ['block' => '9', 'col' => 3]),
                icsb_f('org_other', 'Additional organization', 'textarea', ['block' => '9', 'rows' => 2,
                    'help' => 'Command staff assistants, agency representatives, branches, divisions and groups.']),
                icsb_f('resources', '10. Resource Summary', 'table', [
                    'block' => '10', 'default_rows' => 3, 'print_rows' => 12,
                    'help' => 'Resource: number and category, kind or type ordered. Identifier: agency/resource designator. '
                        . 'ETA: 24-hour clock. Tick Arrived on arrival.',
                    'columns' => [
                        icsb_c('resource', 'Resource', 'text', ['width' => '170px']),
                        icsb_c('identifier', 'Resource Identifier', 'text', ['width' => '150px']),
                        icsb_c('ordered', 'Date/Time Ordered', 'datetime-local', ['width' => '190px']),
                        icsb_c('eta', 'ETA', 'text', ['width' => '80px']),
                        icsb_c('arrived', 'Arrived', 'checkbox', ['width' => '70px']),
                        icsb_c('notes', 'Notes (location/assignment/status)', 'text', ['width' => 'auto']),
                    ],
                ]),
            ]
        ),
    ];
}

// ── ICS 203 — Organization Assignment List ──────────────────────────────
function _icsb_tpl_203(): array
{
    $f = function (string $key, string $label, string $block, int $col = 3) {
        return icsb_f($key, $label, 'text', ['block' => $block, 'col' => $col]);
    };
    return [
        'iap' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_h('3', 'Incident Commander(s) and Command Staff', [
                    'help' => 'At least first initial and last name; with Unified Command, include agency names. '
                        . 'Mark a trainee "(T)"; for a shift change, list both names separated by a slash.',
                ]),
                $f('cmd_ic', 'IC/UCs', '3', 6), $f('cmd_deputy', 'Deputy', '3', 6),
                $f('cmd_safety', 'Safety Officer', '3', 4), $f('cmd_pio', 'Public Info. Officer', '3', 4),
                $f('cmd_liaison', 'Liaison Officer', '3', 4),
                icsb_f('agency_reps', '4. Agency/Organization Representatives', 'table', [
                    'block' => '4', 'default_rows' => 2, 'print_rows' => 5,
                    'columns' => [
                        icsb_c('agency', 'Agency/Organization', 'text', ['width' => '50%']),
                        icsb_c('name', 'Name', 'text', ['width' => 'auto']),
                    ],
                ]),
                icsb_h('5', 'Planning Section'),
                $f('plan_chief', 'Chief', '5'), $f('plan_deputy', 'Deputy', '5'),
                $f('plan_resources', 'Resources Unit', '5'), $f('plan_situation', 'Situation Unit', '5'),
                $f('plan_documentation', 'Documentation Unit', '5'), $f('plan_demob', 'Demobilization Unit', '5'),
                icsb_f('plan_tech', 'Technical Specialists', 'text', ['block' => '5', 'col' => 6, 'help' => 'With each one\'s specialty.']),
                icsb_h('6', 'Logistics Section'),
                $f('log_chief', 'Chief', '6'), $f('log_deputy', 'Deputy', '6'),
                $f('log_support_director', 'Support Branch: Director', '6', 6),
                $f('log_supply', 'Supply Unit', '6', 4), $f('log_facilities', 'Facilities Unit', '6', 4),
                $f('log_ground', 'Ground Support Unit', '6', 4),
                $f('log_service_director', 'Service Branch: Director', '6', 12),
                $f('log_comms', 'Communications Unit', '6', 4), $f('log_medical', 'Medical Unit', '6', 4),
                $f('log_food', 'Food Unit', '6', 4),
                icsb_h('7', 'Operations Section', [
                    'help' => 'Branches and Divisions/Groups may be named for function or geography. For a Division/Group, '
                        . 'put its identifier in the Position column and the supervisor\'s name beside it.',
                ]),
                $f('ops_chief', 'Chief', '7', 4), $f('ops_deputy', 'Deputy', '7', 4), $f('ops_staging', 'Staging Area', '7', 4),
                icsb_f('ops_branches', 'Branches and Divisions/Groups', 'table', [
                    'block' => '7', 'default_rows' => 3, 'print_rows' => 9,
                    'columns' => [
                        icsb_c('branch', 'Branch', 'text', ['width' => '160px']),
                        icsb_c('position', 'Branch Director, Deputy, or Division/Group', 'text', ['width' => '280px']),
                        icsb_c('name', 'Name', 'text', ['width' => 'auto']),
                    ],
                ]),
                $f('ops_air_director', 'Air Operations Branch: Air Ops Branch Dir.', '7', 6),
                icsb_h('8', 'Finance/Administration Section'),
                $f('fin_chief', 'Chief', '8', 4), $f('fin_deputy', 'Deputy', '8', 4), $f('fin_time', 'Time Unit', '8', 4),
                $f('fin_procurement', 'Procurement Unit', '8', 4), $f('fin_comp', 'Comp/Claims Unit', '8', 4),
                $f('fin_cost', 'Cost Unit', '8', 4),
            ],
            icsb_prepared_by('9')
        ),
    ];
}

// ── ICS 204 — Assignment List ───────────────────────────────────────────
function _icsb_tpl_204(): array
{
    return [
        'iap' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('branch', '3. Branch', 'text', ['block' => '3', 'block_title' => 'Branch, Division, Group, Staging Area', 'col' => 3,
                    'help' => 'For reference in a large IAP, e.g. Branch 1, Division D, Group 1A.']),
                icsb_f('division', 'Division', 'text', ['block' => '3', 'col' => 3]),
                icsb_f('group', 'Group', 'text', ['block' => '3', 'col' => 3]),
                icsb_f('staging_area', 'Staging Area', 'text', ['block' => '3', 'col' => 3]),
                icsb_f('operations_personnel', '4. Operations Personnel', 'table', [
                    'block' => '4', 'row_label_header' => 'Position',
                    'fixed_rows' => ['Operations Section Chief', 'Branch Director', 'Division/Group Supervisor'],
                    'columns' => [
                        icsb_c('name', 'Name', 'text', ['width' => 'auto']),
                        icsb_c('contact', 'Contact Number(s)', 'text', ['width' => '260px']),
                    ],
                ]),
                icsb_f('resources_assigned', '5. Resources Assigned', 'table', [
                    'block' => '5', 'default_rows' => 3, 'print_rows' => 8,
                    'help' => 'Resource identifier (e.g. ENG-13; TBD if not yet known), leader, number of persons including the '
                        . 'leader, primary contact with area code, and notes: reporting location, special equipment, briefings, transport.',
                    'columns' => [
                        icsb_c('resource_id', 'Resource Identifier', 'text', ['width' => '140px']),
                        icsb_c('leader', 'Leader', 'text', ['width' => '150px']),
                        icsb_c('persons', '# of Persons', 'number', ['width' => '80px']),
                        icsb_c('contact', 'Contact (e.g., phone, pager, radio frequency, etc.)', 'text', ['width' => '210px']),
                        icsb_c('notes', 'Reporting Location, Special Equipment and Supplies, Remarks, Notes, Information', 'text', ['width' => 'auto']),
                    ],
                ]),
                icsb_f('work_assignments', '6. Work Assignments', 'textarea', ['block' => '6', 'rows' => 4,
                    'help' => 'The tactical objectives for this Division or Group in the operational period.']),
                icsb_f('special_instructions', '7. Special Instructions', 'textarea', ['block' => '7', 'rows' => 3,
                    'help' => 'Safety problems, precautions, drop-off or pick-up points, other important information.']),
                icsb_f('communications', '8. Communications (radio and/or phone contact numbers needed for this assignment)', 'table', [
                    'block' => '8', 'default_rows' => 3, 'print_rows' => 4,
                    'help' => 'For radios: function, frequency, system and channel from the ICS 205. Phones with area code. '
                        . 'Take care with cell numbers in a widely distributed IAP.',
                    'columns' => [
                        icsb_c('name_function', 'Name/Function', 'text', ['width' => '260px']),
                        icsb_c('primary_contact', 'Primary Contact: indicate cell, pager, or radio (frequency/system/channel)', 'text', ['width' => 'auto']),
                    ],
                ]),
            ],
            icsb_prepared_by('9')
        ),
    ];
}

// ── ICS 207 — Incident Organization Chart ───────────────────────────────
// Printed as a chart (_icsp_org_chart_207()); the editor lists the boxes.
function _icsb_tpl_207(): array
{
    $box = function (string $key, string $label, int $col = 3) {
        return icsb_f($key, $label, 'text', ['block' => '3', 'col' => $col, 'chart_box' => true]);
    };
    return [
        'landscape' => true,
        'iap' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_h('3', 'Organization Chart', [
                    'print_chart' => '207',
                    'help' => 'Fill only the positions activated; at least first initial and last name; agency for Unified '
                        . 'Commanders; for a shift change, both names separated by a slash.',
                ]),
                $box('ic', 'Incident Commander(s)', 6),
                $box('liaison', 'Liaison Officer', 2), $box('safety', 'Safety Officer', 2), $box('pio', 'Public Information Officer', 2),
                $box('ops_chief', 'Operations Section Chief', 6), $box('staging', 'Staging Area Manager', 6),
                icsb_f('ops_elements', 'Operations: Branches, Divisions and Groups', 'table', [
                    'block' => '3', 'default_rows' => 4, 'chart_box' => true,
                    'columns' => [
                        icsb_c('element', 'Branch/Division/Group', 'text', ['width' => '45%']),
                        icsb_c('name', 'Name', 'text', ['width' => 'auto']),
                    ],
                ]),
                $box('planning_chief', 'Planning Section Chief'), $box('resources_ldr', 'Resources Unit Ldr.'),
                $box('situation_ldr', 'Situation Unit Ldr.'), $box('documentation_ldr', 'Documentation Unit Ldr.'),
                $box('demob_ldr', 'Demobilization Unit Ldr.'), $box('planning_other', 'Planning: other position'),
                $box('logistics_chief', 'Logistics Section Chief'), $box('support_dir', 'Support Branch Dir.'),
                $box('supply_ldr', 'Supply Unit Ldr.'), $box('facilities_ldr', 'Facilities Unit Ldr.'),
                $box('ground_ldr', 'Ground Spt. Unit Ldr.'), $box('service_dir', 'Service Branch Dir.'),
                $box('comms_ldr', 'Comms Unit Ldr.'), $box('medical_ldr', 'Medical Unit Ldr.'),
                $box('food_ldr', 'Food Unit Ldr.'),
                $box('finance_chief', 'Finance/Admin Section Chief'), $box('time_ldr', 'Time Unit Ldr.'),
                $box('procurement_ldr', 'Procurement Unit Ldr.'), $box('comp_ldr', 'Comp./Claims Unit Ldr.'),
                $box('cost_ldr', 'Cost Unit Ldr.'), $box('finance_other', 'Finance/Admin: other position'),
            ],
            icsb_prepared_by('4')
        ),
    ];
}

// ── ICS 208 — Safety Message/Plan ───────────────────────────────────────
function _icsb_tpl_208(): array
{
    return [
        'iap' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('safety_message', '3. Safety Message/Expanded Safety Message, Safety Plan, Site Safety Plan', 'textarea', [
                    'block' => '3', 'rows' => 10,
                    'help' => 'Safety messages, priorities, key command emphasis and decisions; known hazards and the precautions '
                        . 'to observe this operational period. Reference and attach further messages if needed.',
                ]),
                icsb_f('site_safety_plan_required', '4. Site Safety Plan Required?', 'select', [
                    'block' => '4', 'col' => 4, 'options' => icsb_yes_no(),
                ]),
                icsb_f('site_safety_plan_location', 'Approved Site Safety Plan(s) Located At', 'text', ['block' => '4', 'col' => 8]),
            ],
            icsb_prepared_by('5')
        ),
    ];
}

// ── ICS 208 HM — Site Safety and Control Plan ───────────────────────────
function _icsb_tpl_208hm(): array
{
    $t = function (string $key, string $label, string $block, int $col = 4, array $extra = []) {
        return icsb_f($key, $label, 'text', array_merge(['block' => $block, 'col' => $col], $extra));
    };
    $yn = function (string $key, string $label, string $block, int $col = 4, array $extra = []) {
        return icsb_f($key, $label, 'select', array_merge(['block' => $block, 'col' => $col, 'options' => icsb_yes_no()], $extra));
    };
    $comment = function (string $key, string $block, string $help = '') {
        return icsb_f($key, 'Comment', 'textarea', array_merge(['block' => $block, 'rows' => 2], $help !== '' ? ['help' => $help] : []));
    };
    $team = function (string $key, string $label, string $block, string $prefix, string $help) {
        return icsb_f($key, $label, 'table', [
            'block' => $block, 'row_label_header' => '', 'help' => $help,
            'fixed_rows' => [$prefix . ' 1', $prefix . ' 2', $prefix . ' 3', $prefix . ' 4'],
            'columns' => [
                icsb_c('name', 'Name', 'text', ['width' => 'auto']),
                icsb_c('ppe', 'PPE Level', 'text', ['width' => '140px']),
            ],
        ]);
    };
    $mat = [];
    foreach ([['material', 'Material', '160px'], ['container', 'Container type', '110px'], ['qty', 'Qty.', '60px'],
              ['phys_state', 'Phys. State', '80px'], ['ph', 'pH', '50px'], ['idlh', 'IDLH', '60px'], ['fp', 'F.P.', '55px'],
              ['it', 'I.T.', '55px'], ['vp', 'V.P.', '55px'], ['vd', 'V.D.', '55px'], ['sg', 'S.G.', '55px'],
              ['lel', 'LEL', '55px'], ['uel', 'UEL', '55px']] as [$k, $l, $w]) {
        $mat[] = icsb_c($k, $l, 'text', ['width' => $w]);
    }
    return [
        'print_title' => 'SITE SAFETY AND CONTROL PLAN',
        'fields' => array_merge(
            [
                icsb_f('incident_name', '1. Incident Name', 'text', ['block' => '1', 'col' => 6, 'required' => true,
                    'help' => 'Name and/or incident number.']),
                icsb_f('date_prepared', '2. Date Prepared', 'datetime-local', ['block' => '2', 'col' => 6,
                    'help' => 'Date and time prepared.']),
            ],
            icsb_op_period('3', 6),
            [
                icsb_h('', 'Section I. Site Information'),
                $t('incident_location', '4. Incident Location', '4', 12, ['help' => 'Address and/or map coordinates.']),
                icsb_h('', 'Section II. Organization', [
                    'help' => 'Names of everyone assigned to these ICS positions; 5 and 8 are mandatory. Use 15 and 16 for other functions, e.g. Medical Monitoring.',
                ]),
                $t('org_ic', '5. Incident Commander', '5'), $t('org_hm_sup', '6. HM Group Supervisor', '6'),
                $t('org_tech_spec', '7. Tech. Specialist - HM Reference', '7'), $t('org_safety', '8. Safety Officer', '8'),
                $t('org_entry_leader', '9. Entry Leader', '9'), $t('org_access', '10. Site Access Control Leader', '10'),
                $t('org_asst_safety', '11. Asst. Safety Officer - HM', '11'), $t('org_decon_leader', '12. Decontamination Leader', '12'),
                $t('org_refuge', '13. Safe Refuge Area Mgr', '13'), $t('org_env_health', '14. Environmental Health', '14'),
                $t('org_other1', '15. Other Function (function and name)', '15', 4, ['block_title' => 'Other Function']),
                $t('org_other2', '16. Other Function (function and name)', '16', 4, ['block_title' => 'Other Function']),
                $team('entry_team', '17. Entry Team (Buddy System)', '17', 'Entry', 'Names and PPE level; entries 1-4 are mandatory (buddy system and back-up).'),
                $team('decon_element', '18. Decontamination Element', '18', 'Decon', 'Names and PPE level of the decontamination personnel.'),
                icsb_h('', 'Section III. Hazard/Risk Analysis'),
                icsb_f('materials', '19. Material', 'table', [
                    'block' => '19', 'default_rows' => 2, 'print_rows' => 4, 'columns' => $mat,
                    'help' => 'Every known product (UNK if unknown). pH = corrosivity; IDLH = Immediately Dangerous to Life and Health; '
                        . 'F.P. = flash point; I.T. = ignition temperature; V.P. = vapor pressure; V.D. = vapor density; '
                        . 'S.G. = specific gravity; LEL/UEL = lower/upper explosive limit.',
                ]),
                $comment('hazard_comment', '19'),
                icsb_h('', 'Section IV. Hazard Monitoring', ['help' => 'The instruments that will monitor for the chemical.']),
                $t('mon_lel', '20. LEL Instrument(s)', '20', 6), $t('mon_o2', '21. O2 Instrument(s)', '21', 6),
                $t('mon_tox', '22. Toxicity/PPM Instrument(s)', '22', 6), $t('mon_rad', '23. Radiological Instrument(s)', '23', 6),
                $comment('monitoring_comment', '23'),
                icsb_h('', 'Section V. Decontamination Procedures'),
                $yn('decon_standard', '24. Standard Decontamination Procedures', '24', 4, [
                    'help' => 'No = the standard procedures are modified; describe the changes, including solutions, in Comment.',
                ]),
                $comment('decon_comment', '24'),
                icsb_h('', 'Section VI. Site Communications'),
                $t('freq_command', '25. Command Frequency', '25'), $t('freq_tactical', '26. Tactical Frequency', '26'),
                $t('freq_entry', '27. Entry Frequency', '27'),
                icsb_h('', 'Section VII. Medical Assistance'),
                $yn('med_monitoring', '28. Medical Monitoring', '28', 6),
                $yn('med_treatment', '29. Medical Treatment and Transport In-place', '29', 6),
                $comment('medical_comment', '29', 'Required when either answer is No.'),
                icsb_h('', 'Section VIII. Site Map'),
                icsb_f('site_map', '30. Site Map', 'textarea', ['block' => '30', 'rows' => 4,
                    'help' => 'Sketch or attach a site map defining every location and the layout of the operational zones. '
                        . 'Identifying each item below is mandatory.']),
            ],
            array_map(function ($p) {
                return icsb_f('map_' . $p[0], $p[1], 'checkbox', ['block' => '30', 'col' => 2]);
            }, [['weather', 'Weather'], ['command_post', 'Command Post'], ['zones', 'Zones'],
                ['assembly', 'Assembly Areas'], ['escape', 'Escape Routes'], ['other', 'Other']]),
            [
                icsb_h('', 'Section IX. Entry Objectives'),
                icsb_f('entry_objectives', '31. Entry Objectives', 'textarea', ['block' => '31', 'rows' => 4,
                    'help' => 'Everything the Entry Team will do in the Exclusion Zone, and anything that would change or stop entry.']),
                icsb_h('', 'Section X. SOPs and Safe Work Practices'),
                $yn('sop_modifications', '32. Modifications to Documented SOPs or Work Practices', '32', 6),
                $comment('sop_comment', '32'),
                icsb_h('', 'Section XI. Emergency Procedures'),
                icsb_f('emergency_procedures', '33. Emergency Procedures', 'textarea', ['block' => '33', 'rows' => 3,
                    'help' => 'Procedures affected if an emergency occurs while personnel are in the Exclusion Zone.']),
                icsb_h('', 'Section XII. Safety Briefing', [
                    'help' => 'Each person signs the printed plan once it has been reviewed; note the time the safety briefing was completed.',
                ]),
                icsb_sig('34', '34. Asst. Safety Officer - HM Signature', 4),
                icsb_f('briefing_time', 'Safety Briefing Completed (Time)', 'time', ['block' => '34', 'col' => 4]),
                icsb_sig('35', '35. HM Group Supervisor Signature', 4),
                icsb_sig('36', '36. Incident Commander Signature', 4),
            ]
        ),
    ];
}

// ── ICS 209 — Incident Status Summary ───────────────────────────────────
// FEMA marks the required-when-applicable blocks with "*"; here they are
// `required` (the editor's red asterisk).
function _icsb_tpl_209(): array
{
    $count = function (string $key, string $label, string $block, array $rows, string $help) {
        return icsb_f($key, $label, 'table', [
            'block' => $block, 'required' => true, 'fixed_rows' => $rows, 'row_label_header' => '', 'help' => $help,
            'columns' => [
                icsb_c('this_period', 'A. # This Reporting Period', 'text', ['width' => '170px']),
                icsb_c('to_date', 'B. Total # to Date', 'text', ['width' => '170px']),
            ],
        ]);
    };
    $horizon = function (string $key, string $label, string $block, string $col, string $help) {
        return icsb_f($key, $label, 'table', [
            'block' => $block, 'row_label_header' => '', 'help' => $help,
            'fixed_rows' => ['12 hours', '24 hours', '48 hours', '72 hours', 'Anticipated after 72 hours'],
            'columns' => [icsb_c('text', $col, 'text', ['width' => 'auto'])],
        ]);
    };
    $req = ['required' => true];
    return [
        'prefill' => [
            'start_datetime' => 'incident_start', 'incident_definition' => 'incident_type',
            'state' => 'incident_state', 'city' => 'incident_city', 'short_location' => 'incident_location',
        ],
        'fields' => [
            icsb_f('incident_name', '1. Incident Name', 'text', ['block' => '1', 'col' => 4] + $req),
            icsb_incident_number('2', 3),
            icsb_f('report_version', '3. Report Version', 'select', ['block' => '3', 'col' => 3, 'required' => true,
                'options' => ['Initial', 'Update', 'Final', 'Initial and Final'],
                'help' => 'Initial = first report; Update = a later one; Final = the last. Only one report? Initial and Final.']),
            icsb_f('report_number', 'Rpt # (if used)', 'text', ['block' => '3', 'col' => 2]),
            icsb_f('incident_commander', '4. Incident Commander(s) & Agency or Organization', 'text', ['block' => '4', 'col' => 6,
                'help' => 'First and last name; for Unified Command, each IC by first initial and last name with their organization.'] + $req),
            icsb_f('management_org', '5. Incident Management Organization', 'text', ['block' => '5', 'col' => 6,
                'help' => 'e.g. Type 1, 2 or 3 IMT, Unified Command, Unified Command with an IMT. Leave blank if none is assigned.']),
            icsb_f('start_datetime', '6. Incident Start Date/Time', 'datetime-local', ['block' => '6', 'col' => 4,
                'help' => 'When the incident started (not the report or the operational period).'] + $req),
            icsb_f('start_timezone', 'Time Zone', 'text', ['block' => '6', 'col' => 2, 'help' => 'e.g. CDT.']),
            icsb_f('incident_size', '7. Current Incident Size or Area Involved', 'text', ['block' => '7', 'col' => 4,
                'help' => 'With its unit, e.g. "sq mi", "city block"; say if estimated.']),
            icsb_f('percent', '8. Percent (%) Contained or Completed', 'text', ['block' => '8', 'col' => 2]),
            icsb_f('percent_type', 'Contained or Completed', 'select', ['block' => '8', 'col' => 2,
                'options' => ['Contained', 'Completed']]),
            icsb_f('incident_definition', '9. Incident Definition', 'text', ['block' => '9', 'col' => 4,
                'help' => 'A general category, e.g. tornado, wildfire, bridge collapse, parade, mass casualty.'] + $req),
            icsb_f('complexity_level', '10. Incident Complexity Level', 'text', ['block' => '10', 'col' => 4]),
            icsb_f('period_from', '11. For Time Period: From Date/Time', 'datetime-local', ['block' => '11', 'block_title' => 'For Time Period', 'col' => 4,
                'help' => 'Everything since the last ICS 209 (or since the incident started, for the first one).'] + $req),
            icsb_f('period_to', 'To Date/Time', 'datetime-local', ['block' => '11', 'col' => 4]),
            icsb_h('', 'Approval & Routing Information'),
            icsb_f('prepared_by', '12. Prepared By: Print Name', 'text', ['block' => '12', 'block_title' => 'Prepared By', 'col' => 4,
                'help' => 'Usually the Situation Unit Leader or Planning Section Chief, or a dispatcher.'] + $req),
            icsb_f('prepared_by_position', 'ICS Position', 'text', ['block' => '12', 'col' => 4]),
            icsb_f('prepared_datetime', 'Date/Time Prepared', 'datetime-local', ['block' => '12', 'col' => 4]),
            icsb_f('submitted_datetime', '13. Date/Time Submitted', 'datetime-local', ['block' => '13', 'col' => 4] + $req),
            icsb_f('submitted_timezone', 'Time Zone', 'text', ['block' => '13', 'col' => 2]),
            icsb_f('approved_by', '14. Approved By: Print Name', 'text', ['block' => '14', 'block_title' => 'Approved By', 'col' => 3,
                'help' => 'Usually the Planning Section Chief or Incident Commander.'] + $req),
            icsb_f('approved_by_position', 'ICS Position', 'text', ['block' => '14', 'col' => 3]),
            icsb_sig('14', 'Signature', 6),
            icsb_f('sent_to', '15. Primary Location, Organization, or Agency Sent To', 'text', ['block' => '15'] + $req),
            icsb_h('', 'Incident Location Information', [
                'help' => 'Most of blocks 16-26 are optional, but the more that is filled in, the better. Label every coordinate format.',
            ]),
            icsb_f('state', '16. State', 'text', ['block' => '16', 'col' => 2] + $req),
            icsb_f('county', '17. County/Parish/Borough', 'text', ['block' => '17', 'col' => 3] + $req),
            icsb_f('city', '18. City', 'text', ['block' => '18', 'col' => 3] + $req),
            icsb_f('unit_other', '19. Unit or Other', 'text', ['block' => '19', 'col' => 4]),
            icsb_f('jurisdiction', '20. Incident Jurisdiction', 'text', ['block' => '20', 'col' => 6] + $req),
            icsb_f('ownership', '21. Incident Location Ownership (if different than jurisdiction)', 'text', ['block' => '21', 'col' => 6]),
            icsb_f('longitude', '22. Longitude (indicate format)', 'text', ['block' => '22', 'block_title' => 'Longitude/Latitude', 'col' => 3]),
            icsb_f('latitude', 'Latitude (indicate format)', 'text', ['block' => '22', 'col' => 3]),
            icsb_f('usng', '23. US National Grid Reference', 'text', ['block' => '23', 'col' => 3]),
            icsb_f('legal_description', '24. Legal Description (township, section, range)', 'text', ['block' => '24', 'col' => 3]),
            icsb_f('short_location', '25. Short Location or Area Description', 'textarea', ['block' => '25', 'rows' => 2,
                'help' => 'List all affected areas, or a reference point, e.g. "within a 5 mile radius of Walden, CO".'] + $req),
            icsb_f('utm', '26. UTM Coordinates', 'text', ['block' => '26', 'col' => 4]),
            icsb_f('geospatial', '27. Note any electronic geospatial data included or attached', 'text', ['block' => '27', 'col' => 8,
                'help' => 'Format (e.g. .kml, .shp), content and collection time; keep files small.']),
            icsb_h('', 'Incident Summary'),
            icsb_f('significant_events', '28. Significant Events for the Time Period Reported', 'textarea', ['block' => '28', 'rows' => 4,
                'help' => 'Progress, evacuations, incident growth, road closures, command transitions, containment.'] + $req),
            icsb_f('primary_hazards', '29. Primary Materials or Hazards Involved', 'textarea', ['block' => '29', 'rows' => 2,
                'help' => 'Hazardous chemicals, fuel types, infectious agents, radiation, etc.']),
            icsb_f('damage_assessment', '30. Damage Assessment Information', 'textarea', ['block' => '30', 'rows' => 3,
                'help' => 'Damage and restriction of use or availability of property, natural resources, critical infrastructure and key resources.']),
            icsb_f('structures', 'A. Structural Summary', 'table', [
                'block' => '30', 'row_label_header' => '',
                'fixed_rows' => ['E. Single Residences', 'F. Nonresidential Commercial Property', 'Other Minor Structures', 'Other'],
                'help' => 'Note whether the numbers are estimates or confirmed.',
                'columns' => [
                    icsb_c('threatened', 'B. # Threatened (72 hrs)', 'text', ['width' => '160px']),
                    icsb_c('damaged', 'C. # Damaged', 'text', ['width' => '160px']),
                    icsb_c('destroyed', 'D. # Destroyed', 'text', ['width' => '160px']),
                ],
            ]),
            icsb_h('', 'Additional Incident Decision Support Information'),
            $count('public_status', '31. Public Status Summary', '31', [
                'D. Fatalities', 'E. With Injuries/Illness', 'F. Trapped/In Need of Rescue', 'G. Missing (note if estimated)',
                'H. Evacuated (note if estimated)', 'I. Sheltering in Place (note if estimated)',
                'J. In Temporary Shelters (note if est.)', 'K. Have Received Mass Immunizations',
                'L. Require Immunizations (note if est.)', 'M. In Quarantine', 'N. Total # Civilians (Public) Affected',
            ], 'C. Number of civilians (public) in each category. Mark estimates "est."; never estimate fatalities. '
                . 'Coordinate release of this information with Public Information.'),
            $count('responder_status', '32. Responder Status Summary', '32', [
                'D. Fatalities', 'E. With Injuries/Illness', 'F. Trapped/In Need of Rescue', 'G. Missing',
                'H. Sheltering in Place', 'I. Have Received Immunizations', 'J. Require Immunizations', 'K. In Quarantine',
                'N. Total # Responders Affected',
            ], 'C. Number of responders in each category.'),
            icsb_f('lsh_remarks', '33. Life, Safety, and Health Status/Threat Remarks', 'textarea', ['block' => '33', 'rows' => 3]),
            icsb_f('threat_management', '34. Life, Safety, and Health Threat Management', 'table', [
                'block' => '34', 'required' => true, 'row_label_header' => '',
                'help' => 'Tick each item that is active now; note details here or in block 33.',
                'fixed_rows' => ['No Likely Threat', 'Potential Future Threat', 'Mass Notifications in Progress',
                    'Mass Notifications Completed', 'No Evacuation(s) Imminent', 'Planning for Evacuation',
                    'Planning for Shelter-in-Place', 'Evacuation(s) in Progress', 'Shelter-in-Place in Progress',
                    'Repopulation in Progress', 'Mass Immunization in Progress', 'Mass Immunization Complete',
                    'Quarantine in Progress', 'Area Restriction in Effect'],
                'columns' => [
                    icsb_c('active', 'Check if Active', 'checkbox', ['width' => '120px']),
                    icsb_c('notes', 'Notes', 'text', ['width' => 'auto']),
                ],
            ]),
            icsb_f('weather', '35. Weather Concerns', 'textarea', ['block' => '35', 'rows' => 3,
                'help' => 'Current and predicted weather, and related factors that may cause concern.']),
            $horizon('projected_activity', '36. Projected Incident Activity, Potential, Movement, Escalation, or Spread', '36', 'Projected activity',
                'And influencing factors, for the next operational period and in 12-, 24-, 48- and 72-hour timeframes.'),
            icsb_f('strategic_objectives', '37. Strategic Objectives', 'textarea', ['block' => '37', 'rows' => 3,
                'help' => 'Define the planned end-state for the incident.']),
            $horizon('threat_summary', '38. Current Incident Threat Summary and Risk Information', '38', 'Threats and risks',
                'Primary threats to life, property, communities, critical infrastructure, natural and cultural resources and '
                . 'continuity of operations, with potential economic or cascading impacts.'),
            $horizon('critical_needs', '39. Critical Resource Needs', '39', 'Critical resource needs',
                'Category, kind and/or type and amount needed to meet critical objectives, in priority order.'),
            icsb_f('strategic_discussion', '40. Strategic Discussion', 'textarea', ['block' => '40', 'rows' => 4,
                'help' => 'How overall strategy, constraints and information relate to the critical needs, the IAP and anticipated '
                    . 'results; major problems and concerns.']),
            icsb_f('planned_actions', '41. Planned Actions for Next Operational Period', 'textarea', ['block' => '41', 'rows' => 3]),
            icsb_f('projected_final_size', '42. Projected Final Incident Size/Area', 'text', ['block' => '42', 'col' => 6,
                'help' => 'With its unit, e.g. "sq mi".']),
            icsb_f('anticipated_completion', '43. Anticipated Incident Management Completion Date', 'date', ['block' => '43', 'col' => 6]),
            icsb_f('demob_start', '44. Projected Significant Resource Demobilization Start Date', 'date', ['block' => '44', 'col' => 4]),
            icsb_f('costs_to_date', '45. Estimated Incident Costs to Date', 'text', ['block' => '45', 'col' => 4]),
            icsb_f('projected_cost', '46. Projected Final Incident Cost Estimate', 'text', ['block' => '46', 'col' => 4]),
            icsb_f('remarks', '47. Remarks', 'textarea', ['block' => '47', 'rows' => 4,
                'help' => 'Or continuation of any block above (give the block number).']),
            icsb_f('resource_commitment', 'Incident Resource Commitment Summary', 'table', [
                'default_rows' => 3, 'print_rows' => 8,
                'help' => 'One row per agency and resource kind, e.g. "Type 2 Helicopters": 3 resources, 8 personnel. '
                    . 'Total Personnel includes individual overhead.',
                'columns' => [
                    icsb_c('agency', 'Agency or Organization', 'text', ['width' => '200px', 'block' => '48', 'block_title' => 'Agency or Organization']),
                    icsb_c('resource', 'Resource (category, kind and/or type)', 'text', ['width' => 'auto', 'block' => '49', 'block_title' => 'Resources']),
                    icsb_c('resources_count', '# of Resources', 'number', ['width' => '100px', 'block' => '49']),
                    icsb_c('personnel_count', '# of Personnel', 'number', ['width' => '100px', 'block' => '49']),
                    icsb_c('additional_personnel', 'Additional Personnel not assigned to a resource', 'number', ['width' => '140px', 'block' => '50']),
                    icsb_c('total_personnel', 'Total Personnel', 'number', ['width' => '110px', 'block' => '51']),
                ],
            ]),
            icsb_f('total_resources', '52. Total Resources', 'text', ['block' => '52', 'col' => 6,
                'help' => 'The resources in block 49 (their personnel are counted in block 51).']),
            icsb_f('cooperating_orgs', '53. Additional Cooperating and Assisting Organizations Not Listed Above', 'textarea', [
                'block' => '53', 'rows' => 2, 'help' => 'e.g. ambulance services, Red Cross, utility companies.']),
        ],
    ];
}

// ── ICS 210 — Resource Status Change ────────────────────────────────────
function _icsb_tpl_210(): array
{
    return [
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('status_changes', 'Resource Status Changes', 'table', [
                    'default_rows' => 3, 'print_rows' => 12,
                    'help' => 'One row per change. Out of service (O/S): say why (Mech, Rest, Pers) and the estimated time of '
                        . 'return in Comments. Name the specific location (e.g. Division A, Staging Area, ICP).',
                    'columns' => [
                        icsb_c('resource_number', 'Resource Number', 'text', ['width' => '130px', 'block' => '3']),
                        icsb_c('new_status', 'New Status (Available, Assigned, O/S)', 'select', ['width' => '170px', 'block' => '4',
                            'options' => ['Available', 'Assigned', 'O/S - Mech', 'O/S - Rest', 'O/S - Pers']]),
                        icsb_c('from_status', 'From (Assignment and Status)', 'text', ['width' => 'auto', 'block' => '5']),
                        icsb_c('to_status', 'To (Assignment and Status)', 'text', ['width' => 'auto', 'block' => '6']),
                        icsb_c('change_time', 'Time and Date of Change', 'datetime-local', ['width' => '190px', 'block' => '7']),
                    ],
                ]),
                icsb_f('comments', '8. Comments', 'textarea', ['block' => '8', 'rows' => 3,
                    'help' => 'e.g. why a resource is out of service, Strike Team or Task Force member IDs.']),
            ],
            icsb_prepared_by('9')
        ),
    ];
}

// ── ICS 211 — Incident Check-In List ────────────────────────────────────
function _icsb_tpl_211(): array
{
    return [
        'landscape' => true,
        'prefill' => ['start_datetime' => 'now'],
        'fields' => array_merge(
            [
                icsb_incident_name('1', 6),
                icsb_incident_number('2', 6),
                icsb_f('checkin_location', '3. Check-In Location (complete all that apply)', 'table', [
                    'block' => '3', 'block_title' => 'Check-In Location', 'row_label_header' => '',
                    'fixed_rows' => ['Base', 'Staging Area', 'ICP', 'Helibase', 'Other'],
                    'columns' => [
                        icsb_c('used', 'Check', 'checkbox', ['width' => '70px']),
                        icsb_c('location', 'Location', 'text', ['width' => 'auto']),
                    ],
                ]),
                icsb_f('start_datetime', '4. Start Date/Time', 'datetime-local', ['block' => '4', 'col' => 4,
                    'help' => 'When this list was started.']),
                icsb_f('checkins', 'Check-In Information', 'table', [
                    'default_rows' => 3, 'print_rows' => 10,
                    'help' => 'List single-resource personnel (overhead) by agency and name, or resources by State, agency, '
                        . 'category, kind, type and identifier. For a Strike Team (ST) or Task Force (TF), put its ID on one line '
                        . 'and its members on the following lines. Data Provided to Resources Unit: date, time and initials.',
                    'columns' => [
                        icsb_c('state', 'State', 'text', ['width' => '60px', 'block' => '5',
                            'block_title' => 'List single resource personnel (overhead) by agency and name, OR list resources by the following format']),
                        icsb_c('agency', 'Agency', 'text', ['width' => '90px', 'block' => '5']),
                        icsb_c('category', 'Category', 'text', ['width' => '100px', 'block' => '5']),
                        icsb_c('kind', 'Kind', 'text', ['width' => '90px', 'block' => '5']),
                        icsb_c('type', 'Type', 'text', ['width' => '70px', 'block' => '5']),
                        icsb_c('resource_name', 'Resource Name or Identifier', 'text', ['width' => '140px', 'block' => '5']),
                        icsb_c('st_tf', 'ST or TF', 'select', ['width' => '70px', 'block' => '5', 'options' => ['ST', 'TF']]),
                        icsb_c('order_request', 'Order Request #', 'text', ['width' => '100px', 'block' => '6']),
                        icsb_c('checkin_datetime', 'Date/Time Check-In', 'datetime-local', ['width' => '180px', 'block' => '7']),
                        icsb_c('leader_name', "Leader's Name", 'text', ['width' => '130px', 'block' => '8']),
                        icsb_c('total_personnel', 'Total Number of Personnel', 'number', ['width' => '90px', 'block' => '9']),
                        icsb_c('contact', 'Incident Contact Information', 'text', ['width' => '140px', 'block' => '10']),
                        icsb_c('home_unit', 'Home Unit or Agency', 'text', ['width' => '120px', 'block' => '11']),
                        icsb_c('departure', 'Departure Point, Date and Time', 'text', ['width' => '160px', 'block' => '12']),
                        icsb_c('travel_method', 'Method of Travel', 'text', ['width' => '110px', 'block' => '13']),
                        icsb_c('assignment', 'Incident Assignment', 'text', ['width' => '130px', 'block' => '14']),
                        icsb_c('qualifications', 'Other Qualifications', 'text', ['width' => '130px', 'block' => '15']),
                        icsb_c('data_provided', 'Data Provided to Resources Unit', 'text', ['width' => '130px', 'block' => '16']),
                    ],
                ]),
                icsb_f('remarks', 'Remarks or comments', 'textarea', ['rows' => 2,
                    'help' => 'FEMA uses the reverse of the form for remarks.']),
            ],
            icsb_prepared_by('17')
        ),
    ];
}

// ── ICS 215 — Operational Planning Worksheet ────────────────────────────
function _icsb_tpl_215(): array
{
    return [
        'landscape' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('work_assignments', 'Work Assignments and Resources', 'table', [
                    'default_rows' => 3, 'print_rows' => 10,
                    'help' => 'One row per kind of resource an assignment needs (repeat the Branch and Division for each kind). '
                        . 'Need = Required minus Have. Requested arrival time on the 24-hour clock.',
                    'columns' => [
                        icsb_c('branch', 'Branch', 'text', ['width' => '90px', 'block' => '3']),
                        icsb_c('division', 'Division, Group, or Other', 'text', ['width' => '110px', 'block' => '4']),
                        icsb_c('work_assignment', 'Work Assignment & Special Instructions', 'text', ['width' => 'auto', 'block' => '5']),
                        icsb_c('resource', 'Resource (category/kind/type)', 'text', ['width' => '140px', 'block' => '6', 'block_title' => 'Resources']),
                        icsb_c('required', 'Req.', 'number', ['width' => '65px', 'block' => '6']),
                        icsb_c('have', 'Have', 'number', ['width' => '65px', 'block' => '6']),
                        icsb_c('need', 'Need', 'number', ['width' => '65px', 'block' => '6']),
                        icsb_c('overhead', 'Overhead Position(s)', 'text', ['width' => '120px', 'block' => '7']),
                        icsb_c('equipment', 'Special Equipment & Supplies', 'text', ['width' => '130px', 'block' => '8']),
                        icsb_c('reporting_location', 'Reporting Location', 'text', ['width' => '120px', 'block' => '9']),
                        icsb_c('arrival_time', 'Requested Arrival Time', 'time', ['width' => '110px', 'block' => '10']),
                    ],
                ]),
                icsb_f('totals', 'Totals by resource', 'table', [
                    'default_rows' => 2, 'print_rows' => 4,
                    'help' => 'Per category/kind/type. A slash can separate single resources from Strike Teams/Task Forces, e.g. 4/1.',
                    'columns' => [
                        icsb_c('resource', 'Resource (category/kind/type)', 'text', ['width' => 'auto']),
                        icsb_c('total_required', 'Total Resources Required', 'text', ['width' => '160px', 'block' => '11']),
                        icsb_c('total_have', 'Total Resources Have on Hand', 'text', ['width' => '160px', 'block' => '12']),
                        icsb_c('total_need', 'Total Resources Need To Order', 'text', ['width' => '160px', 'block' => '13']),
                    ],
                ]),
            ],
            icsb_prepared_by('14')
        ),
    ];
}

// ── ICS 215A — Incident Action Plan Safety Analysis ─────────────────────
function _icsb_tpl_215a(): array
{
    return [
        'fields' => array_merge(
            [
                icsb_incident_name('1', 6),
                icsb_incident_number('2', 3),
                icsb_f('date_prepared', '3. Date/Time Prepared', 'datetime-local', ['block' => '3', 'col' => 3]),
            ],
            icsb_op_period('4', 6),
            [
                icsb_f('hazards', 'Safety Analysis', 'table', [
                    'default_rows' => 3, 'print_rows' => 10,
                    'help' => 'Each incident area (Branch, Division or Group) where personnel may meet risks, the hazards and '
                        . 'risks there, and the action that reduces each one (PPE, buddy system, escape routes).',
                    'columns' => [
                        icsb_c('area', 'Incident Area', 'text', ['width' => '200px', 'block' => '5']),
                        icsb_c('hazards', 'Hazards/Risks', 'text', ['width' => 'auto', 'block' => '6']),
                        icsb_c('mitigations', 'Mitigations', 'text', ['width' => 'auto', 'block' => '7']),
                    ],
                ]),
                icsb_f('prepared_by', '8. Prepared by (Safety Officer): Name', 'text', [
                    'block' => '8', 'block_title' => 'Prepared by (Safety Officer and Operations Section Chief)', 'col' => 4,
                    'help' => 'The Safety Officer and the Operations Section Chief prepare it together.',
                ]),
                icsb_sig('8', 'Signature', 4),
                icsb_f('prepared_datetime', 'Date/Time', 'datetime-local', ['block' => '8', 'col' => 4]),
                icsb_f('ops_chief', 'Prepared by (Operations Section Chief): Name', 'text', ['block' => '8', 'col' => 4]),
                icsb_sig('8', 'Signature', 4),
            ]
        ),
    ];
}

// ── ICS 217A — Communications Resource Availability Worksheet ───────────
function _icsb_tpl_217a(): array
{
    return [
        'landscape' => true,
        'print_title' => 'COMMUNICATIONS RESOURCE AVAILABILITY WORKSHEET',
        'fields' => [
            icsb_f('frequency_band', 'Frequency Band', 'text', ['col' => 6]),
            icsb_f('description', 'Description', 'text', ['col' => 6]),
            icsb_f('channels', 'Channels', 'table', [
                'default_rows' => 3, 'print_rows' => 16,
                'help' => 'Frequencies to four decimal places followed by N (narrow) or W (wide). Mode: A analog, D digital '
                    . '(e.g. Project 25), M mixed. Channels as programmed in a control station, mobile or portable radio; '
                    . 'repeaters and base stations are programmed with RX and TX reversed.',
                'columns' => [
                    icsb_c('page_no', 'Pg No', 'text', ['width' => '55px']),
                    icsb_c('channel_config', 'Channel Configuration', 'text', ['width' => '110px']),
                    icsb_c('channel_name', 'Channel Name/Trunked Radio System Talkgroup', 'text', ['width' => '170px']),
                    icsb_c('eligible_users', 'Eligible Users', 'text', ['width' => '130px']),
                    icsb_c('rx_freq', 'RX Freq', 'text', ['width' => '95px', 'print_label' => 'RX Freq N or W', 'print_append' => ['rx_nw']]),
                    icsb_c('rx_nw', 'N or W', 'select', ['width' => '60px', 'options' => ['N', 'W'], 'print_hide' => true]),
                    icsb_c('rx_tone', 'RX Tone/NAC', 'text', ['width' => '85px']),
                    icsb_c('tx_freq', 'TX Freq', 'text', ['width' => '95px', 'print_label' => 'TX Freq N or W', 'print_append' => ['tx_nw']]),
                    icsb_c('tx_nw', 'N or W', 'select', ['width' => '60px', 'options' => ['N', 'W'], 'print_hide' => true]),
                    icsb_c('tx_tone', 'TX Tone/NAC', 'text', ['width' => '85px']),
                    icsb_c('mode', 'Mode (A, D, or M)', 'select', ['width' => '70px', 'options' => [
                        ['value' => 'A', 'label' => 'A (analog)'],
                        ['value' => 'D', 'label' => 'D (digital)'],
                        ['value' => 'M', 'label' => 'M (mixed)'],
                    ]]),
                    icsb_c('remarks', 'Remarks', 'text', ['width' => 'auto']),
                ],
            ]),
        ],
    ];
}

// ── ICS 218 — Support Vehicle/Equipment Inventory ───────────────────────
function _icsb_tpl_218(): array
{
    $c = function (string $key, string $label, string $type = 'text', string $width = '120px') {
        return icsb_c($key, $label, $type, ['width' => $width]);
    };
    return [
        'landscape' => true,
        'fields' => [
            icsb_incident_name('1', 5),
            icsb_incident_number('2', 3),
            icsb_f('date_prepared', '3. Date/Time Prepared', 'datetime-local', ['block' => '3', 'col' => 4]),
            icsb_f('category', '4. Vehicle/Equipment Category', 'text', ['block' => '4',
                'help' => 'e.g. buses, generators, dozers, pickups/sedans, rental cars. Use a separate form for each category.']),
            icsb_f('vehicles', '5. Vehicle/Equipment Information', 'table', [
                'block' => '5', 'default_rows' => 3, 'print_rows' => 10,
                'help' => 'Incident ID: the identifier used only on this incident (e.g. Water Tender 14). Features: 2WD/4WD, towing, '
                    . 'axles, AVL. Operator: name and/or contact. Dates and times on the 24-hour clock.',
                'columns' => [
                    $c('order_number', 'Order Request Number', 'text', '110px'),
                    $c('incident_id_no', 'Incident ID No.', 'text', '100px'),
                    $c('classification', 'Vehicle or Equipment Classification', 'text', '130px'),
                    $c('make', 'Vehicle or Equipment Make', 'text', '110px'),
                    $c('size', 'Category/Kind/Type, Capacity, or Size', 'text', '130px'),
                    $c('features', 'Vehicle or Equipment Features', 'text', '130px'),
                    $c('owner', 'Agency or Owner', 'text', '110px'),
                    $c('operator', 'Operator Name or Contact', 'text', '130px'),
                    $c('license', 'Vehicle License or ID No.', 'text', '110px'),
                    $c('assignment', 'Incident Assignment', 'text', '130px'),
                    $c('start', 'Incident Start Date and Time', 'datetime-local', '180px'),
                    $c('release', 'Incident Release Date and Time', 'datetime-local', '180px'),
                ],
            ]),
            icsb_f('prepared_by', '6. Prepared by: Name', 'text', ['block' => '6', 'block_title' => 'Prepared by', 'col' => 4,
                'help' => 'Ground Support Unit personnel.']),
            icsb_f('prepared_by_position', 'Position/Title', 'text', ['block' => '6', 'col' => 4]),
            icsb_sig('6', 'Signature', 4),
        ],
    ];
}

// ── ICS 219 — Resource Status Cards (T-Cards) ───────────────────────────
// No numbered blocks on the cards; FEMA's field names are used as labels.
// `card_color` prints as a band across the top, for the card stock colour.

/** The status entries on the back of every card (FEMA has room for four). */
function _icsb_219_back(): array
{
    return [
        icsb_h('', 'Back', ['help' => 'Each time the resource moves or changes status: where, when, its status and any notes.']),
        icsb_f('status_log', 'Status entries', 'table', [
            'default_rows' => 2, 'print_rows' => 4,
            'columns' => [
                icsb_c('location', 'Incident Location', 'text', ['width' => 'auto']),
                icsb_c('time', 'Time', 'time', ['width' => '110px']),
                icsb_c('status', 'Status', 'select', ['width' => '130px',
                    'options' => ['Assigned', 'Available', 'O/S Rest', 'O/S Pers', 'O/S Mech']]),
                icsb_c('etr', 'ETR', 'text', ['width' => '90px']),
                icsb_c('notes', 'Notes', 'text', ['width' => 'auto']),
            ],
        ]),
    ];
}

/** The top strip of every card except the personnel card. */
function _icsb_219_strip(string $persHelp = 'Total personnel with the resource, leaders included.'): array
{
    return [
        icsb_f('st_unit', 'ST/Unit', 'text', ['col' => 3, 'help' => 'State and/or unit identifier (3-5 letters).']),
        icsb_f('ldw', 'LDW', 'text', ['col' => 3, 'help' => 'Last Day Worked: the last day the resource may work.']),
        icsb_f('num_pers', '# Pers', 'number', ['col' => 3, 'help' => $persHelp]),
        icsb_f('order_number', 'Order #', 'text', ['col' => 3]),
        icsb_f('agency', 'Agency', 'text', ['col' => 4, 'help' => 'Agency name or designator.']),
        icsb_f('cat_kind_type', 'Cat/Kind/Type', 'text', ['col' => 4]),
        icsb_f('name_id', 'Name/ID #', 'text', ['col' => 4, 'help' => 'The resource name or unique identifier.']),
    ];
}

/** Travel fields shared by the crew/team and personnel cards. */
function _icsb_219_travel(): array
{
    $cb = function (string $key, string $label) {
        return icsb_f($key, $label, 'checkbox', ['col' => 2]);
    };
    return [
        icsb_f('manifest', 'Manifest', 'select', ['col' => 3, 'options' => icsb_yes_no()]),
        icsb_f('manifest_number', 'Manifest Number', 'text', ['col' => 3]),
        icsb_f('total_weight', 'Total Weight', 'text', ['col' => 3, 'help' => 'Needed for charter air transport.']),
        icsb_h('', 'Method of Travel to Incident', ['help' => 'AOV = agency-owned vehicle; POV = privately owned vehicle.']),
        $cb('travel_aov', 'AOV'), $cb('travel_pov', 'POV'), $cb('travel_bus', 'Bus'), $cb('travel_air', 'Air'), $cb('travel_other', 'Other'),
    ];
}

/** Transportation needs at the incident (crew/team and personnel cards). */
function _icsb_219_needs(): array
{
    $cb = function (string $key, string $label) {
        return icsb_f($key, $label, 'checkbox', ['col' => 2]);
    };
    return [
        icsb_h('', 'Transportation Needs at Incident'),
        $cb('need_vehicle', 'Vehicle'), $cb('need_bus', 'Bus'), $cb('need_air', 'Air'), $cb('need_other', 'Other'),
    ];
}

/** Departure, ETD/ETA, ordered, remarks. */
function _icsb_219_trip(bool $destination = false): array
{
    $out = [
        icsb_f('home_base', 'Home Base', 'text', ['col' => 6, 'help' => 'Where the resource is normally based (may not be where it left from).']),
        icsb_f('departure_point', 'Departure Point', 'text', ['col' => 6]),
        icsb_f('etd', 'ETD', 'text', ['col' => 3, 'help' => 'Estimated time of departure from home base (24-hour clock).']),
        icsb_f('eta', 'ETA', 'text', ['col' => 3, 'help' => 'Estimated time of arrival (24-hour clock).']),
    ];
    if ($destination) {
        $out[] = icsb_f('destination', 'Destination Point', 'text', ['col' => 6, 'help' => 'Where at the incident it is to report.']);
    }
    return $out;
}

/** Assemble one card. */
function _icsb_219_card(string $title, string $color, array $front): array
{
    return [
        'print_title' => strtoupper($title),
        'card_color'  => $color,
        'fields' => array_merge(
            $front,
            _icsb_219_back(),
            [
                icsb_f('prepared_by', 'Prepared by', 'text', ['col' => 6]),
                icsb_f('prepared_datetime', 'Date/Time', 'datetime-local', ['col' => 6]),
            ]
        ),
    ];
}

function _icsb_tpl_219_1(): array
{
    return [
        'print_title' => 'HEADER CARD (GRAY)',
        'card_color'  => '#9e9e9e',
        'fields' => [
            icsb_f('header_label', 'Header label', 'text', [
                'help' => 'What this card labels in the T-card rack (e.g. "Engines", "Division A", "Out of Service").',
            ]),
            icsb_f('card_text', 'Card text', 'textarea', ['rows' => 6]),
            icsb_f('prepared_by', 'Prepared by', 'text', ['col' => 6]),
            icsb_f('prepared_datetime', 'Date/Time', 'datetime-local', ['col' => 6]),
        ],
    ];
}

/** Front of the engine, equipment, miscellaneous and generic cards. */
function _icsb_219_resource_front(string $idHelp): array
{
    return array_merge(
        _icsb_219_strip(),
        [
            icsb_h('', 'Front'),
            icsb_f('checked_in', 'Date/Time Checked In', 'datetime-local', ['col' => 4]),
            icsb_f('leader_name', 'Leader Name', 'text', ['col' => 4, 'help' => 'At least first initial and last name.']),
            icsb_f('primary_contact', 'Primary Contact Information', 'text', ['col' => 4,
                'help' => 'Cell, radio (function, frequency, system and channel from the ICS 205), etc.']),
            icsb_f('resource_ids', 'Resource ID #(s) or Name(s)', 'textarea', ['rows' => 2, 'help' => $idHelp]),
        ],
        _icsb_219_trip(),
        [
            icsb_f('ordered', 'Date/Time Ordered', 'datetime-local', ['col' => 6]),
            icsb_f('remarks', 'Remarks', 'textarea', ['rows' => 2]),
        ]
    );
}

function _icsb_tpl_219_2(): array
{
    return _icsb_219_card('Crew/Team Card (Green)', '#00a651', array_merge(
        _icsb_219_strip('Total personnel with the crew/team, leaders included.'),
        [
            icsb_h('', 'Front'),
            icsb_f('checked_in', 'Date/Time Checked In', 'datetime-local', ['col' => 4]),
            icsb_f('leader_name', 'Leader Name', 'text', ['col' => 4, 'help' => 'At least first initial and last name.']),
            icsb_f('primary_contact', 'Primary Contact Information', 'text', ['col' => 4]),
            icsb_f('crew_ids', 'Crew/Team ID #(s) or Name(s)', 'textarea', ['rows' => 2,
                'help' => 'e.g. Air Monitoring Team 2, Entry Team 3.']),
        ],
        _icsb_219_travel(),
        _icsb_219_trip(),
        _icsb_219_needs(),
        [
            icsb_f('ordered', 'Date/Time Ordered', 'datetime-local', ['col' => 6]),
            icsb_f('remarks', 'Remarks', 'textarea', ['rows' => 2]),
        ]
    ));
}

function _icsb_tpl_219_3(): array
{
    return _icsb_219_card('Engine Card (Rose)', '#f4a6b7', _icsb_219_resource_front('The engine\'s identifier(s) or name(s).'));
}

function _icsb_tpl_219_4(): array
{
    return _icsb_219_card('Helicopter Card (Blue)', '#4f81bd', array_merge(
        _icsb_219_strip('Total personnel, the pilot included.'),
        [
            icsb_h('', 'Front'),
            icsb_f('checked_in', 'Date/Time Checked In', 'datetime-local', ['col' => 6]),
            icsb_f('pilot_name', 'Pilot Name', 'text', ['col' => 6, 'help' => 'At least first initial and last name.']),
        ],
        _icsb_219_trip(true),
        [
            icsb_f('ordered', 'Date/Time Ordered', 'datetime-local', ['col' => 6]),
            icsb_f('remarks', 'Remarks', 'textarea', ['rows' => 2]),
        ]
    ));
}

function _icsb_tpl_219_5(): array
{
    return _icsb_219_card('Personnel Card (White)', '#d9d9d9', array_merge(
        [
            icsb_f('st_unit', 'ST/Unit', 'text', ['col' => 3, 'help' => 'State and/or unit identifier (3-5 letters).']),
            icsb_f('name', 'Name', 'text', ['col' => 5, 'help' => 'First initial and last name.']),
            icsb_f('position', 'Position/Title', 'text', ['col' => 4, 'help' => 'ICS position/title.']),
            icsb_h('', 'Front'),
            icsb_f('checked_in', 'Date/Time Checked In', 'datetime-local', ['col' => 4]),
            icsb_f('full_name', 'Full Name', 'text', ['col' => 4]),
            icsb_f('primary_contact', 'Primary Contact Information', 'text', ['col' => 4]),
        ],
        _icsb_219_travel(),
        _icsb_219_trip(),
        _icsb_219_needs(),
        [
            icsb_f('ordered', 'Date/Time Ordered', 'datetime-local', ['col' => 6]),
            icsb_f('remarks', 'Remarks', 'textarea', ['rows' => 2]),
        ]
    ));
}

function _icsb_tpl_219_6(): array
{
    return _icsb_219_card('Fixed-Wing Card (Orange)', '#f7941d', array_merge(
        _icsb_219_strip('Total personnel, the pilot included.'),
        [
            icsb_h('', 'Front'),
            icsb_f('checked_in', 'Date/Time Checked In', 'datetime-local', ['col' => 6]),
            icsb_f('pilot_name', 'Pilot Name', 'text', ['col' => 6, 'help' => 'At least first initial and last name.']),
        ],
        _icsb_219_trip(true),
        [
            icsb_f('ordered', 'Date/Time Ordered', 'datetime-local', ['col' => 6]),
            icsb_f('manufacturer', 'Manufacturer', 'text', ['col' => 6, 'help' => 'The aircraft\'s manufacturer.']),
            icsb_f('remarks', 'Remarks', 'textarea', ['rows' => 2]),
        ]
    ));
}

function _icsb_tpl_219_7(): array
{
    return _icsb_219_card('Equipment Card (Yellow)', '#ffde17', _icsb_219_resource_front('The equipment\'s identifier(s) or name(s).'));
}

function _icsb_tpl_219_8(): array
{
    return _icsb_219_card('Miscellaneous Equipment/Task Force Card (Tan)', '#d2b48c',
        _icsb_219_resource_front('The identifier or name of the equipment or task force (and its members).'));
}

function _icsb_tpl_219_10(): array
{
    return _icsb_219_card('Generic Card (Light Purple)', '#c8a2c8', _icsb_219_resource_front('The resource\'s identifier(s) or name(s).'));
}

// ── ICS 220 — Air Operations Summary ────────────────────────────────────
function _icsb_tpl_220(): array
{
    return [
        'landscape' => true,
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3),
            [
                icsb_f('sunrise', '3. Sunrise/Sunset: Sunrise', 'time', ['block' => '3', 'block_title' => 'Sunrise/Sunset', 'col' => 3]),
                icsb_f('sunset', 'Sunset', 'time', ['block' => '3', 'col' => 3]),
                icsb_f('remarks', '4. Remarks (safety notes, hazards, air operations special equipment, etc.)', 'textarea', [
                    'block' => '4', 'rows' => 3, 'help' => 'Special instructions, safety notes, hazards and priorities for Air Operations personnel.',
                ]),
                icsb_f('medivac', '5. Ready Alert Aircraft: Medivac', 'text', ['block' => '5', 'block_title' => 'Ready Alert Aircraft', 'col' => 6,
                    'help' => 'The medivac aircraft for incident personnel (note it on the ICS 206 too).']),
                icsb_f('new_incident', 'New Incident', 'text', ['block' => '5', 'col' => 6,
                    'help' => 'Aircraft for new incidents in the area or an incident within the incident.']),
                icsb_f('tfr_number', '6. Temporary Flight Restriction Number', 'text', ['block' => '6', 'col' => 4,
                    'help' => 'From the FAA, or the order request number for the TFR.']),
                icsb_f('tfr_altitude', 'Altitude', 'text', ['block' => '6', 'col' => 4, 'help' => 'From the center point.']),
                icsb_f('tfr_center', 'Center Point', 'text', ['block' => '6', 'col' => 4, 'help' => 'Latitude and longitude.']),
                icsb_f('personnel', '7. Personnel', 'table', [
                    'block' => '7', 'row_label_header' => '',
                    'fixed_rows' => ['Air Operations Branch Director', 'Air Support Group Supervisor', 'Air Tactical Group Supervisor',
                        'Helicopter Coordinator', 'Helibase Manager'],
                    'columns' => [
                        icsb_c('name', 'Name', 'text', ['width' => 'auto']),
                        icsb_c('phone', 'Phone Number', 'text', ['width' => '200px']),
                    ],
                ]),
                icsb_f('frequencies', '8. Frequencies', 'table', [
                    'block' => '8', 'row_label_header' => '',
                    'help' => 'Flight following is typically done by Air Operations.',
                    'fixed_rows' => ['Air/Air Fixed-Wing', 'Air/Air Rotary-Wing - Flight Following', 'Air/Ground', 'Command',
                        'Deck Coordinator', 'Take-Off & Landing Coordinator', 'Air Guard'],
                    'columns' => [
                        icsb_c('am', 'AM', 'text', ['width' => '200px']),
                        icsb_c('fm', 'FM', 'text', ['width' => '200px']),
                    ],
                ]),
                icsb_f('atgs_aircraft', '9. Fixed-Wing (category/kind/type, make/model, N#, base): Air Tactical Group Supervisor Aircraft', 'text', [
                    'block' => '9', 'block_title' => 'Fixed-Wing (category/kind/type, make/model, N#, base)',
                ]),
                icsb_f('other_fixed_wing', 'Other Fixed-Wing Aircraft', 'textarea', ['block' => '9', 'rows' => 2]),
                icsb_f('helicopters', '10. Helicopters', 'table', [
                    'block' => '10', 'default_rows' => 2, 'print_rows' => 5,
                    'help' => 'Available: when the aircraft is available. Start: when it becomes operational. Use more rows as needed.',
                    'columns' => [
                        icsb_c('faa_n', 'FAA N#', 'text', ['width' => '100px']),
                        icsb_c('cat_kind_type', 'Category/Kind/Type', 'text', ['width' => '140px']),
                        icsb_c('make_model', 'Make/Model', 'text', ['width' => '140px']),
                        icsb_c('base', 'Base', 'text', ['width' => '140px']),
                        icsb_c('available', 'Available', 'time', ['width' => '110px']),
                        icsb_c('start', 'Start', 'time', ['width' => '110px']),
                        icsb_c('remarks', 'Remarks', 'text', ['width' => 'auto']),
                    ],
                ]),
            ],
            icsb_prepared_by('11'),
            [
                icsb_f('tasks', '12. Task/Mission/Assignment', 'table', [
                    'block' => '12', 'default_rows' => 2, 'print_rows' => 10,
                    'help' => 'Category/kind/type and function: air tactical, reconnaissance, personnel transport, search and rescue, etc. '
                        . 'Include primary air/air and air/ground frequencies if applicable; missions may be listed by priority.',
                    'columns' => [
                        icsb_c('function', 'Category/Kind/Type and Function', 'text', ['width' => '200px']),
                        icsb_c('personnel_cargo', 'Name of Personnel or Cargo (if applicable) or Instructions for Tactical Aircraft', 'text', ['width' => 'auto']),
                        icsb_c('mission_start', 'Mission Start', 'time', ['width' => '110px']),
                        icsb_c('fly_from', 'Fly From', 'text', ['width' => '150px']),
                        icsb_c('fly_to', 'Fly To', 'text', ['width' => '150px']),
                    ],
                ]),
            ]
        ),
    ];
}

// ── ICS 225 — Incident Personnel Performance Rating ─────────────────────
function _icsb_tpl_225(): array
{
    $opts = [
        ['value' => 'N/A', 'label' => 'N/A'],
        ['value' => '1', 'label' => '1 - Unacceptable'],
        ['value' => '2', 'label' => '2 - Needs Improvement'],
        ['value' => '3', 'label' => '3 - Met Standards'],
        ['value' => '4', 'label' => '4 - Fully Successful'],
        ['value' => '5', 'label' => '5 - Exceeded Expectations'],
    ];
    $factors = [
        ['11', 'knowledge', 'Knowledge of the Job/Professional Competence', 'Ability to acquire, apply and share the technical and administrative knowledge and skills of the duties.'],
        ['12', 'results', 'Ability To Obtain Performance/Results', 'Quality, quantity, timeliness and impact of work.'],
        ['13', 'planning', 'Planning/Preparedness', 'Ability to anticipate, set goals, priorities and deadlines, and create a shared vision of the IMT.'],
        ['14', 'resources', 'Using Resources', 'Ability to manage time, materials, information, money and people.'],
        ['15', 'adaptability', 'Adaptability/Attitude', 'Ability to keep a positive attitude and adjust to new information, changing conditions and obstacles.'],
        ['16', 'communication', 'Communication Skills', 'Ability to speak effectively, listen to understand, and express facts and ideas clearly.'],
        ['17', 'teamwork', 'Ability To Work on a Team', 'Ability to manage, lead and take part in teams and encourage cooperation.'],
        ['18', 'welfare', 'Consideration for Personnel/Team Welfare', 'Ability to consider and respond to others\' needs, capabilities and achievements.'],
        ['19', 'directing', 'Directing Others', 'Ability to influence or direct others in accomplishing tasks or missions.'],
        ['20', 'judgment', 'Judgment/Decisions Under Stress', 'Ability to make sound decisions and valid recommendations from facts, experience and risk assessment.'],
        ['21', 'initiative', 'Initiative', 'Ability to originate and act on new ideas and seek responsibility without guidance.'],
        ['22', 'physical', 'Physical Ability for the Job', 'Caring for the physical health and emotional well-being of self and others.'],
        ['23', 'safety', 'Adherence to Safety', 'Caring for the safety of self and others.'],
    ];
    $ratings = [];
    foreach ($factors as [$n, $key, $title, $help]) {
        $ratings[] = icsb_f('rating_' . $key, $n . '. ' . $title, 'select', ['block' => $n, 'col' => 6, 'options' => $opts, 'help' => $help, 'print_option_label' => true]);
    }
    return [
        'prefill' => ['incident_definition' => 'incident_type'],
        'fields' => array_merge(
            [
                icsb_h('', 'This rating is to be used only for determining an individual\'s performance on an incident/event'),
                icsb_f('name', '1. Name', 'text', ['block' => '1', 'col' => 4, 'required' => true, 'help' => 'The individual being rated.']),
                icsb_f('incident_name', '2. Incident Name', 'text', ['block' => '2', 'col' => 4]),
                icsb_incident_number('3', 4),
                icsb_f('home_unit', '4. Home Unit Name and Address', 'text', ['block' => '4', 'col' => 6,
                    'help' => 'Physical address of the rated individual\'s home unit.']),
                icsb_f('incident_agency', '5. Incident Agency and Address', 'text', ['block' => '5', 'col' => 6,
                    'help' => 'The authority having jurisdiction for the incident.']),
                icsb_f('position', '6. Position Held on Incident', 'text', ['block' => '6', 'col' => 4]),
                icsb_f('assigned_from', '7. Date(s) of Assignment: From', 'date', ['block' => '7', 'block_title' => 'Date(s) of Assignment', 'col' => 4]),
                icsb_f('assigned_to', 'To', 'date', ['block' => '7', 'col' => 4]),
                icsb_f('complexity', '8. Incident Complexity Level', 'select', ['block' => '8', 'col' => 4, 'options' => ['1', '2', '3', '4', '5']]),
                icsb_f('incident_definition', '9. Incident Definition', 'text', ['block' => '9', 'col' => 8,
                    'help' => 'A general category, e.g. tornado, wildfire, bridge collapse.']),
                icsb_h('10', 'Evaluation Rating Factors', [
                    'help' => 'N/A = did not apply. 1 Unacceptable and 2 Needs Improvement: explain in Remarks. '
                        . '3 Met Standards; 4 Fully Successful; 5 Exceeded Expectations.',
                ]),
            ],
            $ratings,
            [
                icsb_f('remarks', '24. Remarks', 'textarea', ['block' => '24', 'rows' => 4,
                    'help' => 'Why the individual received these ratings.']),
                [
                    'type' => 'signature', 'block' => '25', 'col' => 8,
                    'label' => '25. Rated Individual (This rating has been discussed with me): Signature',
                    'block_title' => 'Rated Individual (This rating has been discussed with me)',
                ],
                icsb_f('rated_individual_datetime', 'Date/Time', 'datetime-local', ['block' => '25', 'col' => 4]),
                icsb_f('rated_by', '26. Rated by: Name', 'text', ['block' => '26', 'block_title' => 'Rated by', 'col' => 4]),
                icsb_sig('26', 'Signature', 4),
                icsb_f('rated_by_home_unit', 'Home Unit', 'text', ['block' => '26', 'col' => 4]),
                icsb_f('rated_by_position', 'Position Held on This Incident', 'text', ['block' => '26', 'col' => 6]),
                icsb_f('rated_by_datetime', 'Date/Time', 'datetime-local', ['block' => '26', 'col' => 6]),
            ]
        ),
    ];
}

// ── ICS 230-CG — Daily Meeting Schedule ─────────────────────────────────
function _icsb_tpl_230(): array
{
    $m = function (string $name, string $purpose, string $attendees, string $location) {
        return ['datetime' => '', 'meeting_name' => $name, 'purpose' => $purpose, 'attendees' => $attendees, 'location' => $location];
    };
    return [
        'print_title' => 'DAILY MEETING SCHEDULE',
        'fields' => array_merge(
            [icsb_incident_name('1', 6)],
            icsb_op_period('2', 3, 'Operational Period (Date/Time)'),
            [
                icsb_f('meetings', '3. Meeting Schedule', 'table', [
                    'block' => '3', 'print_rows' => 8,
                    'help' => 'A new form starts with the commonly held meetings; set their times, change or add as needed.',
                    'default_data' => [
                        $m('Unified Command Objectives Meeting', 'Review/identify objectives for the next operational period.',
                            'Unified Command members', 'UC Meeting Room'),
                        $m('Command and General Staff Meeting', 'UC presents direction to Command and General Staff.',
                            'UC, Command Staff, General Staff, DOCL, SITL', 'ICP Meeting Room'),
                        $m('Tactics Meeting', 'Develop primary and alternate strategies to meet Incident Objectives for the next Operational Period.',
                            'PSC, OPS, LSC, RESL, SITL, SOFR, DOCL, COML, THSP', 'ICP Meeting Room'),
                        $m('Planning Meeting', 'Review status and finalize strategies/tactics and assignments to meet Incident Objectives '
                            . 'for the next Operational Period and get tacit approval of IAP.',
                            'UC, Command Staff, General Staff, SITL, DOCL, THSP', 'ICP Meeting Room'),
                        $m('Operations Briefing', 'Present IAP and assignments to the Supervisors/Leaders for the next Operational Period.',
                            'IC/UC, Command Staff, General Staff, Branch Directors, Div./Grp Sups., Task Force/Strike Team Leaders and Unit Leaders',
                            'ICP Meeting Room'),
                    ],
                    'columns' => [
                        icsb_c('datetime', 'Date/Time', 'datetime-local', ['width' => '190px']),
                        icsb_c('meeting_name', 'Meeting Name', 'text', ['width' => '200px']),
                        icsb_c('purpose', 'Purpose', 'text', ['width' => 'auto']),
                        icsb_c('attendees', 'Attendees', 'text', ['width' => '240px']),
                        icsb_c('location', 'Location', 'text', ['width' => '150px']),
                    ],
                ]),
                icsb_f('prepared_by', '4. Prepared by (Situation Unit Leader): Name', 'text', [
                    'block' => '4', 'block_title' => 'Prepared by (Situation Unit Leader)', 'col' => 6,
                ]),
                icsb_f('prepared_datetime', 'Date/Time', 'datetime-local', ['block' => '4', 'col' => 6]),
            ]
        ),
    ];
}

// ── ICS 233-CG — Incident Open Action Tracker ───────────────────────────
function _icsb_tpl_233(): array
{
    return [
        'landscape' => true,
        'print_title' => 'INCIDENT OPEN ACTION TRACKER',
        'fields' => [
            icsb_incident_name('1', 12),
            icsb_f('actions', 'Open Actions', 'table', [
                'default_rows' => 3, 'print_rows' => 15,
                'help' => 'Tasks the IC/UC assigns that are not incident objectives (those go on the ICS 202). Number them in '
                    . 'order; tick Briefed once the point of contact has been briefed; enter "completed" in Status when done.',
                'columns' => [
                    icsb_c('no', 'No.', 'number', ['width' => '70px', 'block' => '2']),
                    icsb_c('item', 'Item', 'text', ['width' => 'auto', 'block' => '3']),
                    icsb_c('poc', 'For/POC', 'text', ['width' => '160px', 'block' => '4']),
                    icsb_c('briefed', 'Briefed POC (X)', 'checkbox', ['width' => '90px', 'block' => '5']),
                    icsb_c('start_date', 'Start Date', 'date', ['width' => '140px', 'block' => '6']),
                    icsb_c('status', 'Status', 'text', ['width' => '170px', 'block' => '7']),
                    icsb_c('target_date', 'Target Date', 'date', ['width' => '140px', 'block' => '8']),
                    icsb_c('actual_date', 'Actual Date', 'date', ['width' => '140px', 'block' => '9']),
                ],
            ]),
        ],
    ];
}

/**
 * ICS 207 printed as a chart: the command box with its staff, then four
 * section columns (Operations, Planning, Logistics, Finance/Admin) of boxes.
 */
function _icsp_org_chart_207(array $d): string
{
    $box = function (string $label, string $key) use ($d): string {
        return '<div class="box"><span class="label">' . _icsp_e($label) . '</span><span class="value">'
            . _icsp_value('text', $d[$key] ?? '') . '</span></div>';
    };
    $ops = $box('Operations Section Chief', 'ops_chief') . $box('Staging Area Manager', 'staging');
    $elements = isset($d['ops_elements']) && is_array($d['ops_elements']) ? $d['ops_elements'] : [];
    $n = 0;
    foreach ($elements as $el) {
        if (!is_array($el)) continue;
        // The element's name is data too, so it sits in a value span (the
        // security-label redaction only blanks value spans).
        $ops .= '<div class="box"><span class="label">Branch/Division/Group</span><span class="value"><b>'
            . _icsp_value('text', $el['element'] ?? '') . '</b></span><span class="value">'
            . _icsp_value('text', $el['name'] ?? '') . '</span></div>';
        $n++;
    }
    for (; $n < 4; $n++) {
        $ops .= '<div class="box"><span class="label">&nbsp;</span><span class="value"></span></div>';
    }
    $plan = $box('Planning Section Chief', 'planning_chief') . $box('Resources Unit Ldr.', 'resources_ldr')
        . $box('Situation Unit Ldr.', 'situation_ldr') . $box('Documentation Unit Ldr.', 'documentation_ldr')
        . $box('Demobilization Unit Ldr.', 'demob_ldr') . $box('Other', 'planning_other');
    $log = $box('Logistics Section Chief', 'logistics_chief') . $box('Support Branch Dir.', 'support_dir')
        . $box('Supply Unit Ldr.', 'supply_ldr') . $box('Facilities Unit Ldr.', 'facilities_ldr')
        . $box('Ground Spt. Unit Ldr.', 'ground_ldr') . $box('Service Branch Dir.', 'service_dir')
        . $box('Comms Unit Ldr.', 'comms_ldr') . $box('Medical Unit Ldr.', 'medical_ldr') . $box('Food Unit Ldr.', 'food_ldr');
    $fin = $box('Finance/Admin Section Chief', 'finance_chief') . $box('Time Unit Ldr.', 'time_ldr')
        . $box('Procurement Unit Ldr.', 'procurement_ldr') . $box('Comp./Claims Unit Ldr.', 'comp_ldr')
        . $box('Cost Unit Ldr.', 'cost_ldr') . $box('Other', 'finance_other');
    return '<table class="chart">'
        . '<tr><td colspan="2" class="cmd">' . $box('Incident Commander(s)', 'ic') . '</td>'
        . '<td colspan="2" class="cmd">' . $box('Liaison Officer', 'liaison') . $box('Safety Officer', 'safety')
        . $box('Public Information Officer', 'pio') . '</td></tr>'
        . '<tr><td>' . $ops . '</td><td>' . $plan . '</td><td>' . $log . '</td><td>' . $fin . '</td></tr>'
        . '</table>';
}

// ═════════════════════════════════════════════════════════════════════
// Read-time mapping of data saved by an earlier version
// ═════════════════════════════════════════════════════════════════════

/** Is $v empty for mapping purposes (null, '' or whitespace)? */
function _icsn_blank($v): bool
{
    return $v === null || (is_string($v) && trim($v) === '') || $v === [];
}

/** Copy $value into $d[$to] when $d[$to] is blank and $value is not. */
function _icsn_fill(array &$d, string $to, $value): void
{
    if (_icsn_blank($d[$to] ?? null) && !_icsn_blank($value)) {
        $d[$to] = $value;
    }
}

/**
 * Map data saved by an earlier version of a built-in form onto today's
 * fields. Only ever fills an EMPTY new field from the old one, never
 * overwrites, never deletes the old key — so it is idempotent, and a form
 * someone has already re-saved in the new shape passes through untouched.
 * Used by api/ics-forms.php (GET ?id=, the editor) and generatePrintHtml().
 */
function ics_form_normalize_data(string $type, $data): array
{
    if (!is_array($data)) {
        return [];
    }
    $d = $data;
    switch ($type) {
        case '205':
            // One "Tone/NAC" column became RX Tone/NAC and TX Tone/NAC.
            if (isset($d['radio_channels']) && is_array($d['radio_channels'])) {
                foreach ($d['radio_channels'] as $i => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if (!_icsn_blank($row['tone'] ?? null)) {
                        _icsn_fill($row, 'rx_tone', $row['tone']);
                        _icsn_fill($row, 'tx_tone', $row['tone']);
                    }
                    $d['radio_channels'][$i] = $row;
                }
            }
            break;

        case '214':
            // "Team Name / Team Leader" became FEMA blocks 3 and 4, read the way
            // FEMA's block instructions define them (booklet p.70):
            //   3. Name — "the title of the organizational unit or resource
            //      designator (e.g. Facilities Unit, Safety Officer, Strike
            //      Team)": the old Team Name/Number;
            //   4. ICS Position — "the name and ICS position of the individual
            //      in charge of the Unit": the old Team Leader name and
            //      position, joined as "J. Smith, Engine Boss".
            // 5. Home Agency (and Unit) is the home agency of the person
            // completing the form; nothing in the old shape is that, so it
            // stays empty.
            _icsn_fill($d, 'name', $d['team_name'] ?? null);
            $leader = [];
            foreach (['team_leader_name', 'team_leader_position'] as $k) {
                if (!_icsn_blank($d[$k] ?? null) && is_scalar($d[$k])) {
                    $leader[] = trim((string) $d[$k]);
                }
            }
            if ($leader) {
                _icsn_fill($d, 'ics_position', implode(', ', $leader));
            }
            break;

        case '205a':
            // Phone #, Radio/Freq and Email became FEMA's one "Method(s) of
            // Contact" column.
            if (isset($d['contacts']) && is_array($d['contacts'])) {
                foreach ($d['contacts'] as $i => $row) {
                    if (!is_array($row) || !_icsn_blank($row['contact'] ?? null)) {
                        continue;
                    }
                    $parts = [];
                    foreach (['phone' => 'Phone', 'radio' => 'Radio', 'email' => 'Email'] as $k => $label) {
                        if (!_icsn_blank($row[$k] ?? null)) {
                            $parts[] = $label . ' ' . trim((string) $row[$k]);
                        }
                    }
                    if ($parts) {
                        $d['contacts'][$i]['contact'] = implode('; ', $parts);
                    }
                }
            }
            break;

        case '206':
            // Free-text Y/N columns became Yes/No drop-downs; an ambulance's
            // "Paramedics Y/N" became Level of Service (paramedic = ALS);
            // a hospital's one Travel Time became Air and Ground (shown under
            // Ground, the usual case — check it).
            foreach (['aid_stations', 'transportation', 'hospitals'] as $tbl) {
                if (!isset($d[$tbl]) || !is_array($d[$tbl])) {
                    continue;
                }
                foreach ($d[$tbl] as $i => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ($tbl === 'aid_stations' && isset($row['paramedics'])) {
                        $row['paramedics'] = _icsn_yes_no($row['paramedics']);
                    }
                    if ($tbl === 'transportation' && _icsn_blank($row['level_of_service'] ?? null)
                        && !_icsn_blank($row['paramedics'] ?? null)) {
                        $yn = _icsn_yes_no($row['paramedics']);
                        $row['level_of_service'] = $yn === 'Yes' ? 'ALS' : ($yn === 'No' ? 'BLS' : $row['paramedics']);
                    }
                    if ($tbl === 'hospitals') {
                        _icsn_fill($row, 'travel_ground', $row['travel_time'] ?? null);
                        foreach (['helipad', 'burn_center'] as $k) {
                            if (isset($row[$k])) {
                                $row[$k] = _icsn_yes_no($row[$k]);
                            }
                        }
                    }
                    $d[$tbl][$i] = $row;
                }
            }
            break;

        case '221':
            // Five free-text "check-out" boxes became FEMA's block 6 list.
            $boxes = ['chk_supply' => 'Supply Unit', 'chk_communications' => 'Communications Unit',
                      'chk_facilities' => 'Facilities Unit', 'chk_ground_support' => 'Ground Support Unit'];
            $hasOld = false;
            foreach (array_merge(array_keys($boxes), ['chk_logistics']) as $k) {
                if (array_key_exists($k, $d)) {
                    $hasOld = true;
                }
            }
            if ($hasOld && _icsn_blank($d['checkout'] ?? null)) {
                $rows = _icsb_221_checkout_rows();
                foreach ($rows as $i => $row) {
                    foreach ($boxes as $k => $unit) {
                        if ($row['unit'] === $unit) {
                            [$rows[$i]['check'], $rows[$i]['remarks']] = _icsn_221_box($d[$k] ?? '');
                        }
                    }
                }
                if (!_icsn_blank($d['chk_logistics'] ?? null)) {
                    [$c, $rm] = _icsn_221_box($d['chk_logistics']);
                    $rows[] = ['check' => $c, 'section' => 'Logistics Section', 'unit' => 'Logistics Section (whole section)',
                               'remarks' => $rm, 'name' => '', 'signature' => ''];
                }
                $d['checkout'] = $rows;
            }
            if (isset($d['travel_room'])) {
                $d['travel_room'] = _icsn_yes_no($d['travel_room']);
            }
            break;
    }
    return $d;
}

/** "Y", "yes", "x"... -> "Yes"; "N", "no"... -> "No"; anything else unchanged. */
function _icsn_yes_no($v)
{
    if (!is_string($v)) {
        return $v;
    }
    $l = strtolower(trim($v));
    if (in_array($l, ['y', 'yes', 'true', '1', 'x'], true)) {
        return 'Yes';
    }
    if (in_array($l, ['n', 'no', 'false', '0'], true)) {
        return 'No';
    }
    return $v;
}

/**
 * An old ICS 221 check-out box -> [check, remarks]. As the old printer read
 * it: anything but blank/N/No counts as checked; text other than Y/Yes/X/N/No
 * is kept as the row's remarks.
 */
function _icsn_221_box($v): array
{
    $v = trim((string) $v);
    if ($v === '') {
        return ['', ''];
    }
    $l = strtolower($v);
    return [in_array($l, ['n', 'no'], true) ? '' : '1', in_array($l, ['y', 'yes', 'n', 'no', 'x'], true) ? '' : $v];
}

// ═════════════════════════════════════════════════════════════════════
// FEMA block list
// ═════════════════════════════════════════════════════════════════════

/**
 * The ordered FEMA blocks a template displays: [['block' => '4',
 * 'title' => 'Basic Radio Channel Use'], ...]. A block is named by its FIRST
 * element (field, header or table column) carrying that `block`.
 */
function ics_form_template_blocks(array $tpl): array
{
    $seen = [];
    $out = [];
    $add = function (array $el) use (&$seen, &$out) {
        if (!isset($el['block']) || (string) $el['block'] === '') {
            return;
        }
        $b = (string) $el['block'];
        if (isset($seen[$b])) {
            return;
        }
        $seen[$b] = true;
        $title = isset($el['block_title']) ? (string) $el['block_title']
            : preg_replace('/^\s*' . preg_quote($b, '/') . '\.\s*/', '', (string) ($el['label'] ?? ''));
        $out[] = ['block' => $b, 'title' => rtrim(trim($title), ':')];
    };
    foreach ($tpl['fields'] ?? [] as $f) {
        if (!empty($f['legacy'])) {
            continue;
        }
        $add($f);
        if (($f['type'] ?? '') === 'table') {
            foreach ($f['columns'] ?? [] as $c) {
                if (empty($c['legacy'])) {
                    $add($c);
                }
            }
        }
    }
    return $out;
}

// ═════════════════════════════════════════════════════════════════════
// Print
// ═════════════════════════════════════════════════════════════════════

/** HTML-escape for the printed document. */
function _icsp_e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * A value as it prints: dates as month/day/year (FEMA's instruction), the
 * 24-hour clock unchanged, check boxes as ☑/☐. Returns escaped HTML.
 */
function _icsp_value(string $type, $v): string
{
    if ($type === 'checkbox') {
        return !empty($v) && $v !== '0' ? '&#9745;' : '&#9744;';
    }
    if (is_array($v)) {
        $v = implode(', ', array_map('strval', $v));
    }
    $v = (string) $v;
    if ($type === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return _icsp_e($m[2] . '/' . $m[3] . '/' . $m[1]);
    }
    if ($type === 'datetime-local' && preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}:\d{2})/', $v, $m)) {
        return _icsp_e($m[2] . '/' . $m[3] . '/' . $m[1] . ' ' . $m[4]);
    }
    if ($type === 'textarea') {
        return nl2br(_icsp_e($v));
    }
    return _icsp_e($v);
}

/** The 12-column grid table's opening tag. */
function _icsp_grid_open(): string
{
    return '<table class="g"><colgroup>' . str_repeat('<col>', 12) . '</colgroup>';
}

/** One field's printed cell. */
function _icsp_cell(array $f, array $d, int $col): string
{
    $type = (string) ($f['type'] ?? 'text');
    $label = _icsp_e($f['label'] ?? '');
    if ($type === 'signature') {
        return '<td colspan="' . $col . '"><span class="label">' . $label . ':</span><span class="sig">&nbsp;</span></td>';
    }
    if ($type === 'checkbox') {
        return '<td colspan="' . $col . '"><span class="value">' . _icsp_value('checkbox', $d[$f['key']] ?? '')
            . '</span> <span class="cbl">' . $label . '</span></td>';
    }
    $v = $d[$f['key'] ?? ''] ?? '';
    // A drop-down whose stored code means little on paper (ICS 225's "3") prints its label.
    if ($type === 'select' && !empty($f['print_option_label']) && is_scalar($v)) {
        foreach ($f['options'] ?? [] as $o) {
            if (is_array($o) && (string) ($o['value'] ?? '') === (string) $v) {
                $v = (string) ($o['label'] ?? $v);
                break;
            }
        }
    }
    return '<td colspan="' . $col . '"><span class="label">' . $label . ':</span><span class="value">'
        . _icsp_value($type, $v) . '</span></td>';
}

/** Does any row hold a value in column $key? */
function _icsp_column_has_data(array $rows, string $key): bool
{
    foreach ($rows as $r) {
        if (is_array($r) && !_icsn_blank($r[$key] ?? null)) {
            return true;
        }
    }
    return false;
}

/** A table field, printed as its own table. */
function _icsp_table(array $f, $rows): string
{
    $rows = is_array($rows) ? array_values($rows) : [];
    $cols = [];
    foreach ($f['columns'] ?? [] as $c) {
        if (!empty($c['print_hide'])) {
            continue;
        }
        if (!empty($c['legacy']) && !_icsp_column_has_data($rows, (string) $c['key'])) {
            continue;
        }
        $cols[] = $c;
    }
    $byKey = [];
    foreach ($f['columns'] ?? [] as $c) {
        $byKey[(string) $c['key']] = $c;
    }
    $fixed = isset($f['fixed_rows']) && is_array($f['fixed_rows']) ? array_values($f['fixed_rows']) : null;
    $nCols = count($cols) + ($fixed !== null ? 1 : 0);

    $h = '<table class="t">';
    $h .= '<tr><th colspan="' . max(1, $nCols) . '" class="blk">' . _icsp_e($f['label'] ?? '') . '</th></tr>';

    // Column headers — two rows when the columns are FEMA blocks of their own
    // (ICS 211, 209 block 48-51): the block number/title on top, spanning a
    // block's columns, the column names underneath.
    $groups = [];
    foreach ($cols as $c) {
        $b = isset($c['block']) ? (string) $c['block'] : '';
        $last = count($groups) - 1;
        if ($b !== '' && $last >= 0 && $groups[$last]['block'] === $b) {
            $groups[$last]['cols'][] = $c;
        } else {
            $groups[] = ['block' => $b, 'cols' => [$c]];
        }
    }
    $twoRows = false;
    foreach ($groups as $g) {
        if ($g['block'] !== '' && count($g['cols']) > 1) {
            $twoRows = true;
        }
    }
    $colHead = function (array $c): string {
        return _icsp_e($c['print_label'] ?? $c['label'] ?? '');
    };
    // The editor's pixel width as a hint for narrow columns (Ch #, Qty., Date/Time).
    $colW = function (array $c): string {
        $w = (string) ($c['width'] ?? '');
        return preg_match('/^\d{1,3}px$/', $w) ? ' style="width:' . $w . '"' : '';
    };
    $h .= '<tr>';
    if ($fixed !== null) {
        $h .= '<th' . ($twoRows ? ' rowspan="2"' : '') . '>' . _icsp_e($f['row_label_header'] ?? '') . '</th>';
    }
    foreach ($groups as $g) {
        $first = $g['cols'][0];
        if ($g['block'] !== '' && count($g['cols']) > 1) {
            $h .= '<th colspan="' . count($g['cols']) . '">' . _icsp_e($g['block'] . '. ' . ($first['block_title'] ?? '')) . '</th>';
        } else {
            $text = $g['block'] !== ''
                ? _icsp_e($g['block'] . '. ') . _icsp_e($first['block_title'] ?? ($first['print_label'] ?? $first['label'] ?? ''))
                : $colHead($first);
            $h .= '<th' . ($twoRows ? ' rowspan="2"' : '') . $colW($first) . '>' . $text . '</th>';
        }
    }
    $h .= '</tr>';
    if ($twoRows) {
        $h .= '<tr>';
        foreach ($groups as $g) {
            if ($g['block'] !== '' && count($g['cols']) > 1) {
                foreach ($g['cols'] as $c) {
                    $h .= '<th' . $colW($c) . '>' . $colHead($c) . '</th>';
                }
            }
        }
        $h .= '</tr>';
    }

    $cellsFor = function (array $row) use ($cols, $byKey): string {
        $out = '';
        foreach ($cols as $c) {
            $key = (string) $c['key'];
            $parts = [];
            if (!_icsn_blank($row[$key] ?? null) || ($c['type'] ?? '') === 'checkbox') {
                $parts[] = _icsp_value((string) ($c['type'] ?? 'text'), $row[$key] ?? '');
            }
            foreach ($c['print_append'] ?? [] as $ak) {
                $extra = $row[$ak] ?? '';
                if (!_icsn_blank($extra)) {
                    $parts[] = _icsp_value((string) ($byKey[$ak]['type'] ?? 'text'), $extra);
                }
            }
            $val = implode(' ', $parts);
            $out .= '<td><span class="value">' . $val . '</span></td>';
        }
        return $out;
    };

    if ($fixed !== null) {
        foreach ($fixed as $i => $label) {
            $row = isset($rows[$i]) && is_array($rows[$i]) ? $rows[$i] : [];
            $h .= '<tr><th class="rl">' . _icsp_e($label) . '</th>' . $cellsFor($row) . '</tr>';
        }
    } else {
        $printed = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $h .= '<tr>' . $cellsFor($row) . '</tr>';
            $printed++;
        }
        if ($printed === 0) {
            $blank = max(1, (int) ($f['print_rows'] ?? 5));
            for ($i = 0; $i < $blank; $i++) {
                $h .= '<tr>' . str_repeat('<td class="empty">&nbsp;</td>', max(1, count($cols))) . '</tr>';
            }
        }
    }
    return $h . '</table>';
}

/** The body of a built-in form's printout (everything between title and footer). */
function ics_form_print_body(array $tpl, array $d): string
{
    $out = '';
    $grid = '';
    $cells = '';
    $used = 0;
    $flushRow = function () use (&$grid, &$cells, &$used) {
        if ($cells !== '') {
            if ($used < 12) {
                $cells .= '<td colspan="' . (12 - $used) . '" class="pad"></td>';
            }
            $grid .= '<tr>' . $cells . '</tr>';
        }
        $cells = '';
        $used = 0;
    };
    $flushGrid = function () use (&$out, &$grid, $flushRow) {
        $flushRow();
        if ($grid !== '') {
            $out .= _icsp_grid_open() . $grid . '</table>';
        }
        $grid = '';
    };

    $legacy = [];
    foreach ($tpl['fields'] ?? [] as $f) {
        $type = (string) ($f['type'] ?? 'text');
        if (!empty($f['legacy'])) {
            if ($type !== 'table' && !_icsn_blank($d[$f['key']] ?? null)) {
                $legacy[] = $f;
            }
            continue;
        }
        if (!empty($f['chart_box'])) {
            continue; // drawn by the chart below its header (ICS 207)
        }
        if ($type === 'section_header') {
            $flushRow();
            $grid .= '<tr><th colspan="12" class="blk">' . _icsp_e($f['label'] ?? '') . '</th></tr>';
            if (($f['print_chart'] ?? '') === '207') {
                $flushGrid();
                $out .= _icsp_org_chart_207($d);
            }
            continue;
        }
        if ($type === 'table') {
            $flushGrid();
            $out .= _icsp_table($f, $d[$f['key']] ?? []);
            continue;
        }
        $col = max(1, min(12, (int) ($f['col'] ?? 12)));
        if ($used + $col > 12) {
            $flushRow();
        }
        $cells .= _icsp_cell($f, $d, $col);
        $used += $col;
    }
    $flushGrid();

    if ($legacy) {
        $grid = '<tr><th colspan="12" class="blk">From an earlier version of this form</th></tr>';
        foreach ($legacy as $f) {
            if ($used + 6 > 12) {
                $flushRow();
            }
            $cells .= _icsp_cell($f, $d, 6);
            $used += 6;
        }
        $flushGrid();
    }

    $foot = '<td>' . _icsp_e($tpl['footer'] ?? '') . '</td>';
    if (!empty($tpl['iap'])) {
        $foot .= '<td>IAP Page ______</td>';
    }
    $out .= '<table class="foot"><tr>' . $foot . '</tr></table>';
    return $out;
}

/**
 * The print-optimized HTML document for any ICS form type (built-in or
 * custom). Signature unchanged from the per-form printers it replaces:
 * api/ics-forms.php's export_pdf action and the GH#142 branding tests call it.
 */
function generatePrintHtml($formType, $data, $row)
{
    $tpl = null;
    if ($formType === 'custom') {
        // Phase 140: title/name come from the instance's own frozen _meta
        // (never a fresh ics_form_types lookup -- see ics_form_custom_print_html()'s
        // docblock). form_number/form_title are agency-authored free text and
        // MUST be escaped here.
        $meta = is_array($data) && isset($data['_meta']) && is_array($data['_meta']) ? $data['_meta'] : [];
        $title = htmlspecialchars(strtoupper((string) ($meta['form_number'] ?? 'CUSTOM')), ENT_QUOTES, 'UTF-8');
        $formName = htmlspecialchars((string) ($meta['form_title'] ?? 'Custom Form'), ENT_QUOTES, 'UTF-8');
        $heading = '<h1>' . $title . '</h1><h2>' . $formName . '</h2>';
        $docTitle = $title;
    } else {
        $tpl = ics_builtin_template((string) $formType);
        $number = $tpl['form_number'] ?? strtoupper('ICS ' . $formType);
        $docTitle = _icsp_e($number);
        $heading = '<h1>' . _icsp_e(($tpl['print_title'] ?? strtoupper((string) $formType)) . ' (' . $number . ')') . '</h1>';
    }

    // GH#142 (Phase 155): the agency letterhead for this document, or null. null
    // (no logo, printing branding off, or branding code not loaded) leaves the
    // document as it was. The function_exists() guard is for an OPTIONAL
    // cosmetic feature, not a security control.
    $brandLetterhead = function_exists('branding_ics_letterhead')
        ? branding_ics_letterhead(is_array($row) ? $row : [])
        : null;

    $html = '<!DOCTYPE html><html><head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<title>' . $docTitle . ' - ' . htmlspecialchars($row['title'] ?? '') . '</title>';
    $html .= '<style>';
    $html .= 'body{font-family:Arial,sans-serif;font-size:10pt;color:#000;margin:0.5in;background:#fff}';
    $html .= 'h1{text-align:center;font-size:14pt;margin:0 0 4px}';
    $html .= 'h2{text-align:center;font-size:11pt;margin:0 0 12px;font-weight:normal}';
    $html .= 'table{width:100%;border-collapse:collapse;margin-bottom:12px}';
    $html .= 'td,th{border:1px solid #000;padding:4px 6px;vertical-align:top;font-size:9pt}';
    $html .= 'th{background:#f0f0f0;font-weight:bold;text-align:left}';
    $html .= '.label{font-size:7pt;font-weight:bold;color:#333;display:block;margin-bottom:2px}';
    $html .= '.value{min-height:18px}';
    $html .= '.footer{margin-top:20px;font-size:8pt;color:#666;text-align:center}';
    $html .= 'table.g,table.t{margin-bottom:0;border-top:none}table.g{table-layout:fixed}';
    $html .= 'th.blk{background:#e5e5e5;text-transform:none}th.rl{font-weight:normal;background:#fafafa}';
    $html .= 'td.empty{height:20px}td.pad{border-left:none}td,th{overflow-wrap:anywhere}table.t td,table.t th{font-size:8pt}';
    $html .= '.sig{display:block;border-bottom:1px solid #000;height:16px;margin-top:2px}';
    $html .= '.cbl{font-size:8pt}.band{height:12px;margin:0 0 6px;border:1px solid #000}';
    $html .= 'table.foot{margin-top:0}table.foot td{font-weight:bold;font-size:8pt;width:auto}';
    $html .= '.chart{width:100%;border-collapse:separate;border-spacing:6px 0;table-layout:fixed;margin:4px 0 8px}.chart td{border:none;padding:0;vertical-align:top}';
    $html .= '.chart .box{border:1px solid #000;margin:0 0 4px;padding:2px 4px;min-height:30px;text-align:center}.chart .box .label{text-align:center}.chart .box .value{display:block}';
    // FEMA prints some forms landscape (ICS 205, 211, 213 RR, 215, 217A, 218...).
    $landscape = is_array($tpl) && !empty($tpl['landscape']);
    $html .= '@media print{body{margin:0.3in}@page{size:letter' . ($landscape ? ' landscape' : '') . ';margin:0.5in}}';
    $html .= '</style></head><body>';
    if ($brandLetterhead !== null) {
        $html .= $brandLetterhead['html'];
    }
    // ICS 219 T-cards are printed on coloured card stock; a band in the card's
    // colour stands in for it (fixed colours from the template, never user input).
    if (is_array($tpl) && isset($tpl['card_color']) && preg_match('/^#[0-9a-f]{6}$/i', (string) $tpl['card_color'])) {
        $html .= '<div class="band" style="background:' . $tpl['card_color'] . '"></div>';
    }
    $html .= $heading;

    if ($formType === 'custom') {
        $html .= function_exists('ics_form_custom_print_html') ? ics_form_custom_print_html($data, $row) : '';
    } elseif ($tpl !== null) {
        $html .= ics_form_print_body($tpl, ics_form_normalize_data((string) $formType, $data));
    }

    if ($brandLetterhead !== null && $brandLetterhead['footer_replace']) {
        // branding_print_banner = replace: the logo stands in for the product line.
        $html .= '<div class="footer">Generated &mdash; ' . date('Y-m-d H:i') . '</div>';
    } else {
        $html .= '<div class="footer">Generated by Tickets CAD v4 &mdash; ' . date('Y-m-d H:i') . '</div>';
    }
    $html .= '</body></html>';
    return $html;
}
