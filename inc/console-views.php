<?php
/**
 * Phase 114b3 — console views business logic (shared + personal).
 *
 * Extracted out of api/console-views.php so the decision functions can be
 * driven directly by tests, without HTTP — the established pattern in this
 * codebase (see org_routing_resolve_create_owning_org() / pb_resolve_
 * caller_org_id() and their test files' own docblocks for the rationale).
 * api/console-views.php becomes a thin HTTP dispatcher over these.
 *
 * Two view layers share one schema (console_views.owner_user_id):
 *   - SHARED  (owner_user_id IS NULL)      — admin-authored, console.design
 *     required to create/edit/delete/publish. Visible to every screen.console
 *     holder. This is the b2 designer's original surface, unchanged.
 *   - PERSONAL (owner_user_id = a user id) — b3. Any screen.console holder
 *     may create/edit/delete their OWN personal views; console.design is
 *     NOT required (Eric, 2026-08-20: "a personal layout is scoped to its
 *     owner and shouldn't need elevated permission"). A personal view may
 *     optionally be marked `is_shared=1` ("available for others to adopt")
 *     — this makes it visible (read-only, full detail) to every other
 *     screen.console holder as a CLONE SOURCE, never as a live shared tab
 *     nobody asked to see. Cloning copies its strips into a brand-new
 *     personal view owned by the cloner; the original is never mutated.
 *     No approve/request flow, no per-user ACL: the simplest mechanism
 *     that satisfies "make available for others to adopt" without building
 *     a permissions system nobody asked for. See console-designer.md and
 *     specs/phase-114-audio-matrix/ for the b3 slice this closes.
 */

// ── Component catalog (designer palette) ─────────────────────────────────
// Phase 114b3: monitor/mute/volume are REAL now (console-audio.js + the
// zello/radio widget hooks) — future=>false. 'say' (TTS button) still has
// no backend; stays future=>true, honestly.
function console_component_catalog() {
    return [
        'label'    => ['needs' => null, 'label' => 'Label block',
                       'props' => ['text', 'bg', 'fg'], 'future' => false],
        'led'      => ['needs' => null, 'label' => 'Status light',
                       'props' => [], 'future' => false],
        'activity' => ['needs' => null, 'label' => 'Last-caller line',
                       'props' => [], 'future' => false],
        'ptt'      => ['needs' => ['voice_tx'], 'label' => 'PTT button',
                       'props' => ['text', 'color', 'mode'], 'future' => false],
        'text'     => ['needs' => ['text_rx', 'text_tx', 'source'], 'label' => 'Messages / feed box',
                       'props' => [], 'future' => false],
        'monitor'  => ['needs' => ['voice_rx'], 'label' => 'Monitor toggle',
                       'props' => ['text'], 'future' => false],
        'mute'     => ['needs' => ['voice_rx'], 'label' => 'Mute button',
                       'props' => ['text'], 'future' => false],
        'volume'   => ['needs' => ['voice_rx'], 'label' => 'Volume slider',
                       'props' => [], 'future' => false],
        'say'      => ['needs' => ['tts_out'], 'label' => 'Say (TTS) button',
                       'props' => ['text'], 'future' => true],
    ];
}

/** True when the channel's capabilities permit this component type. */
function console_component_allowed($type, array $caps) {
    $cat = console_component_catalog();
    if (!isset($cat[$type])) { return false; }
    $needs = $cat[$type]['needs'];
    if ($needs === null) { return true; }
    foreach ($needs as $capKey) {
        if (!empty($caps[$capKey])) { return true; }
    }
    return false;
}

/**
 * Validate + clamp one requested component. Returns the clean component
 * or null when the type is unknown/not capable. Inner grid: 12 columns,
 * rows are 14px in both designer and runtime.
 */
