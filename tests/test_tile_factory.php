<?php
/**
 * test_tile_factory.php — window.makeTileLayer branch behavior.
 *
 * makeTileLayer (assets/js/leaflet-quadkey.js) must:
 *   - return an L.TileLayer.QuadKey when the URL template contains {q}
 *   - return a plain L.tileLayer otherwise
 *   - and the QuadKey layer's getTileUrl must substitute {q} with the
 *     computed quadkey for a tile coordinate.
 *
 * This is browser code, so we execute it under node with a minimal Leaflet
 * stub that records which constructor path was taken and lets us call
 * getTileUrl. If node is unavailable (e.g. a server without Node), we fall
 * back to a structural assertion on the source so the test still runs.
 *
 * Spec: specs/configurable-tile-providers-2026-06/ (Phase B).
 */

$base = realpath(__DIR__ . '/..');
$jsPath = $base . '/assets/js/leaflet-quadkey.js';

echo "=== Configurable Tile Providers — makeTileLayer factory tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

if (!file_exists($jsPath)) {
    bad('assets/js/leaflet-quadkey.js exists');
    echo "\nFactory tests: {$pass} passed, {$fail} failed\n";
    exit(1);
}
ok('assets/js/leaflet-quadkey.js exists');

$nodeRc = 1; $nodeOut = [];
@exec('node --version 2>&1', $nodeOut, $nodeRc);

if ($nodeRc === 0) {
    // A Leaflet stub just rich enough for leaflet-quadkey.js to define the
    // QuadKey subclass + factory, and for us to exercise both branches and
    // getTileUrl. Records constructor "type" so we can assert the branch.
    $harness = <<<'JS'
var win = {};
global.window = win;

// ---- Minimal Leaflet stub ----
function TileLayer(url, opts) { this._url = url; this.options = opts || {}; this.__type = 'plain'; }
TileLayer.prototype.getTileUrl = function (c) { return this._url; };
TileLayer.prototype._getSubdomain = function (c) {
    var subs = this.options.subdomains || 'abc';
    var idx = Math.abs(c.x + c.y) % subs.length;
    return subs[idx];
};
TileLayer.prototype._getZoomForUrl = function () { return this.__z; };

// L.TileLayer.extend → classic prototype-chain subclass.
TileLayer.extend = function (proto) {
    function Sub(url, opts) { TileLayer.call(this, url, opts); this.__type = 'quadkey'; }
    Sub.prototype = Object.create(TileLayer.prototype);
    Sub.prototype.constructor = Sub;
    for (var k in proto) { if (proto.hasOwnProperty(k)) { Sub.prototype[k] = proto[k]; } }
    Sub.extend = TileLayer.extend;
    return Sub;
};

var L = {
    TileLayer: TileLayer,
    tileLayer: function (url, opts) { return new TileLayer(url, opts); },
    Util: {
        template: function (str, data) {
            return str.replace(/\{ *([\w_-]+) *\}/g, function (m, key) {
                if (data[key] === undefined) { throw new Error('No value for ' + key); }
                return data[key];
            });
        }
    },
    extend: function (dest, src) { for (var k in src) { dest[k] = src[k]; } return dest; },
    Browser: { retina: false }
};
global.L = L;

var fs = require('fs');
eval(fs.readFileSync(process.argv[2], 'utf8'));

var make = win.makeTileLayer;
var results = {};

// Branch 1: {q} URL → quadkey layer
var q = make('https://ecn.t{s}.tiles.virtualearth.net/tiles/r{q}?g=1', { subdomains: '01234567' });
results.quadkeyType = q.__type;

// Branch 2: plain XYZ URL → plain layer
var p = make('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {});
results.plainType = p.__type;

// getTileUrl on the quadkey layer substitutes {q}. Tile (3,5) @ z=3 → "213".
q.__z = 3;
results.tileUrl = q.getTileUrl({ x: 3, y: 5 });

// Non-string / empty URL should not throw and yields a plain layer.
var e = make('', {});
results.emptyType = e.__type;

console.log(JSON.stringify(results));
JS;
    $tmp = tempnam(sys_get_temp_dir(), 'fac') . '.js';
    file_put_contents($tmp, $harness);
    $runOut = []; $runRc = 1;
    @exec('node ' . escapeshellarg($tmp) . ' ' . escapeshellarg($jsPath), $runOut, $runRc);
    @unlink($tmp);

    $res = (!empty($runOut)) ? json_decode(end($runOut), true) : null;
    if (is_array($res)) {
        if (($res['quadkeyType'] ?? '') === 'quadkey') { ok('makeTileLayer returns a QuadKey layer for {q} URLs'); }
        else { bad('quadkey branch', json_encode($res)); }

        if (($res['plainType'] ?? '') === 'plain') { ok('makeTileLayer returns a plain L.tileLayer for XYZ URLs'); }
        else { bad('plain branch', json_encode($res)); }

        if (($res['tileUrl'] ?? '') === 'https://ecn.t0.tiles.virtualearth.net/tiles/r213?g=1') {
            ok('QuadKey getTileUrl substitutes {q} → "213" for tile (3,5)@z3');
        } else {
            bad('quadkey getTileUrl substitution', $res['tileUrl'] ?? 'null');
        }

        if (($res['emptyType'] ?? '') === 'plain') { ok('empty URL yields a plain layer (no throw)'); }
        else { bad('empty URL branch', json_encode($res)); }
    } else {
        bad('node harness produced parseable output', 'got: ' . implode("\n", $runOut));
    }
} else {
    // No node — structural fallback so the test still asserts something.
    echo "[SKIP] node not available — executing structural checks only\n";
    $js = file_get_contents($jsPath);
    if (strpos($js, "indexOf('{q}') !== -1") !== false) {
        ok('factory source branches on {q} (structural)');
    } else {
        bad('factory {q} branch (structural)');
    }
    if (strpos($js, 'new L.TileLayer.QuadKey') !== false) {
        ok('factory constructs L.TileLayer.QuadKey for {q} (structural)');
    } else {
        bad('factory constructs QuadKey (structural)');
    }
    if (strpos($js, 'L.tileLayer(url, opts)') !== false) {
        ok('factory falls back to L.tileLayer (structural)');
    } else {
        bad('factory L.tileLayer fallback (structural)');
    }
}

echo "\n";
echo "==========================================================\n";
echo "Factory tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

if ($fail > 0) exit(1);
