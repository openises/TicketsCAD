# Security maintenance runbook

**Document version:** 1.0
**Effective date:** 2026-08-14
**Audience:** whoever is maintaining TicketsCAD's security posture — today, one
person
**Companion documents:** [`architecture.md`](architecture.md) (the threat model
and control mapping this runbook keeps current), [`SECURITY-POLICY.md`](../SECURITY-POLICY.md)
(CJIS control detail), root [`SECURITY.md`](../../SECURITY.md) (public policy
and SBOM)

This is the *how and how often*, not the *what*. Every practice named here
already exists somewhere in this project — the SonarQube playbook, the
Dependabot config, the backup-drill code, the SBOM signing procedure. What did
not exist until this document is a single place stating the cadence each one
runs on, and a log of when it actually ran.

---

## Cadence table

| Activity | Cadence | Tooling | Where the result lands |
|---|---|---|---|
| Dependency vulnerability scan (Composer/PHP) | **Every push** (CI gate) + ad hoc before a release | `composer audit`, `.github/workflows/qa.yml` | CI run status; fails the build |
| Dependency currency check (GitHub Actions, Python service deps) | **Weekly** (automated) | `.github/dependabot.yml` | PRs opened automatically against `main` |
| Vendored browser-library review (`assets/vendor/`) | **Quarterly**, or on a CVE report | Manual, cross-checked against `SBOM.cdx.json` (Dependabot cannot watch these — no package manifest) | This doc's maintenance log below |
| Static analysis (SonarQube) | **Before every release**, and at least **monthly** otherwise | `sonar-scanner` against project `ticketscad-newui` — see the SonarQube playbook | SonarQube dashboard + a dated snapshot in `specs/security/audit-YYYY-MM-DD.md` when findings change materially |
| Cryptographic-currency review | **Annually**, or immediately on a NIST SP 800-131A/800-57 revision | Manual — check each algorithm/key-length in `architecture.md` §3 against the current SP revision | This document's maintenance log + `architecture.md` §3 updated in place |
| Audit-log review | **Monthly** for a live production install (operator responsibility — see below) | Settings → Audit Log, or CSV export | Operator's own records — not this project's, since we don't operate installs |
| Backup verification | **Automatic**, every scheduled backup (content-based) | `inc/backup.php`, systemd timer | Settings → System Health |
| Backup **restore drill** | **Quarterly**, minimum | `tools/restore.php --drill`, or Settings → Backup / Maintenance | Health page + `backup_last_drill_status` |
| Key/credential rotation | **On suspected compromise**, immediately. Otherwise: SBOM signing key every **2 years**; per-install encryption keys, on operator judgement | See §5 below | This document's maintenance log, `CHANGELOG.md` for a public-facing rotation |
| Pre-commit + CI gate review (are the gates still mirroring what CI actually checks?) | **Whenever a new CI gate is added** | `tools/install-git-hooks.sh`, `.github/workflows/qa.yml` | Immediate — the standing rule is a CI gate not mirrored locally gets added to the pre-commit hook in the same change |
| This runbook itself | **Annually**, or when a practice listed here changes | — | Document version bump |

---

## 1. Dependency vulnerability scanning

**PHP (Composer).** `composer audit` runs on every push via
`.github/workflows/qa.yml` and is a hard CI gate — a vulnerable locked version
fails the build. See `specs/security/audit-2026-07-16.md` for the last time
this actually caught something (two transitive Symfony components pulled in
via `cboden/ratchet`).

**GitHub Actions, and the Python service dependencies** (DMR bridge,
Meshtastic bridge) are watched weekly by `.github/dependabot.yml`. These open
PRs automatically; review and merge them like any other dependency bump, with
the test suite as the gate.

**What is NOT yet scanned on a cadence, stated rather than hidden** (this is
CIS Control 7, rated PARTIAL in `architecture.md` §4): the OS-level packages
inside `services/dvswitch/docker/Dockerfile` and other container images, and
any Python packages not pinned via a lockfile Dependabot can read. These are
enumerated in the SBOM (so a consumer can check them against a CVE database
themselves) but this project does not yet re-check them on a schedule. Closing
this is item 8 in `architecture.md` §6's gap-closure queue.

**Vendored browser libraries** (`assets/vendor/`) have no package manifest, so
Dependabot cannot watch them at all — this is a stated, known gap
(SECURITY-POLICY.md §6.3). Review quarterly: for each library in
`SBOM.cdx.json`, check its declared version against the upstream project's own
released CVEs.

