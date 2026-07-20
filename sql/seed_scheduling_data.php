<?php
/**
 * Seed sample scheduling data: shift assignments, event participants,
 * team members, and ICS qualifications.
 *
 * Run AFTER seed_demo_data.php and run_scheduling.php
 * Usage: php sql/seed_scheduling_data.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();

// ── Load members ──
$members = $pdo->query("SELECT id, first_name, last_name, callsign FROM member ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($members) . " members.\n";
if (count($members) < 3) {
    echo "Need at least 3 members — run seed_demo_data.php first.\n";
    exit(1);
}

// Build a quick ID map by index
$mIds = array_column($members, 'id');

// ═══ TEAMS ═══════════════════════════════════════════════════════

echo "\n=== Seeding Teams ===\n";
$teamCount = $pdo->query("SELECT COUNT(*) FROM teams")->fetchColumn();
if ($teamCount == 0) {
    // Legacy teams table uses `team` not `name`, and requires by/from/on
    $teamCols = $pdo->query("SHOW COLUMNS FROM teams")->fetchAll(PDO::FETCH_COLUMN, 0);
    $isLegacyTeams = in_array('team', $teamCols) && !in_array('name', $teamCols);

    // Add virtual 'name' alias if legacy and not already there
    if ($isLegacyTeams) {
        try {
            $pdo->exec("ALTER TABLE `teams` ADD COLUMN `name` VARCHAR(48) GENERATED ALWAYS AS (`team`) VIRTUAL");
            echo "  Added virtual 'name' alias for legacy 'team' column.\n";
        } catch (Exception $e) {
            // May already exist
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                echo "  Note: " . $e->getMessage() . "\n";
            }
        }
        // Add 'active' virtual column (always 1 for legacy rows)
        if (!in_array('active', $teamCols)) {
            try {
                $pdo->exec("ALTER TABLE `teams` ADD COLUMN `active` TINYINT(1) DEFAULT 1");
                echo "  Added 'active' column.\n";
            } catch (Exception $e) {}
        }
        // Add 'description' alias for 'mission'
        if (!in_array('description', $teamCols) && in_array('mission', $teamCols)) {
            try {
                $pdo->exec("ALTER TABLE `teams` ADD COLUMN `description` VARCHAR(48) GENERATED ALWAYS AS (`mission`) VIRTUAL");
                echo "  Added virtual 'description' alias for legacy 'mission' column.\n";
            } catch (Exception $e) {}
        }
    }

    $teamData = [
        ['team' => 'HF Radio Team',    'mission' => 'Long-range HF comms for regional traffic'],
        ['team' => 'VHF/UHF Tactical', 'mission' => 'Local tactical comms on repeaters/simplex'],
        ['team' => 'Digital Modes',     'mission' => 'Winlink, JS8Call, digital messaging'],
        ['team' => 'Skywarn',           'mission' => 'Severe weather spotting and reporting'],
    ];

    foreach ($teamData as $t) {
        try {
            if ($isLegacyTeams) {
                $pdo->prepare("INSERT INTO teams (`team`, `sub-group`, `ttypes_id`, `mission`, `leader`, `leader_dpty`, `by`, `from`, `on`) VALUES (?, '', 0, ?, 0, 0, 1, '127.0.0.1', NOW())")
                    ->execute([$t['team'], $t['mission']]);
            } else {
                $pdo->prepare("INSERT INTO teams (name, description, active) VALUES (?, ?, 1)")
                    ->execute([$t['team'], $t['mission']]);
            }
            echo "  Created team: {$t['team']}\n";
        } catch (Exception $e) {
            echo "  Skip {$t['team']}: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "  Teams already exist ({$teamCount}) — skipping.\n";
}

// Reload teams — use 'name' (works for both legacy alias and NewUI column)
try {
    $teams = $pdo->query("SELECT id, name FROM teams ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback to 'team' column
    $teams = $pdo->query("SELECT id, `team` AS name FROM teams ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}
$teamIds = array_column($teams, 'id');

// ═══ TEAM MEMBERS ════════════════════════════════════════════════

echo "\n=== Seeding Team Members ===\n";
$tmCount = $pdo->query("SELECT COUNT(*) FROM team_members")->fetchColumn();
if ($tmCount == 0 && count($teamIds) >= 2) {
    // Spread members across teams
    $assignments = [];
    // First team gets members 0-3, second gets 2-5, third gets 4-7, fourth gets 6-9
    for ($t = 0; $t < min(count($teamIds), 4); $t++) {
        $startIdx = $t * 2;
        for ($m = $startIdx; $m < min($startIdx + 4, count($mIds)); $m++) {
            $role = ($m === $startIdx) ? 'Team Lead' : 'Member';
            $assignments[] = [$teamIds[$t], $mIds[$m], $role];
        }
    }

    $stmt = $pdo->prepare("INSERT IGNORE INTO team_members (team_id, member_id, role, assigned_date) VALUES (?, ?, ?, CURDATE())");
    $inserted = 0;
    foreach ($assignments as $a) {
        try {
            $stmt->execute($a);
            $inserted++;
        } catch (Exception $e) {
            // skip duplicates
        }
    }
    echo "  Assigned {$inserted} team memberships.\n";
} else {
    echo "  Team members already exist ({$tmCount}) — skipping.\n";
}

// ═══ ICS QUALIFICATIONS ══════════════════════════════════════════

echo "\n=== Seeding ICS Qualifications ===\n";
$icsCount = $pdo->query("SELECT COUNT(*) FROM member_ics_qualifications")->fetchColumn();
if ($icsCount == 0) {
    // Get ICS positions
    $icsPositions = $pdo->query("SELECT id, code, title FROM ics_positions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $icsByCode = [];
    foreach ($icsPositions as $ip) $icsByCode[$ip['code']] = $ip['id'];

    // Assign qualifications to demo members
    // Member 0 (Eric) - skip (real user)
    // Member 2 (index 2, Sarah Chen) - COML Qualified, AUXCOMM Expert
    // Member 3 (Marcus Rodriguez) - COMT Qualified
    // Member 4 (David Johnson) - AUXCOMM Qualified, IC Trainee
    // Member 5 (Rachel Williams) - PSC Qualified
    // Member 6 (Michael Thompson) - COML Trainee, COMT Trainee
    // Member 7 (Lisa Davis) - AUXCOMM Qualified
    // Member 8 (Carlos Martinez) - IC Qualified
    // Member 9 (Jennifer Anderson) - DIVS Trainee

    $quals = [];
    if (isset($mIds[2]) && isset($icsByCode['COML'])) $quals[] = [$mIds[2], $icsByCode['COML'], 'Qualified', 'Completed'];
    if (isset($mIds[2]) && isset($icsByCode['AUXCOMM'])) $quals[] = [$mIds[2], $icsByCode['AUXCOMM'], 'Expert', 'Completed'];
    if (isset($mIds[3]) && isset($icsByCode['COMT'])) $quals[] = [$mIds[3], $icsByCode['COMT'], 'Qualified', 'Completed'];
    if (isset($mIds[4]) && isset($icsByCode['AUXCOMM'])) $quals[] = [$mIds[4], $icsByCode['AUXCOMM'], 'Qualified', 'Completed'];
    if (isset($mIds[4]) && isset($icsByCode['IC'])) $quals[] = [$mIds[4], $icsByCode['IC'], 'Trainee', 'In Progress'];
    if (isset($mIds[5]) && isset($icsByCode['PSC'])) $quals[] = [$mIds[5], $icsByCode['PSC'], 'Qualified', 'Completed'];
    if (isset($mIds[6]) && isset($icsByCode['COML'])) $quals[] = [$mIds[6], $icsByCode['COML'], 'Trainee', 'In Progress'];
    if (isset($mIds[6]) && isset($icsByCode['COMT'])) $quals[] = [$mIds[6], $icsByCode['COMT'], 'Trainee', 'In Progress'];
    if (isset($mIds[7]) && isset($icsByCode['AUXCOMM'])) $quals[] = [$mIds[7], $icsByCode['AUXCOMM'], 'Qualified', 'Completed'];
    if (isset($mIds[8]) && isset($icsByCode['IC'])) $quals[] = [$mIds[8], $icsByCode['IC'], 'Qualified', 'Completed'];
    if (isset($mIds[9]) && isset($icsByCode['DIVS'])) $quals[] = [$mIds[9], $icsByCode['DIVS'], 'Trainee', 'In Progress'];

    $stmt = $pdo->prepare("INSERT INTO member_ics_qualifications (member_id, ics_position_id, qualification_level, ptb_status, ptb_start_date) VALUES (?, ?, ?, ?, CURDATE())");
    $inserted = 0;
    foreach ($quals as $q) {
        try {
            $stmt->execute($q);
            $inserted++;
        } catch (Exception $e) {
            echo "  Skip qual: " . $e->getMessage() . "\n";
        }
    }
    echo "  Inserted {$inserted} ICS qualifications.\n";
} else {
    echo "  ICS qualifications already exist ({$icsCount}) — skipping.\n";
}

// ═══ SHIFT ASSIGNMENTS ═══════════════════════════════════════════

echo "\n=== Seeding Shift Assignments ===\n";
$saCount = $pdo->query("SELECT COUNT(*) FROM newui_shift_assignments")->fetchColumn();
if ($saCount == 0) {
    // Get slots and roles for the first template
    $slots = $pdo->query("SELECT id, day_of_week, start_time, label FROM newui_shift_slots WHERE template_id = 1 ORDER BY day_of_week, start_time")->fetchAll(PDO::FETCH_ASSOC);
    $roles = $pdo->query("SELECT id, role_name FROM newui_shift_roles WHERE template_id = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

    if (count($slots) > 0 && count($roles) > 0) {
        $managerRoleId = $roles[0]['id'];  // Net Manager
        $supportRoleId = isset($roles[1]) ? $roles[1]['id'] : $managerRoleId; // Support
        $observerRoleId = isset($roles[2]) ? $roles[2]['id'] : $supportRoleId; // Observer

        // Assign managers and support for this week and next week
        $today = new DateTime();
        $monday = clone $today;
        $monday->modify('monday this week');

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO newui_shift_assignments (slot_id, role_id, member_id, assignment_date, status, self_signup, assigned_by)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        );

        $inserted = 0;
        // Assign for 14 days (this week + next week)
        for ($dayOffset = 0; $dayOffset < 14; $dayOffset++) {
            $date = clone $monday;
            $date->modify("+{$dayOffset} days");
            $dateStr = $date->format('Y-m-d');
            $dow = (int) $date->format('w'); // 0=Sun

            // Find slots for this day of week
            foreach ($slots as $slot) {
                if ((int) $slot['day_of_week'] !== $dow) continue;

                // Rotate managers through members 2-4 (Sarah, Marcus, David)
                $managerIdx = ($dayOffset % 3) + 2;
                $managerId = isset($mIds[$managerIdx]) ? $mIds[$managerIdx] : $mIds[2];

                // Assign manager
                $status = ($dayOffset < 7) ? 'confirmed' : 'assigned';
                try {
                    $stmt->execute([$slot['id'], $managerRoleId, $managerId, $dateStr, $status, 0]);
                    $inserted++;
                } catch (Exception $e) {}

                // Assign support (rotate through members 5-7)
                $supportIdx = ($dayOffset % 3) + 5;
                $supportId = isset($mIds[$supportIdx]) ? $mIds[$supportIdx] : $mIds[5];
                try {
                    $stmt->execute([$slot['id'], $supportRoleId, $supportId, $dateStr, $status, 1]);
                    $inserted++;
                } catch (Exception $e) {}

                // Assign observer on some shifts (every other day, morning only)
                if ($dayOffset % 2 === 0 && $slot['label'] === 'Morning') {
                    $observerIdx = ($dayOffset % 4) + 6;
                    $observerId = isset($mIds[$observerIdx]) ? $mIds[$observerIdx] : $mIds[8];
                    try {
                        $stmt->execute([$slot['id'], $observerRoleId, $observerId, $dateStr, 'assigned', 1]);
                        $inserted++;
                    } catch (Exception $e) {}
                }
            }
        }
        echo "  Inserted {$inserted} shift assignments across 2 weeks.\n";
    } else {
        echo "  No slots/roles found for template 1 — run run_scheduling.php first.\n";
    }
} else {
    echo "  Shift assignments already exist ({$saCount}) — skipping.\n";
}

// ═══ EVENT PARTICIPANTS ══════════════════════════════════════════

echo "\n=== Seeding Event Participants ===\n";
$epCount = $pdo->query("SELECT COUNT(*) FROM newui_event_participants")->fetchColumn();
if ($epCount == 0) {
    $events = $pdo->query("SELECT id, name FROM newui_events ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    if (count($events) > 0) {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO newui_event_participants (event_id, member_id, status, self_signup, role)
             VALUES (?, ?, ?, ?, ?)"
        );

        $inserted = 0;
        foreach ($events as $ev) {
            $evId = (int) $ev['id'];
            // Register 5-8 members per event
            $numParticipants = min(count($mIds), rand(5, 8));
            $shuffled = $mIds;
            shuffle($shuffled);

            for ($i = 0; $i < $numParticipants; $i++) {
                $mId = $shuffled[$i];
                $isSelf = ($i % 2 === 0) ? 1 : 0;
                // Vary statuses
                $statuses = ['registered', 'confirmed', 'confirmed', 'attended'];
                $status = $statuses[$i % count($statuses)];
                // First member gets a role
                $role = null;
                if ($i === 0) $role = 'Net Control';
                if ($i === 1) $role = 'Safety Officer';
                if ($i === 2) $role = 'Logistics';

                try {
                    $stmt->execute([$evId, $mId, $status, $isSelf, $role]);
                    $inserted++;
                } catch (Exception $e) {}
            }
        }
        echo "  Registered {$inserted} event participants across " . count($events) . " events.\n";
    } else {
        echo "  No events found — run run_scheduling.php first.\n";
    }
} else {
    echo "  Event participants already exist ({$epCount}) — skipping.\n";
}

// ═══ Summary ═════════════════════════════════════════════════════

echo "\n═══ Scheduling Data Summary ═══\n";
$counts = [
    'Teams'               => $pdo->query("SELECT COUNT(*) FROM teams")->fetchColumn(),
    'Team Members'        => $pdo->query("SELECT COUNT(*) FROM team_members")->fetchColumn(),
    'ICS Qualifications'  => $pdo->query("SELECT COUNT(*) FROM member_ics_qualifications")->fetchColumn(),
    'Shift Assignments'   => $pdo->query("SELECT COUNT(*) FROM newui_shift_assignments")->fetchColumn(),
    'Event Participants'  => $pdo->query("SELECT COUNT(*) FROM newui_event_participants")->fetchColumn(),
];
foreach ($counts as $label => $count) {
    echo "  {$label}: {$count}\n";
}
echo "\nDone!\n";
