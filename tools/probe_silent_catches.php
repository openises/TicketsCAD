<?php
/**
 * Phase 79 — probe the silent-catch sites flagged in Phase 73f.
 *
 * Issues the EXACT SQL each known silent-catch site uses, against the
 * live database, to separate confirmed bugs from speculative risks.
 *
 * Usage: php tools/probe_silent_catches.php
 */
require __DIR__ . '/../config.php';

$pdo = db();
$prefix = $GLOBALS['db_prefix'] ?? '';

$results = ['OK' => [], 'FAIL' => []];

function probe(PDO $pdo, $file, $line, $label, $sql, $params = []) {
    global $results;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['OK'][] = "[{$file}:{$line}] {$label} — " . count($rows) . " row(s)";
    } catch (Exception $e) {
        $results['FAIL'][] = "[{$file}:{$line}] {$label}\n     " . $e->getMessage();
    }
}

echo "=== probing live SQL behind Phase 73f silent-catch sites ===\n";

// api/statistics.php — actual queries from the file
probe($pdo, 'api/statistics.php', 86,
    "open tickets",
    "SELECT COUNT(DISTINCT `t`.`id`) AS `cnt`
     FROM `{$prefix}ticket` `t`
     LEFT JOIN `{$prefix}allocates` `a` ON `t`.`id` = `a`.`resource_id` AND `a`.`type` = 1
     WHERE (`t`.`status` = 2 OR `t`.`status` = 3)");

probe($pdo, 'api/statistics.php', 93, "closed today",
    "SELECT COUNT(DISTINCT `t`.`id`) AS `cnt`
     FROM `{$prefix}ticket` `t`
     WHERE `t`.`status` = 1 AND DATE(`t`.`problemend`) = CURDATE()");

probe($pdo, 'api/statistics.php', 111, "available responders",
    "SELECT COUNT(DISTINCT `r`.`id`) AS `cnt`
     FROM `{$prefix}responder` `r`
     LEFT JOIN `{$prefix}un_status` `us` ON `r`.`un_status_id` = `us`.`id`
     WHERE `us`.`hide` = 'n'");

// api/incident-types.php — actual queries
probe($pdo, 'api/incident-types.php', 32, "incident types list",
    "SELECT `id`, `type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `match_pattern`
     FROM `{$prefix}in_types`
     ORDER BY `sort`, `type`");

probe($pdo, 'api/incident-types.php', 39, "facilities with hide column",
    "SELECT `id`, `name`, `type`, `lat`, `lng`
     FROM `{$prefix}facilities`
     WHERE `hide` = 0 OR `hide` IS NULL
     ORDER BY `name`");

// api/layout.php — cleanup query
probe($pdo, 'api/layout.php', 99, "snapshot cap cleanup query (uses user_id=1)",
    "SELECT `id` FROM `{$prefix}dashboard_layouts`
     WHERE `user_id` = 1
     ORDER BY `updated_at` DESC LIMIT 20, 999");

// api/config-summary.php — the active_sessions fix we just made
probe($pdo, 'api/config-summary.php', 88, "active sessions count (post-fix)",
    "SELECT COUNT(*) FROM `{$prefix}active_sessions` WHERE `expires_at` > NOW()");

// inc/location-resolver.php
probe($pdo, 'inc/location-resolver.php', 162, "resolve all units main query",
    "SELECT b.`responder_id`, b.`unit_identifier`,
            lr.`lat`, lr.`lng`, lr.`received_at`,
            lp.`code` AS `provider_code`,
            r.`name` AS `unit_name`
     FROM `{$prefix}unit_location_bindings` b
     JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
     JOIN `{$prefix}location_reports` lr ON lr.`unit_identifier` = b.`unit_identifier`
       AND lr.`provider_id` = b.`provider_id`
     LEFT JOIN `{$prefix}responder` r ON b.`responder_id` = r.`id`
     WHERE b.`active` = 1 AND lp.`enabled` = 1
     LIMIT 5");

probe($pdo, 'inc/location-resolver.php', 207, "personnel enrichment",
    "SELECT upa.`member_id`, upa.`role`,
            CONCAT(m.`first_name`, ' ', m.`last_name`) AS `name`
     FROM `{$prefix}unit_personnel_assignments` upa
     LEFT JOIN `{$prefix}member` m ON upa.`member_id` = m.`id`
     WHERE upa.`responder_id` = 1 AND upa.`status` = 'active'");

probe($pdo, 'inc/location-resolver.php', 237, "get unit personnel",
    "SELECT upa.`id`, upa.`member_id`, upa.`role`, upa.`status`, upa.`assigned_at`, upa.`notes`,
            CONCAT(m.`first_name`, ' ', m.`last_name`) AS `member_name`,
            m.`callsign`, m.`phone_cell`
     FROM `{$prefix}unit_personnel_assignments` upa
     LEFT JOIN `{$prefix}member` m ON upa.`member_id` = m.`id`
     WHERE upa.`responder_id` = 1 AND upa.`status` != 'released'
     ORDER BY upa.`assigned_at` ASC");

// inc/session-manager.php — let me grep this one
probe($pdo, 'inc/session-manager.php', 207, "session activity list",
    "SELECT s.* FROM `{$prefix}active_sessions` s ORDER BY s.last_active DESC LIMIT 50");

// broker.php — post-77a
probe($pdo, 'inc/broker.php', 189, "messages INSERT (post-77a fix)",
    "INSERT INTO `{$prefix}messages` (channel, direction, msg_type, sender, recipient, subject, body, priority, status, payload, created_at)
     VALUES ('probe', 'outbound', 'general', 'probe', 'all', '', 'probe', 'normal', 'pending', '{}', NOW())");
try { $pdo->exec("DELETE FROM `{$prefix}messages` WHERE sender = 'probe'"); } catch (Exception $e) {}

probe($pdo, 'inc/channels/local_chat.php', 66, "chat_messages INSERT (post-77a fix)",
    "INSERT INTO `{$prefix}chat_messages` (user_id, user_name, channel, recipient, body, msg_type, priority, ticket_id, signal_id, created_at)
     VALUES (0, 'probe', 'general', 'all', 'probe', 'text', 'normal', NULL, NULL, NOW())");
try { $pdo->exec("DELETE FROM `{$prefix}chat_messages` WHERE user_name = 'probe'"); } catch (Exception $e) {}

echo "\n=== OK ({" . count($results['OK']) . "} queries) ===\n";
foreach ($results['OK'] as $r) echo "  ✓ {$r}\n";

echo "\n=== FAIL ({" . count($results['FAIL']) . "} queries) ===\n";
foreach ($results['FAIL'] as $r) echo "  ✗ {$r}\n";

echo "\n=== summary ===\n";
echo "  " . count($results['OK']) . " queries work against live schema\n";
echo "  " . count($results['FAIL']) . " queries fail and need fixing\n";
