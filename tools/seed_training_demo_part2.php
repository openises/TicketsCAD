<?php
/**
 * Training demo seed — part 2.
 *
 * Adds to the part-1 seed (3 orgs, 75 members, etc.):
 *   - Shift templates with slots and roles per org
 *   - Sample drills / training events
 *   - 60 historical incidents spread across the last 90 days,
 *     drawn from each org's incident type catalog, with unit
 *     assignments and action-log entries to look lived-in
 *
 * Run after seed_training_demo.php on the training instance:
 *   php tools/seed_training_demo_part2.php
 *
 * Idempotent. Re-runs skip already-seeded shifts/events/incidents.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Training demo seed — part 2 ===\n\n";

// ── Map org short_name → id ──
$orgs = [];
foreach (db_fetch_all("SELECT id, short_name FROM `{$prefix}organizations`") as $r) {
    $orgs[$r['short_name']] = (int) $r['id'];
}
if (count($orgs) < 3) {
    fwrite(STDERR, "Expected 3 orgs from part 1 seed — found " . count($orgs) . "\n");
    exit(1);
}

// ── 1. Shift templates ─────────────────────────────────────────
echo "Shift templates...\n";

$shiftTemplates = [
    [
        'name'         => 'CERT Monthly Meeting',
        'description'  => 'Monthly CERT business meeting — second Tuesday, 19:00–21:00',
        'rotation_weeks' => 4,
        'slots' => [
            // dow 2 = Tuesday (MariaDB DAYOFWEEK: 1=Sun..7=Sat; here we use 2=Tue)
            ['dow' => 2, 'start' => '19:00:00', 'end' => '21:00:00', 'label' => '2nd Tuesday Meeting'],
        ],
        'roles' => [
            ['role_name' => 'Meeting Chair', 'min_slots' => 1, 'max_slots' => 1, 'description' => 'Runs the agenda'],
            ['role_name' => 'Secretary',     'min_slots' => 1, 'max_slots' => 1, 'description' => 'Takes minutes'],
            ['role_name' => 'Attendee',      'min_slots' => 5, 'max_slots' => 25, 'description' => 'Open to all active CERT members'],
        ],
    ],
    [
        'name'         => 'CERT Quarterly Drill',
        'description'  => 'Quarterly tabletop or field drill — third Saturday of the quarter',
        'rotation_weeks' => 13,
        'slots' => [
            ['dow' => 7, 'start' => '08:00:00', 'end' => '14:00:00', 'label' => '3rd Saturday — Drill day'],
        ],
        'roles' => [
            ['role_name' => 'Drill Lead',          'min_slots' => 1, 'max_slots' => 1, 'description' => 'Designs and runs the drill scenario'],
            ['role_name' => 'Team Alpha Lead',     'min_slots' => 1, 'max_slots' => 1, 'description' => 'Strike Team Alpha'],
            ['role_name' => 'Team Bravo Lead',     'min_slots' => 1, 'max_slots' => 1, 'description' => 'Strike Team Bravo'],
            ['role_name' => 'Field Participant',   'min_slots' => 8, 'max_slots' => 20, 'description' => 'Boots on the ground'],
            ['role_name' => 'Safety Officer',      'min_slots' => 1, 'max_slots' => 2, 'description' => 'Watches the drill for safety issues'],
        ],
    ],
    [
        'name'         => 'AUXCOMM Weekly Net',
        'description'  => 'Tuesday evening voice net, 19:30 local — primary VHF repeater',
        'rotation_weeks' => 1,
        'slots' => [
            ['dow' => 2, 'start' => '19:30:00', 'end' => '20:30:00', 'label' => 'Tuesday Net'],
        ],
        'roles' => [
            ['role_name' => 'Net Control',      'min_slots' => 1, 'max_slots' => 1, 'description' => 'Runs the net'],
            ['role_name' => 'Alternate NC',     'min_slots' => 1, 'max_slots' => 1, 'description' => 'Backup if primary NC unable'],
            ['role_name' => 'Check-in',         'min_slots' => 5, 'max_slots' => 30, 'description' => 'Member check-ins'],
        ],
    ],
    [
        'name'         => 'AUXCOMM Monthly Activation Drill',
        'description'  => 'Quarterly EOC activation exercise — second Saturday of each quarter',
        'rotation_weeks' => 13,
        'slots' => [
            ['dow' => 7, 'start' => '09:00:00', 'end' => '15:00:00', 'label' => '2nd Saturday — Activation Drill'],
        ],
        'roles' => [
            ['role_name' => 'EOC Comm Officer', 'min_slots' => 1, 'max_slots' => 1, 'description' => 'Primary at EOC'],
            ['role_name' => 'Backup Operator',  'min_slots' => 1, 'max_slots' => 2, 'description' => 'At EOC, supports primary'],
            ['role_name' => 'Mobile Asset',     'min_slots' => 2, 'max_slots' => 6, 'description' => 'Field-deployed operators'],
            ['role_name' => 'Logger',           'min_slots' => 1, 'max_slots' => 2, 'description' => 'Maintains ICS-214'],
        ],
    ],
    [
        'name'         => 'Vol Fire Weekly Training',
        'description'  => 'Tuesday evening training — 19:00 at Station 1',
        'rotation_weeks' => 1,
        'slots' => [
            ['dow' => 2, 'start' => '19:00:00', 'end' => '21:00:00', 'label' => 'Tuesday Training'],
        ],
        'roles' => [
            ['role_name' => 'Training Officer', 'min_slots' => 1, 'max_slots' => 1, 'description' => 'Leads the session'],
            ['role_name' => 'Apparatus Driver', 'min_slots' => 1, 'max_slots' => 2, 'description' => 'Apparatus checked out for training'],
            ['role_name' => 'Firefighter',      'min_slots' => 4, 'max_slots' => 15, 'description' => 'Active member participating'],
        ],
    ],
    [
        'name'         => 'Vol Fire On-Call Rotation',
        'description'  => 'Weekly on-call shifts — primary, backup, chief',
        'rotation_weeks' => 1,
        'slots' => [
            ['dow' => 1, 'start' => '00:00:00', 'end' => '23:59:59', 'label' => 'Sunday'],
            ['dow' => 2, 'start' => '00:00:00', 'end' => '23:59:59', 'label' => 'Monday'],
            ['dow' => 3, 'start' => '00:00:00', 'end' => '23:59:59', 'label' => 'Tuesday'],
            ['dow' => 4, 'start' => '00:00:00', 'end' => '23:59:59', 'label' => 'Wednesday'],
            ['dow' => 5, 'start' => '00:00:00', 'end' => '23:59:59', 'label' => 'Thursday'],
            ['dow' => 6, 'start' => '00:00:00', 'end' => '23:59:59', 'label' => 'Friday'],
            ['dow' => 7, 'start' => '00:00:00', 'end' => '23:59:59', 'label' => 'Saturday'],
        ],
        'roles' => [
            ['role_name' => 'On-Call Officer', 'min_slots' => 1, 'max_slots' => 1, 'description' => 'Initial command'],
            ['role_name' => 'On-Call Driver', 'min_slots' => 1, 'max_slots' => 2, 'description' => 'Apparatus driver'],
            ['role_name' => 'On-Call Crew',   'min_slots' => 2, 'max_slots' => 4, 'description' => 'Available to respond'],
        ],
    ],
];

$createdTemplates = 0;
foreach ($shiftTemplates as $t) {
    $existing = (int) (db_fetch_value(
        "SELECT id FROM `{$prefix}newui_shift_templates` WHERE name = ?", [$t['name']]
    ) ?: 0);
    if ($existing) {
        echo "  '{$t['name']}' exists (#{$existing}) — skipping\n";
        continue;
    }
    db_query(
        "INSERT INTO `{$prefix}newui_shift_templates`
         (name, description, rotation_weeks, timezone, active, created_at, updated_at)
         VALUES (?, ?, ?, 'America/Chicago', 1, NOW(), NOW())",
        [$t['name'], $t['description'], $t['rotation_weeks']]
    );
    $tid = (int) db_insert_id();

    foreach ($t['slots'] as $i => $slot) {
        db_query(
            "INSERT INTO `{$prefix}newui_shift_slots`
             (template_id, day_of_week, start_time, end_time, week_number, label)
             VALUES (?, ?, ?, ?, 1, ?)",
            [$tid, $slot['dow'], $slot['start'], $slot['end'], $slot['label']]
        );
    }

    foreach ($t['roles'] as $i => $role) {
        db_query(
            "INSERT INTO `{$prefix}newui_shift_roles`
             (template_id, role_name, description, min_slots, max_slots, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$tid, $role['role_name'], $role['description'], $role['min_slots'], $role['max_slots'], $i]
        );
    }

    echo "  Created '{$t['name']}' (#{$tid}) — " . count($t['slots']) . " slots, " . count($t['roles']) . " roles\n";
    $createdTemplates++;
}
echo "  {$createdTemplates} new shift templates\n";

// ── 2. Sample upcoming events ─────────────────────────────────
echo "\nEvents (upcoming drills + meetings)...\n";

$events = [
    // CERT
    ['CERT', 'CERT Spring Quarterly Drill',
     'Search & Rescue scenario in Riverbend Park. Combined with AUXCOMM comms support. Bring full kit.',
     'drill', '+14 days 08:00', '+14 days 14:00',
     'Riverbend Park', 25],
    ['CERT', 'CERT Monthly Meeting — Hazard Awareness',
     'Guest speaker: County Emergency Manager. Hazard Identification & Risk Assessment update.',
     'meeting', '+7 days 19:00', '+7 days 21:00',
     'Cedar Falls Community Center', 30],
    // AUXCOMM
    ['AUXCOMM', 'AUXCOMM Field Day',
     'ARRL Field Day participation. Set up portable stations at Riverbend Park. 24-hour operation.',
     'exercise', '+45 days 14:00', '+46 days 14:00',
     'Riverbend Park Pavilion', 20],
    ['AUXCOMM', 'EOC Activation Drill',
     'Simulated severe weather activation. Test full ICS-205 frequency plan.',
     'drill', '+21 days 09:00', '+21 days 15:00',
     'County EOC', 12],
    ['AUXCOMM', 'Repeater Maintenance Day',
     'Annual antenna inspection at Mt Vernon repeater site. Bring climbing gear if qualified.',
     'training', '+30 days 08:00', '+30 days 16:00',
     'Repeater Site — Mt Vernon', 6],
    // Vol Fire
    ['Vol Fire', 'Live-fire Training — Burn Building',
     'Annual interior structure burn at the regional training center. Mutual aid from county departments.',
     'training', '+60 days 08:00', '+60 days 17:00',
     'Regional Fire Training Center', 18],
    ['Vol Fire', 'Tanker Shuttle Exercise',
     'Rural water supply drill. All apparatus. Coordinate with neighboring departments.',
     'exercise', '+35 days 09:00', '+35 days 14:00',
     'Riverbend Township', 25],
    ['Vol Fire', 'Department Meeting — Budget',
     'Annual budget review. Officers required, members welcome.',
     'meeting', '+10 days 19:00', '+10 days 21:00',
     'Station 1 — Main', 15],
    ['Vol Fire', 'Multi-Agency MVA Drill',
     'Combined exercise with CERT and AUXCOMM. Simulated multi-vehicle accident with mass casualty.',
     'drill', '+55 days 09:00', '+55 days 15:00',
     'Highway 7 mile marker 47', 35],
];

$createdEvents = 0;
foreach ($events as [$orgShort, $name, $desc, $type, $startRel, $endRel, $location, $maxP]) {
    $existing = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_events` WHERE name = ?", [$name]
    );
    if ($existing) continue;
    $start = date('Y-m-d H:i:s', strtotime($startRel));
    $end   = date('Y-m-d H:i:s', strtotime($endRel));
    db_query(
        "INSERT INTO `{$prefix}newui_events`
         (name, event_type, description, start_date, end_date, location, max_participants,
          status, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'planned', 1, NOW(), NOW())",
        [$name, $type, $desc, $start, $end, $location, $maxP]
    );
    $createdEvents++;
}
echo "  {$createdEvents} new upcoming events\n";

// ── 3. Historical incidents (last 90 days) ─────────────────────
echo "\nHistorical incidents...\n";

// Pull existing assets we'll reference
$incidentTypeRows = db_fetch_all(
    "SELECT id, type, `group`, set_severity FROM `{$prefix}in_types` ORDER BY id"
);

$typesByOrg = ['CERT' => [], 'AUXCOMM' => [], 'Vol Fire' => []];
foreach ($incidentTypeRows as $t) {
    $grp = $t['group'] ?? '';
    if (isset($typesByOrg[$grp])) {
        $typesByOrg[$grp][] = $t;
    }
}

$responders = db_fetch_all("SELECT id, name, handle FROM `{$prefix}responder`");
$facilities = db_fetch_all("SELECT id, name FROM `{$prefix}facilities`");

// Realistic-looking addresses to seed incident locations
$addresses = [
    ['123 Main St',           'Cedar Falls', 'IA', 42.5240, -92.4453],
    ['450 Oak Ave',           'Cedar Falls', 'IA', 42.5301, -92.4521],
    ['2200 Park Blvd',        'Cedar Falls', 'IA', 42.5180, -92.4400],
    ['78 Maple Ln',           'Cedar Falls', 'IA', 42.5360, -92.4480],
    ['1100 Hospital Dr',      'Cedar Falls', 'IA', 42.5180, -92.4395],
    ['4500 Township Hwy',     'Riverbend',   'IA', 42.5800, -92.5200],
    ['300 Engine House Rd',   'Riverbend',   'IA', 42.6000, -92.5000],
    ['Highway 7 mile marker 47','Riverbend', 'IA', 42.5850, -92.5050],
    ['15 Elm Ct',             'Riverbend',   'IA', 42.5950, -92.4995],
    ['8200 N River Rd',       'Riverbend',   'IA', 42.6050, -92.5100],
    ['200 EOC Way',           'Cedar Falls', 'IA', 42.5252, -92.4459],
    ['500 Civic Center Dr',   'Cedar Falls', 'IA', 42.5250, -92.4460],
    ['1500 Mountain Rd',      'Mount Vernon','IA', 41.9220, -91.4193],
    ['4400 South County Rd',  'Riverbend',   'IA', 42.5700, -92.5000],
    ['622 W First St',        'Cedar Falls', 'IA', 42.5275, -92.4500],
];

$firstNames = ['James','Mary','Robert','Patricia','John','Linda','David','Susan','Joseph','Jessica','Thomas','Sarah','Michael','Karen','Daniel','Lisa','Mark','Sandra','Donald','Ashley','Steven','Kimberly','Andrew','Emily','Paul','Donna','Joshua','Michelle','Kenneth','Carol'];
$lastNames  = ['Smith','Johnson','Williams','Brown','Jones','Miller','Davis','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin','Lee','Thompson','White','Harris','Clark','Lewis','Walker','Allen','King','Wright','Scott','Hill','Green','Adams','Nelson','Hall'];

mt_srand(20260519);

$historicalCount = 60;
$createdIncidents = 0;
$skipExisting = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}ticket` WHERE description LIKE 'seed-demo:%'"
);
if ($skipExisting >= $historicalCount) {
    echo "  {$skipExisting} historical incidents already present — skipping seed\n";
} else {
    $toCreate = $historicalCount - $skipExisting;

    // Status codes per legacy schema: 0=open, 1=in-progress, 2=closed (typical)
    // Severity 1-5 from set_severity values stored on in_types.

    for ($i = 0; $i < $toCreate; $i++) {
        // Pick an org weighted toward Vol Fire (most call volume), then CERT, then AUXCOMM
        $orgPick = ['Vol Fire','Vol Fire','Vol Fire','Vol Fire','CERT','CERT','AUXCOMM'][array_rand([0,0,0,0,1,1,2])];
        if (empty($typesByOrg[$orgPick])) continue;

        $type = $typesByOrg[$orgPick][array_rand($typesByOrg[$orgPick])];
        $orgId = $orgs[$orgPick];

        $addr = $addresses[array_rand($addresses)];
        $caller = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
        $phone = '(555) ' . str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT)
               . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Random datetime in the last 90 days
        $daysAgo = random_int(0, 89);
        $hoursAgo = random_int(0, 23);
        $minutesAgo = random_int(0, 59);
        $occurred = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days -{$hoursAgo} hours -{$minutesAgo} minutes"));

        // Most are closed, a small fraction stay open
        $isOpen = (mt_rand(1, 100) <= 8);
        // 2026-06-11 fix: status codes per api/incident-detail.php are
        // {1: Closed, 2: Open, 3: Scheduled}. The old seed used 0/2
        // which rendered every historical incident as Open in the UI
        // even though its activity log said "Incident closed". See
        // tools/fix_seed_demo_data.php for the one-shot patch script.
        $status = $isOpen ? 2 : 1;
        $severity = (int) ($type['set_severity'] ?? 2);

        $desc = "seed-demo: " . $type['type'] . " at " . $addr[0];

        // Pick a destination facility for some calls
        $rec_facility = 0;
        if ($orgPick === 'Vol Fire' && mt_rand(1, 100) <= 30) {
            foreach ($facilities as $f) {
                if (stripos($f['name'], 'hospital') !== false || stripos($f['name'], 'burn center') !== false) {
                    if (mt_rand(0, 1)) { $rec_facility = (int) $f['id']; break; }
                }
            }
        }

        db_query(
            "INSERT INTO `{$prefix}ticket`
             (in_types_id, org, contact, street, city, state, phone, lat, lng, date,
              scope, description, status, severity, owner, updated, _by, org_id, rec_facility)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, 0, NOW(), 1, ?, ?)",
            [
                (int) $type['id'],
                0,             // legacy org column unused in NewUI
                $caller,
                $addr[0], $addr[1], $addr[2],
                $phone, $addr[3], $addr[4],
                $occurred,
                strtoupper(substr($orgPick, 0, 4)),  // scope short code
                $desc,
                $status, $severity,
                $orgId, $rec_facility,
            ]
        );
        $tid = (int) db_insert_id();
        $createdIncidents++;

        // Assignments — for closed incidents, simulate dispatch + clear
        if (!empty($responders) && mt_rand(1, 100) <= 75) {
            $numUnits = ($severity >= 4) ? random_int(2, 4) : random_int(1, 2);
            $assignedThisIncident = [];
            for ($u = 0; $u < $numUnits; $u++) {
                $r = $responders[array_rand($responders)];
                if (isset($assignedThisIncident[$r['id']])) continue;
                $assignedThisIncident[$r['id']] = true;

                $dispatched = $occurred;
                $responding = date('Y-m-d H:i:s', strtotime($dispatched) + random_int(60, 180));
                $on_scene   = date('Y-m-d H:i:s', strtotime($responding) + random_int(180, 600));
                $clear      = $isOpen ? null : date('Y-m-d H:i:s', strtotime($on_scene) + random_int(900, 7200));

                db_query(
                    "INSERT INTO `{$prefix}assigns`
                     (as_of, status_id, ticket_id, responder_id, dispatched, responding, on_scene, clear, user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    [$occurred, $clear ? 4 : 3, $tid, (int) $r['id'],
                     $dispatched, $responding, $on_scene, $clear]
                );
            }
        }

        // Action log entries — narrative.
        // 2026-06-11 fix: use the action_type values that
        // incident-detail.js renderActions() actually understands:
        //   100   creation marker (green border + plus icon)
        //   10-19 status change   (yellow border + repeat icon)
        //   20-29 assignment      (info border + people icon)
        //   else  generic note    (gray)
        $logTimes = [
            ['+0 minutes',   "Call received from {$caller}", 100],  // creation
            ['+1 minutes',   "Units dispatched",              21],  // assignment
            ['+5 minutes',   "First unit on scene",           22],  // assignment
        ];
        if (!$isOpen) {
            $logTimes[] = ['+20 minutes', 'Scene secured, units released', 13];
            $logTimes[] = ['+30 minutes', 'Incident closed',                11];
        }
        foreach ($logTimes as [$rel, $msg, $actType]) {
            $when = date('Y-m-d H:i:s', strtotime($occurred . ' ' . $rel));
            db_query(
                "INSERT INTO `{$prefix}action`
                 (ticket_id, date, description, user, action_type, updated)
                 VALUES (?, ?, ?, 1, ?, NOW())",
                [$tid, $when, $msg, $actType]
            );
        }
    }
    echo "  {$createdIncidents} new historical incidents created\n";
}

// ── 4. Summary ──
echo "\n=== Counts ===\n";
$counts = db_fetch_all("
    SELECT 'ticket' AS t, COUNT(*) AS n FROM `{$prefix}ticket`
    UNION ALL SELECT 'assigns', COUNT(*) FROM `{$prefix}assigns`
    UNION ALL SELECT 'action', COUNT(*) FROM `{$prefix}action`
    UNION ALL SELECT 'newui_shift_templates', COUNT(*) FROM `{$prefix}newui_shift_templates`
    UNION ALL SELECT 'newui_shift_slots', COUNT(*) FROM `{$prefix}newui_shift_slots`
    UNION ALL SELECT 'newui_shift_roles', COUNT(*) FROM `{$prefix}newui_shift_roles`
    UNION ALL SELECT 'newui_events', COUNT(*) FROM `{$prefix}newui_events`
");
foreach ($counts as $c) {
    echo '  ' . str_pad($c['t'], 28) . $c['n'] . "\n";
}

echo "\n=== Seed part 2 complete ===\n";
