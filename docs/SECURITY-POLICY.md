# Tickets CAD NewUI — Security Policy

**Document version:** 1.0
**Effective date:** 2026-06-08 (Phase 10 ship)
**Audience:** System administrators, CJIS auditors, internal security review
**Scope:** Tickets CAD NewUI v4 only. Does NOT cover the legacy v3.44 install (separate codebase).

This document describes the security controls implemented in the Tickets CAD NewUI v4 application and maps them to the FBI CJIS Security Policy v6.0 (aligned with NIST SP 800-53 Rev 5 and NIST SP 800-63B). It is intended as evidence for a CJIS audit and as a self-test reference for system administrators.

A complementary admin tool — the **Security Compliance Dashboard** (`/compliance-dashboard.php`) — shows the live values of the settings described here and badges them against CJIS recommendations.

---

## Scope and limitations

This policy covers **application-level** controls implemented by Tickets CAD NewUI. It does **not** address:

- **Physical security** of the host (CJIS §5.9). Customer's organisational responsibility.
- **Personnel security** (background checks, security training; CJIS §5.12). Customer responsibility.
- **Media protection** (CJIS §5.8). Customer responsibility.
- **Network infrastructure** (firewall, VLAN segmentation, IDS; CJIS §5.10). Customer responsibility.

The customer organization (the agency operating Tickets CAD) is responsible for ensuring the deployment environment satisfies the controls Tickets CAD cannot enforce.

---

## 1. Identification and Authentication (IA-family)

### 1.1 Password policy (IA-5)

| Control | Setting | Default | CJIS expected | Enforced where |
|---|---|---|---|---|
| Minimum length | `password_min_length` | 8 | ≥ 8 | `inc/password-policy.php::pw_validate()` |
| Composition rules | n/a | (none) | (none — NIST 800-63B reversed this) | n/a |
| Forced periodic rotation | n/a | (disabled) | (disabled — NIST 800-63B) | n/a |
| Rotation reminder | `password_rotation_reminder_days` | 180 days | (suggestion only) | `inc/password-policy.php::pw_needs_rotation()` |
| Reminder snooze | `password_rotation_snooze_days` | 10 days | n/a | API: `snooze_password_reminder` |
| Reuse prevention (history) | `password_history_count` | 10 | ≥ 10 | `inc/password-policy.php::pw_validate()` history check |
| Initial authenticator change required | `force_pw_change_for_new_users` | ON | required (IA-5) | login.php + profile.php (Phase 9) |
| Authenticator at rest | bcrypt cost=12 | (fixed) | strong cryptography | `hash_new_password()` |
| Authenticator in transit | TLS 1.2+ | (deployment) | required | host configuration |
| Feedback during entry | masked (`<input type="password">`) | (fixed) | required | UI templates |

Validation is centralised in `inc/password-policy.php` and enforced consistently across:

- User self-change (`api/profile.php` action=change_password)
- Admin create user (`api/config-admin.php` POST users)
- Admin reset user password (`api/login-security.php` action=reset_password)
- Forced first-login change (Phase 9; same endpoint as user self-change)

### 1.2 Account lockout (AC-7)

| Control | Setting | Default | CJIS expected |
|---|---|---|---|
| Max failed attempts | `lockout_max_attempts` | 5 | ≤ 5 |
| Counting window | `lockout_window_minutes` | 15 | (no requirement) |
| Lockout duration | `lockout_duration_minutes` | 30 | ≥ 10 |
| Enumeration-resistant errors | (fixed) | "Invalid username or password" | required (SI-11) |

Lockout is enforced in `inc/login-security.php::ls_is_locked()` before any password verification. Lockout state is keyed on username, IP, and time window so an attacker cannot trivially enumerate accounts.

### 1.3 Multi-factor authentication (IA-2)

