<?php
/**
 * NewUI v4.0 API - Shift Assignments & Self-Service Signup
 *
 * POST /api/shift-assignments.php action=assign     — Admin assigns member to slot
 * POST /api/shift-assignments.php action=signup      — Volunteer self-signup
 * POST /api/shift-assignments.php action=cancel      — Cancel assignment
 * POST /api/shift-assignments.php action=swap        — Swap assignment between members
 * POST /api/shift-assignments.php action=update_status — Update status (confirmed/completed/no-show)
 *
 * PREREQUISITE ENFORCEMENT:
 *   On signup/assign, checks shift_roles.required_cert_ids and required_ics_position_id
 *   against member's certifications. Admin can override with force=1.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/scheduling-perms.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) json_error('Invalid JSON body');

$action = $input['action'] ?? '';

switch ($action) {
    case 'assign':
        handleAssign($input, false);
        break;
    case 'signup':
        handleAssign($input, true);
        break;
    case 'cancel':
        handleCancel($input);
        break;
    case 'swap':
        handleSwap($input);
        break;
    case 'update_status':
        handleUpdateStatus($input);
        break;
    case 'delete':
        handleDelete($input);
        break;
    default:
        json_error('Unknown action: ' . $action);
}

ini_set('display_errors', $prevDisplay);

/**
 * Assign or self-signup a member to a shift slot+role on a date.
 */
function handleAssign(array $input, bool $selfSignup): void
{
    global $current_user_id, $current_level;

    $slotId   = intval($input['slot_id'] ?? 0);
    $roleId   = intval($input['role_id'] ?? 0);
    $memberId = intval($input['member_id'] ?? 0);
    $date     = $input['assignment_date'] ?? '';
    $force    = !empty($input['force']);
    $notes    = trim($input['notes'] ?? '');

    if (!$slotId || !$roleId || !$memberId || !$date) {
        json_error('Missing required fields: slot_id, role_id, member_id, assignment_date');
    }

    // Validate date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_error('Invalid date format');
    }

    // Self-signup: member_id must be current user (unless admin)
    if ($selfSignup && $memberId !== $current_user_id && !is_admin()) {
        json_error('You can only sign up yourself');
    }

    // Check scheduling permissions
    if ($selfSignup) {
        // Get template_id from the slot for permission resolution
        $templateId = null;
        try {
            $slot = db_fetch_one(
                "SELECT `template_id` FROM " . db_table('newui_shift_slots') . " WHERE `id` = ?",
                [$slotId]
            );
            if ($slot) $templateId = (int) $slot['template_id'];
        } catch (Exception $e) {}

        $perms = scheduling_get_effective_permissions($memberId, 'template', $templateId);
        if (!$perms['can_self_assign']) {
            json_error('You do not have permission to sign up for shifts');
        }
    } elseif (!is_admin()) {
        // Non-admin assigning others — check can_assign_others
        $templateId = null;
        try {
            $slot = db_fetch_one(
                "SELECT `template_id` FROM " . db_table('newui_shift_slots') . " WHERE `id` = ?",
                [$slotId]
            );
            if ($slot) $templateId = (int) $slot['template_id'];
        } catch (Exception $e) {}

        // Resolve permissions for the assigning user's member record
        $assignerMemberId = 0;
        try {
            $assignerMember = db_fetch_one(
                "SELECT `id` FROM " . db_table('member') . " WHERE `user_id` = ?",
                [$current_user_id]
            );
            if ($assignerMember) $assignerMemberId = (int) $assignerMember['id'];
        } catch (Exception $e) {}

        if ($assignerMemberId) {
            $perms = scheduling_get_effective_permissions($assignerMemberId, 'template', $templateId);
            if (!$perms['can_assign_others']) {
                json_error('You do not have permission to assign others to shifts');
            }
        }
    }

    // Check role exists and get prerequisites
    try {
        $role = db_fetch_all(
            "SELECT * FROM " . db_table('newui_shift_roles') . " WHERE id = ?",
            [$roleId]
        );
    } catch (Exception $e) {
        json_error('Failed to load role');
    }
    if (empty($role)) json_error('Role not found', 404);
    $role = $role[0];

    // Check max_slots not exceeded
    try {
        $currentCount = db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('newui_shift_assignments') . "
             WHERE slot_id = ? AND role_id = ? AND assignment_date = ?
               AND status NOT IN ('cancelled','swapped')",
            [$slotId, $roleId, $date]
        );
    } catch (Exception $e) {
        $currentCount = 0;
    }
    if ((int) $currentCount >= (int) $role['max_slots']) {
        json_error('This slot is full (max ' . $role['max_slots'] . ' for ' . $role['role_name'] . ')');
    }

    // Check duplicate
    try {
        $existing = db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('newui_shift_assignments') . "
             WHERE slot_id = ? AND role_id = ? AND member_id = ? AND assignment_date = ?
               AND status NOT IN ('cancelled','swapped')",
            [$slotId, $roleId, $memberId, $date]
        );
    } catch (Exception $e) {
        $existing = 0;
    }
    if ((int) $existing > 0) {
        json_error('Member is already assigned to this slot/role on this date');
    }

    // ── Prerequisite enforcement ──
    if (!$force) {
        $errors = checkPrerequisites($memberId, $role);
        if (!empty($errors)) {
            json_response([
                'success'       => false,
                'prereq_failed' => true,
                'errors'        => $errors,
                'message'       => 'Prerequisites not met. Admin can override with force=1.',
            ], 422);
        }
    }

    // Create assignment
    try {
        db_query(
            "INSERT INTO " . db_table('newui_shift_assignments') . "
             (slot_id, role_id, member_id, assignment_date, status, self_signup, notes, assigned_by)
             VALUES (?, ?, ?, ?, 'assigned', ?, ?, ?)",
            [$slotId, $roleId, $memberId, $date, $selfSignup ? 1 : 0, $notes, $current_user_id]
        );
        $id = db_insert_id();
    } catch (Exception $e) {
        json_error('Failed to create assignment: ' . $e->getMessage());
    }

    audit_log('personnel', 'assign', 'shift_assignment', $id, ($selfSignup ? 'Self-signup' : 'Admin assigned') . " member #{$memberId} to slot #{$slotId} role #{$roleId} on {$date}", [
        'slot_id' => $slotId,
        'role_id' => $roleId,
        'member_id' => $memberId,
        'assignment_date' => $date,
        'self_signup' => $selfSignup
    ]);
    json_response(['success' => true, 'id' => $id]);
}

