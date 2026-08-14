<?php
/**
 * Phase 139 (2026-08-14) — Quick Notes.
 *
 * Review/manage notes captured via the /log command bar shortcut. Every
 * note here belongs strictly to the logged-in user — no RBAC screen gate
 * beyond being authenticated, matching profile.php/time-entries.php's
 * self-service pattern, since ownership by user_id IS the access control.
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
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Quick Notes — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo newui_version(); ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/sop.css">
    <link rel="stylesheet" href="assets/css/notes.css?v=<?php echo newui_version(); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<div class="container-fluid p-3">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0"><i class="bi bi-journal-richtext text-primary me-2"></i>Quick Notes</h5>
        <div class="text-body-secondary small">Captured with <code>/log &lt;text&gt;</code> in the command bar. Notes here are private to you.</div>
    </div>

    <div id="alertArea"></div>

    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-plus-lg"></i></span>
                <input type="text" id="newNoteInput" class="form-control form-control-sm" placeholder="Type a note and press Enter…" autocomplete="off" aria-label="Type a new quick note">
            </div>
        </div>
    </div>

    <div class="row g-3">

        <!-- ═══════════ LEFT: Notes list ═══════════ -->
        <div class="col-md-7">
            <div class="card">
                <div class="card-header d-flex align-items-center py-2">
                    <span class="fw-semibold small">Notes</span>
                    <div class="btn-group btn-group-sm ms-auto" role="group" id="notesFilterGroup">
                        <button type="button" class="btn btn-outline-secondary active" data-filter="all">All</button>
                        <button type="button" class="btn btn-outline-secondary" data-filter="open">Open</button>
                        <button type="button" class="btn btn-outline-secondary" data-filter="done">Done</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="notesList" class="list-group list-group-flush"></div>
                    <div id="notesEmpty" class="p-3 text-body-secondary small d-none">
                        No notes yet — type <code>/log &lt;text&gt;</code> in the command bar, or use the box above.
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ RIGHT: Personal wiki tree (drop target) ═══════════ -->
        <div class="col-md-5">
            <div class="card">
                <div class="card-header d-flex align-items-center py-2">
                    <i class="bi bi-diagram-3 me-2"></i>
                    <span class="fw-semibold small">Your Personal Wiki Pages</span>
                    <button type="button" class="btn btn-sm btn-outline-success ms-auto py-0 px-1" id="btnNewWikiPage" title="New personal page">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="card-body p-2">
                    <div class="small text-body-secondary mb-2">
                        Drag a note onto a page below to copy or move its text there.
                    </div>
                    <div id="wikiTree" class="sop-tree"></div>
                    <div id="wikiTreeEmpty" class="text-body-secondary small p-2 d-none">
                        No personal pages yet. Click <i class="bi bi-plus-lg"></i> above, or drop a note and name a new page.
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ═══════════ Send-to modal (incident activity log / ICS-214) ═══════════ -->
<div class="modal fade" id="sendToModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="sendToModalTitle">Send note</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="sendToModalBody"></div>
        </div>
    </div>
</div>

<!-- ═══════════ Wiki drop confirm (copy vs move) ═══════════ -->
<div class="modal fade" id="wikiDropModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Send to <span id="wikiDropPageName"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" id="wikiDropCopyBtn" autofocus>
                        <i class="bi bi-files me-1"></i>Copy (note stays here too)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="wikiDropMoveBtn">
                        <i class="bi bi-arrow-right me-1"></i>Move (removed from your list)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/quick-notes.js?v=<?php echo newui_version(); ?>"></script>
</body>
</html>
