# A WAF or reverse proxy for internet-facing installs

**Audience:** operators running TicketsCAD reachable from the public
internet (not just a LAN) — the exact deployment shape behind every
incident in [`architecture.md` §7](architecture.md#7-incident-history-why-2s-boundary-gets-the-most-attention).

## Why this is worth doing, specifically for TicketsCAD

TicketsCAD's own incident history is a repeating pattern: a directory or
file that should have been denied was reachable over plain HTTP, on a
specific web server, in a specific configuration an operator had. Every
one of those was eventually fixed in the application (a `.htaccess` rule,
a `web.config`, a code change) — but each fix landed only *after* the gap
existed on a live install. A Web Application Firewall (WAF) or reverse
proxy sitting in front of TicketsCAD is the one layer that can catch the
**next** version of this same mistake — a new directory, a new endpoint,
a config regression — without waiting for a code release, because it
inspects the request independent of what TicketsCAD's own code does with
it.

This is not a substitute for the application-level fixes — see the
[web-server hardening guide](../WEB-SERVER-HARDENING.md) for those. It's
an additional layer that fails differently, which is the actual point of
defense in depth: a bug that slips past one layer still has to get past
the other.

## The realistic option for a volunteer organization: Cloudflare (free tier)

Most of TicketsCAD's operators are volunteer fire/EMS/ARES/campus-security
groups with no budget for commercial security tooling. Cloudflare's free
tier is the practical answer:

- **Cost: $0.** No credit card required for the free plan.
- **DNS proxying** — point your domain's DNS through Cloudflare, and
  every request to your TicketsCAD install passes through their edge
  first.
- **Free managed WAF rules** cover the OWASP-style basics (SQL injection
  patterns, common exploit signatures, known bad bots) without you
  writing a single rule yourself.
- **Free TLS** — Cloudflare terminates HTTPS at their edge and can
  encrypt the hop to your origin server too ("Full" or "Full (strict)"
  SSL mode), which also solves TLS certificate management for operators
  who've been running plain HTTP because a cert felt like one more thing
  to maintain.
- **Rate limiting** on the free tier is limited but present, and useful
  against the "researcher/scanner running automated tooling" attacker
  class in TicketsCAD's own threat model
  ([`architecture.md` §1](architecture.md#1-threat-model)).
- **Hides your origin IP** from casual reconnaissance, which raises the
  bar for an attacker probing for exactly the exposed-directory class of
  bug this project's history is full of.

### Setting it up (outline — Cloudflare's own docs have the current click-path)

1. Add your domain to a free Cloudflare account.
2. Update your domain's nameservers to Cloudflare's (your registrar does
   this — it's the one step that takes up to 24-48 hours to propagate).
3. Set the DNS record for your TicketsCAD hostname to **Proxied**
   (orange cloud icon) — this is what routes traffic through Cloudflare's
   edge instead of straight to your server.
4. Under SSL/TLS, set the encryption mode to **Full** (or **Full
   (strict)** if your origin already has a valid certificate).
5. Under Security → WAF, confirm the free Managed Ruleset is enabled
   (it usually is by default).
6. Optional but recommended: Security → Bots, enable the free-tier bot
   fight mode.

### What this does NOT replace

- **It does not replace TLS configuration on your own server** if you
  want end-to-end encryption rather than trusting Cloudflare's edge —
  use "Full (strict)" mode with a real origin certificate (Let's
  Encrypt is free) rather than "Flexible" mode, which leaves the
  Cloudflare-to-origin hop unencrypted.
- **It does not replace RBAC, CSRF protection, or any application-level
  control** — a WAF blocks malformed or known-malicious requests; it has
  no idea what a *legitimate* request to TicketsCAD should look like at
  the business-logic level.
- **It does not help if your radio/mesh bridge services are also
  internet-facing** — those run as separate processes with their own
  bearer-token-protected surfaces (see
  [`architecture.md` §2](architecture.md#2-trust-boundaries)); a WAF in
  front of the web app doesn't cover them. See
  [`network-segmentation-guidance.md`](network-segmentation-guidance.md)
  for that surface.

## If your organization already has something better

Cloudflare's free tier is the *floor*, not a ceiling. If your agency
already runs a commercial WAF, a hardware firewall with WAF
functionality, or a managed reverse proxy (nginx/HAProxy with
ModSecurity, for example) — use that instead. The requirement is "a
request-inspection layer independent of TicketsCAD's own code," not
specifically Cloudflare.

---

*Part of TicketsCAD's security documentation set. See
[`architecture.md`](architecture.md) for the full threat model this
recommendation is drawn from.*
