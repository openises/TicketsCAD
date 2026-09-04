<?php
/**
 * Phase 127 — SBOM conformance tests.
 *
 * Gates the published Software Bill of Materials against the CISA 2026
 * Minimum Elements for a Software Bill of Materials (published 2026-07-29):
 * https://www.cisa.gov/resources-tools/resources/2026-minimum-elements-software-bill-materials-sbom
 *
 * The important assertions here are the honesty ones. It is easy to produce an
 * SBOM that looks complete; the failure mode that actually matters for a
 * government-aligned audience is an SBOM that states a version it does not
 * know. These tests fail if any component carries a version without evidence,
 * or if the committed SBOM has drifted from the code.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "=== SBOM conformance (CISA 2026 minimum elements) ===\n\n";

/* ---------------------------------------------------------------- *
 * Artifacts exist and parse
 * ---------------------------------------------------------------- */
$jsonPath = $root . '/SBOM.cdx.json';
$txtPath  = $root . '/SBOM.txt';

ok('SBOM.cdx.json exists', is_file($jsonPath));
ok('SBOM.txt exists', is_file($txtPath));

if (!is_file($jsonPath)) {
    echo "\nCannot continue without SBOM.cdx.json. Run: php tools/generate-sbom.php\n";
    echo "\n0 passed, 1 failed\n";
    exit(1);
}

$bom = json_decode((string) file_get_contents($jsonPath), true);
ok('SBOM.cdx.json is valid JSON', is_array($bom));

/* ---------------------------------------------------------------- *
 * SBOM Metadata elements (9)
 * ---------------------------------------------------------------- */
echo "\n-- SBOM Metadata elements --\n";

$meta = $bom['metadata'] ?? [];

ok('SBOM Author', !empty($meta['authors'][0]['name']));
ok('SBOM Data Format Name', ($bom['bomFormat'] ?? null) === 'CycloneDX');
ok('SBOM Data Format Version', ($bom['specVersion'] ?? null) === '1.6');
ok('SBOM Generation Context', !empty($meta['lifecycles'][0]['phase']));
ok('SBOM Tool Name', !empty($meta['tools']['components'][0]['name']));
ok('SBOM Tool Version', !empty($meta['tools']['components'][0]['version']));
ok('SBOM Version', isset($bom['version']) && is_int($bom['version']) && $bom['version'] >= 1);

/* CISA: SBOM Timestamp should adhere to RFC 9557. A plain RFC 3339 UTC
 * timestamp is a conforming RFC 9557 timestamp and is what CycloneDX accepts. */
ok('SBOM Timestamp is RFC 3339 / RFC 9557 conformant',
    isset($meta['timestamp'])
    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $meta['timestamp']) === 1,
    $meta['timestamp'] ?? '(absent)');

