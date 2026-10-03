<?php
/**
 * GH#148 (Phase 155) -- the "Dispatch Towing / Roadside" dialog on incident-detail.php.
 *
 * Included ONLY when vendor_dispatch_enabled='1' and the caller holds action.dispatch_vendor (incident-detail.php
 * decides; with the feature off the page gains nothing, not even a script tag). All dynamic content (company names,
 * contacts, notes, reasons -- every one of them admin- or dispatcher-authored) is written by assets/js/vendor-dispatch.js
 * with .textContent, never .innerHTML; this file is static markup plus t() captions.
 *
 * Reading order == tab order, so the whole dialog is keyboard-reachable; every icon-only control has an aria-label;
 * status is always text plus an icon, never colour alone; queue changes are announced through #vdLive.
 */
?>
<!-- GH#148 — Towing / Roadside dispatch dialog. Opened by #btnVendorDispatch / a card row's Manage button. -->
<div class="modal fade" id="vendorDispatchModal" tabindex="-1" aria-labelledby="vdTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-fullscreen-md-down modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="vdTitle"><i class="bi bi-truck me-2"></i><?php echo e(t('vendor.modal.title', 'Dispatch Towing / Roadside')); ?>
                    <span class="badge text-bg-secondary ms-2 d-none" id="vdRef"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo e(t('vendor.close', 'Close')); ?>"></button>
            </div>
            <div class="modal-body small">
                <div class="alert alert-danger py-2 d-none" id="vdError" role="alert"></div>
                <div class="visually-hidden" id="vdLive" role="status" aria-live="polite"></div>

                <!-- 1. What is needed -->
                <div class="row g-2 mb-2">
                    <div class="col-md-5">
                        <label class="form-label mb-0" for="vdServiceType"><?php echo e(t('vendor.field.service', 'Service needed')); ?></label>
                        <select class="form-select form-select-sm" id="vdServiceType"></select>
                    </div>
                    <div class="col-md-7 d-none" id="vdListWrap">
                        <label class="form-label mb-0" for="vdList"><?php echo e(t('vendor.field.list', 'Rotation list')); ?></label>
                        <select class="form-select form-select-sm" id="vdList"></select>
                    </div>
                </div>
                <div class="form-text d-none mb-2" id="vdNoList"><?php echo e(t('vendor.no_list', 'No rotation list for this service. You can still log a call to any company below.')); ?></div>

                <!-- 2. Calls waiting for an outcome -->
                <div class="border rounded p-2 mb-2 d-none" id="vdPendingWrap">
                    <div class="fw-semibold mb-1"><i class="bi bi-telephone-outbound me-1"></i><?php echo e(t('vendor.pending.title', 'Calls awaiting an outcome')); ?></div>
                    <div id="vdPendingList"></div>
                    <div class="border-top pt-2 mt-2 d-none" id="vdAcceptBox">
                        <label class="form-label mb-0" for="vdAcceptEta"><?php echo e(t('vendor.accept.eta', 'ETA in minutes')); ?></label>
                        <div class="input-group input-group-sm" style="max-width: 18rem;">
                            <input type="number" class="form-control form-control-sm" id="vdAcceptEta" min="0" max="1440" step="1" value="20" inputmode="numeric">
                            <button type="button" class="btn btn-success" id="btnVdAcceptOk"><i class="bi bi-check-lg me-1"></i><?php echo e(t('vendor.accept.ok', 'Accepted')); ?></button>
                            <button type="button" class="btn btn-outline-secondary" id="btnVdAcceptCancel"><?php echo e(t('vendor.cancel', 'Cancel')); ?></button>
                        </div>
                    </div>
                </div>

                <!-- 3. The rotation queue -->
                <div class="mb-2 d-none" id="vdQueueWrap">
                    <div class="d-flex align-items-center mb-1">
                        <div class="fw-semibold"><i class="bi bi-list-ol me-1"></i><?php echo e(t('vendor.queue.title', 'Who to call')); ?></div>
                        <span class="ms-2 text-body-secondary" id="vdQueueMode"></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:2rem">#</th>
                                    <th scope="col"><?php echo e(t('vendor.col.company', 'Company')); ?></th>
                                    <th scope="col"><?php echo e(t('vendor.col.number', 'Number')); ?></th>
                                    <th scope="col"><?php echo e(t('vendor.col.status', 'Status')); ?></th>
                                    <th scope="col" class="text-end"><span class="visually-hidden"><?php echo e(t('vendor.col.action', 'Action')); ?></span></th>
                                </tr>
                            </thead>
                            <tbody id="vdQueueBody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- 4. Override reason (only when a pick skips an eligible next-up) -->
                <div class="border border-warning rounded p-2 mb-2 d-none" id="vdReasonBox" role="group" aria-labelledby="vdReasonLabel">
                    <div class="fw-semibold mb-1" id="vdReasonLabel"><i class="bi bi-exclamation-triangle me-1"></i><?php echo e(t('vendor.reason.title', 'Why skip the company that is next?')); ?></div>
                    <div id="vdReasonPresets">
                        <div class="form-check"><input class="form-check-input" type="radio" name="vdReasonPreset" id="vdRp1" value="The earlier company did not answer"><label class="form-check-label" for="vdRp1"><?php echo e(t('vendor.reason.p1', 'The earlier company did not answer')); ?></label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="vdReasonPreset" id="vdRp2" value="Owner or driver requested this company"><label class="form-check-label" for="vdRp2"><?php echo e(t('vendor.reason.p2', 'Owner or driver requested this company')); ?></label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="vdReasonPreset" id="vdRp3" value="Closest truck"><label class="form-check-label" for="vdRp3"><?php echo e(t('vendor.reason.p3', 'Closest truck')); ?></label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="vdReasonPreset" id="vdRp4" value="Special equipment needed"><label class="form-check-label" for="vdRp4"><?php echo e(t('vendor.reason.p4', 'Special equipment needed')); ?></label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="vdReasonPreset" id="vdRp5" value=""><label class="form-check-label" for="vdRp5"><?php echo e(t('vendor.reason.p5', 'Other (explain)')); ?></label></div>
                    </div>
                    <input type="text" class="form-control form-control-sm mt-1" id="vdReasonOther" maxlength="200" autocomplete="off"
                           aria-label="<?php echo e(t('vendor.reason.other', 'Other reason')); ?>" placeholder="<?php echo e(t('vendor.reason.other', 'Other reason')); ?>">
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-warning" id="btnVdReasonOk"><?php echo e(t('vendor.reason.ok', 'Log call with this reason')); ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnVdReasonCancel"><?php echo e(t('vendor.cancel', 'Cancel')); ?></button>
                    </div>
                </div>

                <!-- 5. Another company (not the next in rotation, or not on a list at all) -->
                <div class="mb-2">
                    <button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="collapse" data-bs-target="#vdOtherBody"
                            aria-expanded="false" aria-controls="vdOtherBody" id="vdOtherToggle">
                        <i class="bi bi-plus-circle me-1"></i><?php echo e(t('vendor.other.title', 'Another company')); ?>
                    </button>
                    <div class="collapse border rounded p-2 mt-1" id="vdOtherBody">
                        <div class="row g-2">
                            <div class="col-md-12">
                                <label class="form-label mb-0" for="vdOtherProvider"><?php echo e(t('vendor.other.pick', 'A company we already have on file')); ?></label>
                                <select class="form-select form-select-sm" id="vdOtherProvider"></select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label mb-0" for="vdOtherName"><?php echo e(t('vendor.other.name', 'Or type a company name')); ?></label>
                                <input type="text" class="form-control form-control-sm" id="vdOtherName" maxlength="120" autocomplete="off">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label mb-0" for="vdOtherPhone"><?php echo e(t('vendor.other.phone', 'Phone number')); ?></label>
                                <input type="tel" class="form-control form-control-sm" id="vdOtherPhone" maxlength="32" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="vdOwnerRequest">
                            <label class="form-check-label" for="vdOwnerRequest"><?php echo e(t('vendor.other.owner', 'The driver or owner requested this company')); ?></label>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-primary" id="btnVdLogOther"><i class="bi bi-telephone me-1"></i><span id="vdLogOtherLabel"><?php echo e(t('vendor.log_call', 'Log call')); ?></span></button>
                        </div>
                    </div>
                </div>

                <!-- 6. Vehicle and details -->
                <div class="border rounded p-2 mb-2" id="vdDetailsWrap">
                    <div class="fw-semibold mb-1"><i class="bi bi-car-front me-1"></i><?php echo e(t('vendor.details.title', 'Vehicle and details')); ?></div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label mb-0" for="vdVehicle"><?php echo e(t('vendor.field.vehicle', 'Vehicle (year, make, model, color)')); ?></label>
                            <input type="text" class="form-control form-control-sm" id="vdVehicle" maxlength="160" autocomplete="off">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-0" for="vdPlate"><?php echo e(t('vendor.field.plate', 'Plate')); ?></label>
                            <input type="text" class="form-control form-control-sm" id="vdPlate" maxlength="16" autocomplete="off">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-0" for="vdPlateState"><?php echo e(t('vendor.field.plate_state', 'Plate state')); ?></label>
                            <input type="text" class="form-control form-control-sm" id="vdPlateState" maxlength="4" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label mb-0" for="vdTowReason"><?php echo e(t('vendor.field.reason', 'Reason for the call')); ?></label>
                            <input type="text" class="form-control form-control-sm" id="vdTowReason" maxlength="80" autocomplete="off"
                                   placeholder="<?php echo e(t('vendor.field.reason_ph', 'e.g. disabled vehicle, impound after crash')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label mb-0" for="vdNotes"><?php echo e(t('vendor.field.notes', 'Notes')); ?></label>
                            <input type="text" class="form-control form-control-sm" id="vdNotes" maxlength="255" autocomplete="off">
                        </div>
                    </div>
                    <div class="mt-2 text-body-secondary"><i class="bi bi-geo-alt me-1"></i><?php echo e(t('vendor.field.location', 'Pickup location')); ?>: <span id="vdLocation"></span></div>

                    <!-- Destination: only for a service type that needs one (a tow). A free-text address never blocks. -->
                    <div class="mt-2 d-none" id="vdDestWrap">
                        <label class="form-label mb-0" for="vdDestSelect"><?php echo e(t('vendor.dest.title', 'Destination')); ?></label>
                        <select class="form-select form-select-sm" id="vdDestSelect"></select>
                        <input type="text" class="form-control form-control-sm mt-1 d-none" id="vdDestText" maxlength="255" autocomplete="off"
                               aria-label="<?php echo e(t('vendor.dest.address', 'Destination address')); ?>" placeholder="<?php echo e(t('vendor.dest.address_ph', 'Street address, city')); ?>">
                    </div>
                    <div class="mt-2 d-none" id="vdSaveDetailsWrap">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnVdSaveDetails"><i class="bi bi-save me-1"></i><?php echo e(t('vendor.save_details', 'Save details')); ?></button>
                    </div>
                </div>

                <!-- 7. Dispatch status (an existing dispatch) -->
                <div class="border rounded p-2 mb-2 d-none" id="vdStatusWrap">
                    <div class="fw-semibold mb-1"><i class="bi bi-activity me-1"></i><?php echo e(t('vendor.status.title', 'Dispatch status')); ?></div>
                    <div class="mb-2" id="vdStatusSummary"></div>
                    <div class="d-flex flex-wrap gap-1 mb-2" id="vdStatusButtons">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnVdOnScene"><i class="bi bi-geo-alt-fill me-1"></i><?php echo e(t('vendor.btn.on_scene', 'On scene')); ?></button>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btnVdComplete"><i class="bi bi-check-circle me-1"></i><?php echo e(t('vendor.btn.completed', 'Completed')); ?></button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="btnVdWithdrew"><i class="bi bi-arrow-counterclockwise me-1"></i><?php echo e(t('vendor.btn.withdrew', 'Company withdrew')); ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnVdGoa"><i class="bi bi-slash-circle me-1"></i><?php echo e(t('vendor.btn.goa', 'Gone on arrival')); ?></button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnVdCancel"><i class="bi bi-x-circle me-1"></i><?php echo e(t('vendor.btn.cancel_dispatch', 'Cancel this dispatch')); ?></button>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label mb-0" for="vdEtaInput"><?php echo e(t('vendor.eta.update', 'Update ETA (minutes)')); ?></label>
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control form-control-sm" id="vdEtaInput" min="0" max="1440" step="1" inputmode="numeric">
                                <button type="button" class="btn btn-outline-primary" id="btnVdEta"><?php echo e(t('vendor.btn.update', 'Update')); ?></button>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label mb-0" for="vdNoteInput"><?php echo e(t('vendor.note.add', 'Add a note to this dispatch')); ?></label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control form-control-sm" id="vdNoteInput" maxlength="500" autocomplete="off">
                                <button type="button" class="btn btn-outline-primary" id="btnVdNote"><?php echo e(t('vendor.btn.add', 'Add')); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 8. Timeline -->
                <div class="mb-2 d-none" id="vdTimelineWrap">
                    <div class="fw-semibold mb-1"><i class="bi bi-clock-history me-1"></i><?php echo e(t('vendor.timeline.title', 'Timeline')); ?></div>
                    <div id="vdTimeline"></div>
                    <div class="border border-warning rounded p-2 mt-2 d-none" id="vdVoidBox">
                        <label class="form-label mb-0" for="vdVoidReason"><?php echo e(t('vendor.void.reason', 'Why is this entry being voided?')); ?></label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control form-control-sm" id="vdVoidReason" maxlength="200" autocomplete="off">
                            <button type="button" class="btn btn-warning" id="btnVdVoidOk"><?php echo e(t('vendor.void.ok', 'Void entry')); ?></button>
                            <button type="button" class="btn btn-outline-secondary" id="btnVdVoidCancel"><?php echo e(t('vendor.cancel', 'Cancel')); ?></button>
                        </div>
                    </div>
                </div>

                <!-- 9. Read-aloud sheet -->
                <div class="border rounded p-2" id="vdReadWrap">
                    <div class="d-flex align-items-center mb-1">
                        <div class="fw-semibold"><i class="bi bi-megaphone me-1"></i><?php echo e(t('vendor.readaloud.title', 'Read to the driver')); ?></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="btnVdCopy"><i class="bi bi-clipboard me-1"></i><?php echo e(t('vendor.copy', 'Copy')); ?></button>
                    </div>
                    <div class="input-group input-group-sm mb-2" style="max-width: 24rem;">
                        <span class="input-group-text" id="vdCallbackLbl"><?php echo e(t('vendor.readaloud.callback', 'Callback number')); ?></span>
                        <input type="tel" class="form-control form-control-sm" id="vdCallback" maxlength="32" autocomplete="off" aria-labelledby="vdCallbackLbl"
                               placeholder="<?php echo e(t('vendor.readaloud.callback_ph', 'the number the driver should call')); ?>">
                    </div>
                    <pre class="mb-0 small" id="vdReadAloud" style="white-space: pre-wrap;"></pre>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?php echo e(t('vendor.close', 'Close')); ?></button>
            </div>
        </div>
    </div>
</div>
