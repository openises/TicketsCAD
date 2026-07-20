<?php
/**
 * Phase 115 — Facility quick actions for the dashboard facilities widget.
 *
 * GET   ?facility_id=N    → { statuses:[{id,name,bg_color,text_color}],
 *                             facility:{id,name,status_id,beds_a,beds_o,beds_info} }
 *                           (drives the Status/Beds modals; no write)
 *
 * POST JSON { action, facility_id, csrf_token, ... }:
 *   action=status  { status_id, note? }   → set facilities.status_id (+history)
 *   action=note    { note }                → append a facility note (history)
 *   action=beds    { beds_a?, beds_o?, note? } → set legacy bed counts (+history)
 *
 * RBAC: status/note → action.manage_facilities; beds → action.update_capacity
 * (the existing bed/capacity permission). is_admin() bypasses. Every write
 * appends an append-only facility_notes row (denormalized username, IP) and
 * fires audit_log under the 'facility' category. A logging failure is
 * swallowed, never fatal to the underlying update (audit-trail standing rule).
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

/** RBAC gate with is_admin bypass. */
function _fac_can(string $perm): bool
{
    return (function_exists('is_admin') && is_admin())
        || (function_exists('rbac_can') && rbac_can($perm));
}

/** Append an append-only facility_notes history row (never fatal). */
function _fac_note(int $facilityId, string $category, string $note, ?string $detail): void
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        require_once __DIR__ . '/../inc/client-ip.php';
        $ip = function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null);
        // GH #75 — self-heal a missing facility_notes table so a fresh/old
        // install can't silently drop notes (this used to swallow the error
        // completely). The detail page now reads these rows back.
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_notes` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `facility_id` INT NOT NULL,
            `category`   VARCHAR(32) NOT NULL DEFAULT 'general',
            `note`       VARCHAR(1000) NOT NULL,
            `detail`     VARCHAR(255) NULL,
            `user_id`    INT NOT NULL DEFAULT 0,
            `username`   VARCHAR(64) NOT NULL DEFAULT '',
            `source_ip`  VARCHAR(64) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_facility_time` (`facility_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db_query(
            "INSERT INTO `{$prefix}facility_notes`
                (facility_id, category, note, detail, user_id, username, source_ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $facilityId, $category,
                mb_substr($note, 0, 1000),
                $detail !== null ? mb_substr($detail, 0, 255) : null,
                (int) ($_SESSION['user_id'] ?? 0),
                mb_substr((string) ($_SESSION['user'] ?? ''), 0, 64),
                $ip,
            ]
        );
    } catch (Throwable $e) {
        // Must never break the status/bed action, but don't hide it either.
        error_log('[facility-action] facility_notes insert failed: ' . $e->getMessage());
    }
}

// ── GET: lookup data for the modals ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $facilityId = (int) ($_GET['facility_id'] ?? 0);
    if ($facilityId <= 0) json_error('Invalid facility_id');
    try {
        $facility = db_fetch_one(
            "SELECT `id`, `name`, `status_id`, `beds_a`, `beds_o`, `beds_info`
             FROM `{$prefix}facilities` WHERE `id` = ? LIMIT 1",
            [$facilityId]
        );
        if (!$facility) json_error('Facility not found', 404);

        $statuses = [];
        foreach (db_fetch_all("SELECT `id`, `status_val`, `bg_color`, `text_color`
                               FROM `{$prefix}fac_status` ORDER BY `sort`, `id`") as $s) {
            $statuses[] = [
                'id'         => (int) $s['id'],
                'name'       => $s['status_val'] ?? '',
                'bg_color'   => $s['bg_color'] ?? null,
                'text_color' => $s['text_color'] ?? null,
            ];
        }
        json_response([
            'success'  => true,
            'statuses' => $statuses,
            'facility' => [
                'id'        => (int) $facility['id'],
                'name'      => $facility['name'] ?? '',
                'status_id' => (int) $facility['status_id'],
                'beds_a'    => $facility['beds_a'] ?? '',
                'beds_o'    => $facility['beds_o'] ?? '',
                'beds_info' => $facility['beds_info'] ?? '',
            ],
        ]);
    } catch (Throwable $e) {
        json_error_safe('Lookup failed', $e, 'facility-action-get', 500);
    }
}

// ── POST: the writes ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$action     = (string) ($input['action'] ?? '');
$facilityId = (int) ($input['facility_id'] ?? 0);
if ($facilityId <= 0) json_error('Invalid facility_id');

try {
    $facility = db_fetch_one(
        "SELECT `id`, `name` FROM `{$prefix}facilities` WHERE `id` = ? LIMIT 1",
        [$facilityId]
    );
} catch (Throwable $e) {
    json_error_safe('Database error', $e, 'facility-action-lookup', 500);
}
if (!$facility) json_error('Facility not found', 404);
$facName = $facility['name'] ?: ('facility #' . $facilityId);

switch ($action) {
    case 'status':
        if (!_fac_can('action.manage_facilities')) {
            json_error('Insufficient permissions: manage facilities', 403);
        }
        $statusId = (int) ($input['status_id'] ?? 0);
        $note     = trim((string) ($input['note'] ?? ''));
        if ($statusId <= 0) json_error('A status is required');
        try {
            $status = db_fetch_one(
                "SELECT `id`, `status_val` FROM `{$prefix}fac_status` WHERE `id` = ? LIMIT 1",
                [$statusId]
            );
            if (!$status) json_error('Unknown status', 400);

            // status_about carries the latest status note on the record;
            // facility_notes keeps the running history.
            db_query(
                "UPDATE `{$prefix}facilities` SET `status_id` = ?, `status_about` = ? WHERE `id` = ?",
                [$statusId, mb_substr($note, 0, 512), $facilityId]
            );
            $label = $status['status_val'] ?: ('status #' . $statusId);
            _fac_note($facilityId, 'status', $note !== '' ? $note : ('Status → ' . $label),
                'Status: ' . $label);
            audit_log('facility', 'status_change', 'facility', $facilityId,
                "Facility {$facName} status → {$label}",
                ['facility_id' => $facilityId, 'status_id' => $statusId, 'status' => $label,
                 'note' => $note, 'source' => 'dashboard_quick_action']);
            json_response(['success' => true, 'message' => $facName . ' set to ' . $label,
                           'status_id' => $statusId, 'status_name' => $label]);
        } catch (Throwable $e) {
            json_error_safe('Status update failed', $e, 'facility-action-status', 500);
        }
        break;

    case 'note':
        if (!_fac_can('action.manage_facilities')) {
            json_error('Insufficient permissions: manage facilities', 403);
        }
        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') json_error('Note text is required');
        if (mb_strlen($note) > 1000) json_error('Note must be 1000 characters or fewer');
        _fac_note($facilityId, 'note', $note, null);
        audit_log('facility', 'note', 'facility', $facilityId,
            "Note on {$facName}: " . mb_substr($note, 0, 200),
            ['facility_id' => $facilityId, 'note' => $note, 'source' => 'dashboard_quick_action']);
        json_response(['success' => true, 'message' => 'Note recorded for ' . $facName]);
        break;

    case 'beds':
        if (!_fac_can('action.update_capacity')) {
            json_error('Insufficient permissions: update capacity', 403);
        }
        // Only overwrite the fields the caller actually sent (partial update).
        $sets = [];
        $args = [];
        $summaryBits = [];
        if (array_key_exists('beds_a', $input)) {
            $ba = (string) (int) $input['beds_a'];
            $sets[] = '`beds_a` = ?'; $args[] = $ba; $summaryBits[] = 'avail ' . $ba;
        }
        if (array_key_exists('beds_o', $input)) {
            $bo = (string) (int) $input['beds_o'];
            $sets[] = '`beds_o` = ?'; $args[] = $bo; $summaryBits[] = 'occ ' . $bo;
        }
        $note = trim((string) ($input['note'] ?? ''));
        if ($note !== '') { $sets[] = '`beds_info` = ?'; $args[] = mb_substr($note, 0, 2048); }
        if (!$sets) json_error('Nothing to update (send beds_a and/or beds_o)');
        try {
            $args[] = $facilityId;
            db_query("UPDATE `{$prefix}facilities` SET " . implode(', ', $sets) . " WHERE `id` = ?", $args);
            $detail = 'Beds: ' . ($summaryBits ? implode(', ', $summaryBits) : 'note');
            _fac_note($facilityId, 'beds', $note !== '' ? $note : $detail, $detail);
            audit_log('facility', 'beds_update', 'facility', $facilityId,
                "Bed counts on {$facName}: " . implode(', ', $summaryBits),
                ['facility_id' => $facilityId, 'beds_a' => $input['beds_a'] ?? null,
                 'beds_o' => $input['beds_o'] ?? null, 'note' => $note,
                 'source' => 'dashboard_quick_action']);
            json_response(['success' => true, 'message' => 'Bed counts updated for ' . $facName]);
        } catch (Throwable $e) {
            json_error_safe('Bed update failed', $e, 'facility-action-beds', 500);
        }
        break;

    default:
        json_error('Unknown action: ' . $action);
}
