<?php
/**
 * Seed demo data for the Personnel Management module.
 * Creates sample members, vehicles, equipment, training records, and certifications.
 *
 * Usage: php sql/seed_demo_data.php
 *
 * Safe to run multiple times — checks for existing data before inserting.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();

// ── Detect member table column naming ──────────────────────────
// Legacy uses field1/field2, NewUI uses first_name/last_name
$cols = $pdo->query("SHOW COLUMNS FROM member")->fetchAll(PDO::FETCH_COLUMN, 0);
$isLegacy = in_array('field1', $cols);
if ($isLegacy) {
    $colLast = 'field1'; $colFirst = 'field2'; $colCallsign = 'field4';
    $colType = 'field3'; $colAvail = 'field8';
    echo "Detected legacy member table (field1/field2 columns).\n";
} else {
    $colLast = 'last_name'; $colFirst = 'first_name'; $colCallsign = 'callsign';
    $colType = 'member_type_id'; $colAvail = 'available';
    echo "Detected NewUI member table.\n";
}

// ═══ DEMO MEMBERS ═══════════════════════════════════════════════
$memberCount = $pdo->query('SELECT COUNT(*) FROM member')->fetchColumn();
echo "Current members: {$memberCount}\n";

$demoMembers = [
    ['last' => 'Chen', 'first' => 'Sarah', 'call' => 'KC9ABC', 'type' => 0],
    ['last' => 'Rodriguez', 'first' => 'Marcus', 'call' => 'W9XYZ', 'type' => 0],
    ['last' => 'Johnson', 'first' => 'David', 'call' => 'KD9LMN', 'type' => 0],
    ['last' => 'Williams', 'first' => 'Rachel', 'call' => 'N9PQR', 'type' => 0],
    ['last' => 'Thompson', 'first' => 'Michael', 'call' => 'KE9STU', 'type' => 0],
    ['last' => 'Davis', 'first' => 'Lisa', 'call' => 'WB9VWX', 'type' => 0],
    ['last' => 'Martinez', 'first' => 'Carlos', 'call' => 'KA9DEF', 'type' => 0],
    ['last' => 'Anderson', 'first' => 'Jennifer', 'call' => 'KB9GHI', 'type' => 0],
];

if ($memberCount < 5) {
    echo "Seeding demo members...\n";
    if ($isLegacy) {
        // Legacy table requires: field7 (bigint NOT NULL), _by, _on, _from
        $mStmt = $pdo->prepare("INSERT INTO member ({$colLast}, {$colFirst}, {$colCallsign}, {$colType}, {$colAvail}, field7, _by, _on, _from) VALUES (?, ?, ?, ?, 'Yes', 0, 1, NOW(), '127.0.0.1')");
    } else {
        $mStmt = $pdo->prepare("INSERT INTO member ({$colLast}, {$colFirst}, {$colCallsign}, {$colType}, {$colAvail}, created_at) VALUES (?, ?, ?, ?, 'Yes', NOW())");
    }

    foreach ($demoMembers as $dm) {
        // Check if member already exists by callsign
        $exists = $pdo->prepare("SELECT COUNT(*) FROM member WHERE {$colCallsign} = ?");
        $exists->execute([$dm['call']]);
        if ($exists->fetchColumn() > 0) continue;

        try {
            $mStmt->execute([$dm['last'], $dm['first'], $dm['call'], $dm['type']]);
            echo "  Added: {$dm['first']} {$dm['last']} ({$dm['call']})\n";
        } catch (Exception $e) {
            echo "  Skip {$dm['first']} {$dm['last']}: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "Enough members exist — skipping member seed.\n";
}

// ── Reload member list ─────────────────────────────────────────
$members = $pdo->query("SELECT id, {$colFirst} AS first_name, {$colLast} AS last_name, {$colCallsign} AS callsign FROM member ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "\nActive members (" . count($members) . "):\n";
foreach ($members as $m) echo "  [{$m['id']}] {$m['first_name']} {$m['last_name']} ({$m['callsign']})\n";

// ═══ VEHICLE TYPES ═════════════════════════════════════════════
$vTypes = $pdo->query("SELECT id, name FROM newui_vehicle_types ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$vTypeMap = [];
foreach ($vTypes as $vt) $vTypeMap[strtolower($vt['name'])] = $vt['id'];

// ═══ EQUIPMENT TYPES ═══════════════════════════════════════════
$eTypes = $pdo->query("SELECT id, name FROM newui_equipment_types ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$eTypeMap = [];
foreach ($eTypes as $et) $eTypeMap[strtolower($et['name'])] = $et['id'];

// ═══ CERTIFICATION IDS ═════════════════════════════════════════
$certs = [];
try {
    $certs = $pdo->query("SELECT id, name, fema_course_code FROM certifications ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$certMap = [];
foreach ($certs as $c) {
    $certMap[strtolower($c['name'])] = $c['id'];
    if (!empty($c['fema_course_code'])) $certMap[strtolower($c['fema_course_code'])] = $c['id'];
}

// ═══ VEHICLES ═══════════════════════════════════════════════════
$existingVehicles = $pdo->query("SELECT COUNT(*) FROM newui_vehicles")->fetchColumn();
if ($existingVehicles > 0) {
    echo "\nVehicles: {$existingVehicles} records exist — skipping.\n";
} else {
    echo "\nSeeding vehicles...\n";
    $now = date('Y-m-d H:i:s');
    $vehicles = [
        // Agency vehicles (no owner)
        [null, 'agency vehicle', 'CMD-1', 2022, 'Ford', 'Explorer', 'White', 1, 0, 'GOV-1234', 'IN', 'Active', 'Primary command vehicle. Dual-band radio, laptop mount, emergency lighting.'],
        [null, 'emergency vehicle', 'COM-1', 2020, 'Chevrolet', 'Express 3500', 'White', 1, 0, 'GOV-5678', 'IN', 'Active', 'Communications van. HF/VHF/UHF station, generator hookup, antenna mast.'],
        [null, 'trailer', 'TRL-1', 2018, 'Wells Cargo', '6x12 Enclosed', 'Gray', 1, 0, 'TRL-9012', 'IN', 'Active', 'Equipment trailer. Shelving, power distribution, external antenna ports.'],
        [null, 'atv/utv', 'ATV-1', 2021, 'Polaris', 'Ranger 570', 'Green', 1, 0, null, null, 'Active', 'Trail access UTV. Severe weather spotting in rural areas.'],
        [null, 'agency vehicle', 'CMD-OLD', 2012, 'Chevrolet', 'Tahoe', 'Black', 1, 0, 'GOV-OLD1', 'IN', 'Out of Service', 'Transmission issues. Pending disposal.'],
    ];
    // Personal vehicles (indices into $members array)
    $personalVehicles = [
        [0, 'personal vehicle', 'V-101', 2019, 'Toyota', 'Tacoma', 'Silver', 0, 1, 'ABC-1234', 'IN', 'Active', 'Magnetic mount antenna. Toolbox in bed.'],
        [1, 'personal vehicle', 'V-102', 2021, 'Subaru', 'Outback', 'Blue', 0, 1, 'XYZ-5678', 'IN', 'Active', 'AWD. Roof rack with antenna mount.'],
        [2, 'personal vehicle', 'V-103', 2017, 'Jeep', 'Wrangler', 'Red', 0, 1, 'JEP-4X4', 'IN', 'Active', '4WD. Good for off-road access.'],
        [3, 'personal vehicle', 'V-104', 2023, 'Honda', 'CR-V', 'Black', 0, 1, 'HON-2023', 'OH', 'Active', 'Dual-band mobile radio installed.'],
        [4, 'personal vehicle', '', 2015, 'Ford', 'F-150', 'White', 0, 1, 'FRD-1500', 'IN', 'Active', 'Extended cab. Can haul equipment.'],
    ];

    $vStmt = $pdo->prepare("INSERT INTO newui_vehicles
        (member_id, vehicle_type_id, callsign, year, make, model, color,
         is_agency_vehicle, is_private, plate_number, plate_state, status, notes,
         insurance_carrier, insurance_policy, insurance_exp, registration_exp, vin,
         created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    foreach ($vehicles as $v) {
        $typeId = $vTypeMap[$v[1]] ?? null;
        $vin = strtoupper(substr(md5($v[4].$v[5].$v[3].rand()), 0, 17));
        $insC = $v[7] ? 'Municipal Risk Pool' : null;
        $insP = $insC ? 'POL-'.rand(100000,999999) : null;
        $insE = $v[11]==='Active' ? date('Y-m-d', strtotime('+'.rand(60,365).' days')) : null;
        $regE = $v[11]==='Active' ? date('Y-m-d', strtotime('+'.rand(90,365).' days')) : null;
        $vStmt->execute([null, $typeId, $v[2]?:null, $v[3], $v[4], $v[5], $v[6], $v[7], $v[8], $v[9], $v[10], $v[11], $v[12], $insC, $insP, $insE, $regE, $vin, $now, $now]);
    }
    echo "  Inserted " . count($vehicles) . " agency vehicles.\n";

    foreach ($personalVehicles as $pv) {
        $memberId = isset($members[$pv[0]]) ? $members[$pv[0]]['id'] : null;
        $typeId = $vTypeMap[$pv[1]] ?? null;
        $vin = strtoupper(substr(md5($pv[4].$pv[5].$pv[3].rand()), 0, 17));
        $vStmt->execute([$memberId, $typeId, $pv[2]?:null, $pv[3], $pv[4], $pv[5], $pv[6], $pv[7], $pv[8], $pv[9], $pv[10], $pv[11], $pv[12], 'State Farm', 'POL-'.rand(100000,999999), date('Y-m-d',strtotime('+'.rand(60,365).' days')), date('Y-m-d',strtotime('+'.rand(90,365).' days')), $vin, $now, $now]);
    }
    echo "  Inserted " . count($personalVehicles) . " personal vehicles.\n";
}

// ═══ EQUIPMENT ══════════════════════════════════════════════════
$existingEquip = $pdo->query("SELECT COUNT(*) FROM newui_equipment")->fetchColumn();
if ($existingEquip > 0) {
    echo "\nEquipment: {$existingEquip} records exist — skipping.\n";
} else {
    echo "\nSeeding equipment...\n";
    $now = date('Y-m-d H:i:s');

    // [type_key, ownership, owner_idx|null, assign_idx|null, name, make, model, serial, tag, condition, status, location, cost, avail_events, notes]
    $items = [
        // Org radios
        ['radio','organization',null,null, 'Motorola XPR 7550e','Motorola','XPR 7550e','MOT-'.rand(10000,99999),'RAD-001','Good','Available','Radio cabinet, Station 1',650,'UHF. Programmed for local repeaters and simplex.'],
        ['radio','organization',null,null, 'Motorola XPR 7550e','Motorola','XPR 7550e','MOT-'.rand(10000,99999),'RAD-002','Good','Available','Radio cabinet, Station 1',650,'UHF. Spare battery included.'],
        ['radio','organization',null,0, 'Motorola XPR 7550e','Motorola','XPR 7550e','MOT-'.rand(10000,99999),'RAD-003','Fair','Checked Out',null,650,'Battery showing reduced capacity.'],
        ['radio','organization',null,null, 'Yaesu FT-991A','Yaesu','FT-991A','YSU-'.rand(10000,99999),'RAD-010','Good','Available','Comm van COM-1',1450,'HF/VHF/UHF all-mode transceiver.'],
        ['radio','organization',null,null, 'Kenwood TM-V71A','Kenwood','TM-V71A','KEN-'.rand(10000,99999),'RAD-011','Good','Available','CMD-1 vehicle mount',375,'Dual-band mobile. Installed in command vehicle.'],
        // Electronics
        ['electronics','organization',null,null, 'Dell Latitude 5540','Dell','Latitude 5540','DEL-'.rand(10000,99999),'LAP-001','Good','Available','Office, Station 1',1200,'Windows 11. CAD software installed.'],
        ['electronics','organization',null,1, 'iPad Air (5th Gen)','Apple','iPad Air 5','APL-'.rand(10000,99999),'TAB-001','Good','Checked Out',null,599,'Field tablet. CAD web app bookmarked.'],
        // Communications
        ['communications','organization',null,null, 'Diamond X510N Antenna','Diamond','X510N',null,'ANT-001','Good','Available','Equipment trailer TRL-1',165,'Dual-band VHF/UHF base antenna.'],
        ['communications','organization',null,null, 'MFJ-1979 Telescoping Mast','MFJ','1979',null,'MST-001','Good','Available','Equipment trailer TRL-1',85,'17-foot telescoping mast with guying kit.'],
        // Generator
        ['generator','organization',null,null, 'Honda EU2200i Generator','Honda','EU2200i','HND-'.rand(10000,99999),'GEN-001','Good','Available','Equipment trailer TRL-1',1149,'Inverter generator. Quiet. 2200W.'],
        // PPE
        ['ppe','organization',null,2, 'Safety Vest ANSI Class 2 (L)','ML Kishigo','1191',null,'PPE-001','Good','Checked Out',null,12,'Size L. Hi-vis lime with reflective.'],
        ['ppe','organization',null,null, 'Safety Vest ANSI Class 2 (XL)','ML Kishigo','1191',null,'PPE-002','Good','Available','Supply closet, Station 1',12,'Size XL.'],
        ['ppe','organization',null,null, 'Safety Vest ANSI Class 2 (M)','ML Kishigo','1191',null,'PPE-003','Good','Available','Supply closet, Station 1',12,'Size M.'],
        // Shelter
        ['shelter','organization',null,null, 'EZ-Up 10x10 Canopy','E-Z UP','Eclipse 10x10',null,'SHL-001','Good','Available','Equipment trailer TRL-1',299,'Blue canopy. Includes weights and sidewalls.'],
        // Personal equipment
        ['radio','personal',0,null, 'Baofeng UV-5R','Baofeng','UV-5R',null,null,'Good','Available',null,null,'Personal HT. Local repeaters programmed.'],
        ['radio','personal',1,null, 'Yaesu FT-60R','Yaesu','FT-60R',null,null,'Good','Available',null,null,'Dual-band HT. Extra battery available.'],
        ['electronics','personal',2,null, 'Lenovo ThinkPad T14','Lenovo','ThinkPad T14',null,null,'Good','Available',null,null,'Winlink and JS8Call installed.'],
        ['generator','personal',3,null, 'Jackery Explorer 1000','Jackery','Explorer 1000',null,null,'Good','Available',null,null,'Portable power station. 8+ hours radio ops.'],
    ];

    $eStmt = $pdo->prepare("INSERT INTO newui_equipment
        (equipment_type_id, ownership, owner_member_id, available_for_events,
         name, make, model, serial_number, asset_tag, `condition`, status,
         assigned_member_id, location, purchase_cost, notes, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    foreach ($items as $e) {
        $typeId = $eTypeMap[$e[0]] ?? null;
        $ownerId = ($e[1]==='personal' && $e[2]!==null && isset($members[$e[2]])) ? $members[$e[2]]['id'] : null;
        $assignId = ($e[3]!==null && isset($members[$e[3]])) ? $members[$e[3]]['id'] : null;
        $avail = ($e[1]==='personal') ? 1 : 0;
        $eStmt->execute([$typeId, $e[1], $ownerId, $avail, $e[4], $e[5], $e[6], $e[7], $e[8], $e[9], $e[10], $assignId, $e[11], $e[12], $e[13], $now, $now]);
    }
    echo "  Inserted " . count($items) . " equipment items.\n";

    // Log entries for checked-out items
    $checkedOut = $pdo->query("SELECT id, assigned_member_id FROM newui_equipment WHERE status = 'Checked Out'")->fetchAll(PDO::FETCH_ASSOC);
    $logStmt = $pdo->prepare("INSERT INTO newui_equipment_log (equipment_id, `action`, member_id, performed_by, notes, created_at) VALUES (?, 'checkout', ?, ?, ?, ?)");
    foreach ($checkedOut as $co) {
        $logStmt->execute([$co['id'], $co['assigned_member_id'], $members[0]['id'], 'Checked out for field operations.', date('Y-m-d H:i:s', strtotime('-'.rand(1,30).' days'))]);
    }
    echo "  Inserted " . count($checkedOut) . " checkout log entries.\n";
}

// ═══ TRAINING RECORDS ═══════════════════════════════════════════
$existingTraining = 0;
try { $existingTraining = $pdo->query("SELECT COUNT(*) FROM training_records")->fetchColumn(); } catch (Exception $e) {}

if ($existingTraining > 0) {
    echo "\nTraining: {$existingTraining} records exist — skipping.\n";
} else {
    echo "\nSeeding training records...\n";
    $courses = [
        ['Course', 'ICS-100: Introduction to ICS', 3, 'FEMA EMI (Online)', 'Online'],
        ['Course', 'ICS-200: Basic ICS for Initial Response', 3, 'FEMA EMI (Online)', 'Online'],
        ['Course', 'ICS-700: NIMS Introduction', 3.5, 'FEMA EMI (Online)', 'Online'],
        ['Course', 'ICS-800: National Response Framework', 3, 'FEMA EMI (Online)', 'Online'],
        ['Course', 'AUXCOMM (IS-2200)', 16, 'State AUXCOMM Instructor', 'County EOC'],
        ['Drill', 'Monthly Net Check-in', 1, 'Net Control', 'Radio (repeater)'],
        ['Drill', 'Simplex Communication Drill', 2, null, 'Field (various)'],
        ['Exercise', 'SET: Simulated Emergency Test', 4, 'EC/DEC', 'County-wide'],
        ['Exercise', 'Skywarn Activation Exercise', 3, 'Skywarn Coordinator', 'NWS Office'],
        ['Workshop', 'Winlink Express Setup & Operation', 2, null, 'Club Meeting Room'],
        ['Workshop', 'Portable Station Deployment', 3, null, 'Field Day Site'],
        ['OJT', 'EOC Communications Setup', 4, 'Communications Officer', 'County EOC'],
    ];

    $tStmt = $pdo->prepare("INSERT INTO training_records
        (member_id, training_name, training_type, training_date, hours, result, instructor, location, created_at)
        VALUES (?, ?, ?, ?, ?, 'Completed', ?, ?, NOW())");

    $count = 0;
    $numM = min(count($members), 6);
    for ($mi = 0; $mi < $numM; $mi++) {
        $mid = $members[$mi]['id'];
        // Everyone: IS-100, IS-700
        foreach ([0, 2] as $ci) {
            $tStmt->execute([$mid, $courses[$ci][1], $courses[$ci][0], date('Y-m-d', strtotime('-'.rand(60,730).' days')), $courses[$ci][2], $courses[$ci][3], $courses[$ci][4]]);
            $count++;
        }
        // First 4: IS-200, IS-800
        if ($mi < 4) {
            foreach ([1, 3] as $ci) {
                $tStmt->execute([$mid, $courses[$ci][1], $courses[$ci][0], date('Y-m-d', strtotime('-'.rand(60,365).' days')), $courses[$ci][2], $courses[$ci][3], $courses[$ci][4]]);
                $count++;
            }
        }
        // First 2: AUXCOMM
        if ($mi < 2) {
            $tStmt->execute([$mid, $courses[4][1], $courses[4][0], date('Y-m-d', strtotime('-'.rand(30,365).' days')), $courses[4][2], $courses[4][3], $courses[4][4]]);
            $count++;
        }
        // Random drills/exercises for everyone
        $extras = array_rand(array_slice($courses, 5), min(3, count($courses)-5));
        if (!is_array($extras)) $extras = [$extras];
        foreach ($extras as $ei) {
            $c = $courses[$ei + 5];
            $tStmt->execute([$mid, $c[1], $c[0], date('Y-m-d', strtotime('-'.rand(7,365).' days')), $c[2], $c[3], $c[4]]);
            $count++;
        }
    }
    echo "  Inserted {$count} training records.\n";
}

// ═══ MEMBER CERTIFICATIONS ══════════════════════════════════════
$existingCerts = 0;
try { $existingCerts = $pdo->query("SELECT COUNT(*) FROM member_certifications")->fetchColumn(); } catch (Exception $e) {}

if ($existingCerts > 0) {
    echo "\nMember certifications: {$existingCerts} records exist — skipping.\n";
} else if (empty($certMap)) {
    echo "\nNo certifications table or no cert data — skipping member certs.\n";
} else {
    echo "\nSeeding member certifications...\n";
    $mcStmt = $pdo->prepare("INSERT INTO member_certifications
        (member_id, certification_id, earned_date, expiry_date, certificate_number, issuing_authority, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)");

    $count = 0;
    $is100 = $certMap['is-100.c'] ?? $certMap['is-100'] ?? null;
    $is200 = $certMap['is-200.c'] ?? $certMap['is-200'] ?? null;
    $is700 = $certMap['is-700.b'] ?? $certMap['is-700'] ?? null;
    $is800 = $certMap['is-800.d'] ?? $certMap['is-800'] ?? null;
    $is2200 = $certMap['is-2200'] ?? $certMap['auxcomm'] ?? null;

    $numM = min(count($members), 6);
    for ($mi = 0; $mi < $numM; $mi++) {
        $mid = $members[$mi]['id'];
        if ($is100) { $d = date('Y-m-d', strtotime('-'.rand(60,730).' days')); $mcStmt->execute([$mid, $is100, $d, null, 'FEMA-'.rand(100000,999999), 'FEMA EMI', null]); $count++; }
        if ($is700) { $d = date('Y-m-d', strtotime('-'.rand(60,730).' days')); $mcStmt->execute([$mid, $is700, $d, null, 'FEMA-'.rand(100000,999999), 'FEMA EMI', null]); $count++; }
        if ($mi < 4) {
            if ($is200) { $d = date('Y-m-d', strtotime('-'.rand(60,365).' days')); $mcStmt->execute([$mid, $is200, $d, null, 'FEMA-'.rand(100000,999999), 'FEMA EMI', null]); $count++; }
            if ($is800) { $d = date('Y-m-d', strtotime('-'.rand(60,365).' days')); $mcStmt->execute([$mid, $is800, $d, null, 'FEMA-'.rand(100000,999999), 'FEMA EMI', null]); $count++; }
        }
        if ($mi < 2 && $is2200) {
            $d = date('Y-m-d', strtotime('-'.rand(30,365).' days'));
            $exp = date('Y-m-d', strtotime('+2 years', strtotime($d)));
            $mcStmt->execute([$mid, $is2200, $d, $exp, 'AUX-'.rand(1000,9999), 'State AUXCOMM Program', 'Completed 16-hour course.']);
            $count++;
        }
    }
    echo "  Inserted {$count} member certifications.\n";
}

// ═══ SUMMARY ════════════════════════════════════════════════════
echo "\n═══ Demo Data Summary ═══\n";
echo "  Members:        " . $pdo->query("SELECT COUNT(*) FROM member")->fetchColumn() . "\n";
echo "  Vehicles:       " . $pdo->query("SELECT COUNT(*) FROM newui_vehicles")->fetchColumn() . "\n";
echo "  Equipment:      " . $pdo->query("SELECT COUNT(*) FROM newui_equipment")->fetchColumn() . "\n";
echo "  Equipment Log:  " . $pdo->query("SELECT COUNT(*) FROM newui_equipment_log")->fetchColumn() . "\n";
try { echo "  Training:       " . $pdo->query("SELECT COUNT(*) FROM training_records")->fetchColumn() . "\n"; } catch (Exception $e) {}
try { echo "  Member Certs:   " . $pdo->query("SELECT COUNT(*) FROM member_certifications")->fetchColumn() . "\n"; } catch (Exception $e) {}
echo "\nDone!\n";
