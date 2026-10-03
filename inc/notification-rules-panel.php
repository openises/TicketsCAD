<?php
/**
 * Settings -> Notification Rules panel markup (Phase 155, GH#144).
 *
 * Included by settings.php in place of the old "will be configurable" stub. The
 * behaviour lives in assets/js/notification-rules.js (window.NotificationRulesAdmin,
 * started by config.js when the tab is first opened); the data comes from
 * api/notification-rules.php, which only a Super Admin may call - the sidebar tab
 * is hidden from everyone else and the panel says so if it is reached anyway.
 *
 * Every dynamic value is inserted by that script with textContent; nothing here
 * carries server data. Every button is type="button" (a button inside a form
 * submits it and reloads the page - GH #84).
 */
if (!defined('NEWUI_ROOT') && !function_exists('e')) { http_response_code(403); exit('Forbidden'); }
?>
        <!-- ── Notification Rules (Phase 155, GH#144) ──────────────── -->
        <div class="config-panel" id="panel-notifications">
            <div class="config-panel-title">
                <i class="bi bi-bell text-warning"></i> Notification Rules
            </div>
            <p class="text-body-secondary small mb-2">
                A rule watches for one thing that happens in the CAD (a new incident, a unit dispatched, a high-alert
                incident...) and tells the people you choose, by email, text message, chat or push, or posts to your
                Slack or Telegram channel, with a message you write. Rules apply to incidents of <strong>every
                organization</strong> on this installation.
            </p>

            <div class="alert alert-warning small d-none" id="nrDenied" role="alert">
                <i class="bi bi-shield-lock me-1"></i>
                Notification rules send mail and texts as the agency, so only a <strong>Super Admin</strong> can
                create or change them. Ask a Super Admin to make the change.
            </div>

            <div id="nrMain">

                <div class="visually-hidden" id="nrLive" aria-live="polite" role="status"></div>
                <div id="nrMsg"></div>

                <!-- Which tool when -->
                <div class="card mb-3" id="nrInfoCard">
                    <div class="card-header py-1 d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-link btn-sm text-decoration-none p-0"
                                data-bs-toggle="collapse" data-bs-target="#nrInfoBody" aria-expanded="true"
                                aria-controls="nrInfoBody" id="nrInfoToggle">
                            <i class="bi bi-info-circle me-1"></i>Notification Rules, Message Routing or Webhooks?
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="nrInfoDismiss">Hide this</button>
                    </div>
                    <div class="collapse show" id="nrInfoBody">
                        <div class="card-body small">
                            <p class="mb-2"><strong>Notification Rules</strong> tell <em>people</em> about CAD events
                                (email, SMS, chat, push, or a Slack/Telegram channel), with a message you write.</p>
                            <p class="mb-2"><strong>Message Routing</strong> copies traffic <em>between channels and
                                radios</em> (Meshtastic, Zello, DMR...) and drives the built-in push alerts. It can
                                not address an email address or a phone number.
                                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-nr-goto="message-routing">Open Message Routing</button></p>
                            <p class="mb-2"><strong>Webhooks</strong> send CAD events to <em>other software</em> as signed
                                JSON (n8n, Zapier, another CAD), not to people.
                                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-nr-goto="webhooks">Open Webhooks</button></p>
                            <p class="mb-0 text-body-secondary">Using Slack or push in both Notification Rules and
                                Message Routing will notify twice. Radio and mesh destinations are not offered here: use
                                Message Routing for those.</p>
                        </div>
                    </div>
                </div>

                <!-- Status strip -->
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small" id="nrStatus">
                    <span class="text-body-secondary">Channels:</span>
                    <span class="d-flex flex-wrap gap-1" id="nrChannelBadges"></span>
                    <span class="ms-auto d-flex align-items-center gap-1" id="nrQueueInfo"></span>
                </div>
                <div class="alert alert-warning small py-2 d-none" id="nrSchedBanner" role="status">
                    <i class="bi bi-clock-history me-1"></i>
                    <strong>No scheduler heartbeat.</strong> Deliveries are sent when a dispatcher acts (a short,
                    bounded attempt), not on a timer. Messages still go out, but a delivery that fails waits for the
                    next dispatch action to be retried. See the setup guide to turn the scheduled sweep on.
                </div>
                <div class="alert alert-danger small py-2 d-none" id="nrBreakerBanner" role="alert"></div>

                <!-- Toolbar -->
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-primary" id="nrBtnNew"><i class="bi bi-plus-lg me-1"></i>New rule</button>
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" id="nrBtnPreset"
                                data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-magic me-1"></i>From template</button>
                        <ul class="dropdown-menu" id="nrPresetMenu" aria-labelledby="nrBtnPreset"></ul>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="nrBtnLog"><i class="bi bi-journal-text me-1"></i>Delivery log</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="nrBtnSettings"
                            data-bs-toggle="collapse" data-bs-target="#nrSettingsCard" aria-expanded="false"
                            aria-controls="nrSettingsCard"><i class="bi bi-sliders me-1"></i>Delivery settings</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="nrBtnRefresh" aria-label="Refresh rules and status"><i class="bi bi-arrow-clockwise"></i></button>
                </div>

                <!-- Delivery settings -->
                <div class="collapse" id="nrSettingsCard">
                    <div class="card card-body mb-3 small">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label mb-1" for="nrSetFormat">Email format</label>
                                <select class="form-select form-select-sm" id="nrSetFormat">
                                    <option value="text">Plain text (best for pagers and Active911)</option>
                                    <option value="html">HTML</option>
                                </select>
                                <div class="form-text">Plain text is sent as-is. HTML wraps nothing for you: the message is escaped, never trusted.</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label mb-1" for="nrSetPrefs">Personal notification preferences</label>
                                <select class="form-select form-select-sm" id="nrSetPrefs">
                                    <option value="explicit_only">Send unless the person has opted out (recommended)</option>
                                    <option value="defaults_apply">Built-in defaults: email and chat on, SMS off</option>
                                </select>
                                <div class="form-text">A high-alert incident always gets through, whatever a person's preferences or quiet hours say. There is no personal preferences screen in this release, so people cannot yet switch SMS on for themselves: choose the second option only if you mean for text messages to reach nobody who has not got a saved preference.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-1" for="nrSetRetention">Keep the delivery log (days)</label>
                                <input type="number" class="form-control form-control-sm" id="nrSetRetention" min="0" max="3650" step="1">
                                <div class="form-text">0 keeps it forever. Messages still waiting are never removed.</div>
                            </div>
                        </div>
                        <div class="mt-3 d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-primary" id="nrSetSave"><i class="bi bi-save me-1"></i>Save settings</button>
                            <span class="small" id="nrSetMsg" role="status"></span>
                        </div>
                    </div>
                </div>

                <!-- Rules table -->
                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="nrTable">
                        <caption class="visually-hidden">Notification rules</caption>
                        <thead>
                            <tr>
                                <th scope="col" style="width:3rem"><span class="visually-hidden">Enabled</span></th>
                                <th scope="col">Rule</th>
                                <th scope="col">When</th>
                                <th scope="col">Who and where</th>
                                <th scope="col">Last fired</th>
                                <th scope="col" class="text-end">Last 30 days</th>
                                <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody id="nrTbody"><tr><td colspan="7" class="text-body-secondary small">Loading...</td></tr></tbody>
                    </table>
                </div>
                <div class="text-body-secondary small d-none" id="nrEmpty">
                    No rules yet. Start from a template above (Active911 is the most common), or choose <strong>New rule</strong>.
                </div>
            </div>

            <!-- ── Rule modal ───────────────────────────────────────── -->
            <div class="modal fade" id="nrRuleModal" tabindex="-1" aria-labelledby="nrRuleTitle" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="nrRuleTitle">New rule</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info small d-none" id="nrPresetNote" role="status"></div>
                            <div class="alert alert-danger small d-none" id="nrFormErrors" role="alert"></div>

                            <div class="row g-4">
                                <div class="col-lg-7">

                                    <h6 class="text-body-secondary text-uppercase small mb-2">1. When</h6>
                                    <div class="mb-2">
                                        <label class="form-label small mb-1" for="nrName">Rule name</label>
                                        <input type="text" class="form-control form-control-sm" id="nrName" maxlength="100" autocomplete="off">
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-6">
                                            <label class="form-label small mb-1" for="nrEvent">Event</label>
                                            <select class="form-select form-select-sm" id="nrEvent"></select>
                                        </div>
                                        <div class="col-md-6 d-flex align-items-end">
                                            <div class="form-text mt-0" id="nrEventDesc"></div>
                                        </div>
                                    </div>
                                    <div class="row g-2 mb-2" id="nrFilters">
                                        <div class="col-md-6">
                                            <label class="form-label small mb-1" for="nrSeverity">Severity (exactly this level)</label>
                                            <select class="form-select form-select-sm" id="nrSeverity"></select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-1" for="nrType">Incident type</label>
                                            <select class="form-select form-select-sm" id="nrType"></select>
                                        </div>
                                    </div>
                                    <div class="form-check mb-1 d-none" id="nrOnceWrap">
                                        <input class="form-check-input" type="checkbox" id="nrOnce">
                                        <label class="form-check-label small" for="nrOnce">Only the first time for each incident</label>
                                        <div class="form-text mt-0">Send one notification when the first unit is dispatched, not one for every unit after it.</div>
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="nrActive" checked>
                                        <label class="form-check-label small" for="nrActive">Rule is on</label>
                                    </div>

                                    <h6 class="text-body-secondary text-uppercase small mb-2">2. Who and where</h6>
                                    <div class="mb-2">
                                        <label class="form-label small mb-1" for="nrChannel">Send by</label>
                                        <select class="form-select form-select-sm" id="nrChannel"></select>
                                        <div class="form-text" id="nrChannelHint"></div>
                                    </div>
                                    <div id="nrRecipBlock">
                                        <div class="small mb-1" id="nrChipsLabel">Recipients</div>
                                        <div class="d-flex flex-wrap gap-1 mb-2" id="nrChips" role="list" aria-labelledby="nrChipsLabel"></div>

                                        <div class="mb-2 position-relative" id="nrAddUser">
                                            <label class="form-label small mb-1" for="nrUserQ">Add a person (user account)</label>
                                            <input type="text" class="form-control form-control-sm" id="nrUserQ" autocomplete="off"
                                                   placeholder="Type a name, login or callsign" role="combobox" aria-expanded="false"
                                                   aria-controls="nrUserResults" aria-autocomplete="list">
                                            <div class="list-group position-absolute w-100 shadow-sm d-none" id="nrUserResults"
                                                 role="listbox" style="z-index:5;max-height:14rem;overflow:auto"></div>
                                        </div>
                                        <div class="mb-2" id="nrAddEmail">
                                            <label class="form-label small mb-1" for="nrEmailIn">Add an email address</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control form-control-sm" id="nrEmailIn" autocomplete="off"
                                                       placeholder="name@example.org (several may be separated by commas)">
                                                <button type="button" class="btn btn-outline-secondary" id="nrEmailAdd">Add</button>
                                            </div>
                                            <div class="form-text">An Active911 alert address goes here.</div>
                                        </div>
                                        <div class="mb-2" id="nrAddPhone">
                                            <label class="form-label small mb-1" for="nrPhoneIn">Add a mobile number</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control form-control-sm" id="nrPhoneIn" autocomplete="off"
                                                       placeholder="+1 555 123 4567">
                                                <button type="button" class="btn btn-outline-secondary" id="nrPhoneAdd">Add</button>
                                            </div>
                                        </div>
                                        <div class="mb-2" id="nrAddList">
                                            <label class="form-label small mb-1" for="nrList">Send to an email list</label>
                                            <select class="form-select form-select-sm" id="nrList"></select>
                                            <div class="form-text" id="nrListHint">Lists are managed under Settings, Email Lists.</div>
                                        </div>
                                    </div>
                                    <div class="alert alert-info small py-2 d-none" id="nrSharedNote"></div>

                                    <h6 class="text-body-secondary text-uppercase small mt-3 mb-2">3. Message</h6>
                                    <div class="mb-2">
                                        <label class="form-label small mb-1" for="nrSubject">Subject</label>
                                        <input type="text" class="form-control form-control-sm" id="nrSubject" maxlength="255" autocomplete="off">
                                        <div class="form-text"><span id="nrSubjectCount">0</span>/255. Leave blank to use the default shown in grey.</div>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small mb-1" for="nrBody">Message</label>
                                        <textarea class="form-control form-control-sm" id="nrBody" rows="5"></textarea>
                                        <div class="form-text"><span id="nrBodyCount">0</span>/<span id="nrBodyMax">4000</span>. Leave blank to use the default.</div>
                                    </div>
                                    <div class="small mb-1">Insert a field (click to add it where you are typing):</div>
                                    <div class="d-flex flex-wrap gap-1 mb-2" id="nrPlaceholders"></div>
                                    <div class="form-text mb-2">
                                        Add <code>|clean</code> inside the braces, like <code>{street|clean}</code>, to remove semicolons and
                                        line breaks so the value fits a one-line format such as Active911 StandardA.
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="nrResetText">Reset subject and message to the default</button>
                                </div>

                                <div class="col-lg-5">
                                    <h6 class="text-body-secondary text-uppercase small mb-2">4. Review</h6>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <label class="form-label small mb-0" for="nrSample">Preview with</label>
                                        <select class="form-select form-select-sm w-auto" id="nrSample">
                                            <option value="synthetic">a sample incident</option>
                                            <option value="latest">the most recent incident</option>
                                        </select>
                                    </div>
                                    <div class="alert alert-warning small py-2 d-none" id="nrWarnings" role="status"></div>
                                    <div class="card mb-2">
                                        <div class="card-body py-2 small">
                                            <div class="text-body-secondary">Subject</div>
                                            <div class="fw-semibold mb-2" id="nrPrevSubject"></div>
                                            <div class="text-body-secondary">Message</div>
                                            <pre class="mb-0 small" id="nrPrevBody" style="white-space:pre-wrap"></pre>
                                        </div>
                                    </div>
                                    <div class="small text-body-secondary mb-1" id="nrPrevSummary"></div>
                                    <ul class="list-unstyled small mb-3" id="nrPrevList"></ul>
                                    <div class="border rounded p-2 small">
                                        <div class="fw-semibold mb-1">Try it</div>
                                        <div class="text-body-secondary mb-2">A test message starts with [TEST] and is never counted as the rule firing.</div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="nrTestMe"><i class="bi bi-send me-1"></i>Send a test to me</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" id="nrTestRule"><i class="bi bi-send-exclamation me-1"></i>Send a test to the real recipients</button>
                                        </div>
                                        <div class="mt-2" id="nrTestResult" role="status"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <span class="small text-body-secondary me-auto">Ctrl+Enter saves</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-sm btn-primary" id="nrBtnSave"><i class="bi bi-save me-1"></i>Save</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="nrBtnSaveTest">Save and send a test to me</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Delivery log modal ───────────────────────────────── -->
            <div class="modal fade" id="nrLogModal" tabindex="-1" aria-labelledby="nrLogTitle" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="nrLogTitle">Delivery log</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2 mb-2 small align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label mb-1" for="nrLogRule">Rule</label>
                                    <select class="form-select form-select-sm" id="nrLogRule"></select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label mb-1" for="nrLogStatus">Status</label>
                                    <select class="form-select form-select-sm" id="nrLogStatus">
                                        <option value="">Any</option>
                                        <option value="queued">Waiting</option>
                                        <option value="sent">Sent</option>
                                        <option value="failed">Failed</option>
                                        <option value="skipped">Skipped</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label mb-1" for="nrLogFrom">From</label>
                                    <input type="date" class="form-control form-control-sm" id="nrLogFrom">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label mb-1" for="nrLogTo">To</label>
                                    <input type="date" class="form-control form-control-sm" id="nrLogTo">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-sm btn-primary" id="nrLogApply">Apply</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle small">
                                    <caption class="visually-hidden">Notification delivery log</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">When</th><th scope="col">Rule</th><th scope="col">Incident</th>
                                            <th scope="col">Channel</th><th scope="col">To</th><th scope="col">Status</th>
                                            <th scope="col">Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody id="nrLogBody"></tbody>
                                </table>
                            </div>
                            <div class="d-flex align-items-center gap-2 small">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="nrLogPrev">Newer</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="nrLogNext">Older</button>
                                <span class="text-body-secondary" id="nrLogInfo"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
