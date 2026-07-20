<?php
/**
 * Phase 22 (2026-06-11) — Per-install agency timezone setting.
 *
 * Adds settings.area_timezone so each install can declare the
 * timezone the agency operates in (the timezone displayed times
 * should appear in, and the one stamps go into the DB as).
 *
 * Does NOT change existing stored datetimes — they were stamped
 * with whatever timezone PHP was running at the time. Going forward,
 * new stamps use the configured timezone.
 *
 * If the row already exists with a valid IANA timezone, leave it
 * alone. Otherwise seed with 'America/New_York' to match the
 * pre-Phase-22 hardcoded default — admin changes via Settings UI.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 22 — area_timezone setting\n";
echo "================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$validZones = DateTimeZone::listIdentifiers();
$defaultZone = 'America/New_York';

try {
    $existing = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'area_timezone' LIMIT 1"
    );
    if ($existing === null || $existing === false || $existing === '') {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('area_timezone', ?)",
            [$defaultZone]
        );
        echo "[OK] Seeded area_timezone = '{$defaultZone}'\n";
        echo "     Change via Settings → Display Settings → Time Zone.\n";
    } elseif (!in_array($existing, $validZones, true)) {
        echo "[WARN] area_timezone is set to '{$existing}' which is not a valid IANA zone.\n";
        echo "       Falling back to '{$defaultZone}'.\n";
        db_query(
            "UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'area_timezone'",
            [$defaultZone]
        );
    } else {
        echo "[OK] area_timezone already set to '{$existing}'\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
