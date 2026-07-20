<?php
/**
 * settings-secrets.php — classify which `settings` keys hold secrets.
 *
 * Secret values (API tokens, passwords, webhook URLs) must NEVER be sent to
 * the browser. api/config-admin.php's `GET settings` returns a `<key>_set`
 * boolean for these instead of the value; the UI shows "stored / not set" and
 * the save path keeps the stored value when the field is left blank.
 *
 * Kept in its own include (not inline in the endpoint) so it is unit-testable
 * — see tests/test_settings_secret_masking.php.
 */

if (!function_exists('is_secret_setting_key')) {

    // Explicit secret keys (match the data-secret form fields in settings.php).
    // The suffix backstop below masks future secret keys by default, but listing
    // the known ones keeps intent clear and survives renamed suffixes.
    function _secret_setting_keys(): array {
        return [
            'sms_twilio_token', 'sms_bulkvs_api_key', 'sms_bulkvs_secret',
            'sms_pushbullet_token', 'sms_generic_api_key',
            'smtp_pass', 'slack_token', 'slack_webhook',
        ];
    }

    /**
     * True if the given settings key holds a secret and must be masked before
     * being returned to a client.
     */
    function is_secret_setting_key(string $name): bool {
        if (in_array($name, _secret_setting_keys(), true)) return true;
        // Suffix backstop: anything that looks like a credential is masked so a
        // newly-added secret setting is safe by default. Non-secret keys (e.g.
        // sms_twilio_sid, push_vapid_public, smtp_host) don't match.
        return (bool) preg_match(
            '/(_token|_secret|_password|_passwd|_pass|_api_key|_apikey|_auth_token|_webhook|_private)$/i',
            $name
        );
    }
}
