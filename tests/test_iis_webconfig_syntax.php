<?php
/**
 * Gate: every web.config TicketsCAD ships — or tells an administrator to
 * paste — must be valid IIS configuration that actually denies, and must be
 * the SAME configuration everywhere.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * v4.2.3 shipped sql/web.config and tools/web.config to close the exposure
 * described in tests/test_web_exposure_hardening.php on IIS, which does not
 * read .htaccess at all. Both files were invalid. On stock IIS 10 / Windows 11
 * they returned **HTTP 500.19** rather than denying anything.
 *
 * The directory was unreachable, so from the outside it looked fixed. It was
 * not: access was blocked because the configuration threw, not because the
 * rule ran. Anything that made the file parse — an administrator tidying it,
 * a future edit, a different IIS feature set — would have opened the directory
 * again, and the 500 page names your application's physical path while it is
 * at it.
 *
 * Ron Jones (@rjonesbsink), on stock IIS 10 / Windows 11, took it apart against
 * a scratch directory, one variable at a time:
 *
 *   our placement (no <security>) + remove        500
 *   our <deny> element + <security> wrapper       500
 *   add Deny only, no remove                      500
 *   <security> + remove + add accessType="Deny"   401
 *
 * Three independent defects, each fatal on its own:
 *
 *   1. <authorization> was a direct child of <system.webServer>. IIS URL
 *      Authorization lives under <security>.
 *   2. <deny users="*" /> is the ASP.NET element (system.web/authorization).
 *      IIS URL Authorization is a different schema: <add accessType="Deny" …>.
 *      The names sound the same and nothing tells you they are not.
 *   3. applicationHost.config ships <add accessType="Allow" users="*" /> at
 *      server level and the collection is keyed on `users`, so a second
 *      users="*" entry is a duplicate key — 0x800700b7. A <remove users="*" />
 *      must come first. That is a property of the DEFAULT IIS configuration,
 *      which is why it failed on every stock install rather than on unusual
 *      ones.
 *
 * A fourth, non-fatal: <hiddenSegments><add segment="." /></hiddenSegments>
 * returned 200. It neither errored nor blocked, so BOTH mechanisms in the
 * shipped file were inert.
 *
 * ── ONE MECHANISM, AND WHICH ONE (2026-08-02) ────────────────────────
 *
 * The repair above and the repair to backup_harden_dir() were written by two
 * sessions at once and landed on different mechanisms — URL Authorization in
 * the shipped files, Request Filtering in the template inc/backup.php writes.
 * Both deny. Shipping both is how one of them rots, so this gate now requires
 * exactly one, and it is **Request Filtering**:
 *
 *   * Request Filtering is in the default IIS feature set; URL Authorization
 *     is an optional role service. A web.config naming a section whose module
 *     is absent answers HTTP 500.19 for the whole directory — a deny by
 *     accident, which is the bug above, and which is what makes an
 *     administrator delete the file and restore the exposure.
 *   * The 401 in the matrix was measured after the reporter ran DISM to add
 *     the URL Authorization feature; he later concluded it had been present
 *     all along. So the 500s are confirmed on an untouched host and the 401 on
 *     a never-installed host is not. His first conclusion about his own
 *     machine was that the feature was missing — which is the point: its
 *     presence is not self-evident.
 *   * Request Filtering denies FILES. The report that started this was
 *     /backups/ answering 403 while the archive inside answered 200 with a
 *     complete database export.
 *
 * URL Authorization is still accepted, but only ALONGSIDE the file-extension
 * deny and only when correctly formed — an optional extra layer, never the
 * only rule. A file whose sole mechanism is URL Authorization is rejected here
 * even in the exact form the reporter measured at 401; that assertion is
 * below, and it is the one that ends the truce.
 *
 * ── WHY THE TEST LOOKS LIKE THIS ─────────────────────────────────────
 *
 * A gate that only greps for the strings we happen to ship would have passed
 * for the whole time the files were broken — that is this project's most
 * repeated failure (CLAUDE.md, "tests that pass by hand-seeding state the real
 * writer never produces"). So:
 *
 *   * the files are PARSED, and the checks are structural — ancestry, element
 *     name, attribute values, document order;
 *   * the same validator is run against the broken shapes above and must
 *     REJECT each one. A validator that passes everything is worth nothing,
 *     and these are the exact four the reporter measured;
 *   * a small MODEL of documented request-filtering behaviour answers
 *     "would this URL be served?" for the archive, for a script, and for an
 *     extension-less directory URL, so the claims written into the shipped
 *     comments are executable rather than remembered. The model is checked
 *     both ways — it must say "served" for a configuration that permits, or
 *     it proves nothing;
 *   * XML in the shipped documentation is validated too, because a doc snippet
 *     is a copy-paste source and gets pasted verbatim;
 *   * the template inc/backup.php writes at runtime is reconstructed from the
 *     source and put through the same validator and the same model.
 *
 * The model is not a live IIS. What still needs measuring on a real host is
 * listed in docs/WEB-SERVER-HARDENING.md under "What still needs confirming
 * on a real IIS host".
 *
 * ── THE hiddenSegments TRAP ──────────────────────────────────────────
 *
 * hiddenSegments matches ANY segment of a URL path, not just the first. The
 * same reporter added <add segment="vendor" /> at site level and it blocked
 * assets/vendor/bootstrap as well, leaving every page unstyled. This project
 * has already unstyled two live production sites with an over-broad vendor
 * deny once before.
 *
 * So the collision check below is not a string blacklist: it walks the tree,
 * collects every directory name that occurs NESTED inside another directory,
 * and rejects any hidden segment matching one. Today that catches `vendor`
 * (assets/vendor), `tests` (services/audio-matrix/tests) and `backups`
 * (tools/upgrade/backups) without anyone having to remember them, and it will
 * catch the next one on its own.
 *
 * Usage: php tests/test_iis_webconfig_syntax.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

// ─────────────────────────────────────────────────────────────────────
// Directory names that occur nested inside another directory. A hidden
// segment matching one of these blocks a legitimate path.
// ─────────────────────────────────────────────────────────────────────

/** @return array<string,string> nested directory name => an example path */
function iis_nested_dir_names(string $root): array
{
    $skip = ['.git', '.claude', 'node_modules', 'vendor', 'cache', 'uploads'];
    $found = [];

    $walk = function (string $dir, int $depth) use (&$walk, &$found, $skip, $root) {
        if ($depth > 4) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $dir . '/' . $e;
            if (!is_dir($path)) {
                continue;
            }
            // depth 0 = a top-level directory. Anything deeper is "nested",
            // and its NAME is what a hidden segment would match on.
            if ($depth >= 1) {
                $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
                if (!isset($found[$e])) {
                    $found[$e] = $rel;
                }
            }
            if (in_array($e, $skip, true)) {
                continue;   // do not descend, but DO record the name
            }
            $walk($path, $depth + 1);
        }
    };
    $walk($root, 0);
    return $found;
}

