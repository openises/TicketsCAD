<?php
/**
 * TicketsCAD documentation viewer.
 *
 * Serves `docs/*.md` files as HTML for public consumption (no login wall).
 *
 * URL shapes:
 *   /documentation/                     → docs/INDEX.md
 *   /documentation/?doc=GLOSSARY        → docs/GLOSSARY.md
 *   /documentation/?doc=locales/de/INDEX → docs/locales/de/INDEX.md
 *   /documentation/<slug>               → same as ?doc=<slug> (via .htaccess)
 *
 * Internal markdown links like [X](GLOSSARY.md) get rewritten to
 * `/documentation/GLOSSARY` so navigation works inside the viewer.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/md.php';

// ─── Configuration ────────────────────────────────────────────────
$DOCS_ROOT  = realpath(__DIR__ . '/../docs');                          // newui-dev/newui/docs
$REPO_DOCS  = realpath(__DIR__ . '/../../../docs');                    // <repo>/docs (cross-project)
$BASE_PATH  = '/documentation';                                         // public-facing path
// Assets live beside the documentation directory, not inside it. Derived from
// $BASE_PATH so the two cannot drift apart. Bootstrap is served from our own
// tree rather than a CDN: TicketsCAD is run by groups that operate with no
// internet at all, and a stylesheet fetched at page load is one that vanishes
// with the uplink. This page skips config.php, so it has no Content Security
// Policy of its own — but the rest of the app allows scripts and styles from
// 'self' only, and there is no reason for the doc viewer to be the exception.
$ASSET_BASE = rtrim(dirname($BASE_PATH), '/') . '/assets/vendor';
$DEFAULT_DOC = 'INDEX';

// ─── Routing ──────────────────────────────────────────────────────
$requested = $_GET['doc'] ?? $DEFAULT_DOC;
$requested = trim((string) $requested);
if ($requested === '') $requested = $DEFAULT_DOC;

// Whitelist: only [A-Za-z0-9_/-] segments, .md optional. Reject ..
if (preg_match('#(\.\.|\\\\)#', $requested)
    || !preg_match('#^[A-Za-z0-9_./\-]+$#', $requested)
) {
    http_response_code(400);
    _doc_error('Invalid document name.');
    exit;
}

// Allow the request to use or omit ".md"
$requested = preg_replace('/\.md$/', '', $requested);

// Locate the file. Try docs/ first, then the cross-project repo-root docs/.
$relPath = $requested . '.md';
$file = null;
$source = null;
if ($DOCS_ROOT) {
    $candidate = realpath($DOCS_ROOT . '/' . $relPath);
    if ($candidate && strpos($candidate, $DOCS_ROOT) === 0 && file_exists($candidate)) {
        $file = $candidate;
        $source = 'newui';
    }
}
if (!$file && $REPO_DOCS) {
    $candidate = realpath($REPO_DOCS . '/' . $relPath);
    if ($candidate && strpos($candidate, $REPO_DOCS) === 0 && file_exists($candidate)) {
        $file = $candidate;
        $source = 'repo';
    }
}

if (!$file) {
    http_response_code(404);
    _doc_error("Document not found: " . htmlspecialchars($requested));
    exit;
}

// ─── Render ───────────────────────────────────────────────────────
$markdown = file_get_contents($file);

// Compute the document's directory so we can rewrite relative links correctly.
$docDir = dirname($file);
$baseRoot = ($source === 'repo') ? dirname($REPO_DOCS) : dirname($DOCS_ROOT);
$relativeDir = str_replace('\\', '/', substr($docDir, strlen($baseRoot) + 1));

$html_title = _extract_title($markdown) ?? $requested;

$linkRewriter = function (string $href) use ($BASE_PATH, $requested, $relativeDir) {
    // Anchor-only → leave alone
    if (strpos($href, '#') === 0) return $href;
    // External URLs → leave alone
    if (preg_match('#^(https?:|mailto:|tel:|/)#', $href)) return $href;

    // Split off the fragment for separate handling
    $frag = '';
    if (($hashPos = strpos($href, '#')) !== false) {
        $frag = substr($href, $hashPos);
        $href = substr($href, 0, $hashPos);
    }

    // Resolve relative to the current doc's directory.
    $resolved = _normalize_path($relativeDir . '/' . $href);

    // If it's a markdown link, rewrite to the viewer URL.
    if (preg_match('/\.md$/i', $resolved)) {
        // Convert into "slug" relative to docs root: strip the "<docs>/" prefix
        // and the .md suffix. Anything outside docs/ stays as-is.
        $slug = null;
        if (preg_match('#^newui-dev/newui/docs/(.+)\.md$#', $resolved, $m)) {
            $slug = $m[1];
        } elseif (preg_match('#^docs/(.+)\.md$#', $resolved, $m)) {
            // Cross-project doc — we serve those too
            $slug = $m[1];
        }
        if ($slug !== null) {
            return $BASE_PATH . '/' . $slug . $frag;
        }
        // Markdown file outside the served roots — leave as a raw path (will 404 nicely)
        return $href . $frag;
    }

    // Non-markdown relative link → leave alone (likely a code reference like ../inc/file.php)
    return $href . $frag;
};

$rendered_html = md_to_html($markdown, $linkRewriter);

// ─── Sidebar ──────────────────────────────────────────────────────
$sidebar = _build_sidebar($BASE_PATH, $requested);

// ─── Output ───────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($html_title) ?> — TicketsCAD Documentation</title>
<link rel="stylesheet" href="<?= $ASSET_BASE ?>/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="<?= $ASSET_BASE ?>/bootstrap/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $BASE_PATH ?>/style.css">
</head>
<body data-bs-theme="auto">

<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= $BASE_PATH ?>/">
      <i class="bi bi-book"></i> TicketsCAD&nbsp;Documentation
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-collapse">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav-collapse">
      <div class="ms-auto d-flex gap-2 align-items-center">
        <a class="btn btn-sm btn-outline-secondary" href="/login.php">Back to app</a>
        <button class="btn btn-sm btn-outline-secondary" onclick="toggleTheme()" title="Toggle theme">
          <i class="bi bi-circle-half"></i>
        </button>
      </div>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <div class="row">
    <aside class="col-lg-3 col-md-4 doc-sidebar p-3 border-end">
      <input type="search" class="form-control form-control-sm mb-3" id="doc-filter" placeholder="Filter docs…" aria-label="Filter documentation">
      <?= $sidebar ?>
    </aside>

    <main class="col-lg-9 col-md-8 doc-content p-4">
      <?= $rendered_html ?>

      <hr class="my-5">
      <p class="text-body-secondary small">
        <i class="bi bi-pencil-square"></i>
        Source: <code><?= htmlspecialchars(str_replace('\\', '/', substr($file, strlen($baseRoot) + 1))) ?></code> ·
        Doc revision: <?= date('Y-m-d', filemtime($file)) ?> ·
        Found a problem? <a href="https://github.com/openises/TicketsCAD/issues">File an issue</a>.
      </p>
    </main>
  </div>
</div>

<script src="<?= $ASSET_BASE ?>/bootstrap/bootstrap.bundle.min.js"></script>
<script>
// Sidebar filter
(function () {
    var input = document.getElementById('doc-filter');
    if (!input) return;
    input.addEventListener('input', function () {
        var q = input.value.toLowerCase().trim();
        document.querySelectorAll('.doc-sidebar li').forEach(function (li) {
            var matches = !q || li.textContent.toLowerCase().indexOf(q) !== -1;
            li.style.display = matches ? '' : 'none';
        });
        document.querySelectorAll('.doc-sidebar h6').forEach(function (h) {
            // Hide a section heading if all its siblings (the following ul) are hidden
            var ul = h.nextElementSibling;
            if (!ul || ul.tagName !== 'UL') return;
            var anyVisible = Array.from(ul.children).some(function (li) {
                return li.style.display !== 'none';
            });
            h.style.display = anyVisible ? '' : 'none';
        });
    });
})();

// Theme toggle (light / dark / auto)
function toggleTheme() {
    var body = document.body;
    var current = body.getAttribute('data-bs-theme') || 'auto';
    var next = current === 'auto' ? 'light' : (current === 'light' ? 'dark' : 'auto');
    body.setAttribute('data-bs-theme', next);
    try { localStorage.setItem('tcad-doc-theme', next); } catch (e) {}
}
(function () {
    try {
        var saved = localStorage.getItem('tcad-doc-theme');
        if (saved) document.body.setAttribute('data-bs-theme', saved);
    } catch (e) {}
})();

// Highlight current section in sidebar
(function () {
    var current = <?= json_encode($BASE_PATH . '/' . $requested) ?>;
    document.querySelectorAll('.doc-sidebar a').forEach(function (a) {
        // Normalise trailing slash and query strings
        var href = a.getAttribute('href');
        if (href === current || href === current + '/') {
            a.classList.add('active');
            a.scrollIntoView({block: 'nearest'});
        }
    });
})();
</script>

</body>
</html>
<?php

// ─── Helpers ──────────────────────────────────────────────────────

function _doc_error(string $msg): void
{
    /* $ASSET_BASE as well as $BASE_PATH: this function builds its HTML by string
       concatenation, so a short-echo tag would be emitted literally rather than
       evaluated. Interpolate the variable instead.
       (And note: a PHP close tag inside even a // comment ends PHP mode — which
       is exactly how this line got written wrong the first time.) */
    global $BASE_PATH, $ASSET_BASE;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8">'
       . '<title>Documentation — error</title>'
       . '<link rel="stylesheet" href="' . htmlspecialchars((string) $ASSET_BASE) . '/bootstrap/bootstrap.min.css">'
       . '</head><body class="container py-5">'
       . '<div class="alert alert-warning"><h3 class="alert-heading">' . htmlspecialchars($msg) . '</h3>'
       . '<p>Try the <a href="' . htmlspecialchars($BASE_PATH) . '/">documentation index</a>.</p></div>'
       . '</body></html>';
}

