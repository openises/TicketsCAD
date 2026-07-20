<?php
/**
 * NewUI v4.0 API — Configuration Summary
 *
 * Returns quick counts and status indicators for the settings landing page.
 * Admin-only endpoint.
 *
 * GET /api/config-summary.php
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

ini_set('display_errors', '0');

if (!is_admin()) {
    json_error('Admin access required', 403);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

$summary = [
    'users'       => ['count' => 0, 'icon' => 'bi-people', 'color' => 'success', 'label' => 'User Accounts', 'link' => '#user-accounts'],
    'members'     => ['count' => 0, 'icon' => 'bi-person-badge', 'color' => 'info', 'label' => 'Personnel', 'link' => '#members'],
    'responders'  => ['count' => 0, 'icon' => 'bi-truck', 'color' => 'primary', 'label' => 'Units', 'link' => '#unit-statuses'],
    'incidents'   => ['count' => 0, 'icon' => 'bi-exclamation-triangle', 'color' => 'warning', 'label' => 'Incident Types', 'link' => '#incident-types'],
    'facilities'  => ['count' => 0, 'icon' => 'bi-building', 'color' => 'danger', 'label' => 'Facilities', 'link' => '#facilities'],
    'teams'       => ['count' => 0, 'icon' => 'bi-people-fill', 'color' => 'info', 'label' => 'Teams', 'link' => '#teams'],
];

// Counts
$queries = [
    'users'      => "SELECT COUNT(*) FROM `{$prefix}user`",
    'members'    => "SELECT COUNT(*) FROM `{$prefix}member`",
    'responders' => "SELECT COUNT(*) FROM `{$prefix}responder`",
    'incidents'  => "SELECT COUNT(*) FROM `{$prefix}in_types`",
    'facilities' => "SELECT COUNT(*) FROM `{$prefix}facilities`",
    'teams'      => "SELECT COUNT(*) FROM `{$prefix}teams`",
];

foreach ($queries as $key => $sql) {
    try {
        $summary[$key]['count'] = (int) db_fetch_value($sql);
    } catch (Exception $e) {
        // Table may not exist
    }
}

// Security insights
$security = [];

// HTTPS status
$security['https'] = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

// 2FA enabled.
// Read from the `settings` table (the modern canonical place written
// by api/config-admin.php on settings save) instead of going through
// get_setting() which targets the legacy `config` table. Beta tester
// a beta tester 2026-06-26 saw the Welcome widget say "Enabled" while
// the Security Compliance page (which already reads from `settings`)
// said "NO" — root cause was the two pages reading from different
// tables that aren't kept in sync. Until the broader config-vs-settings
// schema consolidation lands, point both widgets at `settings`.
// a beta tester 2026-06-26 (second pass — my first fix went the wrong
// direction). The actual SAVE in api/tfa.php:297 writes `tfa_enabled`
// to the `config` table (keyed by `key`), NOT the `settings` table
// (keyed by `name`). Both reader sites (this file + security-compliance)
// were originally split — one reading from `config`, the other from
// `settings` — and the earlier fix aligned them on `settings` to match
// each other, which silently broke the read because the SAVE goes to
// `config`. Correct alignment: BOTH readers point at `config` so they
// agree with the WRITE side.
try {
    $tfaEnabledRow = db_fetch_value(
        "SELECT `value` FROM `{$prefix}config` WHERE `key` = 'tfa_enabled' LIMIT 1"
    );
    $security['tfa_enabled'] = $tfaEnabledRow !== null && (int) $tfaEnabledRow === 1;
} catch (Exception $e) {
    $security['tfa_enabled'] = false;
}

// Users with 2FA enrolled
try {
    $tfaUsers = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_tfa` WHERE `confirmed` = 1");
    $totalUsers = $summary['users']['count'];
    $security['tfa_enrolled'] = $tfaUsers;
    $security['tfa_coverage'] = $totalUsers > 0 ? round(($tfaUsers / $totalUsers) * 100) : 0;
} catch (Exception $e) {
    $security['tfa_enrolled'] = 0;
    $security['tfa_coverage'] = 0;
}

// Recent failed logins (last 24h)
try {
    $failedLogins = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}login_attempts`
         WHERE `success` = 0 AND `created_at` > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $security['failed_logins_24h'] = $failedLogins;
} catch (Exception $e) {
    $security['failed_logins_24h'] = 0;
}

// Active sessions
try {
    $activeSessions = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}active_sessions` WHERE `expires_at` > NOW()"
    );
    $security['active_sessions'] = $activeSessions;
} catch (Exception $e) {
    error_log("[config-summary] active_sessions count failed: " . $e->getMessage());
    $security['active_sessions'] = 0;
}

// Database size
$dbInfo = [];
try {
    $dbSize = db_fetch_one(
        "SELECT
            ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 1) AS `size_mb`,
            COUNT(*) AS `table_count`
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()"
    );
    $dbInfo['size_mb'] = $dbSize ? (float) $dbSize['size_mb'] : 0;
    $dbInfo['table_count'] = $dbSize ? (int) $dbSize['table_count'] : 0;
} catch (Exception $e) {}

// Location providers enabled
$locationInfo = [];
try {
    $enabledProviders = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}location_providers` WHERE `enabled` = 1"
    );
    $totalProviders = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}location_providers`"
    );
    $locationInfo['enabled'] = $enabledProviders;
    $locationInfo['total'] = $totalProviders;
} catch (Exception $e) {
    $locationInfo['enabled'] = 0;
    $locationInfo['total'] = 0;
}

// Communication channels.
// a beta tester 2026-06-26: SMTP "not configured" hint persisted on the
// Welcome page even after he'd successfully tested the SMTP relay. Same
// root cause as the 2FA hint had earlier today — get_setting() reads
// from the `config` table (NewUI-modern), but the SMTP save path
// (inc/channels/smtp.php:_smtp_get_config) and the Settings → Email
// Channel UI both write to the `settings` table (legacy, keyed by
// `name`). Read from the canonical `settings` table directly so the
// hint reflects reality.
$commInfo = [];
try {
    $settingsTable = ($GLOBALS['db_prefix'] ?? '') . 'settings';
    $hasSmtp = db_fetch_value("SELECT `value` FROM `{$settingsTable}` WHERE `name` = 'smtp_host'");
    $hasSms  = db_fetch_value("SELECT `value` FROM `{$settingsTable}` WHERE `name` = 'sms_provider'");
    $hasSlack = db_fetch_value("SELECT `value` FROM `{$settingsTable}` WHERE `name` = 'slack_webhook_url'");
    $commInfo['email_configured'] = !empty($hasSmtp);
    $commInfo['sms_configured']   = !empty($hasSms);
    $commInfo['slack_configured'] = !empty($hasSlack);
} catch (Exception $e) {
    $commInfo = ['email_configured' => false, 'sms_configured' => false, 'slack_configured' => false];
}

// Phase 38: Onboarding hints — actionable suggestions for a new install.
$hints = [];
$cnt = function ($table, $where = '') use ($prefix) {
    try { return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}{$table}` {$where}"); }
    catch (Exception $e) { return null; }
};

$orgCount = $cnt('organization');
if ($orgCount !== null && $orgCount === 0) {
    $hints[] = ['severity'=>'warning','tab'=>'organizations','icon'=>'building','title'=>'No organizations defined yet',
        'body'=>'Add at least one organization so personnel can be assigned to it.'];
}
$incTypeCount = $cnt('in_types');
if ($incTypeCount !== null && $incTypeCount === 0) {
    $hints[] = ['severity'=>'warning','tab'=>'incident-types','icon'=>'exclamation-triangle','title'=>'No incident types defined',
        'body'=>'Dispatchers need at least one incident type before they can create a ticket.'];
}
$userCount = isset($summary['users']['count']) ? (int) $summary['users']['count'] : 0;
if ($userCount <= 1) {
    $hints[] = ['severity'=>'info','tab'=>'user-accounts','icon'=>'people','title'=>'Only one user account exists',
        'body'=>'Add operator and dispatcher accounts so admin/admin isn’t the only login.'];
}
$facCount = $cnt('facilities');
if ($facCount !== null && $facCount === 0) {
    $hints[] = ['severity'=>'info','tab'=>'facilities','icon'=>'hospital','title'=>'No facilities defined',
        'body'=>'Hospitals, shelters, staging areas — add them so dispatchers can route there.'];
}
if (empty($commInfo['email_configured'])) {
    $hints[] = ['severity'=>'info','tab'=>'email-config','icon'=>'envelope','title'=>'Email not configured',
        'body'=>'SMTP server not set. Outgoing email (password resets, notifications) will fail.'];
}
if (empty($security['tfa_enabled'])) {
    $hints[] = ['severity'=>'warning','tab'=>'two-factor-auth','icon'=>'shield-lock','title'=>'Two-factor authentication is disabled',
        'body'=>'CJIS compliance and basic hardening both call for 2FA enablement.'];
}
if (!empty($security['tfa_enabled']) && isset($security['tfa_coverage'])
    && (float) $security['tfa_coverage'] < 100 && $userCount > 1) {
    $hints[] = ['severity'=>'info','tab'=>'user-accounts','icon'=>'shield-exclamation','title'=>'Some users have not enrolled in 2FA',
        'body'=>($security['tfa_enrolled'] ?? 0) . ' of ' . $userCount . ' users enrolled. Encourage the rest.'];
}
$bridgeCount = $cnt('mesh_bridges', 'WHERE revoked_at IS NULL');
if ($bridgeCount === 0) {
    $hints[] = ['severity'=>'info','tab'=>'__link:mesh-console.php','icon'=>'broadcast-pin','title'=>'No mesh bridges registered',
        'body'=>'If you plan to use LoRa-mesh radios, register a bridge token at Mesh Bridges.'];
}
$memberCount = isset($summary['members']['count']) ? (int) $summary['members']['count'] : 0;
if ($memberCount === 0) {
    $hints[] = ['severity'=>'info','tab'=>'members','icon'=>'person-badge','title'=>'No personnel records',
        'body'=>'Add your roster so units and teams can be staffed.'];
}

json_response([
    'summary'      => $summary,
    'security'     => $security,
    'database'     => $dbInfo,
    'location'     => $locationInfo,
    'comms'        => $commInfo,
    'hints'        => $hints,
    'version'      => defined('NEWUI_VERSION') ? NEWUI_VERSION : 'unknown',
    'php_version'  => PHP_VERSION,
]);