$nested = iis_nested_dir_names($root);

// ─────────────────────────────────────────────────────────────────────
// The validator. Structural, not textual.
// ─────────────────────────────────────────────────────────────────────

/** Element names from the document root down to (and including) $el. */
function iis_ancestry(DOMNode $el): array
{
    $chain = [];
    for ($n = $el; $n !== null && $n->nodeType === XML_ELEMENT_NODE; $n = $n->parentNode) {
        array_unshift($chain, $n->nodeName);
    }
    return $chain;
}

/**
 * @param  string   $xml       raw web.config text
 * @param  array    $nested    nested dir name => example path
 * @return string[]            one line per problem; empty array = valid
 */
function iis_webconfig_problems(string $xml, array $nested, array $allowedExtensions = []): array
{
    $problems = [];

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml);
    $xmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$ok || $doc->documentElement === null) {
        $first = $xmlErrors ? trim($xmlErrors[0]->message) : 'unknown parse error';
        return ['not well-formed XML: ' . $first];
    }
    if ($doc->documentElement->nodeName !== 'configuration') {
        $problems[] = 'root element is <' . $doc->documentElement->nodeName
            . '>, must be <configuration>';
    }

    $servers = $doc->getElementsByTagName('system.webServer');
    if ($servers->length === 0) {
        return array_merge($problems, ['no <system.webServer> element']);
    }

    foreach ($servers as $server) {
        // Defect 1 — <authorization> directly under <system.webServer>.
        foreach ($server->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'authorization') {
                $problems[] = '<authorization> is a direct child of <system.webServer>;'
                    . ' IIS URL Authorization must sit under <security> (500.19)';
            }
        }
    }

    // ── The one mechanism this project ships ──────────────────────────
    //
    //   <security><requestFiltering><fileExtensions allowUnlisted="false" />
    //
    // Installed by default, denies the FILE and not merely the listing, and
    // (per Microsoft's own note on 404.7 for "/" requests) denies
    // extension-less URLs too. Ancestry is checked because the section path is
    // what IIS validates: a misplaced element is a 500.19, which is the whole
    // class of bug this file exists to stop.
    $deniesEverything = false;

    foreach ($doc->getElementsByTagName('requestFiltering') as $rf) {
        $chain = iis_ancestry($rf);
        $tail = array_slice($chain, -3);
        if ($tail !== ['system.webServer', 'security', 'requestFiltering']) {
            $problems[] = '<requestFiltering> is at ' . implode('/', $chain)
                . '; IIS reads it at system.webServer/security/requestFiltering (500.19)';
        }
    }

    foreach ($doc->getElementsByTagName('fileExtensions') as $fe) {
        /** @var DOMElement $fe */
        $chain = iis_ancestry($fe);
        $tail = array_slice($chain, -4);
        if ($tail !== ['system.webServer', 'security', 'requestFiltering', 'fileExtensions']) {
            $problems[] = '<fileExtensions> is at ' . implode('/', $chain)
                . '; it must be a child of <requestFiltering> (500.19)';
            continue;
        }
        if (strcasecmp($fe->getAttribute('allowUnlisted'), 'false') !== 0) {
            $problems[] = '<fileExtensions> without allowUnlisted="false" allows every'
                . ' extension that is not explicitly denied — it denies nothing here';
            continue;
        }
        $allowsSomething = false;
        foreach ($fe->getElementsByTagName('add') as $ext) {
            /** @var DOMElement $ext */
            if (strcasecmp($ext->getAttribute('allowed'), 'true') !== 0) {
                continue;
            }
            $allowsSomething = true;
            $which = $ext->getAttribute('fileExtension');
            if ($which === '.' || $which === '') {
                // Never permitted, even for a file with a declared allow-list —
                // this re-opens the extension-less directory URL itself, which
                // is a different and always-wrong thing to allow.
                $problems[] = 'fileExtension="." allowed="true" re-permits every'
                    . ' EXTENSION-LESS url — GET on the directory itself is served again';
            } elseif (in_array($which, $allowedExtensions, true)) {
                // A DECLARED, narrow exception (services/meshtastic and
                // services/meshcore override the parent services/ deny for
                // exactly the .py the Mesh Console tells operators to curl —
                // see those two files' own docblocks). Anything not in the
                // caller's declared list still falls to the branch below.
                continue;
            } else {
                $problems[] = '<fileExtensions allowUnlisted="false"> lists ' . $which
                    . ' as allowed="true", so that extension is still served';
            }
        }
        if (!$allowsSomething) {
            $deniesEverything = true;
        } elseif (!empty($allowedExtensions)) {
            // Everything actually allowed was on the declared exception list,
            // and nothing else was — this file still denies everything OUTSIDE
            // that narrow list, which is what "denies" means for this caller.
            $deniesEverything = true;
        }
    }

    // URL Authorization: permitted as an EXTRA layer, never as the mechanism.
    $auths = [];
    foreach ($doc->getElementsByTagName('authorization') as $auth) {
        if ($auth->parentNode !== null && $auth->parentNode->nodeName === 'security') {
            $auths[] = $auth;
        }
    }

    if (!$deniesEverything) {
        if ($auths) {
            $problems[] = 'URL Authorization is the only mechanism in this file. It is an'
                . ' optional IIS role service, so where it is absent the file answers'
                . ' 500.19 instead of denying. Ship'
                . ' <security><requestFiltering><fileExtensions allowUnlisted="false" />'
                . ' and keep <authorization> only as an extra layer on top';
        } else {
            $problems[] = 'nothing in this file denies anything — TicketsCAD ships'
                . ' <security><requestFiltering><fileExtensions allowUnlisted="false" />';
        }
    }

    foreach ($auths as $auth) {
        $sawRemoveAll = false;
        $sawDenyAll = false;
        $denyBeforeRemove = false;

        foreach ($auth->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var DOMElement $child */
            $name = $child->nodeName;

            // Defect 2 — the ASP.NET element.
            if ($name === 'deny' || $name === 'allow') {
                $problems[] = "<$name> is the ASP.NET system.web element; IIS URL"
                    . ' Authorization uses <add accessType="Deny|Allow" …> (500.19)';
                continue;
            }
            if ($name === 'remove' && $child->getAttribute('users') === '*') {
                $sawRemoveAll = true;
                continue;
            }
            if ($name === 'add') {
                $accessType = $child->getAttribute('accessType');
                $users = $child->getAttribute('users');
                if ($accessType === '' ) {
                    $problems[] = '<add> inside <authorization> has no accessType attribute';
                    continue;
                }
                if (strcasecmp($accessType, 'Deny') === 0 && $users === '*') {
                    $sawDenyAll = true;
                    if (!$sawRemoveAll) {
                        $denyBeforeRemove = true;
                    }
                }
            }
        }

        if (!$sawDenyAll) {
            $problems[] = 'no <add accessType="Deny" users="*" /> — the directory is not denied';
        }
        // Defect 3 — duplicate collection key.
        if ($sawDenyAll && $denyBeforeRemove) {
            $problems[] = 'no <remove users="*" /> before the Deny entry;'
                . ' applicationHost.config already has users="*" and the collection'
                . ' is keyed on it, so this is a duplicate key (0x800700b7 → 500.19)';
        }
        if (!$sawRemoveAll) {
            $problems[] = 'missing <remove users="*" />';
        }
    }

    // Defect 4 — hiddenSegments: inert, or worse, over-broad.
    foreach ($doc->getElementsByTagName('hiddenSegments') as $hs) {
        foreach ($hs->getElementsByTagName('add') as $seg) {
            /** @var DOMElement $seg */
            $s = $seg->getAttribute('segment');
            if ($s === '' || $s === '.' || $s === '..') {
                $problems[] = 'hiddenSegments entry segment="' . $s . '" matches no real'
                    . ' path segment — it returns 200 and blocks nothing, while implying'
                    . ' the directory is protected';
                continue;
            }
            if (isset($nested[$s])) {
                $problems[] = 'hiddenSegments entry segment="' . $s . '" matches ANY path'
                    . ' segment, so it also blocks ' . $nested[$s] . '/';
                continue;
            }
            $problems[] = 'hiddenSegments entry segment="' . $s . '" — hidden segments match'
                . ' any path segment, not just the first; use the file-extension deny'
                . ' instead, which is scoped to the directory the file sits in';
        }
    }

    return $problems;
}

