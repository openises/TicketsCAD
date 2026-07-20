# TicketsCAD NewUI — Documentation

**Version:** 4.0-dev &nbsp;·&nbsp; **License:** GPL-2.0 &nbsp;·&nbsp; **In-app help:** [`help.php`](../help.php)

> **New here?** Pick a row below. Every page tells you who it's for at the top.
>
> | If you are a… | Start with |
> |---|---|
> | **Dispatcher / responder** (you'll use the system) | [User Guide](NEWUI-USER-GUIDE.md) · [FAQ](FAQ.md) · [Glossary](GLOSSARY.md) |
> | **System administrator** (you'll install or run the system) | [Installation Checklist](INSTALLATION-CHECKLIST.md) · [Maintenance Runbook](MAINTENANCE-RUNBOOK.md) · [Troubleshooting](TROUBLESHOOTING.md) |
> | **Developer / integrator** (you'll write code that talks to it) | [Architecture overview](#explanation--how-and-why) · [External API](EXTERNAL-API.md) · [Webhooks](WEBHOOKS-INTEGRATOR-GUIDE.md) · [Routing engine](ROUTING-ENGINE-REFERENCE.md) |
> | **Migrating from v3.44** (you have a legacy install) | [Upgrading from V3](UPGRADING-FROM-V3.md) · [Legacy term map](LEGACY-TO-NEWUI-TERMS.md) |
> | **Security / compliance reviewer** | [Security policy](SECURITY-POLICY.md) · [CJIS posture](CJIS-POSTURE.md) · [Audit log reference](AUDIT-LOG-REFERENCE.md) |

---

## How this documentation is organized

We follow the [Diátaxis](https://diataxis.fr) framework: four kinds of documentation, each with a different job. If you're looking for something and can't find it, this table tells you which section to scan.

| Kind | Purpose | Examples |
|---|---|---|
| **Tutorials** | Hands-on, "learn by doing" | Quick-start, training module sequence |
| **How-to guides** | Solve a specific problem | "Install on a fresh Debian VM", "Set up DMR" |
| **Reference** | Look up a fact | Glossary, RBAC permission list, audit-log schema |
| **Explanation** | Understand the why | Architecture overview, location-inheritance design |

---

## Tutorials — learn the system

- **[Quick-start: first 10 minutes](#) (in-app: `quick-start.php`)** — login, set up your profile, send your first dispatch
- **[Training curriculum](TRAINING-CURRICULUM.md)** — 24-module course covering every operational area, from welcome to mobile to integrations
- **New user welcome** — start here if you've never used TicketsCAD before
- **Login + 2FA setup** — TOTP enrollment, backup codes, "remember device"
- **Creating an incident** — the new-incident form end-to-end
- **Dispatching units** — assignments, status changes, PAR
- **Map, conditions, geofences** — Leaflet, weather, alert zones
- **Communications** — chat, signals, broker, broadcast
- **Major incidents** — linking, escalation, command structure
- **End of shift** — clock out, reports, handoff

---

## How-to guides — solve a problem

### Installing and configuring

- **[Installation checklist](INSTALLATION-CHECKLIST.md)** — blank Debian/Ubuntu VM → working dispatch, 60 minutes
- **[Quick install for evaluators](INSTALL.md)** — older single-file install notes
- **Installing on a fresh VM (training script)** — the same path in tutorial form
- **[Upgrading from v3.44](UPGRADING-FROM-V3.md)** — legacy → v4 migration with rollback
- **[Upgrade rollback](../tools/upgrade/ROLLBACK.md)** — recover from a failed upgrade
- **[RSA proxy install (Linux)](../proxy/INSTALL-LINUX.md)** — field-encryption proxy for HTTP deployments
- **[Fresh install guide](INSTALL.md)** — end-to-end procedure for a blank-slate host

### Integrations and field hardware

- **[DMR Radio — end-to-end install](RADIO-DMR-INSTALL.md)** — start here to enable the radio feature; covers bridge install, proxy, Apache, dmr_channels row, RBAC, on-air verification
- **[DVSwitch DMR bridge setup](DVSWITCH-ADMIN-GUIDE.md)** — Analog_Bridge + MMDVM_Bridge + md380-emu + Piper + Vosk (deeper architecture reference)
- **[Radio AI — admin guide](RADIO-AI-ADMIN-GUIDE.md)** — Phase 85f Claude-on-amateur-radio: listener daemon, approval API, settings, security
- **[Radio AI — operator guide](RADIO-AI-USER-GUIDE.md)** — review/approve/edit/reject AI-drafted voice responses; dry-run + auto-approve safeties
- **Radio AI — security review** — threat model, SonarQube results, manual endpoint review, secrets handling, audit trail
- **[Meshtastic bridge](MESH-BRIDGE-GUIDE.md)** — LoRa mesh bridge service
- **[APRS-IS persistent listener](APRS-LISTENER-SETUP.md)** — Python service replacing 5-minute polling
- **[OwnTracks + Traccar location ingest](TRACCAR-SETUP.md)** — HTTP-direct, per-device tokens, "pick your path"
- **[OwnTracks config push](OWNTRACKS-CONFIG-PUSH.md)** — server-driven device configuration
- **[Map configuration](map-configuration.md)** — tile providers, base map, weather overlays
- **Integrations training module** — broker channels in tutorial form

### Ongoing operations

- **[Maintenance runbook](MAINTENANCE-RUNBOOK.md)** — daily / weekly / monthly tasks
- **[Backup + recovery runbook](BACKUP-RECOVERY-RUNBOOK.md)** — browser-download, filesystem-save, cron, restore
- **Backups training module** — tutorial form
- **[Security policy](SECURITY-POLICY.md)** — security posture, key handling, incident response
- **[Access chain](ACCESS-CHAIN.md)** — auth, RBAC, and per-resource access model
- **[Troubleshooting](TROUBLESHOOTING.md)** — symptom → cause → fix catalogue

### People, permissions, scheduling

- **User management** — create/edit/disable user accounts
- **[RBAC overview](RBAC-GUIDE.md)** — roles, permissions, the 65-permission grid
- **Custom roles** — create a role with exactly the perms you want
- **Time-bound delegation + audit** — temporary grants with expiry
- **Roster (personnel)** — FCC lookups, callsigns, organisations
- **Teams + ICS certs** — NIMS resource typing
- **[Scheduling](scheduling.md)** — shifts and events
- **Time-keeping** — clock-in workflow
- **Equipment + vehicles** — fleet tracking

---

## Reference — look something up

### Concepts and terminology

- **[Glossary](GLOSSARY.md)** — every term used in the system, defined
- **[Legacy → NewUI term map](LEGACY-TO-NEWUI-TERMS.md)** — v3.44 names and what they're called now
- **[Frequently asked questions](FAQ.md)** — quick answers

### System behaviour

- **[Routing engine reference](ROUTING-ENGINE-REFERENCE.md)** — rule schema, filters, loop prevention
- **[External API integrator guide](EXTERNAL-API.md)** — bearer-token REST integration: minting, scopes, every endpoint with request/response, response envelope, errors, rate limits, token rotation playbook, security model, settings reference
- **[Webhooks integrator guide](WEBHOOKS-INTEGRATOR-GUIDE.md)** — HMAC signing, retry, event types
- **[Audit log reference](AUDIT-LOG-REFERENCE.md)** — OCSF-aligned event schema
- **[SQL migrations reference](../sql/README.md)** — `sql/run_*.php` files and what each one does
- **[Access chain](ACCESS-CHAIN.md)** — auth → session → RBAC → resource gate flow
- **[i18n / captions guide](I18N-GUIDE.md)** — `t()` function, language registry, contributing translations

### Security and compliance

- **[Security policy](SECURITY-POLICY.md)** — disclosure, encryption, lockout, session policy
- **[CJIS posture brief](CJIS-POSTURE.md)** — auditor-ready compliance mapping
- **[Backup + recovery runbook](BACKUP-RECOVERY-RUNBOOK.md)** — incident recovery, key/data restore
- **[Audit log reference](AUDIT-LOG-REFERENCE.md)** — what's logged, retention, review

### Feature reference

- **[PAR check guide](PAR-CHECK-GUIDE.md)** — Personnel Accountability Reports, cadence, escalation
- **[NewUI user guide (long form)](NEWUI-USER-GUIDE.md)** — every page and what it does
- **[USER-GUIDE.md (developer-facing)](USER-GUIDE.md)** — technical companion to the user guide

---

## Explanation — how and why

- **[Architecture overview](../README.md)** — repo readme: stack, layout, conventions
- **[Routing engine reference](ROUTING-ENGINE-REFERENCE.md)** — cross-protocol message routing internals
- **[RBAC integrator guide](RBAC-INTEGRATOR-GUIDE.md)** — design decisions, how to add new permissions

---

## Specs and decision records

The `specs/` tree records every non-trivial decision. Read these when you need to understand why something works the way it does.

- **Current state** — what's shipped, what's open
- **Handoff** — what the next session should pick up
- **Constitution** — non-negotiable project rules
- **Future phases** — queued work

Major phase directories under `specs/`:

| Directory | What it covers |
|---|---|
| `security-audit-2026-04/` | 8-file security audit (spec, endpoint inventory, findings, audit report, handoff) |
| `rbac-redesign-2026-05/` | RBAC v2 design |
| `legacy-upgrade-2026-05/` | Legacy → v4 migration design |
| `phase-08-i18n-2026-06/` | i18n implementation |
| `phase-09-force-pw-change-2026-06/` | Force password change |
| `phase-10-cjis-hardening-2026-06/` | CJIS hardening |
| `phase-11-rbac-canonical-2026-06/` | RBAC made canonical |
| `phase-12-sunset-legacy-levels-2026-06/` | Legacy `user.level` deprecation |
| `phase-16-par-checks-2026-06/` | PAR check engine |
| `dvswitch-proxy-2026-06/` | DMR bridge |
| `odmrtp-2026-06/` | Open DMR Terminal Protocol research + integration spec |
| `broker-schema-2026-06/` | Awaiting Eric: broker/chat_messages schema decision |

---

## Multilingual documentation

Translations live under [`docs/locales/<lang>/`](locales/). Currently supported:

| Language | Code | Coverage | Maintainer |
|---|---|---|---|
| English | `en` | 100% (canonical) | core |
| German | `de` | Index + glossary excerpt + FAQ | community |
| Dutch | `nl` | Index + glossary excerpt + FAQ | community |
| French | `fr` | Index + glossary excerpt + FAQ | community |
| Spanish | `es` | Index + glossary excerpt + FAQ | community |

**Want to contribute a translation?** Read [CONTRIBUTING-TRANSLATIONS.md](locales/CONTRIBUTING-TRANSLATIONS.md). Even partial translations are welcome.

The in-app UI is fully translated via the `t()` function and the `captions` table — see [I18N-GUIDE.md](I18N-GUIDE.md). Documentation translation is a separate, opt-in track.

---

## Conventions

- **File paths** in this documentation are written relative to the repo root unless the doc itself is in a subdirectory, in which case they're relative to that subdirectory. All internal links use that convention.
- **Code blocks** show shell commands assuming a Linux/macOS POSIX shell. Windows-only commands are marked with `# Windows:`.
- **`# comments`** in code blocks explain what each command does.
- **Tables** use the GitHub-flavoured markdown three-dash separator. They render in the in-app help viewer too.
- **Callouts** use blockquote with a leading bold tag: **Note:**, **Warning:**, **Tip:**.

---

## Where this index lives

This file is `docs/INDEX.md` in the NewUI repo. The same file under [`/help.php → Documentation Index`](../help.php) renders in-app for users without shell access.

If a link here is dead, file an issue or send a patch — broken links are bugs.

---

*Last refreshed during the 2026-06-15 documentation overhaul. Inventory total: 85+ documentation files. If you're adding a new doc, link it here.*
