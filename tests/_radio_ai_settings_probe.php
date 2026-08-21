<?php
/**
 * Cold-process probe for tests/test_radio_ai_settings_panel.php.
 *
 * Same reason tests/_p132_settings_probe.php, tests/_p132_probe.php, and
 * tests/_par_setting_probe.php exist: get_variable() caches every `settings`
 * row in a function-static array on its FIRST call in a process and never
 * re-reads the table again. Reading a just-written radio_ai_* value back in
 * the SAME process the write happened in would prove nothing — it might be
 * reading the pre-write cache state by coincidence of call order, or reading
 * the fresh DB row for reasons unrelated to whether it's the same underlying
 * store. This probe reads in a genuinely separate `php` interpreter process,
 * once per read, so every call sees the settings table exactly as the
 * listener daemon (inc/radio_ai_listener.php, a long-running separate
 * process itself) would.
 *
 * Reads through BOTH consumers of the `settings` table this feature relies
 * on: get_variable() (inc/functions.php — the generic reader every OTHER
 * admin panel's consumer code uses) and radio_ai_setting() (inc/radio_ai_
 * client.php — the reader inc/radio_ai_listener.php and inc/radio_ai_
 * client.php actually call). If a future refactor ever pointed one of them
 * at a different table/column (this project's own documented "two settings
 * stores" pitfall — `settings` vs `config`, `get_variable()` vs
 * `get_setting()`), this probe is what would catch the two readers
 * disagreeing.
 *
 * File name starts with `_` so tools/test_all.php, which globs test_*.php,
 * does not try to run it as a test.
 *
 * Usage:
 *   php tests/_radio_ai_settings_probe.php <setting_name>
 *     -> JSON: {"get_variable": <raw return>, "radio_ai_setting": <raw return>}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/radio_ai_client.php';   // pulls in inc/db.php + defines radio_ai_setting()

$name = $argv[1] ?? '';
if ($name === '') {
    fwrite(STDERR, "usage: php _radio_ai_settings_probe.php <setting_name>\n");
    exit(1);
}

$viaGetVariable   = get_variable($name);
$viaRadioAiHelper = radio_ai_setting($name, '__RADIO_AI_PROBE_DEFAULT__');

echo json_encode([
    // get_variable() returns `false` (not null) when the row is absent —
    // JSON-encode that faithfully rather than coercing to null, so the
    // caller can tell "absent" apart from a literal empty string.
    'get_variable'     => $viaGetVariable === false ? '__ABSENT__' : $viaGetVariable,
    'radio_ai_setting' => $viaRadioAiHelper,
]);
