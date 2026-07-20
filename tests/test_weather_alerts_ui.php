<?php
/**
 * Phase 112 — Weather-alert UI wiring (static guards).
 *
 * Guards the admin page, sidebar link, notification-tray + audio-alert hooks,
 * and the config controller against silent breakage. Static source assertions
 * (no headless DOM). Usage: php tests/test_weather_alerts_ui.php
 */
$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Phase 112 weather-alert UI wiring ===\n\n";

// ── Admin page ─────────────────────────────────────────────────────────────
$page = rd($base . '/weather-alerts.php');
t('weather-alerts.php RBAC-gated on action.manage_weather_alerts',
    $page !== false && strpos($page, "rbac_can('action.manage_weather_alerts')") !== false);
t('weather-alerts.php has master switch + UA + area/rule tables + test tools',
    $page !== false &&
    strpos($page, 'id="wxEnabled"') !== false &&
    strpos($page, 'id="wxUaContact"') !== false &&
    strpos($page, 'id="wxAreaRows"') !== false &&
    strpos($page, 'id="wxRuleRows"') !== false &&
    strpos($page, 'id="wxTestFixture"') !== false &&
    strpos($page, 'id="wxLoadMn"') !== false);
t('weather-alerts.php loads the controller + csrf token',
    $page !== false &&
    strpos($page, 'assets/js/weather-config.js') !== false &&
    strpos($page, 'id="csrfToken"') !== false);
t('weather-alerts.php exposes the OFF-by-default framing',
    $page !== false && stripos($page, 'off by default') !== false);

// ── Sidebar link ───────────────────────────────────────────────────────────
$side = rd($base . '/inc/config-sidebar.php');
t('config-sidebar links weather-alerts.php gated on the permission',
    $side !== false &&
    (bool) preg_match("/rbac_can\('action\.manage_weather_alerts'\).*?_cfg_link\('weather-alerts',\s*'weather-alerts\.php'/s", $side));

// ── Notification tray ──────────────────────────────────────────────────────
$tray = rd($base . '/assets/js/notification-tray.js');
t('notification-tray defines a weather:alert card type',
    $tray !== false && strpos($tray, "'weather:alert'") !== false);
t('notification-tray always-shows weather: events (no origin-user skip)',
    $tray !== false && strpos($tray, "eventType.indexOf('weather:') === 0") !== false);
t('notification-tray has a weather:alert describe case',
    $tray !== false && (bool) preg_match("/case 'weather:alert':/", $tray));

// ── Audio chime ────────────────────────────────────────────────────────────
$audio = rd($base . '/assets/js/audio-alerts.js');
t('audio-alerts plays a tone on weather:alert',
    $audio !== false && (bool) preg_match("/EventBus\.on\('weather:alert'/", $audio));

// ── Config controller ──────────────────────────────────────────────────────
$js = rd($base . '/assets/js/weather-config.js');
$needActions = ['config', 'areas', 'rules', 'save_settings', 'save_area', 'save_rule',
                'delete_area', 'delete_rule', 'test_fixture', 'dry_run', 'load_minnesota_example'];
$missing = [];
foreach ($needActions as $a) { if ($js === false || strpos($js, "'" . $a . "'") === false) $missing[] = $a; }
t('weather-config wires all API actions (' . implode(',', $needActions) . ')', empty($missing));
t('weather-config renders row data DOM-safe (textContent, not innerHTML)',
    $js !== false && strpos($js, '.textContent') !== false && strpos($js, '.innerHTML') === false);
t('weather-config pulls CSRF from #csrfToken',
    $js !== false && strpos($js, "getElementById('csrfToken')") !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