// ─────────────────────────────────────────────────────────────────────
// A model of DOCUMENTED request-filtering behaviour.
//
// Sources, both Microsoft:
//   * fileExtensions reference — "if you set the allowUnlisted attribute to
//     false, all requests for files with extensions that are not contained in
//     the list of allowed extensions will be denied", and a denied request is
//     "HTTP 404 … 404.7 File Extension Denied".
//   * the archived note "IIS 7.x: Getting 404.7 error for '/' root requests
//     after Disabling Allow Unlisted file extension" — extension-less URLs are
//     denied as well, which is why extension-less frameworks must add
//     <add fileExtension="." allowed="true" />.
//
// This is a model, not a live IIS. It exists so the claims written into the
// shipped comments and docs are executable, and so a future edit that quietly
// permits the archive again fails here.
// ─────────────────────────────────────────────────────────────────────

/** @return string 'denied' or 'served' */
function iis_model_verdict(string $xml, string $urlPath): string
{
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        return 'served';        // a file IIS cannot read protects nothing
    }

    // The extension of the last path segment. A trailing slash, or a segment
    // with no dot, is the extension-less case — modelled as "." the way IIS
    // spells it in the allow list.
    $segments = explode('/', trim($urlPath, '/'));
    $last = $segments === [] ? '' : (string) end($segments);
    $ext = '.';
    if (substr($urlPath, -1) !== '/' && strpos($last, '.') !== false) {
        $ext = strtolower(substr($last, (int) strrpos($last, '.')));
    }

    $verdict = 'served';
    foreach ($doc->getElementsByTagName('fileExtensions') as $fe) {
        /** @var DOMElement $fe */
        $chain = iis_ancestry($fe);
        if (array_slice($chain, -4) !== ['system.webServer', 'security',
                                         'requestFiltering', 'fileExtensions']) {
            continue;           // not a section path IIS reads
        }
        $unlistedAllowed = strcasecmp($fe->getAttribute('allowUnlisted'), 'false') !== 0;
        $explicit = null;
        foreach ($fe->getElementsByTagName('add') as $add) {
            /** @var DOMElement $add */
            $name = strtolower($add->getAttribute('fileExtension'));
            if ($name !== '' && $name[0] !== '.') {
                $name = '.' . $name;    // the UI accepts "inc" for ".inc"
            }
            if ($name === $ext) {
                $explicit = strcasecmp($add->getAttribute('allowed'), 'true') === 0;
            }
        }
        if ($explicit === false) {
            $verdict = 'denied';
        } elseif ($explicit === null && !$unlistedAllowed) {
            $verdict = 'denied';
        }
    }
    return $verdict;
}

