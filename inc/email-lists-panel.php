<?php
/**
 * Settings -> Email Lists panel markup (Phase 155, GH#145).
 *
 * Included by settings.php. Behaviour: assets/js/email-lists-admin.js
 * (window.EmailListsAdmin, started by config.js the first time the tab is opened);
 * data: api/email-lists.php (action.manage_config).
 *
 * Nothing here carries server data - the script inserts every list name, member name,
 * address and message with textContent. Every button is type="button" (a button
 * inside a form submits it and reloads the page - GH #84).
 */
if (!defined('NEWUI_ROOT') && !function_exists('e')) { http_response_code(403); exit('Forbidden'); }
?>
        <!-- ── Email Distribution Lists (Phase 155, GH#145) ───────── -->
        <div class="config-panel" id="panel-email-lists">
            <div class="config-panel-title">
                <i class="bi bi-people text-info"></i> Email Distribution Lists
            </div>
            <p class="text-body-secondary small mb-2">Create named groups of email recipients for notifications. A list can hold roster members, contacts, other lists, and typed addresses, in any mix.</p>

            <div class="alert alert-info py-2 small">
                <i class="bi bi-info-circle me-1"></i>
                <strong>How lists are used:</strong> a list is a group of email addresses that a <a href="#notifications">Notification Rule</a> can send to - for example "email every new SHELTER incident to <em>red-cross-ops</em>". Choose the list in the rule's <em>Send to an email list</em> box. Nothing else in the application reads a list. Entries are read <strong>each time a rule fires</strong>, so changing a member's email address, or adding someone to a list, changes who is told from the next event on.
                <details class="mt-2">
                    <summary class="fw-semibold">What each kind of entry does</summary>
                    <ul class="mb-0 mt-2">
                        <li><strong>Member</strong> &mdash; a roster member, addressed by the email on the member record (read at send time). A member whose status is one you have chosen to skip (see <em>List options</em>), who was deleted, or who has switched email notifications off is left out, and the list says so.</li>
                        <li><strong>Contact</strong> &mdash; a record from the Constituents address book, addressed by the email on that record.</li>
                        <li><strong>Sub-list</strong> &mdash; another list, expanded when the rule fires (up to 10 levels). A list that would contain itself, directly or through other lists, is refused.</li>
                        <li><strong>Email address</strong> &mdash; a typed address for someone not in your roster.</li>
                    </ul>
                    <div class="mt-2">An address held in the single email field of a member or contact can be several addresses separated by commas or semicolons; each one is used. The same address twice is sent once.</div>
                </details>
            </div>

            <div id="elMsg"></div>

            <div class="d-flex gap-2 mb-2 align-items-center flex-wrap">
                <button type="button" class="btn btn-sm btn-success" id="btnNewEmailList"><i class="bi bi-plus-lg me-1"></i>New List</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="elBtnOptions"
                        data-bs-toggle="collapse" data-bs-target="#elOptionsCard" aria-expanded="false"
                        aria-controls="elOptionsCard"><i class="bi bi-sliders me-1"></i>List options</button>
                <input type="text" class="form-control form-control-sm ms-auto" placeholder="Filter lists..." id="emailListFilter" style="max-width:240px;" aria-label="Filter email lists">
            </div>

            <!-- List options -->
            <div class="collapse" id="elOptionsCard">
                <div class="card card-body mb-3 small">
                    <fieldset class="mb-2">
                        <legend class="fs-6 fw-semibold">Leave out members whose status is</legend>
                        <div id="elSkipStatuses" class="d-flex flex-wrap gap-3"></div>
                        <div class="form-text">Ticked statuses are skipped when a list is sent, and shown as <em>Skipped</em> on the entry. A retired volunteer should not keep receiving incident details. Un-tick to send to every member who is not deleted.</div>
                    </fieldset>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="elRequireEmail">
                        <label class="form-check-label" for="elRequireEmail">Refuse to add a member or contact that has no email address</label>
                        <div class="form-text mt-0">Off (default): they can be added, and the entry is marked <em>No email address</em> so you can fix the record. On: the add is refused.</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-primary" id="elOptionsSave"><i class="bi bi-save me-1"></i>Save options</button>
                        <span class="small" id="elOptionsMsg" role="status"></span>
                    </div>
                </div>
            </div>

            <div id="emailListsBody">
                <div class="text-body-secondary p-3 small">Loading lists...</div>
            </div>

            <!-- ── New list modal ──────────────────────────────────── -->
            <div class="modal fade" id="elNewModal" tabindex="-1" aria-labelledby="elNewTitle" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title" id="elNewTitle">New email list</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger small d-none" id="elNewErr" role="alert"></div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="elNewName">Name</label>
                                <input type="text" class="form-control form-control-sm" id="elNewName" maxlength="100" autocomplete="off" placeholder="EOC Ops">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="elNewDesc">Description (optional)</label>
                                <input type="text" class="form-control form-control-sm" id="elNewDesc" maxlength="255" autocomplete="off">
                            </div>
                        </div>
                        <div class="modal-footer py-2">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-sm btn-success" id="elNewCreate">Create and add recipients</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Manage list modal ───────────────────────────────── -->
            <div class="modal fade" id="elManageModal" tabindex="-1" aria-labelledby="elManageTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title" id="elManageTitle">Manage list</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert small py-2 mb-2" id="elSummary" role="status" aria-live="polite"></div>
                            <div class="alert alert-danger small d-none" id="elManageErr" role="alert"></div>

                            <div class="card mb-3">
                                <div class="card-body py-2">
                                    <div class="small fw-semibold mb-1" id="elAddLabel">Add a recipient</div>
                                    <div class="btn-group btn-group-sm mb-2" role="radiogroup" aria-labelledby="elAddLabel" id="elTypeGroup">
                                        <input type="radio" class="btn-check" name="elType" id="elTypeMember" value="member" checked>
                                        <label class="btn btn-outline-secondary" for="elTypeMember">Member</label>
                                        <input type="radio" class="btn-check" name="elType" id="elTypeConstituent" value="constituent">
                                        <label class="btn btn-outline-secondary" for="elTypeConstituent">Contact</label>
                                        <input type="radio" class="btn-check" name="elType" id="elTypeList" value="list">
                                        <label class="btn btn-outline-secondary" for="elTypeList">Sub-list</label>
                                        <input type="radio" class="btn-check" name="elType" id="elTypeInline" value="inline">
                                        <label class="btn btn-outline-secondary" for="elTypeInline">Email address</label>
                                    </div>

                                    <div id="elPickerBlock">
                                        <label class="form-label small mb-1" for="elPickerInput" id="elPickerLabel">Member</label>
                                        <div class="searchable-select-wrap position-relative mb-2">
                                            <input type="text" class="form-control form-control-sm searchable-select-input" id="elPickerInput" autocomplete="off"
                                                   placeholder="Type a name or callsign, or click to browse">
                                            <input type="hidden" id="elPickerValue">
                                            <ul class="searchable-select-list list-group d-none"></ul>
                                        </div>
                                        <div class="form-check small mb-2 d-none" id="elHasEmailWrap">
                                            <input class="form-check-input" type="checkbox" id="elHasEmail" checked>
                                            <label class="form-check-label" for="elHasEmail">Only contacts that have an email address</label>
                                        </div>
                                    </div>

                                    <div class="row g-2 mb-2 d-none" id="elInlineBlock">
                                        <div class="col-md-6">
                                            <label class="form-label small mb-1" for="elInlineEmail">Email address</label>
                                            <input type="text" class="form-control form-control-sm" id="elInlineEmail" autocomplete="off" placeholder="name@example.org">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-1" for="elInlineName">Name (optional)</label>
                                            <input type="text" class="form-control form-control-sm" id="elInlineName" autocomplete="off">
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-success" id="elAddBtn" disabled><i class="bi bi-plus-lg me-1"></i><span id="elAddBtnText">Add</span></button>
                                        <span class="small" id="elAddMsg" role="status" aria-live="polite"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive mb-3">
                                <table class="table table-sm align-middle small mb-0">
                                    <caption class="visually-hidden">Entries on this list</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Type</th>
                                            <th scope="col">Recipient</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">Gives</th>
                                            <th scope="col">Added</th>
                                            <th scope="col" class="text-end"><span class="visually-hidden">Remove</span></th>
                                        </tr>
                                    </thead>
                                    <tbody id="elEntriesBody"></tbody>
                                </table>
                            </div>

                            <details class="mb-2" id="elPreviewDetails">
                                <summary class="small fw-semibold">Preview recipients</summary>
                                <div class="small mt-2" id="elPreviewBody" aria-live="polite"></div>
                            </details>

                            <details class="mb-2" id="elCsvDetails">
                                <summary class="small fw-semibold">Import addresses from a CSV</summary>
                                <div class="mt-2">
                                    <label class="form-label small mb-1" for="elCsvText">One address per line; an optional second column is the name</label>
                                    <textarea class="form-control form-control-sm font-monospace" id="elCsvText" rows="4" placeholder="sheriff@county.gov,County Sheriff"></textarea>
                                    <div class="d-flex align-items-center gap-2 mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="elCsvBtn"><i class="bi bi-upload me-1"></i>Import into this list</button>
                                        <span class="small" id="elCsvResult" role="status" aria-live="polite"></span>
                                    </div>
                                </div>
                            </details>

                            <details class="mb-0" id="elEditDetails">
                                <summary class="small fw-semibold">Edit name and description</summary>
                                <div class="row g-2 mt-1">
                                    <div class="col-md-5">
                                        <label class="form-label small mb-1" for="elEditName">Name</label>
                                        <input type="text" class="form-control form-control-sm" id="elEditName" maxlength="100" autocomplete="off">
                                    </div>
                                    <div class="col-md-7">
                                        <label class="form-label small mb-1" for="elEditDesc">Description</label>
                                        <input type="text" class="form-control form-control-sm" id="elEditDesc" maxlength="255" autocomplete="off">
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="elEditSave">Save name and description</button>
                                    <span class="small" id="elEditMsg" role="status" aria-live="polite"></span>
                                </div>
                            </details>
                        </div>
                        <div class="modal-footer py-2">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
