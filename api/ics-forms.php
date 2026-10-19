<?php
/**
 * NewUI v4.0 API - ICS Forms
 *
 * GET /api/ics-forms.php                  — List saved forms (optionally ?incident_id=X)
 * GET /api/ics-forms.php?id=X             — Get a specific saved form
 * GET /api/ics-forms.php?template=213     — Get blank template for a form type
 * POST action=save                        — Save form data (create or update)
 * POST action=delete                      — Soft-delete to the wastebasket
 * POST action=export_xml                  — Export as Winlink-compatible XML (ICS-213)
 * POST action=export_pdf                  — Generate print-optimized HTML
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/ics-forms-write.php';
require_once __DIR__ . '/../inc/ics-form-types.php';
// GH#154 -- the 35 built-in FEMA form definitions, the legacy-data mapping
// and the print renderer (generatePrintHtml()).
require_once __DIR__ . '/../inc/ics-forms-builtin.php';
// GH#142 (Phase 155) -- agency logo letterhead on the print/PDF document.
require_once __DIR__ . '/../inc/branding.php';
if (file_exists(__DIR__ . '/../inc/security-labels.php')) {
    require_once __DIR__ . '/../inc/security-labels.php';
}

$method = $_SERVER['REQUEST_METHOD'];
$prefix = $GLOBALS['db_prefix'] ?? '';

// 2026-08-02 (Chris Byrd) — soft delete. A form in the wastebasket must
// vanish from every read surface: the hub list, a direct ?id= fetch, and both
// exports. An install that has not migrated yet has no such column, so the
// clause is empty there and everything behaves exactly as it did before —
// the Delete action is what reports the missing schema, not every GET.
$icsNotDeleted = ics_forms_has_soft_delete() ? ' AND `deleted_at` IS NULL' : '';

// GH #79 — team-wide visibility for standalone (incident-less) ICS forms.
// Off by default; an admin turns it on only when the install is a single
// organization (see ics_form_accessible()). Read once per request and thread
// it through every access check below.
//
// NOTE: use get_variable() (the `settings` name/value store the Settings UI
// writes to via api/config-admin.php), NOT get_setting() — that reads the
// separate `config` key/value table and would never see this toggle.
$icsShareStandalone = function_exists('get_variable')
    ? (get_variable('ics_forms_share_standalone') === '1')
    : false;

/**
 * Phase 73q (2026-06-14) — IDOR fix. GH #79 (2026-07-12) — configurable
 * standalone-form sharing.
 *
 * Returns true if the current session is allowed to read or mutate
 * this saved form. Rules:
 *  - Admin / form owner — always allowed.
 *  - Form bound to an incident — gate on access to that incident.
 *  - Orphan form (no incident_id) — creator only, UNLESS the install has
 *    opted into team-wide standalone sharing ($shareStandalone), in which
 *    case any authenticated user may read/mutate it.
 *
 * Without this gate, any logged-in user could read or overwrite any
 * other agency's ICS-213 dispatch messages by incrementing the id
 * in a GET. See specs/security-audit-2026-06/ for the audit note.
 *
 * $shareStandalone is passed in (not read from the DB here) so this stays
 * a pure function — the IDOR regression test drives it in isolation, and
 * the callers read get_setting('ics_forms_share_standalone') once per
 * request. It DEFAULTS to false: an install must explicitly assert it is a
 * single organization before orphan forms open to the whole team, because
 * ics_forms carries no org_id and opening it blindly would be a cross-tenant
 * leak on a multi-org install (test_ics_forms_idor.php guards this).
 */
