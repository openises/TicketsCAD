<?php
/**
 * Chat Bridge — GH#89 (public repo, follow-up to GH#84)
 *
 * Wires the four "Bridge -> X" checkboxes in Settings > Chat Settings >
 * Cross-platform Bridges (settings.php, data-key chat_bridge_telegram /
 * chat_bridge_slack / chat_bridge_email / chat_bridge_mesh) to real
 * behaviour. They have saved to the `settings` table since June (commit
 * 6cc4362) but had zero consumer anywhere in the codebase — checking one
 * changed nothing.
 *
 * Design: reuse the existing Message Routing engine (inc/router.php)
 * instead of building a second bridging mechanism. Each checkbox owns
 * exactly one system-managed row in `message_routes`
 * (source_channel = 'local_chat', dest_channel = that bridge's broker
 * channel code), identified by a stable `managed_key`
 * (e.g. 'chat_bridge:slack') rather than by name or position, so an admin
 * renaming/reordering the rule in the Message Routing admin UI never
 * orphans it.
 *
 * "Sync never clobbers a hand-created row" — same contract
 * inc/channel_registry.php's channel_registry_sync() already uses for
 * comm_channels: a row this file did not create (managed=0, or a
 * different managed_key) is never read, updated, or deleted by
 * chat_bridge_sync(). An admin who has separately hand-built a
 * local_chat -> slack routing rule via the Message Routing UI keeps it,
 * untouched, alongside whatever this file creates.
 *
 * Toggling OFF disables the managed row (`enabled = 0`) rather than
 * deleting it, so re-checking the box later restores any customization
 * (filters, description, transform) an admin made to it in the interim —
 * the same disable-not-delete choice Phase 129's PAR scheduler and this
 * file's own sibling patterns favor for reversible state.
 *
 * DM SAFETY (the one hard requirement here): a local_chat direct/private
 * message must NEVER be forwarded externally by the rule this file
 * creates, REGARDLESS of what that rule's filters_json could otherwise be
 * configured to match. That guarantee is NOT implemented here and is NOT
 * expressed as a filter on the row (filters_json is admin-editable via the
 * Message Routing UI, so a filter-based exclusion could be configured
 * away, accidentally or otherwise). It is enforced unconditionally inside
 * inc/router.php's router_evaluate()/router_test() for EVERY route whose
 * source_channel is 'local_chat' — managed by this file or hand-created —
 * via _router_message_is_local_chat_dm(). See that function's docblock
 * and docs/CHAT-BRIDGE.md.
 */

require_once __DIR__ . '/router.php';

/**
 * The four checkbox -> destination-channel mappings. Single source of
 * truth for chat_bridge_sync() and anything else (docs, tests, a future
 * admin "resync" tool) that needs to know what a given checkbox targets.
 *
 * @return array<string, array{managed_key:string, dest_channel:string, name:string, description:string}>
 */
function chat_bridge_definitions(): array {
    // message_routes.description is VARCHAR(255) -- keep every string
    // below that comfortably, and chat_bridge_sync() also truncates
    // defensively before INSERT so a future edit here can't overflow it.
    $note = 'DMs are never bridged (enforced by the router, not this rule).';
    $prefix = 'System-managed by the "Bridge -> %s" toggle (Settings > Chat Settings). ';

    return [
        'chat_bridge_slack' => [
            'managed_key'  => 'chat_bridge:slack',
            'dest_channel' => 'slack',
            'name'         => 'Chat Bridge -> Slack',
            'description'  => sprintf($prefix, 'Slack') . $note,
        ],
        'chat_bridge_telegram' => [
            'managed_key'  => 'chat_bridge:telegram',
            'dest_channel' => 'telegram',
            'name'         => 'Chat Bridge -> Telegram',
            'description'  => sprintf($prefix, 'Telegram') . $note,
        ],
        'chat_bridge_email' => [
            'managed_key'  => 'chat_bridge:email',
            'dest_channel' => 'email',
            'name'         => 'Chat Bridge -> Email',
            'description'  => sprintf($prefix, 'Email')
                             . 'Sends each message live as sent; no digest/batch '
                             . '(docs/CHAT-BRIDGE.md). ' . $note,
        ],
        'chat_bridge_mesh' => [
            'managed_key'  => 'chat_bridge:mesh',
            'dest_channel' => 'meshtastic',
            'name'         => 'Chat Bridge -> Mesh Radio',
            'description'  => sprintf($prefix, 'Mesh radio') . $note,
        ],
    ];
}

/**
 * Idempotent guarded ALTER for message_routes.managed / .managed_key.
 * Belt-and-braces alongside sql/run_phase146_chat_bridge_routing.php for
 * an install that saves a chat_bridge_* setting before migrations have
 * been (re-)run — mirrors the self-healing pattern CLAUDE.md's "Defensive
 * Database Patterns" section documents and inc/channels/local_chat.php's
 * _chat_ensure_schema() already uses for the same reason.
 */
