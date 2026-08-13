<?php
/**
 * NewUI v4.0 — API fatal-to-JSON guard.
 *
 * THE PROBLEM THIS EXISTS TO SOLVE
 * --------------------------------
 * A beta tester deleted a mesh bridge (2026-07-28) and saw a red
 *
 *     Failed to execute 'json' on 'Response': Unexpected end of JSON input
 *
 * ...even though the delete had worked. Root cause: api/mesh.php passed an
 * array where audit_log() declares `string $summary`. That raises a
 * **TypeError**, and TypeError extends **Error**, not **Exception** — so the
 * `catch (Exception $e)` that wraps almost every API handler did not catch it.
 * Every endpoint sets `display_errors = 0` (correctly: a stray warning would
 * corrupt the JSON stream), so PHP terminated AFTER the database writes had
 * committed and returned an EMPTY BODY. `response.json()` in the browser then
 * fails on empty input, and the operator cannot tell whether the action
 * half-happened.
 *
 * There are ~825 `catch (Exception ...)` blocks across api/. Broadening them
 * all to `catch (Throwable ...)` would be WORSE than the disease — bugs that
 * currently fail loudly would start being silently swallowed and returned as
 * tidy little errors. So we do not touch the catches. Instead we harden the
 * OUTER boundary: whatever kills the request, the client still receives valid
 * JSON with an HTTP 500 and an opaque reference id it can quote.
 *
 * WHAT IT INSTALLS
 * ----------------
 *   set_exception_handler()     — anything uncaught, including Error/TypeError
 *                                 (which no `catch (Exception)` can reach).
 *   register_shutdown_function() — E_ERROR / E_PARSE / E_CORE_ERROR /
 *                                 E_COMPILE_ERROR / E_USER_ERROR, i.e. the
 *                                 fatals no handler can intercept at all
 *                                 (memory exhaustion, call to undefined
 *                                 function, failed require, ...).
 *
 * SAFETY PROPERTIES (all deliberate — see tests/test_api_guard.php)
 * -----------------------------------------------------------------
 *   * NEVER double-emits. If the response body has already started — headers
 *     flushed, an output buffer holding bytes, or json_response() having
 *     already run — both handlers return without writing anything. That
 *     protects the non-JSON endpoints too (SSE stream.php, CSV exports,
 *     backup downloads, dmr audio): a fatal mid-stream appends nothing.
 *   * NEVER leaks internals. The exception class, message, file, line and
 *     stack trace go to error_log() only. The client gets a fixed sentence
 *     plus an 8-hex-char reference that correlates the two.
 *   * NEVER fires for HTML pages. Nothing installs it implicitly — it is
 *     wired explicitly into the API bootstraps (api/auth.php, the handful of
 *     endpoints that authenticate by bearer token instead, and
 *     api/external/v1/_auth.php). config.php, which HTML pages share, is
 *     untouched.
 *   * Idempotent. Repeated api_guard_install() calls register nothing twice.
 *
 * Wire-in (already done — listed so a future endpoint author knows the rule):
 *   api/auth.php                  → every session-authenticated endpoint
 *   api/external/v1/_auth.php     → every bearer-token external API endpoint
 *   api/mesh.php, api/owntracks-config.php   (auth.php only on the admin path)
 *   api/atak-ingest.php, api/dmr-ingest.php, api/feed.php,
 *   api/push-vapid-public-key.php            (no auth.php at all)
 *   api/public-board.php (Phase 138)         — public, unauthenticated by
 *                                               design (specs/security/
 *                                               constitution.md rule 2)
 *
 * A NEW api/*.php that does not require auth.php MUST call api_guard_install()
 * itself. tests/test_api_guard.php fails the suite if one forgets.
 */

