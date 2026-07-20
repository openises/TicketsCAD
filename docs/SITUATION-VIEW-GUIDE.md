# Situation View (Full-Screen EOC Display) — Admin & User Guide

The Situation View (`situation.php`) is the full-screen wall display for an
Emergency Operations Center (EOC) or dispatch desk. It shows every active
incident, every unit, and every facility on one map plus a live side panel,
and it is built to run unattended for hours on a wall-mounted screen.

Open it from the top nav: **Full Screen** (opens in a new tab), or navigate
directly to `situation.php`. Access is gated by the `screen.situation`
permission.

- **Companion reading:** `docs/LOCATION-PROVIDERS-GUIDE.md` (unit tracking),
  `docs/map-configuration.md` (tile providers), `docs/RBAC-GUIDE.md` (access).
- **Source:** `situation.php` (self-contained page — inline map JS).

---

## What the display shows

| Element | Where | Source |
|---------|-------|--------|
| Active incidents | Map markers + Incidents tab | `api/incidents.php` (SSE + 15s poll) |
| Units (EOC) | Map marker group + Units tab | `api/responders.php` |
| Facilities | Map marker group + Facilities tab | `api/facilities.php` |
| Live unit GPS | Moving markers + trails | `UnitTracking` (10s refresh) |
| Radar (2 layers) | Layer control | RainViewer + NOAA/NWS MRMS |
| Weather | Layer control | OpenWeatherMap (needs API key) |
| Road conditions | Layer control | `api/road-conditions.php` |
| Event map images | Layer control | Phase 110 map-image overlays |
| Per-category markups | Layer control | shared `MapPrefs` overlays |

The header badges (Incidents / Units count) reflect the full roster/incident
set. **The Units badge is owned solely by `loadUnits()`** — the live-GPS
tracker no longer writes it (see "Units count" under Fixes below), so the
number reflects everyone on the roster, not only those currently reporting a
position.

---

## The map view-lock behavior (important for wall displays)

A real EOC runs this map for hours, so the map must never yank itself away
from what an operator zoomed to. The rules:

1. **Auto-fit on incident-set change.** When the set of active incidents
   changes (a new call comes in, one clears), the map re-fits to include all
   of them. It does **not** re-fit on an idle refresh tick — only when the
   set actually changes.
2. **Any manual gesture locks the view.** Panning, scroll-zoom, pinch, *or the
   on-screen +/- zoom buttons* locks the view. Once locked, refresh ticks stop
   touching the zoom/center. This is deliberate: a dispatcher watching one
   corner of the county is never snapped back to the whole-county view by an
   automatic refresh.
3. **Re-fit is opt-in.** A **★ (star)** control appears top-right once you've
   locked the view. Click it to opt back into auto-fit and jump to the current
   incident cloud.
4. **Fit tightness bias.** The +/- tightness buttons bias how tight the
   auto-fit is (fill the screen with the incident cluster vs. leave margin to
   watch approaching weather). This bias persists per browser.

**Click-to-zoom with restore:** Click any incident, unit, or facility row in
the side panel to zoom to it and open its popup. Click the **same** row again
to restore the exact view you had before you zoomed in. This works across all
three tabs.

Overlay selections (which radar/weather/road layers are on) and the selected
side-panel tab persist in `localStorage` per browser, so a reload during a
storm doesn't drop your layers.

---

## Radar — two layers, when to use each

The layer control (top-right, layers icon) offers **two independent radar
layers**. They can both be on, but for a clean picture use one at a time.

### Radar — US (NWS)  *(recommended for US operations)*

- Source: **NOAA/NWS MRMS base reflectivity** (Multi-Radar Multi-Sensor),
  1 km resolution, quality-controlled, event-driven ~2-minute updates.
- Rendered **dynamically** by NOAA's ArcGIS service, so it stays **sharp at
  any zoom level** — ideal for watching a cell approach a specific event site.
- **US (CONUS) coverage only.** Outside the continental US it shows nothing.
- No API key. Auto-refreshes every ~2.5 minutes so a wall display stays live.
- Endpoint: `mapservices.weather.noaa.gov/.../radar_base_reflectivity`.

