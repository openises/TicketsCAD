<?php
/**
 * NewUI v4.0 — the standalone Phone window (Phase 155, GH#108 S1).
 *
 * TicketsCAD is a multi-page application, so a SIP registration made by a
 * page's JavaScript ends the moment the operator navigates away. This is the
 * one page that never navigates: a small window (opened with window.open from
 * the navbar phone button or the widget's own "open in its own window"
 * button) that holds the registration, keeps ringing while the operator works
 * in other tabs, and carries nothing else -- no navbar, no SSE, no banner.
 *
 * It is the same widget (assets/js/phone-widget.js) in standalone mode
 * (window.PHONE_STANDALONE): always visible, fills the window, and outranks
 * every other window of the browser in the single-registrant election.
 *
 * Gate: screen.call_queue -- the existing "works the phone" permission the
 * navbar phone button already uses. No new permission.
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

$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';

if (!rbac_can('screen.call_queue')) {
    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Phone — TicketsCAD</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-4" style="max-width: 420px;">
    <div class="alert alert-warning small">
        <h6 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h6>
        <p class="mb-0">The phone needs the "Call Queue" screen permission
           (<code>screen.call_queue</code>). Ask an administrator to grant it to your role.</p>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$csrf = csrf_token();
// Nothing below writes the session; release the lock so the widget's own
// requests (extension lookup, claim) never queue behind this page.
session_write_close();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Phone — TicketsCAD</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/phone-widget.css?v=<?php echo asset_v('assets/css/phone-widget.css'); ?>">
</head>
<body class="phone-standalone-page">
<noscript>
    <div class="alert alert-warning m-2 small">The phone needs JavaScript.</div>
</noscript>

<?php include_once NEWUI_ROOT . '/inc/phone-widget-template.php'; ?>

<script>
window.PHONE_STANDALONE = true;
window.PHONE_CSRF = <?php echo json_encode((string) $csrf); ?>;
</script>
<script src="assets/vendor/jssip/jssip-3.10.1.min.js?v=<?php echo asset_v('assets/vendor/jssip/jssip-3.10.1.min.js'); ?>"></script>
<script src="assets/js/console-workstation.js?v=<?php echo asset_v('assets/js/console-workstation.js'); ?>"></script>
<script src="assets/js/phone-dial-logic.js?v=<?php echo asset_v('assets/js/phone-dial-logic.js'); ?>"></script>
<script src="assets/js/phone-widget.js?v=<?php echo asset_v('assets/js/phone-widget.js'); ?>"></script>
</body>
</html>
