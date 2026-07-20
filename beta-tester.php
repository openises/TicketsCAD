<?php
/**
 * TicketsCAD v4 — Beta Tester Registration
 *
 * Public-facing form at https://your-server.example.com/beta-tester.
 * Collects an application, persists it, and notifies the project
 * owner. No login required (this is the entry point).
 *
 * Spam protection:
 *   - Honeypot field "company_url" that browsers fill in but humans
 *     ignore — submissions with a non-empty value get silently dropped
 *   - Minimum form-fill time (3 seconds) — instant submits are bots
 *   - Per-IP rate limit (5 applications / hour) reusing the project's
 *     rate-limit helper
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/security.php';
require_once __DIR__ . '/inc/rate-limit.php';
require_once __DIR__ . '/inc/broker.php';   // for broker_send('smtp', ...)
require_once __DIR__ . '/inc/client-ip.php'; // for client_ip() — honors X-Forwarded-For from trusted proxies (Cloudflare → NPM → Apache chain)

// Defense in depth — never bleed PHP warnings into the form HTML.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 2026-07-04 (GH #13) — pick the session profile matching the
// client's cookie (TCADMOBILE vs PHPSESSID). Without this, a
// browser holding a mobile cookie opens an empty desktop session
// here and bounces to login -> redirect loop.
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Constants ───────────────────────────────────────────────────
$AGREEMENT_VERSION = '1.0';
$AGENCY_TYPES = [
    'volunteer_fire'    => 'Volunteer fire department',
    'ems'               => 'EMS / ambulance service',
    'ares'              => 'ARES (Amateur Radio Emergency Service)',
    'races'             => 'RACES (Radio Amateur Civil Emergency Service)',
    'cert'              => 'CERT (Community Emergency Response Team)',
    'sar'               => 'Search and rescue',
    'campus_security'   => 'Campus security / school safety',
    'municipal_police'  => 'Municipal police',
    'other'             => 'Other (describe below)',
];

// Where to send the notification email when a new application lands.
$notifyTo = get_variable('beta_application_notify_to') ?: 'ejosterberg@gmail.com';

// CSRF: issue a token on GET, verify on POST.
if (empty($_SESSION['beta_csrf_token'])) {
    $_SESSION['beta_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['beta_csrf_token'];
if (empty($_SESSION['beta_form_start_ts'])) {
    $_SESSION['beta_form_start_ts'] = time();
}
$formStartTs = $_SESSION['beta_form_start_ts'];

// ═══════════════════════════════════════════════════════════════
//  POST — handle submission
// ═══════════════════════════════════════════════════════════════
$errors = [];
$success = false;
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Rate limit: 5 applications per hour per IP ───────────────
    // client_ip() consults X-Forwarded-For / X-Real-IP only when the
    // direct connection is from a configured trusted proxy (NPM,
    // cloudflared, etc. — see inc/client-ip.php). Without this, every
    // submission via the Cloudflare tunnel shares one rate-limit
    // bucket because they all carry the NPM's IP as REMOTE_ADDR.
    $srcIp = client_ip();
    if (!rate_limit_ok('beta-app:' . $srcIp, 5, 3600)) {
        http_response_code(429);
        $errors[] = 'Too many submissions from your network. Please try again in an hour, or email ejosterberg@gmail.com directly.';
    }

    // ── CSRF ────────────────────────────────────────────────────
    if (empty($errors)) {
        $providedCsrf = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrfToken, (string) $providedCsrf)) {
            $errors[] = 'Your session expired. Please reload the page and try again.';
        }
    }

    // ── Honeypot ────────────────────────────────────────────────
    if (empty($errors)) {
        $honeypot = trim($_POST['company_url'] ?? '');
        if ($honeypot !== '') {
            // Silently pretend success — don't tip off the bot
            $success = true;
        }
    }

    // ── Minimum fill time ───────────────────────────────────────
    if (empty($errors) && !$success) {
        $elapsed = time() - (int) $formStartTs;
        if ($elapsed < 3) {
            // Same silent-success treatment as honeypot
            $success = true;
        }
    }

    // ── Collect + validate fields ────────────────────────────────
    if (empty($errors) && !$success) {
        $submitted = [
            'full_name'         => trim((string) ($_POST['full_name'] ?? '')),
            'email'             => trim((string) ($_POST['email'] ?? '')),
            'phone'             => trim((string) ($_POST['phone'] ?? '')),
            'github_user'       => trim((string) ($_POST['github_user'] ?? '')),
            'agency_name'       => trim((string) ($_POST['agency_name'] ?? '')),
            'agency_type'       => trim((string) ($_POST['agency_type'] ?? '')),
            'agency_type_other' => trim((string) ($_POST['agency_type_other'] ?? '')),
            'expected_users'    => trim((string) ($_POST['expected_users'] ?? '')),
            'city'              => trim((string) ($_POST['city'] ?? '')),
            'state_or_region'   => trim((string) ($_POST['state_or_region'] ?? '')),
            'country'           => trim((string) ($_POST['country'] ?? '')),
            'timezone'          => trim((string) ($_POST['timezone'] ?? '')),
            'referral_source'   => trim((string) ($_POST['referral_source'] ?? '')),
            'planned_scenarios' => trim((string) ($_POST['planned_scenarios'] ?? '')),
            'feature_interests' => trim((string) ($_POST['feature_interests'] ?? '')),
            'signed_name'       => trim((string) ($_POST['signed_name'] ?? '')),
            'agreed'            => !empty($_POST['agreed']),
            'agreed_not_life_safety' => !empty($_POST['agreed_not_life_safety']),
        ];

        if ($submitted['full_name'] === '' || strlen($submitted['full_name']) > 120) {
            $errors[] = 'Full name is required (max 120 chars).';
        }
        if (!filter_var($submitted['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if ($submitted['agency_name'] === '') {
            $errors[] = 'Agency or organization name is required.';
        }
        if (!isset($AGENCY_TYPES[$submitted['agency_type']])) {
            $errors[] = 'Please pick an agency type from the dropdown.';
        }
        if ($submitted['agency_type'] === 'other' && $submitted['agency_type_other'] === '') {
            $errors[] = 'Please describe your agency type (or pick a different category).';
        }
        if ($submitted['github_user'] !== '' &&
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{0,38}$/', $submitted['github_user'])) {
            $errors[] = 'GitHub username does not look valid (letters, digits, hyphens; max 39 chars).';
        }
        $userCount = ($submitted['expected_users'] === '') ? null : (int) $submitted['expected_users'];
        if ($userCount !== null && ($userCount < 1 || $userCount > 10000)) {
            $errors[] = 'Expected user count must be between 1 and 10,000.';
        }
        // Timezone: optional, but if supplied it must be a valid IANA zone
        // (we'd rather know up front than store junk).
        if ($submitted['timezone'] !== '' &&
            !in_array($submitted['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'Timezone "' . htmlspecialchars($submitted['timezone']) .
                        '" is not recognized — pick from the dropdown.';
        }
        if (!$submitted['agreed']) {
            $errors[] = 'You must agree to the Beta Tester Agreement to apply.';
        }
        if (!$submitted['agreed_not_life_safety']) {
            $errors[] = 'You must acknowledge that the software is not suitable for life-safety dispatch in its current state.';
        }
        if ($submitted['signed_name'] === '') {
            $errors[] = 'Please type your full name as an electronic signature.';
        } elseif (strcasecmp($submitted['signed_name'], $submitted['full_name']) !== 0) {
            $errors[] = 'The signature must match the Full Name you entered above.';
        }
    }

    // ── Persist + email ──────────────────────────────────────────
    if (empty($errors) && !$success) {
        try {
            db_query(
                "INSERT INTO `{$prefix}beta_tester_applications`
                    (submitted_ip, submitted_ua, full_name, email, phone,
                     github_user, agency_name, agency_type, agency_type_other,
                     expected_user_count, city, state_or_region, country, timezone,
                     referral_source, planned_scenarios, feature_interests,
                     agreed_v, signed_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $srcIp,
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    $submitted['full_name'],
                    $submitted['email'],
                    $submitted['phone'] !== '' ? $submitted['phone'] : null,
                    $submitted['github_user'] !== '' ? $submitted['github_user'] : null,
                    $submitted['agency_name'],
                    $submitted['agency_type'],
                    $submitted['agency_type'] === 'other' ? $submitted['agency_type_other'] : null,
                    $userCount,
                    $submitted['city'] !== '' ? $submitted['city'] : null,
                    $submitted['state_or_region'] !== '' ? $submitted['state_or_region'] : null,
                    $submitted['country'] !== '' ? $submitted['country'] : null,
                    $submitted['timezone'] !== '' ? $submitted['timezone'] : null,
                    $submitted['referral_source'] !== '' ? $submitted['referral_source'] : null,
                    $submitted['planned_scenarios'] !== '' ? $submitted['planned_scenarios'] : null,
                    $submitted['feature_interests'] !== '' ? $submitted['feature_interests'] : null,
                    $AGREEMENT_VERSION,
                    $submitted['signed_name'],
                ]
            );
            $appId = (int) db_insert_id();

            // Send the notification email best-effort. Failure here
            // doesn't fail the submission — the row is in the DB.
            _send_notification_email($notifyTo, $appId, $submitted, $AGENCY_TYPES,
                                    $AGREEMENT_VERSION, $srcIp);

            $success = true;
            // Rotate the CSRF token after success so refresh doesn't
            // double-submit, and reset the fill-time clock.
            $_SESSION['beta_csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['beta_form_start_ts'] = time();
        } catch (Exception $e) {
            error_log("[beta-tester] insert failed: " . $e->getMessage());
            $errors[] = 'We could not save your application — please email ejosterberg@gmail.com directly. (Reference: ' . date('Y-m-d H:i') . ')';
        }
    }
}

/**
 * Compose + send the notification email to the project owner.
 *
 * The email is a *permanent record* — it captures the agreement
 * version, the applicant's typed signature, the IP, and the
 * submission timestamp alongside the data they entered. If an
 * applicant ever disputes what they signed up to, this email is
 * the receipt.
 */