| Control | Implementation | CJIS expected |
|---|---|---|
| MFA method | TOTP (Time-based One-Time Password) via RFC 6238 | AAL2 acceptable (or higher) |
| Enrollment UI | `profile.php` Security tab | per user |
| Backup codes | 8-digit single-use codes generated at enrollment | for AAL2 recovery |
| Remember-device | configurable expiry, IP/CIDR-scoped trusted networks | acceptable |
| Required for CJI access | configurable per role | required for non-physically-secure access |

TOTP enrollment uses QR code (otpauth URI) compatible with Google Authenticator, Authy, Microsoft Authenticator, etc. The shared secret is stored encrypted using AES-GCM (`inc/tfa.php`). At-rest key is held outside the database in `../keys/tfa.key` so a DB-only compromise does not yield enrolled secrets.

### 1.4 Session management (AC-12, IA-11)

| Control | Setting | Default | CJIS expected |
|---|---|---|---|
| Idle timeout | `session_timeout_minutes` | 480 (8h) | ≤ 30 for CJI access |
| Forced re-auth on password change | (fixed) | yes — kills other sessions | required |
| Session ID regeneration on login | (fixed) | yes | required |
| Cookies: `HttpOnly` | (fixed) | yes | required |
| Cookies: `Secure` (when HTTPS) | (fixed) | yes | required |
| Cookies: `SameSite=Lax` | (fixed) | yes | recommended |

**Important:** the default `session_timeout_minutes=480` is suitable for dispatcher console use (8-hour shift) but **exceeds the CJIS 30-minute recommendation for CJI-handling sessions**. Customers handling CJI directly should lower this setting. The Security Compliance Dashboard flags this as a warning if the value exceeds 30.

### 1.5 Authenticator change on first use (IA-5)

Phase 9 (shipped 2026-06-08) added the "must change password at next login" mechanism. When an admin creates a new user or resets an existing user's password, `user.must_change_password` is set to 1. On that user's next login, every page redirects to `profile.php?force_pw=1` until the user picks a new password. API endpoints other than `/api/profile.php` return HTTP 423 (Locked) with `{code: force_pw_change}`. Logout is always allowed.

System-wide default behavior is controlled by `force_pw_change_for_new_users` (default ON). Admin can override per user via the User Accounts form.

---

## 2. Audit and Accountability (AU-family)

### 2.1 Audit events captured (AU-2)

Every authentication-relevant event is recorded in `newui_audit_log` with:

- `category` (e.g., `auth`, `personnel`, `config`)
- `verb` (e.g., `login`, `login_failed`, `password_change`, `sessions_invalidated`)
- `target_type` and `target_id` (e.g., `user` / user id)
- `details` (free-text, includes context like IP, user-agent, reason for admin actions)
- `created_at` (datetime)
- `actor_user_id` (who performed the action)

Specific events captured:

| Event | Category | Verb |
|---|---|---|
| Successful login | auth | login |
| Failed login (bad credentials) | auth | login_failed |
| Login blocked (lockout) | auth | login_blocked |
| Account disabled login attempt | auth | login |
| Session expired forced re-auth | auth | session_expired |
| Password changed (user) | auth | password_change |
| Password changed (forced by admin) | auth | password_change | (details note `(forced by admin)`) |
| Admin reset another user's password | admin | update | (details include `reason` field) |
| Other sessions invalidated on password change | auth | sessions_invalidated |
| 2FA enrolled | auth | tfa_enrolled |
| 2FA verification used backup code | auth | tfa_backup_used |
| 2FA disabled | auth | tfa_disabled |
| Account created | personnel | create |
| Account disabled / re-enabled | personnel | update |
| RBAC role granted / revoked | rbac | grant / revoke |
| Language preference change | i18n | set_language |
| Rotation reminder snoozed | auth | password_rotation_snoozed |

### 2.2 Audit log access (AU-9)

- Audit log is admin-readable via Settings → Audit Log UI.
- The table itself is write-only from the application; no code path allows arbitrary UPDATE or DELETE of audit rows.
- Audit retention is unbounded by default (no automated purge). Customer is responsible for archiving and rotation if storage becomes a concern.

