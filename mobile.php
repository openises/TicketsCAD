<?php
/**
 * NewUI v4.0 - Mobile Unit Interface
 *
 * Simplified touch-friendly interface for field responders on phones/tablets.
 * Shows: status buttons, current assignment, quick notes, mileage logger,
 * GPS toggle, and recent assignment history.
 *
 * Any authenticated user can access this page directly, but users with
 * Field Unit role (level 4 / RBAC role 6) are auto-redirected here from login.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/security.php';
require_once __DIR__ . '/inc/i18n.php';

// Phase 104e (a beta tester GH #6, 2026-07-02) — mobile session isolation.
// The Phase 87b fix (commit 9dbc802) only bumped gc_maxlifetime on
// this script, but PHP session GC is process-wide and reaps files
// by mtime regardless of which script wrote them. Desktop pages
// running at default 1440s reaped mobile files too, so the PWA
// re-asked for login even though the cookie was valid. Isolate
// mobile sessions in their own save_path + dedicated cookie name.
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_mobile();
session_start();
sess_touch_mobile_cookie();

// a beta tester GH #13-followup + Eric GH #6 followup (2026-07-03): the
// mobile session TCADMOBILE cookie is empty on a first-visit-to-
// mobile.php, but the client may have a live DESKTOP session
// (PHPSESSID) from a prior desktop login. Read that desktop
// session file directly and copy its auth data into the mobile
// $_SESSION so the auth check below passes.
//
// v2 of the fix: reads the on-disk sess_<id> file via
// sess_read_desktop_session() instead of trying to session_start()
// under a different session_name. The first attempt did the
// session-swap dance but PHP's session module didn't cleanly
// reverse the save_path change between session_starts under
// mod_php/PHP-FPM, so the desktop read failed silently and the
// user still landed at login. Direct file read has no such
// interaction with PHP's session state machine.
if (empty($_SESSION['user_id']) && !empty($_COOKIE['PHPSESSID'])) {
    $desktopSessionId = preg_replace('/[^A-Za-z0-9,\-]/', '', (string) $_COOKIE['PHPSESSID']);
    $desktopSnapshot = sess_read_desktop_session($desktopSessionId);
    if (!empty($desktopSnapshot['user_id'])) {
        // Copy the auth-carrying keys into the current mobile
        // $_SESSION so mobile.php's auth check passes.
        //
        // Eric emergency (2026-07-03) — v3 of this rescue branch
        // ALSO deleted the desktop session file and cleared the
        // PHPSESSID cookie. That caused ERR_TOO_MANY_REDIRECTS the
        // moment the user clicked back to index.php (or any page
        // that calls session_start() directly without going through
        // sess_bootstrap_auto): index.php opens a fresh empty
        // PHPSESSID session, sees no user_id, redirects to
        // login.php. login.php sees the still-live TCADMOBILE
        // session, considers the user "already logged in", and
        // redirects back to index.php. Infinite bounce.
        //
        // Leave BOTH the desktop session file AND the PHPSESSID
        // cookie in place. Two parallel sessions with the same
        // user_id is safe — the logout handler in login.php's
        // ?logout=1 branch already clears both. This is the
        // minimal emergency fix; a proper fix (add
        // sess_bootstrap_auto to every page that calls
        // session_start) is a separate follow-up.
        // a beta tester GH #13 (2026-07-04) — skip the _sm_tracked marker.
        // It belongs to the DESKTOP session id that sm_create_session
        // registered during login; this mobile session has a different
        // id with no active_sessions row. Copying the marker without
        // re-registering makes the first API call's
        // sm_is_session_valid() read "tracked + no row = force-
        // destroyed" and wipe the session ("Session expired" →
        // "Not authenticated" on everything after). Re-register the
        // mobile id below instead.
        foreach ($desktopSnapshot as $k => $v) {
            if ($k === '_sm_tracked') continue;
            $_SESSION[$k] = $v;
        }
        require_once __DIR__ . '/inc/session-manager.php';
        if (function_exists('sm_create_session') && !empty($_SESSION['user_id'])) {
            sm_create_session((int) $_SESSION['user_id']);
        }
        sess_touch_mobile_cookie();
    }
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


$user     = e($_SESSION['user']);
$level    = (int) ($_SESSION['level'] ?? 99);
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.mobile', 'Mobile Unit View')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?></title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- Mobile CSS -->
    <link rel="stylesheet" href="assets/css/mobile-unit.css?v=<?php echo asset_v('assets/css/mobile-unit.css'); ?>">
</head>
<body>

    <!--
        Phase 16c (2026-06-11) — PAR floating banner. Non-modal. Hidden
        by default; mobile.js shows it when api/par.php reports a
        pending cycle for the unit this user is assigned to. Tapping
        the banner opens the comment-and-ack inline form below it.
    -->
    <div class="par-banner d-none" id="parBanner">
        <div class="par-banner-row" id="parBannerRow">
            <div class="par-banner-icon"><i class="bi bi-shield-exclamation"></i></div>
            <div class="par-banner-text">
                <div class="par-banner-title">PAR check in progress</div>
                <div class="par-banner-sub">
                    <span id="parBannerUnitName">—</span> ·
                    <span id="parBannerCountdown">—</span>
                </div>
            </div>
            <button type="button" class="par-banner-ack" id="parBannerAckBtn">
                <i class="bi bi-check-circle me-1"></i>Ack
            </button>
        </div>
        <div class="par-banner-form d-none" id="parBannerForm">
            <div class="row g-2 px-2 pb-2">
                <div class="col-4">
                    <label class="form-label form-label-sm small mb-0">Members</label>
                    <input type="number" min="1" class="form-control form-control-sm"
                           id="parBannerMembers" placeholder="#">
                </div>
                <div class="col-8">
                    <label class="form-label form-label-sm small mb-0">Comments</label>
                    <textarea class="form-control form-control-sm" id="parBannerComments"
                              rows="2" maxlength="1024"
                              placeholder="Needs, activity, conditions…"></textarea>
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="parBannerCancelBtn">
                        Cancel
                    </button>
                    <button type="button" class="btn btn-sm btn-success" id="parBannerConfirmBtn">
                        <i class="bi bi-send me-1"></i>Send ack
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TOP BAR ═══════════ -->
    <header class="mobile-header">
        <div class="d-flex align-items-center justify-content-between px-3 py-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-broadcast-pin text-primary fs-5"></i>
                <span class="fw-semibold">Tickets</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="font-monospace small" id="mobileClock">00:00</span>
                <span class="badge rounded-pill" id="mobileStatusBadge">--</span>
            </div>
        </div>
    </header>

    <!-- ═══════════ MAIN CONTENT ═══════════ -->
    <main class="mobile-main">

        <!-- Phase 56 — personal-resource clock-in. Top of mobile.php so
             a field volunteer arriving on scene can self-activate in
             one tap, without having to navigate to Profile. Status loads
             on page show; tap toggles. -->
        <div class="mobile-card" id="puCard" style="background: var(--bs-body-tertiary-bg)">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div class="flex-grow-1">
                    <div class="fw-semibold small">
                        <i class="bi bi-person-fill-check me-1"></i>Personal Resource
                        <span class="badge ms-2" id="puBadge">…</span>
                    </div>
                    <small class="text-body-secondary" id="puStatusLine">Checking status…</small>
                </div>
                <button type="button" class="btn btn-success btn-lg d-none" id="btnClockIn">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Clock In
                </button>
                <button type="button" class="btn btn-warning btn-lg d-none" id="btnClockOut">
                    <i class="bi bi-box-arrow-right me-1"></i>Clock Out
                </button>
            </div>
        </div>

        <!-- User & Unit Info -->
        <div class="mobile-card" id="unitInfoCard">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="fw-bold" id="unitName">Loading...</div>
                    <small class="text-body-secondary" id="unitHandle"><?php echo $user; ?></small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefresh" title="Refresh">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
        </div>

        <!-- ── Status Buttons ──────────────────────────────────── -->
        <div class="mobile-section-label">Status</div>
        <div class="mobile-card">
            <div class="status-grid" id="statusGrid">
                <!-- Populated by JS -->
                <div class="text-center text-body-secondary p-3">Loading statuses...</div>
            </div>
        </div>

        <!-- ── Current Assignment ──────────────────────────────── -->
        <div class="mobile-section-label">Current Assignment</div>
        <div class="mobile-card" id="assignmentCard">
            <div class="text-center text-body-secondary p-3" id="noAssignment">
                <i class="bi bi-clipboard-check fs-3 d-block mb-1"></i>
                No active assignment
            </div>
            <div class="d-none" id="assignmentDetail">
                <div class="mb-2">
                    <span class="badge mb-1" id="assignType">--</span>
                    <div class="fw-bold" id="assignNature">--</div>
                </div>
                <div class="small text-body-secondary mb-2" id="assignAddress">--</div>
                <div class="small mb-2" id="assignDesc"></div>
                <!-- Phase 71 — big tap-friendly Navigate button.
                     Previously a tiny corner icon nobody could hit with
                     gloves. Now full-width, opens the multi-app
                     chooser (Apple Maps / Google Maps / Waze /
                     browser). Long-press re-opens the chooser to
                     switch apps. -->
                <button type="button" class="btn btn-success btn-lg w-100 d-none mb-2" id="assignNavBtn">
                    <i class="bi bi-signpost-2-fill me-1"></i> Navigate to Scene
                </button>
                <a href="#" class="btn btn-sm btn-outline-secondary d-none" id="assignMapLink" target="_blank" title="Navigate (fallback)">
                    <i class="bi bi-geo-alt-fill"></i>
                </a>

                <!-- Phase 104i (a beta tester GH #14) — partial parity with
                     the desktop incident-detail. Rather than shove every
                     card in (which blows up the mobile IA), let the user
                     choose which optional sections to expose via three
                     toggle switches. State is saved in localStorage so
                     each field responder can shape their own view.
                     Data comes from api/incident-detail.php on demand
                     when at least one section is enabled. -->
                <div class="mt-3 pt-3 border-top">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <div class="small fw-semibold">More detail</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="mobileDetailTogglesBtn"
                                title="Choose which extra sections to show">
                            <i class="bi bi-sliders"></i>
                        </button>
                    </div>
                    <div class="d-none" id="mobileDetailToggles">
                        <div class="form-check form-switch small mb-1">
                            <input class="form-check-input" type="checkbox" id="mobileShowFacilities">
                            <label class="form-check-label" for="mobileShowFacilities">Facilities</label>
                        </div>
                        <div class="form-check form-switch small mb-1">
                            <input class="form-check-input" type="checkbox" id="mobileShowPatients">
                            <label class="form-check-label" for="mobileShowPatients">Patients</label>
                        </div>
                        <div class="form-check form-switch small mb-1">
                            <input class="form-check-input" type="checkbox" id="mobileShowCallHistory">
                            <label class="form-check-label" for="mobileShowCallHistory">Call history</label>
                        </div>
                        <div class="form-check form-switch small mb-1">
                            <input class="form-check-input" type="checkbox" id="mobileShowActions">
                            <label class="form-check-label" for="mobileShowActions">Action log (full chronology)</label>
                        </div>
                        <div class="form-check form-switch small mb-1">
                            <input class="form-check-input" type="checkbox" id="mobileShowNotes">
                            <label class="form-check-label" for="mobileShowNotes">Notes (dispatcher &amp; responder)</label>
                        </div>
                    </div>
                    <div id="mobileDetailFacilities" class="mt-2 d-none"></div>
                    <div id="mobileDetailPatients"  class="mt-2 d-none"></div>
                    <div id="mobileDetailCallHist"  class="mt-2 d-none"></div>
                    <div id="mobileDetailActions"   class="mt-2 d-none"></div>
                    <div id="mobileDetailNotes"     class="mt-2 d-none"></div>
                </div>

                <!-- Quick Note -->
                <div class="mt-3 pt-3 border-top">
                    <div class="input-group">
                        <input type="text" class="form-control" id="quickNoteInput"
                               placeholder="Add a note..." maxlength="500">
                        <button class="btn btn-primary" type="button" id="btnAddNote">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Mileage Logger ──────────────────────────────────── -->
        <div class="mobile-section-label">Mileage</div>
        <div class="mobile-card" id="mileageCard">
            <!-- Start trip form -->
            <div id="mileageStartForm">
                <div class="mb-2">
                    <label class="form-label small mb-1">Starting Odometer</label>
                    <input type="number" class="form-control" id="startOdoInput"
                           placeholder="e.g. 45230" step="0.1" inputmode="decimal">
                </div>
                <button type="button" class="btn btn-success btn-mobile w-100" id="btnStartMileage">
                    <i class="bi bi-play-fill me-1"></i> Start Trip
                </button>
            </div>

            <!-- Active trip display -->
            <div class="d-none" id="mileageActive">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div>
                        <div class="small text-body-secondary">Trip started</div>
                        <div class="fw-bold" id="mileageStartTime">--</div>
                    </div>
                    <div class="text-end">
                        <div class="small text-body-secondary">Start ODO</div>
                        <div class="fw-bold" id="mileageStartOdo">--</div>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1">Ending Odometer</label>
                    <input type="number" class="form-control" id="endOdoInput"
                           placeholder="e.g. 45248" step="0.1" inputmode="decimal">
                </div>
                <button type="button" class="btn btn-danger btn-mobile w-100" id="btnStopMileage">
                    <i class="bi bi-stop-fill me-1"></i> End Trip
                </button>
            </div>
        </div>

        <!-- ── GPS Location ────────────────────────────────────── -->
        <div class="mobile-section-label">Location</div>
        <div class="mobile-card">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="fw-bold">GPS Sharing</div>
                    <small class="text-body-secondary" id="gpsStatus">Off</small>
                </div>
                <div class="form-check form-switch form-switch-lg">
                    <input class="form-check-input" type="checkbox" role="switch" id="gpsToggle" style="width:3rem;height:1.5rem;">
                </div>
            </div>
            <div class="small text-body-secondary mt-1 d-none" id="gpsCoords"></div>
        </div>

        <!-- ── Recent Assignments ──────────────────────────────── -->
        <div class="mobile-section-label">Recent Assignments</div>
        <div class="mobile-card" id="recentCard">
            <div class="text-center text-body-secondary p-2" id="noRecent">
                No recent assignments
            </div>
            <div id="recentList"></div>
        </div>

        <!-- ── Navigation ──────────────────────────────────────── -->
        <div class="mobile-card mt-3 mb-4">
            <div class="d-grid gap-2">
                <?php if ($level <= 2): ?>
                <a href="index.php" class="btn btn-outline-secondary btn-mobile">
                    <i class="bi bi-grid me-2"></i> Full Dashboard
                </a>
                <?php endif; ?>
                <a href="login.php?logout=1" class="btn btn-outline-danger btn-mobile">
                    <i class="bi bi-box-arrow-right me-2"></i> Log Out
                </a>
            </div>
        </div>

    </main>

    <!-- ═══════════ STATUS TOAST ═══════════ -->
    <div class="position-fixed bottom-0 start-50 translate-middle-x p-3" style="z-index:1090">
        <div class="toast align-items-center border-0" role="alert" id="mobileToast">
            <div class="d-flex">
                <div class="toast-body" id="mobileToastBody">--</div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>

    <!-- Hidden data -->
    <input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

    <!-- Vendor JS -->
    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

    <!-- Phase 104h (a beta tester GH #13) — EventBus + SSE so mobile.js can
         subscribe to server-side events and refresh without an
         interval poll. -->
    <script src="assets/js/event-bus.js?v=<?php echo NEWUI_VERSION; ?>"></script>

    <!-- Phase 71 — navigation launcher (Apple Maps / Google Maps / Waze / web). -->
    <script src="assets/js/navigate-launcher.js?v=<?php echo asset_v('assets/js/navigate-launcher.js'); ?>"></script>

    <!-- Mobile JS -->
    <?php
    // Eric 2026-07-08 — the Internal GPS provider's update_interval /
    // high_accuracy settings existed in its config_json but the mobile
    // page hardcoded 30 s + high accuracy, so editing the setting did
    // nothing. Embed the configured values (interval clamped 5–600 s so
    // a typo can't flood the server or strand a unit as permanently
    // stale).
    $gpsIntervalSec = 30;
    $gpsHighAccuracy = true;
    try {
        $cfgJson = db_fetch_value(
            "SELECT `config_json` FROM `" . ($GLOBALS['db_prefix'] ?? '') . "location_providers`
              WHERE `code` = 'internal' LIMIT 1"
        );
        $cfg = $cfgJson ? json_decode((string) $cfgJson, true) : null;
        if (is_array($cfg)) {
            if (isset($cfg['update_interval'])) {
                $gpsIntervalSec = max(5, min(600, (int) $cfg['update_interval']));
            }
            if (array_key_exists('high_accuracy', $cfg)) {
                $gpsHighAccuracy = !empty($cfg['high_accuracy']);
            }
        }
    } catch (Exception $e) { /* provider row absent — keep defaults */ }
    ?>
    <script>
    window.MOBILE_GPS = {
        intervalMs: <?php echo $gpsIntervalSec * 1000; ?>,
        highAccuracy: <?php echo $gpsHighAccuracy ? 'true' : 'false'; ?>
    };
    </script>
    <script src="assets/js/mobile.js?v=<?php echo asset_v('assets/js/mobile.js'); ?>"></script>

    <!-- Phase 56 — personal-resource clock-in (mobile-prominent) -->
    <script>
    (function () {
        'use strict';
        function $(id) { return document.getElementById(id); }
        var badge = $('puBadge'), line = $('puStatusLine'),
            inBtn = $('btnClockIn'), outBtn = $('btnClockOut');
        if (!badge || !inBtn || !outBtn) return;
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

        function paint(s) {
            if (!s) return;
            if (s.error) {
                badge.textContent = 'err'; badge.className = 'badge bg-danger ms-2';
                line.textContent = s.error;
                inBtn.classList.add('d-none'); outBtn.classList.add('d-none');
                return;
            }
            if (s.can_self_clock === false) {
                // Phase 57 — RBAC gate. Hide the whole card on mobile
                // since field volunteers without the perm have nothing
                // to do here.
                var card = document.getElementById('puCard');
                if (card) card.style.display = 'none';
                return;
            }
            if (s.clocked_in) {
                badge.textContent = 'CLOCKED IN';
                badge.className = 'badge bg-success ms-2';
                line.innerHTML = '<strong>' + (s.unit_handle || s.unit_name || '?') + '</strong> — assignable to incidents';
                inBtn.classList.add('d-none'); outBtn.classList.remove('d-none');
            } else if (s.exists) {
                badge.textContent = 'OFF';
                badge.className = 'badge bg-secondary ms-2';
                line.innerHTML = 'Unit <strong>' + (s.unit_handle || s.unit_name || '?') + '</strong> ready';
                inBtn.classList.remove('d-none'); outBtn.classList.add('d-none');
            } else {
                badge.textContent = 'NEW';
                badge.className = 'badge bg-info text-dark ms-2';
                line.textContent = 'Clocking in creates your personal unit.';
                inBtn.classList.remove('d-none'); outBtn.classList.add('d-none');
            }
        }
        function load() {
            fetch('api/personal-unit.php?action=status', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(paint)
                .catch(function () { paint({ error: 'Status load failed' }); });
        }
        function send(action) {
            inBtn.disabled = outBtn.disabled = true;
            fetch('api/personal-unit.php?action=' + action, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrf })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                inBtn.disabled = outBtn.disabled = false;
                if (d.error) { paint({ error: d.error }); return; }
                paint(d.status || d);
            })
            .catch(function () { inBtn.disabled = outBtn.disabled = false; paint({ error: 'Request failed' }); });
        }
        inBtn.addEventListener('click', function () { send('clock_in'); });
        outBtn.addEventListener('click', function () { send('clock_out'); });
        load();
    })();
    </script>
</body>
</html>
