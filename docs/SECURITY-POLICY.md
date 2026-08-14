# Tickets CAD NewUI — Security Policy

**Document version:** 1.2
**Effective date:** 2026-07-29 (Phase 127b — SBOM Author Signature; signing key added to the cryptographic inventory, §5.1 and §5.3)
**Previous versions:** 1.1, 2026-07-29 (Phase 127 ship — software supply chain / SBOM); 1.0, 2026-06-08 (Phase 10 ship)
**Audience:** System administrators, CJIS auditors, internal security review
**Scope:** Tickets CAD NewUI v4 only. Does NOT cover the legacy v3.44 install (separate codebase).

This document describes the security controls implemented in the Tickets CAD NewUI v4 application and maps them to the FBI CJIS Security Policy v6.0 (aligned with NIST SP 800-53 Rev 5 and NIST SP 800-63B). It is intended as evidence for a CJIS audit and as a self-test reference for system administrators.

For the threat model, trust boundaries, and a mapping against CIS Controls v8
and the CIS Microsoft IIS 10 Benchmark (frameworks this document does not
cover), see [`docs/security/architecture.md`](security/architecture.md). For
the maintenance cadence — how often dependencies, cryptographic currency, and
static analysis findings get re-checked — see
[`docs/security/maintenance.md`](security/maintenance.md).

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

### 4.6 Web-server exposure of non-public directories (SC-7, AC-3)

The web root is the application root, so the web server publishes every
directory in the tree unless configured not to. On 2026-07-30 this was confirmed
to have disclosed a complete database backup over unauthenticated HTTP on a live
install — see
[`security/advisory-2026-07-30-exposed-directories.md`](security/advisory-2026-07-30-exposed-directories.md).

Four independent controls, because no single one covers every deployment:

- **Shipped deny rules** — root `.htaccess` plus `sql/`, `tools/` `.htaccess` and
  `web.config`, denying `backups/`, `inc/`, `sql/`, `tools/`, `tests/`, `specs/`,
  `coordination/`, `drafts/`, `apache/`, `vendor/`, `keys/` and `services/`
  (except the two mesh-bridge scripts the Mesh Console hands out).
- **This is Apache-only.** **nginx never reads `.htaccess`; neither does IIS.**
  Those deployments must apply
  [`nginx/ticketscad-hardening.conf`](nginx/ticketscad-hardening.conf) or the IIS
  equivalent — see [`WEB-SERVER-HARDENING.md`](WEB-SERVER-HARDENING.md). An
  operator who assumes the shipped file protects them is not protected.
- **CLI-only guards** — every script under `sql/` and `tools/` refuses to execute
  under a web SAPI (`403 CLI only`) before loading configuration or touching the
  database. Server-independent; gated by `tests/test_web_exposure_hardening.php`.
- **Backups outside the web root** — `BACKUP_DIR` is platform-aware: `../backups`
  on POSIX, a sibling of the install directory on the same reasoning as
  `FE_KEYS_DIR`, and `%ProgramData%\TicketsCAD\backups` on Windows, where
  `dirname()` of a stock install is `C:\inetpub\wwwroot` — the physical path of
  IIS's Default Web Site (v4.2.3 regression, corrected in v4.2.4; reported by
  @rjonesbsink). Archives written by older versions, in either historical
  location, are left in place, stay listable, are never pruned, and are reported
  by the health check until moved.
  The destination is verified rather than assumed: `backup_dir_exposure()`
  grades the local file layout and `health_check_backup_probe()` proves
  reachability with a random canary served back over HTTP on the default ports.
  Where neither settles it, the Status page prints what the check could not see
  instead of reporting success.

Self-verification: `health_check_web_exposure()` probes `backups/`, `sql/` and
`tools/` over HTTP from the install itself and reports on Settings → System Health;
`health_check_backups()` reports archives found in any served directory.

---

## 5. Cryptography (SC-13, SC-28)

### 5.1 Algorithms used

| Use | Algorithm | Key length | Key custody / at-rest |
|---|---|---|---|
| Password hashing | bcrypt | cost = 12 | column `user.passwd` |
| TOTP secret encryption | AES-256-GCM | 256-bit | column `user_tfa.tfa_secret`, key at `../keys/tfa.key` |
| Field encryption (RSA hybrid) | RSA-OAEP + AES-256-GCM | 2048-bit RSA / 256-bit AES | columns marked encrypted, key at `../keys/private.pem` |
| **SBOM Author Signature** | **ECDSA P-256 (prime256v1) with SHA-256** | **256-bit (≈128-bit security)** | **Per-project, maintainer-held. Private key OUTSIDE this repository — see §5.3. Public key published in-repo as `SBOM-signing-key.pub.pem`.** |
| TLS | per-deployment | per-deployment | n/a |

