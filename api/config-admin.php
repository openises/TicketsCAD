<?php
/**
 * NewUI v4.0 API - Configuration / Admin
 *
 * Unified settings API. Routes via ?section= parameter.
 * All routes require authentication + admin level (level <= 1).
 * User management routes require super level (level == 0).
 *
 * Sections:
 *   types      - Incident types CRUD
 *   statuses   - Unit statuses CRUD
 *   facilities - Facilities CRUD
 *   settings   - System settings (key-value)
 *   users      - User accounts CRUD (super only)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/password-policy.php';
require_once __DIR__ . '/../inc/schema-heal.php';

// Suppress display_errors to keep JSON clean
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix  = $GLOBALS['db_prefix'] ?? '';
$method  = $_SERVER['REQUEST_METHOD'];
$section = $_GET['section'] ?? '';

// ── Auth: require config permission (or legacy admin level) ─────
if (!rbac_can('action.manage_config') && !is_admin()) {
    json_error('Admin access required', 403);
}

// ── CSRF on writes ──────────────────────────────────────────────
if ($method === 'POST' || $method === 'DELETE') {
    $input = ($method === 'POST')
        ? json_decode(file_get_contents('php://input'), true) ?? []
        : [];
    $token = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

// ═══════════════════════════════════════════════════════════════
//  INCIDENT TYPES
// ═══════════════════════════════════════════════════════════════
if ($section === 'types') {

    // GET — list all
    if ($method === 'GET') {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `sort`, `match_pattern`
                 FROM `{$prefix}in_types`
                 ORDER BY `sort`, `type`"
            );
        } catch (Exception $e) {
            // match_pattern column may not exist yet
            $rows = db_fetch_all(
                "SELECT `id`, `type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `sort`
                 FROM `{$prefix}in_types`
                 ORDER BY `sort`, `type`"
            );
        }
        // Phase 26A (2026-06-11), revised Phase 32 (2026-06-12) — attach
        // per-type PAR mode + cadence from par_config. Tri-state:
        //   par_mode = 'default'  → no row in par_config, fall through
        //   par_mode = 'override' → cadence_minutes = N
        //   par_mode = 'disabled' → is_disabled = 1, cadence ignored
        try {
            try {
                $cads = db_fetch_all(
                    "SELECT `in_types_id`, `cadence_minutes`, `is_disabled`
                       FROM `{$prefix}par_config`
                      WHERE `scope` = 'incident_type' AND `in_types_id` IS NOT NULL"
                );
            } catch (Exception $e) {
                // Pre-Phase-32 schema fallback.
                $cads = db_fetch_all(
                    "SELECT `in_types_id`, `cadence_minutes`
                       FROM `{$prefix}par_config`
                      WHERE `scope` = 'incident_type' AND `in_types_id` IS NOT NULL"
                );
                foreach ($cads as &$c) $c['is_disabled'] = 0;
                unset($c);
            }
            $cadMap = [];
            foreach ($cads as $c) $cadMap[(int) $c['in_types_id']] = $c;
            foreach ($rows as &$r) {
                $row = $cadMap[(int) $r['id']] ?? null;
                if ($row && (int) $row['is_disabled'] === 1) {
                    $r['par_mode']             = 'disabled';
                    $r['par_cadence_minutes']  = 0;
                } elseif ($row && (int) $row['cadence_minutes'] > 0) {
                    $r['par_mode']             = 'override';
                    $r['par_cadence_minutes']  = (int) $row['cadence_minutes'];
                } else {
                    $r['par_mode']             = 'default';
                    $r['par_cadence_minutes']  = 0;
                }
            }
            unset($r);
        } catch (Exception $e) {
            foreach ($rows as &$r) {
                $r['par_mode']            = 'default';
                $r['par_cadence_minutes'] = 0;
            }
            unset($r);
        }
        json_response(['rows' => $rows]);
    }

    // POST — create or update
    if ($method === 'POST') {
        $id          = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $typeName    = trim($input['type'] ?? '');
        $description = trim($input['description'] ?? '');
        $protocol    = trim($input['protocol'] ?? '');
        $severity    = (int) ($input['set_severity'] ?? 0);
        $group       = trim($input['group'] ?? '');
        $radius      = (int) ($input['radius'] ?? 0);
        $color       = trim($input['color'] ?? '#0d6efd');
        $sort        = (int) ($input['sort'] ?? 0);
        $pattern     = trim($input['match_pattern'] ?? '');

        if ($typeName === '') {
            json_error('Type name is required');
        }

        try {
            if ($id) {
                // Update
                $sql = "UPDATE `{$prefix}in_types` SET
                    `type` = ?, `description` = ?, `protocol` = ?, `set_severity` = ?,
                    `group` = ?, `radius` = ?, `color` = ?, `sort` = ?, `match_pattern` = ?
                    WHERE `id` = ?";
                db_query($sql, [$typeName, $description, $protocol, $severity, $group, $radius, $color, $sort, $pattern, $id]);
            } else {
                // Insert
                $sql = "INSERT INTO `{$prefix}in_types`
                    (`type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `sort`, `match_pattern`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                db_query($sql, [$typeName, $description, $protocol, $severity, $group, $radius, $color, $sort, $pattern]);
                $id = (int) db_insert_id();
            }
            audit_log('config', $id ? 'update' : 'create', 'incident_type', $id, ($id ? "Updated" : "Created") . " incident type '{$typeName}'");

            // Phase 26A (2026-06-11), revised Phase 32 (2026-06-12) — tri-state.
            // par_mode = 'default'  → DELETE the par_config row (fall through)
            // par_mode = 'override' → upsert with cadence_minutes = N, is_disabled = 0
            // par_mode = 'disabled' → upsert with is_disabled = 1 (cadence ignored)
            //
            // Backward compat: old clients that POST par_cadence_minutes
            // only (no par_mode) still work — par_mode is inferred from
            // the cadence value.
            if (isset($input['par_mode']) || isset($input['par_cadence_minutes'])) {
                $mode = isset($input['par_mode']) ? (string) $input['par_mode'] : null;
                $cad  = isset($input['par_cadence_minutes']) ? (int) $input['par_cadence_minutes'] : 0;

                // Infer mode for old-client compat if not given
                if ($mode === null) {
                    $mode = $cad > 0 ? 'override' : 'default';
                }
                if (!in_array($mode, ['default','override','disabled'], true)) {
                    $mode = 'default';
                }

                try {
                    if ($mode === 'default') {
                        // Remove the row entirely so the resolver
                        // falls through to agency/system layers.
                        db_query(
                            "DELETE FROM `{$prefix}par_config`
                              WHERE `scope` = 'incident_type' AND `in_types_id` = ?",
                            [$id]
                        );
                    } else {
                        $isDisabled = $mode === 'disabled' ? 1 : 0;
                        // When disabled, store cadence_minutes=0 — the
                        // is_disabled flag is what the resolver checks,
                        // but a sentinel 0 keeps backward compat.
                        $cadStore = $mode === 'override' ? max(1, $cad) : 0;

                        $existing = db_fetch_one(
                            "SELECT `id` FROM `{$prefix}par_config`
                              WHERE `scope` = 'incident_type' AND `in_types_id` = ? LIMIT 1",
                            [$id]
                        );
                        if ($existing) {
                            try {
                                db_query(
                                    "UPDATE `{$prefix}par_config`
                                        SET `cadence_minutes` = ?, `is_disabled` = ?, `updated_at` = NOW()
                                      WHERE `id` = ?",
                                    [$cadStore, $isDisabled, (int) $existing['id']]
                                );
                            } catch (Exception $eCol) {
                                // Pre-Phase-32 schema fallback (no is_disabled column).
                                db_query(
                                    "UPDATE `{$prefix}par_config`
                                        SET `cadence_minutes` = ?, `updated_at` = NOW()
                                      WHERE `id` = ?",
                                    [$cadStore, (int) $existing['id']]
                                );
                            }
                        } else {
                            try {
                                db_query(
                                    "INSERT INTO `{$prefix}par_config`
                                        (`scope`, `in_types_id`, `cadence_minutes`, `is_disabled`, `updated_at`)
                                     VALUES ('incident_type', ?, ?, ?, NOW())",
                                    [$id, $cadStore, $isDisabled]
                                );
                            } catch (Exception $eCol) {
                                db_query(
                                    "INSERT INTO `{$prefix}par_config`
                                        (`scope`, `in_types_id`, `cadence_minutes`, `updated_at`)
                                     VALUES ('incident_type', ?, ?, NOW())",
                                    [$id, $cadStore]
                                );
                            }
                        }
                    }
                } catch (Exception $e) {
                    // par_config may not exist on legacy installs — soft-fail
                }
            }

            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            // Retry without match_pattern if column doesn't exist
            if (strpos($e->getMessage(), 'match_pattern') !== false) {
                try {
                    if ($id) {
                        $sql = "UPDATE `{$prefix}in_types` SET
                            `type` = ?, `description` = ?, `protocol` = ?, `set_severity` = ?,
                            `group` = ?, `radius` = ?, `color` = ?, `sort` = ?
                            WHERE `id` = ?";
                        db_query($sql, [$typeName, $description, $protocol, $severity, $group, $radius, $color, $sort, $id]);
                    } else {
                        $sql = "INSERT INTO `{$prefix}in_types`
                            (`type`, `description`, `protocol`, `set_severity`, `group`, `radius`, `color`, `sort`)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        db_query($sql, [$typeName, $description, $protocol, $severity, $group, $radius, $color, $sort]);
                        $id = (int) db_insert_id();
                    }
                    json_response(['saved' => true, 'id' => $id, 'note' => 'match_pattern column not found — run ALTER TABLE']);
                } catch (Exception $e2) {
                    json_error_safe('Save failed. Check server logs.', $e2, 'in_types.save.retry');
                }
            }
            json_error_safe('Save failed. Check server logs.', $e, 'in_types.save');
        }
    }

    // DELETE
    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM `{$prefix}in_types` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'incident_type', $id, "Deleted incident type #{$id}");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error_safe('Delete failed. Check server logs.', $e, 'in_types.delete');
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  UNIT STATUSES
// ═══════════════════════════════════════════════════════════════
if ($section === 'statuses') {

    if ($method === 'GET') {
        try {
            try {
                // Phase 95 (2026-06-28): include extra_data_* columns
                $rows = db_fetch_all(
                    "SELECT `id`, `status_val`, `description`, `dispatch`, `watch`, `hide`,
                            `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`,
                            `incident_action`, `resets_par`,
                            `extra_data_type`, `extra_data_required`,
                            `extra_data_label`, `extra_data_target`
                     FROM `{$prefix}un_status`
                     ORDER BY `sort`, `id`"
                );
            } catch (Exception $e95) {
                // Phase 95 columns missing — drop them and retry
                try {
                    $rows = db_fetch_all(
                        "SELECT `id`, `status_val`, `description`, `dispatch`, `watch`, `hide`,
                                `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`,
                                `incident_action`, `resets_par`
                         FROM `{$prefix}un_status`
                         ORDER BY `sort`, `id`"
                    );
                } catch (Exception $eResetsPar) {
                    // Phase 31 column also missing — drop it and retry
                    $rows = db_fetch_all(
                        "SELECT `id`, `status_val`, `description`, `dispatch`, `watch`, `hide`,
                                `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`,
                                `incident_action`
                         FROM `{$prefix}un_status`
                         ORDER BY `sort`, `id`"
                    );
                    foreach ($rows as &$r) $r['resets_par'] = 0;
                    unset($r);
                }
                // Synthesize Phase 95 defaults so the admin UI renders
                foreach ($rows as &$r) {
                    $r['extra_data_type']     = 'none';
                    $r['extra_data_required'] = 0;
                    $r['extra_data_label']    = null;
                    $r['extra_data_target']   = 'action_log';
                }
                unset($r);
            }
        } catch (Exception $e) {
            // Fallback for older schema
            $rows = db_fetch_all("SELECT `id`, `description` FROM `{$prefix}un_status` ORDER BY `id`");
        }
        // GH #20 — merge the per-status bed-delivery flag (column added by
        // sql/run_gh20_bed_delivery.php; guarded so pre-migration installs
        // just see 0).
        try {
            $bd = [];
            foreach (db_fetch_all("SELECT `id`, `bed_delivery` FROM `{$prefix}un_status`") as $b) {
                $bd[(int) $b['id']] = (int) $b['bed_delivery'];
            }
            foreach ($rows as &$r) { $r['bed_delivery'] = $bd[(int) $r['id']] ?? 0; }
            unset($r);
        } catch (Exception $eBd) {
            foreach ($rows as &$r) { $r['bed_delivery'] = 0; }
            unset($r);
        }
        // GH #66 — merge the per-status hide-from-boards flag (column added
        // by sql/run_gh66_hide_from_board.php; guarded the same way).
        try {
            $hb = [];
            foreach (db_fetch_all("SELECT `id`, `hide_from_board` FROM `{$prefix}un_status`") as $b) {
                $hb[(int) $b['id']] = (int) $b['hide_from_board'];
            }
            foreach ($rows as &$r) { $r['hide_from_board'] = $hb[(int) $r['id']] ?? 0; }
            unset($r);
        } catch (Exception $eHb) {
            foreach ($rows as &$r) { $r['hide_from_board'] = 0; }
            unset($r);
        }
        // GH #68 round 2 — merge the explicit units-filter bucket (column
        // added by sql/run_gh68_units_filter_class.php; '' = auto/legacy).
        try {
            $uf = [];
            foreach (db_fetch_all("SELECT `id`, `units_filter` FROM `{$prefix}un_status`") as $b) {
                $uf[(int) $b['id']] = (string) ($b['units_filter'] ?? '');
            }
            foreach ($rows as &$r) { $r['units_filter'] = $uf[(int) $r['id']] ?? ''; }
            unset($r);
        } catch (Exception $eUf) {
            foreach ($rows as &$r) { $r['units_filter'] = ''; }
            unset($r);
        }
        json_response(['rows' => $rows]);
    }

    if ($method === 'POST') {
        $id        = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $statusVal = trim($input['status_val'] ?? '');
        $desc      = trim($input['description'] ?? '');
        $group     = trim($input['group'] ?? '');
        $sort      = (int) ($input['sort'] ?? 0);
        $bgColor   = trim($input['bg_color'] ?? '');
        $txtColor  = trim($input['text_color'] ?? '');
        $dispatch  = (int) ($input['dispatch'] ?? 0);
        $watch     = (int) ($input['watch'] ?? 0);
        $hide      = trim($input['hide'] ?? 'n');
        $exclReset = trim($input['excl_from_reset'] ?? 'n');

        // Phase 25 (2026-06-11) — allowlist the incident_action enum so
        // dispatcher-side dropdowns can only set known assigns columns.
        $allowedActions = ['', 'dispatched', 'responding', 'on_scene', 'clear'];
        $incidentAction = trim($input['incident_action'] ?? '');
        if (!in_array($incidentAction, $allowedActions, true)) $incidentAction = '';
        // Phase 31 (2026-06-12) — when units enter this status, reset
        // their per-unit PAR cadence timer. Coerce to 0/1.
        $resetsPar = !empty($input['resets_par']) ? 1 : 0;

        // Phase 95 (2026-06-28) — configurable extra-data per status.
        $allowedExtraTypes   = ['none', 'facility', 'mileage', 'location', 'note', 'numeric'];
        $allowedExtraTargets = ['incident', 'unit', 'action_log'];
        $extraDataType = trim($input['extra_data_type'] ?? 'none');
        if (!in_array($extraDataType, $allowedExtraTypes, true)) $extraDataType = 'none';
        $extraDataReq   = !empty($input['extra_data_required']) ? 1 : 0;
        $extraDataLabel = trim($input['extra_data_label'] ?? '');
        if ($extraDataLabel === '') $extraDataLabel = null;
        $extraDataTarget = trim($input['extra_data_target'] ?? 'action_log');
        if (!in_array($extraDataTarget, $allowedExtraTargets, true)) $extraDataTarget = 'action_log';

        if ($desc === '') json_error('Status name is required');

        // Default status_val from description if not provided
        if ($statusVal === '') {
            $statusVal = substr($desc, 0, 20);
        }

        try {
            if ($id) {
                // Phase 95: UPDATE with extra_data_* columns; fallback
                // to legacy column set on pre-Phase-95 installs.
                try {
                    $sql = "UPDATE `{$prefix}un_status` SET
                        `status_val` = ?, `description` = ?, `group` = ?, `sort` = ?,
                        `bg_color` = ?, `text_color` = ?,
                        `dispatch` = ?, `watch` = ?, `hide` = ?, `excl_from_reset` = ?,
                        `incident_action` = ?, `resets_par` = ?,
                        `extra_data_type` = ?, `extra_data_required` = ?,
                        `extra_data_label` = ?, `extra_data_target` = ?
                        WHERE `id` = ?";
                    db_query($sql, [$statusVal, $desc, $group, $sort, $bgColor, $txtColor,
                                    $dispatch, $watch, $hide, $exclReset, $incidentAction, $resetsPar,
                                    $extraDataType, $extraDataReq, $extraDataLabel, $extraDataTarget,
                                    $id]);
                } catch (Exception $e95) {
                    $sql = "UPDATE `{$prefix}un_status` SET
                        `status_val` = ?, `description` = ?, `group` = ?, `sort` = ?,
                        `bg_color` = ?, `text_color` = ?,
                        `dispatch` = ?, `watch` = ?, `hide` = ?, `excl_from_reset` = ?,
                        `incident_action` = ?, `resets_par` = ?
                        WHERE `id` = ?";
                    db_query($sql, [$statusVal, $desc, $group, $sort, $bgColor, $txtColor, $dispatch, $watch, $hide, $exclReset, $incidentAction, $resetsPar, $id]);
                }
            } else {
                try {
                    $sql = "INSERT INTO `{$prefix}un_status`
                        (`status_val`, `description`, `group`, `sort`, `bg_color`, `text_color`,
                         `dispatch`, `watch`, `hide`, `excl_from_reset`, `incident_action`, `resets_par`,
                         `extra_data_type`, `extra_data_required`, `extra_data_label`, `extra_data_target`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    db_query($sql, [$statusVal, $desc, $group, $sort, $bgColor, $txtColor,
                                    $dispatch, $watch, $hide, $exclReset, $incidentAction, $resetsPar,
                                    $extraDataType, $extraDataReq, $extraDataLabel, $extraDataTarget]);
                } catch (Exception $e95) {
                $sql = "INSERT INTO `{$prefix}un_status`
                    (`status_val`, `description`, `group`, `sort`, `bg_color`, `text_color`,
                     `dispatch`, `watch`, `hide`, `excl_from_reset`, `incident_action`, `resets_par`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                db_query($sql, [$statusVal, $desc, $group, $sort, $bgColor, $txtColor, $dispatch, $watch, $hide, $exclReset, $incidentAction, $resetsPar]);
                }
                $id = (int) db_insert_id();
            }
            // GH #20 — persist the bed-delivery flag via a separate guarded
            // UPDATE so the layered legacy-schema fallbacks above never have
            // to know about the new column.
            if ($id && array_key_exists('bed_delivery', $input)) {
                try {
                    db_query(
                        "UPDATE `{$prefix}un_status` SET `bed_delivery` = ? WHERE `id` = ?",
                        [!empty($input['bed_delivery']) ? 1 : 0, $id]
                    );
                } catch (Exception $eBd) { /* pre-migration schema — ignore */ }
            }
            // GH #66 — hide-from-boards flag, same guarded-UPDATE pattern.
            if ($id && array_key_exists('hide_from_board', $input)) {
                try {
                    db_query(
                        "UPDATE `{$prefix}un_status` SET `hide_from_board` = ? WHERE `id` = ?",
                        [!empty($input['hide_from_board']) ? 1 : 0, $id]
                    );
                } catch (Exception $eHb) { /* pre-migration schema — ignore */ }
            }
            // GH #68 round 2 — explicit units-filter bucket; whitelist the
            // enum values, '' clears back to NULL (auto/legacy matching).
            if ($id && array_key_exists('units_filter', $input)) {
                $ufVal = in_array($input['units_filter'], ['available', 'in_service', 'unavailable'], true)
                    ? $input['units_filter'] : null;
                try {
                    db_query(
                        "UPDATE `{$prefix}un_status` SET `units_filter` = ? WHERE `id` = ?",
                        [$ufVal, $id]
                    );
                } catch (Exception $eUf) { /* pre-migration schema — ignore */ }
            }
            audit_log('config', $id ? 'update' : 'create', 'unit_status', $id, ($id ? "Updated" : "Created") . " unit status '{$desc}'");
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            // Pre-Phase-31 schema fallback — drop resets_par and retry.
            try {
                if ($id) {
                    $sql = "UPDATE `{$prefix}un_status` SET
                        `status_val` = ?, `description` = ?, `group` = ?, `sort` = ?,
                        `bg_color` = ?, `text_color` = ?,
                        `dispatch` = ?, `watch` = ?, `hide` = ?, `excl_from_reset` = ?,
                        `incident_action` = ?
                        WHERE `id` = ?";
                    db_query($sql, [$statusVal, $desc, $group, $sort, $bgColor, $txtColor, $dispatch, $watch, $hide, $exclReset, $incidentAction, $id]);
                } else {
                    $sql = "INSERT INTO `{$prefix}un_status`
                        (`status_val`, `description`, `group`, `sort`, `bg_color`, `text_color`,
                         `dispatch`, `watch`, `hide`, `excl_from_reset`, `incident_action`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    db_query($sql, [$statusVal, $desc, $group, $sort, $bgColor, $txtColor, $dispatch, $watch, $hide, $exclReset, $incidentAction]);
                    $id = (int) db_insert_id();
                }
                audit_log('config', $id ? 'update' : 'create', 'unit_status', $id, ($id ? "Updated" : "Created") . " unit status '{$desc}'");
                json_response(['saved' => true, 'id' => $id, 'note' => 'Saved without resets_par (run Phase 31 migration)']);
            } catch (Exception $e2) {
                // Last-resort: minimal update
                try {
                    if ($id) {
                        db_query("UPDATE `{$prefix}un_status` SET `description` = ? WHERE `id` = ?", [$desc, $id]);
                    } else {
                        db_query("INSERT INTO `{$prefix}un_status` (`description`) VALUES (?)", [$desc]);
                        $id = (int) db_insert_id();
                    }
                    json_response(['saved' => true, 'id' => $id, 'note' => 'Saved with limited fields']);
                } catch (Exception $e3) {
                    json_error_safe('Save failed. Check server logs.', $e3, 'un_status.save.minimal');
                }
            }
        }
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        // Protect the default Available status (id=1)
        if ($id === 1) {
            json_error('Cannot delete the default Available status (id=1)');
        }
        try {
            db_query("DELETE FROM `{$prefix}un_status` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'unit_status', $id, "Deleted unit status #{$id}");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error_safe('Delete failed. Check server logs.', $e, 'un_status.delete');
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  SIGNALS / CODES (hints table)
// ═══════════════════════════════════════════════════════════════
if ($section === 'signals') {

    if ($method === 'GET') {
        try {
            $rows = db_fetch_all("SELECT `id`, `tag`, `hint` FROM `{$prefix}hints` ORDER BY `tag`");
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['rows' => $rows]);
    }

    if ($method === 'POST') {
        $id   = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $tag  = trim($input['tag'] ?? '');
        $hint = trim($input['hint'] ?? '');

        if ($tag === '') json_error('Code/tag is required');
        if ($hint === '') json_error('Description is required');

        try {
            if ($id) {
                db_query("UPDATE `{$prefix}hints` SET `tag` = ?, `hint` = ? WHERE `id` = ?", [$tag, $hint, $id]);
            } else {
                db_query("INSERT INTO `{$prefix}hints` (`tag`, `hint`) VALUES (?, ?)", [$tag, $hint]);
                $id = (int) db_insert_id();
            }
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'hints.save');
        }
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM `{$prefix}hints` WHERE `id` = ?", [$id]);
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error_safe('Delete failed. Check server logs.', $e, 'hints.delete');
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  FACILITY STATUSES (issue #29 followup)
// ═══════════════════════════════════════════════════════════════
if ($section === 'facility_statuses') {
    if ($method === 'GET') {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `status_val`, `description`, `group`,
                        `status_available`, `status_unavailable`,
                        `sort`, `bg_color`, `text_color`
                   FROM `{$prefix}fac_status`
                  ORDER BY `sort`, `status_val`"
            );
        } catch (Exception $e) { $rows = []; }
        json_response(['rows' => $rows]);
    }
    if ($method === 'POST') {
        $id       = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $val      = trim((string) ($input['status_val'] ?? ''));
        $desc     = trim((string) ($input['description'] ?? ''));
        $group    = trim((string) ($input['group'] ?? ''));
        $avail    = !empty($input['status_available'])   ? 1 : 0;
        $unavail  = !empty($input['status_unavailable']) ? 1 : 0;
        $sort     = (int) ($input['sort'] ?? 0);
        $bg       = trim((string) ($input['bg_color'] ?? ''));
        $text     = trim((string) ($input['text_color'] ?? ''));

        if ($val === '') json_error('Status is required');

        try {
            if ($id) {
                db_query(
                    "UPDATE `{$prefix}fac_status`
                        SET `status_val` = ?, `description` = ?, `group` = ?,
                            `status_available` = ?, `status_unavailable` = ?,
                            `sort` = ?, `bg_color` = ?, `text_color` = ?
                      WHERE `id` = ?",
                    [$val, $desc, $group, $avail, $unavail, $sort, $bg, $text, $id]
                );
                audit_log('config', 'update', 'facility_status', $id, "Updated fac_status '{$val}'");
            } else {
                db_query(
                    "INSERT INTO `{$prefix}fac_status`
                       (`status_val`, `description`, `group`, `status_available`,
                        `status_unavailable`, `sort`, `bg_color`, `text_color`,
                        `_by`, `_on`, `_from`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
                    [$val, $desc, $group, $avail, $unavail, $sort, $bg, $text,
                     $current_user_id, ($_SERVER['REMOTE_ADDR'] ?? '')]
                );
                $id = (int) db_insert_id();
                audit_log('config', 'create', 'facility_status', $id, "Created fac_status '{$val}'");
            }
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            error_log('facility_statuses save failed: ' . $e->getMessage());
            json_error('Save failed.', 500);
        }
    }
    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM `{$prefix}fac_status` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'facility_status', $id, "Deleted fac_status #{$id}");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            error_log('facility_statuses delete failed: ' . $e->getMessage());
            json_error('Delete failed.', 500);
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  SIGNAL CODES  (issue #31)
// ═══════════════════════════════════════════════════════════════
// CRUD for the `signals` table which drives the new-incident form's
// Signal dropdown. Distinct from the `section=signals` endpoint above
// (that one edits the `hints` table for Field Help Text — the naming
// is legacy and confusing).
if ($section === 'signal_codes') {
    if ($method === 'GET') {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `code`, `description`, `sort_order`, `hide`
                   FROM `{$prefix}signals`
                  ORDER BY `sort_order`, `code`"
            );
        } catch (Exception $e) {
            // Table doesn't exist yet — treat as empty list, admin
            // can run sql/run_signals_table.php to bootstrap.
            $rows = [];
        }
        json_response(['rows' => $rows]);
    }

    if ($method === 'POST') {
        $id   = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $code = trim((string) ($input['code'] ?? ''));
        $desc = trim((string) ($input['description'] ?? ''));
        $sort = (int) ($input['sort_order'] ?? 0);
        $hide = ($input['hide'] ?? 'n') === 'y' ? 'y' : 'n';

        if ($code === '') json_error('Code is required');

        try {
            if ($id) {
                db_query(
                    "UPDATE `{$prefix}signals`
                        SET `code` = ?, `description` = ?, `sort_order` = ?, `hide` = ?
                      WHERE `id` = ?",
                    [$code, $desc, $sort, $hide, $id]
                );
                audit_log('config', 'update', 'signal', $id, "Updated signal code '{$code}'");
            } else {
                db_query(
                    "INSERT INTO `{$prefix}signals` (`code`, `description`, `sort_order`, `hide`)
                     VALUES (?, ?, ?, ?)",
                    [$code, $desc, $sort, $hide]
                );
                $id = (int) db_insert_id();
                audit_log('config', 'create', 'signal', $id, "Created signal code '{$code}'");
            }
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            // Don't leak driver messages (M4 in code review).
            error_log('signal_codes save failed: ' . $e->getMessage());
            json_error('Save failed. Check that the code is unique.', 500);
        }
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM `{$prefix}signals` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'signal', $id, "Deleted signal code #{$id}");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            error_log('signal_codes delete failed: ' . $e->getMessage());
            json_error('Delete failed.', 500);
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  FACILITIES
// ═══════════════════════════════════════════════════════════════
if ($section === 'facilities') {

    if ($method === 'GET') {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `name`, `type`, `description`, `lat`, `lng`, `contact`, `hide`
                 FROM `{$prefix}facilities`
                 ORDER BY `name`"
            );
        } catch (Exception $e) {
            // Fallback without contact/hide if columns don't exist
            $rows = db_fetch_all(
                "SELECT `id`, `name`, `type`, `description`, `lat`, `lng`
                 FROM `{$prefix}facilities`
                 ORDER BY `name`"
            );
        }
        json_response(['rows' => $rows]);
    }

    if ($method === 'POST') {
        $id      = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $name    = trim($input['name'] ?? '');
        $type    = (int) ($input['type'] ?? 0);
        $desc    = trim($input['description'] ?? '');
        $lat     = trim($input['lat'] ?? '');
        $lng     = trim($input['lng'] ?? '');
        $contact = trim($input['contact'] ?? '');
        $hide    = (int) ($input['hide'] ?? 0);

        if ($name === '') json_error('Facility name is required');

        // Phase 102 (a beta tester beta 2026-07-01) — old installs may lack
        // `contact` and/or `hide` on the facilities table. Filter the
        // column list to what actually exists, then run INSERT/UPDATE
        // with the surviving cols. Also heal legacy audit-column
        // defaults on a first-failure retry (same class as fac_types).
        try {
            $tbl  = $prefix . 'facilities';
            $cols = ['name', 'type', 'description', 'lat', 'lng', 'contact', 'hide'];
            $vals = [$name,  $type,  $desc,         $lat,  $lng,  $contact,  $hide];
            [$cols, $vals] = present_cols_only($tbl, $cols, $vals);
            if (empty($cols)) json_error('facilities table has no matching columns', 500);

            if ($id) {
                $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", $cols));
                $sql = "UPDATE `{$tbl}` SET {$set} WHERE `id` = ?";
                $params = array_merge($vals, [$id]);
                try {
                    db_query($sql, $params);
                } catch (Exception $e) {
                    heal_legacy_defaults($tbl);
                    db_query($sql, $params);
                }
            } else {
                $colList = '`' . implode('`, `', $cols) . '`';
                $phList  = rtrim(str_repeat('?,', count($cols)), ',');
                $sql = "INSERT INTO `{$tbl}` ({$colList}) VALUES ({$phList})";
                try {
                    db_query($sql, $vals);
                } catch (Exception $e) {
                    heal_legacy_defaults($tbl);
                    db_query($sql, $vals);
                }
                $id = (int) db_insert_id();
            }
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'facilities.save');
        }
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM `{$prefix}facilities` WHERE `id` = ?", [$id]);
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error_safe('Delete failed. Check server logs.', $e, 'facilities.delete');
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  SYSTEM SETTINGS (key-value pairs in `settings` table)
// ═══════════════════════════════════════════════════════════════
if ($section === 'settings') {

    if ($method === 'GET') {
        try {
            $rows = db_fetch_all("SELECT `name`, `value` FROM `{$prefix}settings`");
            // Secret settings (API tokens, passwords, webhooks) must NEVER be
            // sent to the browser. Instead of the value we return a companion
            // `<name>_set` boolean so the UI can show "stored / not set" and the
            // save path keeps the stored value when the field is left blank.
            // (Same contract as push_vapid_private_set.) See inc/settings-secrets.php.
            require_once __DIR__ . '/../inc/settings-secrets.php';
            $map = [];
            foreach ($rows as $row) {
                $name = $row['name'];
                if (is_secret_setting_key($name)) {
                    $map[$name . '_set'] = ($row['value'] !== '' && $row['value'] !== null);
                } else {
                    $map[$name] = $row['value'];
                }
            }
            json_response(['settings' => $map]);
        } catch (Exception $e) {
            json_response(['settings' => []]);
        }
    }

    if ($method === 'POST') {
        $pairs = $input['settings'] ?? [];
        if (empty($pairs) || !is_array($pairs)) {
            json_error('No settings provided');
        }

        $saved = 0;
        foreach ($pairs as $key => $value) {
            // Allow dots in keys (e.g. rbac.require_separate_approver)
            // but still reject anything that could be SQL-funky.
            $key = preg_replace('/[^a-zA-Z0-9_.]/', '', $key);
            if ($key === '') continue;

            // 2026-06-11 — type-safe validation for known settings.
            // area_timezone must be a valid IANA zone; reject silently
            // rather than persisting garbage that would break boot.
            if ($key === 'area_timezone') {
                if (!in_array($value, DateTimeZone::listIdentifiers(), true)) {
                    json_error("Invalid timezone: '{$value}'", 400);
                }
            }

            // Phase 118 — list page size must be a positive integer (any value
            // the admin wants). Reject garbage; normalize to a clean int string.
            if ($key === 'page_size') {
                $iv = (int) $value;
                if ($iv < 1) {
                    json_error('Page size must be a positive integer.', 400);
                }
                $value = (string) $iv;
            }

            try {
                // Upsert via ON DUPLICATE KEY UPDATE (requires unique index on name)
                db_query(
                    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    [$key, $value]
                );
                $saved++;
            } catch (Exception $e) {
                // Skip individual failures
            }
        }
        if ($saved > 0) {
            audit_log('config', 'update', 'settings', null, "Updated {$saved} system setting(s)", [
                'keys' => array_keys($pairs)
            ]);
        }
        json_response(['saved' => $saved]);
    }
}

// ═══════════════════════════════════════════════════════════════
//  ROLES (Phase 11 — read-only list for the User Accounts dropdown)
// ═══════════════════════════════════════════════════════════════
//
// GET /api/config-admin.php?section=roles
//   Returns the live list of RBAC roles so the User Accounts form can
//   populate its "Role & Permissions set" dropdown. Includes is_system
//   so the UI can disable Delete on the 6 built-ins, and legacy_level
//   so the form can show "(legacy level N)" hints where applicable.
//
// Admin-level access (level <= 1). This endpoint is read-only;
// editing roles still happens via api/rbac.php.
if ($section === 'roles') {

    if (!is_admin()) {
        json_error('Admin access required', 403);
    }

    if ($method === 'GET') {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `name`, `description`,
                        `is_super`, `is_system`, `legacy_level`,
                        `sort_order`, `is_default`
                 FROM `{$prefix}roles`
                 ORDER BY `sort_order`, `name`"
            );
        } catch (Exception $e) {
            // Pre-Phase-11 schema (no legacy_level / is_system) — fall
            // back to the legacy-shaped query so the UI still works.
            try {
                $rows = db_fetch_all(
                    "SELECT `id`, `name`, `description`,
                            `is_super`, `sort_order`, `is_default`,
                            NULL AS `legacy_level`, 0 AS `is_system`
                     FROM `{$prefix}roles`
                     ORDER BY `sort_order`, `name`"
                );
            } catch (Exception $e2) {
                $rows = [];
            }
        }
        json_response(['roles' => $rows]);
    }

    json_error('Method not allowed', 405);
}

