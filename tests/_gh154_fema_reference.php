<?php
/**
 * GH#154 — the FEMA reference table: every form's numbered blocks, in order,
 * with FEMA's block titles, typed by hand from the NIMS ICS Forms Booklet
 * (FEMA 502-2, September 2010) — the blank form pages and their block
 * instruction tables. Kept apart from inc/ics-forms-builtin.php on purpose:
 * tests/test_gh154_ics_forms_fema_reference.php compares the templates with
 * THIS, so a typo in a template cannot also be a typo in its own check.
 *
 * Forms without numbered blocks (ICS 217A and the ICS 219 cards) list their
 * field and column labels under 'labels' instead.
 *
 * Notes on titles:
 *  - Where the form page and the instruction table word a title slightly
 *    differently, the form page wins (e.g. ICS 225 block 4 "Home Unit Name
 *    and Address").
 *  - ICS 208 HM blocks 15 and 16 are blank on the FEMA form ("use boxes 15 and
 *    16 for other functions, i.e. Medical Monitoring"); they are titled
 *    "Other Function" here.
 *  - ICS 209 block 22 is two labels on the form ("Longitude (indicate format)",
 *    "Latitude (indicate format)"); titled "Longitude/Latitude".
 *  - ICS 204 block 3 is four labels on the form; titled "Branch, Division,
 *    Group, Staging Area".
 */
