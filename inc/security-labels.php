<?php
/**
 * NewUI v4.0 — Security labels engine (Phase 18a, 2026-06-11).
 *
 * Spec: specs/phase-18-incident-sensitivity-2026-06/spec.md (v2)
 *
 * Public API:
 *
 *   seclabel_get(int $id): ?array
 *     Return one label row or null.
 *
 *   seclabel_get_by_code(string $code): ?array
 *     Return one label row by its machine code.
 *
 *   seclabel_get_all(): array
 *     All labels ordered by sort_order. Admin UI uses this.
 *
 *   seclabel_default(): array
 *     The system-default row (is_default=1) or fallback to
 *     lowest sort_order.
 *
 *   seclabel_resolve(int $ticketId): array
 *     The full label row that applies to this incident, with an
 *     extra '_resolved_from' key: 'incident_override', 'incident_type',
 *     'system_default', or 'fallback'.
 *
 *   seclabel_apply_override(int $ticketId, int $labelId, ?string $reason, ?int $userId): bool
 *     Set ticket.security_label_override_id + audit. Required reason
 *     when the label has audit_required_reason=1.
 *
 *   seclabel_clear_override(int $ticketId, ?int $userId): bool
 *     NULL out the override on a ticket. Reverts to type/system default.
 *
 *   seclabel_create(array $fields): int
 *   seclabel_update(int $id, array $fields): bool
 *   seclabel_delete(int $id): bool
 *     Admin CRUD. Delete refuses if is_default OR any ticket references
 *     the label OR any in_types references it.
 */

require_once __DIR__ . '/audit.php';

const SECLABEL_COLUMNS = [
    'code', 'name', 'sort_order', 'is_default',
    'badge_bg_color', 'badge_text_color',
    'eoc_show_scope', 'eoc_show_address', 'eoc_show_map_marker', 'eoc_placeholder_text',
    'routing_allow_broadcast', 'routing_allow_direct',
    'routing_send_delay_secs', 'routing_recall_window_s',
    'ics_export_show_full', 'ics_watermark_text',
    'audit_required_reason',
];

function seclabel_get(int $id): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_one(
            "SELECT * FROM `{$prefix}security_labels` WHERE id = ? LIMIT 1", [$id]);
        return $r ?: null;
    } catch (Exception $e) { return null; }
}

function seclabel_get_by_code(string $code): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_one(
            "SELECT * FROM `{$prefix}security_labels` WHERE code = ? LIMIT 1", [$code]);
        return $r ?: null;
    } catch (Exception $e) { return null; }
}

function seclabel_get_all(): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_all(
            "SELECT * FROM `{$prefix}security_labels` ORDER BY sort_order ASC, id ASC");
    } catch (Exception $e) { return []; }
}

function seclabel_default(): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_one(
            "SELECT * FROM `{$prefix}security_labels` WHERE is_default = 1 LIMIT 1");
        if ($r) return $r;
        $r = db_fetch_one(
            "SELECT * FROM `{$prefix}security_labels` ORDER BY sort_order ASC, id ASC LIMIT 1");
        return $r ?: _seclabel_synthetic_fallback();
    } catch (Exception $e) {
        return _seclabel_synthetic_fallback();
    }
}

function _seclabel_synthetic_fallback(): array {
    // Used when the security_labels table is empty/missing. Behaves
    // like the most-permissive option so the system doesn't lock
    // itself out.
    return [
        'id' => 0, 'code' => 'standard', 'name' => 'Standard',
        'sort_order' => 0, 'is_default' => 1,
        'badge_bg_color' => '#198754', 'badge_text_color' => '#ffffff',
        'eoc_show_scope' => 1, 'eoc_show_address' => 1,
        'eoc_show_map_marker' => 'full', 'eoc_placeholder_text' => null,
        'routing_allow_broadcast' => 1, 'routing_allow_direct' => 1,
        'routing_send_delay_secs' => 0, 'routing_recall_window_s' => 0,
        'ics_export_show_full' => 1, 'ics_watermark_text' => null,
        'audit_required_reason' => 0,
    ];
}

function seclabel_resolve(int $ticketId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $tk = db_fetch_one(
            "SELECT id, in_types_id, security_label_override_id
               FROM `{$prefix}ticket` WHERE id = ? LIMIT 1", [$ticketId]);
        if (!$tk) {
            $d = seclabel_default();
            $d['_resolved_from'] = 'fallback';
            return $d;
        }

        // 1. per-incident override
        if (!empty($tk['security_label_override_id'])) {
            $row = seclabel_get((int) $tk['security_label_override_id']);
            if ($row) {
                $row['_resolved_from'] = 'incident_override';
                return $row;
            }
        }

        // 2. per-incident-type default
        if (!empty($tk['in_types_id'])) {
            $typeRow = db_fetch_one(
                "SELECT default_security_label_id
                   FROM `{$prefix}in_types` WHERE id = ? LIMIT 1",
                [(int) $tk['in_types_id']]);
            if ($typeRow && !empty($typeRow['default_security_label_id'])) {
                $row = seclabel_get((int) $typeRow['default_security_label_id']);
                if ($row) {
                    $row['_resolved_from'] = 'incident_type';
                    return $row;
                }
            }
        }

        // 3. system default
        $d = seclabel_default();
        $d['_resolved_from'] = (int) ($d['id'] ?? 0) > 0 ? 'system_default' : 'fallback';
        return $d;
    } catch (Exception $e) {
        $d = seclabel_default();
        $d['_resolved_from'] = 'fallback';
        return $d;
    }
}

