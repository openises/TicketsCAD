<?php
/**
 * NewUI v4.0 API - Incident Feed for External Systems
 *
 * Provides open incidents as XML (RSS/Atom) or JSON for external consumption.
 * Used by weather alert systems, EOC boards, partner agencies, etc.
 *
 * GET /api/feed.php?format=xml         — RSS 2.0 feed of open incidents
 * GET /api/feed.php?format=json        — JSON feed
 * GET /api/feed.php?format=atom        — Atom feed
 * GET /api/feed.php?key=YOUR_API_KEY   — Authenticate with API key
 *
 * Authentication (F-002 hardening, 2026-05-04 — fail closed by default):
 *   - An admin must set `feed_api_key` in settings before the feed is reachable
 *     to anonymous callers. There is no longer a fallthrough to "feed is open"
 *     when the key is unset.
 *   - With a key configured, requests must pass it via ?key= or X-Feed-Key header.
 *   - A logged-in browser session is still accepted as a fallback (admin testing).
 */

require_once __DIR__ . '/../config.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

// ── Authentication ──
// Check for API key in settings
$feedApiKey = null;
try {
    if (function_exists('get_setting')) {
        $feedApiKey = get_setting('feed_api_key', null);
    }
    if (!$feedApiKey) {
        // Also check the settings table (legacy format)
        $feedApiKey = get_variable('feed_api_key');
    }
} catch (Exception $e) {
    // Config/settings table may not exist
}

$authenticated = false;

if ($feedApiKey) {
    // API key is configured — require it (header or query param)
    $providedKey = $_SERVER['HTTP_X_FEED_KEY'] ?? ($_GET['key'] ?? '');
    if ($providedKey !== '' && hash_equals((string) $feedApiKey, (string) $providedKey)) {
        $authenticated = true;
    }
}
// Fall-through: when no key is configured, the feed is *not* open. An admin
// must explicitly set `feed_api_key` in Settings → Integrations to make the
// anonymous feed reachable. This is a deliberate fail-closed posture.

// Allow session-based auth as fallback (admin browser testing)
if (!$authenticated) {
    session_start();
    if (!empty($_SESSION['user_id'])) {
        $authenticated = true;
    }
}

if (!$authenticated) {
    http_response_code(401);
    $msg = $feedApiKey
        ? 'Unauthorized. Provide a valid API key via ?key= parameter or X-Feed-Key header.'
        : 'Unauthorized. The incident feed is disabled until an administrator sets `feed_api_key` in Settings → Integrations.';
    if (($format ?? '') === 'json' || ($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit;
}

// ── Determine format ──
$format = strtolower(trim($_GET['format'] ?? 'xml'));
if (!in_array($format, ['xml', 'json', 'atom'], true)) {
    $format = 'xml';
}

// ── Fetch open incidents ──
$prefix = $GLOBALS['db_prefix'] ?? '';
$incidents = [];

try {
    $rows = db_fetch_all(
        "SELECT
            `t`.`id`,
            `t`.`scope`,
            `t`.`description`,
            `t`.`street`,
            `t`.`city`,
            `t`.`state`,
            `t`.`lat`,
            `t`.`lng`,
            `t`.`severity`,
            `t`.`status`,
            `t`.`date` AS `opened`,
            `t`.`updated`,
            `it`.`type` AS `type_name`,
            `it`.`group` AS `type_group`,
            (SELECT COUNT(*) FROM `{$prefix}assigns` `a`
             WHERE `a`.`ticket_id` = `t`.`id`
             AND (`a`.`clear` IS NULL OR `a`.`clear` = '0000-00-00 00:00:00')
            ) AS `assigned_units`
         FROM `{$prefix}ticket` `t`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         WHERE `t`.`status` = 2
         ORDER BY `t`.`date` DESC
         LIMIT 200"
    );

    $status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];
    $severity_labels = [0 => 'Low', 1 => 'Medium', 2 => 'High'];

    foreach ($rows as $row) {
        $address = trim(($row['street'] ?: '') . ', ' . ($row['city'] ?: '') . ', ' . ($row['state'] ?: ''), ', ');
        $incidents[] = [
            'id'             => (int) $row['id'],
            'type'           => $row['type_name'] ?: 'Unknown',
            'type_group'     => $row['type_group'] ?: '',
            'scope'          => $row['scope'] ?: '',
            'description'    => $row['description'] ?: '',
            'address'        => $address,
            'street'         => $row['street'] ?: '',
            'city'           => $row['city'] ?: '',
            'state'          => $row['state'] ?: '',
            'lat'            => $row['lat'] ? (float) $row['lat'] : null,
            'lng'            => $row['lng'] ? (float) $row['lng'] : null,
            'severity'       => (int) $row['severity'],
            'severity_text'  => $severity_labels[(int) $row['severity']] ?? 'Unknown',
            'status'         => 'Open',
            'opened'         => $row['opened'],
            'updated'        => $row['updated'],
            'assigned_units' => (int) $row['assigned_units'],
        ];
    }
} catch (Exception $e) {
    if ($format === 'json') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Database error']);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Database error';
    }
    ini_set('display_errors', $prevDisplay);
    exit;
}