// ─────────────────────────────────────────────────────────────────────
echo "-- The validator rejects each shape the reporter measured --\n";
// This block is the reason to trust every assertion below it. Each of these
// returned 500 (or, for the last, 200) on stock IIS 10.

$reference = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <fileExtensions allowUnlisted="false" />
      </requestFiltering>
    </security>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
XML;

// Permitted, but only like this: the file-extension deny does the work and URL
// Authorization is a second layer on top of it.
$withOptionalExtra = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <fileExtensions allowUnlisted="false" />
      </requestFiltering>
      <authorization>
        <remove users="*" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
XML;

$badShapes = [
    'authorization outside <security> (500)' => <<<'XML'
<configuration>
  <system.webServer>
    <authorization>
      <remove users="*" />
      <add accessType="Deny" users="*" />
    </authorization>
  </system.webServer>
</configuration>
XML,
    'the ASP.NET <deny> element inside <security> (500)' => <<<'XML'
<configuration>
  <system.webServer>
    <security>
      <authorization>
        <deny users="*" />
      </authorization>
    </security>
  </system.webServer>
</configuration>
XML,
    'Deny with no <remove> first — duplicate collection key (500)' => <<<'XML'
<configuration>
  <system.webServer>
    <security>
      <authorization>
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
  </system.webServer>
</configuration>
XML,
    'the exact v4.2.3 file (500)' => <<<'XML'
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <hiddenSegments>
          <add segment="." />
        </hiddenSegments>
      </requestFiltering>
    </security>
    <authorization>
      <deny users="*" />
    </authorization>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
XML,
];

