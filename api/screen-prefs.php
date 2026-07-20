<?php
/**
 * NewUI v4.0 API — Per-user screen column prefs (Phase 17).
 *
 * GET  ?screen=units              → current merged prefs
 * POST {screen, columns, sort}    → save
 * POST {screen, reset:true}       → reset to defaults
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/screen-prefs.php';

ini_set('display_errors', '0');

$uid    = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) json_error('Auth required', 401);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $screen = (string) ($_GET['screen'] ?? '');
    if ($screen === '') json_error('screen required');
    json_response(['prefs' => prefs_get($uid, $screen)]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('CSRF', 403);
    }
    $screen = (string) ($input['screen'] ?? '');
    if ($screen === '') json_error('screen required');

    if (!empty($input['reset'])) {
        if (prefs_reset($uid, $screen)) json_response(['ok' => true]);
        else                            json_error('reset failed');
    }

    $prefs = [
        'columns' => isset($input['columns']) && is_array($input['columns']) ? $input['columns'] : [],
        'sort'    => isset($input['sort']) && is_array($input['sort']) ? $input['sort'] : ['col' => '', 'dir' => 'asc'],
        'options' => isset($input['options']) && is_array($input['options']) ? $input['options'] : [],
    ];
    if (prefs_set($uid, $screen, $prefs)) {
        json_response(['ok' => true, 'prefs' => prefs_get($uid, $screen)]);
    }
    json_error('save failed');
}

json_error('Method not allowed', 405);