// ── Get org name for feed title ──
$orgName = 'Tickets CAD';
try {
    $val = get_variable('org_name');
    if ($val) $orgName = $val;
} catch (Exception $e) {
    // Ignore
}

$feedTitle = $orgName . ' — Active Incidents';
$feedDescription = 'Open incident feed from ' . $orgName . ' dispatch system.';
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/';
$feedUrl = $baseUrl . 'api/feed.php?format=' . $format;
$now = gmdate('Y-m-d\TH:i:s\Z');

ini_set('display_errors', $prevDisplay);

// ── Output ──
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, max-age=60');
    echo json_encode([
        'feed' => [
            'title'       => $feedTitle,
            'description' => $feedDescription,
            'link'        => $baseUrl,
            'generated'   => $now,
            'count'       => count($incidents),
        ],
        'incidents' => $incidents,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($format === 'atom') {
    header('Content-Type: application/atom+xml; charset=utf-8');
    header('Cache-Control: no-cache, max-age=60');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
    echo '  <title>' . xmlEscape($feedTitle) . '</title>' . "\n";
    echo '  <subtitle>' . xmlEscape($feedDescription) . '</subtitle>' . "\n";
    echo '  <link href="' . xmlEscape($feedUrl) . '" rel="self" type="application/atom+xml"/>' . "\n";
    echo '  <link href="' . xmlEscape($baseUrl) . '" rel="alternate" type="text/html"/>' . "\n";
    echo '  <id>' . xmlEscape($feedUrl) . '</id>' . "\n";
    echo '  <updated>' . $now . '</updated>' . "\n";
    echo '  <generator>Tickets CAD NewUI v' . NEWUI_VERSION . '</generator>' . "\n";

    foreach ($incidents as $inc) {
        $entryUrl = $baseUrl . 'incident-detail.php?id=' . $inc['id'];
        $entryUpdated = $inc['updated'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($inc['updated'])) : $now;
        $entryContent = 'Type: ' . $inc['type'] . "\n"
            . 'Address: ' . $inc['address'] . "\n"
            . 'Severity: ' . $inc['severity_text'] . "\n"
            . 'Assigned Units: ' . $inc['assigned_units'];
        if ($inc['description']) {
            $entryContent .= "\n" . $inc['description'];
        }

        echo "  <entry>\n";
        echo '    <title>Incident #' . $inc['id'] . ': ' . xmlEscape($inc['scope'] ?: $inc['type']) . '</title>' . "\n";
        echo '    <link href="' . xmlEscape($entryUrl) . '"/>' . "\n";
        echo '    <id>' . xmlEscape($entryUrl) . '</id>' . "\n";
        echo '    <updated>' . $entryUpdated . '</updated>' . "\n";
        echo '    <summary type="text">' . xmlEscape($entryContent) . '</summary>' . "\n";
        echo '    <category term="' . xmlEscape($inc['severity_text']) . '"/>' . "\n";
        echo "  </entry>\n";
    }

    echo '</feed>' . "\n";
    exit;
}

// Default: RSS 2.0
header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: no-cache, max-age=60');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
echo '<channel>' . "\n";
echo '  <title>' . xmlEscape($feedTitle) . '</title>' . "\n";
echo '  <link>' . xmlEscape($baseUrl) . '</link>' . "\n";
echo '  <description>' . xmlEscape($feedDescription) . '</description>' . "\n";
echo '  <language>en-us</language>' . "\n";
echo '  <lastBuildDate>' . gmdate('r') . '</lastBuildDate>' . "\n";
echo '  <generator>Tickets CAD NewUI v' . NEWUI_VERSION . '</generator>' . "\n";
echo '  <atom:link href="' . xmlEscape($feedUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";

foreach ($incidents as $inc) {
    $itemUrl = $baseUrl . 'incident-detail.php?id=' . $inc['id'];
    $pubDate = $inc['opened'] ? gmdate('r', strtotime($inc['opened'])) : gmdate('r');
    $descParts = [];
    $descParts[] = 'Type: ' . $inc['type'];
    $descParts[] = 'Address: ' . $inc['address'];
    $descParts[] = 'Severity: ' . $inc['severity_text'];
    $descParts[] = 'Assigned Units: ' . $inc['assigned_units'];
    if ($inc['description']) {
        $descParts[] = $inc['description'];
    }

    echo "  <item>\n";
    echo '    <title>Incident #' . $inc['id'] . ': ' . xmlEscape($inc['scope'] ?: $inc['type']) . '</title>' . "\n";
    echo '    <link>' . xmlEscape($itemUrl) . '</link>' . "\n";
    echo '    <guid isPermaLink="true">' . xmlEscape($itemUrl) . '</guid>' . "\n";
    echo '    <pubDate>' . $pubDate . '</pubDate>' . "\n";
    echo '    <description>' . xmlEscape(implode("\n", $descParts)) . '</description>' . "\n";
    echo '    <category>' . xmlEscape($inc['severity_text']) . '</category>' . "\n";
    echo "  </item>\n";
}

echo '</channel>' . "\n";
echo '</rss>' . "\n";


/**
 * Escape a string for safe inclusion in XML.
 */
function xmlEscape($str) {
    return htmlspecialchars($str ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
