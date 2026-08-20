<?php
/**
 * ICS form delete/restore writers + the authorisation decision behind them.
 *
 * REPORTED BY: Chris Byrd, 2026-07-29 — a saved ICS form could never be
 * deleted by anyone, draft or finalized. See sql/run_ics_forms_soft_delete.php
 * for the schema half of the fix.
 *
 * WHY THIS IS AN inc/ INCLUDE AND NOT MORE CODE IN api/ics-forms.php.
 * Logic buried inside an endpoint's action dispatch cannot be driven by a
 * test — tests/test_ics_forms_idor.php has to `eval()` a regex-extracted
 * copy of ics_form_accessible() out of the endpoint source to reach it,
 * which tests a copy rather than the code that runs. Phase 117 recorded that
 * as a rule ("reusable/testable logic goes in an inc/*.php include"), so the
 * delete path lives here and BOTH api/ics-forms.php and
 * tests/test_ics_form_delete.php require this same file.
 *
 * THE POLICY (approved by Eric, 2026-08-02). ICS forms are operational
 * records, not UI clutter — a finalized ICS-214 is the legal/operational
 * artefact of a real incident, so deleting one is a records-retention
 * decision rather than a convenience:
 *
 *   1. A DRAFT may be deleted by the person who created it, or by a
 *      privileged user.
 *   2. A FINALIZED form (anything not 'draft' — 'final', 'sent') is
 *      privileged-only.
 *   3. Every delete is SOFT — deleted_at + deleted_by — and lands in the
 *      existing wastebasket, restorable. Nothing here hard-deletes, and
 *      api/wastebasket.php marks this type not-purgeable so neither the
 *      purge button nor "Empty wastebasket" can either.
 *   4. Every delete writes an audit entry naming the form, its type, its
 *      incident if linked, and who did it.
 *
 * "Privileged" means is_admin() OR the action.delete_ics_form permission,
 * resolved by the CALLER and passed in — this file stays free of session
 * state so the decision is testable in isolation and cannot be accidentally
 * satisfied by ambient globals.
 *
 * OWNERSHIP is real, not inferred: ics_forms.created_by is written on every
 * INSERT in api/ics-forms.php from the session user id, and has been since
 * the table shipped. It is `INT NOT NULL DEFAULT 0`, so 0 means "no known
 * creator" (a legacy or system-made row) and must never match a live user —
 * hence the explicit > 0 guards on both sides of the comparison below.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

/**
 * Does this install have the soft-delete columns on ics_forms yet?
 *
 * An install that has not run sql/run_migrations.php since 4.2.4 has the
 * table but not the columns. Every caller checks this so the failure is a
 * sentence telling the admin what to run, not a raw PDO "Unknown column"
 * at the moment a user presses Delete (the Phase 125 lesson).
 */
function ics_forms_has_soft_delete(bool $forceReload = false): bool {
    static $cached = null;
    if ($forceReload) $cached = null;
    if ($cached !== null) return $cached;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $n = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND COLUMN_NAME IN ('deleted_at', 'deleted_by')",
            [$prefix . 'ics_forms']
        );
        return $cached = ($n === 2);
    } catch (Throwable $e) {
        error_log('[ics_forms_has_soft_delete] ' . $e->getMessage());
        return $cached = false;
    }
}

/**
 * May this user delete this form? Pure decision — no DB, no session.
 *
 * @param array $row        ics_forms row; needs `status` and `created_by`.
 * @param int   $userId     acting user id (0 = none).
 * @param bool  $privileged is_admin() || rbac_can('action.delete_ics_form').
 * @return array{allowed:bool, reason:string}
 *         reason is a stable machine token; ics_form_delete_message() renders it.
 */
function ics_form_delete_decision(array $row, int $userId, bool $privileged): array {
    if (!empty($row['deleted_at'])) {
        return ['allowed' => false, 'reason' => 'already_deleted'];
    }

    // Rule 1/2 — privilege clears both drafts and finalized forms.
    if ($privileged) {
        return ['allowed' => true, 'reason' => 'privileged'];
    }

    // Anything not an open draft is a finished operational record.
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if ($status !== 'draft') {
        return ['allowed' => false, 'reason' => 'finalized'];
    }

    // Rule 1 — the draft's own creator. Both ids must be real: created_by
    // defaults to 0, and 0 is not a user.
    $creator = (int) ($row['created_by'] ?? 0);
    if ($userId > 0 && $creator > 0 && $creator === $userId) {
        return ['allowed' => true, 'reason' => 'creator'];
    }

    return ['allowed' => false, 'reason' => 'not_creator'];
}

/**
 * Human sentence for a decision reason, for the API's error body.
 */
function ics_form_delete_message(string $reason): string {
    switch ($reason) {
        case 'already_deleted':
            return 'That form is already in the wastebasket.';
        case 'finalized':
            return 'This form is finalized. A finalized ICS form is an operational '
                 . 'record — only an administrator can delete it. Switch it back to '
                 . 'draft first if it is yours and was finalized by mistake.';
        case 'not_creator':
            return 'You can only delete a draft you created yourself.';
        case 'no_schema':
            return 'This install is missing the ICS form wastebasket columns. '
                 . 'Run: php sql/run_migrations.php';
        case 'not_found':
            return 'Form not found';
        default:
            return 'You do not have permission to delete this form.';
    }
}

