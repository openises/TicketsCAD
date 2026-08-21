# HTTPS verification, `require_https`, and `Trusted Reverse Proxies`

This page is for an administrator who has never had to think about reverse
proxies, TLS termination, or spoofable headers before. If you just want your
site running under HTTPS at all, start with **[HTTPS-SETUP.md](HTTPS-SETUP.md)**
instead — this page is about a narrower, harder question: **how does
TicketsCAD tell a *real* HTTPS connection from a browser apart from
someone merely *claiming* their connection is HTTPS?**

That distinction only matters once TicketsCAD sits behind a reverse proxy or
CDN (Cloudflare, nginx, IIS ARR, Nginx Proxy Manager, a Docker setup, a
tunnel — anything that terminates TLS somewhere other than the machine
running TicketsCAD itself). If your install talks TLS directly (a
certificate installed on the same Apache/IIS that runs TicketsCAD, no proxy
in front), you can skip most of this page — the two functions below agree
with each other in that setup, and there is nothing to configure.

---

## The one-paragraph version

TicketsCAD has two ways to ask "is this request HTTPS?", and they give
different answers on purpose. One is honest but naive; the other is
skeptical and correct. A **Require HTTPS** setting (Settings → Login
Settings) uses the skeptical one, and shows an admin-only banner — never a
block, never a redirect — whenever it can't confirm your traffic is
actually encrypted. Getting the banner to go away, if you genuinely are
behind HTTPS, means telling TicketsCAD which reverse proxy to trust via the
**Trusted Reverse Proxies** setting right next to it.

---

## Why there are two functions, not one

A request that reaches TicketsCAD carries a header called
`X-Forwarded-Proto`. A reverse proxy that terminates TLS is supposed to set
it to `https` so the application behind it knows the *original* browser
connection was encrypted, even though the hop from the proxy to the
application itself is often plain HTTP on a private network.

The problem: **that header is just text in an HTTP request. Anyone who can
reach your server at all can set it themselves** — with `curl -H
'X-Forwarded-Proto: https'`, or a browser extension, or a single line of
code. Nothing stops a request that never went anywhere near a real TLS
connection from claiming otherwise.

So TicketsCAD (`inc/https.php`) has two functions that read that header
differently:

| Function | Believes `X-Forwarded-Proto` from... | Use it for |
|---|---|---|
| `is_https()` | **anyone** | Building a `https://` vs `http://` URL, setting a cookie's `Secure` flag, deciding whether to show the general "you're not on HTTPS" reminder. Getting this wrong only affects the person who sent the (possibly fake) header — it's self-harm, not a security hole. |
| `is_https_verified()` | **only a proxy IP address you've explicitly told it to trust** (the `Trusted Reverse Proxies` setting) | Anything that GRANTS or REFUSES something, or tells an admin "you are protected," on the strength of the answer. This is what `require_https`'s banner uses, and what the external API's `external_api_require_tls` gate uses. |

If `is_https_verified()` used the honest-but-naive rule, it wouldn't be
verifying anything — the exact attacker it's meant to catch is the one who
can set the header. This is the same reasoning your bank doesn't accept
"trust me, I'm over 18" as identity verification.

`https_verification_failure_reason()` returns *why* `is_https_verified()`
said no, as one of three values:

| Reason | Meaning |
|---|---|
| `tls` | Verified. (Not actually a "failure" — this is what "yes, encrypted" looks like.) |
| `untrusted_proxy` | A header claimed HTTPS, but the peer that sent the request isn't in your Trusted Reverse Proxies list. TicketsCAD is not calling you insecure — it's saying it **can't tell**, because it doesn't yet trust whoever is vouching for you. |
| `plaintext` | No evidence of TLS at all. This really does mean the connection TicketsCAD can see is plain HTTP. |

The `require_https` banner shows this exact reason, in plain language,
rather than a single generic "not secure" message — an admin who is
genuinely behind a real proxy and just hasn't finished configuring it needs
a different next step than an admin running plain HTTP with no proxy at
all, and a banner that can't tell them apart is actively unhelpful.

---

## `Require HTTPS`: what it actually does (and doesn't)

**Settings → Login Settings → Require HTTPS.** Off by default.

When **on**, and the *current* request does not verify as TLS
(`is_https_verified()` returns false), TicketsCAD shows a dismissible
admin-only banner explaining which of the two "not verified" reasons
applies, with a link back to this page and to the setting itself.