return [
    '201' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Incident Number', '3' => 'Date/Time Initiated',
        '4' => 'Map/Sketch', '5' => 'Situation Summary and Health and Safety Briefing',
        '6' => 'Prepared by', '7' => 'Current and Planned Objectives',
        '8' => 'Current and Planned Actions, Strategies, and Tactics',
        '9' => 'Current Organization', '10' => 'Resource Summary',
    ]],
    '202' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Objective(s)',
        '4' => 'Operational Period Command Emphasis', '5' => 'Site Safety Plan Required?',
        '6' => 'Incident Action Plan', '7' => 'Prepared by', '8' => 'Approved by Incident Commander',
    ]],
    '203' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Incident Commander(s) and Command Staff',
        '4' => 'Agency/Organization Representatives', '5' => 'Planning Section', '6' => 'Logistics Section',
        '7' => 'Operations Section', '8' => 'Finance/Administration Section', '9' => 'Prepared by',
    ]],
    '204' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Branch, Division, Group, Staging Area',
        '4' => 'Operations Personnel', '5' => 'Resources Assigned', '6' => 'Work Assignments',
        '7' => 'Special Instructions',
        '8' => 'Communications (radio and/or phone contact numbers needed for this assignment)',
        '9' => 'Prepared by',
    ]],
    '205' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Date/Time Prepared', '3' => 'Operational Period',
        '4' => 'Basic Radio Channel Use', '5' => 'Special Instructions',
        '6' => 'Prepared by (Communications Unit Leader)',
    ]],
    '205a' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Basic Local Communications Information',
        '4' => 'Prepared by',
    ]],
    '206' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Medical Aid Stations',
        '4' => 'Transportation (indicate air or ground)', '5' => 'Hospitals',
        '6' => 'Special Medical Emergency Procedures', '7' => 'Prepared by (Medical Unit Leader)',
        '8' => 'Approved by (Safety Officer)',
    ]],
    '207' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Organization Chart', '4' => 'Prepared by',
    ]],
    '208' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period',
        '3' => 'Safety Message/Expanded Safety Message, Safety Plan, Site Safety Plan',
        '4' => 'Site Safety Plan Required?', '5' => 'Prepared by',
    ]],
    '208hm' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Date Prepared', '3' => 'Operational Period', '4' => 'Incident Location',
        '5' => 'Incident Commander', '6' => 'HM Group Supervisor', '7' => 'Tech. Specialist - HM Reference',
        '8' => 'Safety Officer', '9' => 'Entry Leader', '10' => 'Site Access Control Leader',
        '11' => 'Asst. Safety Officer - HM', '12' => 'Decontamination Leader', '13' => 'Safe Refuge Area Mgr',
        '14' => 'Environmental Health', '15' => 'Other Function', '16' => 'Other Function',
        '17' => 'Entry Team (Buddy System)', '18' => 'Decontamination Element', '19' => 'Material',
        '20' => 'LEL Instrument(s)', '21' => 'O2 Instrument(s)', '22' => 'Toxicity/PPM Instrument(s)',
        '23' => 'Radiological Instrument(s)', '24' => 'Standard Decontamination Procedures',
        '25' => 'Command Frequency', '26' => 'Tactical Frequency', '27' => 'Entry Frequency',
        '28' => 'Medical Monitoring', '29' => 'Medical Treatment and Transport In-place', '30' => 'Site Map',
        '31' => 'Entry Objectives', '32' => 'Modifications to Documented SOPs or Work Practices',
        '33' => 'Emergency Procedures', '34' => 'Asst. Safety Officer - HM Signature',
        '35' => 'HM Group Supervisor Signature', '36' => 'Incident Commander Signature',
    ]],
    '209' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Incident Number', '3' => 'Report Version',
        '4' => 'Incident Commander(s) & Agency or Organization', '5' => 'Incident Management Organization',
        '6' => 'Incident Start Date/Time', '7' => 'Current Incident Size or Area Involved',
        '8' => 'Percent (%) Contained or Completed', '9' => 'Incident Definition',
        '10' => 'Incident Complexity Level', '11' => 'For Time Period', '12' => 'Prepared By',
        '13' => 'Date/Time Submitted', '14' => 'Approved By',
        '15' => 'Primary Location, Organization, or Agency Sent To', '16' => 'State',
        '17' => 'County/Parish/Borough', '18' => 'City', '19' => 'Unit or Other', '20' => 'Incident Jurisdiction',
        '21' => 'Incident Location Ownership (if different than jurisdiction)', '22' => 'Longitude/Latitude',
        '23' => 'US National Grid Reference', '24' => 'Legal Description (township, section, range)',
        '25' => 'Short Location or Area Description', '26' => 'UTM Coordinates',
        '27' => 'Note any electronic geospatial data included or attached',
        '28' => 'Significant Events for the Time Period Reported', '29' => 'Primary Materials or Hazards Involved',
        '30' => 'Damage Assessment Information', '31' => 'Public Status Summary',
        '32' => 'Responder Status Summary', '33' => 'Life, Safety, and Health Status/Threat Remarks',
        '34' => 'Life, Safety, and Health Threat Management', '35' => 'Weather Concerns',
        '36' => 'Projected Incident Activity, Potential, Movement, Escalation, or Spread',
        '37' => 'Strategic Objectives', '38' => 'Current Incident Threat Summary and Risk Information',
        '39' => 'Critical Resource Needs', '40' => 'Strategic Discussion',
        '41' => 'Planned Actions for Next Operational Period', '42' => 'Projected Final Incident Size/Area',
        '43' => 'Anticipated Incident Management Completion Date',
        '44' => 'Projected Significant Resource Demobilization Start Date',
        '45' => 'Estimated Incident Costs to Date', '46' => 'Projected Final Incident Cost Estimate',
        '47' => 'Remarks', '48' => 'Agency or Organization', '49' => 'Resources',
        '50' => 'Additional Personnel not assigned to a resource', '51' => 'Total Personnel',
        '52' => 'Total Resources', '53' => 'Additional Cooperating and Assisting Organizations Not Listed Above',
    ]],
    '210' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Resource Number',
        '4' => 'New Status (Available, Assigned, O/S)', '5' => 'From (Assignment and Status)',
        '6' => 'To (Assignment and Status)', '7' => 'Time and Date of Change', '8' => 'Comments',
        '9' => 'Prepared by',
    ]],
    '211' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Incident Number', '3' => 'Check-In Location', '4' => 'Start Date/Time',
        '5' => 'List single resource personnel (overhead) by agency and name, OR list resources by the following format',
        '6' => 'Order Request #', '7' => 'Date/Time Check-In', '8' => "Leader's Name",
        '9' => 'Total Number of Personnel', '10' => 'Incident Contact Information', '11' => 'Home Unit or Agency',
        '12' => 'Departure Point, Date and Time', '13' => 'Method of Travel', '14' => 'Incident Assignment',
        '15' => 'Other Qualifications', '16' => 'Data Provided to Resources Unit', '17' => 'Prepared by',
    ]],
    '213' => ['blocks' => [
        '1' => 'Incident Name (Optional)', '2' => 'To (Name and Position)', '3' => 'From (Name and Position)',
        '4' => 'Subject', '5' => 'Date', '6' => 'Time', '7' => 'Message', '8' => 'Approved by', '9' => 'Reply',
        '10' => 'Replied by',
    ]],
    '213rr' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Date/Time', '3' => 'Resource Request Number', '4' => 'Order',
        '5' => 'Requested Delivery/Reporting Location', '6' => 'Suitable Substitutes and/or Suggested Sources',
        '7' => 'Requested by Name/Position', '8' => 'Priority', '9' => 'Section Chief Approval',
        '10' => 'Logistics Order Number', '11' => 'Supplier Phone/Fax/Email', '12' => 'Name of Supplier/POC',
        '13' => 'Notes', '14' => 'Approval Signature of Auth Logistics Rep', '15' => 'Date/Time',
        '16' => 'Order placed by', '17' => 'Reply/Comments from Finance', '18' => 'Finance Section Signature',
        '19' => 'Date/Time',
    ]],
    '214' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Name', '4' => 'ICS Position',
        '5' => 'Home Agency (and Unit)', '6' => 'Resources Assigned', '7' => 'Activity Log', '8' => 'Prepared by',
    ]],
    '215' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Branch', '4' => 'Division, Group, or Other',
        '5' => 'Work Assignment & Special Instructions', '6' => 'Resources', '7' => 'Overhead Position(s)',
        '8' => 'Special Equipment & Supplies', '9' => 'Reporting Location', '10' => 'Requested Arrival Time',
        '11' => 'Total Resources Required', '12' => 'Total Resources Have on Hand',
        '13' => 'Total Resources Need To Order', '14' => 'Prepared by',
    ]],
    '215a' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Incident Number', '3' => 'Date/Time Prepared', '4' => 'Operational Period',
        '5' => 'Incident Area', '6' => 'Hazards/Risks', '7' => 'Mitigations',
        '8' => 'Prepared by (Safety Officer and Operations Section Chief)',
    ]],
    '217a' => ['blocks' => [], 'labels' => [
        'Frequency Band', 'Description', 'Pg No', 'Channel Configuration',
        'Channel Name/Trunked Radio System Talkgroup', 'Eligible Users', 'RX Freq', 'N or W', 'RX Tone/NAC',
        'TX Freq', 'TX Tone/NAC', 'Mode (A, D, or M)', 'Remarks',
    ]],
    '218' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Incident Number', '3' => 'Date/Time Prepared',
        '4' => 'Vehicle/Equipment Category', '5' => 'Vehicle/Equipment Information', '6' => 'Prepared by',
    ]],
    '219-1' => ['blocks' => [], 'labels' => ['Prepared by', 'Date/Time']],
    '219-2' => ['blocks' => [], 'labels' => [
        'ST/Unit', 'LDW', '# Pers', 'Order #', 'Agency', 'Cat/Kind/Type', 'Name/ID #',
        'Date/Time Checked In', 'Leader Name', 'Primary Contact Information', 'Crew/Team ID #(s) or Name(s)',
        'Manifest', 'Total Weight', 'Method of Travel to Incident', 'Home Base', 'Departure Point', 'ETD', 'ETA',
        'Transportation Needs at Incident', 'Date/Time Ordered', 'Remarks',
        'Incident Location', 'Time', 'Status', 'ETR', 'Notes', 'Prepared by', 'Date/Time',
    ]],
    '219-3' => ['blocks' => [], 'labels' => [
        'ST/Unit', 'LDW', '# Pers', 'Order #', 'Agency', 'Cat/Kind/Type', 'Name/ID #',
        'Date/Time Checked In', 'Leader Name', 'Primary Contact Information', 'Resource ID #(s) or Name(s)',
        'Home Base', 'Departure Point', 'ETD', 'ETA', 'Date/Time Ordered', 'Remarks',
        'Incident Location', 'Time', 'Status', 'ETR', 'Notes', 'Prepared by', 'Date/Time',
    ]],
    '219-4' => ['blocks' => [], 'labels' => [
        'ST/Unit', 'LDW', '# Pers', 'Order #', 'Agency', 'Cat/Kind/Type', 'Name/ID #',
        'Date/Time Checked In', 'Pilot Name', 'Home Base', 'Departure Point', 'ETD', 'ETA', 'Destination Point',
        'Date/Time Ordered', 'Remarks',
        'Incident Location', 'Time', 'Status', 'ETR', 'Notes', 'Prepared by', 'Date/Time',
    ]],
    '219-5' => ['blocks' => [], 'labels' => [
        'ST/Unit', 'Name', 'Position/Title',
        'Date/Time Checked In', 'Full Name', 'Primary Contact Information', 'Manifest', 'Total Weight',
        'Method of Travel to Incident', 'Home Base', 'Departure Point', 'ETD', 'ETA',
        'Transportation Needs at Incident', 'Date/Time Ordered', 'Remarks',
        'Incident Location', 'Time', 'Status', 'ETR', 'Notes', 'Prepared by', 'Date/Time',
    ]],
    '219-6' => ['blocks' => [], 'labels' => [
        'ST/Unit', 'LDW', '# Pers', 'Order #', 'Agency', 'Cat/Kind/Type', 'Name/ID #',
        'Date/Time Checked In', 'Pilot Name', 'Home Base', 'Departure Point', 'ETD', 'ETA', 'Destination Point',
        'Date/Time Ordered', 'Manufacturer', 'Remarks',
        'Incident Location', 'Time', 'Status', 'ETR', 'Notes', 'Prepared by', 'Date/Time',
    ]],
    '219-7' => ['blocks' => [], 'labels' => [
        'ST/Unit', 'LDW', '# Pers', 'Order #', 'Agency', 'Cat/Kind/Type', 'Name/ID #',
        'Date/Time Checked In', 'Leader Name', 'Primary Contact Information', 'Resource ID #(s) or Name(s)',
        'Home Base', 'Departure Point', 'ETD', 'ETA', 'Date/Time Ordered', 'Remarks',
        'Incident Location', 'Time', 'Status', 'ETR', 'Notes', 'Prepared by', 'Date/Time',
    ]],
    '219-8' => ['blocks' => [], 'labels' => [
        'ST/Unit', 'LDW', '# Pers', 'Order #', 'Agency', 'Cat/Kind/Type', 'Name/ID #',
        'Date/Time Checked In', 'Leader Name', 'Primary Contact Information', 'Resource ID #(s) or Name(s)',
        'Home Base', 'Departure Point', 'ETD', 'ETA', 'Date/Time Ordered', 'Remarks',
        'Incident Location', 'Time', 'Status', 'ETR', 'Notes', 'Prepared by', 'Date/Time',
    ]],
    '219-10' => ['blocks' => [], 'labels' => [
        'ST/Unit', 'LDW', '# Pers', 'Order #', 'Agency', 'Cat/Kind/Type', 'Name/ID #',
        'Date/Time Checked In', 'Leader Name', 'Primary Contact Information', 'Resource ID #(s) or Name(s)',
        'Home Base', 'Departure Point', 'ETD', 'ETA', 'Date/Time Ordered', 'Remarks',
        'Incident Location', 'Time', 'Status', 'ETR', 'Notes', 'Prepared by', 'Date/Time',
    ]],
    '220' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period', '3' => 'Sunrise/Sunset',
        '4' => 'Remarks (safety notes, hazards, air operations special equipment, etc.)',
        '5' => 'Ready Alert Aircraft', '6' => 'Temporary Flight Restriction Number', '7' => 'Personnel',
        '8' => 'Frequencies', '9' => 'Fixed-Wing (category/kind/type, make/model, N#, base)',
        '10' => 'Helicopters', '11' => 'Prepared by', '12' => 'Task/Mission/Assignment',
    ]],
    '221' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Incident Number', '3' => 'Planned Release Date/Time',
        '4' => 'Resource or Personnel Released', '5' => 'Order Request Number', '6' => 'Resource or Personnel',
        '7' => 'Remarks', '8' => 'Travel Information', '9' => 'Reassignment Information', '10' => 'Prepared by',
    ]],
    '225' => ['blocks' => [
        '1' => 'Name', '2' => 'Incident Name', '3' => 'Incident Number', '4' => 'Home Unit Name and Address',
        '5' => 'Incident Agency and Address', '6' => 'Position Held on Incident', '7' => 'Date(s) of Assignment',
        '8' => 'Incident Complexity Level', '9' => 'Incident Definition', '10' => 'Evaluation Rating Factors',
        '11' => 'Knowledge of the Job/Professional Competence', '12' => 'Ability To Obtain Performance/Results',
        '13' => 'Planning/Preparedness', '14' => 'Using Resources', '15' => 'Adaptability/Attitude',
        '16' => 'Communication Skills', '17' => 'Ability To Work on a Team',
        '18' => 'Consideration for Personnel/Team Welfare', '19' => 'Directing Others',
        '20' => 'Judgment/Decisions Under Stress', '21' => 'Initiative', '22' => 'Physical Ability for the Job',
        '23' => 'Adherence to Safety', '24' => 'Remarks',
        '25' => 'Rated Individual (This rating has been discussed with me)', '26' => 'Rated by',
    ]],
    '230' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'Operational Period (Date/Time)', '3' => 'Meeting Schedule',
        '4' => 'Prepared by (Situation Unit Leader)',
    ]],
    '233' => ['blocks' => [
        '1' => 'Incident Name', '2' => 'No.', '3' => 'Item', '4' => 'For/POC', '5' => 'Briefed POC (X)',
        '6' => 'Start Date', '7' => 'Status', '8' => 'Target Date', '9' => 'Actual Date',
    ]],
];
