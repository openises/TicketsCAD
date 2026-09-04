<?php
/**
 * Phase 127 — Software Bill of Materials generator.
 *
 * Produces:
 *   - SBOM.cdx.json  — CycloneDX 1.6 (ECMA-424), the machine-readable SBOM
 *   - SBOM.txt       — human-readable rendering of the same data
 *
 * Target standard: "2026 Minimum Elements for a Software Bill of Materials
 * (SBOM)", CISA/NSA/FBI + international partners, published 2026-07-29.
 * https://www.cisa.gov/resources-tools/resources/2026-minimum-elements-software-bill-materials-sbom
 *
 * DESIGN RULE — NO INVENTED VERSIONS.
 * Every version in the output is either (a) read from a lockfile, (b) detected
 * at generation time by matching a regex against the actual shipped file, or
 * (c) explicitly recorded as unknown. This script never hardcodes a version
 * number for a vendored library. If a library is upgraded, the detected version
 * changes on the next run; if a banner disappears, the component degrades to
 * "unknown" rather than silently reporting a stale value. That is deliberate:
 * an SBOM with invented versions is a false attestation.
 *
 * UNKNOWN-INFORMATION CONVENTION (CISA "Explicitly Identifying Unknown
 * Information"). Components carry properties:
 *   ticketscad:unknown         comma-separated CISA field names not known
 *   ticketscad:unknown-reason  why the value could not be determined
 * Nothing in this SBOM is withheld; if that ever changes, use
 *   ticketscad:withheld        comma-separated CISA field names withheld
 *
 * Usage:
 *   php tools/generate-sbom.php            regenerate SBOM.cdx.json + SBOM.txt
 *   php tools/generate-sbom.php --check    exit 1 if the committed SBOM is
 *                                          out of date (for CI / release gate)
 *   php tools/generate-sbom.php --verify    exit 1 unless the committed
 *                                          SBOM.cdx.json.sig verifies against
 *                                          the published public key. Needs no
 *                                          private key — anyone can run it.
 *   php tools/generate-sbom.php --sign-key=/path/to/ec-private.pem
 *                                          additionally write a detached
 *                                          signature (SBOM Author Signature)
 *
 * Idempotent: re-running with no dependency changes rewrites byte-identical
 * files (the timestamp and SBOM version only advance when content changes).
 * That extends to the signature: an existing signature that still verifies is
 * left untouched rather than re-created, because ECDSA is randomised and
 * re-signing identical content would otherwise churn the file on every run.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "[FAIL] cannot resolve application root\n");
    exit(1);
}

$opts         = getopt('', ['check', 'verify', 'validate', 'sign-key::']);
$checkOnly    = array_key_exists('check', $opts);
$verifyOnly   = array_key_exists('verify', $opts);
$validateOnly = array_key_exists('validate', $opts);
$signKey      = $opts['sign-key'] ?? null;

/* ------------------------------------------------------------------ *
 * Identity of this generator (CISA: SBOM Tool Name / SBOM Tool Version)
 * ------------------------------------------------------------------ */
const SBOM_TOOL_NAME    = 'TicketsCAD SBOM generator (tools/generate-sbom.php)';
const SBOM_TOOL_VERSION = '2.1.0';

/* ------------------------------------------------------------------ *
 * CISA: SBOM Author Signature
 * ------------------------------------------------------------------ *
 * A detached signature over the exact bytes of SBOM.cdx.json, base64-encoded
 * into SBOM.cdx.json.sig. The matching PUBLIC key is committed to this
 * repository — publishing it is what makes the signature checkable by someone
 * who has never spoken to us. The PRIVATE key is held by the maintainer
 * outside the repository and is never committed (see docs/SECURITY-POLICY.md
 * §5.3).
 *
 * Algorithm: ECDSA on NIST P-256 (prime256v1) with SHA-256.
 *
 * Why not Ed25519, which would otherwise be the modern default: PHP's OpenSSL
 * extension cannot sign with it. openssl_sign() uses the streaming
 * EVP_Sign* API, and Ed25519 requires the one-shot EVP_DigestSign API, so an
 * Ed25519 key loads fine and then fails at signing time with
 * "operation not supported for this keytype" (verified on PHP 8.2 /
 * OpenSSL 3.0.8). ext-sodium, which does implement Ed25519, is not enabled in
 * the stock XAMPP build the maintainer runs, and requiring it would mean a
 * future maintainer could not re-sign a release on a default install.
 * ECDSA P-256 + SHA-256 is a NIST SP 800-57 approved 128-bit-security pair,
 * current under SP 800-131A Rev. 2, it is the ES256 of JOSE/JSF, and it can be
 * verified with a one-line `openssl dgst` that ships with every operating
 * system. That last property matters more here than algorithm fashion: a
 * signature is only worth the ease with which a stranger can check it.
 *
 * Detached rather than the CycloneDX in-document `signature` (JSF) field:
 * a detached signature covers the literal published bytes, so a verifier does
 * not have to reproduce a JSON canonicalisation to check it.
 */
const SBOM_SIG_ALGORITHM = 'ECDSA-P256-SHA256';
const SBOM_SIG_CURVE     = 'prime256v1';
const SBOM_PUBKEY_FILE   = 'SBOM-signing-key.pub.pem';
const SBOM_SIG_FILE      = 'SBOM.cdx.json.sig';

/**
 * Fingerprint a public key: SHA-256 over its DER SubjectPublicKeyInfo,
 * base64-encoded. Identical to
 *   openssl pkey -pubin -in <key> -outform DER | openssl dgst -sha256 -binary | openssl base64
 *
 * Fingerprinting the DER rather than comparing PEM text matters: PEM differs
 * harmlessly by line ending and trailing newline between OpenSSL builds and
 * platforms, so PEM string equality gives false mismatches on Windows.
 */
function sbom_pubkey_fingerprint(string $pem): ?string
{
    if (!preg_match('/-----BEGIN PUBLIC KEY-----(.+?)-----END PUBLIC KEY-----/s', $pem, $m)) {
        return null;
    }
    $der = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);
    if ($der === false || $der === '') return null;
    return base64_encode(hash('sha256', $der, true));
}

/**
 * Verify a detached SBOM signature against a published public key.
 *
 * @return array{0:bool,1:string} [ok, human-readable explanation]
 */
function sbom_verify_detached(string $sbomBytes, string $sigPath, string $pubPath): array
{
    if (!function_exists('openssl_verify')) {
        return [false, 'the OpenSSL extension is required to verify the signature'];
    }
    if (!is_file($sigPath)) {
        return [false, 'no signature file at ' . basename($sigPath)];
    }
    if (!is_file($pubPath)) {
        return [false, 'no published public key at ' . basename($pubPath)];
    }
    $sig = base64_decode(trim((string) file_get_contents($sigPath)), true);
    if ($sig === false || $sig === '') {
        return [false, basename($sigPath) . ' is not valid base64'];
    }
    $pub = openssl_pkey_get_public((string) file_get_contents($pubPath));
    if ($pub === false) {
        return [false, basename($pubPath) . ' is not a readable public key'];
    }
    $res = openssl_verify($sbomBytes, $sig, $pub, OPENSSL_ALGO_SHA256);
    if ($res === 1)  return [true,  'signature is valid for the SBOM bytes on disk'];
    if ($res === 0)  return [false, 'signature does NOT match the SBOM bytes on disk'];
    return [false, 'verification error: ' . (openssl_error_string() ?: 'unknown')];
}

/* --validate: does the COMMITTED document actually conform to the CycloneDX
 * 1.6 schema it claims? Standalone, like --verify, so a recipient or CI can
 * run it without a dependency scan. This exists because we declared
 * "specVersion": "1.6" for a full day while the document was invalid, and
 * nothing in the pipeline had ever asked the schema. */
if ($validateOnly) {
    [$st, $detail] = validateCycloneDx($root . '/SBOM.cdx.json');
    if ($st === 'valid') {
        echo "[OK] SBOM.cdx.json validates against the official CycloneDX 1.6 schema\n";
        echo "     schema: tools/schema/cyclonedx/bom-1.6.schema.json (vendored, unmodified)\n";
        exit(0);
    }
    if ($st === 'unavailable') {
        fwrite(STDERR, "[FAIL] cannot validate: {$detail}\n");
        fwrite(STDERR, "       Schema validation needs Node.js on PATH (it runs ajv against\n");
        fwrite(STDERR, "       the vendored official schema). Install Node, or validate by hand —\n");
        fwrite(STDERR, "       see tools/schema/cyclonedx/README.md.\n");
        exit(1);
    }
    fwrite(STDERR, "[FAIL] SBOM.cdx.json does NOT conform to CycloneDX 1.6:\n{$detail}\n");
    exit(1);
}

/* --verify: a standalone integrity check over what is committed. Needs no
 * private key and no dependency scan, so a recipient — or CI — can run it. */
if ($verifyOnly) {
    $vJson = $root . '/SBOM.cdx.json';
    if (!is_file($vJson)) {
        fwrite(STDERR, "[FAIL] SBOM.cdx.json not found\n");
        exit(1);
    }
    [$vOk, $vWhy] = sbom_verify_detached(
        (string) file_get_contents($vJson),
        $root . '/' . SBOM_SIG_FILE,
        $root . '/' . SBOM_PUBKEY_FILE
    );
    if ($vOk) {
        echo "[OK] SBOM Author Signature verified (" . SBOM_SIG_ALGORITHM . ")\n";
        echo "     " . $vWhy . "\n";
        echo "     public key: " . SBOM_PUBKEY_FILE . "\n";
        exit(0);
    }
    fwrite(STDERR, "[FAIL] SBOM Author Signature did not verify: {$vWhy}\n");
    exit(1);
}

/* CISA: SBOM Author — the entity operating the tool, not the tool itself. */
const SBOM_AUTHOR       = 'OpenISES TicketsCAD Project';
const SBOM_AUTHOR_EMAIL = 'ejosterberg@gmail.com';

/* CISA: SBOM Generation Context. This SBOM is produced from the source tree
 * (lockfiles + shipped vendored artifacts), before any build/packaging step. */
const SBOM_LIFECYCLE_PHASE = 'pre-build';

$appVersion = trim((string) @file_get_contents($root . '/VERSION'));
if ($appVersion === '') {
    fwrite(STDERR, "[FAIL] VERSION file missing or empty — refusing to guess the application version\n");
    exit(1);
}

/* ================================================================== *
 * Helpers
 * ================================================================== */

/** SHA-256 of a shipped file, or null when the file is absent. */
function fileSha256(string $abs): ?string
{
    return is_file($abs) ? hash_file('sha256', $abs) : null;
}

/**
 * Detect a version by matching $regex against the head of a real file.
 * Returns null when the file is missing or carries no version banner.
 * Never falls back to a default — that is the whole point.
 */
function detectVersion(string $abs, string $regex, int $bytes = 4096, bool $tail = false): ?string
{
    if (!is_file($abs)) return null;
    $size = filesize($abs);
    $fh   = fopen($abs, 'rb');
    if (!$fh) return null;
    if ($tail && $size > $bytes) fseek($fh, $size - $bytes);
    $buf = (string) fread($fh, $bytes);
    fclose($fh);
    return preg_match($regex, $buf, $m) ? $m[1] : null;
}

/** Relative path for display, always forward-slashed. */
function relPath(string $abs, string $root): string
{
    return str_replace('\\', '/', substr($abs, strlen($root) + 1));
}

/**
 * Recursively list files under $dir matching $regex, in a STABLE, SORTED order.
 *
 * Directory traversal order is a property of the filesystem, not of the
 * repository: NTFS hands back names in a different order from ext4. Anything
 * derived from raw traversal order — which files land in a "Used by:" list, and
 * in what sequence — therefore changes with the operating system, which changes
 * the content digest, which makes `--check` fail on a machine that is not the
 * one the SBOM was generated on.
 *
 * That is not hypothetical. It is why the CI freshness gate failed on every run
 * from the moment it was introduced: the SBOM was generated on Windows and
 * checked on Linux, and the two disagreed about nothing more than the order of
 * some filenames. Sorting here makes the SBOM reproducible across platforms,
 * which is the whole premise of shipping the generator alongside the SBOM so a
 * recipient can regenerate and compare instead of taking our word for it.
 *
 * @return string[] absolute paths, sorted by their repository-relative form
 */
function scanFiles(string $dir, string $regex): array
{
    if (!is_dir($dir)) return [];
    $out = [];
    $it  = new RegexIterator(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        ),
        $regex
    );
    foreach ($it as $f) {
        if (isNotShipped($f->getPathname())) continue;
        $out[] = $f->getPathname();
    }
    /* Sort on the normalised path so Windows backslashes cannot change the
     * ordering relative to Linux forward slashes. */
    usort($out, fn(string $a, string $b): int
        => str_replace('\\', '/', $a) <=> str_replace('\\', '/', $b));
    return $out;
}