**It does not redirect you to `https://`. It does not refuse the request.
It does not log you out. No user — admin or otherwise — is ever blocked
from using the system because of this setting**, regardless of the
verification state. This was a deliberate decision, not a missing feature:
a hard redirect behind a reverse proxy is one of the easiest ways to build
an infinite redirect loop (the proxy terminates TLS and forwards plain
HTTP internally; TicketsCAD sees plain HTTP and redirects to `https://`;
the browser follows the redirect back through the same proxy; repeat). If
you want a genuine enforced redirect, that belongs at the reverse proxy or
web server layer, which actually terminates the connection and can do it
safely — see [HTTPS-SETUP.md](HTTPS-SETUP.md) and
[WEB-SERVER-HARDENING.md](WEB-SERVER-HARDENING.md).

What turning it on buys you is **visibility**: an admin who believes their
traffic is encrypted, but whose proxy configuration has a gap, finds out
from a banner instead of finding out never. The same live answer also
appears:

- Next to the checkbox itself, on this Settings panel — so you see the
  *current, real* verification state while you're configuring it, not just
  whether the box is ticked.
- On **Settings → System Health**, as a `warn`-severity row (never
  `critical` — a proxy trust list you haven't finished configuring yet is
  not an emergency).

All three surfaces read one function, `https_enforcement_status()`
(`inc/https-enforcement.php`), so they can never disagree with each other.

---

## `Trusted Reverse Proxies`: what to put there

**Settings → Login Settings → Trusted proxy IPs / CIDR.** Default:
`127.0.0.1,::1` (covers the common case where the proxy runs on the same
machine as TicketsCAD).

A comma-separated list of IP addresses and/or CIDR ranges — the same format
and the same underlying list `inc/client-ip.php` already uses to decide
whether to trust `X-Forwarded-For` for the audit log, so you only ever
configure this list once for both purposes:

```
127.0.0.1,::1,10.0.0.5,10.10.0.0/24
```

**What goes in this list is the IP address of the last hop before
TicketsCAD** — the machine (or, on a single-host setup, `127.0.0.1`) whose
*direct TCP connection* Apache/IIS/PHP actually sees, as reported by
`REMOTE_ADDR`. It is not the browser's IP, and if there's more than one
proxy hop between the internet and TicketsCAD, it's the *last* one, not the
first. TicketsCAD trusts the `X-Forwarded-Proto` header only when the
machine that connected to it directly is on this list.

**How to find the right value**, if you're not sure: temporarily add a
line like `error_log('REMOTE_ADDR=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));`
near the top of `config.php`, make one request through your normal browser
path, and check the PHP error log for what showed up — then remove the
line. (A future release may put this on the Diagnostics page directly;
today, the error log is the reliable way to see it.)

---

## Worked example 1: Cloudflare talking directly to your server

The simplest real-world case. Your DNS is proxied through Cloudflare
(orange cloud), and Cloudflare connects straight to your server's public IP
on port 80 or 443 — no other proxy in between.

1. Cloudflare terminates the browser's real HTTPS connection at its edge.
2. Cloudflare's own connection to your origin server sets
   `X-Forwarded-Proto: https` (and `CF-Connecting-IP` with the visitor's
   real IP — `inc/client-ip.php` already prefers that header when present).
3. Because Cloudflare is now the "last hop" your server actually sees, its
   connecting IP address is what belongs in Trusted Reverse Proxies — but
   Cloudflare doesn't connect from one fixed IP, it connects from a large,
   published range. Add
   [Cloudflare's published IP ranges](https://www.cloudflare.com/ips/) (both
   the IPv4 and IPv6 lists) to Trusted Reverse Proxies, comma-separated —
   most of those ranges are already CIDR blocks, which this field supports
   directly.
4. Set your Cloudflare SSL/TLS mode to **Full** or **Full (strict)**, not
   **Flexible** — see the limitations section below for why this matters
   even though TicketsCAD can't check it for you.

## Worked example 2: Cloudflare → Tunnel → an internal reverse proxy → TicketsCAD

The setup this project's own hosted training instance actually uses, and
probably the more common one for a self-hosted install on a home or office
network: `cloudflared` (Cloudflare Tunnel) avoids opening any inbound port
at all, and a reverse proxy (nginx, Nginx Proxy Manager, Caddy) sits between
the tunnel and TicketsCAD for routing/TLS/other services on the same host.

1. Cloudflare terminates the browser's real HTTPS connection at its edge,
   same as example 1.
2. Traffic reaches your network through the tunnel — `cloudflared` may run
   on the *same* machine as TicketsCAD, or on a *different* machine that
   also runs a reverse proxy for several services. Either way, it forwards
   the request to your internal reverse proxy over your own network.
