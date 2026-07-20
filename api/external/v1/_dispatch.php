<?php
/**
 * Phase 94 Stage 3 — External API URL dispatcher.
 *
 * Routes /api/external/v1/<path> to the right handler file + populates
 * $_GET with any path-component IDs (ticket_id, member_id, etc.) so
 * the handler picks them up the same way it would if reached via
 * direct-file access.
 *
 * URL path → handler + $_GET injections:
 *
 *   /incidents                                → incidents.php
 *   /incidents/<id>                           → incidents.php           ?id=<id>
 *   /incidents/<id>/actions                   → incident-actions.php    ?ticket_id=<id>
 *   /incidents/<id>/assignments               → assignments.php         ?ticket_id=<id>
 *   /incidents/<id>/assignments/<aid>         → assignments.php         ?ticket_id=<id>&assign_id=<aid>
 *   /incidents/<id>/attachments               → attachments.php         ?parent_type=incident&parent_id=<id>
 *
 *   /members                                  → members.php
 *   /members/<id>                             → members.php             ?id=<id>
 *   /members/<id>/status                      → member-status.php       ?member_id=<id>
 *   /members/<id>/attachments                 → attachments.php         ?parent_type=member&parent_id=<id>
 *
 *   /responders                               → responders.php
 *   /responders/<id>                          → responders.php          ?id=<id>
 *   /responders/<id>/status                   → responder-status.php    ?responder_id=<id>
 *
 *   /facilities[/<id>][/attachments]          → facilities.php / attachments.php
 *   /teams[/<id>]                             → teams.php
 *   /incident-types[/<id>]                    → incident-types.php
 *
 * Unknown routes → 404 not_found with the parsed path echoed.
 *
 * Sister path: direct-file access (`/api/external/v1/incidents.php?id=42`)
 * still works on installs without mod_rewrite — the handlers don't care
 * how $_GET was populated. The clean-URL form is the documented preferred
 * path; the .php form is documented as the fallback.
 */

declare(strict_types=1);

// Compute the path AFTER /api/external/v1/. Apache passes it via
// REDIRECT_URL or REQUEST_URI; we tolerate both shapes.
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '';

// Strip everything up to and including /api/external/v1/
$marker = '/api/external/v1/';
$pos = strpos($path, $marker);
if ($pos === false) {
    // Shouldn't happen if the .htaccess matched, but defend
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'route_not_found', 'path' => $path]);
    exit;
}
$tail = substr($path, $pos + strlen($marker));
// Trim leading + trailing slashes; drop empty segments
$tail = trim($tail, '/');
$segments = $tail === '' ? [] : array_values(array_filter(explode('/', $tail), 'strlen'));

// Empty path → API discovery stub (intentionally minimal — no auth
// required, just announce the version). Useful for "is this endpoint
// alive?" checks.
if (empty($segments)) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'          => true,
        'api_version' => 'v1',
        'resources'   => [
            'incidents', 'members', 'responders', 'facilities',
            'teams', 'incident-types', 'attachments',
        ],
        'docs'        => '/documentation/?doc=EXTERNAL-API.md',
    ]);
    exit;
}

$resource = $segments[0];
$id1      = $segments[1] ?? null;
$sub      = $segments[2] ?? null;
$id2      = $segments[3] ?? null;

// Validate IDs are numeric where expected. Reject non-numeric early.
$intId1 = ($id1 !== null && ctype_digit((string) $id1)) ? (int) $id1 : null;
$intId2 = ($id2 !== null && ctype_digit((string) $id2)) ? (int) $id2 : null;

