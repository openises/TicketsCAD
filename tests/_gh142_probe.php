<?php
/**
 * GH#142 (Phase 155) - CLI probe: call one branding function in a FRESH process
 * (so get_variable()'s per-process settings cache and the per-request branding
 * cache start empty) and print its result as one JSON line.
 *
 * Usage: php tests/_gh142_probe.php <action> '<json params>'
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/inc/i18n.php';
require_once $root . '/inc/branding.php';

$action = (string) ($argv[1] ?? '');
$p = json_decode((string) ($argv[2] ?? '{}'), true);
$p = is_array($p) ? $p : [];

// A signed-in viewer's active organization, when the test wants one.
$_SESSION = [];
if (isset($p['session_org'])) {
    $_SESSION['active_org_id'] = (int) $p['session_org'];
}
$orgArg = isset($p['org']) ? (int) $p['org'] : null;

$out = null;
switch ($action) {
    case 'scope':
        $out = branding_resolve_scope($orgArg);
        break;
    case 'resolve':
        $out = branding_resolve($orgArg, (string) ($p['variant'] ?? 'light'));
        break;
    case 'img':
        $out = branding_img_html((string) ($p['surface'] ?? 'login'), $orgArg);
        break;
    case 'login':
        $out = branding_login_logo_html();
        break;
    case 'navbar':
        $out = branding_navbar_brand_html();
        break;
    case 'print_config':
        $out = branding_print_config($orgArg);
        break;
    case 'print_script':
        $out = branding_print_config_script($orgArg);
        break;
    case 'public_url':
        $out = branding_public_logo_url($orgArg);
        break;
    case 'setting':
        $out = branding_setting((string) ($p['name'] ?? ''));
        break;
    case 'table_exists':
        $out = branding_table_exists();
        break;
    case 'lifecycle':
        // The whole write/read life of a logo, for tests/test_gh142_branding_nofs.php
        // to run under a private, empty temp directory. Leaves the table as it found it.
        require_once __DIR__ . '/_gh142_branding_fixtures.php';
        db_query("DELETE FROM " . db_table('branding_logos'));
        $up = branding_process_upload(gh142_png());
        $st = branding_store_logo(0, 'light', $up, 1, 'zz142');
        $meta = branding_resolve(0, 'light');
        $uri = $meta ? branding_data_uri($meta) : null;
        $del = branding_delete_logo(0, 'light');
        $out = ['stored' => !empty($st['ok']), 'uri' => is_string($uri) && strpos($uri, 'data:image/png;base64,') === 0,
                'deleted' => !empty($del['ok']), 'tmp' => sys_get_temp_dir()];
        break;
    case 'ics':
        // generatePrintHtml() lives in inc/ics-forms-builtin.php (GH#154 moved it
        // out of api/ics-forms.php, whose endpoint dispatch cannot be included
        // from here). branding_ics_letterhead() is the REAL one.
        require_once $root . '/inc/ics-form-types.php';
        require_once $root . '/inc/ics-forms-builtin.php';
        $row = is_array($p['row'] ?? null) ? $p['row'] : ['id' => 1, 'title' => 'Probe'];
        $res = [];
        foreach ((array) ($p['types'] ?? ['213']) as $type) {
            $data = $type === 'custom'
                ? ['_meta' => ['form_number' => 'ZZ-1', 'form_title' => 'Probe Custom', 'fields' => []]]
                : ['incident_name' => 'Probe', 'to_name' => 'A', 'from_name' => 'B', 'subject' => 'S', 'message' => 'm'];
            $res[$type] = generatePrintHtml($type, $data, $row);
        }
        $out = $res;
        break;
    default:
        $out = ['error' => 'unknown action'];
}
// Wrapped so a legitimate null/false/'' answer is distinguishable from a crash.
echo json_encode(['v' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
