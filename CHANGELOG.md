# Changelog

All notable changes to TicketsCAD (NewUI v4) are documented here.
The format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [4.0.1] - 2026-07-20

### Added
- Docker: an optional `voice` compose profile that runs the Zello + DMR
  push-to-talk relays alongside the app — `docker compose --profile voice up -d`
  — reusing the app image (nothing extra to build). The app's Apache
  reverse-proxies the browser WebSocket paths (`/zello-ws`, `/dmr-ws`) to the
  relay containers. See docs/DOCKER.md section 8a. (The hardware DMR/AMBE bridge
  and Meshtastic still run on the host — they need a physical radio.)

## [4.0.0] - 2026-07-19

First public release of the NewUI v4 rewrite of TicketsCAD — a from-scratch,
keyboard-first dashboard rewrite of the legacy
[TicketsCAD](https://github.com/openises/tickets) Computer-Aided Dispatch system
(v3.44.x), keeping the same MariaDB schema so existing installs can upgrade in
place. See the README for the feature set and install instructions.

### Added
- Per-unit OwnTracks device tracking: a unit/vehicle can carry its own tracked
  device, provisioned from the unit's Location Sources.

### Fixed
- Mass-casualty bed counts: two units transporting to two different hospitals now
  decrement each facility independently. A receiving facility set on a unit's
  status is always that unit's per-unit destination.
- Incidents are referenced by their case number (not the internal database id)
  throughout close/note/create prompts, report exports, and the activity feed.

### Security
- The Settings API no longer returns stored secret values (SMTP / SMS / Slack /
  etc.) to the browser; secret fields report only whether a value is set.