function _chat_bridge_ensure_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $cols = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'message_routes']
        );
        $present = array_map(function ($c) { return strtolower($c['COLUMN_NAME']); }, $cols);

        if (!in_array('managed', $present, true)) {
            db_query(
                "ALTER TABLE `{$prefix}message_routes`
                 ADD COLUMN `managed` TINYINT NOT NULL DEFAULT 0
                 COMMENT 'system-managed row (1, e.g. a chat-bridge checkbox) vs hand-created by an admin (0)'"
            );
        }
        if (!in_array('managed_key', $present, true)) {
            db_query(
                "ALTER TABLE `{$prefix}message_routes`
                 ADD COLUMN `managed_key` VARCHAR(64) DEFAULT NULL
                 COMMENT 'stable identity for a managed row, e.g. chat_bridge:slack -- never set on a hand-created row'"
            );
            // NULL is distinct in a MySQL/MariaDB unique index (every
            // existing hand-created row has managed_key = NULL and stays
            // valid), so this only ever constrains the managed rows this
            // file writes -- see CLAUDE.md's Phase 129 NULL-uniqueness note.
            try {
                db_query("ALTER TABLE `{$prefix}message_routes` ADD UNIQUE KEY `uk_managed_key` (`managed_key`)");
            } catch (Exception $e) {
                // Key may already exist from a concurrent run; non-fatal.
            }
        }
    } catch (Exception $e) {
        error_log('[_chat_bridge_ensure_columns] ' . $e->getMessage());
    }
}

/**
 * Sync `message_routes` with the current chat_bridge_* settings.
 *
 * Reads the LIVE settings table directly (not get_variable()) because
 * get_variable() caches for the lifetime of the request -- if anything
 * earlier in the same request already called it (common during page
 * bootstrap), it would hand back the value from BEFORE this request's
 * settings save wrote the new one. See CLAUDE.md's "TWO settings stores"
 * pitfall for the sibling lesson about this table; the caching trap here
 * is a different one but the same family of bug (reading a place other
 * than the one that was just written).
 *
 * Call this after any settings save that may have touched one of the four
 * keys (api/config-admin.php, section=settings). Idempotent and safe to
 * call unconditionally -- a no-op call costs one SELECT.
 *
 * @return array{created:int, enabled:int, disabled:int}
 */
function chat_bridge_sync(): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (function_exists('_router_ensure_tables')) {
        _router_ensure_tables();
    }
    _chat_bridge_ensure_columns();

    $definitions = chat_bridge_definitions();
    $result = ['created' => 0, 'enabled' => 0, 'disabled' => 0];

    $settingKeys = array_keys($definitions);
    $placeholders = implode(', ', array_fill(0, count($settingKeys), '?'));
    $rows = db_fetch_all(
        "SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` IN ($placeholders)",
        $settingKeys
    );
    $current = [];
    foreach ($rows as $r) {
        $current[$r['name']] = $r['value'];
    }

    foreach ($definitions as $settingKey => $def) {
        $want = (($current[$settingKey] ?? '0') === '1');

        $existing = db_fetch_one(
            "SELECT `id`, `enabled`, `managed` FROM `{$prefix}message_routes` WHERE `managed_key` = ?",
            [$def['managed_key']]
        );

        if (!$existing) {
            if (!$want) {
                continue; // Nothing to create; box is unchecked.
            }
            db_query(
                "INSERT INTO `{$prefix}message_routes`
                    (`name`, `description`, `enabled`, `priority`, `source_channel`, `dest_channel`,
                     `direction`, `filters_json`, `transform_json`, `managed`, `managed_key`, `created_by`)
                 VALUES (?, ?, 1, 100, 'local_chat', ?, 'outbound', NULL, NULL, 1, ?, ?)",
                [
                    substr($def['name'], 0, 100),
                    substr($def['description'], 0, 255),
                    $def['dest_channel'],
                    $def['managed_key'],
                    $_SESSION['user_id'] ?? null,
                ]
            );
            $result['created']++;
            continue;
        }

        // A row carrying this managed_key should always have managed=1
        // (only this function ever sets managed_key) -- but if a fresh
        // install's schema heal ran oddly, or a DB was hand-edited, treat
        // managed=0 as "hands off" rather than assuming it's safe to flip.
        if ((int) $existing['managed'] !== 1) {
            continue;
        }

        $currentlyEnabled = ((int) $existing['enabled'] === 1);
        if ($want && !$currentlyEnabled) {
            db_query("UPDATE `{$prefix}message_routes` SET `enabled` = 1 WHERE `id` = ?", [$existing['id']]);
            $result['enabled']++;
        } elseif (!$want && $currentlyEnabled) {
            db_query("UPDATE `{$prefix}message_routes` SET `enabled` = 0 WHERE `id` = ?", [$existing['id']]);
            $result['disabled']++;
        }
        // Else: already in the desired state -- no-op, and critically,
        // no UPDATE of name/description/filters_json/transform_json, so
        // any admin customization made via the Message Routing UI survives.
    }

    return $result;
}
