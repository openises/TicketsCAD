# Network segmentation for a TicketsCAD install

**Audience:** operators planning or reviewing where TicketsCAD and its
radio/mesh bridge services physically sit on their network.

**Why this exists:** CIS Controls v8, Control 12 (Network Infrastructure
Management) — ranked last, deliberately, in
[`architecture.md` §6](architecture.md#6-prioritized-gap-closure-queue):
this is IG2-tier (a step up from the "essential cyber hygiene" tier most
of the items ahead of it address), and network topology is entirely the
operating agency's decision, not something TicketsCAD's own code can
enforce or verify. This document explains the "why," names the specific
services involved, and gives a starting layout — it does not, and cannot,
configure anything for you.

## The reality this starts from

Stated plainly in
[`architecture.md` §1](architecture.md#1-threat-model): "a volunteer fire
department's CAD server sitting on the same flat network as every other
device in the station is a real, common deployment, and this software
cannot fix that from inside the application layer." Most TicketsCAD
installs run on a single flat LAN. This document is about *why* that's
worth changing when you have the resources to, not a claim that a flat
network makes TicketsCAD unsafe to run.

## What TicketsCAD actually puts on your network

Beyond the web application itself, an install with the optional
radio/mesh integrations enabled runs additional network-facing services,
each documented in [`architecture.md` §2`](architecture.md#2-trust-boundaries):

| Service | What it opens | Where it typically runs |
|---|---|---|
| The TicketsCAD web app (PHP) | HTTP/HTTPS (80/443 typically) | Same host as the database |
| MariaDB/MySQL | 3306 (should NEVER be internet-reachable) | Same host, or a dedicated DB host |
| DMR bridge (`hbp_client.py`) | Outbound to BrandMeister/HBlink + a local bearer-token-protected control API | Often a separate host/VM — needs proximity to a radio or DMR hotspot |
| Meshtastic bridge | Outbound to a Meshtastic node (serial/TCP/BLE) + a local bearer-token-protected control API | A host with a physical or network path to the mesh radio |
| Zello proxy | An authenticated WSS endpoint the browser connects to, which itself connects outbound to Zello | Same host as the web app, or a dedicated proxy host |

Each bridge is a **separate trust domain by design** — see
[`architecture.md` §2`](architecture.md#2-trust-boundaries) — precisely so
a defect in one bridge can't reach the database or RBAC-protected API
directly. Segmentation at the network layer is the same idea one level up:
if a bridge host is compromised, what ELSE on your network can it reach?

## A starting layout (adapt to what you actually have)

You do not need enterprise-grade infrastructure for this — even a
consumer router that supports VLANs, or two physically separate
switches, gets you most of the value:

| Zone | What lives here | Why separate |
|---|---|---|
| **App zone** | The TicketsCAD web app + database | The system of record — the zone everything else should be able to reach *into* only through the application's own authenticated API, never directly to the database |
| **Bridge zone** | DMR bridge, Meshtastic bridge, Zello proxy | These reach the internet or a radio network directly — isolate them from the rest of your LAN so a compromised bridge can't pivot to a workstation, and vice versa |
| **Operator/workstation zone** | Dispatcher and admin workstations, mobile unit devices | Where people actually work — should reach the App zone (to use TicketsCAD) but has no legitimate reason to reach the Bridge zone directly |
| **Guest/IoT zone** (if applicable) | Anything else on the same physical location's network — station Wi-Fi, smart devices, printers | Fully isolated from the other three; a compromised smart device is a common real-world entry point and has zero business reaching a CAD system |

**The rule that matters most:** the database (MariaDB/MySQL, port 3306)
should never be reachable from outside the App zone, and never from the
internet under any circumstance. If your TicketsCAD install and its
database are on different hosts, restrict that connection to exactly the
web app's IP — not "anyone on the LAN," and never `0.0.0.0`.

## Practical starting points, in order of effort

1. **Free and immediate:** confirm your router's admin panel isn't
   reachable from TicketsCAD's network segment, and that the database
   port isn't forwarded to the internet (a shockingly common
   misconfiguration — check your router's port-forwarding table now).
2. **Cheap:** a second physical switch, or a VLAN-capable consumer
   router (many now support this out of the box), to put the radio/mesh
   bridge hosts on a separate segment from workstations.
3. **A firewall between zones**, even a basic one (pfSense/OPNsense are
   free, run on inexpensive hardware) — lets you write the actual rule
   "workstations can reach the app zone on 443, nothing reaches the
   database except the app host, bridges can't reach workstations at
   all."
4. **If you're running the DMR or Meshtastic bridge on the SAME host as
   the web app** (common for small installs), you can't network-segment
   away from yourself — the mitigating control is the bearer-token
   boundary already built into those bridges
   ([`architecture.md` §2`](architecture.md#2-trust-boundaries)), plus
   running them as separate OS-level processes/containers so a bug in one
   doesn't have direct memory/filesystem access to the other.

## What this document is not

It is not a network engineering course, and it does not replace a
professional network assessment if your organization has access to one.
It's the minimum "here's what to ask for" if you're talking to whoever
manages your station's network, or the starting checklist if that's you.

---

*Part of TicketsCAD's security documentation set. See
[`architecture.md`](architecture.md) for the full threat model, and
[`waf-reverse-proxy-recommendation.md`](waf-reverse-proxy-recommendation.md)
for the complementary internet-facing-install recommendation.*
