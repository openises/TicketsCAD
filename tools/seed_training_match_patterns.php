<?php
/**
 * Seed match_pattern regex values onto the demo incident types so the
 * description auto-match feature actually fires during the training
 * scenarios.
 *
 * On the new-incident form, when the operator types into Description
 * and Tabs out, assets/js/new-incident.js walks the incident type list
 * looking for the first `match_pattern` regex that matches the
 * description text. First hit wins. Selecting the type triggers
 * Severity auto-fill and the Protocol panel.
 *
 * Run on the training instance after the part-1 + part-2 seeds:
 *   php tools/seed_training_match_patterns.php
 *
 * Idempotent. Re-runs overwrite the existing pattern only when it's
 * empty or matches the previously-seeded value — operator overrides
 * are preserved.
 *
 * Pattern design notes:
 *   - Case-insensitive (the JS uses `new RegExp(pattern, 'i')`).
 *   - Use word boundaries (\b...\b) so "fire" matches in "structure
 *     fire" but not in "campfire" or "fireworks".
 *   - Order matters — the JS iterates types in DOM order (which is
 *     in_types.sort), first match wins. Most specific patterns
 *     should be earlier; broad catch-alls last.
 *   - Patterns target realistic English the operator would type:
 *     "chest pain", "smoke", "auto accident", "tree across road",
 *     etc.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Seeding match_pattern regexes for demo incident types ===\n\n";

// Map: incident type name → regex pattern. Designed to hit the
// likely-to-be-typed phrases from the training scripts (m01, m03)
// and from realistic call scenarios.
$patterns = [
    // ── Vol Fire (highest call volume, most patterns) ──
    'Structure Fire'         => '\b(structure\s+fire|house\s+fire|building\s+fire|fire\s+in\s+(the|a|my|our)\s+(house|building|home|apartment|garage|kitchen|bedroom|attic|basement)|smoke\s+(in|from|inside)\s+(the|a|my|our)?\s*(house|home|building|apartment)|residence\s+fire|smell\s+(of\s+)?smoke\s+inside)\b',
    'Vehicle Fire'           => '\b(vehicle\s+fire|car\s+fire|truck\s+fire|engine\s+(compartment\s+)?fire|smoke\s+from\s+(the\s+)?(car|vehicle|truck|engine|hood)|vehicle\s+on\s+fire)\b',
    'Wildland Fire'          => '\b(wildland|wild\s*fire|brush\s+fire|grass\s+fire|forest\s+fire|field\s+fire|trees?\s+on\s+fire|woods?\s+on\s+fire|smoke\s+in\s+the\s+(woods|field|brush)|controlled\s+burn\s+(out\s+of\s+control|spreading))\b',
    'EMS — Medical'          => '\b(chest\s+pain|cardiac|heart\s+attack|stroke|seizure|seizing|unconscious|unresponsive|not\s+breathing|difficulty\s+breathing|short(ness)?\s+of\s+breath|trouble\s+breathing|fall(en)?|fell|injured|bleeding|bleed(s|ing)|diabetic|allergic\s+reaction|overdose|od\b|medical\s+emergency|sick\s+person|ill\s+person|patient|ems)\b',
    'MVA with Injuries'      => '\b(mva|m\.v\.a\.|motor\s+vehicle\s+accident|auto\s+accident|car\s+(accident|crash|wreck)|vehicle\s+(accident|crash|collision|wreck)|crash\s+(with\s+)?(injuries|injured|entrapment|pinned)|head[\s-]?on|t[\s-]?bone(d)?|rear[\s-]?ended|rollover|car\s+vs|vehicle\s+vs)\b',
    'Hazmat'                 => '\b(hazmat|haz(ardous)?[\s-]?mat(erial)?|chemical\s+(spill|leak|release)|gas\s+leak|propane\s+leak|natural\s+gas|fuel\s+spill|oil\s+spill|carbon\s+monoxide|co\s+(alarm|detector)|placard|tanker\s+(leak|spill|overturned))\b',
    'Mutual Aid'             => '\b(mutual\s+aid|mutual[\s-]?aid|m\/a\b|automatic\s+aid|assist(ing)?\s+(neighboring|another)\s+(department|district|agency)|request(ed)?\s+(from|by)\s+(\w+\s+)?(fire|department|fd))\b',
    'Public Service'         => '\b(lockout|locked\s+out|stuck\s+in\s+(the\s+)?(car|elevator|house)|lift\s+assist|assist(ing)?\s+(citizen|resident|elderly)|smoke\s+(detector|alarm)\s+(install|battery|chirping|beeping)|public\s+service|standby|special\s+event)\b',

    // ── CERT ──
    'Welfare Check'          => '\b(welfare\s+check|wellness\s+check|check\s+on\s+(my|the|a)\s+(neighbor|mother|father|friend|family\s+member|elderly|grandma|grandpa|grandmother|grandfather)|haven\'?t\s+heard\s+from|hasn\'?t\s+answered|won\'?t\s+answer)\b',
    'Damage Assessment'      => '\b(damage\s+assessment|assess(ing)?\s+damage|post[\s-]?(storm|disaster)\s+(assessment|survey)|survey(ing)?\s+(the\s+)?(damage|neighborhood|area)|document(ing)?\s+damage|tornado\s+damage|hurricane\s+damage|flood(ing)?\s+damage|hail\s+damage)\b',
    'Light Search & Rescue'  => '\b(missing\s+(person|child|hiker|kid)|lost\s+(person|child|hiker|kid)|search(ing)?\s+(for|and\s+rescue)|trapped\s+(in|under)|collapsed?\s+(building|structure|home|house)|li(ght|te)\s+search|lsar)\b',
    'First Aid'              => '\b(first\s+aid|minor\s+(injury|injuries|cut|laceration)|scraped?\s+(knee|elbow)|small\s+cut|band[\s-]?aid|need(s)?\s+a\s+(bandage|band[\s-]?aid)|sprain(ed)?|twisted\s+(ankle|knee|wrist))\b',
    'Door Knocking'          => '\b(door[\s-]?knock(ing)?|door[\s-]?to[\s-]?door|canvass(ing)?|notify(ing)?\s+(residents|neighbors|area)|evacuat(ion|e)\s+(notice|notification)|distribute\s+(flyers|information)|community\s+notification)\b',
    'Logistics Support'      => '\b(logistic(s|al)?\s+support|supplies?\s+(needed|distribution|delivery)|water\s+distribution|food\s+(distribution|delivery)|stag(e|ing)\s+(area|site)|inventory|supply\s+chain|cot\s+setup|shelter\s+setup)\b',

    // ── AUXCOMM (lower call volume, broader patterns) ──
    'Net Activation'         => '\b(net\s+activation|activat(e|ing)\s+(the\s+)?net|opening\s+(the\s+)?(net|frequency)|net\s+control\s+(needed|requested)|stand[\s-]?up\s+(the\s+)?net|emergency\s+net)\b',
    'Tactical Net'           => '\b(tactical\s+net|tac[\s-]?net|tactical\s+(traffic|operations|comms?)|incident\s+(comms?|frequency|net)|on[\s-]?scene\s+comms?)\b',
    'Public Service Event'   => '\b(public\s+service|service\s+event|parade|run\s+comms?|marathon|race\s+comms?|bike\s+(ride|tour)|comms?\s+support\s+for\s+(the\s+)?(parade|run|race|event)|event\s+comms?)\b',
    'Emergency Comms'        => '\b(emergency\s+comm(unication)?s?|aux(iliary)?\s+comm(unication)?s?|backup\s+comm(unication)?s?|primary\s+comm(unication)?s?\s+(failed|down|out)|comms?\s+(failure|outage)|ares|races)\b',
    'Repeater Maintenance'   => '\b(repeater\s+(maintenance|down|offline|issue|inspection|service|antenna)|antenna\s+(work|maintenance|inspection)|hardware\s+(check|test|inspection)|signal\s+strength\s+(test|check))\b',
    'Welfare Traffic'        => '\b(welfare\s+traffic|health\s+(and|&)\s+welfare|radiogram|message\s+(traffic|for\s+family)|family\s+(notification|message)|hwt\b)\b',
];

$updated = 0;
$skipped = 0;
$missing = [];

foreach ($patterns as $typeName => $regex) {
    try {
        $row = db_fetch_one(
            "SELECT id, match_pattern FROM `{$prefix}in_types` WHERE type = ?",
            [$typeName]
        );
        if (empty($row)) {
            $missing[] = $typeName;
            continue;
        }
        $current = (string) ($row['match_pattern'] ?? '');
        if ($current === $regex) {
            // Already at the target value
            $skipped++;
            echo "  [skip] " . str_pad($typeName, 28) . " (already matches)\n";
            continue;
        }
        if ($current !== '' && $current !== $regex) {
            // Operator has set their own — leave it alone
            $skipped++;
            echo "  [skip] " . str_pad($typeName, 28) . " (operator-set pattern preserved)\n";
            continue;
        }
        db_query(
            "UPDATE `{$prefix}in_types` SET match_pattern = ? WHERE id = ?",
            [$regex, (int) $row['id']]
        );
        $updated++;
        echo "  [ok]   " . str_pad($typeName, 28) . " regex applied\n";
    } catch (Throwable $e) {
        echo "  [fail] " . str_pad($typeName, 28) . " — " . $e->getMessage() . "\n";
    }
}

echo "\nSummary: {$updated} updated, {$skipped} skipped (already set), " . count($missing) . " missing\n";
if (!empty($missing)) {
    echo "Missing types (not in DB — run seed_training_demo.php first?):\n";
    foreach ($missing as $m) echo "  - {$m}\n";
}

// Smoke-test a handful of training-scenario phrases against the now-seeded patterns.
echo "\n=== Smoke test: training-scenario phrases ===\n";
$testCases = [
    'Caller reports adult male chest pain, conscious and breathing'        => 'EMS — Medical',
    'Smoke in the house second floor'                                       => 'Structure Fire',
    'Auto accident on Highway 7, possible injuries'                         => 'MVA with Injuries',
    'Brush fire spreading from controlled burn'                             => 'Wildland Fire',
    'Tanker overturned, fuel spill'                                         => 'Hazmat',
    "Welfare check on my elderly neighbor, haven't heard from her in days"  => 'Welfare Check',
    'Damage assessment after the storm'                                     => 'Damage Assessment',
    'Net activation for severe weather'                                     => 'Net Activation',
    'Lockout on Main Street'                                                => 'Public Service',
    'Lift assist for fallen patient'                                        => 'EMS — Medical',
];

$rows = db_fetch_all(
    "SELECT id, type, `group`, match_pattern FROM `{$prefix}in_types`
     WHERE match_pattern IS NOT NULL AND match_pattern != ''
     ORDER BY sort, id"
);

foreach ($testCases as $phrase => $expected) {
    $matched = null;
    foreach ($rows as $r) {
        if (@preg_match('/' . $r['match_pattern'] . '/i', $phrase)) {
            $matched = $r['type'];
            break;
        }
    }
    $ok = ($matched === $expected) ? '✓' : '✗';
    echo "  $ok  \"" . substr($phrase, 0, 55) . "\"\n";
    echo "     expected: " . str_pad($expected, 28) . " got: " . ($matched ?? '(no match)') . "\n";
}

echo "\n=== Seed complete ===\n";