/**
 * True for paths that exist in the development repository but are NOT part of
 * the distributed software. `tools/release-snapshot.sh` strips these before the
 * public tree is published, so a dependency found only here is not a component
 * of TicketsCAD and must not appear in its SBOM.
 *
 * This is not housekeeping. Without it, a jsDelivr `<script>` in a Phase 36
 * planning mock-up (`specs/phase-36-settings-sidebar/sidebar-planner.html`) put
 * **sortablejs** into the SBOM as a runtime dependency of the application.
 * Nothing we ship loads it. That is the same defect Phase 127 was created to
 * correct — an SBOM naming software the application does not use — and it is
 * the one that matters most, because a reader matching that entry against
 * vulnerability data is researching software we do not run.
 *
 * It also broke verification in the only tree that counts. `--check` passed in
 * the development repository (which has `specs/`) and FAILED in the published
 * one (which does not), on the very artifact we invite recipients to regenerate
 * and compare against ours.
 *
 * Keep in step with the EXCLUDES list in `tools/release-snapshot.sh`.
 */
function isNotShipped(string $absPath): bool
{
    global $root;
    $p = str_replace('\\', '/', $absPath);
    $r = str_replace('\\', '/', (string) $root);
    if ($r !== '' && strncmp($p, $r . '/', strlen($r) + 1) === 0) {
        $p = substr($p, strlen($r) + 1);
    }

    /* Top-level trees the snapshot removes, or that git never tracks. Anchored
     * at the repository root, so the SHIPPED `assets/vendor/` browser libraries
     * are unaffected by the `vendor/` entry.
     *
     * `.claude/` earns its place the same way `specs/` did. Claude Code keeps
     * agent worktrees at `.claude/worktrees/<name>/` — entire second copies of
     * this repository, `specs/` and all. The `specs/` entry above is anchored
     * at the root, so it does not match `.claude/worktrees/x/specs/…`, and the
     * planning mock-up described above walked straight back into the SBOM as
     * sortablejs. The document then differed from the committed one on any
     * machine that happened to have a worktree open — an SBOM whose contents
     * depend on the developer's tooling state is not reproducible, which is
     * most of what an SBOM is for. */
    foreach (['specs/', 'coordination/', 'vendor/', 'node_modules/', '.git/', '.claude/'] as $prefix) {
        if (strncmp($p, $prefix, strlen($prefix)) === 0) return true;
    }

    /* services/<service>/bench/ — benchmark harnesses, also excluded. */
    return (bool) preg_match('#^services/[^/]+/bench/#', $p);
}

/**
 * Join shell/Dockerfile line continuations so one logical command is one string.
 *
 * Everything below depends on this. The package lists this generator has to
 * read are wrapped across lines almost without exception —
 *
 *     pip install --quiet \
 *         numpy onnxruntime piper-tts
 *
 * — so a scanner that reads physical lines sees "pip install --quiet" and finds
 * no packages at all. That is not a hypothetical: `onnxruntime` and `piper-tts`
 * were installed by this project's own installer and missing from its own SBOM,
 * on exactly that line, while the project published a claim that the document
 * covered the whole dependency chain.
 *
 * @return string[] logical lines
 */
function joinContinuations(string $src): array
{
    return preg_split('/\r?\n/', (string) preg_replace('/\\\\\r?\n/', ' ', $src)) ?: [];
}

/**
 * Extract the package names from one install command.
 *
 * Given a logical line and a regex matching the install verb, returns the bare
 * package tokens that follow it: option flags, redirections, shell variables and
 * anything path-like are dropped, and the argument list is cut at the first
 * shell operator so a chained `&& docker-php-ext-configure gd` cannot leak "gd"
 * in as an apt package.
 *
 * DELIBERATELY CONSERVATIVE. A token this cannot resolve with confidence — a
 * `$VARIABLE` package name, a local `.deb` path, a glob — is skipped rather than
 * guessed at. A name invented by a parser is worse than a name absent: it sends
 * a reader looking for vulnerability data about software that does not exist,
 * and this project has already published one wrong component identifier. Where
 * skipping loses something real, the component is declared explicitly instead
 * (see the downloaded-artifacts section), with its evidence cited.
 *
 * @return string[] package names, in the order written
 */
function installArgs(string $line, string $verbRegex): array
{
    /* A commented-out or narrative line is not an install command. Skipping it
     * is not tidiness: `services/aprs/install.sh` line 7 is the prose comment
     * "apt install python3-pip + pip install aprslib + ...", which without this
     * guard put `install`, `pip` and two PyPI names into the SBOM as Debian
     * packages. A parser artifact in a bill of materials is exactly the false
     * identifier this file's header rule forbids. */
    if (preg_match('#^\s*(?:\#|//)#', $line)) return [];

    if (!preg_match($verbRegex, $line, $m, PREG_OFFSET_CAPTURE)) return [];
    $rest = substr($line, $m[0][1] + strlen($m[0][0]));

    /* Stop at the first shell operator, redirection or comment. */
    $rest = preg_split('/\s(?:&&|\|\||[;|>]|#)/', $rest, 2)[0];

    $out = [];
    foreach (preg_split('/\s+/', trim($rest)) ?: [] as $tok) {
        $tok = trim($tok, "\"'");
        if ($tok === '') continue;
        if ($tok[0] === '-') continue;                      // option flag
        if (str_contains($tok, '$')) continue;              // shell variable
        if (str_contains($tok, '/')) continue;              // path, URL, local file
        if (str_contains($tok, '=')) continue;              // VAR=value
        if (str_contains($tok, '*')) continue;              // glob
        /* Package names: letters, digits, and . _ + - only. */
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._+-]*$/', $tok)) continue;
        /* Package-manager sub-commands, which can never be package names. The
         * list is deliberately this short: `python3`, `pip` and `curl` all look
         * like sub-commands and are all genuinely installed by this project's
         * scripts, so a longer stop-list would silently drop real components —
         * the precise failure this whole section exists to end. */
        if (in_array($tok, ['install', 'update', 'upgrade', 'remove', 'purge',
                            'clean', 'autoremove'], true)) continue;
        $out[] = $tok;
    }
    return $out;
}

/**
 * Every file in the tree that can install software: shell scripts, Dockerfiles,
 * and the JavaScript that GENERATES an installer for the operator to run
 * (assets/js/mesh-console.js emits a bash script containing a pip line — the
 * packages in it are as real as any other, and no manifest anywhere names them).
 *
 * @return string[] absolute paths
 */
function installerFiles(string $root): array
{
    $files = scanFiles($root, '/\.(sh|bat)$/i');
    foreach (scanFiles($root, '/(^|[\/\\\\])Dockerfile(\.[^\/\\\\]+)?$/i') as $f) {
        $files[] = $f;
    }
    $generated = $root . '/assets/js/mesh-console.js';
    if (is_file($generated)) $files[] = $generated;
    usort($files, fn(string $a, string $b): int
        => str_replace('\\', '/', $a) <=> str_replace('\\', '/', $b));
    return $files;
}

/** Absolute path to the vendored, unmodified official CycloneDX schemas. */
function schemaDir(): string
{
    global $root;
    return $root . '/tools/schema/cyclonedx';
}

/**
 * The 811 identifiers SPDX defines, read from the OFFICIAL enum CycloneDX
 * `$ref`s — never a list maintained by hand here. Covers licence ids and
 * exception ids, which is what `spdx.schema.json` itself contains.
 *
 * @return array<string,true>
 */
function spdxIdentifiers(): array
{
    static $ids = null;
    if ($ids !== null) return $ids;
    $f = schemaDir() . '/spdx.schema.json';
    if (!is_file($f)) {
        fwrite(STDERR, "!! Missing {$f} — cannot check licence identifiers.\n");
        exit(1);
    }
    $j   = json_decode((string) file_get_contents($f), true);
    $ids = array_fill_keys($j['enum'] ?? [], true);
    return $ids;
}

/**
 * Render a licence into a schema-legal CycloneDX `licenses` value, and REFUSE
 * to invent an identifier SPDX does not define.
 *
 * Accepts either a bare SPDX identifier string, or `['expression' => '...']`
 * for a licence that needs an SPDX expression (a `WITH` exception, a dual
 * licence), or `['name' => '...']` for a licence with no SPDX identifier at
 * all — a named licence makes no SPDX claim, which is the honest rendering
 * when none applies.
 *
 * Why this function exists: `mysql-connector-python` was emitted as
 * `{"license":{"id":"GPL-2.0-with-FOSS-exception"}}`. That identifier does not
 * exist in SPDX, so the published document failed the CycloneDX 1.6 schema it
 * declared conformance to — while inviting people to load it into
 * Dependency-Track and Trivy. The identifier is checked against the official
 * enum here, at the point of emission, so no future entry can repeat it.
 *
 * @param string|array{expression?:string,name?:string} $license
 * @return array<int,array<string,mixed>>
 */
function licenseChoice($license, string $componentName): array
{
    $ids = spdxIdentifiers();

    if (is_array($license) && isset($license['name'])) {
        /* No SPDX identifier claimed. Always schema-legal. */
        return [['license' => ['name' => $license['name']]]];
    }

    if (is_array($license) && isset($license['expression'])) {
        $expr = trim((string) $license['expression']);
        /* Check every identifier token in the expression against the official
         * enum. The schema does not do this for expressions, so we do. */
        $tokens = preg_split('/\s+(?:AND|OR|WITH)\s+|[()]/', $expr, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $t) {
            $t = rtrim(trim($t), '+');
            if ($t !== '' && !isset($ids[$t])) {
                fwrite(STDERR, "!! SPDX expression for {$componentName} uses "
                    . "'{$t}', which is not in the official SPDX list.\n"
                    . "   Expression: {$expr}\n"
                    . "   Use a real identifier, or ['name' => '<free text>'] to claim none.\n");
                exit(1);
            }
        }
        /* CycloneDX: an expression is a tuple of EXACTLY ONE object, and must
         * not be mixed with `license` objects in the same array. */
        return [['expression' => $expr]];
    }

    $id = (string) $license;
    if (!isset($ids[$id])) {
        fwrite(STDERR, "!! '{$id}' (component {$componentName}) is not an SPDX "
            . "identifier.\n   Supply ['expression' => '...'] for a licence with an "
            . "exception, or ['name' => '...'] to claim no SPDX identifier.\n");
        exit(1);
    }
    return [['license' => ['id' => $id]]];
}

/**
 * The licence as a human reads it, for SBOM.txt. Mirrors licenseChoice(): a
 * bare identifier, an SPDX expression, or a named licence claiming no SPDX
 * identifier.
 *
 * @param string|array{expression?:string,name?:string}|null $license
 */
function licenseLabel($license): string
{
    if ($license === null)                    return 'license unknown';
    if (is_array($license)) {
        if (isset($license['expression']))    return (string) $license['expression'];
        if (isset($license['name']))          return (string) $license['name'];
        return 'license unknown';
    }
    return (string) $license;
}

/**
 * Validate a CycloneDX document against the OFFICIAL vendored schema.
 *
 * Deliberately not hand-rolled: this shells out to ajv, the reference
 * JSON-Schema validator, against the unmodified upstream schema. A partial
 * check written here would be exactly the false assurance that produced the
 * problem — we asserted "CycloneDX 1.6" all day and nothing ever checked it.
 *
 * @return array{0:string,1:string} [status, detail] where status is
 *         'valid' | 'invalid' | 'unavailable'
 */

/**
 * Find the first top-level {...} object in $text by brace-depth counting
 * (respecting quoted strings and backslash escapes) and return just that
 * substring — never "from the first { to end of string", which breaks the
 * moment anything (npm's own notices, a trailing newline banner) is printed
 * after the JSON a subprocess emitted. Returns null if no balanced object
 * is found at all.
 */
function extractFirstJsonObject(string $text): ?string
{
    $start = strpos($text, '{');
    if ($start === false) return null;

    $depth = 0;
    $inString = false;
    $escaped = false;
    $len = strlen($text);
    for ($i = $start; $i < $len; $i++) {
        $ch = $text[$i];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($ch === '\\') {
                $escaped = true;
            } elseif ($ch === '"') {
                $inString = false;
            }
            continue;
        }
        if ($ch === '"') { $inString = true; continue; }
        if ($ch === '{') { $depth++; continue; }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }
    }
    return null; // never balanced — truncated output, not a parse-after-the-fact problem
}

