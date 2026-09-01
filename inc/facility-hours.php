<?php
/**
 * GH#125 (rjonesbsink, 2026-08-31) — facilities.opening_hours has been
 * read and displayed since before v4 (api/facilities.php's "is it open
 * right now" card, api/facility-detail.php's hours_today/is_open) but
 * had no writer anywhere in the tree: no INSERT/UPDATE touched it, no
 * form field existed, inc/facility-write.php didn't accept it. A v3
 * install carries real per-day schedules (the reporter's own v3 install
 * has 31 populated facilities); a fresh v4 install could display the
 * feature but never populate it.
 *
 * Format (unchanged from v3 — a base64-encoded, PHP-serialized array
 * indexed 0-6 for Sun-Sat, each day [0 => 'on'|'off', 1 => open 'HH:MM',
 * 2 => close 'HH:MM']), preserved deliberately rather than migrated to a
 * new representation: api/facilities.php and api/facility-detail.php
 * both already parse this exact shape, and an upgraded install's real
 * historical data is already stored in it. Changing formats would mean
 * teaching both existing readers to handle two shapes for no gain over
 * just writing the shape they already read.
 *
 * facility_encode_hours() NEVER calls unserialize() — it builds the array
 * fresh from validated day/enabled/open/close inputs and only ever
 * serializes outward. This deliberately avoids the "validate by round-
 * tripping through unserialize()" trap: unserialize() on a value that
 * ultimately traces back to client input is a PHP object-injection
 * surface (a class implementing __wakeup()/__destruct() could execute
 * code on deserialization) even if the immediate caller only intends to
 * re-serialize a plain array. serialize() has no such risk in either
 * direction.
 */

/**
 * Decode facilities.opening_hours into a full 7-day array (Sun-Sat) for
 * an editor UI. Never used to make an "is it open" decision (that stays
 * exactly as api/facilities.php / api/facility-detail.php already
 * compute it) — this is purely the read-back for the write form.
 *
 * @return array 7 entries (indexed 0-6), each ['enabled'=>bool,'open'=>'HH:MM','close'=>'HH:MM']
 */
function facility_decode_hours(?string $raw): array {
    $default = [];
    for ($i = 0; $i < 7; $i++) {
        $default[$i] = ['enabled' => false, 'open' => '09:00', 'close' => '17:00'];
    }
    if ($raw === null || $raw === '') {
        return $default;
    }
    $decoded = @unserialize(@base64_decode($raw));
    if (!is_array($decoded)) {
        return $default;
    }
    $out = $default;
    for ($i = 0; $i < 7; $i++) {
        $day = $decoded[$i] ?? null;
        if (!is_array($day)) { continue; }
        $on = (($day[0] ?? '') === 'on');
        $open = is_string($day[1] ?? null) ? $day[1] : '09:00';
        $close = is_string($day[2] ?? null) ? $day[2] : '17:00';
        $out[$i] = [
            'enabled' => $on,
            'open'    => facility_hours_normalize_time($open, '09:00'),
            'close'   => facility_hours_normalize_time($close, '17:00'),
        ];
    }
    return $out;
}

/**
 * Build the v3-compatible serialized blob from editor input. $days is an
 * array of up to 7 entries, keyed or indexed 0-6, each with
 * enabled/open/close — exactly facility_decode_hours()'s own return
 * shape, so a round-trip (decode -> edit -> encode) is lossless for
 * anything the editor actually exposes.
 *
 * Every field is validated/coerced here — never trusts the caller's
 * shape — since this ultimately becomes what api/facilities.php's live
 * "is it open" check parses back out.
 */
function facility_encode_hours(array $days): string {
    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $day = is_array($days[$i] ?? null) ? $days[$i] : [];
        $on = !empty($day['enabled']);
        $open = facility_hours_normalize_time((string) ($day['open'] ?? '09:00'), '09:00');
        $close = facility_hours_normalize_time((string) ($day['close'] ?? '17:00'), '17:00');
        $week[$i] = [$on ? 'on' : 'off', $open, $close];
    }
    return base64_encode(serialize($week));
}

/**
 * Coerce to a strict HH:MM 24-hour time string, falling back to $default
 * on anything malformed. Never trusts client-controlled input to land in
 * the serialized blob unvalidated.
 */
function facility_hours_normalize_time(string $t, string $default): string {
    $t = trim($t);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t)) {
        return $t;
    }
    return $default;
}
