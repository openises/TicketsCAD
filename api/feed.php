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
 *
 * Security Label awareness (Phase 138, plan.md §8 — added 2026-08-13):
 * this feed requires a shared secret and its threat model is "trusted
 * external system," not "any stranger on the internet" — so it is NOT
 * subject to the public board's own eligibility rules (in_types
 * never-publish / excluded-groups / publish-delay / presence-only stub —
 * those are a v1 public-board feature, not a retroactive change to what
 * this trusted feed has always shown). The ONE thing this feed always
 * respects now is a dispatcher's Security Label: an incident whose
 * resolved label sets `routing_allow_broadcast = 0` (e.g. Restricted /
 * Confidential) is dropped from every format entirely, and a surviving
 * row's address/map-marker detail is capped by that label's own
 * eoc_show_address / eoc_show_map_marker rules (never loosened, only
 * possibly coarser) via the SAME inc/public-board.php redaction function
 * the public board uses — called here with $applyTypeVisibility = false
 * so a type an admin marked "presence-only" for the PUBLIC board is NOT
 * silently stubbed out for this feed's trusted, keyed consumers (security
 * review finding #2). See inc/public-board.php's pb_build_public_record().
 */

require_once __DIR__ . '/../inc/https.php';   // is_https(), is_https_verified()

