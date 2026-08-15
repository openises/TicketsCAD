# Security awareness for dispatchers and operators

**Audience:** the volunteers who log into TicketsCAD — dispatchers, unit
personnel, org admins. Not a technical document; nothing here requires
touching a server or a config file.

**Why this exists:** CIS Controls v8, Control 14 (Security Awareness and
Skills Training) — the cheapest, highest-leverage gap in TicketsCAD's own
self-assessment against that framework (see
[`architecture.md` §4](architecture.md)), because almost every real
compromise of a small organization's systems starts with a person, not a
technical flaw. Print this, or link it from your onboarding checklist.

---

## 1. Your password is the whole lock

- **Use a unique password for TicketsCAD.** Reusing a password from
  another site means a breach of THAT site hands someone your TicketsCAD
  login too — this has happened to real organizations, and it's the single
  most common way an account gets taken over.
- **Length beats complexity.** A four-word passphrase
  (`correct-horse-battery-staple`-style) is both easier to remember and
  harder to crack than `P@ssw0rd1!`.
- **Change your password yourself, any time you want, from your profile
  page** — you don't need to ask an admin. If you think someone else knows
  your password, change it immediately and tell your org admin.
- **Turn on two-factor authentication (2FA)** if your organization allows
  it — your profile page's Security tab walks you through enrollment with
  a QR code. A stolen password alone can't get in with 2FA on.
- **Never share your login** with another dispatcher, "just for today."
  Every action in TicketsCAD is logged under the account that took it —
  sharing a login means the audit trail no longer means anything, and it
  means you're responsible for whatever that other person does under your
  name.

## 2. Phishing, in a public-safety context

Public-safety and volunteer-response organizations are a specific,
attractive phishing target: attackers know these groups handle sensitive
information (locations, PII, sometimes medical details) and often have
less IT support than a typical business. Watch for:

- **An email or text "from TicketsCAD" asking you to log in via a link.**
  TicketsCAD never emails you a login link. If you get one, don't click
  it — go to your organization's TicketsCAD URL directly, the way you
  normally do.
- **Urgency + a request for credentials or a one-time code.** "Your
  account will be locked in 1 hour, verify now" is a pressure tactic, not
  a real system message. Real lockouts don't come with a countdown timer
  in an email.
- **A message impersonating a fellow member or supervisor**, asking you to
  "confirm" your password, forward an incident report, or click a link
  "to see the schedule." If it feels slightly off — wrong tone, unusual
  request, sent at a strange hour — verify by another channel (call them,
  don't reply to the message) before acting.
- **A caller claiming to be "IT support" or "TicketsCAD support" asking
  for your password.** Nobody legitimate ever needs your password. Not an
  admin, not a developer, not anyone. If someone asks for it, that's the
  whole tell.

## 3. Physical device security

- **Lock your screen** when you step away from a device that's logged into
  TicketsCAD — a dispatch console, a laptop, a mobile unit tablet. A few
  seconds of habit closes a real gap.
- **A lost or stolen device that was logged in is a real incident, not an
  embarrassment to hide.** Tell your org admin immediately — they can
  force-log-out that session from the Active Sessions list under
  Settings → Login Settings, without needing physical access to the
  device, so a lost tablet doesn't stay a live login forever.
- **Don't leave a mobile unit's tablet or a dispatch console unattended in
  an unlocked vehicle or an open station bay.** The device itself is a
  target independent of what's on the screen.
- **Session timeout is your safety net, not your only line of defense.**
  TicketsCAD logs an idle session out automatically (organization-
  configurable), but that's minutes, not zero — the habit of locking the
  screen yourself is what actually closes the window.

## 4. If something feels wrong

You don't need to be certain something is a security problem before
saying something — a "this looks odd" is exactly the right threshold.
Tell your org admin if:

- You see an incident, message, or record you don't remember creating.
- You get locked out, or see a "too many failed attempts" message, when
  you're confident you typed your password correctly — that can mean
  someone else was trying it. TicketsCAD logs every login attempt
  (success and failure) in its audit trail, so your admin has something
  concrete to check.
- A device you know was logged in gets lost, stolen, or left unattended
  somewhere it shouldn't have been.
- You clicked a link or entered your password somewhere that, in
  hindsight, didn't feel right.

Reporting something that turns out to be nothing costs a few minutes.
Not reporting something that turns out to be real costs much more.

---

*Part of TicketsCAD's security documentation set — see
[`architecture.md`](architecture.md) for the full threat model and
[`../security/maintenance.md`](maintenance.md) for the maintainer-facing
runbook this document is a companion to.*