function _send_notification_email($to, $appId, array $s, array $agencyTypes,
                                  string $agreementVersion, string $srcIp): void {
    $agencyLabel = $agencyTypes[$s['agency_type']] ?? $s['agency_type'];
    if ($s['agency_type'] === 'other' && $s['agency_type_other'] !== '') {
        $agencyLabel .= ' — ' . $s['agency_type_other'];
    }

    // Compose a "location" line that combines city / region / country
    // into one tidy column rather than three near-empty rows.
    $locationParts = array_filter([
        $s['city'] ?? '',
        $s['state_or_region'] ?? '',
        $s['country'] ?? '',
    ], 'strlen');
    $locationStr = $locationParts ? implode(', ', $locationParts) : '—';

    // Email-header-injection guard: the SMTP relay copies $subject
    // straight into a "Subject: ..." header without stripping CR/LF,
    // so a hostile applicant who put "\r\nBcc: attacker@example.com"
    // in their name could exfiltrate the entire notification (which
    // contains the agreement record + applicant data + IP). Strip any
    // CR/LF/NUL from the user-supplied fragments and cap the subject
    // at a sane length. Even if broker_send/_smtp_relay is hardened
    // later, this defense-in-depth at the call site stays correct.
    $safeName   = preg_replace('/[\r\n\0]+/', ' ', (string) $s['full_name']);
    $safeAgency = preg_replace('/[\r\n\0]+/', ' ', (string) $s['agency_name']);
    $subject = 'TicketsCAD beta application #' . $appId
             . ' — ' . substr($safeName, 0, 80)
             . ' (' . substr($safeAgency, 0, 80) . ')';
    if (strlen($subject) > 200) $subject = substr($subject, 0, 200);

    $rows = [
        ['Application #',   '#' . $appId],
        ['Submitted',       date('Y-m-d H:i:s T')],
        ['Full name',       $s['full_name']],
        ['Email',           $s['email']],
        ['Phone',           $s['phone'] ?: '—'],
        ['GitHub user',     $s['github_user'] ?: '—'],
        ['Agency',          $s['agency_name']],
        ['Agency type',     $agencyLabel],
        ['Expected users',  $s['expected_users'] ?: '—'],
        ['Location',        $locationStr],
        ['Timezone',        $s['timezone'] ?: '—'],
        ['Referral',        $s['referral_source'] ?: '—'],
    ];

    $html = '<h2 style="font-family:sans-serif;margin-bottom:.25rem">New TicketsCAD beta tester application</h2>';
    $html .= '<p style="font-family:sans-serif;color:#666;margin-top:0">Application <strong>#' . (int) $appId . '</strong> — review at <code>/var/www/newui</code>.</p>';

    // ── Applicant data table ───────────────────────────────────
    $html .= '<table border="0" cellspacing="0" cellpadding="6" style="font-family:sans-serif;font-size:14px;border-collapse:collapse;width:100%">';
    foreach ($rows as $r) {
        $html .= '<tr><td style="background:#f6f6f6;font-weight:bold;padding-right:12px;border-bottom:1px solid #ddd;width:35%">'
              . htmlspecialchars($r[0]) . '</td><td style="border-bottom:1px solid #ddd">'
              . htmlspecialchars((string) $r[1]) . '</td></tr>';
    }
    $html .= '</table>';

    if (!empty($s['planned_scenarios'])) {
        $html .= '<h3 style="font-family:sans-serif">Planned testing scenarios</h3>';
        $html .= '<p style="font-family:sans-serif;white-space:pre-wrap;background:#fafafa;padding:.75rem;border-radius:4px">'
              . htmlspecialchars($s['planned_scenarios']) . '</p>';
    }
    if (!empty($s['feature_interests'])) {
        $html .= '<h3 style="font-family:sans-serif">Features of particular interest</h3>';
        $html .= '<p style="font-family:sans-serif;white-space:pre-wrap;background:#fafafa;padding:.75rem;border-radius:4px">'
              . htmlspecialchars($s['feature_interests']) . '</p>';
    }

    // ── Permanent signature record ───────────────────────────────
    $html .= '<hr style="margin:1.5rem 0;border:none;border-top:1px solid #ddd">';
    $html .= '<h3 style="font-family:sans-serif">Agreement record</h3>';
    $html .= '<table border="0" cellspacing="0" cellpadding="6" style="font-family:sans-serif;font-size:14px;border-collapse:collapse;width:100%">';
    $sigRows = [
        ['Agreement version', $agreementVersion],
        ['Typed signature',   $s['signed_name']],
        ['Read+agreed',       !empty($s['agreed']) ? 'Yes' : 'NO (this should not have submitted)'],
        ['Not-life-safety ack', !empty($s['agreed_not_life_safety']) ? 'Yes' : 'NO (this should not have submitted)'],
        ['Source IP',         $srcIp],
        ['Signed at',         date('Y-m-d H:i:s T')],
    ];
    foreach ($sigRows as $r) {
        $html .= '<tr><td style="background:#f6f6f6;font-weight:bold;padding-right:12px;border-bottom:1px solid #ddd;width:35%">'
              . htmlspecialchars($r[0]) . '</td><td style="border-bottom:1px solid #ddd">'
              . htmlspecialchars((string) $r[1]) . '</td></tr>';
    }
    $html .= '</table>';

    // Full agreement body — snapshot of what they actually signed.
    // Mirrors what's displayed on the form. If you change the agreement,
    // bump the version constant in beta-tester.php AND update this
    // block so the email-of-record matches what was on screen.
    $html .= '<h3 style="font-family:sans-serif">Full agreement text shown to the applicant (v' . htmlspecialchars($agreementVersion) . ')</h3>';
    $html .= '<div style="font-family:sans-serif;font-size:13px;background:#fafafa;border-left:3px solid #6610f2;padding:1rem 1.25rem;line-height:1.5">';
    $html .= _beta_agreement_html();
    $html .= '</div>';

    // Send via the broker (configured SMTP relay). If that fails for
    // any reason, log it LOUDLY — the DB row exists so the application
    // isn't lost, but somebody needs to know the notification didn't
    // land.
    $sent = false;
    $sendError = null;
    if (function_exists('broker_send')) {
        try {
            $result = broker_send('smtp', [
                'to'      => $to,
                'subject' => $subject,
                'body'    => $html,
            ]);
            if (!empty($result['success'])) {
                $sent = true;
                error_log("[beta-tester] notification email sent for application #{$appId} to {$to}");
            } else {
                $sendError = $result['error'] ?? 'unknown broker error';
            }
        } catch (Exception $e) {
            $sendError = 'exception: ' . $e->getMessage();
        }
    } else {
        $sendError = 'broker_send not loaded (require_once inc/broker.php missing?)';
    }
    if (!$sent) {
        error_log("[beta-tester] NOTIFICATION FAILED for application #{$appId}: "
                . $sendError . " — applicant: {$s['email']} <{$s['full_name']}> — "
                . "row IS in beta_tester_applications, manual follow-up needed.");
    }
}

