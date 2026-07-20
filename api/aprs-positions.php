<?php
/**
 * Phase 99g (2026-06-28) — APRS-IS position feed for aprs-map.php.
 *
 *   GET /api/aprs-positions.php
 *     Returns the most-recent unique callsign position for each station
 *     reported by the APRS-IS provider within the last `max_age_seconds`.
 *
 *   Query params:
 *     limit       — max stations to return (default 200, cap 1000)
 *     since_min   — only stations heard in the last N minutes (default 60)
 *
 *   Response:
 *     {
 *       provider: {id, code, name, enabled, last_seen_ago_sec},
 *       stations: [
 *         {callsign, lat, lng, altitude, speed, heading, reported_at,
 *          age_sec, raw}
 *       ],
 *       count: N,
 *       listener_status: 'unknown' | 'running' | 'stopped'
 *     }
 *
 * Auth: any logged-in user (map is part of dispatch view).
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$limit    = max(1, min(1000, (int) ($_GET['limit'] ?? 200)));
$sinceMin = max(1, min(1440, (int) ($_GET['since_min'] ?? 60)));

try {
    $provider = db_fetch_one(
        "SELECT id, code, name, enabled
           FROM `{$prefix}location_providers`
          WHERE code = 'aprs'
          LIMIT 1"
    );
    if (!$provider) {
        echo json_encode([
            'provider'        => null,
            'stations'        => [],
            'count'           => 0,
            'listener_status' => 'not_configured',
            'message'         => 'APRS-IS provider row not in location_providers — run sql/run_99g_aprs_provider.php',
        ]);
        exit;
    }

    // Most-recent position per unit_identifier (= callsign for APRS).
    // Subquery also computes report count per callsign in the
    // window so the list-view can show "how chatty is this station".
    $rows = db_fetch_all(
        "SELECT r1.unit_identifier AS callsign,
                r1.lat, r1.lng, r1.altitude, r1.speed, r1.heading,
                r1.reported_at, r1.raw_data,
                latest.report_count AS reports,
                TIMESTAMPDIFF(SECOND, r1.reported_at, UTC_TIMESTAMP()) AS age_sec
           FROM `{$prefix}location_reports` r1
           JOIN (
               SELECT unit_identifier,
                      MAX(reported_at)   AS max_at,
                      COUNT(*)           AS report_count
                 FROM `{$prefix}location_reports`
                WHERE provider_id = ?
                  AND reported_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
                GROUP BY unit_identifier
           ) latest
             ON latest.unit_identifier = r1.unit_identifier
            AND latest.max_at = r1.reported_at
          WHERE r1.provider_id = ?
          ORDER BY r1.reported_at DESC
          LIMIT ?",
        [(int) $provider['id'], $sinceMin, (int) $provider['id'], $limit]
    );

    // Last seen across the whole provider — feeds the "listener status"
    // widget. 2026-06-29 fix (Eric beta): use TIMESTAMPDIFF in SQL
    // instead of PHP time() - strtotime(). The Python listener stores
    // UTC strings; PHP strtotime() interprets them as LOCAL time,
    // adding the local-to-UTC offset (~5h on CDT). TIMESTAMPDIFF works
    // on MariaDB-internal datetime values with no TZ math, so it
    // matches the rest of the API which uses TIMESTAMPDIFF for age_sec.
    $listenerRow = db_fetch_one(
        "SELECT MAX(reported_at) AS last_seen,
                TIMESTAMPDIFF(SECOND, MAX(reported_at), UTC_TIMESTAMP()) AS last_seen_ago
           FROM `{$prefix}location_reports`
          WHERE provider_id = ?",
        [(int) $provider['id']]
    );
    $lastSeen    = $listenerRow['last_seen']     ?? null;
    $lastSeenAgo = isset($listenerRow['last_seen_ago']) ? (int) $listenerRow['last_seen_ago'] : null;
    $listenerStatus = 'not_configured';
    if ($lastSeen) {
        // Heuristic: a row within the last 5 minutes = listener actively
        // receiving. Older = listener down (or genuinely no traffic, but
        // APRS-IS national feed is never quiet for >2-3 min).
        $listenerStatus = ($lastSeenAgo < 300) ? 'running' : 'stopped';
    }

    // 2026-06-29 fix (Eric beta): also expose TRUE unique-callsign
    // count in the window, independent of the LIMIT cap on the
    // stations array. The previous `count` field was just count($rows)
    // which the Listener Status widget calls with limit=1 — so the
    // widget always reported "1 unique station heard". Keep `count`
    // as the rows-returned count for backwards compat; add
    // `unique_stations_in_window` for the true number.
    $uniqueInWindow = (int) db_fetch_value(
        "SELECT COUNT(DISTINCT unit_identifier)
           FROM `{$prefix}location_reports`
          WHERE provider_id = ?
            AND reported_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)",
        [(int) $provider['id'], $sinceMin]
    );

    // 2026-06-29 fix (Eric beta) — coerce DECIMAL columns to float
    // before JSON-encoding. PDO returns DECIMAL as a PHP string,
    // which json_encode emits as a JSON string ("45.123" not 45.123),
    // and the consuming JS calls .toFixed() on it → TypeError. Both
    // aprs-map.php and the embedded Settings → APRS → Map tab use
    // the same shape, so fixing here is the one-place fix.
    //
    // 2026-06-29 followup (Eric beta) — parse raw APRS frame for
    // rich list-view columns: destination, path, symbol, comment.
    // The frame format is CALL>DEST,PATH:PAYLOAD where PAYLOAD
    // starts with one of !=/=@ then has lat/symbol_table/lng/
    // symbol_char then free-text comment. Regex extraction is
    // good enough for ~95% of normal beacon traffic; weather and
    // telemetry frames get a generic 'comment' = the whole payload.
    // Phase 99h-v3 (2026-06-29) — APRS station classification +
    // weather data extraction. Eric beta: "Maybe we need to parse
    // the data and label stations as weather and add a column to
    // the table? Are there other values we could parse out? I
    // assume weather stations also tell us the temperature and
    // likely have other sensors?"
    //
    // Symbol code → human-readable station type. APRS has ~190
    // symbols across two tables ('/' primary, '\' alternate); we
    // cover the most common ~25. Unknowns return 'Other'.
    // Expanded after Eric beta 2026-06-29 — KC0EIG / AB0R / N0NAS-*
    // came through as 'Other' until we covered compressed/MIC-E
    // formats AND added their symbol codes (Yagi, Digipeater,
    // D-STAR node, Object).
    $STATION_TYPES = [
        // Weather + wx
        '/_' => 'Weather',     '\_' => 'Weather',
        '/W' => 'WX site',     '\W' => 'WX site',
        // Mobile / vehicles
        '/>' => 'Car',         '\>' => 'Car',
        '/<' => 'Motorcycle',  '\<' => 'Motorcycle',
        '/j' => 'Jeep',        '\j' => 'Jeep',
        '/k' => 'Truck',       '\k' => 'SUV',
        '/v' => 'Van',         '\v' => 'Van',
        '/U' => 'Bus',
        '/R' => 'RV',
        '/=' => 'Train',
        '/p' => 'Dog',         '\p' => 'Prediction',
        '/u' => 'Truck (18-w)',
        // Air / sea
        '/O' => 'Balloon',     '\O' => 'Balloon',
        '/Y' => 'Yagi',        '\Y' => 'Sailboat',
        '/s' => 'Power boat',  '\s' => 'Overlay-boat',
        '/X' => 'Helicopter',  '\h' => 'Helicopter',
        "/'" => 'Aircraft',    "\\'" => 'Aircraft',
        '/^' => 'Aircraft',    '\^' => 'Aircraft (large)',
        // Emergency
        '/a' => 'Ambulance',   '\a' => 'Ambulance',
        '/f' => 'Fire truck',  '\f' => 'Fire',
        '/!' => 'Police',      '\!' => 'Emergency',
        '/+' => 'Red Cross',   '\+' => 'Red Cross',
        '/o' => 'EOC',
        // Fixed / infrastructure
        '/-' => 'House',       '\-' => 'House (HF)',
        '/h' => 'Hospital',
        '/r' => 'Repeater',    '\r' => 'Repeater',
        '/&' => 'Igate',       '\&' => 'D-STAR node',
        '/I' => 'TCP/IP',
        '/[' => 'HT/Handheld', '\[' => 'HT/Handheld',
        '/#' => 'Digipeater',  '\#' => 'Digipeater',
        '/y' => 'Yagi (fixed)',
        '/Q' => 'Quake',
        '/`' => 'Dish',
        '/n' => 'Node',        '\n' => 'Node',
        '/E' => 'Eyeball',
        '/G' => 'Grid',
        // Misc commonly seen
        '/I' => 'TCP/IP',
        '/M' => 'MacAPRS',
        '/T' => 'SSTV',
    ];

    // APRS weather-payload parser. Format follows APRS spec section 12:
    //   _DDD/SSS  wind direction (deg) / wind speed (mph)
    //   gGGG      gust (mph)
    //   tTTT      temperature (F, signed — '-' for negative)
    //   rRRR      rain last 1h (1/100 inch)
    //   pPPP      rain last 24h (1/100 inch)
    //   PPPP      rain since midnight (1/100 inch) — capital P
    //   hHH       humidity (% — 00 means 100%)
    //   bBBBBB    barometric pressure (1/10 hPa, e.g. 10052 = 1005.2)
    //   sSSS      snowfall (1/100 inch) — optional
    // Fields after the position+symbol; only the wind dir/speed
    // appears in fixed position (immediately after symbol). The
    // rest can appear in any order. Some weather stations omit
    // sensors they don't have ('---' or '...' as placeholder).
    $parseWeather = function (string $payload): array {
        $wx = [];
        // Wind dir/speed: pattern _DDD/SSS (or symbol-prefixed)
        if (preg_match('#[/_](\d{3})/(\d{3})#', $payload, $m)) {
            $dir = (int) $m[1];
            $wx['wind_dir']       = ($dir === 0) ? null : $dir;
            $wx['wind_speed_mph'] = (int) $m[2];
        }
        if (preg_match('/g(\d{3})/', $payload, $m)) {
            $wx['wind_gust_mph'] = (int) $m[1];
        }
        if (preg_match('/t(-?\d{2,3})/', $payload, $m)) {
            $wx['temp_f'] = (int) $m[1];
        }
        if (preg_match('/h(\d{2})/', $payload, $m)) {
            $h = (int) $m[1];
            $wx['humidity'] = ($h === 0) ? 100 : $h;
        }
        if (preg_match('/b(\d{5})/', $payload, $m)) {
            $wx['pressure_hpa'] = ((int) $m[1]) / 10.0;
        }
        if (preg_match('/r(\d{3})/', $payload, $m)) {
            $wx['rain_1h_in'] = ((int) $m[1]) / 100.0;
        }
        if (preg_match('/p(\d{3})/', $payload, $m)) {
            $wx['rain_24h_in'] = ((int) $m[1]) / 100.0;
        }
        // Capital P is "rain since midnight" — must NOT match lowercase p.
        if (preg_match('/P(\d{3})/', $payload, $m)) {
            $wx['rain_today_in'] = ((int) $m[1]) / 100.0;
        }
        if (preg_match('/s(\d{3})/', $payload, $m)) {
            $wx['snow_24h_in'] = ((int) $m[1]) / 100.0;
        }
        return $wx;
    };

    foreach ($rows as &$r) {
        $r['lat']      = isset($r['lat'])      ? (float) $r['lat']      : null;
        $r['lng']      = isset($r['lng'])      ? (float) $r['lng']      : null;
        $r['altitude'] = isset($r['altitude']) ? (float) $r['altitude'] : null;
        $r['speed']    = isset($r['speed'])    ? (float) $r['speed']    : 0.0;
        $r['heading']  = isset($r['heading'])  ? (float) $r['heading']  : 0.0;
        $r['age_sec']  = (int) ($r['age_sec'] ?? 0);
        $r['reports']  = (int) ($r['reports'] ?? 1);

        // Parse the raw frame for rich-list columns.
        $raw = (string) ($r['raw_data'] ?? '');
        $r['destination']   = '';
        $r['path']          = '';
        $r['symbol']        = '';
        $r['comment']       = '';
        $r['station_type']  = 'Other';
        // Weather fields default to null so the client renders '—'.
        $r['wind_dir']       = null;
        $r['wind_speed_mph'] = null;
        $r['wind_gust_mph']  = null;
        $r['temp_f']         = null;
        $r['humidity']       = null;
        $r['pressure_hpa']   = null;
        $r['rain_1h_in']     = null;
        $r['rain_24h_in']    = null;
        $r['rain_today_in']  = null;

        if ($raw !== '') {
            if (preg_match('/^[^>]+>([^:]+):(.+)$/s', $raw, $m)) {
                $header  = $m[1];
                $payload = $m[2];
                $parts   = explode(',', $header, 2);
                $r['destination'] = $parts[0] ?? '';
                $r['path']        = $parts[1] ?? '';

                // Phase 99h-v4 (2026-06-29) — handle all three APRS
                // position formats. Eric beta: previous regex only
                // matched uncompressed (DDMM.MMN/DDDMM.MMW) format,
                // missing MIC-E and compressed-position packets. So
                // stations like KC0EIG (MIC-E) and AB0R (compressed)
                // fell through to station_type='Other' and never got
                // the weather-symbol motion guard.
                $dti = substr($payload, 0, 1);

                // Helper: normalize an APRS symbol-table identifier
                // byte. The wire format allows overlay characters
                // (0-9 or A-J) which mean "alternate table with this
                // char overlaid on the icon"; for classification we
                // collapse those to '\' (alternate).
                $normTable = function (string $t): string {
                    if ($t === '/' || $t === '\\') return $t;
                    $o = ord($t);
                    if (($o >= ord('A') && $o <= ord('J')) ||
                        ($o >= ord('0') && $o <= ord('9'))) {
                        return '\\';
                    }
                    return $t;
                };

                if ($dti === '`' || $dti === '\'') {
                    // MIC-E format. Per APRS spec section 10, bytes
                    // after DTI:
                    //   1-3  lng deg/min/min*100 (encoded)
                    //   4-6  speed + course encoded
                    //   7    symbol code (the icon character)
                    //   8    symbol table identifier
                    //   9+   comment (altitude, mfgr ID, weather)
                    if (strlen($payload) >= 9) {
                        $r['symbol']  = $normTable($payload[8]) . $payload[7];
                        $r['comment'] = trim(substr($payload, 9));
                    } else {
                        $r['comment'] = trim($payload);
                    }
                } elseif (in_array($dti, ['!', '=', '/', '@'], true)) {
                    // Uncompressed OR compressed position. For '/'
                    // and '@', skip 7-byte timestamp first.
                    $offset = ($dti === '/' || $dti === '@') ? 8 : 1;
                    $rest = substr($payload, $offset);

                    if (preg_match('/^[\d.]+[NS](.)[\d.]+[EW](.)/', $rest, $sm)) {
                        // Uncompressed: DDMM.MMN/DDDMM.MMW + symbol
                        $r['symbol']  = $normTable($sm[1]) . $sm[2];
                        $pos = strpos($rest, $sm[0]);
                        if ($pos !== false) {
                            $r['comment'] = trim(substr($rest, $pos + strlen($sm[0])));
                        }
                    } elseif (strlen($rest) >= 13) {
                        // Maybe compressed: byte 0 is table identifier
                        // (/, \, 0-9, or A-J). Use char-range check
                        // instead of regex — PCRE on some PHP builds
                        // chokes on \\ in a char class.
                        $tbl = $rest[0];
                        $tblOrd = ord($tbl);
                        $isTableChar = ($tbl === '/' || $tbl === '\\')
                                    || ($tblOrd >= ord('A') && $tblOrd <= ord('J'))
                                    || ($tblOrd >= ord('0') && $tblOrd <= ord('9'));
                        if ($isTableChar) {
                            // Compressed position (13 chars):
                            //   0     symbol_table
                            //   1-4   lat (base 91)
                            //   5-8   lng (base 91)
                            //   9     symbol_code
                            //   10-11 course/speed or range/alt
                            //   12    compression type
                            $r['symbol']  = $normTable($tbl) . $rest[9];
                            $r['comment'] = trim(substr($rest, 13));
                        } else {
                            $r['comment'] = trim($rest);
                        }
                    } else {
                        $r['comment'] = trim($rest);
                    }
                } elseif ($dti === ';') {
                    // Object report — skip 9-byte name + timestamp etc.
                    // Treat the position portion same as uncompressed.
                    if (preg_match('/[\d.]+[NS](.)[\d.]+[EW](.)/', $payload, $sm)) {
                        $r['symbol']  = $sm[1] . $sm[2];
                        $pos = strpos($payload, $sm[0]);
                        if ($pos !== false) {
                            $r['comment'] = trim(substr($payload, $pos + strlen($sm[0])));
                        }
                    } else {
                        $r['comment'] = trim($payload);
                    }
                } else {
                    // Status, message, telemetry, weather-only, etc.
                    // No position+symbol to extract — punt to comment.
                    $r['comment'] = trim($payload);
                }

                // Classify
                $r['station_type'] = $STATION_TYPES[$r['symbol']] ?? 'Other';

                // Weather parsing — runs for weather-classified
                // stations AND ALSO any station whose comment looks
                // like weather data (catches misclassified MIC-E
                // weather stations and weather-only positionless '_'
                // packets). The wx regex pattern (_DDD/SSS or tNNN +
                // hNN + bNNNNN) is distinctive enough that false
                // positives are vanishingly rare.
                $isWxByComment = preg_match('/_\d{3}\/\d{3}/', $r['comment'])
                              || (preg_match('/t-?\d{2,3}/', $r['comment'])
                                  && preg_match('/[hb]\d{2,5}/', $r['comment']));
                if ($r['station_type'] === 'Weather' || $r['station_type'] === 'WX site' || $isWxByComment) {
                    $wx = $parseWeather($r['comment']);
                    foreach ($wx as $k => $v) { $r[$k] = $v; }
                    $r['speed']   = 0.0;
                    $r['heading'] = 0.0;
                    if ($r['station_type'] === 'Other') {
                        // Promote misclassified-by-symbol to Weather
                        // so the table + map filter behave correctly.
                        $r['station_type'] = 'Weather';
                    }
                }
            }
        }
    }
    unset($r);

    echo json_encode([
        'provider' => [
            'id'                => (int) $provider['id'],
            'code'              => $provider['code'],
            'name'              => $provider['name'],
            'enabled'           => (int) $provider['enabled'],
            'last_seen_ago_sec' => $lastSeenAgo,
        ],
        'stations'                  => $rows,
        'count'                     => count($rows),
        'unique_stations_in_window' => $uniqueInWindow,
        'listener_status'           => $listenerStatus,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'APRS positions query failed: ' . $e->getMessage()]);
}