foreach ($badShapes as $label => $bad) {
    test("rejected: $label", iis_webconfig_problems($bad, $nested) !== []);
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- One mechanism, and it is Request Filtering --\n";

test('accepted: <fileExtensions allowUnlisted="false" /> — the shipped shape',
    iis_webconfig_problems($reference, $nested) === [],
    implode(' | ', iis_webconfig_problems($reference, $nested)));

// The truce is over: the v4.2.4 file was correct IIS configuration and was
// measured at 401, and it is still rejected, because it relies on a role
// service that may not be installed — where it is not, the answer is 500.19.
$urlAuthOnly = <<<'XML'
<configuration>
  <system.webServer>
    <security>
      <authorization>
        <remove users="*" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
XML;
test('rejected: URL Authorization ALONE, even in the form measured at 401',
    iis_webconfig_problems($urlAuthOnly, $nested) !== [],
    'the gate still accepts the mechanism this tree no longer ships');
test('…and the reason given names the role service',
    stripos(implode(' ', iis_webconfig_problems($urlAuthOnly, $nested)), 'role service') !== false);
test('accepted: URL Authorization as an EXTRA layer on top of the deny',
    iis_webconfig_problems($withOptionalExtra, $nested) === [],
    implode(' | ', iis_webconfig_problems($withOptionalExtra, $nested)));

// Misplacement is the 500.19 class of bug, so it is checked for this mechanism
// too and not only for <authorization>.
test('rejected: <fileExtensions> outside <requestFiltering>',
    iis_webconfig_problems(
        '<configuration><system.webServer><security>'
        . '<fileExtensions allowUnlisted="false" />'
        . '</security></system.webServer></configuration>', $nested) !== []);
test('rejected: <requestFiltering> outside <security>',
    iis_webconfig_problems(
        '<configuration><system.webServer><requestFiltering>'
        . '<fileExtensions allowUnlisted="false" />'
        . '</requestFiltering></system.webServer></configuration>', $nested) !== []);
test('rejected: allowUnlisted left at its default of true',
    iis_webconfig_problems(
        '<configuration><system.webServer><security><requestFiltering>'
        . '<fileExtensions><add fileExtension=".zip" allowed="false" /></fileExtensions>'
        . '</requestFiltering></security></system.webServer></configuration>', $nested) !== []);
test('rejected: the "." allow entry that re-permits every directory URL',
    iis_webconfig_problems(
        '<configuration><system.webServer><security><requestFiltering>'
        . '<fileExtensions allowUnlisted="false"><add fileExtension="." allowed="true" />'
        . '</fileExtensions></requestFiltering></security></system.webServer></configuration>',
        $nested) !== []);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- What the deny actually covers (model of documented behaviour) --\n";
// Task 3 of this change: a deny that covers the listing and not the archive is
// not a deny. @rjonesbsink found /backups/ answering 403 while the .zip inside
// it answered 200 with a complete database export.

test('the ARCHIVE is denied, not merely the listing',
    iis_model_verdict($reference, '/backups/ticketscad-2026-08-02-0300.zip') === 'denied');
test('a .sql dump left beside it is denied',
    iis_model_verdict($reference, '/backups/dump.sql') === 'denied');
test('sql/run_migrations.php is denied',
    iis_model_verdict($reference, '/sql/run_migrations.php') === 'denied');
test('the EXTENSION-LESS directory url is denied too (404.7, per MS)',
    iis_model_verdict($reference, '/tools/') === 'denied',
    'if this ever flips, the docs must stop claiming directory urls are covered');
test('a nested directory url is denied',
    iis_model_verdict($reference, '/tools/upgrade/backups/') === 'denied');
test('an extension-less path with no trailing slash is denied',
    iis_model_verdict($reference, '/sql/upgrade') === 'denied');

// A model that answers "denied" to everything proves nothing. These two show
// it can say "served", and they are exactly the configurations the validator
// rejects above — the two halves agree.
test('the model says SERVED under stock IIS (allowUnlisted defaults to true)',
    iis_model_verdict(
        '<configuration><system.webServer><security><requestFiltering>'
        . '<fileExtensions /></requestFiltering></security></system.webServer></configuration>',
        '/backups/x.zip') === 'served');
test('the model says SERVED once "." is allowed — which is why that is rejected',
    iis_model_verdict(
        '<configuration><system.webServer><security><requestFiltering>'
        . '<fileExtensions allowUnlisted="false"><add fileExtension="." allowed="true" />'
        . '</fileExtensions></requestFiltering></security></system.webServer></configuration>',
        '/tools/') === 'served');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- The nested-directory collision detector works --\n";
// Encoded deliberately: these are the names an administrator is most likely to
// reach for, and each one would break a legitimate path.
test('assets/vendor/ is known, so a "vendor" hidden segment is a collision',
    isset($nested['vendor']),
    'the walker found no nested directory named vendor — did assets/vendor move?');
test('a hiddenSegments rule for "vendor" is rejected',
    iis_webconfig_problems(
        '<configuration><system.webServer><security><requestFiltering>'
        . '<fileExtensions allowUnlisted="false" /><hiddenSegments>'
        . '<add segment="vendor" /></hiddenSegments></requestFiltering>'
        . '</security></system.webServer></configuration>',
        $nested
    ) !== []);
test('"tests" occurs nested too, so it is a collision as well',
    isset($nested['tests']),
    'the walker found no nested directory named tests — did services/audio-matrix/tests move?');

// "backups" is the third name an administrator reaches for, and it collides on a
// RUNNING install (tools/upgrade/backups, and the backup directory itself). It is
// deliberately NOT asserted from the tree: git does not track empty directories
// and the archives inside them are gitignored, so tools/upgrade/backups exists on
// a working machine and does NOT exist in a fresh clone. Asserting it passed
// locally for everyone and failed only in CI — the test was resting on a runtime
// artefact rather than on anything we ship.
//
// So prove the DETECTOR instead, which is the thing under test: given a nested
// directory of that name, does a hiddenSegments rule for it get rejected? That
// holds for any name, on any checkout, and does not care what happens to be on
// disk today.
// Every hiddenSegments entry is rejected regardless — that is the shipped
// policy, because the rule returns 200 and protects nothing even when it
// collides with nothing. So a bare "was it rejected?" assertion proves almost
// nothing here; what $nested actually decides is WHICH complaint comes back.
// Test that, or the collision detector could be deleted and this file stay green.
$iisCollide = iis_webconfig_problems(
    '<configuration><system.webServer><security><requestFiltering>'
    . '<fileExtensions allowUnlisted="false" /><hiddenSegments>'
    . '<add segment="backups" /></hiddenSegments></requestFiltering>'
    . '</security></system.webServer></configuration>',
    $nested + ['backups' => 'tools/upgrade/backups']
);
$iisNoCollide = iis_webconfig_problems(
    '<configuration><system.webServer><security><requestFiltering>'
    . '<fileExtensions allowUnlisted="false" /><hiddenSegments>'
    . '<add segment="no-such-directory-anywhere" /></hiddenSegments></requestFiltering>'
    . '</security></system.webServer></configuration>',
    $nested
);
test('a colliding segment is named as a collision, and the path it breaks is quoted',
    $iisCollide !== []
        && strpos(implode(' ', $iisCollide), 'tools/upgrade/backups') !== false,
    'expected the complaint to name tools/upgrade/backups so the reader can see what breaks');
test('a segment that collides with nothing is still rejected, but for the other reason',
    $iisNoCollide !== []
        && strpos(implode(' ', $iisNoCollide), 'matches ANY path') === false,
    'a non-colliding segment must not be reported as a collision — that would make '
    . 'the detector unfalsifiable');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- Every web.config in the tree --\n";

/** @return string[] absolute paths */
function iis_find_webconfigs(string $root): array
{
    $out = [];
    $skip = ['.git', '.claude', 'node_modules', 'vendor'];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($current) use ($skip) {
                return !($current->isDir() && in_array($current->getFilename(), $skip, true));
            }
        )
    );
    foreach ($it as $f) {
        if ($f->isFile() && strcasecmp($f->getFilename(), 'web.config') === 0) {
            $out[] = str_replace('\\', '/', $f->getPathname());
        }
    }
    sort($out);
    return $out;
}

