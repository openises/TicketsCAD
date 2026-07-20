<?php
/**
 * Phase 43c — Map overlay format converters (GeoJSON / KML / GPX).
 *
 * Translates between the legacy mmarkup row shape and the three common
 * interchange formats used by other public-safety / GIS tools.
 *
 *   mmarkup row fields used here:
 *     id, line_name, line_type ('P'|'L'|'C'|'M'),
 *     line_data (JSON-encoded array of [lat,lng] pairs),
 *     line_ident (radius in meters, when line_type='C'),
 *     line_color (#RRGGBB), fill_color (#RRGGBB),
 *     line_width, fill_opacity, line_opacity
 *
 * Public functions:
 *   mmarkup_rows_to_geojson(array $rows, array $catLookup = []): array
 *   geojson_to_mmarkup_rows(array $featureColl, ?int $defaultCatId): array
 *   mmarkup_rows_to_kml(array $rows, string $docName, array $catLookup = []): string
 *   kml_to_mmarkup_rows(string $kmlXml, ?int $defaultCatId): array
 *   mmarkup_rows_to_gpx(array $rows): string
 *
 * GeoJSON and KML round-trip cleanly (polygons, lines, circles, points).
 * GPX is export-only and only emits waypoints (markers) + tracks (lines);
 * polygons land as closed tracks with a `desc=polygon` hint.
 *
 * Color round-trip:
 *   GeoJSON: stored under properties.line_color/fill_color
 *   KML:     <Style> with <LineStyle><color>aabbggrr</color> (ARGB→ABGR order)
 *   GPX:     consumer GPX has no color spec; ignored.
 */

/* ─── Helpers ───────────────────────────────────────────────────── */

/** Convert #RRGGBB → KML ABGR (aabbggrr) with opacity 0..1. */
function _mfc_hex_to_kml_color(string $hex, float $opacity = 1.0): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return 'ff1976d2';   // fallback blue
    $r = substr($hex, 0, 2);
    $g = substr($hex, 2, 2);
    $b = substr($hex, 4, 2);
    $a = sprintf('%02x', max(0, min(255, (int) round($opacity * 255))));
    return $a . $b . $g . $r;
}

/** Convert KML ABGR (aabbggrr) → #RRGGBB. */
function _mfc_kml_color_to_hex(string $abgr): string {
    if (strlen($abgr) === 8) {
        // aabbggrr
        return '#' . substr($abgr, 6, 2) . substr($abgr, 4, 2) . substr($abgr, 2, 2);
    }
    if (strlen($abgr) === 6) {
        return '#' . substr($abgr, 4, 2) . substr($abgr, 2, 2) . substr($abgr, 0, 2);
    }
    return '#1976d2';
}

/** Build the [lng, lat] pair sequence GeoJSON expects from our [lat,lng] storage. */
function _mfc_latlng_to_lnglat(array $latlngs): array {
    $out = [];
    foreach ($latlngs as $p) {
        if (!is_array($p) || count($p) < 2) continue;
        $out[] = [(float) $p[1], (float) $p[0]];
    }
    return $out;
}

/** Reverse: GeoJSON [lng,lat] back to mmarkup [lat,lng]. */
function _mfc_lnglat_to_latlng(array $lnglats): array {
    $out = [];
    foreach ($lnglats as $p) {
        if (!is_array($p) || count($p) < 2) continue;
        $out[] = [(float) $p[1], (float) $p[0]];
    }
    return $out;
}

/** Approximate a circle as a 64-vertex polygon for export to formats that
    have no native circle (KML / GPX). */
function _mfc_circle_to_polygon(float $lat, float $lng, float $radiusM, int $segments = 64): array {
    $coords = [];
    $earthR = 6371000.0;
    $latR = deg2rad($lat);
    for ($i = 0; $i <= $segments; $i++) {
        $b = 2 * M_PI * $i / $segments;
        $latP = asin(sin($latR) * cos($radiusM / $earthR) +
                     cos($latR) * sin($radiusM / $earthR) * cos($b));
        $lngP = deg2rad($lng) + atan2(
            sin($b) * sin($radiusM / $earthR) * cos($latR),
            cos($radiusM / $earthR) - sin($latR) * sin($latP)
        );
        $coords[] = [rad2deg($latP), rad2deg($lngP)];
    }
    return $coords;
}

/* ─── GeoJSON ──────────────────────────────────────────────────── */

