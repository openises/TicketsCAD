<?php
/**
 * Shared modal for unit-action buttons (Dispatch / Status / Note).
 *
 * Eric 2026-07-03: units.php + unit-detail.php title bars need the
 * same View/Edit/Dispatch/Status/Note affordances the situation
 * screen surfaces via the responders widget. Rather than duplicate
 * the dashboard's app.js modals, this include emits a single
 * modal container per page that assets/js/unit-actions.js drives.
 *
 * The heavy lifting (endpoints, extra_data prompts, RBAC) still
 * lives in api/responder-status.php, api/responder-note.php,
 * api/incident-assign.php, api/unit-statuses.php.
 */
if (!defined('NEWUI_ROOT')) { return; }
?>
<!-- Unit-action modal (Dispatch / Status / Note) -->
<div class="modal fade" id="unitActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="unitActionModalTitle">Unit action</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2" id="unitActionModalBody">
                <div class="text-body-secondary small">Loading…</div>
            </div>
        </div>
    </div>
</div>
