<?php
/**
 * NewUI v4.0 - Internal Messaging & HAS Broadcast
 *
 * Three-tab interface: Inbox, Sent, Compose.
 * Supports direct messages, multi-recipient, and "All" broadcast.
 * Unread count badge updates via SSE events.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

// 2026-07-04 (GH #13) — pick the session profile matching the
// client's cookie (TCADMOBILE vs PHPSESSID). Without this, a
// browser holding a mobile cookie opens an empty desktop session
// here and bounces to login -> redirect loop.
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();

$active_page = 'messaging';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo $csrf; ?>">
    <title><?php echo e(t('page.messaging', 'Messaging')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/messaging.css?v=<?php echo asset_v('assets/css/messaging.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-envelope text-primary me-2"></i><?php echo e(t('nav.menu.messages', 'Messages')); ?>
        </h5>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
            </a>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-3" id="msgTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-inbox" data-bs-toggle="tab"
                    data-bs-target="#pane-inbox" type="button" role="tab"
                    aria-controls="pane-inbox" aria-selected="true">
                <i class="bi bi-inbox me-1"></i>Inbox
                <span class="badge bg-danger rounded-pill ms-1 d-none" id="inboxBadge">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-sent" data-bs-toggle="tab"
                    data-bs-target="#pane-sent" type="button" role="tab"
                    aria-controls="pane-sent" aria-selected="false">
                <i class="bi bi-send me-1"></i>Sent
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-compose" data-bs-toggle="tab"
                    data-bs-target="#pane-compose" type="button" role="tab"
                    aria-controls="pane-compose" aria-selected="false">
                <i class="bi bi-pencil-square me-1"></i>Compose
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="msgTabContent">

        <!-- ═══ INBOX TAB ═══ -->
        <div class="tab-pane fade show active" id="pane-inbox" role="tabpanel" aria-labelledby="tab-inbox">
            <!-- Bulk actions -->
            <div class="d-flex align-items-center gap-2 mb-2">
                <div class="form-check form-check-sm">
                    <input class="form-check-input" type="checkbox" id="inboxSelectAll" title="Select all">
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary d-none" id="inboxBulkMarkRead" title="Mark selected as read">
                    <i class="bi bi-envelope-open me-1"></i>Mark Read
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger d-none" id="inboxBulkDelete" title="Delete selected">
                    <i class="bi bi-trash me-1"></i>Delete Selected
                </button>
                <span class="text-body-secondary small ms-auto" id="inboxInfo"></span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" id="inboxTable">
                    <thead>
                        <tr>
                            <th style="width:30px"></th>
                            <th style="width:22px"></th>
                            <th style="width:140px">From</th>
                            <th>Subject</th>
                            <th style="width:80px" class="text-center">Priority</th>
                            <th style="width:150px">Date</th>
                        </tr>
                    </thead>
                    <tbody id="inboxBody">
                        <tr><td colspan="6" class="text-center text-body-secondary py-4">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="inboxPagination" class="d-flex justify-content-center mt-2"></div>
        </div>

        <!-- ═══ SENT TAB ═══ -->
        <div class="tab-pane fade" id="pane-sent" role="tabpanel" aria-labelledby="tab-sent">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" id="sentTable">
                    <thead>
                        <tr>
                            <th style="width:200px">To</th>
                            <th>Subject</th>
                            <th style="width:80px" class="text-center">Priority</th>
                            <th style="width:150px">Date</th>
                        </tr>
                    </thead>
                    <tbody id="sentBody">
                        <tr><td colspan="4" class="text-center text-body-secondary py-4">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="sentPagination" class="d-flex justify-content-center mt-2"></div>
        </div>

        <!-- ═══ COMPOSE TAB ═══ -->
        <div class="tab-pane fade" id="pane-compose" role="tabpanel" aria-labelledby="tab-compose">
            <div class="card">
                <div class="card-body">
                    <form id="composeForm" autocomplete="off">
                        <div class="row g-3">
                            <!-- Phase 99a-v2 (2026-06-28) — restructured per Eric beta:
                                 dynamic per-channel fields, target-bridge picker for
                                 mesh, two-stage Send-To, hide-irrelevant-fields.
                                 The form reshapes as the user picks Channel / Send-To. -->
                            <div class="col-md-4">
                                <label class="form-label small text-body-secondary mb-1">Protocol / Channel</label>
                                <select class="form-select form-select-sm" id="composeChannel">
                                    <option value="inbox" selected>TicketsCAD Inbox (internal)</option>
                                    <option value="smtp">Email (SMTP)</option>
                                    <option value="sms">SMS</option>
                                    <option value="meshtastic">Meshtastic (mesh radio)</option>
                                    <option value="meshcore">MeshCore (mesh radio)</option>
                                    <option value="aprs">APRS-IS (amateur radio)</option>
                                    <option value="dmr">DMR (digital radio)</option>
                                </select>
                                <div class="form-text" id="composeChannelHelp">
                                    Internal — message lands in recipient's TicketsCAD inbox.
                                </div>
                            </div>

                            <!-- Target bridge — mesh channels only. Defaults to the
                                 bridge most recently online OR (when a target node is
                                 chosen) the bridge that most recently heard that node. -->
                            <div class="col-md-4" id="composeBridgeCol" style="display:none;">
                                <label class="form-label small text-body-secondary mb-1">
                                    Target bridge
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-html="true"
                                       data-bs-content="Which mesh bridge should claim this send. <b>Any</b> lets the system pick the bridge that most recently heard the target node (or the most-recently-online bridge for a broadcast). Pick a specific bridge to force routing through it (useful when one bridge is having issues)."
                                       title="Bridge picker help"></i>
                                </label>
                                <select class="form-select form-select-sm" id="composeBridge">
                                    <option value="0">Any (heard-by default)</option>
                                </select>
                            </div>

                            <!-- Send To — second-level discriminator. Options reshape
                                 per channel. Mesh: Broadcast slot / Direct node / Direct
                                 unit. SMTP+SMS: Direct email/phone. APRS: Direct callsign. -->
                            <div class="col-md-4" id="composeSendToCol" style="display:none;">
                                <label class="form-label small text-body-secondary mb-1">Send to</label>
                                <select class="form-select form-select-sm" id="composeSendTo">
                                    <!-- options populated by JS per channel -->
                                </select>
                            </div>

                            <!-- ── Recipient row — exactly one of these is visible at a time -->
                            <div class="col-12" id="composeRecipRow">
                                <!-- Inbox: user multi-select -->
                                <div id="composeRecipInbox">
                                    <label class="form-label small text-body-secondary mb-1">To</label>
                                    <select class="form-select form-select-sm" id="composeTo" multiple size="4">
                                        <option value="all">-- All Users (Broadcast) --</option>
                                    </select>
                                    <div class="form-text">Hold Ctrl to select multiple recipients. Select "All Users" to message everyone.</div>
                                </div>

                                <!-- Channel slot (mesh broadcast / zello channel name) -->
                                <div id="composeRecipChannel" style="display:none;">
                                    <label class="form-label small text-body-secondary mb-1" id="composeRecipChannelLabel">Channel</label>
                                    <select class="form-select form-select-sm" id="composeChannelSlot">
                                        <option value="channel:0">Slot 0 (Primary)</option>
                                        <option value="channel:1">Slot 1</option>
                                        <option value="channel:2">Slot 2</option>
                                        <option value="channel:3">Slot 3</option>
                                        <option value="channel:4">Slot 4</option>
                                        <option value="channel:5">Slot 5</option>
                                        <option value="channel:6">Slot 6</option>
                                        <option value="channel:7">Slot 7</option>
                                    </select>
                                </div>

                                <!-- Text address (SMTP / SMS / APRS / direct mesh node) +
                                     personnel/node picker autocomplete -->
                                <div id="composeRecipText" style="display:none;">
                                    <label class="form-label small text-body-secondary mb-1" id="composeRecipTextLabel">To</label>
                                    <div class="position-relative">
                                        <input type="text" class="form-control form-control-sm" id="composeToText"
                                               placeholder="" autocomplete="off">
                                        <div id="composeToDropdown" class="list-group position-absolute w-100 shadow-sm"
                                             style="display:none; z-index:1050; max-height:240px; overflow-y:auto;"></div>
                                    </div>
                                    <div class="form-text" id="composeRecipTextHelp">
                                        Separate multiple recipients with commas. Start typing to search.
                                    </div>
                                </div>

                                <!-- DMR talkgroup picker — appears when channel=dmr AND
                                     sendTo=talkgroup. Populated from /api/talkgroups.php?enabled=1.
                                     Each option shows "TG XXXX — Name [call_type]". -->
                                <div id="composeRecipTalkgroup" style="display:none;">
                                    <label class="form-label small text-body-secondary mb-1">Talkgroup</label>
                                    <select class="form-select form-select-sm" id="composeTalkgroup">
                                        <option value="">— loading talkgroups —</option>
                                    </select>
                                    <div class="form-text" id="composeTalkgroupHelp">
                                        Enabled talkgroups from Settings → Communications → Voice → DMR Talkgroups.
                                    </div>
                                </div>

                                <!-- Unit / personnel picker (future: resolves to channel-
                                     appropriate address via comm_identifiers) -->
                                <div id="composeRecipUnit" style="display:none;">
                                    <label class="form-label small text-body-secondary mb-1">To unit / person</label>
                                    <select class="form-select form-select-sm" id="composeUnitPick">
                                        <option value="">— select —</option>
                                    </select>
                                    <div class="form-text" id="composeRecipUnitHelp">
                                        Direct messages to a TicketsCAD personnel record — resolved to the channel-appropriate address.
                                    </div>
                                </div>
                            </div>

                            <!-- Speak on channel (TTS) — only for channels that support
                                 voice (Zello, DMR — DMR currently future). Hidden for
                                 the rest. -->
                            <div class="col-md-4" id="composeSpeakCol" style="display:none;">
                                <label class="form-label small text-body-secondary mb-1">&nbsp;</label>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="composeSpeak">
                                    <label class="form-check-label small" for="composeSpeak">
                                        <i class="bi bi-megaphone me-1"></i>Speak on channel (TTS audio)
                                        <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                           data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                           data-bs-content="When checked, the system synthesizes the message body to voice and transmits it as audio on the channel. Available for Zello and DMR (voice-supporting channels)."
                                           title="Speak help"></i>
                                    </label>
                                </div>
                            </div>

                            <!-- Priority — only meaningful for inbox/SMTP/SMS where
                                 high-priority is a deliverable header or visible
                                 prefix. Hidden for mesh/APRS where it has no effect. -->
                            <div class="col-md-3" id="composePriorityCol">
                                <label class="form-label small text-body-secondary mb-1">Priority</label>
                                <select class="form-select form-select-sm" id="composePriority">
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>

                            <!-- Send As — SMTP only (when user has personal email). -->
                            <div class="col-md-3" id="composeSendAsCol" style="display:none;">
                                <label class="form-label small text-body-secondary mb-1">
                                    Send As
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-html="true"
                                       data-bs-content="<b>Dispatch (system)</b> — message from the org's configured SMTP sender.<br><br><b>As yourself</b> — message from your personal email address. Useful when liability lives with you personally."
                                       title="Send As help"></i>
                                </label>
                                <select class="form-select form-select-sm" id="composeSendAs">
                                    <option value="system">Dispatch (system)</option>
                                </select>
                            </div>

                            <!-- Subject — only shown for channels that have subjects
                                 (inbox + smtp). Hidden for SMS where it doesn't apply. -->
                            <div class="col-12" id="composeSubjectCol">
                                <label class="form-label small text-body-secondary mb-1">Subject</label>
                                <input type="text" class="form-control form-control-sm" id="composeSubject"
                                       placeholder="Subject (optional)" maxlength="255">
                            </div>

                            <!-- Attach to incident — applies to inbox only.
                                 External channels don't have a clean way to
                                 link delivered messages back to incidents. -->
                            <div class="col-md-4" id="composeIncidentCol">
                                <label class="form-label small text-body-secondary mb-1">Attach to Incident #</label>
                                <input type="number" class="form-control form-control-sm" id="composeIncident"
                                       placeholder="(optional)" min="1">
                            </div>

                            <!-- Body. Char counter shown for length-limited
                                 channels (SMS 160) — hidden otherwise. -->
                            <div class="col-12">
                                <label class="form-label small text-body-secondary mb-1 d-flex justify-content-between">
                                    <span>Message</span>
                                    <span class="text-body-secondary small font-monospace" id="composeCharCounter" style="display:none;">0/160</span>
                                </label>
                                <textarea class="form-control form-control-sm" id="composeBody" rows="8"
                                          placeholder="Type your message here..."></textarea>
                            </div>

                            <!-- Send -->
                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="composeClear">
                                    <i class="bi bi-x-lg me-1"></i>Clear
                                </button>
                                <button type="submit" class="btn btn-sm btn-primary" id="composeSend">
                                    <i class="bi bi-send me-1"></i>Send Message
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->
</div><!-- /container -->

<!-- ═══ Message Detail Modal ═══ -->
<div class="modal fade" id="msgDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="msgDetailTitle">Message</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3 small text-body-secondary">
                    <div class="col-sm-6"><strong>From:</strong> <span id="msgDetailFrom"></span></div>
                    <div class="col-sm-6"><strong>Date:</strong> <span id="msgDetailDate"></span></div>
                    <div class="col-sm-6"><strong>To:</strong> <span id="msgDetailTo"></span></div>
                    <div class="col-sm-3"><strong>Priority:</strong> <span id="msgDetailPriority"></span></div>
                    <div class="col-sm-3"><strong>Incident:</strong> <span id="msgDetailIncident"></span></div>
                </div>
                <hr class="my-2">
                <div id="msgDetailBody" class="msg-detail-body"></div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-sm btn-outline-primary" id="msgReplyBtn">
                    <i class="bi bi-reply me-1"></i>Reply
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" id="msgDeleteBtn">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ HAS Broadcast Modal ═══ -->
<div class="modal fade" id="hasBroadcastModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title"><i class="bi bi-megaphone-fill me-2"></i>HAS Broadcast — All Stations</h6>
                <button type="button" class="btn-close btn-close-white btn-close-sm" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    This will send an <strong>URGENT</strong> message to ALL logged-in users and trigger an audio alert on every station.
                </div>
                <div class="mb-3">
                    <label class="form-label small text-body-secondary mb-1">Subject</label>
                    <input type="text" class="form-control form-control-sm" id="hasSubject" value="HAS Broadcast" maxlength="255">
                </div>
                <div>
                    <label class="form-label small text-body-secondary mb-1">Message</label>
                    <textarea class="form-control form-control-sm" id="hasBody" rows="4"
                              placeholder="Enter broadcast message..."></textarea>
                </div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="hasSendBtn">
                    <i class="bi bi-megaphone-fill me-1"></i>Broadcast to All Stations
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ HAS Alert Toast (for incoming broadcasts) ═══ -->
<div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:9999" id="hasAlertWrap">
    <div class="toast has-alert-toast border-danger" role="alert" aria-live="assertive" aria-atomic="true"
         data-bs-autohide="false" id="hasAlertToast">
        <div class="toast-header bg-danger text-white">
            <i class="bi bi-megaphone-fill me-2"></i>
            <strong class="me-auto">HAS BROADCAST</strong>
            <small id="hasAlertTime"></small>
            <button type="button" class="btn-close btn-close-white btn-close-sm ms-2" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body" id="hasAlertBody"></div>
    </div>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- App JS -->
<script>
    var USER_ID = <?php echo (int) $_SESSION['user_id']; ?>;
    var CSRF_TOKEN = '<?php echo $csrf; ?>';
</script>
<script src="assets/js/event-bus.js?v=<?php echo asset_v('assets/js/event-bus.js'); ?>"></script>
<script src="assets/js/audio-alerts.js?v=<?php echo asset_v('assets/js/audio-alerts.js'); ?>"></script>
<script src="assets/js/messaging.js?v=<?php echo asset_v('assets/js/messaging.js'); ?>"></script>

</body>
</html>
