<?php
/**
 * Load Composer's autoloader without letting a THIRD-PARTY deprecation reach the page.
 *
 * PHP 8.4 (stock on Debian 13) deprecates an implicitly nullable parameter, and
 * vendor/ratchet/pawl's `connect(..., LoopInterface $loop = null)` is exactly that.
 * The notice is raised while PHP compiles that file, which is when Composer's autoloader
 * pulls in its "files" list - i.e. during `require vendor/autoload.php`, on the first request
 * each Apache worker serves after a reload. On an install whose config.php has debug on
 * (NEWUI_DEBUG, which re-enables display_errors AFTER an API's own ini_set('display_errors',
 * '0')), that is HTML written in front of the JSON: Notification Rules failed to parse on
 * training after a deploy, once per reload, because loading the broker now loads the push
 * stack. Found by the Phase 155 live smoke.
 *
 * We cannot edit vendor/ (Composer owns it, and the SBOM records its hash), and a deprecation
 * in code we do not maintain is not something an operator can act on, so it is silenced for
 * the duration of the require ONLY. Deprecations in this application's own code are untouched.
 */

if (!function_exists('newui_require_vendor_autoload')) {
    /**
     * @return bool true when vendor/autoload.php exists and was loaded (or already was)
     */
    function newui_require_vendor_autoload(): bool
    {
        $file = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($file)) {
            return false;
        }
        $previous = error_reporting(error_reporting() & ~E_DEPRECATED);
        try {
            require_once $file;
        } finally {
            error_reporting($previous);
        }
        return true;
    }
}