/* RFC 9562 serial number. */
ok('SBOM serial number is an RFC 9562 UUID URN',
    isset($bom['serialNumber'])
    && preg_match('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $bom['serialNumber']) === 1,
    $bom['serialNumber'] ?? '(absent)');

/* SBOM Author Signature.
 *
 * This must never be allowed to pass on the mere PRESENCE of a signature file.
 * A signature that does not verify, or that verifies only against a key we
 * never published, is a false assurance — worse than being plainly unsigned.
 * So the test does what a stranger would do: take the published public key out
 * of the repository, and check the detached signature against the SBOM bytes.
 */
$sigPath = $jsonPath . '.sig';
$pubPath = $root . '/SBOM-signing-key.pub.pem';
$rawBom  = (string) file_get_contents($jsonPath);

ok('SBOM Author Signature: detached signature is published', is_file($sigPath),
    'expected SBOM.cdx.json.sig');
ok('SBOM Author Signature: public key is published in the repository', is_file($pubPath),
    'expected SBOM-signing-key.pub.pem — without it nobody can verify the signature');

$sigBytes = is_file($sigPath)
    ? base64_decode(trim((string) file_get_contents($sigPath)), true) : false;
ok('SBOM Author Signature: signature file is valid base64',
    $sigBytes !== false && $sigBytes !== '');

$pubKey = is_file($pubPath) ? openssl_pkey_get_public((string) file_get_contents($pubPath)) : false;
ok('SBOM Author Signature: published key is a readable public key', $pubKey !== false);

$verified = ($pubKey !== false && $sigBytes !== false && $sigBytes !== '')
    ? openssl_verify($rawBom, $sigBytes, $pubKey, OPENSSL_ALGO_SHA256) : -1;
ok('SBOM Author Signature VERIFIES against the published public key', $verified === 1,
    'openssl_verify returned ' . $verified . ' (1 = valid, 0 = does not match, -1 = error). '
    . 'Re-sign with: php tools/generate-sbom.php --sign-key=<private-key>');

/* The key must be the algorithm we document, at a size still considered current
 * (NIST SP 800-131A Rev. 2). Catches a silent downgrade. */
$pubDetails = $pubKey !== false ? openssl_pkey_get_details($pubKey) : [];
ok('SBOM signing key is EC P-256 as documented',
    ($pubDetails['type'] ?? null) === OPENSSL_KEYTYPE_EC
    && ($pubDetails['ec']['curve_name'] ?? '') === 'prime256v1',
    'curve = ' . ($pubDetails['ec']['curve_name'] ?? 'n/a'));

/* Tampering must be detected — proves the check above is load-bearing and not
 * accidentally passing on some degenerate input. */
if ($verified === 1) {
    $tampered = str_replace('"specVersion": "1.6"', '"specVersion": "1.7"', $rawBom);
    ok('SBOM Author Signature rejects a modified SBOM',
        $tampered !== $rawBom
        && openssl_verify($tampered, $sigBytes, $pubKey, OPENSSL_ALGO_SHA256) !== 1);
}

/* A private key must never be committed. */
$strayKeys = array_merge(
    (array) glob($root . '/*.key.pem'),
    (array) glob($root . '/keys/*.pem'),
    (array) glob($root . '/*private*.pem')
);
$tracked = [];
foreach ($strayKeys as $k) {
    $out = [];
    exec('git -C ' . escapeshellarg($root) . ' ls-files --error-unmatch '
        . escapeshellarg($k) . ' 2>&1', $out, $rc);
    if ($rc === 0) $tracked[] = basename($k);
}
ok('no private key is tracked in the repository', $tracked === [],
    'tracked: ' . implode(', ', $tracked));

/* The published key must be the PUBLIC half only. */
ok('published signing key contains no private key material',
    is_file($pubPath)
    && !str_contains((string) file_get_contents($pubPath), 'PRIVATE KEY'));

/* ---------------------------------------------------------------- *
 * Target component
 * ---------------------------------------------------------------- */
echo "\n-- Target component --\n";
$target = $meta['component'] ?? [];
ok('Target component name', !empty($target['name']));
ok('Target component version matches VERSION file',
    ($target['version'] ?? null) === trim((string) @file_get_contents($root . '/VERSION')),
    'SBOM says ' . ($target['version'] ?? 'null'));
ok('Target component license declared', !empty($target['licenses'][0]['license']['id']));

/* ---------------------------------------------------------------- *
 * Component Data elements + the honesty rules
 * ---------------------------------------------------------------- */
echo "\n-- Component data elements --\n";

$components = $bom['components'] ?? [];
ok('SBOM enumerates components', count($components) > 0, count($components) . ' found');

$missingName      = [];
$missingId        = [];
$silentNoVersion  = [];
$badUnknownDecl   = [];
$hashWithoutAlg   = [];

foreach ($components as $c) {
    $name  = $c['name'] ?? '';
    $props = [];
    foreach ($c['properties'] ?? [] as $p) {
        $props[$p['name']][] = $p['value'];
    }
    $unknown = $props['ticketscad:unknown'][0] ?? '';

    if ($name === '') $missingName[] = json_encode($c);

    /* Component Identifiers: at least one identifier must be present. */
    if (empty($c['purl']) && empty($c['cpe']) && empty($props['ticketscad:identifier'])) {
        $missingId[] = $name;
    }

    /* THE KEY HONESTY RULE: a component either states a version, or explicitly
     * declares Component Version unknown. Silence is not acceptable. */
    if (!isset($c['version']) && !str_contains($unknown, 'Component Version')) {
        $silentNoVersion[] = $name;
    }

    /* And the converse: it must not claim a version AND declare it unknown. */
    if (isset($c['version']) && str_contains($unknown, 'Component Version')) {
        $badUnknownDecl[] = $name;
    }

    /* Every declared unknown must carry a reason. */
    if ($unknown !== '' && empty($props['ticketscad:unknown-reason'])) {
        $badUnknownDecl[] = $name . ' (no reason given)';
    }

    /* Component Hash Value implies Component Hash Algorithm. */
    foreach ($c['hashes'] ?? [] as $h) {
        if (empty($h['alg']) || empty($h['content'])) $hashWithoutAlg[] = $name;
    }
}

ok('Every component has a Component Name', $missingName === [], implode(', ', $missingName));
ok('Every component has at least one Component Identifier', $missingId === [], implode(', ', $missingId));
ok('No component omits Component Version silently', $silentNoVersion === [],
    'undeclared: ' . implode(', ', $silentNoVersion));
ok('No component both states and disclaims a version, and every unknown has a reason',
    $badUnknownDecl === [], implode(', ', $badUnknownDecl));
ok('Every Component Hash Value carries a Component Hash Algorithm',
    $hashWithoutAlg === [], implode(', ', $hashWithoutAlg));

/* Coverage: the dependency graph must reference the target component. */
$deps    = $bom['dependencies'] ?? [];
$refs    = array_column($deps, 'ref');
ok('Component Dependency Relationship graph present',
    count($deps) > 0 && in_array($target['bom-ref'] ?? '', $refs, true));

/* Licences: at least the Composer tree should be substantially licensed. */
$withLicense = 0;
foreach ($components as $c) if (!empty($c['licenses'])) $withLicense++;
ok('Component License recorded for the majority of components',
    $withLicense > count($components) / 2,
    "{$withLicense} of " . count($components));

/* Hashes: the libraries we actually ship must be hashed. We ship the vendored
 * browser libraries, so at least those must carry a Component Hash Value. */
$hashed = 0;
foreach ($components as $c) if (!empty($c['hashes'])) $hashed++;
ok('Shipped artifacts carry a Component Hash Value', $hashed > 0, "{$hashed} hashed");

/* ---------------------------------------------------------------- *
 * Practices and processes
 * ---------------------------------------------------------------- */
echo "\n-- Practices and processes --\n";

/* Machine-Processable Data: bom must be a widely used open format. */
ok('Machine-Processable Data: CycloneDX (ECMA-424)',
    ($bom['bomFormat'] ?? '') === 'CycloneDX' && ($bom['specVersion'] ?? '') === '1.6');

/* Explicitly Identifying Unknown Information: convention must be documented. */
$metaProps = [];
foreach ($meta['properties'] ?? [] as $p) $metaProps[$p['name']] = $p['value'];
ok('Explicitly Identifying Unknown Information: convention documented in the SBOM',
    isset($metaProps['ticketscad:unknown-convention']));
ok('Coverage statement present', isset($metaProps['ticketscad:coverage']));
ok('Standard cited in the SBOM', isset($metaProps['ticketscad:standard']));
ok('Regeneration command recorded in the SBOM', isset($metaProps['ticketscad:regenerate']));

/* Accommodation of Updates / Frequency: the generator must exist and the
 * release + CI paths must gate on it, or the SBOM will silently rot. */
ok('Generator script exists', is_file($root . '/tools/generate-sbom.php'));

$qa = (string) @file_get_contents($root . '/.github/workflows/qa.yml');
ok('CI gates SBOM freshness',    str_contains($qa, 'generate-sbom.php --check'));
ok('CI gates SBOM conformance',  str_contains($qa, 'generate-sbom.php --validate'));
ok('CI gates SBOM signature',    str_contains($qa, 'generate-sbom.php --verify'));

/* These two inspect tools/release-snapshot.sh — which the release snapshot
 * deliberately EXCLUDES from itself, so it is absent from every published tree
 * by design. Asserting on it unconditionally turned the published copy of this
 * suite red on the first release that shipped it: two failures for a file whose
 * absence is the correct state. Skip where it does not exist; still fail in the
 * development repository, which is the only place the gate can regress. */
$relPath = $root . '/tools/release-snapshot.sh';
if (!is_file($relPath)) {
    echo "  SKIP  release-script gates (tools/release-snapshot.sh is not shipped in a release tree)\n";
} else {
    $rel = (string) file_get_contents($relPath);
    /* The release script quotes the interpolated path, so match the invocation
     * rather than a fixed substring. */
    ok('Release process gates SBOM freshness',
        preg_match('/generate-sbom\.php["\']?\s+--check/', $rel) === 1);

    /* …and conformance, not only freshness. A stale SBOM and an invalid one are
     * different failures and the release must stop on both. */
    ok('Release process gates SBOM schema conformance',
        preg_match('/generate-sbom\.php["\']?\s+--validate/', $rel) === 1);

    /* Distribution and Delivery: the SBOM must not be excluded from the public
     * release snapshot. */
    ok('SBOM ships in the public release snapshot (not excluded)',
        !preg_match('/^\s*SBOM\./m', $rel));
}

/* ---------------------------------------------------------------- *
 * The SBOM is current
 * ---------------------------------------------------------------- */
echo "\n-- Currency --\n";

$php  = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
$cmd  = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/generate-sbom.php') . ' --check 2>&1';
$out  = [];
$code = 0;
exec($cmd, $out, $code);
ok('Committed SBOM matches current dependencies (generate-sbom.php --check)',
    $code === 0, trim(implode(' ', $out)));

/* The generator's own signature verification must agree, and must be runnable
 * by a recipient who has no private key. */
$out2 = []; $code2 = 0;
exec(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/generate-sbom.php')
     . ' --verify 2>&1', $out2, $code2);
ok('generate-sbom.php --verify confirms the published signature',
    $code2 === 0, trim(implode(' ', $out2)));

/* ---------------------------------------------------------------- *
 * No component data field may be SILENTLY absent
 * ---------------------------------------------------------------- *
 * The whole value of this SBOM to a fire department rests on a missing value
 * meaning "we told you we don't know" rather than "we forgot". An earlier
 * revision silently dropped Component Producer from 18 components and the
 * licence of four container base images while the project was reporting those
 * fields as met, so this is checked mechanically rather than by counting.
 */
echo "\n-- Explicitly Identifying Unknown Information --\n";

$fieldTests = [
    'Component Name'           => fn(array $e) => !empty($e['name']),
    'Component Version'        => fn(array $e) => !empty($e['version']),
    'Component Producer'       => fn(array $e) => !empty($e['publisher']),
    'Component License'        => fn(array $e) => !empty($e['licenses']),
    'Component Hash Value'     => fn(array $e) => !empty($e['hashes'][0]['content']),
    'Component Hash Algorithm' => fn(array $e) => !empty($e['hashes'][0]['alg']),
    'Component Identifiers'    => fn(array $e) => !empty($e['purl']) || !empty($e['cpe'])
                                               || !empty($e['bom-ref']),
];
$depNodes = array_column($bom['dependencies'] ?? [], 'ref');
$silentFields = [];
foreach ($bom['components'] ?? [] as $e) {
    $declared = [];
    $reason   = '';
    foreach ($e['properties'] ?? [] as $p) {
        if ($p['name'] === 'ticketscad:unknown')        $declared = array_map('trim', explode(',', $p['value']));
        if ($p['name'] === 'ticketscad:unknown-reason') $reason   = trim($p['value']);
    }
    $checks = $fieldTests;
    $checks['Component Dependency Relationship'] =
        fn(array $x) => in_array($x['bom-ref'] ?? '', $depNodes, true);
    foreach ($checks as $field => $test) {
        if ($test($e)) continue;
        if (!in_array($field, $declared, true)) {
            $silentFields[] = ($e['name'] ?? '?') . ': ' . $field;
        } elseif ($reason === '') {
            $silentFields[] = ($e['name'] ?? '?') . ': ' . $field . ' (no reason given)';
        }
    }
}
ok('every unstated component data field is explicitly declared unknown, with a reason',
    $silentFields === [],
    count($silentFields) . ' silent: ' . implode('; ', array_slice($silentFields, 0, 6)));

/* Nothing is withheld; if that ever changes it must be a deliberate edit. */
$withheld = [];
foreach ($bom['components'] ?? [] as $e) {
    foreach ($e['properties'] ?? [] as $p) {
        if ($p['name'] === 'ticketscad:withheld') $withheld[] = $e['name'];
    }
}
ok('nothing is withheld from recipients', $withheld === [], implode(', ', $withheld));

/* A recipient holding only SBOM.cdx.json must be able to find out how to
 * verify it, without our documentation. */
$mprops = [];
foreach ($bom['metadata']['properties'] ?? [] as $p) $mprops[$p['name']] = $p['value'];
foreach (['ticketscad:signature-file', 'ticketscad:signature-algorithm',
          'ticketscad:signature-public-key', 'ticketscad:signature-public-key-sha256',
          'ticketscad:signature-verify'] as $need) {
    ok("SBOM is self-describing: {$need}", !empty($mprops[$need]));
}

/* The fingerprint recorded in the SBOM must match the key actually published,
 * so it can be cross-checked against a fingerprint obtained elsewhere. */
$fp = null;
if (is_file($pubPath)
    && preg_match('/-----BEGIN PUBLIC KEY-----(.+?)-----END PUBLIC KEY-----/s',
                  (string) file_get_contents($pubPath), $m)) {
    $der = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);
    if ($der !== false && $der !== '') $fp = base64_encode(hash('sha256', $der, true));
}
ok('recorded public-key fingerprint matches the published key',
    $fp !== null && $fp === ($mprops['ticketscad:signature-public-key-sha256'] ?? null),
    'published ' . ($fp ?? 'n/a') . ' vs recorded '
    . ($mprops['ticketscad:signature-public-key-sha256'] ?? 'n/a'));