if (!function_exists('api_guard_install')) {

    /**
     * The opaque per-request reference id. Minted once, shown to the client,
     * and stamped on every error_log() line the guard writes so a maintainer
     * can grep the log for what the user quoted. Carries no information about
     * the failure by itself.
     */
    function api_guard_ref(): string
    {
        static $ref = null;
        if ($ref === null) {
            try {
                $ref = bin2hex(random_bytes(4));
            } catch (Throwable $e) {
                // random_bytes can throw if the CSPRNG is unavailable; the ref
                // is a log-correlation token, not a secret, so a weaker source
                // is acceptable rather than failing the error path itself.
                $ref = substr(md5(uniqid('', true)), 0, 8);
            }
        }
        return $ref;
    }

    /**
     * Has this request already begun producing a response body?
     *
     * Three independent signals, because any one of them alone has a hole:
     *   1. the flag json_response() sets — true even when output buffering
     *      hides the echo from headers_sent();
     *   2. headers_sent() — true once anything has actually been flushed;
     *   3. a non-empty output buffer — the case where output_buffering is on
     *      in php.ini (it is, on many shared hosts) so bytes are pending but
     *      headers have not gone out yet. Appending JSON there would corrupt
     *      a CSV/XML/SSE body that was already half-written.
     */
    function api_guard_output_started(): bool
    {
        if (!empty($GLOBALS['__api_guard_body_sent'])) {
            return true;
        }
        if (headers_sent()) {
            return true;
        }
        foreach (ob_get_status(true) as $buffer) {
            if (!empty($buffer['buffer_used'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Write the JSON 500. Callers MUST have checked api_guard_output_started()
     * first; this function does not re-check (it sets the flag so a later
     * handler in the same request sees the body as sent).
     */
    function api_guard_emit(): void
    {
        $ref = api_guard_ref();

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        if (isset($GLOBALS['__ext_api_request_id'])) {
            // api/external/v1 speaks its own envelope (inc/external-auth.php
            // ext_api_error()). Integrators parse `ok` / `error`; give them the
            // shape they already handle rather than a foreign one.
            $payload = [
                'ok'          => false,
                'api_version' => 'v1',
                'request_id'  => $GLOBALS['__ext_api_request_id'],
                'error'       => 'server_error',
                'ref'         => $ref,
            ];
        } else {
            $payload = [
                'error' => 'Server error — the request did not complete. '
                         . 'Check the server error log for reference ' . $ref . '.',
                'ref'   => $ref,
            ];
        }

        $GLOBALS['__api_guard_body_sent'] = true;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Install both handlers. Safe to call any number of times.
     */
    function api_guard_install(): void
    {
        static $installed = false;
        if ($installed) {
            return;
        }
        $installed = true;

        // ── 1. Uncaught Throwable (this is the audit_log TypeError case) ──
        // Note: once an exception handler is registered PHP no longer writes
        // its own "PHP Fatal error: Uncaught ..." line, so we log the class,
        // message, origin AND trace ourselves — otherwise installing this
        // guard would COST diagnostics rather than add them.
        set_exception_handler(function ($e) {
            /** @var Throwable $e */
            error_log(sprintf(
                '[api_guard ref=%s] Uncaught %s in %s: %s at %s:%d%s%s',
                api_guard_ref(),
                get_class($e),
                (string) ($_SERVER['SCRIPT_NAME'] ?? 'cli'),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                PHP_EOL,
                $e->getTraceAsString()
            ));

            if (api_guard_output_started()) {
                return;   // a body is already on the wire — do not corrupt it
            }
            api_guard_emit();
        });

        // ── 2. True fatals, which no handler can intercept ──
        register_shutdown_function(function () {
            $err = error_get_last();
            if (!$err) {
                return;
            }
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($err['type'], $fatalTypes, true)) {
                return;   // warnings/notices are not our business
            }

            error_log(sprintf(
                '[api_guard ref=%s] Fatal in %s: %s at %s:%d',
                api_guard_ref(),
                (string) ($_SERVER['SCRIPT_NAME'] ?? 'cli'),
                (string) ($err['message'] ?? ''),
                (string) ($err['file'] ?? ''),
                (int)    ($err['line'] ?? 0)
            ));

            if (api_guard_output_started()) {
                return;
            }
            api_guard_emit();
        });
    }
}