function ics_form_accessible(array $row, bool $shareStandalone = false): bool
{
    if (is_admin()) return true;
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($currentUserId > 0
        && (int) ($row['created_by'] ?? 0) === $currentUserId) {
        return true;
    }
    $incidentId = (int) ($row['incident_id'] ?? 0);
    if ($incidentId > 0) {
        return user_can_access_entity('incident', $incidentId);
    }
    // Orphan form, not owned by us, not admin. GH #79: a beta tester runs one
    // organization per install (a fresh instance per event), so team-wide
    // visibility of standalone forms is exactly what's wanted there. Honor it
    // only when the admin has turned on ics_forms_share_standalone AND the
    // caller is authenticated — never for an anonymous/absent session.
    if ($shareStandalone && $currentUserId > 0) {
        return true;
    }
    return false;
}

// ── GET handlers ────────────────────────────────────────────
if ($method === 'GET') {

    // Get blank template
    if (isset($_GET['template'])) {
        $type = $_GET['template'];
        // Phase 140: a custom type's blank template needs to know WHICH
        // type -- the id travels as a second query param.
        $customTypeId = isset($_GET['custom_type_id']) ? (int) $_GET['custom_type_id'] : null;
        $tpl = getFormTemplate($type, $customTypeId);
        if (!$tpl) json_error('Unknown form type: ' . $type);
        json_response($tpl);
    }

    // Get specific form by id
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?{$icsNotDeleted}",
            [$id]
        );
        if (!$row) json_error('Form not found', 404);
        if (!ics_form_accessible($row, $icsShareStandalone)) json_error('Form not found', 404);
        $row['form_data'] = json_decode($row['form_data_json'], true);
        // GH#154 -- a built-in form saved by an earlier version opens with its
        // old values mapped onto today's FEMA fields (the same function the
        // printer uses; nothing is written back until the user saves).
        if ($row['form_type'] !== 'custom' && is_array($row['form_data'])) {
            $row['form_data'] = ics_form_normalize_data((string) $row['form_type'], $row['form_data']);
        }
        unset($row['form_data_json']);
        // Same key the hub list emits — the editor toolbar's Delete button
        // reads it to decide whether to show itself.
        $row['can_delete'] = ics_forms_has_soft_delete()
            && ics_form_delete_decision(
                   $row,
                   (int) ($_SESSION['user_id'] ?? 0),
                   is_admin() || rbac_can('action.delete_ics_form')
               )['allowed'];
        json_response($row);
    }

    // List forms — optionally filtered by incident_id or form_type
    $where = '1=1' . $icsNotDeleted;
    $params = [];

    if (isset($_GET['incident_id'])) {
        $where .= ' AND `incident_id` = ?';
        $params[] = (int) $_GET['incident_id'];
    }
    if (isset($_GET['form_type'])) {
        $where .= ' AND `form_type` = ?';
        $params[] = $_GET['form_type'];
    }

    $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    // Phase 140: the hub list badge/label for a custom-type row needs its
    // frozen _meta.{form_number,form_title,badge_color,icon} -- pulled via
    // JSON_EXTRACT so the (possibly large, e.g. many table rows) rest of
    // form_data_json never has to travel to the client just to paint a
    // badge. A no-op (all four columns NULL) for every built-in row.
    $rows = db_fetch_all(
        "SELECT `id`, `form_type`, `incident_id`, `title`, `status`,
                `created_by`, `created_by_name`, `created_at`, `updated_at`,
                JSON_UNQUOTE(JSON_EXTRACT(`form_data_json`, '$._meta.form_number')) AS custom_form_number,
                JSON_UNQUOTE(JSON_EXTRACT(`form_data_json`, '$._meta.form_title')) AS custom_form_title,
                JSON_UNQUOTE(JSON_EXTRACT(`form_data_json`, '$._meta.badge_color')) AS custom_badge_color,
                JSON_UNQUOTE(JSON_EXTRACT(`form_data_json`, '$._meta.icon')) AS custom_icon
         FROM `{$prefix}ics_forms`
         WHERE {$where}
         ORDER BY `updated_at` DESC
         LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    // Phase 73q — IDOR fix on the list endpoint. Drop rows the caller
    // can't access (someone else's orphan forms or incidents not in
    // their allocates groups). Cheaper to filter in PHP than to JOIN
    // allocates here, and the resulting page may be short but never
    // contains forbidden rows.
    if (!is_admin()) {
        $rows = array_values(array_filter($rows ?: [], function ($r) use ($icsShareStandalone) {
            return ics_form_accessible($r, $icsShareStandalone);
        }));
    }

    $total = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}ics_forms` WHERE {$where}",
        $params
    );

    // 2026-08-02 (Chris Byrd) — tell the hub which rows this user may delete,
    // so it can render the button only where it will work. The server decides;
    // api/ics-forms.php?action=delete re-checks with the SAME function, so a
    // hand-crafted POST gains nothing from a hidden button. `can_delete` is
    // emitted here because assets/js/ics-forms.js reads exactly that key —
    // see tools/api_contract_audit.php on why JS may not invent key names.
    $icsPrivileged = is_admin() || rbac_can('action.delete_ics_form');
    $icsUserId     = (int) ($_SESSION['user_id'] ?? 0);
    foreach ($rows as $i => $r) {
        $rows[$i]['can_delete'] = ics_forms_has_soft_delete()
            && ics_form_delete_decision($r, $icsUserId, $icsPrivileged)['allowed'];
    }

    json_response(['forms' => $rows, 'total' => $total]);
}

// ── POST handlers ───────────────────────────────────────────
if ($method === 'POST') {

    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!$token) {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['csrf_token'] ?? '';
    }
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    // Parse JSON body if Content-Type is JSON
    if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    // Phase 73r — RBAC gate. Authoring or exporting ICS forms is an
    // operational dispatch task; piggyback on action.create_incident
    // (the existing "trusted to dispatch" permission) plus admin
    // bypass. A user without either has no business saving forms or
    // generating an exportable PDF/XML, since both surfaces contain
    // PII the ICS-213 redactor + Phase 18f labels are supposed to
    // protect.
    //
    // Volunteers without operational roles can still READ forms they
    // own or that are bound to incidents they can access (GET is not
    // gated here — the per-row ics_form_accessible() check from
    // Phase 73q does that more granularly).
    //
    // action.delete_ics_form is admitted here too (2026-08-02): an install
    // that grants ONLY the delete permission to a records role must be able to
    // reach the delete action, or the permission would be a setting wired to
    // nothing. It does not widen anything else — every action below re-checks
    // what it needs, and the delete decision is made per-form.
    if (!is_admin()
        && !rbac_can('action.create_incident')
        && !rbac_can('action.manage_ics_forms')
        && !rbac_can('action.delete_ics_form')) {
        json_error('Forbidden — requires Incident Create or ICS Forms Management role', 403);
    }

    // ── Save form ──
    if ($action === 'save') {
        $formType = $input['form_type'] ?? '';
        $title    = trim($input['title'] ?? '');
        $status   = $input['status'] ?? 'draft';
        $formData = $input['form_data'] ?? [];
        $incidentId = isset($input['incident_id']) && $input['incident_id'] !== ''
            ? (int) $input['incident_id']
            : null;
        $formId   = isset($input['id']) ? (int) $input['id'] : 0;

        $hasCustomCols = ics_forms_has_custom_type_columns();

        // Validate
        // GH#154 -- every built-in FEMA form (inc/ics-forms-builtin.php).
        $validTypes = ics_builtin_slugs();
        if ($hasCustomCols) $validTypes[] = 'custom';
        if (!in_array($formType, $validTypes)) {
            json_error('Invalid form_type. Must be one of: ' . implode(', ', $validTypes));
        }
        $validStatuses = ['draft', 'final', 'sent'];
        if (!in_array($status, $validStatuses)) {
            $status = 'draft';
        }

        // Phase 140: custom-type resolution + select-value validation +
        // the _meta snapshot (build fresh on create, carry forward
        // unchanged on update -- see ics_form_custom_build_meta()'s
        // docblock for why editing the type definition later must never
        // change how an existing submission renders).
        $customTypeId = null;
        if ($formType === 'custom') {
            $requestedCustomTypeId = (int) ($input['custom_type_id'] ?? 0);
            $typeTemplate = ics_form_custom_template($requestedCustomTypeId);
            if (!$typeTemplate) json_error('Form type not found', 404);

            $dataCheck = ics_form_custom_validate_data($typeTemplate['fields'], $formData);
            if (!$dataCheck['valid']) json_error(implode(' ', $dataCheck['errors']));

            $existingMeta = null;
            if ($formId > 0) {
                $existingRow = db_fetch_one(
                    "SELECT `form_data_json` FROM `{$prefix}ics_forms` WHERE `id` = ?{$icsNotDeleted}",
                    [$formId]
                );
                if ($existingRow) {
                    $existingDecoded = json_decode((string) $existingRow['form_data_json'], true);
                    if (is_array($existingDecoded) && isset($existingDecoded['_meta']) && is_array($existingDecoded['_meta'])) {
                        $existingMeta = $existingDecoded['_meta'];
                    }
                }
            }
            $formData['_meta'] = ics_form_custom_build_meta($typeTemplate, $existingMeta);
            $customTypeId = $typeTemplate['custom_type_id'];
        }

        $json = json_encode($formData, JSON_UNESCAPED_UNICODE);
        $userName = $_SESSION['user'] ?? '';
        $wasUpdate = $formId > 0;

        if ($formId > 0) {
            // Phase 73q — IDOR fix on UPDATE. Without this, any logged-in
            // user could overwrite any other agency's saved ICS-213 by
            // passing its id back to ?action=save.
            // 2026-08-02 — {$icsNotDeleted} matters here as much as on the
            // reads: without it a user could edit, un-finalize or overwrite a
            // form sitting in the wastebasket by POSTing its id back, and
            // effectively resurrect a deleted operational record without the
            // admin restore that is supposed to be the only way back.
            // Restore it first; then it is editable again.
            $existing = db_fetch_one(
                "SELECT `id`, `incident_id`, `created_by`
                   FROM `{$prefix}ics_forms` WHERE `id` = ?{$icsNotDeleted}",
                [$formId]
            );
            if (!$existing) json_error('Form not found', 404);
            if (!ics_form_accessible($existing, $icsShareStandalone)) json_error('Form not found', 404);
            // Update existing
            if ($hasCustomCols) {
                db_query(
                    "UPDATE `{$prefix}ics_forms`
                     SET `form_type` = ?, `incident_id` = ?, `title` = ?,
                         `form_data_json` = ?, `status` = ?, `custom_type_id` = ?, `updated_at` = NOW()
                     WHERE `id` = ?",
                    [$formType, $incidentId, $title, $json, $status, $customTypeId, $formId]
                );
            } else {
                db_query(
                    "UPDATE `{$prefix}ics_forms`
                     SET `form_type` = ?, `incident_id` = ?, `title` = ?,
                         `form_data_json` = ?, `status` = ?, `updated_at` = NOW()
                     WHERE `id` = ?",
                    [$formType, $incidentId, $title, $json, $status, $formId]
                );
            }
        } else {
            // Insert new
            if ($hasCustomCols) {
                db_query(
                    "INSERT INTO `{$prefix}ics_forms`
                     (`form_type`, `incident_id`, `title`, `form_data_json`,
                      `created_by`, `created_by_name`, `status`, `custom_type_id`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$formType, $incidentId, $title, $json, $current_user_id, $userName, $status, $customTypeId]
                );
            } else {
                db_query(
                    "INSERT INTO `{$prefix}ics_forms`
                     (`form_type`, `incident_id`, `title`, `form_data_json`,
                      `created_by`, `created_by_name`, `status`)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$formType, $incidentId, $title, $json, $current_user_id, $userName, $status]
                );
            }
            $formId = (int) db_insert_id();
        }

        // GH#154 review (2026-10-19): saving a form wrote no audit entry --
        // only the delete did. Create and update are now recorded, for every
        // form type. audit_log() catches its own failures; the extra guard
        // keeps a logging fault from ever failing a save that already landed.
        try {
            $auditLabel = ics_form_label([
                'form_type' => $formType, 'title' => $title, 'form_data_json' => $json,
            ]);
            audit_log(
                'system',
                $wasUpdate ? 'update' : 'create',
                'ics_forms',
                $formId,
                ($wasUpdate ? 'Updated ' : 'Created ') . $auditLabel
                    . ($incidentId ? " (incident #{$incidentId})" : ''),
                [
                    'form_type'      => $formType,
                    'status'         => $status,
                    'title'          => $title,
                    'incident_id'    => $incidentId,
                    'custom_type_id' => $customTypeId,
                ]
            );
        } catch (Throwable $e) {
            error_log('[ics-forms save audit] ' . $e->getMessage());
        }

        // Whether the user may now delete what they just saved — so the editor
        // toolbar's Delete button appears immediately after the first save of a
        // new form, and disappears the moment a non-privileged author
        // finalizes one. Re-read the row rather than assume: `status` may have
        // been coerced above, and created_by belongs to whoever made it.
        $saved = db_fetch_one(
            "SELECT `status`, `created_by` FROM `{$prefix}ics_forms` WHERE `id` = ?",
            [$formId]
        );
        $canDelete = $saved
            && ics_forms_has_soft_delete()
            && ics_form_delete_decision(
                   $saved,
                   (int) ($_SESSION['user_id'] ?? 0),
                   is_admin() || rbac_can('action.delete_ics_form')
               )['allowed'];

        json_response(['ok' => true, 'id' => $formId, 'can_delete' => $canDelete]);
    }

    // ── Delete form (soft, to the wastebasket) ──
    //
    // Chris Byrd, 2026-07-29: "there is no way to delete it… I can switch a
    // finalized form back to draft but no way to delete it."
    //
    // A draft's creator may delete it; a finalized form is admin-only; the
    // delete is soft and restorable from Settings → Wastebasket; nothing here
    // hard-deletes. The policy lives in inc/ics-forms-write.php so the
    // regression test drives the same code this endpoint does.
    if ($action === 'delete') {
        $formId = (int) ($input['id'] ?? 0);
        if (!$formId) json_error('id is required');

        // Read-access gate first (Phase 73q IDOR rules), so a form the caller
        // cannot see is a 404 rather than a 403 that confirms it exists.
        $row = db_fetch_one(
            "SELECT `id`, `incident_id`, `created_by`, `status`
               FROM `{$prefix}ics_forms` WHERE `id` = ?{$icsNotDeleted}",
            [$formId]
        );
        if (!$row) json_error('Form not found', 404);
        if (!ics_form_accessible($row, $icsShareStandalone)) json_error('Form not found', 404);

        $result = ics_form_soft_delete(
            $formId,
            (int) ($_SESSION['user_id'] ?? 0),
            is_admin() || rbac_can('action.delete_ics_form')
        );

        if (!$result['deleted']) {
            $reason = $result['reason'] ?? '';
            $code = ($reason === 'not_found') ? 404
                  : (in_array($reason, ['finalized', 'not_creator'], true) ? 403 : 400);
            json_error(ics_form_delete_message($reason), $code);
        }

        json_response([
            'ok'      => true,
            'id'      => $formId,
            'message' => 'Form moved to the wastebasket. An administrator can restore it '
                       . 'from Settings → Wastebasket.',
        ]);
    }

    // ── Export XML (ICS-213 Winlink) ──
    if ($action === 'export_xml') {
        $formId = (int) ($input['id'] ?? 0);
        if (!$formId) json_error('id is required');

        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?{$icsNotDeleted}",
            [$formId]
        );
        if (!$row) json_error('Form not found', 404);
        if (!ics_form_accessible($row, $icsShareStandalone)) json_error('Form not found', 404);
        if ($row['form_type'] !== '213') {
            json_error('XML export is only available for ICS-213 forms');
        }

        $data = json_decode($row['form_data_json'], true);
        $xml = generateICS213Xml($data, $row);

        // Return XML as a JSON-wrapped string so JS can create a download
        json_response(['xml' => $xml, 'filename' => 'RMS_Express_Form_ICS213_' . $formId . '.xml']);
    }

    // ── Export print-optimized HTML ──
    if ($action === 'export_pdf') {
        $formId = (int) ($input['id'] ?? 0);
        if (!$formId) json_error('id is required');

        $row = db_fetch_one(
            "SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?{$icsNotDeleted}",
            [$formId]
        );
        if (!$row) json_error('Form not found', 404);
        if (!ics_form_accessible($row, $icsShareStandalone)) json_error('Form not found', 404);

        $data = json_decode($row['form_data_json'], true);
        // Phase 18f — resolve security label for the linked incident
        // and apply watermark + redaction. If no incident_id, fall
        // through with no security wrap.
        $sec = null;
        if (!empty($row['incident_id']) && function_exists('seclabel_resolve')) {
            $sec = seclabel_resolve((int) $row['incident_id']);
        }
        $html = generatePrintHtml($row['form_type'], $data, $row);
        if ($sec) $html = _ics_apply_security_wrap($html, $sec);

        json_response(['html' => $html]);
    }

    json_error('Unknown action: ' . $action);
}

json_error('Method not allowed', 405);

// ═══════════════════════════════════════════════════════════════
// Helper functions
// ═══════════════════════════════════════════════════════════════

/**
 * Return a blank template definition for a given form type.
 *
 * Phase 140: $customTypeId is only meaningful when $type === 'custom' --
 * delegates straight to ics_form_custom_template() (inc/ics-form-types.php),
 * the SAME org-scope + restrict_to_permission choke point the save handler
 * and api/ics-form-types.php's GET ?id=X use.
 *
 * GH#154: the built-in templates (35 FEMA forms) live in
 * inc/ics-forms-builtin.php, one definition shared with the hub page, the
 * printer and the tests.
 */
function getFormTemplate($type, $customTypeId = null) {
    if ($type === 'custom') {
        return ics_form_custom_template($customTypeId);
    }
    return ics_builtin_template((string) $type);
}

/**
 * Generate Winlink-compatible ICS-213 XML.
 */
/**
 * Phase 18f (2026-06-11) — Wrap an exported ICS form HTML with a
 * diagonal watermark per the incident's security label, and optionally
 * redact scope/address-like fields if the label has
 * ics_export_show_full=0. The watermark literal is the
 * ics_watermark_text column; if empty, no watermark.
 */
function _ics_apply_security_wrap(string $html, array $sec): string {
    $watermark = (string) ($sec['ics_watermark_text'] ?? '');
    $showFull  = (int) ($sec['ics_export_show_full'] ?? 1) === 1;

    if (!$showFull) {
        // Phase 73r — was targeting <td class="form-field">…</td> but
        // generatePrintHtml emits <span class="value">…</span> instead,
        // so the redactor silently no-op'd while the watermark made it
        // look like content was protected. Fix: match the actual
        // class. Also redact <td>…</td> blocks that are message-body
        // only (no .label child) since ICS-213's free-text panes don't
        // wrap their content in a .value span at all.
        $name = trim((string) ($sec['name'] ?? ''));
        if ($name === '') $name = 'Restricted';
        $rep = '<span style="background:#dc3545;color:#fff;padding:2px 6px;border-radius:3px;">*** ' .
               htmlspecialchars($name) . ' ***</span>';
        // Replace any .value span content. /s for cross-line match.
        $html = preg_replace(
            '/<span class="value"[^>]*>.*?<\/span>/s',
            '<span class="value">' . $rep . '</span>',
            $html
        );
        // Also redact pure free-text td cells (message body, reply body
        // — wide cells with colspan that printICS213 emits without an
        // inner .value span on some form types).
        $html = preg_replace(
            '/<td colspan="2"[^>]*style="min-height:[^"]*"[^>]*>.*?<\/td>/s',
            '<td colspan="2" style="min-height:60px">' . $rep . '</td>',
            $html
        );
    }

    if ($watermark !== '') {
        // CSS-based diagonal watermark across the page.
        $css = '<style>.p18-watermark{position:fixed;top:0;left:0;width:100vw;height:100vh;display:flex;align-items:center;justify-content:center;font-size:9rem;color:rgba(220,53,69,0.18);font-weight:700;transform:rotate(-32deg);pointer-events:none;z-index:9999;white-space:nowrap;}</style>';
        $div = '<div class="p18-watermark">' . htmlspecialchars($watermark) . '</div>';
        // Insert into <body> if present; else prepend.
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</head>', $css . '</head>', $html);
            $html = str_ireplace('<body', '<body data-p18="' . htmlspecialchars($watermark) . '"', $html);
            $html = str_ireplace('</body>', $div . '</body>', $html);
        } else {
            $html = $css . $div . $html;
        }
    }
    return $html;
}

function generateICS213Xml($data, $row) {
    $now = date('Y-m-d H:i');

    $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    $xml .= '<RMS_Express_Form>' . "\n";
    $xml .= '  <form_parameters>' . "\n";
    $xml .= '    <xml_file_version>1.0</xml_file_version>' . "\n";
    $xml .= '    <rms_express_version>TicketsCAD_v4</rms_express_version>' . "\n";
    $xml .= '    <submission_datetime>' . xs($now) . '</submission_datetime>' . "\n";
    $xml .= '    <senders_callsign></senders_callsign>' . "\n";
    $xml .= '    <grid_square></grid_square>' . "\n";
    $xml .= '    <display_form>ICS213_Viewer.html</display_form>' . "\n";
    $xml .= '  </form_parameters>' . "\n";
    $xml .= '  <variables>' . "\n";
    $xml .= '    <msgseqnum></msgseqnum>' . "\n";
    $xml .= '    <incidentname>' . xs($row['title'] ?? '') . '</incidentname>' . "\n";
    $xml .= '    <datetime>' . xs(($data['date'] ?? '') . ' ' . ($data['time'] ?? '')) . '</datetime>' . "\n";
    $xml .= '    <to>' . xs($data['to_name'] ?? '') . '</to>' . "\n";
    $xml .= '    <toposition>' . xs($data['to_position'] ?? '') . '</toposition>' . "\n";
    $xml .= '    <from>' . xs($data['from_name'] ?? '') . '</from>' . "\n";
    $xml .= '    <fromposition>' . xs($data['from_position'] ?? '') . '</fromposition>' . "\n";
    $xml .= '    <subject>' . xs($data['subject'] ?? '') . '</subject>' . "\n";
    $xml .= '    <message>' . xs($data['message'] ?? '') . '</message>' . "\n";
    $xml .= '    <approved_by>' . xs($data['approved_name'] ?? '') . '</approved_by>' . "\n";
    $xml .= '    <approved_position>' . xs($data['approved_position'] ?? '') . '</approved_position>' . "\n";
    $xml .= '    <approved_datetime></approved_datetime>' . "\n";
    $xml .= '    <reply>' . xs($data['reply'] ?? '') . '</reply>' . "\n";
    $xml .= '    <reply_by>' . xs($data['reply_by'] ?? '') . '</reply_by>' . "\n";
    $xml .= '    <reply_position></reply_position>' . "\n";
    $xml .= '    <reply_datetime>' . xs(($data['reply_date'] ?? '') . ' ' . ($data['reply_time'] ?? '')) . '</reply_datetime>' . "\n";
    $xml .= '  </variables>' . "\n";
    $xml .= '</RMS_Express_Form>' . "\n";

    return $xml;
}

/** XML-safe string escaping */
function xs($str) {
    return htmlspecialchars($str ?? '', ENT_XML1, 'UTF-8');
}

// generatePrintHtml() and the print renderer live in inc/ics-forms-builtin.php (GH#154).