### 2.3 Admin actions require justification (AU-2, IA-5)

Phase 10 added a **required `reason` field** on admin password resets. The reason is captured verbatim in the audit log entry's details JSON and is visible to subsequent auditors. Minimum 3 characters, maximum 2000.

---

## 3. Access Control (AC-family)

### 3.1 Role-based access control (AC-2, AC-3, AC-6)

Tickets CAD NewUI v4 uses RBAC v2 (see `docs/RBAC-GUIDE.md`):

- **63 canonical permissions** across screen, widget, action, field categories
- **6 default roles**: Super Admin, Org Admin, Dispatcher, Read-Only, Field Unit, Communications
- **Scope-aware grants**: global / org / team / self / delegate
- **Time-bound grants** with `expires_at`
- **Privilege-escalation guard** prevents a user from granting permissions they don't hold
- **Delegation depth** tracking

Permissions are checked at every API endpoint via `rbac_can($code, $context)`. API endpoint enforces fail-closed when the v2 schema is in place — a user with zero active grants is denied 403 at the API edge.

### 3.2 Account termination (AC-2)

Disabling a user account:

1. Admin sets `user.can_login = 0` via Settings → User Accounts → Edit user → uncheck "Can Login"
2. The user immediately loses ability to log in (login.php rejects)
3. Their existing sessions can be terminated separately via Settings → Login Settings → Active Sessions → force logout

A future phase (Phase 11+) will add an "emergency lockout" button on the compliance dashboard so a compromised super-admin can be killed without DB access.

### 3.3 Session-based controls

- Session ID is regenerated on login (anti-fixation)
- Concurrent sessions are tracked in `active_sessions` table
- On password change, all OTHER sessions for that user are killed (Phase 8d)
- Admin can force-logout any specific session from the Active Sessions table

---

## 4. System and Information Integrity (SI-family)

### 4.1 Error handling (SI-11)

- All API endpoints suppress `display_errors` (no PHP warnings leak to the response)
- All endpoints catch exceptions and return structured JSON errors
- Authentication failures use enumeration-resistant messages
- Stack traces and SQL details are never returned in HTTP responses; they go to the Apache error log

### 4.2 CSRF protection

- All state-changing endpoints (POST, PUT, DELETE) require a valid CSRF token
- Token is bound to the session ID
- Token is verified via `csrf_verify()` before any database mutation
- Form submissions and AJAX calls both supported (X-CSRF-Token header)

### 4.3 Cross-Site Scripting (XSS) protection

- All user-supplied output is escaped via `e()` (htmlspecialchars wrapper)
- Templates use explicit `<?php echo e(...); ?>` for user data
- Content-Security-Policy header set (`inc/security-headers.php`)
- X-Content-Type-Options: nosniff
- X-Frame-Options: SAMEORIGIN

### 4.4 SQL Injection protection

- 100% of application database queries use PDO prepared statements via `db_query()`, `db_fetch_all()`, `db_fetch_one()`, `db_fetch_value()`
- No string concatenation of user input into SQL
- A SonarQube scan runs against every commit; SQLi findings are gated to zero

### 4.5 HTTPS enforcement (SC-8, SC-13)

- TLS termination handled at the deployment layer (Apache, nginx, Cloudflare, etc.)
- `require_https` system setting can be enabled to bounce HTTP requests to HTTPS
- HSTS header set when enabled
- All cookies marked `Secure` when served over HTTPS

---

## 5. Cryptography (SC-13, SC-28)

### 5.1 Algorithms used

| Use | Algorithm | Key length | At-rest |
|---|---|---|---|
| Password hashing | bcrypt | cost = 12 | column `user.passwd` |
| TOTP secret encryption | AES-256-GCM | 256-bit | column `user_tfa.tfa_secret`, key at `../keys/tfa.key` |
| Field encryption (RSA hybrid) | RSA-OAEP + AES-256-GCM | 2048-bit RSA / 256-bit AES | columns marked encrypted, key at `../keys/private.pem` |
| TLS | per-deployment | per-deployment | n/a |