function console_component_clean(array $c, array $caps) {
    $type = (string) ($c['type'] ?? '');
    if (!console_component_allowed($type, $caps)) { return null; }
    $cat = console_component_catalog()[$type];
    $out = [
        'type' => $type,
        'x' => max(0, min(11, (int) ($c['x'] ?? 0))),
        'y' => max(0, min(300, (int) ($c['y'] ?? 0))),
        'w' => max(1, min(12, (int) ($c['w'] ?? 12))),
        'h' => max(1, min(60, (int) ($c['h'] ?? 2))),
    ];
    if ($out['x'] + $out['w'] > 12) { $out['w'] = 12 - $out['x']; }
    $props = [];
    foreach ($cat['props'] as $k) {
        if (!isset($c['props'][$k])) { continue; }
        $v = trim((string) $c['props'][$k]);
        if ($v === '') { continue; }
        if (($k === 'color' || $k === 'bg' || $k === 'fg') && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)) {
            return null; // invalid colour — reject the component outright
        }
        if ($k === 'mode' && !in_array($v, ['momentary', 'latch'], true)) { return null; }
        if ($k === 'text') { $v = substr($v, 0, 40); }
        $props[$k] = $v;
    }
    if ($props) { $out['props'] = $props; }
    return $out;
}

/**
 * Default positioned component set for a channel — also the converter
 * for legacy b2 flat control lists. Mirrors Eric's sketch: label block
 * on top, LED beside it, activity line, wide PTT, small buttons below.
 */
function console_components_default(array $caps) {
    $comps = [
        ['type' => 'label', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 3],
        ['type' => 'led', 'x' => 10, 'y' => 0, 'w' => 2, 'h' => 1],
        ['type' => 'activity', 'x' => 0, 'y' => 3, 'w' => 12, 'h' => 2],
    ];
    $y = 5;
    if (!empty($caps['voice_tx'])) {
        $comps[] = ['type' => 'ptt', 'x' => 0, 'y' => $y, 'w' => 12, 'h' => 3];
        $y += 3;
    }
    if (!empty($caps['voice_rx'])) {
        $comps[] = ['type' => 'monitor', 'x' => 0, 'y' => $y, 'w' => 4, 'h' => 2];
        $comps[] = ['type' => 'mute', 'x' => 4, 'y' => $y, 'w' => 4, 'h' => 2];
        $comps[] = ['type' => 'volume', 'x' => 0, 'y' => $y + 2, 'w' => 12, 'h' => 1];
        $y += 3;
    }
    if (!empty($caps['text_rx']) || !empty($caps['text_tx']) || !empty($caps['source'])) {
        $comps[] = ['type' => 'text', 'x' => 0, 'y' => $y, 'w' => 12, 'h' => 10];
        $y += 10;
    }
    return $comps;
}

/** True whether console_view_strips.is_shared / layout_json columns exist (idempotent-migration guard). */
function console_views_column_exists($table, $column) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $table, $column]
        );
    } catch (Exception $e) { return false; }
}

/** Attach {strips:[...]} to one console_views row (id required). */
function console_view_attach_strips(array $v) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $hasLayoutCol = console_views_column_exists('console_view_strips', 'layout_json');
    $layoutSel = $hasLayoutCol ? ', layout_json' : '';
    $strips = db_fetch_all(
        "SELECT channel_id, position, width, overrides_json, controls_json $layoutSel
           FROM `{$prefix}console_view_strips`
          WHERE view_id = ? ORDER BY position",
        [$v['id']]
    );
    foreach ($strips as $i => &$s) {
        $s['overrides'] = $s['overrides_json'] ? (json_decode($s['overrides_json'], true) ?: []) : [];
        $decoded = $s['controls_json'] ? (json_decode($s['controls_json'], true) ?: []) : [];
        $s['layout'] = (!empty($s['layout_json']))
            ? (json_decode($s['layout_json'], true) ?: null) : null;
        // Legacy b2 rows: flat control-key list, no layout — convert to
        // the default positioned set so clients see ONE format.
        if ($decoded && is_string($decoded[0] ?? null)) {
            $ch = channel_get((int) $s['channel_id']);
            $decoded = console_components_default($ch ? $ch['capabilities'] : []);
        }
        $s['components'] = $decoded;
        if (!$s['layout']) {
            // Legacy width (1|2) → a sensible rectangle, flowed left-to-right.
            $w = ((int) $s['width'] === 2) ? 6 : 3;
            $perRow = (int) floor(12 / $w);
            $s['layout'] = [
                'x' => ($i % $perRow) * $w,
                'y' => (int) floor($i / $perRow) * 14,
                'w' => $w, 'h' => 14,
            ];
        }
        unset($s['overrides_json'], $s['controls_json'], $s['layout_json']);
    }
    $v['strips'] = $strips;
    return $v;
}