function validateCycloneDx(string $bomPath): array
{
    if (!is_file($bomPath)) return ['invalid', "no such file: {$bomPath}"];

    $dir = schemaDir();
    foreach (['bom-1.6.schema.json', 'spdx.schema.json', 'jsf-0.82.schema.json'] as $s) {
        if (!is_file($dir . '/' . $s)) return ['unavailable', "vendored schema missing: {$s}"];
    }

    $npx = trim((string) shell_exec(
        stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where npx 2>NUL' : 'command -v npx 2>/dev/null'
    ));
    if ($npx === '') {
        return ['unavailable', 'npx (Node.js) not found on PATH'];
    }

    global $root;
    $runner = $root . '/tools/validate-sbom.mjs';
    if (!is_file($runner)) return ['unavailable', 'tools/validate-sbom.mjs is missing'];

    /* ajv-cli is requested only because it guarantees a node_modules/.bin on
     * PATH for the runner to resolve ajv and ajv-formats from; the runner
     * drives ajv itself. */
    $cmd = 'npx --yes -p ajv-cli@5 -p ajv-formats@3 node '
         . escapeshellarg($runner) . ' ' . escapeshellarg($bomPath) . ' 2>&1';

    $out  = [];
    $code = 0;
    exec($cmd, $out, $code);

    /* npm chatters about deprecated transitive packages and (intermittently
     * — only when npm's own update cache has expired, which is why this
     * surfaced as a flaky local failure rather than a reliable one) a
     * "New minor version of npm available" banner; keep the substance. */
    $text = implode("\n", array_filter($out, static fn($l) => stripos(ltrim($l), 'npm warn') !== 0
        && stripos(ltrim($l), 'npm notice') !== 0));

    /* The runner emits one JSON object, but npx/npm can still print trailing
     * lines AFTER it that the filter above doesn't recognize (seen live:
     * npm's update-nag banner landed after the JSON, once, non-deterministically
     * — `json_decode(substr($text, $brace))` requires the ENTIRE remainder to
     * be valid JSON, so trailing garbage fails the decode even though the
     * validator's own answer, sitting right there in $text, was "valid").
     * Extract only the FIRST balanced {...} object by brace-depth, ignoring
     * anything after its closing brace, rather than assuming the object runs
     * to the end of the string. */
    $parsed = json_decode((string) extractFirstJsonObject($text), true);
    if (!is_array($parsed) || !isset($parsed['status'])) {
        return ['unavailable', trim($text) !== '' ? trim($text) : 'validator produced no result'];
    }

    if ($parsed['status'] === 'valid') {
        $skipped = $parsed['formatsNotAsserted'] ?? [];
        return ['valid', $skipped
            ? 'format keywords not asserted (unimplemented by ajv-formats): '
              . implode(', ', $skipped)
            : ''];
    }
    if ($parsed['status'] === 'unavailable') {
        return ['unavailable', (string) ($parsed['error'] ?? 'unknown')];
    }
    return ['invalid', json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
}

/**
 * Build a component record. $version === null means "unknown to the SBOM
 * author" and is rendered explicitly rather than omitted silently.
 */
function component(array $c): array
{
    return $c + [
        'version'        => null,
        'license'        => null,
        'publisher'      => null,
        'description'    => null,
        'purl'           => null,
        'cpe'            => null,
        'sourceUrl'      => null,
        'hash'           => null,
        'files'          => [],
        'unknown'        => [],
        'unknownReason'  => null,
        'identifiers'    => [],
        'notes'          => [],
        'type'           => 'library',
    ];
}

$components = [];

/* ================================================================== *
 * 1. PHP dependencies — composer.lock (authoritative, exact versions)
 * ================================================================== */
$lockPath = $root . '/composer.lock';
if (!is_file($lockPath)) {
    fwrite(STDERR, "[FAIL] composer.lock not found at {$lockPath}\n");
    exit(1);
}
$lock = json_decode((string) file_get_contents($lockPath), true);
if (!is_array($lock)) {
    fwrite(STDERR, "[FAIL] composer.lock is not valid JSON\n");
    exit(1);
}

foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
    $name    = (string) $p['name'];
    $version = ltrim((string) $p['version'], 'v');
    $ref     = $p['source']['reference'] ?? ($p['dist']['reference'] ?? null);

    $identifiers = ['pkg:composer/' . $name . '@' . $version];
    if ($ref) $identifiers[] = 'git-commit:' . $ref;

    /* Composer packages are resolved into vendor/ at install time. vendor/ is
     * gitignored and is not part of the distributed tree, so we do not have the
     * executable artifact to hash. composer.lock carries no dist.shasum for
     * GitHub zipballs either. Record the pinned commit as the integrity anchor
     * and mark the hash unknown, per CISA. */
    $components[] = component([
        'key'           => 'composer:' . $name,
        'name'          => $name,
        'version'       => $version,
        'publisher'     => explode('/', $name)[0],
        'license'       => $p['license'][0] ?? null,
        'description'   => $p['description'] ?? null,
        'purl'          => 'pkg:composer/' . $name . '@' . $version,
        'sourceUrl'     => $p['source']['url'] ?? ($p['homepage'] ?? null),
        'identifiers'   => $identifiers,
        'group'         => 'php-composer',
        'unknown'       => ['Component Hash Value', 'Component Hash Algorithm'],
        'unknownReason' => 'Package is installed by Composer at deploy time from the pinned '
                         . 'source commit; the executable artifact is not distributed with this '
                         . 'repository, so the SBOM author has no artifact to hash. The pinned '
                         . 'git commit reference is supplied as a Component Identifier instead.',
        'notes'         => $ref ? ['Pinned commit: ' . $ref] : [],
    ]);
}

/* ================================================================== *
 * 2. Vendored frontend libraries — versions DETECTED from real files
 * ================================================================== *
 * 'detect' names the artifact whose banner carries the version, plus the
 * regex. 'files' lists every shipped file belonging to the component; each is
 * hashed individually so the SBOM covers the whole shipped footprint.
 * A null 'detect' means the shipped files genuinely carry no version marker.
 */
$vendored = [
    [
        'name'      => 'Bootstrap',
        'publisher' => 'The Bootstrap Authors',
        'license'   => 'MIT',
        'purlBase'  => 'pkg:npm/bootstrap',
        'cpeBase'   => 'cpe:2.3:a:getbootstrap:bootstrap',
        'sourceUrl' => 'https://github.com/twbs/bootstrap',
        'detect'    => ['assets/vendor/bootstrap/bootstrap.min.css', '/Bootstrap\s+v(\d+\.\d+\.\d+)/'],
        'files'     => [
            'assets/vendor/bootstrap/bootstrap.min.css',
            'assets/vendor/bootstrap/bootstrap.bundle.min.js',
        ],
        'notes'     => ['bootstrap.bundle.min.js embeds Popper.js v2; upstream Bootstrap does not '
                      . 'expose a separate Popper version string in the bundle, so Popper is not '
                      . 'separately enumerable from the shipped artifact.'],
    ],
    [
        'name'      => 'Bootstrap Icons',
        'publisher' => 'The Bootstrap Authors',
        'license'   => 'MIT',
        'purlBase'  => 'pkg:npm/bootstrap-icons',
        'sourceUrl' => 'https://github.com/twbs/icons',
        'detect'    => ['assets/vendor/bootstrap/bootstrap-icons.min.css', '/Bootstrap Icons v(\d+\.\d+\.\d+)/'],
        'files'     => [
            'assets/vendor/bootstrap/bootstrap-icons.min.css',
            'assets/vendor/bootstrap/fonts/bootstrap-icons.woff',
            'assets/vendor/bootstrap/fonts/bootstrap-icons.woff2',
            'assets/vendor/fonts/bootstrap-icons.woff',
            'assets/vendor/fonts/bootstrap-icons.woff2',
        ],
        'notes'     => ['The webfont binaries carry no embedded version string; their version is '
                      . 'that of the stylesheet that references them.'],
    ],
    [
        'name'      => 'Leaflet',
        'publisher' => 'Volodymyr Agafonkin',
        'license'   => 'BSD-2-Clause',
        'purlBase'  => 'pkg:npm/leaflet',
        'cpeBase'   => 'cpe:2.3:a:leafletjs:leaflet',
        'sourceUrl' => 'https://github.com/Leaflet/Leaflet',
        'detect'    => ['assets/vendor/leaflet/leaflet.js', '/Leaflet\s+(\d+\.\d+\.\d+)/'],
        'files'     => [
            'assets/vendor/leaflet/leaflet.js',
            'assets/vendor/leaflet/leaflet.css',
            'assets/vendor/leaflet/images/layers-2x.png',
            'assets/vendor/leaflet/images/layers.png',
            'assets/vendor/leaflet/images/marker-icon-2x.png',
            'assets/vendor/leaflet/images/marker-icon.png',
            'assets/vendor/leaflet/images/marker-shadow.png',
        ],
        'notes'     => ['leaflet.css and the marker sprites carry no independent version banner; '
                      . 'they ship as part of the same Leaflet distribution as leaflet.js.'],
    ],
    [
        'name'      => 'GridStack.js',
        'publisher' => 'Alain Dumesny',
        'license'   => 'MIT',
        'purlBase'  => 'pkg:npm/gridstack',
        'sourceUrl' => 'https://github.com/gridstack/gridstack.js',
        /* The webpack bundle has no header banner; the only version marker is
         * GDRev near the end of the file, so scan the tail. */
        'detect'    => ['assets/vendor/gridstack/gridstack-all.js', '/GDRev\s*=\s*"(\d+\.\d+\.\d+)"/', true],
        'files'     => [
            'assets/vendor/gridstack/gridstack-all.js',
            'assets/vendor/gridstack/gridstack.min.css',
            'assets/vendor/gridstack/gridstack-extra.min.css',
        ],
        'notes'     => ['gridstack-all.js references a LICENSE.txt and a source map that are not '
                      . 'shipped. Version is read from the GDRev marker in the bundle.'],
    ],
    [
        'name'      => 'qrcode-generator',
        'publisher' => 'Kazuhiko Arase',
        'license'   => 'MIT',
        'purlBase'  => 'pkg:npm/qrcode-generator',
        'sourceUrl' => 'https://github.com/kazuhikoarase/qrcode-generator',
        'detect'    => ['assets/vendor/qrcode/qrcode-generator.min.js', '/qrcode-generator@(\d+\.\d+\.\d+)/'],
        'files'     => ['assets/vendor/qrcode/qrcode-generator.min.js'],
        'notes'     => ['Used by mesh-console.php. This is Kazuhiko Arase\'s qrcode-generator, '
                      . 'NOT soldair/node-qrcode.'],
    ],
    [
        'name'      => 'qrcode-generator (unminified copy)',
        'publisher' => 'Kazuhiko Arase',
        'license'   => 'MIT',
        'purlBase'  => 'pkg:npm/qrcode-generator',
        'sourceUrl' => 'http://www.d-project.com/',
        'detect'    => null,
        'files'     => ['assets/js/qrcode.min.js'],
        'unknown'   => ['Component Version'],
        'unknownReason' => 'This second, unminified copy of Kazuhiko Arase\'s QR generator carries '
                         . 'a copyright header but no version string anywhere in the file, and it '
                         . 'is not tracked by any package manifest. The SBOM author cannot '
                         . 'determine which upstream release it corresponds to. It is retained as '
                         . 'a distinct component because it is a separate shipped artifact loaded '
                         . 'by profile.php, at a possibly different version from the copy above.',
    ],
    [
        'name'      => 'marked',
        'publisher' => 'Christopher Jeffrey',
        'license'   => 'MIT',
        'purlBase'  => 'pkg:npm/marked',
        'sourceUrl' => 'https://github.com/markedjs/marked',
        /* Header banner: "marked v12.0.2 - a markdown parser". Read from the
         * shipped file, never hardcoded — if this is upgraded the SBOM follows,
         * and if the banner ever disappears the component degrades to an
         * explicit unknown rather than reporting a stale version. */
        'detect'    => ['assets/vendor/marked/marked.min.js', '/marked\s+v(\d+\.\d+\.\d+)/'],
        'files'     => ['assets/vendor/marked/marked.min.js'],
        'notes'     => ['Markdown renderer for the SOP viewer and the documentation browser. '
                      . 'Vendored at a pinned version; it was previously loaded from a CDN URL '
                      . 'that pinned no version, so the browser executed whatever the CDN served '
                      . 'as "latest".'],
    ],
    [
        'name'      => 'Leaflet.Graticule',
        'publisher' => 'Bjorn Sandvik',
        'license'   => null,
        'purlBase'  => 'pkg:npm/leaflet-graticule',
        'sourceUrl' => 'https://github.com/turban/Leaflet.Graticule',
        'detect'    => null,
        'files'     => ['assets/vendor/leaflet/plugins/L.Graticule.js'],
        'unknown'   => ['Component Version', 'Component License'],
        'unknownReason' => 'The shipped file has a three-line header with no version and no '
                         . 'license declaration, and no manifest records it. The upstream project '
                         . 'is MIT-licensed, but that is not evidenced by the artifact actually '
                         . 'shipped, so the SBOM author does not assert it.',
    ],
    [
        'name'      => 'leaflet-openweathermap',
        'publisher' => 'Stefan Bühler',
        'license'   => 'CC0-1.0',
        'purlBase'  => 'pkg:npm/leaflet-openweathermap',
        'sourceUrl' => 'https://github.com/buche/leaflet-openweathermap',
        'detect'    => null,
        'files'     => [
            'assets/vendor/leaflet/plugins/leaflet-openweathermap.js',
            'assets/vendor/leaflet/plugins/leaflet-openweathermap.css',
        ],
        'unknown'   => ['Component Version'],
        'unknownReason' => 'The shipped files declare a license (CC0) and a project page but carry '
                         . 'no version string, and no manifest records the version.',
    ],
];

