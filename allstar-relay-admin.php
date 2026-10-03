<?php
/**
 * NewUI v4.0 — AllStar Relay (test) settings (Phase 155, GH#108 S5)
 *
 * Standalone admin page (same precedent as phone-extensions-admin.php /
 * sip-trunks-admin.php) for the SIMULATED relay built in Phase 153: a spoken
 * incident summary is sent to a plain Asterisk TEST node over SSH and the
 * Asterisk Manager Interface and the returned recording is measured. This is
 * NOT AllStarLink and no radio is involved; the page says so first.
 *
 * Until Phase 155 these settings had an endpoint but no screen (the AMI secret
 * could only be set with a hand-made API call) and defaulted to the
 * maintainer's own lab. Backend: api/allstar-relay.php.
 *
 * Gate: action.manage_config (Super Admin) -- install-wide, names servers and
 * holds a secret. Deliberately not is_admin(), not `|| is_admin()`.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

if (!rbac_can('action.manage_config')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AllStar Relay (test) — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Changing the relay test settings requires the install-wide "Manage configuration"
           permission (<code>action.manage_config</code>, Super Admin by default).</p>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">Back to Settings</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$user     = e($_SESSION['user']);
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>AllStar Relay (test) — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 900px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-broadcast-pin text-warning me-2"></i>AllStar Relay (test)</h4>
        <a href="settings.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="arToast" class="alert d-none" role="status"></div>

    <div class="alert alert-warning mb-3" role="note">
        <h6 class="alert-heading mb-1"><i class="bi bi-exclamation-triangle me-1"></i>This is a simulated test node, not AllStarLink</h6>
        <p class="small mb-2">When switched on, an incident's <strong>Relay test page</strong> button sends a spoken summary
            of the incident to a <strong>plain Asterisk test server</strong> you set up (over SSH and the Asterisk Manager
            Interface), plays it out there, and measures the recording that comes back. <strong>Nothing here uses AllStarLink
            or keys a radio</strong> &mdash; it needs a plain Asterisk server and nothing else. It exists to prove the relay
            path works end to end.</p>
        <p class="small mb-0">Real AllStarLink voice integration (listening to and keying a repeater through a private
            AllStarLink node) is <strong>not built</strong>. If a repeater already publishes an audio stream you can listen to it
            today as a <a href="stream-channels-admin.php">Public Audio Stream</a> channel (receive only; it needs the
            audio-matrix service, see <code>docs/AUDIO-MATRIX-SETUP.md</code>).</p>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-toggle-on me-2"></i>Switch</div>
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="arEnabled">
                <label class="form-check-label" for="arEnabled">Enable the relay test (shows <strong>Relay test page</strong>
                    on incident pages for anyone who may dispatch a unit)</label>
            </div>
            <div class="form-text">Off by default. While it is off the button is not shown and the endpoint answers
                &ldquo;not found&rdquo;.</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-hdd-network me-2"></i>Test node connection</div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label form-label-sm mb-0" for="arSshAlias">SSH alias</label>
                    <input type="text" class="form-control form-control-sm font-monospace" id="arSshAlias" maxlength="100"
                           autocomplete="off" placeholder="e.g. relay-node">
                    <div class="form-text">A host entry in the web server account's <code>~/.ssh/config</code> (host, user, key)
                        that can <code>ssh</code>/<code>scp</code> to the test node without a password and run
                        <code>sudo mv</code> there.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0" for="arAmiHost">AMI host</label>
                    <input type="text" class="form-control form-control-sm font-monospace" id="arAmiHost" maxlength="253"
                           autocomplete="off" placeholder="e.g. 192.0.2.10">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-0" for="arAmiPort">AMI port</label>
                    <input type="number" class="form-control form-control-sm font-monospace" id="arAmiPort" min="1" max="65535" value="5038">
                </div>
                <div class="col-md-6">
                    <label class="form-label form-label-sm mb-0" for="arAmiUser">AMI user</label>
                    <input type="text" class="form-control form-control-sm font-monospace" id="arAmiUser" maxlength="64"
                           autocomplete="off">
                    <div class="form-text">A dedicated <code>manager.conf</code> user with <code>originate</code> permission only.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label form-label-sm mb-0" for="arAmiSecret">AMI secret</label>
                    <input type="password" class="form-control form-control-sm font-monospace" id="arAmiSecret" maxlength="128"
                           autocomplete="new-password" placeholder="">
                    <div class="form-text" id="arSecretHelp">Never shown again. Leave blank to keep the stored one.</div>
                </div>
            </div>

            <details class="mt-3">
                <summary class="small">Advanced: dialplan extension and test-node folders</summary>
                <div class="row g-2 mt-1">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm mb-0" for="arExtension">Dialplan extension</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="arExtension" maxlength="32">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label form-label-sm mb-0" for="arAudioDir">Sounds folder on the test node</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="arAudioDir" maxlength="200">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm mb-0" for="arStagingDir">Staging folder</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="arStagingDir" maxlength="200">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm mb-0" for="arRecordingsDir">Recordings folder</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="arRecordingsDir" maxlength="200">
                    </div>
                    <div class="form-text">Plain absolute paths only. These end up in commands run on the test node.</div>
                </div>
            </details>
        </div>
        <div class="card-footer d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-primary" id="arBtnSave"><i class="bi bi-save me-1"></i>Save</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="arBtnTest"
                    title="Logs in to the Asterisk Manager Interface and logs out. Nothing is sent or played.">
                <i class="bi bi-plug me-1"></i>Test saved connection</button>
            <span class="small text-body-secondary align-self-center">The test logs in to the Manager Interface and out again
                &mdash; no audio, nothing sent. It uses the <em>saved</em> settings.</span>
        </div>
    </div>
    <div id="arTestResult" class="alert d-none" role="status" aria-live="polite"></div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/allstar-relay-admin.js?v=<?php echo asset_v('assets/js/allstar-relay-admin.js'); ?>"></script>
</body>
</html>