// Resolve route → handler file + $_GET injections
$handler = null;
switch ($resource) {

    case 'incidents':
        if ($id1 === null) {
            $handler = 'incidents.php';                       // list / create
        } elseif ($intId1 === null) {
            _ext_404('invalid_incident_id', $id1);
        } else {
            $_GET['id'] = $intId1;
            if ($sub === null) {
                $handler = 'incidents.php';                   // detail / patch / delete
            } elseif ($sub === 'actions') {
                $_GET['ticket_id'] = $intId1;
                unset($_GET['id']);
                $handler = 'incident-actions.php';
            } elseif ($sub === 'assignments') {
                $_GET['ticket_id'] = $intId1;
                unset($_GET['id']);
                if ($id2 !== null) {
                    if ($intId2 === null) _ext_404('invalid_assign_id', $id2);
                    $_GET['assign_id'] = $intId2;
                }
                $handler = 'assignments.php';
            } elseif ($sub === 'attachments') {
                $_GET['parent_type'] = 'incident';
                $_GET['parent_id']   = $intId1;
                unset($_GET['id']);
                $handler = 'attachments.php';
            } else {
                _ext_404('unknown_subresource', $sub);
            }
        }
        break;

    case 'members':
        if ($id1 === null) {
            $handler = 'members.php';
        } elseif ($intId1 === null) {
            _ext_404('invalid_member_id', $id1);
        } else {
            $_GET['id'] = $intId1;
            if ($sub === null) {
                $handler = 'members.php';
            } elseif ($sub === 'status') {
                $_GET['member_id'] = $intId1;
                unset($_GET['id']);
                $handler = 'member-status.php';
            } elseif ($sub === 'attachments') {
                $_GET['parent_type'] = 'member';
                $_GET['parent_id']   = $intId1;
                unset($_GET['id']);
                $handler = 'attachments.php';
            } else {
                _ext_404('unknown_subresource', $sub);
            }
        }
        break;

    case 'responders':
        if ($id1 === null) {
            $handler = 'responders.php';
        } elseif ($intId1 === null) {
            _ext_404('invalid_responder_id', $id1);
        } else {
            $_GET['id'] = $intId1;
            if ($sub === null) {
                $handler = 'responders.php';
            } elseif ($sub === 'status') {
                $_GET['responder_id'] = $intId1;
                unset($_GET['id']);
                $handler = 'responder-status.php';
            } else {
                _ext_404('unknown_subresource', $sub);
            }
        }
        break;

    case 'facilities':
        if ($id1 === null) {
            $handler = 'facilities.php';
        } elseif ($intId1 === null) {
            _ext_404('invalid_facility_id', $id1);
        } else {
            $_GET['id'] = $intId1;
            if ($sub === null) {
                $handler = 'facilities.php';
            } elseif ($sub === 'attachments') {
                $_GET['parent_type'] = 'facility';
                $_GET['parent_id']   = $intId1;
                unset($_GET['id']);
                $handler = 'attachments.php';
            } else {
                _ext_404('unknown_subresource', $sub);
            }
        }
        break;

    case 'teams':
        if ($id1 === null) {
            $handler = 'teams.php';
        } elseif ($intId1 === null) {
            _ext_404('invalid_team_id', $id1);
        } else {
            $_GET['id'] = $intId1;
            $handler = 'teams.php';
        }
        break;

    case 'incident-types':
        if ($id1 === null) {
            $handler = 'incident-types.php';
        } elseif ($intId1 === null) {
            _ext_404('invalid_incident_type_id', $id1);
        } else {
            $_GET['id'] = $intId1;
            $handler = 'incident-types.php';
        }
        break;

    case 'attachments':
        // Direct attachment access (delete by attachment id, list)
        if ($id1 === null) {
            $handler = 'attachments.php';
        } elseif ($intId1 === null) {
            _ext_404('invalid_attachment_id', $id1);
        } else {
            $_GET['id'] = $intId1;
            $handler = 'attachments.php';
        }
        break;

    default:
        _ext_404('unknown_resource', $resource);
}

if ($handler === null) {
    _ext_404('route_not_resolved', $tail);
}

$handlerPath = __DIR__ . '/' . $handler;
if (!is_file($handlerPath)) {
    // Endpoint not implemented yet — clean error, not a 500
    http_response_code(501);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'          => false,
        'api_version' => 'v1',
        'error'       => 'endpoint_not_implemented',
        'handler'     => $handler,
        'route'       => $tail,
    ]);
    exit;
}

require_once $handlerPath;
exit;

function _ext_404(string $code, string $detail): void {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'          => false,
        'api_version' => 'v1',
        'error'       => $code,
        'detail'      => $detail,
    ]);
    exit;
}