3. The internal reverse proxy is what actually opens the TCP connection to
   TicketsCAD's web server and sets `X-Forwarded-Proto: https` — so **the
   internal proxy's IP address**, not Cloudflare's, is what belongs in
   Trusted Reverse Proxies. If `cloudflared` and the reverse proxy run on
   the TicketsCAD host itself, that's `127.0.0.1` (already the default). If
   they run on a separate machine on your LAN, add that machine's IP.
4. You do **not** need Cloudflare's published IP ranges in this setup —
   Cloudflare never connects to TicketsCAD directly; your own internal
   proxy is the only thing TicketsCAD's Apache/IIS process ever sees a TCP
   connection from.

This is also the shape to watch for a specific web-server-level trap: some
Apache configurations translate the forwarded header into the server's own
`HTTPS` environment variable with a line like
`SetEnvIf X-Forwarded-Proto "https" HTTPS=on`, written **without** scoping
it to the proxy's address. `inc/https.php`'s `is_https_verified()` treats a
genuinely-server-set `$_SERVER['HTTPS']` as unconditionally trustworthy —
by design, because normally only the TLS module itself (mod_ssl) can set
it. An unscoped `SetEnvIf` line breaks that assumption: it makes *any*
client that can reach the vhost at all — not just your real proxy — able to
forge the same "yes, this is TLS" signal the whole Trusted Reverse Proxies
mechanism exists to require proof of. **If your vhost does this, scope it
to the proxy's address** (Apache 2.4's `<If>` directive with the `-R`
operator does this cleanly), or remove the line entirely and let
TicketsCAD's own PHP-level detection handle it — `is_https()` already reads
`X-Forwarded-Proto` on its own, without needing Apache to relabel anything.
We found and fixed exactly this gap on our own training instance while
building this feature: the setting had always been off there, so nothing
was silently broken for users, but turning it on would have shown "verified"
for connections that were never actually proven to be — the banner would
have been lying to the one audience it exists to tell the truth to.

---

## What this cannot do — read this before you trust a green checkmark

**TicketsCAD can only verify as far as the last hop that talks to it
directly.** It has no way to see, and makes no claim about, whether
*earlier* hops in the chain are genuinely encrypted.

Concretely, for the Cloudflare examples above:

- **Cloudflare's SSL/TLS mode matters, and TicketsCAD cannot check it.**
  Cloudflare's "Flexible" mode encrypts the browser-to-Cloudflare leg but
  talks **plain HTTP** from Cloudflare to your origin — meaning the
  `X-Forwarded-Proto: https` header it sends can be completely true about
  the browser's connection while the hop carrying your users' session
  cookies and form data across the internet (Cloudflare's network, in
  Flexible mode, generally not the open internet — but still a hop
  TicketsCAD cannot see) is not the "encrypted end to end" story an admin
  might assume. **Full** or **Full (strict)** mode encrypts that hop too.
  This is a setting on your Cloudflare dashboard, not something TicketsCAD
  can detect, influence, or warn you about — `is_https_verified()`
  answering "yes" only ever means "the last hop I can see claims HTTPS, and
  I trust who's telling me that," never "every hop from the browser to
  here was encrypted."
- The same logic applies to **any** proxy chain, Cloudflare or otherwise:
  a `verified: true` answer is trust in your configuration, not an
  independent audit of it. If your internal reverse proxy itself talks
  plain HTTP to a proxy further upstream that you believe is encrypting
  things, TicketsCAD has no visibility into that hop either.
- **A verified `true` also isn't a claim about cipher strength, certificate
  validity, or protocol version.** It answers one question only: "did the
  last hop I can see say HTTPS, from an address I've been told to trust?"
  TLS configuration hardening (disabling old protocol versions, weak
  ciphers) is a web-server/OS-level setting, covered separately in
  [WEB-SERVER-HARDENING.md](WEB-SERVER-HARDENING.md) and
  `docs/security/architecture.md`'s cryptographic inventory.

If any of this matters for a compliance requirement you're working under
(CJIS, HIPAA, etc.), treat `require_https`'s banner as one input among
several, not as the encryption audit itself.

---

## Related docs

- [HTTPS-SETUP.md](HTTPS-SETUP.md) — getting HTTPS running at all, if you
  don't have it yet (Caddy, Cloudflare Tunnel, mkcert, self-signed).
- [WEB-SERVER-HARDENING.md](WEB-SERVER-HARDENING.md) — the web-server-level
  hardening these two settings can't replace (directory exposure, TLS
  cipher configuration).
- `docs/security/architecture.md` — the cryptographic inventory and trust
  boundaries this page's TLS row cross-references.
- `inc/https.php` — the canonical detection functions this whole page
  documents, with their own extensive docblock covering the original
  bug reports that led to the current design.
- `inc/https-enforcement.php` — the `require_https` banner-trigger logic
  built on top of `inc/https.php`.