/** Shared (admin-authored) views — owner_user_id IS NULL. Unchanged b2 shape. */
function console_shared_views() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $views = db_fetch_all(
        "SELECT id, name, icon, sort_order, updated_at
           FROM `{$prefix}console_views`
          WHERE owner_user_id IS NULL
       ORDER BY sort_order, name"
    );
    foreach ($views as &$v) { $v = console_view_attach_strips($v); }
    return $views;
}

/** The caller's own personal views. */
function console_personal_views_for_user($userId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $hasShared = console_views_column_exists('console_views', 'is_shared');
    $sharedSel = $hasShared ? 'is_shared' : '0 AS is_shared';
    $views = db_fetch_all(
        "SELECT id, name, icon, sort_order, updated_at, based_on_view_id, $sharedSel
           FROM `{$prefix}console_views`
          WHERE owner_user_id = ?
       ORDER BY sort_order, name",
        [(int) $userId]
    );
    foreach ($views as &$v) {
        $v['is_shared'] = (bool) ($v['is_shared'] ?? 0);
        $v = console_view_attach_strips($v);
    }
    return $views;
}

/**
 * Other users' personal views marked is_shared=1 — a browsable "adopt
 * someone else's layout" list. Full strip detail included (small, and the
 * whole point is letting the browser preview before cloning). Never
 * includes the caller's own views (those are in my_views already).
 */
function console_shared_personal_views($excludeUserId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!console_views_column_exists('console_views', 'is_shared')) { return []; }
    $views = db_fetch_all(
        "SELECT cv.id, cv.name, cv.icon, cv.updated_at, cv.owner_user_id,
                TRIM(CONCAT(COALESCE(u.name_f,''), ' ', COALESCE(u.name_l,''))) AS owner_name,
                u.user AS owner_login
           FROM `{$prefix}console_views` cv
           LEFT JOIN `{$prefix}user` u ON u.id = cv.owner_user_id
          WHERE cv.owner_user_id IS NOT NULL
            AND cv.owner_user_id != ?
            AND cv.is_shared = 1
       ORDER BY cv.updated_at DESC",
        [(int) $excludeUserId]
    );
    foreach ($views as &$v) {
        $name = trim((string) ($v['owner_name'] ?? ''));
        $v['owner_display'] = ($name !== '') ? $name : (string) ($v['owner_login'] ?? ('user #' . $v['owner_user_id']));
        unset($v['owner_name'], $v['owner_login']);
        $v = console_view_attach_strips($v);
    }
    return $views;
}

/** Raw view row (no strips) or null. */
function console_view_get_row($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $row = db_fetch_one("SELECT * FROM `{$prefix}console_views` WHERE id = ?", [(int) $id]);
    return $row ?: null;
}

/**
 * The RBAC boundary as a pure decision function — driven directly by
 * tests, no session/HTTP required. Given a view row (or null when the id
 * doesn't exist) and the caller's identity, decides whether they may
 * mutate it, and why not when they can't.
 *
 * Shared view (owner_user_id NULL)         -> requires console.design.
 * Personal view owned by the caller        -> requires nothing further
 *                                              (screen.console, already
 *                                              gated file-wide, is enough).
 * Personal view owned by someone else       -> 404 (never reveal it exists
 *                                              — same "not found, not
 *                                              forbidden" convention this
 *                                              codebase uses elsewhere for
 *                                              cross-owner resource checks).
 * No such view                              -> 404.
 */
function console_view_can_write($viewRow, $callerId, $callerCanDesign) {
    if (!$viewRow) {
        return ['ok' => false, 'status' => 404, 'error' => 'View not found'];
    }
    if ($viewRow['owner_user_id'] === null) {
        if ($callerCanDesign) { return ['ok' => true]; }
        return ['ok' => false, 'status' => 403, 'error' => 'console.design permission required to edit a shared view'];
    }
    if ((int) $viewRow['owner_user_id'] === (int) $callerId) {
        return ['ok' => true];
    }
    return ['ok' => false, 'status' => 404, 'error' => 'View not found'];
}

/**
 * True when $sourceRow is a valid clone base for $callerId: any shared
 * view, the caller's own personal view, or another user's personal view
 * explicitly marked is_shared=1.
 */
function console_view_visible_as_clone_source($sourceRow, $callerId) {
    if (!$sourceRow) { return false; }
    if ($sourceRow['owner_user_id'] === null) { return true; }
    if ((int) $sourceRow['owner_user_id'] === (int) $callerId) { return true; }
    return !empty($sourceRow['is_shared']);
}