// ═══════════════════════════════════════════════════════════════
//  USER ACCOUNTS (Super only — level == 0)
// ═══════════════════════════════════════════════════════════════
if ($section === 'users') {

    // QA #4 — user-account CRUD must require the DISTINCT action.manage_users
    // permission, not is_admin(). is_admin() is satisfied by
    // action.manage_config (inc/rbac.php), so a custom config-manager role
    // could create/edit/delete user accounts — the exact privilege boundary
    // this section is meant to hold. rbac_can('action.manage_users') keeps
    // Super Admin (who holds every permission) in while excluding a
    // config-only manager.
    if (!function_exists('rbac_can') || !rbac_can('action.manage_users')) {
        json_error('User management access required (action.manage_users)', 403);
    }

    if ($method === 'GET') {
        // Phase 9 (2026-06-08): include must_change_password in the SELECT
        // so the user-edit form can reflect the current value when an admin
        // opens an existing user.
        //
        // Phase 11 (2026-06-11): also include the user's primary active
        // RBAC role (id + name) so the User Accounts form can display
        // "Role & Permissions set" instead of the legacy Level field.
        // Sub-select picks the most recently-granted active grant.
        // Falls through to progressively simpler queries if joins fail
        // (pre-RBAC-v2 installs etc.).
        try {
            $rows = db_fetch_all(
                "SELECT u.`id`, u.`user`, u.`can_login`, u.`member`,
                        u.`must_change_password`, u.`login` AS `last_login`,
                        u.`home_org_id`,
                        (SELECT COUNT(*) FROM `{$prefix}user_tfa` t WHERE t.`user_id` = u.id) AS `tfa_enrolled`,
                        CONCAT(m.`first_name`, ' ', m.`last_name`) AS `member_name`,
                        m.`callsign` AS `member_callsign`,
                        (SELECT ur.role_id
                           FROM `{$prefix}user_roles` ur
                          WHERE ur.user_id = u.id
                            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                          ORDER BY ur.granted_at DESC, ur.id DESC
                          LIMIT 1) AS `role_id`,
                        (SELECT r.name
                           FROM `{$prefix}user_roles` ur
                           JOIN `{$prefix}roles` r ON r.id = ur.role_id
                          WHERE ur.user_id = u.id
                            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                          ORDER BY ur.granted_at DESC, ur.id DESC
                          LIMIT 1) AS `role_name`,
                        (SELECT ur.org_id
                           FROM `{$prefix}user_roles` ur
                          WHERE ur.user_id = u.id
                            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                          ORDER BY ur.granted_at DESC, ur.id DESC
                          LIMIT 1) AS `role_org_id`,
                        (SELECT o.name
                           FROM `{$prefix}user_roles` ur
                           JOIN `{$prefix}organizations` o ON o.id = ur.org_id
                          WHERE ur.user_id = u.id
                            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                          ORDER BY ur.granted_at DESC, ur.id DESC
                          LIMIT 1) AS `role_org_name`
                 FROM `{$prefix}user` u
                 LEFT JOIN `{$prefix}member` m ON u.`member` = m.`id`
                 ORDER BY u.`user`"
            );
        } catch (Exception $e) {
            try {
                $rows = db_fetch_all(
                    "SELECT u.`id`, u.`user`, u.`can_login`, u.`member`,
                            CONCAT(m.`first_name`, ' ', m.`last_name`) AS `member_name`,
                            m.`callsign` AS `member_callsign`
                     FROM `{$prefix}user` u
                     LEFT JOIN `{$prefix}member` m ON u.`member` = m.`id`
                     ORDER BY u.`user`"
                );
            } catch (Exception $e2) {
                try {
                    // member column may not exist
                    $rows = db_fetch_all(
                        "SELECT `id`, `user`, `can_login` FROM `{$prefix}user` ORDER BY `user`"
                    );
                } catch (Exception $e3) {
                    $rows = db_fetch_all(
                        "SELECT `id`, `user` FROM `{$prefix}user` ORDER BY `user`"
                    );
                }
            }
        }

        // Also fetch all members for the link dropdown.
        // org_count carries the number of organizations the member belongs to
        // so the user-account form can warn when linking to a member with no
        // org (impacts the data the user sees — see docs/ACCESS-CHAIN.md).
        $members = [];
        try {
            $members = db_fetch_all(
                "SELECT m.`id`, m.`first_name`, m.`last_name`, m.`callsign`,
                        COALESCE(orgs.cnt, 0) AS org_count
                 FROM `{$prefix}member` m
                 LEFT JOIN (
                     SELECT member_id, COUNT(*) AS cnt
                     FROM `{$prefix}member_organizations`
                     WHERE status = 'active'
                     GROUP BY member_id
                 ) orgs ON orgs.member_id = m.id
                 WHERE m.`deleted_at` IS NULL
                 ORDER BY m.`last_name`, m.`first_name`"
            );
        } catch (Exception $e) {
            // member_organizations may not exist on older installs — fall back
            try {
                $members = db_fetch_all(
                    "SELECT `id`, `first_name`, `last_name`, `callsign`, 0 AS org_count
                     FROM `{$prefix}member`
                     WHERE `deleted_at` IS NULL
                     ORDER BY `last_name`, `first_name`"
                );
            } catch (Exception $e2) {
                try {
                    $members = db_fetch_all(
                        "SELECT `id`, `first_name`, `last_name`, `callsign`, 0 AS org_count
                         FROM `{$prefix}member`
                         ORDER BY `last_name`, `first_name`"
                    );
                } catch (Exception $e3) { /* ignore */ }
            }
        }

        json_response(['rows' => $rows, 'members' => $members]);
    }

    if ($method === 'POST') {
        $id       = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $username = trim($input['user'] ?? '');
        $password = $input['password'] ?? '';

        if ($username === '') json_error('Username is required');

        // Phase 11 (2026-06-11): role_id is the canonical user identity.
        // Legacy callers (upgrade tools, older scripts) may still POST
        // `level` directly — accept both, but prefer role_id when sent.
        //
        // Resolution order:
        //   1. If role_id present: look up roles.legacy_level, derive
        //      user.level from it.
        //      Phase 11d (2026-06-11): the fallback for custom admin-
        //      created roles with NO legacy mapping is now 3
        //      (Read-Only equivalent) instead of 4. The earlier
        //      fallback of 4 (Field Unit equivalent) triggered the
        //      legacy "send to mobile.php" redirect, surprising
        //      everyone on a custom role (caught by Eric with the
        //      "Internal Auditor" role).
        //   2. Else if level present: use it verbatim. The post-create
        //      auto-grant (below) maps level → role via levelToRole.
        //   3. Else: default level=2 (matches pre-Phase-11 behavior).
        $explicitRoleId = isset($input['role_id']) && $input['role_id'] !== ''
            ? (int) $input['role_id'] : null;
        $lvl = null;
        $roleLegacyLevel = null;
        if ($explicitRoleId !== null && $explicitRoleId > 0) {
            try {
                $roleRow = db_fetch_one(
                    "SELECT id, legacy_level FROM `{$prefix}roles` WHERE id = ? LIMIT 1",
                    [$explicitRoleId]
                );
                if (!$roleRow) {
                    json_error('Unknown role_id', 400);
                }
                $roleLegacyLevel = $roleRow['legacy_level']; // may be NULL for custom roles
                $lvl = $roleLegacyLevel !== null ? (int) $roleLegacyLevel : 3;
            } catch (Exception $e) {
                // Pre-Phase-11 schema — fall back to legacy level handling.
                $lvl = (int) ($input['level'] ?? 3);
            }
        } else {
            $lvl = (int) ($input['level'] ?? 2);
        }

        $canLogin = isset($input['can_login']) ? (int) $input['can_login'] : 1;
        $memberId = isset($input['member']) ? (int) $input['member'] : 0;
        // Phase 99j-2 (Billy beta 2026-06-29) — org scoping inputs.
        // home_org_id = user's primary org affiliation (user.home_org_id)
        // role_org_id = optional org-scope on the chosen role grant
        //               (empty -> scope_kind='global' as before;
        //                non-empty -> scope_kind='org' + that org_id)
        $homeOrgId = (isset($input['home_org_id']) && $input['home_org_id'] !== '')
            ? (int) $input['home_org_id'] : null;
        $roleOrgId = (isset($input['role_org_id']) && $input['role_org_id'] !== '')
            ? (int) $input['role_org_id'] : null;

        // Phase 9 (2026-06-08): per-user force-password-change flag.
        //
        // Logic:
        //   - If $input['must_change_password'] is present, use it verbatim.
        //     (UI always sends 0 or 1; the explicit value is the admin's
        //      choice.)
        //   - If absent on CREATE, default from the system setting
        //     'force_pw_change_for_new_users' (1 = ON in fresh installs).
        //   - If absent on UPDATE, leave the column untouched (existing
        //     scripts that POST a partial body don't accidentally clear it).
        $forceFlagSent = array_key_exists('must_change_password', $input);
        $forceChangePw = null;
        if ($forceFlagSent) {
            $forceChangePw = ((int) $input['must_change_password']) === 1 ? 1 : 0;
        } elseif (!$id) {
            // Create with no explicit value → inherit system default.
            try {
                $sysDefault = db_fetch_value(
                    "SELECT `value` FROM `{$prefix}settings`
                     WHERE `name` = 'force_pw_change_for_new_users' LIMIT 1"
                );
                $forceChangePw = ((int) $sysDefault) === 1 ? 1 : 0;
            } catch (Exception $e) {
                // Settings table or row missing — default to safe (ON).
                $forceChangePw = 1;
            }
        }
        // $forceChangePw remains null on UPDATE-without-explicit-value;
        // we skip the UPDATE for that column in that case.

        try {
            if ($id) {
                // Phase 12 (2026-06-11): no longer write user.level on
                // user-update — RBAC role grants are the source of truth.
                // The column is left intact (still readable by the
                // migration tooling that translates legacy installs).
                db_query("UPDATE `{$prefix}user` SET `user` = ? WHERE `id` = ?", [$username, $id]);
                // Update can_login flag
                try {
                    db_query("UPDATE `{$prefix}user` SET `can_login` = ? WHERE `id` = ?", [$canLogin, $id]);
                } catch (Exception $e2) { /* column may not exist */ }
                // Update member link
                try {
                    db_query("UPDATE `{$prefix}user` SET `member` = ? WHERE `id` = ?",
                        [$memberId > 0 ? $memberId : null, $id]);
                    // Phase 99y (Eric beta 2026-06-30) — keep the back-
                    // reference side consistent. If any OTHER member row
                    // still claims member.user_id = this user, clear it.
                    // Without this cleanup, stale back-references survive
                    // re-linking and cause the OR-join resolvers in
                    // api/personal-unit.php / inc/personnel-units.php /
                    // api/owntracks-config.php to potentially return the
                    // wrong member. Then also set member.user_id on the
                    // newly-linked member so both sides agree.
                    try {
                        if ($memberId > 0) {
                            db_query(
                                "UPDATE `{$prefix}member` SET `user_id` = NULL
                                  WHERE `user_id` = ? AND `id` != ?",
                                [$id, $memberId]
                            );
                            db_query(
                                "UPDATE `{$prefix}member` SET `user_id` = ?
                                  WHERE `id` = ?",
                                [$id, $memberId]
                            );
                        } else {
                            // Admin un-linked the user — clear any stale
                            // back-references pointing to them.
                            db_query(
                                "UPDATE `{$prefix}member` SET `user_id` = NULL WHERE `user_id` = ?",
                                [$id]
                            );
                        }
                    } catch (Exception $eBack) {
                        // member.user_id column may not exist on very old
                        // installs — non-fatal; the forward link still works.
                        error_log('[config-admin user-member sync] ' . $eBack->getMessage());
                    }
                } catch (Exception $e2) { /* member column may not exist */ }
                // Phase 99j-2: update home_org_id when the form sent
                // a value. NULL/empty value leaves the column unchanged
                // (so existing edits don't clobber the home org by
                // accident if the field wasn't in the payload).
                if ($homeOrgId !== null) {
                    try {
                        db_query("UPDATE `{$prefix}user` SET `home_org_id` = ? WHERE `id` = ?",
                            [$homeOrgId > 0 ? $homeOrgId : null, $id]);
                    } catch (Exception $e2) { /* column may not exist (pre-99j) */ }
                }
                // Update password only if provided.
                // Column name is `passwd` (legacy schema), not `pass`. The
                // previous `pass` references caused INSERT/UPDATE to fail
                // with SQLSTATE[42S22] 1054. Caught while Eric was
                // creating a user account on your-server.example.com
                // 2026-05-26.
                if ($password !== '') {
                    // Phase 10 (2026-06-08): policy check (length + history).
                    // Existing schema's password column is `passwd`; helper
                    // is the same module used by the user self-change flow.
                    $polCheck = pw_validate($password, (int)$id);
                    if (!$polCheck['ok']) json_error($polCheck['error']);

                    // Phase 10c (2026-06-11): when an admin changes ANOTHER
                    // user's password via the User Accounts edit form, the
                    // action is an admin password reset and must obey the
                    // same CJIS controls as the dedicated Reset Password
                    // form in Login Settings:
                    //   - require a reason (3-2000 chars)
                    //   - audit with the reason in details
                    //   - force the target user to change pw on next login
                    //   - kill the target user's existing sessions
                    // When the admin is editing their OWN record (e.g.,
                    // they're changing their own password while editing
                    // other fields), we skip the reason requirement —
                    // self-change is logged elsewhere and they're already
                    // authenticated as themselves.
                    $isAdminResetOfOther = ((int) $id !== (int) $current_user_id);
                    $resetReason = null;
                    if ($isAdminResetOfOther) {
                        $resetReason = isset($input['reason'])
                            ? trim((string)$input['reason']) : '';
                        if ($resetReason === '' || strlen($resetReason) < 3) {
                            json_error(
                                'A reason is required when changing another user\'s password '
                                . '(3-2000 characters). This is recorded in the audit log for '
                                . 'CJIS compliance review.',
                                400
                            );
                        }
                        if (strlen($resetReason) > 2000) {
                            json_error('Reason must be 2000 characters or fewer', 400);
                        }
                    }

                    $hash = hash_new_password($password);
                    db_query("UPDATE `{$prefix}user` SET `passwd` = ? WHERE `id` = ?", [$hash, $id]);

                    // Phase 10: record to history + mark changed.
                    pw_record_history((int)$id, $hash);
                    pw_mark_changed((int)$id);

                    if ($isAdminResetOfOther) {
                        // Force the target to choose their own pw on next login.
                        try {
                            db_query(
                                "UPDATE `{$prefix}user` SET `must_change_password` = 1 WHERE `id` = ?",
                                [$id]
                            );
                        } catch (Exception $e2) { /* pre-Phase-9 schema */ }
                        // Kill the target user's existing sessions.
                        try {
                            require_once __DIR__ . '/../inc/session-manager.php';
                            if (function_exists('sm_destroy_all_for_user')) {
                                sm_destroy_all_for_user((int)$id);
                            }
                        } catch (Exception $e2) { /* session-manager missing */ }
                        // Audit as an admin password reset, mirroring the
                        // dedicated Reset Password form in
                        // api/login-security.php. Reason goes verbatim into
                        // the details JSON so auditors can search it.
                        if (function_exists('audit_admin')) {
                            audit_admin($current_user_id, 'update', 'user',
                                "Admin reset password for user #{$id} (User Accounts form)",
                                [
                                    'target_user_id' => (int)$id,
                                    'reason'         => $resetReason,
                                    'source'         => 'user_accounts_edit_form',
                                ]);
                        }
                    }
                }

                // Phase 9 (2026-06-08): persist must_change_password if the
                // admin sent an explicit value. Silently no-op when the
                // column doesn't exist (pre-phase-9 install).
                if ($forceChangePw !== null) {
                    try {
                        db_query(
                            "UPDATE `{$prefix}user` SET `must_change_password` = ? WHERE `id` = ?",
                            [$forceChangePw, $id]
                        );
                    } catch (Exception $e2) { /* pre-phase-9 column missing */ }
                }
            } else {
                // Create user — password required
                if ($password === '') json_error('Password is required for new users');

                // Phase 10 (2026-06-08): policy check before hashing.
                // We pass userId=0 since no row exists yet. The history
                // check is a no-op for userId=0 (the helper checks > 0).
                $polCheck = pw_validate($password, 0);
                if (!$polCheck['ok']) json_error($polCheck['error']);

                // Phase 12 (2026-06-11): no longer write user.level on
                // user-create. RBAC role grants (handled below) are the
                // source of truth. The column defaults to 0 on the row
                // but nothing reads it for access decisions.
                $hash = hash_new_password($password);
                try {
                    db_query(
                        "INSERT INTO `{$prefix}user` (`user`, `passwd`, `can_login`) VALUES (?, ?, ?)",
                        [$username, $hash, $canLogin]
                    );
                } catch (Exception $e2) {
                    db_query(
                        "INSERT INTO `{$prefix}user` (`user`, `passwd`) VALUES (?, ?)",
                        [$username, $hash]
                    );
                }
                $id = (int) db_insert_id();

                // Phase 10: record initial password to history + mark
                // password_changed_at = NOW() so the rotation timer starts.
                pw_record_history($id, $hash);
                pw_mark_changed($id);

                // Phase 9 (2026-06-08): set must_change_password after the
                // initial INSERT. Done as a separate UPDATE so old INSERT
                // shapes still work in environments where the column is
                // absent.
                if ($forceChangePw !== null) {
                    try {
                        db_query(
                            "UPDATE `{$prefix}user` SET `must_change_password` = ? WHERE `id` = ?",
                            [$forceChangePw, $id]
                        );
                    } catch (Exception $e2) { /* column missing — skip */ }
                }
                // Phase 99j-2: set home_org_id on new users. Defaults
                // to org 1 ("System Owner") if the form didn't pick one.
                try {
                    db_query(
                        "UPDATE `{$prefix}user` SET `home_org_id` = ? WHERE `id` = ?",
                        [($homeOrgId !== null && $homeOrgId > 0) ? $homeOrgId : 1, $id]
                    );
                } catch (Exception $e2) { /* column missing (pre-99j) — skip */ }
            }
            // Phase 11 (2026-06-11): grant the RBAC role.
            //
            // If the caller sent an explicit role_id, that's the canonical
            // choice — replace any existing global grant with the new role.
            // This is the user-facing path: admin picks "Operator" in the
            // User Accounts dropdown, system writes user_roles(role_id=4).
            //
            // If only `level` was sent (legacy callers, the upgrade tool,
            // older test scripts), fall back to the levelToRole mapping
            // and ONLY auto-grant when the user has no active grant yet
            // (this is the original Phase-8 auto-grant behavior).
            try {
                $levelToRole = [
                    0 => 1,  // Super → Super Admin
                    1 => 2,  // Admin → Org Admin
                    2 => 3,  // Operator → Dispatcher
                    3 => 5,  // Guest → Read-Only
                    4 => 6,  // Unit → Field Unit
                    5 => 5, 6 => 5, 7 => 5, 8 => 5,
                ];

                if ($explicitRoleId !== null && $explicitRoleId > 0) {
                    // Phase 11 canonical path: REPLACE the user's active
                    // primary role grant with the chosen role.
                    //
                    // Phase 99j-2 (Billy beta 2026-06-29): when the form
                    // supplied a Role Scope (role_org_id), write the grant
                    // with scope_kind='org' + that org_id so this role's
                    // powers only apply within that org (and its
                    // descendants, per the visibility helper). Empty
                    // role_org_id keeps the historic 'global' behavior.
                    //
                    // The replace-target also widens: we delete BOTH old
                    // global grants AND any org-scoped grants the form
                    // is replacing (matched by being managed-by-form),
                    // because a user only has ONE "primary role" set by
                    // this UI. Other scope-bound grants from the Roles &
                    // Permissions UI keep their independent lifecycle.
                    $scopeKind = ($roleOrgId !== null && $roleOrgId > 0) ? 'org' : 'global';
                    $scopeOrg  = ($roleOrgId !== null && $roleOrgId > 0) ? $roleOrgId : null;
                    db_query(
                        "DELETE FROM `{$prefix}user_roles`
                         WHERE user_id = ?
                           AND reason LIKE 'Set via User Accounts form%'",
                        [$id]
                    );
                    // Also clear the legacy unconditional-global grants
                    // (rows without the marker reason) to avoid duplicate
                    // primary roles after one save.
                    db_query(
                        "DELETE FROM `{$prefix}user_roles`
                         WHERE user_id = ? AND scope_kind = 'global'",
                        [$id]
                    );
                    // GH #56 (Billy Irwin / K9OH, 2026-07-04): scope_id is the
                    // column the RBAC engine actually matches on
                    // (_rbac_scope_satisfied compares active_org_id ===
                    // grant.scope_id); org_id is only a back-compat mirror
                    // DERIVED from scope_id (see inc/rbac_grant.php). This
                    // writer had it backwards — it populated org_id but left
                    // scope_id NULL, so every org-scoped grant made through the
                    // User Accounts form was dead on arrival (scope_id NULL
                    // casts to 0, never matches a real org). Write scope_id =
                    // the org
                    // (NULL for a global grant, since $scopeOrg is null there).
                    db_query(
                        "INSERT INTO `{$prefix}user_roles`
                         (user_id, role_id, org_id, scope_kind, scope_id,
                          granted_at, granted_by, reason)
                         VALUES (?, ?, ?, ?, ?, NOW(), ?,
                          'Set via User Accounts form (Phase 11 canonical RBAC, Phase 99j-2 org scope)')",
                        [$id, $explicitRoleId, $scopeOrg, $scopeKind, $scopeOrg, $current_user_id ?? null]
                    );
                    audit_log('rbac', 'grant', 'user_role', (int) db_insert_id(),
                        "Set role #{$explicitRoleId} on user #{$id} (scope={$scopeKind}" .
                        ($scopeOrg ? " org={$scopeOrg}" : '') . ", via User Accounts form)",
                        ['user_id' => $id, 'role_id' => $explicitRoleId,
                         'scope_kind' => $scopeKind, 'org_id' => $scopeOrg,
                         'source' => 'user_accounts_form_role_id']);
                } else {
                    // Legacy path: derive role from `level` and auto-grant
                    // only if no active grant exists. Preserves the
                    // upgrade-tool / older-script POST shape.
                    $roleId = $levelToRole[$lvl] ?? 5;
                    $hasGrant = (int) db_fetch_value(
                        "SELECT COUNT(*) FROM `{$prefix}user_roles`
                         WHERE user_id = ?
                           AND (expires_at IS NULL OR expires_at > NOW())",
                        [$id]
                    );
                    if ($hasGrant === 0) {
                        db_query(
                            "INSERT INTO `{$prefix}user_roles`
                             (user_id, role_id, org_id, scope_kind, scope_id,
                              granted_at, granted_by, reason)
                             VALUES (?, ?, NULL, 'global', NULL, NOW(), ?,
                              'Auto-granted from user.level on user-save')",
                            [$id, $roleId, $current_user_id ?? null]
                        );
                        audit_log('rbac', 'grant', 'user_role', (int) db_insert_id(),
                            "Auto-granted role #{$roleId} to user #{$id} from level {$lvl}",
                            ['user_id' => $id, 'role_id' => $roleId, 'level' => $lvl,
                             'scope_kind' => 'global', 'source' => 'user-save auto-map']);
                    }
                }
            } catch (Throwable $rbacErr) {
                // user_roles table may not exist on a pre-v2 install; that
                // path uses the legacy fallback in inc/rbac.php and is fine.
            }

            audit_log('auth', $id ? 'update' : 'create', 'user', $id, ($id ? "Updated" : "Created") . " user account '{$username}'", [
                'level' => $lvl
            ]);
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'users.save');
        }
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        // Don't allow deleting yourself
        if ($id === $current_user_id) {
            json_error('Cannot delete your own account');
        }
        try {
            // 2026-06-11 (Phase 10b bug-fix): cascade-delete RBAC grants
            // and password history before removing the user row. Without
            // this, deleted users leave orphan user_roles rows that
            // inflate role-count displays (caught 2026-06-11) and
            // orphan password_history rows that grow unbounded.
            // user_password_history table is post-Phase-10, may not exist
            // on older schemas — wrap in try/catch.
            try {
                db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$id]);
            } catch (Exception $e2) { /* table may not exist on pre-RBAC-v2 installs */ }
            try {
                db_query("DELETE FROM `{$prefix}user_password_history` WHERE `user_id` = ?", [$id]);
            } catch (Exception $e2) { /* pre-Phase-10 install */ }

            db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$id]);
            audit_log('auth', 'delete', 'user', $id, "Deleted user account #{$id} (cascaded user_roles + password history)");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error_safe('Delete failed. Check server logs.', $e, 'users.delete');
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  FACILITY TYPES
// ═══════════════════════════════════════════════════════════════
if ($section === 'facility_types') {

    if ($method === 'GET') {
        try {
            $rows = db_fetch_all("SELECT `id`, `name`, `description`, `icon` FROM `{$prefix}fac_types` ORDER BY `name`");
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['rows' => $rows]);
    }

    if ($method === 'POST') {
        $id   = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $name = trim($input['name'] ?? '');
        $desc = trim($input['description'] ?? '');
        $icon = (int) ($input['icon'] ?? 0);

        if ($name === '') json_error('Facility type name is required');

        // Phase 102 (a beta tester beta 2026-07-01) — fresh installs have
        // `_by/_on/_from` audit columns declared NOT NULL without
        // defaults; strict-mode rejects our INSERT with "Field '_by'
        // doesn't have a default value". Self-heal on the first failure
        // then retry once. Idempotent on healthy installs.
        try {
            if ($id) {
                db_query(
                    "UPDATE `{$prefix}fac_types` SET `name` = ?, `description` = ?, `icon` = ? WHERE `id` = ?",
                    [$name, $desc, $icon, $id]
                );
            } else {
                $insertSql = "INSERT INTO `{$prefix}fac_types` (`name`, `description`, `icon`) VALUES (?, ?, ?)";
                $params    = [$name, $desc, $icon];
                try {
                    db_query($insertSql, $params);
                } catch (Exception $e) {
                    heal_legacy_defaults($prefix . 'fac_types');
                    db_query($insertSql, $params);
                }
                $id = (int) db_insert_id();
            }
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'fac_types.save');
        }
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM `{$prefix}fac_types` WHERE `id` = ?", [$id]);
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error_safe('Delete failed. Check server logs.', $e, 'fac_types.delete');
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  UNIT TYPES (unit_types table) — GH #61: config had no editor for the
//  unit "type" that unit-edit.php offers. Mirrors facility_types.
// ═══════════════════════════════════════════════════════════════
if ($section === 'unit_types') {

    if ($method === 'GET') {
        try {
            $rows = db_fetch_all("SELECT `id`, `name`, `description`, `icon` FROM `{$prefix}unit_types` ORDER BY `name`");
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['rows' => $rows]);
    }

    if ($method === 'POST') {
        $id   = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        // unit_types.name is varchar(32) (widened from 16 by
        // run_unit_types_name_widen.php), description varchar(48) — clamp so a
        // long value degrades gracefully instead of erroring.
        $name = mb_substr(trim($input['name'] ?? ''), 0, 32);
        $desc = mb_substr(trim($input['description'] ?? ''), 0, 48);
        $icon = (int) ($input['icon'] ?? 0);

        if ($name === '') json_error('Unit type name is required');

        // Legacy audit columns (_by/_on/_from) are NOT NULL without defaults on
        // fresh installs; self-heal on the first failure then retry once
        // (same pattern as fac_types, Phase 102).
        try {
            if ($id) {
                db_query(
                    "UPDATE `{$prefix}unit_types` SET `name` = ?, `description` = ?, `icon` = ? WHERE `id` = ?",
                    [$name, $desc, $icon, $id]
                );
            } else {
                $insertSql = "INSERT INTO `{$prefix}unit_types` (`name`, `description`, `icon`) VALUES (?, ?, ?)";
                $params    = [$name, $desc, $icon];
                try {
                    db_query($insertSql, $params);
                } catch (Exception $e) {
                    heal_legacy_defaults($prefix . 'unit_types');
                    db_query($insertSql, $params);
                }
                $id = (int) db_insert_id();
            }
            audit_log('config', $id ? 'update' : 'create', 'unit_type', $id, "Saved unit type '{$name}'");
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'unit_types.save');
        }
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM `{$prefix}unit_types` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'unit_type', $id, "Deleted unit type #{$id}");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error_safe('Delete failed. Check server logs.', $e, 'unit_types.delete');
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  WARN LOCATIONS (warnings table)
// ═══════════════════════════════════════════════════════════════
if ($section === 'warn_locations') {

    if ($method === 'GET') {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `title`, `street`, `city`, `state`, `lat`, `lng`, `radius`,
                        `loc_type`, `description`, `_by`, `_on`, `_from`
                 FROM `{$prefix}warnings`
                 ORDER BY `id` DESC"
            );
        } catch (Exception $e) {
            // Fallback without radius if column doesn't exist yet
            try {
                $rows = db_fetch_all(
                    "SELECT `id`, `title`, `street`, `city`, `state`, `lat`, `lng`,
                            `loc_type`, `description`, `_by`, `_on`, `_from`
                     FROM `{$prefix}warnings`
                     ORDER BY `id` DESC"
                );
            } catch (Exception $e2) {
                $rows = [];
            }
        }
        json_response(['rows' => $rows]);
    }

    if ($method === 'POST') {
        $id       = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $title    = trim($input['title'] ?? '');
        $street   = trim($input['street'] ?? '');
        $city     = trim($input['city'] ?? '');
        $state    = trim($input['state'] ?? '');
        $lat      = (float) ($input['lat'] ?? 0);
        $lng      = (float) ($input['lng'] ?? 0);
        $radius   = (int) ($input['radius'] ?? 500);
        $locType  = (int) ($input['loc_type'] ?? 0);
        $desc     = trim($input['description'] ?? '');

        if ($title === '') json_error('Title is required');
        if ($desc === '')  json_error('Description is required');

        try {
            if ($id) {
                $sql = "UPDATE `{$prefix}warnings` SET
                    `title` = ?, `street` = ?, `city` = ?, `state` = ?,
                    `lat` = ?, `lng` = ?, `radius` = ?, `loc_type` = ?, `description` = ?,
                    `_by` = ?, `_on` = NOW(), `_from` = ?
                    WHERE `id` = ?";
                db_query($sql, [$title, $street, $city, $state, $lat, $lng, $radius, $locType, $desc, $current_user_id, $current_user, $id]);
            } else {
                $sql = "INSERT INTO `{$prefix}warnings`
                    (`title`, `street`, `city`, `state`, `lat`, `lng`, `radius`, `loc_type`, `description`, `_by`, `_on`, `_from`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
                db_query($sql, [$title, $street, $city, $state, $lat, $lng, $radius, $locType, $desc, $current_user_id, $current_user]);
                $id = (int) db_insert_id();
            }
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'warnings.save');
        }
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM `{$prefix}warnings` WHERE `id` = ?", [$id]);
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error_safe('Delete failed. Check server logs.', $e, 'warnings.delete');
        }
    }
}

