<?php
/**
 * NewUI v4.0 API — Webhook Deliveries (Phase 94 Stage 5.4)
 *
 * GET  /api/webhook-deliveries.php
 *      → list recent deliveries across all subscriptions with status
 *        counts per time window (1h, 24h, 7d) so admins can see
 *        webhook health at a glance.
 *
 * GET  /api/webhook-deliveries.php?id=N
 *      → full detail for a single delivery: payload, response body,
 *        timing, replay lineage.
 *
 * GET  /api/webhook-deliveries.php?subscription_id=N
 *      → recent deliveries scoped to a single subscription.
 *
 * Admin-gated by action.manage_webhooks. Read-only.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    json_error('Method not allowed', 405);
}

if (!rbac_can('action.manage_webhooks') && !is_admin()) {
    json_error('Admin access required', 403);
}

// ── Single-delivery detail ──────────────────────────────────────
if (!empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    try {
        $row = db_fetch_one(
            "SELECT d.*, s.name AS subscription_name, s.target_url
             FROM `{$prefix}webhook_deliveries` d
             LEFT JOIN `{$prefix}webhook_subscriptions` s ON s.id = d.subscription_id
             WHERE d.id = ?",
            [$id]
        );
    } catch (Exception $e) {
        json_error('Lookup failed: ' . $e->getMessage(), 500);
    }
    if (!$row) json_error('Delivery not found', 404);
    json_response(['delivery' => $row]);
}

// ── List recent + per-window counts ─────────────────────────────
$subId = isset($_GET['subscription_id']) ? (int) $_GET['subscription_id'] : 0;
$limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

$whereClause = $subId > 0 ? 'WHERE d.subscription_id = ?' : '';
$whereParams = $subId > 0 ? [$subId] : [];

try {
    $recent = db_fetch_all(
        "SELECT d.id, d.subscription_id, d.event_type, d.status, d.http_status,
                d.attempt, d.duration_ms, d.created_at, d.dead_lettered_at,
                d.replayed_from_id, d.error,
                s.name AS subscription_name, s.target_url
         FROM `{$prefix}webhook_deliveries` d
         LEFT JOIN `{$prefix}webhook_subscriptions` s ON s.id = d.subscription_id
         {$whereClause}
         ORDER BY d.id DESC
         LIMIT {$limit}",
        $whereParams
    );
} catch (Exception $e) {
    $recent = [];
}

// Per-window counts (success / failed / dead_letter) for 1h, 24h, 7d
// One query per window using SUM(CASE …) so we get all four counts in
// one row each window.
$counts = ['hour' => null, 'day' => null, 'week' => null];
$windows = [
    'hour' => '1 HOUR',
    'day'  => '24 HOUR',
    'week' => '7 DAY',
];
foreach ($windows as $key => $interval) {
    try {
        $row = db_fetch_one(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'success'     THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status = 'failed'      THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'dead_letter' THEN 1 ELSE 0 END) AS dead_letter,
                SUM(CASE WHEN status = 'pending'     THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'retried'     THEN 1 ELSE 0 END) AS retried
             FROM `{$prefix}webhook_deliveries`
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$interval})
               " . ($subId > 0 ? "AND subscription_id = ?" : ""),
            $subId > 0 ? [$subId] : []
        );
        $counts[$key] = $row;
    } catch (Exception $e) {
        $counts[$key] = null;
    }
}

// Per-subscription health summary (only if not scoping to one already)
$subscriptions = [];
if ($subId === 0) {
    try {
        $subscriptions = db_fetch_all(
            "SELECT id, name, target_url, active, last_success_at, last_failure_at,
                    dead_letter_count
             FROM `{$prefix}webhook_subscriptions`
             ORDER BY active DESC, name ASC"
        );
    } catch (Exception $e) { $subscriptions = []; }
}

json_response([
    'deliveries'    => $recent,
    'counts'        => $counts,
    'subscriptions' => $subscriptions,
    'scoped_to'     => $subId > 0 ? $subId : null,
]);

ini_set('display_errors', $prevDisplay);