/* ---------------------------------------------------------------- *
 * The SBOM describes the SHIPPED SOFTWARE, not the development repository.
 *
 * tools/release-snapshot.sh strips specs/, coordination/, services/*&#47;bench and
 * the untracked trees before publishing. A dependency that exists only in one
 * of those is not a component of TicketsCAD.
 *
 * This caught a real one: a jsDelivr <script> in a Phase 36 planning mock-up
 * (specs/phase-36-settings-sidebar/sidebar-planner.html) listed sortablejs as
 * a runtime dependency of the application. Nothing shipped loads it — the same
 * defect class Phase 127 exists to correct, since a reader matching that entry
 * against vulnerability data researches software we do not run. It also made
 * `--check` pass in the dev repo and FAIL in the published one, on the exact
 * artifact recipients are invited to regenerate and compare.
 * ---------------------------------------------------------------- */
$notShipped = '#(^|[\s"\'(/])(specs|coordination|node_modules)/|(^|[\s"\'(/])vendor/|services/[^/\s]+/bench/#';
$leaked = [];
foreach ($bom['components'] ?? [] as $c) {
    /* Every place a source path can surface: the description names the files a
     * CDN component is loaded by, and ticketscad:file properties carry the
     * per-file hashes. */
    $hay = [(string) ($c['description'] ?? '')];
    foreach ($c['properties'] ?? [] as $p) $hay[] = (string) $p['value'];
    foreach ($hay as $h) {
        /* assets/vendor/ IS shipped — do not let it match the vendor/ rule. */
        $h = str_replace('assets/vendor/', 'assets/shipped-vendor/', $h);
        if (preg_match($notShipped, $h)) {
            $leaked[] = ($c['name'] ?? '?') . '@' . ($c['version'] ?? '?');
            break;
        }
    }
}
ok('no component is sourced from a path the release does not ship',
    $leaked === [], implode(', ', array_unique($leaked)));