---

## 2. Static analysis (SonarQube)

Project key `ticketscad-newui` on the self-hosted instance
(`http://10.0.0.10:9000`). Full operational detail — token handling,
scanner CLI path, common rule IDs — lives in the SonarQube playbook (outside
this repo, in the maintainer's global tooling config); this section is the
*cadence and triage process*, which belongs with the code.

**Run before every release**, and at minimum monthly between releases. A scan
that only ever runs "when something feels off" misses the slow accumulation
this rule exists to catch — the gap between the 2026-07-16 snapshot (0
vulnerabilities, security rating A) and the state found on 2026-08-14 (23 open
issues before triage) happened silently over four weeks with no scan in
between; see `specs/security/audit-2026-08-14.md` for the full accounting.

**Triage process for a new finding:**

1. **Read the actual code**, not just the rule description. SonarQube's static
   rules cannot see runtime context — whether a value is user-controlled,
   whether a fallback path is security-sensitive, whether a directory that
   "should" set a header ever serves a response at all. Every finding in this
   project's history that turned out to be a false positive was one where the
   surrounding code already had the right reasoning; the fix was documenting
   it, not changing behaviour.
2. **If it's a real defect, fix it.** Prefer a genuine fix over a suppression
   — e.g. a string-concatenated SQL query that COULD take a bound parameter
   should take one, even if the current call sites happen to be safe, because
   the next caller might not be.
3. **If it's a false positive, suppress it with a reason, in the code, on the
   exact flagged line** — `// NOSONAR <rule-id>: <why>`. This is more durable
   than a SonarQube-only "won't fix" resolution: a resolution recorded only in
   the SonarQube database is invisible to the next person reading the source,
   and (learned the hard way, 2026-08-14) a resolution that predates a rescan
   can look like a fresh open finding if the reviewer doesn't check the issue's
   `status` field. Do both — the inline comment for the reader, the SonarQube
   resolution with a comment for the dashboard.
4. **Never silence a rule project-wide** to make a count look better.
   Suppression is per-finding, with a stated reason, or it doesn't happen.
5. **Log the outcome** in the next dated audit snapshot (`specs/security/audit-
   YYYY-MM-DD.md`) — what was found, what was fixed, what was triaged as a
   false positive and why.

**Not yet a hard CI gate.** Unlike `composer audit`, a SonarQube finding does
not currently fail the build (CIS Control 16 is rated PARTIAL for exactly this
reason in `architecture.md` §4). This is a deliberate, stated tradeoff for
now — false-positive-prone rules (the `S2077` SQL-format warnings on safely-
validated identifier interpolation are the recurring example) would need a
maintained per-project quality profile before a hard gate is worth the
friction. Revisit this if the manual-review cadence above ever slips.

---

## 3. Cryptographic-currency review

Annually, or immediately if NIST publishes a revision to SP 800-131A
(transitioning cryptographic algorithms) or SP 800-57 Part 1 (key management,
key-length guidance).

**Process:** walk `architecture.md` §3's table, algorithm by algorithm, against
the *current* revision of both documents. As of this writing (2026-08-14),
nothing in TicketsCAD's cryptographic inventory is past its approved window —
RSA-2048 remains acceptable through 2030 under SP 800-131A Table 2, AES-256 and
ECDSA P-256 have no stated sunset. **This will not remain true forever**; the
review exists specifically so the day it stops being true is caught by a
scheduled check, not a vulnerability report.

If a rotation is ever needed on currency grounds: the per-install encryption
keys follow `docs/ENCRYPTION-KEY-LIFECYCLE.md`; the project-level SBOM signing
key follows `SECURITY-POLICY.md` §5.3's rotation procedure. Both already exist
and do not need to be re-invented here.

---

## 4. Audit-log review

This is stated as an **operator** responsibility, not a maintainer one — this
project does not operate a production install of its own software for real
dispatch traffic. TicketsCAD ships the tooling (Settings → Audit Log, CSV/JSON
export per the audit-log export feature) and the guidance:

- Review monthly, or per the operating agency's own compliance requirements
  (CJIS audits typically expect evidence of periodic review — see
  SECURITY-POLICY.md §9's audit-readiness checklist).
- Look for: repeated failed-login clusters (possible credential-stuffing),
  admin actions with a `reason` field that doesn't match what actually
  changed, and RBAC grants issued outside expected review windows.
- Retention is unbounded by default (SECURITY-POLICY.md §2.2) — an operator
  concerned about storage growth is responsible for their own archival policy;
  this project does not auto-purge audit data.

For the training/demo instances this project *does* operate
(your-server.example.com, your-server), the same monthly cadence
applies, tracked in this document's maintenance log.

---

## 5. Backup verification and restore drills

**Automatic verification already happens on every scheduled backup** — content-
based, not just "did the file get written" (`inc/backup.php`). This is
continuous, not scheduled separately.

**Restore drills are different and must be run deliberately, quarterly at
minimum**: `tools/restore.php --drill` (or Settings → Backup / Maintenance)
spins up a genuinely separate scratch database, restores into it, compares row
counts against the live system, and tears the scratch database down — proving
the backup is *actually restorable*, not merely present. A backup that has
never been drilled is a hope, not a control.

Run a drill:
- Quarterly, scheduled.
- Immediately after any change to the backup pipeline itself (schema changes,
  a new table, a change to `BACKUP_DIR`'s platform-specific resolution — the
  exact class of change that has broken this twice before, see
  `architecture.md` §7).
- Before a major version upgrade.

---

## 6. Key and credential rotation

| Key | Rotation trigger | Procedure |
|---|---|---|
| Per-install RSA field-encryption key | Suspected compromise, or operator judgement | `docs/ENCRYPTION-KEY-LIFECYCLE.md` |
| TFA encryption key (`../keys/tfa.key`) | Suspected compromise | Same doc |
| SBOM Author Signature (project-level) | Every 2 years, or immediately on suspected compromise, or maintainer-role change | `SECURITY-POLICY.md` §5.3 |
| DMR/Zello/Meshtastic bridge bearer tokens | Suspected compromise, or when rotating a shared secret after personnel change | Regenerate in TicketsCAD's channel settings; update the bridge's `.env`/config to match |
| SonarQube `ci-scanner` analysis token | Yearly, or on compromise | SonarQube playbook (external to this repo) |
| Live admin/user passwords | **Never reset by an agent without explicit operator instruction** — see the standing rule in this project's CLAUDE.md; bcrypt is one-way and there is no take-back | Operator-initiated only |

---

## 7. Patching

**The application itself** has no update check and no telemetry (stated in
SECURITY.md) — an operator patches by `git pull` or a release tarball, on their
own schedule. This project's obligation is to ship fixes promptly and document
them (`CHANGELOG.md`, dated advisories under `docs/security/`), not to push
updates.

**The maintainer's own infrastructure** (your-server.example.com,
your-server, the SonarQube host) follows the fleet-wide patching
practices tracked separately in the maintainer's own environment inventory —
outside this repository's scope, referenced here only so the boundary is clear.

---

## 8. Maintenance log

Append-only. One line per completed activity from the cadence table above.

| Date | Activity | Outcome |
|---|---|---|
| 2026-07-16 | SonarQube scan + dependency scan (pre-release) | 0 vulnerabilities, security rating A, 2 Symfony components patched. See `specs/security/audit-2026-07-16.md`. |
| 2026-08-14 | Full SonarQube re-scan, first since 2026-07-16 | 23 findings surfaced (rating dropped to D over 4 weeks with no scan in between — the exact gap this cadence table exists to close). Triaged: real fixes applied (SQL bound-parameter conversion, Dockerfile non-root user + build-context secrets fix), false positives documented inline + resolved. See `specs/security/audit-2026-08-14.md`. |
| 2026-08-14 | This runbook created | First time a maintenance cadence existed in writing for this project. |
| 2026-09-02 | Full SonarQube re-scan (18-day gap, within cadence); full-history secret/PII scan of both public repos (`openises/TicketsCAD` + newly-discovered `openises/ticketscad-meshbridge`), including all refs (tags + PR heads) not just HEAD | 0 open BLOCKER/CRITICAL bugs/vulnerabilities; 3 real findings from this session's own new code fixed (accessibility labels); all 13 open security hotspots reviewed and resolved SAFE (0 remaining); both public repos confirmed to carry no live credential across their full history — one historical, already-fixed, low-severity hostname disclosure documented for transparency (no action needed). See `specs/security/audit-2026-09-02.md`. |

---

## Document control

| Field | Value |
|---|---|
| Author | Eric Osterberg |
| Origin | 2026-08-14, alongside `architecture.md`, following Eric's explicit request for a maintenance runbook with a real cadence table |
| Next review | Annually, or when a listed practice changes |
| Repository | `openises/TicketsCAD` |
| Path in repo | `newui-dev/newui/docs/security/maintenance.md` |
