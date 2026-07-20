# Frequently Asked Questions

If you can't find your answer here, try [TROUBLESHOOTING.md](TROUBLESHOOTING.md) (symptom-based), [GLOSSARY.md](GLOSSARY.md) (terms), or the in-app help at `/help.php`.

---

## General

### What is TicketsCAD?

A web-based Computer-Aided Dispatch (CAD) system designed for volunteer fire departments, amateur-radio emergency services (ARES/RACES), CERT teams, small EMS agencies, and campus security. Originally written in 2003 (v1.0); rewritten for v4.0 (NewUI) starting in 2026.

It is **open source** (GPL-2.0), runs on commodity Linux + Apache + PHP + MariaDB, and integrates with field hardware (DMR radios, Meshtastic mesh, APRS, OwnTracks phones).

### Who is it for?

Small-to-medium agencies that need real dispatch capability without the cost or complexity of enterprise systems like Spillman, CentralSquare, or RapidSOS. Typical users:

- Volunteer fire departments (10–200 personnel)
- ARES / RACES groups during emergencies
- CERT and reservist programs
- Campus + private security teams
- Search-and-rescue organisations
- ICS training programs (TicketsCAD ships with a 24-module training curriculum)

### Who is it NOT for?

- Agencies handling 1,000+ daily calls (we've never load-tested at that scale)
- Agencies with hard CJIS audit requirements they can't self-attest (see [CJIS-POSTURE.md](CJIS-POSTURE.md) — we provide the controls, you do the certification)
- Anyone needing a vendor-supported product with an SLA (we're community-supported)

### How much does it cost?

The software is free. You pay for hosting (a $10/month VM works for most volunteer agencies). Optional hardware (DMR radios, LoRa modems) is your existing equipment.

### What does the architecture look like?

PHP 8.2 procedural backend, MariaDB / MySQL database, Bootstrap 5 frontend, Leaflet maps, vanilla ES5 JavaScript (no build tools). Server-Sent Events for real-time updates. No containerisation required (but supported); no Kubernetes, no microservices.

See [INDEX.md § Explanation](INDEX.md#explanation--how-and-why) for architecture documents.

### What's NewUI v4.0 vs. v3.44?

v3.44 was the long-running legacy version (PHP 7 / jQuery 1.4 / framesets). v4.0 is a full rewrite with modern tooling. They share the data model but the UI is completely different. See [LEGACY-TO-NEWUI-TERMS.md](LEGACY-TO-NEWUI-TERMS.md) for the rename table and [UPGRADING-FROM-V3.md](UPGRADING-FROM-V3.md) for the migration procedure.

---

## Installation

### How long does an install take?

First time: about an hour following [INSTALLATION-CHECKLIST.md](INSTALLATION-CHECKLIST.md). Subsequent installs (you know the steps): 25–30 minutes.

### Can I run it on Windows?

The web app runs on XAMPP / WAMP on Windows (the developer Eric uses this for local dev). But for production, use Linux. The bridge daemons (DVSwitch, Meshtastic, APRS-IS) all require Linux.

### Can I run it in Docker?

Yes. There's a Docker setup at `docker/` in the repo with `docker-compose.yml`. See `docs/DOCKER-DEPLOY.md` in the cross-project docs (if shipped) for Docker-specific notes. Caveats: the bridge daemons (DVSwitch, etc.) are easier to run on dedicated VMs than in containers.

### What's the smallest VM I can run this on?

Strict minimum for a 5-person volunteer team: 1 vCPU, 1 GB RAM, 10 GB disk. The dashboard will feel slow on phones, but it works.

Recommended for a 25-person team: 2 vCPU, 4 GB RAM, 40 GB disk.

Recommended for 100+ users: 4 vCPU, 8 GB RAM, 100 GB disk. Database tuning matters at that point — see [MAINTENANCE-RUNBOOK.md § Performance](MAINTENANCE-RUNBOOK.md).

### Do I need HTTPS?

For production: yes, absolutely. CJIS demands it, password security depends on it, and modern browsers refuse certain features (PWA install, service workers, geolocation) without it.

For evaluation: HTTP works, but enable the RSA-proxy field-encryption layer ([`proxy/INSTALL-LINUX.md`](../proxy/INSTALL-LINUX.md)) so sensitive form fields are at least client-encrypted.

### Why can't I log in to the install I just set up?

Check, in order:

1. Did you run the migrations? `php sql/run_migrations.php`
2. Did you create the admin user? Use the `/install.php` browser wizard (preferred) or seed via SQL as shown in [INSTALLATION-CHECKLIST.md § Section 10 Path B](INSTALLATION-CHECKLIST.md).
3. Is the database password in `config.php` correct? Test with `mysql -u newui -p`.
4. Is `php-mysql` installed? `php -m | grep pdo_mysql`.

If none of those: check `/var/log/apache2/newui-error.log` for the real error.

---

## Users + roles

### How do I add a user?

Settings → User Accounts → New User. Pick a role from the dropdown; the user receives an email-style notification with a temp password (if SMTP is configured) or you give them the password directly.

The new user is forced to change their password and enroll 2FA on first login (assuming policy requires it).

### How do roles + permissions work?

Roles are bundles of permissions. Six default roles ship (Super Admin, Org Admin, Dispatcher, Operator, Read-Only, Field Unit). You can create custom roles in Settings → Roles & Permissions.

There are 65 permissions total, in 4 categories: `screen.*` (which pages a user can see), `widget.*` (which dashboard widgets), `action.*` (what they can do), `field.*` (which sensitive fields are visible).

See [RBAC-GUIDE.md](RBAC-GUIDE.md) for the full reference.

### What's the difference between a Member and a User?

A **Member** is a person on your personnel roster (firefighter Bob, EMT Alice). They may or may not have a TicketsCAD login.

A **User** is a login account. Linked to a Member via the `member_id` field if the person is on the roster, or unlinked (rare — usually just the admin).

So your CEO might be a User (admin login) but not a Member (not on the dispatch roster). Your active firefighter is both.

### What's a "personal resource"?

When a Member clocks in for a shift (via the navbar toggle), the system auto-creates a Responder unit for them. That Responder is a "personal resource" — a dispatchable unit representing the person themselves, distinct from any vehicle.

This lets dispatchers assign people who aren't in a fire engine without needing to model that person as a vehicle. See Training m18.

### Can I have a user with NO role?

You can create one, but they won't be able to access anything (Phase 12 RBAC fail-closed). The login will succeed, but every page returns 403. Assign a role for them to be useful.

### Can I create custom roles?

Yes. Settings → Roles & Permissions → New Role. Pick a name, description, and check the boxes for the permissions you want. The role appears in the User Accounts dropdown immediately.

### How do "time-bound grants" work?

You can grant a user a role with an expiry date. After the expiry, the hourly cron (`rbac-expire-grants.php`) removes the grant automatically. Use it for temporary elevation — "give Bob Dispatcher role for tonight's exercise only".

See Training m14.

---

## Authentication + security

### Why is 2FA mandatory?

Because TicketsCAD is being used to manage operational data that, if compromised, could put responders or members of the public at risk. We default to 2FA-required for admin and dispatcher roles. You can configure it per-role in Settings.

If you're sure you don't want 2FA, disable it globally in Settings → Identity & Security → Two-Factor Authentication → Disable. We don't recommend this.

### What 2FA apps work?

Any RFC 6238 TOTP authenticator:

- **Aegis** (recommended; open source, Android only)
- **1Password** / Bitwarden / KeePassXC
- **Authy**
- **Microsoft Authenticator**
- **Google Authenticator**
- Hardware tokens that emit TOTP codes (less common)

The enrollment screen shows a QR code; scan it with any of the above.

### What if I lose my phone AND backup codes?

An admin (someone with `action.manage_users`) can reset your 2FA from Settings → User Accounts → row → Reset 2FA. The reset requires a written reason that lands in the audit log.

If you ARE the only admin and can't get in: SSH into the VM and clear the user's TFA enrollment directly with SQL: `sudo mariadb newui -e "DELETE FROM user_tfa WHERE user_id = (SELECT id FROM user WHERE user='yourname');"` — next login will prompt re-enrollment.

If you have no SSH access and no other admin: see [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md).

### Why does "remember this device" sometimes not work?

The trusted-device cookie is bound to your User-Agent + Accept-Language header + the first three octets of your IP address (Phase 73bb hardening). If any of those changed since you ticked the box:

- Browser updated → UA changed → cookie invalid
- Switched networks → /24 prefix changed → cookie invalid
- Changed Accept-Language → cookie invalid

This is intentional. "Remember this device" is a same-network convenience, not a portable bypass. CJIS deployments typically keep the trust window short (≤7 days).

### How does field encryption work?

For installations on HTTP (no TLS), TicketsCAD can encrypt sensitive form fields client-side using the browser's Web Crypto API. The encrypted blob (with `ENC:` or `ENC2:` prefix) is what hits the server; the server decrypts only when the data is actually needed.

Setup: install the RSA proxy ([`proxy/INSTALL-LINUX.md`](../proxy/INSTALL-LINUX.md)), enable in Settings → Identity & Security → Field Encryption.

For production, use TLS instead. Field encryption is a fallback for closed networks where TLS isn't viable.

### Is TicketsCAD CJIS compliant?

It has the technical controls expected by CJIS Policy v6.0 — see [CJIS-POSTURE.md](CJIS-POSTURE.md) for the full mapping. Whether your *deployment* is compliant is a function of how you configure it + how you operate it. We provide the controls; you bring the policies, training, physical security, and (sometimes) the CSO sign-off.

### Where are passwords stored?

bcrypt cost 12 in the `user.passwd` column. We do NOT store plaintext anywhere, do NOT log passwords, and do NOT include passwords in error messages or backups (other than as the bcrypt hash).

If your install has legacy users with MD5/SHA1/plaintext hashes from v3.44, the login pipeline detects and upgrades them to bcrypt on first successful login.

---

## Incidents + dispatch

### How do I create an incident?

Click **New Incident** in the navbar (or hit `N` on the keyboard, or type `/inc` in the command bar). Fill out the form — it's keyboard-first so you can dispatch fast.

See Training m03 for the full walkthrough.

### What's the difference between "incident" and "ticket"?

The user-facing word is **incident**. The database table is `ticket` for historical reasons. Same thing. See [GLOSSARY.md § Incident](GLOSSARY.md#incident).

### What's a "signal"?

A short code with a fixed meaning — e.g. "Signal 7" = unit out of service for lunch. They're configurable in Settings → Operations → Signals. Some installations call them "10-codes" or "hint codes". See [GLOSSARY.md § Signal](GLOSSARY.md#signal).

### What's PAR?

Personnel Accountability Report. A periodic check-in cycle for assigned units. Dispatcher initiates, each assigned unit must acknowledge within the cycle window. Configurable per-incident-type. See [PAR-CHECK-GUIDE.md](PAR-CHECK-GUIDE.md).

### How do I send a Mayday?

There's a red **Mayday** button always visible in the navbar. Tap it. It triggers global alarms, broadcasts a message to every connected client, and (if configured) escalates via SMS.

If you're a field unit on mobile: same button, same effect.

### How does dispatch see my position?

Several paths:

- **Browser GPS** (web/PWA): when you grant geolocation permission, your position is reported as a `browser_gps` provider on your personal resource.
- **OwnTracks** (phone app): more accurate, more battery-efficient. Configure once per device. See [OWNTRACKS-CONFIG-PUSH.md](OWNTRACKS-CONFIG-PUSH.md).
- **APRS** (HF/VHF radio): your callsign's position from APRS-IS, polled via the APRS-IS listener.
- **DMR** (radio terminal): if your radio reports GPS, the position arrives via the DMR bridge.
- **Meshtastic** (LoRa mesh): position beacons over LoRa, bridged via the mesh-bridge VM.

Whichever is most recent (and within the staleness window) is what dispatch sees.

---

## Communications

### Can dispatchers chat with field units?

Yes — three ways:

1. **Local chat** (built-in): browser/PWA only. Messages persist in the DB.
2. **SMS** (Twilio / BulkVS / Pushbullet): outbound to phones. Configure in Settings → Integrations.
3. **Radio** (DMR / Meshtastic / APRS): voice or text over radio. See per-channel guides.

A single message can fan to multiple channels via the [routing engine](ROUTING-ENGINE-REFERENCE.md).

### What's the routing engine?

It forwards messages between channels based on rules. "If a DMR call comes in on TG 9990, post the transcript to local chat in the dispatch channel". See [ROUTING-ENGINE-REFERENCE.md](ROUTING-ENGINE-REFERENCE.md).

### Can I get an alert when something specific happens?

Two paths:

- **Webhooks** for programmatic integration with other systems. See [WEBHOOKS-INTEGRATOR-GUIDE.md](WEBHOOKS-INTEGRATOR-GUIDE.md).
- **Audio alerts** in the browser (custom-composable tones for different event types). Settings → Identity & Security → Audio Alerts.

### Why don't my chat messages persist?

Most likely the broker/`chat_messages` shadow-schema issue documented in `specs/broker-schema-2026-06/decision-memo.md`. Run the recovery migration in [TROUBLESHOOTING.md § chat-doesnt-persist](TROUBLESHOOTING.md#chat-doesnt-persist).

---

## DMR / DVSwitch

### What is the DVSwitch bridge for?

Lets TicketsCAD speak DMR (Digital Mobile Radio). Dispatcher can type a message and Piper TTS speaks it onto a talkgroup; incoming voice gets transcribed by Vosk STT and lands as text in the dispatch feed. See [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md).

### Do I need a hotspot?

No. The DVSwitch stack is fully software — Analog_Bridge + MMDVM_Bridge + md380-emu run on a Linux VM. You connect to BrandMeister, TGIF, or similar via your DMR ID over the internet.

### Do I need a DMR radio?

For testing: no. You can verify by sending text via Piper TTS and observing the Parrot talkgroup (TG 9990) echo it back into the transcripts panel.

For production: yes, if you want your field units to receive the TX. They need DMR radios listening on the same talkgroup.

### Is the transcription accurate?

Default Vosk model (`vosk-model-small-en-us-0.15`, 40 MB) is fine for tactical short phrases ("Engine one responding, ETA five minutes") and gets noisy on names and uncommon vocabulary. For higher accuracy, faster-whisper is queued as an alternative engine (see [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md)).

### Why not ODMRTP instead of DVSwitch?

The Open DMR Terminal Protocol would let TicketsCAD connect directly to BrandMeister, bypassing the MMDVM_Bridge layer. It's researched but not yet implemented — see `specs/odmrtp-2026-06/research.md`.

DVSwitch works today and supports any digital-radio network MMDVM_Bridge handles (DMR, P25, NXDN, YSF). ODMRTP would be BrandMeister-only.

---

## Mobile

### Does the mobile app work offline?

The PWA caches static assets so the UI shell loads offline, but every API call requires connectivity. Field units in genuine no-signal areas can't dispatch (or be dispatched) until they're back online.

For mesh-network deployments where the mobile device talks via Meshtastic, the mesh handles the connectivity layer.

### Is there an iOS app?

It's a Progressive Web App. On iOS Safari: tap Share → Add to Home Screen. Same on Android Chrome. There's no App Store / Play Store listing.

### My phone shows "Add to Home Screen" but the icon is the default Safari icon

The manifest icons weren't picked up (a Phase 49 fix). Clear browser cache, reload the install page, try "Add to Home Screen" again.

### Why does my mobile UI scroll get stuck?

This was a Phase 70/72 bug, since fixed. If you're seeing it now, you're on an old code build. `git pull && systemctl reload apache2`.

---

## Performance

### Why is the dashboard slow?

Most often: a backend query is slow. Common causes:

1. **MariaDB needs tuning.** Default `innodb_buffer_pool_size` is tiny. Raise it to 25–50% of available RAM.
2. **`audit_log` is huge.** Tens of millions of rows make some pages slow. Reduce retention or trim manually.
3. **Map tiles slow.** Use the tile-proxy mode (Settings → Maps → Map Providers → mode = proxy) instead of direct OpenStreetMap.
4. **Bridge daemons on the same VM.** Move them to a dedicated VM (the install playbook does this by default).

### How many concurrent users can I handle?

Untested at scale. Anecdotally:

- A single 2-vCPU VM handles 20–30 dispatchers actively in the UI plus a hundred mobile users beaconing positions.
- Beyond that, profile first — usually MariaDB is the bottleneck before PHP-FPM.
- Horizontal scaling (multiple PHP frontends + one DB) hasn't been needed yet but the code doesn't preclude it.

### Why does SSE use so much CPU?

It shouldn't — `api/stream.php` sleeps between iterations. If it's hot, it's a regression; file an issue. See [TROUBLESHOOTING.md § sse-cpu](TROUBLESHOOTING.md#sse-cpu).

---

## Data + privacy

### Where does my data live?

In your MariaDB database on your VM. We have no telemetry, no analytics, no phone-home. Nothing leaves your install unless you explicitly configure an outbound channel (SMS, email, webhook).

### Can you (the project) see my data?

No. Open-source code, your hardware. Even the SonarQube static-analysis tools we use to maintain code quality run on your-side infrastructure (or the project's, against committed code only — not runtime data).

### What about CJI?

TicketsCAD stores whatever you put in it. If you enter PII (patient info, address, phone), it lives in your DB. Encryption at rest for that data is your job (LUKS, MariaDB tablespace encryption, plus the `ENC2:` field-encryption layer for high-sensitivity fields). See [CJIS-POSTURE.md](CJIS-POSTURE.md) and [SECURITY-POLICY.md](SECURITY-POLICY.md).

### Does TicketsCAD comply with GDPR / CCPA / state privacy law?

The technical controls (export, delete, audit) are there. Whether YOUR deployment complies depends on YOUR policies, retention schedules, and consent flows. The project doesn't provide legal advice.

---

## Backups + recovery

### How often should I back up?

Daily, minimum. Configure the cron in [INSTALLATION-CHECKLIST.md § Section 12](INSTALLATION-CHECKLIST.md#section-12--cron-for-background-tasks).

If your incident volume is high, hourly DB dumps are worth it (the file is rarely > 50 MB after gzip).

### How long do I keep backups?

Default: 30 days locally + indefinite off-site with lifecycle policies (S3 / B2 etc. lifecycle to Glacier after 90 days, expire after 2 years). Match your org's data-retention policy.

### How do I restore?

See [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md). Practiced procedure takes 30-60 min.

### Will my 2FA work after a restore?

Yes IF you restore the encryption keys (`/var/www/keys/`) too. Without those keys, all TFA secrets are scrambled bytes and every user has to re-enroll.

This is why [BACKUP-RECOVERY-RUNBOOK.md § Encryption key escrow](BACKUP-RECOVERY-RUNBOOK.md#encryption-key-escrow) is the most important section in the entire docs.

---

## Updates + development

### How do I update to a new version?

```bash
cd /var/www/newui
sudo mariadb-dump --single-transaction newui | gzip > /var/backups/newui/pre-upgrade-$(date +%F).sql.gz
sudo git pull origin main
sudo -u www-data php sql/run_migrations.php
sudo systemctl reload apache2
```

See [MAINTENANCE-RUNBOOK.md § Monthly](MAINTENANCE-RUNBOOK.md#monthly--12-hours) for the full procedure with smoke tests.

### How do I roll back if an update breaks things?

```bash
git checkout <previous-commit-sha>
mysql newui < /var/backups/newui/tcad-pre-upgrade-YYYY-MM-DD.sql
systemctl reload apache2
```

The backup script writes a `pre-upgrade-*` snapshot before any migration runs, specifically for this.

### Can I run my own modifications?

It's GPL-2.0 source. Fork it, modify it, run it. If your fork might be useful to others, send a PR.

The codebase has strong conventions documented in the project guide.

### How do I report a security issue?

Privately. See [SECURITY-POLICY.md](SECURITY-POLICY.md) for the responsible-disclosure path. Don't file public GitHub issues for security bugs.

### How do I contribute?

- **Bug reports / feature requests:** [GitHub Issues](https://github.com/openises/TicketsCAD/issues)
- **Documentation fixes:** PRs welcome; the docs live alongside the code
- **Translations:** see [CONTRIBUTING-TRANSLATIONS.md](locales/CONTRIBUTING-TRANSLATIONS.md)
- **Code:** read the project guide first, then send PRs against `main`

---

## Other questions

### Why "TicketsCAD"?

Historical. The original 2003 version modelled incidents as "tickets" (think helpdesk ticket — issue arrives, gets assigned, gets resolved). The name stuck even after the actual dispatch concept matured. In NewUI, the user-facing term became "incident" but the project name didn't change.

### Who maintains this?

Primarily Eric Osterberg (the original author, since 2003) with assistance from AI agents and the small contributor community. Issues + PRs are reviewed in batches; expect a response within a week for non-urgent items.

### Is there commercial support?

Not currently. The project is community-maintained. If your org wants commercial support, file an issue — there's been interest in standing up a small support consultancy if demand is there.

### Where can I see what's coming?

- `specs/future-phases.md` — queued phases
- [GitHub releases](https://github.com/openises/TicketsCAD/releases) — what's shipped
- [GitHub Issues](https://github.com/openises/TicketsCAD/issues) — open requests + bugs

---

This FAQ is maintained alongside the code. If you have a question that isn't here, file an issue or send a PR — recurring questions belong on this page.
