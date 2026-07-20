<?php
/**
 * Phase 114b-b2 — console views API (shared views only; personal clones
 * are slice b3)
 *
 * GET                        — shared views with their strips (screen.console)
 * POST action=create         — new shared view {name, icon}         (console.design)
 * POST action=update         — rename/re-icon/re-order {id, ...}    (console.design)
 * POST action=delete         — remove a view + its strips {id}      (console.design)
 * POST action=save_strips    — replace a view's strip set {id, strips: [
 *                                {channel_id, layout:{x,y,w,h},
 *                                 overrides:{label,short_label,color},
 *                                 components:[{type,x,y,w,h,props}, ...]},
 *                                ...]}                              (console.design)
 *
 * Free-form layout (b2.5, Eric 2026-07-07): a strip is a rectangle on
 * the view canvas (12-column outer grid, 20px rows) and its components
 * are rectangles on the strip's inner grid (12 columns, 14px rows) —
 * "a grid layout within a grid layout". Component types are validated
 * against the channel's capabilities so a published view can never
 * contain a dead button; colours and props are whitelisted per type.
 * Legacy b2 rows (flat control lists, no layout) are converted to the
 * default positioned set at read time.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/channel_registry.php';

if (!rbac_can('screen.console')) {
    json_error('Forbidden', 403);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

/**
 * Component catalog — the palette of placeable strip components
 * (Phase 114b-b2.5, free-form layout). Each entry:
 *   needs   — capability keys, ANY of which allows the component
 *             (null = always allowed)
 *   label   — palette display name
 *   future  — backend arrives with the audio bus (114c+); placeable in
 *             the designer for layout planning, rendered disabled at
 *             runtime with an explanatory tooltip
 *   props   — whitelisted per-component props
 */
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
                       'props' => ['text'], 'future' => true],
        'mute'     => ['needs' => ['voice_rx'], 'label' => 'Mute button',
                       'props' => ['text'], 'future' => true],
        'volume'   => ['needs' => ['voice_rx'], 'label' => 'Volume slider',
                       'props' => [], 'future' => true],
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
    if (!empty($caps['text_rx']) || !empty($caps['text_tx']) || !empty($caps['source'])) {
        $comps[] = ['type' => 'text', 'x' => 0, 'y' => $y, 'w' => 12, 'h' => 10];
        $y += 10;
    }
    return $comps;
}

function console_views_with_strips() {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $views = db_fetch_all(
        "SELECT id, name, icon, sort_order, updated_at
           FROM `{$prefix}console_views`
          WHERE owner_user_id IS NULL
       ORDER BY sort_order, name"
    );
    $hasLayoutCol = null;
    foreach ($views as &$v) {
        if ($hasLayoutCol === null) {
            try {
                $hasLayoutCol = (bool) db_fetch_value(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ? AND COLUMN_NAME = 'layout_json'",
                    [$prefix . 'console_view_strips']
                );
            } catch (Exception $e) { $hasLayoutCol = false; }
        }
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
    }
    return $views;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        json_response([
            'views'      => console_views_with_strips(),
            'components' => console_component_catalog(),
        ]);
    } catch (Exception $e) {
        json_error_safe('Failed to load views', $e);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($input['csrf_token'] ?? '')) {
    json_error('Invalid CSRF token', 403);
}
if (!rbac_can('console.design')) {
    json_error('Forbidden', 403);
}

require_once __DIR__ . '/../inc/audit.php';
$action = $input['action'] ?? '';

if ($action === 'create') {
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') { json_error('View name is required'); }
    $icon = trim((string) ($input['icon'] ?? ''));
    if ($icon !== '' && !preg_match('/^bi-[a-z0-9-]+$/', $icon)) {
        json_error('Invalid icon (expects a bi-* class)');
    }
    try {
        $max = (int) db_fetch_value(
            "SELECT COALESCE(MAX(sort_order), 0) FROM `{$prefix}console_views` WHERE owner_user_id IS NULL"
        );
        db_query(
            "INSERT INTO `{$prefix}console_views` (name, icon, owner_user_id, sort_order, created_by)
             VALUES (?, ?, NULL, ?, ?)",
            [substr($name, 0, 80), $icon ?: null, $max + 10, $_SESSION['user_id'] ?? null]
        );
        $id = db_insert_id();
        audit_log('config', 'console.view_create', 'console_views', $id, "Console view \"$name\" created");
        json_response(['ok' => true, 'id' => $id, 'views' => console_views_with_strips()]);
    } catch (Exception $e) {
        json_error_safe('Create failed', $e);
    }
}

