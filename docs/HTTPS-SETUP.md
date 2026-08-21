# Enabling HTTPS for TicketsCAD

TicketsCAD works over plain HTTP, but on HTTP the connection between the browser
and the server is **not encrypted in transit** — passwords, patient details, and
positions can be read by anything on the network path. This guide gets you to
HTTPS. Pick the row that matches your setup; each is a complete recipe.

Once HTTPS is on, the "running without HTTPS" reminder disappears on its own —
there's nothing to switch off in TicketsCAD.

| Your situation | Go to |
|---|---|
| The server is reachable from the internet and you have a domain name | [1 — Caddy (automatic Let's Encrypt)](#1-caddy--automatic-lets-encrypt-easiest) |
| You'd rather not open any ports, or you already use Cloudflare | [2 — Cloudflare Tunnel](#2-cloudflare-tunnel-no-open-ports) |
| LAN-only, and you control a domain's DNS | [3 — Caddy with DNS validation](#3-lan-only-with-a-domain-caddy--dns) |
| LAN-only, no domain at all | [4 — mkcert (locally-trusted certificate)](#4-lan-only-no-domain-mkcert) |

A note before you start: **all of these put a "reverse proxy" in front of
TicketsCAD.** The proxy handles the encryption and forwards plain HTTP to
TicketsCAD on the inside. TicketsCAD already understands this — it reads the
`X-Forwarded-Proto` header the proxy sends, so it correctly knows the visitor is
on HTTPS and the reminder stays off. (If you build your own proxy, make sure it
sets `X-Forwarded-Proto: https`.)

---

## 1. Caddy — automatic Let's Encrypt (easiest)

Best when the server has a public domain name (e.g. `cad.example.org`) pointed at
its public IP, and ports 80 + 443 reach it.

[Caddy](https://caddyserver.com) gets and renews a free Let's Encrypt certificate
automatically. Install it, then use a two-line `Caddyfile`:

```
cad.example.org {
    reverse_proxy localhost:8081
}
```

Replace `cad.example.org` with your domain and `localhost:8081` with wherever
TicketsCAD's plain-HTTP service is listening (the Docker default is `:8081`;
XAMPP is usually `:80`). Reload Caddy — it fetches the certificate on first
request. That's the whole job; renewal is automatic.

## 2. Cloudflare Tunnel (no open ports)

Best when you don't want to expose the server to the internet at all, or your
router won't forward ports. You need a free Cloudflare account and a domain using
Cloudflare DNS.

1. Install `cloudflared` on the server.
2. `cloudflared tunnel login`, then `cloudflared tunnel create ticketscad`.
3. Route a hostname to the local TicketsCAD port:
   ```
   cloudflared tunnel route dns ticketscad cad.example.org
   ```
   and in the tunnel config point `cad.example.org` at `http://localhost:8081`.
4. Run the tunnel (as a service). Cloudflare terminates HTTPS at its edge and
   carries the traffic to your server over the tunnel — no inbound ports opened.

Cloudflare sends `X-Forwarded-Proto: https`, so TicketsCAD sees HTTPS correctly.

## 3. LAN-only, with a domain: Caddy + DNS

Best when TicketsCAD is only reachable inside your network, but you own a domain
you can add DNS records to. A public certificate authority won't validate a name
it can't reach over the internet by the normal method, so use **DNS validation**
instead — Caddy proves control of the domain by creating a temporary DNS record.

Use Caddy with the DNS plugin for your DNS provider (Cloudflare, Route 53, etc.):

```
cad.internal.example.org {
    tls {
        dns cloudflare {env.CLOUDFLARE_API_TOKEN}
    }
    reverse_proxy localhost:8081
}
```

Point `cad.internal.example.org` at the server's LAN IP in your internal DNS.
Devices on the LAN then reach a real, browser-trusted HTTPS certificate without
the server ever being exposed to the internet.

## 4. LAN-only, no domain: mkcert

Best for a closed network with no domain — a handful of dispatch machines on a
private LAN. [mkcert](https://github.com/FiloSottile/mkcert) creates a
certificate that the machines you install its root on will trust.

1. On any machine, install mkcert and run `mkcert -install` once (creates a local
   root CA).
2. Make a certificate for the server's LAN name or IP:
   ```
   mkcert ticketscad.lan 10.0.0.10
   ```
   That writes a `.pem` cert + key.
3. Put those in front of TicketsCAD with any reverse proxy that does TLS —
   Caddy, nginx, or Apache with `mod_ssl` — proxying to the plain-HTTP port.
4. On **each dispatch machine**, install the mkcert root CA (mkcert prints how,
   per OS). After that, those browsers trust the certificate with no warning.

Machines without the root installed will show a browser warning — that's expected
for a private CA. For a small, fixed set of machines this is the least-effort path
to real encryption.

---

## Self-signed, as a last resort

If you can't do any of the above, a plain self-signed certificate still encrypts
the traffic — every browser just shows a one-time "not trusted" warning that
users click past. It's better than plain HTTP for confidentiality, but the
click-through warning trains people to ignore certificate errors, so prefer
mkcert (section 4) whenever you can.

## Verifying it worked

Open TicketsCAD in a browser. The address bar should show `https://` (and a
padlock, if the certificate is trusted). Inside the app, **Diagnostics** shows
"Connection encrypted (HTTPS): yes", and the "running without HTTPS" reminder is
gone. If TicketsCAD still shows the reminder while your browser is clearly on
HTTPS, your reverse proxy isn't forwarding `X-Forwarded-Proto: https` — add that
header in the proxy config.

## Encryption is not the same as access control

HTTPS stops other people on the network path from reading the traffic. It does
nothing about *what your web server is willing to hand out to anyone who asks*.
Those are separate jobs, and the second one has to be configured too: on a
default install the web server publishes every directory in the tree, including
`backups/` — where TicketsCAD keeps complete copies of your database.

**If you followed section 1, 3 or 4 above you are running Caddy or nginx, and
neither reads `.htaccess`** — so the deny rules TicketsCAD ships do nothing for
you until you add the equivalent. Take two minutes over
**[WEB-SERVER-HARDENING.md](WEB-SERVER-HARDENING.md)** now that TLS is working;
it has a copy-paste snippet for each server and a three-command test.

## Want TicketsCAD to actively tell you when it ISN'T verified as HTTPS?

Everything on this page gets HTTPS running. A separate setting, **Require
HTTPS** (Settings → Login Settings), shows an admin-only banner whenever a
connection can't be *verified* as encrypted — useful once you're behind a
reverse proxy/CDN and want to catch a configuration drift instead of
assuming it's still working. It never blocks anyone, on any host, at any
verification state. See **[HTTPS-VERIFICATION.md](HTTPS-VERIFICATION.md)**
for how that verification works and how to configure Trusted Reverse
Proxies so it actually recognizes your setup.

## Related docs

- [HTTPS-VERIFICATION.md](HTTPS-VERIFICATION.md) — how TicketsCAD tells a
  real HTTPS connection apart from a spoofed header, and the `Require
  HTTPS` / `Trusted Reverse Proxies` settings built on that distinction
- [WEB-SERVER-HARDENING.md](WEB-SERVER-HARDENING.md) — stopping the server publishing directories it should not
- [DOCKER.md](DOCKER.md) — the Docker deployment (the `:8081` port referenced above)
- [INSTALL.md](INSTALL.md) — base install
- [TRACCAR-SETUP.md](TRACCAR-SETUP.md) — device tracking (also wants HTTPS on the internet)