$configs = iis_find_webconfigs($root);
$rel = array_map(function ($p) use ($root) {
    return ltrim(str_replace('\\', '/', substr($p, strlen($root))), '/');
}, $configs);

test('sql/web.config ships', in_array('sql/web.config', $rel, true));
test('tools/web.config ships', in_array('tools/web.config', $rel, true));

// services/meshtastic/ and services/meshcore/ are DELIBERATE, narrow
// overrides of the services/ blanket deny one directory up — the Mesh
// Console tells operators to curl a .py bridge script from each. Everything
// else in the tree must still deny every extension outright; these two are
// held to "denies everything except .py" instead, checked both ways below
// (that .py IS served here, AND that .zip/.php/the directory itself are
// still denied) so the exception cannot silently widen.
$narrowExceptions = [
    'services/meshtastic/web.config' => ['.py'],
    'services/meshcore/web.config'   => ['.py'],
];

foreach ($configs as $i => $path) {
    $text = (string) file_get_contents($path);
    $allowedExt = $narrowExceptions[$rel[$i]] ?? [];
    $problems = iis_webconfig_problems($text, $nested, $allowedExt);
    test($rel[$i] . ' is valid IIS configuration that denies',
        $problems === [], implode(' | ', $problems));

    if ($allowedExt) {
        test($rel[$i] . ' serves the declared exception but still denies a script and a directory url',
            iis_model_verdict($text, '/x/bridge_v2.py') === 'served'
            && iis_model_verdict($text, '/x/archive.zip') === 'denied'
            && iis_model_verdict($text, '/x/run_migrations.php') === 'denied'
            && iis_model_verdict($text, '/x/') === 'denied');
        test($rel[$i] . ' uses Request Filtering with an explicit, narrow allow-list',
            strpos($text, 'allowUnlisted="false"') !== false && strpos($text, '<clear />') !== false,
            'a declared exception must reset the inherited list with <clear /> before adding its own entry');
    } else {
        test($rel[$i] . ' denies a file, a script and a directory url',
            iis_model_verdict($text, '/x/archive.zip') === 'denied'
            && iis_model_verdict($text, '/x/run_migrations.php') === 'denied'
            && iis_model_verdict($text, '/x/') === 'denied');
        // One shape everywhere. Not a style preference: the reason the two
        // files diverged in the first place was two sessions each fixing
        // "their" file.
        test($rel[$i] . ' uses the one shipped mechanism',
            strpos($text, '<fileExtensions allowUnlisted="false" />') !== false,
            'every shipped web.config carries the same four lines (or is a declared exception, handled above)');
    }
}