// ── Codes / Standard Messages ───────────────────────────────────
if ($section === 'codes') {
    if ($method === 'GET') {
        try {
            $rows = db_fetch_all("SELECT * FROM " . db_table('codes') . " ORDER BY sort, code");
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['codes' => $rows]);
    }
    if ($method === 'POST') {
        $action = $input['action'] ?? 'save';
        if ($action === 'delete') {
            $id = intval($input['id'] ?? 0);
            if (!$id) json_error('Missing id');
            try {
                db_query("DELETE FROM " . db_table('codes') . " WHERE id = ?", [$id]);
                audit_log('config', 'delete', 'code', $id, "Deleted signal/code #{$id}");
            } catch (Exception $e) {
                json_error_safe('Delete failed. Check server logs.', $e, 'codes.delete');
            }
            json_response(['success' => true]);
        }
        // Save (create or update)
        $code = trim($input['code'] ?? '');
        $text = trim($input['text'] ?? '');
        if (!$code) json_error('Code is required');
        $id = intval($input['id'] ?? 0);
        $sort = intval($input['sort'] ?? 0);
        try {
            if ($id > 0) {
                db_query("UPDATE " . db_table('codes') . " SET code = ?, text = ?, sort = ? WHERE id = ?",
                    [$code, $text, $sort, $id]);
                audit_log('config', 'update', 'code', $id, "Updated signal '{$code}'");
            } else {
                db_query("INSERT INTO " . db_table('codes') . " (code, text, sort) VALUES (?, ?, ?)",
                    [$code, $text, $sort]);
                $id = db_insert_id();
                audit_log('config', 'create', 'code', $id, "Created signal '{$code}'");
            }
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'codes.save');
        }
        json_response(['success' => true, 'id' => $id]);
    }
    json_error('Method not allowed for codes', 405);
}

