<?php
/**
 * GH#47 (cbyrdmo, 2026-08-14/15) -- the EOC display's map zoom buttons
 * never responded to clicks, and the layer-control icon read as "barely
 * visible" top-right. Root-caused live with a throwaway Playwright probe
 * (training-production/_tmp_gh47_probe*.mjs, not shipped): #appHeader is
 * z-index:1030 and #sitMap is a full-bleed map (position:absolute; top:0)
 * that the header floats on top of, so BOTH Leaflet corner controls sat
 * physically behind it. The 2026-06-11 fix for the layer control
 * (z-index:1010) never actually cleared the header's 1030 and never
 * touched the zoom control at all.
 *
 * A same-side offset wasn't enough for the zoom control specifically:
 * #sitOverlay (the incidents panel) spans nearly the full LEFT edge of the
 * map top-to-bottom (left:10px; width:480px; max-height:calc(100% - 20px)),
 * so moving the zoom control down (topleft) or even to bottomleft still
 * landed it under the overlay's own content -- confirmed live both times
 * before landing on bottomright, the one corner neither the header nor the
 * overlay's 480px width reaches.
 *
 * Verified live (not just read from the diff): a real Playwright click on
 * .leaflet-control-zoom-in changed the map's actual zoom level (10 -> 11);
 * a hit-test on .leaflet-control-layers-toggle resolved to the button
 * itself, not a navbar element behind it.
 */

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/situation.php');

$pass = 0; $fail = 0;
function t47(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#47 situation.php map-control collision regression ===\n\n";

t47('the layer control (top-right) is pushed below the header (top:55px, matching header height)',
    (bool) preg_match('/\.leaflet-top\s*\{\s*top:\s*55px;\s*\}/', $src));
t47('the mobile media-query resets .leaflet-top back to Leaflet\'s own default (top:0) since the stacked mobile layout no longer overlays the map',
    (bool) preg_match('/@media \(max-width: 768px\)\s*\{.*?\.leaflet-top\s*\{\s*top:\s*0;\s*\}.*?\}/s', $src));
t47('the built-in zoomControl is disabled so it can be added manually at a collision-free position',
    strpos($src, 'zoomControl: false,') !== false);
t47('the zoom control is re-added at bottomright, not topleft/bottomleft (both of which collide with #sitOverlay)',
    strpos($src, "L.control.zoom({ position: 'bottomright' }).addTo(map);") !== false);
t47('the old always-on zoomControl:true is gone (would double-register the control)',
    strpos($src, 'zoomControl: true,') === false);

// #sitOverlay's own footprint (left:10px, width:480px) must still be
// present and unchanged -- this fix works AROUND that footprint rather
// than shrinking it, so if that geometry ever changes, the "which corner
// is actually free" reasoning above needs re-checking, not silently
// assumed still true.
t47('#sitOverlay is still anchored left:10px (the geometry this fix reasons about)',
    (bool) preg_match('/#sitOverlay\s*\{[^}]*left:\s*10px/s', $src));
t47('#sitOverlay is still 480px wide (the geometry this fix reasons about)',
    (bool) preg_match('/#sitOverlay\s*\{[^}]*width:\s*480px/s', $src));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