function mmarkup_rows_to_geojson(array $rows, array $catLookup = []): array {
    $features = [];
    foreach ($rows as $r) {
        $coords = json_decode((string) ($r['line_data'] ?? '[]'), true) ?: [];
        if (!$coords) continue;
        $type = strtoupper((string) ($r['line_type'] ?? 'P'));
        $catId = (int) ($r['category_id'] ?? $r['line_cat_id'] ?? 0);
        $catName = $catLookup[$catId] ?? null;
        $props = array_filter([
            'id'            => (int) $r['id'],
            'name'          => $r['line_name'] ?? '',
            'category_id'   => $catId ?: null,
            'category_name' => $catName,
            'line_color'    => $r['line_color'] ?? null,
            'fill_color'    => $r['fill_color'] ?? null,
            'line_width'    => $r['line_width'] ?? null,
        ], function ($v) { return $v !== null && $v !== ''; });

        if ($type === 'P' && count($coords) >= 3) {
            // GeoJSON polygons require closing the ring + an outer ring array wrap.
            $ring = _mfc_latlng_to_lnglat($coords);
            if ($ring[0] !== end($ring)) { $ring[] = $ring[0]; }
            $features[] = ['type' => 'Feature', 'properties' => $props,
                'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]]];
        } elseif ($type === 'L' && count($coords) >= 2) {
            $features[] = ['type' => 'Feature', 'properties' => $props,
                'geometry' => ['type' => 'LineString',
                               'coordinates' => _mfc_latlng_to_lnglat($coords)]];
        } elseif ($type === 'C' && count($coords) >= 1) {
            // GeoJSON has no native circle. Encode as Point + properties.radius
            // (the de-facto convention used by Mapbox, Leaflet.draw, etc).
            $props['radius']    = (float) ($r['line_ident'] ?? 0);
            $props['shape']     = 'circle';
            $features[] = ['type' => 'Feature', 'properties' => $props,
                'geometry' => ['type' => 'Point',
                               'coordinates' => [(float) $coords[0][1], (float) $coords[0][0]]]];
        } elseif ($type === 'M' && count($coords) >= 1) {
            $features[] = ['type' => 'Feature', 'properties' => $props,
                'geometry' => ['type' => 'Point',
                               'coordinates' => [(float) $coords[0][1], (float) $coords[0][0]]]];
        }
    }
    return ['type' => 'FeatureCollection', 'features' => $features];
}

function geojson_to_mmarkup_rows(array $fc, ?int $defaultCatId): array {
    $rows = [];
    $features = $fc['features'] ?? ($fc['type'] === 'Feature' ? [$fc] : []);
    foreach ($features as $f) {
        $g = $f['geometry'] ?? [];
        $p = $f['properties'] ?? [];
        $name = trim((string) ($p['name'] ?? $p['title'] ?? ''));
        if ($name === '') $name = 'Imported shape';
        $catId = isset($p['category_id']) ? (int) $p['category_id'] : ($defaultCatId ?: 0);
        $color = $p['line_color'] ?? $p['color'] ?? $p['stroke'] ?? null;
        $fill  = $p['fill_color'] ?? $p['fill']  ?? $color;
        $width = $p['line_width'] ?? $p['stroke-width'] ?? null;

        $base = [
            'name'        => $name,
            'category_id' => $catId,
            'visible'     => 1,
        ];
        if ($color) $base['color']      = $color;
        if ($fill)  $base['fill_color'] = $fill;
        if ($width) $base['width']      = (int) $width;

        $type = strtolower((string) ($g['type'] ?? ''));
        if ($type === 'polygon' && !empty($g['coordinates'][0])) {
            $ring = _mfc_lnglat_to_latlng($g['coordinates'][0]);
            // Drop duplicate-closing vertex so storage matches our drawn shape.
            if (count($ring) > 1 && $ring[0] === end($ring)) array_pop($ring);
            $base['type'] = 'P';
            $base['coordinates'] = json_encode($ring);
            $base['filled'] = 1; $base['fill_opacity'] = 0.25; $base['opacity'] = 0.8;
            $rows[] = $base;
        } elseif ($type === 'linestring' && !empty($g['coordinates'])) {
            $base['type'] = 'L';
            $base['coordinates'] = json_encode(_mfc_lnglat_to_latlng($g['coordinates']));
            $base['filled'] = 0; $base['opacity'] = 0.8;
            $rows[] = $base;
        } elseif ($type === 'point' && !empty($g['coordinates'])) {
            // If properties.radius is present, treat as a circle. Otherwise marker.
            $coord = $g['coordinates'];
            $latlng = [[(float) $coord[1], (float) $coord[0]]];
            if (isset($p['radius']) && (float) $p['radius'] > 0) {
                $base['type'] = 'C';
                $base['coordinates'] = json_encode($latlng);
                $base['ident'] = (string) (float) $p['radius'];
                $base['filled'] = 1; $base['fill_opacity'] = 0.2; $base['opacity'] = 0.8;
            } else {
                $base['type'] = 'M';
                $base['coordinates'] = json_encode($latlng);
            }
            $rows[] = $base;
        }
    }
    return $rows;
}

/* ─── KML ──────────────────────────────────────────────────────── */

function mmarkup_rows_to_kml(array $rows, string $docName, array $catLookup = []): string {
    $esc = function ($s) {
        return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>' . "\n";
    $out .= '<name>' . $esc($docName) . '</name>' . "\n";

    // De-duplicated <Style> blocks per color/width combo.
    $styleSeen = [];
    $styleBlocks = '';
    $styleIdFor = function ($color, $width, $fill, $fillOpacity) use (&$styleSeen, &$styleBlocks) {
        $color = $color ?: '#1976d2';
        $fill  = $fill  ?: $color;
        $key = $color . '|' . $width . '|' . $fill . '|' . $fillOpacity;
        if (isset($styleSeen[$key])) return $styleSeen[$key];
        $id = 's' . count($styleSeen);
        $styleSeen[$key] = $id;
        $line = _mfc_hex_to_kml_color($color, 1.0);
        $fcol = _mfc_hex_to_kml_color($fill,  max(0.0, min(1.0, (float) ($fillOpacity ?: 0.2))));
        $styleBlocks .= '<Style id="' . $id . '">'
                     .  '<LineStyle><color>' . $line . '</color><width>' . max(1, (int) $width) . '</width></LineStyle>'
                     .  '<PolyStyle><color>' . $fcol . '</color></PolyStyle>'
                     .  '</Style>' . "\n";
        return $id;
    };

    $placemarks = '';
    foreach ($rows as $r) {
        $coords = json_decode((string) ($r['line_data'] ?? '[]'), true) ?: [];
        if (!$coords) continue;
        $type = strtoupper((string) ($r['line_type'] ?? 'P'));
        $name = $r['line_name'] ?? 'Shape ' . $r['id'];
        $catId = (int) ($r['category_id'] ?? $r['line_cat_id'] ?? 0);
        $catName = $catLookup[$catId] ?? '';
        $desc = $catName ? 'Category: ' . $catName : '';
        $styleId = $styleIdFor($r['line_color'] ?? '#1976d2',
                               $r['line_width'] ?? 2,
                               $r['fill_color'] ?? null,
                               $r['fill_opacity'] ?? 0.2);

        if ($type === 'P' && count($coords) >= 3) {
            $ring = $coords;
            if ($ring[0] !== end($ring)) $ring[] = $ring[0];
            $coordStr = '';
            foreach ($ring as $p) { $coordStr .= $p[1] . ',' . $p[0] . ',0 '; }
            $placemarks .= '<Placemark><name>' . $esc($name) . '</name>'
                .  ($desc ? '<description>' . $esc($desc) . '</description>' : '')
                .  '<styleUrl>#' . $styleId . '</styleUrl>'
                .  '<Polygon><outerBoundaryIs><LinearRing><coordinates>' . trim($coordStr) . '</coordinates></LinearRing></outerBoundaryIs></Polygon>'
                .  '</Placemark>' . "\n";
        } elseif ($type === 'L' && count($coords) >= 2) {
            $coordStr = '';
            foreach ($coords as $p) { $coordStr .= $p[1] . ',' . $p[0] . ',0 '; }
            $placemarks .= '<Placemark><name>' . $esc($name) . '</name>'
                .  ($desc ? '<description>' . $esc($desc) . '</description>' : '')
                .  '<styleUrl>#' . $styleId . '</styleUrl>'
                .  '<LineString><coordinates>' . trim($coordStr) . '</coordinates></LineString>'
                .  '</Placemark>' . "\n";
        } elseif ($type === 'C' && count($coords) >= 1) {
            // KML has no Circle — emit as polygon approximation.
            $ring = _mfc_circle_to_polygon((float) $coords[0][0], (float) $coords[0][1], (float) ($r['line_ident'] ?? 100));
            $coordStr = '';
            foreach ($ring as $p) { $coordStr .= $p[1] . ',' . $p[0] . ',0 '; }
            $placemarks .= '<Placemark><name>' . $esc($name) . '</name>'
                .  ($desc ? '<description>' . $esc($desc) . '</description>' : '')
                .  '<styleUrl>#' . $styleId . '</styleUrl>'
                .  '<Polygon><outerBoundaryIs><LinearRing><coordinates>' . trim($coordStr) . '</coordinates></LinearRing></outerBoundaryIs></Polygon>'
                .  '</Placemark>' . "\n";
        } elseif ($type === 'M' && count($coords) >= 1) {
            $placemarks .= '<Placemark><name>' . $esc($name) . '</name>'
                .  ($desc ? '<description>' . $esc($desc) . '</description>' : '')
                .  '<Point><coordinates>' . $coords[0][1] . ',' . $coords[0][0] . ',0</coordinates></Point>'
                .  '</Placemark>' . "\n";
        }
    }

    $out .= $styleBlocks . $placemarks . '</Document></kml>' . "\n";
    return $out;
}

function kml_to_mmarkup_rows(string $kmlXml, ?int $defaultCatId): array {
    // libxml_use_internal_errors so a malformed XML doesn't surface PHP warnings.
    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($kmlXml);
    libxml_clear_errors();
    if ($sx === false) return [];

    // Build a styleId → color/width lookup so Placemark <styleUrl> can resolve.
    $styles = [];
    foreach ($sx->xpath('//*[local-name()="Style"]') ?: [] as $st) {
        $id = (string) $st['id'];
        if (!$id) continue;
        $line = $st->xpath('./*[local-name()="LineStyle"]/*[local-name()="color"]') ?: [];
        $poly = $st->xpath('./*[local-name()="PolyStyle"]/*[local-name()="color"]') ?: [];
        $w    = $st->xpath('./*[local-name()="LineStyle"]/*[local-name()="width"]') ?: [];
        $styles[$id] = [
            'color' => isset($line[0]) ? _mfc_kml_color_to_hex((string) $line[0]) : null,
            'fill'  => isset($poly[0]) ? _mfc_kml_color_to_hex((string) $poly[0]) : null,
            'width' => isset($w[0])    ? (int) $w[0] : null,
        ];
    }
    $resolveStyle = function ($placemark) use ($styles) {
        $u = $placemark->xpath('./*[local-name()="styleUrl"]') ?: [];
        if (!$u) return null;
        $id = ltrim(trim((string) $u[0]), '#');
        return $styles[$id] ?? null;
    };

    $rows = [];
    foreach ($sx->xpath('//*[local-name()="Placemark"]') ?: [] as $pm) {
        $nameNode = $pm->xpath('./*[local-name()="name"]') ?: [];
        $name = $nameNode ? trim((string) $nameNode[0]) : 'Imported shape';
        $sty = $resolveStyle($pm) ?: [];
        $base = [
            'name'        => $name,
            'category_id' => $defaultCatId ?: 0,
            'visible'     => 1,
        ];
        if (!empty($sty['color'])) $base['color']      = $sty['color'];
        if (!empty($sty['fill']))  $base['fill_color'] = $sty['fill'];
        if (!empty($sty['width'])) $base['width']      = $sty['width'];

        // Polygon
        $poly = $pm->xpath('.//*[local-name()="Polygon"]//*[local-name()="LinearRing"]/*[local-name()="coordinates"]') ?: [];
        if ($poly) {
            $coords = _parse_kml_coords((string) $poly[0]);
            if (count($coords) > 1 && $coords[0] === end($coords)) array_pop($coords);
            if (count($coords) >= 3) {
                $r = $base + ['type' => 'P', 'coordinates' => json_encode($coords),
                              'filled' => 1, 'fill_opacity' => 0.25, 'opacity' => 0.8];
                $rows[] = $r;
                continue;
            }
        }
        // LineString
        $line = $pm->xpath('.//*[local-name()="LineString"]/*[local-name()="coordinates"]') ?: [];
        if ($line) {
            $coords = _parse_kml_coords((string) $line[0]);
            if (count($coords) >= 2) {
                $rows[] = $base + ['type' => 'L', 'coordinates' => json_encode($coords),
                                   'filled' => 0, 'opacity' => 0.8];
                continue;
            }
        }
        // Point
        $pt = $pm->xpath('.//*[local-name()="Point"]/*[local-name()="coordinates"]') ?: [];
        if ($pt) {
            $coords = _parse_kml_coords((string) $pt[0]);
            if ($coords) {
                $rows[] = $base + ['type' => 'M', 'coordinates' => json_encode([$coords[0]])];
            }
        }
    }
    return $rows;
}

/* ─── KMZ import (maps-comprehensive-2026-06) ──────────────────────
   A KMZ is a ZIP archive containing a KML document (conventionally
   `doc.kml`) plus any referenced assets. We unzip via ZipArchive (already
   used in inc/backup.php), read doc.kml — or the first `*.kml` entry if the
   archive doesn't follow the convention — and hand the KML to the existing
   kml_to_mmarkup_rows() so polygons/lines/circles/points all flow through
   the one converter.

   $kmzBinary is the raw bytes of the .kmz file. Throws RuntimeException on a
   corrupt/empty archive or a KMZ with no KML inside, so the caller can return
   a clean json_error instead of crashing. */
function kmz_extract_kml(string $kmzBinary): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive PHP extension is not available.');
    }
    if ($kmzBinary === '') {
        throw new RuntimeException('Empty KMZ upload.');
    }

    // ZipArchive needs a file path; write the bytes to a temp file.
    $tmp = tempnam(sys_get_temp_dir(), 'kmz_');
    if ($tmp === false) {
        throw new RuntimeException('Could not allocate a temp file for the KMZ.');
    }
    try {
        if (file_put_contents($tmp, $kmzBinary) === false) {
            throw new RuntimeException('Could not write the KMZ to disk.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('KMZ is not a valid zip archive.');
        }

        // Prefer doc.kml (the KMZ convention), else the first *.kml entry.
        $kml = $zip->getFromName('doc.kml');
        if ($kml === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('/\.kml$/i', $name)) {
                    $kml = $zip->getFromName($name);
                    if ($kml !== false) break;
                }
            }
        }
        $zip->close();

        if ($kml === false || $kml === '') {
            throw new RuntimeException('No KML document found inside the KMZ.');
        }
        return $kml;
    } finally {
        @unlink($tmp);
    }
}

