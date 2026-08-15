# Incident response plan — template for TicketsCAD operators

**Audience:** the person or small team responsible for a TicketsCAD
install — an org admin, a technical volunteer, whoever gets the call at
2am. Not a technical deep-dive; a plan to fill in and print **before** you
need it.

**Why this exists:** CIS Controls v8, Control 17 (Incident Response
Management) — see [`architecture.md` §4](architecture.md). This is
distinct from [`SECURITY.md`](../../SECURITY.md)'s vulnerability disclosure
policy, which covers a *researcher* reporting a flaw to the maintainer.
This document is the other direction: **your CAD may have been
compromised, and you need to act**, possibly during an active incident,
possibly with lives depending on the system working. A volunteer
fire/EMS/ARES/campus-security operator has no SOC, no on-call security
team, and often no IT staff at all — this template assumes that reality,
not an enterprise one.

Fill in the bracketed fields and keep a printed copy where your team can
find it without the system that might be the problem.

---

## 1. Before anything happens — fill this in now

| Field | Your answer |
|---|---|
| Who is the technical contact for this install? | `[name, phone, email]` |
| Who is the backup technical contact (the first one is unreachable)? | `[name, phone, email]` |
| Where does TicketsCAD run? (host, VM, hosting provider) | `[hostname/IP, provider, physical location if self-hosted]` |
| Who has admin access to that host (SSH/RDP)? | `[names]` |
| Where are backups stored, and who can restore one? | `[location, procedure/link]` |
| What is your fallback dispatch method if TicketsCAD is unavailable? | `[radio net, paper log, phone tree — write the actual procedure, not "we'll figure it out"]` |
| Who in your organization has authority to take TicketsCAD offline? | `[name/role — a decision made under pressure needs a pre-decided owner]` |

**Do this now, not during an incident:** confirm your fallback dispatch
method actually works by testing it, at least once, when nothing is
wrong. A fallback nobody has practiced is a plan in name only.

## 2. Recognizing a possible compromise

You don't need certainty to act — these are reasons to move to Section 3:

- **Data that shouldn't be visible is visible** — a member sees another
  organization's incidents, a public board shows something it shouldn't,
  someone finds sensitive PII reachable without logging in.
- **Records exist that nobody created** — incidents, users, or roster
  entries nobody on your team remembers adding.
- **Unexpected admin activity** — a new admin account, a role change, a
  password reset nobody requested. Every admin action is in the audit
  trail with a `reason` field — an entry with no plausible reason, or a
  blank one, is worth investigating.
- **A backup, config file, or database export is reachable from a plain
  web browser**, logged out, without a password. (This exact class of bug
  has hit this project's own hosted installs — see
  [`architecture.md` §7](architecture.md) — so it is not a hypothetical.)
- **Someone tells you directly** — a security researcher, a vendor, a
  fellow admin at another agency who noticed something about your
  install specifically.
- **The system behaves strangely** — unexpectedly slow, unfamiliar error
  messages, the server rebooting or restarting services on its own.

None of these alone proves a compromise. All of them are reasons to check.

## 3. First 30 minutes

1. **Don't panic-shut-down the server** if dispatch is actively using it
   for a real incident — that itself endangers people. Move to your
   fallback dispatch method (Section 1) FIRST if operations are live,
   THEN address the system.
2. **Change the passwords that matter most, right now:** the database
   password, and any admin account you suspect. Changing a password
   invalidates that account's *future* logins; it does not undo anything
   already taken.
3. **Force-logout suspicious sessions.** An admin can do this from the
   Active Sessions list under Settings → Login Settings, without needing
   physical access to the affected device.
4. **Take a backup of the CURRENT state before you fix anything.**
   Counterintuitive, but real: if this ever needs investigating properly
   (or reporting to insurance, a partner agency, or law enforcement), the
   compromised state is evidence. Fixing first can destroy it.
5. **Write down what you observed and when**, even roughly. "Noticed
   at 14:20, saw X, changed password at 14:35" — a timeline built during
   the event is far more accurate than one reconstructed afterward.
6. **Call your technical contact (Section 1)**, even if you're not sure
   yet. A false alarm costs a phone call. A missed real incident costs
   much more.

## 4. Containing it

- **Isolate before you investigate**, if you can do so without disrupting
  active dispatch: firewall the host, or at minimum disable the specific
  account/session in question.
- **Don't delete anything you don't understand yet.** A suspicious record,
  an unfamiliar admin account, an odd log entry — screenshot or export it
  before removing it. Deleting the evidence of what happened makes it
  much harder to know if it's actually resolved.
- **Rotate credentials broadly, not narrowly**, once you're past the
  immediate crisis: database password, any API keys/tokens the install
  uses (Twilio, weather providers, Zello, whatever's configured), and any
  admin password that might have been exposed alongside the one you
  already know about.
- **If a specific vulnerability is suspected** (not just "someone got
  in," but "here's how"), that's the moment to loop in the maintainer —
  see [`SECURITY.md`](../../SECURITY.md) for how to report it, even if
  you're reporting an active exploitation rather than a theoretical
  finding.

## 5. Recovery

- **Restore from a KNOWN-GOOD backup** if you have one from before the
  suspected compromise window, rather than trying to hand-clean a live
  system you don't fully trust.
- **Verify the restore actually works** before declaring the incident
  over — `tools/restore.php --drill` if your install has that available,
  or a manual spot-check of real records against what you expect.
- **Re-enroll 2FA and rotate passwords for every admin account**, not
  just the one you suspect was involved — an attacker who got one
  password may have gotten others the same way.
- **Confirm your fallback dispatch method can stand down** — don't return
  to TicketsCAD-only operation until you're confident the system is
  clean.

## 6. After

- **Write a short after-action note** — not a formal report necessarily,
  but enough that the NEXT person filling this template's Section 1 knows
  what happened and what changed. What was the actual cause? What did you
  change as a result? What would you do differently?
- **If the cause was a TicketsCAD software defect** (not a config
  mistake, not a reused password, an actual bug in the application),
  report it per [`SECURITY.md`](../../SECURITY.md) so other installs
  don't hit the same thing.
- **Update Section 1 of this template** if anything about your contacts,
  hosting, or fallback procedure changed as a result.
- **Debrief the team**, briefly. The point isn't blame — a volunteer
  organization runs on trust, and a defensive, blame-first debrief teaches
  people to hide the next "this looks odd" instead of reporting it.

---

*Part of TicketsCAD's security documentation set. See
[`operator-security-awareness.md`](operator-security-awareness.md) for the
everyday-prevention companion to this document, and
[`architecture.md`](architecture.md) for the full threat model.*