// A root web.config would apply site-wide, which is where a hidden segment
// does the damage. There is no reason for one to exist.
test('no web.config at the repository root (a site-wide rule is the dangerous one)',
    !in_array('web.config', $rel, true));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- XML in the shipped documentation --\n";
// A snippet in a doc is a copy-paste source. The one in WEB-SERVER-HARDENING.md
// carried all three defects too, so an administrator following the IIS
// instructions to the letter produced a 500 on backups/ and inc/.

$docFiles = array_merge(
    (array) glob($root . '/docs/*.md'),
    (array) glob($root . '/*.md')
);
$snippetCount = 0;
foreach ($docFiles as $doc) {
    $text = (string) file_get_contents($doc);
    if (!preg_match_all('/```xml\s*\n(.*?)```/s', $text, $m)) {
        continue;
    }
    $name = ltrim(str_replace('\\', '/', substr($doc, strlen($root))), '/');
    foreach ($m[1] as $k => $snippet) {
        if (strpos($snippet, '<system.webServer>') === false) {
            continue;   // not an IIS web.config snippet
        }
        $snippetCount++;
        $problems = iis_webconfig_problems($snippet, $nested);
        test("$name: the web.config snippet is one a reader can paste",
            $problems === [], implode(' | ', $problems));
    }
}
test('at least one documentation snippet was found and checked',
    $snippetCount > 0,
    'the IIS section of docs/WEB-SERVER-HARDENING.md lost its example');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- The documentation describes ONE mechanism, and its limits --\n";
$hard = (string) @file_get_contents($root . '/docs/WEB-SERVER-HARDENING.md');
// Prose wraps, and the warning is a blockquote, so a sentence is split across
// lines by a newline AND a "> " marker. Strip both before matching.
$hardFlat = (string) preg_replace('/\s+/', ' ',
    (string) preg_replace('/^\s*>\s?/m', '', $hard));
test('docs/WEB-SERVER-HARDENING.md ships', $hard !== '');
test('it says hidden segments match any path segment, not just the first',
    stripos($hardFlat, 'not just the first') !== false
    && stripos($hardFlat, 'assets/vendor') !== false,
    'the warning that keeps someone from unstyling the whole site is gone');