/** Convenience: KMZ bytes → mmarkup rows (unzip + reuse the KML converter). */
function kmz_to_mmarkup_rows(string $kmzBinary, ?int $defaultCatId): array {
    $kml = kmz_extract_kml($kmzBinary);   // throws on bad archive
    return kml_to_mmarkup_rows($kml, $defaultCatId);
}

/** Parse a KML <coordinates> block (whitespace-separated "lng,lat[,alt]" tuples)
    into our [[lat,lng],...] storage shape. */
function _parse_kml_coords(string $text): array {
    $out = [];
    foreach (preg_split('/\s+/', trim($text)) as $tup) {
        if (!$tup) continue;
        $parts = explode(',', $tup);
        if (count($parts) < 2) continue;
        $out[] = [(float) $parts[1], (float) $parts[0]];
    }
    return $out;
}

/* ─── GPX (export only) ────────────────────────────────────────── */

function mmarkup_rows_to_gpx(array $rows): string {
    $esc = function ($s) { return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<gpx version="1.1" creator="TicketsCAD NewUI" xmlns="http://www.topografix.com/GPX/1/1">' . "\n";

    $wpts = ''; $trks = '';
    foreach ($rows as $r) {
        $coords = json_decode((string) ($r['line_data'] ?? '[]'), true) ?: [];
        if (!$coords) continue;
        $name = $r['line_name'] ?? 'Shape ' . $r['id'];
        $type = strtoupper((string) ($r['line_type'] ?? 'P'));
        if ($type === 'M' && count($coords) >= 1) {
            $wpts .= '<wpt lat="' . $coords[0][0] . '" lon="' . $coords[0][1] . '"><name>' . $esc($name) . '</name></wpt>' . "\n";
        } elseif ($type === 'C' && count($coords) >= 1) {
            // Mark the center as a waypoint with the radius in the description.
            $wpts .= '<wpt lat="' . $coords[0][0] . '" lon="' . $coords[0][1] . '">'
                  .  '<name>' . $esc($name) . '</name>'
                  .  '<desc>radius_m=' . ((float) ($r['line_ident'] ?? 0)) . '</desc>'
                  .  '</wpt>' . "\n";
        } elseif ($type === 'L' || $type === 'P') {
            $pts = $coords;
            if ($type === 'P' && count($pts) >= 3 && $pts[0] !== end($pts)) $pts[] = $pts[0];
            $trks .= '<trk><name>' . $esc($name) . '</name>'
                  .  ($type === 'P' ? '<desc>shape=polygon</desc>' : '')
                  .  '<trkseg>';
            foreach ($pts as $p) {
                $trks .= '<trkpt lat="' . $p[0] . '" lon="' . $p[1] . '"/>';
            }
            $trks .= '</trkseg></trk>' . "\n";
        }
    }
    $out .= $wpts . $trks . '</gpx>' . "\n";
    return $out;
}
