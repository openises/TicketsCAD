<?php
/**
 * NewUI v4.0 API - Theme
 *
 * GET  /api/theme.php           - Get current theme + CSS variables
 * POST /api/theme.php           - Toggle day/night
 *   Body: { "theme": "Day" | "Night" }
 */

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $theme = $_SESSION['day_night'] ?? 'Day';
    $colors = get_all_css($theme);

    json_response([
        'theme'  => $theme,
        'colors' => $colors,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // CSRF (F-012) — accept token in body or X-CSRF-Token header
    $csrfTok = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_verify((string) $csrfTok)) {
        json_error('Invalid CSRF token', 403);
    }

    $theme = ($input['theme'] ?? '') === 'Night' ? 'Night' : 'Day';

    $_SESSION['day_night'] = $theme;
    $colors = get_all_css($theme);

    json_response([
        'theme'  => $theme,
        'colors' => $colors,
    ]);
}

json_error('Method not allowed', 405);