/**
 * Check certification and ICS position prerequisites for a role.
 */
function checkPrerequisites(int $memberId, array $role): array
{
    $errors = [];

    // Check required certifications
    $requiredCertIds = $role['required_cert_ids'];
    if ($requiredCertIds) {
        $certIds = json_decode($requiredCertIds, true);
        if (is_array($certIds) && !empty($certIds)) {
            foreach ($certIds as $certId) {
                try {
                    $held = db_fetch_value(
                        "SELECT COUNT(*) FROM " . db_table('member_certifications') . "
                         WHERE member_id = ? AND certification_id = ?
                           AND (expiration_date IS NULL OR expiration_date >= CURDATE())",
                        [$memberId, intval($certId)]
                    );
                } catch (Exception $e) {
                    $held = 0;
                }
                if ((int) $held === 0) {
                    // Get cert name
                    try {
                        $certName = db_fetch_value(
                            "SELECT name FROM " . db_table('certifications') . " WHERE id = ?",
                            [intval($certId)]
                        );
                    } catch (Exception $e) {
                        $certName = "Certification #{$certId}";
                    }
                    $errors[] = "Missing or expired certification: " . ($certName ?: "#{$certId}");
                }
            }
        }
    }

    // Check required ICS position qualification
    $icsPositionId = $role['required_ics_position_id'];
    if ($icsPositionId) {
        try {
            $qualified = db_fetch_value(
                "SELECT COUNT(*) FROM " . db_table('member_ics_qualifications') . "
                 WHERE member_id = ? AND ics_position_id = ?
                   AND qualification_level IN ('Qualified','Expert')",
                [$memberId, intval($icsPositionId)]
            );
        } catch (Exception $e) {
            $qualified = 0;
        }
        if ((int) $qualified === 0) {
            try {
                $posTitle = db_fetch_value(
                    "SELECT title FROM " . db_table('ics_positions') . " WHERE id = ?",
                    [intval($icsPositionId)]
                );
            } catch (Exception $e) {
                $posTitle = "Position #{$icsPositionId}";
            }
            $errors[] = "Not qualified for ICS position: " . ($posTitle ?: "#{$icsPositionId}");
        }
    }

    return $errors;
}