foreach ($vendored as $v) {
    $version = null;
    if (!empty($v['detect'])) {
        $abs     = $root . '/' . $v['detect'][0];
        $tail    = $v['detect'][2] ?? false;
        $version = detectVersion($abs, $v['detect'][1], 8192, $tail);
    }

    /* Hash every shipped file belonging to this component. */
    $fileHashes = [];
    foreach ($v['files'] as $rel) {
        $h = fileSha256($root . '/' . $rel);
        if ($h !== null) $fileHashes[$rel] = $h;
    }
    if (!$fileHashes) continue; // component not present in this tree

    $unknown = $v['unknown'] ?? [];
    $reason  = $v['unknownReason'] ?? null;
    if ($version === null && !in_array('Component Version', $unknown, true)) {
        /* A detection regex was configured but did not match — the library was
         * probably upgraded to a build without that banner. Degrade honestly. */
        $unknown[] = 'Component Version';
        $reason    = $reason ?? 'A version banner was expected in ' . $v['detect'][0]
                   . ' but no version string matched. Refusing to report a stale or assumed version.';
    }
    if ($v['license'] === null && !in_array('Component License', $unknown, true)) {
        $unknown[] = 'Component License';
    }

    $primary     = array_key_first($fileHashes);
    $identifiers = [];
    if ($version !== null) {
        $identifiers[] = $v['purlBase'] . '@' . $version;
        if (!empty($v['cpeBase'])) $identifiers[] = $v['cpeBase'] . ':' . $version . ':*:*:*:*:*:*:*';
    } else {
        $identifiers[] = $v['purlBase'];
    }
    foreach ($fileHashes as $rel => $h) $identifiers[] = 'sha256:' . $h . ' (' . $rel . ')';

    $components[] = component([
        'key'           => 'vendored:' . $v['name'],
        'name'          => $v['name'],
        'version'       => $version,
        'publisher'     => $v['publisher'],
        'license'       => $v['license'],
        'purl'          => $version !== null ? $v['purlBase'] . '@' . $version : $v['purlBase'],
        'cpe'           => ($version !== null && !empty($v['cpeBase']))
                            ? $v['cpeBase'] . ':' . $version . ':*:*:*:*:*:*:*' : null,
        'sourceUrl'     => $v['sourceUrl'],
        'hash'          => $fileHashes[$primary],
        'files'         => $fileHashes,
        'identifiers'   => $identifiers,
        'group'         => 'vendored-frontend',
        'unknown'       => $unknown,
        'unknownReason' => $reason,
        'notes'         => $v['notes'] ?? [],
    ]);
}

/* ================================================================== *
 * 3. Remotely loaded frontend code (runtime supply-chain surface)
 * ================================================================== *
 * Scanned rather than hardcoded: any cdn.jsdelivr.net script/link URL in a PHP
 * page is a third-party component the browser executes, even though we do not
 * ship the bytes.
 */
$cdnFound = [];
foreach (scanFiles($root, '/\.(php|html)$/i') as $path) {
    if (preg_match('#[\\\\/](vendor|node_modules|\.git)[\\\\/]#', $path)) continue;
    $src = (string) @file_get_contents($path);
    if (stripos($src, 'cdn.jsdelivr.net') === false) continue;
    if (preg_match_all('#https://cdn\.jsdelivr\.net/npm/([^"\'\s>]+)#', $src, $m)) {
        foreach ($m[1] as $spec) {
            $pkg = $spec;
            $ver = null;
            if (preg_match('#^((?:@[^/]+/)?[^/@]+)@([^/]+)#', $spec, $mm)) {
                $pkg = $mm[1];
                $ver = $mm[2];
            } elseif (preg_match('#^((?:@[^/]+/)?[^/@]+)#', $spec, $mm)) {
                $pkg = $mm[1];
            }
            $k = $pkg . '@' . ($ver ?? '');
            if (!isset($cdnFound[$k])) {
                $cdnFound[$k] = ['pkg' => $pkg, 'ver' => $ver, 'where' => []];
            }
            $cdnFound[$k]['where'][relPath($path, $root)] = true;
        }
    }
}
ksort($cdnFound);
foreach ($cdnFound as $k => $c) {
    /* The "loaded by" list is part of the description, so it must not depend on
     * the order the filesystem handed us the files. */
    ksort($cdnFound[$k]['where']);
}
foreach ($cdnFound as $c) {
    $pkg = $c['pkg'];
    $ver = $c['ver'];
    $components[] = component([
        'key'           => 'cdn:' . $pkg . '@' . ($ver ?? 'floating'),
        'name'          => $pkg,
        'version'       => $ver,
        'purl'          => $ver !== null ? 'pkg:npm/' . $pkg . '@' . $ver : 'pkg:npm/' . $pkg,
        'sourceUrl'     => 'https://www.npmjs.com/package/' . $pkg,
        'identifiers'   => [$ver !== null ? 'pkg:npm/' . $pkg . '@' . $ver : 'pkg:npm/' . $pkg],
        'group'         => 'remote-frontend',
        'description'   => 'Loaded at runtime from the jsDelivr CDN by: '
                         . implode(', ', array_keys($c['where'])),
        'unknown'       => array_merge(
            $ver === null ? ['Component Version'] : [],
            ['Component Producer', 'Component Hash Value', 'Component Hash Algorithm',
             'Component License']
        ),
        'unknownReason' => ($ver === null
                ? 'The CDN URL pins no version, so the browser receives whatever the CDN currently '
                . 'serves as "latest"; the SBOM author cannot state which version executes. '
                : '')
            . 'The artifact is fetched by the browser at runtime and is not distributed with this '
            . 'software, so the SBOM author has no artifact to hash and no Subresource Integrity '
            . 'value is pinned. The producer and licence are those the npm registry records for '
            . 'this package name; the SBOM author reads no manifest here and will not restate them '
            . 'as though they had been verified against the bytes the browser actually runs.',
    ]);
}

/* ================================================================== *
 * 4. Python service dependencies
 * ================================================================== *
 * Declared by requirements files where they exist, and by import statements
 * elsewhere. These are installed at deploy time, so versions are constraints,
 * not resolved versions — recorded honestly as such.
 */
$pyImportMap = [
    'meshtastic'      => ['meshtastic', 'GPL-3.0-only'],
    'paho'            => ['paho-mqtt', 'EPL-2.0'],
    'requests'        => ['requests', 'Apache-2.0'],
    'pubsub'          => ['pypubsub', 'BSD-2-Clause'],
    'meshcore'        => ['meshcore', null],
    'aprslib'         => ['aprslib', 'Apache-2.0'],
    /* Oracle's LICENSE.txt: "released under version 2 of the GNU General
     * Public License (GPLv2) … this Connector is also subject to the Universal
     * FOSS Exception, version 1.0". GPLv2 only — the file carries an "Election
     * of GPLv2" section and no "or later". Both identifiers verified present
     * in the official SPDX enum; the previously emitted
     * "GPL-2.0-with-FOSS-exception" is not an SPDX identifier at all and made
     * the whole document fail the CycloneDX 1.6 schema.
     *
     * Not captured by the expression, and recorded here rather than implied:
     * the licence ALSO grants a separate linking permission for separately
     * licensed software such as OpenSSL. SPDX has no identifier for it. */
    'mysql'           => ['mysql-connector-python',
                          ['expression' => 'GPL-2.0-only WITH Universal-FOSS-exception-1.0']],
    'vosk'            => ['vosk', 'Apache-2.0'],
    'faster_whisper'  => ['faster-whisper', 'MIT'],
    'numpy'           => ['numpy', 'BSD-3-Clause'],
    'serial'          => ['pyserial', 'BSD-3-Clause'],
];

/* Licences for packages this project INSTALLS but never imports, so they reach
 * the SBOM through the install-script scan below rather than through
 * $pyImportMap. Stated only where the upstream licence is unambiguous; the rest
 * are left null and rendered as an explicit unknown. */
$pyScriptLicense = [
    'onnxruntime'  => 'MIT',
    'piper-tts'    => 'MIT',
    'pip'          => 'MIT',
    /* Left unknown deliberately rather than filled in from recollection. */
    'meshcore-cli' => null,
    'esptool'      => null,
];

/* ------------------------------------------------------------------ *
 * 4a. Packages installed by this project's OWN scripts
 * ------------------------------------------------------------------ *
 * The two scans above see a package only if a requirements file pins it or a
 * .py file imports it. Neither is true of the largest install path in this
 * repository: `services/dvswitch/install-bridge.sh` pip-installs its
 * dependencies directly, and `services/aprs/install.sh` does the same.
 *
 * This is the gap that mattered. `onnxruntime` and `piper-tts` were installed
 * by install-bridge.sh line 299 — on the same pip line as `numpy`, which WAS
 * listed because a .py file imports it — and were absent from this SBOM while
 * the project publicly claimed the document covered the whole dependency chain
 * with no minimum depth. Anyone who diffed the installer against SBOM.txt found
 * it in about two minutes. The fix is not to add two names: it is to read the
 * installers, so the next package added to one cannot repeat it.
 */
$pyInstallScripts = [];   // package => [script paths that install it]
foreach (installerFiles($root) as $f) {
    $rel = relPath($f, $root);
    foreach (joinContinuations((string) @file_get_contents($f)) as $line) {
        foreach (installArgs($line, '/(?:^|[\s"\'\/])pip[23]?["\']?\s+install\s+/') as $pkg) {
            $pyInstallScripts[strtolower($pkg)][] = $rel;
        }
    }
}

/* Packages the scanner structurally CANNOT see, declared explicitly with the
 * evidence that puts them here. Both are real runtime dependencies with no
 * manifest anywhere in the tree:
 *
 *  - meshcore-cli is appended to a pip line by JavaScript string concatenation
 *    when the operator picks the MeshCore protocol, so it is not on the same
 *    source line as the `pip install` verb and no line-based scan can reach it.
 *  - esptool is never imported and never pip-installed by us; it is executed as
 *    `python -m esptool` against whatever environment the bridge runs in. It is
 *    a hard dependency of node provisioning that nothing in this repository
 *    declares.
 *
 * Declaring them here is the honest alternative to a cleverer parser that would
 * still miss the next case. The test at tests/test_sbom_installer_coverage.php
 * scans independently and fails if anything reaches an installer without
 * reaching this document by one route or the other. */
$pyDeclared = [
    'meshcore-cli' => 'assets/js/mesh-console.js',
    'esptool'      => 'services/meshcore/configure_node.py',
];

$pyConstraints = [];   // package => declared constraint from a requirements file
$pyUsedIn      = [];   // package => [relative paths]