### Radar — Global

- Source: **RainViewer** global precipitation mosaic. No API key.
- **Worldwide coverage** — use this outside the US, or for a whole-country
  overview.
- The mosaic is only produced through zoom level 7. Zooming in past that
  upscales the last good tile (coarse but continuous) rather than showing
  "Zoom Level Not Supported" placeholders. So it looks fuzzy up close — that's
  expected; switch to the NWS layer for a sharp close-up in the US.
- Refreshes every 5 minutes to the newest available frame.

**Rule of thumb:** US event, zoomed in on a site → **NWS**. Non-US, or a
country-wide glance → **Global**.

### Administrator notes for radar

- **No configuration is required** — both layers work out of the box, no keys.
- **Content-Security-Policy already allows them.** `inc/security-headers.php`
  whitelists `*.rainviewer.com` (img + connect) and
  `mapservices.weather.noaa.gov` (img). If you have added your own reverse
  proxy or a stricter CSP in front of TicketsCAD, you must allow those hosts or
  the radar tiles will silently fail to paint.
- **Both are public internet services.** An air-gapped EOC will not get radar.
  Weather and radar require outbound HTTPS to the providers.
- There is currently **no admin toggle** to hide a radar layer or set a default
  radar layer — both always appear in the layer control, both default to off.
  (See the guide's "Known limitations" note.)

---

## Layer control — everything available

The situation map's layer control mirrors the dashboard map. Base layers
(street/dark/terrain plus your admin-configured tile provider) are radio
buttons; everything below is an independent overlay toggle:

- **Radar — US (NWS)** / **Radar — Global** (above)
- **Temperature / Precipitation / Wind / Clouds** — OpenWeatherMap overlays.
  These require a weather API key configured in **Settings » System** (no key =
  the tiles come back empty; the toggles still appear).
- **Road Conditions** — drawn from the road-conditions API.
- **Event map images** — any Phase 110 event image overlays that are enabled
  and positioned (Settings » Maps).
- **Per-category markup overlays** — race markers, zones, parade routes, and
  any other markup categories. As of #60/#46 these match the dashboard exactly
  (shared `MapPrefs.addMarkupOverlays`), and each category persists its own
  on/off state per browser.

---

## Incident sensitivity on the EOC display

Incidents carry a security/sensitivity label (Phase 18) that controls how they
appear on this public-facing wall display:

- **hide** — the incident is not drawn on the map at all.
- **dim** — drawn as a gray, semi-transparent marker whose popup shows only the
  severity and label (no scope, no address).
- **normal** — full marker and popup.

Addresses can be independently suppressed (`eoc_show_address`), showing a
configurable placeholder (default `*** Restricted ***`). Configure sensitivity
per incident-type or per incident; see the Incident Sensitivity settings.

---

## Recommended wall-display setup

1. Dedicate a browser (or a kiosk-mode tab) to `situation.php` on the wall
   screen. Log in as a user with `screen.situation` and, if the display should
   stay generic, minimal other permissions.
2. Turn on **Radar — US (NWS)** and **Road Conditions**; leave Global radar off.
3. Set the fit-tightness bias to leave a little margin so an approaching cell is
   visible before it reaches your incidents.
4. Do **not** manually pan/zoom the wall display unless you want it to stop
   auto-following incidents — use the **★** button to resume auto-fit if it
   gets locked.
5. The page reconnects SSE and re-polls on its own; it is safe to leave running.

---

## Known limitations / not-yet-configurable

These are intentional call-outs (flexibility is a core project value):

- No admin setting to choose a **default radar layer** or to **hide** a radar
  layer an agency doesn't want. Both always appear, both default off.
- No admin setting for the unit-tracking **refresh interval** (hard-coded 10s)
  or the incident fallback **poll interval** (hard-coded 15s) on this page.
- The **NWS radar is US-only**; there is no equivalent high-resolution dynamic
  layer for other countries.
- Weather overlays silently show nothing without an OpenWeatherMap key — the
  toggles give no hint that a key is missing.