// ── Captions / i18n ────────────────────────────────────────────
if ($section === 'captions') {
    if ($method === 'GET') {
        try {
            $rows = db_fetch_all("SELECT * FROM " . db_table('captions') . " ORDER BY capt");
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['captions' => $rows]);
    }
    if ($method === 'POST') {
        $action = $input['action'] ?? 'save';
        if ($action === 'delete') {
            $id = intval($input['id'] ?? 0);
            if (!$id) json_error('Missing id');
            try {
                db_query("DELETE FROM " . db_table('captions') . " WHERE id = ?", [$id]);
            } catch (Exception $e) {
                json_error_safe('Delete failed. Check server logs.', $e, 'captions.delete');
            }
            json_response(['success' => true]);
        }
        $capt = trim($input['capt'] ?? '');
        $repl = trim($input['repl'] ?? '');
        if (!$capt) json_error('Caption key is required');
        $id = intval($input['id'] ?? 0);
        try {
            if ($id > 0) {
                db_query("UPDATE " . db_table('captions') . " SET capt = ?, repl = ? WHERE id = ?",
                    [$capt, $repl, $id]);
            } else {
                db_query("INSERT INTO " . db_table('captions') . " (capt, repl) VALUES (?, ?)",
                    [$capt, $repl ?: $capt]);
                $id = db_insert_id();
            }
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'captions.save');
        }
        json_response(['success' => true, 'id' => $id]);
    }
    json_error('Method not allowed for captions', 405);
}

