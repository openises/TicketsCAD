<?php
/**
 * NewUI v4.0 API - Personnel Compliance
 *
 * GET /api/compliance.php?action=overview
 *   Returns: { members: [...], summary: { total, expired, expiring_30, expiring_90, compliant } }
 *   Each member has: id, name, certs: [{ name, earned, expiry, status, days_remaining }]
 *
 * GET /api/compliance.php?action=expiring&days=30
 *   Returns: { items: [...] } — just the certifications expiring within N days
 *
 * GET /api/compliance.php?action=my_alerts
 *   Returns: { alerts: [...] } — expiring items for the current logged-in user's linked member
 *   Used by the login reminder banner.
 *
 * POST action=snooze  { alert_id, snooze_until }
 *   Snooze a specific expiration alert for the current user
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// ══════════════════════════════════════════════════════════════
// ACTION: overview — full compliance grid
// ══════════════════════════════════════════════════════════════
if ($action === 'overview') {
    // Require admin
    $userLevel = (int) ($_SESSION['level'] ?? 99);
    if ($userLevel > 2) {
        json_error('Operator access required', 403);
    }

    try {
        // Get all members with their certifications
        $members = db_fetch_all(
            "SELECT m.id, m.name, m.callsign, m.field3 AS type_id,
                    mt.name AS type_name, mt.color AS type_color
             FROM " . db_table('member') . " m
             LEFT JOIN " . db_table('member_types') . " mt ON m.field3 = mt.id
             ORDER BY m.name"
        );

        // Get all member certifications with expiry info
        $certs = db_fetch_all(
            "SELECT mc.member_id, mc.certification_id, mc.earned_date, mc.expiry_date,
                    c.name AS cert_name, c.required
             FROM " . db_table('member_certifications') . " mc
             JOIN " . db_table('certifications') . " c ON mc.certification_id = c.id
             ORDER BY mc.member_id, c.name"
        );

        // Index certs by member_id
        $certsByMember = [];
        foreach ($certs as $cert) {
            $mid = (int) $cert['member_id'];
            if (!isset($certsByMember[$mid])) $certsByMember[$mid] = [];
            $certsByMember[$mid][] = $cert;
        }

        // Get all required certifications for compliance check
        $requiredCerts = [];
        try {
            $requiredCerts = db_fetch_all(
                "SELECT id, name FROM " . db_table('certifications') . " WHERE required = 1 ORDER BY name"
            );
        } catch (Exception $e) {}

        $now = time();
        $result = [];
        $summary = ['total' => 0, 'expired' => 0, 'expiring_30' => 0, 'expiring_90' => 0, 'compliant' => 0];

        foreach ($members as $m) {
            $mid = (int) $m['id'];
            $memberCerts = $certsByMember[$mid] ?? [];
            $certList = [];
            $hasExpired = false;
            $hasExpiring30 = false;
            $hasExpiring90 = false;

            foreach ($memberCerts as $mc) {
                $status = 'valid';
                $daysRemaining = null;

                if (!empty($mc['expiry_date'])) {
                    $expiry = strtotime($mc['expiry_date']);
                    if ($expiry) {
                        $daysRemaining = (int) round(($expiry - $now) / 86400);
                        if ($daysRemaining < 0) {
                            $status = 'expired';
                            $hasExpired = true;
                        } elseif ($daysRemaining <= 30) {
                            $status = 'expiring_30';
                            $hasExpiring30 = true;
                        } elseif ($daysRemaining <= 90) {
                            $status = 'expiring_90';
                            $hasExpiring90 = true;
                        }
                    }
                }

                $certList[] = [
                    'cert_id'        => (int) $mc['certification_id'],
                    'name'           => $mc['cert_name'],
                    'earned'         => $mc['earned_date'],
                    'expiry'         => $mc['expiry_date'],
                    'required'       => (int) ($mc['required'] ?? 0),
                    'status'         => $status,
                    'days_remaining' => $daysRemaining,
                ];
            }

            // Check missing required certs
            $heldCertIds = array_map(function ($c) { return (int) $c['certification_id']; }, $memberCerts);
            $missingRequired = [];
            foreach ($requiredCerts as $rc) {
                if (!in_array((int) $rc['id'], $heldCertIds)) {
                    $missingRequired[] = [
                        'cert_id'        => (int) $rc['id'],
                        'name'           => $rc['name'],
                        'earned'         => null,
                        'expiry'         => null,
                        'required'       => 1,
                        'status'         => 'missing',
                        'days_remaining' => null,
                    ];
                    $hasExpired = true; // Missing required counts as non-compliant
                }
            }

            $allCerts = array_merge($certList, $missingRequired);

            $summary['total']++;
            if ($hasExpired) $summary['expired']++;
            elseif ($hasExpiring30) $summary['expiring_30']++;
            elseif ($hasExpiring90) $summary['expiring_90']++;
            else $summary['compliant']++;

            $result[] = [
                'id'         => $mid,
                'name'       => $m['name'] ?? '',
                'callsign'   => $m['callsign'] ?? '',
                'type_name'  => $m['type_name'] ?? '',
                'type_color' => $m['type_color'] ?? '',
                'certs'      => $allCerts,
                'status'     => $hasExpired ? 'expired' : ($hasExpiring30 ? 'expiring_30' : ($hasExpiring90 ? 'expiring_90' : 'compliant')),
            ];
        }

        ini_set('display_errors', $prevDisplay);
        json_response([
            'members'        => $result,
            'summary'        => $summary,
            'required_certs' => $requiredCerts,
        ]);
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Error loading compliance data: ' . $e->getMessage(), 500);
    }
}

// ══════════════════════════════════════════════════════════════
// ACTION: expiring — items expiring within N days
// ══════════════════════════════════════════════════════════════
elseif ($action === 'expiring') {
    $days = max(1, min(365, (int) ($_GET['days'] ?? 30)));

    try {
        $items = db_fetch_all(
            "SELECT mc.id, mc.member_id, mc.expiry_date,
                    m.name AS member_name, m.callsign,
                    c.name AS cert_name, c.required
             FROM " . db_table('member_certifications') . " mc
             JOIN " . db_table('member') . " m ON mc.member_id = m.id
             JOIN " . db_table('certifications') . " c ON mc.certification_id = c.id
             WHERE mc.expiry_date IS NOT NULL
               AND mc.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY mc.expiry_date ASC",
            [$days]
        );

        $now = time();
        $result = [];
        foreach ($items as $item) {
            $expiry = strtotime($item['expiry_date']);
            $daysRemaining = $expiry ? (int) round(($expiry - $now) / 86400) : null;
            $result[] = [
                'id'             => (int) $item['id'],
                'member_id'      => (int) $item['member_id'],
                'member_name'    => $item['member_name'],
                'callsign'       => $item['callsign'] ?? '',
                'cert_name'      => $item['cert_name'],
                'required'       => (int) ($item['required'] ?? 0),
                'expiry'         => $item['expiry_date'],
                'days_remaining' => $daysRemaining,
                'expired'        => $daysRemaining !== null && $daysRemaining < 0,
            ];
        }

        ini_set('display_errors', $prevDisplay);
        json_response(['items' => $result, 'days_checked' => $days]);
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_error('Error: ' . $e->getMessage(), 500);
    }
}

// ══════════════════════════════════════════════════════════════
// ACTION: my_alerts — current user's expiring certs (for login banner)
// ══════════════════════════════════════════════════════════════
elseif ($action === 'my_alerts') {
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    try {
        // Find member linked to this user
        $member = db_fetch_one(
            "SELECT id FROM " . db_table('member') . " WHERE user_id = ?",
            [$userId]
        );

        if (!$member) {
            ini_set('display_errors', $prevDisplay);
            json_response(['alerts' => []]);
            return;
        }

        $memberId = (int) $member['id'];

        $items = db_fetch_all(
            "SELECT mc.id, mc.expiry_date, c.name AS cert_name, c.required
             FROM " . db_table('member_certifications') . " mc
             JOIN " . db_table('certifications') . " c ON mc.certification_id = c.id
             WHERE mc.member_id = ?
               AND mc.expiry_date IS NOT NULL
               AND mc.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
             ORDER BY mc.expiry_date ASC",
            [$memberId]
        );

        $now = time();
        $alerts = [];
        foreach ($items as $item) {
            $expiry = strtotime($item['expiry_date']);
            $daysRemaining = $expiry ? (int) round(($expiry - $now) / 86400) : null;

            // Check if snoozed
            $snoozed = false;
            $snoozeKey = 'cert_snooze_' . $item['id'];
            if (isset($_SESSION[$snoozeKey])) {
                $snoozeUntil = (int) $_SESSION[$snoozeKey];
                if ($snoozeUntil > $now) {
                    $snoozed = true;
                }
            }

            if (!$snoozed) {
                $alerts[] = [
                    'cert_mc_id'     => (int) $item['id'],
                    'cert_name'      => $item['cert_name'],
                    'required'       => (int) ($item['required'] ?? 0),
                    'expiry'         => $item['expiry_date'],
                    'days_remaining' => $daysRemaining,
                    'expired'        => $daysRemaining !== null && $daysRemaining < 0,
                ];
            }
        }

        ini_set('display_errors', $prevDisplay);
        json_response(['alerts' => $alerts]);
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_response(['alerts' => []]); // Non-fatal — don't block login
    }
}

// ══════════════════════════════════════════════════════════════
// ACTION: snooze — snooze a specific expiration alert
// ══════════════════════════════════════════════════════════════
elseif ($action === 'snooze') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // CSRF (F-013) — modifying session state requires the token even though
    // the impact is limited to the current user's snooze list.
    if (!csrf_verify((string) ($input['csrf_token'] ?? ''))) {
        json_error('Invalid CSRF token', 403);
    }

    $certMcId = (int) ($input['cert_mc_id'] ?? 0);
    $hours    = (int) ($input['hours'] ?? 4);

    if ($certMcId <= 0) {
        json_error('Missing cert_mc_id');
    }

    // Snooze intervals: 1h, 4h, 24h (1 day), 96h (4 days), 336h (2 weeks)
    $allowed = [1, 4, 24, 96, 336];
    if (!in_array($hours, $allowed)) {
        $hours = 4;
    }

    $snoozeKey = 'cert_snooze_' . $certMcId;
    $_SESSION[$snoozeKey] = time() + ($hours * 3600);

    ini_set('display_errors', $prevDisplay);
    json_response(['success' => true, 'snoozed_hours' => $hours]);
}

else {
    ini_set('display_errors', $prevDisplay);
    json_error('Unknown action. Valid: overview, expiring, my_alerts, snooze');
}