/**
 * Soft-delete an ICS form to the wastebasket, with the audit entry.
 *
 * Authorisation is NOT re-derived here — the caller resolves $privileged and
 * this function applies ics_form_delete_decision() to it. That keeps one
 * decision function on the one code path, so a test proving the decision
 * proves what the endpoint does.
 *
 * @return array{deleted:bool, errors:string[], reason:string}
 */
function ics_form_soft_delete(int $formId, int $userId, bool $privileged): array {
    if ($formId <= 0) {
        return ['deleted' => false, 'errors' => ['invalid_id'], 'reason' => 'invalid_id'];
    }
    if (!ics_forms_has_soft_delete()) {
        return ['deleted' => false, 'errors' => ['no_schema'], 'reason' => 'no_schema'];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';

    $row = db_fetch_one(
        "SELECT `id`, `form_type`, `incident_id`, `title`, `status`,
                `created_by`, `created_by_name`, `deleted_at`
           FROM `{$prefix}ics_forms` WHERE `id` = ?",
        [$formId]
    );
    if (!$row) {
        return ['deleted' => false, 'errors' => ['not_found'], 'reason' => 'not_found'];
    }

    $decision = ics_form_delete_decision($row, $userId, $privileged);
    if (!$decision['allowed']) {
        return ['deleted' => false, 'errors' => [$decision['reason']], 'reason' => $decision['reason']];
    }

    try {
        db_query(
            "UPDATE `{$prefix}ics_forms`
                SET `deleted_at` = NOW(), `deleted_by` = ?
              WHERE `id` = ? AND `deleted_at` IS NULL",
            [$userId > 0 ? $userId : null, $formId]
        );
    } catch (Throwable $e) {
        error_log('[ics_form_soft_delete] ' . $e->getMessage());
        return ['deleted' => false, 'errors' => ['write_failed'], 'reason' => 'write_failed'];
    }

    // Requirement 4 — name the form, its type, its incident, and the actor.
    // audit_log() reads the actor from the session itself; target_type is
    // 'ics_forms' so it matches the wastebasket restore/purge entries for the
    // same rows (api/wastebasket.php logs its tableConfig key), and a delete
    // and its later restore read as one story in the audit viewer.
    $label = ics_form_label($row);
    audit_log(
        'system',
        'delete',
        'ics_forms',
        $formId,
        "Deleted {$label} to the wastebasket"
            . (!empty($row['incident_id']) ? " (incident #{$row['incident_id']})" : ''),
        [
            'form_type'   => $row['form_type'],
            'status'      => $row['status'],
            'title'       => $row['title'],
            'incident_id' => $row['incident_id'] !== null ? (int) $row['incident_id'] : null,
            'created_by'  => (int) $row['created_by'],
            'basis'       => $decision['reason'],   // 'privileged' or 'creator'
        ],
        AUDIT_HIGH
    );

    return ['deleted' => true, 'errors' => [], 'reason' => $decision['reason']];
}

/**
 * Display label for a form row — used by the audit summary and by the
 * wastebasket list. "ICS-214 — Bridge collapse (final)".
 *
 * Phase 140 (2026-08-16): a custom type has no fixed "ICS-nnn" number, so
 * its type label comes from the instance's own frozen `_meta` snapshot
 * (form_data_json._meta.form_number, falling back to form_title) rather
 * than 'ICS-' . strtoupper($type) -- reading a fresh ics_form_types row
 * here would violate the whole point of the _meta snapshot (an existing
 * submission's label must never change just because the type definition
 * was edited or archived later). $row['form_data_json'] is optional --
 * callers that select a narrower column list (e.g. the wastebasket list)
 * simply get the generic "Custom Form" fallback below.
 */
function ics_form_label(array $row): string {
    $type = strtoupper((string) ($row['form_type'] ?? ''));
    $title = trim((string) ($row['title'] ?? ''));

    if ($type === 'CUSTOM') {
        $typeLabel = 'Custom Form';
        $rawData = $row['form_data_json'] ?? null;
        if (is_string($rawData) && $rawData !== '') {
            $decoded = json_decode($rawData, true);
            $meta = is_array($decoded) && isset($decoded['_meta']) && is_array($decoded['_meta']) ? $decoded['_meta'] : [];
            $formNumber = trim((string) ($meta['form_number'] ?? ''));
            $formTitle = trim((string) ($meta['form_title'] ?? ''));
            if ($formNumber !== '') {
                $typeLabel = $formNumber;
            } elseif ($formTitle !== '') {
                $typeLabel = $formTitle;
            }
        }
        $out = $typeLabel;
    } else {
        $out = $type !== '' ? 'ICS-' . $type : 'ICS form';
    }

    $out .= ' — ' . ($title !== '' ? $title : '(untitled)');
    $status = trim((string) ($row['status'] ?? ''));
    if ($status !== '') $out .= ' (' . $status . ')';
    return $out;
}