### 5.2 Key lifecycle

Documented in `docs/ENCRYPTION-KEY-LIFECYCLE.md`. Key features:

- RSA keypair is auto-generated on first run
- Re-key via Settings → Field Encryption → "Regenerate Keys" archives the old keypair
- TFA key is separated from DB password (own file, `../keys/tfa.key`) so DB password rotation doesn't break enrollments

---

## 6. Recovery

See `docs/SECURITY-RECOVERY-GUIDE.md` for full procedures:

- Forgotten admin password (single-admin deployment)
- Forgotten user password (admin reset with required reason → user forced to change)
- Lost 2FA authenticator (backup codes or admin force-disable)
- Account lockout (admin unlock or wait for timer)
- Encryption key loss / compromise

---

## 7. Out-of-scope (organisational responsibility)

The following CJIS controls are the customer's responsibility:

- **AC-1** Access Control Policy and Procedures (organisational policy)
- **AT-family** Awareness and Training (personnel training)
- **CP-family** Contingency Planning (BCP / DR)
- **IR-family** Incident Response
- **MA-family** Maintenance
- **MP-family** Media Protection
- **PE-family** Physical and Environmental Protection
- **PS-family** Personnel Security
- **SA-family** System and Services Acquisition
- **SC-1, SC-2** Networking architecture (firewall, segmentation, ingress filtering)
- **CA-family** Security Assessment and Authorization (the ATO process)

Tickets CAD provides the technical primitives (authentication, audit log, encryption); the customer agency wraps them in operational policy.

---

## 8. Audit-readiness checklist

When preparing for a CJIS audit, the administrator should:

1. ✅ Open Settings → System → **Security Compliance Dashboard** and confirm all green badges (or document any yellow/red with justification).
2. ✅ Export the audit log: Settings → Audit Log → CSV Export (for the audit period). Auditor will review for completeness and consistency.
3. ✅ Confirm all super-admin and admin accounts have 2FA enrolled.
4. ✅ Confirm `force_pw_change_for_new_users` is ON.
5. ✅ Confirm password history is ≥ 10.
6. ✅ Confirm session timeout matches the customer's CJI-handling policy (≤ 30 min if CJI is in play).
7. ✅ Confirm this document (`docs/SECURITY-POLICY.md`) is current and applicable to the install.
8. ✅ Demonstrate a failed-login lockout cycle to the auditor.
9. ✅ Demonstrate an admin password reset with the required reason field and the resulting audit log entry.
10. ✅ Demonstrate the rotation reminder flow (banner + snooze + actual change).

---

## 9. Phase ship history (relevant to this policy)

| Phase | Date | What shipped |
|---|---|---|
| Phase 6 (security audit) | 2026-04-11 | 14 audit findings closed; F-007 SSE long tail; Hour-2 auth/session (CSP, HSTS preload, session expiry on API); Hour-3 TFA rate-limit |
| Phase 8 (i18n) | 2026-06-08 | Translation framework |
| Phase 8d (session security) | 2026-06-08 | Password change kills other sessions; admin-reset passwd column fix |
| **Phase 9 (force pw change on first login)** | **2026-06-08** | `must_change_password` flag + system + per-user toggle; forced redirect flow |
| **Phase 10 (CJIS hardening)** | **2026-06-08** | Configurable password policy; admin reset reason; password history; rotation reminder; this document; compliance dashboard |

---

## 10. Document control

| Field | Value |
|---|---|
| Author | Eric Osterberg |
| Reviewers | (sign on review) |
| Next review | Annually OR on major control change |
| Repository | `openises/TicketsCAD` |
| Path in repo | `newui-dev/newui/docs/SECURITY-POLICY.md` |

Changes to this policy require a documented review and a version bump.