/** Copy every strip row from $sourceViewId into $destViewId (append, preserves position order). */
function console_view_copy_strips($sourceViewId, $destViewId) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $hasLayoutCol = console_views_column_exists('console_view_strips', 'layout_json');
    $layoutSel = $hasLayoutCol ? ', layout_json' : ', NULL AS layout_json';
    $rows = db_fetch_all(
        "SELECT channel_id, position, width, overrides_json, controls_json $layoutSel
           FROM `{$prefix}console_view_strips` WHERE view_id = ? ORDER BY position",
        [(int) $sourceViewId]
    );
    foreach ($rows as $r) {
        db_query(
            "INSERT INTO `{$prefix}console_view_strips`
                (view_id, channel_id, position, width, layout_json, overrides_json, controls_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$destViewId, $r['channel_id'], $r['position'], $r['width'],
             $r['layout_json'], $r['overrides_json'], $r['controls_json']]
        );
    }
    return count($rows);
}

/**
 * Create a view. $args: name, icon, ownerUserId (null=shared), createdBy,
 * basedOnViewId (optional clone source — caller must pass a row already
 * verified visible via console_view_visible_as_clone_source()).
 */
function console_view_create(array $args) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $name = trim((string) ($args['name'] ?? ''));
    if ($name === '') { return ['ok' => false, 'status' => 400, 'error' => 'View name is required']; }
    $icon = trim((string) ($args['icon'] ?? ''));
    if ($icon !== '' && !preg_match('/^bi-[a-z0-9-]+$/', $icon)) {
        return ['ok' => false, 'status' => 400, 'error' => 'Invalid icon (expects a bi-* class)'];
    }
    $ownerUserId = array_key_exists('ownerUserId', $args) ? $args['ownerUserId'] : null;
    $ownerUserId = ($ownerUserId === null) ? null : (int) $ownerUserId;
    $basedOnViewId = isset($args['basedOnViewId']) ? (int) $args['basedOnViewId'] : 0;

    try {
        // Parameterized either way — ownerUserId flows through a bind
        // param, never string-concatenated, even though it was already
        // int-cast above (belt and suspenders, matches this project's
        // "parameterized queries only" standard).
        $max = (int) db_fetch_value(
            $ownerUserId === null
                ? "SELECT COALESCE(MAX(sort_order), 0) FROM `{$prefix}console_views` WHERE owner_user_id IS NULL"
                : "SELECT COALESCE(MAX(sort_order), 0) FROM `{$prefix}console_views` WHERE owner_user_id = ?",
            $ownerUserId === null ? [] : [$ownerUserId]
        );
        db_query(
            "INSERT INTO `{$prefix}console_views` (name, icon, owner_user_id, based_on_view_id, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [substr($name, 0, 80), $icon ?: null, $ownerUserId, $basedOnViewId ?: null,
             $max + 10, $args['createdBy'] ?? null]
        );
        $id = (int) db_insert_id();
        $stripsCopied = 0;
        if ($basedOnViewId) {
            $stripsCopied = console_view_copy_strips($basedOnViewId, $id);
        }
        return ['ok' => true, 'id' => $id, 'strips_copied' => $stripsCopied];
    } catch (Exception $e) {
        return ['ok' => false, 'status' => 500, 'error' => 'Create failed'];
    }
}

/** Update name/icon/sort_order/is_shared on an existing view row (id already validated). */
function console_view_update($id, array $fields) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $sets = []; $args = [];
    if (array_key_exists('name', $fields)) {
        $name = trim((string) $fields['name']);
        if ($name === '') { return ['ok' => false, 'status' => 400, 'error' => 'View name is required']; }
        $sets[] = 'name = ?'; $args[] = substr($name, 0, 80);
    }
    if (array_key_exists('icon', $fields)) {
        $icon = trim((string) $fields['icon']);
        if ($icon !== '' && !preg_match('/^bi-[a-z0-9-]+$/', $icon)) {
            return ['ok' => false, 'status' => 400, 'error' => 'Invalid icon (expects a bi-* class)'];
        }
        $sets[] = 'icon = ?'; $args[] = $icon ?: null;
    }
    if (array_key_exists('sort_order', $fields)) {
        $sets[] = 'sort_order = ?'; $args[] = (int) $fields['sort_order'];
    }
    if (array_key_exists('is_shared', $fields) && console_views_column_exists('console_views', 'is_shared')) {
        $sets[] = 'is_shared = ?'; $args[] = !empty($fields['is_shared']) ? 1 : 0;
    }
    if (!$sets) { return ['ok' => false, 'status' => 400, 'error' => 'Nothing to update']; }
    try {
        $args[] = (int) $id;
        db_query("UPDATE `{$prefix}console_views` SET " . implode(', ', $sets) . " WHERE id = ?", $args);
        return ['ok' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'status' => 500, 'error' => 'Update failed'];
    }
}