// ── Regions ─────────────────────────────────────────────────────
if ($section === 'regions') {
    if ($method === 'GET') {
        $regions = [];
        $categories = [];
        try {
            $regions = db_fetch_all(
                "SELECT r.*, rt.name AS category_name
                 FROM " . db_table('region') . " r
                 LEFT JOIN " . db_table('region_type') . " rt ON r.category = rt.id
                 ORDER BY r.group_name"
            );
        } catch (Exception $e) { $regions = []; }
        try {
            $categories = db_fetch_all(
                "SELECT id, name, description
                 FROM " . db_table('region_type') . "
                 ORDER BY name"
            );
        } catch (Exception $e) { $categories = []; }
        json_response(['regions' => $regions, 'categories' => $categories]);
    }
    if ($method === 'POST') {
        $action = (string) ($input['action'] ?? 'save');

        if ($action === 'delete') {
            $id = (int) ($input['id'] ?? 0);
            if (!$id) json_error('Missing region id');
            try {
                db_query("DELETE FROM " . db_table('region') . " WHERE id = ?", [$id]);
                audit_log('config', 'delete', 'region', $id, "Deleted region #{$id}");
            } catch (Exception $e) {
                json_error_safe('Delete failed. Check server logs.', $e, 'regions.delete');
            }
            json_response(['success' => true]);
        }

        // Save (insert or update)
        $id       = (int) ($input['id'] ?? 0);
        $name     = trim((string) ($input['group_name'] ?? ''));
        $category = isset($input['category']) && $input['category'] !== '' ? (int) $input['category'] : null;
        $desc     = trim((string) ($input['description'] ?? ''));
        $areaCode = trim((string) ($input['def_area_code'] ?? ''));
        $city     = trim((string) ($input['def_city'] ?? ''));
        $state    = trim((string) ($input['def_st'] ?? ''));
        $lat      = isset($input['def_lat']) && $input['def_lat'] !== '' ? (float) $input['def_lat'] : null;
        $lng      = isset($input['def_lng']) && $input['def_lng'] !== '' ? (float) $input['def_lng'] : null;
        $zoom     = isset($input['def_zoom']) && $input['def_zoom'] !== '' ? (int) $input['def_zoom'] : 10;

        if ($name === '') json_error('Region name is required');

        // M2 in code review 2026-07-03 — validate the incoming
        // `category` FK before writing. `region.category` referenced
        // `region_type.id` but nothing enforced it at the API layer,
        // so a hand-crafted POST could store a bogus category id and
        // leave orphan rows the categories dropdown couldn't render.
        // Empty/null category is legal ("no category yet") and passes
        // straight through.
        if ($category !== null) {
            try {
                $catExists = (int) db_fetch_value(
                    "SELECT COUNT(*) FROM " . db_table('region_type') . " WHERE id = ?",
                    [$category]
                );
            } catch (Exception $e) {
                // region_type table missing on this install — treat
                // as "no categories configured yet" and let the write
                // proceed with category=null so we don't hard-block
                // regions on installs that never seeded categories.
                $catExists = 0;
                $category  = null;
            }
            if ($category !== null && $catExists === 0) {
                json_error('Selected category is not a valid region type', 422);
            }
        }

        try {
            if ($id > 0) {
                db_query(
                    "UPDATE " . db_table('region') . "
                        SET group_name = ?, category = ?, description = ?,
                            def_area_code = ?, def_city = ?, def_st = ?,
                            def_lat = ?, def_lng = ?, def_zoom = ?
                      WHERE id = ?",
                    [$name, $category, $desc, $areaCode, $city, $state, $lat, $lng, $zoom, $id]
                );
                audit_log('config', 'update', 'region', $id, "Updated region '{$name}'");
            } else {
                db_query(
                    "INSERT INTO " . db_table('region') .
                    " (group_name, category, description, def_area_code, def_city, def_st,
                       def_lat, def_lng, def_zoom, owner, boundary)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)",
                    [$name, $category, $desc, $areaCode, $city, $state, $lat, $lng, $zoom]
                );
                $id = (int) db_insert_id();
                audit_log('config', 'create', 'region', $id, "Created region '{$name}'");
            }
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'regions.save');
        }
        json_response(['success' => true, 'id' => $id]);
    }
    json_error('Method not allowed for regions', 405);
}

