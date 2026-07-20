<?php
/**
 * Shared "no empty response body" harness for JSON API endpoints.
 *
 * Problem the beta testers keep hitting (issues #27, #28, #32,
 * potentially others): an endpoint fatals somewhere deep — a bad
 * require, a MySQL "server has gone away", a class-not-found because
 * composer hasn't been run — and PHP terminates with an empty
 * response body. The browser then shows:
 *
 *     Unexpected end of JSON input
 *     Failed to execute 'json' on 'Response': Unexpected end of JSON input
 *
 * ... which is unhelpful to both the operator (no clue what's wrong)
 * and the maintainer (no error surfaced to the client).
 *
 * This file, included FIRST in an API endpoint before any other
 * require, hardens the response contract:
 *
 *   1. `display_errors` is disabled so a WARN in a downstream include
 *      can't leak HTML into the JSON stream.
 *   2. A shutdown handler catches E_ERROR / E_PARSE / E_CORE_ERROR /
 *      E_COMPILE_ERROR / E_USER_ERROR — anything that terminates PHP
 *      early — and emits a proper JSON 500 with a generic message.
 *      The actual error goes to the server error log for maintainer
 *      follow-up (never leaked to the client — no file paths, no
 *      stack traces).
 *
 * Use:
 *   require_once __DIR__ . '/../inc/json-safe.php';
 *   // ... then the endpoint's normal auth / rbac / business logic.
 *
 * Safe to include multiple times: the shutdown_function is
 * guarded by a static flag so double-inclusion doesn't re-register.
 */

if (!function_exists('_json_safe_installed')) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    function _json_safe_installed(): bool { return true; }

    register_shutdown_function(function () {
        $err = error_get_last();
        if (!$err) return;
        $fatalTypes = [
            E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR,
        ];
        if (!in_array($err['type'], $fatalTypes, true)) return;

        error_log(sprintf(
            '[json-safe fatal] %s at %s:%d',
            (string) ($err['message'] ?? ''),
            (string) ($err['file']    ?? ''),
            (int)    ($err['line']    ?? 0)
        ));

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        // Don't leak filenames / line numbers to the client.
        echo json_encode([
            'error' => 'Server error. Check server logs and try again.',
        ]);
    });
}