// Fatal-to-JSON guard — API-key endpoint, never requires api/auth.php.
// Note: this endpoint can emit XML/Atom. The guard checks whether a body has
// already started and stays silent if so, so a fatal mid-feed appends nothing.
require_once __DIR__ . '/../inc/api_guard.php';
api_guard_install();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/security-labels.php';
require_once __DIR__ . '/../inc/public-board.php';

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
            `td`.`code` AS `disposition_code`,
            (SELECT COUNT(*) FROM `{$prefix}assigns` `a`
             WHERE `a`.`ticket_id` = `t`.`id`
             AND (`a`.`clear` IS NULL OR `a`.`clear` = '0000-00-00 00:00:00')
            ) AS `assigned_units`
         FROM `{$prefix}ticket` `t`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         LEFT JOIN `{$prefix}ticket_disposition` `td` ON `t`.`disposition_id` = `td`.`id`
         WHERE `t`.`status` = 2
           AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')
         ORDER BY `t`.`date` DESC
         LIMIT 200"
        // Soft-delete sweep (issue #25 follow-up) — this feed is consumed
        // by external systems (weather alert boards, partner agencies);
        // a deleted incident must not be exported to them.
        //
        // Phase 132 Step 5 (GH #16) — disposition_code, via a LEFT JOIN so
        // an incident with no disposition (the normal state for a feed
        // that only lists OPEN incidents — a disposition is usually set at
        // or after close) still returns a row rather than being dropped;
        // the column is simply NULL, carried through below.
    );

    $status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];
    $severity_labels = [0 => 'Low', 1 => 'Medium', 2 => 'High'];

    foreach ($rows as $row) {
        // Phase 138 — Security Label gate. routing_allow_broadcast = 0
        // already means "don't broadcast this" everywhere else in the app
        // (chat/SMS/webhook routing, the public board); this closes the
        // one place it wasn't honored. Dropped entirely — no count/hint
        // that anything was withheld, same rule the public board follows.
        $sec = seclabel_resolve((int) $row['id']);
        if ((int) ($sec['routing_allow_broadcast'] ?? 1) === 0) {
            continue;
        }

        // Redact address/map-marker detail via the SAME function the
        // public board uses. $precisionCeiling = 'exact' is this feed's
        // existing baseline (full detail for a trusted, keyed consumer);
        // the label's own eoc_show_address/eoc_show_map_marker can still
        // cap it coarser, never finer. $applyTypeVisibility = false is
        // REQUIRED (security review finding #2) — without it, an
        // in_types row an admin marked presence-only for the PUBLIC
        // board would also silently stub out here.
        $pub = pb_build_public_record($row, $sec, 'exact', false);

        $streetDisplay = (string) ($pub['street_display'] ?? '');
        $cityDisplay   = (string) ($pub['city'] ?? '');
        $stateDisplay  = (string) ($pub['state'] ?? '');
        $address = trim($streetDisplay . ', ' . $cityDisplay . ', ' . $stateDisplay, ', ');
        $typeDisplay = (string) ($pub['type'] ?? '');
        if ($typeDisplay === '') $typeDisplay = 'Unknown';

        // Correctness review finding (2026-08-13) — eoc_show_address /
        // eoc_show_scope are TWO INDEPENDENT Security Label flags (see
        // api/incidents.php's $maskScope / $maskAddress, and
        // inc/security-labels.php). pb_build_public_record() only reads
        // eoc_show_address; the docblock comment there claiming
        // eoc_show_address "implicitly" redacts the scope narrative
        // "elsewhere" was describing api/incidents.php's OWN masking, not
        // anything this feed does — a dispatcher can set eoc_show_address=1
        // (address visible) but eoc_show_scope=0 (narrative hidden), and
        // that combination reached this trusted-but-keyed feed's scope/
        // description fields completely unredacted. Mask them here using
        // the SAME placeholder convention api/incidents.php's
        // scope_display already uses, so the two surfaces agree.
        $maskScope = ((int) ($sec['eoc_show_scope'] ?? 1)) === 0;
        $scopeDisplay = $row['scope'] ?: '';
        $descriptionDisplay = $row['description'] ?: '';
        if ($maskScope) {
            $placeholder = trim((string) ($sec['eoc_placeholder_text'] ?? ''));
            if ($placeholder === '') $placeholder = 'Location withheld';
            $scopeDisplay = $placeholder;
            $descriptionDisplay = $placeholder;
        }

        $incidents[] = [
            'id'             => (int) $row['id'],
            'type'           => $typeDisplay,
            'type_group'     => (string) ($pub['type_group'] ?? ($row['type_group'] ?: '')),
            'scope'          => $scopeDisplay,
            'description'    => $descriptionDisplay,
            'address'        => $address,
            'street'         => $streetDisplay,
            'city'           => $cityDisplay,
            'state'          => $stateDisplay,
            'lat'            => $pub['lat'] ?? null,
            'lng'            => $pub['lng'] ?? null,
            'severity'       => (int) $row['severity'],
            'severity_text'  => (string) ($pub['severity_text'] ?? ($severity_labels[(int) $row['severity']] ?? 'Unknown')),
            'status'         => 'Open',
            // Raw local-time DB strings, unchanged here — the atom/rss
            // branches below compute their own gmdate(strtotime(...))
            // from these as they always have. Phase 138's ISO-8601 fix
            // (plan.md §8 change 2) is applied ONLY in the json branch's
            // own output step, via pb_iso8601() — see below.
            'opened'         => $row['opened'],
            'updated'        => $row['updated'],
            // Phase 132 Step 5 (GH #16) — null/absent, not an error, on the
            // (usual) OPEN incident that has no disposition recorded yet.
            'disposition_code' => $row['disposition_code'] ?? null,
            'assigned_units' => (int) ($pub['assigned_units'] ?? $row['assigned_units']),
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
$baseUrl = (is_https() ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/';
$feedUrl = $baseUrl . 'api/feed.php?format=' . $format;
$now = gmdate('Y-m-d\TH:i:s\Z');

ini_set('display_errors', $prevDisplay);

// ── Output ──
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, max-age=60');
    // Phase 138 (plan.md §8 change 2) — the json branch used to emit
    // opened/updated as the raw LOCAL-time DB string while atom/rss both
    // already converted via gmdate(strtotime(...)). Route json through the
    // SAME pb_iso8601() helper the public board uses (inc/public-board.php)
    // so all three formats share one timestamp implementation instead of
    // json alone drifting. Applied here, not in the shared row loop above,
    // so the atom/rss branches' existing gmdate(strtotime($inc['opened']))
    // calls keep parsing the original raw string unchanged (byte-identical
    // output — strtotime() also accepts an ISO-8601 string, but there is no
    // reason to make that branch depend on it).
    $jsonIncidents = array_map(function ($inc) {
        $inc['opened']  = pb_iso8601($inc['opened']);
        $inc['updated'] = pb_iso8601($inc['updated']);
        return $inc;
    }, $incidents);
    echo json_encode([
        'feed' => [
            'title'       => $feedTitle,
            'description' => $feedDescription,
            'link'        => $baseUrl,
            'generated'   => $now,
            'count'       => count($jsonIncidents),
        ],
        'incidents' => $jsonIncidents,
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
    echo '  <generator>Tickets CAD NewUI v' . newui_version() . '</generator>' . "\n";

    foreach ($incidents as $inc) {
        $entryUrl = $baseUrl . 'incident-detail.php?id=' . $inc['id'];
        $entryUpdated = $inc['updated'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($inc['updated'])) : $now;
        $entryContent = 'Type: ' . $inc['type'] . "\n"
            . 'Address: ' . $inc['address'] . "\n"
            . 'Severity: ' . $inc['severity_text'] . "\n"
            . 'Assigned Units: ' . $inc['assigned_units'];
        if (!empty($inc['disposition_code'])) {
            $entryContent .= "\n" . 'Disposition: ' . $inc['disposition_code'];
        }
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
echo '  <generator>Tickets CAD NewUI v' . newui_version() . '</generator>' . "\n";
echo '  <atom:link href="' . xmlEscape($feedUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";

foreach ($incidents as $inc) {
    $itemUrl = $baseUrl . 'incident-detail.php?id=' . $inc['id'];
    $pubDate = $inc['opened'] ? gmdate('r', strtotime($inc['opened'])) : gmdate('r');
    $descParts = [];
    $descParts[] = 'Type: ' . $inc['type'];
    $descParts[] = 'Address: ' . $inc['address'];
    $descParts[] = 'Severity: ' . $inc['severity_text'];
    $descParts[] = 'Assigned Units: ' . $inc['assigned_units'];
    if (!empty($inc['disposition_code'])) {
        $descParts[] = 'Disposition: ' . $inc['disposition_code'];
    }
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