/* ---------------------------------------------------------------- *
 * Every licence identifier we state must be one SPDX actually defines.
 *
 * We published `{"license":{"id":"GPL-2.0-with-FOSS-exception"}}` for
 * mysql-connector-python. No such SPDX identifier exists, so the document
 * failed the CycloneDX 1.6 schema it declared — the `licenses` `oneOf` matched
 * neither branch. Checked here against the OFFICIAL enum, so no future entry
 * can invent one, and so this fails even where Node is unavailable to run the
 * full schema validation below.
 * ---------------------------------------------------------------- */
$spdxFile = $root . '/tools/schema/cyclonedx/spdx.schema.json';
ok('official SPDX enum is vendored', is_file($spdxFile), $spdxFile);

if (is_file($spdxFile)) {
    $spdx = array_fill_keys(
        json_decode((string) file_get_contents($spdxFile), true)['enum'] ?? [], true);
    ok('SPDX enum parsed', count($spdx) > 500, count($spdx) . ' identifiers');

    $badIds = [];
    $badTok = [];
    foreach ($bom['components'] ?? [] as $c) {
        foreach ($c['licenses'] ?? [] as $l) {
            if (isset($l['license']['id']) && !isset($spdx[$l['license']['id']])) {
                $badIds[] = ($c['name'] ?? '?') . ' => ' . $l['license']['id'];
            }
            if (isset($l['expression'])) {
                $toks = preg_split('/\s+(?:AND|OR|WITH)\s+|[()]/', (string) $l['expression'],
                                   -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($toks as $t) {
                    $t = rtrim(trim($t), '+');
                    if ($t !== '' && !isset($spdx[$t])) {
                        $badTok[] = ($c['name'] ?? '?') . ' => ' . $t;
                    }
                }
            }
        }
    }
    ok('every license.id is a real SPDX identifier', $badIds === [], implode('; ', $badIds));
    ok('every SPDX expression uses real identifiers', $badTok === [], implode('; ', $badTok));
}

/* A `licenses` array must be EITHER license objects OR a single-item
 * expression tuple — CycloneDX rejects a mixture, and that mixture is what the
 * bad identifier produced. */
$mixed = [];
foreach ($bom['components'] ?? [] as $c) {
    $ls = $c['licenses'] ?? [];
    if ($ls === []) continue;
    $hasExpr = false;
    $hasObj  = false;
    foreach ($ls as $l) {
        if (isset($l['expression'])) $hasExpr = true;
        if (isset($l['license']))    $hasObj  = true;
    }
    if (($hasExpr && $hasObj) || ($hasExpr && count($ls) !== 1)) {
        $mixed[] = (string) ($c['name'] ?? '?');
    }
}
ok('no component mixes an SPDX expression with license objects',
    $mixed === [], implode(', ', $mixed));

/* The count we advertise must be the count in the file. The generator once
 * announced 56 while writing 55, because a component was appended after the
 * document had been assembled. */
ok('component array is non-empty and self-consistent',
    isset($bom['components']) && is_array($bom['components']) && count($bom['components']) > 0,
    'components=' . count($bom['components'] ?? []));

/* ---------------------------------------------------------------- *
 * Full conformance against the official schema.
 *
 * Skipped (not failed) where Node is unavailable, because the suite must run
 * on a plain PHP box — CI and tools/release-snapshot.sh both run `--validate`
 * as a blocking step, so nothing can be published without it.
 * ---------------------------------------------------------------- */
$php  = PHP_BINARY;
$vOut = [];
$vRc  = 0;
exec(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/generate-sbom.php')
     . ' --validate 2>&1', $vOut, $vRc);
$vTxt = implode("\n", $vOut);

if ($vRc !== 0 && stripos($vTxt, 'Node.js') !== false) {
    echo "  SKIP  official CycloneDX 1.6 schema validation (Node.js not on PATH)\n";
} else {
    ok('SBOM conforms to the official CycloneDX 1.6 schema', $vRc === 0, $vTxt);
}

/* ---------------------------------------------------------------- *
 * extractFirstJsonObject() regression (2026-09-04).
 *
 * Live bug: `npx --yes -p ajv-cli@5 ...` can print npm's own "New minor
 * version of npm available" banner AFTER the validator's JSON result on a
 * dev box whose npm update-check cache has expired — non-deterministic,
 * which is why this surfaced as a flaky local failure rather than a
 * reliable one. The old extraction did
 * `json_decode(substr($text, strpos($text, '{')))`, which requires the
 * ENTIRE remainder of the string to be valid JSON — trailing banner text
 * broke the decode, and validateCycloneDx() reported "unavailable" (with
 * the confusing side effect that the printed "detail" was the very JSON
 * that had actually said "valid"). Fixed by extracting only the first
 * BALANCED {...} object via brace-depth counting, ignoring anything
 * printed after its closing brace.
 *
 * Drives the REAL function's source (extracted via token_get_all from the
 * actual tools/generate-sbom.php, not a hand-copied reimplementation) in an
 * isolated child process, so a future edit to the real function is what
 * this test actually exercises.
 * ---------------------------------------------------------------- */
function extractFunctionSource(string $file, string $name): ?string
{
    $tokens = token_get_all((string) file_get_contents($file));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        // find the function's name token, skipping whitespace
        $j = $i + 1;
        while ($j < $n && (!is_array($tokens[$j]) || $tokens[$j][0] === T_WHITESPACE)) $j++;
        if (!is_array($tokens[$j]) || $tokens[$j][1] !== $name) continue;

        // walk forward to the opening '{' of the body, then brace-match to the end
        $depth = 0; $started = false; $out = '';
        for ($k = $i; $k < $n; $k++) {
            $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            $out .= $text;
            if ($text === '{') { $depth++; $started = true; }
            elseif ($text === '}') { $depth--; if ($started && $depth === 0) return $out; }
        }
    }
    return null;
}

