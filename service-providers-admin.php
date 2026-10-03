<?php
/**
 * NewUI v4.0 -- Service Providers admin: towing / roadside companies and their rotation lists (GH#148, Phase 155).
 *
 * Standalone admin page (same precedent as matrix-admin.php / org-routing-admin.php): CRUD for the companies the agency
 * CALLS (vendor_providers), the ordered rotation lists they sit on (vendor_rotation_lists / _members), the editable
 * service types (Tow, Lockout, Jumpstart, Tire Change ...), the rotation history with a CSV export, and the settings
 * that decide how the rotation behaves. Backend: api/vendor-admin.php; rules: inc/vendor-admin-write.php.
 * Linked from the Settings sidebar under Resources.
 *
 * Single permission: action.manage_vendors (tier 1, Org Admin or above; structurally withheld from Dispatcher).
 * The page gate and the API gate name the SAME permission, and neither falls back to is_admin(): that fallback
 * (action.manage_config) would hand a narrower-tier permission to anyone holding it. Display-only gating here --
 * every write is re-checked by the API.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';
require_once __DIR__ . '/inc/vendor-dispatch.php';

require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

// Deliberately rbac_can() alone -- see the docblock.
if (!rbac_can('action.manage_vendors')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Service Providers — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Managing towing / roadside companies and rotation lists requires the "Manage Towing / Roadside
           Vendors" permission. Ask an administrator to grant your role <code>action.manage_vendors</code>.</p>
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
$schemaReady = vendor_schema_ready();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('vendor.admin.title', 'Service Providers')); ?> — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/vendor-dispatch.css?v=<?php echo asset_v('assets/css/vendor-dispatch.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1200px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-truck text-primary me-2"></i><?php echo e(t('vendor.admin.title', 'Service Providers')); ?>
            <small class="text-body-secondary fs-6">&mdash; <?php echo e(t('vendor.admin.subtitle', 'towing and roadside companies, rotation lists')); ?></small></h4>
        <a href="documentation/?doc=VENDOR-DISPATCH-GUIDE" class="ms-auto btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
            <i class="bi bi-book me-1"></i>Guide</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

<?php if (!$schemaReady): ?>
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-octagon me-1"></i>
        The towing / roadside tables are missing from this database. Run <code>php sql/run_migrations.php</code> on the
        server, then reload this page.
    </div>
<?php endif; ?>

    <div id="vpToast" class="alert d-none" role="status"></div>

    <p class="text-body-secondary small">
        A <strong>company</strong> here is an outside business your dispatchers <em>call</em> (a tow truck, a locksmith). It
        is not a unit on the board. Every call and its outcome is written to an append-only <strong>ledger</strong>, and
        "who is next" is worked out from that ledger, so the rotation can always be proven afterwards. This software
        records and suggests; your agency owns compliance with its own ordinance.
    </p>

    <ul class="nav nav-tabs vp-tabs mb-3" id="vpTabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="vpTabProviders" data-bs-toggle="tab" data-bs-target="#vpPaneProviders" type="button" role="tab" aria-controls="vpPaneProviders" aria-selected="true"><i class="bi bi-building me-1"></i>Companies</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="vpTabLists" data-bs-toggle="tab" data-bs-target="#vpPaneLists" type="button" role="tab" aria-controls="vpPaneLists" aria-selected="false"><i class="bi bi-list-ol me-1"></i>Rotation Lists</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="vpTabTypes" data-bs-toggle="tab" data-bs-target="#vpPaneTypes" type="button" role="tab" aria-controls="vpPaneTypes" aria-selected="false"><i class="bi bi-tags me-1"></i>Service Types</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="vpTabHistory" data-bs-toggle="tab" data-bs-target="#vpPaneHistory" type="button" role="tab" aria-controls="vpPaneHistory" aria-selected="false"><i class="bi bi-clock-history me-1"></i>History</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="vpTabSettings" data-bs-toggle="tab" data-bs-target="#vpPaneSettings" type="button" role="tab" aria-controls="vpPaneSettings" aria-selected="false"><i class="bi bi-sliders me-1"></i>Settings</button></li>
    </ul>

    <div class="tab-content">
        <!-- ══════════════════ Companies ══════════════════ -->
        <div class="tab-pane fade show active" id="vpPaneProviders" role="tabpanel" aria-labelledby="vpTabProviders" tabindex="0">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-building me-2"></i>Companies we call</span>
                    <button type="button" class="btn btn-sm btn-primary" id="vpBtnNewProvider"><i class="bi bi-plus-lg me-1"></i>New company</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr>
                                <th scope="col">Company</th><th scope="col">Phone</th><th scope="col">Services (from its lists)</th>
                                <th scope="col">Status</th><th scope="col">Organization</th><th scope="col">Updated</th>
                                <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                            </tr></thead>
                            <tbody id="vpProviderRows"><tr><td colspan="7" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════ Rotation lists ══════════════════ -->
        <div class="tab-pane fade" id="vpPaneLists" role="tabpanel" aria-labelledby="vpTabLists" tabindex="0">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-list-ol me-2"></i>Rotation lists</span>
                    <button type="button" class="btn btn-sm btn-primary" id="vpBtnNewList"><i class="bi bi-plus-lg me-1"></i>New list</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr>
                                <th scope="col">List</th><th scope="col">Service</th><th scope="col">Mode</th>
                                <th scope="col" class="text-end">Companies</th><th scope="col">Next up</th>
                                <th scope="col">Status</th><th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                            </tr></thead>
                            <tbody id="vpListRows"><tr><td colspan="7" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mb-3 d-none" id="vpListDetail">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span id="vpListDetailTitle"><i class="bi bi-list-ol me-2"></i>List</span>
                    <span class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="vpBtnEditList"><i class="bi bi-pencil me-1"></i>Edit list</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="vpBtnToggleDefault"><i class="bi bi-star me-1"></i><span id="vpDefaultLabel">Make default</span></button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="vpBtnRetireList"><i class="bi bi-archive me-1"></i>Retire</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="vpBtnDeleteList"><i class="bi bi-trash me-1"></i>Delete</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="vpBtnCloseList" aria-label="Close this list"><i class="bi bi-x-lg"></i></button>
                    </span>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary small py-2" id="vpOrderNote"></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2">
                            <thead><tr>
                                <th scope="col" style="width:2.5rem">#</th><th scope="col">Company</th><th scope="col">Phone</th>
                                <th scope="col">Last turn</th><th scope="col">Status</th>
                                <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                            </tr></thead>
                            <tbody id="vpMemberRows"></tbody>
                        </table>
                    </div>
                    <div class="input-group input-group-sm" style="max-width: 32rem;">
                        <label class="input-group-text" for="vpAddMemberSelect">Add a company</label>
                        <select class="form-select form-select-sm" id="vpAddMemberSelect"></select>
                        <button type="button" class="btn btn-primary" id="vpBtnAddMember"><i class="bi bi-plus-lg me-1"></i>Add</button>
                    </div>
                    <div class="form-text">A company added to a list that already has history goes to the <strong>back</strong> of the rotation.</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════ Service types ══════════════════ -->
        <div class="tab-pane fade" id="vpPaneTypes" role="tabpanel" aria-labelledby="vpTabTypes" tabindex="0">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-tags me-2"></i>Service types</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr>
                                <th scope="col">Code</th><th scope="col">Label</th><th scope="col">Asks for a destination</th>
                                <th scope="col">Active</th><th scope="col" style="width:6rem">Order</th>
                                <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                            </tr></thead>
                            <tbody id="vpTypeRows"></tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer small text-body-secondary">
                    Only a type that "asks for a destination" (a tow) shows the destination field on the dispatch dialog. A type that has
                    been used cannot be deleted; deactivate it instead.
                    <div class="mt-1 d-none" id="vpTypesNote"><i class="bi bi-lock me-1"></i>Service types are shared by every agency on
                        this install, so only a Super Admin can change them.</div>
                </div>
            </div>
        </div>

        <!-- ══════════════════ History ══════════════════ -->
        <div class="tab-pane fade" id="vpPaneHistory" role="tabpanel" aria-labelledby="vpTabHistory" tabindex="0">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-funnel me-2"></i>Rotation history</div>
                <div class="card-body">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3"><label class="form-label form-label-sm mb-0" for="vpHistList">List</label>
                            <select class="form-select form-select-sm" id="vpHistList"></select></div>
                        <div class="col-md-3"><label class="form-label form-label-sm mb-0" for="vpHistProvider">Company</label>
                            <select class="form-select form-select-sm" id="vpHistProvider"></select></div>
                        <div class="col-md-2"><label class="form-label form-label-sm mb-0" for="vpHistFrom">From</label>
                            <input type="date" class="form-control form-control-sm" id="vpHistFrom"></div>
                        <div class="col-md-2"><label class="form-label form-label-sm mb-0" for="vpHistTo">To</label>
                            <input type="date" class="form-control form-control-sm" id="vpHistTo"></div>
                        <div class="col-md-2">
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="vpHistOverrides">
                                <label class="form-check-label" for="vpHistOverrides">Overrides only</label></div>
                        </div>
                    </div>
                    <div class="mt-2 d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-primary" id="vpBtnHistApply"><i class="bi bi-search me-1"></i>Show</button>
                        <a class="btn btn-sm btn-outline-secondary" id="vpBtnHistCsv" href="api/vendor-admin.php?action=history_csv"><i class="bi bi-download me-1"></i>Download CSV</a>
                    </div>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-header">Per company</div>
                <div class="card-body p-0"><div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th scope="col">Company</th><th scope="col" class="text-end">Called</th><th scope="col" class="text-end">Accepted</th>
                            <th scope="col" class="text-end">Declined</th><th scope="col" class="text-end">No answer</th><th scope="col" class="text-end">Unavailable</th>
                            <th scope="col" class="text-end">Overrides</th><th scope="col" class="text-end">Owner requests</th><th scope="col">Last called</th></tr></thead>
                        <tbody id="vpHistSummary"></tbody>
                    </table></div></div>
            </div>
            <div class="card mb-3">
                <div class="card-header">Entries <span class="text-body-secondary small" id="vpHistNote"></span></div>
                <div class="card-body p-0"><div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th scope="col">When</th><th scope="col">Reference</th><th scope="col">List</th><th scope="col">Event</th>
                            <th scope="col">Company</th><th scope="col">How chosen</th><th scope="col">Next up was</th><th scope="col">Reason / detail</th><th scope="col">By</th></tr></thead>
                        <tbody id="vpHistRows"></tbody>
                    </table></div></div>
            </div>
        </div>

        <!-- ══════════════════ Settings ══════════════════ -->
        <div class="tab-pane fade" id="vpPaneSettings" role="tabpanel" aria-labelledby="vpTabSettings" tabindex="0">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-sliders me-2"></i>Towing / roadside dispatch settings</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="vpSetEnabled">
                        <label class="form-check-label" for="vpSetEnabled"><strong>Enable towing / roadside dispatch</strong>
                            <span class="text-body-secondary">&mdash; shows the Tow / Roadside button and card on every incident for roles that hold "Dispatch Towing / Roadside Vendor"</span></label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-0" for="vpSetMode">Rotation mode (default for every list)</label>
                            <select class="form-select form-select-sm" id="vpSetMode">
                                <option value="round_robin">Round robin &mdash; the company called longest ago is next</option>
                                <option value="strict_order">Strict order &mdash; the list order is fixed</option>
                                <option value="manual">Manual &mdash; the screen shows the order, the dispatcher picks</option>
                            </select>
                            <div class="form-text">A list can override this on its own (Rotation Lists &gt; Edit list).</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-0" for="vpSetAdvance">What uses up a company's turn</label>
                            <select class="form-select form-select-sm" id="vpSetAdvance">
                                <option value="any_offer">Any call made to it (a decline or no answer still counts)</option>
                                <option value="accepted_only">Only a call it accepts</option>
                            </select>
                            <div class="form-text">Recorded on each ledger row when it is written, so changing this never rewrites history.</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="vpSetOverride">
                                <label class="form-check-label" for="vpSetOverride">Allow a dispatcher to call a company that is not next in the rotation</label></div>
                            <div class="form-check ms-3"><input class="form-check-input" type="checkbox" id="vpSetReason">
                                <label class="form-check-label" for="vpSetReason">&hellip;and require a reason when that skips an eligible next-up</label></div>
                            <div class="form-text">An override, or a company the driver or owner asked for, never uses up a turn.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-0" for="vpSetDial">Phone numbers in the dispatch dialog</label>
                            <select class="form-select form-select-sm" id="vpSetDial">
                                <option value="off">Show the number and a Log call button (you dial yourself)</option>
                                <option value="tel_link">Call button / link that opens this device's phone app</option>
                                <option value="widget">Call button that dials from the browser phone</option>
                            </select>
                            <div class="form-text">The call is always recorded first, then dialled. Shared with the click-to-dial setting used elsewhere.</div>
                        </div>
                    </div>
                    <div class="mt-3"><button type="button" class="btn btn-primary btn-sm" id="vpBtnSaveSettings"><i class="bi bi-save me-1"></i>Save settings</button></div>
                    <div class="small text-body-secondary mt-2 d-none" id="vpSettingsNote"><i class="bi bi-lock me-1"></i>These settings apply to every agency on
                        this install, so only a Super Admin can change them.</div>
                    <hr>
                    <div class="fw-semibold small mb-1">Before you turn this on</div>
                    <ul class="list-unstyled small mb-0" id="vpChecklist"></ul>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ══════════════════ Provider editor ══════════════════ -->
<div class="modal fade" id="vpProviderModal" tabindex="-1" aria-labelledby="vpProviderTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h6 class="modal-title" id="vpProviderTitle">Company</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body small">
                <div class="alert alert-danger py-2 d-none" id="vpProviderError" role="alert"></div>
                <input type="hidden" id="vpPid" value="0">
                <div class="row g-2">
                    <div class="col-md-8"><label class="form-label mb-0" for="vpPName">Company name</label>
                        <input type="text" class="form-control form-control-sm" id="vpPName" maxlength="120" autocomplete="off"></div>
                    <div class="col-md-4"><label class="form-label mb-0" for="vpPContact">Contact person</label>
                        <input type="text" class="form-control form-control-sm" id="vpPContact" maxlength="64" autocomplete="off"></div>
                    <div class="col-md-4"><label class="form-label mb-0" for="vpPPhone">Phone</label>
                        <input type="tel" class="form-control form-control-sm" id="vpPPhone" maxlength="32" autocomplete="off"></div>
                    <div class="col-md-4"><label class="form-label mb-0" for="vpPPhoneAlt">Alternate phone</label>
                        <input type="tel" class="form-control form-control-sm" id="vpPPhoneAlt" maxlength="32" autocomplete="off"></div>
                    <div class="col-md-4"><label class="form-label mb-0" for="vpPYard">Yard (default destination)</label>
                        <select class="form-select form-select-sm" id="vpPYard"></select></div>
                    <div class="col-md-6"><label class="form-label mb-0" for="vpPArea">Service area</label>
                        <input type="text" class="form-control form-control-sm" id="vpPArea" maxlength="160" autocomplete="off"></div>
                    <div class="col-md-6"><label class="form-label mb-0" for="vpPHours">Hours / availability note</label>
                        <input type="text" class="form-control form-control-sm" id="vpPHours" maxlength="255" autocomplete="off"></div>
                    <div class="col-12"><label class="form-label mb-0" for="vpPNotes">Notes</label>
                        <textarea class="form-control form-control-sm" id="vpPNotes" rows="2" maxlength="4000"></textarea></div>
                    <div class="col-md-6" id="vpPOrgWrap"><label class="form-label mb-0" for="vpPOrg">Organization</label>
                        <select class="form-select form-select-sm" id="vpPOrg"></select>
                        <div class="form-text">Fixed once saved.</div></div>
                    <div class="col-md-6 d-flex align-items-end"><div class="form-check">
                        <input class="form-check-input" type="checkbox" id="vpPActive" checked><label class="form-check-label" for="vpPActive">Active (uncheck to retire)</label></div></div>
                </div>
                <div class="form-text mt-2">A company's services are not set here: they come from the rotation lists it sits on.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="vpBtnSaveProvider"><i class="bi bi-save me-1"></i>Save company</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════ List editor ══════════════════ -->
<div class="modal fade" id="vpListModal" tabindex="-1" aria-labelledby="vpListTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h6 class="modal-title" id="vpListTitle">Rotation list</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body small">
                <div class="alert alert-danger py-2 d-none" id="vpListError" role="alert"></div>
                <input type="hidden" id="vpLid" value="0">
                <div class="mb-2"><label class="form-label mb-0" for="vpLName">List name</label>
                    <input type="text" class="form-control form-control-sm" id="vpLName" maxlength="120" autocomplete="off" placeholder="e.g. Sheriff tow rotation, north county"></div>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label mb-0" for="vpLType">Service</label>
                        <select class="form-select form-select-sm" id="vpLType"></select></div>
                    <div class="col-md-6"><label class="form-label mb-0" for="vpLMode">Rotation mode</label>
                        <select class="form-select form-select-sm" id="vpLMode">
                            <option value="">Use the install-wide setting</option>
                            <option value="round_robin">Round robin</option>
                            <option value="strict_order">Strict order</option>
                            <option value="manual">Manual</option>
                        </select></div>
                </div>
                <div class="my-2"><label class="form-label mb-0" for="vpLDesc">Area / description</label>
                    <input type="text" class="form-control form-control-sm" id="vpLDesc" maxlength="255" autocomplete="off"></div>
                <div class="row g-2">
                    <div class="col-md-6" id="vpLOrgWrap"><label class="form-label mb-0" for="vpLOrg">Organization</label>
                        <select class="form-select form-select-sm" id="vpLOrg"></select><div class="form-text">Fixed once saved.</div></div>
                    <div class="col-md-3"><label class="form-label mb-0" for="vpLSort">Order</label>
                        <input type="number" class="form-control form-control-sm" id="vpLSort" value="0" step="1"></div>
                    <div class="col-md-3 d-flex align-items-end"><div class="form-check">
                        <input class="form-check-input" type="checkbox" id="vpLActive" checked><label class="form-check-label" for="vpLActive">Active</label></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="vpBtnSaveList"><i class="bi bi-save me-1"></i>Save list</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════ Reason / date prompt (suspend, move to end) ══════════════════ -->
<div class="modal fade" id="vpPromptModal" tabindex="-1" aria-labelledby="vpPromptTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h6 class="modal-title" id="vpPromptTitle">Confirm</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body small">
                <p class="mb-2" id="vpPromptMessage"></p>
                <div class="alert alert-danger py-2 d-none" id="vpPromptError" role="alert"></div>
                <div class="mb-2 d-none" id="vpPromptDateWrap"><label class="form-label mb-0" for="vpPromptDate">Until</label>
                    <input type="datetime-local" class="form-control form-control-sm" id="vpPromptDate"></div>
                <div id="vpPromptTextWrap"><label class="form-label mb-0" for="vpPromptText" id="vpPromptTextLabel">Reason</label>
                    <input type="text" class="form-control form-control-sm" id="vpPromptText" maxlength="200" autocomplete="off"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="vpBtnPromptOk">OK</button>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<?php if ($schemaReady): ?>
<script src="assets/js/vendor-admin.js?v=<?php echo asset_v('assets/js/vendor-admin.js'); ?>"></script>
<?php endif; ?>
</body>
</html>