function handleCancel(array $input): void
{
    $id = intval($input['id'] ?? 0);
    if (!$id) json_error('Missing assignment id');

    try {
        db_query(
            "UPDATE " . db_table('newui_shift_assignments') . "
             SET status = 'cancelled', updated_at = NOW()
             WHERE id = ?",
            [$id]
        );
    } catch (Exception $e) {
        json_error('Failed to cancel: ' . $e->getMessage());
    }
    audit_log('personnel', 'unassign', 'shift_assignment', $id, "Cancelled shift assignment #{$id}");
    json_response(['success' => true]);
}

/**
 * Permanently delete a shift assignment (admin only).
 * Frees the slot so it shows as available again.
 */
function handleDelete(array $input): void
{
    global $current_level;

    if (!is_admin()) {
        json_error('Only admins can delete assignments');
    }

    $id = intval($input['id'] ?? 0);
    if (!$id) json_error('Missing assignment id');

    try {
        db_query(
            "DELETE FROM " . db_table('newui_shift_assignments') . " WHERE id = ?",
            [$id]
        );
    } catch (Exception $e) {
        json_error('Failed to delete: ' . $e->getMessage());
    }
    audit_log('personnel', 'delete', 'shift_assignment', $id, "Deleted shift assignment #{$id}");
    json_response(['success' => true]);
}

function handleSwap(array $input): void
{
    global $current_user_id;

    $assignmentId = intval($input['assignment_id'] ?? 0);
    $newMemberId  = intval($input['new_member_id'] ?? 0);
    if (!$assignmentId || !$newMemberId) json_error('Missing assignment_id or new_member_id');

    // Get existing assignment
    try {
        $existing = db_fetch_all(
            "SELECT sa.*, sr.* FROM " . db_table('newui_shift_assignments') . " sa
             LEFT JOIN " . db_table('newui_shift_roles') . " sr ON sa.role_id = sr.id
             WHERE sa.id = ?",
            [$assignmentId]
        );
    } catch (Exception $e) {
        json_error('Assignment not found');
    }
    if (empty($existing)) json_error('Assignment not found', 404);
    $old = $existing[0];

    // Mark old as swapped
    try {
        db_query(
            "UPDATE " . db_table('newui_shift_assignments') . "
             SET status = 'swapped', updated_at = NOW()
             WHERE id = ?",
            [$assignmentId]
        );

        // Create new assignment
        db_query(
            "INSERT INTO " . db_table('newui_shift_assignments') . "
             (slot_id, role_id, member_id, assignment_date, status, self_signup, notes, assigned_by)
             VALUES (?, ?, ?, ?, 'assigned', 0, ?, ?)",
            [$old['slot_id'], $old['role_id'], $newMemberId, $old['assignment_date'],
             'Swapped from member #' . $old['member_id'], $current_user_id]
        );
    } catch (Exception $e) {
        json_error('Failed to swap: ' . $e->getMessage());
    }

    audit_log('personnel', 'assign', 'shift_assignment', null, "Swapped shift assignment #{$assignmentId} to member #{$newMemberId}", [
        'old_assignment_id' => $assignmentId,
        'old_member_id' => $old['member_id'],
        'new_member_id' => $newMemberId,
        'slot_id' => $old['slot_id'],
        'assignment_date' => $old['assignment_date']
    ]);
    json_response(['success' => true]);
}

function handleUpdateStatus(array $input): void
{
    $id     = intval($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    if (!$id) json_error('Missing assignment id');

    $validStatuses = ['assigned', 'confirmed', 'completed', 'no-show', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        json_error('Invalid status. Must be one of: ' . implode(', ', $validStatuses));
    }

    try {
        db_query(
            "UPDATE " . db_table('newui_shift_assignments') . "
             SET status = ?, updated_at = NOW()
             WHERE id = ?",
            [$status, $id]
        );
    } catch (Exception $e) {
        json_error('Failed to update: ' . $e->getMessage());
    }
    audit_log('personnel', 'update', 'shift_assignment', $id, "Updated shift assignment #{$id} status to '{$status}'", [
        'new_status' => $status
    ]);
    json_response(['success' => true]);
}

/**
 * Custom json_response that supports status code parameter.
 */
function json_response_code(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
