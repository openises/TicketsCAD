<?php
/**
 * Phase 41 — Software Bill of Materials generator.
 *
 *   - SBOM.txt        — human-readable, one-line-per-dependency
 *   - SBOM.cdx.json   — CycloneDX v1.5 (the most widely-supported open
 *                       SBOM format; readable by Trivy, Grype, Dependency
 *                       Track, Snyk, GitHub's `npm sbom`, Microsoft SCA…)
 *
 * Sources scanned:
 *   - composer.lock  (PHP server deps)
 *   - tickets/composer.lock  (legacy v3.44 PHP deps if present)
 *   - assets/vendor/  (bundled JS/CSS libs — Bootstrap, Leaflet, GridStack)
 *   - services/meshtastic/  (Python deps — heuristic, since we don't pin them)
 *   - System: PHP version, OS, MariaDB version (recorded only — server side
 *     not bundled with the repo, but useful context).
 *
 * Usage:  php tools/generate-sbom.php
 *
 * Idempotent — overwrites the two output files each run.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$components = [];

// ── 1. Composer (server PHP) ──────────────────────────────────
function addComposerLock(string $path, string $scopeHint, array &$components): void {
    if (!is_file($path)) return;
    $data = json_decode(file_get_contents($path), true);
    foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $p) {
        $name = $p['name'];
        $version = ltrim($p['version'], 'v');
        $purl = 'pkg:composer/' . str_replace('/', '/', $name) . '@' . $version;
        $components[] = [
            'name'        => $name,
            'version'     => $version,
            'type'        => 'library',
            'purl'        => $purl,
            'scope'       => $scopeHint,
            'license'     => $p['license'][0] ?? null,
            'description' => $p['description'] ?? null,
            'sourceUrl'   => $p['source']['url'] ?? ($p['homepage'] ?? null),
            'kind'        => 'php',
        ];
    }
}
addComposerLock($root . '/composer.lock',         'newui',         $components);
addComposerLock($root . '/../tickets/composer.lock', 'tickets-legacy', $components);

// ── 2. Vendored frontend libs (best-effort — no lockfiles) ────
$frontends = [
    'bootstrap'   => ['name' => 'bootstrap',       'license' => 'MIT', 'src' => 'https://github.com/twbs/bootstrap'],
    'leaflet'     => ['name' => 'leaflet',         'license' => 'BSD-2-Clause', 'src' => 'https://github.com/Leaflet/Leaflet'],
    'gridstack'   => ['name' => 'gridstack',       'license' => 'MIT', 'src' => 'https://github.com/gridstack/gridstack.js'],
];
foreach ($frontends as $dir => $meta) {
    $libDir = $root . '/assets/vendor/' . $dir;
    if (!is_dir($libDir)) continue;
    // Try to read a version file or package.json next to it
    $version = 'bundled';
    foreach (['package.json', $dir . '.json'] as $candidate) {
        $f = $libDir . '/' . $candidate;
        if (is_file($f)) {
            $j = json_decode(file_get_contents($f), true);
            if (isset($j['version'])) { $version = $j['version']; break; }
        }
    }
    // Also try VERSION files
    if ($version === 'bundled') {
        foreach (glob($libDir . '/VERSION*') as $vf) {
            $v = trim(file_get_contents($vf));
            if ($v !== '') { $version = $v; break; }
        }
    }
    $components[] = [
        'name'        => $meta['name'],
        'version'     => $version,
        'type'        => 'library',
        'purl'        => 'pkg:generic/' . $meta['name'] . '@' . $version,
        'scope'       => 'frontend',
        'license'     => $meta['license'],
        'description' => null,
        'sourceUrl'   => $meta['src'],
        'kind'        => 'js',
    ];
}

// ── 3. Python deps for the bridge / APRS listener (declared, not pinned) ──
$pythonGroups = [
    'mesh-bridge' => ['meshtastic', 'pypubsub', 'pyserial', 'requests', 'meshcore-cli'],
    'aprs-listener' => ['aprslib', 'requests', 'pymysql'],
];
foreach ($pythonGroups as $group => $pkgs) {
    foreach ($pkgs as $p) {
        $components[] = [
            'name'        => $p,
            'version'     => 'unpinned',
            'type'        => 'library',
            'purl'        => 'pkg:pypi/' . $p,
            'scope'       => $group,
            'license'     => null,
            'description' => null,
            'sourceUrl'   => 'https://pypi.org/project/' . $p . '/',
            'kind'        => 'python',
        ];
    }
}

// ── 4. CDN-loaded JS (mesh-console QR generator) ──────────────
$components[] = [
    'name'        => 'qrcode',
    'version'     => '1.5.3',
    'type'        => 'library',
    'purl'        => 'pkg:npm/qrcode@1.5.3',
    'scope'       => 'mesh-console',
    'license'     => 'MIT',
    'description' => 'QR code generator (loaded from jsdelivr CDN by mesh-console.php)',
    'sourceUrl'   => 'https://github.com/soldair/node-qrcode',
    'kind'        => 'js-cdn',
];

// ── 5. Write SBOM.txt (human readable) ────────────────────────
$txt  = "Tickets CAD NewUI v4 — Software Bill of Materials\n";
$txt .= "Generated: " . date('Y-m-d H:i:s') . "\n";
$txt .= str_repeat('=', 60) . "\n\n";

// Group by kind for legibility
$byKind = [];
foreach ($components as $c) $byKind[$c['kind']][] = $c;
ksort($byKind);
foreach ($byKind as $kind => $list) {
    $txt .= "## " . strtoupper($kind) . "\n";
    usort($list, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    foreach ($list as $c) {
        $txt .= sprintf("  %-40s %-15s  %s  (%s)\n",
            $c['name'], $c['version'],
            $c['license'] ?? 'unknown-license',
            $c['scope']);
    }
    $txt .= "\n";
}

$txt .= "\nTotal components: " . count($components) . "\n";
$txt .= "Machine-readable copy: SBOM.cdx.json (CycloneDX v1.5)\n";

file_put_contents($root . '/SBOM.txt', $txt);
echo "[OK] wrote SBOM.txt (" . count($components) . " components)\n";

// ── 6. Write SBOM.cdx.json (CycloneDX 1.5) ────────────────────
$cdx = [
    'bomFormat'    => 'CycloneDX',
    'specVersion'  => '1.5',
    'version'      => 1,
    'metadata'     => [
        'timestamp'  => date('c'),
        'component'  => [
            'type'    => 'application',
            'name'    => 'TicketsCAD NewUI',
            'version' => trim(@file_get_contents($root . '/VERSION') ?: '4.0.0-dev'),
            'bom-ref' => 'pkg:generic/ticketscad-newui',
        ],
        'tools'      => [
            ['vendor' => 'Tickets CAD', 'name' => 'tools/generate-sbom.php', 'version' => '1.0.0'],
        ],
    ],
    'components'   => [],
];

foreach ($components as $c) {
    $entry = [
        'type'    => $c['type'],
        'name'    => $c['name'],
        'version' => $c['version'],
        'purl'    => $c['purl'],
        'bom-ref' => $c['purl'],
        'scope'   => 'required',
        'group'   => str_contains($c['name'], '/') ? explode('/', $c['name'])[0] : null,
    ];
    if (!empty($c['license'])) {
        $entry['licenses'] = [['license' => ['id' => $c['license']]]];
    }
    if (!empty($c['description'])) $entry['description'] = $c['description'];
    if (!empty($c['sourceUrl'])) {
        $entry['externalReferences'] = [['type' => 'vcs', 'url' => $c['sourceUrl']]];
    }
    $cdx['components'][] = $entry;
}

file_put_contents($root . '/SBOM.cdx.json', json_encode($cdx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "[OK] wrote SBOM.cdx.json\n";

echo "\nDone.\n";