All four are current under NIST SP 800-131A Rev. 2. ECDSA P-256 with SHA-256 is
approved under FIPS 186-5, the Digital Signature Standard the CISA 2026 SBOM
guidance names as an acceptable authority for signature algorithm choice.

Ed25519 would otherwise be preferred for the SBOM signature and was rejected on
evidence, not taste: PHP's `openssl_sign()` uses the streaming `EVP_Sign*` API,
which cannot sign with Ed25519 (`operation not supported for this keytype`), and
`ext-sodium` is not enabled in the maintainer's PHP runtime. Choosing it would
mean a future maintainer could not re-sign a release on a default install.

### 5.2 Key lifecycle (per-install encryption keys)

Documented in `docs/ENCRYPTION-KEY-LIFECYCLE.md`. Key features:

- RSA keypair is auto-generated on first run
- Re-key via Settings → Field Encryption → "Regenerate Keys" archives the old keypair
- TFA key is separated from DB password (own file, `../keys/tfa.key`) so DB password rotation doesn't break enrollments

### 5.3 SBOM signing key lifecycle (project-level, not per-install)

This key is different in kind from the ones above. Those are generated on each
operator's server and protect that operator's data. This one belongs to the
project, exists once, and its only job is to let a stranger confirm that the
SBOM they are holding is the one we published.

| | |
|---|---|
| **Algorithm** | ECDSA on NIST P-256 (`prime256v1`), SHA-256 digest — "ECDSA-P256-SHA256" |
| **Format** | PKCS#8 PEM, unencrypted at rest, protected by filesystem permissions |
| **Held by** | Eric Osterberg, the project maintainer, personally |
| **Private key location** | A user-only file in the maintainer's private secrets directory (`~/.secrets/`), on the maintainer's workstation. **Outside this repository.** The path is deliberately not repeated in published documentation, and the key value appears nowhere — not in git, not in CI, not in any document, not in chat. |
| **Access control** | Filesystem ACL restricted to the maintainer's account only (inherited permissions removed; SYSTEM and Administrators entries removed) |
| **Public key** | `SBOM-signing-key.pub.pem` at the application root, committed and shipped in the release snapshot |
| **Public key SHA-256 fingerprint** | `XRcJ3AwAm0OzSzjmU8KWkknftutwY36a6z7st2YrU0g=` (SHA-256 over the DER SubjectPublicKeyInfo, base64) |
| **Backup** | The maintainer's responsibility, offline. Losing it is recoverable (see rotation); disclosing it is not. |
| **In CI** | Never. CI verifies signatures with the public key; it does not sign. Releases are signed on the maintainer's workstation. |

`.gitignore` blocks `*.pem`, `*.key` and `*.key.pem` and carries an explicit
exception for `SBOM-signing-key.pub.pem` only, so a stray private key dropped
into the tree stays untracked. `tests/test_sbom.php` additionally fails if any
private key becomes tracked, or if the published key file contains private
material.

#### Signing a release

```bash
php tools/generate-sbom.php --sign-key=<path to the private key>
```

The generator refuses to sign with a key that does not match the published
public key, refuses any key that is not EC P-256, and verifies its own output
against the published public key before reporting success. It will not
re-sign an unchanged SBOM whose existing signature still verifies, so signed
regeneration is byte-identical.

#### How a third party verifies a signature

Needs only the three files that ship with every release, and OpenSSL:

```bash
base64 -d SBOM.cdx.json.sig > sbom.sig
openssl dgst -sha256 -verify SBOM-signing-key.pub.pem -signature sbom.sig SBOM.cdx.json
# -> Verified OK
```

Or, with no OpenSSL command line: `php tools/generate-sbom.php --verify`.

The signature covers the exact bytes of `SBOM.cdx.json`. Any modification —
including reformatting the JSON — invalidates it, which is the point.

A recipient who wants assurance that `SBOM-signing-key.pub.pem` is really ours,
and not a substitute shipped alongside a substituted SBOM, should compare its
fingerprint against the value recorded above and in the SBOM's own
`ticketscad:signature-public-key-sha256` metadata property, obtained through a
channel they trust independently of the download.

#### Rotation

Rotate every **2 years**, or immediately on suspected compromise, or when the
maintainer role changes hands.

1. Generate a new keypair:
   `openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -pkeyopt ec_param_enc:named_curve -out <new private key path>`
2. Restrict it to the maintainer's account only. On Windows:
   `icacls <path> /inheritance:r /grant:r "%USERNAME%":F`, then remove the
   SYSTEM and Administrators entries.
3. Export the public half over the committed one:
   `openssl pkey -in <new private key> -pubout -out SBOM-signing-key.pub.pem`
4. Re-sign: `php tools/generate-sbom.php --sign-key=<new private key>`
5. Commit the new public key, the SBOM and the signature together, and note the
   new fingerprint in this section and in the release notes.
