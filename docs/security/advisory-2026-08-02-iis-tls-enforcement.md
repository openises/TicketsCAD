# Security advisory — the "require HTTPS" setting did not enforce HTTPS

**Status: DRAFT — NOT PUBLISHED.** Publishing is the maintainer's decision.
Do not link this file publicly until Eric says so.

- **Date:** 2026-08-02
- **Affected component:** External API (`/api/external/v1/*`), TLS enforcement gate
- **Affected versions:** 4.0.0 through 4.2.2 (the gate has behaved this way since
  the external API shipped)
- **Fixed in:** unreleased — ship with the next point release
- **Severity:** Moderate
- **Reported by:** **Ron Jones** (GitHub [@rjonesbsink](https://github.com/rjonesbsink)),
  privately, with a working proof of concept
- **CVE:** not requested

---

## In one sentence

If you turned on **"Require HTTPS for the external API"**, that switch did
nothing on Microsoft IIS, and could be stepped around on *any* web server by a
caller who added one header — so the API answered requests over plain,
unencrypted HTTP while the setting said it would not.

## What this means for your agency

The external API is the interface other software uses to read your CAD data —
incidents, units, locations. The `external_api_require_tls` setting (**on by
default**) is supposed to refuse any request that did not arrive over an
encrypted connection.

It did not refuse them. On an affected install, someone holding a valid API
token could call the API over plain HTTP and get a full incident list back. More
importantly, an ordinary user or integrator *could not tell* — the setting read
"on", the Settings page said HTTPS was required, and requests kept working. That
is the part that makes this a security issue rather than a plain bug: **a
control reported success while doing nothing**, so operators believed they had
protection they did not have.

What an attacker gains is limited, and worth stating plainly:

- They still need a **valid API token**. This is not a way in without one.
- The realistic harm is **eavesdropping**: on plain HTTP, the token and every
  incident record travel in the clear, readable by anyone positioned on the
  network path — a shared office switch, public Wi-Fi, an ISP link, a
  compromised router.
- It does not by itself grant new access, escalate a role, or alter data.

Running an emergency-dispatch API over plain HTTP is inadvisable regardless of
this setting. The gate is **defence in depth** — a guardrail meant to catch a
misconfiguration — not the protection itself. Its failing is serious because you
were told the guardrail was up.

## Am I affected?

**You are affected if you use the external API at all.** There are two separate
defects, and the second one is not specific to any web server.

| Your web server | Defect 1 — IIS `"off"` | Defect 2 — spoofable header |
|---|---|---|
| **Microsoft IIS** | **Affected** — gate never fired | **Affected** |
| **Apache** | Not affected | **Affected** |
| **nginx** | Not affected | **Affected** |

**Defect 1 (IIS only).** IIS reports a plain-HTTP request by setting the variable
`HTTPS` to the text `"off"`, where Apache and nginx simply leave it unset. The
check asked "is this value empty?" — and the three-letter word `"off"` is not
empty, so IIS plain-HTTP requests were read as secure. Exactly backwards. Apache
and nginx were genuinely unaffected by this one.

**Defect 2 (every platform).** The same check also honoured the
`X-Forwarded-Proto` request header without asking who sent it. That header is
meant to be set by *your* reverse proxy, but on a request that did not pass
through your proxy it is just text the caller typed. So on any server:

```
curl -H 'X-Forwarded-Proto: https' http://your-cad/api/external/v1/incidents.php
```

...walked straight through the gate. This was found while verifying Ron's
report; it was not in the original disclosure.

If you already serve the API only over HTTPS and block plain HTTP at the network
edge, you were never exposed in practice — the gate simply was not the thing
protecting you.

## Check your own install — one command

Run this from any machine that can reach your CAD over plain HTTP. Substitute
your own hostname:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  -H 'X-Forwarded-Proto: https' \
  http://YOUR-CAD-HOSTNAME/api/external/v1/incidents.php
```

- **`426`** — you are protected. That is the gate refusing an unencrypted
  request. This is the fixed behaviour.
- **`401`** — **you are affected.** `401` means the request got *past* the TLS
  gate and was only stopped by the missing token. With a real token this would
  have returned data.
- **connection refused / timeout** — plain HTTP is closed at your edge. Good;
  you were not reachable this way.

On IIS you can reproduce defect 1 without the header at all — drop the
`-H` argument and compare.

## What to do

### Immediately, without updating

Any one of these closes the exposure today. Pick whichever fits your setup:

1. **Stop serving plain HTTP.** The real fix. Redirect all HTTP to HTTPS at the
   web server or reverse proxy, or close port 80 at the firewall. If you already
   do this, you are covered.

2. **Strip the forwarded header at your proxy** so a caller cannot supply it.
   Your proxy should always overwrite it, never pass a client's copy through:
   - nginx / Nginx Proxy Manager: `proxy_set_header X-Forwarded-Proto $scheme;`
   - Apache (`mod_proxy`): `RequestHeader set X-Forwarded-Proto "https"`
   - IIS ARR: set the server variable explicitly in the inbound rule.

3. **If you are not using the external API, turn it off** — Settings → External API Tokens Tokens. An interface nobody uses should not be listening.

4. **Rotate your external API tokens** if the API has been reachable over plain
   HTTP from an untrusted network. Assume anything sent in the clear was
   observed. Settings → External API Tokens → revoke and reissue.

### When you update

Update to the next release. No configuration change is required and no schema
change is involved.

**One thing to check if you run behind a reverse proxy** (Cloudflare, Nginx
Proxy Manager, IIS ARR, an F5, anything that terminates TLS for you): the fixed
gate now believes `X-Forwarded-Proto` **only from a proxy you have listed** as
trusted. That list is the existing `trusted_proxies` setting — the same one that
already governs client-IP logging — and it defaults to `127.0.0.1,::1`, which
covers a proxy running on the same host.

If your proxy is on a *different* host and is not in that list, the API will
start returning `426` after the update. That is the gate working correctly on
information it cannot verify, not a new bug. Add your proxy's address:

```sql
INSERT INTO settings (name, value) VALUES ('trusted_proxies', '127.0.0.1,::1,10.0.0.5')
  ON DUPLICATE KEY UPDATE value = VALUES(value);
```

CIDR ranges are accepted (`10.0.0.0/8`). Cloudflare users should list
Cloudflare's published IP ranges, or better, restrict your origin to accept
connections only from Cloudflare.

The refusal tells you when this is the cause rather than leaving you guessing —
a `426` in this situation carries:

> A forwarded-protocol header claimed HTTPS, but this request did not arrive
> from a trusted proxy. Add the proxy to the trusted_proxies setting.

## A second, lower-severity issue in the same report

Ron also found the same root cause pointed the other way, in the mobile session
cookie (`inc/session-bootstrap.php`). There the code asked "is `HTTPS`
non-empty?" — and on IIS over plain HTTP, `"off"` *is* non-empty, so the session
cookie was marked `Secure` on a connection that was not secure. Browsers then
refuse to send a `Secure` cookie back over plain HTTP, so **mobile/PWA logins on
IIS-over-HTTP could not hold a session** — users were bounced back to the login
screen repeatedly.

This one exposed nothing; it broke a feature. It is fixed by the same change,
and the fix restores mobile sessions on those installs.

## Technical detail

The whole family of defects came from nineteen places in the tree each
re-deriving "am I on HTTPS?" inline, without agreeing. They are now a single
pair of functions in `inc/https.php`:

- **`is_https()`** — best-effort scheme, for building URLs, status display and
  cookie flags. Believes `X-Forwarded-Proto` from anyone, which is safe here: a
  caller who lies about its own scheme only affects its own response.
- **`is_https_verified()`** — provable TLS, for anything that *grants or refuses*
  access. Believes forwarded headers only from a trusted proxy.

A gate built on the first is not a gate: the caller it exists to stop is exactly
the caller who can set the header.

Both handle the spellings that were mishandled before — unset, `"off"`, `"on"`,
`"1"`, `"0"`, empty string, mixed case — plus `REQUEST_SCHEME`, port 443, and
comma-chained `X-Forwarded-Proto` values.

Regression coverage is in `tests/test_https_detection.php` (39 assertions). It
deliberately does **not** assert on source text — the original broken line
mentioned both `HTTPS` and `X-Forwarded-Proto` and read like a correct gate. It
instead runs the real endpoint in a child process under a simulated IIS
environment and asserts on the actual response, and carries a negative control
that reproduces the old expression and fails if the two ever agree again.

## Credit

Found and reported by **Ron Jones** ([@rjonesbsink](https://github.com/rjonesbsink)),
who verified it two independent ways — a server-variable probe showing
`HTTPS="off"`, and a live request returning `200` with a full incident list over
plain HTTP — and proposed the fix that the rest of the codebase already used.

He reported it privately rather than opening a public issue, and asked whether
it should be treated as a formal advisory or an ordinary bug. That was the right
call on both counts, and the reason this document exists.

## Reporting security issues

Please report suspected security issues privately rather than in a public issue.
See [`SECURITY.md`](../../SECURITY.md).