// ═══════════════════════════════════════════════════════════════
//  INCIDENT NUMBERS (Phase 15 — template engine, 2026-06-11)
//
//  The legacy 4-mode (none/sequential/label/year) shape is gone.
//  Settings now sends a single template string and a next sequence
//  number. inc/incident-number.php is the engine; this endpoint is
//  just the read/write surface for the admin UI.
// ═══════════════════════════════════════════════════════════════
if ($section === 'incident_numbers') {
    require_once __DIR__ . '/../inc/incident-number.php';

    if ($method === 'GET') {
        // Phase 15c — optional collision preview. If the admin is
        // typing into the Settings page, the JS may pass ?check=1
        // with a candidate template + sequence to ask "would this
        // collide?". This is a read-only, no-side-effects check.
        if (isset($_GET['check'])) {
            $tpl = isset($_GET['template']) ? (string) $_GET['template'] : incnum_get_template();
            $seq = isset($_GET['next_number']) ? (int) $_GET['next_number'] : incnum_get_next();
            if ($seq < 1) $seq = 1;
            $check = incnum_check_collision($tpl, $seq);
            json_response(['check' => $check]);
        }

        json_response([
            'config' => [
                'template'    => incnum_get_template(),
                'next_number' => incnum_get_next(),
                'reset_mode'  => incnum_get_reset_mode(),
                'label'       => incnum_get_label(),   // Phase 99o
            ],
        ]);
    }

    if ($method === 'POST') {
        $template   = isset($input['template']) ? (string) $input['template'] : '';
        $nextNumber = (int) ($input['next_number'] ?? 1);
        $resetMode  = isset($input['reset_mode']) ? (string) $input['reset_mode'] : '';
        // Phase 99o (Eric beta 2026-06-29): admin-configurable label.
        $label      = isset($input['label']) ? (string) $input['label'] : '';
        if ($nextNumber < 1) $nextNumber = 1;
        if (!in_array($resetMode, ['never','yearly','monthly','daily'], true)) {
            $resetMode = incnum_suggest_reset_mode($template);
        }

        $v = incnum_validate($template);
        if (!$v['valid']) {
            json_error(implode(' ', $v['errors']) ?: 'Invalid template', 400);
        }

        try {
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_template', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$template]
            );
            incnum_set_reset_mode($resetMode);
            incnum_set_next($nextNumber);
            if ($label !== '') incnum_set_label($label);
            audit_log('config', 'update', 'settings', null, 'Updated incident number template', [
                'template'    => $template,
                'next_number' => $nextNumber,
                'reset_mode'  => $resetMode,
                'label'       => $label !== '' ? $label : incnum_get_label(),
            ]);
            json_response([
                'saved'      => true,
                'warnings'   => $v['warnings'],
                'sample'     => incnum_render($template, $nextNumber),
                'reset_mode' => $resetMode,
            ]);
        } catch (Exception $e) {
            json_error_safe('Save failed. Check server logs.', $e, 'incident_number.save');
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  FIELD ENCRYPTION (RSA key management)
// ═══════════════════════════════════════════════════════════════
if ($section === 'field-encryption') {
    require_once __DIR__ . '/../inc/field-encrypt.php';
    require_once __DIR__ . '/../inc/security.php';

    if ($method === 'GET') {
        $keyStatus = fe_key_status();
        json_response([
            'https'   => fe_is_https(),
            'enabled' => get_setting('field_encrypt_enabled', '1') === '1',
            'keys'    => $keyStatus,
        ]);
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!csrf_verify($input['csrf_token'] ?? '')) {
            json_error('CSRF token invalid', 403);
        }

        $action = $input['action'] ?? 'save';

        if ($action === 'regenerate') {
            $ok = fe_generate_keypair();
            if ($ok) {
                // Audit log the key regeneration
                require_once __DIR__ . '/../inc/audit.php';
                audit_log('config', 'regenerate', 'encryption_keys', null,
                    'RSA field encryption keys regenerated (old keys archived)',
                    ['action' => 'key_regeneration', 'type' => 'rsa_field_encryption'],
                    defined('AUDIT_HIGH') ? AUDIT_HIGH : 4);
                json_response(['regenerated' => true, 'keys' => fe_key_status()]);
            } else {
                json_error('Key generation failed. Check openssl extension and directory permissions.', 500);
            }
        }

        // Save toggle
        if ($action === 'save') {
            $enabled = !empty($input['enabled']) ? '1' : '0';
            try {
                $stmt = db_query(
                    "INSERT INTO `{$prefix}config` (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    ['field_encrypt_enabled', $enabled]
                );
                json_response(['saved' => true, 'enabled' => $enabled === '1']);
            } catch (Exception $e) {
                json_error_safe('Save failed. Check server logs.', $e, 'field_encryption.save');
            }
        }

        json_error('Unknown action', 400);
    }
}

// ── Unknown section ─────────────────────────────────────────────
ini_set('display_errors', $prevDisplay);
json_error('Unknown section: ' . $section, 400);
