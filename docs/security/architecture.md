# Security architecture — trust boundaries, threat model, and framework mapping

**Document version:** 1.0
**Effective date:** 2026-08-14
**Audience:** operators evaluating TicketsCAD for their agency, security reviewers, future maintainers
**Scope:** TicketsCAD NewUI v4. The legacy v3.44 codebase (`openises/tickets`) is out of scope.

## What this document is, and what it isn't

TicketsCAD already has three security documents, each with a specific lens:
[`docs/SECURITY-POLICY.md`](../SECURITY-POLICY.md) maps controls to the FBI CJIS
Security Policy v6.0, [`docs/CJIS-POSTURE.md`](../CJIS-POSTURE.md) goes deeper on
CJIS specifically, and
[`docs/security/cisa-oss-2026-conformance.md`](cisa-oss-2026-conformance.md)
self-assesses against CISA's 2026 open-source-security guidance. Root
[`SECURITY.md`](../../SECURITY.md) is the public-facing policy: how to report a
vulnerability, what data leaves an install, and the SBOM.

This document does not repeat any of those. It is the **cross-framework view**:
who might attack this software and how, where the trust boundaries actually sit,
and — new as of this document — how the same posture maps against **CIS Controls
v8** and the **CIS Microsoft IIS 10 Benchmark**, the two frameworks Eric asked to
be added on 2026-08-14 after a second IIS-specific vulnerability report in as
many weeks. Where a control is already documented elsewhere, this document
points there rather than duplicating it — a fact stated in two places drifts
apart the first time only one of them gets updated.

**Living document.** Update this file in the same change that alters a security
control, a trust boundary, a data flow, or an algorithm — the same rule that
governs `SECURITY-POLICY.md`.

---

## 1. Threat model

TicketsCAD's actual deployment reality shapes this more than any generic
threat model would: **self-hosted by volunteers with no dedicated IT staff**,
across a mix of Apache, nginx, IIS, and Docker, often on a single box with no
network segmentation. The threat model below is written for that reality, not
for an enterprise SOC.

