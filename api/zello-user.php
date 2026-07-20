<?php
/**
 * NewUI v4.0 API - Per-User Zello Configuration
 *
 * GET  /api/zello-user.php  — Get calling user's Zello preferences
 * POST /api/zello-user.php  — Upsert calling user's Zello preferences
 *   Body: { "zello_username": "...", "zello_password": "...",
 *           "enabled": 0|1, "ptt_key": "Space", "auto_connect": 0|1,
 *           "play_sounds": 0|1, "csrf_token": "..." }
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $row = db_fetch_one(
            "SELECT `user_id`, `zello_username`, `enabled`, `ptt_key`,
                    `auto_connect`, `play_sounds`, `updated`
             FROM `{$prefix}zello_user_config`
             WHERE `user_id` = ?",
            [$current_user_id]
        );

        if (!$row) {
            // Return defaults
            $row = [
                'user_id'        => $current_user_id,
                'zello_username'  => '',
                'enabled'         => 0,
                'ptt_key'         => 'Space',
                'auto_connect'    => 1,
                'play_sounds'     => 1,
                'updated'         => null,
            ];
        }

        json_response(['config' => $row]);
    } catch (Exception $e) {
        json_error('Failed to load user Zello config: ' . $e->getMessage(), 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON body');
    }

    // CSRF check
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    $username    = trim($input['zello_username'] ?? '');
    $password    = trim($input['zello_password'] ?? '');
    $enabled     = (int) ($input['enabled'] ?? 0);
    $pttKey      = trim($input['ptt_key'] ?? 'Space');
    $autoConnect = (int) ($input['auto_connect'] ?? 1);
    $playSounds  = (int) ($input['play_sounds'] ?? 1);

    if ($pttKey === '') $pttKey = 'Space';

    try {
        // Check if row exists
        $existing = db_fetch_one(
            "SELECT `user_id` FROM `{$prefix}zello_user_config` WHERE `user_id` = ?",
            [$current_user_id]
        );

        if ($existing) {
            // Update — only update password if provided
            if ($password !== '') {
                $sql = "UPDATE `{$prefix}zello_user_config` SET
                        `zello_username` = ?, `zello_password` = ?, `enabled` = ?,
                        `ptt_key` = ?, `auto_connect` = ?, `play_sounds` = ?,
                        `updated` = NOW()
                        WHERE `user_id` = ?";
                db_query($sql, [$username, $password, $enabled, $pttKey, $autoConnect, $playSounds, $current_user_id]);
            } else {
                $sql = "UPDATE `{$prefix}zello_user_config` SET
                        `zello_username` = ?, `enabled` = ?,
                        `ptt_key` = ?, `auto_connect` = ?, `play_sounds` = ?,
                        `updated` = NOW()
                        WHERE `user_id` = ?";
                db_query($sql, [$username, $enabled, $pttKey, $autoConnect, $playSounds, $current_user_id]);
            }
        } else {
            // Insert
            $sql = "INSERT INTO `{$prefix}zello_user_config`
                    (`user_id`, `zello_username`, `zello_password`, `enabled`,
                     `ptt_key`, `auto_connect`, `play_sounds`, `updated`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            db_query($sql, [$current_user_id, $username, $password, $enabled, $pttKey, $autoConnect, $playSounds]);
        }

        json_response(['saved' => true]);
    } catch (Exception $e) {
        json_error('Failed to save: ' . $e->getMessage(), 500);
    }
}

json_error('Method not allowed', 405);