6. Destroy the old private key. Keep the old *public* key in git history so
   previously published releases remain verifiable.

There is no revocation infrastructure and none is claimed. Trust in this key
comes from it being published in the repository, not from a certificate
authority or a transparency log.

#### If the private key is compromised

Treat it as a supply-chain incident, not a routine rotation.

1. Rotate immediately, per the steps above.
2. Announce it: state the date of compromise if known, the old fingerprint, and
   the new one. Say plainly that signatures made with the old key can no longer
   be trusted to prove authorship, and that recipients should re-fetch and
   re-verify.
3. Re-sign and re-publish the SBOM for every supported release.
4. Record the incident as a dated entry in the project's security review log, and
   summarise it in `CHANGELOG.md` for the release that carries the new key.

The blast radius is bounded and worth stating honestly: this key signs an
inventory document. It does not sign code, releases, or updates, and it grants
no access to anything. Someone holding it could publish a convincing but false
ingredients list for TicketsCAD. They could not modify the software, and
`git` remains the authority on what the code actually is.

---

## 6. Software supply chain (SA-family, SR-family)

### 6.1 Software Bill of Materials

TicketsCAD NewUI publishes an SBOM with every release, built to the **2026
Minimum Elements for a Software Bill of Materials (SBOM)** — joint guidance
from CISA, NSA, FBI and international partners, published 2026-07-29, which
updates and replaces the 2021 NTIA minimum elements.

| Artifact | Format | Path |
|---|---|---|
| Machine-readable SBOM | CycloneDX 1.6 (ECMA-424) | `SBOM.cdx.json` |
| Human-readable SBOM | plain text | `SBOM.txt` |
| SBOM Author Signature | detached, ECDSA P-256 / SHA-256, base64 | `SBOM.cdx.json.sig` |
| Signature verification key | public key, PEM | `SBOM-signing-key.pub.pem` (see §5.3) |
| Generator | PHP, no external dependencies | `tools/generate-sbom.php` |
| Conformance tests | 63 assertions (60 in a published tree — three inspect the release script, which is not shipped) | `tests/test_sbom.php` |

**Status against the guidance: 17 of 17 data fields, 6 of 6 practices**, over 56
components. Every field that is not stated for a component is explicitly
declared unknown with a reason; nothing is withheld. The generator refuses to
emit an SBOM in which any component data field is silently absent, so this
cannot quietly regress.

**Why CycloneDX.** The guidance names SPDX and CycloneDX as the two widely
used formats and expresses no preference between them. CycloneDX 1.6 was
chosen because it has a native field for every one of the 17 minimum data
fields — including `metadata.lifecycles` for SBOM Generation Context and
`omniborId`/`swhid` for Component Identifiers — so no element has to be
smuggled into a free-text extension.

**Coverage.** PHP Composer dependencies with their transitive relationships,
browser libraries vendored into the repository, browser libraries loaded from
a CDN at runtime, optional Python service dependencies, third-party source
ported into this tree, and optional container base images. Excluded: non-code
assets, and the operator-supplied platform (PHP runtime, web server,
database engine) — those belong in the operator's own deployment SBOM.

### 6.2 Anti-rot controls

An SBOM that was accurate once and stale afterwards is worse than none,
because it asserts a currency it does not have. Three gates prevent that:

| Gate | Where | Effect |
|---|---|---|
| CI check | `.github/workflows/qa.yml` | Every push fails if `SBOM.cdx.json` no longer matches the dependency set or `VERSION`. |
| Release gate | `tools/release-snapshot.sh` step 0 | A release cannot be cut against a stale SBOM. |
| Test suite | `tests/test_sbom.php` | Runs with `tools/test_all.php`; also asserts no component states a version it cannot evidence. |

Versions of vendored browser libraries are **detected at generation time** by
matching the version banner inside the shipped file, never hardcoded. If a
library is upgraded, the SBOM follows automatically; if a banner disappears,
the component degrades to an explicit "unknown" rather than reporting a stale
value.

### 6.3 Dependency currency

`.github/dependabot.yml` watches Composer, GitHub Actions, and the Meshtastic
Python service weekly. Vendored browser libraries under `assets/vendor/` have
no package manifest, so Dependabot cannot watch them; they are reviewed
manually against the SBOM. **This is a known gap** — the SBOM makes it
visible rather than hiding it.

### 6.4 Known limitations (stated, not hidden)