/**
 * The agreement body in HTML. Kept in one place so the on-screen form
 * and the email-of-record render the *same* text. If you ever update the
 * agreement, bump $AGREEMENT_VERSION at the top of this file AND edit
 * this function.
 */
function _beta_agreement_html(): string {
    return '
    <h4 style="margin-top:0;font-size:1rem">1. Confidentiality</h4>
    <p>No public sharing of files, screenshots, UI captures, specs, or design docs from the repository without prior written approval. Restriction on a given piece of content lifts when that content becomes part of a public release. You are responsible for keeping your local copy reasonably secured: apply vendor updates promptly, use a secure configuration. TicketsCAD updates should be installed within roughly 36 hours of notification (tracking the git repository is recommended).</p>

    <h4 style="font-size:1rem">2. Use restrictions</h4>
    <p>You acknowledge that TicketsCAD v4 is pre-release dispatch software and that you assume responsibility for all issues that arise from your use of it. <strong>The software may not be suitable for life-safety dispatch in its current state.</strong> Drills, exercises, training scenarios, and ARES/RACES practice nets are the intended beta use.</p>

    <h4 style="font-size:1rem">3. Feedback obligations</h4>
    <p>Commitment to actively use the software in a representative scenario. File bug reports by email to <code>ejosterberg@gmail.com</code>. Check in at least every 7 days with a brief status note covering what you have tested, what worked, and what needs improvement. Flag UX confusion and documentation gaps; screenshots strongly recommended.</p>

    <h4 style="font-size:1rem">4. Open-source contribution framing</h4>
    <p>TicketsCAD v4 will be released as Apache 2.0 open source upon public launch. Any feedback, bug reports, suggestions, and documentation contributions you provide are licensed to the project for use without restriction. Optional code contributions follow Apache 2.0 + DCO sign-off.</p>

    <h4 style="font-size:1rem">5. Liability and no warranty</h4>
    <p>Software is provided AS-IS, no warranty. No liability for data loss, downtime, missed dispatches, or any operational disruption. You run the software at your own risk.</p>

    <h4 style="font-size:1rem">6. Tester conduct</h4>
    <p>No transferring access to other people. No reverse-engineering, decompiling, or building a competing product from this code during the beta period. Good-faith testing only.</p>

    <h4 style="font-size:1rem">7. Termination</h4>
    <p>Either party may end the testing relationship at any time, for any reason, with no penalty. On termination: stop using the software, delete local copies where practical, return or destroy any confidential documentation. Confidentiality, liability disclaimer, and feedback license survive termination.</p>

    <h4 style="font-size:1rem">8. Branding</h4>
    <p>You may say you are a beta tester for TicketsCAD. You may not claim endorsement, affiliation beyond that, or use the project name or logo in your own promotional materials without permission.</p>

    <h4 style="font-size:1rem">9. Geographic and regulatory responsibility</h4>
    <p>You are responsible for your own compliance with local laws around dispatch operations, radio communications, data retention, and PII handling in your jurisdiction. If you use the radio integration features, you are responsible for your own FCC licensing (or your country\'s equivalent) and applicable on-air identification rules (§97.119 in the U.S. for amateur radio).</p>
    ';
}

// Helpers (no XSS via stale $errors / $submitted on re-render)
function e_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function e_text($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function selected_if($a, $b) { return ((string) $a === (string) $b) ? ' selected' : ''; }
function checked_if($v) { return $v ? ' checked' : ''; }
?><!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TicketsCAD Beta Tester Registration</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <style>
        body { background: #f8f9fa; }
        .beta-card { max-width: 880px; margin: 2rem auto; }
        .beta-header { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
                       color: white; padding: 1.5rem 2rem; border-radius: .5rem .5rem 0 0; }
        .beta-header h1 { margin: 0; font-size: 1.5rem; }
        .beta-header .subtitle { opacity: .9; margin-top: .25rem; }
        .beta-body { background: white; padding: 2rem; border-radius: 0 0 .5rem .5rem;
                     box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        .agreement-block { background: #f6f6f6; padding: 1.25rem 1.5rem; border-left: 3px solid #6610f2;
                           border-radius: .25rem; font-size: 0.9rem; line-height: 1.5; }
        .agreement-block h3 { font-size: 1.05rem; margin-top: 1.25rem; color: #495057; }
        .agreement-block h3:first-child { margin-top: 0; }
        .agreement-block p { margin-bottom: .75rem; }
        .agreement-meta { font-size: 0.85rem; color: #6c757d; margin-top: -.25rem; margin-bottom: .75rem; }
        .honeypot { position: absolute; left: -10000px; top: auto; width: 1px; height: 1px; overflow: hidden; }
        .section-header { font-weight: 600; color: #495057; margin-top: 1.5rem; margin-bottom: .5rem;
                          padding-bottom: .25rem; border-bottom: 1px solid #dee2e6; }
        .required-asterisk { color: #dc3545; }
    </style>
</head>
<body>
<div class="container">
    <div class="beta-card">
        <div class="beta-header">
            <h1><i class="bi bi-broadcast-pin me-2"></i>TicketsCAD v4 — Beta Tester Program</h1>
            <div class="subtitle">Pre-release access for volunteer fire, EMS, ARES/RACES, CERT, and small-agency dispatch</div>
        </div>
        <div class="beta-body">

<?php if ($success): ?>
            <div class="alert alert-success">
                <h4 class="alert-heading"><i class="bi bi-check-circle me-2"></i>Application received — thank you</h4>
                <p>
                    Your application has been recorded and Eric has been notified. You should hear back within
                    <strong>3 business days</strong> with either an invitation to the GitHub repository plus install
                    instructions, or a note explaining why this round isn't the right fit.
                </p>
                <hr>
                <p class="mb-0">
                    Questions in the meantime? Email
                    <a href="mailto:ejosterberg@gmail.com">ejosterberg@gmail.com</a> directly.
                </p>
            </div>
<?php else: ?>

            <p class="lead">
                Help shape an open-source CAD platform built specifically for the agencies commercial vendors
                have largely abandoned. The beta program asks for honest use, weekly feedback, and respect for
                pre-release confidentiality. In exchange you get early access, a direct line to the maintainer,
                and a real say in how the software ships.
            </p>

<?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong>Please fix the following before submitting:</strong>
                <ul class="mb-0 mt-2">
<?php foreach ($errors as $err): ?>
                    <li><?php echo e_text($err); ?></li>
<?php endforeach; ?>
                </ul>
            </div>
<?php endif; ?>

            <form method="post" action="" autocomplete="on" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo e_attr($csrfToken); ?>">

                <!-- Honeypot — hidden from humans, fills automatically for bots -->
                <div class="honeypot" aria-hidden="true">
                    <label for="company_url">Leave this blank</label>
                    <input type="text" id="company_url" name="company_url" tabindex="-1" autocomplete="off">
                </div>

                <div class="section-header">About you</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="full_name" class="form-label">Full name <span class="required-asterisk">*</span></label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required maxlength="120"
                               value="<?php echo e_attr($submitted['full_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email <span class="required-asterisk">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required maxlength="180"
                               value="<?php echo e_attr($submitted['email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="tel" class="form-control" id="phone" name="phone" maxlength="40"
                               value="<?php echo e_attr($submitted['phone'] ?? ''); ?>">
                        <div class="form-text">Optional. For time-sensitive coordination only.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="github_user" class="form-label">GitHub username</label>
                        <input type="text" class="form-control" id="github_user" name="github_user" maxlength="39"
                               value="<?php echo e_attr($submitted['github_user'] ?? ''); ?>"
                               placeholder="e.g. octocat">
                        <div class="form-text">Required for read-only repository access. If you don't have one, create at <a href="https://github.com/join" target="_blank" rel="noopener">github.com/join</a> (free).</div>
                    </div>
                </div>

                <div class="section-header">About your agency</div>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label for="agency_name" class="form-label">Agency / organization name <span class="required-asterisk">*</span></label>
                        <input type="text" class="form-control" id="agency_name" name="agency_name" required maxlength="200"
                               value="<?php echo e_attr($submitted['agency_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="expected_users" class="form-label">Expected user accounts</label>
                        <input type="number" class="form-control" id="expected_users" name="expected_users" min="1" max="10000"
                               value="<?php echo e_attr($submitted['expected_users'] ?? ''); ?>"
                               placeholder="e.g. 15">
                        <div class="form-text">Rough count — dispatchers + responders.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="agency_type" class="form-label">Agency type <span class="required-asterisk">*</span></label>
                        <select class="form-select" id="agency_type" name="agency_type" required>
                            <option value="">-- pick one --</option>
<?php foreach ($AGENCY_TYPES as $val => $label): ?>
                            <option value="<?php echo e_attr($val); ?>"<?php echo selected_if($submitted['agency_type'] ?? '', $val); ?>>
                                <?php echo e_text($label); ?>
                            </option>
<?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6" id="agencyOtherWrap" style="<?php echo (($submitted['agency_type'] ?? '') === 'other') ? '' : 'display:none'; ?>">
                        <label for="agency_type_other" class="form-label">Describe your agency type</label>
                        <input type="text" class="form-control" id="agency_type_other" name="agency_type_other" maxlength="120"
                               value="<?php echo e_attr($submitted['agency_type_other'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="city" class="form-label">City</label>
                        <input type="text" class="form-control" id="city" name="city" maxlength="120"
                               value="<?php echo e_attr($submitted['city'] ?? ''); ?>"
                               placeholder="e.g. Bloomington">
                    </div>
                    <div class="col-md-4">
                        <label for="state_or_region" class="form-label">State / province / region</label>
                        <input type="text" class="form-control" id="state_or_region" name="state_or_region" maxlength="120"
                               value="<?php echo e_attr($submitted['state_or_region'] ?? ''); ?>"
                               placeholder="e.g. Minnesota">
                    </div>
                    <div class="col-md-4">
                        <label for="country" class="form-label">Country</label>
                        <input type="text" class="form-control" id="country" name="country" maxlength="80"
                               value="<?php echo e_attr($submitted['country'] ?? 'United States'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label for="timezone" class="form-label">Timezone</label>
                        <input type="text" class="form-control" id="timezone" name="timezone" maxlength="64"
                               list="timezone_list" autocomplete="off"
                               value="<?php echo e_attr($submitted['timezone'] ?? ''); ?>"
                               placeholder="e.g. America/Chicago — start typing to autocomplete">
                        <datalist id="timezone_list">
<?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                            <option value="<?php echo e_attr($tz); ?>">
<?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Used to coordinate any live calls or testing windows. Your browser's detected zone is pre-filled if available.</div>
                    </div>
                </div>

                <div class="section-header">About your testing plans</div>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="planned_scenarios" class="form-label">How do you plan to test?</label>
                        <textarea class="form-control" id="planned_scenarios" name="planned_scenarios" rows="3"
                                  maxlength="2000"
                                  placeholder="e.g. Drill the system during our monthly ARES net; use it for traffic-flow simulations; run a tabletop exercise..."><?php echo e_text($submitted['planned_scenarios'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label for="feature_interests" class="form-label">Any features you're particularly interested in?</label>
                        <textarea class="form-control" id="feature_interests" name="feature_interests" rows="2"
                                  maxlength="2000"
                                  placeholder="e.g. DMR/Meshtastic integration; location tracking; ICS forms; mobile responder app..."><?php echo e_text($submitted['feature_interests'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label for="referral_source" class="form-label">How did you hear about TicketsCAD?</label>
                        <input type="text" class="form-control" id="referral_source" name="referral_source" maxlength="255"
                               value="<?php echo e_attr($submitted['referral_source'] ?? ''); ?>"
                               placeholder="e.g. ARES newsletter, GitHub, recommendation from a colleague">
                    </div>
                </div>

                <div class="section-header">Beta Tester Agreement</div>
                <div class="agreement-meta">
                    Version <?php echo e_text($AGREEMENT_VERSION); ?> · Please read in full before signing below.
                </div>

                <div class="agreement-block mb-4">
                    <h3>1. Confidentiality</h3>
                    <p>No public sharing of files, screenshots, UI captures, specs, or design docs from the repository without prior written approval. Restriction on a given piece of content lifts when that content becomes part of a public release. You are responsible for keeping your local copy reasonably secured: apply vendor updates promptly, use a secure configuration. TicketsCAD updates should be installed within roughly 36 hours of notification (tracking the git repository is recommended).</p>

                    <h3>2. Use restrictions</h3>
                    <p>You acknowledge that TicketsCAD v4 is pre-release dispatch software and that you assume responsibility for all issues that arise from your use of it. <strong>The software may not be suitable for life-safety dispatch in its current state.</strong> Drills, exercises, training scenarios, and ARES/RACES practice nets are the intended beta use.</p>

                    <h3>3. Feedback obligations</h3>
                    <p>Commitment to actively use the software in a representative scenario. File bug reports by email to <code>ejosterberg@gmail.com</code>. Check in at least every 7 days with a brief status note covering what you've tested, what worked, and what needs improvement. Flag UX confusion and documentation gaps; screenshots strongly recommended.</p>

                    <h3>4. Open-source contribution framing</h3>
                    <p>TicketsCAD v4 will be released as Apache 2.0 open source upon public launch. Any feedback, bug reports, suggestions, and documentation contributions you provide are licensed to the project for use without restriction. Optional code contributions follow Apache 2.0 + DCO sign-off.</p>

                    <h3>5. Liability and no warranty</h3>
                    <p>Software is provided AS-IS, no warranty. No liability for data loss, downtime, missed dispatches, or any operational disruption. You run the software at your own risk.</p>

                    <h3>6. Tester conduct</h3>
                    <p>No transferring access to other people. No reverse-engineering, decompiling, or building a competing product from this code during the beta period. Good-faith testing only.</p>

                    <h3>7. Termination</h3>
                    <p>Either party may end the testing relationship at any time, for any reason, with no penalty. On termination: stop using the software, delete local copies where practical, return or destroy any confidential documentation. Confidentiality, liability disclaimer, and feedback license survive termination.</p>

                    <h3>8. Branding</h3>
                    <p>You may say you're a beta tester for TicketsCAD. You may not claim endorsement, affiliation beyond that, or use the project name or logo in your own promotional materials without permission.</p>

                    <h3>9. Geographic and regulatory responsibility</h3>
                    <p>You are responsible for your own compliance with local laws around dispatch operations, radio communications, data retention, and PII handling in your jurisdiction. If you use the radio integration features, you are responsible for your own FCC licensing (or your country's equivalent) and applicable on-air identification rules (§97.119 in the U.S. for amateur radio).</p>
                </div>

                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="agreed" name="agreed" required<?php echo checked_if($submitted['agreed'] ?? false); ?>>
                    <label class="form-check-label" for="agreed">
                        I have read and agree to the Beta Tester Agreement above. <span class="required-asterisk">*</span>
                    </label>
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="agreed_not_life_safety" name="agreed_not_life_safety" required<?php echo checked_if($submitted['agreed_not_life_safety'] ?? false); ?>>
                    <label class="form-check-label" for="agreed_not_life_safety">
                        I acknowledge that this is pre-release software and <strong>not suitable for life-safety dispatch</strong> in its current state. <span class="required-asterisk">*</span>
                    </label>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label for="signed_name" class="form-label">Type your full name as electronic signature <span class="required-asterisk">*</span></label>
                        <input type="text" class="form-control" id="signed_name" name="signed_name" required maxlength="120"
                               value="<?php echo e_attr($submitted['signed_name'] ?? ''); ?>"
                               placeholder="Must exactly match the Full Name above">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date</label>
                        <input type="text" class="form-control" value="<?php echo date('Y-m-d'); ?>" disabled>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-send me-2"></i>Submit Application
                </button>
                <p class="text-body-secondary small text-center mt-3 mb-0">
                    Your application is encrypted in transit and stored only on the TicketsCAD project's
                    private infrastructure. We won't share it with anyone outside the project.
                </p>
            </form>

<?php endif; ?>

        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
// Reveal the "describe your agency type" field when "Other" is picked.
(function () {
    var sel = document.getElementById('agency_type');
    var wrap = document.getElementById('agencyOtherWrap');
    if (!sel || !wrap) return;
    sel.addEventListener('change', function () {
        wrap.style.display = (this.value === 'other') ? '' : 'none';
    });
})();

// Auto-fill timezone from browser if the user hasn't typed anything yet.
// Resolved IANA zone is supported in every modern browser (2018+).
(function () {
    var tz = document.getElementById('timezone');
    if (!tz || tz.value !== '') return;
    try {
        var detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (detected) tz.value = detected;
    } catch (e) {
        // Old browser — leave the field empty; the user can type/pick.
    }
})();
</script>
</body>
</html>
