<?php
/**
 * Shared Access-Denied page (specs/rbac-enforcement-2026-06).
 * Rendered by rbac_require_screen() when the session user's role does not
 * permit the requested screen. Themed to match the app (Bootstrap 5,
 * light/dark) so it isn't the jarring bare-HTML page it replaces.
 *
 * $GLOBALS['__denied_perm'] holds the permission code that was required
 * (shown subtly for admins/debugging; harmless to end users).
 */
$bs_theme = (isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark') ? 'dark' : 'light';
$deniedPerm = (string) ($GLOBALS['__denied_perm'] ?? '');
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
?><!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Denied — TicketsCAD</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card shadow-sm text-center" style="max-width:460px;">
        <div class="card-body p-4 p-md-5">
            <i class="bi bi-shield-lock text-secondary" style="font-size:3rem;" aria-hidden="true"></i>
            <h1 class="h4 mt-3 mb-2">Access Denied</h1>
            <p class="text-secondary mb-4">
                Your role doesn&rsquo;t permit this screen. If you believe you should
                have access, ask an administrator to update your role.
            </p>
            <a href="index.php" class="btn btn-primary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Dashboard
            </a>
            <?php if ($deniedPerm !== ''): ?>
            <div class="mt-4"><small class="text-secondary opacity-50">Required permission: <code><?php echo e($deniedPerm); ?></code></small></div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
