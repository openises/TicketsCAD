<?php
/**
 * NewUI v4.0 API — Webhooks
 *
 * GET              List all webhooks
 * GET ?id=X        Get webhook detail with recent deliveries
 * POST action=save Create/update a webhook
 * POST action=delete  Delete a webhook
 * POST action=test    Send a test payload
 *
 * Requires action.manage_config permission (or admin level <= 1).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/webhooks.php';

// Suppress display_errors to keep JSON clean
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Auth: require config permission (or legacy admin level) ─────
if (!rbac_can('action.manage_config') && !is_admin()) {
    json_error('Admin access required', 403);
}

// ── CSRF on writes ──────────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

// ═══════════════════════════════════════════════════════════════
//  GET — List all webhooks or single detail with deliveries
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {
    // Phase 94 Stage 6 part 2 (2026-06-28): reads from the NEW
    // webhook_subscriptions table (per Decision #3) instead of the
    // legacy `webhooks` table. Column projection aliases the new
    // names to the legacy names the existing settings panel JS
    // expects (url, secret, events_json, retry_max), so no JS
    // changes were needed. Retry_max is derived from the per-
    // subscription retry_policy_json's max_attempts field, default 5.
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id > 0) {
        try {
            $hook = db_fetch_one(
                "SELECT s.id, s.name, s.description,
                        s.target_url        AS url,
                        s.hmac_secret       AS secret,
                        s.event_filters_json AS events_json,
                        s.active,
                        CAST(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(s.retry_policy_json, '{\"max_attempts\":5}'), '$.max_attempts')) AS UNSIGNED) AS retry_max,
                        s.ip_allowlist_json,
                        s.created_by, s.created_at, s.updated_at,
                        s.last_success_at, s.last_failure_at, s.dead_letter_count
                 FROM `{$prefix}webhook_subscriptions` s
                 WHERE s.id = ?",
                [$id]
            );
        } catch (Exception $e) {
            json_error('Query failed: ' . $e->getMessage(), 500);
        }

        if (!$hook) {
            json_error('Webhook not found', 404);
        }

        // 2026-06-28 security audit fix #6: mask the HMAC secret in
        // the detail response. The original commit 372a5c2 added the
        // SAVE-path keep-current logic + the documenting comments but
        // the actual masking block was silently dropped from the Edit
        // (Edit reported success but the hunk didn't land in the
        // file). test_external_api_security caught it on 2026-06-28.
        //
        // Re-applying: zero out the full secret in the response so
        // admin can never read it after creation, but expose a short
        // prefix so they can confirm "yes this is the same secret I
        // captured at create time" without disclosing the full value.
        if (!empty($hook['secret'])) {
            $hook['secret_prefix'] = substr((string) $hook['secret'], 0, 8);
            $hook['secret']        = null;
        }

        $hook['events'] = @json_decode($hook['events_json'], true) ?: [];

        try {
            $deliveries = db_fetch_all(
                "SELECT `id`, `event_type`, `http_status`, `duration_ms`, `attempt`,
                        `status`, `error`, `created_at`, `dead_lettered_at`
                 FROM `{$prefix}webhook_deliveries`
                 WHERE `subscription_id` = ?
                 ORDER BY `created_at` DESC
                 LIMIT 20",
                [$id]
            );
        } catch (Exception $e) {
            $deliveries = [];
        }

        json_response(['webhook' => $hook, 'deliveries' => $deliveries]);
    }

    // List all subscriptions
    try {
        $rows = db_fetch_all(
            "SELECT s.id, s.name, s.description,
                    s.target_url        AS url,
                    s.hmac_secret       AS secret,
                    s.event_filters_json AS events_json,
                    s.active,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(s.retry_policy_json, '{\"max_attempts\":5}'), '$.max_attempts')) AS UNSIGNED) AS retry_max,
                    s.created_by, s.created_at, s.updated_at,
                    s.last_success_at, s.last_failure_at, s.dead_letter_count,
                    (SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` d
                     WHERE d.subscription_id = s.id AND d.status = 'success') AS success_count,
                    (SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` d
                     WHERE d.subscription_id = s.id AND d.status = 'failed') AS fail_count,
                    (SELECT d2.status FROM `{$prefix}webhook_deliveries` d2
                     WHERE d2.subscription_id = s.id ORDER BY d2.created_at DESC LIMIT 1) AS last_status,
                    (SELECT d3.created_at FROM `{$prefix}webhook_deliveries` d3
                     WHERE d3.subscription_id = s.id ORDER BY d3.created_at DESC LIMIT 1) AS last_delivery_at
             FROM `{$prefix}webhook_subscriptions` s
             ORDER BY s.name"
        );
    } catch (Exception $e) {
        json_error('Query failed: ' . $e->getMessage(), 500);
    }

    foreach ($rows as &$row) {
        $row['events'] = @json_decode($row['events_json'], true) ?: [];
        // 2026-06-28 security audit fix #6: same masking as the detail
        // GET above. List response previously also returned full HMAC
        // secrets which is even worse — an admin opening the webhook
        // panel would get every subscription's secret at once.
        if (!empty($row['secret'])) {
            $row['secret_prefix'] = substr((string) $row['secret'], 0, 8);
            $row['secret']        = null;
        }
    }
    unset($row);

    json_response(['rows' => $rows]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Create, Update, Delete, Test
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $action = trim($input['action'] ?? 'save');

    // ── DELETE ───────────────────────────────────────────────
    // Phase 94 Stage 6 part 2: deletes from webhook_subscriptions
    // (the new table per Decision #3) and uses subscription_id on
    // the deliveries cleanup. The legacy `webhooks` table is kept in
    // place until Stage 6 fully decommissions it; deleting via this
    // endpoint only touches the new authoritative table.
    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) json_error('Missing webhook id');

        try {
            // Delete deliveries first (orphaned otherwise)
            db_query("DELETE FROM `{$prefix}webhook_deliveries` WHERE `subscription_id` = ?", [$id]);
            db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'webhook_subscription', $id, "Deleted webhook subscription #{$id}");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    // ── TEST ────────────────────────────────────────────────
    if ($action === 'test') {
        $url    = trim($input['url'] ?? '');
        $secret = trim($input['secret'] ?? '');

        if ($url === '') json_error('URL is required');

        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            json_error('Invalid URL format');
        }

        $result = webhook_test($url, $secret);
        json_response(['test' => $result]);
    }

    // ── REPLAY (Phase 94 Stage 5.3) ─────────────────────────
    // Takes a delivery_id that's in 'dead_letter' status (or 'failed'
    // — admin's choice), reads the original payload, fires a fresh
    // delivery against the same subscription with attempt=1 and
    // replayed_from_id=<original_id>. The new row lives or dies on
    // its own merits; the dead-letter row stays marked for the audit
    // trail.
    if ($action === 'replay') {
        if (!rbac_can('action.manage_webhooks') && !is_admin()) {
            json_error('Permission required: action.manage_webhooks', 403);
        }
        $deliveryId = (int) ($input['delivery_id'] ?? 0);
        if ($deliveryId <= 0) json_error('delivery_id required');

        try {
            $orig = db_fetch_one(
                "SELECT d.*, s.target_url, s.hmac_secret, s.active AS sub_active
                 FROM `{$prefix}webhook_deliveries` d
                 JOIN `{$prefix}webhook_subscriptions` s ON s.id = d.subscription_id
                 WHERE d.id = ?",
                [$deliveryId]
            );
        } catch (Exception $e) {
            json_error('Lookup failed: ' . $e->getMessage(), 500);
        }
        if (!$orig) json_error('Delivery not found', 404);
        if (!$orig['sub_active']) json_error('Subscription is inactive — re-activate before replay');

        // Create the fresh delivery row, linked back to the original
        $body = $orig['payload'];
        $signature = hash_hmac('sha256', $body, $orig['hmac_secret']);
        try {
            db_query(
                "INSERT INTO `{$prefix}webhook_deliveries`
                 (`subscription_id`, `event_type`, `payload`, `attempt`, `status`, `replayed_from_id`)
                 VALUES (?, ?, ?, 1, 'pending', ?)",
                [$orig['subscription_id'], $orig['event_type'], $body, $deliveryId]
            );
            $newId = (int) db_insert_id();
        } catch (Exception $e) {
            json_error('Failed to create replay row: ' . $e->getMessage(), 500);
        }

        // Fire (calls back into webhooks.php internal _webhook_send)
        require_once __DIR__ . '/../inc/webhooks.php';
        _webhook_send(
            ['id' => $orig['subscription_id'], 'target_url' => $orig['target_url'], 'hmac_secret' => $orig['hmac_secret']],
            $body, $signature, $newId, 1
        );

        // Re-fetch to surface the post-send status
        try {
            $newRow = db_fetch_one(
                "SELECT id, status, http_status, duration_ms, error
                 FROM `{$prefix}webhook_deliveries` WHERE id = ?",
                [$newId]
            );
        } catch (Exception $e) { $newRow = null; }

        audit_log('config', 'replay', 'webhook_delivery', $newId,
            "Replayed delivery #{$deliveryId} as #{$newId}");

        json_response([
            'replay_id' => $newId,
            'replayed_from' => $deliveryId,
            'delivery' => $newRow,
        ]);
    }

    // ── FIRE_NOW (Phase 94 Stage 5.5) ───────────────────────
    // Exercises the FULL audit→event→subscription→delivery chain
    // with a synthetic payload. Distinct from `test` which just
    // round-trips an arbitrary URL outside the subscription path.
    // Lets an admin verify "yes, when this event-type fires for
    // real, my subscriber receives the delivery" without waiting
    // for a genuine event. Returns the delivery row id so the
    // admin can inspect the result.
    if ($action === 'fire_now') {
        if (!rbac_can('action.manage_webhooks') && !is_admin()) {
            json_error('Permission required: action.manage_webhooks', 403);
        }
        $subscriptionId = (int) ($input['subscription_id'] ?? 0);
        $eventType      = trim($input['event_type'] ?? '');
        if ($subscriptionId <= 0) json_error('subscription_id required');
        if ($eventType === '')    json_error('event_type required (e.g. incident.created)');

        // Verify the subscription exists + is active so we get a
        // useful error instead of "0 fired" silence.
        try {
            $sub = db_fetch_one(
                "SELECT id, name, active FROM `{$prefix}webhook_subscriptions` WHERE id = ?",
                [$subscriptionId]
            );
        } catch (Exception $e) {
            json_error('Subscription lookup failed: ' . $e->getMessage(), 500);
        }
        if (!$sub) json_error('Subscription not found', 404);
        if (!$sub['active']) json_error('Subscription is inactive — enable it before firing');

        // Build a fake-but-realistic payload that mirrors the shape
        // of audit-driven fires (see inc/audit.php Stage 5 hook).
        $payload = [
            'category'    => 'admin_test',
            'activity'    => 'fire_now',
            'target_type' => null,
            'target_id'   => null,
            'summary'     => 'Synthetic test fire from admin UI',
            'details'     => ['triggered_by' => $_SESSION['user'] ?? 'admin'],
            'actor_id'    => $_SESSION['user_id'] ?? null,
            'actor_name'  => $_SESSION['user'] ?? null,
            'event_time'  => gmdate('Y-m-d\TH:i:s\Z'),
            'is_synthetic' => true,
        ];

        // Fire goes through webhook_fire() — which will match this
        // subscription if event_filters_json includes the event_type
        // (or '*' or a matching prefix wildcard). If filters don't
        // match, fired=0 and we tell the admin so.
        $fired = webhook_fire($eventType, $payload);
        audit_log('config', 'fire_now', 'webhook_subscription', $subscriptionId,
            "Admin fired synthetic event '{$eventType}' against subscription '{$sub['name']}'");

        if ($fired === 0) {
            json_response([
                'fired' => 0,
                'message' => "Subscription '{$sub['name']}' did not match event_type '{$eventType}'. " .
                             "Check its event_filters_json — does it include '{$eventType}' or a matching wildcard?",
            ]);
        }

        // Find the just-created delivery row so the admin can inspect it
        try {
            $delivery = db_fetch_one(
                "SELECT id, status, http_status, duration_ms, error
                 FROM `{$prefix}webhook_deliveries`
                 WHERE subscription_id = ? AND event_type = ?
                 ORDER BY id DESC LIMIT 1",
                [$subscriptionId, $eventType]
            );
        } catch (Exception $e) { $delivery = null; }

        json_response([
            'fired'       => $fired,
            'delivery'    => $delivery,
            'message'     => "Fired synthetic '{$eventType}' against subscription '{$sub['name']}'.",
        ]);
    }

    // ── SAVE (create / update) ──────────────────────────────
    // Phase 94 Stage 6 part 2: writes to webhook_subscriptions (NEW
    // table per Decision #3) instead of the legacy `webhooks` table.
    // Legacy field names on input (url, secret, events, retry_max)
    // are accepted for back-compat with the unchanged settings UI;
    // retry_max gets wrapped in the retry_policy_json shape with
    // exponential-backoff defaults. New optional input fields:
    // description, ip_allowlist (array of CIDR strings).
    if ($action === 'save') {
        $id        = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $name      = trim($input['name'] ?? '');
        $url       = trim($input['url'] ?? '');
        $secret    = trim($input['secret'] ?? '');
        $events    = $input['events'] ?? [];
        $active    = (int) ($input['active'] ?? 1);
        $retryMax  = (int) ($input['retry_max'] ?? 5);
        $description = trim($input['description'] ?? '');
        $ipAllow   = $input['ip_allowlist'] ?? null;

        if ($name === '') json_error('Subscription name is required');
        if ($url === '')  json_error('Target URL is required');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            json_error('Invalid URL format');
        }
        if (!is_array($events) || empty($events)) {
            json_error('At least one event type must be selected');
        }

        if ($retryMax < 1) $retryMax = 1;
        if ($retryMax > 10) $retryMax = 10;

        if ($secret === '' && !$id) {
            $secret = bin2hex(random_bytes(32));
        }

        $eventsJson = json_encode(array_values($events));
        // Build the retry_policy_json shape webhook_process_retries
        // expects. Backoff array is the canonical exponential
        // schedule from Stage 5's design: 30s, 60s, 120s, 240s, 480s.
        $retryPolicyJson = json_encode([
            'max_attempts'    => $retryMax,
            'backoff_seconds' => [30, 60, 120, 240, 480],
        ]);
        $ipAllowJson = (is_array($ipAllow) && !empty($ipAllow))
            ? json_encode(array_values($ipAllow))
            : null;

        // 2026-06-28 security audit fix #6 companion change. The detail
        // GET now masks the HMAC secret (returns secret_prefix only).
        // So the SAVE path must treat blank $secret on UPDATE as "keep
        // current value" — otherwise opening an existing webhook in the
        // edit form and clicking Save would clobber the real secret with
        // an empty string. To rotate, the admin clears the field manually
        // and submits a NEW value. On CREATE we auto-generate a secret
        // if missing so the integrator never has a chance to pick a
        // weak one.
        $wasEdit = (bool) $id;
        try {
            if ($id) {
                if ($secret === '') {
                    // Keep current secret untouched on UPDATE
                    $sql = "UPDATE `{$prefix}webhook_subscriptions` SET
                        `name` = ?, `description` = ?, `target_url` = ?,
                        `event_filters_json` = ?, `retry_policy_json` = ?, `active` = ?,
                        `ip_allowlist_json` = ?
                        WHERE `id` = ?";
                    db_query($sql, [
                        $name, ($description ?: null), $url,
                        $eventsJson, $retryPolicyJson, $active, $ipAllowJson,
                        $id
                    ]);
                } else {
                    $sql = "UPDATE `{$prefix}webhook_subscriptions` SET
                        `name` = ?, `description` = ?, `target_url` = ?, `hmac_secret` = ?,
                        `event_filters_json` = ?, `retry_policy_json` = ?, `active` = ?,
                        `ip_allowlist_json` = ?
                        WHERE `id` = ?";
                    db_query($sql, [
                        $name, ($description ?: null), $url, $secret,
                        $eventsJson, $retryPolicyJson, $active, $ipAllowJson,
                        $id
                    ]);
                }
                audit_log('config', 'update', 'webhook_subscription', $id,
                    "Updated webhook subscription '{$name}'" .
                    ($secret === '' ? ' (secret unchanged)' : ' (secret rotated)'));
            } else {
                // CREATE — auto-generate a strong secret if none supplied
                if ($secret === '') {
                    $secret = bin2hex(random_bytes(32));
                }
                $sql = "INSERT INTO `{$prefix}webhook_subscriptions`
                    (`name`, `description`, `target_url`, `hmac_secret`,
                     `event_filters_json`, `retry_policy_json`, `active`,
                     `ip_allowlist_json`, `created_by`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                db_query($sql, [
                    $name, ($description ?: null), $url, $secret,
                    $eventsJson, $retryPolicyJson, $active, $ipAllowJson,
                    $current_user_id
                ]);
                $id = (int) db_insert_id();
                audit_log('config', 'create', 'webhook_subscription', $id, "Created webhook subscription '{$name}'");
            }
            // Only return the secret on CREATE (capture-once flow). On
            // UPDATE — even if the secret was just rotated — the admin
            // must capture from create-time docs or the audit log; never
            // re-disclose via this response, so the response can't be
            // abused as a read-back path.
            json_response([
                'saved'  => true,
                'id'     => $id,
                'secret' => $wasEdit ? null : $secret,
            ]);
        } catch (Exception $e) {
            error_log('[webhooks.php SAVE] db error: ' . $e->getMessage());
            json_error('Save failed: database error', 500);
        }
    }

    json_error('Unknown action: ' . $action, 400);
}

ini_set('display_errors', $prevDisplay);
json_error('Method not allowed', 405);