function _extract_title(string $md): ?string
{
    if (preg_match('/^#\s+(.+)$/m', $md, $m)) {
        // Strip basic inline markdown for the <title>
        return trim(preg_replace('/[`*_]/', '', $m[1]));
    }
    return null;
}

/**
 * Normalize "a/b/../c" → "a/c". Doesn't touch leading "/" or scheme.
 */
function _normalize_path(string $p): string
{
    $p = str_replace('\\', '/', $p);
    $parts = explode('/', $p);
    $out = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { array_pop($out); continue; }
        $out[] = $part;
    }
    return implode('/', $out);
}

/**
 * Build the sidebar HTML. Hand-organised by audience so it matches the
 * INDEX.md routing — auto-generation from the filesystem would produce a
 * flat-but-useless alphabetical list.
 */
function _build_sidebar(string $base, string $current): string
{
    $sections = [
        'Getting started' => [
            ['INDEX',                       'Documentation index'],
            ['FAQ',                         'FAQ'],
            ['GLOSSARY',                    'Glossary'],
            ['LEGACY-TO-NEWUI-TERMS',       'Legacy → v4 term map'],
        ],
        'For administrators' => [
            ['INSTALLATION-CHECKLIST',      'Installation checklist'],
            ['WEB-SERVER-HARDENING',        'Web server hardening'],
            ['MAINTENANCE-RUNBOOK',         'Maintenance runbook'],
            ['TROUBLESHOOTING',             'Troubleshooting'],
            ['BACKUP-RECOVERY-RUNBOOK',     'Backup + recovery'],
            ['UPGRADING-FROM-V3',           'Upgrading from v3.44'],
            ['SECURITY-POLICY',             'Security policy'],
            ['CJIS-POSTURE',                'CJIS posture'],
        ],
        'Integrations' => [
            ['DVSWITCH-ADMIN-GUIDE',        'DVSwitch DMR bridge'],
            ['MESH-BRIDGE-GUIDE',           'Meshtastic / MeshCore'],
            ['APRS-LISTENER-SETUP',         'APRS-IS listener'],
            ['OWNTRACKS-CONFIG-PUSH',       'OwnTracks config push'],
            ['map-configuration',           'Map configuration'],
        ],
        'For users' => [
            ['NEWUI-USER-GUIDE',            'User guide'],
            ['PAR-CHECK-GUIDE',             'PAR checks'],
            ['scheduling',                  'Scheduling'],
            ['TRAINING-CURRICULUM',         'Training curriculum (24 modules)'],
        ],
        'For developers' => [
            ['ROUTING-ENGINE-REFERENCE',    'Routing engine reference'],
            ['WEBHOOKS-INTEGRATOR-GUIDE',   'Webhooks integrator guide'],
            ['AUDIT-LOG-REFERENCE',         'Audit log reference'],
            ['ACCESS-CHAIN',                'Access chain'],
            ['I18N-GUIDE',                  'i18n / captions'],
            ['RBAC-GUIDE',                  'RBAC overview'],
            ['RBAC-INTEGRATOR-GUIDE',       'RBAC integrator guide'],
        ],
        'Multilingual' => [
            ['locales/de/INDEX',            '🇩🇪 Deutsch'],
            ['locales/nl/INDEX',            '🇳🇱 Nederlands'],
            ['locales/fr/INDEX',            '🇫🇷 Français'],
            ['locales/es/INDEX',            '🇪🇸 Español'],
            ['locales/CONTRIBUTING-TRANSLATIONS', 'Contributing translations'],
        ],
    ];

    $html = '<nav class="doc-nav">';
    foreach ($sections as $heading => $items) {
        $html .= '<h6 class="text-uppercase small text-body-secondary mt-3">' . htmlspecialchars($heading) . '</h6>';
        $html .= '<ul class="list-unstyled mb-2">';
        foreach ($items as [$slug, $label]) {
            $href = $base . '/' . $slug;
            $isCurrent = ($slug === $current);
            $cls = $isCurrent ? ' class="active"' : '';
            $html .= '<li><a href="' . htmlspecialchars($href) . '"' . $cls . '>' . htmlspecialchars($label) . '</a></li>';
        }
        $html .= '</ul>';
    }
    $html .= '</nav>';
    return $html;
}
