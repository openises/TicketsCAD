# CJIS Security Policy — TicketsCAD Posture Brief

**Audience:** compliance officer, security auditor, agency CIO evaluating TicketsCAD for use with Criminal Justice Information (CJI).
**Scope:** TicketsCAD NewUI v4.0 as of 2026-06-15.
**Companion docs:** [SECURITY-POLICY.md](SECURITY-POLICY.md) · [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md) · [AUDIT-LOG-REFERENCE.md](AUDIT-LOG-REFERENCE.md)

---

## Disclaimer

This brief is **technical documentation**, not legal certification. CJIS compliance ultimately depends on the operational deployment, the agency's policies, and your CJIS Systems Officer's (CSO's) sign-off. TicketsCAD provides the technical controls; your agency provides the deployment posture (TLS configuration, network segmentation, physical security, personnel screening, etc.) that the controls live within.

**TicketsCAD has not been formally certified by any CSA, FBI CJIS Auditor, or accreditation body.** This document maps our shipped controls against CJIS Security Policy v6.0 (December 2024) so your CSO can perform their own assessment.

If your CSA / state agency has additional requirements above and beyond CJIS Policy v6.0, those are out of scope here.

---

## Quick-reference: which CJIS sections we touch

| CJIS Policy Section | What it covers | TicketsCAD relevance |
|---|---|---|
| §5.1 | Information Exchange Agreements | Out of scope (org-level) |
| §5.2 | Security Awareness Training | Org-level; we provide [TRAINING-CURRICULUM.md](TRAINING-CURRICULUM.md) for the application side |
| §5.3 | Incident Response | We log; you respond. See [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md) |
| §5.4 | **Auditing and Accountability** | **Direct: audit_log, retention, query export.** Detailed below |
| §5.5 | **Access Control** | **Direct: RBAC, session timeout, lockout.** Detailed below |
| §5.6 | **Identification and Authentication** | **Direct: TOTP, password policy, lockout, backup codes.** Detailed below |
| §5.7 | Configuration Management | Org-level. We provide spec-driven change discipline and audit trails |
| §5.8 | Media Protection | Org-level (your backup media, your encryption at rest) |
| §5.9 | Physical Protection | Org-level (your data center) |
| §5.10 | **Systems and Communications Protection** | **Direct: encryption in transit, field encryption.** Detailed below |
| §5.11 | Formal Audits | We provide the artifacts; auditor runs the audit |
| §5.12 | Personnel Security | Org-level |
| §5.13 | **Mobile Devices** | **Direct: PWA, OwnTracks token model.** Detailed below |

This brief details Sections 5.4, 5.5, 5.6, 5.10, and 5.13 — the ones with substantial TicketsCAD-side controls.

---

## §5.4 — Auditing and Accountability

### CJIS requirement summary

CJIS requires **automated audit logging** of significant security events, including:

- Successful and failed authentication
- Privilege use / RBAC changes
- Access to CJI
- System startup / shutdown
- Audit log access itself

Retention is **minimum 365 days**.

### TicketsCAD implementation

| Control | TicketsCAD evidence |
|---|---|
| **Automated audit log** | [`audit_log`](AUDIT-LOG-REFERENCE.md) table — OCSF-aligned schema. Every state-changing action writes a row via `audit_log($category, $action, $entity_type, $entity_id, $details, $context)` |
| **Auth events logged** | `audit_log` category=`auth`: every login attempt (success/fail), 2FA verify, password change, password reset, lockout trigger, session destroy |
| **RBAC changes logged** | category=`admin`: role create/edit/delete, grant create/revoke, permission change, super-admin promote/demote |
| **CJI access logged** | category=`data`: per-incident view (when the incident has a sensitivity label), patient-info view, address-detail view (per-field perms) |
| **System events** | category=`security`: TFA enrollment / disable, encryption key rotation, password policy change |
| **Audit log access logged** | category=`admin`, action=`view_audit_log` — every admin who opens the audit log is themselves audit-logged |
| **365-day retention** | Default 365 days, configurable in Settings → Audit & Compliance. **DO NOT set below 365 for CJIS deployments.** The `audit-log-trim` cron enforces retention |
| **Tamper resistance** | `audit_log` is append-only in code (no UPDATE / DELETE called from any endpoint). DB-level constraint: only the DB owner can DELETE; recommend revoking that grant from the application user (`REVOKE DELETE ON newui.audit_log FROM 'newui'@'localhost';`) |
| **Time synchronisation** | We require chrony/systemd-timesyncd on the VM. The CJIS audit-log timeline is only as good as the system clock. See [INSTALLATION-CHECKLIST.md](INSTALLATION-CHECKLIST.md) |
| **Export to SIEM** | Audit-log records can be exported via [`api/audit-log.php?action=export`](AUDIT-LOG-REFERENCE.md#export) in JSONL or CSV. Recommend a daily cron to a log aggregator (Splunk, ELK, Datadog) |

### Gaps / your responsibility

- **Off-box log shipping.** TicketsCAD writes the audit log to the local DB. CJIS auditors often want logs replicated to a separate, append-only system. **You must configure the SIEM export.**
- **Annual log review.** CJIS requires periodic audit-log review. Add this to your [MAINTENANCE-RUNBOOK.md](MAINTENANCE-RUNBOOK.md) quarterly cadence.

---

## §5.5 — Access Control

### CJIS requirement summary

- **Least privilege** access via role-based permissions
- **Account management** including disable, password rotation, periodic review
- **Session controls** including timeout, concurrent-session limits
- **Remote access** controls

### TicketsCAD implementation

| Control | TicketsCAD evidence |
|---|---|
| **Role-based access control** | 6 default roles, 65 permissions, custom-role-supported. Permissions are scoped per resource type (screen/widget/action/field). See [RBAC-GUIDE.md](RBAC-GUIDE.md) |
| **Least privilege** | Default roles are minimal-perms (Read-Only has 5 perms; Dispatcher has ~25; Super Admin has all 65). New users start at no-roles (must be assigned explicitly) |
| **Account disable** | Settings → User Accounts → Disable — sets `user.locked_until` to far-future, refuses login |
| **Force password change** | Phase 9 — `must_change_password` flag, auto-set on new-user creation and admin reset |
| **Session timeout** | Configurable per-role. Default 24 h; CJIS deployments should set ≤ 30 min for non-trusted networks. Settings → Identity & Security → Session Timeouts |
| **Idle session timeout** | Server-side check via `sm_is_session_valid()` on every API call; expired sessions return HTTP 401 |
| **Forced logout** | Admin can destroy any active session from Settings → Active Sessions; password reset cascades to invalidate every session for that user (Phase 73aa) |
| **Concurrent session count** | Visible in Settings → Active Sessions. No automatic limit, but admin can manually destroy excess sessions |
| **Remote access controls** | TLS 1.2+ for all HTTPS. Trusted CIDR feature allows extended sessions only from approved IP ranges (e.g. office LAN); from other IPs sessions are shorter and 2FA is enforced more aggressively |
| **Per-entity access control** | `allocates` table + RBAC permissions both gate per-resource access. A user can see incident X iff their role has `screen.incidents` AND (admin OR their group is allocated to incident X). See [ACCESS-CHAIN.md](ACCESS-CHAIN.md) |
| **Privilege use auditing** | Every privileged action (config change, role grant, user create, audit-log access) writes to `audit_log` |

### Gaps / your responsibility

- **Account periodic review.** Quarterly audit per [MAINTENANCE-RUNBOOK.md § Stale account scan](MAINTENANCE-RUNBOOK.md#4-stale-account-scan). Document in your CJIS audit evidence packet.
- **Concurrent-session limit.** Not enforced automatically. If your CSA requires it, file a feature request.

---

## §5.6 — Identification and Authentication

### CJIS requirement summary (Advanced Authentication track)

- **Multi-factor authentication** required for all CJI access from non-secure locations
- **Password complexity**: minimum 8 characters, change on first use, history check, periodic rotation
- **Failed-login lockout**: after N attempts, lock for at least 10 min

### TicketsCAD implementation

| Control | TicketsCAD evidence |
|---|---|
| **Multi-factor authentication** | TOTP (RFC 6238) via `inc/totp.php` + `inc/tfa.php`. Backup codes (8 single-use). Compatible with Aegis, 1Password, Authy, Microsoft Authenticator, Google Authenticator. Mandatory by role (Settings → Identity & Security → TFA Required Roles). |
| **TOTP one-time use** (Phase 73bb) | Used codes are tracked in `user_tfa.last_used_counter`. Replays within the slip window are rejected. RFC 6238 §5.2 compliant. |
| **2FA backup codes** | 8 single-use 8-digit codes per user, generated at enrollment, regeneratable. Each code is consumed on use. |
| **Remember device** | Optional cookie-based 2FA bypass for trusted devices. Bound to a fingerprint of UA + Accept-Language + /24 IPv4 prefix (Phase 73bb). Configurable window (default 30 days; CJIS recommend ≤ 7 days). |
| **Trusted CIDR** | Settings → Identity & Security → Trusted CIDRs. Sessions from approved IP ranges may use longer timeouts; from other IPs, 2FA is required even with "remember device" if the cookie doesn't match the IP prefix. |
| **Password minimum length** | Settings → Identity & Security → Password Policy → min length. Default 12 (above CJIS minimum of 8). |
| **Password complexity** | Configurable: require uppercase, lowercase, digit, symbol. Default: yes to all four. |
| **Password history** | `user_password_history` table; the new password can't match the last N hashes (default 5). |
| **Force change on first login** | `user.must_change_password` flag set on new-user creation. Admin reset also sets it. |
| **Password rotation** | Configurable interval (default 90 days). `password_changed_at` column tracks last change; a banner reminds users when rotation is due (snoozeable). |
| **Account lockout** | After N failed attempts (default 5) within a window (default 5 min), account locks for a duration (default 15 min). Above CJIS minimum (10 min). |
| **Hash algorithm** | bcrypt cost 12 (`password_hash()` PHP default). Legacy MD5/SHA1/plaintext hashes are auto-upgraded on first successful login. |
| **CSRF protection** | `csrf_token()` / `csrf_verify()` on all POST endpoints. Token rotates on login (Phase 73cc). |
| **Session ID regeneration** | `session_regenerate_id(true)` on login and on privilege change. Prevents session fixation. |
| **Force-logout on password change** | Phase 33 — all OTHER sessions for the user are destroyed when password changes; current session continues so the user isn't kicked out of the form. |

### Gaps / your responsibility

- **Personnel security** — verifying the human behind each account passes background checks is org-level.
- **Token escrow** — TFA secrets and backup codes are encrypted at rest with the TFA key; lose the TFA key, lose every TFA enrollment. Plan your key escrow per [SECURITY-POLICY.md](SECURITY-POLICY.md).

---

## §5.10 — Systems and Communications Protection

### CJIS requirement summary

- **Encryption in transit** for all CJI
- **Encryption at rest** for CJI (FIPS 140-2/-3 validated cryptography)
- **Boundary protection** (firewalls, segmentation)
- **Mobile device** protections

### TicketsCAD implementation

| Control | TicketsCAD evidence |
|---|---|
| **TLS in transit** | Required in production (the installation checklist refuses to call HTTP "production"). Apache TLS via Let's Encrypt or your internal CA. TLS 1.2+ recommended. |
| **HSTS** | `Strict-Transport-Security` header set by [`inc/security-headers.php`](../inc/security-headers.php) when running over HTTPS. |
| **Field encryption for HTTP fallback** | For deployments where TLS isn't viable (closed networks), the RSA proxy + Web Crypto API in the browser encrypt sensitive form fields client-side. See [`proxy/INSTALL-LINUX.md`](../proxy/INSTALL-LINUX.md). |
| **Encryption at rest — TFA secrets** | All TOTP secrets and backup-code blobs encrypted with AES-GCM via `tfa_encrypt()` / `tfa_decrypt()`. Key in `keys/tfa.key` (32 random bytes, file mode 0600). |
| **Encryption at rest — patient/contact PII** | The `ENC2:` blob format (RSA-wrapped AES-GCM) is available for any field via the field-encryption helper. Selective; opt-in per field. |
| **Database-level encryption** | TicketsCAD does NOT enforce DB-tablespace encryption — that's MariaDB-side configuration (encryption-at-rest plugin or LUKS at the OS layer). **You must configure this for CJIS.** |
| **Cryptography** | PHP openssl extension (FIPS-compliant when underlying OpenSSL is FIPS-validated). Web Crypto API in browser (browser-vendor compliance). |
| **Boundary protection** | Org-level — Apache reverse proxy, firewall rules, IP allowlisting all happen outside TicketsCAD. We provide vhost-level directory restrictions in the install checklist Apache config. |
| **Session encryption** | PHP session cookies marked `Secure`, `HttpOnly`, `SameSite=Lax` when served over HTTPS. |

### Gaps / your responsibility

- **OS-level disk encryption** (LUKS, BitLocker) — not configured by TicketsCAD. Strongly recommended for CJIS.
- **DB-level transparent data encryption** — MariaDB supports it; we don't configure it. Strongly recommended for CJIS.
- **Network segmentation** — TicketsCAD VM should not share a subnet with general-user workstations. Org-level.

---

## §5.13 — Mobile Devices

### CJIS requirement summary

- Mobile device management (MDM)
- Authentication on the device
- Encrypted transport
- Remote wipe capability

### TicketsCAD implementation

| Control | TicketsCAD evidence |
|---|---|
| **PWA installation** | Mobile UI installs as a Progressive Web App; same TLS + auth + RBAC as the desktop UI. No app-store download path. |
| **Per-member tokens (OwnTracks)** | OwnTracks devices authenticate with per-member tokens (server stores SHA-256 hash). Revocable instantly from Settings → User Accounts → row → OwnTracks → Revoke Token. |
| **OwnTracks fail-closed** | Phase 73v hardening: OwnTracks ingest rejects unauthenticated posts unless `owntracks_allow_anonymous=1` is explicitly set. |
| **Trusted CIDR for mobile** | Mobile devices outside the trusted CIDR are forced into shorter session timeouts and stricter 2FA. |
| **No client-side data persistence** | The PWA stores no CJI in localStorage / IndexedDB — every page rerenders from the server. Stolen-device exposure is limited to the open session, which can be killed remotely (Settings → Active Sessions → Destroy). |
| **HTTPS-only mobile traffic** | The PWA refuses to install over plain HTTP. |
| **Lock screen integration** | Org-level (phone OS configures this); TicketsCAD inherits whatever auth the OS enforces to unlock the device. |

### Gaps / your responsibility

- **MDM enrollment** — phones used to access CJI should be enrolled in your org's MDM. TicketsCAD doesn't provide MDM.
- **Remote wipe of the device** — that's the MDM's job. TicketsCAD can kill the session but can't reach the device's storage.

---

## Settings recommended for CJIS deployments

When in doubt, configure these tighter than the defaults:

| Setting | Default | CJIS-recommended |
|---|---|---|
| `password_min_length` | 12 | 12 (already meets) |
| `password_require_uppercase` | 1 | 1 |
| `password_require_lowercase` | 1 | 1 |
| `password_require_digit` | 1 | 1 |
| `password_require_symbol` | 1 | 1 |
| `password_history_count` | 5 | 10 |
| `password_rotation_days` | 90 | 90 |
| `login_lockout_attempts` | 5 | 5 |
| `login_lockout_window_minutes` | 5 | 5 |
| `login_lockout_duration_minutes` | 15 | 30 |
| `session_timeout_minutes` (Super Admin) | 1440 (24 h) | 30 |
| `session_timeout_minutes` (Dispatcher) | 1440 | 60 |
| `session_timeout_minutes` (other roles) | 1440 | 30 |
| `tfa_required_roles` | none | ALL roles (CJIS = all CJI access is MFA-protected) |
| `tfa_remember_days` | 30 | 7 |
| `audit_log_retention_days` | 365 | 365 (minimum; some CSAs require longer) |
| `owntracks_allow_anonymous` | 0 | 0 (must stay 0 for CJIS) |
| `owntracks_require_token` | 1 (if configured) | 1 |

---

## Audit evidence packet

When the CJIS auditor visits, hand them:

1. This document with your install-specific notes appended.
2. The current `audit_log` retention setting screenshot.
3. A sample audit-log export covering the last 30 days (Settings → Audit & Compliance → Export).
4. The user-account list with last-login timestamps (proves periodic review).
5. The role/permission matrix screenshot (Settings → Roles & Permissions).
6. The TLS certificate chain (`openssl s_client -connect cad.example.org:443 -showcerts < /dev/null`).
7. Your MDM enrollment proof for mobile devices.
8. Your encryption-key escrow procedure (your written process, not just the [SECURITY-POLICY.md](SECURITY-POLICY.md) reference).
9. Your incident response runbook (your custom one; [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md) is a starting point).
10. Your DR drill log from the most recent quarter ([MAINTENANCE-RUNBOOK.md § Disaster recovery drill](MAINTENANCE-RUNBOOK.md#1-disaster-recovery-drill)).

---

## What this brief is NOT

- It is **not a certificate of compliance**. There's no FBI seal on this PDF.
- It is **not a substitute for your CSO's risk assessment**. They have to sign off.
- It is **not exhaustive**. CJIS Policy v6.0 is 250+ pages; this brief covers the sections with substantial TicketsCAD-side controls and points you at where the gaps are.
- It is **dated 2026-06-15**. CJIS Policy revisions happen periodically. Re-audit against the current version before each annual recert.

---

## Where to read deeper

| Topic | Doc |
|---|---|
| Security policy implementation details | [SECURITY-POLICY.md](SECURITY-POLICY.md) |
| Incident response | [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md) |
| Encryption keys (storage, rotation, escrow) | [SECURITY-POLICY.md](SECURITY-POLICY.md) |
| Audit log schema | [AUDIT-LOG-REFERENCE.md](AUDIT-LOG-REFERENCE.md) |
| RBAC details | [RBAC-GUIDE.md](RBAC-GUIDE.md) |
| Per-resource access control | [ACCESS-CHAIN.md](ACCESS-CHAIN.md) |
| Mobile UI security | [help.php → Mobile](../help.php) |
