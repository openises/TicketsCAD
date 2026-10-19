<?php
/**
 * GH#154 -- a frozen copy of the nine built-in ICS form templates as they were
 * BEFORE the forms were matched to the FEMA booklet (api/ics-forms.php at
 * commit 5231636a, getFormTemplate()). Generated once from that blob and
 * committed, so tests/test_ics_forms_builtin_regression.php can prove that a
 * form saved by that version -- every one of these keys filled in -- still
 * opens and prints with all of its data, on any checkout (CI clones are
 * shallow and cannot read the old blob). Do not edit: it is history.
 */
return array (
  213 => 
  array (
    'form_type' => '213',
    'form_number' => 'ICS-213',
    'form_title' => 'General Message',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'to_name',
        'label' => 'To (Name)',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'to_position',
        'label' => 'To (Position)',
        'type' => 'text',
        'required' => false,
      ),
      2 => 
      array (
        'key' => 'from_name',
        'label' => 'From (Name)',
        'type' => 'text',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'from_position',
        'label' => 'From (Position)',
        'type' => 'text',
        'required' => false,
      ),
      4 => 
      array (
        'key' => 'subject',
        'label' => 'Subject',
        'type' => 'text',
        'required' => true,
      ),
      5 => 
      array (
        'key' => 'date',
        'label' => 'Date',
        'type' => 'date',
        'required' => true,
      ),
      6 => 
      array (
        'key' => 'time',
        'label' => 'Time',
        'type' => 'time',
        'required' => true,
      ),
      7 => 
      array (
        'key' => 'message',
        'label' => 'Message',
        'type' => 'textarea',
        'required' => true,
        'rows' => 6,
      ),
      8 => 
      array (
        'key' => 'approved_name',
        'label' => 'Approved By (Name)',
        'type' => 'text',
        'required' => false,
      ),
      9 => 
      array (
        'key' => 'approved_position',
        'label' => 'Approved By (Position)',
        'type' => 'text',
        'required' => false,
      ),
      10 => 
      array (
        'key' => 'reply',
        'label' => 'Reply',
        'type' => 'textarea',
        'required' => false,
        'rows' => 4,
      ),
      11 => 
      array (
        'key' => 'reply_date',
        'label' => 'Reply Date',
        'type' => 'date',
        'required' => false,
      ),
      12 => 
      array (
        'key' => 'reply_time',
        'label' => 'Reply Time',
        'type' => 'time',
        'required' => false,
      ),
      13 => 
      array (
        'key' => 'reply_by',
        'label' => 'Reply Signature/Position',
        'type' => 'text',
        'required' => false,
      ),
    ),
  ),
  214 => 
  array (
    'form_type' => '214',
    'form_number' => 'ICS-214',
    'form_title' => 'Activity Log',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'incident_name',
        'label' => 'Incident Name',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'date_prepared',
        'label' => 'Date Prepared',
        'type' => 'date',
        'required' => true,
      ),
      2 => 
      array (
        'key' => 'op_period_from',
        'label' => 'Operational Period From',
        'type' => 'datetime-local',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'op_period_to',
        'label' => 'Operational Period To',
        'type' => 'datetime-local',
        'required' => true,
      ),
      4 => 
      array (
        'key' => 'team_name',
        'label' => 'Team Name/Number',
        'type' => 'text',
        'required' => true,
      ),
      5 => 
      array (
        'key' => 'team_leader_name',
        'label' => 'Team Leader (Name)',
        'type' => 'text',
        'required' => true,
      ),
      6 => 
      array (
        'key' => 'team_leader_position',
        'label' => 'Team Leader (Position)',
        'type' => 'text',
        'required' => false,
      ),
      7 => 
      array (
        'key' => 'activity_log',
        'label' => 'Activity Log',
        'type' => 'table',
        'required' => false,
        'columns' => 
        array (
          0 => 
          array (
            'key' => 'time',
            'label' => 'Time',
            'type' => 'time',
            'width' => '120px',
          ),
          1 => 
          array (
            'key' => 'activity',
            'label' => 'Notable Activities',
            'type' => 'text',
            'width' => 'auto',
          ),
        ),
      ),
      8 => 
      array (
        'key' => 'prepared_by',
        'label' => 'Prepared By',
        'type' => 'text',
        'required' => false,
      ),
    ),
  ),
  202 => 
  array (
    'form_type' => '202',
    'form_number' => 'ICS-202',
    'form_title' => 'Incident Objectives',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'incident_name',
        'label' => 'Incident Name',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'date_prepared',
        'label' => 'Date/Time Prepared',
        'type' => 'datetime-local',
        'required' => true,
      ),
      2 => 
      array (
        'key' => 'op_period_from',
        'label' => 'Operational Period From',
        'type' => 'datetime-local',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'op_period_to',
        'label' => 'Operational Period To',
        'type' => 'datetime-local',
        'required' => true,
      ),
      4 => 
      array (
        'key' => 'objectives',
        'label' => 'Objective(s)',
        'type' => 'textarea',
        'required' => true,
        'rows' => 8,
      ),
      5 => 
      array (
        'key' => 'current_actions',
        'label' => 'Summary of Current Actions',
        'type' => 'textarea',
        'required' => false,
        'rows' => 6,
      ),
      6 => 
      array (
        'key' => 'prepared_by',
        'label' => 'Prepared By (Name/Position)',
        'type' => 'text',
        'required' => false,
      ),
    ),
  ),
  205 => 
  array (
    'form_type' => '205',
    'form_number' => 'ICS-205',
    'form_title' => 'Radio Communications Plan',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'incident_name',
        'label' => 'Incident Name',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'date_prepared',
        'label' => 'Date/Time Prepared',
        'type' => 'datetime-local',
        'required' => true,
      ),
      2 => 
      array (
        'key' => 'op_period_from',
        'label' => 'Operational Period From',
        'type' => 'datetime-local',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'op_period_to',
        'label' => 'Operational Period To',
        'type' => 'datetime-local',
        'required' => true,
      ),
      4 => 
      array (
        'key' => 'radio_channels',
        'label' => 'Radio Channel Assignments',
        'type' => 'table',
        'required' => false,
        'columns' => 
        array (
          0 => 
          array (
            'key' => 'zone_group',
            'label' => 'Zone/Group',
            'type' => 'text',
            'width' => '100px',
          ),
          1 => 
          array (
            'key' => 'channel',
            'label' => 'Channel',
            'type' => 'text',
            'width' => '80px',
          ),
          2 => 
          array (
            'key' => 'function',
            'label' => 'Function',
            'type' => 'text',
            'width' => '120px',
          ),
          3 => 
          array (
            'key' => 'frequency_rx',
            'label' => 'Freq (RX)',
            'type' => 'text',
            'width' => '100px',
          ),
          4 => 
          array (
            'key' => 'frequency_tx',
            'label' => 'Freq (TX)',
            'type' => 'text',
            'width' => '100px',
          ),
          5 => 
          array (
            'key' => 'tone',
            'label' => 'Tone/NAC',
            'type' => 'text',
            'width' => '80px',
          ),
          6 => 
          array (
            'key' => 'assignment',
            'label' => 'Assignment',
            'type' => 'text',
            'width' => 'auto',
          ),
        ),
      ),
      5 => 
      array (
        'key' => 'special_instructions',
        'label' => 'Special Instructions',
        'type' => 'textarea',
        'required' => false,
        'rows' => 4,
      ),
      6 => 
      array (
        'key' => 'prepared_by',
        'label' => 'Prepared By (Name/Position)',
        'type' => 'text',
        'required' => false,
      ),
    ),
  ),
  '205a' => 
  array (
    'form_type' => '205a',
    'form_number' => 'ICS-205A',
    'form_title' => 'Communications List',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'incident_name',
        'label' => 'Incident Name',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'date_prepared',
        'label' => 'Date/Time Prepared',
        'type' => 'datetime-local',
        'required' => true,
      ),
      2 => 
      array (
        'key' => 'op_period_from',
        'label' => 'Operational Period From',
        'type' => 'datetime-local',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'op_period_to',
        'label' => 'Operational Period To',
        'type' => 'datetime-local',
        'required' => true,
      ),
      4 => 
      array (
        'key' => 'contacts',
        'label' => 'Communications List',
        'type' => 'table',
        'required' => false,
        'columns' => 
        array (
          0 => 
          array (
            'key' => 'position',
            'label' => 'ICS Position',
            'type' => 'text',
            'width' => '140px',
          ),
          1 => 
          array (
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'width' => '140px',
          ),
          2 => 
          array (
            'key' => 'phone',
            'label' => 'Phone #',
            'type' => 'text',
            'width' => '120px',
          ),
          3 => 
          array (
            'key' => 'radio',
            'label' => 'Radio/Freq',
            'type' => 'text',
            'width' => '100px',
          ),
          4 => 
          array (
            'key' => 'email',
            'label' => 'Email',
            'type' => 'text',
            'width' => 'auto',
          ),
        ),
      ),
      5 => 
      array (
        'key' => 'prepared_by',
        'label' => 'Prepared By (Name/Position)',
        'type' => 'text',
        'required' => false,
      ),
    ),
  ),
  '213rr' => 
  array (
    'form_type' => '213rr',
    'form_number' => 'ICS-213RR',
    'form_title' => 'Resource Request Message',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'incident_name',
        'label' => 'Incident Name',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'date_prepared',
        'label' => 'Date/Time Prepared',
        'type' => 'datetime-local',
        'required' => true,
      ),
      2 => 
      array (
        'key' => 'to_name',
        'label' => 'To (Name/Position)',
        'type' => 'text',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'from_name',
        'label' => 'From (Name/Position)',
        'type' => 'text',
        'required' => true,
      ),
      4 => 
      array (
        'key' => 'resource_requests',
        'label' => 'Resource Requests',
        'type' => 'table',
        'required' => false,
        'columns' => 
        array (
          0 => 
          array (
            'key' => 'qty',
            'label' => 'Qty',
            'type' => 'text',
            'width' => '60px',
          ),
          1 => 
          array (
            'key' => 'kind',
            'label' => 'Kind',
            'type' => 'text',
            'width' => '100px',
          ),
          2 => 
          array (
            'key' => 'type',
            'label' => 'Type',
            'type' => 'text',
            'width' => '80px',
          ),
          3 => 
          array (
            'key' => 'description',
            'label' => 'Item Description',
            'type' => 'text',
            'width' => 'auto',
          ),
          4 => 
          array (
            'key' => 'arrival',
            'label' => 'Requested Arrival',
            'type' => 'text',
            'width' => '120px',
          ),
          5 => 
          array (
            'key' => 'priority',
            'label' => 'Priority',
            'type' => 'text',
            'width' => '80px',
          ),
          6 => 
          array (
            'key' => 'cost',
            'label' => 'Est. Cost',
            'type' => 'text',
            'width' => '80px',
          ),
        ),
      ),
      5 => 
      array (
        'key' => 'delivery_location',
        'label' => 'Delivery Location',
        'type' => 'text',
        'required' => false,
      ),
      6 => 
      array (
        'key' => 'substitutes',
        'label' => 'Substitutes / Notes',
        'type' => 'textarea',
        'required' => false,
        'rows' => 3,
      ),
      7 => 
      array (
        'key' => 'requested_by',
        'label' => 'Requested By',
        'type' => 'text',
        'required' => false,
      ),
      8 => 
      array (
        'key' => 'approved_by',
        'label' => 'Approved By (Logistics)',
        'type' => 'text',
        'required' => false,
      ),
      9 => 
      array (
        'key' => 'approved_date',
        'label' => 'Approved Date/Time',
        'type' => 'datetime-local',
        'required' => false,
      ),
    ),
  ),
  206 => 
  array (
    'form_type' => '206',
    'form_number' => 'ICS-206',
    'form_title' => 'Medical Plan',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'incident_name',
        'label' => 'Incident Name',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'date_prepared',
        'label' => 'Date/Time Prepared',
        'type' => 'datetime-local',
        'required' => true,
      ),
      2 => 
      array (
        'key' => 'op_period_from',
        'label' => 'Operational Period From',
        'type' => 'datetime-local',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'op_period_to',
        'label' => 'Operational Period To',
        'type' => 'datetime-local',
        'required' => true,
      ),
      4 => 
      array (
        'key' => 'aid_stations',
        'label' => 'Medical Aid Stations',
        'type' => 'table',
        'required' => false,
        'columns' => 
        array (
          0 => 
          array (
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'width' => '160px',
          ),
          1 => 
          array (
            'key' => 'location',
            'label' => 'Location',
            'type' => 'text',
            'width' => 'auto',
          ),
          2 => 
          array (
            'key' => 'paramedics',
            'label' => 'Paramedics Y/N',
            'type' => 'text',
            'width' => '100px',
          ),
        ),
      ),
      5 => 
      array (
        'key' => 'transportation',
        'label' => 'Transportation',
        'type' => 'table',
        'required' => false,
        'columns' => 
        array (
          0 => 
          array (
            'key' => 'service',
            'label' => 'Ambulance Service',
            'type' => 'text',
            'width' => '160px',
          ),
          1 => 
          array (
            'key' => 'address',
            'label' => 'Address',
            'type' => 'text',
            'width' => 'auto',
          ),
          2 => 
          array (
            'key' => 'phone',
            'label' => 'Phone',
            'type' => 'text',
            'width' => '120px',
          ),
          3 => 
          array (
            'key' => 'paramedics',
            'label' => 'Paramedics Y/N',
            'type' => 'text',
            'width' => '100px',
          ),
        ),
      ),
      6 => 
      array (
        'key' => 'hospitals',
        'label' => 'Hospitals',
        'type' => 'table',
        'required' => false,
        'columns' => 
        array (
          0 => 
          array (
            'key' => 'name',
            'label' => 'Name',
            'type' => 'text',
            'width' => '140px',
          ),
          1 => 
          array (
            'key' => 'address',
            'label' => 'Address',
            'type' => 'text',
            'width' => 'auto',
          ),
          2 => 
          array (
            'key' => 'phone',
            'label' => 'Phone',
            'type' => 'text',
            'width' => '110px',
          ),
          3 => 
          array (
            'key' => 'travel_time',
            'label' => 'Travel Time',
            'type' => 'text',
            'width' => '80px',
          ),
          4 => 
          array (
            'key' => 'trauma_level',
            'label' => 'Trauma Ctr Level',
            'type' => 'text',
            'width' => '90px',
          ),
          5 => 
          array (
            'key' => 'helipad',
            'label' => 'Helipad Y/N',
            'type' => 'text',
            'width' => '80px',
          ),
        ),
      ),
      7 => 
      array (
        'key' => 'medical_procedures',
        'label' => 'Medical Emergency Procedures',
        'type' => 'textarea',
        'required' => false,
        'rows' => 6,
      ),
      8 => 
      array (
        'key' => 'prepared_by',
        'label' => 'Prepared By (Name/Position)',
        'type' => 'text',
        'required' => false,
      ),
    ),
  ),
  '214a' => 
  array (
    'form_type' => '214a',
    'form_number' => 'ICS-214a',
    'form_title' => 'Individual Activity Log',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'incident_name',
        'label' => 'Incident Name',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'date_prepared',
        'label' => 'Date Prepared',
        'type' => 'date',
        'required' => true,
      ),
      2 => 
      array (
        'key' => 'op_period_from',
        'label' => 'Operational Period From',
        'type' => 'datetime-local',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'op_period_to',
        'label' => 'Operational Period To',
        'type' => 'datetime-local',
        'required' => true,
      ),
      4 => 
      array (
        'key' => 'individual_name',
        'label' => 'Name',
        'type' => 'text',
        'required' => true,
      ),
      5 => 
      array (
        'key' => 'ics_position',
        'label' => 'ICS Position',
        'type' => 'text',
        'required' => false,
      ),
      6 => 
      array (
        'key' => 'home_agency',
        'label' => 'Home Agency',
        'type' => 'text',
        'required' => false,
      ),
      7 => 
      array (
        'key' => 'activity_log',
        'label' => 'Activity Log',
        'type' => 'table',
        'required' => false,
        'columns' => 
        array (
          0 => 
          array (
            'key' => 'time',
            'label' => 'Time',
            'type' => 'time',
            'width' => '120px',
          ),
          1 => 
          array (
            'key' => 'activity',
            'label' => 'Notable Activities',
            'type' => 'text',
            'width' => 'auto',
          ),
        ),
      ),
      8 => 
      array (
        'key' => 'prepared_by',
        'label' => 'Prepared By',
        'type' => 'text',
        'required' => false,
      ),
    ),
  ),
  221 => 
  array (
    'form_type' => '221',
    'form_number' => 'ICS-221',
    'form_title' => 'Demobilization Check-Out',
    'fields' => 
    array (
      0 => 
      array (
        'key' => 'incident_name',
        'label' => 'Incident Name/Number',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'key' => 'date_time',
        'label' => 'Date/Time',
        'type' => 'datetime-local',
        'required' => true,
      ),
      2 => 
      array (
        'key' => 'resource_unit_id',
        'label' => 'Resource/Unit ID',
        'type' => 'text',
        'required' => true,
      ),
      3 => 
      array (
        'key' => 'leader_name',
        'label' => 'Leader Name',
        'type' => 'text',
        'required' => true,
      ),
      4 => 
      array (
        'key' => 'chk_logistics',
        'label' => 'Logistics Section',
        'type' => 'text',
        'required' => false,
      ),
      5 => 
      array (
        'key' => 'chk_supply',
        'label' => 'Supply Unit',
        'type' => 'text',
        'required' => false,
      ),
      6 => 
      array (
        'key' => 'chk_communications',
        'label' => 'Communications Unit',
        'type' => 'text',
        'required' => false,
      ),
      7 => 
      array (
        'key' => 'chk_facilities',
        'label' => 'Facilities Unit',
        'type' => 'text',
        'required' => false,
      ),
      8 => 
      array (
        'key' => 'chk_ground_support',
        'label' => 'Ground Support Unit',
        'type' => 'text',
        'required' => false,
      ),
      9 => 
      array (
        'key' => 'reassign_destination',
        'label' => 'Reassignment Destination',
        'type' => 'text',
        'required' => false,
      ),
      10 => 
      array (
        'key' => 'reassign_eta',
        'label' => 'Reassignment ETA',
        'type' => 'text',
        'required' => false,
      ),
      11 => 
      array (
        'key' => 'reassign_area',
        'label' => 'Area/Agency/Region',
        'type' => 'text',
        'required' => false,
      ),
      12 => 
      array (
        'key' => 'travel_room',
        'label' => 'Room Reservations',
        'type' => 'text',
        'required' => false,
      ),
      13 => 
      array (
        'key' => 'travel_method',
        'label' => 'Travel Method',
        'type' => 'text',
        'required' => false,
      ),
      14 => 
      array (
        'key' => 'travel_contact',
        'label' => 'Contact Information',
        'type' => 'text',
        'required' => false,
      ),
      15 => 
      array (
        'key' => 'remarks',
        'label' => 'Remarks',
        'type' => 'textarea',
        'required' => false,
        'rows' => 4,
      ),
      16 => 
      array (
        'key' => 'prepared_by',
        'label' => 'Prepared By',
        'type' => 'text',
        'required' => false,
      ),
      17 => 
      array (
        'key' => 'approved_unit_leader',
        'label' => 'Approved By (Unit Leader)',
        'type' => 'text',
        'required' => false,
      ),
      18 => 
      array (
        'key' => 'approved_planning',
        'label' => 'Approved By (Planning Section)',
        'type' => 'text',
        'required' => false,
      ),
      19 => 
      array (
        'key' => 'approved_logistics',
        'label' => 'Approved By (Logistics)',
        'type' => 'text',
        'required' => false,
      ),
    ),
  ),
);