| Attacker class | Capability | What they'd go after | Primary controls |
|---|---|---|---|
| **Unauthenticated internet attacker** | Can send HTTP requests to any exposed port | Exposed directories/files (the recurring incident class — see §7), SQLi, auth bypass, IDOR | Web-exposure hardening (§4.6 in SECURITY-POLICY.md), CSRF, RBAC fail-closed, prepared statements everywhere |
| **A curious or malicious low-privilege volunteer** | Valid credentials, low RBAC grant | Data outside their scope (other orgs' incidents, PII), privilege escalation | RBAC scope-aware grants, privilege-escalation guard, audit logging |
| **A compromised or malicious admin account** | Full application access | Everything the app can see; could disable audit logging or export bulk data | Audit log is write-only from the app (no UPDATE/DELETE path), admin actions require a `reason` field, 2FA strongly recommended |
| **Supply-chain compromise** (a dependency, a vendored library, the SBOM signing key) | Whatever the compromised component can do | Silent backdoor, false SBOM claiming a clean bill of health | SBOM with signature (§5.3, SECURITY-POLICY.md), `composer audit` CI gate, Dependabot, pinned lockfile |
| **Physical device loss** (a dispatcher's laptop, a mobile unit tablet) | Whatever was logged in on that device | Session hijack if the session outlives the loss | Session timeout (default 8h — see the CJIS 30-min note in SECURITY-POLICY.md §1.4), force-logout from Active Sessions, HttpOnly/Secure cookies |
| **Radio-network-adjacent actor** | Access to the amateur radio network the DMR/Meshtastic/Zello bridges connect to | Injecting traffic into the bridge, or reading traffic the bridge relays | Bearer-token-protected bridge control surfaces, bridges run as separate processes/containers (not the web app itself), amateur radio's own "everything is public" nature is disclosed in SECURITY.md |
| **A researcher or scanner running automated tooling against a live install** | Whatever a scanner can reach unauthenticated | The same surface as the unauthenticated attacker, but at volume | SECURITY.md's "Reports produced with AI assistance" policy exists specifically because this class of report has become common and needs a stated bar |

**What is explicitly out of scope for TicketsCAD's own controls** — physical
security, personnel security, network segmentation, and organisational policy
are the operating agency's responsibility, not something the application can
enforce. This is stated plainly in SECURITY-POLICY.md §8 and repeated here
because it is the single most important boundary in this threat model: a
volunteer fire department's CAD server sitting on the same flat network as
every other device in the station is a real, common deployment, and this
software cannot fix that from inside the application layer.

---

## 2. Trust boundaries

```
                      ┌─────────────────────────────────────────┐
                      │         Dispatcher's browser            │
                      │   (session cookie, CSRF token, TLS)     │
                      └──────────────────┬──────────────────────┘
                                         │ HTTPS (operator-configured)
                      ┌──────────────────▼────────────────────────┐
   Web server ───────►│         TicketsCAD PHP application        │
 (Apache/nginx/IIS)   │  api/, inc/, top-level pages — RBAC,      │
                      │  CSRF, audit log, field encryption        │
                      └───┬──────────────┬───────────────┬────────┘
                          │              │               │
                  ┌───────▼────┐  ┌──────▼───────┐  ┌────▼───────────────┐
                  │  MariaDB   │  │  Filesystem  │  │  Radio/mesh        │
                  │  (RBAC,    │  │  (keys/,     │  │  bridge processes  │
                  │  incidents,│  │  backups/,   │  │  (DMR, Meshtastic, │
                  │  audit)    │  │  uploads/)   │  │  Zello) — separate │
                  └────────────┘  └──────────────┘  │  processes/        │
                                                    │  containers,       │
                                                    │  bearer-token-     │
                                                    │  protected HTTP    │
                                                    │  control surfaces  │
                                                    └──────┬─────────────┘
                                                           │ outbound only
                                                   ┌───────▼────────────────┐
                                                   │  Amateur radio /       │
                                                   │  DMR network — PUBLIC  │
                                                   │  by design             │
                                                   └────────────────────────┘

   Third parties contacted (each independently disclosed + toggleable,
   see SECURITY.md "What TicketsCAD sends outside your network"):
   map tiles, geocoding, weather, callsign/DMR-ID lookup, optional AI
   (Radio AI), optional TTS drivers, email/SMS/Slack/webhooks/Web Push.
```

**The boundary that has been breached repeatedly is the web-root/private-files
one**: the line between "the web server's document root" and "the
application's private files." Every incident in §7's table is a failure of
that specific boundary — which is exactly why §4 below gives it more space
than any other control category. **A second, distinct boundary was also
breached once** (2026-08-16): the internal Org-Admin/Super-Admin RBAC
boundary, via a mechanism unrelated to directory exposure — see "A second
incident pattern: the RBAC exclusion-list boundary" immediately after §7's
table, below. Encryption and the database layer itself remain unbreached in
this project's history to date.

**The radio bridges are a separate trust domain on purpose.** They run as
distinct processes (bare-metal) or containers (Docker), each behind its own
bearer token, precisely so that a defect in `hbp_client.py` or the Zello proxy
cannot reach the database or the RBAC-protected API surface directly — they can
only call back into TicketsCAD's own bearer-token-protected endpoints, the same
as any external integration would.

**Cross-org ticket sharing (Phase 141, 2026-08-17, GH#70) is a deliberate,
narrow, audited crossing of the org-isolation boundary — off by default.**
An install's per-organization data isolation (§1's "curious or malicious
low-privilege volunteer" row) is otherwise strict: a user's `org_visible_ids()`
set bounds every ticket-list query and every direct ticket lookup. Cross-org
ticket sharing (`org_type_routing` + `incident_shares`, `inc/org-sharing.php`)
adds exactly one narrow, admin-configured exception — a Super Admin can
route tickets of a given incident type/group from one organization to
another, at a `view` (redacted, read-only) or `assist` (same-org-equivalent)
tier. This is off by default (zero routing rules on a fresh install, and on
both of this project's live hosts as of this phase's ship), fully reversible
per-rule (deactivation stops future sharing), and every routing-rule
create/edit/deactivate and every cross-org read is written to the audit log
distinguishably from ordinary same-org access. Rule authoring is
Super-Admin-only by design in this phase — the org-scoped self-service
permission code exists (`action.manage_org_routing_org`) but ships withheld
from Org Admin's default grant, specifically because a routing rule exposes
the *creating* org's data to a *different* org with no two-party consent
mechanism yet (see `docs/CROSS-ORG-TICKET-SHARING.md` for the full model).
No mutation path in this phase can move ticket ownership between
organizations, at any tier, including via the external API — verified as
part of this phase's own endpoint sweep, not merely asserted.

**Phase 142 (2026-08-17) extends the same boundary two ways, both reusing
Phase 141's authorization primitives rather than building new ones.** First,
ad hoc manual sharing: a dispatcher can share one specific, already-existing
ticket with another organization by hand (no standing rule required),
gated on two new RBAC codes (`action.share_incident`,
`action.revoke_incident_share` — broadly granted to Dispatcher and Org Admin
by default, a deliberate departure from Phase 141's Super-Admin-only
routing-rule codes, reasoned in `docs/CROSS-ORG-TICKET-SHARING.md`) **and**,
on every single request, `org_ticket_is_owned_by_caller()` — the same
Phase-141 function, unmodified, that already guarantees no share at any tier
ever satisfies it. This is what stops the sharpest version of the risk: an
org whose only access to a ticket is itself share-derived — including
`assist` tier, which already carries full same-org-equivalent write access —
can never create or revoke a further share on that ticket, chaining access
onward to a third org the real owner never agreed to. Proven by a dedicated
adversarial regression test (`tests/test_org_sharing_anti_chaining.php`),
not just documented as intent.

Second, real-time visibility: the SSE stream (`api/stream.php`, previously
untouched by Phase 141 for exactly this reason) gained an org-awareness
dimension it didn't have before — a share's recipient org now receives live
push for the shared ticket's events, not just on next poll/reload. This adds
a new `'org'` visibility scope, deliberately **authorized at publish time,
not connection-open time**: a connection's own org membership is a stable
fact computed once when the stream opens, but *which orgs currently hold an
active share on this specific ticket* is volatile and is re-resolved fresh,
live, on every single event published — so a share revoked mid-connection
stops reaching the former recipient starting with the very next event, with
no propagation-delay window on the write side to go stale (unlike the
existing `$userIsAdmin`/`$userGroups`/`$entitledPrefixes` connection-open
snapshot, whose staleness window — up to 5 minutes — is a pre-existing,
already-accepted class this phase does not widen). See
`docs/PITFALLS-INDEX.md`'s "Real-time (SSE)" entry for the general
principle this generalizes to.

**Phase 143 (2026-08-17, GH#70 Phase 3 — the final phase) adds a THIRD
crossing mechanism, standing relationships, with the genuine two-party
consent Phase 141's routing rules deliberately lack.** Three new tables
(`org_relationships`, `org_relationships_members`,
`org_relationships_activations`) and one new file
(`inc/org-relationships.php`). Unlike a routing rule, a standing
relationship cannot take effect unilaterally: every named organization's
own authorized approver must independently consent — gated by
`org_relationship_can_act_for_org()`, keyed on the *acting user's own* org
membership (`org_visible_ids()`) matching the *specific membership row's*
org, never on who proposed the relationship. This is what makes the
guarantee genuinely two-party: Org A's proposer can never make Org B's own
membership row move to `approved`, because Org A's `org_visible_ids()` never
contains Org B. Two ceilings are independently configurable — `access_tier`
(write capability) and `redaction_profile` (field visibility) — so a
relationship can grant real operational write capability without
automatically widening the sharpest redacted fields.

**The read-time expiry guarantee is this phase's single most
security-critical property.** A relationship can optionally require a
separate, time-boxed **activation** step before any visibility exists at
all. When that window elapses, access is revoked at the instant the window
closes, checked fresh inside the SQL query itself
(`org_relationship_activation_live_join_sql()`, a fragment recomputed
against `NOW()` on every evaluation, deliberately never a cached boolean) —
never by waiting for a background sweep to notice and flip a flag. This is
this project's third deliberate application of a lesson first learned twice
the hard way (the PAR scheduler and the pending-message sweep, both §7):
"an on/off switch gates behaviour; cleanup that closes out a stale audit
record runs whether or not anyone is watching, and grants nothing by
running, and revokes nothing by not running." Proven directly, not merely
argued: `tests/test_org_relationships_read_time_expiry.php` activates a
relationship with a short window, lets it expire with the companion
cleanup job (`tools/org_relationship_cleanup_tick.php`, every 5 minutes)
**never invoked at all**, and confirms visibility and write access are both
gone on the very next check while the database's own `deactivated_at`
column is still `NULL` at that moment.

**Anti-chaining was explicitly re-examined for this phase, not assumed to
still hold.** Phase 141's own plan had flagged this as required "the moment
Phase 3 introduces `org_relationships`, since that is exactly the mechanism
that could make `org_visible_ids()` itself share-derived." Confirmed
structural: `org_visible_ids()` and `org_ticket_is_owned_by_caller()`
receive zero edits in Phase 143, in any commit — relationship-derived
visibility is injected exclusively at the ticket-visibility layer
(`org_can_see_ticket()`, `org_ticket_query_filter()`,
`org_can_mutate_ticket()`), never at the org-membership layer. An
`assist`-tier relationship-derived viewer with full write access to another
org's ticket is therefore still refused when attempting to share it onward
(Phase 142's `org_ticket_is_owned_by_caller()` gate, unmodified) or to
propose/approve a relationship "on that org's behalf"
(`org_relationship_can_act_for_org()`'s own per-row check) — proven by
`tests/test_org_relationships_anti_chaining.php`, which additionally asserts
both guard functions' source is byte-identical to their pre-Phase-143
committed shape, not merely that this specific scenario happens not to
break.

Three RBAC codes follow two different default postures, both re-verified
against this project's own standing rule that no gate on a
deliberately-narrower-than-`action.manage_config` permission may fall back
to `is_admin()`: `action.manage_org_relationships` (install-wide,
Super-Admin-only, same posture as Phase 141's routing codes) and
`action.manage_org_relationships_org` / `action.activate_org_relationship`
(both broadly granted to Org Admin and Dispatcher by default — a deliberate
departure from Phase 141's own `_org`-code precedent, justified because
proposing or activating grants zero visibility by itself; the real security
boundary in both cases is the per-row/per-membership check re-run on every
request, the same separation of concerns Phase 142 already established for
manual sharing). Full detail: `docs/CROSS-ORG-TICKET-SHARING.md` and
`specs/phase-143-cross-org-standing-relationships/`.

---

## 3. Cryptographic inventory

Full detail — algorithms, key lengths, key custody, rotation procedures — lives
in [`SECURITY-POLICY.md` §5](../SECURITY-POLICY.md#5-cryptography-sc-13-sc-28).
Summarised here for the cross-framework view, with a currency judgement against
**NIST SP 800-131A Rev. 2** and **NIST SP 800-57 Part 1 Rev. 5** (key-length
guidance), which is the standard `docs/security/maintenance.md`'s cadence table
commits to re-checking:

| Use | Algorithm | Key length | NIST SP 800-131A/800-57 currency (2026-08-14) |
|---|---|---|---|
| Password hashing | bcrypt, cost 12 | n/a (adaptive) | Not a NIST-approved primitive by name, but bcrypt with cost ≥ 10 is broadly accepted industry practice and exceeds OWASP's current minimum; NIST SP 800-63B recommends a memory-hard KDF (Argon2, bcrypt, PBKDF2, scrypt) without mandating one specifically |
| TOTP secret encryption | AES-256-GCM | 256-bit | **Current** — approved through 2030 and beyond under SP 800-131A |
| Field encryption | RSA-OAEP + AES-256-GCM | RSA-2048 / AES-256 | **Current** — RSA-2048 is acceptable through 2030 per SP 800-131A Table 2; AES-256 has no sunset date |
| SBOM Author Signature | ECDSA P-256, SHA-256 | 256-bit (~128-bit security) | **Current** — approved under FIPS 186-5 |
| TLS | operator-configured | operator-configured | **Not verified by this document** — TLS termination happens at the web server/proxy layer, outside TicketsCAD's control. See the CIS IIS Benchmark §6.7 gap below for the IIS-specific piece of this. |

**Nothing here is due for mandatory rotation on cryptographic-currency grounds
alone.** RSA-2048's NIST-approved window runs through 2030; when that changes,
`maintenance.md`'s annual review is where it gets caught, not an ad hoc check.

---

## 4. CIS Controls v8 — self-assessment

CIS Controls v8 is 18 controls, 153 total safeguards, of which **56 are
Implementation Group 1 ("essential cyber hygiene")** — the tier appropriate for
an organisation with limited IT/security resources, which describes essentially
every TicketsCAD operator. This table rates TicketsCAD's own posture — not an
operator's — against each control, following the same honest three-state
pattern the CISA OSS conformance document already uses (MET / PARTIAL / NOT
MET), relabelled here as COVERED / PARTIAL / GAP to match this table's own
scope.

**A note on rigor, stated rather than hidden:** the per-control IG1 safeguard
counts below are qualitative characterisations informed by CIS's own published
materials, not a verified line-by-line count against CIS's downloadable
Implementation Groups reference (that reference ships as an image on
cisecurity.org, not machine-readable text, at the time this was written). Cross-
check against CIS's own poster before treating the IG1 weighting as
authoritative for a compliance submission.

| # | Control | Status | Why | IG1 weight |
|---|---|---|---|---|
| 1 | Inventory and Control of Enterprise Assets | GAP | No documented asset-inventory process for an operator's own servers/hosts — this is the operator's infrastructure, not something the application tracks | Light |
| 2 | Inventory and Control of Software Assets | PARTIAL | SBOM (95 signed components, CI-gated freshness) covers application dependencies; no host/OS-level software inventory guidance | Light |
| 3 | Data Protection | PARTIAL | Field-level RSA+AES-GCM encryption exists for sensitive columns; no documented data-classification, retention, or disposal policy for operators | Moderate |
| 4 | Secure Configuration of Enterprise Assets and Software | **COVERED** | Iteratively hardened, tested web.config/.htaccess/nginx templates; self-checking schema (`tools/check-schema.php`); config gates in CI | Heavy |
| 5 | Account Management | PARTIAL | RBAC + self-service password UI are strong; no documented account-review or deprovisioning cadence for operators | Moderate |
| 6 | Access Control Management | **COVERED** | 65-permission RBAC v2, TOTP 2FA, session/lockout management — this control's substance, in depth | Heavy |
| 7 | Continuous Vulnerability Management | PARTIAL | `composer audit` is a CI gate for PHP dependencies (SECURITY-POLICY.md §6.3); the Python service dependencies (DMR bridge, Meshtastic, TTS) and OS packages are enumerated in the SBOM but not scanned on a cadence — see `maintenance.md` | Moderate |
| 8 | Audit Log Management | **COVERED** | Login/data-access/admin-action trail with IP + user agent, write-only from the app; no stated retention *policy* (retention is unbounded by default, which is itself a documented tradeoff) | Light |
| 9 | Email and Web Browser Protections | GAP | Out of the application's control surface — operator workstation/browser hygiene | Light |
| 10 | Malware Defenses | GAP | No malware/AV scanning on uploaded attachments; host antivirus is the operator's domain | Light |
| 11 | Data Recovery | **COVERED** | Automatic, verified, restorable backups on systemd timers with a genuine `--drill` restore-and-compare mode — a strong, tested match to this control | Moderate |
| 12 | Network Infrastructure Management | PARTIAL | [`network-segmentation-guidance.md`](network-segmentation-guidance.md) now names the specific bridge services and a starting zone layout; still no enforcement — segmentation remains the operator's own network, by design (see §1) | Minimal |
| 13 | Network Monitoring and Defense | GAP | No IDS/monitoring guidance — legitimately IG2/IG3-tier for this project's scale | None |
| 14 | Security Awareness and Skills Training | **COVERED** | [`operator-security-awareness.md`](operator-security-awareness.md) — password hygiene, phishing in a public-safety context, physical device security, every in-app reference verified against the code | Heavy |
| 15 | Service Provider Management | PARTIAL | SECURITY.md discloses every third-party data flow explicitly (AI, TTS, weather, tile providers); no formal service-provider review *process* for operators choosing which to enable | Minimal |
| 16 | Application Software Security | PARTIAL | Strong test-gate discipline (8,000+ assertions, schema/contract/legacy-level audits, pre-commit hooks); SonarQube runs but is not yet a hard CI gate (see `maintenance.md`), no CodeQL or dedicated SAST gate | None |
| 17 | Incident Response Management | **COVERED** | [`incident-response-plan-template.md`](incident-response-plan-template.md) — fill-in-now contacts, recognition signs, first-30-minutes/containment/recovery/after-action, distinct from SECURITY.md's researcher-facing VDP | Light |
| 18 | Penetration Testing | GAP | No formal program; the project's multi-year real-world vulnerability-report history (see §7) is a partial, informal substitute, not an equivalent | None |

**Read for priority, not for a scorecard.** Controls 4, 6, 8, 11, 14, and 17
are now deep. Controls 9, 10, 13, 15, 16, and 18 are legitimately lower
priority at IG1: several carry zero or near-zero IG1 safeguards by design, and
9/10 sit outside the application's own control surface entirely. Control 12
moved from GAP to PARTIAL in this pass (§6 item 10) but stays PARTIAL on
purpose — network topology is the operator's own infrastructure, not
something a document can enforce.

---

## 5. CIS Microsoft IIS 10 Benchmark — gap analysis

Added specifically because IIS has been the source of two real vulnerability
reports in as many weeks (GHSA-rrp6-pqhj-w5wj and its `.git`/`vendor`
continuation — see
[`advisory-2026-08-14-git-vendor-iis-exposure.md`](advisory-2026-08-14-git-vendor-iis-exposure.md)),
both from the same external reporter testing on real IIS. The CIS Microsoft IIS
10 Benchmark v1.2.1 is organised into 7 categories, roughly 55 recommendations
(Tenable's operationalised audit spec counts 61 individual checks — the
difference is Tenable splitting some recommendations into multiple scannable
checks, not a scope difference).

**Applicability note that matters more here than in most gap analyses:**
roughly a quarter of the benchmark — the entire "ASP.NET Configuration"
category, 12 of ~55 items (retail deployment mode, debug mode, MachineKey
validation, .NET trust level, etc.) — is ASP.NET-specific and **does not apply**
to TicketsCAD, a procedural PHP application running under IIS via FastCGI. That
category is marked N/A below rather than confused with a real gap.

| Category | Items | Status | Concrete next step |
|---|---|---|---|
| **1. Basic Configuration** | 7 | PARTIAL | Directory browsing is explicitly disabled in every shipped web.config (confirmed). App-pool-per-site and non-system-partition content remain operator/ops decisions never codified in a doc. **DONE (§6 item 3):** disabling the WebDAV IIS role feature is now documented in [`WEB-SERVER-HARDENING.md`](../WEB-SERVER-HARDENING.md#disable-the-webdav-role-feature) — WebDAV's PUT/MOVE verbs can bypass extension-based Request Filtering entirely, since that layer never sees those verbs as anything to deny. |
| **2. Authentication & Authorization** | 8 | N/A / PARTIAL | TicketsCAD does its own PHP-level session authentication, not IIS Windows/Forms auth, so most of this category doesn't apply as written. The "no credentials in config files" intent is already served by the `.htaccess`/`web.config` denies on `config.php`. **Action queued (§6):** document a pre-flight check that the IIS FastCGI `.php` handler mapping is intact — a broken/removed mapping serves PHP source as plain text, which would leak `config.php`'s DB credentials even with directory listing off. |
| **3. ASP.NET Configuration** | 12 | **N/A** | Not an ASP.NET application. The two conceptually-relevant items (hiding detailed errors, removing identifying headers) are already handled at the PHP layer, server-agnostically (`display_errors` suppressed on API endpoints; security headers set by the app). |
| **4. Request Filtering** | 11 | PARTIAL | **DONE (§6 item 2):** every shipped and runtime-written `web.config` now carries `allowDoubleEscaping="false"` (double-encoding rejection), CI-gated by `tests/test_iis_webconfig_syntax.php`. `maxAllowedContentLength`, `maxUrl`, `maxQueryString`, and a TRACE-method block are genuinely site-wide settings this project's own "no root web.config" rule means it cannot ship itself — documented as an operator fragment in [`WEB-SERVER-HARDENING.md`](../WEB-SERVER-HARDENING.md#site-wide-request-filtering-add-to-your-own-webconfig-not-ours) instead. Still PARTIAL, honestly: the site-wide four remain the operator's own step, not something a `git pull` delivers. |
| **5. IIS Logging** | 3 | GAP | IIS's own logs default to the system drive (`%SystemDrive%\inetpub\logs\LogFiles`); nothing relocates them. This is the *exact same lesson* TicketsCAD has already applied three times to its own writable directories (backups, encryption keys, Zello audio — all moved out of/above the web root after real incidents). **Action queued (§6):** document the identical pattern for IIS's own log directory — relocate via IIS Manager/`appcmd`, restrict with ACLs. |
| **6. FTP Requests** | 2 | **N/A** | TicketsCAD doesn't use or require the IIS FTP role. **Action queued (§6):** one documentation line — "do not enable the FTP Server role for a TicketsCAD install." |
| **7. Transport Encryption** | 12 | PARTIAL | HSTS and other security headers are already sent from the PHP layer (server-agnostic, benefits IIS too). The actual protocol/cipher-suite hardening (disabling SSLv2/3/TLS1.0/1.1, weak-cipher removal) is a **Windows Schannel/registry-level** setting, entirely outside web.config's reach. **Action queued (§6):** document a Windows Server TLS-hardening runbook step for IIS operators, naming the free **IIS Crypto** tool rather than requiring hand-edited registry keys. |

**A concrete new item outside the benchmark's own categories, from the
secondary research below:** deny script execution inside upload/attachment
directories specifically — distinct from the CLI-only code-directory hardening
already shipped, and the single highest-value item found across every source
consulted, given TicketsCAD's live file-upload surface across incidents,
members, and facilities. **DONE (§6 item 1)** — see `uploads/web.config` and
`cache/web.config`.

---

## 6. Prioritized gap-closure queue

Ranked by (exposure for a self-hosted, no-IT-staff operator base with a real
incident history) × (inverse effort). This is a documentation and template-edit
backlog, not a design problem — nothing here requires new infrastructure.

1. **DONE (2026-08-14).** Deny script execution inside upload/attachment
   directories. `uploads/web.config` and `cache/web.config` ship the IIS
   equivalent of the Apache `.htaccess`/nginx rules that already existed —
   Request Filtering, `allowUnlisted="false"` with an explicit allow-list of
   exactly the extensions `api/upload.php`'s `$ALLOWED_EXT_MIME` accepts, kept
   in sync by `tests/test_web_upload_extension_sync.php`. `tests/
   test_iis_webconfig_syntax.php` was generalized to validate an arbitrary
   declared extension list, not just the single `.py` case it was written for.
2. **DONE (2026-08-14).** IIS Request Filtering extended two ways. Every
   shipped and runtime-written `web.config` now carries
   `allowDoubleEscaping="false"` (directory-scoped, safe to ship — gated by
   `tests/test_iis_webconfig_syntax.php`). `maxAllowedContentLength`,
   `maxUrl`, `maxQueryString`, and a TRACE-method block are genuinely
   site-wide settings, which this project's own "no root `web.config`" rule
   (a site-wide rule is the dangerous one — see `sql/web.config`) means it
   cannot safely ship itself; documented instead as a fragment for the
   operator's own site-level config in
   [`WEB-SERVER-HARDENING.md`](../WEB-SERVER-HARDENING.md#site-wide-request-filtering-add-to-your-own-webconfig-not-ours).
3. **DONE (2026-08-14).** Disabling IIS WebDAV documented in
   [`WEB-SERVER-HARDENING.md`](../WEB-SERVER-HARDENING.md#disable-the-webdav-role-feature),
   alongside the WHY (its PUT/MOVE verbs bypass extension-based Request
   Filtering, which does not apply to those verbs).
4. **DONE (2026-08-14).** [`operator-security-awareness.md`](operator-security-awareness.md)
   — password hygiene, phishing in a public-safety context, physical device
   security, one page, every in-app UI reference verified against the actual
   code before publishing.
5. **DONE (2026-08-14).** [`incident-response-plan-template.md`](incident-response-plan-template.md)
   — fill-in-now contact/backup/fallback fields, recognition signs, a first-
   30-minutes checklist, containment, recovery, and after-action guidance.
6. **DONE (2026-08-14).** [`waf-reverse-proxy-recommendation.md`](waf-reverse-proxy-recommendation.md)
   — Cloudflare free tier as the realistic option for an unfunded volunteer
   org, with the explicit list of what it does and does not replace.
7. **Document: relocate IIS's own logs** off the system drive, matching the
   pattern already applied to backups/keys/Zello-audio.
8. **Extend dependency scanning** to the Python services (DMR bridge,
   Meshtastic bridge, TTS) and formalize a vulnerability-management SLA — CIS
   Control 7, already flagged PARTIAL by the CISA OSS conformance doc too.
9. **Publish a lightweight operator asset-inventory checklist** (CIS Controls
   1/2) — OS/patch level, PHP version, TLS cert expiry, which optional
   features are enabled.
10. **DONE (2026-08-14).** [`network-segmentation-guidance.md`](network-segmentation-guidance.md)
    — names the specific bridge services (DMR, Meshtastic, Zello proxy) and
    their network surface, a starting 4-zone layout, and effort-ordered
    practical steps. CIS Control 12, IG2-tier, ranked last on purpose —
    still the right call even though it shipped in this pass.

**Items 7 and 9 remain open** — not part of this pass. Both are still
documentation-only per this queue's own framing (no new infrastructure), so
either is a reasonable next pickup.

**Deliberately excluded from this queue**, and why: Control 13 (Network
Monitoring) and Control 18 (Penetration Testing) are IG2/IG3-tier and outside
what a documentation-and-template pass can close; Controls 9/10 (Email/Browser
Protections, Malware Defenses) are largely outside the application's own
control surface beyond what item 1 already addresses.

**On DISA's IIS 10.0 Server STIG (NIST NCP checklist #952):** relevant as a
reference for operators under a DoD or CJIS-adjacent compliance mandate — its
technical checks overlap heavily with the CIS benchmark above — but it is
written for Department of Defense information systems (POA&M tracking, ISSM
sign-off, a companion Site STIG) and adopting "STIG-compliant" as a stated goal
for a single-maintainer volunteer project would overclaim in exactly the way
the CISA OSS conformance document goes out of its way to avoid. It is
referenced as a pointer in CJIS-POSTURE.md for operators who need it, not
folded into the general hardening docs aimed at volunteer fire/EMS/ARES/campus
security operators.

---

## 7. Incident history (why §2's boundary gets the most attention)

A pattern, not a list of unrelated bugs: **the web root is the application
root**, so every directory in the tree is served unless something explicitly
denies it, and the project has learned this the hard way, repeatedly, across
different web servers:

| Date | What was exposed | Server | Advisory |
|---|---|---|---|
| 2026-07-30 | `backups/`, `sql/`, `tools/`, `inc/` — including a complete database dump | Any (root cause: nothing denied them) | [advisory-2026-07-30-exposed-directories.md](advisory-2026-07-30-exposed-directories.md) |
| 2026-08-02 | A `403` on the directory vs `200` on the file inside it — the check-correction | Any | [advisory-2026-07-30-check-correction.md](advisory-2026-07-30-check-correction.md) |
| 2026-08-02 | IIS-specific: `.htaccess` doesn't apply, first `web.config` attempt itself misconfigured | IIS | [advisory-2026-08-02-iis-tls-enforcement.md](advisory-2026-08-02-iis-tls-enforcement.md) |
| 2026-08-03 | `keys/` sibling-of-webroot assumption broke on IIS's actual directory layout | IIS/Windows | [advisory-2026-08-03-fe-keys-dir.md](advisory-2026-08-03-fe-keys-dir.md) |
| 2026-08-03 | Windows backup-path regression | Windows | [advisory-2026-08-03-windows-backup-regression.md](advisory-2026-08-03-windows-backup-regression.md) |
| 2026-08-14 | `.git/` (full repository history over HTTP) and `vendor/` | IIS | [advisory-2026-08-14-git-vendor-iis-exposure.md](advisory-2026-08-14-git-vendor-iis-exposure.md) |
| 2026-08-14 | Zello voice recordings relocated to a path that was itself the IIS site root on a stock Windows/IIS layout | IIS/Windows | [advisory-2026-08-14-zello-audio-relocation-regression.md](advisory-2026-08-14-zello-audio-relocation-regression.md) |

**Why IIS specifically keeps recurring:** Apache and nginx installs read a
config file that ships in the git tree (`.htaccess`, `nginx/ticketscad-
hardening.conf`) and inherit the fix on the next `git pull`. IIS never reads
`.htaccess`, and every "sibling of the webroot" assumption this project made —
`../backups`, `../keys`, `../zello-audio` — turned out to collide with the
*actual* physical directory layout of a stock Windows/IIS install (`C:\inetpub\
wwwroot` is the site root, and `dirname()` of a subdirectory under it is
sometimes that same root). This is precisely why `docs/security/maintenance.md`
(§ below) commits to a "did the last IIS-affecting change get tested against
an explicit simulated Windows layout" review, not just "did the tests pass."

This history is the strongest evidence for the CIS IIS Benchmark work in §5:
the gaps found there are not hypothetical.

**A second incident pattern: the RBAC exclusion-list boundary (2026-08-16,
commits `743d9d4` + `78c6c10`).** Unlike every incident above, this one had
nothing to do with directory exposure or a specific web server — it was a
defect in how the default Org Admin/Dispatcher grant is seeded.
`sql/rbac.sql`/`sql/run_00_rbac.php` grant Org Admin/Dispatcher "everything
except" a literal `WHERE code NOT IN (...)` list of admin-only permission
codes. Two independent, additive mechanisms both leaked an excluded
permission back onto a lower role, neither visible from reading the
exclusion list itself: (1) a role could hold the OLD code **directly**, from
before that code was ever added to the list — the purely-additive
`INSERT IGNORE` grant never retroactively revokes a pre-existing row when a
string is later added; (2) `sql/run_rbac_v2.php`'s canonicalization step
links every old code to a `<resource>.<verb>` **canonical alias**, which
`rbac_can()` treats as fully interchangeable with the original — a literal
exclusion list written before that canonicalization ran can never name an
alias that didn't exist yet, so re-importing the seed files afterward
re-granted the excluded permission under its new name. Found while building
Phase 140 (an RBAC test that "does NOT hold the install-wide permission"
flaked and looked like test pollution — it wasn't). **Confirmed live: Org
Admin held the canonical alias of `action.manage_config` and
`action.manage_roles` — Super-Admin-tier permissions — on both the dev
database and your-server.example.com, a complete defeat of the
Org-Admin/Super-Admin boundary**; your-server was clean on the alias
path but held all seven then-excluded codes directly, plus Dispatcher
directly held `action.view_reports`. Fixed same-day with two self-healing
repair `DELETE` statements (one per leak path) that run on every re-seed,
applied directly to both production databases as an immediate interim fix
before the permanent version shipped. `tests/test_rbac_canonical_alias_leak.php`
reproduces both mechanisms and asserts the live database carries no leaked
grant. See CLAUDE.md's matching pitfall entry and
`docs/RBAC-INTEGRATOR-GUIDE.md`'s "Don't" section for the rule a developer
adding a new admin-only permission must follow to avoid reintroducing this.

---

## 8. Residual risks — stated, not hidden

- **No live IIS host to test against.** Every IIS fix in this project's history
  (see §7) is verified against Microsoft's documentation and a structural test
  model, then confirmed or corrected by an external reporter testing on a real
  IIS install. This document, and the code it describes, carry that same
  limitation.
- **A single maintainer.** Response time, review depth, and the pace of closing
  the queue in §6 are bounded by one volunteer's time, stated plainly rather
  than implied otherwise (matching the CISA OSS conformance document's own
  framing).
- **Operators control their own network, patch cadence, and physical security**
  — see §1's "out of scope" note. No control in this document can compensate
  for an agency that never patches its host OS or leaves the server in an
  unlocked closet.
- **SonarQube is not yet a hard CI gate** (CIS Control 16, §4) — findings are
  reviewed manually per `maintenance.md`'s cadence, not blocked automatically.
  A finding introduced between reviews ships until the next pass catches it.
- **The radio/mesh bridge attack surface is real and separately scoped.** A
  compromise of a DMR or Zello bridge process could inject traffic onto a
  public amateur radio network under the operator's callsign — a regulatory and
  reputational risk distinct from a data breach, and one no code control fully
  eliminates (the bearer-token boundary in §2 limits blast radius, it does not
  remove the risk).
- **Routing rules (§2, Phase 141) still have no two-party consent mechanism
  — a Super Admin can unilaterally route one organization's tickets to
  another, and the receiving org has no way to decline.** This is unchanged
  by Phase 143 and remains a deliberate design choice, not an oversight:
  a routing rule takes effect unilaterally by design (see
  `docs/CROSS-ORG-TICKET-SHARING.md`). **An administrator who wants genuine
  two-party consent now has an alternative that provides it** — Phase 143's
  standing relationships require every named organization's own authorized
  approver to independently agree before any visibility exists at all (§2).
  Routing rules remain the right tool when a receiving org's consent isn't
  the model (e.g. a parent dispatch org configuring its own child agency's
  visibility within one legal entity); standing relationships are the right
  tool for a genuine inter-agency partnership where both sides need to
  agree. Once a ticket is shared via a rule, deactivating the rule does not
  retroactively un-share it (so a responding org actively working an
  incident never silently loses visibility mid-response) — this remains
  true for routing-rule shares and is joined by the identical guarantee for
  a relationship's already-open activation window (an explicit deactivation
  or elapsed window stops *future* visibility; it has never retroactively
  hidden anything from a currently-open session mid-request). An
  `assist`-tier responding-org user still has no narrower
  per-responder-org write boundary, from any of the three grant sources —
  they can update the status of, or unassign, any unit on a shared or
  relationship-visible ticket, the same reach a same-org co-dispatcher
  already has. Both remain named, accepted limitations across all three
  phases, not oversights.

---

## Document control

| Field | Value |
|---|---|
| Author | Eric Osterberg |
| Origin | 2026-08-14, following a second real IIS vulnerability report in two weeks and Eric's explicit request for a CIS/NIST-aligned security-practice blueprint |
| Sources | CIS Controls v8, CIS Microsoft IIS 10 Benchmark v1.2.1, Tenable's operationalised audit spec, SOCFortress and HyperComply hardening guides, Microsoft's own IIS-hardening guidance, NIST NCP checklist #952 (IIS 10.0 Server STIG) |
| Next review | With `docs/security/maintenance.md`'s annual cadence, or on any material control change |
| Repository | `openises/TicketsCAD` |
| Path in repo | `newui-dev/newui/docs/security/architecture.md` |
