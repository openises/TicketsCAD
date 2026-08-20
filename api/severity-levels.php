<?php
/**
 * NewUI v4.0 API — Read-only list of configured severity levels.
 *
 * GH#87 / GH#88 (2026-08-19). Returns the admin-managed `severity_levels`
 * lookup table (see inc/severity.php) so the New Incident severity
 * dropdown and the Incident Type editor's severity select can both
 * render the exact same scale an admin configured — the single source
 * of truth that eliminates GH#87's client/server mismatch by
 * construction. Deliberately separate from the admin CRUD (Settings ->
 * Severity Levels, gated on action.manage_config via
 * api/config-admin.php?section=severity_levels) — same split as
 * api/insurance-types.php / api/un-statuses.php / api/dispositions-picker.php:
 * READING the scale to populate a dropdown needs no special permission
 * beyond ordinary incident access, only MANAGING the list itself is
 * admin-only.
 *
 * GET /api/severity-levels.php -> all configured severity levels, display order
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/severity.php';

ini_set('display_errors', '0');

json_response(['severity_levels' => severity_levels_for_json()]);
