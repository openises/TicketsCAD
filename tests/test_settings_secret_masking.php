<?php
/**
 * test_settings_secret_masking.php — guards that the `settings` GET never
 * leaks secret values to the browser.
 *
 * Background: api/config-admin.php's `GET settings` used to return every
 * settings row's value in cleartext (Twilio token, SMTP password, Slack
 * webhook, …). It now returns a `<key>_set` boolean for secret keys instead
 * (inc/settings-secrets.php). This test locks that in.
 */
$root = dirname(__DIR__);
require_once $root . '/inc/settings-secrets.php';

$pass = 0; $fail = 0; $fails = [];
function ok($cond, $msg, &$pass, &$fail, &$fails) {
    if ($cond) { $pass++; } else { $fail++; $fails[] = $msg; }
}

// 1. Known secret keys must be classified secret.
$secret = ['sms_twilio_token','sms_bulkvs_api_key','sms_bulkvs_secret','sms_pushbullet_token',
           'sms_generic_api_key','smtp_pass','slack_token','slack_webhook',
           // suffix backstop should also catch these hypothetical future keys:
           'radio_ai_api_key','some_new_password','vendor_auth_token','x_private'];
foreach ($secret as $k) ok(is_secret_setting_key($k), "expected SECRET: $k", $pass, $fail, $fails);

// 2. Non-secret keys must NOT be masked (would break the UI / hide real data).
$plain = ['sms_provider','sms_from','sms_twilio_sid','smtp_host','smtp_port','smtp_user',
          'email_from','email_from_name','slack_mode','slack_channel','push_vapid_public',
          'sms_generic_url','sms_generic_method'];
foreach ($plain as $k) ok(!is_secret_setting_key($k), "expected PLAIN: $k", $pass, $fail, $fails);

// 3. Source guards: the endpoint must actually apply the masking, and the JS
//    secret loaders must not push a returned value into a data-secret field.
$endpoint = @file_get_contents($root . '/api/config-admin.php');
ok($endpoint !== false && strpos($endpoint, 'is_secret_setting_key') !== false,
   'config-admin.php GET settings must call is_secret_setting_key()', $pass, $fail, $fails);

$js = @file_get_contents($root . '/assets/js/config.js');
if ($js !== false) {
    // Each secret-aware loader (SMS, SMTP, Slack) reads a <key>_set flag to set
    // the placeholder — one `key + '_set'` per loader.
    $n = preg_match_all("/key \\+ '_set'\\]/", $js);
    ok($n >= 3, "expected >=3 secret-aware loaders reading _set flag (found $n)", $pass, $fail, $fails);
    // And a data-secret field must never be populated from a returned value.
    ok(preg_match_all("/getAttribute\\('data-secret'\\) === '1'\\)\\s*\\{[\\s\\S]{0,300}?el\\.value = '';/", $js) >= 3,
       'each secret loader must clear el.value (never inject a secret value)', $pass, $fail, $fails);
} else {
    ok(false, 'config.js unreadable', $pass, $fail, $fails);
}

foreach ($fails as $f) fwrite(STDERR, "FAIL: $f\n");
echo "$pass/" . ($pass + $fail) . " settings-secret-masking assertions passed\n";
exit($fail === 0 ? 0 : 1);