function console_view_delete($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("DELETE FROM `{$prefix}console_view_strips` WHERE view_id = ?", [(int) $id]);
        db_query("DELETE FROM `{$prefix}console_views` WHERE id = ?", [(int) $id]);
        return ['ok' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'status' => 500, 'error' => 'Delete failed'];
    }
}

/**
 * Validate + persist a full strip set for one view. Mirrors the b2.5
 * free-form layout validation exactly (component capability gating,
 * colour/mode whitelist, size clamps) — a published view (shared OR
 * personal) can never contain a dead or malformed control.
 */
function console_view_save_strips($id, $strips) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    if (!is_array($strips)) { return ['ok' => false, 'status' => 400, 'error' => 'strips array is required']; }
    if (count($strips) > 64) { return ['ok' => false, 'status' => 400, 'error' => 'Too many strips (max 64)']; }

    $clean = [];
    $overrideKeys = ['label', 'short_label', 'color'];
    foreach ($strips as $i => $s) {
        $chId = (int) ($s['channel_id'] ?? 0);
        $ch = channel_get($chId);
        if (!$ch) { return ['ok' => false, 'status' => 400, 'error' => "Strip $i: unknown channel id $chId"]; }
        $ov = [];
        foreach ($overrideKeys as $k) {
            if (!isset($s['overrides'][$k])) { continue; }
            $val = trim((string) $s['overrides'][$k]);
            if ($val === '') { continue; }
            if ($k === 'color' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
                return ['ok' => false, 'status' => 400, 'error' => "Strip $i: invalid $k"];
            }
            $ov[$k] = substr($val, 0, $k === 'label' ? 120 : 24);
        }

        $lay = is_array($s['layout'] ?? null) ? $s['layout'] : [];
        $layout = [
            'x' => max(0, min(11, (int) ($lay['x'] ?? 0))),
            'y' => max(0, min(500, (int) ($lay['y'] ?? 0))),
            'w' => max(1, min(12, (int) ($lay['w'] ?? 3))),
            'h' => max(4, min(100, (int) ($lay['h'] ?? 14))),
        ];
        if ($layout['x'] + $layout['w'] > 12) { $layout['w'] = 12 - $layout['x']; }

        $reqComps = is_array($s['components'] ?? null) ? $s['components'] : [];
        if (count($reqComps) > 24) { return ['ok' => false, 'status' => 400, 'error' => "Strip $i: too many components (max 24)"]; }
        $components = [];
        foreach ($reqComps as $ci => $c) {
            if (!is_array($c)) { return ['ok' => false, 'status' => 400, 'error' => "Strip $i component $ci: malformed"]; }
            $cc = console_component_clean($c, $ch['capabilities']);
            if ($cc === null) {
                return ['ok' => false, 'status' => 400, 'error' => "Strip $i component $ci: invalid or not supported by this channel"];
            }
            $components[] = $cc;
        }

        $clean[] = [
            'channel_id' => $chId,
            'width'      => ($layout['w'] >= 6) ? 2 : 1,
            'layout'     => json_encode($layout),
            'overrides'  => $ov ? json_encode($ov) : null,
            'components' => $components ? json_encode($components) : null,
        ];
    }

    try {
        db_query("DELETE FROM `{$prefix}console_view_strips` WHERE view_id = ?", [(int) $id]);
        foreach ($clean as $pos => $s) {
            db_query(
                "INSERT INTO `{$prefix}console_view_strips`
                    (view_id, channel_id, position, width, layout_json, overrides_json, controls_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$id, $s['channel_id'], $pos, $s['width'], $s['layout'], $s['overrides'], $s['components']]
            );
        }
        db_query("UPDATE `{$prefix}console_views` SET updated_at = NOW() WHERE id = ?", [(int) $id]);
        return ['ok' => true, 'count' => count($clean)];
    } catch (Exception $e) {
        return ['ok' => false, 'status' => 500, 'error' => 'Save failed'];
    }
}