| Element | Status |
|---|---|
| SBOM Author Signature | **Met.** Detached ECDSA P-256 / SHA-256 signature, verifiable by anyone against the published public key (§5.3). No revocation infrastructure exists; trust in the key comes from its publication in this repository, not from a certificate authority. |
| Component Hash Value | Present for artifacts we actually ship. Explicitly marked unknown for packages installed at deploy time (Composer, pip, container images), where the SBOM author has no artifact to hash. |
| Component Producer | Stated for 42 of 56 components. Explicitly marked unknown for the ten PyPI packages and the four container base images, where the producer is whoever controls that name in the registry at install time and cannot be evidenced from this tree. |
| Component Dependency Relationship | A full transitive graph for the 31 Composer packages, from `composer.lock`. Explicitly marked unknown for the other 25 components: no lockfile in this repository records what they themselves depend on. Their relationship *to* the application is recorded. |
| Component Version | Explicitly marked unknown for four vendored files that carry no version string and are tracked by no manifest. |
| Component License | Explicitly marked unknown where the shipped artifact declares none. |

---

## 7. Recovery

See `docs/SECURITY-RECOVERY-GUIDE.md` for full procedures:

- Forgotten admin password (single-admin deployment)
- Forgotten user password (admin reset with required reason → user forced to change)
- Lost 2FA authenticator (backup codes or admin force-disable)
- Account lockout (admin unlock or wait for timer)
- Encryption key loss / compromise

---

## 8. Out-of-scope (organisational responsibility)

The following CJIS controls are the customer's responsibility:

- **AC-1** Access Control Policy and Procedures (organisational policy)
- **AT-family** Awareness and Training (personnel training)
- **CP-family** Contingency Planning (BCP / DR)
- **IR-family** Incident Response
- **MA-family** Maintenance
- **MP-family** Media Protection
- **PE-family** Physical and Environmental Protection
- **PS-family** Personnel Security
- **SA-family** System and Services Acquisition — *partially addressed since
  Phase 127:* TicketsCAD now publishes a Software Bill of Materials with every
  release (see §6), which supplies the component inventory an acquiring agency
  needs. Vendor risk assessment, procurement policy, and acceptance testing
  remain the customer's responsibility.
- **SC-1, SC-2** Networking architecture (firewall, segmentation, ingress filtering)
  — **and egress filtering.** TicketsCAD ships optional features that originate
  outbound connections to third parties, including an AI feature that can send
  amateur-radio transcripts to a commercial LLM API. All are off or unconfigured
  by default. Every one is enumerated, with the exact content it sends and how to
  disable it, in
  [SECURITY.md § What TicketsCAD sends outside your network](../SECURITY.md#what-ticketscad-sends-outside-your-network);
  the CJIS framing is in [CJIS-POSTURE.md §5.10](CJIS-POSTURE.md#outbound-connections-to-third-party-services).
  Deciding which of them this deployment may reach — and enforcing it at the
  firewall rather than only in application settings — is the customer's
  responsibility.
- **CA-family** Security Assessment and Authorization (the ATO process)

Tickets CAD provides the technical primitives (authentication, audit log, encryption); the customer agency wraps them in operational policy.

---

## 9. Audit-readiness checklist

When preparing for a CJIS audit, the administrator should:

1. ✅ Open Settings → **Security Compliance** and confirm all green badges (or document any yellow/red with justification).
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

## 10. Phase ship history (relevant to this policy)

| Phase | Date | What shipped |
|---|---|---|
| Phase 6 (security audit) | 2026-04-11 | 14 audit findings closed; F-007 SSE long tail; Hour-2 auth/session (CSP, HSTS preload, session expiry on API); Hour-3 TFA rate-limit |
| Phase 8 (i18n) | 2026-06-08 | Translation framework |
| Phase 8d (session security) | 2026-06-08 | Password change kills other sessions; admin-reset passwd column fix |
| **Phase 9 (force pw change on first login)** | **2026-06-08** | `must_change_password` flag + system + per-user toggle; forced redirect flow |
| **Phase 10 (CJIS hardening)** | **2026-06-08** | Configurable password policy; admin reset reason; password history; rotation reminder; this document; compliance dashboard |
| **Phase 127 (SBOM / supply chain)** | **2026-07-29** | SBOM rebuilt to the CISA 2026 Minimum Elements; CycloneDX 1.6; versions detected from shipped artifacts; CI + release freshness gates; `tests/test_sbom.php`; §6 of this document |
| **Phase 127b (SBOM Author Signature)** | **2026-07-29** | SBOM signing key created (ECDSA P-256, maintainer-held, §5.3); public key published; detached signature shipped; `--verify` mode; generator now refuses to emit a silently-absent field. **17 of 17 data fields, 6 of 6 practices** |

---

## 11. Document control

| Field | Value |
|---|---|
| Author | Eric Osterberg |
| Reviewers | (sign on review) |
| Next review | Annually OR on major control change |
| Repository | `openises/TicketsCAD` |
| Path in repo | `newui-dev/newui/docs/SECURITY-POLICY.md` |

Changes to this policy require a documented review and a version bump.