test('it says a 500 on IIS is a broken config, not protection',
    strpos($hardFlat, '500') !== false && stripos($hardFlat, 'not a pass') !== false);
test('it names the status a denied request actually gets (404.7)',
    strpos($hardFlat, '404.7') !== false,
    'an administrator reading their IIS log needs the substatus');
test('it states what happens to an extension-less request',
    stripos($hardFlat, 'extension-less') !== false || stripos($hardFlat, 'extensionless') !== false,
    'the one property of file-extension filtering a reader would not assume');
test('it presents URL Authorization as optional, with the feature-install command',
    stripos($hardFlat, 'optional') !== false
    && stripos($hardFlat, 'IIS-URLAuthorization') !== false,
    'an admin who wants the extra layer needs the command to install the role service');
test('it says plainly what has NOT been measured on a live IIS',
    stripos($hardFlat, 'needs confirming') !== false,
    'claims read off documentation must be labelled as such');

$iisGuide = (string) @file_get_contents($root . '/docs/INSTALL-WINDOWS-IIS.md');
test('docs/INSTALL-WINDOWS-IIS.md ships', $iisGuide !== '');
test('the Windows guide does not send the reader to install URL Authorization',
    stripos($iisGuide, 'URL Authorization role service is not installed') === false,
    'that instruction belonged to the mechanism this tree no longer ships');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- Templates embedded in PHP --\n";
// inc/backup.php writes a web.config into the backup directory at runtime, for
// installs whose backup directory still sits inside a web root — precisely the
// installs most at risk. Its template gets the same treatment as a file on
// disk, because it IS a file on disk a few seconds later.

$phpFiles = array_merge(
    (array) glob($root . '/inc/*.php'),
    (array) glob($root . '/api/*.php'),
    (array) glob($root . '/tools/*.php'),
    (array) glob($root . '/sql/*.php')
);
$embedded = [];
foreach ($phpFiles as $f) {
    $text = (string) file_get_contents($f);
    // The literal element, not the section path: inc/vapid-keygen.php prints an
    // appcmd line naming system.webServer/fastCgi and writes no config at all.
    if (strpos($text, '<system.webServer>') === false) {
        continue;
    }
    $embedded[] = ltrim(str_replace('\\', '/', substr($f, strlen($root))), '/');
}

// 2026-08-03: the template moved from inc/backup.php to inc/served-dir.php when
// the encryption-key directory turned out to need the identical fence
// (GHSA-3jmh-c6f6-64jc) and the three "is this published?" helpers were made
// shared. backup_harden_dir() and fe_harden_keys_dir() are now both callers of
// served_dir_harden(), so there is ONE template and it is this one. If a second
// writer ever appears in inc/, api/, tools/ or sql/, the loop below picks it up
// on its own and holds it to the same shape.
test('the runtime template in inc/served-dir.php is among the files checked',
    in_array('inc/served-dir.php', $embedded, true),
    'served_dir_harden() writes a web.config; if it stopped, say so deliberately');
test('…and there is exactly one such template in the tree',
    count($embedded) === 1,
    'found: ' . implode(', ', $embedded) . ' — two writers is how the shapes diverged before');

foreach ($embedded as $name) {
    // Reconstruct the template the way PHP will emit it: strip the string
    // concatenation and escaping so we validate what lands on disk.
    $text = (string) file_get_contents($root . '/' . $name);
    $emitted = '';
    if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $text, $m)) {
        foreach ($m[1] as $lit) {
            $s = stripcslashes($lit);
            if (strpos($s, '<') !== false && strpos($s, '>') !== false) {
                $emitted .= $s;
            }
        }
    }
    $start = strpos($emitted, '<configuration>');
    $end = strpos($emitted, '</configuration>');
    if ($start === false || $end === false) {
        test("$name: an IIS template was located for checking", false,
            'the file mentions <system.webServer> but no template could be reconstructed');
        continue;
    }
    $snippet = substr($emitted, $start, $end - $start + strlen('</configuration>'));

    $problems = iis_webconfig_problems($snippet, $nested);
    test("$name: the web.config it writes is valid",
        $problems === [], implode(' | ', $problems));
    test("$name: the web.config it writes denies the ARCHIVE, not just the listing",
        iis_model_verdict($snippet, '/backups/ticketscad-2026-08-02-0300.zip') === 'denied'
        && iis_model_verdict($snippet, '/backups/') === 'denied');
    test("$name: it writes the same shape the tree ships",
        strpos($snippet, '<fileExtensions allowUnlisted="false" />') !== false);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