function seclabel_apply_override(int $ticketId, int $labelId, ?string $reason, ?int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $label = seclabel_get($labelId);
    if (!$label) return ['error' => 'unknown label'];
    if ((int) $label['audit_required_reason'] === 1) {
        if (!is_string($reason) || trim($reason) === '') {
            return ['error' => 'Reason required for ' . $label['name']];
        }
    }
    $now = date('Y-m-d H:i:s');
    try {
        db_query(
            "UPDATE `{$prefix}ticket`
                SET security_label_override_id = ?,
                    security_set_by  = ?,
                    security_set_at  = ?,
                    security_reason  = ?
              WHERE id = ?",
            [$labelId, $userId, $now, $reason, $ticketId]
        );
        audit_log('security', 'apply_override', 'ticket', $ticketId,
            "Security label set to '{$label['name']}' on incident #{$ticketId}", [
                'label_id'   => $labelId,
                'label_code' => $label['code'],
                'reason'     => $reason,
            ]);
        return ['ok' => true, 'label' => $label];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function seclabel_clear_override(int $ticketId, ?int $userId): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}ticket`
                SET security_label_override_id = NULL,
                    security_set_by  = ?,
                    security_set_at  = NOW(),
                    security_reason  = 'cleared override'
              WHERE id = ?",
            [$userId, $ticketId]
        );
        audit_log('security', 'clear_override', 'ticket', $ticketId,
            "Security label override cleared on incident #{$ticketId}");
        return true;
    } catch (Exception $e) { return false; }
}

function seclabel_create(array $fields): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$cols, $vals] = _seclabel_normalize_input($fields);
    if (empty($cols)) return 0;
    try {
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        db_query("INSERT INTO `{$prefix}security_labels` (`" . implode('`,`', $cols) . "`)
                  VALUES ({$placeholders})", $vals);
        $newId = (int) db_insert_id();
        if (!empty($fields['is_default']) && (int) $fields['is_default'] === 1) {
            _seclabel_enforce_single_default($newId);
        }
        audit_log('security', 'create_label', 'security_label', $newId,
            "Created security label '" . ($fields['name'] ?? '?') . "'");
        return $newId;
    } catch (Exception $e) { return 0; }
}

function seclabel_update(int $id, array $fields): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    [$cols, $vals] = _seclabel_normalize_input($fields);
    if (empty($cols)) return false;
    $setSql = implode(',', array_map(fn($c) => "`$c` = ?", $cols));
    $vals[] = $id;
    try {
        db_query("UPDATE `{$prefix}security_labels` SET {$setSql} WHERE id = ?", $vals);
        if (isset($fields['is_default']) && (int) $fields['is_default'] === 1) {
            _seclabel_enforce_single_default($id);
        }
        audit_log('security', 'update_label', 'security_label', $id,
            "Updated security label #{$id}", $fields);
        // Refresh the cached default-code setting
        _seclabel_refresh_default_cache();
        return true;
    } catch (Exception $e) { return false; }
}

function seclabel_delete(int $id): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $row = seclabel_get($id);
    if (!$row) return ['error' => 'not found'];
    if ((int) $row['is_default'] === 1) {
        return ['error' => 'Cannot delete the default label. Set another as default first.'];
    }
    try {
        $refTicket = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}ticket` WHERE security_label_override_id = ?", [$id]);
        $refType = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}in_types` WHERE default_security_label_id = ?", [$id]);
        if ($refTicket > 0 || $refType > 0) {
            return ['error' => "In use by {$refTicket} incident(s) and {$refType} incident type(s). Reassign first."];
        }
        db_query("DELETE FROM `{$prefix}security_labels` WHERE id = ?", [$id]);
        audit_log('security', 'delete_label', 'security_label', $id,
            "Deleted security label '" . ($row['name'] ?? '?') . "'");
        return ['ok' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function _seclabel_normalize_input(array $f): array {
    $cols = [];
    $vals = [];
    foreach (SECLABEL_COLUMNS as $c) {
        if (!array_key_exists($c, $f)) continue;
        $v = $f[$c];
        // Coerce types to match schema
        switch ($c) {
            case 'sort_order':
            case 'routing_send_delay_secs':
            case 'routing_recall_window_s':
                $v = max(0, (int) $v); break;
            case 'is_default':
            case 'eoc_show_scope':
            case 'eoc_show_address':
            case 'routing_allow_broadcast':
            case 'routing_allow_direct':
            case 'ics_export_show_full':
            case 'audit_required_reason':
                $v = ((int) $v) === 1 ? 1 : 0; break;
            case 'eoc_show_map_marker':
                if (!in_array($v, ['full','dim','hide'], true)) $v = 'full';
                break;
            case 'code':
                $v = strtolower(preg_replace('/[^a-z0-9_]/', '', (string) $v));
                if ($v === '') continue 2;
                break;
            default:
                $v = is_null($v) ? null : (string) $v;
        }
        $cols[] = $c;
        $vals[] = $v;
    }
    return [$cols, $vals];
}

function _seclabel_enforce_single_default(int $keepId): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "UPDATE `{$prefix}security_labels` SET is_default = 0 WHERE id <> ?", [$keepId]);
    } catch (Exception $e) {}
}

function _seclabel_refresh_default_cache(): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $code = (string) db_fetch_value(
            "SELECT code FROM `{$prefix}security_labels` WHERE is_default = 1 LIMIT 1");
        if ($code !== '') {
            db_query(
                "INSERT INTO `{$prefix}settings` (name, value) VALUES ('incident_default_security_label', ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$code]);
        }
    } catch (Exception $e) {}
}