foreach (glob($root . '/services/*/requirements.txt') ?: [] as $req) {
    foreach (file($req, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (preg_match('/^([A-Za-z0-9_.\-]+)\s*(.*)$/', $line, $m)) {
            $pyConstraints[strtolower($m[1])] = trim($m[2]) !== '' ? trim($m[2]) : null;
            $pyUsedIn[strtolower($m[1])][]    = relPath($req, $root);
        }
    }
}

foreach (scanFiles($root . '/services', '/\.py$/i') as $pyPath) {
    $src = (string) @file_get_contents($pyPath);
    if (preg_match_all('/^\s*(?:import|from)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $m)) {
        foreach (array_unique($m[1]) as $mod) {
            if (!isset($pyImportMap[$mod])) continue;
            $pkg = strtolower($pyImportMap[$mod][0]);
            $pyUsedIn[$pkg][] = relPath($pyPath, $root);
        }
    }
}
/* Fold in the install-script findings and the explicit declarations. */
foreach ($pyInstallScripts as $pkg => $paths) {
    foreach ($paths as $p) $pyUsedIn[$pkg][] = $p;
}
foreach ($pyDeclared as $pkg => $evidence) {
    $pyUsedIn[strtolower($pkg)][] = $evidence;
}

/* Deterministic package order and, within each package, deterministic file
 * order — the "Used by:" list is truncated to six entries, so an unstable
 * order would change which files are even mentioned. */
ksort($pyUsedIn);

$pyByPkg = [];
foreach ($pyImportMap as $meta) $pyByPkg[strtolower($meta[0])] = $meta[1];
foreach ($pyScriptLicense as $pkg => $lic) {
    /* An import-map licence wins: it was there first and is equally evidenced. */
    if (!array_key_exists(strtolower($pkg), $pyByPkg)) {
        $pyByPkg[strtolower($pkg)] = $lic;
    }
}

foreach ($pyUsedIn as $pkg => $paths) {
    $paths      = array_values(array_unique($paths));
    sort($paths);
    $constraint = $pyConstraints[$pkg] ?? null;
    $components[] = component([
        'key'           => 'pypi:' . $pkg,
        'name'          => $pkg,
        'version'       => null,
        'license'       => $pyByPkg[$pkg] ?? null,
        'purl'          => 'pkg:pypi/' . $pkg,
        'sourceUrl'     => 'https://pypi.org/project/' . $pkg . '/',
        'identifiers'   => ['pkg:pypi/' . $pkg],
        'group'         => 'python-services',
        'description'   => 'Used by: ' . implode(', ', array_slice($paths, 0, 6))
                         . (count($paths) > 6 ? ' (+' . (count($paths) - 6) . ' more)' : ''),
        'unknown'       => array_merge(
            ['Component Version', 'Component Producer', 'Component Hash Value',
             'Component Hash Algorithm'],
            ($pyByPkg[$pkg] ?? null) === null ? ['Component License'] : []
        ),
        'unknownReason' => 'Optional Python service dependency, installed with pip on the operator\'s '
                         . 'host at deploy time and not distributed with this software. '
                         . 'The producer is whoever controls this name on PyPI at install time, '
                         . 'which the SBOM author cannot pin from this tree. '
                         . ($constraint !== null
                             ? 'The declared constraint is "' . $constraint . '", which bounds but '
                             . 'does not determine the installed version. '
                             : (isset($pyInstallScripts[$pkg])
                                 ? 'No requirements file pins this package; it is installed by '
                                 . implode(', ', array_values(array_unique($pyInstallScripts[$pkg])))
                                 . ' with no version constraint written, so pip resolves whatever '
                                 . 'is current on PyPI at the moment the operator runs the '
                                 . 'installer. Stating a version here would be a guess, and a '
                                 . 'guessed version is a false attestation. '
                                 : 'No requirements file pins this package and no installer in '
                                 . 'this repository names a version for it. '))
                         . 'The resolved version is therefore known only on the operator\'s host; '
                         . 'operators should generate a deployment SBOM (for example with '
                         . '"pip freeze") to capture it.',
        'notes'         => $constraint !== null ? ['Declared constraint: ' . $constraint] : [],
    ]);
}

/* ================================================================== *
 * 5. GPL-2.0 derivative provenance — MMDVMHost ports in services/dvswitch
 * ================================================================== *
 * Detected, not assumed: these files declare their own provenance in their
 * docstrings. Recording this is a licence-compliance obligation as well as an
 * SBOM one.
 */
$dvswitchDir = $root . '/services/dvswitch';
$mmdvmFiles  = [];
if (is_dir($dvswitchDir)) {
    foreach (glob($dvswitchDir . '/*.py') ?: [] as $f) {
        $src = (string) @file_get_contents($f);
        if (stripos($src, 'MMDVMHost') !== false) {
            $mmdvmFiles[relPath($f, $root)] = hash('sha256', $src);
        }
    }
}
if ($mmdvmFiles) {
    $components[] = component([
        'key'           => 'derived:MMDVMHost',
        'name'          => 'MMDVMHost',
        'publisher'     => 'Jonathan Naylor (G4KLX)',
        'license'       => 'GPL-2.0-only',
        'version'       => null,
        'purl'          => 'pkg:github/g4klx/MMDVMHost',
        'sourceUrl'     => 'https://github.com/g4klx/MMDVMHost',
        'identifiers'   => ['pkg:github/g4klx/MMDVMHost'],
        'group'         => 'derived-source',
        'type'          => 'library',
        'files'         => $mmdvmFiles,
        'description'   => 'DMR protocol logic in services/dvswitch/ is a derivative work of '
                         . 'MMDVMHost. ' . count($mmdvmFiles) . ' file(s) are ports of, or direct '
                         . 'extractions from, MMDVMHost sources (RS129, BPTC19696, Hamming, '
                         . 'AMBEFEC, Golay, DMRSlotType, DMREmbeddedData, DMRNetwork). '
                         . 'TicketsCAD is GPL-2.0, so the copyleft terms are satisfied; this entry '
                         . 'records the provenance so downstream recipients can see it.',
        'unknown'       => ['Component Version', 'Component Hash Value', 'Component Hash Algorithm'],
        'unknownReason' => 'The ported files cite MMDVMHost source filenames but no upstream '
                         . 'release tag or commit, so the SBOM author cannot state which MMDVMHost '
                         . 'revision the port was taken from. There is also no single artifact to '
                         . 'hash: this component is source ported into several files rather than a '
                         . 'distributed package. A SHA-256 for each individual ported file is given '
                         . 'in the "ticketscad:file" properties on this component, which is the '
                         . 'integrity evidence a recipient can actually check against this tree.',
        'notes'         => ['Derived source, not a linked dependency — the code is compiled into '
                          . 'the TicketsCAD tree rather than installed as a package.'],
    ]);
}

/* ================================================================== *
 * 6. Container base images (detected from Dockerfiles)
 * ================================================================== */
$dockerImages = [];

/* Dockerfiles: both "FROM <image>" and multi-stage "COPY --from=<image>". */
foreach ([$root . '/Dockerfile', $root . '/services/dvswitch/docker/Dockerfile'] as $df) {
    if (!is_file($df)) continue;
    $src = (string) file_get_contents($df);
    $found = [];
    if (preg_match_all('/^\s*FROM\s+(?:--platform=\S+\s+)?(\S+)/mi', $src, $m)) {
        $found = array_merge($found, $m[1]);
    }
    if (preg_match_all('/^\s*COPY\s+--from=(\S+)/mi', $src, $m)) {
        $found = array_merge($found, $m[1]);
    }
    foreach ($found as $img) {
        if (stripos($img, 'scratch') === 0) continue;
        /* Skip references to a named build stage rather than a real image. */
        if (!str_contains($img, ':') && !str_contains($img, '/')) continue;
        $dockerImages[$img][] = relPath($df, $root);
    }
}

/* docker-compose service images (e.g. the bundled MariaDB). */
foreach (glob($root . '/*compose*.y*ml') ?: [] as $dc) {
    $src = (string) file_get_contents($dc);
    if (preg_match_all('/^\s*image:\s*["\']?([^"\'\s#]+)/mi', $src, $m)) {
        foreach ($m[1] as $img) {
            if (str_starts_with($img, '$')) continue;
            /* Skip the image this project builds from its own Dockerfile —
             * that is the target component, not a third-party dependency. */
            if (str_contains($img, 'ticketscad')) continue;
            $dockerImages[$img][] = relPath($dc, $root);
        }
    }
}
ksort($dockerImages);
foreach ($dockerImages as $img => $where) {
    sort($where);
    [$imgName, $imgTag] = array_pad(explode(':', $img, 2), 2, null);
    $components[] = component([
        'key'           => 'oci:' . $img,
        'name'          => $imgName,
        'version'       => $imgTag,
        'type'          => 'container',
        'purl'          => 'pkg:docker/' . $imgName . ($imgTag !== null ? '@' . $imgTag : ''),
        'sourceUrl'     => 'https://hub.docker.com/_/' . explode('/', $imgName)[0],
        'identifiers'   => ['pkg:docker/' . $imgName . ($imgTag !== null ? '@' . $imgTag : '')],
        'group'         => 'container-base',
        'description'   => 'Base image referenced by ' . implode(', ', array_unique($where))
                         . '. Optional: only used by operators who deploy with Docker.',
        'unknown'       => ['Component Producer', 'Component License',
                            'Component Hash Value', 'Component Hash Algorithm'],
        'unknownReason' => 'The image is referenced by a mutable tag rather than a digest, so the '
                         . 'exact image contents are resolved at "docker build" time on the '
                         . 'operator\'s host and cannot be hashed by the SBOM author. For the same '
                         . 'reason the producer and licence cannot be stated: a base image is a '
                         . 'whole distribution of many packages under many licences, and which '
                         . 'ones it contains depends on when the tag is pulled. Operators who need '
                         . 'this should generate an image SBOM from the built image (for example '
                         . 'with "syft" or "trivy").',
    ]);
}

/* ================================================================== *
 * 6a. Operating-system packages installed by our Dockerfiles and installers
 * ================================================================== *
 * The container images this project builds, and the installers it ships, run
 * `apt-get install` with package lists written in this repository. Those
 * packages are in the delivered image and on the operator's host because we put
 * them there, so they are components of this software by the same test applied
 * everywhere else in this file: we ship it, or we install it.
 *
 * Scanned from the files rather than listed here, for the same reason as the
 * pip scan above — a list maintained by hand goes stale the first time somebody
 * adds a package and does not think about the SBOM.
 *
 * NOT included: packages that only a DOCUMENT tells the operator to install
 * (docs/INSTALL.md's apache2/php/mariadb-server line, for instance). Those are
 * the platform the operator supplies, which this SBOM has always excluded and
 * says so in its coverage statement. The line drawn here is "code in this
 * repository installs it", which is checkable by anyone.
 */
$aptPkgs = [];   // package => [files that install it]
foreach (installerFiles($root) as $f) {
    $rel = relPath($f, $root);
    foreach (joinContinuations((string) @file_get_contents($f)) as $line) {
        foreach (installArgs($line, '/(?:^|[\s"\'])apt(?:-get)?\s+install\s+/') as $pkg) {
            $aptPkgs[strtolower($pkg)][] = $rel;
        }
    }
}
ksort($aptPkgs);
foreach ($aptPkgs as $pkg => $where) {
    $where = array_values(array_unique($where));
    sort($where);
    $components[] = component([
        'key'           => 'deb:' . $pkg,
        'name'          => $pkg,
        'version'       => null,
        'purl'          => 'pkg:deb/' . $pkg,
        'identifiers'   => ['pkg:deb/' . $pkg],
        'group'         => 'os-packages',
        'description'   => 'Operating-system package installed by ' . implode(', ', $where) . '.',
        'unknown'       => ['Component Version', 'Component Producer', 'Component License',
                            'Component Hash Value', 'Component Hash Algorithm'],
        'unknownReason' => 'Installed with apt at image-build or install time and not distributed '
                         . 'with this software. No version is written in the installing file, so '
                         . 'apt resolves whatever the host\'s configured archive currently serves; '
                         . 'the SBOM author cannot state a version, a producer, a licence or a '
                         . 'hash from this tree, because all four depend on which distribution and '
                         . 'which archive snapshot the operator builds against. Operators who need '
                         . 'these should generate an SBOM from the built image (for example with '
                         . '"syft" or "trivy"), which reads the package database and resolves all '
                         . 'of them exactly.',
    ]);
}

/* ================================================================== *
 * 6b. Artifacts downloaded at install time or first use
 * ================================================================== *
 * Binaries, models and reference datasets that are not in this repository and
 * are not installed by a package manager — they are fetched from a URL by our
 * own scripts and code. A package manager at least records what it installed;
 * nothing records these, which makes them the least visible components in the
 * system and the ones most worth writing down.
 *
 * Declared rather than scanned. The URLs are assembled from shell variables
 * across several lines and reached by three alternative code paths, so a scan
 * would produce either nothing or something invented. Every entry below cites
 * the file and the source it is read from, so a reader can check the claim
 * against the tree. NONE of them is version-pinned, and each says so with the
 * specific reason — a mutable branch, a "latest" redirect, a rolling dump.
 */
$downloaded = [
    [
        'name'      => 'Piper voice model (en_US-lessac-medium)',
        'purl'      => 'pkg:huggingface/rhasspy/piper-voices',
        'type'      => 'machine-learning-model',
        'publisher' => 'Rhasspy',
        'license'   => null,
        'sourceUrl' => 'https://huggingface.co/rhasspy/piper-voices',
        'evidence'  => 'services/dvswitch/install-bridge.sh (voice id at line 107, fetched from '
                     . 'huggingface.co/rhasspy/piper-voices at lines 325-329)',
        'reason'    => 'Fetched from the "main" branch of a Hugging Face repository, which is a '
                     . 'moving reference rather than a release: the bytes served under that path '
                     . 'can change without any identifier in this tree changing. There is no '
                     . 'version to state and no published hash to record. The voice is selectable '
                     . 'with PIPER_VOICE, so an operator may hold a different model entirely.',
    ],
    [
        'name'      => 'Vosk speech-recognition model (vosk-model-small-en-us-0.15)',
        'purl'      => 'pkg:generic/vosk-model-small-en-us@0.15',
        'type'      => 'machine-learning-model',
        'publisher' => 'Alpha Cephei',
        'license'   => null,
        'sourceUrl' => 'https://alphacephei.com/vosk/models',
        'evidence'  => 'services/dvswitch/example.env (DMR_VOSK_MODEL), consumed by '
                     . 'services/dvswitch/bridge.py; download documented in '
                     . 'docs/DVSWITCH-ADMIN-GUIDE.md',
        'reason'    => 'Optional: speech-to-text is off unless the operator sets DMR_VOSK_MODEL. '
                     . 'The model version 0.15 appears in the archive filename and is recorded '
                     . 'above on that evidence, but the archive carries no published checksum for '
                     . 'the SBOM author to record, and the operator chooses which model to '
                     . 'install. The licence of the model weights is not stated in this tree and '
                     . 'is not asserted here.',
    ],
    [
        'name'      => 'faster-whisper model weights',
        'purl'      => 'pkg:huggingface/Systran/faster-whisper-base',
        'type'      => 'machine-learning-model',
        'publisher' => null,
        'license'   => null,
        'sourceUrl' => 'https://huggingface.co/Systran',
        'evidence'  => 'services/dvswitch/bridge.py and services/dvswitch/echo_bot.py — the '
                     . 'library downloads the weights on first inference',
        'reason'    => 'Not fetched by this project at all: the faster-whisper library downloads '
                     . 'the weights into the operator\'s Hugging Face cache the first time '
                     . 'transcription runs. Which repository and revision that resolves to is '
                     . 'decided by the library at runtime, so no version, producer, licence or '
                     . 'hash can be stated from this tree. The model size is configurable, so the '
                     . 'identifier above records the default the code requests, not a guarantee '
                     . 'of what an operator has.',
    ],
    [
        'name'      => 'md380-emu (AMBE vocoder emulator)',
        'purl'      => 'pkg:generic/md380-emu',
        'type'      => 'application',
        'publisher' => null,
        'license'   => null,
        'sourceUrl' => 'https://github.com/travisgoodspeed/md380tools',
        'evidence'  => 'services/dvswitch/install-bridge.sh lines 161-199 (three alternative '
                     . 'acquisition paths) and services/dvswitch/docker/Dockerfile lines 36-53 '
                     . '(.deb from the DVSwitch apt repository, unpacked with dpkg-deb -x)',
        'reason'    => 'Obtained by one of four routes depending on how the operator installs — '
                     . 'copied from a peer host, built from a "--depth 1" clone of the md380tools '
                     . 'default branch, downloaded from an operator-supplied MD380_URL, or '
                     . 'extracted from a .deb matched by a glob in the DVSwitch repository. None '
                     . 'of the four pins a version, so no version, hash or producer can be stated '
                     . 'here; the licence of the resulting binary depends on which route was '
                     . 'taken. This is the least reproducible component in the system and is '
                     . 'recorded as such rather than omitted.',
    ],
    [
        'name'      => 'FCC ULS licence database (amateur and GMRS)',
        'purl'      => 'pkg:generic/fcc-uls',
        'type'      => 'data',
        'publisher' => 'United States Federal Communications Commission',
        'license'   => null,
        'sourceUrl' => 'https://data.fcc.gov/download/pub/uls/complete/',
        'evidence'  => 'tools/update-lookup-data.php and tools/refresh-lookups.php '
                     . '(l_amat.zip, l_gmrs.zip)',
        'reason'    => 'Optional reference data for callsign lookups, downloaded by an operator-run '
                     . 'tool. The FCC publishes it as a rolling dump at a fixed URL with no version '
                     . 'identifier and no published checksum, so the only thing that distinguishes '
                     . 'one copy from another is when it was fetched. It is US Government work and '
                     . 'not subject to copyright, which is not the same as carrying an SPDX '
                     . 'licence identifier, so none is asserted.',
    ],
    [
        'name'      => 'GeoNames postal-code dataset (US)',
        'purl'      => 'pkg:generic/geonames-postal-us',
        'type'      => 'data',
        'publisher' => 'GeoNames',
        'license'   => null,
        'sourceUrl' => 'https://download.geonames.org/export/zip/',
        'evidence'  => 'tools/update-lookup-data.php and tools/refresh-lookups.php (US.zip)',
        'reason'    => 'Optional reference data, downloaded by an operator-run tool from a fixed '
                     . 'URL that always serves the current export. No version identifier and no '
                     . 'published checksum accompany it. GeoNames publishes under a Creative '
                     . 'Commons licence, but the exact version of that licence is not recorded in '
                     . 'this tree and is not asserted from memory.',
    ],
];
foreach ($downloaded as $d) {
    $unknown = ['Component Hash Value', 'Component Hash Algorithm'];
    if ($d['publisher'] === null) $unknown[] = 'Component Producer';
    if ($d['license']   === null) $unknown[] = 'Component License';
    /* Only the Vosk entry carries a version, and only because the archive
     * filename states one. */
    $version = null;
    if (preg_match('/@([0-9][^\s]*)$/', (string) $d['purl'], $vm)) $version = $vm[1];
    if ($version === null) $unknown[] = 'Component Version';

    $components[] = component([
        'key'           => 'artifact:' . $d['name'],
        'name'          => $d['name'],
        'version'       => $version,
        'type'          => $d['type'],
        'publisher'     => $d['publisher'],
        'license'       => $d['license'],
        'purl'          => $d['purl'],
        'sourceUrl'     => $d['sourceUrl'],
        'identifiers'   => [$d['purl']],
        'group'         => 'downloaded-artifacts',
        'description'   => 'Downloaded at install time or first use, not distributed with this '
                         . 'software. Evidence: ' . $d['evidence'] . '.',
        'unknown'       => $unknown,
        'unknownReason' => $d['reason'],
    ]);
}

/* ================================================================== *
 * 6c. Build- and verification-time tooling fetched from a network
 * ================================================================== *
 * Not shipped and not run by the application — but fetched from a third-party
 * registry by this repository's own automation, which makes them part of the
 * chain that produces and checks what we publish. The npm packages are the
 * sharper case: they are fetched by THIS GENERATOR, on the path that validates
 * the SBOM. A bill of materials whose own validator is an undeclared dependency
 * is exactly the sort of omission this document exists to prevent.
 */
$buildTools = [
    ['ajv-cli',                  'pkg:npm/ajv-cli@5',                'MIT',
     'tools/generate-sbom.php (npx --yes -p ajv-cli@5, --validate path)',        '5'],
    ['ajv-formats',              'pkg:npm/ajv-formats@3',            'MIT',
     'tools/generate-sbom.php (npx --yes -p ajv-formats@3, --validate path)',    '3'],
    ['actions/checkout',         'pkg:github/actions/checkout@v7',   'MIT',
     '.github/workflows/qa.yml',                                                 'v7'],
    ['shivammathur/setup-php',   'pkg:github/shivammathur/setup-php@v2', 'MIT',
     '.github/workflows/qa.yml',                                                 'v2'],
];
foreach ($buildTools as [$name, $purl, $license, $evidence, $constraint]) {
    $components[] = component([
        'key'           => 'buildtool:' . $name,
        'name'          => $name,
        'version'       => null,
        'license'       => $license,
        'type'          => 'application',
        'purl'          => $purl,
        'sourceUrl'     => str_starts_with($purl, 'pkg:npm/')
                         ? 'https://www.npmjs.com/package/' . $name
                         : 'https://github.com/' . $name,
        'identifiers'   => [$purl],
        'group'         => 'build-tooling',
        'description'   => 'Fetched from a third-party registry at build or verification time by '
                         . $evidence . '. Not shipped with this software and not loaded by the '
                         . 'application at runtime.',
        'unknown'       => ['Component Version', 'Component Producer',
                            'Component Hash Value', 'Component Hash Algorithm'],
        'unknownReason' => 'Referenced by the major-version constraint "' . $constraint . '", which '
                         . 'bounds but does not determine what is fetched: the registry resolves it '
                         . 'to whatever the newest matching release is at the moment the job runs. '
                         . 'The SBOM author therefore cannot state the resolved version, its '
                         . 'publisher at that moment, or a hash of the fetched bytes. This is a '
                         . 'real supply-chain exposure in the build path and is recorded here '
                         . 'rather than left out because it is only build tooling.',
        'notes'         => ['Declared constraint: ' . $constraint],
    ]);
}

/* ================================================================== *
 * 6d. Specification data vendored into this repository
 * ================================================================== *
 * The official CycloneDX schemas under tools/schema/cyclonedx/ are third-party
 * files that we distribute. They are not application code and nothing loads
 * them at runtime — but "we ship it" is the test this SBOM applies, and the
 * release whose whole subject is SBOM honesty is the worst possible place to
 * quietly omit a third-party artifact we added ourselves.
 */
$schemaFiles = [];
foreach (['bom-1.6.schema.json', 'spdx.schema.json', 'jsf-0.82.schema.json'] as $s) {
    $abs = schemaDir() . '/' . $s;
    if (is_file($abs)) $schemaFiles['tools/schema/cyclonedx/' . $s] = hash_file('sha256', $abs);
}
if ($schemaFiles !== []) {
    ksort($schemaFiles);
    $schemaIdent = [];
    foreach ($schemaFiles as $rel => $h) $schemaIdent[] = 'sha256:' . $h . ' (' . $rel . ')';
    $components[] = component([
        'key'         => 'spec:cyclonedx-schema',
        'name'        => 'CycloneDX specification schemas',
        'version'     => '1.6',
        'publisher'   => 'OWASP Foundation',
        'license'     => 'Apache-2.0',
        'type'        => 'data',
        'sourceUrl'   => 'https://github.com/CycloneDX/specification',
        'hash'        => $schemaFiles['tools/schema/cyclonedx/bom-1.6.schema.json'] ?? null,
        'files'       => $schemaFiles,
        'identifiers' => $schemaIdent,
        'group'       => 'vendored-spec',
        'description' => 'The official, unmodified CycloneDX JSON schemas, vendored so that '
                       . 'validation of this SBOM is deterministic, offline and auditable. Used '
                       . 'only by tools/generate-sbom.php and the release gate; not loaded by the '
                       . 'application at runtime. Provenance and upstream commit are recorded in '
                       . 'tools/schema/cyclonedx/README.md.',
        'unknown'       => ['Component Dependency Relationship'],
        'unknownReason' => 'Specification data with no onward dependencies of its own to declare. '
                         . 'Its relationship TO this application is recorded — the application '
                         . 'ships it.',
    ]);
}

/* ================================================================== *
 * 7. Assemble the CycloneDX 1.6 document
 * ================================================================== */

/* Total order: group, then name, then purl as a tie-break. Without the last
 * key, two same-named components in the same group (the two copies of the QR
 * library) would compare equal and fall back on input order, which is not
 * guaranteed to be the same everywhere. */
usort($components, function (array $a, array $b): int {
    return [$a['group'], strtolower($a['name']), (string) ($a['purl'] ?? ''), $a['key'] ?? '']
       <=> [$b['group'], strtolower($b['name']), (string) ($b['purl'] ?? ''), $b['key'] ?? ''];
});

/** Deterministic bom-ref for each component. */
foreach ($components as $i => $c) {
    $components[$i]['bomRef'] = $c['purl'] !== null
        ? $c['purl'] . '#' . $c['group']
        : $c['group'] . '/' . $c['name'];
}

$appBomRef = 'pkg:generic/ticketscad-newui@' . $appVersion;

/* CISA: Component Dependency Relationship, for the components that do not get
 * one.
 *
 * The dependency graph below is derived from composer.lock, which is the only
 * lockfile in this project that records what each package itself requires.
 * Every other class of component (vendored browser libraries, CDN scripts,
 * pip packages, ported source, container base images) has no equivalent
 * manifest in this tree, so their onward dependencies are genuinely unknown to
 * the SBOM author.
 *
 * In CycloneDX a component that has no entry of its own in "dependencies" means
 * exactly that — relationships unknown — whereas an entry with an empty
 * "dependsOn" asserts it has none. Emitting empty nodes would therefore state
 * something we have not checked. So we leave them out of the graph and say so
 * out loud here instead, which is what the guidance's "Explicitly Identifying
 * Unknown Information" practice asks for. */
foreach ($components as $i => $c) {
    if ($c['group'] === 'php-composer') continue;
    $components[$i]['unknown'][] = 'Component Dependency Relationship';
    $extra = 'The onward dependencies of this component are not stated: it is not described by '
           . 'any lockfile in this repository, so the SBOM author has no evidence of what it '
           . 'itself depends on. Its relationship TO this application is recorded — the '
           . 'application depends on it.';
    $components[$i]['unknownReason'] = $c['unknownReason'] === null
        ? $extra
        : rtrim($c['unknownReason']) . ' ' . $extra;
}

$cdxComponents = [];
foreach ($components as $c) {
    $entry = [
        'type'    => $c['type'],
        'bom-ref' => $c['bomRef'],
        'name'    => $c['name'],
    ];
    if ($c['publisher'] !== null) $entry['publisher'] = $c['publisher'];
    if ($c['version']   !== null) $entry['version']   = $c['version'];
    if ($c['description'] !== null) $entry['description'] = $c['description'];
    $entry['scope'] = 'required';

    if ($c['hash'] !== null) {
        $entry['hashes'] = [['alg' => 'SHA-256', 'content' => $c['hash']]];
    }
    if ($c['license'] !== null) {
        $entry['licenses'] = licenseChoice($c['license'], (string) $c['name']);
    }
    if ($c['cpe']  !== null) $entry['cpe']  = $c['cpe'];
    if ($c['purl'] !== null) $entry['purl'] = $c['purl'];
    if ($c['sourceUrl'] !== null) {
        $entry['externalReferences'] = [['type' => 'vcs', 'url' => $c['sourceUrl']]];
    }

    $props = [];
    foreach ($c['files'] as $rel => $h) {
        $props[] = ['name' => 'ticketscad:file', 'value' => $rel . ' sha256:' . $h];
    }
    foreach ($c['identifiers'] as $id) {
        $props[] = ['name' => 'ticketscad:identifier', 'value' => $id];
    }
    foreach ($c['notes'] as $n) {
        $props[] = ['name' => 'ticketscad:note', 'value' => $n];
    }
    if ($c['unknown']) {
        $props[] = ['name' => 'ticketscad:unknown', 'value' => implode(', ', array_unique($c['unknown']))];
        if ($c['unknownReason'] !== null) {
            $props[] = ['name' => 'ticketscad:unknown-reason', 'value' => $c['unknownReason']];
        }
    }
    if ($props) $entry['properties'] = $props;

    $cdxComponents[] = $entry;
}

/* Dependency graph: the application depends on every enumerated component.
 * TicketsCAD is a procedural PHP application without a build-time linker, so
 * the accurate statement is a flat first-level relationship from the
 * application to each component. Transitive Composer relationships are
 * expressed below from composer.lock's own require data. */
$requireMap = [];
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
    $requireMap[(string) $p['name']] = array_keys($p['require'] ?? []);
}
$byComposerName = [];
foreach ($components as $c) {
    if ($c['group'] === 'php-composer') $byComposerName[$c['name']] = $c['bomRef'];
}

$dependencies = [[
    'ref'       => $appBomRef,
    'dependsOn' => array_values(array_map(fn(array $c) => $c['bomRef'], $components)),
]];
foreach ($components as $c) {
    if ($c['group'] !== 'php-composer') continue;
    $deps = [];
    foreach ($requireMap[$c['name']] ?? [] as $req) {
        if (isset($byComposerName[$req])) $deps[] = $byComposerName[$req];
    }
    $dependencies[] = ['ref' => $c['bomRef'], 'dependsOn' => array_values(array_unique($deps))];
}

/* ================================================================== *
 * 7b. Honesty guard — no component data field may be SILENTLY absent
 * ================================================================== *
 * The CISA minimum elements are only meaningful if a missing value means
 * "we told you we don't know", not "we forgot". This guard enforces that for
 * all eight Component Data fields across every component: each is either
 * populated, or named in that component's "ticketscad:unknown" property with a
 * reason given.
 *
 * This exists because the gap it catches actually happened. An earlier
 * revision silently omitted Component Producer from 18 components, and the
 * licence of four container base images, while the project was reporting the
 * field as met. Counting fields by hand does not scale; the tool checks now.
 */
$fieldPresent = [
    'Component Name'      => fn(array $e) => !empty($e['name']),
    'Component Version'   => fn(array $e) => !empty($e['version']),
    'Component Producer'  => fn(array $e) => !empty($e['publisher']),
    'Component License'   => fn(array $e) => !empty($e['licenses']),
    'Component Hash Value'     => fn(array $e) => !empty($e['hashes'][0]['content']),
    'Component Hash Algorithm' => fn(array $e) => !empty($e['hashes'][0]['alg']),
    'Component Identifiers'    => fn(array $e) => !empty($e['purl']) || !empty($e['cpe'])
                                               || !empty($e['bom-ref']),
];
$silent = [];
foreach ($cdxComponents as $e) {
    $declared = [];
    $hasReason = false;
    foreach ($e['properties'] ?? [] as $p) {
        if ($p['name'] === 'ticketscad:unknown') {
            $declared = array_map('trim', explode(',', $p['value']));
        }
        if ($p['name'] === 'ticketscad:unknown-reason' && trim($p['value']) !== '') {
            $hasReason = true;
        }
    }
    foreach ($fieldPresent as $field => $test) {
        if ($test($e)) continue;
        if (!in_array($field, $declared, true)) {
            $silent[] = $e['name'] . ' (' . ($e['bom-ref'] ?? '?') . '): ' . $field;
        } elseif (!$hasReason) {
            $silent[] = $e['name'] . ': ' . $field . ' declared unknown with no reason given';
        }
    }
}
if ($silent) {
    fwrite(STDERR, "[FAIL] " . count($silent) . " component data field(s) are silently absent.\n");
    fwrite(STDERR, "       A field must be populated, or named in 'unknown' with a reason.\n");
    foreach (array_slice($silent, 0, 25) as $s) fwrite(STDERR, "       - {$s}\n");
    if (count($silent) > 25) fwrite(STDERR, "       ... and " . (count($silent) - 25) . " more\n");
    exit(1);
}

/* ---- Content digest: drives idempotency, SBOM Version and serial number ---- */
$contentDigest = hash('sha256', json_encode(
    ['app' => $appVersion, 'components' => $cdxComponents, 'dependencies' => $dependencies],
    JSON_UNESCAPED_SLASHES
));

/* Carry forward timestamp + version when nothing changed, so re-running the
 * generator does not produce a spurious diff. */
$outPath      = $root . '/SBOM.cdx.json';
$prev         = is_file($outPath) ? json_decode((string) file_get_contents($outPath), true) : null;
$prevDigest   = null;
$prevVersion  = 0;
$prevTime     = null;
if (is_array($prev)) {
    foreach ($prev['metadata']['properties'] ?? [] as $p) {
        if (($p['name'] ?? '') === 'ticketscad:content-digest') $prevDigest = $p['value'];
    }
    $prevVersion = (int) ($prev['version'] ?? 0);
    $prevTime    = $prev['metadata']['timestamp'] ?? null;
}

$unchanged  = ($prevDigest === $contentDigest);
$sbomVersion = $unchanged ? max($prevVersion, 1) : $prevVersion + 1;
/* CISA SBOM Timestamp: RFC 9557. A plain RFC 3339 UTC timestamp is a
 * conforming RFC 9557 timestamp, and is what the CycloneDX schema accepts. */
$timestamp  = $unchanged && $prevTime !== null ? $prevTime : gmdate('Y-m-d\TH:i:s\Z');

/* Deterministic RFC 9562 UUID (version 5, SHA-1 over a project namespace).
 * Same content => same serial number; changed content => new serial number. */
function uuidV5(string $namespaceHex, string $name): string
{
    $nhex = str_replace('-', '', $namespaceHex);
    $nbin = pack('H*', $nhex);
    $hash = sha1($nbin . $name);
    return sprintf(
        '%08s-%04s-%04x-%04x-%12s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
        (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
        substr($hash, 20, 12)
    );
}
/* Namespace: RFC 9562 URL namespace. */
$serial = 'urn:uuid:' . uuidV5('6ba7b811-9dad-11d1-80b4-00c04fd430c8',
    'https://github.com/openises/TicketsCAD#sbom:' . $appVersion . ':' . $contentDigest);

/* Fingerprint of the PUBLISHED signing public key: SHA-256 over the DER
 * SubjectPublicKeyInfo, base64-encoded — the same value OpenSSL prints for
 *   openssl pkey -pubin -in <key> -outform DER | openssl dgst -sha256 -binary | openssl base64
 * Recording it lets someone who obtained the fingerprint through a second
 * channel confirm the key file in this repository is the one they expect. */
$pubKeyPath        = $root . '/' . SBOM_PUBKEY_FILE;
$pubKeyFingerprint = 'unpublished';
if (is_file($pubKeyPath)) {
    $pubKeyFingerprint = sbom_pubkey_fingerprint((string) file_get_contents($pubKeyPath))
                      ?? 'unreadable';
}

$bom = [
    '$schema'     => 'http://cyclonedx.org/schema/bom-1.6.schema.json',
    'bomFormat'   => 'CycloneDX',      // CISA: SBOM Data Format Name
    'specVersion' => '1.6',            // CISA: SBOM Data Format Version
    'serialNumber' => $serial,
    'version'     => $sbomVersion,     // CISA: SBOM Version
    'metadata'    => [
        'timestamp'  => $timestamp,    // CISA: SBOM Timestamp
        'lifecycles' => [['phase' => SBOM_LIFECYCLE_PHASE]], // CISA: SBOM Generation Context
        'tools'      => [
            'components' => [[
                'type'    => 'application',
                'name'    => SBOM_TOOL_NAME,      // CISA: SBOM Tool Name
                'version' => SBOM_TOOL_VERSION,   // CISA: SBOM Tool Version
                'publisher' => SBOM_AUTHOR,
            ]],
        ],
        'authors' => [[                            // CISA: SBOM Author
            'name'  => SBOM_AUTHOR,
            'email' => SBOM_AUTHOR_EMAIL,
        ]],
        'component' => [
            'type'      => 'application',
            'bom-ref'   => $appBomRef,
            'name'      => 'TicketsCAD NewUI',
            'version'   => $appVersion,
            'publisher' => SBOM_AUTHOR,
            'description' => 'Computer-aided dispatch for volunteer emergency services, '
                           . 'amateur-radio emergency communications groups, CERT teams and '
                           . 'small public-safety agencies.',
            'licenses'  => [['license' => ['id' => 'GPL-2.0-only']]],
            'purl'      => 'pkg:generic/ticketscad-newui@' . $appVersion,
            'externalReferences' => [
                ['type' => 'vcs', 'url' => 'https://github.com/openises/TicketsCAD'],
            ],
        ],
        'properties' => [
            ['name' => 'ticketscad:content-digest', 'value' => $contentDigest],
            ['name'  => 'ticketscad:standard',
             'value' => 'CISA 2026 Minimum Elements for a Software Bill of Materials (SBOM), '
                      . 'published 2026-07-29'],
            ['name'  => 'ticketscad:standard-url',
             'value' => 'https://www.cisa.gov/resources-tools/resources/'
                      . '2026-minimum-elements-software-bill-materials-sbom'],
            ['name'  => 'ticketscad:generation-context-detail',
             'value' => 'Generated from the source tree before build: exact versions from '
                      . 'composer.lock, and versions of vendored browser libraries detected from '
                      . 'the shipped artifacts themselves at generation time.'],
            ['name'  => 'ticketscad:unknown-convention',
             'value' => 'Components carry a "ticketscad:unknown" property naming the CISA minimum '
                      . 'elements the SBOM author could not determine, and a '
                      . '"ticketscad:unknown-reason" property explaining why. No information in '
                      . 'this SBOM is withheld; withheld fields would be marked '
                      . '"ticketscad:withheld".'],
            ['name'  => 'ticketscad:coverage',
             'value' => 'Covers PHP Composer dependencies (with transitive relationships), '
                      . 'vendored browser libraries, browser libraries loaded from a CDN at '
                      . 'runtime, optional Python service dependencies (including those installed '
                      . 'only by this project\'s own install scripts), derived GPL source, '
                      . 'operating-system packages installed by our Dockerfiles and installers, '
                      . 'artifacts downloaded at install time or first use (vocoder binary, '
                      . 'speech and voice models, reference datasets), build- and '
                      . 'verification-time tooling fetched from a registry, and optional container '
                      . 'base images. '
                      . 'EXCLUDED, deliberately and not silently: (1) the operator-supplied '
                      . 'platform — PHP runtime, web server, database — and packages that only a '
                      . 'document instructs the operator to install, because those are the '
                      . 'operator\'s environment rather than something this repository installs; '
                      . '(2) non-code assets; (3) third-party hosted SERVICES the software can be '
                      . 'configured to call, which are not components and are enumerated instead '
                      . 'in SECURITY.md under "What TicketsCAD sends outside your network", '
                      . 'including the optional AI features and every one of them off by default.'],
            ['name'  => 'ticketscad:regenerate', 'value' => 'php tools/generate-sbom.php'],
            /* CISA: SBOM Author Signature. Recorded here so that a recipient
             * holding only this file knows a signature exists, what covers it,
             * which key checks it and how to run the check — without needing
             * our documentation. */
            ['name'  => 'ticketscad:signature-file', 'value' => SBOM_SIG_FILE],
            ['name'  => 'ticketscad:signature-algorithm',
             'value' => 'ECDSA P-256 (prime256v1) with SHA-256, detached, base64-encoded. '
                      . 'The signature covers the exact bytes of SBOM.cdx.json.'],
            ['name'  => 'ticketscad:signature-public-key', 'value' => SBOM_PUBKEY_FILE],
            ['name'  => 'ticketscad:signature-public-key-sha256', 'value' => $pubKeyFingerprint],
            ['name'  => 'ticketscad:signature-verify',
             'value' => 'base64 -d ' . SBOM_SIG_FILE . ' > sbom.sig && openssl dgst -sha256 '
                      . '-verify ' . SBOM_PUBKEY_FILE . ' -signature sbom.sig SBOM.cdx.json'],
        ],
    ],
    'components'   => $cdxComponents,
    'dependencies' => $dependencies,
];

$json = json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

/* ================================================================== *
 * 8. Human-readable rendering
 * ================================================================== */
$groupLabels = [
    'php-composer'      => 'PHP dependencies (Composer, exact versions from composer.lock)',
    'vendored-frontend' => 'Browser libraries shipped in this repository',
    'remote-frontend'   => 'Browser libraries loaded from a CDN at runtime',
    'python-services'   => 'Optional Python service dependencies (installed by the operator)',
    'derived-source'    => 'Derived source (third-party code ported into this tree)',
    'vendored-spec'     => 'Specification data shipped in this repository',
    'container-base'    => 'Container base images (optional Docker deployment)',
    'os-packages'       => 'Operating-system packages installed by our Dockerfiles and installers',
    'downloaded-artifacts' => 'Artifacts downloaded at install time or first use '
                            . '(binaries, models, reference data)',
    'build-tooling'     => 'Build- and verification-time tooling fetched from a registry',
];

$unknownCount = 0;
foreach ($components as $c) if ($c['unknown']) $unknownCount++;

$txt  = "TicketsCAD NewUI — Software Bill of Materials\n";
$txt .= str_repeat('=', 72) . "\n\n";
$txt .= "Application:      TicketsCAD NewUI {$appVersion}\n";
$txt .= "SBOM author:      " . SBOM_AUTHOR . "\n";
$txt .= "SBOM version:     {$sbomVersion}\n";
$txt .= "SBOM serial:      {$serial}\n";
$txt .= "Generated:        {$timestamp}\n";
$txt .= "Generated by:     " . SBOM_TOOL_NAME . " " . SBOM_TOOL_VERSION . "\n";
$txt .= "Generation phase: " . SBOM_LIFECYCLE_PHASE . " (from source, before build)\n";
$txt .= "Machine-readable: SBOM.cdx.json (CycloneDX 1.6)\n";
$txt .= "Standard:         CISA 2026 Minimum Elements for a Software Bill of\n";
$txt .= "                  Materials (SBOM), published 2026-07-29\n";
$txt .= "Regenerate with:  php tools/generate-sbom.php\n\n";
$txt .= "Total components: " . count($components) . "\n";
$txt .= "Components with at least one field unknown to the SBOM author: {$unknownCount}\n";
$txt .= "(Unknown values are stated explicitly below rather than guessed.)\n\n";

$grouped = [];
foreach ($components as $c) $grouped[$c['group']][] = $c;

foreach ($groupLabels as $g => $label) {
    if (empty($grouped[$g])) continue;
    $txt .= str_repeat('-', 72) . "\n";
    $txt .= strtoupper($label) . "\n";
    $txt .= str_repeat('-', 72) . "\n";
    foreach ($grouped[$g] as $c) {
        $txt .= sprintf("  %-42s %-14s %s\n",
            $c['name'],
            $c['version'] ?? 'version unknown',
            licenseLabel($c['license']));
        if ($c['unknown']) {
            $txt .= "      unknown to SBOM author: " . implode(', ', array_unique($c['unknown'])) . "\n";
            if ($c['unknownReason']) {
                $txt .= wordwrap('      why: ' . $c['unknownReason'], 72, "\n      ") . "\n";
            }
        }
    }
    $txt .= "\n";
}

$txt .= str_repeat('=', 72) . "\n";
$txt .= "Reporting a problem with this SBOM, or with any component listed in it:\n";
$txt .= "see SECURITY.md in this repository.\n";

/* ================================================================== *
 * 9. Write / check
 * ================================================================== */
$txtPath = $root . '/SBOM.txt';

if ($checkOnly) {
    $currentJson = is_file($outPath) ? (string) file_get_contents($outPath) : '';
    $currentTxt  = is_file($txtPath) ? (string) file_get_contents($txtPath) : '';
    if ($currentJson === $json && $currentTxt === $txt) {
        echo "[OK] SBOM is up to date (" . count($components) . " components, SBOM version {$sbomVersion}).\n";
        exit(0);
    }
    fwrite(STDERR, "[FAIL] SBOM is out of date. Run: php tools/generate-sbom.php\n");
    if (!$unchanged) {
        fwrite(STDERR, "       Component data changed since the committed SBOM was generated.\n");
    }
    exit(1);
}

/* The number we PRINT must be the number we WRITE.
 *
 * Not hypothetical: the vendored-schema component was first added after the
 * document had already been assembled, so the generator cheerfully announced
 * "56 components" while emitting 55. A count that is not read off the emitted
 * document is a claim about intent, not about the artifact — and this SBOM
 * exists to be checked against the artifact. */
$emittedCount = count(json_decode($json, true)['components'] ?? []);
if ($emittedCount !== count($components)) {
    fwrite(STDERR, "[FAIL] internal inconsistency: collected " . count($components)
        . " components but the document contains {$emittedCount}.\n"
        . "       A component was almost certainly added after section 7 assembled\n"
        . "       the document. Nothing was written.\n");
    exit(1);
}

/* Schema gate — BEFORE anything is written.
 *
 * A document that declares "specVersion": "1.6" and does not satisfy the 1.6
 * schema is worse than no SBOM: it is a false assurance, and it breaks in the
 * consumer's tooling rather than ours. We shipped exactly that for a day. The
 * document is validated into a temporary file first, so an invalid one never
 * reaches SBOM.cdx.json — let alone gets signed.
 *
 * Validation runs ajv against the vendored, unmodified official schema. If
 * Node is genuinely unavailable this cannot fail closed without making the
 * generator unrunnable on a plain PHP box, so it warns loudly instead — and
 * `--validate` is a blocking step in BOTH CI and tools/release-snapshot.sh, so
 * nothing can be PUBLISHED without it. */
$tmpPath = $outPath . '.validating.tmp';
file_put_contents($tmpPath, $json);
[$vStatus, $vDetail] = validateCycloneDx($tmpPath);
@unlink($tmpPath);

if ($vStatus === 'invalid') {
    fwrite(STDERR, "[FAIL] refusing to write: the generated document does NOT conform\n");
    fwrite(STDERR, "       to the official CycloneDX 1.6 schema.\n\n{$vDetail}\n\n");
    fwrite(STDERR, "       SBOM.cdx.json was left untouched.\n");
    exit(1);
}

file_put_contents($outPath, $json);
file_put_contents($txtPath, $txt);

echo "[OK] SBOM.cdx.json   CycloneDX 1.6, " . count($components) . " components, SBOM version {$sbomVersion}\n";
if ($vStatus === 'valid') {
    echo "[OK] validates against the official CycloneDX 1.6 schema (vendored, unmodified)\n";
} else {
    echo "[WARN] schema validation SKIPPED — {$vDetail}\n";
    echo "       Run `php tools/generate-sbom.php --validate` somewhere with Node before\n";
    echo "       publishing. The release script and CI both require it.\n";
}
echo "[OK] SBOM.txt        human-readable rendering\n";
if ($unchanged) {
    echo "     (component data unchanged — timestamp and version preserved)\n";
}
echo "     components with unknown fields: {$unknownCount} (stated explicitly, not guessed)\n";

/* ================================================================== *
 * 10. Optional detached signature — CISA "SBOM Author Signature"
 * ================================================================== *
 * Requires a private key the project maintainer controls. No key is committed
 * to this repository, so an unsigned SBOM is the default and the signature
 * element is honestly absent unless a key is supplied.
 */
$sigPath = $root . '/' . SBOM_SIG_FILE;

if ($signKey !== null && $signKey !== '') {
    if (!is_file($signKey)) {
        fwrite(STDERR, "[FAIL] signing key not found: {$signKey}\n");
        exit(1);
    }
    if (!function_exists('openssl_sign')) {
        fwrite(STDERR, "[FAIL] the OpenSSL extension is required to sign the SBOM\n");
        exit(1);
    }
    $pkey = openssl_pkey_get_private((string) file_get_contents($signKey));
    if ($pkey === false) {
        fwrite(STDERR, "[FAIL] could not load the private key (is it encrypted?)\n");
        exit(1);
    }
    $kd = openssl_pkey_get_details($pkey);
    if (!is_array($kd) || ($kd['type'] ?? null) !== OPENSSL_KEYTYPE_EC
        || ($kd['ec']['curve_name'] ?? '') !== SBOM_SIG_CURVE) {
        fwrite(STDERR, "[FAIL] the signing key must be an EC key on " . SBOM_SIG_CURVE
                     . " (" . SBOM_SIG_ALGORITHM . ").\n");
        fwrite(STDERR, "       Ed25519 is not usable here: PHP's openssl_sign() cannot sign\n");
        fwrite(STDERR, "       with it. See the SBOM Author Signature note at the top of this file.\n");
        exit(1);
    }

    /* A signature nobody can check is worse than no signature, because it looks
     * like an assurance. Refuse to sign with a key that does not match the
     * public key we publish. */
    if (!is_file($pubKeyPath)) {
        fwrite(STDERR, "[FAIL] no published public key at " . SBOM_PUBKEY_FILE . ".\n");
        fwrite(STDERR, "       Export it first:  openssl pkey -in <private> -pubout -out "
                     . SBOM_PUBKEY_FILE . "\n");
        exit(1);
    }
    $privPubFp = sbom_pubkey_fingerprint((string) ($kd['key'] ?? ''));
    if ($privPubFp === null || $privPubFp !== $pubKeyFingerprint) {
        fwrite(STDERR, "[FAIL] the signing key does not match the published public key ("
                     . SBOM_PUBKEY_FILE . ").\n");
        fwrite(STDERR, "       published fingerprint: {$pubKeyFingerprint}\n");
        fwrite(STDERR, "       signing-key fingerprint: " . ($privPubFp ?? 'unreadable') . "\n");
        fwrite(STDERR, "       Signing with it would produce a signature no recipient could verify.\n");
        exit(1);
    }

    /* ECDSA signatures are randomised, so re-signing unchanged content would
     * rewrite this file on every run and defeat the reproducibility the rest of
     * this script is built around. If what is already committed still verifies
     * against the SBOM we just generated, keep it. */
    [$stillOk, ] = sbom_verify_detached($json, $sigPath, $pubKeyPath);
    if ($stillOk) {
        echo "[OK] " . SBOM_SIG_FILE . "  existing signature still verifies — left unchanged\n";
    } else {
        $sig = '';
        if (!openssl_sign($json, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
            fwrite(STDERR, "[FAIL] signing failed: " . (openssl_error_string() ?: 'unknown') . "\n");
            exit(1);
        }
        file_put_contents($sigPath, base64_encode($sig) . "\n");
        echo "[OK] " . SBOM_SIG_FILE . "  detached " . SBOM_SIG_ALGORITHM . " signature written\n";
    }

    /* Never claim a signature we have not just verified from disk, with the
     * published public key, exactly as a recipient would. */
    [$vOk, $vWhy] = sbom_verify_detached(
        (string) file_get_contents($outPath), $sigPath, $pubKeyPath
    );
    if (!$vOk) {
        fwrite(STDERR, "[FAIL] self-check failed: {$vWhy}\n");
        exit(1);
    }
    echo "     self-checked against " . SBOM_PUBKEY_FILE . " — {$vWhy}\n";
    echo "     anyone can repeat it:  php tools/generate-sbom.php --verify\n";
} else {
    /* Unsigned run. If a signature is already committed it now covers stale
     * bytes, and a stale signature is a false assurance — say so loudly. */
    if (is_file($sigPath)) {
        [$vOk, $vWhy] = sbom_verify_detached(
            (string) file_get_contents($outPath), $sigPath, $pubKeyPath
        );
        if ($vOk) {
            echo "     signature: " . SBOM_SIG_FILE . " still verifies against this SBOM.\n";
        } else {
            fwrite(STDERR, "[WARN] " . SBOM_SIG_FILE . " no longer matches this SBOM ({$vWhy}).\n");
            fwrite(STDERR, "       Re-sign before committing:\n");
            fwrite(STDERR, "       php tools/generate-sbom.php --sign-key=<private-key.pem>\n");
        }
    } else {
        echo "     NOTE: unsigned. Supply --sign-key=<private-key.pem> to add the\n";
        echo "     CISA 'SBOM Author Signature' element.\n";
    }
}