if ($action === 'update') {
    $id = (int) ($input['id'] ?? 0);
    $v = db_fetch_one(
        "SELECT * FROM `{$prefix}console_views` WHERE id = ? AND owner_user_id IS NULL", [$id]
    );
    if (!$v) { json_error('View not found', 404); }
    $sets = []; $args = [];
    if (array_key_exists('name', $input)) {
        $name = trim((string) $input['name']);
        if ($name === '') { json_error('View name is required'); }
        $sets[] = 'name = ?'; $args[] = substr($name, 0, 80);
    }
    if (array_key_exists('icon', $input)) {
        $icon = trim((string) $input['icon']);
        if ($icon !== '' && !preg_match('/^bi-[a-z0-9-]+$/', $icon)) {
            json_error('Invalid icon (expects a bi-* class)');
        }
        $sets[] = 'icon = ?'; $args[] = $icon ?: null;
    }
    if (array_key_exists('sort_order', $input)) {
        $sets[] = 'sort_order = ?'; $args[] = (int) $input['sort_order'];
    }
    if (!$sets) { json_error('Nothing to update'); }
    try {
        $args[] = $id;
        db_query("UPDATE `{$prefix}console_views` SET " . implode(', ', $sets) . " WHERE id = ?", $args);
        audit_log('config', 'console.view_update', 'console_views', $id, "Console view \"{$v['name']}\" updated");
        json_response(['ok' => true, 'views' => console_views_with_strips()]);
    } catch (Exception $e) {
        json_error_safe('Update failed', $e);
    }
}

if ($action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    $v = db_fetch_one(
        "SELECT * FROM `{$prefix}console_views` WHERE id = ? AND owner_user_id IS NULL", [$id]
    );
    if (!$v) { json_error('View not found', 404); }
    try {
        db_query("DELETE FROM `{$prefix}console_view_strips` WHERE view_id = ?", [$id]);
        db_query("DELETE FROM `{$prefix}console_views` WHERE id = ?", [$id]);
        audit_log('config', 'console.view_delete', 'console_views', $id, "Console view \"{$v['name']}\" deleted");
        json_response(['ok' => true, 'views' => console_views_with_strips()]);
    } catch (Exception $e) {
        json_error_safe('Delete failed', $e);
    }
}

if ($action === 'save_strips') {
    $id = (int) ($input['id'] ?? 0);
    $v = db_fetch_one(
        "SELECT * FROM `{$prefix}console_views` WHERE id = ? AND owner_user_id IS NULL", [$id]
    );
    if (!$v) { json_error('View not found', 404); }
    $strips = $input['strips'] ?? null;
    if (!is_array($strips)) { json_error('strips array is required'); }
    if (count($strips) > 64) { json_error('Too many strips (max 64)'); }

    // Validate every strip BEFORE touching the table.
    $clean = [];
    $overrideKeys = ['label', 'short_label', 'color'];
    foreach ($strips as $i => $s) {
        $chId = (int) ($s['channel_id'] ?? 0);
        $ch = channel_get($chId);
        if (!$ch) { json_error("Strip $i: unknown channel id $chId"); }
        $ov = [];
        foreach ($overrideKeys as $k) {
            if (!isset($s['overrides'][$k])) { continue; }
            $val = trim((string) $s['overrides'][$k]);
            if ($val === '') { continue; }
            if ($k === 'color' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
                json_error("Strip $i: invalid $k");
            }
            $ov[$k] = substr($val, 0, $k === 'label' ? 120 : 24);
        }

        // Strip rectangle on the view canvas (12-col outer grid).
        $lay = is_array($s['layout'] ?? null) ? $s['layout'] : [];
        $layout = [
            'x' => max(0, min(11, (int) ($lay['x'] ?? 0))),
            'y' => max(0, min(500, (int) ($lay['y'] ?? 0))),
            'w' => max(1, min(12, (int) ($lay['w'] ?? 3))),
            'h' => max(4, min(100, (int) ($lay['h'] ?? 14))),
        ];
        if ($layout['x'] + $layout['w'] > 12) { $layout['w'] = 12 - $layout['x']; }

        // Positioned components on the strip's inner grid. Invalid types /
        // incapable components / bad colours reject the publish outright —
        // a published view must never contain a dead or malformed control.
        $reqComps = is_array($s['components'] ?? null) ? $s['components'] : [];
        if (count($reqComps) > 24) { json_error("Strip $i: too many components (max 24)"); }
        $components = [];
        foreach ($reqComps as $ci => $c) {
            if (!is_array($c)) { json_error("Strip $i component $ci: malformed"); }
            $cc = console_component_clean($c, $ch['capabilities']);
            if ($cc === null) {
                json_error("Strip $i component $ci: invalid or not supported by this channel");
            }
            $components[] = $cc;
        }

        $clean[] = [
            'channel_id' => $chId,
            'width'      => ($layout['w'] >= 6) ? 2 : 1, // legacy column for pre-b2.5 readers
            'layout'     => json_encode($layout),
            'overrides'  => $ov ? json_encode($ov) : null,
            'components' => $components ? json_encode($components) : null,
        ];
    }

    try {
        db_query("DELETE FROM `{$prefix}console_view_strips` WHERE view_id = ?", [$id]);
        foreach ($clean as $pos => $s) {
            db_query(
                "INSERT INTO `{$prefix}console_view_strips`
                    (view_id, channel_id, position, width, layout_json, overrides_json, controls_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$id, $s['channel_id'], $pos, $s['width'], $s['layout'], $s['overrides'], $s['components']]
            );
        }
        db_query("UPDATE `{$prefix}console_views` SET updated_at = NOW() WHERE id = ?", [$id]);
        audit_log('config', 'console.view_publish', 'console_views', $id,
            "Console view \"{$v['name']}\" published (" . count($clean) . " strips)");
        json_response(['ok' => true, 'views' => console_views_with_strips()]);
    } catch (Exception $e) {
        json_error_safe('Save failed', $e);
    }
}

json_error('Unknown action');