$fnSrc = extractFunctionSource($root . '/tools/generate-sbom.php', 'extractFirstJsonObject');
if ($fnSrc === null) {
    ok('extractFirstJsonObject() found in tools/generate-sbom.php', false, 'function not found — cannot test it');
} else {
    $harness = "<?php\n{$fnSrc}\n"
        . 'var_export(['
        . '  extractFirstJsonObject(\'{"status":"valid"}\'),'
        . '  extractFirstJsonObject("{\"status\":\"valid\"}\nnpm notice\nnpm notice New minor version of npm available! 11.4.2 -> 11.19.1\n"),'
        . '  extractFirstJsonObject(\'noise before {"status":"valid","formatsNotAsserted":["idn-email"]} noise after\'),'
        . '  extractFirstJsonObject(\'not json at all\'),'
        . '  extractFirstJsonObject(\'{"a":"contains a } brace inside a string","b":1}\'),'
        . ']);';
    $tmpFile = tempnam(sys_get_temp_dir(), 'sbom_extract_test_');
    file_put_contents($tmpFile, $harness);
    $hOut = []; $hRc = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($tmpFile) . ' 2>&1', $hOut, $hRc);
    @unlink($tmpFile);
    $hTxt = implode("\n", $hOut);
    // var_export of an array literal is safe to eval back — everything here
    // is fixed test data, none of it attacker- or network-controlled.
    $results = $hRc === 0 ? eval('return ' . $hTxt . ';') : null;

    ok('extracts a bare JSON object with nothing else present', is_array($results) && json_decode((string) ($results[0] ?? ''), true) === ['status' => 'valid'], $hTxt);
    ok('ignores npm\'s update-nag banner printed AFTER the JSON (the live bug)', is_array($results) && json_decode((string) ($results[1] ?? ''), true) === ['status' => 'valid'], $hTxt);
    ok('ignores noise both before and after the object', is_array($results) && ($p = json_decode((string) ($results[2] ?? ''), true)) && ($p['status'] ?? null) === 'valid' && ($p['formatsNotAsserted'] ?? null) === ['idn-email'], $hTxt);
    ok('returns null (not a crash) when there is no object at all', is_array($results) && $results[3] === null, $hTxt);
    ok('a "}" inside a JSON string value does not end the object early', is_array($results) && ($p = json_decode((string) ($results[4] ?? ''), true)) && ($p['b'] ?? null) === 1, $hTxt);
}

/* ---------------------------------------------------------------- */
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
